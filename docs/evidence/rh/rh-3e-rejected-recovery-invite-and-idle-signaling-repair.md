# RH-3E — Rejected Recovery INVITE Re-entry and Idle Signaling Repair

## Verdict

    RH_3E_REJECTED_RECOVERY_INVITE_AND_IDLE_SIGNALING_REPAIR_FIXED_AND_TESTED

RH-3E is implemented and repository-tested. Natural live reproof is intentionally
pending and is limited to the two RH-3D blockers.

## Scope

This bounded packet changes only the browser recovery/signaling seams. It does not
modify RH-1 participation authority, RH-3B retry/API semantics, RH-3C reconnect
ownership, SIP timers, credentials, backend code, schema, or telephony/runtime
configuration.

## Blocker 1 — Rejected Recovery INVITE

The recovery binding wait is now associated with the signaling-owned invite attempt
ID rather than a boolean alone. The `inviting` callback records the current attempt;
only a terminal `failed` or `terminated` callback for that same attempt can release
its wait. A SIP-established session whose canonical binding is still pending remains
in Recovering and does not produce a second INVITE.

After a current recovery attempt receives a terminal SIP failure, the participant is
preserved, no DELETE is sent, the attempt wait is cleared, and the existing
single-flight recovery coordinator re-enters canonical bootstrap. If the server
still reports the participant recoverable, the existing RH-3B retry ladder schedules
the next admission/INVITE attempt. A stale terminal callback from an older attempt
cannot clear the current attempt's wait.

The retry reuses the normal `participants/self` admission path and therefore the
same canonical participant; it does not create a participant, originate a backend
channel, or add a second retry authority.

## Blocker 2 — Idle Signaling

Real transport loss now reports `connecting` rather than a terminal `failed` state
when the signaling client owns a previously usable transport/registration. The
existing RH-3C signaling client remains responsible for its bounded reconnect and
registration single-flight. The view therefore continues normal signaling repair
when bootstrap has no participation, without calling `participants/self`, sending
an INVITE, or issuing a DELETE.

The fresh-credential allowance is reset at the beginning of a new signaling lifecycle
and on a real transport-disconnect episode. It is not reset on each REGISTER send or
on each successful Registerer callback. A second authentication rejection in the
same episode remains terminal; a later independent episode receives one fresh
credential retry again.

## Focused Evidence

The focused frontend suite covers:

- terminal recovery INVITE failure re-entry and RH-3B retry scheduling;
- no duplicate INVITE while the current SIP attempt is alive/binding-pending;
- stale failure fencing against a newer recovery attempt;
- idle transport loss remaining retryable with no conference work;
- one fresh-credential retry per registration episode;
- terminal second authentication failure within one episode;
- fresh retry allowance after a later independent episode.

The focused command passed with 40 tests across the reference signaling and dialer
view suites. The recovery resilience unit suite passed with 3 tests. The full web
suite passed with 14 files and 198 tests.

## Authority Preservation

PostgreSQL/domain services remain canonical for participation. SIP.js signaling
callbacks remain authoritative for actual invite/session outcome. `conferenceAttempt`
continues to fence browser orchestration; signaling invite attempt IDs fence SIP
callbacks. RH-3B owns API retry timing, and RH-3C owns transport and registration
lifecycle. No backend, database, V0, RT-1A, RH-1, or telephony authority changed.

## Verification Boundary

No natural browser proof was performed in this implementation packet. The next
proof is a narrow live reproof of rejected recovery INVITE re-entry, idle transport
loss/reregistration, and fresh authentication retry allowance across independent
registration episodes. RH-3D's other scenarios are not being rerun here.

