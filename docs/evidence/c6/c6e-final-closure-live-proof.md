# C6E — Final Closure Natural Frontend Live Proof (seventh attempt)

Date: 2026-08-22

## Verdict

    C6E_ASTERISK_NATURAL_FRONTEND_LIVE_PROOF_FOUND_BLOCKER

**The entire C6 backend corridor is now live-proven, in both directions.** All
three defects from the previous packet are closed and confirmed against the
canonical runtime:

* existing managed Deployments converge through normal reconciliation, with no
  reprovisioning and no manual reconcile;
* outbound legs traverse `requested → originating → answered`, with the
  transition source explicitly recorded as `command-requested` then
  `observation-confirmed`;
* the inbound pre-adoption `answered` fact is retained and applied by
  exact-channel catch-up, so the adopted inbound leg reaches `answered`.

One concrete implementation defect remains, and it is confined to the reference
frontend: `CallLegResource` serializes `state` in **lowercase**, while
`CallConsoleView` gates the `Answer`, `Hold` and `Resume` controls — and
`isTerminal()` — on **uppercase** literals. Those three controls therefore never
render, and terminal legs still show `Hang up`. This blocks the two phases that
require them (Hold/Resume and inbound Answer), so the run cannot return a full
pass.

No production source was modified.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  C6 implementation + corrections present and uncommitted
    commit/push:   none created, not pushed

## Deployment

Canonical `make k8s-apply` only (config-check → image-build → image-push → apply
→ its own rollout restarts). **No separate manual application rollout.**

    API / WORKER / NORMALIZER / RECONCILER:
        utcp/api @sha256:2e8e5de2f2273d8ce2f9cd565a229278cdf645f8aeee99fb0acd19e481cb6bcd
    WEB:
        utcp/web @sha256:14183f40b5f509e07fd8698503f63c6692e714f5524b7da5007115caf7a6ddc5
    ASTERISK:
        utcp/asterisk-ari @sha256:06e3a9bed9e9d42f568367780662945440d52a868bb2e151013d82913a455a4f
    FRESH: YES

Cluster/context `utcp-local` / `k3d-utcp-local` via the repository-pinned
kubeconfig. `apntalk-local` untouched.

## Phase 2 — Reconciler Kubernetes Identity — PASSED

    SERVICE ACCOUNT:    utcp-runtime-fencer
    AUTOMOUNT:          false
    PROJECTED TOKEN:    present
                        serviceAccountToken expirationSeconds 3600, path token
                        + kube-root-ca.crt (ca.crt)
                        mounted at /var/run/secrets/kubernetes.io/serviceaccount
    API-CLIENT LABEL:   utcp.io/kubernetes-api-client="true"
    NETWORK POLICY:     matched by the existing
                        allow-runtime-fencer-kubernetes-api policy
    RBAC:               bounded existing runtime-fencer authority (unchanged)
    RECONCILER READY:   YES (1/1 Running, 0 restarts)
    CRASHLOOP:          NO
    API ACCESS:         YES — zero `unavailable_to_control` in the run

## Phase 3 — Existing Managed Node Convergence — PASSED

Proven on a **pre-existing** managed node. No node was provisioned for this
check.

    RUNTIME NODE:   rnp6-readiness-reproof-20260809 (c7e6f4ba-…)  active / ready
                    v0c6-conference-runtime-20260815             active / ready

    BEFORE: volumes (none)            mounts (none)
            dialplan: only  _[c]o[n]f-.  and  _.
    AFTER:  volumes asterisk-local-config
            mounts  asterisk-local-config
            dialplan (live, on the Pod):
              '9900'             Answer, Echo, Hangup
              'c6-generic-proof' NoOp, Answer,
                                 Stasis(utcp-t0-observation,c6-generic-proof),
                                 Hangup
              '_[c]o[n]f-.'      conference admission
              '_.'               Hangup(21)

    FIXTURE VOLUME:       present
    FIXTURE MOUNT:        present
    C6-GENERIC-PROOF:     present
    STASIS:               Stasis(utcp-t0-observation,c6-generic-proof)
    REPROVISION REQUIRED: NO
    MANUAL RECONCILE:     NO
    CONVERGENCE FAILURES: none

Both pre-existing managed Deployments converged automatically after deploy.

## Phase 4 — Natural Login — PASSED

    LOGIN PAGE:      https://app.utcp.local.test/login (real page, ordinary form)
    USER:            admin@utcp.local.test
    TENANT:          Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
                     (selected through the real Active-tenant control)
    C6 CAPABILITIES: 6 of 6
    SESSION BYPASS:  NO

## Phase 5-7 — Outbound Call, Command Lifecycle, Correlation — PASSED

Two outbound Calls were created through the Calls UI form, both on the
**pre-existing** managed node.

    CALL A:  dc275b24-77c9-48ef-9bc2-93ab6808ff5f
      LEG:   92102fbd-65af-484c-bcdf-681c6b66fab6
    CALL B:  cd4cc51c-0da2-4a0f-a336-8f15478e5dcf
      LEG:   ad27d5de-5ead-4663-9f05-c8814dd590d6

    RUNTIME NODE: c7e6f4ba-b925-462f-aff4-71c9fa9a4157
    DESTINATION:  sip:anonymous/sip:c6-generic-proof@127.0.0.1:5060
    ORIGINATE OPERATION: succeeded, attempt 1

    RESERVED: utcp-call-leg-<CallLeg ID>
    ASTERISK: same identity carried by the provider channel
    MATCH:    YES

### Phase 6 — the new command-lifecycle proof

    INITIAL STATE:            requested (leg inserted)
    COMMAND STATE:            originating
    TIME ORIGINATING WRITTEN: 2026-08-22 01:16:24Z
    SOURCE:                   command-requested

Audit evidence, verbatim:

    01:16:24  call.state_changed      {"from":"requested","to":"originating",
                                       "source":"command-requested"}
    01:16:24  call_leg.state_changed  {"from":"requested","to":"originating",
                                       "source":"command-requested"}

The leg did **not** remain `requested`. No `ringing` or `answered` appeared
before the corresponding observation existed.

## Phase 8 — Outbound Observation Lifecycle — PASSED

    01:16:27  call.leg.offered    subject ad27d5de-…  4deb931e6bc95d73335515e8bcde66f6
    01:16:27  call.leg.answered   subject ad27d5de-…  50f43ea63659635dae7adb07d3e01d0c
    01:16:27  call_leg.state_changed {"from":"originating","to":"answered",
                                      "source":"observation-confirmed"}
    01:16:27  call.state_changed     {"from":"originating","to":"answered",
                                      "source":"observation-confirmed"}

    CALL LEG BEFORE: originating
    CALL LEG AFTER:  answered   (answered_at 2026-08-22 01:16:27Z)
    CALL STATE:      answered
    UI STATE:        answered

`ANSWERED` was accepted from `ORIGINATING`. **No answered observation was
silently dropped.** The previous packet's Defect 1 is closed and live-proven.

## Phase 9 / 16 — Duplication and Adoption Counts — PASSED

Per real runtime channel, across every call in this run:

    utcp-call-leg-92102fbd-65af-484c-bcdf-681c6b66fab6   1 Call / 1 Leg  outbound
    utcp-call-leg-ad27d5de-5ead-4663-9f05-c8814dd590d6   1 Call / 1 Leg  outbound
    1787360957.1                                          1 Call / 1 Leg  inbound
    1787361386.3                                          1 Call / 1 Leg  inbound

    ACCIDENTAL INBOUND: 0
    DUPLICATE LEG:      0

The outbound `StasisStart` resolved the pre-existing outbound CallLeg by
deterministic channel id and never fell through to inbound adoption.

## Phase 14 — Inbound Early-Observation Catch-Up — PASSED

This run naturally reproduced the pre-adoption ordering, and the correction
handled it.

    ANSWERED OBSERVATION OCCURRED AT: 2026-08-22 01:09:17Z
                                      (subject runtime:1787360957.1,
                                       a9883b27… / 1b5b70c5… equivalents)
    OFFERED OBSERVATION OCCURRED AT:  2026-08-22 01:09:18Z
    CALL ADOPTED AT:                  2026-08-22 01:09:18Z
    CATCH-UP APPLIED:                 YES
    FINAL STATE:                      answered (answered_at 01:09:22Z)

The `answered` fact arrived **one second before** the channel had any CallLeg,
was retained rather than discarded, and was applied by exact-channel catch-up
immediately after adoption. No timestamps were rewritten and no provider timing
was manipulated.

The second inbound call reproduced the same result through the normal path:

    01:16:30  call_leg.state_changed {"from":"offered","to":"answered",
                                      "source":"observation-confirmed"}

The previous packet's Defect 2 is closed and live-proven.

## Phase 15 — Inbound Adoption — PASSED

    SOURCE:          real local SIP — the managed node's own PJSIP loopback
                     INVITE to c6-generic-proof@127.0.0.1:5060
    ASTERISK CHANNEL: 1787360957.1 / 1787361386.3 (Asterisk-generated uniqueids)
    CALL:            d47d4713-3aae-4cd0-8e93-2455e507cb39
                     e237aaa3-2f51-4211-972b-5a05eee609cc
    LEG:             d7aaf3ef-3a74-4ab4-91e5-a13f12bf15a1
                     7ae09b38-dc50-4945-9d94-68447be3560e
    DIRECTION:       inbound
    CHANNEL MATCH:   YES
    C7 USED:         NO

## Phase 10 — Command vs Observation — PARTIALLY PROVEN

The decisive assertion is proven directly by the canonical audit trail, which
labels the authority of every transition:

    command-requested     requested   → originating
    observation-confirmed originating → answered
    observation-confirmed offered     → answered   (inbound)

Provider-confirmed lifecycle state is only ever written from a normalized
`runtime_observation`; RuntimeOperation success never advanced state on its own.

**Hold/Resume could not be exercised**, and inbound `Answer` — the prompt's
designated fallback — could not either, both for the same reason (see the
blocking defect below). A positive Hold → `call.leg.held` observation → canonical
`HELD` chain therefore remains unproven live.

## Phase 11 — DTMF — PASSED, with a genuine received-DTMF bonus

    DIGIT:            5
    RUNTIME OPERATION: call.leg.send_dtmf → succeeded, attempt 1
    ASTERISK ACTION:  ARI channels/{id}/dtmf
    RESULT:           succeeded

The peer leg then genuinely received it, and it was normalized as a real
provider fact rather than inferred from send success:

    01:16:51  call.leg.dtmf_received  subject 7ae09b38-… (the inbound leg)
              761a5ff85e1e48c6e3e58c5979ab399b

Outbound send success was **not** treated as received DTMF; the received
observation exists independently, on the other leg.

## Phase 12 — Outbound Terminal — PASSED

Driven from the actual frontend `Hang up` control.

    OPERATION: call.leg.hangup → succeeded, attempt 1 (01:16:52Z)
    CHANNEL:   utcp-call-leg-ad27d5de-5ead-4663-9f05-c8814dd590d6
    ARI FACT:  channel destroyed on the exact deterministic channel
    OBSERVATION: call.leg.terminated  01:16:54Z
                 e3477cbe0d9861864c6c234e0e6bcbbc / 71e9e1f136ac46425d6d5cb2a039fcd7
    LEG TERMINAL:  completed (terminated_at 01:17:01Z)
    CALL TERMINAL: completed
    UI:            completed / Termination runtime_lost

Terminal state was applied from the provider-confirmed observation, not from
operation success. `termination_reason` renders as `runtime_lost`, the
normalizer's fallback when the ARI payload carries no explicit cause — a
cosmetic labelling gap, not a lifecycle error.

## Phase 13 — Timeline UI — PASSED

The reference timeline rendered all three kinds, correctly separated and
correlated:

    AUDIT              audit.call.terminated        09:17:01
    AUDIT              audit.call_leg.terminated    09:17:01
    RUNTIME_OBSERVATION call.leg.terminated         09:16:54
    RUNTIME_OBSERVATION call.leg.terminated         09:16:54
    RUNTIME_OPERATION  operation.succeeded  call.leg.hangup     09:16:52
    RUNTIME_OPERATION  operation.succeeded  call.leg.send_dtmf  09:16:48
    AUDIT              audit.call_leg.state_changed 09:16:27
    AUDIT              audit.call.state_changed     09:16:27
    RUNTIME_OBSERVATION call.leg.answered           09:16:27
    RUNTIME_OBSERVATION call.leg.offered            09:16:27
    AUDIT              audit.call_leg.state_changed 09:16:24
    AUDIT              audit.call.state_changed     09:16:24
    AUDIT              audit.call.created           09:16:24
    RUNTIME_OPERATION  operation.succeeded  call.leg.originate  09:16:24

    RAW ARI:         NO
    SECRETS:         NO
    LEASE INTERNALS: NO

Originate command, provider lifecycle observation, representative control and
terminal observation are all present and distinguishable.

## Blocking Defect — Call state casing mismatch between API and reference UI

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking for Hold/Resume and inbound Answer; also mis-renders
              terminal legs
    FILES:    apps/api/app/Http/Resources/Telephony/CallLegResource.php:11
              apps/api/app/Http/Resources/Telephony/CallResource.php:11
              apps/web/src/views/CallConsoleView.vue:199, 208, 217, 380-382

The API emits the raw lowercase enum value:

    'state' => $this->observed_state          // "answered", "offered", "completed"

Confirmed live from inside the authenticated session:

    GET /api/v1/calls/{id}  ->  { "state": "completed", … }

The reference UI compares against uppercase literals:

    v-if="leg.state === 'OFFERED'"    // Answer  — never renders
    v-if="leg.state === 'ANSWERED'"   // Hold    — never renders
    v-if="leg.state === 'HELD'"       // Resume  — never renders

    function isTerminal(value: string): boolean {
      return ['COMPLETED', 'FAILED', 'CANCELLED'].includes(value)   // never true
    }

Observed consequences during the run:

* with the leg genuinely `answered`, the control row rendered only
  `Hang up | Send DTMF` — no `Hold`;
* a `completed` leg still rendered `Hang up`, because `isTerminal()` returned
  false.

`pendingOperation()` at `:384-388` is the tell: it explicitly lists **both**
casings (`'REQUESTED', … , 'requested', …`), which is why the
`Cancel origination` / `Hang up` distinction behaved correctly while the
sibling controls did not. One helper was written case-tolerantly and the others
were not.

    EXPECTED: with CallLeg.observed_state = answered, the UI offers Hold;
              after Hold, canonical HELD arrives from a call.leg.held observation.
    ACTUAL:   the Hold control is never rendered, so the operation cannot be
              issued from the frontend at all.
    CALL:            cd4cc51c-0da2-4a0f-a336-8f15478e5dcf
    CALL LEG:        ad27d5de-5ead-4663-9f05-c8814dd590d6
    RUNTIME NODE:    c7e6f4ba-b925-462f-aff4-71c9fa9a4157
    RUNTIME CHANNEL: utcp-call-leg-ad27d5de-5ead-4663-9f05-c8814dd590d6
    ASTERISK FACT:   channel Up, in Stasis(utcp-t0-observation)
    OBSERVATION:     call.leg.answered present and applied
    CANONICAL STATE: answered
    UI STATE:        answered, but no Hold/Resume/Answer control rendered

The backend operation itself is not implicated: `call.leg.send_dtmf` and
`call.leg.hangup` both succeeded through the same submit path, so only the
render guards are wrong.

## Fixture Characteristic (recorded, not a defect)

Nothing controls the Stasis channel, so the abandoned dialog is reclaimed by
`rtp_timeout=30` roughly 30 s after answer. That is the working window for any
UI control on a proof call. It bounded the run's pacing but did not cause the
blocking defect.

## Phase 18 — Reconciler Stability — PASSED

    telephony-reconciler   1/1 Running   0 restarts   12 min
    unavailable_to_control occurrences: 0
    fencing-token CrashLoop:            none

Natural stale-claim supersessions occurred and were handled safely:

    01:18:12  runtime reconciliation claim superseded during evaluation
              result=superseded target_type=conference_participant
              target_id=c21db45a-8a18-40e6-8171-647cfb16bbee
    01:18:12  … target_id=e467283a-4ecc-4d9f-a8bc-9a7586255518

The claim was skipped and the worker survived. No race was manufactured.

## Conference Isolation

Not disturbed. No conference created, no participant admitted, no
conference-owned channel controlled. Naturally occurring
`conference_participant` reconciliation claims were observed being skipped
safely, and no conference channel appeared as a generic C6 Call. All four
Asterisk Pods ended with 0 active channels. No RH change.

## Security / Authority

    DIRECT ARI CONTROL:       NO
    DB MUTATION:              NO
    SESSION INJECTION:        NO
    OBSERVATION INJECTION:    NO
    FEATURE GATE:             NO
    MANUAL RECONCILE:         NO
    MANUAL DEPLOYMENT PATCH:  NO
    MANUAL CAPABILITY REPAIR: NO
    APNTALK TOUCHED:          NO
    SOURCE PATCHED:           NO

Read-only corroboration only: `dialplan show`, `core show channels`,
`kubectl get -o jsonpath`, and `psql` SELECTs.

## Failed Proof Steps

    Phase 10  Hold / Resume            — blocked by the casing defect
    Phase 17  Inbound Answer           — blocked by the same defect
              (the designated fallback for Phase 10)

Every other phase passed.

## Cleanup

    Non-terminal proof Calls: 0
    Stray Asterisk channels:  0 on all four Pods
    Reconciler:               1/1 Running, healthy
    RuntimeNodes:             rnp6-readiness-reproof-20260809  active / ready
                              v0c6-conference-runtime-20260815 active / ready
                              c6e-final-proof-20260822         active / ready
    Session:                  logged out through the normal UI

The final outbound Call was terminated through the frontend `Hang up` control;
all other proof Calls reached terminal state naturally. Historical Call and
timeline evidence was preserved.

## Repository Verification

    git diff --check          clean
    make repository-hygiene   passed
    make secret-scan          passed

## Code Changes

    NONE.

## Recommended Bounded Correction

**One change, one file.** Normalize the comparison in `CallConsoleView.vue`:
uppercase (or lowercase) `leg.state` / `call.state` once before comparing, so
`Answer`, `Hold`, `Resume` and `isTerminal()` behave like the already
case-tolerant `pendingOperation()`. Alternatively serialize the state uppercase
in `CallLegResource`/`CallResource` — but the UI already tolerates both casings
in one helper, so normalizing in the view is the smaller and safer edit.

Add a component test asserting that a leg with `state: 'answered'` renders
`Hold`, that `state: 'offered'` renders `Answer`, and that `state: 'completed'`
renders neither `Hang up` nor the DTMF row.

Then re-run only Phase 10 and Phase 17; every other phase is already proven and
need not be repeated.

## C6 Status

    C6A-D:                        IMPLEMENTED / TESTED
    C6E IMPLEMENTATION:           IMPLEMENTED / TESTED
    REFERENCE CALL UI:            IMPLEMENTED / PARTIALLY LIVE PROVEN
    MANAGED CONVERGENCE:          LIVE PROVEN (existing node, no reprovision)
    RECONCILER K8S IDENTITY:      LIVE PROVEN
    RECONCILER FENCING:           LIVE PROVEN (skip, worker survives)
    DETERMINISTIC CHANNEL ID:     LIVE PROVEN
    OUTBOUND COMMAND LIFECYCLE:   LIVE PROVEN (requested → originating)
    OUTBOUND OBSERVATION LIFECYCLE: LIVE PROVEN (originating → answered)
    OBSERVATION PIPELINE:         LIVE PROVEN
    INBOUND ADOPTION:             LIVE PROVEN (1 Call / 1 Leg)
    INBOUND EARLY-OBSERVATION CATCH-UP: LIVE PROVEN
    DTMF SEND + RECEIVED:         LIVE PROVEN
    TERMINAL PROOF:               LIVE PROVEN (frontend Hang up)
    TIMELINE UI:                  LIVE PROVEN
    HOLD / RESUME / INBOUND ANSWER: BLOCKED (UI casing defect)
    C6E LIVE:                     FOUND_BLOCKER

    C6:                           NOT LIVE PROVEN

## Recommended Next Step

    BOUNDED CODEX CORRECTION — the single casing fix, then re-run only
    Phase 10 and Phase 17.

T4 must not start until that narrow reproof passes. No further C6 audit is
required; the defect is exact and one-file localized.
