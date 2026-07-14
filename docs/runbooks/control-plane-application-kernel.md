# Control-Plane Application Kernel Runbook

Phase C0 adds the runtime-neutral application kernel used by later control-plane, runtime, signaling, media, and reconciliation phases. It does not expose a new public management API and does not deploy new Kubernetes process roles.

## Scope

C0 owns:

- Modular-monolith kernel boundaries under `App\ControlPlane`.
- Runtime-operation lifecycle and state transitions.
- PostgreSQL-backed worker claiming, leases, and fencing.
- Transactional outbox.
- Deduplicating inbox.
- Idempotency records.
- Append-only audit records.
- Execution context, correlation, causation, and request metadata.
- Runtime-neutral event envelopes and failure classifications.

C0 does not implement runtime-node management, provider-node management, simulator behavior, authentication, tenancy administration, SIP, WSS signaling, Reverb behavior, Kamailio, rtpengine, Asterisk, FreeSWITCH, conferences, telephony sessions, or browser-agent behavior.

## Modules

Current kernel modules:

```text
App\ControlPlane\Shared
App\ControlPlane\RuntimeOperations
App\ControlPlane\Messaging
App\ControlPlane\Idempotency
App\ControlPlane\Audit
```

Domain code must not depend on HTTP controllers, Kubernetes, ARI, ESL, SIP, or PBX libraries. Infrastructure implementations may use Laravel and PostgreSQL.

## Database Authority

PostgreSQL is authoritative for:

- `runtime_operations`
- `control_plane_outbox_messages`
- `control_plane_inbox_messages`
- `control_plane_idempotency_records`
- `control_plane_audit_records`

Redis may later deliver queued work, but Redis is not the operation-owner, outbox, inbox, idempotency, or audit authority.

## Runtime Operation Lifecycle

Supported statuses:

```text
pending
leased
running
retry_scheduled
succeeded
terminal_failed
cancelled
expired
```

Transitions are enforced by `OperationStateMachine`. Terminal states do not return to pending. Retryable failures schedule a bounded retry; terminal failures complete the operation as failed.

## Lease and Fencing

`RuntimeOperationRepository::claimAvailable()` uses PostgreSQL row locking and stable ordering by priority, availability, and creation time. Every successful claim receives a fresh fencing token. An expired or superseded token cannot move or finalize the operation.

## Outbox and Inbox

Outbox messages are written in the same transaction as operation completion. Rolled-back transactions leave no outbox record. Inbox uniqueness is scoped by consumer and message key; identical duplicates are safe, while same-key payload mismatch is a conflict.

## Idempotency

Idempotency is scoped by operation boundary and key. The request fingerprint is deterministic and excludes unstable metadata. Reusing the same key with the same fingerprint replays the original state/result. Reusing it with a different fingerprint fails as a conflict.

## Audit

Audit records are append-only through normal application services. On PostgreSQL, update and delete are rejected by a trigger. Metadata is structured and sensitive keys are redacted before persistence. Audit is not an event store.

## Proof Commands

```sh
make control-plane-config-check
make control-plane-test
make control-plane-migrate-proof
make control-plane-proof
make control-plane-status
```

The test and proof commands use a disposable PostgreSQL database through the existing Compose PostgreSQL service. They do not write proof records to the normal application database.

## Kubernetes Integration

The existing K1 migration lifecycle applies the C0 schema. C0 does not add Deployments, Jobs, Services, HTTPRoutes, NetworkPolicies, or public routes beyond the existing platform.

Future roles are reserved but not deployed:

```text
telephony-command-worker
telephony-event-normalizer
telephony-reconciler
asterisk-ari-events
freeswitch-esl-events
```

## Troubleshooting

- If `control-plane-test` cannot build the test image, check Docker Buildx availability and permissions.
- If a proof fails on locking or append-only audit, verify the proof is running against PostgreSQL, not SQLite.
- If `control-plane-status` reports missing C0 tables, apply the normal Kubernetes application migration lifecycle with `make k8s-apply`. Compose may be used only through disposable proof targets or explicit debug mode.
- Do not use `migrate:fresh` against the normal local database for C0 proof. The proof scripts create disposable databases.
