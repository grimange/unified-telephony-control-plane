# V1 Gap A — Origination Timeout Precedence Implementation

Current-State-Impact: yes

Date: 2026-08-30

## Verdict

`V1_GAP_A_PRECEDENCE_IMPLEMENTED_TESTED_AND_CLOSED`

Gap A is closed by deterministic repository regressions, the defensive terminal
guard in `CallDomainService::bindObservedRuntimeChannel()`, and the ADR-030 §14
authority amendment. The canonical writer remains `CallDomainService`; the
CallLeg row lock remains the precedence enforcement point.

## Resolved authority

The first canonical terminal fact applied under the CallLeg row lock wins and
fixes `observed_state`, `termination_reason`, `termination_party`, and
`terminated_at`. A timeout produces `failed / origination_timeout / system`.
Later offered, answered, orderly-termination, or failure observations remain
in `runtime_observations` and cannot reopen or rewrite the CallLeg or Call,
even when `observed_at` predates the timeout. An observation applied first
advances the leg and suppresses timeout terminalization. Runtime occurrence
time is evidence, not canonical reordering authority.

## Bounded implementation

* Added the terminal-state guard immediately after the locked CallLeg fetch in
  `bindObservedRuntimeChannel()`.
* Added explicit timeout-first regressions for late offered, answered, orderly
  termination, failure, and pre-timeout `observed_at`.
* Added observation-first, duplicate timeout/observation, Call aggregate,
  RuntimeOperation, observation persistence, and terminal binding regressions.
* Amended ADR-030 §14 and removed Gap A from its non-goals.

The 13-case acceptance matrix is represented by the focused domain and
observation tests: all cases pass when the repository API test environment is
available.

| Case | Regression coverage |
| --- | --- |
| 1. Late offered after timeout | `test_late_offered_after_origination_timeout_is_persisted_without_reopening` |
| 2. Late answered after timeout | `test_late_answered_after_origination_timeout_cannot_set_answered_at_or_reopen_call` |
| 3. Late orderly termination | `test_late_orderly_termination_after_origination_timeout_preserves_timeout_metadata` |
| 4. Late failure | `test_late_failure_after_origination_timeout_preserves_timeout_metadata` |
| 5. Earlier `observed_at` | Timeout-first delayed cases use a timestamp 90 seconds before processing |
| 6. Answered first | `test_answered_observation_first_suppresses_origination_timeout` |
| 7. Terminal observation first | `test_terminal_observation_first_suppresses_origination_timeout` |
| 8. Duplicate timeout evaluation | `test_repeated_origination_timeout_evaluation_is_idempotent` |
| 9. Duplicate late observation | `test_duplicate_late_terminal_observation_does_not_add_canonical_terminal_audit` |
| 10. Call aggregate cannot reopen | Late answered and shared timeout assertions |
| 11. RuntimeOperation unchanged | Shared late-observation assertions |
| 12. Late observation persisted | Shared late-observation assertions |
| 13. Terminal channel binding refused | `test_terminal_call_leg_rejects_observed_runtime_channel_binding_without_mutation` |

## Scope and proof boundary

No runtime cancellation, orphan-channel reclamation, new operation, scheduler,
state, schema, feature gate, manual control, browser proof, live provider Call,
or Kubernetes mutation was added or performed. Orphan runtime-channel behavior
is unchanged and deferred as a separate decision. Gap E remains open; Gap F is
`PROOF_GAP_ONLY`. ADR-031 remains `DEFERRED_BY_ENVIRONMENT`, not abandoned;
K5 and RMA remain deferred.

## Repository identity and validation

Starting branch: `main`.

Starting implementation target: `01d57c405007bf22e88b4d41ceeb44a74341224b`.

The host lacked a `php` executable, so direct PHP lint and PHPUnit execution
could not run in this environment. Repository-defined container/API tests and
the mandatory hygiene and phase-status checks are recorded in the completion
handoff.
