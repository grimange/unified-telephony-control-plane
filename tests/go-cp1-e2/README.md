# GO-CP1 E2 Asterisk domain policy developer verification

Run from the repository root:

```bash
tests/go-cp1-e2/verify
```

This pinned entry point creates a uniquely named PostgreSQL 17.6 container,
private Docker network, and new data volume. PostgreSQL has no published port.
It applies the existing Laravel migrations and the reviewed GO-CP1 additive SQL,
compares the protected catalog, generates independent decisions with the
unchanged Laravel `CanonicalInfrastructureService::verify`, and runs the Go
Asterisk policy tests against those persisted canonical fixtures.

The entry point runs focused E2 tests first, then the complete Go kernel test,
race, vet, formatting and module checks. It runs the relevant Laravel canonical,
fencing/restoration, RuntimeNode, telephony, capacity and placement tests, followed
by the existing complete `tests/go-cp1/verify` regression chain. Use `--focused`
to omit only the final inherited full regression chain. PHP syntax and Pint
checks cover the new oracle. The four canonical suites run against PostgreSQL;
the six additional legacy suites run in their normal PHPUnit SQLite environment.
An attempted PostgreSQL run of the legacy restart fixture fails because it
inserts a 36-character UUID into `event_sources.id CHAR(32)`; that unchanged
fixture portability issue is not hidden by modifying its source or schema.
The independent E2 oracle and Go concurrency tests use PostgreSQL throughout.
Bash syntax can be checked with
`bash -n tests/go-cp1-e2/verify`.

No Kubernetes API, Asterisk instance, SIP call or media session is contacted.
Synthetic observations and source-fence provenance are inserted only into the
new disposable database. Positive and negative oracle decisions are produced
by the actual Laravel service, independently of the Go decision function.
Laravel's verifier exposes only an authorized boolean; it does not expose a
policy-specific error code for comparison.

The PostgreSQL race tests synchronize real policy transactions and independent
ordinary SQL writers. They inspect PostgreSQL lock waits instead of relying on
sleep timing to claim serialization. The policy's coarse table guard operates
at READ COMMITTED; the tests do not claim that the whole database runs at
SERIALIZABLE or that the unchanged Laravel workers acquire these guards.
The restart tests terminate real child processes and reconstruct the kernel
against the same disposable database. They retain consumed and uncertain
history and never execute a real external effect.

The verifier writes private evidence into a newly created `/tmp` directory and
prints that directory after cleanup. `cleanup.json` records removal of the
run-owned containers, network and volume. Evidence can be retained in a chosen
private directory by setting `UTCP_CP1_E2_OUTPUT=/tmp/<unique-directory>`.
Raw oracle files and diagnostic logs contain temporary fixture lease tokens;
they are local test artifacts and must not be committed as evidence. Permanent
reports must use summarized counts and sanitized failure descriptions.

Developer verification is separate from independent acceptance. These tests do
not authorize a production callback, a Go writer, FreeSWITCH domain fencing or
restoration, or production authority transfer.
