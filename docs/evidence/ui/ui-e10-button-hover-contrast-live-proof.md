# UI-E10 - Button Hover Contrast Live Proof

Verdict: `UI_E_BUTTON_HOVER_CONTRAST_LIVE_PROOF_COMPLETE`

UI-E10 is a focused evidence-only natural browser live proof of the UI-E9
correction. It closes `PRODUCT_DEFECT-4` recorded by UI-E8 and therefore closes
the UI-E responsive live-proof corridor. No production code, backend authority,
or infrastructure authority was changed. UI-E remains In Progress and
`UTCP_PHASE=T1` remains unchanged.

## Starting Point

- Branch: `main`
- Starting HEAD: `7eae240` (`fix(ui): correct button hover contrast`)
- Starting working tree: clean
- Phase marker: `UTCP_PHASE=T1`
- Push status: not pushed

Evidence authority:

- [`ui-e8-responsive-contract-live-proof.md`](ui-e8-responsive-contract-live-proof.md)
  recorded `PRODUCT_DEFECT-4` (secondary and ghost pointer-hover contrast failure).
- [`ui-e9-button-hover-contrast-correction.md`](ui-e9-button-hover-contrast-correction.md)
  removed the shared hover authority and added variant-owned hover color pairs.

## Repository Baseline Confirmed

Confirmed in `apps/web/src/style.css` at `7eae240`:

- The conflicting broad `.ui-button:hover:not(:disabled)` color authority is
  absent. The generic native rule is scoped by `:not(.ui-button)`.
- Each variant owns its hover foreground and background
  (`.ui-button--primary|secondary|danger|ghost:hover`).
- Every hover selector excludes `:disabled` and `[aria-disabled='true']`, so
  loading controls are excluded from active hover styling.
- Explicit disabled styling remains native (`button:disabled, .ui-button:disabled`).
- The `focus-visible` ring remains independent, painted by `box-shadow: var(--focus-ring)`.

## Web Image Built and Rolled Out

Built web-only from clean `7eae240` using the canonical
`infrastructure/docker/web/Dockerfile` `app-prod` target and the canonical
build arguments, including the established application, WSS, and public Reverb
coordinates. Only the public Reverb application key entered the bundle.

| Property | Value |
|---|---|
| Registry manifest digest | `sha256:dce1a124f855328a45ae1778c30afde16bb66bb2f46cee06a24c0c635a5fa2a4` |
| Running Pod `imageID` digest | `sha256:dce1a124f855328a45ae1778c30afde16bb66bb2f46cee06a24c0c635a5fa2a4` |
| `org.opencontainers.image.revision` | `7eae240` |
| `org.opencontainers.image.created` | `2026-07-27T22:16:30Z` |
| `org.opencontainers.image.version` | `0.1.0-dev` |
| Build provenance | local `docker build`, `IMAGE_SOURCE=local` |
| Web Pod | `web-6b8fbf9844-7tm5v`, created `2026-07-27T22:17:04Z` |

Only `deployment/web` was restarted (`imagePullPolicy: Always`).

Served-bundle confirmation (`/assets/index-ChybOFLD.css`) independently proves the
deployed CSS carries the correction:

- `button:hover:...:not(.ui-button), .ui-button--primary:hover:...` sets `color: var(--color-surface)`.
- `.ui-button--secondary:hover:...` → `background: var(--color-primary-muted); color: var(--color-text)`.
- `.ui-button--ghost:hover:...` → `background: var(--color-primary); color: var(--color-surface)`.
- `.ui-button--danger:hover:...` → `background: var(--color-danger); color: var(--color-surface)`.
- The old broad `.ui-button:hover:not(:disabled){` rule is absent (0 occurrences).

## Preserved Workloads

Not rebuilt and not restarted; every Pod predates the web rollout:

`api`, `gateway`, `asterisk-ari-events`, `control-plane-outbox-dispatcher`,
`kamailio`, `kamailio-registration-observer` (2), `reverb`, `scheduler`,
`simulator-event-source`, `telephony-command-worker`,
`telephony-event-normalizer`, `telephony-reconciler`,
`utcp-runtime-fence-worker`, `worker`, plus PostgreSQL, Redis, Traefik, and
observability.

## Live Baseline

- Context `k3d-utcp-local`, namespace `utcp-platform`.
- Baseline web image before the rollout: `sha256:64e616bd…` (UI-E8's `b53fb9e` build).
- Web, API, and gateway ready; all platform Pods `Ready`.
- Kubernetes Jobs: `utcp-migrate` 1 succeeded, 0 failed. Redis `queues:default`
  and `queues:default:failed` both 0.
- Pending outbox: `control_plane_outbox_messages` with `dispatched_at is null` = **0**.
- Login route healthy (`/login` 200, `/auth/session` 200).

### Kubernetes API policy-pin drift (repaired)

`scripts/security/check-apiserver-policy-drift` failed:
`allow-runtime-fencer-kubernetes-api: stale endpoint destination, expected
172.24.0.5/32, found 172.24.0.2/32`. The observability egress policy carried the
same stale pin. Repaired canonically with `scripts/security/render-apiserver-policy`,
then applying only the two affected rendered manifests
(`.runtime/kubernetes/security/runtime-fencer-apiserver-egress.yaml`,
`.runtime/observability/allow-apiserver-egress.yaml`). The Traefik policy was
already correct and was not reapplied. No broad apply was used. Re-check:
`Kubernetes API egress drift check passed endpoint=172.24.0.5/32:6443`.

## Natural Login and Tenant

Fresh Playwright MCP context with observers attached before navigation (console,
page errors, `fetch`/`XHR` accounting, `focusin`, storage, viewport). No imported
storage state, preset cookies, injected sessions, or authentication bypass.

Real Login page → `admin@utcp.local.test` with a bounded break-glass temporary
password (`scripts/identity/user-access-reset-password`, reason recorded,
`EXPIRES_IN=60`) → forced password change completed through the UI → Dashboard →
`Local Tenant` selected through AppShell.

## Real Button Variant Examples

All examples are existing non-destructive application controls. No synthetic
production DOM was injected. No danger control was activated.

| Variant | Route | Label | Selector | State | Hover testable without activating |
|---|---|---|---|---|---|
| primary | `/admin/runtime-nodes` | Create runtime node | `.ui-button--primary` | enabled | yes |
| primary | `/admin/audit-records` | Log out | `.ui-button--primary` | enabled | yes |
| secondary | `/admin/runtime-nodes`, `/admin/audit-records` | Refresh | `.ui-button--secondary` | enabled | yes |
| ghost | `/admin/audit-records` | Close (inline detail) | `.ui-button--ghost` | enabled | yes |
| danger | `/admin/runtime-nodes` | Disable | `.ui-button--danger` | enabled | yes (hovered only, never clicked) |

Ghost is not rendered on the Runtime Nodes list: its two `RuntimeNodesView`
instances are endpoint-removal and credential-rotation controls inside the detail
panel, and the opened proof node had no endpoint or credential rows. The Audit
Records inline-detail `Close` is a real production ghost instance, so no
proof-only static page was needed.

## Contrast Measurement Method

For each tested button, real browser-computed values were read while hovered via
`getComputedStyle` (`color`, `backgroundColor`, `borderColor`, `opacity`,
`visibility`, `display`). Where the computed background carried transparency
(the resting ghost is `rgba(0, 0, 0, 0)`), the effective background was resolved
by compositing up the ancestor chain to the first fully opaque painted
background — never against transparent black. Contrast used the WCAG
relative-luminance formula with the normal-text threshold `ratio >= 4.5`.
Repository-calculated ratios were not relied on; every value below is browser-measured.

## Light Theme Hover Results

| Variant | Foreground | Effective background | Ratio | Expected | Result |
|---|---|---|---:|---:|---|
| primary | `rgb(255, 255, 255)` | `rgb(23, 63, 113)` | **10.57:1** | 10.57 | PASS |
| secondary | `rgb(17, 24, 39)` | `rgb(232, 240, 251)` | **15.45:1** | 15.45 | PASS |
| ghost | `rgb(255, 255, 255)` | `rgb(32, 84, 147)` | **7.63:1** | 7.63 | PASS |
| danger | `rgb(255, 255, 255)` | `rgb(180, 35, 24)` | **6.57:1** | 6.57 | PASS |

For every measurement the hover selector applied (`el.matches(':hover') === true`),
the computed pair matched the intended semantic token pair, the accessible name
was unchanged, `role` remained `button`, hover produced **0** requests, and hover
caused **0** focus movement.

## Dark Theme Hover Results

| Variant | Foreground | Effective background | Ratio | Expected | Result |
|---|---|---|---:|---:|---|
| primary | `rgb(24, 38, 53)` | `rgb(168, 199, 250)` | **8.93:1** | 8.93 | PASS |
| secondary | `rgb(237, 243, 250)` | `rgb(34, 59, 89)` | **10.23:1** | 10.23 | PASS |
| ghost | `rgb(24, 38, 53)` | `rgb(138, 180, 248)` | **7.29:1** | 7.29 | PASS |
| danger | `rgb(24, 38, 53)` | `rgb(255, 180, 168)` | **9.03:1** | 9.03 | PASS |

Every browser-measured ratio matches the UI-E9 repository-calculated value
exactly, in both themes. Variant semantic distinction remains visible: primary
and ghost resolve to the primary family, secondary to the muted surface, danger
to the danger token. Hover caused **0** requests and **0** page errors.

## Browser Axe Hover-State Result

axe-core `4.12.1` injected into the live page, `color-contrast` explicitly
enabled, no rule group excluded.

| State | Critical | Serious | Moderate | Minor | Total |
|---|---:|---:|---:|---:|---:|
| Light secondary hovered | 0 | 0 | 0 | 0 | **0** |
| Light ghost hovered | 0 | 0 | 0 | 0 | **0** |
| Light primary hovered | 0 | 0 | 0 | 0 | **0** |
| Light danger hovered | 0 | 0 | 0 | 0 | **0** |
| Dark secondary hovered | 0 | 0 | 0 | 0 | **0** |
| Dark ghost hovered | 0 | 0 | 0 | 0 | **0** |
| Dark primary hovered | 0 | 0 | 0 | 0 | **0** |
| Dark danger hovered | 0 | 0 | 0 | 0 | **0** |
| Focused + hovered secondary (Light) | 0 | 0 | 0 | 0 | **0** |
| Loading secondary hovered + focused (Light) | 0 | 0 | 0 | 0 | **0** |
| Disabled pagination present (Light) | 0 | 0 | 0 | 0 | **0** |

UI-E8 recorded **1 serious** violation in the hovered secondary and hovered ghost
states. Those exact states now return **0 serious and 0 critical**, in both
themes. No moderate or minor finding was recorded in any tested state.

## Hover and Focus-Visible Coexistence

Each control was reached with a real keyboard `Tab` (focus seeded on the
immediately preceding focusable element, then one genuine `Tab` keypress, so the
last input modality was the keyboard and `:focus-visible` applies).

| Theme | Control | `:focus-visible` | `:hover` | `activeElement` | Ring while hovered | Ratio |
|---|---|---|---|---|---|---:|
| Light | secondary Refresh | yes | yes | self | `rgba(32, 84, 147, 0.32) 0 0 0 3px` | 15.45:1 |
| Light | ghost Close | yes | yes | self | `rgba(32, 84, 147, 0.32) 0 0 0 3px` | 7.63:1 |
| Dark | secondary Refresh | yes | yes | self | `rgba(138, 180, 248, 0.45) 0 0 0 3px` | 10.23:1 |
| Dark | ghost Close | yes | yes | self | `rgba(138, 180, 248, 0.45) 0 0 0 3px` | 7.29:1 |
| Dark | primary Log out (sanity) | yes | yes | self | `rgba(138, 180, 248, 0.45) 0 0 0 3px` | 8.93:1 |

In every case the 3px ring remained painted with a non-transparent colour while
hover styling was simultaneously applied, `document.activeElement` remained the
control, and the accessible name was stable. The ring is painted as `box-shadow`
outside the border box, so the hover `background` and `border-color` cannot
obscure it; `elementFromPoint` just outside the border resolved to the ancestor
container, not to any overlay.

## Loading-State Hover Result

Audit Records `Refresh` was activated by keyboard while exactly one
`/api/v1/admin/audit-records` request was held pending by bounded Playwright
interception.

While loading:

```text
native disabled = false
aria-disabled   = true
aria-busy       = true
accessible name = "Refresh Refreshing"
activeElement   = the control
```

Hovering the loading control:

- `:hover` matches, but the **active hover styling does not apply**: computed
  `backgroundColor` was `rgb(255, 255, 255)`, identical to a freshly probed
  resting `.ui-button--secondary` background. The
  `:not([aria-disabled='true'])` guard correctly suppresses the hover treatment,
  so a busy control never misleadingly implies available activation.
- Loading foreground remained readable at **17.74:1** (`rgb(17, 24, 39)` on
  `rgb(255, 255, 255)`), above the `4.5:1` threshold.
- The 3px `:focus-visible` ring remained painted while busy.
- Repeated activation during loading (`Enter`, `Space`, and two pointer clicks)
  produced **0 additional requests** (1 before, 1 after).

After release: `aria-disabled` and `aria-busy` cleared, the accessible name
returned to `Refresh`, focus was retained, exactly **1** request total, 0 page
errors. The interception was removed.

## Disabled-State Hover Result

Naturally disabled pagination boundary control on Audit Records page 1
(`Go to previous page`, `.ui-button.ui-button--secondary`):

```text
native disabled = true
aria-busy       = absent
aria-disabled   = absent
```

- Pointer hover applied **no** active hover treatment: computed background was
  `rgb(229, 231, 235)` both resting and hovered (identical), confirming the
  `:not(:disabled)` guard holds.
- Keyboard focus unavailable: `el.focus()` did not make it `document.activeElement`.
- Requests: **0** before and after a forced activation attempt.
- Disabled colours were `rgb(107, 114, 128)` on `rgb(229, 231, 235)` = 3.90:1,
  with `cursor: wait`, matching the repository's established
  `--color-disabled` / `--color-disabled-text` contract. This is classified
  `EXPECTED_BEHAVIOR`, not a defect: WCAG SC 1.4.3 exempts inactive user
  interface components, and axe returned **0** `color-contrast` violations with
  the disabled control present. It is pre-existing and unrelated to the hover
  correction.

## Responsive Sanity

At `375 x 812` (Light and Dark) and at `1280 x 720`, for representative secondary
(`Refresh`) and ghost (`Close`) controls:

| Cell | `documentElement.scrollWidth` | `innerWidth` | Contained | Size change on hover | Text contained | Clipping ancestor |
|---|---:|---:|---|---|---|---|
| Light 375 secondary | 375 | 375 | yes | no (351x40 → 351x40) | yes | none |
| Light 375 ghost | 375 | 375 | yes | no (257x40 → 257x40) | yes | none |
| Dark 375 secondary | 375 | 375 | yes | no (351x40 → 351x40) | yes | none |
| Dark 375 ghost | 375 | 375 | yes | no (257x40 → 257x40) | yes | none |
| Light desktop secondary | 1280 | 1280 | yes | no (87x40 → 87x40) | yes | none |
| Light desktop ghost | 1280 | 1280 | yes | no (68x40 → 68x40) | yes | none |

`overflow-x` computed `visible` on both `documentElement` and `body` in every
cell, and the root remained contained *while hovered*. Hover changes only
`border-color`, `background`, and `color`, so no button dimension changed and no
wrapping defect, clipping, or group overflow was introduced. No focus ring was
clipped: no ancestor of either control had `overflow` `hidden` or `clip`.

## Console and Page-Error Result

```text
page errors                = 0
unexpected console errors  = 0
console warnings           = 0
unexpected failed requests = 0
```

One console error was recorded for the entire session: the established pre-login
`401 https://app.utcp.local.test/api/v1/auth/session` probe, classified
`EXPECTED_BEHAVIOR` and consistent with UI-E2, UI-E4, UI-E6, and UI-E8.

## Network-Hygiene Result

```text
pointer hover        → 0 requests (every variant, both themes)
theme change         → 0 domain requests (Light→Dark and Dark→Light)
keyboard focus / Tab → 0 requests
one loading action   → exactly 1 request
repeated activation while loading → 0 additional requests
disabled activation  → 0 requests
```

## Storage Boundary

Inspected after login, after tenant selection, after Light testing, after Dark
testing, during loading, after reset to System, and after logout. Keys never
exceeded the established allowlist:

```text
localStorage   = ["pusherTransportTLS", "utcp.appearance"]
sessionStorage = []
```

`utcp.appearance` held only `light`, `dark`, then `system`.
`pusherTransportTLS` held only its transport-negotiation record. A
forbidden-substring scan over all keys and values for `hover`, `contrast`,
`variant`, `ratio`, `focus`, `runtime_node`, `runtime-node`, `correlation`,
`admin@utcp`, `password`, `tenant`, `capabilit`, `audit`, `ghost`, `secondary`,
and `e10` returned **no hits**. No hover state, button variant state, contrast
result, focus target, domain record, tenant or capability state, or test
evidence was persisted.

## Findings

| Classification | Finding |
|---|---|
| PASS | All four variants meet `>= 4.5:1` on pointer hover in Light and Dark, matching UI-E9's calculated ratios exactly |
| PASS | Browser axe returns 0 serious and 0 critical in every hovered, focused+hovered, loading, and disabled state |
| PASS | Hover and `:focus-visible` coexist; the 3px ring is never obscured |
| PASS | Loading controls keep readable styling and receive no active hover treatment |
| PASS | Disabled controls receive no active hover treatment, refuse focus, and issue no requests |
| PASS | No responsive regression; hover changes no button dimension |
| EXPECTED_BEHAVIOR | Pre-login `401 /auth/session` probe |
| EXPECTED_BEHAVIOR | Disabled-control contrast 3.90:1 under the established `--color-disabled` contract; WCAG-exempt as an inactive component and unflagged by axe |
| INTENTIONALLY_INDUCED_CONDITION | One bounded proof-only request hold on `/api/v1/admin/audit-records`, released and unrouted |
| PROOF_LIMITATION | Ghost was proven on the Audit Records inline-detail `Close`; the `RuntimeNodesView` ghost instances need a node with endpoint or credential rows, which the proof node lacked. Both use the same `.ui-button--ghost` authority, so variant coverage is complete. |

Product defects: **None.** `PRODUCT_DEFECT-4` is closed.

## Cleanup

- Request interception released and `unroute` / `unrouteAll` called.
- Temporary axe injection discarded with the browser context.
- Appearance reset to `System`.
- Inline detail panels closed; all proof-only `data-e10` attributes removed.
- No filter or pagination change was left applied (Audit Records remained page 1
  with default query).
- Logged out through the real UI, landing on `/login`; only `/auth/csrf` and
  `/auth/logout` were issued and no protected request followed.
- Playwright context closed; `.playwright-mcp/` removed; no screenshots retained.
- Scratch files and the issued temporary credential removed; no port-forward was started.
- Web remains healthy on the `7eae240` image; preserved workloads were not restarted.
- Kubernetes API egress policy drift check passes.

## Closure of PRODUCT_DEFECT-4

UI-E8 measured hovered secondary at 1.55:1 (Dark) and 1.65:1 (Light), and hovered
ghost at 1.23:1 (Dark) and 1.38:1 (Light), with 1 serious axe violation in two
hovered states. On the `7eae240` web image those exact states now measure
secondary 10.23:1 (Dark) and 15.45:1 (Light), ghost 7.29:1 (Dark) and 7.63:1
(Light), with 0 serious and 0 critical axe violations. Primary and danger remain
above threshold in both themes. `PRODUCT_DEFECT-4` is closed and no replacement
defect was introduced.

## Final Responsive-Proof Verdict

UI-E8 returned INCOMPLETE only because of `PRODUCT_DEFECT-4`, which was not a
responsive-layout defect. UI-E8 found no responsive defect, and UI-E10 confirms
no responsive regression from the hover correction. With `PRODUCT_DEFECT-4`
closed, the UI-E responsive live-proof corridor is **Complete**.

## Remaining UI-E Portfolio-Finish Work

- The final bounded portfolio information-architecture and visual-finish slice.
- Deferred non-blocking: `UiFilterBar` Apply exposes no `loading` prop, so its
  busy state is not surfaced through `aria-disabled` / `aria-busy` (recorded by
  UI-E6; its current zero-duplicate behaviour comes from URL-backed list-query
  deduplication rather than the shared activation guard).
- Non-blocking moderate `page-has-heading-one` on Login and Change-password
  (recorded by UI-E2; those pages use `<h3>` and no `<h1>`).
