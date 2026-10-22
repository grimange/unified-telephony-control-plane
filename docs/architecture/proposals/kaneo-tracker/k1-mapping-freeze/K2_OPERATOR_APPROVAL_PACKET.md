# K2 operator approval packet

**K1 verdict: UTCP_KANEO_K1_MAPPING_FREEZE_READY_WITH_CONDITIONS.** No K2 approval has been given. Activation is SELECTED_NOT_ACTIVE.

Expected workspace: Personal Projects `30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il`. Expected private, unarchived project: Unified Telephony Control Plane / UTCP `hrilr4371g33s0iw6lth3o7v`. Reuse all existing columns and labels; create no project or column.

**Approve by root manifest digest:** `ee7432eb43ab8380532c4a33ef9d1f8a91e12fce6a71ae7664fdcf1e0c721a05`.

The root is SHA-256 of canonical payload-hashes.json. Packet digest: `ab40588ccac8a082ecb35b17bc63dd3112dd2891d78299739a85806ebaef5e87`. Source-manifest digest: `10a8dbcf960bbfc8e859315652d1f30c843606bac8da8d54931486a5540ce00e`. Canonicalization is defined in [payload preview](payload-preview.md). The root binds all ten task payload/source digests, full packet (including labels/relations), exact source mapping and candidate decisions.

## Exact proposed writes

Ten create_task calls, four attach_label_to_task calls and five create_task_relation calls: 19 proposed calls, zero executed. Zero existing-task updates, new labels, project/column writes, comments, deletes or Done transitions. The task list and exact scope are in [human preview](payload-preview.md) and [schema-compatible arguments](payload-preview.json).

- `utcp:rma` — Recording and Media Archive — R0-Critical workstream; `in-progress`.
- `utcp:go-native` — Go-native control-plane modernization; `to-do`.
- `utcp:rma-e` — Design RMA-E S3 archive adapter and deterministic MinIO proof; `to-do`.
- `utcp:go-cp0` — Review proposed Go-native ADR for formal operator adoption; `to-do`.
- `utcp:go-cp1` — Review GO-CP1 E1–E4 canonical kernel and executor evidence; `in-review`.
- `utcp:go-cp2-e1` — Proposed GO-CP2 E1 identity and tenancy compatibility; `to-do`.
- `utcp:checker` — Repair runtime-engine checker / accepted K5 API-client label mismatch; `to-do`.
- `utcp:vue-scanner` — Repair precise Vue credential-form scanner finding; `to-do`.
- `utcp:preservation` — Review and preserve modernization and tracker artifacts in source control; `to-do`.
- `utcp:roadmap-residue` — Reconcile stale descriptive RMA/K5 roadmap summaries; `to-do`.

Existing labels reused: Roadmap planning once on Go-native; Proposed / not adopted on Go-native, CP0 and CP2 E1. No Blocked label is attached because no selected task is falsely classified as actively blocked; blocker reasons and later acceptance prerequisites remain explicit. No historical label is needed for a separate CP1 history task because that task is merged.

Relations: RMA→RMA-E subtask; Go-native→CP0, CP1, CP2 E1 subtasks; CP1↔CP2 E1 related. No blocks relation. Relationships carry no acceptance grant. All ten tasks leave owners/dates unset and use required neutral no-priority. RMA-E retains canonical-next-action text and R0-Critical source attribution.

## Excluded and deferred scope

Of the original 13 candidates, ten are included, CP1 independent review plus its E1–E4 historical-summary candidate merge into CP1, and broad CP2 parent defers. Total original 26 remote-unit proposals: ten creates, two merges, fourteen deferred. No old history task is automatically closed. All 151 original source records plus the newly adopted governance decision have exactly one K1 disposition in source-task-mapping.json. M4/M5 Git evidence/governance and M6 exclusions remain preserved; no source deletion.

## Required explicit operator decisions before writes

1. Approve this exact digest, create-only scope, status/closure policy (In Review is developer handoff, no self-acceptance/Done), all labels/relations and consolidation decisions. Import grants no engineering execution authority or technical ADR adoption.
2. Authorize a single writer and exclusive window, plus the bounded Git policy activation entry recording K2 import authorization as MIGRATING. This does not authorize ACTIVE, ledger retirement or K4 acceptance.
3. Confirm actual required task-create, label-attach and relation-create permissions from existing admin evidence, and integration-trigger safety for this project. MCP cannot expose these settings; speculative test writes are forbidden.
4. Accept a verified immutable publication index for the exact sources and this packet, and explicitly accept these frozen path/hash descriptions, or request a new reviewed payload freeze adding published URLs. Do not silently change descriptions after approval.
5. Authorize the K2 journal/report files and read-after-write verification, without deletion, rollback mutations, staging/commit/push or production changes.

K2 preflight refresh and routine execution are future work, not K1 defects. The currently unresolved publication, permission and trigger evidence are actual conditions. No operator action is needed to complete K1 itself. See [ordered procedure and failure recovery](K2_EXECUTION_CHECKLIST.md) and the [next assignment](K2_CODEX_PROMPT.md).
