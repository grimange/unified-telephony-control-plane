# RMA-C ARI Object Sanitizer Recording Metadata Repair

Current-State-Impact: yes

## Verdict

`RMA_C_ARI_OBJECT_SANITIZER_RECORDING_METADATA_REPAIRED_AND_TESTED`

## Blocker and root cause

The focused RMA-C natural-live acceptance created the expected pending
RecordingArtifact, but `RecordingFinished` could not finalize it because the
ARI object sanitizer removed the provider's `recording.format` and
`recording.duration` fields before the existing listener and normalizer could
consume them. The artifact service correctly refused finalization without a
media format.

The repair is limited to `AsteriskAriClient::sanitizeAriObject()`. Its
recording whitelist now transports `format` as a bounded string (at most 32
characters) without rewriting its contents, and transports `duration` only
when it is an integer. Missing or non-integer duration remains null. Provider
paths, spool locations, and other unapproved fields remain stripped.

The existing listener, normalizer, RecordingArtifactService, provider
execution, artifact lifecycle, and availability invariants were not changed.
The normalizer remains the semantic authority: format is required and
validated for artifact finalization; duration is optional and remains in ARI
seconds until normalized to milliseconds.

## Validation

The repository-native container suite `make image-test-api` passed with 702
tests passed, 9 skipped, and 5585 assertions. Focused regression coverage
proves format retention, integer duration retention, absent-duration behavior,
numeric-string non-coercion, and stripping of unauthorized ARI fields. The
existing listener, normalizer, artifact finalization, audit/outbox,
idempotency, missing-format, FreeSWITCH, RMA-A, and RMA-B regressions also
remain green through the full suite. PHP syntax validation passed.

No provider polling, new operational control, schema/API/worker change,
FreeSWITCH implementation, archive/storage authority, or RMA-A/RMA-B change
was introduced. The corrected revision still requires immutable publication,
canonical deployment, and focused deployed natural-live RMA-C reproof.
