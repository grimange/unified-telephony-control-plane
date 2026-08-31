# K5E — Scheduler Overlap Mutex Lifetime Repair

Current-State-Impact: yes

## Scope

This bounded repair started from `b80d9dcab888ea44f2ccb3c050ce63cbe431dd8e`,
`docs(k5): isolate placement observer scheduler mutex defect`. The installed
Laravel framework is v12.63.0. Its implicit `withoutOverlapping()` expiry is
1440 minutes, which is inappropriate for minute-cadence automatic
reconciliation after a scheduler process is terminated before mutex cleanup.

The live proof mapped the application's own scheduled-event mutex names to
three stranded events: `runtime-engine:k5c-placement-observer`,
`telephony-domain:expire-sessions`, and
`telephony-domain:reclaim-orphan-participant-channels --once`. The same
configuration class also affected the other minute-cadence tasks.

## Repair

All 15 current minute-cadence events using overlap protection now declare
`withoutOverlapping(5)`. This includes the placement observer, session expiry,
and orphan participant-channel reclaim tasks. The five-minute expiry retains
overlap protection while bounding recovery from an orphaned mutex to the
cadence-appropriate window.

The two lower-frequency overlap-protected events remain unchanged: the stale
observation task runs every five minutes and the conference recovery metric
pruner runs hourly. No repository evidence required a different expiry for any
minute-cadence task.

No custom mutex, `onOneServer()`, custom mutex name, Redis key cleanup,
`schedule:clear-cache` lifecycle hook, feature gate, scheduler deployment
change, or live Redis mutation was added. The Laravel scheduler remains the
mutex authority.

## Validation and Handoff

The scheduler contract test requires all 15 minute-cadence overlap-protected
events to use explicit five-minute expiry and rejects implicit defaults. It
also pins the three live-proven affected commands individually. The API image
suite and repository checks passed; no frontend, Kubernetes manifest, or
storage files changed.

This packet did not deploy, clear locks, or perform K5E live acceptance. The
remaining proof is `K5E_PLACEMENT_OBSERVER_AUTOMATIC_RECOVERY_LIVE_PROOF_PENDING`:
deploy current `main`, verify bounded scheduler mutex behavior and automatic
placement-observer recovery naturally, then proceed into K5E distributed live
proof if the prerequisite passes.
