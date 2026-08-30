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

**K5C — Capacity and Failure-Domain Policy:** implemented and tested; natural
live proof is pending. **K5B is complete.** The 2026-08-30 natural live proof deployed the exact
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
**Exactly one next action:** deploy the exact K5C commit to canonical native-k3s
and run controlled natural K5C acceptance. **V1 is complete.** The 2026-08-30
controlled live re-proof deployed the committed
`X-UTCP-Caller-Identity-ID` provider-boundary correction through the canonical
native-k3s lifecycle — the live Kamailio provider path now executes all four
`remove_hf()` operations before `t_relay()` — and one natural canonical Call to
`97001` proved the complete boundary: all four `X-UTCP-*` headers present and
exact on the trusted runtime → Kamailio INVITE, and **absent from every
provider-facing INVITE**, including the authenticated retry after `401`. Each of
the four appears exactly once in the whole capture. Normal provider-visible
caller identity (`From: "utcp-v1" <sip:utcp-v1@…>`) is preserved and unchanged by
the fix, the provider authenticated and answered `200 OK`, and the Call closed
`completed / requested / control_plane`. With Gap A–F closed and this last item
proven, no V1 acceptance blocker remains. See the
[`CallerIdentity provider-wire live proof`](../evidence/v1/v1-caller-identity-provider-wire-trust-boundary-live-proof.md)
and the
[`Gap F provider-wire live proof`](../evidence/v1/v1-gap-f-provider-wire-trust-boundary-live-proof.md).
Gap A is **closed**: the first
canonical terminal fact applied under a row lock wins, so an origination timeout
is never reopened or rewritten by a later runtime observation regardless of its
`observed_at`, while that observation is still preserved in
`runtime_observations`. Timeout-first and observation-first interleavings,
duplicate convergence, aggregate preservation, RuntimeOperation preservation,
and terminal channel-binding protection are covered by deterministic API
regressions; ADR-030 §14 now states the rule. See the
[`Gap A precedence audit`](../evidence/v1/v1-gap-a-delayed-observation-origination-timeout-precedence-audit.md).
**Gap B is closed.** The 2026-08-30 controlled live re-proof deployed the exact committed
`dlg_manage()` correction on native k3s through the canonical lifecycle and
proved the remaining criterion: a no-Route provider BYE drove the tracked
dialog from `state 3` to `state 5` (`DLG_STATE_DELETED`) at the BYE, the dialog
left the live dialog store 5.0 s later, and no `dlg_ontimeout` occurred over a
328 s observation window — against 81.5 s to timeout reap on 2026-08-29. The
previously proven corridor was unchanged: trusted known-dialog match,
`dlg_set_ruri()` retarget to the managed runtime Contact, `MEDIA_DELETE`,
relay, managed Asterisk receipt, and canonical `completed / remote / remote`
with `answered_at` preserved. See the
[`registration-dialog BYE termination live re-proof`](../evidence/v1/v1-registration-dialog-bye-termination-live-reproof.md),
[`dialog-termination correction`](../evidence/v1/v1-registration-dialog-bye-termination-correction.md),
[`registration-dialog return live proof`](../evidence/v1/v1-registration-dialog-return-path-live-proof.md),
[`topology-coherent anchoring evidence`](../evidence/v1/v1-provider-dialog-topology-coherent-anchoring.md)
and [`registration-dialog return evidence`](../evidence/v1/v1-registration-dialog-return-path-implementation.md).

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
| T1 Kamailio SIP-over-WSS signaling | Complete                        | CI-guarded foundation marker; current active roadmap phase is T4                                                                                                               |
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
| K5 | Planned / Parallel / R0-Critical under [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md); does not serially gate T4 or C7A, but K5E is required before R0 |
| RMA | Planned / UTCP Core / R0-Critical under [`ADR-029`](../decisions/ADR-029-recording-media-artifact-and-archive-authority.md); begins after the V1 Call/CallLeg corridor and K5E; no implementation or live proof claimed |
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

RMA is the planned Recording & Media Archive track. It follows completed K5E
and the established V1 Call/CallLeg corridor; it is not the current phase and
does not technically gate A0. K5 and RMA remain deferred according to the
current V1/R0 sequencing.

## Historical status rule

Evidence documents may retain superseded status labels because those statements
describe a point in time. The current ledger uses only the final state: T3 and V0 are Complete; C6 is Complete /
live-proven / frozen; T6 is complete and V1 is the current mainline phase. Completed phase IDs,
ADR-024, ADR-025, and all evidence files are preserved.
