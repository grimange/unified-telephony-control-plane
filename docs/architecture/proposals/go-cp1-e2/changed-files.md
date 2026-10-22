# GO-CP1 E2 — complete task-owned file inventory

Parent: [implementation report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md).
Starting branch `main`, HEAD `5e028b9ab3d96f3430bf715f89d66ab19a758761`.
The starting tree contained 63 untracked files and no tracked/staged changes.
The preservation inventory hashed 1,617 pre-existing regular project files.

## Intentional change to a pre-existing artifact

- `apps/control-plane/internal/kernel/store.go` — invoke optional policy predicate
  guard before canonical row locks; normalize bounded lock timeout to conflict.
  This file was already untracked at task start.

- `apps/control-plane/README.md` — replace the historical unported-policy note
  with current explicit installation, proof links and contention boundaries.
  This file was already untracked at task start.

## New implementation and tests

- `apps/control-plane/internal/kernel/asterisk_policy.go` — explicit SQL-backed
  Asterisk fencing/restoration eligibility and predicate guard.
- `apps/control-plane/internal/kernel/asterisk_policy_test.go` — safe, lossless
  provenance scalar and replica validation regressions.
- `apps/control-plane/integration/asterisk_policy_postgres_test.go` — independent
  PHP parity, guarded PostgreSQL races, reserve/consume and audit assertions.
- `apps/control-plane/integration/asterisk_policy_recovery_postgres_test.go` —
  killed-worker recovery, attempt/supersession and replacement identity tests.
- `tests/go-cp1-e2/oracle.php` — actual Laravel verifier fixture construction and
  independent positive/negative decisions; no production PHP rule change.
- `tests/go-cp1-e2/verify` — pinned disposable PostgreSQL and regression entry point.
- `tests/go-cp1-e2/README.md` — executable verifier instructions and proof boundaries.

## New documentation

- `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md`
- `docs/architecture/proposals/go-cp1-e2/gate-matrix.md`
- `docs/architecture/proposals/go-cp1-e2/verification.md`
- `docs/architecture/proposals/go-cp1-e2/changed-files.md`
- `docs/architecture/proposals/go-cp1-e2/next-assignment.md`

No production PHP source, schema/migration, contract fixture, dependency lockfile,
Kubernetes manifest, existing infrastructure executor, accepted ADR or canonical
ledger is task-modified. Historical reports retain their original meaning.
The final comparison found 1,615 unchanged baseline files, exactly the two listed
intentional changes, and no missing file. Twelve new E2 files bring the current
untracked inventory to 75. All 14 task-owned files passed a targeted scan using
the existing secret patterns and a trailing-whitespace check. No staging, commit
or push is performed.
