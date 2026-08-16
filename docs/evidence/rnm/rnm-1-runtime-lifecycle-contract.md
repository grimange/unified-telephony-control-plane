# RNM-1 Evidence: Runtime Lifecycle Contract and Honest Drain Semantics

## Scope

RNM-1 closes the bounded RuntimeNode lifecycle contract without implementing a
drain coordinator, `DRAINED`, decommission orchestration, or managed runtime
provisioning.

## Implemented contract

- `disabled → retired` is valid only with zero active canonical conference
  bindings.
- `retired` is terminal and remains a persisted historical RuntimeNode.
- Retired reconciliation targets and claimed listener leases are released; the
  generic command, listener, reconciliation, restoration, and placement paths
  exclude retired nodes.
- `draining` is a placement cordon: new conference placement and replacement
  selection use `active` only, while existing bindings remain on the draining
  node.
- Runtime family/adapter identity changes are limited to draft or disabled
  nodes with no active bindings.

## Executed verification

Repository context: branch `main`, phase marker `UTCP_PHASE=T1`. No Kubernetes,
cluster, registry, host-port, database, or external runtime mutation was used
for this packet.

Focused command:

```text
cd apps/api && php artisan test --filter='(RuntimeRegistryTest|draining_runtime_is_cordoned|listener_ordinary_eligibility|restore_authorized_listener_node)'
```

Outcome: PASS — 29 tests, 361 assertions.

The focused results prove disabled-to-retired, active-binding rejection,
terminal transitions, persisted history/audit, active-only placement with an
active alternative, deterministic no-eligible-runtime failure, existing
binding retention, draining listener eligibility, and retired listener
exclusion.

Additional executed verification:

- `make test`: PASS — 408 tests, 3,621 assertions, 6 skipped.
- `make check`: PASS — repository hygiene, runtime-registry configuration,
  affected runtime checks, T3/T4 static checks, backend formatting, frontend
  lint, and frontend typecheck all completed successfully.
- `git diff --check`: PASS.
- `cd apps/api && php artisan migrate --pretend --env=testing`: PASS — the
  forward `retired` constraint migration is included in the migration plan;
  the testing database uses the repository's non-PostgreSQL no-op branch.

The Asterisk-ARI standalone config script still has a pre-existing broad scan
that flags canonical Asterisk SIP/RTP configuration; it is not part of the
RNM-1 completion gate and was unchanged. The repository `make check` path
completed successfully.

## Deferred boundaries

RNM-2 will add authoritative active-work counting, progress, zero-work
detection, timeout/cancellation, and `DRAINED`. RNM-3 will add decommission
orchestration. RNP will add managed runtime provisioning through a future
deployment-neutral provisioner.
