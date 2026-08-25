# Architecture Overview

UTCP is the platform layer beneath telephony applications. Applications such as dialers, contact centers, IVR systems, and voice automation products can build on it without directly owning runtime fleet control, reconciliation, health history, or audit.

Its Kubernetes-first direction is intentional: UTCP is designed to operate
vendor-neutral telephony infrastructure across multiple machines and host
failure domains, with a future-compatible path toward multi-site and
hybrid/cloud execution. Kubernetes supplies infrastructure facts, scheduling,
and workload placement; UTCP supplies the telephony-aware control-plane layer
for RuntimeNode eligibility, lifecycle, placement interpretation, maintenance,
reconciliation, and audit. UTCP is neither the consuming telephony application
nor a replacement Kubernetes control plane.

## Current Phase

T0 is complete locally on top of K0-K4 and C0-C5. T1 Kamailio SIP-over-WSS registration is in progress and `UTCP_PHASE` remains `T0` until the remaining T1 acceptance work passes. The canonical integrated local runtime is the `utcp-local` Kubernetes cluster. Docker Compose remains as a disposable compatibility proof and explicit optional debug mode, not a continuously running parallel runtime. The current platform has a Laravel API process, Vue administration shell, k3d/Kubernetes application base, standard-port local Gateway edge, pinned Pod Security Admission labels, default-deny NetworkPolicies with explicit allow paths, Prometheus metrics, Loki log aggregation, Grafana dashboards, Alertmanager alerts, Alloy Kubernetes API log collection, runtime-neutral kernel primitives for operations, leases, fencing, outbox, inbox, idempotency, audit, event envelopes, and execution context, PostgreSQL-authoritative users, tenants, memberships, built-in roles/capabilities, first-party sessions, active-tenant selection, server-computed capability projection, a tenant-scoped runtime-node registry, backend-driven RuntimeNode management catalogs, scoped runtime evidence and history APIs, C3 PostgreSQL-backed runtime receipts, observations, projection checkpoints, reconciliation state, a C4 deterministic runtime-neutral simulator adapter selected only through the existing authenticated runtime-registry API, C5 PostgreSQL-authoritative telephony sessions, conferences, runtime bindings, conference participants, runtime-neutral conference operations, simulator-backed conference observations, projection, reconciliation, and expiry automation, and a T0 `asterisk-ari` adapter boundary for ARI HTTP inspection and event WebSocket ingestion. The T1 repository implementation now includes TelephonySession-scoped signaling credentials, a pinned Kamailio WSS registrar foundation, fenced registration observer/projection repository logic, registration reconciliation, and a User & Access Management signaling panel under the active TelephonySession. Natural browser acceptance, disposable Kamailio Compose compatibility, full T1 promotion, media, ESL event listeners, and Asterisk conference execution remain future work.

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

### Future Media Processing Boundary (not yet implemented)

UTCP preserves a future stream-first seam for media processing without making
it part of the current application kernel or R0 critical path:

```text
Application / consumer business intent
                |
                v
        UTCP Control Plane
                |
                v
        rtpengine media transport plane
                |
                v
        Future Media Processing Plane
```

UTCP may eventually orchestrate authorized, capability-oriented processor
attachments; rtpengine remains responsible for RTP/SRTP transport, anchoring,
forking, and injection; and replaceable Media Processors may observe, transform,
or participate in media. The future seam is a live media stream correlated to
the canonical Call/CallLeg/participant identity, not a WAV-file API. Observer,
inline-transformer, and interactive-participant failure semantics are distinct
and must be explicit when implemented. Processor language, model, vendor, and
deployment runtime are deliberately not canonical. Media Processors are not
current RuntimeNodes, and no processor schema, API, service, or roadmap phase
is introduced here. See [`ADR-026`](../decisions/ADR-026-future-media-processing-plane-and-ai-integration-boundary.md).

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

## Planned Unified Call Transfer & Handoff

Applications choose the business destination; UTCP owns the provider-neutral
telephony mechanism required to reach it. The planned runtime-handoff model
keeps one logical `Call` with source, consultation, and target `CallLeg` records
where possible, and can span RuntimeNodes and providers without exposing ARI,
ESL, SIP REFER, provider channel IDs, or media-routing details to the consuming
application. It orchestrates existing C6 call-control primitives and reuses
runtime operations, observations, audit, and the current C7A/C7B
`DestinationRef`/route authority. T6 remains the later projection boundary; see
[`ADR-025`](../decisions/ADR-025-unified-call-transfer-and-inter-runtime-handoff.md).

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

C2 adds `RuntimeNode` as the sole canonical registry entity for managed telephony execution providers. UI labels may describe these as PBX nodes, but there is no separate ProviderNode, PBXServer, AsteriskNode, or TelephonyServer authority. Every node belongs to exactly one tenant and is managed through the C1-authenticated web/API authority. Tenant administrators can manage their tenant's nodes when granted `runtime.nodes.manage` and `runtime.credentials.rotate`; tenant members do not receive runtime-node management by default.

Runtime family (`asterisk`, `freeswitch`) identifies the external technology family. Adapter key (`asterisk-ari`, `freeswitch-esl`) records the future binding point. C2 validates and stores both values but does not instantiate adapters, open HTTP or WebSocket connections, run health checks, or execute commands.

Desired lifecycle state is administrator intent (`draft`, `active`, `draining`, `disabled`). Backend catalog authority provides safe runtime families, adapter keys, adapter capability support, endpoint requirements, credential requirements, and adapter-configuration availability to the web UI. C3 owns runtime observation, projection, command execution, and reconciliation. Observed state changes only through projection services that reference runtime-event evidence or stale-observation derivation; there is no administrative mark-ready path.

`RuntimeNode` is a telephony runtime concept, not a Kubernetes machine. Kubernetes remains authoritative for Node existence and conditions, addresses, capacity, labels, taints, cordon state, and Pod placement. A Kubernetes Node may host zero, one, or multiple RuntimeNodes. Future Host views may associate those Kubernetes facts with RuntimeNodes and show telephony impact, while UTCP retains authority over RuntimeNode eligibility, active-call impact, draining readiness, and placement policy.

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

## Current Deterministic Simulator Boundary

C4 adds the first leaf implementation of the C3 adapter contract: `runtime_family: simulator`, `adapter_key: simulator-deterministic`, selected only through the existing authenticated runtime-registry API. It adds one Kubernetes process role, `simulator-event-source`, that publishes scheduled simulator events automatically with no public Service, Gateway route, or external egress beyond DNS, PostgreSQL, and Redis. The simulator's own scenario logic lives entirely in `App\Simulator`; the generic C3 engine (`App\RuntimeEngine`, `App\ControlPlane`) contains no reference to the simulator adapter class or to any scenario key.

C4 does not add telephony sessions, conference behavior, SIP registration, or any live Asterisk, FreeSWITCH, Kamailio, or rtpengine adapter. Those remain C5 and later phases.

## Current Telephony Domain Boundary

C5 introduces PostgreSQL-authoritative telephony sessions, conferences, runtime bindings, and conference participants. A `TelephonySession` is a tenant-scoped authenticated control-plane authorization session only; it is not SIP registration, media connectivity, a call, browser microphone access, or a runtime channel.

Conference desired state and participant desired state are application authority. Conference and participant observed state are projection authority and may change only from C3 normalized observations. Runtime work is expressed through runtime-neutral operations and adapter contracts; controllers and services do not call adapters directly, do not insert runtime operations directly, and do not expose a manual runtime/projection/reconciliation API.

The deterministic simulator can execute C5 operations as a leaf adapter, but C5 does not add SIP credentials, SIP registration, WebRTC/media, Asterisk ARI, FreeSWITCH ESL, Kamailio, rtpengine, dialing, PSTN, recording, or an agent desktop.

## Current Asterisk ARI Boundary

T0 adds Asterisk ARI as a leaf runtime adapter for observation only. `AsteriskAriClient` performs authenticated ARI HTTP inspection and ARI event WebSocket connections using credentials stored by C2 and per-node ARI settings from `asterisk_ari_profiles`. The `asterisk-ari-events` process role discovers active Asterisk RuntimeNodes from PostgreSQL, requires an explicit per-node profile, claims node-scoped listener leases, opens C3 connection epochs, and ingests bounded raw ARI evidence through C3 receipt authority. Adapter-configuration updates go through the generic RuntimeNode adapter-configuration route, dispatch at the registered application boundary, audit the change, advance configuration generation, and wake automatic processing.

Readiness projection remains runtime-neutral and evidence-based. A successful HTTP request alone is not conference or media readiness, and T0 does not implement ConfBridge, C5 conference operations on Asterisk, channel origination, SIP registration, PJSIP, RTP/media, trunks, PSTN, browser WebRTC, or FreeSWITCH ESL.

## Current Kubernetes Security Boundary

K3 enforces `restricted` Pod Security Admission pinned to Kubernetes `v1.35` for `utcp-platform`, `utcp-data`, `utcp-runtime`, `utcp-observability`, and `traefik-system`. Application workloads keep service-account token automount disabled and run without privileged mode, host networking, hostPath mounts, or broad Linux capabilities.

`utcp-platform`, `utcp-data`, `utcp-runtime`, and `utcp-observability` are default-deny for ingress and egress. `traefik-system` is policy-controlled and permits public ingress only to Traefik HTTP/HTTPS entrypoints plus exact egress required for DNS, Kubernetes Gateway API watches, and the K1 gateway backend.

Allowed current traffic is limited to:

```text
Traefik -> gateway
gateway -> web, api
api/worker/scheduler/migration -> PostgreSQL, Redis
```

`utcp-runtime` may host the internal local Asterisk ARI fixture for T0 proof. That fixture exposes only an internal ClusterIP ARI port and no SIP, RTP, Gateway route, NodePort, LoadBalancer, host port, host network, host PID, hostPath, or Kubernetes API token. `utcp-observability` runs only the K4 observability stack with explicit policies for Kubernetes discovery, metrics scraping, log ingestion, dashboard access, and local alert delivery. Future signaling, conference execution, and media phases must add their own concrete workloads and policies.

## Current Observability Boundary

K4 observes runtime state without becoming business, reconciliation, telephony, or application authority. Prometheus stores local metrics, Loki stores selected Kubernetes Pod logs, Grafana visualizes provisioned data sources and dashboards, Alertmanager handles local null-routed alerts, and Alloy collects logs through the Kubernetes API.

Observability components remain cluster-internal and are not exposed through Gateway API, NodePort, LoadBalancer, hostPort, or permanent port-forwarding. Prometheus and Loki use short local retention on local-path PVCs. Tracing, production retention, remote-write, external paging, and application-specific metrics contracts are deferred.

## Design Principles

- Keep desired state and observed state distinct.
- Keep vendor-specific behavior behind adapters.
- Keep Kubernetes concepts out of the core telephony domain.
- Keep Redis and WebSockets out of canonical business authority.
- Keep CLI commands limited to bootstrap, diagnostics, recovery, migration, and verification.

## RT-1 Realtime Control-Plane UI Contract

The Laravel Reverb milestone is a notification/invalidation transport
for the authenticated Admin UI. Reverb is not durable event storage and is not
state authority. PostgreSQL plus the existing UTCP domain/application services,
event/outbox seams, projections, and reconciliation authorities remain the
source of truth.

The required flow is canonical transaction commit, existing domain event or
durable outbox where required, Laravel Reverb, authenticated tenant-scoped
browser notification, and canonical API refetch.

The command boundary is fixed: REST/application API is the command and
canonical-read path; Reverb is asynchronous change notification. Reverb
availability is non-blocking for normal management. Create, drain, retire,
deprovision, and later lifecycle commands, API reads, and canonical backend
processing continue during an outage. Only immediate browser updates may be
delayed. After automatic reconnect, the browser resynchronizes from canonical
APIs because transient notifications may have been missed. Manual
reconciliation or projection is not normal recovery.

Future Host and Runtime infrastructure views follow the same boundary:
canonical Kubernetes/UTCP state changes invalidate through Reverb, and the
frontend refetches canonical API state. Reverb is not infrastructure state
authority.

RT-1 is RuntimeNode-first: lifecycle/state changes, runtime operation progress,
readiness and observed-state projection, drain, retirement, and managed
deprovision progress/completion. It preserves the existing authorities of
RuntimeProvisioningService, RuntimeRegistryService, RNM, the runtime operation
framework, ProjectionService/runtime observation, and the
telephony-infrastructure-worker. Reverb does not replace them or create a
second frontend lifecycle authority.

Channel authorization uses the normal session, tenant membership, and existing
capability model, such as runtime.nodes.view. It introduces no Reverb-only
permission system. Tenant scoping is mandatory: a browser must never receive
another tenant's control-plane events, and the exact channel string remains an
implementation decision.

Broadcasts are minimal change notifications, such as an aggregate identifier
and version, followed by a sanitized API refetch. Credential plaintext,
Kubernetes Secret data, kubeconfig, tokens, lease/fencing secrets, raw stack
traces, and unnecessary Kubernetes resource details are excluded. Reverb stays
internal/ClusterIP behind the established Gateway/Traefik edge; the reserved
events.utcp.local.test hostname does not authorize NodePort, LoadBalancer, or
direct-host exposure. Conference, participant, trunk, and session surfaces may
reuse this transport later, but they are not RT-1A scope and no concrete event
classes or schemas are established here.
