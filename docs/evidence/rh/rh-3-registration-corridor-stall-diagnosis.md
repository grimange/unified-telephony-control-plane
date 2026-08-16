# RH-3 — Registration-Corridor Stall Targeted Diagnosis

## Verdict

    RH_3_REGISTRATION_CORRIDOR_STALL_ROOT_CAUSE_ISOLATED

One defect explains the entire 136.75-second silent corridor, and it also explains
the missing credential renewal that made the corridor reachable. Both are the same
seam.

**`registerCurrentRegisterer()` settles its registration promise exclusively on a
SIP.js `RegistererState` *transition*, but SIP.js emits `stateChange` only when the
state actually changes.** Any REGISTER whose outcome matches the Registerer's
current state — accepted while already `Registered`, or rejected while already
`Unregistered` — therefore emits nothing, leaves the promise permanently pending,
and strands the registration single-flight together with every caller awaiting it.

## Method

Evidence-only. No application source, test, schema, manifest, or configuration was
modified. No retry constant, credential TTL, SIP Timer B/F, RH-1 grace, reconnect
timer, or auth-retry allowance was changed. No browser bundle was patched, no
browser state injected, no production JS monkey-patched, no database written, no
credential corrupted, no auth configuration touched.

No live reproduction was required: the causal chain is fully determined by the
installed SIP.js source (static control-flow evidence) combined with the runtime
logs already captured during the RH-3F reproof. Helm remains absent from the host
and was neither required nor installed.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree (RNP … RH-3F packets) plus this document
    commit/push:   none created, not pushed
    sip.js:        0.21.2 (apps/web/package.json `^0.21.2`, installed 0.21.2)
    registrar:     Kamailio `max_expires` 120, `default_expires` 120, `min_expires` 10
                   (infrastructure/kubernetes/base/platform/kamailio-configmap.yaml:66-68)

## Live Failure Timeline

Reconstructed from the captured Kamailio log, the browser's passive
`PerformanceObserver` record, and `control_plane_audit_records`. All times
2026-08-15 UTC.

    CREDENTIAL ISSUED:   21:19:45.888 (POST signaling-credential → 201; audit
                         `telephony.signaling_credential.issued` @21:19:45)
    CREDENTIAL EXPIRY:   21:21:45.888 (120 s lifetime)
    EXPECTED RENEWAL:    ~21:21:15.9 (expiry − RENEWAL_SAFETY_WINDOW_MS 30 000)
    ACTUAL RENEWAL:      **none** — no credential request and no audit issuance
                         between 21:19:45 and 21:26:57
    REGISTER 1:          21:24:41.177 → 401 (`kamailio_registration_challenge`)
    REGISTER 2:          21:24:41.180 → 401 (`kamailio_registration_challenge`),
                         no `registration_accepted`
    SILENCE START:       21:24:41.180 — zero canonical API requests, zero SIP,
                         zero admissions, zero INVITEs for 136.75 s
    GRACE EXPIRY:        21:26:41 (loss 21:24:41 + 120 s); canonical sweep removed
                         the participant @21:27:02, reason
                         `conference participation recovery grace expired`
    NEXT WSS:            21:26:59.020 (`kamailio_websocket_accepted`)
    NEXT CREDENTIAL:     21:26:57.934 (201) — issued *before* the new WSS
    REGISTER SUCCESS:    21:26:59.034 (`kamailio_registration_accepted`)

### The two REGISTER exchanges that were previously unaccounted for

The captured Kamailio log contains two registration events between the renewal and
the stall that complete the picture:

    21:21:44.745  registration_accepted  — **no preceding challenge**
    21:23:43.551  registration_challenge
    21:23:43.560  registration_challenge — **no acceptance**

`21:21:44.745` is SIP.js's own registration refresh: `Registerer.registered()` arms
`registrationTimer` at `refreshFrequency/100 × expires` = 99 % × 120 s = 118.8 s
after the 21:19:45.94 acceptance → 21:21:44.74, matching the observed time to
within ~5 ms. It succeeded because the credential was still valid for one more
second. `21:23:43.551/.560` is the next such refresh (118.8 s later), now using the
credential that expired at 21:21:45 — challenged twice, never accepted.

## SIP.js Registerer Semantics

Static evidence from `apps/web/node_modules/sip.js/lib/api/registerer.js` (0.21.2).

    REGISTER PROMISE:
      `Registerer.register()` resolves on **send** — it returns the
      `outgoingRegisterRequest` from `userAgentCore.register(...)` (registerer.js:258).
      It never reflects the final response. Outcome reaches the caller only through
      the `requestDelegate` callbacks and/or a `stateChange` emission.

    401 STATE (first 401):
      Digest challenge handling is internal to the user-agent core; the application
      delegate is not invoked for the challenge itself. The client re-sends the
      REGISTER with an Authorization header. Registerer state is unchanged.

    SECOND 401 STATE (authenticated REGISTER rejected):
      `onReject` runs (registerer.js:360). Status is not 423, so it falls through to
      `this.unregistered()` (registerer.js:406), then invokes the caller's
      `requestDelegate.onReject(response)` (registerer.js:407-409), then
      `waitingToggle(false)`.

    TERMINAL/UNREGISTERED BEHAVIOR — the decisive detail:
      `unregistered()` is **conditional**:

          unregistered() {
            this.clearTimers();
            if (this._state !== RegistererState.Unregistered) {
              this.stateTransition(RegistererState.Unregistered);
            }
          }

      and `registered(expires)` is symmetrically conditional:

          if (this._state !== RegistererState.Registered) {
            this.stateTransition(RegistererState.Registered);
          }

      `stateTransition()` is the only place that emits
      (`this._stateEventEmitter.emit(this._state)`). Therefore **no `stateChange` is
      emitted when the outcome matches the current state**: an accepted REGISTER
      while already `Registered` emits nothing, and a rejected REGISTER while
      already `Unregistered` emits nothing. `stateTransition()` additionally throws
      on same-state transitions, so this guard is deliberate SIP.js behaviour, not
      an edge case.

      SIP.js schedules no further action of its own after a rejected REGISTER —
      `clearTimers()` cancels both `registrationTimer` and
      `registrationExpiredTimer`, so there is no internal retry.

## Application Registration Lifecycle

    registrationPromise (referenceDialerSignaling.ts:114-121):
      Single-flight. `ensureRegistered()` returns the in-flight promise to every
      later caller, **including when `force` is true** (:114). It is cleared only in
      the `finally` after `ensureRegisteredOnce()` settles (:120). While it is
      pending, every registration caller in the process is blocked behind it.

    registerCurrentRegisterer (:366-405):
      Builds `waitForState` (:371-389), whose only settle paths are
        • a `stateChange` emission → resolve on `Registered`, reject on
          `Unregistered`/`Terminated`, reject if the registerer was superseded; or
        • `this.pendingRegistrationReject(error)`, invoked **only** from
          `handleTransportDisconnect()` (:285) and `stop()` (:196).
      It then `await registerer.register({ requestDelegate: { onReject } })` (:391),
      capturing the rejection into a local `rejection` variable (:390, :394).
      Crucially it then `await waitForState` (:398) and consults `rejection` **only
      inside that promise's catch** (:400). There is no `onAccept` delegate, no
      inspection of `registerer.state` after `register()`, and no timeout. If
      `waitForState` never settles, the captured `RegistrationRejectedError` is
      never thrown.

    ensureRegistered (:111-122):
      `if (!force && this.isRegistered()) return` (:113) — during a healthy episode
      this short-circuits before `ensureRegisteredOnce()`, which is why recovery
      cycles that ran while registration was still valid never touched this seam.

    ensureRegisteredOnce (:337-364):
      Transport check (:339-343) → `registerCurrentRegisterer()` (:347) → on a
      401/403 `RegistrationRejectedError` with the retry allowance available,
      set `authRetryUsed = true` (:354), renew the credential (:355), dispose and
      recreate the registerer (:359-360), register again (:361). **`scheduleCredentialRenewal()`
      is the last statement (:363)** and is reached only when the whole body
      completes without throwing.

    authRetryUsed:
      Reset in `start()` (:142) and in `handleTransportDisconnect()` (:283). Set only
      at :354. In this incident it was **false** for the entire window — no
      401-triggered renewal had ever run since `start()` at 21:18:15, because both
      earlier registrations were accepted. The one fresh-credential allowance was
      therefore **available and unused** at 21:24:41; it simply was never reached.
      **Candidate D is excluded by evidence.**

## Transport Lifecycle

    reconnectPromise (:293-301):
      **null throughout the 136.75 s silence.** `scheduleReconnect()` is called only
      from `handleTransportDisconnect()` (:289) and from `ensureRegisteredOnce()`
      when the transport is already down (:340). The WSS was up the whole time —
      the 21:24:41 REGISTERs reached Kamailio over it — so no reconnect was pending,
      requested, or suppressed.

    DISCONNECT:
      ~21:26:57.9, immediately before the new handshake. Not directly observed in
      the browser record; derived from the ordering
      `handleTransportDisconnect → pendingRegistrationReject → 401 thrown → credential
      POST 21:26:57.934 → reconnect → WSS 21:26:59.020`. Kamailio sets no
      `websocket` modparams, so the module defaults apply (keepalive ping with a
      180 s timeout); the registration binding lapsed at ~21:23:44.7 and the socket
      was closed roughly 180 s later. **NOT DIRECTLY OBSERVABLE** as to which side
      initiated; immaterial to the root cause — it is the *release* mechanism, not
      the cause.

    NEXT RECONNECT:
      21:26:59.020, one ladder step after the disconnect.

    BLOCKING RELATIONSHIP:
      **None in the blocking direction.** Registration did not block reconnect;
      reconnect eventually *unblocked* registration, via `pendingRegistrationReject`
      (:285). **Candidate B is excluded by evidence** — but note the corollary: with
      the transport healthy there was no mechanism at all capable of settling the
      stranded promise, which is precisely why the stall lasted until an unrelated
      transport event occurred.

## Credential Renewal Lifecycle

    TIMER OWNER:  ReferenceDialerSignalingClient (`renewalTimer`, :38)
    SCHEDULE:     `scheduleCredentialRenewal()` (:217-230), delay =
                  `expires_at − now − 30 000 ms`
    CLEAR:        `clearCredentialRenewal()` (:232-235), called from
                  `scheduleCredentialRenewal()` itself (:218), `stop()` (:191) and
                  `handleTransportDisconnect()` (:284)
    RE-ARM:       only two call sites — the tail of `ensureRegisteredOnce()` (:363)
                  and the tail of `renewSignalingCredential()` (:253)

    WHY NO RENEWAL:
      Both re-arm sites sit **after** `await ensureRegistered(true)` /
      `await registerCurrentRegisterer()` in their respective bodies. At 21:19:45
      `renewSignalingCredential()` renewed the credential and awaited
      `ensureRegistered(true)`; the REGISTER was accepted at 21:19:45.939 while the
      Registerer was **already `Registered`**, so `registered()` performed no
      transition and emitted nothing, and `waitForState` never settled. Execution
      never reached :363 or :253, so **no renewal timer was ever armed for the
      21:19:45 credential**. `registrationPromise` and `renewalInFlight` also
      remained stuck until 21:23:43.

      The timer was therefore not cancelled — it was **never created**.
      **Candidate C is real but is a consequence, not the root cause.**

## Root Cause

    Registerer already in `Registered` at the 21:19:45 credential renewal
    → the accepted REGISTER (21:19:45.939) triggers `registered()`, which
      performs NO state transition and emits NO `stateChange`
    → `waitForState` in `registerCurrentRegisterer()` never settles, so
      `ensureRegisteredOnce()` never reaches `scheduleCredentialRenewal()` (:363)
    → the credential-renewal timer is never armed; the credential expires
      unrenewed at 21:21:45
    → SIP.js's own refresh at 21:23:43.55 is rejected 401/401, `unregistered()`
      transitions `Registered → Unregistered` and DOES emit, which settles the
      stranded 21:19:45 promise through its error path (still skipping :363) and
      leaves the Registerer parked in `Unregistered`
    → the 21:24:38 media loss drives recovery into `ensureRegistered()`;
      `registerCurrentRegisterer()` sends REGISTER, which is rejected 401/401 while
      the Registerer is ALREADY `Unregistered`, so `unregistered()` is a no-op and
      again emits NO `stateChange`
    → `waitForState` never settles, the captured `RegistrationRejectedError(401)` is
      never thrown, the 401 branch (:349-361) never runs, and no fresh credential is
      requested
    → `registrationPromise` stays pending, the single-flight `recoveryPromise` in
      `beginRecovery()` stays pending, and the corridor is silent for 136.75 s
    → the RH-1 120-second grace expires at 21:26:41 and the canonical sweep removes
      the participant at 21:27:02
    → only Kamailio's unrelated WebSocket close at ~21:26:57.9 invokes
      `pendingRegistrationReject` (:285), which finally rejects `waitForState`,
      throws the captured 401, resets `authRetryUsed`, and lets the normal
      fresh-credential path complete (credential 21:26:57.934, REGISTER accepted
      21:26:59.034).

The single defect is the third and sixth links: **the registration promise is
settled by state *transitions* rather than by the REGISTER's own final response.**
It fires on both edges — success-while-registered and rejection-while-unregistered.

## Consequences

Listed separately from the root cause; none of these is independently defective.

1. The credential-renewal timer for the 21:19:45 credential was never armed, so the
   credential expired unrenewed at 21:21:45.
2. `registrationPromise` and `renewalInFlight` were stranded from 21:19:45 to
   21:23:43.
3. The SIP registration lapsed, leaving the Registerer parked in `Unregistered` —
   the precondition that made the second (blocking) manifestation reachable.
4. At 21:23:43 the view received `onStateChange('failed')` and absorbed it silently:
   `updateSignalingState` (`ReferenceDialerView.vue:171-176`) set `state = 'recovering'`
   and called `beginRecovery()`, whose synchronous guard
   (`ReferenceDialerView.vue:339`) found the conference still established and
   returned via `markConferenceConnected()`. Correct behaviour, and it is why no
   canonical request appeared — the UI legitimately stayed Connected.
5. Recovery for the second episode issued zero admissions, zero INVITEs and zero
   DELETEs during the stall — a stall, never a storm. Canonical authority behaved
   correctly throughout: the participant stayed `admitted` until the server-side
   grace sweep removed it with the correct reason.
6. RH-3F's measured retry cadence and episode reset are unaffected — both were
   captured before the stall began, and the stall involves no retry-index state.

## Authority Boundary

Unchanged and correct. `referenceDialerSignaling` owns the transport, Registerer and
credential lifecycle; the defect is wholly inside it. `ReferenceDialerView` owns
orchestration only and behaved correctly, including absorbing the spurious `failed`
at 21:23:43. The canonical API remained the sole authority on participation
eligibility, and the server-side 120-second grace remained authoritative and
enforced it. **No correction should move credential or transport authority into the
view**, and none is proposed.

## Exact Defective Seam

    FILE:     apps/web/src/signaling/referenceDialerSignaling.ts
    METHOD:   registerCurrentRegisterer() — lines 366-405, specifically the
              `waitForState` promise (:371-389) and `await waitForState` (:398)
    STATE:    Registerer already in the state the REGISTER outcome would set —
              `Registered` for an accepted REGISTER, `Unregistered` for a rejected one
    EXPECTED: the registration promise settles from the REGISTER's own final
              response — resolve on accept, reject with `RegistrationRejectedError`
              on reject — so `ensureRegisteredOnce()` reaches either
              `scheduleCredentialRenewal()` (:363) or its 401 retry branch (:349-361)
    ACTUAL:   the promise settles only on a `RegistererState` transition; SIP.js
              emits none when the outcome matches the current state, so the promise
              remains pending indefinitely, stranding `registrationPromise` (:114)
              and every caller awaiting it

Secondary, same seam and same fix: `registerer.register()` is given only an
`onReject` delegate (:392-396); there is no `onAccept`, so the accepted-while-
`Registered` case has no settle path at all.

## Bounded Correction

Proven, **not implemented**.

Settle the registration promise from the request outcome, and keep the state
listener only as a corroborating path:

* add an `onAccept` delegate alongside the existing `onReject` in the
  `registerer.register(...)` call (:392-396);
* build an `outcome` promise that resolves when `onAccept` fires and rejects with
  `RegistrationRejectedError(status)` when `onReject` fires;
* replace `await waitForState` (:398) with a race of `outcome` against
  `waitForState`, retaining `pendingRegistrationReject` unchanged so transport loss
  and `stop()` still release the wait, and retaining `waitForState` for the SIP.js
  paths that call `unregistered()` and return **without** invoking the delegate
  (registerer.js:276, :317-318, :323-325 — missing/mismatched Contact or Expires).

This changes no constant, adds no timer, adds no retry, adds no gate, and leaves the
RH-3B ladder, RH-3C reconnect ladder, credential TTL, SIP Timer B/F and the RH-1
120-second grace untouched. Authority boundaries are unchanged.

### Why this shipped green

`apps/web/src/signaling/referenceDialerSignaling.test.ts:69-83` — the `Registerer`
test double emits **unconditionally**:

    this.state = 'Unregistered'; this.stateChange.emit('Unregistered')   // on reject
    this.state = 'Registered';   this.stateChange.emit('Registered')     // on accept

Real SIP.js emits only on an actual transition. Every existing test therefore
exercises a registrar that always emits, and the production hang is unreachable
from the suite. This is the same class of gap as the RH-2D finding, where the
`terminated` assertions passed `attemptId === undefined` and short-circuited the
guard before the comparison.

## Required Regression Tests

Deterministic frontend tests, no live runtime.

1. **Fix the double first.** Make the `Registerer` test double faithful to SIP.js:
   emit `stateChange` only when the new state differs from the current one, in both
   the accept and reject paths. Without this, tests 2-4 cannot fail.
2. `ensureRegistered(true)` **resolves** when the registrar accepts while the
   Registerer is already `Registered` (no emission), and
   `scheduleCredentialRenewal()` arms the next timer — assert a second renewal
   fires on a fake timer.
3. `ensureRegistered()` **rejects with `RegistrationRejectedError(401)`** when the
   registrar rejects while the Registerer is already `Unregistered` (no emission),
   and the single fresh-credential retry then runs and re-registers.
4. A credential renewal whose re-registration does not change Registerer state still
   arms the following renewal — i.e. two consecutive renewals fire from one
   `start()`, which is the exact 21:19:45 case.
5. `registrationPromise` is released in every case above, so a later
   `ensureRegistered()` caller is never blocked behind a settled attempt.
6. Retained unchanged: existing transport-loss rejection coverage
   (`pendingRegistrationReject`), the RH-3C reconnect ladder tests, RH-3E
   same-episode auth exhaustion and fresh-allowance-in-a-later-episode tests, and
   the RH-3F retry-index tests.

## Runtime Reproof Required

One narrow corridor after implementation — not another RH-3 proof:

1. Natural login, Join, Connected.
2. Hold the call across **at least two** credential renewal cycles (~4 minutes) and
   assert each renewal issues a credential and re-registers, proving the renewal
   timer re-arms after a re-registration that changes no Registerer state.
3. Then one bounded `kubectl scale deployment/rtpengine --replicas=0`, and assert the
   replacement-INVITE ladder starts within the RH-1 grace with no silent gap and
   converges after restoration.

Nothing else from RH-3 needs re-running.

## Failed Tests / Commands

    None.

    git diff --check        → clean
    make repository-hygiene → passed
    make secret-scan        → passed

## V0 Status

    COMPLETE / UNCHANGED

## RT-1A Status

    COMPLETE / LIVE PROVEN / UNCHANGED

## RH Status

    RH-3F: COMPLETE / LIVE PROVEN — unaffected; the retry ladder, cap, jitter and
           episode reset carry no registration state and were measured before the
           stall began.
    RH-3C: one remaining registration-lifecycle defect, now isolated to
           `registerCurrentRegisterer()`.
    RH-3:  BLOCKED only by this registration-corridor finding until it is fixed and
           narrowly reproven.
