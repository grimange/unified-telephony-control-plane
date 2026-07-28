# Implementation Roadmap

This is the executable repository roadmap: actual phase ordering, phase objectives and boundaries, current completion state, the next actionable phase, and links to evidence and ADRs. It is synchronized against the two upstream planning documents and against repository-proven state (ADRs, evidence, runbooks, tests, live proof).

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
V0  Natural login, SIP registration, and conference admission
T4  FreeSWITCH ESL adapter parity
T5  Multi-runtime convergence, failover, and recovery
C6  Call lifecycle and normalized call-control domain          [extended scope, not yet started]
C7  External trunk, route, and caller-identity authority        [extended scope, not yet started]
T6  External trunk integration and live route projection        [extended scope, not yet started]
V1  Call lifecycle, call control, and external trunk proof       [extended scope, not yet started]
A0  Reference application contract                              [extended scope, not yet started]
R0  Portfolio release
```

F0 through T1 are complete exactly as `CLAUDE.md` orders them. T2 through T5/R0 are the same five phases `CLAUDE.md` already names, in the same order. C6, C7, T6, V1, and A0 are added between T5 and R0 to carry forward the initial plan's full product scope (see "Phase-Identifier Reconciliation" below for why they sit here and not earlier).

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

V1 proves the application-neutral, capability-aware call-control and external-connectivity contract once C6, C7, and T6 exist:

```text
Authenticated originate request
  -> canonical call and call-operation records
  -> outbound route and caller-identity evaluation
  -> eligible external trunk and runtime selection
  -> Kamailio signaling execution
  -> Asterisk call execution
  -> normalized call and call-leg observations
  -> capability-aware call control (hold, resume, transfer, hangup)
  -> canonical termination and audit timeline
```

V1 is retained here so the roadmap does not silently drop the initial plan's call-lifecycle/external-trunk product scope merely because the current conference-first sequence (C5 → T0 → T1 → T2 → T3 → V0) reaches its own vertical slice first.

## Phase-Identifier Reconciliation

The initial plan and the application plan diverge on C- and T-phase numbering for capabilities beyond registration and conference. The repository's actually-built sequence (ADRs, evidence, `CLAUDE.md`, `phase-status.md`) is the tiebreaker for anything already started; the application plan's numbering is the tiebreaker for anything not yet started, because it is the sequencing-authority document.

| Conflict | Initial plan | Application plan | Repository-proven state | Resolution |
| --- | --- | --- | --- | --- |
| Registration + conference domain phase number | C5 = registration only; conference is a separate later `C8` | C5 mentioned as registration; conference later folded via `C8` reference in passing | `C5` is complete and implements **both** telephony sessions/registration-authorization *and* conferences/participants/runtime bindings in one phase (ADR-017). | Keep repository `C5` as-is (registration authorization + conference domain combined). `C8 — Conference, Bridge, and Participant Domain` from the initial plan is **superseded/merged into C5** and is not reintroduced as a separate future phase. Conference *execution* against a real runtime remains T2, unaffected. |
| Kamailio signaling phase scope and number | `T1 — Kamailio Signaling Edge and SIP-over-WSS`: broad scope including SIP UDP/TCP, dispatcher-routed destinations, one Asterisk destination, SIPp synthetic traffic | `T1 — Kamailio Signaling and SIP-over-WSS`: narrow scope, browser SIP-over-WSS registration only | Repository `T1` (ADR text in `docs/architecture/authority-boundaries.md`) implements exactly the narrow scope: `TelephonySession`-scoped credentials, Kamailio as sole registrar, WSS-only, one active Contact per signaling identity. No SIP UDP/TCP, no dispatcher, no second runtime destination, no INVITE relay, no application-dialog Record-Route path, and no SIP route to Asterisk or another RuntimeNode. | Keep repository `T1` as the narrow WSS-registration scope (already implemented and evidenced). The initial plan's broader SIP application-dialog routing concepts are **not dropped**. Internal browser/conference application-dialog routing belongs to `T3`/`V0` when the browser media path is introduced; external-trunk and general-call dispatcher/projection scope belongs to `C6`/`C7`/`T6`/`V1` once canonical calls, routes, trunks, and route decisions exist. |
| rtpengine media-plane phase number | `T2 — rtpengine Media Plane` | `T3 — rtpengine and Browser Media` | Repository `T3` is rtpengine browser media (not yet started; next after T2). | Keep repository/application-plan numbering: rtpengine is `T3`. The initial plan's `T2` label for the same capability is superseded — renumbered to `T3` with no scope change. |
| External trunk live-integration phase number | `T3 — External Trunk Integration and Live Route Projection` | Not explicitly numbered (folded into "later T-phases") | No repository phase currently claims a `T3` identifier for external trunks — repository `T3` is already claimed by rtpengine (see row above). | Cannot reuse `T3` (conflicts with rtpengine). Assigned the next unclaimed sequential T-number: **`T6`**, continuing the existing T0-T5 convention rather than inventing a new naming scheme. Scope (registration-based and IP-authenticated synthetic trunk projection, inbound/outbound route projection, caller-identity projection, credential-reference rotation, draining/disabling/retirement) is preserved verbatim from the initial plan, plus the Kamailio-dispatcher scope carried forward from the T1 reconciliation row above. |
| Call-lifecycle/call-control domain phase number | `C6 — Call Lifecycle and Normalized Call-Control Domain` | `C6 — Call Lifecycle and Normalized Call-Control Domain` (same number) | Not started; no repository conflict. | No conflict. Kept as `C6`. |
| External trunk/route/caller-identity authority phase number | `C7 — External Trunk, Route, and Caller-Identity Authority` | `C7` (same number, named in passing) | Not started; no repository conflict. | No conflict. Kept as `C7`. |
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

Objective: provide real browser audio through a runtime-neutral media path and introduce the minimum SIP application-dialog routing needed for a registered browser SIP identity to reach the selected Conference execution runtime through Kamailio and rtpengine. Implement SDP offer/answer mediation, ICE handling, DTLS-SRTP, WebRTC-to-RTP adaptation, media anchoring, media-session correlation, timeout/cleanup, media health observation, explicit RTP-related NetworkPolicies, metrics/logs, and the initial Kamailio INVITE route authority for the browser/conference path. That route authority must consume the canonical RuntimeNode eligibility projection rather than redefining placement in Kamailio: an ineligible execution RuntimeNode is excluded from new application-dialog routing, while registration eligibility remains the separate C5/T1 TelephonySession credential authority. Completion criteria: browser audio reaches the selected runtime; media is anchored through rtpengine; signaling and media state correlate; failed media sessions clean up deterministically; initial INVITE routing, runtime destination projection, new-dialog eligibility, existing-dialog behavior, Record-Route/in-dialog routing where required, automatic cutoff/restoration, and absence of direct Asterisk bypass are proven for the internal browser/conference path.

**T3-S1 repository foundation is implemented and PRODUCT_DEFECT-1 is corrected; its live relay proof remains pending, so T3 remains In Progress.** [`docs/evidence/t3/t3-s1-rtpengine-foundation-live-proof.md`](../evidence/t3/t3-s1-rtpengine-foundation-live-proof.md) recorded the former blocker: `infrastructure/docker/rtpengine/Dockerfile` built the pinned release asset name with a tilde (`…+0~mr26.0.1.19…`) where upstream publishes a dot (`…+0.mr26.0.1.19…`), causing HTTP 404 during the pinned image build. [`docs/evidence/t3/t3-s1-rtpengine-package-asset-correction.md`](../evidence/t3/t3-s1-rtpengine-package-asset-correction.md) records the bounded correction: both architecture package assignments now use the upstream `+0.mr26.0.1.19` convention, the version, tag, base-image digest, and SHA-256 checksums are unchanged, `scripts/media/config-check` rejects the tilde form and package-version drift offline, the focused guard regression tests pass, and the corrected pinned `utcp-rtpengine:dev` image builds locally without pushing or applying Kubernetes resources. T3-S1 is ready to resume the focused live relay-foundation proof from image push and Kubernetes rollout. [`ADR-020`](../decisions/ADR-020-t3-rtp-media-plane.md) fixes the RTP media-plane architecture and [`docs/evidence/t3/t3-rtp-media-preparation-audit.md`](../evidence/t3/t3-rtp-media-preparation-audit.md) records the criterion matrix, repository inventory, guard disposition, and the exact first implementation slice. [`docs/evidence/t3/t3-s1-rtpengine-foundation-implementation.md`](../evidence/t3/t3-s1-rtpengine-foundation-implementation.md) records the implemented pinned relay foundation. Decisions implemented in T3-S1: rtpengine is shared platform infrastructure in `utcp-platform` (a single-replica `Deployment`, no `RuntimeNode` or registry capability, mirroring the ADR-019 Kamailio precedent); in-cluster networking only, with ng control on ClusterIP `2223/UDP` and media on `40000–40099/UDP` bound and advertised on the Pod IP; userspace forwarding (`--table=-1`) so Pod Security Admission `restricted:v1.35` holds with no exception; a digest-pinned, repository-built image following the Asterisk precedent; one new `allow-rtpengine-media` NetworkPolicy with default-deny preserved; and no new durable tables. RTP is an explicit UDP media boundary and is never tunnelled through the HTTP/WSS `443` Traefik route, so `scripts/gateway/config-check` and `scripts/k3d/config-check` are retained. T3-S1 adds bounded guard replacements and no live SIP media routing; browser-reachable media, Kamailio INVITE route authority, and conference admission remain deferred to later T3 slices and V0.

### V0 - Natural Login, SIP Registration, and Conference Admission — Planned

The first user-facing telephony milestone (see "First User-Facing Vertical Slice" above). Uses natural browser login, no preset sessions, no injected cookies, no manual database edits, no Artisan activation command, no hidden feature gate, no runtime-specific frontend workflow. V0 must prove the complete registered-browser-to-conference path introduced by T3: `TelephonySession` credential registration remains independent from execution-runtime eligibility; new application dialogs are routed only to eligible selected Conference runtimes; existing-dialog behavior follows the T3 route contract; cutoff and restoration are automatic; the browser/conference path does not bypass Kamailio by routing directly to Asterisk.

### T4 - FreeSWITCH ESL Adapter Parity — Planned

Objective: prove the same registration, call-control, bridge, conference, and observation contracts work against a second execution runtime (`FreeSwitchEslClient`, `FreeSwitchCommandAdapter`, `FreeSwitchEventListener`, `FreeSwitchEventNormalizer`, `FreeSwitchHealthInspector`). Critical proof: the same login page, telephony-session API, SIP registration path, conference-admission API, frontend state machine, and normalized domain events; only adapter selection and runtime execution differ. Completion criteria: both nodes register independently; unsupported capabilities are reported explicitly; Kamailio can route to either runtime; V0 behavior reproduces against FreeSWITCH; no FreeSWITCH-specific branch in application-facing services.

### T5 - Multi-Runtime Convergence, Failover, and Recovery — Complete

Objective: harden runtime behavior after both runtime adapters work. Implement event-stream reconnection/replay, stale-registration expiry, orphan-channel cleanup, conference-membership reconciliation, runtime-node draining/unavailability handling, eligible-node reselection, replay-safe operations, failed-operation recovery, cross-runtime recovery where technically supported. Do not promise seamless active-call migration unless signaling/runtime/media behavior actually prove it. Completion criteria: control-plane state recovers after runtime interruption; duplicate operations remain safe; stale runtime resources are detected and reconciled; runtime-node failure behavior is explicit and observable.

**Status: Complete.** Closure record: [`docs/evidence/t5/t5-phase-closure.md`](../evidence/t5/t5-phase-closure.md) (`T5_COMPLETE`), which reconciles all 21 canonical T5 criteria to `SATISFIED` against the existing evidence corpus without rerunning any proof. T5 evidence proves the multi-RuntimeNode Asterisk topology, deterministic failover and recovery, listener ownership/liveness/degradation/automatic recovery, symmetric degraded/recovered evidence, deterministic capacity-aware placement, pending-no-capacity and automatic retry, recovery metric-event retention with scheduled pruning, and repository Namespace PSA authority reconciliation. The current Kamailio signaling-cutoff item was re-sequenced out of active T5 because T1 Kamailio is registration-only and has no runtime dialog route to cut off. The controlled live Namespace PSA proof is **complete**: `f959f00` recorded the T5-A78 corridor (declarative apply of the canonical `pod-security-labels.yaml`, all five UTCP namespaces at `restricted`/`v1.35` including the `utcp-runtime` version pins, compliant-Pod admission, `restricted:v1.35`-attributed privileged-Pod rejection, migration-Job admissibility, drift introduction and declarative correction, idempotent reapply, and full workload health) against `utcp-local`, satisfying every point of this document's own deferred live acceptance contract. The final phase-closure evidence — the last outstanding T5 item — is now recorded, so **no T5 implementation, live runtime proof, or documentation work remains**. The primary corridor corpus (`T5-A1` through `T5-A78`) remains at its historical path `docs/evidence/t2/multi-node-failover-readiness.md`; the closure document above is the canonical phase-level T5 index and explains that filing anomaly. See also [`docs/evidence/roadmap/t1-t5-roadmap-reconciliation.md`](../evidence/roadmap/t1-t5-roadmap-reconciliation.md).

### C6 - Call Lifecycle and Normalized Call-Control Domain — Planned (extended scope)

Objective: establish the canonical multi-leg call model and capability-aware call-control contract used by dialers, contact centers, IVRs, automation, conferences, and real runtime adapters. Core concepts: `Call`, `CallLeg`, `CallParticipant`, `CallOperation`, `CallObservation`, `CallRouteDecision`, `CallTermination`, `CallTimelineEntry`. Normalized lifecycle: `REQUESTED -> SELECTING_ROUTE -> ORIGINATING -> RINGING -> EARLY_MEDIA -> ANSWERED -> BRIDGED -> HELD -> TRANSFERRING -> TERMINATING -> COMPLETED / FAILED / CANCELLED`. Initial call-control operations: `originate`, `cancelOrigination`, `answer`, `hangup`, `hold`, `resume`, `bridge`, `unbridge`, `blindTransfer`, `attendedTransfer`, `redirect`, `mute`, `unmute`, `sendDtmf`, `startRecording`, `stopRecording` (not every adapter must support every operation; unsupported behavior returns an explicit capability result). Completion criteria: a call can be requested through a public UTCP API without naming Asterisk/FreeSWITCH; normalized call/call-leg state is visible via API and web UI; supported simulator call-control operations execute through the adapter contract; unsupported operations fail explicitly; duplicate/delayed/stale/out-of-order observations are handled deterministically; call termination/failure classification is auditable; no real carrier, PSTN identity, or production recording is required.

## Future External-Trunk, Route, and Caller-Identity Roadmap

### C7 - External Trunk, Route, and Caller-Identity Authority — Planned (extended scope)

Objective: create the canonical management authority for external SIP connectivity, inbound/outbound routing, caller-identity policy, runtime projection, readiness, draining, credential rotation, and retirement. Core concepts: `ExternalTrunk`, `TrunkEndpoint`, `TrunkCredentialReference`, `TrunkCapability`, `TrunkHealthPolicy`, `TrunkProjectionTarget`, `TrunkDesiredProjection`, `TrunkObservedSnapshot`, `InboundRoute`, `OutboundRoute`, `RouteConstraint`, `CallerIdentity`, `CallerIdentityPolicy`, `RouteDecision`. Administrative lifecycle: `DRAFT -> VALIDATING -> ACTIVE -> DRAINING -> DISABLED -> RETIRED`, distinct from observed health: `UNKNOWN / READY / DEGRADED / UNAVAILABLE`. Web-admin management is the primary authority; diagnostic CLI commands may inspect/retry but must not become a second management UI. Secrets are references, never returned through normal read APIs. Completion criteria: trunks/routes/caller identities are manageable through web-admin; cross-tenant visibility/mutation is rejected; credential creation/rotation never exposes stored secrets; activation, observed degradation, draining, disabling, and retirement are proven; a simulator-backed outbound route selects an eligible trunk/runtime deterministically; a simulator-backed inbound route resolves to a normalized application destination; caller-identity policy records deterministic selection/rejection reasons; configuration drift is detected and repaired through C3; no public PSTN or commercial carrier account is required.

### T6 - External Trunk Integration and Live Route Projection — Planned (extended scope; renumbered from the initial plan's conflicting `T3` — see Phase-Identifier Reconciliation)

Objective: connect the C7 trunk/route/caller-identity authority to real signaling and execution projections, and carry forward the Kamailio dispatcher-routed, SIP-UDP/TCP, and SIPp-synthetic-traffic scope that the initial plan originally placed in its own (superseded) `T1`. Use a deterministic synthetic external SIP peer (SIPp or a second isolated SIP runtime) as a trunk provider — no paid carrier account, public PSTN access, real customer caller identities, or production credentials required. Initial scope: registration-based and IP-authenticated trunk projection, inbound/outbound route projection, caller-identity projection where supported, trunk OPTIONS/adapter-specific readiness, normalized trunk registration/health observations, route/trunk eligibility updates, credential-reference rotation, draining/disabling behavior, removal of retired projections, one synthetic inbound call, one synthetic outbound call. The projected Kamailio new-dialog destination set must be derived from UTCP canonical call intent, route/trunk decision, and eligible runtime selection. Kamailio executes the route but must not become tenant, trunk, caller-identity, or runtime-eligibility management authority; ineligible, fenced, unavailable, retired, or otherwise disallowed destinations must be absent or disabled in the projected route set, and restoration follows canonical recovery/projection. Completion criteria: one registration-based and one IP-authenticated synthetic trunk become ready; inbound/outbound route decisions are deterministic and auditable; a synthetic outbound call uses the selected route/trunk/Asterisk runtime; a synthetic inbound call resolves to a normalized UTCP destination; trunk degradation removes new-call eligibility per policy; draining blocks new calls while existing calls complete; credential rotation reconciles without exposing secrets; disabling/retirement remove active projections; no manual runtime-file edit is required for normal lifecycle operations.

### V1 - Call Lifecycle, Call Control, and External Trunk Vertical Slice — Planned (extended scope)

The second vertical slice (see "Second Vertical Slice" above). Exit criteria: the request does not name Asterisk, PJSIP, ARI, Kamailio dispatcher IDs, or trunk configuration sections; selected route/caller-identity/trunk/runtime are inspectable with decision reasons; ringing/answer/bridge/termination states are proven; at least one supported mid-call control is proven; one unsupported control returns an explicit capability result; duplicate/delayed runtime observations do not corrupt terminal state; a degraded/draining trunk changes new-call selection deterministically; the complete timeline is visible through API and web UI.

### A0 - Reference Application Contract — Planned (extended scope)

Objective: prove that applications can build on UTCP call, route, trunk, registration, and conference contracts without owning infrastructure or vendor integrations, via a small reference dialer (not a production predictive dialer). Application responsibility: campaign membership, contact selection, call-request timing, simple retry policy, disposition. UTCP responsibility: authorized caller identity, route/trunk/runtime selection, telephony operation execution, normalized call lifecycle, call-control capability handling, infrastructure observations and audit history. Explicitly out of scope: predictive pacing, compliance automation, real-PSTN requirement, production lead management, billing, full reporting, answering-machine detection, large-scale campaign scheduling. Completion criteria: dialer uses public UTCP contracts only; dialer does not directly call ARI/AMI/ESL/Kamailio management interfaces or runtime configuration files; dialer does not store or manage trunk credentials; switching runtimes or changing eligible trunks/route priority requires no dialer code change; simulator demonstration works without telephony hardware; optional live demonstration works through V1 contracts.

## Next-Phase Ordering

T2 is complete, and T5 hardening is now complete as well (closure record: [`docs/evidence/t5/t5-phase-closure.md`](../evidence/t5/t5-phase-closure.md)), both without advancing `UTCP_PHASE` beyond T1 — the marker is a CI-guarded authoritative-completed-phase pin whose advance would require updating all six guards in one coordinated commit. The next actionable target is a bounded T3 preparation audit: T3 is not yet dependency-ready, lacking an rtpengine/media ADR, a version pin, manifests, and RTP NetworkPolicies, and `scripts/security/config-check` and `scripts/gateway/config-check` currently assert the absence of exactly the RTP surface T3 introduces. T3/V0 browser media and internal application-dialog routing therefore remain planned future work, as do T4 FreeSWITCH parity and the C6/C7/T6/V1/A0 extended-scope phases. C6/C7/T6/V1/A0 remain positioned after T5 and before R0 (see Phase Order); they are not scheduled ahead of T3-T5/V0 because no repository evidence, ADR, or `CLAUDE.md` text currently orders them earlier, and doing so would contradict `CLAUDE.md`'s binding sequence for phases already in flight.

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
