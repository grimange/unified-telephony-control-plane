# RH-2D — Signaling Attempt Authority / Runtime-BYE Recovery Fix

Date: 2026-08-15

## Verdict

`RH_2D_SIGNALING_ATTEMPT_AUTHORITY_RUNTIME_BYE_FIXED_AND_TESTED`

## Bounded correction

The view no longer compares the signaling client's SIP invite attempt ID with
the view's orchestration `conferenceAttempt`. `conferenceAttempt` remains the
fence for bootstrap, admission, polling, and recovery orchestration callbacks.
`ReferenceDialerSignalingClient` remains the sole owner of SIP invite attempt
IDs; one ID is created per actual `invite()` and is used to stamp every session
callback for that dialog. The view records the ID from the `inviting` callback
and fences later defined-ID callbacks against that active signaling ID.

This preserves the live-session callback when non-inviting recovery polling
advances the orchestration generation, while still rejecting callbacks from a
superseded SIP dialog. Existing local-leave terminal callbacks without an ID
remain on the explicit local-control path and are not used as production remote
session identity.

The signaling `invite()` method also returns its signaling-owned attempt ID as a
small explicit contract, without introducing a second counter authority.

## Presentation correction

Successful canonical recovery now restores the outer client state to the
registered/normal presentation at the same time that it marks the conference
connected. This removes the contradictory Connected plus Recovering banner.
Explicit Leave and terminal cleanup clear the active signaling attempt after
the matching local termination path has been processed.

## Tests and boundaries

Focused frontend tests use production-shaped non-undefined attempt IDs for
normal Join and recovery callbacks. They cover normal and recovered-leg remote
BYE, counter drift without a new INVITE, stale superseded callbacks, same-leg
survival, explicit Leave fencing, and removal of the Recovering banner after
canonical binding confirmation. Signaling tests cover one ID per INVITE, ID
reuse across callbacks, and advancement only for a new INVITE.

No backend, RH-1, binder, ARI, readiness, telephony, SIP routing, media, V0,
or RT-1A source was changed. Browser live proof was not performed. The next
step is a narrow natural runtime-BYE proof only.
