# Contributing

UTCP uses phase-based delivery. A change should be small enough to verify and should not implement future roadmap phases unless the task explicitly includes them.

## Working Contract

Every implementation task must identify:

- phase or subphase
- objective
- in-scope deliverables
- explicit non-goals
- authority constraints
- verification requirements
- completion criteria

Before editing, inspect the repository status, applicable docs, existing CI, and existing scaffolding. Preserve valid existing work and user-authored changes.

## Authority Rules

- UTCP owns business policy, desired state, reconciliation decisions, audit history, and normalized runtime contracts.
- PostgreSQL is canonical business storage.
- Redis is limited to queues, locks, caching, and transient projections.
- WebSockets are notifications, not canonical business state.
- Kubernetes manages workload placement, not telephony business policy.
- Traefik handles HTTP, HTTPS, and application WebSockets, not primary SIP or RTP.
- Kamailio, rtpengine, Asterisk, and FreeSWITCH remain live protocol/runtime authorities behind adapter boundaries.

Do not create duplicate management authorities. CLI commands are allowed for bootstrap, diagnostics, recovery, migration, or verification only.

## Local Checks

Run the smallest relevant checks first:

```sh
make help
make doctor
```

When shell scripts are changed, validate syntax:

```sh
bash -n scripts/doctor scripts/check-repository-hygiene
```

## Documentation

Architecture decisions belong in `docs/decisions/`. Roadmap and phase state belong in `docs/roadmap/`. Evidence must be concise, sanitized, and stored under `docs/evidence/<phase-id>/`.

Do not commit credentials, tokens, private hostnames, real telephone identities, customer data, complete noisy logs, generated build artifacts, or machine-specific secrets.

## Pull Requests

Use the repository pull request template. Include the phase, scope, verification performed, and unresolved proof gaps. Later phases must remain marked as planned or not started unless their exit criteria have been proven.
