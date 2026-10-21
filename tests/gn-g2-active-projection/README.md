# Active runtime projection verification

This packet exercises the isolated v3 Asterisk and FreeSWITCH profiles against
the actual PHP Deployment builders and one fresh disposable Kubernetes API.
It does not install a Go service or transfer writer authority.

From the repository root, run:

```bash
tests/gn-g2-active-projection/verify
```

The verifier runs the existing GN-G1 observer, GN-G2 conditional effects and
Lifecycle V2 offline verification, the new PHP authority/projection tests,
independent PHP/Go fingerprints, formatting, schemas, Go tests, race detector,
vet and module integrity. It then creates exactly one uniquely named k3d
cluster, runs all existing real-API tests plus active-profile tests serially by Go
package to avoid contention against the single-node API server, and destroys
that owned cluster in a finally block. No ambient Kubernetes context is used.
The private run directory and sanitized evidence paths are printed. Credentials
are temporary and are removed by cleanup; never copy the kubeconfig into Git.

To run only offline checks:

```bash
tests/gn-g2-active-projection/verify offline
```

For diagnosis during a still-owned run, `verify execute OWNED_RUN_DIRECTORY`
rechecks container identity, loopback endpoint, context and kube-system UID.
It never creates a second cluster. Cleanup of such a diagnostic run is explicit:

```bash
tests/gn-g2-real-api/verify cleanup OWNED_RUN_DIRECTORY
```

The SDK is Go 1.25.8 at
`golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`.
Kubernetes modules remain v0.35.3. The inherited harness pins k3s
`rancher/k3s@sha256:4607083d3cac07e1ccde7317297271d13ed5f60f35a78f33fcef84858a9f1d69`.
The PHP verifier pins the existing PHP 8.4 test image by immutable image ID.
The existing populated module cache is required at `/tmp/utcp-gn-g0-go-cache`
(override with `UTCP_GN_G2_CACHE`). No host packages are installed.

Provider fixtures use the locally resolved RepoDigests encoded in the verifier,
not substituted pause images. Both actual provider projections remain at zero
replicas, so no telephony binary or traffic runs. The separate Pod/ReplicaSet
activation demonstration uses only the fixed digest-qualified Kubernetes pause
image. That image is pulled into containerd on the owned node directly because
exporting a partially cached multi-platform Docker index can omit child blobs.
Network access to that public image registry is required for a fresh run.

`UTCP_GN_G2_ACTIVE_OUTPUT` selects temporary PHP/Go proof exchange storage,
default `/tmp/utcp-gn-g2-active-output`. These generated proofs contain synthetic lease tokens and complete test
envelopes; keep them temporary and never include them in repository evidence. `test_verify.py` checks the active wrapper's owned-node image
pull guards; the inherited harness independently tests cluster identity guards.
`verify-static` includes untracked source, schema equality, PHP syntax, local
documentation links, whitespace, secret-key payloads and isolation guards.

API acceptance proves conditional resource behavior and harmless fixture
controller behavior. It does not prove provider startup, readiness, SIP/media,
production PostgreSQL integration, mutable configuration publication integrity,
or exclusive writer ownership. FreeSWITCH fencing/restoration infrastructure
effects are testable extensions; current production PHP domain handling is
Asterisk-specific. See the [source parity matrix](../../docs/architecture/proposals/gn-g2/active-source-parity.md)
and [v3 contract](../../docs/architecture/proposals/gn-g2/contracts/v3/README.md).
