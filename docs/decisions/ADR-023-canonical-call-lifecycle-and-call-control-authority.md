# ADR-023: Canonical Call Lifecycle and Call-Control Authority

## Status

Accepted and implemented for C6A. This decision establishes the Wave-1
canonical model; execution, observation ingress, API projection, and runtime
adapter work remain bounded C6B-C6E slices.

## Decision

### Call and CallLeg authority

Call is the tenant-scoped logical call identity and owns normalized call-level
lifecycle, direction, correlation, route seams, and terminal metadata. CallLeg
is one runtime/signaling leg and owns runtime-node and runtime-channel
correlation, leg state, remote identity, session association, and the two-party
bridge relationship. Provider channel names, SIP dialogs, ARI objects, and ESL
UUID semantics are not canonical authority.

Only calls and call_legs are new C6 persistence tables. CallParticipant,
durable Bridge, and CallTimelineEntry are not Wave-1 tables. Participant
identity, N-way conference authority, and timeline projection remain deferred
to their accepted boundaries.

### Kernel reuse

Call operations reuse runtime_operations, including its idempotency,
correlation, lease, retry, failure, audit, and outbox integration. Call
observations will reuse append-only runtime_observations; C6A does not add
observation ingress or a parallel observation table.

The normalized operation vocabulary declares target and capability metadata.
Provider-neutral execution remains behind the existing
RuntimeOperationHandler / RuntimeAdapter architecture; C6A adds no provider
execution or eighteen-method adapter interface.

### Conference coexistence

Option B is authoritative: existing conferences and
conference_participants.runtime_channel_id remain the sole authority for
conference legs. Generic C6 calls never mirror or mutate conference
participants. A future conference integration is a separate, bounded
authority cutover requiring its own proof; no dual write or compatibility
synchronization is introduced here.

### TelephonySession and routing boundaries

TelephonySession remains an authorization/control-plane context, not SIP
dialog or media authority. It is nullable on CallLeg; Call does not adopt
session identity. C7 owns route computation and future external addressing.
C6 stores only nullable opaque route/destination/caller-identity seams.

## Consequences

- Runtime-channel uniqueness is fenced by the PostgreSQL partial unique index
  on (runtime_node_id, runtime_channel_id) for non-null channels.
- Terminal state and termination metadata are deterministic and write-once;
  identical repeated terminalization is idempotent.
- Canonical states remain normalized and provider-neutral, with HELD kept
  leg-level.
- C6A provides domain mutation primitives and operation declarations without
  exposing incomplete API or runtime execution paths.
