# UI-D19 Runtime Reconciliation Live Implementation

## Scope

UI-D19 adds the bounded read-only Runtime Reconciliation operational surface on top of the canonical UI-D18 APIs:

- `GET /api/v1/admin/runtime-reconciliations`
- `GET /api/v1/admin/runtime-reconciliations/{runtimeReconciliation}`

The slice includes the Runtime Reconciliation list, pagination, backend-supported filters, selected detail loading, a tenant private realtime channel, metadata-only reconciliation notifications, shared Echo-client integration, request-budget tests, and repository evidence.

It does not add manual reconciliation, retry, replay, repair, approval, delete, Audit API, Kubernetes changes, a second socket client, polling, browser persistence, or a Playwright MCP proof.

## Canonical Snapshot Authority

The frontend reads Runtime Reconciliation state only from the canonical UI-D18 APIs. Notifications are invalidation signals only:

```text
runtime_reconciliation.* notification
-> reread canonical list snapshot
-> reread selected detail only when the selected reconciliation matches
```

The browser never applies notification fields as reconciliation state.

## Canonical Transition Producer

`App\RuntimeEngine\Reconciliation\ReconciliationRepository` is the canonical current-state persistence authority for `runtime_reconciliation_states`.

UI-D19 adds transactional reconciliation aggregate outbox production to the repository transition methods:

- `ensureTarget()` emits `runtime_reconciliation.created` for a new state row.
- `ensureTarget()` emits `runtime_reconciliation.drift_detected` when an existing target's desired generation advances.
- `wakeTarget()` emits `runtime_reconciliation.drift_detected` for an existing target wake, or `runtime_reconciliation.created` for a new target.
- `claimDue()` emits `runtime_reconciliation.reconciliation_started` for claimed rows.
- `markResult()` emits one deterministic event for the resulting lifecycle transition.

Supported repository result events are:

- `runtime_reconciliation.converged`
- `runtime_reconciliation.operation_required`
- `runtime_reconciliation.retry_scheduled`
- `runtime_reconciliation.failed`
- `runtime_reconciliation.drift_detected` as the bounded fallback for a non-terminal waiting result

The event is appended in the same database transaction as the state transition. Idempotent `ensureTarget()` calls with the same desired generation do not produce duplicate reconciliation events.

## Outbox Aggregate and Contract

Canonical Runtime Reconciliation outbox rows use:

```text
aggregate_type = runtime_reconciliation
aggregate_id = runtime_reconciliation_states.id
tenant_id = runtime_reconciliation_states.tenant_id
event_type = runtime_reconciliation.*
```

The outbox payload is bounded to metadata required for browser reread selection:

- `runtime_reconciliation_id`
- `runtime_node_id` only for `target_type = runtime_node`
- `runtime_operation_id` when the transition deterministically links one

No desired state, observed state, status-authority field, generation authority, failure body, exception, credential, endpoint, command, provider response, audit payload, outbox body, or adapter payload is emitted for the browser.

## Private Channel

The tenant-scoped private channel is:

```text
tenant.{tenantId}.runtime-reconciliations
```

`routes/channels.php` authorizes it through the same authority as the snapshot APIs:

```text
valid identity.session
requested tenant equals active session tenant
active tenant membership
AuthorizationService::requireTenant(userId, tenantId, 'runtime.nodes.view')
```

No new capability was introduced and frontend route state is not trusted as an authorization source.

## Browser Notification Envelope

`RuntimeReconciliationOperationalStateChanged` is a queued after-commit browser event.

The stable browser event name is:

```text
runtime-reconciliation.operational-state.changed
```

The envelope contains only:

- `event_type`
- `aggregate_type`
- `aggregate_id`
- `runtime_reconciliation_id`
- `tenant_id`
- `occurred_at`
- `runtime_node_id` when present
- `runtime_operation_id` when present

The aggregate type is `runtime_reconciliation`, and `aggregate_id` equals `runtime_reconciliation_id`.

## Operational Broadcast Bridge

`OperationalBroadcastBridge` now accepts only canonical reconciliation aggregate rows satisfying:

```text
aggregate_type = runtime_reconciliation
event_type begins with runtime_reconciliation.
tenant_id is present
aggregate_id is present
payload.runtime_reconciliation_id equals aggregate_id
```

Matching rows dispatch `RuntimeReconciliationOperationalStateChanged` through the existing queued post-commit path. RuntimeNode, Conference, participant, and Runtime Operation bridge behavior is preserved. Broadcast failure remains isolated in the existing outbox retry and logging lifecycle and does not reverse the reconciliation state transition.

## Producer-Backed Tests

`RuntimeReconciliationRealtimeBroadcastTest` uses a real repository transition flow:

```text
create canonical reconciliation state
-> claim due reconciliation
-> mark operation-required result with a linked Runtime Operation
-> inspect canonical outbox rows
-> dispatch with OutboxDispatcher
-> assert RuntimeReconciliationOperationalStateChanged
```

The primary success path does not synthesize an acceptance outbox row. Coverage also proves channel authorization, exact metadata-only envelopes, strict bridge rejection for unrelated aggregates and malformed reconciliation IDs, rollback behavior, idempotent transition behavior, and broadcast-failure isolation.

## Shared Frontend Connection Integration

The frontend continues to use one shared Echo/Pusher client in `runtimeNodeRealtime.ts`.

Runtime Reconciliation subscriptions are entity-specific handlers layered onto the shared connection authority. The view subscribes only when the session is active, an active tenant exists, `runtime.nodes.view` is granted, and the Runtime Reconciliation route is mounted.

Lifecycle behavior:

- Route leave removes only the Runtime Reconciliation channel and callbacks.
- Tenant switch leaves the old channel, clears selected reconciliation state, loads the new tenant list, and subscribes to the new channel.
- Logout or session rejection leaves active operational channels, disconnects the shared client, and clears reconciliation callbacks and identifiers.
- Generation fencing rejects stale callbacks and old-tenant notifications.

## Route and Navigation

The new read-only AppShell route is:

```text
/operations/runtime-reconciliations
```

Navigation appears under the operational group only when the active tenant grants `runtime.nodes.view`. The route guard reuses the same capability. The route adds no write, retry, replay, repair, approval, delete, or manual reconcile controls.

## List, Filters, Pagination, and Detail UI

`RuntimeReconciliationsView.vue` renders safe canonical fields only:

- reconciliation ID
- target type and identifier
- bounded RuntimeNode reference
- status
- drift state
- desired and observed generations
- attempt count
- last checked, next check, created, and updated timestamps
- safe failure summary when present
- bounded last Runtime Operation reference when present

The list uses backend pagination and does not fetch every page. The visible filters map directly to backend semantics:

- `runtime_node_id`
- `status`
- `target_type`
- `runtime_operation_id`
- `updated_from`
- `updated_to`

Explicit filter changes reset to page one. Notification and reconnect rereads preserve the current page, `per_page`, filters, and backend ordering. Selected detail opens one reconciliation at a time and loads exactly `GET /api/v1/admin/runtime-reconciliations/{id}`.

## Request Budgets

Automated frontend coverage asserts:

```text
initial route:
  list = 1
  details = 0

selected reconciliation:
  selected detail = 1
  unselected details = 0

selected reconciliation event:
  list reread = 1
  selected detail reread = 1
  unrelated details = 0

unrelated reconciliation event:
  list reread = 1
  selected detail reread = 0
  other details = 0

reconnect:
  list reread = 1
  selected detail reread = 1 when selected
  other details = 0
```

No request count scales with the number of reconciliations.

## Notification Handling

Accepted active-tenant reconciliation notifications trigger one canonical list reread. If `runtime_reconciliation_id` matches the selected detail, exactly one selected-detail reread is also issued.

The frontend ignores notifications for the wrong tenant, unsupported aggregate type, malformed reconciliation ID, inactive session, stale route or tenant generation, and inactive subscriptions. Duplicate or out-of-order events remain snapshot-driven because the browser does not apply event state.

## Reconnect and Tenant Lifecycle

Reconnect and material tab resume while the route is mounted reread the list and the selected detail only. Existing canonical rows and selected detail remain visible while stale. Stale presentation clears only after the Runtime Reconciliation channel is subscribed and the required canonical rereads succeed.

Tenant switch leaves the previous channel, clears the selected reconciliation, loads the new list once, and subscribes to the new tenant channel. Late-generation callbacks cannot repopulate the current view.

## Security and Storage Boundary

The implementation does not store Runtime Reconciliation rows, selected details, reconciliation IDs, RuntimeNode IDs, Runtime Operation IDs, tenant IDs, channel names, capabilities, failure summaries, event payloads, or generation state in browser persistence.

Application-owned storage remains limited to established non-sensitive preferences. The bounded Pusher transport cache remains permitted.

The UI does not expose raw desired state, raw observed state, credentials, adapter configuration, endpoint secrets, stack traces, audit payloads, outbox bodies, provider responses, commands, or arbitrary persisted JSON payloads.

## Responsive Behavior

The view uses the existing UI-B/UI-C layout and status components. Narrow-width behavior is covered by source-level frontend tests: heading and live badge can wrap, filters stack, identifiers and generations wrap or truncate accessibly, status and drift include text, pagination remains reachable, selected detail stays within the viewport, and no root-level `overflow-x: hidden` masking is introduced.

## Tests

Added or extended:

```text
apps/api/tests/Feature/ControlPlane/RuntimeReconciliationRealtimeBroadcastTest.php
apps/web/src/realtime/runtimeNodeRealtime.test.ts
apps/web/src/App.test.ts
```

The existing Runtime Reconciliation read API tests remain the canonical snapshot contract coverage.

## Deferred Work

Deferred to later bounded UI-D work:

- natural Playwright MCP proof of the Runtime Reconciliation route, filters, pagination, selected detail, production notifications, scoped rereads, reconnect, route leave, tenant isolation, logout, storage, and responsive behavior
- standalone Audit API and Audit operational view
