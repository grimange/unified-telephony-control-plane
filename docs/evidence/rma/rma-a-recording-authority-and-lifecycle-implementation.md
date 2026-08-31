# RMA-A — Recording Authority and Lifecycle Implementation

Current-State-Impact: yes

Date: 2026-08-31

## Result

RMA-A is implemented and repository-tested. Natural live recording proof is
pending. K5C, K5D, and K5E remain complete and unchanged.

## Authority

ADR-029 remains the canonical boundary. A tenant-scoped PostgreSQL
`recording_sessions` relation is now the durable authority for authorized
technical recording intent and its desired/observed lifecycle. Sessions may
correlate to the canonical Call, CallLeg, and optional Conference; each has an
independent opaque identity and is not identified by a runtime name, local
path, CallLeg ID, or RuntimeOperation ID.

The authenticated API is the normal management path:

```text
authenticated request
  -> telephony.recordings.manage authorization
  -> RecordingSessionService
  -> PostgreSQL RecordingSession
  -> subordinate recording RuntimeOperation
```

`telephony.recordings.view` and `telephony.recordings.manage` are tenant-scoped
capabilities assigned to the existing tenant-admin role. The existing generic
recording operation endpoint now rejects recording start/stop requests; normal
recording execution must originate from a canonical RecordingSession. The
existing Call/CallLeg operation machinery remains the subordinate execution
transport and each RMA operation carries an explicit session correlation.

The session records requested intent separately from runtime observation:
`desired_state` is `recording` or `stopped`, while `observed_state` is
`requested`, `recording`, `stopped`, or `failed`. Runtime operation completion
and terminal failure update the session lifecycle. Normalized Asterisk
recording observations also update the session when a correlated CallLeg is
known. Meaningful start and stop intent transitions use the existing audit and
outbox infrastructure.

## Scope and exclusions

RMA-A does not create a RecordingArtifact, archive target, storage credential
reference, object-storage adapter, MinIO/S3 integration, retention/deletion
lifecycle, playback/download API, media browser, or FreeSWITCH recording
support. Asterisk recording mechanics and the existing FreeSWITCH unsupported
behavior are unchanged.

`call_legs.recording_ref` remains an existing dormant schema scaffold; it is not
used as canonical RMA authority. No competing recording lifecycle or manual
Artisan management path was added.

## Verification

The focused `RecordingSessionTest` covers durable tenant scoping, independent
identity, idempotent start, stop intent, tenant isolation, schema shape, and
the public-operation authority cutover. The full backend image suite passed:

```text
Tests: 9 skipped, 679 passed (5443 assertions)
```

Existing Call/CallLeg, RuntimeOperation, Asterisk, and FreeSWITCH unsupported
regressions passed as part of that suite. The phase-status consistency and
repository hygiene checks are run as part of handoff.

## Remaining work

RMA-A still requires controlled live proof of authenticated recording intent,
runtime execution/observation, and lifecycle convergence. RMA-B through RMA-H
remain not started.
