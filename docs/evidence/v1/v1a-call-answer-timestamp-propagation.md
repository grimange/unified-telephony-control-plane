# V1-A Call Answer Timestamp Propagation

Date: 2026-08-29

## Bounded finding

The latest exact-artifact provider proof reached a genuine answer: one logical
provider dial, one provider INVITE, authentication, provider `200 OK`, Asterisk
channels Up, a valid Stasis control channel, an answered CallLeg, an answered
Call, canonical hangup, and completion. The answered CallLeg had a populated
`answered_at`, but the aggregate Call had `answered_at = NULL`.

`CallDomainService::advanceCallFromLeg()` advanced the aggregate
`observed_state` to `answered` without propagating its answer timestamp. The
existing sibling transition paths establish transition-time `now()` as the
canonical answer timestamp authority.

## Repair and proof

The aggregate advancement path now sets `calls.answered_at` from the same
canonical transition-time authority when it first advances a Call to
`answered`. An existing non-null value is preserved, so replayed observations
and subsequent leg advancement are idempotent. CallLeg answer observation
semantics are unchanged.

Focused lifecycle regressions cover answered-leg advancement, first-answer
stability on replay, answered-to-completed preservation, and pre-answer failure
without an answer timestamp. The refreshed containerized backend suite also
passed.

## Preserved boundaries

This repair changes no Asterisk, Kamailio, provider-signaling, timeout or
delayed-observation, failure-fidelity, RuntimeNode-placement, or historical
data behavior. No migration or backfill was performed. The known delayed
answer-observation race and the remaining V1-A closure concerns remain
deferred.

This note records a bounded repair; it does not declare V1 complete.
