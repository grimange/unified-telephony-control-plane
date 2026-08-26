# Local Runtime Authority Cutoff Runbook

## Scope

The canonical active-phase runtime is native k3s on `utcp-dev01` at
`192.168.254.124`. The `utcp-local` Kubernetes cluster described below is a
supported secondary local/regression runtime and must be explicitly selected
by the task. See [`ADR-028`](../decisions/ADR-028-native-k3s-current-development-and-v1-acceptance-topology.md).

Docker Compose remains supported for:

- image and container compatibility proof
- disposable PostgreSQL and Redis integration proof
- explicit isolated debug sessions
- CI jobs that do not require Kubernetes

Docker Compose is not a fallback when Kubernetes is unavailable and is not a second persistent UTCP runtime.

## Runtime Roles

```text
Dockerfiles
└── define application images

Docker Engine
├── builds images
├── runs k3d node containers
└── runs the local registry

native k3s / utcp-dev01
└── current active-phase runtime

utcp-local Kubernetes
└── supported secondary local/regression runtime

Docker Compose
└── disposable compatibility proof and explicit debug mode
```

## Canonical Lifecycle

Use Kubernetes lifecycle targets for normal local work:

```sh
make local-up
make local-status
make local-proof
make local-down
```

`local-up` and `local-proof` fail when a persistent UTCP Compose project is running, because simultaneous workers could make queue, database, runtime-operation, event, or reconciliation proof ambiguous.

`local-status` is non-mutating and reports persistent Compose drift without stopping it.

## Compose Proof

Run Compose only as a disposable proof:

```sh
make compose-proof
```

The proof uses an isolated project name, isolated networks, disposable named volumes, and a nonstandard gateway port. It does not bind `80` or `443`. It removes containers, networks, and volumes on success or failure.

## Optional Debug Mode

Debug mode is explicit:

```sh
make compose-debug-up
make compose-status
make compose-logs
make compose-down
```

The compatibility alias `make compose-up` invokes debug mode only. Debug mode must not share Kubernetes PostgreSQL, Redis, sessions, queues, runtime operations, event receipts, or reconciliation state.

## Cutoff Procedure

When a persistent repository-owned `utcp` Compose project is running, stop it explicitly:

```sh
UTCP_COMPOSE_PROJECT_NAME=utcp UTCP_GATEWAY_PORT=8088 make compose-down
```

This preserves Docker images, Kubernetes resources, Kubernetes PVCs, the local registry, prepared TLS files, and unrelated Compose projects.

## Verification

Use:

```sh
make local-config-check
make local-status
make local-proof
make compose-proof
docker compose ls
```

Expected final state:

- `utcp-local` is running and healthy.
- Standard ports `80/443` remain owned by the k3d load balancer.
- Gateway, K3 security, K4 observability, and C3 process roles are healthy.
- No persistent UTCP Compose project remains.
- No disposable Compose proof resource remains.
- APNTalk resources are untouched.
