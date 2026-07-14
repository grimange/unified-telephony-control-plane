# UTCP API

This directory contains the Phase F1 Laravel 12 API skeleton for the Unified Telephony Control Plane.

The API currently exposes only platform foundation endpoints:

- `GET /api/health/live`
- `GET /api/health/ready`
- `GET /api/version`

Install and verify from the repository root:

```sh
make api-install
make api-test
make api-check
```

Run locally:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Phase F1 intentionally does not implement authentication, tenancy, telephony adapters, database business schemas, queues, Docker, or Kubernetes.
