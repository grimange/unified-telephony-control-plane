# GN-G2 disposable real API verification

This opt-in harness creates a **new** k3d cluster, verifies the isolated effects
adapter against its real Kubernetes API, and deletes the cluster. It never
selects an ambient context. Production deployment and writer cutover are outside
its scope.

From the repository root:

```bash
tests/gn-g2-real-api/verify
```

Prerequisites are Docker Engine, k3d 5.9.0, kubectl, Python 3, the pinned images,
and the Go dependency cache populated by `components/kubernetes-effects/verify`.
No host package installation occurs. `UTCP_GN_G2_CACHE` may select an existing
dependency cache; its default is `/tmp/utcp-gn-g0-go-cache`.

The pinned images are:

- Kubernetes: `rancher/k3s@sha256:4607083d3cac07e1ccde7317297271d13ed5f60f35a78f33fcef84858a9f1d69`
  (`v1.35.3+k3s1`).
- SDK: `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`
  (Go 1.25.8).

The script first executes six offline harness-safety tests. It creates a random
`gn-g2-proof-<12 hex>` cluster, one server, no agents or load balancer, an
ephemeral loopback-only API port, and a dedicated image volume. It disables
Traefik, ServiceLB, metrics-server, local storage, and CoreDNS. k3d 5.9.0 requires
the image volume during its concurrent tools-container startup. With no load
balancer, k3d preserves literal `:0` in its generated kubeconfig; the harness
replaces that value with the Docker-assigned loopback port after verifying the
owned container and exact port binding, before contacting the API.

Each run uses a private `/tmp/utcp-gn-g2-real-*` directory. Both `KUBECONFIG` and
XDG configuration are confined there. Before testing, the runner checks the
container's random ownership label, immutable container ID, dedicated context,
single-cluster kubeconfig, loopback HTTPS endpoint, and `kube-system` UID.
The Go suite independently checks context, endpoint, name pattern, and system
UID. Unexpected identities fail closed.

Fixture administration uses the ephemeral cluster's credentials. Two adapter
ServiceAccounts separately receive only GET/PATCH on one named Deployment in
the run's namespace. Their short-lived tokens remain in memory. Fixture
Deployments are paused; this is an API semantics test, not workload readiness
or telephony proof. The adapter still implements only `admit_generation` and
`set_replicas`.

The runner executes these commands in the pinned SDK container with the
dependency proxy disabled:

```bash
go test -tags realapi -run TestRealAPI -count=1 -v -timeout=180s ./...
go test -race -tags realapi -run TestRealAPI -count=1 -timeout=180s ./...
go vet -tags realapi ./...
```

It keeps sanitized environment metadata, projected API Status details, test
output, and cleanup confirmation in the run's `evidence/` directory. Evidence
contains no kubeconfig, bearer token, private key, raw resource dump, or raw
Status message. The full existing effects and observer verifiers remain
separate regression gates; the `realapi` build tag is excluded from their
normal tests.

Cleanup verifies the original container ownership before deletion. It checks
that the server, tools container, network, image volume, and dedicated kubeconfig
are gone and pre-existing cluster names remain. Namespace cleanup also checks
its run label and recorded UID and supplies a UID deletion precondition.

For bounded debugging, explicit lifecycle commands are available:

```bash
tests/gn-g2-real-api/verify prepare
tests/gn-g2-real-api/verify execute /tmp/utcp-gn-g2-real-EXACT_RUN_DIRECTORY
tests/gn-g2-real-api/verify cleanup /tmp/utcp-gn-g2-real-EXACT_RUN_DIRECTORY
```

`prepare` intentionally leaves its new cluster running. Always perform cleanup
using its exact reported directory. Never replace these arguments with an
existing cluster or ambient kubeconfig. The normal no-argument command performs
cleanup in a `finally` block even when test execution fails. A failed ownership
check stops cleanup and requires inspection; it does not authorize deletion of
an unexpected resource.

Real API proof applies to the recorded Kubernetes version. PostgreSQL claim
verification and internal mTLS retain their separate PHP/Go and HTTP regression
proof. This test does not establish PostgreSQL/Kubernetes atomicity, revocation
of dispatched requests, production callback integration, lifecycle v2,
exclusive production replica ownership, writer cutover, or natural-live
telephony acceptance.
