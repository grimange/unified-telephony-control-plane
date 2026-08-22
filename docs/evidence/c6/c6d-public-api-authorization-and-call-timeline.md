# C6D — Public API, Authorization, and Call Timeline

Status: `IMPLEMENTED / TESTED` (2026-08-16)

## Scope

C6D adds the tenant-scoped public read and control surface for the canonical
`calls` and `call_legs` resources. It does not add a frontend, runtime adapter,
C7 resource, or persistence table.

The routes are:

- `POST /api/v1/calls`
- `GET /api/v1/calls`
- `GET /api/v1/calls/{call}`
- `GET /api/v1/calls/{call}/legs`
- `POST /api/v1/calls/{call}/operations`
- `GET /api/v1/calls/{call}/operations`
- `GET /api/v1/calls/{call}/timeline`

Controllers authorize the active tenant and delegate writes to
`CallDomainService`/`runtime_operations`. They do not assign canonical state,
insert observations, or execute adapters. Inbound Calls remain created only by
the C6C observation-adoption path and are read/control targets after adoption.

## Authorization and isolation

C6D uses the existing identity-session and tenant-membership boundary with the
six contract permissions: `telephony.calls.view`, `view_own`, `originate`,
`control`, `record`, and `manage`. Operation permission is selected from the
normalized operation catalog before RuntimeOperation creation; runtime
capability remains a separate CommandWorker gate.

All Call, CallLeg, RuntimeOperation, and timeline queries are tenant-scoped.
ConferenceParticipant resources are not projected as generic Calls, and a
TelephonySession is not a public runtime target.

## Derived timeline

The timeline is a bounded, read-only projection over canonical runtime
operations, normalized runtime observations, and existing audit records. It
uses explicit resource mapping, safe normalized observation metadata, and
deterministic descending ordering by occurrence time, source precedence, and
stable source identity. Pagination is bounded by the existing API page size;
the candidate window is capped at 1,000 records per source query. No
`call_timeline_entries` table or timeline write path exists.

The audit record is the canonical Call-created history source, preventing a
duplicate synthetic Call-created event when the same mutation is already
represented in audit history. Timeline output is not replayed into canonical
state and does not expose raw provider payloads, secrets, leases, or adapter
objects.

## Evidence

Focused API tests cover tenant isolation, authenticated operation submission,
idempotent RuntimeOperation reuse, permission separation, normalized resources,
timeline derivation, and the absence of a timeline table. C6A, C6B, and C6C
regressions remain in the focused suite.

No Asterisk, FreeSWITCH, C7, frontend, conference, RH, or schema changes were
introduced by C6D.
