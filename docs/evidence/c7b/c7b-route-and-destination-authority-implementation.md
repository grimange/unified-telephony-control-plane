# C7B Route and Destination Authority Implementation Evidence

## Status

`C7B_COMPLETE` — repository-side authority implementation and focused tests
completed 2026-08-24. This evidence does not claim T6 provider projection or
live SIP/carrier execution.

## Authority and phase boundary

C7A remains the source of tenant-scoped external connectivity, normalized
telephony addresses, caller identities, caller-identity policy, endpoint
lifecycle, and trunk readiness facts. C7B consumes those facts and owns the
canonical route decision:

```text
canonical input
    -> C7B evaluation
    -> provider-neutral RouteDecision
    -> T6 projection/execution (later)
```

T6 remains responsible for projecting these decisions into Kamailio, Asterisk,
FreeSWITCH, or other runtime/provider mechanisms. No provider configuration,
SIP request handling, external PBX, or live carrier proof was added.

## Implemented concepts

- `DestinationRef` is an immutable value object. It supports the existing
  `opaque:` canonical reference form and tenant-resolved
  `telephony_address:<uuid>` references. Provider identifiers and dial strings
  are rejected.
- `InboundRoute` and `OutboundRoute` are tenant-scoped persisted configuration
  authorities in `inbound_routes` and `outbound_routes`.
- `RouteConstraint` is an immutable evaluation explanation, not a universal
  policy/rules engine or a mutable table. It records bounded checks such as
  route lifecycle, trunk eligibility, address direction, and caller policy.
- `RouteDecision` is an immutable derived result. It identifies the selected
  route/trunk/destination/caller identity or a normalized failure and is not
  administrator-editable state. There is no RouteDecision CRUD endpoint or
  second decision store.

Route configuration has a tenant, stable slug, canonical telephony-address
match, external trunk, optional caller identity for outbound routes, priority,
and `draft`/`active`/`disabled`/`retired` lifecycle. Inbound routes also carry a
canonical destination reference. Foreign keys and tenant-scoped uniqueness
prevent orphaned or duplicate route configuration.

## Evaluation

Inbound evaluation accepts a tenant, canonical external trunk, and normalized
telephony-address identity. It selects active exact-match routes ordered by
ascending priority and stable route UUID, then verifies inbound direction and
the C7A trunk eligibility fact before returning a destination reference.

Outbound evaluation accepts a tenant and `DestinationRef` plus an optional
caller identity. It resolves only tenant-owned `telephony_address` references,
selects active exact-match routes in ascending priority and stable UUID order,
checks outbound direction and C7A trunk eligibility, then requires the route
caller identity or an explicit identity to be active and authorized by the C7A
caller-identity policy on the selected trunk. Invalid outbound destinations
return a normalized `destination_invalid` failure; opaque but non-routable
destinations return `destination_not_routable`. No hidden identity substitution
occurs.

Failures remain provider-neutral, including `no_matching_route`,
`no_eligible_route`, `destination_invalid`, `destination_not_routable`,
`caller_identity_not_authorized`, and direction/lifecycle eligibility reasons.

## Management and authorization

The canonical Admin API provides:

```text
GET/POST /api/v1/admin/inbound-routes
GET/POST /api/v1/admin/outbound-routes
POST /api/v1/admin/{inbound|outbound}-routes/{id}/desired-state
```

Tenant administrators use `telephony.routing.view` and
`telephony.routing.manage`. Route writes use the existing idempotency, audit,
outbox, and authenticated active-tenant patterns. Routine route management does
not require Artisan or manual reconciliation. RouteDecision has no CRUD API.

## Negative and provider-neutral evidence

Focused tests cover:

- normalized C7A address and caller-identity reuse;
- deterministic priority selection;
- inbound direction and destination resolution;
- inactive/draft lifecycle exclusion through activation rules;
- C7A trunk readiness/endpoint eligibility;
- caller-identity policy enforcement;
- cross-tenant relationship rejection;
- invalid/non-routable destination failure;
- absence of provider-local fields or dial strings in route configuration and
  decisions;
- idempotent C7B route creation through the canonical API path.

No active competing canonical route table or provider route source was found.
Existing C6 `destination_ref` and runtime adapter destination handling remain
historical/current C6 execution seams; they were not changed or made a second
C7B routing authority. Provider projections remain deferred to T6.

## Explicit non-goals

No Kamailio route/dispatcher projection, Asterisk or FreeSWITCH gateway
configuration, SIP registration/probing, external PBX, carrier credentials,
carrier API, routing economics, load balancing, C7B live SIP proof, V1, K5,
recording, C8, or ADR-026 implementation was performed.
