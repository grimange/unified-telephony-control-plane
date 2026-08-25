# C7A External Connectivity, Telephony Addressing, and Caller Identity

**Status:** Complete — repository implementation and focused tests passed on
2026-08-24.

## Authority resolved

C7A establishes the tenant-scoped desired-state authorities that later routing
and projection phases consume:

- `external_trunks` is the provider-neutral external SIP/PSTN connectivity
  relationship. It owns lifecycle, supported direction/capability metadata,
  observed health fields, configuration version, and readiness/eligibility
  derived from canonical state. It is not an Asterisk, FreeSWITCH, or Kamailio
  configuration record.
- `trunk_endpoints` and `trunk_credential_references` are provider-neutral
  endpoint and secret-reference records beneath an external trunk. Credential
  material is encrypted at rest, never returned by the API, and only the
  reference, fingerprint, version, and lifecycle metadata are exposed.
- `telephony_addresses` is the canonical normalized address authority. The
  implementation supports the repository-authorized `e164` and `sip_uri`
  forms, with deterministic normalization and tenant-scoped uniqueness.
- `caller_identities` authorizes a named originating identity backed by one
  tenant-owned telephony address. `caller_identity_policies` associates that
  identity with an eligible tenant-owned external trunk.
- `external_trunk_addresses` records the tenant-owned address/trunk
  relationship and its inbound, outbound, or both direction.

All mutations use the existing authenticated tenant context, service-layer
transactions, audit/outbox emission, and idempotency conventions. No provider
identifier is part of the canonical payload.

## Lifecycle and validation

External trunks use the repository-defined lifecycle vocabulary:
`draft`, `validating`, `active`, `draining`, `disabled`, and `retired`.
Retirement is terminal. A trunk cannot become active without an active
endpoint. Endpoints, addresses, and caller identities use the bounded
`draft`/`active`/`disabled`/`retired` lifecycle; retirement is terminal, and
active caller identities require an active address. Address attachment rejects
cross-tenant, retired, inactive, and unsupported-direction relationships.

Credential rotation retires the previous active reference for the same
credential type and increments its version. Secret values are not included in
audit, idempotency, outbox, or response payloads.

## Canonical management surface

The authenticated Admin API provides list/read/create/update and lifecycle
operations for trunks, endpoint creation and lifecycle, credential-reference
rotation, address creation and lifecycle, address attachment, caller-identity
creation and lifecycle, and caller-identity policy association. The API is the
normal management authority; no routine CLI or manual reconciliation path was
added. No UI was required by the current C7A contract.

## Phase boundary

C7A answers what external connectivity, telephony addresses, and caller
identities exist and are eligible. C7B remains responsible for inbound and
outbound route, constraint, `RouteDecision`, and `DestinationRef` authority.
T6 remains responsible for projecting canonical trunk and route intent into
live signaling/runtime adapters. No route evaluation, provider projection,
carrier registration, or live carrier integration was implemented.

Existing C6 `Call`, `CallLeg`, `RuntimeOperation`, `RuntimeObservation`, and
`RuntimeNode` authorities were not changed. T4 remains historical complete
evidence; K5 remains Planned / Parallel / R0-Critical.

## Verification

Focused test:

```text
cd apps/api
php artisan test --filter='C7aAuthorityTest'
```

Result: 2 tests passed, 32 assertions. The tests cover normalized E.164 and
SIP addresses, idempotent writes, encrypted credential storage and API secret
non-disclosure, endpoint lifecycle, address association, caller-identity
eligibility, cross-tenant rejection, unsafe trunk activation, and absence of
provider-local fields.

The implementation is repository-only. No carrier credentials, external SIP
peer, provider projection, or live external-connectivity proof was required by
C7A and none was performed.

## Explicit non-goals

No inbound/outbound route evaluation, `DestinationRef` implementation, live
SIP registration, carrier provisioning, number purchasing or porting,
billing/settlement, provider projection, C8, K5, recording, or ADR-026 media
processing was introduced.
