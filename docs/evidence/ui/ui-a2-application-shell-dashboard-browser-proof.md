# UI-A2 — Application Shell, Dashboard, and Navigation Natural Browser Proof

Verdict: `UI_A_APPLICATION_SHELL_DASHBOARD_LIVE_PROOF_COMPLETE`

Controlled live browser proof of the UI-A1 implementation (`90d02b4 — feat(ui): add application
shell dashboard and routing`) on the `utcp-local` k3d cluster, exercised end-to-end through Playwright MCP
from the real login page. No production or backend code changed during this task.

## Environment

- Context `k3d-utcp-local`; application URL `https://app.utcp.local.test` (Traefik Gateway HTTPS, LB
  `172.24.0.2`, resolved to `127.0.0.1` via `/etc/hosts`).
- Repository `HEAD 90d02b4`, working tree clean, `UTCP_PHASE=T1`.
- Playwright MCP only; fresh browser contexts; no injected cookies, storage, headers, or preset sessions.

## Frontend image rollout

- Built only the web image from the clean repository at `90d02b4`
  (`infrastructure/docker/web/Dockerfile`, target `app-prod`, `BUILD_COMMIT=90d02b4`), pushed to the local
  registry, and rolled out **only** `deployment/web` in `utcp-platform`. No other workload (API, workers,
  scheduler, PostgreSQL, Redis, Asterisk, Kamailio, Traefik, Prometheus) was restarted.
- Previous serving digest `sha256:28da8ce78ff9…` (7-day-old pod, pre-UI-A1). New serving digest
  **`sha256:29abd658fc5c…`**; only the new pod (`web-68d78b8957-5qjbx`) remained, restarts `0`.
- The deployed bundle (`dist/assets/index-B_grJwY_.js`, 136.21 kB) matches the local
  `npm run build` output, confirming the deployed assets are the proof commit.
- Post-rollout HTTPS health: `/` `200`, `/healthz` `200`, `/dashboard` (SPA route) `200`,
  `/api/health/ready` `200`. nginx serves the SPA history fallback (`try_files … /index.html`).

## Proof identities

- **Broad-capability user:** `admin@utcp.local.test` — platform role `platform-admin`, memberships on two
  tenants (`local`, `proof-1784195144`), so tenant switching is exercisable. Account required a password
  change, proving the forced-change corridor. A known temporary password was issued through the sanctioned
  break-glass command `make user-access-reset-password` (CLAUDE.md permits resetting one existing account);
  no database or Redis session was created and no cookie/storage was injected.
- **Limited-capability user:** `ui-a2-limited-1784195900@utcp.local.test` — created through the canonical
  web Admin UI (Create user) by the admin, with **no** tenant membership and **no** roles (session
  `capabilities: []`). This is the minimum needed to prove capability-gated navigation and forbidden
  routing. Suspended through the canonical UI after the proof.

## Browser baseline

- Navigating to `/` (fresh context, unauthenticated) redirected `/ → /dashboard → /login?redirect=/dashboard`
  (title "Sign in - UTCP"). Login controls present: Email, Password, Sign in.
- One console error: `GET /api/v1/auth/session → 401` — the expected unauthenticated session probe that
  drives the redirect. Classified as an expected authorization response, not a defect.

## Natural login

Redirect/network sequence (canonical APIs, browser-initiated):
`GET /auth/session 401` (probe) → `GET /auth/csrf 200` → `POST /auth/login 422` (a stale local bootstrap
password — see Divergences) → `POST /auth/login 200` → `GET /auth/session 200`
(`password_change_required=true` ⇒ `/change-password`) → `GET /auth/csrf 200` →
`POST /auth/change-password 200` → `GET /auth/session 200` → arrival at **`/dashboard`**.

- Forced-password-change flow proven: `/login → /change-password → complete → /dashboard`.
- Session response carried a `capabilities` array (recorded below); no credential value appears in
  evidence. Two console errors total (the `401` probe and the `422` stale-password attempt), both expected.

## Application shell

`AppShell` displayed and was exercised (not merely present):

- Product identity "Unified Telephony Control Plane"; current user "UTCP Local Administrator ·
  admin@utcp.local.test"; tenant context; primary navigation; main content; Log out.
- Tenant switcher present (admin has >1 tenant): options "No tenant selected", "Local Tenant",
  "Proof Tenant 1784195144".
- Active-route indication is conveyed beyond color: the active nav link carries `aria-current="page"`
  (plus Vue Router's `router-link-exact-active` class, confirming Vue Router is the route authority).
- Keyboard operation of primary navigation proven: focusing the "Users" nav link and pressing Enter
  navigated to `/admin/users`.
- Responsive navigation control proven at 375px width: a "Menu" button appears (`aria-expanded`,
  `aria-controls="primary-navigation"`); clicking toggled it to expanded and revealed the nav; pressing
  Space on the focused button toggled `aria-expanded` back to `false` and collapsed the nav (keyboard
  operable).

## Dashboard

`/dashboard` is the authenticated landing page. Rendered: Identity card (email, status, tenant, session
expiry), a "Needs attention" attention summary, a Refresh control, and quick-navigation links. With a
tenant selected, summary cards for Runtime nodes, Users and TelephonySessions, and Memberships appeared.
Loading-to-success transitions were observed; no fabricated values.

## Dashboard API evidence

Each visible summary maps to a canonical authenticated API request the browser issued (no manual API
calls):

| Dashboard summary | Backing request | Result |
| --- | --- | --- |
| Users and TelephonySessions: 206 (0 active sessions in page) | `GET /api/v1/admin/users?page=1&per_page=5` | `200` |
| Runtime nodes: 110 | `GET /api/v1/admin/runtime-nodes` | `200` |
| Memberships: 205 | `GET /api/v1/admin/memberships` | `200` |

- No fabricated count appeared; no failed request was shown as zero. Unauthorized summary cards were
  **absent** for the limited user, which made **zero** `/api/v1/admin/*` requests (the UI does not attempt
  summaries the session capabilities do not permit). Refresh re-read canonical data.

## Capability-aware navigation

### Broad-capability user

- No tenant selected — session `capabilities`: `platform.tenants.manage/view`, `platform.users.manage/view`
  only. Navigation showed exactly **Dashboard, Tenants, Users**. Memberships and Runtime nodes were
  correctly hidden (they require tenant-scoped `tenant.memberships.view` / `runtime.nodes.view`, inactive
  with no tenant).
- After selecting "Local Tenant" (`POST /api/v1/auth/tenant-context 200`), capabilities recalculated to
  include `runtime.nodes.view/manage`, `tenant.memberships.view/manage`, `tenant.roles.*`, `telephony.*`,
  and navigation recalculated to **Dashboard, Tenants, Users, Memberships, Runtime nodes**. Every visible
  protected entry maps to a held capability; every hidden entry maps to an absent capability. Navigation
  is driven by the capability array, not role names.

### Limited-capability user

- Session `capabilities: []`, `memberships: []`, `active_tenant: null`. Navigation showed **only
  Dashboard**; no tenant switcher (zero tenants). Dashboard and shell remained fully usable (identity +
  attention + quick-nav). No admin summary cards; no `/api/v1/admin/*` requests were made.

## Existing management workflow

Broad user, through the UI only: Dashboard → Users (`GET /api/v1/admin/users?page=1&per_page=20 200`,
"Page 1 · 206 users", Previous disabled / Next enabled, search + status filter present) → user detail
(`GET /api/v1/admin/users/{id} 200`, Account/Memberships/Roles/TelephonySession rendered) → "Back to
users" → list. Search/filter was also exercised during cleanup ("Page 1 · 1 users" for the limited user).
No console exception occurred.

## Direct URL and reload

- Direct full-page load of `https://app.utcp.local.test/admin/users/8e87b293-…` returned `200` (nginx SPA
  fallback served `index.html`, no gateway 404); Vue Router resolved the User detail view; authentication
  restored from the first-party session (no login redirect); the view then loaded
  `GET /api/v1/admin/users/{id} 200`.
- Direct full-page load of `/dashboard` rendered the Dashboard with auth restored.
- No fallback to the removed handmade router occurred (route titles and `router-link-*` classes are set by
  Vue Router).

## Browser back and forward

Clean in-app SPA history `/dashboard → /admin/users → /admin/users/{id}`, then: Back → `/admin/users`,
Back → `/dashboard`, Forward → `/admin/users`. Each step restored the correct route, title, and
active-navigation state, with no duplicate history entries, no full-page authentication reset, no stale
content, and no console exception.

## Forbidden route

Limited user navigated directly to `/admin/users` (requires a capability it lacks). The frontend route
guard redirected to **`/forbidden`** (ForbiddenView: "This route is not available for the current session
capabilities." with a "Back to dashboard" link). **No** `/api/v1/admin/users` request was made — the guard
blocked before any data fetch, so no protected data was rendered. The unauthorized navigation link was
absent from the shell. No backend authorization was bypassed and no capability was granted to force the
route.

## Not-found route

Full-page load of `/this-route-does-not-exist-uia2` rendered **NotFoundView** ("The requested route does
not exist." + "Back to dashboard"), title "Not found - UTCP" set by Vue Router (not a server-generated
404). The shell stayed coherent; no protected data leaked.

## Tenant switching

Broad user switched from "No tenant selected" to "Local Tenant" via the AppShell switcher:
`POST /api/v1/auth/tenant-context 200` refreshed the session (new expiry), dashboard summaries refreshed
(Runtime nodes and Memberships cards appeared and re-fetched via canonical APIs), and navigation
recalculated from the new capabilities. No stale tenant data was retained. The one-time secret display did
not survive navigation/tenant context change (see Secret handling).

## Secret handling

- `localStorage` and `sessionStorage` were empty throughout; the only JS-readable cookie is `XSRF-TOKEN`
  (CSRF); the session cookie is HttpOnly (not JS-accessible). No auth token in JS storage.
- No secret appeared in any URL.
- The one-time temporary password returned by Create user was surfaced transiently in the UI and was
  **not** persisted: after navigating away it was absent from storage, URL, and page body.
- The existing user-detail signaling metadata view exposed no secret value. No secret was logged to the
  console. Transient/write-only secret handling is additionally covered by frontend unit tests.
- No disposable signaling credential was issued (not required; a safe transient-secret proof was obtained
  from the Create user one-time password).

## Logout and post-logout protection

- AppShell Log out for both users: authenticated route → `POST /auth/logout` → `/login`.
- After admin logout, direct access to `/admin/users` redirected to `/login?redirect=/admin/users`; the
  session endpoint returned `401` on every post-logout probe (server session revoked).
- Browser Back after logout did not restore protected content: it surfaced a browser back-forward-cache
  (bfcache) frozen frame containing only an empty administrative scaffold ("Select an administrative
  view", "Loading") — no user records, counts, tenant data, or PII — and issued no network requests. A
  clean reload of the same URL immediately redirected to `/login`. See Divergences.

## Console and network findings

- Total distinct console errors across both sessions: the expected `GET /auth/session 401` probes and one
  `POST /auth/login 422` (stale-password attempt). No unhandled promise rejections, no failed frontend
  asset requests, no unexpected redirects, no mixed-content/TLS errors, and no duplicate API requests
  indicating double routing.
- The `401` and `422` are expected authorization/validation responses, correctly handled by the UI. No
  unexplained material browser error remains.

## Cleanup

- Disposable limited user suspended through the canonical Users UI (status active → suspended; confirmed
  `suspended` in the identity store).
- No disposable membership or tenant created; no disposable credential issued.
- Browser closed (no background Playwright); temporary `.playwright-mcp` browser-state directory removed;
  scratch credential files removed; no port-forward was ever created (gateway LB + `/etc/hosts` used).
- Existing non-proof users and memberships were not modified.

## Final runtime state

`web` `1/1` Running (digest `sha256:29abd658…`, restarts `0`); `api`, `gateway`, `web` all `1/1`. No other
workload was touched. `UTCP_PHASE=T1`. `T2` remains Complete; `T5` remains In Progress.

## Verification

- `apps/web`: `npm run typecheck` (clean), `npm run lint` (`--max-warnings=0`, clean), `npm run test`
  (21 passed), `npm run build` (built `index-B_grJwY_.js` 136.21 kB, matching the deployed bundle).
- Repository: `make repository-hygiene`, `make workflow-check`, `make secret-scan`, `make check`,
  `make test`, `make build`, `git diff --check`, `git diff --cached --check` (recorded in the final
  report).
- Working tree contained no production/backend/frontend code changes from this task — only this evidence
  document and the two roadmap status updates.

## Divergences

- **Stale local bootstrap credential (non-blocking):** the first `POST /auth/login` returned `422`
  ("Invalid credentials") because `.runtime/identity/bootstrap.json` held a password from a prior session;
  `make identity-bootstrap-local` reuses an existing account without resetting its password. Recovered via
  the sanctioned `make user-access-reset-password` break-glass command, then completed the natural forced
  password change. Classified as a stale local-artifact issue, not an authentication/environment defect
  (API readiness was `200` throughout). `bootstrap.json` remains stale (as it was at task start); the admin
  account is fully functional with its new password.
- **bfcache frozen frame on post-logout Back (non-blocking browser artifact):** pressing browser Back after
  logout displayed a cached visual snapshot of an empty administrative scaffold with no protected data and
  no network activity. The server session was revoked (`401` on all probes) and any reload or real
  navigation immediately redirects to `/login`. This is inherent browser back-forward-cache behavior, not
  an application authorization bypass; no protected content or data was exposed. A future hardening option
  is a `no-store`/bfcache-defeating response on the authenticated shell, tracked under UI-E; it does not
  block UI-A.

Neither divergence invalidates the principal UI-A claim.
