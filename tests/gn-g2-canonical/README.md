# GN-G2 canonical PostgreSQL proof

Run from the repository root:

```bash
tests/gn-g2-canonical/verify
```

The verifier uses the immutable PHP and PostgreSQL images named in
`scripts/control-plane/canonical-proof`; it does not install host packages. It
creates a uniquely named Docker bridge network, PostgreSQL container and data
volume. PostgreSQL publishes no host port; only the test PHP callback publishes an
ephemeral loopback port. All database connections explicitly select the disposable
`utcp_canonical_proof` database. Random database credentials exist only in a
private temporary environment file and are deleted during teardown. The verifier
never reads the application's local `.env` or a production connection string.

Each worker is a fresh PHP application process with a separate PostgreSQL backend.
The coordinator holds the RuntimeNode row lock, releases workers through local
barriers, and confirms PostgreSQL lock contention before allowing transactions to
complete. Generation, preparation, UID-binding and retirement races therefore
exercise real concurrent transactions rather than SQLite or sequential mock calls.
The restart test kills a worker after its uncertain outcome commits and uses a
new application process to recover the durable record.

The PHP bridge is test-only. A run-owned container publishes it on an ephemeral
loopback port. The Go integration test places an ephemeral mTLS frontend before
that bridge and invokes the real database-backed Laravel verifier repeatedly.
Go receives the loopback callback address and sanitized synthetic action fixtures;
it receives no database credentials. No production HTTP route is registered.

Evidence is written under a newly allocated `/tmp/utcp-gn-canonical-output.*`
directory, or a caller-selected private `/tmp` directory supplied through
`UTCP_GN_G2_CANONICAL_OUTPUT`. Exported action envelopes contain ephemeral **test**
lease tokens and must remain local. Commit only sanitized results and counts,
never `canonical-proof.json`, environment files, certificates or kubeconfigs.
`cleanup.json` records successful removal of this run's containers, network and
volume. No pre-existing Docker resources are deleted.

The proof covers disposable PostgreSQL integration, concurrent transactions,
process restart and an HTTP/mTLS integration simulation. It establishes no
production database migration, Kubernetes mutation, deployed acceptance or
natural-live telephony proof. Kubernetes tests are unnecessary when only the
Laravel foundation changes and existing infrastructure contracts remain intact.

The existing `RuntimeEngineTest` and `IdentityAuthorizationTest` suites run
separately against their established in-memory SQLite environment. The unsupported
adapter fixture deliberately writes `unknown-test-adapter`, rejected by the
existing PostgreSQL adapter constraint. The identity fixture compares a JSON audit
column to a scalar through a SQLite-specific assertion, unsupported by PostgreSQL.
Both baseline failures were observed and retained in local evidence. The verifier
does not weaken constraints or edit existing tests. Focused canonical, operation
repository, audit/outbox and idempotency suites, plus independent concurrency and
restart proofs, run against PostgreSQL. SQLite results do not establish canonical
locking or restart durability.

Use `tests/gn-g2-canonical/verify postgres-only` to rerun the disposable database
and live callback proof without repeating the unchanged Go offline suites. The
default command additionally runs the complete existing offline Go foundation
verifier and repository hygiene/phase-status checks.
