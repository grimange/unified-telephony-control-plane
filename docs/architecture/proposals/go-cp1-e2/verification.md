# GO-CP1 E2 — reproducible developer verification

Parent: [implementation report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md).
Run from the repository root:

```bash
tests/go-cp1-e2/verify
```

Use `tests/go-cp1-e2/verify --focused` during iteration to omit the inherited
full `tests/go-cp1/verify` chain. The complete command is the acceptance packet;
a focused pass alone is insufficient for a complete E2 regression claim.

## Pinned environment and safety

| Component | Verified pin used by the entry point |
| --- | --- |
| Go SDK | Go 1.25.8, `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084` |
| PostgreSQL | 17.6, `postgres@sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94` |
| PHP test image | `sha256:9efe210fb8c8e7c90d80fce2b6916b46824f0f0428d6037dd599ec5ef1dd4d7d` |
| PostgreSQL client | Existing pinned `pgx/v5`; dependencies remain unchanged |

The script refuses pre-existing run identities, creates a unique private Docker
network, PostgreSQL container and volume, publishes no database port, and uses
new temporary credentials. It does not read a developer database URL or a
Kubernetes context. Cleanup removes only run-owned objects and verifies their
absence in `cleanup.json`. No Kubernetes or telephony operation is performed.

## Test sequence

1. PHP syntax and Pint check the new independent oracle.
2. Apply the existing Laravel migrations to the new database; repeat migration
   confirms idempotency. Capture the actual migration count rather than assume it.
3. Run unchanged Laravel fingerprint/claim fixtures, then the E2 oracle invoking
   the actual `CanonicalInfrastructureService::verify` on persisted synthetic
   Asterisk state. Require the oracle output artifact before continuing.
4. Capture the protected catalog; apply the existing reviewed GO-CP1 additive
   Goose SQL and compare the protected catalog. E2 introduces no schema change.
5. Inside `apps/control-plane`, run:

   ```bash
   test -z "$(gofmt -l .)"
   go test -count=1 -v -timeout=240s ./integration -run E2Asterisk
   go test -count=1 -v -timeout=240s ./...
   go test -race -count=1 -timeout=240s ./...
   go vet ./...
   go mod verify
   ```

6. Run canonical lifecycle/operation/audit regressions on PostgreSQL. Run the
   unchanged legacy fencing-adapter, RuntimeNode, draining, telephony, capacity and
   placement feature suites with their normal SQLite configuration. These suites
   provide behavioral regressions, not PostgreSQL concurrency proof; the actual
   canonical decision oracle and Go concurrency tests use PostgreSQL.
7. Run `tests/go-cp1/verify`, including existing kernel and offline infrastructure
   verification, then repository hygiene, phase consistency and `git diff --check`.

The PHP suite commands run inside the pinned image under `apps/api`; the entry
point supplies a private PostgreSQL connection for the first and the repository's
normal SQLite PHPUnit configuration for the second:

```bash
vendor/bin/phpunit --do-not-cache-result --stop-on-error --stop-on-failure \
  tests/Feature/ControlPlane/CanonicalInfrastructureTest.php \
  tests/Feature/ControlPlane/RuntimeOperationRepositoryTest.php \
  tests/Feature/ControlPlane/MessagingAndAuditTest.php \
  tests/Feature/ControlPlane/IdempotencyStoreTest.php

vendor/bin/phpunit --do-not-cache-result --stop-on-error --stop-on-failure \
  tests/Feature/Infrastructure/KubernetesRuntimeFenceAdapterTest.php \
  tests/Feature/RuntimeRegistry/RuntimeRegistryTest.php \
  tests/Feature/RuntimeRegistry/RuntimeNodeDrainCoordinatorTest.php \
  tests/Feature/TelephonyDomain/TelephonyDomainTest.php \
  tests/Unit/RuntimeRegistry/RuntimeNodeCapacityEvaluatorTest.php \
  tests/Unit/RuntimeRegistry/RuntimeNodeFailureDomainEvaluatorTest.php
```

`TestE2AsteriskRecoveryWorker` is a subprocess-only helper and skips in its parent
invocation; real child processes execute it during the killed-worker tests.
The ordinary E1 subprocess helper has the same distinction. Report actual
pass/subtest counts and helper skips separately. No skipped helper is presented
as missing restart execution.

The nested `tests/go-cp1/verify` run does not supply `UTCP_CP1_E2_ORACLE`, so its
E2 independent-parity test can skip there. The outer E2 run supplies that artifact
and must execute the actual PHP/Go comparison. Record this nested skip separately;
an outer parity skip is a missing acceptance gate, not a successful comparison.

## Requested concurrency scenarios and exact coverage

| Requested scenario | Proof mechanism / boundary |
| --- | --- |
| Fence versus new active CallLeg | Independent SQL insert blocks on table guard; next verification rejects exhausted replacement capacity |
| Fence versus conference binding change | Replacement binding insert and former binding retirement block; changed state subsequently denies |
| Fence versus desired-state change | Replacement desired-state mutation is guarded; restoration disabled-state fixtures cover target desired state. Fencing has no independent former desired-state predicate |
| Fence versus capacity exhaustion | New CallLeg/binding occupies the last eligible replacement slot |
| Fence versus replacement availability | Replacement readiness/capability/placement mutations block, then deny |
| Claim versus supersession | Independent uncommitted supersession blocks consume, then denial follows commit |
| Reserve versus policy change | Changed eligibility before reserve rejects; reserve does not cache prior verification |
| Consume versus retirement | Real paused policy transaction and competing retire serialize; only the pre-retirement consumed grant exists, later requests cannot obtain a grant |
| Restoration versus new fence | Competing fence insertion and source-status change invalidate authorization |
| Restoration versus configuration | Independent configuration mutation serializes; fresh consume rejects |
| Restoration versus incarnation replacement | Terminal old identity retained; new physical name/UID cannot authorize old request |
| Concurrent restoration attempts | Concurrent consumers of one reservation yield exactly one new grant; status-only duplicate grants nothing |
| Lease expiry versus claim | Expired lease oracle and consume rejection; no TTL-based invention of dispatch failure |
| Attempt rollover versus reservation | Changed/uncommitted attempt invalidates exact old consume envelope |
| Worker restart with pending/uncertain work | Actual subprocesses killed after committed reserve, consume and unknown transitions, for both fence and restore; fresh process rejects changed policy and cannot grant consumed work again |

This is PostgreSQL READ COMMITTED with explicit predicate/table and row locks,
not a claim that the whole database uses SERIALIZABLE. Synchronization inspects
actual PostgreSQL lock waits and committed subprocess barriers. The coarse guard
serializes ordinary writes across tenants and has a two-second lock timeout;
production contention and throughput are not measured by these correctness tests.
A grant consumed before retirement may remain in flight afterward. E2 does not
make the PostgreSQL state change atomic with a later external effect.

## Results and proof classification

Execution results are recorded in the [report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md).
Fixtures use synthetic persisted readiness, placement and source-fence evidence.
These are independent PHP/Go and PostgreSQL concurrency proofs, not fresh
Kubernetes observation, telephony execution, deployment or natural-live proof.
The existing mTLS/signature suites verify synthetic transport and receipt
boundaries; E2 does not create a production callback route.

## Troubleshooting and evidence handling

- Missing immutable SDK/PHP/PG image or denied Docker access is an environmental
  blocker; do not substitute a host toolchain or live database.
- A PHP console exception may exit successfully. The verifier requires a completed
  oracle artifact, and tests compare decisions rather than trusting console exit.
- A denied changed-policy request must not consume a reserved dispatch. An already
  consumed request may return status with `Granted=false`; this is the existing
  one-time consumption contract.
- SQLSTATE `55P03` maps to bounded `ErrConflict`, not domain authorization. Policy
  denial maps to `ErrDenied`. Laravel exposes only an authorized boolean, so there
  is no PHP policy-specific error code to compare.
- Private `/tmp` artifacts can contain temporary lease tokens or request envelopes.
  Do not copy raw logs, oracle files, environment files or database dumps into
  permanent documentation. Summarize counts, assertions and cleanup only.

Run baseline checks without suppressing their known findings:

```bash
make runtime-engine-config-check
make secret-scan
make repository-hygiene phase-status-consistency-check
git diff --check
bash -n tests/go-cp1-e2/verify
```

The first two have independent baseline failures documented in the report.
