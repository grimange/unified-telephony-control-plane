# V0 Reference Dialer SIP Registration

## Status

`V0_REF_DIALER_SIP_REGISTER_IMPLEMENTED_LIVE_PROOF_PENDING`

This is the proposed bounded packet `V0-REF-DIALER-SIP-REGISTER`. The
bootstrap packet checkpoint is `943c965540c8647803074096e8f451eb5c01225d`.
This packet does not claim conference admission or full V0 completion.

## Contract implemented

The authenticated application now exposes `/dialer` through the existing
application shell and navigation. The view:

1. loads `GET /api/v1/reference-dialer/bootstrap`;
2. reuses an active canonical `TelephonySession`, or creates one through the
   existing idempotent session API;
3. obtains a one-time, session-scoped signaling credential through the existing
   signaling credential API;
4. constructs a narrow SIP.js adapter using the returned canonical WSS URI;
5. reports `REGISTERED` only after SIP.js reports `RegistererState.Registered`.

The UI has loading, bootstrap/session error, connecting, registration failure,
and registered states. It does not select or join a conference.

## Authority boundary

Identity middleware, active tenant context, and existing capability checks remain
the authentication and authorization authorities. The existing telephony
session and signaling credential APIs remain lifecycle authorities. Kamailio
remains the SIP registrar on the existing WSS path. The frontend stores only
the short-lived credential material required to construct the browser SIP
client and does not become a session or credential authority.

## Automated verification

- `npx vitest --run src/signaling/referenceDialerSignaling.test.ts src/views/ReferenceDialerView.test.ts` — 5 tests passed;
- `npm run typecheck` — passed;
- `npm run lint` — passed.

## Live proof state

Natural-login Playwright MCP proof remains pending. The required proof must
start at the real login page, navigate to `/dialer`, perform the real WSS
REGISTER, observe `REGISTERED`, and correlate it with the existing Kamailio
registration observation path. No browser session, credential, or runtime
result is claimed by this repository-only implementation run.

## Remaining V0 boundary

After live registration proof, the next packet is conference selection and
admission through UTCP, followed by normalized runtime execution, observed
conference membership, and the remaining media/vertical-slice acceptance.
