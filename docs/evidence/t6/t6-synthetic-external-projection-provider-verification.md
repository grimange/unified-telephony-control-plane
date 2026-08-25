# T6 — synthetic external projection / provider verification proof

Date: 2026-08-24

## Verdict

```text
T6_SYNTHETIC_PROVIDER_VERIFICATION_FOUND_PRODUCT_DEFECT
```

The canonical trigger chain works and is automatic, artifacts are deterministic
and idempotent, lifecycle cutoff and restoration work, and no credential
plaintext is exposed. But **no provider-facing representation and no provider
verification seam exist**, so the authoritative synthetic provider verification
cannot be performed at all. Two concrete content defects were also isolated.

This record does not amend
`t6-external-trunk-and-live-route-projection-implementation.md`; that remains
the repository-side implementation evidence.

## Repository state

```text
branch   main
HEAD     234d8ae30b82f1eb7b9ab2ad0bf9703e3ac684f2   (start and end)
start    dirty — 24 modified, 27 untracked (C7A/C7B/T6 packet)
end      same, plus this evidence file
commit   none
push     not pushed
```

Pre-existing dirty work was left untouched.

## Authoritative T6 proof requirement

`docs/roadmap/implementation-roadmap.md` (T6 section) states the objective is to
project canonical trunks, addresses, routes, caller identity, and destination
decisions **through Kamailio and selected runtime adapters using synthetic
external peers**, and lists exit criteria:

```text
canonical projection is deterministic and idempotent
inbound and outbound synthetic SIP paths are observable
failures are explicit
runtime and signaling adapters remain provider-neutral
audit and cleanup are proven
```

Deferred to V1: real bidirectional external call routing, an external SIP peer
fixture, and carrier interoperability. A commercial carrier, public DID, public
DNS, and production credentials are explicitly out of scope for T6.

So T6 requires a provider-facing representation that a provider boundary
accepts — not merely a canonical snapshot row.

## Automated baseline

```text
php artisan test --filter='T6ExternalTrunkProjectionTest|C7aAuthorityTest|C7bRouteAuthorityTest'
7 passed (99 assertions)
```

## Deployment

The live runtime was initially stale — every C7A/C7B/T6 table was absent
(`DEPLOYMENT_DEFECT`). Converged through the canonical lifecycle only
(`make k8s-image-build`, `make k8s-image-push`, `make k8s-apply`); the
`utcp-migrate` job completed and all tables became present. Deployed code was
then hash-verified against the repository:

```text
ExternalTrunkProjectionService.php  c06aaf80...  repo == control-plane-outbox-dispatcher
OutboxDispatcher.php                56867d54...  repo == control-plane-outbox-dispatcher
```

## Canonical projection trigger — PASS

Synthetic tenant authority created entirely through the canonical admin API
(no manual rows):

```text
telephony addresses  +1339555xxxx (inbound/both), +1339555xxxx (outbound)
external trunk       5ca302f7-...  slug t6syn20260824
credential reference version 1 (secret supplied, never echoed)
trunk endpoint       sip:peer.t6syn.invalid:5060, udp, authentication_mode=credentials
caller identity      8bee145a-...  + caller-identity policy on the trunk
inbound route        0cd9a6d8-...  destination_ref opaque:t6-synthetic-destination
outbound route       49f3775f-...  caller identity bound
```

```text
11:36:17  POST /api/v1/admin/external-trunks/{trunk}/desired-state {"active"}
11:36:18  external_trunk_projection_artifacts: 2 rows projected
            kamailio  desired=active gen=6 hash=171a3c8c...
            asterisk  desired=active gen=6 hash=47d3fabe...
```

One second, fully automatic, via outbox -> OutboxDispatcher ->
T6ProjectionDispatcher -> ExternalTrunkProjectionService.

## Kamailio verification — FAIL (no seam)

```text
canonical input        ExternalTrunk + endpoints + addresses + routes
provider projection    a row in external_trunk_projection_artifacts whose
                       artifact is schema "utcp.t6.projection.v1"
verification mechanism NONE AVAILABLE
provider result        N/A
```

The projected Kamailio artifact is a canonical JSON snapshot. It contains no
Kamailio-consumable material — no config fragment, no `dispatcher`/`domain`/
`uacreg`/`address` rows, and no database view.

The repository does have an established Kamailio consumption pattern and an
established acceptance seam, neither of which T6 uses:

```text
consumption precedent  kamailio.cfg sqlops + kamailio_signaling_auth_view
                       and kamailio_conference_route_view (ADR-022)
acceptance seam        scripts/kamailio-signaling/config-check:111
                       docker run --entrypoint /usr/sbin/kamailio ... -c -f kamailio.cfg
```

A repo-wide search shows `external_trunk_projection_artifacts` is referenced
only by the projection service itself, its migration, and its test. Nothing
consumes it.

## Asterisk verification — FAIL (no seam)

```text
canonical input        same canonical authority
provider projection    the same JSON, differing only by "provider" and the
                       provider_local_trunk_id prefix
verification mechanism NONE AVAILABLE
provider result        N/A
```

No PJSIP material (`ps_endpoints` / `ps_aors` / `ps_auths` / `ps_identify`), no
realtime seam, no rendered configuration, no parser validation. The two provider
artifacts are structurally identical, which is itself evidence that no
provider-specific rendering occurs.

## Defect A — canonical route identity is lost

The artifact's `routes[].route_id` is the **telephony address id**, not the
canonical C7B route id:

```text
canonical outbound_routes.id   49f3775f-b85c-4df8-aeb7-c3556e0f4563
artifact outbound route_id     5826f30e-...  (== telephony_address_id)
canonical inbound_routes.id    0cd9a6d8-22b3-4309-aab6-4ce78fd80828
artifact inbound route_id      4f3e6fca-...  (== telephony_address_id)
```

Mechanism: `ExternalTrunkProjectionService::routes()` joins
`telephony_addresses as a` onto `inbound_routes as r` / `outbound_routes as r`
with no explicit `select()`. Both tables expose `id`, so the joined address `id`
overwrites the route `id` and `$route->id` returns the address id.

Consequence: RouteDecision preservation and inbound canonicalization correlation
cannot be satisfied from the artifact, because the canonical route can no longer
be identified. The focused test does not catch this — it asserts `route_id` only
on the execution-intent path, which receives the id as an argument.

## Defect B — credential reference is dropped by rotation

```text
before rotation   endpoint credential_reference_id 96041ddf... version 1
                  artifact cred_ref=96041ddf... cred_ver=1
rotate credential through the canonical C7A path
after rotation    trunk_credential_references now holds only dff35f5a... version 2
                  trunk_endpoints.credential_reference_id still 96041ddf...  (dangling)
                  artifact cred_ref=null cred_ver=null
```

`render()` resolves credentials by the endpoint's `credential_reference_id`
filtered to `status='active'`; the stale id matches nothing, so an
`authentication_mode=credentials` endpoint is projected with **no credential
linkage at all**. The implementation evidence claims rotation "is handled by the
same tenant reprojection path" — reprojection does occur, but it produces a
worse artifact.

## RouteDecision preservation — PARTIAL

Direction, priority, destination reference, caller identity, and the selected
trunk are preserved, and T6 does not re-evaluate routes or select another trunk.
But the selected route's canonical identity is not preserved (Defect A).

## Inbound canonicalization readiness — PARTIAL

The artifact does carry enough to map a provider signaling context to the
canonical trunk and address:

```text
provider_local_trunk_id  utcp-kamailio-5ca302f7ecea4490a94437ea1203626f
external_trunk_id        5ca302f7-...
addresses[].normalized_value + telephony_address_id
```

Stable identity derives from the canonical trunk UUID, not the mutable name or
slug. But the inbound route itself cannot be identified (Defect A), and there is
no provider-facing representation in which this correlation could actually be
used.

## Credential safety — PASS

```text
plaintext secret in artifacts        no
"secret" / "password" / "ha1"        no
credential create/rotate API response returns fingerprint + version only
```

Version 1 was projected correctly as reference id + version before rotation.

## Idempotency — PASS

Three consecutive reprojections triggered by non-projected canonical changes:

```text
kamailio hash 171a3c8cef634d87...  unchanged
asterisk hash 47d3fabe93b6fade...  unchanged
rows                               2 (no duplicates)
desired_generation                 6 -> 9 (tracks canonical version)
```

## Lifecycle cutoff — PASS

```text
active   -> desired=active   accept_new_calls=true   routes=2
disabled -> desired=removed  accept_new_calls=false  routes=0   hash changed
active   -> desired=active   accept_new_calls=true   routes=2   (automatic restoration)
disabled -> desired=removed  accept_new_calls=false  routes=0   (final state)
```

Projection rows are retained as evidence rather than hard-deleted; row count
stayed at 2 throughout.

## Manual authority — PASS

No Artisan projection command exists (`T6ProjectionDispatcher` is wired directly
into `OutboxDispatcher` and fires on `c7a_authority` / `c7b_route` aggregates).
No manual database update, no kubectl config edit, no provider-specific Admin
endpoint, no push/apply button, no feature gate, no allowlist was used. Every
mutation in this proof was an ordinary authenticated admin API call.

## Provider neutrality — PASS at the canonical boundary

`DestinationRef` actively rejects provider-named opaque values
(`asterisk|freeswitch|kamailio|sofia|pjsip|rtpengine`). No provider-local field
is accepted from the caller. Provider detail is confined to `provider` and
`provider_local_trunk_id` inside the artifact. This passes, but trivially — no
provider-specific representation exists to leak.

## Cleanup

The synthetic trunk was left `disabled`, so both artifacts are `removed` /
`accept_new_calls=false`. The C7A/C7B fixture is retained as reusable proof
authority for the reproof after the correction. No database state was edited by
hand; no provider state was manufactured.

## Bounded correction packet

1. **Kamailio provider representation and verification seam.** Follow the
   existing precedent: a canonical-owned database view consumed by
   `kamailio.cfg` via `sqlops` (as `kamailio_conference_route_view` already
   does), fed from `external_trunk_projection_artifacts`. Verify with the
   existing acceptance seam, `scripts/kamailio-signaling/config-check`'s
   `kamailio -c -f` parser run. Removal behaviour: `desired_state=removed` must
   make the trunk unusable through the view.
2. **Asterisk provider representation and verification seam** — the equivalent
   slice, after Kamailio. Do not build both at once and do not introduce a
   generic provider framework.
3. **Defect A** — `ExternalTrunkProjectionService::routes()` must select the
   route id explicitly (for example `select('r.id as route_id', ...)`) so the
   joined address id cannot overwrite it. Acceptance test: artifact
   `routes[].route_id` equals the canonical `inbound_routes.id` /
   `outbound_routes.id` and differs from `telephony_address_id`.
4. **Defect B** — credential rotation must keep endpoints linked to the current
   credential reference, so an `authentication_mode=credentials` endpoint never
   projects a null reference. Acceptance test: after rotation the artifact
   carries the new reference id and version 2.

## T6 closure

```text
T6_REMAINS_ACTIVE
```

Exactly one remaining requirement: **the provider-facing Kamailio/Asterisk
representation and its synthetic verification seam** (Defects A and B are
corrections inside that same slice).

External PBX fixture: deferred to V1 natural external proof.
