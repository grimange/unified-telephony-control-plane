# K5C Capacity / Failure-Domain Policy Implementation

Date: 2026-08-30

Verdict: `K5C_CAPACITY_FAILURE_DOMAIN_POLICY_IMPLEMENTED_AND_TESTED`

Starting HEAD: `f18086f7686d18664cd074c29bf062b5f82e0dba`

Current-State-Impact: yes

## Authority and scope

The implementation follows the resolved K5C policy in ADR-024 and its
ADR-027 selection cross-reference. K5C remains a new-work admission and
selection policy. It does not implement K5D maintenance, K5E multi-host live
proof, or K5F enrollment. No policy audit was repeated in this packet.

The three existing selection authorities remain the only corridors:

* conference selection in `TelephonyDomainService`;
* outbound selection in `RuntimeNodeSelector::selectForOutboundCall()`;
* inbound selection in `kamailio_inbound_runtime_target_view`.

## Active work and capacity

`RuntimeNodeWorkloadService` defines RuntimeNode active telephony work as
active conference runtime bindings plus CallLegs assigned to the node whose
canonical observed `CallState` is not terminal. Conference-only callers remain
conference-only; lifecycle safety and drain remaining-work checks use the
unified predicate. Thus a node with zero conference bindings and one active
CallLeg still carries work, while terminal CallLegs do not consume capacity.

`RuntimeNodeCapacityEvaluator` makes `runtime_nodes.capacity_weight` one shared
RuntimeNode-wide budget. Zero is unlimited; otherwise a node is eligible only
when active work is less than capacity. Available capacity is the existing
unlimited rank or `max(0, capacity - active work)`. Ordering is deterministic
and lexicographic: placement priority, available capacity, absolute active
load, then RuntimeNode identity. No workload-class budgets or weighted score
were added.

The conference selector uses the shared evaluator with its existing locking
and recount seam. Outbound selection applies the same capacity check and does
not let an explicit RuntimeNode bypass it. Inbound SQL calculates the same
binding-plus-CallLeg load and excludes full candidates before its stable order.

## Desired constraints and observed topology

`placement_region` and `placement_zone` remain UTCP desired exact hard
constraints. Kubernetes region, zone, hostname, Node identity, and Pod
placement remain factual observed authority. With no configured constraint,
missing Kubernetes topology has no K5C penalty. With a configured constraint,
missing, unknown, unavailable, ambiguous, or mismatching required topology is
ineligible for new automatic work. No region/zone scoring, co-residence
policy, CPU/memory score, or Kubernetes scheduler mutation was introduced.

`K5CPlacementObservationService` automatically projects the minimal observed
facts needed by the inbound SQL view into the derived
`runtime_node_k5c_placement_observations` table. The projection stores
RuntimeNode/tenant identity, observation status, observed Node UID/name,
region, zone, hostname, and observation time. It is subordinate to Kubernetes,
read-only to operators, idempotently refreshed by the existing scheduled
Artisan scheduler path, and never overwrites desired placement or
`RuntimeNode.observed_state`. Observation states preserve K5B behavior for
placed, no managed identity, identity not currently observed, ambiguous
multiple Nodes, and Kubernetes observation unavailable.

The inbound view consumes only this derived projection. It remains the
Kamailio selection authority and exposes no durable Host/Node management
authority. No RBAC widening, manual topology editing, manual reconciliation,
Artisan management interface, or new configuration scope was added. Existing
Admin/API RuntimeNode controls remain the normal management path.

## Verification and remaining proof

Focused K5C unit coverage covers shared capacity, unlimited capacity, release,
exact region/zone constraints, missing and unavailable topology, external
RuntimeNode compatibility, ambiguous observation rejection, canonical terminal
CallState SQL parity, and inbound projection ordering/content. Existing
conference, runtime lifecycle, call, and Kubernetes visibility regressions
remain part of the backend suite; the lifecycle helper now uses the same
active-work predicate for retirement/drain safety.

Natural native-k3s acceptance is intentionally not performed here. It is the
next controlled proof for capacity exhaustion/release, desired topology match
and mismatch, and automatic behavior across all three corridors. K5C is
implemented and tested; K5D remains not started.
