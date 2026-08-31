# K5D — Telephony-Aware Host Maintenance Implementation

Current-State-Impact: yes

## Starting state

- Starting HEAD: `fbf6aef1037d24c3bb48ee0646a2293d993c2482`
- K5C: complete / natural-live-proven.
- K5D: not started and next before this implementation.
- K5E, K5F, and Operational Reporting & Insights remain deferred.

## Implemented authority

K5D is a canonical, asynchronous maintenance intent managed through the
authenticated application API and Web Admin Hosts surface. The new
`k5d_host_maintenances` record stores intent, target Node UID/name snapshots,
affected RuntimeNode IDs, lifecycle phase, failure information, audit timing,
and completion timing. It is not a second Kubernetes Node authority.

Observed Kubernetes Node/Pod data remains factual Kubernetes authority. Host to
RuntimeNode correlation uses observed UTCP workload identity and Pod placement,
with Node UID verification preventing Node-name reuse from being treated as the
same target.

## Lifecycle and safety

The coordinator advances `requested -> draining_telephony -> telephony_drained
-> cordoning -> draining_kubernetes -> completed`. RuntimeNodes use the
existing `ACTIVE -> DRAINING -> DRAINED` lifecycle and the existing canonical
active-work predicate: active conference bindings plus non-terminal CallLegs.
Entering the drain path excludes new work through the existing desired-state
eligibility rules; existing work is never terminated.

Kubernetes cordon is attempted only after every currently associated active or
draining RuntimeNode is drained. Placement is refreshed on each reconciliation,
so a newly observed association prevents stale progression. Cordon is a
targeted Kubernetes API patch, followed by bounded Policy/v1 Pod eviction for
UTCP controller-managed Pods. DaemonSet, mirror, terminating, and terminal Pods
are skipped. PDB and transient API responses remain observable and retryable.
Already cordoned Nodes, already absent Pods, repeated reconciliation, and
completed work are idempotent.

## Kubernetes security boundary

The reconciler uses a dedicated `utcp-kubernetes-maintenance` ServiceAccount
with projected native credentials and an explicit API-client network label. Its
ClusterRole is limited to Node get/patch, Pod get/list, and Pod eviction create.
The K5C observer remains read-only; `utcp-platform-app` is not widened, no
cluster-admin or wildcard permissions were added, and no static token, host
kubeconfig, or production `kubectl` execution was introduced.

## Operator surface

Hosts now show maintenance phase, affected RuntimeNodes, observed active
telephony work, and failure reason where present. Authorized operators can
request “Prepare for maintenance” through the existing authenticated API. No
raw Node, Pod, cordon, eviction, or command-console controls were added, and no
manual reconciliation step is required.

## Validation and proof boundary

The implementation includes the maintenance schema, application client/service,
API route/controller, worker reconciliation hook, identity capability, UI
surface, Kubernetes ServiceAccount/RBAC, and manifest regression updates.
Focused service-contract and manifest regression coverage was added, while
repository suites cover the migration, route, lifecycle wiring, and existing
RuntimeNode contracts. Native-k3s deployment and natural host-maintenance
acceptance are not performed here. The remaining proof gap is
`K5D_NATURAL_LIVE_PROOF_PENDING`.

No K5C policy was changed. K5E multi-host acceptance, K5F enrollment, generic
Kubernetes administration, automatic uncordon/reactivation, and reporting
remain outside this bounded implementation.
