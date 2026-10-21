# Next proposed assignment: canonical lifecycle and claim foundation

Status: proposed follow-up only. This prompt does not authorize deployment,
writer transfer or ADR adoption. Review the active-runtime implementation report
and the [source parity boundaries](active-source-parity.md) before execution.

```text
Assignment: UTCP_GN_G2_CANONICAL_LIFECYCLE_AND_CLAIM_FOUNDATION
AI worker/coder: OpenAI Codex CLI.
Model: GPT-6. Reasoning effort: High.
Role: Principal Laravel/PostgreSQL Control Plane Engineer / Distributed Systems Engineer.
Repository: grimange/unified-telephony-control-plane.

Objective: replace the test-only canonical incarnation/authorization model with
a durable, independently tested Laravel/PostgreSQL lifecycle and claim foundation
that can support the existing Go v1/v2/v3 contracts. Do not deploy or route a Go
writer, transfer credentials, or modify the five production Deployment writers.
Production activation and GN-G3 remain separate authorizations.

Tools: Git/ripgrep/source inspection; existing PHP 8.4/Laravel 12/PHPUnit Docker
environment; disposable PostgreSQL using repository tooling; pinned Go 1.25.8
SDK and client-go v0.35.3; JSON Schema; existing GN-G1/GN-G2/lifecycle/active
verifiers; mTLS/fault-injection harnesses; repository hygiene/phase/docs checks.
Do not install host packages or use production databases or credentials.

First record branch, HEAD, complete working tree and hashes of prior untracked
artifacts. Read AGENTS.md, API/docs instructions, phase-status, accepted ADRs,
the PROPOSED / NOT ADOPTED adapter ADR, v1/v2/v3 contracts, current operation
repository, active source parity matrix and implementation reports. Preserve all
existing work. No commit/push. Do not silently adopt the ADR. Explicit operator
authorization of its authority/production integration decision is required before
production wiring; if absent, deliver the durable foundation and service-level
test seam without registering production routes, scheduler bindings or dispatch.

Authorized implementation: narrowly scoped Laravel canonical lifecycle services,
reviewable additive database migrations and persistence models; transactional
identity/generation/claim validation; a service-level verifier compatible with the
existing mTLS callback contract; isolated PostgreSQL/PHP/Go integration tests and
documentation. Use existing operation, audit and outbox authority. Introduce no
second operation queue, independent reconciler, duplicate telephony policy or
Go database access. Do not execute migrations against a deployed database.

Required canonical behavior:
1. Durable preparing -> bound -> retiring -> retired lifecycle, tenant/RuntimeNode
   association, unique never-reused physical name/incarnation, one-way Kubernetes
   UID binding and terminal retirement history. Do not hard-delete evidence.
2. Transactionally allocate lossless PostgreSQL-bigint generations in one shared
   target sequence across v1/v2/v3. Persist exact intended envelope/fingerprint,
   supported profile/configuration identity and current supersession state.
3. Fail-closed service-level claim verification of operation ID/status, tenant,
   RuntimeNode, target cluster/namespace/name/UID/incarnation, action, profile,
   complete intent, current generation/fingerprint, attempt, lease token/expiry
   and deadline. Use existing operation fences and lock order; no positive cache.
4. Deny activation before UID binding or after retiring/retired; prevent v1
   nonzero restoration and v2 inert projection from bypassing active-profile or
   fence/retirement policy. A newer generation alone does not authorize unfencing.
   Preserve existing Asterisk restore/fence domain gates. Treat FreeSWITCH domain
   fence/restore as unsupported until explicitly authorized and implemented.
5. Preserve observe-first unknown outcomes. Durable pending/uncertain effects must
   survive restart without blind replay, RV refresh, replacement-UID adoption or
   fallback PHP dispatch. Kubernetes results are evidence, not canonical policy.
6. Bind approved digest image and Secret/configuration references to canonical
   intent. State honestly whether payload immutability is enforced; do not claim
   configuration_sha256 proves mutable Secret/ConfigMap contents. Keep secret
   bytes out of callback responses/logs and Go. Do not widen resource permissions.

Not authorized: production Go listener/route/service binding, production cluster
or RBAC changes, legacy writer disabling, credential revocation, live operations,
telephony changes/calls, generic Kubernetes manifest inputs, GN-G3 cutover, ADR
adoption, or incidental runtime-engine-config-check repair.

Deliverables: bounded additive persistence/services, service-level claim verifier,
transactional outbox/audit integration where the existing contract requires it,
PostgreSQL concurrency/restart/tenant regression tests, independent PHP/Go full
envelope and fingerprint fixtures, migration/backfill/rollback design preserving
evidence, concise implementation report with exact changed files and one next
assignment. Enumerate all five unchanged production Deployment writer corridors
and the remaining callback deployment, writer cutoff and rollback prerequisites.

Acceptance: run against disposable PostgreSQL (not only SQLite): concurrent
prepare/bind/generation/retire races; unique-name and one-way UID constraints;
wrong tenant/node/UID/incarnation; expired/superseded claims; competing attempts;
cross-version activation bypass; retirement during verification; restart with
unknown outcomes; operation/audit/outbox consistency; schema and PHP/Go parity.
Exercise the service seam through existing test mTLS integration without adding a
production callback route. Run focused and affected PHP suites; complete existing
Go observer/effect/lifecycle/active verifiers, race/vet/module/format/schema checks;
repository hygiene, docs, phase consistency, new-file secret/syntax checks and
git diff --check. Record actual counts and limitations, not inferred live proof.

Keep runtime-engine-config-check's accepted K5 API-client label mismatch visible
as a separate repair prerequisite; do not suppress or broaden the assertion.
Update phase-status only if evidenced canonical current state changes; otherwise
report NO_CHANGE_REQUIRED and passing consistency. ADR remains unadopted absent
explicit operator adoption. Do not claim GN-G3 readiness: production callback,
legacy in-flight quiescence, all-five-corridor exclusive authority, credential
cutoff, external autoscaler handling, compatible rollback and V1/K5 live proof
remain subsequent gates. Report implementation, PostgreSQL-tested, simulated,
real-API-proven and deployed proof separately.
```
