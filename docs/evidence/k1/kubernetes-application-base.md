# K1 Evidence: Kubernetes Application Base

## Summary

K1 deployed the existing UTCP application base to the existing `utcp-local` k3d cluster using Kustomize and the local k3d registry.

Hosted GitHub Actions execution: not observed for the uncommitted working tree.

## Image Publication

- API: `127.0.0.1:5001/utcp/api:0.1.0-k1-dev`
- Web: `127.0.0.1:5001/utcp/web:0.1.0-k1-dev`
- Gateway: `127.0.0.1:5001/utcp/gateway:0.1.0-k1-dev`
- Cluster image prefix: `utcp-local-registry:5000/utcp`

`make k8s-image-push` pushed and pulled all three local registry images.

## Resource Inventory

Data namespace `utcp-data`:

- `statefulset/postgres`: Ready `1/1`, image `postgres:17.6-alpine`
- `statefulset/redis`: Ready `1/1`, image `redis:8.2.3-alpine`
- `service/postgres`: `ClusterIP`, port `5432`
- `service/redis`: `ClusterIP`, port `6379`
- PVCs: `postgres-data-postgres-0` `1Gi`, `redis-data-redis-0` `512Mi`, both Bound with `local-path`

Platform namespace `utcp-platform`:

- `job/utcp-migrate`: Complete `1/1`
- `deployment/api`: Available `1/1`, args `api`
- `deployment/worker`: Available `1/1`, args `worker`
- `deployment/scheduler`: Available `1/1`, args `scheduler`
- `deployment/web`: Available `1/1`
- `deployment/gateway`: Available `1/1`
- `service/api`: `ClusterIP`, port `9000`
- `service/web`: `ClusterIP`, port `8080`
- `service/gateway`: `ClusterIP`, port `8080`

## Runtime Proof

`make k8s-proof` passed through temporary port-forward `127.0.0.1:18089 -> service/gateway:8080`.

Observed responses:

- `/healthz`: `ok`
- `/api/health/live`: `{"status":"ok","service":"utcp-api"}`
- `/api/health/ready`: `{"status":"ready","service":"utcp-api","dependencies":{"postgres":"ok","redis":"ok"}}`
- `/api/version`: `{"service":"utcp-api","version":"0.1.0-dev","commit":"unknown","built_at":"unknown"}`

The proof also confirmed no data Service is externally published and no Ingress or Gateway API resource exists.

## Persistence Proof

`make k8s-persistence-proof` passed:

- PostgreSQL proof marker survived deletion and replacement of `postgres-0`.
- Redis proof key survived deletion and replacement of `redis-0`.
- Proof markers were removed after verification.

## Restart Proof

`make k8s-restart-proof` passed for:

- API
- worker
- scheduler
- web
- gateway

Each Deployment recreated its Pod and returned to available state.

## Security Context Summary

- Application and data ServiceAccounts set `automountServiceAccountToken: false`.
- No Roles, RoleBindings, ClusterRoles, or ClusterRoleBindings were introduced.
- Pods run as non-root image-compatible users.
- Containers set `allowPrivilegeEscalation: false`.
- Containers drop Linux capabilities.
- Pods use `RuntimeDefault` seccomp.
- No host networking, hostPath, Docker socket mount, privileged Pod, NodePort, LoadBalancer, Ingress, or Gateway API resource was introduced.

## Telephony Boundary Review

K1 introduced no Asterisk, FreeSWITCH, Kamailio, rtpengine, SIP, SIPS, RTP, SRTP, PBX-specific Service, telephony port, media privilege, runtime adapter, vendor selector, telephony taint, or runtime allowlist.

`utcp-runtime`, `traefik-system`, and `utcp-observability` remain workload-free.

## Existing Environment Preservation

The default `utcp` Compose project remained running. The unrelated `docker` Compose project and `apntalk-local` k3d cluster were not modified. The global Kubernetes context remained `k3d-apntalk-local`.

## Known Limitations

- Hosted GitHub Actions proof has not been observed.
- K1 is local development infrastructure only.
- Traefik, Gateway API, Ingress, TLS, production secrets, observability, authentication, tenancy, authorization, and telephony runtimes remain deferred.
