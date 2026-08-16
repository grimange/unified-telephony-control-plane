# RH-3 pre-freeze simplification cleanup

Date: 2026-08-16  
Verdict: `RH_3_PRE_FREEZE_SIMPLIFICATION_IMPLEMENTED_AND_TESTED`

## Scope

This bounded cleanup implements the five candidates accepted by the RH-3
pre-closure simplification audit. It does not reopen RH-3 correctness or alter
registration, reconnect, recovery, credential, SIP timer, or participation
authority.

## Changes

1. `ReferenceDialerView.vue` now uses `clearRecoveryBindingWait()` for the
   three binding-wait transient fields. The boolean, participant id, and
   attempt id remain distinct; stale-attempt fencing and binding entry/release
   conditions are unchanged.
2. The identical registered/ready transition is expressed by
   `transitionConferenceReady()`. Attention, connected, leaving, and recovery
   transitions remain branch-specific.
3. `RECOVERY_RETRY_MAX_INDEX` is derived from
   `RECOVERY_RETRY_DELAYS_MS.length - 1` and is reused by both callers. The
   ladder remains `1, 2, 3, 5, 8, 10, 10...`, while HTTP and WSS retry indexes,
   timers, and episode lifecycles remain separate.
4. The unused `referenceDialerApi.isApiRequestTimeout` predicate and the
   redundant `attemptKind = 'recovery'` write were deleted after repository-wide
   reference checks.
5. `ReferenceDialerSignalingClient.invite()` clears `inviteEstablished` in its
   failure catch. No recovery action is triggered by this hygiene cleanup.

## Preserved contracts

The corrected Registerer test double still emits `stateChange` only for actual
state changes and invokes final-response callbacks independently. The
`registered` boolean, `waitForState`, all three attempt identities, separate
single-flight promises, separate retry counters, RH-3C reconnect behavior,
RH-3E idle signaling repair, RH-3F cadence, credential TTL, SIP Timer F/B, and
the canonical 120-second participation grace are unchanged.

## Verification boundary

Focused automated tests and the repository-required frontend and Make checks
were run after implementation. Browser proof was not required for these local,
behavior-preserving cleanups and was not performed. Historical RH-3 diagnosis,
settlement-fix, and live-proof evidence remains preserved.
