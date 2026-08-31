# RMA-A — Asterisk Recording-Start Conflict Exact-Cause Audit

Current-State-Impact: yes

**Date:** 2026-08-31
**Repository:** `main` at
`9a019f0631b9aa6da743e6cd90e4fea9cb84394b`
**Environment:** native k3s, context `default`, control-plane node
`utcp-dev01` (`192.168.254.124`), namespaces `utcp-platform`,
`utcp-runtime`, and `utcp-data`
**Target RuntimeNode:** `102d58ba-93ec-4601-a2a3-81f95801440f`
**Deployed API source commit:** `f00e47ca206f50f3835093d290dda18d2a7a0646`
**Target Asterisk image:**
`ghcr.io/grimange/utcp-asterisk@sha256:3b142eb9213301665c6a173678b1337846c38cc6c0c6305eb3319dd74ca2bc9c`

## Verdict

`RMA_A_ASTERISK_RECORDING_START_BLOCKED_CHANNEL_NOT_IN_STASIS_ROOT_CAUSE_CONFIRMED`

A fresh canonical RecordingSession reached the deployed Asterisk adapter. The
adapter sent the expected channel-recording request while the exact target
channel still existed, but Asterisk returned HTTP `409 Conflict` with
`Channel not in Stasis application`. The target channel was still `Down`, had
never emitted `StasisStart`, was in no bridge, and had no live or stored
recording under the requested name. It remained present for about 27 seconds
after the rejected recording request and then ended unanswered. The conflict
was therefore not caused by channel destruction, bridge membership, recording
name collision, format validation, or identity authorization.

RMA-A remains implemented and repository-tested but is **not natural-live-
proven**. No repair was implemented by this audit.

## Scope and Method

This was a narrow evidence-only audit of the deployed
`call.leg.start_recording` boundary. The repository contract, adapter request,
error normalization, current Asterisk state, canonical PostgreSQL state, and
sanitized runtime events were inspected. The Call and RecordingSession were
created only through their supported authenticated APIs; no SQL/Redis
mutation, direct ARI record operation, manual channel/bridge manufacture,
forced projection, or persisted-outcome edit was used.

Transient Asterisk ARI debugging was enabled only on the exact managed runtime
Pod to expose the otherwise discarded response and was restored to off after
capture. The extraction excluded authorization material. It did not change the
request, response, classification, or any business state.

## Repository and Contract Findings

Current repository behavior is:

* `RecordingSessionService` durably creates a tenant-scoped session with
  `desired_state=recording` and `observed_state=requested`, then immediately
  creates a subordinate `call.leg.start_recording` RuntimeOperation.
* Start authority belongs to the canonical RecordingSession API. The generic
  call-operation API rejects direct recording start/stop mutation.
* The worker is asynchronous and reloads the CallLeg's current
  `runtime_channel_id` before adapter execution. The start path does not first
  require the CallLeg to have reached canonical `answered` state.
* `AsteriskAriClient` invokes `POST /channels/{channelId}/record` with the
  CallLeg ID as its default recording name, `wav` format, and
  `ifExists=overwrite`.
* The ARI wrapper accepts normal success statuses, maps both HTTP `409` and
  `422` to `failure_class=conflict` / `ari_resource_conflict`, and does not
  retain the raw status or Asterisk body in the exception or RuntimeOperation.
  Normal adapter logging records only the normalized class and operation type.
* Asterisk events normalize `StasisStart`, channel state, bridge entry/exit,
  and recording start/finish. A global ARI event subscription does not prove
  that an individual channel is owned by the Stasis application.

The existing RMA-A tests prove durable authority, idempotency, tenant
isolation, and the generic-operation cutoff. The route-level Asterisk test
proves the channel-record endpoint selection. They do not prove lifecycle
eligibility before start dispatch or retain individual raw ARI conflict causes.

## Official Asterisk Baseline

The official Asterisk channel contract defines
`POST /channels/{channelId}/record` as recording media from one channel and
documents HTTP `409` for distinct Stasis, bridge, and recording-name conflict
causes. The official bridge contract instead records mixed audio from all
channels in a bridge. Upstream Asterisk source emits the exact
`Channel not in Stasis application` response when the target exists but is not
controlled by Stasis. This audit used those distinctions only to interpret the
captured response; it did not change UTCP's recording target.

References:

* [Asterisk Channels REST API](https://docs.asterisk.org/Latest_API/API_Documentation/Asterisk_REST_Interface/Channels_REST_API/)
* [Asterisk Bridges REST API](https://docs.asterisk.org/Latest_API/API_Documentation/Asterisk_REST_Interface/Bridges_REST_API/)
* [Upstream ARI channel resource](https://github.com/asterisk/asterisk/blob/master/res/ari/resource_channels.c)

## Fresh Canonical Reproduction

The normal authenticated API and maintained Echo destination created:

```text
Call:                 2c789cb5-8e92-43b2-8a77-a3671ffef825
CallLeg:              91b51ec5-5f4f-4cda-a065-c2401750ab40
RuntimeNode:          102d58ba-93ec-4601-a2a3-81f95801440f
Runtime channel:      utcp-call-leg-91b51ec5-5f4f-4cda-a065-c2401750ab40
Originate operation:  d1353ac0cb0d0be06da887914db67e21
RecordingSession:     f479b3243126b836d113eb97dca8b294
Start operation:      98f59adaa4751c0319930fda352077d8
```

Call creation returned HTTP `201`; the asynchronous originate operation
succeeded after Asterisk accepted channel creation. Recording creation returned
HTTP `202` at `2026-08-31T23:38:48.179180238Z`. The session persisted as
`recording / requested`; the subordinate start operation ran once and ended
`terminal_failed / conflict / ari_resource_conflict`.

Originate-operation success here proves acceptance of channel creation, not an
answered call or Stasis ownership. The target stayed in the natural
originating phase and never became answered during this reproduction.

## Exact ARI Boundary

The sanitized request observed at the target runtime was:

```text
Method:       POST
Path:         /ari/channels/utcp-call-leg-91b51ec5-5f4f-4cda-a065-c2401750ab40/record
Channel ID:   utcp-call-leg-91b51ec5-5f4f-4cda-a065-c2401750ab40
Name:         91b51ec5-5f4f-4cda-a065-c2401750ab40
Format:       wav
ifExists:     overwrite
Body:         empty
```

No `maxDurationSeconds`, `maxSilenceSeconds`, `beep`, `terminateOn`, or other
recording option was supplied.

The exact result was:

```text
HTTP status:          409 Conflict
Asterisk message:     Channel not in Stasis application
Adapter exception:    AsteriskAriException
UTCP failure class:   conflict
UTCP failure code:    ari_resource_conflict
UTCP failure message: ARI resource conflict.
Operation status:     terminal_failed
Attempt count:        1
```

The normalized result was semantically correct for this response. The loss of
the raw status and distinct Asterisk cause after normalization is an
observability gap, not the failure mechanism established by this audit.

## Runtime State at Recording Execution

Read-only ARI observations immediately before and after the failed request
established:

| Fact | Immediately before | Immediately after |
| --- | --- | --- |
| Exact channel | HTTP `200`, present | HTTP `200`, still present |
| Asterisk channel name | `PJSIP/anonymous-00000004` | unchanged |
| Channel state | `Down` | `Down` |
| Stasis ownership | no `StasisStart`; application listing contained only the global `__AST_CHANNEL_ALL_TOPIC` subscription | unchanged |
| Bridges | empty list | empty list |
| Live recording by requested name | HTTP `404`, absent | HTTP `404`, absent |
| Stored recording by requested name | HTTP `404`, absent | HTTP `404`, absent |

The Asterisk event sequence for this channel contained channel creation,
dialplan/variable, and dialing facts but no `StasisStart`, bridge entry, channel
state transition to `Up`, or recording-start observation. A global event
subscription explains why UTCP could observe channel events without the
channel itself being in Stasis.

## Deterministic Timeline

| UTC time | Correlated event |
| --- | --- |
| `23:38:44` | Canonical Call and CallLeg persisted; originate RuntimeOperation created. |
| `23:38:46` | Worker executed ARI originate; Asterisk accepted channel creation and the operation succeeded. |
| `23:38:46.956` | Exact channel creation observed in state `Down`; no `StasisStart` followed. |
| `23:38:47` | Pre-record ARI reads found the exact channel present and `Down`, no bridge, and no per-channel Stasis ownership. |
| `23:38:48.179180238` | Canonical recording request accepted; RecordingSession and subordinate start operation persisted at database second `23:38:48`. Requested live/stored recording names were absent. |
| `23:38:49.997013853` | The worker sent the exact channel-record request; the operation's persisted `started_at` and `completed_at` are database second `23:38:49`. |
| `23:38:49.997195605` | Asterisk returned `409` / `Channel not in Stasis application`. The normalized worker failure log followed at `23:38:49.997394`; the operation terminal-failed on attempt one. |
| `23:38:51` | Post-response reads still found the exact channel present and `Down`, with no bridge and no recording resource. |
| `23:39:16.994` | Asterisk destroyed the channel with cause `19` (`User alerting, no answer`) and technology cause `480`. |
| `23:39:18` | UTCP processed the destruction; the Call and CallLeg were terminal with observed state `failed`. No `StasisStart`, `StasisEnd`, bridge entry, or bridge exit occurred during the channel lifetime. |

The ordering excludes a record-after-destruction race: the channel survived for
about 27 seconds after the ARI rejection. It instead proves that the recording
operation ran while the outbound channel existed but before it was
Stasis-owned or canonically answered. The maintained Echo path did not answer
in this run; diagnosing that routing behavior is outside this audit and is not
needed to identify this recording-start response.

## Root Cause and Exclusions

The exact mechanism is a lifecycle-readiness conflict. Canonical recording
intent immediately dispatched a subordinate start operation while the target
CallLeg remained `originating`. Its Asterisk channel existed in `Down` state but
had never entered the UTCP Stasis application, so channel recording was not yet
legal and Asterisk rejected it.

Competing hypotheses are excluded by direct evidence:

* **Channel gone:** excluded; it returned HTTP `200` before and after the
  rejection and was destroyed much later.
* **Bridge conflict:** excluded; bridge reads were empty and no bridge event
  occurred.
* **Name collision:** excluded; live and stored recording lookups returned
  `404` before and after, and UTCP requested `ifExists=overwrite`.
* **Format or request defect:** excluded; Asterisk returned the explicit Stasis
  message, not an invalid-format or malformed-request result.
* **Error-normalization defect:** excluded as the primary cause; the captured
  `409` is a genuine conflict, although raw-cause preservation is incomplete.
* **Channel-vs-bridge target defect:** excluded by current RMA-A authority; no
  bridge existed, and the canonical target was one CallLeg.

## Authority Resolution

RMA-A currently intends to record **one CallLeg/channel media stream**. A
RecordingSession requires a target CallLeg, may optionally correlate a
Conference, and creates a CallLeg-targeted runtime operation. The Asterisk
channel-recording primitive is therefore consistent with the current contract:
**YES**. Switching to bridge recording would change product semantics and is
not authorized by this evidence.

PostgreSQL remains authoritative for desired recording intent and lifecycle;
Asterisk remains authoritative for instantaneous channel ownership and capture.
The bounded repair boundary is vendor-neutral RecordingSession orchestration:
defer and automatically reconcile subordinate start execution until the
canonical CallLeg lifecycle is recording-eligible, at minimum observed
`answered`, and terminally resolve the desired intent if the leg ends first.
Asterisk Stasis ownership explains this adapter run; it must not become a new
vendor-specific PostgreSQL business-state field.

## Current Behavior, Target, and Planned Work

**Current behavior:** a valid canonical recording request durably records
desired intent and immediately queues channel recording even when the CallLeg
is still `originating`. The deployed adapter then returns a normalized conflict
for a pre-Stasis channel, losing the raw Asterisk cause after classification.

**Target architecture:** desired intent remains durable and vendor-neutral;
subordinate execution converges automatically only when canonical CallLeg
lifecycle permits recording. The adapter continues to translate that eligible
intent to provider-specific capture without taking business authority.

**Planned bounded work:** add lifecycle-aware start dispatch/reconciliation and
focused tests for an originating leg becoming answered and an originating leg
terminating before eligibility. Do not change the target to a bridge.

**Experimental work:** transient ARI diagnostic logging only. It was restored
to off and is not a source or deployment change.

**Proven runtime behavior:** exact Asterisk HTTP `409` and message, pre-/post-
request channel presence, `Down` state, absent Stasis ownership, absent bridge,
absent recording resource, and later unanswered destruction.

**Unresolved proof gaps:** RMA-A start success, recording-start observation,
stop execution, stopped convergence, and complete audit sequence remain
unproven. The RecordingSession also remained `observed_state=requested` after
its subordinate operation terminal-failed, contrary to the intended failure
projection; that secondary lifecycle-projection divergence was preserved and
not investigated after this audit reached its exact-cause stop point. Normal
operations still do not retain the distinct raw ARI status/body.

## Commands and Outcomes

The bounded packet used repository identity/status checks, native-k3s status
inspection, supported authenticated Call and RecordingSession API requests,
read-only ARI channel/application/bridge/recording reads, sanitized target-Pod
diagnostic extraction, and read-only PostgreSQL correlation. Expected outcomes
were the exact identifiers and state transitions above. No repository behavior
changed, so no speculative behavior test was added.

## Scope Preserved

RMA-B through RMA-H, RecordingArtifact/archive/storage, MinIO/S3, retention,
deletion, playback, download, FreeSWITCH recording, K5, identity authorization,
Asterisk destination normalization, general call lifecycle, generic operation
architecture, cluster topology, and ADRs were untouched.

## Exactly One Next Action

Implement and regression-test vendor-neutral RecordingSession start
dispatch/reconciliation so a subordinate start runs only after the canonical
CallLeg is recording-eligible (at minimum `answered`) and terminally resolves
if the leg ends before eligibility; do not change Asterisk channel recording to
bridge recording.
