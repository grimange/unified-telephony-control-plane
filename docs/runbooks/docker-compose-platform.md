# Docker Compose Proof And Debug Runbook

## Scope

This runbook covers the retained Docker Compose configuration after the local
runtime authority cutoff. The current active-phase runtime is native k3s on
`utcp-dev01`; `utcp-local` is a supported secondary local/regression topology.
Compose is a disposable container/integration proof facility and an explicit
optional debug mode only.

It does not cover Kubernetes, Traefik, SIP, RTP, Asterisk, FreeSWITCH, Kamailio, rtpengine, authentication, tenancy, or production operations.

## Prerequisites

Required local tools:

- Docker Engine
- Docker Compose v2
- Make

Check local tool status:

```sh
make doctor
```

## Configuration

The canonical Compose file is:

```text
infrastructure/compose/compose.yaml
```

Safe local defaults are documented in:

```text
infrastructure/compose/env.example
```

The optional debug gateway defaults to:

```text
http://127.0.0.1:18088
```

Do not commit a real Compose `.env` file. The committed example contains only synthetic local defaults.

## Disposable Proof

Validate the resolved Compose configuration:

```sh
make compose-config
```

Build the local images:

```sh
make compose-build
```

Run the disposable proof:

```sh
make compose-proof
```

`compose-proof` uses an isolated project name, isolated networks, disposable named volumes, and a nonstandard gateway port. It starts only the proof stack it owns, waits for readiness, checks PostgreSQL, Redis, API, worker, scheduler, web, gateway, and the C3 process roles, then removes containers, networks, and volumes with a cleanup trap.

The proof must leave no Compose services running after success or failure.

## Optional Debug Startup

Start the explicit debug stack only when needed:

```sh
make compose-debug-up
```

`compose-up` is retained as a compatibility alias for `compose-debug-up`. Debug mode is not canonical local runtime authority and must not be started by `make local-up` or `make local-proof`.

## Service Topology

Services:

- `gateway` - local HTTP entry point
- `web` - compiled Vue static runtime
- `api` - Laravel PHP-FPM API role
- `worker` - Laravel queue worker role
- `scheduler` - Laravel scheduler role
- `control-plane-outbox-dispatcher` - C3 outbox dispatch role
- `telephony-command-worker` - C3 generic runtime-operation worker role
- `telephony-event-normalizer` - C3 raw-event normalization role
- `telephony-reconciler` - C3 reconciliation role
- `migrate` - one-shot Laravel migration role
- `postgres` - local PostgreSQL data service
- `redis` - local Redis transient coordination service

Networks:

- `edge` - host-facing gateway attachment
- `platform` - gateway, web, and API application path
- `data` - API, worker, scheduler, PostgreSQL, and Redis data path

PostgreSQL and Redis are not published to the host by default.

## Health Checks

Show service status:

```sh
make compose-status
```

Run the bounded Compose proof:

```sh
make compose-proof
```

The proof checks gateway `/healthz`, the frontend root, built frontend assets, `/api/health/live`, `/api/health/ready`, `/api/version`, PostgreSQL health, Redis health, migration completion, expected roles, C3 process roles, absence of public data-service bindings, and absence of standard edge bindings on `80/443`.

## Logs

Show recent logs for all services:

```sh
make compose-logs
```

Show one service:

```sh
make compose-logs SERVICE=api
```

Logs must not include credentials, connection strings, or raw environment dumps.

## Persistence

The disposable proof uses isolated volumes and removes them during cleanup. It does not retain database or Redis state.

Optional debug mode uses its own Compose volumes. Redis remains transient authority and is not canonical business storage. Debug volumes must never be shared with Kubernetes PostgreSQL, Redis, queues, sessions, runtime operations, event receipts, or reconciliation state.

Ordinary shutdown preserves volumes:

```sh
make compose-down
```

Starting debug mode again with `make compose-debug-up` reuses that debug project's named volumes.

## Reset

Destroy local Compose data only with the explicit guard:

```sh
make compose-reset CONFIRM=destroy-compose-data
```

This is destructive. It removes optional debug PostgreSQL and Redis Compose volumes.

## Common Failures

- Port `18088` already in use for debug mode: set `UTCP_GATEWAY_PORT` to another nonstandard local port. Do not use `80` or `443`; those belong to the canonical Kubernetes edge.
- Docker daemon unavailable: start Docker and rerun `make doctor`.
- Stale failed debug containers: run `make compose-down`, then `make compose-debug-up`.
- Schema migration failure: inspect `make compose-logs SERVICE=migrate`; API, worker, and scheduler do not run migrations independently.
- Readiness failure: inspect `make compose-logs SERVICE=api`, then confirm PostgreSQL and Redis are healthy with `make compose-status`.
