# GO-CP1 E3 trusted recovery verifier

Run from the repository root:

```bash
tests/go-cp1-e3/verify
```

The default command creates a uniquely named PostgreSQL 17.6 container, private
Docker network and run-owned temporary volume, applies the real Laravel migration
history and reviewed additive Goose migrations, compares the protected catalog,
then executes focused E3 integration tests and the complete canonical Go module
with ordinary tests, race detection, vet and module verification. It runs the
independent unchanged PHP Asterisk oracle, PostgreSQL canonical regressions,
SQLite legacy policy regressions and the full `tests/go-cp1-e2/verify` chain
(including E1 and offline infrastructure verification). Go 1.25.8, PostgreSQL and
PHP images are pinned by immutable digest in the script.

For a focused development iteration, use `tests/go-cp1-e3/verify --focused`.
This still runs the module checks and PHP regressions, but omits the nested E2
chain and repository-wide checks. The E2 oracle retains its original required
E2 test marker through a per-process override; the actual E3 database, container,
network, volume and ownership labels retain the fresh E3 identity.

Outputs go to a private `/tmp/utcp-go-cp1-e3-output.*` directory (or an explicitly
supplied `UTCP_CP1_E3_OUTPUT` below `/tmp`). `cleanup.json` records removal of the
owned container, network and volume even on failure. No database port is
published. Temporary credentials are removed by the exit trap. No Kubernetes or
telephony target is accessed. Output files are local developer evidence and
must be inspected and sanitized before publication.

The E3 tests distinguish signed executor dispatch-journal evidence from signed
resource observations. Journal closure and API rejection are synthetic trusted
fixtures; these tests do not establish actual Kubernetes transport non-delivery.
Concurrent PostgreSQL connections prove duplicate evidence serialization,
canonical-change revalidation, competing recovery reservations and opposing
positive/negative evidence. Real child processes are killed at reservation,
consumption, synthetic effect, and receipt boundaries. Receipt precommit crashes
use an independently held PostgreSQL audit-table lock and observed server wait;
the child is killed before commit and a fresh child proves rollback and safe
recovery. Both observation receipt and dispatch-evidence commit windows are
covered. Ephemeral mTLS binds the actual certificate SAN to the reserved executor
in a test-only handler; no production callback route is registered.

An unchanged Deployment observation cannot prove nonapplication. Unknown
creation retains delayed-delivery risk and cleanup-only retirement. Observed
newer barriers contain future older conditional effects while preserving older
historical uncertainty. A finalized negative barrier frees its bounded recovery
slot only for fresh fully validated authority; an uncertain barrier cannot
recursively spawn recovery generations.
