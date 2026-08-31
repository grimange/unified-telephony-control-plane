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
operational visibility. It also owns reusable technical recording intent and
lifecycle, Recording Artifact metadata, Media Archive Target desired state,
archive credential-reference policy, archive transfer lifecycle, and basic
technical retention/deletion orchestration. Runtime adapters execute
provider-specific behavior
behind those contracts; Kamailio, rtpengine, Asterisk, FreeSWITCH, Kubernetes,
PostgreSQL, Redis, and Traefik retain their own execution or infrastructure
authority.

The normal human management authority is Web Admin through the authenticated
application/domain API. Authorized mutations persist canonical PostgreSQL
desired state and converge automatically through reconciliation and
projection; a routine Artisan command is not required after a successful
mutation. Artisan remains limited to diagnostics, deterministic recovery,
exceptional maintenance, and explicit break-glass operations under
[`ADR-032`](../decisions/ADR-032-canonical-management-authority-and-break-glass-boundary.md).
External systems remain authoritative for facts they own, including
Kubernetes Node/Pod placement and runtime observations.

UTCP is not a campaign dialer, CRM, predictive-dialing engine, contact-center
suite, PBX administration product, visual IVR editor, billing platform, or
carrier inventory system. Applications own campaigns, lead lists, pacing,
agent assignment, dispositions, CRM workflow, business interpretation of
DTMF, IVR menu trees, business hours, and presentation-specific state.

UTCP may eventually provide vendor-neutral operational telephony,
infrastructure, lifecycle, control-plane, and audit reports derived from
canonical state and authoritative external observations. Those reports are
deterministic read models, not canonical authority. Campaigns, leads, agent
and workforce productivity, dispositions, conversion, revenue, and other
business reporting remain application-owned. See
[`ADR-033`](../decisions/ADR-033-operational-reporting-insights-and-business-reporting-boundary.md).

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

### Current topology authority

The canonical current topology is native k3s on `utcp-dev01`, with edge
address `192.168.254.124` and application endpoint
`https://app.utcp.local.test`. V1 natural acceptance runs on native k3s.
k3d/`utcp-local` remains a supported secondary local/regression topology and
must be explicitly selected by a task; historical K0-K4 descriptions do not
reactivate it as the current proof environment. See
[`ADR-028`](../decisions/ADR-028-native-k3s-current-development-and-v1-acceptance-topology.md).

## Current state and executable order

**Current phase:** V1 — Bidirectional external call routing and control; K5C is
implemented and tested, and its natural live proof is blocked by two isolated
live defects.

**Current status:** T4A/T4B are implemented and tested; T4C1/T4C2 are
implemented, tested, live-proven, and frozen. The timer-backed media playback
slice is implemented, tested, and **live-proven** end to end on a naturally
originated managed CallLeg, so **T4 is complete**. Recording remains separate
from T4 and is now represented by the planned RMA track below.
T4D is not a phase. See
[`docs/evidence/t4/t4-timer-backed-media-playback-natural-live-proof.md`](../evidence/t4/t4-timer-backed-media-playback-natural-live-proof.md).

ADR-027 inbound is implemented and regression-proven. The production
`CallObservationProcessor` drives C6 offered adoption and C7B evaluation;
canonical inbound route, ExternalTrunk, and destination binding is persisted;
and Kamailio relays admitted inbound INVITEs through the trusted execution
corridor. The focused ADR-027 regression proof passed, and the canonical API
suite passed with 595 tests, 8 skips, and 5007 assertions. The implementation
is committed at `e334209ccc016053d2f63f8e39e99f2126aa5535`.

**Exactly one next action:** bounded implementation correcting the K5C
placement-observation credential authority and the Web Admin K5C
policy-configuration gap isolated by the 2026-08-31 controlled live proof, after
which controlled natural K5C acceptance can be re-run unchanged. V1 remains
complete and unchanged; the external PBX prerequisites remain separate.
C7B closed on 2026-08-24 after its focused route-authority tests and
provider-neutrality checks passed.

```text
T4 closure
  -> C7A closure
  -> C7B
  -> T6
  -> V1
       |       \
       |        A0
       |          \
       |           R0
       |          /
K5A -> K5B -> K5C -> K5D -> K5E -> RMA
```

The top line is the serial telephony control track and V1 remains current. K5
is a planned parallel, R0-critical distributed-infrastructure track; it does
not serially gate C7A or the established V1 corridor. RMA is a planned UTCP
core, R0-critical track that begins only after the V1 Call/CallLeg corridor is
established and K5E is complete. A0 does not technically depend on RMA; the
preferred execution order is documented separately below. R0 cannot close until
A0, K5E, and RMA satisfy their bounded exit criteria. C8 and other deferred
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

**K5C — Capacity and Failure-Domain Policy:** **implemented and tested; natural
live proof blocked by two isolated live defects** — the placement-observation
projection runs under a ServiceAccount with no Kubernetes credentials and no
`utcp-infrastructure-reader` binding, so it permanently records
`kubernetes_observation_unavailable`; and the Web Admin renders no capacity or
placement fields for UTCP-managed RuntimeNodes, so K5C policy has no natural
management path. See the
[`K5C natural live proof`](../evidence/k5/k5c-capacity-failure-domain-policy-natural-live-proof.md).
Combine Kubernetes facts with
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

**K5F — Guided Existing-Cluster Host Enrollment (Planned / Post-K5E Operator
Experience):** a future guided Admin UI workflow that creates a short-lived host
enrollment intent, issues a one-time bootstrap credential/package the operator
runs locally on the new machine, lets that machine join an already-authorized
cluster through the supported Kubernetes/k3s mechanism, then observes the
resulting Kubernetes Node and correlates the enrollment to its Node UID. This is
**not** cluster provisioning: UTCP never creates or manages clusters, replaces
k3s/kubeadm, invents Nodes, or owns Node existence or readiness. A future
enrollment record is an intent and audit object only — one-time token hash,
expiration, claim state, operator audit metadata, role/topology hints, matched
Node UID, failure status — and never becomes authoritative for readiness,
addresses, capacity, conditions, scheduling, or placement. Readiness is derived
from Kubernetes facts plus UTCP telephony prerequisites and is never
operator-authored; there is no "Mark Ready" authority. K5F follows K5E, does not
reorder K5A–K5E, is **not an R0 gate by default**, and does not block RMA. K5A
remains read-only and gains no Create Host, manual registration, cluster-join, or
host mutation authority. See
[`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md)
2026-08-30 amendment. Not implemented.

**Dependencies / exit criteria:** K5A through K5E progress independently where
practical, building on existing RNP deployment/workload identity and RuntimeNode
authorities. K5A is complete, and K5B placement awareness is complete /
natural-live-proven. R0 requires the bounded K5E
proof in addition to the telephony track. K5F is
sequenced after K5E as an operator-experience enhancement and is not an R0
requirement. The first K5E multi-host proof does not depend on K5F: the second
host may be joined with the normal supported Kubernetes/k3s procedure, and only
once that architecture is proven should K5F automate the operator path.
`K5E -> RMA` is unchanged.

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
workflow, Recording & Media Archive implementation, Queue/ACD, or a new parallel
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
canonical Call/CallLeg -> application/runtime — is implemented: the production
observation path performs C6 offered adoption, invokes C7B, persists canonical
route/trunk/destination binding, and Kamailio relays admitted inbound INVITEs.
Focused repository regression proof and the canonical PHP suite are green. The
External SIP Peer fixture and preparation harness remain available as
deterministic regression; natural external SIP acceptance is the remaining V1
proof.

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

### RMA — Recording & Media Archive

**Status:** Planned / UTCP Core / R0-Critical. RMA is not implemented or
live-proven by this roadmap update.

**Objective:** provide reusable, provider-neutral technical recording and media
artifact/archive lifecycle for multiple telephony applications. The consuming
application owns why recording is requested, business consent meaning, and
workflow; UTCP owns authorized technical intent, tenant policy, lifecycle,
Call/CallLeg/Conference correlation, artifact metadata, archive targets and
credential references, transfer lifecycle, technical retention/deletion,
authorization, and audit. Telephony/media executors retain instantaneous
capture and media-generation authority; object storage owns durable bytes.

**Slices:** RMA-A Recording Authority and Lifecycle; RMA-B Runtime-Neutral
Capture Contract; RMA-C Recording Artifact Authority; RMA-D Archive Target and
Secret-Reference Authority; RMA-E S3-Compatible Archive Adapter and
Deterministic MinIO Proof; RMA-F BYO Storage Credentials and Rotation; RMA-G
Retention and Deletion Lifecycle; RMA-H Distributed Recording and Archive
Natural Live Proof.

These are planned architectural slices only. No RMA schemas, migrations, APIs,
workers, adapters, MinIO deployment, or runtime support are claimed. RMA
depends on the established V1 Call/CallLeg corridor and completed K5E; it does
not technically depend on A0. See [`ADR-029`](../decisions/ADR-029-recording-media-artifact-and-archive-authority.md).

### Operational Reporting & Insights

**Status:** Future UTCP core capability; not currently implemented, not a
current phase, and not an R0 gate. It does not block K5, RMA, or A0 and does
not alter the K5A → K5B → K5C → K5D → K5E order.

**Objective:** provide reusable vendor-neutral operational telephony,
infrastructure/runtime, lifecycle/control-plane, and audit/forensic read models
from canonical UTCP state and authoritative external observations. Future
reports may serve Web Admin Insights and external consuming applications
through a reporting/read API.

**Boundary:** reports are derived and read-only with respect to canonical
Calls, CallLegs, RuntimeNodes, routes, trunks, identities, RMA artifacts,
Kubernetes facts, readiness, placement, policy, and configuration. Business
reporting—campaigns, leads, agent/workforce performance, dispositions,
conversion, sales, revenue, and customer outcomes—belongs to applications.

**Explicit non-goals:** no current reporting tables, projections, APIs,
workers, UI routes/components, exports, report builder, reporting microservice,
or mandatory analytics warehouse. PostgreSQL-backed projections in the existing
modular monolith are the default future direction; external analytics systems
require later evidence. See [`ADR-033`](../decisions/ADR-033-operational-reporting-insights-and-business-reporting-boundary.md).

### Release boundary — R0

**Status:** Planned.

**Objective:** deliver a finite portfolio release proving the core control-plane
contract, not every future telephony application domain.

**Converging tracks:**

```text
Telephony:   T4 closure -> C7A closure -> C7B -> T6 -> V1
Reference:   V1 -> A0
Distributed: K5A -> K5B -> K5C -> K5D -> K5E -> RMA
                         A0, K5E, and RMA converge at R0
```

**R0 exit criteria:** the telephony capability track, minimal A0 reference
consumer proof, bounded K5 distributed-infrastructure track, and bounded RMA
track have their required repository/live evidence; RMA evidence covers
authorized technical recording intent, runtime-neutral capture, artifact and
archive-transfer lifecycle separation, provider-neutral archive-target and
secret-reference boundaries, technical retention/deletion auditability, and a
distributed proof that does not assume one host or local filesystem;
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

**RMA:** planned UTCP Core and R0-critical under ADR-029. RMA begins after the
V1 Call/CallLeg corridor is established and K5E is complete. It is preferred
after K5E and before remaining A0 closure, but A0 has no technical RMA
dependency. RMA does not make business consent or advanced compliance a UTCP
authority.

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

V1 repository implementation is complete under
[`ADR-027`](../decisions/ADR-027-canonical-inbound-external-call-admission-and-execution-target.md),
with focused regression and canonical PHP proof passed and the implementation
committed at `e334209ccc016053d2f63f8e39e99f2126aa5535`. Next, continue on the
canonical native k3s topology (`utcp-dev01`, `192.168.254.124`), close and prove
the uncommitted Kubernetes/Kamailio repairs, re-provision V1 canonical fixture
state through the authoritative API/UI, and run controlled natural
inbound/outbound proof against the independent PBX at `38.146.161.46`. The PBX
prerequisites are not claimed complete here.
