# K5A — Host / Kubernetes Node Visibility Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-30

Starting HEAD: `8c32f8ade311e299b615e45179240ad414b40687`
(`fix(k5): repair host visibility live blockers`)

## Verdict

`K5A_HOST_KUBERNETES_NODE_VISIBILITY_NATURAL_LIVE_PROVEN`

Both previously isolated live defects converged **automatically** through the
normal deployment lifecycle — no manual NetworkPolicy patch, capability insert,
role grant, host sync, or session manipulation. The complete corridor is proven
end to end, and **K5A is complete**.

## Attempt chronology

The first live proof (2026-08-30, HEAD `2bae121…`) deployed K5A successfully but
was blocked before browser acceptance by two independent defects:
`K5A_KUBERNETES_OBSERVER_LIVE_ACCESS_DEFECT` (the API Pod had no rendered
Kubernetes API egress, because the rule used `__KUBERNETES_API_ENDPOINT_*__`
placeholders in a non-`.template.yaml` file) and
`K5A_ADMIN_INFRASTRUCTURE_AUTHORIZATION_LIVE_DEFECT`
(`platform.infrastructure.view` was added to config with no identity-catalog sync
migration, so it was absent from any pre-existing database).

Commit `8c32f8a…` repaired both: the API Deployment now carries
`utcp.io/kubernetes-api-client: "true"` so the existing rendered template policy
covers it, the invalid placeholder rule was removed from
`security/platform/allow-api.yaml`, and
`2026_08_30_100000_sync_k5a_identity_catalog.php` follows the established
`2026_08_24_131000_sync_c7b_identity_catalog.php` precedent. This record proves
their natural convergence.

## Deployment

```text
promoted source commit   8c32f8ade311e299b615e45179240ad414b40687
UTCP_SERVER_API_IMAGE    ghcr.io/grimange/utcp-api@sha256:09f4f232…47ed
lifecycle                server-image-sync -> server-config-check
                         -> server-image-preflight -> server-apply
```

Promotion used the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing debt, which was not
repaired here. No `kubectl patch`, `set image`, `edit`, or manual Pod
replacement.

Pre-deployment both defects were still present and verified: the API Pod carried
no `utcp.io/kubernetes-api-client` label, and the persisted capability catalog
had **0** rows matching `%infrastructure%`.

## Migration convergence — Defect B resolved

The normal deployment migration lifecycle applied the sync migration; no ad hoc
identity command was run.

```text
2026_08_30_100000_sync_k5a_identity_catalog ....... [5] Ran
```

Persisted live state after deployment:

```text
capabilities        key=platform.infrastructure.view  scope=platform
                    description="View observed Kubernetes infrastructure"
role_capabilities   role_key=platform-admin  capability_key=platform.infrastructure.view
capabilities total  31 -> 32
```

## Live API Pod, ServiceAccount and label — Defect A resolved

```text
API Pod          api-6cb796fbb8-q98tx
image            ghcr.io/grimange/utcp-api@sha256:09f4f232…47ed
ServiceAccount   utcp-api
automount        true
label            utcp.io/kubernetes-api-client = true
```

## Live NetworkPolicy rendering

The API Pod is now covered by the rendered Kubernetes API-client policy:

```text
allow-runtime-fencer-kubernetes-api
  selector  utcp.io/kubernetes-api-client: "true"
  egress    TCP:6443 -> ipBlock 192.168.254.124/32

allow-api-required-traffic
  selector  utcp.io/network-role: api
  egress    UDP/TCP 53 | TCP 5432 | TCP 6379 | TCP 8080
```

Endpoint CIDR and port are fully rendered. Cluster-wide count of unresolved
`__KUBERNETES_API_ENDPOINT_` markers in live NetworkPolicies: **0**. Existing
restricted egress is unchanged and no allow-all rule appeared.

## Effective Kubernetes RBAC — regression guard

```text
allowed  get nodes | list nodes | get pods | list pods
denied   create nodes, patch nodes, delete pods, get secrets,
         create pods/exec, impersonate users, '*' '*'
```

Unchanged and still exactly read-only.

## Observer access

```text
api pod -> 192.168.254.124:6443        CONNECT_OK
HttpKubernetesInfrastructureClient     OBSERVER_OK  nodes=1  pods=36
observed node                          utcp-dev01  uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee
```

Proven through the application client, not host `kubectl`.

## Kubernetes baseline (factual authority)

```text
uid            faa05d1c-35fd-48fa-a2f7-6060d845c9ee
name           utcp-dev01
Ready          True
addresses      Hostname utcp-dev01 | InternalIP 192.168.254.124   (no ExternalIP)
capacity       cpu 8, memory 15800704Ki, pods 110, ephemeral-storage 102626232Ki
allocatable    cpu 8, memory 15800704Ki, pods 110, ephemeral-storage 99834798412
taints         []        unschedulable false
labels         8 (control-plane, k3s instance-type, hostname/os/arch)
```

## RuntimeNode correlation

Discovered from live state, not hardcoded:

```text
RuntimeNode        102d58ba-93ec-4601-a2a3-81f95801440f
                   "V1A Outbound Reproof Asterisk 1787825256"  asterisk  active/ready
workload identity  utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085

Pod                asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-655bbjhwdh
namespace          utcp-runtime
labels             app.kubernetes.io/instance=asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
                   app.kubernetes.io/part-of=utcp
spec.nodeName      utcp-dev01
```

The second RuntimeNode (`7322e6e1-…`, draft/unobserved) has no resolvable
workload identity and correctly contributes no association.

The K5A API exposes the same association on the host, with the correlated
workload carrying its `runtime_node_id` and `runtime_node_name`. Correlation used
the canonical namespace + instance-label + `part-of=utcp` mechanism — no
name-substring, IP, or hostname guessing.

## Admin API acceptance

```text
GET /api/v1/admin/infrastructure/hosts   ->   HTTP 200
```

| Fact | Kubernetes | UTCP Admin API |
| --- | --- | --- |
| UID | `faa05d1c-35fd-48fa-a2f7-6060d845c9ee` | `faa05d1c-35fd-48fa-a2f7-6060d845c9ee` |
| Name | `utcp-dev01` | `utcp-dev01` |
| Ready | `True` | `true` |
| Hostname | `utcp-dev01` | `utcp-dev01` |
| InternalIP | `192.168.254.124` | `192.168.254.124` |
| ExternalIP | N/A | N/A (absent) |
| capacity | 6 keys | identical |
| allocatable | 6 keys | identical |
| labels | 8 keys | identical |
| taints | `[]` | `[]` |
| unschedulable | `false` | `false` |
| utcp workloads on node | `23` | `23` |
| RuntimeNode associations | `102d58ba-…` | `102d58ba-…` |

Capacity, allocatable and labels were compared as sorted canonical JSON and are
an **exact match**. No transformation or summarisation was needed.

## Deterministic ordering

Three consecutive calls with no cluster change produced an identical structural
digest over nodes, addresses, conditions, workloads, and runtime_nodes ordering:

```text
call 1  fe366809c5fdaa7f0f61231dda595e8fffbc5768887ba30d0e3a6eaa8e1ec7d3
call 2  fe366809c5fdaa7f0f61231dda595e8fffbc5768887ba30d0e3a6eaa8e1ec7d3
call 3  fe366809c5fdaa7f0f61231dda595e8fffbc5768887ba30d0e3a6eaa8e1ec7d3
```

## Natural Web Admin proof

Playwright MCP, beginning at the real login page. No preset session, injected
cookie, localStorage token, database/Redis-created session, or authentication
bypass was used.

```text
https://app.utcp.local.test/login    real login form (Email, Password, Sign in)
normal form submission               -> https://app.utcp.local.test/dashboard
sidebar navigation                   "Telephony Infrastructure" -> "Hosts"
natural click                        -> https://app.utcp.local.test/admin/hosts
```

The authenticated browser session itself was queried through the application and
holds the capability:

```text
session_user             admin@utcp.local.test
platform.infrastructure.view present
in-browser GET /api/v1/admin/infrastructure/hosts   HTTP 200
```

Rendered Hosts page content:

```text
Hosts — "Status and placement are observed from Kubernetes."
utcp-dev01
Ready
Addresses: Hostname: utcp-dev01, InternalIP: 192.168.254.124
Runtime Nodes: V1A Outbound Reproof Asterisk 1787825256
Workloads: 23
```

Every visible value matches the live Admin API and Kubernetes. No placeholder or
sample data.

## Read-only UI contract

The rendered DOM was enumerated rather than inspected only by eye. Complete
control inventory on `/admin/hosts`:

```text
SELECT  Active tenant        (global chrome)
SELECT  Appearance           (global chrome)
BUTTON  Menu                 (global chrome)
BUTTON  Log out              (global chrome)
A       Dashboard, Hosts, Tenants, Users, System status   (navigation)
BUTTON  Refresh              (read action)
```

```text
forbidden control matches (Add/Create/Edit/Delete Host, Mark Ready, Cordon,
Drain, Uncordon, SSH, Terminal, Join Cluster, remove/update/modify):  0
forms on page:                                                        0
```

K5A remains observation-only.

## Manual reconciliation

```text
Manual host discovery       NOT REQUIRED
Manual host sync            NOT REQUIRED
Manual projection           NOT REQUIRED
Manual host reconciliation  NOT REQUIRED
```

No host-related Artisan command exists in the deployed application, and none was
invoked. The Hosts surface populated purely through the normal application
lifecycle.

## Authority boundary

Kubernetes remains the sole authority for Node existence, UID, readiness,
conditions, addresses, capacity, allocatable, labels, taints, unschedulable
state, Pods, and workload placement. No durable UTCP Host table exists, none was
added, and no Kubernetes mutation capability was introduced. UTCP observes,
normalizes, correlates, and presents.

## K5A classification

```text
K5A: COMPLETE
```

Closure rests on the single canonical Kubernetes Node; multi-host acceptance
belongs to K5E, and neither K5D maintenance nor K5F enrollment is required.

## Boundary

No production source was changed by this proof. V1 remains complete, A0 remains
eligible/parallel, K5B–K5F were not implemented, K5A–K5E ordering and the K5F
post-K5E classification are unchanged, and `K5E -> RMA` is unchanged. The
`scripts/native-k3s/image-sync` `.git` debt, the broad `Quality` CI debt, and the
runtime deployment-convergence debt remain unchanged separate items. No permanent
diagnostic infrastructure was added and transient browser artifacts were removed.
