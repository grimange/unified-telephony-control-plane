# Next assignment — K1 mapping freeze and payload review

```text
Assignment: UTCP_KANEO_TRACKER_K1_MAPPING_FREEZE_AND_PAYLOAD_REVIEW
AI worker/coder: OpenAI Codex CLI
Model: GPT-6.1 Sol
Reasoning effort: Medium; disclose any specific correctness-driven escalation.
Professional role: Principal Delivery Architect / Engineering Governance Architect
Execution role: Read-only auditor and local migration-plan author.

Objective: Turn the E1 audit into an exact, reviewable K2 import packet for a
small initial subset. Do not execute a migration or transfer tracker authority.

Required tools: Git, ripgrep, Python 3/JSON, repository instructions and existing
validators, existing configured Kaneo MCP discovery and read operations only.
No package installation, production access or MCP reconfiguration.

Read AGENTS.md, docs/AGENTS.md and the E1 report at
 docs/architecture/proposals/UTCP_KANEO_TRACKER_MIGRATION_READINESS_AUDIT_E1.md
and every artifact under docs/architecture/proposals/kaneo-tracker/.
Record branch, HEAD, staged/tracked/untracked state and SHA-256 preservation.
Treat that audit as a historical snapshot and revalidate changed sources.
Preserve all existing files; place this assignment's new outputs in a new
k1-mapping-freeze subdirectory. Do not overwrite the E1 audit.

Scope:
1. Refresh the existing workspace/project, columns and every task/related page
   using read-only MCP calls. Allowlist workspace
   30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il and project hrilr4371g33s0iw6lth3o7v.
   Inspect archived/planned arrays and full descriptions where deferred.
2. Reconcile exact UTCP-Migration-Key markers and any stored task IDs before
   proposing creates. Zero matches allows a candidate only; one requires
   reconciliation; multiple matches stop that key. Titles alone are not IDs.
3. Review the proposed 13 initial units; remove or explain low-value overlap.
   Keep RMA-E canonical next action. GO-CP2 E1 is a separate backend proposal.
   Preserve original source, implementation, developer verification,
   independent review, operator acceptance, ADR and deployment dimensions.
4. Produce exact allowlisted future task/label/relation payloads, source hashes,
   marker convention, mapping schema, project/task IDs or null, per-field
   ownership, import order, typed dependencies and payload SHA-256 manifest.
   Reuse the existing project and columns. No automatic Done assignment.
   Do not invent assignees, due dates, priority or acceptance decisions.
5. Separate missing prerequisites from routine K2 implementation. Identify
   evidence paths that remain untracked and need separately authorized
   preservation before immutable remote links can work. Connector permissions
   and integration-trigger safety remain unverified unless read evidence or
   operator evidence resolves them; never test them by mutation.
6. Define single-writer preflight, timeout/partial-create reconciliation,
   immediate returned-ID journaling, read-after-write comparison, update conflict
   stops, relation deduplication and non-destructive recovery. No CAS or
   exactly-once guarantee is exposed. Keep Git validators independent of Kaneo.

Authorization boundary: Only repository reads, non-mutating validators,
read-only Kaneo calls and new local K1 documents/data are authorized. No Kaneo
create/update/delete/comment/label/relation/move/archive calls; no automations;
no source roadmap, ADR, instruction or tracker modification; no stage/commit/
push. K2 requires a separate operator instruction approving the exact payload
manifest and import policy. K5 authority transition needs separate approval.
Do not request authority merely to finish the local review packet.

Deliverables: K1 review report, exact payload-preview.json and Markdown,
source-to-task mapping, collision/reconciliation report, payload hash manifest,
K2 execution/rollback checklist and unresolved operator decisions. No executable
remote-write automation. Existing E1 outputs stay unchanged.

Validation: make repository-hygiene phase-status-consistency-check; reproduce
and validate E1 audit where still applicable; validate K1 JSON, unique source
and migration keys, full page coverage, exact match confidence, column slugs,
source hashes, dependency endpoints and hard-edge acyclicity, evidence/local
links, payload secret scan and whitespace; git diff --check. Report existing
checker/Vue scanner failures separately without incidental fixes. Verify all
baseline hashes and no staged/tracked changes. Reconcile phase-status only if
this task establishes a material authoritative state change and permission
allows it; otherwise report NO_CHANGE_REQUIRED, never silently rewrite it.

Acceptance gates: deterministic payload manifest; every selected source has
one disposition and stable identity; no duplicate or ambiguous creates;
no invented acceptance or adoption; typed dependencies and source links
complete; project scope allowlisted; rollback preserves Git evidence and
returned IDs; unchanged baseline files; zero Kaneo writes. Report readiness
with exact unresolved conditions. Provide the concrete K2 packet for review,
without executing it.
```
