# UI-E4 — Focus-Management Correction Natural Browser Live Proof

Verdict: `UI_E_FOCUS_MANAGEMENT_LIVE_PROOF_INCOMPLETE`

Both UI-E2 focus defects are live-proven corrected. One separate, pre-existing
request-budget defect was found on pagination controls, which do not opt into the
shared loading guard. No production code was modified during this proof.
UI-E remains In Progress. `UTCP_PHASE=T1` is unchanged.

## Evidence Chain

- [`docs/evidence/ui/ui-e2-accessibility-browser-proof.md`](ui-e2-accessibility-browser-proof.md) — found `PRODUCT_DEFECT-1` (loading `UiButton` strands focus on `<body>`) and `PRODUCT_DEFECT-2` (inline detail close strands focus on `<body>`).
- [`docs/evidence/ui/ui-e3-focus-management-correction.md`](ui-e3-focus-management-correction.md) — removed loading-driven native disabling, added the shared activation guard, added Audit opener focus restoration, added automated regression coverage.

This document is the focused natural browser reproof of those corrected claims. The
complete UI-E2 route, contrast, and responsive matrices were deliberately not repeated.

## Source Commit and Web Image

- Source commit: `4d00dec` (`fix(ui): preserve focus during loading actions`), clean working tree.
- Web image built from clean `4d00dec`: registry digest and running digest both
  `sha256:7f2550c50627c9c07cf42abd09a5a1cfbd32852c01d9bcb5f6c1c23d21807fc4`.
- Provenance labels: `org.opencontainers.image.revision=4d00dec741c80230d96c767563299b5dfabf1174`,
  `org.opencontainers.image.created=2026-07-27T03:35:23Z`, `version=0.1.0-dev`, `IMAGE_SOURCE=local`.
- Build coordinates: `app.utcp.local.test` / `443` / `wss` / `/app`; only the public
  Reverb application key entered the build.
- `4d00dec` changes frontend production code only. Only `deployment/web` was restarted.

## Preserved Workloads

API, gateway, PostgreSQL, Redis, Traefik, Kamailio, Asterisk, Reverb, scheduler, worker,
outbox dispatcher, reconciler, command worker, event normalizer, simulator event source,
registration observers, and the runtime fence worker were **not** restarted. Pod creation
timestamps confirm every workload except `web` predates the rollout.

## Baseline and API-Server Policy Repair

The host had restarted before this proof, shuffling k3d node IPs. The canonical drift check
reported `allow-runtime-fencer-kubernetes-api: stale endpoint destination, expected
172.24.0.5/32, found 172.24.0.2/32`, and the browser-facing login route returned `404`.

Repair used only the canonical render-and-apply path — `scripts/security/render-apiserver-policy`
followed by `kubectl apply -f` of the three rendered policies. `make security-apply` was
deliberately not used because it would have restarted preserved data and platform workloads.
Drift re-checked as `passed endpoint=172.24.0.5/32:6443`; Traefik then recovered on its own
(login `200`) with no Traefik restart. Classified `EXPECTED_BEHAVIOR` / environmental —
the known NetworkPolicy endpoint-pin drift, not an application defect.

Baseline after repair: all workloads Ready, `utcp-migrate` Complete, pending outbox `0`,
no failed jobs in any namespace.

## Natural Login and Tenant

Real Login page → `admin@utcp.local.test` with a bounded break-glass temporary credential
(`EXPIRES_IN=30`, reason recorded) → forced password change completed through the UI →
`Local Tenant` selected through AppShell → navigated to `/admin/audit-records` through the
visible primary navigation. No imported storage state, preset cookies, injected session,
database- or Redis-created session, or authentication bypass was used.

## Proof Method

Bounded, proof-only Playwright request interception held exactly one target request pending
per test. The handler matched only the target URL, held only the first match, never modified
the response body, always completed the request with `route.continue()`, and was removed
immediately after each test. No production code and no backend state was changed to create
loading. Observers for requests, failed requests, page errors, console errors/warnings, and
`focusin`/`focusout` were attached before navigation.

## Refresh Focus Retention — PASS

Keyboard `Tab` landed on Refresh (`:focus-visible` true, no `aria-disabled`, no `aria-busy`).
The Audit list request `GET /api/v1/admin/audit-records?page=1&per_page=20` was held pending
and Refresh activated with `Enter`.

While pending:

| Property | Observed |
|---|---|
| `document.activeElement` | the same Refresh button (`isBody: false`) |
| native `disabled` | `false`, attribute absent |
| `aria-disabled` | `true` |
| `aria-busy` | `true` |
| accessible name | `Refresh Refreshing` (original name preserved) |
| `:focus-visible` | `true` |

After release: `activeElement` remained the Refresh button, `aria-busy` and `aria-disabled`
cleared, name returned to `Refresh`, 20 rows rendered, `:focus-visible` still true.
Total Audit list requests for the action: **1**. Zero page errors, zero failed requests.

## Details Loading Focus Retention — PASS

The row-1 Details trigger was reached by real keyboard `Tab` (12 steps from Refresh). Its
detail request `GET /api/v1/admin/audit-records/9af9c971fa4b7ef6c3ee00b448b07b9c` was held.

While pending: `activeElement` was the originating trigger (`isBody: false`), native
`disabled` `false` with the attribute absent, `aria-disabled="true"`, `aria-busy="true"`,
accessible name `SelectedLoading details`, `:focus-visible` true, trigger still connected.

After release: the inline detail panel rendered (heading `9af9c971`), focus remained on the
trigger, ARIA busy/disabled cleared. Detail requests for the selected ID: **1**. No unrelated
detail request and no Audit list reread occurred. Modal focus movement is correctly not
required — the panel is non-modal (`aria-label="Selected Audit record detail"`, no `role="dialog"`).

## Filter Focus Retention — PASS

With `action=identity.tenant_context.selected` typed into the Audit filter, Apply was focused
by keyboard and its list request held.

Before, during, and after the pending request, `document.activeElement` was the Apply button
(`isBody: false`, `:focus-visible` true), native `disabled` `false`. Exactly **1** list request
was issued. The URL updated to `?actor_type=user&action=identity.tenant_context.selected`.
Filters were cleared through the UI afterwards.

Apply exposes no `aria-disabled` or `aria-busy` because `UiFilterBar` passes no `loading` prop
to its submit `UiButton`; the surrounding `UiDataList` region carried `aria-busy="true"` instead.
This matches the UI-E3 claim for filter Apply (focus positioning and one list request), which
does not assert busy ARIA for this caller.

## Pagination Focus Retention — PASS (focus) / defect on request budget

Focus retention passed: before, during, and after a held page request, `document.activeElement`
was the same Next button (`isBody: false`, `:focus-visible` true), and the control remained
natively enabled. One legitimate activation issued exactly one page request.

Repeated activation during the pending load did **not** meet the zero-additional-request
requirement. See `PRODUCT_DEFECT-3`.

Pagination was returned to page 1 with the default page size (20).

## Loading Native and ARIA State — PASS

For every caller that binds `:loading` (Audit Refresh, Audit Details, Tenants Create tenant),
the loading control was natively enabled, retained keyboard focus, and exposed
`aria-disabled="true"` plus `aria-busy="true"`, clearing both on completion. No loading control
carried a native `disabled` attribute at any point.

## Duplicate Activation Prevention — PASS for loading-guarded controls

| Control | Legitimate requests | Additional during loading |
|---|---|---|
| Audit Refresh (2× `Enter`/`Space` + 2× pointer click) | 1 | **0** |
| Audit Details (`Enter`, `Space`, 2× pointer click) | 1 | **0** |
| Tenants Create tenant (`Enter`, `Space`, pointer click, `Enter` from a form field) | 1 | **0** |
| Audit filter Apply (`Enter`, `Space`) | 1 | **0** |
| Pagination Next (`Enter`) | 1 | **1** — see `PRODUCT_DEFECT-3` |

Audit filter Apply's zero-duplicate result is attributable to URL-backed list-query
deduplication (an unchanged query issues no request), not to the shared loading guard, because
Apply never receives `loading`. This is recorded for accuracy; the guard itself is proven by
Refresh, Details, and Create tenant.

## Explicit Disabled Behavior — PASS

Pagination Previous on page 1 was naturally disabled:

- native `disabled` attribute **present**, `el.disabled === true`
- `aria-disabled` absent and `aria-busy` absent — explicit disabled remains distinct from loading
- programmatic `focus()` refused (`document.activeElement` never became the button)
- `Enter`, `Space`, and a forced pointer click produced **0** requests; page state unchanged

No disabled state was manufactured by altering production data.

## Loading Submit Protection — PASS

A live loading submit `UiButton` was exercised on `/admin/tenants` ("Create tenant"), the safest
available non-destructive, non-lockout submit form. The first `POST /api/v1/admin/tenants` was
held pending.

While pending: `activeElement` was the submit button, native `disabled` `false`,
`aria-disabled="true"`, `aria-busy="true"`, accessible name `Create tenant Creating tenant`,
`:focus-visible` true.

`Enter` on the submit button, `Space` on the submit button, a pointer click, and `Enter` from a
form field produced **0** additional submissions. Exactly **1** legitimate POST was issued —
the loading submit button did not resubmit its parent form.

One disposable proof tenant (`uie4-submit-proof-1785123951138`, "UI-E4 Submit Proof") was
created by the single legitimate submission. It is additive, non-destructive, and consistent
with the existing proof-tenant precedent in this environment.

## Audit Detail Close Focus Restoration — PASS

Record `9af9c971` was opened through its row Details trigger. The detail Close button was
reached by keyboard (`:focus-visible` true) and activated with `Enter`.

Result: the detail panel was removed, `document.activeElement` became the **exact** originating
Details trigger (identity-compared against the stored element, `isBody: false`, still connected,
name back to `Details`), focus was visibly indicated, and **0** Audit list requests and **0**
Audit detail requests were caused by the close.

## Selection-Switch Focus Restoration — PASS

Record A (`703e225e`) was opened, then record B (`f570a780`) was opened using B's own Details
trigger; the rendered detail confirmed B and A's trigger reverted to `Details`. Closing from B:

- `focusReturnedToB: true`
- `focusReturnedToA: false`
- requests caused by focus restoration: **0**

The remembered opener correctly updates when selection changes.

## Detached-Opener Safety — PASS

With a detail open on page 2, the originating row was removed through normal list controls
(keyboard-activated pagination Next to page 3). The opener left the DOM
(`openerStillInDom: false`), the inline detail unmounted with its row, **no exception and no
page error** occurred, focus remained on the pagination button rather than `<body>`, and the
application stayed keyboard usable (next `Tab` reached the page-size select).

Because the normal UI removes the detail together with its row before any close can run, the
close-path detached-opener branch is covered by the automated regression test in
`apps/web/src/App.test.ts` rather than live. This matches the anticipated branch and is not a
blocker. Classified `EXPECTED_BEHAVIOR`.

## Browser Axe Sanity — PASS

`axe-core` (`apps/web/node_modules/axe-core/axe.min.js`) was injected as a temporary,
proof-only script tag. No rule groups were disabled.

| State | Light critical/serious | Dark critical/serious |
|---|---|---|
| Audit Records list | 0 / 0 (0 total) | 0 / 0 (0 total) |
| Audit detail open | 0 / 0 (0 total) | 0 / 0 (0 total) |
| Audit detail closed | 0 / 0 (0 total) | 0 / 0 (0 total) |
| Refresh busy (request held) | — | 0 / 0 (0 total) |

Confirmed alongside: Refresh retains an accessible name while busy (`Refresh Refreshing`);
Details retains an accessible name while busy (`SelectedLoading details`); `aria-disabled` and
`aria-busy` clear after completion; the inline detail keeps its established accessible name
`Selected Audit record detail`; Close remains keyboard reachable and enabled.

## Focus Visibility Sanity — PASS

Under real keyboard focus with the ring transition settled, every representative control matched
`:focus-visible` and painted a 3 px ring in both themes:

| Control | Light | Dark |
|---|---|---|
| Refresh | `rgba(32,84,147,0.32) 0 0 0 3px` | `rgba(138,180,248,0.45) 0 0 0 3px` |
| Filter Apply | same | same |
| Pagination Next | same | same |
| Details | same | same |
| Detail Close | same | same |
| Refresh **while loading** (`aria-busy=true`) | same | same |
| Restored opener after close | same | same |

Loading state does not hide the focus ring, and restored opener focus is visibly indicated.

## Console and Page-Error Result — PASS

Across the whole session (76 requests, 49 API requests): **0** page errors, **0** unexpected
console warnings, **0** failed requests, and exactly one console error — the established
pre-login `401 /api/v1/auth/session` probe, classified `EXPECTED_BEHAVIOR` in UI-E2.

No interception returned a failure response; every held request completed normally. One
`route.continue: Route is already handled!` message surfaced from the proof harness's own
teardown ordering during the first Refresh test; the release itself succeeded (rows rendered,
ARIA cleared) and it produced no failed request and no page error. The helper was corrected to
release before unrouting. Classified `PROOF_LIMITATION` in the harness, not a product defect.

## Network-Hygiene Result — PASS (except pagination)

- one legitimate activation → one request (Refresh, Details, Apply, Create tenant, pagination)
- repeated loading activation → zero additional requests for every `loading`-bound control
- focus restoration (detail close, selection switch) → zero requests
- explicit disabled activation → zero requests
- keyboard logout → zero post-logout protected requests

The single exception is pagination repeated activation (`PRODUCT_DEFECT-3`).

## Storage Boundary — PASS

Local and session storage were sampled before login, after login, on Audit route entry, during
loading, with detail open, after detail close, and after logout. At every checkpoint the
contents were exactly:

```text
localStorage: utcp.appearance, pusherTransportTLS
sessionStorage: (empty)
```

A forbidden-substring scan over both stores for `focus`, `activeelement`, `audit`, `record`,
`selected`, `loading`, `request`, `tenant`, `capabilit`, `token`, `password`, `secret`,
`correlation`, `actor`, the tenant UUID, and `opener` returned **no hits**. No focus target,
Audit ID, selected record, loading state, request state, tenant data, capability data,
credential, or domain record entered persistent browser storage. Filter and pagination state
lived only in the URL query.

## Keyboard Logout Result — PASS

Log out was focused by keyboard (`:focus-visible` true) and activated with `Enter`. The real
Login page appeared at `/login` with Sign in visible, **0** post-logout Audit requests and
**0** post-logout protected `/api/v1/admin/` requests occurred, `Tab` reached the email field
with a visible ring, and storage retained only `utcp.appearance` (reset to `system`) and
`pusherTransportTLS`. No cookies or storage were cleared manually.

## Findings Classification

- `PASS`: Refresh focus retention, Details focus retention, filter focus retention, pagination
  focus retention, loading native/ARIA state, duplicate-activation prevention for every
  `loading`-bound control, explicit disabled behavior, loading submit protection, detail-close
  focus restoration, selection-switch focus restoration, detached-opener safety, axe sanity,
  focus-visibility sanity, console/page-error hygiene, storage boundary, keyboard logout.
- `PRODUCT_DEFECT`: `PRODUCT_DEFECT-3` below.
- `EXPECTED_BEHAVIOR`: pre-login `401 /auth/session` probe; apiserver endpoint-pin drift after a
  host restart and its canonical repair; the normal UI unmounting inline detail together with its
  row on page change.
- `PROOF_LIMITATION`: proof-harness teardown ordering (corrected mid-run); filter Apply and
  pagination expose no busy ARIA because their callers pass no `loading` prop.
- `INTENTIONALLY_INDUCED_CONDITION`: bounded request holds; one disposable proof tenant created
  by the single legitimate submit-protection submission.

## Product Defects

### `PRODUCT_DEFECT-3` — Pagination repeated activation issues additional page requests during a pending load

- **Exact control**: `UiPagination` Previous/Next `UiButton`s — `apps/web/src/components/ui/UiPagination.vue:6-14` and `:22-30`, consumed by `AuditRecordsView.vue:319-329`.
- **Exact reproduction**: on `/admin/audit-records` page 1, keyboard-focus Next, hold
  `GET /api/v1/admin/audit-records?page=2&per_page=20` pending, press `Enter` once (legitimate),
  then press `Enter` again while the request is still pending.
- **Expected**: zero additional page requests while the page load is pending.
- **Actual**: a second identical `GET …?page=2&per_page=20` is issued. Deterministically
  reproduced. With four activations during one pending load the observed sequence was
  `page=2, page=2, page=3, page=4`, advancing the list to page 4 — one exact duplicate plus two
  skipped pages.
- **Actual `activeElement`**: the same Next button throughout (focus is **not** lost) — this part
  of the contract passes.
- **Native and ARIA states while pending**: native `disabled` `false`, `aria-disabled` absent,
  `aria-busy` absent — the button never enters the loading state at all.
- **Request counts**: 1 legitimate + 1 additional per extra activation (0 expected).
- **Source seam**: `UiPagination` passes only `:disabled="page <= 1"` / `:disabled="!hasMore"` to
  its `UiButton`s and never passes `:loading`. The shared activation guard added by `4d00dec`
  only engages when `loading` is true, so it cannot fire for pagination.
- **Smallest bounded correction**: give `UiPagination` a `loading` prop (or `busy`), bind it to
  the caller's list resource `loading`/`refreshing` status, and pass it through to both
  pagination `UiButton`s. No change to `UiButton` itself is required.
- **Not a regression**: before `4d00dec`, `UiButton` computed `:disabled="disabled || loading"`
  with `loading` never supplied by `UiPagination`, so the identical behavior existed. This defect
  is pre-existing and outside the scope of `4d00dec`; it does **not** invalidate the
  focus-management claims, all of which passed.

## Proof Limitations

- Audit filter Apply and pagination controls cannot demonstrate `aria-disabled`/`aria-busy`
  during loading, because `UiFilterBar` and `UiPagination` pass no `loading` prop to their
  `UiButton`s. The shared component contract for those states is covered by the passing
  `UiComponents.test.ts` regression tests and is live-proven through Refresh, Details, and the
  Tenants Create-tenant submit. Narrow and non-blocking for the focus claims.
- The close-path detached-opener branch could not be reached through normal UI behavior, because
  the inline detail unmounts with its row. Covered by the automated regression test.

## Cleanup

Every request interception was released and removed (`page.unrouteAll` confirmed; no residual
hold). Audit filters cleared through the UI, pagination returned to page 1 with default page
size 20, detail closed, appearance reset to `System`, browser context logged out and closed,
temporary axe injection and observers are session-only, `.playwright-mcp/` removed, no
screenshots, credentials, or scratch files retained, no port-forwards used. Web remains healthy
on the `4d00dec` image and preserved workloads were not restarted.

## Remaining UI-E Work

- One bounded correction for `PRODUCT_DEFECT-3` (pagination loading guard).
- Responsive-contract expansion.
- Portfolio information-architecture finish.
- The non-blocking moderate `page-has-heading-one` finding on the Login and Change-password
  pages carried forward from UI-E2.
