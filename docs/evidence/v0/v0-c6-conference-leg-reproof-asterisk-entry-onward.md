# V0-C6 — Narrow Asterisk-Entry-Onward Conference-Leg Live Reproof

## Verdict

    V0_C6_CANONICAL_BROWSER_CONFERENCE_LEG_LIVE_REPROOF_FOUND_BLOCKER

The V0-C4 dialplan fix is **live-proven**: the corrected pattern matches, the
browser's real inbound PJSIP channel is created on the conference's bound
RuntimeNode, it enters `Stasis(utcp-t0-observation, conf-<participantId>)`, the
dialog reaches `200 OK`, and the UI reaches **Connected** — with no synthetic
`Local/participant@utcp-conference-proof` channel anywhere.

The corridor then stops one step later: the participant binder never recovers
the admission reference, so `runtime_channel_id` stays null and the channel is
never added to `utcp-conf-<conferenceId>`. Asterisk reclaims the unbridged
channel after 30 s for lack of RTP.

One bounded defect remains, isolated to a single guard.

## Method

Evidence-only. No application source modified. Deployment through canonical
targets only. Natural Playwright login from the real login page; no preset
storage, injected cookie, copied session, database/Redis session, or
authentication bypass. No credential secret, digest material, or Kubernetes
Secret value appears below.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing working tree including the C1–C4 packet and the
                   V0-C4 dialplan fix
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Canonical environment

    RUNTIME NODE:   d4539d79-432d-48dc-8def-d52e0d0ca5e2
                    "V0C6 Conference Runtime 20260815" — active / ready
                    sip/udp endpoint …utcp-runtime.svc.cluster.local:5060, enabled
    CONFERENCE:     68c7d252-2203-4f2a-9b81-4d87d1294768
                    "V0C6 Conference Leg Proof 20260815" — open / ready
    BINDING:        1d9da35b-b825-4716-868b-6b27f4b760e4, active
    ASTERISK IMAGE: utcp/asterisk-ari:0.1.0-k1-dev @sha256:b2d71848…
                    (previous digest af5e9cf8… — rolled this session)
    DEPLOYMENT FRESH: yes

The previously proven fixture was reused; canonical state confirmed it still
active, ready, open, correctly bound, and equipped with the enabled sip endpoint.
Deployment used `make k8s-image-build` → `make k8s-image-push` → `kubectl rollout
restart` of the bound RuntimeNode Deployment and the base `asterisk-ari`
Deployment. No pod was patched, edited, or had files copied into it.

### Deployed dialplan

    [from-kamailio]
    exten => _[c]o[n]f-.,1,NoOp(UTCP canonical conference admission destination=${EXTEN})
     same => n,Answer()
     same => n,Stasis(utcp-t0-observation,${EXTEN})
     same => n,Hangup()

read directly from `/tmp/utcp-asterisk/extensions.conf` inside the bound node —
the corrected `_[c]o[n]f-.` form, not the previous `_conf-.`.

## Live dialplan resolver

Asked of the running bound Asterisk itself:

    CONF DESTINATION: conf-68c7d252-2203-4f2a-9b81-4d87d1294768@from-kamailio
    RESOLVED RULE:    '_[c]o[n]f-.'  →  NoOp / Answer /
                      Stasis(utcp-t0-observation,${EXTEN}) / Hangup
                      (extensions.conf:14-17), listed ahead of the '_.' catch-all
    9900 RULE:        '9900' → NoOp(UTCP local T3-S2A media fixture) / Answer /
                      Echo / Hangup (extensions.local.conf:2-5) on the static
                      T3 application runtime
    RESULT:           PASS — the previous behaviour, in which every conf… form
                      resolved only to '_.', is gone

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
    REGISTRATION:      CSeq 2 → 401 Digest → CSeq 3 → 200 OK @01:50:55.338Z
    UI:                REGISTERED, exactly one joinable conference

## Admission

    PARTICIPANT:            d7bdd037-1287-41fa-9c49-f990014704ff
    SIGNALING DESTINATION:  sip:conf-d7bdd037-1287-41fa-9c49-f990014704ff
                            @sip.utcp.local.test
    PARTICIPANT/URI MATCH:  exact

Join was clicked once; there was no second application-level accept or invite
interaction.

## SIP INVITE

    → INVITE sip:conf-d7bdd037-…@sip.utcp.local.test  CSeq 1 @01:50:55.550Z
    ← 401 Unauthorized                                 CSeq 1
    → ACK                                              CSeq 1
    → INVITE (authenticated)                           CSeq 2 @01:50:55.563Z
    ← 100 trying                                       CSeq 2 @01:50:55.588Z
    ← 200 OK  Contact: <sip:10.42.0.173:5060>          CSeq 2 @01:50:55.675Z
       answer SDP: c=IN IP4 10.42.3.45, m=audio 40035 RTP/SAVPF
    → ACK sip:10.42.0.173:5060                         CSeq 2 @01:50:55.682Z

    REQUEST URI:        sip:conf-<participantId>@<realm>
    BOUND RUNTIME NODE: 10.42.0.173 — the V0C6 node's Asterisk pod
    FINAL SIP:          200 OK

**The previous 403 from the catch-all is gone.**

## Asterisk conference entry — PASS

From `core show channels concise` on the bound node while the call was up:

    PJSIP/anonymous-00000000 ! from-kamailio ! conf-d7bdd037-1287-41fa-9c49-f990014704ff
      ! 3 ! Up ! Stasis ! utcp-t0-observation,conf-d7bdd037-1287-41fa-9c49-f990014704ff

    MATCHED RULE:       _[c]o[n]f-.
    STASIS ENTRY:       Stasis(utcp-t0-observation, conf-<participantId>)
    CHANNEL ID (ARI):   1786758655.0
    CHANNEL NAME:       PJSIP/anonymous-00000000
    CHANNEL TECHNOLOGY: PJSIP — the actual inbound browser leg
    CHANNEL STATE:      Up

This is the real browser SIP leg on the conference's own bound RuntimeNode, with
the admission reference carried through as the Stasis argument.

## Canonical participant binding — FAIL

    PARTICIPANT:        d7bdd037-1287-41fa-9c49-f990014704ff
    RUNTIME_CHANNEL_ID: null
    ACTUAL CHANNEL ID:  1786758655.0
    MATCH:              NO

## Conference bridge — FAIL

    BRIDGE:          utcp-conf-68c7d252-2203-4f2a-9b81-4d87d1294768
                     (present, type stasis / simple_bridge)
    CHANNEL MEMBERS: 0
    MATCH:           NO — the browser channel never became a bridge member

The decisive invariant `browser channel id == participant.runtime_channel_id ==
bridge member` is therefore **not** satisfied.

## Synthetic self-admission negative check — PASS

    LOCAL PROOF CHANNEL FOR PARTICIPANT: no

`core show channels concise` listed exactly one channel for this participant —
the inbound `PJSIP/anonymous-00000000` — and no
`Local/participant@utcp-conference-proof`. The `self_admission` synthetic-channel
cutoff behaves correctly.

## Runtime observation

The ARI ingest path worked; the binding step inside it did not.

    listener lease:        asterisk-ari-events, claimed, valid, on the bound node
    receipt:               asterisk.ari.channel.stasis_start @01:51:00
      sanitized payload:   {"ari_event_type":"StasisStart",
                            "channel_id":"1786758655.0",
                            "channel_name":"PJSIP_anonymous-00000000",
                            "configuration_generation":10, …}
    receipt:               asterisk.ari.channel.stasis_end @01:51:30
    participant observed:  unobserved during the call; no joined observation

## Media path

    RTPENGINE:          offer/answer mediated; browser answered
                        c=IN IP4 10.42.3.45, m=audio 40035 RTP/SAVPF
    CHANNEL:            PJSIP/anonymous-00000000, parked in Stasis
    BRIDGE:             not joined
    9900 ECHO INVOLVED: no
    RESULT:             FAIL — no conference media path was established

At 01:51:25 the bound node logged
`res_pjsip_sdp_rtp.c rtp_check_timeout: Disconnecting channel
'PJSIP/anonymous-00000000' for lack of audio RTP activity in 30 seconds`. That is
the expected consequence of a channel that is answered and parked in Stasis but
never bridged — there is no media destination, so no RTP flows.

## UI

    SESSION STATE: SessionState.Established
    UI STATE:      Connected
    CONFERENCE:    V0C6 Conference Leg Proof 20260815

The UI reached Connected correctly; the gap is behind it, in the runtime binding.

## Natural leave

Not exercised as a proof step: the runtime reclaimed the channel at 30 s before a
Leave could demonstrate anything about a bridged leg. The client converged
correctly on the runtime-initiated termination — the view returned to **Ready**
with the conference list restored, which is the already-accepted
remote-termination convergence behaviour.

    BYE:                    runtime-initiated (rtp_check_timeout)
    CHANNEL FINAL:          destroyed; 0 channels on the node
    PARTICIPANT FINAL:      removed / left
    BRIDGE FINAL:           0 members
    RUNTIME_CHANNEL_ID FINAL: null — it was never set, so the repository's
                            clear-vs-retain contract was not exercised
    UI FINAL:               Ready

## Cleanup idempotence

    RELEASE REQUEST COUNT:      one logical release for the attempt
    REMOVE OPERATION COUNT:     0 — no bound runtime channel existed to remove
    FINAL ADMITTED PARTICIPANTS: 0
    RECONCILIATION:             converged; no duplicate-cleanup storm

## V0-C5 preservation

    9900:            → static selected-application-runtime → T3 Echo fixture,
                     confirmed by the live resolver on the base node
    conf-*:          → canonical conference route → bound RuntimeNode,
                     confirmed by this proof's successful 200 OK from 10.42.0.173
    STATIC FALLBACK: none observed
    RESULT:          V0-C5 remains COMPLETE

## Failed proof steps

### PRODUCT_DEFECT — the participant binder does not recover the admission reference

    CLASSIFICATION: IMPLEMENTATION (blocking)
    CLAIM:          the inbound PJSIP channel is bound to the admitted
                    participant and added to the conference bridge
    EXPECTED:       runtime_channel_id = 1786758655.0 and that channel joins
                    utcp-conf-68c7d252-…
    ACTUAL:         runtime_channel_id null; bridge membership 0
    SIP:            INVITE → 200 OK → ACK; dialog established
    ASTERISK:       PJSIP/anonymous-00000000, Up, context from-kamailio,
                    exten conf-d7bdd037-…
    STASIS:         entered — Stasis(utcp-t0-observation,conf-d7bdd037-…)
    CHANNEL:        ARI id 1786758655.0
    PARTICIPANT:    d7bdd037-… admitted / self_admission at the time of the event
    BRIDGE:         utcp-conf-68c7d252-… present, 0 members

**Isolation, by positive elimination of every other branch of
`AsteriskConferenceParticipantBinder::bind()`:**

1. The event reached the binder — the deployed
   `AsteriskAriEventListener::ingestAriEvent()` contains
   `if ($type === 'StasisStart') { app(AsteriskConferenceParticipantBinder::class)->bind($node, $event); }`
   at line 231-233, dispatching before ingest, and a `stasis_start` receipt was
   written for this exact channel.
2. The deployed image contains the binder and the ARI client method —
   `attachInboundParticipantChannel` is present in both
   `AsteriskConferenceParticipantBinder.php` and `AsteriskAriClient.php` inside
   the running `asterisk-ari-events` pod.
3. No exception was thrown — the binder's `catch (Throwable)` logs
   `asterisk inbound conference participant binding rejected`, and that message
   does not appear in the worker log for the window.
4. `$channelId` was non-empty — the receipt records `channel_id` `1786758655.0`.
5. The database predicate was satisfied — the binder's join
   (participant + conference + active binding matching `conferences.runtime_node_id`
   + node active/ready + active unexpired telephony session) was reproduced
   read-only against the same rows and returned the participant, with
   `conf_state open`, `binding active`, `node active/ready`, `sess active`.

The only remaining false-return is the guard
`if ($channelId === '' || $reference === null) { return false; }`, so
`admissionReference($event['args'])` returned null — the admission reference was
not recovered from the StasisStart event even though Asterisk demonstrably passed
it (`core show channels concise` shows the Stasis data as
`utcp-t0-observation,conf-d7bdd037-…`).

    ROOT CAUSE:     the admission reference is not read from the StasisStart
                    event as `$event['args'][0]`
    AFFECTED FILES: apps/api/app/RuntimeAdapters/Asterisk/AsteriskConferenceParticipantBinder.php
                      (`admissionReference()`, and the guard in `bind()`)
                    apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriEventListener.php
                      (`ingestAriEvent()`, the shape of `$event` handed to `bind()`)

Not patched, per task scope. The next packet should log or assert the observed
`args` shape once, then correct the extraction; the acceptance test is that a
StasisStart for `conf-<uuid>` sets `runtime_channel_id` to the ARI channel id and
adds that id to `utcp-conf-<conferenceId>`.

## Non-blocking observation

The bound node received **52** `asterisk.ari.bridge.created` receipts in five
minutes (~10/min), and the conference's `configuration_generation` has reached
10. Conference-ensure appears to re-create the bridge on every reconciliation
pass rather than treating an existing owned bridge as converged. Bounded, not a
runaway storm, and it predates this proof, but it is worth a separate look after
the binder fix.

## Secret exposure review

No signaling credential secret, digest `Authorization` material, or Kubernetes
Secret value was displayed or written. The admission response exposed no
RuntimeNode DNS, pod IP, or internal SIP target.

## Code changes

    None.

## Retained state

    RuntimeNode d4539d79-… and conference 68c7d252-… retained, still
    active/ready and open/ready with the active binding — ready for the reproof
    after the binder fix.
    0 admitted participants; no orphaned channels; browser logged out.

## Status

    V0-C4 dialplan entry            LIVE-PROVEN
    V0-C4 participant binding       BLOCKED — one bounded defect
    V0-C5                           remains COMPLETE
    V0-C6                           BLOCKED
    V0                              IN PROGRESS
