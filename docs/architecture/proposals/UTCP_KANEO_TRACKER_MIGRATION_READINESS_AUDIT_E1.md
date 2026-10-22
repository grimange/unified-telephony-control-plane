# UTCP Kaneo tracker migration readiness and current-state inventory audit E1

Assignment: `UTCP_KANEO_TRACKER_MIGRATION_READINESS_AUDIT_E1`.

Verdict: **UTCP_KANEO_TRACKER_MIGRATION_READY_WITH_CONDITIONS**.

The existing configured Kaneo connection works for read-only inspection. Its existing UTCP project is empty. A reliable small migration can be designed using its current columns, description markers and native task relations. This audit does not migrate records, authorize implementation, adopt a proposed ADR, close tasks, retire the phase ledger or transfer tracking authority. Conditions concern exact import-policy approval, stable publication of currently untracked evidence, integration-trigger safety and the absence of connector custom fields, conditional updates and column administration. They do not prevent completion of this audit.

## Worker, baseline and preservation

Worker: OpenAI Codex CLI; requested model GPT-6.1 Sol, requested reasoning Medium; role Principal Software Delivery Architect / Engineering Governance Architect / Technical Program Management Systems Architect. The interface does not expose a model attestation. No reasoning escalation was requested. Applied the repository evidence-audit skill and its independent read-only repository/architecture inventory roles. Tools used: shell, Git, ripgrep, Python 3/JSON, existing repository checks and configured Kaneo MCP discovery/read calls. No host packages were installed. No production or infrastructure access occurred.

Starting and current identity: branch `main`, HEAD `5e028b9ab3d96f3430bf715f89d66ab19a758761`. Starting state: zero tracked modifications, zero staged changes, **122 individual untracked files**. [Baseline](kaneo-tracker/repository-baseline.json) contains exact full porcelain state and SHA-256 hashes of **1,676 existing Git-visible files**. The source catalog additionally hashes ignored local governance/instruction files. All pre-existing files are preserved; additions belong only to this audit packet. No existing file was overwritten, staged, committed or pushed.

Root and docs instructions, contribution/security rules, ignore rules, phase ledger, roadmaps, accepted decisions, proposal reports, validator source and relevant workflow consumers were inspected before assessment. Local `.agents/` and `.codex/` instruction files are ignored and cannot be presumed available from a clean checkout. This packet is an audit snapshot with hashes, not a second live tracker.

## Deliverable and evidence index

| Artifact | Contents |
| --- | --- |
| [Source inventory](kaneo-tracker/source-inventory.md) | Exact paths, authority, producers/consumers and validator/governance impact |
| [Source catalog](kaneo-tracker/source-catalog.json) | 499 source artifacts with hashes, formats, record membership, references and dispositions |
| [Record inventory](kaneo-tracker/inventory-records.json) | 151 source records with all migration/status/acceptance/hash/dependency fields |
| [Mapping](kaneo-tracker/migration-mapping.md) | Every distinct record and exactly one primary disposition |
| [Kaneo capability audit](kaneo-tracker/kaneo-capabilities.md) | Actual read/write interface, pagination, native model and connector gaps |
| [Sanitized Kaneo snapshot](kaneo-tracker/kaneo-read-snapshot.json) | Actual project/column/task/label evidence and discovered tool descriptions |
| [Status and authority crosswalk](kaneo-tracker/status-authority-crosswalk.md) | Separate execution, evidence, governance, independent/operator acceptance and deployment dimensions |
| [Dry-run JSON](kaneo-tracker/migration-preview.json) / [Markdown](kaneo-tracker/migration-preview.md) | 26 proposed remote units, source hashes, exact columns, grouping and typed dependencies |
| [Frozen mapping rules](kaneo-tracker/migration-rules.json) | Reviewed grouping/payload policy, not a remote write script |
| [Execution plan](kaneo-tracker/migration-execution-plan.md) | Stages K0–K6, task template, dependency diagram, risks, reconciliation and recovery |
| [Next assignment](kaneo-tracker/next-assignment.md) | Exactly one ready-to-copy K1 prompt; no remote writes authorized |
| [Reproducer](kaneo-tracker/reproduce-preview.py) / [validator](kaneo-tracker/validate-audit.py) | Read-only deterministic local integrity checks; no remote operations |

## Existing tracking architecture and precedence

[The phase ledger](../../roadmap/phase-status.md) is the sole canonical current-state ledger and owner of the canonical next action. [Implementation roadmap](../../roadmap/implementation-roadmap.md) owns phase scope, ordering, dependency and acceptance design; conflicting descriptive status prose is subordinate to the ledger. Accepted ADRs own architectural decisions. Historical evidence records scoped proof at its original time; it is not retroactively rewritten. Proposal-local GN/GO plans and implementation reports supply proposed stages and developer evidence without becoming adopted phase authority.

No repository task database, engineering-tracker synchronization service, canonical GitHub/Gitea issue authority or tracker/dashboard generator was discovered. Product runtime dashboards, PostgreSQL operations and Markdown test checklists are not operational development trackers. Hundreds of evidence attachments remain technical evidence. Relevant consumers are the Makefile, repository hygiene and phase consistency scripts, CI workflows, T1 compatibility checks, secret scanner, runtime checker, root/docs instructions and local worker templates. Their exact inputs/outputs, writes, validation role, future source and test requirements are documented in the source inventory. No live Kaneo dependency should enter repository validation.

Confirmed residue: the implementation roadmap's RMA subsection still describes an earlier RMA-A blocker/unstarted later slices, while its K5 summaries retain planned/pre-proof language. README and chronological UI summaries also reflect earlier stages. The canonical ledger takes precedence. A bounded future reconciliation task is proposed; this audit changes none of those records. `versions.env` T1 and the exact T1 ledger row are foundation compatibility assertions, not proof the whole project is currently in T1.

## Current-state inventory

**151 distinct records**, separate from **499 source artifacts**. These include **79 milestones**, seven acceptance gates, two decisions, one program workstream, one scope boundary, 13 assignments, one review gate, 12 concerns, 33 ADR records and two architecture proposals. Record boundaries represent phase/assignment/governance outcomes; historical UI proof substeps and individual test cases remain linked artifacts. A summary can intentionally contain many source records. Full counts by exact source status and every workstream are in the preview JSON.

| Area | Inventory and current classification |
| --- | --- |
| Platform foundation | F0–F4, K0–K4, C0–C5, T0–T6, V0, RT-1/RT-1A, RNM/RNP, C6, C7A/B completed per ledger; UI A–E complete within declared scope; historical summary only |
| V1 | Complete; six named acceptance gaps closed; no active V1 acceptance blocker. ADR-031 environment edge and orphan reclamation remain separately deferred; fixture cleanup is separate debt |
| K5 | Parent and K5A–E complete/natural-live proven; K5F guided host enrollment planned, not implemented and not an R0 acceptance gate |
| Recording | RMA parent in progress, UTCP Core / R0-Critical. A–D complete in their recorded proof scopes; E–H not started. Exact canonical next action: `RMA_E_S3_COMPATIBLE_ARCHIVE_ADAPTER_AND_DETERMINISTIC_MINIO_PROOF_IMPLEMENTATION_DESIGN` |
| Future platform | A0 reference consumers eligible with bounded scope; R0 acceptance parent; future handoff/media/insights boundaries and WAC/WAC-0–10 unscheduled/post-core records retained without a new execution mandate |
| GN infrastructure | Readiness proposal, G0 fake contracts, G1 observations, G2 conditional effects, real API proof, lifecycle v2, active profiles and canonical Laravel/PostgreSQL foundation remain scoped developer/historical evidence; no live authority handover |
| GN future | G3 five-corridor writer transfer is an unauthorized proposal requiring reconciliation with broader Go-native cutover; G4/G5 original future evaluations excluded from operational import without claiming formal supersession |
| Go-native program | Target selected; GO-CP0 ADR still proposed, formal adoption not established. GO-CP1 E1 canonical kernel, E2 Asterisk policy parity, E3 trusted recovery, E4 executor journal reported developer verified; independent acceptance/operator acceptance/production integration not established |
| GO-CP2–6 | Actual proposed stages: identity/HTTP/realtime; registry/infrastructure; telephony/signaling; recording/remaining domain/API parity; controlled handover/Laravel retirement. CP2 E1 isolated identity/tenancy is the next backend recommendation, not execution authority |
| Concerns | Checker mismatch; Vue scanner finding; untracked preservation; stale summary reconciliation are candidate tasks. External replica writers, immutable publication, executor storage, real Kubernetes integration, session compatibility and production cutover remain acceptance checklists within owning workstreams |

GO-CP1 report words `IMPLEMENTED_WITH_LIMITATIONS`, `ASTERISK_POLICY_PARITY_VERIFIED`, `TRUSTED_RECOVERY_VERIFIED` and `EXECUTOR_JOURNAL_VERIFIED` retain their actual developer scope. E4 retained-store process/container recovery does not prove arbitrary node/storage failure or Kubernetes Pod rescheduling. Later E2–E4 reports can address earlier limitations without retroactively rewriting E1 evidence. FreeSWITCH domain fencing/restoration is not established by infrastructure effects.

Evidence classification counts: source-recorded 86; ledger live-proof seven; ledger closed six; ledger acceptance-proof four; reported developer verification 12; versioned governance records 33; three distinct proposal/planned classifications one each. A source-recorded row is not an independent verification claim.

## Kaneo inspection and proposed representation

Observed read snapshot: **2026-10-22 08:26:26 UTC**. Existing server alias `kaneo_local`; server version, authentication scopes, server rate limits and integration/automation settings are not exposed. Authentication and member reads succeeded. Four member entries were observed without publishing personal details. The connection sees two projects; unrelated task contents were not inventoried.

Reuse workspace **Personal Projects**, ID `30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il`, and existing private project **Unified Telephony Control Plane**, slug `UTCP`, ID `hrilr4371g33s0iw6lth3o7v`. No new workspace/project/board is needed. Dedicated columns read confirms **To Do**, **In Progress**, **In Review**, **Done**, slugs `to-do`, `in-progress`, `in-review`, `done`. Done is final. Project task listing with limit 100 returns total zero, one task page and one related page; each column plus archived/planned arrays is empty. Workspace-scoped UTCP task search also returns zero. **No existing UTCP tasks, matches or collisions** at this snapshot; refresh before creates.

**38 discovered tools: 17 read and 21 write-capable.** Only read calls were invoked. Native exposed relations: subtask (parent source → child target), blocks (directional), related (bidirectional). No custom-field, milestone, column-configuration, transaction/CAS or integration-administration API is exposed. Existing workspace-level labels Blocked, Roadmap planning, Proposed / not adopted and Historical reference can support a later approved import. Titles, timestamps and labels alone never establish identity or formal acceptance. No remote write permission was tested.

The smallest useful organization is one existing UTCP project with parent tasks for program/phase rollups. Workstream is a parent plus description property; no project per phase or new workstream labels are required. Use existing priority `no-priority`, no assignees and no dates where sources do not establish them. Preserve R0-Critical and RMA-E priority in source-derived description text without inventing numerical or Kaneo urgency.

## Migration dispositions and preview

| Disposition | Source records | Proposed remote result | Omission risk / treatment |
| --- | ---: | --- | --- |
| M1 active task | 10 | 10 scoped tasks; K5F/A0 remain future candidates | Actionable work becomes invisible; import only approved subset |
| M2 parent/milestone | 10 | 10 parent tasks using native task relations | Lost program context; avoid falsely closing a parent from child developer tests |
| M3 historical summary | 63 | Six concise summaries: platform, V1, K5, RMA A–D, GN, GO-CP1 | Lost prerequisite history; retain every source ID and proof link |
| M4 Git and link | 32 | No separate remote record | Architectural/specification/checklist authority belongs in Git |
| M5 Git governance | 33 | No separate remote record | ADR/governance cannot be replaced by an operational column |
| M6 exclude | 3 | No remote record; Git retained | Prevent obsolete alternative plans from becoming competing tasks |

All remote creation requires separate approval. **26 total proposed units**, **13 initial candidates**, **six historical summary units**, **68 source records with no remote create**, zero duplicate IDs/keys and zero existing remote matches. The 63 historical source records are not 63 proposed tasks. File-level retention includes additional supporting artifacts and validators beyond these 151 record-level dispositions.

Suggested initial subset: RMA parent/E design; Go-native parent/CP0/CP1/independent CP1 review/CP1 evidence summary/CP2/CP2 E1; checker finding; Vue finding; artifact preservation; stale roadmap summary reconciliation. This is an approval proposal, not an authorization, work queue reorder or requirement to import all 13. Review may remove redundant CP1 rollup/summary representation.

## Status, acceptance and authority ownership

Operational column and source status are separate. Planned/deferred → To Do with explicit disposition; authorized → To Do with authorization property; in progress → In Progress; developer-tested/ready for independent review → In Review with actor/evidence limits. Blocked uses its source column plus Blocked label and reason. Superseded/cancelled retains reason and decision; no automatic deletion/Done. Independently accepted, operator accepted and complete/deployed each require separate actor/scope evidence; final Done requires the approved task closure criteria. Historical source completion remains text until historical import policy is approved; this audit does not manufacture new operator acceptance.

The crosswalk explicitly covers all requested states, the four GO-CP1 assignments, deployment and proposed/adopted ADRs. Missing independent/operator evidence means **not established**, not asserted rejection or proof nobody reviewed it.

Through K4, keep repository authority and project Kaneo as a reconciled operational view (Option A). After explicit K5 approval, recommend **B with dimension-specific C**: Kaneo owns assignment execution status; Git owns architecture, specification, tests, evidence, formal acceptance, architectural adoption and canonical phase/next-action decisions. A reviewer records independent review; an operator records operator acceptance. Implementer self-tests never create formal acceptance. The ledger's later snapshot/generated role requires an explicit separate governance choice; retirement is not predetermined. Never synchronize the same field in both directions.

## Dependencies and migration correctness

Typed dependency edges and a compact diagram are in the execution plan. V1/K5 and completed RMA-C/D supply baseline contracts for archive work; RMA completion, K5 and A0 participate in R0 acceptance. K5F is a later enhancement. CP1 baseline → CP2 → CP3 → CP4 → CP5 is the proposed implementation sequence; CP6 additionally requires independent CP1–5 acceptance, adopted architecture, explicit handover authority and production prerequisites. CP4 domain callback proof is an eventual CP3 authority-transfer gate rather than a circular CP3 implementation blocker. GO-CP2 E1 does **not** depend on GN-G3. RMA precedence over A0 is preferred sequencing, not a fabricated technical blocker. No confirmed hard implementation cycle was found.

Future migration identity is the exact `UTCP-Migration-Key` description marker plus repository mapping manifest; titles are display only. Each unit retains all constituent source IDs, source revision and file hashes, task/project IDs, expected fields, actions and reconciliation state. Full remote pagination and deferred descriptions must be reconciled before each create. One exact match means reconcile, multiple matches stop, zero matches merely permits an approved candidate. Read-after-write and durable returned-ID journaling resolve timeout/partial import ambiguity. No exposed CAS or transaction exists: single-writer import window and stale-payload checks are required, with acknowledged concurrent-writer limits.

## Staged execution, risk and operator gates

K0 this audit; K1 refresh/freeze exact mapping and payloads without writes; K2 separately authorized selected operational import; K3 separately approved historical summaries/relations; K4 dual-read discrepancy measurement without authority transfer; K5 explicit dimension-specific authority decision and reviewed instruction/validator changes; K6 approved worker update protocol. K2/K3 may combine only after exact scope approval. No migration or authority-transfer step follows automatically from this verdict.

Material risks and grounded exposures are documented with prevention, detection and recovery: historical loss, duplicate creates, incorrect acceptance/ADR mapping, RMA priority displacement, untracked evidence links, existing/concurrent collisions, partial imports/timeouts, connector limits, secrets, stale and concurrent updates, unrelated project access, destructive rollback and tracker-dependent CI. No numeric probability is invented.

Rollback preserves original Git evidence, returned IDs and payload hashes. Stop partial imports, inventory committed objects, reconcile unknown results before retrying, and resume from a durable mapping. Do not automatically delete tasks, comments or relationships; corrective remote changes require separate authority. Source rollback never deletes history. Unknown integration automation safety must be resolved through existing administrative/operator evidence before any write, without probing by a prohibited mutation.

Operator prerequisites for completing this audit: **None.** Before K2: approve exact payload subset and history/closure policy; confirm project automation safety and writer exclusivity; resolve publication of untracked source artifacts; confirm permissions in the later authorized preflight. Formal ADR/production authority applies to its own workstream gates, not to the ability to prepare K1.

## Validation and handoff

Read-only validation commands:

```sh
make repository-hygiene phase-status-consistency-check
make phase-status-consistency-check-test
python3 docs/architecture/proposals/kaneo-tracker/reproduce-preview.py --check
python3 docs/architecture/proposals/kaneo-tracker/validate-audit.py
git diff --check
```

The reproducer derives record membership, paths, hashes, status dimensions and totals from the frozen inventory plus explicit mapping rules and sanitized connector snapshot. It writes JSON only to stdout when run without `--check`; it never calls Kaneo. This reproduces the audit snapshot, not a live discovery claim. The validator verifies unique IDs, complete dispositions and dimensions, dependency references/acyclic hard edges, source/evidence/local links, JSON/Python syntax, whitespace, new-artifact secret patterns and SHA-256 preservation. It verifies branch/HEAD and absence of tracked/staged modifications. No general semantic approval validator exists in the repository; evidence judgement remains necessary.

Required hygiene/consistency checks and eight phase-consistency regression cases pass. The final detailed integrity result is recorded in [validation results](kaneo-tracker/validation-results.md). Existing failures reproduced separately: `make runtime-engine-config-check` exits 2 because ordinary API/scheduler/reconciler workers carry Kubernetes API-client labels rejected by its checker; `make secret-scan` exits 2 on `apps/web/src/views/ExternalTrunkDetailView.vue:292`. Neither was repaired or used to reopen accepted milestones. The audit packet itself passes the same secret-pattern checks. The comprehensive GO-CP1 E4 runtime verifier was not rerun for documentation.

**Current-State Ledger Impact: NO_CHANGE_REQUIRED.** This proposal does not change accepted phase classifications, blockers, topology, proof classification or canonical engineering next action. **Phase-Status Consistency Check: PASS.** No production changes, Kaneo writes, existing tracker modifications, staging, commits or pushes. Scope changes: none. Deferred: every migration write, source preservation commit, baseline checker/scanner repair, independent technical acceptance, ADR adoption, integration and production cutover. Smallest next action: run the single [K1 mapping-freeze assignment](kaneo-tracker/next-assignment.md) to produce the exact K2 approval packet.
