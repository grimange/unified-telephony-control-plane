# UI Foundation A–E Roadmap Reconciliation Audit (UI-R0)

Verdict: `UI_FOUNDATIONS_A_TO_E_CONTRACT_DEFINED`

Audit type: narrow evidence audit (documentation-first, evidence-only).
Starting commit: `f959f00`. Phase marker: `UTCP_PHASE=T1` (unchanged).
This is a horizontal UI track. It is **not** part of T5 convergence/failover/recovery or Namespace
security work, and does not reopen them.

---

## 1. Prior Foundation A–E evidence: recovered vs. proposed

**Finding: no authoritative "Foundation A through E" or "UI-A through UI-E" definitions exist anywhere in
the repository working tree or its full Git history.**

Searches performed:

- `grep -rniE "foundation [a-e]\b|ui foundation|ui-[a-e]\b|frontend foundation"` over `docs/`, `web/`,
  `apps/web/`, READMEs → no matches.
- `git log --all -i --grep=` for `foundation`, `ui roadmap`, `frontend roadmap`, `ui-foundation`,
  `foundation a` → no commits.
- `git log --all -iS"Foundation A"` / `"Foundation B"` / `"UI Foundation"` → the only pickaxe hits
  (`a15119d`, `19d7c08`) are **substring coincidences** ("…Foundation. A…" / "Container Build
  Foundation"). `git grep "Foundation A"` in those commits returns nothing literal.
- Added-file history (`git log --all --diff-filter=A --name-only`) filtered for
  `ui|frontend|foundation|design-system|shell` → only unrelated files
  (`create_kamailio_registrar_foundation.php`, `ADR-012-kubernetes-observability-foundation.md`,
  `f2/container-build-foundation.md`, `k0/local-k3d-cluster-foundation.md`).
- `docs/roadmap/` contains only `implementation-roadmap.md` and `phase-status.md`. No
  `ui-foundations.md`, no design-system doc, no UI ADR (ADR-004 is Traefik web *ingress*, not UI). No
  `docs/evidence/ui/` existed before this audit.

The repository's own roadmap vocabulary is phase codes (`F/K/C/T/V/R`) plus informal development
"corridors" (`T1-A`…`T1-F`, explicitly documented as "not a separate phase-numbering scheme"). The
phrase "Foundation A through E / UI-A through UI-E" originates **only** from the preceding task's
recommended-next-step, which this task inherits.

**Consequence:** everything in §7 below is a **new evidence-backed proposal**, clearly marked as such. No
title here is presented as a previously approved decision. The proposal is derived from the current UTCP
architecture (`docs/architecture/overview.md`, `authority-boundaries.md`, ADR-014/015/016/017) and the
current frontend implementation and its gaps.

---

## 2. Pre-existing working-tree modification (preserved, untouched)

`git status --short` → ` M docs/roadmap/phase-status.md` (single pre-existing change, present before this
task).

Classification: **cosmetic Markdown table-alignment only.**

- `git diff --stat` → 27 insertions / 27 deletions.
- `git diff --ignore-all-space --stat` → **1 insertion / 1 deletion**: only the header separator row,
  whose Status-column dashes widen from `----------` to `-------------`. Every other changed line is
  pure cell-padding realignment. No `Phase`, `Status`, or `Notes` value changes.

This file was **not modified** by this audit. Its exact semantic content is unchanged from HEAD; the only
delta is table padding. The downstream documentation step must preserve this (there is no semantic content
to lose) and may reconcile the cosmetic padding intentionally when it adds the UI rows.

---

## 3. Current frontend architecture (`apps/web`)

Stack: Vue 3 (`^3.5.39`) + Vite + TypeScript + Vitest + ESLint. **The only runtime dependency is `vue`.**
There is **no** `vue-router`, **no** state-management library (Pinia/Vuex), **no** component library
(Vuetify/PrimeVue/Element/Naive/Quasar), **no** icon library, **no** table/form library, **no** charting
library, **no** accessibility-primitive library, and **no** real-time client (`laravel-echo`/`pusher-js`/
`WebSocket`).

Source tree (`apps/web/src`, 2909 LOC total):

| File | LOC | Role |
| --- | --- | --- |
| `App.vue` | 1572 | Monolithic single-file application: homemade router, shell, and every admin screen |
| `api/platform.ts` | 496 | Typed `fetch`-based API client (health, identity session, admin resources, catalogs) |
| `App.test.ts` | 580 | Component tests for the shell/admin flows |
| `components/PlatformStatusPage.vue` | 125 | Health/version status page |
| `components/PlatformStatusPage.test.ts` | 120 | Status-page tests |
| `style.css` | 390 | Single global stylesheet |
| `buildInfo.ts` / `main.ts` | 16 | Build metadata + bootstrap |

Key mechanisms:

- **Router:** hand-rolled. `route = ref(window.location.pathname)`; `go()` uses `history.pushState`;
  a `popstate` listener (App.vue:1547) handles the back button; `/admin/users/:id` matched by regex.
  No route-level view components — all routes are `v-if` branches inside `App.vue`.
- **API client:** native `fetch` with `credentials: 'same-origin'` (first-party Laravel session cookies),
  a cached CSRF token fetched from `/api/v1/auth/csrf`, JSON headers, and a typed `ApiRequestError`
  (status + details). Clean, consistent conventions.
- **Styling:** one `:root` block sets base color/background/font only — **no `--` token scale, no spacing
  system, and no `prefers-color-scheme` dark mode.** One responsive breakpoint (`@media max-width: 720px`).
  All styling is per-class in the single 390-line file.
- **State:** local `ref`/`reactive` inside `App.vue`; no store abstraction.

---

## 4. Existing UI feature inventory

Implemented routes (all inside `App.vue`):

| Route | Implemented behavior |
| --- | --- |
| `/login` | Natural login form (email/password), no client-side tokens |
| `/change-password` | Forced/again password change (honors `password_change_required`) |
| `/admin/tenants` | Tenant list + capability-gated create/manage |
| `/admin/memberships` | Membership list/management |
| `/admin/users` + `/admin/users/:id` | User list with canonical pagination; user detail: roles, effective capabilities, **active TelephonySession** visibility + canonical termination, **signaling registration** panel with one-time credential issuance and transient-secret handling |
| `/admin/runtime-nodes` | RuntimeNode list; declared/settable capabilities; **asterisk-ari** adapter-configuration form |

Cross-cutting behavior present: capability-gated navigation and actions (`can()`), active-tenant selector
and switching, logout, session-reject → login redirect, ARIA labelling (`aria-labelledby`), loading/busy
and error text, pagination, and pending/converged-removal wording for sessions.

**Absent:** Conference views; audit/operation-status views; any real-time/streaming view; a shared table
or form component; a notification/toast system; a design-token/theming system; a component library; a
route-level view decomposition.

Tests: `web-test` = **16 passing** unit tests (2 files). Coverage includes: login without client tokens,
server-session capability gating, runtime-node admin without secret exposure, adapter-config submission
through canonical APIs, protected-page → login redirect, transient signaling secrets, pagination, and
session-removal wording. **No committed Playwright/e2e harness exists** — UI browser proof is done via
live Playwright MCP natural flows (the T1-E "natural browser acceptance" convention).

---

## 5. UI authority boundary (verified consumer-only)

The frontend is a **consumer of canonical APIs**, not an authority. Verified:

- **Authorization:** `can(cap)` = `session.value?.capabilities.includes(cap)` (App.vue:1082) — renders the
  server-computed capability array. The frontend never computes authorization.
- **Catalogs:** role and runtime catalogs come from versioned server endpoints
  (`/api/v1/admin/roles`, `/api/v1/admin/runtime-node-catalog`, each with `catalog_version`). Supported
  adapter capabilities are read from the server catalog (`runtimeCatalog…adapter_keys[...]`). **No
  checked-in frontend runtime/capability/role catalog.**
- **Auth/session:** first-party Laravel session (`credentials: 'same-origin'`) + CSRF token; no client
  token minting, no injected/preset session.
- **Secrets:** signaling credentials are shown once and kept transient; runtime credentials are
  write-only (tests assert no secret exposure).

**Only boundary seam found:** `App.vue:911` branches on `node.adapter_key === 'asterisk-ari'` to render an
Asterisk-specific adapter-configuration form. This is a vendor-specific *rendering* branch, not an
authority transfer — the form still submits through the canonical API and the server validates. It is a
candidate for catalog-driven form generation (tracked under UI-C), not a defect requiring correction here.

No client-side lifecycle authority, no duplicated capability mapping, and no Redis/Kubernetes/PBX
authority were found in the frontend.

---

## 6. Existing UI mapped to application phases; shared vs. domain UI

| Phase | Required UI | Implemented | Frontend tests | Natural browser proof | Gap |
| --- | --- | --- | --- | --- | --- |
| F1 minimal shell | Vue shell + health/version | Yes (shell, status page) | Yes | n/a | none material |
| C1 identity/tenancy/authz | Login, tenant switch, capability gating | Yes | Yes | T1-E (login) | polish only |
| C2 RuntimeNode mgmt | Node list + capabilities + adapter config | Yes (asterisk-ari) | Yes | partial | catalog-driven forms; non-Asterisk adapters |
| C5 Session/Conference domain | Session visibility + **Conference** | Session: yes; Conference: **no** | Session: yes | partial | **no Conference UI** |
| T1 user/access + signaling UI | User & Access Mgmt + signaling registration | Yes | Yes | T1-E | polish |
| T2 Conference execution visibility | Conference/bridge/participant views | **No** | No | No | **entirely missing** |
| T3 browser media | WebRTC/media UI | **No** (future) | No | No | gated by T3 runtime |
| V0 natural login→registration→admission | End-to-end operator path | Partial (login+signaling) | Partial | T1-E slice | gated by T3/V0 |
| C6/C7 call lifecycle & control | Call/leg/timeline + call-control UI | **No** (future) | No | No | gated by domain phases |
| R0 portfolio | Cohesive, accessible, polished UX | Partial | Partial | No | cross-cutting |

**Shared UI foundation** (reusable, domain-agnostic): application shell, routing, navigation, layout,
design tokens/components, data-interaction patterns (tables/forms/states/notifications), real-time
plumbing, and cross-cutting accessibility/testing/responsiveness. **Domain feature UI** (owned by C/T/V):
the specific Conference, Session, Call, and Trunk screens and their lifecycle semantics.

Boundary rule enforced by this contract: a UI foundation may be *depended on* by a domain phase, but
completing a UI foundation must never mark a domain phase complete, and one feature screen must never mark
a whole UI foundation complete.

---

## 7. Proposed Foundation UI-A – UI-E (new, evidence-backed)

Five non-overlapping foundations. Titles/scope derived from the architecture and the concrete gaps above.

### UI-A — Application Shell, Routing, and Navigation

- **Objective:** one canonical authenticated shell with a real router, capability-gated navigation, layout
  regions (header/nav/content/breadcrumbs), and session/tenant context — so every domain screen mounts as
  a composable route view rather than a branch of a monolith.
- **In scope:** adopt `vue-router`; decompose `App.vue` route branches into route-level view components +
  a shared `AppShell`/layout + nav + tenant-context/logout; guards that mirror server capabilities;
  back/forward and deep-link correctness.
- **Non-goals:** visual design tokens/components (UI-B); table/form/data patterns (UI-C); any specific
  domain screen; real-time streaming (UI-D).
- **Dependencies:** none (unblocks UI-C/UI-D screens).
- **Current evidence:** homemade `pathname` router + `popstate`, capability-gated nav, tenant switcher,
  session-reject redirect — all functional but inside one 1572-line `App.vue`.
- **Missing:** real router; route-level view decomposition; shared layout/breadcrumb components; router
  guard tests.
- **Test contract:** router navigation/guard/deep-link/redirect unit tests; all 16 existing tests preserved.
- **Browser-proof contract:** natural login → navigate between admin routes → back/forward → tenant switch;
  console/network error capture.
- **Completion criteria:** router adopted; every existing route is a discrete view under the shell;
  capability gating and redirects preserved; tests + one natural browser flow pass; no behavior regression.
- **Status recommendation:** **In Progress.**

### UI-B — Design System and Reusable Component Library

- **Objective:** a single styling authority: design tokens (color, type, spacing — light **and** dark) and
  a small set of accessible reusable primitives (button, input/select/textarea, form field + error,
  table, badge/status, dialog/panel, empty/loading blocks).
- **In scope:** token definitions; theme (incl. `prefers-color-scheme`); primitive components replacing
  ad-hoc markup; documented usage.
- **Non-goals:** routing (UI-A); workflow logic and data fetching (UI-C); adopting a heavy third-party
  admin template (explicitly rejected — see §11).
- **Dependencies:** UI-A recommended first (components mount inside the shell) but tokens can start in
  parallel.
- **Current evidence:** one `:root` base (color/font) + one breakpoint in a 390-line global stylesheet;
  ARIA labels already used.
- **Missing:** token scale; dark mode; every reusable component; styling consolidation.
- **Test contract:** component unit tests (render, props, a11y attributes, disabled/error states).
- **Browser-proof contract:** not required for component-unit work (documentation/unit evidence suffices);
  visual proof folded into UI-A/UI-C flows.
- **Completion criteria:** tokens + core primitives exist, are used by at least the existing screens,
  pass unit tests, and light/dark render correctly.
- **Status recommendation:** **In Progress** (thin base exists).

### UI-C — Data Interaction and Management Workflows

- **Objective:** shared, consistent patterns for list/table interaction (filter/sort/paginate), forms +
  validation + error presentation, the full state set (loading/empty/degraded/error/success), catalog-
  driven forms, and user notifications/toasts.
- **In scope:** a reusable data-table/list pattern; form + validation + `ApiRequestError` presentation;
  standardized state components; a notification system; **catalog-driven adapter-config forms** (removes
  the `asterisk-ari` branch at App.vue:911).
- **Non-goals:** the visual primitives themselves (UI-B); real-time streams (UI-D); domain lifecycle rules
  (owned by C/T/V APIs).
- **Dependencies:** UI-A (shell/views) + UI-B (primitives).
- **Current evidence:** working pagination, forms, `ApiRequestError` handling, capability-gated CRUD,
  catalog-driven capability selection.
- **Missing:** shared table/list abstraction (currently re-implemented per screen); shared validation/
  notification/toast system; consistent empty/degraded components; catalog-driven adapter forms.
- **Test contract:** table (sort/filter/page), form-validation/error, state-rendering, and notification
  unit tests.
- **Browser-proof contract:** natural flow exercising list → filter/paginate → create/edit → validation
  error → success on an existing admin resource.
- **Completion criteria:** at least the existing admin screens use the shared table+form+state+notification
  patterns; adapter-config form is catalog-driven; tests + one browser flow pass.
- **Status recommendation:** **In Progress.**

### UI-D — Real-Time Telephony Operational Experience

- **Objective:** live operational surfaces for TelephonySession/signaling, Conference (participants/bridge),
  runtime health, and audit/operation status — driven by Reverb/WebSocket **notifications only**, never as
  canonical authority (per `authority-boundaries.md`: Reverb is notification, not state).
- **In scope:** the real-time client (`laravel-echo`/Reverb) and reconnection/degraded handling; conference
  operational view; audit/operation-status view; live health indicators; server-truth reconciliation on
  every notification.
- **Non-goals:** WebRTC media capture/playback (T3 domain); call-control semantics (C6/C7); any client-side
  telephony state authority.
- **Dependencies:** UI-A, UI-C; **domain-gated** by C5 Conference domain, T2 execution visibility, and (for
  media) T3/V0. This foundation must not be forced to Complete while those domain phases are unproven.
- **Current evidence:** static (poll/refresh) TelephonySession + signaling-registration panels; runtime-node
  view. **No real-time client, no Conference view, no audit/operation view.**
- **Missing:** the entire real-time plumbing; Conference operational UI; audit/operation-status UI.
- **Test contract:** notification-handling unit tests (notification → refetch canonical state, no direct
  state authority); reconnection/degraded-state tests.
- **Browser-proof contract:** natural flow observing a live session/conference/health change reflected via
  notification then reconciled from the API; requires the relevant domain runtime to be available.
- **Completion criteria:** real-time client integrated as notifications-only; conference + audit/operation
  views live; reconciliation proven; tests + a domain-backed browser flow pass.
- **Status recommendation:** **In Progress** (only static session/signaling exists; real-time + conference
  absent and partly domain-gated).

### UI-E — Accessibility, Testing, Responsiveness, and Portfolio Quality

- **Objective:** the cross-cutting quality contract: accessibility (ARIA, keyboard, focus, contrast),
  responsive layouts across breakpoints, the frontend test discipline (unit + natural browser proof),
  console/network-error hygiene, and portfolio-grade cohesion.
- **In scope:** a11y audit + fixes and (optionally) automated a11y assertions; responsive coverage beyond
  the single breakpoint; a documented browser-proof convention (natural Playwright MCP flows); error/console
  discipline; portfolio polish pass.
- **Non-goals:** feature scope of UI-A–UI-D (this certifies quality *across* them, it does not build them).
- **Dependencies:** cross-cutting; proceeds continuously and finalizes last.
- **Current evidence:** ARIA labelling in place; 16 passing unit tests; no-secret-exposure tests;
  T1-E natural-login browser proofs; one responsive breakpoint.
- **Missing:** systematic a11y audit/automation; multi-breakpoint responsive coverage; a committed
  browser-proof convention doc; portfolio-cohesion pass.
- **Test contract:** a11y assertions (roles/labels/focus), responsive/render tests, and the natural
  browser-proof convention.
- **Browser-proof contract:** natural keyboard-only navigation + responsive checks across the shipped
  screens; console/network-error capture.
- **Completion criteria:** shipped screens meet the a11y + responsive + test + error-hygiene bar and read
  as one portfolio-grade product.
- **Status recommendation:** **In Progress.**

---

## 8. Current status matrix

| ID | Title | Status | Basis |
| --- | --- | --- | --- |
| UI-A | Application Shell, Routing, and Navigation | In Progress | Homemade router + shell exist; no real router, monolithic, no view decomposition |
| UI-B | Design System and Reusable Component Library | In Progress | Base styling only; no tokens/dark mode/components |
| UI-C | Data Interaction and Management Workflows | In Progress | Pagination/forms/errors exist; no shared table/notification/validation system |
| UI-D | Real-Time Telephony Operational Experience | In Progress | Static session/signaling only; no real-time client, no Conference/audit views; partly domain-gated |
| UI-E | Accessibility, Testing, Responsiveness, Portfolio | In Progress | ARIA + 16 tests + T1-E proof; no systematic a11y/responsive/browser-proof convention |

None qualifies for **Complete** (none satisfies the full shared-implementation + tests + natural browser
proof + a11y/error-state + current-docs bar). None is **Blocked** (all can progress now, though UI-D's
Conference/media completion is domain-gated). None is purely **Planned** (each has a real starting base).

---

## 9. Dependency and sequencing contract

Deterministic order:

```text
UI-A  (shell + real router + view decomposition)      ← foundational blocker
  └─ UI-B  (tokens + reusable components)              ← can start in parallel; consumed by UI-C
       └─ UI-C  (tables/forms/states/notifications)    ← needs UI-A views + UI-B primitives
            └─ UI-D  (real-time + conference/audit)    ← needs UI-C; domain-gated by C5/T2/T3/V0
UI-E  (a11y/responsive/testing/portfolio)              ← cross-cutting; continuous; finalizes last
```

- **Independent starters:** UI-A and UI-B (tokens) may proceed immediately with existing APIs.
- **Gating:** UI-A gates clean UI-C/UI-D screens; UI-C gates polished UI-D; UI-D completion is gated by
  domain phases and must not be forced Complete ahead of them.
- **Interleaving with the phase roadmap:** UI-A/B/C/E are independent of T3/V0/T4 and can advance now.
  UI-D's Conference/audit views ride on C5/T2 (available) while its media/end-to-end completion rides on
  T3/V0; FreeSWITCH parity (T4) reuses the same normalized screens (no runtime-specific UI). Call lifecycle
  (C6/C7) and external-trunk (C8) will add domain screens *on top of* UI-A–UI-C, not inside the UI track.
- **Non-negotiable:** no backend/domain work moves into the UI track; unrelated runtime-correctness work
  (e.g. remaining T5 items) is not delayed for UI polish.

---

## 10. Documentation authority

| Document | Authoritative for | Action |
| --- | --- | --- |
| `docs/roadmap/ui-foundations.md` (**new**) | UI-A–UI-E scope, non-goals, dependencies, acceptance + browser-proof criteria | create (Codex step) |
| `docs/roadmap/implementation-roadmap.md` | Overall sequencing; how the UI track interleaves with F/K/C/T/V/R | add a "UI Foundation Track" section (Codex step) |
| `docs/roadmap/phase-status.md` | Concise status ledger | add UI-A–UI-E rows (Codex step) |
| `docs/evidence/ui/` | UI implementation + live-proof evidence | this audit; future proofs |

Rules: acceptance criteria live **only** in `ui-foundations.md` (not duplicated into `phase-status.md`);
each foundation is defined once; `phase-status.md` carries status + a one-line note only.

---

## 11. Open-source UI dependency assessment

| Concern | Current state | Classification |
| --- | --- | --- |
| Framework | Vue 3 + Vite + TS | **current authoritative dependency** (preserve) |
| HTTP client | native `fetch` + CSRF + same-origin session | **current authoritative** (preserve) |
| Router | homemade `pathname`/`pushState` | **candidate → adopt `vue-router`** (official, preserves stack) — UI-A |
| State management | local `ref`/`reactive` | candidate (Pinia) only if complexity grows; not yet needed |
| Component library | none | **candidate requiring later decision** (lightweight/headless only) |
| Admin template | none | **do not adopt** a heavy template (would duplicate routing/auth/state; conflicts with §5 boundary) |
| Headless component framework | none | candidate (e.g. headless a11y primitives) — UI-B |
| Icon library | none | candidate — UI-B |
| Table/form library | none | candidate — UI-C (or build minimal in-house) |
| Charting library | none | candidate — relevant to observability/portfolio (UI-D/UI-E) |
| Accessibility primitives | none (manual ARIA) | candidate — UI-E |

Constraint: any future adoption must preserve the existing Vue app and backend authority — no duplicate
routing/auth/state/styling authority, no runtime-specific business logic pulled into a template. No
stack replacement is recommended for visual preference.

---

## 12. Browser-proof contract (for UI foundations requiring it)

Where browser proof is required (UI-A shell/routing, UI-C workflows, UI-D live views, UI-E a11y/responsive),
use **natural Playwright MCP flows** that:

1. begin at the real login page; 2. authenticate normally (no preset/injected/DB/Redis-created sessions,
no cookie injection); 3. select the tenant through the normal path; 4. use server-returned capabilities;
5. navigate actual UI routes; 6. exercise loading/success/empty/error/authorization behavior where
applicable; 7. record console and relevant network errors; 8. expose no credentials.

Browser proof is **not** required for documentation-only work or component-unit work (UI-B primitives),
where unit evidence is sufficient. **No live browser proof was run in this audit** (roadmap-audit scope).

---

## 13. First bounded UI implementation target

**UI-A first** — adopt `vue-router` and decompose `App.vue` into route-level view components behind a
shared `AppShell`, preserving all current behavior, capability gating, redirects, and the 16 existing tests.

Rationale (this is the *actual* shared blocker, not an alphabetical default): every future domain screen
(Conference, audit/operation status, call lifecycle, trunk management) today must be appended to a
1572-line monolith behind a homemade router. Fixing that unblocks all of UI-C/UI-D and every later domain
UI, is fully testable with existing APIs (no T3/V0 needed), adds only the official `vue-router` (preserving
the stack), and has crisp acceptance criteria. UI-A is explicitly **not** substantially complete, so
choosing it is evidence-driven.

Acceptance for the first target: `vue-router` adopted; each existing route (`/login`, `/change-password`,
`/admin/tenants`, `/admin/memberships`, `/admin/users`, `/admin/users/:id`, `/admin/runtime-nodes`) is a
discrete view under a shared shell; capability gating + session-reject redirect + back/forward preserved;
router guard/navigation unit tests added; all existing tests still pass; one natural browser flow (login →
navigate → tenant switch → back) recorded.

---

## 14. Implementation-readiness decision

**bounded Codex documentation reconciliation.** The audit establishes exact titles/scope (§7), current
implementation mapped to each (§3–§6), status (§8), dependency order (§9), acceptance + browser-proof
criteria (§7, §12), documentation hierarchy (§10), exact files to update (§10), the treatment of the
pre-existing `phase-status.md` change (§2, §15), and the first bounded implementation target (§13).

---

## 15. Ready-to-paste Codex documentation-reconciliation prompt

```text
# UI-R1 — Reconcile UI Foundation A–E into the roadmap documentation

Documentation-only task. Do not modify frontend code, backend code, Kubernetes manifests, or tests.
Base evidence: docs/evidence/ui/ui-foundations-roadmap-audit.md (authoritative for scope/status).

Starting state: branch main; UTCP_PHASE=T1 (do not change); the working tree already contains one
pre-existing cosmetic modification to docs/roadmap/phase-status.md (Markdown table-alignment only, no
semantic change). Preserve it — do not reset, discard, or overwrite user-authored content.

Deliverables (three roadmap docs, one coherent commit):

1. CREATE docs/roadmap/ui-foundations.md — the authoritative UI-A–UI-E definition:
   - Title each foundation exactly as in the audit §7 (UI-A Application Shell/Routing/Navigation;
     UI-B Design System & Reusable Components; UI-C Data Interaction & Management Workflows;
     UI-D Real-Time Telephony Operational Experience; UI-E Accessibility/Testing/Responsiveness/Portfolio).
   - For each: objective, in-scope, non-goals, dependencies, current evidence, missing work, test contract,
     browser-proof contract, completion criteria, status (all In Progress per §8).
   - State plainly that these are a new evidence-backed proposal (no prior authoritative Foundation A–E
     existed in the repo/history), and include the dependency/sequencing contract (§9) and the
     documentation-authority table (§10).
   - Acceptance criteria live ONLY here.

2. EDIT docs/roadmap/implementation-roadmap.md — add a "UI Foundation Track" section: the five IDs+titles,
   the sequencing (UI-A → UI-B → UI-C → UI-D, UI-E cross-cutting), and how the track interleaves with
   C5/T2/T3/V0/T4/C6/C7/C8/R0 WITHOUT moving domain work into the UI track. Reference ui-foundations.md as
   authoritative for scope/acceptance; do not duplicate acceptance criteria here.

3. EDIT docs/roadmap/phase-status.md — this file is already modified (cosmetic table alignment):
   a. Preserve the pre-existing semantic content (there is none to lose; only padding changed).
   b. Intentionally reconcile the cosmetic table formatting (consistent column widths) as part of this edit.
   c. Add one row per UI-A..UI-E with Status "In Progress" and a one-line note pointing to ui-foundations.md
      (status ledger only — no acceptance criteria).
   d. Do not silently discard the user-authored alignment change; fold it into this intentional edit.
   e. Keep all existing phase rows and their statuses unchanged (T2 Complete, T5 In Progress, UTCP_PHASE=T1).

Verification: make repository-hygiene; make workflow-check; make secret-scan; make check; git diff --check.
Commit exactly one: "docs(ui): reconcile UI Foundation A–E into roadmap". Do not push or tag.
Do not implement any UI foundation in this task. The first implementation target (UI-A: adopt vue-router +
decompose App.vue into route views behind a shared shell) is a SEPARATE subsequent task.
```

---

## 16. Verification performed (this audit)

Commands run (all passed): `make repository-hygiene`, `make workflow-check`, `make secret-scan`,
`make api-check` (`pint` passed), `make api-test` (338 passed, 2 skipped), web `npm run typecheck` (clean),
web `npm run lint` (`--max-warnings=0`, clean), web `npm run test` (16 passed), web `npm run build`
(succeeded: `dist/assets/index-*.js` 100.22 kB / gzip 34.14 kB), `git diff --check`, `git diff --cached
--check` (both clean). `make test`/`make check`/`make build` are compositions of the above and are covered.
No live browser proof and no runtime-environment changes were performed (audit scope).

Hosted CI proof: not observed.
