# V1 Canonical Call Termination Authority Implementation

## Scope

This bounded implementation applies ADR-030 to the existing Asterisk and
FreeSWITCH observation seams, domain terminalization, and runtime-loss path.

## Implemented contract

* Asterisk `ChannelDestroyed` remains the orderly terminal fact and preserves
  `cause`, `cause_txt`, `tech_cause`, and the native event type as runtime facts.
* Asterisk `StasisEnd` is no longer a CallLeg terminal observation.
* FreeSWITCH `Hangup-Cause` is preserved as `hangup_cause`; it is not persisted
  as a canonical termination reason.
* The domain derives `requested` from qualifying pre-observation
  `call.hangup`, `call.leg.hangup`, or `call.leg.cancel_origination` intent.
  Other orderly terminal observations become `remote`.
* Authoritative `ProjectionService::markStale()` loss transitions produce
  `call_leg.failed` for bound non-terminal legs as `runtime_lost` / `runtime`.
  Ordinary readiness updates do not fan out failures.
* Terminal metadata remains write-once; duplicate and trailing observations are
  ignored after terminalization.

## Preserved boundaries

CallLeg observation semantics, Asterisk/Kamailio/provider signaling, timeout
policy, delayed-observation policy, provider BYE routing, RuntimeNode
placement, and provider failure taxonomy were not changed.

## Verification

Focused Asterisk, CallObservationProcessor, FreeSWITCH, and runtime stale-loss
regressions were added or updated. The containerized API suite and repository
hygiene checks are the required completion gates; their exact results are
recorded with the implementation handoff.

## Remaining live proof

Controlled live termination proof remains: requested hangup must remain
`requested` / `control_plane`, orderly non-UTCP termination must classify as
`remote` / `remote`, and genuine canonical runtime loss must produce
`call.leg.failed` / `runtime_lost` / `runtime`.
