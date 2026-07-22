# UI-C6 — Users Stale-Response Correction Live Proof

Verdict: `UI_C_USERS_STALE_RESPONSE_LIVE_PROOF_COMPLETE`

Focused controlled browser proof (Playwright MCP) of the Users stale-response
correction `a021fd0` (`fix(ui): prevent stale users response overwrite`) on the
`utcp-local` k3d cluster, exercised from the real login page at
`https://app.utcp.local.test`. It proves that a delayed obsolete Users query
cannot overwrite the rows or pagination belonging to a newer query, and closes
the blocking UI-C3 stale-response gap found in UI-C4.

Playwright MCP only; fresh browser context; no imported cookies, preset storage,
injected session, or authentication bypass. Only GET Users list requests were
intercepted (for the controlled race and delayed refresh); login, session, CSRF,
tenant-context, and mutations were never intercepted. Credentials/secrets are not
reproduced here.

## Source correction confirmed (a021fd0)

- `appState.refreshUsers` now returns a combined `UsersListResult`
  (`{ users, pagination }`) instead of writing module state inside the loader.
- `UsersView` renders `renderedResult = usersResource.state.data ?? emptyResult`,
  with `renderedUsers` / `renderedPagination` derived from that guarded result;
  the row `v-for` and the pagination bindings both read the guarded result.
- The view's `load()` applies results to the legacy module refs via
  `applyUsersListResult(result)` only when `usersResource.load()` returns a
  non-null result — i.e. only after the `useAsyncResource` monotonic `requestId`
  guard accepts the response. A superseded request returns `null` and is not
  applied.

## Deployed image provenance

- Web image built from clean `a021fd0` via the canonical
  `infrastructure/docker/web/Dockerfile` `app-prod` target, pushed to the local
  k3d registry, rolled out with `kubectl rollout restart deploy/web`
  (pullPolicy `Always`). Only the web workload was restarted.
- Running web Pod image digest:
  `utcp-local-registry:5000/utcp/web@sha256:7cc0e769e260d0a0b6602ab56c68678259100e7e79d7a06dc341907ac666df0b`.
- Served bundle `assets/index-CIWkrmSE.js`; `npm run build` from `a021fd0`
  reproduces the identical bundle, confirming deployed == `a021fd0`.
- Edge: `/` `/healthz` `/dashboard` `/admin/users` all `200`. Baseline before
  rollout: prior `dd03c21` digest `sha256:47232dd1…`, API and gateway `1/1`. No
  other workload restarted.

## Natural login

Fresh context → `/login` → authenticated as `admin@utcp.local.test` (no forced
change) → `/dashboard`; Local Tenant selected through the AppShell.

## Query A definition

`status=active`: URL `?status=active`, summary "Page 1 · 206 users", pagination
"Page 1 of 11", **Next enabled**, 20 active-badge rows (first
"Browser Proof User 1783997634647").

## Query B definition

`status=suspended`: URL `?status=suspended`, summary "Page 1 · 1 users",
pagination "Page 1 of 1", **Next and Previous disabled**, 1 suspended-badge row
("UI-A2 Limited User"). Clearly distinguishable from query A in rows, total,
page count, and Next/Previous availability.

## Delayed-request setup

Playwright `page.route('**/api/v1/admin/users?**')` held the first matching
`status=active` request against an unresolved promise (a real controlled hold,
not an arbitrary sleep) while allowing all other requests to continue. Request
log for the race: `[status=active&page=1&per_page=20 (held), status=suspended&page=1&per_page=20 (fast)]`.

## Query B state before query A release

With query A held, query B was applied through the real filter controls and
completed:

```
URL             = /admin/users?status=suspended
status control  = suspended
summary         = Page 1 · 1 users
pagination      = Page 1 of 1
Next / Previous = disabled / disabled
rows            = 1  (UI-A2 Limited User, suspended)
```

(During the hold, before query B, the view showed the prior data in the
refreshing state with URL/control already reflecting query A intent — the held
request had not yet resolved.)

## Rows after late query A completion

Query A was released and allowed to complete. The rendered rows remained
query B:

```
rows                 = 1  (UI-A2 Limited User, suspended)
active rows visible  = 0
"206 users" anywhere = false
```

Query A's 206 active rows did **not** appear.

## Pagination after late query A completion

```
summary          = Page 1 · 1 users
pagination       = Page 1 of 1
Next / Previous  = disabled / disabled
"Page x of 11"   = absent
```

Query A's larger pagination metadata (206 total, 11 pages, Next enabled) did
**not** appear. Correct rows with stale pagination was explicitly ruled out.

## URL and filter state

After the late query A completion the URL remained `?status=suspended` and the
status control remained "suspended". The resource settled (Refresh control back
to "Refresh"), with no duplicate hidden query-A list and no stale error or
notification.

## Tenant-switch stale-response isolation

With a tenant-A Users request held and a switch to Proof Tenant 1784195144
through the AppShell (canonical `POST /api/v1/auth/tenant-context`), the newer
tenant-B request rendered and remained the authority after the held tenant-A
request was released; the active tenant stayed Proof Tenant, and no Users data
was carried in local or session storage across the switch. Note: `/admin/users`
is platform-scoped in this environment (it returns the same 207-user set under
both Local Tenant and Proof Tenant), so tenant-level row distinguishability on
Users is not observable — the switch is guarded by the same
`useAsyncResource` request-generation guard proven with distinguishable data in
the active/suspended race above, and it produced no overwrite anomaly or error.

## Refresh preservation

After removing race interception, on `status=active` (206 users) the Refresh
control showed a "Refreshing" state, retained the existing 20 rows during the
request, and reread the current canonical query (`status=active&page=1&per_page=20`);
the accepted response replaced the current data. Proportional regression check
only.

## Mutation refresh preservation

Disposable proof user "Browser Proof User 1783998463804"
(`355b50f1-ddea-415c-ad03-580d62317dca`), on the default (unfiltered) list:
Suspend → `role="status"` success notification "User updated" / "User
suspended.", the current canonical query reread through the guarded path
(`page=1&per_page=20`), the guarded result updated (row status active →
suspended), and search / status filter / page / URL were unchanged (not reset).
The user was restored to active through the UI (final state active).

## Console and network findings

The only console error across the proof was the expected pre-auth
`GET /api/v1/auth/session 401` bootstrap probe. The deliberately delayed query A
completed late with a normal `200` (discarded by the guard, no error). No
unhandled promise rejections, duplicate list requests, unexpected redirects, or
unexpected protected requests.

## Cleanup

All request interception and delays removed; disposable user restored to active;
returned to Local Tenant; Users filters reset to default; appearance remained
System (never changed); logged out through the AppShell (→ `/login`); browser
context closed. No screenshot, credential, or Playwright scratch file retained;
no temporary port-forward. The only application change was the disposable user
suspend→activate, restored to its intended active state. Web workload healthy
(`web` 1/1).

## Verification

- `apps/web`: `npm run typecheck` (clean), `npm run lint` (`--max-warnings=0`,
  clean), `npm run test` (49 passed / 6 files), `npm run build` (reproduces
  `assets/index-CIWkrmSE.js`).
- Root: `make repository-hygiene`, `make workflow-check`, `make secret-scan` all
  passed; `git diff --check` / `git diff --cached --check` clean.
- Backend `make test` / `make check` suites were not re-run: no backend or
  manifest code changed during this proof.

## Final web health

`web` Deployment 1/1 ready, serving digest `sha256:7cc0e769…` (built from
`a021fd0`). No other workload was restarted or mutated.

## Outcome

A delayed obsolete Users query can no longer overwrite the rows or pagination of
a newer query: releasing the held query A left query B's rows, status badges,
total, page count, Next/Previous state, list summary, URL, and controls intact.
Tenant-switch isolation, normal refresh, and mutation refresh all remain correct
through the guarded path. The blocking UI-C3 stale-response gap is closed. UI-C
remains In Progress (catalog-driven RuntimeNode forms and broader shared
management-workflow adoption remain).
