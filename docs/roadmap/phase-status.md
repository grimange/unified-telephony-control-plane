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

**Exactly one next action:** continue V1 preparation on canonical native k3s
(`utcp-dev01`, `192.168.254.124`) through the authenticated API, then run the
narrow natural inbound/outbound proof against the independent PBX at
`38.146.161.46` only after the shared Kubernetes/Kamailio repairs are closed
and proven on native.
The synthetic fixture remains deterministic regression only. The edge
implementation and current runtime limitation are recorded in the
[`V1 Level-2 edge evidence`](../evidence/v1/v1-level2-external-sip-lab-edge-implementation.md);
the earlier fixture preparation evidence remains historical preparation.
C7A now also supports the bounded V1-A `outbound_registration` signaling mode;
see [`V1-A registration implementation evidence`](../evidence/v1/v1a-registration-external-trunk-implementation.md).
C7B closed on 2026-08-24 after deterministic tenant-scoped inbound/outbound
route evaluation, canonical destination references, C7A eligibility enforcement,
and focused implementation tests passed.

**Current topology authority:** native k3s on `utcp-dev01` at
`192.168.254.124` is the canonical V1 environment. `utcp-local` remains a
supported secondary development/regression topology and is not current V1
acceptance authority. Shared Kubernetes/Kamailio defects found during the
temporary k3d recovery are repaired in the working tree and must be closed and
proven against native before natural external SIP acceptance. The accidental
synthetic V1 fixture with slug `v1-canonical-peer-1787783954` is known cleanup
debt and is deferred to a separate bounded packet. External PBX prerequisites
remain separate.

**Mainline:**

```text
Telephony:   T4 closure -> C7A closure -> C7B -> T6 -> V1 -> A0
Distributed: K5A -> K5B -> K5C -> K5D -> K5E
                         both tracks converge at R0
```

This ledger does not claim that a live proof was performed by this
documentation task. Current T4 implementation evidence:
[`docs/evidence/t4/t4-media-reference-and-freeswitch-playback-implementation.md`](../evidence/t4/t4-media-reference-and-freeswitch-playback-implementation.md).

## Completed phases and tracks

| Track | Current status | Scope / canonical evidence |
| --- | --- | --- |
| F0–F4 | Complete | Repository, application, container, Compose, and CI foundation; `docs/evidence/f0/`–`f4/` |
| K0–K4 | Complete | k3d/Kubernetes, gateway, security, and observability foundation; `docs/evidence/k0/`–`k4/` |
| C0–C5 | Complete | Control-plane kernel, identity, RuntimeNode registry, reconciliation, simulator, sessions, and conferences; `docs/evidence/c0/`–`c5/` |
| T1 Kamailio SIP-over-WSS signaling | Complete | CI-guarded foundation marker; current active roadmap phase is T4 |
| T0–T3 | Complete | Asterisk, Kamailio, conference, and rtpengine execution slices; `docs/evidence/t0/`–`t3/` |
| V0 | Complete | Natural login, SIP registration, conference admission, and reference-client acceptance; `docs/evidence/v0/` |
| RT-1 / RT-1A | Complete | Runtime control-plane notifications and browser proof; `docs/evidence/rt-1/`, `docs/evidence/rt1/` |
| RNM | Complete | RuntimeNode lifecycle and adversarial closure; `docs/evidence/rnm/` |
| RNP | Complete | Managed runtime provisioning/deprovisioning and operator flow; `docs/evidence/rnp/` |
| T5 | Complete | Multi-runtime convergence, failover, recovery, and closure; [`docs/evidence/t5/t5-phase-closure.md`](../evidence/t5/t5-phase-closure.md) |
| C6 | Complete / live-proven / frozen | Canonical Call/CallLeg lifecycle and normalized call control; `docs/evidence/c6/` and [`ADR-023`](../decisions/ADR-023-canonical-call-lifecycle-and-call-control-authority.md) |

## Active phase

| Phase | Status | Next bounded proof |
| --- | --- | --- |
| T4 | Complete | Timer-backed `media.playback` natural live proof passed 2026-08-24; see the T4 live-proof record above |
| T6 | Complete | Kamailio and Asterisk provider-consumption seams implemented/tested with bounded synthetic runtime verification |

## Planned mainline

| Phase | Status | Dependency / boundary |
| --- | --- | --- |
| C7A | Complete | Tenant-scoped ExternalTrunk, endpoint/credential-reference lifecycle, TelephonyAddress, CallerIdentity, policy, and provider-neutral Admin API; see `docs/evidence/c7a/` |
| C7B | Complete | Inbound/outbound routes, derived RouteDecision, and runtime-neutral DestinationRef; see `docs/evidence/c7b/` |
| T6 | Complete | Provider projection and Kamailio/Asterisk synthetic consumption verified; V1 owns natural external SIP acceptance |
| V1 | Active | Fixture and bounded V1-A registration implementation are present; deployment readiness is separated from strict inactive V1-B topology verification; least-privilege registration-reader provisioning and live V1-A deployment are complete, with external REGISTER/interoperability and normalized call control acceptance still pending |
| A0 | Planned | Follows V1; minimal outbound, inbound, and IVR-style reference consumers |
| R0 | Planned | Finite release boundary after the mainline evidence is complete |

## Parallel and deferred tracks

| Track | Status / release placement |
| --- | --- |
| K5 | Planned / Parallel / R0-Critical under [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md); does not serially gate T4 or C7A, but K5E is required before R0 |
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

## Historical status rule

Evidence documents may retain superseded status labels because those statements
describe a point in time. The current ledger uses only the final state: T3 and V0 are Complete; C6 is Complete /
live-proven / frozen; T6 is complete and V1 is the current mainline phase. Completed phase IDs,
ADR-024, ADR-025, and all evidence files are preserved.
