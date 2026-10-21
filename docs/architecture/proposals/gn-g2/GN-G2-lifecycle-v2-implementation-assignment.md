# Next assignment — bounded Deployment lifecycle v2 implementation

**PROPOSED FUTURE ASSIGNMENT.** No production deployment or writer transfer is
authorized by the real API proof. The following is one complete copy/paste prompt.

```text
# UTCP — GN-G2 Lifecycle V2: Conditional Deployment Management

Assignment: UTCP_GN_G2_LIFECYCLE_V2_CONDITIONAL_DEPLOYMENT_MANAGEMENT
Recommended worker: OpenAI Codex CLI
Model: GPT-6; reasoning effort: High
Role: Principal Kubernetes Control Plane Engineer / Distributed Systems Engineer
Execution role: IMPLEMENTER, isolated implementation and controlled verification

Objective
Implement the bounded, version-negotiated Deployment lifecycle contract required
before GN-G3 can transfer all application Deployment writers. Retain Laravel /
PostgreSQL tenant, desired-state, operation, generation, lifecycle and audit
authority. Build on the two v1 effects and the recorded real API proof; do not
repeat the PHP-versus-Go investigation or perform writer cutover.

Required tools
Git/ripgrep, repository instructions and phase ledger, pinned Go 1.25.8 SDK,
client-go/API/apimachinery v0.35.3, existing PHP 8.4 PHPUnit image, JSON Schema
validation, Docker, and only an explicitly authorized disposable real API target.
Use Go unit/race/vet/module/format checks, PHP integration tests and independent
architecture/security review. Do not install host packages or use live credentials.

Baseline and authority
Read root/path AGENTS.md, current phase-status.md, accepted ADRs, GN-G0 normative
v1 contracts, GN-G1/GN-G2 source/reports, the real API verification report and
lifecycle-v2-proposal.md. Record current branch/HEAD/staged/unstaged/untracked
state and preserve every pre-existing artifact. Existing proposed ADR remains
PROPOSED / NOT ADOPTED until explicit operator adoption. No roadmap phase is
complete solely because an isolated lifecycle implementation passes tests.

Authorized implementation boundary for this assignment
Implement isolated v2 contracts, Go lifecycle execution, narrow Laravel canonical
lifecycle/claim integration and tests needed to express target/incarnation/UID
binding. Design any required production persistence migration as a reviewed,
reversible artifact; do not run it against a deployed database. Keep all runtime
service binding and deployment inactive. No production PHP writer behavior,
ServiceAccount, RBAC, NetworkPolicy, Service or deployment change; no telephony
behavior change, CRD, Operator, independent controller or direct Go database/
Redis connection. Do not implement GN-G3 or stage/commit/push without permission.

Deliverables and contract decisions
1. Produce closed negotiated utcp.infrastructure.v2 request/result/error schemas,
   fixtures and compatibility policy without widening or changing v1.
2. Implement create_suspended, converge_projection and retire_delete plus v2
   admission and replica effects. No arbitrary manifest, patch, URL, credentials
   or generic Kubernetes proxy. Review actual Asterisk/FreeSWITCH templates and
   define every allowed projection field explicitly. Reject omitted unsupported
   template fields rather than silently dropping them.
3. Admission and execution must carry the identical complete discriminated intent.
   Hash intent.kind rather than dispatch action, include the complete validated
   projection/retirement payload through deterministic digests, and independently
   prove PHP/Go positive and negative fingerprint parity. Preserve signed-bigint
   generation strings without float coercion or implicit defaults.
4. PostgreSQL owns preparing -> bound -> retiring -> retired, terminal tombstones,
   never-reused physical incarnation names and exactly one unbound-to-UID CAS.
   Go cannot bind, unretire, allocate generations or adopt an object from labels.
   Specify and test canonical migrations/unique constraints/transactions required
   for these invariants; tests must distinguish SQLite from PostgreSQL concurrency.
5. Fresh full-envelope claim verification checks lifecycle, operation token/
   attempt/expiry, target, UID, generation, payload and all admission/retirement
   holds before every dispatch. No cached positive authorization. Projection,
   fence, restore and retirement share one canonical generation sequence.

Creation and activation
Create only the fixed reviewed suspended Deployment at replicas zero. Return
UID/RV/projection facts; Laravel binds once under current preparing intent.
Kubernetes name uniqueness handles create-if-absent, not canonical ownership.
On 409 or lost response, observe exact incarnation/template/ownership and remain
suspended. Never overwrite an unexpected same-name object, infer an old UID, or
bind a retired intent. Activation requires a separate current bound intent and
generation barrier. A delayed POST can arrive after retirement; absence, expiry
or credential revocation cannot retract it. Prove inertness of the entire
template and references, exclusive activation and absence of autoscalers/other
writers. Zero replicas alone is insufficient. Retain canonical orphan cleanup
and do not promise physical nonresurrection without separately adopted target-
side enforcement. If inertness cannot be proved, block create adoption explicitly.

Conditional convergence and retirement
Change reviewed fields, effective replicas and applied markers atomically on the
parent Deployment under UID/RV/admitted intent predicates. Preserve unrelated
fields and canonical fence/retirement holds; a new projection cannot release a
hold. Retirement requires existing Laravel telephony drain/owned-Pod completion
guards, a current retirement barrier and conditional DeleteOptions UID/RV.
Explain how the same RV protects a pre-read retirement predicate because DELETE
does not atomically accept arbitrary annotation tests. Preserve finalizers;
distinguish deletion_accepted, terminating and observed_absent. Never force delete,
remove finalizers or replay an unconditional DELETE against a name.

Unknown outcomes and delayed effects
Implement observe-first recovery for create, projection and delete, verifying
exact identity and canonical lifecycle before any new dispatch. Test create
applied/not applied with lost response, delayed create after retirement, duplicate
create races, UID-binding races, name reuse, delayed conditional delete, finalizer
changes and conflicting owners. An absent object after an uncertain POST remains
unknown; no assumption of permanent absence or automatic activation of a new
incarnation. No automatic old PHP fallback or autonomous Go cleanup authority.

Security, concurrency and error behavior
Retain internal mTLS identities/rotation, strict decoding, field allowlisting,
redacted bounded results, deadlines, backpressure, per-target bounded execution,
and pre-dispatch claim validation. A lease cannot retract an in-flight request.
Real GN-G2 proof observed JSON Patch predicate failures as HTTP422 Invalid, also
used for invalid content; keep conservative classification and do not retry every
422. Prove competing generations across instances and actual API error semantics.
Keep setup credentials separate from constrained lifecycle adapter identities.

Verification and acceptance
Run complete GN-G1/GN-G2 offline regressions and real conditional-effect proof,
v2 schema/fixture/parity tests, PHP canonical lifecycle/claim tests, Go count=1,
race, vet, module verification and formatting, security/mTLS tests, repository
hygiene, phase-status consistency, documentation checks and git diff --check.
Use the explicit owned disposable harness only when separately authorized; no
ambient or canonical context. Prove create races, UID binding, parent CAS,
delete preconditions, finalizers, delayed/unknown effects, terminal retirement,
orphan containment and controlled cleanup against a real API server. State exact
server version and separate Pod/template inertness proof from API semantics.
Keep the existing runtime-engine-config-check mismatch visible and separately
dispositioned; do not repair it incidentally or weaken its security assertions.

Handoff
Produce source, permanent tests, executable schemas/fixtures, reversible canonical
persistence artifacts, pinned one-command verifier and sanitized evidence report.
List exact files/commands/results and implemented/tested/real-server/deployed/
natural-live proof separately. Reconcile the phase ledger only when supported
canonical status changes; preserve RMA-E next action and accepted V1/K5 status.
Prepare a future all-five-corridor GN-G3 proposal covering complete legacy-request
drain, credential-level cutoff, old-effective-authority denial proof, pending
operations, unknown outcomes and conditional-compatible rollback. Do not execute
writer transfer. Report the smallest genuine blocker rather than inventing
compatibility fallbacks or claiming unproven completion.
```
