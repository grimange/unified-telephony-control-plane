# T3-S2B Reciprocal Media Corridor And Prover Reproof

Date: 2026-08-02

Starting commit: `48dc81e` (`fix(t3): correct in-cluster media prover`)

Scoped commits under proof:

```text
fde07d5  fix(t3): permit reference runtime media corridor
48dc81e  fix(t3): correct in-cluster media prover
```

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2B_IN_CLUSTER_WEBRTC_MEDIA_PROOF_INCOMPLETE`

## Summary

The reciprocal media corridor is **proven end to end**. With `fde07d5` fully
applied, browser-to-runtime and runtime-to-browser RTP both flow, Asterisk
`Echo()` returns deterministic audio, the browser receives it with non-zero
audio energy, and both BYE directions delete the media session and release
ports. `PRODUCT_DEFECT-15` is closed.

The proof is nevertheless `INCOMPLETE` because the **committed prover cannot
execute**: `48dc81e` did not close its trust path, and five further prover
defects were found. Every media result below was obtained with a scratchpad
diagnostic prover whose only differences from `48dc81e` are the six enumerated
harness corrections; the committed namespace, Job, and all three committed
proof NetworkPolicies were applied unchanged.

A second finding is recorded: rtpengine terminated twice with `SIGSEGV`
(`exitCode 139`) during media-session teardown in the pre-correction window.

## Repository Baseline

```text
HEAD                a48dc81e (branch main), working tree clean
UTCP_PHASE          T1
fde07d5 ancestor    yes
48dc81e ancestor    yes
make media-config-check / -test                pass
make security-config-check / -test             pass
make t3-media-prover-config-check / -test      pass
```

## Production Baseline

```text
kamailio      kamailio-56b99d4b57-kldt4      uid 41dfde71-…  restarts 4  Ready
rtpengine     rtpengine-74cd786966-8vhff     uid 0fbd6b20-…  restarts 0  Ready  10.42.0.179
asterisk      asterisk-ari-74d8c4b5f8-jtxvl  uid 2cec17cd-…  restarts 0  Ready  10.42.2.170
secondary     asterisk-ari-b-8557bd4d76-rcjfn uid 8a904cdd-… restarts 15 Ready
policy generations   allow-rtpengine-media 1, allow-asterisk-sip-from-kamailio 3
rtpengine sessions own/foreign      0 / 0
rtpengine ports_used / ports_free   0 / 200
asterisk active channels            0
database      tables 41, tenants 27, RuntimeNodes 110 (asterisk, simulator), pending outbox 0
redis         dbsize 34, keys sip/dialog/rtp/media 0/0/0/0
```

## Production Media Policies Applied

Only the two NetworkPolicies changed by `fde07d5`. `kubectl diff` for each
contained exactly the four required directions and nothing else; existing SIP
`5060`, DNS `53`, rtpengine control `2223`, and metrics `2224` rules were
preserved, with no `ipBlock`, no all-UDP rule, and no public exposure.

| Resource | Namespace | Generation |
|---|---|---|
| `allow-rtpengine-media` | `utcp-platform` | `1` → **`2`** |
| `allow-asterisk-sip-from-kamailio` | `utcp-runtime` | `3` → **`4`** |

```text
rtpengine egress  -> utcp-runtime asterisk-ari      UDP 10000-20000
asterisk  ingress <- utcp-platform rtpengine-media  UDP 10000-20000
asterisk  egress  -> utcp-platform rtpengine-media  UDP 40000-40099
rtpengine ingress <- utcp-runtime asterisk-ari      UDP 40000-40099
```

Selectors combine `namespaceSelector` with `podSelector`. The secondary runtime
is excluded: both the rtpengine peer selector and the Asterisk policy's own
`podSelector` require `utcp.dev/runtime-node: local-asterisk-ari`, which
`asterisk-ari-b` (`local-asterisk-ari-b`) does not carry.

A full-cluster Pod snapshot diff immediately after the apply was **identical** —
NetworkPolicy application restarted nothing.

### Corridor probe

Before the apply the corridor was closed. After it, with a negative control:

```text
rtpengine -> asterisk 10.42.2.170:19001  (inside 10000-20000)  20/20 received
rtpengine -> asterisk 10.42.2.170:40050  (outside the range)    0/20 received
```

The rule is open **and** exactly scoped.

## Required Additional Deployment — `rtp.conf`

`fde07d5` also added `infrastructure/docker/asterisk/config/rtp.conf`. That file
is baked into the Asterisk image and staged by the entrypoint
(`cp /opt/utcp-asterisk-config/*.conf /tmp/utcp-asterisk/`).

The running Asterisk Pod predated the commit. Its `/opt/utcp-asterisk-config/`
contained no `rtp.conf`, so no `rtp.conf` was staged into the effective
configuration directory `/tmp/utcp-asterisk` (Asterisk runs with
`-C /tmp/utcp-asterisk/asterisk.conf`, **not** `/etc/asterisk`), and Asterisk
used its compiled-in defaults:

```text
rtp show settings   Port start: 5000   Port end: 31000
```

The image's `/etc/asterisk/rtp.conf` did contain `10000-20000` but is never read
by the running instance.

This is why the corridor appeared intermittent: only calls whose Asterisk RTP
port happened to fall inside the permitted `10000-20000` window succeeded. A
sniff on the rtpengine node captured the mismatch directly:

```text
10.42.1.253:47155 -> 10.42.0.179:40012   1857 pkts   browser -> rtpengine (SRTP)
10.42.0.179:40004 -> 10.42.2.170:29938   1210 pkts   rtpengine -> runtime, port OUTSIDE the range
10.42.0.179:40012 -> 10.42.1.253:47155     37 pkts   rtpengine -> browser (ICE/DTLS only)
```

The committed Asterisk image was therefore rebuilt from HEAD and only
`deploy/asterisk-ari` was rolled out. After rollout:

```text
rtp show settings   Port start: 10000   Port end: 20000
staged config       /tmp/utcp-asterisk/rtp.conf present
new Pod             asterisk-ari-676b58b676-dzfm4  uid 6e3b5c64-…  restarts 0  Ready  10.42.1.254
```

`fde07d5` is only in effect once this image is deployed. This is a divergence
from the prompt's "expected production workload restarts: none", which assumed
the commit changed NetworkPolicies alone.

## Proof Resources Applied

Committed overlay only:

```text
Namespace/utcp-proof
Job/utcp-proof/t3-media-prover                (one-shot, backoffLimit 0,
                                               activeDeadlineSeconds 420,
                                               ttlSecondsAfterFinished 900)
Secret/utcp-proof/t3-media-prover-credentials  (ephemeral, runner-created)
ConfigMap/utcp-proof/t3-media-prover-scenario
ConfigMap/utcp-proof/t3-media-prover-local-ca  (public CA only)
NetworkPolicy/utcp-proof/default-deny
NetworkPolicy/utcp-proof/allow-t3-media-prover-required-egress
NetworkPolicy/utcp-platform/allow-rtpengine-media-prover
```

Render audit: no Deployment, no Service, no NodePort, no LoadBalancer, no
HostPort, no hostNetwork/hostPID/hostIPC, no `ipBlock`, no public UDP route, no
hostPath, no privileged execution. Ports are exactly DNS UDP/TCP `53`,
application/WSS TCP `443`, rtpengine media UDP `40000-40099`. No direct
prover-to-Asterisk media permission. `kubectl diff -k` reported the namespace
absent, confirming every proof resource was new.

## Natural Login and TLS Trust — committed prover FAILS

`PROOF_HARNESS_DEFECT-A`. The committed runner correctly requires
`.runtime/tls/utcp-local-ca.crt` (the repository's designated public-CA slot,
`scripts/gateway/lib:43`) and mounts it read-only from a ConfigMap with
`NODE_EXTRA_CA_CERTS`. But `prepareChromiumTrust()` shells out to `certutil`,
and the pinned image does not provide it — `tools/t3-media-prover/Dockerfile`
never installs `libnss3-tools`:

```text
T3_MEDIA_PROVER_RESULT_JSON={"scenario":"browser-originated-bye",
  "errors":["spawn certutil ENOENT"],"durationMs":13}
job_terminal_state=failed pod_phase=Failed pod_exit_code=1
```

`PROOF_HARNESS_DEFECT-B`. With `certutil` present, Playwright rejects the
launch outright:

```text
browserType.launch: Pass userDataDir parameter to
'browserType.launchPersistentContext(userDataDir, options)'
instead of specifying '--user-data-dir' argument
```

`PROOF_HARNESS_DEFECT-C`. `prepareChromiumTrust()` builds the NSS database
inside a freshly created profile directory. Chromium on Linux reads its NSS
trust store from `$HOME/.pki/nssdb` only, so a database anywhere else is never
consulted. Writing the same database to `$HOME/.pki/nssdb` and dropping the
`--user-data-dir` argument made natural login succeed with **normal TLS
verification** — no `ignoreHTTPSErrors`, no `--ignore-certificate-errors`, no
insecure origin, no ClusterIP URL, and no private key in the Pod.

`PROOF_HARNESS_DEFECT-D`. The login hydration race from the previous proof is
**not** closed. `#app` is the Vue mount point and is visible before the form
renders, and `firstLocator()` still selects with `locator.count()`, which does
not auto-wait. Observed intermittently:

```text
errors:["none of the expected login selectors exists:
         input[name=\"email\"], input[type=\"email\"]"]
```

## Scenario A — Browser-Originated BYE

Selected explicitly. The runner validates the scenario before Job creation and
rejects unknown values; `job.yaml` sources it from the
`t3-media-prover-scenario` ConfigMap. `PROOF_HARNESS_DEFECT-6` from the previous
proof is **closed**.

Result (diagnostic prover, committed policies and Job):

```text
job terminal state   Complete=True
pod phase            Succeeded            exit code 0
scenario             browser-originated-bye
callId               ef48b85e87f6c8@utcp-t3-media-prover
errors               []
```

### ICE and DTLS

```text
iceGatheringState        complete
iceConnectionState       connected (closed after teardown)
peerConnectionState      connected (closed after teardown)
dtlsState                connected
selectedCandidatePair    CPdDm8SeUs_IzV0w+qF
local  candidate         host, private   (prover Pod)
remote candidate         host, private   (rtpengine media Pod)
```

The remote candidate is the rtpengine media Pod. No direct Asterisk media
candidate, no public address, no developer-host address. rtpengine logged
`DTLS-SRTP successfully negotiated using AEAD_AES_256_GCM`.

### Browser outbound media

Monotonic across the full window:

```text
t= 1s  out=55/8800      t= 7s  out=355/56800
t= 4s  out=205/32800    t=12s  out=605/96800
final  outboundRtpPackets=605  outboundRtpBytes=96800
```

### rtpengine to runtime, and runtime echo

rtpengine's own end-of-session accounting shows both legs symmetric:

```text
Media #1 (audio over RTP/SAVPF)  10.42.0.179:40044 <> 10.42.1.2:50420
                                 in 2138 p / 365408 b, out 2130 p / 400314 b
Media #1 (audio over RTP/AVP)    10.42.0.179:40078 <> 10.42.1.254:19948
                                 in 2120 p / 364640 b, out 2120 p / 364640 b
RTCP                             10.42.0.179:40079 <> 10.42.1.254:19949
Average MOS 4.3   packet loss 0/0/0%   jitter 2/2/2 ms
```

The runtime-facing destination `19948` is inside the runtime RTP range and the
policy window. Asterisk reported non-zero receive **and** transmit:

```text
pjsip show channelstats
anonymous-00000002  00:00:13  ulaw   680  0  0  0.002   680  0  0  0.002  0.001
                                      ^rx                 ^tx
```

`Echo()` remained the running application throughout. No NetworkPolicy denial
occurred, and no direct prover-to-Asterisk media path exists.

### Browser inbound media and audio energy

```text
t= 1s  in=54/8640    inboundRtpTotalAudioEnergy=0.0317
t= 4s  in=205/32800  inboundRtpTotalAudioEnergy=0.1288
t=12s  in=605/96800  inboundRtpTotalAudioEnergy=0.3864
final  inboundRtpPackets=605  inboundRtpBytes=96800  audioEnergy=0.386401955338333
       jitter=0.002  packetsLost=0
```

Inbound exactly matches outbound (605/96800 both ways) — deterministic echo of
the 440 Hz tone, not merely packet presence.

`PROOF_HARNESS_DEFECT-E`. The committed prover reads audio energy from
`report.type === 'track'`, a statistic Chromium removed. The live report
inventory proves the correct source:

```text
DIAG_ENERGY_REPORTS=[
  "inbound-rtp/audio  tae=0.38672441245464173  lvl=0.17957090975676748",
  "media-source/audio tae=0.40304779959613380  lvl=0.17999816888943143"]
DIAG_ENERGY track=0  inboundRtp=0.38672441245464173
```

`track` yields `0` in every run. The committed equivalent is
`inbound-rtp.totalAudioEnergy`.

### BYE and cleanup

`PROOF_HARNESS_DEFECT-F`. The committed prover builds in-dialog ACK and BYE with
the original request URI (`sip:9900@sip.utcp.local.test`) instead of the
dialog's remote target from the 200 OK `Contact`. The ACK never reaches the
runtime. Observed signature, matching the T3-S2A contract exactly:

```text
SIP_RX: 401 Unauthorized
SIP_RX: 100 trying
SIP_RX: 200 OK          <- INVITE answer
SIP_RX: 200 OK          |
SIP_RX: 200 OK          |  three 200 OK retransmissions = ACK never landed
SIP_RX: 200 OK          |
SIP_RX: BYE sip:ts-…@….invalid;transport=ws
SIP_RX: 408 Request Timeout
```

Using the dialog remote target (`DIAG_REMOTE_TARGET=sip:10.42.1.254:5060`)
resolved it completely:

```text
SIP_RX: 401 Unauthorized
SIP_RX: 100 trying
SIP_RX: 200 OK          <- INVITE answer
SIP_RX: 200 OK          <- BYE answer
post-ACK 200 retransmissions: 0
byeDirection    browser
finalSipResult  SIP/2.0 200 OK
cleanupResult   signaling-closed
```

After teardown the media session disappeared and ports were released
(`rtpengine_sessions 0/0`, `ports_used 0`, `ports_free 200`).

### Job collection

The corrected runner waits for `Complete=True` **or** `Failed=True`, then
collects Pod phase, Job condition, container exit code, the structured JSON
result and bounded logs **before** cleanup, with `ttlSecondsAfterFinished` at
`900`. The failed-Job collection path was exercised repeatedly and always
returned the structured result and exit code.
`PROOF_HARNESS_DEFECT-2` from the previous proof is **closed**.

## Scenario B — Runtime-Originated BYE

Selected explicitly; the structured result identifies it exactly.

```text
job terminal state   Complete=True
pod phase            Succeeded            exit code 0
scenario             runtime-originated-bye
callId               fba16cccc4d44@utcp-t3-media-prover
errors               []
```

### Readiness gate

The hangup was issued only after the prover emitted its committed readiness
point and only after independent runtime-side reciprocal-media evidence:

```text
prover_ready=yes  proof_call_id=fba16cccc4d44@utcp-t3-media-prover
DIAG_T=12s out=605/96800 in=605/96800 inRtpE=0.38672441245464173
           ice=connected dtls=connected
asterisk channelstats before hangup:
  anonymous-00000003  00:00:13  ulaw  668  0  0  0.002  668  0  0  0.002
active_channels=1
```

ICE connected, DTLS connected, browser outbound non-zero, runtime-facing RTP
non-zero, browser inbound non-zero, audio energy non-zero, channel active.

### Runtime hangup stimulus

```text
active channels at baseline   0
active channels at stimulus   1   (asserted, exactly one)
proof channel                 PJSIP/anonymous-00000003  Exten 9900  Up  Echo
command                       channel request hangup PJSIP/anonymous-00000003
result                        Requested Hangup on channel 'PJSIP/anonymous-00000003'
```

No ARI, no AMI, no dialplan modification, no configuration reload, no broad
channel termination. The channel was active, there was no ACK timeout, and no
`Reason: cause=408` was involved.

Call-ID correlation is established **from the browser side**: the prover matches
an inbound `BYE` against its own Call-ID before answering, so the BYE produced
by the hung-up channel provably belonged to the proof dialog. See
`Proof Limitations` for the CLI-side caveat.

### BYE and cleanup

```text
1.  runtime generated BYE                     yes
2.  Kamailio routed it via the alias corridor  yes (existing WebSocket reused)
3.  browser received BYE                       yes, matching its own Call-ID
4.  browser returned 200 OK                    yes
5.  response reached the runtime               yes, channel terminated
6.  MEDIA_DELETE executed                      yes
7.  rtpengine session disappeared              yes
8.  media ports returned to zero               yes
9.  browser dialog terminated                  yes
10. runtime channel terminated                 yes
11. retransmission / timeout remaining         none
12. structured result successful               yes, errors []
13. Job exit status                            0
```

Final Scenario B media figures:

```text
outboundRtpPackets 605   outboundRtpBytes 96800
inboundRtpPackets  605   inboundRtpBytes  96800
audioEnergy        0.38672441245464173
jitter 0.002   packetsLost 0
byeDirection runtime   finalSipResult 200 OK   cleanupResult signaling-closed
```

## rtpengine SIGSEGV

`rtpengine` terminated twice with `exitCode 139` (`SIGSEGV`), restart count
`0 → 2`, the Pod UID unchanged.

```text
lastState.terminated  exitCode 139, reason Error,
                      startedAt 22:08:50Z, finishedAt 22:17:40Z
events                Warning BackOff x3 — Back-off restarting failed container
```

The crash immediately follows the per-call `Final packet stats` block, i.e. it
occurs during media-session teardown. Both crashes fall inside the
pre-correction window, when the corridor was half-open, media was one-way, and
sessions were abandoned without a BYE (the prover threw before its BYE and the
WebSocket closed). Across the **four** clean call cycles after the corridor was
correct — two Scenario A and two Scenario B, each with a proper BYE in one
direction — rtpengine did not crash again, logged no `ERROR`, and stayed Ready.

This is recorded as a product defect requiring its own bounded diagnosis. It
does not invalidate the corridor result, which was obtained entirely on the
current, stable container.

## Provider-Neutrality Assessment

The browser prover's assertions use only SIP dialog state, ICE state,
DTLS/peer-connection state, RTP packets and bytes, audio-energy evidence, BYE
direction, final SIP response, and cleanup state.

`make t3-media-prover-config-check` enforces the absence of `ARI`, `AMI`,
Asterisk channel identifiers, `fs_cli`, FreeSWITCH ESL, `hostNetwork`,
`ignoreHTTPSErrors`, `--ignore-certificate-errors`, and `max-bundle` in the
prover source, and it passes. No Asterisk Pod IP, dialplan state, or
Asterisk-specific SDP marker is used as a success criterion.

The Asterisk CLI appears only as the external Scenario B stimulus and as
read-only diagnosis.

```text
provider-neutral media contract:  proven against Asterisk reference runtime
runtime agnosticism:              not yet proven
```

## Default-Deny and Containment Preservation

```text
NodePort                            none
LoadBalancer for media              none
ExternalIP                          none
HostPort / HostNetwork              none
public UDPRoute                     none
public rtpengine Service            none
k3d media publication               none
host route                          none
Pod-CIDR ipBlock                    none
direct prover-to-Asterisk media     never permitted
```

Default-deny remains active in `utcp-runtime`, `utcp-platform`, and the proof
namespace. The corridor rules are exact: the negative control on port `40050`
was still denied after the fix. The rtpengine media candidate remains internal
and the external host still has no route to it.

## External Media Edge Status

```text
contained in-cluster media core   = proven (browser and runtime legs both ways)
external browser media reachability = not proven, unchanged, T3-S3
```

## State and Workload Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tenants | 27 | **27** |
| RuntimeNodes | 110 | **110** |
| RuntimeNode families | asterisk, simulator | **unchanged** |
| pending outbox | 0 | **0** |
| Redis `sip`/`dialog`/`rtp`/`media` | 0/0/0/0 | **0/0/0/0** |
| rtpengine sessions own/foreign | 0/0 | **0/0** |
| rtpengine ports_used / ports_free | 0 / 200 | **0 / 200** |
| Asterisk active channels | 0 | **0** |

Full-cluster Pod diff contains exactly two entries:

```text
rtpengine-74cd786966-8vhff        uid unchanged, restarts 0 -> 2   (SIGSEGV, above)
asterisk-ari-74d8c4b5f8-jtxvl     replaced by
asterisk-ari-676b58b676-dzfm4     uid 6e3b5c64-…, restarts 0        (intentional
                                  rollout of the committed rtp.conf image)
```

Kamailio, the secondary runtime `asterisk-ari-b`, and every unrelated workload
retain their UID and restart count. All workloads Ready. Redis `db0` moved
`34 → 74`: ordinary session and cache entries from the authorized API calls that
issued proof credentials. `EXPECTED_BEHAVIOR`.

The corrected production media NetworkPolicies remain applied.

## Findings

| Classification | Finding |
|---|---|
| `PASS` | **`PRODUCT_DEFECT-15` is closed.** With `fde07d5` fully applied, rtpengine bridges RTP to the reference runtime in both directions: runtime leg `in 2120 p / out 2120 p` on `10.42.1.254:19948`, Asterisk `rx 680 / tx 680`, MOS 4.3, 0% loss |
| `PASS` | Corrected policies are exact — the permitted `10000-20000` window opens (20/20) while `40050` stays denied (0/20) |
| `PASS` | NetworkPolicy application restarted no Pod; the post-apply cluster Pod diff was identical |
| `PASS` | Browser inbound echo proven: 605 in / 605 out, `inbound-rtp.totalAudioEnergy` 0.3864 monotonic, jitter 0.002, 0 packets lost |
| `PASS` | Scenario A browser BYE returns `200 OK`, session deleted, ports released, Job exit `0` |
| `PASS` | Scenario B runtime BYE reaches the browser over the existing WebSocket, browser returns `200 OK`, session deleted, ports released, Job exit `0` |
| `PASS` | Scenario selection is explicit and validated before Job creation; unknown scenarios are rejected |
| `PASS` | Failure-tolerant collection works — `Complete` or `Failed`, evidence gathered before cleanup, TTL raised to 900 s |
| `PASS` | T3-S1 containment and default-deny fully preserved |
| **`PRODUCT_DEFECT-16`** | rtpengine terminated twice with `SIGSEGV` (`exitCode 139`) during media-session teardown in the half-open-corridor window. Did not recur across four clean cycles. Needs bounded diagnosis |
| `PROOF_HARNESS_DEFECT-A` | `certutil` is absent from the pinned image; `tools/t3-media-prover/Dockerfile` never installs `libnss3-tools` → `spawn certutil ENOENT` |
| `PROOF_HARNESS_DEFECT-B` | `--user-data-dir` is passed to `chromium.launch()`; Playwright rejects it and demands `launchPersistentContext` |
| `PROOF_HARNESS_DEFECT-C` | The NSS database is created in a private profile directory; Chromium reads trust only from `$HOME/.pki/nssdb` |
| `PROOF_HARNESS_DEFECT-D` | Login still races SPA hydration — `#app` is visible before the form exists and `firstLocator()` uses non-auto-waiting `count()` |
| `PROOF_HARNESS_DEFECT-E` | Audio energy is read from the removed `track` statistic (always `0`); the live source is `inbound-rtp.totalAudioEnergy` |
| `PROOF_HARNESS_DEFECT-F` | In-dialog ACK and BYE reuse the original request URI instead of the dialog remote target from the 200 OK `Contact`, so the ACK never lands — 3 × 200 OK retransmissions, runtime BYE, `408 Request Timeout` |
| `PROOF_POLICY_DEFECT` | None. The three committed proof-only NetworkPolicies were sufficient and correctly selected |
| `PROOF_LIMITATION` | Asterisk 20's `pjsip show channel` does not expose the SIP Call-ID, so CLI-side literal Call-ID matching is unavailable. Correlation used baseline `0` channels, exactly one active channel asserted at the stimulus, extension `9900`, matching media counters, and — decisively — the browser matching the inbound BYE against its own Call-ID before answering |
| `EXPECTED_BEHAVIOR` | Asterisk rollout was required to deliver the `rtp.conf` added by `fde07d5`; without it the runtime range stays at the default `5000-31000` and the corridor is only intermittently correct |
| `EXPECTED_BEHAVIOR` | Redis `db0` `34 → 74` from ordinary API session and cache entries |

## Smallest Bounded Correction

One prover slice closes everything outstanding for the harness:

1. Install `libnss3-tools` in `tools/t3-media-prover/Dockerfile`.
2. Build the NSS database at `$HOME/.pki/nssdb` and remove the `--user-data-dir`
   launch argument (or switch to `launchPersistentContext`).
3. Read received audio energy from `inbound-rtp.totalAudioEnergy`, keeping
   `track` only as a fallback.
4. Use the dialog remote target from the 200 OK `Contact` as the Request-URI for
   in-dialog ACK and BYE.
5. Replace the `#app` wait plus `locator.count()` with an auto-waiting locator on
   the email field.
6. Extend `scripts/t3-media-prover/config-check` to assert `libnss3-tools`, the
   `$HOME/.pki/nssdb` path, the `inbound-rtp` energy source, and remote-target
   in-dialog routing, with matching mutation coverage.

Separately, `PRODUCT_DEFECT-16` (rtpengine `SIGSEGV` on teardown) needs a bounded
diagnosis slice.

## Cleanup

```text
proof Jobs / Pods / Secrets / ConfigMaps   deleted
proof NetworkPolicies (all three)          deleted
proof namespace                            deleted
diagnostic images (nodes + local)          removed
Chromium profiles / trust stores           in-Pod only, destroyed with the Pod
cookies / captures / traces                none retained
temporary Helm v4.0.3                      provisioned, verified, removed
.playwright-mcp/                           absent
credential or Authorization material       none remaining
```

Left in place, as required: the corrected production media NetworkPolicies, the
deployed Asterisk image carrying `rtp.conf`, and
`.runtime/tls/utcp-local-ca.crt` (public certificate only, no private key,
gitignored) which the committed runner requires.

Kamailio Ready, rtpengine Ready, Asterisk Ready, secondary runtime unchanged,
zero proof media sessions and allocations.

## Verification Performed

```text
git status / git log -20 / grep UTCP_PHASE versions.env
git merge-base --is-ancestor fde07d5 HEAD        yes
git merge-base --is-ancestor 48dc81e HEAD        yes
make repository-hygiene                          pass
make workflow-check                              pass
make secret-scan                                 pass
make k8s-config-check                            pass
make security-config-check / -test               pass
make media-config-check / -test                  pass
make kamailio-signaling-config-check / -test     pass
make t3-media-prover-config-check / -test        pass
make check                                       pass
make gateway-config-check                        pass (pinned Helm v4.0.3, removed)
git diff --check / git diff --cached --check     clean
kubectl diff for both production NetworkPolicies  exactly the four directions
```

## Status

```text
PRODUCT_DEFECT-15                       = closed
PRODUCT_DEFECT-16 (rtpengine SIGSEGV)   = open
T3-S2B in-cluster WebRTC media proof    = INCOMPLETE
                                          (media contract proven; committed
                                           prover cannot execute)
T3-S2C second-runtime parity            = Not Started
T3-S3 external media edge               = Not Started
T3-S2 overall                           = In Progress
T3                                      = In Progress
UTCP_PHASE=T1
```

```text
contained in-cluster media core     = proven
external browser media reachability = not proven
Asterisk                            = current reference runtime
runtime agnosticism                 = not yet proven
```

## Recommended Next Step

Bounded implementation of the six prover corrections above, then a re-run of
this proof with the committed prover only. `PRODUCT_DEFECT-16` can be diagnosed
in parallel.
