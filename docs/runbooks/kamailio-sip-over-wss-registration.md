# T1 Kamailio SIP-over-WSS Registration Runbook

Status: registrar runtime foundation proven; fenced observer/projection
implementation exists with focused tests; repository User & Access Management
signaling UI exists; disposable Compose compatibility proof covers the signaling
corridor; natural browser acceptance and full T1 acceptance pending.

## Current Implemented Authority

UTCP now issues a short-lived SIP registration credential from the normal authenticated TelephonySession API:

```bash
POST /api/v1/telephony/sessions/{telephonySession}/signaling-credential
GET  /api/v1/telephony/sessions/{telephonySession}/signaling-credential
```

The POST response returns the generated SIP secret exactly once with safe metadata:

- `username`
- `realm`
- `algorithm`
- `sip_secret`
- `wss_uri`
- `issued_at`
- `expires_at`

The GET response is metadata-only and never returns `sip_secret` or HA1.

## Authority Rules

- One active TelephonySession owns one active signaling identity and credential.
- Username is derived from the TelephonySession identifier.
- Realm is `sip.utcp.local.test`.
- The web password is never used as the SIP password.
- HA1 is persisted as credential-equivalent secret material and is never returned by API.
- Repeated issuance revokes the prior active credential in the same transaction.
- TelephonySession end or expiry revokes active signaling credentials and sets desired registration state to `removed`.
- Controllers do not write observed Contact state.

## Registrar Foundation

The T1-B foundation deploys a pinned Kamailio registrar as shared platform infrastructure, not as a `RuntimeNode`. It uses:

- a trusted WSS route on `sip.utcp.local.test`
- an authenticated `sip` WebSocket subprotocol path
- PostgreSQL `auth_db` against a filtered metadata view
- precomputed MD5 HA1 with `calculate_ha1=0`
- native write-through `usrloc` persistence
- one active Contact per signaling identity
- maximum Contact lifetime of 120 seconds
- explicit deregistration with `Expires: 0`
- separate least-privilege database roles for authentication, usrloc writes, and future observer reads

The foundation proof covers successful REGISTER, refresh, replacement, explicit deregistration, session-end credential revocation, bounded runtime expiration, wrong-password rejection, unsupported digest-algorithm rejection, Kamailio restart recovery, NetworkPolicy boundaries, and safe aggregate status.

## Observer and Projection Implementation

The T1-C repository implementation adds a `kamailio-registration-observer`
process role. It uses the canonical Kamailio event source
(`source_kind=kamailio-registration`, `source_key=local-shared-registrar`) and
the shared C3 lease, fencing, epoch, receipt, and checkpoint tables.

The observer reads Kamailio's `location` table through the dedicated observer
database credentials when running against PostgreSQL. It snapshots only bounded
metadata:

- signaling identity
- internal `ruid`
- expiration time
- last modified time
- one-way Contact fingerprint

Raw Contact URIs, SIP messages, Authorization headers, nonces, cookies, HA1,
and plaintext SIP secrets are not persisted in C3 receipts or public metadata.

Snapshot differences produce bounded internal receipt types:

- `kamailio.registration.accepted`
- `kamailio.registration.refreshed`
- `kamailio.registration.replaced`
- `kamailio.registration.removed`
- `kamailio.registration.expired`

The Kamailio registration normalizer resolves the signaling identity back to
the canonical TelephonySession and projects observed registration state through
the existing C3 projection authority. Application services may update desired
registration state and credential lifecycle only; they do not write live
observed Contact state.

Registration reconciliation is automatic and does not generate REGISTER, mutate
Kamailio `usrloc`, or provide manual retry/projection controls.

## Management UI Surface

The signaling surface lives inside the canonical administration hierarchy:

```text
Administration
└── Users
    └── User detail
        └── Active TelephonySession
            └── Signaling registration
```

The user detail page may display account metadata, tenant memberships, roles,
effective capabilities, the active TelephonySession, safe signaling credential
metadata, desired registration state, observed registration state, and
reconciliation status.

One-time credential issuance is available only through the canonical
authenticated API and the generated SIP secret is shown only in the issuance
result. Metadata refresh cannot recover the secret. Reissuing a credential
revokes the prior active signaling credential through the backend service.

The UI must not create permanent SIP accounts, bind users to provider nodes or
PBX servers, display HA1 or raw Contact values, recover a current credential,
or expose manual registrar, Contact, observer, projection, or reconciliation
controls.

## Disposable Compose Compatibility

`make compose-proof` is a disposable compatibility proof, not a second
management plane or canonical local runtime. Kubernetes remains the canonical
integrated local runtime.

The proof creates an isolated Compose project, generates disposable database
credentials and local TLS material under the ignored runtime directory, starts
the existing application image, the pinned Kamailio registrar, the disposable
WSS gateway, and the existing `kamailio-registration-observer` process role,
then exercises the canonical API and SIP-over-WSS proof harness.

The disposable corridor proves credential issuance, metadata readback without
secret recovery, trusted WSS with the `sip` subprotocol, digest REGISTER,
refresh, replacement, explicit deregistration, wrong-password rejection,
observer projection, reconciliation convergence, session-end credential
revocation, bounded Contact expiration, safe aggregate status, and cleanup of
the project, containers, networks, volumes, and generated secrets.

Compose does not add Compose-specific signaling services, event models,
projection paths, management endpoints, manual registrar controls, manual
observer controls, manual reconciliation controls, or persistent authority.

## Pending Runtime Work

T1 is not complete until live proof establishes:

- observer Deployment rollout and live source claim
- projection of accepted, refreshed, replaced, removed, and expired Contacts
- session-end pending-removal and bounded-expiry convergence
- observer Pod takeover and Kamailio restart behavior
- observer NetworkPolicies and metrics
- natural browser acceptance of the user, TelephonySession, and signaling UI
- full K0-K4, C0-C5, and T0 regression

Do not advance `UTCP_PHASE` from `T0` until those proofs pass.
