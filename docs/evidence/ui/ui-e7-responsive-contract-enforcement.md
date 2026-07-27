# UI-E7 Responsive Contract Enforcement

## Verdict

UI_E_RESPONSIVE_CONTRACT_ENFORCEMENT_COMPLETE

## Scope

This repository slice codifies and enforces the responsive-layout baseline already
proven across the stable UI-A through UI-D surfaces. It does not redesign the
application, change API/domain behavior, add browser proof, or mark UI-E
complete.

## Supported Viewport Classes

| Class | Contract |
| --- | --- |
| narrow | 375px CSS viewport |
| intermediate | 768px CSS viewport |
| desktop | Current desktop layout baseline |

During natural browser proof every shipped primary route must satisfy:

```text
document.documentElement.scrollWidth <= window.innerWidth
```

Root-level overflow clipping is not an acceptable way to satisfy this contract.

## Existing Browser Evidence

- UI-E2 live-proved 0 serious and 0 critical axe violations across the primary
  route set, text-based status, visible focus indicators, and zero root overflow
  at 375x812 and 768x1024, while identifying focus defects.
- UI-E4 live-proved the loading-button and inline-detail focus corrections, and
  identified the separate pagination loading defect.
- UI-E6 live-proved the pagination loading correction on representative
  production callers, with focus retention, loading ARIA, duplicate activation
  suppression, and 0 serious and 0 critical axe violations while pagination was
  loading and after completion.

This UI-E7 slice adds repository enforcement for the responsive contract. It does
not perform a new natural browser proof.

## Route Inventory

| Route | Page container | Heading/actions | Filters | Form layout | Data table/list | Long identifiers | Pagination | Detail panel | Status and empty/error states |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Login | `app-shell`, `auth-panel` | Auth heading in bounded panel | None | Auth form stack | None | Tenant and user identifiers appear only after auth | None | None | Alert and validation text |
| Dashboard | `workspace` | `section-heading` | None | None | `dashboard-grid`, `definition-grid` | Runtime/user/session values use shared wrapping | None | Summary panels | Text labels and status summaries |
| Users | `workspace` | `section-heading` | `UiFilterBar` | `inline-form` | `UiDataList`, `data-table`, `data-row` | Email, user id, tenant ids in bounded row/subgrid containers | `UiPagination` outside the list | User-detail route, no inline panel | `UiBadge`, alerts, empty state |
| Tenants | `workspace` | `section-heading` | None | `inline-form` | `UiDataList`, `data-table`, `data-row` | Tenant id and host names in bounded row/subgrid containers | None | None | `UiBadge`, alerts, empty state |
| Memberships | `workspace` | `section-heading` | None | `inline-form` | `UiDataList`, `data-table`, `data-row` | User, tenant, and role identifiers in bounded rows | None | None | `UiBadge`, alerts, empty state |
| Runtime Nodes | `workspace` | `section-heading`, `row-actions`, live badge | None | `inline-form` and detail form sections | `UiDataList`, `data-table`, `data-row` | Node id, adapter, endpoint, fingerprint, and credential labels wrap | None | `subgrid`, `definition-grid`, detail sections | `UiBadge`, alerts, empty state |
| Conference Operations | `workspace` | `section-heading`, `row-actions`, live badge | `UiFilterBar` | None | `UiDataList`, `data-table`, `data-row` | Conference/session/channel identifiers wrap in row and detail grids | None | `conference-detail-grid`, `definition-grid`, participant rows | Text-bearing badges and alerts |
| Runtime Operations | `workspace` | `section-heading`, `row-actions`, live badge | `UiFilterBar` | None | `UiDataList`, `data-table`, `data-row` | Operation, node, correlation, and aggregate identifiers wrap | `UiPagination` outside the list | `runtime-operation-detail-grid`, `definition-grid` | Text-bearing badges and alerts |
| Runtime Reconciliations | `workspace` | `section-heading`, `row-actions`, live badge | `UiFilterBar` | None | `UiDataList`, `data-table`, `data-row` | Reconciliation, node, operation, generation, and target identifiers wrap | `UiPagination` outside the list | `runtime-reconciliation-detail-grid`, `definition-grid` | Text-bearing badges and alerts |
| Audit Records | `workspace` | `section-heading`, `row-actions` | `UiFilterBar` | None | `UiDataList`, `data-table`, `data-row` | Audit, actor, subject, correlation, request, and metadata identifiers wrap | `UiPagination` outside the list | `audit-record-detail-grid`, `definition-grid`, `metadata-grid` | Text-bearing outcome badges and alerts |

## Shared Responsive Patterns

- Application and page roots use explicit bounded sizing through `width: 100%`,
  `max-width: 100%`, and `min-width: 0` where flex or grid children could
  otherwise force document overflow.
- AppShell topbar, page headings, topbar actions, row actions, pagination, and
  live-update badges wrap instead of forcing horizontal document growth.
- Filter controls use the shared `UiFilterBar` grid contract and collapse to one
  column at the established narrow breakpoint.
- Forms use the shared `inline-form` contract so form columns can shrink and
  collapse without moving controls outside their associated form.
- Long identifiers use the shared wrapping contract on row text, metadata, and
  code-like values with `overflow-wrap: anywhere`.
- Detail panels and grids use bounded containers, `minmax(0, 1fr)` columns, and
  shared narrow-breakpoint collapse rules.
- Pagination remains outside list containment and keeps its wrapping hook, so it
  remains reachable after local list scrolling or detail expansion.

## Local Table Overflow Boundary

Wide tabular content may scroll only inside an explicit local table wrapper.
Application roots, page roots, and main-content roots must not hide or clip
horizontal overflow. Pagination, page actions, and filter controls must remain
outside accidental horizontal clipping.

The current stable routes use bounded data-list rows rather than document-wide
tables. The shared `data-table` container is bounded to its parent; non-tabular
lists are not converted into horizontal scroll areas.

## Conflicting Styles Removed or Replaced

- Root, shell, workspace, grid, panel, and detail containers now carry explicit
  bounded sizing semantics instead of relying on incidental shrink behavior.
- AppShell topbar and topbar actions now use the same wrapping contract as route
  headings and action groups.
- Runtime Reconciliation detail grids now use the same bounded detail-grid and
  narrow-collapse contract as Conference Operations, Runtime Operations, and
  Audit Records.
- Production root-level overflow clipping remains absent and is rejected by
  repository hygiene.

## Structural and Unit Enforcement

The frontend Vitest suite now includes deterministic source and DOM contract
coverage for:

- Primary routes using the expected page container.
- AppShell root, topbar, action, grid, and main-content hooks.
- Shared header/action wrapping hooks.
- Shared `UiFilterBar` responsive filter hooks.
- Shared `inline-form` form-grid hooks.
- Long-identifier wrapping hooks.
- Local data-list containment.
- Pagination outside inaccessible clipping containers.
- Bounded detail-panel and detail-grid hooks.
- Absence of root-level overflow-clipping hooks.

The representative route coverage includes AppShell, administration/form routes,
operational list/detail routes, and Audit Records without duplicating every
route test.

## Repository-Hygiene Enforcement

`scripts/check-repository-hygiene` rejects production `overflow-x: hidden`
introductions and explicitly guards root selectors from `overflow-x: hidden` or
`overflow-x: clip`. It also verifies the shared bounded sizing, wrapping,
filter, long-identifier, and detail-grid contracts that can be checked reliably
with low false-positive risk.

Rules that require real layout measurement remain reserved for natural browser
proof.

## Accessibility Preservation

This slice preserves accessible names, programmatic labels, logical DOM order,
keyboard reachability, visible focus styling, loading-focus behavior, pagination
loading guards, detail-close focus restoration, text-bearing status, and axe
assertions. Responsive behavior is expressed in CSS and shared component hooks,
not JavaScript viewport detection, DOM reordering, hidden controls, or forced
tab order.

## Query and Domain Behavior Preservation

No backend, API, Kubernetes, Reverb, signaling, data, authentication,
authorization, tenant-context, query, pagination, selection, or realtime
workflow behavior is changed by this slice.

## jsdom Proof Limitations

Vitest and jsdom do not perform real layout, so repository tests do not claim
pixel dimensions, actual `scrollWidth`, focus-ring painting, or true browser
overflow. They enforce deterministic structure and source contracts only.

## Deferred Work

- Focused natural responsive reproof at 375px, 768px, and desktop.
- Final bounded portfolio information-architecture and finish slice.
- Deferred `UiFilterBar` Apply busy-state limitation recorded by UI-E6.
