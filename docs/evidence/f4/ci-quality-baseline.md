# F4 CI Quality Baseline Evidence

## Scope

Phase F4 automated the existing F0-F3 contracts. It did not add application features, Kubernetes manifests, Traefik, telephony runtimes, authentication, tenancy, observability services, image publication, or deployment.

## Workflows and Triggers

`Repository Hygiene` runs on:

- `pull_request`
- `push` to `main`
- `workflow_dispatch`

`Quality` runs on:

- `pull_request`
- `push` to `main`
- `workflow_dispatch`

Both workflows use `permissions: contents: read` and concurrency cancellation for superseded pull request or branch runs.

## Jobs

`Repository Hygiene`:

- shell syntax validation
- Python syntax validation
- `make help`
- `make repository-hygiene`
- `make workflow-check`
- `git diff --check`

`Quality`:

- `backend-quality`
- `frontend-quality`
- `container-quality`
- `compose-integration`
- `security-audit`

## Action Pinning Evidence

Pinned actions:

- `actions/checkout` release `v7.0.0`, commit `9c091bb21b7c1c1d1991bb908d89e4e9dddfe3e0`
- `actions/setup-node` release `v6.4.0`, commit `48b55a011bda9f5d6aeb4c2d9c7362e8dae4041e`
- `actions/cache` release `v6.1.0`, commit `55cc8345863c7cc4c66a329aec7e433d2d1c52a9`
- `shivammathur/setup-php` release `2.37.2`, commit `f3e473d116dcccaddc5834248c87452386958240`
- `docker/setup-buildx-action` release `v4.2.0`, commit `bb05f3f5519dd87d3ba754cc423b652a5edd6d2c`

`make workflow-check` passed with actionlint `1.7.12`, downloaded from a pinned release and verified against SHA-256 `8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8`.

## Local CI-Equivalent Result

`make ci` was run locally and passed.

The local CI path used the isolated Compose project `utcp-ci` and gateway port `18088`. Cleanup removed the isolated CI project and its volumes after proof.

## Isolated Compose Proof

The isolated Compose proof confirmed:

- resolved Compose configuration is valid
- expected services exist
- PostgreSQL and Redis become healthy
- migration completes successfully
- API, worker, scheduler, web, and gateway run in their intended roles
- gateway `/healthz` succeeds
- frontend root succeeds
- `/api/health/live` succeeds
- `/api/health/ready` reports PostgreSQL and Redis as `ok`
- `/api/version` succeeds
- PostgreSQL and Redis do not publish host ports

## Audit Result Summaries

Composer audit policy: fail on any advisory in the committed backend lockfile. Result: passed.

npm audit policy: fail on moderate, high, or critical advisories in all locked frontend dependencies. Result: passed with `0 vulnerabilities`.

Secret-oriented repository scan result: passed.

## Default Compose Preservation

Before F4 local proof, `docker compose ls` showed the default `utcp` project running with seven services on `infrastructure/compose/compose.yaml`.

After F4 local proof, the default `utcp` project remained running and the isolated `utcp-ci` project was absent.

## Hosted Run Status

Hosted GitHub Actions execution was not observed because the working tree was not committed or pushed.

## Known Limitations

- Hosted CI proof remains unobserved until the workflows are pushed.
- Kubernetes manifest validation is intentionally absent because no Kubernetes manifests exist yet. It remains a future K0/K1 CI lane.
- No browser login, SIP, RTP, Asterisk, FreeSWITCH, Kamailio, rtpengine, or production deployment proof exists in F4.
