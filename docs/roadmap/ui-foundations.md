# UI Foundations Roadmap

This document is the authoritative roadmap source for UI-A through UI-E scope, status, test contracts, browser-proof contracts, and completion criteria.

The UI-R0 audit in [`docs/evidence/ui/ui-foundations-roadmap-audit.md`](../evidence/ui/ui-foundations-roadmap-audit.md) found no prior authoritative Foundation A-E or UI-A through UI-E definition in the repository or Git history. UI-A through UI-E are therefore a new evidence-backed UTCP roadmap decision derived from the current frontend, canonical API, session, authorization, and application architecture. They are not a recovered historical decision.

## Documentation Hierarchy

| Document | Authority |
| --- | --- |
| `docs/roadmap/ui-foundations.md` | Authoritative UI Foundation scope and acceptance. |
| [`docs/roadmap/implementation-roadmap.md`](implementation-roadmap.md) | Sequencing and phase interleaving. |
| [`docs/roadmap/phase-status.md`](phase-status.md) | Concise current status ledger. |
| [`docs/evidence/ui/`](../evidence/ui/) | Audit, implementation, and browser evidence. |

Detailed acceptance criteria belong in this document. The implementation roadmap should describe sequencing and interleaving without duplicating this file's criteria. The phase-status ledger should remain concise.

## Shared UI Versus Domain Authority

```text
UI-A through UI-E
→ reusable frontend architecture and interaction foundations

C/T/V phases
→ domain behavior, lifecycle, APIs, authorization, and runtime execution
```

Completing a UI foundation does not complete a domain phase. Completing one domain screen does not complete a UI foundation.

Backend authorization remains authoritative. The frontend consumes server-provided capabilities and catalogs; it does not compute business authorization or define checked-in runtime capability catalogs. The frontend renders server catalogs and submits canonical intent through authorized APIs.

The frontend does not manage PBX, Kamailio, Redis, Kubernetes, reconciliation, or runtime state directly. WebSocket and Reverb messages are notifications only; canonical state must always be re-read from backend APIs.

## Sequencing

```text
UI-A
→ UI-B
→ UI-C
→ UI-D

UI-E
→ continuous and cross-cutting
```

UI-A and initial UI-B token work can begin immediately. UI-A is the first bounded implementation because it replaces the homemade router and decomposes the monolithic shell into maintainable route-level views.

UI-A gates maintainable UI-C and UI-D expansion. UI-D feature completion can depend on T2, T3, V0, and later call-control phases. T4 must reuse normalized UI behavior rather than create a FreeSWITCH-specific frontend. Later call, trunk, and route screens build on UI-A through UI-C. Runtime correctness work is not blocked by incomplete visual polish.

## Open-Source Adoption Boundaries

Preserve:

- Vue 3
- Vite
- TypeScript
- Native canonical API client
- First-party session and CSRF flow

Vue Router is the selected immediate UI-A dependency. Lightweight or headless UI dependencies may be evaluated later. A heavy admin template is not selected. No dependency may introduce duplicate routing, authentication, authorization, state authority, or backend catalogs. No frontend stack replacement is planned.

## Browser-Proof Contract

Where browser evidence is material, UI foundation proof must use natural Playwright MCP flows. Browser proof must:

- Start from the real login page.
- Authenticate normally.
- Select tenant context through the normal UI.
- Use server-returned capabilities.
- Navigate actual routes.
- Exercise applicable success, loading, empty, error, and unauthorized states.
- Capture console and relevant network errors.
- Avoid injected cookies, preset sessions, database-created sessions, Redis-created sessions, or authentication bypasses.

Browser proof is not required for documentation-only changes. It is also not required for component-unit-only UI-B work where repository tests are sufficient, but UI-B presentation should still be exercised through later route and workflow proofs.

## UI-A — Application Shell, Routing, and Navigation

Status: Complete

### Objective

Replace the monolithic handmade application-routing structure with a maintainable canonical frontend shell.

### Included Scope

- Official Vue Router integration.
- Route-level view components.
- Shared authenticated `AppShell`.
- Capability-gated navigation.
- Tenant context.
- Login and change-password route boundaries.
- Session rejection and logout redirects.
- Breadcrumb or route-context support.
- Not-found and unauthorized route handling.
- Preservation of direct URL navigation and browser history behavior.

### Current Evidence

- UI-A1 repository implementation added official Vue Router as the frontend route authority for `/login`, `/change-password`, `/dashboard`, current admin routes, `/forbidden`, and not-found routes.
- The former homemade `pathname`, `pushState`, and `popstate` router authority was removed from production frontend code.
- `App.vue` is reduced to top-level router rendering.
- Route-level views now own login, password change, dashboard, tenants, memberships, users, user detail, runtime nodes, forbidden, and not-found screens.
- A shared authenticated `AppShell` provides product identity, capability-aware navigation, tenant context, current user context, logout, route title context, and responsive navigation.
- The dashboard uses existing canonical APIs for RuntimeNode, user, TelephonySession-summary, and membership orientation where server-returned capabilities permit them.
- Frontend tests were strengthened from sixteen to twenty-one tests covering router guards, direct URLs, dashboard summaries, capability-filtered navigation, forbidden/not-found routes, and existing management regressions.
- UI-A2 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-a2-application-shell-dashboard-browser-proof.md`](../evidence/ui/ui-a2-application-shell-dashboard-browser-proof.md)) live-proved the deployed `90d02b4` frontend (web image digest `sha256:29abd658…`) through Playwright MCP: real-login with forced password change, dashboard as the authenticated landing page, dashboard summaries each backed by a canonical authenticated API, capability-aware navigation across no-tenant and tenant-selected states for a broad-capability user, a zero-capability limited user (dashboard usable, admin navigation absent, `/admin/users` guarded to `/forbidden`), a management route through the UI, direct URL and reload, browser back/forward, not-found handling, tenant switching with session and dashboard recalculation, transient secret handling, and logout with post-logout protection.

### Remaining Implementation

- None for UI-A. Use the UI-A shell as the foundation for later UI-C and UI-D route expansion.
- Continue preserving direct navigation, browser history, tenant switching, capability gates, login, change-password, session rejection, logout behavior, and write-only secret handling as additional routes are added.

### Test Contract

- Preserve existing frontend unit tests.
- Add focused router tests for route matching, guards, redirects, direct navigation, and browser-history behavior.
- Keep unit, type, lint, and build checks passing.

### Browser-Proof Contract

Run natural Playwright browser proof for applicable route flows after implementation: login through the real UI, select tenant context normally, navigate current admin routes, verify guarded navigation, exercise direct URLs and browser back/forward behavior, and capture console and relevant network errors.

### Non-Goals

- Backend authorization changes.
- New domain screens.
- State-management framework adoption without need.
- Visual design-system completion.
- T3 media or V0 runtime implementation.

### Completion Criteria

- Vue Router is authoritative.
- Current routes preserve their behavior.
- Views are decomposed from `App.vue`.
- Shared shell and navigation are reusable.
- Capability and tenant boundaries are preserved.
- Unit, type, lint, and build checks pass.
- Natural Playwright browser proof passes for applicable route flows.

## UI-B — Design System and Reusable Component Library

Status: Complete

### Objective

Create a reusable frontend presentation foundation that keeps styling, layout primitives, and common controls consistent across UTCP management screens.

### Included Scope

- Semantic design tokens.
- Light and dark themes.
- Typography and spacing scales.
- Shared buttons, inputs, selects, dialogs, panels, badges, and status indicators.
- Accessible focus and interaction states.
- Responsive layout primitives.
- Consistent telephony state presentation.

### Current Evidence

- UI-B1 repository implementation added `apps/web/src/styles/tokens.css` as the semantic token authority for color, focus, spacing, typography, radii, shadows, and layout values.
- UI-B1 added deterministic system, light, and dark theme behavior using root `data-appearance` and `data-theme` attributes.
- Appearance preference is stored only as local presentation state under `utcp.appearance`; no user, tenant, capability, credential, session, API, or telephony state is stored with it.
- Core accessible primitives now exist for buttons, form fields, text inputs, selects, panels, status badges, alerts, loading states, and empty states.
- `AppShell`, Dashboard, and `/admin/users` have adopted the token and component system.
- Focused frontend tests now cover theme state, theme control behavior, core component contracts, AppShell regressions, Dashboard regressions, and representative management-view adoption.
- UI-B2 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-b2-themes-components-browser-proof.md`](../evidence/ui/ui-b2-themes-components-browser-proof.md)) live-proved the deployed `3b643d0` frontend (web image digest `sha256:f3e23437…`) through Playwright MCP: the system/light/dark contract, runtime media-query response, explicit light/dark persistence across reload and navigation, invalid-value recovery to system, the `utcp.appearance`-only storage boundary, an accessible keyboard-operable theme control that makes no API request, AppShell/Dashboard/Users adoption in both themes, core component semantics, responsive behavior, reduced-motion, WCAG-AA core-token contrast in both themes, and no material wrong-theme flash.
- UI-B2 remaining-view repository implementation (evidence: [`docs/evidence/ui/ui-b2-remaining-view-component-adoption.md`](../evidence/ui/ui-b2-remaining-view-component-adoption.md)) migrated Login, Change Password, Tenants, Memberships, Runtime Nodes, and User Detail to the shared token/component authority, removed duplicate screen-local visual-control styling in those views, corrected the Users mobile metadata overflow contract, preserved existing backend API/domain behavior, and added focused component-adoption, role-catalog, adapter-seam, credential-storage, and responsive-layout regression coverage.
- UI-B3 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-b3-management-view-browser-proof.md`](../evidence/ui/ui-b3-management-view-browser-proof.md), deployed web digest `sha256:4ab3349e…`) live-proved the `f375dd7` frontend through Playwright MCP: natural login, forced change-password, Tenants, Memberships (with server `admin/roles` catalog authority and no hard-coded role list), Runtime Nodes (server `runtime-node-catalog`; write-only credential secrecy with fingerprint-only readback), Users, User Detail (capability- and session-gated controls), light/dark with zero theme API requests and preserved route/tenant/session state, core component and keyboard semantics, and logout with post-logout protection. It also completed the page-level `scrollWidth` proof UI-B2 left pending and found the **Users 375px page-level overflow is not corrected** (`documentElement.scrollWidth 691` vs `innerWidth 375`): the `@media (max-width: 720px)` `.data-row { flex-direction: column }` rule does not reset the base `flex-wrap: wrap`, so per-row metadata wraps into a second off-viewport column. Tenants, Memberships, Runtime Nodes, User Detail, Dashboard, and Login all measured 0 page overflow at 375px. This is a blocking UI-B completion defect.
- UI-B4 repository correction (evidence: [`docs/evidence/ui/ui-b4-users-mobile-overflow-fix.md`](../evidence/ui/ui-b4-users-mobile-overflow-fix.md)) removed the conflicting Users mobile row wrap behavior by setting narrow `.data-row` rows to `flex-wrap: nowrap` and `height: auto` under the existing `@media (max-width: 720px)` vertical-column contract. A focused repository-hygiene check now verifies the narrow `.data-row` contract and rejects root-level overflow masking.
- UI-B5 controlled focused browser proof (evidence: [`docs/evidence/ui/ui-b5-users-mobile-overflow-live-proof.md`](../evidence/ui/ui-b5-users-mobile-overflow-live-proof.md), deployed web digest `sha256:fd9d2201…`) live-proved the `53f0f32` frontend through Playwright MCP: natural login, Users loaded through the normal navigation, and `/admin/users` at a 375px viewport with **no page-level horizontal overflow** in both Light (`documentElement.scrollWidth 375` == `innerWidth 375`) and Dark (`375` == `375`). Computed narrow `.data-row` resolves to `flex-direction: column` / `flex-wrap: nowrap`, the metadata `.subgrid` stays in a single vertical column within the row and viewport (right 350 ≤ 375), the offending-element scan returned zero out-of-bounds elements with no root-level overflow clipping, long identity text wraps, badges and actions remain usable, user-detail navigation and return preserve the layout, theme switching made zero API requests and did not change the dataset/tenant/session, and the desktop 1280px layout (`.data-row` `flex-direction: row`, three-column subgrid) remained intact. This closes the page-level `scrollWidth` gap UI-B3 identified.

### Remaining Implementation

- None for UI-B. Standardizing additional telephony and runtime status presentation is deferred to UI-C/UI-D domain work that reuses this component foundation.

### Test Contract

- Add component unit tests for key primitives, disabled states, error states, ARIA attributes, and focus behavior where practical.
- Keep type, lint, and build checks passing.

### Browser-Proof Contract

Browser proof is not required for token or primitive-only work. Visual and interaction proof should be included when these components are consumed by UI-A, UI-C, UI-D, or UI-E flows.

### Non-Goals

- Large admin-template replacement.
- Duplicate routing, auth, or state systems.
- Domain lifecycle decisions.
- Chart/dashboard completion.

### Completion Criteria

- Semantic tokens and light/dark themes exist.
- Core reusable controls and layout primitives exist.
- Existing screens use the shared primitives where applicable.
- Focus, interaction, and status states are accessible and consistent.
- Unit, type, lint, and build checks pass.

## UI-C — Data Interaction and Management Workflows

Status: Complete

### Objective

Provide reusable data-management patterns so UTCP operator workflows present canonical API state, user intent, and failure conditions consistently.

### Included Scope

- Shared tables.
- Filtering, sorting, and pagination.
- Forms and validation.
- API error presentation.
- Loading, empty, success, degraded, unauthorized, and failure states.
- Notifications.
- Catalog-driven adapter and capability forms.
- Confirmation patterns for destructive operations.
- Write-only secret-return handling.

### Current Evidence

- Pagination and forms exist.
- `ApiRequestError` provides a typed API failure boundary.
- Several management pages already operate.
- UI-B2 migrated the existing Runtime Nodes adapter form to shared visual primitives while keeping one bounded view-local `asterisk-ari` rendering seam.
- UI-C1 repository implementation (evidence: [`docs/evidence/ui/ui-c1-async-state-notifications-runtime-node-loading.md`](../evidence/ui/ui-c1-async-state-notifications-runtime-node-loading.md)) added shared read-resource states (`idle`, `loading`, `success`, `empty`, `refreshing`, `error`, `forbidden`), shared mutation states (`idle`, `submitting`, `succeeded`, `failed`), one app-level notification authority, neutral informational handling for the pre-login session-probe `401`, on-demand RuntimeNode detail loading, node-scoped RuntimeNode field IDs, and representative action feedback for RuntimeNode, User, and Membership mutations.
- Runtime Nodes initial load now remains bounded to the shared catalog/list requests and no longer fans out detail requests per listed node. Per-node adapter configuration, runtime evidence, and history are loaded only when the node detail/editor panel is opened, cached only in current memory, and invalidated on tenant/session changes or relevant node mutations.
- UI-C2 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-c2-async-state-notifications-runtime-node-browser-proof.md`](../evidence/ui/ui-c2-async-state-notifications-runtime-node-browser-proof.md), deployed web digest `sha256:3fd8ac58…`) live-proved the `4f1c40b` frontend through Playwright MCP: the pre-login session-probe `401` renders as a neutral `role="status"` "Sign in to continue" guidance (no "Authentication failed" title, no `aria-invalid`, no notification), while a submitted invalid credential produces a distinct `role="alert"` "Authentication failed" / "Invalid credentials." error with the password field `aria-invalid` and no notification (password never toasted); with **110 RuntimeNodes** the initial load issued exactly **2 requests** (`runtime-node-catalog` + `runtime-nodes`) and **zero per-node detail requests**; opening one node loaded only that node's three detail endpoints, reopening it unchanged issued zero requests (bounded in-memory cache), and opening a second node loaded only its own details; a tenant switch through the AppShell issued `auth/tenant-context` + a list refresh, cleared node detail state (tenant B showed no carried-over detail), and reopening the original node under tenant A forced a fresh canonical reread; a disposable user suspend produced a `role="status"` success notification (stable id, dismiss button, no secret) with automatic list refresh, restored via the UI; a Playwright-intercepted controlled mutation `500` produced a persistent `role="alert"` error notification, left canonical state unchanged, and kept the action retryable; notification IDs are unique with exactly one mounted region and no notification state in browser storage; all rendered RuntimeNode credential-field IDs are node-scoped and unique (0 duplicates, 0 bare `credential-secret` literals, all `label[for]` resolved); and an intercepted single-node detail `500` isolated the error to that node's panel while the 110-row list and other opened nodes stayed usable, with retry succeeding after interception removal. No secret appeared in any notification, ID, URL, or storage; the only console errors were the classified expected/deliberate `401`/`422`/`500` responses.
- UI-C3 repository implementation (evidence: [`docs/evidence/ui/ui-c3-list-query-filter-sort-pagination.md`](../evidence/ui/ui-c3-list-query-filter-sort-pagination.md)) added a shared typed URL-backed list-query contract, shared `UiFilterBar`, `UiPagination`, `UiDataList`, and `UiListSummary` primitives, deterministic invalid-query normalization, Vue Router push/replace history semantics, Users search/status/page/page-size restoration, and unsupported-parameter cleanup for Memberships, Tenants, and Runtime Nodes without adding fake filters or client-side page-only sorting.
- Runtime Nodes continues to preserve the UI-C1 bounded initial request budget, zero initial per-node detail fan-out, on-demand detail loading, current-memory detail cache, tenant-switch detail clearing, and unique node-scoped credential-field IDs while adopting the shared list presentation.
- UI-C4 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-c4-list-query-history-pagination-browser-proof.md`](../evidence/ui/ui-c4-list-query-history-pagination-browser-proof.md), deployed web digest `sha256:47232dd1…`) live-proved most of the UI-C3 contract on the `dd03c21` frontend through Playwright MCP: Users direct-URL restoration with default-parameter normalization (`?page=2&per_page=20&status=active` → URL `?page=2&status=active`, request `status=active&page=2&per_page=20`), reload preservation, filter Apply producing one router navigation + one canonical request with page reset to 1, page-size change resetting page to 1 with filters preserved, page-only navigation preserving other state with correct Previous/Next disabled states, unchanged Apply producing zero duplicate request or history entry, browser Back ×3 and Forward restoring URL/controls/page/request/summary, invalid-query normalization via route replacement without loops (`page=0`/`-1`/`not-a-number` → page 1, unsupported `per_page` → default, unknown `status`/`sort`/`direction` removed, valid filters preserved), absence of sorting controls with canonical server order, Memberships/Tenants stripping all unsupported list parameters with server role-catalog authority and no fake pagination/sorting and unchanged authenticated tenant context, the preserved RuntimeNode 2-request initial budget with zero per-node detail fan-out (110 nodes) under both normal and unsupported-parameter navigation, scoped on-demand detail loading with bounded in-memory reuse, tenant-switch list and detail isolation with canonical reread and no stale-cache reuse, refresh retaining existing rows with a visible refreshing state and a controlled refresh failure kept distinct from empty with successful retry, intact 375px responsive behavior with zero page-level overflow on all lists, and a storage boundary holding only `utcp.appearance`. **A blocking stale-response defect was found:** with a delayed older Users query A and a newer query B, releasing A overwrites the rendered list so `/admin/users?status=suspended` displayed 206 active users. Root cause: `refreshUsers` writes module-level `users`/`userPagination` inside the resource loader, so the `useAsyncResource` `requestId` guard (which only protects `state.data`, not the module refs the view renders) does not prevent the stale overwrite.
- UI-C5 repository implementation (evidence: [`docs/evidence/ui/ui-c5-users-stale-response-fix.md`](../evidence/ui/ui-c5-users-stale-response-fix.md)) corrected the Users stale-response rendering authority: `refreshUsers` now returns a combined Users rows + pagination result, `UsersView` renders rows and pagination from the guarded `useAsyncResource` result, accepted results are applied to legacy module refs only after the request-generation guard accepts them, user mutations reread the current canonical query through the guarded path, and tenant switching invalidates the prior Users resource generation before loading the new tenant query.
- UI-C6 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-c6-users-stale-response-live-proof.md`](../evidence/ui/ui-c6-users-stale-response-live-proof.md), deployed web digest `sha256:7cc0e769…`) live-proved the UI-C5 correction on the `a021fd0` frontend through Playwright MCP and **closes the blocking UI-C3 stale-response gap**: with query A (`status=active`, 206 users, "Page 1 of 11", Next enabled) held unresolved via request interception and query B (`status=suspended`, 1 user "UI-A2 Limited User", "Page 1 of 1", Next+Prev disabled) applied and fully rendered, releasing the obsolete query A did **not** overwrite query B — the rendered rows, status badges, total, current/total pages, Previous/Next state, list summary, URL, and controls all remained query B (no 206 total, no "of 11", no active rows appeared), the resource settled with no duplicate hidden list and no stale error. A tenant switch with a held tenant-A Users request likewise left tenant B as the rendered authority via the same request-generation guard with the canonical `auth/tenant-context` flow and no Users data in browser storage (note: `/admin/users` is platform-scoped and returns the same user set under both tenants in this environment, so tenant-level row distinguishability on Users is not observable; the distinguishable-data guard proof is the active/suspended race). Normal refresh retained existing rows with a visible refreshing state and reread the current query; a disposable user suspend/activate produced the success notification, reread the current canonical query through the guarded path, updated the guarded result, and left search/filter/page/page-size unchanged (user restored to active). The only console error was the expected pre-auth session-probe `401`.
- UI-C8 repository implementation (evidence: [`docs/evidence/ui/ui-c8-runtime-adapter-configuration-descriptor-contract.md`](../evidence/ui/ui-c8-runtime-adapter-configuration-descriptor-contract.md)) adds the missing canonical server descriptor contract for RuntimeNode adapter configuration: configurable adapters publish typed field descriptors through the existing adapter-configuration handler registry, Asterisk ARI publishes its seven current fields with defaults and validation hints reused from backend-owned constants, `simulator-deterministic` publishes descriptors for its existing profile contract because it is configuration-capable, `freeswitch-esl` remains unavailable with no descriptor collection, malformed descriptors fail deterministically, and the Runtime Registry management catalog exposes descriptors additively without node-specific values or secrets.
- UI-C9 repository implementation (evidence: [`docs/evidence/ui/ui-c9-catalog-runtime-forms-management-actions.md`](../evidence/ui/ui-c9-catalog-runtime-forms-management-actions.md)) consumes the canonical RuntimeNode adapter-configuration descriptors in the frontend: `RuntimeNodeCatalogField` generically renders the current server descriptor types (`text`, `integer`, `json`), Asterisk ARI and simulator-deterministic configuration forms render from server descriptors, the handwritten Asterisk form and payload helpers are removed, unsupported required descriptors block submission, descriptor-key payload construction preserves zero/JSON/write-only behavior, RuntimeNode lazy detail loading remains bounded, and Tenants, Memberships, Users, User Detail, and Runtime Nodes current management mutations use shared keyed action state with safe notifications and canonical rereads.
- UI-C10 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-c10-catalog-runtime-forms-actions-browser-proof.md`](../evidence/ui/ui-c10-catalog-runtime-forms-actions-browser-proof.md), deployed web digest `sha256:7ca56c7e…`, API digest `sha256:7d6a24f4…` from `6e31763` incl. the `4eaea84` descriptor publisher) live-proved the UI-C9 consumption on the `6e31763` frontend through Playwright MCP: the RuntimeNode initial load issued only the catalog + list requests with zero per-node detail fan-out over 110 nodes; the Asterisk node rendered exactly its seven descriptor fields in canonical order (text `application_name`; six `*_ms` number inputs with catalog min/max/step) with descriptor labels, `required`, node-scoped unique IDs, and unique help associations, and no handwritten Asterisk DOM; the simulator node rendered scenario_key/scenario_version/seed/`parameters` (JSON textarea) through the same generic renderer; invalid JSON produced a field-associated "must contain valid JSON" error with `aria-invalid` and **zero** save requests; a valid simulator save sent one canonical PUT with descriptor keys and `parameters` as parsed JSON (not a string), a success notification, and selected-node invalidation + reread (restored afterward); an injected required unsupported-type descriptor rendered a blocking alert identifying the key and type with no fallback input, a disabled save, and zero requests; a synthetic optional write-only field was omitted from the intercepted payload when blank and included only when filled, with the value never leaking to notifications/URL/storage/IDs/console; three action-scoped mutations (simulator config, User suspend/activate, Tenant suspend/activate) showed only the acting control entering the submitting state while other rows stayed usable, each with a success notification and canonical reread and preserved list intent; a controlled `500` on a user action left the row unchanged, showed an error, kept other actions usable, and remained retryable (retry succeeded after interception removal); backend validation stayed authoritative (two live `422` rejections with no false success and correction/resubmit); a tenant switch with an open panel, an unsaved edit, and a JSON error cleared rows, panels, form values, errors, and detail cache, loaded tenant B with zero fan-out, and restored fresh canonical detail on return; Asterisk and simulator forms had zero page-level overflow at 375px with theme changes making zero management API requests; and no secret appeared in any notification, ID, URL, storage, or console.
- Shared table abstraction, broader validation normalization, and controlled browser proof of the catalog-driven forms and representative shared management actions do not yet exist.

### Boundary

The frontend renders server catalogs and submits canonical intent.

It must not introduce checked-in capability catalogs, runtime-management authority, or client-owned lifecycle rules.

### Remaining Implementation

- Extract the remaining shared table, form, validation, and confirmation patterns. URL-backed list query and pagination state now exist for supported current management lists.
- Broaden shared API error, validation, and confirmation presentation where later screens demonstrate additional common needs.
- Preserve one-time and write-only secret handling.
- UI-C2 proved the async-state/notification/lazy-detail slice; UI-C6 closed the UI-C3 stale-response gap; UI-C10 proved the catalog-driven RuntimeNode forms, Asterisk handwritten-branch cutoff, simulator JSON handling, unsupported-descriptor blocking, write-only omission, and action-scoped shared management mutations. The current implemented UI-C management-workflow surface (Users, User Detail, Memberships, Tenants, Runtime Nodes lists and mutations, catalog-driven adapter forms) is standardized on the shared list-query, async-resource, keyed-action, and notification contracts, so UI-C is Complete.
- Later work that reuses these foundations (new domain screens, richer shared tables, confirmation-dialog normalization) belongs to the phases that introduce it (UI-D and beyond), not to UI-C.
- Backend adapter-configuration validation currently returns message-level errors (not field-keyed), so the frontend surfaces them through the shared action error notification; the frontend's inline field-error mapping is exercised by client-side descriptor validation (e.g. invalid JSON) and by field-keyed API errors when a backend endpoint supplies them.

### Test Contract

- Add focused unit tests for shared tables, pagination, filtering, sorting, validation, API error rendering, state rendering, notifications, destructive confirmations, catalog-driven adapter forms, and write-only secret behavior.
- Keep type, lint, and build checks passing.

### Browser-Proof Contract

Run natural Playwright browser proof for material management workflows: login normally, select tenant context through the UI, navigate actual admin routes, exercise list, pagination, create or update, validation error, success, unauthorized, and relevant empty or degraded states, and capture console and network errors.

### Non-Goals

- Visual primitive completion beyond UI-B.
- Real-time notification transport ownership beyond UI-D.
- Domain lifecycle decisions.
- Runtime mutation outside canonical backend APIs.

### Completion Criteria

- Existing management screens use shared data, form, state, error, and notification patterns where applicable.
- Adapter and capability forms are catalog-driven.
- Write-only and transient secret behavior remains safe.
- Unit, type, lint, and build checks pass.
- Natural browser proof passes for applicable management workflows.

## UI-D — Real-Time Telephony Operational Experience

Status: In Progress

### Objective

Provide real-time operational visibility for telephony state while preserving backend APIs as the canonical authority.

### Included Scope

- Reverb or WebSocket notification plumbing.
- Runtime and listener health updates.
- TelephonySession state changes.
- Conference and participant operational views.
- Runtime-operation and audit visibility.
- Connection-loss and reconnect presentation.
- Notification-driven refresh without notification authority.
- Consistent degraded, recovering, failed, and restored state presentation.

### Current Evidence

- Static session and signaling panels exist.
- No frontend real-time client exists.
- No Conference operational view exists.
- No runtime-operation or audit view exists.
- UI-D0 contract audit (evidence: [`docs/evidence/ui/ui-d0-realtime-operational-contract-audit.md`](../evidence/ui/ui-d0-realtime-operational-contract-audit.md)) established the current real-time contracts and selected the first slice. Findings: the frontend has zero real-time client (only clean `switchTenant`/`endSession` teardown hooks in `apps/web/src/state/appState.ts`); the backend has no Laravel broadcasting (`BROADCAST_CONNECTION=log`, no `laravel/reverb`, no `config/broadcasting.php`, no `routes/channels.php`, no `ShouldBroadcast`) but a mature transactional-outbox domain-event substrate and session-authenticated snapshot APIs; no Reverb/WSS transport is deployed (a Redis-backed `queue:work` worker is deployed and would carry a queued broadcast). RuntimeNode is the only entity with a live domain-event source plus a mature list/detail/`runtime-evidence` snapshot and an existing browser-proven operational view. **Selected first slice: UI-D1 — real-time broadcast + Reverb/WSS transport foundation delivering notification-only RuntimeNode operational-state events on a tenant-scoped private channel authorized via `AuthorizationService::requireTenant(..., 'runtime.nodes.view')`, consumed by the existing Runtime Nodes view which rereads canonical snapshots on notification.** Delivery is best-effort/at-most-once with no client-facing sequence; the browser event stream is never canonical (event → reread; gap/reconnect → full reread; broadcast down → disconnected/stale state). Next coder: Codex (bounded implementation).
- UI-D1 repository implementation (evidence: [`docs/evidence/ui/ui-d1-runtime-node-realtime-implementation.md`](../evidence/ui/ui-d1-runtime-node-realtime-implementation.md)) installs Laravel Reverb and frontend Echo/Pusher dependencies, adds one notification-only `RuntimeNodeOperationalStateChanged` broadcast, bridges canonical `runtime_node.*` outbox messages after commit, authorizes `tenant.{tenantId}.runtime-nodes` through `/api/broadcasting/auth` with the existing session and `runtime.nodes.view`, deploys a dedicated Reverb workload/Service/NetworkPolicy with generated local credentials, proxies `/app/` WSS through `app.utcp.local.test`, and wires one frontend realtime lifecycle authority into Runtime Nodes. RuntimeNode notifications and reconnects reread canonical snapshots, disconnection preserves prior canonical data with stale presentation, tenant switch/logout/session rejection tear down subscriptions, and the UI-C RuntimeNode request budget remains preserved. UI-D remains In Progress because the natural WSS/browser proof is intentionally deferred.
- UI-D3 repository correction (evidence: [`docs/evidence/ui/ui-d3-migration-broadcast-driver-fix.md`](../evidence/ui/ui-d3-migration-broadcast-driver-fix.md)) fixes the UI-D2 deployment blocker by restoring the local migration overlay to `BROADCAST_CONNECTION=log` and removing migration-only Reverb transport properties. The migration Job remains a non-publisher that runs only `php artisan migrate --force` and receives no `utcp-local-reverb-credentials` Secret or `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET`; API, worker, outbox-dispatcher, and Reverb retain `BROADCAST_CONNECTION=reverb` and the canonical Reverb credentials. Rendered-manifest tests and `make k8s-config-check` now guard the boundary. UI-D remains In Progress pending the natural WSS/browser proof.
- UI-D5 repository correction (evidence: [`docs/evidence/ui/ui-d5-reverb-origin-redis-policy-fix.md`](../evidence/ui/ui-d5-reverb-origin-redis-policy-fix.md)) fixes the two UI-D4 configuration blockers without changing RuntimeNode domain or frontend production logic: local `REVERB_ALLOWED_ORIGIN` values are host-only (`app.utcp.local.test`), the Reverb config fallback derives a hostname from `APP_URL` instead of using the raw URL, and Redis ingress now permits the `reverb` network role only on TCP `6379`. Static checks and rendered-manifest tests now guard both contracts. UI-D remains In Progress pending the natural WSS/browser proof.
- UI-D7 repository correction (evidence: [`docs/evidence/ui/ui-d7-browser-reverb-client-mobile-fix.md`](../evidence/ui/ui-d7-browser-reverb-client-mobile-fix.md)) fixes the frontend blockers isolated by UI-D6 without changing server or infrastructure code: the production Echo/Reverb option builder now enables `ws` and `wss`, keeps WSS selected with `forceTLS`, omits `wsPath` so pusher-js constructs exactly `/app/{key}`, and has direct unit coverage that no polling transport, fallback public port, doubled `/app`, or secret-bearing option is emitted. Runtime Nodes header CSS now permits wrapping and stacks the heading, live-update badge, and refresh action at the established narrow breakpoint without root overflow clipping. UI-D remains In Progress pending the natural WSS/browser proof.

### Authority Boundary

WebSocket and Reverb messages are notifications only.

Canonical state must always be re-read from backend APIs.

### Dependencies

Some domain-specific UI-D completion depends on T2, T3, V0, and later call-control phases. Shared real-time plumbing can be implemented independently.

### Remaining Implementation

- UI-D2 live-proof attempt (evidence: [`docs/evidence/ui/ui-d2-runtime-node-realtime-browser-proof.md`](../evidence/ui/ui-d2-runtime-node-realtime-browser-proof.md)) found the migration Job broadcast-driver deployment blocker before browser testing began. UI-D3 corrected that repository configuration boundary; the UI-D1 corridor is still not live-proven.
- UI-D4 resumed live proof (evidence: [`docs/evidence/ui/ui-d4-runtime-node-realtime-live-proof.md`](../evidence/ui/ui-d4-runtime-node-realtime-live-proof.md), built from `18ef90a`: api `sha256:c4f4f7aa…`, web `sha256:2d96b5f9…`, gateway `sha256:4790ab7f…`) **confirms the UI-D3 correction works** — `make k8s-apply` now succeeds and `utcp-migrate` completes with `BROADCAST_CONNECTION=log` and no Reverb credentials, with api/worker/outbox-dispatcher/reverb all on `reverb` — and **proves the entire public transport corridor**: Reverb is ClusterIP-only on `8080` with zero NodePort/LoadBalancer/hostPort/`6001` in the rendered overlay, canonical edge routes (`/`, `/healthz`, `/dashboard`, `/admin/runtime-nodes`) return 200, `/app/{key}` reaches Reverb rather than the SPA catch-all, `/api/broadcasting/auth` stays on the API, and a real RFC6455 handshake through `wss://app.utcp.local.test` returns `101 Switching Protocols` with `X-Powered-By: Laravel Reverb`. Natural login (with forced password change), `Local Tenant` selection, the RuntimeNode initial budget (**2 canonical requests, zero per-node detail fan-out over 110 nodes**, subscription gated behind the canonical snapshot), and the `utcp.appearance`-only storage boundary are all proven. **Two bounded configuration defects block the behavioural proof:** (1) `REVERB_ALLOWED_ORIGIN=https://app.utcp.local.test` carries a scheme while Reverb matches `parse_url($origin, PHP_URL_HOST)` (`vendor/laravel/reverb/.../Pusher/Server.php:190-206`), so every client — including the browser, which opened exactly one correct `wss://app.utcp.local.test/app/<key>` socket — is rejected with `4009 Origin not allowed` (close 1006) and no subscription or channel-auth ever occurs; the fix is a host-only pattern plus a non-scheme-bearing `config/reverb.php` fallback. (2) `security/data/allow-redis.yaml` omits the `reverb` network role, so with `REVERB_SCALING_ENABLED=true` the `RedisPubSubProvider` fan-out path cannot connect (`reverb → redis:6379` refused while `worker → redis:6379` succeeds); both must be fixed together. A separate environmental issue — the IP-pinned `allow-traefik-kubernetes-api` policy stale at `172.24.0.5` after a node-IP shuffle, which silently cost Traefik its route watch — was repaired canonically via `scripts/security/render-apiserver-policy`. Also recorded: same-tag `gateway`/`web` deployments need an explicit `rollout restart` because unchanged specs are not recreated by `k8s-apply`. No existing static check covers either defect.
- UI-D5 correction (evidence: [`docs/evidence/ui/ui-d5-reverb-origin-redis-policy-fix.md`](../evidence/ui/ui-d5-reverb-origin-redis-policy-fix.md)) sets the committed local Reverb allowed-origin contract to the hostname pattern `app.utcp.local.test`, corrects the `config/reverb.php` fallback to derive a hostname from `APP_URL`, and adds least-privilege Redis ingress from role `reverb` to TCP `6379` while preserving publisher workloads on Reverb and migration on `log`. The next live proof must roll out or restart `gateway` and `web` when immutable image tags are not used and must re-render the Traefik API-server policy if endpoint-pin drift is detected.
- UI-D6 resumed live proof (evidence: [`docs/evidence/ui/ui-d6-runtime-node-realtime-live-proof.md`](../evidence/ui/ui-d6-runtime-node-realtime-live-proof.md), built from `ff3444f`: api `sha256:4a861d38…`, web `sha256:da375be1…`, gateway `sha256:1c47876d…`) **live-proves both UI-D5 corrections and the entire server-side corridor**: a real RFC6455 handshake carrying the browser's exact `Origin: https://app.utcp.local.test` now returns `101 Switching Protocols` (`X-Powered-By: Laravel Reverb`) followed by `pusher:connection_established` — where UI-D4 got `4009 Origin not allowed` — and `reverb → redis:6379` is `OPEN` with post-fix pods at 0 restarts (the pre-fix pod had 10). Also proven: `utcp-migrate` succeeds with `BROADCAST_CONNECTION=log` and no Reverb credentials; all four publisher/serving workloads report `BROADCAST_CONNECTION=reverb` and `REVERB_ALLOWED_ORIGIN=app.utcp.local.test`; Reverb stays ClusterIP-only on `8080` with zero NodePort/LoadBalancer/hostPort/`6001` in the rendered overlay; canonical edge routes return 200 with `/app/` reaching Reverb and `/api/broadcasting/auth` reaching the API; natural login with forced password change and `Local Tenant` selection; and the RuntimeNode initial budget of **2 canonical requests with zero per-node detail fan-out over 110 nodes**, with the `utcp.appearance`-only storage boundary held through logout. **A third, frontend blocking defect now halts the behavioural proof:** the browser never opens a WebSocket — Playwright's own observer records zero WebSocket events, zero `/app/` requests, and zero `broadcasting/auth` requests on both in-app navigation and a fresh direct load, with no JS errors — yet the live-updates badge renders `ui-status-badge--information`, which maps **only** to state `connecting`, proving `subscribeRuntimeNodeRealtime` passed its session/tenant and config guards and reached `new Echo({...})` and `.private(...)`. The defect therefore lies in the pusher-js options built by `createEchoClient` (`apps/web/src/realtime/runtimeNodeRealtime.ts:155-173`), most likely `enabledTransports: [config.wsScheme]` → `['wss']` disabling the `ws`-named WebSocket leg that pusher-js's default strategy resolves through (silent, no error), with `wsPath: '/app'` a probable latent second issue since pusher-js appends `/app/{key}` to that prefix. The web bundle is byte-identical to UI-D4's, so this defect was present but masked by the `4009` rejection. No existing test covers it because the frontend suite injects a stub via `setRuntimeNodeRealtimeClientFactory`. Separately, `/admin/runtime-nodes` now shows **31 px of page-level horizontal overflow at 375 px** (`scrollWidth 406` vs `innerWidth 375`), a regression against the UI-B5 zero-overflow contract introduced with the header live-updates badge. Also recorded: `make k8s-apply` recreated **no** pod this run (no spec changed), so all six Deployments needed explicit `rollout restart` — critical because `envFrom` ConfigMap changes such as the corrected origin are not hot-reloaded.
- UI-D7 correction (evidence: [`docs/evidence/ui/ui-d7-browser-reverb-client-mobile-fix.md`](../evidence/ui/ui-d7-browser-reverb-client-mobile-fix.md)) corrects the browser Echo options (`enabledTransports: ['ws', 'wss']`, `forceTLS` retained, no `wsPath`) and the Runtime Nodes header overflow source, with tests covering the production option builder and the responsive contract. Re-run the focused natural Playwright MCP proof for the UI-D1 Reverb/WSS RuntimeNode corridor.
- UI-D8 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-d8-runtime-node-live-behavior-proof.md`](../evidence/ui/ui-d8-runtime-node-live-behavior-proof.md), deployed web digest `sha256:e9297a6f…` from `d49afb7`, served bundle `index-D3QT7kwb.js`) **live-proves the UI-D1 RuntimeNode real-time corridor end to end and closes the UI-D6 blocker**. The application browser (not an external probe) opened exactly **one** socket to `wss://app.utcp.local.test:443/app/<public-key>` with a **single `/app/` segment** (no `/app/app/{key}`), no `4009`, no `reverb:8080`, no Pod IP and no custom public port, reaching `ws-open` → `pusher:connection_established` → `pusher:subscribe` → `pusher_internal:subscription_succeeded` within ~310 ms and rendering `ui-status-badge--success` "Live updates connected". `POST /api/broadcasting/auth` returned **200** on the real browser session for `private-tenant.{activeTenantId}.runtime-nodes`, only after an active tenant existed, with no auth loop and the signature never recorded. A reversible `seed` change saved through the descriptor-rendered Web Admin form produced a real broadcast whose envelope contained **exactly** the five approved fields (`__extraKeys` empty) with no configuration values, endpoints, credentials, secrets, full RuntimeNode state or outbox payload; event-relative accounting then showed **1** list reread, **3** scoped detail requests for the open affected node and **0** for other nodes across 110 nodes, with the restore following the identical path (canonical generations 6 → 7). Scaling Reverb to zero preserved all 110 rows and the open panel with a `--danger` "displayed data may be stale" badge, usable controls and **0** API/auth/detail requests; restoring it reconnected with **1** channel-auth, **1** list reread, **3** open-node detail requests, **0** other-node requests, and the stale badge cleared at **+509 ms — after** the canonical reread response at +269 ms, not at socket open (0 ms) or subscription success (+81 ms). Tenant switching sent `pusher:unsubscribe` for the old channel and subscribed the new one with rows/panels cleared and zero detail fan-out, and a second natural browser context mutating a Local Tenant node while the primary stayed on Proof Tenant produced **0** accepted events, **0** rereads and no leaked rows. Logout left the private channel, closed the socket with code **1000**, and issued **0** reconnects and **0** channel-auth requests afterwards. `/admin/runtime-nodes` at 375 px measured `scrollWidth 375 == innerWidth 375` in Light and Dark with zero offending elements and no `overflow-x` masking, closing the UI-D6 regression, with theme changes making zero API requests. Three bounded non-blocking follow-ups are recorded: a duplicate initial catalog+list round on view entry (per-node fan-out still **0**), a false "stale" badge after a tenant switch despite a healthy re-subscription, and a vendored `pusherTransportTLS` localStorage entry (no credential/token/key) outside the `utcp.appearance`-only boundary.
- UI-D9 repository implementation (evidence: [`docs/evidence/ui/ui-d9-conference-participant-live-implementation.md`](../evidence/ui/ui-d9-conference-participant-live-implementation.md)) extends the proven UI-D1 Reverb/WSS corridor to Conferences and participants. One tenant-scoped `ConferenceOperationalStateChanged` notification covers `conference.*` and `conference_participant.*` outbox messages with a metadata-only envelope, the channel `tenant.{tenantId}.conferences` is authorized through the same session and `telephony.conferences.view` capability as the Conference snapshots, and the shared frontend realtime authority keeps one Echo/Pusher client for RuntimeNode and Conference subscriptions. `/operations/conferences` is capability-gated, read-only, and loads the canonical Conference list first, then only the selected Conference detail and participants; notifications and reconnects reread canonical list plus selected detail/participants only. The UI-D8 follow-ups are also closed in repository tests: RuntimeNode initial navigation is 1 catalog + 1 list with zero detail fan-out, tenant re-subscription clears stale after snapshot plus subscription even without a new socket connected event, and `pusherTransportTLS` is classified only as bounded non-sensitive vendor transport cache. UI-D remains In Progress pending the Conference/participant natural Playwright MCP live proof.
- UI-D10 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-d10-conference-participant-live-proof.md`](../evidence/ui/ui-d10-conference-participant-live-proof.md), api `sha256:7402da65…` / web `sha256:ecab7ce1…` from `39627da`) **proves the Conference and participant live-operations corridor but is blocked from completion by one frontend regression.** Proven: capability-gated `/operations/conferences` navigation; an initial budget of **1** Conference list request with **0** detail and **0** participant requests over 120 Conferences; natural `POST /api/broadcasting/auth` → **200** for `private-tenant.{activeTenantId}.conferences` with `subscribe` → `subscription_succeeded` and no auth loop; **one shared WebSocket client** across routes (open → subscribe → unsubscribe → close(1000) → open, never two concurrent sockets); selecting one Conference loading exactly its own detail + participants with zero for unselected; real `conference.created`, `conference.desired_state_changed`, `conference_participant.admitted` and `conference_participant.removed` deliveries through canonical outbox → `OperationalBroadcastBridge` → queue → Reverb → browser, each envelope carrying **exactly** the six approved fields (`__extraKeys` empty) with no participant names, endpoints, credentials, signaling, media or outbox payload; **1** list reread plus **1** selected-detail and **1** selected-participant reread per notification with **0** unrelated Conference resources; unrelated-Conference events causing **1** list reread and **0** selected/other resource loads (no all-Conference ownership scan); Reverb scale-to-zero preserving all rows, the selected panel and participants behind a `--danger` stale badge with **0** API requests and no auth loop; clean logout teardown (channel left, socket closed **1000**, zero post-logout reconnect or auth); a bounded `pusherTransportTLS` vendor cache (`timestamp`/`transport`/`latency`/`cacheSkipCount`, transport `ws`, no tenant, user, channel, socket ID, app key or credential) beside `utcp.appearance`; and zero page overflow at 375 px in Light and Dark with no `overflow-x` masking and zero API requests on theme change. The RuntimeNode duplicate-request follow-up is **confirmed fixed** (1 catalog + 1 list + 0 per-node detail on both a fresh route load and an AppShell return). **Blocking regression:** subscription readiness is signalled via `channel.bind?.('pusher:subscription_succeeded', …)`, but Laravel Echo's channel API has **no `bind` method** (it exposes `subscribed(callback)`; `echo.d.ts` contains zero `bind` declarations), so the optional call silently no-ops and `activeRuntimeNodeSubscriptionReady`/`activeConferenceSubscriptionReady` are never set. `activeSubscriptionsReady()` therefore never returns true, so the badge never reaches connected and — materially — `maybeCompleteLiveConnection()` never reaches `resynchronizeCanonicalSnapshots()`: after the outage the socket reconnected and stayed healthy (ping/pong, Reverb independently reachable) for **105 s** with the badge stuck on "reconnecting" and **0** list, detail, participant or re-authorization requests, leaving the operations view silently stale. Event delivery is unaffected because `.listen()` binds independently. No test catches this — the frontend suite injects a stub via `setRuntimeNodeRealtimeClientFactory`, the same gap class as the UI-D6 blocker. Consequently tenant-switch isolation, previous-tenant event rejection, and the RuntimeNode tenant-switch live-state correction were **not** proven, since they depend on the same readiness flag.
- UI-D11 repository correction (evidence: [`docs/evidence/ui/ui-d11-echo-subscription-readiness-fix.md`](../evidence/ui/ui-d11-echo-subscription-readiness-fix.md)) removes the unsupported channel subscription lifecycle `bind` path and uses Laravel Echo `subscribed()` / `error()` for both RuntimeNode and Conference channels. The shared realtime authority now fences subscription callbacks by generation, tenant channel, active channel object, and session, runs reconnect resynchronization once per generation only after active subscriptions and canonical snapshots are ready, lets tenant switches become connected without a repeated socket event, and prevents late callbacks after logout or replacement from restoring live state. UI-D remains In Progress pending the focused natural Playwright MCP proof of reconnect resynchronization, tenant-switch readiness, previous-tenant isolation, and logout interruption.
- UI-D12 controlled natural browser proof (evidence: [`docs/evidence/ui/ui-d12-echo-readiness-live-proof.md`](../evidence/ui/ui-d12-echo-readiness-live-proof.md), web `sha256:f1395fb3…` from `0b46434`, served bundle `index-BfO5s0gG.js`) **live-proves the UI-D11 correction and closes the UI-D10 blocker.** The served bundle carries exactly two `.subscribed(` call sites with `listen` + `error` + generation-fenced readiness and no channel-level `bind` (the residual `pusher:subscription_*` literals are inside Echo's own `subscribed()` implementation and pusher-js internals; the five remaining `.bind` calls are on the supported Pusher *connection* object). Proven live: RuntimeNode 1 catalog + 1 list + 0 per-node detail and Conference 1 list + 0 detail + 0 participants, both reaching **"Live updates connected"** — the state UI-D10 could never leave — on **one shared socket** with scoped selection loading only the selected Conference detail + participants. **Reconnect resynchronization now runs exactly once per generation:** on the Conference route, relative to socket reopen, auth +2 ms → subscription_succeeded +101 ms → list request +104 ms → list response +227 ms → selected detail +236 ms → participants +280 ms → **stale cleared +353 ms**, with 1/1/1 rereads and 0 unrelated; on the RuntimeNode route with one node open, subscription_succeeded +100 ms → 1 auth, 1 list reread, **3** bounded scoped rereads for the open node, 0 unrelated, 0 conference, **stale cleared +439 ms** after the last reread completed. Socket open alone and first-subscription-alone never cleared stale, and the previously recorded **105-second stuck "reconnecting" state is absent**. Outages preserved all rows, the selected panel and participants behind a `--danger` stale badge with **0** API/auth/detail requests and only bounded retries. Tenant switching (Local↔Proof) left the old channel, subscribed the new one, and reached connected with **0** socket recreations, 0 detail fan-out and **no false stale badge**, closing the UI-D10 tenant-switch regression; a rapid `Local → Proof → Local` sequence left **only** the final tenant's channel active (`includesProof: false`) with 0 socket churn. A second natural context mutating Local Tenant while the primary stayed on Proof produced **0** accepted events and **0** rereads of any kind. Logout during reconnect invalidated the generation — after restoring Reverb, **45 s** yielded 0 sockets, 0 frames, 0 auth and 0 rereads — and normal logout after a successful reconnect left the channel and closed the single socket with code **1000**, with 0 late callbacks or post-logout auth. Storage stayed bounded to `utcp.appearance` plus a vendor `pusherTransportTLS` cache (`timestamp`/`transport`/`latency`/`cacheSkipCount`, transport `ws`) carrying no tenant, user, channel, socket ID, key, signature, credential or domain payload. The 11 gateway 502s on `/app/{key}` all fall inside the four deliberate scale-to-zero windows.
- UI-D13 repository implementation (evidence: [`docs/evidence/ui/ui-d13-runtime-operation-read-api.md`](../evidence/ui/ui-d13-runtime-operation-read-api.md)) adds the first backend read foundation for the remaining UI-D operational surfaces: tenant-scoped read-only Runtime Operation list and detail APIs backed by the existing `runtime_operations` table, authorized with the existing `runtime.nodes.view` capability, paginated with deterministic `created_at desc, id desc` ordering, and filtered only on stored canonical fields (`runtime_node_id`, `status`, `operation_type`, `created_from`, `created_to`, `correlation_id`). Responses use explicit safe resources, omit raw payloads, credentials, secrets, stack traces, outbox/audit bodies, and unsafe failure messages, and expose only bounded RuntimeNode metadata plus bounded reconciliation identifiers. UI-D remains In Progress pending the frontend Runtime Operations operational view plus separate audit and reconciliation APIs.
- UI-D14 repository implementation (evidence: [`docs/evidence/ui/ui-d14-runtime-operation-live-implementation.md`](../evidence/ui/ui-d14-runtime-operation-live-implementation.md)) adds the bounded read-only Runtime Operations operational surface on top of the UI-D13 APIs: the tenant channel `tenant.{tenantId}.runtime-operations` is authorized with active-session tenant membership plus `runtime.nodes.view`, `runtime_operation.*` outbox rows produce one metadata-only queued `RuntimeOperationOperationalStateChanged` notification, the shared Echo client remains the single connection authority, `/operations/runtime-operations` is capability-gated and read-only, list filters and pagination use backend query semantics, selected detail loads only on demand, notifications and reconnects reread canonical list plus selected detail only, tenant switch/logout clear callbacks and selected state, and tests cover request budgets, storage boundaries, and responsive source contracts. UI-D remains In Progress pending natural Playwright MCP proof of the Runtime Operations route and the separate audit/reconciliation surfaces.
- UI-D15 controlled natural live proof (evidence: [`docs/evidence/ui/ui-d15-runtime-operation-live-proof.md`](../evidence/ui/ui-d15-runtime-operation-live-proof.md), api `sha256:d06905de…` / web `sha256:d2a3da08…` from `9977e3e`) **is blocked and does not prove the UI-D14 surface**. It confirms image provenance, six healthy workloads, a canonically repaired Kubernetes API egress pin, natural break-glass login with forced password change, capability-gated AppShell navigation to `/operations/runtime-operations`, a read-only route with no retry/cancel/replay/repair/reconcile/delete/create control, exactly 1 list request and 0 detail requests on entry, an unregressed shared RuntimeNode Reverb/WSS corridor (one socket, `broadcasting/auth` 200, `private-tenant.{tenantId}.runtime-nodes` subscribed, 1 catalog + 1 list + 0 fan-out), zero new sockets on route change, zero requests and zero reconnects on theme change, clean logout teardown with no post-logout auth or Runtime Operation request, a storage boundary of only `utcp.appearance` plus the bounded `pusherTransportTLS` vendor cache, and zero 375px overflow in Light and Dark. **Two independent blocking defects were found.** (1) `RuntimeOperationQuery::baseQuery()` joins `runtime_nodes.id`/`.tenant_id` and `runtime_reconciliation_states.tenant_id` (PostgreSQL `uuid`) to `runtime_operations.runtime_node_id`/`.tenant_id` (`character varying`), so both canonical read endpoints fail with `SQLSTATE[42883] operator does not exist: uuid = character varying` and return HTTP 500 on every request; the route renders an explicit "Runtime Operations unavailable / Server Error" alert with no rows, and never subscribes because subscription is correctly gated behind a successful canonical snapshot. The suite cannot see this because `phpunit.xml` runs on SQLite, where `uuid()` is `varchar`. (2) No production code path ever appends an outbox row with `aggregate_type = 'runtime_operation'` — the live outbox holds 4207 `runtime_node`, 1072 `conference_participant`, 881 `conference`, 338 `telephony_session` and **0** `runtime_operation` rows — so `OperationalBroadcastBridge::dispatchRuntimeOperationNotification()` and the `tenant.{tenantId}.runtime-operations` channel are unreachable; `runtime_operation.*` event types are written under `aggregate_type = runtime_node`/`conference` and are rejected by every bridge branch. Pagination, filters, page/filter preservation, selected-detail budget, envelope, unrelated- and selected-operation rereads, reconnect resync, route-leave, tenant isolation, and previous-tenant rejection are all unproven and were not fabricated.
- UI-D16 repository correction (evidence: [`docs/evidence/ui/ui-d16-runtime-operation-postgres-outbox-fix.md`](../evidence/ui/ui-d16-runtime-operation-postgres-outbox-fix.md)) fixes the two UI-D15 repository blockers and the related workload broadcaster boundary: PostgreSQL now converts `runtime_operations.tenant_id` and nullable `runtime_operations.runtime_node_id` to canonical `uuid` types after validating existing values and references; Runtime Operation list/detail PostgreSQL integration coverage runs through the existing control-plane test target and proves RuntimeNode plus reconciliation joins, tenant isolation, and filters; `RuntimeOperationRepository` now emits `runtime_operation.created` and `runtime_operation.status_changed` aggregate outbox rows transactionally from real lifecycle transitions with a metadata-only payload; producer-backed realtime tests prove dispatcher bridging without a synthetic acceptance row, rollback behavior, idempotent create behavior, and broadcast failure isolation; rendered workload checks require Reverb publishers to have canonical credentials while non-publishers and migration use `BROADCAST_CONNECTION=log` without Reverb credentials. UI-D remains In Progress pending the corrected natural Runtime Operations live proof and separate audit/reconciliation surfaces.
- UI-D17 controlled natural live proof (evidence: [`docs/evidence/ui/ui-d17-runtime-operation-corrected-live-proof.md`](../evidence/ui/ui-d17-runtime-operation-corrected-live-proof.md), api `sha256:7c7c1db9…` in-cluster from registry manifest `sha256:d4284d67…` built from `39ea898`; web unchanged at `sha256:d2a3da08…` because `git diff 9977e3e..39ea898 -- apps/web` is empty) **closes both UI-D15 blockers and proves the Runtime Operations surface end to end**. Existing data validated read-only before migration (4714 rows, 0 malformed UUIDs, 0 unresolved tenant/RuntimeNode references); the committed UUID migration converted `runtime_operations.tenant_id` and nullable `runtime_operations.runtime_node_id` to `uuid` in 179 ms preserving all 4714 rows and all five indexes, running on `BROADCAST_CONNECTION=log` with no Reverb credentials; the three canonical joins (RuntimeNode id+tenant, reconciliation last_operation+tenant) now resolve 4714 rows each with **no** added casts and **zero** `SQLSTATE[42883]` in the API log. `make k8s-apply` completed with no rollout-wait failure (UI-D15 aborted on `scheduler`): all four Reverb publishers carry credentials, all nine non-publishers plus the migration Job use `log` without credentials, and a `null auth_key`/`BroadcastManager` scan across all 15 Deployments returned 0 — the nine workloads that had accumulated 38–45 restarts each now boot clean. Live proof: canonical list/detail both **200**; 1 list + 0 detail requests on entry over 4714 operations with channel auth at +184 ms and subscription at +194 ms; server-backed pagination (1 list request per page change, 0 detail, deterministic ordering across page sizes); all six filters mapping to exact canonical parameters with page reset to 1, 1 request, 0 detail, and correct clearing (`status=terminal_failed`→55, `operation_type=conference.close`→159, correlation and bounded-interval→1 each); a safely invalid `correlation_id` surfacing a **422** validation message rather than being ignored; 1 detail request on selection with a 21-key safe contract and no payload/idempotency/lease/trace/outbox/audit/provider/credential/secret/endpoint/env value. **Production notification corridor proven with real events**: `aggregate_type='runtime_operation'` outbox rows went 0 → 36, written transactionally by `RuntimeOperationRepository` transitions with payload bounded to `runtime_operation_id` + `runtime_node_id`; browser frames carried exactly the seven approved keys with `aggregate_type=runtime_operation`, `aggregate_id=runtime_operation_id`, active `tenant_id`, and **no status field** so the envelope cannot become authority. Unrelated-operation events caused list-only rereads (0 selected-detail, 0 other detail); a deterministic selected-operation proof (dedicated `telephony-command-worker` scaled to 0 with reconciler/dispatcher/worker/web/Reverb up and 0 pending operations) gave exactly 1 list reread per event and 1 selected-detail reread per selected event with 0 unrelated details, final status/timestamps coming from the detail HTTP response; every reread preserved `?runtime_node_id=…&page=1&per_page=10`. Reconnect performed exactly one bounded resync per generation (1 list + 1 detail + 0 unrelated, page/filters/selection preserved, stale cleared after the HTTP rereads, 2 sockets created / 1 closed); route leave sent `pusher:unsubscribe` and kept the shared socket for the Conferences channel with 0 Runtime Operation requests afterwards; tenant switch left the old channel, cleared selection, loaded the new tenant once with 0 detail requests and 0 leaked rows on one socket; a second independent context generating and executing a Local Tenant operation produced 0 accepted events / 0 rereads / 0 leaked rows in the primary held on Proof Tenant; logout closed the socket with 0 post-logout auth or Runtime Operation requests. Storage stayed bounded to `utcp.appearance` plus the metadata-only `pusherTransportTLS`; 375px overflow was 0 in Light and Dark with detail inside the viewport and status conveyed as text. All five disposable RuntimeNodes, both scaled workloads, and the Reverb replica count were restored; all 6557 outbox rows are dispatched with 0 non-terminal operations and Runtime Operation history preserved. One divergence is recorded: the first selected-operation attempt lost its measurement to an observer buffer clear (harness error, not a product defect) and was re-proven cleanly. UI-D remains In Progress for the reconciliation-record and audit read authorities.
- UI-D18 repository implementation (evidence: [`docs/evidence/ui/ui-d18-runtime-reconciliation-read-api.md`](../evidence/ui/ui-d18-runtime-reconciliation-read-api.md)) adds tenant-scoped read-only Runtime Reconciliation list and detail APIs backed by the existing `runtime_reconciliation_states` current-state table, authorized with the existing `runtime.nodes.view` capability, paginated with deterministic `updated_at desc, id desc` ordering, and filtered only on stored canonical fields (`runtime_node_id`, `status`, `target_type`, `runtime_operation_id`, `updated_from`, `updated_to`). Responses use explicit safe resources with bounded RuntimeNode and Runtime Operation references, safe failure representation, no raw desired/observed payloads, no credentials, no stack traces, no audit or outbox bodies, and non-scaling list-query coverage plus PostgreSQL endpoint execution through the established control-plane target. UI-D remains In Progress pending the bounded Runtime Reconciliation operational view and separate Audit API.
- UI-D19 repository implementation (evidence: [`docs/evidence/ui/ui-d19-runtime-reconciliation-live-implementation.md`](../evidence/ui/ui-d19-runtime-reconciliation-live-implementation.md)) adds the bounded read-only Runtime Reconciliation operational surface on top of the UI-D18 APIs: the tenant channel `tenant.{tenantId}.runtime-reconciliations` is authorized with active-session tenant membership plus `runtime.nodes.view`, `runtime_reconciliation.*` aggregate outbox rows are produced transactionally by `ReconciliationRepository` transitions, one metadata-only queued `RuntimeReconciliationOperationalStateChanged` notification is bridged through the existing outbox dispatcher path, the shared Echo client remains the single connection authority, `/operations/runtime-reconciliations` is capability-gated and read-only, list filters and pagination use backend query semantics, selected detail loads only on demand, notifications and reconnects reread canonical list plus selected detail only, tenant switch/logout clear callbacks and selected state, and tests cover producer-backed broadcasts, request budgets, storage boundaries, and responsive source contracts. UI-D remains In Progress pending natural Playwright MCP proof of the Runtime Reconciliation route and the separate Audit API.
- UI-D20 controlled natural live proof (evidence: [`docs/evidence/ui/ui-d20-runtime-reconciliation-live-proof.md`](../evidence/ui/ui-d20-runtime-reconciliation-live-proof.md), api `sha256:5ff2e330…` / web `sha256:5947ec57…` built from `d9fe346` with `org.opencontainers.image.revision=d9fe346`) **is blocked and does not complete the UI-D19 surface**. All 12 API-image Deployments plus web were enumerated from the rendered manifests and explicitly restarted (with `crictl rmi` on all three k3d nodes to defeat the fence worker's `IfNotPresent` policy), giving zero version skew — 13 API pods on one digest, 1 web pod on one digest — and `make k8s-apply` completed with migration `Complete` on `BROADCAST_CONNECTION=log` without Reverb credentials. **Proven:** capability-gated navigation and a read-only route with no reconcile/retry/replay/repair/clear-drift/delete/approve/manual-sync control; canonical list and detail both 200 with PostgreSQL joins succeeding and pagination metadata present; a 15-key safe contract with zero raw desired/observed state, credentials, adapter configuration, endpoint secrets, provider responses, commands, traces, audit or outbox payloads (generations exposed only as integers); server-backed pagination with 1 list request per page change, 0 detail requests and deterministic `updated_at desc, id desc` ordering; all six filters (`runtime_node_id`, `status`, `target_type`, `runtime_operation_id`, `updated_from`, `updated_to`) mapping to exact canonical parameters with page reset to 1 and correct clearing; a 422 validation message surfaced rather than ignored; 1 detail request per selection with 0 unrelated detail requests; production outbox rows rising 0 → 4560 from real `ReconciliationRepository` transitions; a browser envelope whose key union across 60 consecutive frames is exactly `aggregate_id, aggregate_type, event_type, occurred_at, runtime_reconciliation_id, tenant_id` with `aggregate_type=runtime_reconciliation`, `aggregate_id === runtime_reconciliation_id` and the active tenant in 100 % of frames and **zero** status/generation/drift/failure/credential keys; unrelated events causing list-only rereads (60 events → 60 list rereads, 0 detail); reconnect performing exactly one bounded resync (1 list + 1 detail + 0 unrelated) with query, selection and stale-clearing all correct; route leave sending `pusher:unsubscribe`, keeping the shared socket and issuing 0 reconciliation requests for 12 s afterwards, then 1 fresh list + 1 fresh subscription on return with 0 new sockets; tenant switch leaving the old channel, clearing selection, loading once with 0 detail requests and 0 leaked rows; a second independent context receiving 210 Local Tenant events over 60 s while the primary on Proof Tenant accepted **0** events, 0 rereads and 0 leaked rows; clean logout with 0 post-logout auth or reconciliation requests; storage bounded to `utcp.appearance` plus metadata-only `pusherTransportTLS`; and 0 overflow at 375px in Light and Dark with drift and status expressed as text. **Blocking defect:** `ReconciliationRepository` emits outbox events for reconciliation *non-transitions* — `claimDue()` appends `runtime_reconciliation.reconciliation_started` for every claimed row on every poll and `markResult()` appends an event for every result, mapping the steady-state `waiting` status to `retry_scheduled`, neither comparing prior and resulting state. With 460 reconciliation states the reconciler therefore produces a permanent ~7 event/second storm in which 98.4 % of events (2280 `reconciliation_started` + 2206 `retry_scheduled` against 72 `converged` + 2 `failed`) carry no state change and 200/200 sampled reconciler results are `waiting` no-ops. Measured consequences: the route issued 11 list requests on entry and then **110 canonical list rereads in a 30-second idle window with no user action** (3.67/s, exactly 1 per event — the client contract is correct, the event volume is not); and the canonical outbox dispatch backlog grew monotonically from 0 to 1000+ (production ~7 rows/s versus a measured dispatcher drain of ~3.3 rows/s), ending at 700 pending and still climbing. Repository coverage cannot see this because it asserts that a transition produces an event but never that a non-transition produces none. Request-discipline measurements above were therefore taken in an isolated quiescent window with the reconciler and scheduler at zero replicas and the outbox drained, which is stated explicitly in the evidence; the selected-reconciliation reread could not be isolated at all and that exact canonical timing limitation is preserved rather than fabricated. Next: bounded correction to emit only on real state change, plus a no-op-reconcile regression test. UI-D remains In Progress.
- Add operational views for sessions, conferences, participants, runtime operations, audits, and runtime health as domain APIs permit.
- Prove that notifications never become canonical lifecycle authority.

### Test Contract

- Add tests for notification receipt, reconnect/degraded states, notification-triggered API refresh, duplicate or stale notification handling, and absence of client-owned canonical state mutation.
- Keep type, lint, and build checks passing.

### Browser-Proof Contract

Run natural Playwright browser proof when runtime or live operational behavior is material: login normally, select tenant context through the UI, navigate actual operational routes, observe a real or controlled state change, prove notification-driven refresh re-reads canonical APIs, exercise connection-loss and reconnect presentation where applicable, and capture console and network errors.

### Non-Goals

- WebRTC media capture or playback outside T3.
- Call-control semantics outside later call-control phases.
- Client-side telephony lifecycle authority.
- Runtime-specific management branches.

### Completion Criteria

- Shared real-time notification plumbing exists.
- Notifications trigger canonical API refresh and do not own state.
- Runtime health, listener health, session, conference, participant, operation, and audit views exist where their domain APIs are available.
- Degraded, recovering, failed, restored, connection-loss, and reconnect states are consistent.
- Unit, type, lint, and build checks pass.
- Natural browser proof passes for material operational flows.

## UI-E — Accessibility, Testing, Responsiveness, and Portfolio Quality

Status: In Progress

UI-E is cross-cutting and should progress continuously alongside UI-A through UI-D.

### Objective

Make the UTCP frontend accessible, responsive, testable, clean under browser diagnostics, and portfolio-ready without changing domain authority.

### Included Scope

- Accessibility standards and regression checks.
- Keyboard operation.
- Focus management.
- Responsive behavior.
- Error and console hygiene.
- Frontend unit-test conventions.
- Natural Playwright browser-proof conventions.
- Cross-browser presentation where justified.
- Portfolio-ready information architecture and finish.

### Current Evidence

- ARIA labels exist in current screens.
- Sixteen frontend unit tests pass.
- Natural browser proofs were performed for T1.
- One responsive breakpoint exists.
- No systematic accessibility or responsive contract exists.

### Remaining Implementation

- Define accessibility and keyboard-operation standards.
- Add systematic focus, label, contrast, and responsive checks where practical.
- Establish frontend unit-test and natural Playwright browser-proof conventions.
- Track console and network error hygiene during browser proof.
- Apply a portfolio-quality information architecture and finish pass after UI-A through UI-D have stable surfaces.

### Test Contract

- Preserve and strengthen frontend unit-test conventions.
- Add accessibility and responsive regression checks where practical.
- Use natural browser proof for keyboard operation, responsive behavior, and console/network hygiene when material.

### Browser-Proof Contract

Run natural Playwright browser proof for material accessibility and responsiveness claims: login normally, select tenant context through the UI, navigate actual routes, use keyboard operation, verify focus behavior, check supported viewport sizes, capture console and relevant network errors, and avoid injected sessions or authentication bypasses.

### Non-Goals

- Completing UI-A, UI-B, UI-C, or UI-D feature scope by itself.
- Creating domain screens independent of C/T/V phase authority.
- Runtime correctness or backend lifecycle implementation.

### Completion Criteria

- Accessibility, keyboard, focus, and responsive contracts are documented and enforced by focused checks where practical.
- Natural browser-proof conventions are documented and used where material.
- Shipped screens are clean under relevant console and network diagnostics.
- The UI presents UTCP as one coherent portfolio-quality product.
- Unit, type, lint, and build checks pass.

## First Bounded UI Implementation

### UI-A1 — Adopt Vue Router and Decompose the Application Shell

Implemented repository scope:

- Add official Vue Router.
- Replace handmade pathname routing.
- Extract route-level views from `App.vue`.
- Add a shared `AppShell`.
- Add `/dashboard` as the default authenticated landing page.
- Add capability-aware navigation and explicit forbidden/not-found views.
- Preserve current URLs, redirects, capability gates, tenant switching, login, logout, and secret handling.
- Preserve and strengthen the existing sixteen tests; twenty-one frontend tests now pass locally.
- Add focused router, dashboard, AppShell, and capability-navigation tests.

Remaining proof:

- Complete a later natural Playwright browser proof through real login, tenant context, dashboard, capability-aware navigation, an existing management route, direct URL navigation, browser back/forward, and logout.
