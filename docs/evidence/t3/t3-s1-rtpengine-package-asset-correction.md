# T3-S1 — rtpengine Package Asset Correction

Verdict: `T3_S1_RTPENGINE_PACKAGE_ASSET_CORRECTION_COMPLETE`

This evidence records the bounded correction for `PRODUCT_DEFECT-1` from the
blocked T3-S1 live proof. The rtpengine architecture remains ADR-020 exactly:
no Kubernetes resource was applied, no image was pushed, and no production SIP
media routing was introduced.

## Source Authority

- Blocked proof commit: `61ed0ca` (`docs(t3): record blocked rtpengine foundation proof`)
- Blocked proof: [`t3-s1-rtpengine-foundation-live-proof.md`](t3-s1-rtpengine-foundation-live-proof.md)
- Implementation evidence: [`t3-s1-rtpengine-foundation-implementation.md`](t3-s1-rtpengine-foundation-implementation.md)
- ADR: [`../../decisions/ADR-020-t3-rtp-media-plane.md`](../../decisions/ADR-020-t3-rtp-media-plane.md)
- Phase marker retained: `UTCP_PHASE=T1`

## Root Cause

`infrastructure/docker/rtpengine/Dockerfile` used the Debian release asset
separator `+0~${RTPENGINE_VERSION}` in both architecture package assignments.
The official upstream `sipwise/rtpengine` release assets for
`mr26.0.1.19` use `+0.${RTPENGINE_VERSION}` instead.

Invalid convention:

```text
0~mr26.0.1.19
```

Valid upstream convention:

```text
0.mr26.0.1.19
```

Full valid filename form:

```text
<package-name>_<package-version>+0.<RTPENGINE_VERSION>+gh+<suite>_<architecture>.deb
```

The blocked proof verified that the release tag, source commit, base image
digest, and both SHA-256 checksums were correct. Only the release asset filename
separator was wrong.

## Pin Preservation

These values remain unchanged:

```text
RTPENGINE_VERSION=mr26.0.1.19
RTPENGINE_SOURCE_COMMIT=3552ac76cceb24e3ec176b77ec9c25554ae5923b
RTPENGINE_BASE_IMAGE=debian:trixie-slim@sha256:020c0d20b9880058cbe785a9db107156c3c75c2ac944a6aa7ab59f2add76a7bd
```

The pinned package checksums remain unchanged:

| Architecture | SHA-256 |
|---|---|
| amd64 | `c60c7a1463e454dbcff81bf0fbd07c65dbeac742e5997d0c611f40f09161f950` |
| arm64 | `f279457f2356b6d4dc7fc483bb2631f79303a3393dbbeae0db9abd58c8c36906` |

## Dockerfile Correction

Both package assignments now use the official upstream asset convention:

```text
rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb
rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_arm64.deb
```

No fallback URL, alternate repository, unpinned asset, checksum change, base
image change, runtime command change, UID/GID change, Kubernetes change, or
Kamailio media-routing change was added.

## Static Guard Enhancement

`scripts/media/config-check` now validates the Dockerfile package assignments
offline against the convention derived from `RTPENGINE_VERSION`.

The guard rejects:

- `+0~${RTPENGINE_VERSION}`
- `latest`
- malformed or unversioned package names
- package-version drift from `RTPENGINE_VERSION`
- release-version drift from `RTPENGINE_VERSION`
- a missing `amd64` or `arm64` assignment
- different package naming rules between the two architectures

Failure messages identify the expected convention and the offending assignment,
including the architecture when it can be derived.

## Regression Coverage

`scripts/media/config-check-test` exercises the real media guard against the
actual Dockerfile and temporary mutated Dockerfiles.

Coverage proves:

- the corrected Dockerfile passes
- the former `+0~${RTPENGINE_VERSION}` form fails
- a mismatched package version fails
- both `amd64` and `arm64` assignments are required
- the existing media guard still runs the checksum, digest, security, port,
  NetworkPolicy, Gateway, Kamailio-boundary, k3d, and durable-authority checks

The target `make media-config-check-test` is wired into `make check`.

## Image Build

Successful local build command:

```bash
make image-build-rtpengine
```

Build inputs:

```text
RTPENGINE_VERSION=mr26.0.1.19
RTPENGINE_SOURCE_COMMIT=3552ac76cceb24e3ec176b77ec9c25554ae5923b
RTPENGINE_BASE_IMAGE=debian:trixie-slim@sha256:020c0d20b9880058cbe785a9db107156c3c75c2ac944a6aa7ab59f2add76a7bd
```

Build result:

```text
result: passed
local image: utcp-rtpengine:dev
image id: sha256:8dbbd8b96f99680542dc7a78f363c49ac3b8702033a8143556d3aaf268b85a51
repo digest: utcp-rtpengine@sha256:8dbbd8b96f99680542dc7a78f363c49ac3b8702033a8143556d3aaf268b85a51
architecture: amd64
configured user: 1000:1000
```

The build downloaded the corrected upstream asset:

```text
rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb
```

Checksum verification succeeded:

```text
/tmp/rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb: OK
```

The corrected `arm64` upstream asset was also downloaded directly for a
focused checksum proof because this host Docker builder cannot execute
cross-architecture `arm64` build layers without emulation:

```text
rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_arm64.deb: OK
arm64_rtpengine_asset_checksum=passed
```

OCI labels on the local image:

```text
org.opencontainers.image.title=UTCP rtpengine media relay
org.opencontainers.image.description=Repository-built rtpengine userspace media relay for the UTCP T3-S1 foundation
org.opencontainers.image.version=0.1.0-dev
org.opencontainers.image.revision=unknown
org.opencontainers.image.created=unknown
org.opencontainers.image.source=local
org.opencontainers.image.vendor=Unified Telephony Control Plane
org.opencontainers.image.licenses=GPL-3.0-or-later
```

## Verification

Repository-static verification:

```text
make repository-hygiene: passed
make workflow-check: passed
make secret-scan: passed
make k8s-config-check: passed
make security-config-check: passed
make media-config-check: passed
make media-config-check-test: passed
make check: passed
make gateway-config-check: passed with repository-pinned Helm v4.0.3 provisioned temporarily under /tmp
git diff --check: passed
```

The first `make gateway-config-check` attempt reported that `helm` was absent.
The check was rerun successfully after temporarily provisioning the
repository-pinned Helm version with checksum verification. No dependency or
lockfile changed.

An additional `linux/arm64` Docker build attempt using the same
`make image-build-rtpengine` target reached the corrected `arm64` package
assignment but stopped at the first `RUN` layer with `exec /bin/sh:
exec format error`; the local builder does not provide arm64 emulation. The
static guard and direct pinned asset checksum proof cover the arm64 filename
correction without changing repository architecture.

## Environment Preservation

```text
Kubernetes resources applied: no
image pushed: no
production Kamailio routing changed: no
browser SIP added: no
conference admission added: no
V0/T4/trunk/PSTN work added: no
UTCP_PHASE changed: no
```

## Remaining Live-Proof Boundary

Resume only the existing focused Claude Code T3-S1 live relay-foundation proof
from image push and Kubernetes rollout. Prove PSA, readiness, liveness, control
NetworkPolicy, metrics, failure, restoration, and authority preservation without
adding production SIP media routing.
