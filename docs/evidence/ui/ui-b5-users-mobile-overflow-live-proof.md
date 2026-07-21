# UI-B5 — Users Mobile Overflow Correction Live Proof

Verdict: `UI_B_USERS_MOBILE_OVERFLOW_LIVE_PROOF_COMPLETE`

Focused controlled browser proof (Playwright MCP) of the `53f0f32`
(`fix(ui): correct users mobile row overflow`) frontend on the `utcp-local`
k3d cluster, exercised from the real login page at
`https://app.utcp.local.test`. This proof closes the page-level `scrollWidth`
gap that UI-B3 identified: `/admin/users` at 375px now has no page-level
horizontal overflow in Light or Dark. It does not repeat the broader UI-B
authentication, management-view, theme, component, capability, or responsive
corridor.

Playwright MCP only; fresh browser context; no imported cookies, preset
storage, injected session, or authentication bypass. Credentials are not
reproduced here.

## Correction under proof

`apps/web/src/style.css`, `@media (max-width: 720px)`:

```css
.data-row {
  flex-wrap: nowrap;
  height: auto;
}
```

added alongside the existing narrow-breakpoint `.data-row { flex-direction:
column }` rule. No `overflow-x`/`overflow` masking exists anywhere in the
stylesheet, and no `html`/`body` overflow clipping is used. No production code
was modified during this proof.

## Deployed image provenance

- Web image built from the clean `53f0f32` tree via the canonical
  `infrastructure/docker/web/Dockerfile` `app-prod` target, pushed to the local
  k3d registry, and rolled out with `kubectl rollout restart deploy/web`
  (pullPolicy `Always`). Only the web workload was restarted.
- Running web Pod image digest:
  `utcp-local-registry:5000/utcp/web@sha256:fd9d2201bae530f6aaf15715ad8b0bd63775b0d34ba7ee145dc7f43ec12c6ada`.
- Served bundle `assets/index-Be8jXbfq.js`; `npm run build` from `53f0f32`
  reproduces the identical bundle name, confirming deployed == `53f0f32`.
- Canonical HTTPS edge: `/` `200`, `/healthz` `200`, `/dashboard` `200`,
  `/admin/users` `200`.
- Baseline before rollout: web served the prior `f375dd7` digest
  `sha256:4ab3349e…`; API and gateway were `1/1` ready. No API, worker,
  scheduler, PostgreSQL, Redis, Asterisk, Kamailio, Traefik, or observability
  workload was restarted.

## Natural login

Fresh context → real `/login` (title "Sign in - UTCP") → authenticated as the
sanctioned broad-capability proof identity `admin@utcp.local.test` through the
real form. No forced password change was required. Only console entry was the
expected pre-auth `GET /auth/session 401` bootstrap probe. Local Tenant selected
through the AppShell tenant control; navigated to `/admin/users` through the
visible navigation.

## Users dataset

`GET /api/v1/admin/users?page=1&per_page=20 200`, `Page 1 · 207 users`, 20 rows
with 20 `Details` links. Search (`#user-search`), status filter
(`#user-status-filter`), `Apply`, `active` status badges, and per-row
`Reset password` / `Suspend` / `Details` controls all present. Representative
long-identity rows exist (longest email
`c2-browser-member-1784001572717@utcp.local.test`, 47 chars). No user was
created or mutated; no manual API call was used as the interaction path.

## Light-theme mobile measurement

Appearance Light, viewport 375×812:

```
innerWidth          = 375
documentScrollWidth = 375   (re-check 375)
bodyScrollWidth     = 375
overflow            = 0
```

No page-level horizontal scrollbar; metadata visible; long identity text wraps;
badges readable; row actions, search, filters, and pagination reachable; no
content clipped.

## Dark-theme mobile measurement

Appearance Dark (same route, tenant, user, dataset), viewport 375:

```
innerWidth          = 375
documentScrollWidth = 375
bodyScrollWidth     = 375
overflow            = 0
```

Theme switch made **zero** API requests (`apiDelta = 0`), left the dataset
unchanged (`Page 1 · 207 users`), and left tenant/session unchanged. No
light-only surface; text, badges, metadata, and actions readable; `active` badge
recoloured to dark-readable green (`rgb(143,214,163)`).

## Computed responsive contract

Representative `.data-row` at 375px:

```
display        = flex
flexDirection  = column
flexWrap       = nowrap
height         = 434.516px  (used value; the breakpoint specifies height: auto —
                             content-sized, not a fixed/stretched cap)
minWidth       = 0px
width          = 351px   (row right 363 ≤ viewport 375)
```

`flex-wrap: nowrap` is the operative change: children stack in one vertical
column instead of wrapping into a second off-viewport column. The used height is
the natural stacked-content height, and because wrapping is disabled it produces
no horizontal expansion (0 offenders, `scrollWidth == innerWidth`).

## Metadata-subgrid bounds

Representative `.subgrid` at 375px:

```
display             = grid
gridTemplateColumns = 325px   (single column)
left  = 25
right = 350
width = 325
withinViewport = true   (350 ≤ 375)
withinRow      = true   (350 ≤ row right 363)
```

Subgrid children are stacked in one vertical column: all share `left = 25` with
strictly increasing `top` (e.g. 2079 → 2125 → 2172 → 2218 → 2265). No second
horizontal column is formed.

## Representative row integrity

Longest-content row
(`C2 Browser Member 1784001572717 · c2-browser-member-1784001572717@utcp.local.test`):

- Max descendant right edge 350 ≤ viewport 375 — all content within the viewport.
- Long email/identity text wraps rather than forcing page width.
- `active` badge text complete and readable.
- `Reset password` / `Suspend` (buttons) and `Details` (link) present and
  reachable.
- User-detail navigation works
  (`GET /admin/users/8e87b293-… 200`); browser back returned to `/admin/users`
  with `overflow 0`, 20 rows, and search still usable.
- No user state was mutated.

## Page-overflow offender scan

At 375px in both Light and Dark the out-of-bounds scan
(`left < -1 || right > innerWidth + 1`) returned **0 elements**. The page fits
without any element extending past the viewport, so the pass is not the result
of root-level `overflow-x: hidden` clipping (none exists).

## Desktop preservation

Viewport 1280×900, `/admin/users`:

```
documentScrollWidth = 1280, overflow = 0, offenders = 0
.data-row  flexDirection = row, flexWrap = wrap, alignItems = center
.subgrid   gridTemplateColumns = 313.44px 313.44px 313.45px  (three columns)
controls   search / status filter / Apply present
```

The desktop horizontal row layout and three-column metadata grid are intact; the
mobile-only `@media (max-width: 720px)` rule introduced no desktop regression.

## Console and network findings

Across the session the only console error was the expected pre-auth
`GET /auth/session 401` bootstrap probe. All `/admin/users` requests returned
`200`; theme switches produced no `/admin/users` request. No unhandled
rejections, asset failures, unexpected redirects, duplicate requests, or
theme-triggered API calls.

## Cleanup

- Appearance reset to `system`; logged out through the AppShell
  (`POST /auth/logout 200` → `/login`); browser context closed.
- No screenshot, credential, or Playwright scratch file retained; no temporary
  port-forward used.
- No application record was created or changed (read-only interaction).
- Web workload healthy (`web` 1/1 ready).

## Verification

- `apps/web`: `npm run typecheck` (clean), `npm run lint` (`--max-warnings=0`,
  clean), `npm run test` (33 passed / 4 files), `npm run build` (reproduces
  `assets/index-Be8jXbfq.js`).
- Root: `make repository-hygiene`, `make workflow-check`, `make secret-scan` all
  passed; `git diff --check` / `git diff --cached --check` clean.
- Backend `make test` / `make check` suites were not re-run: no backend or
  manifest code changed during this proof.

## Outcome

`/admin/users` at 375px has no page-level horizontal overflow in Light or Dark;
the metadata subgrid stays in one in-row vertical column; long rows remain
readable; badges, actions, and navigation remain usable; no overflow clipping
masks the result; and the desktop layout is preserved. The UI-B3 page-level
`scrollWidth` gap is closed and UI-B is complete.
