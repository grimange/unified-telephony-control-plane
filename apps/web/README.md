# UTCP Web

This directory contains the Phase F1 Vue 3, Vite, and TypeScript administration shell.

The current page shows platform status using:

- `GET /api/health/live`
- `GET /api/health/ready`
- `GET /api/version`

Install and verify from the repository root:

```sh
make web-install
make web-test
make web-lint
make web-typecheck
make web-build
```

Run locally against a Laravel API on port 8000:

```sh
VITE_UTCP_API_BASE_URL=http://127.0.0.1:8000 npm run dev
```

Phase F1 intentionally does not add authentication, a UI framework, global state management, telephony domain behavior, Docker, or Kubernetes.
