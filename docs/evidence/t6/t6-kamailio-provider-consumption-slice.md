# T6 Kamailio External-Trunk Provider Consumption Slice

Status: implemented and repository/provider tested. T6 remains active for the separate Asterisk provider-consumption slice.

## Scope

This bounded slice fixes the two defects found by the synthetic provider verification and adds the Kamailio provider-consumption seam. It does not implement Asterisk projection, V1 external SIP acceptance, an external PBX fixture, or any public SIP-edge redesign.

The historical failed synthetic proof remains preserved in its existing evidence document.

## Defect A: route identity

The external-trunk projection previously allowed joined address columns to overwrite route columns. The projection query now uses an explicit select list and aliases the canonical route as `route_id`, while retaining the address as `telephony_address_id`/`address_id` in the derived artifact. Focused regression uses distinct route and address identifiers and proves both values remain correct for inbound and outbound projection.

## Defect B: credential rotation

Canonical C7A credential rotation now runs in the existing transaction boundary: it creates the replacement credential reference, atomically repoints all affected endpoints, retires the old reference, and emits the normal trunk mutation/outbox. T6 performs no newest-reference search, fallback, or stale-reference repair. Projection therefore consumes the current endpoint reference and version directly. Tests prove coherent rebinding, protected plaintext, automatic projection, and idempotent reprojection.

## Kamailio provider representation

`kamailio_external_trunk_route_view` is a read-only PostgreSQL view derived from the projected Kamailio artifact. It exposes only provider-facing routing and identity metadata: canonical trunk, provider-local trunk identity, route, telephony address, normalized address, direction, destination, caller identity, endpoint, URI, and transport. It filters to projected, active, call-accepting authority. Historical generic artifacts remain durable evidence; the view is the runtime-consumption seam. No plaintext credential is exposed. Existing signaling authentication projection remains separate.

## SQLOps consumption

The canonical Kamailio configuration uses the existing `sqlops` connection to query `kamailio_external_trunk_route_view` in the bounded external-trunk route. Kamailio logs the matched canonical/provider correlation and returns a deterministic route result. It does not rerun C7B selection, choose another trunk, or become an editable route authority.

Inbound provider correlation is prepared from the normalized request user and the projected view's canonical trunk/address fields. Full inbound call handling remains V1 scope.

## Runtime synthetic proof

The repository-native smoke path creates canonical address, trunk, credential reference, endpoint, inbound association, and route through the Admin API. It waits for automatic outbox projection, sends an actual synthetic SIP OPTIONS request from the existing allowed Asterisk runtime peer to the canonical Kamailio Service, and asserts the running Kamailio result log:

```text
active       -> SQL-backed provider row matched
disabled     -> projection row absent and route_not_found
reactivated  -> projection restored and provider row matched
```

The proof uses the live Kamailio process and SQLOps result marker; static `kamailio -c -f` validation alone is not treated as acceptance. The SIP response is not used as the assertion because the existing namespace policy does not grant the synthetic sender general UDP return-path authority; the Kamailio request/result log is the decisive runtime observation of route execution.

## Validation boundary

Static Kamailio config parsing and repository config checks pass. Focused C7A/C7B/T6 tests pass. The repository's existing Kamailio mutation test suite retains one unrelated pre-existing failure concerning the secondary Asterisk workload selector mutation. No Asterisk provider seam was added.

