# UI-E5 — Pagination Loading Correction

Verdict: `UI_E_PAGINATION_LOADING_CORRECTION_COMPLETE`

UI-E5 corrects the pagination request-discipline defect found by the UI-E4 live
proof. UI-E remains In Progress pending focused natural browser reproof.
`UTCP_PHASE=T1` is unchanged.

## Evidence Authority

- [`docs/evidence/ui/ui-e4-focus-management-live-proof.md`](ui-e4-focus-management-live-proof.md)

UI-E4 measured the live pagination failure while the page-two Audit list request
was pending:

```text
page=2
page=2
page=3
page=4
```

Focus retention passed, but `UiPagination` did not provide list loading state to
its Previous and Next buttons, so the shared `UiButton` loading guard could not
engage.

## Root Cause

`UiPagination` rendered Previous and Next with only boundary `disabled` state. A
caller entering `refreshing` during a pending page request did not set
`loading=true` on those `UiButton` controls, leaving them natively enabled without
`aria-disabled`, `aria-busy`, or the shared loading activation guard.

## Correction

`UiPagination` now has one explicit Boolean `loading` prop, defaulting to
`false`. It forwards that prop to both Previous and Next.

The resulting behavior is:

- `loading=false`: current pagination behavior.
- available control plus `loading=true`: native `disabled` absent,
  `aria-disabled="true"`, `aria-busy="true"`, focus retained, repeated
  click/Enter/Space activation blocked by `UiButton`.
- boundary control plus any loading state: native `disabled` present and no page
  event emitted.

No debounce, timer, request lock, URL dedupe, feature gate, compatibility branch,
or caller-specific click guard was added.

## Production Caller Inventory

| Caller | Canonical loading state | Page source | Page size source | Total / has_more source | Page handler | Loading distinction |
|---|---|---|---|---|---|---|
| `AuditRecordsView` | `auditRecordsResource.isRefreshing.value` | `auditRecordPagination.page` | `auditRecordPagination.per_page` | `auditRecordPagination.total` / `auditRecordPagination.has_more` | `setPage`, `setPerPage` | initial `loading` hides pagination; visible-list rereads use `refreshing`; detail loading is separate and not passed |
| `UsersView` | `usersResource.isRefreshing.value` | `renderedPagination.page` | `renderedPagination.per_page` | `renderedPagination.total` / `renderedPagination.has_more` | `setPage`, `setPerPage` | initial `loading` hides pagination; visible-list rereads use `refreshing`; user mutations are separate keyed actions |
| `RuntimeOperationsView` | `runtimeOperationsResource.isRefreshing.value` | `runtimeOperationPagination.page` | `runtimeOperationPagination.per_page` | `runtimeOperationPagination.total` / `runtimeOperationPagination.has_more` | `setPage`, `setPerPage` | initial `loading` hides pagination; visible-list rereads use `refreshing`; detail loading and realtime notifications stay separate |
| `RuntimeReconciliationsView` | `runtimeReconciliationsResource.isRefreshing.value` | `runtimeReconciliationPagination.page` | `runtimeReconciliationPagination.per_page` | `runtimeReconciliationPagination.total` / `runtimeReconciliationPagination.has_more` | `setPage`, `setPerPage` | initial `loading` hides pagination; visible-list rereads use `refreshing`; detail loading and realtime notifications stay separate |

Every production `UiPagination` use now supplies `:loading=`. This was verified
by repository inspection rather than a brittle one-off lint rule.

## Regression Coverage

Shared component tests prove:

- `loading=true` forwards native-enabled, `aria-disabled`, and `aria-busy`
  semantics to both available controls.
- one normal Previous or Next activation emits exactly one page event.
- repeated click, Enter, and Space attempts while loading emit zero additional
  page events.
- focus remains on an available pagination control when loading starts.
- Previous on page one and Next on the last page remain natively disabled and
  emit no page event.

Representative route tests prove:

- Audit Records pagination issues one canonical list request for one available
  Next activation, retains focus and loading ARIA while pending, rejects repeated
  click/Enter/Space activation with zero additional requests, then renders the
  canonical page response.
- Runtime Operations pagination preserves `status` and `per_page` query state,
  issues one canonical list request for one available Next activation, retains
  focus and loading ARIA while pending, rejects repeated click/Enter/Space
  activation with zero additional requests, then renders the canonical page
  response.

Existing query-state coverage remains responsible for page-number calculation,
page-size behavior, filter preservation, URL synchronization, backend ordering,
and selection behavior.

## Accessibility Preservation

Previous and Next keep their existing accessible names. Page-size label
association is unchanged. No `tabindex="-1"` was added. `eslint-plugin-vuejs-accessibility`
remains active, and the axe helper continues to fail serious and critical
violations.

## Deferred Work

- Focused natural Playwright MCP reproof that pending pagination retains focus,
  exposes loading ARIA, and rejects repeated click/Enter/Space activation without
  duplicate page requests, plus bounded console/network/accessibility sanity.
- Audit Filter Apply still does not receive a button-level loading prop. UI-E4
  proved its duplicate-request result is currently protected by URL-backed
  query deduplication, not the shared loading guard. That busy-state limitation
  is deferred and was not changed in UI-E5.
