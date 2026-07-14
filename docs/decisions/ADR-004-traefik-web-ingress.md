# ADR-004: Traefik Handles Web Ingress but Not Primary SIP or RTP

## Status

Accepted

## Context

UTCP requires HTTP, HTTPS, and application WebSocket ingress. SIP signaling and RTP media have different protocol authority and operational requirements.

## Decision

Traefik will handle web ingress for HTTP, HTTPS, and application WebSockets. It will not be the primary SIP signaling or RTP media authority in this roadmap.

## Consequences

- Kamailio remains the planned live SIP signaling authority.
- rtpengine remains the planned RTP/SRTP media relay authority.
- Web ingress configuration must not imply that telephony traffic is routed through Traefik.
