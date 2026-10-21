# GN-G2 implementation assignment — conditional Kubernetes Deployment effects

The following is a ready-to-copy assignment. It proposes the next bounded work;
it grants no approval of the ADR, cluster access, writer cutoff or deployment.

```text
# UTCP — GN-G2: Conditional Kubernetes Deployment Effects

Assignment: UTCP_GN_G2_CONDITIONAL_KUBERNETES_DEPLOYMENT_EFFECTS
Recommended worker: OpenAI Codex CLI
Model: GPT-6; reasoning: High
Role: Principal Kubernetes Control Plane Engineer / Distributed Systems Engineer
Execution scope: isolated implementation and developer proof; no production cutover

Mission
Implement the established GN-G0 conditional action contract using direct client-go
for admit_generation and set_replicas on an already-bound Deployment. Preserve
Laravel/PostgreSQL canonical operation, tenant, desired-state, lease/fence, retry,
audit and telephony policy authority. Build on the isolated GN-G1 module and its
read-only regressions. Do not re-run a general PHP-versus-Go architecture review.

Required tools
Git, ripgrep, source inspection, existing Make/check scripts, pinned Go SDK Docker
image and client-go, Go test/race/vet/mod verify, httptest, focused PHPUnit, schema
validation, and an explicitly authorized disposable real Kubernetes API-server
test environment. No host package installation and no live credentials. Do not
create a cluster or change topology merely to evade an unavailable proof gate.

Baseline and authority
Read root/path-local AGENTS.md, current phase ledger and accepted ADRs. Record
branch, HEAD, staged/tracked/untracked files and preserve all previous work.
Read GN-G0 report, complete normative v1 README/action/result/error schemas and
fixtures, proposed adapter ADR, GN-G1 report, module and PHP parity tests. Read
runtime operation repositories/handlers, fencing and workload clients/callers.
The adapter ADR is PROPOSED / NOT ADOPTED unless the operator separately adopts
it. Do not imply adoption from this assignment. Canonical RMA-E next action and
accepted V1/K5 state remain unchanged. Confirm the independent runtime-engine
config-check defect; do not fix or suppress it incidentally.

Authorized implementation
- Isolated, undeployed, narrowly typed action implementation and local/test HTTPS
  mTLS endpoint; preserve GN-G1 observer as read-only.
- Only admit_generation and set_replicas for a canonical pre-bound Deployment.
- Complete closed-schema validation before any access; exact trusted cluster,
  namespace, RuntimeNode/tenant, target/incarnation, Deployment name and UID.
- Test-only Laravel claim-verification bridge using the complete validated
  action; no production PHP behavior/schema change or Go database/Redis client.
- Dedicated unit, HTTP simulation, PHP error-parity and real API-server tests.
- Versioned proposed create/delete/name-reuse contract extension and acceptance
  design only; no lifecycle effect implementation without explicit scope.
- Documentation and reproducible sanitized evidence.

Not authorized
Production routing, Service/RBAC/NetworkPolicy/Deployment changes, deployment to
the canonical cluster, real credentials, application writer cutoff, production
PHP replacement, canonical DB migration, telephony behavior changes, arbitrary
Kubernetes operations, Operator/CRD/controller-runtime authority, stage/commit/push.
GN-G3 must receive separate authorization before any writer transfer.

Conditional effect requirements
1. Use immutable Deployment UID plus exact opaque resourceVersion predicates.
   A pre-read followed by an unconditional write is insufficient. Preserve
   unrelated fields. Do not use unconditional /scale, SSA force or automatic
   retries as a substitute for conditional fencing.
2. Validate existing ownership labels, tenant/RuntimeNode canonical identity,
   target/incarnation binding and fixed allowed operation/absolute replicas.
3. Reproduce the normative fingerprint independently in PHP and Go. The target
   generation is PostgreSQL-allocated, lossless decimal-string, immutable in
   hash, and distinct from Kubernetes metadata.generation and operation token.
4. Before each barrier/effect dispatch, call the fixed claim verification seam
   with the complete action. Denial, outage, expiry or cancellation fails closed;
   no cached positive claim. Preserve current claim and lifecycle checks.
5. admit_generation conditionally writes admitted-generation/intent markers;
   reject an existing higher generation or equal generation/different hash.
6. set_replicas conditionally tests UID, RV and admitted generation/hash, then
   atomically writes absolute replicas plus applied generation/hash. Treat
   matching markers AND current effect as already_satisfied; replica equality
   alone is insufficient. Drift requires re-read, current claim and CAS.
7. Per-target local serialization bounds load; API-side CAS protects across
   replicas. Backpressure/deadlines are bounded and Go has no durable queue.
8. Use explicit dispatch/outcome/error metadata. Lost response after dispatch,
   timeout or cancellation cannot become definite failure/success. Return
   outcome_unknown/observe_first; canonical PHP owns durable recovery and retry.
9. Verify API Status semantics for 409 and JSON Patch predicate failures. Do not
   map every 422 to a retryable conflict. Wrong UID, ownership or incarnation
   fails closed even when a resource name has been reused.
10. No success completes telephony fencing or restoration by itself. Existing
    owned-Pod, readiness, active-work and listener/event/lifecycle gates stay in
    Laravel. GN-G1 v1 observation has no deletionTimestamp and unverified owner
    chains; it cannot prove lifecycle completion or owned-Pod absence.

Required distributed cases
- Lease expired before dispatch: reject before external I/O.
- Lease expired after dispatch: explain non-retractable in-flight request.
- Older generation after newer Kubernetes barrier/effect: conditional rejection.
- Older generation before newer barrier: temporary stale effect remains possible;
  canonical recovery converges. Do not promise PG/Kubernetes atomicity.
- Lease expired while RV unchanged: RV alone is not a lease fence.
- API applies then response is lost: direct observe exact UID/markers/effect,
  current canonical claim/intention check, record satisfaction/supersession or
  conditional retry; never blind replay or automatic PHP writer fallback.
- Duplicate same intent and competing workers/generations across two adapters.
- Resource deletion/recreation/name reuse, concurrent field edits, malformed
  target and authorization/ownership mismatch, timeout and API outage.
- Rollback with in-flight requests cannot reactivate an unrestricted PHP writer.

Create/delete/name-reuse extension deliverable
Specify negotiated closed schemas for fixed create_suspended/projection/delete
effects, canonical target lifecycle, unique never-reused physical incarnation
names, create-if-absent/409 inspection, one-way UID binding, conditional UID/RV
delete preconditions and unknown create/delete recovery. Address all provision,
converge, fence, restore and deprovision application paths plus platform tools.
Do not change normative v1 silently or authorize these lifecycle effects yet.
GN-G3 cannot cut over only scaling while leaving another PHP application writer.

Real API-server proof
Ask for authorization only if no already-authorized isolated API-server target
exists. Do useful implementation/unit/protocol work independently. Record actual
server/client versions and prove real JSON Patch UID/RV tests, barriers, update
conflicts, duplicate effects, delayed competing requests and lost responses.
Fake clients do not prove admission/defaulting or resourceVersion enforcement.
If unavailable, mark the real-server gate explicitly deferred and do not claim
GN-G2 ready for writer cutover. No live production mutation is permitted.

Validation
Run pinned go test -count=1 ./..., go test -race -count=1 ./..., go vet ./...,
go mod verify, schemas/positive-negative fixtures, GN-G1 observer and mTLS tests,
focused existing PHP and new claim/error-parity tests, real API-server suite,
repository hygiene, phase-status consistency, documentation links/whitespace,
runtime-engine-config-check and git diff --check. Classify baseline failures
separately; never weaken existing assertions to report a pass.

Deliverables and acceptance
- Bounded module source, versioned contract extension proposal, deterministic
  tests/fixtures and one-command pinned verifier.
- GN-G2 report with actual commands/results, source/units/fakes/HTTP simulation/
  real-server/deployed proof classifications, safety limitations, ownership and
  retry guarantees, unknown-outcome recovery, and exact remaining gaps.
- GN-G3 proposal covering every application Deployment writer, credential-level
  old-writer cutoff, in-flight draining/recovery, pending operations and compatible
  conditional rollback. Do not execute cutover.
- Preserve existing work and accepted authority; no production binding or mutation.
  GN-G2 effect acceptance requires real API-server conditional-write evidence;
  deployed/natural-live acceptance and GN-G3 remain separate.
```
