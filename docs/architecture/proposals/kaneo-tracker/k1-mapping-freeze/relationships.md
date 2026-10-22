# Frozen relationships and gate interpretation

Four hierarchy links and one contextual relation are approved candidates. No active hard blocking edge is supported for this exact initial subset. Do not invent `blocks` links from historical completed milestones, from GN-G3 to CP2 E1, or from future production acceptance gates to every isolated test.

| Order | Type | Source key | Target key | Meaning |
| --- | --- | --- | --- | --- |
| 1 | related | `utcp:go-cp1` | `utcp:go-cp2-e1` | E4 developer-verified implementation baseline and handoff; not an invented independent-review prerequisite for isolated E1. CP1 acceptance remains a later formal gate. |
| 2 | subtask | `utcp:go-native` | `utcp:go-cp0` | Hierarchy only; parent is not a blocking prerequisite. |
| 3 | subtask | `utcp:go-native` | `utcp:go-cp1` | Hierarchy only; parent is not a blocking prerequisite. |
| 4 | subtask | `utcp:go-native` | `utcp:go-cp2-e1` | Hierarchy only; parent is not a blocking prerequisite. |
| 5 | subtask | `utcp:rma` | `utcp:rma-e` | Hierarchy only; parent is not a blocking prerequisite. |

Create tasks in payload import_order (parents precede children). After every task is journaled and verified, attach labels in frozen order, then resolve relationship templates against verified task IDs and deduplicate by `(source ID, type, target ID)`. Subtask source is parent. Related is bidirectional; reverse-endpoint matches are the same relation. Read get_task_relations on both endpoints after each relation and keep returned relation IDs.

The complete original dependency graph remains in the historical migration preview. Every selected task carries incoming source-level dependencies with typed endpoints. Endpoints absent from this initial import remain Git references, not unresolved remote placeholders. Satisfied RMA-C/D, V1 and K5E are context. GO-CP1 E4 is the implementation baseline for isolated CP2 E1. Independent CP1–5 acceptance, adopted ADR, durable executor deployment, immutable configuration, real Kubernetes and external-writer policy belong to later CP3/CP6 acceptance, not a fabricated initial blocking chain. The Vue finding gates later security acceptance. CP2 broad HTTP/realtime scope remains deferred.
