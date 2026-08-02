# T3-S2B Committed-Image In-Cluster WebRTC Media Proof

Date: 2026-08-02

Starting commit: `5bc95aa` (`fix(t3): make prover image verification executable`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2B_COMMITTED_MEDIA_PROOF_COMPLETE`

## Summary

The committed in-cluster WebRTC prover, built and published from `5bc95aa` and
executed through the committed runner and manifests only, reproduces the full
media contract in both directions.

Scenario A (browser-originated BYE) and Scenario B (runtime-originated BYE) both
exited `0` with empty error arrays. ICE and DTLS connected, reciprocal RTP flowed
through rtpengine to the reference runtime and back, Asterisk `Echo()` returned
the deterministic tone, and the browser measured positive, increasing
`inbound-rtp.totalAudioEnergy`. Both BYE directions deleted the media session and
released ports.

No scratchpad script, alternate image, manifest change, or runtime configuration
change was used. rtpengine did not restart in either scenario.

`PRODUCT_DEFECT-15` and `PROOF_HARNESS_DEFECT-G` are closed, and prover defects
`A`–`F` are proven closed at runtime.

## Repository Baseline

```text
HEAD           5bc95aa (branch main), working tree clean
UTCP_PHASE     T1
make t3-media-prover-config-check / -test    pass
make media-config-check / -test              pass
make security-config-check / -test           pass
node tools/t3-media-prover/sip-dialog-test.mjs   pass
```

## Runtime Baseline

```text
kamailio      kamailio-56b99d4b57-kldt4       uid 41dfde71-…  restarts 4   Ready
rtpengine     rtpengine-74cd786966-8vhff      uid 0fbd6b20-…  restarts 2   Ready
              started 2026-08-01T22:17:53Z
              lastState terminated exitCode 139, finishedAt 22:17:40Z (historical)
asterisk      asterisk-ari-676b58b676-dzfm4   uid 6e3b5c64-…  restarts 0   Ready
secondary     asterisk-ari-b-8557bd4d76-rcjfn uid 8a904cdd-…  restarts 15  Ready
policy generations   allow-rtpengine-media 2, allow-asterisk-sip-from-kamailio 4
rtpengine sessions own/foreign      0 / 0
rtpengine ports_used / ports_free   0 / 200
asterisk active channels            0   (4 calls processed)
database      tables 41, tenants 27, RuntimeNodes 110 (asterisk, simulator),
              pending outbox 0
redis         keys sip/dialog/rtp/media 0/0/0/0
```

PRODUCT_DEFECT-16 baseline: rtpengine uid `0fbd6b20-…`, restart count `2`.

## Committed Image Build and Publication

`make image-build-t3-media-prover` succeeded. The `certutil` verification
introduced by `5bc95aa` executes correctly: presence check, temporary SQL NSS
database creation, `cert9.db` and `key4.db` assertions, and removal of the
temporary directory.

Verified inside the built image:

```text
id             uid=1000(ubuntu) gid=1000(ubuntu) groups=1000(ubuntu)
HOME           /home/ubuntu
certutil       /usr/bin/certutil
node           v24.17.0
residual NSS   none — no cert9.db or key4.db anywhere in the image
/home/ubuntu/.pki   absent (created at runtime)
```

```text
source commit     5bc95aa319262e77854aa2b19fda5c9c872ea963
local image ID    e22fce960525
registry tag      utcp-local-registry:5000/utcp/t3-media-prover:0.1.0-k1-dev
registry digest   sha256:e22fce960525a8e3249ac0cb8d2bbc74df6c2eb5482167feef6234fadd2a9304
build timestamp   2026-08-02 08:25:43 +0800
```

Stale same-tag images had already been purged from all three k3d nodes, so no
earlier digest could shadow the new one. No diagnostic image was built or used.

## Runtime Image Digest

Captured from the live Scenario A Pod:

```text
POD      t3-media-prover-lf2vb
NODE     k3d-utcp-local-server-0
IMAGE    utcp-local-registry:5000/utcp/t3-media-prover:0.1.0-k1-dev
IMAGEID  utcp-local-registry:5000/utcp/t3-media-prover@sha256:e22fce960525a8e3249ac0cb8d2bbc74df6c2eb5482167feef6234fadd2a9304
```

The runtime `imageID` digest equals the newly published digest exactly — not tag
equality. Node-resident image inventory confirms only that digest exists for the
tag, and only on the node both Pods ran:

```text
k3d-utcp-local-server-0   0.1.0-k1-dev  e22fce960525a
k3d-utcp-local-agent-0    not cached
k3d-utcp-local-agent-1    not cached
```

## Certutil and NSS Trust

Trust operated at runtime with normal verification. The committed prover created
`$HOME/.pki/nssdb` with `certutil -N`, imported the public local CA with
`certutil -A -n 'UTCP local public CA' -t C,,`, and Chromium then completed a
clean HTTPS/WSS session against the canonical hostnames.

Rendered proof overlay:

```text
HOME                 /home/ubuntu
NODE_EXTRA_CA_CERTS  /etc/ssl/certs/utcp-local-ca.crt
local-ca volume      configMap t3-media-prover-local-ca, key ca.crt
                     -> subPath utcp-local-ca.crt, readOnly
scenario             configMapKeyRef t3-media-prover-scenario / scenario
credentials          secretRef (ephemeral, runner-created)
private key mount    none
```

Prover source contains no `ignoreHTTPSErrors`, `--ignore-certificate-errors`, or
`--user-data-dir`. No ClusterIP browser URL, no CoreDNS edit, no host route.

## Playwright MCP Natural Login

Independent evidence, from the real HTTPS login page, no injected cookie, local
storage, Redis session, database session, or preset authenticated state. Its
session was not passed to the in-cluster prover.

```text
start                 https://app.utcp.local.test/login   (title "web" pre-hydration)
hydration             waited for real controls; title became "Sign in - UTCP"
input[type=email]     present, visible, enabled
input[type=password]  present, enabled
button[type=submit]   present, enabled, text "Sign in"
TLS                   https, no certificate error
after submit          https://app.utcp.local.test/dashboard  (Dashboard - UTCP)
app shell             .app-shell, nav, [data-testid="app-shell"] -> 2 matches
GET /api/v1/auth/session   HTTP 200
user.status                active
password_change_required   false
memberships                1 — tenant slug "local", "Local Tenant",
                           status active, membership_status active
capabilities               [] (plain tenant-member)
catalog_version            c5.2026-07-15
logout                     HTTP 200
session after logout       HTTP 401
```

`.playwright-mcp/` removed and confirmed absent.

## Committed Prover Natural Login

Performed independently inside both Jobs. Both scenarios reached SIP, media, and
BYE stages, which are only reachable after `naturalLogin()` resolves the real
email, password, and submit controls, submits the form, waits for the URL to
leave `/login`, and waits for the authenticated application shell. Neither run
reported a TLS or selector error, and no preset session was supplied — the Job
receives only credentials through the ephemeral Secret.

## Scenario A — Browser-Originated BYE

Explicitly selected `UTCP_T3_MEDIA_PROVER_SCENARIO=browser-originated-bye`,
executed by `make t3-media-prover-run`.

```text
job terminal state   complete        pod phase Succeeded      exit code 0
scenario             browser-originated-bye
callId               0c7fa91907c618@utcp-t3-media-prover
errors               []
durationMs           5194
```

### SIP remote target and ACK

The committed `createDialog()` parses the 2xx `Contact` into `remoteTarget`, and
`inDialogRequest()` uses it as the Request-URI for ACK and BYE while the `To`
URI stays the dialog remote URI. `sip-dialog-test.mjs` covers this contract and
passes.

Live confirmation: the dialog completed with **no `200 OK` retransmission** and
**no `Reason: cause=408`** — the ACK reached the runtime, which is the decisive
signal established in T3-S2A. The BYE received `SIP/2.0 200 OK`, proving the
in-dialog Request-URI, route set, Call-ID, tags, and CSeq progression were all
accepted by Kamailio and Asterisk.

### ICE and DTLS

```text
iceGatheringState        complete
iceConnectionState       connected (closed after teardown)
peerConnectionState      connected (closed after teardown)
dtlsState                connected
selectedCandidatePair    CPoQvbBGRF_/Ol4H9UM
localCandidateType       host, private   (prover Pod)
remoteCandidateType      host, private   (rtpengine media Pod)
```

No direct Asterisk media candidate, no public or developer-host address.

### Reciprocal RTP and Echo

rtpengine per-leg accounting for the exact proof Call-ID:

```text
Media #1 (audio over RTP/SAVPF)  10.42.0.179:40030 <> 10.42.1.6:53403
                                 in 187 p / 31908 b, out 181 p / 34447 b
Media #1 (audio over RTP/AVP)    10.42.0.179:40002 <> 10.42.1.254:15094
                                 in 179 p / 30788 b, out 185 p / 31820 b
RTCP                             10.42.0.179:40003 <> 10.42.1.254:15095
```

The runtime-facing destination `15094` is inside the runtime RTP range
`10000-20000` and the corrected policy window. Asterisk reported symmetric
receive and transmit while running `Echo()`:

```text
pjsip show channelstats
anonymous-00000004 00:00:01 ulaw    60  0  0  0.000    60  0  0  0.002  0.000
anonymous-00000004 00:00:03 ulaw   164  0  0  0.003   164  0  0  0.003  0.000
                                    ^rx                 ^tx
```

Browser statistics match end to end:

```text
outboundRtpPackets 179   outboundRtpBytes 28640
inboundRtpPackets  179   inboundRtpBytes  28640
jitter 0.002   packetsLost 0
```

Inbound exactly equals outbound — deterministic echo, not merely packet presence.

### Audio energy

```text
audioEnergy        0.11235139465764898
audioEnergySource  inbound-rtp.totalAudioEnergy
```

The committed `assertMediaCounters()` requires the value to increase during the
proof interval, to be strictly positive, and to originate from
`inbound-rtp.totalAudioEnergy`; `assertStructuredResult()` re-asserts the source.
All passed.

### BYE and cleanup

```text
byeDirection     browser
finalSipResult   SIP/2.0 200 OK
cleanupResult    signaling-closed
rtpengine sessions own/foreign      0 / 0
rtpengine ports_used / ports_free   0 / 200
asterisk channels                   0 active (5 calls processed)
```

### Job collection

The committed runner waited for `Complete=True` or `Failed=True`, then collected
the Job condition, Pod phase, container exit code, structured JSON, and bounded
logs **before** cleanup:

```text
job_terminal_state=complete pod_phase=Succeeded pod_exit_code=0
```

## RTPengine Stability After Scenario A

```text
uid                0fbd6b20-…                unchanged
restart count      2                          unchanged
container start    2026-08-01T22:17:53Z       unchanged
last termination   exitCode 139, finishedAt 2026-08-01T22:17:40Z  (historical only)
ready              True
sessions / ports   0 / 0, ports_free 200
```

No new crash. Proceeded to Scenario B.

## Scenario B — Runtime-Originated BYE

Explicitly selected `UTCP_T3_MEDIA_PROVER_SCENARIO=runtime-originated-bye`.

```text
job terminal state   complete        pod phase Succeeded      exit code 0
committed runner     exit 0
scenario             runtime-originated-bye
callId               6d68b6127ddd5@utcp-t3-media-prover
errors               []
durationMs           6029
```

### Readiness marker

```text
UTCP_T3_MEDIA_PROVER_READY_FOR_RUNTIME_HANGUP
```

```text
occurrences in Scenario B stdout   1
occurrences in Scenario A          0
job active when observed           1
marker content                     exactly the marker, no sensitive data
```

The committed prover emits it from the browser context only when
`cfg.scenario === 'runtime-originated-bye'` and only after
`assertMediaCounters(before, after)`, relayed to Job stdout by a console handler
that forwards nothing else. Readiness was never inferred from elapsed time.

### ICE, DTLS, RTP and Echo

```text
iceGatheringState  complete        dtlsState  connected
selectedCandidatePair CPXNAgPpmp_6mcG7/J+
local / remote candidate   host, private / host, private
outboundRtpPackets 179   outboundRtpBytes 28640
inboundRtpPackets  179   inboundRtpBytes  28640
audioEnergy        0.11235139465764898
audioEnergySource  inbound-rtp.totalAudioEnergy
jitter 0.002   packetsLost 0
```

rtpengine per-leg accounting for the proof Call-ID:

```text
Media #1 (audio over RTP/SAVPF)  10.42.0.179:40088 <> 10.42.1.7:60472
                                 in 226 p / 38616 b, out 222 p / 42155 b
Media #1 (audio over RTP/AVP)    10.42.0.179:40062 <> 10.42.1.254:16728
                                 in 220 p / 37840 b, out 224 p / 38528 b
RTCP                             10.42.0.179:40063 <> 10.42.1.254:16729
```

## Runtime Hangup Stimulus

Issued only after the readiness marker, against exactly one channel.

```text
baseline active channels     0
active channels at stimulus  1   (asserted)
proof channel                PJSIP/anonymous-00000005
channel exten                9900
channel state                Up
channelstats before hangup   rx 215 / tx 215, jitter 0.003 / 0.002, loss 0
command                      channel request hangup PJSIP/anonymous-00000005
result                       Requested Hangup on channel 'PJSIP/anonymous-00000005'
```

Reciprocal RTP was positive in both directions before the stimulus, so the ACK
had already reached Asterisk; there was no retransmitted `200 OK`, no ACK
timeout, and no `Reason: cause=408`. No ARI, AMI, dialplan change, configuration
reload, or broad channel termination was used.

## Scenario B BYE and Cleanup

```text
1.  Asterisk generated BYE                        yes
2.  Kamailio routed it via the dialog route        yes (existing WebSocket reused)
3.  browser matched the BYE to its dialog          yes, matched on its own Call-ID
4.  browser returned 200 OK                        yes
5.  response reached Asterisk                      yes, channel terminated
6.  MEDIA_DELETE executed                          yes
7.  rtpengine session disappeared                  yes
8.  media ports returned to zero                   yes
9.  browser dialog terminated                      yes
10. Asterisk channel terminated                    yes (0 active, 6 processed)
11. retransmission / timeout remaining             none
12. structured result collected before cleanup     yes
13. Job exit status                                0
```

```text
byeDirection    runtime
finalSipResult  200 OK
cleanupResult   signaling-closed
```

## RTPengine Stability After Scenario B

```text
uid                0fbd6b20-…                unchanged
restart count      2                          unchanged
container start    2026-08-01T22:17:53Z       unchanged
last termination   exitCode 139, finishedAt 2026-08-01T22:17:40Z  (historical only)
ready              True
ERROR / CRIT / SEGV lines in the current container   0
sessions / ports   0 / 0, ports_free 200
```

## PRODUCT_DEFECT-16 Status

```text
PRODUCT_DEFECT-16:
  not reproduced during two clean committed-prover scenarios
  does not block T3-S2B closure or FreeSWITCH parity
```

The rtpengine Pod UID, restart count, container start time, and last-termination
record were identical before Scenario A, between the scenarios, and after
Scenario B. The only recorded termination remains the historical
`2026-08-01T22:17:40Z` `exitCode 139`. No root cause is claimed and no workaround
was introduced.

## Provider-Neutrality Assessment

The committed prover's success criteria use only SIP dialog state, the response
`Contact` remote target, the route set, ICE state, DTLS/peer state, RTP packets
and bytes, `inbound-rtp.totalAudioEnergy`, BYE direction, final SIP response, and
cleanup state.

`make t3-media-prover-config-check` passes, rejecting ARI, AMI, Asterisk channel
identifiers, `fs_cli`, FreeSWITCH ESL, `hostNetwork`, `ignoreHTTPSErrors`,
`--ignore-certificate-errors`, and `max-bundle` in the prover source. No
Asterisk-specific browser target or provider-specific SDP success marker exists.
The Asterisk CLI appeared only as the Scenario B stimulus.

```text
provider-neutral media contract:  proven against the Asterisk reference runtime
runtime agnosticism:              not yet proven
```

## Containment Preservation

```text
rtpengine Service            ClusterIP only
asterisk-sip Service         ClusterIP only
NodePort / LoadBalancer with media ports    none
                             (only traefik-system/traefik LoadBalancer TCP 80/443)
k3d UDP / media publication  none
HostPort / HostNetwork       none
public UDPRoute              none
Pod-CIDR ipBlock             none
direct prover-to-Asterisk media permission   none
host route to media candidate:
  ip route get 10.42.0.179 -> via 192.168.86.1 dev wlo1
```

The external host still resolves the rtpengine media candidate through its
default gateway with no route to the Pod CIDR. Proof-only egress remained DNS
UDP/TCP `53`, application/WSS TCP `443`, and rtpengine media UDP `40000-40099`.

## External Media Edge Status

```text
contained in-cluster media core:      proven using the committed image and scripts
external browser media reachability:  not proven
```

External media exposure remains T3-S3 scope.

## State and Workload Preservation

Full-cluster Pod snapshot diff between baseline and final is **empty** — every
workload retained its UID and restart count and all are Ready.

```text
Value                              Before   After
database public tables             41       41
tenants                            27       27
RuntimeNodes                       110      110
RuntimeNode families               asterisk, simulator   unchanged
pending outbox                     0        0
Redis sip/dialog/rtp/media         0/0/0/0  0/0/0/0
rtpengine sessions own/foreign     0/0      0/0
rtpengine ports_used / ports_free  0/200    0/200
Asterisk active channels           0        0
allow-rtpengine-media generation   2        2
allow-asterisk-sip-from-kamailio   4        4
```

Two proof members, memberships, and telephony sessions were created through the
canonical API for credential issuance; tenant and RuntimeNode counts are
unchanged. Redis `db0` movement is ordinary session and cache activity.

## Findings

| Classification | Finding |
|---|---|
| `PASS` | The committed image builds from `5bc95aa`; the new `certutil` verification executes correctly (presence check, temporary SQL NSS database, `cert9.db`/`key4.db` assertions, cleanup) and leaves no residual NSS material. **`PROOF_HARNESS_DEFECT-G` is closed** |
| `PASS` | The runtime Pod `imageID` digest equals the newly published digest exactly; only that digest is node-resident for the tag |
| `PASS` | `certutil` and `$HOME/.pki/nssdb` trust operate at runtime under normal TLS verification — no `ignoreHTTPSErrors`, no `--ignore-certificate-errors`, no `--user-data-dir`, no private key, no ClusterIP browser URL |
| `PASS` | Independent Playwright MCP natural login succeeds from the real login page and the session is invalidated on logout |
| `PASS` | The committed Job independently performs natural login in both scenarios |
| `PASS` | Scenario A: ACK and BYE use the 2xx `Contact` remote target; no `200 OK` retransmission and no `Reason: cause=408`; BYE receives `SIP/2.0 200 OK` |
| `PASS` | Scenario A: ICE `connected`, DTLS `connected`, reciprocal RTP `179/28640` in both directions, Asterisk `rx 164 / tx 164` under `Echo()`, runtime leg on `10.42.1.254:15094` inside `10000-20000` |
| `PASS` | Scenario A: `audioEnergy 0.1124` from `inbound-rtp.totalAudioEnergy`, positive and increasing; media session deleted and ports released; Job exit `0` with evidence collected before cleanup |
| `PASS` | Scenario B: readiness marker observed exactly once on stdout, only for Scenario B, after all media assertions, with the Job still active |
| `PASS` | Scenario B: bounded hangup on exactly one `9900` `Up` channel with `rx 215 / tx 215`; runtime BYE reached the browser, answered `200 OK`, media deleted, ports released, Job exit `0` |
| `PASS` | rtpengine did not restart in either scenario; zero `ERROR`/`CRIT`/`SEGV` lines in the current container |
| `PASS` | Containment and default-deny fully preserved; the external host still has no route to the media candidate |
| `PASS` | No production workload restarted; no production resource changed; all state authority values unchanged |
| `PRODUCT_DEFECT-16` | Not reproduced during two clean committed-prover scenarios. Does not block T3-S2B closure or FreeSWITCH parity. No root cause claimed |
| `PROOF_HARNESS_DEFECT` | None remaining. Defects `A`–`G` are all closed |
| `PROOF_POLICY_DEFECT` | None. The three committed proof-only NetworkPolicies were sufficient and correctly selected |
| `PROOF_LIMITATION` | Asterisk 20's CLI does not expose a channel's SIP Call-ID, so channel selection used a zero-channel baseline, an asserted single active channel, extension `9900`, `Up` state, and matching media counters. The dialog identity is nonetheless exact: the prover matched the inbound BYE against its own Call-ID before answering `200 OK` |

## Cleanup

```text
Scenario A and B Jobs / Pods      deleted by the committed runner
proof Secrets                     deleted
scenario ConfigMap                deleted
public CA proof ConfigMap         deleted
proof-only NetworkPolicies        deleted (all three)
proof namespace                   deleted
NSS databases / browser profiles  in-Pod only, destroyed with the Pods
cookies and traces                none retained
structured-result scratch files   .runtime/t3-media-prover removed
temporary Helm v4.0.3             provisioned, verified, removed
.playwright-mcp/                  removed, absent
credential material               none remaining
```

Retained deliberately: the canonical published prover image
`utcp-local-registry:5000/utcp/t3-media-prover:0.1.0-k1-dev` at digest
`sha256:e22fce960525…`, which is the established local-registry artifact for
`5bc95aa` rather than a temporary diagnostic tag; the corrected production media
NetworkPolicies (generations `2` and `4`); the committed Asterisk runtime image;
and `.runtime/tls/utcp-local-ca.crt` (public certificate only, no private key,
gitignored) which the committed runner requires.

Kamailio Ready, rtpengine Ready, Asterisk Ready, secondary runtime unchanged,
zero proof media sessions and allocations, zero active channels.

## Verification Performed

```text
git status / git log -20 / grep UTCP_PHASE versions.env
make t3-media-prover-config-check / -test        pass
make media-config-check / -test                  pass
make security-config-check / -test               pass
make repository-hygiene                          pass
make workflow-check                              pass
make secret-scan                                 pass
make k8s-config-check                            pass
make kamailio-signaling-config-check / -test     pass
make check                                       pass
make gateway-config-check                        pass (pinned Helm v4.0.3, removed)
node tools/t3-media-prover/sip-dialog-test.mjs   pass
make image-build-t3-media-prover                 pass
git diff --check / git diff --cached --check     clean
```

## Status

```text
PRODUCT_DEFECT-15                        = closed
PROOF_HARNESS_DEFECT-G                   = closed
committed prover defects A-F             = closed
PRODUCT_DEFECT-16                        = not reproduced; non-blocking

T3-S2B in-cluster WebRTC media proof     = Complete
T3-S2C second-runtime parity             = Not Started
T3-S3 external media edge                = Not Started
T3-S2 overall                            = In Progress pending second-runtime parity
T3                                       = In Progress
UTCP_PHASE=T1
```

```text
contained in-cluster media core:      proven using the committed image and scripts
external browser media reachability:  not proven
Asterisk:                             current reference runtime
runtime agnosticism:                  not yet proven
```

## Recommended Next Step

Bounded FreeSWITCH parity adapter against the unchanged provider-neutral
signaling and media contracts. External media exposure remains a separate T3-S3
architecture slice.
