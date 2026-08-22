# C6 Reference Call UI — Repository Implementation Evidence

Date: 2026-08-16

Status: `IMPLEMENTED / TESTED`; natural Asterisk proof remains pending.

## Scope

The Vue reference consumer adds an authenticated `Calls` route at `/calls`. It
uses the existing C6D Call, CallLeg, RuntimeOperation, and derived timeline
resources. No API, schema, adapter, conference, RH, C7, or FreeSWITCH changes
were made.

The page provides a tenant-scoped Call list, canonical Call and CallLeg detail,
minimal pre-C7 outbound creation, inbound `OFFERED` Call display, representative
Answer/Hold/Resume/Hang up/DTMF operation controls, operation status, and a
compact normalized timeline. Controls submit only normalized operation envelopes
to the existing operations endpoint and use the existing idempotency-key
mechanism.

## Authority boundary

The page never calls Asterisk or ARI and never writes Call, CallLeg, or
observation state. Operation acceptance and status are displayed separately from
canonical lifecycle state. After a command request, the page refetches the C6D
resources; `ANSWERED`, `HELD`, and terminal state are shown only when returned
by the canonical API after observation processing.

Timeline entries retain the backend's `COMMAND`, `OBSERVATION`, and `AUDIT`
source distinction. Raw provider payloads, credentials, leases, and C7 resource
management are not exposed.

## Verification

- `npx vitest --run src/views/CallConsoleView.test.ts`: 4 tests passed.
- `npm test`: 15 test files and 208 tests passed.
- `npm run lint`, `npm run typecheck`, and `npm run build`: passed.
- `make test`: passed, with 487 backend tests and 208 frontend tests; 6 existing
  PostgreSQL-dependent tests were skipped by the repository environment.
- `git diff --check`, `make repository-hygiene`, and `make secret-scan`: passed.
- `make check`: reached its existing API Pint gate and failed only on the
  unrelated `ManagedRuntimeDeprovisioningOperationTest.php` formatting finding.

The focused tests cover API-backed loading, the no-optimistic-state regression,
view/control capability gating, inbound offered display, and visible operation
failure.

Browser/live Asterisk proof was not performed. The next proof must use the real
login page and the actual Calls UI.
