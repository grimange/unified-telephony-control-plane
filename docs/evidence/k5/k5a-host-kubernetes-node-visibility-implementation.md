# K5A Host / Kubernetes Node Visibility — Implementation

Current-State-Impact: yes

## Scope

K5A adds a read-only, platform-authorized view of Kubernetes Nodes and the
UTCP-managed workloads placed on them. Kubernetes remains authoritative for
Node and Pod facts; UTCP does not persist a duplicate Host model.

The API reads Nodes and Pods through a dedicated read-only Kubernetes client,
orders observations deterministically, and correlates Pods to existing
RuntimeNodes using the managed `utcp.dev/runtime-node` workload identity and
the existing PostgreSQL RuntimeNode registry. Kubernetes unavailability is
returned as an explicit infrastructure observation failure rather than an
empty inventory.

The Admin Hosts page is read-only. It exposes Node readiness, addresses,
capacity/allocatable data, workloads, and RuntimeNode associations. No host
enrollment, placement, cordon, drain, or provisioning behavior was added.

## Security and proof

The platform API uses a dedicated read-only ClusterRole allowing only `get` and
`list` for `nodes` and `pods`. No Kubernetes mutation permissions were added to
this reader role. Focused fake-client and API/UI contract tests cover mapping,
ordering, authorization, unavailable observation, and absence of mutation
controls. No live cluster or browser proof was performed in this bounded
packet; that proof remains the next action.
