# UI-D23 Audit Read API

Verdict: `UI_D_AUDIT_READ_API_COMPLETE`

UI-D23 adds the final backend read authority required by UI-D: tenant-scoped,
read-only Audit list and detail APIs. This is repository implementation evidence
only; it does not add a frontend Audit view, Audit realtime notifications,
exports, deletion, mutation, replay, repair, or CLI management.

## Canonical Audit Authority

The canonical operator-visible Audit persistence is the existing append-only
`control_plane_audit_records` table.

The canonical append authority is:

```text
App\ControlPlane\Audit\AuditRepository
```

`AuditRepository::append()` writes records with:

```text
id
tenant_id
actor_type
actor_id
action
subject_type
subject_id
reason
request_id
correlation_id
metadata
occurred_at
created_at
```

The metadata envelope is versioned and written through `PayloadSafety::redact`.
No outbox, runtime operation, reconciliation, application log, or event replay
source is used as the Audit read authority.

## Tenant Ownership

Tenant-owned rows are scoped by `control_plane_audit_records.tenant_id`.
The API requires an active session tenant and applies `where tenant_id = active
tenant` in the query boundary for both list and detail reads. Platform-level or
tenantless audit rows remain outside this tenant-scoped UI-D API.

Cross-tenant detail access is hidden with the established not-found response.

## Authorization Capability

No dedicated Audit-view or narrower operational-evidence capability exists in
the current capability catalog. Audit visibility is treated as a tenant
administrative function and reuses:

```text
tenant.memberships.manage
```

Requests require a valid identity session, active tenant context, tenant
membership, and that existing tenant capability. No new permission was added.

## Append-Only and Immutability Boundary

The APIs are GET-only. No create, update, delete, redact, restore, replay,
retry, approve, annotate, purge, or export route was added.

PostgreSQL keeps the existing append-only trigger:

```text
reject_control_plane_audit_mutation()
control_plane_audit_no_update
```

which rejects UPDATE and DELETE against `control_plane_audit_records`. GET
requests do not update access timestamps or audit content.

## Retention Boundary

Repository evidence shows no Audit-specific pruning command, scheduled pruning
job, retention setting, or legal-hold mechanism for `control_plane_audit_records`.
The current Audit records are append-only retained records unless a separate
future retention authority is introduced. This slice does not add retention
controls or imply records outside future retention would remain available.

## Routes

```text
GET /api/v1/admin/audit-records
GET /api/v1/admin/audit-records/{auditRecord}
```

Both routes are tenant-scoped, read-only, and mounted under the existing
authenticated admin API middleware.

## Pagination

The list endpoint uses SQL-backed pagination and the established response shape:

```text
audit_records
pagination.page
pagination.per_page
pagination.total
pagination.has_more
```

`per_page` is bounded from 1 to 50. Empty results normalize to page 1 and do not
fabricate rows.

## Filters

The implemented filters map directly to stored canonical columns:

```text
actor_id
actor_type
action
subject_type
subject_id
correlation_id
request_id
occurred_from
occurred_to
```

Identifier-like string filters are bounded and validated. Correlation and
request IDs require 32 hexadecimal characters and are normalized to lowercase
for comparison. Date filters are validated and executed as database timestamp
comparisons; `occurred_to` must be after or equal to `occurred_from`.

No raw payload search, JSON-path search, SQL fragment, regex filter, credential
search, stack-trace search, environment gate, or allowlist was added.

## Ordering

Audit records are historical append-only evidence. The default deterministic
ordering is:

```text
occurred_at descending
id descending
```

`occurred_at` is the canonical event time written by the append context. `id`
provides equal-timestamp stability.

## List Response Contract

Each list row exposes only explicit safe fields:

```text
id
action
actor.type
actor.id
subject.type
subject.id
outcome.status
outcome.code
outcome.summary
correlation_id
request_id
occurred_at
created_at
```

The list response does not expose raw metadata, reason text, before/after
payloads, request bodies, stack traces, outbox bodies, desired state, observed
state, credentials, tokens, cookies, or authorization headers.

## Detail Response Contract

The detail response uses the list contract plus:

```text
reason
metadata
```

`reason` is emitted only when it is not classified as sensitive. `metadata` is
the versioned audit metadata data after `PayloadSafety::redact` has removed
known sensitive values. Raw arbitrary model serialization is not used.

## Actor Representation

Actors are immutable stored references:

```text
type
id
```

The API does not join current users for historical correctness and therefore
continues to render deleted actor references. It does not expose password data,
sessions, tokens, full identity claims, unrelated tenant memberships, or
permission collections.

## Subject Representation

Subjects are immutable stored references:

```text
type
id
```

The API does not dynamically serialize related RuntimeNodes, Runtime Operations,
Runtime Reconciliations, Conferences, participants, users, or tenant objects.
Broken or deleted subject references do not make historical audit rows
unreadable.

## Network and Client Metadata Boundary

The canonical audit table does not have dedicated IP address, user-agent, route,
request-method, request-path, cookie, header, or request-body columns. Mixed
metadata remains behind the redacted metadata boundary. Raw URLs, headers,
cookies, CSRF tokens, signed broadcast-auth parameters, request bodies, and
uploaded file contents are not exposed.

## Before and After Payload Boundary

The current audit authority stores a generic metadata envelope rather than a
dedicated, classified before/after change-set contract. This API only returns
metadata after the existing canonical redaction layer has processed it. Raw or
mixed-sensitivity before/after payloads remain excluded unless a future write
authority defines an approved safe representation.

## Outcome and Failure Boundary

The API derives a bounded outcome from safe stored metadata when present:

```text
status
code
summary
```

It does not turn Audit into an exception-log viewer. Stack traces, raw exception
messages, file paths, SQL text, command output, provider responses, credentials,
tokens, environment values, and raw failure bodies are excluded or redacted.

## Query and N+1 Coverage

`AuditRecordQuery` performs tenant-scoped table reads without per-row joins. The
list test records query counts before and after increasing the row count and
asserts the query count remains constant. Detail reads resolve exactly one
tenant-scoped record.

## PostgreSQL Coverage

`AuditRecordPostgresReadApiTest` is included in the established
`make control-plane-test` target because that target runs `tests/Feature/ControlPlane`
against disposable PostgreSQL. It proves:

- canonical migrations run under PostgreSQL;
- the audit table has the expected PostgreSQL column types;
- records are appended through `AuditRepository`;
- list and detail endpoints execute real PostgreSQL query paths;
- actor and subject references render from immutable stored fields;
- filters, pagination, deterministic ordering, tenant isolation, and safe field
  contracts hold;
- the PostgreSQL append-only trigger rejects deletion.

SQLite feature tests remain for fast authorization, validation, safe-contract,
N+1, and immutability feedback.

## Verification

Focused verification added for:

- authorized tenant, missing capability, missing session, missing active tenant,
  suspended user, and cross-tenant hidden details;
- pagination shape and page-size bounds;
- deterministic equal-timestamp ordering;
- valid and invalid filters;
- exact top-level and nested response keys;
- null actor handling through system actor references;
- deleted actor and subject references through immutable stored values;
- no sensitive field exposure;
- GET immutability and absence of write routes.

## Deferred Work

- bounded read-only Audit operational view using these canonical APIs;
- repository-evidence decision on whether Audit requires realtime notifications;
- natural-browser proof for the eventual Audit view.
