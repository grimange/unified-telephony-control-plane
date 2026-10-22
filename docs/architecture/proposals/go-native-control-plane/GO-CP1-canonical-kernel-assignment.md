# First implementation assignment

Status: ready-to-copy proposal; implementation and independent acceptance require
operator authorization. [Architecture report](../UTCP_GO_NATIVE_CONTROL_PLANE_ARCHITECTURE_PIVOT_AND_MIGRATION_PLAN.md).

```text
UTCP — Go-Native Canonical Kernel E1
Assignment: UTCP_GO_NATIVE_CANONICAL_KERNEL_E1
AI worker/coder: OpenAI Codex CLI
Model: GPT-6; reasoning effort: High
Professional role: Principal Go/PostgreSQL Control Plane Engineer / Distributed Systems Engineer
Execution role: IMPLEMENTER; developer verification, not self-acceptance.
Repository: grimange/unified-telephony-control-plane

Mission
Implement the first isolated Go canonical kernel against the existing verified
PostgreSQL schema. Preserve Laravel as the current live owner. Reuse lifecycle,
operation, generation, claim, audit/outbox and uncertainty behavior; add durable
dispatch reservations and authenticated observation receipts. Do not create a
parallel operation engine or deploy a Go writer.

Required tools
Git/ripgrep/shell, AGENTS and local instructions; pinned Go 1.25.8 Docker SDK
and existing client-go v0.35.3 modules; pgx/v5 + pgxpool; Goose v3 SQL migration
tooling (pin compatible exact versions in go.mod/go.sum); PHP 8.4/Laravel 12/
PHPUnit 11 behavioral oracle; uniquely named disposable PostgreSQL Docker
containers; JSON Schema validation; mTLS/Ed25519 and deterministic HTTP fault
injection; existing Go/PHP verifiers, race/vet/module/format/security/doc checks.
Do not install host packages. No production credentials or database connections.

Authority and baseline
Read repository/path-local AGENTS, phase-status, accepted ADRs, proposed Go-native
ADR and all go-native-control-plane documents, especially the PostgreSQL authority
contract. Read the canonical Laravel foundation report, source/migration and
existing v1/v2/v3 contracts/fixtures/Go engines. Record actual branch/HEAD/full
status and hash all pre-existing task artifacts. Preserve unrelated work. Confirm
operator adoption status; proposed ADR remains unadopted unless separately
recorded. Do not mark canonical RMA-E or V1/K5 status changed by isolated work.
No commit/push without explicit authorization.

Authorized implementation
1. Create only needed packages in apps/control-plane per blueprint: app,
   postgres, kernel and infrastructure. Use explicit pgx SQL and constructors,
   one shared transaction scope, bounded pool and explicit role startup. No
   production route, schema application, service discovery or dispatch wiring.
2. Reuse existing runtime_operations, infrastructure_incarnations/intents, C0
   audit/outbox/inbox/idempotency schema and IDs/statuses. Preserve constraints,
   append-only audit triggers, lock order and tenant relationships. Build disposable
   databases with existing migrations; compare actual catalog including triggers,
   functions/views/grants and seed contracts. Define reviewed Go SQL baseline
   adoption; do not replay baseline DDL over existing production schemas.
3. Implement preparing/bound/retiring/retired, never-reused names, one-way UID
   binding (including retiring cleanup semantics), exact signed-BIGINT generations
   as decimal strings with explicit max/overflow, canonical intent/fingerprint and
   supersession. Cross-version v1/v2/v3 claims must verify the complete persisted
   operation/attempt/token/expiry/deadline/target/profile/action envelope and
   tenant/RuntimeNode authority without caller-supplied lifecycle truth.
4. Preserve Asterisk domain eligibility, current observation gates, activation
   after binding only and terminal retirement holds. FreeSWITCH domain fencing
   and restoration remain unsupported. Configuration-reference fingerprints do
   not prove mutable contents; enforce/document approved image/config identity.
5. Add minimal reviewed SQL for dispatch evidence and signed observation receipts.
   Implement utcp.dispatch.v1 reserve/consume/result/status and
   utcp.observation-receipt.v1 as separate closed internal protocols, without
   silently changing normative infrastructure v1/v2/v3 fingerprints or results.
   Atomically reserve exact current attempt, one unresolved ordinary mutation per
   incarnation plus the single serialized higher-generation recovery-barrier
   exception defined in the authority contract, executor SAN/capability/envelope binding; consume once immediately
   before a dispatch grant. Replayed/lost consume responses grant no second write.
   Keep the closed claim-response schema unchanged; consume/grant uses a new
   injected transport seam. Use existing injectable Verifier seams; do not dispatch real Kubernetes effects.
6. Implement receipt challenge, issuer/scope/key trust, deterministic Ed25519
   signature, replay resistance, freshness/completeness, exact UID/incarnation,
   opaque resourceVersion, marker/effect validation and transactional recording.
   Exact authenticated GET404 can support absence; DELETE2xx/list omission/403
   cannot. Caller assertions cannot bind a UID or finish retirement. Keep delayed
   requests unresolved until bounded; no blind retry or automatic RV refresh.
7. Persist outcome_unknown/observe_first and restart recovery across crash windows.
   Superseded results cannot finish newer work. Audit/outbox and state transitions
   commit together; no secrets in events. No positive authorization cache and no
   Go database credentials passed to an executor.

Explicit exclusions
No live PostgreSQL/Kubernetes/Redis; no cluster creation or mutations; no deployed
migration/backfill; no browser auth migration, public/private production callback
route or active executor deployment; no PHP writer changes; no GN-G3/canonical
writer cutover, telephony traffic, ADR adoption, host packages, commit or push.
Use only authorized uniquely named disposable PostgreSQL and synthetic HTTP/mTLS
fixtures; validate identity/ownership and clean up only owned containers/volumes.

Deliverables
Working isolated Go module with pinned dependencies; minimal additive SQL and
schema compatibility/adoption notes; closed protocol schemas and positive/negative
fixtures; independent PHP/Go fingerprints and claim-decision fixtures; real
PostgreSQL concurrency and subprocess-kill/restart tests; signed receipt/mTLS
integration harness; one pinned verification entry point; evidence report with
exact counts/commands/proof limits and complete changed-file inventory; one
bounded next assignment based on demonstrated gaps.

Required acceptance tests
- Run deterministic fixtures and simulator oracles before provider integration
  gates. CP1 uses synthetic external effects; it authorizes no live telephony.
- Independent PostgreSQL connections and synchronized transactions: concurrent
  prepare/name allocation, generation allocation/rollback/overflow, competing UID
  bind, retire vs reserve/consume, supersession vs claim, duplicate reservation
  and consume, attempt rollover, stale executor and expired capability.
- Decimal strings above 2^53 and at BIGINT maximum, malformed/overflow rejection;
  independent v1/v2/v3 fingerprint and error/decision parity; old-version activation
  bypass, wrong tenant/node/UID/profile/action and unsupported FS domain denial.
- Kill and recreate actual Go worker processes before/after reserve commit,
  consume commit, simulated external effect, receipt/result commit; retain unknown
  outcomes and prevent duplicate grants or false success after restart.
- Lost consume/result responses; authentic/forged/expired/replayed/incomplete
  receipts; name reuse, stale UID, delayed create, pending deletion/finalizers,
  exact absence vs authorization failure; progress through a newer recovery barrier
  while old delivery remains unknown, no unsafe create exception; old results cannot resolve newer work.
- Valid/invalid/expired/wrong-SAN mTLS and rotation; key rotation/revocation,
  replay/concurrency, request limits/cancellation/backpressure; redaction.
- Audit/outbox commit, rollback, duplicate transition, inbox delivery retry and
  append-only enforcement; schema construction/adoption and legacy compatibility.
- go test -count=1 ./...; go test -race -count=1 ./...; go vet ./...;
  go mod verify; gofmt; schema/fixtures; focused existing PHP runtime-operation,
  identity and audit/outbox regressions; existing observer/effects/lifecycle/
  active-profile suites using repository verifiers. No real-API reproof needed
  unless implementation changes those Kubernetes semantics.
- Repository hygiene, phase-status consistency, docs/links/whitespace, secret
  scanning including untracked source, git diff --check, preservation hashes.
  Keep known runtime-engine checker and Vue scanner findings visible; do not
  incidentally repair or suppress them.

Independent review gate
Report IMPLEMENTED/DEVELOPER_TESTED only after actual required tests pass. Hand
the exact diff and evidence to an independent verifier/security reviewer. Separate
unit, PostgreSQL concurrency, mTLS simulation and inherited Kubernetes/live proof.
No claim of production readiness, safe writer cutoff or natural-live acceptance
follows from CP1. Missing production service wiring is a later stage, not a reason
to invent a fallback. State remaining defects precisely and preserve authority.
```
