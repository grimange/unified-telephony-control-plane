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

### Authority Boundary

WebSocket and Reverb messages are notifications only.

Canonical state must always be re-read from backend APIs.

### Dependencies

Some domain-specific UI-D completion depends on T2, T3, V0, and later call-control phases. Shared real-time plumbing can be implemented independently.

### Remaining Implementation

- UI-D2 live-proof attempt (evidence: [`docs/evidence/ui/ui-d2-runtime-node-realtime-browser-proof.md`](../evidence/ui/ui-d2-runtime-node-realtime-browser-proof.md)) is **blocked and the UI-D1 corridor is not proven**. Images built and pushed cleanly from `79a8be6` (api `sha256:d0f2691e…`, web `sha256:d56e989a…`, gateway `sha256:dbf58780…`), but `make k8s-apply` fails at the `utcp-migrate` Job before any platform workload is rolled out: `79a8be6` changed the migration overlay from `BROADCAST_CONNECTION=log` to `reverb` (`infrastructure/kubernetes/overlays/local/migration/application-config.properties`) while the migration Job receives no Reverb credentials (`base/migration/migration-job.yaml` has no `utcp-local-reverb-credentials` secretRef and the migration overlay has no matching `secretGenerator`), so `php artisan migrate --force` boots, resolves the default broadcaster, and dies on `Pusher::__construct(): $auth_key must be of type string, null given`. api, worker, outbox-dispatcher, and reverb are all correctly wired — the migration Job is the only gap. Reproduced deterministically outside the cluster; both candidate fixes verified, with the least-privilege correction (restore a non-broadcasting driver for the migration overlay, which never publishes) recommended. All static/automated checks pass (23 backend tests, 58 frontend tests, pint, typecheck, lint, build, k8s/security/runtime-engine config checks, hygiene, workflow, secret scan); no static check currently covers the migration Job's broadcast driver. No browser session was started and the live runtime was left unchanged.
- Correct the migration-overlay broadcast configuration, then re-run the focused natural Playwright MCP proof for the UI-D1 Reverb/WSS RuntimeNode corridor unchanged.
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
