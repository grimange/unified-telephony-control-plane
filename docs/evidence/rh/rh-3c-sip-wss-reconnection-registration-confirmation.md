# RH-3C — SIP/WSS reconnection and registration confirmation

Status: IMPLEMENTED / TESTED. RH-3D natural browser proof is intentionally pending.

RH-3C is confined to the reference dialer's signaling client and its bounded
view integration. `referenceDialerSignaling` remains the sole owner of the
SIP.js UserAgent, WSS transport, Registerer, registration lifecycle, signaling
credential renewal, and conference invite attempt identity. No backend,
database, telephony, V0, RT-1A, RH-1, or RH-2 authority changed.

## Transport truth

The signaling client now observes both SIP.js transport state changes and
disconnect callbacks. A real disconnect invalidates transport availability and
registration truth even when the client was previously registered;
`isRegistered()` requires both a usable transport and
`RegistererState.Registered`. The application owns one bounded reconnect
single-flight using the existing RH-3 ladder (`1/2/3/5/8/10` seconds, 10-second
cap, ±20% jitter) while `navigator.onLine === false` suppresses reconnect
attempts. A transport reconnect does not itself authorize a recovery INVITE.

## Registration truth

`ensureRegistered()` now shares one in-flight registration operation and resolves
only after the SIP.js Registerer reaches `RegistererState.Registered`.
`Registerer.register()` resolving after the REGISTER is sent is not treated as
success. A disconnect during registration rejects the current operation and
returns ownership to the transport recovery path. Authentication rejection gets
one fresh signaling-credential rebuild and one REGISTER retry; repeated
authentication failure is terminal. Credential TTL and renewal timing remain
unchanged.

The view's normal Join and RH recovery paths both use this same confirmed
registration gate. Recovery can proceed to `participants/self` and the normal
server-returned `conf-*` invite only after canonical bootstrap authority,
usable transport, confirmed registration, and the existing-dialog guard all
permit it. A currently established conference dialog remains authoritative
during transport/API partial failure and is not replaced merely because WSS
reconnects.

## Focused evidence

The signaling tests cover delayed Registerer confirmation, transport
disconnect invalidation and reconnect, one fresh-credential retry, and the
existing invite/session lifecycle. The view now re-enters its existing recovery
single-flight when confirmed registration returns; it does not create a second
recovery coordinator. Existing RH-3B timeout, retry-ladder, offline, debounce,
and HTTP classification behavior remains covered by the web test suite.

Repository verification completed for the affected web package: focused
signaling tests, the full web test suite, lint, typecheck, and production build.
The build emitted only the existing large-chunk warning. Browser/live RH-3D
proof was not performed.

**Next:** RH-3D — adversarial / slow-network natural browser live proof.
