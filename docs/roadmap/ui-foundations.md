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

Status: In Progress

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

### Remaining Implementation

- Broaden component adoption across the remaining management views (Tenants, Memberships, Runtime nodes, User detail, Change password, Login) without changing domain behavior.
- Stack the `/admin/users` list-row metadata subgrid on narrow viewports so the Users view has no page-level horizontal overflow at mobile widths (Dashboard already reflows cleanly; observed as a non-blocking UI-B2 responsive finding).
- Standardize telephony and runtime status presentation.
- UI-B remains In Progress until component adoption spans the remaining management views; the token/theme/core-component foundation and its browser acceptance are proven.

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

Status: In Progress

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
- Shared table, form, validation, and notification systems do not yet exist.
- One Asterisk-specific adapter-form branch remains in `App.vue`.

### Boundary

The frontend renders server catalogs and submits canonical intent.

It must not introduce checked-in capability catalogs, runtime-management authority, or client-owned lifecycle rules.

### Remaining Implementation

- Extract shared table, pagination, filter, sorting, form, validation, confirmation, and notification patterns.
- Standardize API error and state presentation.
- Replace the Asterisk-specific adapter-form branch with server-catalog-driven rendering.
- Preserve one-time and write-only secret handling.

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

### Authority Boundary

WebSocket and Reverb messages are notifications only.

Canonical state must always be re-read from backend APIs.

### Dependencies

Some domain-specific UI-D completion depends on T2, T3, V0, and later call-control phases. Shared real-time plumbing can be implemented independently.

### Remaining Implementation

- Add notification plumbing and reconnect/degraded presentation.
- Re-read canonical backend APIs on notifications.
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
