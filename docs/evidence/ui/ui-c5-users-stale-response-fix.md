# UI-C5 — Users Stale-Response Rendering Fix

Verdict: `UI_C_USERS_STALE_RESPONSE_FIX_REPOSITORY_IMPLEMENTED`

Repository implementation correcting the UI-C3 live acceptance failure found in
`docs/evidence/ui/ui-c4-list-query-history-pagination-browser-proof.md`.

Starting commit: `3cff1dd` (`docs(ui): prove list query and pagination state`).
Implementation under proof before this correction: `dd03c21`
(`feat(ui): add shared list query and pagination state`).

No live browser proof was performed for this implementation task.

## Live failure corrected

The UI-C4 browser proof delayed an older Users query and allowed a newer query to
complete first:

```text
query A: status=active, delayed
query B: status=suspended, completes first

URL after query B:
  /admin/users?status=suspended

rendered result after delayed query A completes:
  active users and active pagination metadata
```

Expected behavior:

```text
older Users query A completes after newer query B
→ query A must not overwrite the rendered users or pagination for query B
```

## Root cause

`useAsyncResource` already had a monotonic request-generation guard, but the
Users view did not render from the guarded resource result. The old
`refreshUsers` loader wrote directly to the module-level `users` and
`userPagination` refs after `identityApi.users(...)` resolved. `UsersView`
rendered those refs, so a stale loader mutated the visible rows and pagination
before the `useAsyncResource` guard rejected only its own `state.data` update.

Previous authority:

```text
canonical Users API response
→ refreshUsers mutates module users/userPagination
→ UsersView renders module users/userPagination
→ useAsyncResource guard protects separate resource state only
```

That duplicated rendered-state authority made the live stale overwrite possible.

## Corrected authority

`refreshUsers` now returns one guarded result shape:

```ts
type UsersListResult = {
  users: AdminUser[];
  pagination: UserListPagination;
};
```

The Users view renders derived rows and pagination from
`usersResource.state.data`. The list loader applies the returned result to the
legacy module refs only after `useAsyncResource.load()` accepts the result for
the active request. A stale request returns `null` and is not applied.

Corrected authority:

```text
canonical Users API response
→ active guarded UsersListResult
→ rendered users and pagination
```

The prior unguarded rendered mutation path was removed from the Users route.

## Preservation

- URL-backed Users search, status, page, and page-size behavior is preserved.
- Browser Back, Forward, reload restoration, query normalization, and list
  summary behavior are preserved.
- Refresh keeps prior data visible while the active query rereads and keeps a
  failed refresh distinct from empty.
- User create, activation, suspension, and password reset still submit through
  the canonical API and reread the current Users query after success.
- Tenant switching resets the Users resource generation before the new tenant
  query is loaded, preventing late prior-tenant responses from rendering.
- Users mobile overflow CSS, token/theme/component authority, notifications,
  RuntimeNode lazy detail loading, and router authority were not changed.
- Backend code, API contracts, database schema, Kubernetes manifests, telephony
  runtime behavior, and `UTCP_PHASE=T1` were not changed.

## Regression coverage

`apps/web/src/App.test.ts` now includes rendered-view regressions for:

- Out-of-order Users query responses: query A `status=active` is held, query B
  `status=suspended` resolves first, and delayed query A cannot overwrite the
  rendered suspended row.
- Pagination metadata: delayed query A reports `206` total and `has_more=true`,
  while query B reports `1` total and `has_more=false`; after A resolves last,
  the rendered summary and Next disabled state still describe query B.
- Tenant isolation: a delayed tenant A Users response cannot overwrite tenant B
  rows and pagination after tenant switch and tenant B response completion.

These tests assert the state rendered by `UsersView`, not only the shared
resource composable in isolation.

## Remaining proof gap

The remaining UI-C3 acceptance gap is a focused browser proof only:

```text
focused Playwright MCP proof that delayed Users query A cannot overwrite newer
query B rows or pagination
```

UI-A and UI-B remain Complete. UI-C remains In Progress pending this focused
live stale-response proof and later UI-C roadmap work.
