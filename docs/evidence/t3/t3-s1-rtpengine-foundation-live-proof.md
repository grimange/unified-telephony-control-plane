# T3-S1 — rtpengine Foundation Live Proof

Verdict: `T3_S1_RTPENGINE_FOUNDATION_LIVE_PROOF_INCOMPLETE`

This document supersedes the proof attempt recorded at `bc15667`, which was
blocked at container startup by `PRODUCT_DEFECT-2` (the `/tmp` `emptyDir`
shadowed the image-created `/tmp/rtpengine` pidfile directory). That defect is
corrected in `812c6ec` and is **confirmed resolved live**.

The resumed proof executed the entire remaining corridor. `PRODUCT_DEFECT-1`
and `PRODUCT_DEFECT-2` are both confirmed corrected, and the relay itself is now
**healthy, admitted, Ready, and self-recovering in `utcp-local`**: the corrected
image builds and pushes, only rtpengine is restarted, startup succeeds on
`/run/rtpengine/rtpengine.pid`, restricted PSA admits the Pod, the effective
security context matches ADR-020 §8 exactly, the Pod IP owns bind and
advertisement, readiness validates a real `ng` `pong`, a liveness failure causes
automatic container recovery, the media boundary stays inside the cluster,
relay unavailability fails visibly with no fallback, restoration is fully
automatic, the Kamailio boundary is byte-identical to git, and no durable media
authority appears anywhere.

The proof stopped at one new, exact, reproducible defect:
**`PRODUCT_DEFECT-3` — the two authorized rtpengine consumers have no
reciprocal source-side egress rule, so both authorized corridors that
`allow-rtpengine-media` declares are unusable end-to-end under default-deny.**
Per the proof contract, no production file was modified to work around it.

**T3-S1 remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

- Proof executed at `812c6ec` (`fix(t3): use writable rtpengine pidfile path`).
- Branch `main`, working tree clean at start and at finish, `UTCP_PHASE=T1`, nothing pushed.
- Authority: [`ADR-020`](../../decisions/ADR-020-t3-rtp-media-plane.md),
  [`t3-rtp-media-preparation-audit.md`](t3-rtp-media-preparation-audit.md),
  [`t3-s1-rtpengine-foundation-implementation.md`](t3-s1-rtpengine-foundation-implementation.md),
  [`t3-s1-rtpengine-package-asset-correction.md`](t3-s1-rtpengine-package-asset-correction.md),
  [`t3-s1-rtpengine-pidfile-correction.md`](t3-s1-rtpengine-pidfile-correction.md).

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

The committed entrypoint carries exactly one PID argument,
`--pidfile=/run/rtpengine/rtpengine.pid`, and no PID path remains under `/tmp`.

## PRODUCT_DEFECT-3 — authorized rtpengine corridors have no reciprocal source egress

`allow-rtpengine-media` correctly permits **ingress** from both authorized
identities. Neither identity has a matching **egress** rule, and `default-deny`
selects every Pod in both source namespaces, so both authorized corridors that
ADR-020 declares are denied at the source before they ever reach rtpengine.

| Field | Value |
|---|---|
| Seam A | [`infrastructure/kubernetes/security/platform/allow-kamailio-signaling.yaml`](../../../infrastructure/kubernetes/security/platform/allow-kamailio-signaling.yaml) — `egress` permits only DNS (`kube-system`) and PostgreSQL `5432`; no rule for rtpengine `2223/UDP` |
| Seam B | [`infrastructure/kubernetes/observability/network-policies/allow-application-metrics.yaml`](../../../infrastructure/kubernetes/observability/network-policies/allow-application-metrics.yaml) — `allow-prometheus-egress-to-application-metrics` permits only `gateway:8081/TCP`; no rule for rtpengine `2224/TCP` |
| Expected | The Kamailio identity reaches `rtpengine.utcp-platform:2223/UDP` and receives `result=pong`; the Prometheus identity reaches rtpengine `2224/TCP` and receives Prometheus text |
| Actual | Kamailio → `2223/UDP` times out (`6.01s`); Prometheus → `2224/TCP` returns `ConnectionRefused` (`0.00s`) |
| Static checks | **All passed** — `scripts/media/config-check` and `scripts/security/config-check` assert `allow-rtpengine-media` alone (its selectors, ports, and forbidden destinations). Neither asserts that a declared ingress source actually holds reciprocal egress, so the unusable corridor is invisible to static coverage |
| Blast radius | Both T3-S1 authorized corridors: ng control and internal metrics |

### Attribution — the denial is source egress, not rtpengine ingress

Each source was first shown to have a **working** egress path to a destination
its own policy permits, then shown to fail only for rtpengine:

| Source | Destination its policy allows | Result | rtpengine destination | Result |
|---|---|---|---|---|
| `kamailio-679bd6bf59-zn6f4` (`utcp.io/network-role: kamailio-signaling`) | `postgres.utcp-data:5432` | reachable, `0.00s` | `rtpengine.utcp-platform:2223/UDP` | **blocked**, `TimeoutError` at `6.01s` |
| `prometheus-utcp-monitoring-prometheus-0` (`app.kubernetes.io/name: prometheus`) | `gateway.utcp-platform:8081` | reachable, `0.01s` | rtpengine Pod IP `:2224/TCP` | **blocked**, `ConnectionRefused` at `0.00s` |

The metrics listener itself is proven healthy from inside the workload (below),
so seam B is a policy denial and not a listener defect. Additionally, **no
`ServiceMonitor` or `PodMonitor` for rtpengine exists** among the eight live
monitors, so rtpengine is not yet a scrape target at all — the same gap against
ADR-020 §10, one layer higher.

### Smallest bounded correction

1. Add one egress rule to `allow-kamailio-signaling.yaml` permitting `2223/UDP`
   (and `40000–40099/UDP` for return media) to the `utcp.io/network-role:
   rtpengine-media` pod selector in `utcp-platform`.
2. Add one egress rule to `allow-application-metrics.yaml` permitting
   `2224/TCP` to the same selector.
3. Extend `scripts/media/config-check` so a declared `allow-rtpengine-media`
   ingress source without a reciprocal egress rule fails statically, and add the
   matching mutation cases to `scripts/media/config-check-test`.
4. Add the rtpengine scrape target required by ADR-020 §10.

This is a NetworkPolicy and static-check correction only. It must not broaden
into Kamailio media routing, browser SIP, conference admission, V0, T4, external
trunks, or PSTN.

## Repository Checks

Run before the rollout and again at the end; every check passed both times.

```text
make repository-hygiene        passed
make workflow-check            passed
make secret-scan               passed
make k8s-config-check          passed
make security-config-check     passed
make media-config-check        passed
make media-config-check-test   passed
make check                     passed (exit 0)
make gateway-config-check      passed
git diff --check               clean
git diff --cached --check      clean
```

Helm was absent from this environment and was provisioned temporarily from the
repository pin `HELM_VERSION=v4.0.3` through the established checksum-verified
process (`helm-v4.0.3-linux-amd64.tar.gz: OK`), then removed during cleanup.

## Live Baseline

```text
kubeconfig: .runtime/kubeconfig/utcp-local.yaml
context:    k3d-utcp-local
namespace:  utcp-platform
nodes:      k3d-utcp-local-server-0 / agent-0 / agent-1, all Ready, all amd64, v1.35.3+k3s1
PSA:        enforce=restricted v1.35, audit=restricted v1.35, warn=restricted v1.35
```

The pre-rollout rtpengine Pod confirmed the defect state still corresponded to
the **old** image and the **old** PID path:

| Field | Value |
|---|---|
| Pod | `rtpengine-557b9cbdd7-pqnqp`, UID `3e6176c2-559c-4e8c-848e-65a502ef587a` |
| imageID | `utcp-local-registry:5000/utcp/rtpengine@sha256:bd021530…` (pre-correction) |
| restart count | 91, `started=false`, `Ready=false` |
| last state | `terminated`, `exitCode 255`, `reason Error` |
| logs | `CRIT: [core] Failed to create PID file (No such file or directory), aborting startup` |
| Deployment | `replicas 1`, `readyReplicas <none>` |
| EndpointSlice | `rtpengine-6p96v`, one endpoint `10.42.1.211`, `ready=false`, `serving=false` |

Baseline state authority and platform facts:

```text
database public tables:          41
tables matching rtp/media:       (none)
tenants:                         27
runtime_nodes:                   110  (asterisk/asterisk-ari 27, simulator/simulator-deterministic 83)
rtpengine RuntimeNode records:   0
pending outbox:                  0
redis db0 keys:                  1 (scheduler cache key, TTL-bearing); db1: 0
redis keys matching rtp:         0
failed Jobs:                     0  (utcp-migrate succeeded)
Services with NodePort/LB:       1  (traefik LoadBalancer, TCP 80/443 only)
k3d host publications:           127.0.0.1:80, 127.0.0.1:443, 127.0.0.1:6550, registry 127.0.0.1:5001
NetworkPolicies (utcp-platform): 18, including default-deny and allow-rtpengine-media
```

**Kubernetes API policy-pin drift: none.**
`scripts/security/check-apiserver-policy-drift` passed with
`endpoint=172.24.0.2/32:6443`, matching the live `kubernetes` endpoint, so no
generated policy needed re-rendering during this proof.

## Final Image Provenance

```bash
make image-build-rtpengine UTCP_BUILD_COMMIT=812c6ec
```

| Field | Value |
|---|---|
| local tag | `utcp-rtpengine:dev` |
| image ID / index digest | `sha256:33cf7e2e5d1987b26893bd4a78c68050cfbc8fc6824723818bfd14a0c60c2f58` |
| `linux/amd64` manifest digest | `sha256:ad8c7e02aa50a73ab79a1b06338f11a874876ff956cf3289171716d655f72778` |
| config digest | `sha256:91713bc143219422e1d003a43dba7d2e78b4bdc5a54997524b3c285a7e2d48b4` |
| `org.opencontainers.image.revision` | **`812c6ec`** |
| `org.opencontainers.image.version` | `0.1.0-dev` |
| architecture / os | `amd64` / `linux` |
| configured user | **`1000:1000`** |
| entrypoint | `/usr/local/bin/utcp-rtpengine-entrypoint` |
| rtpengine version | `26.0.1.19+0~mr26.0.1.19` (`dpkg`: `rtpengine-daemon 26.0.1.19+0~mr26.0.1.19+gh+trixie amd64`) |
| upstream commit | `3552ac76cceb24e3ec176b77ec9c25554ae5923b` (recorded in image history) |
| pidfile in image | `--pidfile=/run/rtpengine/rtpengine.pid` (line 44, single occurrence) |
| `/run/rtpengine` in image | `drwxr-xr-x 2 1000 1000` |

Package checksum layer provenance — the build layer records both the pinned
asset checksum and the verification step:

```text
c60c7a1463e454dbcff81bf0fbd07c65dbeac742e5997d0c611f40f09161f950
sha256sum --check
mr26.0.1.19
3552ac76cceb24e3ec176b77ec9c25554ae5923b
```

Embedded-credential scan: `Config.Env` is `["PATH=…"]` only; a filesystem sweep
for `*.pem`, `*.key`, `id_rsa*`, `.netrc`, and `*.p12` outside the CA trust store
returned only `/usr/lib/ssl/cert.pem` (the Debian CA bundle). **No credential is
embedded.**

The revision is not `unknown` and does not identify an earlier commit.

## Registry Push Result

Pushed rtpengine **only**, using the canonical library's own references
(`scripts/kubernetes/lib` — `required_tools`, `ensure_registry`, `check_k1_tag`,
`RTPENGINE_LOCAL_IMAGE`, `RTPENGINE_HOST_IMAGE`), because
`scripts/kubernetes/image-push` pushes all five images and this proof must not
republish `api`, `web`, `gateway`, or `asterisk-ari`.

| Field | Value |
|---|---|
| registry reference | `127.0.0.1:5001/utcp/rtpengine:0.1.0-k1-dev` |
| in-cluster reference | `utcp-local-registry:5000/utcp/rtpengine:0.1.0-k1-dev` |
| tag | `0.1.0-k1-dev` (`K1_IMAGE_TAG`) — **no `latest`** |
| pushed index digest | `sha256:33cf7e2e5d1987b26893bd4a78c68050cfbc8fc6824723818bfd14a0c60c2f58` |
| `Docker-Content-Digest` header | identical (`sha256:33cf7e2e…`) |
| `linux/amd64` platform digest | `sha256:ad8c7e02aa50a73ab79a1b06338f11a874876ff956cf3289171716d655f72778` |
| local image digest | identical (`sha256:33cf7e2e…`) |
| registry reachability | `GET /v2/_catalog` → `200`; `utcp/rtpengine` present; tags `["0.1.0-k1-dev"]` |
| pull-back verification | `docker pull` returned the same digest |

## rtpengine-Only Rollout

The committed `imagePullPolicy` is **`Always`**
(`rtpengine-deployment.yaml:33`), so the node pulled the corrected digest on the
new Pod without any cache intervention. No manifest changed in `812c6ec`, so
**nothing was re-applied** — only `rollout restart deployment/rtpengine`.

```text
restart issued:  2026-07-28T20:57:08Z
rollout settled: 2026-07-28T20:57:11Z   (successfully rolled out, exit 0)
```

| | Old Pod | New Pod |
|---|---|---|
| name | `rtpengine-557b9cbdd7-pqnqp` | `rtpengine-74cd786966-x2lqm` |
| UID | `3e6176c2-559c-4e8c-848e-65a502ef587a` | `d611728c-7714-46ec-a0a1-dfabf028e907` |
| imageID | `…@sha256:bd021530…` | **`…@sha256:33cf7e2e…`** |
| Pod IP | `10.42.1.211` | `10.42.1.214` |
| ready / restarts | `false` / 92 | `true` / 0 |

**The running `imageID` matches the pushed registry digest exactly** — this is
content-digest equality, not tag equality.

## Preserved Workloads

A full-cluster Pod snapshot (name, UID, restart count) was taken before and
after the rollout. The diff contains **exactly one line pair** — the rtpengine
Pod replacement. All **34** other Pods retained their original UIDs and restart
counts. No unrelated workload was rebuilt, re-applied, or restarted.

## Startup and PID-File Result

```text
INFO: [core] Version 26.0.1.19+0~mr26.0.1.19 initialising
INFO: [http] Websocket listener thread running
INFO: [core] Startup complete, version 26.0.1.19+0~mr26.0.1.19
```

**No PID-file error appears.** Process arguments (`/proc/1/cmdline`):

```text
/usr/bin/rtpengine --foreground --pidfile=/run/rtpengine/rtpengine.pid \
  --table=-1 --listen-ng=10.42.1.214:2223 \
  --interface=internal/10.42.1.214!10.42.1.214 \
  --port-min=40000 --port-max=40099 \
  --listen-http=10.42.1.214:2224 --log-stderr
```

| Requirement | Result |
|---|---|
| `/run/rtpengine/rtpengine.pid` exists | `-rw-r--r-- 1 1000 1000 2` |
| PID file writable and correctly owned | directory writable; owner `1000:1000`; content `1` (rtpengine is PID 1) |
| `/tmp/rtpengine` required | **no** — `/tmp` is an empty `emptyDir` at runtime and startup succeeds regardless |
| `--table=-1` (userspace forwarding) | active |
| `--listen-ng=<Pod IP>:2223` | active |
| `--interface=internal/<Pod IP>!<Pod IP>` | active |
| `--port-min=40000` / `--port-max=40099` | active |
| `--listen-http=<Pod IP>:2224` | active |
| kernel-module or privileged init | **none attempted** — no kernel-table message in the logs |

## Pod Security Admission Result

Admitted under the existing restricted labels with **zero violation, warning, or
audit events**. Event sequence: `Scheduled` → `Pulling` → `Pulled` (1.075s) →
`Created` → `Started`. The ReplicaSet recorded only `SuccessfulCreate`.

## Effective Security Context

Captured from `/proc/1/status` and the mount table **of the real running
workload** — no substitute probe Pod was used.

| Control | Required | Observed |
|---|---|---|
| UID | `1000` | `Uid: 1000 1000 1000 1000` |
| GID | `1000` | `Gid: 1000 1000 1000 1000` |
| capabilities | none | `CapInh/CapPrm/CapEff/CapBnd/CapAmb` all `0000000000000000` |
| `NoNewPrivs` | enabled | `NoNewPrivs: 1` |
| seccomp | `RuntimeDefault` | `Seccomp: 2` (filter mode), `Seccomp_filters: 1` |
| root filesystem | read-only | `overlay / overlay ro,relatime` |
| writable paths | declared volumes only | `/tmp` and `/run/rtpengine` (both `emptyDir`), plus the kubelet-managed `/etc/hosts` |
| service-account token | absent | `/var/run/secrets/kubernetes.io/serviceaccount`: **No such file or directory** |
| namespace sharing | none | `hostNetwork`, `hostPID`, `hostIPC`, `shareProcessNamespace` all unset |
| HostPort | absent | no container port declares `hostPort` |
| HostPath | absent | volumes are `tmp` and `run`, both `emptyDir` |

## Pod-IP Bind and Advertisement

| Field | Value |
|---|---|
| actual Pod IP | `10.42.1.214` |
| downward API `POD_IP` | `10.42.1.214` |
| `listen-ng` | `10.42.1.214:2223` |
| internal/advertised media interface | `internal/10.42.1.214!10.42.1.214` |
| metrics `listen-http` | `10.42.1.214:2224` |
| observed UDP sockets | `('10.42.1.214', 2223)` — **only** |
| observed TCP listeners | `('10.42.1.214', 2224)` and `('127.0.0.1', 2224)` |

No node IP, Service IP, `0.0.0.0` advertisement, or hard-coded developer-host IP
appears anywhere in the bind or advertisement set. The additional
`127.0.0.1:2224` socket is rtpengine's own loopback control listener (it answers
`Unknown command: GET`, not the metrics exposition); it lives inside the Pod
network namespace, is not an advertised address, and is unreachable from outside
the Pod. Classified `EXPECTED_BEHAVIOR`.

Media ports `40000–40099` are not bound at rest — rtpengine allocates them per
session, and T3-S1 establishes no media session.

## Readiness Result

| Field | Value |
|---|---|
| Deployment | `replicas 1`, `readyReplicas 1`, `availableReplicas 1` |
| Pod `Ready` | `True` at `2026-07-28T20:57:11Z` |
| container started | `2026-07-28T20:57:10Z` |
| **time from container start to Ready** | **≈1 second** |
| EndpointSlice | `10.42.1.214`, `ready=true`, `serving=true` |
| probe command | `/usr/local/bin/utcp-rtpengine-ng-ping` (committed readiness helper) |
| probe result | exit `0` |

A raw `ng` request with a **unique cookie** (bypassing rtpengine's duplicate
suppression) returned a freshly computed reply:

```text
request:  utcp-proof-s10 d7:command4:pinge
response: utcp-proof-s10 d6:result4:ponge   => result=pong
```

Process existence alone was not accepted as evidence.

## Liveness and Automatic Recovery

Classified `INTENTIONALLY_INDUCED_CONDITION`.

Pre-condition: Pod `rtpengine-74cd786966-x2lqm`, UID
`d611728c-7714-46ec-a0a1-dfabf028e907`, container `containerd://8236339d…`,
restart count `0`, `Ready=true`. `/proc/1/exe` resolves to `/usr/bin/rtpengine`
and is owned by UID `1000`, confirming rtpengine is PID 1.

`SIGSTOP` was sent to that single process (host PID `205051`) **from the node's
PID namespace**. A PID-namespace init ignores `SIGSTOP` sent from inside its own
namespace, so an ancestor-namespace sender is required to induce the condition at
PID 1 as specified. No probe was patched and the Pod was not deleted.

```text
node view before: State: S (sleeping)
kill -STOP 205051   @ 2026-07-28T20:59:37Z
node view after:  State: T (stopped)
```

| Step | Required | Observed |
|---|---|---|
| 1 | real `ng` liveness probe fails | `Liveness probe failed: … TimeoutError: timed out` at `21:00:11Z` |
| 2 | Kubernetes records the failure | `Warning Unhealthy`, then `Normal Killing — Container rtpengine failed liveness probe, will be restarted` |
| 3 | kubelet restarts the container | container terminated `exitCode 137`; new container `containerd://5b23f5e5…` started `21:00:41Z` |
| 4 | restart count increases | `0` → **`1`** |
| 5 | readiness returns automatically | `Ready=true` again by `21:00:46Z`, no intervention |
| 6 | PID file recreated | `-rw-r--r-- 1 1000 1000 2 … /run/rtpengine/rtpengine.pid` |
| 7 | `ng` ping returns `pong` | `utcp-proof-s11 d6:result4:ponge` |
| 8 | Deployment stays at one replica | `replicas 1`, `readyReplicas 1` |

Readiness had already flipped to `false` at `20:59:56Z`, so the endpoint was
withdrawn before the liveness threshold was reached. The **Pod UID is unchanged**
— the container was restarted in place rather than the Pod being replaced. The
stopped host process is gone.

## Control Service Result

| Requirement | Observed |
|---|---|
| type | `ClusterIP` (`10.43.50.16`) |
| protocol | `UDP` |
| port | `2223` |
| targetPort | `ng` |
| ready endpoint count | `1` |
| endpoint IP | current rtpengine Pod IP |
| external IP / NodePort / LoadBalancer / Gateway | **none** |

The Service declares exactly one port and no `externalIPs`, `nodePort`, or
`loadBalancer` field.

## Authorized Control Result

**FAILED — `PRODUCT_DEFECT-3`.**

Using the existing Kamailio Pod (which carries `python3`, so no debug container
was needed):

```text
source:       kamailio-679bd6bf59-zn6f4
source label: utcp.io/network-role: kamailio-signaling   (exactly the permitted ingress identity)
DNS:          rtpengine.utcp-platform -> 10.43.50.16     (resolves)
ng ping:      rtpengine.utcp-platform:2223/UDP -> TimeoutError after 6.01s
```

NetworkPolicies selecting the destination: `allow-rtpengine-media` (**allows**
`2223/UDP` from this exact selector) and `default-deny`.
NetworkPolicies selecting the source: `allow-kamailio-signaling-required-traffic`
(egress = DNS + PostgreSQL `5432` only) and `default-deny`.

The same Pod reached `postgres.utcp-data:5432` in `0.00s`, proving its egress
path is functional and that the denial is specific to the missing rtpengine
egress rule. No proof-only allow policy was added and `allow-rtpengine-media` was
neither weakened nor patched. Production Kamailio configuration was not modified.

## Unauthorized Control Denial

A short-lived unauthorized Pod was created in the `default` namespace, which has
**zero NetworkPolicies**, so its egress is unrestricted and the destination's
ingress policy is isolated as the only possible cause of denial.

```text
source:          utcp-t3s1-unauthorized-proof (default namespace, 10.42.1.215)
labels:          utcp.io/proof: t3-s1-unauthorized   (no kamailio-signaling identity)
egress validity: DNS resolved rtpengine.utcp-platform -> 10.43.50.16 in 0.01s
ng ping:         rtpengine.utcp-platform:2223/UDP -> TimeoutError after 8.01s  (bounded)
```

| Source | Source egress valid | Destination ingress expected | Actual |
|---|---:|---:|---|
| Authorized Kamailio identity | **no** — `allow-kamailio-signaling-required-traffic` omits rtpengine | allow | **denied at source egress** (`PRODUCT_DEFECT-3`) |
| Unauthorized identity | yes (`default` ns, unrestricted) | deny | **denied**, bounded `TimeoutError` |

The unauthorized row is a clean, isolated proof of rtpengine's ingress policy.
The authorized row could not be isolated with existing policy authority, so the
exact policy interaction is documented above rather than worked around.

## Media-Boundary Containment

| Surface | Result |
|---|---|
| NodePort | none anywhere for `2223` or `40000–40099` |
| LoadBalancer | only `traefik-system/traefik`, TCP `80`/`443` |
| Gateway / HTTPRoute / TLSRoute / TCPRoute / UDPRoute / Ingress | **zero resources exist cluster-wide** |
| HostPort | only `svclb-traefik` `80`/`443` TCP |
| HostPath | none on rtpengine |
| k3d host publication | `127.0.0.1:80`, `127.0.0.1:443` (+ registry `5001`, apiserver `6550`); `infrastructure/k3d/cluster.yaml` publishes nothing else |
| node sockets | all four k3d containers scanned across `/proc/net/{udp,udp6,tcp,tcp6}` — **no socket on `2223`, `2224`, or `40000–40099`** |
| developer-host sockets | `ss -lunp` / `ss -ltnp` — none on `2223`, `2224`, or `40000–40099`; `127.0.0.1:2223` unreachable from the host |

The established public application edge remains TCP `80/443`. No external or
browser RTP reachability is claimed, and no RTP offer/answer media session was
established — that remains outside T3-S1.

## Internal Metrics Result

**FAILED — `PRODUCT_DEFECT-3`.**

Using the real observability identity — an ephemeral debug container attached to
the running Prometheus Pod, sharing its network namespace and therefore its exact
policy identity (`app.kubernetes.io/name: prometheus`, Pod IP `10.42.2.115`):

```text
prometheus -> rtpengine Pod IP :2224/TCP  ->  ConnectionRefused after 0.00s
prometheus -> gateway.utcp-platform:8081  ->  reachable in 0.01s   (egress path is functional)
```

`allow-rtpengine-media` **allows** `2224/TCP` from this exact selector.
`allow-prometheus-egress-to-application-metrics` permits only `gateway:8081`, and
`allow-observability-required-traffic` permits only DNS, intra-namespace ports,
and `traefik:9100`. No rule covers rtpengine.

**The listener itself is healthy.** Queried from inside the workload (which
bypasses NetworkPolicy), rtpengine returns valid Prometheus text exposition:

```text
HTTP/1.0 200 OK
content-type: text/plain
content-length: 21139
total metric samples: 218

rtpengine_ports{name="internal",address="10.42.1.216"}       100
rtpengine_ports_free{name="internal",address="10.42.1.216"}  100
rtpengine_ports_used{name="internal",address="10.42.1.216"}    0
rtpengine_sessions{type="own"}                                 0
rtpengine_sessions{type="foreign"}                             0
rtpengine_sessions_total                                       0
rtpengine_uptime_seconds                                      17
```

**The port counters correspond exactly to the bounded `40000–40099` range** —
`rtpengine_ports = 100` and `rtpengine_ports_free = 100` for the `internal`
interface, labelled with the Pod IP. Session, port, and uptime counters are all
present among the 218 samples.

No public metrics Service, route, or dashboard was added.

## Unauthorized Metrics Denial

From the same unauthorized `default`-namespace Pod with a valid egress path:

```text
unauthorized -> rtpengine Pod IP :2224/TCP -> ConnectionRefused after 0.00s (bounded)
```

| Source | Expected | Actual |
|---|---|---|
| Authorized observability identity | allow | **denied at source egress** (`PRODUCT_DEFECT-3`) |
| Unauthorized identity | deny | **denied**, bounded `ConnectionRefused` |

TCP denials surface as an immediate `ConnectionRefused` and UDP denials as a
bounded timeout; both are the CNI's enforcement of the same default-deny posture.
No NetworkPolicy was altered for proof convenience.

## Relay-Unavailable Failure

Classified `INTENTIONALLY_INDUCED_CONDITION`. Only `deployment/rtpengine` was
scaled to zero, at `2026-07-28T21:07:02Z`.

| Requirement | Observed |
|---|---|
| no ready rtpengine endpoint remains | EndpointSlice `endpoints=null`; Deployment `0/0/0`; no rtpengine Pod exists |
| Service survives | `rtpengine` ClusterIP `10.43.50.16` retained; DNS still resolves |
| control ping fails visibly within a bounded period | `TimeoutError` after `8.01s` |
| no fallback to Asterisk | `asterisk-ari` (13) and `asterisk-ari-b` (12) restart counts and UIDs identical to baseline; `asterisk-ari-events` unchanged at 3 |
| database table count unchanged | `41` → `41` |
| no media table appears | tables matching `rtp`/`media`: `(none)` |
| RuntimeNode count and capabilities unchanged | `110`; families still `asterisk/asterisk-ari` + `simulator/simulator-deterministic`; rtpengine records `0` |
| outbox and Redis domain state unchanged | pending outbox `0`; Redis keys matching `rtp` = `0` |
| no other Pod restarts | full-cluster diff showed only the removal of the rtpengine Pod |

No production SIP or RTP traffic was initiated.

## Restoration Result

`deployment/rtpengine` scaled back to one at `2026-07-28T21:08:03Z`; rollout
completed at `21:08:05Z`.

| Requirement | Observed |
|---|---|
| new Pod admission succeeds | `rtpengine-74cd786966-hvcrn`, UID `fea2c8fc-1f4d-4e5c-ae96-56528349a633`, no PSA violation |
| restricted security remains effective | `uid=1000 gid=1000`, `CapEff 0000000000000000`, `NoNewPrivs 1`, `Seccomp 2` |
| startup succeeds using `/run/rtpengine/rtpengine.pid` | `-rw-r--r-- 1 1000 1000 2`, created `21:08` |
| Pod-IP authority re-derived | new Pod IP `10.42.1.216`; every argument (`listen-ng`, `interface`, `listen-http`) rebound to it |
| readiness returns automatically | `ready=true`, `restarts=0`, started `21:08:04Z` |
| EndpointSlice becomes Ready | `10.42.1.216`, `ready=true` |
| authorized `ng` ping | `utcp-proof-s19 d6:result4:ponge` from the workload |
| authorized control corridor | still **denied** — `PRODUCT_DEFECT-3` unchanged |
| unauthorized control | still denied (`TimeoutError`, `6.01s`) |
| authorized metrics | still **denied** — `PRODUCT_DEFECT-3` unchanged |
| unauthorized metrics | still denied (`ConnectionRefused`) |
| manual reconciliation / projection / repair | **none required** |
| unrelated workload restarts | none caused by restoration |
| running image digest | `sha256:33cf7e2e…`, unchanged |

rtpengine is left at one Ready replica.

## Kamailio Boundary Preservation

| Comparison | Result |
|---|---|
| Git `kamailio-configmap.yaml` `data` vs live ConfigMap | **byte-identical**, `sha256 6e85abaf13001814…` on both sides |
| Running `/etc/kamailio/kamailio.cfg` inside the Pod | `sha256 6e85abaf130018144606e0a235e941e27263181834212c8763bb22f0a489e2e4` — identical to git |
| `rtpengine_offer` / `rtpengine_answer` / `rtpengine_manage` / `rtpengine_delete` | **0 occurrences each** |
| `rtpproxy` / `set_rtp_proxy` / any `rtpengine_` prefix | **0 occurrences** |
| `REGISTER` handling | present and unchanged |
| SDP rewriting (`sdp` / `SDP`) | **0 occurrences** |
| dialog-media route (`dialog`) | **0 occurrences** |
| browser-media / conference admission (`conference`) | **0 occurrences** |
| Asterisk fallback (`fallback`) | **0 occurrences** |
| Kamailio Pod | UID `843bf4db-…` and restart count `40` unchanged from baseline |

The relay carried only probe and proof traffic.

## State-Authority Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `rtp` or `media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes | 110 | **110** |
| rtpengine RuntimeNode records | 0 | **0** |
| registry families / adapter keys | `asterisk/asterisk-ari`, `simulator/simulator-deterministic` | **unchanged** |
| pending outbox | 0 | **0** |
| Redis keys containing `rtp` | 0 | **0** |
| Redis keys containing `media` | — | **0** |
| web-admin settings referencing rtpengine | none | **none** |
| Artisan command surface for media | none | **none** |
| durable media authority in `apps/api` | none | **none** |

Redis `db0` moved `1 → 2 → 0` across the proof. The only key present is the
Laravel scheduler's TTL-bearing cache entry; this is expiry churn, not domain
state. No new durable media authority and no alternate management path appeared.

## Findings

| Classification | Finding |
|---|---|
| **PRODUCT_DEFECT-3** | Neither authorized rtpengine consumer has a reciprocal source egress rule, so both corridors `allow-rtpengine-media` declares are unusable under default-deny. Kamailio → `2223/UDP` times out; Prometheus → `2224/TCP` is refused. Each source was independently shown to reach a destination its own policy permits, isolating the cause to the missing egress rules. Static checks pass because they assert only the rtpengine-side policy. No rtpengine `ServiceMonitor`/`PodMonitor` exists either |
| PASS | `PRODUCT_DEFECT-1` and `PRODUCT_DEFECT-2` are both confirmed corrected live — the pinned image builds, both checksums verify, and rtpengine starts cleanly on `/run/rtpengine/rtpengine.pid` |
| PASS | Final image identifies repository revision `812c6ec`, rtpengine `mr26.0.1.19`, upstream commit `3552ac76…`, `amd64`, user `1000:1000`, no embedded credentials |
| PASS | Registry, local, and **running** image digests all match `sha256:33cf7e2e…`; `linux/amd64` platform digest `sha256:ad8c7e02…`; tag `0.1.0-k1-dev`, no `latest` |
| PASS | Only rtpengine was restarted; all 34 other Pods retained UID and restart count; no manifest re-applied |
| PASS | Startup succeeds with no PID-file error; `/tmp/rtpengine` is not required; `--table=-1` userspace forwarding active; no kernel-module or privileged initialization attempted |
| PASS | Restricted `v1.35` PSA admits the Pod with zero violation events |
| PASS | Effective security context, captured from the **real running workload**, matches ADR-020 §8 exactly |
| PASS | Pod IP owns bind and advertisement; no node IP, Service IP, `0.0.0.0`, or developer-host IP |
| PASS | Readiness validates a real `ng` `pong` (unique-cookie request, freshly computed reply) ≈1s after container start; EndpointSlice Ready |
| PASS | A liveness failure induced by `SIGSTOP` on PID 1 causes kubelet to restart the container automatically; readiness, PID file, and `ng` `pong` all return with the Deployment still at one replica |
| PASS | ClusterIP UDP `2223` carries exactly one Ready endpoint equal to the current Pod IP; no external exposure |
| PASS | Unauthorized control and unauthorized metrics are both denied with bounded failures, isolated through a `default`-namespace source with unrestricted egress |
| PASS | Media boundary contained: no NodePort, LoadBalancer, Gateway/Ingress route, HostPort, k3d publication, node socket, or host socket for `40000–40099`; edge remains TCP `80/443` |
| PASS | The metrics listener serves valid Prometheus text with port counters matching the bounded `40000–40099` range exactly (`rtpengine_ports = 100`) |
| PASS | Relay unavailability produces a visible bounded failure with no Asterisk fallback and no canonical state change |
| PASS | Restoration is fully automatic; no manual reconciliation, projection, or repair command was required |
| PASS | Kamailio config byte-identical to git with zero media-routing directives; `REGISTER` intact |
| PASS | No durable media authority, RuntimeNode, registry capability, tenant, Redis, outbox, web-admin, or Artisan surface changed |
| PASS | All nine repository checks pass before and after; working tree clean |
| EXPECTED_BEHAVIOR | rtpengine additionally binds `127.0.0.1:2224` as its own loopback control listener. It is inside the Pod network namespace, is not an advertised address, and answers `Unknown command: GET` rather than the metrics exposition. Not an exposure |
| EXPECTED_BEHAVIOR | The readiness/liveness helper uses a fixed `ng` cookie, so rtpengine logs `Detected command … as a duplicate` between probes and replies from its duplicate cache. A stopped process still produces no reply, which the liveness proof confirms empirically |
| EXPECTED_BEHAVIOR | Helm absent from this environment; provisioned from the repository pin `HELM_VERSION=v4.0.3` with checksum verification and removed at cleanup |
| EXPECTED_BEHAVIOR | `worker-55fdb7d5f6-jg2x5` restart count advanced 33 → 34 during the proof. The container exited `0` (`reason: Completed`) after exactly `3600s`, which is the designed `--max-time=3600` self-exit in `infrastructure/docker/api/entrypoint:58`. Same Pod UID; unrelated to rtpengine |
| INTENTIONALLY_INDUCED_CONDITION | `SIGSTOP` to the rtpengine process (PID 1, host PID `205051`) to trigger the real liveness probe. The process was reaped by the kubelet restart; no residue |
| INTENTIONALLY_INDUCED_CONDITION | `deployment/rtpengine` scaled to zero to prove relay-unavailable failure, then restored to one replica |
| PROOF_LIMITATION | The **authorized** halves of the control and metrics corridors could not be exercised at all, because `PRODUCT_DEFECT-3` denies them at the source and the proof contract forbids adding a proof-only allow policy. The destination-side ingress policy is proven only in its deny direction |
| PROOF_LIMITATION | The relay-unavailable failure was observed from a source with valid egress rather than from the authorized Kamailio identity, for the same reason |
| PROOF_LIMITATION | The ephemeral debug container attached to the Prometheus Pod terminated cleanly (`exitCode 0`) but its spec entry remains on the Pod object. Kubernetes provides no way to remove an ephemeral container without restarting the Pod, and restarting an unrelated workload was not permitted |
| Unrelated pre-existing condition | `utcp-monitoring-operator` remains `CrashLoopBackOff` (`exitCode 1`), advancing 363 → 367 on its own pre-existing cadence throughout the proof. Same Pod UID; it was already crash-looping for 7d16h before this proof began and is out of T3-S1 scope |
| Deferred | No `rtpengine`/media alert rules exist among the live Prometheus rules, and no rtpengine scrape target is configured. ADR-020 §10 requires relay-unavailable, control-failure, and port-exhaustion alerts — deferred observability, tracked with `PRODUCT_DEFECT-3` seam B |

## Environment Preservation

```text
production code changed:       no
Kubernetes manifests changed:  no
dependencies changed:          no
versions.env changed:          no
runtime configuration changed: no
resources applied:             0 (no manifest changed in 812c6ec)
generated policies re-applied: 0 (no apiserver policy-pin drift existed)
existing workloads restarted:  no
existing workloads rebuilt:    no
workloads rolled:              1 (rtpengine only)
images built:                  1 (rtpengine only)
images pushed:                 1 (rtpengine only)
live media proof run:          no
canonical records mutated:     no
```

All 34 non-rtpengine Pods retain their original UIDs. Restart-count movement is
limited to the two self-driven cases classified above (`worker` `--max-time`
self-exit; `utcp-monitoring-operator` pre-existing crash-loop). `postgres-0` and
`redis-0` are unchanged.

## Cleanup

- The short-lived unauthorized proof Pod (`default/utcp-t3s1-unauthorized-proof`) was deleted; no proof Pod remains in any namespace.
- The ephemeral debug container on the Prometheus Pod exited `0` at `21:14:01Z` on its own `sleep` bound. The Prometheus workload was **not** restarted.
- Provisioned Helm binary, downloaded archive, checksum file, and extracted artefacts removed from the scratch directory; `helm` is no longer on `PATH`.
- No port-forward was started. `.playwright-mcp/` is absent. No credentials were introduced or recorded.
- APNTalk rtpengine images present in the local Docker cache were **not** used, inspected as a source, or referenced; the clean-room requirement is preserved.
- rtpengine is left deployed and **healthy** at one Ready replica on the corrected `812c6ec` image, with its ConfigMap, Service, Deployment, and NetworkPolicy unchanged and matching git.
- Working tree contains only this evidence document and the roadmap updates.

## T3-S1 Final Status

```text
T3-S1 live foundation proof = INCOMPLETE (blocked by PRODUCT_DEFECT-3)
T3 = In Progress
UTCP_PHASE = T1 (unchanged)
```

The relay foundation itself is proven end to end. What remains unproven is the
**authorized reachability** of the two corridors ADR-020 declares, which is a
NetworkPolicy completeness defect on the source side, not an rtpengine defect.

## Next Exact T3 Target

One bounded Codex correction for `PRODUCT_DEFECT-3`:

1. `infrastructure/kubernetes/security/platform/allow-kamailio-signaling.yaml` —
   add egress to the `utcp.io/network-role: rtpengine-media` selector in
   `utcp-platform` for `2223/UDP` and `40000–40099/UDP`.
2. `infrastructure/kubernetes/observability/network-policies/allow-application-metrics.yaml` —
   add egress to the same selector for `2224/TCP`, and add the rtpengine scrape
   target required by ADR-020 §10.
3. `scripts/media/config-check` — fail statically when a source identity named in
   an `allow-rtpengine-media` ingress rule has no reciprocal egress rule, with
   matching mutation cases in `scripts/media/config-check-test`.

Then resume this live proof at the authorized-corridor steps only: authorized
`ng` control from the Kamailio identity, authorized metrics from the Prometheus
identity, and the authorized view of relay-unavailable failure. Every other step
in this document is already proven at `812c6ec` and does not need to be repeated.

Do not broaden the correction into Kamailio media routing, browser SIP,
conference admission, V0, T4, external trunks, or PSTN.
