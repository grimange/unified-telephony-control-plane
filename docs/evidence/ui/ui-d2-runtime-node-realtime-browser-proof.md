# UI-D2 — RuntimeNode Reverb/WSS Live-Update Proof (BLOCKED)

Verdict: `UI_D1_RUNTIME_NODE_REALTIME_LIVE_PROOF_INCOMPLETE`

Proof type: controlled live proof, halted by a blocking deployment defect in the commit under proof.
Starting commit: `79a8be6`. Phase marker: `UTCP_PHASE=T1` (unchanged).
Kubeconfig `.runtime/kubeconfig/utcp-local.yaml`, context `k3d-utcp-local`.
No production code was modified. No database schema changed.

**The live browser proof was not performed.** Deployment of `79a8be6` fails at the canonical
`make k8s-apply` step, before any application workload is rolled out. The blocker is documented below with
an exact, reproducible root cause and a bounded correction target.

---

## 1. Repository baseline

```text
branch: main
HEAD:   79a8be6  feat(realtime): add runtime node live updates
tree:   clean
UTCP_PHASE=T1
```

The committed implementation contains every element the proof requires:

| Element | Location |
| --- | --- |
| Laravel Reverb | `apps/api/composer.json` (+ `composer.lock`) |
| Broadcasting configuration | `apps/api/config/broadcasting.php`, `apps/api/config/reverb.php` |
| RuntimeNode broadcast event | `apps/api/app/Events/RuntimeNodeOperationalStateChanged.php` |
| RuntimeNode outbox bridge | `apps/api/app/RuntimeEngine/Outbox/RuntimeNodeBroadcastBridge.php`, wired in `OutboxDispatcher.php` |
| Private-channel authorization | `apps/api/routes/channels.php` |
| Reverb Deployment / Service | `infrastructure/kubernetes/base/platform/reverb-deployment.yaml`, `reverb-service.yaml` |
| Reverb NetworkPolicy | `infrastructure/kubernetes/security/platform/allow-reverb.yaml` |
| Upgrade-aware `/app/` proxy | `infrastructure/docker/gateway/nginx.conf` |
| Laravel Echo + Pusher | `apps/web/package.json` |
| Frontend realtime lifecycle authority | `apps/web/src/realtime/runtimeNodeRealtime.ts` |
| RuntimeNode live connection-state UI | `apps/web/src/views/RuntimeNodesView.vue` |

Publisher chain (from repository evidence):

```text
canonical mutation → control_plane_outbox_messages
→ control-plane-outbox-dispatcher (OutboxDispatcher → RuntimeNodeBroadcastBridge)
→ queued ShouldBroadcast event (afterCommit)
→ worker (queue:work, Redis)
→ Reverb → private tenant channel → browser
```

---

## 2. Blocking defect — migration Job cannot boot with `BROADCAST_CONNECTION=reverb`

### Symptom

`make k8s-apply` fails. The `utcp-migrate` Job never completes, so the apply aborts **before** any platform
workload (api, worker, outbox-dispatcher, reverb, gateway, web) is updated.

```text
job.batch/utcp-migrate created
error: timed out waiting for the condition on jobs/utcp-migrate
```

Migration pod log (sanitized):

```text
{"component":"migration","message":"process starting"}
{"component":"migration","message":"postgres ready for migration"}
RuntimeException: Failed to create broadcaster for connection "reverb" with error:
Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given
  at Illuminate/Broadcasting/BroadcastManager.php:297
```

Cluster state after the failed apply:

```text
NAME           STATUS   COMPLETIONS   DURATION
utcp-migrate   Failed   0/1           4m55s
```

### Root cause

`79a8be6` changed the **migration** overlay's broadcast driver but did not give that Job the Reverb
credentials it now requires:

```diff
# infrastructure/kubernetes/overlays/local/migration/application-config.properties
-BROADCAST_CONNECTION=log
+BROADCAST_CONNECTION=reverb
+REVERB_SERVER_HOST=0.0.0.0
+REVERB_SERVER_PORT=8080
+REVERB_HOST=reverb.utcp-platform.svc.cluster.local
+REVERB_PORT=8080
+REVERB_SCHEME=http
+REVERB_SCALING_ENABLED=true
+REVERB_ALLOWED_ORIGIN=https://app.utcp.local.test
```

`php artisan migrate --force` boots the full application. `bootstrap/app.php` calls `withBroadcasting(...)`,
which loads `routes/channels.php` and resolves the **default** broadcast connection. With
`BROADCAST_CONNECTION=reverb` and `REVERB_APP_KEY` absent, `config/broadcasting.php` passes `null` into the
Pusher constructor and the process dies before any migration runs.

### Blast radius — exactly one workload

The Reverb Secret is correctly wired into every workload that actually broadcasts, and **only** the
migration Job is missing it:

| Workload | `utcp-local-reverb-credentials` secretRef | Driver |
| --- | --- | --- |
| api | ✅ `base/platform/api-deployment.yaml:45` | reverb |
| worker | ✅ `base/platform/worker-deployment.yaml:42` | reverb |
| control-plane-outbox-dispatcher | ✅ `base/platform/outbox-dispatcher-deployment.yaml:42` | reverb |
| reverb | ✅ `base/platform/reverb-deployment.yaml:45` | reverb |
| **utcp-migrate** | ❌ **absent** (`base/migration/migration-job.yaml:33-39` has only `utcp-local-data-credentials` + `utcp-local-kamailio-db-credentials`; `overlays/local/migration/kustomization.yaml` has no reverb `secretGenerator`) | **reverb** |

### Deterministic reproduction (outside the cluster)

```bash
cd apps/api
env -u REVERB_APP_KEY -u REVERB_APP_SECRET -u REVERB_APP_ID \
  BROADCAST_CONNECTION=reverb php artisan migrate --pretend
# → Failed to create broadcaster for connection "reverb" … $auth_key must be of type string, null given
```

Note the failure requires the key to be **absent** (null). An empty-string key does not reproduce it, which
is why no existing static check or unit test catches this.

### Both candidate corrections verified

```bash
# A) migration overlay keeps a non-broadcasting driver (no reverb creds needed)
env -u REVERB_APP_KEY -u REVERB_APP_SECRET -u REVERB_APP_ID \
  BROADCAST_CONNECTION=log php artisan migrate --pretend      # → 0 broadcaster errors

# B) migration Job receives the Reverb credentials
REVERB_APP_ID=… REVERB_APP_KEY=… REVERB_APP_SECRET=… \
  BROADCAST_CONNECTION=reverb php artisan migrate --pretend   # → 0 broadcaster errors
```

**Recommended correction: (A).** The migration Job runs only `php artisan migrate --force`; it never
publishes a broadcast. Granting it the Reverb application secret would widen credential exposure for no
functional reason. Restoring `BROADCAST_CONNECTION=log` (and dropping the unused `REVERB_*` lines) in
`infrastructure/kubernetes/overlays/local/migration/application-config.properties` keeps the migration
workload least-privileged and is the smallest coherent fix. This is a bounded infrastructure-config
correction, not an architecture change.

---

## 3. What was proven before the blocker

### Images built and pushed from clean `79a8be6`

`make k8s-image-build` + `make k8s-image-push` succeeded. Registry digests:

```text
api     sha256:d0f2691ef17a37c1f5ef227bc61860ea0c08d6b934c9b03d0dfe81b10ba54188
web     sha256:d56e989ab32681719c094451601fe57375f91ce7735c11dc912c17673e8a6387
gateway sha256:dbf5878025c97932df46f09abcd5cfc5b6585a0723186a2a00001a6e7b4ca003
```

These images were **never rolled out** — the apply aborted at the migration Job.

### Reverb credential generation is automatic

`scripts/kubernetes/lib` gained `ensure_local_reverb_credentials`, called from `render_overlay` and
`scripts/kubernetes/image-build`. It generates `REVERB_APP_ID`, a 16-byte `REVERB_APP_KEY`, and a 32-byte
`REVERB_APP_SECRET` into a gitignored `0600` properties file. **No manual operator-created Secret is
required.** Secret values are not recorded in this evidence.

### Frontend build coordinates (public key only)

`scripts/kubernetes/image-build` passes only the **public** application key plus the canonical public
coordinates to the web build; the application secret is never a frontend build argument:

```text
VITE_UTCP_REVERB_APP_KEY=<public key, not recorded>
VITE_UTCP_WS_HOST=app.utcp.local.test
VITE_UTCP_WS_PORT=443
VITE_UTCP_WS_SCHEME=wss
VITE_UTCP_WS_PATH=/app
```

### Port authority — statically proven from the rendered overlay

Rendered via `render_overlay` (42,810 bytes):

```yaml
kind: Service
metadata: { name: reverb, namespace: utcp-platform }
spec:
  type: ClusterIP
  ports: [ { name: ws, port: 8080, targetPort: ws, protocol: TCP } ]
```

```text
NodePort | LoadBalancer | hostPort occurrences in entire render: 0
```

Gateway routing order in `infrastructure/docker/gateway/nginx.conf` — the Upgrade-aware `/app/` location is
declared **before** the `/api/` and `/` locations, so it cannot be captured by the frontend catch-all:

```nginx
location ^~ /app/ {
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_pass http://reverb:8080;
}
location ^~ /api/ { … fastcgi_pass api:9000; }
location /       { … proxy_pass http://web:8080; }
```

Channel-auth route resolves to the API under the `api` prefix with session middleware:

```text
GET|POST|HEAD  api/broadcasting/auth  →  Illuminate\Broadcasting\BroadcastController
bootstrap/app.php: withBroadcasting(channels.php, ['prefix' => 'api', 'middleware' => ['web','identity.session']])
```

### Contract review of the event and channel

`RuntimeNodeOperationalStateChanged` implements `ShouldBroadcast` **and** `ShouldDispatchAfterCommit`
(`public bool $afterCommit = true`), broadcasts as `runtime-node.operational-state.changed` on
`PrivateChannel("tenant.{tenantId}.runtime-nodes")`, and its `broadcastWith()` returns exactly the five
approved metadata fields — `event_type`, `aggregate_type` (literal `runtime_node`), `runtime_node_id`,
`tenant_id`, `occurred_at`. No configuration values, endpoints, credentials, or full RuntimeNode object.

`routes/channels.php` rejects any tenant that is not the session's `active_tenant_id`, then delegates to
`AuthorizationService::requireTenant($user->id, $tenantId, 'runtime.nodes.view')`. The frontend is not an
authorization authority.

`RuntimeNodeBroadcastBridge` publishes only for `aggregate_type === 'runtime_node'` rows whose `event_type`
begins `runtime_node.`, and only when a non-empty `tenant_id` exists.

---

## 4. Verification performed

| Check | Result |
| --- | --- |
| `php artisan test` (RuntimeNodeRealtimeBroadcastTest, ReverbRealtimeInfrastructureTest, RuntimeFencingManifestTest) | **23 passed** (192 assertions) |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` | passed |
| `npm run lint` | passed |
| `npm run test` | **58 passed** (7 files) |
| `npm run build` | passed |
| `make k8s-config-check` | passed |
| `make security-config-check` | passed |
| `make runtime-engine-config-check` | passed |
| `make repository-hygiene` | passed |
| `make workflow-check` | passed |
| `make secret-scan` | passed |
| `git diff --check` / `git diff --cached --check` | clean |
| `make k8s-apply` | **FAILED — migration Job blocker (§2)** |

Every static and automated check passes. The defect is a runtime configuration gap that no current static
check or unit test covers — `ReverbRealtimeInfrastructureTest` asserts the platform workloads' Reverb wiring
but does not assert the migration Job's broadcast driver.

---

## 5. Proof steps not performed

Blocked by §2; none of the following were exercised:

public WSS connection · private-channel authorization · channel subscription · canonical RuntimeNode event
source · outbox/queued-broadcast/Reverb correlation · broadcast envelope capture · automatic canonical
reread · scoped detail reread · detail fan-out · Reverb outage presentation · reconnect and canonical
resynchronization · tenant-switch isolation · previous-tenant event rejection · logout disconnect ·
session-rejection · security and storage boundary · responsive live-state presentation · natural login and
tenant selection · initial request budget.

No Playwright session was started.

---

## 6. Environment state

Preserved. The apply aborted before touching any platform workload:

```text
reverb deployment ......... not created (NotFound)
api / worker / control-plane-outbox-dispatcher / gateway / web ... 1/1 ready, pre-proof images, not restarted
utcp-migrate Job .......... Failed (retained as defect evidence, not deleted)
```

No unrelated telephony, database, Redis, Traefik, or observability workload was restarted. No direct
database or Redis mutation was performed. Reverb remains absent, so the ClusterIP-only/8080 and public
Traefik-443 boundaries are unchanged in the live runtime.

The failed `utcp-migrate` Job is deliberately retained rather than deleted, per the proof contract's
requirement to preserve evidence when a real defect appears. It is superseded automatically on the next
successful `make k8s-apply`.

---

## 7. Outcome

The UI-D1 implementation's application-level contracts (event, channel, bridge, port authority, gateway
routing, credential generation, frontend coordinates) review and test cleanly. A single bounded
infrastructure-configuration defect in the **migration** overlay blocks all deployment and therefore the
entire live proof.

Required next step: bounded infrastructure correction (recommended option A in §2), then re-run this live
proof unchanged. UI-D remains **In Progress**; UI-D1 is **not** proven.

---

## 8. Follow-up (superseded)

The recommended option A correction was implemented in `18ef90a`
([`ui-d3-migration-broadcast-driver-fix.md`](ui-d3-migration-broadcast-driver-fix.md)) and the blocked run
was resumed in [`ui-d4-runtime-node-realtime-live-proof.md`](ui-d4-runtime-node-realtime-live-proof.md),
which **confirms the migration blocker recorded here is resolved** — `make k8s-apply` now succeeds and
`utcp-migrate` completes with `BROADCAST_CONNECTION=log` and no Reverb credentials. UI-D4 additionally
proved the full public WSS corridor (`101 Switching Protocols` from Laravel Reverb through Traefik 443),
then halted on two different bounded configuration defects (`REVERB_ALLOWED_ORIGIN` scheme mismatch and a
missing `reverb` role in the Redis ingress policy). This document remains the historical record of the
migration blocker only.
