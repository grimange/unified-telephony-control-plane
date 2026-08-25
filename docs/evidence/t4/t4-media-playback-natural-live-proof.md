# T4 `media.playback` narrow natural live proof

Date: 2026-08-24

## Verdict

```text
T4_MEDIA_PLAYBACK_NATURAL_LIVE_PROOF_FOUND_PRODUCT_DEFECT
```

The canonical corridor is correct and provider-neutral from the authenticated
API down to and including the FreeSWITCH ESL command. The runtime never executes
the playback. `media.playback` therefore remains unproven against FreeSWITCH and
T4 remains active.

## Starting repository state

```text
branch     main
HEAD       234d8ae30b82f1eb7b9ab2ad0bf9703e3ac684f2
worktree   clean before and after this task
phase      UTCP_PHASE=T1 (marker unchanged)
commit     none created
push       not pushed
```

The previously reported broad dirty worktree was already committed in `f8314fc`
and `234d8ae`. No pre-existing uncommitted work existed to preserve.

## Authoritative contract as implemented

| Concern | Owner |
| --- | --- |
| Operation name | `call.leg.play_media` / `call.leg.stop_media` (`CallOperationCatalog`) |
| Payload | `{ "media_ref": "utcp:media/<identifier>" }` |
| `media_ref` syntax authority | `App\TelephonyDomain\MediaReference::parse()` |
| `media_ref` provider resolution | `MediaReference::providerReference('freeswitch')` -> `/usr/share/freeswitch/sounds/<id>.wav` |
| Reverse mapping | `MediaReference::canonicalFromProviderReference()` |
| Capability | `media.playback`, gated in `CommandWorker::resolveAdapter()` |
| Operation lifecycle | `runtime_operations` |
| Leg/channel fencing | `FreeSwitchRuntimeAdapter::execute()` re-validates tenant, node ownership and bound `runtime_channel_id` |
| FreeSWITCH execution seam | `FreeSwitchEslClient` -> `uuid_broadcast <uuid> <path> aleg` |
| Normalized observation | `PLAYBACK_START`/`PLAYBACK_STOP` -> `call.leg.media_started` / `call.leg.media_stopped` |

Completion semantics for `call.leg.play_media` are **command-confirmed** per C6:
`succeeded` means the provider accepted the command, not that audio played.

## Automated baseline

```text
php artisan test --filter='MediaReference|FreeSwitchEslAdapter|FreeSwitchEventObservation'
  29 passed (183 assertions)

make api-test
  554 passed, 7 skipped, exit 0

./scripts/freeswitch/config-check
  FreeSWITCH config check passed
```

## Deployment verification

Deployed content was compared to repository content by SHA-256, not assumed.

```text
MediaReference.php            c56bac48...  repo == api == telephony-command-worker
FreeSwitchEslClient.php       c30a60e2...  repo == telephony-command-worker
FreeSwitchEventNormalizer.php e77bb6d1...  repo == telephony-command-worker == freeswitch-esl-events
FreeSwitchEslEventListener.php deb4631b...  repo == telephony-command-worker == freeswitch-esl-events
CallOperationCatalog.php      990e2b27...  repo == api == telephony-command-worker

managed FreeSWITCH image      digest-pinned sha256:18690aa8...
reference-tone.wav in Pod     60c0b6c7...  == repository asset, owner 1000:1000
mod_sndfile                   loaded
```

No stale deployment. This is not a `DEPLOYMENT_DEFECT`.

## Environment correction performed first

Traefik returned 404 for every route. Cause: canonical apiserver endpoint pin
drift after a host restart shuffled node IPs.

```text
scripts/security/check-apiserver-policy-drift
  allow-runtime-fencer-kubernetes-api: expected 172.21.0.2/32, found 172.21.0.3/32
make security-apply           -> policies reconciled
  (target then failed at an unrelated missing `helm` host tool)
scripts/security/check-apiserver-policy-drift
  passed endpoint=172.21.0.2/32:6443
make media-edge-apply         -> media edge restored afterwards
```

Classification: `ENVIRONMENT`, repaired through the canonical lifecycle. No
topology, cluster, registry, or host-port change.

## Live proof corridor

Natural login at `https://app.utcp.local.test`, break-glass temporary password
issued through `make user-access-reset-password`, password changed and tenant
selected through the ordinary endpoints. No injected session.

```text
tenant   Local Tenant (a2315712-...)
node     T4C1 FreeSWITCH ACL Final 20260823 (8fe47ee8-...) freeswitch / freeswitch-esl
         desired=active, observed=ready, cv8 == ocv8, declares media.playback
call     3540cf86-...
leg      c87cbb1e-...
channel  utcp-call-leg-c87cbb1e-...
```

| Stage | Result |
| --- | --- |
| A. Canonical request | PASS — payload carried only `media_ref: utcp:media/reference-tone`; no path, UUID, ESL command, or Sofia profile |
| B. RuntimeOperation authority | PASS — one logical operation; idempotency replay returned the same id `0b644533...` |
| C. Capability gate | PASS — `media.playback` declared; gate enforced in `CommandWorker` |
| D. Runtime-channel fencing | PASS — provider channel id identical to canonical `runtime_channel_id` |
| E. `media_ref` resolution | PASS — resolved at the adapter to the sanctioned sounds root |
| F. FreeSWITCH execution | **FAIL** — no playback occurred |
| G. Normalized observation | NOT REACHED — 0 `call.leg.media_*` observations for this leg |
| H. Convergence truthfulness | PASS — operation reports command acceptance only; nothing was fabricated |
| I. Cleanup | PASS — see below |

### The failure

```text
operation call.leg.play_media   status=succeeded   attempts=1
FreeSWITCH channel, read-only, while still live:
  current_application       = park
  last_app                  = _undef_
  playback_last_offset_pos  = _undef_
normalized call.leg.media_* observations for the leg = 0
```

The adapter issued `uuid_broadcast <uuid> <path> aleg`; FreeSWITCH answered
`+OK Message sent` and never executed the application.

## Root-cause isolation

An independent ESL event capture was validated live before any absence was
reported (it captured `HEARTBEAT`, `RE_SCHEDULE` and `CHANNEL_EXECUTE`).

Against an established, answered leg:

| Injection primitive | Response | Effect |
| --- | --- | --- |
| `uuid_broadcast <u> <file> aleg` | `+OK Message sent` | none |
| `uuid_transfer <u> 'playback:<file>,park:' inline` | `+OK` | none |
| `uuid_break <u>` | `+OK` | none — channel stayed parked |
| same, on a leg originated with `{timer_name=soft}` | `+OK` | none |
| same, on a leg held with `&sleep(30000)` instead of `&park` | `+OK` | none |

Control (previously recorded): `originate ... &playback(<file>)` executes fully
and emits `PLAYBACK_START`/`PLAYBACK_STOP`.

Conclusion: **no asynchronous application injection reaches an established
session on this runtime.** Private-event delivery (`broadcast`), state transfer
(`transfer ... inline`) and the break flag (`uuid_break`) are all accepted and
all ignored, on both `park`ed and `sleep`ing legs. The session's own loop never
iterates. Operations that succeed today (`uuid_kill`, `uuid_bridge`,
`uuid_hold`, `uuid_answer`) all act by direct message or state manipulation and
do not depend on that loop.

The `aleg` suffix, the RTP timer, the `park` application, `mod_sndfile`, the
media asset, and `media_ref` resolution are each individually excluded.

## Blocking observability gap

The exact FreeSWITCH-internal reason could not be read because the managed
runtime produces no operational logs at all:

```text
container stdout          221 lines, ends during boot module loading
/var/log/freeswitch/      contains no freeswitch.log (mod_logfile not loaded)
ESL `log 7` + `fsctl loglevel debug`   accepted, then 0 log events in 20s
```

Events are delivered normally; only the logging subsystem is silent. This is
what prevents the final internal step of the diagnosis.

## Negative and boundary results (live)

| Case | Result |
| --- | --- |
| Provider-local path as `media_ref` | `terminal_failed`, `invalid_request` / `invalid_call_operation_payload`, "playMedia requires an opaque utcp:media reference" — rejected at the provider-neutral C6 layer, before the adapter |
| `utcp:media/../../../etc/passwd` | `terminal_failed`, `invalid_request` / `invalid_media_ref` — path containment holds |
| Idempotent replay | same operation id returned; no duplicate logical playback |
| Provider leakage in public request | none required or accepted |

Unsupported-capability and stale-channel rejection remain covered by focused
automated tests; no live duplication was manufactured.

## Observation seam status

The normalized observation path is independently proven, from a prior
hand-stimulated control on the same node:

```text
call.leg.media_started / call.leg.media_stopped
  runtime_channel_id = utcp-playback-diagnostic
  media_ref          = utcp:media/reference-tone   (canonical, not the provider path)
```

So `PLAYBACK_START`/`PLAYBACK_STOP` normalize correctly and the provider path is
reverse-mapped to canonical identity. Only the execution seam is unproven.

## Fixture defect (independent)

`infrastructure/docker/media/reference-tone.wav.base64` decodes to 204 bytes:
8 kHz mono 16-bit PCM with a 160-byte data chunk that is entirely zero — 10 ms
of silence, not a tone. Even after execution is fixed this cannot exercise
`stop_media` deterministically or demonstrate audible media.

## Cleanup and environment preservation

```text
call.hangup via canonical API   succeeded
call / leg                      completed, NORMAL_CLEARING
media observations for the leg  0
stuck operations DB-wide        0
FreeSWITCH channels             0
diagnostic channels created     2, both removed with uuid_kill
```

Diagnostic ESL access was read-only except for the two disclosed diagnostic
originates, which were removed. No manual FreeSWITCH configuration authority was
introduced. `apntalk-local` remained stopped. No cluster, registry, host port,
node topology, or persistent volume was changed.

Pre-existing and unrelated: `kube-prometheus-stack-grafana` and
`utcp-monitoring-operator` were already in CrashLoopBackOff before this task.

## T4 closure decision

```text
T4_REMAINS_ACTIVE
```

Remaining exit criterion: FreeSWITCH `media.playback` must actually execute and
return a normalized playback observation through a canonical CallLeg.
