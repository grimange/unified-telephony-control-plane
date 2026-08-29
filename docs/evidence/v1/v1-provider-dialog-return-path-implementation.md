# V1 Provider Dialog Return-Path Implementation

## Scope

This bounded repository change implements ADR-031's provider-facing SIP
identity and dialog anchoring contract. It does not claim the real-provider
Gap B proof is complete.

## Implemented authority

`UTCP_SERVER_PROVIDER_SIP_ADDRESS` and
`UTCP_SERVER_PROVIDER_SIP_PORT` are explicit native-k3s deployment inputs.
The native configuration check fails closed when either is absent, partial, or
invalid. It does not infer Node IP, NodePort, Pod IP, ClusterIP, loopback, or a
provider endpoint. An explicitly configured valid node address or port 30560 is
not rejected merely because it coincides with infrastructure values.

`render_server()` substitutes the provider identity into the provider Kamailio
socket only. The runtime socket keeps its cluster-internal advertisement. The
provider egress route now calls `record_route()` after `dlg_manage()` and before
relay; the existing `WITHINDLG` / `loose_route()` path and private runtime
Contact remain unchanged. Double Record-Route is accepted for the two-socket
topology.

## Validation

The focused native provider-identity test covers valid IPv4, hostname, IPv6,
the explicit NodePort coincidence, missing/partial values, malformed values,
loopback, cluster-internal identities, unresolved placeholders, rendered
provider/runtime socket identities, and Record-Route ordering. The existing
Kamailio parser-backed signaling checks remain the parser authority.

## Remaining proof

The actual provider-facing address and port, external forwarding, and a
real-provider in-dialog BYE proof remain required. No live environment,
provider configuration, Contact rewriting, authentication, media, placement,
termination, or other Gap A/E/F behavior was changed.
