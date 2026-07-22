# UI-C4 — URL-Backed List State, History, Pagination, and RuntimeNode Request Preservation Browser Proof

Verdict: `UI_C3_LIST_QUERY_PAGINATION_LIVE_PROOF_INCOMPLETE`

Focused controlled browser proof (Playwright MCP) of the UI-C3 implementation
`dd03c21` (`feat(ui): add shared list query and pagination state`) on the
`utcp-local` k3d cluster, exercised from the real login page at
`https://app.utcp.local.test`.

Most of the UI-C3 contract is proven (URL-backed restoration, deterministic
normalization, Back/Forward, pagination reset, sorting-control absence,
RuntimeNode lazy-detail preservation, tenant-scoped isolation, refresh, and
responsive/storage boundaries). One **blocking stale-response defect** was
found: a delayed older Users query overwrites the newer rendered list. Because
completion criterion 16 ("stale responses cannot replace newer results") fails,
the verdict is INCOMPLETE.

Playwright MCP only; fresh browser context; no imported cookies, preset storage,
injected session, or authentication bypass. No application record was created or
changed. Credentials/secrets are not reproduced here.

## Deployed image provenance

- Web image built from clean `dd03c21` via the canonical
  `infrastructure/docker/web/Dockerfile` `app-prod` target, pushed to the local
  k3d registry, rolled out with `kubectl rollout restart deploy/web`
  (pullPolicy `Always`). Only the web workload was restarted.
- Running web Pod image digest:
  `utcp-local-registry:5000/utcp/web@sha256:47232dd1137e9ae82e838651365247fe4a7eb3040821d484c2afa6319a5005b1`.
- Served bundle `assets/index-bMbk_wP2.js`; `npm run build` from `dd03c21`
  reproduces the identical bundle, confirming deployed == `dd03c21`.
- Edge: `/` `/healthz` `/dashboard` `/admin/users` `/admin/memberships`
  `/admin/tenants` `/admin/runtime-nodes` all `200`. Baseline before rollout:
  prior `4f1c40b` digest `sha256:3fd8ac58…`, API and gateway `1/1`. No other
  workload restarted.

## Natural login and tenant context

Fresh context → `/login` → authenticated as `admin@utcp.local.test` (no forced
change) → `/dashboard`; Local Tenant selected through the AppShell. Broad
identity has two tenants (Local Tenant, Proof Tenant 1784195144).

## Users direct URL restoration

`/admin/users` baseline: clean URL (defaults omitted), list request
`page=1&per_page=20`, 207 total / 20 rows, empty filters, summary
"Page 1 · 207 users", no sensitive data in the query string.

Direct `?page=2&per_page=20&status=active` → normalized URL `?page=2&status=active`
(default `per_page` dropped from the URL but still sent to the API:
`status=active&page=2&per_page=20`); status control = "active"; summary
"Page 2 · 206 users"; 20 rows. Controls initialize from the URL and the rendered
list matches the normalized query.

## Reload restoration

Reloading `?page=2&status=active` preserved the URL, re-issued
`status=active&page=2&per_page=20`, restored the status control, and showed
"Page 2 · 206 users" (query intent preserved, canonical data reread).

## Filter application

From page 2, typing a search term produced **no** per-keystroke request. Apply →
URL `?search=c5-runtime&status=active`, exactly one canonical request
(`search=c5-runtime&status=active&page=1&per_page=20`), history +1 (one router
navigation), **page reset 2 → 1**, controls reflecting the URL, summary
"Page 1 · 35 users", no `sort`/`direction` parameters.

## Page-size reset

From page 2 of the filtered list, changing rows-per-page to 50 → URL
`?search=c5-runtime&per_page=50&status=active`, one request
(`page=1&per_page=50`), **page reset 2 → 1**, search and status preserved,
"Page 1 of 1", history +1.

## Page-only navigation

Next → URL `?search=c5-runtime&page=2&status=active`, one request (`page=2`),
history +1, search/status/per_page preserved, "Page 2 of 2", Next disabled (last
page) and Previous enabled (correct disabled states).

## Unchanged Apply behavior

Pressing Apply with controls already matching the URL → **0** duplicate requests,
**0** history entries, no list reset (the `sameQuery` guard returns without
navigating).

## Browser Back and Forward

Deterministic sequence S1 `/admin/users` → S2 `?status=active` → S3
`?page=2&status=active` → S4 `?per_page=50&status=active`. Back ×3 restored each
prior URL, controls, current page, canonical request, and list summary
(S3 `page=2&per_page=20` → S2 `page=1&status=active` → S1 default 207). Forward
replayed S2 (`status=active`, summary "Page 1 · 206 users", canonical request
re-issued). No stale later response overwrote a restored result during Back/Forward.

## Invalid-query normalization

All via `router.replace` (a single Back from a normalized URL returned to the
prior real entry, not to an invalid URL — confirming replacement, no loop; each
invalid navigation issued exactly one canonical request):

| Input | Final URL | Canonical request |
| --- | --- | --- |
| `?page=0&per_page=999&status=unknown&sort=name&direction=sideways` | `/admin/users` | `page=1&per_page=20` |
| `?page=-1&status=active&sort=email` | `?status=active` | `status=active&page=1&per_page=20` |
| `?page=not-a-number&per_page=abc&direction=sideways` | `/admin/users` | `page=1&per_page=20` |

Invalid `page` (0, -1, non-numeric) → page 1; unsupported `per_page` (999, `abc`)
→ default 20; unknown `status`, `sort`, `direction` → removed; a valid filter
(`status=active`) is preserved while invalid siblings are stripped. The page
remained usable; no error page appeared from malformed parameters.

## Sorting-control absence

Users, Memberships, Tenants, and Runtime Nodes render no `aria-sort`, no
`role="columnheader"`, and no sort-labeled controls. Unsupported `sort`/`direction`
normalize away. List order is canonical server order (no client-side page-only
sorting).

## Memberships behavior

`/admin/memberships?page=3&sort=role&search=x&per_page=50` → normalized
`/admin/memberships` (all unsupported parameters stripped); request
`/api/v1/admin/memberships` with no query. The role selector is populated from
the server role catalog (`GET /api/v1/admin/roles` → "Tenant administrator",
"Tenant member"; no frontend role catalog). No pagination or sorting control is
rendered (the endpoint supplies none); tenant context authoritative; 205 rows.

## Tenants behavior

`/admin/tenants?page=2&sort=name&status=active&search=proof` → normalized
`/admin/tenants`; request `/api/v1/admin/tenants` with no query. The authenticated
tenant context remained "Local Tenant" (list query state does not change the
active tenant). No fake filters/pages/sorting; 26 rows; storage contained no
tenant records.

## RuntimeNode initial request budget

`/admin/runtime-nodes`: **110 RuntimeNodes**, **2** admin API requests
(`runtime-node-catalog` + `runtime-nodes`), **0** per-node detail requests
(adapter-configuration / runtime-evidence / history / credential). Bounded shared
requests, zero fan-out.

## RuntimeNode query normalization

`/admin/runtime-nodes?page=5&search=test&sort=name&direction=desc` → normalized
`/admin/runtime-nodes`; catalog + list still loaded; per-node detail fan-out
remained **0**; no detail panel opened from list-query state; no detail payload
placed in the URL.

## On-demand detail preservation

Opening one node issued exactly its three detail endpoints (single distinct node
id); the 110-row list stayed visible; reopening the unchanged node issued **0**
requests (bounded in-memory cache); no other node's details loaded.

## Tenant-switch list isolation

With one node's detail open under tenant A (110 rows), switching to tenant B via
the AppShell reread the list (catalog + list), showed **0** rows and **0**
expanded detail panels (tenant A rows and details gone), and issued **0**
per-node detail requests (cache invalidated). Switching back to tenant A reread
110 rows, and reopening the original node issued **3 fresh** detail requests (a
canonical reread — no stale tenant-A cache reuse). URL contained only supported
list intent; tenant selection is driven by the AppShell, not query parameters.

## Stale-response protection — BLOCKING DEFECT

Using Playwright interception to delay Users list requests: query A
(`status=active`) delayed ~4s, query B (`status=suspended`) fast.

```
request order      : [status=active (A, delayed), status=suspended (B, fast)]
after B (A pending): URL ?status=suspended, control suspended, summary "Page 1 · 1 users"   ✓
after A released   : URL ?status=suspended, control suspended, summary "Page 1 · 206 users" ✗
                     rows rendered = 20 users, all status "active"
```

The newer query B result was overwritten by the older delayed query A:
`/admin/users?status=suspended` displayed 206 **active** users. This violates
completion criterion 16 and the section-20 requirement that "older query A result
does not overwrite" the newer result.

Root cause (confirmed in source, `dd03c21`): `appState.refreshUsers` writes
module-level `users.value` and `userPagination` **inside** the resource loader
(after `await identityApi.users(...)`). `useAsyncResource.load()` applies its
monotonic `requestId` guard only to the resource's own `state.data`/`state.status`
— but `UsersView` renders rows from the module `users` ref and pagination from the
module `userPagination` ref, which the stale loader has already mutated by the
time the guard runs. The stale-response protection is therefore present but
ineffective for the actually-rendered list. Interception was removed immediately
after the proof; no backend state was mutated (the display self-corrects on the
next navigation/reload).

## Refresh behavior

Success (with a brief controlled delay to observe the transition): the Refresh
control showed a "Refreshing" state, retained the existing 20 rows during the
request, and reread the same canonical query on success.

Controlled failure (intercepted `500`): the previous 20 rows stayed visible, a
"Users unavailable" error was shown distinct from the empty state (no "No users"
empty message), and retry after removing the interception succeeded. A failed
refresh is not treated as an empty dataset.

## Responsive behavior

At 375px, page-level horizontal overflow was **0** on Users, Memberships,
Tenants, and Runtime Nodes (`documentElement.scrollWidth == innerWidth == 375`,
zero out-of-bounds elements). The Users filter bar, pagination, and list summary
remained present and usable; the proven Users mobile-row correction held;
RuntimeNode actions remained reachable within the viewport.

## Browser storage boundary

`localStorage` held only `utcp.appearance`; `sessionStorage` was empty. No user,
membership, tenant, or RuntimeNode list, list metadata, RuntimeNode detail,
filter state beyond URL query intent, notification, capability, or tenant record
was persisted.

## Console and network findings

Two console errors, both classified: expected pre-auth `GET /auth/session 401`
bootstrap probe, and the deliberate intercepted refresh-failure `500`. No
unhandled rejections, asset failures, invalid-query redirect loops, unexpected
sorting requests, or unexpected protected requests. The delayed/stale
interception used real backend responses (no error).

## Cleanup

All request interception and delays removed; returned to Local Tenant; RuntimeNode
panels closed (reload); appearance remained System (never changed); logged out
through the AppShell (→ `/login`); browser context closed. No screenshot,
credential, or Playwright scratch file retained; no temporary port-forward. No
application record was created or changed. Web workload healthy (`web` 1/1).

## Verification

- `apps/web`: `npm run typecheck` (clean), `npm run lint` (`--max-warnings=0`,
  clean), `npm run test` (47 passed / 6 files), `npm run build` (reproduces
  `assets/index-bMbk_wP2.js`).
- Root: `make repository-hygiene`, `make workflow-check`, `make secret-scan` all
  passed; `git diff --check` / `git diff --cached --check` clean.
- Backend `make test` / `make check` suites were not re-run: no backend or
  manifest code changed during this proof.

## Outcome

URL-backed list restoration, deterministic normalization, Back/Forward, pagination
reset, sorting-control absence, RuntimeNode request preservation, on-demand
detail scoping, tenant-switch isolation, refresh behavior, responsiveness, and the
storage boundary are all proven on `dd03c21`. The stale-response protection for
the Users list **fails** (a delayed older query overwrites the newer rendered
list), so the UI-C3 list-query slice is not yet acceptance-clean. UI-C remains In
Progress. The next bounded UI-C step is to guard the rendered `users`/
`userPagination` module state against superseded responses and re-run the Users
stale-response proof.
