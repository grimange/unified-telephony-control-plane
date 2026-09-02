# RMA-C Recording Artifact Authority Implementation

Current-State-Impact: yes

## Verdict

`RMA_C_RECORDING_ARTIFACT_AUTHORITY_IMPLEMENTED_AND_TESTED`

## Authority and lifecycle

RMA-C implements the approved observation-driven Model C boundary. The
`RecordingSessionService` remains the capture lifecycle authority,
`RecordingArtifactService` is the sole writer for artifact metadata, and
`CallObservationProcessor` is the single recording-observation ingress. A
`call.leg.recording_started` observation creates one `pending` artifact;
`call.leg.recording_stopped` finalizes that same row to `available`.

The artifact is correlated by the tenant-scoped `CaptureReference` identifier
to the exact `recording_sessions.id`. Its independent `RecordingArtifactId`
primary key and the unique `recording_session_id` constraint enforce one
artifact per RecordingSession. Duplicate and out-of-order observations are
safe, and only `pending → available` emits `recording_artifact.available`.

## Schema and legacy cutoff

Migration `2026_09_02_120000_create_recording_artifacts_table` adds the
required non-null tenant, Call, CallLeg, RuntimeNode, capture reference, state,
and observation metadata; restrict-on-delete foreign keys; the session
cardinality constraint; tenant/state, tenant/Call, and tenant/CallLeg indexes;
and PostgreSQL state/availability checks. Before dropping the dead
`call_legs.recording_ref` column, the migration asserted a zero non-null row
count. Its rollback recreates that nullable legacy column and removes the
artifact table. No compatibility dual-write remains.

## Provider observation and API boundary

Asterisk recording events retain only recording format and duration metadata
needed by RMA-C. Format is normalized to lowercase and ARI duration seconds
are normalized to milliseconds. Provider paths, spool locations, and provider
resource identifiers are not persisted as artifact authority. The existing
recording observation path remains channel-less and preserves exact session
targeting and RecordingSession monotonicity.

Existing RecordingSession reads expose a safe nested artifact projection with
`id`, `state`, `media_format`, `duration_ms`, and `available_at`, or `null`.
The existing `telephony.recordings.view` permission is reused. No route,
permission, worker, queue, RuntimeAdapter operation, or schema authority for
archive/storage was added.

## Media and deferred boundaries

Artifact `available` means that the provider reported capture completion with
a known media format. It does not assert non-zero audio, byte validity,
playability, durable storage, archive transfer, retention, or download. The
runtime-local Asterisk spool limitation remains explicit; archive and object
storage belong to later RMA slices.

FreeSWITCH production behavior remains unchanged and unsupported for recording;
no artifact is manufactured by an unsupported capture attempt.

## Validation and remaining proof

The repository-native `make image-test-api` suite passed with 701 tests passed,
9 skipped, and 5572 assertions. Focused coverage includes artifact identity,
schema/cardinality, lifecycle ordering, idempotency, tenant/session targeting,
available-event uniqueness, Asterisk metadata normalization, safe API
projection, and the unchanged FreeSWITCH unsupported boundary. PHP syntax,
phase-status consistency, repository hygiene, and `git diff --check` are
required final gates. Migration up/down proof is run in the supported
containerized API environment.

RMA-C is implemented and repository-tested; focused deployed natural-live
artifact acceptance remains pending. RMA-D is not started.
