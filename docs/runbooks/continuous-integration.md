# Continuous Integration Runbook

## Scope

Phase F4 automates the established F0-F3 contracts. CI validates repository hygiene, Laravel quality, Vue quality, production builds, deterministic container builds, container smoke checks, isolated Docker Compose runtime proof, dependency audits, and secret-oriented repository checks.

It does not publish images, deploy environments, create Kubernetes manifests, run telephony runtimes, or introduce authentication, tenancy, observability, SIP, RTP, Asterisk, FreeSWITCH, Kamailio, or rtpengine checks.

## Workflow Structure

`Repository Hygiene` runs on pull requests, pushes to `main`, and manual dispatch. It performs shell syntax checks, Python compilation, `make help`, repository hygiene, workflow validation with actionlint, immutable action pin validation, and `git diff --check`.

`Quality` runs these jobs:

- `backend-quality`: PHP and Composer setup, Composer validation, Laravel tests, route/config checks, and Pint in check mode.
- `frontend-quality`: Node setup, `npm ci`, Vitest, ESLint, TypeScript checking, production Vite build, and build-output verification.
- `container-quality`: production image builds, containerized test stages, smoke checks, image inspection, and `make container-check`.
- `compose-integration`: isolated Compose configuration, build, disposable proof, and unconditional cleanup.
- `security-audit`: Composer audit, npm audit, and secret-oriented repository scan.

All workflows use `permissions: contents: read`.

## Action Pinning

External GitHub Actions are pinned to full commit SHAs and preceded by comments naming the release tag used for review. `scripts/ci/check-action-pinning.py` rejects floating tags such as `main`, `master`, `latest`, and moving major-version tags.

`make workflow-check` installs actionlint through `scripts/ci/install-actionlint`. The installer downloads actionlint `1.7.12` from the upstream GitHub release and verifies the Linux amd64 archive SHA-256 before running it.

## Local Reproduction

Run the local CI-equivalent command:

```sh
make ci
```

This command runs dependency installation, repository hygiene, workflow validation, application tests and checks, production builds, container checks, dependency audits, secret scanning, and isolated Compose proof.

The isolated Compose proof uses:

```text
UTCP_CI_COMPOSE_PROJECT_NAME=utcp-ci
UTCP_CI_GATEWAY_PORT=18088
```

The wrapper delegates to `make compose-proof`, which uses isolated networks and volumes, avoids standard edge ports `80/443`, and removes proof containers, networks, and volumes after success or failure. The wrapper refuses to run against persistent local project names.

## Isolated Compose Cleanup

`scripts/ci/compose-ci` always attempts to remove only the isolated CI project:

```sh
docker compose --env-file versions.env --env-file infrastructure/compose/env.example -f infrastructure/compose/compose.yaml -p utcp-ci down --volumes --remove-orphans
```

It must not run `compose-reset`, must not delete optional debug volumes, and must not leave a persistent Compose application stack beside any CI Kubernetes runtime.

## Dependency Audit Policy

Composer policy: `composer audit --locked` fails CI on any advisory in the committed backend lockfile.

npm policy: `npm audit --audit-level=moderate` fails CI on moderate, high, or critical advisories in all locked frontend dependencies, including development dependencies. This keeps dev-only findings visible instead of silently hiding them.

## Secret Scan Policy

`scripts/ci/secret-scan` scans tracked and unignored working-tree files for private key material, common repository tokens, provider token patterns, and suspicious credential assignments. Synthetic `.env.example` files are allowed; real `.env` files are rejected.

The scanner avoids dependency directories, generated frontend output, coverage, Git metadata, and transient caches.

## Common Failures

- Workflow validation failure: run `make workflow-check` and check for an unpinned `uses:` reference or invalid workflow syntax.
- Backend failure: run `make api-test` and `make api-check`.
- Frontend failure: run `make web-test`, `make web-lint`, `make web-typecheck`, and `make web-build`.
- Container failure: run `make container-check`.
- Compose failure: rerun `UTCP_CI_COMPOSE_PROJECT_NAME=utcp-ci UTCP_CI_GATEWAY_PORT=18088 make compose-ci`. For manual inspection, run the equivalent `docker compose --env-file versions.env --env-file infrastructure/compose/env.example -f infrastructure/compose/compose.yaml -p utcp-ci logs --tail=200` before cleanup.
- Audit failure: review the named package advisory and update dependencies through the normal lockfile workflow.

## Hosted Proof Limit

Hosted GitHub Actions proof exists only after these workflow changes are committed, pushed, and observed in GitHub Actions. Local `make ci` proves the reproducible repository contract but is not a hosted-run substitute.

## Future CI Lanes

Kubernetes manifest validation remains a required future CI lane. It will be introduced with K0/K1 when real manifests exist. Its absence in F4 is dependency ordering, not abandonment of the Kubernetes quality requirement.
