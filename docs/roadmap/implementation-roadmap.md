# Implementation Roadmap

This is the executable roadmap for the Unified Telephony Control Plane (UTCP).
It contains current phase truth, bounded future work, dependencies, non-goals,
exit criteria, and links to canonical evidence. It does not replay proof
transcripts or defect chronology.

> **Roadmaps contain current truth. Evidence contains historical truth. Git
> contains document history.**

## Product boundary

UTCP is a vendor-neutral telephony control plane and normalized telephony
authority. It owns canonical desired state, tenant and operator policy,
RuntimeNode lifecycle, runtime readiness and capability contracts, call and
call-leg lifecycle, normalized control operations and observations, trunk and
address authority, routing decisions, reconciliation, idempotency, audit, and
operational visibility. Runtime adapters execute provider-specific behavior
behind those contracts; Kamailio, rtpengine, Asterisk, FreeSWITCH, Kubernetes,
PostgreSQL, Redis, and Traefik retain their own execution or infrastructure
authority.

UTCP is not a campaign dialer, CRM, predictive-dialing engine, contact-center
suite, PBX administration product, visual IVR editor, billing platform, or
carrier inventory system. Applications own campaigns, lead lists, pacing,
agent assignment, dispositions, CRM workflow, business interpretation of
DTMF, IVR menu trees, business hours, and presentation-specific state.

The browser implementation historically called the **Reference Dialer** is
the **Reference Telephony Client** in current product and roadmap prose. It is
a consumer and acceptance vehicle for authentication, SIP registration,
browser signaling/media, conference and call-control integration, and
provider-neutral behavior. This terminology change does not rename routes,
classes, APIs, fixtures, identifiers, ADRs, or historical evidence filenames.

Reference-client resilience is split by authority. Canonical participation
intent, runtime-channel correlation, binding/rebinding, stale-channel fencing,
server-owned recovery grace, RuntimeNode readiness, durable cleanup,
interruption convergence, and reconciliation remain UTCP concerns. Browser
connectivity debounce, online/offline presentation, Vue component lifecycle,
client retry pacing, reconnect presentation, and button state remain
Reference Telephony Client concerns. Completed engineering and evidence are
preserved; only their current product significance is reclassified.

### UTCP Core admission test

A proposed capability belongs in the core roadmap when repository evidence
shows that UTCP needs canonical authority for it, multiple telephony
application types can reuse it, its contract can remain provider-neutral, at
least two materially different consumers could use it, and it represents
infrastructure or telephony lifecycle/control rather than business workflow.
If most of those answers are no, the capability belongs to an application,
reference consumer, provider extension, optional integration, or post-R0
roadmap. This is a scope-control rule, not a substitute for architecture
evidence.

## Document authority

| Document | Authority |
| --- | --- |
| [`docs/unified-telephony-control-plane-initial-implementation-plan.md`](../unified-telephony-control-plane-initial-implementation-plan.md) | Product scope and long-term capability boundary |
| [`docs/unified-telephony-control-plane-application-implementation-plan.md`](../unified-telephony-control-plane-application-implementation-plan.md) | Application implementation detail and phase contracts |
| This document | Executable phase ordering, dependencies, non-goals, exit criteria, and R0 boundary |
| [`phase-status.md`](phase-status.md) | Shortest authoritative current-state ledger and one next action |
| [`docs/roadmap/ui-foundations.md`](ui-foundations.md) | Reusable UI foundation detail; it does not reorder C/T/V phases |
| `docs/evidence/` and `docs/decisions/` | Historical proof, defect/correction history, and durable architecture decisions |

`CLAUDE.md` records the repository phase dependency used for task scoping. It
must agree with the current mainline here and is not a second status ledger.

## Current state and executable order

**Current phase:** T4 — FreeSWITCH call-control adapter parity.

**Current status:** T4A/T4B are implemented and tested; T4C1/T4C2 are
implemented, tested, live-proven, and frozen. The current bounded media
playback slice is implemented/tested and its live proof remains pending.
Recording remains separate. T4D is not a phase. See
[`docs/evidence/t4/t4-media-reference-and-freeswitch-playback-implementation.md`](../evidence/t4/t4-media-reference-and-freeswitch-playback-implementation.md).

**Exactly one next action:** run the canonical narrow live proof for the
implemented T4 media.playback slice. After that proof closes T4, begin C7A.

```text
T4 closure
  -> C7A
  -> C7B
  -> T6
  -> V1
  -> A0
  -> R0
```

C8 and K5 are valid control-plane/infrastructure tracks but do not enter this
critical path unless a later evidence-backed dependency proves they are needed
for the R0 baseline.

## Capability tracks

### Platform Foundation — F0–F4, K0–K4

**Status:** Complete. These phases establish repository governance, the
application/container baseline, Compose, CI, k3d/Kubernetes, ingress, security,
and observability. Their detailed proof remains under `docs/evidence/f0/`
through `docs/evidence/k4/`; the executable roadmap does not duplicate their
run transcripts.

**Authority / responsibility:** UTCP platform packaging and operational
foundations; Kubernetes owns workload placement and declared infrastructure.

**Explicit non-goals:** telephony business authority, provider-specific call
behavior, campaign workflows, or a second deployment topology.

**Dependencies / exit criteria:** repository contract and canonical local
lifecycle are the prerequisites; exit is recorded by the phase evidence and
current ledger.

### Runtime Control Plane — C0–C5, RT-1, RNM, RNP, T5

**Status:** Complete for the established slices. This track includes the
modular control-plane kernel, identity and tenancy, RuntimeNode registry and
lifecycle, command/event/projection/reconciliation, simulator, telephony
session/conference authority, realtime operational notifications, managed
runtime provisioning, convergence, failover, and recovery.

**Authority / responsibility:** UTCP/PostgreSQL owns desired state, lifecycle
decisions, operations, observations, reconciliation, audit, and readiness;
Redis remains transient; Kubernetes and runtime vendors execute their own
responsibilities.

**Explicit non-goals:** replacing Kubernetes, PBX/SIP/media authority, or
making the reference client a management authority.

**Evidence / exit:** [`docs/evidence/t5/t5-phase-closure.md`](../evidence/t5/t5-phase-closure.md),
[`docs/evidence/rnm/rnm-a-runtime-node-management-adversarial-audit.md`](../evidence/rnm/rnm-a-runtime-node-management-adversarial-audit.md),
[`docs/evidence/rnp/rnp-6-natural-managed-runtime-lifecycle-live-proof.md`](../evidence/rnp/rnp-6-natural-managed-runtime-lifecycle-live-proof.md),
and the phase-specific F/K/C/RN/RT evidence establish the completed slices.

**K5 side track:** Host visibility and telephony placement awareness remains
planned under [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md).
It is read-only infrastructure direction—Node discovery, Host inventory,
readiness/capacity, placement visibility, and RuntimeNode association—and is
not a T4, C7, T6, V1, or R0 prerequisite on current evidence.

### Telephony Core — C6, C7A, C7B

#### C6 — Canonical Call Lifecycle and Normalized Call Control

**Status:** Complete / live-proven / frozen in the current ledger. C6 owns
canonical `Call` and `CallLeg` lifecycle, normalized operations and
observations, inbound adoption, call-control capability checks, DTMF
observations, media-control primitives, fencing, idempotency, audit, and the
runtime-neutral seams consumed by C7. Existing conference authority remains
separate where the established architecture requires it.

**Explicit non-goals:** provider-specific routing, campaign logic, IVR
workflow, recording storage/retention, Queue/ACD, or a new parallel
operation/observation authority. See [`ADR-023`](../decisions/ADR-023-canonical-call-lifecycle-and-call-control-authority.md)
and the C6 evidence index.

#### C7A — External Connectivity, Telephony Addressing, and Caller Identity

**Status:** Planned; first post-T4 implementation phase.

**Objective:** establish canonical, tenant-scoped `ExternalTrunk`, endpoint and
credential-reference lifecycle, `TelephonyAddress`, `CallerIdentity`, and
caller-identity policy with readiness, eligibility, audit, and secret-reference
boundaries.

**Authority / responsibility:** UTCP/PostgreSQL owns trunk/address/identity
desired state and policy; adapters and signaling/runtime systems only project
and execute it.

**Explicit non-goals:** carrier provisioning, number purchasing/porting,
billing/settlement, provider-specific identifiers, or route selection beyond
the identity/connectivity facts needed by C7A.

**Dependencies / exit criteria:** T4 closure and existing C6 contracts;
tenant isolation, lifecycle/readiness policy, normalized APIs and tests,
idempotent writes, audit history, and provider-neutral contract evidence.

**Evidence links:** product and phase contracts in the initial implementation
plans; implementation evidence will be added when C7A begins.

#### C7B — Inbound/Outbound Route and Destination Authority

**Status:** Planned; follows C7A.

**Objective:** establish `InboundRoute`, `OutboundRoute`, constraints,
`RouteDecision`, and runtime-neutral `DestinationRef` authority.

**Authority / responsibility:** UTCP resolves address -> route -> destination
and outbound route -> caller identity -> trunk -> runtime; adapters project the
decision and never become its source.

**Explicit non-goals:** campaigns, lead/pacing policy, agent assignment,
dispositions, IVR menu trees, Asterisk dialplans, FreeSWITCH profiles, or
Kamailio dispatcher IDs in canonical domain state.

**Dependencies / exit criteria:** C7A and C6; deterministic tenant-scoped
route decisions, normalized destination classes, authorization, idempotency,
audit, negative/conflict tests, and evidence for both inbound and outbound
resolution.

**Evidence links:** C6 contract evidence and the initial implementation plans;
no C7B implementation evidence is claimed yet.

### Runtime / Execution Adapters — T0–T6

T0 Asterisk, T1 Kamailio SIP-over-WSS, T2 conference execution, T3 rtpengine
media, and T4 FreeSWITCH parity are adapter/execution tracks. They preserve
the control-plane contracts above. T6 is the future live projection phase:

#### T6 — External Trunk Integration and Live Route Projection

**Status:** Planned; follows C7A and C7B.

**Objective:** project canonical trunks, addresses, routes, caller identity,
and destination decisions through Kamailio and selected runtime adapters using
synthetic external peers, with lifecycle, credential-reference rotation,
draining, disablement, and retirement evidence.

**Explicit non-goals:** commercial carrier dependency, direct application-to-
PBX configuration, a second route authority, or silent provider/runtime
fallback.

**Exit criteria:** canonical projection is deterministic and idempotent;
inbound and outbound synthetic SIP paths are observable; failures are explicit;
runtime and signaling adapters remain provider-neutral; audit and cleanup are
proven.

**Evidence links:** existing adapter and runtime evidence under
`docs/evidence/t0/`–`docs/evidence/t5/`; T6 implementation evidence is not yet
claimed.

### Acceptance Slices — V0, V1

Acceptance slices prove multiple control-plane layers together; they are not
standalone telephony products.

**V0 — Natural login, SIP registration, and conference admission:** complete
and retained as the existing browser acceptance slice. Its browser/client
history is evidence, not product scope. See `docs/evidence/v0/`.

**V1 — Bidirectional external call routing and control:** planned after T6.
It proves outbound application -> route -> caller identity -> external trunk ->
runtime and inbound external peer -> address -> route -> destination ->
canonical Call/CallLeg -> application/runtime, including normalized control,
observations, audit, and failure behavior.

**Evidence links:** none yet; the acceptance contract is defined by C6, C7,
and the initial implementation plans.

### Reference Consumers — A0

**Status:** Planned; follows V1.

**Objective:** prove that applications can consume UTCP through normal APIs.
Keep A0 intentionally small: a minimal outbound consumer, a minimal inbound
consumer, and a minimal IVR-style consumer that exercises answer/hangup, media
playback, DTMF observation, and application handoff.

**Explicit non-goals:** campaign dialer, PBX administration, visual IVR
editor, contact-center suite, queue/agent logic, CRM workflow, or a large new
client. Reuse the Reference Telephony Client where practical. The application
owns business meaning; UTCP owns reusable telephony control and lifecycle.

### Release boundary — R0

**Status:** Planned.

**Objective:** deliver a finite portfolio release proving the core control-plane
contract, not every future telephony application domain.

**Critical path:** `T4 closure -> C7A -> C7B -> T6 -> V1 -> A0 -> R0`.

**R0 exit criteria:** the mainline phases have their repository and required
live evidence; canonical trunk/address/route/caller-identity authority is
tenant-safe and idempotent; bidirectional synthetic external routing and
normalized call control are proven; at least the three minimal consumers can
consume UTCP; documentation and evidence links are current; no application or
provider-specific authority has displaced UTCP.

**Evidence links:** release evidence is collected from the completed mainline
phases; no R0 completion evidence is claimed yet.

## Parallel and post-R0 work

**C8 — Unified Call Transfer and Inter-Runtime Handoff:** legitimate UTCP core
control-plane functionality, preserved under [`ADR-025`](../decisions/ADR-025-unified-call-transfer-and-inter-runtime-handoff.md).
Basic transfer may be pulled before R0 only if V1 acceptance or implementation
evidence requires it. Advanced consultative transfer, same-provider
inter-runtime handoff, inter-provider handoff, and rollback/recovery
orchestration are post-R0/R1 by default. C8 does not duplicate C6 operations or
make the Reference Telephony Client authoritative.

**K5:** parallel infrastructure direction under ADR-024; post-R0 unless a
demonstrated T4/C7/T6/V1 dependency changes the release boundary.

**Queue/ACD, campaign behavior, advanced IVR workflow, billing/settlement,
number purchasing/porting, SMS/MMS, and commercial carrier operations:** future
extensions or application/provider domains, outside R0 core. C7B may reserve
a future queue destination class without implementing Queue/ACD.

## Historical reconciliation rules

Completed identifiers are preserved: F/K/C/T/V/R, RT, RNM, and RNP are project
provenance and are not renumbered. Historical evidence may accurately say that
a phase was once `In Progress` or `Planned`; current roadmap and phase-status
entries must use the final current state. Detailed defect narratives, packet
chronology, pod IDs, resource versions, repeated SHAs, and implementation
diaries remain in evidence and Git history rather than in this executable
roadmap.

## Next

Run the canonical narrow live proof for the implemented T4 media.playback
slice. Once it passes, update `phase-status.md` to close T4 and start the
bounded C7A implementation. No runtime change is part of this roadmap
reconciliation.
