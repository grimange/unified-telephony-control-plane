# UI-D14 - Runtime Operation Live Operations Implementation

Verdict: `UI_D_RUNTIME_OPERATION_LIVE_IMPLEMENTATION_COMPLETE`

Implementation type: bounded repository implementation. Natural Playwright MCP proof was intentionally not run.
Starting commit: `686a475` (`feat(api): add runtime operation read endpoints`).
Phase marker: `UTCP_PHASE=T1` unchanged.

## Scope

UI-D14 extends the already live-proven shared Reverb/Echo corridor to Runtime Operations:

```text
canonical runtime_operation.* outbox event
-> queued notification-only broadcast
-> tenant-authorized private Reverb channel
-> shared frontend Echo connection
-> canonical Runtime Operation list/detail reread
```

The implementation is read-only. It adds no operation retry, cancellation, replay, repair, manual
reconciliation, creation, deletion, audit view, reconciliation view, polling fallback, feature gate, or second
management authority.

## Canonical Runtime Operation APIs

Browser state is loaded only through the UI-D13 canonical APIs:

```text
GET /api/v1/admin/runtime-operations
GET /api/v1/admin/runtime-operations/{runtimeOperation}
```

The list response is modeled as:

```text
runtime_operations
pagination.page
pagination.per_page
pagination.total
pagination.has_more
```

The detail response is modeled as:

```text
runtime_operation
```

The frontend API client uses explicit types for operation identifiers, RuntimeNode references, aggregate
references, operation type, status, attempts, priority, correlation/request/causation identifiers, safe failure
shape, lifecycle timestamps, and bounded reconciliation references. Raw payloads, idempotency keys, lease data,
unsafe failure text, provider responses, commands, credentials, endpoints, and outbox bodies are not modeled or
displayed.

## Channel Authorization

The private channel is:

```text
tenant.{tenantId}.runtime-operations
```

It is authorized through the same authority as the canonical Runtime Operation read APIs:

```text
valid identity.session
requested tenant == session active tenant
tenant membership
AuthorizationService::requireTenant(userId, tenantId, runtime.nodes.view)
```

No new capability is introduced. The frontend route and navigation are also gated by `runtime.nodes.view`.
Backend tests cover active tenant success, wrong tenant rejection, missing capability rejection, absent session
rejection, invalid session-version rejection, and suspended-session rejection.

## Notification Envelope

The single queued after-commit event is:

```text
RuntimeOperationOperationalStateChanged
```

The stable browser event name is:

```text
runtime-operation.operational-state.changed
```

The notification-only envelope contains:

```text
event_type
aggregate_type
aggregate_id
runtime_operation_id
tenant_id
occurred_at
runtime_node_id (only when deterministically available)
```

`aggregate_type` is always `runtime_operation`. The Runtime Operation identifier is deterministic and matches
the aggregate identifier accepted from the canonical outbox row. `runtime_node_id` is included only when a
string value exists in the canonical event payload.

The envelope does not contain status authority, raw operation payload, requested payload, result payload,
failure body, stack traces, credentials, endpoints, commands, provider responses, lease data, or the full
outbox body.

## Outbox Bridge

`OperationalBroadcastBridge` now accepts canonical outbox rows only when:

```text
aggregate_type = runtime_operation
event_type starts with runtime_operation.
tenant_id is non-empty
aggregate_id is non-empty
```

Matching rows dispatch `RuntimeOperationOperationalStateChanged` through the existing queued, after-commit
broadcast path. Nonmatching aggregate types and event prefixes do not broadcast. Broadcast failure remains
isolated in the existing outbox dispatch retry/logging path and does not reverse the committed
`runtime_operations` mutation.

Existing RuntimeNode and Conference bridge behavior is preserved.

## Shared Frontend Connection

The existing shared realtime authority still owns one Echo/Pusher client. It now supports three
entity-specific tenant channels:

```text
tenant.{tenantId}.runtime-nodes
tenant.{tenantId}.conferences
tenant.{tenantId}.runtime-operations
```

Runtime Operation handlers are entity-specific. Connection ownership, socket lifecycle, reconnect generation,
subscription readiness, and stale-state clearing remain centralized in `runtimeNodeRealtime.ts`.

The Runtime Operations view subscribes only when the route is mounted, the session is active, an active tenant
exists, and `runtime.nodes.view` is granted. Route leave removes Runtime Operation callbacks and leaves only the
Runtime Operation channel while preserving the shared socket for any other mounted operational subscription.
Logout/session rejection disconnects the shared client and clears callback state.

## Route and Navigation

The new read-only route is:

```text
/operations/runtime-operations
```

It is exposed under the operational navigation group only when the active tenant grants
`runtime.nodes.view`. Route guards use the existing capability convention. No write, retry, cancellation,
repair, replay, recovery, or manual reconciliation controls are present.

## List, Filters, Pagination, and Detail

The list displays safe canonical fields:

- Runtime Operation identifier.
- RuntimeNode name or identifier.
- Operation type.
- Status.
- Attempt count.
- Priority.
- Correlation identifier.
- Created, started, completed, and cancelled timestamps.
- Safe failure summary when present.

The view uses backend pagination and never fetches every page into the browser. Supported filters map exactly
to backend query parameters:

```text
runtime_node_id
status
operation_type
created_from
created_to
correlation_id
```

Explicit filter changes reset to page 1. Pagination preserves filters. Notification rereads preserve the
current page, `per_page`, active filters, and backend ordering.

Selecting a Runtime Operation loads exactly:

```text
GET /api/v1/admin/runtime-operations/{selectedId}
```

No detail request is made for unselected rows.

## Notification Handling

Accepted active-tenant Runtime Operation notifications are treated as invalidations only:

```text
Runtime Operation list reread: 1
selected Runtime Operation detail reread: 1 when event.runtime_operation_id == selected id
unselected detail rereads: 0
```

The handler ignores wrong-tenant notifications, unsupported aggregate types, malformed Runtime Operation
identifiers, aggregate/id mismatches, inactive sessions, inactive subscriptions, and late generation callbacks.
No notification field is applied as operation state.

## Reconnect and Resume

On reconnect or material tab resume while the Runtime Operations view is active:

```text
Runtime Operation list reread: 1
selected Runtime Operation detail reread: 1 when selected
unselected detail rereads: 0
```

Existing canonical rows and selected detail remain visible while stale. Stale clears only after the active
Runtime Operations subscription is ready and canonical rereads succeed.

## Tenant Switch and Logout

Tenant switch leaves the old Runtime Operations channel, clears the selected operation, rereads the list for
the new tenant, subscribes to the new tenant channel, and ignores old-generation callbacks. Logout/session
rejection leaves active channels, disconnects the one shared client, and clears operation callback state.

## Request Budgets

Repository tests assert:

```text
initial Runtime Operations route:
  list requests: 1
  detail requests: 0

select one Runtime Operation:
  selected detail requests: 1
  unselected detail requests: 0

selected-operation notification:
  list reread: 1
  selected detail reread: 1
  unrelated details: 0

unrelated-operation notification:
  list reread: 1
  selected detail reread: 0
  other details: 0

reconnect:
  list reread: 1
  selected detail reread: 1 when selected
  other details: 0
```

No request count scales with the total number of Runtime Operations.

## Security and Storage Boundary

Runtime Operation rows, selected details, tenant IDs, channel names, capabilities, correlation IDs, failure
summaries, RuntimeNode references, and event payloads are held only in current browser memory. No Runtime
Operation cache is added to `localStorage` or `sessionStorage`.

Application-owned persistent storage remains limited to the established appearance preference. The bounded
vendor Pusher transport cache remains permitted only as non-sensitive transport metadata.

## Responsive and Accessibility

The Runtime Operations heading and live-state badge use the existing wrapping section-heading contract.
Filters stack under the established narrow breakpoint. List rows and selected detail use bounded grid tracks
that collapse to one column on narrow viewports. Long identifiers are shortened in primary labels and remain
available in context without relying on color alone for status. Focus and disabled states remain delegated to
the shared UI-B controls.

Repository hygiene was extended to cover the new route and responsive selectors without adding root-level
`overflow-x: hidden`.

## Tests

Focused coverage was added or extended in:

- `apps/api/tests/Feature/ControlPlane/RuntimeOperationRealtimeBroadcastTest.php`
- `apps/web/src/realtime/runtimeNodeRealtime.test.ts`
- `apps/web/src/App.test.ts`
- `scripts/check-repository-hygiene`

The tests cover channel authorization, metadata-only broadcast contract, outbox bridge selection and failure
isolation, the single shared Echo client, channel naming and teardown, generation fencing, notification-driven
canonical rereads, reconnect resynchronization, route/navigation capability gates, initial list budgets,
selection budgets, filter/pagination query mapping, validation/forbidden/not-found states, storage boundaries,
and the responsive source contract.

## Deferred Natural Playwright Proof

UI-D remains In Progress. The remaining proof is one focused natural Playwright MCP proof of the Runtime
Operations route, filters, pagination, selected detail, live notifications, scoped rereads, reconnect, tenant
isolation, logout, storage boundary, and responsive behavior.

## Deferred Audit and Reconciliation Surfaces

Runtime Operation audit and reconciliation views remain out of scope for UI-D14. They require separate
canonical read APIs and separate bounded implementation slices.
