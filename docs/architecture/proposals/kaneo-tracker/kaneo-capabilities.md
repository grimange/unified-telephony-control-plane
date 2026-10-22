# Configured Kaneo capability audit

Observed 2026-10-22 08:26:26 UTC. Existing server alias: `kaneo_local`. Server software/version, transport endpoint, auth credential type/scopes, token expiration and server rate-limit policy are not exposed by the inspected interface. No configuration was read or changed. `whoami` and membership reads succeeded; personal identities are omitted. Four accessible workspace member entries were returned. Read success does not establish write authorization. Write tools exist but no writes were invoked or tested.

Workspace: **Personal Projects**, ID `30VyoMQZ4LROnW3UUeAYGg8bIF9mj1il`. Two projects were returned with `includeArchived=true`: the relevant existing **Unified Telephony Control Plane**, slug `UTCP`, ID `hrilr4371g33s0iw6lth3o7v`, and one unrelated project whose tasks were not inspected. This connection is not restricted to the UTCP project: every future call must explicitly allowlist workspace and project IDs. The workspace name is an existing container, not an organization naming recommendation.

UTCP is private, unarchived, description empty, `lastTaskNumber=0`. Dedicated column read returns `to-do` (To Do), `in-progress` (In Progress), `in-review` (In Review), `done` (Done; final). Project list response had empty `columns`; dedicated read supplies the authoritative current column snapshot.

`list_tasks(projectId, limit=100, page=1, relatedPage=1)` returned total=0, totalPages=1, relatedTotalPages=1, all four column task arrays empty and archived/planned arrays empty. There are no UTCP tasks, assignees, task labels, comments, relations or subtasks to inspect. Scoped `search(q=UTCP,type=tasks,workspaceId=...)` returned zero results. This corroborates absence within the scoped snapshot; it is not an exhaustive content search of unrelated projects. No UTCP collisions found. Re-read immediately before any later import.

Workspace label read returned four reusable definitions where `taskId=null`: Blocked, Roadmap planning, Proposed / not adopted, Historical reference. The response also contained copies attached to unrelated tasks; these are omitted from the saved snapshot. Reuse workspace-level label IDs, never task-attached copies, and do not infer UTCP label use from workspace existence.

There are **38 discovered tools: 17 read tools and 21 write-capable tools**. The complete exact declarations are in [sanitized read snapshot](kaneo-read-snapshot.json). Tool presence is a connector capability, not evidence a mutation is permitted.

| Migration requirement | Native capability observed/exposed | MCP read | MCP write discovered, never invoked | Limitation / fallback / decision |
| --- | --- | --- | --- | --- |
| Workspace/project | Existing workspace/project objects | list_workspaces, list_projects, get_project | create_project, update_project; no workspace-create tool | Reuse exact IDs; no project creation needed |
| Board/columns | Project has four columns with final flag | list_project_columns | Task status updates only; no column config tool | Reuse existing slugs; Ready and Blocked are description properties/label, no new board needed |
| Tasks/search | Bounded project pages and scoped search | list_tasks, get_task, get_task_by_ticket_id, search | create_task, update_task, update_task_status, move_task, duplicate_task, delete_task | No archived-task read tool separate from list response; empty archived array observed |
| Labels | Workspace definitions and task-linked copies | list_workspace_labels plus related task pages | create_label, attach_label_to_task, detach_label_from_task, delete_label | Reuse definitions; no workstream label creation needed initially |
| Priorities | Enumerated no-priority/low/medium/high/urgent | list filters and task details | create/update task | Source ordinal priorities absent; use no-priority. Preserve R0-Critical and canonical-next-action text without inventing urgency |
| Assignees | Workspace member identity field | whoami, list_workspace_members | update_task_assignee, create/update task | Leave unassigned until explicitly named; requested AI role is not a workspace user |
| Hierarchy/dependencies | subtask, blocks, related relation types | get_task_relations | create_task_relation, delete_task_relation | Parent is source, child target; blocks directional; related bidirectional. Future writes require approval and complete endpoint mapping |
| Comments/history | Per-task comments and activity | list_task_comments, list_task_activity | create/update/delete task comment | No task exists to sample; do not fabricate history. Keep formal decisions in Git |
| Evidence/custom status dimensions | Task description string | get_task | create/update description | No custom-field read/write tool exposed. Use versioned description section and Git proof links |
| Milestones | No native milestone API exposed | None specific | None specific | Parent tasks plus explicit type=milestone; no due dates invented |
| Time tracking | Entries supported | get_time_entry, list_task_time_entries | create_time_entry, update_time_entry | Not required; never import test duration as effort |
| Permission/admin/integrations | Current identity/members readable | No scope/role-policy/integration-config query | No admin/config tool | GitHub/Gitea integration and automation triggers cannot be established. Operator must inspect automation before K2 writes |
| Concurrency/versioning | update_task fetches, merges, then full update | Fresh get_task | No conditional revision/ETag parameter exposed | Potential lost updates: one import writer, before/after hashes and stop on drift; no optimistic-lock guarantee |
| Pagination/rate limits | list_tasks default 50, maximum 100 | page + pagination.totalPages; relatedPage + relatedTotalPages per task page | N/A | Follow both dimensions; search limit 1–50 and no pagination argument, so search alone cannot prove inventory completeness. Rate limit not exposed; bounded reads, backoff and resume on transient errors |

## Exact read-only tool allowlist

- `get_project`
- `get_task`
- `get_task_by_ticket_id`
- `get_task_relations`
- `get_time_entry`
- `list_notifications`
- `list_project_columns`
- `list_projects`
- `list_task_activity`
- `list_task_comments`
- `list_task_time_entries`
- `list_tasks`
- `list_workspace_labels`
- `list_workspace_members`
- `list_workspaces`
- `search`
- `whoami`

## Exact write-capable boundary — discovery only

- `attach_label_to_task`
- `create_label`
- `create_project`
- `create_task`
- `create_task_comment`
- `create_task_relation`
- `create_time_entry`
- `delete_label`
- `delete_task`
- `delete_task_comment`
- `delete_task_relation`
- `detach_label_from_task`
- `duplicate_task`
- `move_task`
- `update_project`
- `update_task`
- `update_task_assignee`
- `update_task_comment`
- `update_task_due_date`
- `update_task_status`
- `update_time_entry`

Read calls actually invoked: list_workspaces, list_projects (including archived), list_workspace_labels, whoami, list_workspace_members, get_project, list_project_columns, list_tasks, search. Task-specific reads were unnecessary because the project is empty. Native capabilities not exposed by MCP remain unknown; no external feature comparison was used. Writes, connection reconfiguration and integration automations: zero.
