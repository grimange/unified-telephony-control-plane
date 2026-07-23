# UI-D12 — Echo Subscription Readiness and Canonical Resynchronization Live Proof (COMPLETE)

Verdict: `UI_D_ECHO_SUBSCRIPTION_READINESS_LIVE_PROOF_COMPLETE`

Proof type: controlled natural browser proof of the focused frontend correction.
Source commit: `0b46434` (`fix(realtime): restore subscription readiness and resync`).
Prior evidence: [`ui-d10-conference-participant-live-proof.md`](ui-d10-conference-participant-live-proof.md)
(recorded the blocker) and [`ui-d11-echo-subscription-readiness-fix.md`](ui-d11-echo-subscription-readiness-fix.md)
(the correction). Phase marker `UTCP_PHASE=T1` (unchanged). Kubeconfig
`.runtime/kubeconfig/utcp-local.yaml`, context `k3d-utcp-local`. No production code was modified. No
database schema changed.

**The UI-D10 blocker is closed.** Subscription readiness now flows through Echo-supported callbacks, the
live badge reaches connected, and reconnect performs exactly one bounded canonical resynchronization —
replacing the previously observed 105-second permanently-stuck "reconnecting" state.

---

## 1. Baseline and corrected contract

```text
branch: main   HEAD: 0b46434   tree: clean   UTCP_PHASE=T1
```

Source (`apps/web/src/realtime/runtimeNodeRealtime.ts`) — both channels follow the same shape:

```ts
const generation = ++activeRuntimeNodeToken
const channel = echoClient.private(nextChannelName)
channel.listen(runtimeNodeEventName, handleRuntimeNodeNotification)      // domain events preserved
channel.error?.((error) => handleRuntimeNodeSubscriptionError(error, generation, nextChannelName, channel))
channel.subscribed(() => {
  if (!isCurrentRuntimeNodeSubscription(generation, nextChannelName, channel)) return   // generation fencing
  activeRuntimeNodeSubscriptionReady = true
  void maybeCompleteLiveConnection()
})
```

No channel-level `bind` remains: the five surviving `.bind` calls are on the Pusher **connection** object
(`state_change`, `connected`, `disconnected`, `unavailable`, `failed`) — the supported connection API, not
the channel API.

Verified in the **served** bundle (`/assets/index-BfO5s0gG.js`): exactly **2** `.subscribed(` call sites,
compiling to `…listen(Ol,gu), i.error?.(e=>pu(…)), i.subscribed(()=>{Cu(r,t,i)&&(Bl=!0,xu())})` for
RuntimeNode and the equivalent for Conference. The residual `pusher:subscription_succeeded` /
`pusher:subscription_error` literals in the bundle sit inside **vendor** code — Echo's own implementation
(`subscribed(e){return this.on('pusher:subscription_succeeded',()=>{e()}),this}`) and pusher-js channel/
presence internals.

---

## 2. Web image and preserved workloads

```text
web image digest : sha256:f1395fb3acc9a2893cef0cbf76c280debe614bf060a9b92011315a8b20d5bf12
running web pod  : web-85665995d6-pmj5j  (imageID …f1395fb3acc9a289)  started 13:03:29Z
served bundle    : /assets/index-BfO5s0gG.js
```

Only `deployment/web` was restarted. All server workloads kept their pre-existing pods and images:

```text
api-5d68c75c8d-bcc5t                              11:26:10Z  …a60ec9423af2cfba
worker-784dfff977-s99z9                           11:26:10Z  …a60ec9423af2cfba
control-plane-outbox-dispatcher-77bf94ff4-zt8fb   11:26:10Z  …a60ec9423af2cfba
reverb (same API image, restarted only by the deliberate outage scaling)
gateway-7c98bc964b-7c5hm                          (previous day)  …03b27e5909710b4a
```

Traefik's API-endpoint pin already matched the live endpoint (`172.24.0.3/32`), so no policy re-render was
performed and Traefik was not restarted. PostgreSQL, Redis, Asterisk, Kamailio, observability, and
unrelated telephony workloads were untouched.

---

## 3. Runtime health and transport authority

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate: succeeded=1        reverb → redis:6379 = OPEN
Service reverb: ClusterIP:8080   public WSS: Traefik 443
pre-proof worker failures: 0     pre-proof dispatcher errors: 0
edge: / · /healthz · /admin/runtime-nodes · /operations/conferences → 200
```

---

## 4. Natural login, shared socket, and supported readiness

Real Login → `admin@utcp.local.test` via sanctioned break-glass credential → forced password change through
the UI → `Local Tenant` via AppShell (canonical `POST /auth/tenant-context`). No injected cookies, preset
sessions, or DB/Redis-created sessions.

| Surface | catalog | list | detail | participants | auth | badge |
| --- | --- | --- | --- | --- | --- | --- |
| `/admin/runtime-nodes` (110 rows) | **1** | **1** | **0** | — | 1 | **Live updates connected** (`--success`) |
| `/operations/conferences` (121 rows) | — | **1** | **0** | **0** | 1 | **Live updates connected** |
| select one Conference | — | — | **1** | **1** | — | connected (unchanged) |

Both channels reached `pusher_internal:subscription_succeeded`, and the state machine reached **connected**
— the state UI-D10 could never leave. One application WebSocket at a time throughout; route changes swap
the channel on the shared client. No duplicate canonical reread occurred during initial connection (the
UI-D8/UI-D10 duplicate round stays fixed at 1 catalog + 1 list).

---

## 5. Reconnect canonical resynchronization

### Conference route (first controlled reconnect)

Outage (`reverb 1 → 0`): 122 rows, selected panel and participants all still visible; badge
`--danger` "displayed data may be stale"; **0** API requests, **0** canonical rereads, **0** channel-auth;
one close (1006) and one bounded retry.

Recovery, relative to socket reopen (rel 0):

```text
rel   +2 ms  POST /api/broadcasting/auth                (1 re-authorization)
rel  +53 ms  badge → "reconnecting"
rel +101 ms  pusher_internal:subscription_succeeded      (conferences channel)
rel +104 ms  GET /admin/conferences                      (request)
rel +227 ms  GET /admin/conferences → 200                (response)
rel +236 ms  GET /admin/conferences/{selected}
rel +280 ms  GET /admin/conferences/{selected}/participants
rel +353 ms  badge → "Live updates connected"
```

```text
Conference list rereads          : 1
selected Conference detail       : 1
selected participant list        : 1
unrelated Conference resources   : 0
RuntimeNode requests             : 0   (that view unmounted; its subscription released)
```

### RuntimeNode route (second controlled reconnect, one node open)

Socket lifecycle showed the outage close (1006) followed by **bounded** retries at −45.0 s, −45.0 s and
−30.0 s (backoff, all failing while Reverb was down, with **0** API requests), then a successful reopen.
Relative to that reopen:

```text
rel    0 ms  ws-open + pusher:connection_established
rel  +42 ms  pusher:subscribe   (runtime-nodes channel)
rel +100 ms  pusher_internal:subscription_succeeded
             POST /api/broadcasting/auth        …412 → …453   (1)
             GET  /runtime-node-catalog         …511 → …542
             GET  /runtime-nodes                …544 → …630   (list response completes)
             GET  /{open node}/adapter-configuration …639 → …678
             GET  /{open node}/runtime-evidence      …678 → …728
             GET  /{open node}/history?limit=10      …729 → …767  (last reread completes)
rel +439 ms  badge → "Live updates connected"
```

```text
channel-auth requests            : 1
RuntimeNode list rereads         : 1
open-node scoped rereads         : 3   (bounded, exactly the open node's resources)
unrelated node detail requests   : 0
Conference requests              : 0
```

Badge transitions across the cycle: `connected → reconnecting → disconnected(stale) → reconnecting →
connected`.

**Duplicate-resync result:** exactly one of each reread per reconnect generation in both runs; repeated
subscription callbacks produced no additional reread.

---

## 6. Resync completion ordering

Both reconnects show the same ordering, from independent frame and request evidence:

```text
socket open (rel 0)                      → stale NOT cleared (badge went to "reconnecting")
subscription success (+100 / +101 ms)    → stale NOT cleared
canonical HTTP rereads complete          → (list +227 / +630-equiv, scoped rereads after)
stale cleared (+353 / +439 ms)           → only after subscriptions ready AND rereads succeeded
```

Socket open alone is insufficient; the first required subscription alone does not prematurely clear stale.
Ordering was observed naturally — no application code was modified to manufacture callback sequence. The
previously recorded 105-second stuck-"reconnecting" behaviour is **absent**: completion took 353 ms and
439 ms respectively.

---

## 7. Tenant-switch readiness

While the shared socket stayed connected:

| Switch | unsubscribed | subscribed | new sockets | closes | auth | list | detail | rows | final badge |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Local → Proof | old Local channel | Proof channel | **0** | **0** | 1 | 1 | **0** | 0 | **connected** |
| Proof → Local | Proof channel | Local channel | **0** | **0** | 1 | 1 | **0** | 110 | **connected** |

No global socket recreation, open panels cleared, zero initial detail/participant fan-out, and **no false
stale badge remains** — both directions reached `--success` deterministically. This closes the UI-D10
tenant-switch state regression.

---

## 8. Rapid-generation callback rejection

Rapid AppShell sequence `Local → Proof → Local` (700 ms apart):

```text
final active tenant        : Local (matches expected)
unsubscribe frames         : Local channel, then Proof channel
final active channel set   : [ private-tenant.{local}.runtime-nodes ]   includesProof: false
sockets opened / closed    : 0 / 0        (one shared socket, no duplicate)
channel-auth / list requests: 2 / 2       (one per legitimate generation)
per-node detail requests   : 0
badge transitions          : connecting → connected → connecting → connected
```

Only the final tenant's generation set readiness; no previous-tenant subscription or error callback cleared
stale or left an active channel, and no canonical reread persisted for an obsolete tenant.

---

## 9. Previous-tenant event rejection

Primary held on **Proof Tenant** with the Conference view active (badge connected, 0 rows). A second fresh
Playwright context authenticated naturally, selected **Local Tenant**, and performed one canonical
reversible Conference action through the authorized API from its own authenticated session (create → 201,
close → 200; no outbox insert, no Tinker, no direct database/Redis write, no hidden diagnostic path).

```text
primary active tenant           : Proof (unchanged)
accepted Local Tenant events    : 0
frames received in primary      : 0
Conference rereads              : 0
selected detail / participant   : 0 / 0
RuntimeNode rereads             : 0
leaked Local Tenant data        : 0 (rows remained 0)
```

The second context logged out and was closed.

---

## 10. Logout during reconnect, and normal logout

### Logout during reconnect

With the Conference channel active, Reverb was scaled to zero; the badge reached `--danger`
"disconnected — displayed data may be stale". Logout was performed through AppShell **before** restoring
Reverb, then Reverb was restored to one replica and observed for **45 seconds**:

```text
socket open attempts after logout : 0
subscription frames               : 0
frames of any kind                : 0
broadcasting-auth requests        : 0
canonical RuntimeNode/Conference rereads : 0
URL                               : /login   (live badge absent)
```

Late subscription-success or error callbacks could not revive live state — the realtime generation was
invalidated by logout.

### Normal logout after a successful reconnect

Re-authenticated naturally, selected Local Tenant, established the Conference subscription (123 rows,
connected), performed a controlled Reverb reconnect (badge `success → information → danger → information →
success`), then logged out normally:

```text
unsubscribed                 : private-tenant.{local}.conferences
socket closes                : [1000]      (normal teardown of the one socket)
reconnect attempts after     : 0
late subscription frames     : 0
broadcasting-auth after      : 0
canonical rereads after      : 0
requests after logout        : POST /api/v1/auth/logout
URL                          : /login   (live badge absent)
```

Cookies were never cleared manually.

---

## 11. Storage and security boundary

Sampled before connection, after reconnect, after tenant switching, after logout interruption, and after
normal logout:

```text
before connection        : ["pusherTransportTLS", "utcp.appearance"]        sessionStorage: []
after logout interruption: ["utcp.appearance"]                              sessionStorage: []
after normal logout      : ["pusherTransportTLS", "utcp.appearance"]        sessionStorage: []

pusherTransportTLS = {"timestamp":1784813679889,"transport":"ws","latency":45019,"cacheSkipCount":0}
  parses as JSON ✓   keys: [timestamp, transport, latency, cacheSkipCount]   transport: "ws" ✓
  tenant ID: none · user ID: none · channel: none · socket ID: none
  auth signature: none · application key: none · credential/secret: none
  RuntimeNode or Conference payload: none
```

No other unexpected keys; the vendor cache was not deleted. No protected data appeared in frames, URLs,
notifications, or storage at any point.

---

## 12. Runtime and error findings

```text
web image provenance          : sha256:f1395fb3… from 0b46434; server workloads preserved
WebSocket lifecycle           : one shared socket; 1000 on logout; 1006 + bounded retries during outages
subscription callbacks        : Echo subscribed()/error(), generation-fenced
broadcasting-auth requests    : 1 per subscription/generation; 0 after logout
reconnect generations         : 4 controlled outages, each exactly one resync generation
RuntimeNode rereads           : 1 list + 3 scoped per reconnect (one node open)
Conference rereads            : 1 list + 1 detail + 1 participants per reconnect
tenant switches               : 4 (incl. rapid sequence), 0 socket recreations
previous-tenant handling      : 0 accepted events, 0 rereads
logout interruption           : no revival across 45 s with Reverb healthy
worker failures / failed jobs : 0        outbox-dispatch errors : 0
Reverb errors                 : known startup RedisException CNI race only (non-blocking)
gateway errors                : 11 × 502 on /app/{key}, all inside the four deliberate scale-to-zero
                                windows — expected, not a defect
browser page errors           : 0        unhandled rejections : 0
duplicate rereads / fan-out   : none
```

No failed broadcast job and no stuck proof-generated outbox message remain.

---

## 13. Cleanup and final state

Reverb replicas restored to 1 with Redis OPEN. The one disposable Conference created for the
previous-tenant sanity event (`ui-d12-proof-1784813375949`) was closed through the canonical API in the same
step (desired_state `closed`, 200). Appearance remained **System** (never changed this run). Both browser
contexts logged out and closed, observers removed with them, `.playwright-mcp/` deleted, no port-forwards,
no plaintext credential scratch files.

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate succeeded=1 · reverb ClusterIP:8080 · reverb→redis OPEN · public WSS via Traefik 443
```

PostgreSQL, Redis, Asterisk, Kamailio, Traefik, observability, and unrelated telephony workloads were not
restarted.

---

## 14. Verification

| Check | Result |
| --- | --- |
| `npm run typecheck` / `lint` / `test` / `build` | passed (**75** frontend tests) |
| `php artisan test` (Reverb infra, RuntimeNode broadcast, fencing manifest, Conference broadcast) | **35 passed** (346 assertions) |
| `vendor/bin/pint --test` | passed |
| `make repository-hygiene` / `workflow-check` / `secret-scan` | passed |
| `git diff --check` / `--cached --check` | clean |

Frontend tests rose from 67 to **75**, and the served-bundle inspection in §1 confirms the production
lifecycle uses `subscribed()`/`error()` with no channel-level `bind`.

---

## 15. Outcome

The focused correction is live-proven end to end. Subscription readiness flows through Echo-supported
`subscribed()`/`error()` callbacks with generation fencing; the shared client reaches **connected**;
reconnect performs exactly one bounded canonical resynchronization (1 list plus scoped open/selected
resources, 0 unrelated) with stale clearing only after both subscription readiness and successful HTTP
rereads; tenant switching completes deterministically without socket recreation and without the false stale
badge; obsolete-generation callbacks cannot change current state; previous-tenant events cause no activity;
logout during reconnect prevents any later revival; and normal logout closes every channel and the single
socket cleanly. Storage stays bounded to `utcp.appearance` plus the vendor transport cache.

UI-D remains **In Progress** — this focused correction proof does not complete the remaining UI-D
operational surfaces (runtime operations, audit, and broader telephony views).
