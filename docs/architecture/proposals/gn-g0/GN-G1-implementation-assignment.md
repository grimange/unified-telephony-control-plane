# Ready-to-copy GN-G1 implementation assignment

Copy the following prompt as one assignment. This file is a proposal-local
handoff, not a roadmap status or architectural adoption.

```text
UTCP — GN-G1: Undeployed Read-only Go Infrastructure Observation Parity

Recommended worker: OpenAI Codex CLI.
Recommended model: GPT-6, High reasoning (record actual model availability).
Role: Principal Kubernetes Platform Engineer / Distributed Systems Engineer.
Execution: bounded implementation and isolated verification; no deployment.

Read AGENTS.md and applicable local instructions before edits. Record branch,
HEAD and exact initial working tree; preserve all E1/GN-G0 untracked artifacts
and unrelated work. Review:
- docs/architecture/proposals/UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF.md
- docs/architecture/proposals/go-kubernetes-infrastructure-adapter-adr-proposed.md
- docs/architecture/proposals/gn-g0/contracts/v1/README.md and schemas/fixtures
- experiments/gn-g0-infrastructure-adapter/README.md and verification-results.txt
- docs/architecture/overview.md, applicable accepted ADRs, K5 evidence and
  docs/roadmap/phase-status.md (sole current-state authority).

Authority: this prompt authorizes isolated read-only source implementation and
tests. It does not adopt the proposed ADR, activate a new observer, authorize
cluster access, credentials, schema changes, new deployment/process roles or
canonical routing changes. ADR remains PROPOSED / NOT ADOPTED unless the operator
separately and explicitly adopts it. Preserve the RMA-E canonical next action
and accepted V1/K5 classifications. Reconcile the ledger only if separately
authorized work actually changes canonical state.

Required tools: Git/rg/source inspection; pinned Go SDK container or existing
toolchain; direct client-go; httptest/fake clients; Go unit/race/vet; current
PHP test image/PHPUnit; Make checks; read-only manifests/RBAC/NetworkPolicy;
official Kubernetes documentation for API semantics. No host package installs.
Use the GN-G0 immutable SDK/library versions for reproduction, or document a
deliberate patch upgrade and compatibility evidence. Never give tests live
credentials or a kubeconfig. Production mutation is prohibited.

Authorized implementation:
1. Select and document an isolated non-deployed observer module path under
   repository conventions; keep the GN-G0 experiment historical and separate.
   Implement only the v1 observations endpoint/direct-list acquisition, strict
   decoding and schema/semantic validation. No action endpoint, mutating client,
   CRD, controller-runtime manager, informer, own work queue or business policy.
2. Acquire complete paged Nodes and Pods for configured single-namespace or
   cluster-wide scope. Keep selectors/tokens unchanged, verify per-list RV and
   identities, cap pages/items/raw/projected bytes, reject repeated tokens and
   malformed/duplicate identity. Restart the entire acquisition at most once on
   410 within deadline; never splice list snapshots. Do not claim atomic cross-
   resource consistency. Failure is incomplete/null facts, never healthy empty.
3. Implement stable normalized ordering, nullable topology/placement/controller,
   tri-state readiness, allowed labels and redaction exactly per v1. Inventory
   actual PHP policy label dependencies and test the proposed restricted Node
   projection. Preserve the existing utcp.dev/runtime-node slug and Deployment
   instance correlation. Any incompatible required projection needs an explicit
   reviewed version/proposal; do not silently change a public API.
4. Implement observer-role mTLS validation with ephemeral test certificates,
   fixed cluster/namespace scope, bounded deadlines/cancellation/concurrency,
   saturation and projected token/certificate reload. Prove read-only behavior
   through HTTP request recording and source/dependency inspection. No write
   credentials or deployment manifests are introduced.
5. Build a test-only PHP bridge to the existing KubernetesInfrastructureClient
   seam. Run shared fixtures through current host visibility, placement, K5C
   refresh and tenant projection contracts; compare full established responses
   where retained and explicitly review intentional safe-field differences.
   Laravel alone persists/correlates facts. No production provider binding,
   canonical observer switch or second observation-persistence path. Specify
   per-scope acquisition ordering/freshness for future routing; test overlapping
   completion and stale/unknown recovery without changing canonical schemas.
6. Measure request counts, latency and CPU/RSS for fixed workloads against PHP
   using isolated controlled HTTP fixtures. Label results as simulated workloads;
   do not claim live Kubernetes performance. Demonstrate bounded process shutdown,
   cancellation and recovery after failure; no mutation permissions are needed.

Baseline validation defect: GN-G0 identified scripts/runtime-engine/config-check
lines 415–424 as stale versus accepted K5 API-client labels and exact SA mapping.
Do not modify that production script in this GN-G1 packet. A separately authorized
repair must prove exact allowed workload/SA set, all five infrastructure worker
routes, restricted egress and failure/interrupt-safe mutation-harness restoration.
Before GN-G1 acceptance, link a passing repair packet or explicitly isolate this
pre-existing defect with operator disposition. Do not disable the check or claim
all-green acceptance while it fails.

Deliverables:
- Isolated read-only observer implementation, pinned dependencies, strict v1
  schemas/fixtures compatibility and reproducible verification scripts.
- PHP fixture bridge and full observation/tenant/parity regression evidence.
- Tests for complete/empty/paginated lists; opaque tokens; changed RV; 410 restart;
  401/403/429/5xx; interrupted/malformed/duplicate/oversized responses; missing
  topology/owners; disappearing Node/Pod; cancellation; staleness/overlap; safe
  fields; role/scope denial; certificate/token rotation; saturation; recovery.
- Sanitized resource/request-cost comparison with workload, commands and limits.
- Evidence report separating unit/fake/HTTP simulation, real API integration,
  deployment and natural-live proof. Real API/live proof remains unverified in
  this authorization. Update proposed documents only with supported findings.
- A bounded GN-G2 prompt; no GN-G2 execution or writer cutover.

Validation/acceptance:
Run go test ./..., go test -race ./..., go vet ./..., module integrity and format;
current focused PHP/Kubernetes/K5 contracts and meaningful added parity tests;
repository hygiene, phase-status consistency, docs links/whitespace, workflow
and relevant static checks; inspect final tracked/untracked diff. Record commands,
tool/image digests and every failure with classification. No lost facts may be
silently converted to success, tenant authorization must remain PHP/PG, and
no write or independent reconciliation authority may appear. Do not claim
deployment readiness or full live parity from fake/HTTP tests alone.

Handoff: state implementation outcome, authority preservation, baseline/final
identity/tree, exact changed files, tests/costs, baseline defect disposition,
proof gaps, ADR status, ledger impact and consistency result. Do not stage,
commit or push unless explicitly asked. Only operator authorization can adopt
the extraction and subsequently deploy/route it.
```
