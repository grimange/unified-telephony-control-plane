# ADR-007: PostgreSQL Is Canonical and Redis Is Transient

## Status

Accepted

## Context

UTCP needs durable business records, audit history, queues, locks, and fast transient projections.

## Decision

PostgreSQL will be the canonical authority for persisted business records and audit history. Redis may be used for queues, locks, caching, and transient projections only.

## Consequences

- Redis data loss must not destroy canonical business state.
- Web/API and worker behavior must commit authoritative state to PostgreSQL.
- Any future Redis projection must be reconstructible from canonical data or runtime observation.
