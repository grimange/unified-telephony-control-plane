# C6C — Normalized Observation Ingress, Inbound Adoption, and Fencing

Date: 2026-08-16

Status: `C6C_OBSERVATION_INGRESS_INBOUND_ADOPTION_AND_FENCING_IMPLEMENTED_AND_TESTED`

## Scope

C6C adds the observation-confirmed side of the C6 contract. It reuses
`runtime_observations` and the existing normalizer/projection path, then routes
normalized `call.leg.*` facts through `CallObservationProcessor` into
`CallDomainService`. No observation table, provider event table, public API,
adapter-specific ingress, C7 resource, or frontend surface was added.

## Inbound adoption and authority

An offered normalized observation carrying `runtime_node_id` and
`runtime_channel_id` derives tenant ownership from the RuntimeNode. The domain
allocates one inbound `Call` and one `CallLeg` at `OFFERED`, with the C7 route
seam nullable. Existing exact runtime-channel bindings are reused. The
`call_legs_runtime_channel_unique` partial index remains the final database
fence; same-node adoption attempts are serialized by the RuntimeNode lock.

Channels already owned by `conference_participants` are rejected by the generic
adoption path. Conference authority, RH recovery state, and conference
bindings are not mirrored or mutated.

## Observation processing and fencing

The processor persists normalized observations through the existing append-only
projection before attempting canonical mutation. The existing source-event
identity prevents duplicate mutation. Closed connection epochs do not apply
canonical mutations. State transitions use exact tenant, RuntimeNode, and
runtime-channel correlation plus the C6 transition table, so late/out-of-order
facts cannot regress state or terminate another current leg. Terminal metadata
is write-once and repeated identical terminal facts are idempotent.

Bridge/unbridge facts require two exact current legs from the same tenant and
Call, update the symmetric `bridged_to_leg_id` relationship in one transaction,
and ignore stale nonmatching unbridge facts. Held/resumed remain leg-level.
`call.leg.dtmf_received` is stored as a normalized observation containing the
digit/event data and does not interpret business meaning or mutate lifecycle
state.

The simulator emits C6 observations through the standard scheduled event,
receipt, normalizer, projection, and domain processor path; it does not write
Call or CallLeg state directly.

## Verification

`CallObservationProcessorTest` covers offered adoption, same-source duplicate
dedupe, different events for one channel, state progression and out-of-order
fencing, terminal write-once behavior, bridge/unbridge symmetry and stale
unbridge rejection, conference-channel isolation, DTMF observation storage,
and closed-epoch fencing. Existing C6A, C6B, runtime-observation, runtime
engine, and conference regressions remain required verification.

No browser proof or live telephony proof is required for C6C. Asterisk,
FreeSWITCH, C7, public API, frontend, and persistence schema remain unchanged.
