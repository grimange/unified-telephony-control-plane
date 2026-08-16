# RH-0 — Browser + Telephony Recovery / Conference Auto-Rejoin Contract

## Verdict

    RH_0_BROWSER_TELEPHONY_RECOVERY_CONTRACT_COMPLETED

The repository already carries most of the required authority. Conference
participation intent and the runtime call leg are **already separate canonical
axes**, explicit Leave is **already** the only intent cutoff, unexpected channel
loss **already** preserves intent, the reconciler **already** parks a
self-admission participant waiting for a replacement inbound leg, and admission
**already** returns the same participant and destination on re-admission.

Four bounded gaps prevent deterministic auto-rejoin today, one of them in the
browser and three in the backend. One nullable timestamp column is the only
schema change required.

## Method

Repository-evidence only. No application source, Kubernetes, SIP, Asterisk,
Kamailio, or schema was modified. No deployment and no live interruption proof
was performed. Live inspection was read-only and used only to read committed
configuration values (session lifetime, catalogs).

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing working tree (V0 + RT-1A packets)
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Current conference participation model

    TABLE/MODEL: conference_participants
      id, tenant_id, conference_id, telephony_session_id, user_id,
      desired_state, observed_state, role, admission_reason,
      joined_at, left_at, failure_class, failure_code,
      created_at, updated_at, runtime_channel_id

    PARTICIPANT STATES (config/telephony_domain.php):
      participant_desired_states  = ['admitted', 'removed']
      participant_observed_states = ['unobserved','joining','joined','leaving','left','failed']

    DESIRED/INTENT STATE: desired_state  ('admitted' = the user intends to participate)
    RUNTIME CHANNEL STATE: runtime_channel_id (nullable) + observed_state
    EXPLICIT LEAVE STATE:  desired_state = 'removed'

**The semantic separation the recovery model needs already exists.**
`desired_state` is durable intent; `runtime_channel_id` and `observed_state` are
disposable runtime observation. `admission_reason = 'self_admission'` already
marks a participant whose leg is a browser SIP dialog rather than a synthetic
channel.

## Current Join lifecycle

    browser Join (ReferenceDialerView.join)
      → referenceDialerApi.joinConference()
      → POST /api/v1/conferences/{conference}/participants/self
      → ConferenceController::joinSelf   (requires telephony.conferences.join)
      → TelephonyDomainService::admitSelf
      → TelephonyDomainService::admitParticipant      ← intent authority
          · locks the conference, revalidates open + runtime binding,
            active telephony session, ownership, role
          · REUSES an existing (conference_id, telephony_session_id,
            desired_state='admitted') participant if one exists
          · otherwise inserts desired_state='admitted',
            admission_reason='self_admission'
          · returns signaling_destination = sip:conf-<participantId>@<realm>
      → signalingClient.invite(destination)            (existing UserAgent)
      → Kamailio CONFERENCE_RUNTIME_RELAY → kamailio_conference_route_view
      → bound RuntimeNode, dialplan _[c]o[n]f-. → Stasis(app, conf-<id>)
      → AsteriskAriEventListener::ingestAriEvent (StasisStart)
      → AsteriskConferenceParticipantBinder::bind()
          · sets runtime_channel_id = inbound PJSIP channel id
          · attaches that channel to utcp-conf-<conferenceId>
      → normalizer → observed_state 'joined'

**`admitParticipant`'s reuse branch is the auto-rejoin primitive**: a returning
browser that calls `participants/self` again for the same conference and the same
telephony session receives the *same* participant id and the *same*
`sip:conf-<participantId>@<realm>` destination. No second participant is created.

## Current Leave lifecycle

    Leave button (ReferenceDialerView.leave)
      → signalingClient.leave()  → inviter.bye()  (SIP BYE if Established)
      → finalizeConferenceSession('local-leave')
      → referenceDialerApi.leaveConference()
      → DELETE /api/v1/conferences/{conference}/participants/self
      → ConferenceController::removeSelf
      → TelephonyDomainService::removeSelfFromConference
      → TelephonyDomainService::removeParticipant     ← intent cutoff authority
          · desired_state = 'removed'  (TelephonyDomainService.php:1248)
          · wakes participant reconciliation at the removed generation

Durability: yes — `desired_state='removed'` is canonical PostgreSQL state.
Idempotence: yes — the client single-flights on `cleanupPromise`, and
`leaveConference` normalizes a 404 ("Participant not found.") as converged.
Race with StasisEnd: benign today — `clear()` only nulls `runtime_channel_id`
and cannot resurrect or contradict `desired_state`.

**Only two sites in the entire application set `desired_state='removed'`:**

    TelephonyDomainService.php:1248  removeParticipant()            ← explicit Leave / admin remove
    TelephonyDomainService.php:1314  removeParticipantsForSession() ← telephony session end/expiry

## Current unexpected channel-loss lifecycle

    STASIS END: AsteriskAriEventListener::ingestAriEvent
                  → if ($type === 'StasisEnd') Binder::clear($node, channel_id)
    BINDER CLEAR: AsteriskConferenceParticipantBinder::clear()
                  · UPDATE conference_participants
                      SET runtime_channel_id = NULL
                    WHERE runtime_channel_id = <the exact channel id>
                      AND tenant matches AND the active binding is this node
                  · **does NOT touch desired_state**
    RECONCILER:   ConferenceParticipantReconciler::evaluate()
                  · desired_state='admitted' + admission_reason='self_admission'
                    + runtime_channel_id IS NULL
                    → ReconciliationResult::waiting(
                        'conference_participant_awaiting_inbound_signaling_leg', 30)
    ADAPTER:      AsteriskRuntimeAdapter::ensureParticipant()
                  · self_admission → completes as
                    'runtime_operation.asterisk_conference_participant_awaiting_inbound'
                    and **originates no synthetic channel**
    TERMINAL TRANSITION: **none from runtime loss alone.**

Server-side, an unexpectedly lost channel leaves the participant
`desired_state='admitted'`, `runtime_channel_id=NULL`, and the reconciler
re-evaluates every 30 s **indefinitely**, waiting for a replacement inbound leg.
That is exactly the RECOVERING state the product decision requires — but it is
**unbounded**, and the only thing that ever ends it today is telephony-session
expiry (30 minutes live).

**However, the browser currently destroys the intent before the server ever
exercises that path** — see the Authority Gap below.

## Current refresh behaviour

    AUTH:              first-party Laravel session cookie survives a refresh;
                       the SPA re-authenticates transparently.
    TELEPHONY SESSION: survives. `ReferenceDialerController::bootstrap` calls
                       `TelephonyDomainService::currentSession()`, and
                       `ReferenceDialerView.initialize()` only creates a session
                       when that returns null. Live lifetime is 30 minutes
                       (`session_lifetime_minutes`, observed
                       02:46:03 → 03:16:03).
    SIP.JS:            entirely rebuilt — a new `UserAgent` and `Registerer` are
                       constructed from a newly issued one-time credential. No
                       SIP dialog, session object, or Call-ID survives.
    PARTICIPANT DISCOVERY: **absent.** `bootstrap` returns
                       `application`, `tenant_id`, `telephony_session`,
                       `signaling`, and `conferences` — it does not return the
                       caller's own `conference_participants` row, and no other
                       endpoint exposes "my current participation".
                       `serializeParticipant()` exists but is only returned from
                       the admission/release responses.

Because the telephony session survives a refresh, the participant keyed by
`(conference_id, telephony_session_id)` is still the correct one — the reuse
branch of `admitParticipant` would find it. The refreshed page simply has no way
to *learn* that it should.

## Authority gap

Four bounded gaps prevent deterministic auto-rejoin. Nothing in the existing
architecture makes the accepted product decision impossible.

**GAP 1 — the browser destroys participation intent on every unexpected loss and
on unmount (frontend authority).**
`ReferenceDialerView.updateCallState()` routes `terminated` **and** `failed`
into `finalizeConferenceSession()`, which calls `referenceDialerApi.leaveConference()`;
`onBeforeUnmount` calls `finalizeConferenceSession('local-leave')` as well. So a
remote BYE, an RTP timeout, a navigation, or a refresh all currently issue the
canonical DELETE and set `desired_state='removed'`. Explicit Leave and unexpected
loss are indistinguishable **at the client**, even though the server distinguishes
them perfectly. This is the central change: only the Leave button may call the
canonical release.

**GAP 2 — no loss timestamp, so grace cannot be bounded.**
`clear()` nulls `runtime_channel_id` and bumps `updated_at`, but `updated_at` is
also bumped by unrelated projection/lifecycle writes, so it cannot carry
"when the runtime leg was lost". Recovery eligibility can be *derived* from
existing state; recovery **expiry** cannot.

**GAP 3 — no recovery discovery.**
The reference-dialer bootstrap does not expose the caller's recoverable
participation.

**GAP 4 — no grace expiration owner.**
The reconciler waits forever; only the 30-minute session expiry eventually
converges the participant.

Everything else the contract needs already exists and is already correct.

## Canonical participation intent

    conference_participants.desired_state = 'admitted'

with `admission_reason = 'self_admission'` identifying a browser-owned leg. This
is the durable statement that the user intends to participate. It is unaffected
by SIP dialog state, WebRTC transport, Reverb, page lifetime, or runtime channel
identity.

## Explicit Leave cutoff

    TelephonyDomainService::removeParticipant()  →  desired_state = 'removed'

reached only from `removeSelfFromConference()` (the member's own Leave), the
admin participant-remove route, and `removeParticipantsForSession()` on session
end/expiry.

It prevents all future auto-rejoin because every recovery predicate is anchored
on `desired_state='admitted'`, and no code path ever transitions a participant
back from `removed` to `admitted`. A subsequent deliberate Join creates a *new*
participant (the reuse branch matches only `desired_state='admitted'`), which is
the correct semantics for a fresh, user-initiated participation.

## Unexpected disconnect — the condition that begins recovery

    AsteriskConferenceParticipantBinder::clear()
      sets runtime_channel_id = NULL
      while desired_state remains 'admitted'

triggered by `StasisEnd` for the bound channel, itself produced by any of: browser
BYE without a canonical release, Asterisk `rtp_timeout=30` reclaiming a media-dead
channel, WebRTC/transport failure, tab close, or browser crash. This is a
**server-observed** condition and requires no browser cooperation — which
satisfies the "browser may disappear without warning" requirement.

## Recovery grace

    VALUE:          120 seconds
    SOURCE:         telephony_signaling.contact_max_expires_seconds = 120
                    and telephony_signaling.credential_lifetime_seconds = 120
    RATIONALE:      After 120 s the browser's Kamailio Contact binding has
                    certainly expired and its one-time signaling credential has
                    expired, so the "same logical signaling client" no longer
                    exists at the registrar; any return after that is a fresh
                    registration regardless. 120 s is also comfortably longer
                    than Asterisk's `rtp_timeout` of 30 s (so the server has
                    already observed the loss) and longer than one
                    `awaiting_inbound_signaling_leg` reconciler cycle (30 s),
                    while being far shorter than the 30-minute telephony-session
                    lifetime that is the only bound today. It covers a page
                    refresh (seconds) and a brief network interruption without
                    holding a conference bridge slot for an absent user.
    REPRESENTATION: derived, not stored — `runtime_channel_lost_at + 120 s`.
                    Expressed as a plain domain constant in
                    `config/telephony_domain.php`
                    (`participant_recovery_grace_seconds => 120`), following the
                    existing plain-array catalog convention. **No `env()`
                    override, no environment gate, no per-tenant switch, no
                    per-conference allowlist, no operator-managed timer.**
    EXPIRATION OWNER: the existing scheduler. `routes/console.php:947` already
                    runs `Schedule::command('telephony-domain:expire-sessions')
                    ->everyMinute()->withoutOverlapping()` inside the
                    `schedule:work` scheduler Deployment; the recovery sweep
                    belongs beside it as an equally automatic sweep.

## Recovery eligibility predicate

All canonical, all server-side, all validated by existing services:

    1. participant.desired_state = 'admitted'                    (intent alive)
    2. participant.admission_reason = 'self_admission'           (browser-owned leg)
    3. participant.runtime_channel_id IS NULL                    (leg actually gone)
    4. now() < participant.runtime_channel_lost_at + grace       (within grace)
    5. conference.desired_state = 'open'
    6. an active conference_runtime_bindings row whose runtime_node_id
       equals conferences.runtime_node_id
    7. runtime_nodes.desired_state = 'active' AND observed_state = 'ready'
    8. that node has an enabled sip/udp endpoint
    9. telephony_sessions.status = 'active' AND expires_at > now()
   10. the web session is authenticated, the membership is active, and the user
       still holds telephony.conferences.join / telephony.sessions.* /
       telephony.signaling.issue_own

Predicates 5–8 are exactly the predicates already encoded in
`kamailio_conference_route_view`; 1–3 and 9 are already enforced inside
`AsteriskConferenceParticipantBinder::bind()`; 10 is enforced by
`AuthorizationService::requireTenant` on the existing endpoints. **No browser-side
authority is introduced for any of them.**

## Recovery discovery API

    BOUNDED EXTENSION REQUIRED (one field on an existing endpoint)

`GET /api/v1/reference-dialer/bootstrap` already returns the telephony session,
signaling metadata, and conferences for the authenticated member. It should also
return the caller's recoverable participation, e.g.

    "participation": {
      "participant_id": "...",
      "conference_id": "...",
      "signaling_destination": "sip:conf-<participantId>@<realm>",
      "recoverable": true,
      "recoverable_until": "..."
    }

derived from a new read-only `TelephonyDomainService` method applying the
predicate above. No new endpoint, no new controller, no token. The server decides
`recoverable`; the browser only obeys it.

## Browser recovery state machine

Deterministic client states (the existing `ConferenceState` union plus one
`recovering` state):

    ready → joining → connected → leaving → ready
                          ↓ (unexpected terminal, no explicit Leave)
                      recovering ──recovered──→ connected
                          └────grace/ineligible────→ ready | attention

    brief same-leg recovery (Case A)
      SIP session still Established and server runtime_channel_id unchanged
      → no INVITE, no admission call, stay Connected

    replacement-leg recovery (Case B)
      SIP session Terminated or absent, server reports runtime_channel_id null
      and recoverable=true
      → POST participants/self (reuse branch returns the same participant)
      → INVITE sip:conf-<participantId>@realm on the current UserAgent
      → Connected

    refresh / tab return
      page load → normal auth → bootstrap → participation.recoverable
      → register → Case B

    auth expiration
      any 401 → normal login page; after successful login re-run bootstrap and,
      if still recoverable, resume Case B. Never bypass authentication.

    grace expiration
      bootstrap reports recoverable=false → UI Ready, no INVITE

    conference closure / node ineligible / admin removal
      bootstrap reports recoverable=false → UI Ready (or Needs attention with the
      canonical reason) → no auto-rejoin

    explicit Leave
      immediate canonical release; any in-flight recovery is abandoned and must
      not be retried

## SIP / TelephonySession recovery

Reuse first, replace only if necessary, and never re-scope the authority:

* The **web session** is unchanged by a refresh; a returning browser is already
  authenticated.
* The **TelephonySession** is reused: `bootstrap` → `currentSession()` returns
  the active session, and the client creates a new one only when it is null.
  This matters because participation is keyed by `telephony_session_id` — reusing
  the session is what makes the *same* participant discoverable.
* If the TelephonySession has expired, `expireDueSessions()` has already run
  `removeParticipantsForSession()`, so intent is correctly `removed` and there is
  nothing to recover. The two lifetimes are consistent by construction.
* The **signaling credential** is re-issued through the existing
  `POST /telephony/sessions/{id}/signaling-credential`, and the existing
  `ReferenceDialerSignalingClient` renewal path (proven in V0) keeps it current.
* **SIP registration** recovers by ordinary REGISTER from the new `UserAgent`;
  ADR-019 already makes Kamailio the sole registrar and the identity
  `ts-<telephonySessionId>` is stable across the refresh.

`TelephonySession` remains a control-plane authorization session and is not
re-scoped to own SIP, media, or a dialog.

## Replacement conference leg

Confirmed to reuse the exact V0 corridor, with no second signaling path and no
backend origination:

    sip:conf-<participantId>@<realm>          ← same destination, same participant
      → Kamailio CONFERENCE_RUNTIME_RELAY
      → kamailio_conference_route_view (evaluated at query time)
      → the conference's *current* canonical bound RuntimeNode
      → dialplan _[c]o[n]f-.  → Stasis(app, conf-<participantId>)
      → AsteriskConferenceParticipantBinder::bind()
      → runtime_channel_id = new inbound PJSIP channel
      → member of utcp-conf-<conferenceId>

Because the destination carries only the participant id and the routing
projection is a view over current canonical state, **no RuntimeNode target is
persisted in browser recovery state** and a binding change during recovery is
followed automatically. The browser remains the SIP/WebRTC endpoint.

## Old/new channel fencing

    CURRENT PROTECTION: already correct for the classic race.
      · clear() is channel-scoped:
          WHERE conference_participants.runtime_channel_id = <exact channel id>
        so a late StasisEnd for old channel A cannot clear a newly bound channel B.
      · bind() refuses to steal:
          if ($existingChannel !== '' && $existingChannel !== $channelId) return false;
        so two concurrent inbound legs cannot both claim the participant, and the
        first bound channel wins.
      · bind() re-validates conference open, active binding matching
        conferences.runtime_node_id, node active/ready, and session active inside
        a lockForUpdate transaction.

    GAP: a *refresh* where the old channel is still bound server-side. The old
      browser is gone but Asterisk has not yet reclaimed the channel, so
      runtime_channel_id is still set and bind() will refuse the replacement leg.
      Asterisk's rtp_timeout=30 resolves this within ~30 s (the abandoned leg
      stops sending RTP), after which clear() runs and recovery can proceed — well
      inside a 120 s grace.

    REQUIRED FIX: none for correctness. The bounded contract is that the browser
      treats `runtime_channel_id != null` as "not yet replaceable" and retries
      within the grace rather than issuing an INVITE that cannot bind. An optional
      later optimisation — verifying via ARI that the old channel is truly absent
      and replacing it immediately — is explicitly **not** required and should not
      be bundled into the first slice.

## Duplicate Join / recovery protection

    CURRENT:
      · admitParticipant reuse branch → at most one admitted participant per
        (conference, telephony session); a double Join or a manual Join racing
        auto-rejoin both resolve to the same participant and destination.
      · Idempotency-Key support on the admission endpoint.
      · bind() first-writer-wins → at most one runtime_channel_id.
      · Client: conferenceAttempt counter with attemptId-fenced callbacks, and
        a single-flight cleanupPromise (both live-proven in V0).

    GAP: the client has no guard for *recovery* attempts specifically — multiple
      triggers (page load, Reverb reconnect, SIP re-register, network online
      event) could each start a recovery pass, producing several INVITEs. The
      server would bind only the first, but the surplus legs would enter Stasis
      and be hung up, which is noisy.

    REQUIRED FIX: a single-flight recovery guard in the reference client,
      modelled on the existing cleanupPromise/conferenceAttempt pattern — one
      in-flight recovery at a time, cancelled by explicit Leave.

## Reconciliation / grace expiration

    OWNER:    TelephonyDomainService, invoked by a scheduled sweep alongside the
              existing `telephony-domain:expire-sessions`
              (routes/console.php:947, ->everyMinute()->withoutOverlapping()).
              Intent mutation stays in the single existing authority
              (removeParticipant / the same UPDATE path as
              removeParticipantsForSession); the reconciler continues to make
              runtime decisions only and does not gain intent authority.
    CADENCE:  every minute (existing scheduler cadence), against a 120 s grace,
              so convergence lands within ~1–2 minutes of grace expiry.
    TERMINAL BEHAVIOUR: desired_state → 'removed' with a termination reason
              distinguishing abandonment from explicit Leave; the existing
              ConferenceParticipantReconciler removed-branch then performs the
              usual runtime cleanup and converges observed_state to 'left'.
              No operator action, no Artisan command in the normal path.

## Slow network contract

    REGISTER:   existing SIP.js/Registerer behaviour is unchanged; registration
                failure already surfaces as "SIP registration failed" and the
                credential-renewal guard already fails closed rather than
                looping (proven in the credential-renewal reproof).
    ADMISSION:  `participants/self` is idempotent via the reuse branch, so a slow
                or retried admission cannot create a duplicate participant.
    INVITE:     the client must not treat a slow 100/180/200 as failure; the
                existing attemptId fencing already discards late callbacks from
                superseded attempts. Needed: an explicit bounded INVITE timeout
                that moves to `attention` (retryable) rather than spinning.
    API:        canonical refetch on reconnect is the RT-1A pattern and is
                already proven; a failed refetch must leave the last known
                canonical state visible rather than blanking the view.
    UI:         states remain the existing union plus `recovering`
                ("Reconnecting…"). Requirements: no infinite spinner (every
                pending state has a bounded timeout), no duplicate retry (single
                -flight), and **no premature participant cleanup** — the client
                must never issue the canonical release merely because a request
                was slow.

## Security boundary

Auto-rejoin bypasses nothing:

* it calls the **same** `participants/self` endpoint behind the same session
  auth, tenant-context check, and `telephony.conferences.join` capability check;
* it uses the **same** `sip:conf-<participantId>@realm` destination, so Kamailio
  still applies SIP Digest authentication and still verifies that the
  projection's `signaling_identity` equals the authenticated identity;
* it is gated by canonical predicates that include conference openness, binding
  currency, RuntimeNode readiness, and telephony-session validity;
* it never resurrects a `removed` participant, so it cannot re-enter a conference
  the user left or an admin removed them from;
* **no privileged recovery endpoint, no recovery token, and no auth bypass** is
  proposed. Authentication expiry sends the user through normal login first.

## Reverb boundary

Conference recovery correctness does **not** depend on Reverb. Discovery is a
canonical API read on page load / reconnect — the RT-1A pattern, which was
live-proven precisely by showing that a browser which *missed* every notification
still caught up through canonical refetch. Extending RT-1 to ConferenceParticipant
would improve latency of the UI hint only and is **not** a prerequisite for this
work.

## Data model decision

    BOUNDED SCHEMA CHANGE REQUIRED — one nullable column.

    conference_participants.runtime_channel_lost_at  (nullable timestamptz)
      set by AsteriskConferenceParticipantBinder::clear() when it nulls
      runtime_channel_id while desired_state = 'admitted';
      cleared by bind() when a replacement channel is bound.

Why existing state cannot represent it: eligibility is derivable
(`desired_state='admitted'` + `runtime_channel_id IS NULL` + conference/node
predicates), but **expiry is not**. `updated_at` is written by unrelated
projection and lifecycle updates, so it cannot carry "when the leg was lost";
`joined_at` records the first successful join and `left_at` is terminal-only.
Without a dedicated timestamp the grace cannot be bounded and abandoned
participants would linger until the 30-minute session expiry.

Explicitly **not** required: no RECOVERING enum value (the existing
`desired_state='admitted'` + `runtime_channel_id IS NULL` pair already denotes
it, and the reconciler already names that state
`conference_participant_awaiting_inbound_signaling_leg`); no new table; no
browser recovery token; no new session authority.

## Canonical end-to-end recovery contract

    Join
      → admitParticipant: desired_state='admitted', admission_reason='self_admission'
      → INVITE sip:conf-<participantId>@realm → bind() → runtime_channel_id=<A>
      → bridge member → Connected

    Unexpected loss (BYE without release, rtp_timeout, transport failure,
    tab close, crash, refresh)
      → StasisEnd(<A>) → clear(): runtime_channel_id=NULL,
        runtime_channel_lost_at=now(), desired_state UNCHANGED='admitted'
      → reconciler parks at 'conference_participant_awaiting_inbound_signaling_leg'
      → participation is RECOVERABLE until runtime_channel_lost_at + 120 s

    Browser returns (page load, network recovery, transport reconnect)
      → normal authenticated web session (login if expired)
      → GET /reference-dialer/bootstrap → participation.recoverable
      → reuse the active TelephonySession, issue a signaling credential, REGISTER
      → if server still reports runtime_channel_id != null, wait within grace
      → POST participants/self  (reuse branch → same participant, same destination)
      → INVITE sip:conf-<participantId>@realm
      → projection selects the *current* canonical bound RuntimeNode
      → bind(): runtime_channel_id=<B>, bridge member
      → Connected, without a second Join click

    Late StasisEnd for <A> after <B> is bound
      → clear() is channel-scoped and matches nothing → <B> unaffected

    Explicit Leave at any point (ACTIVE or RECOVERING)
      → removeParticipant: desired_state='removed' immediately
      → in-flight recovery abandoned; a late successful leg is not bound because
        bind() requires desired_state='admitted'
      → never auto-rejoins

    User does not return
      → grace expires → scheduled sweep sets desired_state='removed'
      → reconciler cleans up the runtime and converges observed_state='left'
      → no operator action

    Conference closes / node drains or retires / binding changes /
    authorization revoked / admin removes the participant
      → the corresponding canonical predicate fails → recoverable=false
      → no auto-rejoin; the browser shows canonical state

## Implementation slices

Three bounded slices, each with an independently provable authority change.

### RH-1 — canonical recoverable participation, grace, and expiration

    AUTHORITY CHANGE: introduce the recovery grace as domain state and end
                      unbounded waiting; expose recoverable participation.
    FILES/SEAMS:
      apps/api/database/migrations/<new>_add_runtime_channel_lost_at_to_conference_participants.php
      apps/api/app/RuntimeAdapters/Asterisk/AsteriskConferenceParticipantBinder.php
        (clear() stamps the timestamp; bind() clears it)
      apps/api/app/TelephonyDomain/TelephonyDomainService.php
        (recoverable-participation read method; expireRecoverableParticipants())
      apps/api/app/Http/Controllers/TelephonyDomain/ReferenceDialerController.php
        (bootstrap exposes `participation`)
      apps/api/config/telephony_domain.php
        (participant_recovery_grace_seconds => 120, plain constant)
      apps/api/routes/console.php  (scheduled sweep beside expire-sessions)
    TESTS: clear() stamps and bind() clears the timestamp; bootstrap reports
      recoverable true/false across each predicate (conference closed, node not
      ready, session expired, explicit Leave, grace expired); the sweep converges
      only past-grace participants and never a `removed` one.
    LIVE PROOF: not required (repository + focused tests).
    DEPENDENCIES: none.

### RH-2 — browser recovery and automatic replacement leg

    AUTHORITY CHANGE: the client stops conflating unexpected loss with explicit
                      Leave, and gains a single-flight recovery pass.
    FILES/SEAMS:
      apps/web/src/views/ReferenceDialerView.vue
        (updateCallState: 'terminated'/'failed' must NOT call the canonical
         release when the user did not press Leave; onBeforeUnmount must tear
         down the SIP client without releasing participation; add `recovering`)
      apps/web/src/signaling/referenceDialerSignaling.ts
        (expose whether the dialog is genuinely gone; bounded INVITE timeout)
      apps/web/src/api/platform.ts  (consume bootstrap `participation`)
    TESTS: explicit Leave still releases; remote termination does NOT release;
      unmount does NOT release; recovery is single-flight across duplicate
      triggers; Leave during recovery cancels it; recoverable=false → Ready.
    LIVE PROOF: yes — natural refresh-while-Connected and brief-interruption
      recovery, asserting one participant, one runtime_channel_id, one bridge
      member, and no second Join click.
    DEPENDENCIES: RH-1.

### RH-3 — adversarial interruption and slow-network hardening

    AUTHORITY CHANGE: none — bounded timeouts and UI truthfulness only.
    FILES/SEAMS: the same client files; focused tests.
    TESTS/PROOF: slow REGISTER / admission / INVITE / refetch produce no false
      failure, no infinite spinner, no duplicate retry, and no premature
      participant cleanup; grace expiry, conference closure mid-recovery, and
      auth expiry mid-recovery each converge deterministically.
    DEPENDENCIES: RH-2.

RH-1 is safely shippable alone: it adds recoverable state and bounds it, while
the current client continues to release explicitly, so behaviour is unchanged
until RH-2 lands.

## Tests / evidence reviewed

    apps/web/src/views/ReferenceDialerView.test.ts — including
      'releases admission and returns Ready when the runtime terminates an
       established session'  ← the test that encodes today's conflation and must
       be revised by RH-2
      'coalesces repeated terminal callbacks into one canonical participant release'
      'treats an already absent participant as converged cleanup'
      'ignores a stale callback from an earlier attempt after retry'
      'allows a failed conference attempt to be retried without remounting'
    apps/web/src/signaling/referenceDialerSignaling.test.ts
    apps/api/tests/Feature/TelephonyDomain/TelephonyDomainTest.php
    docs/evidence/v0/v0-c6-conference-media-and-leave-live-proof.md
    docs/evidence/v0/v0-c6-binder-onward-conference-leg-reproof.md
    docs/evidence/v0/v0-a-reference-client-lifecycle-invariant-and-authority-audit.md
    docs/evidence/rt1/rt-1a-runtime-node-realtime-natural-browser-live-proof.md
    docs/decisions/ADR-017, ADR-019, ADR-022

## Unresolved proof gaps

None blocking the contract. Two items are deliberately deferred to
implementation-time verification rather than resolved here:

1. The exact latency of the refresh case where the old channel is still bound —
   bounded by `rtp_timeout=30` and comfortably inside a 120 s grace, to be
   measured in the RH-2 live proof.
2. Whether the RH-2 live proof should also assert the abandoned-leg path
   (close the tab, never return, confirm grace expiry converges) — recommended,
   and cheap once RH-1 exists.

## V0 status

    COMPLETE / UNCHANGED

## RT-1A status

    COMPLETE / LIVE PROVEN
