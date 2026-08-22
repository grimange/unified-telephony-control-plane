# C6E — Observation Corridor Live Reproof (sixth attempt)

Date: 2026-08-22

## Verdict

    C6E_ASTERISK_NATURAL_FRONTEND_LIVE_PROOF_FOUND_BLOCKER

**The observation corridor is now alive.** The blocking normalizer defect is
closed: generic `StasisStart` events normalize on PostgreSQL, `call.leg.*`
observations are written, and **inbound adoption works end to end** — a real
local SIP INVITE reached `c6-generic-proof`, entered Stasis, and produced exactly
one inbound Call and one CallLeg bound to the real Asterisk channel.

Three exact defects remain, all newly isolated:

1. **Outbound legs can never reach a provider lifecycle state.** `requested` only
   transitions to `selecting_route` or `originating`, and **no code ever writes
   either**, so `call.leg.answered` is rejected and the leg is stuck at
   `requested` until terminalization.
2. **Simultaneous `answered` is dropped on inbound adoption.** `StasisStart` and
   `ChannelStateChange(Up)` arrive in the same second; the `answered` observation
   is processed before adoption creates the leg, resolves to nothing, and is
   discarded with no replay. The inbound leg is stranded at `offered`.
3. **Existing managed Deployments still do not converge** — the reconciler has no
   Kubernetes API identity or network path, so every convergence attempt fails
   `unavailable_to_control`.

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
        utcp/api @sha256:c5534140d08eed47c5c31119df469aa996ba5986361453fd26780adc9a429113
    WEB:
        utcp/web @sha256:902f300a6769bffd0886a6f4009d1e59dc91a892e2566d0b09669c18471b8360
    ASTERISK:
        utcp/asterisk-ari @sha256:06e3a9bed9e9d42f568367780662945440d52a868bb2e151013d82913a455a4f
    MIGRATION:        utcp-migrate Complete 1/1 (7 s)
    DEPLOYMENT FRESH: YES

Cluster/context `utcp-local` / `k3d-utcp-local` via the repository-pinned
kubeconfig. `apntalk-local` untouched.

## Phase 2 — Existing Managed Node Convergence — FAILED

    RUNTIME NODE:          rnp6-readiness-reproof-20260809 (active/ready)
                           v0c6-conference-runtime-20260815 (active/ready)
    FIXTURE VOLUME:        ABSENT
    FIXTURE MOUNT:         ABSENT
    C6-GENERIC-PROOF:      ABSENT
    REPROVISION REQUIRED:  (would be) YES
    MANUAL RECONCILE:      NO (none performed)

    CLASS: IMPLEMENTATION

Desired vs actual, pre-existing managed Deployments:

    desired (desiredDeployment())
      volumes:      [{name: asterisk-local-config,
                      configMap: {name: asterisk-local-sip-fixtures, optional: true}}]
      volumeMounts: [{name: asterisk-local-config,
                      mountPath: /opt/utcp-asterisk-local-config, readOnly: true}]
    actual (asterisk-rnp6-…, asterisk-v0c6-…)
      volumes:      (none)
      volumeMounts: (none)

    actual dialplan (rnp6): only  _[c]o[n]f-.  and  _.
    actual dialplan (c6e-final-proof, provisioned by the corrected handler):
      9900, c6-generic-proof, _[c]o[n]f-., _.

### Root cause

`AsteriskRuntimeNodeReconciler::convergeManagedDeployment()` runs on every
managed-node evaluation and calls the shared `desiredDeployment()`, so the
authority wiring is correct. It fails at the Kubernetes call:

    managed Asterisk Deployment convergence failed
      runtime_node_id 3488f30f-…  reason unavailable_to_control
      runtime_node_id c7e6f4ba-…  reason unavailable_to_control
      runtime_node_id d4539d79-…  reason unavailable_to_control

`unavailable_to_control` is `KubernetesWorkloadClientException::unavailable()`.
The reconciler has neither the credentials nor the network path:

    telephony-reconciler
      serviceAccountName:          utcp-platform-app
      automountServiceAccountToken: false
      volumes:                     (none)  -> no serviceAccountToken, no CA
      labels:                      component=telephony-reconciler,
                                   part-of=utcp, network-role=worker
                                   (no utcp.io/kubernetes-api-client)

    utcp-runtime-fence-worker   (the working comparison)
      serviceAccountName:          utcp-runtime-fencer
      labels:                      … utcp.io/kubernetes-api-client: "true"
      volumes: [{name: kubernetes-api-credentials, projected: {sources: [
                 {serviceAccountToken: {expirationSeconds: 3600, path: token}},
                 {configMap: {name: kube-root-ca.crt, items:[ca.crt]}}]}}]

The NetworkPolicy `allow-runtime-fencer-kubernetes-api` selects
`utcp.io/kubernetes-api-client: "true"`, which the reconciler does not carry, so
apiserver egress is denied by `default-deny` as well.

Because convergence fails first (reconciler lines 58-61), managed nodes return
`waiting('managed_deployment_convergence_failed', 30)` and never reach the rest
of `evaluate()`. All three managed Asterisk targets sit in a permanent 30 s
waiting loop.

**No node was reprovisioned to bypass this.** The proof continued on
`c6e-final-proof-20260822`, which already existed and was retained as evidence
from the previous packet.

## Phase 3 — Reconciler Health — PASSED

    RECONCILER READY:        YES  (1/1 Running, stable 15 min)
    CRASHLOOP:               NO
    FENCING LOSS OBSERVED:   YES — naturally, not manufactured
    WORKER SURVIVED:         YES

Observed live:

    runtime reconciliation claim superseded during evaluation
      result=superseded  target_type=conference_participant
      target_id=4f69a526-99f8-4b3c-af5d-c0af8c750386

The worker logged, skipped the claim, and continued processing. The previous
packet's uncaught-exception crash-loop is **closed and live-proven**.

## Phase 4 — Runtime Catalog — PASSED

    BACKEND TRANSPORTS: ["http","https","tcp","tls","udp","ws","wss"]
    BACKEND TLS MODES:  ["disabled","opportunistic","required","verify"]
    FRONTEND SELECT:    http="http" https="https" tcp="tcp" tls="tls"
                        udp="udp" ws="ws" wss="wss"
    NUMERIC OPTIONS:    NONE

## Phase 5 — Natural Login — PASSED

    LOGIN PAGE:      https://app.utcp.local.test/login (real page, ordinary form)
    USER:            admin@utcp.local.test
    TENANT:          Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    C6 CAPABILITIES: 6 of 6
    SESSION BYPASS:  NO

## Phase 6-8 — Outbound Call, Correlation, Conference Guard — PASSED

Two outbound Calls were created through the Calls UI form.

    CALL 1:  c958d361-ac5a-4eb8-a46e-4e4fbbafd0e6
      LEG:   7070dc33-52b4-45e0-bd05-49ac73937856
      CHAN:  utcp-call-leg-7070dc33-52b4-45e0-bd05-49ac73937856
    CALL 2:  6ca23e07-5cbf-4766-bbcb-1ff267b50e11
      LEG:   09b7c9c1-2fae-4e68-927f-87d2e77b85dd
      CHAN:  utcp-call-leg-09b7c9c1-2fae-4e68-927f-87d2e77b85dd

    RUNTIME NODE:        3488f30f-bdf8-4a2a-b2f9-e865b0c625d0
    DESTINATION:         sip:anonymous/sip:c6-generic-proof@127.0.0.1:5060
    ORIGINATE OPERATION: succeeded, attempt 1

    RESERVED CHANNEL:  utcp-call-leg-<CallLeg ID>
    MATCH:             YES
    STATE FABRICATED:  NO

    SQLSTATE[42703]:                 NO
    NORMALIZATION FAILURE:           NO
    GENERIC CLASSIFIED AS CONFERENCE: NO

`AsteriskConferenceChannelOwnership::owns()` is now the single call site from
`AsteriskRuntimeAdapter:125` and `AsteriskAriEventNormalizer:120,:230`. Both the
command path and the normalizer executed cleanly. The previous packet's blocking
normalizer defect is **closed and live-proven**.

## Phase 9 — Observation Corridor — PARTIALLY PASSED

Observations actually written (Call 1):

    00:14:39  call.leg.offered     subject 7070dc33-…  offered
              d92fd1419e3bc2b1ed9cabeec9d9f86d
    00:14:39  call.leg.answered    subject 7070dc33-…  observed
              a5cc42ef3d83e398c129d09c12483e66
    00:14:39  call.leg.answered    subject runtime:1787357679.3  observed
              1b5b70c5fcf1844a60c1522bbdac99aa
    00:14:40  call.leg.offered     subject runtime:1787357679.3  offered
              2e691ad5ce150c4ba82ec72d6fa7e2ad
    00:15:09  call.leg.terminated  subject 7070dc33-…  observed
              2f9c2c4fe5cf03b8a3bd166354c97cd0 / d1f31f3e112ea4aaa3830ef8f4000378
    00:15:09  call.leg.terminated  subject 91bf5f16-…  observed
              127ab943517b854c936b43a5ce81b718 / 55664366adb46fb31238253405fa1f73

What the corridor **does** apply:

* `call.leg.offered` on an existing outbound leg → `bindObservedRuntimeChannel`
  (resolves the pre-existing leg; does **not** create an inbound Call)
* `call.leg.offered` on an unknown channel → `adoptInboundLeg` → inbound Call
* `call.leg.terminated` → `terminalizeObservedLeg` → `completed`

What it **does not** apply: `call.leg.answered`. Final canonical state for all
four Calls:

    outbound  completed   answered_at NULL
    inbound   completed   answered_at NULL

No leg ever displayed `answered`, `ringing` or `early_media`.

## Blocking Defect 1 — outbound legs cannot leave `requested`

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking — no outbound provider lifecycle state is representable
    FILE:     apps/api/app/TelephonyDomain/CallDomainService.php:33-45, :241-251

    private const LEG_TRANSITIONS = [
        'requested'   => ['selecting_route', 'originating'],
        'selecting_route' => ['originating'],
        'originating' => ['ringing', 'early_media', 'answered', 'terminating'],
        …
    ];

`applyObservedLegTransition()` rejects any transition not listed for the current
state. An outbound CallLeg is created in `requested`, and

    grep -rn "'originating'|Originating|'selecting_route'|SelectingRoute"
      apps/api/app/TelephonyDomain/

matches **only** the enum declaration, the transition table itself, and one read
guard at `CallDomainService.php:121`. **No code path ever writes `originating`
or `selecting_route`.**

Therefore `requested → answered` is never legal, `applyObservedLegTransition()`
returns `false`, and the observation is silently discarded. The leg remains
`requested` until `terminalizeObservedLeg()` — which bypasses `LEG_TRANSITIONS`
entirely — jumps it to `completed`.

Consequence: `Hold`, `Resume`, `DTMF` and the UI `Hang up` control are
unreachable for outbound calls, because the reference UI gates them on an
answered/active leg and instead keeps showing `Cancel origination`.

## Blocking Defect 2 — simultaneous `answered` dropped during inbound adoption

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking for inbound Answer
    FILE:     apps/api/app/TelephonyDomain/CallObservationProcessor.php:67-70

Because the fixture runs `Answer()` immediately before `Stasis()`, Asterisk emits
`StasisStart` and `ChannelStateChange(Up)` within the same second. Observed
ordering for the inbound channel:

    00:14:39  call.leg.answered  runtime:1787357679.3   <-- arrives FIRST
    00:14:40  call.leg.offered   runtime:1787357679.3   <-- adoption happens here

At `answered` time no CallLeg exists for that channel yet, so

    $legId = $this->resolveLegId(...);
    if ($legId === null || $channelId === null) { return; }

discards the observation. Adoption then creates the leg at `offered`, and no
further `answered` event is ever emitted. The inbound leg is stranded at
`offered` with `answered_at` NULL.

There is no replay or deferral for observations that arrive before their
subject exists.

## Phase 10 / 16 — Duplication and Adoption Counts — PASSED

Per real runtime channel:

    1787357679.3                                   1 Call / 1 Leg  inbound
    1787357772.5                                   1 Call / 1 Leg  inbound
    utcp-call-leg-7070dc33-52b4-45e0-bd05-49ac73937856  1 Call / 1 Leg  outbound
    utcp-call-leg-09b7c9c1-2fae-4e68-927f-87d2e77b85dd  1 Call / 1 Leg  outbound

    ACCIDENTAL INBOUND CALL FROM THE OUTBOUND CHANNEL: 0
    ACCIDENTAL SECOND CALL LEG:                        0

The outbound `StasisStart` resolved the **existing** outbound CallLeg via the
deterministic channel id and did not fall through to inbound adoption.

## Phase 11-13 — Command vs Observation, DTMF, Hangup — NOT REACHED

Blocked by Defect 1. The UI never offered `Hold`; it showed `Cancel origination`
throughout because the canonical leg state stayed `requested`. No representative
control could be exercised, so command-versus-observation authority could not be
demonstrated positively in this run.

The negative half still held: `call.leg.originate` reported `succeeded` while
canonical state correctly did **not** advance.

## Phase 14 — Timeline UI — PASSED

The reference timeline rendered all three kinds, correctly separated:

    RUNTIME_OBSERVATION  call.leg.offered   08:16:12
    RUNTIME_OBSERVATION  call.leg.answered  08:16:12
    RUNTIME_OPERATION    call.leg.originate 08:16:11  (succeeded)
    AUDIT                call.created / call_leg.terminated / call.terminated

    RAW PROVIDER PAYLOAD:   NO
    SECRETS:                NO
    WORKER LEASE INTERNALS: NO

This is the first run in which `RUNTIME_OBSERVATION` entries appear at all.

## Phase 15 — Inbound Natural Adoption — PASSED

    SOURCE:            real local SIP — the managed node's own PJSIP loopback
                       INVITE to c6-generic-proof@127.0.0.1:5060
    ASTERISK CHANNEL:  1787357679.3   (Asterisk-generated uniqueid)
    OFFERED OBSERVATION: call.leg.offered, observed_state `offered`
    RUNTIME_OBSERVATION ID: 2e691ad5ce150c4ba82ec72d6fa7e2ad
    CALL:              6785f2fa-c50e-4142-8c8e-4616118f4bba
    CALL LEG:          91bf5f16-9a78-4406-8470-ac3730918e0b
    DIRECTION:         inbound
    STATE:             offered
    TENANT:            a2315712-d650-4d43-8efb-1ac0e3cb356c
    RUNTIME NODE:      3488f30f-bdf8-4a2a-b2f9-e865b0c625d0
    RUNTIME CHANNEL:   1787357679.3   (matches the real Asterisk channel)
    C7 USED:           NO

The full inbound path is proven: local SIP source → managed Asterisk →
`c6-generic-proof` → Stasis → normalized `call.leg.offered` →
`runtime_observations` → C6C adoption → one inbound Call and CallLeg.

## Phase 17 — Inbound Answer — NOT REACHED

Blocked by Defect 2 (leg stranded at `offered`) and bounded by the fixture's
lifetime: nothing controls the Stasis channel, so the abandoned dialog is
reclaimed by `rtp_timeout=30` about 30 s after answer. Both channels lived
00:14:39 → 00:15:09.

This is a genuine fixture characteristic, recorded rather than worked around. It
does not by itself prevent Answer — Defect 2 does.

## Conference Isolation

Not disturbed. No conference created, no participant admitted, no
conference-owned channel controlled. A naturally occurring
`conference_participant` reconciliation claim was observed being skipped safely.
All four Asterisk Pods ended with 0 active channels. No RH change.

## Security / Authority

    DIRECT ARI CONTROL:       NO
    DB MUTATION:              NO
    SESSION INJECTION:        NO
    OBSERVATION INJECTION:    NO
    FEATURE GATE:             NO
    MANUAL RECONCILE:         NO
    MANUAL CAPABILITY REPAIR: NO
    MANUAL DEPLOYMENT PATCH:  NO
    APNTALK TOUCHED:          NO
    SOURCE PATCHED:           NO

Read-only corroboration only: `dialplan show`, `core show channels`,
`kubectl get -o jsonpath`, and `psql` SELECTs.

## Cleanup

    Non-terminal proof Calls: 0
    Stray Asterisk channels:  0 on all four Pods
    Reconciler:               1/1 Running, no crashloop
    RuntimeNodes:             c6e-final-proof-20260822        active / ready (RETAINED)
                              rnp6-readiness-reproof-20260809 active / ready
                              v0c6-conference-runtime-20260815 active / ready
    Session:                  logged out through the normal UI

All four proof Calls reached terminal state naturally. Historical Call and
timeline evidence was preserved.

## Repository Verification

    git diff --check          clean
    make k8s-config-check     passed
    make repository-hygiene   passed
    make secret-scan          passed

## Code Changes

    NONE.

## Recommended Bounded Corrections

1. **Make outbound legs traverse `originating`.** Write
   `originating` when the originate operation is dispatched (the channel id is
   already reserved at that moment), or admit `answered`/`ringing`/`early_media`
   directly from `requested`. Without this no outbound call can ever be answered,
   held, or DTMF-controlled. Add a regression test asserting an outbound leg
   reaches `answered` from a `call.leg.answered` observation.

2. **Handle observations that arrive before their subject exists.** For a
   `call.leg.answered` (or `ringing`/`early_media`) whose channel has no CallLeg
   yet, defer and retry rather than discarding, or have `adoptInboundLeg()` seed
   the leg from the channel's current provider state. The `Answer()`-before-
   `Stasis()` ordering is normal Asterisk behaviour, not a fixture artifact.

3. **Give `telephony-reconciler` a Kubernetes API identity.** Add the
   `utcp.io/kubernetes-api-client: "true"` label, the projected
   `kubernetes-api-credentials` volume, and an appropriately scoped
   ServiceAccount — mirroring `utcp-runtime-fence-worker` — or move managed
   Deployment convergence into a worker that already holds that identity.

Items 1 and 2 are required for the C6E retry; item 3 is required before existing
managed nodes can converge without reprovisioning. The retry can reuse the
retained `c6e-final-proof-20260822` node.

## C6 Status

    C6A-D:                        IMPLEMENTED / TESTED
    C6E IMPLEMENTATION:           IMPLEMENTED / TESTED
    REFERENCE CALL UI:            IMPLEMENTED / PARTIALLY LIVE PROVEN
    CONFERENCE OWNERSHIP:         VERIFIED LIVE (command + normalizer)
    RECONCILER FENCING:           VERIFIED LIVE (skip, worker survives)
    RUNTIME CATALOG:              VERIFIED LIVE
    DETERMINISTIC CHANNEL ID:     VERIFIED LIVE
    OBSERVATION NORMALIZATION:    VERIFIED LIVE
    INBOUND ADOPTION:             VERIFIED LIVE (1 Call / 1 Leg)
    OUTBOUND LIFECYCLE STATE:     BLOCKED (Defect 1)
    INBOUND ANSWER:               BLOCKED (Defect 2)
    MANAGED NODE CONVERGENCE:     BLOCKED (Defect 3)
    C6E LIVE:                     FOUND_BLOCKER

    C6:                           NOT LIVE PROVEN

## Recommended Next Step

    BOUNDED CODEX CORRECTION — items 1 and 2, ideally with 3, then re-run C6E.

T4 must not start. No further C6 audit is required; all three defects are exact
and localized.
