# Stage 4 — Native k3s deployment foundation

## Implementation record

The repository now contains a `server` Kubernetes overlay and explicit
`server-config-check`, `server-image-preflight`, `server-apply`,
`server-status`, and `server-proof` lifecycle commands. The overlay reuses the
Kubernetes data, migration, platform, runtime, and runtime-fencing bases. The
local `k3d` overlay and lifecycle remain unchanged.

The continuation establishes GHCR as the native image publication authority
through `.github/workflows/native-images.yml`. The workflow runs the existing
API and web image tests/smoke checks before publishing six repository-owned
images under `ghcr.io/<repository-owner>/utcp-*`, captures immutable digests,
resolves the pinned Kamailio/PostgreSQL/Redis digests, and uploads a generated
`native-k3s-image-lock.env` artifact. The lock correlates every image with the
full GitHub source commit and is the native deployment input.

Native commands require an explicit, readable kubeconfig/context and validate
the expected `utcp-dev01` node. Contexts containing `k3d` or `utcp-local` are
rejected. Native image references come from the GHCR image lock; the k3d
registry is rejected. The server layer
uses `local-path` PVCs and changes the base external Kamailio Service to
ClusterIP so this foundation does not expose SIP/RTP publicly.

## Verification status

Passed repository-side checks:

- shell syntax and whitespace checks;
- native Kustomize render and exposure checks using a non-k3d registry input;
- rejection of an unsafe `k3d-utcp-local` native context;
- native target validation against `utcp-dev01` through the current user kubeconfig.

The continuation adds GHCR publication architecture and a generated native
image-lock contract, but publication and workload deployment were not claimed
in this run. GHCR package visibility and the resulting artifact download are
operator/account-owned inputs. Browser proof was therefore not run because
the native application was not deployed.

Passed continuation checks:

- workflow action pinning and dedicated workflow actionlint validation;
- Dockerfile OCI source/revision metadata additions for Asterisk and
  FreeSWITCH;
- complete digest-lock render covering all native image consumers;
- rejection of mutable or local image references in the rendered native
  manifest;
- hermetic local Kubernetes managed-image mutation regression.

The existing local Kubernetes regression script currently cannot render the
local overlay because its ignored/generated Asterisk credential file is absent
in this checkout. The mutation regression now generates and cleans these
credentials hermetically; the remaining k3d configuration check cannot run
because the `k3d` executable is not installed on this host.

## Final live acceptance reconciliation

The native deployment was subsequently completed and proven on `utcp-dev01`
using the validated native k3s context and the immutable GHCR image lock. The
PostgreSQL and Redis StatefulSets reached readiness through `local-path` PVCs;
the migration Job completed; and the API, worker, scheduler, web, gateway,
telephony runtime, and reconciliation workloads reached their expected
readiness states. Bounded persistence and workload recovery proofs passed.

The UTCP-owned Traefik and Gateway API layer reached readiness with the
prepared CA-signed HTTPS certificate for `app.utcp.local.test`. Gateway routes,
security policy/RBAC, observability components, and the no-public-SIP/RTP
exposure boundary were proven without enabling bundled k3s Traefik.

The native APP_KEY generator defect was resolved during live acceptance. The
former generator emitted hexadecimal text with a `base64:` label, producing an
invalid 48-byte decoded key. Native generation now uses 32 cryptographically
random bytes with correct base64 encoding. Existing malformed native keys are
repaired in place by replacing only `APP_KEY`; valid keys remain stable and
database credentials are preserved. The focused regression covers generation,
32-byte decoding, idempotency, malformed-key repair, credential preservation,
and Kubernetes Secret projection.

After projection, the API container reported a 32-byte decoded APP_KEY. The
CSRF endpoint returned HTTP 200 and the unauthenticated session endpoint
returned the normal HTTP 401 response rather than HTTP 500.

Final browser acceptance used Playwright MCP against
`https://app.utcp.local.test`. The real login page was used, authentication
succeeded, `Local Tenant` was selected through the visible UI, and the
read-only Runtime nodes Admin surface rendered its canonical empty state. The
browser's Runtime nodes request returned HTTP 200 with `{"runtime_nodes":[]}`;
the result matched the rendered state and the authenticated capability/tenant
context returned by the API.

The Runtime nodes page displayed `Live updates disconnected`, but no current
corresponding failed request was captured and the required API-backed read-only
acceptance passed. This remains a non-blocking follow-up observation, not a
Stage 4 defect claim.

Final verdict:

`STAGE_4_NATIVE_K3S_DEPLOYMENT_FOUNDATION_IMPLEMENTED_AND_PROVEN`
