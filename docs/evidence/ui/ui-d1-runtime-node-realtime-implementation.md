# UI-D1 RuntimeNode Real-Time Notifications Implementation

Verdict: `UI_D1_RUNTIME_NODE_REALTIME_IMPLEMENTATION_COMPLETE`

Implementation type: bounded repository implementation. No natural browser proof was performed.
Starting commit: `8d9555f`. Phase marker: `UTCP_PHASE=T1` (unchanged).

UI foundation status after this implementation:

```text
UI-A = Complete
UI-B = Complete
UI-C = Complete
UI-D = In Progress
UI-E = In Progress
```

## Objective

UI-D1 implements the first vertical real-time corridor selected by
[`ui-d0-realtime-operational-contract-audit.md`](ui-d0-realtime-operational-contract-audit.md):

```text
canonical runtime_node.* outbox event
-> queued notification-only broadcast
-> tenant-authorized private Reverb channel
-> WSS through app.utcp.local.test
-> Laravel Echo subscription
-> canonical RuntimeNode snapshot reread
-> live connected/disconnected state in Runtime Nodes UI
```

The task intentionally stops before natural browser proof of the live WSS path.

## Canonical Snapshot Authority

The browser event stream is notification-only. RuntimeNode state remains owned by the existing canonical
snapshot APIs:

- GET `/api/v1/admin/runtime-nodes`
- GET `/api/v1/admin/runtime-nodes/{id}`
- GET `/api/v1/admin/runtime-nodes/{id}/runtime-evidence`
- GET `/api/v1/admin/runtime-nodes/{id}/history`

The frontend never applies broadcast payload fields as RuntimeNode state. Accepted notifications invalidate
the current RuntimeNode list and reread it. If the affected node is already open, only that node's detail and
runtime-evidence resources are reread.

## Transactional Outbox Bridge

`apps/api/app/RuntimeEngine/Outbox/RuntimeNodeBroadcastBridge.php` bridges the existing persisted outbox
authority. `OutboxDispatcher` invokes the bridge during normal outbox delivery and only for messages whose
aggregate type is `runtime_node` and whose event type starts with `runtime_node.`.

No controller dispatches broadcasts. Mutation services continue writing canonical domain events to the
transactional outbox; broadcast delivery is a downstream asynchronous notification side effect.

## Broadcast Event Envelope

`RuntimeNodeOperationalStateChanged` is the single generic broadcast event. It implements Laravel's queued
broadcast contract and after-commit dispatch mechanism, broadcasts as
`runtime-node.operational-state.changed`, and uses:

```text
private-tenant.{tenantId}.runtime-nodes
```

represented by Laravel `PrivateChannel("tenant.{$tenantId}.runtime-nodes")`.

The broadcast payload is intentionally limited to:

```text
event_type
aggregate_type
runtime_node_id
tenant_id
occurred_at
```

`aggregate_type` is always `runtime_node`. No endpoint configuration, credentials, adapter configuration,
full RuntimeNode state, audit payload, or secret values are broadcast.

## Channel Authorization

Laravel broadcasting is registered through the native application bootstrap using:

```text
prefix: api
middleware: web, identity.session
```

This exposes the session-authorized endpoint at `/api/broadcasting/auth`. The private channel callback for
`tenant.{tenantId}.runtime-nodes` accepts only the active session tenant and calls
`AuthorizationService::requireTenant($user->id, $tenantId, 'runtime.nodes.view')`. Wrong-tenant,
missing-capability, unauthenticated, and invalid-session subscription attempts are rejected with normal
authorization responses.

## Delivery Limitations

Reverb/Pusher-protocol delivery is best-effort notification delivery. The client does not receive a canonical
sequence or replay authority. Duplicate, delayed, or out-of-order notifications remain harmless because every
accepted notification rereads backend state and every reconnect resynchronizes from canonical snapshots.

## Failure Isolation

The domain transaction commits before broadcast delivery is attempted. Broadcast job or Reverb failures cannot
roll back canonical RuntimeNode mutations. Synchronous bridge failures during outbox dispatch follow the
existing outbox retry/failure path; queued broadcast failures remain observable through Laravel queue failure
and logging mechanisms. The browser preserves the last canonical data and marks the live transport disconnected
or stale instead of inventing state.

## Reverb Deployment

Laravel Reverb is installed in `apps/api` and configured by `config/broadcasting.php` and `config/reverb.php`.
The canonical local Kubernetes overlay sets `BROADCAST_CONNECTION=reverb`, and Reverb publish credentials are
provided through an idempotently generated Kubernetes Secret, not a checked-in secret.

The API entrypoint now has a `reverb` role that executes:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

The Kubernetes platform base adds a dedicated `reverb` Deployment and ClusterIP Service using the canonical
API image, `replicas: 1`, port `8080`, resource requests/limits, and TCP startup/readiness/liveness probes.

## WSS Routing

The public authority remains:

```text
https://app.utcp.local.test
wss://app.utcp.local.test
```

The gateway nginx config routes `/app/` to the `reverb:8080` Service with HTTP/1.1 Upgrade headers before
the `/api/` and frontend catch-all locations. `/api/broadcasting/auth` remains under the existing `/api/`
FastCGI route to the Laravel API. No NodePort, LoadBalancer, second hostname, or second external port is
introduced.

## NetworkPolicy

`allow-reverb-required-traffic` selects only pods labeled `utcp.io/network-role: reverb`. It permits Reverb
ingress from:

- gateway pods for browser WebSocket connections
- API pods and worker-role pods for broadcast publishing

It permits Reverb egress only to DNS and Redis. The existing gateway, API, and worker policies explicitly
allow egress to Reverb on TCP/8080.

## Frontend Lifecycle

`apps/web/src/realtime/runtimeNodeRealtime.ts` is the single Echo/Pusher lifecycle authority. It owns:

- Echo client creation through a testable factory
- connection state
- tenant private-channel subscription
- unsubscription
- disconnect
- reconnect handling
- authorization failure handling
- RuntimeNode notification callbacks

No Echo, channel, notification, RuntimeNode, tenant, capability, or credential state is persisted in browser
storage.

## Reconnect and Resynchronization

The connection state contract is:

```text
idle
connecting
connected
reconnecting
disconnected
unauthorized
```

The Runtime Nodes UI renders the operator-facing states:

```text
Live updates connected
Live updates connecting
Live updates reconnecting
Live updates disconnected — displayed data may be stale
Live updates unavailable for this session
```

On reconnect or material tab resume, the client rereads the RuntimeNode list and only the currently open
RuntimeNode detail/runtime-evidence resources. The stale indicator is cleared only after canonical reread
succeeds. Failed reread preserves previous data and keeps the stale/disconnected state observable.

## Tenant Switch and Logout

The realtime lifecycle is wired into the existing application session seams:

- tenant switch leaves the old channel before canonical tenant context changes, loads the new RuntimeNode
  snapshot normally, and subscribes to the new active tenant
- logout leaves private channels, disconnects Echo/Pusher, clears connection/callback state, and stores no
  event payload
- canonical session rejection disconnects immediately and does not reconnect until a valid session and active
  tenant exist
- channel authorization denial sets the state to unauthorized without a retry loop

## Request-Budget Preservation

The Runtime Nodes initial navigation still performs the canonical catalog and list requests and zero per-node
detail requests. The subscription auth POST and WSS handshake are transport operations and do not create
RuntimeNode detail fan-out. Notification and reconnect rereads remain list-wide plus scoped open-node detail
rereads only.

## Tests

Focused backend tests cover:

- channel authorization outcomes for no session, valid active tenant, wrong tenant, missing capability, and
  invalid/suspended session
- broadcast channel name, event name, notification-only payload, tenant/node identifiers, and absence of
  secrets/full state
- transactional outbox bridge behavior for committed, rolled-back, registry-change, observed-state,
  non-RuntimeNode, and failure-isolation cases
- Reverb deployment, Service, credentials, WSS gateway routing, NetworkPolicy, and broadcast auth manifest
  contracts

Focused frontend tests cover:

- snapshot-before-subscribe startup
- one Echo client per lifecycle
- correct active-tenant private channel
- no subscription without valid session and tenant
- preserved RuntimeNode request budget and zero initial detail fan-out
- notification-driven canonical rereads without direct payload application
- scoped open-node detail reread
- wrong-tenant and malformed notification rejection
- duplicate/out-of-order notification harmlessness
- disconnect/stale, reconnect/resync, failed-reread stale retention, authorization denial, tenant switch,
  logout/session rejection, and storage/security boundaries
- accessible rendering of connected, connecting, reconnecting, disconnected, and unauthorized live-state text

## Deferred Natural Browser Proof

UI-D remains In Progress pending one focused natural Playwright MCP proof of:

- WSS connection through `app.utcp.local.test`
- private-channel authorization
- canonical RuntimeNode event delivery
- automatic snapshot reread
- reconnect resynchronization
- disconnected/stale presentation
- tenant isolation
- logout disconnect
- zero detail fan-out
- no secret or protected payload exposure
