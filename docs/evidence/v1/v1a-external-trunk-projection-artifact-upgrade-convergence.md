# V1-A External-Trunk Projection Artifact Upgrade Convergence

Date: 2026-08-28

## Bounded finding

The preceding `d913e9ea` route/query repair established the UUID-compatible
projection join and explicit `destination_user` predicate. The subsequent
native-k3s proof showed that an existing persisted T6 artifact still had the
old `utcp.t6.projection.v1` shape, so its full canonical SIP address was
present while `destination_user` was `NULL`. The repaired route view therefore
failed closed with zero selected rows before provider signaling.

## Deployment-time repair

Normal `ExternalTrunkProjectionService` output is now explicitly
`utcp.t6.projection.v2`, with the complete normalized address preserved and
the derived `destination_user` field present for every route. A new forward
Laravel migration upgrades persisted Kamailio and Asterisk T6 artifacts from
v1 (or from a route shape missing the field) during the normal application
upgrade. It performs only the bounded historical artifact transformation:
canonical SIP/SIPS user extraction, stable JSON re-encoding, and artifact-hash
recalculation. It does not emit C7A/C7B events, reprojection commands, or
provider-registration side effects, and it preserves `desired_generation` and
removed state.

## Repository proof

Feature regressions start with an actual pre-upgrade stored artifact and prove
v1-to-v2 migration, destination-user derivation, full-URI preservation,
integrity-hash correctness, idempotence, removed-artifact handling, and
convergence without a business event. The PostgreSQL T6 regression runs the
upgrade against a stale artifact and then proves the unchanged route-view
contract selects exactly one outbound row while wrong endpoint, destination,
direction, inactive, and `accept_new_calls = false` cases remain fail-closed.
Fresh writer output and migrated v1 route representation are asserted to have
the same v2 semantic shape.

This is repository implementation evidence. The next controlled live proof
must deploy the new exact-head immutable artifact and re-prove provider-bound
INVITE, any natural authentication challenge/retry, final provider response,
and canonical Call/CallLeg observation. RuntimeNode placement and SIP failure
detail projection remain deferred closure-audit items.
