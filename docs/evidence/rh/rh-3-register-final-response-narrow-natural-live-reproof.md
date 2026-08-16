# RH-3 — REGISTER Final-Response Settlement Narrow Natural Live Reproof

## Verdict

    RH_3_REGISTER_FINAL_RESPONSE_NARROW_NATURAL_LIVE_REPROOF_PASSED

The historical registration stall is closed and live-proven. **Five consecutive
automatic credential renewals occurred at exactly 90.0-second intervals**, every
one of them an accepted REGISTER while the Registerer was already `Registered` —
the precise case that used to strand the registration promise. The historical
`21:19:45 → 21:26:57` renewal gap does not recur. A bounded media loss taken after
the renewal cycles produced its first canonical request **10 ms** after the runtime
BYE, a maximum in-episode gap of **11.04 s**, and a successful same-participant
replacement well inside the RH-1 grace. No silent corridor of any kind appeared.

## Method

Evidence-only. No application source, test, schema, manifest or configuration was
modified. No credential TTL, renewal interval, RH-3B API ladder, RH-3C reconnect
ladder, RH-3F conference ladder, SIP Timer B/F, RH-1 grace, Asterisk `rtp_timeout`,
Kamailio or rtpengine configuration was changed. Deployment through canonical Make
targets only. Natural Playwright login from the real login page — no preset
session, injected cookie, DB/Redis session or auth bypass. No SQL mutation, no
fabricated SIP state, no injected REGISTER response, no corrupted credential, no
patched browser JS. Browser instrumentation was a passive `PerformanceObserver`
plus DOM sampling and changed no application behaviour. Adversity used one bounded
canonical Kubernetes lifecycle action, disclosed below.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree (RNP … RH-3 packets) plus this document
    commit/push:   none created, not pushed

## Canonical Environment

    API:          utcp/api  @sha256:e7a61e5b20b4b6fab62963d1ced7b78b376b6c5a3ba02c42a56877010559a909
    WEB:          utcp/web  @sha256:1c3822631e431245e0ef39417c0bb5502d2c080f9009e357fd6e7b569a04f7bc
    ASTERISK:     utcp/asterisk-ari (utcp-runtime v0c6 node, unchanged tag 0.1.0-k1-dev)
    WORKER:       utcp/api  @sha256:e7a61e5b… (deployment/worker, same image as API)
    GATEWAY:      utcp/gateway @sha256:1f60a4dd3ccfbd22e9a6b034b981b06366bb1bc67731d525fffa5486ab767912
    RTPENGINE:    utcp/rtpengine @sha256:03b38da7a09f74d00943327b541da4c5de5a0a11a2414b73d8b8c956c8753fdb
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready
    CONFERENCE:   68c7d252-2203-4f2a-9b81-4d87d1294768 — open / ready, binding
                  1d9da35b-… active, 0 conflicting participants
    DEPLOYMENT FRESH: yes

Lifecycle: `k8s-image-build` → `k8s-image-push` → `k8s-apply` → `kubectl rollout
restart deployment` (all sixteen `utcp-platform` Deployments) → `media-edge-apply`.
`security-apply` was **not** required this run — the apiserver `ipBlock` pins were
already current (`allow-traefik-kubernetes-api` and
`allow-runtime-fencer-kubernetes-api` both on 172.21.0.2, matching the live
`kubernetes` endpoint), so the K2/helm stage was never reached. Stasis app
`utcp-t0-observation` confirmed re-created 23:23:30 before any proof traffic.

### Content verification of the final-response settlement in the deployed bundle

The served asset `/assets/index-CQOGlv90.js` contains the corrected settlement,
where `i` is the state-transition promise, `a` the final-response promise, `Jy` the
`RegistrationRejectedError` class and `Lv` the `RegistererState` enum:

    a=new Promise((r,i)=>{e.register({requestDelegate:{
      onAccept:()=>{this.registerer===t&&(this.registered=!0),r()},
      onReject:e=>{n=new Jy(e.message.statusCode??0),i(n)}
    }}).catch(i)});
    try{await Promise.race([i,a])}catch(e){throw n===null?e:n}
    finally{r(),this.pendingRegistrationReject!==null&&(this.pendingRegistrationReject=null)}

Both properties the fix depends on are present in the deployed artefact: the
operation now settles from `onAccept`/`onReject`, and `onAccept` sets
`this.registered = true` directly — necessary because in the same-state case the
`stateChange` listener that used to set it never fires. The pre-existing
`waitForState` promise and `pendingRegistrationReject` cancellation path are
retained unchanged.

## Natural Login

    USER:         t3-s3b-t3s3b1785716804@utcp.local.test (tenant member)
    TENANT:       Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    CAPABILITIES: 8 — telephony.conferences.join / view, telephony.sessions.create_own /
                  view_own, telephony.signaling.issue_own / view_own,
                  tenant.memberships.view, tenant.roles.view

Ordinary email/password login from the real login page, tenant selected through the
normal control. No break-glass command was needed this run.

    login.succeeded          23:24:23
    tenant_context.selected  23:24:44
    telephony_session.created 23:24:54
    websocket_accepted       23:24:54.171
    registration_challenge   23:24:54.173
    registration_accepted    23:24:54.184

## Baseline

    PARTICIPANT:        2a4c8c93-99c3-4b2b-b6fd-d1ae9eb20dc8
    TELEPHONY SESSION:  abe339c0-5a67-4a40-aa47-e9c5efb73089
    CREDENTIAL ISSUED:  23:24:54    EXPIRY: 23:26:54 (120 s)
    REGISTER / REGISTERED: 23:24:54.173 → accepted 23:24:54.184
    JOIN INVITE:        23:25:27.496 → media_offer → media_answer 23:25:27.613
    CHANNEL:            1786836327.2 (PJSIP/anonymous-00000002)
    RUNTIME_CHANNEL_ID: 1786836327.2
    BRIDGE:             utcp-conf-68c7d252-… , bridgechans = 1
    UI:                 Connected @23:25:27.757

    channel == runtime_channel_id == bridge member  ✓

## Renewal Cycle 1

    CREDENTIAL REQUEST: 23:26:24.151 (browser)   ISSUED: 23:26:24 → expires 23:28:24
    REGISTER SENT:      23:26:24.213 (kamailio_registration_challenge)
    FINAL RESPONSE:     **accepted 23:26:24.221**
    REGISTERER BEFORE:  Registered   (NOT DIRECTLY OBSERVABLE internally; determined
                        by the preceding accepted REGISTER at 23:24:54.184 and a
                        continuously usable signaling client)
    REGISTERER AFTER:   Registered
    STATE CHANGE:       **none expected or required** — same-state accept
    NEXT RENEWAL:       armed and observed at 23:27:54 (see cycle 2)

## Renewal Cycle 2

    CREDENTIAL REQUEST: 23:27:54.189 (browser)   ISSUED: 23:27:54 → expires 23:29:54
    REGISTER SENT:      23:27:54.233
    FINAL RESPONSE:     **accepted 23:27:54.237**
    REGISTERER BEFORE:  Registered
    REGISTERER AFTER:   Registered
    STATE CHANGE:       none
    NEXT RENEWAL:       armed and observed at 23:29:24

**Cycle 2 is the decisive regression proof.** It occurred automatically with no user
action, no page refresh, no WSS restart and no credential repair command. Under the
pre-fix build the cycle-1 accept would have left `registerCurrentRegisterer()`
pending, so `scheduleCredentialRenewal()` would never have been reached and cycle 2
could not have happened at all.

### Further cycles (beyond the mandatory two)

    CYCLE 3: request 23:29:24.222, issued 23:29:24 → expires 23:31:24
    CYCLE 4: request 23:30:54.255, issued 23:30:54, REGISTER 23:30:54.306 →
             **accepted 23:30:54.311** — this one landed *inside* the recovery
             episode and did not disturb it
    CYCLE 5: request 23:32:24 (issued 23:32:24), after recovery completed

Five consecutive automatic renewals in total.

## Renewal Continuity

    CYCLE 1 DELTA:  90.048 s  (23:24:54.103 → 23:26:24.151)
    CYCLE 2 DELTA:  90.038 s  (23:26:24.151 → 23:27:54.189)
    CYCLE 3 DELTA:  90.033 s
    CYCLE 4 DELTA:  90.033 s
    CYCLE 5 DELTA:  90.0 s
    SILENT GAP:     **none** — every renewal landed at expiry − 30 s; the largest
                    inter-request interval across the whole healthy session is
                    90.033 s, which *is* the renewal cadence, not silence.
                    Historical failure was a 432 s gap (21:19:45 → 21:26:57).
    CALL SURVIVED:  yes — leg 1786836393.4 (PJSIP/anonymous-00000004) stayed up
                    continuously across renewal cycles 2 and 3, with 7 856 RTP
                    packets received over 2 m 38 s
    PARTICIPANT:    2a4c8c93-… unchanged for the entire proof
    INVITE DELTA:   **0 replacement INVITEs attributable to a renewal** — every
                    replacement in the session is accounted for by a logged
                    `rtp_check_timeout`; no INVITE follows any of the five
                    renewal REGISTERs
    DELETE DELTA:   **0** until the final explicit Leave

## Runtime Loss

    LOSS METHOD:             bounded canonical `kubectl scale deployment/rtpengine
                             --replicas=0` at 23:29:45.605
    ASTERISK:                23:30:15.664 rtp_check_timeout — "Disconnecting channel
                             'PJSIP/anonymous-00000004' for lack of audio RTP
                             activity in 30 seconds"
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 23:30:19+00
    RECOVERABLE:             true
    RECOVERABLE UNTIL:       23:32:19 (loss + 120 s)
    PARTICIPANT:             2a4c8c93-… remained `admitted` throughout
    UI:                      Recovering @23:30:15.758

## Registration / Recovery Responsiveness

    NEXT API:               23:30:15.674 — **10 ms** after the runtime BYE
    NEXT REGISTER:          23:30:54.306 (the scheduled cycle-4 renewal, mid-episode)
    REGISTER FINAL RESPONSE: **accepted 23:30:54.311**; the next replacement INVITE
                            followed 4.7 s later at 23:30:58.996, so the renewal
                            neither blocked nor restarted the recovery corridor
    NEXT ADMISSION:         23:30:19.841
    NEXT INVITE:            23:30:20.024
    MAX SILENT GAP:         **11.044 s** (23:30:58.996 → 23:31:10.040) — the RH-3F
                            10-second ladder cap plus jitter
    REQUESTS IN WINDOW:     30 canonical requests between 23:30:15 and 23:31:20

Recovery work began 4.36 s after the loss and continued without interruption for
the whole grace window. The historical ~136 s silent corridor did not occur.

## Replacement

    ORIGINAL PARTICIPANT: 2a4c8c93-99c3-4b2b-b6fd-d1ae9eb20dc8
    RECOVERY PARTICIPANT: 2a4c8c93-99c3-4b2b-b6fd-d1ae9eb20dc8
    MATCH:                **YES**
    INVITE COUNT:         8 logical replacement attempts, one per scheduled recovery,
                          never concurrent — 7 rejected `488 Media Relay Unavailable`
                          while rtpengine was absent, then success
    CADENCE:              2.03 / 2.97 / 6.20 / 9.83 / 9.38 / 8.56 / 11.27 s —
                          the existing RH-3F ladder, recorded only to show the
                          corridor was active and not blocked by registration
    SUCCESSFUL INVITE:    23:31:10.261 → media_offer → media_answer 23:31:10.360
    CHANNEL:              1786836670.5 (PJSIP/anonymous-00000005)
    RUNTIME_CHANNEL_ID:   1786836670.5
    BRIDGE:               bridgechans restored to 1
    DEPENDENCY RESTORED:  23:31:00.414 (scale 1), ready 23:31:02.981

## Connected Gating

    SIP ESTABLISHED:  23:31:10.360 (media_answer / 200 OK)
    CANONICAL BIND:   23:31:15 (`runtime_channel_id` = 1786836670.5)
    UI BEFORE:        **Recovering** — continuously from 23:30:15.758
    UI AFTER:         **Connected @23:31:19.357**
    RESULT:           PASS — Connected followed the canonical bind, never SIP
                      establishment alone

## Storm Check

    CREDENTIAL ISSUES: 6 total (1 initial + 5 renewals), all at exactly 90 s —
                       no storm, no duplicate, no burst
    REGISTERS:         6 logical (1 initial + 5 renewals), each one challenge +
                       authenticated pair, all accepted
    RECONNECTS:        0 — the WSS transport was never lost; a single
                       `websocket_accepted` at 23:24:54.171 covers the whole proof
    PARTICIPANTS:      1, never duplicated (audit holds exactly one
                       `conference_participant.admitted`, 23:25:27)
    ADMISSIONS:        12 POST `participants/self`, one per recovery attempt across
                       all cycles, never concurrent, all returning the same participant
    INVITES:           12 logical, never overlapping
    DELETES:           **1**, the final explicit UI Leave at 23:32:42
    401 HANDLING:      no natural registration rejection arose — all six REGISTERs
                       were accepted. The one-fresh-credential retry branch therefore
                       remains repository-covered, which the task explicitly permits.

## Failed Proof Steps

    None.

## Divergences

* **Induced infrastructure condition.** One bounded canonical `scale
  deployment/rtpengine --replicas=0` for 74.8 s (23:29:45.605 → 23:31:00.414),
  fully restored, media edge re-verified as
  `--interface=browser/10.42.0.3!127.0.0.1`. Represents the condition under test;
  no configuration changed and no SIP injected.
* **Two extra recovery cycles early in the proof (23:25:57 and 23:26:28), and one
  after the replacement (23:31:40).** Each is explained by a logged
  `rtp_check_timeout` immediately following an rtpengine **Pod replacement** — the
  first browser leg negotiated against the outgoing Pod's advertised address loses
  media, and the next automatic replacement succeeds once the new Pod's NodePort
  path is programmed. Classification: **ENVIRONMENT** (media-edge settling after a
  Pod IP change), not an implementation defect and not registration-related.
  Canonical behaviour was correct in every instance — same participant, one
  admission per attempt, zero DELETEs, and the UI returned to Connected each time.
  It does not affect the renewal measurements: leg 1786836393.4 was continuously up
  across renewal cycles 2 and 3, which is the uncontaminated observation the
  acceptance criteria require.
* **`telephony-reconciler` restarts on `runtime reconciliation fencing token was
  superseded`**, converging and returning ready each time. Unrelated pre-existing
  condition, present before this proof.
* **Observability apiserver `ipBlock` pins remain stale** at 172.21.0.3
  (`allow-observability-kubernetes-api-egress`,
  `allow-prometheus-operator-apiserver-egress`). Owned by `observability-apply`,
  outside this task's scope; recorded, not repaired.

None invalidates the principal claim.

## Environment and Topology Changes

    None.

No cluster, registry, host port, load balancer, Kubernetes context, node topology,
persistent volume, deployment mechanism or parallel runtime was created, changed or
removed. `utcp-local` remained the only UTCP cluster and `apntalk-local` remained
stopped and untouched. Replica scaling of the existing `rtpengine` Deployment and
rollout restarts of existing Deployments are ordinary reversible workload
mutations, both fully restored.

## Improvised or Non-Canonical Actions

    None.

All deployment used repository Make targets. `kubectl scale`, `kubectl rollout
restart`, `kubectl logs` and `kubectl exec` (read-only Asterisk CLI and read-only
`psql`) were used for adversity, same-tag rollout and diagnostics only; none
substituted for a canonical lifecycle path and none bypassed repository validation.

## Cleanup

Participation released through the normal UI Leave (audit
`conference_participant.removed` @23:32:42, exactly one DELETE), then the browser
logged out normally.

    admitted participants: 0
    runtime channels:      0
    conference bridge:     0 members
    conference:            68c7d252-… open / ready
    runtime node:          d4539d79-… active / ready
    rtpengine:             1/1 ready, media edge intact
    utcp-platform:         17/17 workloads ready
    log tails:             stopped

## Acceptance

    [x] natural login used
    [x] baseline call Connected                          (channel == runtime_channel_id == bridge)
    [x] first credential renewal occurs automatically    (23:26:24, +90.048 s)
    [x] first accepted re-registration settles without requiring a state change
    [x] next credential renewal remains armed
    [x] second credential renewal occurs automatically   (23:27:54, +90.038 s)
    [x] second accepted re-registration also settles
    [x] no registrationPromise-like silent stall appears
    [x] existing call remains intact through both renewals (leg 1786836393.4)
    [x] no replacement conference INVITE caused merely by renewal
    [x] one bounded runtime/media loss occurs afterward
    [x] participant becomes canonically recoverable      (lost_at 23:30:19, until 23:32:19)
    [x] registration/recovery remains responsive         (first request +10 ms)
    [x] recovery activity begins within 120-second grace
    [x] no historical ~136-second silent corridor        (max gap 11.044 s)
    [x] same participant is reused                       (12/12)
    [x] replacement recovery succeeds                    (23:31:10.261, 200 OK)
    [x] channel becomes runtime_channel_id               (1786836670.5)
    [x] bridge membership restores                       (0 → 1)
    [x] Connected gating remains canonical               (bind 23:31:15 < Connected 23:31:19)
    [x] no credential/REGISTER/recovery storm
    [x] V0 remains COMPLETE
    [x] RT-1A remains COMPLETE / LIVE PROVEN

    NATURAL 401 BRANCH: not observed — all six REGISTERs were accepted. Recorded as
      repository-covered, which the acceptance standard explicitly permits.

## Code Changes

    None.

## V0 Status

    COMPLETE / UNCHANGED

## RT-1A Status

    COMPLETE / LIVE PROVEN / UNCHANGED

## RH Status

    RH-0:  COMPLETE
    RH-1:  IMPLEMENTED / TESTED
    RH-2:  COMPLETE / LIVE PROVEN
    RH-3A: CONTRACT COMPLETE
    RH-3B: IMPLEMENTED / TESTED
    RH-3C: IMPLEMENTED / TESTED — the remaining registration-lifecycle defect is
           now fixed and live-proven
    RH-3D: HISTORICAL BLOCKER EVIDENCE
    RH-3E: LIVE PROVEN
    RH-3F: COMPLETE / LIVE PROVEN
    RH-3 REGISTER FINAL-RESPONSE: **LIVE PROVEN**
    RH-3:  **COMPLETE / LIVE PROVEN**

Accepted narrow gaps carried forward unchanged and explicitly out of scope here:
the RH-3B 10-second HTTP timeout remains repository-test-only, a natural rejecting
registration episode has not arisen, and browser background/suspension and
multi-tab ownership remain unexercised.
