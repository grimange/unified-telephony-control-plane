# V0 Reference Client Credential Renewal and Recovery Fix

## Verdict

`V0_CREDENTIAL_RENEWAL_AND_REFERENCE_CLIENT_RECOVERY_IMPLEMENTED_AND_TESTED`

This bounded packet addresses PRODUCT_DEFECT-32, PRODUCT_DEFECT-33, and
OBSERVATION-3. Narrow natural credential/retry reproof remains pending.

## PRODUCT_DEFECT-32 — Credential renewal loop

The one-time signaling-credential issuance response now serializes `issued_at`
and `expires_at` as explicit UTC ISO-8601 values through Carbon's canonical
`toISOString()` representation, for example
`2026-08-14T20:32:31.000000Z`. This removes the browser-local-time ambiguity
that caused the renewal timer to interpret the 120-second credential as already
expired.

The reference signaling scheduler validates the expiry and requires a future
renewal target beyond its 30-second safety window. Invalid, expired, or
too-close values fail the signaling lifecycle closed instead of scheduling a
zero-delay loop. A replacement credential must have a later expiry than the
previous credential; a non-advancing replacement produces one controlled
failure and no further churn. Healthy renewal updates the existing SIP.js
UserAgent authentication and re-registers the existing Registerer.

The signaling credential service remains the sole credential authority. No TTL,
realm, secret-storage, or authentication-model workaround was introduced.

## PRODUCT_DEFECT-33 — Retry after failed INVITE

The existing compensated failed-establishment path remains visible as
`Needs attention`, but the Join action is available from that state. A new Join
clears the prior error and creates a fresh attempt identity. Late callbacks from
the failed attempt cannot mutate the retried attempt.

## OBSERVATION-3 — Already-released participant

The existing participant-release API intentionally returns a typed 404 when the
participant is already absent. The frontend API client normalizes only that
specific `ApiRequestError` status to converged cleanup. Authorization failures,
server failures, tenant errors, and other unexpected responses remain errors.
No backend reconciliation or participant-operation redesign was introduced.

## Shared boundaries

Single-flight conference cleanup remains shared by local Leave, remote SIP
termination, failed establishment, and navigation teardown. The canonical
participant endpoint remains the lifecycle write authority. OBSERVATION-2 is
deferred and unchanged.

PROOF_GAP-1 remains unresolved by design: control-plane telephony-session end
does not produce a browser SIP BYE and is reserved for the V0-A lifecycle
authority audit. No polling, Reverb, hangup endpoint, or routing workaround was
introduced.

RT-1 remains Planned and not implemented. No Reverb, broadcast, Echo, or Admin
WebSocket code was added.

## Verification boundary

Focused backend and frontend tests cover timestamp serialization, bounded
renewal, same-client renewal, non-advancing expiry, failed-attempt recovery,
stale-attempt protection, already-absent cleanup, unexpected cleanup errors,
and the existing registration/conference lifecycle. Browser proof was not
performed. The next step is narrow natural credential/retry reproof followed by
the V0-A lifecycle authority audit.
