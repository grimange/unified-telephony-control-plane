# C6E1 — Asterisk Generic Call Execution and Observation Translation

Status: `IMPLEMENTED / TESTED; LIVE PROOF PENDING` (2026-08-16)

## Scope

C6E1 extends the existing Asterisk ARI `RuntimeAdapter.execute()` path for the
normalized C6 operation catalog and translates generic ARI call facts into the
existing C6 observation ingress. This packet does not perform natural Asterisk
proof, implement FreeSWITCH, add C7 resources, change the C6D API, or create a
new persistence authority.

## Command authority

`CallOperationCatalog` remains the operation vocabulary authority. The existing
catalog-derived `SimulatorCallOperationHandler` is also the registered C6
handler for an Asterisk adapter; it validates the normalized envelope and lets
the existing `CommandWorker` capability gate run before
`RuntimeAdapter.execute()`. `AsteriskRuntimeAdapter` resolves Call, CallLeg,
and relationship targets against tenant and exact current runtime identity,
rejecting stale or conference-owned channels before ARI execution.

The Asterisk client maps the 19 catalog operations to provider-local ARI
requests. Provider syntax is confined to `AsteriskAriClient`; canonical
payloads retain normalized destination/media references. Call-level hangup
operates on every current non-null channel in the canonical Call target. No
Bridge aggregate or provider operation table was added.

Advertised Asterisk capability families are the implemented C6 families:
origination, generic control, hold/resume, transfer, DTMF send, media playback,
and recording. Invalid normalized payloads, unbound channels, stale targets,
unsupported mappings, and ARI failures use the existing
`FailureClass`/`RuntimeOperation` result path. No simulator fallback or feature
gate exists.

## Observation authority and translation

ARI event receipts retain stable provider-event identity through the existing
listener and runtime-event receipt path. Generic call events are normalized by
`AsteriskAriEventNormalizer` to the accepted C6 vocabulary and then follow the
existing path:

`ARI receipt → normalized observation → runtime_observations → ProjectionService → CallObservationProcessor → CallDomainService`.

The normalizer never allocates canonical IDs or mutates Call/CallLeg directly.
`StasisStart` can therefore produce `call.leg.offered` for C6C inbound adoption;
the domain allocates the Call and CallLeg. Channel state, termination, DTMF,
bridge membership, media, recording, hold/resume, and mute events map to their
provider-neutral C6 observations. Conference-owned channels remain on the
existing conference path and cannot be adopted or controlled as generic calls.

Command acceptance does not advance observation-confirmed state. In particular,
an accepted ARI answer, hold, bridge, or hangup request only completes the
RuntimeOperation; the corresponding Call/CallLeg state changes only after a
normalized runtime fact is processed by C6C.

## Verification evidence

Focused repository tests cover exact CallLeg targeting and stale-channel
rejection, inbound `StasisStart` adoption through `runtime_observations`, the
existing Asterisk ARI adapter/conference regressions, and the C6A–C6D suites.
No new table, migration, public endpoint, frontend change, FreeSWITCH change,
or conference/RH authority change was made for C6E1.

Natural Asterisk execution and observation proof is intentionally deferred to
the next narrow Claude Code packet. C6E1 is repository-tested only until that
proof is completed.
