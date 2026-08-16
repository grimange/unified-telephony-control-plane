# V0-C4 Participant Binder Admission-Reference Recovery

## Status

Implemented and repository-tested. This bounded fix preserves the existing
conference admission, Kamailio routing, Asterisk dialplan, RuntimeNode
provisioning, and bridge authorities. Browser/live proof was not performed.

## Event-shape root cause

The raw ARI `StasisStart` payload carries application arguments as a top-level
`args` list, for example:

```json
{
  "type": "StasisStart",
  "args": ["conf-d7bdd037-1287-41fa-9c49-f990014704ff"],
  "channel": {"id": "1786758655.0"}
}
```

The WebSocket client sanitized the event but discarded `args`. The listener
therefore passed an event without the admission argument to a binder that
expected `event['args'][0]`, so the binder returned before resolving the
participant.

The client now preserves only bounded, sanitized string arguments. The listener
translates the sanitized ARI event once into the canonical binder shape:

```php
[
    'channel_id' => '1786758655.0',
    'application_args' => ['conf-d7bdd037-1287-41fa-9c49-f990014704ff'],
]
```

The binder consumes only that normalized representation and accepts only the
existing `conf-<UUID>` namespace. Authorization and participant/runtime
revalidation remain in the binder query.

## Binding invariant

For a valid admitted `self_admission` participant, the normalized StasisStart
argument resolves the canonical participant, persists the actual inbound ARI
channel id as `runtime_channel_id`, and passes that same channel id to the
existing bridge attachment method for `utcp-conf-<conferenceId>`. Repeated
delivery of the same channel is idempotent; a conflicting existing channel is
rejected.

Missing, malformed, unknown, or conflicting references fail closed. The
synthetic participant path remains unchanged.

## Verification

Focused tests cover the actual WebSocket sanitizer shape, listener-to-binder
normalization, exact channel persistence and bridge-attachment arguments,
repeated delivery, malformed/missing/unknown references, and conflicting
runtime-channel protection. The next step is a narrow natural V0-C6 reproof
from the now-working Asterisk entry onward.

V0-C1, V0-C2, V0-C3, and V0-C5 remain live proven. V0 remains in progress.
RT-1 remains planned and unimplemented.
