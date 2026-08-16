# RH-2D — Final Runtime-BYE Natural Live Reproof

## Verdict

    RH_2D_RUNTIME_BYE_NATURAL_LIVE_REPROOF_PASSED

The remaining RH-2 blocker is closed. A runtime-originated BYE on a
**recovery-established leg with large orchestration drift** was accepted by the
client, the browser left Connected, entered Recovering, performed **no** canonical
participant DELETE, and completed one canonical replacement recovery that bound
and bridged. The Recovering banner is absent after recovery and the outer view
state is consistent.

The identical situation on the pre-fix build (07:39:06 earlier the same day) left
the UI on Connected for 97 s with 0 admissions and 0 INVITEs.

## Method

Evidence-only. No application source, schema, manifest, or configuration file was
modified. Deployment through canonical Make targets only. Natural Playwright login
from the real login page; no preset storage state, injected cookie, copied
session, database/Redis session, or authentication bypass. No database row was
written by hand, no timestamp manipulated, no scheduler or job invoked manually,
no SIP injected, no Asterisk/Kamailio/dialplan/RTPengine configuration touched, no
Leave clicked before the decisive evidence was captured. Browser instrumentation
was passive logging only (`fetch` and `WebSocket.send`/`message` wrappers) and
changed no application behaviour.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree including the RH-1/RH-2/RH-2B/RH-2C/RH-2D packets
    commit/push:   none created, not pushed

## Canonical Environment

    API:          utcp/api  @sha256:2c53a633a429e6dbbefd37e767e80be8c32448970610608b44c0c012497dfc39
    WEB:          utcp/web  @sha256:9744f594a7debd66b6e833c2c837d53a59e1d9ed07b5604f616b8a2c4e66dfcc
    ASTERISK:     utcp/asterisk-ari @sha256:b2d7184892f9a133d7f0909347d8ae904d250111d3a362569b45c3e4da04dc69
    WORKER:       utcp/api  @sha256:2c53a633… (deployment/worker, same image as API)
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready, sip/udp :5060 enabled
    CONFERENCE:   68c7d252-2203-4f2a-9b81-4d87d1294768 — open / ready, binding active
    DEPLOYMENT FRESH: yes

Lifecycle: `make k8s-image-build` → `make k8s-image-push` → `make k8s-apply` →
`kubectl rollout restart` of the ten `utcp-platform` Deployments →
`make media-edge-apply`. The Stasis application `utcp-t0-observation` was
confirmed re-registered (08:18:07) before any proof traffic.

### Content verification of the RH-2D correction in the deployed bundle

The deployed asset `/usr/share/nginx/html/assets/index-C7Ead1hJ.js` contains the
minified signaling-attempt fence, byte-identical to the locally built dist:

    inviting`)_=r;else if(r!==_)return

which is `if (nextState === 'inviting') activeConferenceInviteAttemptId = attemptId
else if (attemptId !== activeConferenceInviteAttemptId) return` — the RH-2D
separation of the SIP session generation from the view/recovery orchestration
generation. Identifier names are minified away, so the fence expression itself is
the content signal.

## Natural Login

    USER:         t3-s3b-t3s3b1785716804@utcp.local.test (tenant member)
    TENANT:       Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    CAPABILITIES: telephony.conferences.join / view, telephony.sessions.create_own /
                  view_own, telephony.signaling.issue_own / view_own,
                  tenant.memberships.view, tenant.roles.view

Access was restored through the repository's break-glass single-account
password-recovery command with an operator reason recorded for audit; the browser
then completed the ordinary login and forced password-change flow from the real
login page.

## Baseline

    TELEPHONY SESSION:                9317adcd-f9da-4965-a796-49496f0caea0
    PARTICIPANT:                      37a0353c-7cef-4e7e-b378-27a7dab0176e
    SIGNALING DESTINATION:            sip:conf-37a0353c-7cef-4e7e-b378-27a7dab0176e@sip.utcp.local.test
    SIGNALING ATTEMPT:                1 (client `inviteAttempt` after the Join INVITE)
    ACTIVE CONFERENCE INVITE ATTEMPT: 1 (adopted from the `inviting` callback)
    CONFERENCE ATTEMPT:               1 (join(); no beginRecovery had run on this page)
    CHANNEL A:                        PJSIP/anonymous-00000019 (Join leg; replaced
                                      during drift setup — Asterisk disconnected it at
                                      08:31:47 by rtp_check_timeout once the reload
                                      stopped its media)
    UI:                               Connected

Join at 08:30:49.191: 1 admission (`08:30:49.251`), 1 logical INVITE
(`08:30:49.455` CSeq 1 unauth → 401 → ACK → `08:30:49.459` CSeq 2 auth → 200 OK
`08:30:49.552` → ACK).

## Orchestration Drift

Drift was produced through the smallest existing natural path — one ordinary
browser refresh, which drives the canonical recovery corridor. Refresh at
**08:31:17.930**.

    SIGNALING ATTEMPT BEFORE:  1  (previous page instance)
    CONFERENCE ATTEMPT BEFORE: 1

The reload constructs a fresh signaling client (`inviteAttempt` restarts at 0) and
a fresh view (`conferenceAttempt` restarts at 0). While the old channel was still
authoritative, bootstrap reported `state: active` from **08:31:21 to 08:31:45**
and the client polled `beginRecovery()` every `RECOVERY_RETRY_DELAY_MS = 2_000`
without inviting — each entry advancing only the view counter. Loss was then
stamped and the replacement admission and INVITE followed.

    ADMISSION:  08:31:49.120  (1)
    INVITE:     08:31:49.284 CSeq 1 → 401 → ACK → 08:31:49.292 CSeq 2 → 200 OK 08:31:49.390
    UI:         Recovering (banner Y) 08:31:21 → 08:31:54, Connected (banner n) 08:31:57
    DELETE:     0

    SIGNALING ATTEMPT AFTER:                1 (this client instance's first invite)
    ACTIVE CONFERENCE INVITE ATTEMPT AFTER: 1
    CONFERENCE ATTEMPT AFTER:               ≈15 (reconstructed: ~14 non-inviting
                                            beginRecovery entries across the 08:31:21–
                                            08:31:48 polling window, plus one
                                            post-INVITE re-check at 08:31:51)
    SAME SIP SESSION:                       the leg established at 08:31:49 is the
                                            session carried into the decisive event;
                                            it was not replaced again before the BYE

The counter values are reconstructed from the deployed code's deterministic
behaviour and the observed 2-second polling cadence — module-local variables are
not readable from the page. The **directly observed** fact that matters is the
callback acceptance below, which is only possible if the fence no longer compares
against `conferenceAttempt`.

    CHANNEL (drifted leg): 1786782709.26 (PJSIP/anonymous-0000001a)
    RUNTIME_CHANNEL_ID:    1786782709.26
    BRIDGE:                utcp-conf-68c7d252-… , bridgechans = 1

## Runtime BYE — decisive event

The leg carried media and therefore would not have timed out on its own. The
media plane was interrupted so that the runtime's **own committed
`rtp_check_timeout` policy** would terminate the leg — the same natural
runtime-originated BYE behaviour observed in the original failures. The
interruption was a plain pod restart of `deployment/rtpengine`
(`kubectl rollout restart`, 08:32:39.5 → ready 08:32:41.7), which changes no
configuration; the rendered media-edge interfaces were re-verified afterwards
(`--interface=browser/10.42.0.214!127.0.0.1`). No SIP was injected, no Asterisk
setting altered, no database row touched, and participation intent was never
modified.

    ASTERISK:  2026-08-15 08:33:11 NOTICE res_pjsip_sdp_rtp.c:146 rtp_check_timeout:
               Disconnecting channel 'PJSIP/anonymous-0000001a' for lack of audio
               RTP activity in 30 seconds

    BYE RECEIVE TIME:                 2026-08-15T08:33:11.401Z  (rx BYE, CSeq 6053)
    CLIENT RESPONSE:                  200 OK @08:33:11.407
    SIP SESSION STATE BEFORE:         Established
    SIP SESSION STATE AFTER:          Terminated
    SIGNALING CLIENT:                 inviter cleared, inviteEstablished = false
    CALLBACK ATTEMPT ID:              1
    ACTIVE CONFERENCE INVITE ATTEMPT: 1
    CONFERENCE ATTEMPT:               ≈15 (drifted, unrelated to the SIP session)
    CALLBACK ACCEPTED:                **YES**

Acceptance is proven behaviourally: the only code path that moves the UI out of
Connected on an unexpected termination is `updateCallState`'s `terminated` branch
→ `beginRecovery()`. The UI left Connected and entered Recovering within one
sample of the BYE, and a canonical recovery followed, so the callback was not
discarded despite the large `conferenceAttempt` drift.

## Recovery Transition

    CONNECTED CLEARED: 08:33:12  (first sample after the 08:33:11.401 BYE)
    DELETE participants/self COUNT: **0**
    BEGIN RECOVERY:    yes — UI "Recovering", banner rendered 08:33:12 → 08:33:21
    UI:                Recovering

## Canonical Recovery State

    PARTICIPANT:             37a0353c-7cef-4e7e-b378-27a7dab0176e
    DESIRED STATE:           admitted (unchanged across the whole event)
    RUNTIME_CHANNEL_ID:      NULL (08:33:14 → 08:33:18)
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 08:33:14+00
    RECOVERABLE:             true (bootstrap at 08:33:15 and 08:33:18)
    RECOVERABLE UNTIL:       2026-08-15 08:35:14 = loss + 120 s exactly

## Replacement

    ORIGINAL PARTICIPANT:  37a0353c-7cef-4e7e-b378-27a7dab0176e
    RECOVERY PARTICIPANT:  37a0353c-7cef-4e7e-b378-27a7dab0176e
    MATCH:                 YES
    ADMISSION COUNT:       1  (POST participants/self @08:33:15.569)
    SIGNALING DESTINATION: sip:conf-37a0353c-7cef-4e7e-b378-27a7dab0176e@sip.utcp.local.test
                           (server-returned; used verbatim)
    INVITE COUNT:          1 logical transaction — CSeq 1 unauthenticated
                           @08:33:15.736 → 401 → ACK → CSeq 2 authenticated
                           @08:33:15.740 → 100 trying → 200 OK @08:33:15.871 → ACK
    CHANNEL B:             1786782795.27 (PJSIP/anonymous-0000001b)
    RUNTIME_CHANNEL_ID:    1786782795.27  (bound by 08:33:21)
    BRIDGE:                utcp-conf-68c7d252-… , bridgechans 0 → 1 at 08:33:21

    CHANNEL A != CHANNEL B: 1786782709.26 != 1786782795.27
    SYNTHETIC CHANNEL:      none — no Local/participant@utcp-conference-proof at any point

## Connected Gating

    SIP ESTABLISHED:      08:33:15.871 (200 OK to the authenticated INVITE)
    CANONICAL BIND:       ~08:33:19–08:33:21 (runtime_channel_id set, bridgechans 0 → 1)
    UI BEFORE BIND:       **Recovering** — samples at 08:33:15 and 08:33:18 show
                          Recovering with banner rendered while
                          runtime_channel_id was NULL
    UI AFTER BIND:        **Connected** at 08:33:24

The RH-2B invariant holds: SIP Established alone did not produce Connected.

## Recovering Banner

Checked 2 minutes after recovery (08:35:22):

    CONFERENCE STATE:  Connected (Leave control present)
    OUTER STATE:       REGISTERED — "The browser received a successful SIP
                       registration response"
    RECOVERING BANNER: **ABSENT**
    CONFERENCE ERROR:  none

The banner residue reported in the earlier RH-2D diagnosis is fixed.

## Signaling Attempt Replacement

    OLD SIGNALING ATTEMPT:            1  (leg 1786782709.26)
    NEW SIGNALING ATTEMPT:            2  (leg 1786782795.27)
    ACTIVE AFTER REPLACEMENT:         2

No late callback from the old session arose naturally — the old leg's BYE and 200
OK completed at 08:33:11.407, four seconds before the replacement INVITE — so the
stale-rejection branch is not claimed as live-proven here; repository automated
coverage remains the evidence for it.

## Storm Check

Whole-session totals (08:30:49 → 08:35:31, one page reload included):

    admissions (POST participants/self): 3 — baseline Join, refresh recovery,
                                         runtime-BYE recovery (one per intended event)
    DELETE participants/self:            1 — only the final cleanup Leave at 08:35:31
                                         (0 during the entire runtime-BYE corridor)
    INVITEs:                             6 transmissions = 3 logical transactions
    duplicate participants:              none — exactly 1 admitted participant throughout
    binder retry storm:                  none
    credential storm:                    none — ordinary renewals at 08:31:18,
                                         08:32:48, 08:34:18 (~1 per 90 s)
    remove/re-admit loop:                none

## Cleanup

Performed through the normal application UI after all decisive evidence was
captured: one Leave click at 08:35:31.758 (1 DELETE, audit `reason: requested`),
then Log out.

    admitted participants: 0
    runtime channels:      0
    conference bridge:     0 members
    conference:            68c7d252-… open / ready
    runtime node:          d4539d79-… active / ready
    media edge:            intact — runtime/10.42.0.214!10.42.0.214 and
                           browser/10.42.0.214!127.0.0.1

An earlier aborted attempt in this session (a leg whose media kept flowing, so no
runtime BYE occurred) was cleaned up without any Leave: the tab was closed and the
RH-1 grace sweep converged the participation at 08:29:02 with audit
`reason: recovery_grace_expired`.

## RH-2D Acceptance Criteria

    [x] natural web login used
    [x] healthy baseline conference established
    [x] runtime-originated BYE observed
    [x] SIP session transitions Established → Terminated
    [x] signaling client clears the terminated session
    [x] current callback carries current signaling attempt identity
    [x] callback is accepted regardless of unrelated conferenceAttempt drift
    [x] browser leaves Connected
    [x] browser enters Recovering
    [x] no canonical participant DELETE occurs
    [x] bootstrap reports the same participation recoverable
    [x] same participant is reused
    [x] exactly one replacement INVITE occurs
    [x] replacement channel becomes runtime_channel_id
    [x] replacement channel joins canonical bridge
    [x] Connected waits for canonical binding confirmation
    [x] final UI is Connected
    [x] Recovering banner is absent
    [x] no recovery/INVITE storm occurs
    [x] V0 remains COMPLETE
    [x] RT-1A remains COMPLETE / LIVE PROVEN

## Failed Proof Steps

    None.

## Divergences

* **Induced media interruption.** The drifted leg carried working media, so
  Asterisk's `rtp_check_timeout` would not have fired on its own within the proof
  window. A single canonical pod restart of `deployment/rtpengine` was used to
  interrupt the media relay so the runtime would apply its own committed policy.
  No configuration was changed, the media edge was re-verified afterwards, and the
  BYE itself was issued by Asterisk, not injected. Classified as a proof action,
  disclosed here in full.
* **Two aborted attempts before the decisive run.** In the first, a natural leg
  kept media and produced no BYE (`context.clearPermissions()` does not end live
  Chromium tracks, and the MCP browser auto-grants the microphone, so a
  media-less leg could not be produced on demand). In the second, the same held
  after a fresh login. Both were cleaned up canonically — the first by the RH-1
  grace sweep after closing the tab, with no DELETE.
* Neither divergence affects the decisive claim: the BYE was runtime-originated,
  the drift was produced by the ordinary recovery corridor, and no client or
  server behaviour under test was altered.

## Code Changes

    None.

## V0 Status

    COMPLETE / UNCHANGED

## RT-1A Status

    COMPLETE / LIVE PROVEN / UNCHANGED

## RH Status

    RH-0: COMPLETE
    RH-1: IMPLEMENTED / TESTED
    RH-2: COMPLETE / LIVE PROVEN
    RH-3: NOT STARTED
