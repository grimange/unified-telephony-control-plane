# F3 Docker Compose Core Platform Evidence

## Scope

Phase F3 created the local Docker Compose core platform for the existing Laravel API and Vue web application. It did not add Kubernetes, Traefik, simulator behavior, telephony runtimes, authentication, tenancy, authorization, or business-domain models.

## Selected Versions

| Component | Version or image |
| --- | --- |
| Docker Engine observed | `29.6.1` |
| Docker Compose observed | `5.3.1` |
| PostgreSQL image | `postgres:17.6-alpine` |
| Redis image | `redis:8.2.3-alpine` |
| Gateway runtime image | `nginxinc/nginx-unprivileged:1.29.4-alpine` |
| API local image | `utcp-api:dev` (`sha256:209feb44bbd43f9eff6847a1801b4d49d64f4b925c699c31a097ef5e1bf1cfdb`) |
| Web local image | `utcp-web:dev` (`sha256:83be888cb41dddbfde5ced3f0bf8bcd4d4213c46b13614bfad314433230c8a58`) |
| Gateway local image | `utcp-gateway:dev` (`sha256:15a9cafbef584a7c79f6ee32066dc042b01fe995e0be6b7743647aed1b6e7334`) |

## Resolved Service List

The Compose platform defines these services:

- `postgres`
- `redis`
- `migrate`
- `api`
- `worker`
- `scheduler`
- `web`
- `gateway`

## Network Topology

- `gateway`: `edge`, `platform`
- `web`: `platform`
- `api`: `platform`, `data`
- `worker`: `data`
- `scheduler`: `data`
- `migrate`: `data`
- `postgres`: `data`
- `redis`: `data`

PostgreSQL and Redis had no host port bindings during proof. The only host binding was the gateway on `127.0.0.1:8088`.

## Image and Role Mapping

- `api`: `utcp-api:dev`, command `api`
- `worker`: `utcp-api:dev`, command `worker`
- `scheduler`: `utcp-api:dev`, command `scheduler`
- `migrate`: `utcp-api:dev`, command `migrate`
- `web`: `utcp-web:dev`
- `gateway`: `utcp-gateway:dev`
- `postgres`: `postgres:17.6-alpine`
- `redis`: `redis:8.2.3-alpine`

## Runtime Proof

`make compose-up` started the complete platform and reported:

```text
UTCP Compose platform available at http://127.0.0.1:8088
```

`make compose-proof` passed after startup and again after an ordinary restart. It proved:

- `/healthz` through the gateway returned `ok`.
- `/` through the gateway returned the frontend shell and a built JavaScript asset.
- `/api/health/live` returned liveness `ok`.
- `/api/health/ready` returned readiness `ready` with `postgres` and `redis` as `ok`.
- `/api/version` returned `service` as `utcp-api`.
- PostgreSQL and Redis were healthy.
- The migration job exited successfully with code `0`.
- Worker and scheduler containers were running.
- API, worker, and scheduler used the expected process roles.

## Persistence Proof

`scripts/compose/persistence-proof` created a PostgreSQL marker table and row, ran ordinary Compose down without deleting volumes, restarted the platform, and confirmed the row remained available.

## Test Summary

The following verification commands run successfully during F3 implementation:

- `make help`
- `make doctor`
- `make repository-hygiene`
- `make install`
- `make test`
- `make check`
- `make build`
- `make image-build`
- `make image-test`
- `make image-smoke`
- `make image-inspect`
- `make container-check`
- `make compose-config`
- `make compose-build`
- `make compose-up`
- `make compose-status`
- `make compose-test`
- `make compose-proof`
- `scripts/compose/persistence-proof`
- `git diff --check`

One standalone `nginx -t` run against `utcp-gateway:dev` outside Compose failed because the Compose-only `api` upstream name was not resolvable. The same configuration passed inside the running Compose gateway service.

## Telephony Boundary Review

Static Compose checks confirmed there are no services named for Asterisk, FreeSWITCH, Kamailio, or rtpengine. The F3 platform defines no SIP or RTP network, no telephony host ports, and no shared environment variable selecting a PBX vendor.

## Known Limitations

- This is local Docker Compose proof only.
- No Kubernetes, k3d, Helm, Kustomize, or Traefik proof exists in F3.
- No database business schema exists beyond Laravel baseline migrations.
- No Redis-backed domain workflow exists yet.
- No SIP, RTP, PBX, simulator, carrier, or production telephony proof exists.
