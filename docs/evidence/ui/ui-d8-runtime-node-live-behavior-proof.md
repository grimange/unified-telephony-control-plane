# UI-D8 — RuntimeNode Live-Update Behavioral Proof (COMPLETE)

Verdict: `UI_D1_RUNTIME_NODE_REALTIME_LIVE_PROOF_COMPLETE`

Proof type: controlled natural browser proof, completing the UI-D1 corridor.
Source commit: `d49afb7` (`fix(realtime): connect browser client and restore mobile layout`).
Prior blocked runs: [`ui-d6-runtime-node-realtime-live-proof.md`](ui-d6-runtime-node-realtime-live-proof.md)
(browser client never opened a socket) and the correction
[`ui-d7-browser-reverb-client-mobile-fix.md`](ui-d7-browser-reverb-client-mobile-fix.md).
Phase marker `UTCP_PHASE=T1` (unchanged). Kubeconfig `.runtime/kubeconfig/utcp-local.yaml`, context
`k3d-utcp-local`. No production code was modified. No database schema changed.

**Both UI-D7 corrections are live-proven.** The application browser now opens a stable public WSS
connection, authorizes the private tenant channel, receives real broadcasts from canonical Web Admin
mutations, and rereads canonical snapshots — with zero detail fan-out. The 375 px overflow regression is
gone. Two non-blocking findings are recorded in §12.

---

## 1. Baseline and corrected contract

```text
branch: main
HEAD:   d49afb7
tree:   clean
UTCP_PHASE=T1
```

`buildRuntimeNodeEchoOptions()` (`apps/web/src/realtime/runtimeNodeRealtime.ts:172-189`) is used by
production `createEchoClient` (`:191-193`) and emits:

```js
{ broadcaster:'reverb', key:<public>, wsHost, wsPort, wssPort,
  forceTLS: wsScheme === 'wss', enabledTransports:['ws','wss'],
  authEndpoint:'/api/broadcasting/auth', auth:{headers:{'X-Requested-With':'XMLHttpRequest'}}, Pusher }
```

No `wsPath` is emitted and no secret option exists. Verified again in the **served** bundle
(`/assets/index-D3QT7kwb.js`): `enabledTransports:[\`ws\`,\`wss\`]`, `forceTLS:e.wsScheme===\`wss\``, and
**0** occurrences of `wsPath` inside the Echo option object.

---

## 2. Web image built and rolled out; server corridor preserved

Built from clean `d49afb7` with `VITE_UTCP_WS_HOST=app.utcp.local.test`, `VITE_UTCP_WS_PORT=443`,
`VITE_UTCP_WS_SCHEME=wss`, `VITE_UTCP_WS_PATH=/app`, and only the public application key.

```text
web image digest: sha256:e9297a6fecd89fa82b99a9fe072467cd71f847440d248eb260191fef29bafb6c
running web pod : web-fbdbf9479-4vb4x  (imageID …e9297a6fecd89fa8)  started 23:35:39Z
served bundle   : /assets/index-D3QT7kwb.js   (was index-V76hwj8T.js in the failing UI-D6 run)
```

Only `deployment/web` was restarted. All server-corridor pods retained their original identities and
images across the whole proof:

```text
api-c4fccc8c7-5hvl2                              22:43:14Z  …5a86e1e1f6c46fbe
control-plane-outbox-dispatcher-7fd55ffd85-47h7k 22:43:14Z  …5a86e1e1f6c46fbe
worker-5f775f68bb-46q6h                          22:43:14Z  …5a86e1e1f6c46fbe
gateway-7c98bc964b-7c5hm                         22:42:34Z  …03b27e5909710b4a
```

**Recorded divergence (no restart performed):** `make k8s-image-build` also rebuilt api and gateway, and
their registry tag digests changed (api `4a861d38…` → `4dc2cdf4…`, gateway `1c47876d…` → `179f8b6c…`)
because the builds are not byte-reproducible. Those workloads were deliberately **not** restarted, so the
proven server corridor kept running its existing images. The one exception is inherent to the §16 outage
test: scaling Reverb 0→1 recreated its pod, which pulled the new api tag. `d49afb7` changes frontend code
only, so this is metadata-level drift; Reverb behaved identically before and after (§16–§17).

**Bundle reproducibility note:** a bare `npm run build` produces a different content hash
(`index-C1zM3X7F.js`) because the Docker build bakes `VITE_UTCP_REVERB_APP_KEY` and the WS coordinates that
a bare local build lacks. Provenance is therefore established by the served bundle's option contract (§1),
not by hash equality.

---

## 3. Traefik endpoint policy

Not stale; no action taken, Traefik not restarted.

```text
live apiserver endpoint : 172.24.0.3
rendered policy ipBlock : 172.24.0.3/32
```

---

## 4. Workload readiness and transport authority

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate: succeeded=1
reverb → redis.utcp-data:6379 = OPEN
Service reverb: type=ClusterIP  port=8080
rendered overlay: 0 × NodePort | LoadBalancer | hostPort | 6001
```

Edge routing: `/`, `/healthz`, `/dashboard`, `/admin/runtime-nodes` → 200; `/app/{key}` → Reverb (not the
SPA catch-all); `POST /api/broadcasting/auth` → API `401 application/json` when unauthenticated.

---

## 5. Natural login and tenant

Real Login page → `admin@utcp.local.test` via sanctioned break-glass temporary credential → forced password
change completed through the UI → `/dashboard` → `Local Tenant` selected through the AppShell control,
confirmed by a canonical `POST /api/v1/auth/tenant-context` → `200`.

```text
active_tenant.tenant_id = 7be59d2a-07c8-4b4e-a86d-c97771a670b9   (slug "local")
capabilities include runtime.nodes.view / runtime.nodes.manage
```

No injected cookies, preset sessions, imported storage state, or DB/Redis-created sessions.

---

## 6. RuntimeNode initial request budget

Navigating through visible navigation to `/admin/runtime-nodes` with a settled session:

```text
RuntimeNode rows            : 110
per-node detail requests    : 0        ← the critical UI-C contract, held in every measurement
runtime-node-catalog        : 2
runtime-nodes (list)        : 2
```

Ordered requests: `catalog → list → POST /api/broadcasting/auth → catalog → list`.

**Divergence against the §8 target of 1 + 1:** the view issues a second canonical catalog+list round that
coincides with connection establishment. It reproduces on a fresh direct page load, on in-app navigation,
and with `connectedOnce=false`, so it is not the documented reconnect resync. Both rounds are ordinary
canonical snapshot reads — **not** per-node fan-out, and never proportional to the 110 nodes. Recorded as a
bounded efficiency follow-up, not a correctness or authority defect.

The canonical snapshot always precedes the subscription becoming authoritative: subscription is gated behind
`subscribeAfterCanonicalSnapshot()` and the observed order is snapshot → auth → subscribe.

---

## 7. Application-created WSS connection

The application — not an external probe — created exactly **one** socket:

```text
wss://app.utcp.local.test:443/app/<public-key>?protocol=7&client=js&version=8.5.0&flash=false
  /app/ segments in path : 1          ← no /app/app/{key}
  port                   : default 443 (Traefik)
  sockets opened         : 1          ← no duplicate
```

Frame sequence (relative to marker):

```text
+222 ms  open-attempt
+225 ms  ws-open                       (101 Switching Protocols)
+226 ms  recv pusher:connection_established
+259 ms  send pusher:subscribe          private-tenant.7be59d2a-…-runtime-nodes
+308 ms  recv pusher_internal:subscription_succeeded
```

No `4009 Origin not allowed`. No connection to `reverb:8080`, no Pod IP, no custom public port. The
Runtime Nodes badge rendered `ui-status-badge--success` / **"Live updates connected"**.

---

## 8. Private-channel authorization and subscription

```text
POST /api/broadcasting/auth  → 200
  requested channel : private-tenant.7be59d2a-07c8-4b4e-a86d-c97771a670b9.runtime-nodes
  response keys     : ["auth"]        (signature not recorded)
  credentials       : the real browser session (no injected auth)
```

It occurred only after an active tenant existed, exactly once per subscription, with no repeated auth loop
and no subscription-error frame. Exactly one active tenant channel; the canonical list stayed visible and
the connected status remained stable.

---

## 9. Canonical RuntimeNode event source

Disposable `simulator-deterministic` node opened through the real UI:

```text
node : 67bceac5-ced6-4c47-a2de-57c42c376491  ("C4 Live Proof drift", slug c4-live-proof-drift-1784071066)
opening it issued exactly 3 scoped requests: adapter-configuration, runtime-evidence, history?limit=10
original profile: scenario_key=configuration-drift-then-converge, scenario_version=1,
                  seed=c4-live-proof-drift-1784071066, parameters=[]
```

One reversible, non-secret change was made through the descriptor-rendered form and saved with the normal
**Save adapter configuration** button: `seed` → `c4-live-proof-drift-1784071066-d8`. The canonical mutation
was `PUT …/runtime-nodes/{id}/adapter-configuration` with descriptor-keyed payload
`{scenario_key, scenario_version, seed, parameters}`. No manual API call, no outbox insert, no
Artisan/Tinker dispatch, no direct database or Redis write.

**Proof-harness note (not a product defect):** an initial attempt to edit `scenario_version` produced no
request because the catalog descriptor constrains it to `min=1, max=1`; native HTML5 validation correctly
blocked the submit (`Value must be 1.`). The field was restored to `1` and `seed` was used instead.

---

## 10. Broadcast envelope

Delivered over the private tenant channel:

```json
{
  "event": "runtime-node.operational-state.changed",
  "channel": "private-tenant.7be59d2a-07c8-4b4e-a86d-c97771a670b9.runtime-nodes",
  "data": {
    "event_type": "runtime_node.simulator_configuration_changed",
    "aggregate_type": "runtime_node",
    "runtime_node_id": "67bceac5-ced6-4c47-a2de-57c42c376491",
    "tenant_id": "7be59d2a-07c8-4b4e-a86d-c97771a670b9",
    "occurred_at": "2026-07-22T23:44:25.000000Z"
  }
}
```

Key-set assertion captured live: `__allKeys` = exactly the five approved fields, `__extraKeys` = `[]`.
`aggregate_type` is `runtime_node`, `runtime_node_id` is the changed node, `tenant_id` is the active tenant.
**Absent:** adapter configuration values, the changed `seed`, simulator JSON `parameters`, endpoints,
credentials, secrets, full RuntimeNode state, and any full outbox payload.

The full chain is therefore proven end to end: Web Admin mutation → canonical transaction →
`runtime_node.*` outbox message → outbox dispatcher → queued broadcast → Reverb → browser notification.

---

## 11. Automatic canonical reread, scoped detail, and zero fan-out

The restore (`seed` back to `c4-live-proof-drift-1784071066`) was measured with **event-relative** accounting
so the action reread and the notification reread are unambiguously separated:

```text
rel −3389 ms  PUT   …/{node}/adapter-configuration        ← canonical mutation
rel −3338 ms  GET   runtime-node-catalog                  ┐
rel −3308 ms  GET   runtime-nodes                         │ post-action reread
rel −3192 ms  GET   …/{node}/adapter-configuration        │ (scoped to the open node)
rel −3149 ms  GET   …/{node}/runtime-evidence             │
rel −3099 ms  GET   …/{node}/history?limit=10             ┘
rel     0 ms  ◀ runtime-node.operational-state.changed
rel    +3 ms  GET   runtime-node-catalog                  ┐
rel   +56 ms  GET   runtime-nodes                         │ notification-triggered
rel  +247 ms  GET   …/{node}/adapter-configuration        │ canonical reread
rel  +285 ms  GET   …/{node}/runtime-evidence             │
rel  +317 ms  GET   …/{node}/history?limit=10             ┘
```

```text
notifications received           : 1     (no duplicate)
post-event list rereads          : 1
post-event affected-node detail  : 3     (the open node only)
post-event OTHER-node detail     : 0     ← zero fan-out across 110 nodes
```

No request count proportional to the RuntimeNode count. Final displayed state came from HTTP snapshot
readback, not event payload fields — the event carries no configuration data at all. Canonical readback
confirmed both directions:

```text
after change : seed = c4-live-proof-drift-1784071066-d8   configuration_generation 6
after restore: seed = c4-live-proof-drift-1784071066      configuration_generation 7
```

The restore followed the identical notification-and-reread path, satisfying both the reread proof and the
additional no-fan-out trigger.

---

## 12. Reverb outage and reconnect resynchronization

Reverb scaled `1 → 0` (only Reverb; API, worker, dispatcher, Redis, gateway, and web untouched):

```text
badge     : "Live updates disconnected — displayed data may be stale"  (ui-status-badge--danger)
rows      : 110 still visible (list not blanked)
open panel: preserved, seed value intact
controls  : Refresh and Save adapter configuration both usable
API calls during 22 s outage        : 0
broadcasting/auth attempts          : 0     ← no uncontrolled reconnect/auth loop
detail requests                     : 0     ← no fan-out
socket closes / reconnect attempts  : 1 close (1006) / 1 bounded attempt
```

No RuntimeNode domain failure was invented — only the transport was marked stale.

Reverb restored to 1 replica (Redis OPEN). Ordering measured by sampling the badge every 100 ms relative to
socket reopen:

```text
rel    0 ms  ws-open
rel  +54 ms  GET runtime-nodes                (canonical reread request)
rel  +81 ms  pusher_internal:subscription_succeeded
rel +269 ms  runtime-nodes response 200       (canonical reread completes)
rel +509 ms  badge → "Live updates connected" ← stale cleared AFTER successful reread
```

```text
channel-auth requests : 1     (single reauthorization, no loop)
list rereads          : 1
open-node detail      : 3
other-node detail     : 0
```

The stale indicator did **not** clear merely because the socket opened (+0 ms) or because the subscription
succeeded (+81 ms); it cleared at +509 ms, after the canonical snapshot response at +269 ms.

### Finding 1 — tenant-switch leaves a false "stale" badge (non-blocking)

After switching tenants the channel is correctly re-subscribed, but the badge remains
`ui-status-badge--danger` / "Live updates disconnected — displayed data may be stale". Reproduced in both
directions (Local → Proof and Proof → Local) while `pusher_internal:subscription_succeeded` was observed for
the new channel each time. The socket never drops during a tenant switch, so the `connected` transition that
would clear the state never re-fires. Live delivery and authority are unaffected — this is a
state-reporting defect that misreports healthy live updates as stale. Bounded frontend follow-up.

### Finding 2 — `pusherTransportTLS` in localStorage (non-blocking)

Browser storage after logout holds `utcp.appearance` **and** `pusherTransportTLS`, a pusher-js internal
transport-preference cache whose value is `{"timestamp":…,"transport":"…"}`. It contains no credential,
token, key, signature, or tenant data, and is written by the vendored client rather than UTCP code. It is
nonetheless outside the previously stated `utcp.appearance`-only boundary and should be acknowledged (or
cleared on logout) by a bounded follow-up.

---

## 13. Tenant-switch isolation and previous-tenant rejection

Switching `Local Tenant → Proof Tenant 1784195144` through the AppShell:

```text
send pusher:unsubscribe   private-tenant.7be59d2a-….runtime-nodes    ← old channel left
send pusher:subscribe     private-tenant.28678536-….runtime-nodes
recv subscription_succeeded private-tenant.28678536-….runtime-nodes
active_tenant → 28678536-0759-4d00-b2be-e7fd6757c58c (slug proof-1784195144)   (canonical)
Local Tenant rows/details : cleared (0 rows, 0 open panel fields)
per-node detail requests  : 0     ← zero fan-out on the new tenant's initial load
```

No surviving subscription referenced the Local Tenant channel.

A **second fresh Playwright context** then authenticated naturally, selected `Local Tenant`, and changed the
same disposable simulator configuration through Web Admin, while the primary browser stayed on Proof Tenant:

```text
primary active tenant            : proof-1784195144   (unchanged)
primary runtime-node events      : 0        ← no Local Tenant event accepted
primary Local-Tenant events      : 0
primary RuntimeNode API requests : 0        ← no reread triggered
primary rows                     : 0        ← no Local Tenant row or detail appeared
primary frames during window     : pusher:ping / pusher:pong only
```

The second context restored the seed to `c4-live-proof-drift-1784071066` (canonically confirmed), logged
out, and was closed.

---

## 14. Logout disconnect

Returned to `Local Tenant` (unsubscribe Proof channel → subscribe + `subscription_succeeded` Local channel,
110 rows restored), then logged out through the AppShell:

```text
send pusher:unsubscribe  private-tenant.7be59d2a-….runtime-nodes   ← private channel left
ws-close code            : 1000                                    ← clean close
reconnect attempts after logout : 0
broadcasting/auth after logout  : 0
API requests after logout       : POST /api/v1/auth/logout only
final URL                       : /login   (real Login page)
live-updates badge              : no longer present (connection state cleared)
```

Cookies were never cleared manually.

**Session rejection:** not separately exercised — no safe existing UI lifecycle invalidates the session
mid-flight, and no database- or Redis-created invalidation was used, per the proof constraints. The
automated session-rejection coverage is retained.

---

## 15. Mobile overflow correction

`/admin/runtime-nodes` at 375 px:

```text
Light : documentElement.scrollWidth 375 == innerWidth 375   → overflow 0
Dark  : documentElement.scrollWidth 375 == innerWidth 375   → overflow 0
1280px: 1280 == 1280                                        → overflow 0
out-of-viewport offending elements : 0 (all breakpoints)
html/body overflow-x               : "visible" / "visible"  ← no clipping mask added
live-updates badge                 : visible, right edge 179 ≤ 375, text readable
Refresh + Details controls         : reachable and inside the viewport
```

This closes the UI-D6 regression (`scrollWidth 406` vs `innerWidth 375`). Theme changes and resizes issued
**0** API requests and caused no reconnect (socket frames during the theme change were `pusher:ping` /
`pusher:pong` only). Appearance was reset to **System**.

---

## 16. Security and storage boundary

Across the whole proof — sanitized WebSocket URLs and frames, notifications, page URL, storage, console,
DOM, and RuntimeNode HTTP readback — there was no exposure of the Reverb application secret, session
tokens, credentials, adapter secrets, endpoint secrets, full event-store payloads, or protected RuntimeNode
configuration inside frames. The only notification rendered was the standing informational
"Write-only credentials — Secrets are write-only and cannot be retrieved after submission."

The observer redacted the application key from every recorded URL, recorded only `event`/`channel` plus
key **names** for non-approved payloads, and never captured the authorization signature (only the response
key set `["auth"]`). Storage held `utcp.appearance` plus the vendored `pusherTransportTLS` entry (Finding 2).
`sessionStorage` was empty throughout.

---

## 17. Runtime and error findings

```text
migration                    : succeeded=1, credential-free on the log driver
web rollout                  : only deployment/web restarted for the code change
WebSocket lifecycle          : 1 socket; clean 1000 close on logout; 1006 + 1 bounded retry during outage
channel-auth requests        : 1 per subscription; 0 loops; 0 after logout
subscription frames          : subscribe + subscription_succeeded per tenant channel
RuntimeNode notifications    : 1 per canonical mutation, approved metadata only
canonical rereads            : 1 list + scoped open-node details per notification
Reverb outage and recovery   : data preserved, stale shown, resync ordered correctly
worker failures / failed jobs: 0
outbox dispatcher errors     : 0
Reverb errors (restored pod) : 0
gateway 5xx                  : 0
browser page errors          : 0
unhandled rejections         : 0
unexpected reconnect loops   : none
duplicate events or requests : none
```

Console errors observed were the expected pre-auth `/api/v1/auth/session` 401 and two `409` responses from
`/admin/runtime-nodes` and `/admin/memberships` issued while no active tenant was yet selected — both
expected API contract responses, not defects. No failed broadcast job and no stuck proof-generated outbox
message remain.

---

## 18. Cleanup and final state

Simulator configuration restored and canonically verified (`seed = c4-live-proof-drift-1784071066`) ·
Reverb replicas restored to 1 with Redis OPEN · returned to Local Tenant before logout · RuntimeNode panels
closed · appearance reset to System · both browser contexts logged out and closed · observers removed with
the contexts · `.playwright-mcp/` deleted · no port-forwards · no plaintext credential scratch files.

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate succeeded=1 · reverb Service ClusterIP:8080 · reverb→redis OPEN · public WSS via Traefik 443
```

PostgreSQL, Redis, Asterisk, Kamailio, Traefik, observability, and unrelated telephony workloads were not
restarted. The admin account retains a proof-set password; no other disposable record remains changed.

---

## 19. Verification

| Check | Result |
| --- | --- |
| `npm run typecheck` / `lint` / `test` / `build` | passed (**60** frontend tests) |
| `php artisan test` (ReverbRealtimeInfrastructure, RuntimeNodeRealtimeBroadcast, RuntimeFencingManifest) | **30 passed** (309 assertions) |
| `vendor/bin/pint --test` | passed |
| `make repository-hygiene` / `workflow-check` / `secret-scan` | passed |
| `git diff --check` / `--cached --check` | clean |

---

## 20. Outcome

The UI-D1 RuntimeNode real-time corridor is **live-proven end to end**: the application browser opens one
stable public WSS connection through Traefik 443 with a single `/app/{key}` segment, authorizes the private
tenant channel with the real session, subscribes, receives a notification-only broadcast produced by a
canonical Web Admin mutation, and rereads canonical snapshots with scoped detail and zero fan-out — while
outage, reconnect resynchronization, tenant isolation, previous-tenant rejection, logout disconnect, mobile
geometry, and the secret/storage boundary all hold.

Three bounded, non-blocking follow-ups are recorded: the duplicate initial catalog+list round (§6), the
false "stale" badge after a tenant switch (§12 Finding 1), and the vendored `pusherTransportTLS` storage
entry (§12 Finding 2). UI-D remains **In Progress** — UI-D1 completion does not complete the remaining UI-D
operational surfaces (Conference, participants, runtime operations, audit).
