# Next bounded assignment — Asterisk canonical policy parity

This is the recommended bounded follow-up to the verified GO-CP1 packet. Its
scope addresses the confirmed fail-closed Asterisk policy gap. The prompt does
not itself authorize production wiring, architecture adoption or writer transfer.

```text
# UTCP — GO-CP1 E2: Asterisk Canonical Domain Policy Parity

AI worker/coder: OpenAI Codex CLI
Model: GPT-6
Reasoning effort: High
Professional role: Principal Go/PostgreSQL Control Plane Engineer /
Telephony Domain Architect / Distributed Systems Engineer
Execution role: IMPLEMENTER
Assignment: UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY
Repository: grimange/unified-telephony-control-plane

Mission
Complete the bounded Asterisk domain-eligibility gap in the isolated Go canonical
kernel. GO-CP1 intentionally denies runtime fencing/restoration without a trusted
complete DomainPolicy. Port the actual Laravel behavior rather than substituting
an infrastructure-only or permissive policy. Keep FreeSWITCH domain fencing and
restoration unsupported. Do not rebuild existing infrastructure effects.

Required tools
Git/ripgrep; applicable AGENTS and skills; pinned Go 1.25.8 SDK; pgx/v5;
PostgreSQL 17 disposable Docker proof; PHP 8.4/Laravel/PHPUnit oracle; existing
v1/v2/v3 contracts, GO-CP1 verifier and offline infrastructure suites; Go test,
race, vet, module verification, schema, mTLS, hygiene, documentation, phase and
secret checks. No host packages or production credentials.

Baseline and authority
Read current branch/HEAD/status and preserve all existing artifacts with hashes.
Read GO-CP1 report, proposed Go-native ADR, PostgreSQL authority contract,
canonical service and actual TelephonyDomainService, RuntimeNode registry,
fencing/restoration handlers and tests. Identify complete current source gates,
database tables and lock order before editing. Act as Implementer; independent
acceptance remains separate. Keep ADR and accepted V1/K5/RMA-E state unchanged.

Authorized implementation
1. Implement one explicit trusted Asterisk DomainPolicy in apps/control-plane.
   Use existing canonical tables and current operation/attempt authority.
2. Port current fencing eligibility, including tenant/family/capability identity,
   node readiness/desired state, active telephony work and bindings, replacement
   availability, admission/capacity and applicable failure-domain evidence.
   Preserve missing/stale placement and unavailable-health semantics.
3. Port restoration provenance: exact successful fencing source, operation/node
   correlation, current configuration version, tenant/namespace/name/replicas,
   disabled source state and competing fence/active binding protections.
4. Preserve full-envelope leases, current generation, immutable UID/incarnation,
   retirement holds and all cross-version activation protections. No cached grant.
5. Keep policy SQL in canonical transactions with consistent deterministic lock
   order. No HTTP/Kubernetes call inside transactions, and no partial eligibility
   result interpreted as permission.
6. Build independent PHP/Go positive and negative fixtures against real disposable
   PostgreSQL, using the real Laravel policy as oracle, not a shared generator.
7. Test actual concurrent domain changes versus verification/reserve/consume,
   stale observations, capacity exhaustion, source mismatch, supersession,
   retirement and process restart. Prove each accepted outcome has a valid serial
   canonical history. Fail closed for any unsupported behavior.

Exclusions
No production PHP behavior/schema/database changes, browser/API work, live
Kubernetes access or cluster creation, telephony execution, production listener,
active executor, writer cutover, automatic PHP fallback, FreeSWITCH domain support,
ADR adoption, host packages, commit or push. Use synthetic runtime observations
with explicit proof limits. New persistence is not expected; justify any genuine
minimal additive requirement before introducing it.

Deliverables
Source-backed gate matrix; isolated complete Asterisk policy; permanent unit,
PostgreSQL concurrency and independent PHP/Go oracle tests; reproducible pinned
verification; concise evidence report with exact commands/counts, remaining gaps
and preservation inventory; exactly one next assignment selected from results.

Validation and independent acceptance gates
Run tests/go-cp1/verify and relevant Laravel domain/fencing/restoration suites;
Go test -count=1, race -count=1, vet, mod verify, formatting, existing offline
observer/effects/lifecycle/active-profile regressions, schema/mTLS/security,
repository hygiene, phase consistency, docs/links, secret scan and diff checks.
Use real PostgreSQL connections for races. Record all skips/failures and retain
the independent runtime-engine/scanner baseline findings without weakening them.
Acceptance requires equivalent positive and negative Asterisk policy decisions,
cross-version/tenant/lease/UID/retirement enforcement, stable concurrent histories,
no lost audit/outbox consistency, no existing regression and no production action.
Report developer-tested readiness for independent architecture/security and
PostgreSQL review; do not declare self-acceptance or production readiness.
```
