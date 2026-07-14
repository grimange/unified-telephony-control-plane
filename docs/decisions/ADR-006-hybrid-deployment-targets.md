# ADR-006: Hybrid Deployment Targets

## Status

Accepted

## Context

Telephony runtimes may live in Docker, Kubernetes, external virtual machines, bare-metal hosts, or simulator processes.

## Decision

UTCP will model deployment targets neutrally and must not encode Kubernetes as the only runtime location.

## Consequences

- Core runtime concepts must stay deployment-neutral.
- Kubernetes may manage workload placement for Kubernetes-hosted components, but it must not define telephony business policy.
- Runtime adapters must report capabilities and observations through normalized contracts.
