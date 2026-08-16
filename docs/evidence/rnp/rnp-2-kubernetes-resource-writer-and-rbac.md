# RNP-2 — Kubernetes Resource Writer and Scoped RBAC

## Verdict

Historical repository state before the authority cutover: RNP-2 was
implemented and repository-tested, but live authorization proof was blocked
because the active `overlays/local` did not include the existing staged
`components/runtime-fencing` ServiceAccount/RoleBinding. The expected
`utcp-runtime-fencer` identity was absent from the live cluster. That blocked
state is preserved here as evidence; no alternate component apply was used.

Current result: **RNP-2 is complete after the approved Option 1 cutover.** The
existing `utcp-runtime-fencer` / `telephony-infrastructure-worker` boundary is
now the single canonical namespace-scoped authority for runtime fencing and
managed-runtime resource writing. The component is included by the canonical
local overlays, the worker is Ready, and the live allow/deny matrix matches the
combined contract below. No RNP-2 managed Asterisk workload, Secret, Service,
or Deployment was created.

### Option 1 authority decision

The staged fencing identity was intentionally promoted rather than duplicated:

    telephony-infrastructure-worker
        → utcp-runtime-fencer ServiceAccount
        → utcp-runtime-fencer Role in utcp-runtime
        → RuntimeNode-owned Secret/Deployment/Service writes

The API and normal application workers remain outside this Kubernetes write
boundary. The old C5-only assumptions that fencing must not have delete or
Secret authority, and must remain absent from the local overlay, were replaced
with exact combined fencing plus RNP assertions. The executable checks now
parse rendered objects and validate identity, namespace, ownership, exact
verbs, and forbidden privilege absence.

## Authority

RNP-2 extends the existing `HttpKubernetesWorkloadClient`; it does not create a
second Kubernetes client:

    telephony-infrastructure-worker
        → HttpKubernetesWorkloadClient
        → Kubernetes API

The Admin API, web, scheduler, generic worker, Reverb, event normalizer, and
telephony reconciler remain outside this Kubernetes write boundary. RNP-1
requests remain durable PostgreSQL intent with `status = requested`, and their
canonical RuntimeNodes remain `draft`. RNP-2 does not add
`runtime.node.provision`.

## Supported resource scope

The writer supports exactly these namespaced resources in `utcp-runtime`:

* `v1/Secret`
* `apps/v1/Deployment`
* `v1/Service`

The caller cannot supply another namespace. The client rejects a namespace
outside the configured canonical runtime namespace before sending an HTTP
request. It does not invoke `kubectl`, Helm, or Kustomize.

## Ownership contract

Every desired resource is stamped with the established UTCP labels:

    app.kubernetes.io/part-of = utcp
    utcp.dev/runtime-node     = <RuntimeNode slug>

The apply/delete behavior is:

    absent   → create
    owned    → converge or delete
    unowned  → ownership conflict and zero mutation
    absent delete → idempotent success

An existing same-name resource is never adopted, relabeled, overwritten, or
force-deleted without both expected ownership labels.

## Resource handling

Secret responses are reduced to apiVersion, kind, metadata, and type. Secret
data is not returned, logged, copied into exceptions, or included in public
writer results. Deployment apply accepts an already-constructed desired
object and preserves the existing workload contract supplied by its caller.
Service create/converge removes Kubernetes-assigned fields from the write body:
`clusterIP`, `clusterIPs`, `ipFamilies`, `ipFamilyPolicy`, and
`healthCheckNodePort`.

Kubernetes responses are classified through the existing client exception
abstraction: 401/403 as permission denial, 409 as conflict, 422 as invalid
request, 404 as target mismatch where a required object is missing, and
429/5xx/transport or timeout failures as unavailable. Ownership and namespace
violations are rejected locally before mutation.

## RBAC

The existing namespace-scoped `utcp-runtime-fencer` Role retains current
fencing permissions:

* `apps/deployments`: `get`, `list`
* `apps/replicasets`: `get`, `list`
* `apps/deployments/scale`: `get`, `patch`
* core `pods`: `get`, `list`

RNP-2 adds only:

* `apps/deployments`: `create`, `patch`, `delete` (alongside existing `get`,
  `list`)
* core `services`: `get`, `create`, `patch`, `delete`
* core `secrets`: `get`, `create`, `patch`, `delete`

The writer uses GET before every apply/delete, POST for absent apply, PATCH
for owned convergence, and DELETE for owned deletion. Therefore the added
resource verbs are exactly `get`, `create`, `patch`, and `delete`; it does not
use list, watch, or update for those resources. The Role remains namespaced in
`utcp-runtime` and bound only to the `utcp-runtime-fencer` ServiceAccount in
`utcp-platform`.

No wildcard, cluster-wide authority, pods/exec, node, namespace, RBAC, CRD,
ServiceAccount, or external-namespace Secret permission was added. The API
Deployment does not use the infrastructure ServiceAccount or the Kubernetes
API-client identity.

## Tests and live proof

Focused tests cover namespace rejection, create/converge/delete/idempotency,
unowned conflicts, Secret sanitization, Service server-managed fields, HTTP
failure mapping, current runtime-fencing behavior, targeted Role assertions,
and absence of API-pod infrastructure identity. Full repository checks and
`git diff --check` were run after focused tests.

The canonical manifests were applied through `make k8s-apply` using the
repository-owned `k3d-utcp-local` kubeconfig. The existing component is now
included in both the root local composition and its canonical local platform
sub-overlay, so the normal lifecycle created the ServiceAccount, Role,
RoleBinding, and `utcp-runtime-fence-worker`. The worker reported `1/1 Ready`
and is the only Deployment using `utcp-runtime-fencer`.

The live service-account matrix passed. In `utcp-runtime`, the identity can
get/list/create/patch/delete Deployments, get/patch `deployments/scale`,
get/list ReplicaSets, get/list Pods, and get/create/patch/delete Services and
Secrets. It cannot watch/update those writer resources, mutate the scale
subresource outside get/patch, or access forbidden cluster, namespace, RBAC,
CRD, pod-exec, or other-namespace resources. The API identity
`utcp-platform/utcp-platform-app` is denied all six new infrastructure writes.

No disposable managed runtime resource was created. RNP-1 requests remain
`requested`, linked RuntimeNodes remain `draft`, and no
`runtime.node.provision` execution was added.

## Scope boundary

RNP-3 managed Asterisk provisioning, generated ARI credentials, endpoint
generation, RuntimeNode activation, deprovisioning, Admin UI, FreeSWITCH, C7,
T6, and V0 are not implemented here.
