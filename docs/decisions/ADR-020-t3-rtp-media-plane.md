# ADR-020 — T3 RTP Media-Plane Architecture (rtpengine)

- Status: Accepted
- Date: 2026-07-28
- Phase: T3 (rtpengine browser media) — preparation authority only; T3 is not implemented
- Supersedes: none
- Related: [ADR-019](ADR-019-kamailio-signaling-registration-authority.md) (Kamailio signaling/registration authority),
  [ADR-010](ADR-010-local-traefik-gateway-edge-ports.md) (local edge ports),
  [ADR-011](ADR-011-one-cluster-standard-local-edge.md) (one-cluster standard local edge),
  [ADR-018](ADR-018-asterisk-ari-adapter-boundary.md) (adapter boundary)
- Evidence: [`docs/evidence/t3/t3-rtp-media-preparation-audit.md`](../evidence/t3/t3-rtp-media-preparation-audit.md)

## Context

T1 (Kamailio SIP-over-WSS registration), T2 (Asterisk conference execution), and
T5 (convergence, failover, recovery) are complete. T3 is the next incomplete
phase. Its roadmap objective is real browser audio through a runtime-neutral
media path, anchored by rtpengine, plus the minimum Kamailio INVITE routing for
a registered browser identity to reach the selected Conference runtime.

The repository currently contains **no** rtpengine artefact of any kind: no ADR,
no version pin, no manifest, no image, no NetworkPolicy. Instead it contains
nine guard rules that actively reject RTP and rtpengine vocabulary, because
until now any such material would have been unauthorized. T3 therefore cannot
begin until this ADR establishes what "authorized RTP" means, so that guards can
be narrowed to a bounded allow rather than deleted.

Two existing precedents constrain the design decisively:

1. **Kamailio is shared platform infrastructure, not a managed runtime.** ADR-019
   and the T1 evidence record that no `RuntimeNode`, adapter key, or runtime
   family exists for Kamailio anywhere in the registry. rtpengine is the same
   class of component.
2. **The local edge publishes only `127.0.0.1:80` and `127.0.0.1:443`.**
   `infrastructure/k3d/cluster.yaml` maps exactly those two ports to the
   loadbalancer node filter, and ADR-010/ADR-011 fix that standard. No UDP is
   published to the host today.

## Decision

Introduce rtpengine as a **shared platform media-relay component in
`utcp-platform`**, mirroring the Kamailio pattern, under the decisions below.

### 1. Workload model — `Deployment`, `replicas: 1`

rtpengine is deployed as a single-replica `Deployment` in `utcp-platform`.

Reason: T3-S1 requires exactly one deterministically addressable relay. A
`Deployment` gives declarative rollout, standard probes, and ClusterIP
addressability with no node coupling, and it is the model every other
`utcp-platform` component already uses. Media sessions are ephemeral in-memory
state that must not survive restart, so no stable identity or storage is needed.

Rejected: **DaemonSet** — would create one relay per node on a three-node k3d
cluster with no selection authority to choose between them, inventing a
placement problem T3 does not have. **StatefulSet** — provides stable network
identity and persistent volumes, neither of which ephemeral media sessions use.
**Multi-replica Deployment** — rtpengine instances do not share session state;
scaling requires Kamailio-side selection, which is deferred to a later slice.

### 2. Networking model — in-cluster only, ClusterIP control, Pod-IP media

| Contract | Value |
|---|---|
| control protocol | rtpengine **ng** protocol (bencode) |
| control port | **UDP 2223**, ClusterIP only |
| RTP/RTCP port range | **UDP 40000–40099** (100 ports; 50 concurrent RTP+RTCP pairs) |
| bind interface | the Pod IP, injected via downward API `status.podIP` |
| advertised address | the same Pod IP (in-cluster only for T3-S1) |
| Service type | **ClusterIP** for the ng control port |
| publicly exposed ports | **none** |
| internal-only ports | 2223/UDP (ng), 40000–40099/UDP (media) |
| IPv4 scope | IPv4 only |
| NAT behaviour | none required in-cluster; Pod IP is directly routable within the cluster |

`40000–40099` is chosen deliberately: it avoids rtpengine's classic
`10000–20000` default (which five existing guards reject as public-media
vocabulary), sits above the Kubernetes NodePort range `30000–32767`, and is
small enough to be exhaustively assertable in a config check.

The HTTP/WSS application edge remains canonical `443` through Traefik. **RTP is
an explicit UDP media boundary and is never tunnelled through the HTTP/WSS 443
route.** No `UDPRoute`, `TCPRoute`, `TLSRoute`, NodePort, or LoadBalancer is
introduced, and `infrastructure/k3d/cluster.yaml` is unchanged.

Browser-reachable media requires publishing a host UDP range and is explicitly
**deferred** to a later T3 slice, gated on this ADR being extended.

### 3. Version and image authority — repository-built, digest-pinned

rtpengine is built by the repository, mirroring the Asterisk precedent
(`infrastructure/docker/asterisk/Dockerfile`, which pins its base by digest).

- Source authority: upstream `https://github.com/sipwise/rtpengine`
- License: GPL-3.0 — compatible with the repository's build-and-run use
- Base image: the same Debian family the repository already pins, recorded as a
  digest-pinned `ARG` exactly as Asterisk does
- Version variable: `RTPENGINE_VERSION` in `versions.env`, pinned to an exact
  upstream release tag; `RTPENGINE_BASE_IMAGE` pinned by digest
- `latest`, floating branches, and unpinned bases are prohibited

Because this audit runs without network access, the exact tag and digest are
resolved by a **deterministic first-slice process**, not left open: the
implementer resolves the current upstream stable release tag once, records the
tag plus its commit SHA in `versions.env`, records the base-image digest, and
builds from that exact tag. The version is therefore pinned by construction; only
its literal value is resolved at implementation time.

Hard capability constraints on the selected version: it must support the **ng
protocol** and **userspace-only forwarding** (`--table=-1`).

### 4. Forwarding mode — userspace only

rtpengine runs in userspace forwarding mode with the kernel table disabled
(`--table=-1`). The `xt_RTPENGINE` kernel module is **not** used.

This is the decision that keeps T3 inside Pod Security Admission `restricted`.
Kernel forwarding would require `NET_ADMIN`, a privileged container, and a host
kernel module — all prohibited by the current security posture and unnecessary
for local development throughput.

### 5. Kamailio integration boundary

```text
Kamailio  → SIP signaling and transaction authority
rtpengine → media relay authority
```

- rtpengine is invoked only for **application dialogs** (INVITE/200 OK/ACK/BYE).
  **REGISTER traffic is untouched**, preserving the T1 registrar contract intact.
- Initial scope is **SDP offer/answer mediation only** (`offer`, `answer`,
  `delete`), plus `ping` for control liveness.
- Permitted media flags are restricted to what WebRTC-to-RTP adaptation needs
  (ICE, DTLS-SRTP, RTP/AVP↔RTP/SAVPF transcoding of transport, not codecs).
- Session identity correlates through the SIP `Call-ID` plus the canonical
  `TelephonySession`/`Conference` identifiers already owned by the control plane.
- rtpengine never becomes authoritative for signaling, desired state, tenant
  access, or application workflow.
- Kamailio consumes a **configured target set** for T3-S1 — a single ClusterIP
  control endpoint. Kamailio does **not** implement its own placement logic; when
  multiple relays are later introduced, selection must consume the canonical
  RuntimeNode eligibility projection, per the T3 roadmap clause.
- When no eligible rtpengine instance exists, new media setup **fails visibly**
  with an explicit SIP failure; existing state authority is preserved and no
  silent bypass to direct Asterisk media is permitted.

T3-S1 introduces **no** Kamailio production media routing. The relay foundation
stands alone and is provable by control-plane liveness without live SIP media.

### 6. State authority

```text
PostgreSQL → durable desired/configuration authority
Kamailio   → SIP signaling and transaction authority
rtpengine  → active media-relay session authority (in-memory, ephemeral)
Reverb     → notification/invalidation only
```

**T3 introduces no new durable tables.** rtpengine is shared platform
infrastructure, so — exactly as with Kamailio — no `RuntimeNode`, adapter key,
runtime family, or registry capability is created for it. Media-session state is
ephemeral by design and is not projected into canonical storage in T3-S1.

### 7. Lifecycle and failure contract

| Aspect | Behaviour |
|---|---|
| startup | container starts rtpengine in foreground, userspace mode, bound to the Pod IP |
| readiness | ng control `ping` succeeds on 2223/UDP |
| liveness | same ng `ping`, longer period |
| shutdown | `SIGTERM`; in-flight media sessions are dropped |
| pod replacement | new Pod, new Pod IP, empty session table |
| session loss on restart | **accepted and explicit** — all active media sessions are lost |
| stale media cleanup | rtpengine's own session timeout, plus explicit `delete` on BYE once signaling is wired |
| failure reporting | control failures surface as explicit SIP failure responses and metrics/alerts |
| reconciliation | none required; there is no durable media desired state to converge |
| active media migration | **not supported and not claimed** |

### 8. Security contract

| Control | Value |
|---|---|
| runAsNonRoot | `true` |
| runAsUser / runAsGroup | `1000` / `1000` |
| allowPrivilegeEscalation | `false` |
| capabilities | `drop: [ALL]`, none added |
| readOnlyRootFilesystem | `true`, with an `emptyDir` for any runtime scratch |
| seccompProfile | `RuntimeDefault` |
| hostNetwork / hostPID / hostPort / hostPath | **prohibited** |
| privileged | **prohibited** |
| PSA | fully compatible with `restricted:v1.35`; **no exception required** |
| secrets | none — rtpengine holds no credential in T3-S1 |
| log redaction | no SDP bodies, IP-to-identity mappings, or SRTP keying material in logs |

No new namespace is created; rtpengine lives in `utcp-platform` beside Kamailio.

### 9. NetworkPolicy contract

Default-deny is preserved. One new policy, `allow-rtpengine-media`, grants:

- **Ingress** to 2223/UDP from the Kamailio pod selector only.
- **Ingress** to 40000–40099/UDP from in-cluster media peers only.
- **Ingress** to the metrics port from the observability scrape source only.
- **Egress** to 40000–40099/UDP for return media, plus cluster DNS.
- **No** Kubernetes API egress. **No** PostgreSQL or Redis access.

### 10. Observability contract

- Health: ng `ping` for readiness and liveness.
- Metrics: rtpengine's Prometheus exporter on an internal-only port, scraped
  through the established internal path — never a public surface, consistent
  with the T5-A62 metrics-security cutoff.
- Required counters: active media sessions, allocation failures, control request
  failures, packet/error counters.
- Alerts: relay unavailable; sustained control-request failure; port-range
  exhaustion (no-capacity).

## Rejected Alternatives

- **DaemonSet / StatefulSet / multi-replica** — see §1.
- **Kernel-module forwarding (`xt_RTPENGINE`)** — needs `NET_ADMIN`, privileged
  execution, and a host kernel module; breaks PSA `restricted` for throughput
  that local development does not need.
- **hostNetwork with node-IP advertisement** — the usual production answer for
  RTP, but it defeats PSA `restricted`, contradicts the guard set, and is
  unnecessary while media stays in-cluster.
- **NodePort or LoadBalancer for media** — rejected by existing guards and by
  ADR-011's one-cluster standard edge; also unnecessary for T3-S1.
- **Routing RTP through Traefik on 443** — Traefik terminates HTTP/WSS; there is
  no repository evidence supporting RTP over that route, and `UDPRoute` is
  explicitly rejected by `scripts/gateway/config-check`.
- **Classic `10000–20000` media range** — rejected by five existing guards as
  public-media vocabulary; a bounded high range is equally functional.
- **Third-party prebuilt rtpengine image** — no upstream image carries the
  provenance guarantee the repository's digest-pinning policy requires.
- **Modelling rtpengine as a `RuntimeNode`** — contradicts ADR-019's precedent
  that shared signaling/media infrastructure is not a managed execution runtime.

## Consequences

Positive: T3 becomes implementable behind a bounded, guard-enforced contract;
PSA `restricted` holds with no exception; the HTTP edge stays untouched; no new
durable state or namespace appears; vendor neutrality is preserved because no
media vocabulary enters the domain layer.

Negative and accepted: media sessions do not survive a Pod restart; a single
replica is a media single point of failure in T3-S1; browser-reachable media
needs a later slice that publishes a host UDP range and extends this ADR;
userspace forwarding caps throughput well below kernel forwarding.

## Deferred to V0 / T4

Browser SIP and conference admission; host UDP publication for
browser-reachable media; Kamailio INVITE route authority consuming the canonical
RuntimeNode eligibility projection; multi-relay selection and failover;
Record-Route/in-dialog handling; FreeSWITCH parity; external trunks and PSTN;
active-call migration, which remains explicitly unsupported.

## T3-S3A amendment — local external-media projection

The T3-S3A implementation extends this decision with one provider-neutral
external-media contract. `UTCP_PUBLIC_MEDIA_ADDRESS` in `versions.env` is the
sole advertised-address authority, while `RTPENGINE_MEDIA_PORT_MIN` and
`RTPENGINE_MEDIA_PORT_MAX` remain the sole media-range authority. The default
`overlays/local` projection does not consume the public address and remains
internal-only.

The bounded local media-edge projection consumes the address `127.0.0.1`,
publishes UDP `40000-40099` through a dedicated k3d profile and a 100-entry
`rtpengine-media` NodePort Service with `externalTrafficPolicy: Local`, and
pins rtpengine to the k3d server labelled `utcp.dev/media-edge=true`.

The original T3-S3A projection assumed that changing the advertised half of a
single rtpengine interface was sufficient. Live T3-S3B evidence disproved that
assumption: the public advertised address was applied to the application-runtime
leg as well as the browser leg, causing Asterisk to send RTP to its own
loopback. T3-S3B therefore replaces the single-interface assumption with two
named logical interfaces on the same Pod-IP bind model:

```text
runtime/${POD_IP}!${POD_IP}
browser/${POD_IP}!${browser_advertised_address}
```

Kamailio remains the sole ng-control caller and selects the media leg using
`direction=runtime direction=browser` or
`direction=browser direction=runtime` at the four existing offer/answer call
sites. No control, metrics, SIP, runtime RTP, ESL, ARI, or AMI port is
projected.

This is a projection contract, not an external reachability proof. Cluster
recreation and real host-browser UDP validation are deferred to T3-S3B. A
future cloud projection may replace the local host publication with a cloud
UDP load balancer or dedicated media node pool while retaining the same
address and range authorities.
