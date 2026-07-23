# UI-D11 - Echo Subscription Readiness and Reconnect Resynchronization Fix

Verdict: `UI_D_ECHO_SUBSCRIPTION_READINESS_FIX_COMPLETE`

Implementation type: bounded frontend repository correction. Natural browser proof was intentionally not run.
Starting commit: `56e956a` (`docs(ui): prove conference live corridor, record resync blocker`).
Phase marker: `UTCP_PHASE=T1` unchanged.

## Scope

UI-D10 proved the Conference and participant notification corridor, but found one frontend reconnect blocker:
the shared realtime authority waited for subscription readiness through a Pusher-specific channel method that
Laravel Echo channels do not expose. This correction is limited to frontend realtime lifecycle code, focused
unit coverage, and roadmap evidence.

No backend, Kubernetes, NetworkPolicy, Reverb, gateway, Traefik, channel authorization, broadcast event,
outbox bridge, canonical snapshot API, responsive layout, route, capability, or dependency change was made.

## Live Reconnect Symptom

After a Reverb outage, the browser socket reconnected and remained healthy, but the UI stayed in the
reconnecting state for 105 seconds with zero canonical list, detail, participant, or re-authorization requests.
The operational views therefore preserved stale canonical data indefinitely instead of running the bounded
resynchronization path.

## Unsupported Method

The previous production code used optional channel lifecycle calls equivalent to:

```text
channel.bind?.('pusher:subscription_succeeded', ...)
channel.bind?.('pusher:subscription_error', ...)
```

Laravel Echo owns the channel abstraction and exposes subscription lifecycle methods through:

```text
channel.subscribed(callback)
channel.error(callback)
```

The optional `bind` calls silently did nothing with Echo's channel shape. Domain delivery through `.listen(...)`
was unaffected, which is why normal event delivery could be proven while reconnect readiness remained broken.

## RuntimeNode Correction

The RuntimeNode channel now registers readiness through Echo's supported API:

```text
tenant.{tenantId}.runtime-nodes
channel.subscribed(callback)
channel.error(callback)
```

On `subscribed(...)`, the current RuntimeNode subscription is marked ready only after generation, tenant,
channel name, channel object, and session checks pass. The callback then calls the existing bounded completion
function. Initial subscription completion does not run reconnect resynchronization.

On `error(...)`, the RuntimeNode subscription is marked not ready, existing canonical data is preserved, and
the global live state becomes unauthorized or disconnected/stale according to the error. The failed entity
channel is left, and no retry loop or false connected state is created.

## Conference Correction

The Conference channel uses the same Echo lifecycle methods:

```text
tenant.{tenantId}.conferences
channel.subscribed(callback)
channel.error(callback)
```

Conference readiness is tracked independently from RuntimeNode readiness while sharing the same Echo/Pusher
connection. A Conference subscription error does not mutate RuntimeNode readiness unless the shared socket
itself reports a connection failure.

## Generation Fencing

Subscription lifecycle callbacks are fenced by:

```text
entity generation token
active channel name
active channel object
active subscription presence
current sessionActive() result
```

Connection-level socket callbacks are also fenced to the currently active Echo client. Late callbacks from a
previous tenant, previous reconnect generation, replaced channel, or logged-out session cannot clear stale
state or restore a connected badge.

## Idempotent Reconnect Completion

Socket disconnection increments the shared realtime generation, resets active subscription readiness flags,
and marks reconnect resynchronization required. Reconnect completion now runs at most one canonical
resynchronization per generation:

```text
socket connected
+ all required active subscriptions subscribed
+ active snapshots ready
-> exactly one resynchronizeCanonicalSnapshots()
```

Stale clears only after the required subscriptions are ready and the canonical rereads succeed. Repeated
`subscribed(...)` callbacks or repeated completion checks in the same generation do not duplicate the resync.

## Tenant Switch Behavior

Tenant switching leaves old tenant channels, clears callbacks, resets entity readiness, and subscribes to the
new tenant's required channels. A new tenant can reach connected state without another global socket
`connected` event when the socket is already open. Previous-tenant `subscribed`, `error`, or domain event
callbacks are ignored.

## Logout Interruption Behavior

Logout and session rejection invalidate the active generation, leave RuntimeNode and Conference channels,
disconnect the single Echo client, clear readiness flags, clear pending reconnect ownership, and prevent late
subscription success or error callbacks from changing state. The client does not reconnect while
unauthenticated.

## Echo-Compatible Test Seam

The realtime unit fake now mirrors Laravel Echo's channel surface:

```text
subscribed(callback)
error(callback)
listen(event, callback)
stopListening(event, callback?)
```

It intentionally exposes no channel `bind` method. Tests can emit subscription success, subscription errors,
domain notifications, and connection events without creating a real socket or duplicating production
readiness logic.

## Tests Added

Focused frontend coverage now proves:

- RuntimeNode and Conference subscriptions register `subscribed()` and `error()` callbacks.
- Production source does not contain the unsupported subscription lifecycle `bind` strings.
- A channel with no `bind` method reaches connected state.
- RuntimeNode reconnect performs one list reread plus scoped open-node detail rereads.
- RuntimeNode subscription error preserves canonical data and prevents resync.
- Conference reconnect performs one list reread plus selected detail/participant rereads, or list-only when no
  Conference is selected.
- Conference subscription error preserves stale data and does not create another Echo client.
- Shared readiness waits for both active subscriptions, independent of callback order.
- Repeated subscription-success callbacks do not duplicate reconnect resynchronization.
- Rapid tenant switches ignore previous-tenant success, error, and domain callbacks.
- Logout during pending subscription or reconnect remains authoritative.

Existing App integration tests now use the same Echo-compatible channel shape with no `bind` method.

## Request Budgets

No request-budget authority changed. The correction only changes subscription readiness and reconnect
completion. Existing tests remain responsible for the already-established budgets:

```text
RuntimeNode initial navigation:
  catalog requests: 1
  list requests: 1
  per-node detail requests: 0

Conference initial route:
  list requests: 1
  detail requests: 0
  participant requests: 0

Reconnect:
  RuntimeNode list plus open-node resources only
  Conference list plus selected Conference resources only
```

## Verification

Commands run:

```text
cd apps/web
npm run typecheck
npm run test -- src/realtime/runtimeNodeRealtime.test.ts src/App.test.ts
```

Full required verification is recorded in the final UI-D11 task report.

## Remaining Proof Gap

The only remaining proof gap is the focused natural Playwright MCP proof of reconnect canonical
resynchronization, RuntimeNode tenant-switch readiness, Conference tenant isolation, previous-tenant event
rejection, and logout interruption.
