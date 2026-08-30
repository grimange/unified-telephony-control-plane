# Phase Status

This is the definitive current-state ledger. Historical proof and defect
chronology remain in `docs/evidence/` and Git history; they do not compete with
the entries below.

**T1 Kamailio SIP-over-WSS signaling — Complete.** `UTCP_PHASE=T1` remains the
CI-guarded repository phase marker for the original foundation sequence. It is
not a competing current-roadmap status or a claim that T4 is incomplete.

## Current state

**Current phase:** V1 — Bidirectional external call routing and control.

**Current status:** T4A/T4B are implemented and tested. T4C1/T4C2 are
implemented, tested, live-proven, and frozen. The timer-backed `media.playback`
slice is implemented, tested, and **live-proven**: a naturally originated managed
CallLeg inherits `timer_name=soft`, `call.leg.play_media` produced a full 5.000 s
timer-paced playback, `call.leg.stop_media` truncated an active playback at
2.86 s, and both `call.leg.media_started` and `call.leg.media_stopped` entered
canonical observation authority from FreeSWITCH runtime events. **T4 is
complete.** Recording remains separate. T4D does not exist. See the
[`T4 timer-backed media.playback live proof`](../evidence/t4/t4-timer-backed-media-playback-natural-live-proof.md).

**Exactly one next action:** deploy the exact committed provider-channel correlation repair through the canonical native-k3s lifecycle and repeat the deterministic SIP 404 proof once, proving the provider-facing PJSIP observation now resolves to the canonical CallLeg while preserving provider raw facts. The 2026-08-30 controlled proof
**succeeded**: one canonical outbound Call to the operator-confirmed absent
extension `97002` produced the pre-established `SIP 404 Not Found`, and the
repaired sanitizer delivered the provider-facing PJSIP channel's raw facts for
the first time — `cause 1`, `cause_txt "Unallocated (unassigned) number"`, and
**`tech_cause 404`** — persisted through to `runtime_observations`. The Q.850
`cause` propagated to all three channels but `tech_cause` appeared **only** on
the provider-facing leg, confirming ADR-030 §11 concretely: the Local control
channel is not the provider failure-detail authority. Correlation is
`EXISTING_CORRELATION_INSUFFICIENT`: the provider observation's `subject_id`
stays `runtime:<uniqueid>`, and `linkedid` does not exist in the ARI `Channel`
model at all, so no sanitizer change can recover one. The exact missing binding
is the already-inherited `__UTCP_CALL_LEG_ID`, which reaches the provider channel
but is exposed by ARI only through the unpopulated, sanitizer-dropped
`channelvars` object. No dialplan and no Kamailio path is required. See the
[`Gap E fact-binding live proof`](../evidence/v1/v1-gap-e-provider-failure-fact-binding-live-proof.md),
[`Gap E fact-preservation implementation`](../evidence/v1/v1-gap-e-asterisk-ari-termination-fact-preservation.md)
and [`Gap E failure taxonomy audit`](../evidence/v1/v1-gap-e-sip-q850-failure-taxonomy-audit.md).
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
| V1 | Active | Native-k3s is canonical. C7A/C7B, deterministic RuntimeNode selection, ADR-030 termination authority, the Gap B registration/NAT dialog-return corridor, and Gap A timeout precedence are repository-proven; Gap B and Gap A are closed. Gap E remains open: provider-failure authority is live-proven, and the provider-channel-to-CallLeg correlation repair is implemented and tested; controlled natural re-proof precedes the canonical failure taxonomy. Gap F is a proof gap only. |
| A0 | Planned | Follows V1; minimal outbound, inbound, and IVR-style reference consumers |
| R0 | Planned | Finite release boundary after the mainline evidence is complete |

## Parallel and deferred tracks

| Track | Status / release placement |
| --- | --- |
| K5 | Planned / Parallel / R0-Critical under [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md); does not serially gate T4 or C7A, but K5E is required before R0 |
| RMA | Planned / UTCP Core / R0-Critical under [`ADR-029`](../decisions/ADR-029-recording-media-artifact-and-archive-authority.md); begins after the V1 Call/CallLeg corridor and K5E; no implementation or live proof claimed |
| C8 | Planned UTCP core transfer/handoff track under [`ADR-025`](../decisions/ADR-025-unified-call-transfer-and-inter-runtime-handoff.md); advanced consultative and inter-runtime/provider handoff defaults to post-R0/R1 unless V1 proves a basic dependency |
| Queue/ACD | Future extension; no R0 phase |
| Campaigns, CRM, predictive dialing, agent workflow, advanced IVR, billing/settlement, number purchasing/porting, SMS/MMS | Application, provider, or future domains; outside R0 core |

K5 is the distributed telephony infrastructure track: K5A host visibility,
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
termination without `dlg_ontimeout()` are all live-proven. Gap E remains open:
the provider-failure raw fact authority is **live-proven** — `tech_cause` carries
the provider SIP final response and appears only on the provider-facing PJSIP
channel, persisted through to `runtime_observations`. The exact
provider-channel-to-CallLeg correlation repair is implemented and tested:
generated ARI `channelvars` exposes only the already-inherited
`UTCP_CALL_LEG_ID`, and validated identity crosses the sanitizer/listener path
with tenant/runtime-node fences before explicit normalizer correlation. Gap E
remains sequenced as the controlled natural re-proof, then the canonical failure
taxonomy. Gap F
remains a `PROOF_GAP_ONLY` provider-wire trust-boundary proof gap and is **not** a
Gap E prerequisite — the provider outcome is read inside the runtime, never from
the wire.
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
