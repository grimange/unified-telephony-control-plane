# ADR-013: PostgreSQL-Backed Control-Plane Application Kernel

## Status

Accepted

## Context

Later UTCP phases need one runtime-neutral application kernel for accepted work, worker ownership, message handoff, idempotency, auditability, and causation metadata. If these primitives are implemented separately by controllers, runtime adapters, queue jobs, or vendor-specific integrations, UTCP would accumulate duplicate authorities and runtime-specific business logic before the core contracts are proven.

The kernel must also preserve existing authority boundaries:

- PostgreSQL is canonical business storage.
- Redis may support queues and transient coordination but must not own durable operation state.
- Runtime-specific systems such as Asterisk, FreeSWITCH, Kamailio, and rtpengine execute protocols and media, but they do not own UTCP desired state or audit history.

## Decision

UTCP uses a PostgreSQL-backed, runtime-neutral control-plane application kernel as the canonical foundation for:

- Runtime-operation lifecycle state.
- Worker claiming, leases, and fencing tokens.
- Transactional outbox messages.
- Deduplicating inbox records.
- Idempotency records.
- Append-only audit records.
- Execution context, request ID, correlation ID, and causation ID.
- Versioned application event envelopes.
- Normalized result and failure classifications.

The current kernel lives inside the Laravel modular monolith under `App\ControlPlane`. It does not create a microservice, public runtime management endpoint, simulator adapter, or telephony runtime integration.

Future process roles are reserved as direction, not deployed in C0:

```text
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
asterisk-ari-events
freeswitch-esl-events
```

Runtime-specific event listeners ingest external streams only. Normalizers emit runtime-neutral observations. Reconcilers compare desired and observed state. Command workers execute generic runtime operations through adapters.

## Consequences

- C1 and later phases must reuse the kernel instead of creating controller-local idempotency, audit, queue, or leasing mechanisms.
- C2 runtime registry and C3 command/event/reconciliation work have a durable persistence and messaging foundation.
- C4 simulator behavior must implement established contracts; it does not define the initial architecture.
- Redis cannot be used as the canonical operation lease or audit authority.
- A future external event bus would require a separate ADR and must integrate with the transactional outbox boundary.
