# RH-2 — Natural Browser Interruption / Refresh / Auto-Rejoin Live Proof

## Verdict

    RH_2_BROWSER_NETWORK_AUTO_REJOIN_NATURAL_LIVE_PROOF_FOUND_BLOCKER

The RH-2 **recovery corridor** works: a refresh while Connected performs no
canonical Leave, the view enters Recovering, the server correctly withholds
recovery while the old channel is still bound, then grants it, and the browser
re-admits the **same participant** and places **one** replacement INVITE on the
canonical destination without a second Join click.

The recovery **outcome** does not complete: the replacement leg established at
the SIP layer and the UI showed Connected, but the binder never bound it —
`runtime_channel_id` stayed null and the channel never rejoined the conference
bridge. The grace sweep then removed the participant while that channel was still
live and the UI still read Connected.

Scenarios 2–5 were not run: with the replacement leg unable to bind, every
downstream scenario would have measured the same defect rather than its own
property.

## Method

Evidence-only. No application source modified. Deployment through canonical
targets only. Natural Playwright login from the real login page; no preset
storage, injected cookie, copied session, database/Redis session, or
authentication bypass. No database row was hand-edited, no timestamp
manipulated, no expiration command run manually, and `rtp_timeout` was not
changed. No secret appears below.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing working tree including the RH-1 + RH-2 packets
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Canonical environment

    API:            utcp/api:0.1.0-k1-dev @sha256:90436f25…
    WEB:            utcp/web:0.1.0-k1-dev @sha256:272f6341…
    ASTERISK:       utcp/asterisk-ari:0.1.0-k1-dev @sha256:b2d71848…
    RUNTIME NODE:   d4539d79-432d-48dc-8def-d52e0d0ca5e2 (active / ready, sip/udp :5060)
    CONFERENCE:     68c7d252-2203-4f2a-9b81-4d87d1294768 (open / ready, binding active)
    DEPLOYMENT FRESH: yes

Lifecycle: `k8s-image-build` → `k8s-image-push` → `k8s-apply` (migration job
Complete, `2026_08_15_150000_add_runtime_channel_lost_at_to_conference_participants`
DONE) → rollout restart of every `utcp-platform` Deployment → `media-edge-apply`.

Content-verified in the running pods:

    API: RECOVERABLE_PARTICIPATION_GRACE_SECONDS = 120 present;
         'participation' present in ReferenceDialerController
    WEB: 'Recovering' present in the deployed bundle
    DB:  conference_participants.runtime_channel_lost_at (timestamptz, nullable)

Server authority confirmed present: `TelephonyDomainService::currentParticipation()`
returning `{participant_id, conference_id, state, recoverable, recoverable_until}`,
`expireRecoverableParticipants()`, and
`Schedule::command('telephony-domain:expire-recoverable-participants')->everyMinute()->withoutOverlapping()`.

## Natural login

    USER:         t3-s3b-t3s3b1785716804@utcp.local.test (tenant-member)
    TENANT:       Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    CAPABILITIES: telephony.conferences.join / view, telephony.sessions.create_own /
                  view_own, telephony.signaling.issue_own / view_own,
                  tenant.memberships.view, tenant.roles.view

## Baseline conference

    TELEPHONY SESSION:     3ad9edc7-66a3-4aab-a3df-3dde148f2039
    PARTICIPANT:           1b5f01f9-b32f-424d-857f-4d68ea518305
    SIGNALING DESTINATION: sip:conf-1b5f01f9-b32f-424d-857f-4d68ea518305@sip.utcp.local.test
    PJSIP CHANNEL A:       1786770023.6  (PJSIP/anonymous-00000006)
    RUNTIME_CHANNEL_ID:    1786770023.6
    BRIDGE:                utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768
    UI:                    Connected @05:00:24.335Z

Baseline invariant satisfied — the `core show channels concise` row carried the
bridge id and `runtime_channel_id` equalled the channel id.

### Environmental note on the first baseline attempt

An earlier baseline attempt at 04:57:52 failed for an environmental reason
created by this proof's own rollout: restarting `asterisk-ari-events` reset the
ARI WebSocket at 04:57:30, which destroyed the Stasis application
(`Deactivating Stasis app 'utcp-t0-observation'`), and it was only re-created at
04:58:13. The INVITE landed inside that window and Asterisk logged
`Stasis app 'utcp-t0-observation' doesn't exist`, hanging the channel up 46 ms
after ACK. Classified **DEPLOYMENT/ENVIRONMENT**, not an RH defect. Notably the
RH-2 client behaved correctly under it: the unexpected runtime BYE performed **no**
canonical Leave and the participant remained `admitted`. The baseline was
re-established after the Stasis app was confirmed live.

## Scenario 1 — refresh while Connected

    REFRESH:                    2026-08-15T05:00:50.432Z (normal browser reload)
    DELETE PARTICIPANT REQUEST: **NONE** — 0 DELETEs across the whole refresh
    PARTICIPANT DESIRED STATE:  admitted (unchanged)

    BOOTSTRAP (first after reload):
      { participant_id: 1b5f01f9-…, conference_id: 68c7d252-…,
        state: "active", recoverable: false, recoverable_until: null }
      ← old channel A still bound, replacement correctly NOT permitted

    RECOVERING STATE: UI showed "Recovering — Restoring the canonical conference
                      participation"; replacement admissions 0 and replacement
                      INVITEs 0 while state was "active"

    OLD CHANNEL A FINAL: StasisEnd received 05:00:55 for 1786770023.6
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 05:00:55+00
    TIME FROM REFRESH TO LOSS: ~5 s (page unload dropped the WSS transport, so the
                      dialog ended well before the 30 s rtp_timeout)

    BOOTSTRAP (after loss):
      { state: "recoverable", recoverable: true,
        recoverable_until: "2026-08-15T05:02:55.000000Z" }   ← loss + exactly 120 s

### Replacement

    ORIGINAL PARTICIPANT:  1b5f01f9-b32f-424d-857f-4d68ea518305
    RECOVERY PARTICIPANT:  1b5f01f9-b32f-424d-857f-4d68ea518305
    MATCH:                 **YES** — same canonical participant reused
    PARTICIPANTS/SELF COUNT: 1  (POST @05:00:56.850Z)
    SIGNALING DESTINATION: sip:conf-1b5f01f9-…@sip.utcp.local.test  (server-returned)
    REPLACEMENT INVITE:    1 logical transaction — CSeq 1 unauthenticated
                           @05:00:56.971Z → 401 → ACK → CSeq 2 authenticated
                           @05:00:56.974Z (the normal digest pair, not two attempts)
    CHANNEL B:             1786770057.7 (PJSIP/anonymous-00000007), state Up,
                           in Stasis with argument conf-1b5f01f9-…
    CHANNEL CHANGED:       YES — A 1786770023.6 ≠ B 1786770057.7
    UI:                    Connected, no second Join click
    ADMITTED PARTICIPANTS FOR THE CONFERENCE: exactly 1

    RUNTIME_CHANNEL_ID:    **null**            ← FAIL
    BRIDGE MEMBER:         **no bridge**       ← FAIL

The client half of Scenario 1 passed in full. The server-side binding did not
occur.

## Failed proof step

### PRODUCT_DEFECT — the recovery replacement leg is never bound, and the UI reports a false Connected until the grace sweep silently removes the participant

    CLASSIFICATION: IMPLEMENTATION (blocking)

    SCENARIO: Scenario 1 — refresh while Connected
    EXPECTED: StasisStart for the replacement channel binds it —
              runtime_channel_id = channel B, runtime_channel_lost_at cleared,
              channel B added to utcp-conf-<conferenceId>, participant observed joined
    ACTUAL:   channel B stayed Up in Stasis for 77+ s with runtime_channel_id null,
              runtime_channel_lost_at still 05:00:55, and no bridge membership;
              at 05:03:15 the grace sweep set desired_state='removed' /
              observed_state='left' **while channel B was still alive**, and the
              browser still displayed "Connected" with a Leave control at 05:03:30

    BROWSER STATE:          Connected (false)
    BOOTSTRAP PARTICIPATION: last observed
                            { state: "recoverable", recoverable: true,
                              recoverable_until: "2026-08-15T05:02:55.000000Z" }
    PARTICIPANT:            1b5f01f9-b32f-424d-857f-4d68ea518305
    DESIRED_STATE:          admitted → removed (by the sweep at 05:03:15)
    RUNTIME_CHANNEL_ID:     null throughout
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 05:00:55+00 (never cleared)
    SIP SESSION:            Established (200 OK, ACK sent)
    INVITE COUNT:           1 logical transaction
    PJSIP CHANNEL:          1786770057.7, Up, Stasis(utcp-t0-observation,conf-1b5f01f9-…)
    BRIDGE:                 utcp-conf-68c7d252-… — channel B never a member

Evidence that the event reached the binder and was silently refused:

* the canonical receipt exists —
  `asterisk.ari.channel.stasis_start` @05:01:00 with
  `{"channel_id":"1786770057.7","channel_name":"PJSIP_anonymous-00000007", …}`;
* the deployed listener dispatches `bind()` on `StasisStart`;
* **no** `asterisk inbound conference participant binding rejected` line appears
  in the `asterisk-ari-events` log for the window, so no exception was thrown —
  `bind()` returned `false` on its lookup predicate;
* replaying the binder's full join predicate read-only **after** the event
  (05:01:5x) returns the participant successfully
  (`open | active | active/ready | active | t`), i.e. the predicate is satisfiable
  and was only transiently false at event time.

    ROOT CAUSE: `AsteriskConferenceParticipantBinder::bind()` is a one-shot
      reaction to StasisStart with no retry. When its predicate is momentarily
      false at event time, the inbound channel is never bound and nothing ever
      re-attempts it: `ConferenceParticipantReconciler` sees
      `runtime_channel_id IS NULL` and parks at
      `conference_participant_awaiting_inbound_signaling_leg`, which waits for a
      *new* inbound leg and never adopts the live one already sitting in Stasis.
      The most probable transient in this run is the RuntimeNode observation —
      the node's `observed_at` advanced to 05:01:46, i.e. the next successful
      observation landed **after** the 05:01:00 StasisStart, so
      `runtime_nodes.observed_state = 'ready'` was plausibly not yet true when the
      binder evaluated it. The defect is the absent retry/adoption path, not the
      specific transient.

    AFFECTED FILES:
      apps/api/app/RuntimeAdapters/Asterisk/AsteriskConferenceParticipantBinder.php
        (bind(): silent false with no retry, and no reconciliation of an
         already-present inbound channel)
      apps/api/app/TelephonyDomain/Reconciliation/ConferenceParticipantReconciler.php
        (awaiting_inbound_signaling_leg waits for a new leg rather than adopting a
         live unbound one)
      apps/web/src/views/ReferenceDialerView.vue
        (treats SIP Established as Connected without confirming canonical binding)

Not patched, per task scope.

### Secondary finding — the view can strand itself in `recovering` with no Join control

    CLASSIFICATION: IMPLEMENTATION (non-blocking, UI recoverability)

After an explicit Leave taken while the view-level `state` was `recovering`, the
canonical release succeeded (participant `removed`/`left`, 0 admitted remaining)
and `conferenceState` returned to `ready`, but the view-level `state` remained
`recovering`. The available-conference block renders only when
`state === 'registered'`, so the page displayed "Recovering — Restoring the
canonical conference participation" with **no conference list and no Join
button**; only navigating away and back restored it. Server state was correct
throughout, so this is purely a client state-transition gap.

    AFFECTED FILE: apps/web/src/views/ReferenceDialerView.vue

### Inconclusive reproduction attempt

A second attempt at 05:03:44 did not reproduce or refute the binding defect: the
authenticated INVITE for participant `ef413625-…` received no final response, and
after ~32 s the client's existing failed-establishment compensation removed the
participant and showed "Needs attention" with Join available — the documented
behaviour, working correctly. Recorded for completeness; it is not evidence about
the binder.

## Scenarios not run

    Scenario 2 — brief interruption, same dialog survives:  NOT RUN
    Scenario 3 — dialog loss, replacement within grace:     NOT RUN
    Scenario 4 — explicit Leave prevents auto-rejoin:       NOT RUN as a scenario
    Scenario 5 — grace expiration / abandoned participation: NOT RUN as a scenario

Reason: the replacement leg cannot bind, so each of these would have measured the
same defect rather than its own property. Partial evidence was nonetheless
observed naturally and is reported honestly rather than claimed as scenario
passes:

* **Explicit Leave** was exercised twice as canonical cleanup. Both times the
  DELETE was issued, the participant became `removed`/`left`, recovery was
  cancelled, and no auto-rejoin followed. This is consistent with Scenario 4 but
  was not run with its required post-Leave recovery triggers.
* **Grace expiration** was observed exactly: `runtime_channel_lost_at` 05:00:55 →
  `recoverable_until` 05:02:55 (exactly +120 s) → the every-minute sweep converged
  the participant to `removed`/`left` at 05:03:15, with no operator action and no
  manual command. The deadline/sweep separation the task asks about is visible:
  the deadline passed at 05:02:55 while the row was still `admitted`, and physical
  convergence followed at the next sweep. This is the correct RH-1 behaviour —
  but here it fired against a *live* channel, which is what makes the binding
  defect user-visible.

## Duplicate trigger evidence

    TRIGGERS:        page load after refresh, plus bootstrap polling (~2 s cadence)
    ADMISSION COUNT: 1
    INVITE COUNT:    1 logical transaction (401 + authenticated pair)

No duplicate participant, no duplicate admission, no INVITE storm, no bind/clear
storm, no credential storm, and no scheduler storm was observed. The single-flight
recovery coordinator held.

## Participant identity

Confirmed: the recovery reused participant `1b5f01f9-b32f-424d-857f-4d68ea518305`
— identical to the baseline participant — and exactly one admitted participant
existed for the conference throughout.

## Runtime channel fencing

    A:                    1786770023.6
    B:                    1786770057.7
    LATE A CLEAR EFFECT:  none observed. `clear()` remains channel-scoped and the
                          StasisEnd for A at 05:00:55 preceded B's creation, so the
                          A-after-B race did not arise naturally in this run and is
                          not claimed as proven here.

## Signaling destination

    sip:conf-1b5f01f9-b32f-424d-857f-4d68ea518305@sip.utcp.local.test

Taken from the `participants/self` response and used verbatim by the replacement
INVITE. No cached RuntimeNode endpoint was used.

## Synthetic channel check

No `Local/participant@utcp-conference-proof` channel was created for the
self-admission participant at any point. The replacement leg was a real inbound
`PJSIP/anonymous-00000007` channel.

## Stop rule

Invoked. After the blocker was captured with complete evidence and one
inconclusive reproduction, the active proof was stopped rather than continued to
accumulate output. Cleanup was performed through the normal UI and the
environment was left clean: 0 admitted participants, 0 channels on the runtime,
conference `open`/`ready`, RuntimeNode `active`/`ready`, browser logged out.

## V0 preservation

    COMPLETE / UNCHANGED — the V0C6 conference and RuntimeNode fixtures are intact
    and healthy; no V0 signaling, media, or conference behaviour was modified.

## RT-1A preservation

    COMPLETE / LIVE PROVEN / UNCHANGED — not exercised or altered by this proof.

## Code changes

    None.

## RH status

    RH-0: COMPLETE
    RH-1: IMPLEMENTED / TESTED — recoverable participation, the 120 s grace, and
          automatic expiration behaved exactly as contracted in live conditions
          (recoverable_until = loss + 120 s to the second; every-minute sweep
          converged the abandoned participant with no operator action)
    RH-2: FOUND BLOCKER — the recovery corridor is correct up to and including the
          replacement INVITE; the replacement leg is not bound
    RH-3: NOT STARTED
