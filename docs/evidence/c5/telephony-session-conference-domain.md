# C5 Evidence: Telephony Session and Conference Domain

Date: 2026-07-15

## Current Verdict

`PHASE_C5_COMPLETE`

C5 domain code, focused checks, authenticated API proof, live Kubernetes runtime proof, observability proof, security proof, disposable Compose proof, and regression proof passed locally against the canonical `utcp-local` Kubernetes runtime on 2026-07-15. Hosted CI execution is not observed for the uncommitted working tree.

## Implemented Locally

- PostgreSQL migration for `telephony_sessions`, `conferences`, `conference_runtime_bindings`, and `conference_participants`.
- C5 capability catalog entries under C1 identity roles.
- Runtime-neutral capability keys `conference.lifecycle` and `conference.participation`.
- Authenticated API routes for telephony sessions, conference management, member conference visibility, self-admission, and self-removal.
- Application services for session creation/end/expiry, conference creation/state changes/runtime binding, participant admission/removal, audit, outbox, idempotency, and reconciliation-target creation.
- Runtime-neutral operation handlers for `conference.ensure`, `conference.close`, `conference.participant.ensure`, and `conference.participant.remove`.
- Production-neutral conference and participant reconcilers registered through the existing C3 reconciliation registry.
- Deterministic simulator support for conference and participant runtime operations.
- Simulator raw conference events normalized into runtime-neutral conference and participant observations.
- Projection updates for conference and participant observed state through C3 projection authority.
- Scheduler commands for telephony-session expiry and C5 reconciliation target discovery.
- Safe aggregate status command and Make targets.

## Focused Verification Performed

```text
bash -n scripts/telephony-domain/config-check scripts/telephony-domain/test scripts/telephony-domain/api-proof scripts/telephony-domain/runtime-proof scripts/telephony-domain/status
php -l apps/api/app/TelephonyDomain/TelephonyDomainService.php
php -l apps/api/app/Http/Controllers/TelephonyDomain/ConferenceController.php
make telephony-domain-config-check
make telephony-domain-test
make telephony-domain-api-proof
make telephony-domain-runtime-proof
make telephony-domain-status
git diff --check
```

Observed results:

```text
telephony_domain_config_check=ok
TelephonyDomainTest: 2 passed, 31 assertions
telephony-domain-api-proof: passed against the deployed HTTPS API
telephony-domain-runtime-proof: passed against Kubernetes workers, PostgreSQL, and simulator-event-source
telephony-domain-status: safe aggregates returned from the live database
git diff --check: passed
```

## Live Runtime Path Proven

The live runtime proof exercises the deployed API, scheduler, C3 workers, simulator adapter, simulator event source, PostgreSQL authority, raw receipts, normalizers, projectors, checkpoints, and reconcilers:

- admin creates simulator-bound conference
- member cannot manage conference configuration
- desired `open` creates `conference.ensure`
- command worker executes simulator adapter
- simulator event source publishes `simulator.conference.ready`
- event normalizer emits `conference.lifecycle.observed`
- projector sets conference observed `ready`
- participant admission creates `conference.participant.ensure`
- simulator emits joined evidence
- projector sets participant observed `joined`
- session end moves participant desired state to `removed`
- participant removal operation emits left evidence
- projector sets participant observed `left`
- draining rejects new admission
- conference close emits closed evidence
- projector sets conference observed `closed`
- session expiry moves active sessions to `expired`
- expired-session participation moves to desired `removed`
- command-worker and simulator-event-source Pod restarts preserve durable work and produce one terminal completion/projection

## Live Proof Evidence

```text
make k8s-image-build
make k8s-image-push
make k8s-apply
make k8s-status
make telephony-domain-api-proof
make telephony-domain-runtime-proof
make security-proof
make observability-proof
make compose-proof
make local-proof
make test
make check
make build
make container-check
```

The Kubernetes migration Job applied `2026_07_15_090000_create_telephony_session_conference_domain_tables.php`. Live schema inspection confirmed `telephony_sessions`, `conferences`, `conference_runtime_bindings`, `conference_participants`, lifecycle constraints, ownership foreign keys, uniqueness constraints, expiry indexes, reconciliation scan indexes, configuration-generation fields, and observed-state fields.

The runtime proof observed:

```text
conference_ensure_operations=1
conference_ready_receipts=1
conference_ready_observations=1
participant_ensure_operations=1
participant_remove_operations=1
conference_close_operations=1
session expiry live proof passed
conference draining live proof passed
telephony domain runtime proof passed
```

The C5 metrics endpoint exposes bounded aggregate metrics only, including `telephony_sessions_total`, `telephony_sessions_active`, `conferences_total`, `conferences_by_observed_state`, `conference_participants_total`, `conference_participant_operations_total`, `conference_reconciliation_total`, and `conference_participant_reconciliation_total`. C5 alert rules loaded with `ok` health and normal inactive state.

## Proof Split

The live API proof created or reused C5 records only through authenticated APIs and proved member administrative denial, session creation, session idempotency, conference visibility, self-admission, and admission idempotency. A foreign-tenant conference fixture was not created in the live proof because doing so without direct C5 database insertion requires changing the proof tenant's memberships and would weaken the tenant-isolation condition being checked. Cross-tenant conference access remains proven by focused PostgreSQL-backed feature tests.

Duplicate observation, stale epoch, and old-generation rejection are proven by focused projection and simulator tests. The live runtime does not expose a public event injection path, and no such route was added for proof.

## Remaining Notes

- Hosted CI execution has not been observed.
