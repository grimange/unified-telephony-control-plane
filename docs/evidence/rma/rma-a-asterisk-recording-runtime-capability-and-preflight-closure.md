# RMA-A Asterisk recording runtime capability and preflight closure

Current-State-Impact: yes

## Result

The repository now declares and validates the static Asterisk recording
capability, but the provider-native smoke remains blocked at stored WAV artifact
finalization. The bounded packet is therefore not closed and has not been
published or deployed.

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

## Exact remaining blocker

The smoke could not observe a stored, non-empty WAV artifact after live-recording
stop and channel teardown. The stored-resource query returned `404 Recording
not found`; `/var/spool/asterisk/recording` remained empty. No ENOENT, unknown
format, module-load, or permission error was emitted. This localizes the
remaining issue to the isolated provider smoke channel/recording finalization
path, not to the previously missing static spool directory or WAV capability.
No safe repository Asterisk image/configuration repair is proven by this
evidence, so the packet stops without speculative changes.

## Scope and deployment

No UTCP domain, RecordingSession, SIP, NetworkPolicy, FreeSWITCH, storage, or
retention behavior was changed. The source changes are not published into an
immutable image and were not deployed to native-k3s because the required
provider-native artifact gate is not passing. The known generic
`make asterisk-ari-config-check` failure remains unrelated in
`CallDomainService.php`.
