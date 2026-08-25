# T4 FreeSWITCH `media.playback` — `uuid_displace` natural live reproof

Date: 2026-08-24

## Verdict

```text
T4_FREESWITCH_MEDIA_PLAYBACK_UUID_DISPLACE_NATURAL_LIVE_PROOF_FOUND_PRODUCT_DEFECT
```

`uuid_displace` attaches and detaches a displacement media bug correctly and the
canonical corridor reports success, but **zero media is produced** on a canonical
established CallLeg. The command-side `runtime_observation` therefore asserts
`call.leg.media_started` when no media started.

Two independent defects were isolated, both with exact root cause, owning
authority and bounded target.

## Repository state

```text
branch  main
HEAD    234d8ae30b82f1eb7b9ab2ad0bf9703e3ac684f2
start   dirty (Codex uuid_displace packet, 19 modified + 4 untracked)
end     same, plus this evidence file
commit  none
push    not pushed
```

Pre-existing dirty work was left untouched. The only file this task created is
this document.

## Deployment verification

Deployed through the canonical lifecycle: `make k8s-image-build`,
`make k8s-image-push`, `make k8s-apply`.

```text
FreeSwitchEslClient.php     7c3e3c26...  repo == telephony-command-worker
FreeSwitchRuntimeAdapter.php fc61663f... repo == telephony-command-worker
uuid_displace occurrences in deployed client   2
uuid_broadcast occurrences in deployed client  0
managed FreeSWITCH digest   sha256:911fa1e7...  (projected automatically, new Pods, no manual rollout)
reference-tone.wav in Pod   a2782505...  == repository asset, 80044 bytes, 5.000 s, 440 Hz, peak 14745
console.conf.xml            present in the Pod
node 8fe47ee8 (FreeSWITCH)  desired=active observed=ready cv8 == ocv8
```

Not a `DEPLOYMENT_DEFECT`.

## Startup-smoke classification

```text
PROOF_DEFECT
```

`make freeswitch-startup-smoke-test` reproduced the reported failure. The
captured output visibly contains `[CONSOLE]`, `[NOTICE]` and `[ERR]` lines and
the script then reports "FreeSWITCH console logs were not emitted".

Exact root cause: `scripts/freeswitch/startup-smoke-test` runs under
`set -euo pipefail`, and line 47 is

```bash
docker logs "$container" 2>&1 | grep -Eq 'FreeSWITCH|CONSOLE' || { ... exit 1; }
```

`grep -q` exits on first match and closes the pipe, so `docker logs` dies of
SIGPIPE and the pipeline yields 141 under `pipefail`. Reproduced in isolation:

```text
set -euo pipefail; yes ... | grep -Eq ...   -> exit 141
set -eu;           yes ... | grep -Eq ...   -> exit 0
```

The loop at line 32-33 already uses the correct shape (capture to a variable,
then `printf | grep`). Target: apply that same shape at line 47.

**Container logging is NOT fully trustworthy.** Boot logs reach stdout, but the
Pod log stops at 223 lines during core DB initialisation and no runtime log line
ever appears, even with `console.conf.xml` (`loglevel debug`) deployed. Runtime
diagnosis in this proof was therefore done through ESL events and channel
variables, not logs.

## Canonical CallLeg

Established entirely through the canonical API after a natural login (no
injected session, no manufactured database row, no direct ESL origination).

```text
call    c1a4ee0c-...
leg     d9f9559e-...
node    8fe47ee8-...  (freeswitch / freeswitch-esl)
channel utcp-call-leg-d9f9559e-...   present in FreeSWITCH, CS_EXECUTE, app=park
peer    echo leg on dialplan fixture 9900
```

Provider UUID is identical to the canonical `runtime_channel_id`.

Baseline before playback: `uuid_buglist` returned an empty `<media-bugs>` list.

## Playback execution

```text
09:15:46  call.leg.originate                     succeeded, attempt 1
09:16:35  POST /api/v1/calls/{call}/operations   202
          payload: { "media_ref": "utcp:media/reference-tone" }   only
09:16:35  call.leg.play_media                    succeeded, attempt 1
t+3s      uuid_buglist shows
            <function>displace</function>
            <target>/usr/share/freeswitch/sounds/reference-tone.wav</target>
```

Media bug attached on the correct channel; no other channel affected.
Idempotent replay of the same key returned the same operation id
`62e5cfa89a14f9e6c7caf3a0c3c6cb8e`.

### The failure — no media

With the displacement bug attached, measured inside the runtime Pod with no ESL
traffic in the sampling window:

```text
lo   delta over 6 s = 0 bytes
eth0 delta over 6 s = 0 bytes
```

The bug was still attached long after the 5 s tone should have ended, because it
is never serviced.

## Root cause — two distinct defects

### Defect 1 — the runtime has no RTP timer

`infrastructure/docker/freeswitch/config/sip_profiles/utcp-internal.xml` sets no
`rtp-timer-name`. On a live channel `rtp_use_timer` and `rtp_timer_name` are
both `_undef_`. Without a timer the media loop is clocked only by inbound RTP;
with both ends of the loopback fixture silent, nothing ever advances.

Control, same runtime, same asset:

| originate | result |
| --- | --- |
| `&playback(tone)` **without** timer | `PLAYBACK_START` only; still executing `playback` 19 s later; `playback_last_offset_pos` `_undef_` |
| `&playback(tone)` **with** `timer_name=soft,rtp_timer_name=soft` | `PLAYBACK_START` → `PLAYBACK_STOP`, `playback_last_offset_pos=40000` (= 5.000 s), `rtp_audio_out_packet_count=250`, peer leg 249/249 |

This single defect also explains the previous session's finding that
`uuid_broadcast`, `uuid_transfer … inline` and `uuid_break` were all accepted and
ignored.

### Defect 2 — `uuid_displace` is the wrong primitive for this contract

With the RTP timer present, so that media demonstrably flows:

```text
uuid_displace <u> start <tone> 0 mux   -> +OK Success
uuid_buglist                            -> displace bug PRESENT
uuid_displace <u> stop <tone>           -> +OK Success
uuid_buglist                            -> empty
channel survives                        -> still park
rtp_audio_in_packet_count               -> 0
rtp_audio_out_packet_count              -> 0
```

Zero RTP in both directions. `uuid_displace` replaces the audio FreeSWITCH reads
*from* the endpoint; on a parked, non-bridged CallLeg nothing consumes that
stream, so nothing is ever transmitted. It also emits no `PLAYBACK_START` /
`PLAYBACK_STOP`.

By direct contrast, on the same runtime with the timer present:

```text
uuid_broadcast <u> <tone> aleg  ->  CHANNEL_EXECUTE Application: playback
                                    PLAYBACK_START / PLAYBACK_STOP
                                    playback_last_offset_pos = 40000  (5.000 s)
                                    rtp_audio_out_packet_count = 250
                                    channel survives
```

So the previously rejected primitive works correctly once Defect 1 is fixed, and
it produces exactly the runtime events the existing canonical normalizer
consumes.

## Observation proof

No canonical `call.leg.media_started` was produced for the canonical CallLeg.

```text
runtime_observations for leg d9f9559e-...:
  09:16:19  call.leg.answered  observed
  call.leg.media_started / media_stopped count = 0
```

The adapter's `runtime_observation` object exists **only inside the command-side
outbox event payload**:

```text
09:16:36  runtime_operation.freeswitch_call_executed  op=call.leg.play_media
          runtime_observation={"observation_type":"call.leg.media_started",
                               "observed_state":"observed",
                               "media_ref":"utcp:media/reference-tone"}
09:22:03  runtime_operation.freeswitch_call_executed  op=call.leg.stop_media
          runtime_observation={"observation_type":"call.leg.media_stopped", ...}
```

Nothing consumes `runtime_operation.freeswitch_call_executed`, so this never
becomes a `runtime_observations` row. Classification: it is a **command-side
assertion derived from `uuid_buglist`, not a canonical runtime observation** —
and in this run it asserted `media_started` while zero media existed.

The canonical observation path itself is proven functional. The diagnostic
control channels, which executed the real `playback` application, produced
genuine `PLAYBACK_START`/`PLAYBACK_STOP` that the FreeSWITCH ESL listener
normalized correctly:

```text
09:18:41  call.leg.media_started  chan=t4ctl-playback  media_ref=utcp:media/reference-tone
09:18:50  call.leg.media_stopped  chan=t4ctl-playback  media_ref=utcp:media/reference-tone
09:19:59 / 09:20:12   chan=t4ctl2
09:21:21 / 09:21:30   chan=t4ctl3
```

Canonical `media_ref` identity, never the provider path.

## Stop proof

```text
09:22:01  POST call.leg.stop_media   (caller supplied NO media_ref)
          adapter recovered the canonical active media_ref from the prior
          succeeded play_media operation
09:22:03  call.leg.stop_media        succeeded, attempt 1
          uuid_displace <u> stop <resolved path>
          uuid_buglist -> <media-bugs></media-bugs>
          leg still alive, app=park
```

Stop behaves correctly at the provider-mechanics level. It cannot be said to
have "stopped the tone", because no tone was ever playing.

## Provider neutrality

```text
PASS
```

The caller supplied only `media_ref: utcp:media/reference-tone` for play, and
nothing at all for stop. No provider path, channel UUID, ESL command, Sofia
profile or dialplan value crossed the public boundary in either direction.

## Cleanup

```text
call.hangup                     succeeded, attempt 1
call / leg                      completed / terminated
FreeSWITCH channels             0
displacement media bugs         0
stuck RuntimeOperations         0
diagnostic channels created     4 (t4ctl-playback, t4ctl2, t4ctl3, t4disp, t4bc) — all removed
```

Terminal convergence took roughly two minutes rather than seconds; it completed
without intervention. No database state was edited by hand.

## Bounded correction packet

1. **`infrastructure/docker/freeswitch/config/sip_profiles/utcp-internal.xml`** —
   add `<param name="rtp-timer-name" value="soft"/>`, and assert it in
   `scripts/freeswitch/config-check`. Rebuild/push the managed image through the
   canonical lifecycle. This is the prerequisite for any media at all.
2. **`apps/api/app/RuntimeAdapters/FreeSwitch/FreeSwitchEslClient.php`** — the
   playback primitive must execute the `playback` application on the channel, so
   that real `PLAYBACK_START`/`PLAYBACK_STOP` reach the existing canonical
   observation path. `uuid_displace` cannot satisfy the contract on a parked leg.
   The `uuid_buglist`-derived `runtime_observation` should not stand in for a
   canonical observation.
3. **`scripts/freeswitch/startup-smoke-test:47`** — `pipefail` + `grep -q`
   SIGPIPE; capture logs to a variable first.

## T4 closure

```text
T4_REMAINS_ACTIVE
```
