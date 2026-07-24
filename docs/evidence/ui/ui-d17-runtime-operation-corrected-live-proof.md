# UI-D17 — Corrected Runtime Operations Live Proof

Verdict: `UI_D_RUNTIME_OPERATION_CORRECTED_LIVE_PROOF_COMPLETE`

Source commit: `39ea898` — `fix(runtime): restore operation reads and notifications`
Proof type: controlled live proof (evidence only; no production code changed).
Phase marker: `UTCP_PHASE=T1` (unchanged). UI-D remains **In Progress**.

Related evidence:

* [`docs/evidence/ui/ui-d15-runtime-operation-live-proof.md`](ui-d15-runtime-operation-live-proof.md) — the blocked proof that found both defects.
* [`docs/evidence/ui/ui-d16-runtime-operation-postgres-outbox-fix.md`](ui-d16-runtime-operation-postgres-outbox-fix.md) — the bounded correction.

Both UI-D15 blockers are closed and live-proven. The Runtime Operations surface now works
end to end on the canonical PostgreSQL runtime, and the canonical notification corridor
(`RuntimeOperationRepository` transition → `aggregate_type=runtime_operation` outbox row →
`OperationalBroadcastBridge` → queued Reverb notification → tenant channel → canonical HTTP
reread) is proven with real production-generated events.

---

## Repository Baseline

```text
branch      : main
HEAD        : 39ea898
working tree: clean at start
UTCP_PHASE  : T1
```

Committed contracts confirmed at `39ea898`:

| Contract | Evidence |
| --- | --- |
| UUID-alignment migration exists | `database/migrations/2026_07_24_120000_align_runtime_operation_uuid_columns.php` |
| Migration validates before converting | `assertRuntimeOperationUuidCompatibility()` — malformed UUID + unresolved tenant/RuntimeNode reference checks, each throwing before any `ALTER TABLE` |
| No permanent UUID/text casts in the query | `grep` for `cast\|::uuid\|::text\|CAST` in `RuntimeOperationQuery.php` → no matches |
| Repository emits canonical aggregate events | `RuntimeOperationRepository::appendRuntimeOperationEvent()` uses `aggregateType: 'runtime_operation'`, `aggregateId: $runtimeOperationId`; `EVENT_CREATED='runtime_operation.created'`, `EVENT_STATUS_CHANGED='runtime_operation.status_changed'` |
| Producer-backed broadcast tests | `RuntimeOperationRealtimeBroadcastTest::test_repository_transition_produces_runtime_operation_outbox_and_dispatcher_bridges_it` (+ idempotency and rejection cases) |
| PostgreSQL-gated read test | `RuntimeOperationPostgresReadApiTest` (skips unless `pgsql`) |
| Publisher / non-publisher roles explicit | 4 publishers carry `utcp-local-reverb-credentials`; 9 non-publisher workloads pin `BROADCAST_CONNECTION=log` with zero credential references |
| Migration remains `log`, credential-free | `base/migration/migration-job.yaml` → `BROADCAST_CONNECTION=log`, no Reverb secretRef |
| Rendered checks enforce the boundary | `scripts/kubernetes/config-check` and `scripts/security/config-check` both implement `validate_broadcasting_boundary()` over rendered manifests |

---

## Existing-Data Validation

Read-only PostgreSQL inspection before the migration. Nothing was mutated, normalized, deleted,
or replaced.

```text
runtime_operations row count            : 4714
non-null tenant_id                      : 4714
non-null runtime_node_id                : 4714
invalid UUID-format tenant_id           : 0
invalid UUID-format runtime_node_id     : 0
unresolved tenant references            : 0
unresolved RuntimeNode references       : 0
```

The data satisfied every migration precondition, so validation could not be bypassed and was not
bypassed.

## UUID Migration Result

Executed by the canonical migration Job during `make k8s-apply`:

```text
2026_07_24_120000_align_runtime_operation_uuid_columns ....... 179.33ms DONE
utcp-migrate  Complete 1/1  (8s)
BROADCAST_CONNECTION = log
envFrom = utcp-application-config, utcp-local-data-credentials, utcp-local-kamailio-db-credentials
reverb credential ref present = False
```

No schema-altering SQL was run outside the committed migration.

## Live PostgreSQL Schema

```text
runtime_operations.tenant_id       : uuid, nullable YES
runtime_operations.runtime_node_id : uuid, nullable YES
rows preserved                     : 4714 (unchanged)
```

Indexes and constraints intact:

```text
runtime_operations_pkey                 PRIMARY KEY btree (id)
runtime_ops_aggregate_idx               btree (aggregate_type, aggregate_id)
runtime_ops_claim_idx                   btree (status, priority, available_at, created_at)
runtime_ops_idempotency_unique          UNIQUE (operation_type, idempotency_key)
runtime_ops_type_status_tenant_node_idx btree (operation_type, status, tenant_id, runtime_node_id)
```

Read-only join probes equivalent to the canonical query, run with **no casts added**:

```text
runtime_operations ⋈ runtime_nodes (id + tenant_id)                → 4714 rows
runtime_operations ⋈ runtime_reconciliation_states (last_operation_id + tenant_id) → 4714 rows
runtime_operations ⋈ runtime_nodes (inner, resolved names)         → 4714 rows
```

No `uuid = character varying` error occurred. `grep` of the API log after the proof:

```text
SQLSTATE[42883] occurrences : 0
uuid = character varying    : 0
```

## Canonical Apply Result

```text
make k8s-apply → "K1 platform applied. Proof command: make k8s-proof"
```

The apply completed **without any rollout-wait failure**. Under UI-D15 the same command aborted on
the `scheduler` rollout wait because nine workloads crash-looped on a null Reverb `auth_key`.

## Images Built and Rolled Out

Built through the canonical path (`make k8s-image-build`, `make k8s-image-push`) from a clean
`39ea898` working tree.

```text
API registry manifest digest : sha256:d4284d67e8f3c61f07fa92d7f0fed2ef5be60bb92fe954ea3fcb704d91d94cd8
API running in-cluster digest: sha256:7c7c1db9f46fd7c03007f5d0633c929235bcfc1df4b334006573d778a7e4f314
```

Web was **not** rebuilt and **not** restarted. `git diff --stat 9977e3e..39ea898 -- apps/web` is
empty, so the running bundle `web@sha256:d2a3da08f4a481a8259abee4d034924a21962a025bed7366680bc02302e8ca1f`
is exactly the Runtime Operations implementation proven in UI-D15. Gateway was not rebuilt.

Explicitly restarted (mutable tags do not guarantee Pod recreation): `api`, `worker`,
`control-plane-outbox-dispatcher`, `reverb`. The nine broadcaster-changed workloads were recreated
by the apply itself because their Deployment specs changed.

`utcp-runtime-fence-worker` is intentionally staged **out** of the canonical local overlay
(`scripts/telephony-domain/config-check:188` fails if `runtime-fencing` appears in
`overlays/local`), so `make k8s-apply` does not manage it. Its live Deployment was a residue of an
earlier T5 proof still inheriting `BROADCAST_CONNECTION=reverb` from the shared ConfigMap. The
committed `components/runtime-fencing/infrastructure-worker-deployment.yaml` (which `39ea898`
changed to `log`) was applied to it with only the image placeholder resolved to the local registry
tag. It then booted clean for the first time in four days.

## Publisher Workload Result

```text
workload                          BROADCAST_CONNECTION   reverb credentials   boot errors
api                               reverb (ConfigMap)     present              0
worker                            reverb (ConfigMap)     present              0
control-plane-outbox-dispatcher   reverb (ConfigMap)     present              0
reverb                            reverb (ConfigMap)     present              0
```

Canonical host/port/scheme are supplied by `utcp-application-config`
(`REVERB_HOST=reverb.utcp-platform.svc.cluster.local`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`),
validated by `scripts/security/config-check` which fails a publisher missing any of
`REVERB_HOST/PORT/SCHEME` or `REVERB_APP_ID/KEY/SECRET`.

## Non-Publisher Workload Result

```text
workload                          BROADCAST_CONNECTION   reverb credentials   boot errors
asterisk-ari-events               log                    absent               0
kamailio-registration-observer    log                    absent               0
scheduler                         log                    absent               0
simulator-event-source            log                    absent               0
telephony-command-worker          log                    absent               0
telephony-event-normalizer        log                    absent               0
telephony-reconciler              log                    absent               0
utcp-runtime-fence-worker         log                    absent               0
utcp-migrate (Job)                log                    absent               0
```

`gateway`, `kamailio`, and `web` consume no `utcp-application-config` at all (nginx / Kamailio
containers) and construct no broadcaster.

A `null auth_key` / `BroadcastManager` scan across every Deployment returned **0** occurrences.
Under UI-D15 these same nine workloads had accumulated 38–45 restarts each. No Reverb credential
was distributed to any non-publisher to achieve this.

## Final Workload Readiness

```text
api 1/1   worker 1/1   control-plane-outbox-dispatcher 1/1   reverb 1/1
gateway 1/1   web 1/1   scheduler 1/1   telephony-command-worker 1/1
telephony-event-normalizer 1/1   telephony-reconciler 1/1   simulator-event-source 1/1
asterisk-ari-events 1/1   kamailio-registration-observer 2/2   utcp-runtime-fence-worker 1/1
kamailio 1/1   utcp-migrate Complete 1/1
```

```text
reverb → redis TCP 6379 : open
reverb Service          : ClusterIP, port 8080 only, no nodePort
public WSS              : Traefik 443 (wss://app.utcp.local.test/app/<public-key>)
failed broadcast jobs   : none
outbox                  : 6557 dispatched, 0 pending / retry_scheduled / terminal_failed
```

The stale Kubernetes API egress pin repaired in UI-D15 remained correct
(`check-apiserver-policy-drift` → `passed endpoint=172.24.0.5/32:6443`); no policy edit was needed.

---

## Natural Login and Tenant

Real Login page → `admin@utcp.local.test` via a sanctioned bounded break-glass credential
(`scripts/identity/user-access-reset-password`, reason recorded) → forced password change through
the UI → `Local Tenant` via AppShell.

The context was made verifiably fresh before authenticating:

```text
cookies before clear : XSRF-TOKEN, unified-telephony-control-plane-api-session
cookies after clear  : []
storage after clear  : local [] / session []
```

No imported storage state, preset cookies, injected sessions, database- or Redis-created sessions,
or authentication bypasses were used.

## Runtime Operations Route

Capability gating proven naturally:

```text
no tenant selected  : /dashboard  /admin/tenants  /admin/users
Local Tenant active : + /admin/memberships  /admin/runtime-nodes
                      + /operations/runtime-operations  /operations/conferences
```

Entered by clicking the visible AppShell link — no URL bypass. The route is read-only: the only
controls are **Refresh**, filter **Apply**, filter **Clear**, per-row **Details**, and
**Previous**/**Next**. No retry, cancel, replay, repair, reconciliation, delete, or create control
exists.

## PostgreSQL List and Detail Result

```text
GET /api/v1/admin/runtime-operations?page=1&per_page=20        → 200
GET /api/v1/admin/runtime-operations/{validId}                 → 200
unauthenticated route probe                                    → 401 (not 500)
error alert from the UI-D15 PostgreSQL failure                 → absent
```

The reconciliation join — the third `uuid = varchar` site — resolves live in the detail payload:
`reconciliation: { target_type: conference, target_id: da52bf37-…, status: converged }`.

## Initial Request Budget

```text
Runtime Operation list requests : 1
Runtime Operation detail requests: 0
total operations                : 4714
page / page size                : 1 / 20   (Page 1 of 236)
list response                   : 200 at +84 ms from route entry
channel authorization           : POST /api/broadcasting/auth → 200 at +184 ms
channel subscription            : pusher_internal:subscription_succeeded at +194 ms
                                  channel private-tenant.<tenantId>.runtime-operations
websockets created              : 1
```

No request count scaled with the 4714 operations; the WebSocket stream supplied no initial state.

## Pagination Result

Server-backed and bounded. One list request per explicit page change, zero detail requests
throughout, and no full-dataset download (20 or 50 rows rendered, never 4714).

```text
Next  → ?page=2&per_page=20   200   Page 2 of 236   1 list request, 0 detail
Next  → ?page=3&per_page=20   200   Page 3 of 236   1 list request, 0 detail
Prev  → ?page=2&per_page=20   200   Page 2 of 236   1 list request, rows identical to first visit
50/pg → ?page=1&per_page=50   200   Page 1 of 95    1 list request, 50 rows, reset to page 1
```

Ordering is deterministic: returning to page 2 restored the identical 20 IDs, and the 50-row page
equals pages 1+2+first-half-of-3 concatenated in the same order.

## Filter Results

Every exposed filter was exercised from a non-default page (page 2) to prove the page reset. Each
produced exactly **one** list request, **zero** detail requests, the exact canonical query
parameter, and a correct `Clear` back to the unfiltered 4714.

```text
filter            request                                                    result   page reset
runtime_node_id   ?runtime_node_id=05ddb383-…&page=1&per_page=20      200    81 rows   yes
status            ?status=terminal_failed&page=1&per_page=20          200    55 rows   yes
operation_type    ?operation_type=conference.close&page=1&per_page=20 200   159 rows   yes
correlation_id    ?correlation_id=8896c81b…&page=1&per_page=20        200     1 row    yes
created_from/to   ?created_from=2026-07-23T11%3A39%3A00Z
                  &created_to=2026-07-23T11%3A39%3A30Z&page=1&per_page=20 200 1 row    yes
```

Returned rows satisfy the filters: `status=terminal_failed` returned exactly the 55 rows the
database holds in that state and rendered "terminal failed"; `operation_type=conference.close`
returned exactly the 159 `conference.close` rows; the correlation-ID and bounded-interval filters
each isolated the same single known operation `c77b8e02`. All values came from the safe list
response — no hidden or raw payload field was inspected.

Backend validation through the visible UI, using a safely invalid value:

```text
correlation_id = "not-a-valid-correlation-id"
→ GET …?correlation_id=not-a-valid-correlation-id&page=1&per_page=20 → 422
→ presented: "Runtime Operations unavailable — The correlation id field format is invalid."
→ previously loaded rows retained (stale-but-usable), not silently ignored
→ Clear restored 4714
```

## Page and Filter Preservation

An active filter plus a non-default page size were held across both reread triggers.

Notification rereads (selected-operation transition, 4 events):

```text
query before : ?per_page=10&runtime_node_id=056c6db0-5587-4e0e-b31f-bc593dd8379e
every reread : /api/v1/admin/runtime-operations?runtime_node_id=056c6db0-…&page=1&per_page=10
query after  : ?per_page=10&runtime_node_id=056c6db0-…   (unchanged)
```

Reconnect reread:

```text
/api/v1/admin/runtime-operations?runtime_node_id=056c6db0-…&page=1&per_page=10
```

No notification reset the operator to page one. Explicit user filter changes did reset to page one,
as designed.

## Selected Detail Budget

```text
select first operation   : detail requests 1, list requests 0
select a different one   : detail requests 1 (new ID only), list requests 0, open panels 1
```

Canonical detail response contract — exactly 21 keys:

```text
aggregate, attempt, available_at, cancelled_at, causation_id, completed_at, correlation_id,
created_at, expires_at, failure, id, operation_type, payload_version, priority, reconciliation,
request_id, runtime_node, runtime_node_id, started_at, status, updated_at
```

Absent from the response and the UI: raw payload, idempotency key, lease state, raw failure
message, stack trace, outbox body, audit payload, provider response, credential, secret, endpoint
value, environment value. The only `payload`-prefixed key is `payload_version` (an integer).
`failure` is a bounded `{class, code, summary, occurred_at}` shape and was `null` for succeeded
operations.

---

## Canonical Runtime Operation Source

Generated only through the normal Runtime Nodes Web Admin lifecycle against disposable
`simulator-deterministic` RuntimeNodes, using reversible non-secret changes on the
descriptor-rendered form (`seed`) and the canonical desired-state controls (`Activate` /
`Disable`). The primary tab stayed mounted on Runtime Operations throughout; the RuntimeNode
actions were driven from a second tab in the same natural session, and later from a second fully
independent context.

Nothing was inserted directly. No Tinker, no direct PostgreSQL or Redis write, no hidden endpoint,
no Artisan command used as a management surface.

One observation worth recording: `SimulatorRuntimeNodeReconciler` skips `draft` and `disabled`
nodes, and a converged node needs a configuration-generation bump. A seed change on a *disabled*
node therefore produced a canonical `runtime_node.simulator_configuration_changed` outbox row but
no Runtime Operation — correct behaviour, not a defect. Operations were obtained by activating the
node (and/or bumping its configuration generation) through the canonical controls.

## Production Runtime Operation Outbox Rows

```text
aggregate_type='runtime_operation' rows before proof : 0
aggregate_type='runtime_operation' rows after proof  : 36
```

First generated operation, written by the repository transition (not manually):

```text
event_type                        aggregate_type    aggregate_id                       tenant_id      dispatch
runtime_operation.created         runtime_operation 5ef21997cd1f440a9761d140680d87c4   7be59d2a-…     dispatched
runtime_operation.status_changed  runtime_operation 5ef21997cd1f440a9761d140680d87c4   7be59d2a-…     dispatched
runtime_operation.status_changed  runtime_operation 5ef21997cd1f440a9761d140680d87c4   7be59d2a-…     dispatched
runtime_operation.status_changed  runtime_operation 5ef21997cd1f440a9761d140680d87c4   7be59d2a-…     dispatched

payload: {"runtime_node_id":"d227ab0e-…","runtime_operation_id":"5ef21997cd1f440a9761d140680d87c4"}
runtime_operations row: runtime.node.apply_configuration, succeeded, attempt 1, started/completed 23:57:03
```

The four rows per operation correspond exactly to the four persisted repository transitions
(`create`, `claimAvailable`, `markRunning`, `complete`). Payload metadata is bounded to the
Runtime Operation ID plus the RuntimeNode ID when present — no request payload, result payload,
failure body, command, endpoint, provider response, or credential.

## Outbox and Broadcast Delivery

```text
Web Admin action        23:57:0x  Activate on disposable simulator RuntimeNode
reconciler enqueue      23:57:02  runtime.node.apply_configuration
outbox rows written     23:57:02–03  4 × aggregate_type=runtime_operation
outbox dispatch         all 4 → dispatched
browser frames          4 × runtime-operation.operational-state.changed
                        on private-tenant.<tenantId>.runtime-operations
canonical rereads       4 list rereads (one per event), 0 detail rereads
list total              4714 → 4715
```

## Broadcast Envelope

Every captured frame carried exactly the seven approved keys and nothing else:

```text
dataKeys: [aggregate_id, aggregate_type, event_type, occurred_at,
           runtime_node_id, runtime_operation_id, tenant_id]

event_type          : runtime_operation.created | runtime_operation.status_changed
aggregate_type      : runtime_operation
aggregate_id        : 5ef21997cd1f440a9761d140680d87c4
runtime_operation_id: 5ef21997cd1f440a9761d140680d87c4   (equal to aggregate_id)
runtime_node_id     : d227ab0e-dd7c-45d8-a9f4-068f98c9351d
tenant_id           : 7be59d2a-07c8-4b4e-a86d-c97771a670b9   (active tenant)
occurred_at         : 2026-07-23T23:57:02.000000Z
```

Confirmed absent: operation **status** (the envelope carries no status field at all, so the browser
cannot treat it as authority), request payload, result payload, failure body, commands, endpoints,
provider responses, credentials, stack traces, and the full outbox payload.

## Unrelated-Operation Reread

With operation `91b5f055` selected, a *different* operation (`5ef21997`) completed its lifecycle:

```text
list rereads          : 1 per event (4 events → 4)
selected detail rereads: 0
other detail requests : 0
active page/filters   : preserved
```

The UI updated only from the canonical list response.

## Selected-Operation Reread

Executed deterministically. `telephony-command-worker` is the dedicated general Runtime Operation
executor (`runtime-engine:command-worker`, excluding only the fence and restore types); it was
scaled to zero while `telephony-reconciler`, `control-plane-outbox-dispatcher`, `worker`, `web`,
and `reverb` all stayed up. Non-terminal operations were 0 beforehand, so no unrelated work was
delayed. Execution then arrived via the scheduler's per-minute `command-worker --once`, and the
dedicated worker was restored to 1 afterwards.

```text
operation a51402885c9ba52179c8772ad3df0340 (runtime.node.inspect) created → status pending
selected through the UI : 1 detail request, 0 list requests, detail shows "pending"

on the subsequent repository-owned transition (3 status_changed events for this operation
plus 1 unrelated event in the same window):
  selected-operation events : 3
  list rereads              : 4   (exactly one per event, selected + unrelated)
  selected detail rereads   : 3   (exactly one per selected-operation event)
  unrelated detail requests : 0
  first transition          : +15.3 s after selection
  page/filters preserved    : ?runtime_node_id=056c6db0-…&page=1&per_page=10 on every reread
```

Final status and timestamps came from the detail HTTP response, not the envelope:

```text
Status    : succeeded
Started   : 2026-07-24T00:12:00.000000Z
Completed : 2026-07-24T00:12:00.000000Z
Attempt   : 1 / 3
```

No database state, hidden flag, or artificial outbox insertion was used to slow or manipulate
execution.

## Reconnect Resynchronization

Reverb scaled 1 → 0 with the route mounted, operation `a5140288` selected, and the active
filter/page size held.

```text
outage:
  badge                      : "Live updates disconnected — displayed data may be stale"
  sockets closed             : 1
  list/detail rereads        : 0   (no immediate canonical reread)
  rows still visible         : 3   detail still visible, status "succeeded"
  Refresh / Apply            : enabled and usable
  query                      : ?per_page=10&runtime_node_id=056c6db0-…  (unchanged)

recovery (Reverb restored to 1):
  channel reauthorized       : POST /api/broadcasting/auth → 200
  subscription               : pusher_internal:subscription_succeeded on
                               private-tenant.<tenantId>.runtime-operations
  list rereads               : 1
  selected detail rereads    : 1
  unrelated detail requests  : 0
  page and filters           : preserved
  selection                  : preserved (a5140288)
  badge                      : "Live updates connected" — stale cleared after the HTTP rereads
  sockets in this generation : 2 created / 1 closed (original + one reconnect)
```

Exactly one bounded resync per reconnect generation; no duplicate.

## Route-Leave Lifecycle

```text
leave → /operations/conferences:
  frames                     : pusher:unsubscribe  →  pusher:subscribe
                               → subscription_succeeded on private-tenant.<t>.conferences
  new sockets                : 0     closed sockets: 0   (shared socket retained)
  Runtime Operation requests : 0, still 0 after a further 12 s
  Runtime Operation frames   : 0     (callbacks removed)

return → /operations/runtime-operations:
  list requests              : 1 (fresh)
  detail requests            : 0
  subscription               : 1 fresh subscription_succeeded on the runtime-operations channel
  new sockets                : 0
```

## Tenant-Switch Isolation

```text
before (Local Tenant) : selected 92edd705, 20 rows, 4722 total
switch to Proof Tenant 1784195144:
  old channel left          : pusher:unsubscribe sent
  new channel subscribed    : private-tenant.28678536-….runtime-operations
  selected operation        : cleared
  new tenant list requests  : 1
  initial detail requests   : 0
  new sockets               : 0    closed: 0   (one shared socket retained)
  old tenant rows           : gone (0 rows, empty state)
  leaked Local Tenant rows  : 0
  badge                     : connected after snapshot + subscription
```

## Previous-Tenant Event Rejection

A second fully independent Playwright context authenticated naturally and selected Local Tenant
while the primary stayed on Proof Tenant. One canonical Runtime Operation was generated there
through the RuntimeNode Web Admin workflow.

```text
second context : activated disposable node 67bceac5 → operation 0cc372d73f0549c98e864403c6c86610
                 (runtime.node.apply_configuration) created AND executed at 00:17:03,
                 producing 4 further runtime_operation outbox rows

primary context (Proof Tenant), observed for 102 s:
  accepted Local Tenant events   : 0   (only pusher:ping / pusher:pong)
  Runtime Operation list rereads : 0
  detail rereads                 : 0
  leaked Local Tenant rows       : 0
```

The result is non-vacuous: the Local Tenant operation demonstrably ran during the observation
window.

## Logout Teardown

```text
POST /api/v1/auth/logout            → 200
sockets closed                      : 1
sockets created after logout        : 0   (no reconnect)
post-logout broadcasting-auth       : 0
post-logout Runtime Operation calls : 0
real Login page                     : yes
```

Cookies were not cleared manually. No explicit `pusher:unsubscribe` frame precedes logout because
the client disconnects the shared socket outright; channel teardown is evidenced by the socket
close plus the zero post-logout auth and request counts. Explicit per-channel unsubscribe is
separately proven in the route-leave section.

## Storage Boundary

Sampled before route entry, with the list loaded, with a detail selected, after notifications,
after reconnect, after the tenant switch, and after logout:

```text
localStorage   : pusherTransportTLS, utcp.appearance
sessionStorage : (empty)

utcp.appearance    = "system"
pusherTransportTLS = {"timestamp":…,"transport":"ws","latency":…,"cacheSkipCount":0}
```

No Runtime Operation rows, selected detail, operation IDs, RuntimeNode references, tenant IDs,
channel names, capabilities, correlation IDs, failure summaries, event payloads, authentication
material, credentials, or secrets were persisted. The bounded vendor cache was left in place.

## Responsive Result

At `375px`, in Light and Dark:

```text
                     Light    Dark
scrollWidth          375      375
window.innerWidth    375      375
overflow             0        0
root overflow-x      visible  visible   (no clipping mask hiding a defect)
filters stacked      yes      yes
pagination reachable yes      yes
rows readable        yes      yes
status badges        "succeeded" / "attempt 1/3" / "priority 100"  — text, not colour alone
long identifiers     overflow-wrap: anywhere
selected detail      present, fully within viewport, page overflow 0
```

Theme changes issued no Runtime Operation management request and created no socket
(0 socket events). Appearance was reset to System.

Focus measurement note: under headless synthetic focus, `getComputedStyle(el, ':focus-visible')`
returns empty `outline`/`boxShadow` even though the element does receive focus (confirmed
`document.activeElement === button`). This is the established measurement limitation recorded in
the UI-B evidence, not a finding about this route.

---

## Console, Queue, Reverb, Gateway, API, and Network Findings

```text
page errors                  : 0
unhandled rejections         : 0
duplicate rereads            : none (1 per event; 1 per reconnect generation)
unexpected detail fan-out    : none (unrelated detail requests were 0 in every scenario)
failed broadcast jobs        : none
stuck proof-generated outbox : none — all 6557 rows dispatched, 0 pending
non-terminal operations      : 0
Reverb errors                : none
gateway errors               : none
API SQLSTATE[42883]          : 0
broadcaster boot failures    : 0 across all 15 Deployments
```

Console errors, all explained and deliberate:

```text
1 × 422  — the intentional invalid-correlation-id validation test
3 × "WebSocket handshake: Unexpected response code: 502" — during the intentional
        Reverb scale-to-zero outage
1 × "WebSocket is already in CLOSING or CLOSED state" — benign teardown race at logout
```

### Divergence: instrumentation error during the first selected-operation attempt

The first attempt at the selected-operation proof cleared the observer buffers *after* selecting
the operation but *before* the transition arrived, discarding the frames and rereads. The detail
panel independently showed the operation had moved to `succeeded` with fresh timestamps, so the
behaviour was correct, but the measurement was lost. Classified as an **observer error on the
proof harness, not a product defect**. The claim was re-proven cleanly with timestamp-based phase
separation and a single buffer clear, and only that second measurement is reported above.

### Divergence: `runtime_operation.status_changed` count per operation

Each operation produces one `created` plus three `status_changed` events, because
`claimAvailable`, `markRunning`, and `complete` are each persisted repository transitions. Each
event drives exactly one list reread and, when the operation is selected, exactly one detail
reread. This is the designed contract, not duplication.

---

## Cleanup

```text
RuntimeNode configurations restored (all five disposable simulator nodes):
  c4-live-proof-drift-1784174711  desired_state=disabled  seed=c4-live-proof-drift-1784174711
  c4-live-proof-drift-1784163362  desired_state=disabled  seed=c4-live-proof-drift-1784163362
  c4-live-proof-drift-1784123414  desired_state=disabled  seed=c4-live-proof-drift-1784123414
  c4-live-proof-drift-1784068139  desired_state=disabled  seed=c4-live-proof-drift-1784068139
  c4-live-proof-drift-1784071066  desired_state=disabled  seed=c4-live-proof-drift-1784071066

telephony-command-worker replicas : restored to 1 (ready)
scheduler replicas                : restored to 1 (ready)
reverb replicas                   : restored to 1 (ready), Redis 6379 open
generated operations              : all reached canonical terminal state (0 non-terminal)
Runtime Operation history         : preserved — nothing deleted
filters cleared through the UI    : yes (4723 total, Page 1 of 237)
pagination                        : back to the initial page 1 / per_page 20
selected detail                   : closed
appearance                        : reset to System
browser contexts                  : both logged out and closed
observers / interceptions         : removed with the contexts
.playwright-mcp/                  : removed
screenshots / scratch / credential files : none retained
temporary port-forwards           : none created
Reverb Service                    : ClusterIP 8080, no nodePort
public WSS                        : Traefik 443
```

PostgreSQL, Redis, Asterisk, Kamailio, Traefik, gateway, observability, and web were not
restarted. PostgreSQL and Redis Pods retained their pre-existing ages and restart counts.

## Verification Performed

```text
apps/api : php artisan test <6 focused files>  → 41 passed (455 assertions)
apps/api : php artisan test                    → 379 passed, 3 skipped (3159 assertions)
apps/api : vendor/bin/pint --test              → passed
repo     : make control-plane-test             → 34 passed (222 assertions) against a real
                                                 PostgreSQL container, including
                                                 RuntimeOperationPostgresReadApiTest
                                                 "runtime operation list and detail reads join
                                                  runtime nodes and reconciliation on postgres
                                                  uuid columns"
apps/web : npm run typecheck / lint            → passed
apps/web : npm run test                        → 7 files, 83 passed
apps/web : npm run build                       → built
repo     : make k8s-config-check               → K1 Kubernetes config check passed
repo     : make security-config-check          → K3 security config check passed
repo     : make runtime-engine-config-check    → runtime engine config check passed
repo     : make repository-hygiene             → passed
repo     : make workflow-check                 → passed
repo     : make secret-scan                    → passed
repo     : git diff --check / --cached --check → clean
security : scripts/security/check-apiserver-policy-drift → passed
```

The three skipped backend tests are environment-gated (they require a PostgreSQL driver); the
PostgreSQL-gated Runtime Operation read test executes and passes under `make control-plane-test`,
which is exactly the regression guard UI-D15 identified as missing.

## Unresolved Proof Gaps

None for this slice.

## Deferred Work

* `utcp-runtime-fence-worker` remains outside the canonical local overlay by design, so its live
  Deployment is not reconciled by `make k8s-apply`. Its broadcaster configuration was corrected by
  applying the committed component manifest. If that component is ever promoted into the overlay,
  the manual step disappears.
* Remaining UI-D surfaces: reconciliation-record and audit read authorities plus their bounded
  read-only operational views.
