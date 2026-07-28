# T3-S1 — rtpengine Foundation Live Proof

Verdict: `T3_S1_RTPENGINE_FOUNDATION_LIVE_PROOF_INCOMPLETE`

The live proof is blocked by one exact, reproducible defect in `b8cd960`: the
pinned rtpengine release asset filename does not exist upstream, so the image
cannot be built from the pinned source. Per the proof contract — *"Fail the
proof when the pinned source cannot be reproduced. Do not substitute another
version or base image during live proof."* — no substitution was attempted and
no production code was modified.

**T3-S1 remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

- Proof attempted at `b8cd960` (`feat(t3): add pinned rtpengine media-plane foundation`).
- Branch `main`, working tree clean, `UTCP_PHASE=T1`, nothing pushed.
- Authority: [`../../decisions/ADR-020-t3-rtp-media-plane.md`](../../decisions/ADR-020-t3-rtp-media-plane.md),
  [`t3-rtp-media-preparation-audit.md`](t3-rtp-media-preparation-audit.md),
  [`t3-s1-rtpengine-foundation-implementation.md`](t3-s1-rtpengine-foundation-implementation.md).

Confirmed pins in `versions.env`:

```text
RTPENGINE_VERSION=mr26.0.1.19
RTPENGINE_SOURCE_COMMIT=3552ac76cceb24e3ec176b77ec9c25554ae5923b
RTPENGINE_NG_PORT=2223
RTPENGINE_MEDIA_PORT_MIN=40000
RTPENGINE_MEDIA_PORT_MAX=40099
RTPENGINE_METRICS_PORT=2224
RTPENGINE_BASE_IMAGE=debian:trixie-slim@sha256:020c0d20b9880058cbe785a9db107156c3c75c2ac944a6aa7ab59f2add76a7bd
```

## PRODUCT_DEFECT-1 — Pinned rtpengine release asset does not exist

| Field | Value |
|---|---|
| Seam | `infrastructure/docker/rtpengine/Dockerfile`, lines 24 and 28 (`package=` assignments) |
| Expected | `docker build` downloads the pinned `rtpengine-daemon` `.deb` from the `mr26.0.1.19` release and installs it |
| Actual | `curl --fail` receives **HTTP 404** and exits **22**; the build fails at the first `RUN` layer after 22 s |
| Static checks | **All passed** — `make media-config-check`, `make k8s-config-check`, `make check`, `make repository-hygiene` validate the pins *textually* and never resolve the artifact, so none of them detects this |
| Runtime events | None — no Pod was ever created; the failure is at image build |

### Root cause

The Dockerfile builds the asset filename with a **tilde** separator:

```text
rtpengine-daemon_26.0.1.19+0~mr26.0.1.19+gh+trixie_amd64.deb   → HTTP 404
```

The upstream release publishes it with a **dot** separator:

```text
rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb   → HTTP 200
```

Verified directly against the release:

- `https://github.com/sipwise/rtpengine/releases/tag/mr26.0.1.19` → **200** (the pinned version is real)
- Pinned `+0~mr…` asset URL → **404**
- Corrected `+0.mr…` asset URL → **200**
- The GitHub release API lists **34** assets for `mr26.0.1.19` (published `2026-07-22T09:07:12Z`); every one uses `+0.mr`, and **no** asset uses `+0~mr`

### The pins are otherwise correct

Both pinned checksums were verified against the real upstream assets and **match byte-for-byte**:

| Architecture | Real asset | Size | Actual SHA-256 | Pinned SHA-256 | Match |
|---|---|---:|---|---|---|
| amd64 | `rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb` | 650 500 | `c60c7a1463e454dbcff81bf0fbd07c65dbeac742e5997d0c611f40f09161f950` | identical | **yes** |
| arm64 | `rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_arm64.deb` | 588 544 | `f279457f2356b6d4dc7fc483bb2631f79303a3393dbbeae0db9abd58c8c36906` | identical | **yes** |

So the version, the release tag, the base-image digest, and both checksums are
all correct. **Only the filename separator is wrong** — a single character in
each of two lines.

### Smallest bounded Codex correction

In `infrastructure/docker/rtpengine/Dockerfile`, replace `+0~mr26.0.1.19` with
`+0.mr26.0.1.19` in both `package=` assignments:

```text
line 24:  package="rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb";
line 28:  package="rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_arm64.deb";
```

No other change is required: the plain `+` URL form resolves (**200**) without
percent-encoding, both `sha256` values already match, and
`RTPENGINE_VERSION`/`RTPENGINE_SOURCE_COMMIT`/`RTPENGINE_BASE_IMAGE` stay as
pinned.

Recommended hardening, to stop a textual-only pin from passing checks again:
extend `scripts/media/config-check` to assert that the Dockerfile package
filename matches the upstream `NAME_VERSION+0.mrVERSION+gh+SUITE_ARCH`
convention derived from `RTPENGINE_VERSION`, so a malformed separator fails
statically rather than at build time.

## What Was Proven Before the Blocker

### Repository checks — all passed

| Check | Result |
|---|---|
| `make repository-hygiene` | passed |
| `make workflow-check` | passed |
| `make secret-scan` | passed |
| `make k8s-config-check` | passed (`K1 static manifest policy check passed`) |
| `make security-config-check` | passed (`namespace_psa_authority=ok`, `restricted_workload_compatibility=ok`) |
| `make media-config-check` | passed (`T3-S1 media config check passed`) |
| `make check` | passed (hygiene, media, Pint, ESLint, vue-tsc) |
| `make gateway-config-check` | passed (`Gateway API v1.5.1 standard CRD manifest checksum verified`; `Traefik Helm chart 41.0.2 renders proxy image docker.io/traefik:v3.7.7`) |

Helm was absent from this environment and was provisioned to a scratch
directory using the repository's own pinned version (`HELM_VERSION=v4.0.3` from
`versions.env`) and the same checksum-verified procedure as
`scripts/ci/install-kubernetes-tools`. No repository dependency or lockfile was
changed; the binary was removed during cleanup.

### Cluster baseline

- Context `k3d-utcp-local`; nodes `agent-0` (172.24.0.4), `agent-1` (172.24.0.2), `server-0` (172.24.0.5), all `Ready`.
- `utcp-platform` PSA labels: `enforce/audit/warn=restricted`, all three pinned at `v1.35`.
- 15 Deployments, 16 Pods, all Ready; `web` is the newest at `2026-07-28T00:52:38Z`.
- Services in `utcp-platform`: `api`, `gateway`, `kamailio`, `reverb`, `web` — **all ClusterIP**; no NodePort or LoadBalancer.
- NetworkPolicies cluster-wide: **31**.
- Jobs: `utcp-migrate` 1 succeeded, 0 failed.
- Pending outbox: **0**. Redis `queues:default` 0, `queues:default:failed` 0.
- API policy-pin drift check: **passed** (`endpoint=172.24.0.5/32:6443`); no repair was needed and no policy was applied.
- Host publication: `127.0.0.1:80->80/tcp`, `127.0.0.1:443->443/tcp`, plus the established k3d API binding `127.0.0.1:6550->6443/tcp`. **No UDP is published**, so the TCP `80/443` application edge boundary holds.
- rtpengine resources in the cluster: **absent**, as expected before this slice.

### Rendered resources conform to the T3-S1 contract

`kubectl kustomize infrastructure/kubernetes/overlays/local` and
`kubectl kustomize infrastructure/kubernetes/security` render cleanly. The
rtpengine resources match the contract exactly:

| Requirement | Rendered value |
|---|---|
| Deployment namespace / replicas | `utcp-platform` / `1` |
| Service type / protocol / port | `ClusterIP` / `UDP` / `2223` (named `ng`) |
| Media range | container port `40000` named `media`; entrypoint enforces `40000–40099` |
| Metrics | container port `2224/TCP` named `metrics` |
| `runAsNonRoot` / UID / GID | `true` / `1000` / `1000` |
| `allowPrivilegeEscalation` | `false` |
| `capabilities` | `drop: [ALL]` |
| `readOnlyRootFilesystem` | `true` (with `emptyDir` at `/tmp` and `/run/rtpengine`) |
| `seccompProfile` | `RuntimeDefault` |
| `automountServiceAccountToken` | `false` |
| hostNetwork / hostPort / hostPath / NodePort / LoadBalancer / Gateway route | **all absent** |
| Probes | readiness and liveness both `exec /usr/local/bin/utcp-rtpengine-ng-ping` (5 s / 15 s) |
| Resources | requests `100m`/`128Mi`, limits `500m`/`512Mi` |
| POD_IP | `valueFrom.fieldRef.fieldPath: status.podIP` |

`allow-rtpengine-media` renders with `podSelector utcp.io/network-role:
rtpengine-media` and:

- ingress `2223/UDP` **only** from `utcp.io/network-role: kamailio-signaling` in `utcp-platform`;
- ingress `40000–40099/UDP` from Kamailio and from `asterisk-ari` in `utcp-runtime`;
- ingress `2224/TCP` **only** from `app.kubernetes.io/name in (prometheus)` in `utcp-observability`;
- egress `40000–40099/UDP` to the same media peers;
- `policyTypes: [Ingress, Egress]`, so default-deny is preserved and no Kubernetes API, PostgreSQL, or Redis egress is granted.

The entrypoint independently enforces the contract at runtime, rejecting a
missing/loopback/link-local `POD_IP`, a media range other than `40000–40099`,
and an ng port other than `2223`, and it passes `--table=-1` (userspace
forwarding) with `--interface=internal/${POD_IP}!${POD_IP}`.

## What Could Not Be Proven

Everything downstream of a running Pod. No claim is made about any of these:

image build from pinned source · image digest and provenance · registry push ·
resources applied · PSA admission · effective runtime security context ·
Pod-IP bind and advertisement · readiness `ng` pong · liveness-triggered
restart · control Service endpoint · authorized control access · unauthorized
control denial · runtime media-boundary containment · internal metrics
allow/deny · relay-unavailable failure · automatic restoration.

**No T3-S1 resource was applied to the cluster.** Applying them with an
unbuildable image would have produced a permanently `ImagePullBackOff` workload
in `utcp-platform`, contradicting the contract's requirement to *"leave the
T3-S1 resources deployed and healthy"*. Leaving the cluster untouched is the
reversible choice and keeps the environment exactly at its pre-proof baseline.

Two rtpengine images from an unrelated **APNTalk** project exist in the local
Docker cache. They were **not** used: the repository's clean-room requirement
forbids taking configuration or artefacts from an employer or client
repository, and substituting any image would also violate the proof contract.

## Findings

| Classification | Finding |
|---|---|
| **PRODUCT_DEFECT-1** | Pinned rtpengine release asset filename uses `+0~mr` where upstream publishes `+0.mr`; build fails with HTTP 404 / curl exit 22. Static checks pass because they validate pins textually |
| PASS | All eight repository checks pass, including `gateway-config-check` once Helm is provisioned |
| PASS | Rendered Deployment, Service, ConfigMap, and NetworkPolicy match the ADR-020 contract exactly, including restricted-compatible security context and the absence of host/NodePort/LoadBalancer/Gateway surface |
| PASS | Cluster baseline healthy; host publication limited to TCP `80/443` plus the established k3d API port; no UDP published |
| EXPECTED_BEHAVIOR | Helm absent from this environment; provisioned from the repository's pinned `HELM_VERSION=v4.0.3` with checksum verification and removed at cleanup |
| PROOF_LIMITATION | All runtime claims are unproven; no T3-S1 resource was applied, deliberately, to avoid leaving a failing workload |

## Environment Preservation

```text
production code changed:       no
Kubernetes manifests changed:  no
dependencies changed:          no
versions.env changed:          no
runtime configuration changed: no
resources applied:             none
workloads restarted:           no
images built:                  no (build failed at the first RUN layer)
images pushed:                 no
live media proof run:          no
canonical records mutated:     no
```

Cluster state is byte-identical to the recorded baseline: same 16 Pods with the
same creation timestamps, 31 NetworkPolicies, 5 ClusterIP Services, 0 pending
outbox, 0 Redis queue depth, no rtpengine resources.

## Cleanup

- No proof-client Pod was created (the proof never reached that stage).
- No port-forward was started.
- Provisioned Helm binary, downloaded `.deb` artefacts, release JSON, and rendered manifests removed from the scratch directory.
- No credentials were introduced. `.playwright-mcp/` is absent.
- Working tree contains only this evidence document and the roadmap note.

## T3-S1 Final Status

```text
T3-S1 live foundation proof = INCOMPLETE (blocked by PRODUCT_DEFECT-1)
T3 = In Progress
UTCP_PHASE = T1 (unchanged)
```

## Recommended Next Step

One bounded Codex correction for `PRODUCT_DEFECT-1` — replace `+0~mr26.0.1.19`
with `+0.mr26.0.1.19` in both `package=` assignments in
`infrastructure/docker/rtpengine/Dockerfile`, and extend
`scripts/media/config-check` to assert the upstream asset-naming convention so
the same class of defect fails statically. Then re-run this live proof
unchanged from step 4. Do not broaden the correction into Kamailio media
routing, browser SIP, conference admission, V0, or T4.
