# UI-D4 — RuntimeNode Reverb/WSS Live Proof (Resumed; BLOCKED at origin validation)

Verdict: `UI_D1_RUNTIME_NODE_REALTIME_LIVE_PROOF_INCOMPLETE`

Proof type: controlled live proof, resumed from the previously blocked run and halted by a second,
different blocking defect.
Starting commit: `18ef90a` (`fix(k8s): isolate migration from reverb broadcasting`).
Implementation under proof: `79a8be6` (`feat(realtime): add runtime node live updates`).
Prior blocked run: [`ui-d2-runtime-node-realtime-browser-proof.md`](ui-d2-runtime-node-realtime-browser-proof.md)
(migration Job could not boot). Correction evidence: [`ui-d3-migration-broadcast-driver-fix.md`](ui-d3-migration-broadcast-driver-fix.md).
Phase marker `UTCP_PHASE=T1` (unchanged). Kubeconfig `.runtime/kubeconfig/utcp-local.yaml`, context
`k3d-utcp-local`. No production code was modified. No database schema changed.

**The UI-D2 migration blocker is resolved and confirmed fixed.** Deployment now succeeds end to end and the
public WSS corridor is proven to the point of the Reverb handshake. The proof is halted by a **new blocking
defect**: Reverb rejects every browser connection with `4009 Origin not allowed`.

---

## 1. Baseline and correction confirmation

```text
branch: main
HEAD:   18ef90a  fix(k8s): isolate migration from reverb broadcasting
tree:   clean
UTCP_PHASE=T1
```

Rendered workload boundary (verified from `render_overlay` and `kubectl kustomize`):

| Workload | `BROADCAST_CONNECTION` | Reverb credentials |
| --- | --- | --- |
| migration (`utcp-migrate`) | `log` | **none** (0 `REVERB_*` keys, 0 secretRefs) |
| api | `reverb` | ✅ `utcp-local-reverb-credentials` |
| worker | `reverb` | ✅ |
| control-plane-outbox-dispatcher | `reverb` | ✅ |
| reverb | `reverb` | ✅ |
| gateway / web | n/a | correctly absent |

Live values confirmed with `printenv` inside the running api, worker, outbox-dispatcher, and reverb pods:
all four report `BROADCAST_CONNECTION=reverb`.

---

## 2. Images built and rolled out

Built and pushed from clean `18ef90a` via `make k8s-image-build` + `make k8s-image-push`:

```text
api     sha256:c4f4f7aaf8eddd433e3af4b80cdab9145cfa5c858e42b1ff948531a33fae8817
web     sha256:2d96b5f9d1bee3f9b4cb2715be4b873a37e9d3da8cad27c6370a540edefe22fa
gateway sha256:4790ab7fdb46eed1b0ba3bcdb6d9ca2528fdc5be91ae8a7c3db8e062ab71472a
```

**Rollout gotcha recorded:** `make k8s-apply` recreated only the pods whose spec changed (api, worker,
outbox-dispatcher gained the Reverb secretRef; reverb was new). `gateway` and `web` have unchanged specs, so
Kubernetes did **not** recreate them and they kept serving pre-UI-D1 images (`gateway` from 2026-07-21,
`web` from 14:13Z) — the `/app/` location and the Echo-enabled bundle were therefore initially absent. An
explicit `kubectl rollout restart deployment/gateway deployment/web` was required. Same-tag deployments must
always force these two.

---

## 3. Migration Job result — UI-D2 blocker resolved

```text
utcp-migrate: succeeded=1
{"component":"migration","message":"process starting"}
{"component":"migration","message":"postgres ready for migration"}
INFO  Nothing to migrate.
```

The Job completed with `BROADCAST_CONNECTION=log` and **no Reverb credentials**, exactly as the UI-D3
correction intended, and canonically superseded the retained Failed Job from the UI-D2 run. `make k8s-apply`
completed successfully.

---

## 4. Final workload readiness

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
```

---

## 5. Public and internal Reverb port authority — PROVEN

Live Service:

```text
reverb   type=ClusterIP   port=8080
```

Whole rendered overlay (42,810 bytes): **0** occurrences of `NodePort`, `LoadBalancer`, `hostPort`, or
`6001`. No second Reverb hostname. Traefik remains the sole public TLS authority; the browser reached Reverb
only through `wss://app.utcp.local.test` on the default port `443`.

---

## 6. Canonical edge routing — PROVEN

| Path | Result |
| --- | --- |
| `/` | 200 |
| `/healthz` | 200 |
| `/dashboard` | 200 |
| `/admin/runtime-nodes` | 200 |
| `/app/{key}` (plain GET) | Reverb response (**not** the SPA catch-all) |
| `/api/broadcasting/auth` (unauth POST) | `401 application/json` from the API |

Before the gateway rollout, `/app/{key}` returned the SPA's `<!doctype html>` — after it, the Upgrade-aware
`location ^~ /app/` proxy correctly precedes `/api/` and `/`.

### WebSocket upgrade through the full public corridor — PROVEN

A real RFC6455 handshake through `https://app.utcp.local.test/app/{key}`:

```http
HTTP/1.1 101 Switching Protocols
Connection: upgrade
Upgrade: websocket
Sec-Websocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=
Server: nginx
X-Powered-By: Laravel Reverb
```

This proves `public WSS → Traefik 443 (TLS) → gateway nginx /app/ upgrade proxy → Reverb ClusterIP:8080`
works end to end.

---

## 7. BLOCKING DEFECT — Reverb rejects every origin (`4009 Origin not allowed`)

Immediately after the successful `101` upgrade, Reverb closes the connection:

```json
{"event":"pusher:error","data":"{\"code\":4009,\"message\":\"Origin not allowed\"}"}
```

Reproduced both **without** an `Origin` header and **with** the exact browser origin
`Origin: https://app.utcp.local.test`.

### Root cause

Deployed configuration (`infrastructure/kubernetes/overlays/local/platform/application-config.properties`,
also present in the root local overlay and compose env):

```properties
REVERB_ALLOWED_ORIGIN=https://app.utcp.local.test
```

feeding `apps/api/config/reverb.php:48`:

```php
'allowed_origins' => [env('REVERB_ALLOWED_ORIGIN', env('APP_URL', 'http://localhost'))],
```

Reverb's validator (`vendor/laravel/reverb/src/Protocols/Pusher/Server.php:190-206`):

```php
$origin = parse_url($connection->origin(), PHP_URL_HOST);   // → "app.utcp.local.test"

foreach ($allowedOrigins as $allowedOrigin) {
    if (Str::is($allowedOrigin, $origin)) { return; }        // Str::is("https://app.utcp.local.test", "app.utcp.local.test") === false
}

throw new InvalidOrigin;
```

The configured value carries a **scheme**, but Reverb matches against the parsed **host**. The pattern
contains no wildcard, so it can never match any host — **every** browser connection is rejected. The
`config/reverb.php` fallback `env('APP_URL')` is scheme-bearing too, so the default is equally unmatchable.

**Correction:** set the allowed origin to a host pattern — `REVERB_ALLOWED_ORIGIN=app.utcp.local.test` — in
every overlay/compose location, and change the `config/reverb.php` fallback so it cannot yield a
scheme-bearing default. This is a bounded configuration fix.

### Browser-observed consequence

The frontend built the **correct** public coordinates (extracted from the served bundle; key redacted):

```js
{ appKey: "<REDACTED>", wsHost: "app.utcp.local.test", wsPort: 443,
  wsScheme: "wss", wsPath: "/app", authEndpoint: "/api/broadcasting/auth" }
```

and opened exactly one socket, which was immediately rejected:

```json
[{"dir":"open-attempt","url":"wss://app.utcp.local.test/app/<public-key>"},
 {"dir":"ws-error","url":"wss://app.utcp.local.test/app/<public-key>"},
 {"dir":"ws-close","url":"wss://app.utcp.local.test/app/<public-key>","code":1006}]
```

The URL is correct in every respect the proof requires — canonical hostname, implicit public port 443, no
`reverb:8080`, no Pod IP, no custom public port. `POST /api/broadcasting/auth` count: **0** (subscription
never begins without a connection, which is correct sequencing).

### Secondary observation (not separately verified)

With the connection rejected, the Runtime Nodes view remained on **"Live updates connecting"** rather than
transitioning to a disconnected/stale presentation. `runtimeNodeRealtime.ts:216` sets `disconnected` when
`connectedOnce === false`, so the disconnect path appears not to have fired for this failure mode. This
could not be assessed properly while the primary blocker prevents any successful connection; it should be
re-checked once the origin defect is fixed. Canonical RuntimeNode data stayed fully visible and the
management view stayed usable throughout.

---

## 8. SECOND DEFECT — Reverb cannot reach Redis (NetworkPolicy gap)

Reverb logs on every start:

```text
RedisException: Connection refused
  at Illuminate/Redis/Connectors/PhpRedisConnector.php:185
```

Measured:

```text
reverb → redis.utcp-data:6379  = Connection refused
worker → redis.utcp-data:6379  = OPEN        (control)
```

**Root cause:** NetworkPolicy is enforced on both sides. `79a8be6` added
`infrastructure/kubernetes/security/platform/allow-reverb.yaml` with **egress** to Redis, but never added a
matching **ingress** rule on the Redis side. `infrastructure/kubernetes/security/data/allow-redis.yaml`
admits `api`, `worker`, `simulator-event-source`, `asterisk-ari-events`, `scheduler`, and `migration` — but
not `reverb`. That file was last touched by `19d7c08`, long before this feature.

**Why it matters:** the overlay sets `REVERB_SCALING_ENABLED=true`, and
`vendor/laravel/reverb/src/Servers/Reverb/ReverbServerProvider.php:29,37` makes scaling mode register the
`RedisPubSubProvider` for event fan-out. With Redis unreachable, the publish/subscribe path used to deliver
broadcasts is unavailable. Its precise delivery impact could **not** be measured here because the origin
defect (§7) prevents any client from connecting, so this is reported as a confirmed configuration defect
with unmeasured delivery consequence.

**Correction:** add the `reverb` network role to the Redis ingress policy (least-privilege, port 6379 only).
Both fixes are required before the next proof attempt.

---

## 9. Environmental issue encountered and canonically repaired

All edge routes returned Traefik `404` with `OriginStatus: 0` while TLS terminated normally and backends
were reachable. Cause: the IP-pinned `allow-traefik-kubernetes-api` NetworkPolicy still pinned
`172.24.0.5/32` while the live apiserver endpoint had shuffled to `172.24.0.3:6443`, so Traefik lost its
Kubernetes watch and served no dynamic routes.

Repaired canonically with the repository's own tooling — `scripts/security/render-apiserver-policy`
(re-rendered to `172.24.0.3/32:6443`) followed by applying the rendered policies. Routing recovered after
informer resync. This is the previously documented endpoint-pin drift class, an **environmental** condition,
not a defect in the commit under proof.

The `allow-reverb-required-traffic` policy was also absent because NetworkPolicies are applied by the
separate security step; `kubectl apply -k infrastructure/kubernetes/security` created it and updated the
api/gateway/worker/redis policies.

---

## 10. Proof steps completed before the blocker

- **Natural login** — real Login page, `admin@utcp.local.test` via sanctioned break-glass temporary
  credential, forced password change completed through the normal flow, landed on `/dashboard`. No injected
  cookies, preset sessions, or DB/Redis-created sessions.
- **Tenant selection** — `Local Tenant` chosen through the AppShell control; tenant capabilities activated
  (Memberships and Runtime nodes appeared in navigation).
- **RuntimeNode initial request budget — PROVEN.** Navigated via visible navigation to
  `/admin/runtime-nodes` with `performance.clearResourceTimings()` markers:

  ```text
  RuntimeNode data requests : 2   (runtime-node-catalog, runtime-nodes)
  per-node detail requests  : 0
  RuntimeNode rows          : 110
  ```

  No event payload supplied initial state; subscription is gated behind the canonical snapshot
  (`RuntimeNodesView.vue:765-791`, `subscribeAfterCanonicalSnapshot()` runs only after the resource reaches
  `success`/`empty`).
- **Storage boundary — PROVEN.** `localStorage` = `["utcp.appearance"]` only; `sessionStorage` empty. No
  secret, key, or auth signature in any recorded artifact; the WebSocket observer redacts the app key from
  URLs and records only protocol metadata and key *names* for non-approved payloads.
- **Logout** — performed through AppShell; returned to the real Login page.

---

## 11. Proof steps NOT performed (blocked by §7)

private-channel authorization · channel subscription · canonical RuntimeNode event trigger · outbox →
dispatcher → queued broadcast → Reverb correlation · broadcast envelope capture · automatic canonical reread
· scoped detail reread · notification fan-out check · Reverb outage/stale presentation · reconnect and
canonical resynchronization · tenant-switch subscription isolation · previous-tenant event rejection ·
logout socket-close verification · session rejection · responsive live-state presentation.

No simulator RuntimeNode configuration was changed. Reverb replica count was never altered.

---

## 12. Verification

| Check | Result |
| --- | --- |
| `php artisan test` (RuntimeNodeRealtimeBroadcast, ReverbRealtimeInfrastructure, RuntimeFencingManifest) | **27 passed** (241 assertions) |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` / `lint` / `test` / `build` | passed (**58** frontend tests) |
| `make k8s-config-check` / `security-config-check` / `runtime-engine-config-check` | passed |
| `make repository-hygiene` / `workflow-check` / `secret-scan` | passed |
| `git diff --check` / `--cached --check` | clean |
| `make k8s-apply` | **succeeded** (UI-D2 blocker resolved) |

Neither §7 nor §8 is covered by any existing static check or test: `ReverbRealtimeInfrastructureTest`
asserts workload/policy wiring and gateway ordering, but nothing asserts that `REVERB_ALLOWED_ORIGIN` is a
host pattern, nor that the Redis ingress policy admits the `reverb` role.

---

## 13. Cleanup and final state

Simulator configurations untouched · Reverb replicas unchanged (1) · browser logged out and context closed ·
observers removed with the context · `.playwright-mcp/` deleted · no port-forwards left running · no
plaintext credential scratch files.

```text
api 1/1 · worker 1/1 · control-plane-outbox-dispatcher 1/1 · reverb 1/1 · gateway 1/1 · web 1/1
utcp-migrate succeeded=1
reverb Service = ClusterIP:8080
public WSS = Traefik 443
```

No unrelated workload was restarted: PostgreSQL, Redis, Asterisk, Kamailio, Traefik, observability, and the
telephony workers were not restarted (Traefik was repaired by policy re-render only). The admin account
retains a proof-set password; no other disposable record was changed.

---

## 14. Outcome

The UI-D3 migration correction is **proven effective**, and the deployment plus the entire public transport
corridor — Traefik 443 TLS → gateway Upgrade proxy → Reverb ClusterIP:8080, with a real `101 Switching
Protocols` from Laravel Reverb — is **proven working**. Two bounded configuration defects block the
remaining behavioural proof:

1. `REVERB_ALLOWED_ORIGIN` carries a scheme where Reverb matches a host — rejects all clients (blocking).
2. Redis ingress policy omits the `reverb` role — breaks the scaling pub/sub path (must be fixed together).

UI-D remains **In Progress**; UI-D1 is **not** proven.

---

## 15. Follow-up (superseded)

Both defects recorded here were corrected in `ff3444f`
([`ui-d5-reverb-origin-redis-policy-fix.md`](ui-d5-reverb-origin-redis-policy-fix.md)) and the proof was
resumed in [`ui-d6-runtime-node-realtime-live-proof.md`](ui-d6-runtime-node-realtime-live-proof.md), which
**confirms both fixes live** — the browser's exact `Origin` now completes a full Pusher handshake instead of
being rejected with `4009`, and Reverb reaches Redis with pods at 0 restarts. UI-D6 halted on a third,
frontend defect: the Echo client is constructed but pusher-js never opens a socket. Note one correction to
this document — the single WebSocket attempt recorded in §7 was **this proof's own isolation probe**, not
the application; the application has never opened a socket, a fact that was masked here by the `4009`
rejection.
