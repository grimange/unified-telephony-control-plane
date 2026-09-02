# RMA-B Runtime-Neutral Capture Contract Focused Acceptance — Blocker

Current-State-Impact: yes

**Date:** 2026-09-02
**Environment:** native k3s, context `default`, `utcp-dev01` (`192.168.254.124`),
namespaces `utcp-platform` and `utcp-runtime`
**Verdict:** `RMA_B_RUNTIME_NEUTRAL_CAPTURE_CONTRACT_ACCEPTANCE_BLOCKED_CAPTURE_REFERENCE_NOT_DERIVED_FROM_RECORDING_SESSION_ID`

One fresh canonical transaction exercised the deployed RMA-B contract end to end.
The capture mechanism works: the canonical capture identity reaches both
RuntimeOperations, the Asterisk provider name is derived from it, the CallLeg-ID
naming authority is gone, the channel-less provider recording events now
normalize into canonical CallLeg observations for the first time, the exact
RecordingSession converges, and the stopped state stays monotonic under
naturally late provider events.

One boundary fails. The canonical capture identifier is `md5()` of the
RecordingSession id rather than the RecordingSession id itself. No source,
configuration, capability, schema, deployment, or runtime state was changed by
this acceptance run; all provider inspection was read-only.

## Repository and deployed baseline

The run began and ended on `main` at
`7a8c786da00f6296a870d38c85b11d8d793780a3`, matching `origin/main` and remote
`refs/heads/main`, with a clean worktree. The RMA-B implementation source is
`157f1928078786538a6574f47abf5e1864bfc7bb`.

Deployed immutable images were verified before the transaction and matched the
published RMA-B revision exactly:

- API (`api`, `telephony-command-worker`, `telephony-event-normalizer`,
  `asterisk-ari-events`, `telephony-reconciler`):
  `ghcr.io/grimange/utcp-api@sha256:d24c058844a8bf3488fac5079195695b3720b5f2c3009f77c6f5b0f13b882016`
- Asterisk (`asterisk-ari`, managed RuntimeNode Pod):
  `ghcr.io/grimange/utcp-asterisk@sha256:ef75061713c4fcba4d78e4601f7f8159976b790348f47a08f5bd1072ea064e24`

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` reported `desired_state=active`,
`observed_state=ready`, `configuration_version=33 = observed_configuration_version=33`,
desired and observed execution images equal to the deployed Asterisk digest, and
the `recording` capability present.

## Fresh canonical entities

| Entity | Identifier |
| --- | --- |
| Call | `6f7f5d52-1a34-402b-ad7f-024e5386af18` |
| CallLeg | `8e55d54f-fb4f-4db4-b3c3-9101a77ce24c` |
| Runtime channel | `utcp-call-leg-8e55d54f-fb4f-4db4-b3c3-9101a77ce24c` |
| Originate operation | `56818004a094406499db035d85426281` |
| RecordingSession | `f378c9e4c806daedb175c5d9f6173f20` |
| Start operation | `7b2d00eda0155b88f3daed21cd491fe6` |
| Stop operation | `9729d4d60a00328b1f647b8a63b203fb` |
| Provider capture name | `utcp-capture-a93d07e2b311cb5a7b3d812cd3770f54` |
| `RecordingStarted` receipt | `4723648959924455251a9efd6e7978e9` |
| `RecordingFinished` receipt | `271bcd54d6186dbf099b73b77e66f145` |
| `recording_started` observation | `2909eb1546786cccdfc1b093050138ba` |
| `recording_stopped` observation | `9a9b0b3a67b348410451d11cb42cf937` |

## First failing boundary — canonical capture identity

The acceptance contract requires:

```text
capture_ref = utcp:capture/<RecordingSessionId>
```

The deployed durable payload of both capture operations is:

```text
capture_ref = utcp:capture/a93d07e2b311cb5a7b3d812cd3770f54
```

while the RecordingSession id is `f378c9e4c806daedb175c5d9f6173f20`. The value
is `md5('f378c9e4c806daedb175c5d9f6173f20')`.

The owning authority is
`App\TelephonyDomain\CaptureReference::forRecordingSession()`, which returns
`new self(md5($id))`. `RecordingSessionId` already satisfies the
`^[a-f0-9]{32}$` identifier rule enforced by `CaptureReference::parse()`, so the
hash is unnecessary for format conformance.

Two concrete consequences follow, both beyond identifier shape:

1. **The canonical identity is not invertible.** A `capture_ref` cannot be
   resolved to its RecordingSession by derivation, only by search.
2. **Correlation became an unindexed tenant-wide scan.**
   `AsteriskAriEventNormalizer::normalizeRecordingEvent()` loads every
   `recording_sessions` row for the tenant in `requested`/`recording`/`stopped`
   and re-hashes each candidate until one matches, and
   `RecordingSessionService::applyObservation()` repeats the same scan for the
   leg. This executes on every capture observation, and grows with the tenant's
   recording history. The accepted implementation design's basis for requiring no
   migration was explicitly that correlation resolves through the existing
   `recording_sessions.id` primary key.

A related weakness: `forRecordingSession()` validates nothing, so any string is
accepted and hashed. `RuntimeNeutralCaptureContractTest` consequently passes the
non-identifier literal `'session-1'`, and its payload/result assertions are built
from hand-written arrays rather than the production service output, so they do
not exercise the real derivation.

## Boundaries that did pass

**Neutral operation payloads.** Start and stop carry the identical `capture_ref`
and contain only `call_id`, `leg_id`, `recording_session_id`, `capture_ref`, and
`requested_by_user_id`. No `wav`, `ifExists`, ARI route, provider recording name,
or storage path appears in canonical state.

**Provider translation and legacy cutoff.** The live provider resource was
`{"name":"utcp-capture-a93d07e2b311cb5a7b3d812cd3770f54","format":"wav","state":"recording","target_uri":"channel:utcp-call-leg-8e55d54f-fb4f-4db4-b3c3-9101a77ce24c"}`.
Concurrent read-only polling of the CallLeg-ID name
(`8e55d54f-fb4f-4db4-b3c3-9101a77ce24c`) returned `404` for the whole
transaction, proving the removed CallLeg-ID fallback is not reachable.

**Neutral result.** Both operation completion events carried
`runtime_capture_reference` in canonical form alongside `provider_action`
(`channels.record`, `recordings.stop`) and `runtime_channel_id`, with no path,
size, duration, bucket, or archive field.

**Channel-less observation corridor.** Both provider receipts were confirmed to
carry no `channel_id` and no `call_leg_id`:

```text
RecordingStarted  03:09:30.038Z  recording_name=utcp-capture-a93d07e2b311cb5a7b3d812cd3770f54
RecordingFinished 03:09:33.747Z  recording_name=utcp-capture-a93d07e2b311cb5a7b3d812cd3770f54
```

Both nevertheless produced canonical observations with
`subject_type=call_leg`, `subject_id=8e55d54f-fb4f-4db4-b3c3-9101a77ce24c`, and
`capture_ref` in the payload. Under RMA-A these observations did not exist at
all; `runtime_observations` for that leg contained only offered/answered/
terminated. This corridor is the principal new RMA-B behavior and it works.

**Exact session targeting and convergence.** The observations resolved the exact
RecordingSession encoded by the capture reference, not "the latest session on the
leg". The session converged `requested → recording → stopped`
(`started_at=03:09:30`, `stopped_at=03:09:33`).

**Idempotency and exactly-once.** Final durable counts were
`start_recording=1`, `stop_recording=1`, `recording_sessions=1` after both the
idempotent start replay and the idempotent stop replay.

**Store-preserving stop.** After the canonical stop the live recording returned
`404` while the stored recording returned `200` and
`utcp-capture-a93d07e2b311cb5a7b3d812cd3770f54.wav` was retained in the spool.
No `DELETE /ari/recordings/live/...` cancel request occurred.

**Natural monotonicity.** Both recording observations were received at
`03:09:35` and `03:09:36`, after the session had already converged to `stopped`
at `03:09:33`. The naturally late `recording_started` did not regress the stopped
session; the final state remained `stopped/stopped` through natural call
termination at `03:09:57`.

**FreeSWITCH honesty.** The deployed catalog reports
`freeswitch-esl.supported_capabilities = ["call.origination","call.control","call.hold","call.dtmf.send","media.playback"]`
with `recording` absent, and no FreeSWITCH RuntimeNode exists, so no
recording-capable FreeSWITCH execution target can be selected. The repository
conformance test additionally asserts terminal
`freeswitch_call_operation_unsupported` with zero ESL requests issued.

**No RMA-C leakage.** `call_legs.recording_ref` remained `null`, and no
artifact, size, duration, checksum, archive-target, bucket, or storage-URI
authority was created. The stored WAV is 44 bytes because the Echo fixture
corridor carries no RTP; that is a fixture media property and is not evaluated
by RMA-B.

## Bounded next repair

Derive the capture identifier directly from the RecordingSession id and resolve
correlation by primary key:

1. `CaptureReference::forRecordingSession()` must validate the input against
   `^[a-f0-9]{32}$` and use it as the identifier, without hashing.
2. `AsteriskAriEventNormalizer::normalizeRecordingEvent()` must look the session
   up by `id` instead of scanning and re-hashing candidates.
3. `RecordingSessionService::applyObservation()` must select the session by `id`
   plus tenant and leg, keeping its
   `whereIn(observed_state, ['requested','recording'])` monotonicity guard.
4. `RuntimeNeutralCaptureContractTest` must use a real 32-hex RecordingSession id
   and assert the payloads produced by `RecordingSessionService`, not
   hand-written arrays.

No migration, API route, capability, worker, adapter interface, or Asterisk
route/format change is required. Every other boundary proven in this run must
remain unchanged.

## Boundaries not reached

None. The full transaction was executed and every acceptance boundary was
observed.
