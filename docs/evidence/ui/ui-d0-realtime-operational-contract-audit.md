# UI-D0 — First Real-Time Telephony Operational Experience Slice (Contract Audit)

Verdict: `UI_D0_REALTIME_OPERATIONAL_CONTRACT_AUDIT_COMPLETE`

Audit type: narrow evidence audit (evidence-only; no production code changed).
Starting commit: `03d9a86`. Phase marker: `UTCP_PHASE=T1` (unchanged).
Track: horizontal UI foundation (UI-A..UI-E). This is **not** part of T1/T2/T3/V0/T5 domain work and
does not reopen UI-A, UI-B, or UI-C.

UI foundation status is unchanged by this audit:

```text
UI-A = Complete
UI-B = Complete
UI-C = Complete
UI-D = In Progress
UI-E = In Progress
```

---

## 1. Purpose

UI-D ("Real-Time Telephony Operational Experience") requires that operator screens reflect live
telephony/runtime state while backend APIs remain the canonical authority. This audit establishes the
exact real-time contracts that exist today and selects **one** first bounded implementation slice with
exact acceptance criteria. It does not implement production code.

---

## 2. Method

Four read-only inventories were run against the working tree at `03d9a86`, plus direct route/config
verification:

1. Frontend real-time capability (`apps/web/`).
2. Backend broadcasting/event infrastructure (`apps/api/`).
3. Telephony/operational domain-event sources and canonical snapshot (read) APIs.
4. Reverb/WebSocket transport deployment in the local k3d runtime and intended deployment.

---

## 3. Frontend real-time capability inventory

**Finding: the frontend has zero real-time infrastructure. All data flows through a single
request/response `fetch` layer.**

| Concern | Present? | Evidence |
| --- | --- | --- |
| Real-time client dep (Echo/pusher-js/socket.io/SSE) | No | `apps/web/package.json` deps are only `vue`, `vue-router`. The `websocket`/`reconnect_*` strings in the app are Asterisk-ARI **config field keys** rendered in Runtime Node forms, not client libraries. |
| Event-subscription code (`new WebSocket`, `EventSource`, `echo.channel`, `.subscribe()`) | No | None in `apps/web/src`. `RuntimeNodesView.vue:227-251` `<option value="events"|"wss">` are endpoint-type form choices, not a live connection. |
| Polling loop (`setInterval`, focus-refetch) | No | No `setInterval`; the only `setTimeout` uses are toast auto-dismiss (`state/notifications.ts:23,55`). |
| Connection / reconnect state model | No | `composables/asyncState.ts`, `composables/listQueryState.ts` model request state only. |
| Event ordering / dedup | No | No event stream exists. |
| Tenant-switch teardown hook | Yes (HTTP state only) | `state/appState.ts:209` `switchTenant()` → `selectTenant`, `tenantContextVersion += 1`, `clearOneTimeSignalingCredential()`, `clearRuntimeNodeDetails()`. |
| Logout teardown hook | Yes (HTTP state only) | `state/appState.ts:174` `endSession()` → `logout`, clears session, signaling credential, runtime-node details, notifications. |
| Operational view consuming live events | No | `RuntimeNodesView.vue` is request/response only. |
| HTTP client / API layer | `fetch` wrapper | `api/platform.ts:318` `fetchJson<T>()` → `fetch(...)` at `:323` (`credentials: 'same-origin'`); base URL `apiBaseUrl()` `:316` from `VITE_UTCP_API_BASE_URL`. |

**Consequence:** there is no client to reuse; a first slice must introduce the client library, a
connection/reconnect state model, and subscription lifecycle. The existing `switchTenant()` and
`endSession()` teardown points are the clean, already-centralized hooks for subscription lifecycle.

---

## 4. Backend broadcast infrastructure inventory

**Finding: no Laravel broadcasting is wired. Broadcasting is hard-set to the `log` driver everywhere.**

| Concern | State | Evidence |
| --- | --- | --- |
| Broadcast package (reverb/pusher/ably) | Absent | `apps/api/composer.json` requires only `laravel/framework ^12`, `laravel/tinker`. Only `composer.lock` *suggestion* strings mention pusher. |
| `config/broadcasting.php` | Absent | No file under `apps/api/config/`. |
| `config/reverb.php` | Absent | No file. |
| Default broadcast driver | `log` | `apps/api/.env.example:43` and deployed overlay `infrastructure/kubernetes/overlays/local/platform/application-config.properties:30` → `BROADCAST_CONNECTION=log`. |
| `routes/channels.php` / `Broadcast::routes()` / `broadcasting/auth` | Absent | `apps/api/routes/` has only `api.php`, `console.php`, `web.php`. `bootstrap/app.php:12` registers no channels/broadcast routes. No `BroadcastServiceProvider`. |
| `ShouldBroadcast` / `broadcastOn` implementations | None | Zero matches across `apps/api/app`. There is **no `app/Events`, `app/Listeners`, or `app/Broadcasting` directory.** |
| Queue for broadcasts | Present (usable), driver inert | Deployed overlay `QUEUE_CONNECTION=redis` (`application-config.properties:24`), Redis at `redis.utcp-data.svc.cluster.local:6379`; a `queue:work` worker is deployed (`worker-deployment.yaml:33-35` → `entrypoint:65-67`). A queued `ShouldBroadcast` job would run, but resolve to the `log` driver, not a socket. |

**Reconciliation note (do not treat `apps/api` as a skeleton):** the backend is a full modular monolith with
rich domain code organized by module namespace — `app/ControlPlane`, `app/Identity`,
`app/RuntimeRegistry`, `app/RuntimeEngine`, `app/TelephonyDomain`, `app/Simulator`,
`app/RuntimeAdapters`. It emits many domain events (§5) through a transactional outbox, not through
Laravel broadcasting. The correct statement is narrow: **broadcasting/real-time push is absent**, while
the canonical domain-event and snapshot substrate is present and mature.

**Config divergence worth noting:** `.env.example:45` shows `QUEUE_CONNECTION=sync`, but the deployed local
overlay uses `redis`. The deployed value governs the runtime; a queued broadcast will be processed by the
running worker.

---

## 5. Telephony operational event-source matrix

All domain events flow through a **transactional outbox** (`control_plane_outbox_messages`, via
`app/ControlPlane/Messaging/OutboxRepository.php:12` + `EventEnvelope.php:11`), most dual-written to an
immutable **audit** table (`control_plane_audit_records`). Runtime-sourced facts additionally land in an
**observation/projection** pipeline (`runtime_observations` → `RuntimeEngine/Projection/ProjectionService`
→ read-model tables). None are broadcast.

Legend: **DE** = outbox domain event, **DE+A** = outbox + audit, **OBS** = observation/projection,
**A-only** = audit log only.

| Event(s) | Producer (file) | Entity | Trigger | Scope | Persistence | Broadcast |
| --- | --- | --- | --- | --- | --- | --- |
| `runtime_node.created/updated/desired_state_changed/endpoints_changed/capabilities_changed/credential_*/restored` | `RuntimeRegistry/.../RuntimeRegistryService.php:786` (`emit`) | runtime_node | Admin registry mutations | tenant | DE+A | No |
| `runtime_node.asterisk_ari_configuration_changed` / `simulator_configuration_changed` | `AsteriskAriProfileService.php:109`, `SimulatorProfileService.php:205` | runtime_node | Adapter config change | tenant | DE+A | No |
| `runtime_node.event_listener_degraded/recovered` | `AsteriskAriEventListener.php:653/665` | runtime_node | ARI listener health transition | tenant | DE | No |
| `runtime_operation.completed` + `asterisk_conference_*/participant_*/simulator_completed` | `CommandWorker.php:71`, `RuntimeOperationRepository::complete:184/210` | runtime_node / conference / participant | Command worker completes an operation | tenant | DE | No |
| `conference.*` (`created/desired_state_changed/ended/runtime_binding_changed/runtime_fence_*`), `conference_participant.admitted/removed`, failover transitions | `TelephonyDomain/.../TelephonyDomainService.php:1747` + `508/726/811/991`, `ConferenceFailoverCoordinator.php:203` | conference / participant | Conference & failover lifecycle | tenant | DE+A | No |
| `telephony_session.expired` | `TelephonyDomainService.php:177` | telephony_session | TTL expiry sweep | tenant | DE+A | No |
| `telephony.signaling_credential.issued/revoked` | `SignalingCredentialService.php:97/159` | telephony_session | Credential lifecycle | tenant | A-only | No |
| Runtime **OBSERVATIONS** — `runtime.readiness/capability/connection/configuration/event_stream.observed`, `conference.lifecycle/participant.observed` | `AsteriskAriEventListener.php:637` → `EventNormalizer` → `ProjectionService::apply` | runtime_node / conference / participant | Asterisk ARI websocket events | tenant | OBS → `runtime_observations` (projected onto `conferences.observed_state`, `conference_participants.observed_state`) | No |
| Registration **OBSERVATIONS** — `signaling.registration.observed` from `kamailio.registration.accepted/refreshed/replaced/removed/expired` | `KamailioRegistrationSnapshotDiffer.php`, `KamailioRegistrationObserver.php` | signaling registration | Poll+diff of Kamailio registrar | tenant | OBS → `signaling_registration_observations` + `runtime_projection_checkpoints` (has `sequence`) | No |
| Identity actions (`identity.login.*`, `logout`, `tenant_context.selected`, `user/tenant/membership/*role*` ) | `AuthController.php`, `IdentityAdminService.php`, `ResetUserPasswordService.php` | user/tenant/membership | Auth & admin ops | mixed | A-only | No |

Not events: `conference_recovery_metric_events` (metric rows) and Prometheus text (`MetricsController`).

**Ordering substrate:** the outbox has **no monotonic global sequence** (32-char token id; ordering is
`available_at, created_at`). Only the observation checkpoint table (`runtime_projection_checkpoints`) has a
`sequence` column, and it is internal to projection. There is no per-event id/version exposed to any client.

---

## 6. Snapshot (read) API matrix

All under `identity.session` middleware; tenant scope from `session('active_tenant_id')` (409 if absent);
per-endpoint capability via `AuthorizationService::requireTenant(userId, tenantId, capability)`
(`apps/api/app/Identity/Authorization/AuthorizationService.php:54`). Routes: `apps/api/routes/web.php`.

| Method + path | Capability | Scope | Response / freshness key | Pagination |
| --- | --- | --- | --- | --- |
| GET `/api/v1/admin/runtime-nodes` | `runtime.nodes.view` | tenant | `{runtime_nodes:[...]}` each with `configuration_version`, `observed_state`, `observed_at`, `updated_at` | none |
| GET `/api/v1/admin/runtime-nodes/{id}` | `runtime.nodes.view` | tenant | full node (endpoints, credentials, capabilities); `configuration_version`+`updated_at` | n/a |
| **GET `/api/v1/admin/runtime-nodes/{id}/runtime-evidence`** | `runtime.nodes.view` | tenant | **health aggregate**: `desired_state`, `observed_state`, `observed_at`, desired/observed generation, `listener{status,lease_freshness}`, `connection{state,latest_event_at}`, `reconciliation{state,last_evaluated_at,next_retry_at,failure_*}`, `inspection{...}` | n/a |
| GET `/api/v1/admin/runtime-nodes/{id}/history` | `runtime.nodes.view` | tenant | audit-derived history | `limit`≤100, `before` cursor |
| GET `/api/v1/admin/runtime-node-catalog` | `runtime.nodes.view` | tenant | static enums | n/a |
| GET `/api/v1/admin/conferences` (+ `/api/v1/conferences`) | `telephony.conferences.view` | tenant | `{conferences:[...]}` with `desired_state`, `observed_state`, `observed_generation`, `observed_at`, failover fields, `updated_at` | none |
| GET `/api/v1/admin/conferences/{id}` (+ non-admin) | `telephony.conferences.view` | tenant | conference detail (same serializer) | n/a |
| GET `/api/v1/admin/conferences/{id}/participants` | `telephony.conferences.participants.manage` | tenant | participants with `desired/observed_state`, `role`, `joined_at`, `left_at`, `failure_*`, `updated_at` | none |
| GET `/api/v1/telephony/sessions/current` | `telephony.sessions.view_own` | tenant + **own user** | `{telephony_session:{id,status,issued_at,expires_at,ended_at}}` | n/a |

**Snapshot gaps (no GET endpoint):** admin TelephonySession list/detail-by-id; calls/call-legs (no entity);
signaling registration state (data exists, no route); standalone reconciliation/convergence; runtime
operations list; audit log. Building any of these would be **new backend authority** and is out of scope
for a first slice.

---

## 7. Reverb / WSS deployment status

| # | Component | Status | Evidence |
| --- | --- | --- | --- |
| 1 | Reverb server workload | **absent** | No `reverb:start` role; `infrastructure/docker/api/entrypoint` roles are api/worker/scheduler/migrate/outbox-dispatcher/command-worker/infrastructure-worker/event-normalizer/reconciler/simulator-event-source/asterisk-ari-events/kamailio-registration-observer. No Deployment references reverb. |
| 2 | Queue worker for broadcasts | **implemented and deployed** (path inert) | `worker-deployment.yaml:33-35` → `queue:work` (`entrypoint:65-67`), `QUEUE_CONNECTION=redis`. Broadcasts would still hit the `log` driver. |
| 3 | WS Service + Gateway/HTTPRoute + TLS | **absent** | Services expose web `8080`, api `9000`, gateway `8080/8081`. Only HTTPRoute is host `app.utcp.local.test` → `gateway:8080` (`httproute-app.yaml`); gateway nginx (`infrastructure/docker/gateway/nginx.conf:40-64`) proxies `/api/`→api and `/`→web with **no `/app`/`/ws` or `Upgrade` handling**. TLS terminates at Gateway `websecure` (443), secret `utcp-local-gateway-tls`. |
| 4 | Channel-auth endpoint (`broadcasting/auth`) | **absent** | No such route, no `Broadcast::routes()`. |
| 5 | Frontend WS env (`VITE_*`) | **absent** | Web image bakes only `VITE_UTCP_API_BASE_URL` + build metadata (`infrastructure/docker/web/Dockerfile:19-26`). No WS var, no `laravel-echo`/`pusher-js`. |
| 6 | NetworkPolicy for a reverb pod | **absent** | Default-deny model (`security/platform/default-deny.yaml`); worker denies all ingress; no `reverb` role/policy exists. |
| 7 | Reverb health/readiness | **absent** | No reverb workload. |

**Summary:** transport readiness is effectively zero. The live UI is a static SPA served by nginx that
talks to the API purely over HTTP through Traefik at `app.utcp.local.test`.

---

## 8. Delivery, ordering, and canonical-resync semantics (stated without invention)

**Today:** there is no delivery mechanism at all beyond HTTP request/response. Domain events are durably
persisted in the outbox but not pushed, and carry no client-facing sequence/version.

**After the selected slice** (Reverb over the Pusher protocol), the honest guarantees are:

- **Best-effort, at-most-once** notification delivery. No replay, no server-side buffering of missed
  messages, no gap detection in the protocol.
- **In-order within a single channel** as sent by the server; **no cross-channel or global ordering**, and
  **no sequence numbers** exposed to the browser (the outbox has none; observation `sequence` is internal).
- Therefore the browser must never treat a message as state.

**Mandated UI failure behavior (deterministic default):**

```text
event received           → invalidate + reread the affected canonical snapshot (never apply payload as state)
gap / reconnect / ambiguity → full canonical reread of the current view
broadcast unavailable    → show disconnected/stale state; do NOT invent operational state
```

**Canonical resynchronization authority:** the snapshot GETs in §6 (`runtime-nodes` list, `runtime-nodes/{id}`,
`runtime-nodes/{id}/runtime-evidence`). The WebSocket stream is a notification, never canonical.

---

## 9. Channel authorization and tenant/logout lifecycle

**Channel model:** a **private, tenant-scoped** channel, e.g. `tenant.{tenantId}.runtime-nodes`. Authorization
must reuse the server identity authority: the `broadcasting/auth` callback resolves the authenticated user
and the session `active_tenant_id`, and calls
`AuthorizationService::requireTenant(userId, tenantId, 'runtime.nodes.view')`
(`AuthorizationService.php:54`) — identical to the snapshot endpoint. A tenant the user does not belong to,
or a missing capability, must be denied. The frontend must never become authorization authority.

**Lifecycle (wired into existing hooks):**

```text
login → session → select tenant → snapshot GET → authorize+subscribe tenant channel → on event: reread
tenant switch (appState.ts:209)  → unsubscribe old tenant channel, resubscribe new (tenantContextVersion++)
logout (appState.ts:174)         → disconnect client entirely
session rejection / 401          → disconnect; show disconnected state
channel-auth denial              → show degraded/disconnected; do not invent state
tab background/resume            → on resume, reread current snapshot (reconnect implies gap)
```

No protected event payload is persisted to local/session storage (the selected event is notification-only
metadata; nothing is stored). Storage boundary remains `utcp.appearance` only, as proven in UI-C.

---

## 10. Candidate UI-D slice comparison

| Candidate | Snapshot | Domain event | Broadcast | Authz clarity | Deploy | Payload | Resync clarity | UI seam | Testability | Portfolio | Size |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **RuntimeNode health/readiness (Runtime Nodes view)** | ✅ list+detail+**evidence** | ✅ `runtime_node.*` (outbox) | ❌ | ✅ `runtime.nodes.view` | ❌ | ✅ notification-only trivial | ✅ reread evidence | ✅ **existing** `RuntimeNodesView` + `appState` hooks | ✅ high | ✅ high (extends proven view) | medium |
| Conference + participants | ✅ list+detail+participants | ✅ `conference.*` (outbox+OBS) | ❌ | ✅ conf caps | ❌ | ✅ | ✅ reread | ❌ **no operational view exists** (new screen) | ✅ | ✅ | large; depends on T2/T3 |
| TelephonySession | ⚠️ `current`/own only, no list | ⚠️ only `expired` | ❌ | ✅ | ❌ | ✅ | ⚠️ thin | ⚠️ | med | med | reject — snapshot too thin |
| Signaling registration | ❌ **no GET** | ✅ observations | ❌ | n/a | ❌ | — | — | — | — | — | reject — needs new snapshot authority |
| Runtime operations / reconciliation / audit | ❌ no GET | ✅ events | ❌ | n/a | ❌ | — | — | — | — | — | reject — needs new snapshot authority |

Every candidate shares the same missing layers (broadcast + transport + client). RuntimeNode is the only
entity with a mature list+detail+**health** snapshot *and* a live domain-event source *and* an existing,
already-browser-proven operational view to enhance — matching the task's own suggested safe example.

---

## 11. Selected first UI-D slice

**Selected: UI-D1 — Real-time broadcast + transport foundation, delivering live RuntimeNode operational
notifications, consumed by the existing Runtime Nodes view.**

Because the entire real-time stack (broadcast event, channel, transport, client) is absent, the first slice
is a single coherent **vertical foundation** for one entity. It invents no new backend authority: the
canonical state (RuntimeNode desired/observed + `runtime-evidence`) and its snapshot APIs already exist; the
slice adds a **notification-only** push layer on top and a client that **rereads canonical snapshots**.

Consistent with the repository's own decomposition rhythm (UI-C8 published a backend contract, UI-C9
consumed it, UI-C10 proved it), this slice is scoped to the backend + transport + a minimal, provable
frontend consumption on the one existing operational view, with the natural follow-ups (Conference/
participant live views, richer reconnect UX, additional operational snapshots) deferred to later UI-D slices.

---

## 12. Exact implementation boundary

**Backend (`apps/api`)**

1. Add `laravel/reverb` to `composer.json`; publish `config/broadcasting.php` and `config/reverb.php`
   (env-driven host/port/scheme/app keys).
2. Register broadcasting: enable `withBroadcasting`/`Broadcast::routes` behind the existing
   `identity.session` middleware so `broadcasting/auth` is session-authenticated; add `routes/channels.php`
   with one private channel `tenant.{tenantId}.runtime-nodes` whose authorization calls
   `AuthorizationService::requireTenant($userId, $tenantId, 'runtime.nodes.view')` using the session
   `active_tenant_id`.
3. Add one broadcast notification event (e.g. `RuntimeNodeOperationalStateChanged`) implementing
   `ShouldBroadcast`, queued on the deployed Redis worker, dispatched **after DB commit** from the existing
   canonical seam that appends `runtime_node.*` outbox events (`RuntimeRegistryService::emit`, and the
   observed-state projection path for listener/connection/reconciliation transitions). Payload is
   **notification-only**: `event_type`, `aggregate_type='runtime_node'`, `runtime_node_id`, `tenant_id`,
   `occurred_at`. **No secrets, credentials, endpoints, or full state.** Broadcast failure must not affect
   the canonical mutation (queued + isolated).
4. Set `BROADCAST_CONNECTION=reverb` in the deployed overlay (canonical config, not a feature gate).

**Infrastructure (`infrastructure/`)**

5. Reverb Deployment + Service (add a `reverb` role to `infrastructure/docker/api/entrypoint` running
   `php artisan reverb:start`), with readiness/liveness probes.
6. Gateway routing: add an `Upgrade`-aware `/app` WebSocket location in `infrastructure/docker/gateway/nginx.conf`
   proxying to the reverb Service (the existing `app.utcp.local.test` HTTPRoute already routes the whole host
   to `gateway:8080`, so no new HTTPRoute host is required — only the nginx upgrade location + reverb Service).
7. NetworkPolicy `allow-reverb` under the default-deny model: ingress from the gateway; egress to DNS and
   Redis (Reverb pub/sub scaling + broadcast).

**Frontend (`apps/web`)**

8. Add `laravel-echo` + `pusher-js` (Reverb speaks the Pusher protocol); add a `VITE_UTCP_WS_*` build arg
   consumed like `VITE_UTCP_API_BASE_URL`.
9. Add a connection/reconnect state model and an Echo lifecycle module; subscribe to
   `tenant.{activeTenantId}.runtime-nodes` after tenant selection; on notification, **invalidate + reread**
   the current Runtime Nodes snapshot/`runtime-evidence` through the existing `platform.ts` client
   (never apply the payload as state).
10. Wire teardown into the existing hooks: `switchTenant()` (`appState.ts:209`) unsubscribes/resubscribes on
    tenant change; `endSession()` (`appState.ts:174`) disconnects. Present a disconnected/stale badge on the
    Runtime Nodes view when the socket is down; keep canonical data visible.

**Non-goals for this slice:** Conference/participant/session/operations/audit live views; new snapshot
endpoints; global ordering or replay; any second management authority; any environment feature gate.

---

## 13. Automated acceptance criteria

Backend (PHPUnit):

- Channel auth **allows** a member of the tenant with `runtime.nodes.view`; **denies** a non-member and a
  member lacking the capability.
- The event implements `ShouldBroadcast`, targets the correct `tenant.{tenantId}.runtime-nodes` private
  channel, and its payload contains only the notification metadata — **no secret/credential/endpoint/full
  state** field.
- The event is dispatched after commit from the `runtime_node.*` canonical seam; a simulated broadcast
  failure leaves the canonical mutation and outbox row intact.
- `broadcasting/auth` requires the `identity.session` middleware (unauthenticated → 401/403).

Frontend (Vitest):

- Initial render performs the canonical snapshot request(s) before subscribing.
- A received notification triggers exactly one canonical reread of the affected resource (no per-event
  request fan-out; a burst coalesces to a bounded reread).
- Duplicate notification → still exactly one reread (idempotent invalidate).
- Out-of-order/stale notification → resolved by canonical reread (snapshot wins; UI never applies payload).
- Reconnect → full canonical reread of the current view.
- Tenant switch unsubscribes the old channel and resubscribes the new; logout disconnects.
- Capability/channel-auth denial → disconnected/degraded state, no invented data.
- Disconnected socket → stale badge, last canonical data preserved.
- No protected event data written to local/session storage (storage boundary stays `utcp.appearance`).
- Existing UI-C behavior preserved: RuntimeNode initial 2-request budget with zero per-node detail fan-out
  is not regressed by subscription setup.

---

## 14. Later natural browser proof (do not run in this audit)

A subsequent Playwright MCP proof must start at the real Login page, authenticate normally, select tenant
context through the UI, open Runtime Nodes, confirm the socket connects, then cause a canonical
`runtime_node.*` change through the normal API/UI and observe the view reread and reflect it live; prove
tenant-switch channel isolation, logout disconnect, a disconnected/stale badge under socket loss with
canonical data preserved, and no secret/event data in storage, IDs, URL, or console. Deployed web + api +
reverb digests are recorded at proof time.

---

## 15. Delivery decision

- **Outcome:** bounded backend + transport + minimal-frontend implementation, target fully specified
  (§12–§13). Maps to a combined Outcome B (broadcast event/channel) + Outcome C (Reverb/WSS transport).
- **Selected AI coder:** **Codex** — bounded implementation with a known target, per the CLAUDE.md role
  split (Claude Code is reserved for audits/live-proof/diagnosis; bounded implementation with a clear target
  goes to Codex).
- **Remaining proof gaps:** none blocking implementation. The live WSS delivery + browser reread behavior is
  a post-implementation controlled proof (§14), not an audit gap.

---

## 16. Verification performed

Evidence-only. Commands: `git status --short`, `git log`, `grep UTCP_PHASE versions.env`, plus
`git diff --check`, `git diff --cached --check`, `make repository-hygiene`, `make workflow-check`,
`make secret-scan`. No production files modified.
