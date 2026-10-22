# GO-CP1 E4 — reproducible developer verification

Parent: [E4 report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL.md).
Run from the repository root:

```bash
tests/go-cp1-e4/verify
```

`--focused` runs journal/process/container checks and the focused E3 PostgreSQL
packet. It skips E3's inherited E2/E1/offline infrastructure chain; it is iteration
evidence, not the complete E4 regression gate.

## Pinned environment and command flow

| Component | Immutable image |
| --- | --- |
| Go 1.25.8 | `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084` |
| PostgreSQL 17.6 | `postgres@sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94` |
| PHP verification | `sha256:9efe210fb8c8e7c90d80fce2b6916b46824f0f0428d6037dd599ec5ef1dd4d7d` |

The new executor adds no dependency or SQL migration. Existing `pgx/v5`, Goose,
Laravel history and subordinate E1/E3 migrations remain unchanged. The wrapper
uses a read-only repository mount, current UID, a private temporary journal and
no network attachment for local process/container probes. It does not mount a
Kubernetes kubeconfig or use live service configuration.

1. Check the 12 closed-schema fixtures and 17-case public HTTP matrix, then
   check formatting and run executor tests, race detector, vet and module verify
   in the pinned SDK:

   ```bash
   test -z "$(gofmt -l internal/executor integration/e4_*.go)"
   go test -count=1 -v -timeout=240s ./internal/executor
   go test -race -count=1 -timeout=240s ./internal/executor
   go vet ./internal/executor
   go mod verify
   ```

2. Compile the integration test probe and exercise initial container execution,
   same-container restart, replacement container with retained bind-mounted
   storage, corrupted WAL and missing journal. The container probes reconstruct
   closed state; possible-submission recovery is separately SIGKILL-tested.
   Require explicit fail-closed outcomes for damage/loss, not new initialization.
3. Invoke `tests/go-cp1-e3/verify` with `UTCP_CP1_GO_TIMEOUT=900s`. It creates a
   uniquely owned disposable PostgreSQL environment, applies actual Laravel migrations, compares the
   protected catalog after existing subordinate SQL, runs E3-focused and whole
   Go module normal/race/vet/module checks, E4's process-to-PostgreSQL scenarios,
   PHP canonical/domain suites and the E2/E1/offline infrastructure chain.
   The three inherited E1/E2/E3 entry points retain a default of `240s` and
   accept only `240s`, `600s` or `900s`. This optional override was added after
   a measured 240-second full-race timeout. E4 defaults to `900s` for the nested
   complete suite; its own focused executor gate retains `240s`. Test-helper
   readiness is bounded by 90 seconds and the parent test deadline. The E4
   PostgreSQL child has a 90-second overall fixture context and 30-second
   HTTP/client bounds so journal synchronization does not consume a short shared
   deadline. Runtime submission/authorization limits and grant expiry remain
   unchanged; timeout still preserves uncertainty. E4 selects `GOFLAGS=-p=1`
   to serialize package scheduling under measured shared-filesystem load; the
   inherited wrappers pass that optional setting with ordinary defaults unchanged.
   All within-test PostgreSQL/process races retain actual concurrent execution.
4. Record existing baseline findings separately; run hygiene, phase consistency,
   document links, schema/fixtures, supplementary new-Go-source secret checks,
   shell syntax and `git diff --check`.
5. Verify each owned PostgreSQL environment's cleanup record, E4 storage cleanup
   and absence of owned containers. Keep private raw output under `/tmp`; permanent
   evidence contains only commands, counts and sanitized assertions.

## Proof classification

| Evidence | Scope |
| --- | --- |
| Executor unit and scheduled process tests | Real local filesystem commits, independent processes, synthetic authenticated HTTP and SIGKILL/reopen. |
| Container storage probe | Docker process restart and container replacement reconstruct closed state with the same mounted files; damage/loss denial. Not Pod rescheduling or physical node/storage failure. |
| E4 canonical producer integration | Separate executor child with allowlisted environment/configuration, mTLS, actual journal signing and real PostgreSQL E3 acceptance. No Kubernetes request. |
| E3/E2/E1 | Fresh developer regressions against current source, including PostgreSQL concurrency/restart and independent PHP Asterisk policy decisions. |
| Offline Kubernetes components | Client/contract regressions only. Historical real API and natural-live telephony evidence is not rerun or expanded. |

Four producer-to-consumer outcomes are exercised: final reserved pre-dispatch
rejection, consumed definitive non-delivery, supported terminal API rejection and
lost-response ambiguity. Expected HTTP counts are respectively zero, zero, one
and one. Fresh executor processes reopen the same store before signing. The
ambiguous case refuses negative evidence. Supported signed evidence is accepted
idempotently by E3 with exactly one evidence/audit/outbox transition and no second
consume grant.

The executor's actual SIGKILL/reopen cases are:

| Group | Deterministic child boundary names |
| --- | --- |
| Storage, send and closure | `before_wal_write`, `before_wal_sync`, `after_wal_sync`, `before_head_replace`, `after_commit`, `after_possible`, `before_send`, `before_closure`, `after_closure` |
| HTTP result and signing | `after_response`, `before_result`, `after_result`, `before_signature`, `after_signature` |

Those are 14 distinct executor process-kill windows, not separate process-kill
coverage of all 16 boundaries listed in the assignment. Receipt/result commit
crashes remain the inherited E3 subprocess proof; active-exchange response loss
also has controlled transport-failure coverage. The complete run freshly
passed both suites before the final report claimed preservation.

A helper skipped in its ordinary parent invocation is expected only where a
child actually executes it. Direct integration `go test` without the
private database/oracle environment may skip tests and is not PostgreSQL proof.

## Recorded completion

The fifth complete `tests/go-cp1-e4/verify` invocation exited 0. The final source
freeze matched all 26 code/schema/fixture/script hashes. E4's local gate passed
13 top-level and 36 nested journal tests, with one expected subprocess-helper
skip, normal 39.124 seconds and race 90.288 seconds. The separate schema/HTTP
gate passed two top-level and 29 nested cases: twelve schema fixtures and
seventeen HTTP classifications. All five retained-storage/container probes passed.

The fresh E3 whole-module invocation passed 82 top-level and 370 nested tests;
eight embedded child-helper PASS log lines are excluded. Six ordinary helper
entry points are skipped and executed by their parents. Whole-module race, vet
and module verification passed. Independent PHP/Go comparison matched all 75
Asterisk policy decisions, three fingerprints and 21 canonical claims. Nested
E2 repeated the same whole-module counts; E1 passed 81 top-level and 295 nested
checks, with six helper skips and the E2-only oracle skip already covered in
E3/E2. The dedicated observer/effects/Lifecycle/active suites also passed.

All five final cleanup records reported actual resource removal: E4 storage,
E3 PostgreSQL, E2 PostgreSQL, E1 PostgreSQL and prior Laravel-canonical PostgreSQL.
Independent Docker queries found no remaining run-owned containers, volumes or
networks. Run-private storage/configuration/credential directories were absent.
The read-only caches remain reusable tooling. The report records four earlier
failed complete attempts, their test-fixture/budget corrections and the first
historically incorrect cleanup-summary flag; none are recast as successful runs.

See the [result table](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL.md#developer-verification-results)
for exact per-invocation counts, pass times, expected skips, baseline failures and
proof boundaries. Repeated nested invocations are not a unique-test aggregate.

## Troubleshooting

- A missing/incorrect store ID, head/WAL mismatch or unsafe file is a fail-closed
  storage error. Preserve it. Do not reinitialize, truncate or automatically
  replay consumed grants.
- `possible` means delivery may have happened; a timeout, process death or absent
  effect does not authorize negative evidence or another send.
- Closed reserved grants are rejection-only. A consumed grant is a distinct
  authenticated handoff; changing phase in place is an identity conflict.
- An API error is definitive only if its exact structured status, authenticated
  endpoint and dispatch/body hashes qualify. Redirect/intermediary/timeout/5xx
  responses do not qualify.
- Expired/revoked or mismatched transport/signing identities are denials. Do not
  disable TLS checks or alter issuer policy to repair a fixture.
- Docker, pinned-image or disposable-target failures are environmental blockers;
  do not substitute a deployed database, host package or Kubernetes cluster.

Additional checks:

```bash
make repository-hygiene phase-status-consistency-check
make runtime-engine-config-check
make secret-scan
bash -n tests/go-cp1-e4/verify
git diff --check
```

The runtime-engine checker and Vue credential-form scanner signatures are known
independent baseline failures. They remain visible and unchanged, not suppressed.
Developer verification establishes a reviewable isolated implementation; it is
not independent acceptance, production readiness or permission for cutover.
