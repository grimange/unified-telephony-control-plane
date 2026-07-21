# UI-B1 Design Tokens, Themes, and Core Components Evidence

## Scope

UI-B1 implemented the first bounded design-system slice for the UTCP frontend:

- Semantic CSS token authority.
- System, light, and dark appearance contract.
- Local appearance preference persistence.
- Core accessible UI primitives.
- Adoption by `AppShell`, Dashboard, and `/admin/users` as the representative management view.

This slice did not change backend APIs, backend domain behavior, telephony runtime behavior, Kubernetes infrastructure, route authority, authorization authority, or `UTCP_PHASE`.

## Previous Visual Authority

Before UI-B1, `apps/web/src/style.css` owned the visual system directly with repeated literal colors, repeated control and panel styles, a single compact breakpoint, and screen-specific button/form/panel patterns. There was no reusable component primitive layer and no light/dark theme contract.

## Token Authority

`apps/web/src/styles/tokens.css` is the semantic token authority for:

- Background, navigation, surface, text, muted text, border, primary, danger, warning, success, informational, disabled, and focus-ring colors.
- Spacing scale.
- Typography scale, weights, and line heights.
- Border radii.
- Panel shadow.
- Layout widths.
- Compact breakpoint value for token documentation.

Migrated production CSS consumes semantic custom properties instead of hard-coded light-only surfaces.

## Theme Contract

`apps/web/src/state/theme.ts` implements the appearance contract:

- Valid preferences: `system`, `light`, `dark`.
- Missing or invalid stored values resolve to `system`.
- System mode follows `prefers-color-scheme`.
- System mode updates when the media query changes.
- Explicit light and dark preferences ignore later system changes.
- Theme application uses root attributes on `<html>`: `data-appearance` and `data-theme`.

`apps/web/index.html` applies the stored or system-resolved theme before the Vue application boots to avoid a material flash of the wrong theme.

## Local Preference Boundary

Appearance preference is local presentation state only. The stable storage key is:

```text
utcp.appearance
```

Only the appearance preference is stored. No user ID, tenant ID, capability, session state, credential, API data, telephony state, or backend-managed context is stored with the theme preference. The AppShell control updates this local preference without an API request.

## Core Component Inventory

UI-B1 added:

- `UiButton`
- `UiFormField`
- `UiTextInput`
- `UiSelect`
- `UiPanel`
- `UiStatusBadge`
- `UiAlert`
- `UiLoadingState`
- `UiEmptyState`

No component framework, heavy admin template, duplicate router, duplicate auth system, duplicate state authority, or backend catalog dependency was added.

## Adoption

`AppShell` now uses UI primitives for:

- Appearance control.
- Tenant selector.
- Mobile navigation toggle.
- Logout action.
- Token-backed navigation and shell boundaries.

Dashboard now uses UI primitives for:

- Summary panels.
- Refresh action.
- Loading states.
- Empty states.
- Failure and unauthorized alerts.
- Identity context.
- Attention summary.
- Quick navigation surfaces.

`/admin/users` was migrated as the representative management view for:

- Filter form fields.
- Create-user form fields.
- Buttons.
- Panels.
- Loading and empty states.
- User status badges.
- Pagination controls.

The migration preserved existing API calls, filtering, create-user intent, row actions, pagination, and capability checks.

## Verification Evidence

Focused frontend verification:

```text
cd apps/web && npm run typecheck
cd apps/web && npm run lint
cd apps/web && npm run test
cd apps/web && npm run build
```

Final focused frontend result:

```text
4 test files passed
29 tests passed
frontend build passed
```

Static checks performed during implementation:

```text
rg -n "#[0-9a-fA-F]{3,8}|rgb\(|hsl\(" apps/web/src/style.css
rg -n "localStorage|utcp\.appearance|setItem|getItem|appearanceStorageKey" apps/web/src apps/web/index.html
rg -n "admin template|bootstrap|tailwind|vuetify|naive-ui|element-plus|primevue" apps/web/package.json apps/web/package-lock.json apps/web/src
rg -n "role.*navigation|capabilit|session|identityApi|fetch\(" apps/web/src/components/ui apps/web/src/state/theme.ts
```

The static checks confirmed that migrated CSS uses token values, appearance storage is limited to the preference key, no heavy admin-template dependency was added, and visual/theme primitives do not call backend APIs or own navigation or authorization authority.

## Remaining UI-B Work

UI-B remains `In Progress`. Remaining work includes:

- Broader component adoption across remaining management views.
- Additional status-presentation normalization as more domain screens are built.
- Controlled natural browser proof of system/light/dark behavior.
- Contrast, keyboard, responsive, and persistence acceptance through browser evidence.
