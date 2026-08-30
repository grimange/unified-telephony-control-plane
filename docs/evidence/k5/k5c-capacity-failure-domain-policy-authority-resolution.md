# K5C — Capacity and Failure-Domain Policy Authority Resolution

Current-State-Impact: no

## Result

`K5C_CAPACITY_FAILURE_DOMAIN_POLICY_AUTHORITY_RESOLVED`

`K5C_READY_FOR_BOUNDED_IMPLEMENTATION`

This resolution records the durable policy required before K5C product
implementation. K5C remains **NEXT / NOT STARTED**. No production code,
schema, SQL view, frontend, Kubernetes manifest, worker, runtime state, or
cluster state changed.

## Starting identity and resolved audit verdict

Starting HEAD: `2e59674dea00f22a39317712b01b8f339ae4bdff`

The preceding audit verdict was `K5C_POLICY_AUTHORITY_GAP_ISOLATED`: the
repository had proven conference capacity mechanics and three known selection
seams, but had not durably defined canonical telephony load, shared capacity
semantics, or desired-versus-observed failure-domain policy. This document and
the dated K5C amendment in ADR-024 resolve those policy gaps without repeating
the audit or implementing K5C.

## Durable decisions

### Canonical work and capacity

Canonical active telephony work is:

```text
active conference runtime bindings
+ non-terminal CallLegs assigned to the RuntimeNode
```

Non-terminal means the canonical observed `CallState` is not in
`CallState::terminal()`. The same predicate governs the future meaning of
work remaining for retirement and drain convergence; K5D is not implemented.

`runtime_nodes.capacity_weight` is the sole capacity authority. It is one
shared RuntimeNode-wide count budget across conference and call work:

```text
capacity_weight = 0 -> unlimited
otherwise eligible when active telephony work < capacity_weight
```

Capacity affects new-work admission only. It does not terminate existing
Calls or CallLegs, remove conference bindings, force draining, or evict Pods.
The existing deterministic lexicographic placement tuple remains authoritative
(`placement_priority`, available capacity, absolute active load, stable
RuntimeNode identity), with recount-after-lock before reservation where the
lifecycle provides that seam. Explicit requests cannot bypass eligibility.
No workload-class budgets, fields, coefficients, thresholds, or weighted
scoring were added.

### Failure-domain authority and matching

`placement_region` and `placement_zone` are UTCP desired exact hard
constraints, stored through the authenticated Web Admin/application API in
canonical PostgreSQL desired state. Kubernetes region/zone labels, hostname,
Node identity, and Pod placement are observed factual authority. Desired
fields never overwrite or masquerade as those observations.

No configured constraint means no failure-domain restriction and missing
region/zone has no K5C penalty. A configured region and/or zone must exactly
match currently observed, unambiguous Kubernetes values. Missing, unknown,
unavailable, or ambiguous required topology is ineligible for new automatic
telephony work, with an observable attributable constraint failure and no
silent fallback. No region/zone preference or failure-domain scoring exists;
hostname is not substituted for geography.

K5B placement-state treatment is preserved: `placed` is evaluated normally;
unmanaged or not-currently-observed identity remains eligible under existing
non-K5C rules without constraints but is ineligible when a configured
constraint cannot be proven; ambiguous multi-Node placement is not resolved by
age/order; and Kubernetes observation outages do not rewrite RuntimeNode
readiness without a configured constraint.

### Selection and observation projection boundaries

The three existing authorities remain the only selection corridors:

1. conference — `TelephonyDomainService`;
2. outbound — `RuntimeNodeSelector::selectForOutboundCall()`; and
3. inbound — `kamailio_inbound_runtime_target_view`.

K5C extends them semantically and does not add a selector. ADR-027 §§11–12
remain the eligibility and selection/reservation cross-reference, including
applicable configuration/image-convergence rules.

Inbound topology inputs may be exposed through an automatic observer-derived
PostgreSQL observation projection for SQL selection. It is non-canonical,
operator read-only, automatically refreshed, subordinate to Kubernetes facts,
and explicitly current/unknown/unavailable. It cannot become durable Host or
Node authority, and it cannot introduce manual placement sync or topology
editing.

The K5C shared capacity budget applies to inbound selection. If no inbound
candidate has capacity, the established canonical unavailable response may be
returned; this is intentional K5C admission policy, not a regression to mask.

Kubernetes Node readiness remains outside `RuntimeNode.observed_state`
authority. ADR-032's Web Admin -> authenticated API -> PostgreSQL desired state
-> automatic selection/reconciliation path remains the sole management path;
Artisan gains no routine K5C commands.

## Roadmap and next seam

Current state is unchanged: K5A is **COMPLETE**, K5B is **COMPLETE /
NATURAL-LIVE-PROVEN**, K5C is **NEXT / NOT STARTED**, and K5D/K5E remain not
started. The stale current-looking K5B pending-proof prose in the phase ledger
and implementation roadmap was corrected; historical evidence was not edited.

The next task is a bounded K5C implementation extending the three existing
selection seams and adding only the minimal automatic observed-topology
projection needed by the inbound SQL view. Its policy authority gap is now
`NONE`. Selected next AI coder: `Codex`.

Current-State Ledger Impact: NO_CHANGE_REQUIRED
