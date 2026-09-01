# Workload-Aware Capacity & Placement Roadmap (WAC)

This document is the authoritative roadmap source for the `WAC` track: its
purpose, authority boundaries, stage scope, dependency order, release
grouping, and future proof strategy.

**Status: FUTURE / POST-CORE / NOT STARTED / NOT R0-GATING.**

No WAC stage is implemented, in progress, or scheduled. No WAC proof exists.
This document describes a deterministic future evolution path so that UTCP can
eventually move beyond simple active-work counts without encoding vendor
folklore. It does not change current behavior, and it does not make UTCP
incomplete for lacking it.

The architecture decision that permits this direction already exists: see the
[`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md)
2026-09-01 runtime family, capacity meaning, and scaling direction amendment,
and the [`ADR-015`](../decisions/ADR-015-runtime-node-registry-authority.md)
2026-09-01 runtime family interpretation clarification. This roadmap
implements neither; it sequences the future work those decisions allow.

## Documentation Hierarchy

| Document | Authority |
| --- | --- |
| `docs/roadmap/workload-aware-capacity-placement.md` | Authoritative WAC stage scope, dependency order, and release grouping. |
| [`ADR-024`](../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md) | Accepted capacity semantics, K5C contract, and the future workload-aware seam. |
| [`ADR-015`](../decisions/ADR-015-runtime-node-registry-authority.md) | RuntimeNode registry authority and runtime-family interpretation. |
| [`docs/roadmap/implementation-roadmap.md`](implementation-roadmap.md) | Roadmap structure, dependencies, and release boundaries; carries the concise WAC entry. |
| [`docs/roadmap/phase-status.md`](phase-status.md) | Sole canonical current-state ledger and current next action. WAC is absent from it because WAC has no current state. |
| [`docs/architecture/authority-boundaries.md`](../architecture/authority-boundaries.md) | Durable authority separation that WAC must not violate. |

Detailed WAC stage criteria belong in this document. The implementation
roadmap summarizes the track without duplicating these stages. The phase-status
ledger records current work only and must not be expanded to track WAC.

## Core and future boundary

```text
K5C / K5D / K5E
    existing core distributed-telephony capabilities
    implemented and natural-live-proven

WAC
    future capacity intelligence
    not implemented, not started

R0 closure does not depend on WAC.
```

WAC must not become an R0 or R1 gate unless a later explicit roadmap or
architecture decision deliberately changes that boundary. WAC does not alter
RMA priority, and it is not the current next action. A valid, architecturally
sound UTCP deployment operates today on RuntimeNode readiness, declared and
observed capabilities, K5C count-based admission, failure-domain constraints,
deterministic selection and ordering, RuntimeNode lifecycle and draining, and
the proven K5 distributed placement corridor — with no WAC stage present.

`WAC-*` is a separate future capability-track namespace. It is not a
continuation of the `T*`, `V*`, or `K5*` sequences, not a replacement for K5C,
and not part of current RMA work.

## Why WAC exists

Today's admission model answers one question well:

```text
How many units of active telephony work
are currently assigned to this RuntimeNode?
```

That count is deterministic, cheap, serializable under the existing lock, and
safe. It is deliberately blind to one thing: a unit of admitted work is not a
unit of measured compute. A codec-preserving bridge and a transcoded, recorded
conference participant each consume exactly one unit of the K5C budget while
consuming materially different runtime resources.

WAC is the future track that would let UTCP additionally answer:

```text
What type of workload is being requested?

How expensive is that workload expected to be?

What execution pressure is this RuntimeNode under right now?

Can this RuntimeNode safely absorb this particular workload?

Of all eligible RuntimeNodes, which is the best candidate for it?
```

The evolution is therefore:

```text
UTCP today
    "Is this RuntimeNode eligible,
     and is its deterministic count budget available?"

                |
                | future evolution
                v

UTCP with WAC
    "Is this RuntimeNode eligible,
     can it safely absorb THIS workload,
     and is it the best eligible candidate for that workload right now?"
```

## What WAC must not replace

WAC augments the existing model; it does not erase it.

The K5C contract remains exactly as accepted and proven:

```text
capacity_weight = 0
    -> unlimited count budget

capacity_weight > 0
    -> capacity-eligible while active telephony work < capacity_weight
```

Canonical active telephony work keeps its existing definition — active
conference runtime bindings plus non-terminal CallLegs assigned to the
RuntimeNode — and this roadmap does not redefine it. `capacity_weight` remains
an operator-declared admission count limit. It is never reinterpreted as
hardware capacity, CPU capacity, a PBX benchmark, a channel specification, a
provider guarantee, a workload-weighted score, or a universal concurrency
ceiling.

K5C remains valid after WAC exists. The intended future relationship is
additive and conservative:

```text
existing K5C eligibility
AND
future workload-aware eligibility
```

K5C therefore stays the deterministic safety floor and the fallback whenever
workload-aware inputs are unsupported, unknown, or stale. No WAC stage may
delete that floor, and this roadmap binds no production algorithm on top of it.

## Binding architectural principles

These principles are binding on every future WAC stage even though the stages
themselves are unscheduled:

1. WAC remains vendor-neutral.
2. `runtime_family` is never a capacity tier.
3. K5C remains the deterministic baseline and fallback.
4. Workload cost may vary materially by telephony operation.
5. Missing telemetry must not silently equal zero pressure.
6. Observations are not canonical Call or lifecycle state.
7. Workload-aware admission controls new work.
8. Existing calls are not terminated merely because capacity pressure rises.
9. Selection remains explainable and deterministic.
10. Kubernetes retains infrastructure scheduling authority.
11. WAC is post-core and non-R0-gating.
12. Workload-specific capacity claims require reproducible evidence.

## Vendor neutrality

`runtime_family` is technology and adapter-binding metadata. It is not a
capacity tier, a performance class, a scheduling preference, or a workload-cost
multiplier. Assumptions of the shape:

```text
Asterisk  = small  / low capacity
FreeSWITCH = large / high capacity
```

are folklore and must never be encoded in any WAC stage, in either direction.
`asterisk` and `freeswitch` are equally first-class execution families, and no
selection corridor may gain a `runtime_family` predicate or family-based
ordering term.

Future admission and placement decisions must instead derive from requested
workload characteristics, required capabilities, observed RuntimeNode state,
configured safety policy, and reproducible calibration evidence. Real
behavioral differences between runtimes belong in declared and observed
capabilities and inside adapters — not in the family value.

## Authority boundaries

```text
UTCP
    telephony admission, eligibility interpretation, workload policy,
    RuntimeNode selection, placement intent, draining, reconciliation, audit

Kubernetes
    Node resources, Pod scheduling and placement, cordon/drain mechanics,
    infrastructure availability and capacity facts

Runtime adapters
    provider-specific execution beneath the canonical operation contract

Metrics and telemetry
    observations only; never canonical Call, CallLeg, RuntimeNode lifecycle,
    scheduling, or business-state authority
```

WAC must not turn UTCP into a second Kubernetes scheduler, a Kubernetes
console, or an infrastructure provisioning authority. Observing infrastructure
facts as ephemeral selection inputs is permitted under the existing observation
rules; owning them is not.

All future workload-aware eligibility must converge on the three existing
selection corridors — conference selection in `TelephonyDomainService`,
outbound selection in `RuntimeNodeSelector::selectForOutboundCall()`, and
inbound selection in `kamailio_inbound_runtime_target_view` — and must not
introduce a fourth independent selection mechanism.

## Dependency order

```text
Existing K5C deterministic admission
            |
            v
         WAC-0
            |
            v
         WAC-1
            |
       +----+----+
       |         |
       v         v
    WAC-2      WAC-3
       |         |
       +----+----+
            |
            v
         WAC-4
            |
            v
         WAC-5
            |
            v
         WAC-6
            |
       +----+----+
       |         |
       v         v
    WAC-7      WAC-8
       |         |
       +----+----+
            |
            v
         WAC-9
            |
            v
        WAC-10
```

This is a dependency order, not a release commitment. The stages are not
required to be delivered together, and no stage is scheduled.

## Release grouping

```text
WAC Foundation
    WAC-0  Capacity authority and evidence model
    WAC-1  Workload classification foundation
    WAC-2  Runtime capacity observation

WAC Admission v1
    WAC-3  Workload cost profiles
    WAC-4  Runtime headroom model
    WAC-5  Workload-aware admission

WAC Placement v1
    WAC-6  Workload-aware candidate ranking

WAC Distributed Optimization
    WAC-7  Placement diversity and failure-domain optimization
    WAC-8  Capacity calibration and benchmark evidence

WAC Advanced Operations
    WAC-9  Adaptive capacity recommendations
    WAC-10 Autoscaling integration
```

**WAC-5 is the first milestone intended to alter production admission
behavior.** Every stage before it establishes architecture, classification,
observation, and policy evidence without changing proven K5C runtime behavior.

## Stages

### WAC-0 — Capacity Authority and Evidence Model

**Purpose:** define the architecture before changing any admission behavior.

**Future scope:** establish what constitutes a workload and what constitutes
workload cost; which observations are trustworthy and from where; who owns
configured capacity policy; how freshness and staleness are determined; how
reservations and concurrency interact with capacity accounting; how the
inbound, outbound, and conference corridors consume the model; and how K5C
fallback stays deterministic when workload-aware inputs are absent.

**Non-goals:** no admission change, no schema, no metrics collection, no
selector change.

**Exit concept:** an accepted architecture decision defining workload-aware
capacity authority, vocabulary, fallback semantics, and integration seams.

This roadmap does not perform WAC-0. WAC-0 is the first future
implementation and evidence milestone of the track.

### WAC-1 — Workload Classification Foundation

**Purpose:** introduce a vendor-neutral conceptual `WorkloadProfile` so that a
request can describe what kind of telephony work it is.

**Illustrative categories** (conceptual only; exact names are a WAC-0/WAC-1
decision, and existing repository vocabulary wins where it already applies):

```text
voice.bridge
voice.transcode
recording.capture
conference.participant
media.playback
media.processing.realtime
```

**Illustrative characteristics:** codec characteristics, transcoding
requirement, recording requirement, conference participation, media relay,
real-time processing, and any special runtime capability requirement.

**Non-goals:** no production schema, API fields, or RuntimeNode fields.
Vendor-specific workload classes are forbidden — names such as
`freeswitch-heavy` or `asterisk-light` must never be introduced.

**Exit concept:** an agreed vendor-neutral workload vocabulary that maps
cleanly onto the existing capability contract.

### WAC-2 — Runtime Capacity Observation

**Purpose:** normalize trustworthy observations about RuntimeNode execution
pressure.

**Illustrative observation categories:** CPU pressure, memory pressure, active
sessions or channels, bridges and conferences, transcoding pressure, recording
pressure, media and session pressure, and runtime-specific pressure. These are
conceptual future categories only.

**Binding rule:** missing telemetry does not mean zero pressure. A future
observation model must be able to distinguish concepts such as:

```text
SUPPORTED
UNSUPPORTED
UNKNOWN
STALE
AVAILABLE
```

without this roadmap defining their storage, representation, or thresholds.

**Non-goals:** metrics remain observations. They must not become Call lifecycle
authority, RuntimeNode lifecycle authority, Kubernetes scheduling authority, or
business-state authority. No collector, query, or exporter is defined here.

**Exit concept:** a trustworthy, explicitly-typed observation surface whose
absence is representable and safe.

### WAC-3 — Workload Cost Profiles

**Purpose:** estimate the relative capacity cost of a requested workload.

Different telephony operations can cost materially different amounts — a simple
bridge, a transcoded call, a recorded call, and a conference participant are
not interchangeable. Any illustration of that difference in this document is
explanatory only.

**Binding rule:** example costs are not production constants. This roadmap
deliberately commits no constants, coefficients, or units — values of the shape
`bridge = 1`, `transcode = 3` are illustrations of a concept elsewhere in the
industry, never repository policy.

A future cost model should ultimately be supported by runtime version, host
profile, workload characteristics, benchmark and calibration evidence, and
configured policy.

**Exit concept:** a bounded, evidence-supported way to express relative
workload cost that a later stage can consume.

### WAC-4 — Runtime Headroom Model

**Purpose:** convert workload cost plus observations into a vendor-neutral
notion of remaining safe capacity.

Conceptually:

```text
capacity envelope
    - observed execution pressure
    - reserved safety margin
    = available headroom
```

**Binding warning:** this must not collapse into a single-dimension rule such
as `CPU < X% = eligible`. Telephony bottlenecks are frequently not CPU-bound —
media, DSP, I/O, session, and runtime-internal limits can bind first. A future
model may therefore be multi-dimensional.

**Non-goals:** no percentages, thresholds, weights, or formulas are defined
here.

**Exit concept:** an explainable headroom concept that composes with K5C rather
than replacing it.

### WAC-5 — Workload-Aware Admission

**Purpose:** apply the model to admission. **This is the first WAC milestone
allowed to change production admission behavior, and that boundary is
explicit.**

Future eligibility may conceptually combine:

```text
RuntimeNode lifecycle and readiness
required capabilities
K5C count budget
failure-domain requirements
trustworthy workload observations
requested workload cost
available headroom
```

**Binding rules:**

- All existing selection corridors converge on the same admission authority; no
  fourth independent selection mechanism is introduced.
- Explicit RuntimeNode requests must not bypass workload-aware safety, exactly
  as they do not bypass K5C eligibility today.
- Admission controls new work. Lifecycle authority controls existing work.
  Existing Calls, CallLegs, and conference bindings must not be terminated,
  drained, or evicted merely because resource pressure rises after admission.
- Behavior under stale or missing telemetry must be explicit and deterministic
  rather than accidental.

Policy classes may later be described conceptually as `strict`,
`conservative fallback`, and `legacy/K5C fallback`. None of these is canonical
yet; choosing and binding one is future work, not a decision of this roadmap.

**Exit concept:** workload-aware admission that is safe by default, degrades
deterministically to K5C, and preserves the existing serialization seam in
which mutable eligibility is recounted after the applicable lock and before a
reservation is committed.

### WAC-6 — Workload-Aware Candidate Ranking

**Purpose:** once candidates are known to be safe, choose the best candidate for
the requested workload.

Future ranking may conceptually consider placement priority, workload-specific
available headroom, current pressure, workload concentration, failure-domain
considerations, and stable RuntimeNode identity.

**Binding rules:** no machine-learning scheduler, no opaque scoring, and no
arbitrary dynamic formula. Selection must remain explainable, reproducible, and
deterministic — the existing lexicographic ordering discipline is the standard
to preserve, not to abandon.

Illustrative scenario:

```text
Runtime A
    more simple calls
    substantial remaining headroom

Runtime B
    fewer calls
    heavy transcoding and recording
    little remaining headroom

new simple call
    -> A may be the better candidate despite having more active calls
```

That case is precisely the limitation WAC exists to address: a pure count
prefers B, while a workload-aware view can prefer A.

**Exit concept:** deterministic, explainable ranking that improves candidate
choice without introducing hidden scoring.

### WAC-7 — Placement Diversity and Failure-Domain Optimization

**Purpose:** extend existing hard failure-domain eligibility with optional
optimization.

Possible future goals: avoid workload concentration, spread redundant
workloads, prefer underutilized zones or hosts, and preserve warm spare
capacity.

**Binding rules:** hard constraints remain hard constraints — optimization
never softens an eligibility requirement. UTCP remains responsible for
telephony RuntimeNode selection and policy; Kubernetes remains responsible for
Node resources, Pod scheduling and placement, cordon and drain mechanics, and
infrastructure availability.

**Exit concept:** bounded diversity preferences layered above unchanged hard
eligibility.

### WAC-8 — Capacity Calibration and Benchmark Evidence

**Purpose:** make workload cost and capacity assumptions reproducible rather
than asserted.

Future calibration should consider combinations such as runtime family and
version, host profile, codec, transcoding, recording, conference workload, and
media processing.

**Binding rules:** benchmark evidence must be workload-specific, versioned,
reproducible, and safety-margin aware. Benchmark results must not silently
become production policy — promotion into policy is a deliberate, governed act.

**This roadmap invents no benchmark results and claims no capacity numbers for
Asterisk, FreeSWITCH, or any other runtime.**

**Exit concept:** a reproducible calibration method whose outputs are auditable
inputs to policy, not automatic policy.

### WAC-9 — Adaptive Capacity Recommendations

**Purpose:** use accumulated observations to improve capacity recommendations.
This stage comes late deliberately.

Preferred conceptual flow:

```text
observations
    -> capacity recommendation
    -> policy or operator acceptance, or bounded governance
    -> new approved envelope
```

**Binding rules:** any adaptive behavior must remain bounded and explainable.
Automatic self-modifying safety limits are not designed here. No machine
learning is required, and none is implied.

**Exit concept:** recommendations that inform a governed decision rather than
silently mutating safety limits.

### WAC-10 — Autoscaling Integration

**Purpose:** only after workload-aware admission is trustworthy, allow telephony
capacity pressure to inform external scaling.

Potential future signals: sustained low headroom, admission rejection pressure,
workload-specific resource exhaustion, and redundancy deficit.

UTCP may eventually expose bounded metrics or intents usable by systems such as
Kubernetes HPA, KEDA, cluster autoscaling, or external infrastructure
automation. **UTCP must not become the infrastructure provisioning authority.**

Conceptual authority flow:

```text
UTCP detects telephony capacity pressure
        -> publishes a bounded scaling signal
        -> Kubernetes / infrastructure authority scales
        -> RuntimeNodes appear and become Ready
        -> normal UTCP discovery, readiness, and admission apply
```

Adding or removing a RuntimeNode remains an operator or infrastructure action
through the canonical authorities; "automatic PBX horizontal scaling" remains
an initial-roadmap non-goal until an explicit decision changes it.

**Exit concept:** a bounded, consumable capacity-pressure signal that leaves
provisioning authority where it already belongs.

## Future proof strategy

No WAC proof exists today. When WAC work eventually begins, capacity claims
should rest on reproducible workload-specific evidence rather than folklore or
vendor numbers. Conceptual future proof categories include:

```text
simple-bridge capacity
recording capacity
transcoding capacity
conference capacity
media-operation capacity
drain-under-load
reconvergence-under-load
```

**These are future proof categories; no WAC proof exists today.** None of them
is scheduled, and none may be reported as evidence until it is actually
performed. Existing K5C, K5D, and K5E live proof remains historical truth and
is unaffected by this roadmap.

## Open architectural questions owned by WAC-0

These are intentionally unresolved. They are future architectural decisions,
not current blockers, and resolving them without evidence is out of scope:

```text
capacity-unit representation
capacity-vector representation
observation persistence model
metric sources and collection path
freshness and staleness thresholds
fallback policy selection
reservation and concurrency accounting
cost units and cost derivation
benchmark harness shape
ranking model
```

Leaving these open is deliberate. Inventing exact implementation detail merely
to make this roadmap look complete would create false precision that later
evidence would have to unwind.
