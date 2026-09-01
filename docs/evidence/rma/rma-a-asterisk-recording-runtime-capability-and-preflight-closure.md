# RMA-A Asterisk recording runtime capability and preflight closure

Current-State-Impact: yes

## Result

The repository declares and validates the static Asterisk recording capability,
and the provider-native smoke now passes stored WAV artifact finalization after
the fixture was corrected to use the store-preserving ARI stop operation. The
bounded provider-capability packet is closed locally. Immutable image
publication and native-k3s deployment remain intentionally deferred to the next
review step.

## Baseline

- Branch: `main`
- Starting HEAD and `origin/main`: `9652484638bccfecb1bb59a39848ee041aba1731`
- Starting worktree: clean
- Prior blocker: ARI channel recording returned `No such file or directory`
  because the recording spool directory was absent.

## Implemented capability contract

The Asterisk image now provisions the effective standard spool path
`/var/spool/asterisk/recording`, owned by `asterisk:asterisk`, mode `0750`,
while retaining `autoload = no`. `res_stasis_recording.so`,
`res_ari_recordings.so`, and `format_wav.so` are explicit module loads.
Readiness verifies the directory and performs a create/remove probe as the
runtime UID, verifies all explicitly loaded modules are Running, and requires
the `wav`/`wav16` format registration.

`scripts/asterisk-ari/recording-runtime-preflight` and its mutation test cover
the image/configuration contract. The provider smoke target uses the real ARI
channel recording start and live-recording stop operations with `format=wav`.

## Validation evidence

- Static preflight: `ASTERISK_RECORDING_RUNTIME_CAPABILITY_STATIC_PASS`.
- Configuration mutation tests: pass, including removal of each required
  recording module and spool provisioning.
- Built local image checks: UID 1000 (`asterisk`), recording directory exists
  with the expected ownership/mode, `format_wav.so` is Running, and `wav` and
  `wav16` are registered.
- ARI provider smoke reached a real answered channel, returned a WAV recording
  resource (`200`/`queued`), exposed it as live (`200`), and stopped it (`204`).
- RTP packets were observed by Asterisk during the smoke.

## Historical blocker and confirmed cause

The prior smoke used `DELETE /ari/recordings/live/<name>`. In Asterisk 20.20.1
that is the cancel operation: it stops the live recording and discards the
recorded file. This caused the stored-resource query to return `404 Recording
not found` and left the recording directory empty. The provider smoke now uses
`POST /ari/recordings/live/<name>/stop`, which stops and stores the recording.

`RecordingFinished` remains a required lifecycle synchronization point, but it
is not treated as sufficient persistence proof in the tested Asterisk version.
The smoke continues to require StoredRecording visibility, stored-file API
visibility, a regular non-empty filesystem WAV, and cleanup.

The corrected composite run recorded the following evidence for the generated
recording `utcp-recording-runtime-7a099abc9517`:

- recording start requested: `1788297978.272520749`
- `RecordingStarted`: `1788297978.2814476`, format `wav`, state `recording`
- stop `204`: `1788297981.407533030`
- `RecordingFinished`: `1788297981.3942773`, format `wav`, state `done`
- StoredRecording visibility: `1788297981.518980472`, HTTP `200`
- filesystem visibility: `1788297981.578862240`
- filesystem artifact: `/var/spool/asterisk/recording/utcp-recording-runtime-7a099abc9517.wav`
- artifact size: `49324` bytes
- stored-file endpoint: HTTP `200`
- cleanup: HTTP `204`

The event observation timestamp precedes the stop response timestamp by about
13 ms because Asterisk emits the completion event while the HTTP stop request
is completing. This does not weaken the ordering contract: the smoke requires
the stop request to succeed and then requires the matching completion event and
all durable artifact representations.

## Scope and deployment

No UTCP domain, RecordingSession, SIP, NetworkPolicy, FreeSWITCH, storage, or
retention behavior was changed. The source changes are not yet published into
an immutable image and were not deployed to native-k3s; those actions are
deferred pending implementation review. The known generic
`make asterisk-ari-config-check` failure remains unrelated in
`CallDomainService.php`.

## 2026-09-01 Initial RecordingFinished synchronization attempt (superseded)

This bounded follow-up started from local commit
`e9151e296be53955fcf1ab1d807aea6daa08ab24` on `main`, with a clean worktree.
The provider smoke previously asserted the stored resource immediately after
the live-recording stop response. That assertion was premature as a lifecycle
contract: ARI `204` accepts the stop request, but recording finalization is
asynchronous. The smoke now keeps an ARI Stasis WebSocket listener, captures
matching `RecordingStarted`, `RecordingFinished`, and `RecordingFailed` events
by exact generated recording name, and waits within a finite 30-second
convergence deadline for the finished event, inactive live resource, stored
resource, stored-file endpoint, and non-empty filesystem artifact.

The repair proved the matching `RecordingStarted` event, a live recording, and
the matching `RecordingFinished` event. It also proved that the live recording
was no longer active after the stop `204`. However, after the bounded window,
`GET /ari/recordings/stored/<exact-name>` still returned `404 Recording not
found`, the stored-file endpoint could not be proven, and the expected
`/var/spool/asterisk/recording/<exact-name>.wav` artifact was not visible.
The result is therefore the narrow verdict
`RMA_A_ASTERISK_PROVIDER_RECORDING_FINALIZED_BUT_STORED_ARTIFACT_MISSING`.
This is not evidence of a missing spool directory, WAV module, format
registration, or runtime-user permission defect. The smoke now records the
event and endpoint state, timestamps, filesystem listing, channel state, and
RTP diagnostics for this boundary. No speculative Asterisk configuration,
RecordingSession, storage, network policy, or architecture change was made.

This initial synchronization-only attempt is retained as historical evidence
of the observed boundary. It was superseded by the store-preserving ARI stop
correction documented above; no immutable image was published and native-k3s
was not deployed in either attempt.
