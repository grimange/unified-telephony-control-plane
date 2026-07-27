# UI-E8 — Responsive Layout Contract Natural Browser Live Proof

Verdict: `UI_E_RESPONSIVE_CONTRACT_LIVE_PROOF_INCOMPLETE`

Every responsive-layout claim in the UI-E7 contract is live-proven on the shipped
`b53fb9e` web image: all ten primary routes satisfy
`document.documentElement.scrollWidth <= window.innerWidth` at 375 px, 768 px, and
desktop, in Light and Dark, with **no root- or body-level overflow clipping** anywhere.
The verdict is `INCOMPLETE` for one reason only: completion criterion 16 (browser axe
sanity with zero serious violations) is **not** satisfied in pointer-hover states,
because of a **pre-existing, non-responsive** contrast defect in the shared button
hover rule (`PRODUCT_DEFECT-4` below). No production code was modified during this
proof. UI-E remains In Progress. `UTCP_PHASE=T1` is unchanged.

## Evidence Chain

- [`docs/evidence/ui/ui-e2-accessibility-browser-proof.md`](ui-e2-accessibility-browser-proof.md) — established the accessibility baseline and the zero-root-overflow observation at 375×812 and 768×1024.
- [`docs/evidence/ui/ui-e7-responsive-contract-enforcement.md`](ui-e7-responsive-contract-enforcement.md) — codified the responsive contract, supported viewport classes, and repository hygiene enforcement, explicitly deferring natural browser reproof to this slice.

This document is that focused natural reproof. The completed UI-E accessibility route
matrix, focus-management proof, pagination duplicate-activation proof, UI-D proofs, and
T1 proofs were deliberately **not** repeated.

## Source Commit and Web Image

- Source commit: `b53fb9e` (`feat(ui): establish responsive layout contract`), clean working tree.
- Web image built from clean `b53fb9e`: registry digest and running digest both
  `sha256:64e616bd61ad665c813e19285b658f18ed8d0ee7888070ad4c8d1fc35ee3d0c7`.
- Provenance labels: `org.opencontainers.image.revision=b53fb9e830d2c66d652909d0261c3bf9b3607034`,
  `org.opencontainers.image.created=2026-07-27T13:03:59Z`, `version=0.1.0-dev`, `source=local`.
- Build coordinates: `app.utcp.local.test` / `443` / `wss` / `/app`; only the public Reverb
  application key entered the frontend build.
- `b53fb9e` changes frontend production styles only. Only `deployment/web` was restarted.

## Preserved Workloads

API, gateway, PostgreSQL, Redis, Traefik, Kamailio, Asterisk, Reverb, scheduler, worker,
outbox dispatcher, reconciler, command worker, event normalizer, simulator event source,
both registration observers, and the runtime fence worker were **not** restarted. Pod
creation timestamps confirm every workload except `web` predates the rollout; the new web
Pod is `web-55686bfd84-2pjsg` (`2026-07-27T13:04:22Z`), while the next-newest Pod dates to
`2026-07-24T22:35:52Z`.

## Baseline and Environmental Repair

The cluster was found stopped after a host restart (`k3d-utcp-local-serverlb` and
`utcp-local-registry` exited; the k3s server had shut down shortly after boot). The cluster
was restored canonically with `k3d cluster stop/start utcp-local`. Two environmental
conditions were then corrected, both classified below as environmental, not application
defects:

1. A stuck `coredns` Pod (`Unknown`, no IP) left cluster DNS unavailable, which in turn
   caused the `gateway` nginx workload to crash-loop on upstream resolution. Deleting the
   stuck Pod restored DNS and the gateway recovered without any gateway change.
2. Kubernetes API endpoint policy-pin drift: `allow-runtime-fencer-kubernetes-api` still
   pinned `172.24.0.5/32` while the apiserver endpoint had moved to `172.24.0.2`. Repaired
   only through `scripts/security/render-apiserver-policy` followed by a targeted
   `kubectl apply -f .runtime/kubernetes/security/runtime-fencer-apiserver-egress.yaml`.
   No broad apply was used. `scripts/security/check-apiserver-policy-drift` then passed
   (`endpoint=172.24.0.2/32:6443`) and passed again at the end of the proof.

Post-repair baseline: all workloads Ready, `utcp-migrate` Complete, failed jobs `0`
(no `failed_jobs` table; Redis failed queue length `0`), pending outbox `0`, login route
`200`. `kubectl exec` into `postgres-0` remains blocked by the known stale kubelet
serving-certificate SAN on the server node; DB counts were read through the canonical API
Pod instead, so nothing was restarted to work around it.

## Natural Login and Tenant

Real Login page at 375×812 → `admin@utcp.local.test` with a bounded break-glass temporary
credential (`EXPIRES_IN=30`, reason recorded) → forced password change completed through
the UI → `Local Tenant` selected through AppShell → all routes reached through visible
AppShell navigation. No imported storage state, preset cookies, injected session,
database- or Redis-created session, or authentication bypass was used.

## Tested Viewport Matrix

| Class | Size | Themes tested |
|---|---|---|
| narrow | 375 × 812 | Light and Dark |
| intermediate | 768 × 1024 | System |
| desktop | 1440 × 900 | System |

## Route Overflow Matrix

Every cell reports `document.documentElement.scrollWidth` against `window.innerWidth`.
`offenders` counts every element in the document whose bounding rectangle crosses the
viewport edge. All values were measured after reflow settle.

| Route | 375 Light | 375 Dark | 768 | Desktop 1440 | offenders (all cells) |
|---|---|---|---|---|---|
| Login | 375 ≤ 375 | n/a (unauth theme) | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Dashboard | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Users | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Tenants | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Memberships | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Runtime Nodes | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Conference Operations | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Runtime Operations | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Runtime Reconciliations | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |
| Audit Records | 375 ≤ 375 | 375 ≤ 375 | 768 ≤ 768 | 1440 ≤ 1440 | 0 |

Supporting inner measurements were identical across every authenticated route at each
width: `body` and `#app` equal the viewport (375 / 768 / 1440), and `main` measures
351 / 736 / 1280 — an expected inset from the shell gutter, not overflow.

## Root and Body Overflow Styles

`getComputedStyle` returned `overflow-x: visible` on **both** `documentElement` and `body`
on every route at every viewport and theme. A document-wide scan for
`overflow-x: hidden|clip` on `#app`, `.app-shell`, `.shell`, `.workspace`, `.shell-content`,
`main`, `body`, and `html` returned **zero** matches on every route. No pass in this
document is produced by concealed overflow.

## AppShell Responsive Result — PASS

At 375 px the collapsed `Menu` control is visible (`l=12 r=84`), keyboard-focusable, and
`Enter` toggles `aria-expanded` `false → true`. With the navigation open all nine primary
links render inside the viewport (`l=31 r=344`, nav width 313 of 375) and the document
does not widen (`root=375`, `iw=375`). Tenant selector (`l=12 r=247`), Appearance
(`l=263 r=360`), `Menu`, and `Log out` (`l=100 r=186`) all remain within bounds. The topbar
computes `position: static`, so no fixed shell element hides content. Escape retains the
established link-scoped behavior: pressing Escape while `Log out` held focus correctly left
the nav open, while pressing Escape from the `Runtime operations` nav link closed it
(`aria-expanded=false`, 0 visible primary links) without widening the document.

At 768 px and 1440 px the `Menu` control is not rendered and the persistent sidebar is
shown. Navigation and workspace never intersect (768: nav `l=35 r=217` / workspace
`l=256 r=752`; 1440: nav `l=99 r=305` / workspace `l=344 r=1360`), and `main` computes
`min-width: 0px` at both widths, which is the shrink behavior the contract requires.

Resizing across 375 → 768 → 1440 → 375 produced **0** network requests.

## Page Heading and Action Result — PASS

On all nine authenticated routes at all three widths, the page heading and its action group
were both fully within the viewport, with **no** heading/action overlap detected by
rectangle intersection and no horizontal clipping. `.section-heading` and `.topbar` carry
the shared `flex-wrap: wrap` contract, so action groups wrap into a usable stacked group at
375 px instead of extending past the viewport. Representative routes with actions
(Users, Tenants, Runtime Nodes, Runtime Operations, Audit Records) all passed. DOM and
keyboard order were preserved after wrapping (see focus section).

## Filter and Form Result — PASS

Measured on Users, Runtime Operations, Runtime Reconciliations, and Audit Records. Audit
Records is reported in full as the densest case (nine filter inputs plus Apply and Clear):

| Property | 375 × 812 | 768 × 1024 |
|---|---|---|
| `.ui-filter-bar__controls` grid | `317px` (1 column) | `225px 225px` (2 columns) |
| Filter bar box | `l=29 r=346` within | `l=273 r=735` within |
| All 9 inputs within viewport | yes | yes |
| All 9 labels carry `for` | yes | yes |
| Apply / Clear reachable | `l=29 r=100` / `l=112 r=180` | `l=584 r=656` / `l=668 r=735` |

Date and identifier inputs matched their column width exactly and never exceeded their
container. Applying one safe filter (`actor_type=user`) issued exactly **one** canonical
request and left the document width unchanged (`root=375`); clearing it issued exactly one
request and also left width unchanged. The completed filter request-budget proof was not
repeated.

## Long-Identifier Result — PASS

259 long values (≥ 24 characters, including UUIDs, correlation and request IDs, operation,
reconciliation, node, actor, and subject identifiers) were measured at 375 px across
Runtime Nodes (124), Runtime Operations (41), Runtime Reconciliations (41), and Audit
Records (53).

- All 259 remained inside the viewport.
- `overflow-wrap: anywhere` and `white-space: normal` applied to every one.
- **0** were clipped (`scrollWidth > clientWidth` was false for all).
- **0** had `user-select: none`, so copyability is preserved.
- No identifier is hidden solely through clipping.

Longest observed value: an 85-character RuntimeNode label
(`c4-worker-restart-proof-workerrestart1784071…`) rendered at `l=42 r=333 w=291` inside a
375 px viewport — wrapped, fully visible, unclipped.

## Pagination Result — PASS

Audit Records (143 pages) at 375 px: pagination box `l=12 r=363` within viewport; Previous
(`l=12 r=106`), Next (`l=220 r=284`), and the page-size select (`l=12 r=363`, labelled
"Rows per page") all within; page information "Page 1 of 143" readable; **0** overlapping
control pairs; and the pagination region is **not** nested inside any clipped or
horizontally scrolling ancestor. At 768 px the same controls remain within
(`l=288…r=752`). One normal page change advanced to `?page=2` with exactly **one**
canonical request, left `root=375` unchanged, and kept every control within bounds;
returning to page 1 restored the initial state. Pagination duplicate-activation testing was
not repeated.

## List and Local-Overflow Result — PASS

A document-wide scan for elements with `overflow-x: auto|scroll` **and**
`scrollWidth > clientWidth` returned **zero** local horizontal-scroll regions on every
route at every viewport. The stable routes therefore use bounded data-list rows rather than
document-wide tables, exactly as UI-E7 predicted, and no bounded list was misclassified as
requiring local scrolling. No list widened the document on any route. Because no local
overflow region exists in the current route set, the "wide table scrolls only inside an
explicit local wrapper" rule is satisfied vacuously — the permitted-but-unused branch —
and pagination and route actions are consequently never trapped inside inaccessible
overflow.

## Detail-Panel Result — PASS

| Route | 375 grid | 768 grid | Panel within | Longest `dd` within | Width changed on open | Open requests |
|---|---|---|---|---|---|---|
| Audit Records | `291px` (1 col) | `190.8px 233.2px` (2 col) | yes | yes | no | 1 |
| Runtime Operations | `291px` | `190.8px 233.2px` | yes | yes (18 `dd`) | no | 1 |
| Runtime Reconciliations | `291px` | `190.8px 233.2px` | yes | yes (14 `dd`) | no | 1 |
| Runtime Nodes | `subgrid` `291px` | bounded | yes | yes | no | ≤ 3 |

Detail grids collapse coherently to one column at 375 px and to the bounded
`minmax(0, …)` two-column contract at 768 px. All metadata wrapped; every `dd` stayed
within the viewport. Opening a panel never widened the document. Audit Records exposes an
explicit `Close` control (`l=59 r=316` at 375, within), and closing it returned focus to
the exact originating `Details` trigger with **0** list and **0** detail requests. Runtime
Nodes closes via `Hide details` and Runtime Operations / Reconciliations use a persistent
`Selected` row-toggle rather than a dismissible panel; both are established selection
models, not responsive defects. The full detail-focus proof was not repeated.

## Status, Empty, Error, and Loading Result — PASS

- **Status badges:** 40 text-bearing badges measured at 375 px; all within the viewport,
  all carrying text (not colour-only). Rectangle intersection reported 20 overlapping
  pairs, and inspection confirmed **all 20 are `.badge-row` containers overlapping their
  own `.ui-status-badge` children** — nesting, not visual overlap. Non-nested overlaps: **0**.
- **Empty state:** a safe non-matching actor filter produced "No Audit records", rendered
  within the viewport with `root=375`, `overflow-x: visible`, 0 rows, and exactly 1 request.
- **Loading state:** one canonical list request was held by bounded proof-only interception.
  Mid-flight the document stayed at `root=375` with `overflow-x: visible` on root and body;
  `Refresh` exposed `aria-busy="true"` and `aria-disabled="true"` while remaining natively
  enabled, and boundary pagination controls kept their native `disabled`. After release, 20
  rows returned and `aria-busy` cleared to 0. Loading indicators never altered page width.
- **Validation error:** the Login panel's `role=alert` region was exercised on the real
  Login page and stayed inside the panel (see Login result).

No destructive failure was manufactured and no backend data was altered. States not safely
reachable live remain covered by the automated suite.

## Login Responsive Result — PASS

| Viewport | root ≤ innerWidth | root/body overflow-x |
|---|---|---|
| 375 × 812 | 375 ≤ 375 | visible / visible |
| 768 × 1024 | 768 ≤ 768 | visible / visible |
| 1440 × 900 | 1440 ≤ 1440 | visible / visible |

At 375 px the auth panel measures `l=12 r=363` (351 of 375), the heading `l=29 r=241`, and
the Email, Password, and Submit controls all `l=29 r=346` — every element inside the
viewport with no fixed width forcing zoom or horizontal scrolling. Heading and explanatory
text wrap. The status/validation region renders inside the panel. Only the normal
authentication interaction was performed; invalid credentials were not submitted
repeatedly.

## Browser Axe Sanity

Real-browser axe-core (colour-contrast included, no rule groups disabled) on four
representative responsive states:

| State | critical | serious | moderate | minor |
|---|---|---|---|---|
| AppShell 375 dark, mobile nav open, pointer at rest | 0 | 0 | 0 | 0 |
| Audit Records 375 dark (filter-heavy) | 0 | 0 | 0 | 0 |
| Runtime Operations 375 light, detail open | 0 | **1** | 0 | 0 |
| Users 768 light (list-heavy) | 0 | 0 | 0 | 0 |
| AppShell 375 dark, nav open, **pointer hovering `Menu`** | 0 | **1** | 0 | 0 |

Both serious findings are the **same** `color-contrast` violation on
`.ui-button--secondary > span`, and both occur **only while a pointer hovers a secondary
button**. Moving the pointer away and re-running axe on the identical DOM returned
**0 violations**, confirming the resting and keyboard-reachable states are clean. See
`PRODUCT_DEFECT-4`.

## Focus Visibility and Obstruction — PASS

Real `Tab` traversal on Audit Records: 34 controls at 375 px and 29 at 768 px.

- `:focus-visible` matched on **every** control at both widths.
- Outline width was a uniform `3px` on all of them.
- **0** controls were horizontally outside the viewport.
- **0** controls were obscured — verified with `elementFromPoint` at each focused
  control's centre, so nothing hides behind fixed navigation or headers.
- **0** controls were clipped by an `overflow: hidden|clip` ancestor.
- Tab order followed DOM order after wrapping; at 768 px the sequence was tenant →
  Appearance → Log out → the nine nav links → Refresh → filters → Apply → Clear →
  Details rows, with no visual reordering.
- Tabbing produced **0** network requests at both widths.

No focus behavior was added or modified during the proof.

## Console and Page-Error Result — PASS

- Unexpected console errors: **0**
- Console warnings: **0**
- Page errors during measurement: **0**
- Failed requests: **0**
- Unexpected 4xx/5xx responses: **0**

The only console error in the entire session was the established pre-login
`401 /api/v1/auth/session` probe (recorded as `EXPECTED_BEHAVIOR`, consistent with UI-E2,
UI-E4, and UI-E6). A cluster of `TypeError: Cannot read properties of undefined` entries
appeared **after all measurements were complete**, caused by this proof's own cleanup step
deleting the `window.__utcp` observer object while its event listeners were still attached.
These are proof-instrumentation artifacts, not application defects; a reload cleared them
and the fresh document showed only the expected 401 probe. Recorded as
`PROOF_LIMITATION-1`.

## Network-Hygiene Result — PASS

- Viewport resizing across all three classes: **0** requests.
- Theme changes (System → Light → Dark → System): **0** requests each.
- Focus movement / full Tab traversal: **0** requests.
- Opening and closing the mobile navigation: **0** unexpected requests.
- One explicit filter Apply: exactly **1** canonical request. One Clear: exactly **1**.
  One page change: exactly **1**. One detail open: exactly **1**. Detail close: **0**.

Detailed UI-D request budgets were not repeated.

## Storage Boundary — PASS

Inspected before Login, after Login, after tenant selection, after mobile navigation, after
viewport changes, after route navigation, after detail interaction, after theme changes,
and after logout.

```text
localStorage:   utcp.appearance, pusherTransportTLS
sessionStorage: (empty)
cookies:        XSRF-TOKEN, unified-telephony-control-plane-api-session
```

A forbidden-substring scan over the full storage blob for `viewport`, `innerwidth`,
`mobile`, `menu`, `nav-open`, `responsive`, `breakpoint`, `tenant`, `capabilit`, `audit`,
`runtime_node`, `runtime-node`, `correlation`, `focus`, `admin@utcp`, `password`,
`session_id`, `per_page`, and `page=` returned **no hits**. No viewport state, mobile-menu
state, responsive-layout state, domain record, selected identifier, tenant or capability
data, focus target, or test evidence entered persistent browser storage. The two cookies
are the expected first-party Laravel session pair. After logout, storage retained only
`utcp.appearance` (`system`) and `pusherTransportTLS`, and **0** protected requests were
issued.

## Product Defects

### PRODUCT_DEFECT-4 — Secondary and ghost button hover states fail colour contrast

- **Classification:** `PRODUCT_DEFECT` — pre-existing, **not** a responsive-layout defect,
  and **not** introduced by `b53fb9e`.
- **Route / viewport / theme:** reproduced on Dashboard (375 × 812, Dark, mobile nav open)
  and Runtime Operations (375 × 812, Light, detail open); the rule is global, so it applies
  at every viewport and on every route that renders a secondary or ghost button.
- **Exact selector:** `button:hover:not(:disabled), .ui-button:hover:not(:disabled)` in
  `apps/web/src/style.css:204-207`, as it applies to `.ui-button--secondary`
  (`apps/web/src/style.css:218-221`) and `.ui-button--ghost` (`apps/web/src/style.css:229`).
  Failing nodes reported by axe: `.ui-button--secondary > span`.
- **Measured evidence** (computed styles and bounding boxes):

  | Variant | Dark contrast on hover | Light contrast on hover | Verdict |
  |---|---|---|---|
  | default / primary | 8.81 : 1 | 10.68 : 1 | pass |
  | danger | 8.81 : 1 | 10.68 : 1 | pass |
  | **secondary** | **1.55 : 1** | **1.65 : 1** | fail |
  | **ghost** | **1.23 : 1** | **1.38 : 1** | fail |

  Dark `Menu` node: foreground `#edf3fa` on hover background `#a8c7fa`, 16 px / weight 700,
  box `l=25 t=217 w=46 h=22`, axe-reported ratio 1.53 : 1. Light `Selected` node:
  foreground `#111827` on hover background `#173f71`, 16 px / weight 700, box
  `l=55 t=395 w=67 h=22`, axe-reported ratio 1.67 : 1. Expected ratio 4.5 : 1.
- **Overflow context (unchanged by the defect):** `innerWidth` 375; root / body / `#app` /
  `main` `scrollWidth` 375 / 375 / 375 / 351; root and body `overflow-x: visible`.
- **Expected behavior:** a hovered secondary or ghost button keeps a text/background
  contrast ratio of at least 4.5 : 1 in both themes.
- **Actual behavior:** the shared hover rule replaces `background` with
  `var(--color-primary-hover)` but never sets a matching `color`. Variants that override
  `color` (secondary, ghost) therefore keep their resting foreground on the primary hover
  background, collapsing contrast to 1.23–1.65 : 1. Resting state is unaffected
  (`Menu` at rest: `#edf3fa` on `rgb(29,45,61)`), which is why keyboard-driven proofs did
  not surface it.
- **Smallest bounded correction seam:** give the shared hover rule an accompanying
  foreground (`color: var(--color-surface)`) so it stays paired with the hover background,
  or scope hover backgrounds per variant so `.ui-button--secondary` and
  `.ui-button--ghost` receive hover treatments that preserve their own foreground. One
  bounded change in `apps/web/src/style.css:204-207` covers both variants and both themes.
- **Provenance:** `git log -L 204,207:apps/web/src/style.css` attributes the rule to
  `3b643d0` (`feat(ui): add design tokens themes and core components`), well before
  `b53fb9e`. The `b53fb9e` CSS diff contains no `color`, `background`, or `:hover`
  declaration. UI-E2 did not detect it because that proof drove the UI by keyboard, leaving
  no pointer resting on a secondary button.
- **Impact on this slice:** none of the responsive-layout claims are affected. This defect
  alone prevents completion criterion 16 from being satisfied.

No responsive-layout product defect was found.

## Proof Limitations

### PROOF_LIMITATION-1 — Observer-teardown page errors

The cleanup step deleted `window.__utcp` while its `focusin`, `error`, and
`unhandledrejection` listeners were still attached, producing 13
`TypeError: Cannot read properties of undefined` page errors **after** all measurements had
completed. These are artifacts of this proof's instrumentation, not application behavior; a
reload cleared them and the fresh document raised only the expected pre-login 401. No
measured result in this document was taken while those errors were present.

### PROOF_LIMITATION-2 — Login theme coverage

The appearance control is only available after authentication, so the Login route was
proven under the actual unauthenticated theme behavior at all three viewports rather than
in explicit Light and Dark. This matches the contract's allowance for unauthenticated
theme behavior.

### PROOF_LIMITATION-3 — Local table overflow branch unexercised

No route in the current stable set renders a genuinely wide table, so zero local
horizontal-scroll regions exist. The "local overflow only inside an explicit wrapper" rule
is therefore satisfied vacuously; touch-scrolling and scroll-into-view behavior of such a
region could not be exercised live and remain covered only by the repository contract.

## Divergences

- **Environmental:** the cluster was stopped at task start and required a canonical k3d
  restart, one stuck `coredns` Pod deletion, and one canonically rendered NetworkPolicy
  apply. Classified as environmental issues, not application defects; none invalidate the
  principal claim.
- **Environmental:** `kubectl exec` into `postgres-0` remains blocked by the known stale
  kubelet serving-certificate SAN on the server node. Worked around through the canonical
  API Pod without restarting anything.
- **Expected behavior:** the pre-login `401 /api/v1/auth/session` probe.
- **Proof artifact:** `PROOF_LIMITATION-1`.

None of these invalidate the responsive-layout claim.

## Cleanup

Request interceptions released; route filters cleared; pagination returned to page 1;
detail panels closed; mobile navigation closed; appearance reset to `System`; desktop
viewport restored before logout; browser context logged out and closed; injected axe-core
removed (`typeof window.axe === 'undefined'` verified); observers removed;
`.playwright-mcp/` deleted; no credentials or scratch files retained; no port-forwards
started. Web remains healthy on the `b53fb9e` image (`login=200`), preserved workloads were
not restarted, and the API-server policy drift check passes.

## Remaining UI-E Portfolio-Finish Work

- One bounded correction for `PRODUCT_DEFECT-4` (shared button hover contrast).
- The final bounded portfolio information-architecture and visual-finish slice.
- The deferred `UiFilterBar` Apply busy-state gap recorded by UI-E6.
