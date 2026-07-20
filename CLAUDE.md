# CLAUDE.md

This file provides Claude Code–specific guidance for working in the Unified Telephony Control Plane repository.

`AGENTS.md` is the binding repository-wide working contract. Read it before this file. When instructions conflict, follow this precedence:

1. The current user task
2. `AGENTS.md`
3. Applicable ADRs, roadmap contracts, and phase evidence
4. This `CLAUDE.md`
5. General repository conventions

This file complements `AGENTS.md`; it does not replace it.

---

## Mission

Build UTCP as a vendor-neutral telephony control plane that dialers, contact centers, conferencing systems, IVR systems, and voice automation applications can build on.

UTCP owns:

* Canonical desired state
* Tenant and operator policy
* Identity and access authority
* Normalized runtime contracts
* Runtime registration
* Reconciliation decisions
* Lifecycle coordination
* Health history
* Auditability
* Operational visibility

UTCP does not replace the live protocol or infrastructure authority of:

* Kamailio
* rtpengine
* Asterisk
* FreeSWITCH
* Kubernetes
* PostgreSQL
* Redis
* Traefik

The intended management flow is:

```text
Web Admin UI
→ authorized API
→ canonical domain services
→ PostgreSQL desired state
→ automatic reconciliation and projection
→ runtime adapters and infrastructure
→ observed state, audit, metrics, and alerts
```

Normal operators must not need to understand Kubernetes, PBX internals, direct SQL, Redis, or vendor-specific configuration to manage UTCP resources.

---

## Determine repository state dynamically

Do not rely on a large static completion summary in this file. It will become stale.

At the beginning of every task, inspect the actual repository:

```bash
git status --short
git log -8 --oneline --decorate
grep -n '^UTCP_PHASE=' versions.env
```

Then read the relevant current-state documents:

```text
docs/roadmap/phase-status.md
docs/unified-telephony-control-plane-initial-implementation-plan.md
relevant ADRs
relevant evidence documents
```

Treat these as separate facts:

```text
phase marker
roadmap phase status
implemented repository behavior
live-proven runtime behavior
deferred work
```

Do not infer one from another.

For example:

* Code may exist without live proof.
* A live proof may expose a bounded follow-up without invalidating the entire phase.
* A phase marker may remain unchanged while bounded hardening work occurs.
* An evidence document may describe historical behavior that has since been replaced.

Report inconsistencies when they materially affect the task. Do not launch a broad audit merely because documents use different levels of detail.

---

## Governing documents

Read only the documents relevant to the current task, but always begin with `AGENTS.md`.

### Required

* **`AGENTS.md`**
  Binding working method, authority boundaries, audit-versus-implementation standard, proof discipline, git safety, and final-report contract.

* **`docs/unified-telephony-control-plane-initial-implementation-plan.md`**
  Product boundary, roadmap, phase dependencies, deliverables, and exit criteria.

* **`docs/roadmap/phase-status.md`**
  Current phase status and explicitly retained work.

### As applicable

* **`docs/decisions/`**
  Architecture decisions and authority contracts.

* **`docs/evidence/`**
  Repository and live-runtime proof.

* **`docs/unified-telephony-control-plane-codex-blueprint.md`**
  Agent, skill, and execution scaffolding rationale. The architecture rules apply to Claude Code even when the file discusses Codex-specific structure.

Do not read every ADR or evidence file before every bounded change. Read the documents that govern the affected authority and lifecycle.

---

## Evidence-first, implementation-oriented standard

Use the minimum sufficient evidence needed to make a deterministic bounded change.

Proceed to implementation when the repository establishes:

1. The canonical authority
2. The demonstrated or structurally proven defect
3. The expected behavior
4. The bounded implementation seam
5. Testable acceptance criteria

Do not require another audit merely because:

* Every theoretical edge case has not been explored
* A later live proof is still required
* Optional metrics or dashboards remain undefined
* A timeout may require later tuning
* A historical repair path can be handled separately
* A reversible implementation may require one later bounded correction
* An unrelated roadmap item remains incomplete

The purpose of evidence is to enable implementation, not postpone it indefinitely.

---

## Choosing the task mode

Every task must be classified as one of:

```text
bounded implementation
narrow evidence audit
controlled live proof
targeted blocker diagnosis
```

### Choose bounded implementation when

The repository already identifies:

* The owning component
* The conflicting or missing behavior
* The expected replacement behavior
* The files or code seam involved
* Focused acceptance tests

Do not repeat a broad repository audit after these are known.

### Choose a narrow evidence audit when

A material issue remains unclear:

* Root cause
* Authority boundary
* Runtime behavior
* Meaning of a failing test
* Meaning of a live result
* Correct implementation seam
* Correct success condition
* Whether two components are competing for authority

An audit must answer a narrow question and end with an implementation-readiness decision.

Do not use “audit” as a default response to ordinary implementation uncertainty.

### Choose controlled live proof when

Repository implementation is complete and the unresolved claim concerns:

* Runtime behavior
* Protocol behavior
* Kubernetes ownership or readiness
* Failover and restoration
* PBX behavior
* Prometheus scrape or alert evaluation
* Browser interaction
* Automatic recovery

Live proof should validate the material claim, not every adjacent subsystem.

### Choose targeted blocker diagnosis when

An implementation or live-proof run is blocked by a concrete failure whose meaning is not yet established.

Isolate that failure. Do not reopen the entire architecture.

---

## Claude Code role

Claude Code is particularly suitable for:

* Narrow evidence audits
* Runtime and log analysis
* Live-proof execution
* Failure and blocker isolation
* Authority tracing
* Repository archaeology
* Controlled deployment verification
* Correlating Kubernetes, database, PBX, and application evidence

Claude Code may perform bounded repository implementation when explicitly assigned and when the implementation target is already clear.

When assigned bounded implementation:

* Do not convert it into another audit.
* Do not widen the task to redesign adjacent systems.
* Implement the smallest coherent correction.
* Add focused regression tests.
* Run the required broad checks.
* Document only material proof gaps.

When assigned evidence work:

* Do not modify production code unless the prompt explicitly permits it.
* Return a decisive implementation-readiness conclusion.
* Avoid long inventories that do not change the decision.

---

## Deterministic defaults

When the repository supports a safe architectural default, apply it without requesting unnecessary operator decisions.

Preferred defaults:

```text
normal management authority
→ Web Admin UI and authorized API

canonical business storage
→ PostgreSQL

queues, locks, cache, transient coordination
→ Redis

retry ownership
→ persisted operation lifecycle

normal recovery
→ automatic reconciliation

unknown runtime health
→ degraded or unavailable, not absent

duplicate mutation
→ deterministic reuse or idempotent success

historical runtime authority
→ most recent valid authoritative record

runtime placement
→ eligible ready target using deterministic ordering

resource removal
→ lifecycle retirement with retained history

event streams
→ observations and notifications, not canonical business state

credentials
→ write-only secret handling with versioned rotation

runtime mutations
→ exact node, generation, tenant, and resource authority
```

Reserve operator decisions for genuine:

* Business-policy choices
* Security-policy choices
* Credential or secret handling
* External-provider consequences
* Production-impacting actions
* Irreversible data changes
* Customer or real-call impact

Do not escalate ordinary implementation details into operator prerequisites.

---

## Reversible versus irreversible work

Do not treat every local proof like a destructive production operation.

### Reversible repository or local-runtime work

Examples:

* Changing a reconciler
* Correcting an adapter
* Adding a state transition
* Changing an internal listener
* Adding metrics and alerts
* Deploying a local image
* Restarting a local Pod
* Creating a disposable proof Conference
* Applying a reversible local failure condition
* Rolling back a local manifest

Proceed when authority, behavior, and acceptance criteria are clear.

### Irreversible or externally consequential work

Examples:

* Destructive production migration
* Hard deletion of canonical records
* Revoking real customer credentials
* Terminating real calls
* Changing public DNS
* Altering external trunks
* Weakening production security
* Affecting customer identities or traffic

Require explicit authorization when repository contracts cannot safely resolve the choice.

---

## Architecture authority boundaries

| Concern                                                                                                                               | Canonical authority                               |
| ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| Business and tenant policy                                                                                                            | UTCP domain services                              |
| Desired state                                                                                                                         | UTCP API and PostgreSQL                           |
| Users, tenants, memberships, roles, capabilities, and account state                                                                   | PostgreSQL through UTCP identity services         |
| RuntimeNode configuration, endpoints, encrypted credential metadata, capabilities, and desired lifecycle state                        | PostgreSQL through UTCP runtime-registry services |
| Runtime operations, leases, fencing, receipts, observations, projection checkpoints, reconciliation state, and durable event evidence | PostgreSQL through UTCP runtime-engine services   |
| Browser authentication                                                                                                                | First-party Laravel sessions                      |
| Persistent business records                                                                                                           | PostgreSQL                                        |
| Queues, locks, caching, and transient projections                                                                                     | Redis                                             |
| UI real-time messages                                                                                                                 | Reverb/WebSockets as notifications only           |
| HTTP, HTTPS, and application WebSocket ingress                                                                                        | Traefik                                           |
| Live SIP signaling                                                                                                                    | Kamailio                                          |
| RTP and SRTP media relay                                                                                                              | rtpengine                                         |
| Telephony application and call execution                                                                                              | Asterisk or FreeSWITCH through adapter contracts  |
| Workload placement and container orchestration                                                                                        | Kubernetes                                        |

No implementation may silently transfer authority from one component to another.

Examples of prohibited authority transfers:

* Redis becoming canonical business storage
* Kubernetes Pod state becoming canonical telephony state
* A WebSocket event becoming canonical state without projection
* Frontend permission checks replacing server authorization
* A CLI command becoming a second management surface
* A runtime adapter selecting another node without control-plane authority
* A discovery sweep directly performing mutations owned by an operation handler

---

## Management authority

Web Admin UI and authorized APIs are the normal management authority.

CLI and Artisan commands are limited to:

* Bootstrap
* Diagnostics
* Break-glass recovery
* Exceptional maintenance
* Migrations
* Verification
* Thin internal scheduler entrypoints

Do not create commands that become a second management UI.

Normal operation must not require operators to:

* Trigger reconciliation manually
* Trigger projection manually
* Select RuntimeNode IDs
* Select PBX channel IDs
* Edit PostgreSQL
* Edit Redis
* Apply Kubernetes manifests
* Run Asterisk or FreeSWITCH CLI commands
* Enable hidden switches
* Maintain runtime allowlists

Once configured through the authoritative management surface, functionality should participate in the normal automatic lifecycle.

---

## Identity and access authority

Identity authority is server-side.

PostgreSQL through UTCP services owns:

* Users
* Tenants
* Memberships
* Built-in roles
* Tenant-defined roles where supported
* Capability assignments
* Account status
* Active-tenant context
* Session authority
* Audit history

The frontend renders server-computed capabilities. It must not become authorization authority.

Do not expand break-glass commands into normal account management.

For example, a password-recovery command may reset one existing account, but must not grow into:

* User creation
* Tenant creation
* Membership assignment
* Role management
* Permission management
* Account activation
* Password disclosure
* Authentication bypass

Users, tenants, memberships, roles, credentials, and SIP identities should eventually have explicit lifecycle management rather than CRUD-only behavior.

---

## Runtime registry authority

Runtime registry authority is server-side.

UTCP stores tenant-owned runtime configuration such as:

* Runtime family
* Adapter key
* Endpoints
* Encrypted write-only credentials
* Declared capabilities
* Adapter configuration
* Desired lifecycle state
* Runtime evidence and history

Backend catalogs own:

* Supported runtime families
* Adapter keys
* Supported capabilities
* Endpoint requirements
* Available adapter configuration

The frontend must not own a checked-in runtime or capability catalog.

Runtime registry services do not infer canonical registry state from Kubernetes Pods.

Observed state changes only through the established runtime-engine projection authority.

---

## Vendor neutrality

Keep vendor-specific behavior behind adapter contracts.

The core telephony domain must not assume:

* Asterisk-only identifiers
* FreeSWITCH-only commands
* Kubernetes-only deployment
* One fixed registrar
* One fixed media relay
* One fixed runtime topology
* One fixed provider-node assignment model

A runtime may be deployed through:

* Kubernetes
* Docker
* A virtual machine
* Bare metal
* A simulator

Vendor neutrality does not justify speculative abstraction.

Add an abstraction when:

* Multiple real implementations require it, or
* An established architecture contract already defines it

Do not create interfaces merely because a future provider might exist.

---

## Core technical decisions

### Modular monolith

UTCP begins as:

* One Laravel API
* One Vue/Vite administration application
* Workers and scheduler from the same backend image

Do not split into microservices without an explicit architecture decision.

### Canonical integrated local runtime

The canonical integrated local runtime is:

```text
utcp-local k3d/Kubernetes cluster
```

Docker Compose remains:

* A disposable compatibility proof
* An isolated debug mode
* A container-build verification path

Docker Compose must not:

* Start automatically as a parallel UTCP runtime
* Share Kubernetes queues or state accidentally
* Become a fallback when Kubernetes is unavailable
* Compete with Kubernetes for runtime authority

### Kubernetes packaging

Use:

* Kustomize for UTCP-owned Kubernetes resources
* Helm for third-party infrastructure where already established

### Telephony runtime neutrality

Prefer runtime-neutral domain concepts such as:

```text
RuntimeNode
RuntimePool
SignalingGateway
MediaRelay
DeploymentTarget
DesiredProjection
ObservedSnapshot
ReconciliationRun
```

Do not encode Kubernetes concepts into canonical telephony models unless the domain explicitly manages infrastructure placement.

### Control-plane kernel

Use the existing control-plane primitives for:

* Operations
* Leases
* Fencing
* Outbox
* Inbox
* Idempotency
* Audit
* Context
* Event envelopes

Do not create parallel lifecycle machinery when an existing primitive can own the behavior.

---

## Phase discipline

Follow the roadmap dependency order for new feature development:

```text
F0 Repository contract
→ F1 Application skeleton
→ F2 Container images
→ F3 Docker Compose
→ F4 CI baseline
→ K0 k3d cluster
→ K1 Kubernetes application base
→ K2 Traefik/Gateway API
→ K3 Security boundaries
→ K4 Observability
→ C0 Control-plane kernel
→ C1 Identity, tenancy, and authorization
→ C2 Runtime registry
→ C3 Command, event, projection, and reconciliation
→ C4 Deterministic simulator
→ C5 Telephony session and conference domain
→ T0 Asterisk ARI
→ T1 Kamailio SIP-over-WSS
→ T2 Asterisk conference execution
→ T3 rtpengine browser media
→ V0 Natural login, SIP registration, and conference admission
→ T4 FreeSWITCH parity
→ T5 Convergence, failover, and recovery
→ R0 Portfolio release
```

Do not infer completion from this list. Read the current phase marker and phase-status document.

Do not use phase order to avoid a bounded correctness or security correction in already-present code.

Do not implement a future product phase merely because adjacent infrastructure exists.

A phase is complete when its material exit criteria are proven, not when every optional dashboard, hardening idea, or theoretical failure scenario is exhausted.

Separate:

```text
phase-blocking correctness or security gap
```

from:

```text
deferred usability, observability, optimization, or hardening
```

---

## Explicit initial-roadmap non-goals

Do not build these prematurely unless the roadmap is explicitly revised:

* Predictive dialing
* Telecom billing
* Lawful intercept
* Full SBC functionality
* Production PSTN trunk integration
* Multi-region production Kubernetes
* Production secrets automation
* Production certificate automation
* Answering-machine detection
* Large-scale campaign scheduling

Do not introduce speculative foundations for these features during unrelated work.

---

## Prohibited operational friction

Do not add any of the following without an existing documented requirement:

* Environment-variable feature gates
* Manual enablement switches
* Runtime allowlists
* Hidden opt-in paths
* Additional approval steps
* Manual reconciliation for normal operation
* Manual projection for normal operation
* Diagnostic commands required for routine management
* Duplicate runtime mutation authorities
* Public fallbacks preserving behavior meant to be removed
* Silent cross-node fallback
* Hard-coded repair identifiers
* Prefix-wide destructive cleanup
* Age-only destructive cleanup
* Defensive compatibility paths for unsupported legacy behavior

When obsolete behavior conflicts with canonical architecture, remove, replace, or cut off its runtime authority.

Do not leave conflicting behavior active behind a fallback or gate unless a documented compatibility requirement explicitly requires it.

---

## Implementation discipline

* Use one canonical write authority for each state transition.
* Reuse existing domain services and operation lifecycles.
* Prefer persisted retries over hidden in-process loops.
* Prefer explicit degraded or unavailable states over silent fallback.
* Revalidate tenant, node, generation, binding, and resource authority before mutation.
* Preserve stale-generation and wrong-node guards.
* Make repeated execution idempotent.
* Keep discovery separate from mutation when the architecture already distinguishes them.
* Preserve lifecycle history instead of hard-deleting it to satisfy an invariant.
* Remove conflicting authority after replacement is proven.
* Do not redesign adjacent systems unless the bounded task requires it.
* Do not add speculative provider-neutral abstractions without evidence.

---

## Proof discipline

Use proof proportional to the claim.

### Repository evidence can prove

* Code paths
* Static authority boundaries
* Configuration
* Manifest rendering
* Unit and integration behavior
* Absence of conflicting implementation
* Focused regression coverage

### Live evidence is required to prove

* Actual runtime behavior
* Protocol behavior
* Kubernetes ownership or readiness
* PBX behavior
* Failover and restoration
* Prometheus scrape and rule evaluation
* Browser behavior
* Automatic recovery under live conditions

Do not claim runtime proof from static inspection.

Do not require live proof for a repository-only implementation claim.

Do not require browser proof unless the task changes or validates a user-facing workflow that focused tests cannot establish.

---

## Browser proof

When browser proof is genuinely required, use Playwright MCP.

The proof must:

1. Begin from the real login page.
2. Authenticate through the normal application flow.
3. Use the tenant and capabilities returned by the application.
4. Exercise the actual user-facing route.
5. Capture material outcomes without exposing credentials.

Do not use:

* Injected cookies
* Preset sessions
* Database-created sessions
* Redis-created sessions
* Authentication bypasses
* Manually fabricated browser state

Do not require browser proof for backend-only implementation when focused automated tests are sufficient.

---

## Live-runtime work

Before changing the local runtime:

* Confirm the active Kubernetes context.
* Confirm the namespace.
* Confirm relevant workloads and repository image versions.
* Confirm the intended change is reversible.
* Record only the baseline needed for the claim.

During live proof:

* Change only the component needed by the proof.
* Avoid creating unrelated failure conditions.
* Preserve exact timestamps and authority identifiers when material.
* Use canonical APIs and lifecycle paths for business resources.
* Do not use direct SQL or PBX mutation to manufacture success.
* Restore reversible failure conditions.
* Finish with a healthy environment when feasible.

Do not expand every live proof into full-system acceptance.

---

## Divergence classification

Do not automatically fail a task because execution differed from the ideal sequence.

Classify divergences as:

* Blocking correctness defect
* Security defect
* Authority conflict
* Observability gap
* Historical residue
* Expected rollout behavior
* Environmental issue
* Unrelated pre-existing condition
* Harmless timing difference

A divergence blocks completion only when it invalidates the principal claim.

Examples:

* A short metrics reload window does not invalidate a secure scrape cutover.
* An old worker completing an idempotent operation during rollout does not invalidate forward correctness.
* Historical rows do not invalidate a current invariant when they are explicitly classified.
* A missing supplementary event does not invalidate canonical cleanup when the new event path is independently proven.
* An unrelated crash-loop caused by stale local node IPs is an environmental issue, not automatically an application defect.

Document meaningful divergences without turning them into unrelated implementation work.

---

## Verification

Use the root `Makefile` for normal verification.

Run:

1. The smallest focused tests first
2. Relevant configuration or manifest checks
3. Broader phase-required suites
4. Formatting, build, hygiene, and diff checks as required

Use `make help` to inspect available targets.

Do not run every repository target indiscriminately when the phase contract does not require it.

A normal bounded implementation should usually establish:

1. Canonical authority is used.
2. Conflicting behavior is removed or cut off.
3. New behavior is automatic and idempotent.
4. Focused regression tests pass.
5. Required broad tests pass.
6. No second management authority is introduced.
7. Documentation is updated.
8. Repository state is clean.

Do not create twenty or thirty completion conditions when a smaller set proves the contract.

Report:

* Exact commands run
* Final outcomes
* Material intermediate failures that were corrected
* Final remaining failures
* Unresolved live-proof requirements

Do not claim success for commands that were not run.

---

## Evidence handling

Store only concise, sanitized evidence.

Never commit:

* Credentials
* Tokens
* Private keys
* Real telephone identities
* Customer information
* Private production hostnames
* Raw database dumps
* Complete noisy logs
* Machine-specific secrets
* Sensitive protocol captures

Evidence should support the claim without becoming a log archive.

Avoid copying large repeated report sections into the same evidence file.

---

## Git safety

Before editing:

```bash
git status --short
```

Account for all pre-existing changes.

Do not:

* Reset
* Clean
* Force-push
* Rewrite history
* Delete branches
* Discard unrelated work
* Overwrite user-authored changes
* Amend earlier commits without instruction

Do not commit or push unless the task explicitly requires it.

When a commit is requested:

1. Run focused verification.
2. Run required broader checks.
3. Review `git status`.
4. Review the staged diff.
5. Exclude generated files, logs, secrets, and unrelated changes.
6. Create one scoped commit.
7. Report hash, subject, and changed files.
8. Do not push unless explicitly instructed.

For incomplete work:

* Do not commit broken or misleading implementation.
* Create a checkpoint only when explicitly permitted.
* Use an accurate incomplete or blocked message.
* State exactly what remains.

Do not create a WIP commit merely because context or time is running short.

---

## Subagent coordination

Use subagents for:

* Read-heavy repository exploration
* Focused authority tracing
* Independent test review
* Manifest inventory
* Evidence correlation
* Verification planning

Use one primary write owner for bounded implementation.

Do not allow multiple write agents to edit overlapping files concurrently.

Give subagents narrow questions.

Require:

* Concise conclusions
* Relevant file references
* Material evidence
* No raw command dump unless necessary

The primary Claude Code session remains responsible for resolving conflicting conclusions.

---

## Clean-room requirement

Do not copy code, schemas, prompts, documentation, test fixtures, names, or configuration from APNTalk or any employer or client repository.

UTCP must remain a clean-room implementation based on:

* Public knowledge
* Original design
* UTCP’s own repository contracts
* UTCP’s own runtime evidence

Do not use private APNTalk behavior as an undocumented compatibility requirement.

---

## Required task framing

Every bounded task must identify:

* Phase or subphase
* Objective
* In-scope deliverables
* Explicit non-goals
* Canonical authority
* Required verification
* Completion criteria

Keep completion criteria proportional to the task.

A bounded task should not become a roadmap rewrite unless explicitly requested.

---

## Required final report

Follow the final-report contract in `AGENTS.md`.

At minimum, include:

## Verdict

Use one precise machine-readable verdict.

## Starting Commit

## Current Repository State

Include:

* Branch
* HEAD
* Working-tree state
* Phase marker
* Push status

## Current State

## Implemented or Confirmed

## Authority Boundary

State:

* Canonical owner
* Conflicting authority removed or preserved
* Any runtime-authority cutoff

## Files Changed

For evidence-only work, list only the evidence file.

## Verification Performed

List exact commands and material live checks.

## Tests Passed

## Tests Failed

Write `None.` when no final failure remains.

Mention corrected intermediate failures separately when material.

## Divergences

Classify meaningful divergences and state whether they invalidate the principal claim.

## Unresolved Proof Gaps

List only material gaps required for the next implementation or final acceptance.

## Deferred Work

Keep non-blocking roadmap work separate from proof gaps.

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

State what was changed and what remained untouched.

Do not repeat a generic prohibition list.

## Recommended Next Step

Choose one:

```text
bounded implementation
narrow evidence audit
controlled live proof
targeted blocker diagnosis
next roadmap phase
```

## Operator Required Before Next Prompt

List only genuine human prerequisites that:

1. Are directly required by the next task
2. Cannot reasonably be performed by Claude Code with available tools
3. Are supported by current evidence
4. Would otherwise block or invalidate the next task

Do not list:

* Permission to continue
* Optional preparation
* Generic warnings
* Commands that belong in the next prompt
* Architecture choices derivable from repository evidence
* Manual feature gates or allowlists
* Unnecessary service preparation
* “Do not” instructions

When no human action is required, write:

```text
None.
```
