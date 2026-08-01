# T3-S2B In-Cluster WebRTC Media Live Proof

Date: 2026-08-02

Starting commit: `a39e6ba` (`test(t3): add in-cluster webrtc media prover`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2B_IN_CLUSTER_WEBRTC_MEDIA_PROOF_INCOMPLETE`

## Scope

Execute the committed in-cluster WebRTC media prover against the live
`utcp-local` cluster and close the contained media core: ICE, DTLS-SRTP, real
browser-to-runtime media, real runtime-to-browser media, deterministic echo, and
media-session deletion in both BYE directions.

Scenario A (browser-originated BYE) and Scenario B (runtime-originated BYE) both
remain unproven. The proof stopped at a production media-plane defect
(`PRODUCT_DEFECT-15`) that is independent of the prover, and additionally
uncovered four proof-harness defects in the committed prover.

External browser media reachability remains T3-S3 and is untouched.

## Environment Repair Before Baseline

The dev host had restarted. The k3d container IPs had shuffled and k3s refused
to start networking:

```text
level=error msg="Shutdown request received: \"failed to start networking:
unable to initialize network policy controller: error getting node subnet:
failed to find interface with specified node ip\""
```

Resolved with the established tooling only — `k3d cluster stop utcp-local` then
`k3d cluster start utcp-local`. No repository change, no alternate kubeconfig,
no cluster deletion. Node internal IPs settled at server-0 `172.24.0.2`,
agent-0 `172.24.0.4`, agent-1 `172.24.0.3`.

`scripts/security/check-apiserver-policy-drift` then reported
`Kubernetes API egress drift check passed endpoint=172.24.0.2/32:6443`, so no
endpoint-pinned policy re-render was required.

Startup-order DNS races (Kamailio `auth_db` to `postgres`, gateway nginx
`host not found in upstream "reverb"`) self-recovered once CoreDNS was Ready.
`EXPECTED_BEHAVIOR`.

## Runtime Baseline

```text
kamailio      kamailio-56b99d4b57-kldt4     uid 41dfde71-…  restarts 4  Ready
rtpengine     rtpengine-74cd786966-8vhff    uid 0fbd6b20-…  restarts 0  Ready  ip 10.42.0.179
asterisk      asterisk-ari-74d8c4b5f8-jtxvl uid 2cec17cd-…  restarts 0  Ready  ip 10.42.2.170
traefik       traefik-dd559f4ff-g2dvf       uid 271a7a09-…  restarts 26 Ready
gateway endpoint   traefik.traefik-system.svc.cluster.local -> 10.43.232.233:443
rtpengine media    10.42.0.179, UDP 40000-40099, control Service ClusterIP UDP 2223
rtpengine sessions own/foreign      0 / 0
rtpengine ports_used / ports_free   0 / 200
database      tables 41, tenants 27, RuntimeNodes 110 (asterisk, simulator), pending outbox 0
redis         dbsize 1, keys sip/dialog/rtp/media 0/0/0/0
```

Required precondition satisfied: `rtpengine proof sessions = 0`,
`rtpengine proof allocated ports = 0`, all canonical workloads Ready.

The provider-neutral Kamailio media configuration from `afced8d` remained live
(`kamailio_signaling_config_check=pass`, `live_kamailio_runtime=configured`).

## Proof Resources Applied

Rendered from the committed overlay only
(`infrastructure/kubernetes/overlays/local/proof/t3-media`):

```text
Namespace/utcp-proof
Job/utcp-proof/t3-media-prover
NetworkPolicy/utcp-proof/default-deny
NetworkPolicy/utcp-proof/allow-t3-media-prover-required-egress
NetworkPolicy/utcp-platform/allow-rtpengine-media-prover
```

Render audit: zero `NodePort`, `LoadBalancer`, `externalIPs`, `hostPort`,
`hostNetwork`, `hostPID`, `hostIPC`, `privileged: true`, `ipBlock`, `UDPRoute`,
`hostPath`. Zero `Deployment` and zero `Service`. Media access limited to UDP
`40000-40099`, signaling to TCP `443`, DNS to UDP/TCP `53`. No direct
prover-to-Asterisk media permission and no rtpengine control-port permission.

`kubectl diff -k` reported `namespaces "utcp-proof" not found`, confirming every
proof resource was new and no production object was modified.

## Prover Runtime

```text
prover commit          a39e6ba
browser image pin      mcr.microsoft.com/playwright:v1.61.1-noble
playwright package     @playwright/test 1.61.1
built image            utcp-t3-media-prover:dev
registry image         utcp-local-registry:5000/utcp/t3-media-prover:0.1.0-k1-dev
pushed digest          sha256:bd90b3c1f3cb410afa200ee9aa43ca9e1b74161800b137857966c655b3d75153
node image id          sha256:4921425957f01d4175ed94c7bb4cbebd345ddff92d117b2b6cbd12b4a72286c0
security context       runAsNonRoot, uid/gid 1000, allowPrivilegeEscalation=false,
                       capabilities drop ALL, seccompProfile RuntimeDefault,
                       readOnlyRootFilesystem=true, automountServiceAccountToken=false
job bounds             backoffLimit 0, activeDeadlineSeconds 420, ttlSecondsAfterFinished 300
```

The image was pre-pulled on all three k3d nodes with `crictl pull` so image
transfer would not consume the bounded SIP credential lifetime.

## Canonical Origin Resolution

The prover resolved `traefik.traefik-system.svc.cluster.local` through cluster
DNS at execution time and started Chromium with process-local
`--host-resolver-rules` mapping the canonical hostnames to that address.

```text
browser URL       https://app.utcp.local.test
sip WSS URL       wss://sip.utcp.local.test/ws
resolved endpoint 10.43.232.233:443   (not treated as durable authority)
```

Browser URL hostname, HTTP Host, Origin, TLS SNI, cookie domain, WebSocket
origin, and certificate hostname identity were all preserved. No CoreDNS edit,
no host route, no ClusterIP or Service-DNS browser URL.

## Ephemeral Proof Credentials

Issued only through the canonical authorized API path, matching the existing
T1/T3-S2A precedent: `POST /api/v1/admin/users`, `POST /api/v1/admin/memberships`,
natural member login, the application's own forced `change-password`,
`POST /api/v1/auth/tenant-context`, `POST /api/v1/telephony/sessions`, then
`POST /api/v1/telephony/sessions/{id}/signaling-credential` (`HTTP 201`).

```text
credential_realm       sip.utcp.local.test
credential lifetime    ~5 minutes
delivery               external Kubernetes Secret created at execution time
```

No credential was written to a repository file, embedded in a Job label,
annotation, log line, or structured result, and no preset cookie, Redis session,
or database session was created. Tenant and RuntimeNode counts were unchanged.

## Scenario A — Browser-Originated BYE

**Result: `INCOMPLETE`.** The committed prover fails before any media assertion.

```text
job exit status        1
structured result      emitted
error                  page.goto: net::ERR_CERT_AUTHORITY_INVALID
                       at https://app.utcp.local.test/login
duration               222 ms
natural login          FAIL
```

`PROOF_HARNESS_DEFECT-1`. The Traefik certificate is issued by the local mkcert
development CA. The prover container carries no local-CA trust material, the Job
mounts none, the pinned Playwright image has no `certutil` (`libnss3-tools`
absent), and the prover launches the context with `ignoreHTTPSErrors: false`.
Chromium therefore rejects the canonical origin at the first navigation.

The repository reserves a local-CA slot at `.runtime/tls/utcp-local-ca.crt`
(`scripts/gateway/lib:43`), but it is unpopulated and no committed mechanism
delivers a CA into the prover Pod.

The first `make t3-media-prover-run` also lost all diagnostic output:

`PROOF_HARNESS_DEFECT-2`. `scripts/t3-media-prover/run` waits only on
`--for=condition=complete` with a 480 s timeout, while the Job declares
`ttlSecondsAfterFinished: 300`. A Job that fails early is garbage-collected at
t+300 s, well before the wait expires, so the runner's failure path reports
`jobs.batch "t3-media-prover" not found` and neither the logs nor the structured
result survive. Every failing run is therefore undiagnosable through the
committed entry point.

## Targeted Blocker Diagnosis

Because the committed prover could not reach the media plane, a bounded
diagnosis was run to establish the full corrective list in one pass rather than
one defect per cycle. The committed namespace, Job, and all three committed
NetworkPolicies were applied unchanged; only the prover **script** was overlaid
through a scratchpad ConfigMap. No committed repository file was modified and no
production resource was touched.

Defects were isolated one variable at a time.

| Step | Change | Next observed seam |
|---|---|---|
| 1 | TLS trust isolated | login succeeded, WSS opened, INVITE authenticated, answer rejected |
| 2 | `waitForMessage` off-by-one | SDP answer parsed, `setRemoteDescription` rejected |
| 3 | SDP/console diagnostics added | login raced SPA hydration |
| 4 | login hydration wait | answer captured, BUNDLE mismatch confirmed |
| 5 | `bundlePolicy` left at default | ICE + DTLS + outbound RTP all succeeded, echo absent |

`PROOF_HARNESS_DEFECT-3`. `waitForMessage` resolves and then returns
`messages[Math.max(0, cursor - 1)]` (`tools/t3-media-prover/prover.mjs:393`).
When the predicate matches at index *i*, `cursor` is already *i*, so the
function returns the **previous** SIP message. The live message sequence is
`401 Unauthorized`, `100 trying`, `200 OK`, so the INVITE handler received
`100 trying`, produced an empty body, and threw
`SDP answer is not a WebRTC audio answer with rtcp-mux`.

`PROOF_HARNESS_DEFECT-4`. `naturalLogin` navigates with
`waitUntil: 'domcontentloaded'` and then queries selectors with
`locator.count()`, which does not auto-wait. Against the Vue SPA this is racy:
one run failed with
`none of the expected login selectors exists: input[name="email"], input[type="email"]`
while otherwise identical runs succeeded.

`PROOF_HARNESS_DEFECT-5`. The prover constructs its `RTCPeerConnection` with
`bundlePolicy: 'max-bundle'` (`tools/t3-media-prover/prover.mjs:111`). rtpengine's
client-facing answer contains no `a=group:BUNDLE` line, so Chromium rejects it:

```text
InvalidAccessError: Failed to set remote answer sdp:
Answer cannot remove m= section with mid='0' from already-established BUNDLE group
```

With a single audio m-line, bundling is irrelevant; the default `balanced`
policy accepts the same answer. This is a prover configuration defect, not a
mediation defect.

## SDP Mediation Result

**`PASS`.** With the harness defects isolated, the mediated answer delivered to
the real browser was exactly the committed contract:

```text
o=- 2333584222151146403 4 IN IP4 10.42.0.179
s=Asterisk
m=audio 40068 RTP/SAVPF 0 126
c=IN IP4 10.42.0.179
a=rtpmap:0 PCMU/8000
a=rtcp-mux
a=setup:passive
a=fingerprint:sha-256 53:E0:E2:76:…:D8:AA
a=ice-ufrag:ohPE5LBu
a=candidate:… 1 UDP 2130706431 10.42.0.179 40068 typ host
a=end-of-candidates
```

Client-facing `RTP/SAVPF` on rtpengine's own address inside the committed
`40000-40099` range, rtpengine's own DTLS fingerprint, rtpengine's own ICE
credentials and candidate. No Asterisk address, no browser address, no public or
developer-host address. This independently re-confirms the `afced8d` mediation
proven at `72716a3`.

## ICE Result

**`PASS`.**

```text
iceConnectionState        connected
peerConnectionState       connected
selected candidate pair   CPqe+hSPbj_mC8/uEJT   (stable for the whole call)
local candidate           host   10.42.1.241     private, in-cluster prover Pod
remote candidate          host   10.42.0.179     private, rtpengine media Pod
```

The selected remote candidate belongs to the internal rtpengine media network.
No direct Asterisk media candidate, no public address, and no developer-host
address was selected.

## DTLS Result

**`PASS`.** `dtlsState = connected` and `peerConnectionState = connected` were
reached and held for the full 12-second observation window. The browser
therefore completed DTLS-SRTP against rtpengine and sent encrypted media only.
No unencrypted browser-to-runtime path exists.

## Outbound Media Result

**`PASS`.** Real SRTP left the browser at a stable ~50 pps for the entire
window:

```text
t= 2s  out=105/16800   in=0/0  energy=0
t= 4s  out=205/32800   in=0/0  energy=0
t= 6s  out=305/48800   in=0/0  energy=0
t= 8s  out=405/64800   in=0/0  energy=0
t=10s  out=505/80800   in=0/0  energy=0
t=12s  out=605/96800   in=0/0  energy=0
```

`packetsSent` and `bytesSent` both increased monotonically from the deterministic
440 Hz Web Audio source.

## Inbound Echo Result

**`FAIL` — `PRODUCT_DEFECT-15`.** `packetsReceived`, `bytesReceived`, and
received audio energy remained `0` for the entire call, across nine independent
calls.

The runtime side was healthy throughout:

```text
asterisk channel   PJSIP/anonymous-00000005  from-kamailio  9900  Up  Echo  00:00:16
```

Asterisk answered, entered `from-kamailio`, and ran `Echo()` for the full call.

## Root Cause — Media Plane Between rtpengine And The Reference Runtime

Asterisk received **zero** RTP for the entire call:

```text
pjsip show channelstats
anonymous-00000006 00:00:16 ulaw  0  0  0  0.000  0  0  0  0.000  0.000
                                  ^rx                ^tx
```

Pod interface counters over the same window separate "not sent" from "not
delivered":

```text
rtpengine eth0    rx +645   tx +625      (~50 pps in, ~50 pps forwarded)
asterisk  eth0    rx  +22   tx  +25      (signaling only, no RTP)
```

rtpengine forwards; Asterisk never receives. rtpengine's own aggregate counters
agree:

```text
rtpengine_sessions_total            7
rtpengine_one_way_sessions_total    4     media observed in one direction only
rtpengine_packets_total{userspace}  2003
rtpengine_errors_total              0
```

A direct probe confirms the denial. From the rtpengine Pod to the Asterisk Pod,
20 UDP datagrams each to a port in Asterisk's RTP range and to a port in
rtpengine's own range:

```text
rtpengine -> 10.42.2.170:19001   sent, 0 received at Asterisk
rtpengine -> 10.42.2.170:40050   sent, 0 received at Asterisk
```

Positive control: Kamailio to Asterisk on UDP `5060` — the one media-plane-
adjacent port that **is** permitted — works continuously (`8 calls processed`,
every INVITE delivered), proving cross-node Pod-to-Pod UDP is functional when a
NetworkPolicy permits it. kube-router rebuilds its `KUBE-POD-FW-*` chains on
resync, so packet counters on those chains are not a reliable drop signal and
were not used as evidence.

The policy set explains it exactly. Asterisk's media ports are
`rtpstart=10000` / `rtpend=20000` (`/etc/asterisk/rtp.conf`). `utcp-runtime`
contains only three NetworkPolicies:

```text
default-deny                          all pods, Ingress + Egress
allow-asterisk-sip-from-kamailio      ingress UDP 5060 from kamailio
                                      egress  UDP 5060 to kamailio, UDP/TCP 53 to kube-dns
allow-asterisk-ari-from-utcp-workers  ingress TCP 8088 from worker / asterisk-ari-events
```

No policy permits media ingress to the runtime Pod, and because
`allow-asterisk-sip-from-kamailio` declares `policyTypes: [Ingress, Egress]`,
Asterisk's egress is confined to SIP `5060` plus DNS — so the runtime cannot
send RTP back to rtpengine either.

The source side is also mis-ranged. `allow-rtpengine-media` in `utcp-platform`
permits rtpengine egress to `asterisk-ari` on UDP `40000-40099` — rtpengine's
**own** port range, not the destination's. NetworkPolicy `ports` match the
destination port, so rtpengine egress to Asterisk's `10000-20000` is denied at
the source as well.

```text
direction                                  destination port   permitted
rtpengine -> asterisk RTP                  10000-20000        NO  (source and destination)
asterisk  -> rtpengine RTP                 40000-40099        NO  (source: asterisk egress)
rtpengine <- asterisk (rtpengine ingress)  40000-40099        yes (already correct)
```

This is the same reciprocal-policy class already recorded in
`docs/evidence/t3/t3-s1-rtpengine-reciprocal-egress-correction.md`. It was never
exercised before because `72716a3` explicitly recorded actual RTP flow as
unproven — SDP mediation and media-session lifecycle passed without a single RTP
packet ever needing to traverse the runtime media leg.

Classification: `PRODUCT_DEFECT`. The prover and the proof-only policies operate
correctly at this point; production RTP bridging fails.

## Smallest Bounded Correction

Four exact rules, all reciprocal-policy work, no new authority:

1. `allow-rtpengine-media` egress to `utcp-runtime` / `utcp.io/network-role=asterisk-ari`
   on the runtime RTP range (UDP `10000-20000`), replacing or accompanying the
   current `40000-40099` destination range.
2. A `utcp-runtime` policy permitting ingress to the runtime Pod from
   `utcp-platform` / `utcp.io/network-role=rtpengine-media` on UDP `10000-20000`.
3. Egress from the runtime Pod to `utcp-platform` /
   `utcp.io/network-role=rtpengine-media` on UDP `40000-40099`.
4. `allow-rtpengine-media` ingress from `asterisk-ari` on UDP `40000-40099`
   already exists and needs no change.

The runtime RTP range must be sourced from the same canonical place as
`rtp.conf` rather than hard-coded twice, and `scripts/media/config-check` plus
its mutation test should reject a media policy whose destination port range does
not match the runtime's configured RTP range — that is the exact guard whose
absence let this ship.

## Scenario B — Runtime-Originated BYE

**Not executed.** Section 9 of the proof contract requires all Scenario A media
assertions to pass before the bounded runtime hangup stimulus is issued. Inbound
media and echo never passed, so the stimulus was correctly withheld. No Asterisk
CLI hangup was performed against any proof channel.

Additionally, the committed runner cannot select the scenario:

`PROOF_HARNESS_DEFECT-6`. `scripts/t3-media-prover/run` never sets
`UTCP_T3_MEDIA_PROVER_SCENARIO` and `job.yaml` does not declare it, so the
prover always falls back to its `browser-bye` default. Scenario B is
unreachable through `make t3-media-prover-run` as committed.

## Media Deletion And Port Release

Not proven in either BYE direction by this run, because the prover threw before
reaching its BYE and closed the WebSocket instead.

Cleanup nonetheless converged to the required terminal state after all nine
calls:

```text
rtpengine_sessions own/foreign     0 / 0
rtpengine ports_used / ports_free  0 / 200
asterisk                           0 active channels, 0 active calls, 8 calls processed
```

BYE-direction deletion remains proven only at the SDP/lifecycle level by
`72716a3`.

## Provider-Neutrality Assessment

The browser-side assertions depend only on SIP dialog state, ICE state,
DTLS/peer-connection state, RTP statistics, audio-energy evidence, BYE direction
and final SIP result, and cleanup state.

Scanned in `tools/t3-media-prover/prover.mjs`: Asterisk channel identifiers,
ARI, AMI, Asterisk Pod IPs, Asterisk dialplan context, Asterisk-specific SDP
markers, and FreeSWITCH ESL all occur **zero** times. `make
t3-media-prover-config-check` enforces this.

The one Asterisk-specific action in this proof was read-only diagnosis
(`core show channels`, `pjsip show channelstats`) used to locate the defect, not
as media authority. No CLI hangup stimulus was issued.

```text
Asterisk                       = current reference runtime
provider-neutral media contract = SDP plane proven; RTP plane blocked by PRODUCT_DEFECT-15
runtime agnosticism            = not yet proven
```

## T3-S1 Containment Preservation

**`PASS`.** After both phases:

```text
NodePort                      none
LoadBalancer for media        none
ExternalIP                    none
HostPort / HostNetwork        none
public UDPRoute               none
public rtpengine Service      none
k3d media publication         none
host route                    none
Pod-CIDR ipBlock              none
prover-to-Asterisk media      never permitted
```

The rtpengine media candidate `10.42.0.179` remains internal and the external
host still has no route to it. That is expected and remains true.

## External Media Edge Status

```text
contained in-cluster WebRTC media core   = NOT proven (blocked by PRODUCT_DEFECT-15)
external browser media reachability      = NOT proven, out of scope, T3-S3
```

## State And Workload Preservation

A full-cluster Pod snapshot diff between baseline and final is **empty**: every
workload retained its UID and restart count, and all are Ready.

```text
Value                              Before   After
database public tables             41       41
tables containing dialog/rtp/media (none)   (none)
tenants                            27       27
RuntimeNodes                       110      110
RuntimeNode families               asterisk, simulator   unchanged
pending outbox                     0        0
Redis keys sip/dialog/rtp/media    0/0/0/0  0/0/0/0
rtpengine sessions                 0/0      0/0
rtpengine ports_used               0        0
```

Redis `db0` moved `1 → 34`: ordinary session and cache entries created by the
authorized API calls that issued the proof credentials. `EXPECTED_BEHAVIOR`,
matching the T3-S2A precedent. Nine proof users, memberships, and telephony
sessions were created **through the canonical API only**; tenant and RuntimeNode
counts are unchanged.

## Findings

| Classification | Finding |
|---|---|
| `PASS` | SDP offer/answer mediation re-confirmed live against a real Chromium WebRTC client: client-facing `RTP/SAVPF` on rtpengine's address in `40000-40099`, rtpengine's own fingerprint, ICE credentials and candidate, no runtime or browser address leak |
| `PASS` | ICE reaches `connected` with a stable selected pair; remote candidate is the internal rtpengine media address; no Asterisk, public, or developer-host candidate selected |
| `PASS` | DTLS-SRTP completes; `peerConnectionState = connected` held for the full window |
| `PASS` | Real outbound browser-to-rtpengine SRTP: 605 packets / 96 800 bytes over 12 s at ~50 pps |
| `PASS` | T3-S1 containment fully preserved; no public media exposure, host route, or Pod-CIDR `ipBlock` introduced |
| `PASS` | Zero production workload UID or restart changes; all canonical state authority values unchanged |
| **`PRODUCT_DEFECT-15`** | rtpengine cannot bridge RTP to the reference runtime. `utcp-runtime` has no NetworkPolicy permitting media ingress to the runtime Pod from rtpengine, `allow-asterisk-sip-from-kamailio` confines runtime egress to SIP `5060` plus DNS, and `allow-rtpengine-media` egress to `asterisk-ari` is scoped to UDP `40000-40099` instead of the runtime's `10000-20000` RTP range. Asterisk receives and transmits `0` RTP packets while running `Echo()`; `rtpengine_one_way_sessions_total` increments |
| `PROOF_HARNESS_DEFECT-1` | The prover has no local-CA trust; natural login fails `net::ERR_CERT_AUTHORITY_INVALID`. No CA is mounted, the pinned image lacks `certutil`, and `ignoreHTTPSErrors: false` |
| `PROOF_HARNESS_DEFECT-2` | The runner waits only on `condition=complete` for 480 s while the Job sets `ttlSecondsAfterFinished: 300`, so failed runs are garbage-collected before collection and both logs and the structured result are lost |
| `PROOF_HARNESS_DEFECT-3` | `waitForMessage` returns `messages[cursor - 1]`, the message **before** the match, so the INVITE handler parses `100 trying` instead of the `200 OK` answer |
| `PROOF_HARNESS_DEFECT-4` | `naturalLogin` waits only for `domcontentloaded` and queries selectors without auto-waiting, racing Vue SPA hydration |
| `PROOF_HARNESS_DEFECT-5` | `bundlePolicy: 'max-bundle'` rejects rtpengine's answer, which carries no `a=group:BUNDLE`; the default `balanced` policy accepts the identical answer |
| `PROOF_HARNESS_DEFECT-6` | Neither the runner nor `job.yaml` sets `UTCP_T3_MEDIA_PROVER_SCENARIO`, so Scenario B is unreachable through the committed entry point |
| `PROOF_LIMITATION` | The Job mounts a writable `emptyDir` at `/home/pwuser`, but the pinned image's uid 1000 is `ubuntu` with `HOME=/home/ubuntu`. Chromium launched anyway, so this is latent rather than blocking, but the writable volume is not on the browser's actual home path |
| `EXPECTED_BEHAVIOR` | Host restart shuffled k3d node IPs and blocked k3s startup; resolved with `k3d cluster stop`/`start` only |
| `EXPECTED_BEHAVIOR` | Redis `db0` `1 → 34` from ordinary API session and cache entries |

`PROOF_POLICY_DEFECT`: none. The committed proof-only NetworkPolicies were
sufficient and correctly selected — the prover reached rtpengine media, ICE, and
DTLS through them, and the reciprocal `allow-rtpengine-media-prover` policy
worked as designed.

## Cleanup

```text
prover Jobs                deleted
prover Pods                deleted
proof Secrets              deleted
proof ConfigMap            deleted
proof NetworkPolicies      deleted (all three, including the utcp-platform reciprocal)
proof namespace            deleted
residual proof resources   none
captures / traces / cookies / browser profiles / temporary certificates   none retained
port-forwards              none used
temporary Helm             provisioned pinned v4.0.3, verified, removed
.playwright-mcp/           absent
credentials in logs        none
```

Kamailio, rtpengine, and the reference runtime all remain Ready with zero
rtpengine proof sessions and zero proof allocations. The committed repository
files are unchanged.

## Verification Performed

```text
git status --short / git log -20 --oneline --decorate / grep UTCP_PHASE versions.env
make t3-media-prover-config-check            pass
make t3-media-prover-config-check-test       pass
make media-config-check                      pass
make media-config-check-test                 pass
make kamailio-signaling-config-check         pass
make kamailio-signaling-config-check-test    pass
make security-config-check                   pass
make security-config-check-test              pass
make repository-hygiene                      pass
make workflow-check                          pass
make secret-scan                             pass
make k8s-config-check                        pass
make gateway-config-check                    pass  (pinned Helm v4.0.3, removed after)
make check                                   pass
git diff --check / git diff --cached --check clean
scripts/security/check-apiserver-policy-drift  pass, endpoint 172.24.0.2/32:6443
```

`make security-config-check` failed once before the cluster repair with
`Unable to connect to the server: EOF`; it passed after `k3d cluster start`.
That was the environmental condition, not a repository failure.

## Status

`T3-S2A bidirectional signaling = Complete`.

`T3-S2B in-cluster WebRTC media proof = INCOMPLETE`. ICE, DTLS, and real
outbound browser media are proven; inbound echo is blocked by
`PRODUCT_DEFECT-15`. Six proof-harness defects are open in the committed prover.

`T3-S2C second-runtime parity, preferably FreeSWITCH = Not Started`.

`T3-S3 external media edge = Not Started`.

`Asterisk = current reference runtime`.

`runtime agnosticism = not yet proven`.

`T3-S2 overall = In Progress`.

`T3 = In Progress`.

`UTCP_PHASE=T1`.

## Recommended Next Step

Bounded implementation, in this order:

1. Correct `PRODUCT_DEFECT-15` — the four reciprocal media-plane rules above,
   plus a `scripts/media/config-check` guard that rejects a media policy whose
   destination port range does not match the runtime's configured RTP range.
2. Correct `PROOF_HARNESS_DEFECT-1` through `-6` in one bounded prover slice:
   a bounded local-CA trust mechanism that keeps `ignoreHTTPSErrors: false`,
   failure-tolerant result collection, the `waitForMessage` index fix, an
   explicit login hydration wait, removal of `bundlePolicy: 'max-bundle'`, and
   scenario selection wired through the runner and Job.
3. Re-run this proof for Scenario A and Scenario B.
