# UI-E2 — Accessibility, Keyboard, Focus, Responsive, and Diagnostic Browser Proof

Verdict: `UI_E_ACCESSIBILITY_BROWSER_PROOF_INCOMPLETE`

One material product defect was found (keyboard focus stranded on `<body>` whenever a focused `UiButton` enters its loading state, and when an inline detail panel is closed). Evidence is preserved below and a bounded correction is recommended. No production code was modified during this proof. UI-E remains In Progress. `UTCP_PHASE=T1` unchanged.

## Source Commit and Web Image

- Source commit: `4e2dfcb` (`feat(ui): establish UI-E accessibility enforcement`).
- Web image built from clean `4e2dfcb`: registry + running digest `sha256:81256c834865506daf140fdee8a629cac52c134b1888a54cfd4386361ef95f43`, `org.opencontainers.image.revision=4e2dfcb22d7305d4c495786bbd116fc6da5f33a5`, `image.created=2026-07-24T22:11:25Z`, WSS/Reverb coordinates `app.utcp.local.test`/`443`/`wss`/`/app`, public Reverb key only.
- API unchanged at `sha256:9a33ef67…` (`ae5cd5f`); gateway not rebuilt. `4e2dfcb` changes no backend/infrastructure production code. Only `deployment/web` was restarted.

## Preserved Workloads

PostgreSQL, Redis, API, Gateway, Traefik, Kamailio, observability, and all other workloads were not restarted. No failed jobs; pending outbox `0`; apiserver egress drift check passed (no repair required).

## Natural Login and Tenant

Real Login page → `admin@utcp.local.test` (break-glass temporary credential) → forced password change completed through the UI → `Local Tenant` selected through AppShell. No injected cookies/sessions/storage/bypass.

## Browser Axe Method

`axe-core` (`apps/web/node_modules/axe-core/axe.min.js`) injected into the real page via Playwright `addScriptTag({ path })` — a temporary proof-only injection, no production dependency added. `axe.run()` executed after each route reached a stable rendered state with the full rule set; `color-contrast` was evaluated by the real browser (not disabled). No broad rule groups disabled. Dark-mode runs were scoped to `wcag2a/wcag2aa/wcag21a/wcag21aa` tags to confirm contrast. Temporary helper and injected script are session-only and removed at cleanup.

## Route Accessibility Matrix

Desktop 1280 px, real-browser axe (contrast included):

| Route | h1 | main | controls | critical | serious | moderate | minor | root overflow |
|---|---|---|---|---|---|---|---|---|
| Login | — (h3) | yes | 3 | 0 | 0 | 1 (`page-has-heading-one`) | 0 | no |
| Change password | — (h3) | yes | 3 | 0 | 0 | 1 (`page-has-heading-one`) | 0 | no |
| Dashboard | yes | yes | 23 | 0 | 0 | 0 | 0 | no |
| Tenants | yes | yes | 43 | 0 | 0 | 0 | 0 | no |
| Users | yes | yes | 84 | 0 | 0 | 0 | 0 | no |
| Memberships | yes | yes | 222 | 0 | 0 | 0 | 0 | no |
| Runtime nodes | yes | yes | 349 | 0 | 0 | 0 | 0 | no |
| Conferences | yes | yes | 136 | 0 | 0 | 0 | 0 | no |
| Runtime operations | yes | yes | 45 | 0 | 0 | 0 | 0 | no |
| Runtime reconciliations | yes | yes | 45 | 0 | 0 | 0 | 0 | no |
| Audit records | yes | yes | 48 | 0 | 0 | 0 | 0 | no |

Every authenticated route: **0 serious and 0 critical** axe violations in Light and Dark, including real-browser color-contrast. The two pre-auth pages carry one moderate best-practice finding (no `<h1>`), classified below.

## Login Keyboard Result

PASS. `login-email` and `login-password` have `<label for>` associations (accessible names "Email"/"Password") and communicate `required`. Keyboard tab order: email → password → Sign in → cycle. One safely-invalid submission (single attempt) set `login-password` `aria-invalid=true` with `aria-describedby=login-password-error`, surfaced a `role=alert`/`aria-live=assertive` "Authentication failed / Invalid credentials." message, and **retained focus on the password field** (not lost/trapped). Keyboard `Enter` submitted; the final successful authentication used the normal login flow.

## AppShell Keyboard Result

PASS. Real keyboard Tab reached the page-size select, tenant select (`Active tenant`), appearance select (`Appearance`), Log out, and all nine nav links in logical order. Every focused control matched `:focus-visible` (ring applied). No non-interactive element appeared in tab order. `Enter` activated a nav link (1 request) and `Space` activated a button (1 request) with no duplicate requests. Zero API requests occurred during tabbing. Corrected Escape handler: at 375 px, opening the mobile menu (`aria-expanded`/`aria-controls="primary-navigation"`) and pressing Escape **with focus on a nav link** closes the menu (`aria-expanded=false`); Escape is intentionally link-scoped (moved from `<nav>` to each `RouterLink`), so Escape from a non-link control inside the open menu does not close it.

## Route Focus Continuity

PASS (minimum contract). Across route transitions activated by keyboard, focus remained on the persistent sidebar nav link (connected, visible, not `<body>`), the destination `<h1>` was reachable, and there was no stale focus, invisible focus, or keyboard trap.

## Form Labels and Accessible Names

PASS. UiFormField renders `<label for>` associated with the slotted control id (e.g. `audit-actor-type-filter`). The two documented lint suppressions are validated by rendered browser semantics: UiSelect callers both have accessible names (tenant select via UiFormField `label[for]="active-tenant"`; appearance select via caller `aria-label="Appearance"`); UiFormField labels associate to their controls. The corrected surfaces render correctly: the UiPagination page-size select resolves "Rows per page" via `label[for]`, and RuntimeNode capability checkboxes carry both `id`+`label[for]` and a wrapping label (e.g. "Runtime event stream").

## Data-View Keyboard Operation

PASS for reachability and request discipline. On Audit records via keyboard only: filter reached and typed, Apply activated with `Enter` → exactly 1 list request (`actor_type=user`); pagination Next activated with `Enter` → exactly 1 list request (`page=2`); detail opened and closed via keyboard. Logical tab order, visible focus, accessible names, no keyboard trap, no pointer-only control. **Exception:** detail open/close focus handling — see Product Defects.

## Detail and Dialog Focus Behavior

The stable routes use an inline **non-modal** detail panel (`aria-label="Selected Audit record detail"`, no `role="dialog"`), so modal focus trapping is correctly not required. However, opening the panel and closing it strand focus on `<body>` — see Product Defects. No modal/dialog pattern exists on the stable routes.

## Non-Color Status and Validation

PASS. Every status/indicator carries text: Runtime operations "succeeded" / "attempt 1/3", Runtime reconciliations "waiting" / "Drift unknown", Conferences "desired closed" / "observed closed", Audit "No outcome", live indicator "Live updates connecting", and form validation via `role=alert` text. States remain understandable in Light and Dark. No color-only cue and no hover-only tooltip as sole signal.

## Light-Mode Contrast Result

PASS. Real-browser `color-contrast` (WCAG 2 AA / 2.1 AA) returned 0 serious and 0 critical failures across all authenticated routes (body text, headings, navigation, labels, inputs, buttons, links, status badges, error messages, disabled controls, secondary/list text, and detail metadata rendered by these routes).

## Dark-Mode Contrast Result

PASS. Real-browser `color-contrast` returned 0 serious and 0 critical failures (and 0 total axe violations) across all nine authenticated routes in Dark.

## Responsive Results

PASS. `document.documentElement.scrollWidth <= window.innerWidth` held for every primary route at 375×812 (Light and Dark) and 768×1024, and at the desktop baseline (1280). Zero root overflow anywhere (max scrollWidth 375 at 375 px, 768 at 768 px). The AppShell collapses to a keyboard-operable "Menu" button below the breakpoint; navigation, headings, filters, data lists, pagination, and detail remained reachable and inside the viewport.

## Focus Visibility Result

PASS. The design tokens define `--focus-ring: 0 0 0 3px #8ab4f873`, applied by `button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { box-shadow: var(--focus-ring) }` plus `a:focus-visible`. Under real keyboard Tab, every focused control matched `:focus-visible` in both Light and Dark, so the ring paints (the `false` reading on a programmatically-focused button is a scripted-focus artifact, not a defect). A focused-state viewport screenshot was captured during the run as corroboration.

## Console and Page-Error Result

PASS. The only console errors were an expected pre-login `401 /api/v1/auth/session` probe (established normal behavior) and the deliberately induced `422 /api/v1/auth/login` invalid submission. Zero unhandled page errors, zero unexpected warnings, zero unexpected failed requests, and zero unexpected 4xx/5xx.

## Network-Hygiene Result

PASS. Keyboard tabbing triggered 0 requests; theme changes (Light↔Dark) triggered 0 domain requests; focus movement caused 0 detail fan-out; one explicit filter/page/refresh action caused exactly its one expected request. No route introduced polling.

## Storage Boundary

PASS. Local/session storage before login, after login, after tenant selection, after route navigation, after filter/detail interaction, after theme changes, and after logout contained only `utcp.appearance` and the metadata-only vendor `pusherTransportTLS`. A forbidden-substring scan (tokens, secrets, passwords, capabilities, tenant id, actor/correlation ids, selected ids, axe results) returned no hits. Filter state lived only in the URL query.

## Keyboard Logout Result

PASS. Log out activated via keyboard `Enter` → `/login`, Sign in visible, 0 post-logout protected requests, focus usable on the Login page, no domain state in browser persistence, no automatic reauthentication. No cookies/storage cleared manually.

## Product Defects

### PRODUCT_DEFECT-1 — Loading `UiButton` strands keyboard focus on `<body>`

- Route/viewport: all stable routes, all viewports (reproduced on Audit records and Users, 1280 px).
- Control/selector: `apps/web/src/components/ui/UiButton.vue` line 7 — `:disabled="disabled || loading"`; observed via the "Refresh" and per-row "Details" UiButtons.
- Reproduction: keyboard-focus a `UiButton` that triggers an async action (e.g. Refresh, or a list-row "Details" trigger) and activate it with Enter/Space.
- Expected: after activation, keyboard focus remains on the trigger (or moves into the opened panel), so the next Tab continues from the user's position.
- Actual: while `loading` is true the button is natively `disabled`; a disabled element cannot hold focus, so the browser blurs it and focus falls to `<body>`. When loading ends the button is re-enabled but focus is not restored. Deterministic capture: `before={BUTTON "Refresh"}`, `during/after={BODY, isBody:true}`; for Details, `beforeOpen={opener "Details"}`, `afterOpen={BODY}` with the opener still present (label "Selected").
- Evidence: focus-tracking `focusin` observer + `document.activeElement` sampling; axe does not detect it (dynamic focus management).
- Impact: WCAG 2.4.3 Focus Order degradation — every keyboard-activated loading action (Refresh, detail open, filter Apply, pagination, node mutations) drops the user to the top of the document.
- Bounded correction seam: in `UiButton.vue`, stop removing the control from the focus order while busy — keep it focusable during `loading` (e.g. use `aria-disabled`/`aria-busy` with an in-handler guard instead of the native `disabled` attribute), or restore focus to `buttonElement` on the falling edge of `loading` when it was the active element. Component-level change only; no view changes required.

### PRODUCT_DEFECT-2 — Closing an inline detail panel strands focus on `<body>`

- Route/viewport: Audit records (shared inline-detail pattern), 1280 px.
- Control/selector: the detail panel Close `UiButton` (e.g. AuditRecordsView `clearSelectedAuditRecord`); the trigger is the row "Details"/"Selected" button.
- Reproduction: open a row detail, then activate the panel's Close control by keyboard.
- Expected: focus returns to the trigger that opened the panel (or another logical in-list position); step 12 requires "detail close behavior does not strand focus."
- Actual: closing removes the Close button's DOM; focus falls to `<body>` (`afterClose={BODY, panelGone:true, opener label "Details"}`).
- Bounded correction seam: on close, move focus back to the originating trigger (the view already tracks `selectedAuditRecordId`; return focus to that row's trigger button via a `ref`/`nextTick` focus call). Related to Defect-1 and can be corrected in the same bounded slice.

## Proof Limitations

`None.` (The moderate `page-has-heading-one` axe finding on the Login and Change-password pages is a genuine, non-blocking best-practice observation, classified as EXPECTED_BEHAVIOR/minor product polish rather than a proof limitation: both pre-auth pages use `<h3>` as the top heading with no `<h1>`. It is 0 serious / 0 critical and does not block the axe completion bar; a future bounded slice may promote the auth-page heading to `<h1>`.)

## Findings Classification

- PASS: route axe (Light + Dark, contrast included), login keyboard + validation association, AppShell keyboard traversal/activation/Escape, route focus continuity, form labels + both lint suppressions, data-view keyboard reachability + request discipline, non-color status, responsive (0 overflow), focus-ring visibility, console/page-error hygiene, network hygiene, storage boundary, keyboard logout.
- PRODUCT_DEFECT: focus stranded on `<body>` by loading-`UiButton` disable (Defect-1) and inline detail close (Defect-2).
- EXPECTED_BEHAVIOR: pre-login `401 /auth/session` probe; link-scoped mobile Escape.
- INTENTIONALLY_INDUCED_FAILURE: single `422` invalid login submission.

## Cleanup

Appearance preference restored to System; browser context logged out and closed; temporary axe injection and focus/observer helpers are session-only; `.playwright-mcp/` removed; no screenshots/credentials/scratch files retained; no port-forwards used. Web remains healthy on `4e2dfcb`; preserved workloads were not restarted.

## Final UI-E Status

In Progress. The static/unit enforcement (UI-E1) and this browser proof establish the accessibility baseline, but the focus-management defect above and the still-pending responsive-contract expansion and portfolio information-architecture finish keep UI-E open.

## Verification Performed

- `apps/web`: `npm run typecheck` (pass), `npm run lint` (a11y rules active, `--max-warnings=0`, pass), `npm run test` (109 passed, incl. axe unit assertions), `npm run build` (pass).
- Repo: `make repository-hygiene`, `make workflow-check`, `make secret-scan`, `git diff --check` — all pass.
- Running web image derives from `4e2dfcb`; accessibility lint and unit axe assertions remain active/passing; no production code changed during proof; no backend/infrastructure authority changed; `UTCP_PHASE=T1` unchanged.
