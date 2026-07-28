# T3-S1 — rtpengine PID-File Runtime Path Correction

Verdict: `T3_S1_RTPENGINE_PIDFILE_CORRECTION_COMPLETE`

This evidence records the bounded correction for `PRODUCT_DEFECT-2`. No
Kubernetes resource was applied, no image was pushed, no production Kamailio
media routing was introduced, and `UTCP_PHASE=T1` was preserved. T3 remains In
Progress pending resumed live relay proof from the corrected image.

## Source Authority

- Blocked proof commit: `bc15667` (`docs(t3): record blocked rtpengine startup proof`)
- Blocked proof: [`t3-s1-rtpengine-foundation-live-proof.md`](t3-s1-rtpengine-foundation-live-proof.md)
- ADR: [`../../decisions/ADR-020-t3-rtp-media-plane.md`](../../decisions/ADR-020-t3-rtp-media-plane.md)
- Implementation evidence: [`t3-s1-rtpengine-foundation-implementation.md`](t3-s1-rtpengine-foundation-implementation.md)
- Package correction evidence: [`t3-s1-rtpengine-package-asset-correction.md`](t3-s1-rtpengine-package-asset-correction.md)
- Phase marker retained: `UTCP_PHASE=T1`

## Root Cause

The Deployment mounts an `emptyDir` at `/tmp`. That mount hides the
image-created `/tmp/rtpengine` directory from the Dockerfile, so the committed
entrypoint path:

```text
--pidfile=/tmp/rtpengine/rtpengine.pid
```

could not be created at runtime. rtpengine aborted before binding the ng
listener with:

```text
Failed to create PID file (No such file or directory)
```

The existing Deployment also mounts an `emptyDir` at `/run/rtpengine`. That path
is mounted exactly at its runtime location and is writable by UID/GID
`1000:1000`, so it is the canonical PID-file authority for the restricted
rtpengine container.

## Entrypoint Correction

Changed only the PID-file argument:

```text
old: --pidfile=/tmp/rtpengine/rtpengine.pid
new: --pidfile=/run/rtpengine/rtpengine.pid
```

These runtime arguments were preserved unchanged:

```text
--table=-1
--listen-ng=${POD_IP}:2223
--interface=internal/${POD_IP}!${POD_IP}
--port-min=40000
--port-max=40099
--listen-http=${POD_IP}:2224
```

No `/tmp` directory recreation, init container, added volume, `fsGroup`, root
execution, added Linux capability, writable root filesystem, fallback PID path,
conditional path selection, or environment-variable switch was added.

## Static Guard Enhancement

`scripts/media/config-check` now validates the offline PID-file and writable
runtime-mount contract:

- entrypoint has exactly one `--pidfile` argument
- PID path is exactly `/run/rtpengine/rtpengine.pid`
- PID path is not below the conflicting `/tmp` mount
- Deployment source has a `/run/rtpengine` volume mount
- the mount is writable (`readOnly` false or omitted)
- the backing volume is `emptyDir`
- the PID parent is under the expected writable runtime mount

Failure messages name the entrypoint PID path, expected writable mount
`/run/rtpengine`, and conflicting parent mount `/tmp`.

## Regression Coverage

`scripts/media/config-check-test` now proves:

- the corrected repository passes
- changing the PID file back to `/tmp/rtpengine/rtpengine.pid` fails
- removing the `/run/rtpengine` mount fails
- setting `/run/rtpengine` read-only fails
- changing the PID file to another unapproved path fails
- adding a second `--pidfile` argument fails
- existing version, image, port, security, NetworkPolicy, gateway, k3d, Kamailio
  boundary, and durable-authority assertions remain active through the same
  media guard

## Static Verification

```text
git status --short: clean at start
git log -20 --oneline --decorate: HEAD bc15667
grep -n '^UTCP_PHASE=' versions.env: 7:UTCP_PHASE=T1
make repository-hygiene: passed
make workflow-check: passed
make secret-scan: passed
make k8s-config-check: passed
make security-config-check: passed
make media-config-check: passed
make media-config-check-test: passed
make check: passed
make gateway-config-check: first attempt failed because helm was absent
PATH=/tmp/utcp-tools/kubernetes-t3-pid:$PATH make gateway-config-check: passed
git diff --check: passed
git diff --cached --check: passed
```

Helm was provisioned temporarily under `/tmp/utcp-tools/kubernetes-t3-pid` from
the repository-pinned `HELM_VERSION=v4.0.3` using
`scripts/ci/install-kubernetes-tools`; checksum verification reported:

```text
helm-v4.0.3-linux-amd64.tar.gz: OK
```

No repository dependency or lockfile changed.

## Corrected Image Build

Successful local build command:

```text
make image-build-rtpengine UTCP_BUILD_COMMIT=87f0b9f
```

The first two attempts with `UTCP_BUILD_COMMIT=bc15667` did not reach a completed
image because the pre-existing package-install layer was invalidated and stalled
inside `apt-get update` after snapshot TLS failures. The successful correction
build reused the unchanged pinned package layer from the prior verified local
image and rebuilt the changed entrypoint layer.

Build result:

```text
local image: utcp-rtpengine:dev
image id / manifest list: sha256:15e64a1d8788941becc3c3a6abe772e145c54a21d3fb79eadbef4d07eb038345
configured user: 1000:1000
org.opencontainers.image.revision: 87f0b9f
org.opencontainers.image.version: 0.1.0-dev
org.opencontainers.image.source: local
org.opencontainers.image.licenses: GPL-3.0-or-later
```

Proven unchanged build provenance in Docker history:

```text
RTPENGINE_VERSION=mr26.0.1.19
RTPENGINE_SOURCE_COMMIT=3552ac76cceb24e3ec176b77ec9c25554ae5923b
TARGETARCH=amd64
rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb
sha256sum --check
USER 1000:1000
```

The later Claude Code live proof should rebuild from the correction commit so
the runtime image revision label matches the final commit.

## Mount-Shadow Startup Smoke

A disposable local Docker network and container reproduced the relevant
Kubernetes writable-volume contract without applying Kubernetes resources:

```text
/tmp: fresh writable tmpfs, hiding image-created /tmp/rtpengine
/run/rtpengine: fresh writable tmpfs owned by UID/GID 1000
container user: 1000:1000
privileged: not used
extra capabilities: none
```

Pre-start checks in the container required `/tmp/rtpengine` to be absent and
`/run/rtpengine` to be writable before executing the committed entrypoint.

Startup result:

```text
INFO: [core] Version 26.0.1.19+0~mr26.0.1.19 initialising
INFO: [http] Websocket listener thread running
INFO: [core] Startup complete, version 26.0.1.19+0~mr26.0.1.19
```

Runtime command line:

```text
/usr/bin/rtpengine --foreground --pidfile=/run/rtpengine/rtpengine.pid --table=-1 --listen-ng=172.21.0.2:2223 --interface=internal/172.21.0.2!172.21.0.2 --port-min=40000 --port-max=40099 --listen-http=172.21.0.2:2224 --log-stderr
```

PID-file and mount proof:

```text
/tmp/rtpengine: absent
/run/rtpengine: drwxrwxrwx 1000:1000
/run/rtpengine/rtpengine.pid: mode 644 uid 1000 gid 1000 size 2
```

Privilege proof:

```text
Uid: 1000 1000 1000 1000
Gid: 1000 1000 1000 1000
CapPrm: 0000000000000000
CapEff: 0000000000000000
CapAmb: 0000000000000000
```

The committed ng ping helper returned:

```text
POD_IP=172.21.0.2
ng_ping=passed
```

The disposable smoke container and network were removed after proof.

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

Claude Code rebuilds and pushes the correction-commit image, restarts only
deployment/rtpengine, and resumes the existing T3-S1 live proof for readiness,
liveness, control NetworkPolicy, metrics, relay failure, restoration, and
authority preservation.
