# ADR-009: Local Compose Gateway Topology

## Status

Accepted

## Context

Phase F3 needs a deterministic local Docker Compose entry point for the existing Vue web runtime and Laravel PHP-FPM API. Traefik remains the planned HTTP, HTTPS, and application WebSocket ingress authority for later deployment phases, but it is not implemented in F3.

The local platform must expose one localhost HTTP port without publishing PostgreSQL, Redis, API PHP-FPM, or the web runtime directly to the host.

## Decision

The Phase F3 Compose platform uses a minimal unprivileged Nginx gateway service named `gateway`.

The gateway:

- exposes the local Compose HTTP entry point on a non-privileged container port;
- forwards application-root traffic to the `web` service;
- forwards `/api/*` requests to the `api` service through FastCGI;
- serves no database, Redis, SIP, RTP, PBX, or telephony runtime path;
- remains separate from Traefik and Kubernetes ingress.

## Consequences

- Local Compose has one stable URL for application proof.
- PostgreSQL, Redis, PHP-FPM, and the web runtime are not directly published to the host.
- The Laravel API image can continue running PHP-FPM as its production process role.
- The gateway contract is intentionally local and does not preempt the later Traefik and Kubernetes ingress phases.
