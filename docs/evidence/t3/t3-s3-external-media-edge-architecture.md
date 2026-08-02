# T3-S3 External Browser Media-Edge Architecture

Date: 2026-08-02

Starting commit: `803b819` (`docs(t3): close freeswitch runtime parity`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S3_EXTERNAL_MEDIA_EDGE_ARCHITECTURE_COMPLETE`

Decision standard: `EVIDENCE_SUFFICIENT_FOR_BOUNDED_IMPLEMENTATION`

Kubernetes apply performed: **none**. Cluster unchanged.

## Scope

Select the canonical architecture for external browser ICE/RTP reachability.
This is an evidence and architecture decision run only. No public media is
implemented or exposed, no Kubernetes resource is applied, and no route,
firewall, or Docker port mapping is changed.

The completed internal contracts (T3-S1, T3-S2A, T3-S2B, T3-S2C) are treated as
closed and are not reopened.

## Current External Media Topology

Read-only inspection of the live `utcp-local` cluster:

```text
k3d cluster        utcp-local, k3d v5.9.0, k3s v1.35.3+k3s1, 1 server + 2 agents
published ports    k3d-utcp-local-serverlb only:
                     80/tcp  -> 127.0.0.1:80
                     443/tcp -> 127.0.0.1:443
                     6443/tcp -> 127.0.0.1:6550
                   server-0, agent-0, agent-1: no published ports at all
node addresses     serverlb 172.24.0.5, server-0 172.24.0.2,
                   agent-0 172.24.0.4, agent-1 172.24.0.3
host -> node net   172.24.0.0/16 dev br-b3b72cfb4f82 src 172.24.0.1  (routable)
host -> pod CIDR   ip route get 10.42.0.179 -> via 192.168.86.1 dev wlo1  (NOT routable)
rtpengine Pod      10.42.0.179 on k3d-utcp-local-agent-0
rtpengine Service  ClusterIP 10.43.50.16, UDP 2223 (ng control) only
container ports    ng 2223/UDP, media 40000/UDP, metrics 2224/TCP
traefik Service    LoadBalancer, externalIPs 172.24.0.2/.3/.4, ports 80/TCP 443/TCP
traefik entryPoints  web :80/tcp, websecure :443/tcp, traefik :8080/tcp, metrics :9100/tcp
                     (no UDP entry point)
LoadBalancer impl  k3s klipper-lb, DaemonSet svclb-traefik-*
Gateway API        v1.5.1 standard install; CRDs present:
                     backendtlspolicies, gatewayclasses, gateways, grpcroutes,
                     httproutes, listenersets, referencegrants, tlsroutes
                   udproutes.gateway.networking.k8s.io  NOT installed
NodePort range     default 30000-32767 (k3s server has no --service-node-port-range
                   override; live allocations observed at 30480 and 32417)
```

Current path per traffic class:

```text
browser HTTPS/WSS   host 127.0.0.1:443 -> k3d serverlb -> klipper-lb hostPort
                    -> traefik -> gateway/kamailio            WORKS
browser ICE         SDP answer carries rtpengine's Pod IP 10.42.0.x
browser UDP media   host -> 10.42.0.x : no route                FAILS
rtpengine return    never established (no inbound flow to latch onto)
```

## Current RTPengine Interface And Port Authority

`infrastructure/docker/rtpengine/entrypoint` builds the command line:

```sh
--listen-ng="${POD_IP}:${RTPENGINE_NG_PORT}" \
--interface="internal/${POD_IP}!${POD_IP}" \
--port-min="${RTPENGINE_MEDIA_PORT_MIN}" \
--port-max="${RTPENGINE_MEDIA_PORT_MAX}" \
--listen-http="${POD_IP}:${RTPENGINE_METRICS_PORT}"
```

The pinned binary documents the syntax itself:

```text
-i, --interface=[NAME/]IP[!IP]     Local interface for RTP
```

The trailing `!IP` **is** the advertised address. Today it is set to the same
`POD_IP` as the bind address, which is exactly why the browser receives an
in-cluster candidate. **The advertised-address seam already exists and needs no
new mechanism** — only a canonical value.

Port authority is single-sourced today:

```text
versions.env                 RTPENGINE_MEDIA_PORT_MIN=40000
                             RTPENGINE_MEDIA_PORT_MAX=40099
ConfigMap rtpengine-config   RTPENGINE_MEDIA_PORT_MIN/MAX
entrypoint                   hard assertion that the range is exactly 40000-40099
scripts/media/config-check   asserts the same range
```

## Exact External Reachability Failure

Established from committed evidence
(`docs/evidence/t3/t3-s2-provider-neutral-media-live-proof.md`) and re-confirmed
by read-only route inspection this run. No new browser media scenario was run.

```text
browser local candidate     host / private (the browser's own LAN or pod address)
rtpengine remote candidate  Pod IP inside 10.42.0.0/16, port inside 40000-40099
host route to that address  absent — resolves via the default gateway, off-box
observed browser state      ICE "checking", DTLS "connecting", 0 packets sent
```

The failure class is exactly:

```text
the browser receives a candidate that is valid inside the cluster
but unreachable from the external browser network
```

It is **not** any of:

| Excluded cause | Disproof |
|---|---|
| NetworkPolicy denial | in-cluster prover reached rtpengine through the same policies in T3-S2B/T3-S2C |
| rtpengine port-range denial | media flowed on `40000-40099` in both proofs |
| Runtime RTP denial | Asterisk `10.42.1.x:15094` and FreeSWITCH `10.42.1.15:21018` both carried RTP |
| Codec mismatch | PCMU negotiated and echoed in both proofs |
| ICE credential failure | ICE reached `connected` in-cluster |
| DTLS failure | `dtlsState connected`, `AEAD_AES_256_GCM` negotiated |
| SIP/SDP failure | `200 OK` with SDP, ACK, and both BYE directions all proven |

The only missing element is a reachable candidate address.

## Official Capability Findings

| Product | Version | Capability | Limitation | Application to UTCP |
|---|---|---|---|---|
| rtpengine | `mr26.0.1.19` (pinned) | `-i, --interface=[NAME/]IP[!IP]` — advertised address is first-class | one advertised address per interface | the seam already exists; supply a canonical public value |
| k3d | `v5.9.0` (pinned) | `--port` supports ranges; docs example `"30000-32767:30000-32767@server:0"` | docs warn: *"Docker creates iptable entries and a new proxy process per port-mapping, so this may take a very long time or even freeze your system!"*; ports are fixed at cluster create | 100 ports is two orders of magnitude below the cited example, but startup cost must be measured; publication requires recreation |
| Kubernetes | `v1.35.3+k3s1` | `Service.spec.ports` is `[]ServicePort` with scalar `port`/`nodePort` | **no port-range field exists** (`kubectl explain service.spec.ports`) | any Service-based model needs 100 enumerated entries |
| Kubernetes | `v1.35.3+k3s1` | NodePort supports UDP | default range `30000-32767`, changed only by the apiserver `--service-node-port-range` flag | `40000-40099` is outside the default; requires a k3s server arg, i.e. cluster recreation |
| PSA | `restricted:v1.35` enforced on `utcp-platform` | — | **forbids `hostPort` and `hostNetwork`** | proven by server dry-run (below); eliminates host-namespace media edges |
| klipper-lb | `v0.4.15` (k3s built-in) | provides real UDP-capable LoadBalancer locally | creates **one container per Service port** (observed: `lb-tcp-80`, `lb-tcp-443`) | 100 ports ⇒ 100 containers per node — impractical |
| Gateway API | `v1.5.1` standard install (SHA-pinned) | HTTPRoute, GRPCRoute, TLSRoute, ListenerSet, … | `UDPRoute` CRD **not installed** by the standard channel | Gateway-API UDP is unavailable without adding the experimental channel |
| Traefik | chart `41.0.2` | supports UDP entry points in principle | running entry points are `web/websecure/traefik/metrics`, all `/tcp` | no UDP edge exists; ADR-020 already forbids RTP over the 443 route |

PSA proof (server dry-run, non-mutating):

```text
hostPort 40000    Forbidden: violates PodSecurity "restricted:v1.35":
                  hostPort (container "c" uses hostPort 40000)
hostNetwork true  Forbidden: violates PodSecurity "restricted:v1.35":
                  host namespaces (hostNetwork=true)
```

## Prior Architectural Statements

ADR-020 already anticipates this slice and names the intended direction:

> Browser-reachable media requires **publishing a host UDP range** and is
> explicitly **deferred** to a later T3 slice, gated on this ADR being extended.

> browser-reachable media needs a later slice that **publishes a host UDP range**
> and extends this ADR

Its rejections were scoped to T3-S1 and are re-evaluated below on their stated
grounds, not carried over automatically.

## Model Evaluation

### Model A — k3d host UDP range publication

Supported by the pinned k3d and by ADR-020's stated direction. Publishes
`127.0.0.1:40000-40099/udp` on a chosen node container, mirroring the existing
`127.0.0.1:443` edge. Requires cluster recreation.

**Publication alone is insufficient**: traffic lands in the node network
namespace, and the rtpengine Pod is in a different namespace. Something must
forward node→Pod. With `hostPort` and `hostNetwork` both forbidden by PSA, the
only PSA-compatible forwarder is a NodePort Service. Model A is therefore
selected **in combination with** Model B, not instead of it.

### Model B — Kubernetes NodePort

Reaches the Pod via kube-proxy with no pod-level host namespace, so PSA
`restricted` is preserved. Costs: `40000-40099` lies outside the default
`30000-32767`, so the apiserver needs `--service-node-port-range` (cluster
recreation); and 100 ports must be enumerated because the Service schema has no
range field.

Used alone (without k3d publication) it would work locally, because the host
routes to `172.24.0.0/16`. It is rejected alone because the advertised candidate
would then have to be a **node IP**, and node IPs demonstrably shuffle on host
restart (recorded repeatedly in T3 evidence, most recently the
`failed to find interface with specified node ip` recovery). A shuffling
advertised address is not deterministic.

### Model C — Kubernetes LoadBalancer UDP Service

A genuine UDP-capable implementation exists locally (klipper-lb). Rejected on
measured mechanism: klipper-lb materialises **one container per Service port**,
so a 100-port media Service produces 100 containers per node. It also still
needs 100 enumerated ports and yields node-IP external addresses with the same
shuffle problem.

### Model D — Traefik UDP entry points or Gateway API UDPRoute

Rejected on pinned-version evidence: the `UDPRoute` CRD is not installed by the
SHA-pinned Gateway API v1.5.1 standard channel, Traefik's running entry points
are TCP-only, each media port would need its own entry point, and proxying an
ephemeral 100-port RTP range through an HTTP edge contradicts ADR-020's explicit
"RTP is never tunnelled through the HTTP/WSS 443 route".

### Model E — Dedicated media-edge node or host networking

The usual production answer for RTP and the only model that avoids enumerating
100 ports. **Rejected for the local projection on proven grounds**: PSA
`restricted:v1.35` on `utcp-platform` forbids both `hostNetwork` and `hostPort`,
demonstrated by server dry-run above. Adopting it would require weakening the
namespace PSA contract that T5-A78 established and re-proved. It is recorded as
the expected **future cloud projection** shape, where a dedicated media node pool
and a real cloud load balancer make it appropriate.

### Model F — Manual host forwarding

`socat`, `iptables`/`nftables` DNAT, or a custom host daemon. Rejected: the
repository establishes none of these as managed infrastructure, and all require
recurring manual commands or a second management plane, which
`CLAUDE.md` prohibits.

## Selected Canonical Architecture

**One canonical contract, two projections.**

### Canonical media-edge contract (provider-neutral)

```text
public media address    UTCP_PUBLIC_MEDIA_ADDRESS   (single declaration, versions.env)
public UDP range        RTPENGINE_MEDIA_PORT_MIN/MAX (existing; NOT redeclared)
internal media endpoint rtpengine Pod IP via downward API (unchanged)
runtime advertisement   --interface=runtime/${POD_IP}!${POD_IP}
browser advertisement   --interface=browser/${POD_IP}!${UTCP_PUBLIC_MEDIA_ADDRESS}
external readiness      proven reachability on the public address, not process health
default mode            internal-only when no public address is declared
```

### Authority table — no duplicated ownership

| Field | Canonical owner | Projected into |
|---|---|---|
| public media address | `versions.env: UTCP_PUBLIC_MEDIA_ADDRESS` | rtpengine browser interface advertised half, and the media-edge config check |
| public media port range | `versions.env: RTPENGINE_MEDIA_PORT_MIN/MAX` (existing) | rtpengine `--port-min/--port-max`, the NodePort Service ports, the k3d publication range, the NetworkPolicy range |
| internal rtpengine address | Kubernetes downward API `status.podIP` | `--listen-ng`, both `--interface` bind halves, runtime interface advertised half, `--listen-http` |
| host UDP publication | `infrastructure/k3d/cluster.yaml` | k3d/Docker port mapping |
| node→Pod forwarding | `rtpengine-media` NodePort Service | kube-proxy |
| NAT mapping | Docker publication + kube-proxy | none manual |
| external media readiness | media-edge readiness check | status output and the acceptance proof |

Every value has exactly one declaration. The range is never restated
independently in the Service, the k3d config, or a manual command — each is
generated or asserted against `versions.env`.

### Local k3d projection

```text
UTCP_PUBLIC_MEDIA_ADDRESS = 127.0.0.1        (mirrors the existing 127.0.0.1:443 edge)

infrastructure/k3d/cluster.yaml
  ports:
    - port: 127.0.0.1:40000-40099:40000-40099/udp
      nodeFilters: [server:0]
  options.k3s.extraArgs:
    - arg: --kube-apiserver-arg=service-node-port-range=30000-40099
      nodeFilters: [server:*]

Service/utcp-platform/rtpengine-media   type NodePort, 100 UDP entries,
                                        nodePort == port == targetPort == 40000..40099

Deployment/utcp-platform/rtpengine      nodeSelector pinning to the published node
                                        (kubernetes.io/hostname: k3d-utcp-local-server-0)

rtpengine entrypoint                    --interface=runtime/${POD_IP}!${POD_IP}
                                        --interface=browser/${POD_IP}!${UTCP_PUBLIC_MEDIA_ADDRESS}
```

`127.0.0.1` is chosen for the local projection only because the acceptance
browser runs on the host, exactly as it does for `https://app.utcp.local.test`.
It is a projected value of the canonical field, never a hard-coded universal
public media address.

Live T3-S3B evidence later disproved the earlier single-interface form
`internal/${POD_IP}!${UTCP_PUBLIC_MEDIA_ADDRESS}`: it advertised `127.0.0.1` to
the application runtime, so Asterisk returned RTP to its own loopback. The
replacement contract uses the two named interfaces above and Kamailio selects
the leg with rtpengine `direction=` parameters at the existing provider-neutral
offer/answer call sites.

### Future deployment projection

```text
UTCP_PUBLIC_MEDIA_ADDRESS = the cloud load-balancer or elastic IP
publication               = a cloud UDP load balancer or a dedicated media node pool
                            (Model E becomes appropriate where a media node pool
                             and its own PSA profile are justified)
```

The contract fields are identical; only the projection changes. No cloud-provider
integration is implemented in T3-S3.

## Node Placement And Restart Behavior

```text
placement       rtpengine is pinned by nodeSelector to the node carrying the k3d
                publication, so the published host port and the Pod always coincide
restart/move    the Pod restarts on the same pinned node; the advertised address is
                127.0.0.1 and is independent of Pod IP and node IP, so it survives
                Pod restarts, node-IP shuffles, and cluster stop/start
in-flight media does not survive a restart — unchanged from ADR-020 §Consequences
```

## Readiness And Failure Behavior

Readiness must prove **reachability**, not process health:

```text
media-edge readiness = the declared public address accepts UDP on the declared
                       range and the datagram reaches the rtpengine Pod
```

Required observable failures, each with no silent fallback to a private
candidate:

```text
public media binding unavailable   media-edge readiness fails; rtpengine does not
                                   advertise a private candidate instead
invalid advertised address         entrypoint rejects it exactly as it already
                                   rejects an invalid POD_IP (empty, 0.0.0.0,
                                   127.* is currently rejected for the BIND half —
                                   the advertised half needs its own validation)
media range collision              config check fails before deployment
external candidate unreachable     acceptance proof fails; no fallback
```

## Default-Deny Preservation

```text
public SIP/WSS            unchanged canonical Traefik 443 path
public RTP                exactly UDP 40000-40099, nothing else
rtpengine ng control      ClusterIP only, never published
rtpengine metrics         in-cluster only, never published
Asterisk RTP 10000-20000  in-cluster only
FreeSWITCH RTP 21000-21099 in-cluster only
ESL / ARI / AMI           never public
Pod CIDR                  no host route added
hostNetwork / hostPort    not used; PSA restricted:v1.35 preserved
```

Explicitly rejected in the implementation: broad all-UDP exposure, Pod-CIDR
exposure, public control or metrics ports, public runtime RTP ranges, public
Asterisk or FreeSWITCH SIP, direct browser-to-runtime media, unrestricted host
networking, silent internal-candidate fallback, manually enabled firewall rules,
runtime allowlists, and hidden environment opt-ins.

## External Media Acceptance Proof (future)

### Natural browser path
Real login through Playwright MCP from the host browser, canonical HTTPS and WSS
hostnames, no browser-state injection, application-returned tenant context.

### ICE and candidate proof
```text
candidate address equals UTCP_PUBLIC_MEDIA_ADDRESS
candidate port inside 40000-40099
candidate is not a Pod IP
candidate is not a Service ClusterIP
candidate is not an Asterisk or FreeSWITCH address
```

### Connectivity proof
Browser sends UDP media from outside the cluster network; traffic reaches
rtpengine; rtpengine forwards to the selected runtime; echo returns; browser
receives inbound RTP with `inbound-rtp.totalAudioEnergy` positive and increasing.

### Lifecycle proof
Browser-originated BYE and runtime-originated BYE each delete the media session;
ports return to zero; no stale NAT or forwarding state remains.

### Failure proof
Public-media binding unavailable, invalid advertised address, media-range
collision, and external candidate unreachable each fail observably with no
private-candidate fallback.

### Containment proof
Only UDP `40000-40099` is externally reachable; rtpengine control and metrics
remain private; Asterisk and FreeSWITCH ranges remain private; no public ESL,
ARI, or AMI; no public Pod-CIDR route.

## Static Validation Requirements

1. Public media range equals the rtpengine range from `versions.env`.
2. `UTCP_PUBLIC_MEDIA_ADDRESS` has exactly one declaration.
3. k3d published range and NodePort Service ports both equal the declared range.
4. No duplicate or drifting range declaration anywhere.
5. No public rtpengine control (`2223`) or metrics (`2224`) port.
6. No runtime RTP range (`10000-20000`, `21000-21099`) is published.
7. The advertised candidate is never a Pod IP, ClusterIP, or runtime address.
8. No broad UDP rule, no `0.0.0.0/0` ipBlock, no Pod-CIDR exposure.
9. rtpengine node placement is deterministic and matches the published node.
10. Default internal-only behaviour is represented and documented.
11. T3-S2 internal media contracts are unchanged.
12. Asterisk and FreeSWITCH parity checks still pass unmodified.
13. Invalid public-media configuration fails before deployment.
14. Media-edge readiness asserts reachability, not process health.
15. Cleanup removes proof-only resources and forwarding state.

## Mutation Coverage Requirements

Each must fail the check:

```text
public range widened beyond 40000-40099
public range narrower than the rtpengine range
range declared twice with different values
rtpengine ng 2223 published
rtpengine metrics 2224 published
Asterisk 10000-20000 published
FreeSWITCH 21000-21099 published
advertised address set to a Pod IP
advertised address set to a Service ClusterIP
advertised address left empty while publication is declared
advertised address set to 0.0.0.0
hostNetwork or hostPort introduced on rtpengine
NodePort Service missing any port in the range
NodePort nodePort != port != targetPort for any entry
nodeSelector removed while publication is node-pinned
k3d publication present without the apiserver range extension
readiness reduced to process liveness only
```

## Roadmap Slice Decision

Split into two bounded slices — there is a real implementation/proof boundary:
T3-S3A changes `cluster.yaml` and therefore requires cluster recreation, while
T3-S3B needs a different harness entirely (an **external host browser** rather
than the in-cluster prover used by T3-S2B/T3-S2C).

```text
T3-S3A  external-media authority and local projection
        versions.env public address field, rtpengine advertised-address
        projection, k3d publication, apiserver NodePort range, NodePort media
        Service, node pinning, static + mutation coverage, media-edge readiness

T3-S3B  external browser media proof and failure behaviour
        host-browser ICE/RTP acceptance proof, lifecycle proof, the four failure
        modes, and containment proof
```

T3-S3 remains separate from conference admission, external trunks, PSTN,
campaigns, agent state, and durable call control.

## Answers To The Required Decision Questions

1. **Public media address owner** — `versions.env: UTCP_PUBLIC_MEDIA_ADDRESS`.
2. **UDP 40000-40099 publication owner** — `infrastructure/k3d/cluster.yaml`
   (host side) and the `rtpengine-media` NodePort Service (node→Pod side), both
   asserted against `versions.env`.
3. **How external traffic reaches the Pod** — host `127.0.0.1:40000-40099/udp`
   → Docker publication → pinned k3d node container → kube-proxy NodePort →
   rtpengine Pod.
4. **How rtpengine advertises** — two named interfaces using the pinned
   `--interface=[NAME/]IP[!IP]` seam: `runtime/${POD_IP}!${POD_IP}` for the
   application-runtime-facing leg and
   `browser/${POD_IP}!${UTCP_PUBLIC_MEDIA_ADDRESS}` for the browser-facing leg.
5. **Node pinning** — yes, `nodeSelector` to the published node.
6. **Pod restart or move** — advertised address is independent of Pod and node
   IPs, so it survives; in-flight media does not, unchanged from ADR-020.
7. **External address validation** — entrypoint validation plus a media-edge
   config check; reachability is proven by the readiness check and T3-S3B.
8. **Readiness measurement** — a datagram on the declared public address must
   reach the Pod; process health alone is insufficient.
9. **Default-deny** — only the declared range is published; control, metrics, and
   both runtime ranges stay in-cluster; PSA `restricted` is preserved.
10. **No manual activation** — everything is declarative in `versions.env`,
    `cluster.yaml`, and the overlay; no Artisan or shell activation command.
11. **Default internal-only mode** — absent `UTCP_PUBLIC_MEDIA_ADDRESS`,
    rtpengine advertises the Pod IP exactly as today and nothing is published.
12. **Cloud mapping** — the same four contract fields; only the projection
    changes to a cloud UDP load balancer or dedicated media node pool.

## Consequences For ADR-020

ADR-020 must be extended, as it itself requires, to record: the advertised
address becoming a canonical declared value, host UDP range publication, the
NodePort media Service, node pinning, and the re-evaluation of its `hostNetwork`
and `NodePort` rejections on PSA evidence. Two guards need bounded amendment
with the precedent established in `t3-rtp-media-preparation-audit.md`:

```text
scripts/k3d/config-check:45   rejects rtp|rtpengine|NodePort in cluster.yaml
scripts/k3d/verify:106        fails on serverlb /udp, :400xx->, NodePort, rtpengine
```

## Verification Performed

```text
make repository-hygiene / workflow-check / secret-scan / k8s-config-check    pass
make security-config-check / -test                                          pass
make media-config-check / -test                                             pass
make kamailio-signaling-config-check / -test                                pass
make t3-media-prover-config-check / -test                                   pass
make freeswitch-config-check / -test                                        pass
make freeswitch-overlay-check / -test                                       pass
make check                                                                  pass
make gateway-config-check                                pass (pinned Helm v4.0.3, removed)
git diff --check / git diff --cached --check                                clean
kubectl apply                                                               none
```

## Status

```text
T3-S1 = Complete
T3-S2A = Complete
T3-S2B = Complete
T3-S2C = Complete
T3-S2 overall = Complete

T3-S3 = architecture selected and awaiting bounded implementation
T3-S3A = Not Started (next)
T3-S3B = Not Started

T3 = In Progress
UTCP_PHASE=T1
```

External browser media readiness is **not** claimed.

## Selected Next AI Coder

```text
Codex
```

Bounded implementation of T3-S3A against an explicit architecture, exact file
list, and pre-specified static and mutation coverage.
