# V1 Gap A — Delayed Runtime Observation vs Origination Timeout Precedence Audit

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `84f94525433a8a0da67e9acafc8616714dd0d53d`
(`docs(v1): close registration dialog return gap`)

## Verdict

`V1_GAP_A_PRECEDENCE_AUTHORITY_RESOLVED_BOUNDED_IMPLEMENTATION_READY`

The canonical precedence is **already implemented and empirically correct in both
interleavings**. What Gap A actually lacks is (1) any regression test pinning the
timeout-first direction, (2) a stated authority for the rule — ADR-030 §14
explicitly disclaims it — and (3) one unguarded write path on a terminal row.
No behavioral defect in canonical termination precedence was found.

One adjacent functional consequence was discovered and is recorded but is **not**
part of the precedence rule: an origination timeout performs no runtime
cancellation, so a late `answered` implies a runtime channel UTCP has abandoned.

## The race

```text
T0  call.leg.originate accepted; leg observed_state = originating
T1  no authoritative success observation before the deadline
T2  CallOriginationReconciler terminalizes the CallLeg
T3  a runtime observation for the same origination is processed after T2
```

## Origination-timeout authority

```text
routes/console.php:845-855                        ensure targets for non-terminal outbound legs
  -> ReconciliationRepository::ensureTarget('call_leg_origination', legId, 1)
  -> App\TelephonyDomain\Reconciliation\CallOriginationReconciler::evaluate()
     -> App\TelephonyDomain\CallDomainService::terminalizePendingOrigination()
        -> call_legs  UPDATE  observed_state=failed, termination_reason=origination_timeout,
                              termination_party=system, terminated_at=now()
        -> calls      UPDATE  same, when the Call is not already terminal
        -> audit      call_leg.terminated / call.terminated  (origin canonical-reconciliation)
```

Predicate (`CallOriginationReconciler.php:26-56`):

```text
leg.direction == 'outbound'
leg.observed_state in [requested, selecting_route, originating]   # else converged(300)
latest call.leg.originate operation exists
operation.status not in [pending, leased, running, retry_scheduled] # else waiting(10)
operation.status == 'succeeded'                                    # else origination_failed
deadline = strtotime(operation.completed_at ?? updated_at ?? created_at)
         + config('telephony_domain.origination_timeout_seconds', 60)
time() >= deadline
```

Both clocks are UTCP-side. The runtime-supplied `observed_at` is **not** consulted
by the deadline.

Transaction and locking: `terminalizePendingOrigination` runs in one
`DB::transaction`, takes `lockForUpdate()` on the CallLeg, re-checks terminality
and the eligible-state set inside the lock, then takes `lockForUpdate()` on the
Call and re-checks its terminality before writing.

## Runtime-observation authority

```text
AsteriskAriEventListener / AsteriskAriEventNormalizer
FreeSwitchEventNormalizer
SimulatorEventNormalizer
  -> normalized observations only; none writes calls/call_legs
     -> App\RuntimeEngine\Projection\ProjectionService::apply()   (one DB::transaction)
        -> runtime_observations insertOrIgnore                    (ALWAYS, before any domain call)
        -> if inserted == 1 and type starts with call.leg./call.legs.
           -> App\TelephonyDomain\CallObservationProcessor::process()
              -> CallDomainService::bindObservedRuntimeChannel()
              -> CallDomainService::applyObservedLegTransition()   (offered/ringing/answered/held/bridged)
              -> CallDomainService::terminalizeObservedLeg()       (terminated/failed)
                 -> terminalizeObservedLegLocked() -> calls aggregate
```

`grep` for `update(`/`insert(` against `calls` and `call_legs` outside
`app/TelephonyDomain/` returns nothing: **`CallDomainService` is the sole
canonical writer.** No adapter mutates canonical state.

## Terminal-state guards — current state

| Guard | Status | Location |
| --- | --- | --- |
| Row lock before terminal decision | exists | `terminalizePendingOrigination`, `applyObservedLegTransition`, `terminalizeObservedLeg`, `applySuccessfulCallOperation`, `terminalize`, `applyTransition` |
| CallLeg terminal guard | exists | all of the above; `terminalizeObservedLegLocked` re-checks |
| Call aggregate terminal guard | exists | `terminalizeObservedLegLocked`, `terminalizePendingOrigination`, `advanceCallFromLeg` |
| Write-once terminal metadata | exists | `terminalize()` returns false on identical repeat, throws `LogicException` on conflicting rewrite |
| Legal-transition table | exists | `LEG_TRANSITIONS` / `CALL_TRANSITIONS` |
| Runtime node + channel identity fence | exists | `applyObservedLegTransition`, `terminalizeObservedLeg`; unique index on (runtime_node_id, runtime_channel_id) |
| Event-source epoch gate | exists | `CallObservationProcessor::isCurrentEpoch` |
| Receipt / observation dedupe | exists | `runtime_event_receipts_dedupe_unique`, `runtime_observations_source_unique` |
| Terminal guard on `bindObservedRuntimeChannel` | **absent** | `CallDomainService.php:508-533` |
| Occurrence-time (`observed_at`) precedence comparison | absent by design | not used for timeout precedence |
| Version / fencing token on CallLeg | absent | not required; row lock + terminal guard suffice |

`bindObservedRuntimeChannel` is the only path that can write to an already-terminal
CallLeg row. It is currently unreachable in the Gap A scenario because outbound legs
receive a deterministic `runtime_channel_id` (`utcp-call-leg-<legId>`) at insert
(`createOutboundLeg`), so the `runtime_channel_id !== null` early return always fires.
It writes no terminal metadata. It is nonetheless an unguarded write to a terminal row
and is included in the bounded seam as defensive hardening.

## Timestamp semantics

| Timestamp | Source | Persisted | Controls transitions |
| --- | --- | --- | --- |
| `runtime_observations.observed_at` | runtime-supplied (`$observation['observed_at'] ?? now()`) | yes | only via `hasRequestedTerminationIntent` |
| `runtime_observations.received_at` | UTCP `now()` | yes | no |
| `runtime_observations.created_at` | UTCP `now()` | yes | no |
| `runtime_operations.completed_at` | UTCP | yes | yes — starts the timeout deadline |
| `call_legs.terminated_at` / `answered_at` | UTCP `now()` at apply time | yes | write-once |

`observed_at` influences canonical meaning in exactly one place:
`CallDomainService::hasRequestedTerminationIntent()` requires a termination
operation's `created_at <= observed_at` (ADR-030 §5.2). It plays no role in
timeout precedence, and there is no occurrence-time comparison anywhere in the
timeout path.

## `origination_timeout` semantic meaning

ADR-030 §3 defines it verbatim as **"Origination was never observed to complete
within the canonical deadline."** Producer: domain / `CallOriginationReconciler`.
Terminal state `Failed`, `termination_party = system` (§4: "UTCP reconciliation or
lifecycle enforcement initiated it (for example origination timeout)").

This is **meaning A**: an absence-of-evidence determination by the control plane.
It is explicitly *not* a claim that the runtime failed to originate, and not an
abandonment or cancellation. That distinction is what makes the precedence
question real: a late `answered` does not contradict the timeout's stated meaning,
because the timeout only ever asserted that no completion had been *observed* in
time.

## Runtime cancellation on timeout

**None.** `terminalizePendingOrigination` writes canonical state and audit records
only. It requests no RuntimeOperation. `call.leg.cancel_origination` is produced
solely by the authenticated API (`CallController::storeOperation`); no reconciler,
sweeper, or domain path emits it. `CallDomainService::failRuntimeLostLegs` fires
only on the RuntimeNode stale transition. Orphan reclamation exists for conference
participants (`TelephonyDomainService::reclaimOrphanParticipantChannels`) and has
**no CallLeg equivalent**.

Consequence: a delayed `answered` after an origination timeout indicates a runtime
channel that canonical state has abandoned and that nothing will hang up. This is an
adjacent functional gap, not a precedence defect, and is deferred (see below).

## Observed current behavior

Both orderings were exercised against exact HEAD through a temporary,
uncommitted probe using the existing simulator receipt/normalizer/projection
helpers. No production source was modified. Late observations were emitted with
`observed_at` 90 s in the past — i.e. **occurring before the timeout** — which is
precisely the case ADR-030 §14 left open.

Timeout first, then a delayed observation:

```text
late call.leg.offered     leg failed/origination_timeout/system  call failed/origination_timeout  answered_at NULL  observation stored
late call.leg.answered    leg failed/origination_timeout/system  call failed/origination_timeout  answered_at NULL  observation stored
late call.leg.terminated  leg failed/origination_timeout/system  call failed/origination_timeout  answered_at NULL  observation stored
late call.leg.failed      leg failed/origination_timeout/system  call failed/origination_timeout  answered_at NULL  observation stored
```

Observation first, then the deadline is evaluated:

```text
answered first    leg answered   reconciler converged   leg answered, answered_at stamped, no termination
terminated first  leg completed  reconciler converged   leg completed/remote/remote
```

Duplicates:

```text
three timeout evaluations            -> 1 call_leg.terminated audit record
two duplicate late terminated facts  -> terminal metadata unchanged, still 1 audit record
```

Canonical terminal state is immutable in every case, and the late runtime fact is
preserved in `runtime_observations` regardless — the insert precedes and is
independent of the domain call.

## Canonical precedence rule

```text
The first canonical terminal fact applied to a CallLeg wins and fixes
observed_state, termination_reason, termination_party and terminated_at.

Once CallOriginationReconciler has terminalized a CallLeg as
failed / origination_timeout / system, every later runtime observation for that
CallLeg — offered, answered, orderly termination, or failure — is preserved as a
runtime observation and is never applied to canonical CallLeg or Call state,
regardless of its observed_at.

Symmetrically, once any runtime observation has advanced the CallLeg out of
[requested, selecting_route, originating], the origination timeout no longer
applies and the reconciler converges without terminalizing.

Application order, established under a row lock, is authoritative. Runtime
occurrence time is evidence, never a canonical reordering authority.
```

### Authority basis

* ADR-030 §14.1 — "The first causal terminal fact to be applied wins and fixes
  `termination_reason`, `termination_party`, `terminated_at`, and the terminal
  state." The origination timeout is a domain-applied terminal fact and is
  governed by this clause.
* ADR-030 §14.2 — later terminal facts are ignored idempotently.
* ADR-030 §13 — the domain derives canonical meaning "on receiving a terminal
  observation **for a non-terminal CallLeg**"; a terminal CallLeg is outside the
  derivation's scope.
* ADR-030 §3 — `origination_timeout` asserts only that completion was not observed
  in time, so a later contradicting fact does not falsify the recorded meaning.
* ADR-023 / `CallObservationProcessorTest` — write-once terminal metadata.
* Vendor neutrality — accepting a runtime-supplied `observed_at` as authority to
  reopen canonical state would let runtime clock skew rewrite canonical business
  state.

ADR-030 §14 states this ADR "does not address the general problem of an
observation whose `observed_at` precedes a terminalization but whose processing
follows it." The rule above is the resolution of exactly that sentence, and it is
consistent with — not an extension of — §14.1.

## Conflicting legacy terminal mutation authority

```text
NONE
```

`CallDomainService` is the sole writer of `calls` and `call_legs`. No adapter,
normalizer, listener, console command, or controller mutates canonical terminal
state. Nothing requires removal or runtime-authority cutoff.

## Existing test coverage

| Case | Status | Test |
| --- | --- | --- |
| Timeout fires once; duplicate evaluation idempotent | covered | `CallDomainServiceTest::test_accepted_origination_without_observation_times_out_once_and_observation_progression_wins` |
| Observation-first suppresses the timeout | covered | same test (ringing progression) |
| Write-once terminal metadata after an observation-driven terminalization | covered | `CallDomainServiceTest::test_transitions_are_provider_neutral_and_terminal_metadata_is_write_once` |
| Duplicate / conflicting terminal observations after an observed terminalization | covered | `CallObservationProcessorTest::test_observations_progress_without_regression_and_terminal_metadata_is_fenced` |
| Terminal legs cannot be resurrected by local command confirmation | covered | `CallDomainServiceTest::test_local_command_confirmation_is_idempotent_and_cannot_resurrect_terminal_legs` |
| Closed-epoch observation retained without canonical mutation | covered | `CallObservationProcessorTest::test_closed_epoch_observation_is_retained_but_does_not_mutate_canonical_state` |
| **Timeout-first, then late offered** | **missing** | — |
| **Timeout-first, then late answered (`answered_at` must stay NULL)** | **missing** | — |
| **Timeout-first, then late orderly termination (reason must stay `origination_timeout`)** | **missing** | — |
| **Timeout-first, then late failure** | **missing** | — |
| **Late observation with `observed_at` before the timeout** | **missing** | — |
| **Call aggregate cannot reopen after timeout** | **missing** | — |
| **Late observation preserved in `runtime_observations` after timeout** | **missing** | — |

The entire timeout-first half of the race is unprotected by regression coverage.

## Bounded implementation seam

Tests (primary deliverable):

```text
apps/api/tests/Feature/TelephonyDomain/CallDomainServiceTest.php
  add the timeout-first precedence regressions using the existing
  createOutboundCall + runtime_operations backdating + CallOriginationReconciler helpers

apps/api/tests/Feature/TelephonyDomain/CallObservationProcessorTest.php
  add the late-observation-after-timeout regressions using the existing
  runtimeNode() / emit() simulator receipt + ProjectionService helpers,
  including an observation whose observed_at precedes the timeout
```

Source (defensive hardening, one method):

```text
apps/api/app/TelephonyDomain/CallDomainService.php
  bindObservedRuntimeChannel()  (lines 508-533)
  add the terminal guard already present in every sibling path:
      if (CallState::from($leg->observed_state)->terminal()) { return false; }
  immediately after the lockForUpdate() fetch
```

Documentation:

```text
docs/decisions/ADR-030-canonical-call-termination-reason-and-intent-authority.md
  amend §14 to state the resolved Gap A rule in place of the deferral sentence,
  and move Gap A out of the Non-goals list
```

No new service, column, migration, state, event, operation type, config key,
scheduler, or command is required.

## Deterministic acceptance matrix

| # | Scenario | Required outcome |
| --- | --- | --- |
| 1 | timeout, then late `call.leg.offered` | leg `failed`/`origination_timeout`/`system`; Call unchanged; observation row present |
| 2 | timeout, then late `call.leg.answered` | as 1; `call_legs.answered_at` and `calls.answered_at` remain NULL; Call does not reopen |
| 3 | timeout, then late `call.leg.terminated` | as 1; reason stays `origination_timeout`, never `remote`; `terminated_at` unchanged; one audit record |
| 4 | timeout, then late `call.leg.failed` | as 1; reason stays `origination_timeout`, never `runtime_lost` |
| 5 | late observation whose `observed_at` precedes the timeout | identical to 1–4; occurrence time grants no precedence |
| 6 | `answered` observation before the deadline, then reconciler runs | reconciler `converged`; leg stays `answered`; no termination fields written |
| 7 | `terminated` observation before the deadline, then reconciler runs | leg `completed`/`remote`/`remote`; reconciler `converged` |
| 8 | reconciler evaluated three times after the deadline | exactly one `call_leg.terminated` audit record |
| 9 | duplicate late observation after timeout | terminal metadata byte-identical; no additional audit record |
| 10 | Call aggregate after 1–4 | `failed`/`origination_timeout`; never `answered` or `completed` |
| 11 | RuntimeOperation after 1–4 | `call.leg.originate` unchanged; no new operation created |
| 12 | observation preservation | every late observation present in `runtime_observations` with its own `observed_at` and `received_at` |
| 13 | `bindObservedRuntimeChannel` against a terminal leg | returns false; no row mutation |

## Deferred — not part of the precedence rule

**Orphaned runtime channel after origination timeout.** The timeout issues no
runtime cancellation, and no CallLeg orphan reclamation exists. A late `answered`
is positive evidence that a runtime channel existed and was abandoned. Deciding
whether the timeout should also request `call.leg.cancel_origination`, or whether
a CallLeg orphan-reclamation sweep should mirror
`reclaimOrphanParticipantChannels`, is a behavior change that ADR-030 does not
authorize and that carries double-hangup risk. It requires its own bounded
decision and implementation packet. It does not block the precedence rule, which
is correct and deterministic independently of it.

**Vocabulary divergence (adjacent, Gap E territory).**
`CallOriginationReconciler.php:45` writes `termination_reason = 'origination_failed'`
when the originate operation itself reached a terminal-failure status. That value is
not in ADR-030 §3's V1 vocabulary. It is recorded here as an observed fact only; it
is not a Gap A precedence question and is not resolved by this audit.

## Boundary

No production source was changed by this audit. The temporary probe used to observe
current behavior was deleted before any commit; the working tree contains only this
evidence document and the ledger reconciliation. No live telephony Call was placed,
and no Kubernetes, provider, PBX, router, or credential state was touched.
