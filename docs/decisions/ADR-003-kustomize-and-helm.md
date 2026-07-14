# ADR-003: Kustomize for UTCP Resources and Helm for Third-Party Infrastructure

## Status

Accepted

## Context

UTCP will own application and platform manifests while depending on third-party infrastructure such as Traefik.

## Decision

UTCP-owned Kubernetes resources will use Kustomize. Third-party infrastructure will use Helm charts where that is the upstream-supported packaging model.

## Consequences

- UTCP manifests stay reviewable and explicit.
- Third-party chart lifecycle remains aligned with upstream packaging.
- Version pinning belongs in committed configuration when those resources are introduced.
