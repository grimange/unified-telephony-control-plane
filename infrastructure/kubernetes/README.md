# Kubernetes Infrastructure

Phase K1 introduces the local Kubernetes application base for the existing UTCP platform. Kustomize is the repository authority for UTCP-owned Kubernetes resources.

## Layout

- `base/data/` - PostgreSQL and Redis Services, StatefulSets, and data ServiceAccount.
- `base/migration/` - canonical Laravel migration Job using the backend `migrate` role.
- `base/platform/` - API, worker, scheduler, web, gateway, Services, and platform ServiceAccount.
- `overlays/local/` - local k3d image references, synthetic local configuration, and safe development credentials.

The local overlay deploys only into `utcp-platform` and `utcp-data`. The `traefik-system`, `utcp-runtime`, and `utcp-observability` namespaces remain workload-free in K1.

## Local Image Contract

The local K1 images use the existing k3d registry:

- host push endpoint: `127.0.0.1:5001`
- cluster pull endpoint: `utcp-local-registry:5000`

The committed local overlay uses explicit `0.1.0-k1-dev` image tags. No remote registry credentials are required.

## Commands

Run from the repository root:

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

`make k8s-proof` uses a temporary local port-forward to `127.0.0.1:18089` and removes it before exiting. It does not create Ingress, Gateway API, NodePort, LoadBalancer, Traefik, SIP, RTP, or telephony resources.
