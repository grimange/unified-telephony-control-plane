# Next assignment — controlled K2 initial import

```text
Assignment: UTCP_KANEO_TRACKER_K2_CONTROLLED_INITIAL_IMPORT
AI worker/coder: OpenAI Codex CLI
Model: GPT-6.1 Sol
Reasoning effort: Medium; disclose any specific integrity-driven escalation.
Professional role: Principal Engineering Governance Architect / Migration Engineer
Execution role: Single designated migration writer, only after explicit approval.
Repository: grimange/unified-telephony-control-plane

Objective: Execute only the operator-approved frozen K1 initial import into the
existing UTCP Kaneo project, with confirmed IDs and non-destructive recovery.
This prompt does not itself authorize writes. Before ANY Kaneo mutation require
an explicit operator instruction approving the exact root manifest digest
ee7432eb43ab8380532c4a33ef9d1f8a91e12fce6a71ae7664fdcf1e0c721a05
and the 10 task creates, 4 existing-label attachments and 5 relations, closure
policy, exclusive window, actual permissions, integration-trigger safety and
source/publication plan specified in K2_OPERATOR_APPROVAL_PACKET.md. If approval
or a prerequisite is missing, perform read-only/local preflight and report the
exact pending gate; do not create speculative test tasks or infer approval.

Required tools: Git/ripgrep/shell/Python3/JSON/SHA-256, applicable instructions,
local non-mutating validators, existing configured kaneo_local MCP reads and,
only after authorization, exact create_task, attach_label_to_task and
create_task_relation calls. No packages, production access or reconfiguration.

Read AGENTS.md, CLAUDE.md, docs/AGENTS.md, canonical tracker policy, phase ledger,
Governance E1 report and every K1 packet artifact under
 docs/architecture/proposals/kaneo-tracker/k1-mapping-freeze/.
Verify FREEZE_SHA256 against the explicit approval; validate exact packet,
source, mapping and task hashes. Never silently recompute approval hashes.
Record current branch/HEAD/status and preservation hashes; preserve all existing
tracked/untracked work. Use a new K2 directory for journals and reports.

Allowlist workspace 30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il, project
hrilr4371g33s0iw6lth3o7v, slug UTCP. Refresh project/columns/reusable labels,
all task and related pages, planned/archived tasks, deferred descriptions and
relations. Read exact full-line UTCP-Migration-Key markers; titles are not IDs.
Zero exact matches permits only an approved create. One identical match is
read-verified, journaled and skipped; incompatible state stops without update.
Multiple matches stop the key and its relations. No update calls are approved.

Before writes, resolve actual permission and integration-trigger evidence via
operator/admin record; read success/tool presence is not permission. Confirm
immutable publication/access for exact source bytes and this packet. Approve
path/hash locators explicitly or re-freeze and reauthorize payload changes to
add URLs; never fabricate publication or interpolate new URLs at runtime.
Under explicitly approved K2 governance scope, append the durable import
authorization and MIGRATING entry in canonical policy before writes. K1 source
policy hash is the pre-transition version; separately review and journal the
post-transition control-file hash without rehashing task payload/source claims.
If this governance edit is not authorized, stop before writes. Keep all phase
classifications, RMA-E next action, Go-native proposed ADR and proof boundaries.

Follow K2_EXECUTION_CHECKLIST.md exactly. One writer/exclusive window; no CAS,
transaction or exactly-once promise. Create tasks in frozen import_order using
only create_arguments. Immediately persist each returned ID before another
operation, then get_task verify project/key/title/description/status/priority
and absent dates/assignee. Only then continue. After all task read-backs, attach
existing labels, then resolve the explicit relation templates using verified
endpoint IDs. Journal concrete resolved arguments/digests; deduplicate and
read-confirm every label/relation. Do not generate dynamic extra tasks.

On timeout/ambiguous response journal UNKNOWN, stop writes and reconcile full
remote state by exact keys/IDs; never blindly retry create. Continued absence
on one read is not proof of noncommit. Require reliable request-outcome evidence
and explicit operator retry decision if ambiguity persists. On source drift,
project/identity conflict, duplicate, permission error or concurrent edit, pause
affected scope (all scope for project mismatch); never overwrite reviewer state.

Rollback is stop/inventory/forward reconciliation: retain source identity,
approved hashes, returned IDs/relation IDs, last confirmed state and failures.
No automatic delete/archive/update or undo of accepted decisions. Corrective
mutations require separate explicit approval. Builds/tests remain offline.

Deliverables: durable task/key/relationship-ID journal; approval/permission/
publication/trigger evidence index; exact before/after remote snapshot; per-key
read-back comparison; reconciliation counts; partial/unknown/conflict recovery
record if applicable; changed-file/preservation report; exactly one next bounded
K3/K4 handoff based on actual imported scope. Preserve K1 files byte-for-byte.

Validation: K1 validate-packet.py before activation-policy control edit; after
that edit verify preserved pre-transition and approved post-transition hashes
explicitly instead of claiming original policy unchanged. Check all other frozen
sources/payloads unchanged; make repository-hygiene phase-status-consistency-check;
make phase-status-consistency-check-test; JSON/digest/ID/marker/column/label/
relation/source-link/secret/whitespace checks; git diff --check. Full task and
related pagination after import: approved missing/duplicate/misclassified/
orphaned counts zero. Report known K5 checker and Vue scanner findings separately;
no broad runtime suites or incidental repairs. No stage/commit/push.

Acceptance: exactly approved task/label/relation scope, confirmed returned IDs,
verified fields and immutable evidence access, no unknown or unresolved duplicate
outcomes, no existing task overwritten, all prior artifacts preserved. Keep
MIGRATING after successful K2; K4 acceptance and explicit K5 activation remain
separate gates. Never mark ACTIVE, adopt Go-native ADR, authorize implementation,
close developer-tested work or change formal phase/release status by import.
```
