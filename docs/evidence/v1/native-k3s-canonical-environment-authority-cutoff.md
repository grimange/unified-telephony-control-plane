# Native k3s Canonical Environment Authority Cutoff

## Scope

This bounded V1 implementation establishes native k3s on `utcp-dev01` using
Kubernetes context `default` as the current UTCP live-proof and acceptance
authority. It does not implement provider-facing SIP topology (Gap B).

## Authority result

The historical `utcp-local` k3d environment is optional, non-canonical local
integration tooling. Current native lifecycle checks require the canonical
context and node, and fail closed when an active historical k3d server-load
balancer owns established UTCP edge ports: TCP/80, TCP/443, or UDP/5060.

The native edge uses the existing V1 SIP NodePort contract (UDP/30560). This
document intentionally does not decide the provider-reachable public SIP
address or port.

## Repository changes

- Native target validation now requires context `default` and node `utcp-dev01`.
- Native target validation detects active historical k3d ownership of the
  established UTCP HTTP/SIP edge ports without mutating the host.
- Current README, architecture overview, implementation plan, and command
  descriptions identify native k3s as current authority and local k3d as
  optional non-canonical tooling.
- A focused authority mutation harness proves canonical identity and edge
  collision guards reject regression fixtures while allowing an absent edge.

## Runtime evidence

Before the cutoff, the active `k3d-utcp-local-serverlb` owned host UDP/5060
and loopback TCP/80 and TCP/443. The repository's supported reversible
`make local-down` lifecycle maps to `k3d cluster stop utcp-local`; it preserves
the historical registry/data and was used to remove active k3d edge ownership.
Destructive `make k3d-delete` was not used.

## Validation and remaining boundary

Focused authority checks, native configuration checks, repository hygiene, and
diff validation were run for this change. Historical evidence remains
unchanged. Gap B remains open for a separate signaling-authority ADR covering
provider-reachable SIP identity, port, NAT, advertise, and Record-Route
ownership.
