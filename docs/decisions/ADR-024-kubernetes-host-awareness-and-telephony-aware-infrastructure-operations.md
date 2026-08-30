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
