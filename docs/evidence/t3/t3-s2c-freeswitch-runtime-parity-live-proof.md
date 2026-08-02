# T3-S2C FreeSWITCH Runtime Parity Live Proof

Date: 2026-08-02

Starting commit: `04d06a9` (`fix(t3): consume auth before runtime relay`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2C_FREESWITCH_LIVE_PARITY_COMPLETE`

## Summary

FreeSWITCH runtime parity is **proven live**. With `consume_credentials()` in the
authenticated Kamailio request path and `alias="false"` on the FreeSWITCH
`utcp-internal` domain declaration, the browser digest credentials no longer
reach the downstream runtime, FreeSWITCH stops challenging, and both committed
scenarios pass end to end using the unchanged committed WebRTC prover.

`PRODUCT_DEFECT-22` is closed. The provider-neutral application-runtime contract
is now proven against **two** runtimes — Asterisk (T3-S2B) and FreeSWITCH — with
no second signalling route, no second media route, no fallback, and no
provider-specific browser logic.

## Repository Baseline

```text
HEAD           04d06a9 (branch main), working tree clean
UTCP_PHASE     T1
make kamailio-signaling-config-check / -test    pass
make freeswitch-config-check / -test            pass
make freeswitch-overlay-check / -test           pass
make freeswitch-startup-smoke-test              pass  (now includes an INVITE smoke)
make media-config-check                         pass
make security-config-check                      pass
make t3-media-prover-config-check               pass
```

## Canonical Local Reconciliation To `04d06a9`

`kubectl diff -k overlays/local` contained exactly the expected bounded delta —
two objects, no unrelated drift:

```text
ConfigMap/utcp-platform/kamailio-config     + consume_credentials();
Deployment/utcp-platform/kamailio           checksum 9cb5e1f1… -> 89bdad9d…, gen 19 -> 20
```

Applied through the canonical overlay. Kamailio rolled cleanly to
`uid d80fdf64-…`, `restarts 1`, Ready. Re-running the diff afterwards returned
**exit 0 with zero changed objects**.

## Running Authentication Boundary

The live ConfigMap places `consume_credentials()` exactly once, after
authentication and identity validation and before any runtime-facing work:

```text
 8:  if (!www_authorize("$fd", "kamailio_signaling_auth_view")) { ... exit; }
15:  auth_identity_mismatch -> exit
20:  consume_credentials();
22:  if ($proto == "ws" || $proto == "wss") { add_contact_alias() ... }
30:  route(MEDIA_OFFER);
31:  record_route();
32:  route(APPLICATION_RUNTIME_RELAY);
```

```text
consume_credentials() occurrences in the running config   1
consume_credentials() in the REGISTER path                0
failed authentication reaching runtime relay              impossible (exit before line 20)
FreeSWITCH-specific auth retry                            none
downstream runtime credential                             none
live Deployment checksum                                  89bdad9d5ea4fe2df1d95559f021420158e31cd17936fa8cf7451b960bae5104
```

## FreeSWITCH Image

```text
source commit        04d06a9c6dcd14e3d5e15b8acea4d47492920a21
registry tag         utcp-local-registry:5000/utcp/freeswitch:0.1.0-t3-s2c
registry digest      sha256:0c810f4b5e8caa600daff0c626d2961a2cb19ac3a3c3bcd7b3ffcd172974219c
local image ID       0c810f4b5e8c
configuration digest 89ecc33d8f6413b3…
runtime identity     uid=1000 gid=1000
```

## Parity Delta And Runtime

`kubectl diff -k overlays/local-freeswitch` contained only the five documented
objects: `asterisk-ari` replicas `1→0`, the FreeSWITCH Deployment, the ESL
Secret, the `application-runtime-sip` selector narrowing, and `freeswitch-sip`.
No unrelated image, ConfigMap, Secret, Service, Deployment, or NetworkPolicy
change.

```text
Pod             freeswitch-77fbf94d74-cwv7n
Pod imageID     …/utcp/freeswitch@sha256:0c810f4b5e8caa600daff0c626d2961a2cb19ac3a3c3bcd7b3ffcd172974219c
restarts        0        Ready True
sofia           utcp-internal  BIND-URL sip:mod_sofia@10.42.1.15:5060;transport=udp
context         utcp     RTP-IP 10.42.1.15
selection       application-runtime-sip -> 10.42.1.15 (FreeSWITCH) only
asterisk-ari    replicas 0
```

The Pod `imageID` digest equals the published digest exactly.

## Playwright MCP Natural Login

```text
start                 https://app.utcp.local.test/login   (hydrated, HTTPS, no TLS error)
after submit          https://app.utcp.local.test/dashboard
app shell             .app-shell, nav -> 2 matches
GET /api/v1/auth/session   HTTP 200, user.status active, password_change_required false
membership            1 — tenant slug "local", "Local Tenant", both statuses active
capabilities          [] (plain tenant-member)     catalog_version c5.2026-07-15
logout                HTTP 200      session after logout   HTTP 401
```

No injected cookie, storage, Redis, or database session. The MCP session was not
shared with the in-cluster prover; `.playwright-mcp/` removed.

## Scenario A — Browser-Originated BYE

```text
job terminal state   complete     pod phase Succeeded     exit code 0
callId               d2de2e809d08a8@utcp-t3-media-prover
errors               []           durationMs 5938
```

### Authentication boundary on the wire

Header **names** only, captured in the FreeSWITCH node network namespace; no
credential values were recorded:

```text
kamailio -> freeswitch  INVITE sip:9900@sip.utcp.local.test  CSeq: 2 INVITE  auth_headers=NONE
freeswitch -> kamailio  SIP/2.0 100 Trying                   CSeq: 2 INVITE  auth_headers=NONE
freeswitch -> kamailio  SIP/2.0 200 OK                       CSeq: 2 INVITE  auth_headers=NONE
kamailio -> freeswitch  ACK sip:9900@10.42.1.15:5060;transport=udp  CSeq: 2 ACK   auth_headers=NONE
kamailio -> freeswitch  BYE sip:9900@10.42.1.15:5060;transport=udp  CSeq: 3 BYE   auth_headers=NONE
```

No `Authorization` and no `Proxy-Authorization` reached FreeSWITCH. **No `401`,
`407`, or `403`** was returned — the `407` that blocked the previous attempt is
gone. The ACK and BYE both target the dialog remote target
`sip:9900@10.42.1.15:5060;transport=udp` taken from the 2xx `Contact`. Exactly
one INVITE branch reached FreeSWITCH; Asterisk had no Pod and received nothing.

### FreeSWITCH channel

```text
uuid 0a8fe80b-a78b-4f6c-8aff-2a511fb06e97  inbound  CS_EXECUTE  ACTIVE
sofia/utcp-internal/ts-…@sip.utcp.local.test   dest 9900   app echo   context utcp
codec PCMU/8000/64000 both directions
```

### ICE, DTLS and media

```text
iceGatheringState  complete      dtlsState connected
selectedCandidatePair CPS724JUAZ_HhfOEbDS
local / remote candidate   host, private / host, private
outboundRtpPackets 179   outboundRtpBytes 28640
inboundRtpPackets  178   inboundRtpBytes  28480
audioEnergy        0.11166849224328824   source inbound-rtp.totalAudioEnergy
jitter 0.002   packetsLost 0
```

rtpengine per-leg accounting for the proof Call-ID:

```text
Media #1 (audio over RTP/SAVPF)  10.42.0.179:40011 <> 10.42.1.17:40264
                                 in 186 p / 31736 b, out 180 p / 34259 b
Media #1 (audio over RTP/AVP)    10.42.0.179:40046 <> 10.42.1.15:21018
                                 in 178 p / 30616 b, out 184 p / 31648 b
RTCP                             10.42.0.179:40047 <> 10.42.1.15:21019
```

The FreeSWITCH-facing destination `21018` (and RTCP `21019`) is inside the
committed `21000-21099` range; return traffic lands on rtpengine's
`40000-40099`.

### BYE and cleanup

```text
byeDirection    browser      finalSipResult  SIP/2.0 200 OK
cleanupResult   signaling-closed
rtpengine sessions / ports_used     0 / 0
FreeSWITCH channels                 0 total
```

## Scenario B — Runtime-Originated BYE

```text
job terminal state   complete     pod phase Succeeded     exit code 0
committed runner     exit 0
callId               056b1e5ac11d5@utcp-t3-media-prover
errors               []           durationMs 6401
```

### Readiness marker

```text
UTCP_T3_MEDIA_PROVER_READY_FOR_RUNTIME_HANGUP
occurrences  1        job active when observed  1
baseline channels before the run  0
```

### Media

```text
iceGatheringState complete    dtlsState connected
outboundRtpPackets 180  outboundRtpBytes 28800
inboundRtpPackets  178  inboundRtpBytes  28480
audioEnergy 0.1122658945856622  source inbound-rtp.totalAudioEnergy
jitter 0.003   packetsLost 0

Media #1 (RTP/SAVPF)  10.42.0.179:40068 <> 10.42.1.18:33220  in 232 p / out 227 p
Media #1 (RTP/AVP)    10.42.0.179:40080 <> 10.42.1.15:21006  in 225 p / out 230 p
RTCP                  10.42.0.179:40081 <> 10.42.1.15:21007
```

### Bounded loopback hangup stimulus

Selected against a zero-channel baseline with exactly one active channel:

```text
active_channels        1
proof_channel_uuid     ac41d4a1-9ae5-4e1e-bb5f-cf8780c8a5d6
destination            9900        application echo        state CS_EXECUTE
command                fs_cli -H 127.0.0.1 -P 8021 -x 'uuid_kill <uuid>'
```

Loopback ESL only — no exposed ESL, no Service, no management API, no
browser-side FreeSWITCH knowledge.

### FreeSWITCH-originated BYE

```text
freeswitch -> BYE sip:ts-…@1c7363e54165c8.invalid;alias=10.42.0.180~35398~5;transport=ws
              CSeq: 118271202 BYE   auth_headers=NONE
browser    -> SIP/2.0 200 OK        CSeq: 118271202 BYE   auth_headers=NONE
```

Kamailio routed the BYE through the proven alias corridor, the browser matched it
to its own dialog and answered `200 OK`, and the response reached FreeSWITCH.
`MEDIA_DELETE` executed; sessions and ports returned to `0 / 0`; both sides
terminated with no retransmission or timeout.

```text
byeDirection  runtime     finalSipResult  200 OK     cleanupResult  signaling-closed
```

## Selected-Runtime Unavailable Behavior

```text
kubectl -n utcp-runtime scale deploy/freeswitch --replicas=0
application-runtime-sip Ready endpoints   <none>
asterisk-ari                              replicas 0 (unchanged)
```

One authenticated SDP INVITE through the canonical path produced exactly the
committed failure sequence:

```text
kamailio_application_dialog_challenge result=challenge   method=INVITE call_id=cbb942cac85f8@…
kamailio_application_dialog_media     result=media_offer method=INVITE call_id=cbb942cac85f8@…
kamailio_application_dialog_media     result=media_delete method=INVITE call_id=cbb942cac85f8@…
kamailio_application_dialog_rejected  result=application_runtime_unavailable method=INVITE
```

```text
canonical runtime-unavailable response     yes (503 Application Runtime Unavailable)
INVITE delivered to Asterisk               none — Asterisk had no Pod
second runtime selected                    none
dual branch                                none
direct-media fallback                      none
offer-created rtpengine session            deleted (media_delete)
media allocations after                    sessions 0 / ports_used 0
```

FreeSWITCH was then restored through the committed parity overlay and returned to
Ready with `application-runtime-sip -> 10.42.1.20`.

## Zero Asterisk Fallback

Across Scenario A, Scenario B, and the unavailable-runtime test:

```text
Asterisk replicas during parity           0
Asterisk Pods                             none ("No resources found in utcp-runtime namespace")
Asterisk INVITEs received                 0
Asterisk active channels                  0
Asterisk media packets                    0
application-runtime-sip endpoints         FreeSWITCH only
```

This is runtime evidence, not configuration inference: Asterisk had no running
Pod for the entire parity window, so no branch could have reached it.

## Provider Neutrality

The unchanged committed prover asserts only on SIP dialog state, the response
`Contact` remote target, the route set, ICE, DTLS, RTP packets and bytes,
`inbound-rtp.totalAudioEnergy`, BYE direction, final SIP response, and cleanup
state. `make t3-media-prover-config-check` passes, rejecting ARI, AMI, channel
identifiers, `fs_cli`, FreeSWITCH ESL, and TLS-bypass markers in the browser
source. No FreeSWITCH Pod IP, channel UUID, `fs_cli`, ESL, or FreeSWITCH-specific
SDP marker appears in browser logic; the loopback `uuid_kill` was the external
Scenario B stimulus only.

The same prover binary and the same Kamailio routes
(`APPLICATION_RUNTIME_RELAY`, `APPLICATION_RUNTIME_UNAVAILABLE`, `MEDIA_OFFER`,
`MEDIA_ANSWER`, `MEDIA_DELETE`, `APPLICATION_RUNTIME_MEDIA_REPLY`) served both
Asterisk (T3-S2B) and FreeSWITCH with no provider-specific duplicate.

## Containment

```text
NodePort / LoadBalancer for FreeSWITCH / HostPort / HostNetwork   none
public SIP / public ESL / public RTP / public UDPRoute            none
Service or NetworkPolicy on TCP 8021                              none
ipBlock / all-UDP / all-egress rule                               none
dual runtime selector / Asterisk fallback selector                none
```

## Default Runtime Restoration

Restored through the canonical overlay, not manual patching:

```text
kubectl apply -k infrastructure/kubernetes/overlays/local
freeswitch Deployment / Service / ESL Secret     deleted (absent from the default overlay)
asterisk-ari                                     replicas 1, ready 1
application-runtime-sip endpoints                10.42.1.21 (asterisk-ari), Ready, Asterisk only
kubectl diff -k overlays/local                   exit 0, zero drift
all pods                                         Ready
```

## State And Workload Preservation

Workload delta versus the pre-proof baseline is exactly the two intended
rollouts:

```text
kamailio-68998b758d-qns2m   -> kamailio-866cf78686-8gwbf     (consume_credentials checksum)
asterisk-ari-54f778cb7b-8fclp -> asterisk-ari-54f778cb7b-s9fhz (parity scale 0 -> 1)
```

Every other workload retained its UID and restart count.

```text
database public tables   41      tenants 27      RuntimeNodes 110 (asterisk, simulator)
pending outbox           0       Redis sip/dialog/rtp/media 0/0/0/0
rtpengine sessions       0       ports_used 0
Asterisk active channels 0       FreeSWITCH active channels n/a (removed)
```

## Findings

| Classification | Finding |
|---|---|
| `PASS` | Canonical local reconciliation to `04d06a9` was exactly the two-object delta; post-apply diff exits `0` |
| `PASS` | `consume_credentials()` appears exactly once, after authentication and identity validation and before media and relay; REGISTER path unchanged; failed auth cannot reach relay |
| `PASS` | **`PRODUCT_DEFECT-22` closed** — no `Authorization` or `Proxy-Authorization` reaches FreeSWITCH, and FreeSWITCH returns `100 Trying` then `200 OK` with SDP instead of `407` |
| `PASS` | FreeSWITCH Pod runs the published digest `sha256:0c810f4b5e8c…`, zero restarts, Ready on committed exec probes, `utcp-internal` bound UDP `5060`, context `utcp` |
| `PASS` | `application-runtime-sip` resolved to FreeSWITCH alone with Asterisk at zero replicas; one branch, no fallback, no dual delivery |
| `PASS` | Scenario A: ACK and BYE use the 2xx `Contact` remote target, ICE and DTLS connect, reciprocal RTP with runtime leg on `10.42.1.15:21018` inside `21000-21099`, `audioEnergy 0.1117`, browser BYE `200 OK`, media and ports released, Job exit `0` |
| `PASS` | Scenario B: readiness marker observed exactly once while the Job was active, bounded loopback `uuid_kill` on exactly one `9900`/`CS_EXECUTE` channel, FreeSWITCH BYE carried the alias corridor, browser answered `200 OK`, media released, Job exit `0` |
| `PASS` | Selected-runtime unavailability produced the canonical `application_runtime_unavailable` path with `media_delete`, no Asterisk delivery, no second runtime, and zero residual allocation |
| `PASS` | Default Asterisk runtime restored through the canonical overlay with final zero drift and no FreeSWITCH residue |
| `PRODUCT_DEFECT` | `None.` |
| `PROOF_HARNESS_DEFECT` | `None.` The committed prover was unchanged and passed both scenarios |
| `PROOF_POLICY_DEFECT` | `None.` |
| `PROOF_LIMITATION` | `None.` The committed `freeswitch-startup-smoke-test` now drives a real INVITE and asserts `200 OK`, closing the gap that let the `407` reach a live proof |
| `EXPECTED_BEHAVIOR` | One Scenario A run failed on stale credentials because the scratchpad issuance suffix collided with an earlier proof user (`users_normalized_email_unique`). A fresh unique suffix resolved it. Proof-harness scripting only; no product behaviour involved |

## Cleanup

```text
proof Jobs / Pods / namespace / proof-only NetworkPolicies   removed by the committed runner
FreeSWITCH Deployment / Service / ESL Secret                 deleted
FreeSWITCH local and registry image tags, node caches        removed
structured-result scratch (.runtime/t3-media-prover)         removed
browser profiles / NSS databases / captures / traces         none retained
temporary Helm v4.0.3                                        provisioned, verified, removed
.playwright-mcp/                                             absent
credential material                                          none remaining
```

The committed FreeSWITCH NetworkPolicies and the Kamailio/rtpengine FreeSWITCH
corridor rules remain applied as canonical members of the security kustomization;
they are inert with no FreeSWITCH Pod present.

## Verification Performed

```text
make repository-hygiene / workflow-check / secret-scan / k8s-config-check   pass
make security-config-check / -test                                          pass
make media-config-check / -test                                             pass
make kamailio-signaling-config-check / -test                                pass
make t3-media-prover-config-check / -test                                   pass
make freeswitch-config-check / -test                                        pass
make freeswitch-overlay-check / -test                                       pass
make freeswitch-startup-smoke-test                                          pass
make check                                                                  pass
make gateway-config-check                                    pass (pinned Helm v4.0.3, removed)
node tools/t3-media-prover/sip-dialog-test.mjs                              pass
kubectl diff -k overlays/local            exit 0 before parity and after restoration
git diff --check / git diff --cached --check                                clean
```

## Status

```text
PRODUCT_DEFECT-17 through 22 = closed

T3-S2A = Complete
T3-S2B = Complete
T3-S2C FreeSWITCH parity = Complete
T3-S2 overall = Complete

provider-neutral application-runtime contract:
  proven against Asterisk and FreeSWITCH

T3-S3 external media edge = Not Started
T3 = In Progress
UTCP_PHASE=T1
```

External browser media readiness is **not** claimed; it remains T3-S3 scope.

## Recommended Next Step

Begin the separate T3-S3 external media-edge slice. Do not mix public browser
reachability, NAT, firewall, or advertised-address design into the now-proven
internal runtime-parity contract.
