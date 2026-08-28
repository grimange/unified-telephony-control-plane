# V1-A External-Trunk Route Projection Contract Repair

Date: 2026-08-28

## Bounded finding

The V1-A native-k3s proof had already established the complete trusted-runtime
correlation corridor through Kamailio. The next outbound step failed closed in
`route[RUNTIME_EXTERNAL_TRUNK]` with `503 External Trunk Projection
Unavailable`. The PostgreSQL error was an endpoint identity join mismatch
(`uuid = text`), and the route predicate separately compared the request user
(`97001`) with a full canonical SIP URI (`sip:97001@38.146.161.46`).

## Contract repair

The domain projection now preserves the complete normalized `sip_uri` in
`address` and emits the explicit derived `destination_user` routing key. The
Kamailio route view exposes both fields and casts the projected endpoint
identity to the canonical PostgreSQL `uuid` type for `endpoint_id` and
`trunk_endpoint_id`. The registration projection remains the credential
authority and is joined through the UUID-compatible `trunk_endpoint_id`.

Outbound and comparable inbound route selection now match
`destination_user = $rU`; the selected endpoint ID, outbound direction, active
desired state, and `accept_new_calls = true` predicates remain required. The
outbound query still requires exactly one row and continues deriving the
provider host from the canonical endpoint URI.

## Repository proof

Focused source and T6 projection tests prove the full SIP URI and derived user
contract. A PostgreSQL-only T6 regression covers UUID type compatibility, the
registration join, one-row selection, selected endpoint and address values,
wrong endpoint, wrong destination, wrong direction, inactive route, and
disabled projection fail-closed behavior. Kamailio config mutation tests reject
restoring either the full-URI comparison or the text endpoint join.

This note records repository implementation evidence only. Provider-bound
INVITE, provider authentication/result, and canonical Call/CallLeg observation
remain the next controlled live proof.
