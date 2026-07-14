# ADR-010: Local Traefik Gateway Edge Ports

## Status

Superseded by ADR-011.

## Context

Phase K2 introduces a repository-managed Traefik edge in the local `utcp-local` k3d cluster. The operator-provided local DNS names resolve to `127.0.0.1`, but the APNTalk environment already owns host ports `80` and `443` on localhost.

UTCP needs deterministic local HTTP and HTTPS entry points without taking APNTalk ports, changing the global Kubernetes context, or introducing telephony listeners.

## Decision

APNTalk retains `127.0.0.1:80` and `127.0.0.1:443`.

UTCP maps local host ports through the k3d load balancer:

- `127.0.0.1:18080 -> 80/tcp`
- `127.0.0.1:18443 -> 443/tcp`

Traefik remains internally configured on entryPoint and container ports `80` and `443`. Gateway listeners use ports `80` and `443`; the custom ports are host-side k3d mappings only.

K2 routes only `app.utcp.local.test` and `utcp.local.test`. `sip.utcp.local.test` and `events.utcp.local.test` remain reserved and unrouted.

## Consequences

- The canonical K2 URL is `https://app.utcp.local.test:18443/`.
- HTTP redirects include the custom HTTPS port `18443`.
- A future shared host-edge architecture would require a separate explicit decision.
- Traefik remains web-edge infrastructure and does not become the SIP or RTP authority.

## Supersession

ADR-011 replaces this custom-port decision with a one-cluster-at-a-time standard local edge on `127.0.0.1:80` and `127.0.0.1:443`. The custom ports remain historical evidence only and are no longer active configuration or fallback behavior.
