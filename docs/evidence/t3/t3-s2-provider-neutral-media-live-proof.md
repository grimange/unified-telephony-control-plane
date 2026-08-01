# T3-S2 — Provider-Neutral RTPengine Live Media Proof

Verdict: `T3_S2_PROVIDER_NEUTRAL_MEDIA_LIVE_PROOF_INCOMPLETE`

Live proof of the provider-neutral media mediation committed as `afced8d`. Asterisk
is used only as the current reference application runtime; no Asterisk-specific
media authority was added or relied upon.

**Signalling-plane and SDP-plane mediation are fully proven.** A real WebRTC
browser offer is rewritten through rtpengine before reaching the application
runtime, the runtime answer is rewritten into a WebRTC-compatible answer before
reaching the browser, both BYE directions delete the media session, terminal
runtime failure cleans up media state, and rtpengine unavailability fails closed.

**One boundary is not proven: actual RTP/SRTP packet flow.** The developer host
has no route into the cluster pod network, which is the media containment T3-S1
deliberately established, so a host-side browser cannot complete ICE/DTLS with
rtpengine. This is an environment/tooling limitation, not a product defect, and it
leaves completion criteria 8, 9 and 10 unproven.

**T3-S2 remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

* Proof executed at `afced8d` (`feat(t3): add provider-neutral media mediation`).
* Branch `main`, working tree clean at start, `UTCP_PHASE=T1`, nothing pushed.

All six focused static authorities passed:

```text
make media-config-check                     exit 0
make media-config-check-test                exit 0
make kamailio-signaling-config-check        exit 0
make kamailio-signaling-config-check-test   exit 0
make security-config-check                  exit 0
make security-config-check-test             exit 0
```

## Media Authority Boundary

Generic routes present in the render, with rtpengine addressed only through its
existing internal control Service:

```text
route[MEDIA_OFFER]                                 declared, 2 invocations
route[MEDIA_ANSWER]                                declared, 1 invocation
route[MEDIA_DELETE]                                declared, 4 invocations
onreply_route[APPLICATION_RUNTIME_MEDIA_REPLY]     declared
modparam("rtpengine","rtpengine_sock","udp:rtpengine.utcp-platform.svc.cluster.local:2223")
```

Scanned **inside the media routes only**, every forbidden coupling is absent:

```text
ARI                       0        Asterisk CLI              0
AMI                       0        dialplan state            0
Asterisk channel id       0        Pod IP literal            0
database media state      0        Redis media state         0
direct-media / rtpproxy / bypass keywords (whole config)     0
REGISTER media operations                                    0
bidirectional dialog routing (WITHINDLG alias branch)        intact
```

Media identity is carried entirely by the SIP transaction and rtpengine's own
per-call state. No database or Redis media session exists, and no manual media
reconciliation path was introduced.

## Runtime Baseline

```text
kamailio      kamailio-c6bc4454d-sjvzt  uid aa5accfc-…  ready  restarts 1  ip 10.42.2.157
              checksum e064cd33…   live ConfigMap e064cd33…   generation 17
              live rtpengine.so occurrences: 0  -> afced8d not yet live
rtpengine     rtpengine-74cd786966-7dbxl  uid 245b78c5-…  ready  restarts 1  ip 10.42.0.166
              control Service ClusterIP 10.43.50.16, UDP 2223
              sessions own/foreign 0/0   ports_used internal/default 0/0   ports_free 100/100
application runtime  asterisk-ari-74d8c4b5f8-tfgxr  uid 6ba30804-…  ready  restarts 0  ip 10.42.2.150
endpoints     asterisk-sip 10.42.2.150 ready | kamailio-sip-internal 10.42.2.157 ready | rtpengine 10.42.0.166 ready
database      tables 41, dialog/rtp/media tables (none), tenants 27, RuntimeNodes 110
              (asterisk/asterisk-ari + simulator/simulator-deterministic), pending outbox 0
redis         dbsize 3, keys sip/dialog/rtp/media 0/0/0/0
```

Required precondition satisfied: `rtpengine sessions = 0`, `ports_used = 0`.

## Resources Applied

Two, in order. `kubectl diff` restricted to them contained only the correction.

| Resource | Before | After | Material change |
|---|---|---|---|
| `ConfigMap/utcp-platform/kamailio-config` | sha256 `e064cd33…` | rv `466357`, sha256 **`0d788689…`** | `rtpengine.so`, `rtpengine_sock` modparam, `FLT_MEDIA_RELAY_REQUIRED`, the four generic media routes, and their invocations |
| `Deployment/utcp-platform/kamailio` | generation `17` | generation **`18`**, rv `466362` | checksum annotation only |

Before the Deployment apply the Deployment remained at generation `17` with the
old checksum. Image and security context unchanged; no rollout timestamp.

## Kamailio Rollout Result

```text
deployment applied : 2026-08-01T10:51:45Z
rollout complete   : 2026-08-01T10:51:50Z  (~5 seconds)
new ReplicaSet     : kamailio-56b99d4b57  revision 18  desired 1  ready 1
new Pod            : kamailio-56b99d4b57-kldt4  uid 41dfde71-…  ip 10.42.2.158
container started  : 2026-08-01T10:51:47Z      Ready: 2026-08-01T10:51:50Z
old Pod retirement : Created + Pulled for the new Pod, then SuccessfulDelete / Killing kamailio-c6bc4454d-sjvzt
conditions         : Available=True (MinimumReplicasAvailable), Progressing=True (NewReplicaSetAvailable)
restart count      : 1     ERROR lines in the running container: 0
manual restart / Pod deletion / reload RPC / timestamp annotation : none
unrelated workloads rolled : none
```

The rtpengine module bound to its control endpoint at startup:

```text
INFO: rtpengine [rtpengine.c:3428]: rtpp_test(): rtpengine instance
      <udp:rtpengine.utcp-platform.svc.cluster.local:2223> found, support for it enabled
```

The single restart is the known transient `postgres`/NetworkPolicy startup race;
it self-recovered with no parser or configuration error. `EXPECTED_BEHAVIOR`.

## Running Configuration Identity

**PASS.** Byte-identical across all four authorities:

```text
1 repository render        0d788689fd0b71ad0f8353c65172b004de659be6dfbfec7ebff4ba517a06ed0c
2 live ConfigMap           0d788689fd0b71ad0f8353c65172b004de659be6dfbfec7ebff4ba517a06ed0c
3 mounted in the Pod       0d788689fd0b71ad0f8353c65172b004de659be6dfbfec7ebff4ba517a06ed0c
4 Pod checksum annotation  0d788689fd0b71ad0f8353c65172b004de659be6dfbfec7ebff4ba517a06ed0c
```

Observed lifecycle, confirmed by correlated Kamailio logs on every corridor:

```text
initial SDP INVITE   -> media_offer before the application-runtime relay
SDP response         -> APPLICATION_RUNTIME_MEDIA_REPLY -> media_answer before client forwarding
BYE (either side)    -> media_delete, normal signaling relay preserved
terminal failure     -> media_delete from failure_route when an offer created media state
```

## Proof Client

No WebRTC-capable SIP client exists in the repository: `sip-wss-client.php` is
REGISTER-only and the Vue application carries no SIP stack. A disposable
WebRTC-capable client was therefore driven inside the **real application origin**:

* Playwright navigated to `https://app.utcp.local.test`, performed a **natural
  first-party login** through the real login form, and completed the application's
  own forced password change. No preset session, cookie, database or Redis state
  was injected.
* The page then obtained its SIP credential from **its own authenticated session**
  (`POST /api/v1/telephony/sessions` → `POST …/signaling-credential`, both `201`).
* Media used a genuine `RTCPeerConnection` with a deterministic 440 Hz Web Audio
  tone as the outbound track, giving real ICE candidates, a real DTLS fingerprint,
  `UDP/TLS/RTP/SAVPF` and `rtcp-mux` — not a synthetic SDP.
* Signalling ran over `wss://sip.utcp.local.test/ws` with normal subscriber digest
  authentication. The client never contacted Asterisk directly.

No credential or Authorization content is recorded here.

## Original Client SDP Offer

Representative offer generated by the browser (transient values abbreviated):

```text
m=audio 43826 UDP/TLS/RTP/SAVPF 111 63 9 0 8 13 110 126
c=IN IP4 172.19.0.1
a=candidate:… udp … 172.19.0.1 43826 typ host      (plus 172.24.0.1, 172.18.0.1,
a=candidate:… udp … 192.168.86.181 56996 typ host   172.17.0.1 and TCP candidates)
a=ice-ufrag:Xk5N          a=ice-pwd:<redacted>
a=fingerprint:sha-256 DD:9D:AF:…:78
a=setup:actpass           a=rtcp-mux        a=sendrecv
codecs: opus/48000/2, red, G722, PCMU, PCMA, CN, telephone-event
```

## Application-Runtime-Facing Offer

Captured on the Kamailio→runtime leg with a bounded node-namespace capture:

```text
o=- 3014062309174875530 2 IN IP4 10.42.0.166
m=audio 40012 RTP/AVP 111 63 9 0 8 13 110 126
c=IN IP4 10.42.0.166
```

| Requirement | Result |
|---|---|
| media address is the rtpengine runtime-facing relay address | **PASS** — `10.42.0.166` in both `o=` and `c=` |
| media port from the committed allocation | **PASS** — `40012`, inside `40000–40099` |
| browser direct address/candidates not exposed as the runtime's RTP destination | **PASS** — browser host addresses present `0` times |
| no Kamailio Pod IP, node IP or developer-host IP leak | **PASS** |
| runtime-facing transport profile matches the committed generic profile | **PASS** — `RTP/AVP`; ICE candidates `0`, `ice-ufrag` `0`, `fingerprint` `0`, `setup` `0` |
| minimal proven codec available | **PASS** — payload `0` (PCMU) offered |

The generic runtime profile is plain RTP with ICE and DTLS removed; that is a
profile choice for *any* application runtime, not an Asterisk-specific contract.

## Original Runtime SDP Answer

```text
o=- 3014062309174875530 4 IN IP4 10.42.2.150
c=IN IP4 10.42.2.150
m=audio 12682 RTP/AVP 0 126
a=rtpmap:0 PCMU/8000   a=rtpmap:126 telephone-event/8000   a=sendrecv
```

The runtime answers with its own Pod IP and its own RTP port.

## Client-Facing SDP Answer

Delivered to the browser after `MEDIA_ANSWER`:

```text
o=- … 4 IN IP4 10.42.0.166
m=audio 40092 RTP/SAVPF 0 126
c=IN IP4 10.42.0.166
a=rtcp:40092            a=rtcp-mux
a=setup:passive
a=fingerprint:sha-256 15:A8:9C:…:5C          <- rtpengine's own fingerprint
a=tls-id:…
a=ice-ufrag:g0wQCuIq    a=ice-pwd:<redacted>
a=candidate:… 1 UDP 2130706431 10.42.0.166 40092 typ host
a=end-of-candidates
a=rtpmap:0 PCMU/8000    a=rtpmap:126 telephone-event/8000
```

| Requirement | Result |
|---|---|
| rtpengine-facing media address | **PASS** — `10.42.0.166` |
| allocated media port | **PASS** — `40092` (also `40050`, `40048`, `40021` across runs), all in range |
| ICE data as required | **PASS** — `ice-ufrag`, `ice-pwd`, one host candidate, `end-of-candidates` |
| DTLS fingerprint / setup behaviour | **PASS** — rtpengine's own fingerprint, `setup:passive` |
| SRTP-capable transport profile | **PASS** — `RTP/SAVPF` |
| `rtcp-mux` | **PASS** |
| compatible codec | **PASS** — PCMU + telephone-event, both offered by the browser |
| direct runtime media address offered to browser | **rejected** — `10.42.2.150` absent |
| node IP / developer-host address / plain RTP to browser | **rejected** — none |

The browser accepted it: `setRemoteDescription` returned `ok` on every run,
confirming the rewritten answer is a valid WebRTC answer for the original offer.

## MEDIA_OFFER Result

**PASS.** For Call-ID `bba59a7691534d11@utcp-s2-media`:

```text
kamailio_application_dialog_media result=media_offer method=INVITE call_id=…
```

Exactly one `media_offer` per initial SDP offer; rtpengine accepted it; one
session was created (`rtpengine_sessions{own} 0 → 1`) with ports allocated
(`ports_used 0 → 3`, `ports_free 100 → 97`); and the forwarded offer was rewritten
as above.

## MEDIA_ANSWER Result

**PASS.**

```text
kamailio_application_dialog_media result=media_answer method=INVITE call_id=…
```

The runtime SDP response entered `onreply_route[APPLICATION_RUNTIME_MEDIA_REPLY]`,
which delegated to `route[MEDIA_ANSWER]`; exactly one `media_answer` per matching
answer; the same Call-ID and the same rtpengine instance were used; and the
browser-facing SDP was rewritten for WebRTC.

## ICE and DTLS Result

**NOT PROVEN — bounded by the proven media containment.**

```text
iceGatheringState      complete
remoteCandidates       ["10.42.0.166:40048"]   <- rtpengine's candidate was received and used
iceConnectionState     checking -> disconnected
connectionState        connecting -> failed
dtlsTransportState     new
outboundPacketsSent    0
selectedPair           none
```

The browser correctly parsed and attempted rtpengine's candidate. Connectivity
cannot succeed because the developer host has no route to the pod network:

```text
ip route get 10.42.0.166  ->  via 192.168.86.1 dev wlo1      (default gateway, wrong path)
TCP probe host -> 10.42.0.166:2224  ->  TimeoutError
```

This is exactly the media containment T3-S1 proved ("no developer-host socket for
`40000–40099`"). Adding a host route would have breached that proven contract, so
it was not done. Classified `PROOF_LIMITATION`.

## Browser-to-Runtime Media / Runtime-to-Browser Media / Echo Result

**NOT PROVEN**, for the same reason. `outboundPacketsSent = 0`, no inbound RTP,
no selected candidate pair, and therefore no echo. Per the proof contract, success
is **not** claimed from SDP alone — these three rows are reported as unproven.

## Browser-Originated BYE Delete

**PASS.** Call-ID `1f313a74ed7b4d20@utcp-s2-media`:

```text
media_offer  method=INVITE call_id=1f313a74ed7b4d20@utcp-s2-media
media_answer method=INVITE call_id=1f313a74ed7b4d20@utcp-s2-media
media_delete method=BYE    call_id=1f313a74ed7b4d20@utcp-s2-media
bye_request_uri   sip:10.42.2.150:5060
bye_final_status  200 OK        bye_cseq 3 BYE
```

Existing bidirectional signalling succeeded, the BYE reached the runtime, the
runtime returned `200 OK`, the browser received it, and after a bounded settling
interval:

```text
rtpengine_sessions = 0     rtpengine_ports_used = 0     asterisk channels = 0
```

No duplicate media session and no unknown-Call-ID cleanup error altered the
signalling result.

## Runtime-Originated BYE Delete

**PASS.** Fresh dialog `e6b5ca192b124405@utcp-s2-media`; offer and answer mediation
succeeded; browser ACK reached the runtime; the channel stayed active until the
bounded CLI stimulus.

```text
stimulus_at=11:09:11Z channel=PJSIP/anonymous-00000005
  -> Requested Hangup on channel 'PJSIP/anonymous-00000005'

inbound_method                  BYE
inbound_request_uri             sip:ts-…@utcp-s2-webrtc.invalid;transport=ws
inbound_call_id_matches         true
inbound_cseq                    27672 BYE
inbound_reason                  (absent)      <- no cause=408; the stimulus generated the BYE
inbound_response_sent           200 OK
bye_retransmissions_after_200   0
media_delete method=BYE call_id=e6b5ca192b124405@utcp-s2-media
```

Alias-based WebSocket routing succeeded, the browser received the BYE and answered
`200 OK`, the response reached the runtime, the channel and browser dialog
terminated, and sessions and ports returned to `0`. The CLI was used solely as the
proof stimulus, never as media lifecycle authority.

## Terminal Runtime-Failure Cleanup

**PASS.** `INTENTIONALLY_INDUCED_CONDITION`: the canonical application runtime was
scaled `1 → 0` (Ready SIP endpoints reached `0` in 1 s), then one new authenticated
SDP INVITE was sent.

```text
media_offer  method=INVITE call_id=588c628550574e40@utcp-s2-media
media_delete method=INVITE call_id=588c628550574e40@utcp-s2-media    <- failure_route cleanup
kamailio_application_dialog_rejected result=asterisk_unavailable method=INVITE call_id=…
```

```text
created offer state deleted            yes
committed runtime-unavailable response emitted (503)   yes
media session remaining                0
media port remaining                   0
secondary runtime received the request no  (alt runtime: 0 calls processed)
direct-media fallback                  none
media session persisted in DB or Redis none
```

The client's own 20 s wait expired before the committed ~30 s TM failure timer, so
the browser did not observe the `503` itself; the emission is proven from the
Kamailio transaction log. Recorded as a harness timing artifact, not a divergence
in the contract.

## RTPengine-Unavailable Result

**PASS — fail-closed.** `INTENTIONALLY_INDUCED_CONDITION`: only the canonical
rtpengine Deployment was scaled to zero (Ready endpoints `0` in 1 s), then one new
authenticated SDP INVITE was sent.

```text
ERROR: rtpengine: can't send command "offer" to RTPEngine <udp:rtpengine…:2223>
ERROR: rtpengine: rtpp_test(): proxy did not respond to ping
ERROR: rtpengine: select_rtpp_node(): rtpengine failed to select new for callid=8587553d05234a85@utcp-s2-media
ERROR: rtpengine: rtpp_function_call(): no available proxies
WARNING: kamailio_application_dialog_media result=media_offer_failed method=INVITE call_id=…

client final response: 488 Media Relay Unavailable   (no SDP, no Record-Route)
```

```text
MEDIA_OFFER reports failure                     yes
call relayed to the runtime with unmodified SDP no  — runtime shows 0 calls processed
browser-to-runtime direct media possible        no
second media relay selected                     no  — "no available proxies"
repository-committed explicit failure response  488 Media Relay Unavailable
stale session or media allocation remaining     none
```

rtpengine was restored and returned Ready with a zero-session baseline.

## CANCEL Result or Proof Limitation

`PROOF_LIMITATION`. The committed cleanup route is present and static-tested —
`request_route` invokes `route(MEDIA_DELETE)` on `CANCEL` before `t_check_trans()`
and `t_relay()`, and `failure_route[ASTERISK_UNAVAILABLE]` also calls
`route(MEDIA_DELETE)` on `t_is_canceled()`. The extension `9900` fixture answers
immediately and provides no deterministic pre-answer window; the fixture was **not**
modified to force a CANCEL. No CANCEL reached the route, so this is not classified
as a product defect.

## Direct-Media Prohibition

**PASS.** Across every corridor:

```text
runtime-facing offer contains browser addresses      0
client-facing answer contains the runtime Pod IP     0
Kamailio Pod IP / node IP / developer-host IP leaks  0
direct-media or rtpproxy fallback in the config      0
second media relay selected on failure               none
```

Both legs always terminate on rtpengine (`10.42.0.166`); the browser and the
application runtime never learn each other's media addresses.

## REGISTER Preservation

**PASS.**

```text
sip_status=200  sip_result=accepted
kamailio: kamailio_registration_challenge result=challenge
          kamailio_registration_accepted result=ok
media log lines during REGISTER: 0
REGISTER media operations in the running configuration: 0
```

## Runtime-Neutrality Assessment

The media authority is provider-neutral by construction and by evidence: the media
routes contain no ARI, AMI, channel identifier, dialplan reference, Pod IP,
database or Redis coupling, and rtpengine is addressed only through its internal
control Service. Direction is selected from SIP-level facts — the Request-URI
alias/transport/`.invalid` shape and `$proto` — not from any runtime-specific
signal.

Asterisk is the **current reference runtime** only. Runtime agnosticism is **not
yet proven**: a second-runtime parity slice (FreeSWITCH) remains the required
gate before the application-runtime media contract can be called agnostic.

Neutral terminology is used throughout: *client-facing media leg* and
*application-runtime-facing media leg*.

## State and Workload Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes / families | 110 / asterisk + simulator | **110 / unchanged** |
| pending outbox | 0 | **0** |
| Redis keys `sip`/`dialog`/`rtp`/`media` | 0/0/0/0 | **0/0/0/0** |
| rtpengine sessions / ports used | 0 / 0 | **0 / 0** |

Redis `db0` moved `3 → 4` from authorized API session and cache activity.

Full-cluster Pod snapshot diff contains exactly the three expected changes:

```text
- kamailio-c6bc4454d-sjvzt      -> + kamailio-56b99d4b57-kldt4     (checksum-driven rollout)
- rtpengine-74cd786966-7dbxl    -> + rtpengine-74cd786966-pvz4n    (intentional unavailable test)
- asterisk-ari-74d8c4b5f8-tfgxr -> + asterisk-ari-74d8c4b5f8-r2xvj (intentional scale-to-zero test)
```

Every unrelated workload retained its UID **and** restart count.

## Findings

| Classification | Finding |
|---|---|
| PASS | Only the two intended resources were applied; automatic ~5-second rollout to ReplicaSet revision 18 with no manual restart; running configuration byte-identical across all four authorities; rtpengine module bound to its internal control Service at startup |
| PASS | Media authority is provider-neutral: zero ARI, AMI, channel-ID, dialplan, Pod-IP, database or Redis coupling inside the media routes; no direct-media fallback; REGISTER media-free |
| PASS | **MEDIA_OFFER** — one offer per initial SDP INVITE, accepted by rtpengine, one session created, ports allocated from `40000–40099`, and the runtime-facing offer rewritten to rtpengine addressing with plain `RTP/AVP` and ICE/DTLS stripped, leaking no browser address |
| PASS | **MEDIA_ANSWER** — the runtime answer entered `APPLICATION_RUNTIME_MEDIA_REPLY`, exactly one answer processed, and the browser-facing SDP rewritten to a valid WebRTC answer (`RTP/SAVPF`, ICE, rtpengine DTLS fingerprint, `setup:passive`, `rtcp-mux`, PCMU) that the real browser accepted via `setRemoteDescription` |
| PASS | **Browser-originated BYE** deletes the media session (`media_delete` with matching Call-ID), signalling completes `200 OK`, and sessions and ports return to `0` |
| PASS | **Runtime-originated BYE** deletes the same media session; a clean CLI stimulus generated the BYE with no `Reason: cause=408`; the browser received it and answered `200 OK` with `0` retransmissions; both sides terminated |
| PASS | **Terminal runtime failure** — offer state created then deleted via the failure route, committed `asterisk_unavailable` emitted, no secondary runtime, no fallback, no residual session or port, no durable media record |
| PASS | **rtpengine unavailable fails closed** — `media_offer_failed`, `no available proxies`, client receives the committed `488 Media Relay Unavailable`, and the call does **not** reach the runtime with unmodified SDP (`0 calls processed`) |
| PASS | No direct-media path in either direction; no Pod, node or developer-host address leak; no public SIP or media exposure; no durable media authority; state preserved; only the three expected workload changes |
| **PROOF_LIMITATION** | **Actual RTP/SRTP media flow, ICE completion, DTLS establishment and echo are not proven.** The developer host has no route to the pod CIDR (`ip route get 10.42.0.166` → default gateway; TCP probe times out), which is precisely the media containment T3-S1 proved. The browser did receive and attempt rtpengine's candidate (`remoteCandidates ["10.42.0.166:40048"]`, ICE `checking`, DTLS `connecting`) but could not connect. No host route was added, because doing so would breach a proven containment contract. Completion criteria 8, 9 and 10 are therefore unproven |
| PROOF_LIMITATION | No WebRTC-capable SIP client exists in the repository, so a disposable one was driven inside the real application origin after a natural first-party login. It generates genuine WebRTC SDP but is not a committed asset |
| PROOF_LIMITATION | CANCEL has no deterministic pre-answer window; the `9900` fixture answers immediately and was not modified. The cleanup route is present and static-tested |
| EXPECTED_BEHAVIOR | The corrected Kamailio Pod restarted once on the known transient `postgres … Connection refused` new-Pod-IP versus NetworkPolicy race; self-recovered, zero ERROR lines |
| EXPECTED_BEHAVIOR | The client's 20 s response wait is shorter than the committed ~30 s TM failure timer, so the browser did not observe the `503` for the terminal-failure case; emission is proven from the Kamailio log |
| EXPECTED_BEHAVIOR | An abandoned first dialog (its WebSocket closed past Kamailio's 130 s `tcp_connection_lifetime` with no keepalive) left the runtime retransmitting an unanswered BYE; each retransmission re-ran `MEDIA_DELETE` idempotently with no adverse effect. Proof-harness residue, cleared with a bounded hangup |
| INTENTIONALLY_INDUCED_CONDITION | Application runtime scaled `1 → 0 → 1`; rtpengine scaled `1 → 0 → 1`. Both restored and Ready |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
images built or pushed:         none
resources applied:              2 (kamailio ConfigMap, kamailio Deployment)
workloads rolled:               1 (kamailio, automatically via checksum coupling)
availability tests induced:     2 (application runtime, rtpengine) — both restored
unrelated workloads restarted:  none
host routes or firewall rules added: none — media containment left intact
packet captures:                bounded runs in the Kamailio k3d node network namespace,
                                filtered per proof Call-ID with Authorization redacted,
                                stopped and deleted at cleanup
canonical records mutated:      none beyond authorized API proof data
```

## Cleanup

- Kamailio, rtpengine and the application runtime all left Ready; the corrected media configuration left applied.
- Zero proof media sessions and zero media-port allocations at completion.
- Proof contact deregistered through the canonical client; proof telephony sessions ended through the authorized API.
- WebRTC/SIP proof clients, the login and stimulus scripts, sniffer, captures, credentials and scratch manifests all deleted; `.playwright-mcp/` removed and confirmed absent.
- No port-forward was used; no lingering proof containers remain.
- No credential, digest response or Authorization header content was printed or recorded.

## T3-S2 Final Status

```text
T3-S2A                                              = Complete
T3-S2 repository implementation                     = Complete
T3-S2 provider-neutral media mediation live proof   = INCOMPLETE
    SDP offer/answer mediation                      = proven
    session lifecycle and deletion                  = proven
    terminal-failure and fail-closed behaviour      = proven
    actual RTP/SRTP media flow                      = NOT proven (media containment)
T3-S2                                               = In Progress
T3                                                  = In Progress
Asterisk                                            = current reference runtime
runtime-agnostic parity                             = not yet proven
next gate                                           = bounded FreeSWITCH parity
UTCP_PHASE                                          = T1 (unchanged)
```

## Recommended Next Step

Close the media-flow gap with an **in-cluster** WebRTC-capable media prover — a
disposable workload on the pod network that can complete ICE/DTLS against
rtpengine — so browser-to-runtime and runtime-to-browser SRTP, echo and per-leg
packet counters can be proven without weakening the T3-S1 media containment. Then
proceed to the bounded FreeSWITCH parity adapter using the same provider-neutral
offer, answer, delete, failure-cleanup and signalling contracts.

Do not add Asterisk-specific media authority, direct-media fallback, another
relay, feature gates, manual activation, or durable media-session storage.
