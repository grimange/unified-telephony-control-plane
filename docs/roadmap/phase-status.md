# Phase Status

This is the definitive current-state ledger. Historical proof and defect
chronology remain in `docs/evidence/` and Git history; they do not compete with
the entries below.

**T1 Kamailio SIP-over-WSS signaling — Complete.** `UTCP_PHASE=T1` remains the
CI-guarded repository phase marker for the original foundation sequence. It is
not a competing current-roadmap status or a claim that T4 is incomplete.

## Current state

**Current phase:** V1 — Bidirectional external call routing and control — **COMPLETE**. A0 and the parallel K5 distributed-infrastructure track are the post-V1 work.

**Current status:** T4A/T4B are implemented and tested. T4C1/T4C2 are
implemented, tested, live-proven, and frozen. The timer-backed `media.playback`
slice is implemented, tested, and **live-proven**: a naturally originated managed
CallLeg inherits `timer_name=soft`, `call.leg.play_media` produced a full 5.000 s
timer-paced playback, `call.leg.stop_media` truncated an active playback at
2.86 s, and both `call.leg.media_started` and `call.leg.media_stopped` entered
canonical observation authority from FreeSWITCH runtime events. **T4 is
complete.** Recording remains separate. T4D does not exist. See the
[`T4 timer-backed media.playback live proof`](../evidence/t4/t4-timer-backed-media-playback-natural-live-proof.md).

**K5C — Capacity and Failure-Domain Policy is COMPLETE / NATURAL-LIVE-PROVEN.**
The 2026-08-31 controlled natural live reproof deployed repaired `main`
(`9dd173a`) through the canonical native-k3s lifecycle and closed the phase; the
remaining K5C proof gap is **NONE**. The forward migration
`2026_08_31_100000_repair_k5c_inbound_capacity_and_ordering` applied normally in
batch 7 with no manual SQL, and the live view now carries
`capacity_weight = 0 or active_telephony_work < capacity_weight` plus the full
ordering tuple, while the **deployed** Kamailio ConfigMap selects
`available_capacity, active_telephony_work` and orders by
`placement_priority asc, available_capacity desc, active_telephony_work asc,
runtime_node_id asc`. The scheduler still runs as `utcp-kubernetes-observer`
with exactly `get`/`list` on `nodes` and `pods` (writes, secrets and deployments
denied), `utcp-platform-app` remains unprivileged, and the automatic
every-minute observer projects facts agreeing exactly with Kubernetes —
`placed`, uid `faa05d1c-…`, `utcp-dev01`, region and zone genuinely absent. The
managed RuntimeNode's `Telephony policy` section keeps capacity, placement
priority, desired region and desired zone editable while integration identity
stays protected and the K5B placement section stays read-only. Baseline missing
topology with no constraint carries no K5C penalty. One natural bounded Call to
`97001` against `capacity_weight 1` raised shared active telephony work 0 → 1
and, with `cv == ocv` so exclusion was attributable to policy alone, the full
RuntimeNode was **absent from the production inbound view** and rejected for
outbound with the canonical `HTTP 422`; the existing Call continued and ended on
its own (`remote`, ~42 s), and terminal release restored eligibility
automatically. Desired region and desired zone each excluded the node
independently from both corridors while `observed_region`/`observed_zone` stayed
`ABSENT`, no Kubernetes label was added, and `observed_state` stayed `ready`.
Both constraints were then **cleared through the Web Admin** and persisted as
real `NULL`, with eligibility recovering automatically on both corridors and no
SQL recovery anywhere in the packet. Sequential saves are sound in both
directions: capacity→region preserved the new capacity and region→capacity
preserved the cleared topology, with the form reseeding from the canonical
response. The post-deploy zero-row window was correctly classified as the known
separate runtime deployment-convergence debt and awaited rather than bypassed.
Conference capacity parity and multi-candidate ordering behaviour remain
regression-proven, not natural-live-proven — no natural conference fixture and a
single-candidate environment — and neither blocks closure. All temporary policy
values were restored through the Web Admin to `capacity_weight 100`,
`placement_priority 100`, `placement_region NULL`, `placement_zone NULL`, with
the node `active`/`ready` and eligible and every `utcp-platform` Pod Ready. No
production source changed, no K5D behaviour was exercised, and no reporting work
was done. See the
[`K5C natural live proof`](../evidence/k5/k5c-capacity-failure-domain-policy-natural-live-proof.md),
the [`K5C inbound capacity and policy clear repair`](../evidence/k5/k5c-inbound-capacity-and-policy-clear-live-defect-repair.md),
and the [`K5C placement observation and managed policy UI repair`](../evidence/k5/k5c-placement-observation-and-managed-policy-ui-live-defect-repair.md).
**K5B is complete.** The 2026-08-30 natural live proof deployed the exact
K5B commit through the canonical native-k3s lifecycle and proved the whole
placement corridor: RuntimeNode `102d58ba-…` resolved its canonical workload
identity `utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085`,
correlated through namespace + `app.kubernetes.io/instance` + `part-of=utcp` to a
real Pod whose `spec.nodeName` is `utcp-dev01`, and
`GET /api/v1/admin/runtime-nodes/{id}/placement` returned **HTTP 200** with
`status: placed` and Node UID, name, readiness, addresses, topology, taints and
schedulability all matching Kubernetes. K5A and K5B report identical host
identity. A naturally discovered draft RuntimeNode returned
`no_managed_kubernetes_identity` as an unforced positive control. Three calls
produced an identical ordering digest. A fresh natural Playwright login reached
**Telephony Nodes → Details** and the placement section rendered
`HOST utcp-dev01`, `HOST STATUS Ready`, `ZONE Not reported`,
`REGION Not reported`, and the co-resident RuntimeNode — with **zero** controls
and **zero** forms inside that section. No manual placement sync, refresh, or
host assignment was required and no Kubernetes mutation occurred. **K5A is
complete.** The 2026-08-30 natural live proof deployed the
repaired commit through the canonical native-k3s lifecycle and both previously
isolated blockers converged **automatically**: the sync migration
`2026_08_30_100000_sync_k5a_identity_catalog` ran normally and persisted
`platform.infrastructure.view` with its `platform-admin` mapping, and the API Pod
now carries `utcp.io/kubernetes-api-client` so the rendered
`192.168.254.124/32:6443` policy covers it — zero unresolved
`__KUBERNETES_API_ENDPOINT_` markers remain live. The application observer read
1 Node and 36 Pods, RuntimeNode `102d58ba-…` correlated through
namespace + `app.kubernetes.io/instance` to its Pod on `utcp-dev01`,
`GET /api/v1/admin/infrastructure/hosts` returned **HTTP 200** with Node UID,
readiness, addresses, capacity, allocatable, labels, taints and a 23-workload
count all exactly matching Kubernetes, and three consecutive calls produced an
identical structural ordering digest. A natural Playwright login from the real
login page reached **Admin → Hosts** at `/admin/hosts`, the session itself held
`platform.infrastructure.view`, the page rendered the real `utcp-dev01` facts,
and a full DOM control enumeration found **zero** mutation controls and zero
forms. No manual host discovery, sync, projection, or reconciliation was
required, and no durable UTCP Host authority exists. Read-only RBAC is unchanged.
See the [`K5A live proof`](../evidence/k5/k5a-host-kubernetes-node-visibility-live-proof.md)
and [`K5A live-blocker repair`](../evidence/k5/k5a-host-kubernetes-node-visibility-live-blocker-repair.md).
**Exactly one next action:** have Claude Code run
`RMA_A_RECORDING_AUTHORITY_AND_LIFECYCLE_FINAL_NATURAL_LIVE_ACCEPTANCE` with
Stage 0 execution-eligibility verification and Stage 1 full RMA-A lifecycle
proof. The pre-Call execution-image blocker is closed by the deployed,
live-proven managed-workload observer repair: terminating and terminal Pods are
excluded, a live Ready Pod is preferred, and a prior observed image is retained
when no acceptable live image exists. See the
[execution-image observation repair](../evidence/rma/rma-a-managed-runtime-execution-image-live-pod-observation-repair.md)
and the preserved historical
[execution-image observation blocker](../evidence/rma/rma-a-runtime-node-execution-image-observation-live-proof-blocker.md).
The provider recording capability itself was re-verified live and is not
implicated. The bounded
packet now provisions `/var/spool/asterisk/recording`, validates runtime-user
writability, explicitly loads recording modules, and gates WAV registration.
Static and mutation checks pass. The provider-native smoke now uses the
store-preserving ARI `POST /recordings/live/{name}/stop`, captures exact
`RecordingStarted` and `RecordingFinished` events, proves the live resource is
gone after stop `204`, observes StoredRecording and stored-file HTTP `200`,
proves a non-empty WAV artifact, and cleans it up successfully. The prior
stored-artifact blocker was confirmed to be the fixture's use of ARI cancel /
discard (`DELETE /recordings/live/{name}`), not an Asterisk capability defect.
The provider recording-runtime capability packet is now published, deployed,
and verified on its immutable Asterisk revision, but full RMA-A acceptance
remains pending. The earlier WAV capability,
HTTP-422 normalization, and terminal RecordingSession failure-projection repair
remain deployed and live-proven. The fresh post-repair acceptance proved SIP
delivery, `9900` answer, canonical CallLeg `answered`, durable pre-answer intent,
and exactly-one start dispatch. See
the [fixture repair evidence](../evidence/rma/rma-a-native-k3s-asterisk-echo-fixture-materialization-repair.md),
[synchronized SIP trace](../evidence/rma/rma-a-asterisk-canonical-sip-delivery-synchronized-live-trace.md),
[PJSIP transaction capture reproof](../evidence/rma/rma-a-asterisk-pjsip-transaction-capture-reproof.md),
[persistent PJSIP filesystem trace](../evidence/rma/rma-a-asterisk-persistent-pjsip-filesystem-trace-reproof.md),
[live-proof blocker](../evidence/rma/rma-a-recording-session-lifecycle-natural-live-proof-blocker.md),
[implementation evidence](../evidence/rma/rma-a-recording-session-lifecycle-aware-start-reconciliation-implementation.md),
the [exact-cause audit](../evidence/rma/rma-a-asterisk-recording-start-conflict-exact-cause-audit.md),
the [WAV repair evidence](../evidence/rma/rma-a-asterisk-wav-format-ari-422-and-recording-failure-projection-repair.md),
and the [recording-runtime capability evidence](../evidence/rma/rma-a-asterisk-recording-runtime-capability-and-preflight-closure.md).
RMA is the next R0-critical track now that **K5E is COMPLETE /
NATURAL-LIVE-PROVEN**. The
2026-08-31 two-stage controlled live proof deployed `3202451` and closed both
stages. **Stage A** proved the scheduler overlap-mutex repair live: every
minute-cadence overlap event reports `expires=5` in the running application with
**zero** implicit-1440 minute events remaining; the three surviving 24-hour locks
were correctly classified as pre-repair residue and normalized once through the
framework-native `schedule:clear-cache` (disclosed as `PRE-PROOF HISTORICAL
MUTEX CLEANUP`, never counted as recovery evidence); the observer then resumed on
its own and converged the stale projection; and a controlled acquisition of the
**real** event's **real** `CacheEventMutex` produced a Redis `TTL=300`, suppressed
the observer for the full five minutes while every other minute task kept
firing, expired naturally, and the observer ran again on the very next tick —
with no `schedule:clear-cache`, Redis `DEL`, manual invocation, or Pod restart
during the recovery proof. **Stage B** then proved distributed operation across
`utcp-dev02` → `utcp-dev01`: baseline placement correlated automatically; a
natural Web Admin `Prepare for maintenance` on the host actually carrying the
RuntimeNode excluded new work (`HTTP 422`) while work was still active and the
Node still schedulable; the existing Call ended on its own; the RuntimeNode
reached `DRAINED` and only then was the Node cordoned; Kubernetes rescheduled
the workload to the other host with no affinity or forced placement; **UTCP
observed the new host automatically within one observer cycle**; the replacement
became Ready; and telephony eligibility returned and was exercised by a further
natural Call that completed on the new host. Automatic restoration covers
workload recreation, readiness, placement observation and eligibility;
RuntimeNode desired-state reactivation remains a deliberate operator action per
K5D's accepted scope and was performed through the canonical Web Admin
**Reactivate** control. Verdicts
`K5E_PLACEMENT_OBSERVER_AUTOMATIC_MUTEX_RECOVERY_LIVE_PROVEN` and
`K5E_DISTRIBUTED_INFRASTRUCTURE_NATURAL_LIVE_PROVEN`. No production source
changed. See the
[`K5E distributed infrastructure natural live proof`](../evidence/k5/k5e-distributed-infrastructure-natural-live-proof.md).

The synthetic fixture remains deterministic regression only. C7A supports the
bounded V1-A `outbound_registration` signaling mode; C7B closed on 2026-08-24
after deterministic tenant-scoped route evaluation and focused tests passed.

**Current topology authority:** native k3s on `utcp-dev01` at
`192.168.254.124` is the canonical V1 environment. `utcp-local` remains a
supported secondary development/regression topology and is not current V1
acceptance authority. Shared Kubernetes/Kamailio repairs are committed and
native-k3s is the current acceptance authority. The accidental
synthetic V1 fixture with slug `v1-canonical-peer-1787783954` is known cleanup
debt and is deferred to a separate bounded packet. External PBX prerequisites
remain separate.

**Mainline:**

```text
Telephony:   T4 closure -> C7A closure -> C7B -> T6 -> V1 -> A0
Distributed: K5A -> K5B -> K5C -> K5D -> K5E
                         K5E -> RMA
                         A0, K5E, and RMA converge at R0
```

This ledger does not claim that a live proof was performed by this
documentation task. Current T4 implementation evidence:
[`docs/evidence/t4/t4-media-reference-and-freeswitch-playback-implementation.md`](../evidence/t4/t4-media-reference-and-freeswitch-playback-implementation.md).

## Completed phases and tracks

| Track                              | Current status                  | Scope / canonical evidence                                                                                                                                                     |
|------------------------------------|---------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| F0–F4                              | Complete                        | Repository, application, container, Compose, and CI foundation; `docs/evidence/f0/`–`f4/`                                                                                      |
| K0–K4                              | Complete                        | k3d/Kubernetes, gateway, security, and observability foundation; `docs/evidence/k0/`–`k4/`                                                                                     |
| C0–C5                              | Complete                        | Control-plane kernel, identity, RuntimeNode registry, reconciliation, simulator, sessions, and conferences; `docs/evidence/c0/`–`c5/`                                          |
| T1 Kamailio SIP-over-WSS signaling | Complete                        | `UTCP_PHASE=T1` is the CI-guarded marker for the completed original foundation sequence, not the current program phase; RMA is the current active core work                   |
| T0–T3                              | Complete                        | Asterisk, Kamailio, conference, and rtpengine execution slices; `docs/evidence/t0/`–`t3/`                                                                                      |
| V0                                 | Complete                        | Natural login, SIP registration, conference admission, and reference-client acceptance; `docs/evidence/v0/`                                                                    |
| RT-1 / RT-1A                       | Complete                        | Runtime control-plane notifications and browser proof; `docs/evidence/rt-1/`, `docs/evidence/rt1/`                                                                             |
| RNM                                | Complete                        | RuntimeNode lifecycle and adversarial closure; `docs/evidence/rnm/`                                                                                                            |
| RNP                                | Complete                        | Managed runtime provisioning/deprovisioning and operator flow; `docs/evidence/rnp/`                                                                                            |
| T5                                 | Complete                        | Multi-runtime convergence, failover, recovery, and closure; [`docs/evidence/t5/t5-phase-closure.md`](../evidence/t5/t5-phase-closure.md)                                       |
| C6                                 | Complete / live-proven / frozen | Canonical Call/CallLeg lifecycle and normalized call control; `docs/evidence/c6/` and [`ADR-023`](../decisions/ADR-023-canonical-call-lifecycle-and-call-control-authority.md) |

## Active phase

| Phase | Status   | Next bounded proof                                                                                              |
|-------|----------|-----------------------------------------------------------------------------------------------------------------|
| T4    | Complete | Timer-backed `media.playback` natural live proof passed 2026-08-24; see the T4 live-proof record above          |
| T6    | Complete | Kamailio and Asterisk provider-consumption seams implemented/tested with bounded synthetic runtime verification |

## Planned mainline

| Phase | Status | Dependency / boundary |
| --- | --- | --- |
| C7A | Complete | Tenant-scoped ExternalTrunk, endpoint/credential-reference lifecycle, TelephonyAddress, CallerIdentity, policy, and provider-neutral Admin API; see `docs/evidence/c7a/` |
| C7B | Complete | Inbound/outbound routes, derived RouteDecision, and runtime-neutral DestinationRef; see `docs/evidence/c7b/` |
| T6 | Complete | Provider projection and Kamailio/Asterisk synthetic consumption verified; V1 owns natural external SIP acceptance |
| V1 | Complete | Native-k3s is canonical. C7A/C7B, deterministic RuntimeNode selection, ADR-030 termination authority, the Gap B registration/NAT dialog-return corridor, and Gap A timeout precedence are repository-proven. Gap A through Gap F are all closed, including the live-proven provider-wire trust boundary. The `X-UTCP-Caller-Identity-ID` provider-boundary correction is deployed and live-proven, so all four internal correlation headers are stripped before provider relay while normal caller identity is preserved. No V1 acceptance blocker remains. |
| A0 | Eligible | V1 is complete, so A0 may begin in parallel with the K5 track; minimal outbound, inbound, and IVR-style reference consumers |
| R0 | Planned | Finite release boundary after the mainline evidence is complete |

## Parallel and deferred tracks

| Track | Status / release placement |
| --- | --- |
| K5 | Parallel / R0-Critical infrastructure track under [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md); does not serially gate T4 or C7A. K5A–K5E are Complete / natural-live-proven, satisfying the R0 requirement. K5F is Planned, not implemented, and not an R0 gate |
| RMA | In progress / UTCP Core / R0-Critical under [`ADR-029`](../decisions/ADR-029-recording-media-artifact-and-archive-authority.md); RMA-A lifecycle-aware reconciliation is implemented/tested and its pre-answer deferral, natural SIP delivery, natural CallLeg answer, and exactly-one automatic start dispatch are live-proven. The bounded WAV capability, ARI-422 normalization, terminal failure-projection repair, provider recording-runtime capability, and managed-workload live-Pod execution-image observation repair are implemented, repository-validated, immutably published, deployed, and live-proven. The pre-Call execution-image blocker is closed; complete natural-live RecordingSession lifecycle proof remains pending. RMA-B through RMA-H remain not started |
| Operational Reporting & Insights | Future UTCP Core / Post-current-R0 roadmap; not a current phase or R0 gate; no implementation claimed under [`ADR-033`](../decisions/ADR-033-operational-reporting-insights-and-business-reporting-boundary.md) |
| C8 | Planned UTCP core transfer/handoff track under [`ADR-025`](../decisions/ADR-025-unified-call-transfer-and-inter-runtime-handoff.md); advanced consultative and inter-runtime/provider handoff defaults to post-R0/R1 unless V1 proves a basic dependency |
| Queue/ACD | Future extension; no R0 phase |
| Campaigns, CRM, predictive dialing, agent workflow, advanced IVR, billing/settlement, number purchasing/porting, SMS/MMS | Application, provider, or future domains; outside R0 core |

K5 is the distributed telephony infrastructure track. **K5A is complete and
K5B placement awareness is complete / natural-live-proven.** K5A host visibility,
K5B telephony placement awareness, K5C capacity/failure-domain policy, K5D
telephony-aware host maintenance, and K5E a bounded live proof across distinct
Kubernetes host/failure domains. It builds on existing RuntimeNode and RNP
authorities; Kubernetes remains authoritative for Nodes, Pods, scheduling, and
workload placement. Full multi-cluster federation remains future-compatible,
not an R0 requirement.

V1 gap status is explicit: Gap A is **closed** — application order under a row
lock is authoritative, an origination timeout is never reopened by a later runtime
observation, and the late observation is still preserved. Orphaned runtime-channel
reclamation after an
origination timeout is a separate deferred decision, not part of the precedence
rule. **Gap B is closed** — the registration/NAT
return path, managed-runtime BYE receipt, canonical
`completed / remote / remote` termination, and BYE-caused Kamailio dialog
termination without `dlg_ontimeout()` are all live-proven. **Gap E is closed** — the
provider-failure raw fact authority, the provider-channel-to-CallLeg
correlation, and the minimal canonical provider-failure taxonomy are all
live-proven. `tech_cause` carries the provider SIP final response and appears
only on the provider-facing PJSIP channel; that observation resolves to the
canonical CallLeg through `channelvars = UTCP_CALL_LEG_ID` while the provider
uniqueid remains raw evidence and `CallLeg.runtime_channel_id` remains Local
`;1`; and a `tech_cause 404` with `answered_at NULL` converges both CallLeg and
Call to `failed / remote / remote / unreachable / destination_not_found` without
rewriting write-once terminal metadata. Closure rests on that adopted minimum
deterministic contract, not a full provider failure matrix: unmapped provider
outcomes continue to preserve raw evidence and may remain canonical `Failed`
with NULL taxonomy until explicitly adopted. **Gap F is closed** — the
provider-wire trust boundary is **live-proven**. `X-UTCP-Call-Leg-ID`,
`X-UTCP-Route-Decision-ID`, and `X-UTCP-Trunk-Endpoint-ID` are present and exact
on the trusted runtime → Kamailio INVITE and absent from every provider-facing
INVITE, including the authenticated retry, with the provider answering `200 OK`.
All six recorded V1 gaps are therefore closed. The one further item that proof
isolated is now closed as well: the correction that strips
`X-UTCP-Caller-Identity-ID` at the provider relay boundary is deployed and
**live-proven**, so all four internal correlation headers are absent from every
provider-facing INVITE while normal provider-visible caller identity is
preserved. No V1 acceptance blocker remains.
ADR-031 implementation is
complete, while stable-public-edge live acceptance is
`DEFERRED_BY_ENVIRONMENT`, not abandoned, and does not block the registration/
NAT acceptance corridor. Gap C and Gap D are closed; see their linked evidence
and ADRs for detail.

RMA is the Recording & Media Archive track. It follows completed K5E and the
established V1 Call/CallLeg corridor; RMA-A lifecycle-aware start reconciliation
is implemented/tested and deployed, and the forward identity-catalog repair has
been deployed with both declared RMA-A capabilities authorized through the
tenant-admin role. Natural-live evidence now proves durable pre-answer intent
without a start operation, terminal-before-eligibility resolution, the repaired
Asterisk-to-Asterisk SIP delivery, `9900` answer, canonical CallLeg `answered`,
and exactly-one automatic start dispatch. The WAV capability, ARI-422
normalization, and terminal failure-projection repair is deployed and live
proven. The remaining downstream blocker is Asterisk live-recording filesystem
provisioning: post-answer ARI channel-record attempts report `No such file or
directory`, while the active source runtime has `format_wav.so` Running and
`wav` registered but lacks `/var/spool/asterisk/recording`. The terminal retry
after natural call termination returns 404 and now correctly projects the
RecordingSession to `failed`; it is downstream of the ENOENT. The bounded next
repair is declarative creation of the Asterisk recording spool directory in the
repository image, not a change to RecordingSession eligibility, SIP policy,
endpoint, dialplan, channel-vs-bridge recording semantics, or failure taxonomy.
See the [final RMA-A acceptance evidence](../evidence/rma/rma-a-recording-authority-and-lifecycle-final-natural-live-acceptance.md) and
[persistent PJSIP filesystem trace](../evidence/rma/rma-a-asterisk-persistent-pjsip-filesystem-trace-reproof.md).
The earlier Asterisk endpoint-plus-URI,
identity-catalog, and premature pre-answer dispatch defects are repaired. See
the [live-proof blocker](../evidence/rma/rma-a-recording-session-lifecycle-natural-live-proof-blocker.md),
[implementation evidence](../evidence/rma/rma-a-recording-session-lifecycle-aware-start-reconciliation-implementation.md),
and [exact-cause audit](../evidence/rma/rma-a-asterisk-recording-start-conflict-exact-cause-audit.md).
RMA-B through RMA-H remain not started. RMA does not technically gate A0, and
no artifact, archive, retention, playback, or download implementation is
claimed by RMA-A.

## Historical status rule

Evidence documents may retain superseded status labels because those statements
describe a point in time. The current ledger uses only the final state: T3 and V0 are Complete; C6 is Complete /
live-proven / frozen; T6 is complete and V1 is the current mainline phase. Completed phase IDs,
ADR-024, ADR-025, and all evidence files are preserved.
