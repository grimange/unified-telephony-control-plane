# K5A — Host / Kubernetes Node Visibility Natural Live Proof (Blocked)

Current-State-Impact: yes

Date: 2026-08-30

Starting HEAD: `2bae1211584442e3f90a3c3c351f7255d2544595`
(`feat(k5): add kubernetes host visibility`)

## Verdict

`K5A_KUBERNETES_OBSERVER_LIVE_ACCESS_DEFECT`

and, independently:

`K5A_ADMIN_INFRASTRUCTURE_AUTHORIZATION_LIVE_DEFECT`

K5A was deployed through the canonical native-k3s lifecycle. Two distinct live
defects block acceptance. Both pass automated tests and fail only against a real
cluster and a pre-existing database, so neither is a proof-harness artifact.
Neither was repaired here.

The natural Web Admin proof was **not attempted**: the backing Admin API returns
`403`, and the observer beneath it cannot reach Kubernetes at all, so a browser
run would have produced no acceptance evidence.

## Deployment

```text
promoted source commit   2bae1211584442e3f90a3c3c351f7255d2544595
UTCP_SERVER_API_IMAGE    ghcr.io/grimange/utcp-api@sha256:d3b540ca…b1c4
lifecycle                server-image-sync -> server-config-check
                         -> server-image-preflight -> server-apply
```

Promotion used the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing defect, which was not
repaired here. No manual `kubectl patch`, `set image`, `edit`, or Pod
replacement.

Pre-deployment the environment was genuinely without K5A: the API Pod ran
ServiceAccount `utcp-platform-app` and `clusterroles utcp-infrastructure-reader`
did not exist.

## Deployment acceptance — K5A resources are live

```text
API Pod            api-64c65ddf7f-6m4tv   (image sha256:d3b540ca…b1c4)
ServiceAccount     utcp-api               automountServiceAccountToken: true
ClusterRole        utcp-infrastructure-reader
ClusterRoleBinding utcp-infrastructure-reader -> ServiceAccount/utcp-platform/utcp-api
web Pod            web-5f746fbb98-7mzvl   Running
```

Effective RBAC, verified with real authorization checks against
`system:serviceaccount:utcp-platform:utcp-api`:

```text
allowed    get nodes | list nodes | get pods | list pods
denied     create/update/patch/delete nodes, create/delete pods,
           get secrets, create pods/exec, impersonate users, '*' '*'
bindings   only utcp-infrastructure-reader (no cluster-admin)
```

RBAC is exactly the intended read-only surface. **RBAC is not the defect.**

## Kubernetes baseline (authoritative facts)

```text
uid            faa05d1c-35fd-48fa-a2f7-6060d845c9ee
name           utcp-dev01
Ready          True
addresses      Hostname utcp-dev01 | InternalIP 192.168.254.124   (no ExternalIP)
capacity       cpu 8, memory 15800704Ki, pods 110, ephemeral-storage 102626232Ki
allocatable    cpu 8, memory 15800704Ki, pods 110, ephemeral-storage 99834798412
taints         []            unschedulable false
topology       hostname utcp-dev01, os linux, arch amd64 (no region/zone labels)
```

## RuntimeNode correlation baseline

```text
102d58ba-93ec-4601-a2a3-81f95801440f  "V1A Outbound Reproof Asterisk 1787825256"
  active/ready   workload identity  utcp-runtime/asterisk-v1a-outbound-reproof-asterisk-1787-5fced085

7322e6e1-8417-42ce-ad4f-4e7d25b23a3a  draft/unobserved
  workload identity UNRESOLVED (RuntimeNodeWorkloadIdentityException) — correctly has no Pod
```

The matching Pod exists and is placed on the target Node:

```text
namespace  utcp-runtime
pod        asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-66b54spr4g
label      app.kubernetes.io/instance=asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
           app.kubernetes.io/part-of=utcp
nodeName   utcp-dev01
```

So the data required for correlation is present and correct on both sides. The
correlation could not be exercised because the observer never reaches Kubernetes.

## Defect 1 — observer cannot reach the Kubernetes API

```text
KubernetesHostVisibilityService::hosts()
-> KubernetesWorkloadClientException: Kubernetes infrastructure observation is unavailable.
```

Connection material inside the API Pod is complete and correct:

```text
/var/run/secrets/kubernetes.io/serviceaccount/token   readable
/var/run/secrets/kubernetes.io/serviceaccount/ca.crt  readable
service_host 10.43.0.1   service_port 443
```

Connectivity, measured from the Pods themselves:

```text
api pod    -> 10.43.0.1:443          FAIL  errno 111 Connection refused
api pod    -> 192.168.254.124:6443   FAIL  errno 111 Connection refused
fencer pod -> 192.168.254.124:6443   CONNECT_OK
```

### Exact cause

The runtime-fence worker reaches the Kubernetes API through
`allow-runtime-fencer-kubernetes-api`, whose `podSelector` is
`utcp.io/kubernetes-api-client: "true"` and which permits `TCP:6443` to the node
ipBlock. That policy is rendered from
`infrastructure/kubernetes/security/kubernetes-api/allow-runtime-fencer-kubernetes-api.template.yaml`,
where the security apply path substitutes `__KUBERNETES_API_ENDPOINT_CIDR__` and
`__KUBERNETES_API_ENDPOINT_PORT__`.

The K5A commit added an equivalent egress rule using those same placeholders to
`infrastructure/kubernetes/security/platform/allow-api.yaml` — which is **not**
a `.template.yaml` and is applied verbatim by the platform kustomization, so the
placeholders are never substituted. The live policy confirms the rule is absent
entirely:

```text
allow-api-required-traffic  podSelector utcp.io/network-role: api
  egress: UDP/TCP 53 (kube-dns) | TCP 5432 (postgres) | TCP 6379 (redis) | TCP 8080 (reverb)
```

No Kubernetes API egress. The API Pod also does not carry the
`utcp.io/kubernetes-api-client: "true"` label that would bring it under the
existing working policy. With `default-deny` in force, every path to the API
server is closed.

The placeholder convention is used only by `*.template.yaml` files
(`security/kubernetes-api/`, `security/traefik/`, `observability/network-policies/`),
which is why this shape works everywhere else and not here.

## Defect 2 — `platform.infrastructure.view` is never granted

```text
GET /api/v1/admin/infrastructure/hosts   (authenticated platform administrator)
-> HTTP 403 Forbidden
```

`AuthorizationService::platformCapabilities()` resolves capabilities purely from
the database:

```text
platform_role_assignments -> roles -> role_capabilities
```

Live persisted state:

```text
capabilities table                 31 rows, none matching %infrastructure%
role_capabilities (platform-admin) platform.tenants.view, platform.tenants.manage,
                                   platform.users.view, platform.users.manage
config identity.roles.platform-admin  ... + platform.infrastructure.view
config catalog_version             c5.2026-07-15   (unchanged)
```

The identity catalog is seeded into the database by migrations that read
`config('identity.*')` **at migration run time**
(`2026_07_14_110000_create_identity_tenancy_authorization_tables.php::syncCatalog`).
The live database was migrated on 2026-08-26, long before this capability
existed, and that migration will not run again.

The repository already has the correct pattern for this situation — a dedicated
sync migration, e.g. `2026_08_24_131000_sync_c7b_identity_catalog.php`, which
inserted the C7B capabilities and bound them to a role. **The K5A commit shipped
no such migration.**

Automated tests pass because `RefreshDatabase` runs migrations against a fresh
database, so `syncCatalog()` reads the *current* config and does create the row.
The defect is therefore invisible to the suite and appears only on any
pre-existing database — which is every real environment.

## Not performed

No natural Web Admin login, navigation, `/admin/hosts` rendering, read-only
control inspection, Admin API node-fact comparison, workload-placement proof,
RuntimeNode correlation proof, or determinism proof could be captured. All of
them depend on the observer and the authorization grant.

No repair was made to production source, manifests, RBAC, NetworkPolicy, the
database, or the identity catalog. No permanent diagnostic infrastructure was
added. No K5B–K5F behavior was introduced.

## Smallest deterministic corrections

Both are bounded and independent.

**Defect 1** — give the API Pod a rendered Kubernetes API egress path. Either
convert `infrastructure/kubernetes/security/platform/allow-api.yaml` to a
`.template.yaml` rendered by the same security apply path that already
substitutes the endpoint placeholders, or add the existing
`utcp.io/kubernetes-api-client: "true"` label to the API Pod template so the
already-working `allow-runtime-fencer-kubernetes-api` policy covers it. Choosing
between them is an implementation decision; both avoid inventing a new
substitution mechanism.

**Defect 2** — add a dedicated identity-catalog sync migration for
`platform.infrastructure.view` following the
`2026_08_24_131000_sync_c7b_identity_catalog.php` precedent, inserting the
capability and binding it to `platform-admin`.

A focused regression that exercises authorization against a database migrated
*before* the capability existed would have caught defect 2; a manifest test
asserting the API egress policy renders a concrete CIDR/port rather than a
placeholder would have caught defect 1.

## K5A status

```text
K5A: IMPLEMENTED_AND_TESTED — natural live proof BLOCKED by two live defects
```

K5A is not complete. Kubernetes remains the sole host-fact authority, no durable
UTCP Host table exists, and no Kubernetes mutation capability was introduced.

## Boundary

V1 remains complete and untouched. A0 remains eligible/parallel. K5B–K5F were not
implemented, K5A–K5E ordering and the K5F post-K5E classification are unchanged,
and `K5E -> RMA` is unchanged. The `scripts/native-k3s/image-sync` `.git` defect,
the broad `Quality` CI debt, and the runtime deployment-convergence debt remain
unchanged separate items.
