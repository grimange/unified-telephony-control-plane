# UI-D22 — Reconciliation Quiescence Live Proof

Verdict: `UI_D_RECONCILIATION_QUIESCENCE_LIVE_PROOF_COMPLETE`

Source commit: `5e31943` — `fix(reconciliation): suppress no-op lifecycle events`
Proof type: controlled live proof (evidence only; no production code changed).
Phase marker: `UTCP_PHASE=T1` (unchanged). UI-D remains **In Progress**.

Related evidence:

* [`docs/evidence/ui/ui-d20-runtime-reconciliation-live-proof.md`](ui-d20-runtime-reconciliation-live-proof.md) — the blocked proof that found the event storm.
* [`docs/evidence/ui/ui-d21-reconciliation-noop-event-suppression.md`](ui-d21-reconciliation-noop-event-suppression.md) — the bounded correction.

The UI-D20 producer defect is closed and live-proven. Reconciliation lifecycle events are now
emitted **only** on a real fingerprint change; claim-only activity and repeated equal results are
silent; the historical backlog drained canonically to zero; and every real transition still
produces exactly one outbox row, one browser notification, and one canonical reread.

---

## Repository Baseline

```text
branch      : main
HEAD        : 5e31943
working tree: clean at start
UTCP_PHASE  : T1
```

Committed behaviour confirmed at `5e31943`:

| Contract | Evidence |
| --- | --- |
| `claimDue()` no longer emits lifecycle events | the `foreach` updates lease fields + `attempt_count` only; no `appendReconciliationEvent` call remains in the claim path |
| `markResult()` compares prior vs resulting fingerprint | builds `$priorFingerprint = transitionFingerprint($row)` and `$nextFingerprint`, appends only `if ($priorFingerprint !== $nextFingerprint)` |
| Unchanged fingerprint → no event | the guarded append is the sole event site in `markResult()` |
| Changed fingerprint → exactly one event | `eventTypeForTransition($prior,$next)` selects one event type per change |
| Comparison inside the locked, fenced transaction | append sits inside the `DB::transaction` after the fenced `lockForUpdate` read and the `lease_token`+`lease_expires_at` guarded `update()` |
| Canonical fingerprint fields | `transitionFingerprint()` = `status, desired_generation, observed_generation, last_operation_id, blocked_reason` |
| Browser channel + envelope unchanged | `git diff d9fe346..5e31943 -- apps/api/app/Events apps/api/routes/channels.php apps/api/app/RuntimeEngine/Outbox` is empty |
| No frontend production change | `git diff d9fe346..5e31943 -- apps/web` is empty |

Reconciler cadence (from `apps/api/config/runtime_engine.php`): `poll_seconds=3`, `batch_size=10`.

---

## API Image Built and Rolled Out

Built from a clean `5e31943` tree with an explicit provenance label; web and gateway not rebuilt.

```text
api registry manifest : sha256:a3c8c6ce387caec7feded5688b5168cb1b5851cb78795094334b8def0a55c76e
                        (re-pushed after k8s-apply's internal build overwrote the label)
org.opencontainers.image.revision = 5e31943   org.opencontainers.image.version = 0.1.0-dev
```

### Deterministic rollout, zero skew

All **12** Deployments whose rendered container image uses the mutable UTCP API image were
enumerated from the live manifests (not guessed):

```text
api, asterisk-ari-events, control-plane-outbox-dispatcher, kamailio-registration-observer,
reverb, scheduler, simulator-event-source, telephony-command-worker,
telephony-event-normalizer, telephony-reconciler, utcp-runtime-fence-worker, worker
```

`utcp-runtime-fence-worker` is `imagePullPolicy: IfNotPresent`, so the cached API image was removed
from all three k3d nodes with `crictl rmi` before the restart. Every one was restarted plus web.

```text
running API pods on sha256:a3c8c6ce…  : 13
running web pod on sha256:5947ec57…   : 1   (unchanged d9fe346 bundle)
```

Web provenance verified before and after: the running web image is
`web@sha256:5947ec576f51e57ec6927c95cd5e7f50da586122810d4bc8c5a2c6d356976adb`, whose local build
label is `revision=d9fe346` — the already-proven Runtime Reconciliation UI. It was not rebuilt or
restarted.

## Preserved Workloads

PostgreSQL, Redis, gateway, Traefik, Asterisk, Kamailio, and observability were not restarted.
PostgreSQL and Redis Pods retained their pre-existing creation timestamps.

## Final Workload Readiness

```text
all 15 Deployments ready
utcp-migrate            : Complete, BROADCAST_CONNECTION=log, no Reverb credentials
scheduler / reconciler / dispatcher : running at normal replicas (1 each) throughout the primary proof
reverb → redis TCP 6379 : open
reverb Service          : ClusterIP, port 8080 only, no nodePort
public WSS              : Traefik 443
Kubernetes API pin      : drift check passed endpoint=172.24.0.5/32:6443 (no repair needed)
canonical apply         : "K1 platform applied" — no rollout failure
no invalid broadcaster configuration; no failed broadcast job
```

---

## Historical Outbox Backlog

The UI-D20 backlog was preserved intact and observed, never manually altered.

```text
baseline (pre-rollout)  : outbox 21677 dispatched + 12240 pending
                          runtime_reconciliation: 27360 total, 12240 pending, 0 terminal_failed
oldest pending age      : ~29.5 minutes
production rate at baseline (old code): ~400 runtime_reconciliation rows / 60 s (~6.7/s)
```

## Backlog Drain Result

After the `5e31943` rollout, with scheduler, reconciler, and dispatcher all at their normal
replica counts, the backlog drained monotonically to zero. `rr_total` froze at 28101 within
seconds of the rollout — i.e. **new production stopped** — while pending fell steadily.

```text
115 samples at 30 s intervals, rr_total constant at 28101 throughout:
  03:11:21  pending=11471
  03:16:23  pending=10451
  …
  04:07:56  pending=11
  04:08:27  pending=0      ← DRAINED
drain duration            : ~57 minutes
drain rate                : ~110 rows / 30 s (~3.4/s)
failed count              : 0 at every sample
```

No row was deleted, updated, or manually marked dispatched; no alternate cleanup command was used;
the dispatcher ran at its declared replica count of 1. `rr_total` staying flat while pending fell
proves the drain was pure dispatch of historical rows with **new no-op production ≈ 0** — the new
production rate is far below the dispatcher drain rate, reversing the UI-D20 divergence.

## Normal Polling Observation

Immediately after rollout, with scheduler and reconciler at normal replicas:

```text
6 samples over ~2.5 minutes, rr_total constant at 28101, new rows in each 30 s window = 0
reconciler log (last 200 results) : 200 × "result":"waiting"  (0 other outcomes)
```

The reconciler is demonstrably working, not idle:

```text
sum(attempt_count) over 20 s : 1245139 → 1245199  (+60 claims)
runtime_reconciliation rows produced in that window : 0
```

## Claim-Only Event Result

Claims occurred continuously through the normally running reconciler (never via a diagnostic
command). Over the 20-second sample above, **+60 claims produced 0 reconciliation outbox rows and
0 browser events**. Under the old code those 60 claims alone would have produced 60
`reconciliation_started` rows.

```text
lease/claim fields change (attempt_count increments) : yes
fingerprint unchanged                                : yes
new reconciliation outbox rows                       : 0
browser events / list rereads / detail rereads       : 0
```

## Repeated-Waiting Event Result

The 200/200 `waiting` reconciler results are repeated equal no-ops (status, desired_generation,
observed_generation, last_operation_id, blocked_reason all unchanged). Across every observation
window they produced:

```text
new retry_scheduled events : 0
other reconciliation events: 0
browser list rereads       : 0
selected detail rereads    : 0
```

Polling intervals were not altered and the worker was not frozen.

## Idle Browser Request Result

Primary quiescence window in a naturally authenticated browser on the mounted route, with a
reconciliation selected, scheduler and reconciler at normal replicas, and no user action:

```text
window                         : 150 s (≈ 50 reconciler poll cycles at poll_seconds=3)
new runtime_reconciliation events : 0
Runtime Reconciliation list rereads : 0
selected-detail rereads        : 0
socket frames                  : pusher:ping / pusher:pong only
badge                          : "Live updates connected" throughout
active query unchanged         : ?per_page=10&target_type=runtime_node
```

Server-side over the same window: `rr_total` constant at 28101, pending 0, `sum(attempt_count)`
climbing (+350 claims). This is the primary quiescence proof — the UI-D20 storm (110 idle rereads
in 30 s) is gone.

---

## Natural Login and Tenant

Real Login page → `admin@utcp.local.test` via a sanctioned bounded break-glass credential →
forced password change through the UI → `Local Tenant` through AppShell. Context verified fresh:

```text
cookies before clear : XSRF-TOKEN, unified-telephony-control-plane-api-session
cookies after clear  : []
storage before route entry : local [] / session []
```

No imported state, preset cookies, injected sessions, database/Redis sessions, or auth bypasses.

## Runtime Reconciliation Route Sanity

```text
initial list requests : 1   (/api/v1/admin/runtime-reconciliations?page=1&per_page=20 → 200 at +72 ms)
initial detail requests: 0
channel auth          : POST /api/broadcasting/auth → 200 at +126 ms
subscription          : 1 — private-tenant.<tenantId>.runtime-reconciliations
sockets created       : 1
reconciliation events during entry : 0
badge                 : "Live updates connected"
```

The UI-D20 entry produced 11 list requests (1 snapshot + 10 storm-driven); here it is **exactly 1**.

## Selected Detail Sanity

```text
select one reconciliation : 1 detail request, 0 list requests
representative filter      : target_type=runtime_node → ?target_type=runtime_node&page=1&per_page=20 (200)
non-default page size      : 10 → ?target_type=runtime_node&page=1&per_page=10 (200), 84 total, page 1 of 9
active query recorded      : ?per_page=10&target_type=runtime_node
```

## Canonical Real Transition Source

Real transitions were generated only through the normal Runtime Nodes Web Admin lifecycle
(`Activate` on disposable `simulator-deterministic` RuntimeNodes), driven from a second tab in the
same natural session and, for the tenant-isolation case, a second independent context. No direct
PostgreSQL/Redis write, Tinker, Artisan management, hidden endpoint, or manual reconciliation
trigger was used. Before each transition pending outbox was 0.

## Transition Fingerprint Evidence

Every emitted event corresponded to a real fingerprint change, verified against the canonical row
and the linked operations:

```text
unrelated node d19c348a (c4-live-proof-drift-1784174711), Activate:
  drift_detected       status converged→(drift), desired_generation bump
  operation_required   last_operation_id → 4b8b6e5f… (new operation)
  converged            status → converged
  final row: status=converged, desired_generation=10, last_operation_id=4b8b6e5f…

selected node 760a6062 (c4-live-proof-drift-1784071066), Activate:
  drift_detected
  operation_required   last_operation_id → 1017edad…
  operation_required   last_operation_id → 5c98afbc…
  operation_required   last_operation_id → 0240d3c7…
  converged
  each operation_required carried a DISTINCT runtime_operation_id, each backed by a real
  runtime.node.apply_configuration row (1017edad/5c98afbc/0240d3c7, all succeeded)
```

## Real-Transition Outbox Result

```text
unrelated transition : outbox runtime_reconciliation rows +3   (exactly the 3 fingerprint changes)
selected transition  : outbox runtime_reconciliation rows +5   (exactly the 5 fingerprint changes)
pending returned to 0 after each; dispatch_status=dispatched for every row
```

Each event's payload was metadata-bounded (`runtime_reconciliation_id` plus `runtime_node_id`, and
`runtime_operation_id` on the operation-linked events).

## Real-Transition Browser Event Result

Unrelated transition (a different reconciliation selected):

```text
browser notifications : 3   (drift_detected, operation_required, converged)
list rereads          : 3   (one per event, all the active canonical query)
selected detail rereads: 0
other detail requests : 0
active page/filters    : unchanged (?per_page=10&target_type=runtime_node)
```

Envelope key union across the transition frames was exactly:

```text
aggregate_id, aggregate_type, event_type, occurred_at, runtime_node_id,
runtime_operation_id, runtime_reconciliation_id, tenant_id
```

with `aggregate_type=runtime_reconciliation`, `aggregate_id === runtime_reconciliation_id`, active
tenant, and no status / generation / drift / desired-state / observed-state / failure / credential
field. `runtime_operation_id` appeared only on the operation-linked events.

## Unrelated-Reconciliation Reread

Covered above: **1 list reread per event, 0 selected-detail rereads, 0 other detail requests**,
pagination and filters preserved, list state from the canonical HTTP response.

## Selected-Reconciliation Reread

The quiescent producer made this measurement isolatable for the first time (UI-D20 could not do
it). With `760a6062` selected and its own node activated:

```text
events for the selected reconciliation : 5
list rereads                           : 5
selected-detail rereads                : 5
unrelated detail requests              : 0
events for other reconciliations       : 0
final detail (from the HTTP response, not the envelope):
  status=converged, desired generation=12, observed generation=Unknown,
  last operation=runtime.node.apply_configuration 0240d3c7 · succeeded, failure=None
```

Status, generations, linked operation, failure state, and timestamps all came from the detail HTTP
response — the envelope carries none of them.

## Post-Transition Quiescence

After `760a6062` reached its stable converged state, with it still selected and the reconciler
running:

```text
window       : 110 s (≈ 36 reconciler poll cycles)
new events   : 0
list rereads : 0
detail rereads : 0
frames       : pusher:ping / pusher:pong only
```

The transition emitted once, not once per subsequent poll.

## Reconnect Regression

```text
outage (Reverb 1→0):
  badge "Live updates disconnected — displayed data may be stale"
  list/detail rereads 0, rows and detail still visible, query unchanged
recovery (Reverb 0→1):
  channel reauthorized (broadcasting/auth 200), subscription re-established
  list reread 1, selected detail reread 1, unrelated details 0
  page and filters preserved, stale cleared after the HTTP rereads
  sockets this generation: 2 created / 1 closed; no duplicate resync
```

## Route-Leave Regression

```text
leave → /operations/runtime-operations:
  pusher:unsubscribe sent (1), shared socket retained (0 new / 0 closed)
  reconciliation requests after leave: 0, still 0 after a further 14 s; 0 reconciliation frames
return → /operations/runtime-reconciliations:
  1 fresh list request, 0 detail requests, 1 fresh subscription, 0 new sockets
```

## Tenant-Switch Isolation

```text
switch Local Tenant → Proof Tenant 1784195144:
  pusher:unsubscribe sent, selection cleared
  new-tenant list requests 1, new-tenant detail requests 0
  sockets created 0 / closed 0 (one shared socket retained)
  new subscription private-tenant.28678536-….runtime-reconciliations
  leaked old-tenant rows 0, empty state shown, badge connected
```

## Previous-Tenant Event Rejection

A second independent context caused a real Local Tenant transition (`c4-live-proof-drift-1784068139`)
while the primary stayed on Proof Tenant. The transition is server-confirmed:

```text
outbox rows for reconciliation 16b107c2 (tenant local / 7be59d2a):
  04:25:01 drift_detected, 04:25:03 operation_required, 04:25:06 converged  (all dispatched)
node c4-live-proof-drift-1784068139 → desired_state active, converged, new last_operation_id

primary context on Proof Tenant, ~92 s observation:
  accepted Local Tenant events : 0
  list rereads                 : 0
  detail rereads               : 0
  leaked rows                  : 0
  frames                       : pusher:ping / pusher:pong only
```

The witness is the canonical outbox (3 real Local Tenant events on the `local` channel), so the
result is non-vacuous even though the second context had navigated to the Runtime Nodes route.

## Logout Teardown

```text
channel active before logout : private-tenant.<tenantId>.runtime-reconciliations
POST /api/v1/auth/logout     → 200
sockets closed 1, sockets created after logout 0 (no reconnect)
post-logout broadcasting-auth 0, post-logout reconciliation requests 0
real Login page visible
```

## Storage Boundary

Sampled with the list loaded, with a detail selected, after transitions, after reconnect, after
the tenant switch, and after logout:

```text
localStorage   : pusherTransportTLS (+ utcp.appearance once theme was touched)
sessionStorage : (empty)
forbidden-substring scan (reconciliation, tenant_id, runtime_node, runtime_operation, channel,
  capability, failure, generation, credential, secret, desired_state, observed_state) : 0 hits
```

## Responsive Result

At `375px`, Light and Dark:

```text
Light : scrollWidth 375, innerWidth 375, overflow 0
Dark  : scrollWidth 375, innerWidth 375, overflow 0
selected detail : present and fully within the viewport
```

Appearance reset to System.

---

## Console, Queue, Reconciliation, Dispatcher, Reverb, Gateway, API, and Network Findings

```text
page errors                     : 0
unhandled rejections            : 0
new no-op reconciliation events : 0
idle browser rereads            : 0
real transition events          : exactly 1 outbox row + 1 notification + 1 list reread per fingerprint change
duplicate rereads / resync      : none
failed broadcast jobs           : 0
stuck proof-generated outbox rows : 0 (pending returned to 0 after every transition)
reconciler errors               : none (200/200 results are clean "waiting" no-ops)
dispatcher errors               : none
Reverb / gateway / API errors   : none
```

Console errors, all explained: an intentional-outage WebSocket handshake failure during the Reverb
scale-to-zero, a benign "WebSocket already CLOSING/CLOSED" teardown race at logout, and the
expected pre-login `/auth/session` 401.

## Cleanup

```text
RuntimeNode desired states : all three transitioned disposable nodes restored to disabled;
                             83/83 simulator nodes disabled at end
scaled workers             : none left scaled — reconciler and scheduler ran at normal replicas
                             throughout; only Reverb was cycled (1→0→1) for the reconnect regression
generated operations       : all runtime.node.apply_configuration ops succeeded (terminal)
reconciliation/operation/outbox history : preserved, nothing deleted
reverb replicas            : restored to 1 (ready), Redis 6379 open
pending outbox             : 0, confirmed stable across a final ~2-minute idle window (rr_total 28112)
filters cleared / detail closed / appearance reset to System : yes
browser contexts           : both logged out and closed
.playwright-mcp/           : removed
screenshots / scratch / credential files : none retained
temporary port-forwards    : none created
all 15 Deployments healthy; Reverb ClusterIP 8080; public WSS Traefik 443
```

PostgreSQL, Redis, Asterisk, Kamailio, Traefik, gateway, observability, and web were not restarted.

## Verification Performed

```text
apps/api : php artisan test <3 focused files>  → 38 passed (468 assertions)
apps/api : php artisan test                    → 396 passed, 5 skipped (3498 assertions)
apps/api : vendor/bin/pint --test              → passed
repo     : make control-plane-test             → 49 passed (401 assertions) against a real
                                                 PostgreSQL container, including
                                                 "runtime reconciliation noop event suppression runs on postgres"
apps/web : npm run typecheck / lint            → passed
apps/web : npm run test                        → 7 files, 91 passed
apps/web : npm run build                       → built
repo     : make runtime-engine-config-check    → passed
repo     : make repository-hygiene             → passed
repo     : make workflow-check                 → passed
repo     : make secret-scan                    → passed
repo     : git diff --check / --cached --check → clean
```

## Divergences

1. **Two brief re-logins.** After the logout-teardown proof the browser was logged out, so a fresh
   natural login was performed to capture the storage-with-detail and 375px responsive samples, and
   again for the final UI cleanup/logout. Each used the normal Login flow with a sanctioned
   break-glass credential; no injected state.
2. **Rollout-overlap tail.** A tail of `reconciliation_started`/`retry_scheduled` rows landed in the
   ~27 s window while old-image pods finished their loops during the rollout; the last row of any
   kind was at `03:06:04`. Every row after the new reconciler pod started (`03:05:37`) that was not
   a genuine transition ceased, and `rr_total` has been frozen since. Classified as expected rollout
   behaviour, not a defect.
3. **Previous-tenant witness.** The second context had navigated to the Runtime Nodes route, so its
   in-page frame counter read 0; the canonical outbox rows (3 real Local Tenant events) are the
   authoritative witness that the transition occurred, keeping the rejection result non-vacuous.

## Unresolved Proof Gaps

None for this slice.

## Deferred Work

* Remaining UI-D surface: the canonical Audit read authority and its bounded read-only view.
