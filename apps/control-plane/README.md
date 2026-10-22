# Isolated Go canonical kernel — GO-CP1

This module implements the first Go-native canonical-kernel slice against UTCP's
existing PostgreSQL schema. It is developer-tested migration code, not an active
backend, production API, Kubernetes executor, or independent acceptance result.
Laravel remains the deployed behavioral reference and writer. The Go-native ADR
remains **PROPOSED / AWAITING OPERATOR ADOPTION**.

The [GO-CP1 report](../../docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md)
records the exact verification result and limitations. The
[schema adoption note](../../docs/architecture/proposals/go-cp1/postgresql-adoption.md)
defines the disposable baseline and future DDL-ownership boundary.

## Implemented responsibilities

| Package | Responsibility |
| --- | --- |
| `internal/postgres` | Explicit bounded pgxpool construction; test-only additive Goose migration helper |
| `internal/kernel` | Existing incarnation/intent/operation authority, claims, generations, dispatch evidence, challenged receipt acceptance, C0 messaging compatibility |
| `internal/infrastructure` | Existing v1/v2/v3 envelope validation, closed private schemas, Ed25519 receipt verification and testable mTLS transport |
| `internal/app` | Compose the authenticated dispatch transport with the kernel; no listener or external execution |
| `cmd/utcp` | Guarded `migrate-test` command only; no API, worker or executor startup |
| `integration` | Real disposable PostgreSQL, independent PHP oracle, mTLS composition and subprocess restart tests |

The canonical kernel imports no Kubernetes client. Existing infrastructure
modules are reused by the boundary validator; their dependencies do not grant
credentials or activate execution. The future infrastructure-I/O role remains
separate and must receive no PostgreSQL credentials.

## Reproduce verification

From the repository root, with Docker available and the pinned images present:

```bash
tests/go-cp1/verify
```

This creates only a uniquely named PostgreSQL container, network and temporary
volume; applies the current Laravel migrations; exports the PHP behavioral
oracle; compares the protected catalog before and after additive SQL; then runs
Go formatting, tests, race detector, vet, module integrity, focused PHP tests and
the existing offline foundation suites. Cleanup targets only the current run.
No Kubernetes cluster is created or contacted. Private output stays under `/tmp`;
it includes synthetic test credentials and is not repository evidence.

For a focused developer loop:

```bash
tests/go-cp1/verify --kernel-only
```

That option skips the older foundation regression entry points and repository
checks. It is not the complete acceptance command. Missing PostgreSQL/oracle
environment skips integration tests in a direct `go test`; such a run is not
PostgreSQL or PHP/Go proof. See [integration requirements](integration/README.md).

Pinned execution uses Go 1.25.8, pgx/v5 5.7.6, Goose 3.24.3, PostgreSQL 17.6 and
the repository's PHP test image. Exact immutable image identities and observed
results belong to the report. Dependencies remain pinned in `go.mod`/`go.sum`.

## Authority and recovery

Lifecycle and generations reuse `infrastructure_incarnations`,
`infrastructure_intents` and `runtime_operations`. New dispatch/challenge/receipt
rows are subordinate evidence, not a parallel operation engine. All canonical
changes use PostgreSQL transactions, consistent row locks and database time.
No transaction includes an HTTP or Kubernetes call.

`Reserve` verifies one exact current attempt. `Consume` durably changes it to
dispatching before returning one grant. A duplicate consume returns status and
does not grant another dispatch, including after a lost response. This prevents
duplicate grants; it does not provide exactly-once external delivery. Consumed
work without a trustworthy result requires observation. Claim expiry cannot
retract an already dispatched request.

Binding, effect confirmation and terminal absence require a purpose-specific
challenged signed receipt. mTLS authenticates the current peer; Ed25519 binds
durable evidence to its issuer. Neither a signature nor this synthetic suite
proves the issuer observed Kubernetes correctly. DELETE acceptance, a matching
name, failed LIST, or timeout is not absence proof.

The explicit `kernel.NewAsteriskDomainPolicy()` implementation ports the current
Laravel canonical fencing/restoration predicates. Install it with
`SetDomainPolicy` before using a service instance. The default E1 service remains
fail-closed for those operations; no production composition is changed. The
injected policy is trusted code, not a caller-supplied authorization grant.
[The E2 report](../../docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md)
and [E2 verification instructions](../../docs/architecture/proposals/go-cp1-e2/verification.md)
record independent positive/negative PHP parity and PostgreSQL proof status.
Run `tests/go-cp1-e2/verify` for that complete regression packet.

The isolated Asterisk policy takes a coarse seven-table predicate guard before
row locks, with a two-second lock timeout. It serializes relevant writes across
tenants; production contention and throughput remain unmeasured. It preserves
Laravel's current readiness/placement semantics, including the absence of a TTL
in those policy predicates. Authorization does not prove runtime completion.
FreeSWITCH domain fencing/restoration remains unsupported. This module does not
claim complete Laravel backend or telephony parity.

No automatic resourceVersion refresh, mutation replay, fallback PHP writer,
production migration, listener, deployment or writer cutover is provided.
