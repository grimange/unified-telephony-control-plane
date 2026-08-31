# K5C Inbound Capacity and Policy Clear Live-Defect Repair

- Date: 2026-08-31
- Starting HEAD: `55b53f576cadfc39991d12d36a28036c836bc3f8`
- Scope: bounded implementation repair for live defects C1, C2, D, and E
Current-State-Impact: yes

## Defects resolved

The preceding natural K5C proof established four implementation defects without
changing K5C policy. The inbound projection exposed shared active telephony
load and available capacity but did not exclude finite-capacity full rows (C1).
The Kamailio consumer then re-ordered the projection by only placement priority
and RuntimeNode identity, discarding the K5C capacity/load tie-breakers (C2).
`RuntimeRegistryService::updateNode()` used null-coalescing for nullable desired
region and zone, so explicit null could not clear a constraint (D). Finally,
the Web Admin edit form retained local policy values after a successful save,
allowing a later save to replay stale values (E).

## Repairs

- A forward migration, `2026_08_31_100000_repair_k5c_inbound_capacity_and_ordering`,
  replaces the applied K5C inbound view definition. It preserves the existing
  six-column projection and adds the shared predicate `capacity_weight = 0 or
  active_telephony_work < capacity_weight`. Its rollback restores the immediately
  previous K5C view shape and ordering without the new predicate.
- The inbound view continues to calculate active telephony work as active
  conference runtime bindings plus non-terminal CallLegs. Zero capacity remains
  unlimited, terminal CallLegs release load, and the view ordering remains
  placement priority ascending, available capacity descending, active load
  ascending, and stable RuntimeNode identity ascending.
- Kamailio now selects the view's capacity/load columns and consumes that full
  canonical ordering tuple. No policy logic or fourth selector was added.
- Nullable `placement_region` and `placement_zone` updates now use explicit
  key-presence semantics: an absent key preserves the current value and an
  explicitly supplied null persists a clear.
- RuntimeNode policy edit state is reseeded from the canonical RuntimeNode in
  the successful update response after detail state is cleared. Subsequent
  policy saves therefore cannot replay stale capacity, priority, region, or
  zone values.

## Authority and boundaries preserved

The existing RuntimeNode update API remains the management authority. The
shared `runtime_nodes.capacity_weight` budget, exact desired topology
constraints, Kubernetes factual observation boundary, RuntimeNode readiness
authority, conference and outbound selectors, and inbound SQL projection
authority are unchanged. No manual clear endpoint, reconciliation command,
alternate selector, K5D behavior, reporting work, or live deployment was added.

## Validation target

Focused coverage verifies the forward SQL contract and Kamailio consumer order,
nullable clear-versus-absent behavior, and canonical-response form reseeding.
The remaining acceptance gap is natural native-k3s reproof of the repaired
inbound capacity, ordering, constraint-clear, and stale-form corridors.

The next action is to deploy repaired current `main` to canonical native k3s and
rerun the existing controlled K5C natural acceptance. K5C is not complete.
