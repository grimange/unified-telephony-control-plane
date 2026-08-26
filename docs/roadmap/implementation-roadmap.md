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

UTCP is Kubernetes-first because its long-term product purpose includes
operating distributed telephony infrastructure across machines, host failure
domains, sites, and eventually hybrid/cloud environments. Kubernetes supplies
the workload and infrastructure orchestration substrate; UTCP adds
telephony-aware interpretation, eligibility, lifecycle, placement intent, and
reconciliation above that substrate. UTCP is not Kubernetes and does not
replace Kubernetes scheduling.

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

**Current phase:** V1 — Bidirectional external call routing and control.

**Current status:** T4A/T4B are implemented and tested; T4C1/T4C2 are
implemented, tested, live-proven, and frozen. The timer-backed media playback
slice is implemented, tested, and **live-proven** end to end on a naturally
originated managed CallLeg, so **T4 is complete**. Recording remains separate.
T4D is not a phase. See
[`docs/evidence/t4/t4-timer-backed-media-playback-natural-live-proof.md`](../evidence/t4/t4-timer-backed-media-playback-natural-live-proof.md).

**Exactly one next action:** implement the V1 inbound corridor as decided by
[`ADR-027`](../decisions/ADR-027-canonical-inbound-external-call-admission-and-execution-target.md).
The V1 outbound corridor is implemented: `CallDomainService::createOutboundCall()`
evaluates C7B, binds the RouteDecision to the Call/CallLeg, and Kamailio relays
through `route[RUNTIME_EXTERNAL_TRUNK]`. The inbound corridor is not implemented:
`C7bService::evaluateInbound()` has no production caller, `adoptInboundLeg()`
binds no route or trunk, and Kamailio's inbound route replies
`200 External Trunk Route Matched` without relaying. ADR-027 resolves the last
open authority question — the canonical inbound execution target — so this is a
bounded implementation, not further evidence work. Live external SIP acceptance
remains V1 scope and follows the implementation.
C7B closed on 2026-08-24 after its focused route-authority tests and
provider-neutrality checks passed.

```text
T4 closure
  -> C7A closure
  -> C7B
  -> T6
  -> V1
  -> A0
           \
            \
             R0
            /
           /
K5A -> K5B -> K5C -> K5D -> K5E
```

The top line is the serial telephony control track. K5 is a planned parallel,
R0-critical distributed-infrastructure track; it does not serially gate C7A.
C7A may begin after T4 closes even while K5 is incomplete, but R0 cannot close
until both tracks satisfy their bounded exit criteria. C8 and other deferred
domains remain outside this convergence unless later evidence changes the
release boundary.

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

### K5 — Distributed Telephony Infrastructure, Placement & Host Lifecycle

**Status:** Planned / Parallel / R0-Critical. K5 is a UTCP core capability
track, not generic deferred or post-R0 work. It does not serially gate T4 or
C7A.

**Objective:** build the bounded distributed-infrastructure corridor over which
UTCP can correlate Kubernetes host facts with RuntimeNode lifecycle, telephony
eligibility, placement/failure-domain policy, maintenance draining, and
restoration without replacing Kubernetes authority.

**Authority / responsibility:** Kubernetes remains authoritative for Nodes,
Pods, Deployments, Services, node conditions, scheduling mechanics, resource
requests/limits, restart behavior, and workload placement facts. UTCP owns
RuntimeNode identity and lifecycle, telephony readiness and load/bindings,
telephony eligibility and selection, placement intent, failure-domain
interpretation, maintenance coordination, reconciliation, audit, and
provider-neutral telephony consequences. No new durable Host, Site, Cluster,
or DeploymentTarget authority is implied by this documentation update.

**K5A — Host / Kubernetes Node Visibility:** read-only discovery and
correlation of Kubernetes Nodes, readiness, basic capacity, labels/topology,
workload placement, and the RuntimeNode-to-workload-to-host relationship.

**K5B — Telephony Placement Awareness:** interpret the existing deployment and
workload relationship in telephony terms, including RuntimeNode location and
failure-domain association, without copying Kubernetes into a competing
database authority.

**K5C — Capacity and Failure-Domain Policy:** combine Kubernetes facts with
RuntimeNode readiness, declared capability, telephony load, capacity, and
failure-domain constraints to determine telephony eligibility and selection.
UTCP must not reimplement the Kubernetes scheduler.

**K5D — Telephony-Aware Host Maintenance:** identify affected RuntimeNodes,
exclude them from new telephony work, move them through the existing
`ACTIVE -> DRAINING -> DRAINED` lifecycle as canonical work converges, and
coordinate Kubernetes-owned maintenance through normal authorized,
reconciled boundaries. This is planned, not implemented here.

**K5E — Distributed Infrastructure Live Proof:** prove that UTCP can operate
telephony RuntimeNodes across at least two distinct Kubernetes host or failure
domains and correlate placement, runtime readiness, telephony eligibility,
new-work exclusion during failure or maintenance, drain behavior, and automatic
restoration. Full multi-cluster federation is not an R0 requirement; K5 must
remain compatible with a future direction of multi-machine, multi-host,
multi-site/hybrid, and potential multi-cluster operation.

**Dependencies / exit criteria:** K5A through K5E progress independently where
practical, building on existing RNP deployment/workload identity and RuntimeNode
authorities. R0 requires the bounded K5E proof in addition to the telephony
track; no K5 implementation or proof is claimed by this roadmap update.

**Evidence links:** [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md)
and existing RNP/RNM/runtime evidence; K5 implementation evidence is not yet
claimed.

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

**Status:** Complete; first post-T4 implementation phase.

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

**Evidence links:** [`C7A implementation evidence`](../evidence/c7a/c7a-external-connectivity-address-caller-identity-implementation.md).

#### C7B — Inbound/Outbound Route and Destination Authority

**Status:** Complete; follows C7A and precedes T6.

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

**Evidence links:** [`C7B implementation evidence`](../evidence/c7b/c7b-route-and-destination-authority-implementation.md).

### Runtime / Execution Adapters — T0–T6

T0 Asterisk, T1 Kamailio SIP-over-WSS, T2 conference execution, T3 rtpengine
media, and T4 FreeSWITCH parity are adapter/execution tracks. They preserve
the control-plane contracts above. T6 is the future live projection phase:

#### T6 — External Trunk Integration and Live Route Projection

**Status:** Complete; Kamailio and Asterisk provider-consumption seams are
implemented and synthetically verified.

**Objective:** project canonical trunks, addresses, routes, caller identity,
and destination decisions through Kamailio and selected runtime adapters using
synthetic external peers, with lifecycle, credential-reference rotation,
draining, disablement, and retirement evidence.

**Explicit non-goals:** commercial carrier dependency, direct application-to-
PBX configuration, a second route authority, or silent provider/runtime
fallback.

**Exit criteria:** canonical projection is deterministic and idempotent;
provider-consumption seams are synthetically observable; failures are explicit;
runtime and signaling adapters remain provider-neutral; audit and cleanup are
proven. Natural external SIP acceptance is V1 scope.

**Evidence links:** existing adapter and runtime evidence under
`docs/evidence/t0/`–`docs/evidence/t5/`; see
[`T6 implementation evidence`](../evidence/t6/t6-external-trunk-and-live-route-projection-implementation.md).

### Acceptance Slices — V0, V1

Acceptance slices prove multiple control-plane layers together; they are not
standalone telephony products.

**V0 — Natural login, SIP registration, and conference admission:** complete
and retained as the existing browser acceptance slice. Its browser/client
history is evidence, not product scope. See `docs/evidence/v0/`.

**V1 — Bidirectional external call routing and control:** active after T6.
The outbound corridor — application -> route -> caller identity -> external
trunk -> runtime — is implemented and carries canonical route binding. The
inbound corridor — external peer -> address -> route -> destination ->
canonical Call/CallLeg -> application/runtime — requires **implementation**,
not only acceptance: canonical inbound route evaluation has no production
caller, inbound adoption binds no route or trunk, and the Kamailio inbound
route is a verification stub that does not relay. The External SIP Peer fixture
and preparation harness remain available as deterministic regression.

[`ADR-027`](../decisions/ADR-027-canonical-inbound-external-call-admission-and-execution-target.md)
decides the canonical inbound execution target: a RuntimeNode SIP endpoint
derived from canonical RuntimeNode eligibility and deterministic ordering, not
the static `selected-application-runtime` selector. It also settles the ingress
token, the Asterisk and FreeSWITCH product inbound contracts, the Kamailio
trust boundary, and the runtime eligibility rule for new inbound work.

**Evidence links:** [`ADR-027`](../decisions/ADR-027-canonical-inbound-external-call-admission-and-execution-target.md)
for the inbound admission and execution-target contract;
[`ADR-023`](../decisions/ADR-023-canonical-call-lifecycle-and-call-control-authority.md)
for Call/CallLeg authority;
[`V1 External SIP Peer fixture preparation`](../evidence/v1/v1-external-sip-peer-fixture-preparation.md)
for the regression fixture. Normalized control, observations, audit, and
failure behavior remain defined by C6, C7, and the initial implementation
plans.

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

**Converging tracks:**

```text
Telephony:   T4 closure -> C7A closure -> C7B -> T6 -> V1 -> A0
Distributed: K5A -> K5B -> K5C -> K5D -> K5E
                         both tracks converge at R0
```

**R0 exit criteria:** both the telephony capability track and bounded K5
distributed-infrastructure track have their required repository/live evidence;
canonical trunk/address/route/caller-identity authority is tenant-safe and
idempotent; bidirectional synthetic external routing and normalized call
control are proven; at least the three minimal consumers can consume UTCP;
K5E proves operation across distinct host/failure domains with readiness,
eligibility, exclusion, drain, and restoration behavior; documentation and
evidence links are current; no application, provider, or Kubernetes scheduler
authority has displaced UTCP's bounded responsibilities.

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

**K5:** planned parallel and R0-critical under ADR-024. K5 does not block the
serial T4/C7/T6/V1 telephony sequence, but K5E must be proven before R0 closes.

**Future Media Processing architecture:** UTCP preserves a future
provider-neutral Media Processing Plane boundary for DSP, speech processing,
transcription, synthesis, and interactive media participants. No implementation
phase is scheduled before current R0; this is architecture-only and is not an
R0 gate.

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

Implement the V1 inbound corridor under
[`ADR-027`](../decisions/ADR-027-canonical-inbound-external-call-admission-and-execution-target.md):
Kamailio trusted ingress and execution-target resolution, Asterisk and
FreeSWITCH product inbound contracts, normalized ingress correlation, C6
adoption, and C7B RouteDecision attachment. C7A and C7B authority is complete
and both T6 provider-consumption seams are verified; live external connectivity
remains V1 scope and follows the implementation.
