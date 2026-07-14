# F2 Container Build Foundation Evidence

## Scope

Phase F2 created deterministic container build definitions for the existing Laravel API and Vue web application only. It did not add Docker Compose, Kubernetes, Traefik, PostgreSQL services, Redis services, telephony runtimes, authentication, tenancy, or domain behavior.

## Selected Versions

| Component | Version or image |
| --- | --- |
| PHP runtime base | `php:8.4.15-fpm-bookworm` |
| Composer source image | `composer:2.9.5` |
| Redis PECL extension | `redis-6.3.0` |
| Node build base | `node:22.23.1-bookworm-slim` |
| Web runtime base | `nginxinc/nginx-unprivileged:1.29.4-alpine` |
| API local image | `utcp-api:dev` |
| Web local image | `utcp-web:dev` |

## Build and Test Summary

The following F2 verification commands were run successfully:

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

Containerized API tests executed the Laravel test suite without requiring PostgreSQL or Redis. Containerized web tests executed Vitest, ESLint, TypeScript checking, and the production Vite build.

## Smoke Summary

API image smoke verification confirmed:

- PHP starts inside the production image.
- Required extensions are present: `bcmath`, `intl`, `Zend OPcache`, `pcntl`, `pdo_pgsql`, `redis`, and `zip`.
- Composer autoload is available.
- Laravel boots and exposes the required health/version routes.
- `php-fpm -t` validates the PHP-FPM configuration.
- Unknown process roles fail closed with a non-zero status.
- Runtime user is `www-data`.
- `.git`, `.env`, and PHPUnit development dependencies are absent from the production image.

Web image smoke verification confirmed:

- The Nginx container starts.
- `/healthz` returns HTTP 200 with `ok`.
- `/` returns the built Vue shell.
- Built JavaScript assets are served.
- Static asset directory listing is not enabled.
- Runtime user ID is `101`.
- `node_modules` and frontend source directories are absent from the runtime image.

## Metadata Proof

Image inspection confirmed the production images carry OCI labels for title, description, version, revision, created timestamp, and source. Local development defaults were used for uncommitted build metadata:

- version: `0.1.0-dev`
- revision: `unknown`
- created: `unknown`
- source: `local`

The API image inspected as `sha256:62cefa36dfc6f009faec877d6fcf32c774b1f71556d0c739e8d9e27af5d92112`. It is configured with entrypoint `utcp-api-entrypoint`, default command `api`, user `www-data`, and exposed port `9000/tcp`.

The web image inspected as `sha256:3a0d7a49d3f8aa6b4bdf2a8792a2a7269555fed16a2bb28b01db6a09aa7ec538`. It is configured with the upstream unprivileged Nginx entrypoint, default command `nginx -g 'daemon off;'`, user `101`, and exposed port `8080/tcp`.

## Security Review

Working-tree and image-history scans found no real credential values, private keys, local `.env` files, committed dependency directories, or generated frontend build output. The only `.env`-related working-tree file was `apps/api/.env.example`, which contains synthetic placeholder values. Image history includes benign build instructions such as `.env` removal, Laravel `storage/app/private` directory creation, and upstream public package-signing key metadata.

## Known Limitations

- No Compose orchestration proof exists in F2.
- No PostgreSQL or Redis connectivity proof exists in F2.
- No Kubernetes, Traefik, SIP, RTP, Asterisk, or FreeSWITCH proof exists in F2.
- The API production image runs PHP-FPM only; HTTP ingress and upstream routing are intentionally deferred.
