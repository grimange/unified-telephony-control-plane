# Kaneo canonical operational tracker policy

## Decision and current activation record

**Policy: adopted by operator direction.** **Activation: ACTIVE.**

On 2026-10-22 the operator selected Kaneo as UTCP's canonical project task and
execution-progress tracker through assignment
`UTCP_KANEO_CANONICAL_TRACKER_GOVERNANCE_E1`. This adopts the governance target;
existing Git tracking authority remains in effect until explicit activation.
No migration, task write, deployment or unrelated ADR adoption is authorized
by this decision.

This section is the durable, versioned activation decision record. Future
transitions append a dated entry identifying the operator authorization,
transition, effective source revision, workspace/project, approved record scope,
mapping-manifest revision and accepted reconciliation report. Keep previous
entries and link the original decision; never infer a transition from task
counts, a successful run or elapsed time. Only the operator may authorize
ACTIVE. Record that explicit authorization here before workers act on it;
update instruction references in the same reviewed packet. This assignment
leaves the policy as repository changes without committing or pushing it.

| State | Authority and transition gate |
| --- | --- |
| SELECTED_NOT_ACTIVE | Initial selection state. Kaneo is selected; existing Git status authority remains active. K1 planning is read-only. |
| MIGRATING | Separately authorized K2/K3 import. Kaneo is a projection; existing authority still governs conflicts. Record import authorization and exact scope here. |
| RECONCILED_PENDING_ACTIVATION | K4 accepted: approved records, returned IDs, source links, statuses and relations reconcile; missing, duplicate, misclassified and orphaned approved records are zero, or exceptions have explicit operator dispositions. Operator activation remains pending. |
| ACTIVE | Operator explicitly accepts K4 and authorizes K5 with the exact project/record scope and effective revision recorded here. Kaneo owns operational progress; Git retains the dimensions below. |

Outage is not a state transition. Suspension, reversal or scope changes require
an explicit operator decision and reconciled ownership; never silently restore
a second operational status writer.


### 2026-10-22 — authorized K2 migration entry

Operator authorization: `UTCP_KANEO_TRACKER_K2_EXECUTE_INITIAL_IMPORT` in the
current operator instruction. Transition: SELECTED_NOT_ACTIVE → **MIGRATING**.
Effective source HEAD: `5e028b9ab3d96f3430bf715f89d66ab19a758761` (local uncommitted control record).
Workspace `30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il`, project `hrilr4371g33s0iw6lth3o7v`.
Approved successor manifest: `d093be6eb0f9d8c96d7485456610f153e30614114f9bfe59ced03097ba3c1efb`.
Scope: ten task creations, four existing-label attachments and five relationships;
one designated writer, this Codex session. Frozen repository path/SHA-256 evidence
locators are accepted without remote publication. The first approved create may
establish write permission; bounded integration-trigger uncertainty is accepted.
No ACTIVE, K4 acceptance, implementation authority, formal acceptance or deployment
authorization is granted. Existing Git operational authority remains in effect.
Execution identity, policy hashes and results: [K2 execution record](../architecture/proposals/kaneo-tracker/k2-controlled-import/EXECUTED_IMPORT.md).

### 2026-10-22 — accepted K4 reconciliation

Operator decision: `UTCP_KANEO_K5_CANONICAL_TRACKER_ACTIVATION`, the explicit
operator instruction accepting K4. Transition: **MIGRATING → RECONCILED_PENDING_ACTIVATION**.
Accepted scope: ten task identities, four label attachments and five relationships;
zero duplicate, missing, unexpected, mismatched or unresolved imported records.
This accepts tracker-data reconciliation, not independent acceptance of GO-CP1
engineering implementation or any other imported task's scope.

Accepted [K4 report](../architecture/proposals/kaneo-tracker/k4-reconciliation/K4_RECONCILIATION_REPORT.md)
SHA-256: `4b007d918a1dd1070b3f38d01863ac0e6e31a9a74e1f94346ee4ad0afa828384`.
[K4 machine reconciliation](../architecture/proposals/kaneo-tracker/k4-reconciliation/reconciliation.json)
and [executed task mapping](../architecture/proposals/kaneo-tracker/k2-controlled-import/executed-task-mapping.json)
retain the accepted identities. Mapping SHA-256:
`109616fec40c0823ebe30319700aa38561c2fa15de4e171c9fdd5959eee57e8a`.
Approved import root remains
`d093be6eb0f9d8c96d7485456610f153e30614114f9bfe59ced03097ba3c1efb`.
Effective HEAD: `5e028b9ab3d96f3430bf715f89d66ab19a758761`, with local uncommitted
governance changes. Workspace `30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il`, project
`hrilr4371g33s0iw6lth3o7v`. Fresh K5 read-only checks confirm the ten mapped
identities and statuses; K4 remains the accepted comprehensive comparison.

### 2026-10-22 — operator-authorized K5 activation

Operator authorization: `UTCP_KANEO_K5_CANONICAL_TRACKER_ACTIVATION`, explicitly
accepting K4 and authorizing both transitions in sequence. Transition:
**RECONCILED_PENDING_ACTIVATION → ACTIVE**. The preceding K4 acceptance is its
reconciliation basis; this operator decision grants activation authority.
Effective HEAD: `5e028b9ab3d96f3430bf715f89d66ab19a758761`, with this local
uncommitted control record. Workspace **Personal Projects**
(`30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il`), project **Unified Telephony Control Plane**,
slug `UTCP`, ID `hrilr4371g33s0iw6lth3o7v`. The ten imported identities and
returned IDs above form the initial scope; subsequent tasks and updates require
their own assignment authority in this same project.

Kaneo now owns active assignments, task execution/readiness, workstream progress,
blockers, operational dependencies, review queues and explicitly assigned task
ownership. Git retains source/tests, specifications/contracts, architecture/ADRs,
technical evidence, independent QA decisions, formal operator acceptance,
phase/release classifications, canonical roadmap next action, and production
authorization/deployment evidence. No second operational writer is established.
The worker protocol below is operative for authorized assignments now; K6 is not
a prerequisite for ordinary MCP task updates and grants no blanket permission.

Accepted nonblocking limitations: repository path/SHA-256 evidence references
remain local pending UTCP-9 preservation/publication; historical K3 summaries
are optional; K2's recovered journal persistence limitation remains disclosed.
No task state, label, relationship, ADR, milestone acceptance, GO-CP2 execution
authority or production authorization changes through activation.
Before/intermediate/after hashes and source identity are retained in the
[K5 control record](../architecture/proposals/kaneo-tracker/k5-activation/activation-record.json);
see the [K5 implementation report](../architecture/proposals/kaneo-tracker/k5-activation/K5_ACTIVATION_REPORT.md).

## Authority and precedence

The current task's explicit authorization and root [AGENTS.md](../../AGENTS.md)
govern worker scope. This policy is the single cross-worker tracker-governance
contract. It supersedes the earlier audit's *undecided tracker selection* and
recommendation for a future policy choice; the audit remains immutable evidence
of its earlier snapshot. No accepted technical ADR or historical proof changes.

| Dimension | Before ACTIVE | Upon ACTIVE |
| --- | --- | --- |
| Assignment execution, backlog/readiness, workstream progress, task blockers, operational dependencies, review queue, explicitly assigned ownership, active operational progress reporting | Existing repository authority: phase ledger for canonical phase state; relevant bounded assignment records for their declared scope. Kaneo only projects approved imports. | Kaneo, within the activated project/record scope. Workstream task rollups describe execution, not formal phase acceptance. |
| Source code, tests, technical specifications/contracts, implementation and reproducible developer evidence | Git | Git; Kaneo links the exact revision/report and projects its claim. |
| Independent QA/review and formal engineering acceptance | Git decision/evidence record by the authorized reviewer | Same; reviewer-owned outcome may be projected to Kaneo. |
| Operator decisions and acceptance, ADR adoption, deployment authorization and proof | Explicit durable Git decision/evidence records | Same; no operational column grants these authorities. |
| Formal phase/release acceptance, phase classification and canonical roadmap next action | [Phase-status ledger](../roadmap/phase-status.md), accepted decisions and governing acceptance evidence | Same until separately changed by the operator; task status never rewrites them. |

Each dimension has one owner. After activation, Git reports retain technical
claims and dated operational observations but do not become another editable
current task-status ledger. No circular synchronization or last-writer-wins
rule is allowed. A mismatch between a task and a phase is resolved by their
respective owners and evidence, not by copying one status over the other.
The ledger's reconciliation obligations and repository validators continue.

Preserve the accepted V1/K5 classifications and the exact current roadmap next
action:
`RMA_E_S3_COMPATIBLE_ARCHIVE_ADAPTER_AND_DETERMINISTIC_MINIO_PROOF_IMPLEMENTATION_DESIGN`.
GO-CP1 E1–E4 reports establish their developer-verification scopes; independent
and operator acceptance remain not established by those reports. The Go-native
ADR is proposed. Production cutover requires separate authorization. Tracker
selection does not reprioritize RMA-E or close these gates.

## Project, workflow and status dimensions

Use existing MCP server `kaneo_local`, workspace **Personal Projects**
(`30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il`) and project **Unified Telephony Control Plane**,
slug `UTCP`, ID `hrilr4371g33s0iw6lth3o7v`. Do not create a second canonical UTCP
project. Verify project identity on reads and before authorized writes.

Use existing columns **To Do** (`to-do`), **In Progress** (`in-progress`),
**In Review** (`in-review`), **Done** (`done`). Blocked work retains the appropriate
non-final column, supported Blocked label, precise reason and a `blocks` relation
when a real dependency exists. Use Proposed / not adopted and Historical
reference labels as appropriate. Preserve source attribution for imported
history; do not automatically close it. Invent no priorities, owners or dates.

Descriptions and linked Git records must distinguish operational execution,
implementation, developer verification, independent QA/review, operator
acceptance, ADR adoption, production deployment, and formal phase/release
acceptance. These are conventions, not connector-enforced custom fields.

An authorized developer may move a task to In Review after its defined developer
handoff criteria and required verification are met and evidence is linked.
Done requires acceptance of the explicitly defined task scope by its authorized
decision-maker. The implementer may not accept their own work by relabeling
self-tests as independent review. Identify the acceptance role/criteria before
closure; missing criteria or authority means remain In Review. Task Done neither
adopts an ADR nor proves production readiness or formal phase completion.

## AI-worker protocol after activation

All authorized Codex, Claude and other AI workers use the configured Kaneo MCP
for operational task reads/updates under this policy. Activation does not grant
blanket write permission or task-creation authority. The current assignment must
authorize each operation and scope under this active protocol. Ordinary authorized
task updates need no additional K6 program; new automation remains separately governed.

1. **Start:** identify exact task ID, stable key and project; read current state,
   dependencies and acceptance criteria. Confirm execution authority from the
   actual assignment/decision. Task presence is not authorization. Mark In
   Progress only when execution is authorized.
2. **Implement:** update only the assigned task and explicitly authorized related
   records. One designated writer per task; coordinate implementer/reviewer
   handoff. Read immediately before updates, compare expected state and stop on
   newer/conflicting changes. Keep useful blocker/progress updates concise;
   preserve detailed engineering evidence in Git rather than repeated comments.
3. **Developer handoff:** run required checks, retain sanitized evidence in Git,
   link the report and exact source revision/hash, state evidence level and
   limitations, then use In Review only when review-entry criteria are met.
4. **Independent review:** the independent reviewer records findings/decision
   in Git and projects the result to the task. Developer testing cannot substitute.
   Failed/blocked review returns to the appropriate non-final state with reasons
   and preserved history; do not erase prior findings.
5. **Acceptance:** the authorized scope decision-maker records acceptance and
   then Done may reflect that scope. Operator acceptance, ADR adoption,
   deployment and phase/release acceptance retain their separate Git gates.
   Reviewer-owned and operator-owned decisions are never overwritten by a
   developer's progress update.

## Stable identity, concurrency and uncertain writes

Every imported or agent-created task needs a durable external identity plus its
returned Kaneo ID. Migrated tasks use the approved exact description marker
`UTCP-Migration-Key: <approved key>`. New agent-created tasks must receive a
unique stable key recorded before their separately authorized create; K1/K6 must
define their allocation convention. Never use a title as the sole identifier.

Keep returned task/project/relation IDs, key, source IDs/paths/revision/hashes,
approved payload digest and reconciliation state in a durable repository mapping
manifest. This is an identity/reconciliation record, not another live task-status
tracker. Reconcile existing IDs and exact markers across all task and related
pages, planned/archived arrays and deferred descriptions before any create.
One exact match means reconcile; multiple matches stop that key. No match only
permits a separately approved create. Similar titles require review, not merging.

The configured MCP exposes no atomic upsert/CAS guarantees, transaction APIs or
custom-field administration. A pre-update read/hash check cannot eliminate a
concurrent writer race. Use one designated writer and an exclusive import window;
stop and escalate unexpected edits rather than blind overwrite or timestamp
precedence. Journal returned IDs immediately; read after writes to confirm the
project, key and intended fields. For timeouts or ambiguous results, reconcile
by reads before retrying. Never blindly retry creates or claim success without
confirmation. Destructive cleanup is separately authorized; retain history.

## Outages and pending reconciliation

Builds, tests and CI remain independent of Kaneo availability. While ACTIVE,
workers may continue already authorized engineering work where missing tracker
information does not affect safe scope or dependencies. If authority, identity
or required dependency state cannot be established, stop the dependent action.

Preserve engineering evidence in Git. If an operational update cannot be
confirmed, explicitly record it as pending in the assignment handoff with task
ID/key, source revision, intended transition and last observed state. A pending
intent is not an authoritative status or a successful Kaneo update. No competing
Git operational ledger or offline queue is introduced by this policy.

On recovery, read current Kaneo state and reconcile the pending intent before
applying an authorized update. Stop unresolved identity, duplicate keys or
conflicting status changes for the responsible owner/operator. Do not replay
stale intents over reviewer/operator decisions or blindly retry ambiguous creates.
Future automation must implement these confirmation and reconciliation rules;
this assignment implements no queue, synchronization service or remote writer.

## Migration gates and retained validation

| Stage | Required boundary |
| --- | --- |
| GOV-E1 | This adopted selection policy; SELECTED_NOT_ACTIVE. No imports or activation. |
| K1 | Freeze minimal payloads, keys, source hashes, mappings, dependencies, evidence links, closure roles and reconciliation rules. Read-only remote access. |
| K2 | Separately operator-authorized exact initial import; record returned IDs and read-after-write proof. Enter MIGRATING under the recorded authorization. |
| K3 | Approved concise historical summaries and relations; preserve source status/acceptance attribution. No automatic historical acceptance. |
| K4 | Reconcile approved scope and mappings; resolve duplicates, missing/misclassified/orphaned records and conflicts. Record accepted reconciliation and RECONCILED_PENDING_ACTIVATION. |
| K5 | Explicit operator authorization recorded above activates operational authority. Review coherent worker instructions/validator implications in the same packet; no ledger retirement by implication. |
| K6 | Optional focused automation/integration work under separate authorization. The active worker protocol already permits assignment-authorized MCP task updates, with stable keys, one writer, role separation, confirmed writes and outage/conflict handling. No implied blanket permission. |

K2/K3 may share an approved packet; neither completion nor K4 acceptance alone
activates authority. Migration success does not adopt unrelated technical ADRs.
Keep existing Make/CI validators and Git evidence requirements offline. Any future
validator or worker change needs its own bounded review and proportional tests.

The [readiness audit](../architecture/proposals/UTCP_KANEO_TRACKER_MIGRATION_READINESS_AUDIT_E1.md)
and [migration plan](../architecture/proposals/kaneo-tracker/migration-execution-plan.md)
remain historical planning inputs. This policy governs selection, activation
and cross-worker rules where earlier proposals left those choices open.
