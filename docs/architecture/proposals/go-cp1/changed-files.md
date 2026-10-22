# GO-CP1 changed-file inventory

Parent: [implementation report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md).
This packet adds isolated source, test-only additive SQL, synthetic contracts,
verification tooling and documentation. No pre-existing file is intentionally
modified. The final preservation check in [verification](verification.md) verifies
prior artifact hashes separately from this task-owned inventory.

No production application registration, deployed manifest, active runtime worker,
production database, accepted ADR or phase-ledger entry is changed. Existing PHP
writers remain active and unmodified. No commit or push is performed.

The final packet contains 39 task-owned files. The worktree has 63 untracked
files in total: the preserved 24-file baseline plus this packet, with no tracked
or staged modifications. All 1,578 pre-existing preservation hashes match.

The complete task-owned file inventory follows:

- `apps/control-plane/README.md`
- `apps/control-plane/cmd/utcp/main.go`
- `apps/control-plane/contracts/README.md`
- `apps/control-plane/contracts/dispatch-request.schema.json`
- `apps/control-plane/contracts/dispatch-response.schema.json`
- `apps/control-plane/contracts/fixtures.json`
- `apps/control-plane/contracts/observation-receipt.schema.json`
- `apps/control-plane/go.mod`
- `apps/control-plane/go.sum`
- `apps/control-plane/integration/README.md`
- `apps/control-plane/integration/kernel_postgres_test.go`
- `apps/control-plane/integration/transport_postgres_test.go`
- `apps/control-plane/internal/app/dispatch.go`
- `apps/control-plane/internal/infrastructure/envelope.go`
- `apps/control-plane/internal/infrastructure/json.go`
- `apps/control-plane/internal/infrastructure/kernel_receipt.go`
- `apps/control-plane/internal/infrastructure/receipt.go`
- `apps/control-plane/internal/infrastructure/receipt_test.go`
- `apps/control-plane/internal/infrastructure/schema_test.go`
- `apps/control-plane/internal/infrastructure/transport.go`
- `apps/control-plane/internal/infrastructure/transport_test.go`
- `apps/control-plane/internal/kernel/claims.go`
- `apps/control-plane/internal/kernel/dispatch.go`
- `apps/control-plane/internal/kernel/messaging.go`
- `apps/control-plane/internal/kernel/receipts.go`
- `apps/control-plane/internal/kernel/store.go`
- `apps/control-plane/internal/kernel/types.go`
- `apps/control-plane/internal/kernel/types_test.go`
- `apps/control-plane/internal/postgres/migrations.go`
- `apps/control-plane/internal/postgres/pool.go`
- `apps/control-plane/migrations/00001_dispatch_receipts.sql`
- `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md`
- `docs/architecture/proposals/go-cp1/changed-files.md`
- `docs/architecture/proposals/go-cp1/next-assignment.md`
- `docs/architecture/proposals/go-cp1/postgresql-adoption.md`
- `docs/architecture/proposals/go-cp1/verification.md`
- `tests/go-cp1/catalog.sql`
- `tests/go-cp1/oracle.php`
- `tests/go-cp1/verify`
