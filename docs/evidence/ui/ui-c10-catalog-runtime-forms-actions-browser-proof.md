# UI-C10 — Catalog-Driven RuntimeNode Forms and Shared Management Actions Browser Proof

Verdict: `UI_C_CATALOG_FORMS_ACTIONS_LIVE_PROOF_COMPLETE`

Controlled natural browser proof (Playwright MCP) of the descriptor-consuming
frontend `6e31763` (`feat(ui): consume runtime configuration descriptors`) on
the `utcp-local` k3d cluster, exercised from the real login page at
`https://app.utcp.local.test`. Proves catalog-rendered RuntimeNode forms, the
Asterisk handwritten-branch cutoff, simulator JSON handling, canonical save and
reread, unsupported-descriptor blocking, write-only omission, action-scoped
management mutations with notifications, preserved lazy detail loading, and
tenant-switch cleanup.

Playwright MCP only; fresh browser context; no imported cookies, preset storage,
injected session, or authentication bypass. Response/request interception was
used only for the frontend-only synthetic-descriptor and controlled-failure
scenarios; login, session, CSRF, tenant-context, credential, and secret requests
were never intercepted. Secrets are not reproduced here.

## Environment divergence (authorized)

The deployed API pod predated the descriptor-publishing backend commit
`4eaea84`, so the runtime catalog initially exposed no adapter-configuration
descriptors (`asterisk-ari` had `adapter_configuration_available: true` but no
descriptor object). With that backend absent the catalog-driven forms cannot
render, and the task scoped deployment to the web workload only. The operator
authorized also deploying the API. The API was rebuilt from `6e31763` (which
includes `4eaea84`) and rolled out (`deployment/api` only); no workers,
scheduler, data stores, telephony workloads, Traefik, or observability workloads
were restarted. After the API rollout the catalog published the seven Asterisk
descriptors and four simulator descriptors, and the proof proceeded.

## Deployed image provenance

- Web image built from clean `6e31763` via `infrastructure/docker/web/Dockerfile`
  `app-prod`; running web Pod digest
  `utcp-local-registry:5000/utcp/web@sha256:7ca56c7e78db9349316f36c1d0142db57c221ac739a5f04156cdcda6261ca6ba`;
  served bundle `assets/index-Tdk3r1U-.js`, reproduced by `npm run build`.
- API image built from clean `6e31763` (incl. `4eaea84`); running API Pod digest
  `utcp-local-registry:5000/utcp/api@sha256:7d6a24f4e4125d52ae0bf27fdf23e98c632afd73273f8cfa1cab44cff95bc1ce`.
- Edge `/`, `/healthz`, `/dashboard`, `/admin/runtime-nodes` all `200`.

## Natural login

Fresh context → `/login` → `admin@utcp.local.test` (no forced change) →
`/dashboard`; Local Tenant selected through the AppShell.

## RuntimeNode initial request budget

`/admin/runtime-nodes`: **110 nodes** (27 Asterisk, 83 simulator). Initial admin
API requests were exactly two — `runtime-node-catalog` and `runtime-nodes` —
with **zero** per-node adapter-configuration, runtime-evidence, history, or
credential requests.

## Asterisk catalog and rendered fields

Opening one Asterisk node issued exactly three detail requests for that single
node (adapter-configuration + runtime-evidence + history). Catalog fields = 7,
rendered fields = 7, in canonical descriptor order:

```
application_name               text    (label "ARI application name", required)
connect_timeout_ms             number  min 250   max 30000   step 1
request_timeout_ms             number  min 250   max 60000   step 1
websocket_handshake_timeout_ms number  min 250   max 60000   step 1
heartbeat_interval_ms          number  min 1000  max 120000  step 1
reconnect_min_delay_ms         number  min 100   max 120000  step 1
reconnect_max_delay_ms         number  min 100   max 300000  step 1
```

Each field's label comes from the descriptor; `application_name` uses a text
control and the timeout/reconnect fields use native number controls with
`min`/`max`/`step` matching the catalog hints; all controls are `required`; IDs
are deterministic and node-scoped
(`runtime-node-<id>-adapter-field-<key>`), all unique, with unique per-field
help associations and no value or secret in any ID.

## Asterisk legacy authority cutoff

No handwritten Asterisk DOM or `adapter_key === 'asterisk-ari'` branch renders;
the seven fields are produced entirely by the generic `RuntimeNodeCatalogField`
renderer. (Repository confirmation: the literal branch exists only in tests that
assert its absence.)

## Simulator JSON rendering

A simulator node rendered `scenario_key` (text), `scenario_version` (number),
`seed` (text), and `parameters` (JSON `<textarea>`) through the same generic
renderer, with descriptor labels, `required`, and unique node-scoped IDs; the
current `parameters` value rendered as valid readable JSON (`[]`). No
simulator-specific frontend renderer exists.

## Invalid JSON blocking

Entering `{ invalid json, not: valid ` in the `parameters` textarea and saving
produced a field-associated error: the textarea `aria-invalid="true"`,
`aria-describedby` includes the field `-error` element reading "Parameters must
contain valid JSON.", **zero** configuration PUT/PATCH requests were sent, no
success notification appeared, and the form remained usable.

## Canonical simulator save and reread

With valid changed JSON (`{"utcp_c10_proof":1}`) the save issued one canonical
`PUT /api/v1/admin/runtime-nodes/<id>/adapter-configuration` whose payload was
`{"scenario_key":"…","scenario_version":1,"seed":"…","parameters":{"utcp_c10_proof":1}}`
— keys from descriptors, `parameters` submitted as parsed canonical JSON (an
object, not a textarea string), no adapter-specific wrapper key, no read-only
field. It produced a "RuntimeNode updated / RuntimeNode adapter configuration
saved." success notification and re-fetched only that node's three detail
endpoints (selected-node invalidation + canonical reread, no list-wide fan-out);
the textarea re-populated from canonical readback (pretty-printed). The original
value (`[]`) was restored through the same form and confirmed by reread.

## Unsupported descriptor blocking

Injecting a synthetic required descriptor with an unsupported type
(`{key:"utcp_unsupported_proof", input_type:"duration", required:true}`) into the
`asterisk-ari` catalog response (frontend-only interception) rendered a blocking
alert — "Field utcp_unsupported_proof uses unsupported type duration." plus a
form-level "Unsupported adapter configuration: utcp_unsupported_proof (duration)"
— with **no fallback input** for that field (only an alert paragraph), the seven
real fields still present, the Save button **disabled**, and **zero**
configuration requests on a save attempt. Removing the interception and reloading
restored the real seven-field form with Save enabled. This is frontend
unsupported-descriptor behavior, not a real backend descriptor.

## Write-only omission

No live write-only descriptor exists, so a synthetic optional `text` descriptor
with `write_only:true, default:null` was injected into `asterisk-ari` (frontend
only) and the save request was intercepted so it never reached the backend. The
field rendered as `type="password"` with `autocomplete="new-password"`. With the
field blank, the intercepted payload omitted the `utcp_writeonly_proof` key
(payload contained only the seven real keys). With a synthetic proof value
entered, the intercepted payload included `utcp_writeonly_proof` equal to that
value. The proof value did not appear in DOM text, URL, local storage, session
storage, or any element ID. All interception was removed and the canonical form
restored. (The proof value is not reproduced here.)

## Representative shared actions and action-scoped submission state

Three safe reversible actions were exercised:

- Simulator adapter-configuration save (above).
- Disposable user `Browser Proof User 1783998463804` suspend → activate.
- Disposable tenant `browser-proof-1783997634647` suspend → activate.

For each, only the acting control entered the submitting/"Loading" disabled
state while every other row's action buttons stayed enabled (0 disabled),
preventing duplicate submission; the canonical request succeeded; a safe success
notification appeared ("User updated / User suspended.", "Tenant updated / Tenant
status updated.", "RuntimeNode adapter configuration saved."); and the affected
canonical data re-read automatically. URL-backed list intent (search/filter/page)
was preserved, no full-page reload occurred, and no notification contained a
secret or raw sensitive payload. All disposable records were restored to their
prior state.

## Success notifications and canonical rereads

Confirmed above: each successful action emitted a `role="status"` success
notification and triggered a canonical reread of the affected list/detail without
any manual refresh, Artisan, CLI, SQL, Redis, or reconciliation command.

## Failed action behavior

A controlled `500` on a user status `PATCH` (interception, backend not executed)
left the target unchanged (still suspended), showed an error notification
("Simulated user action failure for UI-C10 proof."), produced no success, kept
other rows usable, and left the action retryable; after removing the interception
the retry succeeded and restored the user to active.

## Inline backend validation

Backend validation remained authoritative despite client hints. Two live
rejections were observed on the Asterisk catalog form:
`reconnect_min_delay_ms > reconnect_max_delay_ms` (both individually in-range) →
`422` "Minimum reconnect delay must not exceed maximum reconnect delay."; and a
length-valid but malformed `application_name` → `422` "Invalid Asterisk ARI
application name." Neither was converted into success or empty state; the user
could correct and resubmit; canonical values were unchanged (verified by
readback: `application_name` remained `utcp-t0-observation`).

Divergence: the deployed backend returns these adapter-configuration validation
errors as message-level (`{"message": …}`), not Laravel field-keyed
(`{"errors": {field: […]}}`). The frontend therefore surfaces them through the
shared action error notification rather than mapping them to a specific
descriptor field. The frontend's inline field-error mapping is demonstrated live
by client-side descriptor validation (the invalid-JSON `aria-invalid` +
described-by field error above) and exists in source (`extractApiFieldErrors`
maps field-keyed API errors to the matching descriptor field). Live inline
field-level mapping from a backend error could not be shown for catalog forms
because the backend does not emit field-keyed errors for these endpoints. This is
a backend response-shape characteristic, not a frontend defect; authoritative
backend validation with correct non-success surfacing and retryability is proven.

## One-time secret boundary

No temporary password, signaling secret, write-only value, or credential appeared
in any notification, URL, local storage, session storage, element ID, or console
throughout the proof; a direct scan for the known proof credentials and the
synthetic write-only value found none. RuntimeNode credential readback remains
fingerprint/metadata-only (write-only inputs render as `password` and are cleared
after submission). One-time signaling-credential issuance requires an active
TelephonySession, which is not safely creatable here, so the write-only credential
surface was inspected render-only and relied on automated coverage (consistent
with UI-C2).

## Tenant-switch cleanup

With a simulator node panel open under Local Tenant carrying an unsaved edited
`parameters` value (`{ broken json`) and a "Parameters must contain valid JSON."
field error, switching to Proof Tenant 1784195144 through the AppShell cleared
tenant A rows (110 → 0), closed all panels, cleared the edited form value and the
JSON error, cleared detail cache, and loaded the tenant B list with **zero**
per-node detail fan-out. Switching back to Local Tenant reloaded 110 rows and
reopening a node issued three fresh canonical detail requests for that single
node, with the reopened textarea showing the clean canonical value (`[]`), not
the discarded edit. No transient secret crossed context.

## Responsive and theme behavior

At 375px both the Asterisk form (six number inputs) and the simulator form
(text/number inputs + JSON textarea) had zero page-level horizontal overflow with
all controls within the viewport and Save reachable. Light and Dark both rendered
with zero overflow, and appearance changes made **zero** management API requests.
Appearance was reset to System.

## Console, network, and storage findings

Console errors were limited to classified/deliberate responses: the expected
pre-auth `GET /auth/session 401`; two deliberate section-14 backend `422`
rejections; and one deliberate section-13 intercepted `500`. No unhandled
promise rejections, asset failures, unexpected redirects, duplicate requests, or
unexpected sorting requests. Local storage held only `utcp.appearance`; session
storage was empty; no list, detail, credential, or secret data was persisted.

## Cleanup

Simulator configuration restored to `[]`; no disposable simulator node was
created (existing nodes used); disposable user and tenant restored to active; all
request/response interception removed; returned to Local Tenant; RuntimeNode
panels closed; unsaved form state cleared; appearance reset to System; logged out
through the AppShell; browser context closed; no screenshot, credential, or
Playwright scratch file retained; no temporary port-forward. The only residual
environment change is a benign no-op re-save of one Asterisk node's adapter
configuration (a mis-targeted early click wrote back identical values, bumping
`configuration_version` 1 → 2 with unchanged values). Web and API workloads
healthy (`web` 1/1, `api` 1/1).

## Final web health

`web` 1/1 on `sha256:7ca56c7e…` (from `6e31763`); `api` 1/1 on `sha256:7d6a24f4…`
(from `6e31763`). No other workload was restarted.

## Verification

- `apps/web`: `npm run typecheck` (clean), `npm run lint` (`--max-warnings=0`,
  clean), `npm run test` (53 passed / 6 files), `npm run build` (reproduces
  `assets/index-Tdk3r1U-.js`).
- Root: `make repository-hygiene`, `make workflow-check`, `make secret-scan` all
  passed; `git diff --check` / `git diff --cached --check` clean.
- Backend suite not re-run (evidence-only; no production backend code changed —
  the API image was built from the existing committed `6e31763`/`4eaea84`).

## Outcome

Catalog-driven RuntimeNode forms, the Asterisk handwritten-branch cutoff,
simulator JSON handling, canonical save/reread, unsupported-descriptor blocking,
write-only omission, action-scoped shared management mutations with notifications
and canonical rereads, preserved zero-fan-out lazy detail loading, tenant-switch
cleanup, secret boundaries, and responsive/theme behavior are all proven on
`6e31763`. Authoritative backend validation is proven; live inline field-level
mapping was not demonstrable for catalog forms because the backend emits
message-level errors (documented divergence; frontend behavior correct). The
current implemented UI-C management-workflow surface is standardized on the shared
contracts, so UI-C is Complete.
