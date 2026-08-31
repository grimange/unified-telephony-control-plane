# K5E Prerequisite — Placement Observer Scheduler Mutex Proof-Gap Isolation

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `6de7911fcfa0830da495a393a9ea78d441edd62c`
(`docs(k5): close host maintenance natural live proof`)

Task classification: `NARROW EVIDENCE AUDIT`

## Verdict

`K5E_PLACEMENT_OBSERVER_OVERLAP_MUTEX_LIFETIME_DEFECT`

**Hypothesis proven, by identity rather than correlation.** Every scheduled task
in `routes/console.php` calls `withoutOverlapping()` with no expiry argument, so
each takes Laravel's **1440-minute (24-hour)** default. When a scheduler process
is terminated by a signal mid-task — which happens on every ordinary deployment
rollout — the mutex is never released and the task is suppressed for up to 24
hours. `runtime-engine:k5c-placement-observer` is currently suppressed this way,
which is why UTCP still reports the RuntimeNode on `utcp-dev01` after K5D
naturally moved the workload to `utcp-dev02`.

This is a **UTCP repository configuration defect** (Outcome A). The underlying
framework behaviour is expected and documented; the defect is UTCP applying a
24-hour lock lifetime to minute-cadence tasks with no compensating automatic
recovery, in an architecture that requires recovery to be automatic.

Read-only throughout. No lock was cleared, no task manually invoked, no
placement reconciled, no database projection written, no source changed.

## Evidence chain — each link proven

### 1. Schedule definition

`apps/api/routes/console.php`, exact source:

```php
1007  Schedule::command('runtime-engine:k5c-placement-observer')->everyMinute()->withoutOverlapping();
1013  Schedule::command('telephony-domain:expire-sessions')->everyMinute()->withoutOverlapping();
1018  Schedule::command('telephony-domain:reclaim-orphan-participant-channels --once')->everyMinute()->withoutOverlapping();
```

No expiry argument, no `onOneServer()`, no explicit `name()`, no
`runInBackground()`. All 18 scheduled entries follow the same pattern.

### 2. Framework version and mutex derivation

```text
laravel/framework v12.63.0   (composer.lock, confirmed live: "Laravel Framework 12.63.0")
```

`Illuminate\Console\Scheduling\Event::mutexName()`:

```php
return 'framework'.DIRECTORY_SEPARATOR.'schedule-'.
    sha1($this->expression.$this->normalizeCommand($this->command ?? ''));
```

`ManagesAttributes::withoutOverlapping($expiresAt = 1440)` — the default is
**1440 minutes**. It also installs the suppression filter:

```php
$this->withoutOverlapping = true;
$this->expiresAt = $expiresAt;
return $this->skip(function () {
    return $this->mutex->exists($this);
});
```

`CacheEventMutex::create()` acquires `lock($event->mutexName(), $event->expiresAt * 60)`
→ **86400 seconds** on a lock-capable store. `cache.default = redis`.

### 3. Mutex-key-to-task mapping — derived from the running application

Not guessed and not hashed by hand. A read-only probe was executed inside the
live scheduler Pod that boots the real application, enumerates
`Schedule::events()`, and prints each event's own `mutexName()` together with
`$event->mutex->exists($event)`:

```text
LOCK-EXISTS  framework/schedule-3dabe8304e3b9ec4e5dd913665b531b475f499cc  expr=* * * * *  expires=1440
      '/usr/local/bin/php' 'artisan' runtime-engine:k5c-placement-observer

LOCK-EXISTS  framework/schedule-2f87cf6ee7a087b1075b5c639878f14e640b714f  expr=* * * * *  expires=1440
      '/usr/local/bin/php' 'artisan' telephony-domain:expire-sessions

LOCK-EXISTS  framework/schedule-1e4c4f1822c9a2f9b6a7cfa1e4649e92ce361285  expr=* * * * *  expires=1440
      '/usr/local/bin/php' 'artisan' telephony-domain:reclaim-orphan-participant-channels --once
```

**Every other scheduled event reported `free`.** Three locks, three suppressed
tasks, mapped one-to-one by the framework's own naming function.

### 4. The Redis keys are exactly those three

```text
unified-telephony-control-plane-api-database-unified-telephony-control-plane-api-cache-framework/schedule-3dabe8304e3b9ec4e5dd913665b531b475f499cc
unified-telephony-control-plane-api-database-unified-telephony-control-plane-api-cache-framework/schedule-2f87cf6ee7a087b1075b5c639878f14e640b714f
unified-telephony-control-plane-api-database-unified-telephony-control-plane-api-cache-framework/schedule-1e4c4f1822c9a2f9b6a7cfa1e4649e92ce361285
```

No other `framework/schedule-*` key exists. Each is a `string` holding a Redis
lock owner token (`lKesEjvfR857JC2B`, `eWpA99Vnv6WPrsDu`, `1IOCwBkUg6g8vrmC`).

### 5. Lifetimes, and when each lock was orphaned

TTLs measured at 07:58 UTC, against the 86400 s ceiling:

| Task | TTL | Implied acquisition |
| --- | --- | --- |
| `k5c-placement-observer` | 82232 s | ~06:49 UTC (2026-08-31) |
| `reclaim-orphan-participant-channels` | 85059 s | ~07:36 UTC (2026-08-31) |
| `expire-sessions` | 16476 s | ~12:33 UTC (2026-08-30) |

Scheduler ReplicaSet history shows a rollout immediately before each of the two
recent acquisitions:

```text
scheduler-765c9c48c7  created 2026-08-31T06:48:04Z   -> observer lock ~06:49
scheduler-6f957d86cc  created 2026-08-31T07:35:30Z   -> reclaim lock  ~07:36
```

Both locks were acquired within about a minute of a scheduler Pod replacement —
the window in which the outgoing Pod is still running tasks and is then killed.

The decisive detail: the K5C projection's `observed_at` is **06:49:01**, the
same minute the observer's lock was acquired. The observer's *last successful
run is the very run whose mutex was never released* — it wrote the projection
and was terminated before reaching its release path.

### 6. Why a killed process leaks the lock

`Event::start()` releases on a PHP `Throwable`; `Event::finish()` releases in a
`finally`; `removeMutex()` → `CacheEventMutex::forget()` → `lock(...)->forceRelease()`.

Both paths require the PHP process to keep running. A container/Pod termination
signal kills the process without unwinding, so neither path executes and the
lock survives for its full 24-hour TTL. The framework provides no automatic
recovery; the documented remedy is the operator-run `schedule:clear-cache`.

### 7. Suppression is live and total

Observed over ~3 minutes of natural scheduler ticks — no manual invocation:

```text
runtime-engine:reconciler-scheduled                        4 runs
telephony-domain:retire-closed-bindings --once             4 runs
telephony-domain:failover-coordinator --once               4 runs
telephony-domain:expire-recoverable-participants           4 runs
telephony-domain:ensure-targets                            4 runs
simulator:event-source --once                              4 runs
simulator:ensure-targets                                   4 runs
freeswitch-esl:ensure-targets                              4 runs
asterisk-ari:ensure-targets                                4 runs
runtime-engine:outbox-dispatcher --once                    3 runs
runtime-engine:event-normalizer --once                     3 runs
runtime-engine:command-worker --once                       3 runs
runtime-engine:derive-stale-observations                   1 run   (*/5)
runtime-engine:prune-conference-recovery-metric-events     1 run   (hourly)

runtime-engine:k5c-placement-observer                      0 runs
telephony-domain:expire-sessions                           0 runs
telephony-domain:reclaim-orphan-participant-channels       0 runs
```

The scheduler is healthy and ticking; even the hourly and five-minutely entries
fired. Only the three locked tasks are silent. This rules out *not scheduled*,
*process wiring defect*, *command crash*, *command starts but fails*, and
*scheduler not ticking*: the events are filtered out by
`skip(fn => $this->mutex->exists($this))` before they are ever invoked.

### 8. Placement staleness is the consequence

`runtime-engine:k5c-placement-observer` (`routes/console.php:454`) calls
`K5CPlacementObservationService::refresh()`, whose observable side effect is an
upsert into `runtime_node_k5c_placement_observations` including `observed_at`.

```text
ACTUAL (Kubernetes)
  RuntimeNode   102d58ba-93ec-4601-a2a3-81f95801440f
  workload      utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
  Pod           asterisk-v1a-outbound-reproof-…-748f5hssbv
  Node          utcp-dev02   uid f56c93f5-52d9-4d18-8dc6-99166a2145f6

UTCP OBSERVED
  status        placed
  Node          utcp-dev01   uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee
  observed_at   2026-08-31 06:49:01+00   (frozen; unchanged across the audit)
```

**Placement mismatch still present.** Both projection rows share the same frozen
`observed_at`, consistent with a single last run rather than partial updates.

## Natural recovery not observable in this run

The observer lock has ~22.8 hours remaining. Waiting for natural expiry was not
practical and expiry was **not** manufactured. Automatic recovery was therefore
not observed; the framework code path shows it would occur only when the lock
expires, i.e. up to 24 hours after the orphaning rollout.

## Framework behaviour versus UTCP choice

```text
framework-intended   24h default expiry as a crash safety net;
                     release on completion or PHP exception only;
                     no automatic recovery from signal-killed processes;
                     documented remedy is operator schedule:clear-cache
UTCP choice          withoutOverlapping() with the default on every task,
                     including 18 minute-cadence tasks, with no expiry tuning,
                     no onOneServer(), and no automatic recovery
```

The framework is behaving as designed. The defect is UTCP's configuration: a
**1440× mismatch** between a 60-second cadence and a 24-hour lock lifetime,
combined with an architecture (`AGENTS.md`: normal recovery → automatic
reconciliation; no manual reconciliation for normal operation) that forbids
relying on an operator clearing Redis keys.

Under `docs/AGENTS.md` authority the currently available remedies —
`schedule:clear-cache`, Redis key deletion, manual observer invocation, manual
projection — are all diagnostics or break-glass, not a normal recovery path.
None may be the answer for routine deployments.

## Blast radius beyond placement

The same class of suppression currently affects two other telephony tasks:

```text
telephony-domain:expire-sessions                       suppressed ~19.4h so far
telephony-domain:reclaim-orphan-participant-channels   suppressed ~0.4h so far
```

Any of the 18 tasks can be hit; which ones is decided by whichever happened to be
mid-run when a rollout killed the Pod.

## Bounded implementation target

Smallest change the evidence supports: give the scheduled tasks an explicit
overlap expiry proportionate to their cadence instead of inheriting the 1440-minute
default, so an orphaned lock self-heals in minutes rather than a day —
`withoutOverlapping($minutes)` with a small value for the minute-cadence
entries in `apps/api/routes/console.php`. That preserves genuine overlap
protection while bounding the stale window, and needs no new command, gate,
allowlist, or operator step.

The evidence does **not** establish a need to remove `withoutOverlapping()`,
add custom mutex names, add `onOneServer()`, change the deployment lifecycle, or
redesign the scheduler, so none of those is proposed. A regression assertion
pinning the chosen expiry would guard against silently reverting to the default.

## Boundary

K5D is untouched and remains `COMPLETE / NATURAL-LIVE-PROVEN`. No K5E
acceptance was attempted. No K5D maintenance lifecycle, RBAC, eviction scope, or
reconciliation authority was inspected for change. No production source changed.

Transient environmental note: `utcp-dev02` briefly reported `NodeNotReady` at
~07:59 UTC and returned `NodeReady` at 07:59:48 during the audit. It was not
caused by this read-only audit, it did not alter any finding, and both Nodes are
Ready and schedulable at the end.

## Roadmap impact

```text
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
      (the K5C implementation is not implicated; the observer is simply not invoked)
K5D   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5E   NOT STARTED / BLOCKED ON PLACEMENT-OBSERVER PREREQUISITE
K5F   POST-K5E / UNCHANGED
```

**Exactly one next action:** bounded implementation setting an explicit,
cadence-proportionate `withoutOverlapping()` expiry for the scheduled tasks in
`apps/api/routes/console.php`, after which placement observation recovers
automatically and K5E acceptance can proceed.
