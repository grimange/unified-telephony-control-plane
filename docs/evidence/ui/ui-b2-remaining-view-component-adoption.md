# UI-B2 Remaining View Component Adoption Evidence

Verdict: `UI_B2_REMAINING_VIEW_COMPONENT_ADOPTION_REPOSITORY_IMPLEMENTED`

Repository implementation for the next bounded UI-B adoption slice. This work migrates the remaining current authentication and management views to the established semantic-token and reusable-component authority, and corrects the Users mobile metadata overflow found during the prior UI-B2 browser proof.

Controlled natural browser proof is intentionally pending. UI-B remains **In Progress** until the migrated authentication and management surfaces are exercised through the real browser flow.

## Scope

Implemented:

- Login view component adoption.
- Forced and voluntary change-password view component adoption.
- `/admin/tenants` component adoption.
- `/admin/memberships` component adoption.
- `/admin/runtime-nodes` component adoption.
- `/admin/users/:id` component adoption.
- `/admin/users` narrow-layout metadata overflow correction.
- Focused frontend tests and static source assertions for the migrated-view contracts.

Not changed:

- Backend APIs, request routing, authorization, validation, or lifecycle behavior.
- Database schema.
- Kubernetes manifests.
- Telephony runtime behavior.
- Vue Router architecture.
- `UTCP_PHASE`, which remains `T1`.

## Component Authority

The migrated views consume the existing shared UI primitives under `apps/web/src/components/ui/`:

- `UiButton`
- `UiFormField`
- `UiTextInput`
- `UiSelect`
- `UiPanel`
- `UiStatusBadge`
- `UiAlert`
- `UiLoadingState`
- `UiEmptyState`

No duplicate button, form-field, text-input, select, panel, alert, loading, empty, or status-badge component was added. `UiButton` and `UiPanel` now expose a bounded `focus()` method so existing focus-return behavior can remain on componentized controls.

## Views Migrated

### Login

The Login view now uses shared panels, form fields, text inputs, alerts, loading state, and submit button styling. The existing CSRF/authentication flow, session probe behavior, intended-route redirect, forced-password-change redirect, autocomplete attributes, password-manager-compatible native inputs, and error-response handling were preserved.

Credentials remain submitted only to the canonical backend login endpoint and are not stored in local storage, session storage, routes, navigation state, theme state, or logs.

### Change Password

The Change Password view now uses shared panels, password fields, alerts, loading state, and submit button styling. Forced-change and voluntary-change flows continue through the existing authenticated backend API. Backend password lifecycle rules and validation messages remain authoritative; the frontend only presents server-returned validation and a local confirmation mismatch before submission.

Successful completion still refreshes the authenticated session and preserves intended-route redirect behavior.

### Tenants

The Tenants view now uses shared panels, form fields, text inputs, buttons, loading state, empty state, and status badges. Existing capability gating, tenant creation request body, tenant listing behavior, and API error handling were preserved.

### Memberships

The Memberships view now uses shared panels, form fields, selects, buttons, loading and empty states, alerts, and status badges. Role options are populated from the server-provided role catalog; no checked-in frontend role catalog or role-name authorization logic was introduced.

Existing tenant boundary, capability gating, create request body, status mutation semantics, and cross-tenant API authority were preserved.

### Runtime Nodes

The Runtime Nodes view now uses shared panels, fields, selects, buttons, loading/empty states, alerts, and status badges for RuntimeNode list, catalog-backed creation, status/readiness display, and adapter configuration.

The server-provided runtime family, adapter, and capability catalog remains authoritative. Write-only credential values remain input-only and are not echoed back as readable secrets. Existing RuntimeNode API calls and request bodies were preserved.

The existing `adapter_key === 'asterisk-ari'` rendering seam remains isolated inside the Runtime Nodes view. This slice did not add a speculative generic dynamic-form framework, fallback form, feature switch, or scattered adapter checks. Remaining catalog-driven adapter form extraction is tracked under UI-C.

### User Detail

The User Detail view now uses shared panels, status badges, alerts, loading/empty states, and action buttons for identity, memberships, roles/capabilities, TelephonySession, signaling credential issuance, and lifecycle actions.

Activation, suspension, password reset, TelephonySession termination, signaling credential issuance, and navigation back to Users still use the existing canonical APIs. One-time signaling secrets remain transient in component memory and are not written to URLs, local storage, session storage, theme state, navigation state, or console logs.

## Users Mobile Overflow Correction

The `/admin/users` list-row metadata layout was corrected with token-backed responsive CSS:

- Data rows and subgrid children now allow shrinkage with `min-width: 0`.
- Long metadata values wrap with `overflow-wrap: anywhere`.
- Badge and inline metadata rows wrap instead of forcing page width.
- At narrow widths, the subgrid occupies the available row width instead of preserving a wider desktop grid.

The intended result is that user metadata stacks or wraps coherently at a 375px viewport while controls and actions remain reachable. Controlled browser proof of the exact page-level `scrollWidth` result remains pending.

## Conflicting Visual Authority Removed

The migrated views no longer own screen-local duplicate implementations of:

- Buttons.
- Form labels.
- Text inputs.
- Selects.
- Panels.
- Alerts and error blocks.
- Status badges.
- Loading messages.
- Empty states.
- Focus styling for componentized controls.
- Light-only surface styling for migrated surfaces.

View-local CSS that remains is layout-specific, not a competing reusable visual-control authority.

## Accessibility and Responsiveness

Migrated forms use native controls with programmatic labels, correct button types, `aria-invalid` and `aria-describedby` where applicable, accessible loading text, alert semantics for failures, readable status text, visible token-backed focus rings, and native keyboard operation.

Destructive actions remain explicit native buttons with the existing confirmation behavior. Existing focus-return behavior after signaling secret closure is preserved through the component focus seam.

Responsive behavior remains a single application layout. Forms, action rows, panels, error text, status text, and one-time secret displays wrap within the viewport instead of requiring page-level horizontal scrolling.

## Static Source Assertions

Focused frontend tests assert that:

- All six targeted remaining views import shared UI components.
- Migrated views no longer contain raw local button/select controls.
- Raw inputs were removed from migrated views except the existing RuntimeNode capability checkboxes.
- Membership role selection is driven by `tenantRoleOptions` from the server role catalog.
- No frontend hard-coded tenant role option catalog exists in Memberships.
- The Runtime Nodes view keeps exactly one bounded Asterisk adapter rendering branch.
- Users metadata still renders the expected narrow-layout `.subgrid` contract text.

## Verification Evidence

Focused frontend verification passed:

```text
cd apps/web && npm_config_cache=/tmp/utcp-npm-cache npm run typecheck
cd apps/web && npm_config_cache=/tmp/utcp-npm-cache npm run lint
cd apps/web && npm_config_cache=/tmp/utcp-npm-cache npm run test
```

Vitest result at repository implementation time:

```text
4 test files passed
33 tests passed
```

Additional build and repository-wide verification are recorded in the final task report after the full command set completes.

## Remaining UI-B Gap

Controlled browser proof remains pending for:

```text
real login
→ change-password where applicable
→ Tenants
→ Memberships
→ Runtime Nodes
→ Users
→ User Detail
→ Light/Dark
→ narrow viewport
→ logout
```

No additional UI-B architecture audit is required before that browser proof.
