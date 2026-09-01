# Unified Telephony Control Plane

Unified Telephony Control Plane (UTCP) is a vendor-neutral control plane for telephony platforms. It is intended to sit beneath applications such as dialers, contact centers, IVR systems, and voice automation products.

UTCP owns desired state, tenant and operator policy, normalized runtime contracts, reconciliation decisions, health history, and auditability. It does not replace the live protocol authority of Kamailio, rtpengine, Asterisk, FreeSWITCH, Kubernetes, PostgreSQL, Redis, or Traefik.

UTCP distinguishes a Kubernetes Node/host from a `RuntimeNode`. Kubernetes
owns machine and workload facts such as Node conditions, capacity, and Pod
placement. UTCP may associate those facts with telephony runtimes and expose
their operational consequences—RuntimeNode readiness, active calls and
bindings, and maintenance eligibility—without becoming a generic Kubernetes
dashboard. Multi-machine Host visibility is a planned K-series infrastructure
track; it does not change the current C6 -> T4 mainline.

## Current Status

This repository has completed the F0-F4, K0-K4, C0-C5, and T0 foundations, proving a PostgreSQL-backed application kernel, identity/tenancy/authorization, a runtime-node registry, a command/event/projection/reconciliation engine, a deterministic simulator adapter, a telephony-session/conference domain, and a live Asterisk ARI adapter boundary end to end. T1 Kamailio SIP-over-WSS signaling is complete (see below). It contains a Laravel API process, a Vue administration shell, deterministic local container images, disposable Docker Compose compatibility proof, GitHub Actions workflows for the established contracts, a repository-managed local k3d/K3s cluster foundation, Kustomize-managed local Kubernetes application manifests, a Helm-managed Traefik edge exposed through Kubernetes Gateway API, repository-managed Pod Security plus NetworkPolicy boundaries, a restricted local observability stack, PostgreSQL-backed application-kernel primitives, first-party session identity/tenancy/authorization, a PostgreSQL-authoritative tenant-scoped runtime-node registry, PostgreSQL-backed runtime-engine tables for event receipts, observations, projection checkpoints, and reconciliation state, and a deterministic, runtime-neutral simulator adapter selected explicitly through the same authenticated runtime-registry API as every other adapter family.

The K4 Kubernetes platform deploys the existing UTCP application, PostgreSQL, Redis, migration Job, API, worker, scheduler, web runtime, internal application gateway, Traefik, GatewayClass, Gateway, HTTPS listener, HTTP redirect listener, HTTPRoutes for `app.utcp.local.test` and `utcp.local.test`, Pod Security Admission labels, default-deny NetworkPolicies with explicit application/data/observability allow paths, Prometheus, Alertmanager, Grafana, kube-state-metrics, Loki, and Alloy. C0 adds modular-monolith kernel code for runtime-neutral operations, leases, fencing, transactional outbox, inbox deduplication, idempotency, append-only audit, execution context, and event envelopes. C1 adds PostgreSQL-authoritative users, tenants, memberships, code-owned built-in roles/capabilities, first-party web sessions, active-tenant selection, server-computed capability projection, web-admin management, password lifecycle, suspension behavior, and C0 audit integration. C2 adds `RuntimeNode` as the only canonical telephony execution-node registry entity, with tenant ownership, desired lifecycle state, normalized endpoints, encrypted write-only credentials, runtime-neutral capability declarations, C1 authorization, and C0 audit/idempotency/outbox integration. C3 adds automatic outbox dispatch, generic command worker contracts, raw runtime-event receipts, normalizer contracts, observations, projection checkpoints, stale-observation derivation, and reconciliation state with leases and fencing. It does not deploy tracing, SIP, RTP, PBX runtimes, telephony sessions, conference behavior, live runtime adapters, Asterisk, FreeSWITCH, Kamailio, or rtpengine.

C4 adds a deterministic, runtime-neutral simulator adapter (`runtime_family: simulator`, `adapter_key: simulator-deterministic`) selected only through the existing authenticated runtime-registry API, a `simulator-event-source` Kubernetes process role with no public exposure, and a deterministic scenario catalog (steady-ready, transient-failure-then-ready, terminal-failure, timeout-then-ready, duplicate-observation, disconnect-reconnect, configuration-drift-then-converge) proven live against the deployed C3 workers. It does not deploy telephony sessions, conference behavior, SIP registration, or any live Asterisk, FreeSWITCH, Kamailio, or rtpengine adapter.

C5 is complete locally. PostgreSQL is authoritative for telephony sessions, conferences, runtime bindings, and conference participants; C3 observations remain runtime evidence only. The deployed canonical native k3s runtime has proven authenticated session creation, simulator-bound conference open-to-ready, participant admit-to-joined, participant remove-to-left, draining admission rejection, conference close-to-closed, scheduler-driven session expiry, worker restart recovery, bounded metrics, alerts, security, and disposable Compose compatibility. A C5 `TelephonySession` is only an authenticated user's tenant-scoped control-plane telephony authorization session; it is not SIP registration, media connectivity, a call, microphone access, or a runtime channel.

T0 introduces the first real runtime-adapter boundary for Asterisk ARI. It adds an `asterisk-ari` adapter, internal `AsteriskAriClient`, dynamic `asterisk-ari-events` listener role, internal local Asterisk ARI Kubernetes fixture, node-scoped listener leases, C3 connection epochs, raw ARI evidence receipts, runtime-neutral readiness observations, bounded metrics, and alerts. RuntimeNode remains the canonical PBX-node management authority: the web-admin UI renders backend-provided runtime families, adapter keys, capability metadata, per-node adapter-configuration support, runtime evidence, and scoped audit history from the authenticated RuntimeNode APIs. Asterisk ARI adapter settings are explicit per-node PostgreSQL records; environment values provide only bounded defaults for creating those records. T0 is limited to ARI transport, authentication, inspection, WebSocket connectivity, event ingestion, and runtime-node readiness. It does not implement ConfBridge, conference execution, channel control, SIP, RTP, media, PJSIP endpoints, browser calling, or Asterisk support for C5 conference operations. Final natural browser acceptance and remaining T0 restart, authentication-failure, and recovery proofs are still pending.

T1 is complete (`PHASE_T1_COMPLETE`, evidence `docs/evidence/t1/kamailio-sip-over-wss-signaling.md`), and so is T2 Asterisk conference execution. The UI Foundation track UI-A through UI-E is complete (see `docs/roadmap/ui-foundations.md`). T5 convergence, failover, and recovery is complete in the repository with closure evidence recorded; T3 rtpengine browser media and T3-S3B external failure/containment proof are complete on the canonical native k3s environment. V0 and T4 FreeSWITCH parity are also complete; only R0 remains planned. `UTCP_PHASE` remains `T1`, the CI-guarded marker for the completed original foundation sequence — it is not the current roadmap position. `docs/roadmap/phase-status.md` is the authoritative current-state ledger. The paragraph below records the T1 corridor as originally delivered.

T1 implementation delivered the following. The backend TelephonySession-scoped short-lived SIP registration credentials through the authenticated telephony API, stores only MD5 HA1 verifier material, exposes metadata-only readback, and revokes active signaling credentials when the TelephonySession ends or expires. C3 event-source identity is generalized so RuntimeNode-backed listeners and shared platform observers use the same PostgreSQL lease, fencing, epoch, receipt, and checkpoint authority. The T1-B registrar foundation adds a pinned Kamailio runtime, least-privilege PostgreSQL authentication and usrloc roles, a trusted `sip.utcp.local.test` WSS route, REGISTER-only policy, native usrloc persistence, live digest REGISTER, refresh, replacement, explicit deregistration, session-end revocation, bounded runtime expiration, wrong-password and unsupported-algorithm rejection, Kamailio restart recovery, NetworkPolicy proof, and safe status/metrics foundation. The T1-C repository implementation adds the fenced registration observer, C3 registration receipts/projection, and registration reconciliation. The T1-D repository implementation adds canonical User & Access Management list/detail surfaces and places signaling metadata plus one-time credential issuance beneath the user's active TelephonySession. The T1-F repository implementation extends disposable Compose compatibility proof to start an isolated Kamailio registrar, disposable WSS edge, PostgreSQL role initialization, registration observer, SIP proof, projection proof, session-end expiry proof, safe status, and automatic cleanup using the same application and C3 authorities. It does not add browser SIP registration, permanent SIP accounts, provider-node binding, manual registrar/observer/projection/reconciliation controls, natural browser acceptance, or full T1 promotion; those were closed by the subsequent T1-E natural browser acceptance and T1-G final closure corridors recorded in the T1 evidence document.

## Architecture Direction

- Initial application architecture: modular monolith.
- Canonical business storage: PostgreSQL.
- Transient coordination, queues, locks, caching, and projections: Redis.
- Identity, tenants, memberships, roles, capabilities, and authorization-relevant audit records: PostgreSQL.
- Runtime nodes, endpoint configuration, encrypted credential metadata, declared runtime capabilities, and desired lifecycle state: PostgreSQL.
- Runtime-node management catalogs, adapter-configuration visibility, runtime evidence, and scoped audit history: authenticated backend RuntimeNode APIs backed by PostgreSQL and code-owned registry configuration.
- Browser authentication: first-party Laravel sessions over the existing same-origin HTTPS edge.
- HTTP, HTTPS, and application WebSocket ingress: Traefik.
- Live SIP signaling execution: Kamailio; T1 is complete, including the registrar runtime foundation, observer/projection repository corridor, management UI surface, and natural browser acceptance.
- RTP/SRTP media relay: rtpengine, in T3; the canonical local external-media projection, positive browser scenarios, four negative failure cases, restoration, no-fallback assertions, and containment are proven. See `docs/evidence/t3/t3-s3b-external-browser-media-proof.md`.
- Call execution runtimes: Asterisk and FreeSWITCH behind adapter contracts.
- Control-plane kernel first; runtime-node registry second; command/event/reconciliation third; deterministic simulator and real runtime adapters are later phases.
- Canonical current V1 environment: native k3s on `utcp-dev01`, Kubernetes context `default`.
- Optional non-canonical local integration environment: the historical `utcp-local` k3d cluster.
- Disposable validation runtime: Docker Compose, used for container compatibility and integration proof only.
- Docker Engine remains required for image builds, k3d node containers, and the local registry.

Real telephony, PSTN, carrier trunks, and production SBC behavior are out of scope for the current foundation.

## Repository Map

- `apps/api/` - Laravel 12 API skeleton and platform health/version endpoints.
- `apps/web/` - Vue 3, Vite, and TypeScript administration shell.
- `infrastructure/compose/` - Docker Compose compatibility/debug configuration and safe example environment.
- `infrastructure/docker/` - Dockerfiles, entrypoint scripts, PHP settings, static web-server configuration, and local gateway configuration.
- `infrastructure/helm/` - third-party Helm values managed by the repository.
- `infrastructure/k3d/` - local k3d cluster and namespace contract.
- `infrastructure/kubernetes/` - Kustomize-managed local Kubernetes application base and Gateway API resources.
- `docs/architecture/` - architectural overview and authority boundaries.
- `docs/decisions/` - architecture decision records.
- `docs/roadmap/` - implementation roadmap and phase status.
- `docs/runbooks/` - operational and verification runbooks with immediate use.
- `docs/evidence/` - concise, sanitized evidence from phase verification.
- `scripts/` - repository helper scripts used by `make` and CI.
- `.github/` - pull request and CI hygiene configuration.

Future infrastructure and telephony trees are introduced only by the phases that implement them.

## Commands

```sh
make help
make doctor
make install
make test
make check
make build
make image-build
make image-test
make image-smoke
make image-inspect
make container-check
make local-config-check
make local-up
make local-status
make local-proof
make local-down
make compose-config
make compose-build
make compose-debug-up
make compose-status
make compose-proof
make compose-down
make workflow-check
make security-audit
make ci
make k3d-config-check
make k3d-registry-check-test
make k3d-create
make k3d-status
make k3d-verify
make k3d-registry-proof
make k3d-recreate-proof
make k3d-delete
make ci-k3d
make k8s-config-check
make k8s-image-build
make k8s-image-push
make k8s-apply
make k8s-status
make k8s-proof
make k8s-persistence-proof
make k8s-restart-proof
make k8s-delete
make gateway-config-check
make gateway-crds-install
make traefik-install
make gateway-tls
make gateway-apply
make gateway-status
make gateway-proof
make gateway-delete
make security-config-check
make security-apply
make security-status
make security-proof
make security-delete
make observability-config-check
make observability-install
make observability-apply
make observability-status
make observability-proof
make observability-persistence-proof
make observability-delete
make control-plane-config-check
make control-plane-test
make control-plane-migrate-proof
make control-plane-proof
make control-plane-status
make identity-config-check
make identity-test
make identity-bootstrap-local
make identity-api-proof
make identity-status
make user-access-reset-password
make runtime-registry-config-check
make runtime-registry-test
make runtime-registry-api-proof
make runtime-registry-browser-proof
make runtime-registry-status
make runtime-engine-config-check
make runtime-engine-test
make runtime-engine-proof
make runtime-engine-status
make simulator-config-check
make simulator-test
make simulator-api-proof
make simulator-runtime-proof
make simulator-status
make telephony-domain-config-check
make telephony-domain-test
make telephony-domain-api-proof
make telephony-domain-runtime-proof
make telephony-domain-status
make asterisk-ari-config-check
make asterisk-ari-test
make asterisk-ari-api-proof
make asterisk-ari-runtime-proof
make asterisk-ari-status
```

`make doctor` inspects local tools without installing or modifying host software. It distinguishes tools required now, tools required in later infrastructure phases, and optional tools.

Application-specific targets are also available:

```sh
make api-install
make api-test
make api-check
make web-install
make web-test
make web-lint
make web-typecheck
make web-build
```

Container image targets are available for Phase F2:

```sh
make image-build-api
make image-build-web
make image-build
make image-test-api
make image-test-web
make image-test
make image-smoke-api
make image-smoke-web
make image-smoke
make image-inspect
make container-check
```

## Local Application Development

Install dependencies:

```sh
make install
```

Run the Laravel API locally:

```sh
cd apps/api
php artisan serve --host=127.0.0.1 --port=8000
```

Run the Vue administration shell locally:

```sh
cd apps/web
VITE_UTCP_API_BASE_URL=http://127.0.0.1:8000 npm run dev
```

The API exposes these Phase F1 platform endpoints:

- `GET /api/health/live` - process liveness; no dependency checks.
- `GET /api/health/ready` - configured dependency readiness with sanitized dependency status.
- `GET /api/version` - sanitized service version, commit, and build timestamp metadata.

Readiness dependencies are configured explicitly through `UTCP_READINESS_REQUIRED_DEPENDENCIES`. Phase F1 defaults to no required external dependencies because PostgreSQL and Redis are not provisioned until later phases.

## Identity and Tenancy

C1 provides first-party session authentication through the existing same-origin application:

- `GET /api/v1/auth/session` returns the authenticated user summary, active tenant, available memberships, and server-computed capability keys.
- `POST /api/v1/auth/login` authenticates with a secure HTTP-only session cookie and rotates the session.
- `POST /api/v1/auth/logout` invalidates the server session and clears tenant context.
- `POST /api/v1/auth/tenant-context` selects an active tenant only when the server verifies active membership or explicit platform authority.
- `/login`, `/change-password`, `/admin/tenants`, `/admin/users`, and `/admin/memberships` provide the minimal web-admin surface.

Bootstrap is exceptional initialization only:

```sh
make identity-bootstrap-local
```

The local bootstrap target writes proof credentials under ignored `.runtime/identity/` and does not print passwords during normal invocation. Normal user, tenant, membership, and role-assignment management is performed through the web-admin/API authority. C1 does not implement public self-registration, OAuth/OIDC, MFA, telephony credentials, SIP registration, runtime nodes, conferences, or simulator behavior.

Existing-user break-glass password recovery is available as a bounded operational command. It resolves one existing user, generates the temporary password inside the application, expires it, revokes existing sessions, requires password change through the normal web flow, and preserves account status, roles, memberships, tenants, and capabilities:

```sh
make user-access-reset-password \
  USER='operator@example.com' \
  REASON='Natural browser acceptance' \
  EXPIRES_IN=30 \
  SHOW_PASSWORD=1
```

The direct Artisan form is:

```sh
php artisan utcp:user-access:reset-password \
  --user='operator@example.com' \
  --reason='Natural browser acceptance' \
  --expires-in=30 \
  --show-password
```

This command is recovery tooling only. It is not normal user creation, role management, tenant management, permission management, account activation, or an authentication bypass. Terminal output containing a temporary password must not be copied into evidence or committed files.

## Runtime Registry

C2 provides tenant-scoped runtime-node administration through the same session-authenticated web/API surface:

- `RuntimeNode` is the only canonical registry entity for managed telephony execution providers.
- Runtime family (`asterisk`, `freeswitch`) and adapter key (`asterisk-ari`, `freeswitch-esl`) are stored as configuration metadata only; no adapter is instantiated in C2.
- Desired lifecycle states are `draft`, `active`, `draining`, and `disabled`; observed state remains `unobserved` or `unknown` until C3 introduces observation and reconciliation.
- Endpoints are normalized configuration records for `control`, `events`, and `health` purposes. They do not contain embedded credentials.
- Runtime credentials are encrypted at rest and write-only through the API/UI. Responses expose only safe metadata and a fingerprint.
- Runtime capabilities are code-owned declarations about a node's potential support. They are separate from C1 user authorization capabilities.

Use:

```sh
make runtime-registry-config-check
make runtime-registry-test
make runtime-registry-api-proof
make runtime-registry-browser-proof
make runtime-registry-status
```

The runtime registry does not connect to Asterisk, FreeSWITCH, ARI, ESL, SIP, event WebSockets, or health endpoints.

## Runtime Engine

C3 adds the runtime-neutral processing engine that later adapters must use:

- outbox dispatcher: claims PostgreSQL outbox messages, leases them, delivers transient queue work, and uses fencing to prevent stale completion;
- command worker: reloads runtime operations from PostgreSQL authority, claims leases through C0, resolves handlers and adapter contracts, and records success, retry, terminal failure, cancellation, or expiry;
- event normalizer: claims raw runtime-event receipts, resolves a registered normalizer, applies observations and projection checkpoints atomically, and records unsupported events observably;
- reconciler: compares desired and observed state through registered reconcilers, leases one target at a time, derives idempotent operations, and records converged, waiting, blocked, unsupported, or operation-required results.

PostgreSQL is canonical for operations, outbox rows, raw event receipts, observations, projection checkpoints, reconciliation state, leases, and fencing. Redis remains transient queue delivery only.

The production engine intentionally has no simulator, no null/no-op adapter, no ARI or ESL client, no Asterisk or FreeSWITCH listener, no manual reconcile/project route, and no public runtime-command execution API. Test-only handlers, normalizers, adapters, and reconcilers live only in the test suite.

Use:

```sh
make runtime-engine-config-check
make runtime-engine-test
make runtime-engine-proof
make runtime-engine-status
make simulator-config-check
make simulator-test
make simulator-api-proof
make simulator-runtime-proof
make simulator-status
```

## Deterministic Simulator

C4 adds a deterministic, runtime-neutral simulator adapter that proves the C0-C3 contracts end to end without any real telephony runtime:

- `RuntimeNode` is selected into the simulator explicitly through the existing authenticated runtime-registry API: `runtime_family: simulator`, `adapter_key: simulator-deterministic`. There is no hidden environment gate or implicit fallback.
- The adapter, its `inspect`/`apply_configuration` operation handlers, and its event normalizer are registered under the `simulator-deterministic` adapter key; the generic C3 engine never branches on the simulator.
- The `simulator-event-source` Kubernetes process role publishes scheduled simulator events automatically; it has no public Service, Gateway route, or external egress beyond DNS, PostgreSQL, and Redis.
- The scenario catalog is deterministic given a seed: `steady-ready`, `transient-failure-then-ready`, `terminal-failure`, `timeout-then-ready`, `duplicate-observation`, `disconnect-reconnect`, `configuration-drift-then-converge`.
- `simulator_operations_total`, `simulator_scheduled_events`, `simulator_event_publish_total`, `simulator_scenario_transitions_total`, `simulator_connection_epochs_total`, and `simulator_reconciliation_total` are exposed through `/api/metrics` with bounded, non-high-cardinality labels; four alerts cover event-source unavailability, scheduled-event backlog, repeated terminal failure, and stuck reconciliation.

See [`docs/runbooks/deterministic-simulator.md`](docs/runbooks/deterministic-simulator.md) for the full configuration and verification runbook.

## Container Images

Phase F2 local image names:

- `utcp-api:dev` - Laravel API image.
- `utcp-web:dev` - compiled Vue static web image.

Build both production images:

```sh
make image-build
```

Run containerized tests:

```sh
make image-test
```

Run bounded smoke checks:

```sh
make image-smoke
```

Inspect image metadata and runtime configuration:

```sh
make image-inspect
```

The backend image supports one explicit process role per container:

- `api` - starts PHP-FPM in foreground mode.
- `worker` - starts Laravel queue worker with bounded defaults.
- `scheduler` - starts Laravel scheduler worker.
- `migrate` - runs `php artisan migrate --force` as an exceptional deployment role.

Unknown backend roles fail closed with a non-zero exit status. Direct diagnostic commands such as `php artisan --version`, `php artisan test`, and `php-fpm -t` remain available through the entrypoint.

The frontend image serves compiled static assets through an unprivileged Nginx runtime on port `8080` and exposes `/healthz`. It does not proxy API traffic in this phase.

## Local Runtime Authority

The canonical current V1 environment is native k3s on `utcp-dev01` in Kubernetes context `default`. Normal browser proof, API proof, identity and runtime-node management, C3 processing, and future simulator work target the native edge at `192.168.254.124` through the repository's `server-*` lifecycle.

```text
https://app.utcp.local.test/
```

Use the top-level local lifecycle:

```sh
make server-image-sync
make server-config-check
make server-image-preflight
make server-apply
make server-status
make server-proof
```

`Native k3s Images` publishes immutable `sha-<commit>` images and a
commit-specific image-lock artifact on pushes to `main`. Promote the exact
published commit with `make server-image-sync` (or set
`UTCP_SERVER_SOURCE_COMMIT` to a full commit SHA) before preflight and apply.
Promotion is authenticated, validates source/tag/digest provenance, and
atomically installs `.runtime/native-k3s/image-lock.env`. `server-apply` uses
the active lock; it does not imply that the current repository HEAD is desired
unless that lock was promoted. Manual image rebuilding or pushing is not the
normal native-k3s path.

The `local-*` lifecycle is retained only for explicitly optional, non-canonical k3d integration work. It must not be used as current V1 live or acceptance authority. If a historical k3d environment is running, native checks fail closed before canonical proof can proceed.

Docker still matters locally:

```text
Dockerfiles
└── define application images

Docker Engine
├── builds images
├── runs k3d node containers
└── runs the local registry

Docker Compose
└── disposable compatibility proof and explicit debug mode
```

## Docker Compose Proof And Debug

Phase F3 introduced the Compose topology, but after the local runtime authority cutoff it is no longer the normal integrated local runtime. `make compose-proof` starts an isolated disposable Compose project, validates PostgreSQL, Redis, API, worker, scheduler, web, gateway, C3 process roles, and the T1 Kamailio signaling compatibility corridor, then removes its containers, networks, generated secrets, and disposable volumes.

Build and validate the Compose configuration:

```sh
make compose-config
make compose-build
```

Run the disposable Compose compatibility proof:

```sh
make compose-proof
```

An optional debug runtime is still available explicitly:

```sh
make compose-debug-up
make compose-status
make compose-logs
make compose-down
```

The debug gateway defaults to `http://127.0.0.1:18088` and must not bind standard Kubernetes edge ports `80` or `443`.

## Local Kubernetes Gateway

Phase K2 exposes the K1 internal application gateway through repository-managed Traefik and Gateway API resources.

Canonical local URLs:

```text
http://app.utcp.local.test/
https://app.utcp.local.test/
https://utcp.local.test/
```

Only one local application k3d cluster may own `127.0.0.1:80` and `127.0.0.1:443` at a time. When UTCP is active, the `utcp-local` load balancer owns those standard ports. When APNTalk is active, the operator stops UTCP first and starts APNTalk explicitly. UTCP scripts never stop or start APNTalk automatically.

Traefik is configured internally on listener and container ports `80` and `443`.

K2 activates only `app.utcp.local.test` and `utcp.local.test`. `sip.utcp.local.test` and `events.utcp.local.test` remain reserved and unrouted.

`make gateway-tls` and `make gateway-tls-apply` apply the operator-prepared local certificate and key from ignored `.runtime/tls/utcp-local.crt` and `.runtime/tls/utcp-local.key` to the Gateway TLS Secret. Local scripts validate the certificate/key pair and served fingerprint. They do not install a CA, generate a replacement CA, modify `/etc/hosts`, or fall back to the retired self-signed runtime certificate path. CI may still create isolated CI-only certificate material in CI-owned temporary storage.

Future active WSS endpoints will share TCP `443` through Traefik by hostname:

```text
wss://sip.utcp.local.test/
wss://events.utcp.local.test/
```

## Local Kubernetes Security

Phase K3 applies pinned Pod Security Admission labels and NetworkPolicies to UTCP-owned namespaces. `utcp-platform`, `utcp-data`, `utcp-runtime`, `utcp-observability`, and `traefik-system` enforce the Kubernetes `restricted` profile pinned to `v1.35`.

`utcp-platform`, `utcp-data`, `utcp-runtime`, and `utcp-observability` are default-deny for ingress and egress. `traefik-system` is also policy-controlled while still allowing public ingress to Traefik `web` and `websecure`, DNS, exact Kubernetes API access required by the Gateway API provider, and egress to the internal application gateway.

Current allowed paths are limited to:

```text
Traefik -> utcp-platform/gateway
gateway -> web, api
api, worker, scheduler, migration -> PostgreSQL, Redis
```

`web`, `gateway`, and Traefik do not receive data-store access. `utcp-runtime` and `utcp-observability` remain workload-free and default-denied until later phases add real workloads and their own explicit policies.

K3 does not introduce telephony listeners, SIP, RTP, PBX runtimes, Reverb, or placeholder WSS routes.

## Local Kubernetes Observability

Phase K4 installs local-development observability into `utcp-observability` while preserving the K3 `restricted` Pod Security profile and default-deny model.

The stack is:

```text
Prometheus Operator / Prometheus / Alertmanager / Grafana / kube-state-metrics
Loki single-binary
Alloy Kubernetes API log collection
```

Prometheus scrapes justified internal targets, including Prometheus, Alertmanager, kube-state-metrics, Traefik metrics, Loki, Grafana, and observability component metrics. Node-exporter and privileged host-level agents are disabled so restricted Pod Security remains intact.

Alloy collects Pod logs through the Kubernetes API from only `utcp-platform`, `utcp-data`, `utcp-observability`, and `traefik-system`. It does not mount host paths, container runtime sockets, or node log directories.

Grafana is cluster-internal only. Prometheus, Loki, and Alertmanager data sources plus UTCP dashboards are provisioned declaratively. Use repository proof scripts for temporary port-forwarded checks; no Grafana, Prometheus, Alertmanager, Loki, or metrics endpoint is exposed through Traefik.

Application and gateway logs are structured JSON. The internal gateway generates a request ID, forwards it to Laravel, and includes it in responses and logs. Request IDs are log fields, not Loki labels. Sensitive headers, credentials, request bodies, response bodies, and private keys must not be logged.

K4 uses short local retention and local-path PVCs for Prometheus, Alertmanager, Grafana, and Loki. This is not production observability durability, high availability, tracing, telemetry export, or Grafana Cloud integration.

```sh
make observability-apply
make observability-status
make observability-proof
make observability-persistence-proof
```

Stop the platform while preserving named PostgreSQL and Redis volumes:

```sh
make compose-down
```

Destroy local Compose data volumes only with the explicit reset guard:

```sh
make compose-reset CONFIRM=destroy-compose-data
```

`compose-reset` is destructive. It removes local PostgreSQL and Redis Compose volumes.

The gateway is a local Nginx entry point for Compose only. It forwards `/` to the web runtime and `/api/*` to Laravel through FastCGI. It is not Traefik, does not implement Kubernetes ingress, and does not route SIP, RTP, PBX, or telephony runtime traffic.

Telephony runtimes are not implemented yet.

## Continuous Integration

Phase F4 adds two GitHub Actions workflows:

- `Repository Hygiene` validates repository documentation, shell and Python syntax, workflow syntax, immutable action pinning, whitespace, and forbidden generated or secret-like files.
- `Quality` runs backend quality, frontend quality, container quality, isolated Compose integration, dependency audits, secret-oriented repository checks, K0 k3d foundation proof, K1 Kubernetes manifest validation, and K1 isolated application runtime proof.

Automatic triggers:

- pull requests
- pushes to `main`

The workflows use least-privilege `contents: read` permissions, do not publish images externally, and do not deploy outside isolated local CI runtimes. Future Kubernetes ingress, Traefik, Helm, Kustomize overlays beyond local, and telephony runtime validation remain deferred until real later-phase resources exist.

Run the local CI-equivalent contract with an isolated Compose project:

```sh
make ci
```

The local CI path uses `utcp-ci` and gateway port `18088`, then removes only the isolated CI project and its volumes. It must not start or depend on a persistent Compose application stack.

## Local k3d Cluster

Phase K0 introduces a deterministic local k3d cluster foundation:

- cluster name: `utcp-local`
- context: `k3d-utcp-local`
- topology: one K3s server and two K3s agents
- Kubernetes API: `127.0.0.1:6550`
- local registry: `utcp-local-registry` on `127.0.0.1:5001`
- repository kubeconfig: `.runtime/kubeconfig/utcp-local.yaml`

Prerequisites:

```sh
docker version
k3d version
kubectl version --client
```

Validate configuration without creating the cluster:

```sh
make k3d-config-check
```

Create or verify the cluster:

```sh
make k3d-create
```

Inspect cluster status:

```sh
make k3d-status
```

Run bounded verification:

```sh
make k3d-verify
```

Use the repository-managed kubeconfig without changing the global Kubernetes context:

```sh
scripts/k3d/kube get nodes
scripts/k3d/kube get namespaces
```

Prove the local registry can push from the host and pull from the cluster:

```sh
make k3d-registry-proof
```

Run the destructive K0 recreate proof against only the UTCP-owned local cluster:

```sh
make k3d-recreate-proof
```

Delete only the UTCP-owned local cluster, registry, and repository kubeconfig:

```sh
make k3d-delete
```

The K0 cluster creates namespace boundaries: `traefik-system`, `utcp-platform`, `utcp-runtime`, `utcp-data`, and `utcp-observability`.

For local CI-style K0 proof, run:

```sh
make ci-k3d
```

`make ci-k3d` deletes and recreates only `utcp-local`. It must not start, stop, or reset optional Compose debug projects.

## Kubernetes Application Base

Phase K1 deploys the already-proven UTCP application base to the existing `utcp-local` cluster through Kustomize. It uses the existing local registry:

- host push endpoint: `127.0.0.1:5001`
- cluster pull endpoint: `utcp-local-registry:5000`
- image tag: `0.1.0-k1-dev`

Build and push K1 images:

```sh
make k8s-image-build
make k8s-image-push
```

Deploy the local Kubernetes application base:

```sh
make k8s-apply
make k8s-status
```

Run the temporary port-forward proof through the internal gateway:

```sh
make k8s-proof
```

The proof uses `127.0.0.1:18089` and verifies:

- `GET /healthz`
- `GET /`
- `GET /api/health/live`
- `GET /api/health/ready`
- `GET /api/version`

Prove local StatefulSet persistence and Deployment restart behavior:

```sh
make k8s-persistence-proof
make k8s-restart-proof
```

Delete only K1 Kubernetes resources while preserving the cluster, registry, namespaces, and PVCs:

```sh
make k8s-delete
```

To delete local K1 PVCs, use the explicit destructive confirmation:

```sh
CONFIRM=delete-k1-pvcs make k8s-delete
```

K1 uses only ClusterIP Services and temporary `kubectl port-forward` proof. It does not create Traefik, Gateway API, Ingress, NodePort, LoadBalancer, SIP, RTP, Asterisk, FreeSWITCH, Kamailio, or rtpengine resources.

## Documentation Authority

Start with these documents:

- [Architecture overview](docs/architecture/overview.md)
- [Authority boundaries](docs/architecture/authority-boundaries.md)
- [Implementation roadmap](docs/roadmap/implementation-roadmap.md)
- [Phase status](docs/roadmap/phase-status.md)
- [Provenance](docs/provenance.md)

## Contribution Model

Work proceeds by bounded phases. Each implementation task must name the phase or subphase, preserve existing valid decisions, avoid speculative future implementation, and report verification honestly.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the required engineering workflow.
