# Frozen initial payload review

Dry run; no remote changes. Project `hrilr4371g33s0iw6lth3o7v`. Current authority is Git; activation SELECTED_NOT_ACTIVE. All rows propose CREATE only after separate operator authorization.

| Order | Stable key | Title / outcome | Column slug | Parent |
| --- | --- | --- | --- | --- |
| 1 | `utcp:rma` | Recording and Media Archive — R0-Critical workstream | in-progress | — |
| 2 | `utcp:go-native` | Go-native control-plane modernization | to-do | — |
| 3 | `utcp:rma-e` | Design RMA-E S3 archive adapter and deterministic MinIO proof | to-do | utcp:rma |
| 4 | `utcp:go-cp0` | Review proposed Go-native ADR for formal operator adoption | to-do | utcp:go-native |
| 5 | `utcp:go-cp1` | Review GO-CP1 E1–E4 canonical kernel and executor evidence | in-review | utcp:go-native |
| 6 | `utcp:go-cp2-e1` | Proposed GO-CP2 E1 identity and tenancy compatibility | to-do | utcp:go-native |
| 7 | `utcp:checker` | Repair runtime-engine checker / accepted K5 API-client label mismatch | to-do | — |
| 8 | `utcp:vue-scanner` | Repair precise Vue credential-form scanner finding | to-do | — |
| 9 | `utcp:preservation` | Review and preserve modernization and tracker artifacts in source control | to-do | — |
| 10 | `utcp:roadmap-residue` | Reconcile stale descriptive RMA/K5 roadmap summaries | to-do | — |

Ten creates, zero updates, four existing-label attachments, four subtask relations, one related relation, zero blocks relations, zero label/project/column creations. All tasks use required `priority=no-priority`, no owner, dates or Done. RMA source R0-Critical and canonical priority remain explicit in descriptions.

## Review of all 13 candidates

| Candidate | Decision | Reason |
| --- | --- | --- |
| `utcp:checker` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:go-cp0` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:go-cp1` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:go-cp1-review` | MERGE INTO PARENT | MERGE INTO PARENT: make independent review and scope acceptance the GO-CP1 task outcome; avoid a second review queue record. |
| `utcp:go-cp2` | DEFER | DEFER: broad HTTP/realtime stage remains linked in the modernization parent; import only bounded identity/tenancy E1 now, with that parent. |
| `utcp:go-cp2-e1` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:go-native` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:history-go-cp1` | MERGE INTO PARENT | MERGE INTO PARENT: the GO-CP1 In Review task already needs this exact developer-evidence summary; no separate historical task. |
| `utcp:preservation` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:rma` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:rma-e` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:roadmap-residue` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |
| `utcp:vue-scanner` | INCLUDE | Distinct operational scope with a concrete outcome; source-backed visibility without new execution authority. |

The broad CP2 parent key remains reserved. Its bounded E1 child is deliberately reparented to Go-native for this initial subset; this is not scope expansion. Four E1–E4 source assignments and the review-gate source map to the single CP1 task. No duplicate history/review task is created. Future reparenting requires a reviewed follow-up, not an implicit K2 change.

All other 13 remote-unit proposals remain deferred, including K5/K5F, A0, R0, CP3–CP6 and five other historical summaries. Retain their exact original keys; do not create them in K2. See [all-record mapping](source-task-mapping.json), [candidate decisions](candidate-decisions.json) and [exact arguments](payload-preview.json).

## Hash contract

Use canonical UTF-8 JSON with sorted object keys, compact separators, ensure_ascii=False and no final newline. Array order is significant. Per-task hash covers only exact create_arguments; the packet hash also covers labels, typed relation templates, ordering and source/status metadata. The manifest root digest covers payload-hashes.json canonical bytes. FREEZE_SHA256 contains that root and is the operator approval identity. Never regenerate approval hashes to accept drift.

Relation/label argument templates contain explicit symbolic ID references, not valid final MCP arguments. K2 resolves only those placeholders from verified returned IDs; every other field is frozen. Final resolved call arguments and their digests must be journaled before mutation. Raw create_arguments are directly compatible with the discovered MCP schema: status is a slug, not the database column ID.
