# Deterministic Simulator Runbook

## Scope

C4 provides a deterministic, runtime-neutral simulator adapter that proves the C0-C3 control-plane contracts end to end before any real SIP, media, Asterisk, FreeSWITCH, Kamailio, or rtpengine integration is introduced. It does not implement telephony sessions, conference behavior, SIP registration, or any T0-T5/V0 vertical-slice behavior.

The simulator is selected explicitly through the same authenticated runtime-registry API used by every other adapter family:

```text
runtime_family: simulator
adapter_key: simulator-deterministic
```

There is no hidden environment gate, feature flag, or fallback path that activates the simulator implicitly.

## Authority

PostgreSQL is authoritative for `RuntimeNode` configuration, the simulator adapter profile (`simulator_profiles`), simulator scenario state (`simulator_states`), and scheduled simulator events (`simulator_scheduled_events`). The simulator adapter and event source integrate with the generic C3 engine through the same `RuntimeAdapter`, raw-event-receipt, observation, checkpoint, and reconciliation contracts that real adapters must use.

Redis remains transient queue delivery only; C4 does not add canonical Redis state.

## Process Roles

The backend image adds one C4 role on top of the C3 roles:

```text
simulator-event-source
```

It runs automatically as a Kubernetes Deployment and does not require, and must never receive, a manual `advance`, `emit`, or `reconcile` trigger. The generic C3 roles (`control-plane-outbox-dispatcher`, `telephony-command-worker`, `telephony-event-normalizer`, `telephony-reconciler`) are unmodified by C4; the simulator only supplies a `RuntimeAdapter`, a `RuntimeOperationHandler` pair (`inspect`, `apply_configuration`), and a normalizer registered under the `simulator-deterministic` adapter key.

## Configuring a Simulator Node

Through the same session-authenticated admin API used by every runtime family:

1. `POST /api/v1/admin/runtime-nodes` with `runtime_family: simulator`, `adapter_key: simulator-deterministic` (idempotent via `Idempotency-Key`).
2. `PUT /api/v1/admin/runtime-nodes/{id}/capabilities` with `event.stream`, `runtime.configuration`, `runtime.observation`.
3. `PUT /api/v1/admin/runtime-nodes/{id}/adapter-configuration` with a `scenario_key`, `seed`, and optional `parameters`. This is the canonical adapter-configuration API; it is the only way to select or change simulator behavior.
4. `POST /api/v1/admin/runtime-nodes/{id}/desired-state` with `desired_state: active`.

No endpoint and no credential are required or accepted for the simulator adapter. From this point, `simulator:ensure-targets` (scheduled every minute), the reconciler, the command worker, the simulator event source, and the event normalizer converge the node automatically.

## Scenario Catalog

```text
steady-ready
transient-failure-then-ready
terminal-failure
timeout-then-ready
duplicate-observation
disconnect-reconnect
configuration-drift-then-converge
```

Scenario behavior is deterministic given a `seed`, and is implemented entirely inside `App\Simulator`; the generic C3 engine (`App\RuntimeEngine`, `App\ControlPlane`) never branches on `SimulatorRuntimeAdapter` or on any scenario key.

## Verification

```sh
make simulator-config-check
make simulator-test
make simulator-api-proof
make simulator-runtime-proof
make simulator-status
```

`simulator-test` runs the focused PostgreSQL-backed feature suite (`tests/Feature/Simulator`) using in-process worker calls; it is a lower-level proof of the same contracts and does not require the live cluster.

`simulator-api-proof` and `simulator-runtime-proof` are live proofs. They authenticate against the running `utcp-local` cluster through the same CSRF/session flow as every other API proof, create or reuse the canonical `local-deterministic-simulator` node (`simulator-api-proof`) and dedicated per-scenario proof nodes (`simulator-runtime-proof`), and then poll PostgreSQL read-only for convergence evidence produced by the already-running Kubernetes Deployments. Neither script invokes a manual dispatch, normalization, projection, or reconciliation command; every state transition is produced by the deployed workers on their normal poll cycles.

## Metrics and Alerts

`GET /api/metrics` (proxied through the gateway and scraped by Prometheus) exposes:

```text
simulator_operations_total
simulator_scheduled_events
simulator_event_publish_total
simulator_scenario_transitions_total
simulator_connection_epochs_total
simulator_reconciliation_total
```

Labels are bounded to operation type, result, scenario, failure class, event type, and due state; there is no tenant, runtime-node, operation-id, event-id, request-id, seed, or configuration-generation label.

Alerts (`infrastructure/kubernetes/observability/alerts/utcp-alerts.yaml`):

```text
UTCPSimulatorEventSourceUnavailable
UTCPSimulatorScheduledEventBacklogGrowing
UTCPSimulatorTerminalFailures
UTCPSimulatorReconciliationStuck
```

## Safety Boundaries

- No public simulator Service, Gateway route, or external egress; the `simulator-event-source` NetworkPolicy allows only DNS, PostgreSQL, and Redis.
- No manual simulator advance/emit/reconcile route exists.
- No CLI simulator management surface; the web/API authority is the only management path.
- No vendor telephony vocabulary (Asterisk, FreeSWITCH, Kamailio, rtpengine, ARI, ESL, SIP, WSS) inside `apps/api/app/Simulator`.
- The generic C3 engine contains no `SimulatorRuntimeAdapter` reference.

## Troubleshooting

- `simulator_nodes=0` in `make simulator-status`: no simulator-family `RuntimeNode` exists yet; create one through the admin API.
- A node stays `unobserved` after activation: confirm a `simulator_profiles` row exists (`adapter-configuration` was PUT) and the node's capabilities include `runtime.observation` and `runtime.configuration`.
- A node reaches `blocked`: inspect `runtime_operations.last_failure_code` through `make runtime-engine-status`; this is normal and permanent for `terminal-failure` scenario nodes.
- Reconciliation oscillates between `operation_required` and `blocked`: this was a real defect in `ReconciliationWorker::workOnce()` (it re-initialized the per-claim operation id to `null` instead of preserving `$claim->last_operation_id`) and in `RuntimeOperationRepository::create()` (no idempotent-reuse lookup before insert); both are fixed. If this recurs, capture `runtime_reconciliation_states.last_operation_id` across consecutive polls before changing anything.
