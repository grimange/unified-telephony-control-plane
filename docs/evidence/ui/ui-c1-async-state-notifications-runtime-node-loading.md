# UI-C1 Async State, Notifications, and RuntimeNode Detail Loading

Date: 2026-07-22

Starting commit: `b0b91db`

Implementation scope: repository-only frontend implementation. Controlled browser proof is intentionally pending.

## Baseline

- Existing views used repeated local `loading` booleans and `run()` wrappers around API calls.
- API failures flowed through global `error`/`message` strings and shell-local alerts.
- Runtime Nodes loaded the server RuntimeNode catalog and list, then iterated every listed node through `loadRuntimeNodeDetails(node)`.
- `loadRuntimeNodeDetails()` fetched adapter configuration, runtime evidence, and history for each node during initial list load.
- Login routed the initial unauthenticated session probe `401` through the same error state used by submitted login failures, rendering neutral bootstrap guidance under an authentication-failure heading.
- RuntimeNode repeated forms used duplicate literal field IDs, including `credential-secret`.

## Shared Resource State

`apps/web/src/composables/asyncState.ts` now provides a restrained resource contract for reads:

- `idle`
- `loading`
- `success`
- `empty`
- `refreshing`
- `error`
- `forbidden`

The contract preserves prior data while refreshing, distinguishes empty responses from failed responses, and ignores stale request results that arrive after a newer request.

## Shared Action State

`apps/web/src/composables/asyncState.ts` also provides a restrained mutation contract:

- `idle`
- `submitting`
- `succeeded`
- `failed`

The contract prevents duplicate submission while an action is in flight, preserves backend failure details, supports reset, and does not retry or invent canonical state.

## Notification Authority

`apps/web/src/state/notifications.ts` is the single notification state authority for presentation feedback.

Supported variants:

- `success`
- `information`
- `warning`
- `error`

`apps/web/src/components/ui/UiNotificationRegion.vue` renders the single app-level notification region mounted by `apps/web/src/App.vue`. Non-critical success and information notifications may expire automatically. Material warning and error notifications remain until dismissed. Secret values can be explicitly scrubbed before storage/rendering, and no RuntimeNode credential, signaling credential, temporary password, or reset secret is sent to notification state.

## Login Informational State

`ensureSession()` now treats the expected pre-login session-probe `401` as neutral guidance via `loginNotice`.

Preserved behavior:

- Submitted invalid credentials still set the form error state.
- The password field is only marked invalid for submitted authentication failures.
- Backend-provided login error messages remain visible.
- Successful login, intended-route redirects, and forced-password-change redirects are unchanged.

## RuntimeNode Request Budget

Initial Runtime Nodes load now performs only bounded shared requests:

- RuntimeNode catalog
- RuntimeNode list

The initial load no longer performs per-node adapter-configuration, runtime-evidence, or history requests. Detail requests are loaded on demand when the row details/editor panel is opened.

Required detail request behavior:

- `N` RuntimeNodes do not create `N` detail request groups during initial load.
- Opening one node loads only that node's adapter configuration, runtime evidence, and history where supported.
- Reopening an already loaded node may use the in-memory detail cache.

## Detail Cache and Invalidation

RuntimeNode details are cached only in current Vue memory through `appState.ts`.

Invalidation occurs when:

- Tenant context changes.
- Session logout occurs.
- The node desired state changes.
- Endpoints, capabilities, adapter configuration, or credentials mutate.
- A node is no longer present in the refreshed list.
- Detail loading fails or is forbidden.

After relevant RuntimeNode mutations, the frontend submits the canonical backend request, refreshes the canonical list, and reloads the affected node's details without requiring manual API, CLI, or reconciliation steps.

## Unique IDs

RuntimeNode repeated fields now use stable node-scoped IDs, such as:

- `credential-secret-runtime-1`
- `endpoint-host-runtime-1`
- `asterisk-application-name-runtime-1`

Labels, help text, and error associations are scoped through the existing `UiFormField` contract. No credential value is placed in an ID.

## Representative Mutation Adoption

Shared action state and notifications were adopted for:

- RuntimeNode create and update workflows in `RuntimeNodesView.vue`.
- User activation and suspension in `UsersView.vue`.
- Membership create and status updates in `MembershipsView.vue`.

Inline form and field validation remain separate from notifications.

## Secret Boundary

One-time and write-only secrets remain outside notification state, URLs, storage, and theme state. Existing transient displays for temporary passwords and signaling credentials are preserved.

## Tests and Static Checks

Focused frontend coverage was added for:

- Shared resource-state transitions.
- Shared action-state duplicate prevention and reset.
- Notification variants, unique IDs, dismissal, persistence for critical errors, and secret scrubbing.
- Login informational bootstrap `401` versus submitted authentication failure.
- RuntimeNode initial request budget and detail cache reuse.
- RuntimeNode unique credential field IDs.

Repository hygiene now rejects:

- Literal duplicate `id="credential-secret"` in Runtime Nodes.
- `refreshRuntimeNodes()` calling `loadRuntimeNodeDetails()`.
- Browser persistence in RuntimeNode detail and notification state.
- Secret-bearing notification calls.
- Checked-in role, adapter, or capability catalogs in Runtime Nodes.

## Remaining UI-C Work

- Shared table abstraction.
- URL-backed filter, sorting, and pagination state.
- Broader mutation adoption.
- Catalog-driven adapter forms beyond the existing bounded `asterisk-ari` seam.
- Remaining loading/error-state normalization.
- Controlled natural browser proof of UI-C1 behavior.
