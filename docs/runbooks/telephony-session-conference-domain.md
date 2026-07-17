# Telephony Session and Conference Domain Runbook

## Scope

C5 introduces UTCP's application-domain authority for tenant-scoped telephony sessions, conferences, conference participants, explicit runtime-node binding, and automatic runtime-neutral reconciliation. It does not implement SIP registration, SIP credentials, WebSocket signaling, browser media, calls, dialing, trunks, recording, Asterisk ARI, FreeSWITCH ESL, Kamailio, rtpengine, or the V0 browser-to-conference workflow.

A `TelephonySession` means an authenticated user's tenant-scoped control-plane authorization session for telephony features. It does not mean SIP registration succeeded, a media path exists, a call exists, browser microphone access exists, or a runtime channel exists.

## Authority

PostgreSQL is authoritative for:

- `telephony_sessions`
- `conferences`
- `conference_runtime_bindings`
- `conference_participants`
- desired state, runtime binding, lifecycle timestamps, admission reason, and termination reason

C3 raw receipts and normalized observations are evidence of runtime behavior. They are not authority for desired application state. Redis may wake workers or deliver queues, but it must not own sessions, conferences, participants, admission, runtime binding, or reconciliation decisions.

## Lifecycle

Telephony sessions use a bounded lifecycle:

```text
pending
active
ending
ended
expired
failed
```

The current implementation creates `active` sessions, ends them deterministically, and expires due active sessions through the scheduler command. One active session per user and tenant is enforced by a PostgreSQL partial unique index and application services.

Conferences use desired state:

```text
draft
open
draining
closed
```

Observed conference state is projection-owned:

```text
unobserved
provisioning
ready
degraded
unavailable
closed
```

Participants use desired state:

```text
admitted
removed
```

Observed participant state is projection-owned:

```text
unobserved
joining
joined
leaving
left
failed
```

Controllers and application services must not write runtime-observed state except initial `unobserved` defaults at aggregate creation time. Runtime evidence must enter through C3 raw receipts, normalizers, projectors, and checkpoints.

## APIs

Authenticated tenant routes:

```text
POST /api/v1/telephony/sessions
GET  /api/v1/telephony/sessions/current
POST /api/v1/telephony/sessions/{telephonySession}/end

GET    /api/v1/conferences
GET    /api/v1/conferences/{conference}
POST   /api/v1/conferences/{conference}/participants/self
DELETE /api/v1/conferences/{conference}/participants/self
```

Authenticated administrative routes:

```text
GET   /api/v1/admin/conferences
POST  /api/v1/admin/conferences
GET   /api/v1/admin/conferences/{conference}
PATCH /api/v1/admin/conferences/{conference}
POST  /api/v1/admin/conferences/{conference}/desired-state
POST  /api/v1/admin/conferences/{conference}/runtime-binding
GET   /api/v1/admin/conferences/{conference}/participants
POST  /api/v1/admin/conferences/{conference}/participants/{participant}/remove
```

There is no anonymous join route, raw runtime command route, manual projection route, manual reconciliation route, SIP credential route, or media-token route.

## Runtime Operations

C5 adds runtime-neutral operation types:

```text
conference.ensure
conference.close
conference.participant.ensure
conference.participant.remove
```

Operation payloads contain canonical IDs, expected configuration generation, runtime-node ID, and required runtime capability. They do not contain passwords, cookies, SIP credentials, media information, dial strings, bridge IDs, or vendor identifiers.

The deterministic simulator implements those operations as a leaf adapter. It mutates only persisted simulator runtime state, schedules simulator raw events, and relies on the existing C3 event-source, receipt, normalizer, projection, checkpoint, and reconciliation path.

## Verification

Focused and live checks:

```sh
make telephony-domain-config-check
make telephony-domain-test
make telephony-domain-api-proof
make telephony-domain-runtime-proof
make telephony-domain-status
```

`telephony-domain-api-proof` uses the normal C1 CSRF/session lifecycle against the deployed HTTPS API. It creates or reuses the deterministic simulator RuntimeNode, creates the proof conference, creates the proof member session, proves member administrative denial, proves session and admission idempotency, and does not directly invoke runtime operations.

`telephony-domain-runtime-proof` runs against the canonical `utcp-local` Kubernetes runtime. It proves `open -> ready`, `admitted -> joined`, `removed -> left`, draining admission rejection, `closed -> closed`, session expiry removal, command-worker restart recovery, simulator-event-source restart recovery, raw receipt ingestion, normalization, projection, checkpoint advancement, and reconciliation convergence through the deployed C3/C4 workers.

Cross-tenant conference access and stale epoch/old-generation projection rejection are covered by focused PostgreSQL-backed tests because the live cluster intentionally has no public event injection route or direct C5 fixture path.

## Current Deployment Status

After the later T0 closure proof, the current authoritative phase marker became:

```text
UTCP_PHASE=T0
```

`make telephony-domain-status` reports safe aggregate data only:

```text
sessions by status
conferences by desired and observed state
participants by desired and observed state
conference and participant reconciliation states
expired-session count
terminal failures by normalized class
oldest pending operation age
```

It must not print user names, emails, tenant names, aggregate IDs, operation IDs, event IDs, raw payloads, simulator internal state, credentials, or fencing tokens.
