# RH-2 — Final Natural Browser Interruption / Refresh / Auto-Rejoin Live Reproof

## Verdict

    RH_2_BROWSER_NETWORK_AUTO_REJOIN_NATURAL_LIVE_REPROOF_FOUND_BLOCKER

All five numbered RH-2 scenarios passed, and the RH-2B binder retry path was
**live exercised end to end** (`RETRYABLE → queued retry → same live channel →
BOUND`, with the bridge attachment performed by the retry). The previously
reported blocker — the replacement leg never binding — is **closed**, and the
previously reported secondary defect — the view stranded in `Recovering` after an
explicit Leave — is **also closed**.

The verdict is nonetheless `FOUND_BLOCKER`, because two defects that break RH-2's
own contract were observed live and are not covered by the five scenario scripts:

1. A **runtime-initiated BYE on an established leg leaves the browser showing
   Connected forever**, with no recovery attempt, until the participation
   silently expires. Observed twice, with the inbound BYE captured on the wire.
2. A **transient `degraded` RuntimeNode observation makes the canonical
   participation report `expired` while the 120-second grace is still running**,
   which in the first run caused the client to hang up an already-established
   replacement leg and abandon recovery permanently.

Both are isolated, both are reproducible by timing, and neither is a storm.

## Method

Evidence-only. No application source, schema, manifest, or configuration file was
modified. Deployment through canonical Make targets only. Natural Playwright
login from the real login page; no preset storage state, injected cookie, copied
session, database/Redis session, or authentication bypass. No database row was
hand-edited, no timestamp manipulated, no expiration command invoked manually, no
retry job invoked manually, no channel bound or adopted by hand, no Stasis event
replayed, and no readiness predicate weakened or falsified. `rtp_timeout` and the
120-second grace were not changed. Browser instrumentation was **passive
observation only** (a logging wrapper around `fetch`, `WebSocket.send`/`message`,
and `RTCPeerConnection`); it changed no application behaviour. No secret appears
below.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree including the RH-1/RH-2/RH-2B packets
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Canonical environment

    API:           utcp/api    @sha256:0e1dfdf9d4e5048622f32afb546724870f49e0d93bc87714a1c890e67fadfed2
    WEB:           utcp/web    @sha256:1e16e10034486a05a7416a92ecd2d40259c99b47094a94fd51f80d4d5ee556de
    ASTERISK:      utcp/asterisk-ari @sha256:b2d7184892f9a133d7f0909347d8ae904d250111d3a362569b45c3e4da04dc69
    WORKER:        utcp/api    @sha256:0e1dfdf9… (same image as API; deployment/worker)
    RUNTIME NODE:  d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready, sip/udp :5060 enabled
    CONFERENCE:    68c7d252-2203-4f2a-9b81-4d87d1294768 — open / ready, binding active
    DEPLOYMENT FRESH: yes

Lifecycle: `make k8s-image-build` → `make k8s-image-push` → `make k8s-apply` →
`kubectl rollout restart` of the ten `utcp-platform` Deployments →
`make media-edge-apply`.

Content verification inside the running pods (exact MD5 match against the working
tree, not a tag comparison):

    AsteriskConferenceParticipantBindResult.php      7a35634a20cdea0c274a42aff950990d  (pod == repo)
    AsteriskConferenceParticipantBindingRetryJob.php bb0eb3fe33145e6ffcef1dabaea50032  (pod == repo)
    AsteriskConferenceParticipantBinder.php          2 × AsteriskConferenceParticipantBindResult::RETRYABLE
    AsteriskAriEventListener.php                     1 × AsteriskConferenceParticipantBindingRetryJob::dispatch
    web bundle index-DgzeMHo0.js                     "Canonical recovery returned a different participant" ×1
                                                     "Restoring the canonical conference participation" ×1
                                                     "Recovering..." ×1

The external media edge was re-verified after `k8s-apply` (which reverts it):

    /proc/1/cmdline: --interface=runtime/10.42.0.203!10.42.0.203
                     --interface=browser/10.42.0.203!127.0.0.1
    allow-rtpengine-media: 0 occurrences of utcp.dev/runtime-node

## Retry worker health

    WORKER: deployment/worker → pod worker-569b6689b8-sk2z5 (1/1 Running)
    QUEUE:  QUEUE_CONNECTION=redis, default queue,
            `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`
    READY:  yes — actively draining jobs at proof time

The retry job was never invoked manually.

## Natural login

    USER:         t3-s3b-t3s3b1785716804@utcp.local.test (tenant member)
    TENANT:       Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    CAPABILITIES: telephony.conferences.join / view, telephony.sessions.create_own /
                  view_own, telephony.signaling.issue_own / view_own,
                  tenant.memberships.view, tenant.roles.view

Access was restored through the repository's break-glass single-account
password-recovery command (`utcp:user-access:reset-password`, with an operator
reason recorded for audit); the browser then completed the ordinary login and
forced password-change flow from the real login page.

## Baseline

    TELEPHONY SESSION:     6ff4b710-48fc-4317-a4d8-9051594fb08e
    PARTICIPANT P:         a67f1dad-dc27-4e36-a576-d781cfddc6f0
    SIGNALING DESTINATION: sip:conf-a67f1dad-dc27-4e36-a576-d781cfddc6f0@sip.utcp.local.test
    CHANNEL A:             1786774491.11 (PJSIP/anonymous-0000000b)
    RUNTIME_CHANNEL_ID:    1786774491.11
    BRIDGE:                utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768 (1 chan)
    UI:                    Connected

Invariant satisfied: `CHANNEL A == runtime_channel_id == bridge member`. Media was
verified live (`outbound-rtp` 504 → 1756 packets on a later leg, ICE
`connected/connected`), and the call outlived the 30-second
`rtp_check_timeout`.

## Scenario 1 — refresh while Connected

    REFRESH:            2026-08-15T06:15:53.817Z (ordinary browser reload)
    DELETE COUNT:       0
    DESIRED STATE:      admitted throughout
    BOOTSTRAP:          06:15:56 → 06:16:24  state="active", recoverable=false
                        06:16:27 → 06:16:30  state="recoverable", recoverable=true
    OLD CHANNEL:        1786774491.11 — gone from Asterisk at 06:16:25
                        (`rtp_check_timeout`, i.e. the runtime's own policy)
    LOSS TIME:          runtime_channel_lost_at = 2026-08-15 06:16:26+00
    RECOVERABLE UNTIL:  2026-08-15 06:18:26 = loss + 120 s exactly
    RECOVERING UI:      "Recovering — Restoring the canonical conference
                        participation" from 06:15:56 until 06:16:30

While the old channel was still authoritative the client issued **0** replacement
admissions and **0** replacement INVITEs.

### Replacement admission

    ORIGINAL PARTICIPANT:  a67f1dad-dc27-4e36-a576-d781cfddc6f0
    RECOVERY PARTICIPANT:  a67f1dad-dc27-4e36-a576-d781cfddc6f0
    MATCH:                 YES
    ADMISSION COUNT:       1  (POST participants/self @06:16:26.863)
    SIGNALING DESTINATION: sip:conf-a67f1dad-…@sip.utcp.local.test (server-returned)
    INVITE COUNT:          1 logical transaction — CSeq 1 unauthenticated
                           @06:16:27.033 → 401 → ACK → CSeq 2 authenticated
                           @06:16:27.039 (the normal digest pair)

No second Join click, no duplicate participant, exactly 1 admitted participant.

### Final Scenario 1 invariant

    CHANNEL A:            1786774491.11
    CHANNEL B:            1786774587.12 (PJSIP/anonymous-0000000c)
    A != B:               YES
    RUNTIME_CHANNEL_ID:   1786774587.12   (participant updated_at 06:16:31)
    BRIDGE:               utcp-conf-68c7d252-… , bridgechans 0 → 1 at 06:16:32
    SYNTHETIC CHANNEL:    none — no Local/participant@utcp-conference-proof at any point
    UI:                   Connected at 06:16:33, held stable for 52 s

## Connected gating — decisive proof

    SIP ESTABLISHED:    06:16:27.1  (200 OK to the authenticated INVITE, ACK sent)
    BOOTSTRAP AT THAT TIME: state="recoverable", runtime_channel_id = NULL
    UI BEFORE BIND:     **Recovering** (06:16:27 and 06:16:30 samples)
    CANONICAL BIND TIME: 06:16:31 (runtime_channel_id set, bridgechans 0 → 1)
    UI AFTER BIND:      **Connected** (06:16:33)

A four-second window in which the SIP dialog was Established while the UI
correctly refused to claim Connected. `SIP Established alone → Connected` was not
observed at any point in the reproof.

## Binder retry — decisive proof

Observed on the Scenario 3 replacement leg.

    CHANNEL B:                1786775415.16 (PJSIP/anonymous-00000010)
    STASIS START:             ~06:30:15.6 (channel Up in Stasis at the 06:30:15 sample,
                              runtime_channel_id NULL, bridgechans 0)
    INITIAL BIND RESULT:      RETRYABLE
    RETRY JOB ENQUEUED:       yes — AsteriskConferenceParticipantBindingRetryJob
                              (dispatched with a 1 s delay)
    RETRY ATTEMPTS:           1
    RETRY JOB EXECUTION:      2026-08-15 06:30:18 RUNNING → 53.22 ms DONE (worker pod)
    CHANNEL LIVENESS CHECK:   passed — channel still present in Asterisk
    FINAL BIND RESULT:        BOUND
    RUNTIME_CHANNEL_ID:       1786775415.16 (set at 06:30:18)
    LOSS TIMESTAMP:           cleared to NULL by the same write
    BRIDGE:                   bridgechans 0 → 1 at 06:30:18, channel attached to
                              utcp-conf-68c7d252-…
    SECOND INVITE:            none
    SECOND PARTICIPANT:       none
    RETRY PATH LIVE EXERCISED: **YES**

Inference chain, stated explicitly: the listener dispatches
`AsteriskConferenceParticipantBindingRetryJob` **only** when the inline
`bind()` returns `RETRYABLE`, so the job's existence proves the initial result;
the job's execution timestamp (06:30:18) coincides exactly with the transition of
`runtime_channel_id` from NULL to `1786775415.16` and of the bridge from 0 to 1
members; and no second INVITE or participant exists in that window. The retry
therefore performed the binding and the bridge attachment for the same live
channel.

A second, independent retry execution was observed at **06:09:20** where the
liveness check correctly **stopped** the ladder: the replacement channel had
already been hung up, `inboundConferenceChannelExists` returned false, and the job
returned without re-dispatching. Both branches of the retry contract were
therefore exercised live.

The retry path was reached through ordinary runtime timing only. No readiness was
weakened, no observation faked, no label edited, no job forced.

## Scenario 2 — brief interruption, same dialog survives

    METHOD:            browser context taken offline for 8.010 s
                       (Playwright `context.setOffline`), then restored
    START:             2026-08-15T06:18:34.685Z
    END:               2026-08-15T06:18:42.695Z
    SESSION BEFORE:    established INVITE dialog, CSeq 2
    SESSION AFTER:     same dialog — no BYE, no re-INVITE, no new dialog
    CHANNEL BEFORE:    1786774587.12, bridged
    CHANNEL AFTER:     1786774587.12, bridged, continuously present in every
                       2-second sample across the interruption
    ADMISSION DELTA:   0
    INVITE DELTA:      0
    DELETE DELTA:      0
    UI:                Connected throughout — never entered Recovering

The only traffic in the window was an ordinary scheduled signaling-credential
renewal and re-REGISTER at 06:18:54. The dialog genuinely survived, so this is
reported as Scenario 2 and not reclassified.

## Scenario 3 — dialog dies, automatic replacement within grace

    METHOD:                 natural in-app navigation away from /dialer to
                            /dashboard (component unmount) and back within grace
    OLD CHANNEL:            1786775302.15 (PJSIP/anonymous-0000000f)
    CLIENT BYE ON UNMOUNT:  06:29:32.109 (tx BYE) — dialog ended locally
    DELETE ON UNMOUNT:      0  ← unmount did NOT release canonical participation
    LOSS:                   channel gone; runtime_channel_lost_at = 06:29:36+00
    DESIRED STATE:          admitted (unchanged)
    RECOVERABLE:            yes, recoverable_until = 06:31:36
    RETURN:                 06:30:15.130 (navigate back to /dialer)
    PARTICIPANT BEFORE:     aae5b947-f4a3-4817-9035-0625a176ab24
    PARTICIPANT AFTER:      aae5b947-f4a3-4817-9035-0625a176ab24
    PARTICIPANT MATCH:      YES
    ADMISSION COUNT:        1  (@06:30:15.294)
    INVITE COUNT:           1 logical transaction (CSeq 1 @06:30:15.471 →
                            CSeq 2 @06:30:15.475)
    NEW CHANNEL:            1786775415.16 (PJSIP/anonymous-00000010)
    RUNTIME_CHANNEL_ID:     1786775415.16 (bound at 06:30:18 by the retry — see above)
    BRIDGE:                 utcp-conf-68c7d252-… , bridgechans 0 → 1
    UI:                     Recovering @06:30:18 → Connected @06:30:21

## Scenario 4 — explicit Leave while Recovering

The Recovering condition was created naturally: join, then refresh while the old
channel was still authoritative.

    STATE AT LEAVE:      UI "Recovering", bootstrap state="active",
                         participant 4f69a526-99f8-4b3c-af5d-c0af8c750386
    LEAVE:               2026-08-15T06:35:18.040Z (one click)
    DELETE:              1 — DELETE /conferences/68c7d252-…/participants/self
                         @06:35:18.078
    RECOVERY CANCEL:     yes — 0 admissions and 0 INVITEs after the click
    PARTICIPANT FINAL:   removed / left; bootstrap participation = null
    FINAL STATE:         UI "Ready"
    CONTROLS:            conference list visible, **Join visible**, Leave gone,
                         Recovering banner gone
    ORPHAN CHANNELS:     none — 0 channels on the runtime, bridge 0 members

    POST-LEAVE TRIGGER:  (1) offline → online transition (4 s),
                         (2) full page refresh / remount
    ADMISSION DELTA:     0
    INVITE DELTA:        0
    DELETE DELTA:        0
    AUTO-REJOIN:         **NO**  — participation stayed null, UI stayed Ready with
                         Join available

This closes the secondary defect reported in the previous RH-2 proof (the view
stranded in `recovering` with no conference list and no Join control after a Leave
taken during recovery).

## Scenario 5 — abandoned recovery grace expiration

    LOSS:                          runtime_channel_lost_at = 2026-08-15 06:30:47+00
    RECOVERABLE UNTIL:             2026-08-15 06:32:47 = loss + 120 s exactly
    RECOVERABLE AFTER DEADLINE:    false — bootstrap returned state="expired",
                                   recoverable=false from the 06:32:47 sample
                                   onward, while the row was still desired_state
                                   ='admitted' (deadline disables recovery before
                                   the sweep converges the row)
    SWEEP:                         automatic every-minute TelephonyDomain sweep;
                                   row updated 06:33:02–06:33:04
    FINAL DESIRED STATE:           removed / left
    AUDIT:                         conference_participant.removed with
                                   {"reason":"recovery_grace_expired"} @06:33:02
    RETURN BOOTSTRAP:              natural page reload @06:33:44 →
                                   participation = null
    ADMISSION DELTA:               0
    INVITE DELTA:                  0
    DELETE DELTA:                  0
    UI:                            Ready, Join available, held for 45 s
    AUTO-REJOIN:                   **NO**

No timestamp was manipulated and the expiry command was never invoked by hand.

A second, independent expiry was observed with the browser entirely absent: loss
06:26:00 → sweep 06:28:02, i.e. two seconds after the deadline.

## Participant identity

Every recovery in this reproof reused the **same** canonical participant returned
by `participants/self`, and never more than one participant was admitted to the
conference at a time. No admission ever returned a different participant id.

## Runtime channel fencing

    Scenario 1:  A 1786774491.11 → B 1786774587.12
    Scenario 3:  1786775302.15 → 1786775415.16
    Scenario 4:  1786775628.17 (released by explicit Leave)

In every case the old channel's StasisEnd preceded the replacement channel's
creation, so the late-clear race (StasisEnd for A arriving after B binds) did not
arise naturally and is **not** claimed as proven here; the repository's automated
coverage remains the evidence for that branch. `clear()` remained channel-scoped
throughout and no clear ever nulled a newer channel's binding.

## Synthetic channel check

No `Local/participant@utcp-conference-proof` channel was created at any point.
Every conference leg in this reproof was a real inbound `PJSIP/anonymous-…`
channel arriving through Kamailio on the conference's bound RuntimeNode.

## Storm check

Measured across a 233-second window covering Scenario 4 and two further
join/refresh/recovery cycles:

    admissions:            3 (one per intended join or recovery)
    DELETE requests:       1 (the single explicit Leave)
    INVITEs:               6 transmissions = 3 logical transactions (401 + auth)
    signaling credentials: 4 (~1/min, matching the 120 s lifetime and the
                           expiry−30 s renewal schedule)
    REGISTERs:             8 transmissions = 4 logical (401 + auth)
    binder retry jobs:     2 in the entire session, both accounted for
    bridge attachments:    one per bound channel

No duplicate-participant storm, INVITE storm, binder retry storm, bridge attach
storm, credential storm, remove/re-admit loop, or scheduler storm. The stop rule
was not triggered.

## Failed proof steps

### PRODUCT_DEFECT — a runtime-initiated BYE on an established leg leaves the browser Connected forever and never triggers recovery

    CLASSIFICATION: IMPLEMENTATION (blocking for the RH-2 contract)

    SCENARIO:  unexpected SIP termination while the browser is alive and the
               dialog is established (RH-2's headline claim)
    EXPECTED:  the client converges out of Connected, enters Recovering, and
               performs one replacement admission plus one replacement INVITE
               within the 120-second grace
    ACTUAL:    the client stayed on "Connected" (with a Leave control) for the
               entire grace and beyond, issued 0 admissions, 0 INVITEs and 0
               DELETEs, and the participation silently expired and was swept

    OCCURRENCE 1
      BROWSER STATE:           Connected (false)
      SIP STATE:               inbound BYE captured on the wire —
                               06:30:45.572 rx BYE
      PARTICIPANT:             a67f1dad-dc27-4e36-a576-d781cfddc6f0
      DESIRED_STATE:           admitted → removed (by the sweep at 06:33:02)
      RUNTIME_CHANNEL_ID:      NULL from 06:30:47 onward
      RUNTIME_CHANNEL_LOST_AT: 2026-08-15 06:30:47+00
      BOOTSTRAP:               recoverable/true 06:30:48 → 06:32:43,
                               then expired/false from 06:32:47
      CHANNEL:                 none on the runtime for the whole window
      BRIDGE:                  0 members
      INVITE COUNT:            0
      ADMISSION COUNT:         0
      BINDER RESULT:           n/a (no replacement leg was ever offered)
      RETRY JOB:               n/a
      UI AT +2 min:            still "Connected"

    OCCURRENCE 2
      Same shape at 06:26:00 (loss) → 06:28:00 (deadline) → 06:28:02 (sweep),
      UI "Connected" throughout, 0 admissions, 0 INVITEs.

    CONTRASTING CONTROL: at 06:08:33 a leg established by an ordinary **Join**
      was terminated by the runtime under the same conditions and the client DID
      converge and auto-recover correctly (admission @06:08:39.645, replacement
      INVITE @06:08:39.814, Connected). In both failing occurrences the leg that
      died had been established by a **recovery**, not by a Join.

    ROOT CAUSE: not fully isolated. The behaviour is consistent with the
      post-recovery survival short-circuit in `beginRecovery()` — after
      `ensureRegistered()` the function returns early with
      `state='registered'; conferenceState='connected'` when
      `signalingClient.hasEstablishedConference()` is still true, so a stale
      session object surviving the inbound BYE would produce exactly the observed
      "Connected, no INVITE, no retry, no timer" state. This is stated as the most
      consistent explanation, **not** as proven.

    AFFECTED FILE/METHOD:
      apps/web/src/views/ReferenceDialerView.vue
        — `updateCallState()` `terminated` branch → `beginRecovery()`
        — `beginRecovery()` survival short-circuit on
          `signalingClient.hasEstablishedConference()`

Not patched, per task scope.

### PRODUCT_DEFECT — a transient degraded RuntimeNode observation reports canonical participation as `expired` inside the grace, aborting an established recovery

    CLASSIFICATION: IMPLEMENTATION (blocking, intermittent / timing-dependent)

    SCENARIO:  Scenario 1, first run
    EXPECTED:  while `now < runtime_channel_lost_at + 120 s`, bootstrap reports
               `recoverable` and the client completes recovery
    ACTUAL:    bootstrap reported `state:"expired", recoverable:false` at
               06:09:15.94 with `recoverable_until:"06:11:13Z"` — a deadline two
               minutes in the future — and the client, treating a non
               {active, awaiting_runtime, recoverable} state as terminal,
               terminated the just-established replacement leg and abandoned
               recovery. Twelve seconds later the server reported
               `recoverable:true` again, but no retry was ever scheduled and the
               participation was swept at 06:11:1x.

    BROWSER STATE:           Recovering → Ready (permanently)
    SIP STATE:               replacement dialog Established, then locally
                             terminated ~2 s later
    PARTICIPANT:             682f83af-7293-4843-bd88-91202013defd
    DESIRED_STATE:           admitted (until the grace sweep removed it)
    RUNTIME_CHANNEL_ID:      NULL
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 06:09:13+00
    BOOTSTRAP:               active/false → **expired/false** (06:09:15) →
                             recoverable/true (06:09:28) → swept
    CHANNEL:                 1786774154.10 present at 06:09:15.84, gone by
                             06:09:17.99
    BINDER RESULT:           RETRYABLE
    RETRY JOB:               ran at 06:09:20, found the channel already gone,
                             stopped correctly
    BRIDGE:                  never attached
    INVITE COUNT:            1 (correct — the client did not storm)

    ROOT CAUSE (proven): `AsteriskAriEventNormalizer` maps every ARI event it does
      not explicitly recognise to a `runtime_node` observation with
      `observed_state = 'degraded'` (`default => 'degraded'`, and the matching
      `observation_type` default `runtime.capability.observed`).
      `ProjectionService` writes that value straight into
      `runtime_nodes.observed_state`. Ordinary call events therefore degrade the
      canonical node between the 15-second readiness heartbeats. Measured on the
      conference node over a 40-minute window: **124 `runtime.capability.observed`
      / degraded** against **158 `runtime.readiness.observed` / ready**, produced
      by `ChannelCreated`, `ChannelStateChange`, `ChannelVarset`,
      `ChannelDialplan`, `ChannelHangupRequest` — and `StasisStart` itself.
      `TelephonyDomainService::isRecoverableParticipation()` requires
      `runtime_nodes.observed_state = 'ready'`, so during any such window the
      canonical participation is reported `expired` even though the grace deadline
      has not passed. The same transient is what made the binder return RETRYABLE
      in the run above, and it is the same condition behind the blocker recorded
      in the previous RH-2 proof.

    AFFECTED FILE/METHOD:
      apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriEventNormalizer.php
        — `default => 'degraded'` state mapping for unrecognised ARI events
      apps/api/app/TelephonyDomain/TelephonyDomainService.php
        — `isRecoverableParticipation()` readiness predicate, and
          `currentParticipation()` reporting `expired` whenever it is false
          rather than distinguishing "grace deadline passed" from "node not
          currently observed ready"
      apps/web/src/views/ReferenceDialerView.vue
        — treats any non {active, awaiting_runtime, recoverable} state as
          terminal and stops recovery with no re-check

Not patched, per task scope.

### Minor finding — the Recovering banner can persist after a successful recovery

    CLASSIFICATION: IMPLEMENTATION (non-blocking, cosmetic/consistency)

After one successful refresh-recovery the page simultaneously displayed the
view-level banner "Recovering — Restoring the canonical conference participation"
and the conference-level "Connected" with a working Leave control, while the
canonical state was correctly bound. The conference controls were fully usable, so
this is a display inconsistency rather than a functional failure: the
`updateCallState('connected')` path sets `conferenceState` but does not return the
view-level `state` from `recovering` to `registered`.

    AFFECTED FILE: apps/web/src/views/ReferenceDialerView.vue — `updateCallState()`

## Orphan SIP protection

Observed naturally in the first Scenario 1 run: the replacement dialog reached
Established, the canonical participation was then reported terminally ineligible
(incorrectly — see the defect above), and the client **terminated the local SIP
dialog within ~2 seconds**. Channel `1786774154.10` was present at 06:09:15.84 and
gone by 06:09:17.99, leaving **no live orphan channel parked in Stasis**, and the
UI returned to Ready. The protection itself works; the trigger that fired it was a
mis-reported canonical state. This condition was not manufactured — no database
mutation was used.

## Divergences

* **Media edge omitted after `k8s-apply` (mine, corrected).** `make k8s-apply`
  reverts the external media edge; I did not immediately re-run
  `make media-edge-apply`. Every call in the first proof window therefore lost its
  browser→runtime RTP path and Asterisk terminated each leg after exactly 30 s
  with `rtp_check_timeout`. Classified **DEPLOYMENT (self-inflicted, corrected)**.
  `make media-edge-apply` was run, the two rtpengine interfaces were verified, and
  **every scenario was then re-run from a fresh baseline**. All Scenario 1–5
  results reported above come from the corrected environment. The first-run
  observations are retained only where they are independent of media (the
  `expired` misreport, the retry-liveness branch, orphan-SIP protection).
* **Playwright `setOffline` does not interrupt WebRTC.** A 42-second offline
  period left the media path and therefore the runtime leg fully alive, so it
  could not serve as the Scenario 3 dialog-loss method. Reported honestly rather
  than reclassified: the 8-second offline test is recorded as Scenario 2
  (dialog genuinely survived) and Scenario 3 used natural in-app navigation away
  and back instead.
* **Browser context replacement.** After the tab was closed, the MCP server
  created a new browser context without the granted microphone permission, and
  legs established from it carried no audio. Classified **PROOF_HARNESS**; a fresh
  instrumented browser session was established, media was verified by
  `outbound-rtp` packet counts, and the affected runs were repeated.
* **Telephony session expiry during the last cycle.** The 30-minute telephony
  session issued at 06:07:47 expired at 06:37:47, so the final participant was
  removed with `reason: session_expired`. Expected behaviour, unrelated to RH-2.

None of these invalidate the Scenario 1–5 results or the retry-path proof.

## RH-2 acceptance checklist

    [x] refresh performs no canonical Leave
    [x] old channel prevents premature replacement
    [x] old channel loss begins exact RH-1 grace
    [x] same participant is reused
    [x] one logical replacement INVITE occurs
    [x] replacement uses normal conf-* destination
    [x] replacement channel becomes canonical runtime_channel_id
    [x] replacement channel joins canonical bridge
    [x] Connected is not shown before canonical binding
    [x] same-dialog interruption causes zero replacement INVITEs
    [x] dead-dialog interruption auto-recovers within grace
    [x] explicit Leave while Recovering reaches Ready cleanly
    [x] explicit Leave prevents later auto-rejoin
    [x] grace deadline disables recovery exactly
    [x] scheduler later removes abandoned participation
    [x] returning after expiry does not auto-rejoin
    [x] no duplicate participant/INVITE/binder storm occurs
    [x] V0 remains COMPLETE
    [x] RT-1A remains COMPLETE / LIVE PROVEN

    RETRY PATH LIVE EXERCISED: YES

    [ ] runtime-initiated termination of an established leg converges to
        Recovering and auto-recovers            ← FAILS (defect 1)
    [ ] canonical participation never reports a terminal state inside the grace
                                                 ← FAILS (defect 2)

## Environment state at completion

    admitted participants:  0
    runtime channels:       0
    conference bridge:      0 members
    conference:             68c7d252-… open / ready
    runtime node:           d4539d79-… active / ready
    browser:                logged out through the normal Log out control

## Code changes

    None.

## RH status

    RH-0: COMPLETE
    RH-1: IMPLEMENTED / TESTED — re-confirmed live: the grace deadline is exactly
          loss + 120 s, the deadline disables recovery before the sweep runs, and
          the every-minute sweep converges abandoned participation with
          `reason: recovery_grace_expired` and no operator action
    RH-2: FOUND BLOCKER — all five scenarios and the RH-2B retry path pass; two
          isolated defects outside the scenario scripts break the contract
    RH-3: NOT STARTED
