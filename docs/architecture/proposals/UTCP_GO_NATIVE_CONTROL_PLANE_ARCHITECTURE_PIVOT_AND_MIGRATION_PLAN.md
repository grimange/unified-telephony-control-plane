# Go-native control plane architecture pivot and migration plan

Assignment: `UTCP_GO_NATIVE_CONTROL_PLANE_ARCHITECTURE_PIVOT_AND_MIGRATION_PLAN`.
Date: 2026-10-21. Execution role: ARCHITECT. Worker: OpenAI Codex;
requested configuration GPT-6 / High; professional role Principal Go Platform
Architect / Distributed Systems Architect / Telecom Infrastructure Architect.

## Executive decision

**UTCP_GO_NATIVE_ARCHITECTURE_READY_FOR_ADOPTION.** The operator-selected target
is one Go modular monolith with explicit process roles, PostgreSQL authority,
Vue administration and the existing Kubernetes and telephony engines. Laravel
is the current implementation and migration oracle, not a permanent dependency.
This is an implementation-ready proposal, not adopted architecture or runtime
implementation. The target language is settled; the plan resolves package,
security, persistence, protocol and authority-transfer choices.

Select shared Go packages with **separate credential-scoped process roles**.
Canonical services use in-process interfaces/transactions. Keep Kubernetes IO
separate from browser/API and database privileges, retaining only the narrow
Go-to-Go execution/reservation boundary justified by that separation. Eliminate
the Laravel claim RPC when its canonical responsibility moves; do not discard
UID/RV, ownership, generation or unknown-outcome protections.

Implement and independently review isolated vertical slices before a fenced
live transfer. Do not split tightly coupled canonical transaction ownership by
route. No runtime, database, Kubernetes resource, accepted ADR or canonical
roadmap state changes in this assignment.

## Repository baseline and authority

Starting/final branch: `main`. Starting/final HEAD:
`5e028b9ab3d96f3430bf715f89d66ab19a758761`.
Starting state: no tracked modifications, no staged files, **17 untracked files**
from the canonical lifecycle foundation. The earlier 205 E1–GN-G2 artifacts are
preserved by that HEAD. No commit/push is performed here. A SHA-256 inventory of
all 1,571 pre-existing Git-visible regular files was captured before writing;
final preservation is checked against that inventory. New files are documentation
only. See final validation for resulting inventory/counts.

Repository authority: root/path-local AGENTS; [phase ledger](../../roadmap/phase-status.md);
accepted [ADR-001](../../decisions/ADR-001-modular-monolith.md),
[ADR-007](../../decisions/ADR-007-postgresql-canonical-redis-transient.md),
[ADR-008](../../decisions/ADR-008-container-process-contract.md),
[ADR-013](../../decisions/ADR-013-control-plane-application-kernel.md),
[ADR-014](../../decisions/ADR-014-postgresql-identity-tenancy-and-session-authentication.md),
[ADR-015](../../decisions/ADR-015-runtime-node-registry-authority.md),
[ADR-016](../../decisions/ADR-016-runtime-engine-authority.md), and provider/domain
ADRs 017–033 as applicable. [Architecture overview](../overview.md) and
[authority map](../authority-boundaries.md) describe current boundaries.

The new [Go-native ADR](go-native-control-plane-adr-proposed.md) is **PROPOSED /
AWAITING OPERATOR ADOPTION**. The earlier
[adapter ADR](go-kubernetes-infrastructure-adapter-adr-proposed.md) remains
**PROPOSED / NOT ADOPTED**. Its permanent Laravel target is superseded only upon
adoption of the successor; its proof and safety contracts are preserved.
Accepted ADRs are not edited. Current-State Ledger Impact: **NO_CHANGE_REQUIRED**:
this is a proposal, preserves canonical RMA-E next action and accepted V1/K5, and
does not adopt a new migration track. The proposed CP0–CP6 track is specified
without changing the current ledger.

## Deliverable navigation

| Artifact | Purpose |
| --- | --- |
| [Responsibility matrix](go-native-control-plane/laravel-to-go-matrix.md) | All material PHP modules, tables/dependencies, contracts, tests, evidence, Go targets and stage parity |
| [Module blueprint](go-native-control-plane/module-blueprint.md) | Concrete tree, dependency direction, roles, library choices, startup/shutdown and testing |
| [PostgreSQL authority contract](go-native-control-plane/postgresql-authority-contract.md) | Schema reuse, locking, migration adoption, durable dispatch and authenticated receipts |
| [Migration roadmap](go-native-control-plane/migration-roadmap.md) | CP0–CP6 scopes, dependencies, authority, acceptance and rollback |
| [Proposed ADR](go-native-control-plane-adr-proposed.md) | Adoption-ready architecture decision and supersession relationship |
| [First kernel assignment](go-native-control-plane/GO-CP1-canonical-kernel-assignment.md) | Complete single ready-to-copy implementation prompt |

## Current Laravel coverage and source findings

The [matrix](go-native-control-plane/laravel-to-go-matrix.md) covers 249 PHP app
files, 63 migrations, 80 test PHP files, four route files, configuration/bootstrap
and frontend contracts. Source inventory counts are not accepted feature counts.
There are 110 explicit web route declarations, four API route declarations and
framework-generated broadcast authorization. Business APIs live in
[routes/web.php](../../../apps/api/routes/web.php), not just
[routes/api.php](../../../apps/api/routes/api.php). Reproducing the latter alone
would miss identity, RuntimeNodes, calls, conferences, sessions, routing, recording,
admin and operational read APIs.

Material responsibility groups and ownership are:

- Identity/tenancy: users, normalized identities, suspension/password lifecycle,
  sessions, memberships, capability catalogs, platform/tenant roles and active
  tenant. Go `identity` preserves server-derived authorization and safe projection.
- Kernel: runtime operations/attempts/leases, retry/terminal state machines,
  inbox/outbox/idempotency/audit, observations/checkpoints/connection epochs,
  reconciliation and listener ownership. Go `kernel` reuses the same canonical
  records; no independent Go event processor may run alongside PHP for a source.
- Registry/infrastructure: RuntimeNode identity, desired config, encrypted runtime
  credentials/endpoints, capability evidence, capacity/failure-domain admission,
  host visibility/placement, drain/maintenance, provisioning/convergence/fencing/
  restoration/deprovisioning. Go `registry`/`infrastructure` separate policy from
  Kubernetes effects. Existing Deployment effects do **not** cover Node maintenance.
- Telephony/signaling: sessions, conferences/participants, calls/legs, bindings,
  destination/routes, external trunks, SIP credentials, signaling projection,
  provider-normalized commands/events and browser participant legs. Go owns
  canonical desired state; Kamailio/rtpengine/Asterisk/FreeSWITCH keep live execution.
- Recording: implemented capture lifecycle, canonical capture reference, artifacts,
  archive targets and encrypted credential references. Preserve actual RMA-A–D;
  RMA-E–H are not newly implemented migration scope.
- Background/API: 31 Artisan declarations plus framework scheduler/queue/Reverb
  roles, expiry/retention/recovery jobs, all route resources, metrics and broadcast
  invalidations. Preserve due-time eligibility and operation IDs, not CLI as a
  second management UI. A real queued Asterisk participant-binding retry must be
  drained/converted; Go must not deserialize arbitrary PHP queue jobs.

Configuration defaults are not deployed state: session/queue defaults can be
`database`, while environment-specific runtime configuration uses Redis. Redis
remains transient. Provider-owned Kamailio registrar tables and application
projections/views/grants have separate authority; table presence does not make
the application the registrar writer. The matrix names dynamic routing tables,
which a simple literal Schema::create search could otherwise omit.

## Go component reuse and target boundaries

Existing source:
[observer module](../../../components/kubernetes-observer/go.mod),
[effects module](../../../components/kubernetes-effects/go.mod),
[v1 effects](../../../components/kubernetes-effects/engine.go),
[v2 lifecycle](../../../components/kubernetes-effects/lifecycle/engine.go),
[v3 active profiles](../../../components/kubernetes-effects/active/engine.go).
Current modules use Go 1.25.x and Kubernetes libraries v0.35.3; the verified SDK
baseline is Go 1.25.8, not an authorization for an unpinned upgrade.

Reuse normalization, pagination/410 recovery, deterministic projection, closed
schemas/fingerprints, UID/RV patches, generation barriers, inert creation,
conditional delete, mTLS/rotation, limits and observe-first recovery. Initially
import the existing local modules through reviewed pinned local replacements in
the new isolated application module; keep their verifiers executable. Relocate
into application `internal/adapters/kubernetes` only in a separate behavior-
preserving packet after tests remain identical. Do not copy divergent engines.
No source move or dependency edit occurs now.

Existing `Verifier` interfaces are the integration seam. Replace the closed
PHP claim callback with shared Go canonical verification for ordinary in-process
uses and a **new versioned reserve/consume seam** at the isolated executor.
Existing action/result wire versions stay available at that boundary and as test
oracles. Typed internal services need not serialize JSON or call HTTP to each
other. A shared language does not make current claims, policy or credential
boundaries disappear.

The [blueprint](go-native-control-plane/module-blueprint.md) selects standard HTTP,
pgxpool, module-owned SQL, Goose SQL migrations, explicit composition, PostgreSQL
leased workers, bounded Pusher-compatible realtime and provider-specific adapters.
It rejects microservices, Operators/CRDs, generic manifest endpoints, a new queue
engine, framework-scale abstractions and all-in-one privileged API processes.

## Canonical storage, dispatch and receipts

Keep the verified tables and invariants. Go must preserve 32-hex identifiers,
existing operation statuses, lease/attempt fencing, append-only audit, transactional
outbox and incarnation history. The [authority contract](go-native-control-plane/postgresql-authority-contract.md)
selects READ COMMITTED with consistent locks, exact BIGINT decimal strings,
bounded transaction retries, no external IO inside a transaction, and explicit
cleanup on cancellation. A new migration tool cannot claim safe existing-database
adoption without catalog/trigger/view/grant verification.

Fresh claim verification is point-in-time authorization. The proposed protocol
adds reserve → consume → dispatch → receipt/result resolution, bound to exact
attempt/envelope/executor and committed before external dispatch. Lost consume
responses never grant another write. One ordinary unresolved slot per incarnation
bounds duplicates; a serialized higher-generation recovery-barrier exception
allows progress while retaining old unknown delivery history. Create uncertainty
has separate inert late-object containment and cleanup requirements.

Authenticated challenge-bound observation receipts establish exact UID evidence,
effect/marker state and resource absence through trusted observations. Use mTLS
issuer identity plus signed retained evidence, replay/expiry checks and current
canonical validation. Bulk list snapshots are not binding receipts; a successful
DELETE is not absence. PostgreSQL cannot retract an in-flight Kubernetes request
or atomically commit a Kubernetes patch. Delayed older effects may apply before
a newer barrier; after that barrier conditional predicates fence old writes.

## Browser identity, encryption and HTTP compatibility

Source contracts:
[AuthController](../../../apps/api/app/Http/Controllers/Identity/AuthController.php),
[session middleware](../../../apps/api/app/Http/Middleware/EnsureIdentitySession.php),
[identity projection](../../../apps/api/app/Identity/IdentityProjectionService.php),
[Vue HTTP client](../../../apps/web/src/api/platform.ts),
[bootstrap](../../../apps/api/bootstrap/app.php),
[session config](../../../apps/api/config/session.php).

Preserve `/api/v1/auth/csrf` JSON `csrf_token`, same-origin credentials and explicit
`X-CSRF-TOKEN`; no JWT replacement for first-party browser sessions. Go middleware
must preserve request IDs, trusted proxy/IP policy, JSON validation shapes and
status codes, unauthenticated `401`, authorization/tenant semantics and resource-
scoped `404` protection. Do not normalize all domain errors to one new envelope.
Capture route-by-route golden fixtures including pagination, nullability, decimal
strings, timestamps, `202` asynchronous results and idempotency behavior.

Select **operator-approved forced reauthentication at identity handover** rather
than a PHP session deserializer. Existing encrypted/serialized Laravel sessions
are not a stable language-neutral contract. New Go sessions use random 256-bit
opaque IDs with server-side hashed ID records in a separately versioned PostgreSQL
browser-session table, preserving configured expiry and session-version checks.
Use the configured cookie identity/path/domain, Secure in HTTPS, HttpOnly,
SameSite=Lax and CSRF tokens bound to the session. Expire the old cookie, rotate
session/CSRF at login/logout/password change, return the current session projection
shape and force a CSRF refresh after reauthentication. Test old cookie rejection,
fixation, revoked password/session versions, active-tenant invalidation and old
WebSocket closure. This policy is proposed for adoption, not executed here.

Preserve login's five-attempt/60-second email+IP throttle and generic invalid-
credential `422`, including inactive users and expired temporary passwords. Redis
may hold transient throttle counters; loss must not grant canonical access.
Capability enforcement remains server-side and scoped to current membership and
active tenant. Keep temporary-password change restrictions and suspension checks
on every authenticated request; a stale capability projection in Vue grants no
permission.

Use `golang.org/x/crypto/bcrypt` for existing bcrypt verification, with independent
PHP `$2y$`/Go positive/negative vectors, Unicode/byte-length and cost tests. Do not
claim all deployed hashes are bcrypt from source defaults alone: a future safe
metadata-only inventory must identify unsupported hash formats and block transfer
until supported or an explicit reset policy is approved. Avoid an incidental
password-algorithm change. Go's [bcrypt documentation](https://pkg.go.dev/golang.org/x/crypto/bcrypt)
has byte-length limits that require compatibility tests, not assumptions.

Runtime/trunk/archive credentials use Laravel `Crypt::encryptString`. Preserve
compatibility using a narrowly tested Laravel-envelope decoder with current and
previous keys: base64 JSON with iv/value/mac/tag, configured AES-256-CBC, validated
key length/MAC before decrypt and strict padding, no PHP object deserialization.
[Laravel's implementation](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Encryption/Encrypter.php)
is the protocol reference; cipher/key configuration must be inventoried before
cutover. Unsupported deployed cipher/hash formats fail the preflight rather than
silently rendering credentials unusable. Synthetic independent PHP/Go encryption,
tamper and key-rotation fixtures are mandatory. Keep APP_KEY/keyring in the secret
boundary; never dump plaintext to migrate. New envelope formats/rotation are
separate forward-versioned work; retain legacy decryption until data rotation is
independently verified. This source review is not a newly executed crypto test.

## Vue realtime and WebSocket compatibility

[Channels](../../../apps/api/routes/channels.php),
[Vue realtime](../../../apps/web/src/realtime/runtimeNodeRealtime.ts) and
[event classes](../../../apps/api/app/Events) establish four private channel families:

| Private tenant channel | Event |
| --- | --- |
| `tenant.{tenantId}.runtime-nodes` | `runtime-node.operational-state.changed` |
| `tenant.{tenantId}.conferences` | `conference.operational-state.changed` |
| `tenant.{tenantId}.runtime-operations` | `runtime-operation.operational-state.changed` |
| `tenant.{tenantId}.runtime-reconciliations` | `runtime-reconciliation.operational-state.changed` |

Select a Go implementation of the **required Pusher protocol 7 subset** on
`coder/websocket`; retain Echo/pusher-js. Implement `/app/{key}` connection framing,
connection-established/socket ID, private subscribe/auth, subscription success,
ping/pong, safe errors, string-encoded JSON data and existing event names/payloads.
Keep `/api/broadcasting/auth` session/CSRF/membership/capability authorization and
[Pusher auth signatures](https://pusher.com/docs/channels/library_auth_reference/auth-signatures/).
No presence/client-event protocol is required by inspected frontend usage; reject
unsupported frames. The [protocol specification](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/)
remains the interoperability oracle; a WebSocket library alone is insufficient.

Reverb may remain a transitional runtime until Go protocol/browser parity passes;
its final replacement is the Go realtime role. Redis fanout is transient and
notifications trigger canonical API refresh. Preserve reconnect/resync behavior;
missed broadcasts cannot lose durable state. Bound origins, messages, subscriptions
and connection lifetimes; membership/suspension/session-version revocation must
close or reauthorize existing private subscriptions. Test with actual Vue/Echo,
not only a homemade socket client. Do not claim arbitrary Reverb compatibility.

## Telephony domain and provider migration

Keep provider differences explicit. Asterisk ARI HTTP commands/event WebSocket,
profile/readiness translation, ownership checks, reconnect backoff and leased
connection epochs become one Go adapter. Translate normalized events through the
same inbox/observation/checkpoint and domain transaction contracts. FreeSWITCH ESL
needs framed socket/event protocol, authentication, cancellation/disconnect and
reconnect/epoch handling, not an ARI imitation. Preserve its actually supported
commands/readiness; **FreeSWITCH domain fencing/restoration remains unsupported**
even though its Deployment projection is implemented.

Sessions/conferences/participants migrate with their capacity/admission, binding,
recovery and asynchronous operation state. Calls/CallLegs retain canonical answer,
termination reason/intent and monotonic late-event behavior; a second provider
event processor cannot independently change these records. Destination/routing,
external trunk credential and registration projections retain signaling ownership
and purpose-specific addressing. Kamailio owns live SIP routing/registrations;
rtpengine owns RTP/SRTP relay. Browser WebRTC contracts, SIP identity and existing
Traefik HTTPS/WSS boundary remain. Do not add a SIP stack or media engine to Go.

Recording migration preserves canonical `utcp:capture/<RecordingSession.id>`,
provider-specific capture translation, channel-less event correlation, store-
preserving stop, one artifact per recording and tenant-correct archive credential
references. Preserve RMA-E pending scope; a migration is not an excuse to implement
new storage/archive behavior. Any natural-live proof must be newly authorized and
matched to affected capability/provider, not inferred from Kubernetes acceptance.

## Single-authority transfer and rollback

Use the [roadmap](go-native-control-plane/migration-roadmap.md) as the proposed
sequence: CP0 adoption; CP1 kernel; CP2 identity and compatibility harness; CP3
registry/infrastructure; CP4 telephony/signaling; CP5 recording/remaining;
CP6 controlled backend transfer. HTTP compatibility begins before domain migration
and grows with each slice, rather than waiting until the final stage.

Database sharing never implies safe dual writes. Before any transfer name the
sole table/aggregate writer, transaction cohort, migration owner, event producer,
consumer/inbox key, listener epoch owner, queue/scheduler owner and credential.
Read-only Go comparison may not claim production leases or dispatch outbox.
During live transfer stop/drain old roles and open transactions, revoke old DML/
DDL rights, transfer compatible work and prove effective denial. A shared old DB
login cannot provide a real cutoff. Retain PostgreSQL policy/history throughout.

Kubernetes transfer still requires all five Deployment corridors, credential-level
PHP cutoff, quiescence, unknown-outcome recovery, Node-maintenance treatment,
external autoscaler/replica-writer policy and compatible rollback. `paused: true`
is rollout control, not suspension. Inert creation remains zero-replica closed
pause template, followed by one-way UID binding then explicit approved active
projection. Existing real tests do not establish arbitrary future telephony
startup readiness or immunity to another authorized replica writer.

Rollback before transfer removes isolated Go only. After effects, rollback must
be a fenced transfer to a conditional-compatible implementation; never reactivate
obsolete unconditional PHP writers or restore a DB snapshot independently of
external state. Expand-only schema retains old readers/history through the rollback
window. Durable provider side effects and credential issuance may require forward
reconciliation, not binary reversal.

## Evidence reuse and limits

| Evidence | Inherited demonstrated scope | Required Go acceptance |
| --- | --- | --- |
| [GN-G0](UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF.md) | v1 schemas, fixtures, fake/HTTP feasibility, conditional-convergence model | Preserve exact wire/field/error contracts; new private protocols separately versioned |
| [GN-G1](UTCP_GN_G1_READ_ONLY_GO_INFRASTRUCTURE_OBSERVATION_PARITY.md) | Node/Pod acquisition, pagination/410, safe projection, mTLS, PHP observation parity | Rerun existing verifier after integration; retain v1 deletionTimestamp limitation until versioned |
| [GN-G2](UTCP_GN_G2_CONDITIONAL_KUBERNETES_DEPLOYMENT_EFFECTS.md) and [real API](UTCP_GN_G2_REAL_KUBERNETES_API_CONDITIONAL_EFFECT_VERIFICATION.md) | Conditional Deployment patch, UID/RV, barriers, error Status decoding, concurrency, unknown results and RBAC on disposable API server | No inference of deployed writer transfer; reproof if mutation semantics change |
| [Lifecycle V2](UTCP_GN_G2_LIFECYCLE_V2_CONDITIONAL_DEPLOYMENT_MANAGEMENT.md) | Inert creation/projection, exact delete/finalizer/replacement behavior | Retain late-create containment and authenticated canonical decision requirements |
| [Active profiles](UTCP_GN_G2_ACTIVE_RUNTIME_PROJECTION_PROFILES_AND_FIVE_CORRIDOR_PARITY.md) | Fixed Asterisk/FreeSWITCH projections, fingerprints, isolated five-corridor parity and harmless Pod/ReplicaSet pause evidence | Actual provider projections used zero replicas; not provider startup/call readiness. Image/config publication and current profile limitations remain |
| [Canonical foundation](UTCP_GN_G2_CANONICAL_LIFECYCLE_AND_CLAIM_FOUNDATION.md) | Real PostgreSQL lifecycle/generation/claim races, uncertainty/restart, audit/outbox and PHP/Go test mTLS bridge | Translate to Go and add durable reservations/authenticated receipts; no production callback currently exists |
| [Matrix live-evidence index](go-native-control-plane/laravel-to-go-matrix.md) | Accepted C0–C6, K5A–E, V1, T4/T5 and RMA-A–D within recorded environments | Fresh scope-relevant acceptance after actual migration; K5F and RMA-E–H stay pending |

No Go/PHP runtime test, Docker process, PostgreSQL or Kubernetes action is executed
for this architecture assignment. Source inspection and documentation validation
are new evidence; runtime test counts in prior reports remain **inherited**.
SQLite tests remain useful behavioral oracles but do not prove PostgreSQL races.
No new scale/resource/latency measurement is claimed.

## Risks, decisions and next work

Material engineering risks are crypto/session compatibility, exact schema adoption,
dispatch/receipt crash windows, legacy deployment adoption, immutable configuration
publication, Node maintenance not yet covered by Go Deployment effects, provider
reconnect/event timing, and cutoff of old DB/Kubernetes/listener authority.
These have concrete stage gates, not indefinite architecture blockers.

Only material operator decisions: adopt the proposed successor ADR and its
security/session/migration policies; authorize each future production schema/
credential/route/worker transfer and live proof. Ordinary implementation/library
pinning and test fixture choices belong to implementers. Independent reviewer
acceptance is distinct from operator deployment authorization.

The next assignment is [UTCP_GO_NATIVE_CANONICAL_KERNEL_E1](go-native-control-plane/GO-CP1-canonical-kernel-assignment.md).
Its prompt is the single authoritative copy in this packet. It replaces the prior
Laravel-oriented next-work recommendation for planning purposes only; the old
canonical foundation handoff remains unchanged historical evidence. No immediate
GN-G3 writer cutover is recommended.

## Validation and file inventory

Executed in the repository on 2026-10-21. No packages were installed. Runtime
tests were unnecessary to resolve this architecture scope and were not rerun.

| Exact command / review | Result | Classification |
| --- | --- | --- |
| `make repository-hygiene` | PASS: local runtime cutoff configuration and repository hygiene; nested phase check `changed=24, evidence=0, status-impact=no` | Newly executed repository/static check |
| `make phase-status-consistency-check` | PASS, same inventory/status result | Newly executed; Current-State Ledger Impact: NO_CHANGE_REQUIRED |
| `python3 /tmp/utcp-pivot-doc-check.py` | PASS: 7 Markdown files, 78 local links; UTF-8, titles, balanced code fences, final newlines, tabs/trailing whitespace; repository scanner patterns over every new file | Newly executed bounded documentation validation; temporary helper, no production validator changed |
| Same Python helper against `/tmp/utcp-pivot-baseline.json` | PASS: all 1,571 pre-existing regular-file SHA-256 values match; zero missing/changed; exactly 7 expected new files | Preservation check, including the 17 prior untracked artifacts |
| `make runtime-engine-config-check` | FAIL, make exit 2; checker exit 1, exact message below | Pre-existing baseline validation defect, unchanged; not an architecture defect |
| `make secret-scan` | FAIL, make exit 2; scanner exit 1, exact finding below; no new-document finding | Pre-existing baseline false positive, unchanged; not suppressed |
| `git diff --check` | PASS | Tracked diff whitespace; untracked Markdown independently checked above |
| Read-only architecture-guardian review of report, ADR, blueprint, authority contract, roadmap and first prompt | Ready for operator adoption; no remaining high/medium finding | Architecture review only; no runtime or implementation acceptance |
| `git status --short`, `git ls-files --others --exclude-standard`, branch/HEAD and staged-boundary inspection | `main`, unchanged HEAD, no tracked/staged modifications, 24 untracked files | Final boundary/inventory check; no commit or push |

The documentation helper applies the actual repository scanner's `SECRET_PATTERNS`
without suppressions to all seven files, verifies every relative Markdown link
outside fenced examples, and compares the captured SHA-256 inventory. It does not
claim remote-link availability, full Markdown rendering or runtime correctness.

The runtime checker still reports:

```text
runtime engine config check failed: ordinary platform workers must not carry the Kubernetes API client label: ['utcp-platform/api', 'utcp-platform/scheduler', 'utcp-platform/telephony-reconciler']
```

Its historical blanket assertion in `scripts/runtime-engine/config-check`
rejects accepted K5 API-client workload labels. A separate focused repair must
validate the exact permitted workload/ServiceAccount/label contract with positive
and negative fixtures, preserving the ordinary worker restrictions. Resolve before
CP3 production-integration acceptance and CP6; do not remove accepted labels or
grant broader access to satisfy the checker.

The secret scanner still reports:

```text
possible secret material: apps/web/src/views/ExternalTrunkDetailView.vue:292
```

The flagged local variable reads `credentialForm.secret`, not a literal
credential. A separate narrow scanner repair should distinguish this expression
from literal assignments and retain regression cases for literal credentials,
private keys and tokens. Do not exempt Vue files or all `secret` variables.
Resolve before CP2 security acceptance and CP6. Both failures remain visible;
no full green repository gate is claimed.

During final drafting, quoting that complete assignment also reproduced the
scanner match in this new report. The prose was rewritten to avoid duplicating
the expression; the final scoped scan passes without changing scanner rules.

Complete **new documentation** inventory:

```text
docs/architecture/proposals/UTCP_GO_NATIVE_CONTROL_PLANE_ARCHITECTURE_PIVOT_AND_MIGRATION_PLAN.md
docs/architecture/proposals/go-native-control-plane-adr-proposed.md
docs/architecture/proposals/go-native-control-plane/GO-CP1-canonical-kernel-assignment.md
docs/architecture/proposals/go-native-control-plane/laravel-to-go-matrix.md
docs/architecture/proposals/go-native-control-plane/migration-roadmap.md
docs/architecture/proposals/go-native-control-plane/module-blueprint.md
docs/architecture/proposals/go-native-control-plane/postgresql-authority-contract.md
```

Complete **pre-existing untracked** inventory, all preserved byte-for-byte:

```text
apps/api/app/ControlPlane/InfrastructureAuthority/ActionFingerprint.php
apps/api/app/ControlPlane/InfrastructureAuthority/ActiveFingerprint.php
apps/api/app/ControlPlane/InfrastructureAuthority/CanonicalInfrastructureService.php
apps/api/app/ControlPlane/InfrastructureAuthority/LifecycleFingerprint.php
apps/api/database/migrations/2026_10_21_100000_create_infrastructure_incarnation_authority.php
apps/api/tests/Feature/ControlPlane/CanonicalInfrastructureTest.php
components/kubernetes-effects/canonical_claim_integration_test.go
docs/architecture/proposals/UTCP_GN_G2_CANONICAL_LIFECYCLE_AND_CLAIM_FOUNDATION.md
docs/architecture/proposals/gn-g2/canonical-foundation-migration-and-handoff.md
scripts/control-plane/canonical-proof
tests/gn-g2-canonical/CanonicalIntegrationBridge.php
tests/gn-g2-canonical/Fixture.php
tests/gn-g2-canonical/PostgresProofTest.php
tests/gn-g2-canonical/README.md
tests/gn-g2-canonical/verify
tests/gn-g2-canonical/verify-go-integration
tests/gn-g2-canonical/worker.php
```

No production code, schemas, accepted ADRs, ledger, existing contracts or prior
reports were modified. No database, Docker, Kubernetes or telephony operation was
performed. The new ADR awaits adoption; this packet is complete architecture
work, with future implementation and live acceptance explicitly separate.
