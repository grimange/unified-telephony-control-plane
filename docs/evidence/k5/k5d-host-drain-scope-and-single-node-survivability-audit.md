# K5D — Host Drain Scope and Single-Node Survivability Authority Audit

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `18b1d02e8fc58e9e56bda4c624577b4c960c3599`
(`feat(k5): implement telephony-aware host maintenance`)

Task classification: `NARROW EVIDENCE AUDIT / LIVE-PROOF BLOCKER CLARIFICATION`

## Verdict

`K5D_KUBERNETES_DRAIN_SCOPE_LIVE_PROOF_BLOCKER_FOUND`

The current K5D Kubernetes eviction selector is not scoped to the maintenance
subject. It selects the entire UTCP control plane on the target host —
including the maintenance coordinator itself, the canonical PostgreSQL state
store, and the API and Web surfaces. On the current canonical single-node
native-k3s environment the planned K5D natural live proof would destroy the
platform and could never reach `completed`. **The planned K5D natural live
proof must not be run as currently defined.**

No mutation was performed. No production source was changed. The runtime was
left untouched.

## Scope of this audit

Read-only. The already-settled K5D telephony ordering — maintenance intent →
identify affected RuntimeNodes → exclude new telephony work →
`ACTIVE -> DRAINING -> DRAINED` → only then Kubernetes cordon/eviction — was
not reopened, and neither were Web Admin intent ownership, the active-work
predicate, ServiceAccount separation, or PDB policy.

## Current `drainablePods()` predicate

`apps/api/app/Infrastructure/Kubernetes/HttpKubernetesMaintenanceClient.php`

```text
GET /api/v1/pods?fieldSelector=spec.nodeName=<node>     (all namespaces)

retain  metadata.labels["app.kubernetes.io/part-of"] == "utcp"
skip    any ownerReference of kind DaemonSet
skip    annotation kubernetes.io/config.mirror
skip    metadata.deletionTimestamp != null
skip    status.phase in (Succeeded, Failed)
evict   everything else
```

Two properties matter:

1. **No RuntimeNode association is required.** The maintenance subject —
   the RuntimeNodes affected by the host — is computed separately in
   `HostMaintenanceService::reconcile()` for the telephony drain phase, and that
   result is never used to scope eviction.
2. **Controller ownership is not required.** The only owner test is a negative
   one against `DaemonSet`. A Pod with no `ownerReferences` at all and the
   `part-of: utcp` label would be evicted.

## Documentation versus source

`docs/evidence/k5/k5d-telephony-aware-host-maintenance-implementation.md` states
the client performs "bounded Policy/v1 Pod eviction for **UTCP
controller-managed Pods**".

```text
documentation says controller-managed   YES
source requires controller ownership    NO
agreement                               FAIL
```

The phrase describes an intent the predicate does not enforce. This mismatch is
recorded as fact; the wording alone is not the blocker.

## Unused maintenance-client marker

The K5D commit adds `utcp.io/k5d-maintenance-client: "true"` to the
`telephony-reconciler` Pod template. A repository-wide search finds that label
**declared in exactly one place and referenced nowhere** — not by the drain
filter, not by any NetworkPolicy, not by any selector. It is currently inert and
does not protect the coordinator. (The reconciler's actual API egress comes from
the pre-existing `utcp.io/kubernetes-api-client: "true"` label, which the
NetworkPolicy does consume.)

## Live environment, read-only

```text
kubectl get nodes -o wide
utcp-dev01   Ready   control-plane   v1.36.3+k3s1   192.168.254.124

uid            faa05d1c-35fd-48fa-a2f7-6060d845c9ee
unschedulable  false
taints         0
Ready          True

total Kubernetes Nodes        1
currently schedulable Nodes   1
PodDisruptionBudgets          none, cluster-wide
```

With no PDB anywhere, nothing would throttle or block the eviction sequence.

## Exact eviction set on `utcp-dev01`

The predicate was reproduced read-only against live Pod observations. The
eviction API was never called.

**22 Pods match. 1 of them is RuntimeNode-associated.**

| Namespace | Pod | Component | Owner | part-of | RuntimeNode | Result | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- |
| utcp-data | postgres-0 | postgres | StatefulSet | utcp | no | **EVICT** | matches |
| utcp-data | redis-0 | redis | StatefulSet | utcp | no | **EVICT** | matches |
| utcp-platform | api-99c494766-nx9mn | api | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | web-6df65fb9c4-wvskb | web | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | gateway-6f5cdfc74c-fhqzd | gateway | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | scheduler-59686dd58f-7877m | scheduler | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | telephony-reconciler-6c8ccd5c84-hc897 | telephony-reconciler | ReplicaSet | utcp | no | **EVICT** | matches — *this is the coordinator* |
| utcp-platform | worker-7f4b4ffd76-9bwrt | worker | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | kamailio-676b88d969-wzjhl | kamailio | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | rtpengine-6bb489b54d-bzvww | rtpengine | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | reverb-7cc7bd8f7d-jqzsc | reverb | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | control-plane-outbox-dispatcher-…-s7hhs | outbox-dispatcher | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | telephony-command-worker-…-t5rvt | command-worker | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | telephony-event-normalizer-…-dzp8h | event-normalizer | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | utcp-runtime-fence-worker-…-9s2zf | fence-worker | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | asterisk-ari-events-…-r2jvv | ari-events | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | freeswitch-esl-events-…-hkrrp | esl-events | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | kamailio-registration-observer-…-jmb6b | reg-observer | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | kamailio-registration-observer-…-nkqh2 | reg-observer | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-platform | simulator-event-source-…-dn6k5 | simulator | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-runtime | asterisk-ari-7fb96b6d5c-zlppr | asterisk-ari | ReplicaSet | utcp | no | **EVICT** | matches |
| utcp-runtime | asterisk-v1a-outbound-reproof-…-6cc75544bm | asterisk-ari | ReplicaSet | utcp | **YES** | **EVICT** | matches — *the only maintenance subject* |

Correctly excluded:

```text
kube-system/coredns, local-path-provisioner, metrics-server   part-of absent
kube-system/svclb-traefik-…                                   part-of absent (also DaemonSet)
traefik-system/traefik-…                                      part-of absent
utcp-observability/* (7 Pods)                                 part-of absent / memberlist /
                                                              kube-state-metrics / kube-prometheus-stack
utcp-platform/utcp-migrate-f9wxb                              phase Succeeded
```

RuntimeNode association was derived from canonical state, not guessed: only
RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` carries a managed workload
identity (`utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085`).
RuntimeNode `7322e6e1-…` has `labels = NULL` and no workload.

```text
RuntimeNode Pods in filter                     1
non-RuntimeNode UTCP platform/data Pods        21
```

## Self-eviction check

```text
Would the deployed telephony-reconciler Pod be returned by drainablePods()?
YES
```

Proven field by field against the current predicate:

```text
metadata.labels["app.kubernetes.io/part-of"]   "utcp"          -> retained
ownerReferences                                [ReplicaSet]    -> not DaemonSet, not skipped
annotations["kubernetes.io/config.mirror"]     absent          -> not skipped
metadata.deletionTimestamp                     absent          -> not skipped
status.phase                                   Running         -> not skipped
=> MATCHES
```

The `scheduler` Pod, which also invokes `reconcileDue()` on its every-minute
`runtime-engine:reconciler --once` schedule, matches on the same grounds.

Note on live-versus-HEAD: the currently running cluster is still on `9dd173a`
(pre-K5D), so the live reconciler Pod uses `serviceAccountName:
utcp-runtime-fencer`. At HEAD the rendered manifest sets
`utcp-kubernetes-maintenance`. The K5D commit changes only that
ServiceAccount and two labels on that Pod template and alters no
`part-of` label anywhere, so the 22-Pod eviction set is unchanged after
deploying K5D — the coordinator would then simply also hold cordon and eviction
authority over itself.

## Coordinator survivability on the current topology

`HostMaintenanceService::reconcile()` in `draining_kubernetes`:

```php
$pods = $this->kubernetes->drainablePods($maintenance->node_name);
if ($pods !== []) { foreach ($pods as $pod) $this->kubernetes->evict(...); return; }
$this->transition($maintenance, 'completed', ['completed_at' => now()]);
```

```text
Does completing maintenance require a post-eviction reconcile cycle?
YES — the eviction pass returns; only a later pass that observes zero
drainable Pods can persist `completed`.
```

`reconcileDue()` is called at the top of `ReconciliationWorker::workOnce()`,
which runs in the `telephony-reconciler` Deployment (a ~10s loop) and in the
`scheduler` Deployment (every minute). **Both Pods are in the eviction set.**

The deterministic sequence on this environment:

```text
1. cordon utcp-dev01                 -> spec.unschedulable = true
2. evict 22 UTCP Pods, including telephony-reconciler, scheduler,
   api, web, and postgres-0
3. ReplicaSets/StatefulSets create replacements
4. the only Node in the cluster is unschedulable
   -> every replacement Pod stays Pending indefinitely
5. no reconciler and no scheduler Pod exists
   -> reconcileDue() never runs again
   -> `completed` is never persisted
6. postgres-0 is gone
   -> k5d_host_maintenances cannot be read or written at all
7. api and web are gone
   -> the operator cannot observe or intervene through the canonical surface
```

```text
Can the coordinator reschedule after the target Node is cordoned?   NO
Can K5D maintenance reach `completed` naturally on this topology?   NO
```

Recovery would require an out-of-band `kubectl uncordon`. The K5D
implementation evidence states that automatic uncordon/reactivation is
explicitly outside its scope, so no canonical path restores the environment.

## The blocker is not only single-node

The canonical data services are pinned to this host by storage:

```text
postgres-data-postgres-0   storageClass local-path   Bound
redis-data-redis-0         storageClass local-path   Bound
local-path                 rancher.io/local-path     WaitForFirstConsumer
```

`rancher.io/local-path` volumes are node-local. A replacement `postgres-0`
must land on the node holding the PV — which is precisely the node being
cordoned. **Evicting `postgres-0` therefore strands the canonical state store
regardless of how many other hosts exist.** Adding a second host would let the
stateless control plane reschedule, but would not make the current eviction
scope safe.

This is why the primary verdict is a scope defect rather than a pure topology
limitation: a second host is necessary for a genuine host-evacuation proof, but
it is not sufficient to make the present selector correct.

## Authority

ADR-024's telephony-aware maintenance boundary:

> UTCP first prevents new placement on affected runtimes, moves eligible
> RuntimeNodes into `draining`, and waits for canonical telephony conditions …
> After the RuntimeNodes are drained, **the canonical controller/reconciliation
> authority coordinates the Kubernetes-owned cordon/drain operation and observes
> replacement workloads.**

Two requirements follow directly and are both violated by the current scope:

1. The canonical controller/reconciliation authority must **coordinate** the
   operation. A coordinator that evicts itself mid-operation cannot coordinate
   the remainder of it.
2. That authority must **observe replacement workloads** afterwards. Observation
   is impossible once the coordinator, the API, and the canonical database are
   destroyed.

ADR-024 also requires "observable failures, and idempotent recovery", and the
K5D implementation evidence claims "repeated reconciliation … [is] idempotent".
Repeated reconciliation is unreachable once the reconciler and its state store
are evicted onto a cordoned node.

ADR-024 does authorise a Kubernetes-owned host drain as the step after
telephony drain. What it does **not** settle is whether UTCP's own control
plane and stateful data services are themselves in scope for evacuation during
their own host's maintenance. The two ADR requirements above resolve the
question for the coordinating control plane and its state store: they must
survive. That is not a new option — it is option C, the explicit
maintenance-safe subset, derived from existing authority.

## Root cause

`HttpKubernetesMaintenanceClient::drainablePods()` scopes eviction by the
UTCP ownership label alone. It does not distinguish:

```text
maintenance SUBJECT   telephony RuntimeNode workloads on the host
                      (1 Pod here)

maintenance AUTHORITY the coordinator, API, Web, scheduler, workers
                      and the canonical PostgreSQL/Redis state stores
                      (21 Pods here)
```

The affected-RuntimeNode set is already computed in
`HostMaintenanceService::reconcile()` for the telephony drain phase and is
simply not used to scope the Kubernetes eviction.

## Bounded implementation target

For a separate packet. Not implemented here.

1. Scope `drainablePods()` — or the caller — to the maintenance subject, so
   eviction covers the affected RuntimeNode workloads already resolved during
   the telephony drain phase rather than every `part-of: utcp` Pod on the host.
2. Whatever scope is chosen, guarantee that the coordinating control plane and
   its canonical state stores are never evicted by their own maintenance
   operation, so `draining_kubernetes -> completed` remains reachable and
   auditable. An explicit, tested exclusion is preferable to relying on the
   subject filter alone; the already-declared but currently inert
   `utcp.io/k5d-maintenance-client` label is a candidate marker.
3. Align the implementation evidence wording with the enforced predicate, or
   enforce controller ownership if that was the intent.
4. Leave the settled telephony ordering, ServiceAccount separation, RBAC, and
   PDB policy unchanged.

Broader full-host evacuation, replacement-workload observation across hosts,
non-node-pinned data storage, and automatic uncordon/restoration remain K5E
concerns and are explicitly out of scope for that repair.

## Status impact

The roadmap previously instructed the next operator to "deploy current main to
canonical native k3s and perform controlled K5D telephony-aware host-maintenance
natural acceptance". On this single-node environment that instruction would
destroy the platform and could not complete. The phase ledger and roadmap
next-action are corrected to the bounded repair. K5D remains
`IMPLEMENTED_AND_TESTED`; its natural live proof is now explicitly blocked.

```text
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5D   IMPLEMENTED_AND_TESTED
      NATURAL LIVE PROOF BLOCKED — K5D_KUBERNETES_DRAIN_SCOPE_LIVE_PROOF_BLOCKER_FOUND
K5E   NOT STARTED
```

## Mutation boundary

No maintenance intent was requested. No Node was cordoned or uncordoned. No Pod
was evicted, deleted, or patched. No Node label or taint was changed. No
`server-apply`, no SQL mutation, no source change. All Kubernetes access was
read-only (`get`/`list`) plus `kubectl auth can-i`-style inspection; the
eviction predicate was reproduced locally against observed Pod JSON and the
eviction API was never called.
