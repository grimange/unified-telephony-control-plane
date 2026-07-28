# T3-S1 — Pinned rtpengine Media-Plane Foundation Implementation

Verdict: `T3_S1_RTPENGINE_FOUNDATION_IMPLEMENTATION_COMPLETE`

Repository implementation of the bounded T3-S1 relay foundation. No Kubernetes
apply was run, no image was built or deployed, no Playwright proof was run, and
no production SIP media routing was enabled. T3 remains In Progress pending
focused live relay proof.

## Source Authority

- ADR: [`docs/decisions/ADR-020-t3-rtp-media-plane.md`](../../decisions/ADR-020-t3-rtp-media-plane.md)
- Preparation evidence: [`docs/evidence/t3/t3-rtp-media-preparation-audit.md`](t3-rtp-media-preparation-audit.md)
- Starting commit: `f286022` (`docs(t3): define RTP media-plane architecture`)
- Phase marker retained: `UTCP_PHASE=T1`

## Selected Upstream Version

- Upstream project: `sipwise/rtpengine`
- Stable release: `mr26.0.1.19`
- Upstream tag commit: `3552ac76cceb24e3ec176b77ec9c25554ae5923b`
- Resolution date: 2026-07-28
- Source authority: official GitHub release page and tag resolution.
- Recorded in `versions.env` as `RTPENGINE_VERSION` and
  `RTPENGINE_SOURCE_COMMIT`.

The pinned release is not `latest`, `master`, `main`, or a floating branch.

## Base Image Digest and Build Provenance

- Base image: `debian:trixie-slim@sha256:020c0d20b9880058cbe785a9db107156c3c75c2ac944a6aa7ab59f2add76a7bd`
- Provenance: Docker official Debian `trixie-slim` image index inspected
  through Docker Buildx on 2026-07-28.
- The repository-built image downloads official release `.deb` assets for
  `amd64` and `arm64`, validates their SHA-256 checksums, installs only the
  runtime package and probe dependencies, and runs as UID/GID `1000:1000`.
- No credentials, secrets, floating package source, or third-party runtime
  image authority is embedded.

## Workload and Networking Resources

T3-S1 adds these resources in `utcp-platform`:

| Resource | Name | Contract |
|---|---|---|
| ConfigMap | `rtpengine-config` | Canonical ports: ng `2223`, media `40000-40099`, metrics `2224` |
| Deployment | `rtpengine` | `replicas: 1`, userspace relay, component label `rtpengine` |
| Service | `rtpengine` | `ClusterIP`, UDP `2223`, internal ng control only |
| NetworkPolicy | `allow-rtpengine-media` | Bounded control, media, and internal metrics paths |

The Deployment binds and advertises the Pod IP supplied by downward API
`status.podIP`. The entrypoint rejects absent, malformed, loopback, link-local,
or `0.0.0.0` Pod IP values instead of substituting another address.

No NodePort, LoadBalancer, Gateway route, Ingress, HostPort, HostNetwork, k3d
UDP publication, or new namespace was added. `infrastructure/k3d/cluster.yaml`
is unchanged.

## Runtime Configuration

- Forwarding mode: userspace via `--table=-1`.
- Control protocol: rtpengine ng over UDP.
- Control port: `2223`.
- Media range: UDP `40000-40099`.
- Bind address: `POD_IP` from `status.podIP`.
- Advertised address: `POD_IP` from `status.podIP`.

The ConfigMap, entrypoint validation, Deployment ports, Service, NetworkPolicy,
and `scripts/media/config-check` all assert the same canonical values.

## Security Context and PSA Compatibility

The Pod and container are restricted-compatible:

- `runAsNonRoot: true`
- `runAsUser: 1000`
- `runAsGroup: 1000`
- `allowPrivilegeEscalation: false`
- `readOnlyRootFilesystem: true`
- `capabilities.drop: [ALL]`
- `seccompProfile.type: RuntimeDefault`
- `automountServiceAccountToken: false`
- explicit CPU and memory requests and limits
- bounded writable `emptyDir` mounts for temporary runtime paths only

No privileged execution, added Linux capability, host namespace, host mount, API
credential, or Pod Security Admission exception was introduced.

## Probes and Metrics Foundation

Readiness and liveness both use the repository helper
`/usr/local/bin/utcp-rtpengine-ng-ping`, which sends a real ng `ping` over UDP
to the local Pod process and requires a response containing `result=pong`.

Internal metrics are enabled on the Pod IP at TCP `2224` and remain reachable
only from the existing observability source selected by NetworkPolicy. This
establishes the repository foundation for process readiness, ng control
responsiveness, liveness, control-error/media-error exposure where supported by
upstream rtpengine, and Pod restart visibility. Alerting resources remain
deferred to the live-proof/follow-up slice identified by the preparation audit.

## NetworkPolicy

`allow-rtpengine-media` preserves default-deny and allows only:

- Kamailio signaling Pods in `utcp-platform` to reach UDP `2223`.
- Kamailio signaling Pods and Asterisk ARI Pods to exchange UDP `40000-40099`
  media with rtpengine inside the cluster.
- Prometheus in `utcp-observability` to scrape internal TCP `2224` metrics.

Denied by omission:

- Kubernetes API egress
- PostgreSQL egress
- Redis egress
- public rtpengine control access
- arbitrary namespace-wide ingress
- unbounded UDP ingress
- unrestricted egress

## Guard Replacements

- G1 (`scripts/kubernetes/static-check`) now permits only the selected
  single-replica `utcp-platform` rtpengine Deployment and internal ClusterIP
  Service, while rejecting unsafe namespace, replica, exposure, port, image,
  probe, resource, and security variants.
- G3/G4 (`scripts/security/config-check`) now require the exact
  `allow-rtpengine-media` policy and validate the bounded security, control,
  media, and metrics contract.
- G7 (`scripts/k3d/verify`) now allows only the expected in-cluster rtpengine
  workload and rejects rtpengine HostPort, NodePort, LoadBalancer, or k3d UDP
  publication.

## Retained Guards

G2, G5, G6, G8, and G9 remain effective:

- RTP does not traverse Gateway API or Traefik HTTP/WSS routing.
- k3d still exposes only HTTP/HTTPS host ports.
- Asterisk adapter authority is unchanged.
- Control-plane and runtime-registry vendor-neutrality guards still reject a
  RuntimeNode capability or durable application authority for rtpengine.

## Static Verification

Added `scripts/media/config-check` and `make media-config-check`. The check is
wired into the canonical `make check` lifecycle and verifies:

- exact version pin and immutable base digest
- Deployment, Service, ConfigMap, Dockerfile, entrypoint, and probe helper
- userspace forwarding and exact canonical ports
- Pod IP downward API ownership
- restricted-compatible security context and resources
- ng ping readiness/liveness
- ClusterIP-only control Service
- bounded NetworkPolicy and absence of API/PostgreSQL/Redis egress
- no Gateway RTP route or public control surface
- no k3d UDP publication
- no Kamailio production media routing
- no new durable application media authority

## Explicit Absence of Live SIP Routing

T3-S1 does not add `rtpengine_offer`, `rtpengine_answer`,
`rtpengine_manage`, `rtpengine_delete`, SDP rewriting, dialog routing, browser
media routing, RuntimeNode capability changes, database migrations, web-admin
settings, or manual activation. SIP `REGISTER` remains the T1 registration-only
contract.

## Deferred Live Proof

Required remaining proof is limited to focused Claude Code live proof of image
build, Kubernetes rollout, Pod readiness/liveness, ng ping, restricted PSA
compatibility, NetworkPolicy, internal metrics, relay-unavailable failure,
restoration, and environment preservation; no production SIP media routing.
