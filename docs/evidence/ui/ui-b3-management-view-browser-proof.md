# UI-B3 — Management-View Component Adoption Browser Proof

Verdict: `UI_B_MANAGEMENT_COMPONENT_ADOPTION_LIVE_PROOF_INCOMPLETE`

Controlled natural browser proof (Playwright MCP) of the `f375dd7` frontend
(`feat(ui): adopt components across management views`) on the `utcp-local`
k3d cluster, exercised from the real login page at
`https://app.utcp.local.test`. The proof confirmed component adoption,
server-catalog authority, credential secrecy, theme behaviour, keyboard and
component semantics, logout, and post-logout protection across the migrated
authentication and management surfaces.

It also completed the page-level `scrollWidth` proof that UI-B2 explicitly left
pending (`ui-b2-remaining-view-component-adoption.md` line 94). That proof
found that the Users narrow-layout metadata **still produces page-level
horizontal overflow at 375px**, so UI-B does not yet meet its completion gate.

Playwright MCP only; fresh browser context; no imported cookies, preset
storage, injected session, or manually constructed authentication. Credentials
are not reproduced in this evidence.

## Deployed image provenance

- Web image built from the clean `f375dd7` tree via the canonical
  `infrastructure/docker/web/Dockerfile` `app-prod` target, pushed to the local
  k3d registry, and rolled out with `kubectl rollout restart deploy/web`
  (pullPolicy `Always`). Only the web workload was restarted.
- Running web Pod image digest:
  `utcp-local-registry:5000/utcp/web@sha256:4ab3349edd7b4aacbc0d9b60a4fe4c42e66575ec77a6b86a47feb04af2fede03`.
- Served bundle `assets/index-BekBKSYU.js`; `npm run build` from `f375dd7`
  reproduces the identical bundle name, confirming deployed == `f375dd7`.
- Canonical HTTPS edge reachable: `/` `200`, `/healthz` `200`, `/dashboard`
  `200`.
- No API, worker, scheduler, PostgreSQL, Redis, Asterisk, Kamailio, Traefik, or
  observability workload was restarted.

## Natural login

Fresh context → real `/login` (title "Sign in - UTCP", heading "Sign in",
Email + Password fields, "Sign in" submit). Authenticated as the sanctioned
broad-capability proof identity `admin@utcp.local.test`. A bounded temporary
password was issued through the sanctioned break-glass
`scripts/identity/user-access-reset-password` lifecycle (which revoked prior
sessions and set `password_change_required`); the change was completed through
the real web form.

Auth request sequence (no duplicate submissions):
`GET /auth/session 401` (bootstrap probe) → `GET /auth/csrf 200` →
`POST /auth/login 200` → `GET /auth/session 200`
(`password_change_required` ⇒ `/change-password`).

- Email/password labels are programmatically associated (`label[for]` matches
  input id); `autocomplete="username"` / `current-password`; both `required`.
- The shared alert/error contract renders through `UiAlert`
  (`role="alert"`, title "Authentication failed") bound to the shared
  `appState.error`; the password field associates the error via
  `aria-describedby="login-password-error"`.
- Credentials are not persisted (temp and new password absent from local and
  session storage after login).

Minor semantic note (non-blocking): on a first unauthenticated visit the
bootstrap `GET /auth/session 401` drives `appState.fail()` to set
`error = "Sign in to continue."`, which the Login view renders through the
error-variant `UiAlert` titled "Authentication failed" and marks the password
`aria-invalid` before any user action. It is the app's own shared error
contract using the shared component correctly, but the "Authentication failed"
title over an informational "please sign in" prompt is slightly misleading.

## Change-password view

Forced-change corridor proven end to end:
`/login → /change-password → normal update → session refresh → /dashboard`.
`GET /auth/csrf 200` → `POST /auth/change-password 200` → `GET /auth/session 200`.

- Fields `current-password` / `new-password` / `confirm-password` are labelled
  and `required`; `autocomplete` is `current-password` / `new-password` /
  `new-password`; the new-password help is associated via
  `aria-describedby="new-password-help"`.
- A `role="status"` "Password lifecycle" panel describes the refresh-then-redirect
  contract. Backend validation stayed authoritative (`200` on success).
- Password values not persisted; existing redirect contract preserved.
- The view uses the same `app-shell` / `auth-panel` / `form-stack` structure as
  Login (shared `UiPanel`/`UiFormField`), which measured 0 page overflow at
  375px on Login.

## Tenants view

Reached through the visible navigation (`/admin/tenants`). Directory loaded via
`GET /api/v1/admin/tenants 200`. Create-tenant form (`Tenant slug`,
`Display name`, both labelled + `required`) and the tenant directory render with
`UiStatusBadge` `active` (`ui-status-badge--success`) and capability-gated
`Suspend` actions. Read-only inspection only — no tenant was created or mutated.

## Memberships view

`/admin/memberships` loaded membership data
(`GET /api/v1/admin/memberships 200`), current tenant context respected
(Local Tenant). Buttons, selects, status text, and empty/alert structures use
the shared component contract. Read-only only; no membership row was edited.

## Server role-catalog authority

The membership role selector (`#membership-role`: "Tenant administrator",
"Tenant member") is populated from the server catalog, not a hard-coded
frontend list:

- `GET /api/v1/admin/roles 200` fires on the view.
- `MembershipsView.vue` binds options to `tenantRoleOptions`, a computed over
  `appState.roleCatalog`, which is set by `identityApi.roles()`.
- No literal tenant-role array exists in the view source.

This satisfies the server role-catalog authority requirement.

## Runtime-nodes view

`/admin/runtime-nodes` loaded the node list and catalogs from the server:
`GET /admin/runtime-nodes 200`, `GET /admin/runtime-node-catalog 200`, and
per-node `adapter-configuration`, `runtime-evidence`, and `history?limit=10`
(all `200`). Runtime family (`Asterisk`, `FreeSWITCH`, `Deterministic
simulator`) and adapter (`Asterisk ARI`) options come from the catalog request.
Desired/observed status render as text-labelled `UiStatusBadge`
(`desired draft/disabled/active`, `observed unobserved/stale/ready`) with
semantic `--warning`/`--danger`/`--neutral`/`--success` classes; meaning is not
colour-only. The existing single bounded Asterisk adapter seam is preserved; no
new vendor branch is exposed.

Observation (non-blocking): every listed node eagerly fetches its
adapter-configuration + runtime-evidence + history triple on render, producing
~3×N detail requests for N nodes — a performance nuance in the shipped view, not
a correctness or security defect.

## Runtime credential secrecy

Write-only credentials are not exposed by readback:

- All 110 per-node secret inputs are `type="password"`,
  `autocomplete="new-password"`, and empty (value length 0) — no stored secret
  is pre-filled.
- Existing credentials render only `type · vN · status · fingerprint(12)`
  (e.g. `control-api v2 · active · fingerprint 8eb7198b0021`); the
  "Write-only credentials" panel states secrets cannot be retrieved after
  submission.
- No credential secret value appears in the rendered DOM or in any readback
  response.

Minor accessibility note (non-blocking, pre-existing): `id="credential-secret"`
is repeated once per node (duplicate ids across the list).

## Users view

`/admin/users` loaded 207 users, paginated 20 per page
(`Page 1 · 207 users`), with search (`#user-search`), status filter
(`#user-status-filter`: Any/Active/Suspended), an `Apply` action,
`Create user` form, `active` status badges, and per-row `Details` links plus
capability-gated `Reset password` / `Suspend` controls. Search was exercised
read-only: filtering to `admin@utcp.local.test` returned `Page 1 · 1 users`,
then was cleared back to the full list. No user was created or mutated from this
view.

## User-detail view

Opened an existing user through the Users list (read-only). Panels render with
shared components: Identity (`term`/`definition` list — display name, email,
`active` status badge, forced-change flag, created/updated), Tenant memberships
(`Local Tenant · roles tenant-admin · active`), Roles and capabilities
(platform vs active-tenant capability lists), and Active TelephonySession
(`UiEmptyState` "No active TelephonySession"). Back-navigation to `/admin/users`
works.

Action controls are capability- and condition-gated in
`UserDetailView.vue`: `End session` requires `telephony.sessions.manage` **and**
an active session; `Issue/Reissue signaling credential` requires
`telephony.signaling.manage` **and** an active session. The inspected user had
no active TelephonySession, so no lifecycle controls rendered — the expected
"where permitted" outcome. Activation/suspension/password-reset controls live on
the Users list, not the detail view.

## Signaling-secret handling

Not live-exercised. Issuing a one-time signaling credential requires an active
TelephonySession (source-gated as above), and no proof user currently holds an
active session; creating one requires a full SIP-registration telephony flow
outside this UI proof's scope, and no safe disposable session-bearing user was
available. The metadata-only surface was inspected (signaling panel shows
"No active TelephonySession / Signaling registration is unavailable"). The
transient contract is present in source
(`signalingSecretVisible ? oneTimeSignalingCredential.sip_secret : 'hidden'`,
cleared by `closeOneTimeSignalingCredential`) and covered by focused component
tests. No secret was created or copied.

## Light and dark compatibility

The appearance control was exercised on the migrated views. Switching to Dark on
Tenants set `data-theme=dark`, persisted `utcp.appearance=dark`, preserved the
route and active tenant, and made **zero** API requests; the `active` badge
recoloured to a dark-readable green. Switching to Light on Users set
`data-theme=light` with dark text on light background and a readable dark-green
badge. Theme changes preserved route, tenant context, and session state.

## Core component semantics

- `UiButton`: native `<button type="submit">`/`<button type="button">` with
  accessible names and loading labels (`Signing in`, `Save credential`, etc.).
- `UiFormField` / `UiTextInput` / `UiSelect`: `label[for]` association, help/error
  association via `aria-describedby`, `aria-invalid` on error, `required`
  semantics, native keyboard behaviour, and appropriate `autocomplete`.
- `UiPanel`: labelled section structure (kicker + heading) across all views.
- `UiStatusBadge`: readable text label plus a semantic variant class; meaning is
  never colour-only.
- `UiAlert`: `role="alert"` for the shared error contract.
- `UiLoadingState` vs `UiEmptyState`: distinct ("Loading runtime nodes." vs
  "No active TelephonySession"), not conflated with zero values.

## Keyboard behaviour

Tab moved focus to the status-filter select with `:focus-visible` matching and a
visible 3px focus ring (`box-shadow rgba(32,84,147,0.32) 0 0 0 3px`). The
appearance and tenant selects and the mobile nav toggle are keyboard operable.

## Responsive route matrix (375px)

| Route | `documentElement.scrollWidth` | `innerWidth` | Page overflow |
| --- | --- | --- | --- |
| `/login` | 375 | 375 | none (panel 351) |
| `/change-password` | not independently measured — shares Login's proven `auth-panel` | 375 | none (structural) |
| `/dashboard` | 375 | 375 | none |
| `/admin/tenants` | 375 | 375 | none |
| `/admin/memberships` | 375 | 375 | none |
| `/admin/runtime-nodes` | 375 | 375 | none |
| `/admin/users/:id` | 375 | 375 | none |
| `/admin/users` | **691** | 375 | **316px page-level overflow (blocking)** |

The mobile AppShell nav toggle reveals the full sidebar (all five links) and the
appearance control remains reachable at 375px.

## Users mobile-overflow proof (BLOCKING — completion-critical)

At a 375px viewport `/admin/users` produces a page-level horizontal overflow:

```
document.documentElement.scrollWidth = 691
window.innerWidth                    = 375
overflow                             = 316px
```

This is worse than the ~588px previously observed, i.e. the UI-B2 "correction"
did not eliminate the page-level overflow.

Root cause (isolated, reproducible):

- The overflowing elements are the per-user-row `.subgrid` metadata blocks,
  rendered at `left≈366, right≈691, width≈325` — a second column outside the
  viewport. Every ancestor from `.data-row` up through `MAIN` fits
  (`left 12 → right 363`, width 351).
- `.data-row` is `display:flex`. The base rule sets `flex-wrap: wrap`; the
  `@media (max-width: 720px)` block sets `.data-row { flex-direction: column }`
  but never resets `flex-wrap`. With a stretched row height (~435px) and three
  stacked children (identity block, `.row-actions`, `.subgrid` metadata),
  column-direction wrapping pushes the third child into a **second column** to
  the right, expanding the page to 691px.
- Confirmed by a non-destructive in-browser diagnostic: injecting
  `.data-row{flex-wrap:nowrap;height:auto}` dropped `scrollWidth` from
  691 → 375 with zero off-viewport elements; removing it restored 691. No
  production code was modified and page overflow was **not** masked with
  `overflow-x:hidden`.

Blast radius: the defect is specific to the Users row structure. Tenants,
Memberships, Runtime Nodes, User Detail, Dashboard, and Login all measured 0
page overflow at 375px; simpler `.data-row` rows there do not exceed the
stretched column height and so do not wrap.

## Limited-capability behaviour

Not exercised. The only prior limited proof identity
(`ui-a2-limited-…@utcp.local.test`) was suspended during UI-A2 cleanup, so no
active limited identity remained; no user was created solely to exercise a view.
Capability-driven navigation and route-guard denial were already live-proven in
UI-A2.

## Logout and post-logout protection

AppShell `Log out` → `GET /auth/csrf 200` → `POST /auth/logout 200` → `/login`.
After logout:

- Direct `GET /admin/users` redirected to `/login?redirect=/admin/users`.
- `GET /api/v1/auth/session` returned `401`.
- No protected content in the DOM; no tenant context or one-time secret in
  local/session storage. Only presentation-only `utcp.appearance` remained.

## Console and network findings

Across the whole session the only console error was the expected pre-auth
`GET /auth/session 401` bootstrap probe (and one explicit post-logout `401`
probe). No unhandled rejections, asset failures, unexpected redirects, duplicate
submissions, duplicate route requests, theme-triggered requests, or
TLS/mixed-content errors. No protected API request was made before a capability
or authentication denial.

## Cleanup

- No disposable tenant, user, membership, or signaling credential was created,
  so none required removal.
- `admin@utcp.local.test` remains active with its post-change password and
  `password_change_required=false`; the temporary break-glass credential was
  consumed by the completed change.
- Appearance reset to `system`; browser context closed; screenshot scratch file
  removed; no temporary port-forward used.
- Web workload healthy (`web` 1/1 ready).

## Verification

- `apps/web`: `npm run typecheck` (clean), `npm run lint` (`--max-warnings=0`,
  clean), `npm run test` (33 passed / 4 files), `npm run build` (reproduces
  `assets/index-BekBKSYU.js`).
- Root: `make repository-hygiene`, `make secret-scan`, `make workflow-check` all
  passed; `git diff --check` / `git diff --cached --check` clean.
- Backend `make test` / `make check` suites were not re-run: no backend or
  manifest code changed during this proof.

## Outcome

Component adoption, server-catalog authority, credential secrecy, theme,
keyboard, component semantics, logout, and post-logout protection are proven
across the migrated views. The completion-critical Users 375px page-level
overflow proof **failed** (691px vs 375px), which the roadmap defines as part of
the UI-B completion gate. UI-B therefore remains **In Progress**. The next
bounded UI-B step is to reset `.data-row` `flex-wrap`/height at the mobile
breakpoint (or otherwise stop column-wrapping) and re-run the Users 375px
`scrollWidth` proof.
