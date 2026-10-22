# E4 changed-file inventory

Parent: [E4 report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL.md).

The E4 packet owns 32 paths: 28 new files and four intentional modifications
to existing untracked files. Existing runtime source, contracts, SQL,
dependencies, accepted ADRs and phase ledger are preserved. Starting identity:
`main`, `5e028b9ab3d96f3430bf715f89d66ab19a758761`, 94 individual untracked
files, zero tracked/staged changes. Final inventory is 122 untracked files and
zero tracked/staged changes. No file is staged, committed or pushed.

The initial SHA-256 manifest covers 1,648 Git-visible regular files; 1,644 remain
unchanged. All four intentional exceptions have private original-byte backups
whose hashes match that baseline. The initial manifest SHA-256 is
`7bcd9f3931f39a3f71b5b755b33a564a4d18d819df3b37135fa797e5314cd78e`.
Final handoff comparison is reported in the parent report.

## Implementation

- `apps/control-plane/internal/executor/grant.go` — closed signed synthetic grant,
  exact identity, bounded endpoint and independent body/envelope hashes.
- `apps/control-plane/internal/executor/journal.go` — private WAL/checkpoint,
  stable cross-process lock, state validation, irreversible closure and quarantine.
- `apps/control-plane/internal/executor/http.go` — one synthetic HTTPS submission
  after durable possible-send; correlated structured terminal classification.
- `apps/control-plane/internal/executor/evidence.go` — fresh challenged E3 signing
  from immutable durable journal facts.
- `apps/control-plane/internal/executor/journal_test.go` — authentication, storage,
  process arbitration, closure, synthetic transport and SIGKILL/restart regressions.
- `apps/control-plane/contracts/executor-grant-v1.schema.json` — explicitly new
  private grant version; existing dispatch/evidence/action schemas stay unchanged.
- `apps/control-plane/integration/e4_executor_postgres_test.go` — separate executor
  child, mTLS producer/consumer PostgreSQL integration and Docker storage probe.
- `apps/control-plane/integration/e4_schema_test.go` — closed grant-schema and
  positive/negative shape-fixture checks.
- `apps/control-plane/integration/e4_http_matrix_test.go` — public-API synthetic
  HTTP success/rejection/error classification matrix.

## Verification

- `tests/go-cp1-e4/verify` — pinned isolated wrapper, storage lifecycle checks and
  full E3/E2/E1 regression chain with reviewed optional test-budget overrides.
- `tests/go-cp1-e4/README.md` — command, fixture and storage-proof boundaries.
- `tests/go-cp1-e4/fixtures/positive-consumed.json`
- `tests/go-cp1-e4/fixtures/positive-reserved.json`
- `tests/go-cp1-e4/fixtures/negative-malformed-time.json`
- `tests/go-cp1-e4/fixtures/negative-missing-store.json`
- `tests/go-cp1-e4/fixtures/negative-negative-attempt.json`
- `tests/go-cp1-e4/fixtures/negative-null-uid.json`
- `tests/go-cp1-e4/fixtures/negative-numeric-generation.json`
- `tests/go-cp1-e4/fixtures/negative-unknown-action.json`
- `tests/go-cp1-e4/fixtures/negative-unknown-field.json`
- `tests/go-cp1-e4/fixtures/negative-unknown-phase.json`
- `tests/go-cp1-e4/fixtures/negative-unknown-version.json`
- `tests/go-cp1-e4/fixtures/negative-zero-generation.json`

## Intentional prior-artifact modifications

- `tests/go-cp1/verify`
- `tests/go-cp1-e2/verify`
- `tests/go-cp1-e3/verify`
- `apps/control-plane/integration/asterisk_policy_postgres_test.go`

Each verifier accepts only bounded `UTCP_CP1_GO_TIMEOUT` values (`240s`, `600s`, `900s`)
with its original 240-second default. E4 selects 900 seconds after measured full
race runs exceeded 240 seconds under durable-filesystem and subprocess load.
The verifiers also pass optional `GOFLAGS=-p=1`, selected by E4 to serialize
package scheduling while preserving all within-test concurrent transactions and
processes. Ordinary inherited defaults remain unchanged. The single existing
Asterisk race-test correction permits only the designed `ErrConflict` from a
contending retirement, retries it once after the consumption commit, and still
requires terminal retirement/replay denial. Runtime lock/timeouts, contracts and
production behavior are unchanged. Original bytes/hashes for all four files are
preserved privately for review. No other starting artifact is modified.

## Documentation

- `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL.md`
- `docs/architecture/proposals/go-cp1-e4/executor-state-machine.md`
- `docs/architecture/proposals/go-cp1-e4/verification.md`
- `docs/architecture/proposals/go-cp1-e4/changed-files.md`
- `docs/architecture/proposals/go-cp1-e4/next-assignment.md`

Temporary journals, certificates/private keys, database credentials, compiled
probes, logs and catalogs stay outside the repository. Disposable resources are
owned by unique verifier run identities; cleanup does not touch pre-existing
containers, volumes or networks. The Go cache is reusable tooling, not a proof
resource claimed to be destroyed.
