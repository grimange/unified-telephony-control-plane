# Runtime Engine Runbook

## Scope

C3 provides UTCP's runtime-neutral command, event, projection, and reconciliation engine. It does not implement a simulator, Asterisk adapter, FreeSWITCH adapter, ARI/ESL listener, SIP signaling, conference behavior, media behavior, or the V0 vertical slice.

## Authority

PostgreSQL is authoritative for runtime operations, outbox dispatch state, raw runtime-event receipts, connection epochs, observations, projection checkpoints, reconciliation state, leases, and fencing.

Redis is transient queue delivery only. A queue message wakes processing, but workers reload and mutate authoritative state through PostgreSQL.

## Process Roles

The backend image supports these C3 roles:

```text
control-plane-outbox-dispatcher
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
```

They run automatically in Compose and Kubernetes. Do not replace them with routine manual dispatch, projection, or reconciliation commands.

## Outbox Dispatch

The dispatcher claims pending or retryable outbox rows in stable order, writes a lease owner and token, delivers a transient queue signal, and marks dispatch through a fencing token. Stale workers cannot complete a message after lease takeover. Rows are retained for bounded audit and troubleshooting rather than deleted immediately.

## Command Worker

The command worker reloads a runtime operation from PostgreSQL, claims it through the C0 operation lease, resolves a `RuntimeOperationHandler`, and resolves a `RuntimeAdapter` only when required. Unsupported handlers or adapters fail observably. There is no production no-op or simulator fallback.

## Event Normalization

Future runtime-specific listeners will call internal services to record raw runtime-event receipts. The normalizer claims receipts, resolves a normalizer by adapter key and event type/version, applies normalized observations, advances projection checkpoints atomically, and records unsupported events without mutating projections.

## Reconciliation

Reconcilers compare desired state, observed state, configuration generation, and outstanding operations. Reconciliation state uses PostgreSQL leases and fencing so only one worker repairs a target generation. Results are `converged`, `waiting`, `operation_required`, `blocked`, or `unsupported`.

## Verification

Use:

```sh
make runtime-engine-config-check
make runtime-engine-test
make runtime-engine-proof
make runtime-engine-status
```

`runtime-engine-proof` uses disposable PostgreSQL state and test-only handlers, adapters, normalizers, and reconcilers. Proof fixtures are not production runtime behavior.

## Safety Boundaries

- No public command execution API.
- No public raw-event ingestion API.
- No manual projection or reconciliation route.
- No manual mark-ready route.
- No live runtime endpoint egress.
- No ARI, ESL, Asterisk, or FreeSWITCH process role.
- No simulator production implementation.

## Troubleshooting

- Unsupported handler: verify the operation type/version has a registered production handler in the phase that owns that operation.
- Unsupported adapter: expected until C4/T0/T4 add real adapters.
- Event remains unsupported: expected for unknown event type/version; it should remain visible without projection mutation.
- Reconciliation blocked: inspect safe summary fields with `make runtime-engine-status`; do not edit reconciliation rows manually.
