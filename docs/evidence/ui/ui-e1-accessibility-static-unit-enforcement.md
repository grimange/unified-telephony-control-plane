# UI-E1 Accessibility Static and Unit Enforcement

## Scope

UI-E1 establishes repository-level accessibility quality enforcement for the stable UI-A through UI-D frontend surfaces. It does not change backend behavior, API contracts, authorization capabilities, domain authority, Kubernetes manifests, Reverb/WebSocket behavior, or the phase marker.

UI-E remains In Progress. `UTCP_PHASE=T1` remains unchanged.

## Dependencies

Frontend development dependencies added:

- `eslint-plugin-vuejs-accessibility` for Vue template accessibility linting in the canonical ESLint flat config.
- `axe-core` for Vitest/jsdom accessibility assertions.

No runtime dependency and no browser automation dependency were added.

## ESLint Enforcement

The canonical `apps/web` lint command remains:

```bash
npm run lint
```

The existing ESLint 9 flat configuration now includes `eslint-plugin-vuejs-accessibility` `flat/recommended` alongside the existing JavaScript, TypeScript, Vue, and Vitest rules. The canonical command uses `--max-warnings=0`, so accessibility lint findings fail the normal lint path.

Narrow inline suppressions remain only for component-boundary false positives:

- `UiFormField.vue`: the component owns label/id generation and passes the generated id into its control slot; rendered associations are covered by axe tests.
- `UiSelect.vue`: the primitive is labelled by `UiFormField` or caller-provided `aria-label` at render sites; rendered associations are covered by axe tests.

No broad directory ignore, file-wide accessibility suppression, warning downgrade, environment activation, or alternate lint command was added.

## Axe Harness

`apps/web/src/test/accessibility.ts` provides `assertNoSeriousAxeViolations(container)`.

The helper:

- Waits for Vue updates before running axe.
- Runs axe against the mounted container, using a temporary cloned host when Vue Test Utils mounted the wrapper outside `document`.
- Fails on serious or critical violations.
- Emits readable failures containing the axe rule id, impact, and affected selector.
- Removes temporary DOM state after each assertion.

The helper disables `color-contrast` only because jsdom does not compute real layout and paint contrast reliably. Natural browser proof owns contrast, actual focus movement, tab order, responsive layout, and browser-network evidence.

A temporary inaccessible fixture with an unnamed button was introduced during implementation, the focused Vitest run failed with `button-name [critical]: button`, and the temporary fixture was removed.

## Shared Primitive Coverage

`apps/web/src/components/ui/UiComponents.test.ts` covers the existing shared primitives with deterministic assertions and a composed axe fixture:

- `UiButton`
- `UiTextInput`
- `UiSelect`
- native checkbox rendered through `UiFormField`
- `UiFormField`
- `UiPanel`
- `UiDataList`
- `UiStatusBadge`
- `UiPagination`
- `UiEmptyState`
- `UiAlert`
- `UiLoadingState`
- `UiFilterBar`
- `UiNotificationRegion`

The coverage verifies accessible names or label association where applicable, semantic native controls, disabled/loading state, error association, panel naming, table/list semantics in the composed fixture, status text, pagination naming, notification roles, and secret redaction.

## Primary Route Coverage

`apps/web/src/App.test.ts` adds axe assertions for:

- Login
- Dashboard
- Users
- Tenants
- Memberships
- Runtime Nodes
- Conference Operations
- Runtime Operations
- Runtime Reconciliations
- Audit Records

The tests use the existing Vue Router, app shell, route guards, session fixtures, deterministic API mocks, and Vue Test Utils mounting. No live network access, browser sessions, production authentication bypass, or second test harness was added.

## Corrections Made

Concrete lint/test findings corrected:

- Added an explicit generated `id`/`for` association for the pagination page-size select.
- Moved the compact-navigation Escape key handler from a non-interactive `nav` wrapper to each keyboard-reachable `RouterLink`.
- Added generated `id`/`for` associations for RuntimeNode capability checkboxes.
- Kept primitive label ownership intact with narrow documented suppressions for the reusable form-field/select boundary.
- Added serious/critical axe assertions over shared primitives and primary route views.

## Accessibility Contract

- Prefer semantic HTML over ARIA.
- Every form control requires a programmatic label in the rendered DOM.
- Every interactive control requires an accessible name.
- Status, validation, and operational outcomes must not rely only on color.
- Dialogs, panels, and detail regions require clear names.
- Tables and data lists require coherent semantics.
- Destructive and state-changing controls require explicit visible text or an accessible name.
- Meaningful content must not be hidden from assistive technology to silence a tool.

## Keyboard and Focus Contract

- All interactive controls must be keyboard reachable.
- Focus order should follow visual and document order.
- Focus indicators must remain visible.
- Route and dialog behavior must not strand focus.
- Unit tests can verify semantic reachability and labels; natural browser proof must test real tab order, focus restoration, and keyboard activation.

## Responsive Contract

- Root-level overflow clipping is prohibited as a defect mask.
- Controls, identifiers, tables, filters, pagination, and detail panels must remain usable at supported narrow widths.
- Layout-dependent contrast, overflow, wrapping, and viewport fit require browser proof.

## Unit-Test Convention

- Unit tests use deterministic fixtures and mocked canonical APIs.
- Axe assertions cover shared primitives and primary route views.
- Serious and critical axe violations fail the unit suite.
- jsdom tests do not claim layout, real focus movement, contrast, responsive behavior, browser networking, or production authentication proof.
- `npm run test` is the durable authority for the current frontend unit-test count.

## Natural Playwright Proof Convention

Later natural browser proof must:

- Begin at the real Login page when authentication is material.
- Use normal application login, permission, and tenant flow.
- Avoid injected sessions, cookies, storage, or production auth bypasses.
- Record console, page-error, network, focus, keyboard, responsive, contrast, and storage evidence.
- Explain intentionally induced failures separately from product defects.

## Verification Status

Initial focused checks passed after correction:

```bash
cd apps/web
npm run typecheck
npm run lint
npm run test
```

The remaining broad repository checks are tracked by the implementation completion report.

## Deferred Proof

Natural Playwright MCP keyboard, focus, contrast, responsive, and console/network-hygiene proof remains deferred. This slice does not claim browser proof.
