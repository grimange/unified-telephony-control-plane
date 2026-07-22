# UI-C9 Catalog Runtime Forms and Management Actions

Date: 2026-07-22

Starting commit: `4eaea84`

## Result

UI-C9 cuts the Runtime Nodes frontend over to the canonical adapter-configuration descriptors published by the Runtime Registry catalog. RuntimeNode configuration fields now render from server descriptors, canonical payloads are built from descriptor keys, the handwritten Asterisk ARI frontend form authority is removed, and remaining current management mutations use shared action state plus the application notification authority.

UI-C remains `In Progress` pending controlled browser proof of the descriptor-rendered forms and representative action workflows.

## Canonical Descriptor Consumption

The Runtime Nodes view now resolves configuration fields as:

```text
selected RuntimeNode
→ server catalog adapter descriptor
→ ordered field descriptors
→ RuntimeNodeCatalogField renderer
→ generic descriptor payload builder
→ existing adapter-configuration endpoint
```

The frontend consumes the existing `RuntimeAdapterConfigurationFieldDescriptor` type from `apps/web/src/api/platform.ts`. It does not define a second descriptor hierarchy or checked-in adapter field catalog.

## Previous Frontend Authority Removed

The former frontend Asterisk-specific configuration branch and helpers were destroyed:

- no `adapter_key === 'asterisk-ari'` form branch remains in `RuntimeNodesView.vue`;
- no `asteriskConfigurationForm` helper remains;
- no `saveAsteriskAdapterConfiguration` helper remains;
- no `asteriskNumberFields` frontend field list remains.

Adapter names remain only as server-provided data values, catalog fixtures, or test expectations.

## Catalog Field Renderer

`apps/web/src/components/runtime/RuntimeNodeCatalogField.vue` renders the currently published server descriptor types:

| Descriptor type | Rendered control | Notes |
| --- | --- | --- |
| `text` | shared `UiTextInput` | Uses descriptor label, help, required, read-only, text length hints, deterministic IDs, and password-style input for write-only text fields. |
| `integer` | shared `UiTextInput` with native numeric attributes | Uses descriptor `min`, `max`, and `step` hints and preserves zero values. |
| `json` | accessible `textarea` using shared control styling | Displays deterministic JSON, parses before submission, and maps invalid JSON to the field. |

Unknown descriptor types are not guessed or converted to text. Required unsupported descriptors render blocking feedback and disable submission; optional unsupported descriptors are omitted only through the generic descriptor payload path.

## Asterisk and Simulator Cutover

Asterisk ARI renders all seven catalog fields in descriptor order:

1. `application_name`
2. `connect_timeout_ms`
3. `request_timeout_ms`
4. `websocket_handshake_timeout_ms`
5. `heartbeat_interval_ms`
6. `reconnect_min_delay_ms`
7. `reconnect_max_delay_ms`

The simulator deterministic adapter renders through the same path, including its `parameters` JSON descriptor. No simulator-specific renderer or payload branch was added.

`freeswitch-esl` remains non-configuration-capable and receives no fabricated fields.

## Payload Construction

`saveRuntimeAdapterConfiguration()` now uses a single generic payload builder in `apps/web/src/state/appState.ts`.

The builder:

- includes only descriptor-defined writable fields;
- excludes read-only fields;
- preserves descriptor keys exactly;
- preserves integer zero and JSON false/null/empty collection values;
- parses JSON before submission and blocks invalid JSON;
- omits blank unchanged write-only fields;
- maps backend field errors back to matching descriptor controls.

Backend validation remains authoritative.

## Defaults, Current Values, and Secrets

Form initialization preserves the required precedence:

```text
sanitized current configuration value
→ descriptor default
→ type-appropriate empty input
```

Opening a RuntimeNode detail panel does not mutate the node. Descriptor defaults initialize the form only; save still requires explicit operator action.

Write-only descriptor values are never prepopulated from readback, never included in IDs, omitted when blank, sent only when entered, and cleared after success. Notifications use safe human-readable success/failure text and do not include one-time or replacement secret values.

## RuntimeNode Lazy Loading Preservation

The descriptor renderer starts only after the selected node details/configuration are loaded. Initial Runtime Nodes page load still fetches only the list and shared catalog, with zero per-node detail fan-out. Saving one node invalidates only that node detail state, refreshes the canonical list, and rereads that node detail without loading every node.

Tenant switching still clears RuntimeNode detail state, forms, field errors, unsupported descriptor errors, transient secret surfaces, and detail caches through the existing state reset path.

## Management Mutation Inventory

| View | Mutation | UI-C9 action-state authority |
| --- | --- | --- |
| Tenants | Create tenant | `useAsyncActionMap` keyed create action, shared notification, canonical tenant reread |
| Tenants | Set tenant status | `useAsyncActionMap` keyed tenant/status action, shared notification, canonical tenant reread |
| Memberships | Create membership | `useAsyncActionMap` keyed create action, shared notification, role catalog remains server-driven, canonical membership reread |
| Memberships | Set membership status | `useAsyncActionMap` keyed membership/status action, shared notification, canonical membership reread |
| Users | Create user | `useAsyncActionMap` keyed create action, shared notification, current URL-backed query reread |
| Users | Activate or suspend user | `useAsyncActionMap` keyed user/status action, shared notification, current guarded query reread |
| Users | Password reset | `useAsyncActionMap` keyed user/password-reset action, shared notification without temporary password, current query reread |
| User Detail | End TelephonySession | `useAsyncActionMap` keyed action, shared notification, canonical user-detail reread |
| User Detail | Issue or reissue signaling credential | `useAsyncActionMap` keyed action, shared notification without SIP secret, canonical user-detail reread, transient inline secret display |
| Runtime Nodes | Create RuntimeNode | `useAsyncActionMap` keyed create action, shared notification, canonical list reread |
| Runtime Nodes | Set desired state | `useAsyncActionMap` keyed node/state action, shared notification, affected detail invalidation and canonical reread |
| Runtime Nodes | Add or remove endpoint | `useAsyncActionMap` keyed endpoint action, shared notification, affected detail invalidation and canonical reread |
| Runtime Nodes | Set capabilities | `useAsyncActionMap` keyed capabilities action, shared notification, affected detail invalidation and canonical reread |
| Runtime Nodes | Create, rotate, or retire credential | `useAsyncActionMap` keyed credential action, shared notification without credential values, affected detail invalidation and canonical reread |
| Runtime Nodes | Save adapter configuration | `useAsyncActionMap` keyed adapter-configuration action, shared notification, affected detail invalidation and canonical reread |

No new management operation, CLI workflow, manual reconciliation path, feature gate, allowlist, or alternate API was introduced.

## Accessibility and Responsiveness

Catalog-rendered controls use programmatic labels, unique help/error associations, native input semantics, visible shared focus styling, accessible invalid JSON feedback, required/read-only/disabled semantics, and keyboard-submittable forms.

Fields and action rows keep the existing shared responsive layout. JSON textareas are constrained by the shared control width and narrow layouts stack without a second mobile DOM authority.

## Tests

Focused frontend coverage now proves:

- Asterisk descriptor fields render in canonical order with text/integer controls and catalog constraints.
- Simulator `json` descriptors render through the same generic path.
- Invalid JSON blocks submission with field-level feedback.
- Required unsupported descriptors block submission and do not become text inputs.
- Payload keys equal descriptor keys.
- Read-only fields are omitted.
- Integer zero and valid JSON values are retained.
- Blank write-only values are omitted, entered write-only replacements are included once, and write-only inputs clear after success.
- Notifications and rendered text do not contain synthetic secret values.
- RuntimeNode initial detail fan-out remains zero and reopened node details use the bounded cache.
- Existing Users stale-response, Users mobile overflow, URL-backed list state, notification, and credential-ID regressions remain covered.

Repository hygiene now also checks that the Runtime Nodes frontend has no Asterisk-specific form branch/helper, no checked-in adapter field catalog, no adapter-key rendering/payload branch, and that current management views use the shared keyed action state.

## Remaining UI-C Proof Scope

The remaining proof is a focused natural browser run covering:

- catalog-rendered Asterisk fields;
- simulator JSON handling;
- canonical save and reread;
- invalid JSON and unsupported-descriptor blocking where safely reproducible;
- secret omission;
- representative shared management actions;
- notifications;
- zero RuntimeNode initial detail fan-out;
- tenant-switch cleanup.
