# UI-E11 - Portfolio Information-Architecture and Visual-Finish Preparation Audit

Verdict: `UI_E_PORTFOLIO_FINISH_PREPARATION_AUDIT_COMPLETE`

UI-E11 is a narrow read-only preparation audit for the final UI-E portfolio
information-architecture and visual-finish implementation slice. No production
code, dependency, Kubernetes manifest, backend authority, or domain authority
was changed. UI-E remains In Progress and `UTCP_PHASE=T1` remains unchanged.

## Starting Point

- Branch: `main`
- Starting HEAD: `67d07e4` (`docs(ui): prove button hover contrast`)
- Starting working tree: clean
- Phase marker: `UTCP_PHASE=T1`
- Push status: not pushed

Evidence authority read for this audit:
[`ui-e1`](ui-e1-accessibility-static-unit-enforcement.md),
[`ui-e2`](ui-e2-accessibility-browser-proof.md),
[`ui-e4`](ui-e4-focus-management-live-proof.md),
[`ui-e6`](ui-e6-pagination-loading-live-proof.md),
[`ui-e7`](ui-e7-responsive-contract-enforcement.md),
[`ui-e8`](ui-e8-responsive-contract-live-proof.md),
[`ui-e10`](ui-e10-button-hover-contrast-live-proof.md),
plus `AGENTS.md`, `CLAUDE.md`, `docs/roadmap/ui-foundations.md`, and
`docs/roadmap/phase-status.md`.

## Repository-Defined UI-E Finish Objective

`docs/roadmap/ui-foundations.md` defines five UI-E completion criteria:

1. Accessibility, keyboard, focus, and responsive contracts documented and enforced — **met** (UI-E1 through UI-E10).
2. Natural browser-proof conventions documented and used where material — **met**.
3. Shipped screens clean under console and network diagnostics — **met**.
4. **The UI presents UTCP as one coherent portfolio-quality product — the one open criterion.**
5. Unit, type, lint, and build checks pass — **met**.

The roadmap's included-scope line is "Portfolio-ready information architecture
and finish", and its remaining-implementation line is "Apply a portfolio-quality
information architecture and finish pass after UI-A through UI-D have stable
surfaces". UI-A through UI-D are Complete, so the precondition is satisfied.

Derived working definitions, consistent with the roadmap and not invented
beyond it:

- **Portfolio information architecture** — a reviewer who has never read the
  repository can tell what UTCP is, what the operator manages, how the major
  control-plane surfaces relate, and which action on each page is primary.
- **Visual finish** — the same structural, copy, and action patterns are applied
  consistently across every route, so no route reads as unfinished relative to
  its neighbours.
- **Coherent portfolio-quality product** — the nine authenticated routes plus
  Login read as one designed product rather than nine independently built screens.

## Live Inspection Basis

- Context `k3d-utcp-local`, namespace `utcp-platform`, `https://app.utcp.local.test`.
- Running web image `sha256:dce1a124…` with `org.opencontainers.image.revision=7eae240`, Pod ready.
- **No rebuild was required.** `67d07e4` is documentation-only on top of `7eae240`,
  so the running bundle already represents the completed UI-E10 frontend at HEAD.
- Fresh Playwright MCP context, observers attached before navigation, natural
  Login with a bounded break-glass credential, forced password change through the
  UI, `Local Tenant` selected through AppShell. No injected session, cookie,
  storage, or bypass.
- Reviewed at desktop `1280x720` and narrow `375x812`.
- Read-only throughout: navigation and filter queries only. Post-audit
  `tenants=27` and `runtime_nodes=110` match the counts the UI rendered, so no
  canonical record was mutated.

## Current Information Architecture

```text
Login  (unauthenticated)
└── AppShell  (topbar: product eyebrow, page H1, operator identity,
                        tenant selector, appearance, Menu, Log out)
    ├── sidebar: single flat "Navigation" list of 9 links
    │            + "Tenant context" block (tenant, catalog version)
    └── shell-content: one route view
                       └── .section-heading (H2 + optional live badge + Refresh)
                           └── UiPanel / UiDataList (H3) …
```

Heading hierarchy is H1 (shell) → H2 (view) → H3 (panels) on all nine
authenticated routes. Routes span two path families, `/admin/*` and
`/operations/*`, but the navigation is flat and exposes no such distinction.

## Route Matrix

| Nav label | Path | Page title / H2 | Description | Primary goal | Primary action | Secondary actions | Data presentation | Detail | Capability |
|---|---|---|---|---|---|---|---|---|---|
| — | `/login` | Sign in (H3, **no H1**) | none | Authenticate | Sign in | — | form | — | guest only |
| Dashboard | `/dashboard` | Dashboard | "Current operator and tenant context from the authenticated session." | Orient operator | **Refresh (primary variant)** | quick-nav links | 6 panels | — | auth only |
| Tenants | `/admin/tenants` | Tenants | **none** | Manage tenants | Create tenant | Refresh | list, `27 tenants` | inline | `platform.tenants.view` |
| Users | `/admin/users` | Users | **none** | Manage users | Create user | Refresh, filter | list, `Page 1 · 207 users` | `/admin/users/:id` | `platform.users.view` OR `tenant.memberships.view` |
| Memberships | `/admin/memberships` | Memberships | **none** | Manage tenant access | Add membership | Refresh | list, `205 memberships` | inline | `tenant.memberships.view` |
| Audit records | `/admin/audit-records` | Audit records | **none** | Inspect audit history | — (read-only) | Refresh, filter, pagination | list, `Page 1 · 2849 Audit records` | inline + Close | `tenant.memberships.manage` + active tenant |
| Runtime nodes | `/admin/runtime-nodes` | Runtime nodes | **none** | Manage runtime registry | Create runtime node | Refresh, Details, Activate/Drain, Disable | list, `110 RuntimeNodes` | inline | `runtime.nodes.view` |
| Runtime operations | `/operations/runtime-operations` | Runtime operations | **none** | Inspect operation lifecycle | — (read-only) | Refresh, filter, pagination | list, `Page 1 · 4730 Runtime Operations` | inline | `runtime.nodes.view` |
| Runtime reconciliations | `/operations/runtime-reconciliations` | Runtime reconciliations | **none** | Inspect reconciliation runs | — (read-only) | Refresh, filter, pagination | list, `Page 1 · 460 Runtime Reconciliations` | inline | `runtime.nodes.view` |
| Conferences | `/operations/conferences` | Conferences | **none** | Observe conferences | — (read-only) | Refresh | list, `122 Conferences` | inline | `telephony.conferences.view` |

Empty state on every list route is shared `UiEmptyState` (title + message, **no
action**). Error and forbidden states are shared `UiDataList` `UiAlert`s
(`"<Concept> unavailable"` / `"<Concept> forbidden"`). Loading is shared
`UiLoadingState`.

**Eight of nine authenticated routes present a bare heading with no statement of
purpose.** Only Dashboard has an introductory description, and that description
understates its own page.

## Navigation Findings

Answers to the required questions:

1. **Administration versus runtime/operations distinguishable?** No. The rendered
   `<nav aria-label="Primary">` has nine direct `<a>` children under one
   "Navigation" label. The `/admin` versus `/operations` split is invisible.
2. **Closely related routes adjacent?** Yes. Order is Dashboard → Tenants →
   Users → Memberships → Audit records → Runtime nodes → Runtime operations →
   Runtime reconciliations → Conferences, which already clusters identity and
   tenancy, then runtime, then telephony.
3. **Labels understandable without repository knowledge?** Mostly. "Runtime
   reconciliations" and "Runtime operations" are canonical UTCP domain terms and
   must not be renamed, but nothing on those pages explains them.
4. **Does order communicate a coherent workflow?** Yes, the sequence is sound.
5. **Labels technically correct but obscure?** "Runtime reconciliations" is the
   clearest case. The correct remedy is helper copy on the page, not a rename.
6. **Would groups improve comprehension?** Yes, modestly — they would make the
   already-correct clustering visible.
7. **Would grouping create empty sections under capability filtering?** **Yes, if
   implemented naively.** An operator holding only `telephony.conferences.view`
   would see empty "Administration" and "Runtime" headings. Any grouping must
   render a heading only when that group has at least one capability-visible
   entry. Note also that `Runtime nodes` is served from `/admin/runtime-nodes`
   but belongs to the runtime group conceptually, so grouping must be by concept,
   not by path prefix — no route may change.
8. **Does mobile preserve the hierarchy?** Yes. At 375x812 the sidebar collapses,
   the Menu button appears, and the opened menu renders the identical nine
   entries in the identical order. Active state (`aria-current="page"` plus
   `.active`) works in both.

## Dashboard Findings

Present purpose: operator and tenant orientation plus a live summary. It renders
six panels — Identity, Runtime nodes, Users and TelephonySessions, Memberships,
Attention summary, Available management — from real authorized API reads with
per-panel loading, empty, unauthorized, and failure states, and a capability-filtered
quick-navigation list.

Assessment against the required checks:

- **Explains UTCP?** No. The string "Unified Telephony Control Plane" appears
  exactly **once** in the entire authenticated application (the topbar eyebrow),
  with no explanation of what it is or what it owns.
- **Orients the operator?** Partially. It shows who and which tenant, but not
  what the product does or how the surfaces relate.
- **Useful navigation?** Yes — the quick-navigation panel mirrors capability-filtered nav.
- **Meaningful existing state?** Yes — genuine counts, per-node desired/observed
  state, and a real attention summary derived from already-loaded data.
- **Duplicates other routes?** No; it summarises rather than replicates.
- **Appears unfinished?** Partially, for two specific reasons:
  - Its description, "Current operator and tenant context from the authenticated
    session", describes only the first panel while the page also carries runtime,
    users, memberships, and attention summaries.
  - Its `Refresh` renders `variant="primary"` and never binds `:loading`
    (measured live: `aria-busy` and `aria-disabled` both `null` while the
    dashboard was reloading). Every other route uses `variant="secondary"` with
    `:loading` and `loading-label="Refreshing"`. On the landing page a
    maintenance action is therefore styled as the page's primary call to action
    and is the only Refresh in the product that gives no busy feedback.

The Dashboard's data content is sufficient. It needs product framing and action
correction, not new data.

## Page-Hierarchy Findings

Consistent and correct, requiring no change:

- H1 → H2 → H3 on all nine authenticated routes.
- `Refresh` is always in the page heading, right-aligned.
- Filters always precede lists, via shared `UiFilterBar` on the four filterable routes.
- Destructive actions are last in row action groups and use the `danger` variant
  (`Details` secondary → `Activate`/`Drain` secondary → `Disable` danger), so
  primary and destructive actions are **not** ambiguous in list rows.
- Detail panels open inline and restore focus to the exact opener (UI-E4/UI-E10).

Actionable inconsistencies:

- **Missing page descriptions on 8 of 9 routes** (all except Dashboard). Verified
  live: `description: null` on Tenants, Users, Memberships, Audit records,
  Runtime nodes, Runtime operations, Runtime reconciliations, and Conferences.
- **Structural divergence in the heading block.** Dashboard wraps
  `<div><h2/><p class="meta"/></div>` inside `.section-heading`; the other eight
  place `<h2>` as a direct child. The wrapper form is already proven safe —
  `.section-heading > *` sets `min-width: 0; max-width: 100%`, and Dashboard
  renders correctly at 375px today.
- **Dashboard Refresh variant and loading divergence** (above).
- Empty states carry no next-step action (see below).

## Terminology Findings

Capitalisation of user-visible domain terms is inconsistent across routes and,
in two views, **within a single view**. All strings below were read from the
running application.

Rendered `UiListSummary` output — four conventions for the same construct:

| Route | Rendered summary | Convention |
|---|---|---|
| Tenants | `27 tenants` | lowercase |
| Users | `Page 1 · 207 users` | lowercase |
| Memberships | `205 memberships` | lowercase |
| Audit records | `Page 1 · 2849 Audit records` | Sentence case |
| Runtime operations | `Page 1 · 4730 Runtime Operations` | Title Case |
| Runtime reconciliations | `Page 1 · 460 Runtime Reconciliations` | Title Case |
| Conferences | `122 Conferences` | Title Case |
| Runtime nodes | `110 RuntimeNodes` | **PascalCase** |

Rendered panel titles — three conventions:

```text
Tenant list · User list · Membership list · Audit record list · Conference list   (sentence)
Runtime Operation list · Runtime Reconciliation list                              (Title Case)
RuntimeNode list                                                                  (PascalCase)
```

Within `RuntimeNodesView.vue` alone, one concept uses three conventions:

```text
title="RuntimeNode list"              empty-title="No RuntimeNodes"
loading-label="Loading runtime nodes."  error-title="Runtime nodes unavailable"
```

Within `ConferenceOperationsView.vue`, two adjacent props of the same component
instance disagree:

```text
empty-title="No conferences"     empty-message="No Conferences were returned."
```

API and domain type names leak into user-visible copy in PascalCase:

```text
"Users and TelephonySessions"          (Dashboard panel title)
"No RuntimeNodes were returned."       (Dashboard runtime card empty text)
"RuntimeNode action failed"            (RuntimeNodes alert title)
"RuntimeNode desired state updated."   (RuntimeNodes success notification)
```

Navigation labels are uniformly sentence case ("Runtime nodes", "Runtime
operations", "Runtime reconciliations", "Audit records"), so the navigation
label, the page heading, and the body copy for the same concept disagree.

Canonical domain terminology itself is correct and must be preserved.
`RuntimeNode`, `TelephonySession`, `Runtime Operation`, and
`Runtime Reconciliation` are canonical UTCP concepts; the defect is presentation
casing and type-name leakage, not the concepts. The remedy is sentence case for
user-visible prose plus explanatory helper copy, never renaming a concept.

## Visual-Consistency Findings

Consistent, requiring no change: page spacing, panel spacing, heading sizes,
supporting-text treatment, action-group spacing, filter density, form density,
list row density, detail metadata presentation, status badge usage, border and
surface hierarchy, destructive emphasis, long-identifier treatment, and the
shared loading component. UI-E7, UI-E8, and UI-E10 already enforce and live-prove
the layout, overflow, focus, and contrast dimensions.

The inconsistencies found are **not** token or stylesheet defects. Classified by
origin:

| Inconsistency | Origin |
|---|---|
| 8 routes lack a page description | route-local omission of a pattern only Dashboard implements |
| Dashboard heading uses a wrapper div, others do not | route-local duplication |
| Dashboard Refresh primary and not loading-bound | route-local divergence from the shared pattern |
| Copy casing across `item-label`, `empty-*`, `error-title`, panel `title` | route-local copy, no shared convention |
| Empty states offer no next step | shared component (`UiEmptyState` exposes no action slot) |
| Duplicate panel `label` values on one page (`Tenant access` twice on Memberships; `Runtime registry` twice on Runtime nodes; `Operations` twice on Runtime operations) | route-local copy |

No new palette, typography system, icon library, CSS framework, or animation is
needed, and none is recommended. `style.css` needs **no** change: the wrapper
form is already supported and already proven at 375px.

## Desktop Live Findings

At `1280x720`, all nine authenticated routes rendered correctly with consistent
H1/H2/H3 hierarchy, working active navigation state, shared panels, and shared
list summaries. Findings are exactly those recorded above: absent descriptions on
eight routes, the Dashboard Refresh divergence, and the copy-casing conflicts.
No layout, overflow, contrast, focus, or console defect was observed.

The live-updates badge read "Live updates disconnected — displayed data may be
stale" on Runtime nodes and Runtime operations and "Live updates connecting" on
Runtime reconciliations and Conferences during the sweep. This is genuine
transient realtime connection state, correctly surfaced as text, and is not a
finish defect.

## Narrow Live Findings

At `375x812`: `documentElement.scrollWidth` 375 equals `innerWidth` 375, the
sidebar collapses, the Menu button appears, the page H1 remains visible, and the
opened menu preserves the identical nine entries in the identical order with the
same single "Navigation" label. No narrow-specific finish defect was found, and
no responsive regression exists. Because the sidebar is hidden at 375px, any
navigation grouping introduced later must be verified in the opened mobile menu,
not only on desktop.

## Must-Fix Findings

**MUST_FIX_FOR_UI_E-1 — No product orientation anywhere in the application.**
Route: all, most acutely `/login` and `/dashboard`. The product name appears once
(topbar eyebrow) and the Login page — the entry point for any portfolio reviewer —
renders only "Unified Telephony Control Plane", "Sign in", and two fields, with
no statement of what UTCP is or what it manages. Selector: `.app-topbar .eyebrow`,
`LoginView.vue` heading block, `DashboardView.vue` `.section-heading`. This
directly defeats completion criterion 4. Smallest seam: a static product-orientation
line on Login and a short static explanation panel on Dashboard, using existing
shared components and no new data. Shared and route-specific.

**MUST_FIX_FOR_UI_E-2 — Eight of nine routes state no purpose.**
Routes: Tenants, Users, Memberships, Audit records, Runtime nodes, Runtime
operations, Runtime reconciliations, Conferences. Selector:
`.shell-content .section-heading`. Measured live as `description: null`. A
reviewer cannot tell what "Runtime reconciliations" is or why it exists. Smallest
seam: adopt the Dashboard's already-proven `<div><h2/><p class="meta"/></div>`
wrapper in the eight views and add one sentence each. Shared pattern,
route-specific copy.

**MUST_FIX_FOR_UI_E-3 — User-visible terminology casing conflicts across and within routes.**
Routes: all list routes. Selectors: `.ui-list-summary`, `.ui-empty-state strong`,
`.ui-panel h3`, `UiDataList` `error-title`/`forbidden-title`. Four conventions
render for the same construct, three appear inside `RuntimeNodesView.vue` alone,
two adjacent props disagree inside `ConferenceOperationsView.vue`, and PascalCase
API type names (`RuntimeNodes`, `TelephonySessions`) surface as user-visible copy
that contradicts the sentence-case navigation labels. Smallest seam: normalise
user-visible prose to sentence case in view copy props; preserve canonical
concept names. Shared convention, route-local edits.

**MUST_FIX_FOR_UI_E-4 — Dashboard action hierarchy and busy state diverge from every other route.**
Route: `/dashboard`, desktop and narrow, both themes. Visible text "Refresh".
Selector: `.shell-content .section-heading button`. Current behavior: renders
`ui-button--primary`; while the dashboard reloads, `aria-busy` and
`aria-disabled` both remain `null`. Every other route renders
`ui-button--secondary` with `:loading` and `loading-label="Refreshing"`. On the
landing page a maintenance action therefore reads as the primary call to action
and is the only Refresh giving no busy feedback. Smallest seam:
`DashboardView.vue` — set `variant="secondary"`, bind `:loading` to the existing
`attentionLoading` computed, and add `loading-label="Refreshing"`. Route-specific,
aligning to the shared pattern.

## Should-Fix Findings

**SHOULD_FIX_IF_BOUNDED-1 — Navigation exposes no grouping.**
Nine flat links under one "Navigation" label while routes span `/admin/*` and
`/operations/*`. Ordering and adjacency are already correct, so this is
comprehension polish rather than confusion. If implemented, groups must render
only when they contain at least one capability-visible entry, must group by
concept rather than path (`Runtime nodes` is served from `/admin/`), must add no
route, must not change capability authority, and must be verified in the opened
375px menu.

**SHOULD_FIX_IF_BOUNDED-2 — Login and Change-password have no `<h1>`.**
Confirmed live: `/login` headings are `["H3:Sign in"]` with `hasH1: false`. This
is UI-E2's still-open non-blocking moderate `page-has-heading-one` axe finding.
It is naturally resolved if MUST_FIX-1 introduces a product-level heading on
Login. Recorded as should-fix because it closes a known open finding rather than
reopening a completed corridor.

**SHOULD_FIX_IF_BOUNDED-3 — Empty states offer no next-step action.**
Verified live on a filtered Audit query: the rendered node is exactly
`<div class="ui-empty-state"><strong>No Audit records</strong><p>No Audit records
matched the current filters.</p></div>` with no button or link. Mitigated because
filterable routes expose a `Clear` control in the filter bar above, so no route is
a dead end. Smallest seam: an optional action slot on `UiEmptyState`, used only
where a genuine next step exists.

## Optional Polish

- Duplicate panel `label` values on a single page (Memberships "Tenant access"
  twice; Runtime nodes "Runtime registry" twice; Runtime operations "Operations"
  twice).
- `"<Concept> forbidden"` error titles are terse and technical for an end user.
- Conferences has no filter bar while the other three high-volume list routes do.

## Not Defects

- Heading hierarchy H1 → H2 → H3 is consistent on all nine authenticated routes.
- Active navigation state (`aria-current="page"` plus `.active`) is correct.
- Mobile menu preserves the desktop order and hierarchy exactly.
- Destructive-action placement and emphasis in list rows are unambiguous.
- Navigation ordering and adjacency already reflect a coherent operator workflow.
- Topbar placement of tenant selector, appearance control, and Log out is consistent.
- Live-updates badge text differences reflect genuine realtime state.
- Dashboard data content is sufficient; it needs framing, not new data.
- Spacing, density, badges, surfaces, and long-identifier treatment are consistent.

## Deferred Future Scope

- `UiFilterBar` Apply `loading` prop (UI-E6's recorded deferred busy-state gap).
- TelephonySession and listener-health views (T2/T3/V0-gated, recorded by UI-D).
- Conferences filter bar, if conference volume later justifies it.

## Recommended Final Implementation Boundary

**One bounded slice: UI-E12 — Portfolio Information Architecture and Visual Finish.**

One commit is safe and coherent because every change is presentation-layer copy
and variant selection inside view templates plus one optional shared component
slot. No CSS, token, router, capability, state, API, or component-contract change
is required, so no completed corridor is disturbed.

In scope:

1. Add a product-orientation line to Login as an `<h1>`-level product title,
   resolving MUST_FIX-1 and SHOULD_FIX-2 together.
2. Add a short static "What UTCP is" explanation panel to Dashboard using existing
   `UiPanel`, with static copy only — no new endpoint, aggregate, metric, or statistic.
3. Correct the Dashboard description to describe the whole page.
4. Set the Dashboard `Refresh` to `variant="secondary"`, bind `:loading` to the
   existing `attentionLoading`, and add `loading-label="Refreshing"`.
5. Adopt the Dashboard's proven `<div><h2/><p class="meta"/></div>` heading
   wrapper in the eight remaining primary views and add one purpose sentence each,
   explaining "Runtime reconciliations" and "Runtime operations" in plain language.
6. Normalise user-visible copy to sentence case across `item-label`, `empty-title`,
   `empty-message`, `error-title`, `forbidden-title`, `loading-label`,
   `refreshing-label`, and panel `title`, and remove PascalCase API type names from
   user-visible strings, preserving canonical concept meaning.
7. Optionally, if it stays within the same commit, add navigation grouping that
   renders a group heading only when it holds at least one capability-visible entry.

Deliberately excluded from the slice: the optional-polish items and all deferred
future scope.

## Exact Production Files

```text
apps/web/src/views/LoginView.vue
apps/web/src/views/DashboardView.vue
apps/web/src/views/TenantsView.vue
apps/web/src/views/UsersView.vue
apps/web/src/views/MembershipsView.vue
apps/web/src/views/AuditRecordsView.vue
apps/web/src/views/RuntimeNodesView.vue
apps/web/src/views/RuntimeOperationsView.vue
apps/web/src/views/RuntimeReconciliationsView.vue
apps/web/src/views/ConferenceOperationsView.vue
```

Only if navigation grouping is included:

```text
apps/web/src/navigation.ts
apps/web/src/layouts/AppShell.vue
apps/web/src/components/ui/UiEmptyState.vue   (only if SHOULD_FIX-3 is included)
```

`apps/web/src/style.css` must **not** change. `.section-heading > *` already sets
`min-width: 0; max-width: 100%`, the wrapper form already renders correctly at
375px on Dashboard, and `scripts/check-repository-hygiene` asserts four
`.section-heading` CSS contracts that must remain byte-stable.

## Exact Test Files

```text
apps/web/src/App.test.ts
apps/web/src/components/ui/UiComponents.test.ts   (only if UiEmptyState gains a slot)
```

Required additions:

- Assert every primary view renders a non-empty page description in its
  `.section-heading`.
- Assert the Dashboard `Refresh` uses `variant="secondary"` and forwards a
  `loading` binding.
- Assert no user-visible copy prop contains a PascalCase API type name
  (`RuntimeNode`, `RuntimeNodes`, `TelephonySession`, `TelephonySessions`).
- Assert nav label, page heading, and list `item-label` agree in casing per route.
- Assert Login exposes exactly one `<h1>`.
- Preserve unchanged: the `class="section-heading"`, `class="workspace"`, and
  `<UiPanel|UiDataList>` per-route source assertions at `App.test.ts:3367-3371`,
  the `.section-heading` responsive assertions, and every existing axe assertion.

`App.test.ts:3369` asserts each view **source** literally contains
`class="section-heading"`. Keeping that literal class in each view — rather than
extracting a `UiPageHeader` component — preserves the assertion unchanged and is
why no new shared component is recommended.

## Required Browser Reproof

One focused natural Playwright MCP proof, not a repeat of any completed corridor:

1. Natural Login, forced password change, `Local Tenant` selection.
2. Every primary route renders a visible page description.
3. Login shows the product orientation and exactly one `<h1>`.
4. Dashboard shows the product explanation, and its `Refresh` is secondary and
   exposes `aria-disabled="true"` plus `aria-busy="true"` while loading, clearing on completion.
5. Rendered casing agrees across nav label, heading, list summary, and empty state
   on every route, with no PascalCase API type name visible.
6. If grouping ships: groups render on desktop and inside the opened 375px menu,
   and no empty group heading appears under capability filtering.
7. Regression sanity only, not full re-proof: axe 0 serious and 0 critical on
   Login, Dashboard, and two list routes in Light and Dark; root
   `scrollWidth <= innerWidth` at 375x812; 0 page errors; 0 unexpected console
   errors beyond the established pre-login `401 /auth/session` probe; storage
   limited to `utcp.appearance` and `pusherTransportTLS`.

## Explicit Exclusions

The implementation must not add backend endpoints, new domain data, new runtime
state, fake dashboard metrics, new roles or permissions, new routes, new
telephony behavior, new WebSocket behavior, new infrastructure, feature gates,
manual activation, analytics or tracking, external fonts, icon libraries, CSS
frameworks, animation libraries, a marketing website, a total visual redesign, or
any T2/T3/V0/T4 functionality. It must not change `style.css`, tokens, the
router, capability authority, tenant context, API or domain authority, or
realtime notification-only semantics, and must not alter `UTCP_PHASE`.

## Preserved Contracts

Accessibility lint and axe enforcement; natural keyboard navigation; focus
management; the loading activation guard; the pagination loading guard; detail
focus restoration; the responsive contract; button hover contrast; query and
request budgets; authentication and authorization; tenant context; API and domain
authority; realtime notification-only semantics; and `UTCP_PHASE=T1`.

## UI-E Closure Criteria

UI-E may be marked Complete after the UI-E12 implementation and its focused
browser proof when all of the following hold:

1. **Information architecture** — a reviewer can state what UTCP is and what each
   route manages from the UI alone.
2. **Dashboard and product orientation** — Login and Dashboard both present static
   product framing; the Dashboard description matches its actual content.
3. **Route hierarchy** — all nine authenticated routes present H1 → H2 → H3, a
   description, and a Refresh in the same position with the same variant.
4. **Terminology** — user-visible prose is sentence case; nav label, heading, list
   summary, and empty state agree per route; no PascalCase API type name is visible.
5. **Action hierarchy** — exactly one primary action per route where one exists;
   Refresh is secondary everywhere; destructive actions remain danger-variant and last.
6. **Shared visual consistency** — no route-local duplication of a pattern the
   shared components already own; `style.css` unchanged.
7. **Empty, loading, and error presentation** — consistent across routes; no route
   reads as unfinished.
8. **Accessibility preserved** — axe 0 serious and 0 critical on the reproofed
   routes in Light and Dark; accessibility lint still active; Login exposes one `<h1>`.
9. **Responsive preserved** — root `scrollWidth <= innerWidth` at 375x812 on the
   reproofed routes; no new local scroll region.
10. **Focus and keyboard preserved** — loading and pagination guards, detail focus
    restoration, and `:focus-visible` behavior unchanged.
11. **Console and network hygiene** — 0 page errors, 0 unexpected console errors
    beyond the established pre-login probe, 0 requests on theme change and tabbing,
    exactly one per explicit action.
12. **Portfolio coherence** — the ten surfaces read as one designed product.
13. Frontend typecheck, lint, test, and build pass; `make repository-hygiene`,
    `make workflow-check`, and `make secret-scan` pass.
14. `UTCP_PHASE=T1` unchanged and nothing pushed.

Hosted production deployment is **not** required; the roadmap does not require it.

## Cleanup

Appearance was already `System` at the end of the session; menus and detail
panels closed; filters returned to the default query; pagination never left page
one; logged out naturally to `/login`; Playwright context closed; observers
discarded with the context; `.playwright-mcp/` removed; no screenshots retained;
break-glass credential and scratch files removed; no port-forward was started.
Storage ended limited to `utcp.appearance` (`system`) and `pusherTransportTLS`.
Post-audit `tenants=27` and `runtime_nodes=110` confirm no canonical record was mutated.
