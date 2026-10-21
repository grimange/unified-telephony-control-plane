# GN-G2 Active Runtime Projection Profiles and Five-Corridor Parity

Assignment: `UTCP_GN_G2_ACTIVE_RUNTIME_PROJECTION_PROFILES_AND_FIVE_CORRIDOR_PARITY`.
Date: 2026-10-21. Worker: OpenAI Codex CLI; requested GPT-6 / High; role:
Principal Kubernetes Control Plane Engineer / Telecom Infrastructure Architect /
Distributed Systems Engineer. This packet is isolated implementation and proof;
the adapter ADR remains **PROPOSED / NOT ADOPTED**.

## Verdict and proof boundary

**GN_G2_ACTIVE_RUNTIME_PROFILES_IMPLEMENTED_WITH_LIMITATIONS**.
Both provider projections and all material isolated real API tests pass. The
remaining parity limitation is explicit: existing PHP domain fencing/restoration
is Asterisk-specific; equivalent FreeSWITCH infrastructure effects do not establish
that missing domain support. Production authority integration is deferred, not
claimed by these tests.
Both fixed provider profiles are implemented through a new closed v3 contract.
The actual PHP provider Pod specifications and independently computed intent
fingerprints are compared in executable tests. All five Deployment corridors
have a source mapping and test-only authority model. Existing PHP fencing and
restoration are Asterisk-specific: FreeSWITCH infrastructure support is an
extension, not a claim of existing production domain parity.

No production PHP, database, Kubernetes manifest, RBAC, routing, deployed service,
telephony behavior or writer authority is changed. Actual provider projections
are tested at zero replicas; only harmless pause Pods execute. This proves
resource semantics, not provider startup, readiness, SIP, calls or media.

## Repository baseline and preservation

Starting branch: `main`. Starting HEAD:
`4ef700639c50a58933bc5a0d1e7ae08628cc4c62`.
There were 177 untracked files and no tracked/staged modifications. All initial
untracked paths were hashed before implementation; final comparison preserved
all 177 byte-for-byte. Final branch/HEAD are unchanged, with 205 untracked files
(28 new task files), no tracked modifications and no staged files. No reset, clean, stage, commit or push is performed.
The baseline hash inventory stays temporary at
`/tmp/utcp-active-projection-baseline.json`; it contains paths and hashes only.

Repository authority reviewed: root and applicable API/docs instructions,
[phase ledger](../../roadmap/phase-status.md), accepted authority/ADR documents,
[v1 contract](gn-g0/contracts/v1/README.md),
[v2 contract](gn-g2/contracts/v2/README.md), previous GN-G1/GN-G2/lifecycle reports,
and the [proposed adapter ADR](go-kubernetes-infrastructure-adapter-adr-proposed.md).
Accepted V1/K5 classifications and the RMA-E next action remain unchanged.
This isolated proposal packet does not change canonical phase authority.

## Actual PHP source inventory and parity

The complete field-by-field inventory, seven call sites across five corridors,
source links and classifications are in the
[source parity matrix](gn-g2/active-source-parity.md).

Both production builders use one provider container, digest-qualified image,
`Always` pull policy, Secret `envFrom`, 50m CPU/128Mi requests, 500m/384Mi limits,
no ServiceAccount token automount, 30-second termination grace, nonroot execution,
RuntimeDefault seccomp, no privilege escalation and dropped capabilities.

Asterisk retains ARI 8088/TCP and SIP 5060/UDP; its optional fixed ConfigMap and
read-only mount, readiness/startup script, CLI liveness probe, writable root and
image-selected numeric user match PHP. FreeSWITCH retains SIP 5060/UDP, ESL
8021/TCP, representative RTP 21000/UDP, three emptyDirs, UID/GID/fsGroup 1000,
read-only root and the three fixed health probes. No command/argument, sidecar,
init-container, host access, placement, lifecycle hook or arbitrary volume input
is exposed.

Full typed PodSpec comparison against output from the actual Laravel builders
covers fields beyond hand-picked assertions. Deliberate transformations are
never-reused incarnation names, immutable incarnation selectors, additional
ownership labels, staged inert creation/binding/activation, explicit Kubernetes
default RollingUpdate strategy and conditional generation markers. Parent fields
outside the controlled set remain intact. Go owns the complete Pod template;
unsupported admission changes or sidecars remain explicit drift.

Legacy Services and Secrets remain correlated to RuntimeNode identity; they must
not be renamed from the new physical Deployment name. Their publication,
cleanup, endpoint/capability records and canonical lifecycle completion remain
Laravel responsibilities.

## Implementation and versioned contract

New package: `components/kubernetes-effects/active`, inside the unchanged pinned
GN-G2 module. There is no executable main, listener, production binding, database
or Redis client, independent watch/controller, or deployment wiring.

| Component | Responsibility |
| --- | --- |
| `types.go`, `schema.go`, `contracts/` | Closed v3 envelope/result, strict JSON and embedded normative schemas |
| `projection.go` | Fixed `asterisk.managed.v1` and `freeswitch.managed.v1` templates and independent deterministic fingerprints |
| `engine.go` | Trust catalogs, claims, identity, conditional mutation, bounded work and read-only recovery |
| `claims.go` | Full-envelope authenticated callback; no positive authorization cache |
| `transport.go` | Testable mTLS handler, endpoint/method/identity/body/deadline/backpressure controls; no listener |
| Unit, parity and real API tests | Contract, security, actual PHP builder comparison and Kubernetes controller/API proof |

The normative [v3 contract](gn-g2/contracts/v3/README.md) uses
`utcp.infrastructure.v3` at `/internal/infrastructure/v3/actions` and supports
only `converge_projection`. The callback is
`/internal/infrastructure/v3/claims/verify`. v1/v2 schemas, semantics, source and
inert creation remain byte-identical to baseline.

The request requires canonical `bound` assertion, exact tenant/RuntimeNode,
cluster/namespace/name/UID/incarnation, expected resourceVersion, decimal-string
generation, complete operation claim and deadline. The projection contains only
profile version, approved digest image, configuration ID/SHA256 and approved
namespace-scoped Secret name. Absolute replicas are bounded 0–100.

Unknown/duplicate fields, unsupported profiles/actions/versions, null/missing
required values, numeric or overflowing generations, invalid Unicode, unqualified
or mutable images and unsafe template inputs are rejected. The manifest contains
2 valid and 11 invalid actions plus 5 valid and 7 invalid results. Embedded and
normative schema/fixture copies must compare byte-for-byte.

SHA-256 fingerprints use ordered UTF-8 byte-length-prefixed scalar values;
independent PHP and Go implementations test both profiles at generation 10 and
maximum signed PostgreSQL bigint. Optional/null behavior is explicit; no JSON
map order or floating-point generation conversion participates.

## Image identity and configuration trust

Actual local Docker RepoDigests were inspected rather than invented:

- Asterisk: `127.0.0.1:5001/utcp/asterisk-ari@sha256:6027f3bc1464e4c7ed3485bb5a078a529e93ce3f04a2b3d3b0a03d5ac00598e9`.
- FreeSWITCH: `127.0.0.1:5001/utcp/freeswitch@sha256:3eedabc8afb2e3d1a4fc1e4ef8a6439bec2c7a30df4588566989dd9346ba3a11`.

The verifier passes these exact references to both PHP builders and Go catalogs.
No pause image is substituted for a provider profile. No mutable tag is accepted
or resolved by Go; future canonical publication must provide a verified digest.
Catalogs are copied on engine construction so caller map mutation cannot change
approved image/Secret inputs afterward. These checks establish configured image
identity, not registry attestation or provider execution proof.

`configuration_sha256` binds declared intent; it does not verify the payload of a
mutable Secret or shared optional ConfigMap. Go never reads Secret bytes.
Versioned/immutable configuration publication and canonical catalog ownership
remain production integration prerequisites.

## Activation safety and retirement invariant

An incarnation may receive a nonzero active runtime projection only when its UID
is canonically bound, lifecycle permits activation, the complete current claim
matches approved provider/configuration and global target generation, expected
Kubernetes UID/resourceVersion match, and no retirement hold exists. Kubernetes
controllers may then create Pods from that approved template. API acceptance is
neither Pod readiness nor completed telephony activation.

`paused: true` controls rollout, not execution. The real harmless fixture proves
zero replicas after inert creation yield no owned ReplicaSets/Pods once the
controller observes the Deployment; an existing ReplicaSet can still scale to
two running Pods while its Deployment remains paused. The fixture is then scaled
to zero and observed until no Pods remain. This matches the upstream
[Deployment scaling/pausing semantics](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/).

The v3 patch atomically installs template, rollout state (`paused: false`),
absolute replicas and generation/projection markers. Any nonempty terminal
retirement marker rejects activation even with a larger requested generation.
Canonical retirement alone cannot revoke an already-dispatched request: a newer
Kubernetes barrier is required to reject older prepared writes. Retirement and
fencing completion also require owned-Pod drain evidence.

The production claim authority must govern **all versions** in one generation
sequence. v1 scaling or v2 nonzero inert convergence must not bypass approved
profile, fence or retirement policy against an existing active ReplicaSet. The
test authority exercises these cross-version guards; the isolated adapter does
not claim that production callback or exclusive writer transfer already exists.

## Five-corridor semantic parity

| Corridor | Existing PHP effect | Proposed isolated Go sequence and evidence |
| --- | --- | --- |
| Managed provisioning | Provider builder + apply Deployment; publish Secret/Service and canonical records | Prepare → v2 inert creation → observe UID → canonical bind → v3 provider projection; actual builder parity and test lifecycle guards |
| Managed convergence | Reuse provider builder and apply managed workload | Current global generation/full claim → v3 conditional projection; whole PodSpec equality, field preservation, drift and idempotency tests |
| Runtime fencing | Asterisk-owned workload scale to zero, then verify no owned Pods | Current v1 admission + absolute zero replicas; existing effects/real suite plus test authority; patch success is not completed fencing |
| Runtime restoration | Domain checks then Asterisk-owned scale to target | Fresh claim/current incarnation/approved profile/current generation → authorized projection/replicas; old fence/restore intents superseded |
| Managed deprovisioning | Delete managed Deployment, Service and Secret | Canonical retiring barrier → v2 exact UID/RV delete → finalizer/absence observation → terminal retirement; ancillary cleanup stays PHP |

Current FreeSWITCH PHP fencing/restoration domain support is **unsupported**;
Go infrastructure fixtures cover the corresponding effects without widening
production telephony policy. No actual PHP writer is replaced. Error compatibility
fixtures retain normalized unknown outcomes and `observe_first` receipts; they
do not turn an unavailable response into a definitive failed mutation.

## Conditional writes, concurrency and recovery

A single parent Deployment JSON Patch tests UID, expected resourceVersion and
installed generation/fingerprint predicates while applying all controlled fields
and markers. There is no `/scale` fallback, split marker/template update,
Server-Side Apply claim or automatic RV refresh/replay. Unrelated parent metadata
and spec fields are preserved; only explicitly owned fields change.

A current claim is checked before the read and immediately before dispatch,
without positive caching. Lease expiry before dispatch rejects work. Expiry after
dispatch does not retract the request. Older writes arriving after a newer
Kubernetes barrier fail conditions; writes arriving before that barrier may apply
temporarily and require separately authorized convergence. There is no atomic
PostgreSQL/Kubernetes transaction or unconditional compensation.

Two adapter instances use the same Kubernetes-enforced CAS boundary. Per-engine
admission limits work to four requests and one mutation per target; those local
limits are not distributed locks. Five-second contexts, request/claim deadlines,
request size limits and cancellation bound work. A cancelled/timed-out dispatched
write is uncertain, not definitively unexecuted.

Lost applied and lost unapplied responses return `outcome_unknown`, `effect` and
`dispatch` unknown, `retry=observe_first`. Recovery is GET-only: exact UID,
incarnation, current claim, markers, complete normalized controlled projection
and replicas. Applied matching intent becomes already satisfied; unapplied
intent is drift. Superseded intent and replacement UID are rejected. No mutation,
new UID binding, blind replay or PHP fallback occurs during recovery.

Kubernetes defaults are normalized only for documented default fields. A failed
JSON Patch test can be HTTP 422/Invalid; v3 conservatively maps it to
`invalid_request` and never parses human Status text or treats all 422s as
retryable conflicts. Pre-read stale RV maps explicitly to resource-version
conflict; lost transport remains unknown.

## Security and verification environment

The mTLS handler reuses strict GN-G1 trust with exact executor URI identity and
server verification. Invalid/missing/untrusted/expired identities, rotation
failure, malformed callbacks, unsupported body/operation, oversize requests,
unsafe fields, unapproved images/Secret slots, cancellation and overload have
regressions. Full operation payloads, kubeconfigs, bearer tokens, private keys and
Secret material are excluded from permanent evidence.

One new owned k3d cluster was authorized and prepared:
`gn-g2-proof-a395b9f6a516`, context `k3d-gn-g2-proof-a395b9f6a516`, API endpoint
`https://127.0.0.1:32773`, node `k3d-gn-g2-proof-a395b9f6a516-server-0`, Kubernetes
`v1.35.3+k3s1`. Private explicit kubeconfig, container identity, kube-system UID,
nonce and loopback checks protect every operation. No default or canonical
context is selected. No pre-existing cluster is modified or deleted.

The active adapter's dedicated ServiceAccounts have named Deployment GET/PATCH
only. Real denials cover Secret reads, Node patches, cross-namespace reads,
unrelated Deployment patches, CREATE, DELETE and `/scale`. Fixture administration
uses a separate credential. Inherited lifecycle RBAC separately covers namespace
CREATE; Kubernetes resourceNames cannot constrain CREATE, so future production
namespace/admission/template boundaries remain necessary.

Pinned toolchain:

- Go 1.25.8: `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`.
- `client-go`, `api`, `apimachinery`: v0.35.3; no dependency or lockfile changes.
- k3s: `rancher/k3s@sha256:4607083d3cac07e1ccde7317297271d13ed5f60f35a78f33fcef84858a9f1d69`.
- PHP 8.4.15 / PHPUnit 11.5.56, existing image `sha256:9efe210fb8c8e7c90d80fce2b6916b46824f0f0428d6037dd599ec5ef1dd4d7d`.
- Docker 29.7.2 / k3d 5.9.0. No host packages installed.
- Running fixture only: `registry.k8s.io/pause@sha256:ee6521f290b2168b6e0935a181d4cff9be1ac3f505666ef0e3c98fae8199917a`.

## Commands and evidence

The [one-command verifier](../../../tests/gn-g2-active-projection/README.md) is:

```bash
tests/gn-g2-active-projection/verify
```

This run used `verify offline`, inherited `prepare`, and `verify execute` on the
same single owned run directory so diagnosis never created another cluster.
The real wrapper runs package suites serially (`-p 1`) against one API server.
Within the pinned module it executes:

```bash
go test -count=1 -v ./...
go test -race -count=1 ./...
go vet ./...
go mod verify
```

Focused active tests additionally mount generated PHP exchange data; the real
suite uses `-tags realapi -run TestRealAPI`, serial packages, race detection and
vet. Temporary PHP exchange includes synthetic lease tokens and stays outside
repository evidence. Laravel operation claims use real repository methods on
isolated SQLite test rows; the canonical incarnation model remains test-only
in memory. Go then consumes those exported decisions over ephemeral mTLS.
This is not a production Laravel HTTP callback or PostgreSQL integration proof.

Initial verification findings and bounded corrections:

1. The first offline run reached passing legacy suites but failed documentation
   link validation while this report was still being assembled. Completing the
   report repaired the missing link; no check was disabled.
2. Strict qualified-image parity exposed a negative unit fixture that used an
   unqualified image while expecting a catalog-forbidden result. It now uses a
   structurally valid unapproved registry/repository digest; malformed images
   remain separately rejected. A dedicated recomputed-fingerprint regression
   proves Go and PHP reject unqualified images consistently.
3. Concurrent Go real-test packages saturated the single-node fixture enough
   that two gated tests reached the five-second adapter deadline. Both returned
   conservative unknown outcomes, not false success. The wrapper now runs real
   packages serially; the same unchanged CAS/retirement assertions passed on the
   same cluster. No deadline, identity guard or safety assertion was weakened.
4. Docker multi-platform image export omitted a child blob during fixture image
   import. The owned-node helper now pulls the exact harmless pause digest into
   containerd after verifying run identity, with a bounded timeout. Three wrapper
   regressions prove identity failure prevents execution and the exact owned
   node/image are selected. No provider image was substituted.

5. A repeated offline parity run found a Go exchange file created by an earlier
   disposable UID. Opening it in place failed with permission denied. The test
   export now writes a temporary sibling and atomically renames it, preserving
   strict checks while allowing repeatable ownership changes. The full offline
   verifier was rerun. The added eleventh negative fixture also required updating
   the PHP fixture-count assertion from ten to eleven; no negative case was removed.

Sanitized [real API evidence](gn-g2/active-real-api-evidence.json) records all three
real suites, 108 observation/status records and owned-cluster cleanup. There were
3 top-level suites and 73 passing entries including subtests, zero skips. Both
normal and race-enabled runs passed; real-tag vet passed.

| Real package | Normal run | Race run |
| --- | --- | --- |
| Conditional v1 | 55.675 s | 57.751 s |
| Active v3 | 81.558 s | 76.626 s |
| Lifecycle v2 | 55.247 s | 52.793 s |

The active suite contains eleven scenario groups: both provider profiles;
activation rejection; conflicting adapters; older-after-retirement barrier;
older-before-barrier convergence; actual pause/ReplicaSet/Pod behavior; replacement
UID; applied/unapplied lost responses; and restricted RBAC. Concurrent adapters
produced one confirmed success and one conditional rejection. Provider projections
kept zero replicas and zero Pods. The harmless paused existing ReplicaSet reached
two Running Pods, then drained to zero. All seven active RBAC denials returned
403. Inherited real suites additionally prove conditional UID/RV patches,
missing markers, scale boundaries, finalizers and lifecycle uncertainty.

Cleanup returned exit zero and independently verified: owned containers zero,
network absent, image volume absent, kubeconfig removed, pre-existing clusters
preserved. The owned cluster `gn-g2-proof-a395b9f6a516` is deleted. Temporary raw
logs and synthetic PHP exchange data remain outside the repository; no credential
is included in permanent evidence.

## Baseline validation defect

`make runtime-engine-config-check` still fails independently:

```text
runtime engine config check failed: ordinary platform workers must not carry the Kubernetes API client label: ['utcp-platform/api', 'utcp-platform/scheduler', 'utcp-platform/telephony-reconciler']
make: *** [Makefile:546: runtime-engine-config-check] Error 1
```

This is the unchanged older assertion versus accepted K5 API-client-label
contract. No assertion, manifest, permission or production validation script is
modified or suppressed. A separate focused positive/negative regression repair
or explicit operator disposition remains required before broader acceptance.

## Production prerequisites and next assignment

Remaining gates: durable PostgreSQL incarnation and shared generation allocation;
production full-envelope claim service; cross-version activation/fence policy;
image/configuration publication ownership; FreeSWITCH fence/restore domain
decision; ADR adoption; all-five-corridor exclusive writer transfer; old PHP
credential cutoff and in-flight/unknown-operation draining; external replica
writers/HPA; compatible rollback; preserved V1/K5 deployed proof; baseline static
repair. These are future integration prerequisites, not newly introduced runtime
regressions. This packet does not authorize immediate GN-G3.

Exactly one next engineering assignment is prepared:
[canonical lifecycle and claim foundation](gn-g2/GN-G2-canonical-integration-assignment.md).
It implements bounded durable authority and service-level verification under
isolated tests, while keeping routes, dispatch, production migrations and writer
cutover inactive until separately authorized.

Current-State Ledger Impact: **NO_CHANGE_REQUIRED**. ADR remains
**PROPOSED / NOT ADOPTED**. Phase-status consistency: **PASS**; repository hygiene
and final preservation checks are recorded below. No canonical ledger edit is
required for this isolated, unadopted proposal implementation.

## Complete changed-file inventory

All 28 paths below are new and untracked. No pre-existing file was modified.
Production application/runtime code and deployed resources remain unchanged.

```text
components/kubernetes-effects/active/claims.go
components/kubernetes-effects/active/contracts/action.schema.json
components/kubernetes-effects/active/contracts/fixtures.json
components/kubernetes-effects/active/contracts/result.schema.json
components/kubernetes-effects/active/engine.go
components/kubernetes-effects/active/engine_test.go
components/kubernetes-effects/active/parity_test.go
components/kubernetes-effects/active/projection.go
components/kubernetes-effects/active/real_api_test.go
components/kubernetes-effects/active/schema.go
components/kubernetes-effects/active/transport.go
components/kubernetes-effects/active/transport_test.go
components/kubernetes-effects/active/types.go
docs/architecture/proposals/UTCP_GN_G2_ACTIVE_RUNTIME_PROJECTION_PROFILES_AND_FIVE_CORRIDOR_PARITY.md
docs/architecture/proposals/gn-g2/GN-G2-canonical-integration-assignment.md
docs/architecture/proposals/gn-g2/active-real-api-evidence.json
docs/architecture/proposals/gn-g2/active-source-parity.md
docs/architecture/proposals/gn-g2/contracts/v3/README.md
docs/architecture/proposals/gn-g2/contracts/v3/action.schema.json
docs/architecture/proposals/gn-g2/contracts/v3/fixtures.json
docs/architecture/proposals/gn-g2/contracts/v3/result.schema.json
tests/gn-g2-active-projection/ActiveAuthority.php
tests/gn-g2-active-projection/ActiveProjectionParityTest.php
tests/gn-g2-active-projection/README.md
tests/gn-g2-active-projection/test_verify.py
tests/gn-g2-active-projection/verify
tests/gn-g2-active-projection/verify-php
tests/gn-g2-active-projection/verify-static
```

## Final regression and preservation results

Final offline command: `tests/gn-g2-active-projection/verify offline`, exit **0**.
The inherited GN-G1/GN-G2/Lifecycle V2 verification entry points are executed by
this wrapper; they were not replaced by fixture-only assertions.

| Category | Actual result |
| --- | --- |
| Conditional Go package | 26 top-level / 169 passing entries including subtests; race/vet/module/format PASS |
| Observer Go package | 21 top-level / 107 passing entries; race/vet/module/format PASS |
| Lifecycle focused Go package with PHP artifacts | 15 top-level / 86 passing entries; zero skips; race/vet/module/format PASS |
| Active focused Go package with PHP artifacts | 18 top-level / 96 passing entries; zero skips; race/vet/module/format PASS |
| Conditional PHP suite | 111 tests / 597 assertions, PASS |
| Observer PHP suite | 59 tests / 368 assertions, PASS |
| Lifecycle PHP suite | 20 tests / 209 assertions, PASS |
| Active PHP suite | 20 tests / 267 assertions, PASS, including final Go-to-PHP fingerprint exchange |
| Active wrapper safety tests | 3 tests, PASS; inherited cluster-identity/lifecycle safety tests also PASS |
| v1/v2/v3 schemas and fixtures | PASS through embedded Go validation and independent PHP validation; normative/embedded v3 copies identical |
| mTLS, identity, redaction, backpressure, claims, unknown outcomes | PASS in Go/PHP focused and inherited regression suites |
| Real API suites | 3 top-level / 73 passing entries, zero skips; all three race suites and real-tag vet PASS |
| Active documentation/new-file validation | PASS, all 28 task-owned files |
| `make repository-hygiene phase-status-consistency-check` | PASS; status-impact=no |
| `git diff --check` | PASS; no staged or tracked diffs |
| Baseline artifact SHA-256 comparison | PASS; all 177 prior files unchanged, none missing |
| `make runtime-engine-config-check` | FAIL, unchanged independent K5 API-client-label mismatch above |

The broad module run intentionally lacks the separate PHP artifact environments
for active (three skips) and lifecycle (two skips). The focused runs then supply
the correct exchange environments and execute every skipped parity/claim test:
zero skips in each focused run. No real API scenario was skipped.

Exact Go commands executed by the pinned wrappers include:

```bash
# Existing effects module; exports its independent PHP parity data.
UTCP_GN_G2_EXPORT=1 go test -count=1 -v ./...
go test -race -count=1 ./...
go vet ./...
go mod verify
# Existing observer module, through its verifier.
go test -count=1 -v ./...
go test -race -count=1 ./...
go vet ./...
go mod verify
# Focused parity environments, in the existing effects module.
go test -count=1 -v ./lifecycle
go test -race -count=1 ./lifecycle
go vet ./lifecycle
go test -count=1 -v ./active
go test -race -count=1 ./active
go vet ./active
# Real API suites, using the explicit owned kubeconfig/context.
go test -p 1 -tags realapi -run TestRealAPI -count=1 -v -timeout=360s ./...
go test -p 1 -race -tags realapi -run TestRealAPI -count=1 -timeout=360s ./...
go vet -tags realapi ./...
```

Go formatting checks cover original module sources and every new active Go file.
The final static validator parses new JSON/Python/PHP, verifies local Markdown
links/whitespace, scans private-key payloads and disallowed infrastructure access,
and checks embedded/normative contract copies. Sanitized evidence additionally
rejects bearer credentials, private keys and token/password fields. No generated
binary, certificate, kubeconfig, cache or raw operation envelope was added.

Final repository identity remains `main` at
`4ef700639c50a58933bc5a0d1e7ae08628cc4c62`; no tracked/staged modifications,
205 untracked files, no commit or push. Recommend a separately authorized scoped
preservation commit only after review, secret scan and exact staged-file audit.
Current-State Ledger Impact: **NO_CHANGE_REQUIRED**.
Phase-Status Consistency Check: **PASS**.
