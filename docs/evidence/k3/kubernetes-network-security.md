# K3 Evidence: Kubernetes Network and Security Boundaries

Phase K3 applies Pod Security Admission and NetworkPolicy boundaries around the existing K1 application base and K2 Traefik/Gateway API edge.

## Environment Preservation

- `apntalk-local` was intentionally stopped before this phase and was not started, stopped, deleted, or modified by UTCP.
- `utcp-local` remained running.
- The standard local edge remained `127.0.0.1:80` and `127.0.0.1:443`.
- No UTCP publication of `18080` or `18443` was observed during K3 proof.
- Global Kubernetes context remained `k3d-apntalk-local`; repository commands used `.runtime/kubeconfig/utcp-local.yaml`.

## Namespace Security Profiles

Observed Pod Security Admission labels:

| Namespace | Enforce | Audit | Warn |
| --- | --- | --- | --- |
| `utcp-platform` | `restricted` `v1.35` | `restricted` `v1.35` | `restricted` `v1.35` |
| `utcp-data` | `restricted` `v1.35` | `restricted` `v1.35` | `restricted` `v1.35` |
| `utcp-runtime` | `restricted` `v1.35` | `restricted` `v1.35` | `restricted` `v1.35` |
| `utcp-observability` | `restricted` `v1.35` | `restricted` `v1.35` | `restricted` `v1.35` |
| `traefik-system` | `restricted` `v1.35` | `restricted` `v1.35` | `restricted` `v1.35` |

Pod Security rejection proof passed for all five namespaces using a privileged dry-run proof pod. No privileged proof pod ran.

## NetworkPolicy Inventory

Default-deny policies were present in:

- `utcp-platform`
- `utcp-data`
- `utcp-runtime`
- `utcp-observability`

`traefik-system` had a policy-controlled ingress-controller model:

- `default-deny`
- `allow-traefik-required-traffic`
- `allow-traefik-kubernetes-api`
- `allow-traefik-gateway-service-clusterip`

Application/data policies:

- `allow-gateway-required-traffic`
- `allow-gateway-service-clusterips`
- `allow-api-required-traffic`
- `allow-worker-required-egress`
- `allow-scheduler-required-egress`
- `allow-migration-required-egress`
- `allow-web-from-gateway`
- `allow-backend-data-service-clusterips`
- `allow-postgres-from-backend-roles`
- `allow-redis-from-backend-roles`

## Kubernetes API Egress

The generated Traefik API egress policy was rendered from observed cluster state:

```text
service=10.43.0.1/32:443
endpoint=172.24.0.2/32:6443
```

The endpoint allowance is required in this local k3d/K3s environment because the CNI enforces the post-DNAT API endpoint. The policy remains exact and does not grant broad HTTPS or `0.0.0.0/0` egress.

Traefik API reachability proof returned the expected unauthenticated Kubernetes API response path, proving TCP reachability without exposing credentials.

## Positive Connectivity Proof

`make security-proof` passed these positive checks:

| Source | Destination | Result |
| --- | --- | --- |
| real Traefik | gateway | passed |
| real Traefik | Kubernetes API | passed |
| real gateway | web | passed |
| real gateway | API PHP-FPM | passed |
| real API | PostgreSQL | passed |
| real API | Redis | passed |
| real worker | PostgreSQL | passed |
| real worker | Redis | passed |
| real scheduler | PostgreSQL | passed |
| real scheduler | Redis | passed |

K1 application proof and K2 Gateway proof also passed after K3 policy application.

## Negative Connectivity Proof

`make security-proof` passed these denial checks against existing, resolved endpoints:

| Source | Destination | Result |
| --- | --- | --- |
| web-equivalent pod | PostgreSQL | denied |
| web-equivalent pod | Redis | denied |
| gateway-equivalent pod | PostgreSQL | denied |
| gateway-equivalent pod | Redis | denied |
| Traefik-equivalent pod | PostgreSQL | denied |
| Traefik-equivalent pod | Redis | denied |
| generic platform pod | PostgreSQL | denied |
| generic platform pod | Redis | denied |
| runtime namespace pod | gateway | denied |
| runtime namespace pod | API | denied |
| observability namespace pod | gateway | denied |
| observability namespace pod | API | denied |
| data namespace pod | gateway | denied |

Proof pods were removed after proof.

## RBAC and ServiceAccounts

Application workloads used non-default service accounts with `automountServiceAccountToken: false`.

Observed application service accounts:

- `utcp-platform/utcp-platform-app`
- `utcp-data/utcp-data`

No `Role` or `RoleBinding` existed in `utcp-platform` or `utcp-data`. Traefik retained only Helm-managed RBAC required for Gateway API operation.

## External Gateway Regression

`make gateway-proof` passed under active K3 policies:

- HTTP redirects to HTTPS without a custom port.
- `/healthz` succeeds.
- `/` serves the Vue shell.
- `/api/health/live` succeeds.
- `/api/health/ready` reports PostgreSQL and Redis healthy.
- `/api/version` succeeds.
- Reserved hostnames do not route to the application.
- Traefik dashboard is not externally exposed.

## Telephony Boundary

K3 introduced no Asterisk, FreeSWITCH, Kamailio, rtpengine, SIP, SIPS, RTP, SRTP, WebRTC signaling, WSS signaling route, PBX Service, media privilege, runtime adapter, runtime allowlist, or telephony-specific NetworkPolicy.

`utcp-runtime` remains workload-free and default-denied.

## Hosted CI Status

Hosted GitHub Actions execution has not been observed for this uncommitted working tree.
