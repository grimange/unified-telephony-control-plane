# V0 Reference Client Call-Lifecycle Convergence Implementation

## Verdict

`V0_REFERENCE_CLIENT_CALL_LIFECYCLE_CONVERGENCE_IMPLEMENTED_AND_TESTED`

This bounded frontend packet addresses the three reference-client convergence
findings from the natural conference-admission proof. Narrow natural lifecycle
reproof remains pending.

## Defect 30 — Remote termination

`SessionState.Terminated` is terminal regardless of whether the local Leave
action initiated it. Established remote termination now invokes the same
conference cleanup seam as local Leave, releases the admitted participant
through the canonical `DELETE /api/v1/conferences/{conference}/participants/self`
endpoint, clears the active conference, and returns the view to Ready.

The view keeps one cleanup promise per conference attempt. Repeated terminal
callbacks and overlapping Leave/navigation teardown therefore do not
intentionally issue duplicate logical participant-release requests.

## Defect 31 — Failed INVITE

SIP.js 0.21.2 terminal session transitions are handled explicitly. A session
that reaches `Terminated` before `Established` is reported as a failed
establishment, not as a connected call. Because admission precedes INVITE, the
reference client compensates by calling the existing canonical participant
release endpoint and presents a sanitized Needs attention state that permits
retry without a page refresh.

Admission failure remains a separate path: no Inviter is created, no INVITE is
sent, and no compensation release is issued for a participant that was never
admitted. Attempt identity guards prevent stale callbacks from an earlier
attempt from changing a newer attempt.

## Signaling credential lifecycle

The existing telephony signaling credential API remains the issuance authority.
The client schedules renewal before the finite `expires_at` deadline, updates
authorization on the existing SIP.js UserAgent, and re-registers the existing
Registerer. It does not create another UserAgent or registration binding, make
credentials permanent, store credentials in localStorage, or add manual
renewal controls.

Renewal failure reports signaling failure through the actual adapter state
callback, so the UI does not retain a false REGISTERED state indefinitely.

## Verification

Focused frontend tests pass: remote termination, terminal failed INVITE,
admission compensation, repeated terminal callbacks, local Leave, navigation
teardown, finite-credential renewal, renewal failure, and registration
continuity are covered. Frontend type checking passes. Natural browser proof was
not performed by this implementation packet.

## Scope boundaries

No conference-admission authority, SIP registration authority, backend
participant-operation framework, Kubernetes manifest, runtime topology, or
Reverb/RT-1 implementation was changed. OBSERVATION-2, concerning a historical
backend blocked reconciliation state after a duplicate remove during an ARI
outage, remains separate follow-up evidence.
