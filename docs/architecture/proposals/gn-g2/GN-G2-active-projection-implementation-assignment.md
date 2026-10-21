# GN-G2 follow-up — Versioned active runtime projection profiles

Copy/paste assignment:

```text
Assignment: UTCP_GN_G2_ACTIVE_RUNTIME_PROJECTION_PROFILES_AND_FIVE_CORRIDOR_PARITY
AI worker: OpenAI Codex CLI. Model: GPT-6. Reasoning: High.
Role: Principal Kubernetes Control Plane Engineer / Distributed Systems Engineer.
Repository: grimange/unified-telephony-control-plane.

Implement the next isolated, undeployed lifecycle adapter capability. Start from
components/kubernetes-effects/lifecycle and the GN-G2 Lifecycle V2 report. The
current executable projection supports only inert.pause.v1; do not mistake that
profile for active Asterisk/FreeSWITCH parity or GN-G3 writer-cutover readiness.

Required tools: Git/ripgrep/source inspection, pinned Go 1.25.8 Docker SDK,
client-go/api/apimachinery v0.35.3, existing PHP 8.4/PHPUnit image, JSON Schema,
Go unit/race/vet/module/format checks, controlled HTTP fault injection, and the
existing disposable-cluster real API harness. Do not install host packages.

First read root/path instructions, phase-status ledger, proposed adapter ADR,
v1/v2 executable contracts, lifecycle report and actual PHP provisioning,
convergence, fencing, restoration and deprovisioning implementations. Record
branch/HEAD/full working-tree state and preserve all prior artifacts. No commit
or push. ADR remains PROPOSED / NOT ADOPTED; canonical V1/K5/RMA-E authority is
unchanged.

Authorized source scope: isolated Go adapter, explicitly versioned closed
contracts/fixtures, test-only PHP parity bridge, verification harness and
concise documentation. Do not change production PHP behavior, persistence,
Kubernetes manifests/RBAC, routing or deployed writers. No GN-G3 cutover.

Deliver reviewed, fixed active Asterisk and FreeSWITCH projection profiles
covering the actual required image identity, resources, named ports, probes,
Secret reference slots, approved volumes/mounts, runtime user/security settings,
placement and configuration references. Inventory actual provider projections
before selecting fields. Use no raw Kubernetes manifest escape hatch or generic
maps. Reject unsupported external side effects, ownership injection and
privilege escalation. Explicitly version any new request shape or fingerprint
encoding; preserve current v1 and inert-profile compatibility without silent
reinterpretation. Mutable production image tags require a deliberate canonical
digest-resolution contract; never invent a digest or silently relax pinning.

Keep create_suspended wholly inert. Active projection/activation requires exact
one-way-bound UID/incarnation, current full-envelope canonical claim, generation,
fingerprint and expected RV. Preserve the conditional parent Deployment atomic
barrier/template/replica/applied-marker operation, field ownership rules,
retirement holds and observe-first unknown outcomes. No automatic RV refresh,
blind retry or implicit activation under an old create claim. Go allocates no
canonical generation, determines no tenant policy, and opens no DB/Redis client.

Provide test-only PHP projection producers and independent fingerprints using
actual provider templates. Demonstrate semantic parity for all five application
corridors: managed provisioning, workload convergence, runtime fencing, runtime
restoration and managed deprovisioning. Distinguish mapping/fixture parity from
production integration; do not modify current writers to make tests pass.

One newly named isolated disposable k3d cluster is authorized for API verification
and cleanup only. Use private explicit kubeconfig/context, loopback endpoint,
cluster/run identity checks, fixture-admin versus restricted adapter identities
and existing pinned images. Never use canonical k3s, historical utcp-local, live
credentials, production databases or implicit default context. Active telephony
Pods/calls or external connectivity are not authorized: use paused/suspended API
fixtures and controlled test images when runtime execution would be required.
Report that proof gap rather than claiming active runtime readiness.

Acceptance: closed schemas and positive/negative fixtures; independent PHP/Go
fingerprint and provider-projection parity; immutable UID/incarnation and
full-claim enforcement; one-way binding; atomic CAS projection/barrier markers;
retirement/activation holds; whole-template inert creation unchanged; stale
claims/generations, concurrent writes, incompatible injection, lost responses,
replacement UID and finalizers covered. Run full GN-G1/GN-G2/lifecycle verifiers,
go test -count=1 ./..., go test -race -count=1 ./..., go vet ./..., go mod verify,
format/schema/PHP/mTLS/security/RBAC and authorized real API tests. Record exact
commands/counts, API versus simulation limits, cleanup and full file inventory.
Run hygiene, phase consistency, docs and git diff --check. Keep the independent
runtime-engine-config-check label mismatch visible; no incidental checker repair.

Deliver isolated implementation, versioned contracts and parity fixtures,
permanent regressions, one-command pinned verification, concise evidence report
and one bounded next prompt based on remaining prerequisites. Do not recommend
GN-G3 execution until production lifecycle persistence/callback, ADR adoption,
legacy drain/unknown-outcome migration, exclusive writer/credential cutoff,
external replica writers, compatible rollback and V1/K5 proof have explicit
implementation and acceptance plans. State implemented, simulated, API-proven,
runtime-unverified and planned work separately.
```
