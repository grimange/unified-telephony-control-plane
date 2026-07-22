# UI-D6 — RuntimeNode Reverb/WSS Live Proof (Transport PROVEN; blocked on frontend client)

Verdict: `UI_D1_RUNTIME_NODE_REALTIME_LIVE_PROOF_INCOMPLETE`

Proof type: controlled live proof, resumed from the previously blocked run.
Source commit: `ff3444f` (`fix(realtime): correct reverb origin and redis access`).
Implementation under proof: `79a8be6` (`feat(realtime): add runtime node live updates`).
Prior blocked run: [`ui-d4-runtime-node-realtime-live-proof.md`](ui-d4-runtime-node-realtime-live-proof.md).
Correction evidence: [`ui-d5-reverb-origin-redis-policy-fix.md`](ui-d5-reverb-origin-redis-policy-fix.md).
Phase marker `UTCP_PHASE=T1` (unchanged). Kubeconfig `.runtime/kubeconfig/utcp-local.yaml`, context
`k3d-utcp-local`. No production code was modified. No database schema changed.

**Both UI-D5 corrections are confirmed effective.** The origin fix is proven live — the server-side WSS
corridor now completes a full Pusher handshake — and Reverb reaches Redis. The proof is halted by a **new,
third blocking defect, this time in the frontend**: the browser's Echo client is constructed and
`.private()` is called, but pusher-js never opens a WebSocket and never requests channel authorization.

---

## 1. Baseline and correction confirmation

```text
branch: main
HEAD:   ff3444f  fix(realtime): correct reverb origin and redis access
tree:   clean
UTCP_PHASE=T1
```

Committed configuration verified in every location:

```text
infrastructure/kubernetes/overlays/local/platform/application-config.properties:37  REVERB_ALLOWED_ORIGIN=app.utcp.local.test
infrastructure/kubernetes/overlays/local/application-config.properties:36           REVERB_ALLOWED_ORIGIN=app.utcp.local.test
infrastructure/compose/compose.yaml:44 / env.example:23                             app.utcp.local.test
apps/api/.env.example:53                                                            app.utcp.local.test
```

`apps/api/config/reverb.php` now derives a host-only fallback:

```php
$defaultAllowedOrigin = static function (): string {
    $host = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $host : 'localhost';
};
$allowedOrigin = env('REVERB_ALLOWED_ORIGIN') ?: $defaultAllowedOrigin();
```

`infrastructure/kubernetes/security/data/allow-redis.yaml` ingress roles now include `reverb`
(`redis, api, worker, simulator-event-source, asterisk-ari-events, reverb, scheduler, migration`).
Migration overlay remains `BROADCAST_CONNECTION=log` with no `REVERB_*` keys.

Live environment on all four publisher/serving workloads:

```text
api / worker / control-plane-outbox-dispatcher / reverb
  BROADCAST_CONNECTION   = reverb
  REVERB_ALLOWED_ORIGIN  = app.utcp.local.test
```

---

## 2. Images built and rolled out

Built and pushed from clean `ff3444f`:

```text
api     sha256:4a861d38ed413a0e08a4f9a87a32fec0d90c37eec17b8e056e7c3ea6706284f1
web     sha256:da375be112490a50228d226fa01c7c64dd240d79af1d0edbfc4cc40e5a6bae70
gateway sha256:1c47876d3310ad24a7dc7447efd47d74bbcd7dbbc4f9217831958878f286ce74
```

### Mutable-tag rollout gotcha — now confirmed to extend beyond gateway/web

`make k8s-apply` recreated **no** application pod this run, because no Deployment spec changed (the Reverb
secretRef was already present from the previous run). Explicit restarts were required for **six**
Deployments, not just the two UI-D4 identified:

```text
kubectl -n utcp-platform rollout restart deployment/gateway deployment/web deployment/reverb
kubectl -n utcp-platform rollout restart deployment/api deployment/control-plane-outbox-dispatcher deployment/worker
```

This matters beyond image freshness: `envFrom` ConfigMap changes (the corrected
`REVERB_ALLOWED_ORIGIN`) are **not** hot-reloaded into running Pods, so without the restarts the publishers
and Reverb would have continued running the old scheme-bearing origin. After the restarts all six ran the
`ff3444f` images (api-family digest `5a86e1e1f6c46fbe…`, gateway `03b27e5909710b4a…`, web `765e8ac5ab82a967…`).

---

## 3. Traefik API-policy state

No refresh required. The rendered pin already matched the live endpoint:

```text
live apiserver endpoint : 172.24.0.3:6443
allow-traefik-kubernetes-api ipBlock : 172.24.0.3/32
```

Traefik was not restarted and continued serving routes throughout.

---

## 4. Migration Job result

```text
utcp-migrate: succeeded=1
INFO  Nothing to migrate.
```

Completed with `BROADCAST_CONNECTION=log` and no Reverb credentials.

---

## 5. Final workload readiness

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
```

---

## 6. Reverb Redis connectivity — CORRECTED

```text
reverb → redis.utcp-data.svc.cluster.local:6379  = OPEN
```

The pre-fix pod had accumulated **10 restarts** crash-looping on the denied Redis connection; the post-fix
pods run with **0 restarts** and a stable listener on `0.0.0.0:8080`.

**Residual startup-timing divergence (non-blocking):** each freshly-created Reverb pod still logs exactly
one `RedisException: Connection refused` at process start (≈1s after container start), after which the pod
becomes and stays Ready with Redis reachable. This is consistent with the CNI programming the new Pod's
NetworkPolicy rules a moment after the container starts, not with the policy being wrong — the policy is
correct, and a `fsockopen` probe from the same running pod returns `OPEN`. Worth a bounded startup-retry or
readiness-gate follow-up; it did not affect this proof.

---

## 7. Public and internal Reverb port authority — PROVEN

```text
Service reverb: type=ClusterIP  port=8080  nodePort=<none>
```

Rendered overlay scan for `NodePort|LoadBalancer|hostPort|6001`: **0 matches**. No second public Reverb
hostname. Traefik remains the sole public TLS ingress.

---

## 8. Canonical edge routing — PROVEN

| Path | Result |
| --- | --- |
| `/` · `/healthz` · `/dashboard` · `/admin/runtime-nodes` | 200 |
| `/app/{key}` (plain GET) | Reverb response, **not** SPA HTML |
| `/api/broadcasting/auth` (unauth POST) | `401 application/json` from the API |

### Full public WSS corridor — PROVEN, origin fix confirmed

A real RFC6455 handshake carrying the browser's exact `Origin: https://app.utcp.local.test`:

```http
HTTP/1.1 101 Switching Protocols
X-Powered-By: Laravel Reverb
```
```json
{"event":"pusher:connection_established","data":"{\"socket_id\":\"…\",\"activity_timeout\":30}"}
```

Compare UI-D4, where the identical request returned `{"event":"pusher:error","data":"{\"code\":4009,
\"message\":\"Origin not allowed\"}"}`. **The UI-D5 origin correction is live-proven.** The corridor
`browser → wss://app.utcp.local.test:443 → Traefik TLS → gateway /app/ Upgrade proxy → Reverb ClusterIP:8080`
works end to end.

---

## 9. Natural login, tenant, and RuntimeNode request budget — PROVEN

Real Login page → `admin@utcp.local.test` via sanctioned break-glass temporary credential → forced password
change completed through the UI → `/dashboard` → `Local Tenant` selected through AppShell (a
`Proof Tenant 1784195144` also exists for the tenant-isolation step). No injected cookies, preset sessions,
or DB/Redis-created sessions.

Navigating through visible navigation to `/admin/runtime-nodes` with markers established immediately before:

```text
RuntimeNode rows            : 110
runtime-node-catalog        : 1   (+10 ms)
runtime-nodes (list)        : 1   (+48 ms)
per-node detail requests    : 0
broadcasting/auth requests  : 0
```

Snapshot completed at +48 ms. No event payload supplied initial state; subscription is gated behind the
canonical snapshot (`RuntimeNodesView.vue:765-791`).

Storage boundary held throughout: `localStorage = ["utcp.appearance"]`, `sessionStorage` empty — before,
during, and after logout.

---

## 10. BLOCKING DEFECT — the browser never opens a WebSocket

### Observation

On both an in-app navigation and a fresh direct load of `/admin/runtime-nodes` (110 rows rendered, session
and catalog and list all fetched), waiting 8–12 s each time:

```text
Playwright page.on('websocket') events        : 0
requests to /app/*                            : 0
POST /api/broadcasting/auth                   : 0
in-page WebSocket constructions               : 0
JS console errors                             : none (only the expected pre-auth /auth/session 401)
```

The Playwright-level observer is independent of any in-page instrumentation, so this is not an artefact of
the proof harness.

### The client *is* being constructed

The live-updates badge renders with the class `ui-status-badge--information`:

```html
<span class="ui-status-badge ui-status-badge--information live-updates-badge">Live updates connecting</span>
```

Per `RuntimeNodesView.vue:645-653`, `information` maps **only** to connection state `connecting`; the
initial `idle` state maps to `neutral`, and a null transport config would set `disconnected` → `danger` with
the text "Live updates disconnected — displayed data may be stale". Therefore, in
`runtimeNodeRealtime.ts:99-131`, execution passed the `sessionActive()`/`tenantId` guard **and** the
`config === null` guard, set state to `connecting`, ran `echoClient = echoClientFactory(config)`
(`new Echo({...})`), and reached `echoClient.private('tenant.{id}.runtime-nodes')`.

Supporting facts:

- The deployed bundle contains the full realtime path — `broadcaster:\`reverb\``, the channel template,
  the `operational-state.changed` event name, and both connected/disconnected status strings.
- The baked transport config is correct: `{ wsHost: "app.utcp.local.test", wsPort: 443, wsScheme: "wss",
  wsPath: "/app", authEndpoint: "/api/broadcasting/auth", appKey: <present> }`.
- The session payload really does expose `active_tenant.tenant_id`, so the guard could not have failed.
- The web bundle is byte-identical to UI-D4's (`index-V76hwj8T.js`) — `ff3444f` changed no frontend file —
  so this defect was present, and masked by the `4009` rejection, in the previous run too. The single socket
  recorded in UI-D4 was this proof's own isolation probe, not the application.

### Where the defect lies

Between `new Echo({...})` / `.private(...)` and any network activity — i.e. in the pusher-js client options
built by `createEchoClient` (`apps/web/src/realtime/runtimeNodeRealtime.ts:155-173`). Two option choices are
suspect and should be examined first:

```js
enabledTransports: [config.wsScheme],   // → ['wss']
wsPath: config.wsPath,                  // → '/app'
```

`enabledTransports` is applied by pusher-js as a name filter
(`(!config.enabledTransports || arrayIndexOf(config.enabledTransports, name) !== -1)`), and the default
strategy registers WebSocket legs under **two** names — `ws` and `wss` (both mapping to registry type `ws`).
Enabling only `wss` disables the `ws`-named leg; if the composite strategy's WebSocket path resolves through
that leg, no transport remains and pusher-js fails silently with no connection and no error — which matches
every observation. The conventional Laravel Echo/Reverb configuration passes `enabledTransports: ['ws','wss']`
(or omits the key) and lets `forceTLS` select TLS, which `createEchoClient` already sets correctly.

Separately, `wsPath: '/app'` is likely a latent second problem: pusher-js treats `wsPath` as a path
**prefix** and appends `/app/{key}` itself, which would yield `/app/app/{key}`. This could not be observed
because no connection is attempted, but it should be resolved in the same fix (Reverb's public route is
already `/app/{key}`, so `wsPath` should be left unset).

This is a bounded frontend fix; it is **not** covered by any existing test — the frontend suite injects a
stub client via `setRuntimeNodeRealtimeClientFactory`, so `createEchoClient`'s real option set is never
exercised.

---

## 11. Proof steps NOT performed (blocked by §10)

private-channel authorization · channel subscription · canonical RuntimeNode event trigger · outbox →
dispatcher → queued broadcast → Reverb correlation · broadcast envelope capture · automatic canonical reread
· scoped detail reread · notification fan-out check · Reverb outage/stale presentation · reconnect and
canonical resynchronization · tenant-switch subscription isolation · previous-tenant event rejection ·
logout socket-close verification · session rejection.

No simulator RuntimeNode configuration was changed. Reverb replica count was never altered (no outage
injection was performed, since there was no connection to disrupt).

---

## 12. Additional finding — 375 px page-level overflow on Runtime Nodes

Measured on `/admin/runtime-nodes` while authenticated:

```text
document.documentElement.scrollWidth : 406
window.innerWidth                    : 375      → 31 px page-level horizontal overflow
live-updates badge                   : visible, right edge 303 (inside viewport), text readable
```

The badge itself fits and does not obscure management controls, but the page overflows horizontally. UI-B5
previously proved `/admin/runtime-nodes` at 0 page-level overflow at 375 px, and the live-updates badge was
added to this view's header row by `79a8be6`, so this is a regression against the established UI-B
responsive contract. Light/dark and theme-request checks were not completed because the primary blocker made
the connected/disconnected state comparison meaningless.

---

## 13. Verification

| Check | Result |
| --- | --- |
| `php artisan test` (ReverbRealtimeInfrastructure, RuntimeNodeRealtimeBroadcast, RuntimeFencingManifest) | **30 passed** (309 assertions) |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` / `lint` / `test` / `build` | passed (**58** frontend tests) |
| `make k8s-config-check` / `security-config-check` / `runtime-engine-config-check` | passed |
| `make repository-hygiene` / `workflow-check` / `secret-scan` | passed |
| `git diff --check` / `--cached --check` | clean |
| `make k8s-apply` | succeeded |

Every automated check passes while the corridor is unusable from a browser — the frontend suite stubs the
Echo factory, so no test covers the real pusher-js option set.

---

## 14. Cleanup and final state

Simulator configurations untouched · Reverb replicas unchanged (1) · browser logged out through AppShell and
returned to the real Login page · Playwright context closed · observers removed with the context ·
`.playwright-mcp/` deleted · no port-forwards · no credential scratch files.

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate succeeded=1 · reverb Service ClusterIP:8080 · reverb→redis OPEN
worker log: 0 failures/exceptions · no stuck proof-generated outbox message (none were generated)
```

PostgreSQL, Redis, Asterisk, Kamailio, Traefik, observability, and unrelated telephony workloads were not
restarted. The admin account retains a proof-set password; no disposable record was changed.

---

## 15. Outcome

Both UI-D5 corrections are **live-proven**: Reverb accepts the browser's origin (full Pusher handshake) and
reaches Redis. The complete server-side corridor — Traefik 443 TLS, gateway Upgrade proxy, Reverb
ClusterIP:8080, migration isolation, publisher configuration — is proven working. The remaining blocker has
moved into the frontend: the Echo client is constructed but pusher-js never opens a socket, so no
subscription, authorization, or event delivery can occur.

Required next step: bounded frontend correction to `createEchoClient`'s pusher-js options (plus the 375 px
overflow regression), with a test that exercises the real option set rather than the injected stub. UI-D
remains **In Progress**; UI-D1 is **not** live-proven.
