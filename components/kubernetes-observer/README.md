# Isolated GN-G1 Kubernetes observer

Functional read-only component; **undeployed**. This module has no executable,
listener, production image, route, manifest, database connection or scheduler.
It implements the proposed [GN-G0 v1 observation contract](../../docs/architecture/proposals/gn-g0/contracts/v1/README.md).
The ADR remains PROPOSED / NOT ADOPTED. Laravel owns tenant authorization,
RuntimeNode correlation, policy, canonical persistence and reconciliation.

From the repository root, run:

```bash
components/kubernetes-observer/verify
```

The command uses Go 1.25.8 by immutable SDK image digest, a disposable Go cache,
network-disabled containers, read-only source mounts, and the existing pinned
PHP test image. It runs unit/protocol/mTLS tests, the race detector, vet, module
integrity, PHP parity/regression tests and local source/document checks. If the
module cache is empty, `components/kubernetes-observer/verify --download` first
fetches the pinned module graph inside the container. No host installation,
live kubeconfig or Kubernetes credentials are used. Docker must already work.
`UTCP_GN_G1_CACHE` and `UTCP_GN_G1_OUTPUT` select disposable directories; defaults
are `/tmp/utcp-gn-g0-go-cache` and `/tmp/utcp-gn-g1-output`.

## Assembly

`NewKubernetesReader(UpstreamConfig)` requires an explicit HTTPS upstream, CA,
token and Pod namespace (`*` means configured cluster-wide visibility). It does
not discover ambient credentials. The client-go REST client is private; a
second transport boundary permits only GET on the fixed Node and Pod collection
paths. Redirects, arbitrary hosts, Secrets and write verbs are rejected.

Compile the unchanged schemas with `NewValidator(trustedContractDirectory)`.
Construct `NewObserver(Collector{Reader: reader, ClusterID: id, Namespace: scope}, validator)`.
Supply that function and both validator callbacks to `NewHandler(TransportConfig)`:

```go
observe, err := observer.NewObserver(collector, validator)
// Handle err before constructing the handler.
handler, err := observer.NewHandler(observer.TransportConfig{
    ClusterID: collector.ClusterID,
    AllowedNamespaces: []string{collector.Namespace},
    ClientIdentity: "spiffe://utcp.invalid/laravel/observer",
    Observe: observe,
    ValidateRequest: validator.ValidateRequest,
    ValidateResponse: validator.ValidateSnapshot,
})
// A future explicitly authorized host supplies an HTTPS server using handler
// and NewServerTLS(...).Config(); this module starts no listener itself.
```

`TestEndToEndMTLSObservation` runs that assembly against two ephemeral HTTPS
servers and consumes the real normalized response. Tests generate certificate
and key material in memory; no private key fixture is saved in the repository.

## Acquisition and limits

Nodes and Pods are separate paginated lists, with opaque resource versions and
unchanged continuation parameters. The implementation never claims atomic
cross-resource consistency. It discards both collections on HTTP 410, restarting
the whole snapshot once within the original deadline. An exhausted or invalid
acquisition returns an incomplete envelope with null facts and a safe error.
Valid empty lists return complete empty arrays. Missing/null `items`, malformed
identities, changing list RVs, duplicate identities and repeated tokens fail.

Defaults and upper bounds: 500 items/page, 1,000 pages/resource, 100,000 total
objects, 16 MiB/raw response and normalized snapshot, 1 MiB/RPC request, four
concurrent acquisitions, no waiting queue, five-second overall deadline,
two-second connection/TLS handshake, client-go QPS 5/burst 10. Test-only smaller
limits exercise failure paths. Raw pages are projected immediately; abandoned
pages are never merged. A token-bucket wait exceeding the deadline budget is
classified as timeout even when rejected before the deadline actually elapses.

Each successful request acquires fresh lists; duplicate request IDs do not imply
a cache. One Laravel refresh must share one caller-owned snapshot across its
RuntimeNode loop to realize the measured request reduction. Replacing every old
per-node list call with a separate RPC would repeat acquisitions.

`Fresh` enforces the existing 300-second collection-age budget without resetting
timestamps on receipt. There is no adapter-side historical cache. Production
receipt ordering and retention of stale facts remain future Laravel integration
work; the test bridge rejects stale/incomplete data but is not registered in the
application. See the [PHP proof](../../tests/gn-g1-observer/README.md).

## Transport and rotation

HTTPS/mTLS uses TLS 1.3, an explicit CA, strict server DNS and exact client URI
SAN plus EKU/validity checks. Unknown fields, duplicate keys, trailing values,
invalid UTF-8, wrong versions/scopes and operation requests fail before upstream
access. Saturation returns 429 with Retry-After 1; upstream domain errors use
schema-valid HTTP 200 envelopes. Wire errors remain generic non-envelopes.

`ServerTLSMaterial.Reload`, `RotatingHTTPClient.Reload`, and
`KubernetesReader.Reload` atomically install validated new material and preserve
last-good state on invalid updates. Callers must read projected material and
invoke reload; no automatic filesystem watcher is provided. Server trust changes
apply to new handshakes, with session resumption disabled. Client reload closes
old idle connections. Rotation does not revoke in-flight requests or prove
instant credential revocation on established connections. Rollout must account
for the bounded request lifetime.

## Projection and proof limits

Projection preserves only v1 fields. No raw spec/env/annotation/message,
managedFields, Secret, token or credential is returned. Allowed identity labels
are infrastructure evidence, never tenant authorization. Direct controller
references are explicitly `ownership_chain=unverified`.

The closed v1 Pod schema cannot carry `deletionTimestamp` or distinguish a
Running-but-deleting Pod. GN-G1 preserves v1 and current PHP placement behavior;
a negotiated version correction is required before a lifecycle consumer needs
that distinction. Terminal Pod phase remains an observed fact, not a telephony
completion assertion.

Tests prove Go behavior, fake-client normalization, HTTP pagination/failure
protocol simulation, ephemeral mTLS and existing PHP service compatibility.
They do not prove a real Kubernetes API server, deployed RBAC, production
throughput, natural-live behavior or GN-G2 conditional writes.
