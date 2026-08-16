# RNM-3 Evidence: Runtime Node Decommission Orchestration

## Scope

RNM-3 adds the planned post-drain decommission corridor. It does not provision
or physically destroy PBX infrastructure, implement capability projection, or
reuse failure fencing for planned lifecycle management.

## Implemented contract

- `POST /api/v1/admin/runtime-nodes/{id}/decommission` is the canonical
  operator action and requires the existing runtime-node management capability.
- Only a `drained` RuntimeNode with zero active `conference_runtime_bindings`
  can create a decommission operation. The generic desired-state endpoint
  cannot assert `retired` from `drained`.
- The existing durable runtime-operation kernel owns
  `runtime.node.decommission`; repeated requests reuse the pending operation.
  The handler is a control-plane operation and does not require a PBX adapter.
- Completion locks and revalidates the node, operation ownership, desired
  state, and active binding count. It retires every active UTCP-held runtime
  credential, removes remaining reconciliation/listener enrollment, preserves
  history, emits audit/outbox records, and system-completes `drained → retired`.
- A stale worker after `drained → active` is rejected and cannot retire the
  node or its credentials. Retirement remains terminal and excluded from
  placement, normal execution, reconciliation, listener claims, and restore.
- Failure fencing is not invoked. No current canonical planned-runtime
  shutdown abstraction exists, so externally managed infrastructure is not
  claimed to be physically destroyed; RNP/hosting lifecycle remains deferred.
- The Admin UI exposes Decommission only for drained nodes with one contextual
  confirmation and shows sanitized operation progress/failure. Secrets are not
  returned or rendered.

## Executed verification

Repository context: branch `main`, HEAD `943c965540c8647803074096e8f451eb5c01225d`.
The working tree contains pre-existing RNM-1, RNM-2, and V0 changes; no commit
or push was performed for RNM-3.

Focused backend command:

```text
cd apps/api && php artisan test --filter='decommission'
```

Outcome: PASS — 5 tests, 39 assertions. The tests cover the happy path,
state/precondition guards, idempotent request, reactivation race, terminal
retirement, and execution-time active-binding recheck.

Frontend commands:

```text
cd apps/web && npm run test
cd apps/web && npm run typecheck
cd apps/web && npm run lint
cd apps/web && npm run build
```

Outcome: PASS — 9 files and 140 tests; typecheck, lint, and production build
passed. Vite emitted only its existing large-chunk warning.

Repository commands:

```text
make test
```

Outcome: PASS — 417 API tests, 3,706 assertions, 6 skipped; 140 frontend
tests.

## Deferred boundaries

RNM-4 owns observed capability projection. RNM-5/RNM-6 own the remaining
natural lifecycle UI and browser/live lifecycle proof. RNP owns future managed
runtime provisioning and any supported physical hosting teardown.
