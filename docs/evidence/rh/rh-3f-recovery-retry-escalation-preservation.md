# RH-3F — Recovery retry escalation preservation

Date: 2026-08-15

Status: LIVE PROVEN. The narrow natural retry-cadence reproof passed on
2026-08-15 — see `rh-3f-narrow-retry-cadence-natural-live-reproof.md`.

## Defect

RH-3E correctly released the binding latch after a terminal rejected recovery
INVITE, but the recovery coordinator reset `recoveryRetryIndex` whenever
`participants/self` succeeded. Because admission reuse is intermediate progress,
consecutive rejected replacement INVITEs restarted the RH-3B ladder and produced
a flat short cadence.

## Correction

The existing recovery coordinator now keeps one retry index for the complete
unresolved recovery episode. Canonical admission reuse does not reset it.
Terminal replacement INVITE failure advances the existing RH-3B ladder, while
binding-only polling schedules a recheck without consuming another retry step.
The index resets only when canonical binding reaches Connected, explicit Leave
or terminal canonical state cancels recovery, or a genuinely new recovery
episode begins. The existing 1/2/3/5/8/10-second ladder, 10-second cap, and
20% jitter are unchanged.

No backend, schema, SIP/WSS, telephony, readiness, or resilience constants
changed. RH-3E attempt fencing and rejected-INVITE re-entry remain intact.

## Evidence

The deterministic frontend regression repeatedly rejects the same participant's
replacement INVITE and verifies the ladder progresses through each step without
DELETE, duplicate participation, or a second INVITE while binding is merely
pending. Focused view, resilience, and signaling tests pass. Browser proof was
not performed; the next step is a narrow live cadence proof.
