# UI-D21 — Reconciliation No-Op Event Suppression

Verdict: `UI_D_RECONCILIATION_NOOP_EVENT_FIX_COMPLETE`

Starting commit: `9b199ae` — `docs(ui): prove live runtime reconciliations`
Phase marker: `UTCP_PHASE=T1` (unchanged). UI-D remains **In Progress**.

Related evidence:

* [`docs/evidence/ui/ui-d20-runtime-reconciliation-live-proof.md`](ui-d20-runtime-reconciliation-live-proof.md)
* [`docs/evidence/ui/ui-d19-runtime-reconciliation-live-implementation.md`](ui-d19-runtime-reconciliation-live-implementation.md)

This repository correction fixes the UI-D20 live blocker by stopping
`ReconciliationRepository` from producing `runtime_reconciliation.*` outbox rows for
polls, lease claims, and unchanged reconciliation results.

No frontend, Reverb, Kubernetes, gateway, migration, dependency, retry, repair, replay,
or manual reconciliation path was added or changed.

---

## Live Event-Storm Measurement

UI-D20 measured an idle Runtime Reconciliation route while 460 reconciliation states existed:

```text
approximately 7 reconciliation events/second while idle
98.4% of sampled events contain no state change
110 canonical list rereads in 30 seconds
outbox production exceeds dispatcher drain
pending outbox backlog grows continuously
```

The browser was obeying the established contract: one canonical list reread per accepted
metadata-only notification. The defect was therefore upstream in producer volume, not in the
shared Echo client, route lifecycle, reconnect behavior, or HTTP reread logic.

## Root Cause

The exact producer seams were:

```text
apps/api/app/RuntimeEngine/Reconciliation/ReconciliationRepository.php
  ReconciliationRepository::claimDue()
  ReconciliationRepository::markResult()
```

Previous behavior:

```text
claimDue()
→ appended runtime_reconciliation.reconciliation_started
  for every claimed row on every poll

markResult()
→ appended an event for every result
→ repeated waiting no-op mapped to runtime_reconciliation.retry_scheduled
```

Neither path compared the prior canonical reconciliation state with the resulting state.

## Canonical Transition Fingerprint

Runtime Reconciliation notifications now represent persisted operator-visible state
transitions. The minimum fingerprint is:

```text
status
desired_generation
observed_generation
last_operation_id
blocked_reason
```

When all fingerprint fields are unchanged, no `runtime_reconciliation.*` outbox row is
appended. When one or more fingerprint fields change, exactly one reconciliation aggregate
event is appended in the same transaction as the state change.

Lease and worker-coordination fields are intentionally excluded:

```text
lease_owner
lease_token
lease_expires_at
claim timestamp
poll timestamp
worker identity
attempt_count
last_checked_at
next_check_at
updated_at-only touches
```

## Claim Versus Domain State Boundary

`claimDue()` now only claims work for execution coordination. A routine claim updates lease
fields and attempt count, but it does not change reconciliation `status` and does not append a
browser-visible event.

The current data model does not persist a durable operator-visible "started" state. For that
reason, `runtime_reconciliation.reconciliation_started` is no longer produced by `claimDue()`.
The event name remains part of the broader established lifecycle vocabulary, but this slice does
not fabricate a started transition merely to preserve historical event volume.

## Corrected markResult Behavior

`markResult()` now:

1. Loads and locks the reconciliation row through the existing transaction and lease-fencing
   path.
2. Captures the prior transition fingerprint.
3. Applies the canonical result.
4. Captures the resulting transition fingerprint.
5. Appends a reconciliation outbox event only when the fingerprints differ.
6. Commits the state change and event atomically.

Stale or fenced workers still return `false` and append no event.

## Real-Transition Mapping

Event names are derived from the prior and resulting persisted fingerprint:

| Transition | Event |
| --- | --- |
| Creation | `runtime_reconciliation.created` |
| Desired-generation advance or wake into drift/waiting | `runtime_reconciliation.drift_detected` |
| Resulting status `converged` | `runtime_reconciliation.converged` |
| New linked Runtime Operation or status `operation_required` | `runtime_reconciliation.operation_required` |
| Resulting status `blocked` / `unsupported` or materially changed blocked reason | `runtime_reconciliation.failed` |
| Real transition into `waiting` / `retry_scheduled` retry state | `runtime_reconciliation.retry_scheduled` |

Repeated equal waiting, failure, retry, convergence, and operation-required results append zero
additional events.

## No-Op Regression Coverage

Focused producer-backed tests cover:

```text
stable reconciliation + 10 no-op claim/result cycles
→ 0 new runtime_reconciliation outbox rows

claim-only lease changes
→ 0 reconciliation events

20 stable rows × 5 no-op polling cycles
→ 0 new runtime_reconciliation outbox rows
```

Additional transition tests prove one event for real convergence, drift, new linked operation,
failure or blocked-reason change, and retry-state transition, with repeated identical results
remaining silent.

## PostgreSQL Coverage

The PostgreSQL control-plane target includes the no-op invariant:

```text
claim/result transaction behavior
fingerprint comparison
zero outbox rows for unchanged state
one outbox row for real convergence
stale lease fencing remains event-silent
rolled-back creation writes no state or outbox row
```

This is executed through the real `ReconciliationRepository` and PostgreSQL migrations, not
through synthetic outbox rows or SQL-string inspection.

## Browser Contract Preservation

The existing browser notification contract is unchanged:

```text
channel:
tenant.{tenantId}.runtime-reconciliations

browser event:
runtime-reconciliation.operational-state.changed

envelope:
event_type
aggregate_type
aggregate_id
runtime_reconciliation_id
tenant_id
occurred_at
runtime_node_id when available
runtime_operation_id when available
```

No status, generation, drift, failure, desired-state, observed-state, audit, outbox, credential,
endpoint, provider-response, or command payload was added. The browser remains notification-only
and must reread canonical HTTP snapshots.

## Historical Outbox Boundary

This repository task does not delete, rewrite, or manually mark historical outbox rows.

Existing deployed backlog is preserved. After deployment of this correction, no new no-op rows
should be produced. The existing backlog should drain through the canonical dispatcher before the
next live proof measures idle quiescence and request discipline.

## Verification

Repository verification for this evidence must include:

```text
cd apps/api
php artisan test \
  tests/Feature/RuntimeEngine/RuntimeEngineTest.php \
  tests/Feature/ControlPlane/RuntimeReconciliationRealtimeBroadcastTest.php \
  tests/Feature/ControlPlane/RuntimeReconciliationReadApiTest.php

cd ../..
make control-plane-test

cd apps/api
php artisan test \
  tests/Feature/ControlPlane/RuntimeOperationRealtimeBroadcastTest.php \
  tests/Feature/RuntimeRegistry/RuntimeNodeRealtimeBroadcastTest.php \
  tests/Feature/TelephonyDomain/ConferenceRealtimeBroadcastTest.php

php artisan test
vendor/bin/pint --test

cd apps/web
npm run typecheck
npm run lint
npm run test
npm run build

make runtime-engine-config-check
make repository-hygiene
make workflow-check
make secret-scan
make test
make check
make build
git diff --check
git diff --cached --check
```

## Deferred Corrected Live Proof

The remaining proof gap is a focused natural Playwright MCP Runtime Reconciliation live proof
after deployment. It must wait for the existing outbox backlog to drain, then prove idle
quiescence, real-transition notifications, selected-detail rereads, normal-operation request
discipline, reconnect, route leave, tenant isolation, logout, storage, and responsive behavior.
