# ADR-008: Container Process Role Contract and Static Web Runtime

## Status

Accepted

## Context

Phase F2 needs reusable container images for the Laravel API and Vue administration shell before Docker Compose or Kubernetes orchestration exists. Later phases will run API, worker, scheduler, and migration responsibilities as separate deployed processes from the same backend application codebase.

## Decision

The backend production image will run PHP-FPM for the `api` role and will expose explicit `worker`, `scheduler`, and `migrate` roles through the same entrypoint. Unknown roles fail closed, while intentional direct diagnostic commands remain available.

The frontend production image will serve the compiled Vue application through an unprivileged Nginx runtime on port `8080`, with SPA fallback and a static `/healthz` endpoint. It will not proxy Laravel API traffic in Phase F2.

## Consequences

- One backend image can be reused by later Compose and Kubernetes phases while each container still runs one primary process role.
- Database migrations are available as an explicit deployment role and do not run automatically for ordinary API, worker, or scheduler startup.
- The frontend image remains a static web runtime until later ingress and reverse-proxy phases define routing.
- The container images do not imply that Compose, Kubernetes, Traefik, PostgreSQL, Redis, or telephony runtimes are implemented.
