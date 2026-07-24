# UI-D24 Audit View Implementation

## Scope

UI-D24 adds the read-only Audit operational view for the administrative surface. It is a bounded repository implementation that consumes the canonical Audit read APIs from `58a96d5` and does not change Audit persistence, append behavior, retention, or producer behavior.

UI-D remains In Progress. Final UI-D completion is deferred until focused natural Playwright MCP proof of this route.

## Canonical APIs

The browser reads Audit snapshots only from:

- `GET /api/v1/admin/audit-records`
- `GET /api/v1/admin/audit-records/{id}`

Canonical persistence and append authority remain:

- `control_plane_audit_records`
- `ControlPlane\Audit\AuditRepository::append()`

No browser-owned Audit history store, outbox-derived history, Runtime Operation-derived history, Reconciliation-derived history, local Audit persistence, client aggregation, second Audit API, Audit mutation endpoint, or Audit write control was added.

## Authorization

The route and navigation use the established tenant-admin capability:

- `tenant.memberships.manage`

The route also requires a natural active tenant. A generic authenticated session without an active tenant does not expose or authorize the Audit view.

## Route and Navigation

The route is:

- `/admin/audit-records`

The navigation entry is under the administrative navigation group and is visible only when the active tenant grants `tenant.memberships.manage`. No alias route was added.

## List Fields

The list renders only fields returned by the canonical list resource:

- Audit record ID
- Occurred timestamp
- Action
- Actor type and identifier
- Subject type and identifier
- Outcome status, code, and summary
- Correlation identifier
- Request identifier
- Created timestamp

The browser does not reconstruct omitted data, fetch actor models, fetch subject models, or dynamically serialize related resources.

## Detail Fields

The selected-record detail renders only fields returned by the canonical detail resource:

- Audit record ID
- Action
- Actor type and identifier
- Subject type and identifier
- Outcome status, code, and summary
- Correlation identifier
- Request identifier
- Occurred timestamp
- Created timestamp
- Safe reason
- Safe metadata

Deleted or missing actor and subject references remain readable as bounded type and identifier values.

## Pagination

Pagination is server-backed through the canonical list response:

- `audit_records`
- `pagination.page`
- `pagination.per_page`
- `pagination.total`
- `pagination.has_more`

The initial route loads one list page only. Page changes preserve active filters and issue one canonical list request without detail fan-out. Ordering remains the backend order: `occurred_at desc, id desc`.

## Filters

The view exposes only filters implemented by `AuditRecordQuery` and validated by the controller:

- `actor_type`
- `actor_id`
- `action`
- `subject_type`
- `subject_id`
- `correlation_id`
- `request_id`
- `occurred_from`
- `occurred_to`

No unsupported outcome filter, raw metadata search, raw payload search, credential or token search, regex search, arbitrary JSON-path search, or client-side full-history search was added.

Filter submission resets the page to one. Clearing filters is deterministic. Backend validation errors are displayed instead of ignored. Pagination and explicit refresh preserve the active filter set.

## Explicit Refresh

The view provides a visible Refresh control. Refresh issues exactly one canonical list reread for the current page, page size, and active filters. It does not load selected detail, unrelated detail, or any realtime-derived resource.

A failed refresh preserves the previously loaded canonical list and displays the error. Refresh does not use polling, timers, visibility-triggered background refresh, or silent automatic refresh.

## Selected Detail

Selecting a record calls:

- `GET /api/v1/admin/audit-records/{selectedId}`

The initial route performs zero detail requests. Selecting one record loads only that selected ID. Changing selection loads only the new selected ID. Closing the detail panel does not trigger another list request.

## Actor and Subject Rendering

Actor rendering is bounded to:

- `type`
- `identifier`

Subject rendering is bounded to:

- `type`
- `identifier`

No session, token, permission, membership list, identity claim, password state, authentication metadata, or related resource is fetched or displayed.

## Outcome, Reason, and Metadata

Outcome rendering uses only the explicit safe outcome shape:

- `status`
- `code`
- `summary`

Reason rendering uses only the dedicated safe reason field.

Safe metadata is rendered as bounded key/value rows from the canonical detail resource. The renderer does not display an opaque raw database payload, does not use unrestricted JSON dumping, and limits displayed metadata entries and values defensively while preserving the server-owned safety boundary.

## Sensitive Exclusions

The Audit view and tests exclude rendering of:

- Credentials
- API keys
- Tokens
- Cookies
- Authorization headers
- Password values
- Raw request bodies
- Stack traces
- SQL
- Command output
- Provider responses
- Outbox bodies
- Raw desired state
- Raw observed state
- Kubernetes Secret data
- Environment values

The frontend does not infer or reconstruct excluded values from correlation, request, actor, or subject fields.

## Request Budgets

Repository tests cover these request budgets:

- Initial Audit route: one list request, zero detail requests.
- Pagination: one list request per page change, zero detail requests.
- Filter submission: one list request, zero detail requests, page reset to one.
- Selecting one Audit record: one selected detail request, zero unrelated detail requests.
- Refresh: one list reread, current page preserved, active filters preserved, zero detail fan-out.

## Loading and Error States

The view supports:

- Initial loading
- Empty results
- Refreshing
- Page loading
- Detail loading
- Validation error
- Forbidden
- Not found
- List server error
- Detail server error

A failed detail request does not remove the canonical list. A failed refresh keeps the previously loaded list while surfacing the error.

## Storage Boundary

No Audit list rows, selected detail, Audit IDs, actor identifiers, subject identifiers, correlation IDs, request IDs, outcome, reason, metadata, tenant ID, or capability state are persisted to `localStorage` or `sessionStorage`.

The only existing persistent UI preference remains the established non-sensitive appearance setting. URL query parameters represent filter/page state through the existing list-query convention and are not a browser storage cache.

## Responsive Behavior

The Audit route reuses the established operational list and panel components. The heading and actions wrap, filters stack at narrow widths, identifiers use wrapping/truncation-friendly text containers, outcome includes text and does not rely only on color, pagination remains reachable, and detail plus metadata values wrap inside the viewport.

Repository hygiene now includes the Audit row and detail-grid responsive contracts and does not introduce root-level `overflow-x: hidden`.

## Realtime Decision

Audit realtime notifications are not implemented in this slice.

Reason:

- Audit correctness is provided by append-only canonical HTTP snapshots.
- No canonical Audit aggregate event or channel has been established for this view.
- No concrete live-update failure or operator requirement has been demonstrated.
- Creating a producer, channel, bridge branch, or background refresh loop would expand authority and proof scope unnecessarily.

No Audit Echo subscription, Audit private channel, Audit broadcast event, `OperationalBroadcastBridge` Audit branch, timer-based polling, visibility-based background refresh, or silent automatic interval refresh was added.

## Deferred Proof

Natural Playwright MCP proof is deferred by task boundary. The remaining proof must cover the Audit route, pagination, filters, refresh, selected detail, safe-field rendering, authorization, logout, storage, and responsive behavior.

Final UI-D completion assessment is deferred until that live proof is complete.

## Verification

Repository verification for this implementation is recorded in the task completion report.
