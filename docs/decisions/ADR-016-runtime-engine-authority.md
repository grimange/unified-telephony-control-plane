# ADR-016: Runtime Engine Authority

## Status

Accepted

## Context

C0 established PostgreSQL-backed runtime operations, leases, fencing, outbox, inbox, idempotency, audit, event envelopes, and execution context.

C2 established tenant-owned `RuntimeNode` registry authority with desired lifecycle state, runtime family, adapter key, endpoints, encrypted write-only credentials, and declared runtime capabilities.

The next foundation needs automatic processing without letting Redis, controllers, runtime-specific listeners, or operator commands become authority.

## Decision

UTCP uses PostgreSQL as the canonical authority for:

- runtime operations and their leases/fencing;
- outbox dispatch state and dispatch leases;
- canonical event-source identity;
- raw runtime-event receipts;
- source epochs;
- normalized observations;
- projection checkpoints;
- reconciliation state, leases, and fencing.

Redis may deliver transient queue wake-ups. Queue delivery is never proof that canonical state changed.

Normal processing is automatic through generic process roles from the existing backend image:

```text
control-plane-outbox-dispatcher
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
```

Runtime-specific listeners and adapters remain leaf implementations in later phases. C3 defines contracts and proves the engine with test-only fixtures, but it does not add a production simulator, null adapter, Asterisk adapter, FreeSWITCH adapter, ARI client, ESL client, SIP signaling, conference behavior, public command-execution route, manual projection route, or manual reconciliation route.

Observed runtime-node state changes only through projection authority. Administrative desired state and projected observed state remain separate.

C3 event-source authority is runtime-neutral. A source may be backed by a canonical `RuntimeNode`, or it may represent shared platform infrastructure that has no RuntimeNode. The same PostgreSQL source identity owns its lease, fencing, source epochs, receipts, and checkpoints. Platform infrastructure must not create fake RuntimeNodes or parallel platform-specific lease, epoch, receipt, or checkpoint tables.

## Consequences

- PostgreSQL remains the source of truth when queue messages are duplicated, delayed, or lost.
- Leases and fencing protect outbox dispatch, command execution, event normalization, projection checkpoints, and reconciliation.
- RuntimeNode-backed listeners and future platform observers use the same event-source ownership and stale-owner rejection path.
- Unsupported handlers, unsupported event types, and missing adapters fail observably instead of silently succeeding.
- The deterministic simulator begins later as a real adapter implementation against these contracts.
- Asterisk and FreeSWITCH integrations remain runtime-specific leaf adapters/listeners and must not become central domain authority.
