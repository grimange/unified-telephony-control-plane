# RT-1A — RuntimeNode Realtime Notification Natural Browser Live Proof

## Verdict

    RT_1A_RUNTIME_NODE_REALTIME_NATURAL_BROWSER_LIVE_PROOF_PASSED

Both decisive properties are proven live against canonical `utcp-local`:

    canonical RuntimeNode mutation → transactional outbox → automatic dispatcher
      → Reverb → authenticated tenant-scoped notification
      → canonical API refetch → visible UI update

    Reverb disconnect/reconnect → canonical API resynchronization,
      recovering a notification the browser never received

## Method

Evidence-only. No application source modified. Deployment and the Reverb
interruption were performed through canonical repository/Kubernetes lifecycle
targets only. Natural Playwright login from the real login page; no preset
storage, injected cookie, copied session, database/Redis session, or
authentication bypass. No secret, token, or Kubernetes Secret value appears
below; the Pusher auth strings observed on the wire are deliberately not
reproduced.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing working tree including the RT-1A packet
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Canonical environment

    API:        utcp/api:0.1.0-k1-dev @sha256:e26d6dc9…
    WEB:        utcp/web:0.1.0-k1-dev @sha256:bbce9fdb…
    REVERB:     deployment/reverb on the api image @sha256:e26d6dc9…,
                Service ClusterIP :8080, edge via HTTPRoute app-https
    WORKER:     @sha256:e26d6dc9…       SCHEDULER: @sha256:e26d6dc9…
    DISPATCHER: control-plane-outbox-dispatcher @sha256:e26d6dc9…
    DEPLOYMENT FRESH: yes

The deployed web bundle predated the RT-1A packet
(`runtimeNodeRealtime.ts` mtime 2026-08-15 11:14 local vs web image build
05:28), so the tree was redeployed through `make k8s-image-build` →
`k8s-image-push` → `k8s-apply` → rollout restart of every `utcp-platform`
Deployment. Freshness was then confirmed by content:

* deployed web bundle contains `runtime-node.operational-state.changed` and
  `runtime-nodes`
* deployed API contains `app/Events/RuntimeNodeOperationalStateChanged.php` and
  the `tenant.{tenantId}.runtime-nodes` channel in `routes/channels.php`
* `BROADCAST_CONNECTION=reverb` on both the API and the outbox dispatcher

### Reverb runtime health

    REVERB POD:             reverb-7f86b86dc7-59bhn, 1/1 Running
    SERVICE:                reverb ClusterIP :8080
    PUBLIC EVENTS ENDPOINT: wss://app.utcp.local.test:443/app/<app-key>
                            (through the existing app-https route)
    READY:                  yes — "Starting server on 0.0.0.0:8080"
    CONNECTED CLIENTS:      observed indirectly through successful
                            `pusher:connection_established` and
                            `pusher_internal:subscription_succeeded` frames

No replacement Reverb process was started.

### Dispatcher runtime health

    OUTBOX WORKER:    control-plane-outbox-dispatcher, 1/1 Running
    SCHEDULER:        scheduler, 1/1 Running
    READY:            yes — actively logging
                      `{"message":"outbox message dispatched", …, "attempt":1}`
    QUEUE:            canonical outbox → broadcast path (`BROADCAST_CONNECTION=reverb`)
    PENDING BASELINE: dispatcher already draining unrelated
                      `runtime_reconciliation.converged` traffic at proof start

No manual flush, queue invocation, or manual broadcast was used at any point.

## Natural admin login

    USER:         admin@utcp.local.test
    TENANT:       Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    CAPABILITIES: runtime.nodes.view and runtime.nodes.manage present
                  (plus the full administrator set)

## RuntimeNode fixture

    RUNTIME NODE:      1e1075fd-1412-4a2e-8a65-6ddfc92918d5
                       "RT1A Realtime Proof 20260815"
                       slug rt1a-realtime-proof-20260815
    TYPE:              external, simulator / simulator-deterministic
    INITIAL LIFECYCLE: draft ("Creating")
    INITIAL OBSERVED:  unobserved
    WHY SAFE:          A simulator runtime is a pure control-plane object with no
                       Kubernetes workload, no SIP, and no media, so no user call
                       can be affected. It was created through the normal Admin UI
                       "Register an existing runtime" flow. The two existing
                       active nodes were rejected as fixtures: `d4539d79-…` is the
                       V0C6 conference-bound node and `c7e6f4ba-…` is the retained
                       RNP6 managed fixture whose Admin UI intentionally exposes no
                       edit controls (RNP-U2).

No RuntimeNode row, endpoint, Kubernetes label, or PostgreSQL value was written
by hand, and no CLI was used as a management surface.

## Initial API state

    REQUEST: GET /api/v1/admin/runtime-node-catalog  @03:31:37.439Z
             GET /api/v1/admin/runtime-nodes         @03:31:37.494Z
    RESULT:  200, 200
    UI:      "Runtime nodes — 11 runtime nodes", "Live updates connected"

The list is populated from the canonical API before any realtime frame arrives.

## Reverb subscription

    ENDPOINT:   wss://app.utcp.local.test:443/app/<app-key>?protocol=7&client=js&version=8.5.0
    CHANNEL:    private-tenant.a2315712-d650-4d43-8efb-1ac0e3cb356c.runtime-nodes
    AUTH:       `pusher:subscribe` carried a server-issued auth signature
                (value not reproduced here)
    SUBSCRIBED: yes — `pusher_internal:subscription_succeeded` @03:31:37.679Z

The channel is exactly the tenant-scoped RuntimeNode channel for the active
tenant, in Laravel's `private-` form of `tenant.{tenantId}.runtime-nodes`.

## Canonical mutation

Performed from a **separate page (Page B)** in the same authenticated context so
that Browser A took no local action whatsoever — making any refetch on Browser A
unambiguously notification-driven.

    ACTION: "Activate" on RT1A Realtime Proof 20260815, clicked in the normal
            Runtime nodes Admin UI  @03:32:44.558Z
    BEFORE: desired_state draft ("Creating")
    HTTP:   POST /api/v1/admin/runtime-nodes/{id}/desired-state (issued by the UI)
    AFTER:  desired_state active ("Starting"); canonical row confirms `active`

## Transactional outbox

    OUTBOX ID:      c0debab2a4794fbd35d5e29ec3f02cfd
    EVENT TYPE:     runtime_node.desired_state_changed
    AGGREGATE TYPE: runtime_node
    AGGREGATE ID:   1e1075fd-1412-4a2e-8a65-6ddfc92918d5  ← exactly the mutated node
    TENANT:         a2315712-d650-4d43-8efb-1ac0e3cb356c
    STATE:          dispatched
    CREATED:        2026-08-15 03:32:44+00
    DISPATCHED:     2026-08-15 03:32:47+00

The earlier creation produced its own intent
(`79ed93e1ad66…`, `runtime_node.created`, same aggregate id), confirming the
mutation and the notification intent commit together.

## Automatic dispatcher

    CLAIM:       the row was claimed and processed by the running
                 control-plane-outbox-dispatcher with no operator action
    DISPATCH:    attempt_count 1
    FINAL STATE: dispatched, 3 s after the mutation

No manual flush command, manual queue execution, or manual Reverb broadcast was
used as the success path.

## Browser notification

Received by Browser A, which had performed no local action:

    EVENT:          runtime-node.operational-state.changed
    EVENT TYPE:     runtime_node.desired_state_changed
    AGGREGATE TYPE: runtime_node
    RUNTIME NODE:   1e1075fd-1412-4a2e-8a65-6ddfc92918d5  ← the exact mutated node
    TENANT:         a2315712-d650-4d43-8efb-1ac0e3cb356c
    OCCURRED AT:    2026-08-15T03:32:44.000000Z
    RECEIVED AT:    2026-08-15T03:32:49.268Z
    CHANNEL:        private-tenant.a2315712-….runtime-nodes

## Notification-driven canonical refetch — decisive

    NOTIFICATION TIME: 03:32:49.268Z
    REFETCH REQUEST:   GET /api/v1/admin/runtime-node-catalog @03:32:49.269Z (+1 ms)
                       GET /api/v1/admin/runtime-nodes        @03:32:49.350Z
    HTTP RESULT:       200, 200
    CANONICAL STATE:   desired_state active
    UI BEFORE:         "Creating"  with controls Details / Activate / Disable
    UI AFTER:          "Starting"  with controls Details / Drain / Disable
    PAYLOAD USED AS AUTHORITY: **NO**

The sequence is strictly notification → API refetch → UI reflects the API
result. The UI did not change until the refetch returned, and Browser A issued no
request between page load and the notification.

## Event payload authority check

    PAYLOAD FIELDS:          event_type, aggregate_type, runtime_node_id,
                             tenant_id, occurred_at
    FULL RUNTIME NODE PRESENT: NO — no name, slug, family, adapter, endpoints,
                             capabilities, credentials, lifecycle, or observed state
    SENSITIVE DATA PRESENT:  NO

The payload is bounded invalidation metadata, exactly as the contract requires.

## Duplicate notification behavior

The chosen mutation produced a single RuntimeNode notification, so no natural
duplicate case arose.

    DUPLICATE LIVE CASE: not naturally produced
    REFETCH COUNT:       1 refetch pair (catalog + list) per notification

No duplicate outbox rows were created manually. Repository automated coverage
remains the accepted evidence for coalescing.

## Reverb interruption

    METHOD:                 kubectl scale deploy/reverb --replicas=0
                            (canonical Kubernetes lifecycle) @03:33:25.230Z
    BROWSER DISCONNECT:     ws-close code 1006 @03:33:55.295Z, then repeated
                            reconnect attempts at 03:33:56, 03:34:11, 03:34:41,
                            each ws-error → close 1006 with backoff
    UI BEHAVIOR:            badge changed "Live updates connected" →
                            **"Live updates disconnected"** — the UI states the
                            transport is down rather than silently pretending
    API REMAINED AVAILABLE: yes — `https://app.utcp.local.test/login` → 200

Application source, Reverb configuration, and authentication were untouched; no
second Reverb instance was created; browser network interception was not used as
the interruption mechanism.

## Mutation during Reverb outage

    ACTION:          "Disable" on the same node, through the normal Admin UI on a
                     separate page @03:34:22.002Z
    HTTP:            succeeded — Page B rendered "Out of service"
    CANONICAL STATE: desired_state `disabled` in PostgreSQL
    OUTBOX:          717dba574330…, runtime_node.desired_state_changed,
                     occurred 03:34:22, dispatch_status `dispatched` at 03:34:23,
                     attempt_count 1

**Reverb availability did not determine RuntimeNode mutation success.** The
canonical mutation committed and the notification intent was recorded while the
transport was down.

Recorded precisely rather than assumed: the outbox row reached `dispatched`
during the outage rather than pending/retrying. The broadcast hand-off completed,
but no client could receive it — Browser A recorded **0** notifications for that
change. The pending-retry branch was therefore not exercised live; repository
coverage remains the evidence for it. What matters for RT-1A's recovery claim is
independently proven below: Browser A genuinely missed this notification.

Browser A's state during the outage — the setup for the decisive recovery test:

    canonical state:  disabled ("Out of service")
    Browser A UI:     still "Starting"  ← provably stale
    Browser A HTTP:   none since the interruption
    notifications:    0

## Reconnect

    REVERB RESTORED:      kubectl scale deploy/reverb --replicas=1 @03:34:53.606Z,
                          rollout complete, pod reverb-7f86b86dc7-4gl8j Running
    WEBSOCKET RECONNECTED: ws-open @03:35:41.310Z,
                          `pusher:connection_established` @03:35:41.329Z
                          (the 03:34:41 attempt preceded readiness and failed;
                          the client's own backoff produced the next attempt)
    CHANNEL RESUBSCRIBED: `pusher:subscribe` @03:35:41.402Z →
                          `pusher_internal:subscription_succeeded` @03:35:41.406Z
                          on private-tenant.a2315712-….runtime-nodes

No browser state was replayed manually.

## Reconnect canonical resync — decisive

    RECONNECT TIME:   03:35:41.406Z (subscription succeeded)
    REFETCH REQUEST:  GET /api/v1/admin/runtime-node-catalog @03:35:41.406Z
                      GET /api/v1/admin/runtime-nodes        @03:35:41.481Z
    HTTP RESULT:      200, 200
    CANONICAL STATE:  desired_state disabled
    UI STATE:         "Starting" → **"Out of service"**, controls Details /
                      Activate — matching canonical state
    MISSED EVENT RECOVERED: YES
    NOTIFICATIONS RECEIVED FOR THAT CHANGE: **0**

This is the critical recovery property, proven exactly: the browser never
received the notification for the outage-time mutation, yet reconnect triggered
canonical resynchronization and the UI caught up.

    missed notification ≠ permanent stale UI

## Outbox recovery

    BEFORE RESTORE:          the outage-time row was already `dispatched`
    AFTER RESTORE:           unchanged, `dispatched`, attempt_count 1
    MANUAL ACTION REQUIRED:  **NO**

Fleet-wide at the end of the proof: 11 634 `dispatched`, 3 `pending` (ordinary
in-flight traffic from unrelated reconciliation activity), no failed or stuck
rows. No operator flush, manual replay, or repair command was used.

## Refresh corroboration

    REFRESH:      full page reload @03:37:05.004Z
    API FETCH:    GET /api/v1/admin/runtime-node-catalog @03:37:05.112Z → 200
                  GET /api/v1/admin/runtime-nodes        @03:37:05.196Z → 200
    SUBSCRIPTION: re-established — `subscription_succeeded` on the same
                  tenant-scoped channel
    UI:           "Out of service", correct and API-authoritative

Conference auto-rejoin and SIP recovery were **not** tested; they belong to the
dedicated resilience-hardening phase.

## Tenant isolation

    LIVE CHECK:         bounded read-only authorization probe — the authenticated
                        admin session requested channel authorization for
                        `private-tenant.<non-active-tenant-uuid>.runtime-nodes`
                        via `POST /api/broadcasting/auth`
                        → **403 Forbidden**, no auth signature issued
    AUTOMATED COVERAGE: accepted for full cross-tenant membership cases

Scope stated precisely: only one tenant exists in this environment, and the task
forbids fabricating a second tenant, so this probe proves the channel callback's
active-tenant guard rejects a foreign tenant id. The membership/capability branch
of the same callback remains covered by the automated tests. No tenant isolation
was weakened for proof convenience.

## Final RuntimeNode state

    RT1A Realtime Proof 20260815 (1e1075fd-…): desired_state **active**,
    observed_state unobserved — restored through the same Admin UI "Activate"
    control after the proof, producing a fourth notification
    (7a3e213c2124…, dispatched 03:37:22) that Browser A again consumed
    notification → refetch → UI "Starting".

    Retained as an inert external simulator control-plane object with no workload.
    No terminal/irreversible lifecycle action was used.

## V0 preservation

    admitted participants: 0
    open conferences:      1 (the retained V0C6 fixture)
    V0C6 RuntimeNode:      d4539d79-… active / ready, untouched
    V0 status:             COMPLETE / UNCHANGED

No V0 signaling, media, or conference behaviour was modified or exercised.

## Failed proof steps

    None.

## Code changes

    None.

## Documentation updated

    docs/evidence/rt1/rt-1a-runtime-node-realtime-natural-browser-live-proof.md (new)
    docs/roadmap/phase-status.md
    docs/roadmap/implementation-roadmap.md

## RT-1A decision

    LIVE PROVEN / COMPLETE

## RT-1 status

    IN PROGRESS — RuntimeNode vertical slice only.
    Resource coverage is deliberately not expanded.
