# UI-D25 — Audit Records View Natural Live Proof

Verdict: `UI_D_AUDIT_LIVE_PROOF_COMPLETE`

Evidence-only controlled live proof. No production code changed. `UTCP_PHASE=T1` unchanged.

## Source Commit and Image Provenance

- Source commit: `ae5cd5f` (`feat(ui): add audit records view`), clean working tree.
- API image built from clean `ae5cd5f`:
  - registry manifest digest `sha256:9a33ef67106dfa36ea451a2f550f6f9cdfcd3a10562829f0ace68c4052cff29b`
  - `org.opencontainers.image.revision=ae5cd5fa4dbbb5191d37e05e2cdbbf8cc5e5f15f`, `image.created=2026-07-24T20:14:37Z`, `image.version=0.1.0-dev`.
- Web image built from clean `ae5cd5f` with the established WSS/Reverb coordinates (`VITE_UTCP_WS_HOST=app.utcp.local.test`, `WS_PORT=443`, `WS_SCHEME=wss`, `WS_PATH=/app`, only the public `REVERB_APP_KEY`):
  - registry manifest digest `sha256:a2f4777ca1014e735c87af279fd96a28b66e8b49d8560d445999cfad4020f849`
  - `org.opencontainers.image.revision=ae5cd5fa4dbbb5191d37e05e2cdbbf8cc5e5f15f`, `image.created=2026-07-24T20:14:54Z`.
- Gateway image was not rebuilt.

## Workloads Rolled Out and Preserved

- Migration re-run on the `ae5cd5f` image: `utcp-migrate` `Complete`, `BROADCAST_CONNECTION=log`, no Reverb credentials.
- Every Deployment carrying the mutable UTCP API image was enumerated and explicitly restarted (12): `api`, `asterisk-ari-events`, `control-plane-outbox-dispatcher`, `kamailio-registration-observer`, `reverb`, `scheduler`, `simulator-event-source`, `telephony-command-worker`, `telephony-event-normalizer`, `telephony-reconciler`, `utcp-runtime-fence-worker`, `worker`; plus `web`.
- Post-rollout digests: 14/14 API-image pods on `sha256:9a33ef67…`; 1/1 web pod on `sha256:a2f4777c…`. Zero version skew. `imagePullPolicy: Always` on all API-image workloads made the mutable-tag pull deterministic (no `crictl` intervention required).
- Not restarted / preserved: PostgreSQL, Redis, Gateway, Traefik, Asterisk, Kamailio, observability. Reverb remained ClusterIP `8080`; public WSS remained Traefik `443`.
- Baseline repair: the rendered Kubernetes API-server egress policy `allow-runtime-fencer-kubernetes-api` was stale (`172.24.0.5/32` vs live `172.24.0.2/32`); repaired canonically via `scripts/security/render-apiserver-policy` + `kubectl apply` of the rendered artifacts; drift check then `passed endpoint=172.24.0.2/32:6443`.
- No failed jobs; pending outbox `0` before and after.

## Canonical Audit APIs

- Unauthenticated `GET /api/v1/admin/audit-records` and `/{id}` returned `401` (never `500`).
- After natural authentication + tenant activation: list `200`, detail `200`.
- List envelope: `audit_records`, `pagination.{page,per_page,total,has_more}`. Detail envelope: bounded safe contract only (see below).
- Ordering `occurred_at desc, id desc`. No SQL/authorization/serialization/tenant-scope errors in the API log across the session.

## Natural Login and Tenant

- Real Login page → `admin@utcp.local.test` via a bounded break-glass temporary credential → forced password change completed through the UI → `Local Tenant` selected through AppShell. No injected cookies/sessions/storage; no bypass.

## Audit Route and Authorization

- Before any active tenant, the AppShell primary nav showed only Dashboard/Tenants/Users — the `Audit records` link was absent (route/nav require `requiresActiveTenant` + `tenant.memberships.manage`).
- After tenant activation the `Audit records` link appeared; entered by clicking the actual AppShell link → `/admin/audit-records`.
- Route is read-only: only Refresh, Apply, Clear, per-row Details, pagination, and Rows-per-page controls exist. No create/edit/delete/redact/replay/retry/prune/export/bulk-download control. `POST`/`DELETE` on the API routes return `405` (automated coverage).
- Zero Audit WebSocket subscriptions were ever created (`window.WebSocket` wrapper counted `ws=0` throughout). No polling/timer began.
- Missing-capability route gating: the admin holds `tenant.memberships.manage` on both available tenants, so no sanctioned low-privilege identity/tenant was available; per instruction no permission was created to manufacture the check. The `requiresActiveTenant` gate was proven live (nav hidden pre-tenant); missing-capability denial is covered by automated tests.

## Initial Request Budget

- On route entry: Audit list requests = **1** (`/api/v1/admin/audit-records?page=1&per_page=20`), Audit detail requests = **0**, WS = 0.
- Total Audit records (Local Tenant): 2834; page 1; per_page 20 (default); Page 1 of 142. Request count does not scale with record count.

## Pagination (server-backed)

- Next → `?page=2` (URL-backed): 1 list request (`page=2&per_page=20`), 0 detail, Page 2 of 142.
- Previous → page 1: 1 list request, rows restored, URL clean.
- Rows-per-page 50 → 1 list request (`page=1&per_page=50`), 50 rows rendered, Page 1 of 57. One list request per explicit page/size change; zero detail during pagination; no full-dataset download.

## Filters

Each filter: exactly 1 list request, 0 detail, page reset to 1, canonical query parameter, returned records satisfy the filter, deterministic clear.

| Filter | Query parameter | Result |
|---|---|---|
| action | `action=identity.logout` | page 3→1, Page 1 of 4, 20/20 rows match |
| actor_type | `actor_type=user` | Page 1 of 129, all rows match |
| actor_id | `actor_id=5f47ae5f-…` | Page 1 of 102, all rows match |
| subject_type | `subject_type=runtime_node` | Page 1 of 39, all rows match |
| subject_id | `subject_id=056c6db0-…` | Page 1 of 1, 11 rows, all match |
| correlation_id | `correlation_id=45a313c5…` (32 hex) | Page 1 of 1, 1 row |
| request_id | `request_id=2b7f68fc…` (32 hex) | Page 1 of 1, 1 row |
| occurred_from + occurred_to | `occurred_from=2026-07-24T04:27:00Z&occurred_to=2026-07-24T04:28:30Z` | Page 1 of 1, 2 rows both within interval |

Clear returned to the full 2834-row list (Page 1 of 142), field emptied, 1 list request.

## Validation

- Invalid `correlation_id=not-a-valid-hex-value` → HTTP **422**, body `{"message":"The correlation id field format is invalid.","errors":{"correlation_id":[…]}}`; visible message "Audit records unavailable — The correlation id field format is invalid."; the previous canonical 20-row list remained visible. Clearing recovered the full list.

## Selected Detail Budget

- Select one record → 1 detail request, 0 list rereads.
- Select a different record → only the new id fetched (1 detail).
- Close detail → 0 list rereads.

## Safe Actor/Subject, Outcome, Reason, Metadata Rendering

- Detail contract (API + DOM) limited to: `id`, `action`, bounded `actor{type,id}`, bounded `subject{type,id}`, `outcome`, `correlation_id`, `request_id`, `occurred_at`, `created_at`, `reason`, sanitized `metadata`.
- Example record `aff3ab0a…` (`runtime_node.desired_state_changed`): metadata rendered as a bounded safe map `from: active`, `to: disabled`; reason `None`; outcome text `No outcome`; actor/subject shown as `type id`; no unrestricted raw JSON dump.
- Outcome conveyed as text (not color alone).
- Deleted/unavailable actor/subject references remain readable via stored type + identifier values (the resource reads stored columns, not joins).

## Sensitive Field Exclusion

- Programmatic scan of 25 detail records spanning 3 distinct actions (`identity.tenant_context.selected`, `identity.logout`, `runtime_node.desired_state_changed`): **0 violations** — no key outside the allowed set, no sensitive key (`password/secret/token/authorization/cookie/private_key/credential/request_body/stack_trace/outbox_body/desired_state/observed_state/provider_response/api_key/env/bearer`), no oversized/raw-dump payload.

## Idle Automatic-Refresh

- Route mounted with active filter (`subject_type=runtime_node`), page 2 of 39, and a selected detail; counters cleared; observed **125.1 s**:
  - automatic Audit list requests: 0
  - automatic Audit detail requests: 0
  - Audit WebSocket subscriptions: 0
  - timer/visibility-driven refreshes: 0; state preserved (page 2, detail open).
- Deterministic refresh model confirmed: canonical append → no notification/poll → operator Refresh → one canonical reread.

## Canonical Audit Append Source

- Repository authority: `RuntimeRegistryService::changeDesiredState()` → `emit()` → `AuditRepository::append($context, 'runtime_node.desired_state_changed', 'runtime_node', $nodeId, $payload)` (`apps/api/app/RuntimeRegistry/RuntimeRegistryService.php:786`).
- Web Admin action: on a disposable simulator-deterministic RuntimeNode `Local Deterministic Simulator` (`0e109001-…`, `simulator`/`simulator-deterministic`), clicked **Activate** through the Runtime Nodes admin route in a second browser tab (primary Audit tab held stable). PATCH `/api/v1/admin/runtime-nodes/0e109001-…/desired-state` → 200.

## New Audit Record Evidence (read-only server evidence)

- Local Tenant audit count 2834 → 2835 (+1).
- New record: id `367e68397b2ae4ca21fb144ea12038f4`, action `runtime_node.desired_state_changed`, actor `user 5f47ae5f-…`, subject `runtime_node 0e109001-…`, tenant `7be59d2a-…` (Local), occurred `2026-07-24 20:30:27Z`, metadata `{"data":{"from":"disabled","to":"active"},"version":1}`.

## No Automatic Appearance

- After the mutation, switching focus to the primary Audit tab (a visibility change) and holding 30 s: Audit list requests = 0, detail = 0, WS = 0; the new record did **not** auto-insert; page 2 / filter / open detail preserved.

## Explicit Refresh and Preservation

- Refresh on page 2 (query excluding the new record): 1 list reread, 0 detail fan-out; page 2, per_page 20, and `subject_type=runtime_node` all preserved; new record correctly absent.
- Moved via explicit controls to page 1 (query including the new record) and Refreshed once: 1 list reread; the new record appeared at row index 0 straight from the canonical list response (no browser-created row, no realtime).
- Selecting the new record → 1 detail request (`/audit-records/367e6839…`); detail showed the canonical safe fields (`from: disabled`, `to: active`).

## Failed-Refresh (bounded browser interception)

- Playwright `page.route` returned `500` for a single Audit list GET (detail untouched, no backend change): the previous canonical list stayed visible (20 rows) and a visible "Audit records unavailable" error surfaced. Interception removed; one Refresh → one successful canonical list request; list restored.

## Logout

- AppShell Log out → `POST /auth/logout` **200**; post-logout Audit list = 0, detail = 0; real Login page shown. Audit has no channel to unsubscribe and no reconnect occurred.

## Storage Boundary

- Local/session storage before entry, after list, after filters/detail, after mutation+Refresh, and after logout contained only `utcp.appearance` and the metadata-only vendor `pusherTransportTLS`. A forbidden-substring scan (record ids, actor/subject/correlation/request ids, `desired_state_changed`, `audit`, `tenant_id`) returned no hits. Filter state lived only in the URL query (`subject_type`, `page`, `per_page`, etc.).

## Responsive

- At 375 px, Light and Dark: `document.documentElement.scrollWidth === window.innerWidth` (375) — zero root overflow; selected detail within the viewport; outcome as text; theme changes (System→Dark→Light) caused 0 Audit requests. Appearance reset to System.

## Console, API, PostgreSQL, and Network Findings

- Console errors: 3, all explained — a pre-login `401` `/api/v1/auth/session` probe, the intentional `422` invalid-filter test, and the intentional bounded `500` interception test. No unexplained errors.
- API log: no exception/SQLSTATE/serialization/authorization failures. No failed jobs. Pending outbox `0`. No unexpected requests; request budgets exactly as recorded.

## Cleanup

- Runtime node restored to `desired disabled` via the UI **Disable** action (PATCH 200); both proof audit records (`{from:disabled,to:active}` and `{from:active,to:disabled}`) retained — no Audit history deleted or rewritten. Audit count 2837 reflects activate + one tenant-reselect + disable appends, all canonical.
- Audit filters cleared, pagination returned to page 1 / per_page 20, detail closed, appearance System, both browser contexts logged out and closed, request interceptions and observers removed, `.playwright-mcp/` removed, no scratch/screenshot/credential files left. All Deployments healthy; preserved workloads untouched.

## Final UI-D Completion Assessment

- The Audit live proof passes on all completion criteria.
- The UI-D horizontal operational surfaces achievable at `UTCP_PHASE=T1` are all live-proven end to end: RuntimeNode realtime (UI-D8/D12), Conference/participant (UI-D10/D12), Runtime Operations (UI-D17), Runtime Reconciliations (UI-D22), and Audit (this proof, UI-D25). Shared Reverb/WSS plumbing, reconnect/stale presentation, tenant isolation, logout teardown, request budgets, storage boundaries, and 375 px hygiene are established across these surfaces.
- The only remaining `Included Scope` items — live TelephonySession state and runtime/listener-health operational views — are, per the UI-D `Dependencies` note, gated on `T2`/`T3`/`V0` and later call-control phases and are not buildable at `T1`; they are carried by those phases, not as an open UI-D-at-T1 requirement.
- Decision: **UI-D = Complete** for its T1-achievable scope. No concrete, currently-actionable UI-D roadmap requirement remains unsatisfied. `UTCP_PHASE=T1` is unchanged; UI-E remains In Progress.

## Verification

- `php artisan test tests/Feature/ControlPlane/AuditRecordReadApiTest.php tests/Feature/ControlPlane/MessagingAndAuditTest.php` — audit read + messaging pass (postgres-only case skipped under SQLite).
- `php artisan test` — 402 passed, 6 skipped, 0 failed.
- `make control-plane-test` — PostgreSQL suite passed, including `AuditRecordPostgresReadApiTest` ("audit list and detail execute against postgres with safe contract") and the append-only redaction case.
- `vendor/bin/pint --test` — passed.
- `apps/web`: `npm run typecheck`, `npm run lint`, `npm run test` (98 passed), `npm run build` — all passed.
- Running API and web images derive from `ae5cd5f`; no production code changed; no Audit realtime/polling/timer path exists; no Audit history deleted; `UTCP_PHASE=T1` unchanged.
