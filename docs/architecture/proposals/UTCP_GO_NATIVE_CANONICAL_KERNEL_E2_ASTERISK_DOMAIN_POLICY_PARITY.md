# GO-CP1 E2 — Asterisk canonical domain-policy parity

Assignment: `UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY`.
Date: 2026-10-22. Execution role: IMPLEMENTER; developer verification, not
independent acceptance. This isolated packet extends the
[E1 canonical kernel](UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md).

Worker: OpenAI Codex CLI. Requested model/reasoning: GPT-6 / High; no separate
runtime-model attestation is claimed. Professional role: Go/PostgreSQL control
plane engineer, telephony-domain architect and distributed-systems engineer.
Tools: Git/ripgrep/shell, repository source, pinned Docker Go/PHP/PostgreSQL,
PHPUnit, Go testing/race/vet/module tools and existing repository validators.

## Result and proof boundary

**`UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_POLICY_PARITY_VERIFIED`.**
The complete pinned `tests/go-cp1-e2/verify` command exited 0. Meaningful positive
and negative actual-Laravel parity, PostgreSQL races, killed-worker restart tests
and the inherited offline regression chain passed. All three disposable database
runs verified cleanup. This is developer-owned implementation evidence prepared
for independent review, not independent acceptance or production readiness.

The new explicit `AsteriskDomainPolicy` ports the current Laravel canonical
fencing/restoration eligibility. It reads canonical PostgreSQL facts inside the
existing kernel transaction and participates in existing claim, reservation and
consumption checks. No independent operation engine or external effect is added.
The default E1 service still denies Asterisk domain operations unless the trusted
policy is explicitly installed. FreeSWITCH domain fencing/restoration remains
unsupported.

The Go-native target is operator-selected; its ADR remains **PROPOSED / AWAITING
OPERATOR ADOPTION**. This developer packet does not adopt it, alter production
writer authority or establish production readiness.

## Repository baseline and preservation

Starting branch: `main`. Starting HEAD:
`5e028b9ab3d96f3430bf715f89d66ab19a758761`. The starting tree contained 63 untracked
files, no tracked modifications and no staged changes. A baseline SHA-256
inventory covered 1,617 existing regular project files, including prior untracked
canonical/Go artifacts. The only intended pre-existing changes are
`apps/control-plane/internal/kernel/store.go` and the current module README.
The [complete task inventory](go-cp1-e2/changed-files.md) lists every addition.
The final preservation comparison found 1,615 unchanged files, exactly those two
intentional changes, and no missing file. Twelve new E2 files bring the current
untracked inventory to 75. A targeted existing-pattern secret scan of all 14
task-owned files found no findings; those files have no trailing whitespace.
No reset, clean, staging, commit or push is performed.

## Source-backed policy ownership

The complete [gate matrix](go-cp1-e2/gate-matrix.md) maps actual PHP methods,
canonical tables, authorization/rejection rules, Go functions, concurrency
concerns and permanent evidence. Key sources are:

| Responsibility | Existing source / Go counterpart |
| --- | --- |
| Canonical domain eligibility | `CanonicalInfrastructureService::assertDomainPolicy` / `AsteriskDomainPolicy.Check` |
| Eligible distinct replacement | `TelephonyDomainService::hasDistinctEligibleReplacement` and `selectRuntimeNodeForConference` / `asteriskReplacement` |
| Replacement workload | `RuntimeNodeWorkloadService` / guarded binding and nonterminal CallLeg counts |
| K5C failure-domain match | `RuntimeNodeFailureDomainEvaluator::eligible` / guarded declared/observed placement predicates |
| Restoration source provenance | Canonical service source-fence lookup / `asteriskRestoreSource` |
| Full-envelope authority | Existing Laravel `verify` / existing Go `verifyLocked`, `target`, `policyCheck` |
| Execution and completion | Existing fencing/restoration handlers and registry methods; not ported into E2 authorization |

No source condition requiring zero former-node active work is invented. A fence
is associated with an open conference and its active former binding. A distinct
eligible replacement must exist. Replacement capacity null/zero is unlimited;
otherwise active conference bindings excluding the affected conference plus
nonterminal CallLegs must fit. Required conference capabilities, desired active
state, observed readiness and applicable placement must all hold. PHP candidate
ranking is not needed to compute the same stable existence decision in Go.

The port uses the repository's current configured operation types and capability
keys. It does not reproduce arbitrary future PHP configuration overrides;
production configuration composition and compatibility remain separately scoped.

Restoration requires disabled Asterisk state, an exact positive target, current
RuntimeNode configuration, and a same-tenant/node successful completed source
fence whose disabled/scale provenance identifies the intended operation, name,
namespace, generation and prior replicas. Competing in-progress fences and active
open-conference bindings reject restoration. Replicas zero alone grants nothing.

## Implemented Go boundary

- [asterisk_policy.go](../../../apps/control-plane/internal/kernel/asterisk_policy.go)
  contains the explicit policy constructor, SQL predicates and strict provenance
  helpers. It does not call ARI, Kubernetes, Redis or another service.
- [store.go](../../../apps/control-plane/internal/kernel/store.go) invokes an
  optional policy guard before canonical row locks and maps lock timeout to a
  bounded conflict. Existing pure-database serialization/deadlock retries remain
  bounded; no external mutation retry is added.
- [unit tests](../../../apps/control-plane/internal/kernel/asterisk_policy_test.go),
  [PostgreSQL parity/race tests](../../../apps/control-plane/integration/asterisk_policy_postgres_test.go)
  and [restart tests](../../../apps/control-plane/integration/asterisk_policy_recovery_postgres_test.go)
  preserve the identified invariants.

Existing full-envelope checks remain mandatory: operation identity/status,
attempt/token/lease and deadline, tenant/node, exact immutable target,
incarnation/UID, action/version/profile/configuration, current generation and
fingerprint, persisted intent, lifecycle and supersession. V1 nonzero replicas
still require confirmed V3 active-profile evidence. V2 cannot bypass V3 activation
policy. Retirement rejects future activation across versions.

`Verify`, `Reserve` and first `Consume` re-evaluate the policy transactionally.
A policy change after verification invalidates a later reserve/consume. A
consumption already committed before retirement remains a historical grant;
repeating it returns status without another grant. PostgreSQL retirement cannot
retract an already dispatched external request.

## Transaction and concurrency design

With this policy installed, each service transaction first obtains
`SHARE ROW EXCLUSIVE` on seven fixed tables: `call_legs`,
`conference_runtime_bindings`, `conferences`, `runtime_node_capabilities`,
`runtime_node_k5c_placement_observations`, `runtime_nodes`, `runtime_operations`.
Then normal RuntimeNode → incarnation → intent → operation locks apply. This
protects missing-row predicates and new active-work inserts against ordinary SQL
writers under READ COMMITTED. No claim is made that unchanged Laravel workers
acquire this guard or that PostgreSQL globally uses SERIALIZABLE.

The guard deliberately trades throughput for a stable isolated policy decision.
It conflicts across tenants and all configured service transactions, with a
local two-second lock timeout. SQLSTATE `55P03` becomes `ErrConflict`; policy
failure is `ErrDenied`. Production contention, throughput and fairness have not
been measured. Replacing this guard with a narrower production protocol requires
proof that every relevant writer participates or an equivalent validated
transaction design. It must not be weakened incidentally.

The [verification note](go-cp1-e2/verification.md) maps all 15 requested race
families to exact tests and scope boundaries. Tests use independent PostgreSQL
connections, server-observed lock waits and real killed/restarted Go subprocesses.
Authorization remains a point-in-time serial decision, not atomicity between
PostgreSQL and Kubernetes or telephony execution.

## Independent PHP/Go behavior and deliberate boundaries

The test-only [PHP oracle](../../../tests/go-cp1-e2/oracle.php) constructs persisted
synthetic scenarios and calls the unchanged actual Laravel canonical verifier.
Go independently reads those same canonical records. Positive fencing and
positive restoration are required; a negative-only corpus cannot pass. Individual
negative families invalidate replacement, capacity, placement, source provenance,
identity, lifecycle, claim, configuration or generation conditions.

Laravel's verifier returns an authorized boolean, not policy-specific error codes.
The parity claim compares that decision; Go deterministic denial/conflict behavior
is tested separately. Existing v1/v2/v3 fingerprints and envelopes are unchanged.

A malformed-provenance regression prevents composite generation values from
collapsing to a missing scalar. Supported canonical integer/string comparisons
remain lossless, including values above `2^53`. Go deliberately rejects numeric
fractions, exponents and overflow rather than reproducing PHP floating-point
coercion. The source's legacy both-missing generation comparison remains visible.
No claim is made that every malformed PHP coercion is behaviorally equivalent.

## Implementation and test-harness corrections

Source inspection found that a composite provenance generation could otherwise
collapse into the same empty scalar representation as a missing field. The new
helper distinguishes malformed composites from the supported legacy missing-field
comparison. Permanent unit and persisted negative fixtures cover the distinction;
it does not add authority from malformed input.

The independent oracle initially exposed an isolation defect in its fixture
construction: the existing repository claimant scans the global queue and could
claim an earlier eligible fixture instead of the newly created operation. The
test-only oracle now locks earlier operation rows through a separate PostgreSQL
connection while the unchanged claimant uses `SKIP LOCKED`, then checks the exact
persisted operation's lease token and attempt against its envelope. Prior negative
fixtures remain unchanged for Go comparison. A dedicated placement-positive case
and individually invalidated negative cases therefore test actual policy decisions
rather than an accidental stale claim. Production Laravel code is unchanged.

Tests also distinguish an already-consumed status response from a fresh grant:
`Granted=false` is the duplicate-consumption contract even if the response is not
an authorization error. Neither that response nor domain authorization is evidence
that the external effect executed successfully.

An initial run of the unchanged legacy PHP feature suite against PostgreSQL
reported 50 tests, 246 assertions and one fixture error. The restoration-restart
helper inserts a 36-character UUID into `event_sources.id`, whose PostgreSQL type
is `CHAR(32)` (`SQLSTATE 22001`). The verifier runs those legacy feature suites in
their normal SQLite configuration instead of changing the production schema or
rewriting unrelated fixtures. That regression result is classified as SQLite
proof. The independently executed actual Laravel canonical oracle and all Go
transaction/concurrency scenarios remain PostgreSQL proof; the failed exploratory
legacy PostgreSQL run is not presented as a pass.

## Observation and restoration limits inherited from source

Canonical readiness and K5C placement policy here has no freshness TTL. Stale
persisted `placed` evidence can satisfy it. Missing constrained placement rejects;
missing unconstrained topology can qualify. An unavailable/unobserved former node
can intentionally qualify for fencing only with all conference, binding and
replacement gates satisfied. SQL/query failure is not treated as empty safe work.

Legacy successful-fence provenance lacks source UID/incarnation, age and latest-
success enforcement. Current immutable envelope checks and never-reused physical
names separately protect the active target; the historical source is not stronger
identity proof than its actual fields. Production refinement of these limitations
requires a bounded policy/contract decision and independent acceptance.

The restoration handler's actual pre-scale ownership and zero-workload checks,
then post-scale Deployment/Pod readiness, listener lease, connection epoch and
RuntimeNode readiness are execution/completion concerns. E2 does not treat an
eligibility decision as these completion results. No Asterisk, SIP, media,
Kubernetes API or natural-live K5 proof is performed.

## Audit, outbox, security and restart

Policy authorization itself emits no execution-success audit/outbox event or
operation completion. Existing reservation, lifecycle and result transactions keep
C0 audit/outbox semantics. Permanent tests compare audit/outbox counts for repeated
positive/negative verification and rerun rollback, deduplication and messaging
regressions. Lease tokens and private synthetic envelopes are not permanent evidence.

New subprocess tests kill real workers after committed reservation, consumption
and outcome-unknown phases for both operation families. A fresh process against
the same database observes durable state, rejects changed domain eligibility,
and cannot obtain a second consumed grant. No real external effect is executed.
Existing Ed25519/mTLS and receipt tests remain regression requirements. Go gains
no production callback, database credential sharing with infrastructure IO, or
Kubernetes privilege.

## Verification results

See [reproduction and safety](go-cp1-e2/verification.md). These results come from
the completed frozen full verifier, including the inherited E1 and canonical
foundation chain. `tests/go-cp1-e2/verify` exited 0; a focused pass alone was not
used as the complete result.

| Command / category | Fresh result / proof classification |
| --- | --- |
| Laravel migrations and repeat | 63 migrations; repeat reports nothing to migrate; disposable PostgreSQL |
| Independent Laravel Asterisk oracle | 75 decisions: 6 positive fences, 1 positive restoration, 29 fence denials and 39 restoration denials; actual canonical verifier on PostgreSQL |
| Focused `go test -count=1 -v -timeout=240s ./integration -run E2Asterisk` | PASS, 10 passing top-level tests and 101 passing subtests; one subprocess-parent helper skip, children executed |
| Full `go test -count=1 -v -timeout=240s ./...` | PASS, 43 passing top-level tests and 181 passing subtests: 224 passing nodes; two subprocess-parent helper skips |
| `go test -race -count=1 -timeout=240s ./...` | PASS; actual disposable PostgreSQL integration plus kernel/receipt/transport tests |
| `go vet ./...`; `go mod verify` | PASS; all modules verified |
| PHP canonical/operation/audit/idempotency regression suites | PASS, 30 tests and 117 assertions on PostgreSQL |
| PHP legacy fencing, registry, draining, telephony, capacity and placement suites | PASS, 148 tests and 1,445 assertions with normal SQLite configuration |
| Nested E1 Go kernel regression | PASS, 42 top-level and 106 subtests; race/vet/module checks PASS; two parent helper skips and one expected absent E2-oracle skip |
| Inherited canonical foundation PostgreSQL | PASS, canonical 19 tests/80 assertions, concurrency/restart 11/115, operations/audit 11/37 |
| Inherited normal SQLite runtime/identity regression | PASS, 43 tests and 447 assertions |
| Inherited actual PHP/Go canonical bridge | PASS, 2 top-level tests and 25 subtests in each ordinary/race run; no skipped bridge case |
| GN-G1 observer offline ordinary/race | PASS, 21 top-level tests and 86 subtests; no skip |
| Generic effects offline ordinary/race | PASS, 27 top-level tests and 143 subtests; two PostgreSQL-only skips covered by the real PostgreSQL bridge |
| Lifecycle package offline ordinary/race | PASS, 13 top-level tests and 56 subtests; two PHP-dependent skips covered by the dedicated lifecycle run |
| Dedicated lifecycle parity/mTLS ordinary/race | PASS, 15 top-level tests and 71 subtests; no skip |
| Active-profile package offline ordinary/race | PASS, 15 top-level tests and 66 subtests; three PHP-dependent skips covered by the dedicated active run |
| Dedicated active-profile parity ordinary/race | PASS, 18 top-level tests and 78 subtests; no skip |
| Inherited Python harnesses | PASS, three suites with 3, 6 and 3 tests respectively |
| Existing offline static validators | PASS; effects 69, observer 67, lifecycle 26 and active-profile 28 files inspected |
| Protected PostgreSQL catalog comparison | PASS; existing protected Laravel catalog unchanged after the existing reviewed additive SQL; E2 adds no DDL |

The full module breakdown is integration 29 top-level/128 subtests,
infrastructure 9/42, and kernel 5/11. Focused and full runs repeat some tests;
their counts must not be added as distinct cases. The nested E1 process lacks
`UTCP_CP1_E2_ORACLE`; its expected independent-parity skip does not replace the
outer comparison, which executed all 75 cases. Parent-only subprocess helpers
skip because their actual child processes perform the restart scenarios.
External observations and effects remain synthetic even though policy and
concurrency use PostgreSQL. The inherited offline PHP runs also passed: their
reported suite results were 20 tests/209 assertions, 111/597 (twice), 59/368,
20/209 and 20/267 (twice). Repeated suites and package selections overlap and are
not presented as a count of unique tests.

Cleanup receipts from the outer E2, nested E1 and canonical-foundation runs each
confirmed removal of all run-owned containers, volumes and networks. No published
database port, pre-existing volume or live service was used. Private temporary
outputs are not permanent repository evidence and are not copied into this report.

The following baseline checks were freshly reproduced independently of E2:

| Command | Result / disposition |
| --- | --- |
| `make repository-hygiene phase-status-consistency-check` | PASS with final 75-file untracked inventory; zero evidence changes; status impact `no` |
| `git diff --check` | PASS; new/untracked content also receives separate inspection |
| `bash -n tests/go-cp1-e2/verify` | PASS |
| `make runtime-engine-config-check` | Existing exit 2: ordinary platform workers must not carry Kubernetes API-client label; affected `utcp-platform/api`, `scheduler`, `telephony-reconciler` |
| `make secret-scan` | Existing exit 2: possible secret material at `apps/web/src/views/ExternalTrunkDetailView.vue:292` |

Neither baseline issue is suppressed, exempted or incidentally repaired. Both
remain separate acceptance dependencies for future production integration.

## Current state, planned work and next assignment

Current behavior: isolated Go source and test-only composition; deployed Laravel
writers remain unchanged. Target architecture: operator-selected Go-native modular
monolith, separate canonical database and infrastructure-IO privilege roles.
Planned work: trusted recovery evidence, production configuration-publication
identity, formal architecture adoption and any later controlled wiring/cutover.
Experimental proof: synthetic canonical PostgreSQL and offline transport/executor
regressions. Proven runtime behavior: none newly claimed by E2.

E1's affirmative `confirmed_not_applied` receipt limitation and nested recovery-
barrier uncertainty/liveness gap remain outside E2. The
[next assignment](go-cp1-e2/next-assignment.md) supplies exactly one complete E3
prompt addressing that evidence boundary. It does not recommend direct cutover.
FreeSWITCH domain policy, production contention/freshness refinement and full
runtime completion remain separately scoped prerequisites.

Current-State Ledger Impact: **NO_CHANGE_REQUIRED**. This isolated proposed
migration packet changes no accepted V1/K5 classification, deployed authority or
canonical RMA-E next action. Phase-Status Consistency Check: **PASS**.
Operator prerequisites for this isolated developer packet: **None**. Production
adoption and authority transfer require separate explicit operator/governance action.
