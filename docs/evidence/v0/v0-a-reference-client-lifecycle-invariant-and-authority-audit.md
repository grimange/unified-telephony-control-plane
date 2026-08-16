# V0-A — Reference Client Lifecycle Invariant and Authority Audit

## Verdict

    V0_A_REFERENCE_CLIENT_LIFECYCLE_INVARIANT_AND_AUTHORITY_AUDIT_PASSED

The reference-client lifecycle has one coherent set of authorities and
deterministic convergence rules across every supported normal and terminal path.
PROOF_GAP-1 resolves to classification **B**: ending a telephony session
intentionally does not own SIP dialog termination, and the repository states this
as an explicit accepted contract rather than leaving it implicit. No material
canonical-state or UI contradiction remains.

## Method

Evidence-only, repository-first. No application source, Kubernetes topology,
Kamailio/Asterisk/rtpengine configuration, credential TTL, or Reverb
configuration was modified. **No new live proof was required**: every open
question resolved against committed ADRs, architecture contracts, schema,
configuration, implementation, and the already-accepted live evidence chain. The
only command executed against the tree was the focused frontend test suite,
which is a read-only check.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing large uncommitted RNP/RNM/V0 working tree (94 paths)
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

Application source is unchanged since the credential-renewal reproof; the same
six packet files carry the same mtimes.

## Existing accepted V0 evidence

Accepted and not re-proven here:

* **Registration** — natural login → telephony session → signaling credential →
  WSS → REGISTER → Digest → 200 OK → REGISTERED.
* **Credential renewal** — 4 credentials in 271 s, one per ~90 s, all expiries
  strictly advancing, no storm, original 120 s expiry crossed without remount.
* **Post-renewal admission** — Join 71 s after the original credential expired →
  `participants/self` 201 → authenticated INVITE → 200 OK → Established →
  Connected.
* **Normal Leave** — Connected → Leave → BYE → 200 OK → DELETE
  `participants/self` → participant removed/left → Ready, one logical release and
  one successful remove operation.
* **Failed establishment** — admission succeeds, INVITE fails, no false
  Connected, participant compensated, Needs attention. Live-observed once and
  repository-tested.

Focused coverage re-run during this audit as a read-only check:

    apps/web/src/views/ReferenceDialerView.test.ts
    apps/web/src/signaling/referenceDialerSignaling.test.ts
    → 2 files, 24 tests, all passing

## Lifecycle authority map

| Object | Canonical authority | Evidence |
|---|---|---|
| User / tenant identity | PostgreSQL through C1 identity services; server-computed capability projection | ADR-014; `authority-boundaries.md` |
| Telephony session | PostgreSQL through C5 `TelephonyDomainService`; **a tenant-scoped control-plane telephony authorization session only — explicitly not SIP registration, a media path, a call, microphone access, or a runtime channel** | ADR-017 Decision; `authority-boundaries.md:54`; `overview.md:116` |
| Signaling credential | `telephony_signaling_credentials` through `SignalingCredentialService`; **one active TelephonySession may hold exactly one active short-lived credential** | ADR-019 Decision; `SignalingCredentialService::issue()` revokes all unrevoked rows before insert |
| SIP registration — desired | UTCP: `signaling_registration_observations.desired_state` ∈ `eligible` \| `removed`, set only through `upsertDesiredRegistration()` | ADR-019 ("services set desired state only") |
| SIP registration — execution | **Kamailio / native `usrloc` is the sole registrar**: authentication, Contact binding, replacement, deregistration, expiration | ADR-019 Decision |
| SIP registration — observation | Fenced C3 event source (`KamailioRegistrationObserver`) → normalized `kamailio.registration.*` receipts → projection → `SignalingRegistrationReconciler` | ADR-019; ADR-016 |
| Conference | PostgreSQL through C5; desired state + `configuration_generation` | ADR-017 |
| Conference participant | PostgreSQL through C5 `admitParticipant` / `removeParticipant` | ADR-017 |
| Conference/runtime binding | `conference_runtime_bindings` through C5 placement; runtime work only via `conference.ensure`, `conference.close`, `conference.participant.ensure`, `conference.participant.remove` | ADR-017 Decision |
| Runtime channel / evidence | Runtime adapter (Asterisk ARI) under C3 operations; observation through receipts and `conferenceRuntimeSummary` | ADR-018; ADR-016 |
| Browser SIP dialog | **No canonical UTCP object and no canonical UTCP operation.** Owned end-to-end by the signaling/runtime plane: SIP.js in the browser, Kamailio relaying, Asterisk terminating | schema (no dialog/channel/call-leg table); `telephony_domain.operation_types` (no dialog operation) |
| Reference-client UI | `ReferenceDialerView.vue`, projecting server-returned state and SIP.js session state; never an authority | — |

### The one authority that does not exist, stated explicitly

There is **no canonical representation of a browser SIP dialog** anywhere in the
control plane. The telephony-domain schema contains only
`telephony_sessions`, `telephony_signaling_credentials`,
`signaling_registration_observations`, `conference_participants`, `conferences`,
and `conference_runtime_bindings` — verified directly against the live database.
The complete canonical operation catalogue is:

    conference.ensure                 conference.close
    conference.participant.ensure     conference.participant.remove
    runtime.node.verify_conference_absent
    runtime.node.runtime.fence        runtime.node.restore
    runtime.node.decommission
    runtime.node.provision            runtime.node.deprovision

None of these targets a dialog, a call leg, or a browser channel. This is by
design, not omission — see PROOF_GAP-1.

## Credential TTL invariant

    CURRENT TTL:      120 s   (telephony_signaling.credential_lifetime_seconds,
                               no environment override in the local overlay)
    RENEWAL WINDOW:   30 s    (RENEWAL_SAFETY_WINDOW_MS)
    CONTRACT CLASSIFICATION: IMPLICIT BUT SAFE CURRENT CONFIGURATION
    V0 IMPACT:        none

`SignalingCredentialService::issue()` computes

    expires_at = now + min(credential_lifetime_seconds,
                           max(1, seconds remaining in the telephony session))

ADR-019 requires "one active short-lived SIP credential" but fixes no numeric
floor, and nothing in configuration, validation, or tests ties
`credential_lifetime_seconds` to the client's 30-second safety window. The
invariant `credential TTL > renewal safety window` therefore holds today by
configuration (120 > 30, live-proven) rather than by contract.

Two evidenced edges follow from the `min()` clamp, both recorded as non-blocking:

1. A future `credential_lifetime_seconds` at or below 30 would make
   `scheduleCredentialRenewal()` compute `delay <= 0` at mount, so the reference
   client would fail closed immediately. Nothing prevents that value today.
2. During the final ≤30 seconds of any telephony session the clamp necessarily
   yields a sub-window credential, so the client fails closed and shows
   "SIP registration failed" up to ~30 s before the session's natural expiry.
   This is an early **under**-claim, not a false REGISTERED: the credential is
   still momentarily valid, and `expireDueSessions()` converges the session,
   credentials, and participants immediately afterwards. It does not meet the
   contradiction standard.

No environment gate, manual control, fallback TTL, or compatibility logic was
added or recommended.

## Registration invariant

Confirmed:

    one active reference-client telephony session
      → one logical SIP identity   (ts-<session uuid, dashes stripped>, stable)
      → one logical browser registration

`usernameForSession()` derives the identity deterministically from the session
id, so a session cannot produce two identities. `issue()` revokes every unrevoked
credential for the session before inserting the replacement, so two credentials
cannot be current at once. Live corroboration across the renewal proof: 4
credential rows, exactly 1 unrevoked at every observation, 1
`signaling_registration_observations` row, 1 `registered` row across all tenants,
and contact RUID `uloc-6a7f8890-f-1` unchanged across all three renewals.

Events that invalidate registration, and the authority that converges each:

| Event | Desired state | Convergence authority | Bound |
|---|---|---|---|
| Credential replacement | stays `eligible` | client re-REGISTERs on the same Registerer; Kamailio replaces the binding | immediate |
| Credential expiry with no renewal | stays `eligible` | Kamailio contact expiry | ≤120 s (`contact_max_expires_seconds`) |
| Telephony session end / expiry | `removed` via `revokeForSession()` | Kamailio bounded contact expiry; reconciler waits `bounded_contact_expiry_pending` until observed `unregistered`/`expired` | ≤120 s |
| Browser unmount | unchanged | `stop()` → `registerer.unregister()` → observed `removed` | immediate |

`SignalingRegistrationReconciler` never force-deregisters; it waits for the
runtime's bounded expiry, exactly as ADR-019 requires ("services set desired
state only"). No new required event was invented.

## Admission invariant

Confirmed chain:

    POST /api/v1/conferences/{conference}/participants/self
      → ConferenceController::joinSelf  (requires telephony.conferences.join)
      → TelephonyDomainService::admitSelf
      → admitParticipant

`admitParticipant` revalidates, under `lockForUpdate`, that the conference exists
in the tenant, that `desired_state === 'open'`, that `runtime_node_id` is bound,
that the telephony session is active and unexpired, that the caller owns the
session or holds the manage capability, and that the role is configured. Success
creates or reuses exactly one `conference_participants` row with
`desired_state = admitted`, wakes the `conference_participant` reconciliation
target at the correct desired generation, emits
`conference_participant.admitted`, and returns the sanitized
`signaling_destination`.

The browser INVITE is strictly downstream: `ReferenceDialerView.join()` awaits
`referenceDialerApi.joinConference(...)` before calling
`signalingClient.invite(admission.signaling_destination)`. Frontend filtering of
closed or unbound conferences is presentation only — the backend independently
refused closed conferences with 422 in the earlier live proof.

## Failed establishment invariant

Confirmed:

    admission succeeds + SIP never reaches Established
      → participant released

`ReferenceDialerSignalingClient` distinguishes the two terminal paths by
`inviteEstablished` / `localTermination`, emitting `failed` when `Terminated` is
reached without a prior `Established`. `updateCallState('failed')` calls
`finalizeConferenceSession('failed')`, which invokes
`referenceDialerApi.leaveConference()` and lands the view in `attention`.

No canonical backend object can remain active after that compensation completes:
the participant's `desired_state` becomes `removed`, the reconciler removes or
observes the absence of the runtime channel, and no runtime binding is created
per-participant. The only failure mode would be the compensation request itself
failing, which leaves the view in `attention` with the error surfaced rather than
silently converged. No destructive live repro was performed — repository evidence
is not contradictory.

## Conference attempt / stale callback invariant

Confirmed coherent; not redesigned.

* `join()` increments `conferenceAttempt` and resets `cleanupPromise`.
* `invite()` assigns `attemptId = ++this.inviteAttempt`, and its state listener
  short-circuits on `if (this.inviter !== inviter) return`.
* `updateCallState()` drops any callback whose `attemptId !== conferenceAttempt`.
* `finalizeConferenceSession()` single-flights on `cleanupPromise`, so repeated
  terminal callbacks collapse into one canonical release.

Test-proven (`coalesces repeated terminal callbacks into one canonical
participant release`) and live-proven (one POST + exactly one DELETE per attempt;
one `conference.participant.ensure` and one `conference.participant.remove`, each
succeeded in 1 attempt).

## Participant cleanup / 404 semantics

    CLASSIFICATION:  BRITTLE BUT NON-BLOCKING
    CHANGE REQUIRED: none for V0

Canonical semantics of `DELETE /api/v1/conferences/{conference}/participants/self`:
`removeSelfFromConference()` selects the caller's participant with
`desired_state = 'admitted'` and, finding none,
`abort_unless($participant !== null, 404, 'Participant not found.')`. The route is
therefore **not** an idempotent convergence command at the domain layer — an
already-converged state returns 404, not success. There is no typed domain error
code; the payload is a plain `{"message": "Participant not found."}`.

The frontend normalizes this in `platform.ts::leaveConference`, catching
`ApiRequestError` with `status === 404` and returning `{ participant: null }` so
`finalizeConferenceSession` converges to Ready.

Reason for the classification, on evidence rather than aesthetics:

* **Semantically sound for this route.** Every 404 it can produce means "you are
  not an admitted participant of this conference", which is exactly the
  post-condition the client is trying to reach. Treating it as converged is
  correct.
* **Brittle in one evidenced way.** `ApiRequestError` carries the raw transport
  status and sets `details = null` when the body is not JSON, so a non-domain 404
  is indistinguishable from the domain 404. That condition is not hypothetical:
  during this audit series the edge returned 404 for *every* route while
  Traefik's apiserver NetworkPolicy pin was stale. Under such skew the client
  would show Ready while the participant remained admitted server-side.
* **Non-blocking.** That divergence is bounded and self-correcting: the
  participant is released by `removeParticipantsForSession()` on session end or
  `expireDueSessions()`, and the participant reconciler independently converges
  from observed runtime absence. Worst case is a stale row for at most the
  telephony-session lifetime, with no runtime channel left behind once the
  reconciler observes absence.

If it is ever hardened, the seam is exact and one-line-shaped: assert the domain
payload (`details.message`, or a typed code added to `removeSelfFromConference`)
before treating a 404 as converged. Not required for V0.

## Local leave invariant

Repository and live evidence agree:

    Connected → Leave → inviter.bye() → BYE → 200 OK
             → finalizeConferenceSession('local-leave')
             → DELETE participants/self
             → participant removed / left
             → UI Ready

Live-proven twice (most recently BYE at 21:34:35.908Z, 200 OK at 21:34:35.923Z,
participant `removed`/`left` at 21:34:46Z, reconciliation converged). Media proof
intentionally not repeated.

## Remote SIP termination invariant

The **client contract** is coherent and test-proven: a remote BYE drives SIP.js
to `SessionState.Terminated`, which routes through the same
`finalizeConferenceSession` path as a local leave, releasing the participant and
returning the UI to Ready (`releases admission and returns Ready when the runtime
terminates an established session`; `reports remote termination after an
established dialog without requiring local leave`).

The **runtime question** is now answered definitively: **no canonical UTCP
operation is expected to produce such a BYE toward the reference client's
dialog.** The full operation catalogue was enumerated and each conference-facing
implementation inspected —

* `conference.participant.remove` → `removeParticipantChannel()` deletes
  `utcp-part-<participant>` and its Local peer on the bound RuntimeNode;
* `conference.close` → `closeConferenceBridge()` issues
  `DELETE bridges/utcp-conf-<conference>`;

— and neither touches the browser's PJSIP channel, which lives in the
`from-kamailio` context on the SIP-facing Asterisk and is never a member of the
bridge. No BYE was manufactured.

The committed runtime does hold a bounded reclaim authority for abandoned
dialogs. `infrastructure/docker/asterisk*/config/pjsip.conf`, endpoint
`anonymous`:

    ; Reclaim dialogs that lose external RTP without operator cleanup.
    rtp_timeout=30
    rtp_timeout_hold=30

So a browser dialog whose media stops — tab closed, network lost, machine slept —
is terminated by Asterisk within 30 s. This was observed live twice in the
conference-admission reproof.

## PROOF_GAP-1 — control-plane session end

    EXPECTED CONTRACT:  none — the repository explicitly denies it
    ACTUAL CONTRACT:    telephony session end does not own SIP dialog termination
    CLASSIFICATION:     B
    MISSING PATH:       none
    ROOT CAUSE:         n/a — behaviour matches the accepted contract
    IMPLEMENTATION SEAM: n/a

The question — *when UTCP ends an active telephony session, who is responsible
for terminating any associated active SIP dialog or runtime channel?* — is
answered directly by two accepted documents, in identical words:

> `docs/architecture/authority-boundaries.md:54` — "A C5 `TelephonySession` is an
> authenticated user's tenant-scoped control-plane telephony authorization
> session only; **it is not SIP registration, media connectivity, a call, browser
> microphone access, or a runtime channel.**"

> `docs/decisions/ADR-017` (Accepted), Decision — "A `TelephonySession` is an
> authenticated user's tenant-scoped control-plane telephony authorization
> session. **It does not represent SIP registration, a media path, a call,
> microphone access, or a runtime channel.**"

ADR-017 further constrains C5 runtime work to the four conference/participant
operations, and `authority-boundaries.md:54` forbids C5 from adding "SIP
credentials, SIP registration, WebRTC/media, ARI, ESL, Asterisk, FreeSWITCH,
Kamailio, rtpengine". A session-end path that hung up a SIP dialog would
therefore violate the accepted C5 boundary, not satisfy it.

What session end **is** contracted to do, and does — verified in
`endLockedSession()` and observed live:

    status → ended, ended_at, termination_reason
    revokeForSession()            → all credentials revoked; desired registration `removed`
    removeParticipantsForSession() → every admitted participant → `removed`,
                                     reconciliation woken at the correct generation
    emit telephony_session.ended

Each of those converges through an identified authority: credentials immediately;
registration through Kamailio's bounded contact expiry (≤120 s, observed live
`pending_removal` → `unregistered`); participants through the participant
reconciler, which removed the runtime channel and reported `healthy_absent` with
`bridge_channel_count = 0`.

**What legitimately survives**, and why it is not a contradiction: the reference
client's browser dialog. Its far end is extension `9900`, the committed T3
`Answer(); Echo(); Hangup()` media fixture — not the conference bridge. It
carries no tenant resource and no conference audio, and the member's canonical
conference membership has already been removed. Its convergence authorities are
identified and bounded: the member's own Leave or navigation (immediate BYE), and
Asterisk `rtp_timeout=30` once media stops. What is genuinely absent is a *push
notification* telling an open browser tab that control-plane state changed —
which is exactly RT-1's documented scope, and which this audit is forbidden to
solve. That is a freshness gap in a minimal reference client, not a lifecycle
authority contradiction.

PROOF_GAP-1 therefore **closes without implementation**. No Reverb, Echo,
polling, session-end WebSocket workaround, forced hangup endpoint, or direct
Asterisk action was introduced or recommended.

## Terminal state matrix

Rows are limited to triggers the repository establishes as reference-client
lifecycle paths. Conference close and runtime drain are **not** included: no
repository evidence makes them reference-client triggers, and neither reaches the
browser dialog.

| Trigger | Telephony session | Credential | Registration | Participant | SIP dialog | UI | Convergence authority |
|---|---|---|---|---|---|---|---|
| Normal local Leave | active | current | registered | removed → left | BYE → terminated | Ready | client `leave()` + `removeSelf` + participant reconciler |
| Failed INVITE before Established | active | current | registered | admitted → removed (compensated) | never established | Needs attention, Join retryable | client `finalizeConferenceSession('failed')` |
| Remote SIP BYE | active | current | registered | removed → left | terminated | Ready | SIP.js `Terminated` → shared cleanup (test-proven; no canonical UTCP operation emits this BYE) |
| Browser navigation / unmount | active | current | → removed | released via `local-leave` | BYE, then unregister | view destroyed | `onBeforeUnmount` → `leave()` → `stop()` |
| Credential renewal failure | active | last one valid until expiry | expires within ≤120 s | unchanged | unchanged | "SIP registration failed" (truthful) | client fails closed; Kamailio contact expiry |
| Telephony session end | ended | revoked immediately | desired `removed` → `unregistered` ≤120 s | removed → left | **survives until member acts**; reclaimed by `rtp_timeout=30` once media stops | call panel stale "Connected" until member acts | contract: session end does not own the dialog (ADR-017); dialog converges via member action or runtime reclaim; UI freshness is RT-1 |
| Participant already removed | active | current | registered | already removed | BYE on Leave | Ready | client normalizes 404 as converged |

## Material contradictions

    None.

Each candidate in the contradiction standard was tested against evidence:

* *Session ended + participant removed + credential revoked + dialog permanently
  active + UI permanently Connected, with no convergence authority* — **does not
  hold**. The dialog is not permanently active (member action; `rtp_timeout=30`),
  conference membership converged canonically, and the surviving leg is the echo
  fixture. The contract explicitly excludes dialogs from session authority.
* *UI REGISTERED while registration authority is durably invalid* — **does not
  hold**. Renewal failure and session end both drive the view to "SIP
  registration failed"; both were observed live. The only mismatch found is the
  opposite direction (an under-claim in the final ≤30 s of a session).
* *Participant admitted after failed establishment with no cleanup authority* —
  **does not hold**. Compensation is implemented, test-proven, and live-observed.
* *Multiple current credentials or registrations for one logical session* —
  **does not hold**. Contract (ADR-019) plus implementation plus live
  measurement: 1 unrevoked credential, 1 registration row, stable RUID.
* *Stale conference callback mutating a later attempt* — **does not hold**.
  Attempt-id fencing at both the client and view layers, test-proven.

## Non-blocking observations

1. **Credential TTL is not contractually floored.** `credential_lifetime_seconds`
   has no validation tying it to the client's 30 s renewal window. Safe at the
   configured 120 s; a value ≤30 would fail the reference client at mount.
2. **Sub-window credential at end of session.** The `min(lifetime,
   session_remaining)` clamp guarantees a credential shorter than the renewal
   window during the final ≤30 s of every telephony session, so the client fails
   closed slightly before natural expiry. Truthful under-claim; converges.
3. **Participant release 404 is status-normalized.** See the cleanup section:
   correct for the domain 404, brittle against a non-domain 404, bounded and
   self-correcting. Seam identified if it is ever hardened.
4. **No push path for control-plane state changes.** An open dialer tab cannot
   learn that its session was ended administratively. Documented RT-1 scope.
5. **Reference destination is a fixed echo fixture.** `signaling_destination` is
   the constant `sip:9900@{realm}`, so the browser leg is not the conference leg.
   Recorded previously; not a V0 acceptance criterion, and the canonical
   participant channel does carry conference membership correctly.

## Failed tests / checks

    Focused reference-client tests:  2 files, 24 tests, all passing.

Unrelated pre-existing issue, unchanged and untouched:

    apps/api/tests/Feature/RuntimeProvisioning/ManagedRuntimeDeprovisioningOperationTest.php
    fails `pint --test` (unary_operator_spaces, statement_indentation,
    not_operator_with_successor_space).

`make check` is therefore not claimed green. This is not a V0 failure.

## Code changes

    None.

## Environment and topology changes

    None. No cluster, registry, namespace, host port, context, node, persistent
    volume, deployment mechanism, or runtime configuration was touched. No
    deployment was performed; this audit required no live mutation.

## Improvised or non-canonical actions

    None.

## V0 completion decision

    V0-A PASSED
    V0 COMPLETE

The composed V0 acceptance chain:

    natural login and browser SIP registration        LIVE PROVEN
    conference discovery, admission and INVITE        LIVE PROVEN
    runtime conference binding and bidirectional RTP  LIVE PROVEN
    local Leave and canonical cleanup                 LIVE PROVEN
    failed-establishment compensation                 LIVE OBSERVED + TESTED
    bounded credential renewal across the old cliff   LIVE PROVEN
    lifecycle authority coherence                     AUDITED (this document)

No further generic V0 audit or reproof is required.
