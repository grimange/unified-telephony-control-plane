# RH-2 — Browser Recovery and Automatic Replacement Conference Leg

## Verdict

    RH_2_BROWSER_NETWORK_AUTO_REJOIN_IMPLEMENTED_AND_TESTED

RH-2 is implemented and repository-tested. Natural browser interruption,
refresh, and replacement-leg proof remains pending; this packet did not perform
live browser proof.

## Scope and authority

The reference dialer now separates unexpected runtime/browser loss from explicit
Leave. Remote SIP termination, browser offline/online transitions, and component
unmount tear down only local signaling resources and enter `Recovering`; they do
not call `DELETE /participants/self`. `desired_state=removed` remains owned by
the existing explicit Leave API path.

Recovery uses the existing authenticated bootstrap response as its sole
discovery authority. When participation is recoverable, the client calls the
normal `participants/self` admission endpoint, verifies that the same
participant is reused, and passes the returned opaque `signaling_destination` to
the existing SIP invite path. It never constructs a destination or persists a
RuntimeNode target.

When RH-1 reports that the prior runtime channel is still authoritative, the
client remains in `Recovering` and re-evaluates canonically. An established
browser dialog suppresses replacement. All recovery triggers share one
single-flight promise and the existing conference-attempt fencing; explicit
Leave invalidates the attempt and ignores late callbacks.

Recovery INVITE failure preserves admitted participation and schedules bounded
canonical rediscovery while RH-1 still reports eligibility. New user-initiated
Join failure retains the existing compensation behavior.

## Focused repository evidence

The frontend tests cover fresh load without participation, recoverable bootstrap,
active-old-channel waiting, same-dialog preservation, unexpected termination,
unmount without participant release, explicit Leave cancellation, repeated
termination single-flight behavior, recovery failure handling, and existing
new-Join compensation. Signaling tests cover established-dialog detection,
reuse of the existing UserAgent/Registerer during re-registration, remote
termination, and the existing credential lifecycle.

## Boundaries preserved

- No backend, schema, RH-1 recovery authority, V0, or RT-1A changes were made.
- No recovery endpoint, recovery token, browser persistence, feature gate, or
  Reverb dependency was introduced.
- No automatic rejoin is claimed live-proven.
- RH-3 slow-network and adversarial hardening remain deferred.

## Phase chain

```text
V0 COMPLETE
→ RT-1A COMPLETE / LIVE PROVEN
→ RH-0 COMPLETE
→ RH-1 IMPLEMENTED / TESTED
→ RH-2 IMPLEMENTED / TESTED
→ RH-2 natural browser proof pending
→ RH-3 not implemented
```
