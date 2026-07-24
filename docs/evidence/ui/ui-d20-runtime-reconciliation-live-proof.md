# UI-D20 — Runtime Reconciliation Live Proof (Blocked)

Verdict: `UI_D_RUNTIME_RECONCILIATION_LIVE_PROOF_INCOMPLETE`

Source commit: `d9fe346` — `feat(ui): add live runtime reconciliations`
Proof type: controlled live proof (evidence only; no production code changed).
Phase marker: `UTCP_PHASE=T1` (unchanged). UI-D remains **In Progress**.

Related evidence:

* [`docs/evidence/ui/ui-d18-runtime-reconciliation-read-api.md`](ui-d18-runtime-reconciliation-read-api.md)
* [`docs/evidence/ui/ui-d19-runtime-reconciliation-live-implementation.md`](ui-d19-runtime-reconciliation-live-implementation.md)

The read APIs, the route, and every request-discipline contract of the Runtime Reconciliation
surface are correct and live-proven. The canonical notification corridor also works end to end.

**One blocking defect prevents completion:** `ReconciliationRepository` emits outbox events for
reconciliation *non-transitions*. The reconciler's steady-state polling loop therefore produces a
permanent ~7 event/second broadcast storm, which (a) makes the required initial request budget of
one list request unachievable during normal operation, and (b) grows the canonical outbox
dispatch backlog without bound. Both are measured below. No production code was altered.

---

## Repository Baseline

```text
branch      : main
HEAD        : d9fe346
working tree: clean at start
UTCP_PHASE  : T1
```

Committed contracts confirmed at `d9fe346`:

| Contract | Evidence |
| --- | --- |
| Canonical list/detail APIs | `routes/web.php:57-58` → `AdminRuntimeReconciliationController` |
| Backend ordering | `RuntimeReconciliationQuery:52-53` → `updated_at desc, id desc` |
| `ReconciliationRepository` aggregate events | 7 event constants, `AGGREGATE_TYPE='runtime_reconciliation'` |
| `RuntimeReconciliationOperationalStateChanged` | metadata-only `broadcastWith()`, node/operation IDs only when present |
| Strict bridge branch | requires `aggregate_type='runtime_reconciliation'`, `runtime_reconciliation.` prefix, **and** `payload.runtime_reconciliation_id === aggregate_id` |
| `tenant.{tenantId}.runtime-reconciliations` | `routes/channels.php`, session active tenant + `runtime.nodes.view` |
| `/operations/runtime-reconciliations` | `router/index.ts:51`, `navigation.ts:28`, capability `runtime.nodes.view` |
| Shared Echo/Pusher connection | single client in `realtime/runtimeNodeRealtime.ts` |
| No write or manual-trigger controls | view exposes only Refresh / Apply / Clear / Details / Previous / Next |
| Safe detail contract | `RuntimeReconciliationDetailResource` returns exactly `parent::toArray()` — no extra raw fields |

---

## Images Built and Rolled Out

Built from a clean `d9fe346` tree with explicit provenance labels and the canonical web
coordinates (`app.utcp.local.test` / `443` / `wss` / `/app`, `/api/broadcasting/auth`). Only the
public Reverb application key was passed as a build argument; the Reverb secret was never exposed.

```text
api registry manifest : sha256:5ff2e330a559fc37e7bbe272dac4cd03962f4efad36c65b2e76a1a6fd673aa2e
web registry manifest : sha256:5947ec576f51e57ec6927c95cd5e7f50da586122810d4bc8c5a2c6d356976adb
org.opencontainers.image.revision = d9fe346 (both images)
```

Gateway was not rebuilt (its production configuration did not change).

### Deterministic rollout

Every Deployment whose rendered container image uses the mutable UTCP API image was enumerated
from the live manifests and restarted — no guessing about which workload executes reconciliation
logic:

```text
api, asterisk-ari-events, control-plane-outbox-dispatcher, kamailio-registration-observer,
reverb, scheduler, simulator-event-source, telephony-command-worker,
telephony-event-normalizer, telephony-reconciler, utcp-runtime-fence-worker, worker   (12)
plus deployment/web                                                                    (1)
```

`utcp-runtime-fence-worker` uses `imagePullPolicy: IfNotPresent`, so the cached API image was
removed from all three k3d nodes with `crictl rmi` before the restart to force a genuine pull.

Zero version skew after rollout:

```text
api@sha256:5ff2e330…  13 running pods
web@sha256:5947ec57…   1 running pod
```

The only other API-image pods were one terminating `kamailio-registration-observer` replica and
the already-`Succeeded` `utcp-migrate` Job pod.

## Preserved Workloads

PostgreSQL, Redis, gateway, Traefik, Asterisk, Kamailio, and observability were not restarted.
PostgreSQL and Redis Pods retained their pre-existing creation timestamps.

## Final Workload Readiness

```text
all 15 Deployments ready (api, asterisk-ari-events, control-plane-outbox-dispatcher, gateway,
kamailio, kamailio-registration-observer 2/2, reverb, scheduler, simulator-event-source,
telephony-command-worker, telephony-event-normalizer, telephony-reconciler,
utcp-runtime-fence-worker, web, worker)

utcp-migrate            : Complete 1/1, BROADCAST_CONNECTION=log, no Reverb credentials
reverb → redis TCP 6379 : open
reverb Service          : ClusterIP, port 8080 only, no nodePort
NodePort/LoadBalancer   : traefik only (the canonical public edge)
public WSS              : Traefik 443, wss://app.utcp.local.test/app/<public-key>
Kubernetes API pin      : drift check passed endpoint=172.24.0.5/32:6443 (no repair needed)
canonical apply         : "K1 platform applied" — no rollout failure
```

Baseline before rollout: 6557 outbox rows, **0 pending**, 0 `runtime_reconciliation` rows,
460 reconciliation states (342 converged / 116 waiting / 2 blocked).

---

## BLOCKER — Reconciliation non-transitions are broadcast, producing an unbounded event storm

### What was measured

Immediately after the route was opened, with **no user action at all**:

```text
30-second idle observation window
  runtime_reconciliation events received : 110
    runtime_reconciliation.reconciliation_started : 57
    runtime_reconciliation.retry_scheduled        : 53
  canonical list rereads                 : 110  (3.67 per second)
  detail requests                        : 0
  distinct reconciliation aggregates     : 59
  every reread query                     : /api/v1/admin/runtime-reconciliations?page=1&per_page=20
```

The per-event contract is correct — exactly **one** list reread per event, never a detail
fan-out. The defect is the event *production*, not the client's handling of it.

Route entry itself showed 11 list requests: one canonical snapshot at +73 ms followed by ten
notification-driven rereads between +1050 ms and +1430 ms.

### Canonical outbox growth and dispatcher backlog

```text
time      runtime_reconciliation rows   pending outbox rows   rows produced /30 s
01:47:28            1440                       650                   220
01:47:53            1600                       720                   200
01:48:18            1780                       810                   200
01:48:43            1960                       910                   200
01:49:08            2140                      1000                   220
```

Production ≈ **7 rows/second**. With the reconciler paused the dispatcher drained the same table
at ≈ **3.3 rows/second** (1380 → 0 over ~7 minutes). The dispatcher is therefore outpaced roughly
two-to-one and the pending backlog grows monotonically and without bound for as long as the
reconciler runs. Baseline pending was 0; final pending was 700 and still climbing.

There were **no** dispatcher errors, worker errors, or Reverb errors — the components are healthy
and simply saturated.

### Root cause

`ReconciliationRepository` appends an outbox event on every claim and every result, without
comparing prior and resulting state:

* `claimDue()` (`ReconciliationRepository.php:159-177`) appends
  `runtime_reconciliation.reconciliation_started` for **every claimed row on every poll**.
* `markResult()` (`ReconciliationRepository.php:218-225`) appends an event for **every result**,
  and `eventTypeForResult()` maps the steady-state `waiting` status to
  `runtime_reconciliation.retry_scheduled`.

So a fully converged, drift-free target still emits two outbox rows per poll cycle, forever.
`next_check_at` bounds the per-row frequency, but with 460 reconciliation states the aggregate is
a permanent storm.

The reconciler log confirms every event represents a non-transition:

```text
telephony-reconciler last 200 results:  200 × "result":"waiting"   (0 other outcomes)
```

Event-type totals over the proof window are consistent — 2280 `reconciliation_started` and 2206
`retry_scheduled` against only 72 `converged` and 2 `failed`: **98.4 % of all emitted events carry
no state change.**

### Contrast with the proven Runtime Operations corridor

`RuntimeOperationRepository` (live-proven in UI-D17) emits only on genuine persisted lifecycle
transitions of a finite operation, so its event volume is naturally bounded — four events per
operation, total. The reconciliation aggregate is a *long-lived, continuously re-polled* record,
so the same "emit on every repository write" pattern does not transfer to it.

### Bounded implementation seam

`apps/api/app/RuntimeEngine/Reconciliation/ReconciliationRepository.php` — emit only on an actual
state change. Concretely: suppress `reconciliation_started` when the lease is a routine re-poll of
an unchanged target, and suppress the result event when `status`, `desired_generation`,
`observed_generation`, `last_operation_id`, and `blocked_reason` are all unchanged from the row
that was read. Regression coverage should assert that a no-op reconcile of a converged target
appends **zero** outbox rows.

### Claims this blocks

```text
initial request budget = 1 list request : FAILS under normal operation (11 on entry, then 3.67/s)
no stuck / bounded outbox               : FAILS (pending backlog grows without bound)
```

---

## Independently Proven Results

Because the storm makes per-action attribution impossible, the request-discipline claims below
were measured in a **quiescent window** with `telephony-reconciler` and `scheduler` scaled to zero
and the outbox backlog fully drained to 0. This isolation is stated explicitly: it demonstrates
that the *route's own* request discipline is correct, and it does not represent normal operation.

### Natural login and tenant

Real Login page → `admin@utcp.local.test` via a sanctioned bounded break-glass credential →
forced password change through the UI → `Local Tenant` through AppShell. The context was verified
fresh first:

```text
cookies before clear : XSRF-TOKEN, unified-telephony-control-plane-api-session
cookies after clear  : []
storage after clear  : local [] / session []
```

No imported storage state, preset cookies, injected sessions, database- or Redis-created sessions,
or authentication bypasses.

### Runtime Reconciliation route

```text
no tenant selected  : /dashboard  /admin/tenants  /admin/users
Local Tenant active : + /admin/memberships  /admin/runtime-nodes  /operations/runtime-operations
                      + /operations/runtime-reconciliations  /operations/conferences
```

Entered by clicking the visible AppShell link. Controls are **Refresh, Apply, Clear, Details,
Previous, Next** only — no reconcile, retry, replay, repair, clear-drift, delete, approve, or
manual-sync control exists.

### Initial request budget (quiescent)

```text
Runtime Reconciliation list requests : 1   (/api/v1/admin/runtime-reconciliations?page=1&per_page=20 → 200 at +66 ms)
Runtime Reconciliation detail requests: 0
total reconciliations                : 460     page 1 of 23, page size 20
channel authorization                : POST /api/broadcasting/auth → 200 at +138 ms
channel subscription                 : subscription_succeeded at +141 ms on
                                       private-tenant.<tenantId>.runtime-reconciliations
websockets created                   : 1
reconciliation events in window      : 0
```

### Pagination (quiescent)

Server-backed and bounded — one list request per page change, zero detail requests, no
full-dataset download.

```text
Next   → ?page=2&per_page=20   200   Page 2 of 23    1 request, 0 detail
Next   → ?page=3&per_page=20   200   Page 3 of 23    1 request, 0 detail
Prev   → ?page=2&per_page=20   200   Page 2 of 23    rows identical to the first visit
10/pg  → ?page=1&per_page=10   200   Page 1 of 46    10 rows
```

Ordering is deterministic (`updated_at desc, id desc`): the 10-row page is the exact prefix of the
20-row page, and returning to page 2 restored the identical 20 IDs.

### Filters (quiescent)

Every exposed filter was exercised from page 2 to prove the page reset. Each produced exactly one
list request, zero detail requests, the exact canonical query parameter, and a correct `Clear`
back to 460.

```text
filter                request                                                     result
runtime_node_id       ?runtime_node_id=d227ab0e-…&page=1&per_page=20        200   1 row
status                ?status=converged&page=1&per_page=20                  200   343 rows
target_type           ?target_type=runtime_node&page=1&per_page=20          200   84 rows
runtime_operation_id  ?runtime_operation_id=5ef21997…&page=1&per_page=20    200   1 row
updated_from/to       ?updated_from=2026-07-24T00%3A18%3A30Z
                      &updated_to=2026-07-24T00%3A19%3A30Z&page=1&per_page=20 200 4 rows
```

Returned rows satisfy the filters: `status=converged` returned exactly the 343 the API reports,
`target_type=runtime_node` exactly 84, and the `runtime_operation_id` filter isolated the single
reconciliation whose `last_operation_id` is that operation. All values came from the safe list and
detail APIs.

Backend validation through the visible UI:

```text
runtime_operation_id = "not-a-valid-operation-id"  →  422
presented: "Runtime Reconciliations unavailable — The runtime operation id field format is invalid."
previously loaded rows retained (20); Clear restored 460
```

### Selected detail budget (quiescent)

```text
select one reconciliation : 1 detail request, 0 list requests
select a different one    : 1 detail request (new ID only), 0 list requests, 1 open panel
```

Canonical detail contract — exactly 15 keys:

```text
attempt_count, created_at, desired_generation, failure, has_drift, id, last_checked_at,
last_operation_id, next_check_at, observed_generation, runtime_node, runtime_operation,
status, target, updated_at
```

Forbidden-key scan and forbidden-substring scan both returned **zero** hits for: raw desired
state, raw observed state, payload, credentials, adapter configuration, endpoint secrets, provider
response, raw command, stack trace, audit payload, outbox payload. Generations are exposed only as
integers (`desired_generation: 1`, `observed_generation: null`).

### Canonical reconciliation source

The automatic lifecycle that writes through `ReconciliationRepository` is the dedicated
`telephony-reconciler` Deployment (`runtime-engine:reconciler`, continuous loop), with the
`scheduler` also invoking `runtime-engine:reconciler --once` every minute. Its transitions are the
canonical production source of `runtime_reconciliation.*` outbox rows.

No RuntimeNode desired state was modified during this proof, and no Web Admin mutation was needed:
the automatic reconciliation lifecycle already produces canonical transitions continuously — far
more than the single reversible change the plan anticipated. No direct database or Redis write,
Tinker, manual outbox insertion, hidden endpoint, Artisan reconciliation authority, or manual
trigger was used.

### Production reconciliation outbox rows

```text
aggregate_type='runtime_reconciliation' before : 0
aggregate_type='runtime_reconciliation' after  : 4560

runtime_reconciliation.reconciliation_started : 2280
runtime_reconciliation.retry_scheduled        : 2206
runtime_reconciliation.converged              :   72
runtime_reconciliation.failed                 :    2
```

Every row was written by real `ReconciliationRepository` transitions; none was synthesized.

### Broadcast envelope

Captured over 60 consecutive production frames. The union of every payload key across all frames:

```text
aggregate_id, aggregate_type, event_type, occurred_at, runtime_reconciliation_id, tenant_id
```

```text
event name        : runtime-reconciliation.operational-state.changed
channel           : private-tenant.<tenantId>.runtime-reconciliations
aggregate_type    : runtime_reconciliation           (100 % of frames)
aggregate_id      : === runtime_reconciliation_id    (100 % of frames)
tenant_id         : active tenant                    (100 % of frames)
event types seen  : runtime_reconciliation.retry_scheduled, runtime_reconciliation.reconciliation_started
```

`runtime_node_id` and `runtime_operation_id` were absent because these reconciliation targets have
no linked node or operation — matching the "when available" contract.

Forbidden-key scan across all frames returned **zero** hits for status, desired generation,
observed generation, drift, raw desired state, raw observed state, failure body, attempt count,
credentials, endpoints, commands, provider responses, audit body, and full outbox body. The
browser therefore cannot treat the stream as authority for any of them.

### Unrelated-reconciliation reread

With 60 unrelated reconciliation events in a 20-second window:

```text
canonical list rereads     : 60   (exactly 1 per event)
detail requests            : 0
unrelated detail requests  : 0
distinct list queries      : 1 — the active canonical query, unchanged
```

List state came only from the canonical HTTP response.

### Reconnect canonical resynchronization (quiescent)

Reverb scaled 1 → 0 with the route mounted, a reconciliation selected, and
`?target_type=runtime_node&per_page=10` active.

```text
outage:
  badge               : "Live updates disconnected — displayed data may be stale"
  list/detail rereads : 0     (no immediate canonical reread)
  rows visible        : 10    detail panel still visible
  Refresh / Apply     : enabled and usable
  query               : unchanged

recovery (Reverb restored to 1):
  channel reauthorized      : POST /api/broadcasting/auth → 200
  subscription              : subscription_succeeded on private-tenant.<tenantId>.runtime-reconciliations
  list rereads              : 1
  selected detail rereads   : 1
  unrelated detail requests : 0
  query preserved           : ?target_type=runtime_node&page=1&per_page=10
  badge                     : connected — stale cleared after the HTTP rereads
  sockets this generation   : 2 created / 1 closed (original + one reconnect)
```

Exactly one bounded resync per generation; no duplicate.

### Route-leave lifecycle

```text
leave → /operations/runtime-operations:
  frames                        : pusher:unsubscribe → pusher:subscribe
                                  → subscription_succeeded on private-tenant.<t>.runtime-operations
  new sockets / closed sockets  : 0 / 0   (shared socket retained)
  reconciliation requests       : 0, still 0 after a further 12 s
  reconciliation frames         : 0       (callbacks removed)

return → /operations/runtime-reconciliations:
  list requests                 : 1 fresh
  detail requests               : 0
  subscription                  : 1 fresh subscription_succeeded on the reconciliation channel
  new sockets                   : 0
```

### Tenant-switch isolation

```text
switch Local Tenant → Proof Tenant 1784195144:
  old channel left          : pusher:unsubscribe sent
  new channel subscribed    : private-tenant.28678536-….runtime-reconciliations
  selected reconciliation   : cleared
  new tenant list requests  : 1
  initial detail requests   : 0
  new sockets / closed      : 0 / 0   (one shared socket retained)
  old tenant rows           : gone (0 rows, empty state)
  leaked Local Tenant rows  : 0
  badge                     : connected after snapshot + subscription
```

### Previous-tenant event rejection

A second fully independent Playwright context authenticated naturally, selected Local Tenant, and
opened the reconciliation route while the primary stayed on Proof Tenant.

```text
over a 60-second observation:
  second context (Local Tenant) : 210 runtime_reconciliation events received
  primary  (Proof Tenant)       :   0 accepted events
                                    0 list rereads
                                    0 detail rereads
                                    0 leaked rows
                                    frames seen: pusher:ping / pusher:pong only
```

Decisively non-vacuous: 210 real Local Tenant events were flowing while the primary accepted none.

### Logout teardown

```text
channel active before logout        : private-tenant.<tenantId>.runtime-reconciliations
POST /api/v1/auth/logout            → 200
sockets closed                      : 1
sockets created after logout        : 0   (no reconnect)
post-logout broadcasting-auth       : 0
post-logout reconciliation requests : 0
real Login page                     : yes
```

Cookies were not cleared manually.

### Storage boundary

Sampled before route entry, with the list loaded, with a detail selected, after notifications,
after reconnect, after the tenant switch, and after logout:

```text
localStorage   : pusherTransportTLS, utcp.appearance
sessionStorage : (empty)

utcp.appearance    = "system"
pusherTransportTLS = {"timestamp":…,"transport":"ws","latency":…,"cacheSkipCount":0}
```

No reconciliation rows, reconciliation IDs, selected detail, tenant ID, RuntimeNode ID, Runtime
Operation ID, channel name, capability data, failure summary, generation state, event payload,
credential or secret was persisted. The bounded vendor cache was left in place.

### Responsive and accessible behaviour

At `375px`, Light and Dark:

```text
                        Light    Dark
scrollWidth             375      375
window.innerWidth       375      375
overflow                0        0
root overflow-x         visible  visible   (no clipping mask hiding a defect)
heading + badge wrap    yes      yes
filters stack           yes      yes
pagination reachable    yes      yes
row within viewport     yes      yes
drift + status as text  yes      yes       ("waiting" / "converged" / "Drift unknown")
generations visible     yes      yes       ("Desired N")
long identifier wrap    overflow-wrap: anywhere
selected detail         present, fully within viewport, page overflow 0
```

Theme changes issued zero reconciliation management requests and created zero socket events.
Appearance was reset to System. Focus was confirmed to land on the control
(`document.activeElement === button`); the computed `:focus-visible` ring is not readable under
headless synthetic focus, which is the established UI-B measurement limitation, not a finding
about this route.

---

## Console, Queue, Reconciliation, Reverb, Gateway, API, and Network Findings

```text
page errors                     : 0
unhandled rejections            : 0
duplicate rereads per event     : none (exactly 1 list reread per event)
duplicate resync per generation : none (1 list + 1 detail)
unexpected detail fan-out       : none (0 unrelated detail requests in every scenario)
failed broadcast jobs           : none
reconciliation-worker errors    : none
Runtime Operation errors        : none — 0 non-terminal operations remain
outbox-dispatch errors          : none
Reverb errors                   : none
gateway errors                  : none
API errors                      : none (no SQL or serialization error; joins succeed)
pending outbox rows             : 700 and growing — see BLOCKER
```

Console errors, all explained:

```text
1 × 422  — the intentional invalid runtime_operation_id validation test
n × "WebSocket handshake / connection failed" — during the intentional Reverb scale-to-zero
1 × "WebSocket is already in CLOSING or CLOSED state" — benign teardown race at logout
```

## Divergences

1. **Request-discipline measurements were taken in an isolated quiescent window.** The initial
   budget, pagination, filters, selected-detail budget, reconnect, and route-leave results above
   were measured with `telephony-reconciler` and `scheduler` at zero replicas and the outbox
   drained. This is stated wherever it applies. Under normal operation the initial budget claim
   fails, which is the blocker.
2. **No Web Admin desired-state change was made.** The plan anticipated one reversible RuntimeNode
   change as the reconciliation trigger. It was unnecessary and would have added nothing: the
   automatic reconciliation lifecycle already produced 4560 canonical outbox rows during the
   proof. Consequently no RuntimeNode desired state needed restoring.
3. **The selected-reconciliation reread was not isolated.** Under the storm a selection is
   repeatedly invalidated by list re-renders (one attempted selection did not persist), and with
   the reconciler stopped no reconciliation transitions occur at all — so there is no window in
   which exactly one selected-reconciliation transition can be observed. This exact canonical
   timing limitation is preserved here rather than fabricated. The equivalent guarantee is
   independently evidenced by the reconnect resync (1 list + 1 selected detail + 0 unrelated) and
   by the 60-event unrelated-reread measurement (60 list rereads, 0 detail requests).

## Cleanup

```text
RuntimeNode desired states      : none modified, none to restore
telephony-reconciler replicas   : restored to 1 (ready)
scheduler replicas              : restored to 1 (ready)
reverb replicas                 : restored to 1 (ready), Redis 6379 open
reconciliation + operation history : preserved, nothing deleted
non-terminal Runtime Operations : 0
filters cleared through the UI  : yes
pagination                      : returned to page 1 / per_page 20
selected detail                 : closed
appearance                      : reset to System
browser contexts                : both logged out and closed
observers / interceptions       : removed with the contexts
.playwright-mcp/                : removed
screenshots / scratch / credential files : none retained
temporary port-forwards         : none created
all 15 Deployments              : healthy
Reverb Service                  : ClusterIP 8080
public WSS                      : Traefik 443
```

PostgreSQL, Redis, Asterisk, Kamailio, Traefik, gateway, and observability were not restarted.

## Verification Performed

```text
apps/api : php artisan test <6 focused files>   → 31 passed, 1 skipped (282 assertions)
apps/api : php artisan test                     → 392 passed, 4 skipped (3291 assertions)
apps/api : vendor/bin/pint --test               → passed
repo     : make control-plane-test              → 48 passed (379 assertions) against a real
                                                  PostgreSQL container, including
                                                  RuntimeReconciliationPostgresReadApiTest
apps/web : npm run typecheck / lint             → passed
apps/web : npm run test                         → 7 files, 91 passed
apps/web : npm run build                        → built
repo     : make repository-hygiene              → passed
repo     : make workflow-check                  → passed
repo     : make secret-scan                     → passed
repo     : git diff --check / --cached --check  → clean
```

Every test passes. That is part of the finding: the suite asserts that a reconciliation transition
*produces* an event, but nothing asserts that a **non-transition produces none**, so the storm is
invisible to repository coverage — the same class of gap UI-D15 found for the SQLite driver.

## Unresolved Proof Gaps

1. `ReconciliationRepository` must stop emitting outbox events for non-transitions before the
   initial request budget and bounded-outbox contracts can hold under normal operation.
2. Regression coverage must assert that a no-op reconcile of a converged target appends zero
   outbox rows.
3. The selected-reconciliation reread needs a canonical lifecycle in which a single selectable
   reconciliation transitions in isolation; it could not be constructed at this commit.

## Deferred Work

* Remaining UI-D surface: the canonical Audit read authority and its bounded read-only view.
