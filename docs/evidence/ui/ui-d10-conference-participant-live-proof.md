# UI-D10 — Conference and Participant Live Operations Proof (BLOCKED on reconnect resynchronization)

Verdict: `UI_D_CONFERENCE_PARTICIPANT_LIVE_PROOF_INCOMPLETE`

Proof type: controlled natural browser proof.
Source commit: `39627da` (`feat(ui): add live conference operations`).
Foundation: [`ui-d8-runtime-node-live-behavior-proof.md`](ui-d8-runtime-node-live-behavior-proof.md).
Phase marker `UTCP_PHASE=T1` (unchanged). Kubeconfig `.runtime/kubeconfig/utcp-local.yaml`, context
`k3d-utcp-local`. No production code was modified. No database schema changed.

**The Conference/participant notification corridor itself is fully proven** — channel authorization,
subscription, both `conference.*` and `conference_participant.*` deliveries, approved-only envelopes,
scoped canonical rereads, and zero fan-out all pass, and the RuntimeNode duplicate-request follow-up is
confirmed fixed. **One blocking regression halts completion:** after a transport interruption the client
reconnects but never runs the canonical resynchronization, so the operations view silently displays stale
data indefinitely (§9).

---

## 1. Baseline and committed surface

```text
branch: main   HEAD: 39627da   tree: clean   UTCP_PHASE=T1
```

Present and verified: `ConferenceOperationalStateChanged`, `OperationalBroadcastBridge` (maps both
`conference` and `conference_participant` aggregates, deriving `conference_id` for participants),
Conference private-channel authorization in `routes/channels.php` gated on `telephony.conferences.view`,
`/operations/conferences` route, capability-gated navigation, one shared Echo client, Conference list/detail/
participant view, RuntimeNode duplicate-request and tenant-switch corrections, and the bounded
`pusherTransportTLS` contract (`isPermittedPusherTransportCache`).

---

## 2. Images built and rolled out

```text
api     sha256:7402da654201b03ffd2cdb94d625d5fd255ba1effdb8749848012b5063b8df9e
web     sha256:ecab7ce1125baaacec447126285b33fba5e9626edac8cebb11b4b16d1fc11de2
gateway sha256:396a68e8e8d2e4342c851fd80d7982046597ae5dcd1e6d4d910a8491861b2e56  (built, NOT rolled out)
```

Reverb loads the same mutable `utcp/api` tag (`reverb-deployment.yaml` uses image `utcp-api`), so it was
restarted with the other API-image workloads. Explicitly rolled out: **api, worker,
control-plane-outbox-dispatcher, reverb, web** — all confirmed running the new API image
(`…a60ec9423af2cfba`) and web image (`…a270ce4fafa07e4b`).

**Preserved:** gateway kept its existing pod and image (`…03b27e5909710b4a`); PostgreSQL, Redis, Asterisk,
Kamailio, Traefik, observability, and unrelated telephony workloads were not restarted. Traefik's
API-endpoint pin was already current (`172.24.0.3/32` == live endpoint), so no policy re-render was needed.

Migration Job succeeded with `BROADCAST_CONNECTION=log` and **zero** Reverb credential references.

---

## 3. Runtime readiness and transport authority

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate: succeeded=1        reverb → redis:6379 = OPEN
Service reverb: ClusterIP:8080   rendered overlay: 0 × NodePort|LoadBalancer|hostPort|6001
edge: / · /healthz · /operations/conferences · /admin/runtime-nodes → 200
```

Pre-proof worker/dispatcher error counts were 0. Reverb logged its one known startup `RedisException`
(CNI programs the pod's NetworkPolicy shortly after container start; Redis reachable immediately after) —
the documented non-blocking race from UI-D8.

---

## 4. Natural login and shared connection

Real Login → `admin@utcp.local.test` via sanctioned break-glass credential → forced password change through
the UI → `Local Tenant` selected through AppShell (canonical `POST /auth/tenant-context` → 200).

```text
active tenant : 7be59d2a-07c8-4b4e-a86d-c97771a670b9 (slug "local")
capabilities  : telephony.conferences.view / .manage / .participants.manage / .join
navigation    : Conferences → /operations/conferences  (capability-gated, visible)
```

**One shared WebSocket client** confirmed across the whole session: the socket lifecycle was
`open → subscribe → subscription_succeeded → unsubscribe → close(1000) → open → …`, i.e. exactly one live
socket at a time, reused across routes and re-created only after an explicit close. Route changes swap the
channel on the shared client rather than opening a second socket.

---

## 5. RuntimeNode follow-up regressions — duplicate requests CORRECTED

The UI-D8 duplicate initial round is fixed. Measured on both required paths:

| Path | catalog | list | per-node detail |
| --- | --- | --- | --- |
| Fresh route load (`/admin/runtime-nodes`) | **1** | **1** | **0** |
| Navigate away and return through AppShell | **1** | **1** | **0** |

WebSocket and `broadcasting/auth` traffic excluded, as required. 110 rows rendered.

---

## 6. Conference initial request budget — PROVEN

Entering `/operations/conferences` through the capability-gated navigation:

```text
Conference list requests      : 1        (GET /api/v1/admin/conferences)
Conference detail requests    : 0
Conference participant requests: 0
Conference rows               : 120
```

Ordered traffic was `GET /admin/conferences` → `POST /api/broadcasting/auth` (+152 ms). The event stream
supplied no initial canonical state, and no request count scaled with the 120 Conferences.

---

## 7. Channel authorization, subscription, and selection — PROVEN

```text
POST /api/broadcasting/auth → 200
  requested channel : private-tenant.7be59d2a-07c8-4b4e-a86d-c97771a670b9.conferences
  session           : the real browser session; occurred only after tenant context existed
  loop              : none (one auth per subscription); signature never recorded
wire frames         : pusher:subscribe → pusher_internal:subscription_succeeded on that channel
```

Selecting one Conference through the UI loaded **exactly** its own two resources:

```text
GET /api/v1/admin/conferences/34b5accb-…            (selected detail)
GET /api/v1/admin/conferences/34b5accb-…/participants
unselected Conference detail/participant requests : 0
```

The panel rendered canonical data (runtime node, binding lifecycle, observed state, generation,
participants). No conference state was mutated to populate the view.

---

## 8. Canonical event sources and delivery — PROVEN

**Source selection and authority.** The `/operations/conferences` view is read-only (controls: Refresh,
Details, Selected), so no Conference mutation exists in the UI. Under the §12 precedence the canonical
source is therefore the **authorized Conference admin/participant API**, which `CLAUDE.md` names as
management authority alongside the Web Admin UI ("Web Admin UI and authorized API"). All mutations were
issued **from the authenticated browser session** with the app's own CSRF flow (`GET /auth/csrf` →
`X-XSRF-TOKEN`), on **disposable** data. No outbox row was inserted, no Tinker/Artisan dispatch, no direct
database or Redis write, and no second management path was created.

### `conference.created` — disposable Conference created (also proves unrelated-Conference scoping)

```json
{ "event_type":"conference.created", "aggregate_type":"conference",
  "aggregate_id":"da52bf37-2901-4713-85fe-77e8b73b0406",
  "conference_id":"da52bf37-2901-4713-85fe-77e8b73b0406",
  "tenant_id":"7be59d2a-07c8-4b4e-a86d-c97771a670b9",
  "occurred_at":"2026-07-23T11:32:12.000000Z" }
```

`__allKeys` = exactly the six approved fields; `__extraKeys` = `[]`. Channel
`private-tenant.…conferences`. The full chain is proven: canonical action → transaction → `conference.*`
outbox message → `OperationalBroadcastBridge` → queued broadcast → Reverb → browser.

**Unrelated-Conference scoping (§16) — PROVEN.** A *different* Conference was selected at the time:

```text
Conference list reread                : 1
selected Conference detail            : 0
selected Conference participants      : 0
any other Conference detail/participant: 0
post-event requests                   : ["GET /api/v1/admin/conferences"]
```

The browser did not inspect all Conferences to determine ownership.

### `conference.desired_state_changed` — selected Conference (scoped rereads)

Backend validation stayed authoritative: `draft → active` was correctly rejected `422 "Invalid conference
desired-state transition."`; the legal `draft → open` succeeded (200).

```json
{ "event_type":"conference.desired_state_changed", "aggregate_type":"conference",
  "aggregate_id":"da52bf37-…", "conference_id":"da52bf37-…",
  "tenant_id":"7be59d2a-…", "occurred_at":"2026-07-23T11:33:32.000000Z" }
```

```text
Conference list reread            : 1
selected Conference detail reread : 1
selected participant-list reread  : 1
unrelated Conference resources    : 0
```

### `conference_participant.admitted` / `.removed` — canonical participant lifecycle

Joined through the canonical non-admin lifecycle `POST /api/v1/conferences/{id}/participants/self`
(after `POST /api/v1/telephony/sessions`, 201), then restored with `DELETE …/participants/self` (200).

```json
{ "event_type":"conference_participant.admitted", "aggregate_type":"conference_participant",
  "aggregate_id":"2347fb31-af8e-4ebd-93d2-003de4376691",
  "conference_id":"da52bf37-2901-4713-85fe-77e8b73b0406",
  "tenant_id":"7be59d2a-…", "occurred_at":"2026-07-23T11:34:03.000000Z" }
```

`aggregate_type` is `conference_participant`, `conference_id` is the selected Conference, `tenant_id` the
active tenant, `__extraKeys` = `[]`. **Absent:** participant names, addresses, destinations, credentials,
endpoint data, signaling payloads, media data, and any full outbox payload.

```text
Conference list reread            : 1
selected Conference detail reread : 1
selected participant-list reread  : 1
unrelated Conference resources    : 0
```

The removal emitted `conference_participant.removed` with the same clean envelope; the participant record
resolved to state `left`, preserving lifecycle history rather than being hard-deleted.

Final displayed state came from canonical HTTP readback in every case — the envelopes carry no state fields
at all.

---

## 9. BLOCKING REGRESSION — reconnect never resynchronizes canonical snapshots

### Disconnect behaviour (correct)

Reverb scaled `1 → 0` (only Reverb):

```text
badge  : "Live updates disconnected — displayed data may be stale" (--danger)
data   : 122 Conference rows, selected panel and participants all still visible
controls: Refresh and Details usable
API requests during 22 s outage : 0     (no auth loop, no detail/participant fan-out)
socket  : 1 close (1006) + 1 bounded retry; no invented Conference domain failure
```

### Reconnect behaviour (defective)

Reverb restored to 1 replica (Redis OPEN, independently confirmed reachable from the browser by a probe
that completed a full `pusher:connection_established` handshake). Over the following **105 seconds**:

```text
socket           : reconnected and healthy (pusher:ping / pusher:pong flowing)
badge            : stuck on "Live updates reconnecting" — never returns to connected
Conference list reread          : 0
selected Conference detail      : 0
selected participant list       : 0
channel re-authorization        : 0
API requests of any kind        : 0
```

**The canonical resynchronization never runs**, so the operations view keeps displaying pre-outage data
indefinitely while the transport is actually healthy.

### Root cause

`apps/web/src/realtime/runtimeNodeRealtime.ts` marks a subscription ready only from a `bind` callback:

```ts
activeRuntimeNodeChannel.bind?.('pusher:subscription_succeeded', () => {
  activeRuntimeNodeSubscriptionReady = true
  void maybeCompleteLiveConnection()
})
```

(and the equivalent for the Conference channel). **Laravel Echo's channel API has no `bind` method** — its
type surface exposes `subscribed(callback)`, `listen`, `error`, `stopListening`, and friends; a repository
scan of `node_modules/laravel-echo/dist/echo.d.ts` finds `subscribed(callback: CallableFunction): this` and
**zero** `bind` declarations. The optional call `.bind?.(…)` therefore silently no-ops, so
`activeRuntimeNodeSubscriptionReady` / `activeConferenceSubscriptionReady` are never set.

Consequences, both observed live:

1. `activeSubscriptionsReady()` never returns true, so `maybeCompleteLiveConnection()` always early-returns
   and the badge never reaches **connected** — it shows "connecting" on first connect and "reconnecting"
   after an outage even while subscriptions are healthy and events are being delivered.
2. The same early return sits in front of `resynchronizeCanonicalSnapshots()`, so `requiresReconnectResync`
   never clears and **no canonical reread happens after a reconnect**.

Event delivery itself is unaffected because `.listen()` is bound independently — which is why §8 passes
while §9 fails. The correction is bounded: use Echo's `subscribed(...)` (or bind through
`channel.subscription`/the underlying Pusher channel) instead of the non-existent `bind`.

**No existing test catches this**: the frontend suite injects a stub client via
`setRuntimeNodeRealtimeClientFactory`, so the real Echo channel API surface is never exercised — the same
class of gap that produced the UI-D6 blocker.

---

## 10. Storage and responsive results

### Storage boundary — PROVEN

Inspected before connection, while connected, and after logout:

```text
localStorage : ["pusherTransportTLS", "utcp.appearance"]      sessionStorage: []
utcp.appearance = "system"
pusherTransportTLS = {"timestamp":…,"transport":"ws","latency":…,"cacheSkipCount":0}
  parses as JSON ✓   keys: [timestamp, transport, latency, cacheSkipCount]   transport: "ws" ✓
  tenant ID: none · user ID: none · channel: none · socket ID: none · app key: none · credential: none
```

Bounded vendor transport-cache metadata only; no other unexpected keys; the cache was not deleted.

### Responsive — PROVEN

`/operations/conferences` at 375 px:

```text
Light : scrollWidth 375 == innerWidth 375  → overflow 0   offenders 0
Dark  : scrollWidth 375 == innerWidth 375  → overflow 0   offenders 0
html/body overflow-x : "visible" / "visible"   (no clipping mask)
live badge and controls inside the viewport
theme changes : 0 API requests, no reconnect
appearance reset to System
```

---

## 11. Logout teardown — PROVEN

```text
send pusher:unsubscribe  private-tenant.7be59d2a-….conferences   ← channel left
ws-close code            : 1000                                   ← normal teardown of the one socket
reconnect attempts after logout : 0
broadcasting/auth after logout  : 0
API requests after logout       : GET /auth/csrf, POST /auth/logout
final URL                       : /login       live badge: absent
```

Cookies were never cleared manually.

---

## 12. Steps not performed

**§19 tenant-switch isolation and previous-tenant event rejection were not exercised.** The blocking
reconnect defect (§9) left the shared client in a permanent "reconnecting" state after the outage test, so
a tenant-switch measurement taken from that state could not have distinguished correct channel teardown
from the stuck-state artifact. This is recorded as not proven rather than reported on degraded evidence.
The RuntimeNode tenant-switch live-state correction is likewise **not confirmed**, since it depends on the
same readiness flag that §9 shows is never set.

---

## 13. Runtime and error findings

```text
image provenance      : api/web/reverb/worker/dispatcher on 39627da builds; gateway preserved
workload rollouts     : 5 restarted, gateway + data/infra untouched
WebSocket lifecycle   : one shared socket; clean 1000 closes; 1006 + bounded retry during outage
subscriptions         : runtime-nodes and conferences channels, one at a time per route
broadcasting-auth     : 1 per subscription; 0 loops; 0 after logout
Conference frames     : conference.created, conference.desired_state_changed
Participant frames    : conference_participant.admitted, conference_participant.removed
canonical rereads     : 1 list + scoped selected detail/participants per notification
Reverb outage/recovery: data preserved and stale shown; RECOVERY RESYNC FAILS (§9)
worker failures / failed jobs : 0        outbox-dispatch errors : 0
Reverb errors         : 1 known startup RedisException race (non-blocking)
gateway errors        : 4 × 502 on /app/{key} at 11:35:29–11:36:14 — entirely within the deliberate
                        Reverb scale-to-zero window; expected, not a defect
browser page errors / unhandled rejections : 0
duplicate requests / unexpected fan-out    : none
```

No failed broadcast job and no stuck proof-generated outbox message remain.

---

## 14. Cleanup and final state

The disposable Conference was closed through the canonical API (`desired_state → closed`, 200) and the
proof TelephonySession ended (200); the participant was already restored to `left`. Reverb replicas
restored to 1 with Redis OPEN. Browser logged out and context closed, observers removed with it,
`.playwright-mcp/` deleted, no port-forwards or credential scratch files.

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate succeeded=1 · reverb ClusterIP:8080 · reverb→redis OPEN · public WSS via Traefik 443
```

One disposable Conference (`ui-d10-proof-1784806332484`) remains in `closed` desired state with its
participant in `left` — retained deliberately, since UTCP preserves conference and participant lifecycle
history rather than hard-deleting it.

---

## 15. Verification

| Check | Result |
| --- | --- |
| `php artisan test` (4 focused suites incl. `ConferenceRealtimeBroadcastTest`) | **35 passed** (346 assertions) |
| `php artisan test` (full backend suite) | **364 passed**, 2 skipped (2979 assertions) |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` / `lint` / `test` / `build` | passed (**67** frontend tests) |
| `make repository-hygiene` / `workflow-check` / `secret-scan` | passed |
| `git diff --check` / `--cached --check` | clean |

Every automated check passes while the live reconnect resynchronization is broken — the stubbed Echo
factory means no test exercises the real channel API.

---

## 16. Outcome

The Conference and participant live-operations corridor is proven: capability-gated navigation, a 1-request
initial budget with zero detail/participant fan-out, natural private-channel authorization and
subscription, scoped selection, real `conference.*` and `conference_participant.*` deliveries with
approved-metadata-only envelopes, correctly scoped canonical rereads, unrelated-Conference isolation,
correct disconnect presentation, clean logout teardown, a bounded vendor storage cache, and zero 375 px
overflow. The RuntimeNode duplicate-request follow-up is confirmed fixed.

One bounded frontend regression blocks completion: subscription readiness is signalled through a
non-existent Echo `bind` method, so the live badge never reaches connected and — materially — **no
canonical resynchronization occurs after a reconnect**. UI-D remains **In Progress**.
