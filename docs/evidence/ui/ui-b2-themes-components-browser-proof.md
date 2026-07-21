# UI-B2 — Themes and Core Components Natural Browser Proof

Verdict: `UI_B1_THEMES_COMPONENTS_LIVE_PROOF_COMPLETE`

Controlled live acceptance of the UI-B1 implementation (`3b643d0 — feat(ui): add design tokens themes and
core components`) on the `utcp-local` k3d cluster, exercised through Playwright MCP from the real login
page. No production, backend, or Kubernetes code changed during this task. UI-B remains **In Progress**
(broader component adoption across the other management views is still required for UI-B completion).

## Environment

- Context `k3d-utcp-local`; application URL `https://app.utcp.local.test` (Traefik Gateway HTTPS).
- Repository `HEAD 3b643d0`, working tree clean, `UTCP_PHASE=T1`.
- Playwright MCP; fresh context; no injected cookies/storage/sessions, no bypass. Theme scenarios used
  Playwright media emulation (`prefers-color-scheme`, `prefers-reduced-motion`) and manipulated only the
  `utcp.appearance` localStorage key.

## Frontend image rollout

- Built only the web image from clean `3b643d0` (`BUILD_COMMIT=3b643d0`), pushed to the local registry,
  and `rollout restart deployment/web` only. No API/worker/scheduler/PostgreSQL/Redis/Asterisk/Kamailio/
  Traefik/observability workload was restarted.
- Serving digest moved `sha256:29abd658…` (UI-A2) → **`sha256:f3e23437…`** (single pod
  `web-55bfb55f46-zpgk7`, restarts 0). The served bundle `index-DxzQz-3W.js` matches the local
  `npm run build` output (provenance to `3b643d0`). The served `index.html` contains the synchronous
  `utcp.appearance` anti-flash bootstrap.
- Post-rollout HTTPS: `/`, `/healthz`, `/dashboard` all `200`.

## Natural login

Fresh context → real `/login` → authenticated as the sanctioned admin proof identity
`admin@utcp.local.test` → `/dashboard`. No forced change (password persisted from UI-A2). Only console
error was the expected pre-auth `GET /api/v1/auth/session 401` probe. No credentials in evidence.

## System-light resolution

Cleared `utcp.appearance`, emulated `prefers-color-scheme: light`, reloaded: `data-appearance=system`,
`data-theme=light`, `colorScheme=light`, control shows **System**, stored preference `null` (none written).

## System-dark resolution

No stored preference, emulated dark, reloaded: `data-appearance=system`, `data-theme=dark`,
`colorScheme=dark`, control shows System, stored preference still `null` — no explicit Light/Dark was
written merely because the system resolved dark.

## Runtime system preference changes

Appearance `system`, no reload: start light → `theme=light`; emulate dark → `theme=dark`; emulate light →
`theme=light`. Appearance stayed `system` throughout and the resolved theme followed the media query.
**0 API requests** during the toggles.

## Explicit Light preference

Selected **Light** via the AppShell control while system was dark: `data-appearance=light`,
`data-theme=light`, `localStorage["utcp.appearance"]="light"`, control `light`. Persisted across reload,
direct navigation to `/admin/users`, and browser Back; and **remained Light after a system→dark media
change** (explicit preference overrides the OS). 0 API requests from the media/theme changes.

## Explicit Dark preference

Selected **Dark** while system was light: `data-appearance=dark`, `data-theme=dark`,
`localStorage["utcp.appearance"]="dark"`. Remained Dark across reload, direct `/dashboard` load, navigation
to `/admin/users`, and browser Back/Forward. Migrated dashboard rendered on genuine dark surfaces
(body `rgb(16,24,32)`, panels `rgb(32,50,68)`) — no hard-coded light panel, no invisible text. Tenant and
session context unchanged; no appearance API request.

## Invalid preference recovery

With `utcp.appearance="invalid-proof-value"` and system dark, a fresh load recovered deterministically to
`data-appearance=system`, `data-theme=dark` (current media), control `system`. The app mounted normally
(dark body rendered), with **no page error, no blank render, no fourth theme state, and no API request**.
The invalid value is safely ignored (mapped to system) and was reset to `system` during cleanup.

## Appearance storage boundary

`localStorage` contained only `utcp.appearance` (one of system/light/dark); `sessionStorage` was empty. No
user ID, tenant ID, role, capabilities, session payload, credential, telephony, or dashboard data is stored
by the appearance feature.

## Theme control accessibility

The control is a native `<select>` with accessible name **Appearance**, keyboard-focusable, and operable by
keyboard (ArrowDown changed System→Light and applied+persisted it). It exposes the selected option
correctly, works with no tenant selected, invokes **no API request**, and does not depend on any
administrative capability (it is client-side presentation state in the shared AppShell; the zero-capability
user proven in UI-A2 receives the same shell). The keyboard focus ring is a `box-shadow: var(--focus-ring)`
(`0 0 0 3px` — light `rgb(32 84 147/32%)`, dark `rgb(138 180 248/45%)`) and was visually confirmed rendered
on keyboard focus in a viewport screenshot in dark theme (the `getComputedStyle` reading of the
`:focus-visible` box-shadow is unreliable under headless synthetic focus; the composited paint and the
token/rule/paint mechanism were verified).

## AppShell theme behavior

In both themes: product identity, capability-driven primary navigation, active-route indication
(`aria-current="page"`), tenant selector, appearance selector, user context, logout, and the responsive
menu control all render with semantic token styles, readable text, and visible focus. Theme switching did
not change the active route, tenant context, or trigger logout/session refresh.

## Dashboard theme behavior

In both themes the Identity, Runtime nodes (110), Users and TelephonySessions (207), Memberships (205),
Attention, and Quick-navigation panels render as visually distinct `UiPanel` surfaces. Status meaning is
carried by readable text; counts did not change with theme; no data reload occurred solely from theme
selection.

## Users-view theme behavior

`/admin/users` in both themes: search field, account-status filter, primary (Apply/Create user) and
secondary (Refresh/Details) and destructive (Suspend) buttons, `active` status badges, pagination
("Page 1 · N users"), and user-detail navigation all render with labels associated to controls, visible
focus, and readable status text. Existing API behavior unchanged; a read-only path was used (no mutation).

## Core component semantics

- **UiButton**: native `<button>` with correct `type` (button/submit), accessible names, and `disabled`
  property.
- **UiFormField/UiTextInput/UiSelect**: every control has a programmatic label (Active tenant, Appearance,
  Search, Account status, Email [required], Display name [required]); native `required` honored;
  `aria-describedby`/`aria-invalid` appear only when help/error text is present (covered by
  `UiComponents.test.ts`).
- **UiStatusBadge**: readable text (`active`) with class `ui-status-badge--success`; meaning by text, not
  color alone.
- **UiPanel / UiAlert / loading / empty**: panels render as coherent regions with heading association;
  alert/loading/empty variants are covered by focused unit tests where not naturally present in the live
  dataset.

## Keyboard behavior

Theme control operable via keyboard (focus + ArrowDown). Responsive menu operable via keyboard (Space
toggled `aria-expanded` true/false and showed/hid `#primary-navigation`). Focus reaches the appearance and
navigation controls.

## Responsive behavior

- **Dashboard at 375px**: reflows to a single column with **no horizontal page overflow**
  (`scrollWidth == innerWidth == 375`).
- **Mobile navigation**: the "Menu" control opens and closes the primary navigation via mouse and keyboard;
  the appearance control remains reachable.
- **Users at 375px**: filters/buttons wrap and stack; **0 interactive controls were clipped** (search and
  Apply within the viewport). However, the user-list row `.meta` metadata columns (a `.subgrid`) do not
  collapse to a single column at mobile width, producing page-level horizontal scroll (`scrollWidth` 588 >
  375). No control is affected. Classified as a **non-blocking** Users-view responsive polish gap for the
  next UI-B adoption slice (see Divergences).
- Returning to desktop restored the shell correctly.

## Reduced-motion behavior

With `prefers-reduced-motion: reduce`, button and panel `transition-duration` resolve to `0.01ms` (via the
`tokens.css` reduced-motion block); theme switching still applies instantly and the interface remains fully
usable without animation.

## Contrast evidence

WCAG contrast ratios for core semantic token pairs (computed from rendered colors; light values measured
against the resolved opaque panel surface):

| Pair | Light | Dark |
| --- | --- | --- |
| Primary text on page background | 16.53 | 16.02 |
| Primary text on panel surface | 16.96 | 18.80 |
| Muted text on panel surface | 5.19 | 11.86 |
| Primary button text/background | 7.63 | 12.58 |
| Destructive status text/surface | 6.57 | 9.03 |
| Form text/control background | 17.74 | 12.58 |
| Status badge text/background | 5.21 | 6.92 |
| Link/nav text on background | 17.74 | (dark ≥ AA) |

All core token pairs meet WCAG AA in both themes. No material core-token contrast failure. (An initial
light-theme reading of 1.18/3.87 was a measurement artifact from matching a transparent-background element
and was corrected by resolving the first opaque ancestor background.)

## Wrong-theme-flash evidence

- Explicit **Dark** with system emulated **light** (hardest case): at both `DOMContentLoaded` and the first
  `requestAnimationFrame` (first paint), `data-appearance=dark`, `data-theme=dark`, `colorScheme=dark`, and
  first-paint body background was dark `rgb(16,24,32)`. No light frame preceded dark.
- **System** mode under dark system preference: first-paint `data-theme=dark`, body `rgb(16,24,32)`.
- The synchronous `<head>` bootstrap sets the root attributes before body paint, so there is no material
  wrong-theme flash.

## Console and network findings

The only console error across the session was the expected pre-auth `GET /api/v1/auth/session 401` probe.
No unhandled rejections, asset failures, theme exceptions, unexpected redirects, or TLS/mixed-content
issues. **No appearance/theme API request occurred** anywhere; all network traffic was normal
session/csrf/login/tenant-context/users/runtime-nodes/memberships from reloads and navigation. Theme and
media changes produced 0 API requests (verified with in-page request counters).

## Cleanup

Appearance preference returned to `system` (invalid value removed); browser context closed; Playwright
artifacts and proof screenshots removed; scratch credential removed; no port-forward was created. No
application record was created or changed for this proof (admin active/unchanged; the UI-A2 limited user
remains suspended). The web workload remains healthy.

## Final workload health

`web` `1/1` Running (digest `sha256:f3e23437…`, restarts 0). No other workload touched. `UTCP_PHASE=T1`;
UI-A Complete; UI-B/UI-C/UI-D/UI-E In Progress; T2 Complete; T5 In Progress.

## Divergences

- **Users-view mobile list-row overflow (non-blocking):** at 375px the `/admin/users` user-list `.meta`
  subgrid does not stack, causing page-level horizontal scroll (588px). All interactive controls remain
  reachable and unclipped, and the Dashboard reflows cleanly. This is remaining UI-B component-adoption
  polish for the Users list rows, not a theme-contract or core-component defect, and does not block the
  UI-B1 proof. Recorded as the concrete target for the next UI-B adoption slice.
- **Focus-ring `getComputedStyle` under headless (measurement artifact):** the `:focus-visible` box-shadow
  reads as none via `getComputedStyle` under synthetic/headless focus, but the ring paints (confirmed by
  viewport screenshot) and the token/rule/paint mechanism was verified. Not an application defect.

Neither divergence invalidates the principal UI-B1 theme-and-component claim.
