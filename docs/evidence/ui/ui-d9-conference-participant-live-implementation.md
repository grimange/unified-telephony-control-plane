# UI-D9 — Conference and Participant Live Operations Implementation

Verdict: `UI_D_CONFERENCE_PARTICIPANT_LIVE_IMPLEMENTATION_COMPLETE`

Implementation type: bounded repository implementation. Natural browser proof was intentionally not run.
Starting commit: `2b848eb` (`docs(ui): prove runtime node live updates`).
Phase marker: `UTCP_PHASE=T1` unchanged.

## Scope

UI-D9 extends the UI-D1 live-notification corridor, already proven in
[`ui-d8-runtime-node-live-behavior-proof.md`](ui-d8-runtime-node-live-behavior-proof.md), from RuntimeNodes
to Conferences and Conference participants:

```text
canonical conference.* / conference_participant.* outbox event
→ queued notification-only broadcast
→ tenant-authorized private Reverb channel
→ shared frontend Echo connection
→ canonical Conference list/detail/participant reread
```

The implementation also closes the three bounded RuntimeNode follow-ups recorded by UI-D8:

- duplicate initial RuntimeNode catalog/list requests
- false stale badge after tenant switching when the socket remains connected
- `pusherTransportTLS` storage-boundary classification

## Conference Snapshot Authorities

Canonical browser state remains the existing HTTP snapshot APIs:

```text
GET /api/v1/admin/conferences
GET /api/v1/admin/conferences/{conference}
GET /api/v1/admin/conferences/{conference}/participants
```

The new operational view never treats a WebSocket event as state. Events only select which canonical
snapshots to reread. Disconnection preserves the last canonical Conference data and marks live data stale.

## Conference Event Sources

The outbox bridge accepts only:

```text
aggregate_type = conference             and event_type starts conference.
aggregate_type = conference_participant and event_type starts conference_participant.
```

Conference events use their aggregate id as `conference_id`. Participant events require a deterministic
`conference_id` in the canonical outbox payload; participant events without that identifier are not
broadcast because the browser must not infer ownership.

## Channel Authorization

The private channel is:

```text
tenant.{tenantId}.conferences
```

It is authorized through the same Laravel broadcasting endpoint and session authority as the RuntimeNode
channel. Authorization requires:

```text
valid identity.session
requested tenant == session active tenant
AuthorizationService::requireTenant(user, tenant, telephony.conferences.view)
```

Wrong tenant, missing capability, suspended/invalid session, and no session are covered by deterministic
backend tests.

## Notification Envelope

The one stable browser event name is:

```text
conference.operational-state.changed
```

The notification-only payload is exactly:

```text
event_type
aggregate_type
aggregate_id
conference_id
tenant_id
occurred_at
```

It contains no participant names, addresses, endpoints, credentials, media data, adapter data, canonical
state, or full outbox payload.

## Outbox Bridge

`OperationalBroadcastBridge` is the shared post-commit bridge for RuntimeNode and Conference operational
notifications. It preserves the existing queued, after-commit, failure-isolated behavior:

```text
domain transaction commits
→ outbox row remains canonical
→ broadcast is attempted asynchronously
→ broadcast failure does not reverse the canonical mutation
```

No controller, frontend-facing API service, adapter implementation, manual replay command, polling path, or
routine Artisan management command was added.

## Shared Frontend Connection Lifecycle

The existing frontend realtime authority now manages both tenant channels through one Echo/Pusher client:

```text
tenant.{tenantId}.runtime-nodes
tenant.{tenantId}.conferences
```

Entity-specific handlers remain separate. RuntimeNode notifications refresh the RuntimeNode list and scoped
open-node detail resources. Conference notifications refresh the Conference list and only the selected
Conference detail/participants when the event matches the selected Conference.

Tenant switch leaves all old-tenant channels and clears callbacks before subscribing to new-tenant channels.
Logout and session rejection leave channels, disconnect the one Echo client, and clear callback state.

## RuntimeNode Follow-Ups

The duplicate initial RuntimeNode catalog/list reread was removed at the redundant realtime completion path.
Initial RuntimeNode navigation and in-app navigation now issue:

```text
runtime catalog requests: 1
RuntimeNode list requests: 1
per-node detail requests: 0
```

Tenant-switch stale-state handling now requires both the new canonical snapshot and the new private-channel
subscription before clearing stale. A global socket `connected` event is no longer required to repeat.

`pusherTransportTLS` is classified as vendor-owned transport cache only. Application-owned persistent storage
remains `utcp.appearance`. Tests permit `pusherTransportTLS` only when it is bounded JSON metadata containing
an expected `transport` (`ws` or `wss`) and a timestamp, with no tenant id, user id, channel, auth signature,
socket id, application key, credential, or secret.

## Operational Route and View

The new route is:

```text
/operations/conferences
```

Navigation is capability-gated by `telephony.conferences.view`. The route is read-only operational state, not
a management authority. It displays:

- Conference list from the canonical list API.
- One selected Conference detail.
- Participant list only for the selected Conference.
- Loading, refreshing, empty, error/forbidden, connected, reconnecting, disconnected/stale, and unavailable
  states using existing UI-B primitives.

No creation, destructive lifecycle controls, participant mutation controls, call-leg view, registration view,
event replay, manual synchronization, or polling fallback was added.

## Request Budgets

Repository tests assert:

```text
initial Conference route:
  Conference list requests: 1
  Conference detail requests: 0
  Participant requests: 0

select one Conference:
  selected Conference detail: 1
  selected Conference participants: 1
  unselected Conference detail/participants: 0

RuntimeNode initial navigation:
  catalog: 1
  list: 1
  per-node detail: 0
```

Realtime module tests also cover selected-Conference notification rereads and wrong-tenant/malformed event
ignoring. No request count scales with the total number of Conferences or RuntimeNodes.

## Reconnect and Resume

On reconnect or material tab resume, the shared realtime authority rereads:

```text
RuntimeNode list
open RuntimeNode detail resources only
Conference list
selected Conference detail and participants only
```

Stale clears only after the active subscriptions are ready and canonical rereads succeed. Failed rereads
preserve existing canonical data and keep stale status observable.

## Responsive and Accessibility

The Conference heading/live badge uses the existing wrapping section-heading contract. Conference detail and
participant rows use bounded grid tracks (`minmax(0, ...)`) and collapse to a single column at the established
720 px breakpoint. The repository hygiene check rejects root-level overflow masking and verifies the new
Conference responsive contract.

## Tests

Focused tests added or extended:

- `apps/api/tests/Feature/TelephonyDomain/ConferenceRealtimeBroadcastTest.php`
- `apps/api/tests/Feature/RuntimeRegistry/RuntimeNodeRealtimeBroadcastTest.php`
- `apps/web/src/realtime/runtimeNodeRealtime.test.ts`
- `apps/web/src/App.test.ts`
- `scripts/check-repository-hygiene`

Focused commands already run during implementation:

```bash
cd apps/api
php artisan test tests/Feature/TelephonyDomain/ConferenceRealtimeBroadcastTest.php tests/Feature/RuntimeRegistry/RuntimeNodeRealtimeBroadcastTest.php

cd apps/web
npm run typecheck
npm run test -- src/realtime/runtimeNodeRealtime.test.ts src/App.test.ts

make repository-hygiene
```

Broad verification is recorded in the final task report.

## Deferred Natural Browser Proof

UI-D remains In Progress. The remaining proof is one focused natural Playwright MCP proof of Conference and
participant live notifications, canonical scoped rereads, reconnect behavior, tenant isolation, logout,
request budgets, storage boundary, and responsive behavior.
