# UTCP GN-G2 — Conditional Kubernetes Deployment Effects

Assignment: `UTCP_GN_G2_CONDITIONAL_KUBERNETES_DEPLOYMENT_EFFECTS`.
Date: 2026-10-21. Worker: OpenAI Codex, acting as Principal Kubernetes Control
Plane Engineer / Distributed Systems Engineer. Scope: isolated implementation
and developer proof. This report does not adopt the adapter ADR, authorize a
writer transfer, or change the canonical phase ledger.

## Verdict and proof boundary

The isolated Go module implements the two planned v1 effects on already-bound
Deployments: `admit_generation` and `set_replicas`. Laravel/PostgreSQL retain
canonical authority. No production PHP application logic, database schema,
Kubernetes manifest, RBAC, service binding or deployed workload is changed.

Verdict: **GN_G2_IMPLEMENTED_SIMULATED_PROOF_ONLY**. The pinned developer verifier
passes, including focused PHP regression and the complete GN-G1 regression.
The independent baseline static-check failure remains visible. The real
API-server gate is **DEFERRED / NOT VERIFIED** because no explicitly authorized
disposable target was established. HTTP fixtures and RFC6902 simulations cannot
prove Kubernetes admission/defaulting, resourceVersion enforcement or actual
concurrent API-server behavior. No deployed or natural-live proof is claimed.

The accepted current state remains in [phase-status.md](../../roadmap/phase-status.md).
V1/K5 classifications and the canonical RMA-E next action are unchanged.
`Current-State Ledger Impact: NO_CHANGE_REQUIRED`: this isolated proposed adapter
has no adopted production authority or canonical roadmap phase completion.

## Repository baseline and preservation

| Item | Starting state |
| --- | --- |
| Branch | `main` |
| HEAD | `4ef700639c50a58933bc5a0d1e7ae08628cc4c62` |
| Tracked unstaged changes | 0 |
| Staged changes | 0 |
| Existing untracked files | 117, including all E1/GN-G0/GN-G1 artifacts |
| Preservation method | SHA-256 inventory of all 117 pre-existing untracked files before edits, compared at final verification |
| Commit / push | Neither authorized nor performed |

The baseline hash manifest and test exports remain disposable under `/tmp`;
they are not canonical state or committed credentials. Final status and complete
new-file inventory are recorded below after validation.

## Implementation and authority

The new [kubernetes-effects module](../../../components/kubernetes-effects/)
depends on the existing isolated GN-G1 observer module for tested rotating mTLS
material. It creates no listener, executable service, background reconciliation
loop, database connection or production registration.

| Source | Implemented responsibility |
| --- | --- |
| `types.go` | Closed typed action/result, fixed marker names, deterministic intent fingerprint, narrow client/verifier interfaces |
| `schema.go` | Normative JSON Schema loading, strict UTF-8/unpaired-surrogate/duplicate-key/trailing-value/null/depth handling, lossless signed-bigint generation checks |
| `engine.go` | Trusted scope/identity, ownership and marker validation, local bounded serialization, fresh claims, patch construction, idempotency and GET-only recovery |
| `kubernetes.go` | Direct client-go parent Deployment GET/JSON Patch, exact path/verb guard, HTTPS, no redirects or mutation retries, bounded responses, rate-budget preparation |
| `claims.go` | Fixed HTTPS full-action Laravel callback with strict two-field reply and no positive cache, retry or fallback |
| `transport.go` | Testable authenticated executor-only HTTPS handler, strict request decoding, bounded size/deadline/admission and safe normalized results |
| `*_test.go` | Engine, protocol, mTLS, parity, identity, uncertainty, cancellation and concurrency regression tests |
| `verify`, `verify-static` | Pinned one-command developer verification and static scope/hygiene checks |

| Responsibility | Authority retained |
| --- | --- |
| Tenant authentication/authorization; RuntimeNode ownership | Laravel / PostgreSQL |
| Desired telephony state, admission, lifecycle holds | Laravel / PostgreSQL |
| Operation lifecycle, generation allocation, claim token/expiry, durable retry | PostgreSQL-backed UTCP engine |
| Kubernetes identity and observed resource state | Kubernetes |
| Bounded authorized effect execution | Isolated proposed Go adapter |
| Durable result recording and audit | Laravel / PostgreSQL |
| Runtime telephony execution and completion guards | Existing Asterisk / FreeSWITCH adapters and Laravel services |

The adapter does not decide whether a tenant may scale, fence or restore a
RuntimeNode. Infrastructure labels provide an additional fail-closed identity
check after canonical authorization; they do not grant tenant permission.

## Current PHP caller-to-effect mapping

All five application Deployment mutation corridors currently converge on
[HttpKubernetesWorkloadClient](../../../apps/api/app/Infrastructure/RuntimeFencing/HttpKubernetesWorkloadClient.php).
No corridor is transferred by GN-G2.

| Corridor | Existing entry point | Current effect and future boundary |
| --- | --- | --- |
| Provision | `ManagedAsteriskProvisioningOperationHandler`, `ManagedFreeSwitchProvisioningOperationHandler` | GET ownership, create/update managed workload; requires lifecycle create/projection extension before transfer |
| Workload converge | `ManagedRuntimeWorkloadConvergenceOperationHandler` | Existing workload projection apply; future projection must share generation and canonical fence holds with replicas |
| Runtime fence | `KubernetesRuntimeFenceAdapter` | Absolute Deployment scaling, currently unconditional merge PATCH of `/scale`; Go v1 demonstrates conditional parent Deployment alternative |
| Runtime restore | `RuntimeNodeRestoreOperationHandler` | Existing canonical admission followed by scale/restore; policy and readiness completion stay PHP |
| Deprovision | Managed Asterisk/FreeSWITCH deprovision handlers | Owned workload deletion, currently pre-read then DELETE without UID/RV; requires lifecycle extension |

`apply` currently GETs an owned resource then POSTs or merge-patches; scale uses
`/scale`; deletion has no server-enforced UID/RV precondition. Existing ownership
checks and canonical operation fences protect their own domains but are not the
new generation-barrier contract. Existing workload identity persists namespace
and name; the new immutable UID/target/incarnation binding is not silently added
to the production schema by these tests.

Deployment ownership is distinct from Pod execution evidence. Existing PHP
owned-Pod inspection follows Deployment UID to ReplicaSet controller UID to Pod
controller UID. GN-G2 does not replace that proof, telephony draining, readiness,
active-work, listener epoch, maintenance or restoration guards. The GN-G1 v1
projection omits Pod `deletionTimestamp` and exposes an unverified direct owner
chain; it is not sufficient evidence for lifecycle completion or owned-Pod absence.

Platform manifest/image-rollout and diagnostic tooling are separate deployment
lifecycle authorities, not automatic exceptions to target exclusivity. Before
cutover, inventory their field/resource ownership and prevent them from racing
with the application writer on the same managed targets.

## Contract conformance and fingerprint parity

The implementation consumes the unchanged
[GN-G0 v1 action/result schemas](gn-g0/contracts/v1/README.md) and fixture
manifest. The version remains exactly `utcp.infrastructure.v1`; unknown members,
unsupported actions and malformed requests fail before Kubernetes access.
Requests cannot supply a Kubernetes URL, token, kubeconfig, arbitrary patch,
manifest or resource kind. Replicas are absolute integers 0–100.

Generation stays a positive decimal string through PHP and Go, with the signed
PostgreSQL bigint upper bound. It is not a floating-point value, Kubernetes
`metadata.generation`, timestamp, claim attempt or resourceVersion. RV remains
opaque and is compared only for exact equality.

The fingerprint is SHA-256 over the exact v1 fixed field order, UTF-8 byte-length
prefixes and literal `set_replicas`; both actions for one intent share the hash.
Expected RV and retry/claim metadata are deliberately excluded. Independent PHP
vectors cover both actions, generations beyond JavaScript integer precision and
at signed-bigint maximum, Unicode opaque identity, replica bounds, changed retry
context, and malformed/coerced/overflow fields. All required fields remain
required; no optional defaults or numeric coercion are introduced.

The [test-only PHP bridge](../../../tests/gn-g2-effects/README.md) exports actual
repository authorization decisions, fingerprints and error mappings for Go;
Go exports computed hashes and executed results for reverse PHP comparison.
Disposable tokens belong only to SQLite fixture operation rows, not deployed
credentials. Bootstrap PHP may skip reverse comparison before the Go export
exists; the final verification invocation must complete that comparison.

## Claim verification

The engine checks canonical authorization before the direct GET and again
immediately before any PATCH. It reserves the client-go rate budget before the
final callback, so waiting for a token cannot silently extend an authorization
decision. A one-shot target/client-bound dispatch permit prevents unprepared
direct PATCH calls. Local context/deadline/expiry checks follow the callback.

The callback is `POST /internal/infrastructure/v1/claims/verify`, over strict
mTLS, carrying the complete validated action. Only the exact two-field reply
`contract_version` and boolean `authorized` is accepted. Denial, malformed data,
outage, untrusted TLS, expiry or cancellation prevents dispatch. No positive
authorization is cached; no direct PostgreSQL or Redis access exists.

The PHP bridge leases actual operation rows through
[RuntimeOperationRepository](../../../apps/api/app/ControlPlane/RuntimeOperations/RuntimeOperationRepository.php)
and exercises reclaim and fenced `markRunning`. It checks token, attempt,
expiry, status, tenant and RuntimeNode. A separate test-owned canonical intent
record supplies proposed target/UID/RV/incarnation/generation/hash/action/lifecycle
bindings absent from today's production operation contract. This distinction is
essential: GN-G2 does not add a production claim route or pretend the complete
future binding is already persisted. SQLite does not prove PostgreSQL lock
concurrency. The current operation repository has no lease-renewal method;
listener lease renewal belongs to a different authority.

## Conditional mutation semantics

Trusted cluster and namespace configuration are fixed outside requests. Direct
GET must match name, namespace, UID, all seven required identity labels and a
nonterminating resource. Invalid/partial marker pairs, malformed hashes or
generations, applied generation above admission, or conflicting same-generation
markers fail closed. Matching labels never authorize an old UID after recreation.

`admit_generation` builds one JSON Patch against the parent Deployment. It tests
UID and exact RV, rejects a higher installed generation or equal generation with
a different hash, then installs admitted generation/hash together. If annotations
are absent, it creates the parent annotation map under that same RV CAS and
adds only its own keys. Unrelated annotations and fields are preserved.

`set_replicas` uses one parent-Deployment JSON Patch testing UID, RV and admitted
generation/hash, then writes absolute replicas plus applied generation/hash in
that same patch. It does not split marker and replica updates or use `/scale`,
SSA force, unconditional merge patch or a separate marker request. Direct-read
ownership checks are protected by the object's tested RV.

`already_satisfied` requires current authorization, immutable identity, matching
admitted markers and, for replicas, matching applied markers and actual replicas.
Replica equality alone is insufficient. An identical observed result can be
recognized despite an old request RV without dispatching another mutation.
Otherwise an RV mismatch returns conflict, not an automatic refresh-and-replay.

An explicit 409 is `resource_version_conflict`; generic 400/422 remains
`invalid_request`. A later changed resource cannot prove what caused a prior
422. No speculative predicate-specific mapping grants retry permission. The
deferred real-server gate must establish actual structured API Status signatures
before a narrower mapping is introduced. These implementation choices follow
the [Kubernetes API concepts](https://kubernetes.io/docs/reference/using-api/api-concepts/)
and [Deployment API](https://kubernetes.io/docs/reference/kubernetes-api/apps/deployment-v1/)
model; documentation and simulation are not real-server proof.

## Distributed-write safety and recovery

Chosen model: **generation-barrier conditional convergence**. PostgreSQL orders
canonical intents; Kubernetes applies conditional effects on one exact immutable
target. Local serialization is a load limit; API-side predicates are intended to
protect across adapter instances. There is no atomic PostgreSQL/Kubernetes
transaction or promise that an expired lease retracts an in-flight request.

| Scenario | Implemented decision / limitation |
| --- | --- |
| A: claim expired before dispatch | Reject locally or at current full-action callback; no PATCH |
| B: claim expires after dispatch | Previously authorized request can still apply; context cancellation does not retract server execution |
| C: older action after newer barrier | Old UID/RV/barrier predicates reject; simulation covers ordering, real API enforcement remains deferred |
| D: older action before newer barrier | Temporary stale effect remains possible; later current intent establishes a newer barrier and converges |
| E: expiry with unchanged RV | RV does not encode lease validity; current claim is still required |
| F: duplicate intent | Matching markers plus effect and current authorization yield observed satisfaction; no durable Go dedup store |
| G: same generation, different hash | Deterministic ownership conflict, no write |
| H: concurrent adapters | Local per-target locks do not span instances; competing conditional patches must be resolved by server CAS |
| I: response lost | `outcome_unknown`, `effect=unknown`, `dispatch=unknown`, `retry=observe_first`; no automatic mutation retry |
| J: name reuse | Exact old UID/incarnation binding rejects a replacement even with the same name and labels |
| K: rollback in flight | Requires separately authorized quiescence, effective credential cutoff and newer conditional barrier; no unrestricted PHP fallback |

`Recover` performs direct GET-only inspection, verifies current claim and exact
identity/markers/effect, and returns observed satisfaction, supersession/conflict
or drift. It never dispatches a retry. A not-applied lost response cannot be
reported as confirmed failure merely because the current state still lacks
markers. Laravel must durably record uncertainty, retain eligibility holds,
reauthorize any subsequent conditional attempt and fence operation completion.

Before dispatch, rejection is `not_sent`; explicit API refusal is `rejected`.
Confirmed valid application is `response_received`. Postdispatch I/O failure,
deadline/cancellation, ambiguous 5xx or malformed success response is unknown.
An API effect success does not complete a telephony operation. A lease-lost
receipt cannot bypass canonical PostgreSQL fencing.

The PHP compatibility projection retains the full normalized envelope; exception
reason alone must never discard uncertainty or schedule a blind retry.

| Normalized outcomes | Existing PHP compatibility reason |
| --- | --- |
| `success`, `already_satisfied` | No exception |
| `invalid_request` | `invalid_request` |
| `unauthorized`, `forbidden` | `permission_denied` |
| `target_not_found`, `identity_mismatch` | `target_mismatch` |
| `ownership_conflict` | `ownership_conflict` |
| `resource_version_conflict`, `stale_generation`, `drift` | `fence_in_progress` |
| `timeout`, `cancelled`, `transport_unavailable`, `retryable_failure`, `outcome_unknown`, `busy` | `unavailable_to_control` |
| `terminal_failure` | `failed` |

The normalized `dispatch`, `effect` and `retry` fields remain authoritative for
transport uncertainty. In particular, `outcome_unknown` is still
`unknown/unknown/observe_first` after compatibility mapping. Existing PHP failure
classes alone do not implement that future recovery corridor.

## Security, bounds and observer preservation

The action handler accepts only POST on the fixed action path, verified client
certificate chains and the configured executor URI. An observer certificate
cannot call it. The reused GN-G1 TLS material verifies CA, certificate identity,
EKU/expiry and strict server identity; invalid reload retains last-good material.
Tests use only ephemeral keys/certificates. No key, token or raw operation body
is committed or emitted through normalized errors.

Limits are 1 MiB request, 16 MiB response, maximum five-second work budget clamped
to caller deadline, four concurrent target actions, one local action per target,
no queue, client-go QPS 5/burst 10 and no mutating HTTP retry. Claim responses
have a separate 16 KiB limit. GET/PATCH are limited to configured parent
Deployment paths; scale, Secret, create, delete and alternate URLs are refused.
All error output is generic or projected contract metadata, not raw upstream
messages. Go receives no PostgreSQL/Redis credential.

GN-G1 files and normative v1 observation artifacts are preserved. The full
observer verifier remains a required regression, including pagination/410,
freshness, redaction, mTLS, PHP parity and cancellation. No v1 change silently
repairs the separately identified Pod deletion timestamp limitation.

## Verification environment and commands

Pinned baseline: Go 1.25.8, client-go/API/apimachinery v0.35.3, SDK
`golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`.
PHP verification uses the existing image
`sha256:9efe210fb8c8e7c90d80fce2b6916b46824f0f0428d6037dd599ec5ef1dd4d7d`
(PHP 8.4.15 / PHPUnit 11.5.56). No host packages are installed. The verifier mounts
sources read-only, uses the existing module cache and disposable `/tmp` exports,
and disables external networking for container verification.

From repository root:

```bash
components/kubernetes-effects/verify
make repository-hygiene
make phase-status-consistency-check
make runtime-engine-config-check
git diff --check
```

The one-command verifier runs PHP export, pinned Go formatting/module integrity,
`go test -count=1 ./...`, `go test -race -count=1 ./...`, `go vet ./...`, reverse
PHP comparison, the complete GN-G1 verifier and GN-G2 static checks. No real API
server is contacted. The completed pinned verifier returned exit 0.

| Exact command / check | Confirmed result |
| --- | --- |
| `components/kubernetes-effects/verify` | PASS; complete offline Go/PHP/GN-G1/static sequence, exit 0 |
| `go test -count=1 -v ./...` in effects module | PASS; 25 top-level tests, 166 passing test entries including subtests |
| `go test -race -count=1 ./...` in effects module | PASS |
| `go vet ./...` in effects module | PASS |
| `go mod verify` in effects module | PASS; pinned modules verified |
| `test -z "$(gofmt -l *.go)"` in effects module | PASS |
| `tests/gn-g2-effects/verify-php` final reverse-parity invocation | PASS; 111 tests, 597 assertions, no skipped tests |
| `components/kubernetes-observer/verify` | PASS; 21 top-level Go tests / 107 passing entries including subtests; count/race/vet/module/format checks pass; PHP 59 tests / 368 assertions |
| GN-G2 / GN-G1 static documentation and isolation checks | PASS; 26 / 67 task-owned files respectively |
| `make repository-hygiene` | PASS |
| `make phase-status-consistency-check` | PASS |
| `make runtime-engine-config-check` | FAIL; unchanged pre-existing three-workload API-client-label assertion |
| `git diff --check` | PASS |

The expanded focused PHP invocation includes existing RuntimeOperationRepository,
HttpKubernetesWorkloadClient, KubernetesResourceWriter and KubernetesRuntimeFence
regressions alongside the new claim/fingerprint/result bridge. PHP/Go proof
exchanges cover eight positive fingerprints, twelve negative fingerprint inputs,
fourteen actual repository-backed claim decisions and all eighteen normative
error projections. These counts describe developer tests, not real-server or
telephony acceptance. Independent read-only architecture and security reviews
reported no remaining material findings in the final reviewed scope.

| Proof class | Supported claim / limitation |
| --- | --- |
| Source inspected | Narrow interfaces, authority separation, caller mapping and no production binding |
| Go unit tests | Decoder/fingerprint/identity/marker/authorization/error and state decision behavior |
| RFC6902 in-memory simulation | Generated patch predicates, ordering and atomic application in the simulator |
| HTTPS protocol simulation | client-go request path/content type, TLS, lost-response/no-retry and bounds |
| PHP integration simulation | Actual repository leases/reclaim/fenced transitions on disposable SQLite, full-action test record and reverse parity |
| Real Kubernetes API server | Deferred / not verified; admission/defaulting/subresources/true CAS Status behavior not proven |
| Deployed / natural-live | Not attempted or claimed; no production writer transfer |

## Independent baseline validation defect

The reproduced `make runtime-engine-config-check` assertion rejects the Kubernetes
API-client label on `utcp-platform/api`, `utcp-platform/scheduler` and
`utcp-platform/telephony-reconciler`. The historical static assertion conflicts
with current accepted K5 manifest/test expectations for those API consumers and
their dedicated ServiceAccounts. GN-G2 must reproduce its exact signature and
keep it visible; it does not modify the checker or accepted workload permissions.

Exact assertion: `ordinary platform workers must not carry the Kubernetes API
client label`, followed by the three workload identities above. This is the
same failure signature reported in GN-G0/GN-G1; it was not introduced by GN-G2.

Separate repair: replace the obsolete blanket prohibition with exact reviewed
API-consumer/ServiceAccount assertions, retaining negative tests for an ordinary
unapproved worker gaining the label, incorrect account/RBAC binding and unwanted
write scope. Do not broadly permit all platform workloads. This baseline
acceptance dependency is independent of isolated conditional-effect correctness.

## Remaining decisions and next work

The [lifecycle v2 proposal](gn-g2/lifecycle-v2-proposal.md) specifies concrete
closed objects, incarnation/UID binding, terminal retirement, fixed projection,
create races, conditional delete and unknown outcomes. It is not implemented or
adopted. Zero replicas alone cannot prove a delayed orphan harmless: require
exclusive activation, no autoscaler/other writer, inert template/references and
canonical cleanup. Physical nonresurrection needs a separate approved target-side
mechanism if required.

Before any GN-G3 execution, the operator must authorize ADR/contract adoption,
complete lifecycle implementation and real API-server validation, supply scoped
environment/cutover authority and disposition the baseline checker defect.
The [real API-server procedure](gn-g2/real-api-verification.md) is an unexecuted
proof specification. The [GN-G3 assignment](gn-g2/GN-G3-implementation-assignment.md)
is a complete proposed handoff, conditional on those gates.

Initial legacy writers cannot be neutralized by a new generation marker: their
unconditional scale/merge/DELETE requests do not test it. All five corridors and
credentials must quiesce; effective old authority must be revoked and tested;
all already accepted legacy requests need server-confirmed terminal resolution.
If audit continuity or handler-terminal proof is incomplete, same-incarnation
cutover is prohibited. A separate isolation design must also contain delayed
legacy create-at-one effects. Rollback can enable only a conditional-compatible
writer under a newer canonical execution generation.

## Final validation and file inventory

The completed developer verification supports isolated implementation with
simulated proof only. The independent worktree inspection confirms 26 new GN-G2
files and 143 total untracked files;
all 117 pre-existing files match their baseline SHA-256 bytes. No tracked or
staged changes, commit or push occurred. Recheck these boundaries at handoff.

Complete new-file inventory:

```text
components/kubernetes-effects/README.md
components/kubernetes-effects/claims.go
components/kubernetes-effects/engine.go
components/kubernetes-effects/engine_test.go
components/kubernetes-effects/go.mod
components/kubernetes-effects/go.sum
components/kubernetes-effects/kubernetes.go
components/kubernetes-effects/kubernetes_test.go
components/kubernetes-effects/parity_test.go
components/kubernetes-effects/schema.go
components/kubernetes-effects/transport.go
components/kubernetes-effects/transport_test.go
components/kubernetes-effects/types.go
components/kubernetes-effects/verify
components/kubernetes-effects/verify-static
docs/architecture/proposals/UTCP_GN_G2_CONDITIONAL_KUBERNETES_DEPLOYMENT_EFFECTS.md
docs/architecture/proposals/gn-g2/GN-G3-implementation-assignment.md
docs/architecture/proposals/gn-g2/lifecycle-v2-proposal.md
docs/architecture/proposals/gn-g2/real-api-verification.md
tests/gn-g2-effects/ClaimVerificationBridge.php
tests/gn-g2-effects/ConditionalEffectParityTest.php
tests/gn-g2-effects/README.md
tests/gn-g2-effects/ResultCompatibilityBridge.php
tests/gn-g2-effects/fixtures/fingerprints.json
tests/gn-g2-effects/fixtures/invalid-fingerprints.json
tests/gn-g2-effects/verify-php
```
