# UTCP GN-G2 — Real Kubernetes API conditional-effect verification

Assignment: `UTCP_GN_G2_REAL_KUBERNETES_API_CONDITIONAL_EFFECT_VERIFICATION`.
Date: 2026-10-21. Worker: OpenAI Codex CLI, acting as Principal Kubernetes
Control Plane Verification Engineer / Distributed Systems Engineer. GPT-6 with
High reasoning was requested; actual model configuration is not independently
exposed. Tools: Git/ripgrep, Docker/k3d, pinned Go/client-go, existing PHP PHPUnit,
and repository verification scripts. Scope: an explicitly authorized disposable
real API-server proof of the isolated GN-G2 library.

**Verdict: `GN_G2_REAL_API_VERIFIED`.** A fresh owned Kubernetes API server
passed all 16 scenario groups, Go race detection and tagged vet. Offline Go/PHP
regressions also passed, and owned-cluster cleanup was independently verified.
This proves the recorded server's conditional mutation semantics for the two
isolated effects. It does not adopt the proposed ADR, deploy the adapter or
authorize Deployment writer transfer.

The [original GN-G2 implementation report](UTCP_GN_G2_CONDITIONAL_KUBERNETES_DEPLOYMENT_EFFECTS.md)
remains a historical account of simulated verification. This later packet
supersedes its deferred API-server gate only to the extent proven below. Neither
report establishes deployed UTCP or natural-live telephony acceptance.

## 1. Repository baseline

| Item | Starting state |
| --- | --- |
| Starting / final branch | `main` (unchanged) |
| Starting / final HEAD | `4ef700639c50a58933bc5a0d1e7ae08628cc4c62` (unchanged) |
| Tracked unstaged / staged changes | 0 / 0 |
| Pre-existing untracked artifacts | 143, including E1/GN-G0/GN-G1/GN-G2 |
| Preservation | All 143 baseline paths preserved; 137 unchanged and six intentional scoped changes; seven new paths |
| Commit / push | No staging, commit or push performed; optional scoped local commit not used |

The [canonical phase ledger](../../roadmap/phase-status.md), accepted V1/K5 state
and RMA-E next action are outside this isolated proposed-adapter proof. No
production Deployment, ServiceAccount, RBAC, schema or runtime authority changes.
`Current-State Ledger Impact: NO_CHANGE_REQUIRED`.
`Phase-Status Consistency Check: PASS` (`changed=150`, `status-impact=no`).

## 2. Environment and Kubernetes version

The first executed target was `gn-g2-proof-f01ead79ab92`, context
`k3d-gn-g2-proof-f01ead79ab92`, node
`k3d-gn-g2-proof-f01ead79ab92-server-0`, verified HTTPS endpoint
`https://127.0.0.1:32769`, server `v1.35.3+k3s1`. Its `kube-system` UID was
`01ee44f0-cf40-4462-af3e-b3b9339a5fd8`. These identifiers describe a disposable
fixture, not the canonical native k3s environment.

The final strengthened run used `gn-g2-proof-1bda6d563797`, context
`k3d-gn-g2-proof-1bda6d563797`, node
`k3d-gn-g2-proof-1bda6d563797-server-0`, endpoint
`https://127.0.0.1:32771`, and `kube-system` UID
`679ebf22-d3c4-4b52-a753-f13c5f091427`. Its Kubernetes version was also
`v1.35.3+k3s1`. Version compatibility proven here is limited to that version;
client-go version matching does not prove every upstream distribution or
admission configuration behaves identically.

## 3. Creation, isolation and reproduction

The [gated proof runner](../../../tests/gn-g2-real-api/verify) creates one
randomly named, explicitly owned single-server k3d cluster. It never uses an
ambient kubeconfig. API exposure binds only an ephemeral loopback port. The
runner pins the node image, disables bundled Traefik/ServiceLB/metrics/local
storage/DNS add-ons, disables default kubeconfig updates and context switching,
and verifies its context, container ownership nonce and `kube-system` UID before
execution. No existing cluster is an implicit target.

The test setup creates two run-scoped namespaces and inert test Deployments.
No telephony workload, customer identity, Service, external trunk, ingress or
production Go listener is involved. Administrative fixture credentials create
and clean up test resources; adapter execution receives separate constrained
short-lived ServiceAccount tokens held in memory. API-server proof does not
assert Pod readiness, media behavior, runtime draining or telephony completion.

After obtaining explicit authorization for this disposable proof, reproduce from
repository root with pre-existing pinned images and dependency cache:

```bash
tests/gn-g2-real-api/verify
```

The default command checks its own safety tests, prepares a fresh cluster, runs
the tagged real-server test and race/vet checks, and always attempts owned
cleanup. Its `prepare`, `execute RUN_DIRECTORY`, and `cleanup RUN_DIRECTORY`
modes support diagnosis without selecting another environment. Kubeconfig and
run metadata stay in a private `/tmp/utcp-gn-g2-real-*` directory; never copy
credentials or raw Kubernetes dumps into repository evidence.

## 4. Toolchain and immutable inputs

| Input | Pinned value |
| --- | --- |
| Go | `go1.25.8 linux/amd64` |
| SDK | `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084` |
| Kubernetes libraries | client-go, API and apimachinery `v0.35.3` |
| Node image | `rancher/k3s@sha256:4607083d3cac07e1ccde7317297271d13ed5f60f35a78f33fcef84858a9f1d69` |
| PHP regression environment | Existing pinned PHP 8.4 / PHPUnit image used by the GN-G2 verifier |

The real proof uses build tag `realapi`; ordinary offline verification does not
select or create a cluster. The SDK uses host networking solely to reach the
owned loopback API endpoint, read-only repository mounts, `GOTOOLCHAIN=local`
and `GOPROXY=off`. No host packages are installed.

## 5. Atomic parent Deployment JSON Patch

Real API execution accepted generation admission with absent, empty and unrelated
annotations. The adapter bootstraps the annotation map under UID/RV predicates.
It preserves unrelated fields. A failing later JSON Patch `test` rolled back
earlier operations in that same patch; partial metadata/replica application was
not observed.

`set_replicas` successfully tested the admitted generation/fingerprint and changed
absolute replicas plus applied markers in one parent Deployment operation. Tests read
the resource back to establish agreement between result and actual stored state.
No production action or raw arbitrary patch endpoint was added.

## 6. UID and resourceVersion predicates

Direct server requests with an incorrect UID, incorrect resourceVersion or
missing required marker were rejected. Read-only identity validation remains an
additional application guard; the parent object's tested RV protects the
observed ownership and metadata against intervening modification.

The first adapter projection reported HTTP 422, `reason=Invalid`, rather than
the simulator's explicit 409. Investigation found its `UnexpectedServerResponse`
cause was a synthetic client-go fallback, not authoritative server Status
details. After the narrow projection repair, real server predicate rejections
had no structured causes, while invalid replicas carried
`FieldValueInvalid` for `spec.replicas`. The final strengthened test compares
wire Status with the client projection for code, reason and cause type/field. The failed requests did not alter targets.
ResourceVersion is opaque equality state, not a lease, generation or ordering
number.

## 7. Generation barriers and delayed writes

The real runs demonstrated rejection of generation 10 after generation 11's
barrier, including a prepared old action using the current RV to isolate the
admitted-generation predicate. A same-generation conflicting fingerprint was
rejected by the engine. The post-repair server run also independently rejected
an incorrect fingerprint with correct UID, RV and generation predicates.

The opposite order remains possible: a previously prepared raw conditional
generation-10 request reached Kubernetes after modeled claim expiry but before
generation 11's barrier, temporarily set replicas to
2, and the current generation-11 effect subsequently converged to 4. PostgreSQL
expiry cannot retract an external request in flight. An expired claim with an
unchanged Kubernetes RV was rejected before dispatch; RV equality did not confer
authorization. No atomic PostgreSQL/Kubernetes transaction is claimed.
Claim expiry in this real API test is modeled verifier state; the proof does not
connect a live PostgreSQL operation lease to Kubernetes. The separate PHP suite
continues to exercise actual repository claim transitions on disposable SQLite.

## 8. Concurrency across adapter instances

Two independent adapters, authenticated as distinct constrained ServiceAccounts,
prepared mutations against the same RV. Both admission
and replica-effect races produced exactly one `success` and one rejected
`invalid_request`, with one resulting stored state. This proves the tested
API-server conditional behavior across instances; local locks do not provide
that cross-instance guarantee. A rejected CAS does not authorize automatic
RV refresh and replay. Laravel must reobserve and obtain a current full claim.

## 9. Replica idempotency and the scale boundary

Repeated identical intent was satisfied only with immutable identity, admitted
and applied generation/hash markers, matching replicas and current authorization.
Replica equality by itself remained insufficient. The adapter's RBAC and request
transport refused `/scale`.

A separate setup-identity `GetScale`/`UpdateScale` actor changed replicas without
updating the parent's intent markers. This models an HPA-like competing writer;
it does not run or prove an actual HPA controller. Recovery detected drift.
Kubernetes can enforce a
conditional operation; it cannot make unrelated authorized writers obey UTCP's
marker protocol. Exclusive writer ownership, including external scale tools and
autoscalers, remains a cutover prerequisite.

## 10. Immutable identity and name reuse

Wrong UID, tenant, RuntimeNode, incarnation, namespace and name were rejected.
The setup identity deleted and recreated the same fixture name. Both old
execution and GET-only recovery rejected the replacement UID despite matching
name/labels. This tests already-bound identity safety; it does not implement
Deployment create/delete lifecycle actions or canonical one-way UID binding.

## 11. Unknown-outcome recovery

A controlled forwarding proxy exercised real upstream requests and deliberately
lost responses. When Kubernetes had applied the mutation, the initial result was
`outcome_unknown / observe_first`; a later exact-identity observation established
`already_satisfied`. When the proxy did not forward the mutation, the same
initial uncertainty recovered as drift. Each case made one downstream mutation
attempt; recovery made zero mutations. Each uncertain branch then underwent
actual fixture replacement, and recovery returned `identity_mismatch` for the old UID. No blind
replay or PHP fallback occurred.

These tests prove the controlled transport cases, not that every possible
network failure exposes whether Kubernetes accepted a request. Unresolved state
must remain unknown until safely observed and canonically reauthorized.

## 12. RBAC, credentials and claim authority

Each of two adapter ServiceAccounts received only `get` and `patch` on named
Deployment `managed` in the proof namespace. Ten actual HTTP403 denials covered
create, delete, Secret read, another namespace GET/PATCH, Node mutation,
namespace list, Deployment list, another Deployment and `/scale` patch.
Administrative setup credentials were not supplied to the adapter.

The real-server proof still uses a **test-only claim verifier**. It demonstrates
deny/unavailable/expired/malformed/cancelled decisions preventing dispatch and
retains the independent PHP bridge regression, but does not install a production
Laravel callback or persist future target/generation bindings. Go has no
PostgreSQL/Redis client, tenant-policy authority or reconciliation loop. Existing
internal mTLS/redaction regressions remain required separately.

## 13. Actual error classification

| Observed server condition | Actual response | Adapter contract decision |
| --- | --- | --- |
| UID/RV/marker predicate fails | Post-repair server Status: 422 `Invalid`, no causes | `invalid_request`; no speculative retry classification |
| Invalid negative replicas | Post-repair server Status: 422 `Invalid`, `FieldValueInvalid` at `spec.replicas` | `invalid_request` |
| Malformed patch syntax | 400 `BadRequest` | `invalid_request` |
| Disallowed named resource under constrained account | 403 `Forbidden` | `forbidden` |
| Missing target under setup identity | 404 `NotFound` | `target_not_found` |
| Invalid bearer token | 401 `Unauthorized` | `unauthorized` |
| Response lost after attempted mutation | No definitive response | `outcome_unknown / observe_first` |

HTTP422 alone cannot distinguish a concurrent predicate refusal from invalid
content. No broad retryable-conflict mapping was introduced. Client errors and
logs expose safe normalized metadata, not bearer tokens or raw sensitive objects.

## 14. Root causes and implementation repairs

Real API investigation exposed one narrow error-projection defect: client-go
`Result.Raw()` returned its generic fallback error without decoding the real
structured Status body. GET/PATCH paths now call `Result.Error()` before reading successful raw bytes.
A permanent `TestClientPreservesStructuredKubernetesStatus` regression covers
both verbs; the real-server test independently compares actual Status fields.
Conservative HTTP422 classification remains unchanged. No broad retry permission
was introduced. This repairs the isolated Go adapter's error handling; deployed
PHP/runtime behavior and production manifests remain unchanged.
The proof also adds a build-tagged test and indirect module dependency
`github.com/spf13/pflag v1.0.9`, needed by the explicit kubeconfig test client. Kubernetes
library versions remain pinned at v0.35.3.

The independent historical `runtime-engine-config-check` failure remains:
`ordinary platform workers must not carry the Kubernetes API client label` for
`utcp-platform/api`, `utcp-platform/scheduler` and
`utcp-platform/telephony-reconciler`. It was reproduced unchanged. No checker,
accepted permission or production manifest repair is included.

## 15. Regression and verification results

| Command | Current evidence |
| --- | --- |
| `tests/gn-g2-real-api/verify` | PASS; 16 scenario groups, 33 passing entries including parent/subcases; real test 58.083s, race 57.019s, tagged vet; cleanup verified |
| `components/kubernetes-effects/verify` | PASS; effects 26 top-level / 169 passing entries, race/vet/module/format; PHP 111 tests / 597 assertions; observer 21 top-level / 107 entries and PHP 59 tests / 368 assertions |
| `make runtime-engine-config-check` | FAIL; unchanged pre-existing three-workload assertion |
| `make repository-hygiene` | PASS |
| `make phase-status-consistency-check` | PASS; 150 changed/untracked paths, no canonical status impact |
| `git diff --check` | PASS |

The pinned SDK ran these commands inside the effects module:

```bash
go test -tags realapi -run TestRealAPI -count=1 -v -timeout=180s ./...
go test -race -tags realapi -run TestRealAPI -count=1 -timeout=180s ./...
go vet -tags realapi ./...
```

The first command recorded 33 passing test entries: one parent, 16 scenario
groups and 16 nested subcases. These are test entries, not 33 independent
end-to-end test groups. The offline verifier ran
`go test -count=1 ./...`, `go test -race -count=1 ./...`, `go vet ./...`,
`go mod verify` and formatting checks in both Go modules, plus contract/parity,
mTLS and static/documentation checks. All passed. The effects PHP final parity
pass had no skip. The offline effects suite includes the new structured-Status
GET/PATCH regression; the real API test remains behind its explicit build tag.

Sanitized evidence is under `/tmp/utcp-gn-g2-real-seny00c_/evidence/`, with
`verification.log`, `real-api-evidence.json` and `cleanup.json`. Full offline
regression output is `/tmp/utcp-gn-g2-real-regression-proof-final.log`. These
local execution artifacts contain the exact recorded outcomes and are not
repository deliverables; this report summarizes assertions without copying logs.

## 16. Proven scope and remaining limitations

The completed proof establishes actual API conditional mutation behavior on the
recorded server. Unit tests, HTTPS simulation, PHP integration simulation,
real API-server execution, deployed UTCP and natural-live telephony remain
separate proof classes. No production deployment or telephony acceptance is
claimed. Admission plugins, version upgrades, production authorization, lifecycle
create/delete, canonical persistence, legacy request draining and writer transfer
remain independently gated.

## 17. Complete changed-file inventory

The SHA-256 comparison preserved all 143 baseline paths: 137 unchanged and
six intentionally modified. No tracked/staged changes were introduced. The final
working tree has 150 untracked files, including these seven new paths.

Intentional scoped changes to pre-existing untracked artifacts:

- `components/kubernetes-effects/README.md` — real-proof entry point and limits.
- `components/kubernetes-effects/go.mod` — indirect test-client dependency.
- `components/kubernetes-effects/go.sum` — dependency integrity metadata.
- `components/kubernetes-effects/kubernetes.go` — structured Status decoding.
- `components/kubernetes-effects/kubernetes_test.go` — GET/PATCH Status regression.
- `docs/architecture/proposals/gn-g2/real-api-verification.md` — dated supplement.

New paths:

- `components/kubernetes-effects/real_api_test.go` — build-tagged real API tests.
- `docs/architecture/proposals/UTCP_GN_G2_REAL_KUBERNETES_API_CONDITIONAL_EFFECT_VERIFICATION.md` — this evidence report.
- `docs/architecture/proposals/gn-g2/GN-G2-lifecycle-v2-implementation-assignment.md` — one complete next assignment.
- `tests/gn-g2-real-api/README.md` — reproduction and isolation instructions.
- `tests/gn-g2-real-api/test_verify.py` — proof-runner safety regressions.
- `tests/gn-g2-real-api/verify` — pinned owned-cluster lifecycle and test runner.
- `tests/gn-g2-real-api/verify-static` — scoped documentation/source checks.

No production PHP, deployed runtime, manifest, RBAC, accepted ADR or phase-ledger
file changed. The proposed adapter ADR remains **PROPOSED / NOT ADOPTED**.

## 18. Cleanup and preservation

Every completed run cleaned up both run-scoped namespaces by their captured
UIDs. The final run also observed zero Pods and ReplicaSets in both namespaces;
this is fixture isolation evidence, not a general template inertness proof.
The final `cleanup.json` confirms `deleted=true`, zero owned containers, absent
owned network/image volume, removed private kubeconfig and preserved pre-existing
clusters. Independent post-cleanup inspection found no owned proof cluster;
the six pre-existing containers and secondary `utcp-local` identity remained.
The runner validates its unpredictable ownership nonce and container identity before deletion, checks
that pre-existing clusters survive, and checks for remaining owned containers,
network, image volume and private kubeconfig. Do not claim cleanup from test
success alone.

## 19. Exact prerequisites before GN-G3

GN-G3 is not the next implementation assignment. First implement and independently
review the [bounded lifecycle v2 assignment](gn-g2/GN-G2-lifecycle-v2-implementation-assignment.md):
closed executable schemas, complete payload/fingerprint parity, preparing/bound/
retiring/retired persistence, one-way UID binding, never-reused incarnations,
suspended creation, conditional projection/delete, finalizers and unknown outcomes.
Prove suspended-template inertness and contain delayed creates; zero replicas
alone is insufficient.

Before any later writer cutover, adopt the appropriate ADR/contracts explicitly,
disposition the baseline checker, transfer every application corridor
(provision, converge, fence, restore and deprovision), prove legacy-request
terminal drain, revoke old effective write authority, exclude
competing platform/scale writers, and authorize environment-specific rollout and
conditional-compatible rollback. API proof does not itself authorize those steps.
