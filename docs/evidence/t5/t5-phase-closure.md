# T5 Phase Closure — Multi-Runtime Convergence, Failover, and Recovery

Verdict: `T5_COMPLETE`

This is the canonical phase-level closure record for T5. It is an evidence-only
document: no proof was rerun, no production code, Kubernetes manifest, runtime
configuration, dependency, or phase marker was changed, no image was rebuilt,
and no workload was restarted.

## Source Commit

- Closure audit performed at `cb7b9c8` (`docs(roadmap): reconcile T1 and T5 status`).
- Branch `main`, working tree clean, `UTCP_PHASE=T1`, ahead of `origin/main` with nothing pushed.
- Immediate predecessor finding: [`docs/evidence/roadmap/t1-t5-roadmap-reconciliation.md`](../roadmap/t1-t5-roadmap-reconciliation.md)
  established that all T5 implementation and live-proof criteria were satisfied
  and that only this closure record remained.

## Canonical T5 Authority

`docs/roadmap/implementation-roadmap.md` §"T5 - Multi-Runtime Convergence,
Failover, and Recovery" is the executable roadmap authority.
`docs/roadmap/phase-status.md` is the status ledger. The T5 contract is:

**Objective (implementation items):** event-stream reconnection/replay;
stale-registration expiry; orphan-channel cleanup; conference-membership
reconciliation; runtime-node draining/unavailability handling; eligible-node
reselection; replay-safe operations; failed-operation recovery; cross-runtime
recovery *where technically supported*. Boundary: do not promise seamless
active-call migration unless signaling/runtime/media behavior actually prove it.

**Completion criteria:** control-plane state recovers after runtime
interruption; duplicate operations remain safe; stale runtime resources are
detected and reconciled; runtime-node failure behavior is explicit and
observable.

The roadmap's status paragraph additionally names the Namespace Pod Security
Admission reconciliation and its controlled live proof as T5 items.

No requirement absent from repository authority was added. R0 release criteria
(clean-clone setup, versioned release, hosted CI green) are **not** treated as
T5 exit criteria.

## T5 Criterion Matrix

Primary evidence corpus, for every row unless stated otherwise:
`docs/evidence/t2/multi-node-failover-readiness.md` (12 886 lines; see the
filing-anomaly section below). Section references are the `T5-Ann` corridor
headings inside that file.

| # | Criterion | Authority | Evidence (§) | Commit | Result | Freshness | Gap | Status |
|---|---|---|---|---|---|---|---|---|
| 1 | Multi-RuntimeNode topology exists and is schedulable | Objective (precondition for failover/reselection) | §T5-A8, §T5-A10 | node-B rollout corridor | Second Asterisk RuntimeNode deployed live; horizontal scale and existing-node identity proven | current | None | `SATISFIED` |
| 2 | Runtime-node failure/unavailability handling | Objective + criterion 4 | §T5-A2, §T5-A3 | failover coordinator corridor | Scheduled coordinator sweep gated behind authoritative former-runtime absence verification | current | None | `SATISFIED` |
| 3 | Runtime fencing (duplicate-execution prevention) | Objective + criterion 2 | §T5-A4, §T5-A5, §T5-A15, §T5-A47 | `1820757` | Generic Kubernetes runtime-fence operation/adapter; replacement-before-fence guard; exact controller-owner-reference ownership — `T5_EXACT_OWNERSHIP_AND_SECOND_GENERATION_LIVE_PROOF_COMPLETE` | current | None | `SATISFIED` |
| 4 | Eligible-node reselection after failure | Objective | §T5-A47, §T5-A69/A70 | `97b56d6` | Conference rebinds to an eligible node; `G+1 → G+2` second-generation recovery proven live | current | None | `SATISFIED` |
| 5 | Failed-operation recovery / no-capacity handling | Objective + criterion 1 | §T5-A42, §T5-A70 | `97b56d6`, `d8a53e9` | Durable observable no-capacity (`failover_pending`) state; automatic rebind once a ready node appears — `T5_DETERMINISTIC_CAPACITY_AND_PLACEMENT_LIVE_PROOF_COMPLETE` | current | None | `SATISFIED` |
| 6 | Canonical node restoration (un-fencing) | Objective + criterion 1 | §T5-A37 | restoration corridor | `T5_CANONICAL_FORMER_NODE_RESTORATION_LIVE_PROOF_COMPLETE`, all 31 completion criteria met: fence → disabled + placement-excluded → authorized desired-state `active` → single idempotent `runtime.node.restore` | current | None | `SATISFIED` |
| 7 | Stale runtime resource detection and reconciliation (RuntimeBinding) | Criterion 3 | §T5-A49, §T5-A50 | binding-retirement corridor | `T5_AUTOMATIC_RUNTIME_BINDING_RETIREMENT_LIVE_PROOF_COMPLETE`; closed/closed conferences with active binding = 0 | current | None | `SATISFIED` |
| 8 | Orphan-channel cleanup | Objective | §T5-A54, §T5-A55, §T5-A56 | `62ae45e` | `T5_ORPHAN_STASIS_LOCAL_CHANNEL_CLEANUP_LIVE_PROOF_COMPLETE`; automatic orphan participant Local-channel reclamation with health-gated re-inspection of both legs | current | None | `SATISFIED` |
| 9 | Conference-membership reconciliation | Objective + criterion 3 | §T5-A55 (participant presence, bridge membership, aggregate participant presence) | `62ae45e` | Participant/bridge membership reconciled against runtime reference state | current | None | `SATISFIED` |
| 10 | No false absence under degraded runtime (correctness of detection) | Criterion 3 | §T5-A52, §T5-A53 | `af2212d` | `T5_AUTHORITATIVE_ARI_FALSE_404_LIVE_PROOF_COMPLETE`; with `res_ari_bridges.so` unloaded every inspection classified `degraded_unavailable`, no authoritative absence, no verify/fence/rebind; auto-recovered to `healthy_present` after restore | current | None | `SATISFIED` |
| 11 | Event-stream reconnection / listener liveness and degradation | Objective + criterion 4 | §T5-A63, §T5-A64, §T5-A65 | `1e792fe` | `T5_EVENTS_ONLY_LISTENER_LIVENESS_LIVE_PROOF_COMPLETE`; deterministic ARI event-stream degradation and automatic recovery | current | None | `SATISFIED` |
| 12 | Reconnect recovery event symmetry | Criterion 4 | §T5-A66, §T5-A67 | `f90e266` | `T5_LISTENER_RECONNECT_RECOVERY_EVENT_LIVE_PROOF_COMPLETE`; degraded and recovered events are symmetric | current | None | `SATISFIED` |
| 13 | Replay-safe / idempotent operations | Criterion 2 | §T5-A37 (single idempotent `runtime.node.restore`), §T5-A5 (replacement-before-fence guard), C3 operation/lease/fencing kernel in [`../c3/runtime-engine.md`](../c3/runtime-engine.md) | `1820757` | Duplicate and repeated operations remain safe; generation and wrong-node guards preserved | current | None | `SATISFIED` |
| 14 | Resilience observability: metrics and actionable alerts | Criterion 4 | §T5-A58 → §T5-A62 | `e87d1c7` | `T5_METRICS_SECURITY_AND_ORPHAN_ALERT_LIVE_PROOF_COMPLETE` (28/28 criteria): internal scrape path, public metrics cutoff, corrected aggregation, actionable orphan alert replacing a non-actionable one | current | None | `SATISFIED` |
| 15 | Recovery metric-event retention and scheduled pruning | Criterion 4 (bounded observability) | §T5-A73, §T5-A74, §T5-A75 | `3f89dc0`, `b56de3c` | `T5_CONFERENCE_RECOVERY_EVENT_RETENTION_LIVE_PROOF_COMPLETE`; scheduled pruning proven live | current | None | `SATISFIED` |
| 16 | Namespace Pod Security Admission — repository authority | Roadmap status paragraph | §T5-A76, §T5-A77 | `2ec8fd2` | Single canonical Namespace PSA authority; duplicate `utcp-runtime` definitions removed | current | None | `SATISFIED` |
| 17 | Namespace Pod Security Admission — controlled live proof | Roadmap status paragraph + §"Live acceptance contract" | §T5-A78 | `f959f00` | All six deferred acceptance points met (detailed below) | current | None | `SATISFIED` |
| 18 | Stale-registration expiry | Objective | T1 corridor: [`../t1/kamailio-sip-over-wss-signaling.md`](../t1/kamailio-sip-over-wss-signaling.md) | T1-G | Bounded Contact expiration observed ≈115 s; session-end revocation and post-end refresh rejection proven | current | None | `SATISFIED` |
| 19 | Kamailio signaling cutoff | Objective (re-sequenced) | §T5-A71, §T5-A72 | `2620225` | Formally re-sequenced **out of** active T5: T1 Kamailio is registration-only and has no runtime dialog route to cut off; the future routing cutoff is preserved under the phases that create the route (T3/V0) | current | None | `SATISFIED` (as an explicit, authority-backed scope decision) |
| 20 | Cross-runtime recovery *where technically supported* | Objective (conditional clause) | roadmap T4 section | — | Not technically supported yet: the second runtime family (FreeSWITCH) is T4, which is `PLANNED` and gated behind T3. The conditional clause is therefore inapplicable, not unmet | current | None | `SATISFIED` (conditional clause inapplicable) |
| 21 | Do not promise seamless active-call migration | Objective boundary | whole corpus | — | No T5 document claims seamless active-call migration; failover is conference rebinding with explicit, observable state, not in-flight media continuity | current | None | `SATISFIED` |

**All 21 criteria are `SATISFIED`. No criterion is `NOT_SATISFIED`.**

## Multi-Node and Failover Determination

Applying the required lens to the actual T5 contract:

- **What failed or was interrupted:** an Asterisk execution RuntimeNode became
  unavailable (§T5-A2/A3); an ARI event stream degraded (§T5-A65); an ARI REST
  resource family degraded while HTTP stayed up (§T5-A53); capacity was
  exhausted so no eligible node existed (§T5-A70).
- **What automatically recovered:** the failover coordinator sweep rebound the
  Conference to an eligible node; a no-capacity conference held a durable
  `failover_pending` state and rebound automatically once a ready node
  appeared; the listener reconnected and emitted a symmetric recovery event;
  degraded inspections auto-returned to `healthy_present` after module restore;
  a canonically restored node was un-fenced through a desired-state `active`
  request.
- **What remained authoritative:** PostgreSQL throughout — RuntimeNode desired
  state, RuntimeBinding, conference membership, operations, leases, and
  generations. Kubernetes Pod state never became canonical telephony state.
- **What duplicate execution was prevented:** exact controller-owner-reference
  fencing removed the `asterisk-ari` / `asterisk-ari-b` prefix collision, so
  fencing the shorter-named node left its sibling Pod running (§T5-A47); the
  replacement-before-fence guard prevents fencing without a replacement
  (§T5-A5); generation and wrong-node guards short-circuit stale operations.
- **What operator action was required:** none for recovery. Restoration is an
  authorized desired-state change through the canonical API, not a manual
  runtime mutation. No hidden switch, allowlist, or manual reconciliation
  trigger exists.
- **Evidence:** §T5-A2/A3/A8/A10/A37/A42/A47/A53/A65/A70 as tabulated above.

No live scenario was rerun.

## Fencing and Authority Determination

Fencing is a canonical control-plane operation (`runtime.node.restore` and the
generic Kubernetes runtime-fence operation/adapter), executed by the dedicated
`utcp-runtime-fence-worker` process role under PostgreSQL lease and fencing
authority from the C3 kernel. Singleton/leader authority is enforced by the same
lease/epoch/receipt/checkpoint model that T1 uses for its registration observer,
whose fenced Pod takeover was itself proven live in the T1 corridor.

State authority is preserved end to end: the fence worker mutates infrastructure
only after the control plane has recorded the authoritative decision, and
observed state re-enters the system exclusively through the established C3
projection path. No T5 mechanism writes canonical telephony state from
Kubernetes, Redis, or a WebSocket event.

## Security and NetworkPolicy Determination

- The fencing worker reaches the Kubernetes API through an explicit, canonically
  rendered egress policy
  (`infrastructure/kubernetes/security/kubernetes-api/allow-runtime-fencer-kubernetes-api.template.yaml`),
  proven in §T5-A17 → §T5-A19, with the projected-CA readability correction in
  §T5-A20 and the token-audience correction that followed. Default-deny is
  preserved; the fencer holds a narrow allow, not broad egress.
- Resilience metrics were moved to an internal scrape path with the public
  metrics surface cut off, and a non-actionable orphan alert was replaced with
  an actionable one (§T5-A60 → §T5-A62, `e87d1c7`).
- `scripts/asterisk-ari/config-check:148` enforces that the T5 staged A/B
  runtime overlay exposes no `NodePort`, `LoadBalancer`, `hostPort`,
  `hostNetwork`, `hostPID`, or SIP/RTP port surface — a standing repository
  guard, not a one-time proof.
- Re-validated at HEAD during this audit (non-mutating):
  `make security-config-check` → `namespace_psa_authority=ok`,
  `restricted_workload_compatibility=ok`, `K3 security config check passed`.

## Namespace PSA Determination

`f959f00` (`docs(t5): prove namespace security label reconciliation`) added
§T5-A78 to the corpus, proving the reconciliation live against `utcp-local`
(Kubernetes `v1.35.3+k3s1`) from repository `HEAD 2ec8fd2` with `UTCP_PHASE=T1`.
Mapped against the document's own six-point deferred acceptance contract:

| # | Deferred acceptance point | Result |
|---|---|---|
| 1 | Apply manifests; all UTCP workloads stay Ready | **Met** — every Deployment/StatefulSet stayed Ready; the two `utcp-runtime` Asterisk pods kept baseline restart counts |
| 2 | All five UTCP namespaces at `restricted` + `v1.35`, incl. `utcp-runtime` `enforce-version` | **Met** — four `unchanged`, `utcp-runtime configured`; all six labels present on all five namespaces; reapply idempotent |
| 3 | Compliant Pod admitted; violating Pod rejected as `restricted:v1.35` | **Met** — `t5a78-psa-compliant` admitted and `Succeeded`; `t5a78-psa-violating` rejected with the rejection explicitly attributed to `restricted:v1.35`, never created |
| 4 | Migration/maintenance Jobs remain admissible | **Met** — migration overlay server-side dry-run `unchanged`; the Job's exact pod template passed `restricted:v1.35` enforce |
| 5 | No unrelated namespace modified; drift restored by re-apply | **Met** — system namespaces untouched; `audit-version` drift introduced and restored declaratively; second reapply idempotent; NetworkPolicy count `30` before and after |
| 6 | Final environment healthy, no disposable Pods left | **Met** — compliant Pod deleted, violating Pod never created, no manifest left applied, no port-forward left |

Mapped against the required equivalents: intended namespace labels ✓; intended
enforce/audit/warn levels ✓; existing workloads remain healthy ✓; non-compliant
test workload rejected ✓; compliant workload accepted ✓; cleanup completes ✓;
no canonical production workload compromised ✓.

Two items are recorded in the proof and remain correctly classified there: a
pre-existing, unrelated `grafana-sc-dashboard` sidecar CrashLoopBackOff caused
by the documented API-server-egress endpoint-pin drift after a node-IP shuffle
(Namespace reconciliation does not touch NetworkPolicies), and a deliberate
narrowing of the apply authority to the canonical
`pod-security-labels.yaml` rather than the broader `make security-apply`, which
would have restarted unrelated workloads. Neither weakens the claim.

**The Namespace Pod Security Admission criterion is satisfied.**
**No additional live PSA proof is required for T5 closure.**

## Evidence Filing Anomaly

The primary T5 evidence corpus is filed at:

```text
docs/evidence/t2/multi-node-failover-readiness.md
```

That file opens with the heading
`# T2-C0 — Multi-Node Asterisk Conference Failover and Fencing Readiness`: it
began as a T2 readiness audit and then accumulated the entire T5 corridor
sequence `T5-A1` through `T5-A78` in place. Its commit provenance is
correspondingly T5-titled — `f959f00`, `2ec8fd2`, `28dea35`, `b56de3c`,
`3f89dc0`, `d8a53e9`, `97b56d6`, `1e792fe`, `f90e266`, `e87d1c7`, and others all
carry `t5` scopes while touching a `docs/evidence/t2/` path.

The file is **not** moved, renamed, duplicated, or rewritten by this closure.
Relocating it would break stable historical links from roughly twenty T5 commits
and from `docs/roadmap/implementation-roadmap.md`,
`docs/roadmap/phase-status.md`, and
[`../roadmap/t1-t5-roadmap-reconciliation.md`](../roadmap/t1-t5-roadmap-reconciliation.md),
while changing no fact. Repository convention already preserves historical
evidence in place.

**This document is therefore the canonical phase-level T5 index.** Readers
seeking T5 status start here; the corridor-level detail remains at the legacy
path above, and `docs/evidence/t5/` is the canonical location for any future
T5 phase-level record.

## Hosted CI Determination

```text
Hosted CI is required for R0 release closure.
Hosted CI is not a T5 exit criterion.
Hosted CI was not observed during this task.
Its absence does not prevent T5 completion.
```

The single hosted-CI requirement in the repository is
`docs/roadmap/implementation-roadmap.md` §"Phase R0 — Portfolio Release"
("CI is green"). No T-phase or UI-phase exit criterion references it. GitHub and
the network were not queried and no workflow file was altered.

## Current Architecture Applicability

**Status: current and applicable.**

Fifty-nine commits sit between `f959f00` and `cb7b9c8`. They were checked
against each surface that could invalidate T5 evidence; a concrete architecture
conflict was required before invalidating anything, and none was found:

| Surface | Change since `f959f00` | Effect on T5 evidence |
|---|---|---|
| Node topology | `infrastructure/kubernetes/overlays/local-two-asterisk/` **unchanged** | none |
| Namespace policy | `infrastructure/kubernetes/security/namespaces/` **unchanged** | none |
| Fencing authority | fence worker, fence operation, and adapter unchanged | none |
| NetworkPolicy boundaries | **additive only** — new `allow-reverb.yaml`; reverb role added to `allow-redis`, `allow-api`, `allow-gateway`, `allow-worker` (UI-D Reverb/WSS work). The fencer's `allow-runtime-fencer-kubernetes-api.template.yaml` is unchanged and default-deny is preserved | none |
| Security controls | `make security-config-check` re-validated at HEAD: `namespace_psa_authority=ok`, `restricted_workload_compatibility=ok` | none |
| Workload recovery | recovery/failover code paths unchanged | none |
| Reconciliation authority | `5e31943` changed `ReconciliationRepository` to gate **event emission** on a fingerprint of `desired_generation`/`status`/`last_operation_id`/`blocked_reason` | **none** — convergence, claim/lease, and state authority are untouched; only duplicate no-op events are suppressed. UI-D22 live-proved that each real fingerprint change still emits exactly one event, so criterion 4's "explicit and observable" requirement is preserved and the prior ~7 events/sec no-op storm is removed |

The remaining commits in that range are UI-A…UI-E frontend and documentation
work, which render canonical API state and hold no telephony authority.

## No Proof Was Rerun

No Namespace PSA, failover, fencing, restoration, capacity, listener-liveness,
orphan-cleanup, retention, security, T1, T2, or UI proof was re-executed. Every
row in the criterion matrix is reconciled from evidence already committed to the
repository. The only commands run during this audit were read-only repository
inspection plus the non-mutating `make security-config-check`,
`make repository-hygiene`, `make workflow-check`, and `make secret-scan`.

## Environment Preservation

```text
production code changed:      no
dependencies changed:         no
Kubernetes manifests changed: no
runtime configuration changed:no
versions.env changed:         no
workloads restarted:          no
images rebuilt:               no
live proof rerun:             no
Kubernetes apply run:         no
Playwright MCP run:           no
```

No canonical record was mutated and no cluster state was changed.

## Remaining Repository Dependency Graph

```text
T5  COMPLETE  (this document)

T3 rtpengine browser media — PLANNED, not dependency-ready
├── missing: ADR establishing media/rtpengine authority (last ADR is 019)
├── missing: rtpengine version pin in versions.env
├── missing: rtpengine Kubernetes manifests
├── missing: explicit RTP NetworkPolicies
├── missing: Kamailio INVITE route authority consuming the canonical
│            RuntimeNode eligibility projection
└── blocked-by: guard scripts that currently ASSERT RTP ABSENCE —
                scripts/security/config-check:426, scripts/gateway/config-check:61

V0 natural login → SIP registration → conference admission — DEPENDENCY_GATED on T3
T4 FreeSWITCH ESL parity                                   — DEPENDENCY_GATED on T3 + V0
C6/C7/T6/V1/A0 extended scope                              — PLANNED
R0 portfolio release                                       — DEPENDENCY_GATED on the above + hosted CI green
```

## Phase-Marker Decision

`UTCP_PHASE=T1` is **unchanged**, and no guard was modified. Reasons:

- It is a guarded marker naming the authoritative completed phase
  (`scripts/check-repository-hygiene:36-37` states this literally).
- Six repository guards currently pin it: `check-repository-hygiene` and
  `local/config-check` require exactly `T1`; `asterisk-conference/config-check`
  requires `^UTCP_PHASE=T1$`; `kamailio-signaling/config-check` accepts `T[01]`;
  `telephony-domain/config-check` accepts `C[45]|T[01]`;
  `asterisk-ari/config-check` accepts `C5|T[01]`. **No guard accepts `T2` or
  `T5`.**
- Advancing it is therefore a coordinated behavior and validation change, not
  documentation reconciliation: `make repository-hygiene` — and hosted CI with
  it — would fail until every guard is updated in the same commit.
- T5 closure does not by itself establish the correct next marker value. T5 sits
  after T3/V0/T4 in the `CLAUDE.md` sequence, all of which remain planned, so
  "furthest completed phase" is not a single unambiguous value right now.
- Any future marker change must update all guards, roadmap text, and agent
  instructions together, in one bounded commit that re-runs the full check set.

## Final T5 Status

```text
T5 = Complete
```

Every one of the 21 canonical criteria is `SATISFIED`; Namespace PSA is
conclusively satisfied; no later architecture change invalidates the evidence;
hosted CI is confirmed to be an R0 requirement rather than a T5 one; the only
previously outstanding item was this closure documentation; and no
implementation or live proof remains.
