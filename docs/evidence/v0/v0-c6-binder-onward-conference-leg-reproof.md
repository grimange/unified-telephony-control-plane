# V0-C6 — Binder-Onward Canonical Browser Conference-Leg Live Reproof

## Verdict

    V0_C6_CANONICAL_BROWSER_CONFERENCE_LEG_LIVE_REPROOF_FOUND_BLOCKER

The binder fix is **live-proven**. The decisive control-plane invariant now
holds: the actual inbound browser PJSIP channel is bound to the canonical
ConferenceParticipant and is a member of `utcp-conf-<conferenceId>`.

    browser inbound PJSIP channel id  1786760286.1
      == conference_participants.runtime_channel_id  1786760286.1
      == bridge member of utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768   ✓

One bounded defect remains, in the media plane and not in the binder: the
rtpengine NetworkPolicy still pins a single hardcoded RuntimeNode, so RTP cannot
reach any managed RuntimeNode. Asterisk therefore receives no audio and reclaims
the correctly-bridged channel after 30 s.

This is the same class of defect V0-C4 corrected for
`allow-asterisk-sip-from-kamailio.yaml`, left unfixed in the sibling media
policy.

## Method

Evidence-only. No application source modified. Deployment through canonical
targets only. Natural Playwright login from the real login page; no preset
storage, injected cookie, copied session, database/Redis session, or
authentication bypass. No credential secret, digest material, or Kubernetes
Secret value appears below.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing working tree including the C1–C4 packets and the
                   binder/listener fix
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Canonical environment

    RUNTIME NODE:     d4539d79-432d-48dc-8def-d52e0d0ca5e2
                      "V0C6 Conference Runtime 20260815" — active / ready,
                      sip/udp :5060 endpoint enabled
    CONFERENCE:       68c7d252-2203-4f2a-9b81-4d87d1294768
                      "V0C6 Conference Leg Proof 20260815" — open / ready,
                      binding 1d9da35b-… active
    API IMAGE:        utcp/api:0.1.0-k1-dev @sha256:1e4aa2f7…  (was e21aa2d5…)
    ASTERISK IMAGE:   utcp/asterisk-ari:0.1.0-k1-dev @sha256:b2d71848…
    DEPLOYMENT FRESH: yes
    LISTENER FIX PRESENT: yes — `'application_args' => array_values(array_filter($args, 'is_string'))`
                      at AsteriskAriEventListener.php:262 inside the running pod
    BINDER FIX PRESENT:   yes — `$this->admissionReference($event['application_args'] ?? null)`
                      at AsteriskConferenceParticipantBinder.php:21 inside the running pod

Deployed with `make k8s-image-build` → `k8s-image-push` → `k8s-apply` → rollout
restart of every `utcp-platform` Deployment → `make media-edge-apply`. Before
proceeding, the old `asterisk-ari-events` replica was confirmed terminated (one
pod remaining), its listener lease `claimed`, and the Stasis application
`utcp-t0-observation` re-registered on the bound node at 02:17:28. No pod was
patched, edited, or had files copied into it; no database row was written by hand.

## Natural login

    USER:        t3-s3b-t3s3b1785716804@utcp.local.test (tenant-member)
    TENANT:      Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: telephony.conferences.join, telephony.conferences.view,
                 telephony.sessions.create_own, telephony.sessions.view_own,
                 telephony.signaling.issue_own, telephony.signaling.view_own,
                 tenant.memberships.view, tenant.roles.view

## Registration

    TELEPHONY SESSION: ee6bbdb1-39a2-432d-acc1-1231c8dd9ff8
    SIP IDENTITY:      ts-ee6bbdb139a2432dacc11231c8dd9ff8
    REGISTRATION:      CSeq 2 → 401 Digest → CSeq 3 → 200 OK @02:18:06.267Z
    UI:                REGISTERED, one joinable conference

## Admission

    PARTICIPANT:            e5405f17-229f-4b33-adeb-8437d07a7959
    SIGNALING DESTINATION:  sip:conf-e5405f17-229f-4b33-adeb-8437d07a7959
                            @sip.utcp.local.test
    PARTICIPANT/URI MATCH:  exact

Join clicked once; no second application-level acceptance.

## SIP INVITE

    → INVITE sip:conf-e5405f17-…@sip.utcp.local.test  CSeq 1 @02:18:06.676Z
    ← 401 Unauthorized                                 CSeq 1
    → ACK                                              CSeq 1
    → INVITE (authenticated)                           CSeq 2 @02:18:06.688Z
    ← 100 trying                                       CSeq 2 @02:18:06.715Z
    ← 200 OK  Contact: <sip:10.42.0.173:5060>          CSeq 2 @02:18:06.799Z
    → ACK sip:10.42.0.173:5060                         CSeq 2 @02:18:06.808Z

    REQUEST URI:        sip:conf-<participantId>@<realm>
    BOUND RUNTIME NODE: 10.42.0.173 — the V0C6 node's Asterisk pod
    MATCHED EXTENSION:  _[c]o[n]f-.
    FINAL SIP:          200 OK

## Raw StasisStart

Read from the running Asterisk while the call was up
(`core show channels concise`):

    PJSIP/anonymous-00000001 ! from-kamailio ! conf-e5405f17-229f-4b33-adeb-8437d07a7959
      ! 3 ! Up ! Stasis ! utcp-t0-observation,conf-e5405f17-229f-4b33-adeb-8437d07a7959
      ! … ! utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768 ! 1786760286.1

    TYPE:         StasisStart
    CHANNEL ID:   1786760286.1
    CHANNEL NAME: PJSIP/anonymous-00000001
    ARGS:         conf-e5405f17-229f-4b33-adeb-8437d07a7959

The channel row also shows the bridge id in place, so Asterisk itself reports the
channel as a member of the canonical conference bridge.

## Listener normalization — PASS

    receipt: asterisk.ari.channel.stasis_start @02:18:09
      {"ari_event_type":"StasisStart",
       "channel_id":"1786760286.1",
       "channel_name":"PJSIP_anonymous-00000001",
       "configuration_generation":10,
       "runtime_node_id":"d4539d79-432d-48dc-8def-d52e0d0ca5e2", …}

    CHANNEL ID:        1786760286.1
    APPLICATION ARGS:  preserved and consumed — proven by effect, since the
                       binder's only path to a non-null admission reference is
                       `$event['application_args']`, and it bound successfully
    BINDER DISPATCHED: yes

The sanitized receipt intentionally does not persist `application_args`; the
proof that the reference survived is the binding itself, which the binder cannot
perform without it.

## Canonical participant binding — PASS

    PARTICIPANT:        e5405f17-229f-4b33-adeb-8437d07a7959
    ACTUAL CHANNEL ID:  1786760286.1
    RUNTIME_CHANNEL_ID: 1786760286.1
    MATCH:              YES

Read from `conference_participants` while the call was established:
`admitted | unobserved | 1786760286.1 | self_admission`.

## Conference bridge — PASS

    BRIDGE:           utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768
                      (stasis / simple_bridge)
    MEMBERS:          1
    EXPECTED CHANNEL: 1786760286.1
    MATCH:            YES

`bridge show all` reported `Chans 1` while connected, and the channel row itself
carried the bridge id. The full invariant is satisfied:

    browser PJSIP channel == participant.runtime_channel_id == bridge member

## Synthetic self-admission — PASS

    LOCAL PROOF CHANNEL: none

`core show channels concise` listed exactly one channel — the inbound
`PJSIP/anonymous-00000001` — and no `Local/participant@utcp-conference-proof`.

## Runtime observation

    OBSERVED PARTICIPANT: e5405f17-… resolved from the real PJSIP channel
    OBSERVED CHANNEL:     1786760286.1
    OBSERVED MEMBERSHIP:  `unobserved` while connected, then `left` after teardown
    OBSERVATION SOURCE:   existing ARI receipt → normalizer → projection path

The `joined` observation was not reached within the call's 30-second lifetime.
This is downstream of the media defect below rather than a separate finding: the
channel was destroyed before the participant-observed projection converged. No
second observation authority appeared.

## Media path — FAIL

Measured from the real `RTCPeerConnection` ~9 s into a second, identical call:

    ICE / connection state:      connected / connected
    nominated candidate pair:    446 packets sent, 2 received
                                 (89 418 bytes sent, 795 received)
    outbound-rtp audio:          433 packets, 69 280 bytes
    inbound-rtp audio:           none
    answer SDP from the runtime: m=audio 40018 RTP/SAVPF
                                 c=IN IP4 127.0.0.1
                                 a=candidate:… 127.0.0.1 40018 typ host

    RTPENGINE:          mediating; browser-facing 127.0.0.1:40018 as designed
    PJSIP CHANNEL:      1786760286.1, bridged
    BRIDGE:             utcp-conf-68c7d252-…
    9900 ECHO INVOLVED: no
    RESULT:             FAIL — the browser sends RTP, Asterisk receives none

At 02:18:36 the bound node logged
`res_pjsip_sdp_rtp.c rtp_check_timeout: Disconnecting channel
'PJSIP/anonymous-00000001' for lack of audio RTP activity in 30 seconds`, exactly
30 s after answer.

## UI

    SESSION STATE: SessionState.Established
    UI STATE:      Connected
    CONFERENCE:    V0C6 Conference Leg Proof 20260815

Unlike the previous reproof, Connected was accompanied by the satisfied
channel/bridge invariant.

## Natural leave

Not exercisable as a proof step: the runtime reclaimed the channel at 30 s before
a Leave could demonstrate anything about a bridged leg. The client converged
correctly on the runtime-initiated termination.

    BYE:                      received from the runtime @02:18:36.821Z
    CHANNEL FINAL:            destroyed; 0 channels on the node
    PARTICIPANT FINAL:        removed / left
    BRIDGE FINAL:             0 members
    RUNTIME_CHANNEL_ID FINAL: cleared to null — the repository's actual contract
                              is to clear on StasisEnd
                              (`AsteriskConferenceParticipantBinder::clear()`
                              sets `runtime_channel_id => null` for the matching
                              channel), not to retain it as historical evidence
    UI FINAL:                 Ready

## Cleanup idempotence

    RELEASE REQUEST COUNT:       one logical release per attempt
    REMOVE OPERATION COUNT:      0 — no `conference.participant.remove` operation
                                 was needed; the runtime channel was already gone
    FINAL ADMITTED PARTICIPANTS: 0
    RECONCILIATION:              converged; no duplicate binding, no competing
                                 runtime_channel_id, no bridge add/remove storm,
                                 no INVITE loop, no credential storm

## C5 preservation

    9900:            → static selected-application-runtime → T3 Echo fixture
    conf-*:          → canonical conference route → bound RuntimeNode
                       (proven again by this call's 200 OK from 10.42.0.173)
    STATIC FALLBACK: none observed
    RESULT:          V0-C5 remains COMPLETE

## Deferred bridge-recreation observation

    DEFERRED / NON-BLOCKING

The bridge existed, accepted the channel, and reported `Chans 1` while connected;
the recreation churn did not cause the binding failure, member loss, or the media
failure. Not investigated further, per scope.

## Failed proof steps

### PRODUCT_DEFECT — rtpengine media NetworkPolicy pins one hardcoded RuntimeNode

    CLASSIFICATION: IMPLEMENTATION (blocking, repository defect — not deployment
                    staleness: the committed source carries the pin)
    CLAIM:          browser media traverses rtpengine into the bridged PJSIP
                    channel on the conference's bound RuntimeNode
    EXPECTED:       Asterisk receives the browser's RTP on the bridged channel
    ACTUAL:         Asterisk receives no audio RTP; the correctly-bridged channel
                    is reclaimed after 30 s
    RAW STASIS ARGS:   conf-e5405f17-229f-4b33-adeb-8437d07a7959
    NORMALIZED ARGS:   consumed successfully (binding occurred)
    CHANNEL:           1786760286.1, PJSIP, Up, bridged
    PARTICIPANT:       e5405f17-…
    RUNTIME_CHANNEL_ID: 1786760286.1 (correct)
    BRIDGE:            utcp-conf-68c7d252-…, 1 member (correct)

Root cause, from the committed policy source
`infrastructure/kubernetes/security/platform/allow-rtpengine-media.yaml`:

    line 35 (ingress, RTP from Asterisk 40000-40099)
    line 75 (egress,  RTP to Asterisk 10000-20000)
      podSelector:
        matchLabels:
          app.kubernetes.io/component: asterisk-ari
          utcp.io/network-role: asterisk-ari
          utcp.dev/runtime-node: local-asterisk-ari      ← hardcoded

Live pod labels:

    asterisk-ari-54fbcf7fd9-v4nzd                      runtime-node=local-asterisk-ari
    asterisk-rnp6-readiness-reproof-20260809-…          runtime-node=rnp6-readiness-reproof-20260809
    asterisk-v0c6-conference-runtime-20260815-…         runtime-node=v0c6-conference-runtime-20260815

Under default-deny, rtpengine may therefore exchange RTP **only** with the single
statically-labelled base node. Every managed RuntimeNode — the only kind that can
host a canonical conference under ADR-022 — is unreachable for media in both
directions. The signaling policy `allow-asterisk-sip-from-kamailio.yaml` had this
exact pin removed by V0-C4; the sibling media policy was missed.

    AFFECTED FILE: infrastructure/kubernetes/security/platform/allow-rtpengine-media.yaml
                   (lines 35 and 75 — remove the `utcp.dev/runtime-node`
                   matchLabel from both the ingress and egress podSelectors,
                   matching the correction already applied to
                   allow-asterisk-sip-from-kamailio.yaml)

Not patched, per task scope. Acceptance test for the fix: with a managed
RuntimeNode bound to the conference, a natural Join produces non-zero
`inbound-rtp` packets at the browser and no `rtp_check_timeout` disconnect.

Note the fix requires `make security-apply` to take effect — NetworkPolicies are
not applied by `make k8s-apply`. That target currently exits non-zero at its final
Gateway step with `missing required K2 tool: helm`, after the policies have
applied.

## RTP timeout boundary

`rtp_timeout` / `rtp_timeout_hold` were not modified. The timeout is new evidence
in the sense that the channel was this time correctly bridged, but it is not a
separate defect: the channel was bridged and still received no RTP, which is the
expected consequence of the policy denial above.

## Secret exposure review

No signaling credential secret, digest `Authorization` material, or Kubernetes
Secret value was displayed or written. The admission response exposed no
RuntimeNode DNS, pod IP, or internal SIP target.

## Code changes

    None.

## Retained state

    RuntimeNode d4539d79-… and conference 68c7d252-… retained, active/ready and
    open/ready with the active binding — ready for the reproof after the policy
    fix. 0 admitted participants; no orphaned channels; browser logged out.

## Status

    binder / listener contract        LIVE-PROVEN
    channel == participant == bridge  LIVE-PROVEN
    media into the conference leg     BLOCKED — one bounded policy defect
    V0-C5                             remains COMPLETE
    V0-C6                             BLOCKED
    V0                                IN PROGRESS
