# GO-CP1 E3 — task-owned file inventory and preservation

Parent: [E3 report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_EVIDENCE.md).
Starting branch `main`, HEAD `5e028b9ab3d96f3430bf715f89d66ab19a758761`.
Starting tree: 75 untracked files, no tracked/staged modifications. The starting
SHA-256 inventory covers 1,629 Git-visible regular project files.

The frozen implementation inventory contains 19 new files and two intentional
changes to prior files. The preservation comparison is 1,627 identical starting
files, two intentional changes and zero missing files. Git retains 94 untracked
files (75 prior plus 19 additions), with no tracked or staged changes. The final
source/contract scan reports zero task-owned secret-pattern findings, broken
local Markdown links or trailing-whitespace errors. The complete verifier and
all four owned PostgreSQL cleanup checks pass.

## New implementation and contract files

- `apps/control-plane/internal/kernel/dispatch_evidence.go` — challenged exact
  dispatch evidence and transactional negative resolution.
- `apps/control-plane/internal/kernel/dispatch_evidence_test.go` — exact structured
  rejection whitelist and conservative unknown categories.
- `apps/control-plane/internal/infrastructure/dispatch_evidence.go` — closed
  signed payload, strict parser and scoped cryptographic verifier.
- `apps/control-plane/internal/infrastructure/dispatch_evidence_test.go` —
  signature, shape, identity, freshness and category regressions.
- `apps/control-plane/contracts/dispatch-evidence.schema.json` — separate closed
  `utcp.dispatch-evidence.v1` schema.
- `apps/control-plane/contracts/dispatch-evidence-fixtures.json` — synthetic
  positive/negative structural fixtures.
- `apps/control-plane/migrations/00002_dispatch_evidence.sql` — subordinate
  immutable accepted evidence and single-use challenges.

## New integration and verification files

- `apps/control-plane/integration/e3_evidence_postgres_test.go` — real PostgreSQL
  signed negative evidence, transactional races, replay and audit/outbox tests.
- `apps/control-plane/integration/e3_recovery_postgres_test.go` — lost barrier
  confirmation, delayed create, bounded recovery and killed-worker reconstruction.
- `apps/control-plane/integration/e3_crash_postgres_test.go` — actual process kill
  before/after negative-evidence and observation transaction commits.
- `apps/control-plane/integration/e3_races_postgres_test.go` — synchronized canonical
  invalidation, competing recovery reservations and terminal-evidence ordering.
- `apps/control-plane/integration/e3_transport_postgres_test.go` — ephemeral mTLS
  executor correlation and malformed, oversized and cancelled input rejection.
- `tests/go-cp1-e3/verify` — pinned isolated PostgreSQL and inherited regression
  command; no Kubernetes execution.
- `tests/go-cp1-e3/README.md` — verifier safety and proof boundaries.

## New documentation

- `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_EVIDENCE.md`
- `docs/architecture/proposals/go-cp1-e3/recovery-decision-matrix.md`
- `docs/architecture/proposals/go-cp1-e3/verification.md`
- `docs/architecture/proposals/go-cp1-e3/changed-files.md`
- `docs/architecture/proposals/go-cp1-e3/next-assignment.md`

## Intentional changes to prior files

`apps/control-plane/contracts/README.md` — document the separate private evidence
protocol, exact trust contract, current canonical checks and remaining liveness
boundary. Historical E1/E2 reports and the module README retain their meaning. No old receipt or dispatch schema,
infrastructure V1/V2/V3 semantics, production PHP, production migration, manifest,
accepted ADR, canonical ledger or dependency lockfile is intentionally changed.

`tests/go-cp1/catalog.sql` — extend the existing exact subordinate-relation
exclusions with the two new evidence tables in columns, constraints, indexes,
triggers and grants. Protected Laravel tables, functions, views and seed catalogs
remain compared without weakening their assertions; E3 integration tests verify
the new subordinate records. No new function exclusion is added.

No staging, commit, push, reset or clean is performed. Private test credentials,
synthetic envelopes, raw logs and generated binaries stay outside the repository.
