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
- Cluster provisioning or replacement of Kubernetes scheduling.
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
