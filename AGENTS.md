# Unified Telephony Control Plane — Agent Instructions

## Mission

Build UTCP as a vendor-neutral telephony control plane that applications such as dialers, contact centers, IVR systems, conferencing systems, and voice automation platforms can build on.

UTCP owns:

* Canonical desired state
* Tenant and operator policy
* Normalized runtime contracts
* Reconciliation decisions
* Lifecycle coordination
* Health history
* Auditability
* Operational visibility

UTCP does not replace the live protocol or infrastructure authority of Kamailio, rtpengine, Asterisk, FreeSWITCH, Kubernetes, PostgreSQL, Redis, or Traefik.

The normal operator experience must remain:

```text
Web Admin UI
→ authorized API
→ canonical domain services
→ desired-state persistence
→ automatic reconciliation and projection
→ runtime adapters and infrastructure
→ observed state and operational evidence
```

Operators and application users must not need to understand Kubernetes, PBX internals, Redis, direct SQL, or vendor-specific configuration to perform normal management.

---

## Core engineering standard

Use an evidence-first but implementation-oriented approach.

The goal is not to eliminate every theoretical risk before editing. The goal is to obtain the minimum sufficient evidence needed to make a bounded, deterministic change.

Proceed to implementation when the repository establishes:

1. The canonical authority
2. The demonstrated or structurally proven defect
3. The expected behavior
4. The bounded implementation location
5. Testable acceptance criteria

Do not require another audit merely because:

* Every theoretical edge case has not been explored
* Live proof is still pending
* Optional metrics or dashboards are not designed
* A timeout may later require tuning
* Historical repair can be implemented separately
* A reversible implementation may require a later correction

Use an evidence-only audit only when a material question remains unresolved, such as:

* Which component owns the behavior
* Whether the reported failure is real
* What a failing test or runtime result means
* Which code path must change
* Whether two authorities conflict
* What successful behavior should be

Prefer implementation once those questions are answered.

---

## Deterministic defaults

When repository architecture and existing contracts support a safe default, apply it without asking for unnecessary operator decisions.

Preferred defaults include:

```text
normal management authority
→ Web Admin UI and authorized API

business state
→ PostgreSQL

runtime retry ownership
→ persisted operation lifecycle

runtime reconciliation
→ automatic and idempotent

unknown runtime health
→ unavailable or degraded, not absent

duplicate mutation
→ deterministic reuse or idempotent success

ambiguous historical binding
→ most recent authoritative binding

runtime placement
→ eligible ready target using deterministic ordering

resource retirement
→ retain history and retire state, not hard delete

notifications
→ non-authoritative signals backed by canonical persisted state

runtime failure recovery
→ automatic reconciliation before manual intervention
```

Reserve operator decisions for genuine:

* Business-policy choices
* Security-policy choices
* Credential or secret handling
* Production-impacting actions
* Irreversible data changes
* External-provider or customer-impacting actions

Do not escalate ordinary architectural defaults into operator approval requirements.

---

## Reversible and irreversible work

Treat reversible local work differently from irreversible or externally consequential work.

### Reversible repository or local-runtime work

Examples include:

* Adding or correcting a reconciler transition
* Refactoring an adapter
* Changing an internal listener
* Adding metrics or alerts
* Changing a retry classification
* Restarting a local Pod
* Creating a disposable resource inside the repository-authorized local proof environment
* Temporarily applying a reversible local failure condition
* Deploying a local image and rolling it back

Proceed once the authority, behavior, and acceptance criteria are clear.

Reversible does not mean automatically authorized. A local mutation must still remain inside the task's declared scope, repository-defined topology, canonical lifecycle, and preservation requirements. Creating or replacing a cluster, registry, network, database, persistent volume, host-port mapping, certificate authority, or alternate deployment path is an environment-topology change and is governed by the rules below.

### Irreversible or externally consequential work

Examples include:

* Destructive production migrations
* Deleting canonical business history
* Revoking real customer credentials
* Terminating real calls
* Changing public DNS
* Modifying external trunks
* Weakening public security controls
* Affecting production traffic or customer identity

Require stronger evidence and explicit operator authorization when the task cannot be safely derived from repository contracts.

Do not treat every local proof as if it were an irreversible production change.

---

## Working method

* Inspect the repository and applicable documentation before editing.
* Inspect `git status --short` before changing files.
* Work on one bounded phase, subphase, or defect per task.
* Prefer the smallest coherent implementation that satisfies the requested contract.
* Preserve valid existing work and user-authored changes.
* Do not implement unrelated future roadmap work.
* Use repository architecture and current contracts to resolve ordinary implementation choices.
* Prefer real implementation and focused validation over repeated broad audits.
* Keep proof requirements proportional to the claim being made.
* Classify divergences by actual impact rather than treating every divergence as task failure.
* Do not add speculative abstraction, compatibility layers, or operational controls without a demonstrated need.

A bounded task may include implementation, focused tests, documentation, and one scoped commit when explicitly requested.

---

## No improvised environments or alternate lifecycle

When a repository-backed command, environment, or proof path is blocked, diagnose the blocker. Do not invent a new environment or bypass the canonical lifecycle merely to continue the task.

Without explicit authorization in the current task and repository evidence supporting the target, do not:

* Create a new Kubernetes or k3d cluster, registry, namespace topology, Compose project, network, or parallel runtime environment
* Stop the canonical cluster and substitute a differently named cluster
* Recreate, delete, or replace an existing cluster, registry, volume, database, or other stateful environment
* Add alternate host ports, custom-port fallbacks, proxy paths, or hostname mappings
* Deploy committed manifests directly when the canonical deployment target or verifier rejects them
* Bypass repository preflight, configuration checks, Make targets, scripts, overlays, or safety assertions
* Patch live Kubernetes objects or containers as a substitute for changing the canonical repository source
* Use a temporary proof topology that changes the architecture being proved
* Convert a diagnostic workaround into an undocumented deployment or management path

The absence of a required capability in the canonical environment is evidence of a repository or environment gap. It is not permission to create a parallel solution.

### Required response to a blocked canonical path

When the canonical path cannot proceed:

1. Stop before making an alternate topology or lifecycle mutation.
2. Preserve the current environment unless an already-authorized recovery action is documented and in scope.
3. Identify the exact failed command, rejected invariant, missing capability, and affected acceptance criterion.
4. Determine whether the repository already defines one deterministic recovery or rebuild path.
5. If that path is safe, reversible, explicitly in scope, and preserves required state, use only that path.
6. Otherwise report the blocker and the smallest repository correction or operator decision required.

Do not interpret broad instructions such as “complete the proof,” “recover the environment,” “use best judgment,” “fix blockers,” or “prioritize deterministic outcomes” as authorization to create a new cluster, bypass checks, delete state, or change topology.

### Canonical-environment preference

Prefer repairing or reproducibly rebuilding the canonical environment over creating a second near-duplicate environment.

Before any Kubernetes, runtime, ingress, host-networking, or live-proof
mutation:

1. Read `docs/roadmap/phase-status.md`, the applicable topology ADR, and the
   applicable active-phase runbook.
2. Resolve exactly one canonical environment, including environment type,
   cluster/context, node, edge address, and active hostname ownership.
3. The current active authority is native k3s / `utcp-dev01` unless a later
   current roadmap entry and accepted ADR explicitly change it.
4. Historical runbooks and evidence do not automatically reactivate their
   topology.
5. k3d / `utcp-local` must be explicitly selected by the task before it can be
   used for mutation or proof.
6. Do not create, recreate, or switch to k3d merely because native topology
   has a blocker. A topology transition requires an explicit current decision.

Before mutation, report:

```text
CANONICAL_ENVIRONMENT=native-k3s
CANONICAL_CONTEXT=<resolved native context>
CANONICAL_NODE=utcp-dev01
CANONICAL_EDGE_ADDRESS=192.168.254.124
```

The canonical environment is selected only by current phase-status, an
applicable accepted topology ADR, and the current executable roadmap/runbook;
historical proof does not select deployment topology.

For local UTCP development, a missing immutable k3d cluster capability, such
as a required host-port publication, should normally be addressed in the
explicitly selected k3d cluster-creation configuration and verified through
the repository lifecycle.
* Rebuilding is permitted only when the current task explicitly authorizes it, repository evidence identifies the exact desired configuration, and preflight confirms that required state is reproducible or safely preserved.
* A second cluster is permitted only when the architecture intentionally requires multiple simultaneous clusters and that topology, ownership, ports, lifecycle, and cleanup are documented in the repository before creation.
* A temporary cluster must never silently become the canonical deployment target.

### Repository checks are architectural constraints

If a canonical verifier rejects a proposed topology or deployment path, treat that result as a blocking contract mismatch until repository evidence proves the verifier is stale or incorrect.

Do not work around the rejection by invoking lower-level tools or applying manifests directly. Correct the canonical source, configuration, and verifier together when the intended architecture is established; otherwise stop and report the conflict.

### Mutation preflight

Before any authorized cluster rebuild, environment replacement, or stateful local-runtime mutation, record:

* The canonical resource being changed
* Why the current resource cannot satisfy the repository contract
* The exact repository-backed target configuration and command
* Existing state that may be lost, including volumes, secrets, generated certificates, database contents, evidence, and locally built images
* What is reproducible, what must be preserved, and how recovery will be verified
* Host-port, cluster-name, registry, context, namespace, and neighboring-project conflicts
* The rollback or recreation path

If any potentially irreplaceable state or target identity remains unclear, stop and report the proof gap. Do not create an alternate environment to avoid resolving it.

### Scope-change stop condition

Stop and report before proceeding when new evidence requires a material change to any of these:

* Cluster or environment topology
* Canonical cluster, context, namespace, registry, or deployment target
* Host port or hostname ownership
* Persistence or data-retention behavior
* Security boundary or public exposure
* Management or runtime authority
* Repository deployment lifecycle
* Explicit non-goals or preservation requirements

The report must state the discovered fact, why the original path is blocked, the smallest deterministic correction, its impact, and whether operator authorization is required. Do not implement the scope change in the same run unless the current task already explicitly authorizes that exact class of change.

---

## Architecture authority boundaries

### UTCP

Owns:

* Business policy
* Desired state
* Lifecycle decisions
* Reconciliation decisions
* Placement policy
* Normalized runtime contracts
* Audit history
* Operator-facing management workflows

### PostgreSQL

Owns canonical persisted business records and durable lifecycle evidence.

### Redis

May provide:

* Queues
* Locks
* Caching
* Rate limiting
* Ephemeral coordination
* Transient projections

Redis must not become the sole authority for canonical business state.

### Kubernetes

Owns workload placement, container orchestration, restart behavior, and declared infrastructure resources.

Kubernetes state must not replace canonical telephony or business state.

### Traefik

Owns HTTP, HTTPS, and application WebSocket ingress.

### Kamailio

Owns live SIP signaling execution and signaling-edge behavior.

### rtpengine

Owns RTP and SRTP media relay execution.

### Asterisk and FreeSWITCH

Own live telephony application and call execution behavior behind UTCP runtime-adapter contracts.

### WebSocket and event streams

WebSocket messages and runtime events are observations and notifications. They are not canonical business state by themselves.

No implementation may silently transfer authority from one component to another.

---

## Management authority

Web Admin UI and authorized APIs are the normal management authority.

CLI and Artisan commands are limited to:

* Initial bootstrap
* Diagnostics
* Break-glass recovery
* Exceptional maintenance
* Migrations
* Verification
* Thin scheduler entrypoints

Do not create CLI commands that become a second management UI.

Do not require operators to routinely:

* Run reconciliation manually
* Trigger projections manually
* Select RuntimeNode IDs
* Select PBX channel IDs
* Edit PostgreSQL
* Edit Redis
* Apply Kubernetes resources
* Run Asterisk or FreeSWITCH commands
* Enable hidden runtime switches

New functionality should become active through the normal canonical lifecycle once configured through the authoritative management surface.

---

## Prohibited operational friction

Do not introduce any of the following unless an existing documented security or deployment requirement specifically requires it:

* New environment-variable feature gates
* Manual enablement switches
* Runtime allowlists
* Hidden opt-in paths
* Extra operator approval steps
* Manual reconciliation triggers for normal operation
* Manual projection triggers
* Diagnostic commands required for routine use
* Multiple runtime mutation authorities
* Public fallbacks that preserve behavior meant to be removed
* Compatibility branches for unsupported legacy behavior
* Silent fallback to another RuntimeNode or provider
* Hard-coded repair identifiers
* Prefix-wide or age-only destructive cleanup

When conflicting legacy behavior is proven obsolete, remove or replace its runtime authority. Do not leave it active behind a fallback, gate, or alternate path unless a documented compatibility requirement demands it.

---

## Vendor neutrality

Keep vendor-specific telephony behavior behind adapter contracts.

The core telephony domain must not assume:

* Asterisk-only identifiers
* FreeSWITCH-only commands
* Kubernetes-only deployment
* One fixed registrar
* One fixed media relay
* One fixed provider-node topology

A runtime may be deployed through Kubernetes, Docker, a virtual machine, bare metal, or a simulator.

Kubernetes concepts must not leak into canonical business and telephony-domain contracts unless the domain is explicitly managing infrastructure placement.

Vendor neutrality does not require speculative abstraction. Add abstractions only when at least two real implementations or an established architecture contract justify them.

---

## Clean-room requirement

Do not copy code, schemas, prompts, documentation, tests, fixtures, names, configuration, or implementation details from APNTalk or any employer or client repository.

UTCP must remain a clean-room implementation derived from public knowledge, original design, and its own repository evidence.

---

## Audit versus implementation decision

### Choose evidence-only audit when

A material issue is unresolved:

* Root cause is unclear
* Authority boundary is unclear
* Failing-test meaning is unclear
* Runtime behavior is unclear
* The proposed change depends on an unverified assumption
* The correct success condition is unknown
* A live proof is needed to identify the implementation target

The audit must be narrow and directed toward an implementation decision.

### Choose bounded implementation when

The repository already identifies:

* The authority
* The defect or missing behavior
* The expected result
* The implementation seam
* The acceptance tests

Do not repeat a broad audit after those facts are established.

### Choose live proof when

Repository implementation is complete and the remaining claim concerns:

* Runtime behavior
* Deployment behavior
* Failover or recovery
* External protocol behavior
* Prometheus scraping
* Kubernetes policy
* Browser or UI interaction

Live proof should validate only the material runtime claim.

---

## Proof discipline

Use proof proportional to the claim.

### Repository proof may establish

* Code paths
* Configuration
* Static authority boundaries
* Unit and integration behavior
* Manifest rendering
* Absence of conflicting implementation
* Focused regression coverage

### Live proof is required to establish

* Actual runtime behavior
* Protocol behavior
* Deployment rollout behavior
* Kubernetes ownership or readiness behavior
* PBX behavior
* Browser behavior
* Prometheus scrape and alert evaluation
* Real automatic recovery

Do not claim live proof from static inspection.

Do not require browser proof for repository-only work unless the task changes a user-facing workflow that cannot be sufficiently validated through focused tests.

When browser proof is required, use Playwright through the real login page and normal authentication flow. Do not inject sessions, cookies, Redis state, or authentication bypasses.

---

## Divergence classification

Do not automatically fail a task because execution differs from the ideal path.

This flexibility applies to observed results and harmless execution details. It does not authorize changing the declared topology, canonical target, lifecycle, authority boundary, public exposure, or preservation requirements. Those are scope changes and must follow the stop condition above.

Classify each divergence as one of:

* Blocking correctness defect
* Security defect
* Authority conflict
* Observability gap
* Historical residue
* Expected rollout behavior
* Environmental issue
* Unrelated pre-existing condition
* Harmless timing difference

Only a divergence that invalidates the task’s principal claim should normally block completion.

The following normally invalidate a live-proof claim until explicitly resolved:

* Proof executed in an undocumented replacement environment
* Canonical deployment validation bypassed because it rejected the proof topology
* A different cluster, registry, context, namespace, port publication, or overlay used without prior authorization
* Direct manifest application or live patching used instead of the canonical deployment lifecycle
* A proof environment that materially differs from the environment named by the acceptance criteria

Examples:

* A temporary scrape reload window does not invalidate a successful secure metrics cutover.
* An old worker completing an idempotent operation during rollout does not invalidate forward correctness.
* Historical rows do not invalidate a current invariant when explicitly classified.
* A missing optional event does not invalidate canonical runtime cleanup when the forward event path is independently proven.

Document meaningful divergences without expanding the task into unrelated repair work.

---

## Implementation discipline

* Use one canonical write authority for each state transition.
* Remove conflicting runtime authority once replacement is proven.
* Reuse existing domain services and operation lifecycles.
* Prefer persisted retries over hidden in-process loops.
* Prefer explicit degraded or unavailable states over silent fallback.
* Revalidate authority before runtime mutation.
* Preserve tenant isolation.
* Preserve stale-generation and wrong-node guards.
* Make recovery idempotent.
* Keep discovery separate from mutation when the architecture already distinguishes them.
* Do not hard-delete lifecycle evidence merely to make an invariant pass.
* Do not redesign an adjacent subsystem unless the bounded task requires it.

---

## Verification

* Add or update tests for changed behavior.
* Run the smallest relevant tests first.
* Run broader phase-required checks after focused tests pass.
* Report exact commands and outcomes.
* Do not claim success for commands that were not run.
* Do not hide intermediate failures; report whether they were corrected.
* Record material unresolved proof gaps.
* Do not convert optional follow-up work into a blocker.
* Keep verification proportional to the implementation.
* Verify the intended cluster, context, namespace, registry, overlay, host ports, and deployment target before runtime mutation.
* Use explicit `--context` and namespace selection for Kubernetes inspection and proof commands when the global context may point elsewhere.
* Treat canonical preflight and deployment checks as required evidence, not optional conveniences.
* If a required check rejects the intended path, stop or correct the repository contract; do not continue through a lower-level bypass.

A normal bounded implementation should usually prove:

1. Canonical authority is used.
2. Conflicting behavior is removed or cut off.
3. The new behavior is automatic and idempotent.
4. Focused regression tests pass.
5. Required broad tests pass.
6. No second management authority is introduced.
7. Documentation is updated.
8. Repository state is clean.

Do not create twenty or thirty completion conditions when a smaller set proves the contract.

---

## Evidence handling

Store only concise and sanitized evidence.

Never commit:

* Credentials
* Tokens
* Private keys
* Customer information
* Real telephone identities
* Private production hostnames
* Complete noisy logs
* Machine-specific secrets
* Raw database dumps
* Unredacted protocol captures containing sensitive identity

Evidence should contain only what is needed to support the claim.

Do not duplicate large report sections unnecessarily.

---

## Git safety

* Do not reset, clean, force-push, rewrite history, delete branches, or discard unrelated changes unless explicitly instructed.
* Do not commit or push unless the task explicitly requests it.
* Keep generated files and local secrets untracked.
* Before editing, inspect `git status --short` and account for pre-existing changes.
* Do not overwrite user-authored work.
* Do not amend an earlier evidence or implementation commit unless explicitly instructed.

### End-of-prompt commit convention

When a bounded prompt explicitly requests a commit:

1. Run the required focused verification.
2. Run the required broader checks.
3. Review `git status` and the staged diff.
4. Exclude generated runtime files, secrets, logs, and unrelated changes.
5. Create one scoped commit for the completed task.
6. Report the commit hash, message, and changed files.
7. Do not push unless explicitly requested.

### Incomplete runs

Do not commit broken, unsafe, or misleading work.

A coherent partial checkpoint may be committed only when:

* The task explicitly permits it
* The partial state is safe
* The commit message clearly identifies it as incomplete or blocked
* The final report states exactly what remains

Do not create a WIP commit merely because time or context is running short.

---

## Subagent coordination

* Use parallel subagents primarily for read-heavy inspection, focused review, test planning, or independent verification.
* Use one primary write owner for each bounded implementation.
* Do not allow multiple write agents to modify overlapping files concurrently.
* Give subagents narrow questions rather than broad repository missions.
* Require concise conclusions, relevant file references, and material evidence.
* Do not include raw command noise when a summarized result is sufficient.
* The primary agent remains responsible for reconciling conflicting subagent conclusions.

---

## Phase discipline

Every implementation task must identify:

* Phase or subphase
* Objective
* In-scope deliverables
* Explicit non-goals
* Authority constraints
* Required verification
* Completion criteria

A phase or subphase is complete when its material exit criteria are proven.

Do not hold a phase open because:

* Optional dashboards remain
* Low-priority hardening remains
* An unrelated roadmap item is incomplete
* Historical evidence could be expanded
* Every theoretical failure has not been simulated

Separate:

```text
phase-blocking correctness or security gaps
```

from:

```text
deferred observability, usability, optimization, or hardening
```

Proceed when the phase’s intended contract is satisfied.

---

## Operator Required Before Next Prompt

List only manual actions the human operator must complete before the next task can safely and usefully run.

Include an action only when all are true:

1. It is directly required by the selected next task.
2. The agent cannot reasonably complete it with repository or available runtime tooling.
3. The requirement is supported by current evidence.
4. Without it, the next task would be blocked, unsafe, or invalid.

Do not list:

* Generic warnings
* Permission to continue
* Optional preparation
* Commands that belong inside the next task
* Architecture decisions derivable from repository evidence
* Manual gates, allowlists, or switches
* Services or credentials that are not actually required
* “Do not” instructions

When nothing is required, write:

```text
None.
```

---

## Required final report

End bounded implementation, live-proof, and evidence-audit tasks with:

## Verdict

Use one precise machine-readable verdict, such as:

```text
PHASE_F0_COMPLETE
PHASE_F0_INCOMPLETE
T5_LISTENER_LIVENESS_IMPLEMENTATION_COMPLETE
T5_LISTENER_LIVENESS_LIVE_PROOF_COMPLETE
EVIDENCE_AUDIT_COMPLETE
BLOCKED
```

## Starting Commit

## Current Repository State

Include branch, HEAD, working-tree state, phase marker, and push status.

## Current State

## Implemented or Confirmed

## Authority Boundary

State the canonical owner and any conflicting authority removed.

## Files Changed

For evidence-only work, list only the evidence file.

## Verification Performed

List exact commands and material live checks.

## Tests Passed

## Tests Failed

State `None.` when no final failure remains. Mention corrected intermediate failures separately when relevant.

## Divergences

Classify only meaningful divergences and state whether they invalidate the principal claim.

## Unresolved Proof Gaps

List only material gaps required for the next task or final acceptance.

## Deferred Work

Keep non-blocking roadmap items separate from proof gaps.

## Commit Created

Include hash and subject when a commit was requested.

## Push Status

Write:

```text
not pushed
```

unless explicitly instructed otherwise.

## Remaining Uncommitted Files

Write:

```text
None.
```

or list and justify every path.

## Existing Environment Preservation

State what was changed and what remained untouched. Include every cluster, registry, Compose project, database, volume, host-port binding, or other environment resource created, started, stopped, rebuilt, deleted, or bypassed. Do not repeat generic prohibition lists.

## Improvisations and Scope Changes

Write:

```text
None.
```

or list every deviation from the repository-defined command, topology, target environment, or lifecycle. For each deviation, state whether it was explicitly authorized, the repository evidence supporting it, and its effect on the principal claim. An unreported improvisation is a task failure.

## Recommended Next Step

Choose the next step according to evidence:

* bounded implementation
* narrow evidence audit
* controlled live proof
* next roadmap phase

## Operator Required Before Next Prompt

Write only genuine human prerequisites or:

```text
None.
```
