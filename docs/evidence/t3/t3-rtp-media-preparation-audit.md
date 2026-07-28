# T3 — RTP/Media Architecture Authority and First-Slice Audit

Verdict: `T3_RTP_MEDIA_PREPARATION_AUDIT_COMPLETE`

Narrow evidence-only architecture audit. No production code, Kubernetes
manifest, dependency, runtime configuration, or phase marker was changed; no
image was built; no workload was restarted; no live media proof was run. **T3
remains not implemented.**

Architecture authority: [`docs/decisions/ADR-020-t3-rtp-media-plane.md`](../../decisions/ADR-020-t3-rtp-media-plane.md).

## Source Commit

- Audit performed at `e33a240` (`docs(t5): close resilient platform phase`).
- Branch `main`, working tree clean, `UTCP_PHASE=T1`, ahead of `origin/main`, nothing pushed.
- Preceding closures: [`../t5/t5-phase-closure.md`](../t5/t5-phase-closure.md) (`T5_COMPLETE`),
  [`../roadmap/t1-t5-roadmap-reconciliation.md`](../roadmap/t1-t5-roadmap-reconciliation.md).

## Canonical T3 Contract

Extracted from `docs/roadmap/implementation-roadmap.md` §"T3 - rtpengine Browser
Media — Planned". Numbered canonically; nothing from V0, T4, external trunks,
PSTN, or active-call migration was imported.

| # | T3 requirement | Class |
|---|---|---|
| 1 | Real browser audio through a runtime-neutral media path | Objective |
| 2 | SDP offer/answer mediation | Objective |
| 3 | ICE handling | Objective |
| 4 | DTLS-SRTP | Objective |
| 5 | WebRTC-to-RTP adaptation | Objective |
| 6 | Media anchoring through rtpengine | Objective + exit |
| 7 | Media-session correlation with signaling | Objective + exit |
| 8 | Timeout / cleanup of failed media sessions | Objective + exit |
| 9 | Media health observation | Objective |
| 10 | Explicit RTP-related NetworkPolicies | Objective |
| 11 | Metrics and logs | Objective |
| 12 | Initial Kamailio INVITE route authority for the browser/conference path | Objective + exit |
| 13 | Route authority consumes the canonical RuntimeNode eligibility projection; placement is **not** redefined in Kamailio | Constraint |
| 14 | Ineligible execution RuntimeNode excluded from **new** application-dialog routing | Constraint |
| 15 | Registration eligibility remains the separate C5/T1 `TelephonySession` credential authority | Boundary |
| 16 | Existing-dialog behaviour, Record-Route / in-dialog routing where required | Exit |
| 17 | Automatic cutoff and restoration | Exit |
| 18 | Absence of direct Asterisk bypass on the internal browser/conference path | Exit |

Relationship to adjacent phases: **V0** consumes T3's registered-browser-to-
conference path and is gated behind it. **T4** reproduces V0 against FreeSWITCH
and is gated behind T3 and V0. Neither contributes T3 exit criteria.

## Current RTP/Media Repository State

The repository contains **no rtpengine artefact of any kind**:

| Surface | Finding |
|---|---|
| `docs/decisions/` | No media ADR; highest was ADR-019 (Kamailio). ADR-020 now fills this gap |
| `versions.env` | No `RTPENGINE_*` variable; no RTP port variable |
| `infrastructure/kubernetes/` | No rtpengine manifest anywhere |
| `infrastructure/docker/` | No rtpengine image build |
| `Makefile` | No media target |
| `.github/workflows/` | No media job |
| `docs/runbooks/` | No media runbook |
| `infrastructure/k3d/cluster.yaml` | Publishes **only** `127.0.0.1:80:80` and `127.0.0.1:443:443` to the `loadbalancer` node filter. **No UDP is published to the host** |

Conventions the first slice must follow, established elsewhere:

- **Namespaces**: `utcp-platform`, `utcp-data`, `utcp-runtime`, `utcp-observability`, `traefik-system`. Kamailio (shared platform signaling) lives in `utcp-platform`; Asterisk (execution runtime) lives in `utcp-runtime`.
- **Image build**: repository-built with a digest-pinned base — `infrastructure/docker/asterisk/Dockerfile` pins `andrius/asterisk:20@sha256:a27dae75…`. Kamailio pins an exact upstream tag in `versions.env` (`KAMAILIO_IMAGE=ghcr.io/kamailio/kamailio:5.8.6-bookworm`, `KAMAILIO_VERSION=5.8.6`).
- **Pod security**: pod-level `runAsNonRoot`, `runAsUser/Group: 1000`, `seccompProfile: RuntimeDefault`; container-level `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]` (Kamailio deployment lines 24-29, 80-85).
- **Probes/resources**: `tcpSocket` readiness at 5 s and liveness at 15 s, with explicit CPU/memory requests and limits.
- **PSA**: all five UTCP namespaces enforce/audit/warn `restricted:v1.35`, re-validated at HEAD (`namespace_psa_authority=ok`, `restricted_workload_compatibility=ok`).
- **Registry**: `utcp-local-registry:5000` in-cluster, `127.0.0.1:5001` from the host, tag `K1_IMAGE_TAG=0.1.0-k1-dev`.

## Existing Guard Disposition

Nine guard rules currently reject RTP/rtpengine vocabulary. Each is classified;
none is deleted to make manifests pass.

| # | Guard | Current rule | Disposition | Exact new rule |
|---|---|---|---|---|
| G1 | `scripts/kubernetes/static-check:26` | rejects `freeswitch\|rtpengine\|confbridge\|(sips\|rtp\|srtp\|pbx\|pjsip)\|hostNetwork\|hostPID\|hostPort\|privileged\|hostPath` in any rendered manifest | **REPLACE_WITH_BOUNDED_ALLOW** | Drop only `rtpengine` and the standalone `rtp\|srtp` alternatives; retain `freeswitch\|confbridge\|sips\|pbx\|pjsip` and every host/privileged term. Add an rtpengine stanza mirroring the existing Kamailio stanza (lines 36-45): if `rtpengine` appears it **must** carry `app.kubernetes.io/component: rtpengine`, expose only the named ports `ng` and `media`, and declare no `sip`/`sips` port name |
| G2 | `scripts/kubernetes/static-check:31` | rejects `port/containerPort: 5060\|5061\|10000\|20000` and `name: sip\|sips\|rtp` | **RETAIN unchanged** | Satisfied by construction: control port `2223`, media range `40000–40099`, port names `ng` and `media` |
| G3 | `scripts/security/config-check:426` | rejects `18080\|18443\|5060\|5061\|10000\|20000\|sips\|rtp\|rtpengine\|freeswitch` in the rendered **security** kustomization | **REPLACE_WITH_BOUNDED_ALLOW** | Drop `rtp\|rtpengine` only. Add: if `rtpengine` appears, the exact policy `name: allow-rtpengine-media` must exist (mirroring the existing `allow-kamailio-signaling-required-traffic` stanza at lines 431-436), and the manifest must contain no `10000\|20000` and no LoadBalancer/NodePort |
| G4 | `scripts/security/config-check` required-policy list (lines ~410-420) | enumerates required K3 NetworkPolicies | **EXTEND** | Add `allow-rtpengine-media` to the required list so the policy cannot be silently dropped |
| G5 | `scripts/gateway/config-check:61` | rejects `events.utcp.local.test\|TCPRoute\|TLSRoute\|UDPRoute\|asterisk\|freeswitch\|rtpengine\|hostNetwork\|hostPath\|privileged\|nodePort` in the rendered **Gateway/Traefik** config | **RETAIN unchanged** | This guard is exactly the architecture: RTP must never reach the HTTP/WSS edge. Keeping it is what proves media is not tunnelled through 443 |
| G6 | `scripts/k3d/config-check:45` | rejects `rtp\|sip\|rtpengine\|NodePort` and custom ports in the k3d cluster config | **RETAIN unchanged** | T3-S1 is in-cluster only and publishes no host UDP, so `infrastructure/k3d/cluster.yaml` is untouched. Host UDP publication is a later slice that must extend ADR-020 and this guard together |
| G7 | `scripts/k3d/verify:73` | fails if any running Pod/workload matches `rtpengine\|sip\|rtp\|…` | **REPLACE_WITH_BOUNDED_ALLOW** | Permit exactly one `rtpengine` workload in `utcp-platform` carrying `app.kubernetes.io/component: rtpengine`; continue rejecting every other match |
| G8 | `scripts/asterisk-ari/config-check:103` and `scripts/asterisk-conference/config-check:236` | reject `rtp\|rtpengine\|media` inside the Asterisk adapter, Asterisk image, and `base/runtime` | **RETAIN unchanged** | Media must not leak into the Asterisk adapter or execution-runtime manifests; rtpengine lives in `base/platform` |
| G9 | `scripts/control-plane/config-check:41,48` and `scripts/runtime-registry/config-check:64` | reject `rtpengine` in the control-plane kernel and reject registry capabilities matching `rtp.\|media.` | **RETAIN unchanged** | Vendor neutrality: rtpengine is shared platform infrastructure, so it gets no `RuntimeNode`, adapter key, runtime family, or registry capability — the same rule ADR-019 applies to Kamailio |

`scripts/asterisk-ari/config-check:146-147` (which forbid
`NodePort|LoadBalancer|hostPort|hostNetwork|hostPID|5060|5061|10000|20000` in
`base/platform` and `base/runtime`) need **no change**: the selected ports and
Service type contain none of those tokens.

The new bounded contract must still reject: unpinned or `latest` images;
unbounded UDP ranges; a publicly exposed control port; unapproved `hostPort`,
`hostNetwork`, or `hostPath`; LoadBalancer or NodePort control surface; a
missing NetworkPolicy; privileged execution; missing resource requests/limits;
missing probes; missing security context; and RTP exposure outside the selected
media boundary.

## Selected Architecture

Full rationale in ADR-020; the operative decisions are:

- **Workload model**: `Deployment`, `replicas: 1`, in `utcp-platform`.
- **Networking**: in-cluster only. ng control on **UDP 2223** via ClusterIP; RTP/RTCP on **UDP 40000–40099** bound and advertised on the Pod IP (downward API `status.podIP`). No public port, no NodePort, no LoadBalancer, no Gateway route, no k3d change.
- **Forwarding**: userspace only (`--table=-1`) — the decision that keeps PSA `restricted` with **no exception**.
- **Version**: `RTPENGINE_VERSION` + digest-pinned `RTPENGINE_BASE_IMAGE` in `versions.env`, built from upstream `sipwise/rtpengine` (GPL-3.0). Because this audit has no network access, the first slice resolves the exact stable tag and base digest once and records them; the version is pinned by construction, never `latest`.
- **Kamailio boundary**: signaling authority stays with Kamailio, media relay with rtpengine; REGISTER untouched; offer/answer/delete/ping only; **no production media routing in T3-S1**.
- **State**: no new durable tables, no `RuntimeNode`, no registry capability.
- **Security**: non-root 1000/1000, `drop: [ALL]`, `readOnlyRootFilesystem: true`, `RuntimeDefault`, no host namespaces, PSA `restricted:v1.35` compatible.
- **NetworkPolicy**: one new `allow-rtpengine-media`; default-deny preserved; no Kubernetes API, PostgreSQL, or Redis egress.
- **Observability**: ng `ping` probes; internal-only Prometheus metrics consistent with the T5-A62 metrics-security cutoff; alerts for relay-unavailable, control-failure, and port-range exhaustion.

## Exact Kubernetes Resource Inventory

| Resource | Namespace | Name | Purpose | Selector / ports | Owner file | In slice |
|---|---|---|---|---|---|---|
| ConfigMap | `utcp-platform` | `rtpengine-config` | rtpengine runtime configuration | — | `infrastructure/kubernetes/base/platform/rtpengine-configmap.yaml` | **yes** |
| Deployment | `utcp-platform` | `rtpengine` | single userspace media relay | `app.kubernetes.io/component: rtpengine`; `ng` 2223/UDP, `media` 40000–40099/UDP, `metrics` internal TCP | `infrastructure/kubernetes/base/platform/rtpengine-deployment.yaml` | **yes** |
| Service (ClusterIP) | `utcp-platform` | `rtpengine` | ng control endpoint for Kamailio | selects the Deployment; `ng` 2223/UDP | `infrastructure/kubernetes/base/platform/rtpengine-service.yaml` | **yes** |
| NetworkPolicy | `utcp-platform` | `allow-rtpengine-media` | bounded ingress/egress for control, media, metrics, DNS | component selector | `infrastructure/kubernetes/security/platform/allow-rtpengine-media.yaml` | **yes** |
| PrometheusRule | `utcp-observability` | `utcp-rtpengine-rules` | relay-unavailable, control-failure, port-exhaustion alerts | — | observability overlay | no — second slice |
| Media-facing Service / host UDP publication | — | — | browser-reachable media | — | — | no — deferred, requires an ADR-020 extension |
| PodDisruptionBudget | — | — | not justified at `replicas: 1` | — | — | no |

No speculative resource is added.

## Selected First Implementation Slice — `T3-S1`

**Pinned rtpengine media-plane foundation: control and media networking,
security and NetworkPolicy, health and metrics foundation, repository guards —
with no live SIP media routing.**

This boundary is valid because the relay is independently provable: ng `ping`
over the ClusterIP control port demonstrates a ready, reachable, policy-scoped
relay without any SIP dialog, browser, or conference. No repository evidence
suggests a standalone relay foundation would be invalid or untestable.

### Exact production files

```text
versions.env                                                        (add RTPENGINE_VERSION, RTPENGINE_BASE_IMAGE, RTPENGINE_NG_PORT=2223, RTPENGINE_MEDIA_PORT_MIN=40000, RTPENGINE_MEDIA_PORT_MAX=40099)
infrastructure/docker/rtpengine/Dockerfile                          (new; digest-pinned base, build from the pinned upstream tag, USER 1000:1000)
infrastructure/docker/rtpengine/entrypoint                          (new; userspace mode, --table=-1, bind/advertise $POD_IP)
infrastructure/docker/rtpengine/readiness                           (new; ng ping)
infrastructure/kubernetes/base/platform/rtpengine-configmap.yaml    (new)
infrastructure/kubernetes/base/platform/rtpengine-deployment.yaml   (new)
infrastructure/kubernetes/base/platform/rtpengine-service.yaml      (new)
infrastructure/kubernetes/base/platform/kustomization.yaml          (register the three new resources)
infrastructure/kubernetes/security/platform/allow-rtpengine-media.yaml (new)
infrastructure/kubernetes/security/kustomization.yaml               (register the new policy)
scripts/kubernetes/static-check                                     (G1 bounded allow)
scripts/security/config-check                                       (G3 bounded allow + G4 required-policy extension)
scripts/k3d/verify                                                  (G7 bounded allow)
scripts/media/config-check                                          (new; deterministic T3 media contract check)
scripts/kubernetes/image-build                                      (build the rtpengine image)
scripts/kubernetes/image-push                                       (push the rtpengine image)
Makefile                                                            (add media-config-check; wire into the existing check aggregate)
```

### Exact test files

```text
scripts/media/config-check          asserts: version pinned and not latest; base image digest-pinned;
                                    ng port == 2223; media range == 40000-40099 and bounded;
                                    Service type == ClusterIP; no NodePort/LoadBalancer/hostNetwork/
                                    hostPort/hostPath/privileged; runAsNonRoot + drop ALL +
                                    readOnlyRootFilesystem + RuntimeDefault present; resource
                                    requests and limits present; readiness and liveness probes present;
                                    allow-rtpengine-media exists; no Kubernetes API / PostgreSQL /
                                    Redis egress; no rtpengine token in the Gateway/Traefik render;
                                    no RuntimeNode, adapter key, runtime family, or registry
                                    capability for rtpengine
apps/api/tests/…                    none — T3-S1 adds no application code
```

### Exact verification commands

```bash
make repository-hygiene
make workflow-check
make secret-scan
make k8s-config-check
make security-config-check
make gateway-config-check
make media-config-check
make check
git diff --check
```

### Exact completion criteria

1. `RTPENGINE_VERSION` and a digest-pinned `RTPENGINE_BASE_IMAGE` are recorded in `versions.env`; neither is `latest` or a floating branch.
2. The rtpengine image builds reproducibly from the pinned tag and runs as UID/GID 1000.
3. The Deployment renders with `replicas: 1` in `utcp-platform`, component label `rtpengine`, named ports `ng` and `media` only.
4. ng control is ClusterIP `2223/UDP`; media is `40000–40099/UDP` bound and advertised on `status.podIP`.
5. Pod and container security contexts satisfy PSA `restricted:v1.35` with **no** exception, and `make security-config-check` still reports `namespace_psa_authority=ok` and `restricted_workload_compatibility=ok`.
6. `allow-rtpengine-media` exists, default-deny is preserved, and no Kubernetes API, PostgreSQL, or Redis egress is granted.
7. Readiness and liveness probes use ng `ping`; explicit resource requests and limits are set.
8. Guards G1, G3, G4, G7 are narrowed to the bounded allow above; G2, G5, G6, G8, G9 remain unchanged and still pass.
9. `infrastructure/k3d/cluster.yaml` is unchanged and no host UDP is published.
10. No `RuntimeNode`, adapter key, runtime family, or registry capability is created for rtpengine; no new durable table is added.
11. No Kamailio configuration change and no SIP media routing.
12. Every command in the list above passes.
13. `UTCP_PHASE=T1` unchanged; T3 remains not complete.

### Explicit exclusions

Live SIP media routing; Kamailio production configuration changes; browser SIP;
conference admission; V0; Asterisk or FreeSWITCH integration; external trunks
and PSTN; T4; host UDP publication and browser-reachable media; kernel-module
forwarding; multi-relay selection or failover; new durable tables; a new
namespace; any change to `UTCP_PHASE`.

### Expected commit message

```text
feat(t3): add pinned rtpengine media-plane foundation
```

### Recommended next actor

**Codex** — bounded repository implementation against a fully specified seam.

## Operator Requirements

```text
None.
```

Every T3-S1 value is derived deterministically from repository architecture: the
namespace from the Kamailio precedent, the ports from the guard set, the
security context from the Kamailio deployment, the image policy from the
Asterisk Dockerfile, and the addressing from the existing in-cluster model. A
production public media IP, cloud load-balancer allocation, or an externally
mandated UDP range would be genuine operator inputs — none is required for
T3-S1, which is in-cluster only.

## Verification Performed During This Audit

`make repository-hygiene`, `make workflow-check`, `make secret-scan`,
`make security-config-check`, `make gateway-config-check`, `git diff --check`,
`git diff --cached --check` — all passed.

```text
production code changed:       no
Kubernetes manifests changed:  no
dependencies changed:          no
versions.env changed:          no
runtime configuration changed: no
workloads restarted:           no
images built:                  no
live media proof run:          no
```

## Next Coder

```text
Codex — BOUNDED_IMPLEMENTATION of T3-S1
```
