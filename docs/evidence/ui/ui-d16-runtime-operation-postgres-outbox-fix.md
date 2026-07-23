# UI-D16 — Runtime Operation PostgreSQL Reads and Canonical Notifications

Verdict: `UI_D_RUNTIME_OPERATION_POSTGRES_OUTBOX_FIX_COMPLETE`

Source commit: `cc6e466` — `docs(ui): prove live runtime operations`
Implementation type: bounded backend, schema, and deployment-configuration correction.
Phase marker: `UTCP_PHASE=T1` (unchanged). UI-D remains **In Progress**.

No Playwright MCP proof was run for this repository correction. The remaining proof is the focused natural Runtime Operations live proof from this corrected commit.

---

## Live PostgreSQL Failure

UI-D15 proved that the deployed Runtime Operations list and detail endpoints failed against canonical PostgreSQL with:

```text
SQLSTATE[42883]: Undefined function: 7 ERROR: operator does not exist: uuid = character varying
```

The failing query path was `RuntimeOperationQuery::baseQuery()`, which joined `runtime_nodes.id` and `runtime_nodes.tenant_id` to `runtime_operations.runtime_node_id` and `runtime_operations.tenant_id`, and joined `runtime_reconciliation_states.tenant_id` to `runtime_operations.tenant_id`.

SQLite tests did not expose the defect because SQLite treats Laravel `uuid()` columns as string-compatible.

## Original Mismatched Column Types

The original `runtime_operations` migration declared:

```text
runtime_operations.tenant_id       string nullable
runtime_operations.runtime_node_id string nullable
```

The canonical related tables already used PostgreSQL UUID semantics:

```text
runtime_nodes.id                         uuid
runtime_nodes.tenant_id                  uuid
runtime_reconciliation_states.tenant_id  uuid
```

`runtime_operations.aggregate_id`, `correlation_id`, `causation_id`, and `request_id` were not converted because they are not direct RuntimeNode or tenant relational UUID references in this slice.

## Canonical UUID Schema Correction

The forward migration `2026_07_24_120000_align_runtime_operation_uuid_columns.php` converts the canonical Runtime Operation relationship columns on PostgreSQL:

```text
runtime_operations.tenant_id       uuid nullable
runtime_operations.runtime_node_id uuid nullable
```

The conversion uses explicit PostgreSQL casts:

```sql
ALTER TABLE runtime_operations ALTER COLUMN tenant_id TYPE uuid USING tenant_id::uuid;
ALTER TABLE runtime_operations ALTER COLUMN runtime_node_id TYPE uuid USING runtime_node_id::uuid;
```

SQLite remains runnable through a driver-aware no-op migration path, preserving the repository's fast local test workflow without weakening PostgreSQL column semantics.

## Existing-Data Validation

Before conversion the migration verifies:

- non-null tenant IDs are syntactically valid UUIDs;
- non-null RuntimeNode IDs are syntactically valid UUIDs;
- tenant references resolve to `tenants.id`;
- RuntimeNode references resolve to `runtime_nodes.id` for the same tenant.

Malformed or unresolved canonical relationship values fail the migration observably. The migration does not null, regenerate, or delete existing Runtime Operation history.

## PostgreSQL Integration Coverage

`RuntimeOperationPostgresReadApiTest` runs only on a PostgreSQL connection and is included by the existing `scripts/control-plane/test` target. It runs canonical migrations, asserts the PostgreSQL column types are `uuid`, creates tenant, RuntimeNode, reconciliation, and Runtime Operation records, then calls the real list and detail APIs.

The PostgreSQL proof covers:

- list endpoint success;
- detail endpoint success;
- RuntimeNode join;
- reconciliation join;
- tenant isolation;
- stored-field filtering;
- absence of the former `uuid = character varying` failure.

The existing SQLite feature tests remain as fast general coverage.

## Original Zero-Row Runtime Operation Aggregate Evidence

UI-D15 proved the live outbox contained zero rows with:

```text
aggregate_type = runtime_operation
```

Existing `runtime_operation.*` event types were emitted under target aggregates such as `runtime_node` or `conference`, so the strict `OperationalBroadcastBridge` correctly rejected them for the Runtime Operations browser channel.

## Canonical Transition Producer

`RuntimeOperationRepository` now produces canonical Runtime Operation aggregate outbox rows at the lifecycle persistence authority. Repository transitions append notification events in the same transaction as the Runtime Operation state change.

The deterministic event names are:

```text
runtime_operation.created
runtime_operation.status_changed
```

The existing target-aggregate outbox event supplied to `complete()` remains preserved for its existing consumers, with an additional Runtime Operation aggregate notification in the same transaction.

## Exact Outbox Contract

Runtime Operation notification rows use:

```text
aggregate_type = runtime_operation
aggregate_id   = runtime operation ID
tenant_id      = Runtime Operation tenant
event_type     = runtime_operation.created or runtime_operation.status_changed
payload        = runtime_operation_id, runtime_node_id when present
```

The payload intentionally omits status, raw request payload, result payload, failure message, stack trace, lease data, commands, endpoints, provider responses, credentials, and secrets. Browser state remains snapshot-driven through canonical HTTP rereads.

## Producer-Backed Broadcast Tests

`RuntimeOperationRealtimeBroadcastTest` now proves the main acceptance path without manually inserting the successful Runtime Operation outbox row:

```text
RuntimeOperationRepository::create()
→ RuntimeOperationRepository::claimAvailable()
→ canonical outbox rows with aggregate_type=runtime_operation
→ OutboxDispatcher
→ RuntimeOperationOperationalStateChanged
```

The test asserts the exact metadata-only envelope, tenant channel, RuntimeNode metadata, aggregate identity, strict bridge rejection for malformed shapes, rollback behavior, idempotent create behavior, and broadcast failure isolation.

RuntimeNode and Conference broadcast tests remain in the verification set to guard existing behavior.

## Workload Broadcaster Classification

Rendered-manifest checks preserve the established Reverb publisher boundary:

```text
api
worker
control-plane-outbox-dispatcher
reverb
```

These workloads must use `BROADCAST_CONNECTION=reverb` with the canonical Reverb host, port, scheme, and credential Secret references.

## Corrected Rendered Environments

The non-publisher workloads now explicitly use:

```text
BROADCAST_CONNECTION=log
```

without Reverb application credential Secret references:

```text
scheduler
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
simulator-event-source
asterisk-ari-events
kamailio-registration-observer
utcp-runtime-fence-worker
```

The correction does not grant Reverb credentials broadly and does not introduce a feature gate or silent fallback.

## Migration Boundary

The migration Job remains a non-publisher:

```text
BROADCAST_CONNECTION=log
no utcp-local-reverb-credentials
no REVERB_APP_ID
no REVERB_APP_KEY
no REVERB_APP_SECRET
```

Both Kubernetes and security configuration checks enforce this boundary.

## Deferred Live Runtime Operations Proof

The remaining proof is:

```text
re-run the natural Playwright MCP Runtime Operations live proof from the
corrected commit, including PostgreSQL list/detail, pagination, filters,
production-generated notifications, scoped rereads, reconnect, tenant isolation,
logout, storage, and responsive behavior
```

No audit, reconciliation, retry, repair, replay, cancellation, write control, polling fallback, second WebSocket client, or browser-owned Runtime Operation state was added in this slice.
