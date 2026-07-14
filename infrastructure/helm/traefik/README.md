# Traefik Helm Configuration

K2 installs Traefik as third-party infrastructure with Helm chart `41.0.2` and explicitly runs Traefik Proxy `v3.7.7`.

The chart is configured only for Kubernetes Gateway API:

- Gateway API provider enabled.
- Kubernetes Ingress provider disabled.
- Traefik CRD provider disabled.
- Dashboard and insecure API disabled.
- Internal Traefik entryPoints remain `80` and `443`.
- Host-side ports are provided only by k3d load balancer mappings: `127.0.0.1:80` and `127.0.0.1:443`.

UTCP-owned Gateway API resources are managed separately through Kustomize under `infrastructure/kubernetes/gateway-api/`.
