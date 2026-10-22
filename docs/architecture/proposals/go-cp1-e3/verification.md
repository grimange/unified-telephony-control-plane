# GO-CP1 E3 — reproducible developer verification

Parent: [E3 report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_EVIDENCE.md).
Run from the repository root:

```bash
tests/go-cp1-e3/verify
```

The full command runs the E3 PostgreSQL/protocol suite, then
`tests/go-cp1-e2/verify`, which in turn runs the E1/offline infrastructure packet.
`tests/go-cp1-e3/verify --focused` skips that inherited chain and repository checks
for iteration; a focused result is not complete regression evidence.

## Pinned and isolated environment

| Component | Immutable image |
| --- | --- |
| Go 1.25.8 | `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084` |
| PostgreSQL 17.6 | `postgres@sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94` |
| PHP verification | `sha256:9efe210fb8c8e7c90d80fce2b6916b46824f0f0428d6037dd599ec5ef1dd4d7d` |

Existing `pgx/v5` and Goose dependencies remain pinned. No host package is
installed. Each run refuses pre-existing identities, creates only its labeled
Docker network/container/temporary volume, uses ephemeral credentials and
publishes no PostgreSQL port. Kubernetes context and live database configuration
are never read. Cleanup removes and verifies only owned resources, writing a
sanitized `cleanup.json` under private `/tmp` output.

## Command sequence and proof limits

1. PHP syntax/Pint check the independent E2 oracle.
2. Apply current Laravel migrations, repeat for idempotency, require actual
   E1/E2 oracle artifacts and capture the real migration count.
3. Snapshot the protected Laravel PostgreSQL catalog, apply reviewed subordinate
   Goose SQL with `migrate-test`, compare the protected catalog unchanged.
4. Run in `apps/control-plane` with the pinned SDK:

   ```bash
   test -z "$(gofmt -l .)"
   go test -count=1 -v -timeout=240s ./integration -run E3
   go test -count=1 -v -timeout=240s ./...
   go test -race -count=1 -timeout=240s ./...
   go vet ./...
   go mod verify
   ```

5. Run actual PostgreSQL canonical lifecycle, operations, audit/outbox and
   idempotency PHPUnit suites. Run the unchanged legacy fencing, registry,
   draining, telephony, capacity and placement suites under their normal SQLite
   configuration. SQLite proves behavioral regressions only; PostgreSQL decision
   and concurrent-transaction tests provide database evidence.
6. Run full E2/E1/offline foundation regression entry points against current code.
7. Run hygiene, phase-status consistency and `git diff --check`; separately record
   the known runtime-engine checker and Vue secret-scanner failures.

A subprocess-helper skip in its parent invocation is expected only when an actual
child process executes the scenario. Record child execution and crash windows
separately. Direct `go test` without the private PostgreSQL DSN/oracle may skip
integration tests and is not PostgreSQL/parity evidence.

E3 dispatch evidence uses a synthetic trusted executor journal and signed fixture
Status categories. No real Kubernetes request is authorized or performed. Prior
real API evidence remains historical; this packet does not turn synthetic
non-delivery assertions into real transport guarantees.

## Scheduled concurrency and crash coverage

The source schedules the following real PostgreSQL cases; their final run results
are recorded in the parent report rather than inferred from this inventory.

| Case | Synchronization / durable assertion |
| --- | --- |
| Same negative evidence accepted concurrently | Independent transactions and start channel; exactly one stored receipt, audit/outbox transition |
| Same barrier observation accepted concurrently | Independent transactions; one exact receipt and unchanged predecessor history |
| Evidence versus supersession, retirement or attempt rollover | Independent writer holds changed row; server lock wait established before commit; acceptance revalidates and rejects |
| Negative evidence versus late positive effect | Audit-table lock pauses first transaction; both orderings tested; first terminal fact remains, contradictory evidence rejects |
| Two recovery reservations | Concurrent independently authenticated requests; one recovery grant, one denial |
| Failed barrier followed by fresh recovery | Finalized negative closes only the failed slot; older uncertainty remains; ordinary work denied and a fresh higher-generation recovery can qualify |
| Lost barrier response after supersession | Exact historical observation confirms the consumed barrier; no repeated grant; older intent stays unknown |
| Uncertain create observed absent, then found late | Absence cannot settle delivery or retire history; late exact UID is cleanup-only and cannot activate |
| Recovery versus newer generation or expired lease | Independent writer holds generation/lease change; competing reserve/consume blocks, then rejects after commit |
| Valid evidence versus forged evidence | Concurrent starts on one challenge; valid evidence commits once and altered signed identity rejects |
| Different-tenant recoveries | Cross-used challenges reject; legitimate receipts execute concurrently and preserve both predecessors |
| Wrong identity, expiry and replay | Permanent cryptographic/canonical negative fixtures plus inherited receipt/claim tests; not every fixture is a separate scheduled transaction race |

Eight new crash windows use actual killed Go subprocesses and fresh processes
against the same PostgreSQL database: after recovery reservation, consumption,
synthetic effect, and barrier confirmation; and before/after each of the negative
dispatch-evidence and positive recovery-observation commits. Precommit receipt
cases independently lock the audit table and observe PostgreSQL blocking before
killing the child. Restart asserts rollback or exact idempotent durable recovery,
not just a reconstructed in-memory object. Existing E1/E2 subprocess, lease,
retirement, UID, domain-policy and generation tests run again through the complete
chain. This is READ COMMITTED plus explicit canonical locks and validation, not a
claim that every database transaction uses SERIALIZABLE isolation.

## Troubleshooting and evidence handling

- Unavailable Docker/pinned images or failed disposable identity checks are
  environmental blockers; do not use host packages or a deployed database.
- Missing completed PHP oracle output fails the verifier even if a PHP console
  invocation returned success.
- An unchanged resource or missing Deployment is not confirmed nonapplication.
  Retain unresolved state until exact observation or justified terminal journal
  evidence arrives.
- Repeated consume returns status without a new grant. Repeated exact accepted
  evidence returns status; altered evidence on the consumed challenge rejects.
- A recovery slot occupied by an unknown recovery dispatch cannot be bypassed by
  another recovery barrier. Establish the exact barrier or a terminal negative
  journal fact; otherwise report the quiescence/evidence dependency.
- `/tmp` output and synthetic oracle envelopes can contain temporary test
  credentials. Do not commit them, raw logs, database dumps or environment files.
  Permanent evidence contains counts, command outcomes and sanitized assertions.

Additional repository checks:

```bash
make repository-hygiene phase-status-consistency-check
make runtime-engine-config-check
make secret-scan
bash -n tests/go-cp1-e3/verify
git diff --check
```

The final default command exited zero. Exact counts, expected skips, repeated
suite boundaries and verified cleanup of all four owned PostgreSQL environments
are recorded in the parent report. The result is developer evidence for
independent review; no Kubernetes mutation or telephony execution was performed.
