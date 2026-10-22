# Next bounded assignment — trusted recovery evidence and forward progress

This prompt follows the E2 policy work. It addresses the documented E1 recovery
limitations; it does not authorize production wiring or writer cutover.

```text
# UTCP — GO-CP1 E3: Trusted Recovery Evidence and Bounded Forward Progress

AI worker/coder: OpenAI Codex CLI
Model: GPT-6
Reasoning effort: High
Professional role: Principal Go/PostgreSQL Control Plane Engineer /
Distributed Systems and Security Engineer
Execution role: IMPLEMENTER
Assignment: UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_EVIDENCE
Repository: grimange/unified-telephony-control-plane

Mission
Refine the existing isolated Go kernel's challenged observation and recovery
protocol. E1 deliberately leaves affirmative confirmed_not_applied observation
unsupported, and a consumed/unknown recovery-barrier dispatch can block further
automatic progress. Preserve safety while defining and implementing only the
bounded evidence and progress mechanisms justified by the actual current code.
Do not reinterpret a fresh GET showing no effect as proof that a delayed write
cannot still arrive. Preserve E2 Asterisk authorization and FreeSWITCH exclusion.

Required tools
Git/ripgrep/shell; applicable AGENTS and skills; pinned Go 1.25.8 Docker SDK;
pgx/v5 and PostgreSQL 17.6 disposable Docker databases; PHP 8.4/Laravel/PHPUnit
reference; existing dispatch/receipt and v1/v2/v3 schemas/fixtures; Ed25519 and
mTLS tooling; deterministic synthetic IO; real independent PostgreSQL connections
and killed subprocesses; Go test/race/vet/module/format checks; repository hygiene,
secret scan, documentation and phase checks. No host packages or live credentials.

Baseline and governance
Read branch/HEAD/status, hash all starting artifacts, and preserve unrelated work.
Read GO-CP1 E1/E2 reports, authority contract, signed-receipt and dispatch source,
current recovery tests, proposed ADR and canonical ledger. Act as Implementer;
independent review remains separate. Do not adopt an ADR or change V1/K5/RMA-E.
Document the exact source-level recovery gap before altering its protocol.

Authorized bounded implementation
1. Define which authenticated evidence can establish confirmed_not_applied for
   each existing effect. Distinguish authoritative rejection before application,
   delivery uncertainty, current effect absence and conditional containment by a
   newer observed barrier. Uncertain inert creation must retain its tombstone and
   cleanup-only treatment; absence alone must not erase delayed-create risk.
2. Extend the smallest versioned private receipt/result contract needed for such
   evidence. Preserve existing infrastructure v1/v2/v3 semantics and fingerprints;
   do not silently broaden a closed schema. Add positive and negative fixtures.
3. Persist evidence, exact operation/attempt/dispatch/UID/incarnation correlation,
   issuer/challenge/signature, freshness, completeness and current authority in
   one transaction with audit/outbox. Reject unsupported assertions and replay.
4. Implement bounded recovery for a lost recovery-barrier result where the exact
   newer barrier is authentically observed. Never grant a second consumed right,
   create an unbounded exception-slot chain, or falsely declare old delivery known.
   If safe forward progress requires request/credential quiescence beyond this
   scope, retain the unresolved state and report that exact dependency.
5. Preserve full-envelope claim and E2 domain checks at any new reservation or
   consumption. Restart recovery must not cache authorization or revive old
   incarnations. Keep the canonical DB role separate from synthetic IO.
6. Add deterministic PostgreSQL races and actual killed/restarted Go subprocesses
   at evidence commit, receipt consumption, recovery reservation, grant and result
   boundaries. Independent processes must reconstruct sufficient durable state.
7. Use the existing Laravel behavior only where a comparable canonical rule exists;
   do not claim new Go recovery protocol behavior already exists in Laravel.

Exclusions
No Kubernetes cluster creation/access/mutation, live telephony or traffic,
production PHP changes, production database or schema application, active Go
executor/listener/deployment, public callback, external writer transfer,
automatic PHP fallback, FreeSWITCH domain support, ADR adoption, host packages,
staging, commit or push. Minimal reviewed additive SQL is permitted only if
necessary for subordinate evidence; no competing operation engine.

Deliverables
Source-backed recovery decision table; smallest justified versioned contracts;
functional isolated Go refinement; permanent positive/negative signature, identity,
replay and concurrency tests; subprocess crash/restart proof; pinned verifier;
sanitized report with complete inventory and exact commands/counts; exactly one
next assignment selected from actual remaining evidence. Preserve raw temporary
credentials and logs outside permanent evidence.

Validation and objective independent acceptance gates
Run tests/go-cp1-e2/verify plus the new E3 verifier and all relevant prior kernel,
receipt, dispatch, mTLS, schema and offline infrastructure regressions. Run go test -count=1 ./..., go test -race -count=1 ./..., go vet ./...,
and go mod verify, plus formatting with the pinned toolchain. Validate the
existing Laravel canonical reference, PostgreSQL catalog, constraints, audit/outbox,
idempotency, cancellation, bounded pools, and cleanup. Record known independent
runtime-engine label and Vue scanner failures without suppressing them.

Acceptance requires no unsupported absence/not-applied inference, no duplicate
dispatch grant, bounded slots, no old UID rebind, terminal history preservation,
signed exact evidence, current authorization, real PostgreSQL serialization and
restart durability, no regression of meaningful positive/negative E2 Asterisk
parity, verified disposable cleanup, hygiene and phase consistency. Report any
remaining recovery liveness limit explicitly. Developer tests establish review
readiness, not independent acceptance or production cutover readiness.
```
