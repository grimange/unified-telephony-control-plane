# RH-3D — Adversarial / Slow-Network Natural Browser Live Proof

## Verdict

    RH_3D_ADVERSARIAL_SLOW_NETWORK_NATURAL_LIVE_PROOF_FOUND_BLOCKER

Most of the combined RH-3B + RH-3C contract is live-proven: transport loss
invalidates registration truth, reconnection is automatic and bounded, REGISTER
send is not treated as success, an established dialog survives both a WSS outage
and a full API outage, offline/online flapping produces no work at all, dead-dialog
recovery reuses the same participant with exactly one replacement INVITE, and the
canonical 120-second grace remains the ultimate authority with clean convergence to
Ready.

Two client defects were isolated:

1. **A rejected recovery INVITE ends replacement attempts for the rest of the
   grace.** `awaitingRecoveryBinding` stays latched after a non-2xx final response,
   so `beginRecovery()` only re-polls and never issues another INVITE — even after
   the impairment clears 67 s before the grace expires.
2. **With no participation, a transport loss is never repaired.** After an idle
   WSS outage the client stayed on "SIP registration failed" for 100+ s after the
   transport was available again, with two 401 REGISTERs and **no** fresh-credential
   retry.

## Method

Evidence-only. No application source, schema, manifest, or configuration file was
modified; no timeout/retry constant, SIP.js option, readiness predicate, RH-1 grace,
or `rtp_timeout` was changed; no manual reconnect/recovery command was added.
Deployment through canonical Make targets. Natural Playwright login from the real
login page; no preset session, injected cookie, DB/Redis session, or auth bypass. No
SQL mutation, no manual `runtime_channel_id` or timestamp change, no participant
fabrication, no SIP injection, no manual job invocation. Browser instrumentation was
passive logging only (`fetch`, `WebSocket` construct/open/close/send/message)
and changed no application behaviour. Adversity was produced only with bounded
browser network controls (`setOffline`, `route`, `routeWebSocket`) and normal
Kubernetes lifecycle actions on infrastructure workloads (`rollout restart`,
`scale`), all disclosed below.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree (RH-1 … RH-3C packets)
    commit/push:   none created, not pushed

## Canonical Environment

    API:          utcp/api  @sha256:428fbf44a2c959587b220fa821deacb721039243db78bfe5116f3ea9c2eff1ac
    WEB:          utcp/web  @sha256:047d34b09905f6b8ba5fd4807c04490a5ba8499120d8f561513e7aced2966377
    ASTERISK:     utcp/asterisk-ari @sha256:b2d7184892f9a133d7f0909347d8ae904d250111d3a362569b45c3e4da04dc69
    WORKER:       utcp/api  @sha256:428fbf44… (deployment/worker, same image as API)
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready, sip/udp :5060
    CONFERENCE:   68c7d252-2203-4f2a-9b81-4d87d1294768 — open / ready, binding active
    DEPLOYMENT FRESH: yes

Lifecycle: `k8s-image-build` → `k8s-image-push` → `k8s-apply` → rollout restart of
the ten `utcp-platform` Deployments → `media-edge-apply`. Stasis app
`utcp-t0-observation` confirmed registered (10:25:52) before any proof traffic.

Content verification in the deployed bundle
`/usr/share/nginx/html/assets/index-DWWK54K0.js`:

    ApiRequestTimeoutError                        present   (RH-3B request timeout)
    1e3,2e3,3e3,5e3,8e3,1e4                       present   (RH-3B/3C retry ladder)
    "The WSS transport is unavailable"            present   (RH-3C reconnect gate)
    "The browser is offline"                      present   (RH-3C offline suppression)
    "The SIP registration attempt was superseded" present   (RH-3C registration confirmation)
    "The SIP registrar rejected registration"     present   (RH-3C truthful invalidation)

## Natural Login

    USER:         t3-s3b-t3s3b1785716804@utcp.local.test (tenant member)
    TENANT:       Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    CAPABILITIES: telephony.conferences.join / view, telephony.sessions.create_own /
                  view_own, telephony.signaling.issue_own / view_own,
                  tenant.memberships.view, tenant.roles.view

Access restored through the repository break-glass single-account password-recovery
command with an operator reason recorded for audit; the browser then completed the
ordinary login and forced password-change flow from the real login page.

## Baseline

    TELEPHONY SESSION:  8bce0e6d-7713-42fc-8004-21904487218e
    PARTICIPANT:        fae04cfa-cd1f-45f2-b71f-f38a34c45fed (phase 1)
                        1abcf643-7f61-456a-bfc4-5c2164548064 (phase 2)
    INVITE:             10:26:49.757 CSeq 1 → 401 → ACK → 10:26:49.761 CSeq 2 →
                        100 trying → 200 OK 10:26:49.910 → ACK
    CHANNEL:            1786789679.30 (PJSIP/anonymous-0000001e) after two natural
                        recovery cycles
    RUNTIME_CHANNEL_ID: 1786789679.30
    BRIDGE:             utcp-conf-68c7d252-… , bridgechans = 1
    UI:                 Connected

REGISTER→Registered ordering is structurally enforced in the deployed build —
`invite()` throws `The SIP client is not registered.` unless
`transportConnected && registered && registerer.state === Registered` — and was
observed directly at the Scenario A restoration below. The baseline REGISTER
predates the probe reset and is therefore recorded from the restoration case rather
than claimed here.

## Scenario A — WSS Loss / Reconnect

Transport loss produced by a canonical `kubectl rollout restart deployment/kamailio`
(10:30:42.204 → ready 10:30:47.988). No SIP was injected and no configuration
changed.

    LOSS:                    10:30:47.965  WebSocket close, code 1006
    REGISTRATION INVALIDATED: yes — the client emitted signaling `failed`
                             ("WebSocket closed wss://sip.utcp.local.test/ws
                             (code: 1006)" was rendered in a later idle repeat of
                             this condition), and no INVITE was attempted while down
    isRegistered:            false (structurally: `transportConnected` cleared on
                             disconnect, and `invite()` is gated on it)
    RECONNECT ATTEMPTS:      10:30:48.888 (construct #2) → refused, close 1006
                             10:30:51.026 (construct #3) → open
    OBSERVED DELTAS:         loss → attempt 1: 0.92 s   (ladder step 1 s, ±20 % → 0.8–1.2 ✓)
                             attempt 1 → attempt 2: 2.13 s (ladder step 2 s, ±20 % → 1.6–2.4 ✓)
    RECONNECT CORRIDOR:      exactly one — no concurrent constructs at any instant
    TRANSPORT RESTORED:      10:30:51.037
    REGISTER:                10:30:51.037 CSeq 7 → 401
                             10:30:51.041 CSeq 8 → 401
    CREDENTIAL RETRY:        10:30:51.049 POST signaling-credential → 201 (exactly one)
    REGISTERED:              10:30:51.095 CSeq 2 → 401 → 10:30:51.098 CSeq 3 → **200 OK**
                             at 10:30:51.105
    INVITE GATE:             no replacement was required — the established dialog
                             survived (Scenario B). REPLACEMENT INVITE COUNT: 0

This also exercised the **REGISTER authentication retry** naturally: first rejection
→ exactly one fresh credential → second REGISTER → 200 OK.

## Scenario B — Existing Dialog Survival

    SESSION BEFORE/DURING/AFTER: same INVITE dialog, never re-established
    CHANNEL BEFORE:              1786789679.30 (PJSIP/anonymous-0000001e), bridged
    CHANNEL AFTER:               1786789679.30, bridged — continuously present in
                                 every 2-second server sample from 10:30:40 through
                                 10:32:10 and beyond
    RUNTIME_CHANNEL_ID:          unchanged throughout
    ADMISSION DELTA:             0
    INVITE DELTA:                0
    DELETE DELTA:                0
    MEDIA:                       uninterrupted — the channel outlived the 30 s
                                 `rtp_check_timeout` many times over (251 s)
    RESULT:                      **PASS** — a WSS transport loss and reconnection did
                                 not disturb the established dialog, its runtime
                                 channel, or its bridge membership

## Scenario C — API Up / SIP Down

Idle repeat (no participation) with the SIP WSS refused at the browser
(`routeWebSocket`) and the transport dropped by a Kamailio restart at 10:47:37.

    API:        reachable — bootstrap returned **200 in 21 consecutive samples**
                across the whole 82-second outage
    TRANSPORT:  unavailable (close 1006 at 10:47:42.024)
    REGISTERER: not Registered
    BOOTSTRAP:  200:null (no participation existed)
    INVITE:     **0** — no INVITE was attempted while the transport was unavailable
                or while unregistered
    UI:         "SIP registration failed" throughout

The required negative properties hold. The positive property — automatic repair —
does not; see Failed Proof Step 2.

## Scenario D — API Down / SIP Up

API made unreachable at the browser (`page.route('**/api/v1/**', abort)`) for 39 s
(10:33:01.911 → 10:33:40.975) with a healthy established call.

    API:                unreachable
    SIP:                transport up, registered
    DIALOG:             established and unchanged
    BOOTSTRAP REQUESTS: 0 issued by the client (nothing was broken, so nothing was
                        retried)
    INVITE COUNT:       0
    DELETE COUNT:       **0**
    UI:                 Connected with the Leave control, no Recovering banner, for
                        all 13 samples
    ON RETURN:          bootstrap 200 `active` immediately; UI Connected; one clean
                        resync corridor, no storm
    RESULT:             **PASS** — the mandatory invariant holds: an API outage does
                        not BYE the call, does not issue a canonical Leave, and does
                        not create another INVITE

## Scenario E — API Timeout / Retry

    REQUEST:             not exercised
    TIMEOUT:             not observed
    NEXT RETRY:          n/a
    CONCURRENT REQUESTS: n/a
    LADDER:              n/a

A hung `**/api/v1/reference-dialer/bootstrap` route was installed at 10:34:37 and a
media-plane interruption was used to try to force a recovery cycle through it, but
the recovery-path bootstrap was never reached in that window (see Divergences —
a Kamailio restart earlier in the run had destroyed in-dialog routing state for the
pre-existing dialog, so the runtime BYE never reached the browser and no recovery
began). The 10-second `AbortController` branch is therefore recorded as a **narrow
proof gap**, not as a pass or a failure. Repository automated coverage for
`ApiRequestTimeoutError` remains the evidence for that branch.

## Scenario F — Flapping

    EVENTS:     10:40:30.005 offline · 10:40:34.009 online ·
                10:40:38.014 offline · 10:40:42.017 online  (4 transitions in 12 s,
                then 20 s settle)
    API REQUESTS: **1** — and it is the scheduled credential renewal at 10:41:00
                (≈90 s cadence), not a response to any transition
    RECONNECTS:  0 WebSocket constructs — the transport survived, so no reconnection
                was needed
    REGISTERS:   2 transmissions = 1 logical (401 + auth) at 10:41:00, part of the
                credential renewal
    ADMISSIONS:  0
    INVITES:     0
    DELETES:     0
    STORM:       **none** — the online events coalesced into no work at all, because
                the established dialog survived and `beginRecovery()` short-circuits
                on a surviving dialog
    UI:          Connected throughout

## Scenario G — Dead Dialog Recovery

Proven three times in this run. Two spontaneous cycles (media-less legs terminated
by Asterisk's own `rtp_check_timeout`) and one induced by a bounded media-plane
outage.

    Cycle 1  rx BYE 10:27:19.915 → loss 10:27:23 → 1 admission 10:27:26.083 (201) →
             1 logical INVITE 10:27:26.205 → channel 1786789646.29 bound and bridged
             10:27:29 → Connected
    Cycle 2  rx BYE 10:27:56.310 → loss 10:27:59 → 1 admission 10:27:59.302 (201) →
             1 logical INVITE 10:27:59.426 → channel 1786789679.30 bound and bridged
             10:28:05 → Connected

    PARTICIPANT BEFORE: fae04cfa-cd1f-45f2-b71f-f38a34c45fed
    PARTICIPANT AFTER:  fae04cfa-cd1f-45f2-b71f-f38a34c45fed
    MATCH:              **YES** (both cycles)
    INVITE:             exactly one logical transaction per cycle
    BRIDGE:             bridgechans 0 → 1 on each replacement
    DELETE:             0
    UI:                 Recovering → Connected
    RESULT:             **PASS**

    Cycle 3 (induced media outage, phase 2)
    PARTICIPANT BEFORE: 1abcf643-7f61-456a-bfc4-5c2164548064
    LOSS:               rx BYE 10:41:55.140 (runtime `rtp_check_timeout`), 200 OK sent
    RECOVERABLE:        true, until ≈10:43:59 (loss + 120 s)
    ADMISSION:          1 at 10:42:00.732 (201), same participant
    INVITE:             1 logical at 10:42:00.863 → **488 Media Relay Unavailable**
                        (Kamailio fail-closed while rtpengine was scaled to 0)
    RESULT:             recovery did **not** complete — see Failed Proof Step 1

## Scenario H — Sustained Degradation

Window 10:41:34 → 10:44:07 (media plane scaled to 0 for 78 s, restored ≈10:42:52;
recovery grace expiring ≈10:43:59).

    DURATION:            ~2.5 minutes of adversity
    API REQUESTS:        ~16 client bootstrap polls over the window (the rest of the
                         50 observed starts were the proof's own sampling), i.e. the
                         ladder at its 10 s cap — not the pre-RH-3B 2 s cadence
    RECONNECTS:          0 (transport healthy)
    REGISTERS:           1 logical pair at 10:42:59 (credential renewal)
    CREDENTIAL ISSUES:   0 beyond the scheduled renewal — **no credential storm**
    ADMISSIONS:          **1**
    INVITES:             **1** logical
    PARTICIPANTS:        1 (never duplicated)
    DELETES:             **0**
    FINAL CANONICAL STATE: grace expired ≈10:43:59; the every-minute sweep removed
                         the participation at 10:44:06 with audit
                         `reason: recovery_grace_expired`
    UI:                  converged to **Ready**, banner absent, held stable through
                         10:45:11
    RESULT:              bounded work, no storm of any kind, canonical grace remained
                         the ultimate authority, and the browser converged cleanly —
                         **but** the recovery itself never completed despite the
                         impairment clearing 67 s before the deadline (Failed Proof
                         Step 1)

## Long Outage / Contact Expiry

    EXERCISED:   no
    CONTACT:     not inspected (`kamctl` RPC FIFO is not enabled in the deployed
                 Kamailio image, so contact state could not be read without an
                 improvised path)
    RESTORATION: n/a

Recorded as a residual proof gap. The longest outage in this run was 82 s, below
Kamailio's 120 s `max_expires`.

## REGISTER Auth Retry

    EXERCISED:          **yes, naturally** (Scenario A restoration)
    FIRST REJECTION:    10:30:51.037 CSeq 7 → 401; 10:30:51.041 CSeq 8 → 401
    CREDENTIAL RETRIES: **exactly 1** (POST signaling-credential → 201 at 10:30:51.049)
    SECOND REGISTER:    10:30:51.095 CSeq 2 → 401 → CSeq 3 → **200 OK** 10:30:51.105
    RESULT:             **PASS** — at most one fresh-credential retry, then Registered

Note the contrasting idle case in Failed Proof Step 2, where the same rejection
produced **no** credential retry at all.

## Connected Gating

    SIP ESTABLISHED:            10:27:26.3 / 10:27:59.5 (recovery legs)
    RUNTIME_CHANNEL_ID BEFORE:  NULL at the 10:27:27 and 10:28:01 samples
    UI BEFORE:                  Recovering
    CANONICAL BIND:             10:27:29 and 10:28:05 (bridgechans 0 → 1)
    UI AFTER:                   Connected
    RESULT:                     **PASS** — SIP Established alone never produced
                                Connected; RH-2B gating preserved

## Storm Check

Across the entire proof (≈25 minutes, 8 adversarial conditions):

    admissions:            5 total, each attributable to one intended Join or one
                           recovery — never more than one per event
    replacement INVITEs:   one logical transaction per recovery, never overlapping
    DELETE requests:       **0** for the whole proof
    credential issuance:   only the scheduled ~90 s renewals plus one auth retry
    reconnect corridors:   one at a time, never concurrent
    REGISTER transactions: never overlapping
    API cadence:           ladder-paced (10 s cap under sustained failure), not 2 s
    duplicate participants: none — exactly one admitted participant at any time

No storm pattern of any kind was observed; the stop rule was not triggered.

## Reverb Boundary

Reverb was neither required nor exercised for any conference recovery in this proof.
RT-1 coverage was not expanded.

## Background/Suspension Observation

    EXERCISED: no
    RESULT:    not attempted — the proof harness cannot faithfully represent browser
               background throttling, and the prompt makes this non-blocking.
               Recorded as a residual observation gap.

## Failed Proof Steps

### 1. IMPLEMENTATION (blocking) — a rejected recovery INVITE ends replacement attempts for the remainder of the grace

    SCENARIO:  G/H, cycle 3
    EXPECTED:  after a non-2xx final response to a recovery INVITE, the client
               re-reads bootstrap and, while the server still reports the
               participation recoverable, attempts a further replacement within the
               120 s grace (RH-3A: "INVITE 4xx → WAIT FOR CANONICAL STATE … the
               server decides eligibility"; "INVITE 5xx → RETRY AUTOMATICALLY while
               canonically recoverable")
    ACTUAL:    after `488 Media Relay Unavailable` at 10:42:00.876 the client issued
               **no further INVITE and no further admission** for the remaining 67 s
               of eligibility, even though the media plane was restored at ≈10:42:52
               and bootstrap reported `recoverable: true` continuously until the
               deadline. The participation expired and was swept at 10:44:06.

    API:                 reachable, bootstrap 200 throughout
    TRANSPORT:           connected
    REGISTERER:          Registered
    DIALOG:              none (replacement rejected)
    PARTICIPANT:         1abcf643-7f61-456a-bfc4-5c2164548064
    RUNTIME_CHANNEL_ID:  NULL
    RECOVERABLE:         true until ≈10:43:59, then false
    UI:                  Recovering until the sweep, then Ready
    API REQUEST COUNT:   ladder-paced polls only
    RECONNECT COUNT:     0
    REGISTER COUNT:      1 logical (scheduled renewal)
    ADMISSION COUNT:     1
    INVITE COUNT:        1 logical
    DELETE COUNT:        0

    ROOT CAUSE: `awaitingRecoveryBinding` is set immediately before the replacement
      INVITE (`ReferenceDialerView.vue:389`) and is cleared only on success
      (`:349-350`, `:397-398`), in `stopUnboundRecovery()`, in `cancelRecovery()`, or
      in `beginRecovery()`'s `catch` when no dialog is established. SIP.js resolves
      `invite()` when the request is **sent**, so a later non-2xx final response does
      not throw inside `beginRecovery()` and the `catch` never runs. The flag stays
      latched, so every subsequent entry takes the awaiting-binding branch at
      `:347-359`, whose only outcomes are Connected, `stopUnboundRecovery()`, or
      `scheduleRecovery()` — **it contains no path that issues another INVITE**. The
      corridor therefore degenerates into pure polling until the grace expires.
      (The `updateCallState('failed')` callback for that attempt is separately
      suppressed by the single-flight `recoveryPromise !== null` guard, since the
      488 arrives while the recovery promise is still awaiting its confirmation
      bootstrap.)

    AFFECTED FILE/METHOD:
      apps/web/src/views/ReferenceDialerView.vue
        — `beginRecovery()` awaiting-binding branch `:347-359`
        — `awaitingRecoveryBinding` lifecycle `:389`, `:397-398`, catch `:410-412`

### 2. IMPLEMENTATION (blocking for the idle case) — with no participation, a transport loss is never repaired

    SCENARIO:  C (idle repeat)
    EXPECTED:  the bounded reconnect ladder restores the transport and, once
               connected, registration is re-established (with at most one
               fresh-credential retry), returning the client to REGISTERED
    ACTUAL:    over the 82-second outage **no WebSocket construct occurred at all**;
               a single construct appeared at 10:49:04.715 only after the block was
               lifted, its two REGISTERs (CSeq 5 and 6) were both rejected 401,
               **no fresh-credential request was issued**, and the UI remained
               "SIP registration failed" from 10:47:42 through 10:50:46 (100+ s
               after the transport was available again) with no further attempts.

    API:                 reachable, bootstrap 200 in 21 consecutive samples
    TRANSPORT:           closed 1006 at 10:47:42.024; reopened 10:49:04.720
    REGISTERER:          not Registered
    DIALOG:              none
    PARTICIPANT:         none (participation null)
    UI:                  "SIP registration failed", terminal
    RECONNECT COUNT:     1 visible construct in 82 s
    REGISTER COUNT:      2 transmissions, both 401
    CREDENTIAL ISSUES:   **0**
    ADMISSION/INVITE/DELETE: 0 / 0 / 0

    ROOT CAUSE (not fully isolated): with `participation === null` the view's
      `updateSignalingState('failed')` sets `state = 'failed'` and does **not** call
      `beginRecovery()`, so nothing drives `ensureRegistered()`; the signaling
      client's own `reconnectLoop()` produced no observable connection attempts in
      that window. The absent credential retry is consistent with `authRetryUsed`
      remaining latched from the earlier successful retry at 10:30:51, since it is
      reset only on a successful `RegistererState.Registered`. Both parts are
      plausible from the code but were not isolated to a single line in this proof.

    AFFECTED FILE/METHOD:
      apps/web/src/signaling/referenceDialerSignaling.ts
        — `scheduleReconnect()` / `reconnectLoop()` `:292-330`
        — `authRetryUsed` lifecycle `:266-268`, `:345-360`
      apps/web/src/views/ReferenceDialerView.vue
        — `updateSignalingState()` failure branch (no recovery driver when
          participation is null)

Neither defect was patched.

## Divergences

* **Kamailio restarts destroyed in-dialog routing for pre-existing dialogs.** After
  the 10:30:42 restart, the runtime BYE for the leg established at 10:27:59 never
  reached the browser, so no recovery began and Scenario E could not be exercised in
  that window. Classified **ENVIRONMENT** (a consequence of the proof's own
  transport-loss method, not a product defect), and the affected observations are not
  claimed as client evidence.
* **Induced infrastructure conditions.** Transport loss used
  `kubectl rollout restart deployment/kamailio` (twice); media loss used
  `kubectl rollout restart deployment/rtpengine` and `kubectl scale … --replicas=0/1`
  (78 s outage). No configuration was changed; the media edge was re-verified after
  each (`--interface=browser/<podIP>!127.0.0.1`). Both are normal Kubernetes
  lifecycle actions representing the condition under test.
* **`routeWebSocket` block did not survive a page reload** (the toggle flag is
  re-initialised by the init script), and `page.unroute` does not remove WebSocket
  routes, which cost one aborted Scenario C attempt. Classified **PROOF_HARNESS**;
  the scenario was re-run cleanly in an idle state.
* **Two Join attempts in the run did not establish** (10:46 phase-2 setup) and
  compensated correctly with no admitted participant left behind. Not claimed as
  evidence either way.

## Environment State at Completion

    admitted participants: 0
    runtime channels:      0
    conference bridge:     0 members
    conference:            68c7d252-… open / ready
    runtime node:          d4539d79-… active / ready
    rtpengine:             1/1 ready, media edge intact
    participants removed:  both by the canonical grace sweep
                           (`reason: recovery_grace_expired`), zero DELETEs issued
    browser:               logged out through the normal Log out control

## RH-3D Acceptance Criteria

    [x] natural login used
    [x] WSS transport loss invalidates registration truth
    [x] reconnect is automatic                        (with an active participation)
    [x] reconnect behaviour is bounded / no storm
    [x] REGISTER send is not treated as success
    [x] recovery waits for RegistererState.Registered
    [x] API up / SIP down does not INVITE prematurely
    [x] API down / SIP up does not bypass canonical bootstrap
    [x] existing established dialog survives an unrelated API outage
    [x] offline suppresses recovery work
    [x] online events debounce / coalesce
    [x] connectivity flapping produces no storm
    [x] dead dialog can recover after signaling restoration   (accepted INVITE)
    [x] same participant reused
    [x] exactly one replacement INVITE per recovery
    [x] canonical Connected gating preserved
    [x] sustained degradation remains bounded
    [x] canonical grace remains the ultimate recovery authority
    [x] no credential issuance storm
    [x] no duplicate reconnect / register / admission / INVITE paths
    [x] V0 remains COMPLETE
    [x] RT-1A remains COMPLETE / LIVE PROVEN

    [ ] recovery continues after a rejected replacement INVITE   ← FAILS (defect 1)
    [ ] transport loss is repaired when no participation exists  ← FAILS (defect 2)

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
    RH-3C: IMPLEMENTED / TESTED
    RH-3D: FOUND BLOCKER — two isolated client defects; all other combined
           behaviours live-proven
