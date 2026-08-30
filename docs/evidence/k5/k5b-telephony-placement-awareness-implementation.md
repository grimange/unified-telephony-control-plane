# K5B — Telephony Placement Awareness Implementation

Current-State-Impact: yes

## Result

K5B placement awareness is implemented and repository-tested. The remaining
acceptance step is a natural native-k3s proof; K5C placement policy is not
implemented.

## Authority and bounded seam

Kubernetes remains authoritative for Node identity, readiness, addresses,
capacity, labels/topology, and Pod placement. RuntimeNode remains the UTCP
authority for runtime identity and lifecycle. The implementation reuses the
K5A `KubernetesHostVisibilityService` and its existing workload identity rule:
tenant-scoped RuntimeNode workload identity resolves to namespace plus
deployment, which is matched against managed `utcp` Pod labels and
`Pod.spec.nodeName`.

No Host, placement, or Node-residency table was added. Placement is a read-only
derived response and does not mutate `runtime_nodes.runtime_channel_id` or any
Kubernetes resource.

## Implemented behavior

`GET /api/v1/admin/runtime-nodes/{runtimeNode}/placement` is protected by the
existing `runtime.nodes.view` authorization and reports the observed Node,
readiness, addresses, capacity, allocatable resources, selected topology
labels, workload Pods, and tenant-scoped co-resident RuntimeNodes when exactly
one current Node is observed.

The response distinguishes missing managed workload identity, an identity with
no currently observed Pod, Kubernetes observation unavailability, and
conflicting Pods observed on multiple Nodes. Conflicting placement is reported
as ambiguous rather than selected by response order, Pod age, or a heuristic.
Node addresses and topology remain observations; absent region/zone labels are
represented as not reported. Co-resident RuntimeNodes and Pods use stable
ordering.

The existing RuntimeNode Admin view renders the observed host context as
read-only, including host readiness, topology, and co-resident RuntimeNodes.
It retains the existing desired placement fields without adding placement
mutation controls or CLI management.

## Proof

Focused backend tests cover placed, separated, co-resident, missing identity,
unobserved identity, ambiguous multi-Node, authorization, read-only routing,
stable host ordering, and unavailable Kubernetes observation. Frontend tests
cover the placement API contract, read-only host presentation, explicit
uncertainty states, and absence of placement controls. The web lint, typecheck,
test, and production build passed; the containerized API suite is the required
backend verification path.

No live deployment or browser proof was performed in this packet. The next
action is to deploy the exact commit through canonical native k3s and perform
the natural K5B proof. K5C capacity/failure-domain selection policy, K5D
maintenance, K5E multi-host acceptance, K5F enrollment, scheduler mutation,
and any durable placement authority remain out of scope.
