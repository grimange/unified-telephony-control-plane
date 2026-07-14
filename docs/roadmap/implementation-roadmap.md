# Implementation Roadmap

This roadmap summarizes the phase sequence. The detailed initial plan remains available at `docs/unified-telephony-control-plane-initial-implementation-plan.md`.

## Phase Order

1. F0 - Repository contract and governance.
2. F1 - Minimal application skeleton.
3. F2 - Container build foundation.
4. F3 - Docker Compose core platform.
5. F4 - CI quality baseline.
6. K0 - Local k3d cluster foundation.
7. K1 - Kubernetes application base.
8. K2 - Traefik and Gateway API.
9. K3 - Kubernetes security boundaries.
10. K4 - Initial observability.
11. C0 - Control-plane application kernel.
12. C1 - Identity, tenancy, and authorization.
13. C2 - Runtime registry and runtime-node management.
14. C3 - Command, event, projection, and reconciliation engine.
15. C4 - Deterministic simulator adapter.
16. C5 - Telephony-session and conference domain.
17. T0 - Asterisk ARI adapter.
18. T1 - Kamailio SIP-over-WSS signaling.
19. T2 - Asterisk conference execution.
20. T3 - rtpengine browser media.
21. V0 - Natural login, SIP registration, and conference admission.
22. T4 - FreeSWITCH ESL parity.
23. T5 - Convergence, failover, and recovery.
24. R0 - Portfolio release.

## First User-Facing Vertical Slice

V0 proves:

```text
Natural browser login
  -> authenticated tenant and permission context
  -> short-lived telephony session
  -> SIP REGISTER over WSS through Traefik and Kamailio
  -> conference admission request through UTCP
  -> normalized runtime adapter
  -> Asterisk conference execution
  -> media through rtpengine
  -> observed conference membership
  -> UI shows REGISTERED and CONFERENCE_JOINED
```

The first live slice may use Asterisk, but application API and frontend behavior remain runtime-neutral. FreeSWITCH later implements the same normalized contracts.

## Current Application Foundation

C0 established the PostgreSQL-backed control-plane kernel: runtime-neutral operations, leases, fencing, outbox, inbox, idempotency, audit, event envelopes, and execution context.

C1 establishes the identity and authorization prerequisite for the V0 "natural browser login" step:

- PostgreSQL-authoritative users, tenants, memberships, built-in roles, capabilities, and role assignments.
- First-party session authentication for the same-origin Vue/Laravel application.
- Active-tenant selection and server-computed capability projection.
- Web-admin management for users, tenants, memberships, status changes, password resets, and role assignments after bounded local bootstrap.
- Suspension behavior for users, tenants, and memberships.
- C0 audit integration for identity and authorization mutations.

C2 establishes the runtime registry authority that later command/event/reconciliation work will consume:

- `RuntimeNode` is the sole canonical registry entity.
- Every node belongs to one tenant.
- Runtime family and adapter key are stored separately as metadata.
- Desired lifecycle state is administrator intent; observed state remains `unobserved` or `unknown`.
- Endpoints, encrypted write-only credentials, and declared runtime capabilities are managed through the C1-authenticated web/API surface.
- C0 audit, idempotency, and outbox integration record registry changes without exposing secret material.

C2 does not create telephony sessions, SIP credentials, SIP registration, WSS signaling, simulator behavior, conferences, runtime adapters, command workers, event listeners, health reconciliation, Asterisk workloads, or FreeSWITCH workloads. Those remain in C3 and later phases.

C3 establishes the generic processing engine that later simulator and real adapters consume:

- automatic transactional-outbox dispatch with PostgreSQL claims, leases, retry state, and fencing;
- generic command-worker contracts that reload operation authority from PostgreSQL and fail unsupported handlers or adapters observably;
- raw runtime-event receipts, connection epochs, duplicate/conflict detection, and event-normalizer contracts;
- normalized observations, projection checkpoints, and observed-state projection authority;
- automatic reconciliation state with target leases, fencing, blocked/unsupported outcomes, and idempotent operation creation for actionable drift;
- Compose and Kubernetes process roles for `control-plane-outbox-dispatcher`, `telephony-command-worker`, `telephony-event-normalizer`, and `telephony-reconciler`.

C3 still does not add a deterministic simulator, live Asterisk or FreeSWITCH adapter, ARI/ESL event listener, SIP signaling, conference behavior, media behavior, public command execution route, manual projection route, or manual reconciliation route.

## Phase F0 Exit Criteria

- Repository has a coherent documented purpose.
- `make help` succeeds.
- `make doctor` reports available and missing development tools without modifying the host.
- No real credentials are stored.
- Roadmap phase status is visible.
- CI validates basic repository hygiene.

## Phase Discipline

Phases may be split into smaller implementation corridors, but must not be reordered without an ADR explaining why. Later phases remain planned until their exit criteria are proven.
