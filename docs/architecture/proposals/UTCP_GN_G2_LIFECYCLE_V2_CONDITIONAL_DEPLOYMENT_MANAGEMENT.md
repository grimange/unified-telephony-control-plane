# GN-G2 Lifecycle V2 — Conditional Deployment Management

Assignment: `UTCP_GN_G2_LIFECYCLE_V2_CONDITIONAL_DEPLOYMENT_MANAGEMENT`.
Date: 2026-10-21. Architecture status: **PROPOSED / NOT ADOPTED**.

## Executive result

**`GN_G2_LIFECYCLE_V2_IMPLEMENTED_WITH_LIMITATIONS`.** The isolated implementation
supports three
negotiated `utcp.infrastructure.v2` effects: `create_suspended`,
`converge_projection`, and `retire_delete`. Offline and combined real API/race/vet verification pass. Final owned-cluster
cleanup is recorded below. This document does not adopt the ADR
or authorize production integration.

A material scope limitation remains: the executable projection profile is
**`inert.pause.v1` only**. Active Asterisk and FreeSWITCH profiles from the
original lifecycle proposal are unsupported. The implementation proves bounded
object lifecycle mechanics; it does not yet replace all five PHP Deployment
mutation corridors or establish telephony workload parity. The next packet is
[active projection implementation](gn-g2/GN-G2-active-projection-implementation-assignment.md),
not writer cutover.

## Repository identity and preservation

Starting branch: `main`; HEAD:
`4ef700639c50a58933bc5a0d1e7ae08628cc4c62`. Starting state: zero tracked or staged
changes and 150 untracked E1–GN-G2 artifacts. A SHA-256 inventory of those files
was captured before implementation. No reset, clean, commit or push is
permitted in this assignment. Final preservation and file inventory are recorded
in the validation section.

The [phase ledger](../../roadmap/phase-status.md) continues to govern current
V1/K5 classifications, canonical environment and RMA-E next action. This
isolated proposed adapter does not alter those authorities. The
[adapter ADR](go-kubernetes-infrastructure-adapter-adr-proposed.md) remains
proposed. The [earlier lifecycle design](gn-g2/lifecycle-v2-proposal.md) records
the prior target architecture; the [executable contract](gn-g2/contracts/v2/README.md)
states the implemented restricted behavior and its deliberate differences.

## Current production behavior and authority

Laravel/PostgreSQL retains tenant authorization, RuntimeNode identity, desired
telephony state, generation allocation, operation claims, lifecycle transitions,
UID binding, durable results and audit. Kubernetes owns object UID, resource
version and actual state. The isolated Go adapter executes a freshly authorized
bounded infrastructure effect; it opens no PostgreSQL/Redis connection and
allocates no canonical generation. No independent reconciler, CRD, controller,
production listener, route, ServiceAccount or RBAC change is introduced.

The production PHP mutation paths remain unchanged:

| Application corridor | Existing entry point | Current workload effect |
| --- | --- | --- |
| Managed provisioning | [Asterisk provisioning](../../../apps/api/app/RuntimeProvisioning/ManagedAsteriskProvisioningOperationHandler.php), [FreeSWITCH provisioning](../../../apps/api/app/RuntimeProvisioning/ManagedFreeSwitchProvisioningOperationHandler.php) | `applyDeployment` |
| Managed convergence | [Managed workload convergence](../../../apps/api/app/RuntimeProvisioning/ManagedRuntimeWorkloadConvergenceOperationHandler.php) | Provider projection through `applyDeployment` |
| Runtime fencing | [Kubernetes fence adapter](../../../apps/api/app/Infrastructure/RuntimeFencing/KubernetesRuntimeFenceAdapter.php) | `scaleDeployment(..., 0)` |
| Runtime restoration | [Restore operation handler](../../../apps/api/app/Infrastructure/RuntimeFencing/RuntimeNodeRestoreOperationHandler.php) | Authorized absolute scale |
| Managed deprovisioning | [Asterisk deprovisioning](../../../apps/api/app/RuntimeProvisioning/ManagedAsteriskDeprovisioningOperationHandler.php), [FreeSWITCH provider](../../../apps/api/app/RuntimeProvisioning/ManagedFreeSwitchProvisioningOperationHandler.php) | `deleteDeployment` |

Their transport is [HttpKubernetesWorkloadClient](../../../apps/api/app/Infrastructure/RuntimeFencing/HttpKubernetesWorkloadClient.php).
Current production templates contain active telephony images, Secret references,
probes and, for FreeSWITCH, writable runtime volumes. They cannot be represented
by the inert-only executable profile. The existing PHP resource naming and
persistence do not yet implement the proposed incarnation lifecycle.

## Implemented components and negotiated contract

The new [lifecycle package](../../../components/kubernetes-effects/lifecycle/types.go)
is separate from the existing v1 engine in the same pinned Go module:

| Component | Responsibility |
| --- | --- |
| `types.go`, `schema.go`, embedded `contracts/` | Closed v2 objects, action-specific validation, lossless signed-bigint decimal generations, deterministic intent/projection hashing |
| `engine.go` | Identity, marker, claim, lifecycle effect and read-only recovery decisions; bounded work admission |
| `kubernetes.go` | Direct client-go exact Deployment GET/POST/PATCH/DELETE, no automatic CAS refresh, `/scale`, list or Secret operation |
| `claims.go` | Full-envelope, current claim callback over existing rotating mTLS client; no positive authorization caching |
| `transport.go` | Isolated authenticated internal HTTP handler, bounded decoding and safe result projection |
| Unit, parity, transport and real API tests | Contract, failure, concurrency, identity, recovery and security regression coverage |

The [action schema](gn-g2/contracts/v2/action.schema.json),
[result schema](gn-g2/contracts/v2/result.schema.json) and
[fixture manifest](gn-g2/contracts/v2/fixtures.json) accompany the implementation.
Only `POST /internal/infrastructure/v2/actions` with
`contract_version=utcp.infrastructure.v2` is accepted. Unsupported versions,
actions, fields, duplicate keys, malformed identities, numeric generations,
ambiguous values and fingerprints fail closed. Requests are limited to 64 KiB.
No v1 normative field or action is broadened.

The intent fingerprint uses a fixed-order UTF-8 byte-length-prefixed encoding of
version, tenant, RuntimeNode, immutable physical identity, generation, intent
kind and bounded payload digests/scalars. The UID slot is empty only for create;
resourceVersion, claim and retry metadata are excluded. Projection hashing
includes every field of the closed profile. Independent PHP vectors exercise
positive and negative compatibility, including generations above JavaScript's
safe integer range.

## Canonical lifecycle and full claim verification

[Test-only PHP authority](../../../tests/gn-g2-lifecycle/LifecycleAuthority.php)
models `preparing -> bound -> retiring -> retired`. It allocates random
incarnations and `utcp-<incarnation>` physical names, retains name tombstones,
protects revisions, binds one observed UID, forbids incompatible rebinding and
makes retirement terminal. A delayed suspended orphan may bind an exact UID for
retirement cleanup only; that does not return the incarnation to bound state.

The PHP bridge exercises actual
[RuntimeOperationRepository](../../../apps/api/app/ControlPlane/RuntimeOperations/RuntimeOperationRepository.php)
lease acquisition/reclamation and verifies the operation row's token, attempt,
lease expiry, tenant and RuntimeNode together with the complete canonical v2
envelope. Current lifecycle state and fingerprint must agree. Expired,
superseded, malformed, unavailable or unauthorized verification fails closed;
there is no positive authorization cache or direct Go database access.

This is a test authority model using SQLite/in-memory state. It is **not** a
production lifecycle migration or proof of PostgreSQL multi-process CAS.
Production lifecycle persistence, transaction boundaries, retained tombstones
and callback authorization still require a separately authorized implementation.

## Creation safety and immutable binding

`create_suspended` requires a current preparing claim, unbound UID/RV fields,
trusted cluster/namespace, exact ownership and a physical name derived from the
immutable incarnation. Kubernetes name uniqueness enforces create-if-absent.
An existing resource is not silently adopted: exact identity, complete suspended
profile, markers and effect must match. Conflicting ownership or projection
fails closed; a create race requires observation and fresh canonical authority,
not upsert. The result exposes Kubernetes' assigned UID/RV for Laravel's one-way
binding decision.

Creation constructs a fixed `/pause` container with the immutable pause 3.10 OCI
index digest documented in the contract. There are no init containers, sidecars,
ports, hooks, probes, executable configuration, environment, Secret/ConfigMap
mounts, volumes or host access. Token automount/service links are disabled;
nonroot identity, dropped capabilities, read-only root filesystem, no privilege
escalation and RuntimeDefault seccomp are fixed. The Deployment is paused and
initially has zero replicas. Complete typed Pod-template validation rejects
unexpected security/template fields; only enumerated Kubernetes defaults are
normalized. Unrelated parent metadata/spec fields remain outside that owned
projection.

This template restriction, rather than replicas alone, bounds delayed creation.
The pause image's no-telephony behavior is a supply-chain assumption; API tests
do not attest arbitrary registry image contents. Other writers, admission
webhooks, autoscalers and separately created resources remain distinct
activation authorities to constrain before production adoption.

A POST already dispatched can arrive after canonical retirement. The test
exercises that race: the object can appear, but remains the exact inert
incarnation; stale activation/recovery authority is refused and cleanup requires
a new authorized retirement intent. Absence after an uncertain create is not
permanent nonresurrection. No Kubernetes-side tombstone admission controller or
PostgreSQL/Kubernetes atomic transaction is claimed.

## Conditional projection and deletion

`converge_projection` accepts a bound UID, expected RV, current generation and
full intent fingerprint. A single parent Deployment JSON Patch tests UID, RV
and the observed barrier, then atomically installs the new authorized barrier,
controlled template/configuration, absolute replicas and applied markers.
Lower generations and equal-generation conflicting fingerprints are refused.
There is no unconditional fallback, automatic RV refresh or blind replay.
Unrelated parent fields survive; the complete profile owns the Pod template and
refuses incompatible third-party injection. The initial profile remains paused
and inert even with a nonzero desired replica count.

`retire_delete` requires a current retiring claim, exact bound identity and
observed zero replicas. It first installs the retirement barrier/ID with CAS,
then obtains a **fresh claim** before DELETE with UID and the returned RV
preconditions. These are **two Kubernetes operations**, not an atomic combined
annotation/delete transaction. Any intervening change can invalidate deletion.
If the barrier succeeds but the subsequent claim is denied, the result retains
`retirement_admitted` as a partial-effect receipt; it does not report no effect.
No force deletion or unknown-finalizer removal occurs. `deletion_accepted`,
`terminating` and `observed_absent` remain distinct. Telephony draining and owned
Pod completion are Laravel admission responsibilities, not inferred by Go from
zero replicas or HTTP success. Kubernetes deletion preconditions are defined in
the [official API reference](https://kubernetes.io/docs/reference/kubernetes-api/definitions/preconditions-v1-meta/).

## Unknown outcomes and distributed-write limits

| Uncertain effect | Observe-first behavior |
| --- | --- |
| Create applied, response lost | Verify exact physical identity, ownership, profile and suspended markers; return evidence, never bind canonically in Go |
| Create not applied or still delayed | Remain unknown when absent; no blind POST or assumption of permanent absence |
| Projection applied, response lost | Verify exact UID, barrier/applied fingerprints, configuration and replicas; replica equality alone is insufficient |
| Projection not applied | Report observed drift; require current canonical authorization for any subsequent CAS |
| Delete response lost | Distinguish exact target still present, terminating, absent or replaced; no unconditional replay |
| Claim superseded/resource replaced | Reject old authority/UID; never recover against a replacement or activate a PHP fallback |

Dispatched transport interruption yields `outcome_unknown`, `effect=unknown`,
`dispatch=unknown`, `retry=observe_first`. Read-only recovery sends no mutations.
A lease verified immediately before dispatch can expire while a request is in
flight; PostgreSQL cannot retract it. An older mutation may apply before a newer
Kubernetes barrier; once that barrier exists, UID/RV/barrier predicates prevent
its overwrite. Convergence remains canonically scheduled by Laravel.

## Real API verification environment and observations

One newly created owned k3d cluster was used:

| Property | Value |
| --- | --- |
| Cluster / explicit context | `gn-g2-proof-6d25ea213201` / `k3d-gn-g2-proof-6d25ea213201` |
| Kubernetes | `v1.35.3+k3s1` |
| API | Verified loopback `https://127.0.0.1:32772` |
| k3s image | `rancher/k3s@sha256:4607083d3cac07e1ccde7317297271d13ed5f60f35a78f33fcef84858a9f1d69` |
| Go SDK | Go 1.25.8, `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084` |
| Kubernetes libraries | client-go / api / apimachinery v0.35.3 |
| PHP environment | Existing pinned PHP 8.4 / PHPUnit image, SQLite test database |

The [harness](../../../tests/gn-g2-lifecycle/README.md) verifies the fresh cluster
name, private single-context kubeconfig, loopback HTTPS, cluster UID, run labels,
nonce/container ownership and cleanup scope. Canonical native k3s and historical
`utcp-local` are excluded. Fixture administration and two restricted adapter
ServiceAccounts are separate. No live UTCP credentials or databases are used.

The [sanitized API/RBAC evidence](gn-g2/lifecycle-v2-real-api-evidence.json)
records concise structured outcomes without credentials or raw objects.
Observed real API behavior in the controlled run includes:

| Case | Actual API/result behavior |
| --- | --- |
| Create/defaulting/name collision | Assigned UID, suspended profile and API defaults preserved; duplicate raw POST gives 409 `AlreadyExists`; adapter observes exact duplicate idempotently |
| Parent JSON Patch UID/RV/generation/hash predicate failure | 422 `Invalid`, no protected update; generic 422 remains conservative `invalid_request`, not an invented retryable conflict |
| Projection/barrier CAS | Atomic marker/configuration update, unrelated fields preserved; old generation and stale UID/RV rejected |
| Two adapters, same observed RV | Exactly one successful conditional mutation and one rejection; no partial marker/configuration state |
| Duplicate/competing create | One name winner; identical loser observes satisfied state, conflicting intent refuses adoption |
| Wrong delete UID/RV | 409 `Conflict`; exact-identity preconditions protect replacement resources |
| Finalizer hold | Delete accepted, subsequent observation terminating, removal only after fixture-admin release, then absence confirmed |
| Same-name replacement | New UID; old projection, delete and recovery rejected |
| Delayed create after modeled retirement | Already dispatched POST may apply; remains suspended, fresh exact-identity cleanup required |
| Lost create/patch/delete response | Explicit uncertainty and read-only recovery; no automatic mutation replay |

Deletion proof uses complementary cases: finalizer/lost-DELETE fixtures have
the retirement barrier preadmitted by the fixture administrator to isolate
DELETE semantics. A separate adapter-driven test exercises barrier CAS followed
by an intervening RV change and 409 rejection, retaining the partial-effect
receipt. These are not represented as one combined atomic retirement/delete
operation.

These observations establish actual Kubernetes object/API semantics for this
server version. Canonical retirement/lease changes are controlled test-bridge
state, not production PostgreSQL execution. No active telephony Pod or call was
part of the proof.

## Security and bounded execution

Internal v2 transport reuses the mTLS foundation and configured caller URI
identity, trusted CA and certificate verification. Regression tests exercise
valid/invalid identities, cancellation, deadlines and claim responses. Requests
and normalized results are closed projections, not raw Kubernetes objects or
arbitrary manifests. Credentials, raw upstream messages and private keys do not
appear in results or committed evidence. At most four active requests and one
active request per physical target per Engine bound local work; Kubernetes CAS
protects across instances. No unbounded retry loop or authorization cache exists.

Restricted real adapter RBAC permits Deployment creation only in the owned test
namespace and exact named GET/PATCH/DELETE fixtures. Kubernetes RBAC cannot
restrict CREATE by `resourceNames`; that namespace-level permission limitation
is explicit, and the bounded application envelope remains mandatory. Nine real
403 denials cover Secrets, Node mutation, cross-namespace create/get/delete,
unrelated-name patch, `/scale` patch, Service creation and Deployment list.
Production RBAC is unchanged.

## Validation and evidence classification

The complete offline verifier passed. The combined real API run passed 33 v1 and 28 lifecycle PASS entries
(61 total); the lifecycle run records 38 sanitized evidence records. Both packages also pass real API race detection and
`go vet -tags realapi ./...`. Owned-cluster cleanup is confirmed. The
reproducible authorized entry point is:

```bash
tests/gn-g2-lifecycle/verify
```

The default command performs offline checks, creates one new owned cluster,
runs old v1 and new v2 real API suites, and verifies cleanup. Diagnostic modes
are `verify offline` and `verify execute <owned-run-directory>`; the latter was
used to reuse the single cluster during root-cause correction. It must never be
pointed at arbitrary kubeconfigs or existing canonical clusters.

Commands executed in the pinned environments:

| Command/category | Result |
| --- | --- |
| `tests/gn-g2-lifecycle/verify offline` | PASS; complete orchestrated offline suite |
| Existing real harness `test_verify.py` | PASS, 6 safety tests |
| Lifecycle `test_verify.py` | PASS, 3 static allowlist-scope regressions |
| `tests/gn-g2-lifecycle/verify-php` | PASS, 20 tests / 209 assertions, including actual repository lease tests and six reverse Go fingerprint vectors |
| `components/kubernetes-effects/verify` | PASS; v1 engine 26 top-level / 169 PASS entries; focused PHP 111 tests / 597 assertions |
| `components/kubernetes-observer/verify` through effects verifier | PASS; 21 top-level / 107 PASS entries; PHP 59 tests / 368 assertions |
| Lifecycle `go test -count=1 -v ./lifecycle` with PHP output mounted | PASS, 15 top-level / 86 PASS entries; PHP fingerprint and canonical claims enabled |
| `go test -count=1 ./...`, `go test -race -count=1 ./...`, `go vet ./...`, `go mod verify` through existing module verifiers | PASS; no dependency upgrade |
| Lifecycle `go test -race -count=1 ./lifecycle`, `go vet ./lifecycle`, Go formatting | PASS |
| v1/v2 schemas, normative fixtures, embedded/document schema equality | PASS in module/static checks |
| `tests/gn-g2-lifecycle/verify execute <owned-run-directory>` | PASS, v1 33 and lifecycle 28 regular PASS entries; both real API race suites and tagged vet PASS |
| `tests/gn-g2-lifecycle/verify-static` | PASS, 26 files: documentation links, UTF-8, whitespace, JSON, PHP syntax, private-key and isolation checks |
| `make repository-hygiene phase-status-consistency-check` | PASS on final tree: 177 changed/untracked files, status impact `no` |
| `git diff --check` | PASS; untracked file checks are performed separately above |

The parent effects verifier deliberately has no new PHP proof-output mount and
therefore skips the two new PHP-dependent lifecycle tests in that invocation.
The lifecycle-specific verifier subsequently mounts independently generated PHP
outputs and executes both successfully; these are not unverified parity skips.
PASS-entry totals include nested subtests and are not counts of independent
runtime scenarios. Timing is a controlled test observation, not a performance
or production capacity claim.

Required categories: lifecycle Go unit/race/vet/module/format checks, v2 and v1
schemas/fixtures, independent PHP fingerprint and full canonical-claim parity,
PHP repository lease tests, GN-G1/GN-G2 regressions, mTLS, concurrency/fault
injection, real API lifecycle/RBAC, harness safety, documentation, hygiene,
phase consistency and `git diff --check`.

The unchanged independent baseline failure is:

```text
make runtime-engine-config-check
ordinary platform workers must not carry the Kubernetes API client label:
['utcp-platform/api', 'utcp-platform/scheduler', 'utcp-platform/telephony-reconciler']
```

The historical static assertion conflicts with labels expected by accepted K5
workloads. It is not suppressed or repaired in this packet. A separate narrow
checker repair with positive/negative regressions remains a broader acceptance
dependency.

| Evidence class | Supported claim / boundary |
| --- | --- |
| Source inspection | Five PHP mutation corridors and restricted Go/contract authority |
| Go unit/HTTP simulation | Decision logic, normalization, schema, cancellation, fault and local concurrency behavior |
| PHP integration simulation | Actual operation repository methods plus test-only lifecycle authority; not PostgreSQL production CAS |
| Real Kubernetes API | Create uniqueness/defaulting, conditional parent patch, deletion preconditions/finalizers, identity replacement and restricted RBAC |
| Deployed / natural-live telephony | **Not performed**; no production routing, writers, workloads or tenant traffic changed |

## GN-G3 readiness and next work

Completed mechanics do not imply writer-cutover readiness. Active provider
projection profiles and five-corridor parity are the immediate technical gap.
Thereafter production lifecycle persistence/one-way binding/tombstones,
production full-envelope claim callback, adopted architecture/contracts,
legacy in-flight request draining, pending/unknown-outcome migration,
exclusive application writer credentials, external replica-writer/HPA
constraints, old PHP credential cutoff, conditional-compatible rollback,
V1/K5 natural-live proof and the independent baseline checker disposition
remain explicit prerequisites. No flag alone proves credential-level cutoff.

The next bounded assignment is linked above. GN-G3 must remain separately
authorized after those prerequisites are evidenced.

## Changed-file inventory and final disposition

The task-owned inventory is:

```text
components/kubernetes-effects/lifecycle/claims.go
components/kubernetes-effects/lifecycle/contracts/action.schema.json
components/kubernetes-effects/lifecycle/contracts/fixtures.json
components/kubernetes-effects/lifecycle/contracts/result.schema.json
components/kubernetes-effects/lifecycle/engine.go
components/kubernetes-effects/lifecycle/engine_test.go
components/kubernetes-effects/lifecycle/kubernetes.go
components/kubernetes-effects/lifecycle/parity_test.go
components/kubernetes-effects/lifecycle/real_api_test.go
components/kubernetes-effects/lifecycle/schema.go
components/kubernetes-effects/lifecycle/transport.go
components/kubernetes-effects/lifecycle/transport_test.go
components/kubernetes-effects/lifecycle/types.go
components/kubernetes-effects/verify-static
docs/architecture/proposals/UTCP_GN_G2_LIFECYCLE_V2_CONDITIONAL_DEPLOYMENT_MANAGEMENT.md
docs/architecture/proposals/gn-g2/GN-G2-active-projection-implementation-assignment.md
docs/architecture/proposals/gn-g2/contracts/v2/README.md
docs/architecture/proposals/gn-g2/contracts/v2/action.schema.json
docs/architecture/proposals/gn-g2/contracts/v2/fixtures.json
docs/architecture/proposals/gn-g2/contracts/v2/result.schema.json
docs/architecture/proposals/gn-g2/lifecycle-v2-proposal.md
docs/architecture/proposals/gn-g2/lifecycle-v2-real-api-evidence.json
tests/gn-g2-lifecycle/LifecycleAuthority.php
tests/gn-g2-lifecycle/LifecycleParityTest.php
tests/gn-g2-lifecycle/README.md
tests/gn-g2-lifecycle/test_verify.py
tests/gn-g2-lifecycle/verify
tests/gn-g2-lifecycle/verify-php
tests/gn-g2-lifecycle/verify-static
```

The two modified prior artifacts are the isolated effects static verifier and
historical lifecycle proposal. The former permits POST/DELETE only in the exact
new lifecycle engine/client paths and has positive/negative scope regressions;
the latter adds a dated successor link without changing its historical meaning.
All other paths above are new. The original 150-file SHA-256 inventory confirms
148 files byte-for-byte unchanged, exactly those two scoped updates and no
missing artifacts. Branch/HEAD remain unchanged; tracked/staged changes remain
zero. Final state contains 177 untracked files: the original 150 plus 27 new
files, with the two scoped edits remaining untracked. Final disposable-cluster
cleanup is confirmed: the owned cluster, network and volume were removed, the
private kubeconfig was deleted, and pre-existing cluster identities were
preserved.
Production runtime/application files, database schemas, manifests, RBAC and
accepted ADRs remain unchanged. No commit or push was performed.

Final disposition:

- Implementation: all three restricted-profile effects implemented and
  developer/API-tested; active provider projections remain unsupported.
- Real Kubernetes API proof: PASS for the bounded object lifecycle protocol;
  zero Pods/ReplicaSets observed in the two owned fixture namespaces and both
  namespaces confirmed absent before cluster cleanup.
- Go unit/race/vet/module/format, PHP parity, schemas, mTLS, RBAC and prior
  observer/effects regressions: PASS.
- Baseline static check: FAIL, unchanged independent K5 label mismatch; no
  suppression or incidental production checker change.
- Production deployment / writer cutover / ADR adoption: none.
- Current-State Ledger Impact: `NO_CHANGE_REQUIRED`.
- Phase-Status Consistency Check: `PASS`.
- Working tree: branch/HEAD unchanged, zero tracked/staged changes, 177 untracked
  files; all prior artifacts retained as described above.
- Disposable Cluster Cleanup: `CONFIRMED`.

