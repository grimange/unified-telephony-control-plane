# UI-A1 Application Shell, Dashboard, and Routing Evidence

Date: 2026-07-21

## Scope

UI-A1 implemented the first bounded UI Foundation A slice:

- Official Vue Router route authority.
- Route-level view decomposition.
- Shared authenticated `AppShell`.
- Capability-aware navigation.
- `/dashboard` as the default authenticated landing page.
- Explicit forbidden and not-found route states.
- Dashboard summaries from existing canonical APIs only.

No backend domain behavior, runtime behavior, Kubernetes infrastructure, tests outside the frontend, or phase marker behavior was intentionally changed.

## Implementation Evidence

- `apps/web/src/App.vue` now renders only the top-level router outlet.
- `apps/web/src/router/index.ts` defines the canonical route table and guards for `/login`, `/change-password`, `/dashboard`, `/admin/tenants`, `/admin/memberships`, `/admin/users`, `/admin/users/:id`, `/admin/runtime-nodes`, `/forbidden`, and `/:pathMatch(.*)*`.
- `apps/web/src/layouts/AppShell.vue` owns authenticated product identity, current user context, tenant selection, logout, route title context, responsive navigation, and active route indication.
- `apps/web/src/navigation.ts` maps route entries to server-returned capabilities or capability predicates; it does not use role names as frontend authority.
- `apps/web/src/views/DashboardView.vue` reads RuntimeNode, user, TelephonySession-summary, and membership orientation from existing authorized APIs when current capabilities allow those calls.
- Dashboard cards distinguish loading, success, empty, unauthorized, API failure, and partial-degradation states; a failed card does not blank successful cards.
- Existing signaling credential and RuntimeNode credential paths remain write-only and transient.

## Static Router Removal Evidence

Production frontend source no longer contains the old homemade route authority patterns:

```bash
rg -n "window\.location\.pathname|history\.pushState|window\.onpopstate|addEventListener\('popstate'|popstate|window\.history\.pushState" apps/web/src
```

Result: no matches.

## Test Evidence

Focused frontend baseline before refactor:

```bash
cd apps/web
npm run test
```

Result before implementation: 2 files passed, 16 tests passed.

Focused frontend proof after implementation:

```bash
cd apps/web
npm run typecheck
npm run lint
npm run test
npm run build
```

Result after implementation:

- `npm run typecheck`: passed.
- `npm run lint`: passed.
- `npm run test`: 2 files passed, 21 tests passed.
- `npm run build`: passed.

## Remaining UI-A Proof

UI-A remains `In Progress` pending a controlled natural Playwright browser proof through:

```text
real login
→ tenant context
→ dashboard
→ capability-aware navigation
→ existing management route
→ direct URL navigation
→ browser back/forward
→ logout
```

No live browser proof was performed in UI-A1.
