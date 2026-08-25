# T6 External Trunk and Live Route Projection — Implementation Evidence

## Status

The repository-side T6 projection contract is implemented and tested. T6
remains active because the synthetic external SIP acceptance path is outside
this repository-only packet and is reserved for the later live-proof boundary.

## Authority and boundary

C7A remains the only editable authority for `ExternalTrunk`, endpoints,
credential references, addresses, caller identities, and their lifecycle.
C7B remains the only editable authority for inbound/outbound routes and
`DestinationRef`; `RouteDecision` remains a derived immutable result. T6
renders those authorities into replaceable provider artifacts and does not
provide provider-specific management CRUD.

The current approved projection targets are Kamailio and the selected Asterisk
runtime adapter. FreeSWITCH remains the existing T4 call-execution adapter and
is not added as an external-trunk projection target by this bounded slice.

## Projection contract

`ExternalTrunkProjectionService` renders one deterministic artifact per
tenant/trunk/provider into `external_trunk_projection_artifacts`:

```text
C7A/C7B canonical state
    -> T6 projection artifact
    -> later provider apply/verification seam
```

Artifacts contain provider-local derived identifiers, endpoint/address data,
active inbound/outbound route data, lifecycle eligibility, and credential
reference metadata. They never contain credential plaintext. Stable provider
identifiers derive from the canonical trunk UUID, not mutable labels.

`T6ProjectionDispatcher` is attached to the existing outbox dispatcher. C7A
and C7B canonical events automatically reproject the tenant through the
existing outbox lifecycle. No manual projection command, feature gate, or
provider-specific management surface was added.

Outbound execution intent consumes an already selected `RouteDecision`; it
does not evaluate routes or select another trunk. Inbound artifacts preserve
the canonical trunk/address mapping for the later signaling-to-C7B seam.

## Lifecycle, idempotency, and credentials

Active trunks project as accepting new calls. Draining trunks project with
new-call eligibility disabled. Draft, validating, disabled, and retired
trunks are rendered as removed/cut off; projection rows remain as evidence
rather than being hard-deleted. Repeated projection produces the same stable
artifact hash and does not create duplicate provider artifacts.

Credential references are projected only as reference ID/version metadata.
The encrypted secret remains in the C7A credential authority. A credential
rotation event is handled by the same tenant reprojection path.

## Provider mappings

```text
ExternalTrunk + addresses + routes
    -> Kamailio derived trunk/address/route artifact

ExternalTrunk + endpoints + routes
    -> Asterisk derived endpoint/route artifact

RouteDecision
    -> provider execution intent preserving selected trunk,
       destination, caller identity, and route ID
```

No Kamailio script, Asterisk configuration file, FreeSWITCH gateway, carrier
registration, or live SIP peer was created here. Applying these derived
artifacts to live provider infrastructure remains the next runtime proof
boundary rather than a second canonical authority.

## Verification

Focused test:

```text
php artisan test --filter='T6ExternalTrunkProjectionTest'
2 tests passed, 31 assertions
```

The test proves outbox-triggered convergence, deterministic repeated apply,
provider-neutral canonical input, selected-RouteDecision preservation,
credential plaintext protection, and disabled-trunk cutoff without deleting
projection evidence.

## Explicit non-goals

This packet does not implement V1 natural external SIP proof, an external PBX
fixture, carrier connectivity, provider-specific Admin CRUD, K5, C8, recording,
or ADR-026 media/AI functionality.
