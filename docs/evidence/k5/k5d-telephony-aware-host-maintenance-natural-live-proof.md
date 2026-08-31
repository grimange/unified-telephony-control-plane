# K5D — Telephony-Aware Host Maintenance Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `7da5de556665bf58b29190e90cb751aeb51a689d`
(`fix(k5): scope host maintenance eviction to runtime workloads`)

Deployed HEAD: `7da5de556665bf58b29190e90cb751aeb51a689d`

## Verdict

`K5D_MAINTENANCE_EVICTION_RBAC_LIVE_DEFECT`

(closest packet classification: `K5D_RUNTIME_WORKLOAD_EVICTION_LIVE_DEFECT` —
the subject Pod eviction can never occur)

**K5D is not closed.** The eviction-scope repair is deployed and verified
correct, but the deployed maintenance ClusterRole grants the Pod eviction
permission in the wrong Kubernetes API group. The maintenance corridor would
cordon the target Node and then be permanently denied at the eviction step.

**No maintenance was requested and no mutation was performed.** The defect was
proven deterministically *before* the §11 pre-mutation gate was passed, so the
environment was never driven into a cordoned/blocked state. Both Nodes remain
uncordoned, `k5d_host_maintenances` holds zero rows, the RuntimeNode remains
`active`/`ready`, and the subject Pod is still Running.

## Deployment

Canonical native-k3s lifecycle only.

```text
lifecycle               server-image-sync -> server-config-check
                        -> server-image-preflight -> server-apply
UTCP_SERVER_API_IMAGE   ghcr.io/grimange/utcp-api@sha256:641324c6…1a53
UTCP_SERVER_WEB_IMAGE   ghcr.io/grimange/utcp-web@sha256:12591f72…0ebd
reconciler image        ghcr.io/grimange/utcp-api@sha256:641324c6…1a53
```

Promotion used the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing debt, which was not
repaired here. `api`, `gateway`, `web`, `worker`, `scheduler` and `reverb` all
rolled out successfully.

## Canonical topology

```text
utcp-dev01  192.168.254.124  control-plane  Ready  schedulable
            uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee
utcp-dev02  192.168.254.125  agent          Ready  schedulable
            uid f56c93f5-52d9-4d18-8dc6-99166a2145f6
```

No topology label, taint, affinity, or storage change was made.

## K5D migrations applied naturally

```text
2026_08_31_120000_create_k5d_host_maintenances     batch 8
2026_08_31_121000_sync_k5d_identity_catalog        batch 8
table k5d_host_maintenances                        exists
rows                                               0
manual SQL required                                NO
```

## Maintenance identity

```text
telephony-reconciler-77c949b4c6-cgcv8
  serviceAccountName  utcp-kubernetes-maintenance
  node                utcp-dev02
  phase               Running
  utcp.io/k5d-maintenance-client   absent (removed by the repair, as documented)
```

The previous pre-repair Pod (`utcp-runtime-fencer`) was still terminating
during the rollout and is not the coordinator.

## Eviction-scope repair — VERIFIED CORRECT

`HttpKubernetesMaintenanceClient::drainablePods($nodeName, $workloadIdentities)`
now requires a positive match against the canonical affected-RuntimeNode
workload identity set supplied by `HostMaintenanceService`, in addition to the
target Node, the UTCP label, and the pre-existing DaemonSet/mirror/terminating
exclusions.

Canonical identities:

```text
102d58ba-93ec-4601-a2a3-81f95801440f  active/ready
  utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
7322e6e1-8417-42ce-ad4f-4e7d25b23a3a  draft/unobserved   labels NULL (no workload)
```

Reproducing the repaired predicate read-only against live Pod observations for
target `utcp-dev01`:

```text
SUBJECT SET (would be evicted) — 1 Pod
  utcp-runtime/asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6ff6786p5v

NOT SUBJECTS on the target host (part-of=utcp, no matching identity)
  utcp-data/postgres-0
  utcp-data/redis-0
  utcp-platform/kamailio-676b88d969-wzjhl
  utcp-platform/kamailio-registration-observer-7c658c45df-j6559
```

| Component | Expected | Result |
| --- | --- | --- |
| affected RuntimeNode Pod | IN SUBJECT | **IN SUBJECT** |
| PostgreSQL | OUT | **OUT** |
| Redis | OUT | **OUT** |
| telephony-reconciler | OUT | **OUT** (on `utcp-dev02`, and no identity match) |
| scheduler | OUT | **OUT** (on `utcp-dev02`) |
| API | OUT | **OUT** (on `utcp-dev02`) |
| Web | OUT | **OUT** (on `utcp-dev02`) |
| Kamailio / rtpengine | OUT | **OUT** |

The previous 22-Pod blast radius is reduced to exactly the one affected
RuntimeNode workload. **The §11 scope gate passes.**

## The blocking defect — eviction denied by API-group mismatch

The deployed ClusterRole:

```text
kubectl get clusterrole utcp-kubernetes-maintenance -o json | jq -c '.rules[]'

{"apiGroups":[""],      "resources":["nodes"],         "verbs":["get","patch"]}
{"apiGroups":[""],      "resources":["pods"],          "verbs":["get","list"]}
{"apiGroups":["policy"],"resources":["pods/eviction"], "verbs":["create"]}
```

`HttpKubernetesMaintenanceClient::evict()` POSTs to:

```text
POST /api/v1/namespaces/{ns}/pods/{name}/eviction
```

That `/api/v1` path places the request in the **core** (`""`) API group, so
Kubernetes authorizes `create pods/eviction` in group `""`. The ClusterRole
grants it in group `policy`, which does not match.

### Live authorization evidence

`kubectl auth can-i`, impersonating the maintenance ServiceAccount:

```text
get nodes                              yes
patch nodes                            yes
get pods (all namespaces)              yes
list pods (all namespaces)             yes
create pods/eviction                   NO
create pods --subresource=eviction     NO
```

SubjectAccessReview, which isolates the group precisely:

```text
group ""      resource pods  subresource eviction   ->  allowed: false
group policy  resource pods  subresource eviction   ->  allowed: true
   reason: RBAC: allowed by ClusterRoleBinding "utcp-kubernetes-maintenance"
```

The `policy` grant is inert — Kubernetes has no `pods` resource in that group:

```text
kubectl auth can-i create pods.policy/eviction
  Warning: the server doesn't have a resource type 'pods' in group 'policy'
kubectl api-resources --api-group=policy
  poddisruptionbudgets   pdb   policy/v1   true   PodDisruptionBudget
```

### Decisive end-to-end probe, with no possibility of mutation

An eviction was attempted against a **nonexistent** Pod name while impersonating
the maintenance ServiceAccount. A 403 proves authorization denial; a 404 would
prove authorization succeeded and only the Pod was absent. Nothing could be
evicted either way.

```text
as utcp-kubernetes-maintenance:
  Error from server (Forbidden): pods "k5d-audit-nonexistent-pod" is forbidden:
  User "system:serviceaccount:utcp-platform:utcp-kubernetes-maintenance"
  cannot create resource "pods/eviction" in API group "" in the namespace "utcp-runtime"

control, as cluster-admin (same path and body):
  Error from server (NotFound): pods "k5d-audit-nonexistent-pod" not found
```

The API server names the mismatch itself: **API group `""`**. The control call
proves the request path and body are correct, so authorization is the only
difference.

Only one binding exists for this ServiceAccount
(`ClusterRoleBinding utcp-kubernetes-maintenance` → `ClusterRole
utcp-kubernetes-maintenance`), so no other grant can compensate.

Note: the `/api/v1` discovery document advertises `pods/eviction` with
`group: policy` — that is the Eviction *object's* `policy/v1` apiVersion, not
the group used for authorization. The SubjectAccessReview and the live 403
settle it; the discovery entry is a known source of exactly this mistake.

## Consequence for the maintenance corridor

`patch nodes` **is** permitted, so the corridor would progress normally through
telephony drain and then **cordon the target Node** — and only then fail:

```text
requested -> draining_telephony -> telephony_drained -> cordoning
  -> cordon utcp-dev01                 SUCCEEDS (patch nodes allowed)
  -> draining_kubernetes
  -> evict subject Pod                 HTTP 403
  -> assertResponse -> forbidden
  -> reconcileDue catch -> fail()
  -> status = blocked, failure_code = forbidden
```

The maintenance can never reach `completed`, the subject Pod is never evicted,
and `utcp-dev01` is left cordoned with no product path to uncordon (automatic
uncordon/reactivation is explicitly outside K5D scope). Requesting maintenance
would therefore have degraded the environment while proving nothing.

This is why the proof stopped at the pre-mutation gate rather than running the
corridor and observing the block. The failure is fully determined by
authorization evidence, and running it would have added a cordoned Node and a
`blocked` maintenance record without adding proof value.

## What was proven and what was not

Natural-live-proven in this packet:

```text
repaired current main deployed through the canonical native-k3s lifecycle
K5D migrations applied naturally in batch 8, no manual SQL
coordinator runs as utcp-kubernetes-maintenance
two-node baseline healthy, both Ready and schedulable
eviction scope repair correct: exactly 1 subject Pod on the target host
PostgreSQL, Redis, Kamailio, and the registration observer excluded on-host
coordinator, scheduler, API, Web outside the subject set
observer RBAC still read-only; utcp-platform-app not widened
```

Not proven — blocked by the defect:

```text
natural Web Admin maintenance request
RuntimeNode drain lifecycle under maintenance
new-work exclusion before Kubernetes mutation
existing-work continuation
cordon-after-drain ordering
subject Pod eviction
non-subject workload survival through a real run
post-eviction reconciliation to completed
audit lifecycle
replacement Pod scheduling to utcp-dev02
```

None of these is claimed. The scope-repair result above is read-only
verification of the deployed predicate, not a live eviction observation.

## Maintenance RBAC summary

```text
ALLOWED (correct)
  get nodes          yes
  patch nodes        yes
  get pods           yes
  list pods          yes

DENIED (correct)
  get/list secrets   no
  patch deployments  no
  delete pods        no
  create pods        no
  delete nodes       no

DENIED (DEFECT — must be allowed)
  create pods/eviction in API group ""    no
```

Observer and shared identities are unchanged:

```text
utcp-kubernetes-observer   patch nodes no, create evictions no, list nodes yes
utcp-platform-app          list nodes no, list pods no, create evictions no
```

## Bounded implementation target

For a separate packet. Not implemented here.

Change the eviction rule in
`infrastructure/kubernetes/base/platform/kubernetes-maintenance-rbac.yaml` from
the `policy` API group to the core group, so it matches the group Kubernetes
actually authorizes for the `/api/v1/.../pods/{name}/eviction` subresource:

```yaml
- apiGroups: [""]
  resources: ["pods/eviction"]
  verbs: ["create"]
```

No other RBAC change is needed; Node get/patch and Pod get/list are already
correct and the denied set is already correct. The eviction-scope repair,
telephony ordering, ServiceAccount separation, NetworkPolicy, storage, and
K5C/K5E behavior all remain unchanged.

Worth adding to that packet: a manifest regression assertion that pins the
eviction rule to the core API group, since the discovery document's
`group: policy` annotation makes this an easy mistake to reintroduce.

## Environment and mutation boundary

No maintenance intent was requested. No Node was cordoned or uncordoned. No Pod
was evicted, deleted, or force-deleted. No PostgreSQL/Redis storage change, no
topology label, taint, affinity, or full-host drain. No production source was
changed. No manual SQL and no Artisan K5D management.

Verified after the audit:

```text
utcp-dev01  unschedulable=false  taints=0  Ready
utcp-dev02  unschedulable=false  taints=0  Ready
k5d_host_maintenances rows                 0
RuntimeNode 102d58ba-…                     active/ready
subject Pod …-6ff6786p5v                   Running on utcp-dev01
```

The only Kubernetes writes attempted anywhere in this packet were the two
eviction probes against a **nonexistent** Pod name, which cannot mutate state:
one was denied (403) and the control was a 404.

## Roadmap impact

```text
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5D   IMPLEMENTED_AND_TESTED
      EVICTION SCOPE REPAIR VERIFIED LIVE
      NATURAL LIVE PROOF BLOCKED — K5D_MAINTENANCE_EVICTION_RBAC_LIVE_DEFECT
K5E   NOT STARTED
```

**Exactly one next action:** bounded implementation correcting the maintenance
eviction ClusterRole API group, after which this controlled natural K5D
acceptance corridor can be re-run unchanged.
