# V0 — Narrow Natural Conference Admission Live Reproof

## Verdict

    V0_NATURAL_CONFERENCE_ADMISSION_LIVE_REPROOF_FOUND_BLOCKER

The scripted admission corridor itself passed end to end. The reproof also
reproduced two deterministic defects in the same reference-client conference
lifecycle, one of which fails the STEP 13 convergence requirement
("no false Connected UI", "participant no longer active"). V0 is therefore
**not** declared complete; it is blocked on one small, precisely isolated
frontend packet.

Explicitly proven and not to be re-run:

    natural member login                                PASS
    natural navigation to /dialer                       PASS
    bootstrap, telephony session, signaling credential  PASS
    WSS + REGISTER 401 → auth REGISTER → 200 OK         PASS
    joinable-conference discovery and filtering         PASS
    POST participants/self admission                    PASS
    admission-authority corroboration                   PASS
    browser INVITE from the registered UserAgent        PASS
    destination authority (admission == Request-URI)    PASS
    Kamailio routing and media mediation                PASS
    SIP dialog Established                              PASS
    canonical conference runtime corroboration          PASS
    bidirectional browser RTP over the media edge       PASS
    Connected UI                                        PASS
    natural Leave → BYE → DELETE self → Ready           PASS
    canonical participant/runtime cleanup               PASS
    negative admission guard on closed conferences      PASS

## Method

Evidence-only. No application source was modified. Natural Playwright session
from `https://app.utcp.local.test/login`; bounded break-glass passwords issued
through the canonical `make user-access-reset-password` target and changed
through the application's own forced-change flow. No preset storage state,
injected cookie, copied session, database/Redis session, or authentication
bypass. All API calls the report attributes to the member were issued either by
the application itself or as same-origin authenticated `fetch` inside that
natural session. SIP frames were captured from the real browser WebSocket by
wrapping `window.WebSocket` before the dialer mounted; digest `nonce`,
`response`, `cnonce`, and `opaque` values are redacted. No credential value
appears in this document.

## Canonical environment

    CONTEXT:            k3d-utcp-local
    KUBECONFIG:         .runtime/kubeconfig/utcp-local.yaml
    API IMAGE:          utcp/api:0.1.0-k1-dev       @sha256:5fd09e3b…
    WEB IMAGE:          utcp/web:0.1.0-k1-dev       @sha256:78e9b14e…
    GATEWAY IMAGE:      utcp/gateway:0.1.0-k1-dev   @sha256:8823a4bb…
    KAMAILIO IMAGE:     ghcr.io/kamailio/kamailio   @sha256:2552f809… (5.8.6)
    RTPENGINE IMAGE:    utcp/rtpengine:0.1.0-k1-dev @sha256:1d6b2fd5…
    ASTERISK IMAGE:     utcp/asterisk-ari:0.1.0-k1-dev @sha256:52c1e527…
    DEPLOYMENT FRESH:   yes — rebuilt, pushed, applied, and rolled from the
                        current working tree during this session

### Deployment freshness

The bounded conference-admission implementation packet did not deploy. The
cluster was therefore brought to the working tree through the canonical
lifecycle only:

    make k8s-config-check
    make k8s-image-build
    make k8s-image-push
    make k8s-apply
    kubectl rollout restart (every utcp-platform Deployment; same-tag images)
    make media-edge-config-check
    make media-edge-apply

Freshness was verified by content, not by tag:

* deployed web bundle contains `participants/self` and `Available conferences`
* deployed API contains `referenceClientSignalingDestination()` and both
  `signaling_destination` projections in `TelephonyDomainService.php`

### Environment repairs performed through canonical paths

1. **apiserver NetworkPolicy endpoint-pin drift.** The host had restarted and
   the Kubernetes API endpoint had moved to `172.21.0.3`, while
   `allow-traefik-kubernetes-api`, `allow-runtime-fencer-kubernetes-api`, and
   both observability policies were still pinned to `172.21.0.4` / `172.21.0.2`.
   `scripts/security/check-apiserver-policy-drift` failed. Repaired with
   `scripts/security/render-apiserver-policy` and `kubectl apply` of the four
   rendered policies; the drift check then passed. Classified ENVIRONMENT.
2. **Traefik route resync.** Traefik had started while the stale policy blocked
   the apiserver, so every host returned 404. One `rollout restart` of the
   existing Traefik Deployment restored routing (200 on `/login`). Ordinary
   reversible workload mutation; no topology change.
3. **External media-edge projection.** `make k8s-apply` leaves rtpengine in the
   single-interface base configuration, which advertises the Pod IP to the
   browser. `make media-edge-apply` restored the canonical two-interface split
   (`runtime/POD!POD`, `browser/POD!127.0.0.1`). Classified ENVIRONMENT; noted
   below as a lifecycle observation.

No cluster, registry, namespace, host port, node topology, or persistent volume
was created, replaced, or removed.

## Conference proof fixture

    CONFERENCE ID:      95e4c4e9-0349-4e03-8cc6-b09980cdbee5
    SLUG:               v0-conference-admission-reproof-20260815
    NAME:               V0 Conference Admission Reproof 20260815
    DESIRED STATE:      open
    OBSERVED STATE:     ready
    CONFIG GENERATION:  2 (observed 2)
    RUNTIME NODE:       c7e6f4ba-b925-462f-aff4-71c9fa9a4157
                        "RNP6 Readiness Reproof 20260809" (asterisk / asterisk-ari,
                        active / ready, conference.lifecycle + conference.participation)
    RUNTIME BINDING:    7262e0ff-4f4f-4e23-a08a-e1a83962d489, status active
    OPERATION:          conference.ensure — succeeded (1 attempt)
    FIXTURE AUTHORITY:  POST /api/v1/admin/conferences then
                        POST /api/v1/admin/conferences/{id}/desired-state
                        issued as same-origin authenticated fetch inside a natural
                        tenant-administrator browser session.

The Admin UI's `/operations/conferences` surface is read-only (Details only), so
it is not a conference-creation authority; the authorized admin API is the
canonical creation path the repository's own proof scripts use. No conference
row was written directly to PostgreSQL, no second fixture path was invented,
and no Asterisk CLI was used as conference authority. The retained RNP fixture
`rnp6-readiness-reproof-20260809` was not modified. The two closed RNM6
conferences were deliberately left in place as negative-discovery inputs.

The conference is **retained open** as live evidence.

## Natural member login

    LOGIN:  https://app.utcp.local.test/login
    USER:   t3-s3b-t3s3b1785716804@utcp.local.test  ("T3 S3B Prover t3s3b1785716804")
    TENANT: Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)

    capabilities returned by the application:
      telephony.conferences.join, telephony.conferences.view,
      telephony.sessions.create_own, telephony.sessions.view_own,
      telephony.signaling.issue_own, telephony.signaling.view_own,
      tenant.memberships.view, tenant.roles.view

All five capabilities the task requires were present and none was granted for
this proof. No preset or injected browser state was used; both personas signed
in from the real login page and completed the application's forced
password-change flow.

## STEP 1 — Registration reuse

    SIP IDENTITY:        ts-b2fdd53284a7405b873c19ca2672ac29
    TELEPHONY SESSION:   b2fdd532-84a7-405b-873c-19ca2672ac29 (active)
    WSS TARGET:          wss://sip.utcp.local.test/ws
    REGISTER:            CSeq 2 unauthenticated → 401 Digest (Kamailio 5.8.6)
                         → CSeq 3 Digest MD5 → 200 OK, expires=120
    UI STATE:            REGISTERED
    ROUTE REACHED BY:    clicking "Reference dialer" in Primary navigation

The extended 120-second registration-stability proof was intentionally **not**
repeated; the previous natural proof already established it. Registration did
not regress because of the conference changes.

## STEP 2 — Joinable conference discovery

    BACKEND RETURNED (GET /api/v1/reference-dialer/bootstrap):
      RNM6 Cordon Probe        desired closed, runtime_node_id null
      RNM6 Drain Work Proof    desired closed, runtime_node_id 6700025f…
      V0 Conference Admission Reproof 20260815  desired open, observed ready,
                                                runtime_node_id c7e6f4ba…

    VISIBLE JOINABLE CONFERENCES:      1 — the open, runtime-bound V0 conference
    CLOSED SHOWN AS AVAILABLE:         none
    JOIN CONTROL:                      one "Join" button, on the eligible entry only
    RESULT:                            PASS

The count-only "Conferences available 2" presentation defect from the previous
proof is gone. The backend remains the admission authority (STEP 14).

## STEP 3 — Join action

    POST /api/v1/conferences/95e4c4e9-0349-4e03-8cc6-b09980cdbee5/participants/self
    HTTP:                   201
    PARTICIPANT:            bd4cfd86-5cad-4098-874e-73b8a55cc781
    TELEPHONY SESSION:      b2fdd532-84a7-405b-873c-19ca2672ac29
    ROLE / REASON:          participant / self_admission
    SIGNALING DESTINATION:  sip:9900@sip.utcp.local.test
    RESULT:                 PASS

The destination is returned by UTCP. The member has no field, control, or code
path to supply or override it. No credential material is present in the
response.

## STEP 4 — Admission authority corroboration

    ADMISSION AUTHORITY:  ConferenceController::joinSelf
                          → TelephonyDomainService::admitSelf
                          → admitParticipant (tenant, session, capability, and
                            open+bound conference all revalidated under lock)
    PARTICIPANT:          bd4cfd86-5cad-4098-874e-73b8a55cc781
    CONFERENCE:           95e4c4e9-0349-4e03-8cc6-b09980cdbee5
    TELEPHONY SESSION:    b2fdd532-84a7-405b-873c-19ca2672ac29 (active)
    USER:                 the signed-in member
    RUNTIME NODE:         c7e6f4ba-b925-462f-aff4-71c9fa9a4157
    OPERATION:            conference.participant.ensure — succeeded, 1 attempt
    STATE:                desired admitted → observed joined at 19:32:43Z

Read-only PostgreSQL inspection was used only to corroborate rows the
application had already written.

## STEP 5 — Browser SIP INVITE

Captured from the real browser WebSocket, digest values redacted:

    → INVITE sip:9900@sip.utcp.local.test SIP/2.0        CSeq 1 INVITE
      From: <sip:ts-b2fdd53284a7405b873c19ca2672ac29@sip.utcp.local.test>
      Contact: <sip:…@….invalid;transport=ws;ob>
      Content-Type: application/sdp
      m=audio … UDP/TLS/RTP/SAVPF …   a=setup:actpass

    ← SIP/2.0 401 Unauthorized                            CSeq 1 INVITE
    → ACK                                                 CSeq 1 ACK
    → INVITE … Authorization: Digest algorithm=MD5,
        username="ts-b2fdd53284a7405b873c19ca2672ac29", …  CSeq 2 INVITE
    ← SIP/2.0 100 trying                                  CSeq 2 INVITE
    ← SIP/2.0 200 OK   Contact: <sip:10.42.1.33:5060>     CSeq 2 INVITE
      m=audio 40054 RTP/SAVPF 0 126
      c=IN IP4 127.0.0.1     a=setup:passive     a=rtcp-mux
      a=ice-ufrag/ice-pwd, a=candidate:… 127.0.0.1 40054 typ host
    → ACK sip:10.42.1.33:5060                             CSeq 2 ACK

    INVITE SENT:            yes, from the existing registered UserAgent
    REQUEST URI:            sip:9900@sip.utcp.local.test
    FINAL STATUS:           200 OK
    DIALOG STATE:           SessionState.Established (UI "Connected" 290 ms
                            after the Join click)
    BROWSER CONSOLE ERRORS: none from the dialer

No second `UserAgent` was created; sip.js `Inviter` reuses the registered one.

## STEP 6 — Destination authority

    ADMISSION DESTINATION:  sip:9900@sip.utcp.local.test
    INVITE REQUEST-URI:     sip:9900@sip.utcp.local.test
    MATCH:                  exact
    RESULT:                 PASS

UTCP chooses the destination; the client consumes it verbatim.

## STEP 7 — Kamailio / runtime routing

    kamailio_websocket_accepted        result=ok
    kamailio_registration_accepted     result=ok
    kamailio_application_dialog_challenge  method=INVITE  call_id=qm4kbpub…
    kamailio_application_dialog_media      result=media_offer   method=INVITE
    kamailio_application_dialog_media      result=media_answer  method=INVITE
    kamailio_application_dialog_media      result=media_delete  method=BYE

    TARGET/RUNTIME:  application-runtime-sip.utcp-runtime.svc.cluster.local:5060
                     → Pod asterisk-ari-88b89f756-4fbbv (10.42.1.33)
    ROUTING RESULT:  accepted, mediated, and torn down cleanly

Read-only log evidence. No call was originated or modified from any CLI.

## STEP 8 — SIP dialog established

    SESSION STATE:    SessionState.Established
    FINAL SIP RESULT: 200 OK, ACK sent to the 2xx Contact
    ESTABLISHED AT:   2026-08-14T19:32:40.426Z

## STEP 9 — Canonical conference runtime corroboration

Through the canonical ARI inspection authority
(`AsteriskAriClient::conferenceRuntimeSummary`), while connected:

    bridge_exists                          true
    owned_bridge                           true
    bridge_channel_count                   1
    participant_channel_exists             true
    participant_channel_in_bridge          true
    participant_any_channel_in_bridge      true
    runtime_reference_health               healthy_present
    participant_runtime_reference_health   healthy_present
    participant channel id                 utcp-part-<participant uuid>

    CONFERENCE:        95e4c4e9-0349-4e03-8cc6-b09980cdbee5 (open / ready)
    RUNTIME NODE:      c7e6f4ba-b925-462f-aff4-71c9fa9a4157
    BINDING:           7262e0ff-… active
    RECONCILIATION:    conference converged; participant converged
    OBSERVED AT:       2026-08-14 19:32:43Z

No row was created manually.

### Architectural note — two disjoint legs

This is factual, not a failure of any listed step, but it materially qualifies
what "the member is in the conference" currently means.

`referenceClientSignalingDestination()` returns the constant
`sip:9900@{realm}` for every conference. Extension `9900` is the committed
local T3 fixture `Answer(); Echo(); Hangup()` in
`infrastructure/kubernetes/overlays/local/runtime/extensions.local.conf`, served
by the base Asterisk Pod (`10.42.1.33`, runtime-node label
`local-asterisk-ari`), which is the only Asterisk that Kamailio routes SIP to.
The conference bridge and the participant channel live on a **different**
Asterisk — the RNP-managed Pod behind RuntimeNode `c7e6f4ba`, which publishes
ARI only and has no SIP service.

So the browser's audio path terminates on an echo application, while conference
membership is a separately control-plane-originated Local channel in the bridge.
Every V0 acceptance step above is genuinely satisfied, but the browser leg is
not the conference leg. Making the two the same is a real product step beyond
this task's scope and is recorded as deferred work, not as a proof failure.

## STEP 10 — Media / session sanity

Measured from the real `RTCPeerConnection` 8 s after establishment:

    iceConnectionState             connected
    connectionState                connected
    nominated candidate pair       succeeded — 409 packets sent, 405 received
                                   (77 977 bytes sent, 76 489 bytes received)
    inbound-rtp audio              402 packets, 64 320 bytes
    outbound-rtp audio             403 packets, 64 480 bytes
    media path                     browser → 127.0.0.1:40054 (host loopback,
                                   published by k3d serverlb) → NodePort
                                   rtpengine-media → rtpengine Pod
                                   (`--interface=browser/<pod>!127.0.0.1`)
                                   → Asterisk over `runtime/POD!POD`
    RESULT                         PASS — bidirectional RTP proven

V0 acceptance does not require media beyond an established dialog and an active
runtime binding; this evidence is recorded because the media path is what keeps
the dialog alive (see PRODUCT_DEFECT-30 and the environment note below). No
codec, MOS, loss, or quality measurement was performed.

## STEP 11 — Connected UI

    UI STATE:     "Connected"
    CONFERENCE:   "V0 Conference Admission Reproof 20260815"
    CONTROLS:     "Leave" (the only call control)
    CONTRADICTORY STATES: none — "Reference client status" read "Connected",
                  not Ready, Joining, or Needs attention
    RESULT:       PASS

## STEP 12 — Natural leave

    LEAVE CLICKED:            2026-08-14T19:33:04.316Z
    BYE OBSERVED:             → BYE sip:10.42.1.33:5060  CSeq 3 BYE  @19:33:04.333Z
                              ← SIP/2.0 200 OK           CSeq 3 BYE  @19:33:04.348Z
    PARTICIPANT DELETE HTTP:  DELETE …/participants/self → 200
    UI FINAL STATE:           "Ready" with the available-conference list restored
                              @19:33:04.340Z
    RESULT:                   PASS

Leave was clicked; the page was not navigated away from as the leave mechanism.

## STEP 13 — Runtime cleanup / convergence

    PARTICIPANT FINAL STATE:  desired removed, observed left, left_at 19:33:07Z
    OPERATIONS:               conference.participant.ensure  succeeded
                              conference.participant.remove  succeeded
    RUNTIME (ARI):            bridge_exists true, bridge_channel_count 0,
                              participant_channel_exists false,
                              participant_peer_channel_exists false,
                              participant_runtime_reference_health healthy_absent
    ALL FOUR PARTICIPANTS:    every participant created during this session
                              converged to removed / left
    SIP DIALOG FINAL STATE:   terminated, no orphan dialog
    REGISTRATION STATE:       still REGISTERED and Ready after the call
    RESULT:                   PASS for the natural-Leave path

Two convergence exceptions are recorded below as PRODUCT_DEFECT-30 (false
Connected UI on runtime-initiated termination) and OBSERVATION-2 (a duplicate
remove operation left one reconciliation state blocked).

## STEP 14 — Negative admission guard

Issued as same-origin authenticated fetch inside the same natural member
session:

    POST /api/v1/conferences/90152e05-…/participants/self  → 422
         {"message":"Conference must be open for admission."}
    POST /api/v1/conferences/95ca377e-…/participants/self  → 422
         {"message":"Conference must be open for admission."}

    participants created for either closed conference:  0
    new SIP frames on the browser socket:               0
    UI state:                                           unchanged ("Ready")
    RESULT:                                             PASS

Neither closed conference was offered by the UI, and the backend independently
refused both. Frontend filtering is not the security boundary.

## Failed proof steps

### PRODUCT_DEFECT-30 — runtime-initiated termination leaves a false Connected UI and an orphaned participant

    CLASSIFICATION:   IMPLEMENTATION (blocking for V0)
    CLAIM:            After the conference dialog ends, the client converges:
                      no false Connected UI, participant no longer active.
    EXPECTED:         A BYE received from the runtime returns the view to
                      "Ready" and releases the canonical participant through
                      DELETE /participants/self, exactly as the Leave button does.
    ACTUAL:           The view stays "Connected" with the conference name and a
                      Leave button, indefinitely, and the canonical participant
                      stays desired=admitted / observed=joined.
    REPRODUCED:       twice, independently

      run 1: ACK 19:24:40.229Z → runtime BYE 19:25:10.238Z → browser 200 OK
             → UI still "Connected" at 19:26:00Z; participant 8182b500 still
             admitted/joined at 19:27:00Z; only the manual Leave click at
             19:28:03Z released it.
      run 2: ACK 19:30:18.466Z → runtime BYE 19:30:48.470Z → browser 200 OK
             → UI still "Connected" at 19:31:00Z; participant 5de3268f still
             admitted/joined.

    BROWSER STATE:    UI "Connected" + "Leave"; sip.js session terminated.
    HTTP STATE:       no DELETE /participants/self is ever issued.
    SIP STATE:        dialog terminated correctly (BYE answered 200 OK).
    ADMISSION STATE:  participant remains admitted.
    CONFERENCE STATE: open / ready, still holding the participant channel.
    RUNTIME STATE:    the control-plane participant channel stays in the bridge
                      until the reconciler is told the participant was removed.
    ROOT CAUSE:       `apps/web/src/views/ReferenceDialerView.vue`
                      `updateCallState()` resets to `ready` only under
                      `nextState === 'terminated' && conferenceState.value === 'leaving'`.
                      A runtime-initiated BYE reaches `terminated` while the
                      state is still `connected`, so the guard never fires; and
                      nothing on that path calls
                      `referenceDialerApi.leaveConference()`, so the canonical
                      participant is never released. Only the `leave()` button
                      handler performs the canonical removal.
    AFFECTED AUTHORITY: V0 reference client (frontend). The canonical telephony
                      domain is not at fault — `removeSelf` works correctly when
                      it is called.
    AFFECTED FILE:    apps/web/src/views/ReferenceDialerView.vue
                      (`updateCallState`, and the missing canonical release on
                      remote termination)

Trigger note: in both runs the BYE came from Asterisk
`res_pjsip_sdp_rtp.c rtp_check_timeout` — "Disconnecting channel … for lack of
audio RTP activity in 30 seconds". The trigger was environmental in run 1 (the
external media-edge projection had not yet been re-applied) and transient in
run 2 (rtpengine had just rolled and the media path had not settled). The
**defect is independent of the trigger**: any runtime-side termination — RTP
timeout, conference close, node drain, operator removal — produces the same
false Connected state and the same orphaned participant.

### PRODUCT_DEFECT-31 — a failed INVITE leaves the client stuck in "Joining…" with the participant already admitted

    CLASSIFICATION:   IMPLEMENTATION
    CLAIM:            An admission that succeeds but whose INVITE fails must not
                      present an indeterminate state, and must not leave a
                      canonical participant behind.
    EXPECTED:         The view surfaces the failure ("Needs attention" /
                      "Conference unavailable") and does not strand the
                      participant.
    ACTUAL:           The view stayed at "Reference client status: Joining…"
                      indefinitely with no error, no Leave control, and no
                      recovery path, while participant 30f138b0 was admitted and
                      reconciled to observed=joined.
    OBSERVED:         19:28:25Z — POST participants/self 201, then
                      INVITE CSeq 1 → 401 → ACK → INVITE CSeq 2 (authenticated)
                      → 401 → ACK, and nothing further. Still "Joining…" at
                      19:29:40Z. No JavaScript error was logged.
    ROOT CAUSE:       `ReferenceDialerSignalingClient.invite()` awaits
                      `inviter.invite()`; on this failure path neither the
                      promise rejection nor the `failed` call-state callback
                      fired, so `ReferenceDialerView.vue` never left
                      `conferenceState = 'joining'`. The admission POST that
                      precedes the INVITE is not compensated when the INVITE
                      does not succeed.
    AFFECTED FILES:   apps/web/src/signaling/referenceDialerSignaling.ts
                      apps/web/src/views/ReferenceDialerView.vue

### OBSERVATION-1 — the one-time signaling credential expires after 120 s and is never renewed

    CLASSIFICATION:   IMPLEMENTATION (non-blocking for the admission corridor)
    OBSERVED:         `telephony_signaling_credentials` rows issued at 19:24:07,
                      19:30:18, and 19:32:30 each carry expires_at = issued_at +
                      120 s. `/dialer` issues exactly one credential on mount.
                      After expiry `kamailio_signaling_auth_view` no longer
                      exposes the identity, so Kamailio challenges and then
                      rejects both REGISTER and INVITE, while the view continues
                      to display "REGISTERED".
    EFFECT:           The dialer is usable for roughly two minutes after page
                      load. This is the direct cause of PRODUCT_DEFECT-31's
                      401s: that join was attempted 4 min 18 s after mount.
                      Remounting the view (Dashboard → Reference dialer) issues a
                      fresh credential and restores the corridor.
    AFFECTED AREA:    reference-client credential lifecycle; the previous natural
                      registration proof recorded the same expiry as clean
                      convergence because the member navigated away at that point.

### OBSERVATION-2 — a duplicate remove operation terminal-failed and left one reconciliation state blocked

    CLASSIFICATION:   ENVIRONMENT trigger with an IMPLEMENTATION consequence
    OBSERVED:         The Leave at 19:33:04Z produced two
                      `conference.participant.remove` operations for
                      bd4cfd86-…: the first succeeded at 19:33:06Z; the second
                      failed three times with `ari_http_unavailable`
                      (19:33:06 / 19:33:21 / 19:33:51) and became
                      `terminal_failed`.
    CONSEQUENCE:      `runtime_reconciliation_states` for that participant is
                      `blocked` and does not self-clear — `ConferenceParticipantReconciler`
                      returns `blocked` whenever the last operation is
                      `terminal_failed`, without re-evaluating actual runtime
                      state. The participant's canonical state
                      (removed / left) and the runtime (channel absent,
                      healthy_absent) are both correct, so the block is stale.
                      The three other participants of this session converged.
    TRIGGER:          transient. Six consecutive ARI inspections against the same
                      node immediately afterwards all succeeded (6/6), and the
                      Asterisk Pod logged no error in that window.

### Environment note — `make k8s-apply` reverts the external media-edge projection

`make k8s-apply` rewrites the rtpengine Deployment to the single-interface base
form, dropping `--interface=browser/<pod>!127.0.0.1`. Any browser media proof
must run `make media-edge-apply` afterwards. Not a V0 defect; recorded so the
next session does not repeat run 1's diagnosis.

## Code changes

    None.

## Environment and topology changes

    None. No cluster, registry, namespace, host port, load balancer, node,
    persistent volume, or deployment mechanism was created, replaced, or
    removed. Images were rebuilt and Deployments rolled through the canonical
    targets only.

## Improvised or non-canonical actions

    None.

## Retained state

    Conference 95e4c4e9-0349-4e03-8cc6-b09980cdbee5
      "V0 Conference Admission Reproof 20260815" — left open / ready as live
      evidence and as the fixture for the next narrow reproof.
    RuntimeNode c7e6f4ba-b925-462f-aff4-71c9fa9a4157
      "RNP6 Readiness Reproof 20260809" — untouched, still active / ready.
    Four conference participants from this session — all removed / left.
    Break-glass passwords were issued for `admin@utcp.local.test` and the member
    persona and changed through the application's own flow; both sessions were
    logged out at the end.

## V0 completion decision

    V0 BLOCKED — reference-client conference lifecycle only.

The admission corridor itself is proven. What remains is one small frontend
packet: converge the view and release the canonical participant when the dialog
ends for any reason other than the Leave button, and surface a failed INVITE
instead of stranding "Joining…".
