# RMA-A RecordingSession Lifecycle-Aware Start Reconciliation Implementation

Current-State-Impact: yes

## Verdict

`RMA_A_RECORDING_SESSION_LIFECYCLE_AWARE_START_RECONCILIATION_IMPLEMENTED_AND_TESTED`

## Scope and starting state

The implementation repaired the confirmed premature-dispatch defect documented
in the [exact-cause audit](./rma-a-asterisk-recording-start-conflict-exact-cause-audit.md):
the canonical recording request created `call.leg.start_recording` while the
CallLeg was still `originating`, producing Asterisk `409 Channel not in Stasis
application`. No Asterisk primitive, bridge semantics, schema, or provider
readiness field was changed.

## Canonical behavior

Recording intent is durable with `desired_state=recording` and
`observed_state=requested` while a CallLeg is pre-answer. The subordinate start
operation is created only when canonical CallLeg lifecycle reaches `answered`
(or a post-answer lifecycle state), and is persisted with the session under
transactional row locks. Terminal CallLeg states before dispatch resolve the
session as failed with the vendor-neutral code
`recording_subject_terminated_before_start`. Stop requests persist
`desired_state=stopped` before creating their existing subordinate stop
operation, so a later answer cannot dispatch a start.

The reconciliation layer does not inspect adapter identity, ARI, Stasis,
FreeSWITCH, ESL, or provider-specific readiness. Asterisk continues to use the
existing CallLeg/channel recording primitive; FreeSWITCH behavior remains
unchanged.

## Failure projection and idempotency

Canonical CallLeg observation processing invokes reconciliation after answer
and terminal observations. Session and leg locks, reloaded state checks, and the
nullable `start_operation_id` guard make duplicate/replayed observations and
concurrent workers converge to one start operation. The existing command
worker terminal-failure callback remains wired to `markOperationFailed()`, which
projects failure class, code, message, and `failed_at` into the session; focused
coverage confirms terminal failures project while retry-scheduled outcomes do
not.

## Automated proof

`make image-test-api` passed: 683 tests passed, 9 skipped, 5472 assertions.
Focused RecordingSession coverage now proves pre-answer deferral, answer
dispatch exactly once, stop-before-answer suppression, terminal-before-answer
resolution, stale-answer suppression, and terminal subordinate failure
projection. PHP syntax and `git diff --check` also pass.

## Deployment and live proof

No schema migration was required. Immutable native-k3s deployment and a fresh
complete natural-live RMA-A proof remain the next Terra-owned action; this
packet does not claim natural-live recording start/observe/stop completion.

## Preserved scope and gaps

RMA-B through RMA-H, artifacts, media storage, retention, playback, download,
FreeSWITCH recording, bridge recording, generic call lifecycle, identity, and
runtime topology remain untouched. Remaining proof gaps are successful natural
answer/start, recording observation, stop convergence, and the complete RMA-A
audit sequence in the deployed environment.
