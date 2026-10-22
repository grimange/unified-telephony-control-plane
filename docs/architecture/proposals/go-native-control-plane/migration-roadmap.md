# Proposed Go control-plane migration sequence

Status: **PROPOSED / NOT EXECUTION AUTHORIZATION**, 2026-10-21.
Parent: [pivot report](../UTCP_GO_NATIVE_CONTROL_PLANE_ARCHITECTURE_PIVOT_AND_MIGRATION_PLAN.md).
CP0–CP6 are proposal identifiers, not additions to the canonical phase ledger.
The existing canonical RMA-E next action and V1/K5 classifications remain intact.

## Sequencing rule

Implement bounded vertical slices against disposable databases and deterministic
provider fixtures, with Laravel as the behavioral oracle. Initially **all live
canonical writes stay with PHP**. Go may read only through separately authorized
shadow credentials, and shadow execution must not deliver outbox events, consume
canonical leases or invoke external effects. The architecture assignment does
not authorize even that shadow deployment.

Implementation stages and authority-transfer stages are distinct. Routes that
share a transaction or invariant cannot be split between writers merely by URL.
The default final transfer cohort includes identity, kernel, registry, telephony,
recording and signaling application authority because their transactions and
outbox are coupled. Smaller live transfers are permitted only by a later packet
proving transaction closure, credentials, event ownership and compatible rollback.
This is incremental implementation and verification, not an untested whole-backend
rewrite followed by a switch. Each stage produces an executable reviewed slice;
CP6 is a series of controlled rehearsals and gates, not permission to flip traffic.

## Stage contracts

| Stage | Objective, old → new owner and scope | Dependencies / PostgreSQL impact | Authority change | Required acceptance and rollback |
| --- | --- | --- | --- | --- |
| **GO-CP0** | Adopt target, module/role design, migration and security contracts. Laravel remains current owner. | This proposal; accepted ADR review; no DDL. | Operator adopts a successor ADR; no runtime transfer. | Independent architecture/security review confirms inventory, single-writer and receipt/reservation model. Record adoption explicitly through a later authorized packet. Rollback is withdrawal of proposal; historical evidence retained. |
| **GO-CP1** | PHP `ControlPlane` and infrastructure authority → isolated Go kernel + infrastructure authority stores. Lifecycle, generation, claims, reservations, receipts, audit/outbox and uncertainty. | CP0 design; existing 63 migration schema; real disposable PostgreSQL. Add only justified reservation/receipt SQL; no production migration. | Test databases only. No active dispatcher, production route or writer. | [First assignment](GO-CP1-canonical-kernel-assignment.md): all lifecycle/tenant/claim/cross-version/PG races/restart gates; independent review; schema equivalence and no blind replay. Remove isolated component to rollback; retain evidence, no live state affected. |
| **GO-CP2** | PHP identity/controllers/Reverb contracts → Go identity, HTTP middleware and realtime compatibility harness. Bring generic API/health/metrics and route contract registry; domain handlers arrive with owners. | CP1 audit/idempotency; bcrypt and Laravel encryption fixtures, session table proposal, role/catalog seed compatibility. | No live authentication or session transfer. | Browser login/logout/rotation/CSRF/tenant/capability, suspension, rate limiting and cross-tenant denial; same response/status/pagination per route; Echo protocol handshake/auth/subscription/event/reconnect tests; secret-envelope compatibility; session invalidation rehearsal. Rollback isolated Go; never accept two session authorities without an explicit future design. |
| **GO-CP3** | PHP registry, RuntimeEngine, provisioning, Kubernetes visibility/maintenance → Go registry/infrastructure; integrate existing Go components. | CP1–2; immutable configuration publication and image provenance; lifecycle adoption design; bounded Node-maintenance contract in addition to Deployment effects. | Tests and disposable infrastructure only. Five current PHP writers remain active in actual environment. | Full registry/capability/selection/drain/host parity; every Deployment corridor plus Node maintenance mapped; real API UID/RV/barrier/lifecycle/RBAC tests after relevant changes; observe-first restart, no live traffic, image/config publication checks. CP4 supplies domain callback dependencies before transfer. Rollback removes isolated roles, no credential transfer yet. |
| **GO-CP4** | PHP sessions/conferences/calls/routing/provider clients/listeners → Go telephony/signaling and Asterisk/FreeSWITCH adapters. | CP1–3, current provider contracts/epochs, destination/credential views, admission and capacity; no new SIP/RTP engine. | Isolated provider simulations; later separately authorized protocol proof. | Session/Call/CallLeg/binding/participant transaction parity, answer/termination monotonicity, duplicate/reordered events, wrong-node/epoch rejection; ARI/ESL wire and disconnect/recovery; exact supported capability matrix; signaling publication/view/grant parity; required scoped natural-live rehearsals only after authorization. Rollback isolated implementation; live listener transfer remains CP6. |
| **GO-CP5** | PHP recording/archive-target and remaining admin/read/retention responsibilities → Go recording + completed API handlers. | CP1–4 and current RMA-A–D authority. Reuse canonical capture/artifact IDs/encrypted refs; do not implement RMA-E–H incidentally. | Test-only ownership; complete route/worker/migration inventory reconciled. | Recording start/stop/store-preserving behavior, channel-less event correlation, exactly one artifact/event, tenant-safe archive credentials and audit; UI contract replay and browser automation across all implemented pages; scheduler/retention parity. Rollback isolated Go; no dropping existing storage/history. |
| **GO-CP6** | Controlled backend authority handover and Laravel retirement, including API, workers, listeners, migrations and five Deployment writers. | Independent CP1–5 acceptance; adopted ADR; baseline fixes; current-environment inventory and explicit new operator authorization. | Explicit credential/process/lease/routing transfer, never implicit shared-DB dual writes. | Rehearse fenced cutover on restored disposable data; prove old DML/DDL/Kubernetes/producer authority absent, resolve unknowns, preserve relevant V1/K5/RMA contracts with fresh authorized proof; failover/restart/rollback drills and acceptance by separate reviewer. Retire Laravel only after inventory closure, monitoring period and rollback expiry. |

Every implementation packet records starting/final Git identity, exact commands,
test counts/proof classification, changed files, scope, preserved artifacts,
security review and independent reviewer handoff. Developer-tested is not
independently accepted. No stage inherits a pass simply because the PHP oracle
passed in the past.

Run deterministic fixtures and the simulator oracle first. Provider protocol
tests follow those gates; any deployed or natural-live proof follows separate
authorization and never substitutes for the earlier contract regressions.

## Authority transfer runbook contract for CP6

1. Freeze operator mutation intake for the exact transfer cohort; drain web
   requests and scheduled/queued producers. Preserve reads as appropriate. Record
   DB transactions, pending operations, PHP queue retry payloads, event offsets,
   listener leases/epochs and all unknown dispatched Kubernetes/provider effects.
2. Stop old command/reconciliation/outbox/projector/scheduler/listener roles. A
   feature flag is not an authority cutoff. Quiesce existing network requests;
   stale lease expiration alone cannot drain an already-dispatched request.
3. Resolve unknown effects by authenticated exact observations and current policy.
   Establish barrier/retirement state as needed. If unresolved work cannot be
   safely bounded, defer transfer; do not enable a fallback unconditional writer.
4. Verify compatible forward schema and baseline fingerprint. Transfer migration
   DDL credentials exclusively. Preserve Laravel history; no automatic Down.
5. Revoke old PHP DML/operation-producer rights for the cohort, invalidate old
   connection pools/sessions as required, and prove effective denial using the
   old identity. A GRANT change alone does not roll back open transactions. Do
   not reuse a shared application login that keeps the old process authorized.
6. For Kubernetes, transfer **all five application Deployment corridors together**:
   provisioning, convergence, fencing, restoration, deprovisioning. Revoke old
   ServiceAccount/RBAC mutation rights and remove credentials/projections. Prove
   old credentials cannot create, patch/update, scale or delete actual scoped
   test resources; use authorization checks plus negative API attempts, not labels
   or flags alone. Separately inventory authorized platform rollout tools.
7. Define external-writer policy: no uncontrolled HPA/other replica writer on
   UTCP-managed runtime Deployments; resourceVersion can detect a collision but
   does not prevent a later external override. Node-maintenance mutation rights
   need their own explicit transfer. Namespace-scoped CREATE is not constrainable
   by `resourceNames`; require closed templates, scope and reviewed admission.
8. Start new Go workers with compatible claims, epochs and persisted work. Do not
   create new operation IDs to disguise pending work. Convert or drain the queued
   Asterisk binding retry explicitly; never deserialize arbitrary PHP jobs in Go.
9. Switch API/browser/realtime authority atomically for compatible cohorts;
   execute the selected forced reauthentication policy and close old subscriptions.
   Start one producer/consumer per source; a shared inbox key is not sufficient
   proof that two different event normalizers are safe simultaneously.
10. Observe fresh canonical and provider/infrastructure behavior and independently
    accept. Preserve current native-k3s environment authority when a later live
    task is approved; never silently substitute a disposable cluster for V1 proof.

## Rollback boundaries

Before a live transfer, discard isolated Go deployment/test state without altering
PHP. After live transfer, rollback is another fenced handover, not a reverse
feature flag: stop new admissions, quiesce requests, account for unresolved
external effects, stop new workers/listeners, revoke new authority, verify schema
compatibility and permit only a **conditional-compatible** previous release.
The present unrestricted PHP Kubernetes paths are not automatically a valid
rollback target. Keep expand-only schema/history and compatible API readers until
the rollback window closes. A durable provider action or issued credential may
need forward reconciliation; it is not undone by restoring a binary or database
snapshot. Never restore PostgreSQL independently of already-applied external
side effects and claim that the world has been rolled back.

## Separate baseline repairs and publication prerequisites

Before CP3 production-integration acceptance and CP6, repair the runtime-engine
checker with positive/negative workload-label regression tests; preserve accepted
K5 permissions. Before CP2/CP6 security acceptance, correct the secret scanner's
specific form-expression false positive using parser-aware classification or an
exact reviewed synthetic fixture, retaining literal-secret detection tests. Do
not broadly exempt Vue files, `secret` variables or Go migration documents.

Image/config publication is a CP3 dependency: authorized resolved image digest,
immutable/versioned Secret/ConfigMap references and rotation identity. Existing
v3's fixed ConfigMap reference may need a closed versioned extension. This is
future implementation, not evidence that active provider startup was proven by
zero-replica API tests.
