# RMA-C Recording Artifact Authority Focused Deployed Natural-Live Reproof — PASSED

Current-State-Impact: yes

**Date:** 2026-09-02
**Environment:** native k3s, context `default`, nodes `utcp-dev01` / `utcp-dev02`,
namespaces `utcp-platform` and `utcp-runtime`
**Verdict:** `RMA_C_RECORDING_ARTIFACT_AUTHORITY_FOCUSED_DEPLOYED_NATURAL_LIVE_REPROOF_PASSED`

The previously blocked causal chain is proven live on the corrected immutable
revision:

```text
Asterisk RecordingFinished  recording.format = "wav"
→ sanitizer retains format/duration
→ receipt recording_format = "wav"
→ observation media_format = "wav"
→ same RecordingArtifact pending → available
→ exactly one recording_artifact.available
→ existing RecordingSession API projects the available artifact
```

Two fresh natural transactions were run. No provider event was injected,
reordered, or synthesized; no artifact state was created manually; no source,
schema, capability, or deployment state was changed.

## Repository and deployed baseline

The run began and ended on `main` at
`383936e84d2fc5cfeecb8e6ee7e4050cb74c6840`, matching `origin/main` and remote
`refs/heads/main`, with a clean worktree. The deployed application source is the
corrected revision `dc6ae91b9edadada9f0c321489d8b81c75f5b90f`.

**Event-path image provenance.** Every UTCP API workload — including all
authoritative RMA-C event processors (`api`, `asterisk-ari-events`,
`telephony-event-normalizer`, `telephony-command-worker`,
`telephony-reconciler`, `control-plane-outbox-dispatcher`) — runs
`ghcr.io/grimange/utcp-api@sha256:3729d93f448a87084d9057504a25040c19cd7e31cf0c13b5eaaf52fe3a98a538`.
**Zero** workloads run the previously blocked digest
`sha256:53e370c9…a9f990`. There is no mixed-version proof.

Both Asterisk Pods run
`ghcr.io/grimange/utcp-asterisk@sha256:cf9d0303513756c7e878175e54ae9262506eab2a0f9f80b5987968952b8530ac`;
the managed RuntimeNode Pod
`asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6c77b9tflj` was Running,
Ready, restart count `0`.

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` was `active`/`ready`,
`configuration_version = observed_configuration_version = 33`, desired execution
image equal to observed, with the `recording` capability present. It was not
refreshed or modified.

**Deployed sanitizer provenance**, read directly from the running API image:

```php
'format'   => is_string($value['format'] ?? null) ? mb_substr($value['format'], 0, 32) : null,
'duration' => is_int($value['duration'] ?? null) ? $value['duration'] : null,
```

## Transaction 1 — provider-terminated capture

| Entity | Identifier |
| --- | --- |
| Call | `a8c19fe8-e3d7-4933-9a0b-b7fd18034b5e` |
| CallLeg | `547e9196-20d4-46d2-9112-8e02a2542689` |
| RecordingSession | `4cbb8775bd9fc8fa7c0788e836ea28ae` |
| RecordingArtifact | `3415154ccf27eeff32cea02b72076899` |
| Start operation | `98f4d52a51c6c1ee9d2514737725acf8` |
| `capture_ref` | `utcp:capture/4cbb8775bd9fc8fa7c0788e836ea28ae` |

Pre-start the API returned `"artifact": null`. The `RecordingStarted` receipt
`4fb40e584269d62c5882afcffd682d9c` carried **`recording_format: "wav"`**, and the
pending artifact was created at `07:56:32` with exact correlation. Asterisk then
ended the capture on its own at `07:56:22.112Z`, logging
`__ast_play_and_record: No audio available on PJSIP/anonymous-00000000` — the
known zero-RTP Echo fixture property. The genuine `RecordingFinished` receipt
`595dab4d8e8dfc591e1d0b41c40312b1` carried `recording_format: "wav"` and
`recording_duration: 0`, the artifact finalized to `available` with
`media_format=wav`, `duration_ms=0`, `available_at=07:56:22`, and exactly one
`recording_artifact.available` event was emitted.

Because the provider had already finished the capture, the subsequent canonical
stop request correctly early-returned under the frozen RMA-A stop-idempotency
rule (`observed_state === 'stopped'`), so no stop operation was created and the
session ended `desired_state=recording` / `observed_state=stopped`. This
ordering had not been exercised before. It is disclosed in full under
*Divergences* below and is not an RMA-C artifact-authority behavior.

## Transaction 2 — canonical-stop capture (primary record)

| Entity | Identifier |
| --- | --- |
| Call | `cb7b95cb-f4df-46c7-bb44-4ee7f2c3c063` |
| CallLeg | `48dcef7e-3ec2-42ad-a46b-b8589c7228f8` |
| Runtime channel | `utcp-call-leg-48dcef7e-3ec2-42ad-a46b-b8589c7228f8` |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| RecordingSession (`X`) | `404d7ff0c6edf156f279a4a3ba0bd329` |
| **RecordingArtifact (`A`)** | `86e0e14af4488360243d648a65751d01` |
| `capture_ref` | `utcp:capture/404d7ff0c6edf156f279a4a3ba0bd329` |
| Provider capture | `utcp-capture-404d7ff0c6edf156f279a4a3ba0bd329` |
| Start operation | `91c57420579c09c3c6335fb041edd48c` |
| Stop operation | `a9eddf1ceeeeeb205587a4815be19b56` |
| `RecordingStarted` receipt | `42a04c8daa26986211a0d023be725e58` |
| `RecordingFinished` receipt | `55d6735b33f6bd01f68a5ef1eaea50fd` |

Pre-start: `"artifact": null`, zero `recording_artifacts` rows for `X`.

**Provider metadata and sanitized receipts** — the repaired boundary:

```text
RecordingStarted  08:01:43.387Z  recording_format="wav"  recording_duration=null
RecordingFinished 08:01:46.473Z  recording_format="wav"  recording_duration=0
```

Read-only ARI polling independently returned
`{"name":"utcp-capture-404d7ff0c6edf156f279a4a3ba0bd329","format":"wav","state":"recording","target_uri":"channel:utcp-call-leg-48dcef7e-…"}`
while live. Under the previous revision both receipt fields were `null`; they now
carry the provider values.

**Normalized observations:**

```text
call.leg.recording_started  subject=call_leg/48dcef7e-…  capture_ref=utcp:capture/404d7ff0…  media_format="wav"
call.leg.recording_stopped  subject=call_leg/48dcef7e-…  capture_ref=utcp:capture/404d7ff0…  media_format="wav"  duration_ms=0
```

**Canonical stop and store-preserving provider stop.** The stop was issued
through the canonical RecordingSession API; stop operation
`a9eddf1ceeeeeb205587a4815be19b56` succeeded on `attempt_count=1` carrying the
identical `capture_ref`. Provider outcome at `08:01:46.818Z`: live recording
`404`, stored recording `200`, spool artifact retained — store-preserving, not
cancel/discard.

**Finalized artifact:**

```text
id                   = 86e0e14af4488360243d648a65751d01
state                = available
recording_session_id = 404d7ff0c6edf156f279a4a3ba0bd329   (= X)
call_id              = cb7b95cb-f4df-46c7-bb44-4ee7f2c3c063
call_leg_id          = 48dcef7e-3ec2-42ad-a46b-b8589c7228f8
runtime_node_id      = 102d58ba-93ec-4601-a2a3-81f95801440f
capture_ref          = utcp:capture/404d7ff0c6edf156f279a4a3ba0bd329
media_format         = wav
duration_ms          = 0
observed_started_at  = 2026-09-02 08:01:43+00
available_at         = 2026-09-02 08:01:46+00
```

`artifact_count_for_session = 1`. The artifact identity is independent of the
RecordingSession identity.

**Audit / outbox** — exactly one `recording_artifact.available`, aggregate
`recording_artifact/86e0e14af4488360243d648a65751d01`, payload carrying
`recording_session_id`, `call_id`, `call_leg_id`, `runtime_node_id`,
`capture_ref`, `media_format=wav`, `duration_ms=0`. No
`recording_artifact.created` and no `recording_artifact.failed`.

**API projection** through the existing endpoint under
`telephony.recordings.view`:

```json
"artifact": {"id":"86e0e14af4488360243d648a65751d01","state":"available",
             "media_format":"wav","duration_ms":0,"available_at":"2026-09-02 08:01:46+00"}
```

No `capture_ref`, `runtime_node_id`, provider recording name, spool or filesystem
path, size, checksum, bucket, object key, or storage URI is exposed.

**RecordingSession lifecycle separation.** The session reached
`desired_state=stopped`, `observed_state=stopped`, `stopped_at=08:01:46`
through its own frozen RMA-A path; the artifact never mutated session state and
the session never wrote artifact state.

## Duration contract

Case A applied in both transactions: Asterisk naturally supplied
`recording.duration = 0` on `RecordingFinished`, normalized to `duration_ms = 0`
(0 s x 1000). `RecordingStarted` carried no duration, which is valid. No duration
was fabricated or inferred from timestamps. The value is `0` because the fixture
corridor carries no audio RTP, consistent with the 44-byte artifact.

## 44-byte WAV boundary — closed

Both transactions produced the known 44-byte `RIFF/WAVE` artifact with a
zero-length PCM payload
(`utcp-capture-404d7ff0c6edf156f279a4a3ba0bd329.wav`, 44 bytes, retained after
stop), and both artifacts reached `state=available` with `media_format=wav`.
This closes the previously blocked content-independence boundary: RMA-C
availability depends on provider-reported completion plus a normalized format,
never on byte size, duration, or audio content. No RTP was manipulated to force
this outcome.

## Divergences

**Transaction 1 stop ordering.** Asterisk self-terminated the capture
(`No audio available`) before the canonical stop was issued, so `requestStop`
early-returned under the frozen RMA-A idempotency rule and the session ended
`desired_state=recording` / `observed_state=stopped` with a null
`stop_operation_id`. This is pre-existing RecordingSession authority that RMA-C
does not own and this packet did not change; it is not caused by, and does not
affect, artifact authority. Transaction 2 exercised the canonical stop path
cleanly and ended `stopped/stopped` with a real stop operation. Whether
`desired_state` should also converge to `stopped` when a capture ends at the
provider first is a separate, bounded RecordingSession question, deliberately not
opened here.

**Observation-processing latency.** Receipt-to-observation lag was ~14 s in
transaction 1 and ~3 s in transaction 2. This is normal event-batch behavior and
affected only test sequencing, not correctness.

## Runtime-local artifact limitation

The provider media remains container-local Asterisk spool storage with no PVC. It
is runtime-local provider media, **not** a durable archive. `state = available`
means the runtime reported capture completion in a known container format. No
PVC, object storage, archive copy, or manual preservation was added.

## FreeSWITCH and RMA-D / RMA-E boundaries

The deployed catalog still reports
`freeswitch-esl.supported_capabilities = ["call.origination","call.control","call.hold","call.dtmf.send","media.playback"]`
with `recording` absent; no FreeSWITCH recording transaction was performed. No
archive target, credential, transfer, S3, MinIO, bucket, object key, storage URI,
`size_bytes`, `checksum`, retention, playback, download, or deletion authority
appeared. `recording_artifacts` remains metadata authority only.

## Acceptance matrix

| Boundary | Result |
| --- | --- |
| Correct repaired API digest deployed | PASS |
| All authoritative event processors use repaired digest | PASS |
| Correct Asterisk digest deployed | PASS |
| RuntimeNode active/ready/current | PASS |
| Fresh RecordingSession | PASS |
| Artifact absent before start | PASS |
| Natural RecordingStarted | PASS |
| Exactly one pending artifact | PASS |
| Exact RecordingSession correlation | PASS |
| Exact RuntimeNode correlation | PASS |
| Natural RecordingFinished | PASS |
| Provider format = wav | PASS |
| Sanitized receipt `recording_format` = wav | PASS |
| Observation `media_format` = wav | PASS |
| Duration normalization correct if supplied | PASS (0 s → 0 ms) |
| Same artifact finalized | PASS |
| pending → available | PASS |
| Exactly one artifact | PASS |
| Exactly one `recording_artifact.available` | PASS |
| Available artifact API projection | PASS |
| RecordingSession lifecycle separate | PASS |
| 44-byte WAV availability | PASS |
| FreeSWITCH boundary unchanged | PASS |
| No RMA-D/E leakage | PASS |

## Historical residue

The blocked transaction's artifact `105561340c01c539b7f360263af9b39d`
(RecordingSession `5e4ba8c917a17764f8bf6d0d1c55505c`) remains `pending` from the
pre-repair run. It is preserved historical evidence of the sanitizer defect, is
correctly represented by the explicit `pending` state rather than an absent row,
and was not mutated.
