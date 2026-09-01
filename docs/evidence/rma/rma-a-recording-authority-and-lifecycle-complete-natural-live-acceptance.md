# RMA-A Recording Authority and Lifecycle Complete Natural-Live Acceptance

Current-State-Impact: yes

## Verdict

`RMA_A_ASTERISK_RECORDING_START_BLOCKED_WAV_FORMAT_MODULE_NOT_LOADED`

The fresh deployed acceptance proves the NetworkPolicy repair, SIP delivery,
natural answer, canonical CallLeg projection, pre-answer recording deferral,
and exactly-one automatic start-operation dispatch. The first new downstream
blocker is the Asterisk recording format configuration: the adapter requests
`format=wav`, but the active Asterisk explicitly disables module autoloading
and does not load the installed `format_wav.so`. Asterisk returned HTTP 422 to
the channel-record request.

This is not a pass for the complete RMA-A lifecycle. No source, Kubernetes
configuration, database, Redis, or provider state was changed during this
acceptance run.

## Repository and runtime baseline

The run began on `main` at
`163f3f121ec739ff747a55fd3c4806ae8f01ad44` with a clean worktree and matching
`origin/main`/remote `refs/heads/main`. That commit is the accepted
same-namespace Asterisk UDP/5060 NetworkPolicy repair.

The native-k3s cluster had both Nodes Ready. The originating RuntimeNode used
Pod `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6864fkk5mf`
(`10.42.0.43`); the sole `asterisk-sip` endpoint was
`asterisk-ari-7c6d4d4868-xq8ff` (`10.42.0.44`). Both used
`ghcr.io/grimange/utcp-asterisk@sha256:1865f34b6887b967b61505af4416d6dbdc9c3123dca7d6bfd20db57ff7fdb1fc`
and carried the deployed `app.kubernetes.io/component=asterisk-ari` and
`utcp.io/network-role=asterisk-ari` labels. The `asterisk-sip` Service
ClusterIP was `10.43.190.58`, UDP/5060, with that sole ready endpoint.

The live `allow-asterisk-sip-from-kamailio` policy retained default deny and
its additive ingress/egress peer rules selected exactly that component/role on
UDP/5060. No policy was edited or bypassed.

## Fresh canonical lifecycle evidence

The first fresh acceptance transaction established the intended timing:

| Entity | Identifier |
| --- | --- |
| Call | `6902d57d-7bf3-408b-ba2b-0c734e86246f` |
| CallLeg | `58a13889-29c8-43fb-88f3-a651b9e59104` |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-58a13889-29c8-43fb-88f3-a651b9e59104` |
| Originate operation | `a85e56bfc200bc3fd21b8e1b5ead0095` |
| RecordingSession | `1e59246f6ae56192f8c73ed58fc0dd7c` |
| Start operation | `2626baeb7fa680a806955699cdd60f16` |

At the recording request, the Call and CallLeg were `originating`; the
canonical API returned 202 with `desired_state=recording`,
`observed_state=requested`, and `start_operation_id=null`. There was no
`call.leg.start_recording` operation. The leg naturally became `answered`; the
reconciler then created exactly one start operation and bound it to the
RecordingSession. It terminally failed at the provider boundary, so no stop
operation, active-state idempotency, recording-start observation, or stop
convergence was attempted.

## Persistent post-repair SIP and answer proof

A second fresh transaction was captured in an Asterisk-owned temporary
filesystem logger channel before submitting the canonical API request. The
channel was verified writable, PJSIP logging and ARI debug were enabled, and
then disabled after retrieval; the dynamic logger channel was removed. No
module was force-unloaded and no permanent diagnostic configuration remains.

| Entity | Identifier |
| --- | --- |
| Call | `5250eadf-e815-4c87-8a6c-448291752367` |
| CallLeg | `910a9ed3-2047-4f1c-bcba-99ac165c3bc7` |
| Runtime channel | `utcp-call-leg-910a9ed3-2047-4f1c-bcba-99ac165c3bc7` |
| Originate operation | `8bce04dc133fe8a2c748f12d05b1c98f` |
| RecordingSession | `63cf5776f71c23c0f8dbc72e3b8cf4fd` |
| Start operation | `9b7abbb6418c1bddb4fc980737222bd2` |

At `2026-09-01T09:41:37Z`, Asterisk accepted:

```text
POST /ari/channels?endpoint=PJSIP/anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060&app=utcp-t0-observation&timeout=30&channelId=utcp-call-leg-910a9ed3-2047-4f1c-bcba-99ac165c3bc7&formats=ulaw
```

with HTTP 200. The resulting `PJSIP/anonymous-00000009` channel entered the
UTCP Stasis application. Its persistent PJSIP trace shows an outbound INVITE
to `UDP:10.43.190.58:5060`, Call-ID
`782c0dc0-28ed-4338-b0ef-66a0b88cd4e6`, Request-URI
`sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060`. It received
`100 Trying`, then `200 OK` from the Service; the Contact identified the ready
destination Pod at `10.42.0.44:5060`, and the originator sent an ACK directly
to that endpoint.

The originator emitted `ChannelStateChange` with `state=Up` at
`09:41:37.213Z`. The normal event path processed the `call.leg.answered`
observation at `09:41:45Z`, and the CallLeg naturally became `answered`.
The destination's accepted `200 OK`, the loaded fixed route
`9900 -> NoOp -> Answer -> Echo -> Hangup`, and the sustained answered channel
prove the maintained fixture's answer path was used. The primary persistent
capture was originating-side, so this evidence does not claim a separately
retained destination-console dialplan line.

The recording request was made at `09:41:34Z`, while the leg was still
`originating`; it returned 202 with `requested` observed state and a null start
operation. Reconciliation created exactly one start operation at `09:41:45Z`.
No second client recording request was made.

## Exact recording-start failure

At `09:41:46Z`, the persistent ARI trace recorded the actual provider request:

```text
POST /ari/channels/utcp-call-leg-910a9ed3-2047-4f1c-bcba-99ac165c3bc7/record?name=910a9ed3-2047-4f1c-bcba-99ac165c3bc7&format=wav&ifExists=overwrite
```

Asterisk returned:

```text
HTTP 422 Unprocessable Entity
```

The temporary `res_ari` trace preserved the raw status but did not emit the
response JSON body; no credentials or raw log file were retained in the
repository. This is an evidence gap for the response message only, not for the
format mechanism: immediately after the failure, `core show file formats`
listed only `g722`, `ulaw`, `alaw`, and PCM variants, with no `wav` format;
`module show like format_wav.so` showed no loaded module, while
`/usr/lib/asterisk/modules/format_wav.so` exists in the image.

The active `/tmp/utcp-asterisk/modules.conf` and its repository authority,
`infrastructure/docker/asterisk/config/modules.conf`, both set `autoload = no`
and explicitly load `format_pcm.so` but omit `format_wav.so`. Meanwhile
`AsteriskAriClient` constructs every `call.leg.start_recording` request with
`format=wav` and `ifExists=overwrite`. The request was made after the channel
was Up and in Stasis; no canonical bridge relationship was present. This
excludes the earlier pre-answer/Stasis 409 and does not match a recording-name
collision response.

The RuntimeOperation consequently became `terminal_failed` with
`failure_class=conflict`, `failure_code=ari_resource_conflict`, and generic
message `ARI resource conflict.` The status is not semantically faithful to
the captured raw 422. Although repository code contains terminal failure
projection, this live session remained `observed_state=requested` with null
failure fields after the terminal start failure. That secondary live divergence
is recorded for the bounded repair packet; it was not repaired or masked.

## Authority and bounded next implementation

The primary failure belongs to the repository-owned Asterisk explicit module
configuration, not to RecordingSession eligibility, NetworkPolicy, SIP
delivery, the fixture, bridge-recording semantics, or canonical CallLeg state.
The smallest repair is to load `format_wav.so` in the explicit Asterisk module
set and add the focused configuration proof. The same bounded packet must
preserve a meaningful non-conflict mapping for ARI 422 and prove that a
terminal subordinate start failure projects to its RecordingSession in the
deployed worker path. It must not introduce Stasis/PBX-specific canonical
state or change channel-recording to bridge-recording.

## Remaining RMA-A proof

The following remains unproven after the first exact blocker:

1. successful provider channel recording start;
2. recording-start observation and canonical active convergence;
3. active/start idempotency;
4. canonical stop and provider recording stop;
5. stopped observation/convergence and stop idempotency;
6. complete operation/audit continuity through the stopped lifecycle.

## Validation and scope

No source/configuration was changed. The required documentation checks for
this evidence packet passed:

* `make phase-status-consistency-check`;
* `make repository-hygiene`;
* `git diff --check`.

The known generic `make asterisk-ari-config-check` scan remains outside this
acceptance scope and was not used to classify the RMA-A result. NetworkPolicy,
fixture, RecordingSession/application source, ARI recording primitive,
FreeSWITCH, storage, and all later RMA phases were untouched.

**Exactly one next action:** have GPT-5.6 Luna Medium implement the bounded
Asterisk `format_wav.so` module-loading, ARI-422 normalization, and terminal
RecordingSession-failure-projection repair packet; deploy it canonically and
then repeat this complete fresh RMA-A natural-live acceptance.
