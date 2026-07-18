# T2-B Evidence — Asterisk Conference Recovery (Restart and Retryable Partial Failure)

Date: 2026-07-17/18 (local `utcp-local` cluster)
Baseline commit under proof: `799c812` (`fix(t2): remove legacy Local module reference and harden Asterisk readiness`) plus the corrections recorded in this commit.
`UTCP_PHASE=T1` throughout.

## 1. Cluster node-address repair

After a host/Docker restart, the `utcp-local` k3d node containers had re-acquired
Docker network addresses while Kubernetes Node objects retained stale
`INTERNAL-IP` values (two agents reporting the same address). `kubectl exec`
and `kubectl logs` failed through the kubelet proxy (502 via the API server and
kubelet serving-certificate IP mismatch), and Pods on the affected agent were
stuck `Unknown`/`ContainerCreating`.

Repair result: after the most recent full stop/start of the node containers,
every kubelet re-registered its current address. No cluster recreation, node
replacement, or manual certificate action was required (Level 1/2 outcome —
bounded container restart). Verified after repair:

- three Nodes `Ready`, each with a unique `INTERNAL-IP` equal to its Docker
  container address (`agent-0` .5, `agent-1` .2, `server-0` .4);
- `kubectl exec` and `kubectl logs` verified against workloads on all three
  nodes (API Pod on agent-0, Asterisk Pod, Postgres on server-0);
- all core UTCP Deployments available; `make local-status`,
  `runtime-registry-status`, `runtime-engine-status`, `telephony-domain-status`,
  `kamailio-signaling-status`, `asterisk-ari-status`,
  `asterisk-conference-status`, and `asterisk-conference-recovery-status` all
  succeed with no `api_pod_unavailable`.

## 2. Module-aware Asterisk readiness deployment (commit 799c812)

Built and pushed through `make k8s-image-build` / `k8s-image-push` /
`k8s-apply`; the `asterisk-ari` Deployment rolled to the new image.

Proven on fresh Pods (initial rollout, corridor pod replacement, and
scale-to-zero recovery — three independent fresh containers):

- readiness probe execs `/usr/local/bin/utcp-asterisk-readiness` (manifest and
  live Pod spec);
- the Pod remained not-Ready for the gated window after container start
  (observed 17–18 s from `startedAt` to `Ready=True`) and only became Ready
  once the script passed;
- startup logs contain zero `Error loading module` lines;
- all 40 configured `load =` modules report `Running`;
- `core show channeltypes` lists the core-resident `Local` driver;
- ARI HTTP answers locally; the `utcp-conference-proof` dialplan parses;
- the RuntimeNode projected observed `ready` only after Pod readiness, and
  projected `unavailable` while Asterisk was absent during the
  retryable-partial-failure corridor.

## 3. Live defect found and fixed: lost ARI Stasis subscription after runtime replacement

With readiness fixed, the T2-A baseline still failed at participant ensure on a
fresh Asterisk. Live evidence chain:

- ARI originate succeeded and the Local channel executed the proof dialplan,
  but Asterisk logged `Stasis app 'utcp-t0-observation' doesn't exist`;
- the events listener had logged nothing across the entire Asterisk Pod
  replacement: its WebSocket to the vanished Pod was half-open, `readEvent`
  returns null indefinitely on such a socket, and the periodic health check
  used ARI HTTP through the Service DNS — which reached the *new* Pod and
  passed — so the listener never reconnected and the Stasis application was
  never re-registered on the replacement runtime.

Fix (this commit): the listener health check now also verifies through the
existing control endpoint that the Stasis application is still registered on
the runtime (`AsteriskAriClient::stasisApplicationRegistered`, ARI
`GET /applications/{app}`). When registration is gone, the listener raises the
existing retryable transport failure path: failure receipt, reconnect backoff,
teardown, and automatic reconnect that re-registers the application. Focused
regression: `test_listener_tears_down_connection_when_stasis_application_registration_is_lost`.
Live confirmation: after deployment the listener re-subscribed within one
heartbeat of every subsequent Asterisk replacement, and the T2-A baseline
(`make asterisk-conference-runtime-proof`) passed end to end.

## 4. Proof-environment defect: 1-minute telephony session lifetime

The first `asterisk_restart_recovery` rerun then failed only at the final
projection step. Direct evidence: the proof participant's telephony session was
created with a 60-second lifetime
(`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=1` in the local overlay), and the C5
expiry automation — behaving correctly — expired the session mid-corridor,
marked the participant removed, and removed the reconstructed channel. Every
later join evidence was then correctly rejected (desired state no longer
`admitted`).

Correction (this commit): local overlay lifetime raised to 5 minutes; the C5
expiry proof timeout (`session_expired`) retuned from 180 s to 480 s to match;
both long recovery corridors now fail fast with an explicit
`*_session_lifetime_too_short_for_recovery_corridor` reason if the proof
session cannot outlive the corridor, and the conference config check enforces
the guard's presence.

## 5. asterisk_restart_recovery — passed

`scripts/asterisk-conference/recovery-runtime-proof --corridor asterisk_restart_recovery`
passed with all invariants:

- baseline conference and participant converged (bridge and Local participant
  channel present and projected);
- only the Asterisk Pod was replaced; the RuntimeNode was observed non-ready
  during replacement and the module-aware readiness script gated the
  replacement Pod;
- the pre-existing RuntimeBinding remained authoritative after restart
  (`runtime_binding_preserved=passed`);
- recovery reconstructed exactly one bridge and one Local participant channel
  automatically (reconnect drift inspection woke recovery; reconciliation
  dispatched the operations);
- conference projected ready, participant projected joined, reconciliation
  converged, no stale pre-restart event was accepted (stale-marked evidence was
  rejected by generation/desired-state guards);
- corridor cleanup left zero proof bridges and channels
  (`orphan_inspection=passed`).

## 6. retryable_partial_failure — passed

The corridor's unavailability predicate was corrected to match the proven C3
contract: while the runtime is unreachable the participant reconciler *waits*
(`conference_participant_runtime_inspection_unavailable`) and deliberately does
not create doomed operations — the same contract already locked by
`test_runtime_unavailability_waits_without_projecting_false_absence`. The
corridor now proves, and passed:

- Asterisk scaled to zero through the established corridor mechanism;
- desired participant join submitted canonically while unavailable;
- RuntimeNode projected observed `unavailable`;
- participant reconciliation held `waiting` with no dispatched operation and
  **zero** premature `conference.participant.ensure` operations;
- no false `joined` projection occurred during unavailability;
- after restore, module-aware readiness passed, reconciliation automatically
  created and succeeded the participant ensure, exactly one Local participant
  channel appeared, the participant projected joined, and reconciliation
  converged;
- original replica count restored; cleanup passed.

## 7. Final orphan and environment inspection

After both corridors: zero proof bridges, zero proof participant channels, zero
open proof conference operations, zero non-converged proof reconciliation
targets. Asterisk Deployment at one ready replica; listener healthy;
RuntimeNode `ready`; API/workers/Kamailio/observer/Postgres/Redis healthy;
ports 80/443 remain UTCP-owned; no persistent UTCP Compose project; APNTalk
untouched; global kube context still `k3d-apntalk-local`.

Two proof-cleanup defects were found and corrected during this inspection:

- the recovery proof registered joined participants inside a command
  substitution subshell, so its cleanup loop never removed them; participants
  are now registered by the caller (`register_participant`) so corridor
  cleanup removes channels before conference close;
- one orphaned Stasis-parked Local channel pair (left over from a corridor run
  before that fix) was removed: the canonical admin participant-remove API was
  invoked first, and because the domain had already converged
  (observed `left` projected from bridge teardown), the surviving parked legs
  were hung up directly on the runtime as disposable proof artifacts. This is
  recorded transparently below as a remaining platform gap.

## 8. close_before_remove_cleanup — passed live (T2-B8, commit 176af2e deployed)

Live acceptance of the reconciliation correction in `176af2e`
(`fix(t2): verify runtime absence before participant teardown convergence`),
2026-07-18 on `utcp-local`.

Deployment currency: the running `telephony-reconciler` (and `scheduler`,
which also runs `runtime-engine:reconciler --once`) were rolled after
`make k8s-image-build`/`k8s-image-push`/`k8s-apply`; the deployed
`ConferenceParticipantReconciler.php` is md5-identical to the repository file
and no longer contains the removed/left convergence early-return. Asterisk,
Kamailio, Traefik, and unrelated roles were not restarted.

`scripts/asterisk-conference/recovery-runtime-proof --corridor close_before_remove_cleanup`
passed end to end (`close_before_remove_cleanup=passed`,
`orphan_inspection=passed`) after a clean T2-A baseline run:

- baseline: one open conference, one admitted participant projected joined,
  one active RuntimeBinding, one real bridge, one real Local participant
  channel, both reconciliation targets converged; no client-supplied runtime
  identifiers;
- the conference was closed **before** participant removal: `conference.close`
  dispatched normally, the bridge was destroyed, and the participant projected
  `left` from bridge-teardown evidence while its desired state was still
  `admitted`;
- the parked Local channel remained observable through bound ARI inspection —
  the exact historical failure condition — and no manual hangup was performed;
- participant desired state was then set to `removed` through the canonical
  admin API; the reconciler did **not** converge from removed+left alone:
  bound runtime inspection ran, detected the parked channel
  (`inspection:channel-present` receipt recorded for the participant), and a
  new generic `conference.participant.remove` operation was dispatched
  automatically (+6 s) and succeeded;
- all Local channel legs disappeared without any Asterisk CLI or direct ARI
  cleanup; subsequent inspection recorded absence
  (`inspection:channel-absent`), absence evidence reached the projector, the
  participant remained `left`, and reconciliation converged;
- exactly one `conference.participant.remove` operation existed afterwards —
  no duplicate-removal loop;
- final inspection: zero proof bridges, zero proof participant channels, zero
  open proof operations, zero non-converged proof targets; Asterisk at one
  ready replica; RuntimeNode `ready`; listener, API, workers, Kamailio,
  observer, Postgres, and Redis healthy.

Observed quality gap during the corridor (bounded, invariants unaffected):
while the closed conference still had an admitted participant, the recovery
wake path dispatched ~60 stale no-op `conference.participant.ensure`
operations at ~3 s cadence (each completed as a stale no-op without touching
Asterisk), and the corridor's admitted+left projection predicate took ~180 s
to settle even though the bridge-teardown `left` observation was recorded
within seconds of the close. This churn/wake-storm behavior is recorded below
as a remaining gap.

## 9. Live recovery-alert evaluation — passed (T2-B9, 2026-07-18)

Monitoring diagnosis first corrected the record: the Prometheus Operator was
**not** crash-looping at T2-B9 start (1/1 Ready; its historical 59 restarts
dated from the earlier cluster node-address fault). The actually failing
component was the Grafana dashboard sidecar (`grafana-sc-dashboard`,
CrashLoopBackOff ×37) — and the real defect behind both its crashes and a
silent Prometheus outage was environmental: after the node-IP shuffle, two
rendered NetworkPolicies still pinned the old API-server endpoint
(`172.24.0.2/32` instead of `172.24.0.4/32`):

- `utcp-observability/allow-observability-kubernetes-api-egress`
- `traefik-system/allow-traefik-kubernetes-api`

With kube-proxy DNAT translating the `kubernetes` Service VIP to the endpoint
address, all API egress from observability Pods was dropped: the Grafana
sidecar crash-looped listing dashboard resources, and Prometheus
service discovery silently emptied — zero active targets and no scraped
`utcp_*` series since the node-IP fault, with no errors in the Prometheus log.

Correction (live/environmental only; the repository templates were already
correct): both policies were re-rendered and re-applied through their
canonical render mechanisms (`scripts/observability/lib
render_apiserver_policy` and the K3 security equivalent). No repository file
changed; no manual long-term workaround was added. Discovery repopulated to
10 scrape pools with all expected targets up, and the Grafana replacement Pod
came up 2/2 with zero restarts.

Operator reconciliation proof: the operator Pod was deleted once; the
replacement synced caches with no errors and the full `utcp-platform-alerts`
group (34 rules, including the three recovery rules) remained loaded in the
Prometheus rules API with `health=ok` — reconciliation is operator-owned and
survives operator replacement. The `PrometheusRule` namespace/labels match the
Prometheus `ruleNamespaceSelector` (`utcp-observability`) and empty
`ruleSelector`.

Metrics proof: all seven recovery series
(`utcp_conference_runtime_inspections_total`,
`utcp_conference_runtime_inspection_failures_total`,
`utcp_conference_recovery_operations_total`,
`utcp_conference_recovery_operation_failures_total`,
`utcp_conference_recovery_stale_events_rejected_total`,
`utcp_conference_recovery_backlog`, `utcp_conference_recovery_lag_seconds`)
are queryable with historical corridor evidence retained; the 34 series carry
only bounded label keys (`adapter_key`, `failure_class`, `operation`,
`reason`, `resource_type`, `result`) with no tenant/conference/participant/
runtime/bridge/channel/operation/receipt/epoch/fencing identifiers; backlog
and lag were 0 after completed recovery.

Alert-expression proof: all three recovery alert expressions evaluate without
error and were FALSE/inactive in the healthy state. A bounded live condition
(one proof conference; Asterisk scaled 0→1 through the established corridor
mechanism, restored within ~2.5 minutes) drove
`UTCPTelephonyConferenceRuntimeInspectionFailures` through the complete
lifecycle, directly observed in the `ALERTS` series: inactive → expression
TRUE within 60 s → **pending** (23:59–00:08) → **firing** (00:09–00:19,
after the full production `for: 10m`, satisfied by real
`ari_http_transport_failed` inspection failures in the rolling window) →
expression FALSE and rule **inactive/resolved** (00:24) with Alertmanager
showing zero active alerts afterwards. No thresholds or `for` durations were
modified; no metric rows were fabricated. The other two recovery rules were
proven loaded, healthy, and inactive against live data; their firing was not
separately induced.

During this task, sixteen stale open proof conferences left behind by earlier
corridor runs were closed through the canonical admin API (all projected
closed and converged), so `UTCPTelephonyConferenceReconciliationStuck` no
longer has standing input. Final state: operator, Prometheus, Alertmanager,
kube-state-metrics, Loki, Alloy, and Grafana all fully Ready; zero proof
bridges/channels; Asterisk at one ready replica; all recovery alerts
inactive.

## 10. ARI event-drain latency and recovery-operation churn — isolated (T2-B10, 2026-07-18)

Evidence audit at commit `ed50f08` using one bounded
`close_before_remove_cleanup` reproduction (passed, orphan-free) plus
2-second sampling of the participant reconciliation row during the churn
window. Both historical symptoms reproduced and are now fully explained. No
production code was changed.

**Event drain (measured).** The listener process loop is
`workOnce(); sleep(poll_seconds=5)`, and `workOnce` reads **at most one
WebSocket frame per claimed connection per cycle** (single non-blocking
`readEvent` call; no drain loop). Measured receipt times show exactly one
`ari:*` frame every ~5 s (28 frames over 160 s; occasional 10 s gaps on
heartbeat cycles). A single Local participant join emits a burst of ~19
frames (channel create/state/varset events arrive as
`unknown_event_observed` under `subscribeAll=true`), so the burst alone takes
~95 s to drain. In this run `ChannelLeftBridge` (emitted 00:34:29) was read
at 00:35:59 — **90 s of pure queue delay** — then normalized and projected
within ≤2 s each. The dominant delay stage is the listener read/poll loop;
every downstream stage (ingestion, normalizer, projector) measured ≤2 s.
The historical 180 s left-projection delay was the same mechanism behind a
deeper queue.

**Operation churn (proven trigger chain).** During the window between
conference close and participant removal, the 30 ensure operations (1 genuine
join + 29 stale no-ops at ~3.3 s cadence) were sustained by a fully proven
loop:

1. `ConferenceParticipantReconciler` (admitted branch) dispatches
   `conference.participant.ensure` whenever inspection shows the participant
   not attached — it loads the conference row but never checks
   `conferences.desired_state`, so it keeps dispatching for a **closed**
   conference;
2. the Asterisk adapter's stale fence (`conference.desired_state !== 'open'`)
   completes each operation as a stale no-op — zero of the 29 touched
   Asterisk;
3. `RuntimeOperationRepository::complete()` unconditionally rewakes the
   aggregate's reconciliation target (`waiting`, `next_check_at = now()`,
   sampled live with `last_operation_id`/`attempt_count` preserved),
   overriding the reconciler's own 60 s `operation_required` pacing;
4. the reconciler's idempotency key includes `last_operation_id`, so each
   completed no-op licenses a fresh operation row.

Cycle period ≈ command-worker poll (3 s). The loop terminates only when the
drain-delayed `ChannelLeftBridge` finally projects `left` (converging the
target, which the completion-wake skips) or when removal changes the desired
state. Churn is therefore an **independent reconciliation-policy defect whose
duration is amplified by the listener drain latency** — not one shared root
cause, and not caused by event latency.

**Correctness and scaling.** No correctness violation was found: generation
and desired-state fencing kept every stale operation away from Asterisk, no
false projection occurred, and cleanup converged orphan-free. The cost is
waste: ~1 stale operation row + outbox message + evaluation per 3 s per
affected participant (linear in participants caught in a close-before-remove
window), and event-burst drain time grows linearly with frames outstanding
(~5 s × queue depth). For T2-C failover this matters: a runtime failover
generates large event bursts and many simultaneously non-converged targets,
so minute-scale evidence delays and operation-row churn would multiply.
Secondary measured artifact: every drained `unknown_event_observed` frame
projects the RuntimeNode to observed `degraded` (flapping ready/degraded
throughout the drain window), which could interact with readiness-gated
corridors and the stale-observation alert during long drains.

**Missing operational signals** (not implemented here): listener frame
backlog / emit-to-read age, and stale no-op operation visibility (stale
completions count as plain `succeeded` in
`utcp_conference_recovery_operations_total`, so churn is invisible to
metrics).

**Selected correction (implemented in T2-B11 source/tests, live proof
pending):** The listener now drains immediately available frames per claimed
connection by looping `readEvent` until it returns `null` or reaches the
deterministic per-connection `max_events_per_cycle` cap. The outer
`poll_seconds=5` cadence, heartbeat cadence, lease fencing, event ordering,
and reconnect/teardown path remain unchanged. The participant reconciler now
suppresses `conference.participant.ensure` while the parent Conference
desired state is not `open`, returning
`waiting(conference_not_open_for_participant_ensure)` instead of creating an
operation the adapter stale-generation fence is guaranteed to reject. Adapter
generation fencing remains defense in depth; live latency and stale-ensure
churn verification remains pending for Claude Code.

## 11. Remaining gaps (not closed by these corridors)
- Listener event drain and stale-ensure churn: isolated in §10 with a
  selected bounded correction (per-cycle frame drain + closed-conference
  ensure suppression) awaiting implementation before T2-C.
- Rendered NetworkPolicy endpoint pins (`allow-observability-kubernetes-api-egress`,
  `allow-traefik-kubernetes-api`) become stale whenever k3d node addresses
  change; the canonical re-render/apply mechanism recovers them, but nothing
  detects the drift automatically. Worth a bounded check in a status target.
- Multi-node failover/fencing, T2 Compose compatibility, and final T2
  phase-wide acceptance remain open.
- Host tooling: `helm` is missing from this workstation, so
  `make observability-config-check` and the K4 sections of `make local-status`
  cannot run (pre-existing, unrelated to T2-B).
