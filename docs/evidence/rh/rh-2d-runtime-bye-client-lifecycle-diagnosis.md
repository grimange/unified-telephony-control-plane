# RH-2D — Runtime-Initiated BYE Client Lifecycle Diagnosis

## Verdict

    RH_2D_RUNTIME_BYE_CLIENT_LIFECYCLE_ROOT_CAUSE_ISOLATED

The defect is **not** `hasEstablishedConference()`. That method behaves correctly:
the signaling client clears its session state on the inbound BYE *before* it
emits the terminal callback, and a live discriminating test proved
`hasEstablishedConference()` returns `false` in the stuck state.

The real cause is that the terminal callback never reaches its handler. The view
fences call-state callbacks on an attempt id, but the view's counter
(`conferenceAttempt`) and the signaling client's counter (`inviteAttempt`) are
**two independent counters incremented at different rates**. Every
`beginRecovery()` entry advances the view counter, including the many entries
that never place an INVITE, so after any recovery the two counters diverge. The
`terminated` callback for the live leg then arrives stamped with a stale-looking
id and is discarded at the first line of `updateCallState`, so `beginRecovery()`
is never invoked and the UI keeps rendering Connected.

## Method

Evidence and root-cause only. No application source, schema, manifest, or
configuration was modified. Deployment through canonical Make targets only.
Natural Playwright login from the real login page; no preset storage state,
injected cookie, copied session, database/Redis session, or authentication
bypass. No database row was written or read-modified by hand, no timestamp
manipulated, no scheduler or job invoked manually. Browser instrumentation was
passive logging only (`fetch`, `WebSocket.send`/`message`, `RTCPeerConnection`
wrappers) and changed no application behaviour. The terminating BYE came from
Asterisk's own committed `rtp_check_timeout` policy — no failure was injected
into signaling, media, or infrastructure.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree including the RH-1/RH-2/RH-2B/RH-2C packets
    commit/push:   none created, not pushed

Deployed for the reproduction (canonical lifecycle: `k8s-image-build` →
`k8s-image-push` → `k8s-apply` → rollout restart → `media-edge-apply`):

    API / WORKER: utcp/api @sha256:447e1d5a1abbff0adc634e486071cf957aca720dce0b889216db3c946a34c7a6
    WEB:          utcp/web @sha256:e1eae7cfb8d01e95dbce1d9f41c6b3ba20b49b1c823205ff02851338feeafe92
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready
    CONFERENCE:   68c7d252-2203-4f2a-9b81-4d87d1294768 — open / ready

RH-2C confirmed in effect during the run: unrecognised ARI events now project
`unknown` rather than `degraded` (30 `unknown` / 26 `ready` observations in the
window) and `runtime_nodes.observed_state` stayed `ready` throughout, so the
earlier `expired`-inside-grace confound was absent.

## Runtime-BYE Reproduction

One session produced both the working control and the failure back to back.

### Control — leg established by Join (counters aligned)

    SIP SESSION:        INVITE @07:38:03.518 (CSeq 1 unauth → 401 → CSeq 2 auth),
                        200 OK @07:38:03.630, ACK @07:38:03.635
    CHANNEL:            PJSIP/anonymous-00000014
    BYE:                **rx BYE @07:38:33.635** — Asterisk `rtp_check_timeout`
                        ("Disconnecting channel 'PJSIP/anonymous-00000014' for lack
                        of audio RTP activity in 30 seconds"); client answered
                        200 OK @07:38:33.639
    UI:                 **Recovering @07:38:36** (banner rendered)
    RECOVERY:           1 admission, replacement INVITE @07:38:35.939 → 200 OK
                        @07:38:36.074 → UI Connected @07:38:42
    RESULT:             correct — the terminal callback was accepted and
                        `beginRecovery()` ran

### Failure — leg established by recovery (counters drifted)

    SIP SESSION:        the replacement dialog established @07:38:36.074
    CHANNEL:            PJSIP/anonymous-00000015
    BYE:                **rx BYE @07:39:06.080** — same `rtp_check_timeout` policy
                        ("…in 31 seconds"); client answered 200 OK @07:39:06.082
    RUNTIME_CHANNEL_ID: NULL
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 07:39:09+00
    PARTICIPANT:        c27e4be1-96d3-4866-8261-f7c0f317e0a9
    BOOTSTRAP:          state="recoverable", recoverable=true,
                        recoverable_until 2026-08-15T07:41:09Z
    UI:                 **Connected** continuously from 07:39:06 to 07:40:43
                        (97 s), banner not shown
    RECOVERY:           **0** admissions, **0** replacement INVITEs
    RESULT:             the terminal callback was discarded; recovery never began

### Discriminating test — alternative trigger while stuck

    TRIGGER:      natural offline → online transition @07:40:43.031
                  (`window` "online" event → `handleOnline()` → `beginRecovery()`)
    ADMISSION:    1 @07:40:44.603
    INVITE:       1 logical transaction @07:40:44.799 / .804
    UI:           Recovering @07:40:47 → Connected @07:40:52
    BOOTSTRAP:    state="active" @07:40:52

`beginRecovery()` guards on `hasEstablishedConference()` at
`ReferenceDialerView.vue:280` and returns early — with **no** admission and **no**
INVITE — when it is true. Recovery ran to completion, so
`hasEstablishedConference()` returned **false**. The session state was already
correct; only the trigger was missing.

## SIP.js Lifecycle

    STATE BEFORE:       SessionState.Established (`inviteEstablished = true`)
    BYE EVENT:          inbound BYE on the WSS transport, answered 200 OK
    STATE TRANSITIONS:  Established → Terminated
                        (SIP.js sets Terminated directly for a received BYE;
                        `Terminating` is the locally-initiated `bye()` path. If an
                        intermediate `Terminating` did fire it is immaterial — it
                        carries the same attempt id and is discarded by the same
                        guard.)
    STATE AFTER:        SessionState.Terminated

Handler — `referenceDialerSignaling.ts:59-68`:

    if (state === SessionState.Terminated) {
      const wasEstablished = this.inviteEstablished
      this.inviter = null                       // ← reference cleared first
      this.inviteEstablished = false            // ← flag cleared first
      if (wasEstablished || this.localTermination) {
        this.onCallStateChange?.('terminated', undefined, attemptId)   // ← then emitted
      } else { … }
    }

Cleanup therefore **precedes** the callback. There is no transient window in
which a recovery decision could observe a stale established session.

## Client Session Reference

    REFERENCE BEFORE:    ReferenceDialerSignalingClient.inviter = Inviter X (Established)
    REFERENCE AFTER:     ReferenceDialerSignalingClient.inviter = null
    COLLECTION/MAP AFTER: none exists — the client holds a single `inviter` field
                         (`referenceDialerSignaling.ts:21`), not a collection, so
                         no orphan session can be retained anywhere else
    inviteEstablished AFTER: false

Confirmed live: the `online`-triggered `beginRecovery()` passed the
`hasEstablishedConference()` guard, which is only possible when both
`inviter?.state === Established` and `inviteEstablished` are false.

## Termination Callback

    CALLBACK:       onCallStateChange('terminated', undefined, attemptId)
                    → ReferenceDialerView.updateCallState (wired at
                      ReferenceDialerView.vue:457)
    ORDER:          Terminated transition → clear `inviter` → clear
                    `inviteEstablished` → emit callback
    ACCEPTED:       **NO** — rejected at `ReferenceDialerView.vue:173`

        if (attemptId !== undefined && attemptId !== conferenceAttempt) return

    STATE MUTATION: none. `conferenceState` stayed `'connected'`, `state` stayed
                    `'registered'`, no recovery timer was set, no promise created.

Rejection by the alternative guards is excluded:

* `destroyed` (`:172`) — false; the page was alive and rendering.
* `explicitLeaveInFlight` (`:192`) — false. It is set only by `cancelRecovery()`
  (reachable solely from `leave()`) and transiently inside
  `stopUnboundRecovery()` (restored in `finally`). No Leave was clicked, and the
  `online`-triggered `beginRecovery()` at 07:40:43 passed its own
  `explicitLeaveInFlight` guard at `:279`, proving the flag was false throughout
  the stuck window.

The attempt-id guard at `:173` is therefore the only filter that could have
rejected the callback.

## beginRecovery

    INVOKED:   NO (for the runtime BYE at 07:39:06)
    WHEN:      never — `updateCallState`'s `terminated` branch at `:191-192`
               (`if (!explicitLeaveInFlight) void beginRecovery()`) was not reached
    RESULT:    no recovery admission, no replacement INVITE, UI frozen on Connected
               until the unrelated `online` event supplied a second trigger 97 s
               later

## hasEstablishedConference

    IMPLEMENTATION (referenceDialerSignaling.ts:81-83):

        public hasEstablishedConference(): boolean {
          return this.inviter?.state === SessionState.Established || this.inviteEstablished
        }

    INPUTS:            this.inviter (single Inviter reference or null),
                       this.inviteEstablished (boolean)
    STATE CHECKS:      inviter.state === SessionState.Established
    REFERENCE CHECKS:  optional chaining only — a non-null reference alone is NOT
                       sufficient to return true
    FALLBACKS:         this.inviteEstablished
    TRUE CONDITIONS:   the current Inviter is Established, or the established flag
                       is still set
    TRUE/FALSE after the runtime BYE: **FALSE**
    EXACT TRUE CONDITION IN THE FAILING CASE: none — no condition evaluated true
    WHY INCORRECT:     it is **not** incorrect. Both fields are cleared by the
                       Terminated handler before the callback is emitted, and the
                       live discriminator confirmed it returns false in the stuck
                       state.

## Attempt Fencing

    INVOLVED: **YES — this is the defect.**

Two independent counters are compared as if they were one:

    view:    let conferenceAttempt = 0                 (ReferenceDialerView.vue:138)
    client:  private inviteAttempt = 0                 (referenceDialerSignaling.ts:22)

    client increments — exactly one site:
      referenceDialerSignaling.ts:47   const attemptId = ++this.inviteAttempt   (inside invite())

    view increments — three sites:
      ReferenceDialerView.vue:230      conferenceAttempt += 1                   (cancelRecovery)
      ReferenceDialerView.vue:285      const attemptId = ++conferenceAttempt    (beginRecovery, EVERY entry)
      ReferenceDialerView.vue:402      conferenceAttempt += 1                   (join)

    comparison:
      ReferenceDialerView.vue:173      if (attemptId !== undefined && attemptId !== conferenceAttempt) return

`beginRecovery()` increments at `:285` and then returns **without inviting** on
every one of these paths: participation null (`:297-306`), the
`awaitingRecoveryBinding` re-check branch (`:309-323`, all three exits), the
not-yet-recoverable polling branch (`:325-334`), the survival short-circuits
(`:336-347`), and the catch branch (`:374-388`). The polling branch re-arms
itself through `scheduleRecovery()` every `RECOVERY_RETRY_DELAY_MS = 2_000`
(`:271`, `:275`), so the view counter advances roughly once every two seconds
while the client counter stands still.

Reconstructed counter values for the reproduction:

    ATTEMPT BEFORE BYE (control leg):
      page mount, participation null → no beginRecovery
      join()  → conferenceAttempt = 1 ; invite() → inviteAttempt = 1   ALIGNED
      BYE @07:38:33 emits attemptId 1 == conferenceAttempt 1
      TERMINATION CALLBACK ACCEPTED: YES → beginRecovery() ran

    ATTEMPT AFTER:
      beginRecovery (from the accepted callback) → conferenceAttempt = 2
      invite() @07:38:35.939                     → inviteAttempt   = 2
      post-INVITE confirmation not yet bound → scheduleRecovery →
      beginRecovery again ~07:38:38             → conferenceAttempt = 3, NO invite
      (consistent with the observed UI: Recovering 07:38:36–07:38:39,
       bootstrap "active" at 07:38:39, Connected at 07:38:42)

    ATTEMPT BEFORE BYE (recovery leg):  conferenceAttempt = 3, inviteAttempt = 2
      BYE @07:39:06 emits attemptId 2 != conferenceAttempt 3
      TERMINATION CALLBACK ACCEPTED: **NO**
      RECOVERY CALLBACK ACCEPTED:    n/a — no recovery was started

The drift is one-directional and monotonic: the view counter can only run ahead,
so once a recovery has occurred every subsequent callback for the live leg is
discarded — including `connected`, `terminating` and `terminated`.

Corroborating detail: the recovery leg still reached "Connected" in the UI only
because `beginRecovery()` sets `conferenceState.value = 'connected'` itself at
`:363-368` / `:310-316` after its own canonical bootstrap confirmation, entirely
bypassing `updateCallState`. The UI's Connected state for a recovered leg is
therefore never driven by the SIP callback path that would later report its
termination.

### Why the existing tests do not catch this

`apps/web/src/views/ReferenceDialerView.test.ts` drives the fake client through
`emitCallState(state, message?, attemptId?)`. Every `terminated` assertion
(`:231`, `:320`, `:321`, `:368`, `:392`, `:431`, `:474`) calls it with
`attemptId === undefined`, which short-circuits the guard at `:173` before the
comparison. The single call that supplies an id (`:279`,
`emitCallState('failed', 'stale failure', 1)`) asserts the guard's *intended*
suppression of a superseded attempt. No test exercises a **live** attempt whose
id has fallen behind `conferenceAttempt`, so the production path is untested.

## Normal Join vs Recovered Leg

    DIFFERENCE: yes, but not in session handling. Both legs use the same `Inviter`
                construction, the same single `inviter` reference, the same
                `stateChange` listener registration, the same delegate wiring and
                the same cleanup. There is no difference in reference assignment,
                delegate registration, `recoveryPromise` lifecycle, or callback
                registration.

                The only difference is **counter alignment**:
                  join()  increments conferenceAttempt and immediately invites →
                          aligned when no prior recovery has run
                  recovery increments conferenceAttempt on every beginRecovery()
                          entry, most of which never invite → drifted

    MATERIAL:   YES — and it is not intrinsic to recovered legs. A **Join** leg is
                equally affected once any recovery has previously run on the same
                page, because `join()` at `:402` advances only the view counter and
                never resynchronises the client. "Recovered leg" is a symptom of
                the drift, not a separate code path.

## Connected UI Authority

    VARIABLE/COMPUTED: `conferenceState` (ref<ConferenceState>,
                       ReferenceDialerView.vue:134), rendered at `:62` and `:73-77`.
                       It is NOT derived from SIP SessionState, from the session
                       reference, or from canonical bootstrap participation — it is
                       an independently held ref.

    WHY IT STAYED CONNECTED: it was set to `'connected'` by `beginRecovery()`'s
                       own success path (`:366` / `:314`) and nothing ever wrote it
                       again. The only writer that reacts to SIP termination is
                       `updateCallState`, and its callback was discarded at `:173`.
                       Because `conferenceState` is not a computed value derived
                       from the session or from bootstrap, a missed transition is
                       permanent: the canonical bootstrap visibly reported
                       `recoverable` from 07:39:10 onward while the same page kept
                       rendering "Connected".

## Recovering Banner

    SAME ROOT CAUSE: **NO**

The banner renders on `state === 'recovering'` (template `:34`). Its residue comes
from a different asymmetry in the same file: the paths that set
`conferenceState.value = 'connected'` **without** restoring
`state.value = 'registered'` — the survival short-circuit at `:280-282` and the
`connected` branch of `updateCallState` at `:181`. Both leave a previously set
`state = 'recovering'` in place, so the banner is rendered beside a working
Connected/Leave control.

It is a distinct cause but sits in the same seam (recovery-path state management
in `ReferenceDialerView.vue`), so it can reasonably ride along in the bounded fix.
It is non-blocking on its own.

## Backend Correlation

    desired_state:           admitted   (unchanged throughout the stuck window)
    runtime_channel_id:      NULL
    runtime_channel_lost_at: 2026-08-15 07:39:09+00
    bootstrap recoverable:   true, recoverable_until 2026-08-15T07:41:09Z
                             (= loss + 120 s exactly)
    runtime channels:        none — the leg was gone from Asterisk
    runtime node:            observed_state ready throughout (RH-2C in effect)

The backend recovery authority was **correct** at every moment: intent preserved,
loss stamped once, the exact RH-1 grace offered, and the participation advertised
as recoverable while the browser displayed Connected. The blocker is entirely
browser-local.

## Exact Root Cause

A runtime-initiated BYE transitions the client's `Inviter` to
`SessionState.Terminated`; the listener in
`referenceDialerSignaling.ts:59-68` correctly clears `this.inviter` and
`this.inviteEstablished` and then emits
`onCallStateChange('terminated', undefined, attemptId)` where `attemptId` is the
signaling client's own `inviteAttempt` (incremented only inside `invite()`).
`ReferenceDialerView.updateCallState` rejects that callback at line 173 because it
compares the client's `inviteAttempt` against the view's separate
`conferenceAttempt`, which `beginRecovery()` increments on **every** entry
(`:285`) including the ~1-per-2-seconds polling entries that never place an
INVITE. After any recovery the view counter is strictly ahead, the live leg's
`terminated` callback is discarded, `beginRecovery()` at `:192` is never invoked,
and `conferenceState` — an independently held ref that no other code path
revises — remains `'connected'` until an unrelated trigger (`online`, refresh)
happens to call `beginRecovery()`. `hasEstablishedConference()` is not implicated:
it returns `false` in that state, proven live by the `online`-triggered recovery
completing normally.

## Bounded Fix Target

    apps/web/src/views/ReferenceDialerView.vue
      - updateCallState()          :171-194   attempt-id comparison at :173
      - beginRecovery()            :285       ++conferenceAttempt on every entry,
                                              including entries that never invite
      - join()                     :402       conferenceAttempt += 1 without
                                              resynchronising the signaling client
      - cancelRecovery()           :230       conferenceAttempt += 1
      - (banner, optional)         :280-282 and :181 — set conferenceState without
                                              restoring `state` to 'registered'

    apps/web/src/signaling/referenceDialerSignaling.ts
      - invite()                   :41-79     sole owner of the attempt id
                                              (`inviteAttempt`, :47) that the view
                                              is compared against

The seam to correct is the ownership of the attempt identity: one authority must
issue the id used both to stamp callbacks and to fence them (for example, the
view passing its `attemptId` into `invite()`, or the view fencing on the id the
client returns), so the two can never diverge. Not implemented here.

## Tests Required For Fix

    1. remote BYE, normal Join leg  — terminal callback carrying the live attempt
       id is accepted; recovery begins; UI leaves Connected.
    2. remote BYE, recovered leg    — same assertion after at least one completed
       recovery, i.e. with the counters in the state that previously drifted.
    3. counter-drift regression     — several beginRecovery() entries that do not
       invite (polling / awaiting-binding), then a remote BYE on the live leg:
       the callback must still be accepted.
    4. same-dialog survival         — a recovery trigger while the dialog is still
       Established must not produce a replacement INVITE (guards the fix against
       over-correcting into duplicate legs).
    5. explicit Leave               — Leave still cancels recovery, issues exactly
       one canonical removal, and suppresses any late callback for the leg it ended.
    6. late/stale callback fencing  — a callback from a genuinely superseded
       attempt must still be ignored (preserve the behaviour asserted today at
       ReferenceDialerView.test.ts:279).
    7. banner consistency (if the banner is included in the fix) — after a
       successful recovery and after the survival short-circuit, `state` returns to
       'registered' so the Recovering banner is not rendered beside Connected.

Existing view tests must be updated to pass a realistic `attemptId` from the fake
client; otherwise the guard remains unexercised.

## Environment State at Completion

    admitted participants: 0
    runtime channels:      0
    conference bridge:     0 members
    conference:            68c7d252-… open / ready
    runtime node:          d4539d79-… active / ready
    browser:               logged out through the normal Log out control

## Code Changes

    None.

## V0 Status

    COMPLETE / UNCHANGED

## RT-1A Status

    COMPLETE / LIVE PROVEN / UNCHANGED

## RH Status

    RH-0: COMPLETE
    RH-1: IMPLEMENTED / TESTED
    RH-2: BLOCKED ONLY ON THIS CLIENT DEFECT
    RH-3: NOT STARTED
