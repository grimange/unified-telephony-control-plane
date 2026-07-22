# UI-C2 — Shared Async State, Notifications, and Lazy RuntimeNode Detail Browser Proof

Verdict: `UI_C1_ASYNC_STATE_NOTIFICATIONS_LIVE_PROOF_COMPLETE`

Focused controlled browser proof (Playwright MCP) of the UI-C1 implementation
`4f1c40b` (`feat(ui): add shared data states and notifications`) on the
`utcp-local` k3d cluster, exercised from the real login page at
`https://app.utcp.local.test`. It proves the shared async-resource/action
states, one notification authority, informational-vs-submitted Login handling,
bounded RuntimeNode initial requests with on-demand single-node details,
tenant-switch cache invalidation, and unique node-scoped RuntimeNode form IDs.
It does not reopen UI-A, UI-B, router, design-system, component, responsive, or
UI-C architecture.

Playwright MCP only; fresh browser context; no imported cookies, preset
storage, injected session, or authentication bypass. Credentials and secrets are
not reproduced here.

## Deployed image provenance

- Web image built from the clean `4f1c40b` tree via the canonical
  `infrastructure/docker/web/Dockerfile` `app-prod` target, pushed to the local
  k3d registry, rolled out with `kubectl rollout restart deploy/web`
  (pullPolicy `Always`). Only the web workload was restarted.
- Running web Pod image digest:
  `utcp-local-registry:5000/utcp/web@sha256:3fd8ac581e3a6330cae6e6939b66187f6876792a1a614ec0dd92ca752471cc73`.
- Served bundle `assets/index-DmF_hRD1.js`; `npm run build` from `4f1c40b`
  reproduces the identical bundle name, confirming deployed == `4f1c40b`.
- Canonical HTTPS edge: `/` `200`, `/healthz` `200`, `/dashboard` `200`,
  `/admin/runtime-nodes` `200`. Baseline before rollout: prior `53f0f32` digest
  `sha256:fd9d2201…`, API and gateway `1/1`. No API, worker, scheduler,
  PostgreSQL, Redis, Asterisk, Kamailio, Traefik, or observability workload was
  restarted.

## Initial informational Login state

Fresh context → `/login` (title "Sign in - UTCP", heading "Sign in"). The
bootstrap `GET /api/v1/auth/session` returned `401`, and the view rendered a
single neutral guidance element:

- `role="status"`, `aria-live="polite"`, title "Sign in to continue", body
  "Sign in to continue."
- No "Authentication failed" title present.
- Email and password fields both `aria-invalid = null`.
- No notification region mounted (0 notifications) — the bootstrap `401` is
  handled with `notify:false`.
- Normal tab order: email → password → submit.

## Submitted Login failure

One deliberate attempt with the correct email and an incorrect password
(`POST /api/v1/auth/login 422`, "Invalid credentials.") produced a distinct
state:

- `role="alert"` titled "Authentication failed" with body "Invalid credentials."
- Password field `aria-invalid = true` (field error semantics appear only after
  submission); email not invalid.
- "Sign in to continue" guidance removed.
- Zero notifications created (the submitted password is never placed in a
  notification).
- No credential persisted in local/session storage or the URL; form remained
  usable (submit not disabled).

`useAsyncAction.run()` guards re-entry with
`if (state.status === 'submitting') return null`, preventing duplicate
submission while a request is active.

## Natural login

Authenticated normally with the sanctioned broad-capability proof identity
`admin@utcp.local.test`; no forced password change required; arrived at
`/dashboard`. Local Tenant selected through the AppShell tenant control.

## RuntimeNode count and initial request budget

Navigated to `/admin/runtime-nodes` through the visible navigation. After a
resource-timing marker at navigation:

```
RuntimeNode count                : 110
initial API requests             : 2
  runtime catalog                : 1  (GET /api/v1/admin/runtime-node-catalog)
  RuntimeNode list               : 1  (GET /api/v1/admin/runtime-nodes)
per-node detail requests (initial): 0
```

With 110 nodes the initial load performed **zero** per-node
adapter-configuration, runtime-evidence, history, or credential requests — the
bounded shared-request contract holds.

## On-demand single-node details

Opening one node (`df8c7b7d-d863-42f7-87aa-b6ea159d3c57`) via its Details toggle
issued exactly three requests, all for that node:

```
GET /api/v1/admin/runtime-nodes/df8c7b7d…/adapter-configuration  200
GET /api/v1/admin/runtime-nodes/df8c7b7d…/runtime-evidence       200
GET /api/v1/admin/runtime-nodes/df8c7b7d…/history                200
distinct node IDs requested = 1
```

The 110-row list stayed visible; only the selected node rendered a detail panel
(exactly one `type="password"` credential input on the page, empty — no secret
returned by readback); no other node's details were requested.

## Bounded detail cache

Closing and reopening the same unchanged node issued **0** detail requests
(cache hit: `loadRuntimeNodeDetails` returns early when status is `success`),
while re-rendering the panel. Opening a second node
(`8609e774-818f-41a3-a857-d3b7d6569384`) issued only that node's three detail
requests — no list-wide fan-out.

## Tenant-switch invalidation

With the admin identity's two tenants (Local Tenant, Proof Tenant 1784195144):

```
tenant A (Local): node df8c7b7d opened (3 detail reqs)
→ switch to tenant B (Proof 1784195144) via AppShell:
    POST /api/v1/auth/tenant-context
    GET  /api/v1/admin/runtime-node-catalog
    GET  /api/v1/admin/runtime-nodes
    tenant B list = 0 nodes; 0 expanded detail panels carried over;
    0 per-node detail requests
→ switch back to tenant A: list reloaded
→ reopen node df8c7b7d: 3 fresh detail requests (canonical reread)
```

The tenant switch used the canonical UI flow, refreshed the list for the new
tenant, cleared detail state (`clearRuntimeNodeDetails` in `switchTenant`), did
not leave tenant A details attached under tenant B, and forced a canonical
reread on return — contrasting with the same-context cache hit above. No secret
or stale mutation state crossed tenant context; navigation stayed
capability-driven.

## Successful mutation notification

Disposable proof user `Browser Proof User 1783998463804`
(`355b50f1-ddea-415c-ad03-580d62317dca`), suspended then restored through the
Users UI. On suspend the shared action succeeded and a notification was captured
live (before its ~5s success auto-expire):

- id `notification-3` (stable, unique), `role="status"`, `aria-live="polite"`,
  variant `ui-notification--success`.
- Title "User updated"; message "User suspended." (human-readable, no secret).
- Keyboard-accessible dismiss `<button aria-label="Dismiss User updated">`.
- Affected canonical state refreshed automatically (badge active → suspended;
  action toggled to Activate). No manual refresh, Artisan, reconciliation, or
  direct API call required.

The user was restored to `active` through the UI (final state confirmed).

## Controlled failed mutation notification

Using Playwright request interception on a single `PATCH
/api/v1/admin/users/355b50f1…` (fulfilled with a controlled `500` and a
non-sensitive message; the backend mutation did not execute):

- Exactly one request intercepted.
- Error notification `notification-5`, `role="alert"`, `aria-live="assertive"`,
  variant `ui-notification--error`, title "User action failed", message
  "Simulated server failure for UI-C2 error-notification proof."
- The notification did not auto-expire (still present after 1.5s), was not shown
  as success, and was dismissible by keyboard (focusable dismiss button removed
  it).
- The action exited the submitting state (Suspend button re-enabled → retry
  possible); canonical state stayed `active` (unchanged).
- Interception removed immediately after the single proof. Login, session,
  tenant-context, and credential-secret requests were never intercepted.

This proves the frontend shared error-notification contract, not backend failure
behavior.

## Notification authority and lifecycle

- Exactly one notification region is mounted (`<section aria-label="Notifications">`,
  present only while notifications exist).
- Notification IDs are unique and sequential (`notification-3`, `notification-5`).
- Success (`role=status`/`--success`) and error (`role=alert`/`--error`) are
  distinguishable by text and semantics, not color alone.
- Dismissal controls are keyboard-accessible buttons.
- Success/information notifications auto-expire (~5s); error notifications
  persist until dismissed.
- No notification state exists in `localStorage` or `sessionStorage` (only
  `utcp.appearance` presentation state is stored).

## Unique RuntimeNode form IDs

With two nodes expanded, all 43 rendered `[id]` elements were unique (0
duplicates). Credential fields are node-scoped:

```
credential-secret-df8c7b7d-…            (input type=password, label "Write-only secret")
credential-secret-df8c7b7d-…-help
credential-secret-8609e774-…            (input type=password, label "Write-only secret")
credential-secret-8609e774-…-help
bare "credential-secret" id occurrences : 0
label[for] targets resolving to input   : all
broken label[for] references            : 0
```

No secret value appears in any ID; help/error associations target the matching
node.

## Detail-failure isolation

Using Playwright interception to fail a third node's three detail endpoints
(`1d678ec4-c30f-495b-957a-8330594d1339`, controlled `500`s):

- The node's detail panel entered a scoped error state ("RuntimeNode details
  unavailable" + controlled message); it was not shown as empty detail data.
- The 110-row list stayed fully visible and usable; the two previously opened
  nodes kept their loaded detail; no list-wide page error replaced the view.
- After removing the interception, closing and reopening the node retried
  successfully (full four-form detail panel rendered, no error).

## Secret preservation

No RuntimeNode credential secret, signaling secret, temporary password,
reset-password secret, or login password appeared in notification text, DOM
outside approved transient displays, URL, local storage, session storage,
console, or request descriptions. A direct scan for the known proof passwords
found none anywhere; no password input carried a value; the URL had no query or
secret.

## Console and network findings

Six console errors total, all classified and expected/deliberate:

```
GET  /api/v1/auth/session                                   401  expected pre-login probe
POST /api/v1/auth/login                                     422  deliberate submitted failure
PATCH /api/v1/admin/users/355b50f1…                         500  deliberate intercepted mutation failure
GET  /api/v1/admin/runtime-nodes/1d678ec4…/adapter-configuration 500  deliberate intercepted detail failure
GET  /api/v1/admin/runtime-nodes/1d678ec4…/runtime-evidence      500  deliberate intercepted detail failure
GET  /api/v1/admin/runtime-nodes/1d678ec4…/history?limit=10      500  deliberate intercepted detail failure
```

No unhandled promise rejections, asset failures, unexpected redirects, duplicate
initial RuntimeNode requests, theme-related API calls, or unexpected protected
requests.

## Cleanup

- Disposable user `355b50f1…` restored to `active` through the UI (confirmed).
- All Playwright request interception removed (`unroute` for the users and
  runtime-node detail patterns).
- Returned to Local Tenant; appearance remained `system` (never changed);
  logged out through the AppShell (`POST /auth/logout` → `/login`); browser
  context closed.
- No screenshot, credential, or Playwright scratch file retained; no temporary
  port-forward used.
- Intercepted mutations never reached the backend, so no unintended application
  record changed.
- Web workload healthy (`web` 1/1 ready).

## Verification

- `apps/web`: `npm run typecheck` (clean), `npm run lint` (`--max-warnings=0`,
  clean), `npm run test` (38 passed / 5 files), `npm run build` (reproduces
  `assets/index-DmF_hRD1.js`).
- Root: `make repository-hygiene`, `make workflow-check`, `make secret-scan` all
  passed; `git diff --check` / `git diff --cached --check` clean.
- Backend `make test` / `make check` suites were not re-run: no backend or
  manifest code changed during this proof.

## Outcome

All UI-C1 async-state, notification, and lazy RuntimeNode-detail contracts are
proven live: informational vs submitted Login handling, a bounded 2-request
initial RuntimeNode load with zero per-node fan-out over 110 nodes, on-demand
single-node details with bounded in-memory reuse, tenant-switch invalidation
with canonical reread, success and controlled-failure notifications, one
notification authority with unique IDs and no stored state, unique node-scoped
RuntimeNode form IDs, and single-node detail-failure isolation with successful
retry — all with no secret exposure. UI-C remains In Progress; the remaining
scope (shared tables, URL-backed filter/sort/pagination, broader adoption, and
catalog-driven adapter forms) is unchanged.
