# C0 Evidence — Control-Plane Application Kernel

Date: 2026-07-14

## Scope

C0 implemented runtime-neutral application-kernel primitives only. No public runtime management API, simulator, SIP, WSS signaling, Reverb behavior, Kamailio, rtpengine, Asterisk, FreeSWITCH, conferences, telephony sessions, runtime-node management, or new Kubernetes process role was introduced.

## Implemented Kernel

- `App\ControlPlane\Shared`: opaque identifiers, execution context, payload safety, stable JSON, result names.
- `App\ControlPlane\RuntimeOperations`: state machine, failure classes, PostgreSQL operation repository, claims, leases, fencing.
- `App\ControlPlane\Messaging`: event envelope, transactional outbox, deduplicating inbox.
- `App\ControlPlane\Idempotency`: scoped idempotency records, replay, conflict, in-progress state.
- `App\ControlPlane\Audit`: append-only audit repository with metadata redaction.
- PostgreSQL migration for `runtime_operations`, outbox, inbox, idempotency, and audit tables.

## Local Proof Observed

`make control-plane-config-check`

- Required C0 files exist.
- Runtime-specific production vocabulary absent.
- Runtime-specific public routes absent.
- Future process roles absent from Kubernetes manifests.
- Migration indexes, safety checks, and locking primitives present.
- PHP syntax valid.

`make control-plane-test`

- 18 tests passed.
- 67 assertions passed.
- Covered state transitions, terminal immutability, failure classification, sensitive payload rejection, execution context, event envelopes, operation claiming, lease takeover, fencing-token rejection, completion plus outbox, retry scheduling, terminal failure, idempotency replay/conflict/in-progress state, outbox rollback, idempotent dispatch marking, inbox deduplication, payload mismatch conflict, audit redaction, and PostgreSQL append-only audit trigger.

`make control-plane-migrate-proof`

- Disposable PostgreSQL database created.
- Empty-database migration passed.
- Repeated migration reported nothing pending.
- Required tables existed.
- Required indexes existed:
  - `runtime_ops_claim_idx`
  - `runtime_ops_idempotency_unique`
  - `inbox_consumer_key_unique`
  - `idempotency_scope_key_unique`
  - `audit_subject_idx`
- PostgreSQL audit mutation rejection trigger existed:
  - `control_plane_audit_no_update`
- Proof ran with Redis host intentionally non-authoritative for migration.

`make control-plane-proof`

- Disposable PostgreSQL proof passed.
- 1 focused proof test passed.
- 14 assertions passed.
- Proved operation creation, idempotent duplicate replay, idempotent conflict rejection, one active lease, second-worker exclusion, expired lease takeover, old fencing-token rejection, current-owner completion, atomic operation/outbox commit, duplicate inbox handling, append-only audit rejection, and disposable proof database cleanup.

## Runtime Integration Observed

`make k8s-apply`

- Rebuilt and pushed the K1 API, web, and gateway images through the canonical lifecycle.
- Applied the normal Kubernetes migration lifecycle.
- The `utcp-migrate` Job completed successfully.
- The C0 migration `2026_07_14_090000_create_control_plane_kernel_tables` was observed as `DONE` in the migration Job logs.

`make k8s-proof`

- `/healthz` succeeded through the internal gateway.
- `/api/health/live` succeeded.
- `/api/health/ready` reported PostgreSQL and Redis healthy.
- `/api/version` succeeded.

`make gateway-proof`

- Standard-port HTTPS proof passed for `app.utcp.local.test`.
- HTTP redirected to HTTPS without a custom port.
- Reserved hostnames remained unrouted.

`make security-proof`

- K3 Pod Security Admission rejection proof passed.
- Required positive and negative NetworkPolicy connectivity checks passed.
- Gateway and application readiness remained healthy under K3 policy.

`make observability-proof`

- Metrics proof passed.
- Request-ID log ingestion proof passed.
- Grafana provisioning proof passed.
- Synthetic alert proof passed and cleaned up.

`make test`, `make check`, `make build`, and `make container-check`

- Repository unit/integration tests, quality checks, build, and container validation passed after the C0 API test image environment was made explicit.

## Hosted CI

Not observed for the uncommitted working tree.

## Sanitization

No private keys, decoded Secret values, database passwords, Redis credentials, Authorization headers, cookies, session tokens, SIP credentials, or payload bodies were recorded.
