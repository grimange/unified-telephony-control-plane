# RMA-C Recording Artifact Authority Focused Acceptance — Blocker

Current-State-Impact: yes

**Date:** 2026-09-02
**Environment:** native k3s, context `default`, nodes `utcp-dev01` / `utcp-dev02`,
namespaces `utcp-platform` and `utcp-runtime`
**Verdict:** `RMA_C_ARTIFACT_FINALIZATION_BLOCKED_ARI_OBJECT_SANITIZER_STRIPS_RECORDING_FORMAT`

One fresh natural transaction proved the new artifact authority through
`pending` creation, then stopped at finalization. The `RecordingArtifact` was
created correctly with complete and exact correlation, but never reached
`available` because the provider's media format never survives ARI event
sanitization. No source, schema, configuration, capability, deployment, or
runtime state was changed by this acceptance run; provider inspection was
read-only and no event was injected.

## Repository and deployed baseline

The run began and ended on `main` at
`da30973d4db4f9fb0bf1742f1e544ae798fcd68b`, matching `origin/main` and remote
`refs/heads/main`, with a clean worktree. The deployed application source is
`d863b13e8865f14cd5c304e10414e97de26e17b4`.

Verified running image IDs matched the expected RMA-C digests exactly:

- API (`api`, `telephony-event-normalizer`, `telephony-command-worker`,
  `asterisk-ari-events`, `control-plane-outbox-dispatcher`,
  `telephony-reconciler`):
  `ghcr.io/grimange/utcp-api@sha256:53e370c90f9682075f33231b2562edd0a1bda949c56961e90b03efd753a9f990`
- Asterisk (`asterisk-ari`, managed RuntimeNode Pod):
  `ghcr.io/grimange/utcp-asterisk@sha256:5a7ea5819e84f82de9be1c7e2c223b87813ae6a53ecee33e5b0f80aab2972a1d`

Database baseline confirmed read-only: migration
`2026_09_02_120000_create_recording_artifacts_table` applied in batch 11;
`recording_artifacts` present with the designed columns, the
`recording_artifacts_session_unique` UNIQUE constraint, five FKs, three indexes,
and both CHECK constraints, including
`state <> 'available' OR (media_format IS NOT NULL AND available_at IS NOT NULL)`;
`call_legs.recording_ref` absent; zero pre-existing artifact rows.

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` was `active`/`ready`,
`configuration_version = observed_configuration_version = 33`, desired execution
image equal to observed, with the `recording` capability present.

## Fresh canonical entities

| Entity | Identifier |
| --- | --- |
| Call | `c136e73f-323a-4ef6-82a9-2d2bfb584d5e` |
| CallLeg | `6d2a96d2-1a12-4795-be27-cda54ace2fc8` |
| Runtime channel | `utcp-call-leg-6d2a96d2-1a12-4795-be27-cda54ace2fc8` |
| RecordingSession (`X`) | `5e4ba8c917a17764f8bf6d0d1c55505c` |
| `capture_ref` | `utcp:capture/5e4ba8c917a17764f8bf6d0d1c55505c` |
| Start operation | `3be1975b452ff401da078bb82a64c5aa` |
| Stop operation | `00dad8b195ffce1e9376d7a14204213d` |
| **RecordingArtifact** | `105561340c01c539b7f360263af9b39d` |
| `RecordingStarted` receipt | `e1e8cf7f2d865ae4b3cd7b0ac804ccaa` |
| `RecordingFinished` receipt | `6ec05af424b96e21c3bf502be9a0b963` |

## First failing boundary — artifact finalization

Expected after the `call.leg.recording_stopped` observation:

```text
state = available, media_format != null, available_at != null
exactly one recording_artifact.available event
```

Actual, after the observation was recorded at `06:16:07` and a further ~51
seconds of polling:

```json
{"id":"105561340c01c539b7f360263af9b39d","state":"pending",
 "media_format":null,"duration_ms":null,"available_at":null,
 "observed_started_at":"2026-09-02 06:15:59+00"}
```

Zero `recording_artifact.available` events were emitted. The session itself
converged normally to `stopped/stopped`.

## Root cause

The provider event's media format is stripped before the RMA-C listener can read
it.

Both durable receipts show the RMA-C extraction running but receiving nulls:

```text
RecordingStarted  e1e8cf7f… occurred 06:15:59.144Z
  {"recording_name":"utcp-capture-5e4ba8c917a17764f8bf6d0d1c55505c",
   "recording_format":null,"recording_duration":null,…}
RecordingFinished 6ec05af4… occurred 06:16:02.207Z
  {"recording_name":"utcp-capture-5e4ba8c917a17764f8bf6d0d1c55505c",
   "recording_format":null,"recording_duration":null,…}
```

The chain is unbroken and fully repository-verifiable:

1. `AsteriskAriClient::readWebSocketFrame()` returns
   `['type' => 'event', 'event' => $this->sanitizeAriEvent($decoded)]`, so the
   listener at `AsteriskAriEventListener.php:149` consumes an already-sanitized
   event.
2. `AsteriskAriClient::sanitizeAriEvent()` maps
   `'recording' => $this->sanitizeAriObject($event['recording'])`.
3. `AsteriskAriClient::sanitizeAriObject()` is a strict whitelist returning only
   `id`, `name`, `state`, `caller`, `connected`, `channelvars`, `media_uri`, and
   `channels`. **`format` and `duration` are not in it.**
4. `AsteriskAriEventListener.php:262-263` therefore reads
   `$event['recording']['format']` and `['duration']` from keys that no longer
   exist, and always records `null`.
5. `AsteriskAriEventNormalizer` emits the `call.leg.recording_stopped`
   observation without `media_format`.
6. `RecordingArtifactService::applyCaptureObservation()` correctly refuses to
   finalize without a valid format (`mediaFormat()` returns `null` → early
   return), because writing `available` with a null `media_format` would violate
   `recording_artifacts_available_metadata_check`.

The provider genuinely supplies the format. Read-only ARI polling during the same
transaction returned
`{"name":"utcp-capture-5e4ba8c917a17764f8bf6d0d1c55505c","format":"wav","state":"recording","target_uri":"channel:utcp-call-leg-6d2a96d2-…"}`
while live, and `{"name":"utcp-capture-…","format":"wav"}` once stored. The prior
provider-native smoke, which reads the ARI WebSocket directly and bypasses
`sanitizeAriObject()`, likewise observed `format wav` on both
`RecordingStarted` and `RecordingFinished`.

`RecordingArtifactService` is not defective; it refused to write a row that the
schema forbids. The defect is a single upstream sanitizer whitelist omission.

## Boundaries that did pass

**Artifact absent before capture.** The `202` response to the pre-answer
RecordingSession request carried `"artifact": null`, and `recording_artifacts`
held zero rows for the session before `RecordingStarted`.

**Pending artifact creation — exact and complete.** At `06:16:01`, immediately
after the `call.leg.recording_started` observation was processed, exactly one
artifact existed with every correlation exact:

```text
id                   = 105561340c01c539b7f360263af9b39d
state                = pending
recording_session_id = 5e4ba8c917a17764f8bf6d0d1c55505c   (= X)
call_id              = c136e73f-323a-4ef6-82a9-2d2bfb584d5e
call_leg_id          = 6d2a96d2-1a12-4795-be27-cda54ace2fc8
runtime_node_id      = 102d58ba-93ec-4601-a2a3-81f95801440f
capture_ref          = utcp:capture/5e4ba8c917a17764f8bf6d0d1c55505c
observed_started_at  = 2026-09-02 06:15:59+00
available_at         = null
```

The artifact identity is independent of the RecordingSession id, as designed.

**Pending API projection.** The existing RecordingSession endpoint returned
`"artifact":{"id":"105561340c01c539b7f360263af9b39d","state":"pending","media_format":null,"duration_ms":null,"available_at":null}`
under the existing `telephony.recordings.view` permission, with no new route and
no `capture_ref`, path, size, or checksum exposure.

**Cardinality.** Exactly one artifact for the session throughout, including
after the `recording_stopped` observation.

**Channel-less provider corridor.** Both receipts reached `status=processed` and
normalized into `call.leg.recording_started` / `call.leg.recording_stopped` with
`subject_type=call_leg`, `subject_id=6d2a96d2-…`, and
`capture_ref=utcp:capture/5e4ba8c917a17764f8bf6d0d1c55505c`.

**RMA-A / RMA-B regressions intact.** Pre-answer intent, natural answer,
exactly-one start, `capture_ref` identity on both operations, store-preserving
stop (live `404`, stored `200`, artifact retained), and `stopped/stopped`
convergence all behaved as previously proven.

**Lifecycle separation.** The stalled artifact did not affect RecordingSession
state; the session reached `stopped/stopped` independently.

**FreeSWITCH and RMA-D/E boundaries.** `freeswitch-esl` still does not advertise
`recording`; no archive target, credential, transfer, bucket, object key,
storage URI, `size_bytes`, `checksum`, retention, playback, or download
authority appeared anywhere.

## Bounded next repair

Add the two missing keys to the ARI object sanitizer whitelist in
`apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriClient.php::sanitizeAriObject()`:

```text
'format'   => bounded lowercase token when present, else null
'duration' => integer seconds when numeric, else null
```

`AsteriskAriEventListener` already reads exactly these keys and needs no change
beyond tolerating a numeric-string `duration`. No normalizer, artifact service,
schema, migration, capability, API, permission, or RMA-A/RMA-B change is
required. The repair must not widen the sanitizer beyond these two fields and
must not expose provider storage paths.

Focused regression coverage required: a sanitizer test proving a `recording`
object containing `format`/`duration` survives sanitization while unrelated keys
remain stripped; a listener test proving `recording_format`/`recording_duration`
are populated; and a normalization-to-artifact test proving
`media_format = "wav"` drives `pending → available` with a single
`recording_artifact.available` event.

## Boundaries not reached

`available` finalization, media-format normalization, duration normalization,
availability API projection, and the `recording_artifact.available` audit/outbox
contract were not reached because finalization never occurred.
