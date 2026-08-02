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

## Authorization is distinct from reversibility

A change being reversible does not make it authorized.

Repository and local-runtime actions must satisfy all three conditions:

1. They are within the current task's explicit scope.
2. They follow an existing repository-supported lifecycle.
3. They do not materially alter the established topology, authority boundary, or environment identity.

Examples of changes that remain material even when technically reversible:

* Creating another k3d cluster or local registry
* Replacing the canonical `utcp-local` environment
* Changing host port publication, node count, or cluster topology
* Creating a parallel Kubernetes deployment
* Switching from the canonical Make target or script to direct lower-level application
* Starting Docker Compose as a substitute for Kubernetes
* Moving a proof to another cluster or runtime
* Recreating stateful infrastructure or replacing persistent volumes
* Introducing a second ingress, signaling, media, or runtime authority

Do not perform these actions merely because they can later be undone.

When a material environment or topology change is required, use exactly one of these paths:

1. Follow an existing repository-defined and task-authorized lifecycle.
2. Implement a bounded repository correction when the task explicitly authorizes that correction.
3. Stop and report the repository gap, its effect, and the smallest deterministic correction needed.

Do not improvise a parallel environment as a substitute for a blocked canonical lifecycle.

---

## Reversible, material, and irreversible work

Classify a proposed mutation before performing it.

### Ordinary reversible work

Examples:

* Changing a bounded repository implementation
* Correcting an adapter
* Adding a focused regression test
* Deploying an already-supported local image through the canonical lifecycle
* Restarting one local Pod
* Creating a disposable application-domain proof resource through the canonical API
* Applying a repository-documented reversible failure condition
* Rolling back a workload using the established deployment mechanism

Proceed when:

* the task authorizes mutation;
* authority, expected behavior, and acceptance criteria are clear;
* the repository already supports the lifecycle being used; and
* the action does not materially change environment topology or identity.

### Material environment or topology work

Examples:

* Creating, deleting, or rebuilding a k3d cluster
* Creating or replacing a registry
* Adding host port mappings or changing load-balancer publication
* Changing cluster node counts
* Creating a parallel proof cluster
* Moving the canonical deployment to another cluster or replacing `utcp-local`
* Recreating stateful services or persistent volumes
* Bypassing a canonical Make target or deployment script
* Applying lower-level manifests because the canonical lifecycle rejects the intended topology
* Starting another runtime as a substitute for an unavailable canonical runtime

These actions require explicit task authorization or an already documented repository lifecycle that clearly covers the exact action. Technical reversibility is not sufficient authorization.

If a required immutable cluster capability is absent—for example, a required k3d host-port publication—do not create a replacement or parallel cluster automatically. Instead:

1. Confirm the capability is genuinely required.
2. Confirm the canonical cluster cannot acquire it in place.
3. Inventory non-reproducible state and preservation requirements.
4. Identify the repository configuration that should own the capability.
5. Determine whether the current task authorizes changing that configuration and rebuilding the canonical cluster.
6. If authorized, use the canonical rebuild lifecycle.
7. Otherwise, stop and report the bounded repository gap.

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
* Deleting non-reproducible state without a verified recovery path

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

### Canonical local environment identity

The canonical local Kubernetes environment is:

```text
cluster: utcp-local
context: k3d-utcp-local
registry host endpoint: 127.0.0.1:5001
edge ownership: 127.0.0.1:80 and 127.0.0.1:443
```

Repository instructions and current committed configuration may define additional required port publications.

Do not create another cluster such as `utcp-mediaedge`, `utcp-proof`, `utcp-test`, `utcp-recovery`, or `utcp-local-2` as a replacement, workaround, or temporary proof environment unless the current task explicitly authorizes a documented multi-cluster topology.

A disposable application resource inside `utcp-local` is not equivalent to a disposable cluster. The latter is a material topology change.

If `utcp-local` lacks an immutable k3d capability needed by the current roadmap—for example, required RTP UDP host-port publication—the preferred deterministic correction is:

1. Update the repository-owned canonical cluster configuration.
2. Verify that local state is reproducible or safely preserved.
3. Rebuild `utcp-local` through the authorized canonical lifecycle.
4. Redeploy through the normal repository commands.
5. Verify restoration and the newly required capability.

Do not create a second cluster to avoid correcting the canonical lifecycle.

`apntalk-local` is a separate environment. Preserve it and keep it stopped while UTCP owns the standard local edge unless the operator explicitly requests otherwise.

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

## No improvised execution paths

A failure in the canonical lifecycle is evidence of a blocker or repository gap. It is not permission to invent a substitute lifecycle.

If a canonical repository Make target or script rejects the intended topology, validates a different cluster profile, cannot deploy the required configuration, lacks a required immutable environment capability, or otherwise prevents valid proof, do not silently replace it with:

* direct `kubectl apply` or direct Kustomize application;
* ad hoc Helm commands or manually copied manifests;
* an additional cluster or registry;
* a Docker Compose substitute;
* manually patched live resources; or
* another lower-level execution path.

First classify the failure as an implementation defect, repository automation gap, environment drift, unsupported topology, or incorrect task assumption. Then either correct it within the canonical repository lifecycle when the task explicitly authorizes that bounded implementation, or stop and report the blocker and smallest deterministic correction.

Read-only lower-level diagnostics are allowed when they help establish the blocker. Diagnostic access must not become an alternate deployment or management path.

Never describe an improvised environment as temporary or disposable to bypass this rule.

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
* Preserve the canonical environment identity unless the task explicitly authorizes changing it.
* Do not create parallel infrastructure to work around missing canonical capability.
* Treat a rejected canonical deployment path as a blocker or repository gap, not permission to bypass it.
* Correct reproducible local-environment requirements in repository-owned configuration.
* Prefer rebuilding the canonical reproducible environment over maintaining an undocumented replacement.
* Pause before any material topology deviation that was not part of the task's stated plan.
* Report newly discovered topology requirements before implementing them when they expand the task materially.

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

* Confirm the intended cluster name and Kubernetes context.
* Confirm the namespace.
* Confirm relevant workloads and repository image versions.
* Confirm which repository command owns deployment.
* Confirm the proposed action is within the current task's authorization.
* Distinguish ordinary reversible workload mutation from material environment mutation.
* Record only the baseline needed for the claim.
* Inventory non-reproducible state before any cluster, registry, volume, or stateful-service recreation.
* Confirm a repository-defined recovery path for any material environment change.
* Confirm the change will not create a parallel authority or replacement environment.

During live proof:

* Use the canonical cluster and deployment lifecycle.
* Change only the component needed by the proof.
* Avoid creating unrelated failure conditions.
* Preserve exact timestamps and authority identifiers when material.
* Use canonical APIs and lifecycle paths for business resources.
* Do not use direct SQL or PBX mutation to manufacture success.
* Do not apply lower-level manifests as a substitute for a failed canonical deployment command.
* Do not create a second cluster or registry to bypass missing capability in `utcp-local`.
* Restore reversible failure conditions.
* Finish with a healthy environment when feasible.

### Mandatory pause conditions

Stop live-runtime mutation and report before proceeding when:

* The canonical cluster lacks a required immutable capability.
* The canonical deployment command rejects the required topology.
* Completion would require creating another cluster or registry.
* Completion would require changing host port publication not already supported by repository configuration.
* Completion would require deleting or recreating stateful infrastructure without a preservation assessment.
* The task's requested environment cannot support the proof as currently defined.
* A lower-level deployment path would bypass repository validation.
* The proposed recovery would change environment identity or topology.
* The current repository contract and required live topology conflict.

A pause does not automatically require operator intervention when the resolution is derivable from repository evidence. It means the current run must not silently expand itself.

Report:

1. The exact discovered limitation.
2. Why the canonical lifecycle cannot proceed.
3. The smallest repository-backed correction.
4. Whether existing state is reproducible.
5. Whether the task already authorizes that correction.
6. What remains unmodified.

Do not expand every live proof into full-system acceptance.

---

## Divergence classification

Do not automatically fail a task because execution differed from the ideal sequence.

### Material execution divergence

A divergence from the planned execution path is material when it changes:

* Cluster or registry identity
* Host port ownership or publication
* Node topology
* Deployment mechanism
* Persistent-state lifecycle
* Runtime authority
* Security boundary
* Canonical management path

Material divergence requires a pause unless the current task explicitly authorized the alternative.

Do not classify a newly created cluster, alternate registry, direct-manifest deployment, or replacement runtime as a harmless environmental difference merely because the intended application code is unchanged.

When the canonical path fails, diagnose and classify that failure. Do not continue through an improvised substitute and report the substitution only afterward.

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

Environmental classification explains a failure; it does not authorize bypassing the canonical environment lifecycle.

Document meaningful divergences without turning them into unrelated implementation work.

---

## Plan-change boundary

Claude Code may make small implementation adjustments without pausing when they remain inside the task's established authority, files, lifecycle, and acceptance criteria.

Claude Code must pause before performing a newly discovered action that materially changes the environment topology, canonical cluster or registry, host networking or port publication, deployment lifecycle, persistent-state handling, security boundary, canonical authority, or scope of proof.

The pause report must be concise and decision-oriented. It must not produce a new broad audit. Use this format:

```text
Discovered limitation:
Canonical path affected:
Why the planned path cannot proceed:
Proposed deterministic correction:
State-preservation impact:
Current task authorization:
Mutation stopped at:
```

If the proposed correction is already explicitly authorized by the task and supported by a repository-defined lifecycle, proceed after recording the preflight evidence. If either condition is absent, stop and request direction or recommend a bounded follow-up.

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
9. The canonical environment and deployment lifecycle were preserved.
10. No alternate cluster, registry, runtime, or lower-level deployment path was introduced.

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

## Environment and Topology Changes

List every change involving:

* Clusters
* Registries
* Host ports
* Load balancers
* Kubernetes contexts
* Node topology
* Persistent volumes
* Deployment mechanisms
* Parallel runtimes

For every item, state whether it was repository-supported, explicitly authorized, restored, and whether it remains part of the canonical environment.

If none occurred, write:

```text
None.
```

## Improvised or Non-Canonical Actions

List every action that departed from the repository's canonical lifecycle, including failed attempts.

For each action, state:

* Why it was attempted
* Whether it mutated state
* What validation it bypassed
* Whether it was reverted
* Whether any residue remains

If none occurred, write:

```text
None.
```

An improvised action must never be omitted merely because it was temporary, failed, reverted, or did not affect the final commit.

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
