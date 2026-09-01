# ADR-024: Kubernetes Host Awareness and Telephony-Aware Infrastructure Operations

## Status

Accepted as a future architecture and roadmap direction. K5 is planned in
parallel with the telephony mainline and is R0-critical; no functionality in
this ADR is implemented by this documentation packet.

## Context

UTCP is Kubernetes-first and may operate across clusters containing multiple
physical or virtual worker machines. Kubernetes owns infrastructure facts and
Pod placement; UTCP owns the telephony consequences of those facts.

A Kubernetes Node (or host) is not a UTCP `RuntimeNode`. One Kubernetes Node
may host zero, one, or multiple UTCP RuntimeNodes and supporting telephony
workloads.

## Decision

Kubernetes remains authoritative for Node existence, Ready/NotReady and other
Node conditions, addresses, capacity, allocatable resources, labels, taints,
cordon state, scheduling, and Pod placement. UTCP may observe, normalize,
associate, and display these facts, but must not create a competing canonical
Kubernetes infrastructure authority in PostgreSQL.

`RuntimeNode` remains the UTCP registry concept for a managed or understood
telephony runtime. The conceptual relationship is:

```text
Kubernetes cluster
  -> Kubernetes host / Node
    -> Kubernetes workload / Pod
      -> UTCP RuntimeNode
        -> telephony calls, bindings, and operations
```

UTCP owns the telephony interpretation: RuntimeNode eligibility, workload
placement awareness, active-call and binding impact, draining eligibility,
telephony maintenance readiness, and placement or failure-domain policy.

The first future infrastructure slice is intentionally read-only:

```text
Kubernetes Node discovery
  -> Host inventory
  -> Ready/NotReady and basic capacity
  -> workload placement
  -> RuntimeNode association
  -> read-only Admin UI
```

The Admin UI may eventually expose `Hosts` and `Telephony Runtimes` as an
operator-oriented infrastructure surface. It is not intended to become
Rancher, OpenLens, or a generic Kubernetes Dashboard.

Later slices may add RuntimeNode placement awareness, capacity and
failure-domain policy, telephony-aware host maintenance/drain, and multi-site
or cloud placement. These remain roadmap concepts rather than promises of a
specific hybrid or federated topology.

## Telephony-aware maintenance boundary

Future host maintenance starts as a canonical UTCP API operation. UTCP first
prevents new placement on affected runtimes, moves eligible RuntimeNodes into
`draining`, and waits for canonical telephony conditions such as zero active
Calls and zero active runtime bindings. After the RuntimeNodes are drained,
the canonical controller/reconciliation authority coordinates the
Kubernetes-owned cordon/drain operation and observes replacement workloads.

This is not a raw `kubectl drain` button. Routine lifecycle management must not
require `kubectl`, an Artisan drain command, or manual reconciliation. Any
future mutation requires normal authorization, audit, least-privilege
Kubernetes access, observable failures, and idempotent recovery.

## Security and realtime boundaries

Future Kubernetes API access follows the existing hardened UTCP workload
identity pattern: explicit projected tokens, least-privilege RBAC, no broad
service-account token automount, no cluster-admin requirement, and explicit
NetworkPolicy. The Admin UI must not expose arbitrary Kubernetes command
passthrough.

Infrastructure views follow the established realtime rule:

```text
canonical Kubernetes/UTCP state change
  -> Reverb invalidation
    -> frontend refetch
      -> canonical API state
```

Reverb tells the UI that something changed; it is not infrastructure state
authority.

## Consequences

- Host facts and telephony-runtime facts retain separate authorities.
- Operators can eventually see which RuntimeNodes, calls, and bindings are
  affected by a host condition without turning UTCP into a Kubernetes console.
- A first read-only slice can provide useful multi-machine visibility without
  introducing Kubernetes mutation authority or new telephony placement policy.
- The boundary supports future on-premises, commercial-cloud, availability
  zone, and other failure-domain metadata when the selected topology supports
  it.

## Non-goals

- Generic Kubernetes dashboard functionality.
- Arbitrary `kubectl` execution from the Admin UI.
- Cluster provisioning or replacement of Kubernetes scheduling. This forbids
  UTCP creating or managing Kubernetes clusters, replacing k3s/kubeadm,
  inventing Kubernetes Nodes, owning Node existence or readiness, or becoming a
  generic cluster lifecycle manager. It does **not** forbid the later guided
  enrollment of a new machine into an already-existing, already-authorized
  cluster described in the 2026-08-30 amendment below; guided existing-cluster
  host enrollment is not cluster provisioning.
- Immediate multi-cluster, cloud-federation, or AWS-hybrid implementation.
- Any C6 closure work, T4 FreeSWITCH parity work, or C7 routing/trunk work.

## Roadmap placement

These slices form the K5 distributed telephony infrastructure track, beginning
with K5A Host / Kubernetes Node Visibility and progressing through K5E
Distributed Infrastructure Live Proof. K5 is not a serial prerequisite for
C6 closure, T4, or C7A: C7A may begin when T4 closes even if K5 is incomplete.
K5E is nevertheless required before the R0 portfolio release closes, alongside
the serial telephony capability track.

## Roadmap alignment amendment — 2026-08-24

The intended UTCP product is not merely a telephony stack running inside one
Kubernetes environment. UTCP is a vendor-neutral telephony control plane for
distributed execution across machines and host failure domains, with a
long-term direction toward multi-site, hybrid/cloud, and potentially
multi-cluster operation. Kubernetes remains the infrastructure authority;
UTCP owns the telephony-aware interpretation, eligibility, placement intent,
RuntimeNode lifecycle, maintenance coordination, reconciliation, and audit.

This amendment classifies K5 as **Planned / Parallel / R0-Critical** rather
than generic deferred or post-R0 work. It does not add a false `T4 -> K5 ->
C7A` dependency. The bounded R0 K5 exit is a proof across at least two
distinct Kubernetes host/failure domains covering placement correlation,
runtime readiness, telephony eligibility, new-work exclusion, draining, and
automatic restoration. Full multi-cluster federation and cloud-provider
automation remain future-compatible directions, not R0 requirements.

RMA — Recording & Media Archive — intentionally follows K5E. Future recording
and archive contracts may rely on the host visibility, placement/failure-domain
correlation, telephony-aware draining, maintenance coordination, and automatic
restoration established by K5, without assuming one host or local filesystem.
This ADR does not define those RMA contracts; see
[`ADR-029`](ADR-029-recording-media-artifact-and-archive-authority.md).

## Roadmap alignment amendment — 2026-08-30: guided existing-cluster host enrollment

### Why this amendment exists

The original non-goal list forbids "cluster provisioning" without distinguishing
two different things that current wording collapses:

```text
cluster provisioning                              still a non-goal
guided enrollment into an existing cluster        future allowed direction
```

This amendment draws that line so a later operator-experience slice cannot be
mistaken either for a prohibited direction or for permission to become a
Kubernetes replacement.

### What remains forbidden

UTCP must never create or manage Kubernetes clusters, replace k3s or kubeadm,
invent Kubernetes Nodes, own Node existence or readiness, become a generic
cluster lifecycle manager, or become Rancher, OpenLens, or a Kubernetes
Dashboard. Arbitrary `kubectl` execution and arbitrary host shell access from
the Admin UI remain forbidden.

### What a future guided enrollment may become

A future slice may offer a guided Admin UI workflow whose whole purpose is to
make an operator's existing, supported Kubernetes join procedure easier and
auditable — never to perform cluster lifecycle management:

```text
Admin UI
  -> create a short-lived host enrollment intent
  -> generate a one-time bootstrap credential/package
  -> operator runs it locally on the new machine
  -> the machine joins through the supported Kubernetes/k3s mechanism
  -> Kubernetes creates the authoritative Node
  -> UTCP observes and discovers that Node
  -> enrollment correlates to the Kubernetes Node UID
  -> normal K5 host visibility and telephony interpretation take over
```

The privileged work happens locally on the machine being enrolled, initiated by
the operator. UTCP issues an intent and later correlates a fact; it does not
reach into hosts.

### Enrollment authority boundary

A future enrollment record — conceptually `NodeEnrollment` or
`InfrastructureEnrollment` — is an **intent and audit** object, not a host
authority. It may own only:

```text
enrollment intent
one-time token hash
expiration
claim/consumption state
operator audit metadata
requested role/topology hints
matched Kubernetes Node UID
failure/cancellation status
```

It must never become authoritative for Node existence, readiness, addresses,
capacity, conditions, scheduling, or placement. Those remain Kubernetes facts
under the Decision above, and no durable duplicate `Host` authority is created.
No schema is defined by this amendment.

### Enrollment lifecycle and the readiness rule

A bounded conceptual lifecycle, not a schema commitment:

```text
PENDING -> CLAIMED -> JOINING -> DISCOVERED -> READY
terminal: EXPIRED | FAILED | CANCELLED
```

One rule is load-bearing and permanent:

```text
READY is never operator-authored.
```

Readiness is derived from observed Kubernetes Node facts plus UTCP telephony
prerequisites. There must never be a "Mark Ready" control, nor any manual
authority over Node readiness.

### Security posture

Guided enrollment must use a short-lived, one-time or bounded-use enrollment
credential with explicit expiration, operator authorization, an audit trail,
least privilege, explicit failure states, and idempotent correlation. It must
not require a permanent root agent with unrestricted shell, broad passwordless
sudo, cluster-admin, or a static long-lived join token displayed in the UI.
Bootstrap transport, package signing, token exchange, and k3s join mechanics
remain implementation decisions and are deliberately not frozen here.

### Roadmap placement

This becomes **K5F — Guided Existing-Cluster Host Enrollment**, classified as
Planned / Post-K5E Operator Experience. It follows K5E, does not reorder
K5A–K5E, is **not an R0 gate by default**, and does not block RMA. K5A remains
read-only: it discovers Nodes that already exist in Kubernetes and gains no
"Create Host", manual Node registration, manual readiness, cluster-join, or host
mutation authority from this amendment.

The first K5E multi-host proof does **not** depend on K5F. The second host may be
joined with the normal supported Kubernetes/k3s procedure, after which K5E proves
discovery, RuntimeNode correlation, placement awareness, telephony eligibility,
failure-domain interpretation, new-work exclusion, draining, and restoration.
Only once that architecture is proven should K5F automate the operator path for
subsequent hosts.

K5D telephony-aware maintenance remains a separate workflow from enrollment and
is unchanged by this amendment. It must not become a raw drain button, arbitrary
node mutation surface, remote shell, or manual runtime-state editor.

`K5E -> RMA` is unchanged; RMA depends on the distributed host and
failure-domain foundation, not on guided enrollment UX.

## K5C capacity and failure-domain policy amendment — 2026-08-30

This amendment defines the K5C policy contract. K5C remains a planned,
bounded implementation slice; this amendment does not implement production
code, change RuntimeNode state, or advance the roadmap status.

### Canonical active telephony work

For capacity, retirement safety, drain convergence, and maintenance readiness,
a RuntimeNode carries canonical telephony work when it has:

```text
active conference runtime bindings
+ non-terminal CallLegs assigned to the RuntimeNode
```

A CallLeg is active for this predicate when its canonical observed state is
not in `CallState::terminal()`. This is a semantic definition only; it does
not redefine `CallState` or add a workload state table. K5D's eventual
`ACTIVE -> DRAINING -> DRAINED` convergence must use this same predicate, so
zero conference bindings alone does not prove that a RuntimeNode is drained.

K5C governs admission and selection of new telephony work. A candidate that
becomes capacity- or failure-domain-ineligible must not thereby terminate
Calls or CallLegs, remove conference bindings, force RuntimeNode draining, or
evict Pods. Existing-work convergence remains with established lifecycle and
recovery behavior and later K5D maintenance authority.

### Shared RuntimeNode capacity

`runtime_nodes.capacity_weight` is the single canonical RuntimeNode capacity
configuration. Despite its historical name, it is a count limit. A value of
zero means unlimited; otherwise a RuntimeNode is capacity-eligible exactly
when:

```text
active telephony work < capacity_weight
```

The budget is one shared RuntimeNode-wide budget across conference bindings
and non-terminal assigned CallLegs. It is not separate conference, outbound,
inbound, or workload-class budgets. K5C introduces no additional capacity
fields, coefficients, percentages, CPU thresholds, or weighted scores.

The proven deterministic ordering remains lexicographic, using the existing
repository-authoritative tuple and its unlimited available-slot treatment:

```text
placement_priority
down available capacity
down absolute active telephony load
down stable RuntimeNode identity
```

Implementations must preserve the existing authoritative serialization seam:
mutable-count eligibility is recounted after the applicable lock and before a
reservation is committed. Explicit RuntimeNode requests do not bypass
capacity or any other canonical eligibility rule. See ADR-027 §§11–12 for the
existing eligibility and selection/reservation contract; K5C extends the same
corridors rather than creating a new selector.

### Desired failure-domain constraints and observed topology

`placement_region` and `placement_zone` remain UTCP desired failure-domain
constraints, operator-writable only through:

```text
Web Admin -> authenticated application/domain API
           -> canonical PostgreSQL desired state
           -> automatic selection/reconciliation behavior
```

They are not factual claims about current residency and must not overwrite or
masquerade as Kubernetes observations. Kubernetes remains factual authority
for `topology.kubernetes.io/region`, `topology.kubernetes.io/zone`,
`kubernetes.io/hostname`, Node UID/name, and Pod placement. Hostname remains
observed placement context and never substitutes for region or zone.

When configured, region and zone are exact hard eligibility constraints:

* neither configured means no K5C failure-domain restriction;
* a configured region must exactly match the currently observed Kubernetes
  region;
* a configured zone must exactly match the currently observed Kubernetes
  zone; and
* when both are configured, both must match.

Comparison uses the existing validation/storage semantics. No hierarchy is
inferred beyond what Kubernetes reports. Missing, unknown, unavailable, or
ambiguous required topology cannot prove a configured constraint and makes a
RuntimeNode ineligible for new automatic telephony work. There is no silent
fallback or arbitrary observation-age/Pod-age heuristic. If no constraint is
configured, missing region/zone does not create a K5C penalty and an
infrastructure-observation outage does not become a new RuntimeNode readiness
failure.

The K5B placement states retain these semantics:

| K5B placement state | No configured region/zone | Configured region/zone |
| --- | --- | --- |
| `placed` | evaluate observed topology normally | exact configured match required |
| `no_managed_kubernetes_identity` | preserve existing selection semantics | ineligible; Kubernetes placement cannot be proven |
| `identity_present_but_not_currently_observed` | preserve existing non-K5C eligibility | ineligible until observed |
| `ambiguous_multiple_nodes_observed` | preserve existing non-K5C eligibility; do not pick a Node by order/age | ineligible unless the required value is unambiguous across relevant placement |
| `kubernetes_observation_unavailable` | preserve existing RuntimeNode readiness/eligibility authority | ineligible because the constraint cannot be validated |

Kubernetes Node readiness must not become a second writer of
`RuntimeNode.observed_state`. K5C may consume Kubernetes facts as ephemeral
selection inputs, but runtime-adapter observation remains authoritative for
RuntimeNode observed readiness.

There is no K5C region/zone preference, spread, anti-affinity, co-residence,
or other failure-domain scoring policy. K5B co-residence remains observable
information only. More sophisticated diversity policy requires separate
future authority.

### Selection corridors and SQL observation projection

K5C extends the three existing selection authorities and adds no fourth:

1. conference selection in `TelephonyDomainService`;
2. outbound selection in `RuntimeNodeSelector::selectForOutboundCall()`; and
3. inbound selection in `kamailio_inbound_runtime_target_view`.

Shared K5C eligibility semantics converge across these corridors. Capability-
specific requirements may differ, but accidental pre-K5C divergence must not
become a new policy. ADR-027 configuration/image-convergence requirements
remain applicable where that ADR requires them.

Because inbound selection is SQL-side, Kubernetes topology needed for inbound
eligibility may be made SQL-consumable through this bounded projection:

```text
Kubernetes factual observation
        -> automatic UTCP observer/projection
        -> derived PostgreSQL observation projection
        -> SQL selection view
```

The projection is derived, non-canonical, read-only to operators, automatically
refreshed, and subordinate to Kubernetes facts. A later implementation may
persist only the minimal observed RuntimeNode identity, Node UID/name,
region/zone, placement observation status, and freshness metadata needed for
deterministic SQL eligibility. Operators must not create Hosts, mark runtime
readiness, set observed region/zone, or invoke manual placement sync/projection
authority. No durable duplicate Host authority is created.

Observation failure must have explicit current/unknown/unavailable semantics.
No arbitrary freshness threshold is established by this amendment. With a
configured constraint, a value that cannot be proven from current authoritative
observation is ineligible; without one, observation outage does not replace
RuntimeNode readiness authority.

Applying this shared capacity budget to inbound selection may therefore
produce the existing canonical unavailable response when no eligible candidate
has capacity. That is an intentional K5C admission-policy consequence; V1's
deferred unlimited inbound behavior is not preserved as a K5C exception.

No platform, tenant, route, inbound, outbound, conference, strategy-enum, or
spread-policy configuration scope is added. Artisan remains outside the
management path: no routine capacity reconciliation, placement sync, or
runtime topology-editing command is authorized. The management authority
remains the ADR-032 Web Admin/API/PostgreSQL/automatic selection and
reconciliation path.

## Runtime family, capacity meaning, and scaling direction amendment — 2026-09-01

### Why this amendment exists

This ADR already owns K5 capacity and failure-domain policy, so it also owns
what `capacity_weight` *means* and what a RuntimeNode's technology family is
allowed to imply. The K5C amendment above defines the mechanism precisely but
does not state the interpretation rules, which leaves room for two telecom
folklore assumptions to be read back into the model later:

1. that `runtime_family` is a performance class, with Asterisk treated as an
   inherently small runtime and FreeSWITCH as an inherently large one; and
2. that `capacity_weight` is a measured or benchmarked physical PBX maximum.

Both readings are wrong. This amendment records the durable interpretation. It
changes no field, semantics, selector, view, migration, API, or proven K5C
behavior, and it does not reinterpret completed K5C evidence.

### Runtime family is technology metadata, not a performance class

`runtime_family` identifies the external runtime technology and its adapter
binding. It carries no capacity, throughput, quality, or preference meaning:

```text
runtime_family = technology / adapter family metadata

runtime_family != small / medium / large
runtime_family != low-capacity / high-capacity
runtime_family != preferred / inferior
```

Asterisk and FreeSWITCH are both first-class RuntimeNode execution families.
Neither is a fallback for the other, and no fixed per-family concurrency
figure is canonical anywhere in UTCP. Modern capacity for either runtime
depends on the actual workload — codec-preserving bridging, transcoding,
recording, conference mixing, media playback or injection, WebRTC/SIP media
handling, other DSP work — plus runtime configuration and available hardware.
A family identifier cannot express any of that and must not be used as a proxy
for it.

Selection therefore stays on the existing inputs and must not gain a family
term: canonical eligibility (tenant, desired state, observed readiness,
configuration and execution convergence), required capability, desired
placement and failure-domain policy, and the applicable admission budget. The
K5C ordering tuple is unchanged. `RuntimeNodeSelector`, `TelephonyDomainService`,
and `kamailio_inbound_runtime_target_view` contain no `runtime_family`
predicate today, and a hard-coded preference for either family must not be
introduced in either direction.

Vendor neutrality does not mean pretending both runtimes behave identically.
Real differences are expressed as declared and observed capabilities and are
executed inside adapters:

```text
UTCP operation
  -> required capability
  -> eligible RuntimeNode selection
  -> adapter-specific execution
```

The canonical operation and capability contract stays UTCP-owned; the
provider-specific mechanism stays adapter-owned. This amendment introduces no
capability key.

### What `capacity_weight` is, and what it is not

`capacity_weight` is the current bounded deterministic **admission count**
primitive defined by the K5C amendment above: a value of zero means unlimited,
and otherwise a RuntimeNode is capacity-eligible exactly while
`active telephony work < capacity_weight`, over one shared RuntimeNode-wide
budget. That behavior is complete, live-proven, and unchanged here.

It is explicitly none of the following:

```text
a benchmark result
a hardware or media-capacity measurement
a SIP channel specification
a provider guarantee
a universal concurrency ceiling
a claim that every telephony workload costs the same
```

One unit of budget is one unit of admitted work, not one unit of measured
compute. A recording or transcoding leg and a simple codec-preserving bridge
consume one budget unit each while consuming materially different runtime
resources. K5C chose that simplification deliberately for R0: it is a
deterministic operator-declared admission limit, not a model of physical
capacity. Operators should set it from what they intend a RuntimeNode to
accept, and revise it from their own observed behavior.

### Future workload-aware admission seam

A later capacity model may become workload-aware — distinguishing costs such
as simple media bridging, transcoding, recording, conference participation,
media playback/injection, and WebRTC/SIP media handling — and may incorporate
authoritative observed runtime or resource pressure. The durable rule is only:

> Future admission may become workload-aware and evidence-driven. It must not
> infer capacity from `runtime_family`.

This is a future-compatible direction, not a committed implementation. No
roadmap phase currently owns it. This amendment deliberately defines no new
field, table, coefficient, percentage, CPU threshold, call limit, vendor
scoring constant, scheduling algorithm, or API, and does not rename or alter
`capacity_weight`. Any such model requires its own decision and its own proof.

Capacity claims made then should rest on reproducible workload-specific
evidence rather than folklore or arbitrary vendor numbers. Conceptual proof
categories — none of which exist today, and none of which this amendment
schedules — would include simple-bridge capacity, recording capacity,
transcoding capacity, conference capacity, media-operation capacity,
drain-under-load behavior, and reconvergence under load.

### Infrastructure capacity stays Kubernetes-owned

The separation established in the Decision section above is unchanged.
Kubernetes remains authoritative for Node capacity, allocatable resources, Pod
resource requests and limits, scheduling, and placement. UTCP owns telephony
RuntimeNode eligibility, lifecycle, capability requirements, telephony
admission, selection, placement interpretation, draining, and reconciliation.

A future workload-aware model consuming CPU, memory, or media telemetry would
consume those as ephemeral selection inputs under the existing observation
rules. Observing infrastructure facts must not make UTCP a second Kubernetes
scheduler or a competing canonical infrastructure authority.

### Horizontal RuntimeNode distribution is the scaling direction

UTCP is designed around a fleet of RuntimeNodes rather than one indefinitely
enlarged PBX instance:

```text
Kamailio / signaling edge
        |
        v
UTCP selection and admission
        |
   +----+----+---------+
   |         |         |
   v         v         v
Runtime A  Runtime B  Runtime C
   |         |         |
Asterisk  FreeSWITCH  Asterisk
```

When practical capacity is reached, the architecturally compatible answers are
adding RuntimeNodes, distributing work across them, and draining or replacing
individual runtimes — the K5C/K5D/K5E corridor that is already proven — rather
than a vendor-specific vertical-scaling assumption. Mixed-family fleets are
architecturally normal, and a heterogeneous fleet must not be ordered by
family.

This describes intent and existing capability only. UTCP has no automatic
horizontal autoscaling of telephony runtimes: adding or removing a RuntimeNode
remains an operator action through the canonical management authority, and
"automatic PBX horizontal scaling" remains an initial-roadmap non-goal.

## Roadmap status clarification amendment — 2026-09-01

### Why this amendment exists

The Status section above records this ADR's original acceptance: K5 was
accepted as a future architecture and roadmap direction, and no functionality
was implemented by that documentation packet. That statement was accurate for
the packet it described and is not rewritten here. Since then, K5A through
K5E have been implemented and natural-live-proven. This amendment records the
current implementation position without altering the original acceptance
record.

### Current position

Per the authoritative current-state ledger,
[`docs/roadmap/phase-status.md`](../roadmap/phase-status.md):

```text
K5A  Host / Kubernetes Node Visibility           Complete / natural-live-proven
K5B  Telephony Placement Awareness               Complete / natural-live-proven
K5C  Capacity and Failure-Domain Policy          Complete / natural-live-proven
K5D  Telephony-Aware Host Maintenance            Complete / natural-live-proven
K5E  Distributed Infrastructure Live Proof       Complete / natural-live-proven
K5F  Guided Existing-Cluster Host Enrollment     Planned; not implemented; not an R0 gate
```

K5 remains the parallel, R0-critical distributed-infrastructure track defined
above; K5E's closure satisfies the R0 convergence requirement this ADR
establishes. RMA is the current active R0-critical core work and depends on
K5E's closure, not on K5F.

The future workload-aware runtime family, capacity, and scaling-direction
amendment above remains architecture/future work only. It defines no field,
schema, coefficient, threshold, or admission algorithm, and none is
implemented or scheduled by this clarification.
