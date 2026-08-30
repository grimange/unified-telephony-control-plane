# K5C — Capacity and Failure-Domain Policy Authority Audit

Current-State-Impact: no

Date: 2026-08-30

Starting HEAD: `e20a699b03b97ad5abf3025659a82b72796f02f1`
(`docs(k5): close telephony placement live proof`)

Task mode: narrow evidence-only audit. No production source, schema, manifest,
RuntimeNode state, or cluster state was modified. Nothing was deployed. No
browser proof and no live call traffic were used.

## Verdict

`K5C_POLICY_AUTHORITY_GAP_ISOLATED`

The **capacity** half of K5C is substantially determined by existing repository
authority and can be implemented by reuse rather than invention. The
**failure-domain** half is not: `placement_region` and `placement_zone` exist as
operator-writable canonical fields with **zero consumers and no defined
constraint semantics**, and no ADR, roadmap contract, or implementation resolves
their precedence against the Kubernetes-observed topology that K5B already
surfaces. Three bounded policy questions must be answered before K5C is
implementable without inventing selection policy.

## Question this audit answers

> Can Codex implement K5C without inventing new routing/selection policy?

Not yet. Two of the three blocking questions are narrow; one is a genuine
authority decision.

## Canonical K5C contract

The only definition-bearing K5C statement in the repository is
`docs/roadmap/implementation-roadmap.md:225`:

> **K5C — Capacity and Failure-Domain Policy:** combine Kubernetes facts with
> RuntimeNode readiness, declared capability, telephony load, capacity, and
> failure-domain constraints to determine telephony eligibility and selection.
> UTCP must not reimplement the Kubernetes scheduler.

`ADR-024` — the governing K5 ADR — **does not mention K5C at all**. The K5
subphase decomposition lives only in `docs/roadmap/implementation-roadmap.md`
and `docs/roadmap/phase-status.md`.

The binding authority statement for K5C is `ADR-027` §12:

> K5C extends the *ordering inputs* of the same projection; it does not change
> who holds the authority, so the V1 contract survives unchanged.
> `capacity_weight` already exists and is deliberately left unused by V1 to
> avoid pulling capacity policy forward.

This is decisive and constrains K5C in two ways:

1. K5C **must not** introduce a new selection authority. It adds ordering inputs
   to the three existing seams.
2. `capacity_weight` is the **already-reserved** capacity input. K5C does not
   need to introduce a capacity field, and introducing one would contradict
   ADR-027.

## The three selection seams K5C must extend

| # | Corridor | Seam | Ordering today | Capacity applied |
| --- | --- | --- | --- | --- |
| 1 | Conference binding | `TelephonyDomainService` (PHP, ~1555–1700) | `placement_priority`, `-availableSlotRank`, `active_binding_count`, `id` | **Yes** |
| 2 | Outbound call | `RuntimeNodeSelector::selectForOutboundCall()` (PHP) | `placement_priority`, `id` | No — deliberately |
| 3 | Inbound call | `kamailio_inbound_runtime_target_view` (SQL view) | `placement_priority`, `runtime_node_id` | No |

Seam 3 is a PostgreSQL view consumed directly by Kamailio
(`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml:309`). Any K5C
ordering input on the inbound corridor must be expressible in SQL, because
Kamailio selects `[0,*]` — the first row — with no application code in the path.

## Capacity authority — RESOLVED

The conference selector is a complete, regression-locked capacity policy that
K5C can reuse verbatim.

**Capacity representation.** `runtime_nodes.capacity_weight`,
`unsignedInteger` default `100`
(`2026_07_14_130000_create_runtime_registry_tables.php:31`), operator-writable
through the Admin API as `['sometimes','integer','min:0','max:100000']`
(`AdminRuntimeNodeController.php:87,329`), serialized under `placement`
(`RuntimeRegistryService.php:1144–1149`). Scope is the RuntimeNode, which is an
existing ADR-032-compliant management scope. **No new configuration scope is
required.**

**Capacity semantics.** `runtimeNodeHasConferenceCapacity()`:
`capacity === 0 || activeBindingCount < capacity`. Zero means unlimited;
otherwise it is a **hard eligibility cutoff**, not a soft weight. Despite the
field name, it is a count limit, not a proportional weight.

**Ordering rule.** `conferenceAvailableSlotRank()` returns `PHP_INT_MAX` for
unlimited, else `max(0, capacity - activeBindingCount)`. The sort tuple is
lexicographic — priority, then most free slots, then least absolute load, then
stable id. There is **no weighted score and no tunable coefficient**, which is
exactly what the packet forbids inventing.

**Regression lock.** `TelephonyDomainTest` covers deterministic ranking,
exclusion of full/non-ready/degraded/missing-capability/other-tenant nodes,
explicit-request bypass prevention, retry after slot release, and recount after
lock. K5C inherits proven semantics.

**Conclusion.** Capacity representation, semantics, ordering, and configuration
scope are all established. K5C does not invent them.

## Telephony load authority — PARTIALLY RESOLVED

Capacity works for conferences because `conference_runtime_bindings` is a
canonical persisted per-node workload table with a `status = 'active'`
predicate. The `RuntimeNodeSelector` docblock states the call-side position
explicitly:

> Calls have no canonical binding/workload table, so conference capacity is not
> applied here.

This is accurate as to a *table*, but a canonical per-node call load fact is
nevertheless **derivable from existing canonical artifacts without invention**:

* `call_legs.runtime_node_id` — FK to `runtime_nodes`
  (`2026_08_16_100000_create_c6_call_tables.php:45,71`).
* `CallState::terminal()` — canonical terminal set
  `{completed, failed, cancelled}` (`app/TelephonyDomain/CallState.php`).

An active call leg is therefore a leg whose `observed_state` is not terminal.
Both inputs are canonical and already authoritative, and the predicate is
expressible in SQL for seam 3. No new state vocabulary is needed.

What is **not** determined is whether that derived count is the canonical
telephony load fact, because of the conflict recorded next.

## Blocking gap 1 — `activeBindingCount()` excludes call legs

`RuntimeRegistryService::activeBindingCount()` (lines 853–860) is the canonical
"does this node still carry work" predicate. It counts **only**
`conference_runtime_bindings`. It gates:

* retirement — `'Runtime node cannot be retired while active runtime bindings exist.'` (line 187–188)
* runtime-family/adapter identity change (line 864)

A RuntimeNode carrying live external call legs and zero conference bindings
therefore currently reports zero work. K5C cannot define telephony load without
deciding whether this predicate becomes call-aware, because K5D's
`ACTIVE -> DRAINING -> DRAINED` convergence depends on the same notion of
"work remaining". Answering it inside K5C changes retirement and drain
behavior; answering it inconsistently creates two competing definitions of
telephony load.

This is a bounded authority decision, not a scoring algorithm.

## Blocking gap 2 — shared or separate capacity budget

`capacity_weight` is a single scalar per RuntimeNode. If K5C applies it to the
call corridors, no repository authority states whether the budget is:

* **shared** — conference bindings and active call legs consume one budget; or
* **separate** — the same integer is independently applied per workload class.

The behavioral difference is material: under a shared budget a node at
`capacity_weight = 100` holding 100 conference bindings becomes ineligible for
**inbound external calls**, changing the ADR-027 admission contract and the
`503 Inbound Runtime Unavailable` failure surface. Nothing in ADR-027, ADR-024,
or the roadmap resolves this. It cannot be inferred from the conference
implementation, which never had a second workload class.

## Blocking gap 3 — failure-domain policy is undefined (primary gap)

`placement_region` and `placement_zone` (`string(80)` nullable, migration lines
28–29) are operator-writable through the Admin API
(`AdminRuntimeNodeController.php:84–85,326–327`), persisted
(`RuntimeRegistryService.php:67–68,155–156`), and serialized
(`RuntimeRegistryService.php:1145–1146`).

They have **no consumers.** A repo-wide search returns only validation,
persistence, and serialization — no selection, eligibility, ordering, or
constraint logic anywhere.

Three sub-questions are unresolved, and each requires inventing policy:

1. **Which source is authoritative?** K5B already exposes
   Kubernetes-*observed* topology — `topology.kubernetes.io/region`,
   `topology.kubernetes.io/zone`, `kubernetes.io/hostname` — through
   `KubernetesHostVisibilityService::hostFacts()`. UTCP also holds
   operator-*declared* `placement_region`/`placement_zone`. No authority states
   precedence, reconciliation, or conflict behavior between the two. Choosing
   observed topology as authoritative would also contradict the K5B boundary
   recorded below.

2. **What constraint do they impose?** The roadmap says "failure-domain
   constraints to determine telephony eligibility and selection" but defines no
   rule. Anti-affinity, spread, preference, and hard exclusion are all
   consistent with that sentence and produce different selections.

3. **Constraint over what unit?** Conference-participant co-residence, call-leg
   pair co-residence, and per-tenant domain diversity are distinct policies. No
   repository artifact expresses any of them.

Unlike gaps 1 and 2, this gap has no partial precedent to reuse. Implementing it
today would mean inventing routing policy, which the packet forbids.

## Readiness authority — RESOLVED, no duplication risk

The packet asked whether reconciliation already converts Kubernetes host health
into RuntimeNode readiness. **It does not, and K5C must not add it.**

* `AsteriskRuntimeNodeReconciler` contains no Kubernetes, host, or node input;
  it derives `observed_state` only from adapter observation.
* `KubernetesHostVisibilityService` is consumed by exactly two read-only
  controllers — `AdminRuntimeNodeController::placement()` and
  `AdminKubernetesHostController::index()`. It never writes `observed_state`,
  never feeds selection, and holds no canonical state.

`observed_state` authority is therefore runtime-adapter observation only. K5C
should consume Kubernetes facts as *ordering inputs* per ADR-027 §12 and must
not let Node readiness become a second writer of RuntimeNode readiness. This
preserves ADR-024's boundary and the "must not reimplement the Kubernetes
scheduler" constraint.

## Eligibility gating — RESOLVED

Drain and retirement already gate eligibility with no K5C work required. All
three seams require `desired_state = 'active'`, so `draining`, `drained`,
`disabled`, `draft`, and `retired` are excluded from new work. This matches the
ADR-027 §11 table exactly.

One divergence in *strictness* exists between the seams and is material to K5C:

| Eligibility condition | Seam 1 conference | Seam 2 outbound | Seam 3 inbound |
| --- | --- | --- | --- |
| tenant scope | yes | yes | yes |
| `desired_state = 'active'` | yes | yes | yes |
| `observed_state = 'ready'` | yes | yes | yes |
| required capability | `conference.*` | `call.control` | `call.control` |
| configuration converged | no | **no** | **yes** |
| execution image digest current | no | **no** | **yes** |
| capacity | **yes** | no | no |

Seam 3 enforces the full ADR-027 §11 rule; seam 2 does not. K5C adds ordering
inputs to seams that do not currently agree on eligibility. Whether K5C should
also converge that strictness is a legitimate scope question for the
implementation packet — it is **not** a policy invention, since ADR-027 §11
already states the stricter rule.

## What K5C can reuse without inventing policy

* `capacity_weight` — representation, range, default, operator scope, and the
  ADR-027 §12 reservation.
* `capacity === 0 || load < capacity` — hard-cutoff semantics.
* The four-key lexicographic ordering tuple, already regression-locked.
* `lockForUpdate()` recount-after-lock and next-candidate continuation.
* Explicit-request-cannot-bypass-eligibility behavior, proven on both seam 1 and
  seam 2.
* `CallState::terminal()` and `call_legs.runtime_node_id` as call load inputs.
* Drain/retire gating and K5B read-only placement facts.

## What must be decided before implementation

1. Does the canonical "node carries work" predicate become call-aware, and does
   that change retirement and drain convergence?
2. Is `capacity_weight` a shared budget across conference bindings and call
   legs, or applied separately per workload class?
3. What failure-domain rule do `placement_region`/`placement_zone` express, is
   the operator-declared or Kubernetes-observed value authoritative, and over
   what unit does the constraint apply?

Question 3 is the primary blocker. Questions 1 and 2 are narrow and could be
resolved by an ADR amendment or an explicit decision in the implementation
packet.

## Recommended correction

A bounded ADR addition — extending ADR-024 or ADR-027 §12, both of which already
own this boundary — answering exactly the three questions above. ADR-024 is the
better host because it governs the K5 track and currently says nothing about
K5C. No new configuration scope, no new capacity field, and no new selection
authority are needed.

With those three answers recorded, K5C becomes a bounded implementation:
extend the existing ordering inputs on three known seams, reusing an
already-proven capacity rule.

## Observed documentation inconsistency

`docs/roadmap/phase-status.md:176–178` still reads "K5A is complete and K5B
placement awareness is implemented and tested; K5B natural native-k3s live proof
is pending", contradicting lines 25–27 where K5B is recorded complete with its
2026-08-30 live proof. `docs/roadmap/implementation-roadmap.md:266–267` carries
the same stale text. This is historical residue from before the K5B proof, not a
current-state claim, and it does not affect the exactly-one-next-action.
Correcting it is deferred to a separate bounded documentation packet; this
evidence-only audit left both files unmodified.

## Boundaries preserved

No application code, schema, migration, API, frontend, Kubernetes manifest,
RuntimeNode state, or cluster state was modified. Nothing was deployed. No
scoring algorithm, weight, threshold, failure-domain rule, or configuration
scope was invented. K5C remains the exactly-one-next-action and was not
advanced.
