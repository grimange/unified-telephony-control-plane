# K5E — Scheduler Mutex Automatic-Recovery Reproof and Distributed Infrastructure Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `3202451ac019ef5944d43a5cdde4a4e8a545e0a7`
(`fix(scheduler): bound overlap mutex lifetime for minute tasks`)

Deployed HEAD: `3202451ac019ef5944d43a5cdde4a4e8a545e0a7`

## Verdicts

```text
STAGE A   K5E_PLACEMENT_OBSERVER_AUTOMATIC_MUTEX_RECOVERY_LIVE_PROVEN
STAGE B   K5E_DISTRIBUTED_INFRASTRUCTURE_NATURAL_LIVE_PROVEN
```

**K5E is complete.** Both stages passed on real two-host infrastructure. No
production source was changed.

## Deployment

Canonical native-k3s lifecycle only.

```text
lifecycle              server-image-sync -> server-config-check
                       -> server-image-preflight -> server-apply
UTCP_SERVER_API_IMAGE  ghcr.io/grimange/utcp-api@sha256:cc24dd55…4de0
scheduler pod          scheduler-5b964f68f-c7jtd
Laravel                12.63.0
```

Promotion used the established `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` debt, which was not repaired here.

```text
utcp-dev01  192.168.254.124  uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee  Ready
utcp-dev02  192.168.254.125  uid f56c93f5-52d9-4d18-8dc6-99166a2145f6  Ready
```

---

# STAGE A — Scheduler Mutex Automatic Recovery

## A1. Live schedule configuration

Read from the running application's own `Schedule::events()`, not from source:

```text
expires=5   runtime-engine:k5c-placement-observer                        (* * * * *)
expires=5   telephony-domain:expire-sessions                             (* * * * *)
expires=5   telephony-domain:reclaim-orphan-participant-channels --once  (* * * * *)
expires=5   … all 15 minute-cadence overlap-protected events

minute-cadence overlap events still at implicit 1440:  0
```

The only remaining `1440` values are `runtime-engine:derive-stale-observations`
(`*/5`) and `runtime-engine:prune-conference-recovery-metric-events` (`hourly`) —
not minute-cadence, and outside the repair's scope.

## A2. Historical pre-repair locks

Captured before any action, mapped through the application's own `mutexName()`:

```text
schedule-3dabe830…  runtime-engine:k5c-placement-observer                        ttl 78877
schedule-2f87cf6e…  telephony-domain:expire-sessions                             ttl 13120
schedule-1e4c4f18…  telephony-domain:reclaim-orphan-participant-channels --once  ttl 81703
```

All three are **PRE-REPAIR LOCKS**: their Redis keys were created under the old
1440-minute configuration and retain that TTL. Deploying new code does not
retroactively shorten an existing key, so these long TTLs are expected
historical residue and are **not** evidence against the five-minute repair.

## A3. Pre-proof historical mutex cleanup

`PRE-PROOF HISTORICAL MUTEX CLEANUP` — a one-time framework-native test
normalization, performed only after the locks and their mappings were captured
and after confirming safety:

```text
longest scheduled task duration observed over 5 minutes:  909 ms
no legitimately long-running overlap task
waited for a clean window in which only the 3 historical locks remained
  (a transient derive-stale-observations lock was allowed to release naturally)

php artisan schedule:clear-cache        (in the deployed scheduler Pod)
  INFO Deleting mutex for [… telephony-domain:expire-sessions].
  INFO Deleting mutex for [… telephony-domain:reclaim-orphan-participant-channels --once].
locks after: 0
```

This is **not** normal UTCP operation, not automatic-recovery evidence, not a
deployment requirement, and not a K5E acceptance mechanism. No manual Redis
`DEL` was used. No repository code changed.

## A4. Natural resumption after normalization

No manual invocation of any task.

```text
baseline observed_at   2026-08-31 06:49:01+00  (stale, from the leaked run)
t=+40s                 2026-08-31 08:56:03+00  node=utcp-dev02
```

The placement observer resumed on its own and **converged**: observed
`utcp-dev02` / uid `f56c93f5-…` matching the actual Pod placement. The other two
previously stranded tasks also resumed — over three minutes each of
`k5c-placement-observer`, `expire-sessions`, and
`reclaim-orphan-participant-channels` executed twice.

## A5. Controlled framework-native mutex — TTL proof

The real scheduled `Event`'s real `CacheEventMutex` was acquired and
deliberately not released, simulating a process that died holding it. No Redis
key was invented and no other cache primitive was used.

```text
event      : runtime-engine:k5c-placement-observer
expression : * * * * *
expiresAt  : 5 minutes
mutexName  : framework/schedule-3dabe8304e3b9ec4e5dd913665b531b475f499cc
mutexClass : Illuminate\Console\Scheduling\CacheEventMutex
acquired   : true

immediate Redis TTL:  300
```

**TTL is exactly 300 seconds**, not 86400. The repair is live.

## A6. Suppression, natural expiry, automatic resumption

Lock created 08:56:42 UTC. Nothing was cleared; expiry was allowed to happen.

```text
08:56:59  ttl=284  observer_runs=0  other_task_runs=0
08:57:15  ttl=268  observer_runs=0  other_task_runs=2
08:58:18  ttl=205  observer_runs=0  other_task_runs=2
08:59:36  ttl=127  observer_runs=0  other_task_runs=2
09:00:55  ttl=48   observer_runs=0  other_task_runs=0
09:01:26  ttl=17   observer_runs=0  other_task_runs=2
09:01:42  ttl=1    observer_runs=0
09:01:57  ttl=-2   observer_runs=0                     <- key expired naturally
09:02:13  ttl=-2   observer_runs=2   observed_at=09:02:02   <- resumed automatically
```

Three things are proven together: **overlap protection still works** (zero
observer runs for the whole five minutes), **the scheduler stayed healthy**
(other minute tasks kept firing throughout), and **recovery is automatic** (the
observer ran again on the first tick after expiry and rewrote `observed_at`).

Total suppression ≈ 5 min 20 s — the lock lifetime plus one scheduler tick.
Under the previous configuration the same event would have suppressed the
observer for 24 hours.

```text
schedule:clear-cache during the recovery proof   NO
Redis DEL                                        NO
manual observer invocation                       NO
Pod restart to force recovery                    NO
```

**Stage A passes.**

---

# STAGE B — Distributed Infrastructure Live Proof

K5E contract (roadmap §337-343, ADR-024 §142-145): prove UTCP can operate
telephony RuntimeNodes across at least two distinct Kubernetes host/failure
domains, correlating **placement**, **runtime readiness**, **telephony
eligibility**, **new-work exclusion**, **draining**, and **automatic
restoration**.

## B1. Baseline across two hosts

```text
RuntimeNode        102d58ba-93ec-4601-a2a3-81f95801440f
workload identity  utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
actual Pod         …-7bfc989gj5 on utcp-dev02, ready=true
observed           placed on utcp-dev02, uid f56c93f5-…, observed_at 09:02:02
desired/observed   active / ready      cv 30 == ocv 30
capacity           100, priority 100
inbound eligible   available_capacity 100, active work 0

utcp-dev01  Ready, schedulable        utcp-dev02  Ready, schedulable
```

**Placement correlation baseline: actual == observed, with no manual
projection.**

## B2. Scenario — the repository-defined K5D maintenance corridor

Per the packet's preference for the deterministic telephony-aware evacuation
path. One natural bounded Call established active work, then a natural Web Admin
`Prepare for maintenance` was issued on `utcp-dev02` — the host actually
carrying the RuntimeNode. The Hosts surface showed
`Runtime Nodes: V1A Outbound Reproof Asterisk 1787825256` and
`Active telephony work: 1`.

Fresh natural login was used (logged out first, re-authenticated from the real
login page). No injected session state.

## B3. Timeline

```text
09:03:48  host_maintenance.requested            (Web Admin, utcp-dev02)
09:03:49  host_maintenance.draining.telephony
09:04:21  draining, legs=1, dev02 NOT cordoned  <- new-work exclusion exercised here
09:04:32  Call A terminated naturally
09:04:35  legs=0
09:04:47  host_maintenance.telephony.drained
09:04:47  host_maintenance.cordoning            dev02 cordoned
09:04:47  host_maintenance.draining.kubernetes
09:04:53  host_maintenance.completed
09:04:56  replacement Pod …-7bfc99nw5w created on utcp-dev01 (Pending)
09:05:10  UTCP observed placement -> utcp-dev01     <- automatic, no manual step
09:05:46  replacement Pod Ready on utcp-dev01
09:06:24  desired active/ready, inbound eligibility restored on utcp-dev01
09:07:18  Call C on the new host completed (remote)
```

## B4. New-work exclusion

Captured with the RuntimeNode `draining`, `legs=1`, and `utcp-dev02` **not yet
cordoned**:

```text
HTTP 422  "No eligible runtime node is available for outbound call execution."
```

The draining RuntimeNode was not selected. **PASS.**

## B5. Existing work and draining

Call A (`2b32e232-…`) was not terminated by the maintenance request; it ended on
its own at 09:04:32 (`origination_timeout` — a provider-side outcome, distinct
from K5E eligibility, which is what the run exercises). Active work then reached
zero naturally, the RuntimeNode reached `drained`, and cordon followed only
afterwards (09:04:47). **No Kubernetes mutation while active work > 0. PASS.**

## B6. Cross-host movement

```text
old Pod   …-7bfc989gj5  on utcp-dev02
new Pod   …-7bfc99nw5w  on utcp-dev01  uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee
Ready     false (09:05:29) -> true (09:05:46)
```

No forced scheduling, no affinity or nodeSelector added, no manual Pod movement.
Kubernetes rescheduled naturally because `utcp-dev02` was cordoned and
`utcp-dev01` was Ready and schedulable. **PASS.**

## B7. Automatic placement observation after movement

This is the criterion Stage A unblocked, and the reason K5E could not proceed
before:

```text
09:04:56  workload already recreated on utcp-dev01
09:05:10  runtime_node_k5c_placement_observations -> utcp-dev01
                                                     uid faa05d1c-…
```

UTCP followed the cross-host move on its own, within one observer cycle. No
manual observer command, no manual projection, no SQL, no Redis manipulation.
**PASS.**

## B8. Runtime readiness and telephony eligibility on the new host

```text
replacement Pod Ready            09:05:46
RuntimeNode observed_state       ready
inbound eligibility restored     09:06:24, available_capacity 100, work 0
```

Proven functionally, not just by state: a further natural outbound Call
(`6a7ef844-…`) was created and **completed** (`remote`, 09:07:18) with its
CallLeg bound to RuntimeNode `102d58ba-…`, now hosted on `utcp-dev01`. Telephony
genuinely works after the failure-domain change. **PASS.**

## B9. Automatic restoration — precise boundary

```text
AUTOMATIC   workload recreation on the surviving host
AUTOMATIC   Pod readiness
AUTOMATIC   UTCP placement observation following the new host
AUTOMATIC   telephony eligibility once desired state is active
OPERATOR    RuntimeNode desired-state reactivation
```

Infrastructure restoration and observation are fully automatic. Returning the
RuntimeNode from `drained` to `active` is a deliberate operator action: K5D's
accepted scope explicitly excludes automatic uncordon and reactivation, so an
operator confirming a drained host is fit to return is by design, not a gap. It
was performed through the canonical Web Admin **Reactivate** control — no SQL,
no Artisan — after which eligibility returned on its own.

## B10. K5E acceptance criteria

| Criterion | Result |
| --- | --- |
| operates across ≥2 distinct Kubernetes hosts | **PASS** — `utcp-dev02` → `utcp-dev01`, distinct Node UIDs |
| placement correlation | **PASS** — matched at baseline and after the move, automatically |
| runtime readiness | **PASS** — replacement Ready; `observed_state ready` |
| telephony eligibility | **PASS** — restored and exercised by a completed Call |
| new-work exclusion | **PASS** — `HTTP 422` while draining with work active |
| draining | **PASS** — drained naturally; cordon only afterwards |
| automatic restoration | **PASS** — see the boundary in B9 |

## B11. Audit

Six clean lifecycle events, no repetition and no `blocked` noise:

```text
09:03:48 requested   09:03:49 draining.telephony   09:04:47 telephony.drained
09:04:47 cordoning   09:04:47 draining.kubernetes  09:04:53 completed
```

## Environment and boundary

```text
manual placement reconcile        NO
manual SQL projection             NO
manual observer invocation        NO
production source changed         NO
new cluster / third host / cloud  NO
storage redesign                  NO
affinity / nodeSelector added     NO
feature gates or allowlists       NO
K5F implementation                NO
```

Post-proof environment restoration, after all acceptance evidence was captured:
`kubectl uncordon utcp-dev02` — the documented out-of-scope operator recovery,
not part of K5E acceptance.

Final verified state:

```text
utcp-dev01 / utcp-dev02   Ready, schedulable
RuntimeNode               active / ready, capacity 100
actual placement          …-7bfc99nw5w on utcp-dev01, ready=true
observed placement        placed on utcp-dev01 at 09:07:02   (matches)
inbound eligibility       1 eligible candidate
scheduler overlap locks   0
utcp-platform Pods        18/18 Running
```

Proof Calls, CallLegs, maintenance records and audit were retained as canonical
history.

Network context: `utcp-dev01` and `utcp-dev02` are on a mixed Wi-Fi/wired
development LAN. No timing threshold is part of the K5E contract and none was
applied; the proof rests on functional correctness. No `NodeNotReady` event
occurred during this run.

## Roadmap impact

```text
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5D   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5E   COMPLETE / NATURAL-LIVE-PROVEN     proof gap: NONE
K5F   POST-K5E / UNCHANGED
```

Carried debt, unchanged and separate: canonical blocked-maintenance
cancellation, blocked-audit damping, the `scripts/native-k3s/image-sync` `.git`
parsing debt, and runtime deployment-convergence.

**Exactly one next action:** derive from current roadmap authority — with K5E
closed, `K5A -> K5B -> K5C -> K5D -> K5E -> RMA` makes **RMA — Recording & Media
Archive** the next R0-critical track, with K5F remaining a post-K5E operator-experience
item that is not an R0 gate.
