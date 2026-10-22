# Exact K2 approval boundary

**Not approved. No writes performed.** Final proposed successor root:
`d093be6eb0f9d8c96d7485456610f153e30614114f9bfe59ced03097ba3c1efb`.
Original K1 root `ee7432eb43ab8380532c4a33ef9d1f8a91e12fce6a71ae7664fdcf1e0c721a05` remains intact; approve neither title nor old digest as a
substitute for this corrected version. [Exact successor](freeze-v2/payload-preview.json)
and [one-status diff](freeze-v2/change-report.json).

Target workspace Personal Projects / 30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il;
project Unified Telephony Control Plane / UTCP / hrilr4371g33s0iw6lth3o7v.

Scope: ten creates, four existing-label attachments and five relations. No updates,
Done, deletes, archives, comments, new labels, columns, projects or integrations.
Seven To Do; two In Progress (RMA and Go-native parents); one In Review (CP1).
No owners/dates or invented priority. Exact import order is frozen:

1. utcp:rma
2. utcp:go-native
3. utcp:rma-e
4. utcp:go-cp0
5. utcp:go-cp1
6. utcp:go-cp2-e1
7. utcp:checker
8. utcp:vue-scanner
9. utcp:preservation
10. utcp:roadmap-residue

Labels unchanged: Roadmap planning on Go-native; Proposed / not adopted on
Go-native, CP0 and CP2 E1. Relations unchanged: RMA→RMA-E and Go-native→CP0,
CP1, CP2 E1 subtasks; CP1↔CP2 E1 related. Zero hard blocks. Original deferrals,
merges, status dimensions and RMA-E priority remain intact.

## Required operator/admin evidence

- Explicit approval of the successor root, exact 19-operation scope, columns and
  no-Done closure policy. Presence of this assignment is explicitly not approval.
- Either verified immutable evidence access/publication with exact-byte index,
  or explicit acceptance of the frozen path/hash representation and current access
  limitation. Do not silently change descriptions to add links.
- Administrative confirmation of create-task, attach-label and create-relation
  permission for this account/project; MCP exposes no suitable permission read API.
- Integration-trigger review/confirmation for these operations. No administration
  read API is exposed; tool presence cannot prove absence of automation.
- One designated writer and exclusive import window, recorded before mutations.
- Explicit authorization to record the bounded MIGRATING decision in the policy,
  preserving prior and new control hashes. No ACTIVE or K4 acceptance authorization.

Git staging/commit/push is separately gated: approve exact publication batches,
any K2 packet preservation scope and a concrete destination. Kaneo approval alone
never authorizes publishing Git changes. No blanket git add, speculative permission
writes, concurrent task writers or destructive rollback.

[Evidence index](approval-evidence-index.json) records every missing gate.
[Publication report](EVIDENCE_PUBLICATION_REPORT.md) gives the concrete source plan.
When all gates pass, use the K1 execution checklist with this successor payload/root,
its unchanged source/mapping manifests and the explicit change report. Preserve all
original K1 bytes. K1's validator remains a preflight requirement; additionally run
validate-preflight.py for the successor. No silent substitution of approval hashes.

Validation scope: the original K1 validator passed before K2 files were created. `validate-preflight.py` reruns every original check through an in-memory adapter that extends only its historical new-file whitelist to this authorized K2 directory. The frozen K1 script is unchanged. Use this scoped validator on resume; do not alter the original freeze to accommodate new audit files.
