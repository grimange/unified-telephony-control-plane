# UTCP API Instructions

These rules refine root `AGENTS.md` for `apps/api`. The Laravel API is the
authorized application boundary and domain-service host, not a second runtime
or infrastructure authority.

Keep business, identity, tenant, RuntimeNode, operation, lifecycle, audit,
reconciliation, and desired-state decisions in application services and
PostgreSQL-backed repositories. Controllers authorize, validate, and translate
requests; they do not contain policy, provider commands, or direct runtime
mutation. Do not infer completion or readiness from HTTP success, UI state,
queue publication, WebSocket delivery, or a provider response alone.

Follow the existing Laravel 12 structure, dependency injection, validation,
authorization, route, config, response, DTO/value-object, repository, and
transaction patterns. Migrations are additive, reviewable, indexed for access
patterns, and safe under repeated execution where practical. Redis is not
canonical storage. Jobs and workers reload PostgreSQL authority, claim work
with leases/fencing, classify retryable versus terminal failure, and tolerate
duplicate delivery.

Keep Asterisk, FreeSWITCH, Kamailio, rtpengine, and simulator behavior behind
runtime-adapter contracts and provider projections. Core API contracts use
runtime-neutral identifiers, capabilities, lifecycle states, and observations.
Validate tenant scope, authorization, generation, lifecycle eligibility, and
idempotency before mutation; never silently fall back to another node, provider,
channel, or credential. Keep secrets write-only or metadata-only as specified,
and redact logs, exceptions, payloads, audit metadata, and tests.

For backend changes, add the smallest unit, feature, integration, or migration
regression, then run the affected Make target and broader API checks. Use
disposable database proof only through existing repository lifecycle scripts.
Repository tests prove code; Kubernetes, provider, browser, and recovery claims
require their corresponding live proof. Consult the authority map, applicable
ADR, and phase evidence before changing lifecycle or authority.
