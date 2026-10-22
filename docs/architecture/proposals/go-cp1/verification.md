# GO-CP1 verification evidence

Parent: [implementation report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md).
Date: 2026-10-22. Classification: developer testing of isolated code and disposable
PostgreSQL. Independent acceptance, deployment and natural-live proof are absent.

## Confirmed environment

The PostgreSQL runner creates uniquely named run-owned container/network/volume,
uses ephemeral credentials in private temporary files, and publishes no database
port. It rejects pre-existing identities and verifies owned cleanup. Go and PHP
containers attach only to that explicit proof network. No kubeconfig or live
database target is read. Pinned identities are in the parent report and verifier.

Confirmed in the final disposable run:

| Check | Actual result |
| --- | --- |
| Pinned SDK compile | New module compiles with Go 1.25.8 |
| Laravel disposable baseline | 63 migrations applied successfully |
| Repeated Laravel migration | No pending migrations |
| Independent PHP export | Three v1/v2/v3 fingerprints and 21 claim decisions generated against PostgreSQL |
| Repository hygiene | Passed for the current packet |
| Phase-status consistency | Passed; no canonical phase/status change |

The complete `tests/go-cp1/verify` run exited `0`, including the new module,
focused PHP tests, prior canonical proof, infrastructure offline chain and owned
cleanup. Failed exploratory runs remain diagnostic history, not passing proof.

## Reproduction commands and final matrix

One complete entry point from the repository root:

```bash
tests/go-cp1/verify
```

The runner executes the following relevant commands inside pinned containers:

```bash
php artisan migrate --force
php artisan migrate --force
php /cp1/oracle.php
go run ./cmd/utcp migrate-test
test -z "$(gofmt -l .)"
go test -count=1 -v -timeout=240s ./...
go test -race -count=1 -timeout=240s ./...
go vet ./...
go mod verify
```

Protected catalog acquisition uses `tests/go-cp1/catalog.sql` before and after
additive SQL followed by `cmp`. The runner also executes focused PHPUnit tests,
`tests/gn-g2-canonical/verify postgres-only`,
`tests/gn-g2-active-projection/verify offline`, repository hygiene,
phase-status consistency and `git diff --check`.

| Gate | Final result / count | Proof class |
| --- | --- | --- |
| New Go unit/protocol tests | Ordinary run passed; 100 passing test nodes module-wide, zero failures, one helper skip | Unit / HTTP simulation |
| New PostgreSQL integration | 19/19 integration tests passed in ordinary run | Real disposable PostgreSQL |
| Concurrency and subprocess restart | Ordinary run passed, including three killed-process/restart barriers | Independent connections / process termination |
| Race detector | Passed for all packages; PostgreSQL integration 40.194 s | Developer race instrumentation |
| Vet / module integrity / formatting | Passed; all modules verified, no gofmt output | Static and dependency validation |
| Closed private schema fixtures | Passed in module suite | Structural schema validation |
| mTLS / signed receipt negatives | Passed in ordinary and race suites | Ephemeral credentials and HTTP simulation |
| PHP fingerprints / claim decisions | Three fingerprints and 21 decisions matched (three allowed, 18 denied) | Independent PHP/Go canonical subset |
| Focused PHP regressions | Passed: 30 tests, 117 assertions; PHP 8.4.15 / PHPUnit 11.5.56 | Disposable PostgreSQL |
| Previous canonical verifier | Passed: 41 PostgreSQL tests / 232 assertions; separate 43 SQLite tests / 447 assertions; two normal/race Go bridge tests with 25 named subtests | PostgreSQL / SQLite / HTTP-mTLS, distinguished below |
| Infrastructure offline chain | Passed: effects 170 passing nodes; observer 107; dedicated lifecycle 86; dedicated active 96; detailed PHP counts below | Existing Go/PHP simulated regressions |
| Protected Laravel catalog | Passed; byte-identical before/after, detailed below | Disposable catalog compatibility |
| Hygiene / phase consistency / docs / diff | Passed; targeted local links, new-file UTF-8/whitespace/content checks and `git diff --check` passed | Repository validation |
| Preservation hashes | Passed: 1,578 pre-existing files, zero mismatches; 39 new files, 63 total untracked, no tracked/staged modifications | Existing file integrity |
| Owned PostgreSQL cleanup | Passed: run-owned containers, volume and network removed; nested canonical cleanup also verified | Disposable resource lifecycle |

Integration tests explicitly skip without their disposable DSN or PHP oracle.
The verifier must supply both; an unexpected skip is a proof gap. The child-test
helper skips in its ordinary parent invocation and is executed explicitly by
subprocess scenarios; that helper skip is not a missing PostgreSQL scenario.
The ordinary run's 100 passing nodes comprise 31 top-level tests and 69 named
subtests; 19 top-level tests exercise actual PostgreSQL integration. Counting
subtests does not turn them into 100 independent top-level test functions.

### Existing regression runs

The previous canonical entry point ran these PHP groups independently:

| Group | Result | Database proof |
| --- | --- | --- |
| Canonical infrastructure | 19 tests / 80 assertions passed | PostgreSQL |
| Concurrency and restart proof | 11 tests / 115 assertions passed | PostgreSQL, independent connections/processes |
| Runtime operations, messaging and idempotency | 11 tests / 37 assertions passed | PostgreSQL |
| Unchanged runtime engine and identity | 43 tests / 447 assertions passed | SQLite; no PostgreSQL locking claim |

Its Go mTLS bridge passed two top-level tests and 25 named subtests in the normal
run, then passed race detection and module verification. Filtered active and
lifecycle packages reported no matching tests in this bridge-only command; the
dedicated suites below supply their proof.

The offline chain reran all relevant existing verifier entry points:

| Suite | Go ordinary-run count | PHP result | Other validation |
| --- | --- | --- | --- |
| Conditional effects | 170 passing nodes; two PostgreSQL-artifact skips covered by the prior canonical bridge | Existing chain fixtures | Race, vet, module integrity, formatting; 69-file static checks passed |
| Observer | 107 passing nodes, no skips | 59 tests / 368 assertions passed | Race, vet, module integrity, formatting; 67-file static checks passed |
| Dedicated lifecycle | 86 passing nodes, no skips | 20 tests / 209 assertions passed | Race, vet, module integrity, formatting; 26-file static checks passed |
| Dedicated active profiles | 96 passing nodes, no skips | 20 tests / 267 assertions passed | Race, vet, module integrity, formatting; 28-file static checks passed |

The earlier broad effects-module traversal also ran active/lifecycle packages
without their generated PHP artifacts: 81/69 passing nodes with three/two
conditional artifact skips respectively. Dedicated verifiers regenerated those
artifacts and reran the scenarios with zero skips. Counts overlap across entry
points; they are not added into one purported count of unique tests. All these
regressions are simulated/offline behavior, not new real Kubernetes evidence.

## Normalized catalog snapshot

The final run's protected catalog comparison passed. Its private output directory
is `/tmp/utcp-go-cp1-output.zBHskw`; this is a local diagnostic location, not
committed evidence. No oracle payload, credentials or raw catalog is copied here.
Both `catalog-before.json` and `catalog-after.json` contain 508,627 bytes and have
SHA-256:

```text
1fcfa3e6510f13bfd7c1786fb0b6e88bc585ea61cca0fbd700ccc934c3de5981
```

| Protected catalog projection | Count |
| --- | ---: |
| Relations represented by columns | 71 |
| Columns | 895 |
| Constraints | 324 |
| Indexes | 214 |
| Triggers / functions / views | 3 / 3 / 4 |
| Grant rows | 512 |
| Capability / role / role-capability seed identities | 38 / 3 / 44 |

These counts describe the preserved projection, with the reviewed new objects
excluded as specified in the [adoption note](postgresql-adoption.md). They do
not imply that the new tables and functions were absent or untested.

### Cleanup evidence

The final run identity was `utcp-go-cp1-20261022003954-853d7bb0f1`.
Its private `cleanup.json` records `containers_removed=true`,
`volume_removed=true`, and `network_removed=true`; the runner independently
inspected each owned identity after removal. The nested canonical verifier also
reported verified cleanup. No production, Kubernetes or pre-existing database
resource was used or removed. Temporary credential files were deleted rather
than retained as evidence.

## Coverage and limits

The permanent tests exercise exact BIGINTs, concurrent preparation/generations,
binding/retirement, immutable history, current/expired/superseded claims,
single consumption, lost response and worker restart, bounded recovery-barrier
containment, signed receipt replay/identity/effect guards, audit rollback and
existing messaging records. Counts above come from the executed ordinary run,
not an inventory of test source.

The mTLS suite uses ephemeral in-memory keys and certificates. It verifies
authentication, certificate/trust rejection, rotation, request validation,
cancellation/backpressure and safe errors. It is not a deployed transport.

No real Kubernetes mutation test was run in GO-CP1. Earlier GN-G2 real API
evidence remains inherited proof of its specific conditional predicates, not
proof that this new canonical protocol has been deployed or connected to it.
No telephony engine, SIP/media, browser, production or natural-live test is
authorized by this packet.

## Development findings and permanent corrections

Initial disposable PostgreSQL runs exposed a new preparation-query defect:
reusing one parameter for UUID identity and text projection produced SQLSTATE
`42P08`. Explicit parameter typing removes that ambiguity. A related UUID/text
coalescing mismatch denied the independent oracle's positive claims; matching
the persisted identity as text repairs the comparison. The oracle requires a
positive and negative decision for every v1/v2/v3 version, so an all-denied
implementation cannot count as parity. The final new-module rerun passed those
independent decisions and the repaired PostgreSQL paths.

A later run passed the protected catalog comparison and all 21 independent
PHP claim decisions, but exposed the same UUID/text parameter ambiguity in the
new test fixture's operation insert. Separating those fixture parameters fixes
the setup error; it does not turn that failed run into passing lifecycle proof.
One disposable startup also exceeded its initial readiness budget. Owned cleanup
completed, and the runner now uses bounded readiness attempts with startup
diagnostics. The final complete run remains the acceptance evidence.

Two subsequent test expectations were corrected without weakening implementation
guards: a successful status-only consume response is distinguished from
`Granted=true`, and reservation expiry is exercised through a short valid
deadline and PostgreSQL time rather than rewriting immutable evidence. The
database correctly rejected the attempted test mutation of `expires_at`.

Review also exposed retirement recovery that could preserve safety while leaving
consumed non-create work permanently unaccounted for. The bounded correction
supports exact-UID higher-generation retirement cleanup, accepts complete absence
only against its immutable predecessor set, and records historical recovery
evidence without restoring lifecycle or dispatch authority. Unknown CREATE is
excluded. Tests cover both ordinary unknown work and a recovery barrier that
becomes uncertain before retirement.

Exact-byte accepted receipt retries now acknowledge recorded status without
duplicating transitions or events. New or altered bytes cannot reuse a consumed
challenge. Signing/trust/observed-effect regressions remain separate from this
status-only idempotency behavior.

## Independent baseline failures

| Command | Known signature | Classification |
| --- | --- | --- |
| `make runtime-engine-config-check` | Exit 2: `ordinary platform workers must not carry the Kubernetes API client label`, affecting accepted K5 API/scheduler/telephony-reconciler labels | Pre-existing baseline validation defect |
| `make secret-scan` | Exit 2: `apps/web/src/views/ExternalTrunkDetailView.vue:292`, a credential-form assignment | Pre-existing scanner finding; no new literal credential claimed |

Both findings were freshly reproduced and remain visible. Neither checker was
suppressed or incidentally repaired. The targeted scan of all 39 new files found
no new credential/private-key content; it does not replace the failing repository
scanner or erase its existing finding.
