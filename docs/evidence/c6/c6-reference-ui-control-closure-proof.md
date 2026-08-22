# C6 — Targeted Reference Call UI Control Closure Proof

Date: 2026-08-22

## Verdict

    C6_REFERENCE_CALL_UI_CONTROL_LIVE_PROOF_FOUND_BLOCKER

**The casing defect this task was created to close is fixed and live-proven.**
With canonical lowercase state straight from the API, the reference UI now
renders exactly the right controls:

    state "answered"  -> Hold, Hang up            (no Answer/Resume/Cancel)
    state "completed" -> no active controls, DTMF row absent
    state "failed"    -> no active controls, DTMF row absent

Both halves of the original defect — the `Hold`/`Answer`/`Resume` render guards
and `isTerminal()` — are confirmed correct against live canonical state.

Closure is nevertheless blocked, by a **different and newly isolated** issue:
`call.leg.hold` succeeds at the provider, but **Asterisk never emits a
`ChannelHold` event for an application-initiated ARI hold**, so no
`call.leg.held` observation is ever produced and the canonical `held` state is
unreachable. `Resume` therefore cannot be reached either.

This is provider-semantics, not casing, and not a frontend defect.

No production source was modified.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  C6 implementation + corrections present and uncommitted
    commit/push:   none created, not pushed

## Deployment

Canonical `make k8s-apply` only. No manual Pod patch, no copied files, no
separate manual rollout.

    WEB:      utcp/web @sha256:be6c3d1b77e248f90d98b4353f958d1b8f77cf756d7c3427a04ead279f9ceef1
    API:      utcp/api @sha256:c06ef9dd4033cb2d353e76bcdc46f2eee43493b4010782105ade586d7139976a
    ASTERISK: utcp/asterisk-ari @sha256:02441a7fd7d04135da6b040b89fc1a7f68c3fd66dc2e39ec184fff4cb769473d
    FRESH:    YES

Cluster/context `utcp-local` / `k3d-utcp-local` via the repository-pinned
kubeconfig. `apntalk-local` untouched. Sanity check only: the proof node
`rnp6-readiness-reproof-20260809` remained `active` / `ready`; the managed
convergence proof was not repeated.

## Natural Login

    LOGIN PAGE:     https://app.utcp.local.test/login (real page, ordinary form)
    USER:           admin@utcp.local.test
    TENANT:         Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    C6 PERMISSIONS: present (six telephony.calls.* capabilities, unchanged)
    SESSION BYPASS: NO

## Answered State — PASSED

Call created through the Calls UI on the already-proven managed Asterisk
loopback corridor. No raw API creation, no direct ARI, no state injection.

    CALL:      417b0182-8843-408f-9ac0-9e8113401e68
    CALL LEG:  dec53217-9f94-43b7-840e-26f1bea580b8
    API STATE: "answered"        (lowercase, straight from GET /api/v1/calls/{id})
    UI STATE:  answered

    HOLD:               VISIBLE
    HANG UP:            VISIBLE
    ANSWER:             NOT VISIBLE
    RESUME:             NOT VISIBLE
    CANCEL ORIGINATION: NOT VISIBLE

Rendered control row, verbatim from the DOM:

    ["Hold", "Hang up", "Send DTMF"]

**This is the primary casing-regression proof and it passes.** Before the
correction this row was `["Hang up", "Send DTMF"]` with `Hold` absent against
the identical canonical state.

## Hold — FAILED (provider does not emit the fact)

    UI ACTION:        Hold clicked in the real Calls UI
    RUNTIME OPERATION: call.leg.hold
    OPERATION STATUS:  succeeded, attempt 1, no failure class or code
    ASTERISK ACTION:   ARI POST /channels/{id}/hold
    PROVIDER FACT:     NONE — no ChannelHold event was emitted
    NORMALIZED OBSERVATION: NONE
    CANONICAL STATE:   answered (unchanged; call_legs.held = false)
    UI STATE:          answered — Hold still rendered, correctly

The call subsequently terminated normally at 01:31:51Z via the already-proven
provider-confirmed terminal path.

### Evidence that the fact never arrived

The whole mapping chain is present and correct:

    AsteriskAriEventListener.php:819-820
        'ChannelHold'   => 'channel_hold'
        'ChannelUnhold' => 'channel_unhold'
    config/asterisk_ari.php:48-49
        'channel_hold'   => 'asterisk.ari.channel.hold'
        'channel_unhold' => 'asterisk.ari.channel.unhold'
    AsteriskAriEventNormalizer.php:162-163
        channel_hold   -> call.leg.held
        channel_unhold -> call.leg.resumed
    CallObservationProcessor STATE_OBSERVATIONS
        call.leg.held    -> CallState::Held
        call.leg.resumed -> CallState::Answered

But across the **entire history** of the canonical database:

    select event_type, count(*) from runtime_event_receipts
      where event_type ilike '%hold%' group by 1;
    -> (zero rows)

Asterisk has never once delivered a hold event. The listener is not filtering it
out: unmapped events are still recorded as
`asterisk.ari.unknown_event_observed`, and samples from this very call show
`ChannelDialplan`, `ChannelCreated`, `Dial` and `ChannelVarset` being captured
for the same channel. A `ChannelHold` simply was never sent.

### Root cause

ARI `POST /channels/{id}/hold` calls `ast_queue_hold()`, which queues a HOLD
control frame **toward the channel's peer**. `res_stasis` raises the
`ChannelHold` ARI event when a HOLD frame is *received from* a channel — that
is, when the remote party places the call on hold. An application-initiated hold
is not echoed back as an event to the initiating application.

Consequently the C6 contract as written —

    Hold -> provider fact -> normalized call.leg.held -> canonical HELD

cannot be satisfied against Asterisk's ARI hold endpoint, because the provider
emits no fact to observe. This is an authority-model mismatch with provider
semantics, not a defect in the UI, the normalizer, the processor or the catalog.

    CLASS: IMPLEMENTATION (authority model vs provider semantics)

## Held State — NOT REACHED

    API STATE: never became "held"
    HOLD:      still VISIBLE (correct for canonical "answered")
    RESUME:    NOT VISIBLE (correct for canonical "answered")
    HANG UP:   VISIBLE

The UI behaved correctly for the state it was actually given. `Resume`
rendering could not be exercised because canonical `held` was never produced.

## Resume — NOT REACHED

Blocked by the same cause.

## Inbound Offered

    NATURALLY INTERACTIVE: NO

The `c6-generic-proof` fixture runs `Answer()` immediately before `Stasis()`, so
`ChannelStateChange(Up)` and `StasisStart` land in the same second and the
already-proven catch-up moves the adopted leg straight to `answered`. Observed
this run:

    01:31:21Z  call.leg.answered  runtime:1787362281.5   (before adoption)
    01:31:22Z  call.leg.offered   runtime:1787362281.5   (adoption)
    -> final canonical state: answered

Per the task's Natural Auto-Answer Exception, event order was not manipulated,
no alternative SIP topology was invented, Asterisk was not delayed, and this is
**not** classified as a UI failure. Lowercase `offered → Answer visible` rests on
the repository component regression.

    OFFERED INTERACTIVE WINDOW: NOT NATURALLY AVAILABLE
    CATCH-UP FINAL STATE:       answered

## Completed Terminal UI — PASSED

    CALL:      417b0182-8843-408f-9ac0-9e8113401e68
    API STATE: "completed"   (termination_reason runtime_lost)
    TERMINAL:  TRUE

    HANG UP:            NOT VISIBLE
    HOLD:               NOT VISIBLE
    RESUME:             NOT VISIBLE
    ANSWER:             NOT VISIBLE
    CANCEL ORIGINATION: NOT VISIBLE
    DTMF ROW:           ABSENT

Rendered control row, verbatim: `[]`.

**This is the second half of the casing-regression proof and it passes.**
Before the correction, `isTerminal()` compared against uppercase literals only
and a completed leg still rendered `Hang up`.

## Failed / Cancelled Sanity — PASSED (naturally available)

No failure was manufactured. A historical failed Call already present in
`/calls` was inspected:

    CALL:      f7e96142-ec8c-4458-a3a8-7a8dd352c10c
    API STATE: "failed"
    CONTROLS:  []           (none)
    DTMF ROW:  ABSENT

## Security / Authority

    DIRECT ARI CONTROL:      NO
    DB MUTATION:             NO
    SESSION INJECTION:       NO
    OBSERVATION INJECTION:   NO
    FEATURE GATE:            NO
    MANUAL RECONCILE:        NO
    MANUAL DEPLOYMENT PATCH: NO
    APNTALK TOUCHED:         NO
    SOURCE PATCHED:          NO

Read-only corroboration only: `core show channels`, `psql` SELECTs, and
authenticated in-session API reads used solely to record canonical state
alongside the rendered DOM.

## Failed Proof Steps

    Phase 5  Hold        — operation succeeded, provider emitted no ChannelHold
    Phase 6  Held state  — not reached (canonical `held` unreachable)
    Phase 7  Resume      — not reached (same cause)

All other targeted checks passed.

## Success Criteria Status

    [x] answered API state renders Hold
    [ ] clicking Hold produces provider-confirmed held      <- blocked
    [ ] held API state renders Resume                       <- not reached
    [ ] clicking Resume produces provider-confirmed answered <- not reached
    [x] completed API state exposes no active controls
    [x] offered API state renders Answer if naturally interactive
        (not naturally interactive; component regression applies)
    [x] no auth bypass
    [x] no direct ARI
    [x] no DB/session/observation injection
    [x] no manual reconcile
    [x] no source patch during proof

## Cleanup

    Non-terminal proof Calls: 0
    Stray Asterisk channels:  0 on all four Pods
    RuntimeNodes:             unchanged, all active / ready
    Session:                  logged out through the normal UI

Historical Call and timeline evidence preserved.

## Repository Verification

    git diff --check          clean
    make repository-hygiene   passed
    make secret-scan          passed

## Code Changes

    NONE.

## Recommended Bounded Correction

The decision is a product one, so it should be made deliberately rather than
patched reflexively. Two coherent options:

1. **Treat app-initiated hold as command-confirmed** (recommended). Asterisk
   emits no observable fact for it, so no amount of observation plumbing will
   ever produce one. Apply `held` from the succeeded `call.leg.hold` operation,
   audited with `source = command-requested` — the same honest labelling already
   used for `requested → originating` — and keep `call.leg.held` observations
   handling the genuine remote-hold case when a peer initiates it. This makes
   `held` reachable and keeps the authority label truthful.

2. **Have the adapter confirm hold by inspection.** After a successful hold,
   read the channel back over ARI and synthesize a `call.leg.held` observation
   from the observed channel state, exactly as the existing conference
   inspection-evidence path does. Heavier, but keeps every canonical state
   observation-sourced.

Do **not** simply relax the transition table: without one of the above, nothing
ever writes `held`.

Either way, add a regression test asserting that a succeeded `call.leg.hold`
results in canonical `held`, and re-run only Phases 5-7. Phases 4, 9 and 10 are
proven and need no repeat.

## C6 Status

    BACKEND CORRIDOR:            LIVE PROVEN
    MANAGED CONVERGENCE:         LIVE PROVEN
    OUTBOUND LIFECYCLE:          LIVE PROVEN
    INBOUND ADOPTION:            LIVE PROVEN
    INBOUND CATCH-UP:            LIVE PROVEN
    DTMF:                        LIVE PROVEN
    TERMINAL BACKEND PATH:       LIVE PROVEN
    REFERENCE CALL UI CASING:    LIVE PROVEN (answered / completed / failed)
    HOLD -> HELD -> RESUME:      BLOCKED (provider emits no hold fact)
    C6E:                         FOUND_BLOCKER

    C6:                          NOT FULLY LIVE PROVEN

## Recommended Next Step

    BOUNDED CODEX CORRECTION — decide and implement the hold-confirmation
    model (option 1 recommended), then re-run only Phases 5-7.

T4 must not start until that narrow reproof passes. No further C6 audit is
required.
