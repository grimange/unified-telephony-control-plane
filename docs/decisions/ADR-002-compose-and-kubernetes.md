# ADR-002: Docker Compose and Kubernetes Are Both Supported

## Status

Accepted

## Context

UTCP needs a fast local development path and a credible deployment proof path.

## Decision

Docker Compose will remain a supported development path. Kubernetes, initially through k3d, will be supported for deployment proof and platform behavior.

## Consequences

- Compose support must not be broken by Kubernetes work.
- Kubernetes support must not leak workload-placement concepts into telephony business policy.
- Later phases must verify both paths where their scope touches both.
