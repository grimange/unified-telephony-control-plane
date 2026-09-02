# RMA-B Capture Reference Direct Derivation Focused Live Reproof — PASSED

Current-State-Impact: yes

**Date:** 2026-09-02
**Environment:** native k3s, context `default`, nodes `utcp-dev01` / `utcp-dev02`,
namespaces `utcp-platform` and `utcp-runtime`
**Verdict:** `RMA_B_CAPTURE_REFERENCE_DIRECT_DERIVATION_FOCUSED_LIVE_REPROOF_PASSED`

One fresh canonical transaction proved the repaired identity contract live:

```text
RecordingSession.id = X
= capture_ref identifier
= Asterisk provider capture suffix
= normalized observation identifier
= targeted RecordingSession
```

The previously failing boundary is closed. All RMA-B regression boundaries
remained intact. No source, configuration, capability, schema, deployment, or
runtime state was changed by this reproof; provider inspection was read-only.

## Repository and deployed baseline

The run began and ended on `main` at
`8113245e800166fb6e0177ccd911ffa218c8028a`, matching `origin/main` and remote
`refs/heads/main`, with a clean worktree. The deployed application source is the
repaired revision `8e77cdc78251ebee88f461b2775745f00d4de63a`.

Verified running image IDs matched the expected repaired digests exactly:

- API (`api`, `telephony-command-worker`, `telephony-event-normalizer`,
  `asterisk-ari-events`, `telephony-reconciler`, `control-plane-outbox-dispatcher`):
  `ghcr.io/grimange/utcp-api@sha256:050c0cc8b8dcb5f6413fb446eb86ec66bd7ba5b9e6f44b6aa4218ba6bbdd9820`
- Asterisk (`asterisk-ari`, managed RuntimeNode Pod):
  `ghcr.io/grimange/utcp-asterisk@sha256:c303c574a01ca0d2bd8153e84505781685839505f67a124fe1ffde5982afd577`

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f`: `desired_state=active`,
`observed_state=ready`, `configuration_version=33 = observed_configuration_version=33`,
desired execution image equal to observed
(`sha256:c303c574…afd577`), and the `recording` capability present. No RuntimeNode
state was refreshed, reconciled, or mutated.

A read-only probe of the deployed code confirmed the derivation before the
transaction: `CaptureReference::forRecordingSession('0123…cdef')` returned
`utcp:capture/0123456789abcdef0123456789abcdef` and provider reference
`utcp-capture-0123456789abcdef0123456789abcdef`, while the previous md5 behavior
would have produced `utcp:capture/8516ac99dc60603295de7bdb6a153530`. The deployed
factory now also rejects a non-32-hex argument with `invalid_capture_ref`.

## Fresh canonical entities

| Entity | Identifier |
| --- | --- |
| Call | `1572e466-4cfa-4cc3-8872-418862af0ede` |
| CallLeg | `7694b7b0-8d31-4f9e-aeb0-b06f5452e810` |
| Runtime channel | `utcp-call-leg-7694b7b0-8d31-4f9e-aeb0-b06f5452e810` |
| Originate operation | `31ae659627f5f3fc72d68a53fe85698d` |
| RecordingSession (`X`) | `9c0c895f17aa1ea65689932919011133` |
| Start operation | `0fb3f5dedfda88f5bfb79917329440aa` |
| Stop operation | `226e1302f8f75ddba169ccf4646865a0` |
| Provider capture name | `utcp-capture-9c0c895f17aa1ea65689932919011133` |
| `RecordingStarted` receipt | `7a9ef9db17d60c7cd8f40c57de9b4e50` |
| `RecordingFinished` receipt | `ffc69ae11ad4d64413761f309b8cb74b` |
| `recording_started` observation | `98a77a7cf7d8997200055127eb4bc6d0` |
| `recording_stopped` observation | `09d06adba2185dc69f0634b47a55d710` |

The RecordingSession was created pre-answer at `04:52:01` while the CallLeg was
still `originating`, with `start_operation_id=null`.

## Direct capture identity — principal invariant

```text
RecordingSession.id X = 9c0c895f17aa1ea65689932919011133
capture_ref           = utcp:capture/9c0c895f17aa1ea65689932919011133
md5(X) would be       = utcp:capture/85c7602d956df9f3880d6980f816ad96
```

Both durable capture operations carry the identical reference, and the
identifier after `utcp:capture/` is byte-for-byte equal to `RecordingSession.id`:

| Operation | `capture_ref` identifier | `== X` | `== md5(X)` | `recording_session_id` |
| --- | --- | --- | --- | --- |
| `call.leg.start_recording` | `9c0c895f17aa1ea65689932919011133` | YES | NO | `9c0c895f17aa1ea65689932919011133` |
| `call.leg.stop_recording` | `9c0c895f17aa1ea65689932919011133` | YES | NO | `9c0c895f17aa1ea65689932919011133` |

## Canonical payload purity

Both capture payloads contain exactly
`call_id`, `capture_ref`, `leg_id`, `recording_session_id`, `requested_by_user_id`.
An explicit check for `format`, `wav`, `ifExists`, `recording_name`, `ari_route`,
`storage_path`, `bucket`, `artifact_uri`, and `provider_action` returned an empty
set.

## Asterisk provider identity

The live provider resource while active was:

```json
{"name":"utcp-capture-9c0c895f17aa1ea65689932919011133","format":"wav","state":"recording",
 "target_uri":"channel:utcp-call-leg-7694b7b0-8d31-4f9e-aeb0-b06f5452e810"}
```

Three read-only provider polls ran concurrently for the whole transaction:

| Candidate provider name | Result |
| --- | --- |
| `utcp-capture-9c0c895f17aa1ea65689932919011133` (`= X`) | **200**, live then stored |
| `utcp-capture-85c7602d956df9f3880d6980f816ad96` (`= md5(X)`) | `404` throughout |
| `7694b7b0-8d31-4f9e-aeb0-b06f5452e810` (CallLeg id) | `404` throughout |

The spool contained exactly one artifact,
`utcp-capture-9c0c895f17aa1ea65689932919011133.wav`. This proves live, from the
provider rather than from source inspection, that the md5 indirection is gone and
the CallLeg-ID naming authority remains removed.

`format=wav` and `ifExists=overwrite` behavior was preserved; the operation
succeeded on `attempt_count=1`. No direct ARI recording start was issued.

## Neutral runtime result

Both operation completion events carried the canonical reference:

```json
{"adapter_key":"asterisk-ari","operation_type":"call.leg.start_recording","provider_action":"channels.record",
 "runtime_capture_reference":"utcp:capture/9c0c895f17aa1ea65689932919011133","runtime_channel_id":"utcp-call-leg-7694b7b0-…"}
{"adapter_key":"asterisk-ari","operation_type":"call.leg.stop_recording","provider_action":"recordings.stop",
 "runtime_capture_reference":"utcp:capture/9c0c895f17aa1ea65689932919011133","runtime_channel_id":"utcp-call-leg-7694b7b0-…"}
```

No path, size, duration, checksum, bucket, storage URI, or archive-target field
appears.

## Channel-less provider events and direct correlation

Both provider receipts were confirmed to carry **no** `channel_id` and **no**
`call_leg_id`, and both named the direct capture identity:

```text
RecordingStarted  7a9ef9db17d60c7cd8f40c57de9b4e50  occurred 04:52:25  channel_id=ABSENT call_leg_id=ABSENT
  name=utcp-capture-9c0c895f17aa1ea65689932919011133
RecordingFinished ffc69ae11ad4d64413761f309b8cb74b  occurred 04:52:28  channel_id=ABSENT call_leg_id=ABSENT
  name=utcp-capture-9c0c895f17aa1ea65689932919011133
```

Both normalized into canonical CallLeg observations whose identifier equals `X`
and whose subject is the exact CallLeg:

```text
98a77a7cf7d8997200055127eb4bc6d0  call.leg.recording_started
  subject=call_leg/7694b7b0-8d31-4f9e-aeb0-b06f5452e810   capture_ref=utcp:capture/9c0c895f17aa1ea65689932919011133
09d06adba2185dc69f0634b47a55d710  call.leg.recording_stopped
  subject=call_leg/7694b7b0-8d31-4f9e-aeb0-b06f5452e810   capture_ref=utcp:capture/9c0c895f17aa1ea65689932919011133
```

The full live chain is therefore one identity:

```text
provider name suffix  = 9c0c895f17aa1ea65689932919011133
canonical capture_ref = 9c0c895f17aa1ea65689932919011133
observation identifier= 9c0c895f17aa1ea65689932919011133
RecordingSession.id   = 9c0c895f17aa1ea65689932919011133
```

The deployed source resolves this by primary key
(`->where('id', $identifier)` in both `AsteriskAriEventNormalizer::normalizeRecordingEvent()`
and `RecordingSessionService::applyObservation()`); the previous scan-and-rehash
correlation is removed. No database tracing was enabled and no instrumentation
was added.

## Lifecycle convergence and regression boundaries

| Time (UTC) | Event |
| --- | --- |
| `04:52:01` | RecordingSession created pre-answer, `recording/requested`, `start_operation_id=null` |
| `04:52:22` | Natural CallLeg `answered` |
| `04:52:23` | Exactly one `call.leg.start_recording` created |
| `04:52:24` | Start operation `succeeded`; session `recording/recording` |
| `04:52:26` | Canonical stop; `desired_state=stopped`; stop operation created |
| `04:52:28` | Stop operation `succeeded`; session `stopped/stopped` |
| `04:52:29` / `04:52:32` | Recording receipts processed |
| `04:52:30` / `04:52:33` | Canonical `recording_started` / `recording_stopped` observations recorded |
| `04:52:58` | Natural call termination; session unchanged `stopped/stopped` |

Final durable counts: `start_recording=1`, `stop_recording=1`,
`recording_sessions=1`, after both idempotent replays (start replay returned the
same session, `capture_ref`, and `start_operation_id`; stop replay returned the
same `stop_operation_id` with state still `stopped`).

Store-preserving stop was confirmed: after the canonical stop the live recording
returned `404` while the stored recording returned `200` and the spool artifact
was retained. No `DELETE /ari/recordings/live/...` request occurred.

Observation monotonicity reproduced naturally without being forced: both
recording observations were recorded at `04:52:30` and `04:52:33`, **after** the
session had already converged to `stopped` at `04:52:28`. The late
`recording_started` did not resurrect the stopped session, and the state stayed
`stopped/stopped` through natural termination.

## Sequential-session collision boundary

The identifier is the RecordingSession primary key, so distinct sessions yield
distinct capture references and distinct provider captures by construction
(`A != B → utcp:capture/A != utcp:capture/B → utcp-capture-A != utcp-capture-B`).
The CallLeg-ID name was proven `404` throughout. No second PBX transaction was
created; the repository mutation test remains authoritative for the two-session
case.

## FreeSWITCH boundary — regression preserved

The deployed catalog still reports
`freeswitch-esl.supported_capabilities = ["call.origination","call.control","call.hold","call.dtmf.send","media.playback"]`
with `recording` absent, and zero FreeSWITCH RuntimeNodes exist. A source check
confirms no capture arm, `uuid_record`, `RECORD_START`, or `RECORD_STOP` handling
has appeared in the FreeSWITCH adapter, ESL client, or event normalizer. No
capability was added, no RuntimeNode created, and no provider failure
manufactured.

## RMA-C boundary preserved

`call_legs.recording_ref` remained `null`, and a repository search found no
`recording_artifacts`, `media_archive_targets`, `archive_transfers`,
`RecordingArtifact`, or `MediaArchiveTarget` surface. No artifact size, duration,
checksum, bucket, object key, storage URI, archive target, or retention state was
created. Provider-side stored-file presence is provider evidence, not canonical
artifact authority. Media content was not evaluated.

## Acceptance matrix

| Boundary | Result |
| --- | --- |
| Correct repaired API digest deployed | PASS |
| Fresh RecordingSession | PASS |
| RecordingSession.id = X | PASS |
| start capture_ref = `utcp:capture/X` | PASS |
| capture identifier != md5(X) | PASS |
| provider name = `utcp-capture-X` | PASS |
| provider suffix = RecordingSession.id | PASS |
| CallLeg-ID fallback absent | PASS |
| start `runtime_capture_reference` = `utcp:capture/X` | PASS |
| channel-less `RecordingStarted` accepted | PASS |
| started observation `capture_ref` = `utcp:capture/X` | PASS |
| exact RecordingSession targeted | PASS |
| recording convergence | PASS |
| stop `capture_ref` = same `utcp:capture/X` | PASS |
| provider stop uses same `utcp-capture-X` | PASS |
| store-preserving stop | PASS |
| channel-less `RecordingFinished` accepted | PASS |
| stopped observation `capture_ref` = `utcp:capture/X` | PASS |
| stopped convergence | PASS |
| no state regression | PASS |
| FreeSWITCH unsupported boundary unchanged | PASS |
| no RMA-C authority leakage | PASS |

## Unrelated runtime condition

The originate operation `31ae659627f5f3fc72d68a53fe85698d` recorded
`attempt_count=2` with `last_failure_code=ari_http_transport_failed` on its first
attempt at `04:52:01`, then succeeded at `04:52:18` through the normal persisted
retry lifecycle, and the CallLeg answered naturally at `04:52:22`. This is a
transient ARI HTTP transport condition against a recently-started Asterisk Pod,
handled correctly by existing retry authority. It is unrelated to the capture
contract, touched no recording boundary, and did not require intervention.

## Scope

No implementation file was modified. No migration, capability, feature flag,
compatibility path, alternate correlation path, diagnostic management command, or
RMA-C behavior was introduced.
