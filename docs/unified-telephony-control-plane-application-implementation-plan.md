# Unified Telephony Control Plane — Application Implementation Plan

**Status:** Proposed application roadmap
**Repository:** `unified-telephony-control-plane`
**Companion document:** `docs/unified-telephony-control-plane-initial-implementation-plan.md`

---

## 1. Purpose

This document defines the application implementation roadmap that follows the initial infrastructure phases of the Unified Telephony Control Plane (UTCP).

The infrastructure plan establishes the execution environment:

- deterministic container images
- Docker Compose development runtime
- local k3d Kubernetes foundation
- Kubernetes application deployment
- Traefik and Gateway API
- namespace and network security
- observability

This application plan defines what should be implemented after that foundation is stable.

The long-term user-facing objective is:

```text
Natural browser login
    ↓
Short-lived telephony session
    ↓
SIP REGISTER over WSS
    ↓
Conference admission
    ↓
Runtime execution
    ↓
Observed conference membership
    ↓
Browser audio
```

That workflow is the north-star acceptance test, but it is not the first application phase. It depends on identity, runtime registry, asynchronous command execution, event normalization, desired and observed state, reconciliation, signaling, media, and runtime-specific adapters.

---

## 2. Strategic Direction

UTCP should remain a modular monolith until there is clear evidence that a component requires independent scaling, deployment, failure isolation, or security boundaries.

The application core must remain:

- vendor-neutral
- runtime-neutral
- desired-state driven
- event-aware
- idempotent
- auditable
- tenant-aware
- deterministic
- observable
- recoverable

Runtime-specific behavior belongs behind adapters.

Recording & Media Archive is a planned UTCP core capability, deferred until
after V1 and K5E. UTCP will own authorized technical recording intent and
lifecycle, Recording Artifact metadata, Media Archive Target lifecycle,
archive credential-reference policy, archive transfer orchestration, and basic
technical retention/deletion. Applications retain the business reason to
record, consent meaning, customer workflow, and disposition; advanced legal
and compliance interpretation remains separately governed. No RMA implementation
is claimed here. See [`ADR-029`](decisions/ADR-029-recording-media-artifact-and-archive-authority.md)
and the executable roadmap.

The application must not contain controller or service branches such as:

```php
if ($runtime === 'asterisk') {
    // Asterisk workflow
}

if ($runtime === 'freeswitch') {
    // FreeSWITCH workflow
}
```

Instead, application services must depend on normalized contracts.

---

## 3. North-Star Vertical Slice

The first complete user-facing telephony workflow is:

> **V0 — Agent Login, SIP Registration, and Conference Admission**

Expected flow:

```text
1. Agent opens https://app.utcp.local.test/.
2. Agent signs in through the normal web login page.
3. UTCP resolves tenant membership and capabilities.
4. UTCP issues a short-lived telephony session.
5. The browser receives SIP-over-WSS bootstrap configuration.
6. The browser connects to wss://sip.utcp.local.test/.
7. The browser sends SIP REGISTER.
8. Kamailio validates the temporary credential.
9. UTCP observes the REGISTERED state.
10. The agent requests conference admission.
11. UTCP records the desired membership intent.
12. A generic telephony command worker invokes the selected runtime adapter.
13. Asterisk performs the conference operation.
14. Kamailio routes signaling.
15. rtpengine anchors WebRTC media.
16. Runtime events update observed conference membership.
17. The UI shows REGISTERED and CONFERENCE_JOINED.
18. Logout or expiry revokes registration and conference membership.
```

V0 must use:

- natural browser login
- no preset sessions
- no injected cookies
- no manual database edits
- no Artisan activation command
- no hidden feature gate
- no runtime-specific frontend workflow

---

## 4. External Edge Direction

The intended public edge remains:

```text
443/TCP
└── Traefik
    ├── app.utcp.local.test
    │   └── HTTPS → UTCP web/API
    │
    ├── sip.utcp.local.test
    │   └── WSS → Kamailio WebSocket listener
    │
    └── events.utcp.local.test
        └── WSS → Reverb/application events
```

Authority boundaries:

- Traefik owns HTTP, HTTPS, and WebSocket edge routing.
- Kamailio owns SIP signaling and live registration location.
- rtpengine owns media relay and WebRTC/RTP adaptation.
- Asterisk and FreeSWITCH execute telephony runtime operations.
- UTCP owns policy, desired state, orchestration, reconciliation, audit, and normalized observed state.
- Reverb transports notifications; it is not canonical state authority.

Native SIP over TLS for non-browser endpoints is a separate future concern and must not be confused with SIP over secure WebSocket.

---

## 5. Core Application Principles

### 5.1 Desired state and observed state

UTCP must keep intended state separate from runtime-observed state.

Example:

```text
Desired:
  Agent should be registered.
  Agent should be a conference participant.

Observed:
  Kamailio currently has an active contact.
  Runtime currently reports the participant as joined.
```

The reconciler compares these states and creates repair operations when required.

### 5.2 Asynchronous runtime execution

Runtime calls should not normally occur directly inside HTTP request handlers.

Preferred flow:

```text
HTTP request
    ↓
validated intent
    ↓
transactional application state
    ↓
runtime operation
    ↓
outbox
    ↓
generic command worker
    ↓
runtime adapter
    ↓
runtime event
    ↓
observed-state projection
```

### 5.3 Runtime-neutral application contracts

The application should depend on contracts such as:

```text
TelephonyRuntimeAdapter
ConferenceRuntimeAdapter
RegistrationObservation
ConferenceMembershipObservation
RuntimeOperationExecutor
RuntimeEventNormalizer
RuntimeHealthInspector
```

Asterisk ARI and FreeSWITCH ESL implement these contracts beneath the application layer.

### 5.4 Web credentials and SIP credentials are separate

The browser login password must never become the SIP password.

Preferred model:

```text
Web authentication
    ↓
UTCP telephony-session issuance
    ↓
short-lived SIP identity and secret
    ↓
SIP REGISTER
```

Telephony credentials must be:

- tenant-scoped
- user-scoped
- agent-scoped
- session-scoped
- short-lived
- revocable
- auditable
- unusable after logout or expiry

---

# 6. Application Phase Roadmap

## C0 — Control-Plane Application Kernel

### Objective

Establish the application rules and infrastructure that every later module uses.

### Implement

- modular-monolith module boundaries
- tenant-aware identifiers
- actor and request context
- canonical result and error contracts
- audit records
- transactional outbox
- idempotent inbox
- operation identifiers
- idempotency keys
- retry and timeout classifications
- cancellation and expiry semantics
- desired-state and observed-state conventions
- normalized domain-event envelope
- projection checkpoints
- durable runtime-operation records

Initial modules may include:

```text
Identity
Tenancy
Access
RuntimeRegistry
RuntimeOperations
TelephonySession
Registration
Conference
Audit
```

### Non-goals

Do not implement:

- ARI
- ESL
- SIP
- Kamailio
- rtpengine
- conference runtime behavior
- browser telephony
- PBX-specific models

### Completion criteria

- Domain modules have explicit boundaries.
- Runtime operations are durable and idempotent.
- Transactional outbox and inbox behavior is proven.
- Desired and observed state conventions are documented.
- No runtime-specific application branching exists.

---

## C1 — Identity, Tenancy, and Authorization

### Objective

Provide the authority required for natural login and tenant-scoped application behavior.

### Implement

- users
- tenants
- tenant memberships
- roles
- capabilities
- web session authentication
- login and logout
- admin bootstrap
- tenant selection
- capability projection
- access-change auditing
- authenticated frontend shell

Initial capability families may include:

```text
runtime.nodes.view
runtime.nodes.manage

agents.sessions.create
agents.sessions.terminate

conferences.view
conferences.join
conferences.manage
```

### Non-goals

Do not issue SIP credentials yet.

Do not implement runtime-node execution or conference control.

### Completion criteria

- Natural browser login works.
- Tenant context is deterministic.
- Capability checks are enforced server-side and reflected in the UI.
- No credential ladder or secondary CLI management surface is introduced.

---

## C2 — Runtime Registry and Runtime-Node Management

### Objective

Create the canonical inventory and lifecycle authority for telephony execution runtimes.

### Canonical model

```text
RuntimeNode
├── identity
├── tenant ownership
├── runtime family
├── endpoints
├── credentials
├── capabilities
├── desired lifecycle state
├── observed state placeholder
├── adapter binding
└── configuration version
```

Example capabilities:

```text
conference.execution
channel.control
event.stream
recording
registration.observation
```

### Implement

- runtime-node definitions
- endpoint management
- credential management and rotation
- capability declarations
- desired lifecycle state
- bounded observed placeholders such as unknown or unobserved
- admin API and web UI
- audit trail

C2 does not implement health probing, runtime eligibility, automatic reconciliation, runtime command execution, event listeners, ARI, ESL, Asterisk, FreeSWITCH, SIP, media, simulator behavior, or conference behavior. C3 owns command, event, projection, observed-state, and reconciliation authority.

### Authority rule

The application should ask:

```text
Which eligible healthy node supports conference execution?
```

It should not ask:

```text
Which Asterisk server should be used?
```

### CLI policy

Artisan commands may support:

- diagnostics
- recovery
- bounded repair

They must not become a second runtime-node management interface.

### Completion criteria

- Runtime nodes are managed through the web/API authority.
- Health and readiness are observed automatically.
- Runtime eligibility is capability-based.
- No vendor-specific application workflow exists.

---

## C3 — Runtime Command, Event, Projection, and Reconciliation Engine

### Objective

Build the runtime-neutral execution engine beneath all telephony workflows.

### Command path

```text
Application service
    ↓
runtime operation
    ↓
transactional outbox
    ↓
telephony command worker
    ↓
selected runtime adapter
    ↓
runtime
```

### Event path

```text
runtime
    ↓
adapter-specific listener
    ↓
raw event inbox
    ↓
event normalizer
    ↓
observed-state projection
    ↓
domain event
    ↓
notification transport
```

### Reconciliation path

```text
desired state
    ↔
observed state
    ↓
reconciler
    ↓
repair operation
```

### Implement

- runtime-operation state machine
- operation leases
- retry policy
- timeout policy
- cancellation
- expiry
- dead-letter classification
- raw runtime-event persistence
- event deduplication
- normalized event contracts
- projection checkpoints
- reconciliation scheduling
- stale-operation detection
- evidence and execution history

Suggested operation lifecycle:

```text
PENDING
CLAIMED
EXECUTING
SUCCEEDED
RETRYABLE_FAILURE
TERMINAL_FAILURE
CANCELLED
EXPIRED
```

### Completion criteria

- Commands execute asynchronously.
- Events are persisted before projection.
- Duplicate events and commands are safe.
- Reconciliation can identify and repair drift.
- Runtime-specific payloads do not leak into application state.

---

## C4 — Deterministic Simulator Adapter

### Objective

Prove the complete control-plane contract before using a live PBX.

The simulator is a real development adapter, not merely a unit-test mock.

### Simulator capabilities

```text
create telephony session
simulate registration
create conference
join participant
remove participant
read conference state
emit normalized events
simulate timeout
simulate runtime disconnection
simulate partial failure
simulate stale observed state
```

### Prove

- command execution
- event normalization
- projection updates
- desired versus observed state
- retry behavior
- timeout behavior
- reconciliation
- frontend-neutral contracts

### Completion criteria

- Application services contain no Asterisk or FreeSWITCH branches.
- Complete session and conference lifecycles run through the simulator.
- Failure and drift scenarios are deterministic and reproducible.

---

## C5 — Telephony Session, Registration, and Conference Domain

### Objective

Implement the application domain required by V0, using the C4 simulator adapter as the first runtime proof beneath the already-established C0-C3 contracts.

## Telephony session

Suggested lifecycle:

```text
REQUESTED
ISSUED
REGISTERING
REGISTERED
EXPIRING
REVOKED
FAILED
```

A telephony session must be:

- tenant-scoped
- user-scoped
- agent-scoped
- short-lived
- revocable
- distinct from the web session
- associated with an eligible runtime
- auditable

## Registration

Maintain separate desired and observed state.

```text
RegistrationIntent
RegistrationCredential
RegistrationObservation
RegistrationExpiry
```

## Conference

Canonical entities may include:

```text
Conference
ConferenceParticipant
ConferenceAdmission
ConferenceMembershipIntent
ConferenceMembershipObservation
```

Suggested membership lifecycle:

```text
REQUESTED
ADMITTED
JOINING
JOINED
LEAVING
LEFT
FAILED
```

### C4 simulator-backed acceptance flow

```text
natural web login
    ↓
telephony session issued
    ↓
simulated registration observed
    ↓
conference admission requested
    ↓
simulated runtime join
    ↓
observed membership projected
    ↓
UI reflects REGISTERED and CONFERENCE_JOINED
```

### Completion criteria

- Domain behavior is runtime-neutral.
- Session expiry and revocation are deterministic.
- Conference admission is policy-controlled.
- Desired and observed membership are separately stored.
- C4 simulator-backed end-to-end lifecycle passes.

---

# 7. Runtime Integration Phases

## T0 — Asterisk ARI Adapter

### Objective

Implement the first live runtime adapter beneath the generic control-plane contracts.

### Components

```text
AsteriskAriClient
AsteriskCommandAdapter
AsteriskEventListener
AsteriskEventNormalizer
AsteriskHealthInspector
```

### Generic worker direction

Prefer a generic process:

```text
telephony-command-worker
```

over a runtime-specific application worker:

```text
ari-worker
```

The generic worker:

1. claims a runtime operation
2. loads the selected runtime node
3. resolves the adapter
4. invokes the adapter
5. records execution evidence
6. classifies success, retry, or terminal failure

### ARI event listener

Use a separate long-running listener:

```text
asterisk-ari-event-listener
```

Responsibilities:

- ARI WebSocket lifecycle
- reconnect
- event receipt
- runtime-node attribution
- raw event persistence
- checkpointing

It must not directly update canonical conference tables or frontend state.

### Event normalization

Translate ARI events such as:

```text
StasisStart
ChannelEnteredBridge
ChannelLeftBridge
ChannelDestroyed
BridgeCreated
```

into normalized events such as:

```text
participant.channel.created
participant.conference.joined
participant.conference.left
participant.disconnected
conference.created
```

### Important controller rule

Avoid an authoritative HTTP `AriController` that directly executes live PBX commands.

Preferred:

```text
HTTP request
    ↓
validated intent
    ↓
runtime operation accepted
    ↓
background execution
    ↓
runtime observation
    ↓
final projected state
```

### Completion criteria

- ARI is isolated behind adapters.
- Runtime events are normalized.
- Runtime commands are idempotent.
- Asterisk health and event-stream state are observable.
- Application behavior remains vendor-neutral.

---

## T1 — Kamailio Signaling and SIP-over-WSS

### Objective

Activate browser SIP registration through the shared public `443/TCP` edge.

### Activate

```text
wss://sip.utcp.local.test/
```

### Implement

- Traefik WSS route
- Kamailio WebSocket listener
- ephemeral SIP credential validation
- SIP REGISTER processing
- contact/location state
- expiry
- deregistration
- registration-event normalization
- registration health
- K3 NetworkPolicies
- K4 metrics and logs

### Authority

- UTCP owns session and credential policy.
- Kamailio owns live registration location and signaling.
- Traefik owns WSS edge routing.

### Completion criteria

- Browser REGISTER succeeds with short-lived credentials.
- Registration state is observed and projected.
- Logout or expiry revokes the registration.
- No web password is reused as a SIP password.

---

## T2 — Asterisk Conference Execution

### Objective

Map normalized conference operations onto Asterisk.

### Implement

- conference creation or resolution
- participant channel creation
- bridge or conference membership
- join and leave operations
- runtime-event normalization
- membership reconciliation
- timeout handling
- partial-failure handling
- stale-channel cleanup

### Completion criteria

- Asterisk conference joins use generic application operations.
- Observed membership is driven by normalized runtime events.
- Retries do not create duplicate participants.
- Conference state can be reconciled after event-stream interruption.

---

## T3 — rtpengine and Browser Media

### Objective

Provide real browser audio through a runtime-neutral media path.

### Implement

- SDP offer and answer mediation
- ICE handling
- DTLS-SRTP
- WebRTC-to-RTP adaptation
- media anchoring
- media-session correlation
- timeout and cleanup
- media health observation
- explicit RTP-related NetworkPolicies
- metrics and logs

### Completion criteria

- Browser audio reaches the selected runtime.
- Media is anchored through rtpengine.
- Signaling and media state can be correlated.
- Failed media sessions are cleaned up deterministically.

---

# 8. V0 — Agent Login, SIP Registration, and Conference Admission

### Objective

Prove the first complete live user-facing telephony workflow.

### Acceptance criteria

1. Natural browser login succeeds.
2. Tenant and capability context is returned.
3. A short-lived telephony session is issued.
4. The browser receives SIP-over-WSS bootstrap configuration.
5. SIP REGISTER succeeds through Traefik and Kamailio.
6. Registration is visible as normalized observed state.
7. The agent requests conference admission through the application.
8. UTCP records desired conference membership.
9. The generic command worker invokes the selected runtime adapter.
10. Asterisk performs the conference operation.
11. rtpengine provides working browser audio.
12. Runtime events project the joined state.
13. The UI shows `REGISTERED`.
14. The UI shows `CONFERENCE_JOINED`.
15. Logout removes or expires the SIP registration.
16. Logout removes conference membership.
17. No manual runtime command is required.
18. No hidden feature gate is required.
19. No preset browser session or injected cookie is used.
20. No runtime-specific frontend branch exists.

---

## T4 — FreeSWITCH ESL Adapter Parity

### Objective

Prove that the same application contract works with FreeSWITCH.

### Components

```text
FreeSwitchEslClient
FreeSwitchCommandAdapter
FreeSwitchEventListener
FreeSwitchEventNormalizer
FreeSwitchHealthInspector
```

### Parity proof

Use:

- the same login page
- the same telephony-session API
- the same SIP registration path
- the same conference-admission API
- the same frontend state machine
- the same normalized domain events

Only adapter selection and runtime execution should differ.

### Completion criteria

- FreeSWITCH implements the same runtime contracts.
- No duplicate FreeSWITCH-specific application workflow exists.
- V0 behavior is reproduced against FreeSWITCH.

---

## T5 — Convergence, Failover, and Recovery

### Objective

Harden runtime behavior after both runtime adapters work.

### Implement

- event-stream reconnection
- event replay
- stale-registration expiry
- orphan-channel cleanup
- conference-membership reconciliation
- runtime-node draining
- runtime-node unavailability handling
- eligible-node reselection
- replay-safe operations
- failed-operation recovery
- cross-runtime recovery where technically supported

### Important limitation

Do not promise seamless active-call migration unless signaling, runtime, and media behavior prove it.

### Completion criteria

- Control-plane state recovers after runtime interruption.
- Duplicate operations remain safe.
- Stale runtime resources are detected and reconciled.
- Runtime-node failure behavior is explicit and observable.

---

# 9. Suggested Process Topology

Keep one application codebase and image with distinct process roles initially.

```text
api
worker
scheduler
reverb

telephony-command-worker
telephony-event-normalizer
telephony-reconciler

asterisk-ari-event-listener
freeswitch-esl-event-listener
```

Potential responsibilities:

| Process | Responsibility |
|---|---|
| `api` | HTTP application requests |
| `worker` | General application jobs |
| `scheduler` | Periodic application scheduling |
| `reverb` | Notification delivery |
| `telephony-command-worker` | Generic runtime-operation execution |
| `telephony-event-normalizer` | Raw runtime-event normalization and projection |
| `telephony-reconciler` | Desired/observed-state reconciliation |
| `asterisk-ari-event-listener` | Asterisk ARI event-stream ingestion |
| `freeswitch-esl-event-listener` | FreeSWITCH ESL event-stream ingestion |

Do not introduce separate microservices merely because processes have different roles.

Split a component only when there is evidence of:

- independent scaling
- independent deployment
- materially different security boundary
- materially different availability requirement
- unacceptable failure coupling

---

# 10. Canonical Authority Matrix

| Concern | Canonical authority |
|---|---|
| Web login | UTCP Identity |
| Tenant membership | UTCP Tenancy |
| Capabilities | UTCP Access |
| Telephony session | UTCP |
| Short-lived SIP credential | UTCP |
| Runtime inventory | UTCP Runtime Registry |
| Runtime eligibility | UTCP |
| Desired registration | UTCP |
| Live SIP contact/location | Kamailio |
| Desired conference membership | UTCP |
| Runtime conference execution | Asterisk or FreeSWITCH adapter |
| Actual conference membership | Runtime observation projected by UTCP |
| Media relay | rtpengine |
| Recording and archive lifecycle | UTCP, planned after V1 and K5E |
| Recording media bytes | Object storage; not PostgreSQL business blobs |
| Edge HTTPS/WSS routing | Traefik |
| UI notifications | Reverb |
| Durable audit | PostgreSQL |
| Queue, cache, locks | Redis |
| Metrics | Prometheus |
| Logs | Loki |
| Dashboards | Grafana |
| Alerts | Alertmanager |

---

# 11. Recommended Phase Order

```text
K4  Observability foundation
 ↓
C0  Control-plane application kernel
 ↓
C1  Identity, tenancy, and authorization
 ↓
C2  Runtime registry and runtime-node management
 ↓
C3  Command, event, projection, and reconciliation engine
 ↓
C4  Deterministic simulator adapter
 ↓
C5  Telephony-session, registration, and conference domain
 ↓
T0  Asterisk ARI adapter
 ↓
T1  Kamailio SIP-over-WSS signaling
 ↓
T2  Asterisk conference execution
 ↓
T3  rtpengine browser media
 ↓
V0  Natural login → SIP REGISTER → conference admission
 ↓
T4  FreeSWITCH ESL adapter parity
 ↓
T5  Convergence, failover, and recovery
 ↓
V1  Bidirectional external routing and control
 ↓
K5A -> K5B -> K5C -> K5D -> K5E
 ↓
RMA Recording & Media Archive
 ↓
A0  Minimal reference consumers (preferred order; no RMA dependency)
```

This is preferred program order, not a false dependency declaration: A0
depends on V1, while RMA depends on the established V1 Call/CallLeg corridor
and K5E, and RMA is not an A0 prerequisite.

---

# 12. Phase Completion Rules

A phase must not be marked complete from static code alone.

Each phase should include, where applicable:

- architecture boundary review
- migrations and schema proof
- unit tests
- feature tests
- API tests
- frontend tests
- asynchronous worker proof
- event normalization proof
- idempotency proof
- retry and timeout proof
- restart proof
- reconciliation proof
- security proof
- observability proof
- cleanup proof
- environment-preservation proof
- evidence document
- roadmap status update

A later phase must not compensate for an unproven earlier authority boundary.

---

# 13. Prohibited Architecture Patterns

Do not introduce:

- direct HTTP-controller-to-PBX command execution
- Asterisk-specific application services
- FreeSWITCH-specific application services
- web passwords reused as SIP passwords
- canonical state stored only in Redis
- runtime events directly mutating unrelated domain tables
- frontend interpretation of raw ARI or ESL events
- manual feature gates for normal operation
- runtime allowlists used as substitutes for modeled eligibility
- CLI management surfaces that compete with the web/API authority
- placeholder telephony workloads
- fake telephony metrics
- microservices without evidence
- event notifications used as canonical state
- automatic APNTalk control from UTCP

---

# 14. Documentation and Evidence

Each phase should add or update:

```text
docs/architecture/
docs/decisions/
docs/runbooks/
docs/evidence/
docs/roadmap/phase-status.md
```

Evidence should record:

- implemented authority
- files changed
- runtime topology
- tests passed
- tests failed
- proof gaps
- deferred work
- operator requirements
- next recommended phase

Do not include:

- private keys
- credentials
- session secrets
- SIP secrets
- complete runtime payloads containing sensitive data
- decoded Kubernetes Secrets

---

# 15. Final Direction

The login-to-conference workflow remains the correct destination, but UTCP must first build the control-plane application that makes the workflow:

- deterministic
- secure
- runtime-neutral
- observable
- recoverable
- testable
- auditable

The most important implementation rule is:

> ARI and ESL workers are infrastructure beneath the runtime-operation model. They are not the application roadmap themselves.

After K4, the next application implementation phase should be:

> **C0 — Control-Plane Application Kernel**

not login, not an ARI controller, and not a direct PBX integration.
