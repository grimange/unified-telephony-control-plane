# T3-S3B External Browser Media Edge — Projection Proven, Advertisement Blocked

Date: 2026-08-02

Starting commit: `20f8daa` (`feat(t3): add external media edge projection`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S3B_EXTERNAL_BROWSER_MEDIA_PROOF_INCOMPLETE`

Remaining seam: **rtpengine advertised address**

## Summary

The committed T3-S3A local media-edge projection is **infrastructurally sound and
proven end to end at the transport layer**. A fresh proof cluster built from
`infrastructure/k3d/cluster-media-edge.yaml` publishes exactly 100 UDP ports on
loopback, the apiserver accepts the extended NodePort range, the media-edge node
label applies, the 100-port NodePort Service admits, rtpengine-selector pods land
on the media-edge node, and **real host-originated UDP traverses the full path to
a Pod and back** with a stable RTP-cadence 5-tuple.

The browser media proof is nevertheless blocked by one exact defect:
**`PRODUCT_DEFECT-23` — the rtpengine entrypoint rejects the committed
`UTCP_PUBLIC_MEDIA_ADDRESS=127.0.0.1`**, so rtpengine cannot start under the
media-edge overlay and can never advertise the external candidate.

The canonical `utcp-local` cluster was preserved (stopped, not deleted), the
proof cluster was deleted, and the canonical environment was restored with zero
drift.

## Repository Baseline

```text
HEAD           20f8daa (branch main), working tree clean
UTCP_PHASE     T1
make media-edge-config-check / -test        pass
make media-edge-projection-check            pass
make media-config-check / -test             pass
make security-config-check / -test          pass
make k3d-config-check                       pass
make t3-media-prover-config-check / -test   pass
make check                                  pass
```

## Tool Versions

```text
k3d      v5.9.0        (matches K3D_VERSION / K3D_MIN_VERSION)
k3s      v1.35.3-k3s1  (matches K3S_IMAGE)
kubectl  in-path, server v1.35.3+k3s1
docker   host daemon
helm     v4.0.3 — no canonical repository bootstrap exists; a temporary pinned
         binary was fetched to /tmp, used for `make gateway-config-check`
         (PASS), and removed. Not committed.
```

## PRODUCT_DEFECT-23 — Entrypoint Rejects The Committed Public Media Address

`versions.env` declares the canonical value and `scripts/media-edge/config-check`
requires exactly it:

```text
versions.env:43                 UTCP_PUBLIC_MEDIA_ADDRESS=127.0.0.1
scripts/media-edge/config-check UTCP_PUBLIC_MEDIA_ADDRESS must be declared once
                                as 127.0.0.1
```

`20f8daa` validates the advertised address with the pre-existing
`require_ipv4()` helper, which was written for the **bind** address and
deliberately excludes loopback:

```sh
require_ipv4() {
  case "$value" in
    ""|0.0.0.0|127.*|169.254.*) return 1 ;;
  esac
  ...
}

advertised_address="$POD_IP"
if [ "${UTCP_PUBLIC_MEDIA_ADDRESS+x}" = "x" ]; then
  if ! require_ipv4 "$UTCP_PUBLIC_MEDIA_ADDRESS" || [ "$UTCP_PUBLIC_MEDIA_ADDRESS" = "$POD_IP" ]; then
    printf 'invalid UTCP_PUBLIC_MEDIA_ADDRESS for rtpengine advertisement: %s\n' ...
    exit 1
  fi
  advertised_address="$UTCP_PUBLIC_MEDIA_ADDRESS"
fi
```

Executed against the real image built from `20f8daa`:

```text
$ docker run --rm -e POD_IP=10.42.0.50 ... -e UTCP_PUBLIC_MEDIA_ADDRESS=127.0.0.1 utcp-rtpengine:dev
invalid UTCP_PUBLIC_MEDIA_ADDRESS for rtpengine advertisement: 127.0.0.1
```

Without the variable the entrypoint proceeds past validation into rtpengine
itself. With the committed value it exits before rtpengine ever runs.

`127.*` is correct to reject for the **bind** address and correct to *accept* for
the **advertised** address in the committed local projection — the two need
separate validators. The contract and the validator contradict each other.

Failed seam: **rtpengine advertised address**.

### Why every static check still passes

`scripts/media-edge/config-check` asserts the entrypoint does *not* hard-code
`127.0.0.1` (correct) and that `versions.env` declares it (correct), but nothing
cross-validates the declared value against the entrypoint's own validator, and
no check executes the entrypoint with the declared address. Classification:
`PROOF_LIMITATION`.

### Smallest bounded correction

1. Split the validator: keep `require_bindable_ipv4()` (rejecting `""`,
   `0.0.0.0`, `127.*`, `169.254.*`) for `POD_IP`, and add
   `require_advertisable_ipv4()` that permits loopback while still rejecting
   `""`, `0.0.0.0`, `169.254.*`, the Pod IP, Service ClusterIPs, and runtime
   addresses.
2. Add a `media-edge-config-check` assertion that runs the entrypoint's
   advertised-address validation against the declared
   `UTCP_PUBLIC_MEDIA_ADDRESS`, plus a mutation case proving a loopback
   advertised address is accepted and a bind-address loopback is still rejected.

## Canonical Environment Preservation

The existing `utcp-local` cluster was **stopped, not deleted**:

```text
before   utcp-local 1/1 servers, 2/2 agents; all 36 pods Ready
         tables 41, tenants 27, RuntimeNodes 110, pending outbox 0,
         Redis sip/dialog/rtp/media 0/0/0/0,
         0 active channels, rtpengine sessions 0, allocations 0
after stop  4 k3d-utcp-local containers Exited, volume k3d-utcp-local-images intact
            127.0.0.1 80/443/6550 released
```

## Proof Cluster

Created from the committed profile via a `.runtime` scratch copy whose **only**
differences were the cluster name and the registry name/port (required to avoid
colliding with the preserved registry). Port mappings, node labels, node counts,
API args, network settings, and Kubernetes version were untouched and are
byte-identical to the committed file.

```text
cluster        utcp-mediaedge (k3d v5.9.0, k3s v1.35.3-k3s1, 1 server + 2 agents)
creation time  29.7 s — the k3d documentation's warning that a large published
               range "may take a very long time or even freeze your system"
               does not materialise at 100 ports
kubeconfig     .runtime/kubeconfig/utcp-mediaedge.yaml (fresh)
API endpoint   https://127.0.0.1:6550 — reachable
```

## Host Port Publication — PASS

```text
exact UDP mappings on 127.0.0.1     100  (40000/udp … 40099/udp)
TCP mappings inside 40000-40099        0
0.0.0.0 bindings                       0
2223 / 2224 published                  none
Asterisk RTP (10000-20000) published   none
FreeSWITCH RTP (21000-21099) published none
runtime SIP (5060) / ESL (8021)        none
also published                         127.0.0.1:80/tcp, 127.0.0.1:443/tcp,
                                       127.0.0.1:6550 -> 6443/tcp
```

Publication lands on the **serverlb**, not directly on `server:0` — k3d maps
published ports "via the serverlb" and uses the node filter to choose the
upstream. The generated nginx configuration proxies every media port to the
media-edge node:

```text
upstream 40000_udp { server k3d-utcp-mediaedge-server-0:40000 ...; }
server { listen 40000 udp; proxy_pass 40000_udp; proxy_timeout 600; ... }
```

This is correct behaviour, not a defect, but it means the effective path has an
extra nginx UDP hop that the architecture document did not name explicitly.

## NodePort Range And Node Label — PASS

```text
apiserver arg   --kube-apiserver-arg=service-node-port-range=30000-40099  (live)
node labels     k3d-utcp-mediaedge-server-0   utcp.dev/media-edge=true
                k3d-utcp-mediaedge-agent-0    <none>
                k3d-utcp-mediaedge-agent-1    <none>
```

## Media Service — PASS

Committed `rtpengine-media` Service, validated structurally and by server
admission:

```text
type                     NodePort
externalTrafficPolicy    Local
selector                 app.kubernetes.io/part-of=utcp,
                         app.kubernetes.io/component=rtpengine
port count               100
range                    40000-40099, contiguous
protocol                 UDP for all 100
port == targetPort == nodePort   100 / 100 (0 violations)
apiserver admission      service/rtpengine-media created (server dry run)
```

Admission proves the extended NodePort range genuinely accepts `40000-40099`.

## Node Placement — PASS

A proof-only Deployment carrying the committed Service selector and the
`utcp.dev/media-edge: "true"` nodeSelector scheduled onto the media-edge node and
became Ready:

```text
pod    media-edge-udp-probe-76c8bdfb67-l7zpj
node   k3d-utcp-mediaedge-server-0   (the media-edge node)
podIP  10.42.0.6
endpointslice rtpengine-media  ready=true, nodeName=k3d-utcp-mediaedge-server-0
```

No endpoint appeared on a non-media-edge node.

## External Host UDP Path — PASS

Sent from the developer host loopback, outside the Pod and node networks:

```text
host 127.0.0.1:40050/udp  ->  serverlb (nginx udp proxy)
                          ->  k3d-utcp-mediaedge-server-0:40050
                          ->  rtpengine-media NodePort
                          ->  Pod 10.42.0.6:40050
```

```text
datagrams sent from host loopback   5
received in the Pod                 5   (all five payloads, from 172.21.0.5)
reply observed on the host          ECHO:UTCP-EXTERNAL-MEDIA-PROBE-4
                                    from ('127.0.0.1', 40050)
```

**The complete external forwarding path works, in both directions.**

### Source 5-tuple stability at RTP cadence — PASS

An initial 5-datagram burst arrived from five different upstream source ports,
which would break rtpengine's symmetric-RTP latching. A controlled retest with a
fixed host source port and RTP-like 200 ms spacing shows the nginx UDP session is
stable once established:

```text
10 datagrams, 200 ms apart, host source port 45123
observed by the Pod: 172.21.0.5:37563  x10
distinct upstream source ports: 1
```

The burst variation was a session-establishment artifact, not steady-state
behaviour. Real RTP arrives at a steady cadence, so rtpengine will latch once.
This is recorded so the T3-S3B rerun asserts it explicitly rather than assuming
it.

## Not Executed

Blocked by `PRODUCT_DEFECT-23`, because rtpengine cannot start under the
media-edge overlay and therefore can never advertise `127.0.0.1`:

```text
full media-edge overlay deployment and migrations
rtpengine runtime projection verification (advertised 127.0.0.1)
signaling and selected-runtime baseline on the proof cluster
Playwright MCP natural host-browser login
external Scenario A (candidate, packet path, media, browser BYE)
external Scenario B (readiness marker, runtime BYE)
failure cases A, B, C, D
full containment sweep against a live media session
```

No natural-login proof was run on the proof cluster, no proof credentials were
created there, and `.playwright-mcp/` was never created.

## Containment Observed So Far

From the published surface alone, before any workload deployment:

```text
externally published   TCP 80, TCP 443, TCP 6550 (API), UDP 40000-40099
not published          UDP 2223, TCP/UDP 2224, UDP 10000-20000,
                       UDP 21000-21099, TCP 8021, runtime SIP 5060
no 0.0.0.0 binding, no Pod-CIDR route added, no hostNetwork, no hostPort,
no manual iptables/nftables/socat/Docker proxy rule was created
```

The full containment sweep against a live media session remains unexecuted.

## Cleanup And Restoration

```text
proof cluster deleted            k3d cluster delete utcp-mediaedge
residual containers              none
residual networks                none
residual volumes                 none
proof registry image tag         removed
scratch cluster config           removed (.runtime, never committed)
proof kubeconfig                 removed
temporary Helm v4.0.3            fetched to /tmp, used, removed
.playwright-mcp/                 never created
```

Canonical cluster restarted and verified:

```text
all workloads Ready              yes
database public tables           41   (unchanged)
tenants                          27   (unchanged)
RuntimeNodes                     110  (unchanged)
pending outbox                   0    (unchanged)
Redis sip/dialog/rtp/media       0/0/0/0 (unchanged)
Asterisk active channels         0
rtpengine sessions / ports_used  0 / 0
kubectl diff -k overlays/local   exit 0 — zero drift
```

## Findings

| Classification | Finding |
|---|---|
| `PASS` | k3d publishes exactly 100 UDP ports on `127.0.0.1` for `40000-40099`, with no TCP in range, no `0.0.0.0` binding, and no control, metrics, runtime-RTP, SIP, or ESL publication |
| `PASS` | Proof-cluster creation with the 100-port range completes in 29.7 s; the k3d "may freeze your system" warning does not materialise at this size |
| `PASS` | The apiserver runs with `service-node-port-range=30000-40099` and admits the committed 100-port Service |
| `PASS` | `utcp.dev/media-edge=true` applies to `server:0` only |
| `PASS` | The committed `rtpengine-media` Service is structurally exact: 100 contiguous UDP ports, `port == targetPort == nodePort` for all 100, `externalTrafficPolicy: Local` |
| `PASS` | A Pod carrying the committed selector schedules onto the media-edge node, becomes Ready, and is the Service endpoint; no endpoint appears elsewhere |
| `PASS` | **Real host-loopback UDP traverses serverlb → media-edge node → NodePort → Pod, and the reply returns to the host** |
| `PASS` | The upstream source 5-tuple is stable at RTP cadence (10/10 datagrams from one port), so symmetric-RTP latching will hold |
| `PASS` | The canonical `utcp-local` cluster was preserved through stop/start with all state, and restored to zero drift |
| **`PRODUCT_DEFECT-23`** | The rtpengine entrypoint validates the **advertised** address with `require_ipv4()`, which rejects `127.*`. The committed `UTCP_PUBLIC_MEDIA_ADDRESS=127.0.0.1` therefore makes the entrypoint exit before rtpengine starts: `invalid UTCP_PUBLIC_MEDIA_ADDRESS for rtpengine advertisement: 127.0.0.1`. Seam: **rtpengine advertised address** |
| `PROOF_LIMITATION` | No committed check cross-validates the declared `UTCP_PUBLIC_MEDIA_ADDRESS` against the entrypoint's own validator, and none executes the entrypoint with the declared value — which is why the whole static suite passes against a projection that cannot start |
| `PROOF_LIMITATION` | k3d publishes via the **serverlb** nginx UDP proxy rather than binding the node container directly; the architecture document did not name this hop. It is correct behaviour and the path is proven, but the rerun should assert the nginx session stability explicitly |
| `EXPECTED_BEHAVIOR` | The repository has no canonical Helm bootstrap despite `gateway-config-check` requiring Helm; a temporary pinned `v4.0.3` binary was used and removed |

## PRODUCT_DEFECT-25 and PROOF_HARNESS_DEFECT-I Repository Correction

`PRODUCT_DEFECT-25` is corrected in the repository. The canonical internal
`allow-rtpengine-media` policy remains unchanged in the default local
projection. The dedicated media-edge projection now adds an ingress-only
`allow-rtpengine-external-media` policy selecting only the canonical rtpengine
Pods, omitting `from` so variable external browser addresses are admitted, and
restricting ingress to UDP `40000-40099`. It has no egress, source `ipBlock`,
control, metrics, runtime, SIP, ESL, ARI, or AMI access.

`PROOF_HARNESS_DEFECT-I` is corrected. The media-edge applicability checker
now parses structured JSON and unwraps `kind: List` items, while also handling
the supported direct-object and array shapes. It validates every nested
Service and NetworkPolicy, rejects malformed or duplicate objects, and keeps
the rendered projection and canonical range checks active. Static and
mutation coverage cover the external policy boundary and List normalization.

No Kubernetes resource was applied, no cluster was recreated, and no browser
media scenario was run. The external transport path, rtpengine advertisement,
and browser destination-port observations remain the previously established
live evidence; resumed browser proof is pending this repository correction.

## Status

```text
T3-S1 = Complete
T3-S2 = Complete
T3-S3A = Complete (infrastructure proven live; one entrypoint defect open)
T3-S3B = INCOMPLETE — blocked by PRODUCT_DEFECT-23
T3-S3 = In Progress
T3 = In Progress
UTCP_PHASE=T1
```

# Runner-Owned Runtime Hangup Correction — 2026-08-03

The bounded correction is implemented in `scripts/t3-media-prover/run` and
`scripts/t3-media-prover/runtime-hangup`. For `runtime-originated-bye` only,
the host runner snapshots the supported runtime before starting the browser
prover, streams prover stdout, consumes the exact
`UTCP_T3_MEDIA_PROVER_READY_FOR_RUNTIME_HANGUP` marker once, and invokes the
runtime-family transport automatically. The browser prover remains provider
neutral. Asterisk uses the existing explicit-context Kubernetes exec path and
`channel request hangup`; FreeSWITCH uses the existing loopback ESL
`uuid_kill` transport. Selection is a before/after delta scoped to the
proof endpoint (`from-kamailio`, `9900`, `Up`, `Echo`), with distinct zero and
ambiguous-candidate failures. Baseline channels are stored separately from
the selected owned channel, so failure cleanup cannot act on a pre-existing
channel.

Focused tests cover marker scope, single-trigger guarding, missing-marker
failure, zero/ambiguous/current-proof channel selection, provider-neutral
browser code, result freshness, API cleanup, and Kubernetes-mode preservation.
`make check` passed after the correction.

## Controlled live regression

The canonical host lifecycle created the disposable proof identity, tenant
context, signaling credentials, and session through the normal authenticated
HTTP API flow; it performed API cleanup on every run. No cookies, injected
sessions, direct SQL, Redis state, or manual PBX command was used.

An initial Scenario B attempt failed before the readiness marker at browser
`PeerConnection connected` readiness (`timed out waiting for PeerConnection
connected`, `durationMs=46984`). The runner then reported that the marker was
not reached and API cleanup passed; no runtime hangup was attempted. This was
not accepted as a Scenario B result.

A controlled retry passed:

```text
scenario                         runtime-originated-bye
marker                           observed exactly once
selected current-proof channel   PJSIP/anonymous-00000009
runtime action                   channel request hangup (automatic)
ICE / DTLS                       complete / connected
RTP outbound / inbound           175 / 175 packets; 28000 / 28000 bytes
inbound audio energy             0.10907418307530405
bye direction                    runtime
final SIP result                 200 OK
cleanup result                   signaling-closed
proof_api_cleanup                passed
```

The pre-existing Asterisk channel set was five immediately before this retry
and five after it; the selected channel was the exactly-one new delta. The
pre-existing channels were not selected or terminated.

Scenario A was then run unchanged and passed through the canonical lifecycle:

```text
scenario                         browser-originated-bye
RTP outbound / inbound           175 / 174 packets; 28000 / 27840 bytes
inbound audio energy             0.1090194999733286
bye direction                    browser
final SIP result                 SIP/2.0 200 OK
cleanup result                   signaling-closed
proof_api_cleanup                passed
runtime hangup actor             not activated
```

The current run proves the runner correction and both signaling/media
regressions. The four bounded failure cases and the containment sweep were
not run under this bounded task, so T3-S3B remains incomplete and phase status
was not changed. The first failed Scenario B attempt also left no accepted
current result and is retained above as a failed attempt rather than evidence.

No general remote-internet or cloud readiness is claimed. External browser media
readiness is **not** claimed.

## Recommended Next Step

Bounded implementation of `PRODUCT_DEFECT-23` — split the bind and advertised
address validators and add the cross-validation check and mutation cases — then
rerun this proof from §4. Every other element of the local media-edge projection
is already proven live and does not need re-litigation.

## PRODUCT_DEFECT-23 correction

The repository correction separates rtpengine bind and advertisement semantics.
`require_bindable_ipv4()` continues to reject loopback, link-local, multicast,
broadcast, malformed, and unspecified bind addresses. The new
`require_advertisable_ipv4()` accepts the committed local projection's
`127.0.0.1` while rejecting unspecified, link-local, multicast, broadcast, and
malformed values; `require_advertised_for_pod()` additionally rejects an
explicit value equal to `POD_IP`.

Both the entrypoint and the media-edge execution check source the same
repository-owned validation library. The declared `UTCP_PUBLIC_MEDIA_ADDRESS`
from `versions.env` passes as an advertised address and fails as a bind address;
the image-level preflight proves the effective interface remains
`internal/192.0.2.10!127.0.0.1` with media range `40000-40099`. The media-edge
static validator also rejects a declared value matching a rendered Service
ClusterIP and preserves the existing NodePort, placement, and publication
contracts.

The live local forwarding path is recorded accurately as:

```text
host loopback UDP publication
→ k3d serverlb nginx UDP proxy
→ media-edge server node
→ NodePort with externalTrafficPolicy Local
→ rtpengine Pod
```

No Kubernetes resource was applied, the cluster was not recreated, and the
browser media proof remains the T3-S3B gap.

## T3-S3B repository corrections at `099dcac`

`PRODUCT_DEFECT-24` is corrected. The media-edge Kustomization no longer reads
`versions.env` from outside its root. `scripts/media-edge/sync-public-media-projection`
deterministically generates the in-root `generated/public-media.env` from the
sole `versions.env` authority, validates the value through the shared
rtpengine advertised-address validator, and writes it atomically. The media
edge check compares authority, generated projection, and rendered Deployment
value, rejects unrestricted loading, duplicate variables, marker drift, and
unrelated generated content.

Plain `kubectl kustomize infrastructure/kubernetes/overlays/local-media-edge`
now succeeds without `LoadRestrictionsNone`. The repository applicability
target also invokes the canonical `kubectl apply -k ... --dry-run=client`
path. In this environment kubectl's configured API endpoint is unavailable,
so the client dry-run cannot complete discovery; the target reports that
environmental condition and validates the rendered object set offline instead.
No Kubernetes resource was applied.

`PROOF_HARNESS_DEFECT-H` is corrected. `scripts/t3-media-prover/run` now
supports exactly `kubernetes` (the unchanged default) and `host` execution
modes. Host mode invokes the same `tools/t3-media-prover/prover.mjs`, uses the
canonical host HTTPS/WSS origins, preserves both scenarios and the runtime
hangup readiness marker, propagates the shared result and exit-code contract,
and cleans transient state on exit or interruption. It creates no Kubernetes
proof resources. `scripts/t3-media-prover/host-check` and its mutation checks
cover preflight, dry-run orchestration, fail-closed modes, shared browser
logic, and cleanup contracts. No real browser or media scenario was run.

Status:

```text
T3-S3A = Complete
T3-S3B = ready to resume live host-browser proof
T3-S3 = In Progress
T3 = In Progress
UTCP_PHASE=T1
```

The remaining proof is the committed host-browser Scenario A and Scenario B
run against the temporary media-edge cluster, including reciprocal RTP, audio
energy, both BYE paths, failure behaviour, containment, and restoration.

---

# T3-S3B Live Host-Browser Run At `619ec15` — External Media Rejected By Policy

## Summary

`PRODUCT_DEFECT-24` is confirmed closed live: canonical
`kubectl apply -k infrastructure/kubernetes/overlays/local-media-edge`
succeeded against a fresh `utcp-mediaedge` proof cluster with no load-restrictor
override. The whole external edge — publication, host browser login, SIP/WSS
signalling, SDP mediation, advertised address, rtpengine port binding, serverlb
forwarding, and NodePort programming — is proven working.

External browser media still does not flow. The failure is isolated to exactly
one seam and one new product defect.

**Verdict: `T3_S3B_EXTERNAL_BROWSER_MEDIA_BLOCKED_BY_MEDIA_INGRESS_POLICY`.**

## PRODUCT_DEFECT-25 — No External Ingress Peer For Published Media

`allow-rtpengine-media` admits UDP `40000-40099` only from
`namespaceSelector` + `podSelector` peers:

```text
policy: allow-rtpengine-media  selector: {utcp.io/network-role: rtpengine-media}
   ingress ports: ['UDP/2223-']          from: [['namespaceSelector','podSelector']]
   ingress ports: ['UDP/40000-40099']    from: [['namespaceSelector','podSelector']]
   ingress ports: ['UDP/40000-40099']    from: [['namespaceSelector','podSelector']]
   ingress ports: ['TCP/2224-']          from: [['namespaceSelector','podSelector']]
```

Externally published media arrives through the NodePort from a **non-Pod
source** (the k3d serverlb / node address). It matches no peer, so the
kube-router pod firewall rejects it:

```text
-A KUBE-POD-FW-DWQNARJAWJKH3K42 -m comment --comment "rule to REJECT traffic
   destined for POD name:rtpengine-5f97dd98d6-sv2gg namespace: utcp-platform"
   -m mark ! --mark 0x10000/0x10000 -j REJECT --reject-with icmp-port-unreachable
```

That REJECT is what the serverlb reports, and it is the direct cause of the
browser failure:

```text
prover: "errors":["page.evaluate: Error: timed out waiting for ICE connected"]

serverlb: recv() failed (111: Connection refused) while proxying and reading
  from upstream, udp client: 172.21.0.1, server: 0.0.0.0:40023,
  upstream: "172.21.0.2:40023", bytes from/to client:4784/0,
  bytes from/to upstream:0/4784
```

The in-cluster T3-S2B proof passed because that prover ran **as a Pod**, which
does match the podSelector. The defect is therefore specific to the external
media edge and was not reachable before T3-S3B.

## Isolation Chain — Each Hop Proven In Turn

```text
host browser natural login          PASS  session 200, tenant local active, logout 401
SIP/WSS signalling                  PASS  INVITE challenged, dialog established
rtpengine SDP mediation             PASS  'offer' -> 'answer', "Creating new call"
advertised address                  PASS  --interface=internal/10.42.0.8!127.0.0.1
rtpengine media socket binding      PASS  /proc/net/udp shows 10.42.0.8:40004,40005,
                                          40033,40073,40082,40083 held for the call
browser destination correctness     PASS  browser sent to 40073 and 40033, both bound
host publication                    PASS  100 UDP maps on 127.0.0.1:40000-40099
serverlb forwarding                 PASS  bytes from/to upstream 0/4784 (forwarded)
NodePort programming                PASS  1100 nat rules, KUBE-NODEPORTS dport rules,
                                          endpoint 10.42.0.8 ready on the media-edge node
externalTrafficPolicy: Local        PASS  local ready endpoint present
node -> Pod media delivery          FAIL  42 rejects, 0 successes; ICMP port-unreachable
rtpengine browser leg counters      FAIL  in 0 p, 0 b; out 0 p, 0 b
```

The failing seam is **media ingress authorization**, not host-runner origin
selection, candidate rewriting, DTLS, host UDP publication, serverlb
forwarding, or NodePort forwarding.

## Method Notes

Probing an unallocated port in `40000-40099` also returns ICMP unreachable.
That is expected: rtpengine allocates media sockets per call, not all 100 at
boot. Every conclusion above correlates the probed port against the bound set
read from `/proc/net/udp` inside the rtpengine container during the live call.

## Not Executed

Scenario B, the four bounded failure cases, and the containment sweep were not
run. They depend on external media reaching rtpengine, which `PRODUCT_DEFECT-25`
prevents. Running them now would produce no additional information.

## PROOF_HARNESS_DEFECT-I — Applicability Check Does Not Unwrap `kind: List`

`scripts/media-edge/overlay-applicability-check` runs
`kubectl apply -k ... --dry-run=client -o yaml`, which returns a single
`kind: List` document with 45 `items`. The script scans only top-level
documents for `kind: Service`, so it never finds `rtpengine-media` and always
fails. This makes `make check`, `media-edge-projection-check`, and
`media-edge-overlay-applicability-check` fail at `619ec15` on a clean tree.

The underlying Service is correct. Server-side dry-run against the proof
cluster returned 45 objects, `rtpengine-media` as `NodePort` with
`externalTrafficPolicy: Local`, 100 contiguous nodePorts `40000-40099`,
0 violations, and ConfigMap `utcp-media-edge-authority` carrying
`UTCP_PUBLIC_MEDIA_ADDRESS=127.0.0.1`.

## Cleanup And Restoration

The `utcp-mediaedge` proof cluster was deleted. The preserved kubeconfig was
restored, the registry container was renamed back to `utcp-local-registry` and
started, and `utcp-local` was returned to service.

`k3d cluster start utcp-local` did not restore the cluster on its own: the
serverlb stayed `Exited`, and after starting it explicitly the apiserver still
failed with

```text
failed to start networking: unable to initialize network policy controller:
error getting node subnet: failed to find interface with specified node ip
```

A full `k3d cluster stop` followed by `k3d cluster start` resolved it.

Final state: 3 nodes Ready, 35 pods all fully ready, and
`kubectl diff -k infrastructure/kubernetes/overlays/local` exit `0` — zero
drift. Temporary Helm, the proof cluster profile, and `.playwright-mcp/` were
removed. No credential value appears in this document.

## Status

```text
T3-S3A = Complete
T3-S3B = ready to resume external browser media proof
T3-S3  = In Progress
T3     = In Progress
UTCP_PHASE=T1
```

## Recommended Next Step

Bounded implementation on `allow-rtpengine-media` to admit the external media
source for the published range, plus `PROOF_HARNESS_DEFECT-I`. The peer choice
is a security-policy decision — an `ipBlock` scoped to the node/cluster address
range versus an unrestricted `from` on the media ports — and must not be
silently widened.

---

# T3-S3B Live Run At `76d0bdd` — PRODUCT_DEFECT-25 Closed, Runtime Leg Mis-Advertised

## Summary

The ingress-policy correction works. External browser media now reaches
rtpengine, ICE completes, and rtpengine relays the browser audio to Asterisk.
Asterisk echoes it back correctly. The proof still fails because rtpengine
advertises the **public** address on the **runtime-facing** leg, so Asterisk
returns its RTP to its own loopback.

**Verdict: `T3_S3B_EXTERNAL_BROWSER_MEDIA_PROOF_INCOMPLETE`.**
**`PRODUCT_DEFECT-25` = closed. New `PRODUCT_DEFECT-26` blocks completion.**

## PRODUCT_DEFECT-25 — Closed And Proven

Live `allow-rtpengine-external-media` in `utcp-platform`:

```text
podSelector:  utcp.io/network-role=rtpengine-media   (selects 1 canonical Pod)
policyTypes:  ['Ingress']            egress present: False
ingress from: ABSENT (all sources)
ingress port: UDP 40000-40099 (endPort 40099)
ipBlock present: False
```

The policy exists only in the media-edge projection (the default `overlays/local`
render contains 0 occurrences), and the internal `allow-rtpengine-media` remains
present and unchanged (`2223`, `40000-40099` ×2, `2224`).

Packet admission, previously 0:

```text
rtpengine browser leg:
  Port 10.42.0.10:40020 <> 172.21.0.5:42000
  in 180 p, 30844 b        out 2 p, 794 b

[ice] ICE negotiated: peer for component 1 is 172.21.0.5:42000
[ice] ICE negotiated: local interface 10.42.0.10
```

Inbound external media is greater than zero and ICE negotiates against the
published edge. Asterisk's own pod-firewall REJECT counter stayed at **0**
for the whole call.

## PRODUCT_DEFECT-26 — Public Address Advertised On The Runtime Leg

rtpengine runs with a single interface:

```text
--interface=internal/10.42.0.10!127.0.0.1
```

The `!ADDR` advertised address applies to every SDP rtpengine generates from
that interface, including the offer sent to the application runtime. Captured
on the Asterisk node during a live proof call:

```text
10.42.2.5 -> 10.42.1.11  [INVITE sip:9900@sip.utcp.local.test]  c=IN IP4 127.0.0.1
10.42.2.5 -> 10.42.1.11  [INVITE sip:9900@sip.utcp.local.test]  m=audio 40090 RTP/AVP
10.42.1.11 -> 10.42.2.5  [SIP/2.0 200 OK]                       c=IN IP4 10.42.1.11
10.42.1.11 -> 10.42.2.5  [SIP/2.0 200 OK]                       m=audio 18886 RTP/AVP
```

Asterisk is instructed to send media to `127.0.0.1:40090` — its own loopback.
Asterisk's answer correctly advertises its Pod IP, which is why the
rtpengine→Asterisk direction works and the reverse does not:

```text
Asterisk pjsip show channelstats:
  anonymous-00000001  ulaw  Receive Count 173   Transmit Count 173

rtpengine runtime leg:
  Port 10.42.0.10:40018 <> 10.42.1.11:10514   in 0 p, 0 b   out 179 p, 30788 b
  Port 10.42.0.10:40019 <> 10.42.1.11:10515 (RTCP)  in 0 p   out 1 p
```

Asterisk receives 173 and transmits 173; rtpengine receives 0 of them. The
prover therefore fails on inbound media only:

```text
"errors":["inbound RTP packet count did not increase; inbound RTP byte count
 did not increase; received audio energy did not increase; received audio
 energy was not positive; audio energy source is not
 inbound-rtp.totalAudioEnergy"]
```

The runtime-facing leg must advertise the Pod IP while only the browser-facing
leg advertises the public address. The rtpengine-native form is two named
interfaces with per-leg direction selection, for example
`--interface=internal/<POD_IP>` plus `--interface=external/<POD_IP>!127.0.0.1`,
with the media adapter selecting direction per leg. That is a bounded
implementation on the media projection and the ng offer/answer direction
parameters, not a policy change.

## Environment And Baseline

Server-side dry-run admitted **46** objects, including `rtpengine-media` as
`NodePort` with `externalTrafficPolicy: Local`, 100 contiguous UDP nodePorts
`40000-40099`, `allow-rtpengine-external-media`, ConfigMap
`utcp-media-edge-authority -> {UTCP_PUBLIC_MEDIA_ADDRESS: 127.0.0.1}`, the
rtpengine `utcp.dev/media-edge=true` nodeSelector, and restricted Pod security
contexts (`runAsNonRoot=True`, `allowPrivilegeEscalation=False`,
`capabilities.drop=[ALL]`, `seccompProfile=RuntimeDefault`).

Canonical `kubectl apply -k infrastructure/kubernetes/overlays/local-media-edge`
succeeded with no load-restrictor override. rtpengine ran on the media-edge node
with `imageID` digest `sha256:a92227ec…` equal to the published registry digest,
0 restarts.

Host publication was exactly 100 UDP mappings on `127.0.0.1:40000-40099`, with
0 non-loopback UDP, 0 TCP inside the media range, and 0 published control,
metrics, SIP, ESL or runtime-RTP ports.

Natural host-browser login passed from `https://app.utcp.local.test` using the
real login form with no injected state: session `200`, user `active`,
`password_change_required=false`, active membership, 4 platform capabilities,
catalog `c5.2026-07-15`, logout `200`, session afterwards `401`.

## Not Executed

Scenario B, the four bounded failure cases, and the containment sweep were not
run. All of them depend on reciprocal browser media, which `PRODUCT_DEFECT-26`
prevents.

## Cleanup And Restoration

The `utcp-mediaedge` proof cluster was deleted; 0 containers and 0 networks
remain. The preserved kubeconfig and registry identity were restored and
`utcp-local` was returned to service with a full k3d stop/start cycle (a plain
`start` again left the serverlb `Exited` and k3s failing with
`failed to find interface with specified node ip`).

Restoration matches the recorded baseline exactly:

```text
pods                   35, all Ready
tables                 41   (baseline 41)
tenants                27   (baseline 27)
runtime_nodes         110   (baseline 110)
pending outbox          0   (baseline 0)
active channels         0
rtpengine allocations   0
redis media keys        0
kubectl diff -k overlays/local   exit 0 (zero drift)
```

Temporary Helm, the scratch cluster profile, and Playwright state were removed.
No credential value appears in this document.

## Status

```text
T3-S3A = Complete
T3-S3B = blocked on PRODUCT_DEFECT-26
T3-S3  = In Progress
T3     = In Progress
UTCP_PHASE=T1
```

## Recommended Next Step

Bounded implementation: give rtpengine a runtime-facing interface that
advertises the Pod IP and a browser-facing interface that advertises the public
address, and select direction per leg in the media adapter. Then re-run host
Scenario A and B, the failure cases, and containment.

# T3-S3B PRODUCT_DEFECT-26 Repository Correction

Date: 2026-08-03

Starting commit: `d100921251474a20345fc95774072eec820769cb`

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S3B_PRODUCT_DEFECT_26_IMPLEMENTED_REPOSITORY`

## Corrected Contract

Live T3-S3B evidence disproved the earlier single-interface advertisement
assumption. The process form
`--interface=internal/${POD_IP}!${UTCP_PUBLIC_MEDIA_ADDRESS}` advertised the
public browser address to the application-runtime-facing SDP as well as the
browser-facing SDP. With the local media-edge value `127.0.0.1`, Asterisk was
therefore instructed to return RTP to its own loopback.

The repository correction defines two named rtpengine logical interfaces:

```text
runtime/${POD_IP}!${POD_IP}
browser/${POD_IP}!${browser_advertised_address}
```

`browser_advertised_address` defaults to the Pod IP when
`UTCP_PUBLIC_MEDIA_ADDRESS` is absent, preserving the internal-only default. If
`UTCP_PUBLIC_MEDIA_ADDRESS` is configured, the entrypoint continues to validate
it with the advertised-address validator and uses it only on the browser-facing
interface. The Pod IP still uses the bind-address validator.

Kamailio remains the only rtpengine ng-control authority. The four existing
provider-neutral `rtpengine_offer` and `rtpengine_answer` calls now select
directions explicitly:

```text
runtime -> browser  for browser-facing SDP
browser -> runtime  for application-runtime-facing SDP
```

No fifth branch, application-runtime-side media authority, feature gate,
allowlist, manual switch, NetworkPolicy widening, or single-interface fallback
was added.

## Repository Verification

Focused checks were run before the implementation commit:

```text
./scripts/media-edge/config-check        PASS
./scripts/media-edge/config-check-test   PASS
./scripts/kamailio-signaling/config-check        PASS
./scripts/kamailio-signaling/config-check-test   PASS
```

The media-edge mutation suite now fails on runtime interface name drift,
browser interface name drift, runtime advertisement drift, browser public
projection removal, and browser default fallback drift. The Kamailio mutation
suite now fails on missing, swapped, or renamed rtpengine directions.

Live Scenario A, Scenario B, the four bounded failure cases, and the
containment sweep remain proof obligations for the subsequent live section of
this file.

## Canonical Lifecycle Correction

The earlier repository evidence used `infrastructure/k3d/cluster-media-edge.yaml`
as a temporary media-edge profile. That profile was not adopted as a supported
UTCP environment. The canonical lifecycle is now consolidated in
`infrastructure/k3d/cluster.yaml`: `utcp-local` owns the standard HTTP/HTTPS
edge, the loopback UDP range `127.0.0.1:40000-40099`, the
`utcp-local-registry` on `127.0.0.1:5001`, and the `utcp.dev/media-edge=true`
server label. The alternate profile was removed from the repository so the
external proof uses the same canonical cluster lifecycle as the baseline
deployment.

# Canonical Rebuild And Live Reproof Attempt

Date: 2026-08-03

The disposable `utcp-local` development environment was destroyed and
recreated under the explicitly authorized clean-install condition. The
canonical `make k3d-recreate-proof` lifecycle created one server, two agents,
the `utcp-local-registry` at `127.0.0.1:5001`, TCP edge publications on ports
80 and 443, and the complete `127.0.0.1:40000-40099/UDP` publication. The old
application, runtime, PVC, and local-path state was intentionally not restored.
`apntalk-local` remained stopped and untouched.

The first fresh recreation exposed a verifier command-order defect: the
repository emitted `kubectl --context ... -A get`, which the installed kubectl
rejected. The verifier now emits `kubectl --context ... get ... -A`, with a
focused regression check. A second verifier correction excludes the expected
kube-system `svclb-traefik` ServiceLB pods from the unauthorized-workload
check. The K4 observability configuration also now permits its declared
rtpengine PodMonitor and avoids matching the legitimate alert threshold as a
media-port declaration. The focused checks and `make check` pass after these
corrections. K4 installation itself remains blocked by the external Grafana
Helm repository timing out; no alternate deployment path was used.

The canonical application lifecycle then completed from the empty database:
`make k8s-apply` built and published the canonical images, ran migrations, and
made the platform and runtime workloads ready. A second `make k8s-apply`
completed idempotently. `make k8s-proof`, `make k8s-persistence-proof`, and
`make identity-bootstrap-local` passed. Gateway, security, and k3d verification
passed. The external media projection was applied through the repository-owned
`make media-edge-apply` lifecycle, which rolled out rtpengine and declared the
UDP media Service and its existing bounded NetworkPolicy.

After the media projection was installed, `gateway-proof` required an explicit
exception for the authorized `utcp-platform/rtpengine-media` NodePort. The
verifier was narrowed to continue rejecting every other direct K1
platform/data exposure.

Live rtpengine inspection after that rollout showed the expected pinned
version and distinct interfaces:

```text
--interface=runtime/10.42.0.29!10.42.0.29
--interface=browser/10.42.0.29!127.0.0.1
--port-min=40000 --port-max=40099
```

The first real host-mode browser proof used the normal application login flow
and the repository Playwright prover. SIP signaling reached the application
runtime, but the proof failed at its media assertion: inbound RTP packet and
byte counters did not increase, and browser
`inbound-rtp.totalAudioEnergy` was not positive. The sanitized prover result is
kept only in ignored `.runtime` state. No successful Scenario A claim is made.
Scenario B, the four bounded failure cases, and the live containment sweep
were not completed after Scenario A exposed this remaining runtime media-path
gap. The static containment and NetworkPolicy checks passed, but that does not
substitute for the required live sweep.

## Current Status After Rebuild

```text
canonical rebuild and fresh install = passed
PRODUCT_DEFECT-26 repository implementation = passed
PRODUCT_DEFECT-26 live interface inspection = passed
T3-S3B Scenario A = failed: no inbound RTP/audio energy
T3-S3B Scenario B = not proven
T3-S3B bounded failure cases = not run to completion
T3-S3B live containment sweep = not proven
T3-S3B = blocked
```

The historical `utcp-mediaedge` incident remains recorded above; it was not
created or adopted during this run. The canonical lifecycle and the clean
rebuild are the only supported environment path.

# T3-S3B Scenario A Diagnosis At `dddf688` — Media Path Proven End-To-End

Date: 2026-08-03. Evidence-only diagnostic run. No repository implementation
change, no commit, no cluster recreation, no manifest application, no live
resource patch, no NetworkPolicy change.

## Summary

The reported Scenario A media failure **does not reproduce**. Two consecutive
natural host-browser Scenario A runs against the deployed `dddf688` stack passed
with reciprocal RTP and positive inbound audio energy. Every hop from the host
loopback edge to Asterisk and back carries traffic.

**`PRODUCT_DEFECT-26` is closed and live-proven.** The captured INVITE toward
the application runtime now advertises the rtpengine Pod IP, and Asterisk's RTP
returns to rtpengine instead of its own loopback.

There is **no failed RTP hop**. The first-failed-hop question is answered
negatively at every one of the twelve boundaries examined.

## Baseline Environment

```text
cluster/context      utcp-local / k3d-utcp-local
nodes                server-0 172.21.0.2, agent-0 172.21.0.3, agent-1 172.21.0.4, all Ready
rtpengine Pod        10.42.0.29 on k3d-utcp-local-server-0, 0 restarts
rtpengine image      utcp-local-registry:5000/utcp/rtpengine@sha256:32ee825d…
asterisk-ari Pod     10.42.1.4 on k3d-utcp-local-agent-0
kamailio Pod         10.42.0.6 on k3d-utcp-local-server-0
rtpengine-media Svc  NodePort, externalTrafficPolicy Local, 100 UDP ports
                     port == targetPort == nodePort for all of 40000-40099
EndpointSlice        10.42.0.29 ready, node k3d-utcp-local-server-0, 100 ports
host publication     100 UDP mappings 127.0.0.1:40000-40099 on the serverlb only;
                     server-0, agent-0 and agent-1 publish nothing
k3d serverlb         one nginx stream server per media port, single upstream
                     k3d-utcp-local-server-0:<same port>
apntalk-local        stopped, untouched
utcp-mediaedge       absent
registry             127.0.0.1:5001 only
```

Live rtpengine arguments:

```text
--listen-ng=10.42.0.29:2223
--interface=runtime/10.42.0.29!10.42.0.29
--interface=browser/10.42.0.29!127.0.0.1
--port-min=40000 --port-max=40099
```

## Natural Proof Method

Two disposable proof members, telephony sessions and signaling credentials were
issued **through the canonical HTTP API only** — `/api/v1/auth/login`,
`/api/v1/auth/tenant-context`, `/api/v1/admin/users`,
`/api/v1/admin/memberships`, `/api/v1/telephony/sessions` and
`/api/v1/telephony/sessions/{id}/signaling-credential`. No SQL, no Redis, no
injected session. Both sessions were then ended through
`/api/v1/telephony/sessions/{id}/end`; the Kamailio auth view returned to `0`
rows, so no signaling credential remains live.

The media proof itself is the unmodified repository lifecycle:

```text
./scripts/t3-media-prover/run --execution-mode host --scenario browser-originated-bye
```

## Scenario A Result — Two Consecutive Passes

```text
                       run 1                 run 2
errors                 []                    []
dtlsState              connected             connected
localCandidateType     prflx                 prflx
remoteCandidateType    host (loopback)       host (loopback)
outboundRtpPackets     176 / 28160 b         177 / 28320 b
inboundRtpPackets      175 / 28000 b         176 / 28160 b
audioEnergy            0.11080               0.11031
audioEnergySource      inbound-rtp.totalAudioEnergy
packetsLost            1                     0
byeDirection           browser               browser
finalSipResult         SIP/2.0 200 OK        SIP/2.0 200 OK
cleanupResult          signaling-closed      signaling-closed
runner exit            0                     0
```

## Runtime-Facing SDP — PRODUCT_DEFECT-26 Closed

Captured on the runtime-facing UDP `5060` leg (headers other than the SDP media
lines were discarded before output):

```text
10.42.0.6:5060 -> 10.42.1.4:5060   INVITE sip:9900@sip.utcp.local.test SIP/2.0
    m=audio 40014 RTP/AVP 111 63 9 0 8 13 110 126
    c=IN IP4 10.42.0.29
    a=rtcp:40015

10.43.214.161:5060 -> 10.42.0.6:5060   SIP/2.0 200 OK
    c=IN IP4 10.42.1.4
    m=audio 12310 RTP/AVP 0 126
```

The runtime-facing connection address is the rtpengine **Pod IP**, not
`127.0.0.1`. This is the exact line that carried `c=IN IP4 127.0.0.1` at
`76d0bdd`.

## Hop-By-Hop Packet Observation

Bounded UDP flow counters in the `k3d-utcp-local-server-0` and
`k3d-utcp-local-agent-0` network namespaces during run 1. Headers and sizes
only; no payload retained.

```text
 4  NodePort ingress at the node
    eth0          172.21.0.5:46965 -> 172.21.0.2:40005   193 p   36787 b
 5  DNAT to the rtpengine Pod, client source preserved (externalTrafficPolicy Local)
    cni0/veth     172.21.0.5:46965 -> 10.42.0.29:40005   193 p   36787 b
 7  rtpengine runtime leg outbound to Asterisk
    flannel.1     10.42.0.29:40036 -> 10.42.1.4:18614    181 p   31132 b
 8  same flow observed arriving on the Asterisk node
    veth (agent-0) 10.42.0.29:40036 -> 10.42.1.4:18614   181 p   31132 b
 9  Asterisk return RTP leaving its Pod
    veth (agent-0) 10.42.1.4:18614 -> 10.42.0.29:40036   175 p   30100 b
10  same flow arriving at the rtpengine Pod
    veth (server-0) 10.42.1.4:18614 -> 10.42.0.29:40036  175 p   30100 b
11  rtpengine browser leg outbound
    veth          10.42.0.29:40005 -> 172.21.0.5:46965   183 p   34346 b
    eth0          172.21.0.2:40005 -> 172.21.0.5:46965   183 p   34346 b
```

rtpengine's own final per-leg statistics for the same call:

```text
Port 10.42.0.29:40005 <>  172.21.0.5:46965          in 183 p, 31220 b   out 177 p, 33694 b
Port 10.42.0.29:40036 <>   10.42.1.4:18614          in 175 p, 30100 b   out 181 p, 31132 b
Port 10.42.0.29:40037 <>   10.42.1.4:18615 (RTCP)   in   0 p,     0 b   out   2 p,    88 b
```

The runtime leg inbound counter is **175**, not `0`. ICE negotiated against the
published edge:

```text
[ice] ICE negotiated: peer for component 1 is 172.21.0.5:46965
[ice] ICE negotiated: local interface 10.42.0.29
```

Kamailio recorded the full media lifecycle for the same `Call-ID`:

```text
kamailio_websocket_accepted        result=ok
kamailio_application_dialog_challenge result=challenge  method=INVITE
kamailio_application_dialog_media  result=media_offer   method=INVITE
kamailio_application_dialog_media  result=media_answer  method=INVITE
kamailio_application_dialog_media  result=media_delete  method=BYE
```

## Why The Previous Run Reported A Failure

The previously reported Scenario A failure was **not produced by this deployed
stack**. Three independent observations establish that no Scenario A call was
executed after the media-edge rollout:

1. rtpengine started `2026-08-03T00:23:30Z` with `0` restarts and, over the
   whole diagnostic window, had received exactly **two** `offer` commands — both
   from this run. `rtpengine_sessions_total` was `2`.
2. The Kamailio Pod predates the rtpengine rollout, has `0` restarts, and its
   entire log before this run contains **only startup lines** — no
   `websocket_accepted`, no `challenge`, no media event.
3. `.runtime/t3-media-prover/host-browser-originated-bye.json` still carried its
   `2026-08-02` modification time and `durationMs 4858` immediately before this
   run. The failure string quoted for the previous run is verbatim identical to
   that stale file's contents.

The prover writes its result file on failure as well as on success, so an
executed-and-failed Scenario A would have rewritten that file. It did not.

## Repository-Owned Gap Identified

`scripts/t3-media-prover/run` removes the previous result only in the
`kubernetes` branch (`rm -f "$RESULT_DIR/result.json"`). The `host` branch
computes `host-${SCENARIO}.json` and never clears it, and there is no staleness
guard. A result file written by an earlier cluster therefore survives a full
canonical rebuild and can be read as if it were the current outcome. This is a
`PROOF_HARNESS_DEFECT`, not a media-path defect.

A second, larger gap: both execution modes `require_env` four credential values
with no repository-owned lifecycle that issues them through the canonical API,
so every Scenario A run depends on out-of-band provisioning.

## Non-Blocking Observations

- Kamailio logs one `PGRES_FATAL_ERROR` / `terminating connection due to
  administrator command` pair per call, on the first database query of a worker
  process. PostgreSQL itself logs no `FATAL` and no shutdown. Kamailio
  reconnects, digest authentication succeeds, and the call completes. Pre-existing
  and non-blocking; it did not affect either run.
- `/metrics` exposes `rtpengine_ports*` and `rtpengine_interface_*` only under
  `name="runtime"`. The `browser` interface has no series even though it
  allocated and released port `40005`. Observability labelling gap only.

## Status After This Diagnosis

```text
PRODUCT_DEFECT-26                      = closed, live-proven
T3-S3B Scenario A                      = passed, reproduced twice
T3-S3B Scenario B                      = not run in this diagnostic run
T3-S3B bounded failure cases           = not run in this diagnostic run
T3-S3B live containment sweep          = not run in this diagnostic run
T3-S3B                                 = not complete; no longer blocked
T3-S3 / T3                             = In Progress
UTCP_PHASE=T1
```

Scenario B, the four bounded failure cases, and the live containment sweep
remain unproven because this run was scoped to the Scenario A diagnosis. No
completion claim is made for them.

## Environment State After This Run

```text
rtpengine sessions own/foreign         0 / 0
rtpengine ports used/free              0 / 100
rtpengine restarts                     0
new Pod restarts caused by this run    0
kamailio_signaling_auth_view rows      0
proof telephony sessions               2 created, 2 ended through the canonical API
Kubernetes resources created           none
Kubernetes resources modified          none
```

# Host Result Freshness And Canonical Proof Lifecycle At `dddf688`

Date: 2026-08-03. Bounded harness correction and controlled live reproof.

## Harness correction

`scripts/t3-media-prover/run` now resolves and removes the host result before
any host prerequisite, setup, browser, or proof execution can fail. A current
result is required after the prover returns. The host runner now creates its
proof identity through the existing authenticated HTTP API lifecycle, writes
the four child-process credential values only to a mode-600 temporary file,
and removes that state on exit. Cleanup ends the telephony session through the
admin API and suspends the disposable proof user; no SQL, Redis, injected
session, or hard-coded credential was used.

The focused host mutation test passed for a pre-seeded result followed by a
failed prover: the old result was absent. A controlled successful child then
wrote and was consumed as the current result. Removing the cleanup line was
detected by the mutation assertion. Existing Kubernetes-mode checks remained
passing.

## Controlled live results

Scenario A was run once after the correction through the canonical host runner.
It created a fresh result after the pre-run removal and completed normal API
cleanup. Sanitized current values were:

```text
errors                 []
dtlsState              connected
peerConnectionState    closed
outboundRtpPackets     179
inboundRtpPackets      178
inboundRtpBytes        28480
audioEnergy             0.11224649268565433
audioEnergySource       inbound-rtp.totalAudioEnergy
byeDirection            browser
finalSipResult          SIP/2.0 200 OK
cleanupResult           signaling-closed
runner API cleanup      passed
```

This is a current Scenario A pass and is consistent with the already proven
PRODUCT_DEFECT-26 runtime-facing Pod advertisement and reciprocal media path.

Scenario B was attempted through the same lifecycle. It reached the current
runtime-hangup readiness marker, but the current result recorded:

```text
errors          timed out waiting for SIP message
cleanupResult   failed-before-cleanup
durationMs      125374
```

The runner's API cleanup completed after the attempt. Scenario B is therefore
not a pass. The failure is a live proof gap in the runtime-originated hangup
path; no media-topology or PRODUCT_DEFECT-26 change was made.

The four bounded failure cases and the live containment sweep were not run to
completion after Scenario B failed. Static media-edge, NetworkPolicy,
default-overlay, and security checks remain passing, but they do not replace
the required live sweep. `docs/roadmap/phase-status.md` was not changed and
T3-S3B remains incomplete.

# T3-S3B Scenario B Diagnosis At `894f1d1` — No Runtime Hangup Trigger Exists

Date: 2026-08-03. Evidence-only diagnostic run. No repository implementation
change, no commit, no cluster/registry/overlay/NetworkPolicy/media-topology
change, no SQL or Redis mutation, no hangup stimulus issued.

## Summary

Scenario B does not fail in the media plane, in Asterisk, in Kamailio, or in the
browser. It fails at the **second** lifecycle boundary: after the prover emits
its readiness marker, **nothing in the repository ever triggers the
runtime-originated hangup**, so the browser waits out the full
`runtimeByeWaitMs` window and throws.

`scripts/t3-media-prover/run` never reads
`UTCP_T3_MEDIA_PROVER_READY_FOR_RUNTIME_HANGUP` in either execution mode. The
only repository references to that marker are `prover.mjs` (which emits it) and
`scripts/t3-media-prover/config-check` (which asserts it is emitted exactly
once, after the media assertions, only for `runtime-originated-bye`). No
consumer exists.

## Scenario B Expected Lifecycle And Awaited Transition

```text
prover: INVITE 9900 → 401 → INVITE(auth) → 200 OK → ACK
prover: ICE connected → PeerConnection connected → assertMediaCounters
prover: document.body.dataset.utcpMediaReady = 'runtime-originated-bye'
prover: stdout UTCP_T3_MEDIA_PROVER_READY_FOR_RUNTIME_HANGUP
  ⟵ AN EXTERNAL ACTOR MUST NOW HANG UP THE RUNTIME CHANNEL
runtime: BYE toward the browser through the Kamailio alias corridor
prover: 200 OK, byeDirection='runtime', finalSipResult='200 OK'
runner: canonical API cleanup
```

The awaited message is exactly `prover.mjs:249`:

```js
const bye = await waitForMessage(
  messages,
  (message) => /^BYE\s/i.test(message) && message.includes(callId),
  cfg.runtimeByeWaitMs,
  messageCursor);
```

Timeout and matching rules: `runtimeByeWaitMs` defaults to `120000` ms
(`UTCP_T3_MEDIA_PROVER_RUNTIME_BYE_WAIT_MS`); there is no retry. `messageCursor`
is created at `prover.mjs:150` before the WebSocket handler and only advances
past consumed messages, so every inbound SIP message from WS open onward is
retained and scanned — there is no subscription window. `byeDirection` is set to
the literal `'runtime'` and `finalSipResult` to the literal `'200 OK'` only
*after* the BYE is matched, which is why both were empty in the reported result.

## Controlled Correlation Window

One canonical run, `./scripts/t3-media-prover/run --execution-mode host
--scenario runtime-originated-bye`. No stale result existed (the result
directory was empty). Credentials were newly provisioned and cleaned up by the
runner's own canonical API lifecycle.

```text
01:51:50.198  baseline: 3 pre-existing Up channels, rtpengine own sessions 2
01:51:53.257  runner start; proof_api_setup=passed
01:51:57.580  Kamailio 10.42.0.6 → Asterisk 10.42.1.4   INVITE sip:9900@…  CSeq: 2 INVITE
01:51:57.581  Asterisk → Kamailio                        100 Trying         CSeq: 2 INVITE
01:51:57.581  Asterisk → Kamailio                        200 OK             CSeq: 2 INVITE
01:51:57.676  Kamailio → Asterisk                        ACK                CSeq: 2 ACK
01:51:58±     prover stdout: UTCP_T3_MEDIA_PROVER_READY_FOR_RUNTIME_HANGUP  (exactly once)
01:52:00      Asterisk active channels 3 → 4
01:52:00 … 01:54:17   active channels constant at 4
01:51:57 … 01:54:32   runtime-facing SIP: ZERO further messages, ZERO BYE
01:54:00      prover throws 'timed out waiting for SIP message'  durationMs 126568
01:54:03.717  proof_api_cleanup=passed; runner exit 1
```

`126568 ms` = ~6.5 s of setup, signalling and media assertion, plus exactly the
`120000 ms` runtime-BYE window.

## Boundary-By-Boundary Result

```text
 1 readiness marker reached          PASSED   marker on stdout exactly once, after media assertions
 2 runtime hangup trigger issued     FAILED   no trigger in the repository; none observed
 3 trigger reaches the runtime       UNPROVED not reached
 4 runtime accepts/rejects trigger   UNPROVED not reached
 5 Asterisk generates the BYE        UNPROVED not reached; 0 BYE in 155 s of capture
 6 Kamailio receives the BYE         UNPROVED not reached; no media_delete logged
 7 Kamailio forwards the BYE         UNPROVED not reached
 8 browser receives the BYE          UNPROVED not reached
 9 browser answers 200 OK            UNPROVED not reached
10 harness recognises the event      UNPROVED not reached
11 byeDirection/finalSipResult set   FAILED   both empty; set only after the BYE match
12 result finalised before timeout   FAILED   126568 ms, errors=['timed out waiting for SIP message']
13 API cleanup completes             PASSED   proof_api_cleanup=passed
```

Kamailio logged `websocket_accepted → challenge → media_offer → media_answer`
for this `Call-ID` and **no `media_delete`**, which only executes on a BYE.
Asterisk logged nothing beyond one pre-existing `No SIP authenticator
registered` warning.

## Hypotheses

```text
CONFIRMED  no runtime hangup trigger exists in the proof lifecycle
           scripts/t3-media-prover/run has no marker consumer in either mode;
           the marker's only other references are config-check assertions.
           Historical Scenario B passes were externally stimulated by hand:
           docs/evidence/t3/t3-s2c-freeswitch-runtime-parity-live-proof.md
           records the literal command fs_cli -H 127.0.0.1 -P 8021 -x
           'uuid_kill <uuid>', with durationMs 6401 — about one second after
           the marker. T3-S2B recorded durationMs 6029 the same way.

REJECTED   harness subscribed too late / marker precedes listener readiness
           messageCursor exists from before WS handler installation and never
           skips; any BYE at any time would have been matched.
REJECTED   overly restrictive matcher (method/status/dialog/ordering)
           the same predicate matched live against both Asterisk (T3-S2B) and
           FreeSWITCH (T3-S2C); and no BYE was emitted at all to match.
REJECTED   Asterisk generated a different transaction
           155 s of runtime-facing capture contains exactly INVITE/100/200/ACK.
REJECTED   Kamailio filtered or misrouted the BYE
           no BYE reached Kamailio; no media_delete; nothing to filter.
REJECTED   dialog identifier or tag mismatch
           the dialog established normally and media flowed; no in-dialog
           request was ever generated by either side.
REJECTED   cleanup/timeout race with result finalisation
           the prover wrote its result, then the runner's API cleanup passed.
REJECTED   unjustifiably short timeout
           120 s versus a ~1 s historical marker-to-BYE latency. A timeout
           increase would not change the outcome and is not supported.
```

## Abandoned-Dialog Residue — Separate Bounded Finding

A timed-out Scenario B leaves the dialog permanently established. The prover
closes its WebSocket without a BYE, and nothing reaps the result:

```text
before this run   3 Up channels (all 9900@from-kamailio Echo), rtpengine own sessions 2, ports used 6
after this run    4 Up channels,                                rtpengine own sessions 3, ports used 9
                  rtpengine ports free 91 of 100
```

`infrastructure/docker/asterisk/config/rtp.conf` declares only `rtpstart`/
`rtpend` — no `rtptimeout` or `rtpholdtimeout` — and
`infrastructure/docker/asterisk/config/pjsip.conf` declares no session timers.
Each abandoned attempt consumes one channel and three media ports, so the
100-port pool tolerates roughly thirty more before exhaustion. This is
independent of the missing trigger and would still occur on any Scenario B
failure.

## Correction To An Earlier Note In This Document

The earlier Scenario A diagnosis recorded that `/metrics` exposes
`rtpengine_ports*` only under `name="runtime"`. That observation was taken while
the pool was idle. With sessions active the endpoint emits `name="runtime"`,
`name="browser"` and `name="default"` series. There is no observability gap.

## Scenario A And PRODUCT_DEFECT-26 Regression Status

Not re-run in this diagnostic. The Scenario B attempt independently re-exercised
the same corridor up to and including media: authenticated INVITE, `200 OK`,
ACK, ICE, DTLS and `assertMediaCounters(before, after)` all passed before the
readiness marker was emitted, so the PRODUCT_DEFECT-26 media path remained
correct throughout.

## Status After This Diagnosis

```text
T3-S3B Scenario A                      = passed (previous run, unchanged)
T3-S3B Scenario B                      = not proven; blocked at boundary 2
T3-S3B bounded failure cases           = not run
T3-S3B live containment sweep          = not run
T3-S3B                                 = not complete
T3-S3 / T3                             = In Progress
UTCP_PHASE=T1
```
