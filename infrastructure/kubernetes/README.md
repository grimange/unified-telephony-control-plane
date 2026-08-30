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

## Native k3s server lifecycle

The `overlays/server` layer is the physical/server deployment path for an
already-installed native k3s cluster. It reuses the same data, migration,
platform, runtime, and fencing bases as local Kubernetes, while keeping the
k3d lifecycle and registry exclusively in the local overlay. Native commands
validate an explicit kubeconfig/context and the expected `utcp-dev01` node;
they reject k3d contexts and the `utcp-local` registry.

The canonical native publication authority is GHCR. The
`native-images.yml` workflow builds and tests the repository-owned images,
publishes them under `ghcr.io/<repository-owner>/utcp-*`, and uploads a
machine-readable image lock containing the resulting immutable digests and
source commit. The package visibility decision remains account-owned; public
packages allow anonymous pulls from every native k3s node.

The native image lock is promoted from the exact commit-named GitHub Actions
artifact; it must not be rebuilt or edited manually. With GitHub CLI
authentication available, promote the repository HEAD (or set
`UTCP_SERVER_SOURCE_COMMIT` to an exact full commit):

```sh
export UTCP_SERVER_KUBECONFIG=/path/to/native-k3s.kubeconfig
export UTCP_SERVER_CONTEXT=default
export UTCP_SERVER_NODE_NAME=utcp-dev01
make server-image-sync
make server-config-check
make server-image-preflight
make server-apply
make server-status
make server-proof
```

`Native k3s Images` publishes immutable `sha-<commit>` images and the
commit-specific lock artifact on every `main` push. `server-image-sync`
authenticates to GitHub, retrieves only the requested artifact, validates its
source commit, matching tag, registry, and complete digest-pinned image set,
then atomically promotes it into the native runtime state directory.
`server-image-preflight` and `server-apply` consume that declared lock;
`server-apply` does not imply that the active lock is for the current checkout.
Manual workstation image builds and pushes are not the normal native-k3s path.

The registry account and image-pull credentials are operator-owned and are
not committed. The server overlay uses the existing `local-path` storage
class, keeps application/data Services internal, and disables the existing
external Kamailio NodePort for this foundation phase. Repository-managed
Traefik/Gateway API, security, and observability remain separate layers to be
run against the same validated native target; public DNS, certificates, and
SIP/RTP exposure are deferred.
