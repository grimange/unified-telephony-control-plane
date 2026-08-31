# K5D — Telephony-Aware Host Maintenance Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `421a1f6d632ed1a23ab91da55e2ef0993ce81ffe`
(`fix(k5): align host maintenance reconciliation authority`)

Deployed HEAD: `421a1f6d632ed1a23ab91da55e2ef0993ce81ffe`

## Verdict

`K5D_TELEPHONY_AWARE_HOST_MAINTENANCE_NATURAL_LIVE_PROVEN`

**K5D is complete.** The full corridor ran end to end on real two-node
infrastructure: a natural Web Admin maintenance request excluded new telephony
work, let existing work finish on its own, drained the RuntimeNode, cordoned the
target Node only afterwards, evicted **only** the affected RuntimeNode workload,
kept the coordinator and every platform and state-store workload alive, and
reached `completed` through a later canonical reconciliation. Remaining K5D
proof gap: **NONE**.

No production source was changed. No manual SQL was used during acceptance.

## Deployment

Canonical native-k3s lifecycle only.

```text
lifecycle               server-image-sync -> server-config-check
                        -> server-image-preflight -> server-apply
UTCP_SERVER_API_IMAGE   ghcr.io/grimange/utcp-api@sha256:e519e493…6c80
UTCP_SERVER_WEB_IMAGE   ghcr.io/grimange/utcp-web@sha256:7337ae5e…65e8
reconciler / scheduler  ghcr.io/grimange/utcp-api@sha256:e519e493…6c80
```

Promotion used the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` debt, which was not repaired here.

## Topology

```text
utcp-dev01  192.168.254.124  control-plane  Ready  schedulable
            uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee
utcp-dev02  192.168.254.125  agent          Ready  schedulable
            uid f56c93f5-52d9-4d18-8dc6-99166a2145f6
```

No topology label, taint, affinity, or storage change was made.

## Preflight gates

### Maintenance RBAC — all three prior repairs confirmed live

```text
live ClusterRole utcp-kubernetes-maintenance
  {"apiGroups":[""],"resources":["nodes"],"verbs":["get","list","patch"]}
  {"apiGroups":[""],"resources":["pods"],"verbs":["get","list"]}
  {"apiGroups":[""],"resources":["pods/eviction"],"verbs":["create"]}

nodes/get     yes      nodes/delete  no
nodes/list    yes      nodes/update  no
nodes/patch   yes      secrets list  no
pods/get      yes
pods/list     yes
core pods/eviction create   yes
```

The harmless nonexistent-Pod probe as the maintenance ServiceAccount returned
`NotFound`, not `Forbidden` — authorization reaches the real endpoint.

Observer remains read-only (`patch nodes` no, `create eviction` no,
`delete pods` no, `list nodes` yes).

### Reconciliation authority — verified in the deployed image

Not inferred from source tests; read out of the running container:

```text
ReconciliationWorker::workOnce($workerId, $batchSize, bool $includeHostMaintenance = false)
  if ($includeHostMaintenance) { app(HostMaintenanceService::class)->reconcileDue(); }

routes/console.php:404   runtime-engine:reconciler   -> includeHostMaintenance: true
routes/console.php:1002  scheduler Schedule::call    -> flag omitted (default false)

telephony-reconciler-55fd6694c7-rbj7h   sa=utcp-kubernetes-maintenance   K5D enabled
scheduler-6f957d86cc-vdvzp              sa=utcp-kubernetes-observer      K5D disabled
```

### Eviction scope — verified read-only before mutation

Target derived from live placement, not assumed: after convergence the
RuntimeNode workload settled on **`utcp-dev01`**, which also hosts PostgreSQL and
Redis — making this a strong scope test.

```text
RuntimeNode   102d58ba-93ec-4601-a2a3-81f95801440f   active/ready   cv 27 == ocv 27
identity      utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
subject Pod   asterisk-v1a-outbound-reproof-…-748f5xkx5j  on utcp-dev01

SUBJECT — 1 Pod
  utcp-runtime/asterisk-v1a-outbound-reproof-…-748f5xkx5j

PROTECTED on the target host
  utcp-data/postgres-0                    utcp-data/redis-0
  utcp-platform/kamailio-…                utcp-platform/kamailio-registration-observer-…
  utcp-platform/utcp-runtime-fence-worker-…  utcp-platform/worker-…
```

## Natural Web Admin request

Fresh natural login — a session existed, so it was **logged out first** and
re-authenticated from the real login page. No cookie, localStorage,
sessionStorage, database or Redis session injection; no bypass. Tenant selected,
natural sidebar navigation to **Hosts**, which rendered:

```text
utcp-dev01  Ready  Runtime Nodes: V1A Outbound Reproof Asterisk 1787825256
                   Workloads: 7    Active telephony work: 1
utcp-dev02  Ready  Runtime Nodes: None   Workloads: 16
```

`Prepare for maintenance` was clicked on the `utcp-dev01` card. Exactly one
intent was persisted for the correct Node UID:

```text
id        d49a2680bf0d60b2f3f6fff8b676856a
node_uid  faa05d1c-35fd-48fa-a2f7-6060d845c9ee   (utcp-dev01)
```

No SQL, no direct API substitution for the human action.

## The corridor, with timestamps

```text
07:37:51  Call A CallLeg created            active telephony work = 1
07:38:13  host_maintenance.requested        (Web Admin)
07:38:17  host_maintenance.draining.telephony
07:38:17  runtime_node.desired_state_changed   -> draining
          [new-work exclusion exercised here, work still 1, Node NOT cordoned]
07:38:37  Call A terminated naturally       termination_reason remote
07:38:49  runtime_node.desired_state_changed   -> drained
07:38:52  host_maintenance.telephony.drained
07:38:52  host_maintenance.cordoning        Node cordoned
07:38:52  host_maintenance.draining.kubernetes
07:39:00  host_maintenance.completed        subject Pod gone
```

### New-work exclusion — the gap from the previous attempt, now closed

Captured in a live poll at the exact moment the RuntimeNode was `draining` with
work still outstanding and the Node still schedulable:

```text
desired_state=draining   active_legs=1   node_unschedulable=false
```

A second natural outbound Call was then attempted through the identical
canonical corridor:

```text
HTTP 422  "No eligible runtime node is available for outbound call execution."
```

The draining RuntimeNode was **not** selected for new work. **PASS.**

### Existing work continued

Call A `3d5e80ad-5337-48cb-9238-8904efb15b34` ran 07:37:51 → 07:38:37 and
terminated on its own with `termination_reason = remote`. It was not terminated
by the maintenance request. **PASS.**

### Ordering — no Kubernetes mutation while work remained

```text
last active CallLeg ended   07:38:37
RuntimeNode DRAINED         07:38:49
Node cordoned               07:38:52
```

Cordon occurred **15 seconds after** the last active work ended and **3 seconds
after** DRAINED. The polling trace independently confirms
`node_unschedulable=false` while `active_legs=1`. **PASS.**

## RuntimeNode-scoped eviction

The subject Pod `…-748f5xkx5j` was removed; Kubernetes recorded graceful
container shutdown (`Killing … Stopping container asterisk`). No force delete
and no `kubectl drain` was used as the product action.

That the removal used the eviction subresource rather than a delete is provable
from authorization alone — the maintenance ServiceAccount **cannot delete Pods**:

```text
delete pods           no
create pods/eviction  yes
```

The client posts a `policy/v1` `Eviction` object to the core
`/api/v1/namespaces/{ns}/pods/{name}/eviction` subresource. With `delete pods`
denied, eviction is the only path by which the Pod could have been removed.

```text
PodDisruptionBudgets   NONE PRESENT (none created for this proof)
```

## Non-subject survival — the decisive scope evidence

Every other `part-of: utcp` Pod on the **target host** survived the operation:

```text
utcp-data/postgres-0                              Running   (started 2026-08-26, not restarted)
utcp-data/redis-0                                 Running   (started 2026-08-26, not restarted)
utcp-platform/kamailio-676b88d969-wzjhl           Running
utcp-platform/kamailio-registration-observer-…    Running
utcp-platform/utcp-runtime-fence-worker-…         Running
utcp-platform/worker-779cc7f968-4fsld             Running
```

PostgreSQL and Redis sat on the drained host and were untouched — the exact
failure mode the original blast-radius audit identified is closed.

The coordinator and scheduler (both on `utcp-dev02`) remained `1/1 Running`
throughout and after:

```text
telephony-reconciler-55fd6694c7-rbj7h   1/1 Running
scheduler-6f957d86cc-vdvzp              1/1 Running
```

## Coordinator ownership and scheduler non-participation

Maintenance advanced on the telephony-reconciler's ~10s loop — note the three
transitions landing together at 07:38:52 in a single pass, and completion at
07:39:00, neither aligned to the scheduler's minute cadence.

The scheduler ran throughout the maintenance window and performed **generic
reconciliation only**:

```text
07:38:02  Running [runtime-engine:reconciler-scheduled]  DONE
07:39:01  Running [runtime-engine:reconciler-scheduled]  DONE
scheduler log lines matching maintenance | cordon | evict :   NONE
```

**The scheduler did not advance K5D. PASS.**

## Audit lifecycle

Complete and clean — exactly six events, no repetition:

```text
07:38:13  host_maintenance.requested
07:38:17  host_maintenance.draining.telephony
07:38:52  host_maintenance.telephony.drained
07:38:52  host_maintenance.cordoning
07:38:52  host_maintenance.draining.kubernetes
07:39:00  host_maintenance.completed
```

Plus `runtime_node.desired_state_changed` at 07:38:17 (→ draining) and 07:38:49
(→ drained). **Zero `host_maintenance.blocked` events** — the previously
observed blocked-audit noise did not recur on the happy path.

## Replacement workload — K5E-supporting evidence only

With the target cordoned and the other Node Ready, Kubernetes rescheduled the
workload naturally. No affinity, nodeSelector, or manual placement was used.

```text
replacement Pod   asterisk-v1a-outbound-reproof-…-748f5hssbv
uid               dcc5a549-3cdb-4297-a804-5ff9d7613b06
node              utcp-dev02
phase / ready     Running / true
```

Classified `K5E-SUPPORTING NATURAL EVIDENCE`. **K5E is NOT complete.**

## Placement observation did not converge — separate, non-K5D issue

The K5C derived placement projection still reports `utcp-dev01`
(`observed_at 06:49:01`) while the workload actually runs on `utcp-dev02`. It
did not update across a four-minute watch.

Root cause is **not** K5D and **not** the K5C observer logic. The scheduler is
simply not executing `runtime-engine:k5c-placement-observer` at all: over a
three-minute sample every other scheduled task ran three times, while exactly
three `everyMinute` tasks never ran —
`runtime-engine:k5c-placement-observer`, `telephony-domain:expire-sessions`, and
`telephony-domain:reclaim-orphan-participant-channels`. Redis holds exactly
three stale `framework/schedule-*` overlap mutexes with multi-hour TTLs
(≈4.8h, ≈23h, ≈23.9h):

```text
framework/schedule-2f87cf6e…   ttl 17291
framework/schedule-1e4c4f18…   ttl 85874
framework/schedule-3dabe830…   ttl 83047
```

Three stuck `withoutOverlapping()` mutexes against exactly three non-running
tasks is the classic signature of locks surviving a scheduler Pod restart. The
correlation is empirical — the lock-to-task mapping was **not** confirmed by
reproducing Laravel's mutex hash, so the mechanism is stated as strongly
indicated rather than proven.

This does not affect any K5D acceptance criterion: §28 classifies placement
observation as supporting evidence, and the K5D corridor is driven entirely by
the telephony-reconciler, which never depends on the scheduler. It is recorded
as **newly observed operational debt** for a separate packet, alongside the
existing blocked-cancellation and blocked-audit-damping debt.

## Post-proof environment restoration

Performed **only after** all acceptance evidence was captured. It manufactures
no acceptance result.

```text
1. canonical    RuntimeNode returned to service via the Web Admin "Reactivate"
                control on Telephony Nodes.  No SQL, no Artisan.
                -> desired_state active / observed_state ready

2. out-of-scope operator recovery
                kubectl uncordon utcp-dev01
                K5D deliberately does not implement automatic uncordon, so this
                is the documented operator recovery action, not part of K5D.
```

The completed maintenance record was left untouched as canonical history.

Final verified state:

```text
utcp-dev01 / utcp-dev02   Ready, schedulable, no taints
RuntimeNode               active / ready
inbound eligibility       available_capacity 100, active work 0
maintenance records       utcp-dev01: completed   utcp-dev02: cancelled (prior packet)
utcp-platform Pods        18/18 Running
postgres-0 / redis-0      Running, never restarted
```

Proof Calls, CallLegs and audit history were retained; nothing was scrubbed.

## Acceptance summary

```text
repaired main deployed                                   PASS
maintenance SA get/list/patch Nodes                      PASS
maintenance SA get/list Pods                             PASS
maintenance SA create core pods/eviction                 PASS
observer remains read-only                               PASS
scheduler no longer advances K5D                         PASS
telephony-reconciler is sole K5D coordinator             PASS
natural browser login                                    PASS
maintenance requested through Web Admin                  PASS
correct RuntimeNode identified                           PASS
new work excluded while draining                         PASS
existing work remained active and ended naturally        PASS
no Kubernetes mutation while active work > 0             PASS
active work reached zero naturally                       PASS
RuntimeNode reached DRAINED                              PASS
Node cordoned only after DRAINED                         PASS
only affected RuntimeNode workload evicted               PASS
Policy/v1 Eviction used; no force delete                 PASS
platform and state-store workloads survived              PASS
telephony-reconciler survived                            PASS
later canonical reconcile reached completed              PASS
no manual normal-operation reconcile                     PASS
no manual SQL used to manufacture acceptance             PASS
no K5E implementation added                              PASS
```

## Roadmap impact

```text
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5D   COMPLETE / NATURAL-LIVE-PROVEN     proof gap: NONE
K5E   NOT STARTED / NEXT
K5F   POST-K5E / UNCHANGED
```

Carried debt, all separate and unchanged in scope: canonical
blocked-maintenance cancellation, blocked-audit damping, the
`scripts/native-k3s/image-sync` `.git` parsing debt, runtime
deployment-convergence, and the newly observed stuck scheduler-mutex issue.

**Exactly one next action:** `K5E — Distributed Infrastructure Live Proof`. The
two-host topology now exists and this run already produced natural
K5E-supporting evidence — an evicted RuntimeNode workload rescheduling to
`utcp-dev02` and reaching Ready.
