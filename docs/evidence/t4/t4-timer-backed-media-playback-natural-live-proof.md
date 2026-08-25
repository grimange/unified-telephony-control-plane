# T4 FreeSWITCH timer-backed `media.playback` — natural live proof

Date: 2026-08-24

## Verdict

```text
T4_FREESWITCH_TIMER_BACKED_MEDIA_PLAYBACK_NATURAL_LIVE_PROOF_PASSED
```

A naturally originated UTCP-managed FreeSWITCH CallLeg inherits `timer_name=soft`,
`call.leg.play_media` produces genuine timer-paced playback with real RTP, both
`call.leg.media_started` and `call.leg.media_stopped` enter canonical observation
authority from FreeSWITCH runtime events, and `call.leg.stop_media` truncates an
active playback while the CallLeg survives.

This record does not amend the prior `uuid_displace` and timerless failures;
those remain historical truth.

## Repository state

```text
branch        main
HEAD          234d8ae30b82f1eb7b9ab2ad0bf9703e3ac684f2   (start and end)
start         dirty — 21 modified, 6 untracked (Codex timer-backed packet)
end           same, plus this evidence file
commit        none
push          not pushed
```

Pre-existing dirty work was left untouched.

## Repository contract verified

```text
call.leg.play_media  -> uuid_broadcast <runtime_channel_id> <resolved path> aleg
call.leg.stop_media  -> uuid_break <runtime_channel_id>
uuid_displace / uuid_buglist   absent from the playback path
PLAYBACK_START -> call.leg.media_started
PLAYBACK_STOP  -> call.leg.media_stopped
sip_profiles/utcp-internal.xml:14  <param name="rtp-timer-name" value="soft"/>
origination         originate {origination_uuid=<id>,timer_name=soft}<dest> &park
reference tone      5.000 s, 8000 Hz, mono, 16-bit, peak 14745
focused tests       31 passed (189 assertions)
```

## Deployment proof

The canonical runtime was initially **stale**:

```text
DEPLOYMENT_DEFECT (converged)
  deployed adapter  7c3e3c26...   (previous uuid_displace build)
  repository        e4db75a4...
  pod profile       no rtp-timer-name present
```

Converged through the canonical lifecycle only — `make k8s-image-build`,
`make k8s-image-push`, `make k8s-apply`. No manual file copy, no live patch, no
unmanaged instance, no second cluster.

After convergence:

```text
FreeSwitchEslClient.php      e4db75a4...  repo == telephony-command-worker
FreeSwitchRuntimeAdapter.php fc61663f...  repo == telephony-command-worker
deployed client              uuid_broadcast=1  uuid_displace=0  uuid_break=1
managed FreeSWITCH digest    sha256:8d3a3c1e...  (digest-pinned, Pods rolled automatically)
pod profile                  rtp-timer-name = soft
pod reference-tone.wav       a2782505...  == repository asset
node 8fe47ee8 (freeswitch)   desired=active observed=ready cv8 == ocv8
apiserver policy drift       clean
```

## Canonical CallLeg

Established entirely through the canonical authenticated API — no manufactured
rows, no direct ESL origination, no diagnostic channel used as the proof object.

```text
call     e941eefe-...
leg      c31ae8d8-...
node     8fe47ee8-...   freeswitch / freeswitch-esl, ready, media.playback declared
channel  utcp-call-leg-c31ae8d8-...   present in FreeSWITCH, CS_EXECUTE, app=park
peer     echo leg on dialplan fixture 9900
```

Provider UUID is identical to the canonical `runtime_channel_id`.

## Timer proof — the decisive inheritance check

Read from the naturally originated managed channel:

```text
timer_name       = soft      <-- inherited from canonical origination
rtp_timer_name   = _undef_
rtp_use_timer    = _undef_
```

`rtp_timer_name` / `rtp_use_timer` are internal derived variables FreeSWITCH does
not expose as channel variables; they are not required, because timer-backed
media behaviour is proven directly below (playback advances in real time and
terminates at exactly the tone duration, which never happened timerless).

## Play-media proof

```text
10:17:00  POST /api/v1/calls/{call}/operations
          { "operation_type": "call.leg.play_media",
            "target_leg_id": "c31ae8d8-...",
            "payload": { "media_ref": "utcp:media/reference-tone" } }      202
10:17:00  RuntimeOperation b1ce4d80... created, node 8fe47ee8, capability media.playback
10:17:02  operation started + completed, attempt 1
10:17:02  CHANNEL_EXECUTE  Application: playback
          Unique-ID: utcp-call-leg-c31ae8d8-...          <-- exact current channel
10:17:02  PLAYBACK_START
          Playback-File-Path: /usr/share/freeswitch/sounds/reference-tone.wav
10:17:07  PLAYBACK_STOP
          variable_playback_last_offset_pos: 40000
```

### Media evidence

```text
PLAYBACK_START -> PLAYBACK_STOP        5 s wall clock (10:17:02 -> 10:17:07)
playback_last_offset_pos               40000 samples @ 8000 Hz = 5.000 s rendered
tone duration                          5.000 s  -> exact match
```

Real-time pacing plus a full sample offset is positive media progression: a
timerless channel previously stalled indefinitely with the offset never set.
Command `+OK` and `PLAYBACK_START` alone were not treated as sufficient.

Channel remained alive after natural completion.

## Canonical normalized observations

Produced from FreeSWITCH runtime events (each carries a `source_event_id`), not
from command acceptance:

```text
10:17:13  call.leg.media_started  node=8fe47ee8  chan=utcp-call-leg-c31ae8d8-...
                                  media_ref=utcp:media/reference-tone
10:17:25  call.leg.media_stopped  node=8fe47ee8  chan=utcp-call-leg-c31ae8d8-...
                                  media_ref=utcp:media/reference-tone
```

Subject is the canonical CallLeg; the channel matches `runtime_channel_id`; the
reference is canonical identity, never the provider path. Four playback episodes
in this run each produced a correctly paired started/stopped observation.

## Stop-media proof

The first stop attempt was inconclusive because `play_media` and `stop_media`
were claimed in the same command-worker tick (both started 10:18:20), so
`uuid_break` executed before the playback application began. Re-run with the
stop spaced into the middle of the tone:

```text
10:20:14  POST call.leg.play_media  { "media_ref": "utcp:media/reference-tone" }   202
10:20:17  PLAYBACK_START
10:20:17  POST call.leg.stop_media  { "operation_type": "call.leg.stop_media",
                                      "target_leg_id": "c31ae8d8-..." }            202
          (no media_ref, no provider path, no channel UUID, no ESL command)
10:20:20  stop_media operation succeeded, attempt 1  -> uuid_break <current uuid>
10:20:20  PLAYBACK_STOP
          variable_playback_last_offset_pos: 22880
```

```text
truncated at   22880 / 40000 samples = 2.86 s of a 5.000 s tone
elapsed        3 s instead of 5 s
```

Playback was genuinely interrupted mid-stream. `call.leg.media_stopped` followed
through canonical observation authority at 10:20:28. The CallLeg survived and
remained usable; no orphaned playback remained.

The adapter recovered the canonical active media reference from the last
succeeded `play_media` operation, so the public stop request required no
provider-local input.

## Provider neutrality

```text
PASS
```

Play carried only `media_ref: utcp:media/reference-tone`; stop carried only the
operation type and target leg. No `uuid_broadcast`, `uuid_break`, FreeSWITCH
path, `timer_name`, `rtp-timer-name`, channel UUID or Sofia detail crossed the
public boundary in either direction.

## Idempotency and truthfulness

```text
replay of idempotency key t4-timer-play-1
  -> same RuntimeOperation id b1ce4d80..., status succeeded, attempts 1
  -> 0 PLAYBACK events during the replay window (no duplicate playback)
```

The canonical timeline keeps command acceptance and runtime fact distinct:

```text
10:20:14  operation.succeeded     src=runtime_operation    call.leg.play_media
10:20:17  operation.succeeded     src=runtime_operation    call.leg.stop_media
10:20:18  call.leg.media_started  src=runtime_observation
10:20:28  call.leg.media_stopped  src=runtime_observation
```

## Cleanup

```text
10:21:53  call.hangup                 succeeded, attempt 1
10:21:54  call.leg.terminated         src=runtime_observation
call / leg                            completed / terminated, NORMAL_CLEARING
FreeSWITCH channels                   0
active playback                       none
stuck RuntimeOperations               0
leg operations                        8, all succeeded
diagnostic channels created           none — every stimulus went through the canonical API
```

No database state was edited by hand.

## Runtime logging

The known FreeSWITCH stdout-continuity concern did not materially affect this
proof. ESL events, channel variables, canonical observations and RuntimeOperation
records were fully discriminating. No logging workaround was introduced.

## T4 closure

The current authoritative roadmap and phase-status name exactly one remaining T4
action — deploy the corrected digest-pinned managed image and rerun one narrow
`call.leg.play_media` / `uuid_broadcast` proof — and state that recording remains
separate and that T4D does not exist. That action is now complete and passed.

```text
T4_COMPLETE
```

Next telephony mainline: **C7A — External Connectivity, Telephony Addressing, and
Caller Identity**. K5 remains a parallel R0-critical track and does not serially
gate C7A.
