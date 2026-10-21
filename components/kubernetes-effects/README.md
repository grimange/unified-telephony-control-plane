# Isolated conditional Deployment effects

GN-G2 implements `admit_generation` and `set_replicas` against an existing,
canonically bound Deployment. This library has no executable, listener,
deployment manifest, database, Redis client, controller or credential discovery.
The proposed ADR remains **PROPOSED / NOT ADOPTED**.

The unchanged [GN-G0 v1 contract](../../docs/architecture/proposals/gn-g0/contracts/v1/README.md)
is normative. `schema.go` validates its closed schemas and rejects ambiguous
JSON. `types.go` implements lossless generation strings and byte-length-prefixed
fingerprints. `engine.go` checks trusted cluster/namespace, full immutable
identity, ownership, paired markers and current claims. It builds one JSON Patch
on the parent Deployment, including UID/resourceVersion tests and the complete
effect. It never writes `/scale`, retries a mutation or refreshes a conflicting
resourceVersion automatically.

`kubernetes.go` uses direct client-go with explicitly supplied HTTPS trust and
credentials. Its transport permits only GET and JSON Patch on configured
namespace Deployment paths. A one-use target-bound permit reserves rate budget
before the final claim callback; claim expiry bounds the mutation context.
`claims.go` sends the complete action to one fixed HTTPS/mTLS Laravel verification
endpoint without cached authorization. `transport.go` creates an HTTP handler,
requiring the executor client URI identity and verified mTLS. GN-G1 supplies the
unchanged TLS implementation; observer and executor caller identities are distinct.

`Engine.Recover` is a read-only observe-first operation. It does not add a new
wire action or replay the write. Laravel must preserve the normalized result,
reacquire canonical authorization and decide whether a subsequent action is safe.
Replica equality alone never establishes completion. PostgreSQL cannot retract
an already dispatched Kubernetes request when a lease expires. Barriers protect
against older writes only after the newer barrier is installed.

Run the complete offline verification from the repository root:

```bash
./components/kubernetes-effects/verify
```

This uses the GN-G1 pinned Go 1.25.8 SDK digest and client-go/API/apimachinery
v0.35.3, the pinned PHP 8.4 test image, read-only source mounts and network-disabled
containers. Existing Go module/cache dependencies and images must already be
available. It installs no host packages. Disposable sanitized PHP/Go parity
exports go to `/tmp/utcp-gn-g2-output`; the Go cache defaults to
`/tmp/utcp-gn-g0-go-cache`. Override them with `UTCP_GN_G2_OUTPUT` and
`UTCP_GN_G2_CACHE`. The verifier runs PHP export, Go unit/race/vet/module/format
checks, reverse PHP parity, the complete GN-G1 verifier, and static documentation
checks. The first PHP pass may skip reverse parity before the Go export exists;
the final PHP pass must have no skip.

The offline tests use in-memory conditional JSON Patch models, controlled HTTPS protocol
servers, ephemeral in-memory certificates and disposable SQLite through the
existing Laravel repository. They establish implementation behavior, not real
Kubernetes admission, defaulting or concurrency enforcement. In the original
implementation packet no disposable API-server target was authorized, so its
historical gate was **DEFERRED / NOT VERIFIED**. See the [GN-G2 report](../../docs/architecture/proposals/UTCP_GN_G2_CONDITIONAL_KUBERNETES_DEPLOYMENT_EFFECTS.md)
and [real API-server gate](../../docs/architecture/proposals/gn-g2/real-api-verification.md)
before any writer-cutover decision.

## Separately authorized real API proof

The later real API assignment adds a build-tagged test and an explicitly owned
disposable-cluster runner. It does not deploy this library or transfer writer
authority. After explicit authorization for isolated cluster creation, run:

```bash
./tests/gn-g2-real-api/verify
```

The runner requires existing pinned images/tooling, creates an isolated loopback
target without ambient credentials, executes real API unit/race/vet checks, and
verifies owned cleanup. Ordinary `./components/kubernetes-effects/verify` stays
offline. Read the [proof runner instructions](../../tests/gn-g2-real-api/README.md)
and [dated real API report](../../docs/architecture/proposals/UTCP_GN_G2_REAL_KUBERNETES_API_CONDITIONAL_EFFECT_VERIFICATION.md)
for actual results and limitations. The completed proof is
`GN_G2_REAL_API_VERIFIED` on Kubernetes `v1.35.3+k3s1`: 16 scenario groups,
race detection and tagged vet passed, with verified cleanup. The isolated
GET/PATCH client now decodes structured API Status before reading successful
bytes; conservative 422 handling is unchanged. API success alone does not
establish production or writer-cutover readiness. The next implementation is
[bounded lifecycle v2](../../docs/architecture/proposals/gn-g2/GN-G2-lifecycle-v2-implementation-assignment.md).
