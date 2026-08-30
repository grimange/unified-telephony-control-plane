# K5B — Telephony Placement Awareness Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-30

Starting HEAD: `1b1c1bf0eca2638438287cc82f4edf3491ae2a8c`
(`feat(k5): add telephony placement awareness`)

## Verdict

`K5B_TELEPHONY_PLACEMENT_AWARENESS_NATURAL_LIVE_PROVEN`

The full placement-awareness corridor is proven end to end on real
infrastructure — RuntimeNode → canonical workload identity → real Pod →
`spec.nodeName` → real Kubernetes Node → read-only placement API → natural
authenticated Web Admin. **K5B is complete.**

## Deployment

```text
promoted source commit   1b1c1bf0eca2638438287cc82f4edf3491ae2a8c
UTCP_SERVER_API_IMAGE    ghcr.io/grimange/utcp-api@sha256:550bd9fd…2a1f
lifecycle                server-image-sync -> server-config-check
                         -> server-image-preflight -> server-apply
```

The `Native k3s Images` workflow was still in progress at packet start; it was
awaited to `completed / success` rather than worked around. Promotion used the
established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing debt, which was not
repaired here. No manual patching of routes, placement data, Pods, Node labels,
database rows, or browser state.

Post-rollout: `api-54ccbbd6d-drlrd` and `web-6f85f95949-nll5g` Ready, all
`utcp-platform` Pods Ready, and the placement route live in the running API:

```text
GET|HEAD  api/v1/admin/runtime-nodes/{runtimeNode}/placement
```

## Naturally discovered RuntimeNodes

Discovered from live canonical state, not hardcoded:

```text
102d58ba-93ec-4601-a2a3-81f95801440f  "V1A Outbound Reproof Asterisk 1787825256"
  tenant local (342ee3b1-…)  asterisk / asterisk-ari  active / ready
  workload identity  utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085

7322e6e1-8417-42ce-ad4f-4e7d25b23a3a  "V1A Outbound Proof Asterisk 1787821584"
  tenant local  asterisk  draft / unobserved  workload identity NO_IDENTITY
```

The first is the placement subject; the second serves as a **natural** positive
control for `no_managed_kubernetes_identity` — it was not created or mutated for
this proof.

## Kubernetes placement baseline

Correlated only through the canonical mechanism — namespace +
`app.kubernetes.io/instance` + `app.kubernetes.io/part-of=utcp` — never by
Pod-name substring, hostname, IP, display name, or hardcoded ID.

```text
namespace   utcp-runtime
instance    asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
part-of     utcp
pod         asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-7cf9cccrcj
spec.nodeName  utcp-dev01
phase       Running
```

At first capture a second replica
(`…-655bbjhwdh`, uid `461ab855-…`) was still present from the deployment
rollout. **Both replicas were on `utcp-dev01`**, so exactly one distinct Node was
observed and `placed` remained the correct status; no arbitrary Pod selection was
made, and no Pod-age, first/newest/oldest, or time-threshold heuristic was used.
By the time of the API call the rollout had converged to a single Pod, and the
API's Pod list matched Kubernetes exactly at that moment.

Node baseline:

```text
uid            faa05d1c-35fd-48fa-a2f7-6060d845c9ee
name           utcp-dev01
Ready          True
addresses      Hostname utcp-dev01 | InternalIP 192.168.254.124
region label   ABSENT
zone label     ABSENT
hostname label utcp-dev01
taints         []        unschedulable false
```

## Placement API

```text
GET /api/v1/admin/runtime-nodes/102d58ba-…/placement   ->   HTTP 200
status: placed
```

Authorization is tenant-scoped `runtime.nodes.view`; the endpoint resolves the
RuntimeNode within the caller's tenant.

| Fact | Kubernetes | K5B placement API |
| --- | --- | --- |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` | same |
| Namespace | `utcp-runtime` | `utcp-runtime` |
| Deployment | `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085` | same |
| Pod | `…-7cf9cccrcj` | `…-7cf9cccrcj` (`node_name utcp-dev01`, `Running`) |
| Node UID | `faa05d1c-35fd-48fa-a2f7-6060d845c9ee` | same |
| Node name | `utcp-dev01` | `utcp-dev01` |
| Ready | `True` | `true` |
| Hostname address | `utcp-dev01` | `utcp-dev01` |
| InternalIP | `192.168.254.124` | `192.168.254.124` |
| Region | absent | absent from `topology` |
| Zone | absent | absent from `topology` |
| `kubernetes.io/hostname` | `utcp-dev01` | `topology["kubernetes.io/hostname"] = utcp-dev01` |
| taints / unschedulable | `[]` / `false` | `[]` / `false` |

Topology is exposed as a `topology` map containing only the three recognised
labels that actually exist; region and zone keys are simply absent rather than
null-filled or synthesised from hostname or IP.

## Natural status controls

```text
102d58ba-…  (identity + one observed Node)   -> placed
7322e6e1-…  (no managed workload identity)   -> no_managed_kubernetes_identity
            kubernetes_node null, workload null, co_resident []
```

`identity_present_but_not_currently_observed`,
`ambiguous_multiple_nodes_observed`, and `kubernetes_observation_unavailable`
were **not** manufactured — no Kubernetes manipulation, no RuntimeNode mutation,
no NetworkPolicy or RBAC breakage. They remain covered by the repository's
automated tests.

## K5A regression agreement

```text
K5A  /api/v1/admin/infrastructure/hosts
     host utcp-dev01  uid faa05d1c-…  ready true  runtime_nodes [102d58ba-…]

K5B  placement kubernetes_node
     uid faa05d1c-…  name utcp-dev01

host identity agreement: PASS
```

Both surfaces derive from the same Kubernetes authority and report identical
host identity and the same RuntimeNode relationship.

## Co-resident RuntimeNodes

```text
co_resident_runtime_nodes: [ 102d58ba-… "V1A Outbound Reproof Asterisk 1787825256" ]
```

Only one tenant-authorized RuntimeNode currently has a managed workload on this
Node, so the list correctly contains just that node. No additional RuntimeNode
was manufactured to demonstrate co-residency.

## Deterministic ordering

Three consecutive calls with no runtime or cluster change produced an identical
normalized digest across status, node name, addresses, conditions, pods, and
co-resident entries:

```text
call 1  88f3c3feb1a8e31b5d9f1bdac88403c5e7ca561e70cc5063c405896105c2808f
call 2  88f3c3feb1a8e31b5d9f1bdac88403c5e7ca561e70cc5063c405896105c2808f
call 3  88f3c3feb1a8e31b5d9f1bdac88403c5e7ca561e70cc5063c405896105c2808f
```

## Natural Web Admin proof

Playwright MCP. The browser still held a session from the earlier K5A proof, so
it was **logged out first** and a fresh login performed from the real login page
inside this packet. No preset session, injected cookie, localStorage token,
database/Redis-created session, or authentication bypass was used.

```text
https://app.utcp.local.test/login    real form (Email, Password, Sign in)
normal submission                    -> /dashboard
active tenant selected               Local Tenant  (tenant-scoped surface)
sidebar navigation                   "Telephony Infrastructure" -> "Telephony Nodes"
natural click                        -> /admin/runtime-nodes
Details on the target node           expanded its detail panel
```

Rendered placement section:

```text
Placement and infrastructure
Current host placement is observed from Kubernetes and is read-only.

HOST                      utcp-dev01
HOST STATUS               Ready
ZONE                      Not reported
REGION                    Not reported
CO-RESIDENT RUNTIMENODES  V1A Outbound Reproof Asterisk 1787825256
```

The authenticated browser session's own request confirmed agreement from inside
the application:

```text
in-browser GET .../placement   HTTP 200
status placed, node utcp-dev01, uid faa05d1c-…, ready true,
topology {kubernetes.io/hostname: utcp-dev01},
co-resident ["V1A Outbound Reproof Asterisk 1787825256"]
```

Every visible value agrees with Kubernetes, the K5A host surface, and the K5B
API.

## Missing topology handling

Region and zone labels genuinely do not exist on this native-k3s Node. The UI
renders an explicit `Not reported` for each rather than an error, a blank, or a
value synthesised from hostname or IP. This is correct behaviour, not a failure.

## Read-only placement contract

The placement section's DOM was enumerated rather than judged visually:

```text
controls inside the placement section:  0
forms inside the placement section:     0
page-wide matches for Move Runtime / Assign Host / Change Host / Change Node /
  Pin to Node / Prefer Zone / Avoid Host / Rebalance / Reschedule / Set Node /
  Select Host / Migrate:                0
```

The page does carry pre-existing RuntimeNode **lifecycle** controls (Add
Telephony Node, Activate, Disable, Drain, Details, Refresh). Those belong to the
established RuntimeNode registry/lifecycle surface and to K5D's future
maintenance scope — none of them mutates placement, and none appears inside the
placement section. K5B remains awareness only.

## Management authority compliance

```text
Manual placement sync              NO
Manual placement refresh command   NO
Manual host assignment             NO
Artisan placement management       NO
```

No placement-related Artisan command exists in the deployed application, and none
was invoked. Placement awareness arose purely from normal observation.

## No Kubernetes mutation

No Pod was patched, no `nodeName` set, no `nodeSelector`, affinity,
anti-affinity, or topology-spread added, and no cordon, drain, eviction, or Node
label change was performed. Verified after the proof:

```text
node utcp-dev01   taints (none)   unschedulable (none)
pod  …-7cf9cccrcj nodeName utcp-dev01  nodeSelector none  affinity none
```

## Authority boundary

Kubernetes remains authoritative for Node identity, Pod placement,
`spec.nodeName`, readiness, addresses, capacity, allocatable, topology labels,
taints, and schedulability. UTCP retains RuntimeNode identity, lifecycle, tenant
ownership, and managed workload identity. K5B only derives and displays the
observed relationship; no durable placement authority was created and none was
mutated.

## K5B classification

```text
K5B: COMPLETE
```

Closure does not depend on a second Kubernetes host, region/zone labels, more
than one placed RuntimeNode, or a naturally reproduced ambiguous rollout. No K5C
policy — scoring, capacity thresholds, zone preference, failure-domain
avoidance, admission control, co-resident exclusion, or scheduler mutation — was
introduced or exercised.

## Boundary

No production source was changed by this proof. V1 and K5A remain complete, A0
remains eligible/parallel, K5C–K5F were not started, `K5A -> K5B -> K5C -> K5D
-> K5E` ordering is unchanged, and `K5E -> RMA` is unchanged. The
`scripts/native-k3s/image-sync` `.git` debt, the broad `Quality` CI debt, and the
runtime deployment-convergence debt remain unchanged separate items; no Pod-age
heuristic was introduced. No permanent diagnostic infrastructure was added and
transient browser artifacts were removed.
