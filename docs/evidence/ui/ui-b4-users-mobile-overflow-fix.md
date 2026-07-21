# UI-B4 Users Mobile Overflow Fix

## Scope

UI-B4 corrects the remaining UI-B browser-proof failure for the Users route only:

```text
route: /admin/users
viewport: 375px
documentElement.scrollWidth: 691px
innerWidth: 375px
```

This is a repository implementation correction. It does not include the follow-up live browser proof.

## Root Cause

The live UI-B3 browser proof found that Users rows still exceeded the 375px viewport because the base `.data-row` rule used `flex-wrap: wrap`. The narrow breakpoint changed `.data-row` to `flex-direction: column`, but did not reset wrapping. With stretched row height, the `.subgrid` metadata child could wrap into a second flex column off viewport, expanding the document width.

## CSS Authority Changed

The correction is scoped to the existing responsive authority in `apps/web/src/style.css`:

```css
@media (max-width: 720px) {
  .data-row {
    flex-wrap: nowrap;
    height: auto;
  }
}
```

The existing narrow `.topbar, .data-row` rule still makes Users rows a vertical column. The new `.data-row` rule removes the conflicting mobile wrap behavior and prevents the metadata subgrid from becoming an off-viewport second flex column.

No root-level `overflow-x: hidden` masking was added.

## Metadata and Actions

`apps/web/src/views/UsersView.vue` was not changed. Identity, email, status, metadata values, row actions, pagination, search, filters, user creation, user-detail navigation, and existing capability checks remain present through the existing UI.

The fix changes only the narrow row layout contract, so metadata and actions remain visually associated with the same user record.

## Desktop Preservation

Desktop row behavior remains under the existing base `.data-row` rules. The correction is limited to `@media (max-width: 720px)`, preserving the desktop structure, metadata alignment, status badges, actions, pagination, search, filters, light theme, and dark theme.

## Regression Contract

`scripts/check-repository-hygiene` now includes a focused source-level CSS contract proving that:

- `apps/web/src/style.css` does not mask page overflow with `overflow-x: hidden`.
- The `@media (max-width: 720px)` block keeps `.data-row` as `flex-direction: column`.
- The same breakpoint sets `.data-row` to `flex-wrap: nowrap`.
- The same breakpoint sets `.data-row` to `height: auto`.

This static contract prevents reintroducing the failure mode. It does not replace the remaining live `scrollWidth` measurement.

## Remaining Proof

UI-B remains In Progress until a focused Playwright MCP browser proof shows `/admin/users` has no page-level overflow at 375px in both Light and Dark.

## Phase State

```text
UI-A = Complete
UI-B = In Progress
UI-C = In Progress
UI-D = In Progress
UI-E = In Progress
T2 = Complete
T5 = In Progress
UTCP_PHASE=T1
```
