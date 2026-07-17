# C4 Evidence: Deterministic Simulator Adapter

Date: 2026-07-14/15

## Security Proof Root Cause and Correction

`make security-proof` was reported failing on the Traefik-to-Kubernetes-API allow assertion. Root cause: the Traefik egress NetworkPolicy CIDR is rendered from the live cluster's `default/kubernetes` Service ClusterIP and node endpoint IP at `security-apply` time (`render_apiserver_policy`/`render_service_clusterip_policy` in `scripts/security/lib`). When the rendered/applied policy predates a cluster or Service lifecycle event that changed those IPs, the applied `ipBlock` CIDR goes stale relative to the live cluster and the allow assertion fails as a spurious deny. Re-running `make security-apply` re-renders both policies from the live cluster and re-applies them; `make security-config-check`, `make security-status`, and `make security-proof` then passed cleanly and repeatably (three consecutive runs). No NetworkPolicy source content was changed and no allowlist was widened.

## Helm and Observability Tool Resolution

`scripts/ci/install-kubernetes-tools` already downloads a pinned, checksum-verified `helm` (per `versions.env` `HELM_VERSION`), but its `k3d` checksum step matched the literal filename `k3d-linux-amd64` against the upstream `checksums.txt`, which now prefixes entries with `_dist/`. The stale match caused the whole script to abort before reaching the `helm` download. The grep/sed patterns were widened to accept an optional path prefix. Running `UTCP_CI_BIN_DIR=/tmp/utcp-tools/gateway scripts/ci/install-kubernetes-tools` (the exact local cache path `scripts/doctor`, `scripts/gateway/lib`, and `scripts/observability/lib` already check) now populates `kubectl`, `k3d`, and `helm` with verified checksums. `make doctor`, `make observability-config-check`, `make observability-apply`, and `make observability-status` all passed with `helm` resolved from that path; no system-wide install, no root, and no unpinned download were used.

## Authenticated Proof-Node Lifecycle

`scripts/simulator/lib` and `scripts/simulator/api-proof` were added, following the same CSRF/session pattern as `scripts/identity/api-proof` and `scripts/runtime-registry/api-proof`. The canonical node (`slug: local-deterministic-simulator`, `runtime_family: simulator`, `adapter_key: simulator-deterministic`) is created idempotently (looked up by slug first, created with an `Idempotency-Key` only if absent), configured with no endpoint and no credential, given `event.stream`/`runtime.configuration`/`runtime.observation` capabilities, configured through `PUT .../adapter-configuration`, and activated through `POST .../desired-state`. Re-running the script reuses the same node id.

## Live Scenario Runtime Proof

All seven scenarios were proven against the deployed `utcp-local` Kubernetes workers, with no manual dispatch/normalization/projection/reconciliation command invoked at any point.

- **steady-ready**: observed `ready` in 30-35s; reconciliation `converged`; 1 operation, 3 processed receipts, 1 checkpoint.
- **transient-failure-then-ready**: first attempt failed `transient_transport` (retryable); automatic retry after the computed backoff succeeded; observed `ready`; exactly 1 succeeded operation.
- **terminal-failure**: operation reached `terminal_failed`; reconciliation reached `blocked`; confirmed stable after a 70s settle window with exactly 1 operation total and `observed_state` never `ready`.
- **timeout-then-ready**: first attempt failed `timeout` (retryable); automatic retry succeeded; observed `ready`.
- **duplicate-observation**: 2 scheduled publications sharing one dedupe key; exactly 1 event receipt and 1 observation recorded; observed `ready`.
- **disconnect-reconnect**: 2 connection epochs recorded, the first `closed`; observed `ready` restored from the second epoch. The stale-epoch rejection assertion (an event arriving against an already-closed epoch must raise a `DomainException`) is proven by the focused PostgreSQL test `SimulatorRuntimeProofTest::test_disconnect_reconnect_epochs_and_configuration_drift_converge_without_duplicate_repairs` rather than live, because the live cluster exposes no public event-ingestion route capable of targeting a closed epoch — only the internal `simulator-event-source` worker can call `RuntimeEventReceiptRepository::ingest()`, and forcing it out-of-band would require bypassing the live authority path this corridor proves. This is the one explicitly-sanctioned hybrid split.
- **configuration-drift-then-converge**: first reconcile pass (never-observed generation) produced a `degraded` observation at `configuration_version - 1`; the append-only `runtime_observations` audit trail recorded it even though the live current state had already advanced past it by the time of inspection. A second automatic reconcile pass detected the drift, issued exactly one `apply_configuration` operation, and converged to `ready` at the full desired generation. Exactly 2 operations total, exactly 1 `apply_configuration` operation.

## Root Cause: Reconciliation Fencing/Idempotency Defects (found via live concurrency)

Running six scenario nodes concurrently (a load level the simulator authority had not previously carried) exposed two real defects in the generic C3 engine, unrelated to the simulator's own code:

1. `ReconciliationRepository::claimDue()` used plain `lockForUpdate()` instead of the `FOR UPDATE SKIP LOCKED` pattern already used by `RuntimeOperationRepository::claimAvailable()`. The continuous per-role Deployment and the scheduler's per-minute `--once` invocation of the same role can run concurrently; without `SKIP LOCKED` this increased contention and lock-wait duration. The same missing pattern was found and fixed in `RuntimeEventReceiptRepository::claimAvailable()` and `OutboxRepository::claimAvailable()`.
2. `ReconciliationWorker::workOnce()` re-initialized the per-claim `$operationId` to `null` before every `evaluate()` call, instead of preserving `$claim->last_operation_id`. Any non-`operation_required` result (`blocked`, `waiting`, `converged`) wiped out an already-linked `last_operation_id`. Combined with `RuntimeOperationRepository::create()` having no idempotent-reuse lookup before insert, a target that reached `blocked` and was later force-rechecked (the periodic `simulator:ensure-targets` sweep resets `next_check_at` unconditionally) would lose its operation link, re-request an operation with the same deterministic idempotency key, and hit the `runtime_ops_idempotency_unique` constraint — an uncaught exception that aborted the reconciler's whole claimed batch.

Both are fixed: `create()` now checks `findIdempotent()` before inserting, and `workOnce()` initializes `$operationId = $claim->last_operation_id`. A regression test (`RuntimeEngineTest::test_reconciler_preserves_last_operation_id_across_non_operation_required_results`) was added and confirmed to fail against the pre-fix code and pass against the fix. Live re-verification showed a `terminal-failure` node's reconciliation state stable at `blocked` across five consecutive minute-cycles with exactly one operation, with no oscillation and no exception in `telephony-reconciler` logs.

## Deterministic Restart and Recovery Proof

**Event source**: a `steady-ready` node's `simulator-event-source` Deployment Pod was identified and force-deleted (`--grace-period=0 --force`) while 1 of 3 scheduled events for that node was still `pending` (2 already `published`). The Deployment replaced the Pod (new Pod observed `Running` within seconds); the scheduled-event rows survived unchanged in PostgreSQL; the replacement Pod published the remaining event; the node reached `ready` with exactly 3 processed receipts, 3 observations, and 1 checkpoint (no duplicates).

**Generic C3 worker**: a `transient-failure-then-ready` node's operation was allowed to reach `retry_scheduled` (a real ~15s backoff window); the `telephony-command-worker` Pod was force-deleted during that window. The Deployment replaced the Pod; the operation remained `retry_scheduled`, untouched; once `available_at` elapsed, the replacement Pod's next poll claimed and completed it. Exactly 1 operation total; node reached `ready`.

The exact sub-millisecond stale-lease-token race (deleting a Pod between `claimDue()` and `markPublished()`/completion) could not be safely forced deterministically through external `kubectl` polling; the generic lease/fencing mechanism for that race is proven by the existing focused tests (`RuntimeEngineTest::test_outbox_dispatch_claims_leases_queue_delivery_and_fencing`, `test_reconciler_leasing_idempotent_operation_generation_blocked_and_takeover`).

## Simulator Metrics and Alerts

`https://utcp.local.test/api/metrics` (trusted system CA, no `-k`) returned all six simulator metrics with scenario-level aggregation only; a scan for `tenant`, `runtime_node`, `operation_id`, `event_id`, `request_id`, `seed`, and `configuration_generation` labels found none.

The Prometheus `utcp-application` ServiceMonitor target was `down` (`connect: connection refused`) because the K4 observability NetworkPolicy set had an ingress-only rule (`allow-application-metrics-from-prometheus`, in `utcp-platform`) with no matching egress rule from Prometheus's own default-deny `utcp-observability` namespace. A second, narrowly-scoped NetworkPolicy (`allow-prometheus-egress-to-application-metrics`, podSelector `app.kubernetes.io/name: prometheus` only) was added allowing egress to the gateway Pod on port 8080 only. The target became `up` within one scrape interval; `simulator_operations_total` became queryable through the Prometheus API. All four simulator alerts (`UTCPSimulatorEventSourceUnavailable`, `UTCPSimulatorScheduledEventBacklogGrowing`, `UTCPSimulatorTerminalFailures`, `UTCPSimulatorReconciliationStuck`) loaded with `health: ok`, `state: inactive`. `make observability-proof`'s existing synthetic-alert convention (temporary `PrometheusRule` with `vector(1)`, evaluated to firing, deleted, confirmed resolved in Alertmanager) passed.

## No-Fallback Proof

`simulator-config-check` asserts: no `simulator/advance|emit|reconcile` route, no `api/v1/admin/ari|esl|asterisk|freeswitch` route, no vendor-telephony vocabulary inside `apps/api/app/Simulator`, no `SimulatorRuntimeAdapter` reference inside `apps/api/app/RuntimeEngine` or `apps/api/app/ControlPlane`, and `UTCP_PHASE=C4` must not appear in `versions.env` until this proof passes. `control-plane-config-check`'s runtime-vocabulary scan was corrected to exclude the C4 simulator module and its composition-root wiring line (both legitimate now that C4 exists) while continuing to forbid real telephony-vendor vocabulary everywhere, including inside the simulator module itself.

## Regression Corridor

Full local regression passed: `doctor`, `repository-hygiene`, `workflow-check`, `secret-scan`, `local-status`, `control-plane-*`, `identity-*`, `runtime-registry-*`, `runtime-engine-*`, `simulator-*` (including the two live proofs, twice, for idempotency), `k8s-*`, `gateway-proof`, `security-proof`, `observability-proof`, `local-proof`, `compose-proof` (disposable, cleaned up), `test`, `check`, `build`, `container-check`, `git diff --check`, shell/Python/PHP syntax checks. `scripts/runtime-engine/status`'s live-detection `grep -qx` never matched Laravel's padded `artisan list --raw` output, so it always silently used its degraded fallback (which also had three wrong table names); both were corrected.

## Unresolved Proof Gaps

- Disconnect-reconnect's stale-epoch rejection is proven at the focused-test level only (explicitly sanctioned hybrid split above).
- The sub-millisecond stale-lease-token race for the event-source and command-worker restart proofs is proven at the focused-test level only, not forced live.
- Hosted GitHub Actions execution of the `deterministic-simulator` CI job has not been observed; only local execution of the same underlying `make` targets was observed.
