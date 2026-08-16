# RH-3 — REGISTER Final-Response Settlement Fix

## Verdict

    RH_3_REGISTRATION_CORRIDOR_STALL_ROOT_CAUSE_ISOLATED

    RH_3_REGISTER_FINAL_RESPONSE_SETTLEMENT_FIXED_AND_TESTED

This bounded repository fix closes the isolated SIP.js 0.21.2 settlement seam.
`registerCurrentRegisterer()` now settles from the concrete REGISTER final
response delegate while retaining Registerer state observation and lifecycle
cancellation. No browser live proof was performed in this packet.

## Correction

`onAccept` resolves the current registration operation, including when the
Registerer is already `Registered` and SIP.js emits no redundant state change.
The accepted response restores application registration truth for the current
Registerer. `onReject` rejects with `RegistrationRejectedError(status)`,
including when the Registerer is already `Unregistered` and emits no state
change. The existing `pendingRegistrationReject` path remains responsible for
transport loss, stop, and superseded lifecycle cancellation.

The existing `registrationPromise` remains the single-flight boundary and is
released by its existing `finally` path. Credential renewal remains unchanged;
it is reached once the accepted registration operation actually settles.

## Regression Coverage

The corrected fake emits `stateChange` only when the simulated Registerer state
actually changes. Focused tests cover:

- accepted REGISTER while already `Registered`, with no state change;
- rejected `401` while already `Unregistered`, with no state change;
- one fresh credential retry and terminal second authentication failure;
- registration promise release on success and terminal rejection;
- transport-loss registration recovery;
- two consecutive fake-timer credential renewal cycles without stranded
  `renewalInFlight` or duplicate renewal scheduling.

Existing RH-3C, RH-3E, and RH-3F coverage remains in place. SIP Timer F, the
credential TTL, the one-auth-retry-per-episode contract, RH-1 grace, retry
cadence, reconnect timing, backend, schema, and telephony infrastructure were
not changed.

## Verification

    npm_config_cache=/tmp/utcp-npm-cache npm run test -- src/signaling/referenceDialerSignaling.test.ts

Result: 1 test file passed, 23 tests passed.

Browser live proof: not performed. The next step is one narrow natural-browser
reproof covering two accepted credential renewal cycles and one bounded
runtime/media-loss recovery corridor.
