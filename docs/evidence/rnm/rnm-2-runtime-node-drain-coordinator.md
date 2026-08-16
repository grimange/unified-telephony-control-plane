# RNM-2 Evidence: Runtime Node Drain Coordinator and Completion Detection

## Scope

RNM-2 adds the system-proven `drained` lifecycle state and automatic drain
completion. It does not implement decommission orchestration, forced drain,
active-work migration, or managed runtime provisioning.

## Implemented contract

- `active → draining` is the operator-requested cordon transition.
- `draining → drained` is produced only by `RuntimeRegistryService::completeDrain`
  after a locked re-check finds zero active `conference_runtime_bindings` for
  the tenant and RuntimeNode.
- `drained → active` is the operator Reactivate transition; `retired` remains
  terminal.
- Existing active bindings remain attached while a node drains. New placement
  continues to use the RNM-1 active-only eligibility rule.
- `runtime_node_drains` durably records status, initial and remaining work,
  timestamps, deadline, timeout classification, and completion.
- The existing `runtime-engine:reconciler --once` scheduler evaluates
  `runtime_node_drain` targets automatically. The coordinator is safe to repeat;
  completion re-checks current desired state and current binding count, so a
  stale pass cannot overwrite cancellation.
- A deadline with remaining work records `timed_out` / `drain_deadline_exceeded`
  while leaving the node `draining`, cordoned, and its bindings intact. Natural
  later completion may still produce `drained` without erasing timeout history.
- Runtime evidence exposes drain state, remaining/initial work, timestamps,
  deadline, completion, timeout, and sanitized failure fields. The Admin UI
  shows remaining work, cancellation, timeout messaging, and Reactivate for
  `drained` nodes.

## Executed verification

Repository context: branch `main`, phase marker `UTCP_PHASE=T1`. No Kubernetes,
cluster, registry, host-port, or external runtime mutation was used.

Focused RNM-2 command:

```text
cd apps/api && php artisan test tests/Feature/RuntimeRegistry/RuntimeNodeDrainCoordinatorTest.php
```

Outcome: PASS — 4 tests, 46 assertions. The scenarios cover remaining work,
zero-work completion, idempotent completion, administrator rejection of direct
`drained`, cancellation against a stale coordinator pass, drained reactivation,
timeout visibility, preservation of active bindings, and timeout followed by
natural completion with timeout history retained.

The affected Runtime Registry suite also passed:

```text
cd apps/api && php artisan test tests/Feature/RuntimeRegistry/RuntimeRegistryTest.php
```

Outcome: PASS — 26 tests, 341 assertions.

Repository baseline after RNM-2 implementation:

```text
make test: PASS — 412 API tests, 3,667 assertions, 6 skipped; 140 frontend tests.
make check: PASS — local runtime cutoff, repository hygiene, and T3-S1 media checks.
apps/web npm run build: PASS — vue-tsc and Vite production build.
php artisan migrate --pretend --env=testing: PASS — both RNM-2 migrations render.
git diff --check: PASS.
```

## Deferred boundaries

RNM-3 owns decommission orchestration, including supported runtime shutdown,
credential retirement, projection removal, and final soft retirement. RNP
managed runtime provisioning remains a separate future capability.
