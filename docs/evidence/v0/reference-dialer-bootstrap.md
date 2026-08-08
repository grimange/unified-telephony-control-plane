# V0 Reference Dialer Bootstrap

## Status

`V0_FIRST_BOUNDED_UNIT_PASSED`

This is the proposed first V0 implementation packet, `V0-REF-DIALER-BOOTSTRAP`.
It does not claim that V0 is complete.

## Contract implemented

`GET /api/v1/reference-dialer/bootstrap` provides the authenticated,
active-tenant application context needed by the reference dialer:

- the fixed application identifier `reference-dialer`;
- the active tenant identifier;
- the current user's active `TelephonySession`, when one exists;
- safe signaling credential and registration metadata, when a session exists;
- tenant-scoped conferences.

The endpoint is read-only. Session creation, signaling credential issuance, and
conference admission continue to use their existing public UTCP endpoints and
canonical domain services. The response never contains the one-time SIP secret.

## Authority boundary

Identity session middleware and the active tenant session remain the
authentication and tenant-context authorities. `TelephonyDomainService` remains
the authority for telephony sessions and conferences. `SignalingCredentialService`
remains the authority for signaling metadata. No frontend, dialer-specific
store, runtime adapter, Kamailio management path, or PBX interface was added.

## Verification

The focused feature test proves unauthenticated denial, tenant-scoped
conference visibility, active-session visibility, signaling metadata visibility,
and absence of the SIP secret. Full repository `make test` and `make check`
remain required after this packet.

## Remaining V0 boundary

The next packet must connect this application context to the existing
session-creation and one-time credential APIs, then prove SIP REGISTER over WSS
through the natural browser login flow. Conference admission and observed
membership remain subsequent V0 acceptance work.
