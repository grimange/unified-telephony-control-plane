# RH-3A — Adversarial / Slow-Network Resilience Contract

## Verdict

    RH_3A_ADVERSARIAL_SLOW_NETWORK_RESILIENCE_CONTRACT_COMPLETED

Every value below is derived from committed repository configuration, committed
library defaults, or an already-proven RH contract. Four new client constants are
required; no schema change, no environment gate, no operator knob.

## Method

Repository-backed contract audit. No application source modified, no browser proof
performed, no RH-2 scenario repeated. Values were read from committed
configuration, from the deployed SIP.js library in `apps/web/node_modules/sip.js`
(version `^0.21.2`), and from existing tests.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree (RH-1/RH-2/RH-2B/RH-2C/RH-2D packets)
    commit/push:   none created, not pushed

## Existing Timing Inventory

| CONCERN | CURRENT VALUE | OWNER | SOURCE |
| --- | --- | --- | --- |
| RH recovery rediscovery interval | 2 000 ms, constant | reference dialer view | `apps/web/src/views/ReferenceDialerView.vue:149` `RECOVERY_RETRY_DELAY_MS`, scheduled at `:292`/`:296` |
| RH participation grace | 120 s | TelephonyDomain (server) | `TelephonyDomainService.php:26` `RECOVERABLE_PARTICIPATION_GRACE_SECONDS` |
| Grace expiration sweep | every minute | scheduler | `routes/console.php:954` `telephony-domain:expire-recoverable-participants` |
| SIP credential lifetime | 120 s | signaling domain | `config/telephony_signaling.php:6` `credential_lifetime_seconds` |
| Credential renewal safety margin | 30 000 ms before expiry (→ ~90 s cadence) | signaling client | `referenceDialerSignaling.ts:11` `RENEWAL_SAFETY_WINDOW_MS`, `:184` |
| Kamailio contact max expiry | 120 s (`min` 10, `default` 120) | Kamailio registrar | `kamailio-configmap.yaml:66-68` |
| Kamailio usrloc timer | 20 s | Kamailio | `kamailio-configmap.yaml:64` |
| SIP.js `Registerer` default expires | 600 s, capped to 120 s by the registrar's 200 OK | SIP.js | `sip.js/lib/api/registerer.js:675` |
| SIP.js registration refresh point | 99 % of granted expires (~118.8 s at 120 s) | SIP.js | `registerer.js:676` `defaultRefreshFrequency = 99` |
| SIP.js transport auto-reconnect | **disabled** — `reconnectionAttempts: 0`, `attemptReconnection()` only fires when `> 0` | SIP.js / application | `user-agent.js:200-201`, `:782-784`; the application never calls `attemptReconnection()` |
| SIP.js non-INVITE transaction timeout (Timer F) | 64 × T1 = **32 000 ms** | SIP.js core | `sip.js/lib/core/timers.js:1,14` |
| SIP.js INVITE transaction timeout (Timer B) | 64 × T1 = **32 000 ms** | SIP.js core | `timers.js:1,12` |
| `register()` promise resolution | resolves when the REGISTER is **sent**, not when accepted | SIP.js | `registerer.js:419` `return Promise.resolve(outgoingRegisterRequest)` |
| Browser API request timeout | **none** — no `AbortController`, no `signal`, no retry | web API client | `apps/web/src/api/platform.ts:714-739` `fetchJson` |
| Bootstrap request | `GET /api/v1/reference-dialer/bootstrap`, allow `[200]` | web API client | `platform.ts:1011` |
| Admission request | `POST …/participants/self`, allow `[201]`, `Idempotency-Key` | web API client | `platform.ts:1025-1031` |
| Leave request | `DELETE …/participants/self`, 404 folded to converged | web API client | `platform.ts:1032-1039` |
| Asterisk media-loss policy | `rtp_timeout = 30`, `rtp_timeout_hold = 30` | Asterisk runtime | `infrastructure/docker/asterisk/config/rtp.conf:9-10`, `pjsip.conf:18-19` |
| TelephonySession lifetime | 60 min default, **30 min** in the local overlay | TelephonyDomain | `config/telephony_domain.php:13`; `overlays/local/platform/application-config.properties:30` |
| Session expiry sweep | every minute | scheduler | `routes/console.php:953` |
| Binder retry ladder | `[1, 2, 3, 5, 8, 10, 10, 10, 10, 10, 10]` s, 12 attempts, ~79 s | binder retry job | `AsteriskConferenceParticipantBindingRetryJob.php:17` |
| Binder retry job uniqueness window | 120 s | binder retry job | same file, `uniqueFor()` |
| Participant reconciler waits | `awaiting_inbound_signaling_leg` 30 s; `operation_in_progress` 60 s; converged 30/300 s | reconciler | `ConferenceParticipantReconciler.php:45,55,81,97` |
| Runtime engine poll / lease | poll 3 s (`--once` loops sleep 5 s), lease 60 s | runtime engine | `config/runtime_engine.php:52-53`, `routes/console.php:304…` |
| ARI HTTP timeouts | connect 2 000 ms, request 4 000 ms | ARI client | `config/asterisk_ari.php:12-13` |
| ARI websocket reconnect | 1 000 ms → 30 000 ms, heartbeat 15 000 ms, pong deadline 15 000 ms | ARI listener | `config/asterisk_ari.php:14-19` |
| Kubernetes client timeouts | connect 2 s, request 5 s | runtime fencing | `config/runtime_engine.php:66-67` |
| Stale observation derivation | 300 s | runtime engine | `config/runtime_engine.php:56` |
| Reverb / realtime | reconnect + canonical refetch; **not** required for conference recovery | realtime module | `apps/web/src/realtime/runtimeNodeRealtime.ts`; RT-1A evidence |
| Browser online/offline | `online` → `beginRecovery()`; `offline` → `connected` becomes `recovering` | reference dialer view | `ReferenceDialerView.vue:469-481` |
| Recovery metric events | table exists, hourly pruner | runtime engine | `2026_07_17_140000_create_conference_recovery_metric_events.php`, `routes/console.php:949` |
| Orphan participant channel reclamation | every minute, **removed** participants in **closed** conferences only | TelephonyDomain | `routes/console.php:958`, `TelephonyDomainService::reclaimOrphanParticipantChannels` |

## Current Failure Semantics

    API:            no timeout, no abort, no retry. `fetchJson` awaits the browser's
                    default fetch behaviour indefinitely. Non-allowed statuses raise
                    `ApiRequestError(status, details)`; transport failures surface as a
                    raw fetch `TypeError`. 401 additionally disconnects RuntimeNode
                    realtime. Only `leaveConference` classifies anything (404 →
                    converged).
    WSS:            no automatic reconnection. `reconnectionAttempts` defaults to 0 and
                    the application never calls `attemptReconnection()`. The
                    `onDisconnect` delegate reports `failed` **only when not
                    registered** (`referenceDialerSignaling.ts:129-131`), so a transport
                    drop while registered is silently swallowed and `isRegistered()`
                    keeps returning true.
    REGISTER:       `ensureRegistered()` sets `registered = true` as soon as
                    `register()` resolves — i.e. on send, not on 200 OK. Confirmation
                    arrives asynchronously through the `stateChange` listener. A
                    `RegistererState.Unregistered` transition reports `failed` unless
                    stopping. Timer F bounds an unanswered REGISTER at 32 s.
    INVITE:         bounded only by SIP.js Timer B (32 s). The client emits
                    `inviting` → `connected` / `terminating` / `terminated` / `failed`
                    with the session's own attempt id, which RH-2D made the sole fence.
                    No application-level INVITE timer exists.
    CANONICAL BIND: RH-2B/RH-2D contract — SIP Established is not Connected;
                    `beginRecovery()` re-polls bootstrap through `scheduleRecovery()`
                    until `participation.state === 'active'`. Binder-side retry is
                    independent and bounded (~79 s over 12 attempts).
    OFFLINE:        `handleOffline()` only repaints `connected` → `recovering`; it does
                    not suppress recovery attempts. `handleOnline()` calls
                    `beginRecovery()` with no debounce. `navigator.onLine` is not
                    treated as authoritative anywhere else.
    RECOVERY POLL:  constant 2 s for the whole eligibility window, clamped only by
                    `Math.min(RECOVERY_RETRY_DELAY_MS, remaining)` at
                    `ReferenceDialerView.vue:292`. Worst case ≈ 60 bootstrap requests
                    per broken client per 120 s grace, unjittered, plus one bootstrap
                    per attempt inside `beginRecovery()`.

## Contract Gaps

Only genuine gaps are listed; everything not listed is already contracted.

1. **No API request timeout or abort.** A hung recovery request stalls the
   single-flight `recoveryPromise` indefinitely, silently consuming the grace with
   the UI parked on Recovering.
2. **No API error classification for the recovery path.** Every non-401 failure is
   funnelled into `scheduleRecovery()` identically, so a terminal 403/404/409 is
   retried for the full grace and a retryable 5xx is treated no differently.
3. **Constant 2 s polling for 120 s.** Up to 60 requests per broken client, phase-
   locked across clients, with no reduction while the browser is known offline.
4. **No WSS reconnection.** With `reconnectionAttempts: 0` and no application call
   to `attemptReconnection()`, a dropped transport is never re-established and is
   not even reported while `registered` is true.
5. **Optimistic registration.** `ensureRegistered()` resolves on REGISTER *send*, so
   recovery can proceed to INVITE before registration is confirmed.
6. **No connectivity debounce.** `handleOnline()` calls `beginRecovery()` directly;
   rapid offline/online flapping produces one attempt per transition, coalesced only
   by the `recoveryPromise !== null` guard.
7. **Terminal classification is incomplete.** Only HTTP 401 reaches
   `conferenceState = 'attention'` on the recovery path (`:396-399`); conference
   closed, permission revoked, and session expired all converge silently to Ready.
8. **Multi-tab is undefined.** `createSession` reuses one active telephony session
   per (tenant, user) and `currentParticipation()` returns one participation per
   user, so two open tabs can each run recovery for the same participant. The
   existing orphan sweep does **not** cover this: it only reclaims **removed**
   participants in **closed** conferences with retired bindings.

## Canonical RH-3 State Machine

The existing UI model is preserved exactly — `ViewState` ∈ {loading, connecting,
registered, recovering, failed, error} and `ConferenceState` ∈ {ready, joining,
connected, recovering, leaving, attention}. No new UI state is introduced; the
existing `recovering` presentation absorbs offline and slow-network conditions.

**Registering** (`state = 'connecting'`, "Registering")
* ENTER: client start, or a transport/registration loss that is being re-established.
* AUTOMATIC ACTION: one registration attempt; on transport loss, one reconnection
  ladder (below).
* EXIT: `RegistererState.Registered` → `registered`.
* TERMINAL: authentication rejected and a fresh credential also rejected →
  `failed` + `attention`.

**Ready** (`conferenceState = 'ready'`)
* ENTER: registered with no participation, or participation legitimately ended.
* AUTOMATIC ACTION: none. Join is operator-initiated.
* EXIT: Join, or a bootstrap reporting participation.
* TERMINAL: n/a.

**Joining** (`conferenceState = 'joining'`)
* ENTER: Join clicked; admission requested.
* AUTOMATIC ACTION: one admission, one INVITE; Timer B bounds it at 32 s.
* EXIT: canonical binding confirmed → Connected.
* TERMINAL: establishment fails → existing new-Join compensation (canonical
  removal) → `attention`.

**Connected** (`conferenceState = 'connected'`)
* ENTER: bootstrap confirms the same participation `active` (RH-2B gate).
* AUTOMATIC ACTION: none beyond credential renewal.
* EXIT: unexpected termination, offline, or Leave.
* TERMINAL: n/a.

**Recovering** (`state = 'recovering'`, `conferenceState = 'recovering'`)
* ENTER: unexpected SIP termination, browser offline, transport loss, or bootstrap
  reporting a non-`active` recoverable participation.
* AUTOMATIC ACTION: single-flight canonical rediscovery on the bounded ladder;
  replacement INVITE only when the server says `recoverable` **and** no established
  dialog survives; while offline, no network attempts at all.
* EXIT: bootstrap confirms `active` → Connected; participation absent/ineligible →
  Ready.
* TERMINAL: canonical grace expired, or a terminal classification → Ready or
  `attention` per the matrix.

**Needs attention** (`conferenceState = 'attention'`)
* ENTER: only terminal classifications (below).
* AUTOMATIC ACTION: none — retrying stops.
* EXIT: operator action (Join is enabled in this state, `:103`).
* TERMINAL: this is the terminal state.

## Partial Reachability Matrix

**Case A — API reachable, SIP WSS unreachable.**
Bootstrap may be refreshed and remains authoritative. Registration retries on the
bounded reconnection ladder. UI shows `recovering` (registration lost, participation
preserved). **No replacement INVITE is attempted while the transport is down** —
`invite()` already throws `The SIP client is not registered.` and that must be
classified retryable, not terminal. Stop when the canonical grace expires (server
decides) or the ladder is exhausted → `attention`.

**Case B — API unreachable, SIP dialog still established.**
The call keeps running. This is the required behaviour and the repository already
supports it: nothing in the SIP/media path consults the API, and the runtime channel
lives in Asterisk. **No canonical Leave.** The UI stays Connected; bootstrap refetch
failures are retried on the ladder and must not repaint the conference state. On API
return, one refetch resynchronises. RH-2D confirmed the inverse direction is safe —
canonical participation is only ever changed by the server.

**Case C — API unreachable, SIP dialog gone.**
Recovery eligibility cannot be refreshed. **No new INVITE may be issued.** The
client retries bootstrap on the ladder and holds `recovering`. The browser must not
compute eligibility from a locally cached `recoverable_until`: that value is
advisory for scheduling only. When the API returns, the server decides. If the API
is still unreachable when the locally known deadline passes, the client converges to
Ready without issuing anything — the server's sweep will have removed the
participation.

**Case D — API reachable, REGISTER succeeds, INVITE responses slow.**
One logical INVITE per recovery cycle, bounded by SIP.js Timer B (32 s). No
application timer is added. While the INVITE is outstanding the single-flight
`recoveryPromise` blocks a second attempt. On provisional-only responses the client
waits out Timer B; on Timer B expiry the session reaches Terminated without
Established → `failed` → for a recovery attempt, participation is preserved and the
ladder continues; for a new Join, existing compensation applies.

**Case E — SIP Established, canonical binding confirmation delayed.**
RH-2B/RH-2D rule preserved: **not Connected until canonical confirmation.** The
client polls bootstrap on the ladder, issues **no** further INVITE, and lets the
binder retry ladder (~79 s) work independently. If participation becomes terminal
while the local dialog is established, the existing `stopUnboundRecovery()` tears the
orphan dialog down. If the grace expires with the leg still unbound, the same
teardown runs and the UI converges to Ready.

## API Retry Contract

    TIMEOUT:    10 000 ms per recovery-path request, enforced with an AbortController.
                Derivation: must exceed the slowest committed server-side dependency
                timeout (Kubernetes request 5 s, ARI request 4 s) with margin, and must
                not exceed the recovery ladder cap (10 s) or a hung request would stall
                the ladder. Applies to bootstrap, participants/self and
                signaling-credential on the recovery path only; unrelated admin
                surfaces are out of scope.
    RETRYABLE:  network/transport error (fetch rejection), abort/timeout, HTTP 5xx,
                HTTP 429 if it ever appears. Retried on the ladder; participation
                preserved.
    TERMINAL:   403 (capability revoked) and 404 (participation absent) → Ready with no
                further attempts; 409 → treated per the existing domain semantics
                (idempotent reuse), i.e. re-read bootstrap once and follow the server —
                not a failure. Conference closed / session expired are observed through
                bootstrap participation becoming absent or ineligible, not through a
                status code.
    AUTH:       401 → the existing normal authentication flow (`state = 'failed'`,
                `conferenceState = 'attention'`, realtime disconnected). Never retried
                as a network error.

## Recovery Poll Contract

    INITIAL:  1 000 ms
    BACKOFF:  1 000, 2 000, 3 000, 5 000, 8 000, 10 000 ms
    CAP:      10 000 ms, repeated for the remainder of the eligibility window
    JITTER:   ±20 % applied to each delay
    STOP:     canonical grace expiry (`recoverable_until` reached, already implemented
              at `:259-270`), participation absent or terminal, explicit Leave, or a
              terminal classification. The ladder resets to its first step on every
              successful canonical confirmation and on every new loss event.

Derivation: the ladder is the committed binder ladder
(`AsteriskConferenceParticipantBindingRetryJob::RETRY_DELAYS_SECONDS = [1,2,3,5,8,10,…]`)
reused verbatim, so client and server escalate on the same shape. Against the 120 s
grace this yields ≈15 canonical requests instead of ≈60 — a 75 % reduction — while
still detecting an `rtp_timeout = 30 s` loss within ≤10 s of it being stamped, and
recovering a short outage within ~1 s. The cap equals the recovery request timeout so
a hung request cannot overlap the next attempt. Jitter exists solely to prevent
phase-locking across many clients recovering from one shared outage; it is a fixed
ratio, not configuration.

While `navigator.onLine === false` the ladder is **suspended** rather than run: no
bootstrap, no admission, no INVITE. The `online` event resumes it immediately at step
one. `navigator.onLine` is authoritative only for suppressing attempts — never for
concluding that SIP or the server is healthy.

## REGISTER Contract

    TIMEOUT:            32 000 ms per attempt — SIP.js Timer F. No new constant.
    RETRY:              registration is retried only through the transport reconnection
                        ladder below; no second registration state machine is added.
    TRANSPORT:          the application must enable reconnection explicitly, because
                        `reconnectionAttempts` defaults to 0. Use SIP.js's own
                        `attemptReconnection()` on the same bounded ladder as the
                        recovery poll (1/2/3/5/8/10 s, cap 10 s), stopping when the
                        canonical grace expires or the participation ends. The
                        `onDisconnect` delegate must report loss **regardless of**
                        `registered`, and must clear `registered`, so the UI enters
                        `recovering` instead of silently believing it is registered.
    AUTH FAILURE:       `RegistererState.Unregistered` after a rejected challenge →
                        request one fresh credential and retry once; a second rejection
                        is terminal → `failed` + `attention`.
    CREDENTIAL RENEWAL: unchanged — renewal at expiry − 30 s (~90 s cadence) against a
                        120 s lifetime, already proven storm-free. If the renewal API is
                        slow or unavailable, the existing registration remains valid
                        until its own expiry; renewal failures are retried on the
                        recovery ladder and must not tear down a working call. If the
                        credential expires during connectivity loss, registration is
                        re-established only after a fresh credential is obtained on
                        reconnect. **Do not extend the credential TTL to mask outages.**
    CONFIRMATION:       `ensureRegistered()` must resolve on `RegistererState.Registered`
                        rather than on `register()` resolving, so recovery never INVITEs
                        against an unconfirmed registration.

Note: SIP.js refreshes registration at 99 % of the granted 120 s (~118.8 s) while the
credential renewal re-REGISTERs at ~90 s. The 90 s path pre-empts the 119 s path;
this overlap is benign and was observed live (REGISTERs 90 s apart), but the fix must
not add a third refresh timer.

## INVITE Contract

    TIMEOUT:                32 000 ms — SIP.js Timer B. No application timer.
    PROVISIONAL RESPONSE:   100/180 do not extend the contract; the attempt is still
                            bounded by Timer B.
    LATE FINAL RESPONSE:    fenced by RH-2D's `activeConferenceInviteAttemptId`. A late
                            2xx for a superseded attempt is discarded by the view; the
                            signaling client's own `if (this.inviter !== inviter) return`
                            guard discards late state changes for a replaced session.
    NEW JOIN FAILURE:       unchanged — existing compensation removes the admitted
                            participant and shows `attention`.
    RECOVERY FAILURE:       participation is preserved while canonically recoverable;
                            the ladder continues; no duplicate participant is created
                            because `participants/self` reuses the same participant.
    ONE LOGICAL ATTEMPT:    guaranteed by the existing single-flight `recoveryPromise`
                            plus the attempt fence; the ladder must never start a second
                            INVITE while one is outstanding.

## Canonical Binding Contract

    POLL:               bootstrap on the recovery ladder (1/2/3/5/8/10 s, cap 10 s).
    UI:                 `recovering` throughout; never Connected before confirmation.
    NO SECOND INVITE:   slowness of binding is never a reason to re-INVITE.
    TIMEOUT/ATTENTION:  none of its own — the canonical grace is the only deadline.
                        Binder retry (~79 s) is comfortably inside the 120 s grace, so
                        a binding that never completes converges through grace expiry,
                        not through a client timer.
    TERMINAL CLEANUP:   if participation becomes absent or ineligible while a local
                        dialog is established, `stopUnboundRecovery()` terminates it so
                        no orphan channel is left parked in Stasis (already proven live
                        in the RH-2 reproof).

## Connectivity Flapping Contract

    COALESCE:         all recovery entry points (`terminated` callback, `online`,
                      scheduled timer, mount) funnel into the one `beginRecovery()`
                      coordinator.
    SINGLE-FLIGHT:    existing `recoveryPromise !== null` guard, retained.
    DEBOUNCE:         1 000 ms on connectivity transitions — an `online` event within
                      1 000 ms of the previous one replaces the pending trigger instead
                      of adding one. Derivation: matches the smallest committed retry
                      step in the repository (binder first retry 1 s; RH-2B listener
                      dispatch delay `->delay(now()->addSecond())`).
    STORM PREVENTION: ladder + single-flight + debounce + offline suspension together
                      bound a flapping client to at most one canonical request per
                      1 000 ms transient and one per ladder step otherwise. No
                      duplicate participants (server-side reuse), no duplicate INVITEs
                      (single-flight + fence), no duplicate REGISTERs beyond SIP.js's
                      own semantics.

## Repeated Runtime Loss Contract

Recovery restarts normally on every loss while canonical participation remains
eligible. **Canonical eligibility is the guard — not a client retry counter.** Each
loss stamps a fresh `runtime_channel_lost_at` and therefore a fresh 120 s window;
the client resets its ladder to step one on each new loss event. RH-2D proved the
attempt authority handles a second termination correctly. No arbitrary
"max recoveries per session" cutoff is introduced: the repository shows no abuse
risk, because each cycle costs exactly one admission (idempotent, same participant)
and one INVITE, and a runtime that keeps killing legs will exhaust the grace on its
own.

## Sustained Degradation Contract

When connectivity repeatedly permits starting recovery but never completing it:

* the ladder escalates to its 10 s cap and stays there — bounded work;
* every attempt is single-flight, so partial attempts cannot overlap;
* the admission is idempotent and reuses the same participant, so no participant or
  admission storm is possible;
* the INVITE is fenced, so no duplicate leg survives;
* the canonical 120 s grace expires and the every-minute sweep removes the
  abandoned participation with `reason: recovery_grace_expired`;
* the client observes participation absent on its next successful bootstrap and
  converges to **Ready** (not `attention` — nothing needs operator action). If the
  failure was classified terminal (auth), it converges to `attention` instead.

## API Restart Contract

A Laravel API restart must not affect an established call. Confirmed by repository
structure: the SIP/media path (browser → Kamailio → rtpengine → Asterisk) never
consults the API, and the runtime channel and bridge live in Asterisk. Contract:
the call remains active, canonical refetch failures are retried on the ladder, the
UI stays Connected, **no canonical Leave is issued**, and on API return one refetch
resynchronises. PBX call lifetime is never tied to the web API process lifetime.

## Worker/Scheduler Restart Contract

    binder retry:                    queued job on the Redis default queue; survives a
                                     worker restart and runs when the worker returns
                                     (`AsteriskConferenceParticipantBindingRetryJob`,
                                     `uniqueFor()` 120 s bounds duplication).
    grace expiration:                `Schedule::command(...)->everyMinute()
                                     ->withoutOverlapping()` — a missed minute simply
                                     runs late; the predicate is timestamp-based, so
                                     convergence is correct, only later.
    canonical binding confirmation:  server-side state is read by the browser through
                                     bootstrap; a worker restart delays binding but
                                     does not invalidate it. The reconciler re-evaluates
                                     on its own cadence.

Eventual automatic recovery once workers return is the contract. No manual replay or
repair command becomes part of normal operation.

## Reverb Boundary

**Reverb failure must not prevent SIP or canonical API recovery.** Conference
recovery depends only on the canonical API and the SIP path; RT-1A already proved
reconnect-plus-refetch for RuntimeNode, and RH-0 established that Reverb is not
required for recovery correctness. RH-3 must not expand RT-1 realtime coverage to
conferences or participants to satisfy any requirement in this contract.

## Browser Suspension Contract

Background tabs throttle `setTimeout` (typically to ≥1 min), so the 2 s — and the
proposed 10 s — ladder cannot be relied on while hidden. The correctness principle:

* the server's 120 s grace remains the only authority; missed client timers cannot
  grant extra recovery authority;
* on resume (`visibilitychange` to visible, or the `online` event), the client
  performs **one** canonical refetch and lets the server decide;
* a suspended tab that wakes after the grace has expired sees participation absent
  and converges to Ready.

No background-tab machinery is built. If a resumed tab must also re-establish the
transport, that is covered by the same reconnection ladder.

## Retryability Matrix

| CONDITION | CLASSIFICATION | NOTE |
| --- | --- | --- |
| API timeout / abort (10 s) | RETRY AUTOMATICALLY | ladder; participation preserved |
| API network error (fetch rejection) | RETRY AUTOMATICALLY | ladder |
| API 401 | NORMAL AUTH FLOW | existing: `failed` + `attention`, realtime disconnected |
| API 403 | TERMINAL / NEEDS ATTENTION | capability revoked; stop retrying |
| API 404 (participation absent) | TERMINAL / READY | intent legitimately gone |
| API 409 | WAIT FOR CANONICAL STATE | domain conflict; re-read bootstrap once, follow the server |
| API 5xx | RETRY AUTOMATICALLY | ladder |
| WSS transport unavailable | RETRY AUTOMATICALLY | reconnection ladder; UI `recovering`; no INVITE |
| REGISTER auth failure | RETRY AUTOMATICALLY once with a fresh credential, then TERMINAL / NEEDS ATTENTION | second rejection is terminal |
| REGISTER transport failure | RETRY AUTOMATICALLY | reconnection ladder, bounded by Timer F per attempt |
| INVITE timeout (Timer B, 32 s) | RETRY AUTOMATICALLY while canonically recoverable | recovery preserves the participant; new Join compensates |
| INVITE 4xx (e.g. 403/404/486) | WAIT FOR CANONICAL STATE | re-read bootstrap; the server decides eligibility |
| INVITE 5xx/6xx | RETRY AUTOMATICALLY while canonically recoverable | same ladder |
| SIP Established, binding delayed | WAIT FOR CANONICAL STATE | stay `recovering`; no second INVITE |
| Runtime BYE | RETRY AUTOMATICALLY | RH-2D corridor; 0 DELETE; same participant |
| Browser offline | WAIT FOR CANONICAL STATE | suspend the ladder; preserve participation; no Leave |
| Canonical grace expired | TERMINAL / READY | server already removed or will remove the participation |
| Conference closed | TERMINAL / READY | observed as participation absent/ineligible via bootstrap |
| Session expired | TERMINAL / READY | server removes participants with `reason: session_expired` |

## Deterministic Constants

Only four additions are required. All are code/domain constants in the web client —
none is environment-configurable, and none duplicates an existing library or server
timeout.

    NAME/CONCEPT: RECOVERY_RETRY_DELAYS_MS
    VALUE:        [1000, 2000, 3000, 5000, 8000, 10000], last value repeated
    DERIVATION:   verbatim reuse of the committed binder ladder
                  (AsteriskConferenceParticipantBindingRetryJob::RETRY_DELAYS_SECONDS);
                  detects an rtp_timeout=30 s loss within ≤10 s and cuts canonical
                  requests over the 120 s grace from ≈60 to ≈15
    OWNER:        reference dialer recovery orchestration
                  (replaces RECOVERY_RETRY_DELAY_MS at ReferenceDialerView.vue:149)

    NAME/CONCEPT: RECOVERY_RETRY_JITTER_RATIO
    VALUE:        0.2 (±20 %)
    DERIVATION:   prevents phase-locked retries across many clients recovering from one
                  shared outage; fixed ratio, not configuration
    OWNER:        reference dialer recovery orchestration

    NAME/CONCEPT: RECOVERY_REQUEST_TIMEOUT_MS
    VALUE:        10 000
    DERIVATION:   > slowest committed server-side dependency timeout (Kubernetes 5 s,
                  ARI 4 s) with margin, and ≤ the ladder cap so a hung request cannot
                  overlap the next attempt
    OWNER:        web API client, applied to recovery-path requests via AbortController

    NAME/CONCEPT: CONNECTIVITY_DEBOUNCE_MS
    VALUE:        1 000
    DERIVATION:   matches the smallest committed retry step in the repository (binder
                  first retry 1 s; RH-2B listener dispatch `->delay(now()->addSecond())`)
    OWNER:        reference dialer connectivity handling

No constant is required for INVITE (SIP.js Timer B, 32 s), REGISTER (Timer F, 32 s),
credential renewal (existing 30 s safety window), the grace (server-side 120 s), or
binder retry (existing ladder).

## Data Model Decision

    NO SCHEMA CHANGE.

Browser resilience remains transient orchestration around canonical server authority.
No network-attempt table, failure-history table, or recovery token is required.
`conference_recovery_metric_events` already exists with an hourly pruner and is
sufficient for any RH-3 observability need.

## Authority Preservation

RH-0 through RH-2 are unchanged by this contract. Specifically preserved:
`desired_state` semantics; `runtime_channel_lost_at` semantics; the 120-second grace
as the sole recovery authority; explicit Leave as the only browser-driven
participation cutoff; conference placement; RuntimeNode readiness authority;
TelephonySession ownership; Kamailio routing; binder retry authority; and the RH-2B
rule that SIP Established alone is never Connected. No feature flag, environment
gate, per-tenant switch, manual retry command, or operator timeout knob is
introduced.

## Implementation Slices

    NAME:         RH-3B — recovery cadence, request timeout, offline suspension
    TARGET:       replace constant 2 s polling with the bounded jittered ladder; add
                  AbortController-based timeouts to recovery-path API calls; classify
                  API errors per the matrix; suspend the ladder while offline; debounce
                  connectivity transitions; reset the ladder on each new loss
    FILES/SEAMS:  apps/web/src/views/ReferenceDialerView.vue
                    (:149 constant, :255-296 scheduleRecovery, :299-395 beginRecovery,
                     :469-481 handleOnline/handleOffline)
                  apps/web/src/api/platform.ts (:714-739 fetchJson — recovery-path
                     timeout/abort only, no broad redesign)
    TESTS:        ladder progression and reset; jitter bounded; no request while
                  offline; one coalesced attempt across rapid flapping; timeout →
                  retryable; 403/404 terminal; 5xx retryable; 401 auth flow; grace
                  expiry stop preserved
    LIVE PROOF:   not required for this slice
    DEPENDENCIES: none

    NAME:         RH-3C — transport reconnection and registration confirmation
    TARGET:       enable and drive SIP.js reconnection on the same ladder; report
                  transport loss regardless of `registered` and clear the flag;
                  resolve `ensureRegistered()` on RegistererState.Registered; one
                  credential-refresh retry on auth rejection then terminal; extend
                  terminal → `attention` classification (conference closed, permission
                  revoked, session expired, auth unrecoverable)
    FILES/SEAMS:  apps/web/src/signaling/referenceDialerSignaling.ts
                    (:89-96 ensureRegistered, :113-155 start/delegate/onDisconnect,
                     :135-143 registerer stateChange)
                  apps/web/src/views/ReferenceDialerView.vue (:156-169
                     updateSignalingState, terminal classification)
    TESTS:        transport drop while registered surfaces loss and enters recovering;
                  reconnection ladder bounded and stops at grace expiry; no INVITE while
                  unregistered; registration confirmed before recovery INVITE; auth
                  rejection retried once with a fresh credential then terminal;
                  credential renewal failure does not tear down a live call
    LIVE PROOF:   not required for this slice
    DEPENDENCIES: RH-3B (shares the ladder)

    NAME:         RH-3D — adversarial natural-browser/network live proof
    TARGET:       prove the contract live: WSS-only outage (Case A), API-only outage
                  with a live call (Case B), API outage with a dead dialog (Case C),
                  slow INVITE (Case D), delayed binding (Case E), connectivity flapping,
                  repeated runtime loss, and sustained degradation converging with no
                  storm
    FILES/SEAMS:  evidence only
    TESTS:        n/a
    LIVE PROOF:   required — natural Playwright login, canonical deployment; note the
                  RH-2D tradecraft that the MCP browser cannot be forced to stop sending
                  RTP, so a runtime BYE is induced with one canonical rtpengine pod
                  restart
    DEPENDENCIES: RH-3B and RH-3C

    DEFERRED (separate future contract): multi-tab call ownership. Two tabs share one
    telephony session and one participation and can each drive recovery for the same
    participant; the existing orphan-channel sweep does not cover a second live channel
    on an admitted participant. This is explicitly **not** solved inside RH-3.

## Tests / Evidence Reviewed

`apps/web/src/views/ReferenceDialerView.test.ts` (18 cases — RH-2 corridor, attempt
fencing, drift acceptance, coalesced terminal callbacks, Leave cutoff; **no** timeout,
backoff, offline, or degradation coverage), `apps/web/src/signaling/referenceDialerSignaling.test.ts`
(registration lifecycle, one attempt identity per INVITE, remote termination),
`docs/evidence/rh/rh-0-browser-telephony-recovery-contract.md`,
`rh-1-canonical-recoverable-participation.md`, `rh-2b`, `rh-2c`,
`rh-2d-runtime-bye-client-lifecycle-diagnosis.md`,
`rh-2d-runtime-bye-natural-live-reproof.md`, and the committed configuration listed
in the timing inventory.

## Unresolved Proof Gaps

1. Browser background-throttling behaviour under the new ladder is asserted from the
   correctness principle (server grace authoritative, refetch on resume) rather than
   measured; RH-3D should observe one suspended-tab resume.
2. The reconnection ladder's interaction with Kamailio's 120 s contact expiry during
   an outage longer than the contact lifetime is contracted (fresh credential on
   reconnect) but not live-observed; RH-3D Case A should cover it.

## V0 Status

    COMPLETE / UNCHANGED

## RT-1A Status

    COMPLETE / LIVE PROVEN / UNCHANGED

## RH Status

    RH-0: COMPLETE
    RH-1: IMPLEMENTED / TESTED
    RH-2: COMPLETE / LIVE PROVEN
    RH-3: CONTRACT COMPLETE — RH-3B/RH-3C/RH-3D defined, none implemented
