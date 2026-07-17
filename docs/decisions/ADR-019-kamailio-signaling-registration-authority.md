# ADR-019: Kamailio Signaling Registration Authority

## Status

Accepted for the T1 implementation corridor.

## Context

T0 established Asterisk ARI as a runtime-adapter and event-listener boundary beneath the generic C3 engine. T1 must activate browser SIP registration over the shared public `443/TCP` edge without letting Kamailio, `usrloc`, or any new signaling-credential material become a second business authority alongside PostgreSQL/UTCP.

T1 also introduces the first C3 event source that is not backed by a `RuntimeNode` at all: the Kamailio registration observer is shared platform infrastructure, not a tenant-owned managed telephony execution provider. ADR-016 already generalized C3 event-source identity to support this shape; T1 is the first phase that actually exercises it.

T1 must not implement Asterisk signaling, media, ConfBridge, or conference execution; those remain T2/T3.

## Decision

`TelephonySession` (C5) remains the PostgreSQL authority for signaling eligibility. `telephony_signaling_credentials` is the only persisted SIP credential authority. One active `TelephonySession` may issue exactly one active short-lived SIP credential, with a stable session-scoped username in the canonical `sip.utcp.local.test` realm. HA1 is stored as secret-equivalent verifier material; the plaintext SIP secret is returned only once, in the issuance response. The web login password is never reused as a SIP password.

Kamailio is deployed as pinned shared platform infrastructure (not a `RuntimeNode`) and is the sole registrar: actual REGISTER authentication, current Contact binding, replacement, explicit deregistration, and runtime expiration are Kamailio/native-`usrloc` authority. Kamailio uses three least-privilege PostgreSQL roles with disjoint grants: an authentication-view reader (`utcp_kamailio_auth_reader`, read-only against a sanitized `kamailio_signaling_auth_view` exposing only `username`/`domain`/`ha1`), a `usrloc` writer (`utcp_kamailio_usrloc_writer`, native `location`/`version` tables only), and an observer reader (`utcp_kamailio_observer_reader`, read-only against `location`). No role can read or write outside its own grant.

The Kamailio registration observer runs as a fenced C3 event source under the same generic lease, fencing, source-epoch, receipt, and checkpoint authority as RuntimeNode-backed listeners (ADR-016), with no parallel platform-specific tables. It polls `usrloc` snapshots through its own read-only database role, computes a `contact_fingerprint` hash at snapshot time so the raw Contact string is never persisted past that point, diffs against the prior snapshot, and emits normalized `kamailio.registration.{accepted,refreshed,replaced,removed,expired}` receipts. Automatic reconciliation converges desired vs. observed registration state from those receipts; there is no manual Contact, observer, projection, or reconciliation control.

The canonical User & Access Management web-admin UI is the only surface for signaling metadata and one-time credential issuance, nested under a user's active `TelephonySession`. It exposes no permanent SIP account, no user-to-provider-node binding, no PBX assignment, and no credential-recovery control.

Disposable Compose compatibility proof (`make compose-proof`) exercises the same credential/registrar/WSS/observer/projection/reconciliation/expiry corridor in an isolated disposable project, with deterministic cleanup on both success and failure, and never becomes a second canonical authority.

## Consequences

- Controllers and application services do not call Kamailio, `usrloc`, or the registration observer directly; they set desired state only, exactly as C5 already requires for conference operations.
- Redis does not own signaling credentials, registration state, or observer coordination.
- Raw Contact values, SIP messages, `Authorization` headers, HA1, and plaintext SIP secrets must not appear in public registration metadata, audit records, logs, or evidence.
- Generic C3 workers, normalizers, and reconcilers remain runtime-neutral; Kamailio-specific behavior stays inside the observer/normalizer/differ boundary.
- Future Asterisk, FreeSWITCH, rtpengine, and external-trunk phases (T2, T3, T6) must integrate through these same runtime-neutral boundaries instead of changing T1 signaling authority.
- T1 does not add Asterisk signaling, media, or conference execution.
