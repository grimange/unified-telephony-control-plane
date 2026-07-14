# Container Images Runbook

## Scope

This runbook covers the Phase F2 local container images only:

- `utcp-api:dev`
- `utcp-web:dev`

Docker Compose, Kubernetes, Traefik, PostgreSQL services, Redis services, and telephony runtimes are not implemented in this phase.

## Prerequisites

- Docker Engine available to the current user.
- Project dependencies and lockfiles committed under `apps/api/` and `apps/web/`.
- No real `.env` files, private keys, tokens, certificates, or database dumps in the build context.

Check local tooling:

```sh
make doctor
docker version
docker info
```

## Build Images

Build both production images:

```sh
make image-build
```

Build one image:

```sh
make image-build-api
make image-build-web
```

The build uses explicit versioned base images recorded in `versions.env`. Build metadata is accepted through Makefile variables:

```sh
make image-build UTCP_BUILD_VERSION=0.1.0-dev UTCP_BUILD_COMMIT=unknown UTCP_BUILD_CREATED=unknown
```

## Backend Process Roles

The backend image supports these roles:

```sh
docker run --rm utcp-api:dev api
docker run --rm utcp-api:dev worker
docker run --rm utcp-api:dev scheduler
docker run --rm utcp-api:dev migrate
```

Use `api` for PHP-FPM foreground execution. Use `worker` for the Laravel queue worker and `scheduler` for Laravel scheduler execution. Use `migrate` only as an explicit deployment operation; migrations do not run automatically during ordinary container startup.

Direct diagnostic commands remain available:

```sh
docker run --rm utcp-api:dev php artisan --version
docker run --rm utcp-api:dev php-fpm -t
```

Unknown roles must fail with a non-zero exit status.

## Test Images

Run containerized tests:

```sh
make image-test
```

Backend tests run from the backend test stage with development dependencies. Frontend tests, lint, type checking, and production build validation run from the frontend test stage.

## Smoke Checks

Run bounded smoke verification:

```sh
make image-smoke
```

Backend smoke checks validate PHP, required extensions, Composer autoloading, Laravel boot, route registration, PHP-FPM configuration, unknown-role rejection, non-root execution, and absence of `.git` and `.env`.

Frontend smoke checks start an ephemeral container, verify `/healthz`, verify the SPA root and built assets are served, confirm non-root execution, and check that source dependency directories are absent.

## Inspect Metadata

Inspect useful image properties:

```sh
make image-inspect
docker image inspect utcp-api:dev
docker image inspect utcp-web:dev
docker history --no-trunc utcp-api:dev
docker history --no-trunc utcp-web:dev
```

Do not paste full image histories into evidence when they contain noisy package-manager output. Record concise proof only.

## Troubleshooting

If Docker cannot build because the daemon is unavailable, start or repair Docker outside this repository and rerun `make doctor`.

If dependency installation fails inside an image build, verify that `apps/api/composer.lock` and `apps/web/package-lock.json` are committed and current.

If frontend smoke checks fail on port publishing, verify that Docker can publish loopback ports for the current user.

If backend smoke checks report missing PHP extensions, rebuild without relying on stale local image tags:

```sh
make image-build-api
make image-smoke-api
```

## Cleanup

The smoke targets remove their ephemeral containers automatically.

The following cleanup commands are destructive to local Docker artifacts and should only be run intentionally:

```sh
docker image rm utcp-api:dev utcp-web:dev
docker image prune
docker builder prune
```
