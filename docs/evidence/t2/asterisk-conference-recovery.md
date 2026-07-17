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

## 9. Remaining gaps (not closed by these corridors)
- Listener event drain rate is one frame per poll cycle (~3–5 s per event
  during recovery bursts); tolerable now but worth a bounded improvement.
- Stale-ensure churn during the close-before-remove window: the recovery wake
  path repeatedly reopens the participant target for an admitted participant
  of a closed conference (~60 stale no-op ensure operations at ~3 s cadence in
  T2-B8) and the admitted+left projection predicate settles slowly (~180 s)
  during that window. Bounded and side-effect-free, but needs a follow-up
  churn/wake-storm assessment together with the listener drain-rate item.
- Prometheus Operator remains in its pre-existing crash loop; recovery metrics
  are live and the alert `PrometheusRule` exists, but live alert evaluation is
  still unproven (separate task).
- Multi-node failover/fencing, T2 Compose compatibility, and final T2
  phase-wide acceptance remain open.
- Host tooling: `helm` is missing from this workstation, so
  `make observability-config-check` and the K4 sections of `make local-status`
  cannot run (pre-existing, unrelated to T2-B).
