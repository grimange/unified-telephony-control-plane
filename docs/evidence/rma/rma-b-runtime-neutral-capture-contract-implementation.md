# RMA-B Runtime-Neutral Capture Contract Implementation

Current-State-Impact: yes

## Verdict

`RMA_B_RUNTIME_NEUTRAL_CAPTURE_CONTRACT_IMPLEMENTED_AND_TESTED`

## Scope

RMA-B formalizes the existing Model C boundary without creating a second
execution architecture: `RecordingSession` owns lifecycle intent,
`RuntimeOperation` owns durable execution, `CaptureReference` owns the
deterministic runtime-neutral capture identity, and adapters translate to
provider mechanics. RMA-A lifecycle behavior remains unchanged.

## Implemented contract

`CaptureReference` uses the canonical `utcp:capture/<32 lowercase hex>` form,
derives deterministically from `RecordingSession.id`, and translates to
`utcp-capture-<id>` only at the Asterisk boundary. Start and stop operations
carry the same `capture_ref` and no provider vocabulary. The previous
Asterisk CallLeg-ID recording-name fallback was removed. Successful Asterisk
operations expose the canonical `runtime_capture_reference` result field.

Asterisk recording events now correlate by the canonical provider capture name
even when no channel ID is present. The recording-only normalizer exception is
narrow; non-recording events retain the existing empty-channel rejection.
Observation updates resolve the exact tenant-scoped RecordingSession and retain
the existing monotonic state guard. The simulator emits the same normalized
capture observations. FreeSWITCH recording remains explicitly unsupported and
its capability catalog is unchanged.

## Boundary decisions

No migration, table, column, external API, worker, queue, scheduler, or
`RuntimeAdapter` interface change was introduced. No RecordingArtifact,
archive, object-storage, retention, playback, download, or media-content
authority was introduced. The RMA-A zero-payload WAV fixture behavior remains a
capture-lifecycle fact; byte/content validity is deferred to RMA-C.

## Validation

The host lacks local API Composer dependencies, so the repository-native
container path `make image-test-api` was used. It passed 687 tests, with 9
skipped, and 5488 assertions. Direct PHP syntax validation, the required API
checks, phase-status consistency, repository hygiene, and `git diff --check`
are required completion checks for the implementation commit.

## Remaining scope

No FreeSWITCH recording implementation or live PBX/deployment proof is claimed.
The next bounded action is RMA-B acceptance proof, followed by later RMA-C
artifact authority work according to the roadmap.
