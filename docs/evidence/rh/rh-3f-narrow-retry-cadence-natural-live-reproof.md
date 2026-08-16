# RH-3F — Narrow Retry-Cadence Natural Live Reproof

## Verdict

    RH_3F_NARROW_RETRY_CADENCE_NATURAL_LIVE_REPROOF_PASSED

Every RH-3F acceptance item is live-proven. The RH-3E non-blocking deviation is
closed: under one unresolved recovery episode nine consecutively rejected
replacement INVITEs escalated through the complete RH-3B ladder
(**2 → 3 → 5 → 8 → 10 s, cap held for four further attempts**) instead of the flat
~2.45 s RH-3E recorded, restoring the dependency produced a successful
same-participant replacement, canonical bind reset the episode, and a later
independent episode restarted at the **1-second** ladder step.

One material live finding outside the RH-3F changed surface is recorded below and
is **not** part of this verdict: in the second episode the signaling/registration
corridor stalled for **136.75 s** after the retry-reset evidence was captured, and
the recoverable participation expired. It is reported in full under *Live finding*.

## Method

Evidence-only. No application source, schema, manifest, or configuration file was
modified. No retry constant, RH-1 grace, Asterisk `rtp_timeout`, SIP/WSS
registration behaviour, or Kamailio/rtpengine configuration was changed. Deployment
used canonical Make targets only. Natural Playwright login from the real login page
— no preset session, injected cookie, DB/Redis session, or auth bypass. No SQL
mutation, no manual participant or timestamp change, no SIP injection, no manual
recovery invocation. Browser instrumentation was a passive `PerformanceObserver`
plus DOM sampling; it changed no application behaviour. Adversity used only the
normal Kubernetes lifecycle action disclosed below.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree (RNP … RH-3F packets), unchanged by this proof
    commit/push:   none created, not pushed

## Canonical Environment

    API:          utcp/api  @sha256:95d1558bf1b74ca179f5266c24b950cd5aab45156b52973394349c595984ab28
    WEB:          utcp/web  @sha256:53a4cb066d87c1855670ac2f3cb69e4ec082811952ac0da4f28ae5dda25b3ecc
    ASTERISK:     utcp/asterisk-ari @sha256:af09b8b5802a6ed74d055e1e81c91cc327661f288208f94629ab1d3fbd7fe0d3
    WORKER:       utcp/api  @sha256:95d1558bf1b74ca179f5266c24b950cd5aab45156b52973394349c595984ab28
    GATEWAY:      utcp/gateway @sha256:24ec57e397ca24de2dcbdb2eaf73b102ab54ebd3f005abd569e3917cdeceb18a
    RTPENGINE:    utcp/rtpengine @sha256:03b38da7a09f74d00943327b541da4c5de5a0a11a2414b73d8b8c956c8753fdb
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready
    CONFERENCE:   68c7d252-2203-4f2a-9b81-4d87d1294768 — open / ready, binding 1d9da35b active
    DEPLOYMENT FRESH: yes

Lifecycle: `security-apply` → `k8s-image-build` → `k8s-image-push` → `k8s-apply` →
`kubectl rollout restart deployment` (all sixteen `utcp-platform` Deployments) →
`media-edge-apply`. Stasis app `utcp-t0-observation` re-registered 21:14:15 before
any proof traffic. Media edge verified live as
`--interface=browser/<podIP>!127.0.0.1`.

### Content verification of RH-3F in the deployed bundle

The served asset `/assets/index-rvkKg6aX.js` contains the RH-3B ladder unchanged
and the RH-3F retry-index semantics:

    By=[1e3,2e3,3e3,5e3,8e3,1e4], Vy=.2, Hy=1e4, Uy=1e3
    function Wy(e){return By[Math.min(Math.max(e,0),By.length-1)]}
    function Gy(e,t=Math.random){let n=Wy(e),r=(t()*2-1)*Vy;return Math.round(n*(1+r))}

    function ie(){m=0}                                     // resetRecoveryRetry
    function ce(e=!0){ … let t=Gy(m); e&&(m=Math.min(m+1,5)); d=setTimeout(()=>void T(),t)}
    async function T(e){ … t.value!==`recovering`&&ie(); …  // reset ONLY on a new episode

`m` is the retry index. In the deployed recovery corridor `ie()` appears exactly
once and is guarded by `t.value!==\`recovering\``, so an in-flight episode never
resets. The canonical admission (`Ed.joinConference`) does **not** reset it — this
is precisely the RH-3E defect that RH-3F removed. Binding-only polls call `ce(!1)`,
which schedules a recheck without consuming a ladder step; terminal INVITE failure
calls `ce()`, which advances it.

## Natural Login

    USER:         t3-s3b-t3s3b1785716804@utcp.local.test (tenant member)
    TENANT:       Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    CAPABILITIES: 8 — telephony.conferences.join / view, telephony.sessions.create_own /
                  view_own, telephony.signaling.issue_own / view_own,
                  tenant.memberships.view, tenant.roles.view

Access restored through the repository break-glass single-account
password-recovery command with an operator reason recorded for audit; the browser
then completed the ordinary login and forced password-change flow from the real
login page, selected the tenant through the normal control, and opened `/dialer`.

    login.succeeded          21:18:00
    tenant_context.selected  21:18:09
    websocket_accepted       21:18:15.914
    registration_challenge   21:18:15.916
    registration_accepted    21:18:15.930

## Baseline

    TELEPHONY SESSION:  832324b8-d3c1-4329-93d4-72e46dcfd979
    PARTICIPANT P:      e467283a-4ecc-4d9f-a8bc-9a7586255518
    JOIN INVITE:        21:18:31.324 (call_id 9dsqec0t939p13qh4sh7) → media_offer →
                        media_answer 21:18:31.479
    CHANNEL A:          1786828711.0 (PJSIP/anonymous-00000000)
    RUNTIME_CHANNEL_ID: 1786828711.0
    BRIDGE:             utcp-conf-68c7d252-… , bridgechans = 1
    UI:                 REGISTERED / Connected
    MEDIA:              1894 RTP packets received at loss−0:38

    channel A == runtime_channel_id == bridge member  ✓

## Recovery Episode 1

    LOSS METHOD:             bounded canonical `kubectl scale deployment/rtpengine
                             --replicas=0` at 21:19:53.298 (no configuration changed,
                             no SIP injected)
    ASTERISK:                21:20:23.473 rtp_check_timeout — "Disconnecting channel
                             'PJSIP/anonymous-00000000' for lack of audio RTP activity
                             in 30 seconds"
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 21:20:24+00
    RECOVERABLE UNTIL:       21:22:24 (loss + 120 s exactly)
    PARTICIPANT:             e467283a-… unchanged, desired_state `admitted` throughout
    UI:                      Recovering from 21:20:23.545

Kamailio fail-closed every replacement while rtpengine was absent
(`rtpp_function_call(): no available proxies` →
`kamailio_application_dialog_media result=media_offer_failed` →
`sl_send_reply("488","Media Relay Unavailable")`).

## Rejected INVITE Cadence

`INVITE TIME` is the Kamailio `dialog_challenge method=INVITE` for that attempt;
`TERMINAL` is its `media_offer_failed` / 488. `DELTA` is INVITE-to-INVITE.
`SCHEDULED` isolates the scheduler: it is measured from the end of the attempt's
canonical confirmation bootstrap (the request that arms the next timer) to the
start of the next attempt's first bootstrap, taken from the browser's passive
`PerformanceObserver` record.

| ATTEMPT | INVITE TIME | FINAL RESPONSE | TERMINAL | NEXT INVITE | DELTA | SCHEDULED | LADDER STEP | ±20 % WINDOW | RESULT |
|--------:|-------------|----------------|----------|-------------|------:|----------:|------------:|--------------|--------|
| 1 | 21:20:24.915 | 488 Media Relay Unavailable | 21:20:24.920 | 21:20:27.433 | 2.518 | 2.243 | 2 s | 1.6–2.4 | PASS |
| 2 | 21:20:27.433 | 488 Media Relay Unavailable | 21:20:27.437 | 21:20:30.215 | 2.782 | 2.492 | 3 s | 2.4–3.6 | PASS |
| 3 | 21:20:30.215 | 488 Media Relay Unavailable | 21:20:30.221 | 21:20:35.592 | 5.376 | 5.103 | 5 s | 4.0–6.0 | PASS |
| 4 | 21:20:35.592 | 488 Media Relay Unavailable | 21:20:35.603 | 21:20:44.058 | 8.467 | 8.175 | 8 s | 6.4–9.6 | PASS |
| 5 | 21:20:44.058 | 488 Media Relay Unavailable | 21:20:44.062 | 21:20:53.340 | 9.281 | 8.996 | 10 s cap | 8.0–12.0 | PASS |
| 6 | 21:20:53.340 | 488 Media Relay Unavailable | 21:20:53.349 | 21:21:01.762 | 8.422 | 8.132 | 10 s cap | 8.0–12.0 | PASS |
| 7 | 21:21:01.762 | 488 Media Relay Unavailable | 21:21:01.766 | 21:21:11.592 | 9.830 | 9.543 | 10 s cap | 8.0–12.0 | PASS |
| 8 | 21:21:11.592 | 488 Media Relay Unavailable | 21:21:11.604 | 21:21:23.684 | 12.092 | 11.792 | 10 s cap | 8.0–12.0 | PASS |
| 9 | 21:21:23.684 | 488 Media Relay Unavailable | 21:21:23.688 | 21:21:33.918 | 10.234 | 9.922 | 10 s cap | 8.0–12.0 | PASS |
| 10 | 21:21:33.918 | **200 OK** (media_offer → media_answer 21:21:33.923) | — | — | — | — | — | — | PASS |

Every scheduled interval falls inside its own ±20 % window. The progression is
strictly the committed ladder; nothing is flat near ~2 s after the first two steps.

### Retry Escalation

    1S:       consumed by the immediate first attempt (21:20:24.915), which the
              terminal callback issues directly rather than through a timer
    2S:       2.243 s  ✓
    3S:       2.492 s  ✓
    5S:       5.103 s  ✓
    8S:       8.175 s  ✓
    10S CAP:  8.996 / 8.132 / 9.543 / 11.792 / 9.922 s — reached and held for five
              intervals, never exceeded the 12.0 s jitter ceiling  ✓

### Scheduler phase note (within contract, not a defect)

The index advances exactly once per terminal failure — 0→1→2→3→4→5, then capped —
so the ladder is walked once and only once per rejected INVITE, and binding-only
polls consume no step. The armed timer, however, uses the value *after* that
advance, because the 488 terminal callback lands while the attempt's confirmation
bootstrap is still in flight and that bootstrap then re-arms via `ce(!1)` at the
new index. The observed sequence is therefore the ladder offset by one position
(2,3,5,8,10,…) rather than (1,2,3,5,8,10,…). Every RH-3B clause still holds: one
step per terminal failure, no step consumed by binding latency, cap preserved,
jitter preserved. Recorded for completeness; **no correction is proposed and none
is required.**

## Storm Check

Measured over the whole 69-second failure window 21:20:24 → 21:21:33.

    PARTICIPANT COUNT:   1 — e467283a-… never duplicated; all 10 admissions returned
                         the same participant id
    ADMISSIONS:          10 POST `participants/self`, exactly one per scheduled
                         recovery attempt, never concurrent
    INVITES:             10 logical (401 challenge + authenticated pair each)
    MAX CONCURRENT:      1 — every attempt's terminal response preceded the next
                         attempt's first request
    DELETES:             **0**
    CREDENTIAL ISSUES:   **0** during the window (audit shows only 21:18:15 initial
                         and 21:19:45 scheduled renewal before it)
    SUB-SECOND RETRIES:  none
    CONCURRENT RECOVERY: none — single-flight `recoveryPromise` held throughout

Audit across the entire episode contains exactly one
`conference_participant.admitted` (21:18:31, the original Join). The nine recovery
admissions were deterministic idempotent reuse and wrote no additional record.

## Binding-Wait Preservation

Each of the nine terminal 488s released its own matching binding wait — proven by
the fact that a further replacement INVITE followed every one of them (RH-3D
produced exactly one INVITE and then nothing). No INVITE was issued while binding
was merely pending: after the successful 200 OK at 21:21:33.923 the client issued
**no** further INVITE, only the binding poll at 21:21:45.099.

## Dependency Restoration

    RESTORE START:        21:21:22.643 (`kubectl scale deployment/rtpengine --replicas=1`)
    RESTORE READY:        21:21:24.986
    ORIGINAL PARTICIPANT: e467283a-4ecc-4d9f-a8bc-9a7586255518
    RECOVERY PARTICIPANT: e467283a-4ecc-4d9f-a8bc-9a7586255518
    MATCH:                **YES**
    SUCCESSFUL INVITE:    attempt 10 @ 21:21:33.918 → media_answer 21:21:33.923
    CHANNEL B:            1786828894.1 (PJSIP/anonymous-00000001)
    RUNTIME_CHANNEL_ID:   1786828894.1
    BRIDGE:               utcp-conf-68c7d252-… , bridgechans 0 → 1
    LOST_AT:              cleared
    DELETE COUNT:         0 before final cleanup
    MEDIA AFTER RECOVERY: 7037 RTP packets received at +2:21

Attempt 9 was rejected at 21:21:23.688, 1.30 s before rtpengine became ready, and
the very next scheduled attempt succeeded. Recovery completed with 47 s of grace
remaining.

## Connected Gating

    SIP ESTABLISHED:  21:21:33.923 (200 OK / media_answer)
    CANONICAL BIND:   21:21:34 (`runtime_channel_id` = 1786828894.1, bridgechans 0 → 1)
    UI BEFORE BIND:   **Recovering** — continuously from 21:20:23.545
    UI AFTER BIND:    **Connected** @ 21:21:45.345, on the next binding poll
    RESULT:           PASS — RH-2 Connected gating intact; the UI never showed
                      Connected on SIP establishment alone

## Retry Reset After Connected

After Connected the recovery episode ended cleanly. The only request following the
canonical bind was the single binding poll at 21:21:45.099 that observed
`active`; the browser then issued **zero** canonical requests for the next
2 min 23 s. No old recovery timer continued.

## Next Independent Recovery Episode

A second, entirely independent loss was induced with the same bounded canonical
method.

    SECOND EPISODE LOSS:     `scale rtpengine --replicas=0` 21:24:08.764;
                             Asterisk rtp_check_timeout 21:24:38.045 disconnected
                             'PJSIP/anonymous-00000001'
    RUNTIME_CHANNEL_LOST_AT: 2026-08-15 21:24:41+00
    SECOND EPISODE RECOVERABLE: yes (grace to 21:26:41)
    UI:                      Recovering from 21:24:38.145

First scheduled intervals of the new episode, from the passive browser record:

| CYCLE | REQUEST START | PREVIOUS END | DELTA | LADDER STEP | ±20 % WINDOW | RESULT |
|------:|---------------|--------------|------:|------------:|--------------|--------|
| 1 | 21:24:38.055 | — (immediate re-entry on `terminated`) | — | — | — | — |
| 2 | 21:24:39.222 | 21:24:38.104 | **1.118** | 1 s | 0.8–1.2 | PASS |
| 3 | 21:24:40.104 | 21:24:39.281 | **0.882** | 1 s | 0.8–1.2 | PASS |
| 4 | 21:24:41.128 | 21:24:40.162 | **0.966** | 1 s | 0.8–1.2 | PASS |

    STARTED AT INITIAL LADDER: **YES**

The previous episode ended saturated at the 10-second cap with intervals of
9.5–11.8 s. The new episode's first intervals are 0.88–1.12 s — the initial
1-second step within jitter, three times consecutively (these are binding-only
polls, which correctly do not consume a step). The retry episode is unambiguously
reset by canonical Connected.

## Live finding — registration corridor stall in episode 2

    CLASSIFICATION: IMPLEMENTATION (outside the RH-3F changed surface)
    ROOT CAUSE:     NOT fully isolated

After the retry-reset evidence above was captured, the second episode did not
converge. Timeline:

    21:24:41.128→.175  bootstrap #4 (participation now recoverable)
    21:24:41.177       REGISTER → 401 challenge
    21:24:41.180       REGISTER → 401 challenge   (no `registration_accepted`)
    21:24:41 … 21:26:57  **136.75 s with zero canonical requests and zero SIP**
    21:26:41           RH-1 grace expires
    21:26:57.934       POST signaling-credential → 201 (fresh credential)
    21:26:59.020       **kamailio_websocket_accepted** — a NEW WSS handshake
    21:26:59.021/.034  registration_challenge → **registration_accepted**
    21:26:58.791       bootstrap → participation null → UI returns to Ready
    21:27:02           canonical sweep: `conference_participant.removed`,
                       reason `conference participation recovery grace expired`

    EXPECTED: the recovery episode continues issuing ladder-paced replacement
              attempts for the remainder of the 120 s grace.
    ACTUAL:   the corridor produced no attempt at all for 136.75 s and the
              recoverable participation expired.

    PARTICIPANT:        e467283a-… (unchanged, admitted until the sweep)
    RECOVERABLE:        true from 21:24:41 to 21:26:41
    ATTEMPT:            none issued during the stall
    FINAL SIP RESPONSE: 401 (twice), never accepted
    RETRY INDEX:        not observable
    SCHEDULED DELAY:    n/a — the single-flight `recoveryPromise` never settled
    ACTUAL NEXT INVITE: none
    DELETE COUNT:       0 (the removal at 21:27:02 is the canonical server-side
                        grace sweep, not a browser DELETE)
    CREDENTIAL ISSUES:  1, at the end of the stall
    UI:                 Recovering for the whole stall, then correctly Ready

What is established: the stall sits between `beginRecovery()`'s
`await signalingClient.ensureRegistered()` and its settlement; the credential in
use had expired at ~21:21:45 (issued 21:19:45, 120 s lifetime) because no scheduled
renewal request was made between 21:19:45 and 21:26:57; and recovery resumed only
after a **new WSS handshake**, which implies the transport had also dropped and was
being re-established by the RH-3C reconnect path.

What is **not** established: whether the 136.75 s is the SIP.js registration wait
in `registerCurrentRegisterer()` failing to settle on a repeated 401 until a
transport event released it, or the RH-3C reconnect ladder itself taking that long,
and why the ~90 s credential renewal timer produced no request. Distinguishing
these requires client-side instrumentation that this evidence-only task does not
authorize.

This finding does not touch `recoveryRetryIndex` or any RH-3F behaviour, and it
does not affect any episode-1 measurement or the episode-2 reset measurement, all
of which completed before it. It is **not** a basis for an RH-3G implementation
wave; the appropriate next step is a targeted blocker diagnosis.

## Failed Proof Steps

    None. Every RH-3F acceptance item was exercised and passed.

## Cleanup

Participation had already been released by the canonical grace sweep, so no UI
Leave was applicable; the browser then logged out normally through the UI.

    admitted participants: 0
    runtime channels:      0
    conference bridge:     0 members (utcp-conf-68c7d252-…, 2 calls processed)
    conference:            68c7d252-… open / ready
    runtime node:          d4539d79-… active / ready
    rtpengine:             1/1 ready, media edge `--interface=browser/10.42.0.250!127.0.0.1`
    utcp-platform:         17/17 workloads ready

## Divergences

* **Induced infrastructure condition.** Media loss used
  `kubectl scale deployment/rtpengine --replicas=0` twice (89 s at 21:19:53 →
  21:21:22, and 49 s at 21:24:08 → 21:24:57). Both are normal Kubernetes lifecycle
  actions representing the condition under test; no configuration was changed and
  the media edge was re-verified after each restoration. Does not invalidate the
  principal claim.
* **Stale apiserver endpoint pin repaired before the proof.** A host restart had
  shuffled the k3d node IPs, leaving `allow-traefik-kubernetes-api` and
  `allow-runtime-fencer-kubernetes-api` pinned to 172.21.0.3 while the apiserver had
  moved to 172.21.0.2. Repaired canonically with `make security-apply`. Environmental
  issue, pre-existing, unrelated to the principal claim.
* **`make security-apply` exited non-zero** at its trailing K2 regression step with
  `missing required K2 tool: helm`; `make doctor` confirms helm is absent from the
  host. All NetworkPolicies had already applied and were verified live. Environmental
  tooling gap; Traefik was already installed and serving. Does not invalidate the
  principal claim.
* **First login attempt used the wrong origin.** `https://utcp.local.test` is not an
  allowed WSS origin — Kamailio logged `kamailio_websocket_rejected result=origin`
  and the client reported close 1006. The proof was restarted on the canonical
  application origin `https://app.utcp.local.test`. Proof-harness correction, no
  application behaviour involved.
* **`telephony-reconciler` restarts on `runtime reconciliation fencing token was
  superseded`** (2 restarts during the session, 8 already present before it), each
  time converging and returning ready. Unrelated pre-existing condition.
* **Observability apiserver pins remain stale** at 172.21.0.3
  (`allow-observability-kubernetes-api-egress`,
  `allow-prometheus-operator-apiserver-egress`). Owned by `observability-apply`,
  outside this task's scope; recorded, not repaired.

## Environment and Topology Changes

    None.

No cluster, registry, host port, load balancer, Kubernetes context, node topology,
persistent volume, deployment mechanism, or parallel runtime was created, changed,
or removed. `utcp-local` remained the only UTCP cluster and `apntalk-local` remained
stopped and untouched. Replica-count scaling of the existing `rtpengine` Deployment
and rollout restarts of existing Deployments are ordinary reversible workload
mutations, both fully restored.

## Improvised or Non-Canonical Actions

    None.

All deployment used repository Make targets. `kubectl scale`, `kubectl rollout
restart`, `kubectl logs`, `kubectl exec` (read-only Asterisk CLI and read-only
`psql`) were used for adversity, same-tag rollout, and diagnostics only; none
substituted for a canonical lifecycle path and none bypassed repository validation.

## RH-3F Acceptance

    [x] repeated replacement INVITEs fail terminally under one recovery episode  (9 × 488)
    [x] each terminal failure releases its matching binding wait                 (9 further attempts)
    [x] same participant remains admitted                                        (10/10)
    [x] no canonical DELETE occurs                                               (0)
    [x] retry cadence escalates through the RH-3B ladder                         (2→3→5→8→10)
    [x] cadence does not remain flat near ~2 seconds                             (2.24 → 11.79 s)
    [x] ±20 % jitter remains within contract                                     (9/9 in window)
    [x] 10-second cap is preserved                                               (5 intervals, ≤12.0 s)
    [x] no overlapping INVITE/recovery storm                                     (max concurrent 1)
    [x] participant reuse remains unchanged                                      (1 participant)
    [x] restoring dependency permits successful replacement                      (attempt 10, 200 OK)
    [x] successful channel becomes runtime_channel_id                            (1786828894.1)
    [x] bridge membership restored                                               (0 → 1)
    [x] Connected still waits for canonical binding                              (bind 21:21:34 < Connected 21:21:45)
    [x] successful Connected state ends/resets the recovery episode              (0 requests for 2m23s)
    [x] next independent recovery episode starts from initial ladder             (1.118 / 0.882 / 0.966 s)
    [x] V0 remains COMPLETE
    [x] RT-1A remains COMPLETE / LIVE PROVEN

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
    RH-3D: HISTORICAL BLOCKER EVIDENCE
    RH-3E: LIVE PROVEN
    RH-3F: **LIVE PROVEN** — retry escalation, cap, reset and same-participant
           replacement all natural-live-proven; the RH-3E cadence deviation is closed
    RH-3:  COMPLETE / LIVE PROVEN for its stated contract, with the live finding above
           outstanding as a separate targeted diagnosis

Accepted narrow gaps carried forward unchanged: the RH-3B 10-second HTTP timeout
remains repository-test-only, a second independently rejecting authentication
episode has not arisen naturally, and browser background throttling has not been
exercised.
