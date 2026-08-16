# RH-2C — Canonical ARI Runtime Observation Authority Fix

## Status

`RH_2C_ARI_RUNTIME_OBSERVATION_AUTHORITY_FIXED_AND_TESTED`

This bounded repository correction removes the false RuntimeNode-health
authority from ordinary and unknown Asterisk ARI traffic. No browser or live
proof was performed.

## Root cause

`AsteriskAriEventNormalizer` assigned `degraded` to its generic fallback
observation. `ProjectionService` then treated every `runtime_node` observation
as a readiness mutation, so ordinary call events such as `StasisStart` and
channel/bridge traffic oscillated the canonical RuntimeNode between `ready`
and `degraded`. That false state made RH recovery appear ineligible inside its
valid grace period.

## Bounded correction

The generic normalizer fallback now retains an `unknown` capability/evidence
observation rather than manufacturing degraded health. `ProjectionService`
continues projecting capability snapshots, but only readiness-bearing
RuntimeNode observations may mutate `runtime_nodes.observed_state`.

Existing explicit connection, listener, readiness, and authentication-failure
mappings remain health-bearing. Capability freshness is evaluated from the
capability snapshot timestamp rather than an unrelated RuntimeNode readiness
timestamp.

The listener, binder, participant/channel observation, runtime receipts, and
RH-2B same-channel retry path are unchanged. No Vue, V0, RT-1A, RH-1 grace, or
runtime-BYE changes were made.

## Tests

Focused Asterisk normalizer, capability projection, and recovery-related tests
prove that readiness observations remain authoritative, explicit degraded
health remains supported, `StasisStart`/unknown traffic cannot degrade a ready
node, and capability evidence is still retained. Browser proof was not
performed.

## Phase chain

```text
V0 COMPLETE
→ RT-1A COMPLETE / LIVE PROVEN
→ RH-0 COMPLETE
→ RH-1 IMPLEMENTED / TESTED
→ RH-2B IMPLEMENTED / TESTED
→ RH-2C IMPLEMENTED / TESTED
→ narrow runtime-initiated BYE client diagnosis pending
→ RH-3 not implemented
```
