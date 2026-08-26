# Kubernetes Application Base Runbook

> **Historical/secondary topology:** This runbook describes the K1 k3d
> deployment path and preserves its local-development facts. It does not
> select the current active-phase topology. Current V1 development and
> acceptance authority is native k3s; follow the native/server runbook and
> [`ADR-028`](../decisions/ADR-028-native-k3s-current-development-and-v1-acceptance-topology.md)
> for active V1 runtime work.

Phase K1 deploys the existing UTCP application base to the repository-managed `utcp-local` k3d cluster. It is a local development deployment, not a production architecture.

## Prerequisites

- K0 cluster running and verified: `make k3d-verify`
- Local registry running: `utcp-local-registry` on `127.0.0.1:5001`
- Repository kubeconfig: `.runtime/kubeconfig/utcp-local.yaml`
- `kubectl kustomize` available

The global Kubernetes context is not used by K1 scripts.

## Topology

- `utcp-data`: PostgreSQL StatefulSet and Redis StatefulSet.
- `utcp-platform`: migration Job, API Deployment, worker Deployment, scheduler Deployment, web Deployment, and internal gateway Deployment.
- `traefik-system`, `utcp-runtime`, and `utcp-observability`: no K1 workloads.

All Services are `ClusterIP`. The gateway is reached only with temporary `kubectl port-forward` during proof.

## Image Flow

K1 builds the existing API, web, and gateway Dockerfiles and pushes explicit local-development tags:

```text
127.0.0.1:5001/utcp/api:0.1.0-k1-dev
127.0.0.1:5001/utcp/web:0.1.0-k1-dev
127.0.0.1:5001/utcp/gateway:0.1.0-k1-dev
```

Kubernetes pulls the same images through:

```text
utcp-local-registry:5000/utcp/<name>:0.1.0-k1-dev
```

No remote registry credentials are used.

## Configuration and Secrets

Kustomize generates local ConfigMaps and synthetic local-development Secrets from checked-in `.properties` files under `infrastructure/kubernetes/overlays/local/`.

These values are fixtures for local proof only. Do not replace them with production credentials.

## Migration Lifecycle

`make k8s-apply` applies resources in order:

1. Verify the K0 cluster.
2. Render and validate Kustomize manifests.
3. Build and push K1 images.
4. Apply PostgreSQL and Redis.
5. Wait for data StatefulSets.
6. Recreate and run the canonical `utcp-migrate` Job.
7. Wait for migration completion.
8. Apply API, worker, scheduler, web, and gateway Deployments.
9. Wait for platform rollout.

API, worker, and scheduler Pods do not run migrations independently.

## Commands

```sh
make k8s-config-check
make k8s-image-build
make k8s-image-push
make k8s-apply
make k8s-status
make k8s-proof
make k8s-persistence-proof
make k8s-restart-proof
```

`make k8s-proof` uses `127.0.0.1:18089 -> service/gateway:8080`, verifies gateway health, frontend root, API liveness, API readiness, and API version, then removes the port-forward process.

## Persistence

PostgreSQL and Redis use local-path PVCs. `make k8s-persistence-proof` writes proof markers, deletes only the data Pods, waits for replacement Pods, confirms the markers persisted, and removes the markers.

Redis persistence improves local restart behavior only. Redis remains transient authority for queues, locks, cache, and projections.

## Cleanup

Delete K1 resources while retaining local data PVCs:

```sh
make k8s-delete
```

Destructively delete K1 PVCs:

```sh
CONFIRM=delete-k1-pvcs make k8s-delete
```

K1 cleanup does not delete the k3d cluster, registry, canonical namespaces, Docker Compose project, or unrelated Kubernetes resources.

## K2 Edge Relationship

After K2, the K1 internal `gateway` Service remains `ClusterIP` and is exposed externally only through Traefik and Gateway API on standard local ports `80` and `443`. K1 does not create NodePort, hostPort, hostNetwork, or LoadBalancer exposure.

## Known Limitations

- Ingress, NodePort, and direct LoadBalancer exposure for K1 application Services are not implemented.
- PostgreSQL and Redis are local development StatefulSets, not a production recommendation.
- Authentication, tenancy, authorization, observability, simulator behavior, and telephony runtimes are not implemented in K1.
