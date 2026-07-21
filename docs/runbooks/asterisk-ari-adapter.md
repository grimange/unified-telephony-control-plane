# Asterisk ARI Adapter Runbook

T0 proves Asterisk ARI transport and runtime observation only. It does not provision ConfBridge conferences, channels, SIP endpoints, RTP, media, trunks, or browser calling.

## Authority

- C2 owns the `RuntimeNode`, endpoints, encrypted write-only ARI credential metadata, declared capabilities, and desired lifecycle state.
- C2 owns the per-node `asterisk_ari_profiles` adapter configuration record. Environment values provide bounded creation defaults only; configured nodes are not driven by permanent global ARI environment authority.
- `AsteriskAriClient` owns ARI HTTP and WebSocket transport inside the adapter boundary.
- `asterisk-ari-events` dynamically discovers eligible active `asterisk-ari` RuntimeNodes and owns one node-scoped listener lease at a time.
- C3 owns runtime operations, leases, fencing, connection epochs, raw receipts, normalized observations, projection checkpoints, and reconciliation.
- PostgreSQL is the durable authority. Redis remains transient queue/coordination infrastructure only.
- Normal management authority is the web-admin UI calling canonical authenticated RuntimeNode APIs. Proof scripts are automated tests against those same APIs, not a second management surface.

## Local Runtime Fixture

The local Kubernetes fixture deploys an internal Asterisk ARI service in `utcp-runtime`. It exposes only the internal ARI HTTP/WebSocket port through ClusterIP. It has no public Service, Gateway route, NodePort, LoadBalancer, host port, SIP listener, RTP port, PJSIP endpoint, ConfBridge execution, host network, host PID, hostPath, Docker socket, or Kubernetes API token.

Local ARI credentials are generated into an ignored repository runtime file by the Kubernetes lifecycle and are written into C2 through the authenticated API proof. They must not appear in endpoint URLs, logs, audit records, outbox events, receipts, status output, or evidence.

## Verification

```sh
make asterisk-ari-config-check
make asterisk-ari-test
make k8s-image-build
make k8s-image-push
make k8s-apply
make asterisk-ari-api-proof
make asterisk-ari-runtime-proof
make asterisk-ari-status
```

`asterisk-ari-api-proof` uses the normal C1 session and CSRF lifecycle to create or reuse `local-asterisk-ari`, configure endpoints, write the ARI credential through the write-only C2 API, configure the per-node ARI profile through the canonical adapter-configuration route, declare only `runtime.observation` and `event.stream`, and activate the node.

`asterisk-ari-runtime-proof` must observe deployed workers performing inspection, WebSocket connection, raw receipt ingestion, normalization, projection, and readiness convergence. It must not replace the normal lifecycle with manual runtime command execution.

## RuntimeNode Management

The RuntimeNode detail page is the PBX-oriented management surface for Asterisk ARI nodes. It renders backend catalog metadata, persisted node capabilities, safe credential metadata, per-node adapter configuration, desired-versus-observed evidence, and scoped audit history. It does not reveal credentials, duplicate endpoints inside the ARI profile, expose raw ARI event payloads, or provide manual listener/reconcile/retry/mark-ready controls.

The adapter-configuration API validates bounded timeout and reconnect settings, stores them in PostgreSQL, audits successful changes, advances the node configuration generation, and wakes automatic processing. A missing profile is incomplete configuration and prevents listener eligibility until the explicit profile exists.

## T2 Conference Recovery Telemetry

T2 conference recovery remains adapter-neutral in the C3 and C5 layers. The generic reconcilers decide whether desired and observed conference state requires runtime inspection or a generic conference operation. Asterisk-specific bridge and channel inspection stays inside the Asterisk adapter, which translates runtime state into bounded inspection evidence.

The metrics endpoint exposes low-cardinality recovery telemetry:

- `utcp_conference_runtime_inspections_10m`: runtime inspections grouped by adapter, resource type, result, and failure class.
- `utcp_conference_runtime_inspection_failures_10m`: unavailable or failed inspections grouped by adapter, resource type, failure class, and reason.
- `utcp_conference_recovery_operations_total`: conference recovery operations grouped by operation, result, and failure class.
- `utcp_conference_recovery_operation_failures_total`: retrying or failed recovery operations grouped by operation, result, and failure class.
- `utcp_conference_recovery_stale_events_rejected_total`: stale or superseded conference event receipts grouped by result and reason.
- `utcp_conference_recovery_backlog`: current non-converged conference reconciliation targets grouped by resource type and result.
- `utcp_conference_recovery_lag_seconds`: oldest current recovery lag grouped by resource type.

These metrics must not label by tenant, user, conference, participant, RuntimeNode, RuntimeBinding, bridge, channel, operation ID, receipt ID, event epoch, owner, fencing token, or request ID.

The Prometheus alert rules cover repeated recovery operation failures, prolonged recovery backlog or lag, and repeated runtime inspection failures. They intentionally do not fire merely because there are zero Conferences or zero participants.

## T2 Recovery Proof Corridors

The repository recovery proof script supports independently invocable live corridors for later Claude Code acceptance:

```sh
scripts/asterisk-conference/recovery-runtime-proof --corridor listener_restart_recovery
scripts/asterisk-conference/recovery-runtime-proof --corridor event_gap_recovery
scripts/asterisk-conference/recovery-runtime-proof --corridor asterisk_restart_recovery
scripts/asterisk-conference/recovery-runtime-proof --corridor retryable_partial_failure
scripts/asterisk-conference/recovery-runtime-proof --corridor close_before_remove_cleanup
scripts/asterisk-conference/recovery-runtime-proof --corridor all
```

The default invocation remains equivalent to the complete `all` sequence. Each corridor keeps bounded timeouts, uses the canonical authenticated Conference lifecycle, prints only safe result summaries, and preserves cleanup traps. The script is proof tooling only; it is not a runtime management interface.

Projected participant `left` is runtime evidence, not proof that an Asterisk channel no longer exists. Participant removal remains inspection-driven: when a participant is desired `removed` and the active runtime binding still reports its channel present or attached, reconciliation dispatches `conference.participant.remove`; when inspection proves the channel absent, the adapter records sanitized absence evidence and convergence proceeds through the normal projection/reconciliation path. The close-before-remove parked-channel leak is corrected at reconciliation authority, and live regression proof remains pending for Claude Code.

T2-B source-level repository work keeps `UTCP_PHASE=T1`. Live acceptance of listener restart, event-gap recovery, Asterisk restart reconstruction, retryable partial failure, close-before-remove participant cleanup, recovery alert fire/resolve, and final orphan cleanup remains a separate Claude Code corridor.

## T2-B11 Listener Drain and Ensure Suppression

The ARI event listener drains immediately available WebSocket frames in one `workOnce` cycle for each claimed connection. The drain stops as soon as `readEvent()` returns `null` and is capped by the deterministic repository configuration `max_events_per_cycle` so one busy connection cannot monopolize the listener indefinitely. The five-second outer poll cadence, heartbeat cadence, lease behavior, and reconnect/teardown path remain unchanged.

Participant ensure remains valid only while the parent Conference desired state is `open`. If an admitted participant is reconciled after the Conference has moved to `draining`, `closed`, or any other non-open desired state, the reconciler waits with `conference_not_open_for_participant_ensure` and does not create a stale ensure operation. The adapter generation fence remains in place as defense in depth. Live proof that ARI burst latency and stale participant-ensure churn are eliminated remains pending for Claude Code.

## Asterisk 20 Local Channel Readiness

The Asterisk 20 image uses the pinned `andrius/asterisk:20` digest already recorded by the repository. Local channel support is core-resident in this supported Asterisk version, so `chan_local.so` must not be configured, compiled, or added as a compatibility fallback. The conference proof fixture continues to prove Local-channel use through the `Local/participant@utcp-conference-proof/n` dial string.

The runtime image includes `/usr/local/bin/utcp-asterisk-readiness`. Kubernetes readiness executes that script instead of a port-only TCP check. The script waits for Asterisk to finish booting, confirms the local ARI HTTP route returns the expected unauthenticated ARI response, derives required loadable modules from `/opt/utcp-asterisk-config/modules.conf`, requires every configured module to report `Running`, and separately verifies that the `Local` channel technology is registered through `core show channeltypes`.

This repository change does not complete live T2-B acceptance. Deployment of the readiness change, Asterisk restart recovery rerun, retryable partial-failure rerun, alert evaluation, and final T2 acceptance remain pending until the separate `utcp-local` stale-node-IP environment fault is isolated or repaired.

## Exclusions

T0 deliberately excludes ConfBridge, C5 conference operation support on Asterisk, channel origination, channel control, dialplan execution, SIP registration, PJSIP configuration, Kamailio, RTP/media, rtpengine, browser WebRTC, trunks, PSTN, and FreeSWITCH ESL. Those remain later roadmap phases.
