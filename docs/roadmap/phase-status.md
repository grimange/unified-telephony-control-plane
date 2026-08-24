# Phase Status

This is the definitive current-state ledger. Historical proof and defect
chronology remain in `docs/evidence/` and Git history; they do not compete with
the entries below.

**T1 Kamailio SIP-over-WSS signaling — Complete.** `UTCP_PHASE=T1` remains the
CI-guarded repository phase marker for the original foundation sequence. It is
not a competing current-roadmap status or a claim that T4 is incomplete.

## Current state

**Current phase:** T4 — FreeSWITCH call-control adapter parity.

**Current status:** T4A/T4B are implemented and tested. T4C1/T4C2 are
implemented, tested, live-proven, and frozen. The current bounded
`media.playback` implementation is present and tested; its narrow live proof
is pending. Recording remains separate. T4D does not exist.

**Exactly one next action:** run the canonical narrow T4 `media.playback` live
proof. After T4 closure, begin C7A.

**Mainline:**

```text
T4 closure -> C7A -> C7B -> T6 -> V1 -> A0 -> R0
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
| T4 | In progress | Live proof of implemented FreeSWITCH `media.playback`; see the T4 evidence link above |

## Planned mainline

| Phase | Status | Dependency / boundary |
| --- | --- | --- |
| C7A | Planned | Follows T4; external connectivity, TelephonyAddress, trunk lifecycle, and CallerIdentity authority |
| C7B | Planned | Follows C7A; inbound/outbound routes, RouteDecision, and runtime-neutral DestinationRef |
| T6 | Planned | Follows C7A/C7B; live external trunk and route projection through adapters |
| V1 | Planned | Follows T6; bidirectional external routing and normalized call control acceptance |
| A0 | Planned | Follows V1; minimal outbound, inbound, and IVR-style reference consumers |
| R0 | Planned | Finite release boundary after the mainline evidence is complete |

## Parallel and deferred tracks

| Track | Status / release placement |
| --- | --- |
| K5 | Planned parallel infrastructure track under [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md); not an R0 prerequisite on current evidence |
| C8 | Planned UTCP core transfer/handoff track under [`ADR-025`](../decisions/ADR-025-unified-call-transfer-and-inter-runtime-handoff.md); advanced consultative and inter-runtime/provider handoff defaults to post-R0/R1 unless V1 proves a basic dependency |
| Queue/ACD | Future extension; no R0 phase |
| Campaigns, CRM, predictive dialing, agent workflow, advanced IVR, billing/settlement, number purchasing/porting, SMS/MMS | Application, provider, or future domains; outside R0 core |

## Historical status rule

Evidence documents may retain superseded status labels because those statements
describe a point in time. The current ledger uses only the final state: T3 and V0 are Complete; C6 is Complete /
live-proven / frozen; T4 is the only active mainline phase. Completed phase IDs,
ADR-024, ADR-025, and all evidence files are preserved.
