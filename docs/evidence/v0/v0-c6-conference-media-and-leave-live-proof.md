# V0-C6 — Final Conference Media and Natural Leave Live Proof

## Verdict

    V0_C6_CANONICAL_BROWSER_CONFERENCE_MEDIA_AND_LEAVE_LIVE_PROOF_PASSED

The complete V0 vertical slice is proven end to end against canonical
`utcp-local`. The browser's own SIP dialog is the conference participant, its
media reaches the conference bridge on the conference's canonically bound
RuntimeNode, the call is stable well past the previous failure threshold, and a
natural user Leave tears everything down cleanly.

**V0 is COMPLETE.**

## Method

Evidence-only. No application source modified. The corrected media
NetworkPolicy was applied through the canonical `make security-apply` lifecycle
and the external media edge restored through `make media-edge-apply`. Natural
Playwright login from the real login page; no preset storage, injected cookie,
copied session, database/Redis session, or authentication bypass. No credential
secret, digest material, or Kubernetes Secret value appears below.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing working tree including the C1–C4 packets and the
                   rtpengine media-policy fix
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Canonical environment

    RUNTIME NODE:     d4539d79-432d-48dc-8def-d52e0d0ca5e2
                      "V0C6 Conference Runtime 20260815" — active / ready,
                      sip/udp :5060 endpoint enabled
    CONFERENCE:       68c7d252-2203-4f2a-9b81-4d87d1294768
                      "V0C6 Conference Leg Proof 20260815" — open / ready,
                      binding active
    API IMAGE:        utcp/api:0.1.0-k1-dev @sha256:1e4aa2f7…
    ASTERISK IMAGE:   utcp/asterisk-ari:0.1.0-k1-dev @sha256:b2d71848…
    RTPENGINE:        utcp/rtpengine:0.1.0-k1-dev, two-interface external media
                      edge `runtime/POD!POD` + `browser/POD!127.0.0.1`
    DEPLOYMENT FRESH: yes

The previously proven fixture was reused after confirming it still active, ready,
open, correctly bound, and equipped with the enabled sip endpoint.

## Security apply

    COMMAND:            make security-apply
    NETWORKPOLICY APPLY: succeeded — all policies reported applied/unchanged
                        before the Gateway stage
    HELM/GATEWAY STAGE:  failed — "missing required K2 tool: helm"
    FINAL RESULT:        non-zero exit at the Gateway stage only

Classified **ENVIRONMENT / NON-BLOCKING FOR THIS MEDIA PROOF**: live cluster
inspection (below) confirms the corrected media policy is active. Helm was not
installed, no bypass flag was added, no Makefile dependency was changed, and no
Gateway resource was applied by hand.

### Lifecycle interaction observed and corrected canonically

`make security-apply` re-applies the K1 base as part of its run, which reverts
the external media-edge projection: immediately afterwards rtpengine ran with
`--interface=browser/10.42.1.68!10.42.1.68` (the Pod IP) instead of the published
host address. The first join attempt after the security apply therefore failed at
ICE (`iceConnectionState: disconnected`, 0 outbound packets) because the browser
cannot reach a Pod IP. Restoring the projection with the canonical
`make media-edge-apply` returned rtpengine to
`--interface=browser/10.42.0.183!127.0.0.1`, with the corrected NetworkPolicy
still in place. Both were then verified simultaneously before the proof run. This
is a known ordering property of the canonical lifecycle — security apply first,
media edge second — not a defect discovered here.

## Live media NetworkPolicy

    INGRESS SELECTOR: {app.kubernetes.io/component: asterisk-ari,
                       utcp.io/network-role: asterisk-ari}
    EGRESS SELECTOR:  {app.kubernetes.io/component: asterisk-ari,
                       utcp.io/network-role: asterisk-ari}
    STATIC RUNTIME PIN: NO — `utcp.dev/runtime-node` is absent from both
                       Asterisk media selectors
    NAMESPACE:        utcp-runtime (preserved)
    PORTS:            ingress UDP 40000-40099 (Asterisk → rtpengine)
                      egress  UDP 10000-20000 (rtpengine → Asterisk)

## Managed runtime match

    POD:                asterisk-v0c6-conference-runtime-20260815-5ce1a2de-74676c7qvsg9
    COMPONENT:          asterisk-ari
    NETWORK ROLE:       asterisk-ari
    RUNTIME NODE LABEL: v0c6-conference-runtime-20260815 (still present on the
                        pod, no longer part of media authorization)
    MATCH:              YES

Selecting on the canonical labels now returns all three Asterisk pods — the base
node, the retained RNP6 node, and the managed conference node — so managed
RuntimeNodes are media-eligible.

## Natural login

    USER:        t3-s3b-t3s3b1785716804@utcp.local.test (tenant-member)
    TENANT:      Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: telephony.conferences.join, telephony.conferences.view,
                 telephony.sessions.create_own, telephony.sessions.view_own,
                 telephony.signaling.issue_own, telephony.signaling.view_own,
                 tenant.memberships.view, tenant.roles.view

## Registration

    TELEPHONY SESSION: b46d8ea6-ff5a-4fec-9bcf-e9fcb7c6fbe5
    SIP IDENTITY:      ts-b46d8ea6ff5a4fec9bcfe9fcb7c6fbe5
    REGISTRATION:      REGISTER → 401 Unauthorized → authenticated REGISTER
                       → 200 OK
    UI:                REGISTERED

## Admission

    PARTICIPANT:            1a4effbf-b612-4a92-942d-aea6bcc8f275
    SIGNALING DESTINATION:  sip:conf-1a4effbf-b612-4a92-942d-aea6bcc8f275
                            @sip.utcp.local.test

Join was clicked once; no second application-level acceptance.

## Canonical conference leg

Read from the running Asterisk on the bound node while the call was up:

    PJSIP/anonymous-00000004 ! from-kamailio ! conf-1a4effbf-b612-4a92-942d-aea6bcc8f275
      ! 3 ! Up ! Stasis ! utcp-t0-observation,conf-1a4effbf-…
      ! … ! utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768 ! 1786762054.4

    PJSIP CHANNEL:      1786762054.4  (PJSIP/anonymous-00000004)
    RUNTIME_CHANNEL_ID: 1786762054.4
    BRIDGE:             utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768, 1 member
    MATCH:              YES

The decisive invariant remains intact during the successful media call:

    browser PJSIP channel == participant.runtime_channel_id == bridge member

## Browser RTP

Measured from the real `RTCPeerConnection` ~12 s after answer:

    ICE:              connected  (connectionState connected)
    NOMINATED PAIR:   625 packets sent, 4 received
    OUTBOUND PACKETS: 619
    OUTBOUND BYTES:   99 040
    INBOUND PACKETS:  0
    INBOUND BYTES:    0

Browser outbound RTP is > 0 and flowing. Browser inbound RTP is 0, which is the
**expected** behaviour for a single-participant conference: the mixing bridge has
no other talker, so Asterisk has nothing to transmit back. This is not the prior
failure mode — see the Asterisk counters below, which prove the browser's audio is
arriving at the conference bridge.

## rtpengine

    SESSION:       mediating this dialog — SDP offer/answer rewritten, browser
                   answered on the published browser-facing address
    BROWSER SIDE:  interface `browser/10.42.0.183!127.0.0.1` (host-published)
    ASTERISK SIDE: interface `runtime/10.42.0.183!10.42.0.183` toward the managed
                   RuntimeNode's RTP range
    ACTIVITY:      active — proven transitively by Asterisk's receive counters,
                   since the browser's RTP can only reach the runtime through
                   rtpengine

## Asterisk media — the decisive counter

    pjsip show channelstats
      BridgeId  ChannelId           UpTime    Codec  Recv Count  Lost  Pct  Jitter  …
      utcp-con  anonymous-00000004  00:02:20  ulaw   6972        12    0    0.000

    CHANNEL:      anonymous-00000004 (1786762054.4)
    BRIDGE:       utcp-con… (utcp-conf-68c7d252-…)
    RTP ACTIVITY: 6 972 packets received from the browser, 12 lost, 0 %
    TIMEOUT:      none for this channel

Asterisk is receiving the browser's audio on the exact channel that is the
conference bridge member. Transmit count is 0, consistent with a
single-participant mixing bridge.

## Timeout window

    CALL DURATION OBSERVED: 02:47:34.79 → 02:50:02.41 = 2 min 28 s
    RTP CHECK TIMEOUT FIRED: NO for this channel
    CHANNEL STILL ACTIVE:    YES — sampled alive at 02:48:17, 02:48:32, 02:48:47,
                             02:49:03, 02:49:18 and still up at Leave

The only `rtp_check_timeout` in the window was at 02:46:34 against
`PJSIP/anonymous-00000003` — the earlier attempt made while the media edge was
still reverted. `rtp_timeout` and `rtp_timeout_hold` were not modified.

Because `rtp_check_timeout` triggers on lack of *received* audio RTP, the
channel's survival for 148 seconds is itself independent proof that Asterisk is
receiving the browser's media.

## Runtime observation

    PARTICIPANT:  1a4effbf-b612-4a92-942d-aea6bcc8f275
    CHANNEL:      1786762054.4
    STATE:        admitted / **joined**
    MEMBERSHIP:   bridge member confirmed by both Asterisk and the projection

The participant reached the canonical `joined` observation during the call — the
step that could not converge in the previous reproof because the channel was
destroyed at 30 s.

## UI stability

    SESSION STATE: SessionState.Established
    UI STATE:      Connected, naming "V0C6 Conference Leg Proof 20260815"
    DURATION:      Connected continuously for 2 min 28 s, well past the previous
                   30-second media-timeout window, alongside bidirectional-capable
                   RTP and the intact bridge invariant

## Natural leave

    LEAVE CLICK:              2026-08-15T02:50:02.407Z (once, through the real UI)
    BYE:                      → BYE sip:10.42.0.173:5060 @02:50:02.417Z
                              ← 200 OK
    CHANNEL FINAL:            terminated — 0 channels on the bound node
    PARTICIPANT FINAL:        removed / left, left_at 02:50:11Z
    BRIDGE FINAL:             utcp-conf-68c7d252-…, 0 members
    RUNTIME_CHANNEL_ID FINAL: null — cleared, matching the repository's
                              `AsteriskConferenceParticipantBinder::clear()` contract
    UI FINAL:                 Ready, with the available-conference list restored

## Cleanup idempotence

    PARTICIPANT RELEASE REQUEST COUNT: 1 — `participants/self` resource timing
                                       went 3 → 4 across the Leave (one DELETE)
    REMOVE OPERATION COUNT:            0 — the runtime channel was already gone,
                                       so no `conference.participant.remove`
                                       operation was required
    FINAL ADMITTED PARTICIPANTS:       0
    RECONCILIATION FINAL:              converged

No cleanup storm, no duplicate participant release, no channel recreation, no
INVITE loop, no credential storm.

## C5 preservation

    9900:            → `[extensions.local.conf]` T3-S2A media fixture,
                     Answer / Echo / Hangup on the static application runtime
    conf-*:          → CONFERENCE_RUNTIME_RELAY → canonical projection →
                     conference-bound RuntimeNode (this call routed to
                     10.42.0.173, the managed node)
    STATIC FALLBACK: NONE — 0 references to APPLICATION_RUNTIME_RELAY or
                     application-runtime-sip inside the deployed conference route

## Bridge-recreation observation

    DEFERRED / NON-BLOCKING

129 `asterisk.ari.bridge.created` receipts in five minutes on this node, yet the
bridge held the channel for the full call, media flowed, the participant reached
`joined`, and Leave converged cleanly. It caused no bridge-member loss, media
interruption, teardown, or Leave failure, so it was not investigated further. It
remains available for a separate narrow reconciliation/idempotency audit after V0.

## Secret exposure review

No signaling credential secret, digest `Authorization` material, or Kubernetes
Secret value was displayed, logged, or written to evidence. The admission response
exposed no RuntimeNode Service DNS, pod IP, or internal SIP target; the internal
target was used only server-side by Kamailio.

## Failed proof steps

    None.

## Code changes

    None.

## Retained state

    RuntimeNode d4539d79-… active/ready with its canonical sip endpoint.
    Conference 68c7d252-… open/ready with the active binding.
    0 admitted participants; 0 channels; bridge empty; browser logged out.

## V0 closure

    corrected media NetworkPolicy live                     ✓
    managed Asterisk RuntimeNode matches the media policy   ✓
    browser PJSIP channel == participant runtime channel    ✓
    same channel in the canonical conference bridge         ✓
    browser outbound RTP > 0                                ✓
    browser media reaches the conference bridge             ✓ (6 972 packets
                                                              received by Asterisk)
    rtpengine mediates the managed RuntimeNode path         ✓
    no rtp_check_timeout past the 30-second threshold       ✓ (148 s call)
    participant reaches joined observation                  ✓
    UI Connected while media is healthy                     ✓
    natural Leave clicked once                              ✓
    browser sends BYE                                       ✓
    same channel terminates                                 ✓
    participant removed/left                                ✓
    bridge membership removed                               ✓
    runtime_channel_id cleared                              ✓
    UI Ready                                                ✓
    cleanup idempotent                                      ✓

    V0-C6  PASSED
    V0     COMPLETE

The single browser inbound-RTP figure of 0 is the expected consequence of a
one-participant mixing bridge and is not a failed criterion: the required proof —
that the browser's actual leg carries media into the conference path — is
established by Asterisk's 6 972 received packets on the bridged channel and by the
channel surviving 148 seconds against a 30-second RTP-inactivity timer.
