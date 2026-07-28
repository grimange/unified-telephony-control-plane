# T3-S1 — rtpengine Foundation Live Proof

Verdict: `T3_S1_RTPENGINE_FOUNDATION_LIVE_PROOF_INCOMPLETE`

This document supersedes the earlier proof attempt at `b8cd960`, which was blocked
at image build by `PRODUCT_DEFECT-1` (pinned release asset filename separator).
That defect is corrected in `87f0b9f` and is **confirmed resolved**: the pinned
image now builds, both package checksums verify, the image pushes to the local
registry, and the Kubernetes resources apply and are admitted under restricted
Pod Security Admission.

The proof advanced from image build all the way to a live, admitted Pod and then
stopped at one new, exact, reproducible defect: **`PRODUCT_DEFECT-2` — the
`/tmp` `emptyDir` mount shadows the image-created `/tmp/rtpengine` directory, so
the pidfile path passed by the committed entrypoint does not exist and rtpengine
aborts during startup.** Per the proof contract, no production file was modified
to work around it.

**T3-S1 remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

- Proof executed at `87f0b9f` (`fix(t3): correct pinned rtpengine package asset`).
- Branch `main`, working tree clean at start and at finish, `UTCP_PHASE=T1`, nothing pushed.
- Authority: [`ADR-020`](../../decisions/ADR-020-t3-rtp-media-plane.md),
  [`t3-rtp-media-preparation-audit.md`](t3-rtp-media-preparation-audit.md),
  [`t3-s1-rtpengine-foundation-implementation.md`](t3-s1-rtpengine-foundation-implementation.md),
  [`t3-s1-rtpengine-package-asset-correction.md`](t3-s1-rtpengine-package-asset-correction.md).

Confirmed pins in `versions.env`, all unchanged by this proof:

```text
RTPENGINE_VERSION=mr26.0.1.19
RTPENGINE_SOURCE_COMMIT=3552ac76cceb24e3ec176b77ec9c25554ae5923b
RTPENGINE_NG_PORT=2223
RTPENGINE_MEDIA_PORT_MIN=40000
RTPENGINE_MEDIA_PORT_MAX=40099
RTPENGINE_METRICS_PORT=2224
RTPENGINE_BASE_IMAGE=debian:trixie-slim@sha256:020c0d20b9880058cbe785a9db107156c3c75c2ac944a6aa7ab59f2add76a7bd
```

## PRODUCT_DEFECT-2 — `/tmp` emptyDir shadows the pidfile directory, aborting startup

| Field | Value |
|---|---|
| Seam | [`infrastructure/docker/rtpengine/entrypoint`](../../../infrastructure/docker/rtpengine/entrypoint) line 44 (`--pidfile=/tmp/rtpengine/rtpengine.pid`) against [`infrastructure/kubernetes/base/platform/rtpengine-deployment.yaml`](../../../infrastructure/kubernetes/base/platform/rtpengine-deployment.yaml) lines 78–79 (`emptyDir` mounted at `/tmp`) |
| Expected | The container starts rtpengine in the foreground, binds ng on the Pod IP, and the readiness `ng` ping returns `pong` |
| Actual | rtpengine logs `CRIT: [core] Failed to create PID file (No such file or directory), aborting startup`, exits `255` immediately after start, and the Pod enters `CrashLoopBackOff` |
| Static checks | **All passed** — `make media-config-check`, `make media-config-check-test`, `make k8s-config-check`, `make security-config-check`, `make check` validate ports, pins, and security fields but none asserts that the pidfile path survives the volume mounts |
| Pod events | `Scheduled` → `Pulled` → `Created` → `Started` → `Unhealthy` (readiness) → `BackOff restarting failed container`. **No PSA violation, no image-pull error** |
| Restart count | 7 at the time of this record, still climbing under the standard backoff |

### Root cause

The Dockerfile creates and owns the pidfile directory inside the image:

```text
infrastructure/docker/rtpengine/Dockerfile:49-50
  mkdir -p /tmp/rtpengine /run/rtpengine
  chown -R 1000:1000 /tmp/rtpengine /run/rtpengine
```

The Deployment then mounts an empty `emptyDir` **over `/tmp`**, which shadows the
image content beneath it. `/tmp/rtpengine` therefore does not exist at runtime,
so rtpengine cannot create `/tmp/rtpengine/rtpengine.pid` and aborts before it
binds any socket.

Verified directly in-cluster with a short-lived probe Pod using the **same image,
same securityContext, and same two `emptyDir` mounts**:

```text
/tmp            drwxrwxrwx root root      (emptyDir, writable by UID 1000)
/run/rtpengine  drwxrwxrwx root root      (emptyDir, writable by UID 1000)
/tmp/rtpengine  does not exist            <-- shadowed away
mkdir -p /tmp/rtpengine                   succeeds
```

The second `emptyDir` at `/run/rtpengine` is mounted exactly at its mount point,
so that directory **does** exist and **is** writable by UID `1000` — but nothing
currently uses it.

### The rest of the runtime contract is otherwise correct

To establish whether the pidfile path is the only barrier — without modifying any
production file — the committed image was run in a disposable local container
with the identical mount shape and the **committed entrypoint invoked unchanged**,
with only `mkdir -p /tmp/rtpengine` executed first. rtpengine then started
cleanly and satisfied every remaining runtime requirement:

```text
INFO: [core] Version 26.0.1.19+0~mr26.0.1.19 initialising
INFO: [http] Websocket listener thread running
INFO: [core] Startup complete, version 26.0.1.19+0~mr26.0.1.19
```

Process command line (PID 1, UID `1000`):

```text
/usr/bin/rtpengine --foreground --pidfile=/tmp/rtpengine/rtpengine.pid --table=-1
  --listen-ng=<IP>:2223 --interface=internal/<IP>!<IP>
  --port-min=40000 --port-max=40099 --listen-http=<IP>:2224 --log-stderr
```

| Requirement | Result |
|---|---|
| `--table=-1` userspace forwarding | active |
| ng control port | UDP `2223` on the injected IP |
| media range | exactly `40000–40099` (`rtpengine_ports 100`, `rtpengine_ports_free 100`) |
| metrics bind | TCP `2224` on the injected IP |
| bind/advertise identity | `--interface=internal/<IP>!<IP>`, no node IP, Service IP, loopback, or hard-coded host IP |
| privileged/kernel initialisation | none attempted; no `xt_RTPENGINE`, no forwarding setup |
| committed readiness helper | `/usr/local/bin/utcp-rtpengine-ng-ping` returned a validated `result=pong` (exit `0`) |

So `PRODUCT_DEFECT-2` is the **only** barrier between the current commit and a
Ready rtpengine Pod. No further defect is hidden behind it at the process level.

### Smallest bounded Codex correction

Point the pidfile at the already-mounted, already-writable `/run/rtpengine`
`emptyDir` — a one-line change in `infrastructure/docker/rtpengine/entrypoint`:

```text
line 44:  --pidfile=/run/rtpengine/rtpengine.pid \
```

`/run/rtpengine` is mounted exactly at its mount point, so it always exists and
is writable by UID `1000`; it exists in the committed manifest for runtime
scratch and is currently unused. Equally small alternatives, in order of
preference:

1. the change above (uses the volume already provisioned for this purpose);
2. drop `--pidfile` entirely — a PID file carries no meaning for a single
   foreground process under Kubernetes supervision;
3. `mkdir -p /tmp/rtpengine` in the entrypoint before `exec`.

Recommended hardening, so a mount-shadowed runtime path fails statically rather
than at rollout: extend `scripts/media/config-check` to assert that every
filesystem path the entrypoint writes to (currently the pidfile directory) is
either a rendered `volumeMounts.mountPath` itself or nested under one that the
image does not have to pre-create.

## Proven Live

### Repository checks — all passed, before and after

| Check | Before apply | After apply |
|---|---|---|
| `make repository-hygiene` | passed | passed |
| `make workflow-check` | passed | passed |
| `make secret-scan` | passed | passed |
| `make k8s-config-check` | passed | passed |
| `make security-config-check` | passed (`namespace_psa_authority=ok`, `restricted_workload_compatibility=ok`) | passed |
| `make media-config-check` | passed (`T3-S1 media config check passed`) | passed |
| `make media-config-check-test` | passed | passed |
| `make gateway-config-check` | passed (Gateway API `v1.5.1` CRD checksum verified; Traefik chart `41.0.2` renders `docker.io/traefik:v3.7.7`) | passed |
| `make check` | passed (hygiene, media, Pint, ESLint, vue-tsc) | passed |
| `git diff --check` / `git diff --cached --check` | clean | clean |

Helm was absent from this environment and was provisioned to a scratch directory
from the repository's own pin (`HELM_VERSION=v4.0.3`) using the same
checksum-verified procedure as `scripts/ci/install-kubernetes-tools`
(`helm-v4.0.3-linux-amd64.tar.gz: OK`, `v4.0.3+g9db13ee`). No repository
dependency or lockfile changed; the binary was removed during cleanup.

### Cluster baseline

- Context `k3d-utcp-local`, namespace `utcp-platform`, kubeconfig `.runtime/kubeconfig/utcp-local.yaml`.
- Nodes all `Ready`, all `amd64`, all `v1.35.3+k3s1`: `server-0` (172.24.0.2), `agent-1` (172.24.0.3), `agent-0` (172.24.0.4).
- PSA on `utcp-platform`, `utcp-runtime`, `utcp-observability`: `enforce`/`audit`/`warn` = `restricted`, all pinned `v1.35`.
- 16 pre-existing `utcp-platform` Pods, all Ready; 15 Deployments; 5 ClusterIP Services (`api`, `gateway`, `kamailio`, `reverb`, `web`); 31 NetworkPolicies cluster-wide; `utcp-migrate` 1 succeeded / 0 failed.
- Pending outbox `0`; Redis `queues:default` `0`, `queues:default:failed` `0`.
- Host publication: `127.0.0.1:80->80/tcp`, `127.0.0.1:443->443/tcp`, plus the established k3d API `127.0.0.1:6550->6443/tcp` and registry `127.0.0.1:5001->5000/tcp`. **No UDP published.**
- rtpengine resources in the cluster: **absent**, as expected before this slice.

**Kubernetes API policy-pin drift found and repaired.** The node IPs had shuffled
since the previous proof (`server-0` moved `172.24.0.5` → `172.24.0.2`) while the
applied policies still pinned `172.24.0.5/32`. Symptoms: `utcp-kube-state-metrics`
(240 restarts) and the Grafana dashboard sidecar not Ready. Repaired **only**
through the canonical renderer and **only** for the three affected generated
policy resources:

```text
scripts/security/render-apiserver-policy
kubectl apply -f .runtime/kubernetes/security/traefik-apiserver-egress.yaml
             -f .runtime/kubernetes/security/runtime-fencer-apiserver-egress.yaml
             -f .runtime/observability/allow-apiserver-egress.yaml
→ allow-traefik-kubernetes-api               172.24.0.2/32
→ allow-runtime-fencer-kubernetes-api        172.24.0.2/32
→ allow-observability-kubernetes-api-egress  172.24.0.2/32
scripts/security/check-apiserver-policy-drift
→ Kubernetes API egress drift check passed endpoint=172.24.0.2/32:6443
```

`utcp-kube-state-metrics` and the Grafana sidecar returned to Ready after the
repair. No hand-written policy was applied and no other resource was touched.

### Final image provenance

Built with the canonical repository metadata mechanism, rtpengine only:

```text
make image-build-rtpengine UTCP_BUILD_COMMIT=87f0b9f
```

| Field | Value |
|---|---|
| local image | `utcp-rtpengine:dev` |
| image ID / index digest | `sha256:bd021530b37fa8d76e256fb5bf5c48a52446ef323cd12527eb5f738f8ecf15dd` |
| `linux/amd64` manifest digest | `sha256:13c1a769277dfecf35744d0b1a00d4abe8de9ab56a549469bff5073aba4e609b` |
| config digest | `sha256:2e74fc237dc754e47915a64bbea4a3105645b3b794dc76c7d445b4d794814a06` |
| `org.opencontainers.image.revision` | **`87f0b9f`** (was `unknown` in the pre-correction build) |
| `org.opencontainers.image.version` | `0.1.0-dev` |
| `org.opencontainers.image.licenses` | `GPL-3.0-or-later` |
| architecture / os | `amd64` / `linux` |
| configured user | `1000:1000` |
| entrypoint | `/usr/local/bin/utcp-rtpengine-entrypoint` |
| rtpengine version in image | `26.0.1.19+0~mr26.0.1.19` (from tag `mr26.0.1.19`) |
| upstream source commit | `3552ac76cceb24e3ec176b77ec9c25554ae5923b` (recorded in build history) |
| package asset installed | `rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb` |
| package checksum | verified in-layer by `sha256sum --check` against the pinned `c60c7a14…f950` |
| embedded credentials | none — no credential material in `/usr/local/bin` or `/etc/rtpengine` |

The image build history records `BUILD_COMMIT=87f0b9f`,
`RTPENGINE_VERSION=mr26.0.1.19`,
`RTPENGINE_SOURCE_COMMIT=3552ac76cceb24e3ec176b77ec9c25554ae5923b`, and
`TARGETARCH=amd64`, so every required identity is verifiable from the image
itself. `org.opencontainers.image.created` remains `unknown`, which is the
repository-wide canonical default (`Makefile:9`) and matches
`docs/runbooks/container-images.md`.

### Registry push result

Pushed rtpengine **only**, using the canonical library's own references
(`scripts/kubernetes/lib`), because `scripts/kubernetes/image-push` pushes all
five images and this proof must not republish `api`, `web`, `gateway`, or
`asterisk-ari`.

| Field | Value |
|---|---|
| registry reference | `127.0.0.1:5001/utcp/rtpengine:0.1.0-k1-dev` |
| in-cluster reference | `utcp-local-registry:5000/utcp/rtpengine:0.1.0-k1-dev` |
| tag | `0.1.0-k1-dev` (`K1_IMAGE_TAG`) — **no `latest`** |
| pushed manifest-index digest | `sha256:bd021530b37fa8d76e256fb5bf5c48a52446ef323cd12527eb5f738f8ecf15dd` |
| `linux/amd64` platform digest | `sha256:13c1a769277dfecf35744d0b1a00d4abe8de9ab56a549469bff5073aba4e609b` |
| local image digest | identical (`sha256:bd021530…`) |
| registry reachability | `GET /v2/_catalog` → `200`; `utcp/rtpengine` present; tags `["0.1.0-k1-dev"]` |
| pull-back verification | `docker pull` returned the same digest |

**The running Pod's `imageID` is `utcp-local-registry:5000/utcp/rtpengine@sha256:bd021530…`, so the local, registry, and running image content match exactly.**

### Rendered resources conform to the T3-S1 contract

`kubectl kustomize infrastructure/kubernetes/overlays/local` and
`kubectl kustomize infrastructure/kubernetes/security` render cleanly.

| Requirement | Rendered value |
|---|---|
| Deployment namespace / replicas | `utcp-platform` / `1` |
| Deployment image | `utcp-local-registry:5000/utcp/rtpengine:0.1.0-k1-dev`, `imagePullPolicy: Always` |
| Service type / protocol / port | `ClusterIP` / `UDP` / `2223` (named `ng`) |
| Container ports | `ng 2223/UDP`, `media 40000/UDP`, `metrics 2224/TCP` |
| `runAsNonRoot` / UID / GID | `true` / `1000` / `1000` |
| `allowPrivilegeEscalation` | `false` |
| `capabilities` | `drop: [ALL]` |
| `readOnlyRootFilesystem` | `true` (with `emptyDir` at `/tmp` and `/run/rtpengine`) |
| `seccompProfile` | `RuntimeDefault` |
| `automountServiceAccountToken` | `false` |
| hostNetwork / hostPID / hostIPC / hostPort / hostPath / NodePort / LoadBalancer / Gateway route | **all absent** (asserted programmatically across both renders) |
| Probes | readiness and liveness both `exec /usr/local/bin/utcp-rtpengine-ng-ping` (`5 s` / `15 s`, timeout `3 s`) |
| `POD_IP` | `valueFrom.fieldRef.fieldPath: status.podIP` |

`kubectl diff` against the live cluster confirms rendering introduces **no
unrelated material change**: apart from the four new rtpengine resources, every
other differing NetworkPolicy differs **only** in its `metadata.generation`
field (a server-side merge artefact, zero spec change). One genuinely divergent
pre-existing resource, `utcp-application-config` (live `APP_URL`/
`BROADCAST_CONNECTION` differ from the local overlay), was **deliberately not
applied** — it is unrelated to T3-S1 and outside this proof's scope.

### Resources applied — only T3-S1

```text
configmap/rtpengine-config                        created
service/rtpengine                                 created
deployment.apps/rtpengine                         created
networkpolicy.networking.k8s.io/allow-rtpengine-media  created
```

No broad cluster or namespace apply was performed. No existing workload was
rebuilt or restarted. There is no separately defined internal metrics resource —
metrics is a container port only, with no metrics Service, consistent with the
"public metrics Service absent" requirement.

### Pod Security Admission — PASS

The Pod is **admitted and started** under the current `restricted:v1.35` labels:

```text
Scheduled → Pulled → Created → Started
PSA violation / denial / forbidden events: 0
```

Admission is therefore proven; the subsequent crash loop is an application
startup failure, not an admission failure.

### Effective security context — PASS

Captured from a short-lived probe Pod running the **same image with the same
securityContext and volumes** under restricted PSA in `utcp-platform` (the
rtpengine container itself exits too quickly to exec into):

```text
uid=1000 gid=1000 groups=1000
CapInh/CapPrm/CapEff/CapBnd/CapAmb: 0000000000000000  (no capabilities)
NoNewPrivs: 1
Seccomp: 2 (SECCOMP_MODE_FILTER), Seccomp_filters: 1   → RuntimeDefault active
overlay / mounted ro; touch / → "Read-only file system"
writable paths: /tmp and /run/rtpengine only (both emptyDir)
/var/run/secrets/kubernetes.io/serviceaccount → does not exist
own cgroup/ipc/mnt/net/pid/uts namespaces (no host namespace sharing)
```

Confirmed on the live rtpengine Pod spec:

```text
pod securityContext:       {runAsUser:1000, runAsGroup:1000, runAsNonRoot:true, seccompProfile:RuntimeDefault}
container securityContext: {allowPrivilegeEscalation:false, capabilities.drop:[ALL], readOnlyRootFilesystem:true}
automountServiceAccountToken: false
hostNetwork / hostPID / hostIPC: unset
hostPort entries: 0    hostPath volumes: 0
volumes: tmp (emptyDir), run (emptyDir)
```

| Requirement | Result |
|---|---|
| UID = 1000 | PASS |
| GID = 1000 | PASS |
| capabilities = none | PASS |
| seccomp = RuntimeDefault | PASS |
| read-only root filesystem = true | PASS |
| service-account token = absent | PASS |
| HostNetwork = false | PASS |
| HostPort absent | PASS |
| HostPath absent | PASS |

### Media-boundary containment — PASS

| Requirement | Result |
|---|---|
| Services exposing `40000–40099` | **none** (only UDP service ports cluster-wide: `kube-dns 53`, `alertmanager-operated 9094`, `rtpengine 2223` — all ClusterIP) |
| NodePort for media | none — no NodePort Service exists anywhere in the cluster |
| LoadBalancer for media | none — the only LoadBalancer is `traefik-system/traefik` on TCP `80`/`443` |
| Gateway / HTTPRoute / Ingress route to media | none — all 6 route objects (`utcp-local` Gateway, 5 HTTPRoutes) reference neither `rtpengine` nor any port in `40000–40099`; `TLSRoute` objects: none; `UDPRoute`/`TCPRoute` CRDs not installed |
| HostPort | none in the Pod spec |
| k3d host publication | `infrastructure/k3d/cluster.yaml` maps only `127.0.0.1:80:80` and `127.0.0.1:443:443` to the loadbalancer filter; unchanged |
| host-namespace UDP socket in `40000–40099` | **none** on any of the three node containers, and **none** on the host |
| application edge | remains TCP `80/443` |

No claim of browser or external RTP reachability is made. An actual RTP
packet-relay session is outside T3-S1.

### Internal metrics protocol — determined

The committed metrics listener (`--listen-http=<POD_IP>:2224`) serves **Prometheus
text exposition over HTTP on `GET /metrics`** (`HTTP/1.0 200 OK`, 218 metric
samples). Other paths return `404 Not Found`. Representative non-sensitive
counters actually exposed:

```text
rtpengine_sessions{type="own"} 0
rtpengine_sessions{type="foreign"} 0
rtpengine_sessions_total 0
rtpengine_uptime_seconds <n>
rtpengine_closed_sessions_total{reason="rejected"|"timeout"|"final_timeout"|"terminated"|…} 0
rtpengine_packets_total{type="userspace"} 0
rtpengine_packet_errors_total{type="userspace"} 0
rtpengine_errors_total{proxy="<IP>"} 0
rtpengine_ports{name="internal",address="<IP>"} 100
rtpengine_ports_free{name="internal",address="<IP>"} 100
rtpengine_ports_used{name="internal",address="<IP>"} 0
```

`rtpengine_ports 100` / `rtpengine_ports_free 100` independently confirm the
`40000–40099` allocation. This was determined on the disposable diagnostic
container; the in-cluster allow/deny corridor is **not** proven (see below).

### Kamailio boundary preservation — PASS

The live Kamailio configuration, the live `kamailio-config` ConfigMap, and the
committed ConfigMap in git are **byte-identical**:

```text
sha256 6e85abaf130018144606e0a235e941e27263181834212c8763bb22f0a489e2e4  (all three, 3983 bytes)
```

| Requirement | Result |
|---|---|
| `rtpengine_offer` / `rtpengine_answer` / `rtpengine_manage` / `rtpengine_delete` | **0 occurrences** in the live config and in every ConfigMap in `utcp-platform` |
| `rtpproxy_*` / `loadmodule … rtpengine.so` | **0 occurrences** |
| SDP rewriting | none |
| dialog-media route | none |
| browser-media route | none |
| conference admission | none |
| silent Asterisk fallback | none — nothing consumes the rtpengine Service |
| `REGISTER` behaviour | unchanged (`save("location", "0x04")` intact at line 95) |
| `Record-Route` / in-dialog handling | absent |

### State-authority preservation — PASS

| Concern | Result |
|---|---|
| Public tables in PostgreSQL | 41 (unchanged) |
| Tables matching `%rtp%` / `%media%` / `%relay%` | **none** — no durable media authority introduced |
| RuntimeNode families / adapters | `asterisk`/`asterisk-ari` (27), `simulator`/`simulator-deterministic` (83) — unchanged |
| RuntimeNodes with an rtpengine family or adapter key | **0** — rtpengine is shared platform infrastructure, not a managed runtime |
| Tenants / users / audit records | 27 / 207 / 4176 |
| Pending outbox | `0` before, `0` after |
| Redis `queues:default` / `:failed` | `0` / `0` before and after |
| Redis keys matching `*rtp*` | none |
| Web-admin configuration / Artisan command surface | unchanged — no code modified |

## Not Proven — blocked by PRODUCT_DEFECT-2

Everything that requires a Ready rtpengine Pod. No claim is made for any of these:

| Criterion | Status |
|---|---|
| Readiness (`available`/`ready` replicas = 1, validated in-cluster `pong`) | **not proven** — `ready=<none>`, `available=<none>`, Pod `0/1 CrashLoopBackOff` |
| Liveness-triggered recovery (`SIGSTOP` → probe failure → kubelet restart → automatic re-readiness) | **not proven** — no running process to suspend; the observed restarts are startup-abort restarts, not liveness-triggered recovery |
| ClusterIP UDP `2223` with one **ready** endpoint | **not proven** — EndpointSlice `rtpengine-6p96v` carries `10.42.1.211` with `ready=false` |
| Authorized control (`ng` ping via `rtpengine.utcp-platform` from the Kamailio identity) | **not proven** — no listener |
| Unauthorized control denial | **not proven** — with no listener, authorized and unauthorized clients are indistinguishable, so a deny result would prove nothing |
| Internal metrics allow from the observability identity, and deny from an unauthorized Pod | **not proven** in-cluster for the same reason (the protocol itself is determined above) |
| Relay-unavailable failure corridor | **not proven** — no healthy baseline exists to induce failure from |
| Automatic restoration | **not proven** — depends on the above |

Structural targeting **is** confirmed: `allow-rtpengine-media` selects the
rtpengine Pod (`utcp.io/network-role: rtpengine-media`) and the live Kamailio Pod
carries the authorized `utcp.io/network-role: kamailio-signaling` label, so the
policy's subject and source identities resolve correctly against real Pods.

## Findings

| Classification | Finding |
|---|---|
| **PRODUCT_DEFECT-2** | The `/tmp` `emptyDir` shadows the image-created `/tmp/rtpengine`, so `--pidfile=/tmp/rtpengine/rtpengine.pid` cannot be created and rtpengine aborts startup (`CRIT: Failed to create PID file`, exit `255`, `CrashLoopBackOff`). All static checks pass because none asserts that entrypoint write paths survive the volume mounts. One-line correction identified and verified |
| PASS | `PRODUCT_DEFECT-1` is confirmed corrected — the pinned asset resolves, both checksums verify, and the pinned image builds |
| PASS | Final image identifies repository revision `87f0b9f`, version `mr26.0.1.19`, upstream commit `3552ac76…`, `amd64`, user `1000:1000`, no embedded credentials |
| PASS | Registry, local, and **running** image digests all match `sha256:bd021530…`; tag `0.1.0-k1-dev`, no `latest` |
| PASS | Only the four T3-S1 resources were applied; no existing workload rebuilt or restarted |
| PASS | Restricted `v1.35` PSA **admits** the Pod with zero violation events |
| PASS | Effective security context matches the ADR-020 §8 contract exactly (UID/GID 1000, no capabilities, RuntimeDefault seccomp, read-only root, no SA token, no host namespaces) |
| PASS | Userspace forwarding, Pod-IP bind/advertisement, exact port range, and a validated `ng` `pong` from the committed readiness helper are all confirmed on the committed image once the shadowed directory exists |
| PASS | Media boundary contained: no NodePort, LoadBalancer, Gateway/Ingress route, HostPort, k3d publication, or host socket for `40000–40099`; edge remains TCP `80/443` |
| PASS | Metrics protocol determined as Prometheus text on `GET /metrics` over TCP `2224`, with session, port, packet, and error counters present |
| PASS | Kamailio config byte-identical to git with zero media-routing directives; `REGISTER` intact; no silent Asterisk fallback |
| PASS | No durable media authority, RuntimeNode, registry capability, tenant, Redis, or outbox change |
| PASS | All nine repository checks pass before and after; working tree clean |
| EXPECTED_BEHAVIOR | Helm absent from this environment; provisioned from the repository's pinned `HELM_VERSION=v4.0.3` with checksum verification and removed at cleanup |
| EXPECTED_BEHAVIOR | Kubernetes API policy-pin drift after a node-IP shuffle; repaired through the canonical renderer for only the three affected generated policies, restoring `utcp-kube-state-metrics` and the Grafana sidecar to Ready |
| PROOF_LIMITATION | Effective security context was captured from a probe Pod using the same image, securityContext, and volumes, because the rtpengine container exits before it can be exec'd into. Admission, spec-level security, and effective identity are all independently confirmed on the real Pod's spec and events |
| PROOF_LIMITATION | Startup, bind, port range, userspace forwarding, `ng` `pong`, and the metrics protocol were verified on a disposable local container running the committed image with the committed entrypoint (only `mkdir -p /tmp/rtpengine` added). This establishes that no second defect is hidden behind `PRODUCT_DEFECT-2`, but it is not in-cluster proof of readiness, liveness recovery, or the NetworkPolicy corridor |
| INTENTIONALLY_INDUCED_CONDITION | None. No failure condition was induced; the crash loop is the defect itself, not an induced condition |
| Unrelated pre-existing condition | `utcp-monitoring-operator` remains `CrashLoopBackOff` (`connection refused` to the Kubernetes Service ClusterIP `10.43.0.1:443`, not a policy timeout). It dials the ClusterIP while `check-apiserver-policy-drift` explicitly forbids pinning a ClusterIP destination — a pre-existing observability integration gap, unrelated to T3-S1 and out of scope |
| Divergence from ADR-020 §9 | The rendered `allow-rtpengine-media` grants **no** cluster-DNS egress, although §9 lists "plus cluster DNS". This is stricter than the ADR and functionally harmless in T3-S1: the entrypoint resolves no hostname and binds only the injected `POD_IP`. It does not invalidate any claim |
| Deferred | No `rtpengine`/media alert rules exist among the 42 live Prometheus rules. ADR-020 §10 requires relay-unavailable, control-failure, and port-exhaustion alerts — deferred observability, not a T3-S1 foundation blocker |

## Environment Preservation

```text
production code changed:       no
Kubernetes manifests changed:  no
dependencies changed:          no
versions.env changed:          no
runtime configuration changed: no
resources applied:             4 (rtpengine ConfigMap, Service, Deployment, allow-rtpengine-media)
generated policies re-applied: 3 (canonical apiserver-egress re-render after node-IP drift)
existing workloads restarted:  no
existing workloads rebuilt:    no
images built:                  1 (rtpengine only)
images pushed:                 1 (rtpengine only)
live media proof run:          no
canonical records mutated:     no
```

All 16 pre-existing `utcp-platform` Pods retain their original creation
timestamps and restart counts (`kamailio` 40, `gateway` 12, `worker` 26,
`web` 0, and so on). `postgres-0` and `redis-0` are unchanged. The only new
workload is `rtpengine`. Observability restart counters advanced on their own
pre-existing crash-loop cadence, and two of the three affected containers
recovered as a result of the canonical policy repair.

## Cleanup

- Both short-lived proof Pods (`t3s1-volume-probe`, `t3s1-security-probe`) deleted; no proof Pod remains in any namespace.
- Disposable local diagnostic container (`t3s1-diag`) removed.
- Provisioned Helm binary, downloaded archive, checksum file, rendered manifests, and extracted artefacts removed from the scratch directory.
- No port-forward was started. `.playwright-mcp/` is absent. No credentials were introduced or recorded.
- APNTalk rtpengine images present in the local Docker cache were **not** used, inspected as a source, or referenced; the clean-room requirement is preserved.
- **T3-S1 resources are intentionally left applied** at `replicas: 1`. They are correct as rendered, and the single remaining defect is a one-line entrypoint change: after the bounded correction, an image rebuild plus `kubectl rollout restart deploy/rtpengine` reaches Ready with no re-apply. Removing them would discard the reproducible failure state this proof establishes. The Pod is **not** healthy and cannot be until the defect is corrected.
- Working tree contains only this evidence document and the roadmap updates.

## T3-S1 Final Status

```text
T3-S1 live foundation proof = INCOMPLETE (blocked by PRODUCT_DEFECT-2)
T3 = In Progress
UTCP_PHASE = T1 (unchanged)
```

## Next Exact T3 Target

One bounded Codex correction for `PRODUCT_DEFECT-2`: change
`infrastructure/docker/rtpengine/entrypoint` line 44 to
`--pidfile=/run/rtpengine/rtpengine.pid` (the already-mounted, already-writable
`emptyDir`), and extend `scripts/media/config-check` so an entrypoint write path
that the volume mounts shadow fails statically. Then resume this live proof from
step 4 (rebuild the image at the new commit, push, `rollout restart`) and execute
the still-unproven corridor: readiness, liveness-triggered recovery, ClusterIP
endpoint readiness, authorized control, unauthorized denial, metrics allow/deny,
relay-unavailable failure, and automatic restoration.

Do not broaden the correction into Kamailio media routing, browser SIP,
conference admission, V0, T4, external trunks, or PSTN.
