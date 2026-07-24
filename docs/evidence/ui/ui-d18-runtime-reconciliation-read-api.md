# UI-D18 Runtime Reconciliation Read API

## Scope

UI-D18 adds the backend read authority for Runtime Reconciliation current-state inspection only:

- `GET /api/v1/admin/runtime-reconciliations`
- `GET /api/v1/admin/runtime-reconciliations/{runtimeReconciliation}`

No frontend route, realtime channel, broadcast bridge, audit API, manual reconciliation control, retry, replay, repair, approval flow, Artisan management command, Kubernetes change, or phase-marker change is included.

## Canonical Reconciliation Model and Table

The canonical current-state authority is the existing `runtime_reconciliation_states` table, written by `App\RuntimeEngine\Reconciliation\ReconciliationRepository`.

The API reads that table through `App\ControlPlane\RuntimeReconciliation\RuntimeReconciliationQuery`. It does not create a duplicate read model, outbox-derived snapshot, audit-derived snapshot, Runtime Operation replacement snapshot, adapter-specific API, or browser-owned reconciliation state.

## Current-State Versus History Boundary

`runtime_reconciliation_states` represents the current reconciliation state for a target:

- `target_type`
- `target_id`
- desired and observed generations
- lifecycle status
- attempt count
- last and next reconciliation timestamps
- current safe blocked reason when the state is blocked
- the last linked Runtime Operation identifier when present

Runtime Operation lifecycle remains owned by the Runtime Operation API. Historical audit evidence remains outside this API and is deferred to a future Audit API. This slice does not embed attempt histories, audit bodies, outbox bodies, operation histories, desired payloads, or observed payloads.

## Tenant Ownership

Every list and detail query is scoped at the database boundary:

```text
runtime_reconciliation_states.tenant_id = session.active_tenant_id
```

RuntimeNode and Runtime Operation joins also require tenant equality. A route parameter, reconciliation ID, RuntimeNode ID, Runtime Operation ID, target identifier, or filter cannot override the active tenant. Cross-tenant detail reads follow the hidden-resource convention and return `404`.

## Authorization Capability

The endpoints reuse the existing `runtime.nodes.view` capability.

Runtime Reconciliation is subordinate operational evidence for RuntimeNodes and no narrower reconciliation-view capability exists. Requests require:

- valid `identity.session`
- active tenant context
- active tenant membership
- `AuthorizationService::requireTenant(..., 'runtime.nodes.view')`

## List Route

```text
GET /api/v1/admin/runtime-reconciliations
```

The response shape is:

```text
runtime_reconciliations: [...]
pagination:
  page
  per_page
  total
  has_more
```

`per_page` is bounded to `1..50` and defaults to `20`.

## Detail Route

```text
GET /api/v1/admin/runtime-reconciliations/{runtimeReconciliation}
```

The identifier must be a canonical 32-character runtime engine identifier. Malformed, missing, and other-tenant records return `404`.

## Deterministic Ordering

The list is ordered by:

```text
updated_at descending
id descending
```

`runtime_reconciliation_states` is a current-state table. `updated_at` is the operationally relevant ordering key because reconciliation transitions update the same state row; `id` provides deterministic stability for equal timestamps.

## Filters

The implemented filters are backed by stored canonical fields:

- `runtime_node_id` as UUID, mapped to `target_type = runtime_node` and `target_id`
- `status` as the canonical reconciliation lifecycle status
- `target_type` as a registered reconciler target type
- `runtime_operation_id` as a 32-character linked operation identifier
- `updated_from` as an inclusive timestamp bound
- `updated_to` as an exclusive timestamp bound

Invalid UUIDs, statuses, target types, operation identifiers, dates, and reversed date ranges return validation errors. Combined filters remain tenant-scoped.

No raw JSON search, desired-state search, observed-state search, adapter-specific filter, raw SQL expression, hidden allowlist, or environment-gated filter was added.

## List Response Fields

List rows expose only explicit safe fields:

- `id`
- `target.type`
- `target.id`
- bounded `runtime_node`
- `status`
- `desired_generation`
- `observed_generation`
- `has_drift`
- `attempt_count`
- `last_checked_at`
- `next_check_at`
- `last_operation_id`
- bounded `runtime_operation`
- safe `failure`
- `created_at`
- `updated_at`

The API does not serialize the model directly.

## Detail Response Fields

The detail response uses the same explicit safe contract under:

```text
runtime_reconciliation
```

The separate detail resource exists as a stable detail boundary for future safe additions without changing the list contract.

## Safe Failure Representation

The safe failure shape is:

```text
failure:
  category
  code
  summary
  occurred_at
```

`category` is the current reconciliation status. `code` is derived from the persisted blocked reason only when the value is safe; values containing secret-like, stack-like, path-like, token-like, or whitespace-bearing free text are reported as `redacted`. The API does not expose raw exception messages, stack traces, file paths, Secret data, environment values, provider responses, raw commands, SIP credentials, adapter credentials, or endpoint secrets.

## Desired and Observed Payload Exclusions

The current table stores desired and observed generation numbers, not an approved sanitized desired/observed payload representation. The API exposes generation fields and `has_drift` only. It does not expose raw desired state, observed state, adapter payloads, provider payloads, or JSON bodies.

## RuntimeNode Relationship

RuntimeNode data is bounded to:

- `id`
- `name`
- `slug`
- `runtime_family`
- `adapter_key`

The join is limited to `target_type = runtime_node` and tenant equality. Because `target_id` is a polymorphic target identifier, PostgreSQL stores it as `character varying`; the join casts the RuntimeNode UUID identifier to text at the relationship boundary while preserving canonical UUID columns for tenant and Runtime Operation joins.

Credentials, adapter configuration, endpoints, runtime evidence history, and RuntimeNode detail payloads are not loaded.

## Runtime Operation Relationship

When `last_operation_id` is present and resolves inside the same tenant, the response exposes a bounded Runtime Operation reference:

- `id`
- `operation_type`
- `status`
- `created_at`
- `completed_at`

The API does not embed full Runtime Operation details, histories, payloads, idempotency keys, leases, provider responses, or failure messages.

## Audit Boundary

The authority boundary remains:

```text
Runtime Reconciliation API -> reconciliation current-state authority
Runtime Operation API -> operation lifecycle authority
future Audit API -> historical audit authority
```

This API does not embed audit records or audit payloads.

## PostgreSQL Coverage

`apps/api/tests/Feature/ControlPlane/RuntimeReconciliationPostgresReadApiTest.php` runs only on PostgreSQL and is included under the existing `make control-plane-test` target.

The PostgreSQL test asserts canonical column semantics, runs migrations, creates tenants, RuntimeNodes, Runtime Operations, and reconciliation states through existing repositories or supported table helpers, calls the real list and detail endpoints, exercises RuntimeNode and Runtime Operation joins, verifies a filter, checks pagination, asserts tenant isolation, and proves the query executes without a driver-specific UUID mismatch.

SQLite coverage remains in `RuntimeReconciliationReadApiTest.php` for fast feature feedback.

## Query-Count Result

The list query uses bounded joins and SQL pagination. Feature coverage compares query count with one reconciliation row and nine reconciliation rows and asserts the count is unchanged, proving list query count does not scale with the number of rows returned.

The list does not perform per-row queries for RuntimeNode, Runtime Operation, failure state, correlation data, audit history, operation history, outbox payloads, credentials, or adapter configuration.

## Tests

Added:

```text
apps/api/tests/Feature/ControlPlane/RuntimeReconciliationReadApiTest.php
apps/api/tests/Feature/ControlPlane/RuntimeReconciliationPostgresReadApiTest.php
```

Coverage includes:

- session, active tenant, capability, suspended-user, and hidden-resource authorization
- list pagination, deterministic ordering, empty and tenant-excluded results
- exact safe list and detail keys
- filter validation and composability
- bounded RuntimeNode and Runtime Operation references
- safe failure representation and sensitive-field exclusions
- no raw desired or observed payload exposure
- no audit or outbox body exposure
- non-scaling list query count
- real PostgreSQL list and detail execution through the established control-plane target

## Deferred Work

Deferred to later bounded UI-D slices:

- read-only Runtime Reconciliation frontend operational view
- Runtime Reconciliation notification consumption
- natural browser proof for the future view
- standalone Audit API
