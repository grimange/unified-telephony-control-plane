# RMA-A Recording Authority and Lifecycle Final Natural-Live Acceptance — PASSED

Current-State-Impact: yes

**Date:** 2026-09-02
**Environment:** native k3s, context `default`, `utcp-dev01` (`192.168.254.124`),
namespaces `utcp-platform` and `utcp-runtime`
**Verdict:** `RMA_A_RECORDING_AUTHORITY_AND_LIFECYCLE_FINAL_NATURAL_LIVE_ACCEPTANCE_PASSED`

One fresh canonical transaction proved the complete remaining RMA-A lifecycle:
RuntimeNode eligibility, canonical Call origination, pre-answer RecordingSession
intent with zero premature provider start, natural SIP answer, exactly-one
automatic recording start, a real Asterisk WAV recording, canonical active
convergence, start idempotency, canonical stop, store-preserving Asterisk stop,
canonical stopped convergence, stop idempotency, and complete audit continuity.

No implementation source, test, migration, Asterisk configuration, Kubernetes
manifest, NetworkPolicy, database row, or Redis key was modified to obtain this
result. All provider inspection was read-only.

## Repository and deployed baseline

The run began and ended on `main` at
`d8b788d1a72397d944cd3e6a35004dababf16fac`, matching `origin/main`, with a clean
worktree. The CI-guarded marker remained `UTCP_PHASE=T1`.

Both Kubernetes Nodes were Ready. The live Pods carried the published immutable
images:

| Workload | Pod | Node | Image |
| --- | --- | --- | --- |
| API | `api-7d6d498875-n42pk` | `utcp-dev02` | `ghcr.io/grimange/utcp-api@sha256:191b468df1cc47d1d4ee92108cfb1ee686acc5c22d5d93a09a65411daae2ec0a` |
| Managed RuntimeNode Asterisk | `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6576f8m8vk` | `utcp-dev01` | `ghcr.io/grimange/utcp-asterisk@sha256:8d086c0fd9d4b319fcf9a9bf12f9e54db236964918ff94b6263974c40bc7cf66` |
| Destination Asterisk fixture | `asterisk-ari-5d566cb45d-xcrkh` | `utcp-dev01` | `ghcr.io/grimange/utcp-asterisk@sha256:8d086c0fd9d4b319fcf9a9bf12f9e54db236964918ff94b6263974c40bc7cf66` |

## Stage 0 — execution eligibility

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f`
(`v1a-outbound-reproof-asterisk-1787825256`) reported:

```text
desired_state=active
observed_state=ready
configuration_version=33
observed_configuration_version=33
desired_execution_image=ghcr.io/grimange/utcp-asterisk@sha256:8d086c0fd9d4b319fcf9a9bf12f9e54db236964918ff94b6263974c40bc7cf66
observed_execution_image=sha256:8d086c0fd9d4b319fcf9a9bf12f9e54db236964918ff94b6263974c40bc7cf66
```

The desired and observed execution digests were equal and a healthy Ready
Asterisk Pod existed, so the node was eligible. The live-Pod execution-image
observation repair did not regress.

## Fresh canonical entities

| Entity | Identifier |
| --- | --- |
| Tenant | `342ee3b1-5b74-4964-8113-15030a61fda3` |
| Call | `b720aebc-7709-42e8-89b6-c497398d0a43` |
| CallLeg | `7995cc63-e028-45a8-9123-c5203224772f` |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-7995cc63-e028-45a8-9123-c5203224772f` |
| Originate RuntimeOperation | `d2cf5bef82aed709a4d719adb743dd62` |
| RecordingSession | `07e7b201206ccf43f08a003ec5061611` |
| Start RuntimeOperation | `68d588546b57ac2d09c2354d42f99361` |
| Stop RuntimeOperation | `aa5f5ea88fcffced0c3a3e8dcee04f4b` |
| Provider recording name | `7995cc63-e028-45a8-9123-c5203224772f` |
| `RecordingStarted` receipt | `39519e6e5edb3783a2ab7608ba8dd539` |
| `RecordingFinished` receipt | `4902f603b8aee9229da5eeb6b0d4fb80` |

All management actions used the normal first-party authenticated application
API through the Traefik edge (`https://app.utcp.local.test`) after a real
`POST /api/v1/auth/login` and tenant-context selection. The session carried
server-computed `telephony.calls.originate` and `telephony.recordings.manage`.

## Correlated lifecycle timeline (UTC, 2026-09-02)

| Time | Boundary | Evidence |
| --- | --- | --- |
| `00:47:26.864` | Fresh canonical Call | `POST /api/v1/calls` → `201 Created`; destination `sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060` |
| `00:47:27` | Canonical persistence | Call and CallLeg `originating`; originate operation `pending` |
| `00:47:29.490` | Pre-answer RecordingSession intent | `POST /api/v1/calls/{call}/recordings` → `202 Accepted`; `desired_state=recording`, `observed_state=requested`, `start_operation_id=null` |
| `00:47:29.958` | Zero premature start | CallLeg still `originating`; only operation present is `call.leg.originate`; `call.leg.start_recording` count `0` |
| `00:47:32.212` | Natural SIP INVITE | `ChannelCreated` `PJSIP/anonymous-00000001`, state `Down`; destination Asterisk processed the inbound request in the same second |
| `00:47:32.234` | Natural SIP answer | `ChannelStateChange` → `Up` plus `StasisStart` (200 OK / ACK); destination dialplan `9900 → Answer() → Echo()` |
| `00:47:38` | Canonical answered | Audit `call_leg.state_changed originating → answered`, `source=observation-confirmed`; Call `answered` |
| `00:47:38` | Exactly-one automatic start | Exactly one `call.leg.start_recording` created and bound to the session; `attempt_count=1`, `status=succeeded` |
| `00:47:38.862` | Provider WAV start / `RecordingStarted` | Durable receipt, `recording_name=7995cc63-e028-45a8-9123-c5203224772f` |
| `00:47:39.105` | LiveRecording | `GET /ari/recordings/live/{name}` → `200` `{"name":"7995cc63-…","format":"wav","state":"recording","target_uri":"channel:utcp-call-leg-7995cc63-…"}` |
| `00:47:38` | Canonical active convergence | RecordingSession `desired_state=recording`, `observed_state=recording`, `started_at=00:47:38` |
| `00:47:39.363` | Start idempotency | Repeat `POST …/recordings` → `202`, same session id, same `start_operation_id`; operation count unchanged; LiveRecording uninterrupted and unduplicated |
| `00:47:39.650` | Canonical stop | `POST …/recordings/{session}/stop` → `202`; `desired_state=stopped`; stop operation created |
| `00:47:42.390` | Store-preserving provider stop / `RecordingFinished` | Durable receipt for the same recording name |
| `00:47:42.814` | Provider store continuity | LiveRecording `404`; StoredRecording `200`; spool artifact retained |
| `00:47:42` | Canonical stopped convergence | RecordingSession `desired_state=stopped`, `observed_state=stopped`, `stopped_at=00:47:42`; stop operation `succeeded`, `attempt_count=1` |
| `00:47:42.844` | Stop idempotency | Repeat stop → `202`, still `stopped/stopped`, no second stop operation, no provider retry |
| `00:48:02` | Natural termination | Both Asterisk instances logged `lack of audio RTP activity in 30 seconds`; `ChannelHangupRequest`, `StasisEnd`, `ChannelDestroyed` |
| `00:48:10` | Final canonical state | Call and CallLeg `terminated/completed`, `termination_reason=remote`; RecordingSession unchanged at `stopped/stopped` |

## Exactly-once and idempotency counts

Final durable counts for CallLeg `7995cc63-e028-45a8-9123-c5203224772f`:

```text
call.leg.start_recording total = 1
call.leg.stop_recording  total = 1
recording_sessions for call    = 1
```

The counts were `0` start operations before recording eligibility, `1` after
natural answer, and still `1` after the idempotent start replay. The stop count
was `1` after the canonical stop and still `1` after the idempotent stop replay.
Neither replay produced a duplicate provider recording, a second LiveRecording,
or a canonical state regression.

## Store-preserving Asterisk stop

The adapter's canonical stop route is
`POST /ari/recordings/live/{recordingName}/stop`
(`apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriClient.php`), not the
Asterisk cancel/discard operation `DELETE /ari/recordings/live/{recordingName}`.
The live provider outcome matches store semantics rather than cancel semantics:

```text
GET /ari/recordings/live/7995cc63-e028-45a8-9123-c5203224772f        → 404
GET /ari/recordings/stored/7995cc63-e028-45a8-9123-c5203224772f      → 200 {"name":"7995cc63-…","format":"wav"}
GET /ari/recordings/stored/7995cc63-e028-45a8-9123-c5203224772f/file → 200 audio/wav
```

The filesystem artifact persisted after the canonical stop and after natural
call termination:

```text
/var/spool/asterisk/recording/7995cc63-e028-45a8-9123-c5203224772f.wav
regular file, 44 bytes, asterisk:asterisk, mode 0644
RIFF … WAVE fmt  PCM, 1 channel, 8000 Hz, 16-bit, data chunk length 0
```

The container is a valid retained WAV. Its PCM payload is empty because the
maintained `9900` Echo fixture carries no inbound audio RTP on this corridor:
both Asterisk instances independently logged
`rtp_check_timeout: Disconnecting channel 'PJSIP/anonymous-00000001' for lack of
audio RTP activity in 30 seconds`. Audio payload capture is a media-path
property of the fixture, not a recording authority or lifecycle property, and
was already proven separately at image level (`49324` bytes) in the
[recording-runtime capability evidence](rma-a-asterisk-recording-runtime-capability-and-preflight-closure.md)
where RTP was flowing. The RMA-A continuity assertion — stored rather than
canceled — is proven decisively here.

## Audit continuity

The durable chain is complete and self-consistent across authorities:

```text
call.created (actor=user)
→ call_leg.state_changed requested → originating (command-requested)
→ recording_session.requested (actor=user, operation_id=null)
→ ChannelCreated / Dial / ChannelStateChange Up / StasisStart
→ call.leg.offered and call.leg.answered runtime observations
→ call_leg.state_changed originating → answered (observation-confirmed)
→ call.leg.start_recording operation 68d588546b57ac2d09c2354d42f99361 (succeeded)
→ asterisk.ari.recording.started receipt 39519e6e5edb3783a2ab7608ba8dd539 (processed)
→ RecordingSession observed_state=recording
→ recording_session.stop_requested (actor=user, operation_id=aa5f5ea88fcffced0c3a3e8dcee04f4b)
→ call.leg.stop_recording operation aa5f5ea88fcffced0c3a3e8dcee04f4b (succeeded)
→ asterisk.ari.recording.finished receipt 4902f603b8aee9229da5eeb6b0d4fb80 (processed)
→ RecordingSession observed_state=stopped
→ call.leg.terminated observation
→ call.terminated and call_leg.terminated
```

Both recording events were normalized to `asterisk.ari.recording.started` and
`asterisk.ari.recording.finished` and reached `status=processed` on the single
open `asterisk-ari` event epoch `680862eaa4ab394355ebd8b37d2517f6` bound to the
same RuntimeNode.

## Acceptance matrix

| Boundary | Result |
| --- | --- |
| Stage-0 execution eligibility | PASS |
| Fresh canonical Call | PASS |
| Pre-answer RecordingSession | PASS |
| Zero premature start | PASS |
| Natural SIP answer | PASS |
| Canonical answered | PASS |
| Exactly-one automatic start | PASS |
| Provider WAV start | PASS |
| `RecordingStarted` | PASS |
| LiveRecording | PASS |
| Canonical active convergence | PASS |
| Start idempotency | PASS |
| Canonical stop | PASS |
| Store-preserving Asterisk stop | PASS |
| `RecordingFinished` | PASS |
| StoredRecording retained | PASS |
| Canonical stopped convergence | PASS |
| Stop idempotency | PASS |
| Complete audit continuity | PASS |

## Divergences

Two non-invalidating divergences were observed and classified.

The stored WAV has a zero-length PCM payload because the Echo fixture corridor
carried no audio RTP. This is a fixture media property, is independently
explained by both Asterisk RTP-timeout notices, and does not affect recording
authority, dispatch, provider store semantics, or canonical convergence.

Canonical `answered` was projected at `00:47:38` while the provider channel
reached `Up` at `00:47:32.234`. This is normal event-projection latency in the
established observation pipeline, is consistent with the `processed_at` lag on
the same epoch's receipts, and does not weaken exactly-once dispatch: the single
start operation was created only after canonical eligibility, and zero start
operations existed before it.

## Scope

No archival, MinIO/S3, retention, playback, or download behavior is implemented
or claimed. RMA-B through RMA-H remain not started.
