# UI-C3 List Query, Filtering, Sorting, and Pagination State

Date: 2026-07-22

Starting commit: `8d83fa2`

Implementation scope: repository-only frontend implementation. Controlled browser proof is intentionally pending.

## Baseline

- Users owned search, status, page, and page size in screen/local app state rather than the route URL.
- Tenants, Memberships, and Runtime Nodes rendered list loading, empty, error, and panel surfaces through repeated view-local markup.
- Browser reload, Back, and Forward did not provide a shared list-query restoration contract.
- Runtime Nodes already used UI-C1 shared async state and lazy detail loading; this slice preserved that request budget.

## Canonical Endpoint Support

| View | Search | Filters | Pagination | Sorting |
| --- | --- | --- | --- | --- |
| `/admin/users` | `search` | `status=active|suspended` | `page`, `per_page` | Not exposed by API contract |
| `/admin/memberships` | Not exposed | Not exposed | Not exposed | Not exposed |
| `/admin/tenants` | Not exposed | Not exposed | Not exposed | Not exposed |
| `/admin/runtime-nodes` | Not exposed | Not exposed | Not exposed | Not exposed |

No backend endpoint or request contract was changed. Sorting controls were not added because none of the targeted endpoints exposes server-side sort parameters.

## Shared List-Query Contract

`apps/web/src/composables/listQueryState.ts` provides the shared route-backed list-query contract.

Supported dimensions are opt-in:

- `search`
- declared string filters
- `page`
- `per_page`

Unsupported dimensions are normalized out of the URL. The contract parses the current route query, normalizes it into typed state, serializes supported canonical API parameters, and updates the URL through Vue Router only.

## URL Authority

The route query is now the restorable frontend authority for non-sensitive list intent.

Users supports URLs such as:

```text
/admin/users?page=2&per_page=10&search=alice&status=active
```

The URL never stores list records, credentials, tokens, notifications, mutation state, RuntimeNode detail payloads, tenant session state, or protected response bodies.

## Validation and Normalization

Implemented deterministic normalization:

- Missing page uses page `1`.
- Non-numeric or less-than-one page uses page `1`.
- Unsupported page sizes use the canonical default.
- Unknown filter values are omitted.
- Unknown query dimensions, including unsupported sorting keys, are removed.
- Automatic cleanup uses router replacement to avoid extra history entries.
- No malformed query creates an error route.

## History Semantics

User-driven list changes use Vue Router pushes:

- Applying changed filters pushes one URL entry.
- Page changes push one URL entry.
- Page-size changes push one URL entry and reset to page `1`.

Automatic normalization uses router replacement. Unchanged Apply does not push history or issue another request.

Browser Back and Forward restore the shared query state in deterministic unit coverage. Controlled browser proof remains pending.

## Pagination Reset Rules

Users resets page to `1` when search, status, or page size changes. Applying unchanged search/status leaves the current page unchanged and creates no duplicate request. Changing only the page preserves the active search, status, and page size.

## Stale-Response Protection

The migrated list views continue to use the UI-C1 `useAsyncResource` request-generation contract. A stale older response cannot replace newer query results. Refreshing retains prior data where present, and the shared list wrapper can show retained rows with a distinct refreshing or failure state.

## Shared List Components

Added restrained reusable list primitives:

- `UiFilterBar`
- `UiPagination`
- `UiDataList`
- `UiListSummary`

The components do not contain Users, Tenant, Membership, RuntimeNode, role, adapter, capability, or authorization logic. Domain rows and controls remain in their views through slots and existing shared form components.

## View Adoption

Users now uses:

- `useListQueryState` with `search`, `status`, `page`, and `per_page`.
- `UiFilterBar` for search/status Apply and Clear.
- `UiDataList` for loading, refreshing, empty, error, forbidden, and retained-data states.
- `UiListSummary` and `UiPagination` for canonical pagination metadata.

Memberships, Tenants, and Runtime Nodes use the shared query contract to normalize unsupported query dimensions away and use shared list-state presentation primitives. Their backend contracts do not expose list filters, pagination, or sorting, so no fake frontend controls were added.

## RuntimeNode Request Preservation

Runtime Nodes still performs only bounded initial shared requests and does not issue per-node detail requests on page load. Detail payloads remain on-demand, view-local expansion is not encoded in the URL, and tenant switching clears expanded detail panels while existing app-state cache invalidation clears detail data.

## Accessibility and Responsiveness

- Filter controls keep programmatic labels through `UiFormField`.
- Filter Apply submits through the native form.
- Pagination uses buttons with accessible names and disabled state.
- Current page and supplied totals are announced as readable text.
- Loading, refreshing, empty, error, and forbidden states stay distinct.
- Filter and pagination controls wrap at the existing narrow breakpoint.
- Users retains the UI-B mobile overflow correction.

## Tests and Static Checks

Focused frontend coverage was added for:

- Query parsing and normalization.
- Unsupported parameter removal.
- Router push/replace behavior.
- Back and Forward state restoration.
- Unchanged Apply no-op behavior.
- Shared filter, pagination, list, and summary components.
- Users URL-backed search/status/page/page-size behavior.
- RuntimeNode initial detail fan-out and unique field-ID tests remain preserved from UI-C1 coverage.

Repository hygiene now also rejects:

- Manual `pushState` or `replaceState` in list-query frontend sources.
- Browser persistence in the list-query frontend sources.
- Client-side `.sort()` over the paginated management lists.
- Missing `useListQueryState` adoption in the four targeted views.
- Loss of the Users canonical pagination contract.

## Remaining UI-C Work

- Natural browser proof of UI-C3 URL restoration, Back/Forward behavior, invalid-query normalization, pagination reset, and RuntimeNode request preservation.
- Catalog-driven RuntimeNode forms beyond the existing bounded `asterisk-ari` seam.
- Broader shared mutation adoption.
- Any additional list/workflow normalization outside the four targeted current management lists.
