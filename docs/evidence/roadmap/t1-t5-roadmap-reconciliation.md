# T1/T5 Roadmap Reconciliation and Next-Phase Selection Audit

Verdict: `T1_T5_ROADMAP_RECONCILIATION_COMPLETE`

This is a bounded evidence-only roadmap audit. No production code, dependency,
Kubernetes manifest, runtime configuration, or phase marker was changed. No
workload was rebuilt or restarted and no browser proof was run.

## Starting Point

- Branch: `main`
- Starting HEAD: `664f4f1` (`docs(ui): complete UI-E portfolio proof`)
- Starting working tree: clean
- Phase marker: `UTCP_PHASE=T1`
- Push status: `main` is **ahead 11 / behind 0** of `origin/main` (`f49276b`); nothing pushed.

## Canonical Roadmap Authority

`docs/roadmap/implementation-roadmap.md` declares a five-document hierarchy. The
audit adopts it unchanged:

| Document | Authority |
|---|---|
| `docs/unified-telephony-control-plane-initial-implementation-plan.md` | Product boundary — what UTCP must eventually do |
| `docs/unified-telephony-control-plane-application-implementation-plan.md` | Application/runtime sequencing |
| **`docs/roadmap/implementation-roadmap.md`** | **Executable roadmap authority** — actual ordering, objectives, current completion state, next actionable phase |
| `docs/roadmap/ui-foundations.md` | Authoritative UI-A…UI-E scope and completion |
| `docs/roadmap/phase-status.md` | Status-ledger authority — shortest path to "what phase are we in" |

`CLAUDE.md` states the binding phase-dependency order for task scoping and
"must not be treated as an independently competing roadmap".

`README.md` is **not** a roadmap authority: its own "Documentation Authority"
section points readers to the architecture and roadmap documents. That matters
below, because README carries the single most misleading stale statement in the
repository.

When documents disagree, the executable roadmap plus the status ledger win for
phases already started or complete. No document contradicted another on *phase
ordering*; the contradictions found are all *status* staleness.

## `UTCP_PHASE` Semantics

`UTCP_PHASE` is a **manually maintained roadmap marker naming the authoritative
completed phase**. This is stated literally by the guard that enforces it:

```text
scripts/check-repository-hygiene:36-37
  if [ "$declared_phase" != "T1" ]; then
    printf 'UTCP_PHASE must match the authoritative completed phase T1, found: %s\n'
```

Answers to the required questions:

1. **Which files consume it?** Only `versions.env` (declaration) and six shell
   guards: `scripts/check-repository-hygiene`, `scripts/local/config-check`,
   `scripts/kamailio-signaling/config-check`,
   `scripts/asterisk-conference/config-check`,
   `scripts/telephony-domain/config-check`, `scripts/asterisk-ari/config-check`.
   It is also described in `CLAUDE.md` and `README.md`.
2. **Does runtime behavior depend on it?** **No.** `rg UTCP_PHASE infrastructure/ apps/ .github/ Makefile`
   returns nothing. It is not injected into any image, manifest, env var, or
   application config. Changing it activates and deactivates no code path.
3. **Do CI checks depend on it?** **Yes.** `.github/workflows/repository-hygiene.yml`
   runs `make repository-hygiene`, which hard-fails unless the value is exactly
   `T1` **and** `docs/roadmap/phase-status.md` marks
   `T1 Kamailio SIP-over-WSS signaling` as `Complete`.
4. **Does changing it activate code or infrastructure?** No code or
   infrastructure — but it would **break CI**. The accepted values are pinned:
   `check-repository-hygiene` and `local/config-check` require exactly `T1`;
   `asterisk-conference` requires `^UTCP_PHASE=T1$`; `kamailio-signaling`
   accepts `T[01]`; `telephony-domain` accepts `C[45]|T[01]`; `asterisk-ari`
   accepts `C5|T[01]`. **No guard accepts `T2`.**
5. **Is it intended to advance after T1 completion?** Not automatically. The
   executable roadmap states plainly: "T2 is complete and T5 hardening is
   currently in progress **without advancing `UTCP_PHASE` beyond T1**." The
   marker is deliberately held.
6. **Is `T1` still truthful after T2 and UI completion?** Yes under the
   repository's own definition, but it is **imprecise**. Read literally as "the
   authoritative completed phase", T2 is also complete, so the marker
   understates reality. It is truthful as an intentionally conservative pin, not
   as a live index of the furthest completed phase.
7. **Would changing it now be reconciliation or a behavioral change?** A
   **behavioral change**. Setting `UTCP_PHASE=T2` would fail
   `make repository-hygiene` (and therefore hosted CI) plus four other config
   checks until all six guards are edited in the same commit. It is out of scope
   for an evidence-only audit.

The marker was not changed by this audit.

## Complete Phase Matrix

Status vocabulary: `COMPLETE`, `IN_PROGRESS`, `PLANNED`, `DEPENDENCY_GATED`,
`BLOCKED`, `STATUS_UNCLEAR`, `NOT_APPLICABLE`.

| Phase | Purpose | Documented | Strongest evidence | Reconciled |
|---|---|---|---|---|
| F0 | Repository contract and governance | Complete | `docs/evidence/f0/` | `COMPLETE` |
| F1 | Minimal application skeleton | Complete | `docs/evidence/f1/` | `COMPLETE` |
| F2 | Container build foundation | Complete | `docs/evidence/f2/` | `COMPLETE` |
| F3 | Docker Compose core platform | Complete | `docs/evidence/f3/` | `COMPLETE` |
| F4 | CI quality baseline | Complete | `docs/evidence/f4/` | `COMPLETE` |
| K0 | Local k3d cluster foundation | Complete | `docs/evidence/k0/` | `COMPLETE` |
| K1 | Kubernetes application base | Complete | `docs/evidence/k1/` | `COMPLETE` |
| K2 | Traefik and Gateway API | Complete | `docs/evidence/k2/` | `COMPLETE` |
| K3 | Kubernetes security boundaries | Complete | `docs/evidence/k3/` | `COMPLETE` |
| K4 | Kubernetes observability foundation | Complete | `docs/evidence/k4/` | `COMPLETE` |
| C0 | Control-plane application kernel | Complete | `docs/evidence/c0/` | `COMPLETE` |
| C1 | Identity, tenancy, authorization | Complete | `docs/evidence/c1/` | `COMPLETE` |
| C2 | Runtime registry | Complete | `docs/evidence/c2/` | `COMPLETE` |
| C3 | Command/event/projection/reconciliation | Complete | `docs/evidence/c3/` | `COMPLETE` |
| C4 | Deterministic simulator adapter | Complete | `docs/evidence/c4/` | `COMPLETE` |
| C5 | Telephony-session and conference domain | Complete | `docs/evidence/c5/` | `COMPLETE` |
| T0 | Asterisk ARI adapter | Complete | `docs/evidence/t0/` | `COMPLETE` |
| T1 | Kamailio SIP-over-WSS signaling | Complete | `docs/evidence/t1/kamailio-sip-over-wss-signaling.md` (`PHASE_T1_COMPLETE`) | `COMPLETE` |
| T2 | Asterisk conference execution | Complete | `docs/evidence/t2/asterisk-conference-recovery.md` | `COMPLETE` |
| T3 | rtpengine browser media | Planned | none | `PLANNED` (architecture decision unresolved — see dependency graph) |
| V0 | Login → SIP registration → conference admission | Planned | none | `DEPENDENCY_GATED` on T3 |
| T4 | FreeSWITCH ESL parity | Planned | none | `DEPENDENCY_GATED` on T3/V0 |
| T5 | Multi-runtime convergence, failover, recovery | In Progress | `docs/evidence/t2/multi-node-failover-readiness.md` (12 886 lines, incl. T5-A78) | `IN_PROGRESS` — **only final phase-closure evidence remains** |
| C6/C7/T6/V1/A0 | Call lifecycle, trunks, routes, reference app | Planned (extended scope) | none | `PLANNED` |
| UI-A | Application shell, routing, navigation | Complete | `docs/roadmap/ui-foundations.md` | `COMPLETE` |
| UI-B | Design system and component library | Complete | `docs/roadmap/ui-foundations.md` | `COMPLETE` |
| UI-C | Data interaction and management workflows | Complete | `docs/roadmap/ui-foundations.md` | `COMPLETE` |
| UI-D | Real-time telephony operational experience | Complete (T1-achievable scope) | `docs/evidence/ui/ui-d25-audit-live-proof.md` | `COMPLETE` (T2/T3/V0-gated views excluded by design) |
| UI-E | Accessibility, testing, responsiveness, portfolio quality | Complete | `docs/evidence/ui/ui-e13-portfolio-finish-live-proof.md` | `COMPLETE` |
| R0 | Portfolio release | Planned | none | `DEPENDENCY_GATED` on remaining phases + hosted CI green |

No phase was marked Complete merely because later work exists, and no phase was
left open merely because a checklist was not updated.

## T1 Reconciliation

`docs/evidence/t1/kamailio-sip-over-wss-signaling.md` records verdict
`PHASE_T1_COMPLETE` for the T1-G final closure corridor. Reconciled against the
required criteria using existing evidence only — no SIP, browser, Kubernetes, or
runtime test was rerun:

| Criterion | Reconciled from existing evidence |
|---|---|
| Kamailio Deployment and Service | T1-B pinned runtime; ClusterIP 8080/WS only, no NodePort/LoadBalancer/hostNetwork |
| SIP WSS route | Trusted `sip.utcp.local.test` WSS route over the shared `443/TCP` edge |
| TLS and origin handling | Gateway/Traefik edge; T1-B route proof |
| SIP identity | Session-scoped username in the canonical realm |
| Credential authority | `telephony_signaling_credentials` is the only persisted SIP credential authority; `TelephonySession` is the eligibility authority |
| One-time plaintext handling | Plaintext SIP secret returned only once at issuance; never re-served |
| HA1 verifier storage | HA1 computed only at issuance; never serialized to response, log, or audit |
| Authentication roles | Three least-privilege PostgreSQL roles with disjoint grants, verified in migration |
| REGISTER success and rejection | Live: REGISTER, refresh, replace, deregister, wrong-password 401, SHA-256-algorithm 401 |
| Kamailio registrar authority | Kamailio is sole registrar; no application path writes `location` |
| Native usrloc | Native `usrloc` persistence proven |
| Refresh and deregistration | Same `ruid`, advancing `last_modified`; explicit deregistration proven |
| Expiry and revocation | Session-end auth-view revocation, post-end refresh 401, bounded expiry observed ≈115 s |
| Desired state | PostgreSQL-authoritative registration desire |
| Observed state | Sanitized `usrloc` snapshot diffing |
| Observation ingestion | C3 registration receipts |
| Normalization and projection | Normalizer + projection proven; aggregates recorded |
| Listener fencing | Fenced observer; **Pod takeover proven live** during closure (closed the runbook's last flagged gap) |
| Tenant isolation | Two-tenant non-platform natural browser proof, both directions, 404 on cross-tenant detail |
| Contact constraints | Exactly one active Contact/credential per signaling identity; no multi-Contact mode |
| NetworkPolicy | T1-B NetworkPolicy proof |
| Metrics and alerts | `kamailio_registration_*` with `result`-only labels; five alert rules each pairing a negative signal with a positive precondition |
| Redaction | No HA1, raw Contact, `ruid`, or SIP messages in public metadata; `make secret-scan` passed |
| Restart and takeover | Kamailio Pod restart with persisted-Contact recovery; observer takeover proven |
| Synthetic SIP proof | `kamailio-signaling-runtime-proof` with `no_asterisk_sip_scope=true` |

Additionally, `make repository-hygiene` **actively enforces** T1 completeness:
it fails unless `phase-status.md` marks T1 `Complete`. T1's Complete status is
therefore machine-guarded, not merely asserted.

**T1 remains unequivocally COMPLETE.**

## T2 Reconciliation

T2 (Asterisk conference execution) is recorded Complete in both the executable
roadmap and the status ledger, supported by
`docs/evidence/t2/asterisk-conference-recovery.md` (449 lines) plus the
multi-node topology sections of `multi-node-failover-readiness.md`. UI-D
consumed T2 conference reads (Conference operations route) but did not alter T2
domain authority — the UI is a renderer of canonical API state throughout.

**No roadmap statement still marks T2 incomplete**, and no genuine unresolved T2
gap was found. The only T2-adjacent anomaly is a documentation-location one, not
a status one: **all T5 evidence lives inside `docs/evidence/t2/`** and there is
no `docs/evidence/t5/` directory. `f959f00` is even titled `docs(t5): …` while
its sole file is `docs/evidence/t2/multi-node-failover-readiness.md`. This is
recorded as a filing inconsistency to be resolved by the T5 closure task; it is
**not** a T2 defect and historical files are not relocated by this audit.

**T2 remains COMPLETE.**

## T5 Reconciliation

The executable roadmap and the status ledger agree on T5's remaining work:

> "Remaining T5 exit work is the controlled live Namespace PSA
> application/admission/rejection/drift-correction/final-health proof and final
> phase-closure evidence."

| T5 criterion | Status | Evidence | Current? |
|---|---|---|---|
| Multi-RuntimeNode Asterisk topology | Complete | `multi-node-failover-readiness.md` | yes |
| Deterministic failover and restoration recovery | Complete | same | yes |
| Listener ownership, liveness, degradation, automatic recovery | Complete | same; `1e792fe`, `f90e266`, `4068362` | yes |
| Symmetric degraded/recovered events | Complete | same; `bb9d171` | yes |
| Deterministic capacity-aware placement | Complete | same; `97b56d6`, `d8a53e9` | yes |
| Pending-no-capacity and automatic retry | Complete | same | yes |
| Recovery metric-event retention with scheduled pruning | Complete | same; `3f89dc0`, `b56de3c` | yes |
| Internal resilience metrics and corrected alerts | Complete | same; `e87d1c7`, `fa0d8d3` | yes |
| Namespace PSA repository authority reconciliation | Complete | same; `2ec8fd2` | yes |
| **Namespace PSA controlled live proof** | **Complete** | same, §T5-A78; `f959f00` | **yes — the roadmap text is stale** |
| Kamailio signaling cutoff | Not applicable | re-sequenced out (`2620225`); T1 Kamailio is registration-only with no runtime dialog route | yes |
| Cross-runtime recovery | Dependency-gated | roadmap scopes it "where technically supported"; second runtime is T4 | yes |
| **Final T5 phase-closure evidence** | **Outstanding** | none | — |

No T5 criterion is duplicated elsewhere in a way that would double-count proof.

**T5 reconciled status: `IN_PROGRESS`, with exactly one remaining deliverable —
the final phase-closure evidence document.** No further implementation and no
further live runtime proof is required by the repository's own stated T5 exit
criteria.

## Namespace PSA Determination

`f959f00` (`docs(t5): prove namespace security label reconciliation`,
2026-07-21) added a 117-line section, "T5-A78 controlled live Namespace PSA
reconciliation proof", to `docs/evidence/t2/multi-node-failover-readiness.md`.
It was applied against `utcp-local`, Kubernetes `v1.35.3+k3s1`, from repository
`HEAD 2ec8fd2` with `UTCP_PHASE=T1`.

Mapped against the six-point "Live acceptance contract (deferred — do not run
now)" recorded earlier in the same document at lines 12589-12598:

| # | Contract requirement | Result |
|---|---|---|
| 1 | Apply manifests; all UTCP workloads stay Ready | **Met** — every Deployment/StatefulSet stayed Ready; the two `utcp-runtime` Asterisk pods kept baseline restart counts |
| 2 | All 5 UTCP namespaces at `restricted` + `v1.35`, incl. `utcp-runtime` `enforce-version` | **Met** — four `unchanged`, `utcp-runtime configured`; all six labels present on all five namespaces; reapply idempotent |
| 3 | Compliant Pod admitted; violating Pod rejected as `restricted:v1.35` | **Met** — `t5a78-psa-compliant` admitted and `Succeeded`; `t5a78-psa-violating` rejected with the rejection explicitly attributed to `restricted:v1.35` and never created |
| 4 | Migration/maintenance Jobs remain admissible | **Met** — migration overlay server-side dry-run `unchanged`; the Job's exact pod template passed `restricted:v1.35` enforce |
| 5 | No unrelated namespace modified; drift restored by re-apply | **Met** — system namespaces untouched; `audit-version` drift introduced and restored declaratively; second reapply idempotent; NetworkPolicy count `30` before and after |
| 6 | Final environment healthy, no disposable Pods left | **Met** — compliant Pod deleted, violating Pod never created, no manifest left applied, no port-forward left |

One divergence is recorded in the proof and is correctly classified there as
pre-existing and unrelated: the `kube-prometheus-stack-grafana`
`grafana-sc-dashboard` sidecar was in CrashLoopBackOff both before and after,
caused by the documented API-server-egress NetworkPolicy endpoint-pin drift
after a node-IP shuffle. Namespace reconciliation does not touch
NetworkPolicies.

One deliberate narrowing is recorded: the apply authority was
`kubectl apply -f infrastructure/kubernetes/security/namespaces/pod-security-labels.yaml`
— the sole file the security kustomization references for Namespace resources —
rather than the broader `make security-apply`, which would have re-rendered
API-server NetworkPolicies, restarted data/platform workloads, and rerun the
migration Job. This keeps the change scoped to Namespace reconciliation and does
**not** weaken the claim: the manifest applied is the canonical declarative
Namespace authority.

**Determination: the T5 Namespace Pod Security Admission criterion is fully
satisfied.** The proof was not rerun. Consequently the sentence "Remaining T5
exit work is the controlled live Namespace PSA … proof and final phase-closure
evidence" — present in **both** `implementation-roadmap.md` and
`phase-status.md` — is **stale**, because `f959f00` updated only the evidence
file and never the two roadmap documents.

## Hosted CI Requirement

Two workflows exist:

- `.github/workflows/repository-hygiene.yml` — `bash -n` on the script surface,
  `py_compile` on Python scripts, `make repository-hygiene`, `make workflow-check`.
- `.github/workflows/quality.yml` — quality checks.

A repository-wide search for `hosted CI`, `CI proof`, `observed and passed`, and
`CI is green` across `README.md`, `docs/`, `.github/`, `AGENTS.md`, `CLAUDE.md`,
and `Makefile` returns exactly **one** requirement:

```text
docs/roadmap/implementation-roadmap.md:284  (Phase R0 — Portfolio Release)
  Exit criteria: clean-clone setup is verified; demo contains only synthetic
  data; CI is green; no secrets are committed; …
```

**Classification: hosted CI is required only for release/publication (R0). It is
not an exit criterion for T1, T2, T3, T4, T5, V0, or any UI phase.** For those
phases, locally executed `make repository-hygiene`, `make workflow-check`, and
`make secret-scan` are the repository's own CI-equivalent authority.

Hosted CI was **not observed** for this audit, and this is not treated as either
a pass or a failure. Because the selected next target is not R0, it is not a
blocker and requires no operator action now. It becomes a genuine operator or
connected-service requirement only when R0 is attempted, since `main` is ahead 11
and unpushed, so no hosted run exists for the current tree.

## Stale or Contradictory Status Statements

| # | Location | Statement | Classification | Action |
|---|---|---|---|---|
| S1 | `README.md:19` | "T1 implementation has started but is **not complete** … `UTCP_PHASE` remains `T0`." | **CONTRADICTORY** — contradicts `versions.env` (`T1`), `phase-status.md` (T1 Complete), the `PHASE_T1_COMPLETE` evidence, and the CI guard that *requires* T1 | **Correct** |
| S2 | `README.md` "Current Status" | Narrative stops at T1-in-progress; never mentions T2, T5, or UI-A…UI-E | **STALE** (incomplete) | **Correct** |
| S3 | `docs/roadmap/phase-status.md` T5 row | "Remaining T5 exit work is limited to the controlled live Namespace PSA … proof and final T5 closure evidence." | **STALE** — the live PSA proof was delivered by `f959f00` | **Correct** |
| S4 | `docs/roadmap/implementation-roadmap.md:254` | Same claim as S3 | **STALE** — same reason | **Correct** |
| S5 | `docs/roadmap/ui-foundations.md:400-405` | Repeated "UI-E remains In Progress" | **HISTORICAL_EVIDENCE** — each is scoped to its own corridor entry ("as of UI-E2/E3/E4/E5/E6"); the authoritative `Status:` line reads Complete and the UI-E13 entry records closure | **Leave** |
| S6 | `docs/evidence/**` phase-marker mentions | Point-in-time `UTCP_PHASE` values | **HISTORICAL_EVIDENCE** — preserved per the convention recorded in the T1 evidence itself | **Leave** |
| S7 | T5 evidence filed under `docs/evidence/t2/`; no `docs/evidence/t5/` | Filing inconsistency | **DUPLICATED/misfiled location, not a status error** | Defer to T5 closure task; do not relocate history now |

Checked and found **clean** (no correction needed): no `18080`/`18443` legacy
custom-port assumptions remain in current docs (the 80/443 standard edge is
consistently described); no statement implies browser SIP was required for T1
(the T1 evidence explicitly scopes browser SIP out and the roadmap sequences it
to T3/V0); no statement confuses V0 with T1; Reverb is consistently described in
`docs/architecture/authority-boundaries.md` as "notifications only" and
explicitly "must not be the only record of a business transition"; UI-D and T1
are not listed as incomplete anywhere current.

Historical evidence documents were **not** rewritten. Only current
roadmap/summary documents were corrected, and only for contradictions this audit
directly proved.

## Remaining Dependency Graph

```text
T5 (IN_PROGRESS)
└── final phase-closure evidence          ← no predecessor; ready now
                                             no external/operator/credential dependency
                                             no browser SIP, no media/RTP, no trunk/PSTN
                                             no hosted infrastructure or AWS

T3 rtpengine browser media (PLANNED)
├── requires: ADR establishing media/rtpengine authority   ← MISSING (last ADR is 019)
├── requires: rtpengine version pin in versions.env        ← MISSING
├── requires: rtpengine Kubernetes manifests               ← MISSING (none in infrastructure/)
├── requires: explicit RTP NetworkPolicies                 ← MISSING
├── requires: Kamailio INVITE route authority consuming the
│             canonical RuntimeNode eligibility projection ← undesigned
├── blocked-by: guard scripts that currently ASSERT RTP ABSENCE —
│             scripts/security/config-check:426 fails on /rtp|rtpengine/,
│             scripts/gateway/config-check:61 fails on /rtpengine|UDPRoute/
├── needs media/RTP: yes      needs browser SIP: yes
└── needs external trunk/PSTN: no    needs hosted infra/AWS: no

V0 natural login → SIP registration → conference admission (DEPENDENCY_GATED)
└── blocked-by: T3 (must prove the registered-browser-to-conference path T3 introduces)

T4 FreeSWITCH ESL parity (DEPENDENCY_GATED)
└── blocked-by: T3 + V0 (must reproduce V0 behavior against a second runtime)

R0 portfolio release (DEPENDENCY_GATED)
└── blocked-by: remaining phases + hosted CI green (the only hosted-CI gate)
```

T3 is therefore **not** dependency-ready: it needs an unresolved architecture
decision (an ADR), a version pin, new infrastructure, and edits to three guard
scripts that presently enforce the absence of exactly what T3 introduces.

## Next Actual Repository Target

**T5 — final phase-closure evidence.**

Applying the selection standard:

1. *Earliest dependency-ready incomplete phase* — T5 is the only incomplete
   phase with zero unmet predecessors; T3/V0/T4/R0 are all gated.
2. *Authority and prerequisites clear* — the executable roadmap names the exact
   remaining deliverable; every other T5 criterion is proven and referenced.
3. *Not choosing documentation-only over a ready implementation phase* — the
   only implementation candidate, T3, is **not** dependency-ready (no ADR, no
   pin, no manifests, active guards asserting RTP absence), so the exception
   does not apply. T5 closure is also not a "documentation-only phase": it is
   the final exit artifact of a real in-flight phase, which phase discipline
   requires before advancing ("Later phases remain planned until their exit
   criteria are proven").
4. *No unresolved runtime/credential/business/architecture decision* — none for
   T5 closure.
5. *Vertical slice preference* — no end-to-end slice is available without T3;
   V0 is the next real slice and is gated.
6. *Architecture preserved* — closure changes no authority.
7. *No reopening of T1 or UI work* — none required.

## Next Action Classification

```text
EVIDENCE_ONLY_AUDIT
```

Chosen because the remaining T5 deliverable is a closure/reconciliation evidence
document assembled from proof that already exists. `BOUNDED_IMPLEMENTATION` is
wrong — there is no code seam to change. `LIVE_RUNTIME_PROOF` is wrong — the last
outstanding runtime claim (Namespace PSA) was proven by `f959f00`, and rerunning
it would duplicate completed proof. `OPERATOR_ACTION` and `DEPENDENCY_WAIT` are
wrong — no external, manual, or blocked dependency exists.

## Selected AI Coder

```text
Claude Code
```

**Reason.** The task is evidence reconciliation and phase-closure documentation
across a 12 886-line evidence corpus spanning many T5 corridors — the exact
profile `CLAUDE.md` assigns to Claude Code ("narrow evidence audits", "evidence
correlation", "repository archaeology", "authority tracing"). It requires
reading and correlating existing proof, not writing code.

**Codex is not appropriate**: `CLAUDE.md` scopes Codex to bounded repository
implementation, deterministic refactors, removal of conflicting behavior, and
automated tests. T5 closure changes no production code and adds no tests, so
there is no implementation seam for Codex to own.

**Operator is not appropriate**: no credential, provider account, business
decision, external service authorization, or production-only access is involved.
Hosted CI — the one genuine connected-service item — is an R0 gate, not a T5 gate.

## Exact Recommended Roadmap Corrections

Applied by this audit (each directly proven above):

1. `docs/roadmap/phase-status.md` — T5 row: replace the claim that the
   controlled live Namespace PSA proof remains outstanding with the fact that
   `f959f00` proved it, leaving final phase-closure evidence as the sole
   remaining T5 exit item. **T5 stays `In Progress`.**
2. `docs/roadmap/implementation-roadmap.md` — T5 section: the same correction.
3. `README.md` "Current Status" — replace the stale
   "T1 … not complete … `UTCP_PHASE` remains `T0`" paragraph with an accurate
   summary: T1 and T2 complete, UI-A…UI-E complete, T5 in progress pending
   closure evidence, T3/V0/T4 planned, and `UTCP_PHASE=T1` held deliberately as
   the CI-guarded completed-phase marker.

Deliberately **not** applied:

- Marking T5 `Complete` — that is the next task's deliverable, and this audit
  reviewed the roadmap's *stated* remaining work rather than performing the full
  closure review.
- Any change to `versions.env` (see below).
- Any change to `docs/roadmap/ui-foundations.md` corridor history (S5) or to any
  file under `docs/evidence/` other than adding this new document.
- Relocating T5 evidence out of `docs/evidence/t2/` (S7) — deferred to closure.

## Phase-Marker Recommendation

**Keep `UTCP_PHASE=T1` unchanged for now.** The value is CI-enforced: six guard
scripts pin it, and no guard accepts `T2`, so any advance is a coordinated
multi-file behavioral change, not documentation reconciliation.

Recommended sequencing when an advance is eventually wanted: complete T5 closure
first, then perform one bounded implementation that (a) updates every guard's
accepted value, (b) updates `phase-status.md` and `CLAUDE.md`'s marker guidance,
and (c) verifies `make repository-hygiene` and hosted CI in the same commit.
Alternatively, keep the conservative pin and document explicitly that the marker
tracks the last *fully closed* T-phase rather than the furthest completed one.
Either is defensible; the change must not be made incidentally.

## Explicit Exclusions

This audit did not modify production code, dependencies, Kubernetes manifests,
runtime configuration, or the phase marker; did not run Kubernetes apply, rebuild
images, or restart workloads; did not run Playwright MCP; did not repeat T1
runtime, UI-A…UI-E, accessibility, focus, pagination, responsive, contrast,
UI-D, or browser proofs; did not rerun the Namespace PSA proof; did not use the
network or GitHub; and did not begin implementation of the selected next target.
