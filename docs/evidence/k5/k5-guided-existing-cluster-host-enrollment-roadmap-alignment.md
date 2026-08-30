# K5 — Guided Existing-Cluster Host Enrollment Roadmap Alignment

Current-State-Impact: no

Date: 2026-08-30

Starting HEAD: `e4fe41d8e5aca2b0b662625d31de2833cacf86c7`
(`fix(v1): strip caller identity id at provider boundary`)

## Verdict

`K5_GUIDED_EXISTING_CLUSTER_HOST_ENROLLMENT_ROADMAP_ALIGNED`

Documentation-only alignment. No K5 functionality was implemented, no production
source was modified, nothing was deployed, and the current V1
exactly-one-next-action is unchanged.

## Reason for alignment

ADR-024's non-goal list forbade "cluster provisioning" without distinguishing two
different things that the wording collapsed:

```text
cluster provisioning                          still a non-goal
guided enrollment into an existing cluster    future allowed direction
```

Without that distinction, a future guided "Add Host" workflow could be read
either as a prohibited direction or — worse — as licence to drift toward a
Kubernetes replacement. This packet draws the line explicitly and durably.

## Existing K5 authority preserved

The track order and dependency chain are unchanged:

```text
K5A -> K5B -> K5C -> K5D -> K5E -> RMA
```

`K5A` Host / Kubernetes Node Visibility, `K5B` Telephony Placement Awareness,
`K5C` Capacity and Failure-Domain Policy, `K5D` Telephony-Aware Host Maintenance,
and `K5E` Distributed Infrastructure Live Proof retain their existing definitions
and sequence. R0 still requires K5E. Neither roadmap track line was edited.

## K5A remains read-only

K5A's contract is unchanged and gains nothing from this amendment:

```text
Kubernetes Node discovery -> host inventory -> Ready/NotReady -> addresses
-> basic capacity -> labels/topology -> workload placement
-> RuntimeNode association -> read-only Admin UI
```

Explicitly still absent from K5A: Create Host, manual Node registration, manual
host IP authority, manual Ready state, cluster-join automation, SSH/bootstrap
automation, host mutation. The first real K5 implementation still discovers Nodes
that already exist in Kubernetes.

## Kubernetes authority preserved

Kubernetes remains authoritative for Node existence, Node UID, Node name,
Ready/NotReady and other conditions, addresses, capacity, allocatable resources,
labels, taints, cordon state, Pods, Deployments, Services, scheduling mechanics,
and workload placement. UTCP may observe, normalize, correlate, display, and
interpret telephony consequences — applying RuntimeNode eligibility, telephony
placement intent, telephony-aware maintenance coordination, and operator-intent
audit — without creating a competing canonical Kubernetes authority in
PostgreSQL.

## Cluster-provisioning non-goal clarified, not removed

The non-goal is retained and sharpened in place. Still forbidden: UTCP creating
or managing clusters, replacing k3s/kubeadm, inventing Nodes, owning Node
existence or readiness, becoming a generic cluster lifecycle manager, or becoming
Rancher/OpenLens/a Kubernetes Dashboard. Arbitrary `kubectl` execution and
arbitrary host shell from the Admin UI remain forbidden.

Distinguished from it: guided enrollment of a new machine into an
already-existing, already-authorized cluster.

## K5F added

```text
K5F — Guided Existing-Cluster Host Enrollment
Status: Planned / Post-K5E Operator Experience
```

Intended shape:

```text
Admin UI -> short-lived enrollment intent -> one-time bootstrap credential/package
-> operator executes locally on the new machine
-> machine joins via the supported Kubernetes/k3s mechanism
-> Kubernetes creates the authoritative Node
-> UTCP observes/discovers it -> enrollment correlates to Node UID
-> normal K5 host visibility and telephony interpretation take over
```

The privileged work happens locally on the machine being enrolled, initiated by
the operator. UTCP issues an intent and later correlates a fact; it does not
reach into hosts.

## Enrollment authority boundary

A future `NodeEnrollment` / `InfrastructureEnrollment` record is an intent and
audit object, not a host authority. It may own only enrollment intent, one-time
token hash, expiration, claim/consumption state, operator audit metadata,
requested role/topology hints, matched Kubernetes Node UID, and
failure/cancellation status.

It must never be authoritative for Node readiness, IPs, capacity, conditions,
scheduling, placement, or existence. No durable duplicate `Host` authority is
introduced, and no database schema was added by this packet.

## Readiness authority

Conceptual lifecycle, not a schema commitment:

```text
PENDING -> CLAIMED -> JOINING -> DISCOVERED -> READY
terminal: EXPIRED | FAILED | CANCELLED
```

One rule is permanent:

```text
READY is never operator-authored.
```

Readiness is derived from observed Kubernetes Node facts plus UTCP telephony
prerequisites. There must never be a "Mark Ready" control or any manual
readiness authority.

## Security posture

Guided enrollment must require a short-lived, one-time or bounded-use credential
with explicit expiration, operator authorization, an audit trail, least
privilege, explicit failure states, and idempotent correlation. It must not
require a permanent root agent with unrestricted shell, broad passwordless sudo,
cluster-admin, or a static long-lived join token in the UI. Bootstrap transport,
package signing, token exchange, and k3s join mechanics remain K5F implementation
decisions and are deliberately not frozen.

## R0 and RMA boundaries

```text
K5F: not an R0 gate by default; post-K5E operator-experience enhancement
R0 convergence unchanged:  A0, K5E, RMA -> R0
RMA dependency unchanged:  K5E -> RMA   (not K5F -> RMA)
```

RMA depends on the distributed host and failure-domain foundation, not on guided
enrollment UX.

## K5E first multi-host proof does not depend on K5F

The second physical or virtual host for K5E may be joined using the normal
supported Kubernetes/k3s procedure. K5E then proves automatic Node discovery,
RuntimeNode-to-host correlation, placement awareness, telephony eligibility,
failure-domain interpretation, new-work exclusion, draining, and restoration.
Only after that architecture is proven should K5F automate the operator path for
subsequent hosts.

## K5D maintenance boundary unchanged

```text
operator requests maintenance -> identify RuntimeNodes on host
-> exclude from new telephony work -> ACTIVE -> DRAINING -> DRAINED
-> wait for canonical calls/bindings to converge
-> coordinate authorized Kubernetes-owned maintenance
-> observe host/runtime restoration -> restore telephony eligibility
```

K5D must not become a raw `kubectl drain` button, an arbitrary node mutation UI,
a remote shell, or a manual runtime-state editor. Enrollment and maintenance
remain distinct product workflows.

## Admin UI direction

Conceptual only; no frontend work is implied and no K5A Create Host control is
introduced:

```text
K5A  Hosts — observe
K5B  Hosts — placement awareness
K5C  Hosts — capacity / failure-domain interpretation
K5D  Hosts — maintenance lifecycle
K5F  Hosts — guided Add Host enrollment
```

`docs/roadmap/ui-foundations.md` was inspected and left unmodified: it covers the
UI-A through UI-D shell, design-system, data-workflow, and realtime tracks and
contains no K5, Hosts, or Infrastructure navigation content that this alignment
could contradict.

## Current-state impact

None. This packet is future-roadmap clarification, not current execution
authority, so the header marker above is declared `no`.

The V1 exactly-one-next-action is unchanged: deploy the committed
`X-UTCP-Caller-Identity-ID` provider-boundary stripping correction to canonical
native k3s and perform one controlled natural provider-wire re-proof. V1 was not
advanced or closed, no live proof was started, and no K5 implementation was
performed.

## Boundary

Documentation only. No production source, Kubernetes manifest, backend schema, or
frontend code was modified. No deployment occurred. The temporary provider-wire
capture wrapper and its sudoers authorization remain installed, as they are still
required for the immediate V1 live re-proof.
