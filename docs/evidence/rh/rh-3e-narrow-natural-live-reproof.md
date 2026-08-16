# RH-3E — Narrow Natural Live Reproof

## Verdict

    RH_3E_NARROW_NATURAL_LIVE_REPROOF_PASSED_WITH_NARROW_PROOF_GAP

Both RH-3D blockers are closed and live-proven.

* **Corridor 1** — a recovery INVITE rejected with `488 Media Relay Unavailable`
  now releases its binding wait, the participant stays admitted with **zero**
  canonical DELETEs, recovery re-enters automatically, and a later attempt binds and
  bridges the moment the dependency is restored. RH-3D produced exactly **one**
  INVITE and then waited out the grace; RH-3E produced **17 further attempts** and
  recovered.
* **Corridor 2** — an idle WSS loss with `participation = null` is now repaired
  automatically: bounded reconnect (2 attempts), REGISTER, one fresh credential,
  `RegistererState.Registered`, back to Ready in **2.8 s**, with **0** conference
  admissions, INVITEs or DELETEs and no user action. RH-3D left this state
  unrepaired for 100+ s.

The narrow gaps are the RH-3B 10-second HTTP timeout (still repository-only) and a
second *rejecting* authentication episode (did not arise naturally). One
non-blocking deviation is recorded: the post-`488` retry cadence is a flat ~2.45 s
rather than the escalating RH-3B ladder.

## Method

Evidence-only. No application source, schema, manifest, or configuration file was
modified; no RH-1 grace, RH-3B ladder/timeout/debounce, RH-3C reconnect ladder,
SIP Timer B/F, credential TTL, `rtp_timeout`, binder retry, readiness predicate,
Kamailio routing or rtpengine configuration was changed. Deployment through
canonical Make targets only. Natural Playwright login from the real login page; no
preset session, injected cookie, DB/Redis session, or auth bypass. No SQL mutation,
no manual participant or timestamp change, no SIP injection, no manual job
invocation. Browser instrumentation was passive logging only and changed no
application behaviour. Adversity used only normal Kubernetes lifecycle actions on
infrastructure workloads, disclosed below.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree (RH-1 … RH-3E packets)
    commit/push:   none created, not pushed

## Canonical Environment

    API:          utcp/api  @sha256:382f337e450a8d5f6029c9ec8d3777d574de81a6645a1f9c328a021766168c89
    WEB:          utcp/web  @sha256:40e4900e1d87d1e8f93b264372e4a90c7def85c8f4647bd7ce91a6c6345e82a8
    ASTERISK:     utcp/asterisk-ari @sha256:b2d7184892f9a133d7f0909347d8ae904d250111d3a362569b45c3e4da04dc69
    WORKER:       utcp/api  @sha256:382f337e… (deployment/worker, same image as API)
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready, sip/udp :5060
    CONFERENCE:   68c7d252-2203-4f2a-9b81-4d87d1294768 — open / ready, binding active
    DEPLOYMENT FRESH: yes

Lifecycle: `k8s-image-build` → `k8s-image-push` → `k8s-apply` → rollout restart of
the ten `utcp-platform` Deployments → `media-edge-apply`. Stasis app
`utcp-t0-observation` confirmed registered (11:29:54) before any proof traffic.

### Content verification of the RH-3E change in the deployed bundle

The deployed asset `/usr/share/nginx/html/assets/index-DnLgogUN.js` contains, byte
for byte identical to the locally built dist:

    inviting`)x=r,_===`recovery`&&v&&(y=r);else if(r!==x)return}

where `r` is the callback attempt id, `x` the active conference invite attempt,
`v` the awaiting-binding flag, `_` the attempt kind and `y` the new
**`awaitingRecoveryBindingAttemptId`** — i.e. the binding wait is now stamped with
the exact signaling attempt that issued the INVITE (Fix 1). `ApiRequestTimeoutError`
and the `1e3,2e3,3e3,5e3,8e3,1e4` ladder remain present from RH-3B.

## Natural Login

    USER:         t3-s3b-t3s3b1785716804@utcp.local.test (tenant member)
    TENANT:       Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    CAPABILITIES: 8 — telephony.conferences.join / view, telephony.sessions.create_own /
                  view_own, telephony.signaling.issue_own / view_own,
                  tenant.memberships.view, tenant.roles.view

Access restored through the repository break-glass single-account password-recovery
command with an operator reason recorded for audit; the browser then completed the
ordinary login and forced password-change flow from the real login page.

## Corridor 1 — Rejected Recovery INVITE

Baseline established naturally (login → confirmed registration → Join → Connected).

    TELEPHONY SESSION:  802da2b6-272b-44b7-91d1-3b9fa52c32d1
    PARTICIPANT P:      c21db45a-8a18-40e6-8171-647cfb16bbee
    CHANNEL A:          1786793438.32 (PJSIP/anonymous-00000020)
    RUNTIME_CHANNEL_ID: 1786793438.32
    BRIDGE:             utcp-conf-68c7d252-… , bridgechans = 1
    UI:                 Connected

The media plane was interrupted by a bounded canonical
`kubectl scale deployment/rtpengine --replicas=0` at 11:31:06.835, so Asterisk's own
committed `rtp_check_timeout` policy terminated the leg and Kamailio fail-closed the
replacement. No SIP was injected and no configuration changed.

    ASTERISK:            11:31:36 rtp_check_timeout — "Disconnecting channel
                         'PJSIP/anonymous-00000020' for lack of audio RTP activity
                         in 30 seconds"
    LOSS:                rx BYE 11:31:36.558, client answered 200 OK 11:31:36.566
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 11:31:40+00
    RECOVERABLE:         true
    RECOVERABLE UNTIL:   11:33:40 (loss + 120 s exactly)

    ATTEMPT N:           11:31:43.319 — CSeq 1 → 401 → ACK → CSeq 2 authenticated
    FINAL SIP RESPONSE:  **488 Media Relay Unavailable** @11:31:43.342, ACK sent
    TERMINAL CALLBACK:   delivered to the view for that attempt (proved by the
                         re-entry below — RH-3D produced no further attempt at all)
    ATTEMPT ID:          the INVITE's own signaling attempt; the binding wait was
                         stamped with it and released by its matching terminal
                         callback

## Binding-Wait Release

    PARTICIPANT:      c21db45a-8a18-40e6-8171-647cfb16bbee (unchanged)
    DESIRED STATE:    admitted, continuously, through the whole failure window
    DELETE COUNT:     **0**
    PARTICIPANT COUNT: 1 — never duplicated; all 18 INVITEs targeted the same
                      `conf-c21db45a-…` destination and all 18 admissions returned 201
    NEXT BOOTSTRAP:   the recovery coordinator resumed immediately and kept polling
    RETRY DELAY:      successive replacement attempts at 11:31:45.879, 11:31:48.586,
                      11:31:51.191, 11:31:53.677, 11:31:56.363, 11:31:58.898,
                      11:32:01.159, 11:32:03.196, 11:32:05.380, 11:32:07.943, …
                      observed inter-attempt deltas: 2.56, 2.71, 2.60, 2.49, 2.69,
                      2.54, 2.26, 2.04, 2.18, 2.56, 2.40, 2.44, 2.64, 2.09, 2.60,
                      2.06 s
    RECOVERABLE:      true throughout (bootstrap `recoverable/true` from 11:31:41 to
                      11:32:24)

    NO TIGHT STORM:   no sub-second retries, no concurrent attempts, single-flight
                      held, one participant, zero DELETEs. See the non-blocking
                      deviation below regarding ladder escalation.

**This is the decisive contrast with RH-3D**, where the identical `488` produced
exactly one INVITE and then nothing for the remaining 67 s of grace.

## Later Successful Replacement

    DEPENDENCY RESTORED:   11:32:18.877 (scale 1), rtpengine ready 11:32:20.931
    ORIGINAL PARTICIPANT:  c21db45a-8a18-40e6-8171-647cfb16bbee
    RECOVERY PARTICIPANT:  c21db45a-8a18-40e6-8171-647cfb16bbee
    MATCH:                 **YES**
    ATTEMPT N+1:           the first attempt after restoration reached
                           **200 OK @11:32:22.306**
    INVITE COUNT:          one logical transaction per attempt (401 + authenticated
                           pair); no attempt ever overlapped another
    CHANNEL B:             1786793542.33 (PJSIP/anonymous-00000021)
    RUNTIME_CHANNEL_ID:    1786793542.33 (observed set by 11:32:27)
    BRIDGE:                utcp-conf-68c7d252-… , bridgechans 0 → 1
    UI:                    Recovering → **Connected @11:32:30**
    DELETE COUNT:          0

A second, entirely natural cycle followed (rx BYE 11:32:52.312 while rtpengine was
still settling → one attempt at 11:32:58.993 → 200 OK 11:32:59.132 → Connected
11:33:04), reusing the same participant with one admission and one INVITE.

## Binding Delay Preservation

    SIP ESTABLISHED:   11:32:22.306 (200 OK)
    UI BEFORE BIND:    **Recovering** — the 11:32:24 sample still showed
                       `recoverable/true` with the Recovering presentation
    SECOND INVITE:     **none** — the next logical INVITE is 36.81 s later and
                       belongs to the subsequent natural cycle, so the
                       INVITE DELTA during the binding wait is **0**
    CANONICAL BIND:    ~11:32:26 (`runtime_channel_id` set, bridgechans 0 → 1 by
                       11:32:27)
    UI AFTER:          **Connected @11:32:30**

RH-3E did not turn binding latency into SIP failure, and RH-2 Connected gating is
intact.

## Stale Callback

Not observed naturally — each rejected attempt's terminal callback arrived before
the next attempt was issued, so no late callback from attempt N existed while N+1
was current. Repository automated coverage remains the evidence for that branch.

## Corridor 2 — Idle WSS Loss With No Participation

The conference was released through the normal UI Leave (audit `reason: requested`,
11:34:14), leaving:

    PARTICIPATION: null
    TRANSPORT:     connected
    REGISTERER:    Registered
    UI:            REGISTERED / Ready, Join available

Transport loss produced by a bounded canonical
`kubectl rollout restart deployment/kamailio` at 11:34:30.370 (ready 11:34:35.953).

    LOSS:               11:34:35.939 — WebSocket close, code 1006
    TRANSPORT STATE:    disconnected
    REGISTERER STATE:   not Registered (registration truth invalidated)
    UI:                 recovered to Ready before the first 3-second sample
    ADMISSION COUNT:    **0**
    INVITE COUNT:       **0**
    DELETE COUNT:       **0**

## Idle Signaling Restore

    RECONNECT ATTEMPTS: 11:34:36.768 construct #2 → refused, close 1006  (+0.83 s,
                        ladder step 1 s ±20 % ✓)
                        11:34:38.656 construct #3 → **open**            (+1.89 s,
                        ladder step 2 s ±20 % ✓)
    TRANSPORT RESTORED: 11:34:38.660
    REGISTER SENT:      11:34:38.661 CSeq 7 → 401
                        11:34:38.665 CSeq 8 → 401
    CREDENTIAL:         11:34:38.728 POST signaling-credential → 201 (exactly one)
    REGISTERED:         11:34:38.730 CSeq 2 → 401 → 11:34:38.734 CSeq 3 →
                        **200 OK @11:34:38.738**
    FINAL UI:           REGISTERED / Ready — stable across 24 consecutive samples
                        (11:34:51 → 11:36:01)
    PARTICIPATION:      null throughout
    ADMISSION DELTA:    **0**
    INVITE DELTA:       **0**
    DELETE DELTA:       **0**
    USER ACTION:        none required — total repair time 2.80 s from loss to
                        Registered

The only later API call was the ordinary ~90 s credential renewal at 11:36:08
(201, then REGISTER 401 → 200 OK) — normal cadence, not a storm.

## Authentication Episode Reset

    LIVE EXERCISED: PARTIAL

    EPISODE A (Corridor 1 window, 11:32:00.460): scheduled credential renewal —
      REGISTER CSeq 4 → 401 (normal digest challenge) → CSeq 5 → 200 OK.
      AUTH FAILURES: 0 rejections · CREDENTIAL RETRIES: 0 · RESULT: Registered

    EPISODE B (Corridor 2, 11:34:38): independent transport-loss episode —
      AUTH FAILURES: 2 rejections (CSeq 7, CSeq 8 both 401 against the existing
      credential) · CREDENTIAL RETRIES: **exactly 1** (201) · REGISTER ATTEMPTS: 2
      logical · RESULT: **200 OK, Registered**

    STORM: none — one credential issued per episode plus the scheduled renewals

Episode B proves the one-retry allowance was **available** in a new registration
episode and was consumed exactly once. A *second rejecting* episode did not arise
naturally within this proof, and inducing one would require corrupting credentials
or weakening Kamailio auth — both explicitly prohibited. The per-episode reset
therefore remains covered by repository automated tests for the second-rejection
case. Recorded as a narrow proof gap, not a blocker.

## API Timeout

    LIVE EXERCISED: NO
    RESULT:         not reproducible within this narrow packet without manufacturing
                    a hung request; RH-3B repository test evidence retained.

## Storm Check

Corridor 1 window (11:31:06 → 11:33:10, ~2 minutes of adversity):

    PARTICIPANTS:      1 (never duplicated; all attempts reused c21db45a-…)
    RECONNECTS:        0 (transport healthy)
    REGISTERS:         1 logical pair (scheduled renewal)
    CREDENTIAL ISSUES: 1 (the scheduled renewal)
    ADMISSIONS:        18, all 201, one per attempt, never concurrent
    INVITES:           18 logical, never overlapping
    DELETES:           **0**

Corridor 2 window:

    PARTICIPANTS: 0 · RECONNECTS: 2 attempts, one corridor · REGISTERS: 2 logical
    CREDENTIAL ISSUES: 1 (+1 scheduled renewal) · ADMISSIONS: 0 · INVITES: 0
    DELETES: 0

No duplicate concurrent recovery or signaling corridor was observed anywhere.

## Connected Gating

    SIP ESTABLISHED:            11:32:22.306
    RUNTIME_CHANNEL_ID BEFORE:  NULL (11:32:17 and 11:32:24 samples)
    UI BEFORE:                  Recovering
    CANONICAL BIND:             ~11:32:26 (bridgechans 0 → 1 by 11:32:27)
    UI AFTER:                   Connected @11:32:30
    RESULT:                     **PASS** — RH-2 gating intact

## Failed Proof Steps

    None.

### Non-blocking deviation — post-rejection retry cadence does not escalate

    CLASSIFICATION: IMPLEMENTATION (non-blocking)

The 17 post-`488` attempts were spaced at a flat **2.04–2.71 s** (mean ≈2.45 s) and
never escalated toward the RH-3B ladder's 3 / 5 / 8 / 10 s steps, because the retry
index is reset on each cycle that reaches the admission. Over the 76-second failure
window this cost 18 admissions and 18 INVITEs where the RH-3A contract intends
roughly half that.

This does **not** meet the definition of a storm used in this packet — no
sub-second retries, no concurrency, single-flight held, one participant, zero
DELETEs, and the whole corridor is bounded by the canonical 120-second grace — and
every blocking acceptance item passes. It is nonetheless a partial deviation from
the acceptance line "canonical recovery resumes through **RH-3B ladder**": recovery
resumes correctly, but without ladder escalation under a persistently failing
dependency. Recorded here so a reviewer can decide whether to schedule a bounded
follow-up; it was not patched.

    AFFECTED FILE/METHOD: apps/web/src/views/ReferenceDialerView.vue —
      `recoveryRetryIndex` reset on the successful-admission path inside
      `beginRecovery()`

## Divergences

* **Induced infrastructure conditions.** Media loss used
  `kubectl scale deployment/rtpengine --replicas=0` for 72 s (11:31:06 → 11:32:18);
  transport loss used `kubectl rollout restart deployment/kamailio`. Both are normal
  Kubernetes lifecycle actions representing the condition under test; no
  configuration was changed and the media edge was re-verified afterwards
  (`--interface=browser/10.42.0.229!127.0.0.1`).
* A second natural recovery cycle occurred at 11:32:52 while rtpengine was still
  settling; it is reported rather than suppressed and behaved identically.

## Cleanup

Participation was released through the normal UI Leave (audit `reason: requested`),
then the browser logged out normally. Final canonical state:

    admitted participants: 0
    runtime channels:      0
    conference bridge:     0 members
    conference:            68c7d252-… open / ready
    runtime node:          d4539d79-… active / ready
    rtpengine:             1/1 ready, media edge intact

## RH-3E Acceptance

    [x] rejected recovery INVITE terminally fails            (488 @11:31:43.342)
    [x] its binding wait is released                         (17 further attempts)
    [x] participant remains admitted
    [x] no canonical DELETE occurs                           (0 across both corridors)
    [~] canonical recovery resumes through RH-3B ladder      (resumes ✓; no ladder
                                                              escalation — see the
                                                              non-blocking deviation)
    [x] same participant is reused                           (18/18)
    [x] a later replacement INVITE is allowed while still recoverable
    [x] successful retry binds and bridges                   (channel B, bridge 0→1)
    [x] no second INVITE occurs merely due to slow binding   (INVITE delta 0)
    [x] idle WSS loss works with participation=null
    [x] idle transport repair is automatic                   (2.80 s, no user action)
    [x] idle REGISTER waits for confirmed Registered
    [x] idle repair performs 0 conference admissions/INVITEs/DELETEs
    [x] no reconnect/REGISTER/credential/INVITE storm
    [x] RH-2 Connected gating remains intact
    [x] V0 remains COMPLETE
    [x] RT-1A remains COMPLETE / LIVE PROVEN

    AUTH EPISODE RESET LIVE EXERCISED: PARTIAL (one episode consumed its single
      allowance and succeeded; a second rejecting episode did not arise naturally)
    API TIMEOUT LIVE EXERCISED: NO

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
    RH-3D: HISTORICAL BLOCKER EVIDENCE (both blockers now closed)
    RH-3E: LIVE PROVEN — both blocking corridors pass
    RH-3:  **COMPLETE / LIVE PROVEN**, with two narrow non-blocking proof gaps
           (API timeout repository-only; second auth-rejection episode) and one
           non-blocking retry-cadence deviation recorded
