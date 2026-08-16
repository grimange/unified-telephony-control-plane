# RH-2B — Replacement-Leg Binding Retry and Canonical Connected Gating

## Status

`RH_2_REPLACEMENT_LEG_BINDING_RETRY_AND_CONNECTED_GATING_FIXED_AND_TESTED`

The bounded RH-2B implementation is repository-tested. The natural browser
reproof remains pending; no live browser proof was performed in this packet.

## Challenge

The RH-2 natural proof reached a valid replacement `StasisStart`, but the first
canonical binder evaluation observed a transient RuntimeNode eligibility miss.
The old boolean binder contract returned false and no later work reconsidered
the same live channel. The browser also treated SIP.js `Established` as
`Connected` before canonical participant binding was visible, and explicit
Leave from `Recovering` could leave the view stranded in that state.

## Bounded correction

The Asterisk binding seam now returns `BOUND`, `ALREADY_BOUND`, `RETRYABLE`, or
`TERMINAL`. A retryable result schedules a unique, bounded delayed job carrying
the exact tenant, RuntimeNode, ARI channel, and `conf-<participant>` reference.
Each retry verifies the exact channel still exists and re-runs the existing
canonical participant, conference, session, binding, RuntimeNode, and readiness
predicates. Successful retry uses the existing binding and bridge attachment
path; no second INVITE, participant, or channel authority is introduced.

The recovery UI remains `Recovering` after SIP establishment until canonical
bootstrap confirms the same participation is active/bound. Terminal discovery
results tear down the local unbound recovery leg. Explicit Leave cancels and
fences recovery, performs the existing canonical removal, and restores the
normal registered/Ready controls without remounting.

## Tests

Focused Asterisk tests prove transient retry, exact-channel liveness stop,
listener scheduling, idempotent repeated execution, and existing stale-channel
fencing. Focused reference-dialer tests prove canonical Connected gating,
recovery failure retry, same-dialog preservation, explicit Leave cancellation,
and preservation of new-Join compensation. Broader repository verification is
reported with the implementation task; natural browser Scenarios 1–5 remain
the next proof.

## Preserved boundaries

The reconciler remains a state/reconciliation authority and does not enumerate
or discover inbound channels. Runtime readiness predicates remain intact. V0,
RT-1A, RH-1, the 120-second grace, Kamailio routing, the Asterisk dialplan, and
the participant authority are unchanged. RH-3 remains not implemented.

## Phase chain

```text
V0 COMPLETE
→ RT-1A COMPLETE / LIVE PROVEN
→ RH-0 COMPLETE
→ RH-1 IMPLEMENTED / TESTED
→ RH-2B IMPLEMENTED / TESTED
→ RH-2 natural browser Scenarios 1–5 pending
→ RH-3 not implemented
```
