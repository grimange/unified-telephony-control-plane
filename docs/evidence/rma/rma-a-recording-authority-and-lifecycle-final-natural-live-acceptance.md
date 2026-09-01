# RMA-A Recording Authority and Lifecycle Final Natural-Live Acceptance

Current-State-Impact: yes

## Verdict

`RMA_A_ASTERISK_RECORDING_START_BLOCKED_MISSING_RECORDING_SPOOL_DIRECTORY`

This fresh acceptance did not reproduce the repaired WAV capability failure.
Both the originating dynamic Asterisk RuntimeNode and the destination Asterisk
reported `format_wav.so` as Running and registered `wav` and `wav16`. The
post-answer channel-record requests instead reached Asterisk's ARI recording
handler and failed with the exact runtime error `No such file or directory`.
The live source Pod has `/var/spool/asterisk/monitor` but lacks
`/var/spool/asterisk/recording`, the directory required by Asterisk's live
recording implementation. This is a bounded repository-owned Asterisk image
filesystem-provisioning defect, not a RecordingSession, SIP, NetworkPolicy, or
WAV module capability regression.

No source, Kubernetes configuration, database, Redis, or provider state was
changed in this acceptance run.

## Repository and deployed baseline

The run began on `main` at
`6cc91cd0c53a00ee123341afda3a4fc1ae3dc378`, with matching `origin/main` and
remote `refs/heads/main`, and a clean worktree. The accepted bounded repair is
source commit `0d3a6d538712608985679d2b458d1c39bafd7472`.

The live native-k3s Pods used the published immutable images:

- API: `ghcr.io/grimange/utcp-api@sha256:8436c9d497d8707bcf09d0c2b581e70fd52477992b462ffc8140fb82dcd440ea`
- Asterisk: `ghcr.io/grimange/utcp-asterisk@sha256:a6dbe7b4d6c3afb2860f66e43fba119481009b9876ec0cf149343ea6a414972e`

Both Kubernetes Nodes were Ready. The source RuntimeNode Pod was
`asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-9f5dbrv629`
(`10.42.1.104`) and the destination Asterisk Pod was
`asterisk-ari-64778bbbf9-2nmnc` (`10.42.1.103`). The `asterisk-sip` Service
remained `10.43.190.58`, UDP/5060, with that sole ready EndpointSlice backend.
Both Asterisk Pods carry the accepted component and network-role labels. The
runtime namespace still contains `default-deny` and the additive
`allow-asterisk-sip-from-kamailio` policy; no security bypass was used.

## WAV capability and storage inspection

Using the deployed Asterisk configuration, both involved Asterisk processes
reported:

```text
format_wav.so  Microsoft WAV/WAV16 format  Running
slin16 wav16 wav16
slin   wav   wav
```

The dynamic source Pod's configured `astspooldir` is `/var/spool/asterisk`.
Read-only filesystem inspection showed the standard `monitor`, `outgoing`,
`voicemail`, `fax`, and `tmp` directories, owned by `asterisk:asterisk`, but
`/var/spool/asterisk/recording` does not exist. The repository Asterisk
Dockerfile creates only the configuration and sound directories; it does not
create the live-recording spool directory. This directly correlates with the
ARI recording handler's ENOENT diagnostic below.

## Fresh canonical lifecycle

| Entity | Identifier |
| --- | --- |
| Call | `c52ea4fe-392b-484f-993e-e17070608d94` |
| CallLeg | `e6746152-e281-416e-9142-a7956355002e` |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-e6746152-e281-416e-9142-a7956355002e` |
| Originate RuntimeOperation | `ad77a052b9fd30736ce3e53dbd9412df` |
| RecordingSession | `d554f0f2768082b0e45ab244e4c12f5e` |
| Start RuntimeOperation | `f2714e77e029899763e964e09e66d86e` |

The canonical Call was accepted at `2026-09-01T10:36:16.998Z`; the canonical
RecordingSession request was accepted at `10:36:17.288Z`, while the CallLeg was
still `originating`. The session was durable with
`desired_state=recording`, `observed_state=requested`, and
`start_operation_id=null`; the only RuntimeOperation was the pending originate
operation. Thus there were zero pre-answer `call.leg.start_recording`
operations.

The CallLeg naturally reached `answered`. The reconciler created exactly one
start operation at `10:36:24Z` and atomically bound it to the RecordingSession;
no second recording request was made. The start worker first attempted it at
`10:36:25Z`, retried at `10:36:40Z`, and the CallLeg naturally completed at
`10:36:51Z` after Asterisk disconnected the Echo channel for 30 seconds of no
RTP. This confirms pre-answer deferral and exactly-once answer reconciliation
remain intact.

## Provider failure and canonical projection

The source Asterisk Pod recorded the two in-flight ARI record attempts:

```text
2026-09-01 10:36:25 ast_ari_channels_record: Unrecognized recording error: No such file or directory
2026-09-01 10:36:40 ast_ari_channels_record: Unrecognized recording error: No such file or directory
```

The adapter's canonical request remains channel recording with
`format=wav` and `ifExists=overwrite`. The first two provider responses were
classified retryable as `runtime_unavailable`; the running Asterisk logger did
not retain their HTTP response bodies. The later third retry occurred only
after channel termination and returned the expected downstream
`ari_resource_not_found` / HTTP 404 outcome. It is not the first failure.

At `10:37:10Z` the subordinate start operation became `terminal_failed` after
its third attempt. At `10:37:11Z` the linked RecordingSession converged
automatically to `observed_state=failed`, retaining `desired_state=recording`,
`failure_class=conflict`, `failure_code=ari_resource_not_found`, and
`failure_message=ARI resource was not found.` This naturally proves the
deployed terminal-failure projection wiring; it does not misrepresent the
terminal retry's downstream 404 as the initial ENOENT root cause.

No active recording was created, so active-state convergence, start
idempotency, provider recording existence, canonical stop, provider stop,
stopped convergence, and stop idempotency were correctly not manufactured.

## Authority and bounded next repair

The smallest repair target is
`infrastructure/docker/asterisk/Dockerfile`: declaratively create
`/var/spool/asterisk/recording` owned by `asterisk:asterisk` in the repository
Asterisk image, with focused image/configuration regression coverage. The
repair must not alter the `format=wav` contract, module hardening,
RecordingSession lifecycle, Echo fixture, SIP policy, channel-recording
primitive, or canonical state model. A new immutable Asterisk image and a
fresh Terra natural-live acceptance are then required.

## Scope and validation

No implementation files were changed. Evidence/status-only validation is
recorded with this packet. The known generic ARI configuration scan and missing
local PHPUnit executable remain unrelated validation debt and were not used to
classify this live provider blocker.

**Exactly one next action:** have GPT-5.6 Luna Medium add the missing
repository-owned Asterisk live-recording spool directory, publish/deploy its
immutable image, and preserve the existing least-privilege module and network
configuration.

Current-State Ledger Impact: UPDATED
