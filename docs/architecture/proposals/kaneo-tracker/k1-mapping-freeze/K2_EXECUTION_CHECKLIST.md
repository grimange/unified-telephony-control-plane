# K2 execution and non-destructive recovery

## Exact approval boundary

K1 performs no writes and leaves SELECTED_NOT_ACTIVE. Before any K2 mutation, obtain explicit operator authorization identifying root manifest digest `ee7432eb43ab8380532c4a33ef9d1f8a91e12fce6a71ae7664fdcf1e0c721a05`, project `hrilr4371g33s0iw6lth3o7v`, all ten task keys, four label attachments, five relations and the no-Done/no-update closure policy. An instruction to read or prepare this packet is not import authorization. Do not treat the K2 prompt alone as approval.

Approve one exclusive import window and designated writer. The operator must confirm relevant create-task, attach-label and create-relation permission through existing administrative evidence; read success and tool presence do not prove permission. No speculative test creates. MCP exposes no permission or integration-configuration query. Obtain an operator/admin record that importing these tasks/labels/relations cannot trigger unapproved GitHub/Gitea or other integration actions. If that cannot be established, pause writes.

Approve evidence publication: [publication plan](evidence-publication.md). Do not stage/commit/push without separate authority. Record the accepted immutable publication index and verify every exact source hash. Adding URLs to frozen task text changes payloads and requires a newly reviewed freeze; do not substitute URLs at runtime. Operator must explicitly accept repository path/hash locators in this exact packet or request that re-freeze after publication. Never claim a local file is remotely published.

## Preflight and activation record

1. Read root/docs instructions, adopted policy and this packet. Verify canonical JSON digest of payload-hashes.json equals FREEZE_SHA256 and the operator-approved digest, then verify packet/mapping/source hashes and exact schema definitions from the refreshed MCP interface. Run validate-packet.py. Do not recompute approval hashes to bless drift.
2. Confirm exact workspace/project IDs, slug UTCP, private/unarchived identity and all four column IDs/slugs; status arguments are column slugs. Confirm reusable label IDs/names/taskId=null, undeleted. Unexpected identity or column/label drift pauses affected writes for review.
3. Read all task pages and related pages per task page, planned/archived arrays, deferred full descriptions and all relevant relations. Search is corroboration only and must include workspaceId plus projectId. Compare exact full-line UTCP-Migration-Key markers and stored mapping IDs. Similar titles require investigation. Do not create unresolved/duplicated identities.
4. Verify current source hashes against source-hashes.json (all 505 catalog/governance/task-source inputs), per-task source sets, payload digests and dependency endpoints. A source-byte change pauses affected units and their dependent relations. Non-impacting HEAD changes still require documented revision review; frozen descriptions intentionally retain the approved source revision/hash. Unrelated baseline files are preservation evidence, not extra execution-status authority.
5. Before writes, record the separately approved K2 authorization and exact scope durably in the policy activation record, entering MIGRATING. This is a necessary K2 governance edit, requiring explicit authorization in that assignment; K1 leaves the policy untouched. A future policy authorization entry is an expected control-file change: preserve the approved pre-transition hash and the separately reviewed post-transition hash in the K2 journal. It is not permission to rehash task source descriptions or other changed source files. If K2 authorization does not cover this transition, stop before remote writes.
6. Initialize a new durable K2 journal (new task-owned directory, preserve this frozen packet). Record approval digest, run/writer, source/publication manifest, permission/trigger approvals and preflight remote snapshot. Capture IDs immediately after every write, before another operation. No transaction/CAS/atomic upsert is exposed; one writer and exclusive window are required.

## Ordered import

For each task in import_order, re-read/reconcile the exact key immediately before mutation. No match allows only the approved create_arguments to create_task. One identical match is adopted into the journal after full read comparison, not recreated or updated; any incompatible title/status/description/priority/project is a conflict requiring operator decision. Multiple matches block the key and its relations. Do not update existing tasks under this create-only packet.

After each successful create response, persist returned task ID, marker, approved payload hash, source-set hash, timestamp and raw outcome category in the sanitized journal. Immediately get_task and verify exact project/key/title/description/status/priority, no assignee/dates, and frozen source markers. Only a confirmed read-back permits the next create. If the durable journal cannot be written, stop all further writes and recover identity by reads.

After all task read-backs, attach four existing labels in payload array order, resolving only explicit task-ID placeholders. Verify full task/related labels after each attachment and record returned attachment ID if exposed. Then create the five relations in frozen relation order, only after both endpoints have verified IDs. Deduplicate source/type/target (related is undirected), resolve symbolic references to exact IDs, journal the concrete arguments and their digest before call, then record returned relation ID and verify get_task_relations for both endpoints. Do not create hierarchy from title matching.

Final full read reconciliation must show all ten approved keys exactly once, four approved label memberships and five relations, with zero missing/misclassified/orphaned approved records and zero unexpected writes. Record expected retained pre-existing items if any, without mutating them. This K2 proof is not K4 acceptance or K5 activation. Keep MIGRATING; any RECONCILED_PENDING_ACTIVATION transition requires the later accepted K4 gate, and ACTIVE requires separate explicit operator K5 authorization.

## Stale or uncertain results

| Observation | Immediate behavior | Recovery / decision |
| --- | --- | --- |
| Source/payload hash differs | Pause affected task and dependent relations; preserve approved hashes | Re-review changed payload and approve a new manifest; never silently recompute |
| Create timeout, malformed response or transport failure | Journal UNKNOWN; stop writes, do not retry | Full fresh marker scan including deferred/planned/archived data and mapped IDs; one exact task gets journaled and read-verified; duplicates/conflict stop |
| No match after ambiguous create | Absence on one read does not prove noncommit | Keep UNKNOWN until the pending request outcome is resolved by reliable completion/admin evidence and a fresh complete scan; operator explicitly authorizes any later retry under the same key |
| Permission/trigger safety unresolved or denied | Stop before new writes; never probe by creating | Operator/admin establishes actual authority or narrows scope with a new approved packet |
| Remote edit or incompatible state | Preserve reviewer/operator changes; no last-writer-wins | Escalate the exact field/key to its owner; create-only packet authorizes no overwrite |
| Label/relation timeout | Stop and query actual membership/relations | Resume only after deduplication; unresolved outcome remains UNKNOWN |
| Project identity drift / duplicate task key | Stop affected scope; project mismatch stops all writes | Operator resolves identity before any resume |
| Kaneo unavailable | Keep pending intents explicit, never claim success | Recover by reads; builds/tests/CI remain offline; Git remains current tracking authority through migration |

## Non-destructive rollback

Rollback means stop and inventory, not delete. Preserve original sources, approved payload/root hashes, key-to-ID mappings, returned relation IDs, last confirmed remote fields/hash, failed call category and required corrective decision. Separate entries for PLANNED, SENT_UNKNOWN, RETURNED_ID_UNVERIFIED, VERIFIED and CONFLICT; states are import reconciliation only, not task-progress authority. Do not replay a stale intent over independent/operator decisions.

Keep successfully created tasks/labels/relations. Resume forward only after full read reconciliation and authorization still matches. Any corrective field update, relation removal, task archive/delete or scope rollback requires separate explicit approval with before/after review. Connector has no archive API. Never erase Git evidence or automatically delete remote history. Reverting MIGRATING to another governance state also needs an operator-recorded decision; a failed run does not transfer operational authority.
