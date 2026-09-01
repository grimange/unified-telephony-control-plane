# RMA-A RecordingSession Lifecycle Natural-Live Proof Blocker

Current-State-Impact: yes

**Date:** 2026-09-01
**Repository:** `main` at `8bd27e79f6aca510695a0128de165232726f77e6`
**Environment:** native k3s, context `default`
**Native k3s image workflow:** `Native k3s Images` run `33472125608` — succeeded
**Deployed API image:** `ghcr.io/grimange/utcp-api@sha256:c37ce8ddf8dbf84209f62519e312f598cf81aa8a227402e8357c5b862e46a7f0`
**Target Asterisk image:** `ghcr.io/grimange/utcp-asterisk@sha256:1865f34b6887b967b61505af4416d6dbdc9c3123dca7d6bfd20db57ff7fdb1fc`

## Verdict

`RMA_A_NATURAL_LIVE_PROOF_BLOCKED_CALL_NOT_NATURALLY_ANSWERED`

The immutable implementation deployment converged, and a fresh canonical
pre-answer RecordingSession proved the lifecycle-aware deferral repair in the
deployed system. The maintained Asterisk Echo destination did not naturally
answer, so the proof could not legitimately exercise recording start,
recording observation, canonical stop, or stopped convergence. The first
remaining blocker is the native-k3s Asterisk Echo-fixture boundary, not a
regression of pre-answer RecordingSession reconciliation.

## Scope and method

The Call and RecordingSession were created through the supported authenticated
UTCP APIs. Runtime and Asterisk state were inspected read-only. No SQL or Redis
mutation, direct ARI recording or answer command, direct RuntimeOperation
creation, forced observation, bridge manipulation, or manual reconciliation
was used.

The native-k3s migration completed and the digest-pinned API, worker, and
target Asterisk deployment converged before the Call was created. The image
publication workflow represented source commit
`8bd27e79f6aca510695a0128de165232726f77e6`.

## Fresh canonical proof

```text
Call:                 4ce85992-0c0b-4507-9692-c6e69060221e
CallLeg:              871a352f-6d07-4ebe-b9f9-ddd7384c46b9
RuntimeNode:          102d58ba-93ec-4601-a2a3-81f95801440f
Runtime channel:      utcp-call-leg-871a352f-6d07-4ebe-b9f9-ddd7384c46b9
Originate operation:  f2533e68bd804ed7a11dd0c8579b663a
RecordingSession:     4fd23465a8cb49c27c3782e0346d5390
```

The originate operation succeeded at `05:37:05` UTC. At the same pre-answer
boundary, the RecordingSession was persisted with
`desired_state=recording`, `observed_state=requested`, and
`start_operation_id=null`. No `call.leg.start_recording` RuntimeOperation
existed. This is deployed proof that canonical recording intent remains durable
without prematurely dispatching a provider recording command.

No canonical `answered` observation arrived. The runtime observed channel
termination at `05:37:36` UTC; the canonical Call and CallLeg became `failed`
with remote termination at `05:37:40` UTC. The pending session then converged
to failed with the vendor-neutral code
`recording_subject_terminated_before_start` and still had no start operation.
This also proves the terminal-before-eligibility path without provider-specific
state entering canonical authority.

## Fixture boundary evidence

Read-only inspection of the selected Asterisk Pod
`asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-56465szclc` found that
its optional `asterisk-local-sip-fixtures` ConfigMap mount referred to a
ConfigMap that was not present. A read-only Asterisk dialplan inspection found
only the reject catch-all in `from-kamailio`; extension `9900` resolved to that
path rather than an Echo extension.

This proves the maintained live destination was not materialized as an Echo
fixture in this deployment. It does not, by itself, prove the deeper source of
the absent ConfigMap or authorize a manual runtime fixture change.

## Authority and current state

Canonical UTCP authority remains the desired RecordingSession intent, CallLeg
lifecycle, RuntimeOperation orchestration, and terminal projection. Asterisk
continues to own dialplan execution and whether the Echo fixture can answer.
No Asterisk Stasis fact or other provider-specific readiness state was added to
the canonical model.

The lifecycle-aware repair remains implementation-tested and is now partially
live-proven for pre-answer deferral and terminal-before-eligibility resolution.
RMA-A is not complete or full natural-live-proven: the natural answer, one
automatic start operation, provider recording start, start observation,
canonical stop, provider stop, stopped observation, idempotency of active
start/stop, and full audit sequence remain unproven.

## Exact next action

Have GPT-5.6 Luna Medium make the bounded native-k3s Asterisk Echo-fixture
materialization/attachment repair, then have GPT-5.6 Terra Medium repeat the
fresh complete RMA-A natural-live lifecycle proof.

## Preserved scope

No application source, runtime-state data, ARI recording primitive, bridge
recording behavior, FreeSWITCH behavior, artifact/storage work, identity,
authorization, generic RuntimeOperation design, or cluster topology was
changed by this proof packet.
