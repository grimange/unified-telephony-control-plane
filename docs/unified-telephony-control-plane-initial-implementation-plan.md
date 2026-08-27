# Unified Telephony Control Plane

## Initial End-to-End Implementation Roadmap

**Repository:** `unified-telephony-control-plane`
**Project abbreviation:** UTCP
**Roadmap status:** Revised initial foundation with explicit call lifecycle, call control, external trunk, route, and caller-identity authority
**Primary objective:** Build a portfolio-quality, vendor-neutral telephony control plane that can support dialers, contact centers, IVR applications, telephony automation, normalized multi-leg call lifecycle and call control, external trunk and routing management, and heterogeneous Asterisk/FreeSWITCH runtime fleets.

---

# 1. Product Boundary

UTCP is the platform beneath telephony applications.

It owns:

* tenant and user authority
* telephony runtime registration
* desired telephony state
* observed runtime state
* health and readiness
* runtime capability discovery
* deterministic reconciliation
* signaling and media resource registration
* canonical telephony identity, session, and registration contracts
* canonical call intent, call lifecycle, call-leg, and call-observation records
* normalized call-control commands and capability negotiation
* authorized technical recording intent and lifecycle
* recording artifact metadata and Call/CallLeg/Conference correlation
* media archive target lifecycle and archive credential-reference policy
* basic technical retention/deletion orchestration and archive evidence
* external trunk definitions, endpoints, credential references, lifecycle, readiness, and runtime projections
* inbound and outbound routing policy
* caller-identity selection policy
* normalized route, runtime, and trunk selection decisions
* operational audit history
* normalized runtime commands and events

Applications built on UTCP may own:

* outbound campaigns
* lead lists
* dialing and pacing strategies
* contact-center queues and workforce workflows
* IVR workflow definitions
* notification jobs
* the business reason a call is requested
* business consent meaning, jurisdictional interpretation, and workflow
* application-specific retry, disposition, and outcome policy
* business reporting
* customer-specific workflows

Applications request telephony outcomes through public UTCP contracts. They must not manage Asterisk, FreeSWITCH, Kamailio, rtpengine, carrier endpoints, trunk credentials, or vendor-specific call-control APIs directly.

UTCP owns the reusable telephony mechanism and infrastructure policy. Applications own the business workflow that decides why, when, and for whom that mechanism is used.

The first implementation must not attempt to become a complete dialer, contact-center suite, carrier billing platform, or customer-specific workflow engine.

---

# 2. Architectural Authority Boundaries

| Concern                                      | Authority                                      |
| -------------------------------------------- | ---------------------------------------------- |
| Business and tenant policy                   | UTCP                                           |
| Desired telephony configuration              | UTCP/PostgreSQL                                |
| External trunk lifecycle and policy          | UTCP/PostgreSQL                                |
| Inbound and outbound route policy            | UTCP/PostgreSQL                                |
| Caller-identity selection policy             | UTCP/PostgreSQL                                |
| Recording intent and lifecycle               | UTCP/PostgreSQL                                |
| Recording artifact and archive metadata      | UTCP/PostgreSQL                                |
| Archive credential references                | UTCP secret-reference boundary                 |
| Canonical call intent and requested control  | UTCP/PostgreSQL                                |
| Instantaneous live call execution            | Asterisk or FreeSWITCH                         |
| Normalized call and call-leg observations    | Runtime observations stored by UTCP            |
| Observed runtime and trunk state             | Runtime observations stored by UTCP            |
| Runtime, route, and trunk selection decisions | UTCP workers                                   |
| Reconciliation decisions                     | UTCP workers                                   |
| HTTP, HTTPS and application WebSockets       | Traefik                                        |
| Browser SIP-over-WSS forwarding              | Traefik to Kamailio                            |
| Live SIP signaling and route execution       | Kamailio and selected execution runtime        |
| RTP, SRTP and media relay                    | rtpengine                                      |
| Instantaneous media capture                  | Asterisk, FreeSWITCH, rtpengine, or future adapter |
| Durable recording media bytes                | Object storage                                 |
| Workload placement                           | Kubernetes                                     |
| Persistent business records                  | PostgreSQL                                     |
| queues, locks and transient projections      | Redis                                          |
| real-time UI notifications                   | Reverb/WebSockets                              |
| audit history                                | PostgreSQL                                     |
| monitoring data                              | Observability stack                            |

WebSocket messages must be notifications, not business authority.

Redis must not become the canonical database.

Kubernetes must not contain telephony business policy.

Kamailio must not become the source of tenant or business configuration.

Asterisk and FreeSWITCH adapters must not leak vendor-specific concepts into the central domain unless represented as optional capabilities.

UTCP call state is a normalized control-plane record, not a claim that PostgreSQL replaces the execution runtime's instantaneous channel state. Runtime events are normalized into canonical call, call-leg, bridge, participant, route, and termination observations.

External trunk credentials must be stored through a secret-reference boundary. Trunk definitions and lifecycle records may be canonical PostgreSQL data, but plaintext carrier secrets must not be exposed through normal APIs, audit records, events, logs, or evidence artifacts.

Kamailio, Asterisk, and FreeSWITCH execute projected signaling and call behavior. They must not become a second management authority for external trunks, routes, caller identities, or tenant policy.

---

# 3. Core Technical Decisions

## 3.1 Application architecture

Start as a **modular monolith**, not microservices.

Use:

* one Laravel API codebase
* one Vue administration application
* Laravel queue workers from the same backend image
* Laravel scheduler from the same backend image
* PostgreSQL
* Redis
* Laravel Reverb when real-time notification becomes necessary

Domain boundaries may be expressed inside Laravel, but they must not initially become independently deployed services.

Initial kernel and platform modules:

```text
ControlPlaneKernel
RuntimeOperations
Messaging
Idempotency
Audit
Shared
Health
```

Later modules:

```text
Identity
Tenancy
Access
RuntimeRegistry
Reconciliation
TelephonyIdentity
TelephonySession
Registration
CallLifecycle
CallControl
ExternalTrunk
InboundRouting
OutboundRouting
CallerIdentity
Conference
Bridge
Participant
Signaling
Media
AgentLifecycle
ReferenceDialer
```

## 3.2 Local execution modes

UTCP must support two local modes.

### Fast application development

```text
Docker Compose
```

Used for:

* application development
* backend tests
* frontend tests
* database work
* queue tests
* simulator integration
* quick demonstrations

### Platform deployment proof

```text
k3d/Kubernetes
```

Used for:

* Kubernetes manifests
* deployment behavior
* Traefik Gateway API
* health probes
* network policies
* rolling updates
* runtime placement
* portfolio demonstrations

Docker Compose must remain functional after Kubernetes support is introduced.

## 3.3 Kubernetes packaging

Use:

* Kustomize for UTCP-owned Kubernetes resources
* Helm for third-party infrastructure such as Traefik
* Gateway API for HTTP routing
* explicit version pinning
* local and CI overlays
* no production-specific cloud assumptions

## 3.4 Telephony deployment model

UTCP must support hybrid runtime locations:

```text
DeploymentTarget
├── Docker
├── Kubernetes
├── External virtual machine
├── Bare-metal server
└── Simulator
```

Do not encode Kubernetes directly into the core runtime-node model.

Use neutral concepts:

```text
RuntimeNode
RuntimePool
SignalingGateway
MediaRelay
DeploymentTarget
DesiredProjection
ObservedSnapshot
ReconciliationRun
```

## 3.5 Call, route, and trunk control-plane model

UTCP must expose runtime-neutral contracts for call execution and external connectivity.

Core neutral concepts include:

```text
TelephonyIdentity
TelephonySession
RegistrationBinding
Call
CallLeg
CallParticipant
CallOperation
CallObservation
Bridge
Conference
ExternalTrunk
TrunkEndpoint
TrunkCredentialReference
TrunkProjection
InboundRoute
OutboundRoute
CallerIdentityPolicy
RouteDecision
TerminationObservation
```

A business application may request an outcome such as `OriginateCall`, `TransferCall`, or `JoinConference`. UTCP validates tenant policy, resolves required capabilities, selects an eligible route, trunk, runtime, and media path, submits a normalized operation, and records the resulting observations.

Controllers and application services must not branch directly on Asterisk channel names, FreeSWITCH UUID semantics, PJSIP endpoint configuration, Sofia gateway syntax, ARI event names, ESL event names, or Kamailio dispatcher internals.

Calls are multi-leg by design. The canonical model must not assume that a business call can be represented by one mutable status field or one runtime channel identifier.

External trunks are lifecycle-managed shared platform resources. Their creation, validation, activation, credential rotation, draining, disabling, projection removal, and retirement must be governed through UTCP rather than application-specific code or manual runtime files.

---

# 4. Proposed Repository Structure

```text
unified-telephony-control-plane/
├── apps/
│   ├── api/                         # Laravel
│   └── web/                         # Vue 3
│
├── infrastructure/
│   ├── docker/
│   │   ├── api/
│   │   ├── web/
│   │   ├── nginx/
│   │   ├── kamailio/
│   │   ├── rtpengine/
│   │   ├── asterisk/
│   │   └── freeswitch/
│   │
│   ├── compose/
│   │   ├── compose.yaml
│   │   ├── compose.local.yaml
│   │   └── env.example
│   │
│   ├── k3d/
│   │   └── cluster.yaml
│   │
│   ├── kubernetes/
│   │   ├── base/
│   │   │   ├── namespaces/
│   │   │   ├── platform/
│   │   │   ├── runtime/
│   │   │   ├── data/
│   │   │   └── networking/
│   │   └── overlays/
│   │       ├── local/
│   │       └── ci/
│   │
│   ├── helm/
│   │   └── traefik/
│   │
│   └── observability/
│
├── telephony/
│   ├── simulator/
│   ├── sipp/
│   ├── kamailio/
│   ├── rtpengine/
│   ├── asterisk/
│   └── freeswitch/
│
├── scripts/
│   ├── doctor
│   ├── compose/
│   ├── k3d/
│   ├── kubernetes/
│   └── verification/
│
├── docs/
│   ├── architecture/
│   ├── decisions/
│   ├── roadmap/
│   ├── runbooks/
│   ├── demonstrations/
│   └── evidence/
│
├── tests/
│   ├── infrastructure/
│   ├── contracts/
│   └── end-to-end/
│
├── .github/
│   ├── workflows/
│   └── pull_request_template.md
│
├── Makefile
├── README.md
├── CONTRIBUTING.md
├── SECURITY.md
├── LICENSE
└── versions.env
```

Do not create empty directories for every future capability unless the corresponding phase introduces a real contract or implementation.

---

# 5. Docker Compose Profiles

Use one Compose project with explicit profiles.

```text
core
├── postgres
├── redis
├── api
├── worker
├── scheduler
├── web
└── application proxy

signaling
└── kamailio

media
└── rtpengine

asterisk
└── asterisk

freeswitch
└── freeswitch

simulator
└── deterministic telephony simulator

telephony-test
└── SIPp synthetic endpoints and trunk peer

observability
├── prometheus
└── grafana
```

Historical Phase F3 commands:

```bash
make compose-debug-up
make compose-down
make compose-logs
make compose-status
```

After the local runtime authority cutoff, the canonical integrated local runtime is the `utcp-local` Kubernetes cluster. Docker Compose remains available for disposable compatibility proof and explicit isolated debug sessions only. Future optional telephony profiles must not be introduced as a normal parallel runtime beside Kubernetes.

---

# 6. Kubernetes Namespaces

Use explicit namespaces from the beginning.

```text
traefik-system
utcp-platform
utcp-runtime
utcp-data
utcp-observability
```

Responsibilities:

* `traefik-system`: Traefik and Gateway resources
* `utcp-platform`: API, web, workers, scheduler
* `utcp-runtime`: simulator, Kamailio, Asterisk and FreeSWITCH
* `utcp-data`: local-only PostgreSQL and Redis
* `utcp-observability`: metrics and dashboards

All scripts must pass both context and namespace explicitly. They must not depend on the current global Kubernetes namespace.

Example:

```bash
kubectl \
  --context k3d-utcp-local \
  --namespace utcp-platform \
  get pods
```

Provide wrapper targets so developers rarely need to type this manually.

```bash
make k8s-status
make k8s-platform-status
make k8s-runtime-status
```

---

# 7. Implementation Phase Map

## Phase F0 — Repository Contract and Governance

### Goal

Establish the repository as an intentionally designed engineering project before introducing application or infrastructure code.

### Deliverables

* project README
* architecture overview
* implementation roadmap
* provenance statement
* contribution guide
* security policy
* initial ADR structure
* version-pinning policy
* Git attributes and editor configuration
* Makefile command convention
* CI workflow skeleton
* pull-request template
* phase-status document

### Required ADRs

```text
ADR-001: Modular monolith as the initial application architecture
ADR-002: Docker Compose and Kubernetes are both supported
ADR-003: Kustomize for UTCP resources and Helm for third-party software
ADR-004: Traefik handles web traffic but not SIP or RTP
ADR-005: Simulator-first runtime integration
ADR-006: Hybrid deployment targets
ADR-007: PostgreSQL is canonical and Redis is transient
ADR-008: Canonical multi-leg call lifecycle and normalized call-control contracts
ADR-009: UTCP owns external trunk, route, and caller-identity authority
ADR-010: Secret references and one-time secret-return boundaries
ADR-011: Applications own business workflows while UTCP owns telephony mechanism and infrastructure policy
```

### Exit criteria

* repository has a coherent documented purpose
* `make help` succeeds
* `make doctor` reports missing and available development tools
* no real credentials are stored
* roadmap phase status is visible
* CI validates basic repository hygiene

---

## Phase F1 — Minimal Application Skeleton

### Goal

Create actual application processes that infrastructure can deploy and verify.

### Backend

Create a minimal Laravel application with:

```text
GET /api/health/live
GET /api/health/ready
GET /api/version
```

Requirements:

* `/live` proves the process is alive
* `/ready` verifies required dependencies
* `/version` returns build metadata
* structured JSON logging
* environment validation
* PostgreSQL connection
* Redis connection
* queue worker configuration
* scheduler configuration

Do not implement telephony business models yet.

### Frontend

Create a minimal Vue application with:

* application shell
* build metadata
* API connectivity indicator
* platform readiness page
* no large component framework unless justified

### Exit criteria

* backend tests pass
* frontend tests pass
* production builds succeed
* API responds outside containers
* no domain functionality has been prematurely introduced

---

## Phase F2 — Container Build Foundation

### Goal

Create deterministic, reusable images for local Compose and Kubernetes.

### Deliverables

* multi-stage backend image
* separate backend runtime targets where useful
* frontend build image
* production frontend serving image
* non-root execution where feasible
* health checks
* `.dockerignore`
* deterministic dependency installation
* image labels containing commit and build version
* no secrets embedded in images

### Required backend process modes

The same backend image should support:

```text
api
queue-worker
scheduler
migration-job
```

Do not maintain four unrelated Dockerfiles unless technically required.

### Exit criteria

* all images build from a clean checkout
* containers run without bind-mounted application source in production mode
* images receive version metadata
* no container depends on interactive initialization
* image scanning finds no committed secret material

---

## Phase F3 — Docker Compose Core Platform

### Goal

Provide a one-command local environment around real application containers.

### Core services

```text
PostgreSQL
Redis
Laravel API
Laravel worker
Laravel scheduler
Vue web
application reverse proxy
```

### Network separation

Use named networks:

```text
edge
platform
data
telephony
```

Expected direction:

* web ingress reaches API/web through `edge`
* API reaches PostgreSQL and Redis through `data`
* workers reach database and Redis
* databases are not attached to `edge`
* telephony components use `telephony`

### Storage

Use named volumes for:

* PostgreSQL
* application storage where required

Redis persistence may be enabled for local resilience but must not imply canonical authority.

### Developer commands

```bash
make local-up
make local-status
make local-proof
make test
make local-down
make compose-proof
```

`local-up`, `local-status`, `local-proof`, and `local-down` operate on the canonical `utcp-local` Kubernetes runtime. `compose-proof` is disposable and must clean up containers, networks, and volumes after success or failure.

### Exit criteria

* a clean checkout starts with documented commands
* database migration completes deterministically
* API, worker and scheduler are all running
* readiness checks pass
* frontend communicates with API
* restarting containers preserves expected local database data
* optional profiles do not affect core startup

---

## Phase F4 — CI Quality Baseline

### Goal

Prevent the AI coder or future contributors from silently breaking foundational contracts.

### CI lanes

```text
Repository hygiene
Backend lint and tests
Frontend lint and tests
Container builds
Compose configuration validation
Kubernetes manifest validation
Secret scanning
Dependency vulnerability reporting
```

### Rules

* pin third-party GitHub Actions
* cache dependencies safely
* do not require real secrets
* do not publish images yet
* fail on invalid Compose or Kubernetes configuration
* keep tests reproducible from a clean checkout

### Exit criteria

All required CI lanes pass on the main branch.

---

## Phase K0 — Local k3d Cluster Foundation

### Goal

Create a deterministic local Kubernetes environment.

### Cluster characteristics

```text
Cluster name: utcp-local
Context: k3d-utcp-local
One server node initially
Two agent nodes initially
Local registry
Explicit host port mappings
Bundled Traefik disabled
```

Suggested exposed web ports:

```text
80
443
```

Reserve future signaling mappings:

```text
5060/UDP
5060/TCP
5061/TCP
```

Do not expose a wide RTP range during this phase.

### Required commands

```bash
make k3d-create
make k3d-status
make k3d-delete
make registry-status
```

`make k3d-create` must be idempotent or safely report that the cluster already exists.

### Exit criteria

* cluster starts from the committed configuration
* local registry is reachable
* all expected nodes become ready
* namespaces are created
* no bundled Traefik installation remains
* deleting and recreating the cluster works

---

## Phase K1 — Kubernetes Application Base

### Goal

Deploy the minimal UTCP application to Kubernetes without ingress.

### Resources

#### Deployments

```text
api
web
worker
scheduler
```

The scheduler may use a continuously running scheduler process or a Kubernetes CronJob strategy, but the decision must be recorded in an ADR.

#### Local-only StatefulSets

```text
postgres
redis
```

Production overlays must not imply that in-cluster PostgreSQL and Redis are the recommended production architecture. Provide connection contracts for external managed services.

### Required Kubernetes configuration

* ConfigMaps
* example Secrets
* Services
* readiness probes
* liveness probes
* startup probes where justified
* resource requests and limits
* PodDisruptionBudgets only when multiple replicas make them meaningful
* namespace-scoped service accounts
* no default-service-account use where avoidable

### Exit criteria

* all application workloads become ready
* migration job completes
* application health endpoints work through port forwarding
* workers can process a test job
* scheduler emits a verified heartbeat
* PostgreSQL and Redis survive pod replacement locally

---

## Phase K2 — Traefik and Gateway API

### Goal

Expose the web platform through a deliberately installed and pinned edge layer.

### Design

Install:

* Gateway API definitions
* Traefik through a pinned Helm configuration
* GatewayClass
* Gateway
* HTTPRoutes

Local hostnames:

```text
app.utcp.local.test
utcp.local.test
```

Use the one-cluster-at-a-time standard local edge:

```text
127.0.0.1:80 -> Traefik port 80
127.0.0.1:443 -> Traefik port 443
```

`sip.utcp.local.test` and `events.utcp.local.test` are reserved but unrouted in K2. Do not create placeholder routes.

Future HTTPS/WSS edge:

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

### Boundary

Traefik handles:

* HTTP
* HTTPS
* frontend routing
* API routing
* application WebSockets
* future browser SIP-over-WSS forwarding by hostname

Traefik does not initially handle:

* native SIP ingress
* RTP media
* rtpengine traffic

Kamailio remains the SIP signaling authority, rtpengine remains the media relay authority, Asterisk and FreeSWITCH remain execution runtimes, UTCP remains desired-state/policy/orchestration/reconciliation authority, and Reverb events remain notification transport.

### Exit criteria

* browser reaches the frontend
* frontend reaches the API
* API paths route without accidental rewriting
* TLS strategy is documented
* route tests run in CI or an ephemeral cluster
* Traefik dashboard is not publicly exposed without protection

---

## Phase K3 — Kubernetes Security Boundaries

### Goal

Make security controls part of the architecture rather than a late retrofit.

### Deliverables

* default-deny NetworkPolicies by namespace
* explicit API-to-data access
* explicit worker-to-data access
* explicit Traefik-to-platform access
* no direct web-to-database path
* Pod Security admission labels
* dropped Linux capabilities
* no privileged application containers
* secret-handling documentation
* RBAC and service-account token boundary validation
* deterministic positive and negative connectivity proof
* documented exception for exact Kubernetes API endpoint egress where the local CNI enforces post-DNAT API traffic

### Exit criteria

* permitted application flows work
* prohibited flows fail in verification tests
* the API cannot reach unrelated runtime workloads without explicit policy
* the frontend cannot reach PostgreSQL or Redis
* security exceptions are documented rather than silently disabled
* no SIP, RTP, WSS signaling, PBX, or media policies are introduced before those phases

---

## Phase K4 — Initial Observability

### Goal

Make runtime state and failures visible before telephony components are added.

### Application requirements

* structured logs to stdout/stderr
* request correlation identifiers
* queue job identifiers
* reconciliation identifiers when introduced
* basic application metrics
* health and readiness metrics
* build/version information

### Platform requirements

Initial local-development observability includes:

```text
Prometheus
Grafana
Alertmanager
kube-state-metrics
Loki
Alloy
```

Prometheus, Alertmanager, Grafana, kube-state-metrics, Loki, and Alloy are deployed in `utcp-observability` through Helm-managed third-party charts and UTCP-owned Kustomize resources. Alloy collects selected Pod logs through the Kubernetes API rather than hostPath mounts. Tracing remains deferred until real C0/C1/runtime operations exist to instrument.

### Initial dashboard

Show:

* API availability
* API latency
* failed requests
* queue depth
* failed jobs
* worker heartbeat
* scheduler heartbeat
* PostgreSQL readiness
* Redis readiness
* pod restarts
* Traefik request metrics
* recent structured application and gateway logs
* local alert state

### Exit criteria

* dashboard works in Docker or Kubernetes
* deliberate API failure appears in metrics
* deliberate worker failure is visible
* runbook explains the first diagnostic steps

---

## Phase C0 — Control-Plane Application Kernel

### Goal

Establish the application-layer kernel that later runtime registry, command/event, simulator, signaling, media, and runtime integrations must use.

### Kernel capabilities

Implement runtime-neutral primitives for:

```text
RuntimeOperation
ExecutionContext
ApplicationEventEnvelope
TransactionalOutbox
DeduplicatingInbox
IdempotencyRecord
AuditRecord
WorkerLease
FencingToken
FailureClassification
ApplicationResult
```

### Boundary rules

The C0 kernel:

* remains inside the Laravel modular monolith
* uses PostgreSQL as canonical storage
* centralizes runtime-operation state transitions
* uses PostgreSQL-backed leases and fencing for worker ownership
* commits operation state and outbox events atomically
* deduplicates inbound normalized messages
* records append-only audit entries
* propagates request, correlation and causation context
* stays runtime-neutral

It must not implement:

```text
RuntimeNode
SimulatorRuntimeAdapter
Authentication
Tenancy administration
SIP
WSS signaling
Kamailio
rtpengine
Asterisk
FreeSWITCH
Conference behavior
Telephony sessions
Call lifecycle
Call control
External trunks
Inbound or outbound route authority
Caller-identity policy
```

### Process-role direction

C0 reserves these later process roles without deploying them:

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

Event listeners ingest runtime-specific streams only. Normalizers emit runtime-neutral observations. Reconcilers compare desired and observed state. Command workers execute generic runtime operations through adapters.

The deterministic simulator belongs to C4, after C2 runtime registry and C3 command/event/reconciliation contracts exist.

### Exit criteria

* modular-monolith kernel boundaries are explicit
* runtime-operation state transitions are centrally enforced
* PostgreSQL atomically claims operations
* lease expiry and fencing are proven
* retryable and terminal failures are distinct
* transactional outbox behavior is proven
* inbox duplicate handling is proven
* idempotency replay and conflict behavior are proven
* audit records are append-only
* event envelopes are versioned and runtime-neutral
* request, correlation and causation context propagates
* no public runtime-specific API exists
* no simulator production behavior exists
* no ARI, ESL, Asterisk or FreeSWITCH production dependency exists
* K1 migration lifecycle applies the C0 schema successfully
* K0-K4 runtime and security proofs remain passing

This is an application-kernel milestone, not the first user-facing telephony milestone.

---

## Phase C1 — Identity, Tenancy, and Authorization

### Goal

Introduce PostgreSQL-authoritative identity, tenant context, and authorization before real telephony infrastructure is manageable.

### Built-in roles

```text
platform-admin
tenant-admin
tenant-member
```

### Initial capabilities

```text
platform.tenants.view
platform.tenants.manage
platform.users.view
platform.users.manage
tenant.memberships.view
tenant.memberships.manage
tenant.roles.view
tenant.roles.assign
```

### Requirements

* PostgreSQL-authoritative users, tenants, memberships, roles, capabilities, role assignments and account status
* first-party server session authentication for the same-origin Vue/Laravel application
* secure, HTTP-only, SameSite session cookie with CSRF protection and login throttling
* active tenant selected by server-validated active membership or explicit platform authority
* server-computed capability projection; frontend route gates are convenience only
* no cross-tenant enumeration or mutation
* explicit platform-level and tenant-level administrative mutation capabilities
* password change, administrator reset, session invalidation and suspension behavior
* audit actor and tenant context recorded through the C0 append-only audit boundary
* bounded first-administrator bootstrap command for local development only
* no production credential, temporary password, session secret or CSRF token printed by status/proof commands
* no public registration, JWT, localStorage auth token, OAuth/OIDC, MFA, telephony credential, SIP registration, runtime node or conference behavior

### Exit criteria

* natural login succeeds through the real web login page
* positive and negative authorization tests pass
* cross-tenant requests return safe responses
* UI capability gates use server-provided capabilities while backend remains final authority
* user, tenant, membership and role-assignment management works through the web-admin surface after bootstrap
* user, tenant and membership suspension behavior is proven
* C1 migrations apply through the K1 migration lifecycle
* C0 audit integration is proven without storing secrets
* backend remains final authority
* no telephony identity, session, call, call-control, trunk, route, credential, conference, worker or runtime integration is introduced

---

## Phase C2 — Runtime Registry and Runtime-Node Management

### Goal

Create the canonical inventory and management authority for heterogeneous execution runtimes, signaling gateways, media relays, pools, and deployment targets.

### Initial scope

Implement runtime-neutral records for:

```text
RuntimeNode
RuntimePool
RuntimePoolMembership
DeploymentTarget
RuntimeCredentialReference
RuntimeEndpoint
RuntimeCapability
RuntimeHealthPolicy
RuntimeObservedSnapshot
RuntimeProjectionTarget
```

### Requirements

* tenant-aware and platform-aware runtime ownership where applicable
* web-admin management as the primary authority
* explicit runtime type and adapter binding without controller-level vendor branching
* endpoint and secret-reference separation
* capability declarations distinguished from observed capabilities
* desired administrative state such as draft, active, draining, disabled and retired
* observed readiness, reachability, adapter connectivity and last-seen timestamps
* runtime-pool membership with deterministic priority and eligibility policy
* soft retirement rather than destructive deletion when historical calls or audit records refer to a runtime
* audit integration through C0
* no direct Asterisk, FreeSWITCH, Kamailio or rtpengine dependency yet

### Exit criteria

* runtime nodes and pools can be created and managed through the web-admin surface
* cross-tenant access is rejected
* secret values are never returned after creation or rotation
* declared and observed capabilities are represented separately
* runtime administrative state changes are audited
* retired nodes cannot receive new desired projections
* no simulator or real runtime adapter is required for completion

---

## Phase C3 — Command, Event, Projection, and Reconciliation Engine

### Goal

Build the reusable control-plane engine that submits normalized runtime operations, ingests observations, projects desired state, detects drift, and converges supported runtime resources.

### Core contracts

```text
RuntimeCommand
RuntimeCommandResult
RuntimeEvent
NormalizedObservation
DesiredProjection
ProjectionRevision
ObservedProjection
DriftRecord
ReconciliationPlan
ReconciliationRun
AdapterExecutionResult
OutboxDispatchClaim
RuntimeEventReceipt
RuntimeProjectionCheckpoint
RuntimeReconciliationState
```

### Requirements

* commands use C0 runtime-operation state transitions and idempotency
* runtime-specific events enter only through adapter or normalizer boundaries
* desired projections are versioned and immutable by revision
* runtime observations include source time, ingestion time, sequence or cursor where available, and freshness classification
* stale observations cannot overwrite newer accepted state
* reconciliation compares desired and observed state without controller-side execution
* retries distinguish transient, terminal, capability, validation and stale-fencing failures
* concurrent reconcilers use leases and fencing
* automatic reconciliation is the default after relevant desired-state changes
* manual reconcile actions are diagnostic or recovery tools, not the primary lifecycle
* every decision records why a target was selected, skipped, rejected or deferred
* Redis queue delivery is transient and PostgreSQL remains authority for operations, outbox, raw receipts, observations, checkpoints, reconciliation state, leases and fencing
* generic process roles run automatically from the backend image:

```text
control-plane-outbox-dispatcher
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
```

* no production simulator, ARI client, ESL client, no-op adapter, Asterisk listener or FreeSWITCH listener exists in C3

### Exit criteria

* normalized commands can be submitted against an adapter interface
* duplicate events and commands are handled deterministically
* desired projection changes enqueue automatic reconciliation
* stale observations and stale workers cannot overwrite current state
* retryable and terminal adapter failures are proven
* reconciliation decisions and drift are inspectable through API and web UI
* no real runtime is required for completion
* no public command-execution, manual projection, manual reconciliation or manual mark-ready route exists

---

## Phase C4 — Deterministic Simulator Adapter

### Goal

Provide a deterministic, runtime-neutral simulator that proves control-plane behavior before real SIP, media, Asterisk, FreeSWITCH, or carrier integration is introduced.

### Initial C4 simulator capabilities

C4 must support scripted behavior for:

```text
runtime health and readiness
capability discovery
desired projection application
observed projection snapshots
configuration drift
command delay
retryable failure
terminal failure
stale observation
duplicate event
runtime replacement
```

### Domain extensions

Later domain phases extend the same simulator adapter with:

```text
C5 telephony registration
C6 call origination and call-leg progression
C6 answer, bridge, hold, resume, transfer and hangup
C7 external trunk readiness, degradation and route-selection eligibility
C8 conference membership and participant control
```

C4 must provide the extension seam, deterministic scenario engine and normalized event path. It must not prematurely invent the C5-C8 domain contracts before those phases define them.

### Requirements

* deterministic clocks or controllable time where tests require it
* stable scenario identifiers and expected event sequences
* no network access required for normal test execution
* no production adapter code path bypassed by simulator mode
* fault injection is explicit, bounded and auditable
* simulator observations use the same normalized envelopes accepted from real adapters

### Exit criteria

* runtime registration and capability discovery work through the adapter contract
* projection and reconciliation success, drift and repair are proven
* duplicate, delayed and stale observations are proven
* retryable and terminal failures are proven
* deterministic scenarios run in backend tests and end-to-end tests
* no Asterisk, FreeSWITCH, Kamailio, rtpengine or carrier dependency exists

---

## Phase C5 — Telephony Identity, Session, and Registration Domain

### Goal

Create runtime-neutral identity, short-lived telephony session, credential, and registration contracts before browser SIP or PBX registration is enabled.

### Core concepts

```text
TelephonyIdentity
TelephonyIdentityAssignment
TelephonySession
RegistrationCredential
RegistrationIntent
RegistrationBinding
RegistrationObservation
RegistrationPolicy
```

### Requirements

* tenant-scoped telephony identities are distinct from application user accounts
* assignments connect authorized users or service actors to telephony identities
* short-lived registration credentials are issued through a bounded secret-return path
* plaintext registration secrets are not stored in audit, event, log or evidence records
* a registration intent identifies the allowed signaling gateway, transport, tenant and expiry
* runtime observations report normalized registration state without exposing vendor contacts or secrets as canonical API fields
* suspension or revocation prevents new credential issuance and invalidates active sessions where supported
* simulator registration scenarios prove requested, issued, registering, registered, expired, revoked and failed states
* no public SIP listener or browser microphone proof is required yet

### Exit criteria

* authorized users can obtain a short-lived telephony session through UTCP
* unauthorized and cross-tenant issuance fails safely
* registration credentials expire and cannot be replayed outside policy
* normalized registration observations update the canonical projection
* revocation and suspension behavior is proven
* simulator-backed UI shows telephony-session and registration state

---

## Phase C6 — Call Lifecycle and Normalized Call-Control Domain

### Goal

Establish the canonical multi-leg call model and capability-aware call-control contract used by dialers, contact centers, IVRs, automation, conferences, and real runtime adapters.

### Core concepts

```text
Call
CallLeg
CallParticipant
CallOperation
CallObservation
CallRouteDecision
CallTermination
CallTimelineEntry
```

### Normalized lifecycle direction

```text
REQUESTED
SELECTING_ROUTE
ORIGINATING
RINGING
EARLY_MEDIA
ANSWERED
BRIDGED
HELD
TRANSFERRING
TERMINATING
COMPLETED
FAILED
CANCELLED
```

The implementation may use more precise internal states, but public states must remain versioned, documented, and runtime-neutral.

### Initial call-control operations

```text
originate
cancelOrigination
answer
hangup
hold
resume
bridge
unbridge
blindTransfer
attendedTransfer
redirect
mute
unmute
sendDtmf
startRecording
stopRecording
```

Not every adapter must support every operation. Unsupported behavior must return an explicit capability result rather than triggering runtime-specific branching in controllers or application services.

### Requirements

* calls are multi-leg and may span more than one runtime operation
* call intent, execution observations and application metadata are stored separately
* application metadata is bounded and cannot redefine control-plane authority
* every operation is idempotent and linked by correlation and causation identifiers
* event ordering tolerates duplicates, delayed events and partial runtime streams
* terminal state is derived through explicit rules rather than last-event-wins mutation
* runtime identifiers remain adapter-owned references, not public business identifiers
* call timelines show requested actions, route decisions, runtime observations and termination reason
* simulator scenarios prove originate, ring, answer, bridge, hold, resume, transfer, hangup, failure and timeout
* recording control, technical artifact metadata, archive-target lifecycle, and basic technical retention/deletion are UTCP core directions; business consent meaning and advanced compliance remain separately governed

### Exit criteria

* a call can be requested through a public UTCP API without naming Asterisk or FreeSWITCH
* normalized call and call-leg state is visible through API and web UI
* supported simulator call-control operations execute through the adapter contract
* unsupported operations fail explicitly and safely
* duplicate, delayed, stale and out-of-order call observations are handled deterministically
* call termination and failure classification are auditable
* no real carrier, PSTN identity or production recording is required

---

## Phase C7 — External Trunk, Route, and Caller-Identity Authority

### Goal

Create the canonical management authority for external SIP connectivity, inbound and outbound routing, caller-identity policy, runtime projection, readiness, draining, credential rotation and retirement.

### Core concepts

```text
ExternalTrunk
TrunkEndpoint
TrunkCredentialReference
TrunkCapability
TrunkHealthPolicy
TrunkProjectionTarget
TrunkDesiredProjection
TrunkObservedSnapshot
InboundRoute
OutboundRoute
RouteConstraint
CallerIdentity
CallerIdentityPolicy
RouteDecision
```

### Administrative lifecycle direction

```text
DRAFT
VALIDATING
ACTIVE
DRAINING
DISABLED
RETIRED
```

### Observed health direction

```text
UNKNOWN
READY
DEGRADED
UNAVAILABLE
```

Administrative lifecycle and observed health are separate. For example, an administratively `ACTIVE` trunk may be observed as `DEGRADED` without silently changing tenant policy.

### Requirements

* web-admin management is the primary authority
* diagnostic commands may inspect or retry but must not become a second management UI
* endpoints, authentication mode, transport, codec constraints and capability declarations are runtime-neutral where possible
* vendor or carrier extensions are isolated behind optional structured capabilities
* secrets are managed as references and are never returned through normal read APIs
* credential rotation supports staged projection and explicit cutover where the target topology permits it
* draining prevents new route selection while allowing existing calls to complete
* disabling removes eligibility and reconciles runtime projections
* retirement preserves historical call, route and audit associations
* inbound routes match normalized destination and source criteria
* outbound routes express priority, constraints, allowed trunks, required capabilities and fallback behavior
* caller-identity policy selects an authorized identity and records why it was selected or rejected
* route decisions select an eligible route, trunk, runtime and media path without exposing vendor configuration to applications
* simulator trunk peers prove registration-based and IP-authenticated readiness models without real carrier credentials
* desired and observed trunk projections reconcile automatically

### Exit criteria

* trunks, routes and caller identities are manageable through the web-admin surface
* cross-tenant visibility and mutation are rejected
* credential creation and rotation do not expose stored secrets
* activation, observed degradation, draining, disabling and retirement are proven
* a simulator-backed outbound route selects an eligible trunk and runtime deterministically
* a simulator-backed inbound route resolves to a normalized application destination
* caller-identity policy records deterministic selection or rejection reasons
* configuration drift is detected and repaired through C3
* no public PSTN or commercial carrier account is required

---

## Phase C8 — Conference, Bridge, and Participant Domain

### Goal

Build conferences and bridges on top of the canonical call, call-leg, participant and call-control contracts rather than as a separate vendor-specific path.

### Core concepts

```text
Bridge
BridgeMembership
Conference
ConferenceParticipant
ConferenceAdmission
ConferenceObservation
ParticipantControlOperation
```

### Requirements

* conference admission is tenant-authorized and capability-aware
* a conference may be executed through an adapter-specific primitive while retaining a normalized domain contract
* bridge and conference membership observations link to canonical calls and call legs
* join, leave, mute, unmute, hold, remove and end operations use C6 operation semantics
* duplicate join and leave observations are idempotent
* stale membership observations cannot resurrect ended participation
* simulator scenarios prove admission, join, membership observation, participant controls, leave and conference termination
* conference application workflows do not branch on Asterisk bridge IDs or FreeSWITCH conference UUIDs

### Exit criteria

* conference admission works through a public UTCP contract
* simulator-backed participants join and leave through normalized operations
* participant controls are capability-aware and audited
* call, leg, bridge and conference timelines remain correlated
* stale and duplicate membership events are handled deterministically
* no real browser SIP, media or PBX conference dependency is required

---

## Phase T0 — Asterisk ARI Adapter

### Goal

Add the first real runtime adapter transport by proving authenticated Asterisk ARI HTTP inspection, ARI WebSocket event ingestion, listener leasing, connection epochs, runtime-neutral readiness observations, and reconnect behavior without making Asterisk concepts part of the central domain.

### Initial scope

* internal local Asterisk ARI fixture for Kubernetes and CI proof
* ARI HTTP authentication and runtime inspection
* ARI WebSocket connection for runtime-level event evidence
* dynamic `asterisk-ari-events` listener discovery from C2 RuntimeNodes
* PostgreSQL listener leases and fencing
* C3 connection epochs and raw event receipts
* runtime-neutral readiness and connection observations
* reconnect after listener or Asterisk restart
* bounded metrics, logs, alerts and NetworkPolicies
* unsupported C5 conference operations on Asterisk until T2
* no SIP, PJSIP endpoint, RTP, media, ConfBridge, channel control, trunks, PSTN or browser calling

### Adapter contract

The Asterisk adapter supports only generic runtime observation during T0:

```text
runtime.node.inspect
```

Unsupported operations, including C5 conference operations, must fail observably as unsupported capability or unsupported operation. They must not fall back to the simulator or report fake success.

Vendor-specific details such as ARI connection state, HTTP status, WebSocket handshake behavior and native event names remain inside the adapter and normalizer boundary. ConfBridge, channel identifiers, PJSIP endpoint sections and dialplan contexts are not T0 authority.

### Exit criteria

* Asterisk ARI fixture is internal only and exposes no SIP or media endpoint
* authenticated ARI HTTP inspection succeeds through `AsteriskAriClient`
* `asterisk-ari-events` discovers active Asterisk nodes automatically
* one listener lease and C3 connection epoch are created per active connection
* raw connection evidence enters C3 receipts and normalizes to runtime observations
* RuntimeNode readiness is projected only from documented ARI evidence
* listener restart and Asterisk restart recover without manual processing
* authentication failure is normalized and sanitized
* unsupported conference operation proof fails safely
* controllers and application workflows contain no Asterisk-specific branching
* no real carrier identity, SIP endpoint, media path, or browser call is required

---

## Phase T1 — Kamailio Signaling Edge and SIP-over-WSS

### Goal

Introduce a dedicated SIP signaling layer between browser clients, synthetic SIP endpoints, external trunk peers and execution runtimes.

Kamailio's dispatcher module may execute projected runtime selection, while its rtpengine integration may control media relay selection. UTCP remains the policy, desired-state, route-decision and reconciliation authority.

### Initial scope

* Kamailio container
* SIP UDP and TCP
* SIP-over-WebSocket and SIP-over-WSS
* health endpoint or command probe
* dispatcher configuration
* one Asterisk destination
* SIP OPTIONS readiness
* synthetic SIPp test traffic
* browser-compatible registration flow
* configuration generated or projected by UTCP
* deterministic reload
* normalized registration and signaling observations where available

### UTCP ownership

UTCP stores:

* desired signaling-gateway configuration
* runtime-pool membership
* destination priorities and eligibility
* external trunk projection intent
* health-policy configuration
* route-decision inputs and outcomes

Kamailio executes live SIP routing and forwards traffic to the selected runtime or peer. Kamailio configuration files and dispatcher tables must not become the canonical tenant management surface.

### Exit criteria

* synthetic SIP traffic enters through Kamailio
* traffic reaches Asterisk through a projected destination
* browser SIP registration can traverse WSS through Traefik to Kamailio
* unavailable destinations are excluded
* dispatcher and signaling changes reconcile from UTCP
* reload does not require manual file editing
* observed Kamailio state is recorded
* no carrier credentials are required

---

## Phase T2 — rtpengine Media Plane

### Goal

Introduce media relay without making it a mandatory dependency of every development workflow.

### Initial mode

Implement rtpengine first as an optional Docker Compose media profile.

Use:

* narrow local UDP media range
* explicit advertised address
* Kamailio integration
* health/readiness probe
* synthetic media verification
* media statistics collection
* correlation to canonical calls and call legs

### Kubernetes status

Treat Kubernetes rtpengine deployment as experimental until validated.

A future Kubernetes overlay may require:

* dedicated nodes
* node affinity
* host networking
* controlled privileges
* explicit media port exposure
* documented kernel-module decisions

Do not weaken cluster-wide security merely to make an initial rtpengine pod start.

### Exit criteria

* SDP is rewritten through Kamailio and rtpengine
* RTP passes through the relay
* media observations correlate to canonical call and call-leg identifiers
* failure of the relay is visible
* calls are not silently accepted without expected media
* UTCP records media-node health
* media configuration is not hard-coded in application code

---

## Phase V0 — Natural Login, SIP Registration, and Conference Admission Vertical Slice

### Goal

Prove the first live browser-to-runtime workflow through the same identity, session, registration, call, conference, signaling and media contracts used by later applications.

### Vertical slice

```text
Natural browser login
 ↓
Authenticated tenant and capability context
 ↓
Short-lived telephony session
 ↓
SIP REGISTER over WSS through Traefik and Kamailio
 ↓
Normalized registration observation
 ↓
Conference admission request through UTCP
 ↓
Normalized runtime adapter operation
 ↓
Asterisk conference and bridge execution
 ↓
Media through rtpengine
 ↓
Observed call legs and conference membership
 ↓
UI shows REGISTERED and CONFERENCE_JOINED
```

### Exit criteria

* proof uses the real web login page and first-party session authentication
* no preset browser session, manually injected cookie or Redis mutation is used
* microphone permission is requested through the natural browser path
* short-lived registration credentials are issued by UTCP
* SIP registration is observed through normalized contracts
* conference admission is authorized by the backend
* call-leg and membership observations appear in the UI
* browser, signaling, runtime and media failures are distinguishable
* no public PSTN or external carrier is required

---

## Phase T3 — External Trunk Integration and Live Route Projection

### Goal

Connect the C7 trunk, route and caller-identity authority to real signaling and execution projections while keeping commercial carrier access optional.

### Initial proof topology

Use a deterministic synthetic external SIP peer, such as SIPp or a second isolated SIP runtime, to act as a trunk provider.

The phase must not require:

* a paid carrier account
* public PSTN access
* real customer caller identities
* production credentials

A later optional demonstration may use a real carrier only through local, uncommitted secret references and a documented safety boundary.

### Initial scope

* registration-based trunk projection
* IP-authenticated trunk projection
* inbound route projection
* outbound route projection
* caller-identity projection where supported
* trunk OPTIONS or adapter-specific readiness
* normalized trunk registration and health observations
* route and trunk eligibility updates
* credential-reference rotation workflow
* draining and disabling behavior
* removal of retired runtime projections
* one synthetic inbound call
* one synthetic outbound call

### Projection direction

```text
UTCP trunk, route and caller-identity authority
 ↓
Versioned desired projection
 ↓
Kamailio and/or selected runtime adapter
 ↓
Synthetic external SIP peer
 ↓
Normalized trunk, route, call and call-leg observations
```

The topology may project portions of a trunk to Kamailio, Asterisk or FreeSWITCH depending on responsibility. That placement is an adapter decision and must not alter the canonical C7 API.

### Exit criteria

* one registration-based synthetic trunk becomes ready
* one IP-authenticated synthetic trunk becomes ready
* inbound and outbound route decisions are deterministic and auditable
* a synthetic outbound call uses the selected route, trunk and Asterisk runtime
* a synthetic inbound call resolves to a normalized UTCP destination
* trunk degradation removes it from new-call eligibility according to policy
* draining blocks new calls while existing calls may complete
* credential rotation reconciles without exposing secrets
* disabling and retirement remove active projections
* no manual runtime-file edit is required for normal lifecycle operations

---

## Phase V1 — Call Lifecycle, Call Control, and External Trunk Vertical Slice

### Goal

Prove that an application can request and control a call through UTCP without knowing the selected route, trunk, signaling gateway or execution runtime.

### Vertical slice

```text
Authenticated application request
 ↓
Canonical OriginateCall operation
 ↓
Outbound route evaluation
 ↓
Caller-identity policy evaluation
 ↓
Eligible external trunk selection
 ↓
Eligible runtime selection
 ↓
Kamailio signaling execution
 ↓
Asterisk call execution
 ↓
rtpengine media relay where required
 ↓
Normalized call and call-leg observations
 ↓
Capability-aware hold, resume, transfer or hangup
 ↓
Canonical termination and audit timeline
```

### Exit criteria

* the request does not name Asterisk, PJSIP, ARI, Kamailio dispatcher IDs or trunk configuration sections
* selected route, caller identity, trunk and runtime are inspectable with decision reasons
* ringing, answer, bridge and termination states are proven
* at least one supported mid-call control is proven
* one unsupported control returns an explicit capability result
* duplicate and delayed runtime observations do not corrupt terminal state
* a degraded or draining trunk changes new-call selection deterministically
* the complete timeline is visible through API and web UI

---

## Phase T4 — FreeSWITCH Runtime Adapter Parity

### Goal

Prove that UTCP is genuinely vendor-neutral by adding a second execution runtime that implements the same registration, call, call-control, bridge, conference, trunk-projection and observation contracts.

### Initial scope

* containerized FreeSWITCH
* Sofia SIP profile
* ESL connectivity
* health and readiness
* synthetic endpoint
* synthetic internal call
* normalized runtime capabilities
* registration in UTCP
* Kamailio dispatcher membership
* normalized call and call-leg observations
* supported call-control operations
* conference execution parity for the V0 contract
* external trunk projection parity for the V1 contract where supported

### Critical proof

The same UTCP API and application workflow must operate against Asterisk and FreeSWITCH without adding runtime-specific branching to controllers, route services, trunk services, call services, conference services or frontend code.

Capability differences are permitted. Hidden semantic differences are not.

### Exit criteria

* both nodes register independently
* shared operations use the same adapter contracts
* unsupported capabilities are reported explicitly
* Kamailio can route to either runtime
* the V0 workflow can execute on FreeSWITCH where declared capabilities permit it
* the V1 originate and lifecycle workflow can execute on FreeSWITCH where declared capabilities permit it
* one runtime can fail without destroying control-plane availability
* no FreeSWITCH-specific branch is introduced into application-facing services

---

## Phase T5 — Multi-Runtime Convergence, Failover, and Recovery

### Goal

Demonstrate the architectural value of UTCP across signaling, media, external trunks, calls, conferences and heterogeneous execution runtimes.

### Proof scenarios

* Asterisk primary, FreeSWITCH secondary
* FreeSWITCH primary, Asterisk secondary
* one runtime unhealthy
* one runtime draining
* one runtime lacks a requested capability
* one external trunk degraded
* one external trunk draining
* route fallback to another eligible trunk
* configuration drift on one runtime or signaling gateway
* concurrent reconciliation requests
* delayed runtime response
* stale observation
* event-stream interruption and recovery
* runtime replacement
* in-progress call remains associated with its execution runtime while new calls fail over
* conference admission selects only a runtime with required capabilities

### Exit criteria

* policy selects an eligible route, trunk and runtime
* failover is deterministic
* existing calls are not silently migrated between runtimes
* new-call selection excludes unhealthy or draining resources according to policy
* stale state cannot overwrite newer state
* reconciliation is idempotent
* operator can inspect why a route, trunk or node was selected or rejected
* recovery after adapter or event-stream interruption is proven
* all decisions are auditable

This is the second major portfolio milestone.

---

## Phase A0 — Reference Application Contract

### Goal

Prove that applications can build on UTCP call, route, trunk, registration and conference contracts without owning infrastructure or vendor integrations.

Implement a small reference dialer, not a production predictive dialer.

### Scope

* synthetic contacts
* one campaign
* manual or simple progressive initiation
* agent availability
* public UTCP originate and call-control APIs
* normalized call and call-leg states
* route and caller-identity policy references
* call result returned to the application boundary
* simulator mode
* optional Asterisk or FreeSWITCH mode

### Application responsibility

The reference dialer owns:

* campaign membership
* contact selection
* when to request a call
* simple application retry policy
* application disposition

UTCP owns:

* authorized caller identity
* route, trunk and runtime selection
* telephony operation execution
* normalized call lifecycle
* call-control capability handling
* infrastructure observations and audit history

### Explicitly out of scope

* predictive pacing
* compliance automation
* real PSTN requirement
* production lead management
* billing
* full reporting
* answering-machine detection
* large-scale campaign scheduling

### Exit criteria

* dialer uses public UTCP contracts
* dialer does not directly call ARI, AMI, ESL, Kamailio management interfaces or runtime configuration files
* dialer does not store or manage trunk credentials
* switching runtimes requires no dialer code changes
* changing eligible trunks or route priority requires no dialer code changes
* simulator demonstration works without telephony hardware
* optional live demonstration works through V1 contracts

---

## Phase RMA — Recording & Media Archive

### Goal

Define and later prove the reusable technical recording and media-archive
lifecycle used by multiple telephony applications. RMA is planned, UTCP core,
and R0-critical; implementation begins only after the V1 Call/CallLeg corridor
is established and K5E is complete.

### Planned slices

RMA-A Recording Authority and Lifecycle; RMA-B Runtime-Neutral Capture Contract;
RMA-C Recording Artifact Authority; RMA-D Archive Target and Secret-Reference
Authority; RMA-E S3-Compatible Archive Adapter and Deterministic MinIO Proof;
RMA-F BYO Storage Credentials and Rotation; RMA-G Retention and Deletion
Lifecycle; RMA-H Distributed Recording and Archive Natural Live Proof.

These are architectural plans, not implementation claims. `RecordingSession` is
separate from `RecordingArtifact`; artifact metadata is separate from media
bytes; capture lifecycle is separate from archive-transfer lifecycle. PostgreSQL
owns metadata, not large recording binaries, and UTCP orchestrates the archive
path without becoming the canonical media data plane. See ADR-029.

### Boundary

Applications own business reason, consent meaning, customer workflow, and
application disposition. UTCP owns authorized technical intent, tenant policy,
correlation, observations, artifact metadata, archive targets, credential
references, transfer lifecycle, technical retention/deletion, authorization,
and audit. Telephony/media executors capture media; object storage owns durable
bytes. Legal holds, e-discovery, PCI/HIPAA workflows, and jurisdiction-specific
automation remain separate future domains.

---

## Phase R0 — Portfolio Release

### Goal

Make the project understandable and reproducible by another engineer or prospective client.

R0 converges the V1/A0 consumer work with K5E and RMA. The technical
dependencies are V1 Call/CallLeg corridor and K5E -> RMA; A0 does not
technically depend on RMA. K5 is planned, parallel, and R0-critical: it does
not serially gate C7A, but its bounded multi-host/failure-domain proof must be
complete before R0 closes. Kubernetes remains authoritative for
infrastructure facts, scheduling, and workload placement; UTCP owns the
telephony-aware RuntimeNode interpretation, eligibility, lifecycle,
maintenance coordination, reconciliation, and audit. Full multi-cluster
federation remains a future-compatible direction, not an R0 requirement.

### Required documentation

* architecture overview
* authority map
* local Compose guide
* k3d guide
* runtime-adapter guide
* call-lifecycle and call-control contract guide
* external-trunk, route and caller-identity lifecycle guide
* security model
* observability guide
* failure-injection guide
* demonstration guide
* known limitations
* production-readiness disclaimer
* roadmap

### Demonstration flow

```text
1. Start UTCP.
2. Log in through the natural web login page.
3. Register a simulator runtime.
4. Submit desired configuration.
5. Watch reconciliation complete.
6. Introduce drift.
7. Observe automatic repair.
8. Create a synthetic external trunk, outbound route and caller-identity policy.
9. Activate the trunk and observe readiness.
10. Place a simulator-backed call and inspect call-leg progression.
11. Execute a supported call-control operation.
12. Register Asterisk and FreeSWITCH.
13. Route synthetic SIP traffic through Kamailio.
14. Observe rtpengine media relay.
15. Complete the V0 browser registration and conference flow.
16. Complete the V1 external-trunk and call-control flow.
17. Drain or degrade one trunk and observe deterministic route fallback.
18. Fail one runtime and observe deterministic new-call failover.
19. Place a synthetic reference-dialer call without runtime-specific application code.
20. Inspect route decisions, call timeline, reconciliation history and audit records.
```

### Exit criteria

* clean-clone setup is verified
* demo contains only synthetic data
* CI is green
* no secrets are committed
* screenshots and diagrams are current
* project limitations are honest
* versioned release is created
* bounded RMA implementation and distributed archive evidence are complete

---

# 8. Dependency Order

The AI coder must follow this sequence:

```text
F0 Repository contract
 ↓
F1 Minimal application skeleton
 ↓
F2 Container images
 ↓
F3 Docker Compose
 ↓
F4 CI baseline
 ↓
K0 k3d cluster
 ↓
K1 Kubernetes application base
 ↓
K2 Traefik/Gateway API
 ↓
K3 Security boundaries
 ↓
K4 Observability
 ↓
C0 Control-plane application kernel
 ↓
C1 Identity, tenancy, and authorization
 ↓
C2 Runtime registry and runtime-node management
 ↓
C3 Command, event, projection, and reconciliation engine
 ↓
C4 Deterministic simulator adapter
 ↓
C5 Telephony identity, session, and registration domain
 ↓
C6 Call lifecycle and normalized call-control domain
 ↓
C7 External trunk, route, and caller-identity authority
 ↓
C8 Conference, bridge, and participant domain
 ↓
T0 Asterisk runtime adapter and internal call execution
 ↓
T1 Kamailio signaling edge and SIP-over-WSS
 ↓
T2 rtpengine media plane
 ↓
V0 Natural login, SIP registration, and conference admission
 ↓
T3 External trunk integration and live route projection
 ↓
V1 Call lifecycle, call control, and external trunk proof
 ↓
T4 FreeSWITCH runtime adapter parity
 ↓
T5 Multi-runtime convergence, failover, and recovery
 ↓
V1 closure
 ↓
K5A -> K5B -> K5C -> K5D -> K5E
 ↓
RMA Recording & Media Archive
 ↓
A0 Reference application contract (preferred order; no RMA dependency)
 ↓
R0 Portfolio release
```

The technical dependency graph is separate from this preferred execution
order: A0 depends on V1, RMA depends on the established V1 Call/CallLeg
corridor and K5E, and A0 does not technically depend on RMA. The preferred
program order is V1 closure, K5A-K5E, RMA, remaining A0 closure, then R0.

Phases may be split into smaller implementation corridors, but must not be reordered without an ADR explaining why.

C6 and C7 must exist before an application or real adapter is allowed to invent its own call, route, trunk or caller-identity model. The simulator proves these contracts first. T0, T3 and T4 implement them against real signaling and execution components.

The V0 vertical slice proves the browser registration, conference and media path without requiring an external carrier:

```text
Natural browser login
 ↓
Authenticated tenant and permission context
 ↓
Short-lived telephony session
 ↓
SIP REGISTER over WSS through Traefik and Kamailio
 ↓
Normalized registration observation
 ↓
Conference admission request through UTCP
 ↓
Normalized runtime adapter
 ↓
Asterisk conference execution
 ↓
Media through rtpengine
 ↓
Observed call legs and conference membership
 ↓
UI shows REGISTERED and CONFERENCE_JOINED
```

The V1 vertical slice proves application-neutral call execution through external trunk authority:

```text
Authenticated originate request
 ↓
Canonical call and call-operation records
 ↓
Outbound route and caller-identity evaluation
 ↓
Eligible external trunk and runtime selection
 ↓
Kamailio signaling execution
 ↓
Asterisk call execution
 ↓
Normalized call and call-leg observations
 ↓
Capability-aware call control
 ↓
Canonical termination and audit timeline
```

Application services must operate on normalized contracts such as `TelephonyRuntimeAdapter`, `RegistrationObservation`, `CallOperation`, `CallObservation`, `CallLegObservation`, `ExternalTrunkProjection`, `RouteDecision`, `ConferenceRuntimeAdapter`, and `ConferenceMembershipObservation`.

Controllers, application workflows and frontend code must not branch directly on `asterisk`, `freeswitch`, ARI, AMI, ESL, PJSIP, Sofia, Kamailio dispatcher internals, or runtime configuration-section names. The first live vertical slices may use Asterisk, but the application API and frontend behavior remain runtime-neutral. FreeSWITCH later implements the same contracts and declares capability differences explicitly.

---

# 9. AI Coder Execution Contract

Every AI coder prompt must identify exactly one phase or bounded subphase.

The AI coder must:

1. Inspect the current repository before modifying it.
2. Read the roadmap and applicable ADRs.
3. Report the current phase state.
4. Identify conflicts between the roadmap and existing implementation.
5. Implement only the requested bounded scope.
6. Avoid speculative future abstractions.
7. Add or update tests.
8. Run relevant verification.
9. Update documentation when contracts change.
10. Update phase status only when exit criteria are proven.
11. Report files changed.
12. Report commands run and their results.
13. Report unresolved proof gaps honestly.
14. Avoid claiming completion when verification was skipped.

The AI coder must not:

* implement multiple future phases in one prompt
* add hidden environment gates
* add manual allowlists without a demonstrated need
* create a second management authority through CLI commands
* allow a reference application to manage trunks, routes, caller identities or runtime configuration directly
* bypass canonical call and call-leg records with adapter-specific application state
* copy APNTalk implementation
* introduce real credentials or customer data
* use `latest` container tags
* expose PostgreSQL or Redis through public ingress
* route SIP or RTP through Traefik merely because TCP/UDP routing is available
* make Redis the business authority
* introduce microservices prematurely
* use broad privileged containers to bypass infrastructure problems
* mark experimental telephony Kubernetes deployment as production-ready

---

# 10. Phase Completion Report Format

Each implementation run must end with:

```text
## Verdict

PHASE_<ID>_COMPLETE
or
PHASE_<ID>_INCOMPLETE

## Implemented

## Files Changed

## Verification Performed

## Tests Passed

## Tests Failed

## Unresolved Proof Gaps

## Deferred Work

## Operator Required Before Next Prompt
```

`Operator Required Before Next Prompt` must contain only real manual actions needed before continuing.

When no action is needed:

```text
None.
```

Do not fill this section with generic cautions or actions unrelated to the next phase.

---

# 11. Evidence Requirements

Store concise, sanitized evidence under:

```text
docs/evidence/<phase-id>/
```

Evidence may include:

* command summaries
* test summaries
* architecture screenshots
* Kubernetes resource snapshots
* sanitized call-flow output
* sanitized call and call-leg timelines
* route and trunk selection decision summaries
* trunk lifecycle and reconciliation proof
* failure-injection results

Do not commit:

* complete noisy logs
* credentials
* private hostnames
* local absolute paths
* machine-specific tokens
* unredacted SIP identities
* generated build artifacts

---

# 12. Initial Non-Goals

The initial roadmap does not promise:

* production multi-region Kubernetes
* carrier-grade availability
* public PSTN operation as a required proof
* commercial carrier onboarding as a required proof
* carrier billing, settlement or least-cost-routing marketplace behavior
* predictive dialing
* telecom billing
* customer-specific caller-ID compliance automation
* lawful-intercept capabilities
* full SBC functionality
* automatic PBX horizontal scaling
* production rtpengine autoscaling
* production certificate automation
* production secrets management
* customer-specific compliance workflows

These may be considered after the reference platform proves its foundational contracts.

---

# 13. First Major Success Definition

UTCP has a valid foundation when:

* Compose and Kubernetes deploy the same application
* Traefik exposes the web control plane
* PostgreSQL and Redis have clear authority boundaries
* workers and scheduler operate reliably
* observability detects failures
* NetworkPolicies enforce expected communication
* a simulator runtime can converge desired and observed state
* normalized telephony sessions and registrations are proven through the simulator
* canonical multi-leg call lifecycle and call-control operations are proven through the simulator
* external trunk lifecycle, route selection and caller-identity policy are proven through the simulator
* conference and bridge membership are built on the same call and operation contracts
* failure and drift are visible and recoverable
* the same workflow is proven through API, UI and automated tests

Real telephony integration should begin only after this milestone. Asterisk, Kamailio, rtpengine, synthetic external trunk peers and FreeSWITCH then implement the already-proven control-plane contracts rather than defining them.
