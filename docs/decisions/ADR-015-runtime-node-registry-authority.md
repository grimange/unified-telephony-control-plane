# ADR-015: Runtime-Node Registry Authority

## Status

Accepted

## Context

UTCP needs a durable registry for telephony execution providers before command execution, event normalization, health projection, reconciliation, simulator behavior, Asterisk, or FreeSWITCH adapters can be implemented safely.

C0 already provides PostgreSQL-backed kernel primitives for audit, idempotency, outbox, execution context, and event envelopes. C1 already provides PostgreSQL-authoritative identity, tenant context, and server-side authorization.

## Decision

UTCP uses `RuntimeNode` as the sole canonical registry entity for managed telephony execution providers. Runtime nodes are tenant-owned PostgreSQL records.

PostgreSQL is authoritative for:

- runtime-node identity
- tenant ownership
- runtime family
- adapter key
- desired lifecycle state
- normalized endpoints
- encrypted write-only credential metadata
- declared runtime capabilities
- placement metadata
- administrative audit history

Runtime family identifies the external runtime technology, such as `asterisk` or `freeswitch`. Adapter key records the future adapter binding, such as `asterisk-ari` or `freeswitch-esl`. C2 stores and validates those values but does not instantiate adapters, connect to runtimes, run health checks, or execute runtime commands.

Credentials are write-only through the API and UI. Plaintext and ciphertext must not appear in API responses, audit metadata, outbox payloads, logs, evidence, or status commands. Responses may expose only safe metadata such as type, identifier, version, status, rotation timestamp, expiry, and a fingerprint.

Desired lifecycle state is administrator intent. Observed state remains `unobserved` or `unknown` until a later command/event/projection/reconciliation phase establishes observation authority.

## Consequences

- No parallel `ProviderNode`, `TelephonyServer`, `PBXServer`, `AsteriskServer`, or `FreeSwitchServer` registry authority is introduced.
- Redis, Kubernetes Pod discovery, and live runtime endpoints are not registry authority.
- C1 capabilities protect runtime-node administration.
- C0 audit, idempotency, and outbox records are used for registry mutations without leaking secret material.
- A future shared-runtime or cross-tenant runtime model requires a separate architecture decision.
- C3 owns command execution, event normalization, observed-state projection, and reconciliation.
