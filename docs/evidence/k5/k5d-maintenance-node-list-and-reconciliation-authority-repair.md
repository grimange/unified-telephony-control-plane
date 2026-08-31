# K5D — Maintenance Node-List and Reconciliation Authority Repair

Current-State-Impact: yes

## Scope

This bounded repair started from `44a0b047c1b33aaa2eaddf6c123c34161374d884`,
`docs(k5): isolate maintenance node-list RBAC live defect`. The live proof had
already established two exact defects: the maintenance identity could not list
Nodes although `HostMaintenanceService::reconcile()` calls `listNodes()`, and
the scheduler's deliberately read-only observer identity also invoked
`reconcileDue()`, allowing it to advance a mutating K5D workflow it could not
finish.

## Repairs

The maintenance ClusterRole now grants the narrow Node authority required by
its reconciliation path: core `nodes` `get`, `list`, and `patch`. No `watch`,
create, update, delete, wildcard, or additional resource authority was added.
The existing core-group `pods/eviction` `create` rule and Pod `get`/`list` rule
were preserved. The observer identity remains read-only and the shared
`utcp-platform-app` identity was not widened.

The existing `ReconciliationWorker` remains the generic reconciliation worker,
but host maintenance is now opt-in at the process-owned call boundary. The
long-running `runtime-engine:reconciler` process used by
`telephony-reconciler` explicitly includes `HostMaintenanceService::reconcileDue()`.
The Laravel scheduler invokes the same worker for its generic scheduled pass
without host maintenance. This leaves `telephony-reconciler` under
`utcp-kubernetes-maintenance` as the sole canonical K5D reconciliation runtime;
the scheduler under `utcp-kubernetes-observer` cannot advance the maintenance
state machine.

No second worker, queue, feature gate, manual switch, cancellation path, audit
damping, storage change, frontend change, K5C change, or K5E behavior was added.
The prior RuntimeNode-scoped eviction selector, telephony drain ordering, PDB
semantics, Node cordon behavior, and Kubernetes API-based eviction remain
unchanged.

## Validation

Focused manifest coverage requires maintenance `nodes` `get/list/patch`, keeps
the core `pods/eviction` rule, rejects the old policy-group representation, and
retains the observer read-only boundary. Focused K5D contract coverage pins the
telephony-reconciler inclusion and scheduler exclusion. The canonical local
Kustomize render shows:

```yaml
- apiGroups: [""]
  resources: [nodes]
  verbs: [get, list, patch]
- apiGroups: [""]
  resources: [pods/eviction]
  verbs: [create]
```

The implementation packet performs no live deployment or K5D natural
acceptance. The next proof must deploy current `main` to canonical native k3s
and resume the controlled K5D natural live proof.

The blocked-maintenance cancellation surface and repeated blocked-state audit
emission remain separate follow-up debt; neither is required to repair the
canonical happy-path reconciliation authority.
