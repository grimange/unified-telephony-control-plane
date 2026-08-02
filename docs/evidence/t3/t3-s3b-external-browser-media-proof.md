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

No general remote-internet or cloud readiness is claimed. External browser media
readiness is **not** claimed.

## Recommended Next Step

Bounded implementation of `PRODUCT_DEFECT-23` — split the bind and advertised
address validators and add the cross-validation check and mutation cases — then
rerun this proof from §4. Every other element of the local media-edge projection
is already proven live and does not need re-litigation.
