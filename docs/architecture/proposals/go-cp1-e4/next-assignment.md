# Next bounded assignment — GO-CP2 identity and tenancy compatibility E1

This is the next implementation recommendation after E4's isolated developer
gates pass. It is not execution authorization, ADR adoption or production cutover.
It progresses the broader Go backend migration while retaining the current PHP
behavioral reference and the separate canonical/database and infrastructure-I/O
roles. WebSocket replacement, domain administration and live session transfer
remain later bounded work.

```text
# UTCP — GO-CP2 E1: Go-Native Identity and Tenancy Compatibility

AI worker/coder: OpenAI Codex CLI
Model: GPT-6
Reasoning effort: High
Professional role: Principal Go Application Security Engineer / PostgreSQL
Control Plane Engineer
Execution role: IMPLEMENTER
Assignment ID: UTCP_GO_NATIVE_IDENTITY_TENANCY_COMPATIBILITY_E1
Repository: grimange/unified-telephony-control-plane

Objective
Implement an isolated Go identity/tenancy vertical slice with independent Laravel
behavioral parity. Preserve the current browser contract for login, logout,
session eligibility, active tenant, capability projection and tenant-scoped
request authorization. Reuse existing PostgreSQL identity records and GO-CP1
audit/outbox primitives. Do not deploy or transfer authentication authority.

Baseline and governance
Read root/path-local AGENTS, the canonical phase ledger, Go-native proposed ADR,
module blueprint, migration matrix and identity/security decisions; E1–E4 reports
and their source. Record branch, HEAD, staged/tracked/untracked inventory and
SHA-256 preservation hashes before edits. Preserve every existing artifact.
Act as Implementer; independent review and operator acceptance remain separate.
No reset, clean, staging, commit, push, ADR adoption or accepted phase reclassification.
Preserve V1/K5 and canonical RMA-E next action. Report evidenced ledger impact.

Required tools
Existing pinned Go 1.25.8 SDK, pgx/v5/pgxpool and PostgreSQL 17.6 Docker environment;
PHP 8.4/Laravel 12/PHPUnit 11 oracle; existing Vue API/client source; net/http,
crypto/rand, standard cookie/CSRF and constant-time verification facilities;
focused reviewed password-hash compatibility dependency only if required; actual
PostgreSQL transactions/concurrency, browser-contract HTTP tests, ephemeral mTLS
where applicable; go test/race/vet/mod verify/gofmt; repository schema, security,
hygiene, documentation and phase-status checks. No host package installation.

Source audit before implementation
Inventory actual Identity services, User model, session middleware, login/logout
routes/controllers, request/response/error envelopes, capability/role catalogs,
tenant membership selection, user/tenant suspension, password-reset/session-version
behavior, cookie/CSRF settings, throttling and the Vue client calls. Identify exact
PostgreSQL tables, defaults, indexes and append-only audit behavior. Build a
source-backed positive/negative gate matrix with exact paths/methods and tests.
Do not invent requirements or assume Laravel session serialization or encrypted
cookies are natively understood by Go.

Authorized implementation
1. Add only coherent Go identity/application HTTP packages containing actual code,
   with explicit constructors and interfaces. Keep canonical domain authorization
   separate from HTTP adaptation. Do not add a generic framework or ORM.
2. Reuse existing users, tenants, memberships, roles/capabilities and canonical IDs.
   Implement current user/session-version, tenant and membership eligibility,
   platform versus tenant capability rules and safe projections. Query persisted
   canonical truth; do not accept caller-supplied membership or capability claims.
3. Independently verify existing Laravel password hashes, supported algorithms,
   malformed hash behavior and password lifecycle. No plaintext password/audit
   leakage, permissive fallback or silent algorithm conversion. Any rehash policy
   must match reviewed existing authority and remain isolated.
4. Implement a test-only Go browser-session flow with secure random session IDs,
   bounded lifetime, rotation on login/privilege transitions, logout/revocation,
   session-version invalidation and CSRF enforcement matching the observed API
   contract. Follow the architecture's explicit future reauthentication policy;
   do not deserialize or adopt live Laravel sessions. If a subordinate Go session
   table is necessary, justify minimal additive SQL, keep it test-only, compare
   the protected Laravel catalog and retain one DDL authority.
5. Implement the bounded login/logout/current-identity/active-tenant HTTP slice
   using exact discovered paths and response/status/header semantics in isolated
   httptest servers. Preserve request IDs, safe errors and frontend capability
   projection. No production listener, route binding or Vue change.
6. Implement deterministic login throttling and denial behavior against a focused
   test store. Redis may be transient coordination only; it must not become user,
   tenant or authorization authority. Document future multi-instance rate-limit
   requirements rather than claiming an in-memory limiter is globally enforced.
7. Commit identity transitions with the existing canonical audit/outbox contract
   where required. Idempotent retry must not duplicate events; failed transactions
   must not emit successful login/reset/tenant transition events. Never record
   passwords, raw cookies, CSRF tokens or secret payloads.
8. Preserve separate kernel DB authority and executor credential isolation. Do not
   give browser requests a dispatch shortcut or modify GO-CP1 journal/evidence,
   generation, lifecycle, Asterisk policy or Kubernetes contracts.

Exclusions
No deployed PostgreSQL/Redis/Kubernetes or telephony access, no production data,
credentials, route/service wiring, active Go worker, writer migration, live
session/cookie handover, production backfill, PHP writer changes, Vue API rewrite,
WebSocket/realtime replacement, FreeSWITCH domain policy, arbitrary backend port,
public callback, GN-G3, host package installation, commit or push. Do not repair
the independent K5 static-check or Vue scanner baseline finding incidentally.

Deliverables
A source-backed identity/tenant gate matrix; functional isolated Go services and
HTTP slice; closed request/response fixtures; independent PHP/Go password,
authorization and response parity; real PostgreSQL migration/concurrency evidence;
transactional audit/outbox tests; one pinned tests/go-cp2-e1/verify entry point;
permanent evidence report including commands/counts/failures, compatibility and
security decisions, changed-file inventory and preservation hashes; exactly one
next bounded prompt based on observed remaining gaps.

Required verification
Test meaningful positive login, membership selection, platform and tenant grants;
wrong password, malformed hash, inactive user, suspended tenant/membership,
missing capability, wrong tenant, session fixation, stale session version,
expired session, logout replay, CSRF missing/mismatch, malformed/duplicate/unknown
JSON fields, rate-limit behavior, cancellation and safe errors. Use independent
PHP oracle results, not one generator for both expected and actual outputs.

Coordinate separate PostgreSQL connections for logout/reset versus authorization,
user or tenant suspension versus request authorization, membership/capability
change versus active-tenant access, concurrent login/session rotation and duplicate
transition audit/outbox. State actual isolation and lock/version discipline; do
not infer PostgreSQL correctness from SQLite tests. Restart a real Go process
against persisted test sessions and prove revocation/expiry remains effective.

Run the new pinned verifier; relevant existing Laravel Identity/authorization,
user reset and catalog-upgrade tests; GO-CP1 E4 full verifier to preserve journal,
E3 recovery, E2 75-case Asterisk parity and offline infrastructure suites. Within
apps/control-plane run go test -count=1 ./..., go test -race -count=1 ./...,
go vet ./..., go mod verify, and formatting. Validate catalog protection,
fixtures, negative security tests, docs links, repository hygiene, phase-status
consistency, secret scans/new untracked sources, git diff --check and preservation
hashes. Record the unchanged independent runtime-engine checker and Vue scanner
signatures separately. Verify cleanup of only run-owned PostgreSQL, containers,
networks, volumes and ephemeral credentials.

Objective acceptance gates
All supported identity/tenancy positive and negative parity decisions match the
actual Laravel oracle; current browser responses and cookie/CSRF contract are
preserved in isolated tests; no cross-tenant or stale-session authorization;
password compatibility is independently proven; no session fixation or CSRF
bypass; real PostgreSQL concurrency/restart and audit/outbox tests pass; existing
GO-CP1 tests pass; dependencies and secret handling are reviewed; catalog and
prior artifacts remain intact; all disposable resources are removed; no live
writer/session authority changes. Any unsupported encrypted-cookie/session
interoperability is an explicit transition constraint, not a silent fallback.
Developer evidence must be handed to an independent reviewer before acceptance.
```
