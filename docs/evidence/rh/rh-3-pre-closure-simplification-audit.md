# RH-3 — Pre-Closure Simplification / Complexity Audit

## Verdict

    RH_3_PRE_CLOSURE_SIMPLIFICATION_AUDIT_COMPLETED

No conflicting or dead authority was found. The RH-3 implementation is safe to
freeze as it stands. **Outcome A**: five small, mechanical simplifications are
available — all local, all behaviour-preserving — worth one bounded cleanup packet.
The large majority of the apparent complexity maps to genuinely distinct proven
authority and must be kept.

## Repository State

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    phase marker:  UTCP_PHASE=T1
    dirty:         pre-existing working tree plus this document
    commit/push:   none created, not pushed

Static repository evidence only. No application source modified, no live or browser
proof run, no RH-3 correctness contract reopened.

## Current Complexity Summary

    SIGNALING LOC:            417   (apps/web/src/signaling/referenceDialerSignaling.ts)
    VIEW LOC:                 594   (apps/web/src/views/ReferenceDialerView.vue)
    RESILIENCE HELPER LOC:     18   (apps/web/src/views/recoveryResilience.ts)
    MUTABLE SIGNALING STATE:   20 mutable private fields (32 private members total)
    RECOVERY FLAGS:            15 module-level `let` + 6 reactive refs in the view
    PROMISES / SINGLE-FLIGHT:  4 (registrationPromise, reconnectPromise,
                               recoveryPromise, cleanupPromise) + 1 in-flight boolean
                               (renewalInFlight) + 2 external reject handles
                               (pendingRegistrationReject, reconnectTimerReject)
    TIMERS:                    4 (renewalTimer, reconnectTimer, recoveryTimer,
                               connectivityDebounceTimer)
    RETRY COUNTERS:            2 (reconnectRetryIndex, recoveryRetryIndex)
    GENERATION COUNTERS:       2 (inviteAttempt in signaling, conferenceAttempt in view)
    conferenceState WRITES:    20
    OUTER state WRITES:        17
    beginRecovery():           151 lines, 35 conditionals, 24 return points

## Signaling Authority Map

| STATE | OWNER | SOURCE OF TRUTH | VERDICT |
|---|---|---|---|
| `transportConnected` | signaling | SIP.js transport `stateChange` + UA delegate | KEEP — DISTINCT AUTHORITY |
| `registered` | signaling | own credential-trust latch (see note) | **KEEP — DISTINCT AUTHORITY** |
| `registerer.state` | SIP.js | SIP.js Registerer | KEEP |
| `registrationPromise` | signaling | single-flight registration op | KEEP |
| `reconnectPromise` | signaling | single-flight transport op | KEEP |
| `pendingRegistrationReject` | signaling | external cancellation of a pending REGISTER | KEEP |
| `reconnectTimerReject` | signaling | external cancellation of a pending backoff | KEEP |
| `authRetryUsed` | signaling | one-fresh-credential allowance per episode | KEEP |
| `renewalInFlight` | signaling | credential-renewal reentrancy guard | KEEP |
| `renewalTimer` | signaling | credential renewal schedule | KEEP |
| `reconnectRetryIndex` | signaling | WSS reconnect ladder position | KEEP |
| `inviter` / `inviteAttempt` | signaling | current SIP conference session + its generation | KEEP |
| `inviteEstablished` | signaling | "this session reached Established" latch | SIMPLIFY (candidate 5) |
| `localTermination` | signaling | local vs remote termination classification | KEEP — DISTINCT AUTHORITY |
| `shouldRemainRegistered` | signaling | declared intent to stay registered | SIMPLIFY — derivable (candidate 6, **recommended EXCLUDE**) |
| `stopping` | signaling | teardown latch | KEEP |

### The `registered` boolean specifically answers the audit's question: KEEP

It is **not** derivable from `transportConnected + Registerer.state + final-response
settlement`. Two writes set it `false` while SIP.js still believes the Registerer is
`Registered` and the transport is up:

* `scheduleCredentialRenewal()` :224 — credential expiry invalid or already inside
  the 30 s safety window;
* `renewSignalingCredential()` :255 — credential renewal failed (for example the
  issuance API threw before any REGISTER was sent).

In both cases the SIP registration is still nominally alive but the **credential
backing it is no longer trustworthy**. `isRegistered()` :108 must return `false` so
that the next `ensureRegistered()` performs a real re-registration instead of taking
the `!force && isRegistered()` early return at :113, and so that `invite()` :63
refuses to place a call on an untrustworthy registration. Deleting it would
reintroduce exactly the class of "trusted a stale credential" failure that RH-3's
final-response work closed. The `onAccept` write at :397 is also load-bearing: in
the same-state accept case no `stateChange` fires, so nothing else would set it.

## Recovery Authority Map

| FLAG | INDEPENDENT LIFETIME | VERDICT |
|---|---|---|
| `conferenceAttempt` | one browser **orchestration** episode; fences async continuations across awaits | KEEP — DISTINCT AUTHORITY |
| `activeConferenceInviteAttemptId` | the **SIP session** currently owned by the view; fences call-state callbacks | KEEP — DISTINCT AUTHORITY |
| `awaitingRecoveryBindingAttemptId` | the **specific recovery INVITE** whose canonical binding is pending | KEEP — DISTINCT AUTHORITY |
| `awaitingRecoveryBinding` | "a recovery INVITE is outstanding" — **true before the attempt id is known** (:415 vs :419), and consulted at :200 to decide whether to stamp the id from the `inviting` callback | KEEP — DISTINCT AUTHORITY |
| `recoveryParticipantId` | canonical participant expected to be confirmed by binding | KEEP |
| `recoveryRetryIndex` | RH-3F ladder position for the whole unresolved episode | KEEP |
| `recoveryTimer` | next scheduled recovery attempt | KEEP |
| `connectivityDebounceTimer` | RH-3A online-event debounce (1 s), separate concern | KEEP |
| `recoveryRequestController` | abort handle for in-flight recovery HTTP | KEEP |
| `explicitLeaveInFlight` | user intent cutoff; suppresses all auto-recovery | KEEP |
| `attemptKind` | `'new'` (Join) vs `'recovery'` callback semantics | KEEP (one redundant write, candidate 4) |
| `cleanupPromise` | single-flight canonical release | KEEP |
| `recoveryPromise` | single-flight recovery corridor | KEEP |
| `conferenceState` / outer `state` / `conferenceError` | presentation | KEEP (writes consolidated, candidate 2) |
| `destroyed` | unmount latch | KEEP |

The three numeric identities are **not** interchangeable and must not be merged —
this separation is exactly what closed RH-2D, where `conferenceAttempt` was
incorrectly compared against the signaling client's `inviteAttempt`. Static check
confirms the defect is fully gone: all seven `conferenceAttempt` comparisons
(:357, :403, :413, :420, :422, :436, :464) compare only against its own snapshot,
and no site compares it with a SIP attempt id.

## Promise / Single-Flight Map

| OPERATION | CREATED | CLEARED | SUCCESS | FAILURE | CANCEL | CAN REMAIN PENDING | DUPLICATES |
|---|---|---|---|---|---|---|---|
| `registrationPromise` | `ensureRegistered()` :116 | `finally` :120 | final response `onAccept` | `onReject` → `RegistrationRejectedError` | `pendingRegistrationReject` (transport loss / `stop()`) | **No** — settled by the REGISTER's own final response since the fix | none |
| `reconnectPromise` | `scheduleReconnect()` :296 | `.finally()` :298 | transport connected :324 | ladder exhausted / offline :334 | `reconnectTimerReject` | No | none |
| `recoveryPromise` | `beginRecovery()` :353 | `finally` :480 | any terminal branch | `catch` :435 | attempt-generation guards + `AbortController` | No | none |
| `cleanupPromise` | `finalizeConferenceSession()` :259 | `finally` :270 | canonical DELETE ok | catch → attention | none | No | none |
| `renewalInFlight` | :240 | `finally` :258 | — | — | — | No | none |

All four are correctly scoped, each has exactly one creation site and one clearing
site in a `finally`, and none can remain pending. There are **no nested Promise
wrappers and no duplicate completion conditions**. The `Promise.race([waitForState,
finalResponse])` in `registerCurrentRegisterer()` is the one place where two
settlement sources coexist, and both are required — see Protected Complexity.

## Timer / Retry Map

| LADDER / TIMER | OWNER | VALUE | SHARED? |
|---|---|---|---|
| HTTP recovery retry | view `recoveryRetryIndex` | 1/2/3/5/8/10 s ±20 % | shares only the **pure** `recoveryRetryDelay()` |
| WSS reconnect | signaling `reconnectRetryIndex` | 1/2/3/5/8/10 s ±20 % | shares only the **pure** `recoveryRetryDelay()` |
| Credential renewal | signaling `renewalTimer` | expiry − 30 s | own |
| Connectivity debounce | view `connectivityDebounceTimer` | 1 s | own |
| REGISTER / INVITE transactions | SIP.js | Timer F / Timer B | untouched |
| Asterisk RTP timeout | runtime | 30 s | untouched |
| Participation grace | backend | 120 s | untouched |

Correct as designed: a shared **pure** delay/jitter function, two entirely separate
counters and lifecycles. No shared lifecycle authority exists and none should be
introduced. The only defect here is a duplicated **magic cap** (candidate 3).

## State Transition Map

Grouping the 20 `conferenceState` writes and 17 outer-`state` writes by conceptual
transition:

    RECOVERING          state='recovering' + conferenceState='recovering'
                        :210-211, :347-348                                    (2 sites)
    CONNECTED           markConferenceConnected() :187-193                     (1 helper, 5 callers)
    READY               state='registered' + conferenceState='ready'
                        :317-318, :366-367, :392-393, :457-458, :467-468,
                        :512-513, :527-528                                     (7 sites)
    ATTENTION           conferenceState='attention' (+ outer state varies)
                        :254, :263, :266, :447, :452, :530                     (6 sites)
    LEAVING             conferenceState='leaving' :229, :517                   (2 sites)
    JOINING             conferenceState='joining' :496                         (1 site)

`CONNECTED` is already consolidated behind `markConferenceConnected()`. `READY` is
the clear outlier: five of its seven occurrences are byte-identical three-line
blocks. `ATTENTION` is **not** a good consolidation target — its outer-state
component legitimately differs per branch (`failed` for 401 at :446, `registered`
for 403 at :451), so a single helper would either lose that distinction or need a
parameter for every field.

## Historical Legacy Paths

| PATH | FOUND | REACHABLE | ACTION |
|---|---|---|---|
| Fixed 2 000 ms recovery polling | **No** — the only `2_000` is the ladder's second step | n/a | none |
| Old singular `RECOVERY_RETRY_DELAY_MS` constant | **No** | n/a | none |
| Registration success inferred from `register()` resolving | **No** — settlement is via `onAccept`/`onReject` | n/a | none |
| `stateChange`-only REGISTER completion | Partially — `waitForState` retained | Yes, deliberately | **KEEP** (see Protected Complexity) |
| `conferenceAttempt` compared with SIP attempt ids | **No** | n/a | none |
| Idle transport failure treated as permanently terminal | **No** — `handleTransportDisconnect()` :278 always schedules reconnect when the transport was usable | n/a | none |
| `recoveryRetryIndex` reset on `participants/self` success | **No** — the only reset inside the corridor is the episode guard at :344 | n/a | none |
| `awaitingRecoveryBinding` boolean without attempt identity | Both exist and both are load-bearing | Yes | KEEP |
| Stale comments describing superseded behaviour | **None found** in the four primary files | n/a | none |
| Fallback branches retained after canonical replacement | **None found** | n/a | none |

No superseded RH behaviour survives behind a fallback or gate. This is the finding
that makes the phase safe to freeze.

## Duplication Findings

1. **Recovery-binding latch clearing is repeated at 8 sites** — :218-220, :233-235,
   :299-301, :307-308, :374-376, :426-428, :439-441, :524-526. Seven clear all three
   of `awaitingRecoveryBinding`, `awaitingRecoveryBindingAttemptId`,
   `recoveryParticipantId`; **`stopUnboundRecovery()` :307-308 clears only two**,
   omitting `recoveryParticipantId`. Harmless today (the stale value is only read at
   :373 inside the `awaitingRecoveryBinding` branch, which that function has just
   disabled, and :417 rewrites it before it can matter) but it is an unnecessary
   asymmetry in the most defect-prone area of the file.
2. **`state='registered' + conferenceState='ready'` repeated at 7 sites**, five of
   them byte-identical.
3. **Retry-ladder cap `5` duplicated as a magic number** — `ReferenceDialerView.vue:332`
   hardcodes `5` while `referenceDialerSignaling.ts:13` names it
   `RECONNECT_MAX_RETRY_INDEX = 5`. Both are really
   `RECOVERY_RETRY_DELAYS_MS.length - 1`, which the pure helper module already knows
   (`recoveryResilience.ts:7`). A ladder length change today silently desynchronises
   the two corridors' caps.
4. **`markConferenceConnected()` :189 writes `recoveryRetryIndex = 0` directly**
   instead of calling the existing `resetRecoveryRetry()` helper :287.
5. **The 7× repeated guard** `if (destroyed || explicitLeaveInFlight || attemptId !==
   conferenceAttempt) return` — noted for completeness; extracting a predicate saves
   no lines and slightly obscures the fence, so **no action recommended**.

## Dead Code Findings

1. **`referenceDialerApi.isApiRequestTimeout` (`api/platform.ts:1055`) is never
   called.** Repository-wide search finds the `ApiRequestTimeoutError` *class* used
   at :776 (thrown) and in `platform.test.ts` (asserted), but the predicate has no
   caller in any view, store or test. It is an RH-3B leftover from when a timeout was
   expected to need its own classification; the final design correctly lets a timed-out
   recovery request fall through to `scheduleRecovery()` at
   `ReferenceDialerView.vue:478`. DELETE SAFE: yes. TEST PROTECTION: none.
2. **Redundant `attemptKind = 'recovery'` at `ReferenceDialerView.vue:414`** — already
   set at :346 in the same function; no interleaving can change it in between
   (`join()` requires `state === 'registered'` but state is `'recovering'`, and
   `leave()`/`cancelRecovery()` set `explicitLeaveInFlight`, which the :413 guard
   catches). DELETE SAFE: yes.

No other unreachable code was found.

## Test Fidelity Findings

* **Registerer fake — now high fidelity and should be left alone.**
  `referenceDialerSignaling.test.ts:69-83` emits `stateChange` only on an actual
  state change and invokes `onAccept`/`onReject` separately. This is precisely the
  correction that exposed the REGISTER settlement defect, and the fake now encodes
  the SIP.js contract faithfully.
* **Inviter fake is lower fidelity than the Registerer fake.**
  `referenceDialerSignaling.test.ts:103-128`: `invite()` emits `Established` (or
  `Establishing`→`Terminated`) **before it resolves**. Real SIP.js resolves
  `Inviter.invite()` on **send**, with the final response arriving later — this is
  the exact asymmetry that produced RH-3D DEFECT 1. The signaling-module tests
  therefore cannot express "invite() resolved, then a 488 arrived".
  **Mitigation already in place:** the *view* tests, which own the recovery
  corridor, model the late terminal correctly and independently of `invite()` —
  `ReferenceDialerView.test.ts:542` drives a ladder of
  `emitCallState('failed', '488 attempt N', attemptId)`, with further late-488 cases
  at :584 and :621-627. The RH-3E/RH-3F corridor is therefore deterministically
  covered where it matters. Recommended as an **optional test-only** improvement,
  not a source change.
* No duplicate fixtures or obsolete historical cases were found; the fakes are
  defined once and shared.

## Protected Complexity

Must remain — each represents distinct proven authority or lifetime:

1. **Three numeric identities** (`conferenceAttempt`,
   `activeConferenceInviteAttemptId`, `awaitingRecoveryBindingAttemptId`). Merging
   any two reintroduces RH-2D.
2. **`awaitingRecoveryBinding` boolean alongside its attempt id.** The boolean is
   `true` from :415 while the id is still `null` until :419 — that window is real and
   is consumed at :200 to stamp the id from the `inviting` callback.
3. **`waitForState` alongside the final-response promise.** `waitForState` still
   covers the SIP.js `onAccept` paths that call `unregistered()` and return **without**
   invoking the caller's delegate (`registerer.js` missing/mismatched Contact or
   Expires), plus supersede and transport-loss cancellation. Removing it would
   recreate a hang on those paths.
4. **`registered` boolean** — see the Signaling Authority Map note.
5. **`localTermination`** (signaling) is *not* a duplicate of `explicitLeaveInFlight`
   (view): different module, different lifetime, and the view flag also covers
   `cancelRecovery()` and `stopUnboundRecovery()`.
6. **Two separate retry counters** with identical ladder values but independent
   lifecycles.
7. **Canonical binding confirmation gating Connected**, the server-owned 120 s grace,
   and SIP Timer B/F ownership.

## Simplification Candidates

### 1. Extract `clearRecoveryBindingLatch()`

    CURRENT:  the same 2-3 line clearing block at 8 sites; one asymmetric
    PROPOSED: one private helper clearing all three fields; called from all 8 sites
    FILES:    apps/web/src/views/ReferenceDialerView.vue
    BEHAVIOR CHANGE: none on any reachable path (the added
              `recoveryParticipantId = null` in stopUnboundRecovery is unobservable —
              :373 is gated by awaitingRecoveryBinding, which that function clears)
    AUTHORITY CHANGE: none
    RISK:     very low
    TESTS:    existing ReferenceDialerView.test.ts late-488 ladder (:542), stale
              attempt (:627), leave-while-recovering, stop-unbound cases all cover it
    VALUE:    HIGH — removes ~14 lines and an asymmetry in the most defect-prone area

### 2. Extract `setDialerReady()`

    CURRENT:  `state='registered'; conferenceState='ready'` (+ selectedConference=null,
              sometimes conferenceError='') duplicated at 7 sites
    PROPOSED: one helper with an explicit `clearError` argument; callers keep their
              own `selectedConference` handling where it differs
    FILES:    apps/web/src/views/ReferenceDialerView.vue
    BEHAVIOR CHANGE: none
    AUTHORITY CHANGE: none
    RISK:     low — must preserve the two sites that intentionally do NOT clear
              conferenceError
    TESTS:    participation-null, 404, 409-null, leave, stopUnboundRecovery cases
    VALUE:    MEDIUM — ~10 lines and removes a contradictory-state hazard

### 3. Name the ladder cap once

    CURRENT:  magic `5` at ReferenceDialerView.vue:332; `RECONNECT_MAX_RETRY_INDEX = 5`
              at referenceDialerSignaling.ts:13
    PROPOSED: export `RECOVERY_RETRY_MAX_INDEX = RECOVERY_RETRY_DELAYS_MS.length - 1`
              from recoveryResilience.ts and use it in both corridors
    FILES:    recoveryResilience.ts, ReferenceDialerView.vue, referenceDialerSignaling.ts
    BEHAVIOR CHANGE: none (both values are already 5)
    AUTHORITY CHANGE: none — a pure constant, explicitly sanctioned; the two counters
              and lifecycles stay entirely separate
    RISK:     very low
    TESTS:    recoveryResilience.test.ts + both ladder tests
    VALUE:    MEDIUM — removes a silent desynchronisation hazard if the ladder changes

### 4. Delete two provably dead lines

    CURRENT:  `referenceDialerApi.isApiRequestTimeout` (platform.ts:1055, no callers);
              redundant `attemptKind = 'recovery'` (ReferenceDialerView.vue:414)
    PROPOSED: delete both
    FILES:    apps/web/src/api/platform.ts, apps/web/src/views/ReferenceDialerView.vue
    BEHAVIOR CHANGE: none
    AUTHORITY CHANGE: none
    RISK:     none — `ApiRequestTimeoutError` (the class) and its test stay
    TESTS:    existing platform.test.ts timeout test unaffected
    VALUE:    LOW but free

### 5. Clear `inviteEstablished` in the `invite()` catch

    CURRENT:  referenceDialerSignaling.ts:95-100 sets `this.inviter = null` on an
              `invite()` rejection but leaves `inviteEstablished` set. If a rejection
              could ever follow Established, `hasEstablishedConference()` :104 would
              latch `true` permanently via its `|| this.inviteEstablished` disjunct,
              and `beginRecovery()` :339 would short-circuit forever.
    PROPOSED: add `this.inviteEstablished = false` in that catch
    FILES:    apps/web/src/signaling/referenceDialerSignaling.ts
    BEHAVIOR CHANGE: none on any path reachable with SIP.js 0.21.2 (`Inviter.invite()`
              rejects on send failure, before Established); strictly removes a latent
              latch
    AUTHORITY CHANGE: none
    RISK:     very low. **Do not instead delete the `|| this.inviteEstablished`
              disjunct** — the latch is load-bearing for terminal classification at :81-88
    TESTS:    add one signaling test asserting `hasEstablishedConference() === false`
              after a rejected invite
    VALUE:    LOW — one line, closes a latent stale-latch

### 6. `shouldRemainRegistered` — RECOMMENDED EXCLUDE

    CURRENT:  field + 3 writes + 1 read (:327)
    ANALYSIS: at its only read site the enclosing `while (!this.stopping)` guarantees
              `stopping === false`, and every write pairs with a `stopping` write, so
              it is equivalent to `!this.stopping` there.
    DECISION: **exclude from the packet.** It is derivable but the payoff is ~5 lines,
              and it names an intent ("we want to be registered") that is not the same
              concept as "we are not tearing down". This is precisely the
              derivable-but-not-duplicate case the audit standard warns against.

## Candidates Rejected

* **Merge the three attempt identities into one generation** — reintroduces RH-2D.
* **Merge the HTTP recovery and WSS reconnect loops / introduce a `RetryManager`** —
  identical numbers, different lifecycles and owners; no such abstraction exists in
  the repository.
* **Introduce a generic state machine or `ResilienceService`** — the codebase has no
  such framework; this would add a concept, not remove one.
* **Delete the `registered` boolean as derivable** — it is not derivable; see above.
* **Delete `waitForState` now that the final response settles the operation** —
  still required for SIP.js's delegate-less accept paths and for cancellation.
* **Collapse `awaitingRecoveryBinding` into its attempt id** — the null-id window is
  real and load-bearing.
* **Replace SIP Timer F/B with an application timeout; treat SIP Established as
  Connected; drop canonical binding confirmation; replace the server grace with a
  browser timer; move transport recovery into the view** — all rejected outright.
* **Extract the 7× superseded-attempt guard into a predicate** — saves no lines and
  obscures the fence.

## Recommended Cleanup Packet

One bounded Codex packet, **candidates 1-5 only**:

    SCOPE:  apps/web/src/views/ReferenceDialerView.vue
            apps/web/src/signaling/referenceDialerSignaling.ts
            apps/web/src/views/recoveryResilience.ts
            apps/web/src/api/platform.ts
            + their focused tests
    DO:     extract clearRecoveryBindingLatch(); extract setDialerReady();
            export and use RECOVERY_RETRY_MAX_INDEX; delete isApiRequestTimeout and
            the redundant attemptKind write; clear inviteEstablished in the invite()
            catch and add one test for it
    DO NOT: merge attempt identities, merge retry corridors, remove waitForState,
            remove the `registered` boolean, remove shouldRemainRegistered, introduce
            any generic retry/state/error abstraction, or touch any timing constant
    EXPECT: ~30 fewer lines, 8 latch sites → 1, 7 ready-transitions → 1,
            2 magic caps → 1 named constant, 2 dead lines removed
    GATE:   `make web-test`, `make web-lint`, `make web-typecheck` must pass with the
            existing RH test suite unmodified except for the one added invite-catch test

Optional and separable (test-only, no source risk): raise the Inviter fake to the
Registerer fake's fidelity by resolving `invite()` on send and emitting the terminal
response separately.

This packet is genuinely optional. If the preference is to freeze RH-3 untouched,
nothing in this audit argues against that — no finding affects correctness,
authority, or safety.

## Browser Reproof Required After Cleanup

    NO.

Every candidate is a local extraction or a provably dead deletion. None crosses an
authority boundary, changes a timing constant, or alters a settlement path. The
existing focused tests — the late-488 ladder, stale-attempt fencing, leave-while-
recovering, stop-unbound recovery, both renewal-cycle cases and the reconnect ladder
— deterministically cover every touched line. `make web-test` plus lint and
typecheck are sufficient.

## V0 Status

    COMPLETE / UNCHANGED

## RT-1A Status

    COMPLETE / LIVE PROVEN / UNCHANGED

## RH Status

    RH-3:           FUNCTIONALLY COMPLETE / LIVE PROVEN
    SIMPLIFICATION: AUDIT COMPLETED — no blocker; one optional bounded packet
                    (candidates 1-5) identified
