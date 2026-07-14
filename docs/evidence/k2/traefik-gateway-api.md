# K2 Evidence: Traefik and Gateway API

## Summary

K2 installs a repository-managed Traefik edge in the recreated `utcp-local` cluster and exposes the K1 application gateway through Kubernetes Gateway API on the canonical one-cluster-at-a-time local edge.

The first K2 implementation used custom host ports while APNTalk owned localhost `80/443`. ADR-011 supersedes that decision. The refreshed K2 proof uses standard loopback ports `80/443`, and the operator-confirmed stopped `apntalk-local` state is expected external state, not a UTCP preservation failure.

Hosted GitHub Actions execution: not observed for the uncommitted working tree.

## Host Binding Inspection

Before cluster recreation:

- `apntalk-local` existed but was intentionally stopped before this task.
- `k3d-apntalk-local-serverlb` and `k3d-apntalk-local-server-0` were `Exited (137)`.
- `127.0.0.1:80` and `127.0.0.1:443` were free.
- The pre-existing `utcp-local` cluster used the superseded custom mappings.
- `/etc/hosts` resolved `utcp.local.test`, `sip.utcp.local.test`, `app.utcp.local.test`, and `events.utcp.local.test` to `127.0.0.1`.
- UTCP did not invoke an APNTalk lifecycle command.

After recreation:

```text
k3d-utcp-local-serverlb  127.0.0.1:80->80/tcp, 127.0.0.1:443->443/tcp, 127.0.0.1:6550->6443/tcp
```

Inspection confirmed UTCP did not publish `18080` or `18443`.

## Versions

- Gateway API Standard CRDs: `v1.5.1`
- Gateway API Standard artifact SHA-256: `751002b3b91a87f7ae3bd2517c79a47a8d7ed6702901808a1cf9bd97d284f9b8`
- Traefik Helm chart: `41.0.2`
- Traefik image: `docker.io/traefik:v3.7.7`
- K3s Kubernetes version: `v1.35.3+k3s1`

The Traefik chart declared Kubernetes support for `>=1.25.0-0`. The chart default app version was older than the selected patched proxy, so the image was explicitly overridden to `v3.7.7`.

## Cluster Recreation and K1 Preservation

`make k3d-recreate-proof` passed after replacing the K2 host-port mappings in `infrastructure/k3d/cluster.yaml` with the standard local edge.

`make k8s-apply`, `make k8s-status`, `make k8s-proof`, `make k8s-persistence-proof`, and `make k8s-restart-proof` passed after recreation. K1 proof observed:

- `/healthz`: `ok`
- `/api/health/live`: `{"status":"ok","service":"utcp-api"}`
- `/api/health/ready`: `{"status":"ready","service":"utcp-api","dependencies":{"postgres":"ok","redis":"ok"}}`
- `/api/version`: `{"service":"utcp-api","version":"0.1.0-dev","commit":"unknown","built_at":"unknown"}`

The recreated local-path PVCs contain only synthetic local-development data. No persistent-volume continuity was claimed across cluster deletion.

## Gateway API and Traefik Status

`make gateway-apply` passed.

Observed status:

- Helm release `traefik`: `deployed`, revision `2`
- Traefik Deployment: `1/1`, image `docker.io/traefik:v3.7.7`
- Traefik Service: `LoadBalancer`, ports `80` and `443`
- GatewayClass `utcp-traefik`: `Accepted=True`
- Gateway `traefik-system/utcp-local`: `Programmed=True`
- Listener `web`: `Accepted=True`, `ResolvedRefs=True`, `Programmed=True`, `attachedRoutes=2`
- Listener `websecure`: `Accepted=True`, `ResolvedRefs=True`, `Programmed=True`, `attachedRoutes=2`
- HTTPRoutes `app-http-redirect`, `app-https`, `root-http-redirect`, and `root-https`: `Accepted=True`, `ResolvedRefs=True`

Gateway API `v1.5.1` Standard includes TLSRoute CRDs. K2 installs the pinned Standard artifact because Traefik `v3.7.7` watches the Standard resource set, but UTCP creates no TLSRoute, TCPRoute, UDPRoute, SIP, or RTP resources.

## TLS Proof

Generated certificate:

```text
notBefore=Jul 13 21:22:56 2026 GMT
notAfter=Aug 14 21:22:56 2027 GMT
DNS:app.utcp.local.test, DNS:utcp.local.test
```

Private keys and certificates are under ignored `.runtime/tls/` storage. The host trust store was not modified.

## External Runtime Proof

`make gateway-proof` passed.

Observed:

- HTTP redirect: `https://app.utcp.local.test/api/version?proof=1`
- `/healthz`: `ok`
- `/api/health/live`: `{"status":"ok","service":"utcp-api"}`
- `/api/health/ready`: `{"status":"ready","service":"utcp-api","dependencies":{"postgres":"ok","redis":"ok"}}`
- `/api/version`: `{"service":"utcp-api","version":"0.1.0-dev","commit":"unknown","built_at":"unknown"}`
- `/` served the Vue shell.
- `https://utcp.local.test/` served the same Vue shell.

Proof used the generated local CA with `curl --cacert` and explicit host resolution.

## Reserved Hostname and Dashboard Proof

`make gateway-proof` confirmed:

- Host-header mismatch did not route to the UTCP application.
- `sip.utcp.local.test` did not route to the UTCP application.
- `events.utcp.local.test` did not route to the UTCP application.
- The Traefik dashboard/API was not externally reachable.
- No `kubectl port-forward` process remained.
- No K1 Service in `utcp-platform` or `utcp-data` was exposed as `NodePort` or `LoadBalancer`.
- UTCP did not publish `18080` or `18443`.
- `utcp-runtime` remained empty.

## Existing Environment Preservation

After proof:

- Default UTCP Compose project remained running.
- APNTalk Compose project `docker` remained running for PostgreSQL and Redis.
- `apntalk-local` remained present and intentionally stopped; final inspection still showed `k3d-apntalk-local-serverlb` and `k3d-apntalk-local-server-0` stopped with exit `137`.
- UTCP owned localhost `80` and `443` through `k3d-utcp-local-serverlb`.
- UTCP did not stop, start, delete, recreate, or mutate `apntalk-local`.
- Global Kubernetes context remained `k3d-apntalk-local`.
- `utcp-local` remained running.

## Known Limitations

- Hosted CI proof has not been observed for the uncommitted working tree.
- Browser trust was not configured.
- Authentication, WebSocket events, SIP, RTP, Asterisk, FreeSWITCH, Kamailio, rtpengine, production ingress readiness, and observability were not implemented or proven.
