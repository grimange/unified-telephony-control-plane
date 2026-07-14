# Architecture Overview

UTCP is the platform layer beneath telephony applications. Applications such as dialers, contact centers, IVR systems, and voice automation products can build on it without directly owning runtime fleet control, reconciliation, health history, or audit.

## Current Phase

Phase C3 establishes the runtime-neutral command, event, projection, and reconciliation engine on top of the completed K0-K4 infrastructure, C0 application kernel, C1 identity foundation, and C2 runtime registry. The canonical integrated local runtime is the `utcp-local` Kubernetes cluster. Docker Compose remains as a disposable compatibility proof and explicit optional debug mode, not a continuously running parallel runtime. The current platform has a Laravel API process, Vue administration shell, k3d/Kubernetes application base, standard-port local Gateway edge, pinned Pod Security Admission labels, default-deny NetworkPolicies with explicit allow paths, Prometheus metrics, Loki log aggregation, Grafana dashboards, Alertmanager alerts, Alloy Kubernetes API log collection, runtime-neutral kernel primitives for operations, leases, fencing, outbox, inbox, idempotency, audit, event envelopes, and execution context, PostgreSQL-authoritative users, tenants, memberships, built-in roles/capabilities, first-party sessions, active-tenant selection, server-computed capability projection, a tenant-scoped runtime-node registry, and C3 PostgreSQL-backed runtime receipts, observations, projection checkpoints, and reconciliation state. Simulator behavior, tracing, SIP, WSS signaling, media, conferences, live runtime connections, ARI/ESL event listeners, and runtime adapters remain future phases.

## Planned System Shape

The initial application architecture is a modular monolith. Domain boundaries may be expressed inside the application, but they must not become independently deployed services until a future ADR proves that need.

Planned components:

- API and workers in one backend codebase.
- Administrative web application.
- PostgreSQL for canonical business records.
- Redis for queues, locks, caching, and transient projections.
- PostgreSQL-authoritative users, tenants, memberships, built-in roles, capability catalog, account status, and authorization-relevant audit records.
- PostgreSQL-authoritative runtime nodes, endpoints, encrypted write-only runtime credentials, declared runtime capabilities, desired lifecycle state, and registry audit records.
- PostgreSQL-authoritative runtime operations, outbox dispatch state, runtime-event receipts, observations, projection checkpoints, reconciliation state, leases, and fencing.
- First-party server-side session authentication for the same-origin Vue/Laravel application.
- Kubernetes/k3d as the canonical integrated local runtime.
- Docker Compose for disposable compatibility proof and explicit isolated debug sessions.
- Traefik for HTTP, HTTPS, and application WebSocket ingress.
- Traefik as the one-cluster-at-a-time local edge on `127.0.0.1:80` and `127.0.0.1:443`.
- Kubernetes Pod Security and NetworkPolicy boundaries around current application/data/edge namespaces.
- Prometheus, Loki, Grafana, Alertmanager, kube-state-metrics, and Alloy for local Kubernetes observability.
- Control-plane application kernel before runtime registry, simulator, or real telephony integration.
- Runtime registry before command/event/reconciliation; command/event/reconciliation before simulator or real telephony integration.
- Simulator runtime after registry and command/event contracts exist.
- Asterisk, FreeSWITCH, Kamailio, and rtpengine behind explicit adapter/runtime boundaries in later phases.

Planned external HTTPS/WSS edge:

```text
443/TCP
└── Traefik
    ├── app.utcp.local.test
    │   └── HTTPS -> UTCP web/API
    ├── sip.utcp.local.test
    │   └── WSS -> Kamailio WebSocket listener
    └── events.utcp.local.test
        └── WSS -> Reverb/application events
```

Browser SIP registration uses SIP over secure WebSocket. SIP-over-WSS and application event WSS can share TCP `443` with HTTPS because Traefik routes HTTP/WebSocket traffic by hostname and route. Native SIP/TLS for non-browser devices is a separate future concern.

## Non-Goals

This foundation does not implement real SIP signaling, media relay, PSTN access, production telephony behavior, distributed tracing, application OpenTelemetry instrumentation, runtime command execution, health reconciliation, telephony sessions, conference behavior, or other telephony business-domain workflows.

## Current Application Kernel Boundary

C0 keeps the backend as a modular monolith and adds explicit kernel modules under `App\ControlPlane`: `Shared`, `RuntimeOperations`, `Messaging`, `Idempotency`, and `Audit`. Domain services do not depend on HTTP controllers, Kubernetes, ARI, ESL, SIP, or PBX libraries. PostgreSQL is authoritative for runtime-operation lifecycle, worker leases and fencing, outbox, inbox, idempotency, and audit records. Redis remains a queue/cache/transient coordination component and does not determine operation ownership.

The kernel defines neutral process-role direction for later phases without deploying those roles in C0:

```text
api
worker
scheduler
reverb
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
asterisk-ari-events
freeswitch-esl-events
```

Future event listeners ingest runtime-specific streams only. Normalizers emit runtime-neutral observations, reconcilers compare desired and observed state, and command workers execute generic runtime operations through adapters.

## Current Identity Boundary

C1 keeps identity and authorization server-side. PostgreSQL is authoritative for users, tenants, memberships, roles, capabilities, role assignments, account status, membership status, tenant status, and authorization-relevant audit records. Redis may store transient web sessions and login rate-limit state, but Redis is not identity authority.

The Vue application receives only a safe session projection from `/api/v1/auth/session`: user summary, password-change requirement, active tenant, available memberships, and canonical capability keys. Router guards and navigation visibility are client conveniences only; API controllers and identity services enforce platform and tenant capabilities server-side.

Platform authority is separate from tenant membership. A platform administrator is not represented as membership in a fabricated system tenant. Tenant-scoped application services must receive an explicit active tenant context. Suspended users cannot authenticate, suspended tenants cannot be selected, and suspended memberships produce no tenant capabilities.

Bootstrap is limited to first local administrator initialization through `identity:bootstrap-local` and `make identity-bootstrap-local`. Normal users, tenants, memberships, password resets, status changes, and role assignments are managed through the web-admin/API surface and audited through the C0 append-only audit boundary.

## Current Runtime Registry Boundary

C2 adds `RuntimeNode` as the sole canonical registry entity for managed telephony execution providers. Every node belongs to exactly one tenant and is managed through the C1-authenticated web/API authority. Tenant administrators can manage their tenant's nodes when granted `runtime.nodes.manage` and `runtime.credentials.rotate`; tenant members do not receive runtime-node management by default.

Runtime family (`asterisk`, `freeswitch`) identifies the external technology family. Adapter key (`asterisk-ari`, `freeswitch-esl`) records the future binding point. C2 validates and stores both values but does not instantiate adapters, open HTTP or WebSocket connections, run health checks, or execute commands.

Desired lifecycle state is administrator intent (`draft`, `active`, `draining`, `disabled`). C3 owns runtime observation, projection, command execution, and reconciliation. Observed state changes only through projection services that reference runtime-event evidence or stale-observation derivation; there is no administrative mark-ready path.

Endpoints are normalized configuration records for `control`, `events`, and `health` purposes. Credentials are separate encrypted write-only records. API responses expose safe credential metadata and fingerprints only; plaintext and ciphertext are excluded from logs, audit metadata, outbox payloads, status output, and UI detail views.

## Current Runtime Engine Boundary

C3 adds generic process roles from the existing backend image:

```text
control-plane-outbox-dispatcher
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
```

The outbox dispatcher claims C0 outbox rows with PostgreSQL leases and fencing, then uses Redis only for transient queue delivery. The command worker reloads runtime-operation authority from PostgreSQL, resolves operation handlers and adapter contracts, and records success, retry, terminal failure, cancellation, or expiry. The event normalizer claims raw runtime-event receipts, resolves a normalizer by adapter/event type, applies observations and projection checkpoints atomically, and records unsupported events without mutating projections. The reconciler leases one desired/observed target at a time and derives idempotent operations only when a registered reconciler reports actionable drift.

C3 does not add a simulator, null adapter, ARI client, ESL client, Asterisk listener, FreeSWITCH listener, manual reconciliation route, manual projection route, or public runtime-command execution API. Runtime-specific listeners and adapters are future leaf implementations.

## Current Kubernetes Security Boundary

K3 enforces `restricted` Pod Security Admission pinned to Kubernetes `v1.35` for `utcp-platform`, `utcp-data`, `utcp-runtime`, `utcp-observability`, and `traefik-system`. Application workloads keep service-account token automount disabled and run without privileged mode, host networking, hostPath mounts, or broad Linux capabilities.

`utcp-platform`, `utcp-data`, `utcp-runtime`, and `utcp-observability` are default-deny for ingress and egress. `traefik-system` is policy-controlled and permits public ingress only to Traefik HTTP/HTTPS entrypoints plus exact egress required for DNS, Kubernetes Gateway API watches, and the K1 gateway backend.

Allowed current traffic is limited to:

```text
Traefik -> gateway
gateway -> web, api
api/worker/scheduler/migration -> PostgreSQL, Redis
```

`utcp-runtime` remains empty and default-denied. `utcp-observability` runs only the K4 observability stack with explicit policies for Kubernetes discovery, metrics scraping, log ingestion, dashboard access, and local alert delivery. Future signaling, events, and media phases must add their own concrete workloads and policies.

## Current Observability Boundary

K4 observes runtime state without becoming business, reconciliation, telephony, or application authority. Prometheus stores local metrics, Loki stores selected Kubernetes Pod logs, Grafana visualizes provisioned data sources and dashboards, Alertmanager handles local null-routed alerts, and Alloy collects logs through the Kubernetes API.

Observability components remain cluster-internal and are not exposed through Gateway API, NodePort, LoadBalancer, hostPort, or permanent port-forwarding. Prometheus and Loki use short local retention on local-path PVCs. Tracing, production retention, remote-write, external paging, and application-specific metrics contracts are deferred.

## Design Principles

- Keep desired state and observed state distinct.
- Keep vendor-specific behavior behind adapters.
- Keep Kubernetes concepts out of the core telephony domain.
- Keep Redis and WebSockets out of canonical business authority.
- Keep CLI commands limited to bootstrap, diagnostics, recovery, migration, and verification.
