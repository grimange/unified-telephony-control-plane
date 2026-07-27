# UI-E6 — Pagination Loading and Duplicate-Activation Natural Browser Live Proof

Verdict: `UI_E_PAGINATION_LOADING_LIVE_PROOF_COMPLETE`

The UI-E4 pagination request-discipline defect is live-proven corrected on every
representative production caller. No production code was modified during this proof.
UI-E remains In Progress. `UTCP_PHASE=T1` is unchanged.

## Evidence Chain

- [`docs/evidence/ui/ui-e4-focus-management-live-proof.md`](ui-e4-focus-management-live-proof.md) — proved the loading and detail-close focus corrections, but recorded `PRODUCT_DEFECT-3`: a pending pagination request accepted repeated activation, producing `page=2, page=2, page=3, page=4`.
- [`docs/evidence/ui/ui-e5-pagination-loading-correction.md`](ui-e5-pagination-loading-correction.md) — added the `UiPagination` `loading` prop, forwarded it to Previous and Next, wired all four production callers, and added component and route regressions.

This document is the focused natural browser reproof of that correction. The full UI-E
accessibility route matrix, UI-D proofs, T1 proofs, and the completed Refresh/detail
focus proof were deliberately not repeated.

## Source Commit and Web Image

- Source commit: `c40a16b` (`fix(ui): guard pagination while loading`), clean working tree.
- Web image built from clean `c40a16b`: registry digest and running digest both
  `sha256:54403c5d8ef3dcbed1e8e0a9a0f234d3e965a6a97a12a1ba142aa93eacb88542`.
- Provenance labels: `org.opencontainers.image.revision=c40a16ba6806c83bd0587ff84c8dce6fda64dc64`,
  `org.opencontainers.image.created=2026-07-27T05:16:30Z`, `version=0.1.0-dev`, `source=local`.
- Build coordinates: `app.utcp.local.test` / `443` / `wss` / `/app`; only the public Reverb
  application key entered the frontend build.
- `c40a16b` changes frontend production code only. Only `deployment/web` was restarted.

## Preserved Workloads

API, gateway, PostgreSQL, Redis, Traefik, Kamailio, Asterisk, Reverb, scheduler, worker,
outbox dispatcher, reconciler, command worker, event normalizer, simulator event source,
both registration observers, and the runtime fence worker were **not** restarted. Pod
creation timestamps confirm every workload except `web` predates the rollout; the new web
pod is `web-7f68f97c9b-bc74j`.

## Baseline

All workloads Ready, `utcp-migrate` Complete, failed jobs `0`, pending outbox `0`, login
route `200`, and the API-server policy-pin drift check passed
(`endpoint=172.24.0.5/32:6443`) with no repair required. No workload was restarted during
baseline inspection.

## Natural Login and Tenant

Real Login page → `admin@utcp.local.test` with a bounded break-glass temporary credential
(`EXPIRES_IN=30`, reason recorded) → forced password change completed through the UI →
`Local Tenant` selected through AppShell → routes reached through visible AppShell
navigation. No imported storage state, preset cookies, injected session, database- or
Redis-created session, or authentication bypass was used.

## Proof Method

Bounded, proof-only Playwright interception held exactly one canonical list request pending
per measurement. The handler matched one exact request, held only the first match, never
modified the response body, always completed with `route.continue()`, and was removed after
each measurement. No request was failed to create loading, and no persistent mock or service
worker was created.

Page-change events were counted independently of HTTP by instrumenting `history.pushState`,
`history.replaceState`, and `popstate` before navigation. This distinguishes a genuinely
suppressed activation from URL deduplication: a blocked activation emits **no page event at
all**, whereas dedupe would still emit the event and merely skip the request. Every result
below reports both counts.

## Audit Next Loading Result — PASS

Initial state: `/admin/audit-records`, page 1 of 143, 2843 records, `per_page=20`, no active
filters, Previous natively disabled, Next natively enabled. Next was reached by real keyboard
Tab (21 steps) with `:focus-visible` true.

Held request: `GET /api/v1/admin/audit-records?page=2&per_page=20`.

| Property while pending | Observed |
|---|---|
| `document.activeElement` | same Next button (`isBody: false`) |
| native `disabled` | `false`, attribute absent |
| `aria-disabled` | `true` |
| `aria-busy` | `true` |
| role | `button` |
| accessible name | `Go to next page` (unchanged; visible text `Next Loading`) |
| `:focus-visible` | `true` |
| Audit list requests | **1** |

Repeated activation while still pending — `Enter`, `Space`, and two pointer clicks:

```text
additional Audit list requests = 0
additional page-change events   = 0
page-event history              = ["", "?page=2"]
```

The URL never advanced past `?page=2`, and no second `next` event reached the caller at all.

After release: `?page=2`, "Page 2 of 143", 20 rows rendered from the canonical HTTP response,
focus still on Next with `:focus-visible` true, `aria-disabled` and `aria-busy` cleared,
`per_page=20` preserved, filters preserved (none). Zero page errors, zero failed requests.

This directly replaces the UI-E4 failure sequence `page=2, page=2, page=3, page=4`.

## Audit Previous Loading Result — PASS

Measured from page 3 (reached through two normal Next activations) so the destination is a
non-boundary page. Previous was keyboard-focused.

Held request: `GET /api/v1/admin/audit-records?page=2&per_page=20`.

While pending: `activeElement` was Previous (`isBody: false`), native `disabled` `false` with
the attribute absent, `aria-disabled="true"`, `aria-busy="true"`, accessible name
`Go to previous page` (visible text `Previous Loading`), `:focus-visible` true, **1**
legitimate list request.

Repeated `Enter`, `Space`, and two pointer clicks while pending:

```text
additional list requests    = 0
additional page-change events = 0
```

After release: `?page=2`, "Page 2 of 143", 20 rows, `per_page=20` preserved, focus **remained
on Previous** with `:focus-visible` true, and loading ARIA cleared.

### Boundary-transition observation — `EXPECTED_BEHAVIOR`

A separate Previous measurement from page 2 (destination page 1) showed the same pending-state
contract and the same zero additional requests, but after completion `document.activeElement`
became `<body>`. This is the native-disabled boundary transition: arriving at page 1 makes
Previous a boundary control, so it gains the native `disabled` attribute and the browser blurs
it. It is explicit boundary disabling, not loading-driven focus loss, and it is why the
retention measurement above was taken against a non-boundary destination.

## Audit Boundary Disabled Result — PASS

| Boundary | Native `disabled` | `aria-disabled` | `aria-busy` | Focus | Requests | Page events | URL |
|---|---|---|---|---|---|---|---|
| Page 1 Previous | attribute **present**, `el.disabled=true` | absent | absent | `focus()` refused (`activeElement` stayed `BODY`) | **0** | **0** | unchanged (`""`) |
| Page 143 Next (final page, 3 rows) | attribute **present**, `el.disabled=true` | absent | absent | `focus()` refused | **0** | **0** | unchanged (`?page=143`) |

Both boundaries rejected `Enter`, `Space`, and a forced pointer click. The final page was
reached through the supported direct page-query flow (`?page=143`); no backend record was
mutated to create it. Boundary disabling exposes **no** loading ARIA, keeping it clearly
distinct from the loading state.

## Runtime Operations Pagination Result — PASS

Reached through visible AppShell navigation to `/operations/runtime-operations`.

The preferred `status=running` filter returned **0 rows and no pagination** — `running` is a
transient operation state and none were in flight. The measurement therefore used
`status=succeeded`, a safe supported filter from the same server-provided catalog, recorded
here as a deliberate substitution rather than a silent change.

State: `?status=succeeded`, 4675 Runtime Operations, page 1 of 234, `per_page=20`, no detail
open. A quiet-window check first confirmed **0** background realtime list rereads over 8 s, so
the counts below are attributable to activation alone rather than to this route's realtime
event stream.

Held request: `GET /api/v1/admin/runtime-operations?status=succeeded&page=2&per_page=20`.

While pending: `activeElement` was Next, role `button`, accessible name `Go to next page`,
native `disabled` `false` with the attribute absent, `aria-disabled="true"`,
`aria-busy="true"`, `:focus-visible` true, **1** list request.

Repeated `Enter`, `Space`, and two pointer clicks:

```text
additional list requests      = 0
additional page-change events = 0
```

After release: `?page=2&status=succeeded`, "Page 2 of 234", 20 rows, status filter
`succeeded` **preserved**, `per_page=20` preserved, ordering preserved (every rendered row
`succeeded`), focus still on Next, loading ARIA cleared, and **0** detail requests — no detail
fan-out. Runtime Operations functional and realtime behavior was not re-proven.

## Users Pagination Result — PASS

`/admin/users`, 207 users, page 1 of 11, `per_page=20`. One Next activation with
`GET /api/v1/admin/users?page=2&per_page=20` held pending:

```text
legitimate requests           = 1
aria-disabled / aria-busy     = true / true while pending
native disabled               = false
focus retained on Next        = yes (:focus-visible true)
additional requests           = 0
additional page-change events = 0
after release                 = "Page 2 of 11", per_page 20, ARIA cleared, focus on Next
```

No users were created for this check.

## Loading Native and ARIA State

Across Audit Next, Audit Previous, Runtime Operations Next, and Users Next, every available
pagination control while pending was:

```text
role            = button
accessible name = "Go to previous page" / "Go to next page" (unchanged)
native disabled = false (attribute absent)
aria-disabled   = "true"
aria-busy       = "true"
keyboard focus  = retained on the activating control
```

After completion, every control had `aria-disabled` and `aria-busy` absent, an unchanged
accessible name, and remained natively enabled unless it had become a boundary control.

## Duplicate Activation Result

| Route | Control | Repeated inputs | Additional requests | Additional page events |
|---|---|---|---|---|
| Audit Records | Next | Enter, Space, 2× click | **0** | **0** |
| Audit Records | Previous | Enter, Space, 2× click | **0** | **0** |
| Runtime Operations | Next | Enter, Space, 2× click | **0** | **0** |
| Users | Next | Enter, Space, 1× click | **0** | **0** |
| Audit Records (page 1) | Previous, boundary | Enter, Space, forced click | **0** | **0** |
| Audit Records (page 143) | Next, boundary | Enter, Space, forced click | **0** | **0** |

Zero page-change events confirms the suppression happens at the shared `UiButton` activation
guard before any `next`/`previous` emit reaches the caller. It is **not** URL deduplication,
debounce, or request cancellation — a deduplicated activation would still have emitted the
page event.

## Request-Count Result

Every accepted request, with exact page parameters:

```text
GET /api/v1/admin/audit-records?page=1&per_page=20          (route entry)
GET /api/v1/admin/audit-records?page=2&per_page=20          (Audit Next, held)
GET /api/v1/admin/audit-records?page=1&per_page=20          (Audit Previous to boundary, held)
GET /api/v1/admin/audit-records?page=2&per_page=20          (Audit Previous from page 3, held)
GET /api/v1/admin/audit-records?page=143&per_page=20        (direct final-page flow)
GET /api/v1/admin/runtime-operations?page=1&per_page=20     (route entry)
GET /api/v1/admin/runtime-operations?status=running&page=1&per_page=20
GET /api/v1/admin/runtime-operations?status=succeeded&page=2&per_page=20  (Ops Next, held)
GET /api/v1/admin/users?page=2&per_page=20                  (Users Next, held)
```

One legitimate activation produced exactly one request in every case. Focus movement
(`Tab`, `Shift+Tab`, `focus()`) produced zero requests.

## Query-State Preservation

| Route | Filter preserved | `per_page` preserved | Ordering preserved |
|---|---|---|---|
| Audit Records | yes (none active; `?page=2` only) | yes (20) | yes |
| Runtime Operations | yes (`status=succeeded` survived the page change) | yes (20) | yes (all rows `succeeded`) |
| Users | yes (none active) | yes (20) | yes |

## Browser Axe Sanity — PASS

`axe-core` (`apps/web/node_modules/axe-core/axe.min.js`) was injected as a temporary,
proof-only script tag. No rule groups were disabled.

| State | critical | serious | total |
|---|---|---|---|
| Audit Records list while pagination loading | 0 | 0 | 0 |
| Audit Records after completion | 0 | 0 | 0 |
| Runtime Operations list while pagination loading | 0 | 0 | 0 |
| Runtime Operations after completion | 0 | 0 | 0 |

## Focus Visibility Sanity — PASS

Audit Next inspected before activation, while loading, and after completion:

| Theme | Before | While loading | After |
|---|---|---|---|
| Light | `:focus-visible` true, ring `rgba(32,84,147,0.32) 0 0 0 3px` | same ring, `aria-busy=true` | same ring |
| Dark | `:focus-visible` true, ring `rgba(138,180,248,0.45) 0 0 0 3px` | same ring, `aria-busy=true` | same ring |

The control never disappeared and focus was never lost. Busy styling does not obscure the
ring. Appearance was reset to `System` after the check.

### Loading-width reflow — `EXPECTED_BEHAVIOR`

While loading, the Next button grows from 64 px to 133 px wide (height unchanged at 40 px) and
its left edge moves from x=1008 to x=939, because `UiButton` appends the visible loading label
beside the preserved control name. This is the committed loading-label design, identical in
both themes, and it causes no focus loss, no control disappearance, and no axe finding. It is
recorded as a known cosmetic reflow rather than an unexpected shift.

## Console and Page-Error Result — PASS

Across the whole session (120 requests, 54 API requests): **0** page errors, **0** unexpected
console warnings, **0** failed requests, and exactly one console error — the established
pre-login `401 /api/v1/auth/session` probe, classified `EXPECTED_BEHAVIOR` since UI-E2.

Every held request completed normally; none was failed. All interceptions were released and
removed.

## Network-Hygiene Result — PASS

```text
one legitimate page activation  → one request
Enter/Space/click while pending → zero additional requests
focus movement                  → zero requests
boundary activation             → zero requests
```

Runtime Operations background realtime churn was measured at **0** rereads over an 8 s quiet
window before the measurement, so no request in the counts above is attributable to the event
stream.

## Storage Boundary — PASS

Local and session storage sampled before login, after login, after tenant selection, during
pending Audit pagination, during pending Runtime Operations pagination, after completion, and
after logout. At every checkpoint:

```text
localStorage:   utcp.appearance, pusherTransportTLS
sessionStorage: (empty)
```

A forbidden-substring scan for `page`, `pending`, `loading`, `audit`, `operation`, `record`,
`selected`, `filter`, `status`, `tenant`, `capabilit`, `token`, `password`, `secret`,
`request`, `focus`, the tenant UUID, and `succeeded` returned **no hits**. No pagination
loading state, pending page, Audit record, Runtime Operation, selected ID, filter, tenant
data, capability data, request state, or focus target entered persistent browser storage.
Filter and page state lived only in the URL query.

## Keyboard Logout Result — PASS

Log out was keyboard-focused (`:focus-visible` true) and activated with `Enter`. The real
Login page appeared at `/login` with Sign in visible, **0** post-logout Audit, Runtime
Operations, or Users requests occurred, `Tab` reached the email field with a visible ring, and
storage retained only `utcp.appearance` (`system`) and `pusherTransportTLS`. No cookies or
storage were cleared manually.

## Findings Classification

- `PASS`: Audit Next, Audit Previous, Audit boundary disabling at both ends, Runtime
  Operations Next, Users Next, loading native/ARIA state, duplicate-activation prevention,
  request counts, query-state preservation, axe sanity, focus visibility, console/page-error
  hygiene, network hygiene, storage boundary, keyboard logout.
- `PRODUCT_DEFECT`: none.
- `EXPECTED_BEHAVIOR`: pre-login `401 /auth/session` probe; focus moving to `<body>` when a
  pagination control becomes a native-disabled boundary control after the page change; the
  loading-label width reflow.
- `PROOF_LIMITATION`: the `status=running` substitution and the deferred Filter Apply
  busy-state gap, both below.
- `INTENTIONALLY_INDUCED_CONDITION`: bounded proof-only request holds.

## Product Defects

`None.`

## Proof Limitations

- **`status=running` substitution.** The preferred Runtime Operations filter returned 0 rows
  and no pagination, because `running` is a transient operation state with none in flight at
  proof time. `status=succeeded` (4675 operations, 234 pages) was used instead. Both are safe
  supported values from the same server catalog, and the substitution does not weaken the
  pagination claim.
- **Deferred Filter Apply busy-state gap.** Carried forward from UI-E4 and still open:
  `UiFilterBar` passes no `loading` prop to its Apply submit `UiButton`, so Apply exposes no
  `aria-disabled`/`aria-busy` while its list request is pending, and its zero-duplicate
  behavior comes from URL-backed list-query deduplication rather than from the shared
  activation guard. This is outside the `c40a16b` scope, which corrects `UiPagination` only.
  It is a candidate for a later bounded slice applying the same `loading`-prop pattern to
  `UiFilterBar`.

## Cleanup

All request interceptions released and removed (`page.unrouteAll` confirmed; no residual
hold). Audit filters cleared, Audit returned to page one with default page size 20; Runtime
Operations filters cleared through the real Clear control and returned to page 1 of 237 with
`per_page=20`; no detail left open on either route; appearance reset to `System`; browser
context logged out and closed; temporary axe injection and observers are session-only;
`.playwright-mcp/` removed; no screenshots, credentials, or scratch files retained; no
port-forwards used. Web remains healthy on the `c40a16b` image and preserved workloads were
not restarted.

## Remaining UI-E Work

- Responsive-contract expansion.
- Portfolio information-architecture finish.
- Optional bounded `UiFilterBar` loading-prop slice for the deferred Apply busy-state gap.
- The non-blocking moderate `page-has-heading-one` finding on the Login and Change-password
  pages, carried forward from UI-E2.
