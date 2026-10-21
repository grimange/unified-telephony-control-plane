# Isolated Deployment Lifecycle V2 verification

This packet implements and tests only `create_suspended`, `converge_projection`
and `retire_delete`. It creates no production listener, route or deployment.
Laravel/PostgreSQL retains lifecycle, UID binding, tenant, generation and claim
ownership. The [v2 contract](../../docs/architecture/proposals/gn-g2/contracts/v2/README.md)
is separately negotiated; the v1 observer and two existing effects remain intact.

From the repository root, with the existing pinned SDK/PHP/node images and Go
cache available:

```bash
tests/gn-g2-lifecycle/verify
```

This command runs PHP lifecycle/parity checks, GN-G1/GN-G2 regression checks,
Go unit/race/vet/module/format checks, then creates **one new owned k3d cluster**,
runs both v1 and v2 real API suites against it, and attempts verified cleanup in
`finally`. Only execute the default command with authorization to create that
new disposable environment. No canonical or ambient context is selected.

For diagnosis, `verify offline` performs no Kubernetes access. To retry against
an already prepared, owned run, use `verify execute /tmp/utcp-gn-g2-real-EXACT`.
The existing [cluster harness](../gn-g2-real-api/README.md) owns identity checks,
private kubeconfig, explicit context, loopback endpoint, nonce/container identity,
namespace isolation and cleanup. Its `prepare` and `cleanup` modes can be used
with explicit authorization; never create another cluster to evade a test failure.

The SDK is Go 1.25.8 at
`golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`.
Kubernetes libraries remain v0.35.3; the server image is
`rancher/k3s@sha256:4607083d3cac07e1ccde7317297271d13ed5f60f35a78f33fcef84858a9f1d69`.
PHP uses the existing pinned 8.4 test image. No host package install is required.

Evidence remains in the private run directory. Do not copy bearer tokens,
kubeconfigs, certificates, private keys or raw objects into repository evidence.
The report records sanitized API outcomes and the exact proof boundary. Real
API success establishes object mutation semantics, not telephony readiness or
production canonical persistence. The PHP lifecycle model is test-only; SQLite
coverage is not PostgreSQL concurrency proof. Lifecycle v2 remains proposed,
and no writer cutover is authorized.
