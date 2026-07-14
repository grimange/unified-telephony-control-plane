# Kubernetes Network Security Runbook

Phase K3 applies Kubernetes Pod Security Admission and NetworkPolicy boundaries around the existing K1 application base and K2 Traefik/Gateway API edge.

K3 does not introduce SIP, RTP, WSS signaling, Reverb, observability workloads, PBX runtimes, runtime adapters, authentication, tenancy, or conference behavior.

## Architecture

Current active traffic:

```text
external client
  -> Traefik web/websecure
  -> utcp-platform/gateway
  -> web, api

api, worker, scheduler, migration
  -> PostgreSQL, Redis
```

The future shared `443/TCP` HTTPS/WSS model remains reserved by hostname, but K3 creates no routes or policies for `sip.utcp.local.test` or `events.utcp.local.test`.

## Namespace Profiles

K3 labels these UTCP-owned namespaces with Pod Security Admission `restricted` pinned to Kubernetes `v1.35`:

- `utcp-platform`
- `utcp-data`
- `utcp-runtime`
- `utcp-observability`
- `traefik-system`

The labels are applied for `enforce`, `audit`, and `warn`. Do not label `kube-system`, `default`, APNTalk namespaces, or unrelated clusters.

## Network Policy Model

`utcp-platform`, `utcp-data`, `utcp-runtime`, and `utcp-observability` have default-deny ingress and egress.

`traefik-system` has an explicit policy model for the public ingress controller. It allows public ingress only to Traefik HTTP and HTTPS entrypoints, DNS egress, exact Kubernetes API egress, and egress to the K1 gateway backend.

Allowed current flows:

| Source | Destination | Ports |
| --- | --- | --- |
| Traefik | `utcp-platform/gateway` | TCP 8080 |
| gateway | web | TCP 8080 |
| gateway | api | TCP 9000 |
| api | PostgreSQL, Redis | TCP 5432, 6379 |
| worker | PostgreSQL, Redis | TCP 5432, 6379 |
| scheduler | PostgreSQL, Redis | TCP 5432, 6379 |
| migration | PostgreSQL, Redis | TCP 5432, 6379 |

Denied current flows include web/gateway/Traefik/generic platform pods to PostgreSQL or Redis, empty runtime/observability namespaces to K1 services, and data namespace pods to the gateway.

## Kubernetes API Egress

Traefik must watch Gateway API resources. The local K3s Service address is rendered into an ignored runtime policy:

```text
.runtime/kubernetes/security/traefik-apiserver-egress.yaml
```

The renderer validates the Kubernetes API Service ClusterIP and the single observed API endpoint. The endpoint allowance is needed because this local k3d/K3s CNI enforces the post-DNAT API endpoint rather than only the Service ClusterIP.

Do not commit generated runtime files and do not replace the exact destination with `0.0.0.0/0` or broad HTTPS egress.

## Commands

Validate without mutating the cluster:

```sh
make security-config-check
```

Apply K3:

```sh
make security-apply
```

Inspect status:

```sh
make security-status
```

Run runtime proof:

```sh
make security-proof
```

Remove only K3-owned policy resources:

```sh
make security-delete
```

`security-delete` is security-reducing. It preserves the K0 cluster, K1 workloads, K2 Traefik/Gateway API resources, namespaces, PVCs, registry, and APNTalk resources.

## Proof Methodology

`make security-proof` proves:

- Pod Security rejects a privileged proof pod in each K3 namespace.
- Real Traefik can reach the K1 gateway.
- Real Traefik can reach the Kubernetes API path needed for Gateway API watches.
- The real gateway can reach web and API.
- Real API, worker, and scheduler roles can reach PostgreSQL and Redis.
- Unauthorized proof pods cannot reach data or application services.
- K1 and K2 runtime proofs still pass.
- K3 proof pods are cleaned up.

Positive application proof uses existing workloads where that provides stronger evidence than synthetic pods. Negative proof uses short-lived restricted proof pods with bounded connection attempts and explicit cleanup.

## Troubleshooting

If application readiness fails after applying K3, check workload labels:

```sh
kubectl --kubeconfig .runtime/kubeconfig/utcp-local.yaml get pods -A -L utcp.io/network-role
```

If Traefik Gateway status stops updating, regenerate and reapply the runtime API egress policy:

```sh
scripts/security/render-apiserver-policy
kubectl --kubeconfig .runtime/kubeconfig/utcp-local.yaml --context k3d-utcp-local apply -f .runtime/kubernetes/security/traefik-apiserver-egress.yaml
```

If a denied proof reports `Connection refused`, inspect whether the CNI is returning a TCP reset for policy denial. K3 treats a failed TCP connection to an existing, resolved endpoint as denial evidence only after endpoint existence has been verified.

## Limitations

K3 is a local-development security boundary. It does not implement production secret management, Vault, External Secrets, cloud KMS, production certificate automation, observability, or runtime telephony networking.

Future signaling, events, media, and observability phases must add their own explicit policies when real workloads exist.
