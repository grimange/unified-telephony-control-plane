# RT-1A — RuntimeNode Realtime Control-Plane UI Notifications

## Verdict

`RT_1A_RUNTIME_NODE_REALTIME_NOTIFICATION_VERTICAL_SLICE_IMPLEMENTED_AND_TESTED`

RT-1A is the RuntimeNode-first repository vertical slice. Natural Admin
browser proof remains pending and is intentionally outside this implementation
packet.

## Authority and scope

V0 remains complete. PostgreSQL and the existing RuntimeRegistry/domain,
projection, and reconciliation authorities remain canonical. REST remains the
RuntimeNode command and read path. Reverb carries invalidation notifications;
it does not carry commands or authoritative RuntimeNode state.

The slice is limited to RuntimeNode lifecycle and observed-state changes. No
conference, SIP, session, trunk, or media authority was moved into realtime
transport.

## Existing infrastructure reused

- `control_plane_outbox_messages` is the existing transactional outbox.
- `OutboxDispatcher` claims pending rows, dispatches them through the existing
  broadcast bridge, and marks success or retryable failure.
- Laravel Reverb, the existing queue/worker path, Redis configuration, and
  internal ClusterIP/Gateway deployment are reused.
- `tenant.{tenantId}.runtime-nodes` is an authenticated private channel. Its
  authorization requires the active session tenant and the existing
  `runtime.nodes.view` capability.

## Notification production

Operator-driven RuntimeNode mutations continue to append outbox intent from
`RuntimeRegistryService` in the same transaction as the canonical mutation.
Background observed-state projection and stale-state derivation now append the
same RuntimeNode notification family from `ProjectionService`, also within the
canonical transaction. Duplicate observations that do not change canonical
state do not append a second notification.

The dispatched event is bounded invalidation metadata: event type, aggregate
type, RuntimeNode identifier, tenant identifier, and occurrence timestamp. It
does not contain the RuntimeNode aggregate, credentials, Kubernetes Secret
data, or internal runtime details.

## Browser behavior

The Runtime Nodes surface still performs its initial canonical API fetch and
then subscribes to the authorized tenant channel. A matching notification
coalesces same-turn refresh requests and refetches the list and open detail
resources through the canonical API. The event payload is never used to patch
RuntimeNode state. Reconnect performs canonical list/detail resynchronization;
duplicate notifications are harmless.

## Verification evidence

- RuntimeEngine focused tests cover observed-state notification production,
  stale-state notification production, and transaction rollback behavior.
- RuntimeNode broadcast tests cover bounded payload, channel authorization,
  dispatch, rollback, and retryable transport failure.
- RuntimeNode realtime Vue tests cover initial fetch/subscription,
  notification-triggered refetch, payload authority, coalescing, reconnect,
  and tenant filtering.

## Status

V0: `COMPLETE`.

RT-1: `IN PROGRESS`.

RT-1A: `IMPLEMENTED / TESTED`; narrow natural Admin browser proof is pending.
