# GN-G3 assignment — complete application Deployment writer transfer

**PROPOSAL ONLY / NOT AUTHORIZED BY GN-G2.** Lifecycle implementation and real
API-server proof are prerequisites, not work silently included in a cutover.
Copy the following prompt only after the operator supplies the stated authority.

```text
# UTCP — GN-G3: Exclusive Application Deployment Writer Cutover

Assignment: UTCP_GN_G3_EXCLUSIVE_KUBERNETES_DEPLOYMENT_WRITER_CUTOVER
Recommended worker: OpenAI Codex CLI
Model: GPT-6; reasoning: High
Role: Principal Kubernetes Control Plane Engineer / Distributed Systems Engineer
Execution role: IMPLEMENTER with separately authorized controlled acceptance

Objective
Transfer every application-managed Deployment mutation corridor to one bounded
Go executor while Laravel/PostgreSQL retain tenant/domain authority, durable
operations, target lifecycle, claims/fencing, desired intent, retries and audit.
Do not cut over only scaling. Do not introduce another reconciliation authority.

Required tools
Git/ripgrep, repository instructions and phase ledger, pinned Go SDK and
client-go, Go unit/race/vet/module checks, existing PHP 8.4 PHPUnit image,
JSON Schema parity tooling, Docker, and explicitly authorized Kubernetes test
and deployment credentials. Use official Kubernetes API documentation to verify
conditional semantics. No host packages, new cluster or implicit live target.

Authority and hard prerequisites
Read AGENTS.md/path instructions, current phase-status ledger, accepted ADRs,
GN-G0 contracts, GN-G1/GN-G2 reports and source, lifecycle-v2-proposal.md and
real-api-verification.md. Record current branch/HEAD/diffs/untracked artifacts
and preserve all unrelated work. ADR adoption and deployment/cutover require
explicit operator authorization; a prompt copied without that authorization is
not permission to modify the canonical environment.

Before implementing cutover, establish all of these gates:
1. Operator-adopted adapter ADR and negotiated executable lifecycle contract.
2. Implemented and independently tested create_suspended, projection convergence
   and conditional deletion, canonical preparing/bound/retiring/retired state,
   terminal tombstones, never-reused incarnation names and one-way UID binding.
3. Real API-server GN-G2 UID/RV/barrier/concurrency/status/unknown-outcome proof.
4. Real lifecycle API-server race/delete/finalizer/name-reuse/late-create proof,
   plus demonstrated inert suspended templates and exclusive activation.
5. Existing runtime-engine-config-check inconsistency separately repaired or
   explicitly dispositioned with accepted positive and negative regression proof.
6. Explicit environment, namespace, target set, rollback identity and evidence
   retention authorization. Report these before any live mutation.
If a gate is absent, complete a precise prerequisite audit and stop before
cutover. Do not broaden GN-G3 into an unreviewed lifecycle implementation.

Scope and inventory
Trace all callers of HttpKubernetesWorkloadClient and all direct shell/API/SDK
Deployment writers. Transfer provision, workload.converge, runtime.fence,
runtime.restore and deprovision for both managed telephony providers. Preserve
drain/maintenance/restoration, owned-Pod/readiness, active-work, listener epoch,
provider execution and canonical operation completion guards in Laravel.
Classify platform manifests, Helm/Kustomize, image rollout and operator recovery
tools separately; they must obey explicit target exclusions/quiescence and cannot
remain an unrestricted competing writer to application-managed fields.

Implementation boundaries
- Go receives full authorized bounded intents; no PostgreSQL/Redis credentials,
  generation allocation, tenant policy, autonomous controller, CRD or raw proxy.
- Laravel canonical target serialization orders projection, fence, restore and
  retirement under one generation sequence. Convergence cannot restore replicas
  while a canonical fence or retirement hold persists.
- Full-action fresh claim verification precedes each external dispatch. Retain
  UID/RV CAS, admitted/applied marker checks and no mutation replay on transport
  uncertainty. Physical identity never follows name reuse or matching labels.
- Add narrow production transport/binding only as explicitly authorized. Retain
  distinct observer/executor mTLS identities, least-privilege ServiceAccounts,
  namespace scoping and redacted bounded responses.

Initial legacy writer transfer protocol
1. Inventory every application credential, Role/ClusterRoleBinding and alternate
   route granting Deployment create/update/patch/delete, including subresources,
   impersonation and inherited wildcard rights. Snapshot sanitized authority.
2. Quiesce all five old mutation corridors and every legacy retry/pending source;
   persist pending canonical intent without dispatch. Drain current operations,
   classify unknown receipts and maintain telephony eligibility holds.
3. Remove old PHP effective write authority at credential/RBAC level. Preserve
   only separately justified observation rights. A feature flag is insufficient.
4. Prove effective cutoff through SubjectAccessReview/impersonated can-i checks
   and actual safe negative write attempts using the old identity for parent
   Deployment and scale paths. Verify no inherited role or alternate token still
   grants writes. Do not expose credential material in evidence.
5. Credential cutoff does not retract requests admitted earlier. Require complete
   server audit correlation from RequestReceived to proven terminal handling for
   every accepted legacy attempt, including audit continuity/no-dropped events,
   panic/cancellation cases and verified synchronous handler semantics. Client
   exit, timeout, current GET or ResponseStarted alone is not terminal proof.
6. If complete server-confirmed legacy drain is unavailable, prohibit same-
   incarnation first cutover. Stop for a separately reviewed isolation/replacement
   design that contains old create-at-one activation as well as old PATCH/DELETE;
   renaming new Deployments or setting replicas zero is not sufficient.
7. Observe final exact identities, marker and child state; resolve canonical
   uncertainty, allocate a new execution generation, admit the conditional
   baseline, then enable the exclusive Go identity for reviewed targets.
8. Resume pending operations through current claims and conditional intents.
   Preserve business idempotency identity while allocating a distinct execution
   generation/key where authority handoff requires it. No automatic PHP fallback.

Rollback
Prepare a reviewed conditional-compatible rollback build and identity; the old
unconditional PHP writer is not a rollback target. Quiesce the active conditional
writer, revoke its effective rights, classify in-flight requests and canonical
unknowns, allocate a newer execution-generation barrier and activate only one
conditional-compatible writer. Older conditional requests then fail CAS/barrier
checks after the newer barrier. A request arriving before the barrier can still
temporarily apply; preserve canonical holds and converge. Do not promise atomic
PostgreSQL/Kubernetes rollback or instantaneous lease revocation.

Validation and acceptance
Run complete GN-G1/GN-G2 and lifecycle Go/PHP/schema/mTLS/security suites, race,
vet, module integrity, formatting, repository hygiene, documentation validation,
phase-status consistency and git diff --check. Prove all five application
corridors, effective old credential denial, absence of dual writers, unknown-
outcome recovery, stale/delayed writes and compatible rollback against a real
authorized API server. Then execute separately authorized bounded deployment and
natural-live acceptance preserving existing V1/K5 telephony behavior. API success
alone is not runtime readiness or telephony completion. Do not weaken tests or
hide independent baseline failures.

Deliverables
Production changes only within the adopted/authorized contract, exact caller and
credential inventory, executable cutover/rollback runbook, independent security
review, sanitized API and live evidence, complete changed-file inventory and
formal verdict with implemented/tested/API-server/deployed/natural-live proof
separated. Reconcile phase-status.md only when supported exit criteria change
canonical state; preserve RMA-E next action unless expressly authorized otherwise.
Do not stage, commit or push without explicit authorization. Report material
prerequisites and the smallest next action if any gate prevents completion.
```
