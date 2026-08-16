# V0 Reference Client Conference Admission Implementation

## Verdict

`V0_REFERENCE_CLIENT_CONFERENCE_ADMISSION_IMPLEMENTED_AND_TESTED`

This bounded implementation closes the repository blocker found by the
natural registration proof. It does not claim the subsequent natural browser
conference-admission proof.

## Scope and authority

`/dialer` remains a minimal UTCP reference client. It displays only member-
eligible conferences, calls the existing `POST
/api/v1/conferences/{conference}/participants/self` authority, and then uses
the already registered SIP.js `UserAgent` for the browser INVITE. It does not
add conference administration, dialer-product workflows, a placement service,
or a second admission authority.

The frontend projects conferences with `desired_state=open` and a non-null
canonical runtime binding. The backend admission endpoint remains the final
authorization boundary and rejects closed, unauthorized, and cross-tenant
requests according to the existing domain service.

## Admission response

The existing admission response was extended minimally with a sanitized
`signaling_destination` projection. It is derived from the configured UTCP SIP
realm and the existing local reference-conference signaling fixture (`9900`):
`sip:9900@{realm}`. This is control-plane response data, not user input and
not a new conference or placement authority. No credential plaintext is
returned.

## Browser lifecycle

The view orders the action as:

```text
REGISTERED → Join → participants/self → existing UserAgent → Inviter → INVITE
→ Connected → Leave → SIP dialog termination → Ready
```

SIP.js 0.21.2 APIs used are `new Inviter(userAgent, targetURI)`,
`inviter.invite()`, `session.bye()` for an established dialog, and
`inviter.cancel()` while an INVITE is still being established. No second
`UserAgent` is created. Admission failures do not create an INVITE, and INVITE
failures do not present a false Connected state.

## Verification

Focused Vue/SIP.js tests cover joinable filtering, canonical admission ordering,
admission failure, Inviter reuse, connected presentation, and leave teardown.
The existing registration tests remain in the same signaling test suite. The
frontend production build also passes. Natural conference admission remains
pending and was intentionally not performed in this packet.

