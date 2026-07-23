# UI-D13 Runtime Operation Read API

## Scope

UI-D13 adds the backend read foundation for Runtime Operations only:

- `GET /api/v1/admin/runtime-operations`
- `GET /api/v1/admin/runtime-operations/{runtimeOperation}`

No frontend operational view, realtime subscription, audit API, reconciliation API, write route, Artisan management path, WebSocket path, Kubernetes resource, or migration is added in this slice.

## Canonical Authority

Canonical Runtime Operation snapshots read the existing persisted `runtime_operations` table through `App\ControlPlane\RuntimeOperations\RuntimeOperationQuery`.

The implementation does not create a second table, browser-owned state, outbox-derived read model, audit-derived read model, or adapter-specific operation API. Outbox messages and broadcast events remain notifications and evidence, not Runtime Operation read authority.

## Tenant Ownership

Every list and detail query is scoped by the session active tenant:

```text
runtime_operations.tenant_id = session.active_tenant_id
```

RuntimeNode and reconciliation joins also include tenant equality, so a route parameter, filter, operation identifier, RuntimeNode identifier, or correlation identifier cannot override the active tenant boundary.

Other-tenant Runtime Operations are hidden from detail reads with the repository's tenant-hidden `404` behavior.

## Authorization Capability

The endpoints reuse the existing `runtime.nodes.view` capability.

This matches the existing RuntimeNode runtime-evidence authorization boundary and treats Runtime Operations as subordinate runtime operational evidence rather than introducing a new route-only permission. Requests require:

- valid `identity.session`
- active tenant context
- active tenant membership through the session middleware
- `AuthorizationService::requireTenant(..., 'runtime.nodes.view')`

## List Endpoint

```text
GET /api/v1/admin/runtime-operations
```

The list response is paginated and ordered deterministically:

```text
created_at descending
id descending
```

Pagination response:

```text
runtime_operations: [...]
pagination:
  page
  per_page
  total
  has_more
```

`per_page` is bounded to `1..50` and defaults to `20`.

## Filters

The implemented filters are backed by canonical stored fields:

- `runtime_node_id` as UUID
- `status` as `OperationStatus`
- `operation_type` as the configured canonical operation types
- `created_from` as an inclusive date bound
- `created_to` as an exclusive date bound
- `correlation_id` as a 32-character hexadecimal correlation identifier

Invalid identifiers, dates, statuses, operation types, and correlation identifiers return validation errors. Combined filters remain tenant-scoped.

## Detail Endpoint

```text
GET /api/v1/admin/runtime-operations/{runtimeOperation}
```

The detail endpoint validates the Runtime Operation identifier, resolves through the active tenant query, and returns `404` for missing, malformed, or other-tenant operations.

## Response Fields

List rows expose only explicit safe fields:

- `id`
- `runtime_node_id`
- bounded `runtime_node` metadata
- `operation_type`
- `aggregate.type`
- `aggregate.id`
- `status`
- `attempt.count`
- `attempt.max`
- `priority`
- `correlation_id`
- safe `failure`
- `available_at`
- `started_at`
- `completed_at`
- `cancelled_at`
- `created_at`
- `updated_at`

Detail responses add:

- `payload_version`
- `causation_id`
- `request_id`
- `expires_at`
- bounded `reconciliation` reference when present

Timestamps use the repository's JSON timestamp serialization.

## Sensitive Fields Omitted

The API deliberately omits:

- raw `payload`
- `idempotency_key`
- lease owner and lease expiry
- raw failure message
- stack traces
- outbox bodies
- audit bodies
- provider responses
- credentials
- adapter configuration secrets
- SIP credentials
- endpoint secrets
- environment variables

## Failure Representation

Runtime failure visibility is bounded to persisted safe identifiers:

```text
failure:
  class
  code
  summary
  occurred_at
```

`summary` is derived only from the stored failure class and code. The raw failure message is not exposed in this slice because it may contain provider or exception internals.

## RuntimeNode Relationship

RuntimeNode data is bounded to display metadata joined by tenant:

- `id`
- `name`
- `slug`
- `runtime_family`
- `adapter_key`

The list endpoint does not load RuntimeNode credentials, adapter configuration, runtime evidence, or detail histories.

## Reconciliation and Audit Boundary

Runtime Operation detail may include a bounded reconciliation identifier reference when an existing reconciliation state points at the operation:

- `id`
- `target_type`
- `target_id`
- `status`

It does not embed reconciliation histories or audit collections. Future reconciliation and audit APIs remain their own snapshot authorities.

## Query Behavior

The list query performs a bounded join for RuntimeNode metadata and paginates in SQL with a separate count query. Tests assert that query count does not scale with the number of Runtime Operations returned.

Detail loads only the operation, bounded RuntimeNode metadata, and the bounded reconciliation reference.

## Tests

Added feature coverage in:

```text
apps/api/tests/Feature/ControlPlane/RuntimeOperationReadApiTest.php
```

Coverage includes:

- session, active tenant, capability, and suspended-user authorization
- tenant-hidden list and detail behavior
- pagination shape
- deterministic ordering
- exact safe list and detail fields
- all implemented filters and validation failures
- bounded RuntimeNode and reconciliation references
- sensitive field exclusions
- no N+1 list behavior

Focused runtime-operation repository tests also remain passing.

## Deferred Work

Deferred to later bounded UI-D slices:

- Runtime Operations frontend operational view
- Runtime Operation realtime subscription and notification handling
- standalone audit read API
- standalone reconciliation read API
- natural browser proof for the future frontend surface
