# UTCP GN-G1 — Read-only Go Kubernetes infrastructure observation parity

Assignment: `UTCP_GN_G1_READ_ONLY_GO_INFRASTRUCTURE_OBSERVATION_PARITY`.
Date: 2026-10-21. Scope: isolated implementation and developer verification.
Architecture status: **PROPOSED / NOT ADOPTED**.

## 1. Executive result and proof classification

The isolated [Go observer](../../../components/kubernetes-observer/) implements
the existing `utcp.infrastructure.v1` observation interface using direct
client-go. It acquires complete Node and Pod lists, projects only permitted
fields, and serves the normalized snapshot through an authenticated HTTPS/mTLS
handler. The component has no executable listener, production service binding,
database, Redis client, action endpoint, or Kubernetes write operation.

**Verdict: `GN_G1_IMPLEMENTED_WITH_BASELINE_DEPENDENCY`.** The component is
**IMPLEMENTED / DEVELOPER_TESTED** and **READY_FOR_INDEPENDENT_REVIEW**. The
independent checker is a **BASELINE_ACCEPTANCE_DEPENDENCY**. The accepted
scope remains undeployed; implementation/test outcomes below do not change the
[canonical phase ledger](../../roadmap/phase-status.md), its RMA-E next action,
or accepted V1/K5 classifications. The [proposed ADR](go-kubernetes-infrastructure-adapter-adr-proposed.md)
and normative [GN-G0 v1 contract](gn-g0/contracts/v1/README.md) are unchanged.

| Evidence class | Supported claim |
| --- | --- |
| Source-inspected | No production binding; read-only interface and GET/path guard; PHP retains identity, authorization and persistence |
| Go unit and fake-client tests | Normalization, deterministic ordering, bounds, freshness and failure classification |
| HTTP/TLS protocol simulation | Pagination, continuation recovery, strict request decoding, mTLS, cancellation and the assembled endpoint |
| PHP integration simulation | Existing Laravel services/routes and SQLite persistence consume actual exported Go snapshots |
| Real Kubernetes API-server integration | Not performed; simulated pagination does not prove API-server admission/defaulting semantics |
| Deployed or natural-live proof | Not performed; no cluster credentials or resources accessed |

The independent `runtime-engine-config-check` mismatch remains a baseline
acceptance dependency. A closed-v1 projection gap also remains explicit: v1
cannot carry Pod deletion timestamps. GN-G1 preserves the normative schema;
these observations must not authorize lifecycle completion or owned-Pod absence.

## 2. Repository identity and preservation

Starting branch: `main`. Starting HEAD:
`4ef700639c50a58933bc5a0d1e7ae08628cc4c62`.
The initial worktree had no tracked or staged changes and **50 untracked files**
from earlier E1/GN-G0 work. A disposable SHA-256 baseline recorded those files
before implementation. Final preservation and worktree checks are recorded in
section 15. No prior proposal, accepted ADR, phase ledger, production PHP file,
schema migration, deployed manifest or validation checker is edited by GN-G1.

## 3. Current PHP behavior and implementation boundary

The existing [KubernetesInfrastructureClient](../../../apps/api/app/Infrastructure/Kubernetes/KubernetesInfrastructureClient.php)
exposes `listNodes()` and `listPods()`. Its production
[HTTP implementation](../../../apps/api/app/Infrastructure/Kubernetes/HttpKubernetesInfrastructureClient.php)
performs one request for each collection; it checks kind/items and does not
follow continuation metadata. A permanent test demonstrates that limitation.

[KubernetesHostVisibilityService](../../../apps/api/app/Infrastructure/Kubernetes/KubernetesHostVisibilityService.php)
combines Node/Pod facts with canonical RuntimeNodes. `placementForRuntimeNode`
checks the canonical tenant row before infrastructure access. Correlation uses
the resolved namespace, `app.kubernetes.io/part-of=utcp`, and the Deployment
instance label. Co-resident RuntimeNode results remain tenant-scoped. A label
does not grant tenant access. This observation correlation does not verify the
complete Deployment/ReplicaSet/Pod UID chain.

[K5CPlacementObservationService](../../../apps/api/app/Infrastructure/Kubernetes/K5CPlacementObservationService.php)
calls that placement path for every RuntimeNode and persists the resulting
observation using Laravel. For N managed RuntimeNodes, the current path makes
2N collection HTTP requests. On infrastructure exceptions it records unavailable
observations and null topology facts. It does **not** implement the GN-G0 target
of retaining last-known topology visibly stale. No production behavior was
changed to conceal that difference.

The new [test-only PHP bridge](../../../tests/gn-g1-observer/SnapshotInfrastructureClient.php)
adapts one already-acquired Go snapshot to the existing read-only interface.
It is neither application-autoloaded nor registered. Repeated PHP correlation
uses the same caller-owned snapshot; Go adds no cache, scheduler, canonical
observation order, or competing reconciliation authority.

| Authority | Owner after this assignment |
| --- | --- |
| Tenant authentication/authorization and RuntimeNode ownership | Existing Laravel/PostgreSQL |
| Desired telephony policy, operation state, leases/fences and audit | Existing Laravel/PostgreSQL engine |
| Kubernetes identity and resource observations | Kubernetes; read-only Go normalization in isolated tests |
| Observation receipt, ordering, persistence and tenant projection | Laravel; future production integration remains planned |
| Live telephony execution/readiness | Existing runtime adapters and Asterisk/FreeSWITCH |
| Infrastructure mutation | Existing production authority; Go has no mutation capability |

## 4. Component structure and selected transport

| New module file | Responsibility |
| --- | --- |
| `types.go` | Closed-v1 DTOs and deterministic allowlisted normalization |
| `collector.go` | Complete acquisition, integrity/bounds, bounded 410 recovery and freshness helper |
| `kubernetes.go` | Fixed upstream HTTPS client-go REST reads, GET/path enforcement and atomic credential reload |
| `schema.go` | Compile unchanged normative request/response schemas; supported observer assembly |
| `transport.go` | Strict internal JSON RPC, mTLS helpers, request/deadline limits and four-slot backpressure |
| `collector_test.go`, `integration_test.go`, `transport_test.go` | Unit/fake/protocol/transport/contract and assembled-endpoint proof |
| `verify`, `verify-static` | Pinned offline Go/PHP verification and task-local documentation/isolation checks |

The interface is **HTTPS JSON RPC with mTLS**,
`POST /internal/infrastructure/v1/observations`. The `NewObserver` assembly
validates the unchanged request and response schemas. `NewHandler` is configured
with both validators, a fixed cluster ID, explicit namespace allow-set and exact
Laravel observer URI identity. The assembled endpoint is tested through mTLS to
the real collector and a controlled TLS Kubernetes HTTP server.

This is a reusable library and test transport, not an active service. Constructing
a handler does not deploy or bind it. Credentials and upstream URL are trusted
bootstrap inputs; callers cannot supply endpoints, selectors, tokens, manifests,
tenant claims or arbitrary Kubernetes requests.

## 5. v1 contract compatibility and field projection

The implementation compiles the original
[request schema](gn-g0/contracts/v1/observation-request.schema.json) and
[observation schema](gn-g0/contracts/v1/observation.schema.json), including format
assertions, and checks the normative observation fixture manifest. No permissive
schema copy or silent version downgrade is introduced. Requests reject unknown
fields, duplicate JSON keys, trailing JSON, invalid UTF-8, malformed identifiers,
unsupported versions and unsupported scopes before Kubernetes collection.

Nodes preserve UID/name/resourceVersion, Ready `True/False/Unknown`, scheduling
state, addresses, capacity, allocatable, safe conditions without messages, taints,
and nullable topology. Node labels are limited to hostname, OS, architecture,
region and zone. Existing K5C topology/policy inputs use region, zone and
hostname; these survive projection. Missing readiness remains `Unknown`; absent
topology remains null.

Pods preserve namespace/name/UID/resourceVersion, node placement or null, phase,
readiness, permitted workload labels and direct controller identity.
`ownership_chain=unverified` explicitly avoids claiming a verified Deployment
chain. Arbitrary annotations/labels, container environment/specification,
managedFields, condition/status messages, token/Secret payloads and raw objects
are absent. Strict upstream access permits only GET on the configured Node and
Pod collection paths; even an accidental PATCH or another resource path fails.

**Precise v1 gap:** the closed Pod schema has no deletion timestamp or
termination field. A `Running` Pod with `metadata.deletionTimestamp` therefore
cannot be distinguished from a non-deleting Running Pod in v1. A proposed
negotiated v2 should add required nullable `deletion_timestamp`, preserve its
Kubernetes value, and define terminating state independently of phase. This is
planned contract work, not adopted or implemented here. Existing PHP placement
also includes terminal Pods as placement facts; the parity fixture preserves
that behavior. Placement facts are not live telephony readiness evidence.

## 6. Complete acquisition, identity and recovery

Collection is direct client-go REST, with no watch, informer, controller-runtime,
operator, CRD or persistent cache. Node and Pod lists are acquired separately.
The first page omits resourceVersion; continuation requests retain the configured
limit and opaque server token. Each resource list must retain one nonempty
resourceVersion across all pages. RV is opaque and is never numerically ordered.

The collector follows an empty final continuation token, including an empty
intermediate page carrying a token. It rejects repeated tokens, changed RV,
missing UID/name/RV, duplicate identities, wrong namespace, malformed objects,
and exceeded page/object/byte limits. Nonempty continuation tokens are opaque:
the adapter checks repetition and bounds; Kubernetes determines token validity.
Missing/null `items` is malformed; valid empty arrays yield complete empty facts.

A 410/Gone/Expired discards **both** resource acquisitions and restarts once if
the shared deadline permits. No abandoned facts or RV survive. Exhaustion yields
incomplete null facts. Normalization occurs per page, so raw upstream objects are
not retained across the complete acquisition.

Default limits are 500 objects/page, at most 1,000 pages, 100,000 objects and
16 MiB normalized response. Raw upstream page bodies are bounded at 16 MiB.
The shared deadline is at most five seconds, clamped to the caller deadline;
client-go uses QPS=5/burst=10. The HTTP transport has a two-second connect/TLS
handshake bound and bounded request/header deadlines. Oversized snapshots fail;
they never truncate into healthy success.

Each list exposes its own start/finish, RV, page count and completeness. The
envelope has acquisition times. **Separate Node and Pod lists are not an atomic
cross-resource snapshot.** A Pod can reference a missing Node; this remains
unresolved placement, and absence in a complete list is not telephony termination.
Kubernetes' list/continuation semantics are described in the
[official API concepts](https://kubernetes.io/docs/reference/using-api/api-concepts/).

## 7. Freshness and deterministic error outcomes

`Fresh` requires a complete snapshot with valid acquisition/list intervals and
the oldest collection start no older than 300 seconds; future completion is
rejected. Using acquisition start is conservative across collection duration.
The PHP bridge independently rejects stale and incomplete data and never resets
collection time when the same snapshot is reused. Every duplicate observation
request performs a fresh bounded acquisition; no request-ID dedup cache invents
freshness. Caller-owned reuse is explicit.

Production Laravel receipt ordering, per-scope sequence allocation, retention of
last-known facts and clock-skew policy remain unimplemented integration work.
GN-G1 does not claim that a freshness predicate by itself prevents overlapping
snapshots from replacing each other in a future canonical store.

| Condition | v1 observation/error outcome | Existing PHP meaning through test bridge |
| --- | --- | --- |
| Valid empty collections | `complete=true`, empty arrays, no error | Observed absence, not an outage |
| API 401 / 403 | `unauthorized` / `forbidden`, null facts | Existing forbidden exception semantics |
| API 404 | `target_not_found`, incomplete | Existing `target_mismatch` exception; no invented empty cluster |
| API 429/503, exhausted 410 | `retryable_failure`, null facts | Unavailable; future caller owns bounded scheduling |
| DNS, TLS or connection failure | `transport_unavailable`, null facts | Unavailable |
| Timeout or cancellation | `timeout` / `cancelled`, null facts | Unavailable; no partial scheduling facts |
| Malformed JSON/kind/items/identity, changed RV or exceeded integrity bounds | `terminal_failure`, null facts | Malformed/failed observation |
| Unsupported or invalid request | Wire 400, or invalid request before acquisition | Rejected before Kubernetes access |
| Stale or unsupported response at PHP boundary | Bridge rejects it | No stale/invalid facts become current |

Domain outcomes use HTTP 200 with schema-valid envelopes. Wire failures use
400/413, TLS/role/scope rejection 401/403, saturation 429 with Retry-After,
and unavailable invalid output 503. No raw upstream error text is returned.
An incomplete snapshot always has `complete=false`, `nodes=null`, `pods=null`
and a normalized error; per-list diagnostic status cannot authorize decisions.

## 8. HTTPS/mTLS, rotation and isolation

Test certificates and keys are generated ephemerally in memory. TLS 1.3 verifies
the explicitly configured CA, server DNS identity/EKU and exact observer client
URI identity. Tests reject missing/untrusted/expired certificates, wrong server
identity and a certificate with the wrong client role. No private keys or live
credentials are stored in repository files.

Certificate/trust reload atomically swaps validated complete material. A failed
or mixed certificate/key reload preserves the last-good configuration. New TLS
handshakes use new material; existing/in-flight connections are not retroactively
revoked. Client reload closes idle connections. Kubernetes token/CA rotation is
also explicit/atomic and rejects endpoint or scope changes. There is no automatic
file watcher: a future deployment owner must connect projected-file updates to
these reload APIs and define revocation/restart policy.

The handler holds at most four acquisition slots through response serialization,
with no waiting queue. Saturation rejects before collection. Tests exercise slow
requests, disconnects, cancellation, repeated requests, deadline bounds and
backpressure. The upstream client rejects redirects and enforces GET/fixed paths.
The component opens no DB/Redis connection and contains no Kubernetes mutation
client or action endpoint. Verification containers use `--network none`; loopback
HTTP/TLS simulation remains available. No production RBAC or networking changes
are introduced.

## 9. PHP/Go parity proof

The [PHP suite](../../../tests/gn-g1-observer/README.md) consumes the **actual
Go-exported snapshots**, rather than merely checking stored response fixtures.
It compares the current PHP HTTP client/service path against the bridge through
the existing Host visibility and K5C placement/persistence services.

Coverage includes healthy infrastructure, missing topology, multiple Nodes and
Pods, absent Pod, terminal Pod, wrong workload identity, absent Node, empty valid
collections and multiple pages; incomplete/unavailable, unsupported response and
stale rejection; tenant routes/co-resident filtering; and a spoofed tenant label.
The only expected comparison projection is the v1 removal of arbitrary Node
labels. Existing retained facts and placement outcomes remain asserted.

SQLite and Laravel `Http::fake` are test infrastructure. Existing PHP code alone
writes observations; neither Go nor the bridge changes desired RuntimeNode
state. The bridge has no independent tenant-authority input. The full existing
Host visibility and RuntimeFencing manifest suites run alongside the new cases.
A data-driven regression checks every mapped GN-G0 error code against the
normative PHP exception reason, including `target_not_found` to `target_mismatch`,
`terminal_failure` to `failed`, and unknown-code fail-closed behavior.
This is PHP integration simulation, not production routing or PostgreSQL proof.

## 10. Controlled efficiency and resource measurements

The fixture workloads have three Kubernetes Nodes and 1/10/100 managed Pods.
Five-item pages deliberately exercise pagination. One caller-owned shared Go
snapshot requires one Node acquisition and one Pod acquisition, independent of
the number of subsequent PHP RuntimeNode correlations.

| Managed RuntimeNodes | Current measured PHP list HTTP requests | Shared Go acquisition requests | Continuation requests | Additional Kubernetes requests during shared PHP correlation | Repeated paginated model, not measured legacy behavior |
| --- | --- | --- | --- | --- | --- |
| 1 | 2 | 2 | 0 | 0 | 2 |
| 10 | 20 | 3 | 1 | 0 | 30 |
| 100 | 200 | 21 | 19 | 0 | 2,100 |

The following single-run measurements were captured after compilation of the
trusted schemas and before race instrumentation. Acquisition still includes
normalization and response-schema validation. Process max RSS is 140,904 KiB
for this Go test process and includes earlier tests, fixtures and schema setup;
it is not an isolated service RSS measurement.

| RuntimeNodes | Go acquisition wall ms | Go process CPU ms | Go allocated bytes | Go live heap bytes | Current PHP refresh wall / CPU ms | Shared PHP correlation wall / CPU ms |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 4.513 | 5.057 | 479,280 | 3,422,864 | 1.912 / 1.919 | 1.339 / 1.343 |
| 10 | 5.159 | 5.776 | 1,032,216 | 4,011,776 | 11.746 / 11.744 | 7.749 / 7.754 |
| 100 | 2208.482 | 44.479 | 5,288,448 | 5,411,768 | 246.280 / 246.260 | 107.848 / 107.846 |

PHP peak process memory was 57,147,392 bytes for 1/10 RuntimeNodes and
65,536,000 bytes for 100. Measured PHP memory growth was zero for 1/10 and
8,388,608 bytes for the current 100-RuntimeNode refresh; shared correlation's
incremental growth was zero in that already-warmed process. These figures are
allocator/process observations, not independent cold-service comparisons.

The runner emits
`go-measurements.json` and `php-measurements.json` into the disposable output
directory. Go records acquisition wall/process-CPU time, cumulative allocation,
live heap and process max RSS; PHP records each current refresh and shared
correlation/persistence wall/process-CPU time and memory. These single-process
figures include runtime/test overhead and are not production sizing results.
PHP measurements include SQLite correlation/persistence; Go acquisition is
separate. No claim of Go-vs-PHP real-cluster throughput follows from HTTP fakes.

An earlier two-item-page run required 52 list requests for the 100-Pod case and
exhausted the five-second rate budget after 32 Pod pages. That limit motivated
five-item measurement pages; the deadline and QPS were retained. Default
500-item production-style pages would need two requests for these small fixture
collections, but their real-server cost remains unmeasured. The demonstrated
request reduction comes from snapshot sharing and complete pagination, not from
the implementation language alone. Production gains require a future authorized
caller integration that acquires once per refresh.

## 11. Reproduction and pinned dependencies

```bash
components/kubernetes-observer/verify
```

For a missing dependency cache, the explicit `--download` option obtains only
the pinned Go modules before the offline verification pass. No host packages are
installed. The SDK is Go **1.25.8**, image `golang:1.25.8-bookworm`, executed by
immutable digest
`sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`.
`k8s.io/client-go`, `k8s.io/api` and `k8s.io/apimachinery` are **v0.35.3**;
JSON Schema validation uses `github.com/santhosh-tekuri/jsonschema/v5` **v5.3.1**.
This library line models Kubernetes 1.35 types; compatibility with UTCP's actual
server version remains a future real API-server gate, not an inferred guarantee.

The PHP runner uses the existing test image by immutable ID
`sha256:9efe210fb8c8e7c90d80fce2b6916b46824f0f0428d6037dd599ec5ef1dd4d7d`,
PHP 8.4.15 and PHPUnit 11.5.56. Source mounts are read-only and output is disposable.
Neither runner loads kubeconfig, mounted live tokens, real database credentials
or production service discovery.

## 12. Baseline static-check defect and disposition

`make runtime-engine-config-check` still fails at the assertion in
[scripts/runtime-engine/config-check](../../../scripts/runtime-engine/config-check)
that ordinary platform workers must not carry the Kubernetes API-client label.
Its rejected set is exactly `utcp-platform/api`, `utcp-platform/scheduler`, and
`utcp-platform/telephony-reconciler`. The assertion at lines 415–424 conflicts
with the current accepted K5 manifest expectations in
[RuntimeFencingManifestTest](../../../apps/api/tests/Feature/Infrastructure/RuntimeFencingManifestTest.php),
including the dedicated API, observer and maintenance ServiceAccounts.

This is a **pre-existing baseline defect**, not a GN-G1 regression. The checker
is unchanged. The smallest separate repair is to assert the exact current
workload/label/ServiceAccount permission matrix, retain denial for unrelated
workers, and add positive and negative checker regressions. Removing the check
or permitting every worker is not an acceptable repair. Separate authorization
or explicit operator disposition is required before full acceptance; this does
not invalidate the isolated observer's implementation tests.

## 13. Acceptance and remaining proof gaps

| Gate | GN-G1 disposition |
| --- | --- |
| Source compilation, unit/race/vet, module integrity | PASS |
| Normative schemas/fixtures, pagination/recovery, incomplete-null facts | PASS; exact commands below |
| Strict projection, mTLS, cancellation/backpressure | PASS; exact commands below |
| Actual Go-to-PHP observation parity and existing focused PHP regressions | PASS; exact commands below |
| Request/resource measurement | Implemented; final measured output below |
| No mutation, production wiring, DB/Redis authority | Source-inspected and isolated verification |
| Repository/documentation/phase consistency | PASS; exact commands below |
| Baseline runtime-engine static check | Fails independently; separate acceptance dependency |
| Real API-server integration / deployed / natural-live proof | Not performed or claimed |

Additional gaps are deliberate: no production listener/service lifecycle, file
watcher, deployment certificate issuance/revocation policy, production PHP client
binding, canonical snapshot sequence/retention store, Deployment UID-chain
verification, Running-but-deleting projection or Kubernetes mutation. Existing
V1/K5 natural-live evidence remains historical authority for the deployed PHP
system and is not reused as Go live acceptance.

## 14. Bounded follow-up and material operator decisions

The ready-to-copy [GN-G2 assignment](gn-g1/GN-G2-implementation-assignment.md)
implements only conditional effects against an already-bound Deployment and
requires a separately authorized isolated real API-server environment. It does
not authorize writer cutover. Creation/deletion/name-reuse extensions must be
versioned before GN-G3 can transfer every application-level writer.

Material operator decisions are: adoption of the proposed ADR before any
production extraction; ownership of future PKI/ServiceAccounts/service lifecycle;
authorization of isolated API-server validation; separate repair/disposition of
the baseline checker; and explicit approval of a negotiated lifecycle projection
version before consumers depend on Pod deletion state. GN-G1 grants none of
those deployment or architectural adoptions by itself.

## 15. Commands, final evidence and changed-file boundaries

| Command | Result and classification |
| --- | --- |
| `components/kubernetes-observer/verify` | Complete entry point PASS, exit 0; `go test -count=1 -v ./...` PASS (4.638s); `go test -race -count=1 ./...` PASS (5.894s); `go vet ./...` PASS; `go mod verify` PASS; gofmt PASS |
| `tests/gn-g1-observer/verify-php` | PASS: 59 tests, 368 assertions; 4.356s, 62.50 MiB PHPUnit process memory; existing Host/manifest suites included |
| `components/kubernetes-observer/verify-static` | PASS: 67 task-owned files; UTF-8, whitespace, JSON, local links and isolation guards |
| `make repository-hygiene phase-status-consistency-check control-plane-config-check workflow-check` | PASS: repository hygiene, phase-status consistency, control-plane/static PHP checks and workflow checks |
| `make runtime-engine-config-check` | FAIL, make exit 2: independent known label/ServiceAccount assertion mismatch described above |
| `git diff --check` and final preservation/status inspection | PASS: no whitespace defects; all 50 prior files retain baseline SHA-256; no tracked/staged changes; 117 untracked files (50 preserved + 67 new) |

An independent architecture-boundary source review found no unresolved introduced
authority defect. This is source review, not runtime acceptance.

Transient verification-harness failures were corrected: an initial schema mount
omission, formatting failure, a negative TLS fixture that reused httptest's
shared default certificate, PHP measurement output permissions, and the static
helper matching its own private-key detection literal. These were introduced
proof/harness defects; the corrected tests run rather than skipping assertions.
The five-second 52-request rate-budget exhaustion remains a documented controlled
limit. No environmental failure is interpreted as evidence against Go's
architectural suitability.

The permanent task-owned file inventory is the new
`components/kubernetes-observer/` module, `tests/gn-g1-observer/` bridge/fixtures,
this report and the GN-G2 prompt. Disposable generated snapshots, certificates,
measurements, cache and run output remain under `/tmp`; raw logs are not copied
into documentation. The prior 50 E1/GN-G0 files are preserved, untracked and
unstaged. No stage, commit or push is performed.

`Current-State Ledger Impact: NO_CHANGE_REQUIRED` — isolated developer proof
does not adopt a canonical roadmap phase, change accepted V1/K5 status, close a
deployed gap, or replace the RMA-E next action. `Phase-Status Consistency Check: PASS`. ADR status remains **PROPOSED / NOT ADOPTED**.

Exact new-file inventory (67 files; all task-owned and untracked):

```text
components/kubernetes-observer/README.md
components/kubernetes-observer/collector.go
components/kubernetes-observer/collector_test.go
components/kubernetes-observer/go.mod
components/kubernetes-observer/go.sum
components/kubernetes-observer/integration_test.go
components/kubernetes-observer/kubernetes.go
components/kubernetes-observer/schema.go
components/kubernetes-observer/transport.go
components/kubernetes-observer/transport_test.go
components/kubernetes-observer/types.go
components/kubernetes-observer/verify
components/kubernetes-observer/verify-static
docs/architecture/proposals/UTCP_GN_G1_READ_ONLY_GO_INFRASTRUCTURE_OBSERVATION_PARITY.md
docs/architecture/proposals/gn-g1/GN-G2-implementation-assignment.md
tests/gn-g1-observer/ObservationParityTest.php
tests/gn-g1-observer/README.md
tests/gn-g1-observer/SnapshotInfrastructureClient.php
tests/gn-g1-observer/fixtures/empty/nodes.json
tests/gn-g1-observer/fixtures/empty/pods.json
tests/gn-g1-observer/fixtures/empty/scenario.json
tests/gn-g1-observer/fixtures/healthy/nodes.json
tests/gn-g1-observer/fixtures/healthy/pods.json
tests/gn-g1-observer/fixtures/healthy/scenario.json
tests/gn-g1-observer/fixtures/incomplete/nodes.json
tests/gn-g1-observer/fixtures/incomplete/pods.json
tests/gn-g1-observer/fixtures/incomplete/scenario.json
tests/gn-g1-observer/fixtures/invalid/nodes.json
tests/gn-g1-observer/fixtures/invalid/pods.json
tests/gn-g1-observer/fixtures/invalid/scenario.json
tests/gn-g1-observer/fixtures/missing-node/nodes.json
tests/gn-g1-observer/fixtures/missing-node/pods.json
tests/gn-g1-observer/fixtures/missing-node/scenario.json
tests/gn-g1-observer/fixtures/missing-pod/nodes.json
tests/gn-g1-observer/fixtures/missing-pod/pods.json
tests/gn-g1-observer/fixtures/missing-pod/scenario.json
tests/gn-g1-observer/fixtures/missing-topology/nodes.json
tests/gn-g1-observer/fixtures/missing-topology/pods.json
tests/gn-g1-observer/fixtures/missing-topology/scenario.json
tests/gn-g1-observer/fixtures/multipage/nodes.json
tests/gn-g1-observer/fixtures/multipage/pods.json
tests/gn-g1-observer/fixtures/multipage/scenario.json
tests/gn-g1-observer/fixtures/multiple/nodes.json
tests/gn-g1-observer/fixtures/multiple/pods.json
tests/gn-g1-observer/fixtures/multiple/scenario.json
tests/gn-g1-observer/fixtures/ownership-mismatch/nodes.json
tests/gn-g1-observer/fixtures/ownership-mismatch/pods.json
tests/gn-g1-observer/fixtures/ownership-mismatch/scenario.json
tests/gn-g1-observer/fixtures/performance-1/nodes.json
tests/gn-g1-observer/fixtures/performance-1/pods.json
tests/gn-g1-observer/fixtures/performance-1/scenario.json
tests/gn-g1-observer/fixtures/performance-10/nodes.json
tests/gn-g1-observer/fixtures/performance-10/pods.json
tests/gn-g1-observer/fixtures/performance-10/scenario.json
tests/gn-g1-observer/fixtures/performance-100/nodes.json
tests/gn-g1-observer/fixtures/performance-100/pods.json
tests/gn-g1-observer/fixtures/performance-100/scenario.json
tests/gn-g1-observer/fixtures/stale/nodes.json
tests/gn-g1-observer/fixtures/stale/pods.json
tests/gn-g1-observer/fixtures/stale/scenario.json
tests/gn-g1-observer/fixtures/terminated/nodes.json
tests/gn-g1-observer/fixtures/terminated/pods.json
tests/gn-g1-observer/fixtures/terminated/scenario.json
tests/gn-g1-observer/fixtures/unavailable/nodes.json
tests/gn-g1-observer/fixtures/unavailable/pods.json
tests/gn-g1-observer/fixtures/unavailable/scenario.json
tests/gn-g1-observer/verify-php
```
