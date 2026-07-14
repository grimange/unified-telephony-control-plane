# Unified Telephony Control Plane — Agent Instructions

## Mission

Build UTCP as a vendor-neutral telephony control plane that applications such as dialers, contact centers, IVR systems, and voice automation can build on.

UTCP owns desired state, tenant and operator policy, normalized runtime contracts, reconciliation, health history, and auditability. It does not replace the live protocol authority of Kamailio, rtpengine, Asterisk, FreeSWITCH, Kubernetes, PostgreSQL, Redis, or Traefik.

## Working method

- Inspect the repository and applicable documentation before editing.
- Work on one bounded phase or subphase per task.
- Prefer the smallest defensible implementation that satisfies the requested contract.
- Preserve valid existing work and user-authored changes.
- Do not implement future roadmap phases unless the current task explicitly includes them.
- Do not invent hidden environment gates, allowlists, feature flags, or manual activation steps without evidence that they are required.
- Do not create duplicate management authorities. Web/API authority is primary; CLI commands are limited to bootstrap, diagnostics, recovery, migration, or verification unless an ADR explicitly says otherwise.
- Treat PostgreSQL as canonical business storage. Redis may provide queues, locks, caching, and transient projections only.
- Treat WebSocket messages as notifications, never canonical business state.
- Keep vendor-specific telephony behavior behind adapter contracts.
- Keep Kubernetes concepts out of the core telephony domain. A runtime may be deployed through Kubernetes, Docker, a VM, bare metal, or a simulator.
- Do not copy code, schemas, prompts, documentation, test fixtures, names, or configuration from APNTalk or any employer/client repository.

## Architecture authority boundaries

- UTCP: business policy, desired state, reconciliation decisions, audit history.
- PostgreSQL: canonical persisted business records.
- Redis: transient coordination and acceleration.
- Kubernetes: workload placement and container orchestration.
- Traefik: HTTP, HTTPS, and application WebSocket ingress.
- Kamailio: live SIP signaling execution.
- rtpengine: RTP/SRTP media relay.
- Asterisk and FreeSWITCH: telephony application and call execution runtimes.

No implementation may silently transfer one authority to another component.

## Verification

- Add or update tests for changed behavior.
- Run the smallest relevant checks first, then broader checks required by the phase.
- Report exact commands and outcomes.
- Do not claim runtime proof from static inspection.
- Do not claim test success for commands that were not run.
- Record unresolved proof gaps explicitly.
- Store only concise, sanitized evidence. Never commit credentials, tokens, private hostnames, customer information, real telephone identities, complete noisy logs, or machine-specific secrets.

## Git safety

- Do not reset, clean, force-push, rewrite history, delete branches, or discard unrelated changes unless explicitly instructed.
- Do not commit or push unless the task explicitly requests it.
- Keep generated files and local secrets untracked.
- Before editing, inspect `git status --short` and account for pre-existing changes.

## Subagent coordination

- Use parallel subagents primarily for read-heavy exploration, review, or verification planning.
- Use one primary write owner for a bounded implementation.
- Do not allow multiple write agents to modify overlapping files concurrently.
- Subagents must return concise evidence and file references rather than raw command noise.

## Phase discipline

Every implementation task must identify:

- phase or subphase
- objective
- in-scope deliverables
- explicit non-goals
- authority constraints
- verification requirements
- completion criteria

A phase is complete only when its exit criteria are proven.

## Required final report

End bounded implementation and audit tasks with:

## Verdict

Use a precise verdict such as `PHASE_F0_COMPLETE`, `PHASE_F0_INCOMPLETE`, `EVIDENCE_AUDIT_COMPLETE`, or `BLOCKED`.

## Current State

## Implemented or Confirmed

## Files Changed

## Verification Performed

## Tests Passed

## Tests Failed

## Unresolved Proof Gaps

## Deferred Work

## Operator Required Before Next Prompt

List only real manual actions required before the next task. Write `None.` when nothing is required.
