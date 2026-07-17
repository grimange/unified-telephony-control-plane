# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository state

This repository has completed the governance foundation, minimal application skeleton, deterministic container build foundation, Docker Compose core platform, CI quality baseline, local k3d cluster foundation, Kubernetes application base, Traefik/Gateway API edge, Kubernetes network/security boundaries, Kubernetes observability foundation, C0 control-plane application kernel, C1 identity/tenancy/authorization foundation, C2 runtime registry, C3 runtime engine, C4 deterministic simulator adapter, C5 telephony-session/conference domain, T0 Asterisk ARI adapter corridor, and T1 Kamailio SIP-over-WSS signaling. `UTCP_PHASE` is `T1`. T1 is complete: TelephonySession-scoped signaling credentials are issued through the authenticated telephony API with HA1 stored as secret-equivalent verifier material, Kamailio is the sole live registrar with least-privilege PostgreSQL authentication/usrloc roles behind a trusted WSS route, the fenced `kamailio-registration-observer` process (proven to survive a lease-holder Pod kill with no lost checkpoint progress) projects sanitized `usrloc` snapshots through C3 receipts/normalizer/projection into automatic registration reconciliation, the canonical User & Access Management UI exposes safe signaling metadata and one-time credential issuance with no permanent SIP account or provider-node binding, natural browser acceptance and a two-tenant non-platform-member isolation proof both passed against real login/session flows with no injected cookies, and disposable Kamailio Compose compatibility (`make compose-proof`) passed with verified cleanup. See `docs/roadmap/phase-status.md`, `docs/decisions/ADR-019-kamailio-signaling-registration-authority.md`, and `docs/evidence/t1/kamailio-sip-over-wss-signaling.md`. T1 does not add Asterisk SIP signaling, media, or conference execution; those remain T2 and T3. The canonical integrated local runtime is the `utcp-local` Kubernetes cluster. Docker Compose remains as a disposable compatibility proof and explicit optional debug mode under `infrastructure/compose/`; it must not be treated as a continuously running parallel UTCP runtime. The repository includes a Laravel API in `apps/api/`, a Vue/Vite/TypeScript administration shell in `apps/web/`, local production-oriented Docker image definitions under `infrastructure/docker/`, repository-managed local k3d files under `infrastructure/k3d/`, Kustomize-managed Kubernetes application manifests, Helm-managed Traefik configured through Gateway API, repository-managed Pod Security plus NetworkPolicy controls, Helm/Kustomize-managed Prometheus, Loki, Grafana, Alertmanager, kube-state-metrics, and Alloy in `utcp-observability`, PostgreSQL-backed kernel primitives under `App\ControlPlane`, PostgreSQL-authoritative users, tenants, memberships, built-in roles/capabilities, first-party sessions, active-tenant selection, C0-backed audit integration, PostgreSQL-authoritative tenant-owned `RuntimeNode` records with normalized endpoints, encrypted write-only credentials, declared runtime capabilities, desired lifecycle state, backend-driven runtime-management catalogs, per-node adapter-configuration dispatch, scoped runtime evidence and history APIs, C3-owned observed-state projection, a C4 deterministic runtime-neutral simulator adapter (`App\Simulator`) selected only through the existing authenticated runtime-registry API, and C5 PostgreSQL-authoritative telephony sessions, conferences, runtime bindings, conference participants, runtime-neutral conference operations, simulator-backed conference observations, projection, reconciliation, and expiry automation. It still has no tracing, media, or completed T2-T5/V0 vertical slice. T0 does not implement ConfBridge, channel control, C5 conference operations on Asterisk, SIP, PJSIP, RTP, media, or browser calling. T1 does not implement Asterisk conference execution, rtpengine media, or external trunks.

Use the root `Makefile` for normal verification. `make help` lists the current top-level commands, including K0/K1/K2/K3/K4 lifecycle targets.

## Governing documents — read before doing any work

- **`AGENTS.md`** (repo root) — the binding working-method contract: phase discipline, authority boundaries, git safety, verification requirements, subagent coordination, and the required final-report format. Read this first; it is not optional guidance.
- **`docs/unified-telephony-control-plane-initial-implementation-plan.md`** — the full implementation roadmap: product boundary, architecture decisions, repository layout, phase-by-phase deliverables and exit criteria (F0–R0), and the AI coder execution contract.
- **`docs/unified-telephony-control-plane-codex-blueprint.md`** — the design rationale behind the `.codex/agents/`, `.agents/skills/`, and `docs/ai/templates/` scaffolding (Codex-specific, but the architecture rules it encodes apply equally here).

## Project

UTCP (Unified Telephony Control Plane) is a vendor-neutral telephony control plane — the platform layer beneath dialers, contact centers, IVR systems, and voice automation apps. It owns desired state, tenant/operator policy, runtime registration, reconciliation, health history, and audit — not live protocol execution.

### Authority boundaries (do not blur these)

| Concern | Authority |
|---|---|
| Business/tenant policy, desired state, reconciliation decisions | UTCP (Laravel API + PostgreSQL) |
| Users, tenants, memberships, roles, capabilities, account state | PostgreSQL through UTCP identity services |
| Runtime nodes, endpoint configuration, encrypted credential metadata, declared runtime capabilities, desired lifecycle state | PostgreSQL through UTCP runtime registry services |
| Runtime operations, outbox dispatch, raw event receipts, observations, projection checkpoints, reconciliation state, leases, and fencing | PostgreSQL through UTCP runtime engine services |
| Browser authentication | First-party Laravel sessions; Redis may hold transient session state only |
| Persistent business records | PostgreSQL (canonical) |
| Queues, locks, transient projections | Redis (never canonical) |
| Real-time UI notifications | Reverb/WebSockets (notifications only, never business authority) |
| HTTP, HTTPS, application WebSocket ingress | Traefik |
| Live SIP signaling | Kamailio |
| RTP/SRTP media relay | rtpengine |
| Call execution | Asterisk or FreeSWITCH (behind adapter contracts) |
| Workload placement | Kubernetes (must not leak into the telephony domain model) |

A CLI command is for bootstrap/diagnostics/recovery/migration/verification only — it must never become a second management authority alongside the web/API. `utcp:user-access:reset-password` is a C1 break-glass recovery command for one existing user: it generates the temporary password internally, records bounded expiration, revokes existing sessions, and sends the user back through normal login plus forced password change. It must not grow user creation, role, membership, tenant, permission, account-activation, password-reveal, or bypass behavior.

### Core technical decisions

- Start as a **modular monolith** (one Laravel API, one Vue admin app, workers/scheduler from the same backend image) — not microservices.
- The canonical integrated local runtime is **k3d/Kubernetes** through the `utcp-local` cluster. **Docker Compose** must keep working for disposable container/integration proof and explicit isolated debug sessions, but it must not start automatically, share Kubernetes PostgreSQL/Redis/queues, or become a fallback when Kubernetes is unavailable.
- Kustomize for UTCP-owned Kubernetes resources; Helm for third-party infra (e.g. Traefik).
- Telephony runtime location is neutral (`RuntimeNode`, `RuntimePool`, `SignalingGateway`, `MediaRelay`, `DeploymentTarget`, `DesiredProjection`, `ObservedSnapshot`, `ReconciliationRun`) — Kubernetes concepts must not be encoded into the core runtime-node model.
- Application-kernel first: C0 provides runtime-neutral operations, leases, fencing, outbox, inbox, idempotency, audit, context, and event envelopes. The deterministic simulator is C4, after runtime registry and command/event/reconciliation contracts exist.
- Identity authority is server-side: C1 stores users, tenants, memberships, built-in roles, capabilities, assignments, and account status in PostgreSQL; the frontend renders server-computed capabilities and must not become the authorization authority.
- Runtime registry authority is server-side: C2 stores tenant-owned `RuntimeNode` configuration, endpoints, write-only encrypted credentials, declared capabilities, per-node adapter configuration, and desired lifecycle state in PostgreSQL. Runtime families, adapter keys, adapter-supported capabilities, adapter endpoint requirements, and adapter-configuration availability are served from backend catalog authority. The frontend must not own a checked-in runtime or capability catalog. C3 changes observed state only through projection authority. Neither C2 nor C3 connects to runtimes or infers registry state from Kubernetes Pods.

### Phase dependency order

Work proceeds strictly in this order (see the roadmap doc for full deliverables/exit criteria per phase); do not skip ahead or implement multiple phases in one task:

```
F0 Repository contract → F1 App skeleton → F2 Container images → F3 Docker Compose
 → F4 CI baseline → K0 k3d cluster → K1 K8s app base → K2 Traefik/Gateway API
 → K3 Security boundaries → K4 Observability → C0 Control-plane application kernel
 → C1 Identity/tenancy/authorization → C2 Runtime registry → C3 Command/event/reconciliation
 → C4 Deterministic simulator → C5 Telephony-session/conference domain
 → T0 Asterisk ARI → T1 Kamailio SIP-over-WSS → T2 Asterisk conference
 → T3 rtpengine browser media → V0 Natural login/SIP REGISTER/conference admission
 → T4 FreeSWITCH ESL parity → T5 Convergence/failover/recovery → R0 Portfolio release
```

C0 is the application-kernel foundation. C1 is the identity, tenancy, session, and authorization foundation. C4 later proves the deterministic simulator against established contracts, and T5 later proves deterministic multi-runtime convergence/failover.

The first user-facing telephony milestone is V0: natural login -> SIP REGISTER over WSS through Traefik/Kamailio -> conference admission through UTCP -> Asterisk conference execution -> media through rtpengine -> UI shows `REGISTERED` and `CONFERENCE_JOINED`. Do not begin V0 during infrastructure phases.

### Explicit non-goals (do not build these prematurely or at all in the initial roadmap)

Predictive dialing, telecom billing, lawful intercept, full SBC functionality, PSTN/production trunk integration, multi-region production Kubernetes, production secrets management/cert automation, answering-machine detection, large-scale campaign scheduling.

## Working method (see `AGENTS.md` for the full contract)

- One bounded phase/subphase per task; inspect the repo and roadmap before editing; report current phase state and any roadmap/implementation conflicts first.
- Smallest defensible implementation — no speculative future-phase scaffolding, no hidden environment gates or allowlists.
- Every implementation task ends with the report format defined in `AGENTS.md` (`Verdict`, `Current State`, `Implemented or Confirmed`, `Files Changed`, `Verification Performed`, `Tests Passed`/`Failed`, `Unresolved Proof Gaps`, `Deferred Work`, `Operator Required Before Next Prompt`). Do not claim a phase complete without proof of its exit criteria.
- Never commit or push unless explicitly asked; never copy code/config/schemas from the APNTalk repository or any other employer/client repo.
