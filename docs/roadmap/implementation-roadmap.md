# Implementation Roadmap

This is the executable repository roadmap: actual phase ordering, phase objectives and boundaries, current completion state, the next actionable phase, and links to evidence and ADRs. It is synchronized against the two upstream planning documents and against repository-proven state (ADRs, evidence, runbooks, tests, live proof).

**C6 natural-live-proof readiness corrections (2026-08-21): IMPLEMENTED / TESTED; C6E natural proof pending.** The forward identity migration synchronizes current configured capabilities into already-migrated databases; the normal managed Asterisk reconciliation path converges system-owned capabilities idempotently; managed Asterisk projects the proof-only `c6-generic-proof` Stasis destination without changing conference or T3 Echo routing; and the canonical local mutable-image apply workflow automatically rolls affected deployments. No frontend, call schema, runtime authority, T4, C7, or live-call proof changes are included. Evidence: [`docs/evidence/c6/c6-natural-live-proof-readiness-corrections.md`](../evidence/c6/c6-natural-live-proof-readiness-corrections.md).

**Multi-machine infrastructure direction (2026-08-22): ROADMAP-DEFINED / PLANNED.** ADR-024 establishes Kubernetes Host/Node awareness as a separate K-series track. The first future slice, K5 Host Visibility, is read-only and covers Node discovery, Host inventory, basic readiness/capacity, workload placement, RuntimeNode association, and an operator-oriented Admin UI surface. It does not change the primary `RT-1 -> C6 -> T4 -> C7` sequence or make Host visibility a C6 or T4 prerequisite.

## Document Hierarchy

Five documents share roadmap responsibility. Each has one job; none should duplicate another's.

| Document | Purpose | Authority |
| --- | --- | --- |
| [`docs/unified-telephony-control-plane-initial-implementation-plan.md`](../unified-telephony-control-plane-initial-implementation-plan.md) | Product scope, long-term authority boundaries, the complete end-to-end capability map (call lifecycle, call control, external trunks, routes, caller identity, conferences, signaling, media, runtime neutrality, Asterisk/FreeSWITCH parity). | Product boundary authority. Defines what UTCP must eventually be able to do. |
| [`docs/unified-telephony-control-plane-application-implementation-plan.md`](../unified-telephony-control-plane-application-implementation-plan.md) | Application and runtime-integration sequencing; C-phase and T-phase implementation detail; the north-star V0 vertical slice; application-process responsibilities. | Sequencing authority for how the application layer is built, phase by phase. |
| `docs/roadmap/implementation-roadmap.md` (this document) | The executable repository roadmap: actual phase ordering, objectives, boundaries, current completion state, next actionable phase, evidence/ADR links, future phases retained in full. | Executable roadmap authority. Reconciles the two plans above against what the repository has actually proven. |
| [`docs/roadmap/ui-foundations.md`](ui-foundations.md) | UI-A through UI-E foundation scope, non-goals, dependencies, current evidence, remaining implementation, test contracts, browser-proof contracts, and completion criteria. | Authoritative UI Foundation roadmap. Defines reusable frontend foundations without replacing C/T/V domain phase authority. |
| [`docs/roadmap/phase-status.md`](phase-status.md) | Concise factual current-state ledger: completed, active, blocked, deferred phases. No duplicate architecture specification. | Status-ledger authority. Always the shortest path to "what phase are we in." |

`CLAUDE.md` (repository root) states the binding phase-dependency order for AI-coder task scoping and must not be treated as an independently competing roadmap; this document elaborates the same order with full objective/boundary/evidence detail and extends it, after the last phase named in `CLAUDE.md`'s current sequence, with the initial plan's full product scope (call lifecycle, external trunk, routing, caller-identity, and reference-application phases) so that scope is not lost. Extending the sequence here does not reorder any phase `CLAUDE.md` already orders (F0 through T5, V0, R0); it only adds phases that `CLAUDE.md`'s current text does not yet mention, pending a future ADR/CLAUDE.md update if and when work on them begins.

This implementation roadmap must not become an independent competing product plan. When it disagrees with the initial plan on product scope, the initial plan wins and this document is corrected. When it disagrees with the application plan or with `CLAUDE.md` on phase sequencing, repository-proven precedent (ADRs, evidence, phase-status, `CLAUDE.md`) wins for phases already started or complete, and the application plan's sequencing wins for phases not yet started.

## Phase Order

```text
F0  Repository contract and governance
F1  Minimal application skeleton
F2  Container build foundation
F3  Docker Compose core platform
F4  CI quality baseline
K0  Local k3d cluster foundation
K1  Kubernetes application base
K2  Traefik and Gateway API
K3  Kubernetes security boundaries
K4  Initial observability
K5  Host visibility and telephony placement awareness (planned side track)
C0  Control-plane application kernel
C1  Identity, tenancy, and authorization
C2  Runtime registry and runtime-node management
C3  Command, event, projection, and reconciliation engine
C4  Deterministic simulator adapter
C5  Telephony-session and conference domain
T0  Asterisk ARI adapter
T1  Kamailio SIP-over-WSS signaling
T2  Asterisk conference execution
T3  rtpengine browser media
V0  Natural login, SIP registration, and conference admission        [complete]
RT-1 Horizontal runtime-resource expansion                             [RT-1A complete]
C6  Canonical call lifecycle and normalized call control          [next]
T4  FreeSWITCH call-control adapter parity                         [after C6]
C7A External connectivity, telephony addressing, and caller identity [planned]
C7B Inbound/outbound route and destination authority                  [planned]
T6  Live external connectivity and route projection                  [planned]
V1  Bidirectional external call routing and control                   [planned]
A0  Reference consumers                                                [planned]
R0  Portfolio release
```

F0 through T1 are complete exactly as `CLAUDE.md` orders them. T2, T3, V0, and
the prior T4/T5 records remain preserved as historical/current status records;
T5 is complete and is not restored as pending work. The
active post-RH-3 sequence is the horizontal RT-1 track, then C6, T4, C7A, C7B,
T6, V1, A0, and R0. C7 remains one top-level roadmap identity and is executed
through the two bounded C7A and C7B corridors below. This revision does not
renumber completed phases or erase the historical reconciliation that explains
the earlier placement.

## Post-RH-3 Foundation Revision — Current Executable Direction

This section is the current executable direction after RH-3. It is a roadmap
contract, not an implementation claim. RH-3 is **COMPLETE / LIVE PROVEN /
SIMPLIFICATION COMPLETE / FROZEN**. No application, schema, API, runtime, or
infrastructure work is part of this documentation revision.

### Product boundary

UTCP is a vendor-neutral telephony control plane. It owns foundational,
reusable telephony authority, normalized runtime operations, lifecycle
coordination, routing decisions, and auditability. It is not a campaign dialer,
CRM, contact-center application, predictive dialing engine, full IVR authoring
product, visual workflow editor, billing platform, or carrier settlement
system. Applications own campaign membership, lead and CRM decisions, pacing,
workflow meaning, disposition, and reporting.

### C6 — Canonical Call Lifecycle & Normalized Call Control

C6 defines the canonical `Call`, `CallLeg`, `CallParticipant`, `CallOperation`,
`CallObservation`, `CallRouteDecision`, `CallTermination`, and
`CallTimelineEntry` model and capability-aware operations. The lifecycle must
support both calls originated by an application and calls first observed or
adopted from an external/runtime source. An inbound call therefore enters
through a normalized inbound entry concept/state (for example, `OFFERED`),
rather than being forced through the outbound-only
`REQUESTED -> SELECTING_ROUTE -> ORIGINATING` sequence. The final state name is
chosen by the C6 contract and is not prescribed by Asterisk or FreeSWITCH.

In addition to existing control operations, C6 adds:

- a normalized DTMF-received observation on a `CallLeg`, representing an
  observed digit/event without assigning business meaning; and
- low-level `playMedia` and `stopMedia` operations for media playback on a
  leg.

An application may interpret digit `1` as Sales; UTCP only reports the
observation. Recording control remains separate from recording storage,
retention, and compliance workflow. The simulator remains required for C6
contract tests.

### T4 — FreeSWITCH Call-Control Adapter Parity

T4 implements and proves the normalized C6 adapter contract through FreeSWITCH
ESL. It consumes C6 and does not invent alternate FreeSWITCH call semantics.
The proof is that the same normalized C6 operation contract can execute through
the Asterisk and FreeSWITCH adapters, with adapter-specific behavior hidden
behind the normalized contract. Existing FreeSWITCH SIP/media parity remains
historical evidence; no ESL call-control implementation is claimed here.

T4 follows C6 because normalized call-control parity cannot be proven before
C6 defines the normalized contract. T4 should precede C7 because validating
that contract against a second real runtime prevents later routing and address
domains from building on accidentally Asterisk-shaped semantics. T4 is
deferred from its previous numeric position, not removed.

### C7 — External authority, split into two bounded corridors

#### C7A — External Connectivity, Telephony Addressing & Caller Identity

C7A defines tenant-owned, lifecycle-aware and readiness-aware:
`ExternalTrunk`, `TrunkEndpoint`, `TrunkCredentialReference` (or the existing
equivalent), `TelephonyAddress`, `CallerIdentity`, and
`CallerIdentityPolicy`. It answers who owns an address, which tenant and
external trunk/provider relationship it belongs to, whether it is active or
disabled, whether it is eligible for inbound routing or caller identity, what
route/destination assignment applies, and what audit history exists.

`TelephonyAddress` is the runtime-neutral canonical concept, not an abstraction
named simply `DID`. It may represent E.164/DID addresses, SIP URIs, and logical
extensions where supported. C7A does not own number purchasing, number
porting, carrier commercial inventory, billing, or settlement; those remain
provider/commercial extensions.

#### C7B — Inbound/Outbound Route & Destination Authority

C7B defines `InboundRoute`, `OutboundRoute`, `RouteConstraint`, `RouteDecision`,
and the normalized `DestinationRef`. A `DestinationRef` contains no runtime
fields such as an Asterisk dialplan context or PJSIP endpoint, a FreeSWITCH
Sofia profile or XML dialplan destination, or a Kamailio dispatcher ID. Its
extensible classes may include a telephony identity, conference, application
endpoint, external destination, and a reserved future queue destination.

Canonical inbound resolution is:

```text
External SIP peer
  -> ExternalTrunk
  -> called TelephonyAddress
  -> InboundRoute
  -> RouteDecision
  -> DestinationRef
  -> canonical Call / CallLeg
  -> runtime or application execution
```

The canonical API/domain state owns the routing authority; runtime adapters
and projectors execute the selected result. Canonical outbound resolution is:

```text
application requests OriginateCall
  -> outbound route selection
  -> caller identity policy
  -> external trunk selection
  -> runtime selection
  -> Call / CallLeg execution
  -> observations, termination, and audit
```

This is reusable telephony control-plane behavior, not campaign or dialer
behavior. No campaign, lead list, pacing, predictive dialing, agent
assignment, disposition, or campaign retry policy belongs in C7.

### T6 — Live External Connectivity / Route Projection

T6 proves projection and application of canonical C7 resources: `ExternalTrunk`,
`TelephonyAddress` where runtime/network projection is required,
`InboundRoute`, `OutboundRoute`, `CallerIdentity`, and `DestinationRef`
resolution. Projection runs through Kamailio and the selected runtime adapter
where required; canonical C7 APIs do not change based on runtime choice.
Baseline proof uses synthetic external SIP peers. No commercial carrier is
required. Kamailio executes projected signaling but never owns tenant,
trunk, address, caller-identity, route, or runtime-eligibility authority.

### V1 — Bidirectional External Call Routing & Control

V1 proves two provider- and runtime-neutral vertical corridors at the canonical
API level:

- **Outbound:** authenticated application -> `OriginateCall` -> outbound route
  -> caller identity -> external trunk -> runtime -> synthetic external peer
  -> normalized observations and control.
- **Inbound:** synthetic external peer -> external trunk -> called
  `TelephonyAddress` -> inbound route -> `DestinationRef` -> canonical
  `Call` / `CallLeg` -> selected application/runtime destination -> normalized
  observations and control.

### A0 — Reference Consumers

A0 proves the application boundary through three intentionally small consumers:

- an outbound consumer that originates and controls an external call through
  UTCP, and is not a campaign dialer;
- an inbound consumer that receives and owns a routed inbound call through
  UTCP, and is not a PBX UI; and
- a minimal IVR consumer that exercises inbound `TelephonyAddress` ->
  `DestinationRef(application)` -> application acceptance/control ->
  `playMedia` -> DTMF-received observation -> application-selected
  `DestinationRef` -> UTCP transfer/redirect -> continued normalized lifecycle.

The minimal IVR consumer must not grow into a full IVR editor, menu
administration product, or contact-center workflow system.

### Explicit product-boundary deferrals

Queue/ACD is not required for the foundational UTCP release. It may be a
future application/domain extension; C7B may reserve a future queue
`DestinationRef` class, but there is no Queue/ACD phase and no skills, agent
distribution, queue strategy, wrap-up, or workforce logic in this roadmap.

UTCP provides the reusable IVR primitives `answer`, `hangup`, transfer or
redirect, `bridge`, DTMF-received observation, media playback, media stop, call
timeline/observations, and `DestinationRef` application handoff. IVR
applications own menu trees, the meaning of a digit, business hours, CRM
decisions, prompt sequencing, and visual workflow design.

### Capability catalog

| Capability | Boundary |
| --- | --- |
| Call lifecycle; call control; ExternalTrunk; TelephonyAddress / DID authority; inbound routing; outbound routing; CallerIdentity; DestinationRef | CORE |
| DTMF observation/control; media playback; recording control | CORE primitive |
| Recording storage; IVR workflow | Application concern |
| Queue/ACD | FUTURE EXTENSION / DEFERRED |
| Campaigns; lead management; predictive dialing; CRM; carrier billing/settlement | OUT OF CORE |
| DID purchasing/porting | PROVIDER EXTENSION |
| SMS/MMS | SEPARATE DOMAIN / OUT OF CURRENT ROADMAP |

## UI Foundation Track

The horizontal UI Foundation track is defined in [`docs/roadmap/ui-foundations.md`](ui-foundations.md). It does not renumber, replace, or advance the F/K/C/T/V/R phase sequence, and it does not change `UTCP_PHASE`.

```text
UI-A  Application Shell, Routing, and Navigation
  -> UI-B  Design System and Reusable Component Library
      -> UI-C  Data Interaction and Management Workflows
          -> UI-D  Real-Time Telephony Operational Experience

UI-E  Accessibility, Testing, Responsiveness, and Portfolio Quality
  -> continuous and cross-cutting
```

UI-A through UI-E own reusable frontend architecture and interaction foundations. C/T/V phases own domain behavior, lifecycle, APIs, authorization, and runtime execution. Completing a UI foundation does not complete a domain phase; completing one domain screen does not complete a UI foundation. Backend authorization remains authoritative, the frontend consumes server-provided capabilities and catalogs, and notifications are not canonical state.

UI-A is the first bounded implementation: adopt Vue Router and decompose the monolithic `App.vue` application shell into route-level views behind a shared `AppShell`. Initial UI-B token work can begin alongside UI-A, but UI-A gates maintainable UI-C and UI-D expansion.

The UI track interleaves with the existing roadmap as follows:

- C1, C2, C5, and T1 already provide current login, tenant, capability, runtime-node, user, session, and signaling UI evidence that UI-A through UI-C must preserve.
- T2 provides conference-execution domain behavior that later UI-D operational views may display, without making UI-D the domain authority.
- T3 and V0 provide browser media and end-to-end admission behavior that domain-specific UI-D completion will depend on.
- T4 must reuse normalized UI behavior rather than create a FreeSWITCH-specific frontend.
- C6, C7, T6, and V1 call, trunk, route, and call-control screens should build on UI-A through UI-C, with real-time operational presentation flowing through UI-D where appropriate.
- R0 portfolio release depends on UI-E quality progress, but runtime correctness work is not blocked by incomplete visual polish.

Detailed UI Foundation criteria live only in `docs/roadmap/ui-foundations.md`.

## First User-Facing Vertical Slice

V0 proves:

```text
Natural browser login
  -> authenticated tenant and permission context
  -> short-lived telephony session
  -> SIP REGISTER over WSS through Traefik and Kamailio
  -> conference admission request through UTCP
  -> normalized runtime adapter
  -> Asterisk conference execution
  -> media through rtpengine
  -> observed conference membership
  -> UI shows REGISTERED and CONFERENCE_JOINED
```

The first live slice may use Asterisk, but application API and frontend behavior remain runtime-neutral. FreeSWITCH later implements the same normalized contracts (T4).

## Second Vertical Slice (Extended Scope)

V1 is the bidirectional, application-neutral call-control and
external-connectivity slice once C6, C7A, C7B, and T6 exist:

```text
OUTBOUND: authenticated application
  -> OriginateCall -> outbound route -> caller identity -> external trunk
  -> runtime -> synthetic external peer -> normalized observations/control

INBOUND: synthetic external peer
  -> external trunk -> called TelephonyAddress -> inbound route
  -> DestinationRef -> canonical Call/CallLeg
  -> selected application/runtime destination -> normalized observations/control
```

V1 is retained here so the roadmap does not silently drop the initial plan's
call-lifecycle/external-trunk product scope merely because the current
conference-first sequence reaches its own vertical slice first.

## Phase-Identifier Reconciliation

The initial plan and the application plan diverge on C- and T-phase numbering for capabilities beyond registration and conference. The repository's actually-built sequence (ADRs, evidence, `CLAUDE.md`, `phase-status.md`) is the tiebreaker for anything already started; the application plan's numbering is the tiebreaker for anything not yet started, because it is the sequencing-authority document.

| Conflict | Initial plan | Application plan | Repository-proven state | Resolution |
| --- | --- | --- | --- | --- |
| Registration + conference domain phase number | C5 = registration only; conference is a separate later `C8` | C5 mentioned as registration; conference later folded via `C8` reference in passing | `C5` is complete and implements **both** telephony sessions/registration-authorization *and* conferences/participants/runtime bindings in one phase (ADR-017). | Keep repository `C5` as-is (registration authorization + conference domain combined). `C8 — Conference, Bridge, and Participant Domain` from the initial plan is **superseded/merged into C5** and is not reintroduced as a separate future phase. Conference *execution* against a real runtime remains T2, unaffected. |
| Kamailio signaling phase scope and number | `T1 — Kamailio Signaling Edge and SIP-over-WSS`: broad scope including SIP UDP/TCP, dispatcher-routed destinations, one Asterisk destination, SIPp synthetic traffic | `T1 — Kamailio Signaling and SIP-over-WSS`: narrow scope, browser SIP-over-WSS registration only | Repository `T1` (ADR text in `docs/architecture/authority-boundaries.md`) implements exactly the narrow scope: `TelephonySession`-scoped credentials, Kamailio as sole registrar, WSS-only, one active Contact per signaling identity. No SIP UDP/TCP, no dispatcher, no second runtime destination, no INVITE relay, no application-dialog Record-Route path, and no SIP route to Asterisk or another RuntimeNode. | Keep repository `T1` as the narrow WSS-registration scope (already implemented and evidenced). The initial plan's broader SIP application-dialog routing concepts are **not dropped**. Internal browser/conference application-dialog routing belongs to `T3`/`V0` when the browser media path is introduced; external-trunk and general-call dispatcher/projection scope belongs to `C6`/`C7`/`T6`/`V1` once canonical calls, routes, trunks, and route decisions exist. |
| rtpengine media-plane phase number | `T2 — rtpengine Media Plane` | `T3 — rtpengine and Browser Media` | Repository `T3` is rtpengine browser media (not yet started; next after T2). | Keep repository/application-plan numbering: rtpengine is `T3`. The initial plan's `T2` label for the same capability is superseded — renumbered to `T3` with no scope change. |
| External trunk live-integration phase number | `T3 — External Trunk Integration and Live Route Projection` | Not explicitly numbered (folded into "later T-phases") | No repository phase currently claims a `T3` identifier for external trunks — repository `T3` is already claimed by rtpengine (see row above). | Cannot reuse `T3` (conflicts with rtpengine). Assigned the next unclaimed sequential T-number: **`T6`**, continuing the existing T0-T5 convention rather than inventing a new naming scheme. Scope (registration-based and IP-authenticated synthetic trunk projection, inbound/outbound route projection, caller-identity projection, credential-reference rotation, draining/disabling/retirement) is preserved verbatim from the initial plan, plus the Kamailio-dispatcher scope carried forward from the T1 reconciliation row above. |
| Call-lifecycle/call-control domain phase number | `C6 — Call Lifecycle and Normalized Call-Control Domain` | `C6 — Call Lifecycle and Normalized Call-Control Domain` (same number) | Not started; no repository conflict. | No conflict. Kept as `C6`. |
| External trunk/route/caller-identity authority phase number | `C7 — External Trunk, Route, and Caller-Identity Authority` | `C7` (same number, named in passing) | Not started; no repository conflict. | Keep top-level `C7`, executed as **C7A — External Connectivity, Telephony Addressing & Caller Identity** and **C7B — Inbound/Outbound Route & Destination Authority**. |
| Call-lifecycle/external-trunk vertical slice | `V1 — Call Lifecycle, Call Control, and External Trunk Vertical Slice` | Not present as a named phase | Not started; no repository conflict (no phase currently uses `V1`). | No conflict. Kept as `V1`, resequenced after `T6` (see Phase Order) since it depends on `C6`, `C7`, and `T6` all existing first. |
| Reference application phase | `A0 — Reference Application Contract` | Not present | Not started; no repository conflict. | No conflict. Kept as `A0`, immediately before `R0`. |

No operator decision was required to resolve any of the above; all conflicts were resolved from existing ADRs, evidence, `phase-status.md`, and `CLAUDE.md` precedent.

## Completed-Phase Reconciliation (F0-T0; T1 detailed separately below)

F0-F4, K0-K4, and C0-C5 are reconciled as **Complete**, matching `docs/roadmap/phase-status.md` and their respective evidence documents (`docs/evidence/f0/` through `docs/evidence/c5/`) and ADRs (ADR-001 through ADR-017). No wording below claims completion beyond what those evidence documents already prove; this section only restates each phase's objective/authority/boundary in the fuller narrative form the initial and application plans use, so this roadmap does not collapse into a bare status table.

### F0 - Repository Contract and Governance — Complete

Established the repository as an intentionally designed engineering project: README, architecture overview, roadmap, provenance statement, contribution guide, security policy, ADR-001 through ADR-011 (initial batch), version-pinning policy, Makefile command convention, CI workflow skeleton, PR template, phase-status document. Evidence: `docs/evidence/f0/repository-hygiene.md`.

### F1 - Minimal Application Skeleton — Complete

Minimal Laravel API (`/api/health/live`, `/api/health/ready`, `/api/version`) and minimal Vue 3 administration shell with build metadata and an API connectivity indicator. No domain models were introduced. Evidence: `docs/evidence/f1/application-skeleton.md`.

### F2 - Container Build Foundation — Complete

Deterministic multi-stage backend and frontend images, non-root execution, health checks, `.dockerignore`, commit/build version labels, no embedded secrets, shared backend image supporting `api`/`queue-worker`/`scheduler`/`migration-job` process modes. Evidence: `docs/evidence/f2/container-build-foundation.md`.

### F3 - Docker Compose Core Platform — Complete

One-command Compose environment (PostgreSQL, Redis, API, worker, scheduler, web, application proxy) with named-network separation (`edge`/`platform`/`data`/`telephony`). After the local runtime authority cutoff, Compose is retained only for disposable compatibility proof and explicit debug mode; `utcp-local` Kubernetes is the canonical integrated local runtime. Evidence: `docs/evidence/f3/docker-compose-core-platform.md`.

### F4 - CI Quality Baseline — Complete

GitHub Actions lanes for repository hygiene, backend/frontend lint and tests, container builds, Compose/Kubernetes config validation, secret scanning, and dependency audit reporting; local CI-equivalent checks and isolated Compose proof. Hosted GitHub Actions execution has not been observed for the current uncommitted working tree (see Hosted CI Proof in the latest T1-G evidence). Evidence: `docs/evidence/f4/ci-quality-baseline.md`.

### K0 - Local k3d Cluster Foundation — Complete

Repository-managed `utcp-local` k3d/K3s cluster (context `k3d-utcp-local`), local registry, kubeconfig isolation (`.runtime/kubeconfig/utcp-local.yaml`), namespaces, registry-pull proof, recreate proof, CI wiring. Evidence: `docs/evidence/k0/local-k3d-cluster-foundation.md`.

### K1 - Kubernetes Application Base — Complete

Kustomize-managed manifests deploy PostgreSQL, Redis, migration Job, API, worker, scheduler, web, and internal gateway; port-forward, persistence, and restart proofs passed. Evidence: `docs/evidence/k1/kubernetes-application-base.md`.

### K2 - Traefik and Gateway API — Complete

One-cluster-at-a-time standard local edge on `127.0.0.1:80/443`; Gateway API, Traefik, TLS, route status, reserved-host rejection (`sip.utcp.local.test`/`events.utcp.local.test` reserved but unrouted until their phases), and external runtime proof. APNTalk remained intentionally stopped and untouched during cluster recreation proof. Evidence: `docs/evidence/k2/traefik-gateway-api.md`.

### K3 - Kubernetes Security Boundaries — Complete

`restricted` Pod Security Admission pinned to `v1.35` on UTCP namespaces; default-deny NetworkPolicies with explicit allow paths; positive/negative connectivity proof, PSA rejection proof, K1/K2 regression, cleanup proof. Evidence: `docs/evidence/k3/kubernetes-network-security.md`.

### K4 - Kubernetes Observability Foundation — Complete

Prometheus Operator, Prometheus, Alertmanager, Grafana, kube-state-metrics, Loki, and Alloy in `utcp-observability` under restricted PSA and K3 NetworkPolicies; metrics, log ingestion, Grafana provisioning, synthetic alert delivery, persistence, gateway/security regression proof. Evidence: `docs/evidence/k4/kubernetes-observability.md`.

### K5 - Multi-Machine Host Visibility and Telephony Placement Awareness — Planned

K5 is a separate infrastructure track for operating UTCP across Kubernetes
clusters containing multiple physical or virtual machines. The first slice is
read-only: discover Kubernetes Nodes, present Host inventory and
Ready/NotReady/basic capacity, associate workload placement with UTCP
RuntimeNodes, and expose the result in an operator-oriented Admin UI. Kubernetes
remains authoritative for Node facts and Pod placement; UTCP owns the
telephony interpretation, including RuntimeNode eligibility and active-call or
binding impact. Later planned slices cover placement awareness, capacity and
failure-domain policy, telephony-aware host maintenance/drain, and multi-site
or cloud placement. See ADR-024.

### C0 - Control-Plane Application Kernel — Complete

Modular-monolith kernel primitives under `App\ControlPlane`: runtime-neutral `RuntimeOperation`, `ExecutionContext`, PostgreSQL leases and fencing, transactional outbox, deduplicating inbox, idempotency records, append-only audit, versioned event envelopes. No `RuntimeNode`, simulator, authentication, SIP, WSS, Kamailio, rtpengine, Asterisk, FreeSWITCH, conference, telephony-session, call-lifecycle, or trunk/route authority exists in C0. Evidence: `docs/evidence/c0/control-plane-application-kernel.md`; ADR-013.

### C1 - Identity, Tenancy, and Authorization — Complete

PostgreSQL-authoritative users, tenants, memberships, built-in roles/capabilities (`platform-admin`, `tenant-admin`, `tenant-member`), first-party server sessions, active-tenant selection, server-computed capability projection, web-admin management after bounded local bootstrap, password lifecycle, suspension behavior, C0 audit integration. No telephony identity, SIP credential, runtime node, or conference behavior. Evidence: `docs/evidence/c1/identity-tenancy-authorization.md`; ADR-014.

### C2 - Runtime Registry and Runtime-Node Management — Complete

`RuntimeNode` as the sole canonical registry entity, tenant-owned, normalized endpoints, encrypted write-only credentials, declared runtime capabilities, desired lifecycle state (`draft`/`active`/`draining`/`disabled`/`retired`), backend-driven runtime-management catalogs, C1 authorization, C0 audit/idempotency/outbox integration. No health probing, reconciliation, command execution, event listeners, or real runtime dependency. Evidence: `docs/evidence/c2/runtime-registry.md`; ADR-015.

### C3 - Command, Event, Projection, and Reconciliation Engine — Complete

Automatic transactional-outbox dispatch with PostgreSQL claims/leases/fencing; generic command-worker contracts; raw runtime-event receipts, connection epochs, duplicate/conflict detection; normalized observations, projection checkpoints; automatic reconciliation with target leases, fencing, blocked/unsupported outcomes, idempotent operation creation for actionable drift; Kubernetes process roles `control-plane-outbox-dispatcher`, `telephony-command-worker`, `telephony-event-normalizer`, `telephony-reconciler`. No simulator, ARI/ESL client, or public command/projection/reconciliation route. The local runtime authority cutoff (`docs/evidence/local-runtime-authority-cutoff.md`) makes `utcp-local` Kubernetes the sole canonical integrated local runtime before C4 begins. Evidence: `docs/evidence/c3/runtime-engine.md`; ADR-016.

### C4 - Deterministic Simulator Adapter — Complete

`simulator-deterministic` `RuntimeAdapter`, `inspect`/`apply_configuration` handlers, and an event normalizer registered under the `simulator-deterministic` adapter key with no branching in the generic C3 engine; `simulator-event-source` Kubernetes process role with no public exposure; all seven scenarios (steady-ready, transient-failure-then-ready, terminal-failure, timeout-then-ready, duplicate-observation, disconnect-reconnect, configuration-drift-then-converge) proven live against deployed `utcp-local` workers; event-source and generic-worker restart/recovery proven with no lost or duplicated work; bounded `simulator_*` metrics and alerts. Two real C3 reconciliation defects (missing `SKIP LOCKED` fencing; `last_operation_id` not preserved across non-`operation_required` results) were found under live concurrency and fixed with regression tests. Evidence: `docs/evidence/c4/deterministic-simulator.md`; ADR-005.

### C5 - Telephony-Session and Conference Domain — Complete

PostgreSQL-authoritative `TelephonySession`, `Conference`, `ConferenceRuntimeBinding`, `ConferenceParticipant`; authenticated APIs; C1 authorization; C0 audit/idempotency/outbox integration; runtime-neutral conference operations (`conference.ensure`, `conference.close`, `conference.participant.ensure`, `conference.participant.remove`); simulator execution; C3 raw receipts, normalized observations, projection checkpoints, automatic reconciliation; scheduler-driven expiry; bounded metrics/alerts; live Kubernetes conference-lifecycle proof, restart/recovery proof, disposable Compose compatibility proof, regression proof; cross-tenant and stale-event edge cases covered by focused PostgreSQL tests. A `TelephonySession` is an authenticated user's tenant-scoped control-plane telephony authorization session only — not SIP registration, media connectivity, a call, microphone access, or a runtime channel. C5 combines what the initial plan called C5 (registration/session) and C8 (conference) into one phase (see Phase-Identifier Reconciliation). Evidence: `docs/evidence/c5/telephony-session-conference-domain.md`; ADR-017.

### T0 - Asterisk ARI Adapter — Complete

`asterisk-ari` adapter, `AsteriskAriClient`, internal local Asterisk ARI Kubernetes fixture (no SIP/media/Gateway-route/NodePort/LoadBalancer/host-network/hostPath/API-token exposure), `asterisk-ari-events` listener role with persistent per-node claimed-connection ownership, listener-lease fencing with stale-epoch cleanup on takeover, periodic HTTP liveness re-inspection, bounded exponential reconnect backoff, C3 connection epochs, raw receipt ingestion, runtime-neutral readiness normalization/projection, bounded metrics/alerts. Live-proven against `utcp-local`: listener steady-state ownership, listener Pod restart/fenced takeover, internal Asterisk Pod restart/automatic recovery, sanitized authentication-failure evidence with bounded retry, credential-generation recovery, the unsupported-conference-capability boundary (an Asterisk T0 node cannot be bound to a C5 conference), unknown-ARI-event safety, metrics/alert firing-and-resolution, NetworkPolicy positive/negative connectivity (including a stale rendered Traefik-to-apiserver egress policy found and corrected). T0 does not implement ConfBridge, C5 conference execution on Asterisk, channel origination, SIP/PJSIP, RTP/media, trunks, PSTN, or browser calling. Evidence: `docs/evidence/t0/asterisk-ari-adapter.md`; ADR-018.

## T1 Roadmap Reconciliation (Kamailio SIP-over-WSS Signaling)

**Status: Complete.** The T1-G final closure corridor (evidence inventory, canonical authority acceptance, two-tenant non-platform isolation proof, Kubernetes phase-wide proof, disposable Compose phase-wide proof, natural browser smoke, security acceptance, observability acceptance, and full repository regression) passed. `UTCP_PHASE` is `T1`. See `docs/roadmap/phase-status.md`, `docs/decisions/ADR-019-kamailio-signaling-registration-authority.md`, and `docs/evidence/t1/kamailio-sip-over-wss-signaling.md`.

T1 activates browser SIP registration over the shared public `443/TCP` edge, scoped narrowly to WSS registration (see Phase-Identifier Reconciliation for why broader SIP application-dialog routing is sequenced to `T3`/`V0` for browser conference routing and to `C6`/`C7`/`T6`/`V1` for external-trunk/general-call routing). The accepted T1 contract, reconciled against `docs/architecture/authority-boundaries.md` and the T1 corridor evidence, is:

- `TelephonySession` is the PostgreSQL authority for signaling eligibility. `telephony_signaling_credentials` is the only persisted SIP credential authority.
- **One active `TelephonySession` may issue exactly one active short-lived SIP credential** with a stable session-scoped username in the canonical `sip.utcp.local.test` realm. There is no supported mode, legacy path, or compatibility option in which more than one Contact/credential is active per signaling identity at a time; any prior roadmap wording implying multiple concurrent active Contacts is stale and has been removed rather than retained as an alternative.
- HA1 is stored as secret-equivalent verifier material; the plaintext SIP secret is returned only once, in the issuance response. The web login password is never reused as a SIP password.
- Kamailio is the sole registrar: actual REGISTER authentication, current Contact binding, replacement, explicit deregistration, and runtime expiration are Kamailio/native-`usrloc` authority, not UTCP-application authority.
- C3 event-source identity is generalized so RuntimeNode-backed listeners and shared platform observers (including the Kamailio registration observer) share one PostgreSQL lease, fencing, source-epoch, receipt, and checkpoint authority — no parallel platform-specific tables.
- The fenced `kamailio-registration-observer` process role diffs sanitized `usrloc` snapshots, emits C3 registration receipts, normalizes them, projects observed registration state, and automatic reconciliation converges desired vs. observed registration.
- The canonical User & Access Management web-admin UI renders user/tenant/membership/role/capability detail, active-`TelephonySession` visibility and canonical termination, and a nested signaling panel exposing safe metadata plus one-time credential issuance — with no permanent SIP account, no user-to-provider-node binding, no PBX assignment, no manual Contact/observer/projection/reconciliation control.
- Disposable Compose compatibility proof (`make compose-proof`) exercises the same credential/registrar/WSS/observer/projection/reconciliation/expiry/cleanup corridor in an isolated disposable project without becoming a second canonical authority.
- T1 does not add INVITE relay, dispatcher/runtime destination selection, application-dialog Record-Route, Asterisk signaling, media, or conference execution; those remain T2/T3/V0 for internal browser conference routing and C6/C7/T6/V1 for external trunk and general call routing.

Corridor naming used informally during development (`T1-A` generic C3 event-source authority, `T1-B` Kamailio registrar/WSS foundation, `T1-C` registration observer/projection/reconciliation, `T1-D` User & Access Management UI, `T1-E` natural browser acceptance, `T1-F` disposable Compose compatibility) is retained here only as a cross-reference to existing runbook/evidence prose (e.g. `docs/runbooks/kamailio-sip-over-wss-registration.md`); it is not a separate phase-numbering scheme and must not be treated as one.

## Future Call-Lifecycle and Call-Control Roadmap

### T2 - Asterisk Conference Execution — Complete

Objective: map the already-canonical C5 conference operations (`conference.ensure`, `conference.close`, `conference.participant.ensure`, `conference.participant.remove`) onto Asterisk. Implement conference creation/resolution, participant channel creation, bridge/conference membership, join/leave operations, runtime-event normalization (`StasisStart`, `ChannelEnteredBridge`, `ChannelLeftBridge`, `ChannelDestroyed`, `BridgeCreated` -> `participant.channel.created`, `participant.conference.joined`, `participant.conference.left`, `conference.created`), membership reconciliation, timeout/partial-failure handling, stale-channel cleanup. Non-goals: SIP/PJSIP, RTP/media, trunks, PSTN, browser calling, FreeSWITCH.

**Status: Complete.** Repository and live T2 evidence prove the Asterisk conference execution corridor: generic C5 conference operations execute through the Asterisk adapter, observed membership is driven by normalized ARI events, retries do not create duplicate participants, stale/wrong-node protection is enforced, and conference state reconciles after runtime interruption and recovery. Browser media and Kamailio application-dialog routing remain T3/V0 scope, not T2 exit criteria. See `docs/evidence/t2/asterisk-conference-recovery.md` and `docs/evidence/t2/multi-node-failover-readiness.md`.

### T3 - rtpengine Browser Media — In Progress

**T3-S2B repository correction (2026-08-02):** `PRODUCT_DEFECT-15` and the committed prover defects are corrected. The exact reciprocal corridor uses the rendered runtime UDP `10000-20000` destination range and rtpengine UDP `40000-40099` destination range with canonical selectors; the prover now has trusted local CA handling, deterministic terminal Job collection, corrected message cursors, Vue hydration synchronization, compatible bundle policy, explicit Scenario A/B selection, and matching UID/GID `1000` HOME storage. Static and mutation coverage passes. T3-S2B is ready for Scenario A and Scenario B live reproof; T3-S2C and T3-S3 remain Not Started. T3 remains In Progress and `UTCP_PHASE=T1` is unchanged. See [`docs/evidence/t3/t3-s2b-media-corridor-and-prover-correction.md`](../evidence/t3/t3-s2b-media-corridor-and-prover-correction.md).

**T3-S2B committed-prover correction (2026-08-02):** Starting at `d4218d5`, the pinned prover image now installs and checks `certutil`; Chromium trusts the public local CA through the canonical `$HOME/.pki/nssdb` and no longer receives `--user-data-dir` or TLS bypasses. Login waits on hydrated controls, audio proof uses `inbound-rtp.totalAudioEnergy`, and SIP ACK/BYE requests use the successful response Contact remote target with preserved route-set and CSeq behavior. Static, mutation, and focused Node coverage pass. `PRODUCT_DEFECT-16` remains open pending clean committed-prover observation. T3-S2B is ready for final committed-prover reproof; T3 remains In Progress and `UTCP_PHASE=T1` is unchanged. See [`docs/evidence/t3/t3-s2b-committed-media-prover-correction.md`](../evidence/t3/t3-s2b-committed-media-prover-correction.md).
**T3-S2C FreeSWITCH parity adapter (2026-08-02):** Starting at `9ca15fa`, a pinned minimal FreeSWITCH runtime, internal ClusterIP SIP Service, `9900` Answer/Echo/Hangup fixture, explicit non-overlapping RTP range `21000-21099`, selected-runtime projection, exact reciprocal SIP/media policies, and generic Kamailio/media-route reuse are implemented. The default Asterisk adapter remains supported; the FreeSWITCH overlay has no dual delivery or Asterisk fallback and exposes no public SIP, ESL, or media. Static and mutation checks pass. T3-S2C is implemented in the repository and awaiting live parity proof; provider-neutral behavior is proven against Asterisk, runtime agnosticism is not yet proven. `PRODUCT_DEFECT-16` remains historical and open with no workaround. T3-S3 remains Not Started, T3 remains In Progress, and `UTCP_PHASE=T1` is unchanged. See [`docs/evidence/t3/t3-s2c-freeswitch-runtime-parity-adapter.md`](../evidence/t3/t3-s2c-freeswitch-runtime-parity-adapter.md).

**T3-S2C FreeSWITCH startup correction (2026-08-02):** Starting at `fe1746e`, `PRODUCT_DEFECT-17` through `PRODUCT_DEFECT-20` are corrected. The entrypoint now uses argument-less `-c` with coordinated `-conf`, `-log`, `-db`, and `-run` directories; the image uses the official FreeSWITCH XML document and section structure, allowlisted required modules, the `utcp-internal` UDP/5060 Sofia profile, the `9900` Echo dialplan, and explicit RTP `21000-21099`; pod-level UID/GID/fsGroup `1000` and writable `emptyDir` mounts cover runtime paths; loopback-only Event Socket and executable status probes replace TCP-on-UDP probes. The actual pinned image startup smoke passed module, Sofia RUNNING, UDP/5060, RTP configuration, Echo fixture, writable-path, healthcheck, and SIGTERM checks. No Kubernetes resources were applied and no parity scenario ran. T3-S2C is ready for focused live parity proof; T3-S2 overall and T3 remain In Progress, T3-S3 is Not Started, and `UTCP_PHASE=T1` is unchanged. See [`docs/evidence/t3/t3-s2c-freeswitch-runtime-startup-correction.md`](../evidence/t3/t3-s2c-freeswitch-runtime-startup-correction.md).

**T3-S2C parity overlay composition correction (2026-08-02):** Starting at `b03b377`, `PRODUCT_DEFECT-21` is corrected. `overlays/local-freeswitch` now inherits the complete canonical `overlays/local` composition and adds only the bounded FreeSWITCH resources and runtime-selection delta. Full rendered validation preserves all canonical local images, ConfigMap/Secret generators, references, default Asterisk behavior, and generic Kamailio relay configuration; it rejects unresolved bare `utcp-*` images and unbounded resource drift. The focused mutation suite covers base re-inclusion, image/generator loss, reference drift, workload and selector drift, dual/fallback selection, public SIP exposure, and provider-specific relay changes. The offline overlay acceptance check passes; no Kubernetes resources were applied and no parity scenario ran. T3-S2C is safe and ready for focused live parity proof. `PRODUCT_DEFECT-16` remains historical and open with no workaround. T3-S2 overall and T3 remain In Progress, T3-S3 is Not Started, and `UTCP_PHASE=T1` is unchanged. See [`docs/evidence/t3/t3-s2c-freeswitch-parity-overlay-correction.md`](../evidence/t3/t3-s2c-freeswitch-parity-overlay-correction.md).

Objective: provide real browser audio through a runtime-neutral media path and introduce the minimum SIP application-dialog routing needed for a registered browser SIP identity to reach the selected Conference execution runtime through Kamailio and rtpengine. Implement SDP offer/answer mediation, ICE handling, DTLS-SRTP, WebRTC-to-RTP adaptation, media anchoring, media-session correlation, timeout/cleanup, media health observation, explicit RTP-related NetworkPolicies, metrics/logs, and the initial Kamailio INVITE route authority for the browser/conference path. That route authority must consume the canonical RuntimeNode eligibility projection rather than redefining placement in Kamailio: an ineligible execution RuntimeNode is excluded from new application-dialog routing, while registration eligibility remains the separate C5/T1 TelephonySession credential authority. Completion criteria: browser audio reaches the selected runtime; media is anchored through rtpengine; signaling and media state correlate; failed media sessions clean up deterministically; initial INVITE routing, runtime destination projection, new-dialog eligibility, existing-dialog behavior, Record-Route/in-dialog routing where required, automatic cutoff/restoration, and absence of direct Asterisk bypass are proven for the internal browser/conference path.

**T3-S1 repository foundation is implemented, `PRODUCT_DEFECT-1` and `PRODUCT_DEFECT-2` are both corrected and confirmed resolved live, `PRODUCT_DEFECT-3` is corrected and confirmed closed live, `PRODUCT_DEFECT-4` is corrected in the repository, and T3-S1 is ready for final scrape-discovery reproof, so T3 remains In Progress.** The two earlier blockers are fixed: the pinned release asset filename separator ([`docs/evidence/t3/t3-s1-rtpengine-package-asset-correction.md`](../evidence/t3/t3-s1-rtpengine-package-asset-correction.md)) and the `/tmp` `emptyDir` that shadowed the image-created pidfile directory ([`docs/evidence/t3/t3-s1-rtpengine-pidfile-correction.md`](../evidence/t3/t3-s1-rtpengine-pidfile-correction.md)); the entrypoint now writes `--pidfile=/run/rtpengine/rtpengine.pid` into the existing writable `/run/rtpengine` `emptyDir`, and `scripts/media/config-check` plus `scripts/media/config-check-test` reject `/tmp`-based, missing, read-only, unapproved, and duplicate PID paths. [`docs/evidence/t3/t3-s1-rtpengine-foundation-live-proof.md`](../evidence/t3/t3-s1-rtpengine-foundation-live-proof.md) (executed at `812c6ec`) proves: the final image identifies repository revision `812c6ec`, rtpengine `mr26.0.1.19`, upstream commit `3552ac76…`, `amd64`, user `1000:1000`, and no embedded credentials; local, registry, and **running** image digests all match `sha256:33cf7e2e…` (`linux/amd64` platform digest `sha256:ad8c7e02…`) on tag `0.1.0-k1-dev` with no `latest`; only rtpengine was rolled, with no manifest re-applied and all 34 other Pods retaining UID and restart count; startup succeeds with **no PID-file error** and `/tmp/rtpengine` is no longer required; restricted `v1.35` PSA **admits** the Pod with zero violation events; the effective security context captured from the real running workload matches ADR-020 §8 exactly (UID/GID `1000`, no capabilities, `NoNewPrivs`, `RuntimeDefault` seccomp, read-only root, no service-account token, no host namespaces, no HostPort, no HostPath); userspace forwarding (`--table=-1`), Pod-IP bind and advertisement, and the exact `40000–40099` range all hold; readiness validates a real `ng` `pong` about one second after container start and the EndpointSlice becomes Ready; a `SIGSTOP` on PID 1 makes the real `ng` liveness probe fail and kubelet restarts the container automatically (`exitCode 137`, restart count `0`→`1`, same Pod UID) with readiness, the PID file, and `ng` `pong` all returning at one replica; ClusterIP UDP `2223` carries exactly one Ready endpoint equal to the current Pod IP; unauthorized control and unauthorized metrics are both denied with bounded failures, isolated through a `default`-namespace source with unrestricted egress; the media boundary is contained (no NodePort, LoadBalancer, Gateway/Ingress/UDPRoute, HostPort, k3d publication, node socket, or developer-host socket for `40000–40099`, edge unchanged at TCP `80/443`); the metrics listener serves valid Prometheus text whose port counters match the bounded range exactly (`rtpengine_ports = 100`); relay unavailability produces a visible bounded failure with no Asterisk fallback and no canonical state change; restoration is fully automatic with no manual reconciliation, projection, or repair command; the Kamailio config is byte-identical to git with zero media-routing directives and `REGISTER` intact; and no durable media table, RuntimeNode, registry capability, tenant, Redis, outbox, web-admin, or Artisan authority changed. **`PRODUCT_DEFECT-3` is corrected:** [`docs/evidence/t3/t3-s1-rtpengine-reciprocal-egress-correction.md`](../evidence/t3/t3-s1-rtpengine-reciprocal-egress-correction.md) records the exact reciprocal source egress from Kamailio to rtpengine `2223/UDP`, reciprocal source egress from Prometheus to rtpengine `2224/TCP`, the internal rtpengine PodMonitor for `/metrics`, default-deny preservation, and static/mutation coverage rejecting missing, wrong-port, widened, and missing-scrape variants. The authorized-corridor reproof at `b21c117` confirms `PRODUCT_DEFECT-3` is closed live: only the two reciprocal NetworkPolicies and the rtpengine `PodMonitor` were applied, causing zero Pod restarts, zero image changes, and zero Deployment rollouts, with rtpengine, Kamailio, and Prometheus all retaining identical Pod UIDs and restart counts. The authorized Kamailio identity now receives `result=pong`; the authorized Prometheus identity now receives `HTTP/1.0 200 OK` from rtpengine `2224/TCP` including `rtpengine_ports = 100`; unauthorized control and unauthorized metrics both remain denied with bounded failures; default-deny is intact; and no canonical state or Kamailio runtime configuration changed. **`PRODUCT_DEFECT-4` is corrected in the repository:** [`docs/evidence/t3/t3-s1-prometheus-operator-api-egress-correction.md`](../evidence/t3/t3-s1-prometheus-operator-api-egress-correction.md) records the bounded Prometheus Operator API-egress correction. The rtpengine `PodMonitor`, its selectors, its named `metrics` port resolving to TCP `2224`, and the Prometheus CR selectors were already verified correct, but no `PodMonitor` rendered into scrape configuration because the Prometheus Operator was selected by no API-egress policy: the old template allowed `app.kubernetes.io/name: prometheus-operator` while the chart labels the operator Pod `kube-prometheus-stack-prometheus-operator` with `app.kubernetes.io/component: prometheus-operator`. The template now renders a narrow `allow-prometheus-operator-apiserver-egress` policy selecting `app.kubernetes.io/component: prometheus-operator`, removes the unused dead name selector, preserves the pinned API destination and TCP port contract, and adds static rendered-label coverage validation for every observability API-dependent workload. Only operator readiness, PodMonitor job rendering, target health, and one ingested `rtpengine_*` sample need re-running. [`ADR-020`](../decisions/ADR-020-t3-rtp-media-plane.md) fixes the RTP media-plane architecture and [`docs/evidence/t3/t3-rtp-media-preparation-audit.md`](../evidence/t3/t3-rtp-media-preparation-audit.md) records the criterion matrix, repository inventory, guard disposition, and the exact first implementation slice. [`docs/evidence/t3/t3-s1-rtpengine-foundation-implementation.md`](../evidence/t3/t3-s1-rtpengine-foundation-implementation.md) records the implemented pinned relay foundation. Decisions implemented in T3-S1: rtpengine is shared platform infrastructure in `utcp-platform` (a single-replica `Deployment`, no `RuntimeNode` or registry capability, mirroring the ADR-019 Kamailio precedent); in-cluster networking only, with ng control on ClusterIP `2223/UDP` and media on `40000–40099/UDP` bound and advertised on the Pod IP; userspace forwarding (`--table=-1`) so Pod Security Admission `restricted:v1.35` holds with no exception; a digest-pinned repository-built image following the Asterisk precedent; one new `allow-rtpengine-media` NetworkPolicy with default-deny preserved; and no new durable tables. RTP is an explicit UDP media boundary and is never tunnelled through the HTTP/WSS `443` Traefik route, so `scripts/gateway/config-check` and `scripts/k3d/config-check` are retained. T3-S1 adds bounded guard replacements and no live SIP media routing; browser-reachable media, Kamailio INVITE route authority, and conference admission remain deferred to later T3 slices and V0. **T3-S1 is Complete.** The final scrape-discovery proof at `bde02b7` applied exactly one resource — `NetworkPolicy/utcp-observability/allow-prometheus-operator-apiserver-egress`, selecting `app.kubernetes.io/component: prometheus-operator` with egress limited to the pinned API endpoint `172.24.0.2/32` on TCP `6443` — and proved all four remaining outcomes: the Prometheus Operator reached the Kubernetes API (`connection established kubernetes_version=1.35.3+k3s1`, API capabilities discovered, informers synced, zero errors in the entire post-recovery log, and no TLS, authorization, or service-account error replacing the policy error); it recovered to Ready through its **own** `5m0s` crash-loop backoff with no manual rollout (restart `396`->`397`, same Pod UID, Deployment `1/1/1`, then stable for 9 minutes with the restart count frozen at 397); the unchanged rtpengine `PodMonitor` (generation `1`, rv `423578`) was reconciled by normal Operator authority, moving the generated configuration secret from rv `260855` to `426909` and `podMonitor` jobs from `0` to `1`, producing `podMonitor/utcp-observability/rtpengine/0` whose namespace selector, both pod-label selectors, `/metrics` path, and named `metrics` port resolving to TCP `2224` all match the PodMonitor exactly; and the rtpengine target is discovered and `up` at `http://10.42.1.216:2224/metrics` with an empty `lastError`, a recent `lastScrape`, and a `2.3ms` scrape duration, with `rtpengine_ports = 100`, `rtpengine_ports_free = 100`, `rtpengine_ports_used = 0`, and `rtpengine_sessions = 0` ingested and queried **through Prometheus itself** and labelled `namespace=utcp-platform`, `pod=rtpengine-74cd786966-hvcrn`, `container=rtpengine`, `instance=10.42.1.216:2224`. Default-deny remained intact, no public control, metrics, or media exposure was introduced, rtpengine, Prometheus, and Kamailio all retained identical Pod UIDs, restart counts, and images with no rollout, and no canonical state or Kamailio runtime configuration changed. All four defects in the T3-S1 arc (`PRODUCT_DEFECT-1` through `PRODUCT_DEFECT-4`) are now closed. T3 remains In Progress: the next bounded slice per ADR-020 sections 5 and 7 is the Kamailio application-dialog media route (SDP `offer`/`answer`/`delete` with `REGISTER` untouched), consuming the ClusterIP control endpoint this proof established.

**T3-S2A live signaling proof is INCOMPLETE at `cbc098e`: `PRODUCT_DEFECT-5` and `PRODUCT_DEFECT-6` are both closed and confirmed live, and three new defects — `PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`, and `PRODUCT_DEFECT-9` — remain open.** [`docs/evidence/t3/t3-s2a-asterisk-sip-application-dialog-authority.md`](../evidence/t3/t3-s2a-asterisk-sip-application-dialog-authority.md) records the bounded prerequisite for T3-S2 media mediation: one canonical internal Asterisk SIP destination (`asterisk-sip.utcp-runtime.svc.cluster.local:5060`), an explicit `autoload=no` PJSIP module set, one UDP `5060` internal transport, a Kamailio-facing `from-kamailio` endpoint with `direct_media=no`, a dedicated ClusterIP SIP Service separate from ARI, exact reciprocal Kamailio/Asterisk SIP NetworkPolicies, a local-overlay-only `9900` Answer/Echo/Hangup media fixture, and a Kamailio application-dialog route seam that authenticates initial INVITEs, applies Record-Route, uses loose routing for sequential requests, handles ACK/CANCEL/BYE/UPDATE explicitly, preserves unsupported-method `405`, and returns `503 Application Runtime Unavailable` when the canonical Asterisk target cannot be relayed. REGISTER remains unchanged, no rtpengine operation or SDP rewriting is present, no public SIP surface was added, and no Kubernetes apply or workload restart was performed by the repository implementation. T3-S1 is Complete, T3-S2 media mediation is Not Started, and T3 remains In Progress. The T3-S2A live signaling proof at `ab5ab55` remained incomplete because `PRODUCT_DEFECT-5` made the rendered Kamailio configuration unparseable: `route[APPLICATION_DIALOG]` invoked `has_totag()` without `loadmodule "siputils.so"`, causing new Pods to exit `255` while the pre-T3-S2A Pod stayed Ready. [`docs/evidence/t3/t3-s2a-kamailio-siputils-rendered-config-correction.md`](../evidence/t3/t3-s2a-kamailio-siputils-rendered-config-correction.md) records the repository correction: the canonical ConfigMap now loads `siputils.so`; `scripts/kamailio-signaling/config-check` renders each supported Kamailio variant, extracts the final mounted `kamailio.cfg`, validates it with the pinned `ghcr.io/kamailio/kamailio:5.8.6-bookworm` parser, and verifies a deterministic `utcp.io/kamailio-config-sha256` Pod-template checksum; `scripts/kamailio-signaling/config-check-test` now covers missing module, unknown command, invalid module parameter, route syntax, rendered ConfigMap drift, and checksum rollout-coupling regressions. No rtpengine mediation was added. The only remaining T3-S2A proof is Kamailio-only: apply the corrected ConfigMap/checksum change, confirm the failed ReplicaSet is replaced by a Ready Pod, and resume the dialog proof from subscriber authentication onward. T3-S2 media mediation remains Not Started. T3 remains In Progress. **The T3-S2A Kamailio dialog reproof at `92365f8` closes `PRODUCT_DEFECT-5` and is recorded in [`docs/evidence/t3/t3-s2a-asterisk-sip-application-dialog-live-proof.md`](../evidence/t3/t3-s2a-asterisk-sip-application-dialog-live-proof.md), but T3-S2A is ready for authenticated-dialog reproof after repository correction of `PRODUCT_DEFECT-6`.** Proven live: only the corrected `kamailio-config` ConfigMap (sha256 `a4b733fd...` to `bc14c98e...`) and the checksum-coupled `kamailio` Deployment (generation 11 to 12) were applied; the `utcp.io/kamailio-config-sha256` annotation equals the rendered `kamailio.cfg` SHA-256 exactly, so configuration content alone changed the Pod template and produced a fully automatic ~3-second rollout with **no manual restart and no timestamp annotation**; ReplicaSet convergence was correct (new revision 12 took over, the old Ready Pod was retired only after the replacement reached Ready, and the previously failing revision 11 no longer owns a replica); `siputils.so` loads, the authoritative parser run against the exact running configuration is clean with no `has_totag` error, and the running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount, and Pod annotation; the old blanket non-REGISTER `405` guard no longer intercepts INVITE while the REGISTER branch is unchanged and no rtpengine operation or SDP rewriting exists; an unauthenticated INVITE to `9900` now returns the canonical `401` challenge with `realm=sip.utcp.local.test` instead of the previous `405`; subscriber digest authentication for INVITE **succeeds** (execution reached `route[ASTERISK_RELAY]`, which is gated behind the authentication guard) using a credential issued only through the authorized API; `MESSAGE` still returns `405` from the corrected configuration; REGISTER is preserved end to end (`200 accepted`, one active location contact, registrar branch taken, Asterisk and rtpengine untouched); rtpengine sessions and port counters are unchanged with zero offer/answer/delete operations; and Asterisk, rtpengine, and every unrelated workload retain identical Pod UIDs and restart counts with no canonical state mutation. **`PRODUCT_DEFECT-6`:** `kamailio-configmap.yaml` declares only `listen=tcp:0.0.0.0:8080` and provides no UDP socket, while `route[ASTERISK_RELAY]` targets `sip:asterisk-sip.utcp-runtime.svc.cluster.local:5060;transport=udp`. `t_relay()` therefore cannot add a branch (`uri2dst2(): no corresponding socket found ... (udp:10.43.209.141:5060)`, `prepare_new_uac(): can't fwd ... (no corresponding listening socket)`) and the committed `503 Application Runtime Unavailable` branch fires **while Asterisk is healthy and its Service endpoint is Ready** — a false unavailability signal masking a transport misconfiguration. Static and rendered-parser checks pass because syntactic validity does not imply a matching transport socket. [`docs/evidence/t3/t3-s2a-kamailio-udp-relay-socket-correction.md`](../evidence/t3/t3-s2a-kamailio-udp-relay-socket-correction.md) records the repository correction: the canonical ConfigMap now adds exactly one `listen=udp:0.0.0.0:5060` socket beside the existing TCP `8080` listener, the Deployment declares the matching UDP `5060` container port, the deterministic `utcp.io/kamailio-config-sha256` annotation is recalculated from the rendered `kamailio.cfg`, and `scripts/kamailio-signaling/config-check` now asserts that every routing destination transport has a corresponding `listen=` socket and matching container port. Consequently the relayed INVITE, Asterisk execution of `9900`, the SDP-bearing answer, `Record-Route`, ACK, BYE, and a genuine Asterisk-unavailable `503` remain unproven and are not claimed; the Asterisk-unavailable condition was deliberately not induced because the same `503` already fires with Asterisk healthy and would produce non-discriminating evidence. T3-S2 media mediation is Not Started and T3 remains In Progress. **The T3-S2A authenticated dialog reproof at `cbc098e` closes `PRODUCT_DEFECT-6` and is recorded in [`docs/evidence/t3/t3-s2a-asterisk-sip-application-dialog-live-proof.md`](../evidence/t3/t3-s2a-asterisk-sip-application-dialog-live-proof.md), but T3-S2A remains In Progress behind three new defects.** Exactly two resources were applied in order — the corrected `kamailio-config` ConfigMap (sha256 `bc14c98e...` to `749fc1ca...`, one added `listen=udp:0.0.0.0:5060` line) and the checksum-coupled `kamailio` Deployment (generation 12 to 13, adding the `sip-udp` UDP `5060` container port) — with `kubectl diff` restricted to those two showing no unrelated drift, the image and securityContext unchanged, and no rollout timestamp; the ConfigMap apply alone changed neither the Deployment nor the Pod, confirming the running process does not reparse the mounted file, and the Deployment apply produced a fully automatic ~5-second rollout to ReplicaSet revision 13 that retired the old Pod only after the replacement started, with **no manual restart, no reload RPC, and no Pod deletion**. Proven live: Kamailio owns exactly one UDP `0.0.0.0:5060` socket, confirmed by its own startup banner (`udp: 0.0.0.0 [0.0.0.0]:5060`, `tcp: 0.0.0.0 [0.0.0.0]:8080`) and by the Pod's kernel socket table, with zero `no corresponding socket`, `prepare_new_uac`, or `t_forward_nonack` errors and zero ERROR lines in the running container; the running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount, and Pod checksum annotation (`749fc1ca...`); no public Kamailio UDP surface exists (ClusterIP `8080/TCP` only, no UDP Service, no NodePort, no LoadBalancer, no `UDPRoute` CRD, no HostPort, no HostNetwork, and no UDP `5060` socket on any k3d node or on the developer host); the authenticated INVITE reaches healthy Asterisk over the canonical internal Service and the prior **false** healthy-Asterisk `503` is gone; Asterisk identifies the internal `anonymous` endpoint (`context=from-kamailio`, `direct_media=false`, `transport-udp-internal 0.0.0.0:5060`) and executes `from-kamailio` extension `9900` through `Answer()` to `Echo()`, captured live as `PJSIP/anonymous-00000001 from-kamailio 9900 Prio 3 Up Echo`; a valid SDP-bearing `200 OK` returns through Kamailio carrying Asterisk's own origin and connection address and an Asterisk-chosen RTP port, with zero rtpengine rewriting, zero rtpengine sessions, and zero `msg_apply_changes`/`subst_body`/`replace_body`; `record_route()` executes with correct double record-routing (`;lr`, `;r2=on`, `;ftag=`) keeping Kamailio in the route set; the intentional `asterisk-ari` `1 -> 0 -> 1` availability test restored automatically with PJSIP UDP `5060` active, exactly one Ready `asterisk-sip` endpoint, and the authenticated INVITE succeeding again against the **new** Pod IP with no stale address pinning and no manual reconciliation; REGISTER is preserved end to end (`200 accepted`, one active contact, registrar branch taken) and unsupported `MESSAGE` still returns `405`; rtpengine sessions and port counters remained `0` with zero offer/answer/delete; and the full-cluster Pod snapshot diff contains only the Kamailio rollout and the intentional Asterisk availability test, with every other Pod retaining its UID and restart count and no durable dialog or media authority, tenant, RuntimeNode, outbox, or Redis `sip`/`dialog`/`rtp`/`media` change. **`PRODUCT_DEFECT-7`:** in `request_route` the destination-domain guard `if ($rd != "sip.utcp.local.test") { sl_send_reply("403","Forbidden"); }` executes **before** `route(APPLICATION_DIALOG)` and therefore before `has_totag()` and `loose_route()`, so every in-dialog request — whose Request-URI is the negotiated Asterisk remote target `sip:<pod-ip>:5060` — is rejected `403 Forbidden` with `kamailio_registration_rejected result=foreign_domain`; the ACK never reaches Asterisk (which retransmits its unacknowledged `200 OK` and finally tears the channel down on its own timer) and the BYE is answered `403` by Kamailio itself, so `route[WITHINDLG]` and `loose_route()` are unreachable for real dialogs and no dialog can be terminated through the control plane. **`PRODUCT_DEFECT-8`:** `record_route()` advertises `<sip:0.0.0.0;lr;r2=on;ftag=...>, <sip:0.0.0.0:8080;transport=ws;lr;r2=on;ftag=...>` because both listeners bind the wildcard address with no `advertise` clause or `advertised_address`, producing an unroutable route set; this is latent for the SIP-over-WSS client direction, which reuses its established WebSocket transport per RFC 7118, and blocking for Asterisk-initiated in-dialog requests, whose live confirmation is gated behind `PRODUCT_DEFECT-7` because it requires a confirmed dialog. Note that `scripts/kamailio-signaling/config-check` currently both mandates the wildcard bind and fails any Service selecting Kamailio that exposes a UDP port without distinguishing an internal ClusterIP port from public exposure, so that assertion needs narrowing to public surface only. **`PRODUCT_DEFECT-9`:** `route[ASTERISK_RELAY]` detects unavailability only through the synchronous `t_relay()` return value and arms no `t_on_failure(...)` failure route, so the committed `503 Application Runtime Unavailable` contract is unreachable for the condition it was written for — the Service DNS resolves to its ClusterIP whether or not any endpoint is Ready and UDP surfaces no immediate delivery failure, so `t_relay()` succeeds and the failure appears only asynchronously; with `asterisk-sip` at **zero** Ready endpoints the authenticated INVITE received `100 Trying` then a bare `408 Request Timeout` after ~30 seconds with **no** `result=asterisk_unavailable` log, while no second Asterisk destination (`asterisk-ari-b` reported `0 calls processed`), Pod-IP fallback, ARI fallback, rtpengine routing, direct-media bypass, or database/RuntimeNode mutation occurred. CANCEL remains a bounded proof limitation because the `9900` fixture answers immediately and was not altered. The next bounded slice is a Codex correction of `PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`, and `PRODUCT_DEFECT-9` with matching static coverage, followed by a focused reproof of ACK continuity, BYE continuity, and the Asterisk-unavailable `503` only. T3-S2A is In Progress, T3-S2 media mediation is Not Started, and T3 remains In Progress.

**T3-S2A live signaling proof is INCOMPLETE at `081267a`: `PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`, and `PRODUCT_DEFECT-9` are all closed and confirmed live, and one new defect — `PRODUCT_DEFECT-10` — remains open.** [`docs/evidence/t3/t3-s2a-in-dialog-routing-and-failure-contract-correction.md`](../evidence/t3/t3-s2a-in-dialog-routing-and-failure-contract-correction.md) records the correction of `PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`, and `PRODUCT_DEFECT-9` in the repository: established-dialog `has_totag()`/`WITHINDLG` processing now precedes initial-domain validation while initial foreign domains remain rejected; Record-Route listeners preserve wildcard binds but advertise the canonical T1 edge identity and the new internal `kamailio-sip-internal.utcp-platform.svc.cluster.local:5060` Service identity; exact reciprocal Asterisk-to-Kamailio UDP `5060` NetworkPolicies are rendered without public SIP exposure; and `route[ASTERISK_RELAY]` arms `failure_route[ASTERISK_UNAVAILABLE]` before `t_relay()` so generated local `408` timeout/no-response becomes the committed `503 Application Runtime Unavailable` without retry, fallback, ARI, rtpengine, or broad response override. Static and mutation coverage now guards all three defects, every supported render parses with the pinned Kamailio image, and no Kubernetes apply was performed. `PRODUCT_DEFECT-7 = corrected in repository`; `PRODUCT_DEFECT-8 = corrected in repository`; `PRODUCT_DEFECT-9 = corrected in repository`. T3-S2A is ready for focused ACK/BYE/failure reproof. T3-S2 media mediation is Not Started. T3 remains In Progress. **The T3-S2A final in-dialog reproof at `081267a` closes `PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`, and `PRODUCT_DEFECT-9`, and is recorded in [`docs/evidence/t3/t3-s2a-asterisk-sip-application-dialog-live-proof.md`](../evidence/t3/t3-s2a-asterisk-sip-application-dialog-live-proof.md), but T3-S2A remains In Progress behind `PRODUCT_DEFECT-10`.** Exactly five resources were applied in dependency order — the corrected `kamailio-config` ConfigMap (sha256 `749fc1ca...` to `58e5c733...`, adding both `advertise` clauses, moving `OPTIONS`/`CANCEL`/`has_totag()` ahead of the initial-domain guard, relabelling that guard `result=initial_foreign_domain`, and adding `t_on_failure("ASTERISK_UNAVAILABLE")` plus `failure_route[ASTERISK_UNAVAILABLE]`), the new ClusterIP `kamailio-sip-internal` Service (UDP `5060`, `targetPort: sip-udp`), `allow-kamailio-signaling-required-traffic` (generation 4 to 5, adding UDP `5060` ingress from the canonical Asterisk Pod identity), `allow-asterisk-sip-from-kamailio` (generation 1 to 2, adding `Egress` with UDP `5060` to the canonical Kamailio signaling Pod), and the checksum-coupled `kamailio` Deployment (generation 13 to 14) — with `kubectl diff` restricted to those five showing no Asterisk Deployment, rtpengine, Prometheus, public-edge, unrelated ConfigMap, image, security-context, or rollout-timestamp change. The Deployment apply produced a fully automatic ~4-second rollout to ReplicaSet revision 14 that retired the old Pod only after the replacement started, with no manual restart, no Pod deletion, and no reload RPC; the running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount, and Pod checksum annotation (`58e5c733...`), and Kamailio's own banner confirms `udp: 0.0.0.0:5060 advertise udp:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060` and `tcp: 0.0.0.0:8080 advertise tcp:sip.utcp.local.test:443` with zero ERROR lines. Proven live: the `Record-Route` set contains exactly the two intended stable identities and no `0.0.0.0`, Kamailio Pod IP, node IP, developer-host IP, or unrelated Service, with the Asterisk Pod IP appearing only as the Asterisk `Contact`; the route set is usable, not merely syntactically present — the in-dialog ACK is loose-routed into `route[WITHINDLG]` without repeating subscriber authentication and without any `foreign_domain` rejection, so Asterisk post-ACK `200 OK` retransmissions fell from **3 to 0**; the client-originated BYE follows the same route set, reaches the same Asterisk dialog, returns `200 OK`, and terminates the channel with no manual cleanup, with Call-ID and both tags consistent throughout; `Service/kamailio-sip-internal` is ClusterIP UDP `5060` on `targetPort: sip-udp` with exactly one Ready endpoint equal to the current Kamailio Pod IP, tracked correctly across the whole proof, and no hard-coded ClusterIP or Pod IP is used as routing authority (zero `10.42.x.y` literals in the running configuration); the reverse corridor admits only the canonical workloads on UDP `5060` in both directions with `utcp-platform/default-deny` and `utcp-runtime/default-deny` both intact, no `ipBlock`, no namespace-wide SIP rule, no media ports, and the secondary `asterisk-ari-b` workload correctly excluded by its `utcp.dev/runtime-node: local-asterisk-ari-b` label, confirmed by a bounded probe run inside the real Asterisk container that received `SIP/2.0 200 Keepalive` from the internal Service while a non-admitted destination timed out; a fresh out-of-dialog request to `sip:9900@not-utcp.invalid` still returns `403 Forbidden` with `result=initial_foreign_domain` and is forwarded to neither Asterisk nor rtpengine; zero Ready `asterisk-sip` endpoints now produce an explicit `503 Application Runtime Unavailable` through `failure_route[ASTERISK_UNAVAILABLE]` with the Call-ID-correlated `kamailio_application_dialog_rejected result=asterisk_unavailable` log, replacing the previous bare `408`, while no alternate Asterisk workload received a call (`asterisk-ari-b` reported `0 calls processed`), no Pod-IP fallback, ARI route, rtpengine route, direct-media bypass, second destination, or database/RuntimeNode mutation occurred; restoration to the committed replica count is automatic with PJSIP UDP `5060` active, one Ready endpoint, and INVITE, ACK, and BYE all succeeding against the **new** Asterisk Pod IP with no stale pinning; and REGISTER through the existing registrar path, unsupported `MESSAGE` `405`, rtpengine non-involvement (sessions and ports_used `0`, zero offer/answer/delete), public-surface containment (both Kamailio Services ClusterIP-only, no NodePort, LoadBalancer, ExternalIP, Gateway/Ingress/UDPRoute, HostPort, HostNetwork, node socket, or developer-host socket), and state authority (tables 41, tenants 27, RuntimeNodes 110, pending outbox 0, Redis `sip`/`dialog`/`rtp`/`media` all 0) are all preserved, with the full-cluster Pod diff containing only the Kamailio rollout and the intentional Asterisk `1 -> 0 -> 1` test. **`PRODUCT_DEFECT-10`:** the new `egress` block in `infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml` grants the canonical Asterisk workload only UDP `5060` to the Kamailio signaling Pod, while `utcp-runtime/default-deny` denies all other egress and no other policy in `utcp-runtime` grants that Pod any egress — so it has **no DNS egress**. Because closing `PRODUCT_DEFECT-8` made `record_route()` advertise a DNS name by design, Asterisk must resolve `kamailio-sip-internal.utcp-platform.svc.cluster.local` to route an in-dialog request, but every lookup fails with `gaierror` and a raw UDP query to `kube-dns` `10.43.0.10:53` times out rather than returning an rcode, proving the DNS transport itself is denied. A bounded proof action requesting hangup of a confirmed proof channel therefore produced zero Kamailio log lines and no BYE at the client, while the channel terminated locally only — Asterisk-initiated in-dialog signaling (BYE on hangup, session-timer re-INVITE) cannot leave the Pod. The asymmetry is that `allow-kamailio-signaling-required-traffic` already grants **Kamailio** `UDP 53` + `TCP 53` egress to `kube-system`, which is why Kamailio resolves `asterisk-sip...`; the Asterisk policy has no mirror. Before `081267a` the omission was harmless because Asterisk never originated outbound traffic — ARI is ingress and SIP replies ride the established conntrack flow. All static checks pass because nothing asserts that a workload required to reach an advertised in-cluster identity also has DNS egress. The smallest bounded correction is the mirrored `kube-system` DNS egress rule plus a `scripts/security/config-check` assertion and a `config-check-test` mutation removing it; no proof-only NetworkPolicy was added to work around it during proof. CANCEL remains a bounded proof limitation because the `9900` fixture answers immediately and was not altered. The next bounded slice is that DNS-egress correction followed by a focused reproof of the Asterisk-originated BYE only. T3-S2A is In Progress, T3-S2 media mediation is Not Started, and T3 remains In Progress.

[`docs/evidence/t3/t3-s2a-asterisk-cluster-dns-egress-correction.md`](../evidence/t3/t3-s2a-asterisk-cluster-dns-egress-correction.md) records `PRODUCT_DEFECT-10 = corrected in repository`: the canonical Asterisk NetworkPolicy now mirrors the repository-established cluster-DNS authority used by Kamailio, granting only UDP `53` and TCP `53` to the combined `kube-system` namespace selector and `k8s-app=kube-dns` Pod selector while preserving exact UDP `5060` SIP egress to canonical Kamailio, default-deny, secondary Asterisk exclusion, and the internal Service DNS route-set identity `kamailio-sip-internal.utcp-platform.svc.cluster.local:5060`. `scripts/security/config-check` now compares the Asterisk DNS rule with the Kamailio DNS rule and rejects selector drift, `ipBlock`, broad CIDRs, unrestricted egress, namespace-wide DNS, DNS-over-TLS or unrelated ports, missing SIP egress, and secondary-workload inclusion; `scripts/security/config-check-test` covers missing, widened, split-selector, IP-literal, and stale-authority mutations. No Kubernetes apply or workload restart was performed. T3-S2A is ready for final Asterisk-originated BYE reproof. T3-S2 media mediation is Not Started. T3 remains In Progress. **The T3-S2A Asterisk-originated BYE closure proof at `741efbb` closes `PRODUCT_DEFECT-10`, but T3-S2A remains In Progress behind a new `PRODUCT_DEFECT-11`.** Exactly one resource was applied — `NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio` (generation 2 to 3, rv 445437 to 448277), whose `kubectl diff` contained only the added `UDP 53` + `TCP 53` egress rule to the `kube-system` namespace with `podSelector k8s-app=kube-dns` — and no workload was restarted or replaced (the full-cluster Pod snapshot diff is empty). The repository's matching tightening of the **Kamailio** DNS rule was proven by `kubectl diff` to be a pure hardening delta on an already-working rule and was deliberately not applied, leaving `allow-kamailio-signaling-required-traffic` live at generation 5 against a repository render of 6. Proven live: the canonical Asterisk workload now resolves cluster DNS from its real Pod network namespace over **both** UDP and TCP (`kamailio-sip-internal.utcp-platform.svc.cluster.local`, `rcode=0`, `10.43.3.212`, matching the live ClusterIP exactly, at 0.5 ms and 0.3 ms) through the in-cluster resolver `10.43.0.10` with no `/etc/hosts` entry and no `hostAliases` supplying the answer; the policy selects only the canonical `local-asterisk-ari` Pod while `asterisk-ari-b` is selected solely by `default-deny` and therefore holds no egress grant; existing SIP egress remains exact (a bounded probe resolving the Service by name received `SIP/2.0 200 Keepalive` from UDP `5060`, while rtpengine `ng` `2223` and PostgreSQL `5432` from the same identity remained denied); and **a real Asterisk-originated in-dialog BYE was generated by a bounded `channel request hangup` stimulus, left the Asterisk Pod, reached Kamailio over the internal Service identity, entered established-dialog handling and was processed by `loose_route()`** — Kamailio's own log carries the exact dialog Call-ID `1281477efeaeaf32@utcp-s2a-closure` with `route=within_dialog method=BYE`, with no `result=initial_foreign_domain` rejection and no repeated subscriber authentication. Default-deny remains active in both namespaces with no `ipBlock`, no unrestricted rule, no namespace-only destination, and no NodePort, LoadBalancer, ExternalIP, HostPort, HostNetwork, Gateway, Ingress, UDPRoute, or public SIP path added; rtpengine sessions and ports_used remained `0` with zero offer/answer/delete; and tables 41, tenants 27, RuntimeNodes 110, pending outbox 0 and Redis `sip`/`dialog`/`rtp`/`media` all 0 were preserved. **`PRODUCT_DEFECT-11`:** Kamailio could not route an in-dialog request *to* a SIP-over-WSS client. `route[APPLICATION_DIALOG]` called `record_route()` but no WebSocket Contact alias helper, and `route[WITHINDLG]` called `loose_route()` then `t_relay()` but never `handle_ruri_alias()`; `nathelper.so` was loaded yet `set_contact_alias`, `add_contact_alias`, `handle_ruri_alias`, `fix_nated_contact`, `fix_nated_register` and `nat_uac_test` all occurred **0** times. Kamailio therefore DNS-resolved the client's `Contact` host — which RFC 7118 section 5.2.1 allows to be a randomly generated `.invalid` domain, as every real browser stack uses — and failed: `sip_hostport2su(): could not resolve hostname: "utcp-s2a-proof.invalid"` then `uri2dst2(): failed to resolve` then `t_forward_nonack(): failure to add branches` then `sl_reply_error()`. The BYE never reached the client, the client returned no response, and the Asterisk channel terminated only by its own local hangup. The seam is isolated precisely by an asymmetry: *responses* toward the client still work, because `tm` routes them by `Via` on the transaction-bound WebSocket connection, so only a **new request** toward the client needs the missing alias binding. This blocks any Asterisk-initiated in-dialog request toward a browser client — BYE on hangup, session-timer re-INVITE, in-dialog UPDATE — and therefore directly blocks V0 conference admission semantics. [`docs/evidence/t3/t3-s2a-websocket-dialog-alias-correction.md`](../evidence/t3/t3-s2a-websocket-dialog-alias-correction.md) records `PRODUCT_DEFECT-11 = corrected in repository`: the selected correction uses the official WebSocket `add_contact_alias()` pattern after subscriber authentication and before `record_route()` on authenticated initial WS/WSS application INVITEs, then uses `$du == ""` plus `handle_ruri_alias()` after `loose_route()` and before relay in `WITHINDLG`. Alias failures return `400 Bad Request` and stop routing; REGISTER, location storage, Outbound/Path/GRUU, rtpengine, Record-Route identities, the Asterisk failure route, and the pending DNS selector hardening remain unchanged. Static and mutation coverage now guard the alias lifecycle, and every supported rendered Kamailio configuration parses with the pinned image. No Kubernetes apply or workload restart was performed. T3-S2A is ready for final browser-bound BYE reproof. **The T3-S2A WebSocket alias closure proof at `1381bf3` closes `PRODUCT_DEFECT-11`, `PRODUCT_DEFECT-12` and `PRODUCT_DEFECT-13`, but T3-S2A remains In Progress behind a new `PRODUCT_DEFECT-14`.** Exactly two resources were applied — the corrected `kamailio-config` ConfigMap (sha256 `3a38ad30...` to `2b92c60b...`, replacing the uppercase `"WS"/"WSS"` guard with lowercase `"ws"/"wss"` and adding the `missing_dialog_contact_alias` `$du` postcondition) and the checksum-coupled `kamailio` Deployment (generation 15 to 16) — producing a fully automatic ~4-second rollout to ReplicaSet revision 16 with no manual restart, zero ERROR lines, and running configuration byte-identical across repository render, live ConfigMap, in-Pod mount and Pod checksum annotation. A bounded packet capture in the Kamailio k3d node network namespace, filtered per Call-ID with Authorization redacted, proves the corridor end to end. **`PRODUCT_DEFECT-12` closed:** the lowercase guard executes and `add_contact_alias()` runs — zero uppercase comparisons remain. **`PRODUCT_DEFECT-11` closed:** the INVITE forwarded to Asterisk carries exactly one alias, `Contact: <sip:ts-...@utcp-s2a-proof.invalid;alias=10.42.0.150~36196~5;transport=ws>`, retaining the original browser identity and `.invalid` host; Asterisk retains the alias-bearing remote target, proven from the generated BYE Request-URI itself; that BYE reaches Kamailio, `has_totag()` routes it to `route[WITHINDLG]`, `loose_route()` succeeds, `handle_ruri_alias()` consumes the alias, `$du` becomes non-empty and the **existing** browser WebSocket connection is selected; the browser receives the BYE at `07:21:53Z` with matching Call-ID, tags and `CSeq: 5701 BYE`, answers `200 OK`, and that response was captured on the wire returning through Kamailio to Asterisk carrying the browser's own `User-Agent` — with `0` BYE retransmissions, no transaction timeout, no Kamailio-generated error, and the channel terminating with no manual cleanup. **`PRODUCT_DEFECT-13` closed:** one bounded synthetic in-dialog BYE with a distinct synthetic Call-ID and no alias returns `400 Bad Request` with `result=missing_dialog_contact_alias`, and one with a structurally invalid alias derived from the observed `ip~port~proto` format returns `400` with `result=invalid_dialog_contact_alias` (`nathelper: no proto in alias param`) — both with no DNS query, no `t_relay()`, no fallback and zero datagrams toward Asterisk. Across the whole proof there was no `sip_hostport2su()` failure, no `uri2dst2()` failure, no `t_forward_nonack()` failure and no `478 Unresolvable destination`. REGISTER is unchanged with no `;alias=` in registrar storage, the `503` failure-route contract is intact, rtpengine stays uninvolved, both Kamailio Services remain ClusterIP-only, and state authority is preserved. **`PRODUCT_DEFECT-14`:** the `$du` postcondition is applied to **every** in-dialog request, but only requests travelling toward the browser carry an alias. A request travelling toward Asterisk has a normal routable Request-URI (`sip:10.42.1.224:5060`), so `loose_route()` correctly leaves `$du` empty, `handle_ruri_alias()` finds no alias, and the guard wrongly rejects it `400 Bad Request` with `missing_dialog_contact_alias`. The ACK is therefore dropped (`post_ack_200_retransmissions` `0` to `3`, and Asterisk finally tore the channel down on its ACK timer, the source of the `Reason: cause=408` on the BYE) and the client-originated BYE returns `400 Bad Request` instead of `200 OK` — regressing two corridors that were `PASS` at `081267a` and `b547a98`. All static and parser checks pass because nothing asserts the postcondition applies only to alias-bearing Request-URIs. The smallest bounded correction is to guard the alias block on the presence of an `alias` URI parameter, e.g. `if ($du == "" && $(ru{uri.param,alias}) != "")`, with `config-check` and `config-check-test` coverage for both the unconditional-postcondition and dropped-postcondition mutations, followed by a reproof of ACK continuity, client-originated BYE and one clean CLI-triggered Asterisk-originated BYE only. T3-S2A is In Progress, T3-S2 media mediation is Not Started, and T3 remains In Progress. T3-S2 media mediation is Not Started. T3 remains In Progress. **The T3-S2A WebSocket-bound BYE proof at `b547a98` did NOT close `PRODUCT_DEFECT-11`; two new exact defects, `PRODUCT_DEFECT-12` and `PRODUCT_DEFECT-13`, are open.** Exactly three resources were applied in dependency order — `allow-kamailio-signaling-required-traffic` (generation 5 to 6, only the previously reviewed cluster-DNS `podSelector k8s-app=kube-dns` hardening, with the DNS, PostgreSQL, rtpengine and Asterisk egress authorities all intact and no `ipBlock`), the corrected `kamailio-config` ConfigMap (sha256 `58e5c733...` to `3a38ad30...`) and the checksum-coupled `kamailio` Deployment (generation 14 to 15) — producing a fully automatic ~4-second rollout to ReplicaSet revision 15 with no manual restart and no unrelated workload rollout. The running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount and Pod checksum annotation, with `add_contact_alias()` correctly placed after authentication and before `record_route()`, `handle_ruri_alias()` after `loose_route()` and before relay, zero alias operations in REGISTER and zero rtpengine operations. A bounded packet capture taken in the Kamailio k3d node network namespace, filtered to the single proof Call-ID with Authorization redacted, proves the outcome directly. **`PRODUCT_DEFECT-12`:** the guard `if ($proto == "WS" || $proto == "WSS")` compares against uppercase literals while Kamailio's `$proto` renders lowercase (`ws`/`wss`), so `add_contact_alias()` is dead code. This is proven by elimination from runtime evidence — the INVITE forwarded to Asterisk carries `Contact: <sip:ts-...@utcp-s2a-proof.invalid;transport=ws>` with **no** `;alias=` parameter, yet `result=websocket_contact_alias_failed` never fired and the INVITE completed with `200 OK`, leaving branch-not-entered as the only possibility. **`PRODUCT_DEFECT-13`:** `handle_ruri_alias()` returns success when the Request-URI carries no alias at all, so the committed `invalid_dialog_contact_alias` guard cannot detect the miss, `$du` stays empty and `t_relay()` falls back to ordinary DNS on the `.invalid` host — `sip_hostport2su(): could not resolve hostname` then `uri2dst2()` then `t_forward_nonack()` then `kamailio_application_dialog_relay_failed route=within_dialog method=BYE`, with Kamailio returning `478 Unresolvable destination (478/TM)` to Asterisk. That is the exact DNS fallback the contract forbids, and it masked `PRODUCT_DEFECT-12` by replacing the intended explicit failure with a generic resolution error. What the proof did establish: Asterisk generated a correct in-dialog BYE (matching Call-ID and tags, incremented CSeq, both route-set hops with the internal Kamailio Service first), it reached Kamailio, `has_totag()` routed it to `route[WITHINDLG]` and `loose_route()` succeeded with no initial-domain rejection and no repeated authentication; the browser never received the BYE, so the client returned no `200 OK` and the Asterisk channel terminated locally only. Regression boundaries hold: REGISTER is unchanged with no `;alias=` in the stored contact, client ACK still lands (0 post-ACK retransmissions) and the client-originated BYE still returns `200 OK` through the changed `WITHINDLG`, the `503` failure-route contract is intact, rtpengine stays uninvolved, both Kamailio Services remain ClusterIP-only, and the full-cluster Pod diff contains only the expected Kamailio rollout. Alias creation is correctly gated behind authentication and alias consumption behind `has_totag()` plus `loose_route()`, but the committed "invalid alias fails explicitly, never DNS fallback" property is not currently satisfied; the malformed-alias security case is untestable until `PRODUCT_DEFECT-12` is corrected. The next bounded slice is comparing `$proto` against the lowercase tokens and requiring `$du != ""` after `handle_ruri_alias()`, with matching static and mutation coverage, followed by a reproof of the last hop only. T3-S2A is In Progress, T3-S2 media mediation is Not Started, and T3 remains In Progress.

[`docs/evidence/t3/t3-s2a-directional-dialog-alias-correction.md`](../evidence/t3/t3-s2a-directional-dialog-alias-correction.md) records `PRODUCT_DEFECT-14 = corrected in repository`: established-dialog alias handling is now direction-aware. Alias consumption is gated by the Request-URI `alias` parameter and still requires non-empty `$du` for browser-bound alias-bearing requests; WS/WSS or `.invalid` browser targets without an alias fail explicitly with `400 Bad Request` and `result=missing_dialog_contact_alias`; malformed aliases fail explicitly with `result=invalid_dialog_contact_alias`; and alias-free ACK/BYE requests toward a normal routable Asterisk Contact may relay using the Request-URI with `$du` empty. Static validation and mutation coverage now guard the complete bidirectional matrix while preserving the lowercase initial WS/WSS guard, single `add_contact_alias()`, Record-Route, Asterisk unavailable `503`, DNS-policy, REGISTER, rtpengine and public-surface contracts. No Kubernetes apply was performed. `PRODUCT_DEFECT-11` through `PRODUCT_DEFECT-14` are ready for consolidated live closure. T3-S2A is ready for final bidirectional dialog proof. T3-S2 media mediation is Not Started. T3 remains In Progress. **The T3-S2A consolidated bidirectional dialog closure proof at `134a1d1` closes `PRODUCT_DEFECT-11` through `PRODUCT_DEFECT-14`; all five rows of the committed directional matrix pass and T3-S2A is Complete.** Exactly two resources were applied — the corrected `kamailio-config` ConfigMap (sha256 `2b92c60b...` to `e064cd33...`, replacing the unconditional `$du` postcondition with directional alias-present / browser-style / routable-target branches) and the checksum-coupled `kamailio` Deployment (generation 16 to 17) — producing a fully automatic ~5-second rollout to ReplicaSet revision 17 with no manual restart, zero ERROR lines, and running configuration byte-identical across repository render, live ConfigMap, in-Pod mount and Pod checksum annotation. Two bounded packet captures in the Kamailio k3d node network namespace, each filtered to one proof Call-ID with Authorization redacted, provide the wire evidence. **Row 1, browser ACK to Asterisk:** Request-URI `sip:10.42.2.150:5060`, alias absent, alias handling not invoked, and `post_ack_200_retransmissions = 0` — the decisive signal, which was `3` under the unconditional postcondition at `1381bf3`. **Row 2, browser BYE to Asterisk:** alias absent, empty `$du` accepted, relayed on the routable Request-URI, `200 OK` returned with matching Call-ID, tags and CSeq, dialog terminated, no alias failure log. **Row 3, Asterisk BYE to browser:** exactly one alias on the forwarded Contact (`;alias=10.42.0.164~48170~5`, ip~port~proto with proto 5 = WSS); a **clean CLI-triggered** hangup produced the BYE in the same second with **no `Reason: cause=408`**, proving the stimulus rather than an ACK timeout generated it; the alias was detected and consumed, `$du` became non-empty, the existing WebSocket connection was reused, the browser received the BYE and answered `200 OK`, and that response was captured returning through Kamailio to Asterisk carrying the browser's own `User-Agent` with `0` retransmissions, the Asterisk channel and browser dialog both terminating cleanly. **Row 4, missing browser alias:** `400 Bad Request` with `missing_dialog_contact_alias`. **Row 5, malformed browser alias:** `400 Bad Request` with `invalid_dialog_contact_alias` (`nathelper: no proto in alias param`). Both negative rows used distinct synthetic Call-IDs, never a live dialog identifier, and produced no DNS query, no `t_relay()`, no browser delivery, no Asterisk branch and no fallback — zero datagrams were captured for the synthetic Call-IDs. Across all five rows there was no `sip_hostport2su()` failure, no `uri2dst2()` failure, no `t_forward_nonack()` failure and no `478 Unresolvable destination`. REGISTER is unchanged with no `;alias=` in registrar storage, the `503` failure-route contract is intact in the running configuration, rtpengine sessions and ports_used remained `0` with zero operations, both Kamailio Services remain ClusterIP-only, no durable dialog authority appeared, and the full-cluster Pod diff contains exactly one change — the expected Kamailio rollout — with Asterisk, rtpengine and every unrelated workload retaining their UID and restart count. One environmental note: a host restart before the apply shuffled the k3d container IPs and k3s refused to start (`failed to find interface with specified node ip`); this was resolved with the established `k3d cluster stop`/`start` tooling only, restoring the original IP assignment with no repository change, no alternate kubeconfig and no cluster deletion, after which the baseline was re-recorded. CANCEL remains a bounded fixture property rather than a defect, because the `9900` fixture answers immediately. T3-S2A live signaling proof is Complete, T3-S2A is Complete, T3-S2 media mediation is Not Started, and T3 remains In Progress.

[`docs/evidence/t3/t3-s2-provider-neutral-media-mediation-correction.md`](../evidence/t3/t3-s2-provider-neutral-media-mediation-correction.md) records the T3-S2 provider-neutral media mediation repository correction: Kamailio now owns SIP/SDP-driven media-control invocation through `MEDIA_OFFER`, `MEDIA_ANSWER`, and `MEDIA_DELETE`; rtpengine owns ephemeral RTP/RTCP/ICE relay state; and Asterisk remains only the current reference application runtime and SIP/SDP peer. Initial SDP INVITEs call `rtpengine_offer()` after authentication and WebSocket Contact alias preparation but before `record_route()` and runtime relay; application-runtime SDP replies call `rtpengine_answer()` from a named reply route before returning to the browser; in-dialog re-INVITE/UPDATE uses the same generic offer/answer path; BYE in either direction, CANCEL, cancellation, and terminal application-runtime failure delegate cleanup to the single media delete route. Static and mutation coverage reject missing or late offer/answer/delete, REGISTER media operations, unsupported-method media creation, direct-media fallback, `rtpengine_manage()`, provider-specific Asterisk channel/ARI/AMI/dialplan authority, embedded Pod/node/developer media IPs, duplicate delete authority, public-surface regressions, stale checksum coupling, and regressions in the established bidirectional alias and unavailable-runtime `503` contracts. No Kubernetes apply was performed. T3-S2A = Complete. T3-S2 provider-neutral media mediation = corrected in repository and awaiting live proof. Asterisk = current reference runtime. Runtime-agnostic parity = not yet proven; a bounded second-runtime parity gate such as FreeSWITCH remains required after T3-S2 live proof. T3 = In Progress. `UTCP_PHASE=T1`.

### V0 - Natural Login, SIP Registration, and Conference Admission — Planned

The first user-facing telephony milestone (see "First User-Facing Vertical Slice" above). Uses natural browser login, no preset sessions, no injected cookies, no manual database edits, no Artisan activation command, no hidden feature gate, no runtime-specific frontend workflow. V0 must prove the complete registered-browser-to-conference path introduced by T3: `TelephonySession` credential registration remains independent from execution-runtime eligibility; new application dialogs are routed only to eligible selected Conference runtimes; existing-dialog behavior follows the T3 route contract; cutoff and restoration are automatic; the browser/conference path does not bypass Kamailio by routing directly to Asterisk.

**V0-REF-DIALER-BOOTSTRAP (2026-08-08):** The first proposed bounded V0 packet is implemented and evidenced in [`docs/evidence/v0/reference-dialer-bootstrap.md`](../evidence/v0/reference-dialer-bootstrap.md). The authenticated `GET /api/v1/reference-dialer/bootstrap` read model reuses the existing identity, tenant, telephony-session, signaling-metadata, and conference authorities. It is read-only and does not claim SIP registration, conference admission, or full V0 completion.

**V0-REF-DIALER-SIP-REGISTER (2026-08-08):** The proposed second bounded packet is implemented in the authenticated `/dialer` route and documented in [`docs/evidence/v0/reference-dialer-sip-register.md`](../evidence/v0/reference-dialer-sip-register.md). The minimal UI consumes the bootstrap contract, reuses the existing telephony-session and one-time signaling-credential APIs, and drives `REGISTERED` from the SIP.js registrar event on the canonical WSS URI. Natural-login Playwright proof and runtime registration corroboration remain pending; conference admission and full V0 remain incomplete.

**V0 natural live proof (2026-08-09): SIP registration corridor PROVEN end to end; V0 blocked only on conference admission.** The natural-login proof pending above is now complete and closes the registration half of the V0 slice. A tenant-member persona logged in naturally, navigated to `/dialer` via the Primary navigation, and the real browser SIP stack opened `wss://sip.utcp.local.test/ws` (HTTPRoute `sip-wss` → `kamailio:8080`) and completed `REGISTER → 401 Digest → authenticated REGISTER → 200 OK`, `expires=120`, identity `ts-<telephony-session>`. Runtime corroboration came through the canonical `signaling_registration_observations` projection written by `kamailio-registration-observer`: `observed_state=registered`, `kamailio.registration.accepted`, matching tenant/session/identity/expiry, and the only registered row of 47. Stability was proven across a natural refresh cycle (`accepted → refreshed`, contact RUID unchanged, one identity row, no failure class), and exit converged to `expired` with zero registered rows. **The remaining blocker is that conference admission does not exist in the client under proof**: `apps/web/src/signaling/referenceDialerSignaling.ts` uses only sip.js `Registerer` with no `Inviter` or INVITE path, and `apps/web/src/views/ReferenceDialerView.vue` shows `bootstrap.conferences` as a count with zero action controls. The canonical admission authority already exists and is routed (`POST /api/v1/conferences/{conference}/participants/self` → `ConferenceController::joinSelf` → `TelephonyDomainService::admitSelf`), so the next V0 packet is bounded frontend work: a conference entry control wired to the existing seam plus a browser INVITE into the conference, followed by runtime conference-binding corroboration. Non-blocking observations: `tenant-admin` lacks `telephony.sessions.view_own` so administrators cannot reach the dialer, and the conference count includes closed conferences. Evidence: `docs/evidence/v0/v0-natural-login-sip-registration-and-conference-admission-live-proof.md`.

**V0-REF-DIALER-CONFERENCE-ADMISSION (2026-08-14):** The bounded conference-admission packet closing the blocker above is implemented and tested in `/dialer` and documented in [`docs/evidence/v0/reference-client-conference-admission-implementation.md`](../evidence/v0/reference-client-conference-admission-implementation.md).

**V0 narrow natural conference-admission live reproof (2026-08-15): admission corridor PROVEN; V0 blocked on reference-client call-lifecycle convergence.** Against canonical `utcp-local`, with the working tree deployed through the canonical lifecycle, the whole corridor passed naturally in one member session: `REGISTERED` → exactly one joinable conference shown (closed conferences withheld and independently refused 422 by the backend) → Join → `POST participants/self` 201 with `signaling_destination` `sip:9900@sip.utcp.local.test` → browser `INVITE` to exactly that Request-URI from the already registered `UserAgent` → 401 → authenticated INVITE → `200 OK` → ACK → `SessionState.Established` → Kamailio `media_offer`/`media_answer` → canonical ARI corroboration (`participant_channel_in_bridge`, `healthy_present`) with `conference.participant.ensure` succeeded and reconciliation converged → bidirectional browser RTP over the external media edge → "Connected" UI → natural Leave → `BYE`/`200 OK` + `DELETE participants/self` → participant `removed`/`left`, runtime channel `healthy_absent`, member still `REGISTERED`. **Blocker — PRODUCT_DEFECT-30:** a runtime-initiated BYE (RTP timeout, conference close, drain, operator removal) leaves a false "Connected" UI and an orphaned admitted participant, because `ReferenceDialerView.vue::updateCallState` only converges from `leaving` and the remote-termination path never calls the canonical leave. **PRODUCT_DEFECT-31:** a failed INVITE strands the view at "Joining…" with the participant already admitted. **OBSERVATION-1:** the one-time signaling credential has a 120 s TTL and is issued only on mount, so the dialer silently stops authenticating after ~2 minutes while still showing "REGISTERED". Architectural note: the reference `signaling_destination` is a constant echo-fixture extension on the SIP-facing Asterisk while the conference bridge lives on a different, ARI-only RuntimeNode, so the browser leg is not yet the conference leg. The next V0 packet is bounded frontend work in `ReferenceDialerView.vue` and `referenceDialerSignaling.ts`. Evidence: `docs/evidence/v0/v0-natural-conference-admission-live-reproof.md`.

**V0-REF-DIALER-CALL-LIFECYCLE (2026-08-15):** The bounded lifecycle-convergence packet (remote-termination convergence, failed-establishment compensation, automatic credential renewal) is implemented and unit-tested in `referenceDialerSignaling.ts` and `ReferenceDialerView.vue`.

**V0 narrow call-lifecycle natural live reproof (2026-08-15): failed-establishment handler PROVEN; V0 blocked on an unbounded credential-renewal loop.** **PRODUCT_DEFECT-32 (blocking):** `SignalingCredentialService::issueOwn()` returns a timezone-naive `expires_at` (`(string) $expiresAt` → `"2026-08-14 20:32:31"`); V8 `Date.parse` treats it as local time, so on a UTC+8 host `scheduleCredentialRenewal()` computes a zero delay and reschedules itself unchanged — **7 415 credentials, 5 436 REGISTERs and 3 406 audit records from one page mount at ~23/s**. Only the POST response is naive (the read endpoint returns `+00`), and the unit tests use their own fixtures plus fake timers, which is why this is live-only. The resulting natural INVITE failure **proved the DEFECT-31 fix**: participant canonically compensated, no false Connected, no indefinite "Joining…" — though **PRODUCT_DEFECT-33** leaves the Join control permanently `disabled` afterwards, so "retry possible" is unmet. **PROOF_GAP-1:** DEFECT-30 could not be exercised — the canonical `POST /telephony/sessions/{id}/end` released the participant but produced no BYE toward the browser's `9900` echo leg, so the call panel stayed "Connected" while registration truth correctly converged to failed; closing that path is RT-1, not V0. Cleanup idempotence passed (one logical release per attempt, 0 remove operations required). Next V0 packet: fix the credential `expires_at` serialisation (and add a client-side delay floor), then re-run only PROOF B–E. Evidence: `docs/evidence/v0/v0-call-lifecycle-natural-live-reproof.md`.

**V0-REF-DIALER-CREDENTIAL-RENEWAL (2026-08-15):** The bounded fix packet is implemented and unit-tested — explicit UTC ISO-8601 serialisation in `SignalingCredentialService::issueOwn()`, a guarded renewal scheduler that refuses a non-positive or non-finite delay and asserts the replacement expiry advances, a retryable `attention` state, and a typed-404 leave that converges.

**V0 narrow credential-renewal and recovery natural live reproof (2026-08-15): PASSED; PRODUCT_DEFECT-32 CLOSED.** With the browser still in Asia/Manila, the one-time credential response now carries explicit UTC ISO-8601 and parses correctly. Renewal fired at 21:32:24.018Z against a predicted 21:32:23.942Z (**76 ms deviation, 90.032 s after issue**) after 78 s of measured quiescence. Cadence over 271 s on one mounted page: **4 credentials (one per ~90 s, each expiry strictly advancing), 8 REGISTERs, 26 SIP frames, 4 audit records** — versus 3 406 / 5 436 / 13 620 in 149 s before. Crossing the original expiry without remount kept exactly one registered row, a stable contact RUID and a truthful REGISTERED UI, and **a Join 71 s after the original expiry reached `200 OK` → Established → Connected, eliminating the 120-second authentication cliff**. Leave regressed cleanly and cleanup stayed idempotent (one release, one succeeded remove operation, reconciliation converged). PRODUCT_DEFECT-33 and OBSERVATION-3 were not naturally exercised — no failure was manufactured — and remain covered by focused tests with the structural fixes confirmed statically. PROOF_GAP-1 stays unresolved by design. Next: **V0-A — Reference Client Lifecycle Invariant & Authority Audit**; V0 does not close before it. Evidence: `docs/evidence/v0/v0-credential-renewal-and-recovery-live-reproof.md`.

**V0-A — Reference Client Lifecycle Invariant & Authority Audit (2026-08-15): PASSED. V0 is COMPLETE.** Evidence-only and repository-first; no source, topology, runtime configuration, or TTL was touched and no live mutation was needed. Credential authority is an explicit contract (ADR-019: one active session → exactly one active short-lived credential); registration execution is solely Kamailio/`usrloc` with UTCP setting desired state only and the reconciler waiting on bounded contact expiry (≤120 s); admission revalidates authority under lock with the INVITE strictly downstream; compensation, attempt-id fencing and single-flight cleanup are implemented, test-proven (24/24) and live-observed. Credential TTL classified **IMPLICIT BUT SAFE CURRENT CONFIGURATION**; participant-release 404 normalization classified **BRITTLE BUT NON-BLOCKING**; neither blocks V0. **PROOF_GAP-1 closes as classification B without implementation** — ADR-017 and `authority-boundaries.md:54` both state that a `TelephonySession` "does not represent SIP registration, a media path, a call, microphone access, or a runtime channel", the schema holds no dialog object and the operation catalogue holds no dialog operation, so session end is contractually not a call-termination command; it converges everything it owns, and the reference client's surviving `9900` echo leg is reclaimed by Asterisk `rtp_timeout=30` or by the member's own Leave. No material contradiction remains. Next milestone: **RT-1**. Evidence: `docs/evidence/v0/v0-a-reference-client-lifecycle-invariant-and-authority-audit.md`. *(V0-A's lifecycle findings stand, but its V0 closure is superseded by V0-B below.)*

**V0-B — Browser Conference-Leg Authority & Correlation Audit (2026-08-15): `REQUIRES_ARCHITECTURE_CLARIFICATION`. V0 reopened.** The authoritative V0 contract is **ACTUAL BROWSER CONFERENCE LEG** — the initial implementation plan states the slice as one chain through "Asterisk conference and bridge execution → Media through rtpengine → Observed call legs and conference membership", and V0's own phase row requires "proof that the T3 internal application-dialog route uses canonical Conference RuntimeNode eligibility". **That is unmet: the browser SIP leg and the admitted participant leg share no identifier, channel, bridge, runtime node, or audio.** `signaling_destination` is the constant `sip:9900@{realm}`, a **T3-S2A media fixture** (`Answer(); Echo(); Hangup()`) — the committed base `from-kamailio` context rejects every destination, so no browser-reachable conference entry exists at all. Kamailio hardcodes one `$du` to `application-runtime-sip` and consumes zero control-plane state; the SIP-facing runtime is chosen by a static Kubernetes label, not by conference placement. The conference's bound RuntimeNode cannot receive SIP: `endpoint_purposes` has no `sip` value and RNP provisioning exposes only `ari` 8088. The admitted participant is a silent `Local/participant@utcp-conference-proof` proof channel with no linkage to the browser's PJSIP channel, so the member's microphone reaches an echo test. **The fixed 9900 route may remain as an independent connectivity test but must not be returned by canonical conference admission**, with no gate or fallback preserving the conflict. Four authorities are unresolved (conference signaling address; RuntimeNode SIP identity; the Kamailio route authority ADR-020 already defers to V0; inbound-channel→participant correlation), so the next step is an ADR amendment, not implementation. Evidence: `docs/evidence/v0/v0-b-browser-conference-leg-authority-and-correlation-audit.md`.

**V0-C — Conference SIP Routing Architecture Contract (2026-08-15): `COMPLETED`.** Documentation-only. The accepted topology — Browser → Kamailio → the conference's canonically bound RuntimeNode → inbound PJSIP channel → admitted participant → conference bridge — is recorded as **ADR-022**, with amendments appended to ADR-017/019/020 and a conference-signaling section added to `docs/architecture/authority-boundaries.md`. Admission returns `sip:conf-<participantId>@<realm>` from the existing `admitParticipant` (following ADR-019's accepted `ts-<sessionId>` user-part convention, so **no token authority is needed**); RuntimeNodes gain a `sip` endpoint purpose plus `udp` transport in the canonical endpoint registry, provisioned automatically by RNP as ClusterIP-only and reachable only from Kamailio; Kamailio consumes a sanitized `kamailio_conference_route_view` through its own least-privilege role via `sqlops.so`, so **no generation or fencing machinery is required — a view cannot go stale** — and a miss fails closed with no fallback to the static `selected-application-runtime`. The `9900` fixture and the static selection label are retained for T3 connectivity proof only; `Local/participant@utcp-conference-proof` remains for synthetic participants only. One nullable `conference_participants.runtime_channel_id` is the sole schema addition. Six bounded Codex packets V0-C1..V0-C6 are specified with exact files, dependencies, and acceptance criteria. Next: **V0-C1**. Evidence: `docs/evidence/v0/v0-c-conference-sip-routing-architecture-contract.md`.

**V0-C5 closure and V0-C6 live proof (2026-08-15): `FOUND_BLOCKER`.** C1–C4 were deployed through canonical targets and verified by content; migrations landed `conference_participants.runtime_channel_id` and `kamailio_conference_route_view` with SELECT granted only to `utcp_kamailio_auth_reader`. **V0-C5 COMPLETE** — on the deployed config `conf-*` dispatches exclusively to `CONFERENCE_RUNTIME_RELAY` with 0 references to the static application runtime, and `APPLICATION_RUNTIME_RELAY` has 0 references to the projection. **V0-C1/C2/C3 LIVE-PROVEN** — a managed node provisioned through `POST /api/v1/admin/runtime-provisioning` came up active/ready in ~25 s with the canonical `sip/udp/5060` endpoint and a ClusterIP-only Service; `participants/self` returned `sip:conf-<participantId>@<realm>`; and the authenticated INVITE was relayed to the conference's bound node (proven transitively — no `CONFERENCE_RUNTIME_RELAY` failure branch fired, and only the managed node saw the dialog). **BLOCKER — V0-C4**: Asterisk replied `403 Forbidden` with no channel and no Stasis entry, because `dialplan show conf-<uuid>@from-kamailio` resolves to the catch-all `_.` → `Hangup(21)`. The committed pattern `_conf-.` is evaluated as `_CONF-.` where `N` is the digit class `[2-9]`, so it can never match literal `conf…`. Bounded fix in `infrastructure/docker/asterisk/config/extensions.conf:14`; acceptance test is that `dialplan show conf-<uuid>@from-kamailio` resolves to the conference extension. Next: **BOUNDED V0-C4 DIALPLAN FIX**, then re-run V0-C6 only. Evidence: `docs/evidence/v0/v0-c5-c6-canonical-browser-conference-leg-live-proof.md`.

**V0-C6 narrow Asterisk-entry-onward reproof (2026-08-15): `FOUND_BLOCKER`; the dialplan fix is LIVE-PROVEN.** With `_[c]o[n]f-.` deployed to the bound RuntimeNode, the running Asterisk resolver returns the conference rule for `conf-<uuid>@from-kamailio` (9900 still resolves to the T3 Echo fixture, so **V0-C5 remains COMPLETE**), the authenticated INVITE reached `200 OK` from the bound node, and the **real inbound `PJSIP/anonymous-00000000` channel entered `Stasis(utcp-t0-observation,conf-<participantId>)`** with no synthetic Local channel; the UI reached Connected. **BLOCKER**: `runtime_channel_id` stayed null and the conference bridge kept 0 members, so the browser channel never became the participant leg; Asterisk reclaimed the unbridged channel at 30 s and the client converged cleanly. Isolated by elimination to `AsteriskConferenceParticipantBinder::bind()`'s guard — the StasisStart receipt, the listener dispatch, the deployed binder/ARI client, the absence of any exception log, and a read-only reproduction of the full join predicate are all positive, leaving only `admissionReference($event['args'])` returning null. Next: **BOUNDED V0-C4 BINDER FIX** (`AsteriskConferenceParticipantBinder::admissionReference()` / the `$event` shape from `AsteriskAriEventListener::ingestAriEvent()`), then re-run V0-C6 only. Evidence: `docs/evidence/v0/v0-c6-conference-leg-reproof-asterisk-entry-onward.md`.

**V0-C6 binder-onward reproof (2026-08-15): `FOUND_BLOCKER`; the binder fix is LIVE-PROVEN and the decisive invariant HOLDS.** With the binder/listener packet deployed and content-verified in the running pods, a natural Join produced a real inbound `PJSIP/anonymous-00000001` channel in `Stasis(utcp-t0-observation,conf-<participantId>)` on the bound node, no synthetic Local channel, UI Connected, and — decisively — **browser channel `1786760286.1` == `conference_participants.runtime_channel_id` == member of `utcp-conf-68c7d252-…` (bridge Chans 1)**. **BLOCKER — media**: the browser sent 446 packets and received 2; Asterisk got no audio and reclaimed the bridged channel at 30 s. Repository defect (not staleness): `infrastructure/kubernetes/security/platform/allow-rtpengine-media.yaml` hardcodes `utcp.dev/runtime-node: local-asterisk-ari` in both the ingress (line 35) and egress (line 75) podSelectors, so rtpengine can exchange RTP only with the base node and never with a managed RuntimeNode — the same pin V0-C4 removed from the sibling signaling policy. Fix both selectors, then `make security-apply` (NetworkPolicies are not applied by `make k8s-apply`). Also recorded: `runtime_channel_id` is **cleared** on StasisEnd by contract; cleanup stayed bounded; bridge-recreation churn is DEFERRED / NON-BLOCKING. Next: **BOUNDED V0 FIX — rtpengine media policy RuntimeNode pin**, then re-run V0-C6 only. Evidence: `docs/evidence/v0/v0-c6-binder-onward-conference-leg-reproof.md`.

**V0-C6 final conference media and natural Leave live proof (2026-08-15): `PASSED`. V0 is COMPLETE.** The corrected media policy was applied through canonical `make security-apply` (NetworkPolicies applied; the run then exits on `missing required K2 tool: helm` at the Gateway stage — ENVIRONMENT, non-blocking, verified live). `security-apply` re-applies the K1 base and reverts the external media edge, so `make media-edge-apply` must follow it — after that the corrected policy and `browser/POD!127.0.0.1` were both verified simultaneously. The full chain then passed: REGISTERED → Join → `sip:conf-<participantId>@realm` → 200 OK from the bound node → **browser PJSIP channel `1786762054.4` == `runtime_channel_id` == member of `utcp-conf-68c7d252-…`** → **Asterisk received 6 972 RTP packets on that bridged channel**, no `rtp_check_timeout` across a **148-second** call → participant observed **`joined`** → UI Connected → natural Leave → BYE → channel terminated, participant `removed`/`left`, bridge emptied, `runtime_channel_id` cleared, UI Ready, cleanup idempotent. Browser inbound RTP 0 is expected for a single-participant mixing bridge. C5 preserved (`9900` → T3 Echo; `conf-*` → canonical projection; no static fallback). Bridge-recreation churn remains DEFERRED / NON-BLOCKING. Next milestone: **RT-1**. Evidence: `docs/evidence/v0/v0-c6-conference-media-and-leave-live-proof.md`.

**RT-1A — RuntimeNode realtime natural browser live proof (2026-08-15): `PASSED`. RT-1A LIVE PROVEN / COMPLETE.** Both decisive properties hold live. **Notification → refetch**: with the mutation driven from a separate page so the observing page acted only on the notification, a `runtime_node.desired_state_changed` event for the exact node arrived on `private-tenant.{tenantId}.runtime-nodes` and the browser refetched the canonical API **1 ms later**, then rendered the API result; the outbox row (aggregate_id == mutated node) dispatched automatically at attempt 1 with no manual flush, and the payload carries only invalidation metadata — no RuntimeNode aggregate, no sensitive data. **Reconnect → canonical resync**: scaling Reverb to 0 left the API available and a canonical mutation still succeeded (proving Reverb does not gate mutation) while the browser received **0** notifications and went provably stale; on restore the client resubscribed and immediately refetched, catching up to canonical state — *missed notification ≠ permanent stale UI*. Refresh recovery and a bounded 403 tenant-channel authorization probe also passed; the outage-time outbox row reached `dispatched` rather than retrying, so the pending-retry branch remains automated-test covered. V0 unchanged. Next: **RESILIENCE / HARDENING — browser + telephony recovery contract**; RT-1 resource coverage is deliberately not expanded yet. Evidence: `docs/evidence/rt1/rt-1a-runtime-node-realtime-natural-browser-live-proof.md`.

**RH-0 — Browser + Telephony Recovery / Conference Auto-Rejoin Contract (2026-08-15): `COMPLETED`.** Architecture/evidence only. The recovery model is mostly already present: `desired_state ∈ {admitted, removed}` is durable participation intent while `runtime_channel_id`/`observed_state` are disposable observation; only `removeParticipant` and `removeParticipantsForSession` ever set `removed`, so explicit Leave is the single intent cutoff; `Binder::clear()` nulls only the channel and is channel-scoped (late StasisEnd cannot damage a replacement leg); `bind()` is first-writer-wins; the reconciler already parks self-admission participants at `conference_participant_awaiting_inbound_signaling_leg`; and `admitParticipant`'s reuse branch already returns the same participant and destination — the auto-rejoin primitive. **Gaps**: the client destroys intent on unexpected loss and on unmount (only Leave may release); no loss timestamp to bound grace; no recovery discovery on bootstrap; no grace expiration owner. **Contract**: 120 s grace derived from the committed `contact_max_expires_seconds`/`credential_lifetime_seconds` as a plain domain constant (no env gate), expired by a sweep beside the existing `expire-sessions` schedule; discovery via one bounded bootstrap field; the replacement leg reuses the exact V0 `conf-*` corridor so binding changes are followed automatically; **one nullable `conference_participants.runtime_channel_lost_at`** is the only schema change. Reverb is not required for correctness. Slices: **RH-1** recoverable participation + grace + expiration, **RH-2** browser recovery + replacement leg (live proof), **RH-3** slow-network/adversarial hardening. Next: **RH-1**. Evidence: `docs/evidence/rh/rh-0-browser-telephony-recovery-contract.md`.

**V0-C4 dialplan pattern fix (2026-08-15): IMPLEMENTED AND TESTED in the repository; narrow V0-C6 reproof pending.** The invalid `_conf-.` extension was replaced with `_[c]o[n]f-.`, which Asterisk resolves as the literal `conf-` namespace followed by the participant destination suffix. A real resolver check proves canonical UUID destinations enter `utcp-t0-observation`, `9900` retains the T3 Echo route, and unrelated destinations remain on the `_.` rejection path. No C1/C2/C3 authority or runtime routing was changed. Evidence: `docs/evidence/v0/v0-c4-asterisk-conference-entry-pattern-fix.md`.

**V0-C4 participant binder admission-reference recovery (2026-08-15): IMPLEMENTED AND TESTED; narrow V0-C6 reproof pending.** The raw ARI `StasisStart` argument is now preserved through the sanitized WebSocket event, normalized once by `AsteriskAriEventListener`, and consumed by the binder as a canonical `channel_id`/`application_args` input. Focused tests prove that `conf-<participantId>` binds the exact inbound channel, persists `runtime_channel_id`, and invokes the existing bridge attachment with that same channel; repeated delivery is idempotent and malformed, unknown, or conflicting references fail closed. No C1/C2/C3/C5 authority changed. Evidence: `docs/evidence/v0/v0-c4-participant-binder-admission-reference-recovery.md`. Next: **NARROW V0-C6 BINDER-ONWARD NATURAL LIVE REPROOF** only.

**V0-C6 RTPengine managed RuntimeNode media NetworkPolicy (2026-08-15): IMPLEMENTED AND TESTED; narrow media-and-Leave natural reproof pending.** The browser conference-leg proof isolated the remaining media failure to both Asterisk peers in `allow-rtpengine-media` being pinned to `utcp.dev/runtime-node: local-asterisk-ari`. The policy now selects the canonical Asterisk component/network-role labels, so historical base and managed RuntimeNodes qualify without broadening namespace, port, or public exposure boundaries. Focused security/media checks reject the old static pin, missing canonical labels, widened peers, and changed media ranges. No browser/live proof, live policy apply, application source, timeout, bridge, signaling, Kamailio, or RT-1 change was made. Evidence: `docs/evidence/v0/v0-c6-rtpengine-managed-runtime-media-policy-fix.md`. V0 remains in progress and RT-1 remains Planned/not implemented.

### RT-1 — Realtime Control-Plane UI Notifications — In Progress

RT-1 is placed immediately after V0 and before the next substantial adapter
milestone. It is a cross-cutting Admin UI milestone under the existing UI-D
track and not a replacement for UI-D. **V0 closed on 2026-08-15 with the V0-C6 final conference media and
natural Leave live proof, so RT-1 is now the next control-plane milestone.** V0-A resolved PROOF_GAP-1 as an
explicit architectural boundary rather than a defect: ending a telephony session
is contractually not a call-termination command, and everything session end owns
converges through identified authorities. What remains is UI freshness — an open
reference-dialer tab cannot learn that control-plane state changed — which is
exactly RT-1's scope. RT-1 must remain a notification path over canonical
committed state (outbox → Reverb → browser notification → canonical API
refetch); it must not become the telephony-session or SIP-dialog termination
authority.

Laravel Reverb is a transient realtime notification/invalidation transport,
never state authority. PostgreSQL, existing UTCP domain/application services,
and existing projection and reconciliation authorities remain canonical.

The required flow is canonical transaction commit, existing domain event or
durable outbox seam where required, Laravel Reverb, authenticated
tenant-scoped browser notification, and canonical API refetch. The command
boundary is REST/application API for commands and canonical reads; Reverb is
asynchronous change notification.

Create, drain, retire, deprovision, and future lifecycle commands continue to
work when Reverb is unavailable. API reads and backend processing continue;
only immediate browser updates degrade. Automatic reconnect is followed by
canonical API resynchronization because delivery is transient and missed
events are not replay authority. No manual reconciliation or projection
command is introduced.

The first implementation is limited to Runtime Nodes and runtime management:

- RuntimeNode lifecycle and state changes.
- Runtime operation status and progress.
- Readiness and observed-state projection changes.
- Drain progression.
- Retirement.
- Managed deprovision progress and completion.

The implementation sequence is: confirm the authenticated internal Reverb
transport contract and no-command-dependency rule; produce post-commit
RuntimeNode notifications through the existing event/outbox relationship;
subscribe from Vue after normal login and tenant resolution and refetch
canonical RuntimeNode APIs; add bounded reconnect/resync; then prove the
natural Admin browser flow with real login, an actual lifecycle change, no
page refresh, and outage/reconnect resynchronization.

Channels are authenticated and tenant-scoped through the normal application
session, tenant membership, and existing capability authorization. RuntimeNode
access uses the existing capability architecture, such as runtime.nodes.view;
Reverb introduces no separate permission system or special role names. A
browser must never receive another tenant's events. The exact channel string
remains implementation work.

Notifications are minimal change signals, conceptually an aggregate identifier
and version, not RuntimeNode state. The browser refetches sanitized canonical
API resources. Credentials, Kubernetes Secret data, kubeconfig, tokens,
lease/fencing secrets, raw stack traces, and unnecessary Kubernetes details
must not be broadcast. Coalescing/debouncing should avoid an event storm.

RT-1 preserves the authorities of RuntimeProvisioningService,
RuntimeRegistryService, RNM, the runtime operation framework,
ProjectionService/runtime observation, and the
telephony-infrastructure-worker. Reverb observes their committed changes and
does not replace provisioning, registry, lifecycle, operation, observed-state,
or Kubernetes mutation authority. The Runtime Nodes UI remains a projection of
canonical API state and does not gain a second frontend lifecycle state machine.

RT-1 acceptance requires tenant isolation, REST/API command and read authority,
notification-only payloads with no secrets, automatic list/detail updates for
provisioning/readiness/drain/retirement/deprovision, commands and backend
processing surviving Reverb outage, automatic reconnect followed by canonical
resync, no manual reconciliation/projection path, and natural browser proof.
RT-1 is In Progress. RT-1A is implemented and repository-tested; its natural
browser proof remains pending.

Conference, participant, trunk, and session event families may later reuse the
same transport and refetch pattern. Illustrative event names do not establish
concrete event classes or schemas here. The reserved events.utcp.local.test
hostname remains behind the established edge policy: Reverb is
internal/ClusterIP behind Gateway/Traefik, with no NodePort, LoadBalancer, or
direct-host exposure.

#### RT-1A — RuntimeNode Realtime Control-Plane UI Notifications — Implemented / Tested

RT-1A completes the first RuntimeNode vertical slice using the existing
`control_plane_outbox_messages` transactional outbox, dispatcher, Reverb event,
tenant channel, and RuntimeNode Vue subscription. Operator mutations already
append RuntimeNode outbox intent through `RuntimeRegistryService`; this slice
also appends notification intent when canonical observed-state projection and
stale-state derivation change RuntimeNode state. These rows are committed or
rolled back with the canonical database transaction and remain retryable when
transport delivery fails.

The browser receives bounded RuntimeNode invalidation metadata only and
refetches the canonical RuntimeNode API. Same-turn duplicate notifications are
coalesced, and reconnect performs canonical list/detail resynchronization.
Channel authorization remains session-, tenant-, and `runtime.nodes.view`-
based. No Reverb command path, feature gate, per-node allowlist, or V0
telephony authority was introduced. Next: narrow natural Admin browser proof
with real login, a canonical RuntimeNode mutation, notification/refetch, and
reconnect recovery. Evidence:
`docs/evidence/rt-1/rt-1a-runtime-node-realtime-notification-vertical-slice.md`.

### T4 - FreeSWITCH Call-Control Adapter Parity — Planned

Objective: implement and prove the normalized C6 call-control adapter contract
through FreeSWITCH ESL. T4 consumes C6 and does not invent alternate call
semantics. The critical proof is that the same normalized C6 operation contract
executes through the Asterisk and FreeSWITCH adapters, with adapter-specific
behavior hidden behind the normalized contract. Existing FreeSWITCH SIP/media
parity remains valid historical evidence; no ESL call-control implementation is
claimed before T4 begins. Registration, bridge, conference, observation, and
unsupported-capability behavior are proven only where defined by C6.

### T5 - Multi-Runtime Convergence, Failover, and Recovery — Complete

Objective: harden runtime behavior after both runtime adapters work. Implement event-stream reconnection/replay, stale-registration expiry, orphan-channel cleanup, conference-membership reconciliation, runtime-node draining/unavailability handling, eligible-node reselection, replay-safe operations, failed-operation recovery, cross-runtime recovery where technically supported. Do not promise seamless active-call migration unless signaling/runtime/media behavior actually prove it. Completion criteria: control-plane state recovers after runtime interruption; duplicate operations remain safe; stale runtime resources are detected and reconciled; runtime-node failure behavior is explicit and observable.

**Status: Complete.** Closure record: [`docs/evidence/t5/t5-phase-closure.md`](../evidence/t5/t5-phase-closure.md) (`T5_COMPLETE`), which reconciles all 21 canonical T5 criteria to `SATISFIED` against the existing evidence corpus without rerunning any proof. T5 evidence proves the multi-RuntimeNode Asterisk topology, deterministic failover and recovery, listener ownership/liveness/degradation/automatic recovery, symmetric degraded/recovered evidence, deterministic capacity-aware placement, pending-no-capacity and automatic retry, recovery metric-event retention with scheduled pruning, and repository Namespace PSA authority reconciliation. The current Kamailio signaling-cutoff item was re-sequenced out of active T5 because T1 Kamailio is registration-only and has no runtime dialog route to cut off. The controlled live Namespace PSA proof is **complete**: `f959f00` recorded the T5-A78 corridor (declarative apply of the canonical `pod-security-labels.yaml`, all five UTCP namespaces at `restricted`/`v1.35` including the `utcp-runtime` version pins, compliant-Pod admission, `restricted:v1.35`-attributed privileged-Pod rejection, migration-Job admissibility, drift introduction and declarative correction, idempotent reapply, and full workload health) against `utcp-local`, satisfying every point of this document's own deferred live acceptance contract. The final phase-closure evidence — the last outstanding T5 item — is now recorded, so **no T5 implementation, live runtime proof, or documentation work remains**. The primary corridor corpus (`T5-A1` through `T5-A78`) remains at its historical path `docs/evidence/t2/multi-node-failover-readiness.md`; the closure document above is the canonical phase-level T5 index and explains that filing anomaly. See also [`docs/evidence/roadmap/t1-t5-roadmap-reconciliation.md`](../evidence/roadmap/t1-t5-roadmap-reconciliation.md).

### C6 - Canonical Call Lifecycle and Normalized Call-Control Domain — C6E1 repository-tested; narrow live proof pending

**C6A status: COMPLETE — canonical model and operation authority implemented.**
Wave 1 persistence is exactly `calls` and `call_legs`; call operations reuse
`runtime_operations`, and future call observations reuse `runtime_observations`.
C6A declares inbound `OFFERED`, DTMF/media operation seams, and opaque C7 route
seams without runtime execution. `CallParticipant`, durable `Bridge`, and
`CallTimelineEntry` are not tables. Existing conference participants remain the
Option-B authority; conference integration is a future bounded cutover. Evidence:
[`docs/evidence/c6/c6a-canonical-call-model-and-operation-authority-implementation.md`](../evidence/c6/c6a-canonical-call-model-and-operation-authority-implementation.md).

**C6B status: COMPLETE — handlers, capability gating, and simulator execution implemented and tested.**
The catalog remains the sole C6 operation vocabulary authority. The existing
`CommandWorker` capability gate dispatches all 19 catalog operations through
catalog-derived handlers to the existing simulator `RuntimeAdapter`; missing
capabilities and invalid normalized payloads fail visibly before adapter
execution. Simulator acceptance records execution evidence in existing
simulator state only: C6B creates no observations and does not advance
observation-confirmed Call or CallLeg state. Idempotency remains owned by
`runtime_operations`; conference handlers and Option-B conference authority
are unchanged. Evidence:
[`docs/evidence/c6/c6b-handlers-capability-gating-and-simulator-execution.md`](../evidence/c6/c6b-handlers-capability-gating-and-simulator-execution.md).

**C6C status: COMPLETE — normalized observation ingress, inbound adoption, and stale/duplicate fencing implemented and tested.**
The existing `runtime_observations` append-only kernel now routes normalized C6
facts through `CallObservationProcessor` and `CallDomainService`. Offered
observations allocate or reuse one tenant-owned inbound Call/CallLeg without
C7, exact runtime-channel identity and the partial unique fence protect
adoption, and closed-epoch, duplicate, out-of-order, terminal, bridge, and
conference-owned-channel cases are deterministic. DTMF received remains an
observation with no business interpretation. The simulator uses the standard
receipt/normalizer/projection path and does not mutate canonical state as a
command-side shortcut. Evidence:
[`docs/evidence/c6/c6c-observation-ingress-inbound-adoption-and-fencing.md`](../evidence/c6/c6c-observation-ingress-inbound-adoption-and-fencing.md).

**C6D status: COMPLETE — public API, authorization, and derived Call timeline implemented and tested.**

**C6D status: COMPLETE — public API, authorization, and derived Call timeline implemented and tested.** The seven normalized routes are tenant-scoped and use explicit provider-neutral Call, CallLeg, RuntimeOperation, and timeline resources. Operation submission delegates to the existing CallDomainService and `runtime_operations` idempotency authority; authorization is checked before operation creation, while runtime capability remains the worker-time gate. The timeline is a bounded read-only projection over runtime operations, normalized observations, and existing audit records, with deterministic ordering and no `call_timeline_entries` table. Inbound Calls adopted by C6C are readable and controllable without C7. No frontend, adapter, C7, conference, RH, or schema changes were introduced. Evidence: [`docs/evidence/c6/c6d-public-api-authorization-and-call-timeline.md`](../evidence/c6/c6d-public-api-authorization-and-call-timeline.md). **Next: C6E — Asterisk generic call execution and bounded live proof.**

**C6E1 status: IMPLEMENTED / TESTED; PostgreSQL deployment blocker fixed; natural Asterisk proof pending.** The
existing Asterisk `RuntimeAdapter.execute()` path now executes the normalized
19-operation C6 catalog with honest capability declarations, exact current
CallLeg runtime-channel fencing, normalized provider-local ARI request mapping,
and existing RuntimeOperation failure semantics. Generic ARI facts are
translated into C6 observations and continue through `runtime_observations`,
`ProjectionService`, `CallObservationProcessor`, and `CallDomainService`; ARI
command success does not fabricate observation-confirmed Call/CallLeg state.
Inbound generic `StasisStart` facts use C6C adoption, while conference-owned
channels remain under conference authority. No new tables, API, frontend,
FreeSWITCH, C7, RH, or conference changes were introduced. Evidence:
[`docs/evidence/c6/c6e-asterisk-generic-call-execution-implementation.md`](../evidence/c6/c6e-asterisk-generic-call-execution-implementation.md).
**C6 PostgreSQL deployment blocker correction (2026-08-16): IMPLEMENTED / TESTED.**
The C6 call-leg self-referencing bridge FK is added after the `call_legs` table
and primary key exist, preserving `ON DELETE SET NULL` and the partial
runtime-channel uniqueness fence. The existing PostgreSQL migration proof now
checks the C6 tables, constraints, rollback/reapply, and normal six-capability
identity synchronization. The narrow outbound Asterisk review also corrected
deterministic originate-channel correlation: the first matching `stasis_start`
binds the existing outbound CallLeg through normalized observation processing
instead of entering inbound adoption. No frontend, conference/RH, C7, or
FreeSWITCH work was introduced. Evidence:
[`docs/evidence/c6/c6-postgresql-migration-blocker-correction.md`](../evidence/c6/c6-postgresql-migration-blocker-correction.md).
**Next: C6E narrow natural Asterisk generic-call proof; T4 remains deferred.**

**C6 reference Call UI (2026-08-16): IMPLEMENTED / TESTED; natural frontend proof pending.** The authenticated `/calls` route is a deliberately small reference consumer of the C6D API. It reads tenant-scoped Calls, CallLegs, operations, and derived timeline entries; creates only the bounded pre-C7 outbound Call intent; displays C6C-adopted inbound `OFFERED` Calls; and submits representative normalized operations through the existing operation resource. Command status is displayed separately from observation-confirmed lifecycle state, with no optimistic telephony mutation and no direct provider call. Evidence: [`docs/evidence/c6/c6-reference-call-ui-implementation.md`](../evidence/c6/c6-reference-call-ui-implementation.md). No backend, schema, adapter, conference, RH, C7, or FreeSWITCH changes were introduced. **Next: C6E narrow natural frontend Asterisk proof; T4 remains deferred.**

Objective: establish the canonical multi-leg call model and capability-aware
call-control contract used by applications, conferences, and real runtime
adapters. Core concepts remain `Call`, `CallLeg`, `CallParticipant`,
`CallOperation`, `CallObservation`, `CallRouteDecision`, `CallTermination`,
and `CallTimelineEntry`. The lifecycle supports outbound origination and an
inbound entry state for calls first observed/adopted from an external/runtime
source; inbound calls are not forced through `REQUESTED -> SELECTING_ROUTE ->
ORIGINATING`. Add normalized DTMF-received observation on a `CallLeg`, plus
`playMedia` and `stopMedia` primitives. Existing controls including
`sendDtmf`, recording control, transfer, bridge, hold, resume, and hangup remain
capability-aware; recording storage/retention is outside this phase. The
simulator must exercise the contract, and no Asterisk or FreeSWITCH state name
is prescribed.

## Future External-Trunk, Route, and Caller-Identity Roadmap

### C7A - External Connectivity, Telephony Addressing, and Caller Identity — Planned

Objective: create canonical authority for `ExternalTrunk`, `TrunkEndpoint`,
`TrunkCredentialReference`, `TelephonyAddress`, `CallerIdentity`, and
`CallerIdentityPolicy`, including tenant ownership, lifecycle/readiness,
credential references, eligibility, audit history, draining, and retirement.
`TelephonyAddress` represents E.164/DID, SIP URI, or supported logical
extension classes without making DID purchasing, porting, commercial inventory,
billing, or settlement a UTCP responsibility. Existing lifecycle and observed
health distinctions, web-admin authority, secret-reference handling, and
tenant isolation remain. C7A does not own route or destination resolution;
those belong to C7B.

### C7B - Inbound/Outbound Route and Destination Authority — Planned

Objective: create canonical authority for `InboundRoute`, `OutboundRoute`,
`RouteConstraint`, `RouteDecision`, and `DestinationRef`. A DestinationRef is
runtime-neutral and extensible across telephony identity, conference,
application endpoint, external destination, and a reserved future queue class;
it contains no Asterisk, FreeSWITCH, or Kamailio runtime-specific field. The
canonical inbound resolution is `ExternalTrunk -> called TelephonyAddress ->
InboundRoute -> RouteDecision -> DestinationRef -> Call/CallLeg`; runtime
adapters and projectors execute the selected result. Outbound selection remains
`OriginateCall -> outbound route -> caller identity policy -> external trunk ->
runtime -> Call/CallLeg`, and is not campaign behavior. Web-admin management,
tenant isolation, deterministic decisions, and C3 projection/reconciliation
remain the completion boundaries.

### T6 - External Trunk Integration and Live Route Projection — Planned (extended scope; renumbered from the initial plan's conflicting `T3` — see Phase-Identifier Reconciliation)

Objective: connect C7A/C7B canonical resources to real signaling and execution
projections, including `ExternalTrunk`, `TelephonyAddress` where network or
runtime projection requires it, `InboundRoute`, `OutboundRoute`,
`CallerIdentity`, and `DestinationRef` resolution. Use Kamailio and the
selected runtime adapter where required; canonical C7 APIs remain unchanged by
runtime choice. Use deterministic synthetic external SIP peers (SIPp or a
second isolated SIP runtime) for baseline proof; no commercial carrier is
required. Prove registration-based and IP-authenticated trunk projection,
route/caller-identity projection, readiness and health observations,
credential-reference rotation, draining/disabling/retirement, one synthetic
inbound call, and one synthetic outbound call. Kamailio executes the projected
route but never owns tenant, trunk, address, caller-identity, route, or
runtime-eligibility authority. No manual runtime-file edit is required for the
normal lifecycle.

### V1 - Bidirectional External Call Routing and Control — Planned

The second vertical slice (see "Second Vertical Slice" above) proves both the
outbound and inbound corridors. Exit criteria include runtime-neutral route,
caller-identity, trunk, address, destination, call-leg, normalized control,
and audit decisions; one supported mid-call control; one explicit unsupported
capability result; deterministic degraded/draining selection; and a complete
timeline visible through API and web UI. Requests do not name Asterisk, PJSIP,
ARI, Kamailio dispatcher IDs, or trunk configuration sections.

### A0 - Reference Consumers — Planned

Objective: prove that applications can build on UTCP call, route, trunk,
address, destination, registration, and conference contracts without owning
infrastructure or vendor integrations. The three consumers are intentionally
small: an outbound consumer that is not a campaign dialer; an inbound consumer
that is not a PBX UI; and a minimal IVR consumer that exercises
`TelephonyAddress -> DestinationRef(application) -> accept/control ->
playMedia -> DTMF observation -> application-selected DestinationRef ->
transfer/redirect`. The IVR consumer must not become a menu editor or workflow
product. Applications own campaign membership, contact selection, request
timing, simple retry policy, disposition, and workflow meaning. UTCP owns
authorized identity, route/trunk/runtime selection, normalized execution,
capability handling, observations, and audit history.

**T3-S2A repository correction at `df79e8f` closes `PRODUCT_DEFECT-12` and `PRODUCT_DEFECT-13` in the repository and is recorded in [`docs/evidence/t3/t3-s2a-websocket-alias-runtime-guard-correction.md`](../evidence/t3/t3-s2a-websocket-alias-runtime-guard-correction.md).** `PRODUCT_DEFECT-12` is corrected by replacing the dead uppercase WebSocket alias guard with the lowercase live `$proto` values `ws` and `wss` only, keeping `add_contact_alias()` after subscriber authentication and before `record_route()`/Asterisk relay. `PRODUCT_DEFECT-13` is corrected by requiring `$du` to be non-empty after `handle_ruri_alias()` in `route[WITHINDLG]`; explicit `400 Bad Request` failures now distinguish `invalid_dialog_contact_alias` from `missing_dialog_contact_alias`, and neither case can fall through to `.invalid` DNS resolution. Static validation and mutation coverage now reject uppercase or mixed-case compatibility guards, missing WS/WSS coverage, UDP aliasing, alias creation outside the authenticated WebSocket branch, missing alias failure exits, empty-destination DNS fallback, fallback destinations, `$du` overwrites, REGISTER aliasing, stale checksums, and regressions in the existing ACK, client-BYE, Record-Route, unavailable-runtime `503`, DNS-policy, rtpengine, and public-surface contracts. No Kubernetes apply was performed. `PRODUCT_DEFECT-11 = ready for final live closure proof`; T3-S2A is ready for browser-bound BYE reproof; T3-S2 media mediation is Not Started; T3 remains In Progress. **The T3-S2 provider-neutral media live proof at `afced8d` is recorded in `docs/evidence/t3/t3-s2-provider-neutral-media-live-proof.md`: SDP-plane mediation and media-session lifecycle are fully proven, but actual RTP/SRTP media flow is not, so T3-S2 remains In Progress.** Exactly two resources were applied — the corrected `kamailio-config` ConfigMap (sha256 `e064cd33...` to `0d788689...`, adding `rtpengine.so`, the `rtpengine_sock` modparam pointed at the existing internal control Service `udp:rtpengine.utcp-platform.svc.cluster.local:2223`, and the generic `MEDIA_OFFER`, `MEDIA_ANSWER`, `MEDIA_DELETE` and `APPLICATION_RUNTIME_MEDIA_REPLY` routes) and the checksum-coupled `kamailio` Deployment (generation 17 to 18) — producing an automatic ~5-second rollout to ReplicaSet revision 18 with no manual restart, zero ERROR lines, running configuration byte-identical across all four authorities, and the rtpengine module binding its control endpoint at startup. Media authority is provider-neutral by evidence: scanned inside the media routes, ARI, AMI, Asterisk channel identifiers, Asterisk CLI, dialplan state, Pod-IP literals, database media state and Redis media state all occur **zero** times, there is no direct-media or `rtpproxy` fallback anywhere, and REGISTER carries zero media operations. Because no WebRTC-capable SIP client exists in the repository, a disposable one was driven inside the real application origin after a **natural first-party login** through the real login form (including the app's own forced password change), with the page obtaining its SIP credential from its own authenticated session and generating a genuine `RTCPeerConnection` offer (real ICE candidates, real DTLS fingerprint, `UDP/TLS/RTP/SAVPF`, `rtcp-mux`, deterministic 440 Hz Web Audio tone). Proven live: **MEDIA_OFFER** runs once per initial SDP INVITE, rtpengine accepts it, one session is created with ports allocated from the committed `40000-40099` range, and the runtime-facing offer is rewritten to `o=`/`c= 10.42.0.166` with `m=audio 40012 RTP/AVP` — ICE candidates, `ice-ufrag`, `fingerprint` and `setup` all stripped and **zero** browser addresses leaked; **MEDIA_ANSWER** runs once via `APPLICATION_RUNTIME_MEDIA_REPLY` and rewrites the runtime's own `c=IN IP4 10.42.2.150 / m=audio 12682 RTP/AVP` answer into `c=IN IP4 10.42.0.166 / m=audio 40092 RTP/SAVPF` with ICE, rtpengine's own DTLS fingerprint, `setup:passive`, `rtcp-mux` and PCMU — accepted by the real browser via `setRemoteDescription`, with the runtime Pod IP absent; **browser-originated BYE** logs `media_delete` with the matching Call-ID, returns `200 OK`, and returns sessions and ports to `0`; **runtime-originated BYE** from a clean CLI stimulus (no `Reason: cause=408`) is alias-routed to the browser, answered `200 OK` with `0` retransmissions, deletes the same media session and terminates both sides; **terminal runtime failure** deletes the created offer state from the failure route and emits the committed `asterisk_unavailable` `503` with no secondary runtime, no fallback and no residual session or port; and **rtpengine unavailability fails closed** with `media_offer_failed`, `no available proxies`, a committed `488 Media Relay Unavailable` to the client, and **no leak to the runtime** (`0 calls processed`). No direct-media path, Pod/node/developer-host address leak, public exposure or durable media authority appeared, and the full-cluster Pod diff contains only the Kamailio rollout plus the two intentional availability tests. **The unproven boundary is actual media flow:** the developer host has no route to the pod CIDR (`ip route get 10.42.0.166` resolves via the default gateway; a TCP probe times out), which is precisely the media containment T3-S1 proved, so a host-side browser cannot complete ICE/DTLS with rtpengine — the browser did receive and attempt rtpengine's candidate (`remoteCandidates ["10.42.0.166:40048"]`, ICE `checking`, DTLS `connecting`) but sent `0` packets. No host route was added, because that would breach a proven containment contract, so ICE completion, DTLS establishment, browser-to-runtime and runtime-to-browser SRTP and echo are reported unproven rather than inferred from SDP. CANCEL remains a bounded fixture limitation. The next step is an **in-cluster** WebRTC-capable media prover on the pod network to close the media-flow gap without weakening containment, after which the bounded FreeSWITCH parity gate applies. Asterisk remains the current reference runtime and runtime-agnostic parity is not yet proven. T3-S2 is In Progress and T3 remains In Progress.

**T3-S2B repository proof infrastructure is implemented and recorded in [`docs/evidence/t3/t3-s2b-in-cluster-webrtc-media-prover.md`](../evidence/t3/t3-s2b-in-cluster-webrtc-media-prover.md).** The new local-only one-shot prover runs a pinned real Playwright/Chromium WebRTC browser inside the Pod network, uses natural application login and canonical HTTPS/WSS hostnames, reaches contained rtpengine media only through exact proof-only NetworkPolicies, emits structured RTP/audio evidence, and keeps the proof workload out of production/base renders. This separates the T3 media work into: T3-S2A bidirectional application-dialog signaling = Complete; T3-S2B in-cluster WebRTC media-flow proof = prover corrected in repository, live proof pending; T3-S2C second-runtime parity, preferably FreeSWITCH = Not Started; T3-S3 external media edge, advertised address, NAT/firewall and public reachability = Not Started. Asterisk remains the current reference runtime. Runtime-agnostic parity and external browser media readiness are not yet proven. T3 remains In Progress. `UTCP_PHASE=T1`.

## Next-Phase Ordering

The historical T3 preparation and FreeSWITCH SIP/media proof above remain
preserved. They do not replace the current post-RH-3 direction. T5 is complete
and remains complete; it is not reopened or returned to pending work. The next
executable capability is **C6 — Canonical Call Lifecycle & Normalized Call
Control**. The bounded sequence is:

```text
RT-1 -> C6 -> T4 -> C7A -> C7B -> T6 -> V1 -> A0 -> R0
```

T4 follows C6 for normalized contract definition and precedes C7 for
second-real-runtime validation. K5 is intentionally omitted from this primary
sequence: it is a parallel planned infrastructure track and is not a
prerequisite for C6 closure, T4, or C7. ADR-024 records its Host/RuntimeNode
authority boundary and future maintenance direction.

## Phase R0 — Portfolio Release — Planned

Objective: make the project understandable and reproducible by another engineer or prospective client. Required documentation: architecture overview, authority map, local Compose guide, k3d guide, runtime-adapter guide, call-lifecycle/call-control contract guide, external-trunk/route/caller-identity lifecycle guide, security model, observability guide, failure-injection guide, demonstration guide, known limitations, production-readiness disclaimer, roadmap. Exit criteria: clean-clone setup is verified; demo contains only synthetic data; CI is green; no secrets are committed; screenshots/diagrams are current; project limitations are honest; a versioned release is created.

## Phase F0 Exit Criteria

- Repository has a coherent documented purpose.
- `make help` succeeds.
- `make doctor` reports available and missing development tools without modifying the host.
- No real credentials are stored.
- Roadmap phase status is visible.
- CI validates basic repository hygiene.

## Phase Discipline

Phases may be split into smaller implementation corridors, but must not be reordered without an ADR explaining why. Later phases remain planned until their exit criteria are proven. A phase must not be marked complete from static code alone — architecture-boundary review, migrations/schema proof, unit/feature/API/frontend tests, asynchronous worker proof, event-normalization proof, idempotency proof, retry/timeout proof, restart proof, reconciliation proof, security proof, observability proof, cleanup proof, environment-preservation proof, an evidence document, and a roadmap-status update are all expected where applicable. A later phase must not compensate for an unproven earlier authority boundary.

**T3-S3A external media projection (2026-08-02):** Starting at `9b253f0`, the repository implements the canonical `UTCP_PUBLIC_MEDIA_ADDRESS` authority, preserves the default internal-only local overlay, and adds a bounded local k3d media-edge projection with loopback UDP `40000-40099`, a matching 100-port UDP NodePort Service, `externalTrafficPolicy: Local`, and stable media-edge node pinning. Full rendered image/generator preservation, bounded overlay-delta, k3d restriction, and mutation checks pass offline. No Kubernetes resource was applied, the cluster was not recreated, and external reachability was not claimed. T3-S2 is Complete; T3-S3A is implemented and awaiting cluster recreation and live validation; T3-S3B is Not Started; T3 remains In Progress and `UTCP_PHASE=T1` is unchanged. See [`docs/evidence/t3/t3-s3a-external-media-projection.md`](../evidence/t3/t3-s3a-external-media-projection.md).

**T3-S3 external media-edge architecture decision (2026-08-02):** Starting at `803b819`, the canonical architecture for external browser ICE/RTP reachability is selected; no Kubernetes resource was applied and the cluster is unchanged. The exact current failure is isolated as *"the browser receives a candidate that is valid inside the cluster but unreachable from the external browser network"* — rtpengine advertises its Pod IP in `10.42.0.0/16` while `ip route get` on the host resolves that address via the default gateway; NetworkPolicy, port-range, runtime-RTP, codec, ICE-credential, DTLS, and SIP/SDP causes are each excluded by the completed T3-S2B/T3-S2C proofs. The chosen contract is provider-neutral — a single `UTCP_PUBLIC_MEDIA_ADDRESS` declaration in `versions.env` projected into rtpengine's already-existing advertised-address seam `--interface=[NAME/]IP[!IP]` (confirmed from the pinned `mr26.0.1.19` binary), reusing the existing `RTPENGINE_MEDIA_PORT_MIN/MAX` with no second range declaration. The local k3d projection is host UDP range publication (`127.0.0.1:40000-40099/udp@server:0`, mirroring the existing `127.0.0.1:443` edge) combined with a `rtpengine-media` NodePort Service and node pinning, requiring `--kube-apiserver-arg=service-node-port-range=30000-40099` and therefore cluster recreation. Models were rejected on measured evidence rather than principle: Traefik/Gateway-API UDP because the SHA-pinned Gateway API v1.5.1 standard channel installs no `UDPRoute` CRD and Traefik's running entry points are all `/tcp`; LoadBalancer because k3s klipper-lb materialises one container per Service port (observed `lb-tcp-80`, `lb-tcp-443`), so 100 media ports would mean 100 containers per node; dedicated media edge / `hostNetwork` because PSA `restricted:v1.35` on `utcp-platform` forbids both `hostNetwork` and `hostPort`, proven by server dry-run; NodePort alone because the advertised candidate would be a node IP, and node IPs demonstrably shuffle on host restart; and manual `socat`/DNAT forwarding because it requires recurring manual commands and a second management plane. `Service.spec.ports` has no range field (`kubectl explain`), so the 100 enumerated NodePort entries are unavoidable for any Service-based model. ADR-020 must be extended as it already requires, and two guards (`scripts/k3d/config-check:45`, `scripts/k3d/verify:106`) need bounded amendment with the precedent set in `t3-rtp-media-preparation-audit.md`. T3-S3 is split into T3-S3A (external-media authority and local projection) and T3-S3B (external browser media proof and failure behaviour) because cluster recreation and an external host-browser harness form a genuine implementation/proof boundary. T3-S2 remains Complete, T3-S3 is architecture-selected and awaiting bounded implementation by Codex, T3 remains In Progress, and `UTCP_PHASE=T1` is unchanged. See [`docs/evidence/t3/t3-s3-external-media-edge-architecture.md`](../evidence/t3/t3-s3-external-media-edge-architecture.md).

**T3-S3B external ingress and applicability correction (2026-08-02):** Starting from `7568b6e`, the repository adds a media-edge-only all-source UDP ingress policy bounded to rtpengine's canonical `40000-40099` media range and makes the applicability checker normalize direct, array, YAML, and `kind: List` output without skipping nested objects. The default local projection and internal media policy remain unchanged. Static and mutation checks pass; no Kubernetes resource was applied, no cluster was recreated, and external browser media proof remains pending. T3-S3B is ready to resume, T3 remains In Progress, and `UTCP_PHASE=T1` is unchanged.

**T3-S3B failure and containment closure attempt (2026-08-08):** The repository now validates canonical Asterisk exec readiness, bounded abandoned-media timeouts, explicit external candidate identity/port/fallback assertions, deterministic failure-result contracts, and live containment inventory. `make test` and `make check` pass; Scenario A and Scenario B pass on `utcp-local`, and containment passes during an allocated Scenario B session. T3-S3B remains incomplete until the four defined live negative cases are created and proven through the canonical lifecycle with restoration and baseline cleanup.

**T3-S3B negative cases and restoration closure (2026-08-08):** The four committed failure lifecycles are now live-proven against canonical `utcp-local`: invalid advertised public-media addresses reject before Kubernetes mutation; explicit NodePort allocation collides on `40000`/`40099`; public media binding loss moves healthy rtpengine to `agent-0` with no eligible local endpoint on `server-0`; and the external-candidate-unreachable overlay removes only external RTP admission while preserving the healthy local endpoint and media allocation. Every case produced the intended failure, rejected private fallback, restored through the canonical lifecycle, and returned Asterisk channels and rtpengine sessions/used ports to baseline with zero Kustomize drift. Fresh Scenario A and B regressions passed with explicit forbidden-address inputs; live containment passed during an allocated session; `make test`, `make check`, and focused proof checks passed. T3-S3B and T3 are complete; `UTCP_PHASE=T1` remains unchanged. The next roadmap milestone is the planned V0 reference dialer.

## RNM — Runtime Node Management Completion

RNM completes the operator-visible RuntimeNode lifecycle using the canonical C2
registry, C3 reconciliation engine, runtime adapters, and T5 failure/recovery
foundation. C2 remains the registry authority defined by ADR-015; RNM is the
cross-cutting lifecycle completion milestone.

The intended lifecycle is:

```text
DRAFT → ACTIVE → DRAINING → DRAINED → RETIRED
```

with failure/manual exclusion remaining separate:

```text
ACTIVE → DISABLED → ACTIVE
```

Desired lifecycle and observed health remain orthogonal.

### RNM-1 — Lifecycle contract and honest drain semantics — Complete

Implemented terminal soft retirement with zero-active-binding protection,
retired exclusion from operational paths, active-only new placement, existing
work preservation on draining nodes, identity mutation guards, minimal UI
compatibility, and documentation alignment. Evidence:
[`docs/evidence/rnm/rnm-1-runtime-lifecycle-contract.md`](../evidence/rnm/rnm-1-runtime-lifecycle-contract.md).

### RNM-2 — Drain coordinator and completion detection — Complete

Implemented active-work accounting from durable conference bindings, automatic
progress evaluation through the existing reconciliation scheduler,
zero-work-produced `DRAINED`, timeout classification, cancellation,
idempotent completion, RuntimeNode evidence projection, and minimal truthful
Admin UI state/progress presentation. Evidence:
[`docs/evidence/rnm/rnm-2-runtime-node-drain-coordinator.md`](../evidence/rnm/rnm-2-runtime-node-drain-coordinator.md).

### RNM-3 — Decommission orchestration — Complete

Decommission is an explicit, durable `runtime.node.decommission` operation
available only for `DRAINED` nodes with zero active canonical bindings. The
system-owned completion path retires all UTCP-held active credentials, removes
remaining runtime-management enrollment, and transitions the node to terminal
soft-retirement while preserving history. Stale operations cannot override
reactivation, and planned decommission does not reuse failure fencing or claim
physical destruction of externally managed infrastructure. Evidence:
[`docs/evidence/rnm/rnm-3-runtime-node-decommission.md`](../evidence/rnm/rnm-3-runtime-node-decommission.md).

### RNM-4 — Observed capability projection — Complete

Observed capability snapshots now flow from normalized runtime evidence through
`ProjectionService` into a durable projection separate from the declared
`RuntimeRegistryService` capability set. RuntimeNode evidence exposes observed
freshness, provenance, and derived declared-versus-observed drift without
mutating administrator intent. The deterministic simulator now emits a complete
intrinsic capability snapshot during its normal inspect lifecycle, closing the
live producer gap without copying declared capabilities. Evidence:
[`docs/evidence/rnm/rnm-4-observed-capability-projection.md`](../evidence/rnm/rnm-4-observed-capability-projection.md).

### RNM-5 — Natural RuntimeNode Admin UI — Complete

The canonical Admin UI now provides a coherent RuntimeNode list/detail
workflow: externally-managed node registration, safe metadata and placement
editing, endpoint add/edit/remove, write-only credential lifecycle, declared
capability editing, observed capability/drift evidence, human-readable health
and drain/decommission status, state-aware lifecycle actions, and loadable
history. Kubernetes/Docker implementation details remain outside normal UI
operation.

The canonical fixture gap and all three acceptance defects from the first
natural browser run are closed. A deterministic-simulator RuntimeNode was
created and driven through create → configure → activate → ready → drain →
drained → reactivate → drain → decommission → retired entirely from the real
Admin UI after a natural login. Family/adapter selection is catalog-driven,
credential rotation succeeds with the existing credential type, and explicit
Refresh/detail reopening forces current evidence so row and expanded detail
agree without a full browser reload. Focused frontend tests, full frontend
checks, `make test`, `make check`, canonical deployment, and fresh natural
browser proof all passed. The follow-on simulator producer proof now shows the
same UI moving from unknown capability evidence to a fresh intrinsic snapshot
and deterministic declared/observed drift, again through normal reconciliation
and refresh.
Evidence:
[`docs/evidence/rnm/rnm-5-natural-runtime-node-admin-ui.md`](../evidence/rnm/rnm-5-natural-runtime-node-admin-ui.md).

### RNM-6 — Full lifecycle browser/live proof — Complete

The complete planned lifecycle was proven live on `utcp-local` through the
canonical Admin UI after a natural login: create → configure → declared
capabilities → activate → automatic `READY` → automatic observed-capability
snapshot with visible declared/observed drift → drain held open by a real
runtime binding (`remaining_work 1`) with a live cordon proof
(`pending_no_capacity` for new work) → system-produced `DRAINED` → reactivate →
`READY` → final drain → decommission → `RETIRED`, with credential retirement,
retained historical evidence, and cursor history pagination. `DRAINED` and
`RETIRED` were produced by the coordinator and the decommission operation, never
asserted by the operator.

The first run failed one required criterion: `RETIRED` was not read-only at the
canonical API authority, because `assertNodeNotRetired()` was not called from
`updateNode()`. A bounded fix added that guard, and a narrow post-fix live
reproof closed the gap: `PATCH /api/v1/admin/runtime-nodes/{id}` on a retired
node now returns `422 Retired runtime nodes are read-only historical records`
with zero persisted mutation, no new `runtime_node.updated` audit, and no
side-effect operation; all eight terminal mutation probes are refused and
historical evidence remains readable. The defect history is preserved rather
than rewritten. Evidence:
[`docs/evidence/rnm/rnm-6-full-runtime-node-lifecycle-live-proof.md`](../evidence/rnm/rnm-6-full-runtime-node-lifecycle-live-proof.md).

RNM-6 is therefore complete. RNM — Runtime Node Management Completion — was
subsequently **challenged by the RNM-A adversarial acceptance audit**, which
proved two reachable retired-node authority defects (adapter configuration
writable on a RETIRED node; `disabled → retired` stranding an unrevocable active
credential), both sharing the Stage 17 root pattern of per-writer terminality
enforcement. Bounded fixes were applied and the two exact attacks were replayed
live against the fixed deployment: both now return `422` with zero persisted
mutation, and `disabled → retired` retires every active UTCP-held credential
inside the same transaction. **RNM-A passed and RNM closure stands: RNM —
Runtime Node Management Completion — is COMPLETE.** Managed runtime provisioning
remains future RNP work. See
[`docs/evidence/rnm/rnm-a-runtime-node-management-adversarial-audit.md`](../evidence/rnm/rnm-a-runtime-node-management-adversarial-audit.md).

## RNP — Managed Runtime Provisioning

RNP-1 is implemented: the Admin API and RuntimeProvisioningService persist a
tenant-scoped local_kubernetes deployment target and durable Asterisk
provisioning intent, creating the linked canonical DRAFT RuntimeNode.
Idempotency, transactionality, tenant isolation, audit, and outbox behavior are
covered by focused tests. RNP-1 performs no Kubernetes resource writes. **RNP-2
— Kubernetes Resource Writer and Scoped RBAC is complete.** The existing
HttpKubernetesWorkloadClient provides an ownership-safe, namespace-bounded
writer for Secret, Deployment, and Service resources. Option 1 promotes the
existing `utcp-runtime-fencer` / `telephony-infrastructure-worker` boundary as
the single fencing plus provisioning identity; the canonical local overlay
activates it and the live namespace-scoped allow/deny matrix passed. No
managed Asterisk workload is created by RNP-2; provisioning and lifecycle
management remain separate internal responsibilities, and all managed and
external onboarding paths continue to converge on RuntimeNode. Evidence:
`docs/evidence/rnp/rnp-2-kubernetes-resource-writer-and-rbac.md`.

**RNP-3 — Managed Asterisk Provisioning Operation: Complete in the repository.**
Accepted RNP-1 intent now creates one idempotent `runtime.node.provision`
operation. The infrastructure worker executes it through
`RunsWithoutRuntimeAdapter`, generates the checked-in Asterisk workload
contract, converges Secret, Deployment, and Service resources through RNP-2,
registers the credential, endpoints, catalog capabilities, adapter profile, and
Kubernetes workload identity through canonical services, and activates the
DRAFT RuntimeNode only after configuration is complete. Credential reuse,
partial retry, ownership conflict handling, activation ordering, and secret
non-exposure are focused tested. Readiness remains the existing Asterisk
reconciliation and projection authority; no live managed-runtime lifecycle
proof is claimed. RNP overall remains in progress. Evidence:
`docs/evidence/rnp/rnp-3-managed-asterisk-provisioning-operation.md`.

**RNP-4 — Managed Runtime Deprovisioning: Complete in the repository.** A
managed RuntimeNode reaching canonical `RETIRED` through either RNM terminal
corridor automatically schedules one idempotent `runtime.node.deprovision`
operation. The infrastructure worker revalidates `RETIRED`, proves the
canonical RNP provisioning relationship, preflights ownership for the exact
RNP-3 Deployment, Service, and Secret names, and deletes owned resources in
Deployment → Service → Secret order through the RNP-2 writer. Absence and
partial deletion retries converge; an ownership conflict deletes nothing.
External/adopted retired nodes are preserved, RuntimeNode history and
RuntimeRegistry credential metadata remain untouched, and no Kubernetes
authority was expanded. Focused RNM, RNP-2, RNP-3, and RNP-4 tests pass; no
live managed-runtime lifecycle proof is claimed. RNP overall remains in
progress. Evidence:
`docs/evidence/rnp/rnp-4-managed-runtime-deprovisioning.md`.

**RNP-5 — Managed Runtime Admin UI: Complete in the repository.** The existing
Runtime Nodes Admin surface now offers one Add Runtime entry point with Managed
Runtime and Register Existing Runtime paths. Managed onboarding supports the
canonical Asterisk adapter and deployment targets returned by the API, presents
a business-intent review, and submits exactly one canonical RNP provisioning
request. Managed/external status and provisioning/deprovisioning progress are
read-only projections of the canonical RuntimeNode/RNP relationship and
operation evidence; RNM remains the lifecycle authority. No credentials, raw
Kubernetes details, or competing retry/deprovision controls are exposed.
Focused frontend/API coverage, the RNP-1 through RNP-4 regressions, and the
repository checks are required evidence for this phase; no natural managed
runtime browser or live lifecycle proof is claimed. RNP overall remains in
progress. Evidence:
`docs/evidence/rnp/rnp-5-managed-runtime-admin-ui.md`.

**RNP-6 — Natural Managed Runtime Lifecycle Live Proof: Complete. RNP is
Complete.** RNP-6 is one composed evidence chain: first natural live run
(FOUND_BLOCKER) → bounded fixes for PRODUCT_DEFECT-27/-28 → narrow live reproof
(PASSED, 2026-08-09). The reproof established the five items the repairs
affect, through a natural browser session against canonical `utcp-local`:
the managed Deployment carries the configured qualified image
`utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev` (worker reads
`UTCP_MANAGED_ASTERISK_IMAGE` from `utcp-application-config`) and pulls
successfully with no `ImagePullBackOff` or `ErrImagePull`; the managed
Asterisk workload reaches Deployment Available/Pod Running/Container Ready
with 0 restarts and no probe failures; the existing Asterisk
reconciler → observation → ProjectionService authority converges naturally to
`observed_state = ready` and sustains it across eleven consecutive samples
while reconciliation reports `converged`; the Runtime Nodes UI renders
`Provisioning: Ready`; and the retired historical fixture renders
`Infrastructure: Deprovisioned`. Provisioning succeeded on attempt 1 with no
drift and no repair. The readiness fixture
`rnp6-readiness-reproof-20260809` is retained ACTIVE/READY as live evidence;
the already-proven RNM drain/decommission/deprovision path was deliberately
not rerun on it. No code or topology changes were made during the reproof.
Historical detail of the first run follows and is intentionally preserved.

The consolidated live acceptance run drove the entire
managed-runtime chain through the real Admin UI against canonical
`utcp-local`, from natural login to historical retention. Proven live: one UI
submission yields exactly one provisioning request, one RuntimeNode, and one
`runtime.node.provision` operation with no manual Start/Apply/Reconcile step;
the infrastructure worker automatically creates exactly one Secret,
Deployment, and Service with correct `part-of`/`runtime-node` ownership and no
extra resources; credential, endpoints, capabilities, adapter configuration,
and Kubernetes workload identity are derived automatically and every one
precedes activation; the API ServiceAccount still holds no infrastructure
mutation authority; RNM Drain and Decommission produce `draining → drained →
retired` through the canonical coordinator; exactly one
`runtime.node.deprovision` operation is scheduled automatically from the
provisioning relationship and removes all three resources; the historical
RuntimeNode, endpoints, capabilities, adapter configuration, and retired
credential metadata are retained; and no credential plaintext appears in the
UI, API, operations, audit, or outbox. Two defects block completion.
PRODUCT_DEFECT-27 (blocking): `ManagedAsteriskProvisioningOperationHandler`
writes the Deployment with the unqualified image `utcp-asterisk-ari`; because
RNP-3 writes through the Kubernetes API the overlay Kustomize image transform
never applies, the reference resolves to Docker Hub, and the Pod stays in
`ImagePullBackOff`, so the managed runtime can never reach
`observed_state = ready`. PRODUCT_DEFECT-28: `RuntimeNodesView.vue` compares
runtime-operation status against `completed`, which the backend never emits
(canonical terminal status is `succeeded`), mislabelling a succeeded provision
as `Requested` and a succeeded deprovision as `Deprovisioning`. The next RNP
packet implemented both bounded fixes: managed Asterisk now uses the required
qualified image from canonical application configuration, and the Admin UI
uses the canonical operation-status union. Focused backend/frontend and
configuration checks pass, and the narrow live reproof summarised above has
since closed both defects. Evidence:
`docs/evidence/rnp/rnp-6-natural-managed-runtime-lifecycle-live-proof.md`.

**RNP-U2 — Operator Experience & Managed Authority Hardening: Complete and
accepted by natural live reproof (2026-08-09).** The narrow reproof passed all
eight required items against canonical `utcp-local` through a natural browser
session: name-only managed creation (1 control, 3 clicks, 0 technical
decisions), automatic resolution of the single runtime type and single
deployment target, managed detail with 0 mutation controls and 0 inputs, all
four Admin mutation corridors rejected 422 with canonical state unchanged, a
non-retired external node retaining its full editing surface, human-facing
primary status with canonical detail preserved under Advanced diagnostics,
truthful management-aware retirement confirmation, and duplicate-identity 422
validation with no orphan data and no Server Error. Two bounded status-label
inconsistencies in adjacent states are deferred polish. Evidence:
`docs/evidence/rnp/rnp-u2-operator-experience-streamlining.md`.

The implementation record, retained: the normal managed path
is name-first, collapses the current single Asterisk and single location
choices, derives slug server-side, returns useful 422 duplicate validation,
and removes redundant review navigation. The canonical RNP provisioning
relationship blocks manual mutation of generated managed configuration at the
Admin API boundary while preserving internal provisioning and external
RuntimeNode mutation. The UI presents one human-facing status, keeps technical
data under Advanced diagnostics, and uses management-aware retirement
confirmation. Evidence:
`docs/evidence/rnp/rnp-u2-operator-experience-streamlining.md`.
@@ -276,0 +277,2 @@

**V0 reference-client call-lifecycle convergence implementation (2026-08-15): IMPLEMENTED AND TESTED; narrow natural lifecycle reproof pending.** PRODUCT_DEFECT-30 and PRODUCT_DEFECT-31 are corrected in the bounded reference client, and OBSERVATION-1 is addressed through automatic renewal of the finite signaling credential on the existing SIP.js UserAgent/Registerer. Focused tests cover terminal remote termination, failed INVITE compensation, idempotent participant release, navigation teardown, renewal success/failure, and registration continuity. No Reverb/RT-1 implementation or backend participant-reconciliation redesign was introduced. Evidence: [`docs/evidence/v0/reference-client-call-lifecycle-convergence-implementation.md`](../evidence/v0/reference-client-call-lifecycle-convergence-implementation.md). V0 remains in progress pending narrow natural lifecycle reproof.

**V0 credential renewal and reference-client recovery fix (2026-08-15): IMPLEMENTED AND TESTED; narrow credential/retry natural reproof pending.** PRODUCT_DEFECT-32 is corrected at the API boundary with explicit UTC ISO-8601 issuance timestamps and a bounded fail-closed renewal scheduler that rejects invalid, expired, or non-advancing credential lifecycles. PRODUCT_DEFECT-33 is corrected by making the existing `Needs attention` state retryable without remounting, while preserving attempt identity protection. OBSERVATION-3 is handled at the frontend API-client boundary by normalizing only the typed 404 already-absent participant result as converged cleanup; unexpected release errors remain visible. Shared single-flight cleanup, the existing signaling credential authority, and the SIP.js UserAgent/Registerer are preserved. No Reverb/RT-1 implementation, PROOF_GAP-1 workaround, or backend reconciler redesign was introduced. Evidence: [`docs/evidence/v0/reference-client-credential-renewal-and-recovery-implementation.md`](../evidence/v0/reference-client-credential-renewal-and-recovery-implementation.md). V0 remains in progress pending narrow natural reproof, followed by the V0-A lifecycle authority audit.
**RH-1 — Canonical recoverable participation, recovery grace, discovery, and automatic expiration (2026-08-15): IMPLEMENTED AND TESTED; RH-2 browser recovery remains pending.** The server-side recovery foundation records runtime_channel_lost_at only for the exact current runtime channel, clears it on successful replacement binding, exposes authenticated bounded participation discovery through the existing reference-dialer bootstrap, and expires abandoned admitted self-admission participation automatically after the exact 120-second domain grace. desired_state remains the sole participation-intent authority; explicit removal defeats recovery. No browser auto-rejoin, V0, RT-1A, Reverb, recovery token, or second lifecycle authority was introduced. Evidence: docs/evidence/rh/rh-1-canonical-recoverable-participation.md.

**RH-2 — Browser refresh/network recovery and automatic replacement conference leg (2026-08-15): IMPLEMENTED AND TESTED; natural browser proof pending.** Unexpected SIP termination, network lifecycle signals, refresh/navigation teardown, and component unmount now preserve canonical participation and enter browser `Recovering`; only explicit Leave invokes the existing participant removal authority. Bootstrap participation remains the server decision, active old runtime channels block premature replacement, surviving established dialogs are reused, and eligible recovery reuses the same participant through `participants/self` before inviting the server-returned `conf-*` destination. Single-flight recovery, attempt fencing, explicit-Leave cancellation, and preservation of new-Join compensation are covered by focused frontend/signaling tests. No RH-1 backend, schema, V0, RT-1A, Reverb, browser-persistence, or feature-gate change was introduced. Evidence: `docs/evidence/rh/rh-2-browser-network-auto-rejoin-implementation.md`. Next: narrow natural browser refresh/interruption/auto-rejoin proof; RH-3 remains not implemented.

**RH-2 — Natural browser interruption/refresh/auto-rejoin live proof (2026-08-15): `FOUND BLOCKER`.** Evidence-only, no source changed. The recovery corridor is proven correct through the replacement INVITE: refresh issued **0 DELETE** and preserved `admitted`; bootstrap withheld recovery while the old channel was bound (`state:"active"`), then granted it at `runtime_channel_lost_at + 120 s` exactly; the client re-admitted the **same** participant on the **same** `conf-*` destination and placed **one** logical INVITE with no second Join click and no duplicate/storm behaviour. **Blocker (IMPLEMENTATION)**: the replacement channel is never bound — it stayed Up in Stasis with `runtime_channel_id` null and no bridge membership while the UI read Connected, and the RH-1 grace sweep then removed the participant against a live channel. The `stasis_start` receipt exists and the listener dispatched it with no rejection logged, so `AsteriskConferenceParticipantBinder::bind()` returned `false` on a transiently-false predicate (the RuntimeNode's next `observed_at` landed after the event) and **nothing retries** — `ConferenceParticipantReconciler` waits for a *new* inbound leg instead of adopting the live unbound one. Secondary non-blocking finding: Leave taken while the view is `recovering` strands the view in `recovering`, hiding the conference list and Join until remount. Scenarios 2–5 not run (each would have measured the same defect); grace expiration and explicit-Leave cutoff were observed naturally and matched RH-1 exactly. V0 and RT-1A unchanged; the 120 s grace and `rtp_timeout` unmodified; environment left clean. Evidence: `docs/evidence/rh/rh-2-browser-network-auto-rejoin-live-proof.md`. Next: bounded implementation closing the replacement-leg binding gap, then a narrow RH-2 reproof.

**RH-2 — Final natural browser interruption/refresh/auto-rejoin live reproof (2026-08-15): `FOUND BLOCKER`.** Evidence-only, no source changed. **All five scenarios passed and the RH-2B retry path was live exercised** (`RETRYABLE → queued retry → same live channel → BOUND`, the retry itself writing `runtime_channel_id` and attaching the bridge at 06:30:18; a second execution proved the liveness branch stops the ladder when the channel is gone). Refresh: 0 DELETE, recovery withheld while the old channel was bound then granted at loss + 120 s exactly, same participant on the same `conf-*` destination, one logical INVITE, bound + bridged; Connected was withheld for four seconds while the SIP dialog was Established but unbound. Brief interruption: same dialog, 0 admissions, 0 INVITEs. Dialog loss: one replacement, bound and bridged. Leave-while-Recovering: exactly one DELETE, UI returns to Ready **with the conference list and Join restored** (previous stranded-`recovering` defect closed) and no later auto-rejoin. Grace expiry: deadline disables recovery before the sweep, sweep converges with `reason: recovery_grace_expired`, return after expiry does not auto-rejoin. No storms. **Two blocking defects remain, outside the scenario scripts.** (1) A **runtime-initiated BYE on an established leg leaves the browser on Connected indefinitely** with no recovery attempt until participation silently expires — observed twice with the BYE captured on the wire, against a control where a Join-established leg recovered correctly; most consistent with the `hasEstablishedConference()` survival short-circuit in `beginRecovery()`, root cause not fully isolated. (2) `AsteriskAriEventNormalizer` maps **every unrecognised ARI event** to a `runtime_node` observation with `observed_state='degraded'`, which `ProjectionService` writes to the canonical node (124 degraded vs 158 ready in 40 minutes, including from `StasisStart` itself); since `isRecoverableParticipation()` requires `ready`, `currentParticipation()` reports **`expired` inside the grace**, and the client aborted an already-established replacement leg and never retried — the same transient behind the previous RH-2 blocker. Non-blocking: the Recovering banner can persist alongside a working Connected/Leave. Divergence: `make k8s-apply` reverts the media edge and `make media-edge-apply` was not re-run immediately, costing the first proof window (DEPLOYMENT, self-inflicted, corrected); all reported results come from the corrected environment. V0 and RT-1A unchanged; grace and `rtp_timeout` unmodified; environment left clean. Evidence: `docs/evidence/rh/rh-2-browser-network-auto-rejoin-live-reproof.md`. Next: bounded RH-2 fix for both defects, then a narrow reproof of those two corridors only.

**RH-2B — Replacement-leg binding retry and canonical Connected gating (2026-08-15): IMPLEMENTED AND TESTED; natural proof pending.** The existing StasisStart binder now classifies transient readiness/observation misses separately from terminal rejection and schedules bounded retry for the same live channel, RuntimeNode, and participant reference. Recovery remains Recovering after SIP establishment until canonical bootstrap confirms the same participant is active/bound; explicit Leave from Recovering cancels and fences the attempt and restores Ready controls. The change preserves the reconciler boundary, RH-1 grace, V0, RT-1A, and all telephony authorities. Evidence: `docs/evidence/rh/rh-2b-replacement-leg-binding-retry-connected-gating.md`. Next: narrow RH-2 Scenarios 1–5 natural browser proof; RH-3 remains not implemented.

**RH-2C — Canonical ARI Runtime Observation Authority (2026-08-15): IMPLEMENTED AND TESTED; browser proof not required.** The generic Asterisk ARI normalizer fallback now retains unknown/call traffic as non-readiness evidence instead of manufacturing `runtime_node` `degraded` state. Projection preserves capability snapshots while restricting canonical RuntimeNode readiness mutation to explicit readiness/connection observations; explicit authentication failure remains a real degraded health signal. Focused tests cover StasisStart, unknown traffic, projection preservation, capability freshness, and explicit health degradation. No binder retry, browser recovery, RH-1 grace, V0, RT-1A, or runtime-BYE changes were introduced. Evidence: `docs/evidence/rh/rh-2c-ari-runtime-observation-authority.md`. RH-2 remains blocked only on the separate runtime-initiated BYE client diagnosis; RH-3 remains not implemented.

**RH-2D — Runtime-initiated BYE client lifecycle diagnosis (2026-08-15): `ROOT CAUSE ISOLATED`.** Evidence only, no source changed. **`hasEstablishedConference()` is not the defect** — the signaling client clears `inviter` and `inviteEstablished` before emitting the terminal callback, and a live discriminator confirmed it returns `false` in the stuck state: a natural offline→online transition reached `beginRecovery()` and recovered normally (1 admission, 1 INVITE, Connected) while the UI had been frozen on a false Connected for 97 s. **Root cause: the view fences call-state callbacks on `conferenceAttempt` (`ReferenceDialerView.vue:173`) but the id stamped on them is the signaling client's separate `inviteAttempt` (`referenceDialerSignaling.ts:47`).** `beginRecovery()` increments the view counter on every entry (`:285`) including the six paths that never invite — notably the 2-second polling branch — so after any recovery the view counter runs ahead and the live leg's `terminated` callback is discarded, `beginRecovery()` at `:192` is never invoked, and `conferenceState` (an independently held ref) stays `'connected'` forever. One live session captured both cases: a Join leg (counters 1/1) received a runtime BYE and recovered correctly; the resulting recovery leg (counters 3/2) received a runtime BYE and produced 0 admissions and 0 INVITEs. Backend authority was correct throughout (`admitted`, channel NULL, loss stamped, bootstrap recoverable for exactly 120 s, node `ready` with RH-2C in effect). The defect is not intrinsic to recovered legs — a Join leg is equally affected once any recovery has run on the page. **Test gap:** every `terminated` assertion in `ReferenceDialerView.test.ts` passes `attemptId === undefined`, skipping the guard entirely. Non-blocking and separately caused: the Recovering banner persists beside Connected because `:280-282`/`:181` set `conferenceState` without restoring `state`. Fix seam: single ownership of the attempt identity. Evidence: `docs/evidence/rh/rh-2d-runtime-bye-client-lifecycle-diagnosis.md`. Next: bounded RH-2D client fix.

**RH-2D — Final runtime-BYE natural live reproof (2026-08-15): `PASSED`. RH-2 is COMPLETE / LIVE PROVEN.** Evidence-only, no source changed. The deployed bundle was content-verified to carry the RH-2D fence (the SIP session generation is adopted from the `inviting` callback and compared on its own, no longer against `conferenceAttempt`). Drift was produced through the ordinary recovery corridor — one refresh drove ~14 non-inviting `beginRecovery()` polling entries, leaving the live leg on signaling attempt **1** while `conferenceAttempt` reached ≈15. The drifted recovery leg was then terminated by Asterisk's own `rtp_check_timeout` policy (**rx BYE @08:33:11.401**, answered 200 OK), and **the callback was accepted despite the drift**: the UI left Connected at 08:33:12, entered Recovering, issued **0 DELETE**, and completed canonical recovery — loss stamped 08:33:14, `admitted` preserved, bootstrap recoverable until 08:35:14 (loss + 120 s exactly), 1 admission, **same** participant, 1 logical INVITE, replacement channel `1786782795.27` bound and bridged by 08:33:21, Connected 08:33:24. Connected gating preserved (Established at 08:33:15.871 held the UI at Recovering until the canonical bind); the Recovering banner is absent after recovery with the outer state consistent. No storms; the only DELETE was the final cleanup Leave. The pre-fix build left the identical situation on Connected for 97 s with 0 admissions and 0 INVITEs. Divergence: the leg carried media, so one canonical `kubectl rollout restart deployment/rtpengine` interrupted the media relay to let the runtime apply its own timeout — no configuration changed, media edge re-verified, no SIP injected, no database touched. Environment left clean. Evidence: `docs/evidence/rh/rh-2d-runtime-bye-natural-live-reproof.md`. **Next: RH-3 adversarial / slow-network resilience hardening.**

**RH-3A — Adversarial / slow-network resilience contract (2026-08-15): `COMPLETED`.** Repository-backed contract audit, no source changed. Timing inventory taken from committed configuration and deployed SIP.js `^0.21.2`. Gaps found: the web API client has **no timeout/abort/retry** and no recovery-path error classification; constant 2 s polling costs ≈60 canonical requests per broken client per grace; **no WSS reconnection exists** (`reconnectionAttempts: 0`, `attemptReconnection()` never called, and `onDisconnect` only reports failure when not registered, so a drop while registered is swallowed); `ensureRegistered()` resolves on REGISTER *send* rather than on 200 OK; no connectivity debounce; terminal classification limited to 401; and multi-tab ownership is undefined (one session and one participation per user, orphan sweep covers only removed participants in closed conferences) — explicitly deferred as a separate contract. Contract established across the partial-reachability matrix, API retryability, REGISTER/INVITE bounds, canonical-binding UX, flapping, repeated loss, sustained degradation, API/worker/Reverb restarts and browser suspension, mapped onto the existing UI states with no redesign. Derivations: the recovery ladder reuses the committed binder ladder verbatim (`[1,2,3,5,8,10,…]` s, cap 10 s, ±20 % jitter) — ≈15 requests instead of ≈60 while still catching an `rtp_timeout=30 s` loss within ≤10 s; INVITE/REGISTER stay on SIP.js Timer B/F (32 s); with the API unreachable the browser issues **no** INVITE and never trusts a cached `recoverable_until`; an established call survives an API restart with no canonical Leave; Reverb never gates recovery. Four new client constants only (`RECOVERY_RETRY_DELAYS_MS`, `RECOVERY_RETRY_JITTER_RATIO`, `RECOVERY_REQUEST_TIMEOUT_MS = 10_000`, `CONNECTIVITY_DEBOUNCE_MS = 1_000`), all code constants, no env gates. **NO SCHEMA CHANGE.** Slices: RH-3B (cadence/timeout/classification/offline), RH-3C (reconnection/registration confirmation/terminal states), RH-3D (adversarial live proof). Evidence: `docs/evidence/rh/rh-3a-adversarial-slow-network-resilience-contract.md`. Next: **RH-3B**.


**RH-2D — Signaling attempt authority / runtime-BYE recovery fix (2026-08-15): IMPLEMENTED AND TESTED; narrow natural runtime-BYE proof pending.** The view now fences SIP session callbacks against the signaling-owned invite attempt, while retaining `conferenceAttempt` solely for orchestration fencing. Non-inviting recovery passes cannot invalidate the active SIP session identity, and stale callbacks from superseded dialogs remain rejected. Recovery success also restores the normal outer registered presentation so Connected is not shown with a Recovering banner. Evidence: `docs/evidence/rh/rh-2d-signaling-attempt-authority-runtime-bye-fix.md`.

**RH-3B — Recovery cadence, request timeout, error classification, offline suspension, and connectivity debounce (2026-08-15): IMPLEMENTED / TESTED; browser proof not performed.** The reference dialer recovery coordinator now uses the bounded 1/2/3/5/8/10-second retry ladder with ±20% jitter and a 10-second cap, recovery-scoped API timeout/abort handling, explicit retry/terminal HTTP classification, offline suspension, and one-second online-event debounce. Existing SIP dialogs remain authoritative during API outage; absent dialogs wait for canonical bootstrap before admission or INVITE. No backend/schema/telephony changes were introduced. Evidence: `docs/evidence/rh/rh-3b-recovery-cadence-api-failure-resilience.md`.

**RH-3C — SIP/WSS transport reconnection and registration confirmation (2026-08-15): IMPLEMENTED / TESTED; RH-3D browser proof pending.** `referenceDialerSignaling` now invalidates registration on real WSS transport loss, owns a bounded single-flight SIP.js reconnect ladder, suppresses reconnect while offline, and waits for `RegistererState.Registered` rather than treating REGISTER transmission as confirmation. Authentication rejection receives one fresh-credential retry; repeated rejection is terminal. Normal Join and RH recovery share the confirmed-registration gate, while existing dialogs remain authoritative during transport recovery. No backend/schema/telephony/V0/RT-1A/RH-1/RH-2 authority changed. Evidence: `docs/evidence/rh/rh-3c-sip-wss-reconnection-registration-confirmation.md`. Next: RH-3D adversarial / slow-network natural browser live proof.


**RH-3D — Adversarial / slow-network natural browser live proof (2026-08-15): `FOUND BLOCKER`.** Evidence-only, no source or constant changed. Live-proven: WSS loss invalidates registration truth and reconnection is automatic with **one corridor** (attempts at +0.92 s and +2.13 s, matching ladder steps 1 s / 2 s within ±20 % jitter); REGISTER send is never treated as success — after 401/401 the client made **exactly one** fresh-credential retry and reached 200 OK; the established dialog, its runtime channel and bridge membership survived the transport loss unchanged; a 39 s API outage left the call alive with **0 DELETE**, 0 INVITE and a clean resync; four offline/online transitions in 12 s produced **one** API request (the scheduled credential renewal) and no reconnect, admission, INVITE or DELETE; dead-dialog recovery ran three times with one admission, the same participant, one logical INVITE, bind + bridge, and Connected never shown before canonical binding; a 78 s media-plane outage kept work ladder-paced at the 10 s cap with 1 admission / 1 INVITE / 0 DELETE and converged to Ready after the canonical sweep; and with the transport down bootstrap returned 200 in 21 consecutive samples with **0** INVITEs. **Two client defects isolated.** (1) A rejected recovery INVITE (`488`) ends replacement attempts for the rest of the grace — SIP.js resolves `invite()` on send, so a later non-2xx never throws inside `beginRecovery()`, `awaitingRecoveryBinding` stays latched, and the awaiting-binding branch (`ReferenceDialerView.vue:347-359`) has no path that issues another INVITE; recovery failed even though the impairment cleared 67 s before expiry. (2) With no participation, a transport loss is never repaired — no WebSocket constructs across an 82 s idle outage, then 2×401 with **no** credential retry and the UI stuck on "SIP registration failed" for 100+ s. Proof gaps: the 10 s `AbortController` branch was not reached (an earlier Kamailio restart destroyed in-dialog routing so no recovery began), and contact-expiry / browser-suspension were not exercised. Environment left clean; no storms anywhere (0 DELETEs for the whole proof). Evidence: `docs/evidence/rh/rh-3d-adversarial-slow-network-natural-live-proof.md`. Next: bounded RH-3E fix for the two defects, then a narrow reproof of those corridors only.
**RH-3E — Rejected recovery INVITE re-entry and idle signaling repair (2026-08-15): IMPLEMENTED / TESTED; narrow natural live reproof pending.** The current recovery INVITE now owns an explicit signaling-attempt binding wait; only that attempt's terminal SIP failure releases the wait and re-enters the existing canonical recovery coordinator, preserving the admitted participant and RH-3B retry ladder. A SIP-established leg whose canonical binding is merely delayed remains in Recovering and cannot trigger a second INVITE. Idle transport loss now remains retryable when participation is absent: signaling emits `connecting`, preserves the existing RH-3C reconnect/registration single-flight, and resets the one-fresh-credential allowance at the independent transport/registration episode boundary rather than on every REGISTER success. Focused signaling and view tests cover 488 re-entry, stale callback fencing, no duplicate while alive, idle reconnect, same-episode auth exhaustion, and fresh retry allowance in a later episode. No backend, schema, resilience constants, or telephony authority changed. Evidence: [`docs/evidence/rh/rh-3e-rejected-recovery-invite-and-idle-signaling-repair.md`](../evidence/rh/rh-3e-rejected-recovery-invite-and-idle-signaling-repair.md). Next: RH-3E narrow natural live reproof.

**RH-3E — Narrow natural live reproof (2026-08-15): `PASSED WITH NARROW PROOF GAP`. RH-3 is COMPLETE / LIVE PROVEN.** Evidence-only, no source or constant changed; the deployed bundle was content-verified to carry the `awaitingRecoveryBindingAttemptId` fence. **Corridor 1**: a recovery INVITE rejected with `488 Media Relay Unavailable` now releases its binding wait — where RH-3D produced exactly one INVITE and then waited out the grace, RH-3E produced **17 further attempts**, all reusing the same participant with 201 admissions and **0 DELETEs**, and the first attempt after the media plane was restored reached **200 OK**, bound channel B and bridged (bridgechans 0→1) with the UI going Recovering → Connected; the INVITE delta during the binding wait was **0**, so binding latency was not turned into SIP failure. **Corridor 2**: an idle WSS loss with `participation = null` is now repaired automatically — close 1006, reconnect attempts at +0.83 s and +1.89 s (ladder 1 s / 2 s ±20 %, one corridor), REGISTER 401/401, **exactly one** fresh credential, then 200 OK — **2.80 s total, no user action**, participation still null and 0 admissions/INVITEs/DELETEs, versus RH-3D leaving the same state unrepaired for 100+ s. Narrow gaps: the RH-3B 10 s HTTP timeout remains repository-only, and a second *rejecting* auth episode did not arise naturally (inducing one would require prohibited credential/auth tampering). One non-blocking deviation: the post-488 retry cadence is a flat ~2.45 s and does not escalate to the ladder's 3/5/8/10 s steps because `recoveryRetryIndex` resets on each cycle reaching the admission — bounded by the 120 s grace, not a storm. Environment left clean. Evidence: `docs/evidence/rh/rh-3e-narrow-natural-live-reproof.md`. **RH-3 COMPLETE / LIVE PROVEN — the RH resilience track is closed.**
**RH-3F — Recovery retry escalation preservation (2026-08-15): IMPLEMENTED / TESTED; narrow natural retry-cadence reproof pending.** The retry index now belongs to the unresolved recovery episode rather than the intermediate admission request. Rejected replacement INVITEs advance the existing RH-3B ladder; binding-only polling does not advance it; successful canonical binding, explicit Leave, terminal canonical state, and a new episode reset it. No backend/schema/signaling or new resilience constants changed. Evidence: `docs/evidence/rh/rh-3f-recovery-retry-escalation-preservation.md`. Next: RH-3F narrow retry-cadence live reproof.
**RH-3 — REGISTER final-response settlement fix (2026-08-16): IMPLEMENTED / TESTED; narrow natural live reproof pending.** The reference signaling registration operation now settles from SIP.js final-response delegates as well as the existing Registerer state and lifecycle cancellation paths. Same-state accepted and rejected REGISTER outcomes no longer strand `registrationPromise`, and the existing credential renewal/auth-retry lifecycle can continue. No backend/schema/telephony changes or RH-3B/RH-3C/RH-3F behavior changes. Evidence: [`docs/evidence/rh/rh-3-registration-final-response-settlement-fix.md`](../evidence/rh/rh-3-registration-final-response-settlement-fix.md). Next: one narrow natural-browser reproof across two renewal cycles and one bounded recovery corridor.

**RH-3 — Pre-freeze simplification cleanup (2026-08-16): COMPLETE / LIVE PROVEN / SIMPLIFICATION COMPLETE / FROZEN.** The five accepted local cleanups are complete: recovery-binding cleanup and identical Ready transitions are centralized, the recovery retry maximum is derived from the existing ladder, two dead/redundant writes are removed, and failed INVITEs clear the established latch. Attempt identities, registration settlement, retry lifecycles, timing, canonical binding, and RH-3F cadence are unchanged. Focused and full automated verification passed; browser proof was not required. Evidence: [`docs/evidence/rh/rh-3-pre-freeze-simplification-cleanup.md`](../evidence/rh/rh-3-pre-freeze-simplification-cleanup.md). **Freeze RH-3; do not perform another RH audit.**
