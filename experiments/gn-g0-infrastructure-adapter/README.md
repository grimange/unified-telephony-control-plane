# GN-G0 isolated infrastructure adapter experiment

**NON-PRODUCTION / PROPOSED CONTRACT PROOF — 2026-10-21.** This module is not an
RPC service and has no production entrypoint, image, service discovery, manifests
or application registration. It uses only fake clients and loopback HTTP models.
It must never be pointed at a cluster or given live credentials.

The [GN-G0 report](../../docs/architecture/proposals/UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF.md)
and [v1 contract](../../docs/architecture/proposals/gn-g0/contracts/v1/README.md)
define the proposal and its proof boundaries. The E1 report is historical and
unchanged; this module supplies the previously missing executable feasibility.

## Pinned tools and dependencies

Host Go was absent. No host package was installed. Docker resolved
`golang:1.25.8-bookworm` to:

```text
golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084
go version go1.25.8 linux/amd64
k8s.io/api v0.35.3
k8s.io/apimachinery v0.35.3
k8s.io/client-go v0.35.3
github.com/santhosh-tekuri/jsonschema/v5 v5.3.1
github.com/evanphx/json-patch/v5 v5.9.0
```

The SDK digest is immutable; [go.mod](go.mod) and [go.sum](go.sum) pin dependencies.
The selected API minor matches the repository's k3s 1.35.3 assumption. No real
cluster compatibility or production security-support claim follows. Revisit
patch releases/security advisories before deployment adoption.

PHP verification uses the existing local `utcp-api:dev-test` image by immutable
local image ID:
`sha256:9efe210fb8c8e7c90d80fce2b6916b46824f0f0428d6037dd599ec5ef1dd4d7d`.
It contains PHP 8.4.15 and PHPUnit 11.5.56. This local ID is not a distributable
registry reference. If unavailable elsewhere, build the repository's existing
API test target and deliberately update the proof runner to that inspected image
ID; do not silently run a different image. Application source/tests/config are
mounted read-only over image dependencies. SQLite is in-memory; networking is
disabled. Current source, rather than embedded old application source, is tested.

## Reproduce from repository root

Docker must be available. Initial image/module acquisition needs ordinary
registry/module access; subsequent verification has no external network.

```bash
docker pull golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084
experiments/gn-g0-infrastructure-adapter/verify --download
experiments/gn-g0-infrastructure-adapter/verify
experiments/gn-g0-infrastructure-adapter/verify-php
python3 experiments/gn-g0-infrastructure-adapter/verify-text
make repository-hygiene phase-status-consistency-check control-plane-config-check workflow-check
git diff --check
git status --short --untracked-files=all
```

`verify` runs `go mod verify`, checks gofmt, then `go test -count=1 ./...`,
`go test -race -count=1 ./...` and `go vet ./...`. Its extra count flag prevents
cached test results. Docker runs as the invoking UID/GID, with source/contracts
mounted read-only and no network. Tests create only loopback httptest servers;
the race detector uses the SDK's existing C compiler. No Docker socket or
kubeconfig is mounted. A task-specific module/build cache defaults to
`/tmp/utcp-gn-g0-go-cache`; override `UTCP_GN_G0_CACHE` if needed. No build caches
or binaries belong in the repository. `--download` permits only the separate
dependency-acquisition container network access.

For host Go 1.25.8 with dependencies already cached, run in this directory:

```bash
go test ./...
go test -race ./...
go vet ./...
```

[verify-php](verify-php) names every existing focused suite and adds the isolated
[PhpContractParityTest.php](PhpContractParityTest.php). Its three tests compare
shared Node/Pod fields through the current host-visibility service, independently
compute the lossless intent hash, and check current PHP exception reasons.
They do not prove full tenant/public API parity. [verify-text](verify-text)
checks local Markdown file links and whitespace, including untracked artifacts;
it does not fetch external websites or validate Markdown heading anchors.

## Experiments and evidence classification

| Test | Mechanism / demonstrated property |
|---|---|
| TestSchemasAndFixtures | JSON Schema 2020-12; 29 valid/invalid wire fixtures and independently checked fingerprint |
| TestFakeNormalizationAndRedaction | client-go fake list input; stable ordering, Ready/placement, absent topology/controller, safe field projection, secret sentinel omitted |
| TestHTTPPaginationAndFailures | real client-go HTTP transport to httptest; paged Nodes/Pods, opaque tokens, empty intermediate page, 403/410, changed RV, repeated token, duplicate/malformed identities fail incomplete |
| TestRepeatedIntentUnknownOutcomeAndRedaction | barrier/effect/duplicate; applied-then-disconnected response returns unknown; direct read recovers without another mutation; safe receipts |
| TestIdentityOwnershipAndConflictFailClosed | wrong cluster/namespace/UID/labels/instance/RV, same-generation hash conflict, ordinary 422 classification |
| TestDelayedOldWriteAfterNewBarrier | old held patch fails after newer generation's barrier/effect commits in the conditional HTTP model |
| TestLeaseExpiryBeforeDispatchAndUnchangedRV | expired claim or denied authorization sends no patch |
| TestLeaseExpiryAfterDispatchBeforeNewBarrierMayApply | old sent effect can apply after logical lease expiry; next intent converges |
| TestDuplicateWorkersSameIntent | concurrent same intent; at most one actual barrier patch; race detection exercises shared model |
| TestCancellationTimeoutAndAPIUnavailable | canceled context/deadline and API failure classifications; no implied rollback |

Emitted action results and HTTP-acquired snapshots are also checked against the
versioned schemas. The model implements RFC6902 patches and RV changes; these
are **simulated**, never Kubernetes API-server validation. The client-go fake
does not supply real pagination, admission, defaulting, CAS or subresource proof.

The prototype deliberately implements only `admit_generation` and `set_replicas`
on a bound Deployment. It is not a production decoder/server: mTLS, authorization
callback transport, durable PG generation allocation, bounds at the raw transport
layer, 410 whole-acquisition restart, telemetry and full observation parity remain
implementation gates. No create/delete/projection/lifecycle execution is present.
Actual API Status/predicate enforcement, delayed create containment, UID/RV delete
preconditions and all five writer corridors require GN-G2 integration tests.
Credential cutoff/rollback and natural-live V1/K5 acceptance require GN-G3.

Exact command outcomes and limitations are recorded in
[verification-results.txt](verification-results.txt).
