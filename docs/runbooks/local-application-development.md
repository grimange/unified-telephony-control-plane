# Local Application Development

This runbook covers the Phase F1 local Laravel API and Vue administration shell.

## Prerequisites

Inspect local tools without changing the host:

```sh
make doctor
```

Phase F1 requires PHP, Composer, Node.js, and npm. Docker, Kubernetes, k3d, Helm, and Kustomize remain later-phase tools.

## Install Dependencies

```sh
make install
```

This installs Composer dependencies under `apps/api/vendor/` and npm dependencies under `apps/web/node_modules/`. These generated directories must remain untracked.

## Run the API

```sh
cd apps/api
php artisan serve --host=127.0.0.1 --port=8000
```

Available endpoints:

- `GET /api/health/live`
- `GET /api/health/ready`
- `GET /api/version`

Readiness dependencies are explicit through `UTCP_READINESS_REQUIRED_DEPENDENCIES`. For Phase F1 local development, the default is empty because PostgreSQL and Redis are not provisioned yet.

## Run the Web Shell

```sh
cd apps/web
VITE_UTCP_API_BASE_URL=http://127.0.0.1:8000 npm run dev
```

The web shell reads `VITE_UTCP_WEB_VERSION`, `VITE_UTCP_WEB_COMMIT`, and `VITE_UTCP_WEB_BUILT_AT` when provided. Safe local defaults are used otherwise.

## Verify

```sh
make test
make check
make build
```

These commands do not start long-running development servers and do not install host software.
