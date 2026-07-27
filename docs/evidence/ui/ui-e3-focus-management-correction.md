# UI-E3 — Loading and Inline-Detail Focus Management Correction

Verdict: `UI_E_FOCUS_MANAGEMENT_CORRECTION_COMPLETE`

UI-E remains In Progress. `UTCP_PHASE=T1` is unchanged.

## Browser Evidence Authority

UI-E2 (`docs/evidence/ui/ui-e2-accessibility-browser-proof.md`) found two focused keyboard defects:

- `PRODUCT_DEFECT-1`: `UiButton` applied native `disabled` while `loading`, so a focused async action button blurred and `document.activeElement` became `<body>`.
- `PRODUCT_DEFECT-2`: closing the Audit inline detail removed the focused Close button and did not return focus to the originating Details trigger.

UI-E2 otherwise proved the T1 accessibility baseline with zero serious and zero critical axe findings across primary routes, including browser color contrast, keyboard reachability, visible focus rings, responsive checks, console/network hygiene, and bounded browser storage.

## Root Cause

`apps/web/src/components/ui/UiButton.vue` coupled asynchronous loading state to native disablement through `:disabled="disabled || loading"`.

Native disabled buttons cannot retain keyboard focus. When a focused Refresh, Details, filter, pagination, or mutation button entered loading, the browser removed the button from focusability and focus fell to `<body>`.

The Audit close defect was separate but related: the inline detail panel is non-modal, and activating Close removed the focused control without restoring focus to the trigger that opened the detail.

## Correction

`UiButton` now keeps the `disabled` prop as the only source of the native disabled attribute:

- `disabled=true` produces native `disabled` and remains non-activatable.
- `loading=true` with `disabled=false` leaves native `disabled` absent.
- Loading buttons expose `aria-disabled="true"` and `aria-busy="true"`.
- The default slot remains rendered while the loading label is added, so the control name is not erased by the busy state.

The component now owns activation guarding at the shared boundary:

- Normal click activation emits one `click` event to callers.
- Loading click activation prevents default behavior, stops propagation, and emits no caller action.
- Loading `Enter` and `Space` keydown activation are canceled before a duplicate click or submit can proceed.
- Loading submit buttons do not resubmit their parent form.

No timer, global request lock, feature gate, compatibility mode, caller-specific fallback, or backend idempotency change was added.

## Audit Detail Focus

`AuditRecordsView.vue` now tracks the actual Details trigger element that opened the selected Audit record. Closing the inline detail clears the selection and, after the DOM update, returns focus to that remembered trigger only when it is still connected and focusable.

Switching selection replaces the remembered opener. Route unmount clears the stored element reference. Focus restoration is view-local and does not introduce a modal role, focus trap, browser persistence, global focus registry, or Audit list reread.

## Automated Regression Coverage

`apps/web/src/components/ui/UiComponents.test.ts` covers:

- Focus remains on a `UiButton` when `loading` changes from `false` to `true`.
- Loading buttons have no native disabled attribute and expose `aria-disabled` plus `aria-busy`.
- Explicit `disabled=true` preserves native disabled behavior and blocks activation.
- One legitimate activation before loading reaches the caller exactly once.
- Pointer click, Enter, and Space activation while loading cause zero additional actions.
- A loading submit button does not submit its form again.

`apps/web/src/App.test.ts` covers representative Audit callers:

- Audit Refresh keeps focus during a pending reread, rejects repeated loading activation, and performs exactly one reread.
- Audit Details keeps focus during a pending detail request, rejects repeated loading activation, and performs exactly one detail request.
- Audit filter Apply and pagination Next preserve focus positioning and issue exactly one list request each.
- Audit detail Close removes the detail, returns focus to the exact originating Details trigger, and causes zero list rereads.
- Switching from record A to record B updates the focus-return target so Close returns focus to B's trigger.
- If the remembered opener is detached before close, closing does not throw and does not focus the detached element.

## Request Budgets Preserved

- Audit Refresh: one list reread.
- Audit Details: one selected-detail request.
- Detail Close: zero rereads.
- Filter Apply: one list request.
- Pagination Next: one list request.
- Loading-state repeated activation: zero additional requests.

## Accessibility Enforcement

The existing Vue accessibility linting, serious/critical axe helper, visible `:focus-visible` styling, and two narrow lint suppressions remain in place. This correction does not disable axe rules or weaken lint enforcement.

## Verification

Focused regression proof was run before documentation sync:

```bash
cd apps/web
npm run test -- UiComponents.test.ts App.test.ts
```

Result: passed, 2 files and 76 tests.

Full frontend and repository verification is recorded in the final task report for the committing run.

## Deferred Proof

Focused natural Playwright MCP reproof remains deferred for UI-E:

- loading-button focus retention
- duplicate-activation prevention
- Audit detail close focus restoration
- bounded accessibility, console, network, and storage regression sanity

Do not repeat the completed full route matrix.
