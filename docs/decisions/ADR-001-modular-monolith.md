# ADR-001: Modular Monolith as the Initial Application Architecture

## Status

Accepted

## Context

UTCP needs clear domain boundaries, queue workers, auditability, and a web/API management surface before it needs independently deployed services.

## Decision

The initial application architecture will be a modular monolith. Domain boundaries may be represented inside one application codebase, but the initial system will not be split into microservices.

## Consequences

- Development starts with one deployable application boundary.
- Transactions, audit writes, and reconciliation state can remain straightforward while the domain model stabilizes.
- Future service extraction requires a separate ADR and operational proof.
