# C6B — Handlers, Capability Gating, and Simulator Execution

Date: 2026-08-16

Status: `C6B_HANDLERS_CAPABILITY_GATING_AND_SIMULATOR_EXECUTION_IMPLEMENTED_AND_TESTED`

## Scope

C6B registers the normalized C6 operation execution path, reuses the existing
runtime-operation worker and capability gate, and exercises the accepted C6
operation catalog through the deterministic simulator. It does not add
observation ingress, inbound adoption, public API/read projections, Asterisk or
FreeSWITCH call control, C7 resources, frontend code, or new persistence.

## Authority and execution path

`CallOperationCatalog` is the only C6 operation vocabulary authority. The
existing `RuntimeOperationHandler` interface is registered once per catalog
entry by the application provider. `CommandWorker` continues to claim and lease
`runtime_operations`, resolve the tenant/runtime adapter, enforce the declared
capability against `runtime_node_capabilities`, invoke the handler, and persist
the existing success/failure lifecycle.

The simulator uses the same normalized operation envelope as future adapters.
Its C6 branch records a compact execution receipt in the existing simulator
state payload and returns the normal runtime-operation completion result. It
does not create `runtime_observations` and does not mutate observation-confirmed
Call or CallLeg state. There is no simulator-only command API or second
operation list.

## Operation coverage

All 19 accepted catalog operations are registered and tested:

| Operation family | Target | Capability |
| --- | --- | --- |
| originate, cancel origination | `call_leg` | `call.origination` |
| answer, hangup leg, mute, unmute | `call_leg` | `call.control` |
| hangup call | `call` | `call.control` |
| hold, resume | `call_leg` | `call.hold` |
| bridge, unbridge | relationship | `call.control` |
| blind transfer, attended transfer, redirect | `call_leg` / relationship | `call.transfer` |
| send DTMF | `call_leg` | `call.dtmf.send` |
| play media, stop media | `call_leg` | `media.playback` |
| start recording, stop recording | `call_leg` | `recording` |

Relationship validation remains the C6A domain authority: the participating
legs must be distinct, tenant-scoped, and members of the same canonical Call.
Normalized media references are opaque `utcp:media/<id>` values and no provider
payloads enter the operation envelope.

## Deterministic proof

The focused C6B feature test proves:

- all 19 catalog entries have exactly one registered handler and complete through
  the real `CommandWorker` and simulator;
- missing capability produces the existing `unsupported_capability` terminal
  failure with zero simulator adapter execution;
- invalid normalized payload produces the existing `invalid_request` terminal
  failure before adapter execution;
- repeated operation creation with the same runtime-operation idempotency key
  returns one canonical operation and executes the simulator once; and
- successful command execution creates no runtime observation and leaves the
  Call/CallLeg observation state unchanged.

Existing simulator, runtime-operation, conference, and C6A tests remain part of
the verification set. No browser or live telephony proof is required for C6B.

## Boundaries preserved

`runtime_operations` remains the operation and idempotency authority;
`runtime_observations` remains the observation authority. No conference model,
participant, recovery state, TelephonySession identity, C6A schema, or C7
resource is changed. Asterisk and FreeSWITCH call-control execution remain
future slices.
