# ADR-014: PostgreSQL Identity, Tenancy, and Session Authentication

## Status

Accepted

## Context

UTCP needs natural browser login, tenant context, and authorization before runtime registry, telephony sessions, SIP registration, conferences, or runtime adapters can be safely introduced.

The platform already uses PostgreSQL as canonical business storage and Redis as transient coordination/cache/session infrastructure. C0 also established PostgreSQL-backed audit, idempotency, outbox, inbox, execution context, and runtime-neutral kernel primitives.

## Decision

UTCP uses PostgreSQL-authoritative identity and tenancy:

- users
- tenants
- tenant memberships
- account, tenant, and membership status
- code-owned capabilities
- built-in roles
- platform role assignments
- tenant role assignments
- authorization-relevant audit records

UTCP uses first-party Laravel session authentication for the same-origin Vue/Laravel application. Redis may store transient session and login-rate-limit state, but Redis is not canonical identity or authorization storage.

The server computes capability projection for the current platform or tenant context. The frontend renders the server-provided identity, tenant, and capability state, but authorization remains enforced by API services and controllers.

After bounded first-administrator bootstrap, normal user, tenant, membership, password reset, status, and role-assignment management occurs through the web-admin/API authority. The bootstrap command is exceptional initialization, not a second management interface.

## Consequences

- Platform authority remains distinct from tenant membership; platform administrators are not modeled as members of a fabricated system tenant.
- Unknown capabilities fail closed because the catalog is code-owned and synchronized through normal migration/deployment lifecycle.
- Public self-registration, OAuth/OIDC, MFA, email recovery, and external identity providers require later product/security decisions.
- Telephony credentials, telephony sessions, SIP registration, runtime nodes, provider nodes, conferences, and runtime adapters are not part of C1.
- A future external identity-provider integration must preserve server-computed capabilities and PostgreSQL-authoritative authorization state unless a later ADR changes that boundary.
