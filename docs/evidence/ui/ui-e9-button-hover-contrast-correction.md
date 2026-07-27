# UI-E9 - Button Hover Contrast Correction

Verdict: `UI_E_BUTTON_HOVER_CONTRAST_CORRECTION_COMPLETE`

UI-E9 corrects the shared-button hover contrast defect recorded by UI-E8
`PRODUCT_DEFECT-4`. The correction is repository-only: no Kubernetes apply,
Playwright MCP, browser live proof, backend code, infrastructure code, domain
workflow, or phase marker was changed. UI-E remains In Progress and
`UTCP_PHASE=T1` remains unchanged.

## Starting Point

- Branch: `main`
- Starting HEAD: `0c56482` (`docs(ui): prove responsive layout contract`)
- Starting working tree: clean
- Phase marker: `UTCP_PHASE=T1`
- Evidence authority: [`ui-e8-responsive-contract-live-proof.md`](ui-e8-responsive-contract-live-proof.md)

## UI-E8 Live Measurements

UI-E8 measured the following pointer-hover contrast failures caused by the shared
button hover rule:

| Variant | Dark hover contrast | Light hover contrast | Result |
|---|---:|---:|---|
| secondary | 1.55:1 | 1.65:1 | fail |
| ghost | 1.23:1 | 1.38:1 | fail |

Primary and danger hover states were already above the normal-text threshold.

## Root Cause

The previous shared selector in `apps/web/src/style.css` applied
`background: var(--color-primary-hover)` to every non-disabled `button` and
`.ui-button` on hover. It did not set a matching foreground. Secondary and ghost
buttons kept their variant resting foregrounds while inheriting the primary hover
background, collapsing contrast in both Light and Dark.

## Shared Hover Authority Cutoff

The conflicting shared hover authority was removed. Hover background and
foreground are now owned by explicit variant selectors:

| Variant | Hover foreground | Hover background |
|---|---|---|
| primary | `--color-surface` | `--color-primary-hover` |
| secondary | `--color-text` | `--color-primary-muted` |
| ghost | `--color-surface` | `--color-primary` |
| danger | `--color-surface` | `--color-danger` |

The generic native `button` hover path remains scoped to non-`.ui-button`
buttons and uses the same foreground/background pair as primary. The hover
selectors exclude native `:disabled` and loading `aria-disabled="true"` buttons,
so busy controls do not visually imply available activation.

## Deterministic Contrast Ratios

Ratios below are computed from `apps/web/src/styles/tokens.css` by the focused
Vitest contrast helper in `apps/web/src/App.test.ts`. The threshold is the WCAG
normal-text minimum, `4.5:1`.

| Variant | Light hover contrast |
|---|---:|
| primary | 10.57:1 |
| secondary | 15.45:1 |
| ghost | 7.63:1 |
| danger | 6.57:1 |

| Variant | Dark hover contrast |
|---|---:|
| primary | 8.93:1 |
| secondary | 10.23:1 |
| ghost | 7.29:1 |
| danger | 9.03:1 |

## State Preservation

- `focus-visible` remains the global `--focus-ring` 3px ring and is not removed
  by hover styling.
- Hover and focus-visible can coexist because hover uses background, foreground,
  and border declarations while focus-visible continues to paint box-shadow.
- Explicit disabled buttons still use native `disabled` styling.
- Loading buttons still remain focusable with `aria-disabled="true"` and
  `aria-busy="true"` and are protected by the existing shared activation guard.
- Loading labels and visible button names remain present.
- Active feedback remains coherent through the same border/background/color
  state surface; no layout or sizing declarations changed.
- Responsive structure is unchanged: no widths, root overflow behavior, page
  layout, filter containment, pagination containment, or detail-panel selectors
  were modified.

## Automated Regression Coverage

Added deterministic source-contract coverage in `apps/web/src/App.test.ts`:

- Asserts the old broad `.ui-button:hover` background authority is absent.
- Asserts every variant has an explicit hover background and foreground.
- Parses `tokens.css` and computes WCAG contrast for primary, secondary, ghost,
  and danger hover pairs in Light and Dark.
- Requires every hover pair to meet `ratio >= 4.5`.

Extended `apps/web/src/components/ui/UiComponents.test.ts`:

- Verifies every variant emits the stable `ui-button--{variant}` hook used by
  the hover CSS.
- Verifies resting button text remains present.
- Verifies loading text, `aria-disabled`, and `aria-busy` remain present without
  native disabling.

This is deterministic repository coverage only. It does not claim real browser
pseudo-class rendering or painted contrast.

## Verification

Focused check run during implementation:

```bash
cd apps/web
npm run test -- App.test.ts UiComponents.test.ts
```

Result: passed after correcting one test expectation that assumed Vue Test Utils
would serialize a separating space between the default and loading spans.

Full verification:

```bash
cd apps/web
npm run typecheck
npm run lint
npm run test
npm run build
cd ../..
make repository-hygiene
make workflow-check
make secret-scan
make test
make check
make build
git diff --check
git diff --cached --check
```

Results:

- `npm run typecheck`: passed.
- `npm run lint`: passed with `--max-warnings=0`.
- `npm run test`: passed, 7 files and 129 tests.
- `npm run build`: passed.
- `make repository-hygiene`: passed.
- `make workflow-check`: passed.
- `make secret-scan`: passed.
- `make test`: passed, backend 402 passed / 6 skipped and web 129 passed.
- `make check`: passed.
- `make build`: passed.
- `git diff --check`: passed.
- `git diff --cached --check`: passed before staging.

## Deferred Proof

Focused natural Playwright MCP reproof of secondary and ghost button hover
contrast in Light and Dark, with primary/danger, focus-visible, loading,
responsive, console, and accessibility regression sanity.
