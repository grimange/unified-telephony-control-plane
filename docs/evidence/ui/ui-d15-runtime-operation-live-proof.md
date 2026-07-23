# UI-D15 — Runtime Operations Live Proof (Blocked)

Verdict: `UI_D_RUNTIME_OPERATION_LIVE_PROOF_INCOMPLETE`

Source commit: `9977e3e` — `feat(ui): add live runtime operations`
Proof type: controlled live proof (evidence only; no production code changed).
Phase marker: `UTCP_PHASE=T1` (unchanged). UI-D remains **In Progress**.

The natural Playwright proof reached the Runtime Operations route and was then stopped by a
deterministic HTTP 500 on the canonical list and detail endpoints. A second, independent
structural gap was proven from the canonical outbox: no production code path can ever emit the
`runtime_operation.*` event the realtime corridor consumes. Both are recorded below with exact
authority, reproduction, and bounded implementation seams. Nothing was fabricated or worked
around.

---

## Repository Baseline

```text
branch      : main
HEAD        : 9977e3e
working tree: clean at start
UTCP_PHASE  : T1
push status : not pushed
```

Committed production contracts confirmed present at `9977e3e`:

| Contract | Location |
| --- | --- |
| Runtime Operation list/detail API | `apps/api/routes/web.php:54-55` → `AdminRuntimeOperationController` |
| `RuntimeOperationOperationalStateChanged` | `apps/api/app/Events/RuntimeOperationOperationalStateChanged.php` |
| `runtime_operation.*` bridge support | `apps/api/app/RuntimeEngine/Outbox/OperationalBroadcastBridge.php:80-108` |
| `tenant.{tenantId}.runtime-operations` | `apps/api/routes/channels.php:29-37` |
| `runtime.nodes.view` authorization | controller `index`/`show` + channel callback |
| `/operations/runtime-operations` | `apps/web/src/router/index.ts:49`, `apps/web/src/navigation.ts:27` |
| One shared Echo/Pusher client | `apps/web/src/realtime/runtimeNodeRealtime.ts` (single `echoClient`) |
| Notification-driven canonical reread | `RuntimeOperationsView.vue:495-504` |
| No Runtime Operation write controls | `RuntimeOperationsView.vue` template — only Refresh / Apply / Clear / Details |

---

## Images Built and Rolled Out

Built through the canonical path (`make k8s-image-build`, `make k8s-image-push`) with the
established frontend coordinates `app.utcp.local.test` / `443` / `wss` / `/app`, exposing only the
public Reverb application key. No Reverb secret was exposed at any point.

```text
registry manifest  api : sha256:e5c52cd3296f04b2b9c81521223bac6d0f7e715881f6ab5fd7d3d01bebc53813
registry manifest  web : sha256:5d3301f75d3d158eb6b88d2a1512d286499f589dc2f0bfc0cb994c59d7882505

running in-cluster api : sha256:d06905de63810facb64b04c7b1e3c922b334ea9f95e965a5c7992574868e15d2
running in-cluster web : sha256:d2a3da08f4a481a8259abee4d034924a21962a025bed7366680bc02302e8ca1f
```

Provenance confirmed behaviourally: before rollout `GET /api/v1/admin/runtime-operations`
returned `404 route could not be found`; after rollout the same request returned `401`
(unauthenticated) and, on an authenticated session, `500` — the `9977e3e` routes are live.

Explicitly restarted (mutable tags do not guarantee Pod replacement):

```text
api, worker, control-plane-outbox-dispatcher, web, reverb
```

Reverb was restarted because its Deployment uses the same changed API image, as the current
manifest establishes.

## Preserved Workloads

Not restarted by this proof: gateway (no image or configuration drift), PostgreSQL, Redis,
Asterisk, Kamailio, Traefik, observability, and all unrelated telephony workloads. The canonical
`make k8s-apply` reported `statefulset/postgres configured` and `statefulset/redis configured`,
but neither Pod was recreated — `postgres-0` and `redis-0` retained their pre-existing ages and
restart counts.

## Final Workload Readiness

```text
api                             1/1
worker                          1/1
control-plane-outbox-dispatcher 1/1
reverb                          1/1
gateway                         1/1
web                             1/1
utcp-migrate                    Complete 1/1 (BROADCAST_CONNECTION=log, no Reverb credentials)
```

```text
reverb → redis TCP 6379         : open
reverb Service                  : ClusterIP, port 8080 only
NodePort / LoadBalancer / HostPort for Reverb : none
browser WSS                     : Traefik 443 (wss://app.utcp.local.test/app/<public-key>)
outbox before proof             : 6498 dispatched, 0 pending, 0 retry_scheduled, 0 terminal_failed
failed broadcast jobs           : none
```

### Kubernetes API egress pin repaired canonically

Baseline drift was found and repaired only through the canonical renderer, with no manual policy
edit and no Traefik restart:

```text
before : allow-traefik-kubernetes-api         172.24.0.3/32   (stale)
         allow-runtime-fencer-kubernetes-api  172.24.0.3/32   (stale)
         actual kubernetes endpoint           172.24.0.5
repair : scripts/security/render-apiserver-policy
         kubectl apply -f .runtime/kubernetes/security/traefik-apiserver-egress.yaml
         kubectl apply -f .runtime/kubernetes/security/runtime-fencer-apiserver-egress.yaml
after  : scripts/security/check-apiserver-policy-drift → passed endpoint=172.24.0.5/32:6443
```

---

## Natural Login and Tenant

Real Login page → `admin@utcp.local.test` via a sanctioned bounded break-glass credential
(`scripts/identity/user-access-reset-password`, reason recorded, 90 min expiry) → forced password
change completed through the UI → `Local Tenant` selected through AppShell.

The Playwright context was made verifiably fresh before authenticating: all cookies were cleared
and both storage areas emptied, then the Login page was reloaded.

```text
cookies before clear : XSRF-TOKEN, unified-telephony-control-plane-api-session
cookies after clear  : []
storage after clear  : local [] / session []
```

No imported storage state, preset cookies, injected sessions, database- or Redis-created
sessions, or authentication bypasses were used.

## Runtime Operations Route and Navigation

Capability gating proven naturally. With no tenant selected the AppShell navigation exposed only
`/dashboard`, `/admin/tenants`, `/admin/users`. After selecting Local Tenant:

```text
/dashboard  /admin/tenants  /admin/users  /admin/memberships
/admin/runtime-nodes  /operations/runtime-operations  /operations/conferences
```

`/operations/runtime-operations` was entered by clicking the visible AppShell link — no manual URL
bypass. The route is read-only: the rendered surface exposes only **Refresh**, filter **Apply**,
filter **Clear**, and per-row **Details**. No retry, cancel, replay, repair, reconciliation,
delete, or create control exists.

---

## BLOCKER 1 — Canonical Runtime Operation read APIs fail on PostgreSQL

**Both canonical endpoints return HTTP 500 on every request against the canonical PostgreSQL
schema. The Runtime Operations route is completely non-functional on the canonical runtime.**

Observed in the browser on route entry:

```text
GET /api/v1/admin/runtime-operations?page=1&per_page=20  → 500
```

Observed on the detail endpoint through the same authenticated session:

```text
GET /api/v1/admin/runtime-operations/<operation-id>      → 500
```

API log (`deploy/api`, `2026-07-23T21:56:31Z`):

```text
SQLSTATE[42883]: Undefined function: 7 ERROR: operator does not exist: uuid = character varying
LINE 1: ...left join "runtime_nodes" on "runtime_nodes"."id" = "runtime...
HINT: No operator matches the given name and argument types.
SQL: select count(*) as aggregate from "runtime_operations"
     left join "runtime_nodes"
       on "runtime_nodes"."id" = "runtime_operations"."runtime_node_id"
      and "runtime_nodes"."tenant_id" = "runtime_operations"."tenant_id"
     where "runtime_operations"."tenant_id" = <active tenant>
```

### Root cause

`RuntimeOperationQuery::baseQuery()` (`apps/api/app/ControlPlane/RuntimeOperations/RuntimeOperationQuery.php:83-89`)
joins across a schema type boundary that PostgreSQL does not implicitly coerce:

| Column | Migration | PostgreSQL type |
| --- | --- | --- |
| `runtime_operations.tenant_id` | `$table->string('tenant_id')` | `character varying` |
| `runtime_operations.runtime_node_id` | `$table->string('runtime_node_id')` | `character varying` |
| `runtime_nodes.id` | `$table->uuid('id')` | `uuid` |
| `runtime_nodes.tenant_id` | `$table->uuid('tenant_id')` | `uuid` |
| `runtime_reconciliation_states.tenant_id` | `$table->uuid('tenant_id')` | `uuid` |

`baseQuery()` therefore emits `uuid = character varying` twice, and `find()` adds a third
occurrence via `reconciliation.tenant_id = runtime_operations.tenant_id`. PostgreSQL rejects all
three; the `index` count query fails first, so no row is ever returned.

### Why repository tests did not catch it

`apps/api/phpunit.xml:27` sets `DB_CONNECTION=sqlite`. SQLite renders `$table->uuid()` as
`varchar` and is dynamically typed, so the identical join succeeds there. All 26 focused
Runtime Operation tests and the full 376-test suite pass while the canonical PostgreSQL
deployment fails 100% of the time. The defect is invisible to the current test driver.

### Consequences observed

The UI presented the failure explicitly rather than silently ignoring it — an alert reading
`Runtime Operations unavailable / Server Error`, with no partial or fabricated rows. Because
`loadRuntimeOperations()` gates subscription behind a successful canonical snapshot
(`RuntimeOperationsView.vue:448-451`), the Runtime Operations channel never subscribed and the
badge stayed at `Live updates connecting`. That gating is correct behaviour; it is reported here
only to explain why the realtime steps could not run.

### Bounded implementation seam

`apps/api/app/ControlPlane/RuntimeOperations/RuntimeOperationQuery.php` — make the join operands
type-compatible on PostgreSQL, and add PostgreSQL-backed coverage (or an equivalent typed-schema
guard) so that a driver that enforces types is exercised for this query.

---

## BLOCKER 2 — No canonical workflow can emit a `runtime_operation.*` outbox event

**`OperationalBroadcastBridge::dispatchRuntimeOperationNotification()` is unreachable from
production code, so the `tenant.{tenantId}.runtime-operations` channel can never receive an
event.**

The bridge accepts a row only when `aggregate_type === 'runtime_operation'`
(`OperationalBroadcastBridge.php:89`). No production code path ever writes such a row.

Live canonical outbox, entire history:

```text
aggregate_type          count
runtime_node            4207
conference_participant  1072
conference               881
telephony_session        338
runtime_operation          0
```

`runtime_operations` rows themselves never carry that aggregate type:

```text
runtime_operations.aggregate_type : runtime_node (3469), conference_participant (920), conference (325)
```

### Root cause

`OutboxRepository::append()` is the single outbox write authority. Every caller passes a fixed
aggregate type, none of which is `runtime_operation`:

```text
CommandWorker.php:71                    forAggregate(..., $row->aggregate_type, ...)  → runtime_node | conference | conference_participant
AsteriskAriEventListener.php:653        'runtime_node'
TelephonyDomainService.php:178,509,727,812,992,1748   'telephony_session' | 'conference' | 'conference_participant'
ConferenceFailoverCoordinator.php:204   'conference'
AsteriskAriProfileService.php:110       'runtime_node'
SimulatorProfileService.php:206         'runtime_node'
RuntimeRegistryService.php:787          'runtime_node'
```

`RuntimeOperationRepository::complete()` appends only the envelope handed to it (built from the
operation's *target* aggregate); `fail()`, `markRunning()`, and enqueue append nothing at all.

Event *types* such as `runtime_operation.simulator_completed` and
`runtime_operation.asterisk_conference_ensured` do exist, but they are written under
`aggregate_type = runtime_node` / `conference`, so the bridge's runtime-node and conference
branches reject them (prefix mismatch) and the runtime-operation branch rejects them
(aggregate mismatch). They produce no notification on any channel.

`RuntimeOperationRealtimeBroadcastTest` passes because it synthesises outbox rows with
`aggregate_type => 'runtime_operation'` directly (`RuntimeOperationRealtimeBroadcastTest.php:98-99`);
the assumption in `docs/evidence/ui/ui-d14-runtime-operation-live-implementation.md:103-104`
("`aggregate_type` is always `runtime_operation`") is not satisfied by any producer.

### Bounded implementation seam

The canonical Runtime Operation lifecycle owner must emit a `runtime_operation.*` outbox event
keyed on `aggregate_type = 'runtime_operation'` and `aggregate_id = <runtime operation id>` at its
state transitions — most naturally inside `RuntimeOperationRepository` alongside the existing
persisted transitions, so the event is written in the same transaction as the state change and
retains the established outbox retry ownership. Alternatively the bridge contract is revised, but
that would change the committed channel and envelope contract and should not be done implicitly.

---

## Claims Blocked by the Above

The following required steps could not be executed, because each depends on a successful
canonical list snapshot or on a canonical `runtime_operation.*` delivery. No substitute,
synthetic, or fabricated result was produced for any of them.

```text
initial request budget (list = 1, detail = 0) : list request count PROVEN = 1, detail = 0,
                                                but the single list response was 500
pagination                                     : blocked by BLOCKER 1
filters (runtime_node_id, status, operation_type,
         created_from, created_to, correlation_id) : blocked by BLOCKER 1
page/filter preservation on reread              : blocked by BLOCKER 1
selected-detail request budget                  : blocked by BLOCKER 1 (no rows to select)
canonical Runtime Operation generation           : blocked by BLOCKER 2
broadcast envelope                               : blocked by BLOCKER 2
unrelated-operation reread                       : blocked by BLOCKER 2
selected-operation reread                        : blocked by BLOCKER 2
reconnect canonical resynchronization            : blocked by BLOCKER 1 (never subscribes)
Runtime Operations route-leave lifecycle         : blocked by BLOCKER 1 (never subscribes)
tenant-switch isolation for this channel         : blocked by BLOCKER 1
previous-tenant event rejection                  : blocked by BLOCKER 2
```

One partial positive is recorded: the route issued **exactly one** list request and **zero**
detail requests on entry, with no request scaling and no state supplied by the WebSocket stream.
The request budget shape is therefore consistent with the contract, but it cannot be claimed as
proven because the response was an error.

---

## Independently Proven Results

These were established naturally and are not affected by either blocker.

### Shared realtime lifecycle is not regressed by `9977e3e`

Navigating to `/admin/runtime-nodes` in the same session:

```text
websocket   : 1 created — wss://app.utcp.local.test/app/<public-key>
POST /api/broadcasting/auth                       → 200
recv pusher:connection_established
sent pusher:subscribe
recv pusher_internal:subscription_succeeded  channel private-tenant.<tenantId>.runtime-nodes
badge       : "Live updates connected"
api         : GET /api/v1/admin/runtime-node-catalog → 200
              GET /api/v1/admin/runtime-nodes        → 200
              (1 catalog + 1 list, zero per-node detail fan-out)
```

The proven RuntimeNode Reverb/WSS corridor, one-shared-client authority, and subscribe-after-
snapshot readiness all remain intact at `9977e3e`.

### Shared socket lifecycle on route change

Leaving Runtime Nodes for Runtime Operations:

```text
new sockets created : 0
sockets closed      : 1
```

The RuntimeNode channel was left and, because Runtime Operations never established a
subscription (BLOCKER 1), the shared client had no remaining channel and disconnected. No second
socket was ever created.

### Theme changes

```text
theme Dark → Light → System
Runtime Operation requests : 0
socket events              : 0
```

Theme changes issue no management requests and cause no reconnection.

### Logout teardown

```text
POST /api/v1/auth/logout                     → 200
post-logout broadcasting-auth requests       : 0
post-logout Runtime Operation list requests  : 0
post-logout Runtime Operation detail requests: 0
new sockets after logout                     : 0
real Login page                              : yes
```

Cookies were not cleared manually at logout.

### Storage and security boundary

Sampled before route entry, with the route loaded, after the theme changes, and after logout:

```text
localStorage   : utcp.appearance, pusherTransportTLS
sessionStorage : (empty)
```

No Runtime Operation rows, selected detail, operation IDs, RuntimeNode references, tenant IDs,
channel names, capabilities, correlation IDs, failure summaries, event payloads, authentication
material, credentials, or secrets were persisted. The bounded vendor cache `pusherTransportTLS`
was left in place and was not deleted to satisfy the boundary.

### Responsive behaviour at 375px

Measured on `/operations/runtime-operations` in Light and Dark:

```text
Light : documentElement.scrollWidth 375, window.innerWidth 375, overflow 0
Dark  : documentElement.scrollWidth 375, window.innerWidth 375, overflow 0
```

The heading and live badge wrap, the six filter fields stack coherently and remain reachable, and
the error alert stays inside the viewport in both themes. Row, long-identifier, selected-detail,
and pagination narrow-layout behaviour could not be assessed because no rows rendered.
Appearance was reset to System.

### Response-shape contract (repository-proven)

The committed resources model only the safe contract. `RuntimeOperationListResource` /
`RuntimeOperationDetailResource` expose id, runtime node reference, operation type, aggregate,
status, attempt, priority, correlation/request/causation ids, payload version, timestamps, a
`class:code` failure summary, and a bounded reconciliation reference. They contain no raw
operation payload, idempotency key, lease value, raw failure message, stack trace, outbox body,
audit payload, provider response, credential, adapter/SIP/endpoint secret, or environment value.
This remains a repository claim only; it was not live-confirmed because no response body was
returned.

---

## Console, Queue, Reverb, Gateway, and Network Findings

```text
page errors                        : 0
unhandled rejections               : 0
console errors                     : 3 × "Failed to load resource: 500" (BLOCKER 1)
                                     1 × "WebSocket is already in CLOSING or CLOSED state"
                                         (benign teardown race during logout)
                                     1 × pre-login /auth/session 401 (expected bootstrap probe)
duplicate rereads                  : none
unexpected detail fan-out          : none
failed broadcast jobs              : none
stuck proof-generated outbox rows  : none (0 pending; no proof operation was generated)
reverb errors                      : none
gateway errors                     : none
```

### Pre-existing environmental condition (not caused by this proof)

Nine workloads were already in `CrashLoopBackOff` at baseline, for 2–4 days, all with the same
cause: they inherit `BROADCAST_CONNECTION=reverb` from the shared platform config but their
Deployments do not reference `utcp-local-reverb-credentials`, so `Pusher::__construct()` receives
a null `auth_key` at boot.

```text
affected: scheduler, telephony-reconciler, telephony-command-worker, telephony-event-normalizer,
          simulator-event-source, utcp-runtime-fence-worker, asterisk-ari-events,
          kamailio-registration-observer (×2)
secretRef present only in: api, worker, control-plane-outbox-dispatcher, reverb
```

This is the same class as the recorded `utcp-migrate` broadcast-driver blocker. It caused
`make k8s-apply` to fail its `scheduler` rollout wait after all required objects had been applied
and the migration Job had completed. Classified as a **pre-existing environmental/configuration
condition**, not a defect introduced by `9977e3e`, and not a cause of either blocker above — both
blockers reproduce entirely within the healthy `api` workload. It is recorded because it would
independently prevent proof-generated Runtime Operations from progressing past `pending`.

`worker` and `control-plane-outbox-dispatcher` each restarted once immediately after the rollout
with transient Redis/PostgreSQL `Connection refused` at process start; both settled to `1/1` and
processed normally. Classified as a harmless startup race.

---

## Cleanup

```text
simulator RuntimeNode configuration : never modified (no proof operation was generated)
Runtime Operation history           : untouched; nothing deleted
Reverb replicas                     : 1 (never scaled; the reconnect proof could not run)
Reverb readiness / Redis 6379       : healthy / open
appearance                          : reset to System
browser contexts                    : logged out and closed
observers and interceptions         : removed with the context
.playwright-mcp/                    : removed
screenshots / scratch / credential files : none retained
temporary port-forwards             : none created
six required workloads              : all 1/1
unrelated telephony, database, Redis, Traefik, observability : not restarted
reverb Service                      : ClusterIP 8080
public WSS                          : Traefik 443
```

The break-glass credential was consumed by the forced password change and is not recorded here.

## Verification Performed

```text
apps/api : php artisan test <5 focused Runtime Operation / RuntimeNode / Conference realtime files>
           → 26 passed (187 assertions)
apps/api : php artisan test  → 376 passed, 2 skipped (3077 assertions)
apps/api : vendor/bin/pint --test → passed
apps/web : npm run typecheck → passed
apps/web : npm run lint      → passed
apps/web : npm run test      → 7 files, 83 passed
apps/web : npm run build     → built
repo     : make repository-hygiene → passed
repo     : make workflow-check     → passed
repo     : make secret-scan        → passed
repo     : git diff --check / git diff --cached --check → clean
security : scripts/security/check-apiserver-policy-drift → passed
```

Every focused and broad test passes. That is itself part of the finding: the current SQLite test
driver cannot observe BLOCKER 1, and the synthesised outbox fixtures cannot observe BLOCKER 2.

## Unresolved Proof Gaps

1. Runtime Operation list and detail must return `200` on the canonical PostgreSQL schema
   (BLOCKER 1) before any request-budget, pagination, filter, or selected-detail claim can be
   proven.
2. A canonical production workflow must emit a `runtime_operation.*` outbox event with
   `aggregate_type = 'runtime_operation'` (BLOCKER 2) before the envelope, unrelated-operation
   reread, selected-operation reread, reconnect resynchronization, route-leave, tenant-isolation,
   and previous-tenant-rejection claims can be proven.
3. Repository coverage must exercise a type-enforcing database driver for
   `RuntimeOperationQuery`, otherwise BLOCKER 1 can silently return.

## Deferred Work

- The shared-platform `BROADCAST_CONNECTION=reverb` / missing-secretRef crash loop across the nine
  non-broadcasting workloads. Non-blocking for UI-D15's principal claim, but it will block any
  proof that requires a Runtime Operation to progress beyond `pending`.
- Narrow-layout assessment of Runtime Operation rows, long identifiers, selected detail, and
  pagination at 375px, once rows can render.
