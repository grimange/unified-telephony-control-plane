# UTCP GN-G0 — Infrastructure adapter contract and executable fake proof

Assignment: `UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF`.
Date: 2026-10-21. Worker: OpenAI Codex CLI, GPT-6 family as identified by the
session, High reasoning requested; no independently inspectable model-build ID.
Role: Principal Kubernetes Control Plane Architect / Distributed Systems Engineer.
Scope: architecture/contract design, source review and isolated technical proof.

## Executive verdict

**GN_G0_COMPLETE_WITH_EXPLICIT_DEFERRED_PROOF.** Recommend proceeding to the
bounded, undeployed GN-G1 read-only implementation assignment. Select internal
HTTPS JSON RPC, direct client-go, canonical PG authorization and target-scoped
monotonic generations with Kubernetes UID/resourceVersion conditional writes.
Executable Go unit/fake/HTTP simulation, race detection, vet and PHP fixture
compatibility are verified; actual API-server concurrency, deployment/mTLS,
full observation parity and writer cutoff remain explicit later-stage gates.
No production Go service or architectural adoption is implied.

The write guarantee is **generation-barrier conditional convergence**, not
lease-expiry linearizability. After a newer Kubernetes admission barrier is
acknowledged, an older prepared conditional mutation cannot overwrite it under
the stated API semantics and exclusive-writer invariant. Before that barrier,
a previously authorized request may apply after its PG lease expires. Keep
eligibility held and reconcile to the newer canonical intent. Strict exclusion
at PG lease expiry would need an additional target-side mechanism and is not
promised by this proposal.

This packet resolves a selected contract and its executable bounded slice.
v1 actions intentionally support admission and replicas on bound Deployments;
fixed create/projection/delete schemas and their real API tests must be added
in GN-G2 before any complete writer transfer. Their lifecycle safety rules are
specified here; the prototype does not implement them. This is ordinary staged
implementation, not permission to cut over a partial writer.

## Baseline, authority and preservation

Starting/final branch: `main`. HEAD: `4ef700639c50a58933bc5a0d1e7ae08628cc4c62`.
Initial tree contained only these two untracked E1 proposals:

```text
?? docs/architecture/proposals/UTCP_GO_NATIVE_INFRASTRUCTURE_MIGRATION_READINESS_E1.md
?? docs/architecture/proposals/go-kubernetes-infrastructure-adapter-adr-proposed.md
```

The E1 report remains byte-identical: SHA-256
`b542798ee269fd6552a3f801c3bd9a092aabf8bfc4d2cfe53977d2e7132a5fc4`.
The ADR is revised under this assignment and remains untracked/proposed.
New task-owned files are confined to this proposal packet and
[the isolated experiment](../../../experiments/gn-g0-infrastructure-adapter/README.md).
Tracked runtime/configuration/manifests/validation scripts and accepted ADRs
are unchanged. Nothing staged, committed or pushed. No live credentials read,
cluster accessed, host packages installed or live resources mutated.

Read root/path instructions, applicable audit/boundary/verification skills,
[overview](../overview.md), [E1](UTCP_GO_NATIVE_INFRASTRUCTURE_MIGRATION_READINESS_E1.md),
[the proposed ADR](go-kubernetes-infrastructure-adapter-adr-proposed.md), actual
sources, manifests and tests. Independent read-only repository and boundary
reviews cross-checked the writer graph and delayed-write reasoning.

[The canonical ledger](../../roadmap/phase-status.md) still governs: V1 complete;
K5A–K5E complete/natural-live-proven; K5F planned, not an R0 gate. RMA-E remains
unstarted and the canonical next action remains
`RMA_E_S3_COMPATIBLE_ARCHIVE_ADAPTER_AND_DETERMINISTIC_MINIO_PROOF_IMPLEMENTATION_DESIGN`.
GN-G identifiers are proposal-local dependencies, not adopted roadmap phases.
This packet changes no canonical completion/proof classification or next action.

Accepted authorities remain [ADR-001](../../decisions/ADR-001-modular-monolith.md),
[ADR-007](../../decisions/ADR-007-postgresql-canonical-redis-transient.md),
[ADR-013](../../decisions/ADR-013-control-plane-application-kernel.md),
[ADR-014](../../decisions/ADR-014-postgresql-identity-tenancy-and-session-authentication.md),
[ADR-015](../../decisions/ADR-015-runtime-node-registry-authority.md),
[ADR-016](../../decisions/ADR-016-runtime-engine-authority.md),
[ADR-021](../../decisions/ADR-021-runtime-lifecycle-and-provisioning.md),
[ADR-024](../../decisions/ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md),
[ADR-028](../../decisions/ADR-028-native-k3s-current-development-and-v1-acceptance-topology.md)
and [ADR-032](../../decisions/ADR-032-canonical-management-authority-and-break-glass-cli-boundary.md).
ADR-024's later completion amendment resolves its historical initial Planned
wording. E1's historical unavailable-Go statement remains true of its execution;
GN-G0 adds a separately dated container-toolchain proof.

## Existing implementation and authority matrix

| Responsibility | Canonical authority | Proposed Go boundary |
|---|---|---|
| User identity, tenant authentication/authorization | Laravel/PG | Authenticate internal peer/role only; no tenant policy |
| RuntimeNode and tenant ownership | Laravel/PG | Validate exact bound target against canonical authorization |
| Desired telephony state, admission and placement policy | Laravel/PG | No independent policy or scheduling decision |
| Operation lifecycle, claims, leases, fencing | PG-backed UTCP engine | Bounded effect attempts; no durable queue or own lease authority |
| Target generation/incarnation identity | Proposed PG execution identity under RuntimeNode | Compare markers; never allocate business intent |
| Kubernetes UID/RV/objects and workload facts | Kubernetes | Direct typed acquisition and conditional requests |
| Observation transport | Current PHP; proposed Go | Safe normalized facts, complete scopes |
| Authorized Kubernetes effects | Current dedicated PHP infrastructure worker | Future Go executor after complete credential cutoff |
| Live telephony execution | Existing Asterisk/FreeSWITCH adapters/runtimes | Unchanged; protocol migration deferred |
| Audit/result/observation persistence | UTCP/PG | Return bounded receipt, no direct DB access |
| Redis coordination | Transient only | No canonical dependency/new authority |

The main observation seam is
[HttpKubernetesInfrastructureClient](../../../apps/api/app/Infrastructure/Kubernetes/HttpKubernetesInfrastructureClient.php)
implementing
[KubernetesInfrastructureClient](../../../apps/api/app/Infrastructure/Kubernetes/KubernetesInfrastructureClient.php):
Node/Pod GET lists, HTTP token/CA, 2s connect/5s request, no pagination handling.
401/403 map to permission_denied; transport/non-success to unavailable_to_control;
malformed list to failed. Failures throw rather than represent an empty cluster.

[KubernetesHostVisibilityService](../../../apps/api/app/Infrastructure/Kubernetes/KubernetesHostVisibilityService.php)
performs tenant/RuntimeNode joins, active-work counts, readiness and placement
projection. Its placement reads are repeated per RuntimeNode by
[K5CPlacementObservationService](../../../apps/api/app/Infrastructure/Kubernetes/K5CPlacementObservationService.php),
which persists PG observation rows and fails all refreshed placements unavailable
when acquisition fails. Keep these canonical joins, policy, persistence and
unknown-state behavior in Laravel. GN-G1 can share one complete bounded
acquisition across a refresh, but must measure request count and freshness.

[RuntimeOperationRepository](../../../apps/api/app/ControlPlane/RuntimeOperations/RuntimeOperationRepository.php)
uses PG transactions, `FOR UPDATE SKIP LOCKED`, expiry/reclaim and random claim
tokens. `assertFence` checks exact token and expiry for canonical transitions.
These tokens are equality capabilities, not monotonic target generations.
No lease-renewal method is present in this repository's operation repository;
60s default claims must bound remote work or a separately tested renewal seam
must be introduced. Renewal would extend PG permission, not retract dispatched
I/O. [CommandWorker](../../../apps/api/app/RuntimeEngine/Commands/CommandWorker.php)
passes a handler operation array without lease token/expiry. Future writes need
an explicit claim-aware execution context; do not extract a token from logs or
invent a Go-side claim. Existing durable operations/outbox/inbox and audit stay
canonical. Different operations for one Deployment currently have no shared
monotonic infrastructure target generation.

## Caller-to-effect inventory

All production application Deployment writes found in app/routes converge on
[HttpKubernetesWorkloadClient](../../../apps/api/app/Infrastructure/RuntimeFencing/HttpKubernetesWorkloadClient.php),
through [KubernetesWorkloadClient](../../../apps/api/app/Infrastructure/RuntimeFencing/KubernetesWorkloadClient.php)
and [AppServiceProvider](../../../apps/api/app/Providers/AppServiceProvider.php).
No alternate SDK/direct Deployment HTTP call or shell/kubectl invocation was
found in those production sources. Test doubles and unavailable clients mirror
this seam; they are not separate live writers.

| Caller graph / operation | Current effect and RBAC | Authorization, identity, concurrency and tests | Proposed disposition |
|---|---|---|---|
| Admin provisioning -> RuntimeProvisioningService -> durable `runtime.node.provision` -> ManagedRuntimeProvisioningOperationHandler -> Asterisk/FreeSWITCH provider | GET then POST or merge PATCH Deployment; also Secret and Service. Deployment get/create/patch | Tenant permission, idempotent request, canonical provider/config, part-of + utcp.dev/runtime-node slug label. PG operation claim; no atomic UID/RV condition. ManagedAsteriskProvisioningOperationTest, ManagedFreeSwitchProvisioningTest, ResourceWriter tests | Future fixed create_suspended/projection; PHP retains provider/config/Secret handling |
| AsteriskRuntimeNodeReconciler / FreeSwitchRuntimeNodeReconciler -> operationRequired -> `runtime.node.workload.converge` -> ManagedRuntimeWorkloadConvergenceOperationHandler | Reapply provider Deployment (get/create/patch), then observation | Tenant-scoped node; desired active **or draining**; operation claim. Provider template declares replicas=1. ReconcilerKubernetesAuthorityTest and provisioning coverage | Same target generation/order as scale; cannot remain an independent PHP writer |
| ConferenceFailoverCoordinator -> RuntimeFencingCoordinator -> durable `runtime.node.runtime.fence` -> RuntimeFenceOperationHandler -> KubernetesRuntimeFenceAdapter | GET Deployment/RS/Pods; merge PATCH deployments/scale replicas=0 | Ownership, recovered DB state, zero replicas/status/owned-Pod completion and provenance. KubernetesRuntimeFenceAdapterTest | Go conditional parent-Deployment replicas; Laravel retains telephony policy and completion |
| Admin desired-state -> RuntimeRegistryService disabled->active with successful source fence -> `runtime.node.runtime.restore` -> RuntimeNodeRestoreOperationHandler | GET workload, scale target replicas, observe readiness | Tenant/config/source provenance/unresolved fences/active work; fresh listener lease/event epoch before canonical activation. Fence/restore suite | Go conditional replicas; Laravel retains readiness/activation |
| Admin decommission OR permitted terminal desired state -> RuntimeRegistryService scheduleManagedDeprovisionIfApplicable -> `runtime.node.deprovision` -> provider | GET/DELETE owned Deployment, Service, Secret | Tenant/lifecycle/ownership; absent is retry success; no DELETE UID/RV preconditions. ManagedRuntimeDeprovisioningOperationTest | Future terminal incarnation retirement + conditional delete |
| Infrastructure host/placement API and K5C scheduled refresh | Nodes/Pods get/list | Laravel operator/tenant permission and PG joins. KubernetesHostVisibilityTest | Go facts only, PHP correlation |
| HostMaintenanceService via scheduler/reconciliation | Node get/list/patch cordon, Pods get/list, pods/eviction create | Canonical telephony drain before effects, Node UID check; no explicit durable per-row claim visible in inspected reconcileDue loop. HttpKubernetesMaintenanceClientTest, K5DHostMaintenanceContractTest | Retain PHP in GN-G1–G3; separate later contract needed |

Exact source effects: Asterisk provision `:77–80`, FreeSWITCH provision `:51–53`,
convergence `:52–63`, Asterisk deprovision `:92`, FreeSWITCH delete `:94`,
restore `:282`, fence adapter `:48` in their respective classes. Files are
[under RuntimeProvisioning](../../../apps/api/app/RuntimeProvisioning/ManagedRuntimeWorkloadConvergenceOperationHandler.php),
[RuntimeFencing](../../../apps/api/app/Infrastructure/RuntimeFencing/RuntimeNodeRestoreOperationHandler.php)
and [Kubernetes maintenance](../../../apps/api/app/Infrastructure/Kubernetes/HttpKubernetesMaintenanceClient.php).
[Worker routing](../../../apps/api/routes/console.php) excludes all five
infrastructure operation types from ordinary command workers and includes them
in the dedicated infrastructure worker.

Current workload client scale is an unconditional merge PATCH to `/scale`.
Apply performs read/ownership validation then POST or merge PATCH; delete reads
ownership then sends DELETE. None atomically binds the observed UID/RV.
Namespace is fixed utcp-runtime, resource kinds bounded. Current persisted
logical identity is namespace/name, not cluster+UID+incarnation. Owned-Pod
inspection follows Deployment UID -> ReplicaSet owner UID -> Pod owner UID,
but its current Pod selector hardcodes component=asterisk-ari; GN-G2 must prove
FreeSWITCH ownership parity rather than copy that limitation into a generic
contract. Current errors are normalized and Secret return fields filtered.

The coexistence of provider apply replicas=1 and fence scale=0 is a source-proven
potential ordering conflict; GN-G0 does not claim to have reproduced a live race.
Moving only `/scale` would leave conflicting application write authority.
Future Laravel target authorization must preserve unresolved fence/lifecycle
holds: convergence derives effective replicas from canonical target intent,
never blindly from the provider default. A higher generation is not permission
to reactivate a fenced node; only admitted canonical restoration releases it.

## Selected transport and versioned interfaces

The normative [v1 contract](gn-g0/contracts/v1/README.md) specifies endpoints,
validation, mTLS roles, authorization callback, limits, freshness, safe fields,
fingerprint algorithm, outcomes and lifecycle requirements. JSON schemas and
[fixture manifest](gn-g0/contracts/v1/fixtures/manifest.json) are executable
interoperability artifacts. HTTP is selected over gRPC for existing Laravel
HTTP seams, bounded operations and fixture sharing; neither streaming nor a
second message consumer is justified. PHP retains canonical work dispatch and
receives the synchronous bounded RPC response. No Go callback may independently
complete an operation or decide desired telephony state.

Flow: PHP transaction records target intent/generation and durable operation;
worker claims it, resolves policy/tenant/identity, submits exact Action; Go
schema/semantic validates, verifies current full claim with Laravel, directly
reads Kubernetes, checks identity/markers, revalidates claim immediately before
conditional dispatch; returns safe Result. PHP records attempt receipt and
observation, then fenced canonical completion/outbox or explicit uncertainty
and retry/recovery. If the caller lost its lease, receipt is evidence only until
a current claimant validates it. API acceptance does not prove runtime readiness.

No action accepts arbitrary Kubernetes content. v1 enums are admission and
absolute replicas only. Action includes version, request/operation IDs,
tenant/RuntimeNode, target/incarnation/cluster/ns/name/RuntimeNode slug/UID/RV, PG generation,
intent hash, claim attempt/token/expiry, deadline, idempotency/correlation/audit.
The token is secret internal context, never a response/log field. Target identity
labels are checked in addition to trusted tenant lookup, never instead of it.

Observers have no mutation role or write ServiceAccount; executors have bounded
namespaced Deployment get/patch only for the first write slice. No watch/update
is required. Later create/delete verbs require lifecycle-schema adoption. No
Secret read permission is required by the observer or replicas executor. Keep
existing PHP Secret/Service corridors separately authorized during a future
Deployment transfer; do not copy their broader fencer Role into Go.

## Delayed-write model: invariants and scenarios A–H

Canonical PG target row locks allocate a strictly increasing signed-bigint
infra execution generation for changed target intent or an explicit writer-authority
handoff/supersession; `(target,generation)` binds one
hash. A handoff may keep the desired effect/business operation identity while
allocating a new execution generation/key; ordinary retries retain their pair.
All five operation corridors share this order.
Target creation/deletion/retirement must participate in the same ordering.
A local Go target mutex limits concurrency but is not distributed fencing.

Admission patches test UID+RV and change only admitted markers. Effects test
UID+RV+admitted generation/hash and atomically set replicas+applied markers.
Direct reads reject a higher generation or equal generation with a different
hash. A retried action never drops conditions or auto-refreshes RV across a
newer barrier. An admitted marker is never treated as proof of the intended
effect. An applied marker must match both hash and current effect predicate.

| Scenario | Deterministic contract / guarantee | GN-G0 evidence |
|---|---|---|
| A: lease expires before dispatch | Local expiry and full canonical authorization check reject; second check immediately before dispatch. A final check-to-send race remains equivalent to B | Expired claim and authorization-denied unit/HTTP tests |
| B: expiry after dispatch | PG completion rejects stale claimant. Kubernetes request can still apply. Cancellation/credential revocation cannot retract admitted I/O | Delayed HTTP patch applies after logical clock passes expiry |
| C: generation 10 arrives after committed 11 | New barrier changes RV. Old exact RV/predicate fails; re-read sees higher generation and rejects. Older barrier cannot be refreshed over 11 | Delayed old effect blocked until 11 barrier/effect commit; old request rejected, replicas remain 1 and applied-generation remains 11 |
| D: old request arrives before 11 barrier | Temporary stale effect possible; hold eligibility, observe provenance and converge to canonical 11. No false strict-safety claim | Expired in-flight 10 applies; subsequent 11 restores desired replicas |
| E: lease expires, Kubernetes unchanged | RV alone permits old prepared request; it encodes Kubernetes changes, not PG time. Admission/claim checks help only before dispatch | B/D test keeps same RV between preparation and acceptance |
| F: API applies but response lost | Sent-with-unknown outcome, never definitive failure. Direct exact UID/marker/effect recovery; current authorization before any retry | Server applies patch then closes connection; unknown response; repeat observes applied marker, no extra patch |
| G: duplicate workers | Same generation/hash one CAS barrier wins or existing admission recognized. Competing intent shares PG target serialization; Kubernetes CAS rejects stale prepared actions | Concurrent identical barrier workers: one patch; other conflict/already satisfied; race detector |
| H: rollback with in-flight requests | Quiesce admission and revoke previous writer rights; previously admitted effects may finish. Compatible conditional successor publishes newer barrier before relying on exclusion; no unrestricted PHP fallback | Protocol/source review only; conditional successor required; initial legacy drain is a separate GN-G2/GN-G3 gate |

Safety depends on authentic canonical requests, exclusive application write
rights, correct monotonic allocation, immutable markers/UID, real API atomic
conditional behavior and no operator/tool bypass during an in-progress target
corridor. Malicious privileged cluster administrators are outside that guarantee.
No transaction spans PG and Kubernetes; there is no exactly-once transport,
global cross-resource transaction, or guaranteed rollback of sent I/O.

## Immutable identity and lifecycle

Namespace/name locates; UID identifies the actual incarnation; RV guards the
observed version. Labels correlate and enforce application ownership checks,
but RBAC does not enforce their tenant values. K8s enforces conditional patch
tests and DELETE preconditions; PG enforces generation, authorization, immutable
binding and terminal retirement. ReplicaSet/Pod chains must be validated by UID,
not name/prefix/labels alone. Spec/metadata.generation is distinct from UTCP's
infrastructure generation.

No resource can be rebound after wrong UID/name reuse. Future replacement gets
new incarnation and unique physical name; absence never authorizes adopting a
foreign replacement. New objects start replicas=0, exact returned/discovered UID
binds in PG, then separate current intent activates. A 409 create race requires
exact incarnation/projection inspection. DELETE requires UID+RV after terminal
retirement, a newer barrier and existing drain/zero/owned-Pod predicates.
Deletion accepted is distinct from observed absence. Retired names stay unused.

An unknown old CREATE can arrive after DELETE and recreate its old name. PG
tombstones and a GET=404 cannot prevent this. The zero-replica rule contains it
as an inert orphan, never a canonical reactivation; cleanup must positively
identify the retired incarnation. Strict physical nonresurrection requires an
additional Kubernetes-side admission/tombstone design. No such new system is
approved here. See the contract's normative lifecycle-extension requirements.

## Complete writer transfer and rollback protocol

Besides the application graph, authorized platform tooling can write Deployments:
[native-k3s apply](../../../scripts/native-k3s/apply) reapplies server manifests,
including the fixed Asterisk fixture replicas=1;
[secondary Kubernetes apply](../../../scripts/kubernetes/apply) applies/restarts;
[conference recovery proof](../../../scripts/asterisk-conference/recovery-runtime-proof)
scales the fixed Asterisk fixture and deletes a Pod; other protocol/restart proof
tools delete Pods; media/gateway/observability/prover scripts own their declared
platform/test resources. Node maintenance eviction indirectly causes replacement
through Kubernetes controllers. These are not additional autonomous telephony
reconcilers, but overlapping fixture lifecycle/proof writes require an explicit
maintenance/exclusion procedure and field ownership before GN-G3. Kubernetes's
Deployment/ReplicaSet controllers legitimately maintain status/children.

A first transfer has an additional condition: today's unconditional PHP requests
are not protected by a newer barrier. An already accepted legacy PATCH/scale or
DELETE can still change the same incarnation after that barrier. Select a
server-confirmed legacy drain: complete Kubernetes audit metadata must correlate
all accepted old-writer requests through terminal response stages, with proved
coverage and no unresolved API work; inspect final/deleting resource and children,
then establish an exclusively conditional-compatible baseline. GN-G2/GN-G3 must
prove this audit/drain design, including server-side terminal handler semantics.
A client timeout, worker exit, revoked credential or current GET is insufficient.
Without that proof, same-incarnation first transfer is prohibited. A separately
reviewed replacement/isolation design must also contain legacy create-at-one
resurrection and live telephony effects; name changes alone do not contain it.

Future GN-G3 protocol, **not authorized now**:

1. Implement all five application corridors behind one conditional contract,
   including fixed create/projection/delete. Inventory every live writer and
   resolved ServiceAccount binding, projected/legacy token, impersonation/group
   grant and mounted kubeconfig. Prebuild a compatible conditional PHP rollback
   release. Assign ownership of fixed manifest-managed telephony fixtures.
2. Stop new target-intent dispatch; retain pending PG operations. Mark executing
   attempts draining, wait bounded deadlines, inspect unknown results by exact
   UID/markers. Do not infer quiescence from a worker exit or timeout.
   Collect legacy-request evidence here; finalize the server-confirmed drain
   only after effective revocation in step 3. No unresolved unconditional
   request is eligible for barrier-based recovery.
3. Remove effective Deployment **create/update/patch/delete and scale patch/update**
   privileges from every PHP/application writer identity, remove/revoke its
   credential mounts/grants and restart old executors under authorized procedures.
   Preserve required PHP Node maintenance/observation and Service/Secret rights
   separately. A flag alone is insufficient; Kubernetes RBAC grants are additive.
   After proving effective revocation, finalize the server-confirmed drain of
   all accepted legacy requests before enabling any successor writer.
4. Prove negative effective authorization for each old identity across parent
   Deployments and scale subresource, enumerate bindings, and use dedicated
   harmless precreated canary Deployment requests with old credentials to obtain
   403 for each relevant verb. No real telephony target is a negative-test victim.
   Verify Go observer cannot mutate, executor cannot read Secrets/cross namespaces.
   No privileged automation continues overlapping writes to managed targets.
5. Grant Go only the adopted namespaced effect verbs; bootstrap/revalidate exact
   target UID/generation under CAS, publish a newer admission barrier and recover
   earlier receipts. Requeue ordinary retries with their generation; an explicit
   writer handoff
   allocates a newer execution generation/key, preserving desired effect and
   business operation/idempotency identity. Resume one application writer and prove
   K5/V1 fencing/restoration/drain/provision/deprovision behavior naturally live.
6. Rollback repeats quiesce/unknown inspection and credential transfer; Go loses
   effective writes before a conditional-compatible PHP release gains them.
   Newer barriers exclude old prepared writes only after acknowledgment. A request
   admitted before credential cutoff can still finish earlier; PG eligibility
   remains held through this uncertainty. Never roll back to the current
   unconditional client or automatically fall back after RPC failure.

## PHP error parity and recovery

[errors.json](gn-g0/contracts/v1/errors.json) maps all normalized result outcomes.
Success/already-satisfied are infrastructure effects only. Invalid request maps
to invalid_request; auth failures to permission_denied; not-found/UID mismatch to
target_mismatch; ownership to ownership_conflict; RV/stale generation/drift to
fence_in_progress; before-send unavailable/timeout/cancel and unknown to
unavailable_to_control; terminal malformed evidence to failed. Preserve the
richer normalized outcome alongside the compatibility reason. Existing engine
Conflict is terminal by default, so mapping alone cannot schedule a retry;
authorized canonical infrastructure recovery must explicitly distinguish CAS
re-observation, supersession and permanent ownership failure.

Never map sent-with-unknown to ordinary retryable failure. PG records dispatch,
effect, requested generation/hash, safe observed UID/RV and correlation before
recovery. On restart, Go acquires no own desired work; PHP reclaims durable work,
inspects unresolved effects and reauthorizes. Backpressure rejects nondispatched
work; retries use engine backoff, bounded attempts and target holds.

## Feasibility and validation evidence

Host `go version` unavailable. Docker obtained `golang:1.25.8-bookworm` resolved
to immutable digest
`sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`.
Actual SDK: `go1.25.8 linux/amd64`; client-go/api/apimachinery v0.35.3.
Same-minor API assumptions target the repository's k3s 1.35.3; no live
compatibility is claimed. `go.mod`/`go.sum` pin the isolated module, not the
production dependency set. JSON Schema validator v5.3.1 and RFC6902 simulation
library v5.9.0 are test dependencies. Maintained patch/security selection must
be revisited before production adoption.

Reproduction and exact outputs are in
[experiment README](../../../experiments/gn-g0-infrastructure-adapter/README.md)
and [verification results](../../../experiments/gn-g0-infrastructure-adapter/verification-results.txt).
Commands: `verify --download` for pinned acquisition; `verify` executes module
integrity, gofmt check, `go test -count=1 ./...`, `go test -race -count=1 ./...`
and `go vet ./...` inside a network-disabled container. `verify-php` executes
the focused existing suites and isolated shared-fixture tests in the existing
PHP image (PHP 8.4.15, PHPUnit 11.5.56), all with disposable SQLite and no network.
No Docker/Compose service or cluster deployment is started.

| Evidence | Classification and meaning |
|---|---|
| Complete writer graph, RBAC, policies, leases and lifecycle review | Source-inspected; no credential effectiveness or live state claim |
| Stable Node/Pod normalization, safe-field omission, missing topology/ownership, deterministic order | Pure Go + client-go fake; safe shared fixtures |
| Node and Pod pagination, opaque continuation, expired/repeated tokens, inconsistent RV and denied list | httptest protocol simulation; no real list snapshot consistency proof |
| Barriers/effects, UID/ownership refusal, duplicate workers, delayed stale write, unknown result | RFC6902 HTTP model through real client-go transport; simulated enforcement |
| Cancellation/deadline | Context/HTTP simulation; does not prove API mutation cancellation |
| Go race detector/vet | Executed; detects tested-process races/static issues only |
| Existing PHP focused suite | 91 tests, 611 assertions passed separately; combined with 3 proof tests, 94 tests / 645 assertions passed; current contracts only |
| Shared PHP fixture/error/hash tests | Executed through actual current host visibility service and exception reasons; full public/tenant/API parity deferred GN-G1 |
| Real API UID/RV/patch/DELETE/admission/defaulting behavior | Not verified; GN-G2 integration gate |
| Live mTLS/rotation, RBAC cutoff, recovery, V1/K5 acceptance | Not verified; GN-G1/GN-G3 controlled authorized proof |

Final command outcomes/counts are recorded in verification-results.txt. Required
repository hygiene, phase consistency, control-plane static and workflow checks
pass. The independent link/whitespace verifier covers new/revised docs and
untracked text; `git diff --check` is also required because default git diff
alone does not cover untracked files. No new behavior is wired into UTCP.

An initial offline verification attempt lacked cached module `.info` metadata;
`verify --download` acquired it and offline verification then ran. An initial
PHP proof assertion compared associative-label key insertion order; it was
corrected to compare JSON-object semantics. Those were environmental bootstrap
and introduced proof-harness issues respectively, resolved before final proof;
neither changed production code. All remaining failures are disclosed below.

## Baseline validation defect disposition

`make runtime-engine-config-check` fails at
[scripts/runtime-engine/config-check](../../../scripts/runtime-engine/config-check)
lines 415–424: blanket rejection of ordinary API-client-labeled workers.
The actual diagnostic names `utcp-platform/api`, `scheduler`,
`telephony-reconciler`. Current accepted K5 manifests and
[RuntimeFencingManifestTest](../../../apps/api/tests/Feature/Infrastructure/RuntimeFencingManifestTest.php)
lines 21–41 require that exact set. Their ServiceAccounts are utcp-api,
utcp-kubernetes-observer and utcp-kubernetes-maintenance respectively; API reader
and maintenance roles intentionally differ. The checker is stale; removing
those labels would break accepted K5 network access.

Smallest repair in a **separate authorized validation packet**: compare exact
sorted workload set plus expected ServiceAccount mapping; preserve dedicated
fencer/token/endpoint-only egress/RBAC constraints. Add missing/unexpected label,
wrong SA and unauthorized worker egress negative fixtures. Also strengthen
worker dispatch validation to the current five infrastructure operation types.
The mutation harness `config-check-test` restores CommandWorker under EXIT but
restores its mutated allow-worker policy only on normal success; repair cleanup
for both files or mutate isolated copies, and prove restoration on failure and
interrupt. GN-G0 did not run that unsafe mutation harness or alter the scripts.

Acceptance: canonical manifests pass unchanged; each unexpected/missing API
client or wrong identity fails; each missing/overlapping operation corridor
fails; TCP8090 unauthorized egress still fails; interrupted harness preserves
all bytes; focused PHP manifest/client/fence/provision tests pass. This pre-existing
defect does not block proposal completion, but must be isolated/repaired before
GN-G1 implementation acceptance. It is not evidence against Go feasibility.

## Migration dependency and acceptance plan

| Stage | Scope/dependencies and exact authority changes | Acceptance / proof | Rollback and remaining risk |
|---|---|---|---|
| GN-G0 | Current proposal/contracts/isolated proof. No runtime authority change | Selected invariants, schemas/fixtures, reproducible fake/HTTP/PHP evidence, explicit proof limits | Remove task-owned packet if declined; accepted architecture untouched |
| GN-G1 | Undeployed/read-only direct-list observer, dependent on scoped operator authorization and baseline checker disposition. Laravel retains sole observation persistence/policy. No mutation SA/verbs | Full PHP public-response and tenant projection parity; all-namespace/scoped complete pagination incl 410 restart and malformed/duplicate identity; timestamps/freshness/overlap ordering; missing topology/placement; 401/403/429/5xx/recovery/cancellation; safe fields; mTLS role/rotation tests; request count plus CPU/RSS measured against PHP; no production routing until separately adopted and no simultaneous canonical observer | Retain existing PHP observer; credentials read-only, no mutation fallback. Stale acquisition/projection compatibility remain measured risks |
| GN-G2 | Conditional Deployment executor implemented but no writer transfer. PG target identity/generation and claim-aware engine seam require separately authorized schema/code work. Fixed lifecycle extension schemas cover all application effects | Dedicated real API-server integration: JSON Patch UID/RV/barrier/effect atomicity, actual Status mapping, conflict/retry/duplicate/lost response, preexpiry/postexpiry A–G, server-audited legacy drain design, UID deletion/recreation, create0/retire/late POST containment, owned-Pod Asterisk/FreeSWITCH, PHP error parity. No production mutation permissions granted by GN-G0 | PHP remains writer until cutoff; experimental executor isolated. Real admission/subresource/defaulting remain unproven until tests |
| GN-G3 | Transfer **all five** Deployment corridors and credential authority together; resolve fixed fixture platform apply ownership. Depends GN-G2 + operator-approved live packet | Effective old-credential 403 matrix incl parent and scale verbs, no write bindings/mounts, one active writer, unknown-attempt recovery, proved server-confirmed legacy drain, compatible rollback drill, preserved K5 and V1 natural-live lifecycle proofs | Quiesce/revoke Go then enable only conditional-compatible PHP; no unconditional old release. In-flight admission and orphan residue explicitly contained |
| GN-G4 | Deferred telephony listener/ARI/ESL evaluation after measured infrastructure results; no authority change approved | Separate protocol/event epoch/restart proof and ADR justification | Retain existing runtimes/adapters |
| GN-G5 | Deferred broader backend decision; no Laravel rewrite approved | Measured benefit/cost, identity/domain/kernel equivalence and separate adoption | Retain canonical Laravel/PG architecture |

Preserve [K5A visibility proof](../../evidence/k5/k5a-host-kubernetes-node-visibility-live-proof.md),
[K5C policy proof](../../evidence/k5/k5c-capacity-failure-domain-policy-natural-live-proof.md)
and [K5E distributed proof](../../evidence/k5/k5e-distributed-infrastructure-natural-live-proof.md).
These historical proofs are not new Go acceptance. No CRD/controller-runtime
or Kubernetes leader election is necessary for the selected adapter.

## Risks, rejected alternatives and operator decisions

Material risks: CAS retry storms from status/RV churn; a wider-than-intended
ServiceAccount bypass; old platform manifest apply overwriting managed replicas;
partial observation being treated as empty; overbroad labels/unsafe output;
clock skew; unknown results being terminalized; target-generation allocation
across separate operation types; orphan resource accumulation; incompatible
rollback; extra process/certificate maintenance. Metrics should count scope
completion/staleness/pages, request duration/count, CAS failures, denied identity,
unknown outcomes, in-flight/saturation and recovered attempts. Avoid tenant IDs,
resource names or operation IDs as unbounded metric labels. Logs contain safe
IDs/outcome/elapsed only, never request body, token, raw Kubernetes object or
upstream error body; audit remains PG.

Reject an independently reconciling Go controller/CRD, cache-driven initial
effect authorization, dual PHP/Go writers, blind retry/fallback, SSA force as
fencing, single-pod/local-mutex-only safety, active-at-create lifecycle, name-based
UID re-adoption, full Laravel replacement and a new coordination store. Each
either weakens authority or lacks a measured need. HTTP and direct lists are
implementable without those additions.

Only material operator decisions remain: adopt a scoped read-only extraction
and its safe Node-label projection; later adopt the bounded-stale-effect/inert-
orphan write model (or request a different target-side enforcement architecture);
authorize PG target/claim implementation and fixed lifecycle schemas; assign
component/certificate/cluster identity ownership; and authorize complete writer
cutoff/live-proof/rollback separately. GN-G0 asks for no further permission and
executes none of those transitions. ADR stays **PROPOSED / NOT ADOPTED**; only
operator adoption may allocate an accepted ADR and reconcile canonical pages.

## Next recommended assignment

Use exactly the [GN-G1 assignment](gn-g0/GN-G1-implementation-assignment.md).
It grants only undeployed read-only implementation, does not assume ADR adoption,
and isolates the baseline checker repair as a separate required validation task.

## External protocol references

[Kubernetes API concepts](https://kubernetes.io/docs/reference/using-api/api-concepts/)
supports conditional updates and per-list pagination semantics; the
[JSON Patch section](https://kubernetes.io/docs/reference/using-api/api-concepts/#http-patch-using-json-patch)
permits conditions, while the
[DeleteOptions definition](https://kubernetes.io/docs/reference/kubernetes-api/common-definitions/delete-options/)
defines UID/resourceVersion preconditions. These documented mechanisms are
assumptions tested by the simulation, not a substitute for GN-G2 API-server
validation. [client-go compatibility guidance](https://github.com/kubernetes/client-go#compatibility-client-go--kubernetes-clusters)
requires explicit version matching/testing; fake clients do not implement all
server admission/storage/defaulting behavior.

Current-State Ledger Impact: **NO_CHANGE_REQUIRED**.
Phase-Status Consistency Check: **PASS** (final verification in results artifact).
ADR Status: **PROPOSED / NOT ADOPTED**.
Runtime Code Changed: **NO**. Live Kubernetes Mutation: **NO**.

## Exact task-owned file inventory

The following 49 paths are untracked at handoff. The ADR was already
untracked and is revised; the other 48 paths are new. The original E1 report
is excluded because its bytes are unchanged. Nothing is staged.

```text
docs/architecture/proposals/UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF.md
docs/architecture/proposals/gn-g0/GN-G1-implementation-assignment.md
docs/architecture/proposals/gn-g0/contracts/v1/README.md
docs/architecture/proposals/gn-g0/contracts/v1/action.schema.json
docs/architecture/proposals/gn-g0/contracts/v1/errors.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/action-admit.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/action-replicas.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/invalid-arbitrary-manifest.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/invalid-empty-healthy.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/invalid-missing-uid.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/invalid-numeric-generation.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/invalid-unsupported-create.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/manifest.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/observation-complete.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/observation-forbidden.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/observation-incomplete.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/observation-request.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-already_satisfied.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-busy.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-cancelled.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-drift.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-forbidden.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-identity_mismatch.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-invalid_request.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-outcome_unknown.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-ownership_conflict.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-resource_version_conflict.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-retryable_failure.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-stale_generation.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-success.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-target_not_found.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-terminal_failure.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-timeout.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-transport_unavailable.json
docs/architecture/proposals/gn-g0/contracts/v1/fixtures/result-unauthorized.json
docs/architecture/proposals/gn-g0/contracts/v1/observation-request.schema.json
docs/architecture/proposals/gn-g0/contracts/v1/observation.schema.json
docs/architecture/proposals/gn-g0/contracts/v1/result.schema.json
docs/architecture/proposals/go-kubernetes-infrastructure-adapter-adr-proposed.md
experiments/gn-g0-infrastructure-adapter/PhpContractParityTest.php
experiments/gn-g0-infrastructure-adapter/README.md
experiments/gn-g0-infrastructure-adapter/adapter.go
experiments/gn-g0-infrastructure-adapter/adapter_test.go
experiments/gn-g0-infrastructure-adapter/go.mod
experiments/gn-g0-infrastructure-adapter/go.sum
experiments/gn-g0-infrastructure-adapter/verification-results.txt
experiments/gn-g0-infrastructure-adapter/verify
experiments/gn-g0-infrastructure-adapter/verify-php
experiments/gn-g0-infrastructure-adapter/verify-text
```
