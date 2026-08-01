# T3-S2A — Internal Asterisk SIP Application-Dialog Live Proof

Verdict: `T3_S2A_ASTERISK_SIP_APPLICATION_AUTHORITY_LIVE_PROOF_INCOMPLETE`

The **Asterisk half of T3-S2A is fully deployed and proven live**: the rebuilt
image loads every committed PJSIP module under `autoload=no`, exactly one UDP
`5060` transport is active, the `anonymous` endpoint resolves `context=from-kamailio`
with `direct_media=false`, the `from-kamailio` dialplan carries the local `9900`
fixture from the overlay only, the ClusterIP SIP Service has exactly one Ready
endpoint, the reciprocal NetworkPolicy corridor allows the real Kamailio identity
and denies everything else, and no public SIP surface exists.

The proof stopped at one new, exact, reproducible defect:
**`PRODUCT_DEFECT-5` — the committed Kamailio configuration invokes `has_totag()`
without loading `siputils.so`, so Kamailio fails to parse its own configuration
and the new Pod never starts.** The application-dialog route is therefore not
live, and no SIP dialog corridor (challenge, INVITE, SDP answer, Record-Route,
ACK, BYE, CANCEL, Asterisk-unavailable `503`) could be exercised.

Per the proof contract, no production file was modified to work around it.

**`PRODUCT_DEFECT-5` is now corrected in `92365f8` and confirmed closed live** —
see [Kamailio Dialog Reproof (`92365f8`)](#kamailio-dialog-reproof-92365f8) at
the end of this document. The reproof proved deterministic checksum-coupled
rollout, parser and module resolution, the canonical authentication challenge,
successful subscriber digest authentication for INVITE, unsupported-method
rejection, and REGISTER preservation — and isolated one further exact defect,
**`PRODUCT_DEFECT-6`**, which prevents the relay from reaching Asterisk.

**T3-S2A remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

---

## Initial Deployment Proof (`ab5ab55`) — historical verdict: `INCOMPLETE`

## Source Commit

- Proof executed at `ab5ab55` (`feat(t3): establish internal asterisk sip authority`).
- Branch `main`, working tree clean at start and at finish, `UTCP_PHASE=T1`, nothing pushed.
- Authority: [`ADR-019`](../../decisions/ADR-019-kamailio-signaling-registration-authority.md),
  [`ADR-020`](../../decisions/ADR-020-t3-rtp-media-plane.md),
  [`t3-s1-rtpengine-foundation-live-proof.md`](t3-s1-rtpengine-foundation-live-proof.md),
  [`t3-s2a-asterisk-sip-application-dialog-authority.md`](t3-s2a-asterisk-sip-application-dialog-authority.md).

## PRODUCT_DEFECT-5 — `has_totag()` is invoked without loading `siputils.so`

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — `route[APPLICATION_DIALOG]` calls `has_totag()` (rendered line 114) while the `loadmodule` block lists 17 modules that do **not** include `siputils.so` |
| Expected | Kamailio parses its configuration, starts, and dispatches in-dialog requests through `route[WITHINDLG]` |
| Actual | `yyparse(): cfg. parser: failed to find command has_totag (params 0)` → `CRITICAL: parse error in config file /tmp/kamailio.cfg, line 114, column 19: unknown command, missing loadmodule?` → container exits `255` → `CrashLoopBackOff` |
| Module availability | `siputils.so` **is present** in the Kamailio image (`/usr/lib/x86_64-linux-gnu/kamailio/modules/siputils.so`, one of 237 modules). Only the `loadmodule` line is missing |
| Static checks | **All passed** — `make kamailio-signaling-config-check` (`live_kamailio_runtime=configured`) and `make kamailio-signaling-config-check-test` (`regression_tests=pass`), plus `k8s-config-check`, `security-config-check`, `media-config-check` and `make check`. None parses the configuration with Kamailio itself, so an unloadable module reference is invisible to static coverage |
| Blast radius | Every new Kamailio Pod. The application-dialog route, and therefore the whole T3-S2A signaling corridor, cannot run |

### Verified root cause and verified correction

The configuration was validated with Kamailio's own parser (`kamailio -c -f`)
against a disposable scratch copy. No mounted configuration, running process, or
repository file was modified, and the scratch files were deleted afterwards.

```text
A) as-committed configuration
   ERROR: yyparse(): cfg. parser: failed to find command has_totag (params 0)
   CRITICAL: parse error ... line 114, column 19: unknown command, missing loadmodule?

B) identical configuration + one added line: loadmodule "siputils.so"
   (no ERROR, no CRITICAL, no parse error — configuration parses cleanly)
```

**`siputils.so` is the only missing module.** Every other function invoked by the
new route set resolves against an already-loaded module (`tm`, `sl`, `rr`, `pv`,
`xlog`, `textops`, `maxfwd`, `sanity`, `xhttp`, `websocket`, `auth`, `auth_db`,
`usrloc`, `registrar`, `db_postgres`, `nathelper`, `pike`).

### Smallest bounded correction

1. Add `loadmodule "siputils.so"` to the `loadmodule` block in
   `kamailio-configmap.yaml` (the natural place is beside `loadmodule "rr.so"`,
   which the same commit added for `record_route()`/`loose_route()`).
2. Extend `scripts/kamailio-signaling/config-check` to parse the rendered
   configuration with Kamailio's own `-c` config check, or at minimum assert that
   every invoked route function maps to a loaded module, with matching mutation
   cases in `scripts/kamailio-signaling/config-check-test`.

This is a one-line configuration correction plus a static-coverage assertion. It
must not broaden into rtpengine mediation, browser SIP, conference admission,
V0, T4, external trunks, or PSTN.

## Repository Baseline and Static Assertions

`HEAD = ab5ab55`, working tree clean, `UTCP_PHASE=T1`. All §1 assertions verified
against the committed tree:

| Assertion | Result |
|---|---|
| exactly one Asterisk SIP Service | one — `base/runtime/asterisk-sip-service.yaml` |
| ClusterIP UDP `5060` | `type: ClusterIP`, `protocol: UDP`, `port: 5060`, `targetPort: sip` |
| ARI separate on TCP `8088` | `asterisk-ari-service.yaml`, `protocol: TCP`, `port: 8088` |
| exactly one PJSIP UDP transport | `pjsip.conf` declares one `type=transport`, `protocol=udp`, `bind=0.0.0.0:5060` |
| endpoint `context=from-kamailio` | yes |
| `direct_media=no` | yes |
| local overlay contains `9900` | `overlays/local/runtime/extensions.local.conf` |
| base does **not** contain `9900` | 0 occurrences anywhere under `infrastructure/docker/asterisk/config/` |
| Kamailio uses canonical Service DNS | `$du = "sip:asterisk-sip.utcp-runtime.svc.cluster.local:5060;transport=udp"` |
| REGISTER carries no application-dialog routing | REGISTER branch brace-matched in full: contains `save("location")` and exits; contains **no** `APPLICATION_DIALOG`, `ASTERISK_RELAY`, `record_route`, `loose_route`, `t_relay`, `rtpengine`, or `asterisk-sip` |
| no rtpengine operation in the application route | 0 occurrences of `rtpengine_offer`/`answer`/`delete`/`manage`, `rtpproxy`, `set_rtp_proxy`, and 0 of `msg_apply_changes`/`subst_body`/`replace_body`/`sdp_` |

The only IPv4 literal in the configuration is `0.0.0.0` in `listen=tcp:0.0.0.0:8080`.

## Pre-Deployment Checks

All passed before anything was applied, and all passed again at the end:

```text
make repository-hygiene                      passed
make workflow-check                          passed
make secret-scan                             passed
make k8s-config-check                        passed
make security-config-check                   passed
make security-config-check-test              passed
make media-config-check                      passed
make media-config-check-test                 passed
make kamailio-signaling-config-check         passed  (live_kamailio_runtime=configured)
make kamailio-signaling-config-check-test    passed
make check                                   passed (exit 0)
make gateway-config-check                    passed
git diff --check / --cached --check          clean
```

Helm was absent and was provisioned from the repository pin `HELM_VERSION=v4.0.3`
through the checksum-verified process, then removed at cleanup.

## Runtime Baseline

```text
kubeconfig .runtime/kubeconfig/utcp-local.yaml, context k3d-utcp-local
asterisk-ari      asterisk-ari-db55d57c5-2vvsn   uid 3840cda2-…  ready=true  restarts=13  image sha256:962245f7…
asterisk-ari-b    asterisk-ari-b-8557bd4d76-…    uid 8a904cdd-…  ready=true  restarts=12  (separate runtime node)
kamailio          kamailio-679bd6bf59-zn6f4      uid 843bf4db-…  ready=true  restarts=40
rtpengine         rtpengine-74cd786966-hvcrn     uid fea2c8fc-…  ready=true  restarts=0
```

Clean pre-deployment state confirmed — no partial prior apply:

```text
Service/utcp-runtime/asterisk-sip                     NotFound
ConfigMap/utcp-runtime/asterisk-local-sip-fixtures    NotFound
NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio  NotFound
Asterisk PJSIP modules loaded                         0
Asterisk 'pjsip show transports'                      command does not exist
Asterisk dialplan contexts                            stasis-utcp-t0-observation, utcp-conference-proof, default
Asterisk 'from-kamailio' context                      "There is no existence of 'from-kamailio' context"
Asterisk UDP sockets                                  0
kamailio live cfg sha256                              6e85abaf1300…
```

State authority: 41 public tables, no `dialog`/`rtp`/`media` tables, 27 tenants,
110 RuntimeNodes (`asterisk/asterisk-ari` + `simulator/simulator-deterministic`,
27 asterisk), 0 pending outbox, Redis `db0`=0 with 0 `sip`/`dialog`/`rtp`/`media`
keys. rtpengine sessions 0, ports_used 0. **No Kubernetes API policy-pin drift.**

## Deployment Boundary

A full render of `overlays/local` and `security`, plus `kubectl diff` against
live, identified every object that would change. Six are T3-S2A; one is
pre-existing unrelated drift and was **explicitly excluded**; the rest differed by
a `metadata.generation` line only.

| Object | Disposition |
|---|---|
| `Deployment/utcp-runtime/asterisk-ari` | **applied** — adds SIP `containerPort 5060/UDP`, exec readiness probe (replacing `tcpSocket: ari`), local-config volume and mount |
| `ConfigMap/utcp-platform/kamailio-config` | **applied** — application-dialog route |
| `ConfigMap/utcp-runtime/asterisk-local-sip-fixtures` | **applied** — new, the `9900` fixture |
| `Service/utcp-runtime/asterisk-sip` | **applied** — new |
| `NetworkPolicy/utcp-platform/allow-kamailio-signaling-required-traffic` | **applied** — adds UDP `5060` egress to `utcp-runtime` / `component=asterisk-ari` |
| `NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio` | **applied** — new |
| `ConfigMap/utcp-platform/utcp-application-config` | **EXCLUDED — unrelated pre-existing drift.** The render would flip live `APP_URL: http://gateway:8080` → `https://app.utcp.local.test` and `BROADCAST_CONNECTION: log` → `reverb` plus seven `REVERB_*` keys. It is not part of `ab5ab55`, and applying it would reconfigure the API and every worker |

## Images Built or Reused

`ab5ab55` changed image-owned Asterisk files (`Dockerfile`, `config/extensions.conf`,
`config/modules.conf`, new `config/pjsip.conf`, `entrypoint`, `readiness`), so
Asterisk was rebuilt and pushed. Nothing else was rebuilt or republished.

| Field | Value |
|---|---|
| build command | canonical Asterisk block of `scripts/kubernetes/image-build` |
| base image | `andrius/asterisk:20@sha256:a27dae75…` (pinned in `versions.env`) |
| local tag | `utcp-asterisk-ari:dev` |
| index digest | `sha256:14dab60500f11ff9ad1fb4c1ad9296de04748eae354d86ac21c8595225b7db44` |
| `linux/amd64` manifest digest | `sha256:307acf45870344f8d25fb2f9a8458a91d7de22a455016a286a0952a84ce00e41` |
| registry reference | `127.0.0.1:5001/utcp/asterisk-ari:0.1.0-k1-dev` (no `latest`) |
| `Docker-Content-Digest` | identical (`sha256:14dab605…`) |
| pull-back | same digest |
| configured user / arch | `1000:1000` / `amd64` |
| exposed ports | `5060/udp`, `8088/tcp` |
| required modules present | `res_pjproject`, `res_pjsip`, `res_pjsip_endpoint_identifier_anonymous`, `res_pjsip_pubsub`, `res_pjsip_session`, `res_rtp_asterisk`, `res_pjsip_sdp_rtp`, `chan_pjsip`, `app_echo`, `res_sorcery_memory`, `res_sorcery_astdb`, `codec_ulaw` — all present |
| `9900` in image | **0 occurrences** (local-overlay only, as contracted) |

Not rebuilt or pushed: API, Web, Gateway, Kamailio, rtpengine, Asterisk ARI adapter.

**Divergence:** the committed Asterisk `Dockerfile` declares no
`org.opencontainers.image.revision` argument or label, so repository revision
metadata could not be stamped as `ab5ab55` without modifying a production file.
The image carries only the upstream base-image labels. Provenance is therefore
established by content digest plus verified in-image file contents. Recorded as a
divergence, not a defect.

## Resources Applied

Applied at `2026-07-29T02:28:04Z`.

| Resource | Before | After |
|---|---|---|
| `ConfigMap/utcp-runtime/asterisk-local-sip-fixtures` | absent | rv `431711` |
| `Service/utcp-runtime/asterisk-sip` | absent | rv `431714`, `ClusterIP 10.43.209.141` |
| `NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio` | absent | gen `1`, rv `431717` |
| `NetworkPolicy/utcp-platform/allow-kamailio-signaling-required-traffic` | gen `3`, rv `423576` | gen `4`, rv `431718` |
| `ConfigMap/utcp-platform/kamailio-config` | rv `84155` | rv `431719` |
| `Deployment/utcp-runtime/asterisk-ari` | gen `8`, rv `404953` | gen `9`, rv `431724` |

No rtpengine, Prometheus, database, Gateway, or k3d resource was applied.

## Workloads Rolled Out

| Workload | Old | New | Result |
|---|---|---|---|
| `asterisk-ari` | `…-db55d57c5-2vvsn`, uid `3840cda2-…`, image `sha256:962245f7…`, restarts 13 | `asterisk-ari-74d8c4b5f8-h55nr`, uid `78e463c9-…`, image **`sha256:14dab605…`**, restarts 0, IP `10.42.1.218` | **Ready**, rolled out by `02:28:38Z` |
| `kamailio` | `…-679bd6bf59-zn6f4`, uid `843bf4db-…`, restarts 40 | `kamailio-5bd99db6b6-7vxh9`, then `kamailio-85f4bd8c49-4qnw9` | **failed** — `CrashLoopBackOff`, `exitCode 255` (`PRODUCT_DEFECT-5`) |

The running Asterisk `imageID` matches the pushed registry digest exactly.
`asterisk-ari-b` and rtpengine were not touched.

Kamailio's `RollingUpdate` strategy (`maxUnavailable: 25%`, which floors to `0`
at one replica) kept the healthy pre-existing Pod serving, so the Deployment
remains `DESIRED 1 / READY 1 / AVAILABLE 1` and the Service EndpointSlice carries
only the Ready old Pod. **Signaling was never interrupted.**

## Asterisk Module Result

**PASS.** `autoload = no` remains effective and every committed module loads:

```text
chan_pjsip.so                              Running   core
res_pjsip.so                               Running   core
res_pjsip_endpoint_identifier_anonymous.so Running   core
res_pjsip_pubsub.so                        Running   core
res_pjsip_sdp_rtp.so                       Running   core
res_pjsip_session.so                       Running   core
res_pjproject.so                           Running   core
res_rtp_asterisk.so                        Running   core
app_echo.so                                Running   core
codec_ulaw.so                              Running   core
res_sorcery_memory.so                      Running   core
res_sorcery_astdb.so                       Running   core
res_ari.so                                 Running   core   <- ARI remains available
```

No required module failed to load and no unrelated transport or trunk module
appeared. Asterisk readiness (which now asserts the UDP `5060` transport) returns
success — the Pod is `Ready`.

## PJSIP Transport Result

**PASS.** Exactly one transport, exactly as committed:

```text
Transport:  transport-udp-internal     udp      0      0  0.0.0.0:5060
Objects found: 1
```

Sockets inside the Asterisk Pod:

```text
UDP:        ('0.0.0.0', 5060), ('0.0.0.0', 37190)   <- 37190 is the RTP stack's ephemeral socket
TCP-LISTEN: ('0.0.0.0', 8088)                       <- ARI only
```

No TCP `5060`, no TLS SIP, no WS/WSS SIP, no HostPort, no node or developer-host
UDP `5060` socket, and no public Gateway or LoadBalancer path. Binding `0.0.0.0`
inside the isolated Pod is the committed model; external scope is governed by the
ClusterIP Service and the NetworkPolicy corridor.

## Kamailio-Facing Endpoint Result

**PASS.**

```text
Endpoint:   anonymous                    Unavailable   0 of inf
Transport:  transport-udp-internal  udp  0  0  0.0.0.0:5060
context          : from-kamailio
direct_media     : false
allow            : (ulaw)
identify_by      : username,ip
```

`endpoint_identifier_order=anonymous` with
`res_pjsip_endpoint_identifier_anonymous.so` loaded provides the committed
anonymous/internal identification model. No IP-address matching, credential, or
endpoint registration was added during proof.

## Direct-Media Prevention

**PASS.** `direct_media : false` on the live endpoint, so Asterisk remains in the
media path and no direct client-to-client media negotiation is offered.

## Dialplan and Local Fixture

**PASS.** The live merged dialplan shows the overlay fixture and the base
catch-all, with correct file provenance:

```text
[ Context 'from-kamailio' created by 'pbx_config' ]
  '9900' => 1. NoOp(UTCP local T3-S2A media fixture)   [extensions.local.conf:2]
            2. Answer()                                 [extensions.local.conf:3]
            3. Echo()                                   [extensions.local.conf:4]
            4. Hangup()                                 [extensions.local.conf:5]
  '_.'   => 1. NoOp(UTCP internal application dialog rejected destination=${EXTEN}) [extensions.conf:14]
            2. Hangup(21)                               [extensions.conf:15]
-= 2 extensions (6 priorities) in 1 context. =-
```

Unknown destinations terminate deterministically via `Hangup(21)`. No trunk,
PSTN, external dialing, recording, ARI dependency, or second SIP leg exists.
`9900` is sourced only from `extensions.local.conf`, delivered by the
`asterisk-local-sip-fixtures` ConfigMap and merged by the committed entrypoint;
it is absent from the image's `extensions.conf`.

## Asterisk SIP Service

**PASS.**

```text
Service/utcp-runtime/asterisk-sip
  type=ClusterIP  clusterIP=10.43.209.141  port=5060  protocol=UDP  targetPort=sip
EndpointSlice
  endpoint=10.42.1.218  ready=true  pod=asterisk-ari-74d8c4b5f8-h55nr   (exactly one)
```

The endpoint IP equals the current Asterisk Pod IP. `asterisk-ari-b` is correctly
excluded — the Service selector requires `utcp.dev/runtime-node: local-asterisk-ari`,
and `asterisk-ari-b` carries `local-asterisk-ari-b`.

From the Kamailio Pod, cluster DNS resolves the canonical name:

```text
asterisk-sip.utcp-runtime.svc.cluster.local -> 10.43.209.141
```

Kamailio's configured destination is the DNS name, not a ClusterIP or Pod IP.

## Reciprocal Policy Result

**PASS.** The corridor is complete and symmetric.

```text
Kamailio source egress   : UDP 5060 -> ns utcp-runtime / app.kubernetes.io/component=asterisk-ari
Asterisk dest ingress    : UDP 5060 <- ns utcp-platform / utcp.io/network-role=kamailio-signaling
```

| Requirement | Result |
|---|---|
| default-deny active | `utcp-runtime/default-deny` retains `podSelector: {}` with `[Ingress, Egress]` |
| no wildcard UDP | every rule declares an explicit port |
| no namespace-wide SIP rule | both rules carry a `podSelector` |
| ARI policy separate | `allow-asterisk-ari-from-utcp-workers` remains TCP `8088` only |
| no media UDP corridor added | none |
| existing Kamailio egress intact | DNS `53`, PostgreSQL `5432`, rtpengine `2223` all retained alongside the new `5060` |

## Unauthorized Direct SIP Denial

**PASS.** A short-lived Pod in the `default` namespace (zero NetworkPolicies, so
unrestricted source egress) isolates Asterisk's ingress policy as the only
possible cause of denial.

| Source | Expected | Actual |
|---|---|---|
| Real Kamailio identity | allowed | **`SIP/2.0 200 OK`** from Asterisk in `0.014s` (OPTIONS via the Service DNS) |
| Unauthorized identity (`default` ns, DNS resolved in the same call) | denied | **denied**, bounded `TimeoutError` after `8.01s` |

No NetworkPolicy was modified for proof convenience. The proof Pod was deleted.

## Synthetic SIP Client

The canonical T1 tool `scripts/kamailio-signaling/sip-wss-client.php` supports
only REGISTER-family actions (`register`, `refresh`, `replace`, `deregister`,
`wrong-password`, `sha256`), so it cannot drive an INVITE dialog. A **disposable**
probe was written in the scratch directory, reusing that tool's exact transport
handling — TLS to `127.0.0.1:443`, `Host: sip.utcp.local.test`, path `/ws`,
`Sec-WebSocket-Protocol: sip`, `Origin: https://app.utcp.local.test`, and the same
masked WebSocket framing — so every request traverses the canonical Kamailio
listener through Traefik. It was never added as a repository or runtime component
and was removed at cleanup. No credential material was printed or recorded.

Because `PRODUCT_DEFECT-5` prevents the application-dialog route from running, no
authenticated subscriber credential was created; the corridor never reaches the
point where one is required.

## Subscriber Authentication Challenge

**BLOCKED by `PRODUCT_DEFECT-5`.** Under the committed route an unauthenticated
INVITE would receive a `401` digest challenge from
`route[APPLICATION_DIALOG]`. The live corridor instead runs the previous
registration-only configuration:

```text
INVITE sip:9900@sip.utcp.local.test  ->  405 Method Not Allowed
```

This is the pre-T3-S2A `unsupported_method` rejection, and is direct evidence that
the application-dialog route is not live.

## Authenticated Initial INVITE

**BLOCKED by `PRODUCT_DEFECT-5`.** Not reachable — the corridor rejects INVITE at
the old route before authentication.

## SDP-Bearing Response

**BLOCKED by `PRODUCT_DEFECT-5`.** Asterisk received no INVITE: `0 active
channels, 0 active calls, 0 calls processed`, and no `INVITE`, `9900`,
`from-kamailio`, or `Echo` activity appears in its logs.

## Record-Route Result

**BLOCKED by `PRODUCT_DEFECT-5`.** `rr.so` and `modparam("rr","enable_full_lr",1)`
are present in the committed configuration and were applied to the ConfigMap, but
no dialog could be established to observe `Record-Route` on the wire.

## ACK Continuity

**BLOCKED by `PRODUCT_DEFECT-5`.**

## BYE Continuity

**BLOCKED by `PRODUCT_DEFECT-5`.**

## CANCEL Result

**BLOCKED by `PRODUCT_DEFECT-5`.** No deterministic pre-answer CANCEL scenario was
attempted, and none is claimed. The committed `route[APPLICATION_DIALOG]` CANCEL
branch (`t_check_trans()` then `t_relay()`) retains only its configuration
validation and mutation-test evidence.

## Unsupported-Method Result

**PARTIAL.** `OPTIONS` has an existing health role in this repository
(`sl_send_reply("200","Keepalive")` before the method dispatch), so `MESSAGE` was
used instead:

```text
MESSAGE sip:9900@sip.utcp.local.test  ->  405 Method Not Allowed
```

Asterisk received nothing (0 channels, 0 calls processed) and rtpengine recorded
no control operation. REGISTER behavior was unaffected. The committed route set
would also reject `MESSAGE` with `405` because it is outside
`INVITE|ACK|CANCEL|BYE|UPDATE`, but this observation was served by the **previous**
configuration, so it cannot be attributed to the new route.

## Asterisk-Unavailable Result

**BLOCKED by `PRODUCT_DEFECT-5`.** The committed `503 Application Runtime
Unavailable` contract lives in `route[ASTERISK_RELAY]`, which never executes. No
Asterisk scale-to-zero condition was induced, because with the route unreachable
it could not produce the required evidence and would only have added avoidable
churn. Nothing about the `503` contract is claimed.

## Restoration Result

Not applicable — no unavailability condition was induced. Asterisk was left
Ready at its committed replica count throughout.

## REGISTER Preservation

**PASS.** The running Kamailio configuration is byte-identical to its
pre-proof, T1-proven state (`sha256 6e85abaf1300…` before and after), because the
new configuration never loaded. Confirmed live through the canonical WSS listener:

```text
REGISTER sip:sip.utcp.local.test
  -> 401 Unauthorized
     WWW-Authenticate: Digest realm="sip.utcp.local.test", nonce=<server-generated>
```

The canonical digest challenge is intact and the registrar route is live.
Application-dialog routing was not invoked, Asterisk received no REGISTER
(0 calls processed), and rtpengine performed no REGISTER-related control
operation. The serving Kamailio Pod (`kamailio-679bd6bf59-zn6f4`, uid
`843bf4db-…`, restarts `40`) is unchanged from the baseline. Re-confirmed after
all changes were applied.

## rtpengine Boundary Preservation

**PASS.** No mediation occurred, as required for this signaling-only slice.

```text
rtpengine_sessions{type="own"}       0     (baseline 0)
rtpengine_sessions{type="foreign"}   0     (baseline 0)
rtpengine_sessions_total             0     (baseline 0)
rtpengine_ports_used{internal}       0     (baseline 0)
rtpengine_ports_used{default}        0     (baseline 0)
offer/answer/delete log lines        0
```

The rtpengine Pod (uid `fea2c8fc-…`, restarts `0`) was not touched.

## Public-Surface Containment

**PASS.**

| Surface | Result |
|---|---|
| Asterisk SIP Service | `ClusterIP` only, UDP `5060` |
| NodePort UDP `5060` | absent (`nodePort=None`) |
| LoadBalancer UDP `5060` | absent — the only LoadBalancer is `traefik-system/traefik` (TCP `80`/`443`) |
| Gateway / Ingress / UDPRoute / TLSRoute / TCPRoute / HTTPRoute | **zero resources cluster-wide** |
| HostPort UDP `5060` | absent (0 containers) |
| k3d UDP `5060` publication | absent — `127.0.0.1:80`, `127.0.0.1:443`, `127.0.0.1:6550` only |
| node UDP `5060` sockets | 0 on all four k3d containers |
| developer-host UDP `5060` socket | absent |

ARI remains on its existing internal TCP `8088` authority. The established public
edge remains TCP `80/443`.

## State-Authority Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes | 110 | **110** |
| RuntimeNode families | `asterisk/asterisk-ari`, `simulator/simulator-deterministic` | **unchanged** |
| Asterisk RuntimeNode records | 27 | **27** |
| pending outbox | 0 | **0** |
| Redis keys `sip` / `dialog` / `rtp` / `media` | 0 / 0 / 0 / 0 | **0 / 0 / 0 / 0** |
| running Kamailio config hash | `6e85abaf1300…` | **identical** |
| rtpengine sessions / ports_used | 0 / 0 | **0 / 0** |

Redis `db0` moved `0 → 1` (the Laravel scheduler's TTL-bearing cache key on its
normal cadence). No durable dialog or media authority was introduced.

## Findings

| Classification | Finding |
|---|---|
| **PRODUCT_DEFECT-5** | `route[APPLICATION_DIALOG]` invokes `has_totag()` without `loadmodule "siputils.so"`, so Kamailio cannot parse its configuration and every new Pod exits `255` into `CrashLoopBackOff`. Verified with Kamailio's own parser: adding the single `loadmodule` line makes the configuration parse cleanly, and `siputils.so` is already present in the image. All static checks — including both `kamailio-signaling` targets — pass despite this |
| PASS | The rebuilt Asterisk image loads every committed PJSIP module with `autoload=no` retained; ARI remains available; readiness succeeds |
| PASS | Exactly one PJSIP transport, UDP `0.0.0.0:5060`; no TCP/TLS/WS/WSS SIP; no HostPort or node/host socket |
| PASS | The `anonymous` endpoint resolves `context=from-kamailio`, `direct_media=false`, `allow=(ulaw)` |
| PASS | `from-kamailio` carries `9900` (`Answer`/`Echo`/`Hangup`) from the overlay only, plus a deterministic `_.` → `Hangup(21)`; `9900` is absent from the image |
| PASS | `Service/utcp-runtime/asterisk-sip` is ClusterIP UDP `5060` with exactly one Ready endpoint equal to the Asterisk Pod IP; `asterisk-ari-b` correctly excluded |
| PASS | Kamailio resolves the canonical Service DNS through cluster DNS, not a hard-coded address |
| PASS | The reciprocal policy corridor allows the real Kamailio identity (`200 OK` in `0.014s`) and denies an unauthorized source with a bounded timeout; default-deny intact; ARI policy separate |
| PASS | Only the six T3-S2A resources were applied; the unrelated `utcp-application-config` drift was explicitly excluded |
| PASS | Only Asterisk and Kamailio were affected; `asterisk-ari-b`, rtpengine, and all other workloads retain identical UIDs and restart counts |
| PASS | REGISTER is fully preserved — canonical `401` digest challenge live, running configuration byte-identical, Asterisk and rtpengine untouched by it |
| PASS | No rtpengine mediation occurred; sessions and port counters unchanged; zero offer/answer/delete operations |
| PASS | No public SIP exposure; edge unchanged at TCP `80/443` |
| PASS | No durable dialog or media authority; no canonical state mutation |
| PASS | All repository checks pass before and after |
| EXPECTED_BEHAVIOR | The Asterisk RTP stack binds an additional ephemeral UDP socket (`0.0.0.0:37190`). It is inside the Pod, not advertised, and not a SIP listener |
| EXPECTED_BEHAVIOR | Kamailio's `RollingUpdate` (`maxUnavailable: 25%` → `0` at one replica) preserved the healthy Pod, so signaling was never interrupted despite the failed rollout |
| EXPECTED_BEHAVIOR | Redis `db0` `0 → 1`: the scheduler's TTL-bearing cache key, not domain state |
| EXPECTED_BEHAVIOR | Helm absent; provisioned from the repository pin with checksum verification and removed at cleanup |
| Divergence | The committed Asterisk `Dockerfile` accepts no build-time revision argument, so `ab5ab55` could not be stamped as image metadata without modifying a production file. Provenance rests on the content digest and verified in-image contents |
| Divergence | `ConfigMap/utcp-platform/utcp-application-config` differs between the render and live (`APP_URL`, `BROADCAST_CONNECTION`, `REVERB_*`). It is pre-existing, unrelated to `ab5ab55`, and was deliberately not applied |
| PROOF_LIMITATION | The entire SIP dialog corridor — authentication challenge, authenticated INVITE, SDP-bearing answer, Record-Route, ACK, BYE, CANCEL, and the Asterisk-unavailable `503` contract — could not be exercised, because `PRODUCT_DEFECT-5` prevents the route from running. Nothing about those behaviors is claimed |
| PROOF_LIMITATION | The `405` observed for `MESSAGE` and `INVITE` was served by the previous configuration and therefore cannot be attributed to the new committed route set |
| Operator-relevant risk | Because the applied `kamailio-config` ConfigMap is unparseable, **every new Kamailio Pod will crash-loop until `PRODUCT_DEFECT-5` is corrected.** Kamailio currently stays available only because the pre-existing Pod is still running and rolling-update protects it. If that Pod is evicted, drained, or rescheduled, SIP signaling will stop |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
versions.env changed:           no
resources applied:              6 (all T3-S2A; one unrelated ConfigMap explicitly excluded)
images built:                   1 (asterisk-ari only)
images pushed:                  1 (asterisk-ari only)
workloads rolled:               2 (asterisk-ari succeeded; kamailio failed on PRODUCT_DEFECT-5)
unrelated workloads restarted:  none
canonical records mutated:      none
live media proof run:           no
```

Every other Pod retains its baseline UID and restart count, including
`asterisk-ari-b`, rtpengine, Prometheus, PostgreSQL, and Redis.

## Cleanup

- `default/utcp-t3s2a-unauthorized-proof` deleted; no proof Pod remains in any namespace.
- The disposable WSS SIP probe lived only in the scratch directory and was never added to the repository or the cluster.
- Disposable Kamailio configuration-parser scratch files were deleted from the Pod immediately after validation; no mounted configuration or running process was altered.
- The `kubectl rollout restart` annotation added during this proof was removed from the Kamailio Deployment, reverting that action.
- Provisioned Helm binary, archive, checksum file, and extracted artefacts removed; `helm` is no longer on `PATH`.
- No packet capture and no port-forward were used. `.playwright-mcp/` is absent. No credentials were introduced, printed, or recorded.
- Asterisk is left Ready at its committed replica count; Kamailio is left Ready and serving REGISTER.
- The six T3-S2A resources are left applied, preserving the reproducible failure state.
- Working tree contains only this evidence document and the roadmap updates.

## T3-S2A Final Status

```text
T3-S2A repository implementation = Complete (Asterisk half proven live)
T3-S2A live signaling proof      = INCOMPLETE (blocked by PRODUCT_DEFECT-5)
T3-S2A                           = In Progress
T3-S2 media mediation            = Not Started
T3                               = In Progress
UTCP_PHASE                       = T1 (unchanged)
```

## Next Exact T3 Target

One bounded Codex correction for `PRODUCT_DEFECT-5`:

1. Add `loadmodule "siputils.so"` to
   `infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`.
2. Extend `scripts/kamailio-signaling/config-check` to validate the rendered
   configuration with Kamailio's own config parser (or assert module coverage for
   every invoked function), with matching cases in `config-check-test`.

Then resume this proof from the Kamailio rollout only. Everything on the Asterisk
side — image, modules, transport, endpoint, dialplan, Service, EndpointSlice,
policy corridor, unauthorized denial, containment, and state preservation — is
already proven at `ab5ab55` and does not need repeating. The remaining live steps
are: authentication challenge, authenticated INVITE, SDP-bearing answer,
Record-Route, ACK, BYE, CANCEL (or its deterministic limitation), the
Asterisk-unavailable `503` contract, and restoration.

Do not add rtpengine mediation, browser SIP, conference admission, V0, T4,
external trunks, or PSTN.

---

# Kamailio Dialog Reproof (`92365f8`)

Verdict: `T3_S2A_KAMAILIO_DIALOG_LIVE_PROOF_INCOMPLETE`

Focused reproof of only the corridor that `PRODUCT_DEFECT-5` blocked. No
completed Asterisk, Service, NetworkPolicy, unauthorized-access, or
public-surface proof was repeated, and no production file was modified.

**`PRODUCT_DEFECT-5` is closed.** The corrected configuration loads
`siputils.so`, parses cleanly with the pinned image, and the checksum-coupled
Pod template produced a fully automatic rollout with **no manual restart**. The
application-dialog route is live: an unauthenticated INVITE to `9900` now
receives the canonical `401` digest challenge instead of the previous `405`, and
subscriber digest authentication for INVITE succeeds.

**One further exact defect blocks the dialog: `PRODUCT_DEFECT-6`.** Kamailio
declares only `listen=tcp:0.0.0.0:8080` and has **no UDP socket**, but
`route[ASTERISK_RELAY]` targets a UDP destination. `t_relay()` therefore cannot
build a branch, and the committed `503 Application Runtime Unavailable` failure
branch fires **while Asterisk is healthy and its Service endpoint is Ready** —
a false unavailability signal that masks a transport misconfiguration.

## Source Commit

- Reproof executed at `92365f8` (`fix(t3): validate rendered kamailio dialog config`).
- Branch `main`, working tree clean at start and at finish, `UTCP_PHASE=T1`, nothing pushed.

Pre-apply static authorities both passed:

```text
make kamailio-signaling-config-check        exit 0  (kamailio_signaling_config_check=pass, live_kamailio_runtime=configured)
make kamailio-signaling-config-check-test   exit 0
```

The corrected `config-check` now runs the **real Kamailio parser** against the
rendered configuration (`docker run --entrypoint /usr/sbin/kamailio … -c -f`) and
asserts the Deployment checksum annotation equals the rendered `kamailio.cfg`
SHA-256. This is exactly the static-coverage correction the previous proof
recommended, and it is confirmed effective.

Verified in the render:

```text
loadmodule "siputils.so"                     present
route[APPLICATION_DIALOG]                    present
sha256(rendered kamailio.cfg)                bc14c98ecc0f8ba1c9d3bc8765a62f4d62a1a7d70c0fa2b57e2470daf4f87702
Deployment utcp.io/kamailio-config-sha256    bc14c98ecc0f8ba1c9d3bc8765a62f4d62a1a7d70c0fa2b57e2470daf4f87702
match                                        yes
rollout timestamp annotation                 absent
```

## PRODUCT_DEFECT-6 — Kamailio has no UDP socket for the Asterisk relay destination

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — the transport block declares only `listen=tcp:0.0.0.0:8080`, while `route[ASTERISK_RELAY]` sets `$du = "sip:asterisk-sip.utcp-runtime.svc.cluster.local:5060;transport=udp"` |
| Expected | `t_relay()` forwards the authenticated INVITE over UDP to the Asterisk SIP Service, Asterisk enters `from-kamailio` and executes `9900` |
| Actual | `t_relay()` fails to add a branch and the committed failure branch returns `503 Application Runtime Unavailable`, even though Asterisk is Ready |
| Kamailio log | `ERROR: tm [ut.h:306]: uri2dst2(): no corresponding socket found for "asterisk-sip.utcp-runtime.svc.cluster.local" af 2 (udp:10.43.209.141:5060)` → `ERROR: tm [t_fwd.c:473]: prepare_new_uac(): can't fwd to af 2 (IPv4), proto 1 (udp) (no corresponding listening socket)` → `ERROR: tm [t_fwd.c:1764]: t_forward_nonack(): failure to add branches` → `WARNING: <script>: kamailio_application_dialog_rejected result=asterisk_unavailable` |
| Observed sockets in the Pod | `UDP: []`, `TCP listen: [('0.0.0.0', 8080)]` |
| Static and parser checks | **All passed**, including the new rendered-parser check. A configuration can be syntactically valid and still reference a transport for which no listening socket exists; nothing asserts that every destination transport has a matching `listen=` socket |
| Severity note | The `503` is a **false failure signal**. It fires for a transport reason, not because Asterisk is unavailable, so the committed unavailability contract currently cannot distinguish a healthy Asterisk from an absent one |

### Smallest bounded correction

1. Add a UDP socket to `kamailio-configmap.yaml`, e.g. `listen=udp:0.0.0.0:5060`
   beside the existing `listen=tcp:0.0.0.0:8080`, so `tm` can forward to the
   UDP destination. Declare the matching container port on the Kamailio
   Deployment for clarity.
2. Extend `scripts/kamailio-signaling/config-check` to assert that every
   destination transport used by the routing script (`$du` / `;transport=`) has a
   corresponding `listen=` socket of the same protocol, with matching mutation
   cases in `config-check-test`.

Return-path traffic needs no new policy: the reciprocal corridor is already
proven, and NetworkPolicy is connection-tracked, so replies to an allowed flow
are permitted.

This is a configuration and static-coverage correction only. It must not broaden
into rtpengine mediation, browser SIP, conference admission, V0, T4, external
trunks, or PSTN.

## Runtime Baseline

```text
Deployment kamailio    generation 11, DESIRED 1, READY 1, AVAILABLE 1, UNAVAILABLE 1
conditions             Available=True (MinimumReplicasAvailable)
                       Progressing=False (ProgressDeadlineExceeded)   <- the stuck rollout
old Ready Pod          kamailio-679bd6bf59-zn6f4  uid 843bf4db-…  ready=true  restarts=40   (RS rev 9)
failed Pod             kamailio-85f4bd8c49-4qnw9  uid 5cdeac08-…  ready=false restarts=22   (RS rev 11)
pod-template annots    (none)
live ConfigMap         rv 431719, sha256 a4b733fd…   (the unparseable T3-S2A config)
old Pod running config sha256 6e85abaf…              (previous parseable configuration)
INVITE 9900            405 Method Not Allowed
MESSAGE                405 Method Not Allowed
asterisk-ari           uid 78e463c9-…  ready=true  restarts=0  podIP 10.42.1.218
rtpengine              uid fea2c8fc-…  ready=true  restarts=0
asterisk-sip endpoint  10.42.1.218 ready=true
rtpengine sessions/ports_used  0 / 0
tables 41, no dialog/rtp/media tables, tenants 27, RuntimeNodes 110, outbox 0
Redis db0 0, keys sip/dialog/rtp/media all 0
```

Confirmed: the old Pod was still serving the previous parseable configuration.
Neither the old Pod nor the failed ReplicaSet was deleted manually.

## Resources Applied

Exactly two, applied in the prescribed order. `kubectl diff` over only these two
showed exactly two material changes and no unrelated drift.

| Resource | Field | Before | After |
|---|---|---|---|
| `ConfigMap/utcp-platform/kamailio-config` | resourceVersion | `431719` | `435223` |
| | data SHA-256 | `a4b733fd…` | **`bc14c98e…`** |
| `Deployment/utcp-platform/kamailio` | generation | `11` | `12` |
| | resourceVersion | `432578` | `435246` |
| | `utcp.io/kamailio-config-sha256` | absent | **`bc14c98e…`** |
| | image | `ghcr.io/kamailio/kamailio:5.8.6-bookworm` | **unchanged** |
| | securityContext | present | **unchanged** |

After the ConfigMap apply and before the Deployment apply, the Deployment
remained at generation `11` with no pod-template annotations and both Pods
unchanged — confirming the running process does not reparse the mounted file
dynamically. **No reload command and no `kubectl rollout restart` were issued.**

## Deterministic Rollout Coupling

Applying the Deployment changed the pod template solely through the
configuration-checksum annotation, which is what triggered the new ReplicaSet.

```text
deployment applied : 2026-07-29T04:11:48Z
new Pod scheduled  : 2026-07-29T04:11:48Z
new Pod started    : 2026-07-29T04:11:49Z
new Pod Ready      : 2026-07-29T04:11:51Z
rollout duration   : ~3 seconds
manual restart     : none
timestamp annotation added: none
```

## ReplicaSet Convergence

```text
kamailio-75f769585f   desired 0   rev 7
kamailio-654556c77c   desired 0   rev 8
kamailio-679bd6bf59   desired 0   rev 9    <- previously serving
kamailio-5bd99db6b6   desired 0   rev 10
kamailio-85f4bd8c49   desired 0   rev 11   <- previously failing, no longer owns a replica
kamailio-598854cdfc   desired 1   ready 1  rev 12   <- corrected template
```

| Requirement | Result |
|---|---|
| new ReplicaSet from the corrected template | `kamailio-598854cdfc`, revision 12 |
| corrected Pod starts and parses | `kamailio-598854cdfc-j9j54`, uid `8b8ecb96-…` |
| corrected Pod becomes Ready | `Ready=True at 04:11:51Z` |
| old Ready Pod remains until replacement readiness | old Pod `Killing` event at `04:11:51Z` — **after** the new Pod reached Ready |
| failed ReplicaSet no longer owns an active replica | rev 11 scaled to `0` |
| Deployment reaches committed replica count | `DESIRED 1 / READY 1 / UPDATED 1 / AVAILABLE 1` |
| Deployment conditions | `Available=True (MinimumReplicasAvailable)`, `Progressing=True (NewReplicaSetAvailable)` |
| manual restart or repair | **none** |
| historical ReplicaSets | left intact under normal revision-history behavior |

Service endpoint now resolves to the corrected Pod only (`10.42.2.118`, ready).

## Kamailio Parser and Module Result

**PASS.**

| Requirement | Result |
|---|---|
| CrashLoopBackOff | absent |
| parser error | none |
| `failed to find command has_totag` | **absent** |
| `siputils.so` loads | present in the running configuration (18 `loadmodule` lines) |
| `rr.so`, `tm.so`, `auth_db`, `registrar`, `usrloc` | all still loaded |
| every named route resolves | yes — no `fix_actions` failure in the running container |
| Kamailio Ready | yes |
| authoritative parser against the **exact running configuration** (`/usr/sbin/kamailio -c -f`) | clean — no ERROR, CRITICAL, or parse error |

Startup log of the running container is clean: `Listening on tcp: 0.0.0.0
[0.0.0.0]:8080`, `rr`/`auth`/`pike` initialised, no errors.

**Divergence — one transient restart.** The corrected Pod shows `restarts=1`.
The first container start exited `255` with
`db_postgres … connection to server at "postgres.utcp-data…" port 5432 failed:
Connection refused` → `auth_db … unable to open database connection` →
`fix_actions(): fixing failed`. This is the well-known race between a brand-new
Pod IP and NetworkPolicy datapath programming, not the `has_totag` defect — there
is no parser error in either attempt. The immediate kubelet restart succeeded and
the Pod has been stable since. Classified `EXPECTED_BEHAVIOR`.

## Running Configuration Identity

**PASS.** Byte-identical by SHA-256 across all four authorities:

```text
repository-rendered kamailio.cfg          bc14c98ecc0f8ba1c9d3bc8765a62f4d62a1a7d70c0fa2b57e2470daf4f87702
live ConfigMap data                       bc14c98ecc0f8ba1c9d3bc8765a62f4d62a1a7d70c0fa2b57e2470daf4f87702
file mounted in the Pod (/etc/kamailio)   bc14c98ecc0f8ba1c9d3bc8765a62f4d62a1a7d70c0fa2b57e2470daf4f87702
Pod checksum annotation                   bc14c98ecc0f8ba1c9d3bc8765a62f4d62a1a7d70c0fa2b57e2470daf4f87702
```

The entrypoint-materialised `/tmp/kamailio.cfg` differs by design, because it
substitutes the database credential placeholders at startup. No credential value
is recorded here.

Route-authority assertions on the materialised running configuration:

```text
loadmodule "siputils.so"                       1
route[APPLICATION_DIALOG]                      1
old blanket `if ($rm != "REGISTER")` guard     0   <- no longer intercepts INVITE
REGISTER branch present                        1
REGISTER save("location")                      1
record_route() / loose_route()                 1 / 1
rtpengine_offer|answer|delete|manage|rtpproxy  0
msg_apply_changes|subst_body|replace_body      0
```

## Subscriber Authentication Challenge

**PASS.** The unauthenticated out-of-dialog INVITE to `9900` now receives the
canonical digest challenge instead of the previous `405`:

```text
before correction : INVITE sip:9900@sip.utcp.local.test -> 405 Method Not Allowed
after  correction : INVITE sip:9900@sip.utcp.local.test -> 401 Unauthorized
                    challenge realm = sip.utcp.local.test
```

Asterisk received no INVITE before authentication (`0 calls processed`), and
rtpengine recorded no control operation. No Authorization header content,
credential, or digest response is recorded.

## Authenticated Initial INVITE

**PARTIAL — blocked at the relay by `PRODUCT_DEFECT-6`.**

The canonical local subscriber was obtained through the **authorized API only**,
exactly as `scripts/kamailio-signaling/runtime-proof` does: admin login → create
user → membership → member login → `POST /api/v1/telephony/sessions` → `POST
/api/v1/telephony/sessions/{id}/signaling-credential` (`HTTP 201`). No database
insert, no Redis write, no bypass credential, and no preset authenticated
transaction.

```text
credential_issue_http      201
credential_realm           sip.utcp.local.test
credential_username_sha256 d8ef1f35e73bc67bdb162517c5918cdfeb02353bdc7a10bc30f92a1582d54240
telephony_session_sha256   75bfa1befcc0543d4e1b0985a5f0d52b571f3f1bd82f9e6910c5f225d531dac8
```

| Step | Result |
|---|---|
| 1. subscriber authentication succeeds | **PASS** — the second INVITE passed `www_authorize`; no `auth_identity_mismatch` or `403` was logged, and execution reached `route[ASTERISK_RELAY]`, which sits after the authentication guard |
| 2. Kamailio enters the application-dialog route | **PASS** — `kamailio_application_dialog_challenge … method=INVITE` then relay attempt |
| 3. `record_route()` applied | not observable — no forwarded request was produced |
| 4. resolves `asterisk-sip.utcp-runtime.svc.cluster.local` | **PASS** — resolved to `10.43.209.141` (present in the `uri2dst2` error text) |
| 5. relayed statefully | **FAIL** — `t_relay()` could not add a branch |
| 6–10. Asterisk receives INVITE, `from-kamailio`, `9900`, SDP answer | **BLOCKED** |

```text
status sequence : 100 trying -- your call is important to us | 503 Application Runtime Unavailable
final status    : 503
call_id         : 825db1725ad54639@utcp-s2a
from_tag        : 0c26cbd6c4da
to_tag          : 30574a8f11c896b9be9efa95e11948af.f9c90000
cseq            : 2 INVITE
record_route    : (absent)
contact         : (absent)
content_type    : (absent)
body_len        : 0
```

## SDP-Bearing Response

**BLOCKED by `PRODUCT_DEFECT-6`.** No `200 OK` and no SDP were produced. Asterisk
reports `0 active channels, 0 active calls, 0 calls processed` and zero
`INVITE`/`9900`/`Echo` log lines.

## Record-Route Result

**BLOCKED by `PRODUCT_DEFECT-6`.** `record_route()` is present in the running
configuration and `rr.so` is loaded, but no forwarded request or dialog-forming
response was generated, so no `Record-Route` header could be observed on the wire.

## ACK Continuity

**BLOCKED by `PRODUCT_DEFECT-6`.** No dialog was established. The challenge
response was ACKed hop-by-hop as required by RFC 3261, which the corrected
configuration handled without error.

## BYE Continuity

**BLOCKED by `PRODUCT_DEFECT-6`.**

## CANCEL Result

**Bounded live-proof limitation, unchanged.** No dialog reaches a pre-answer
state, so no deterministic CANCEL window exists. The fixture was not altered, no
delay was introduced, and no production configuration was patched. The committed
CANCEL branch (`t_check_trans()` then `t_relay()`) retains its parser and
mutation-test evidence only. No nondeterministic race is claimed as passing.

## Unsupported-Method Result

**PASS**, and now served by the **corrected** running configuration.

```text
MESSAGE sip:9900@sip.utcp.local.test -> 405 Method Not Allowed
kamailio log: kamailio_registration_rejected result=unsupported_method method=MESSAGE
```

`OPTIONS` was not used because it has an existing health role
(`sl_send_reply("200","Keepalive")`). Asterisk received nothing, rtpengine
recorded no operation, and REGISTER remained unaffected.

## Asterisk-Unavailable Result

**NOT PROVEN — and deliberately not induced.**

The committed `503 Application Runtime Unavailable` response was already observed
**with Asterisk healthy and `asterisk-sip` carrying a Ready endpoint
(`10.42.1.218`)**, because `PRODUCT_DEFECT-6` makes `t_relay()` fail for a
transport reason. Scaling Asterisk to zero would therefore produce the same `503`
while proving nothing about the unavailability contract — the test has no
discriminating power in the present state, and the proof contract explicitly
forbids claiming the `503` contract on that basis. Inducing it would only add
avoidable churn to a healthy workload, so **no scale-to-zero condition was
induced** and nothing about the contract is claimed.

Requirements that *were* confirmed while the `503` fired: no alternative Asterisk
destination, no Pod-IP fallback, no ARI routing, no rtpengine routing, no
direct-media path, and no database or RuntimeNode mutation.

## Restoration Result

Not applicable — no unavailability condition was induced. Asterisk remained at
its committed replica count and Ready throughout, with an unchanged Pod UID and
`restarts=0`.

## REGISTER Preservation

**PASS**, proven against the corrected configuration with the canonical T1
tooling (`scripts/kamailio-signaling/sip-wss-client.php`) and the real
API-issued subscriber:

```text
websocket_subprotocol=sip
sip_action=register
sip_status=200
sip_result=accepted
active location contacts = 1
kamailio log: kamailio_registration_accepted result=ok
```

The REGISTER branch was taken, **not** the application-dialog route. Asterisk
received no REGISTER (`0 calls processed`), rtpengine performed no
REGISTER-related operation, and the existing registrar and `location` storage
authority is unchanged.

## rtpengine Boundary Preservation

**PASS.** Unchanged throughout the reproof, as required for this signaling-only
slice.

```text
rtpengine_sessions{own}/{foreign}/total   0 / 0 / 0     (baseline 0 / 0 / 0)
rtpengine_ports_used{internal}/{default}  0 / 0         (baseline 0 / 0)
offer/answer/delete log lines             0
Pod uid fea2c8fc-… restarts 0             unchanged
```

## Workload Preservation

| Workload | Baseline | Final |
|---|---|---|
| `asterisk-ari` | uid `78e463c9-…`, restarts `0` | **identical**, Ready |
| `rtpengine` | uid `fea2c8fc-…`, restarts `0` | **identical**, Ready |
| `kamailio` | old Ready `843bf4db-…` (restarts 40) + failed `5cdeac08-…` (restarts 22) | replaced by the single corrected Pod `8b8ecb96-…` |

A full-cluster Pod snapshot diff contains only the Kamailio replacement: two
Pods removed, one corrected Pod added. **No unrelated workload restarted.**

## State-Authority Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes | 110 | **110** |
| RuntimeNode families | `asterisk/asterisk-ari`, `simulator/simulator-deterministic` | **unchanged** |
| pending outbox | 0 | **0** |
| Redis keys `sip` / `dialog` / `rtp` / `media` | 0 / 0 / 0 / 0 | **0 / 0 / 0 / 0** |

No durable dialog or media authority was introduced. Redis `db0` moved `0 → 5`:
ordinary session and cache entries created by the authorized API calls that
issued the subscriber credential. One proof user, membership, and telephony
session were created **through the canonical API**, matching the existing T1
`runtime-proof` precedent; tenant and RuntimeNode counts are unchanged.

## Findings

| Classification | Finding |
|---|---|
| PASS | `PRODUCT_DEFECT-5` is **closed** — `siputils.so` loads, the running configuration parses cleanly with the authoritative parser, and no `has_totag` error exists |
| PASS | Configuration content automatically changed the Pod template through the `utcp.io/kamailio-config-sha256` annotation; the rollout was fully automatic with **no manual restart** and no timestamp annotation |
| PASS | ReplicaSet convergence was correct: a new revision-12 ReplicaSet took over, the old Ready Pod was retired only **after** the replacement reached Ready, and the previously failing revision-11 ReplicaSet no longer owns a replica |
| PASS | Running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount, and the Pod checksum annotation |
| PASS | The old blanket non-REGISTER `405` guard no longer intercepts INVITE; the REGISTER branch is unchanged; no rtpengine operation or SDP rewriting exists |
| PASS | Unauthenticated INVITE to `9900` receives the canonical `401` challenge with `realm=sip.utcp.local.test` |
| PASS | Subscriber digest authentication for INVITE **succeeds** — execution reached `route[ASTERISK_RELAY]`, which is gated behind the authentication guard |
| PASS | Kamailio resolves the canonical Asterisk Service DNS to `10.43.209.141` |
| PASS | Unsupported method `MESSAGE` returns `405` from the corrected configuration, reaching neither Asterisk nor rtpengine |
| PASS | REGISTER is preserved end to end: `200 accepted`, one active location contact, registrar branch taken, Asterisk and rtpengine untouched |
| PASS | rtpengine remains entirely uninvolved; sessions and port counters unchanged |
| PASS | No durable dialog authority, no canonical state mutation, no unrelated workload restart |
| PASS | All repository checks pass before and after |
| **PRODUCT_DEFECT-6** | Kamailio declares only `listen=tcp:0.0.0.0:8080` and has no UDP socket, so `t_relay()` to the UDP Asterisk destination fails (`no corresponding listening socket`) and the committed `503` fires while Asterisk is healthy. Static and rendered-parser checks pass because syntax validity does not imply a matching transport socket |
| EXPECTED_BEHAVIOR | The corrected Pod restarted once at startup: a transient `postgres … Connection refused` during `auth_db` fixup, the standard new-Pod-IP versus NetworkPolicy programming race. No parser error occurred in either attempt and the immediate restart succeeded |
| EXPECTED_BEHAVIOR | Redis `db0` `0 → 5` from authorized API session/cache activity during credential issuance |
| EXPECTED_BEHAVIOR | The break-glass `user-access-reset-password` command was required because the stored bootstrap administrator password had drifted from the live account. It was used for its documented purpose, on one existing account, and the password was then re-synchronised with `.runtime/identity/bootstrap.json` through the normal auth API |
| EXPECTED_BEHAVIOR | Helm absent; provisioned from the repository pin `HELM_VERSION=v4.0.3` with checksum verification and removed at cleanup |
| PROOF_LIMITATION | The dialog corridor beyond authentication — relayed INVITE, Asterisk execution of `9900`, SDP-bearing answer, `Record-Route`, ACK, and BYE — could not be exercised and is not claimed |
| PROOF_LIMITATION | The Asterisk-unavailable `503` contract cannot currently be proven, because the same `503` already fires with Asterisk healthy. The condition was deliberately not induced rather than producing non-discriminating evidence |
| PROOF_LIMITATION | CANCEL remains without a deterministic live window; the fixture was not altered to create one |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
images built or pushed:         none
resources applied:              2 (kamailio ConfigMap, kamailio Deployment)
manual rollout restart:         none
workloads rolled:               1 (kamailio, automatically via checksum coupling)
unrelated workloads restarted:  none
canonical records mutated:      none beyond authorized API proof data
```

## Cleanup

- The corrected Kamailio Pod is left Ready; Asterisk is left Ready at its committed replica count; rtpengine is left Ready.
- No synthetic SIP proof Pod was created this round — the disposable client runs from the scratch directory through the canonical Traefik/WSS edge.
- The disposable dialog client, credential helper, and parser scratch files were removed; none was added to the repository or the cluster.
- Provisioned Helm binary, archive, checksum file, and extracted artefacts removed; `helm` is no longer on `PATH`.
- No packet capture and no port-forward were used. `.playwright-mcp/` is absent.
- No credential, digest response, or Authorization header content was printed or recorded; the subscriber secret existed only in a private scratch file, now deleted.
- The corrected ConfigMap and Deployment are left applied.

## T3-S2A Final Status After Reproof

```text
PRODUCT_DEFECT-5 = closed
PRODUCT_DEFECT-6 = open (blocks the relay to Asterisk)
T3-S2A repository implementation = Complete
T3-S2A live signaling proof      = INCOMPLETE
T3-S2A                           = In Progress
T3-S2 media mediation            = Not Started
T3                               = In Progress
UTCP_PHASE                       = T1 (unchanged)
```

## Next Exact T3 Target

One bounded Codex correction for `PRODUCT_DEFECT-6`:

1. Add `listen=udp:0.0.0.0:5060` to `kamailio-configmap.yaml` beside the existing
   TCP listener, and declare the matching UDP container port on the Kamailio
   Deployment. The configuration checksum annotation will change automatically,
   so the rollout remains deterministic with no manual restart.
2. Extend `scripts/kamailio-signaling/config-check` to assert that every routing
   destination transport has a matching `listen=` socket, with mutation cases in
   `config-check-test`.

Then resume from the authenticated INVITE only. Already proven at `92365f8` and
not needing repetition: deterministic checksum rollout, ReplicaSet convergence,
parser and module resolution, running-configuration identity, the authentication
challenge, successful subscriber authentication, unsupported-method rejection,
REGISTER preservation, rtpengine non-involvement, and state preservation. The
remaining live steps are the relayed INVITE, Asterisk execution of `9900`, the
SDP-bearing answer, `Record-Route`, ACK, BYE, and a genuine Asterisk-unavailable
`503` observation once the `503` can distinguish real unavailability.

Do not add rtpengine mediation, browser SIP, conference admission, V0, T4,
external trunks, or PSTN.

---

# Authenticated Dialog Reproof (`cbc098e`)

Verdict: `T3_S2A_AUTHENTICATED_DIALOG_REPROOF_INCOMPLETE`

Focused reproof of only the corridor that `PRODUCT_DEFECT-6` blocked. No completed
Asterisk, Service, NetworkPolicy, unauthorized-access, checksum-mechanism, or
public-surface proof was repeated, and no production file was modified.

**`PRODUCT_DEFECT-6` is closed.** Kamailio now owns exactly one UDP `5060`
socket, `t_relay()` builds the outbound branch, the INVITE reaches Asterisk over
the canonical internal Service, Asterisk identifies the internal endpoint,
executes `from-kamailio,9900` through `Answer()` and `Echo()`, and returns a
`200 OK` carrying its own SDP. The prior false healthy-Asterisk `503` is gone.

**Three further exact defects block T3-S2A completion:**

* **`PRODUCT_DEFECT-7`** — the `request_route` domain guard runs *before* loose
  routing, so every in-dialog ACK and BYE is rejected `403 Forbidden`
  (`result=foreign_domain`). ACK never reaches Asterisk and BYE cannot terminate
  the dialog.
* **`PRODUCT_DEFECT-8`** — `record_route()` advertises `sip:0.0.0.0`, an
  unroutable route set, because both listeners bind the wildcard address with no
  advertised address configured.
* **`PRODUCT_DEFECT-9`** — `route[ASTERISK_RELAY]` detects unavailability only
  through the synchronous `t_relay()` return value and arms no failure route, so
  the committed `503 Application Runtime Unavailable` contract is unreachable for
  the condition it was written for. A genuinely absent Asterisk yields a bare
  `408 Request Timeout` after ~30 s with no UTCP-authored rejection signal.

**T3-S2A remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

* Reproof executed at `cbc098e` (`fix(t3): add kamailio udp relay socket`).
* Branch `main`, working tree clean at start, `UTCP_PHASE=T1`, nothing pushed.

Pre-apply static authorities both passed:

```text
make kamailio-signaling-config-check        exit 0  (kamailio_signaling_config_check=pass, live_kamailio_runtime=configured)
make kamailio-signaling-config-check-test   exit 0  (kamailio_signaling_config_check_regression_tests=pass)
```

Render assertions over the canonical local overlay:

```text
listen=tcp:0.0.0.0:8080                      present
listen=udp:0.0.0.0:5060                      present
Asterisk relay destination                   asterisk-sip.utcp-runtime.svc.cluster.local:5060;transport=udp  (exactly one)
loadmodule "siputils.so"                     present
rtpengine offer/answer/delete/manage/rtpproxy 0
Deployment port                              name=sip-udp containerPort=5060 protocol=UDP
sha256(rendered kamailio.cfg)                749fc1ca8a22750531621b259d523ef045e5214e2f997683655bf22731673e7d
Deployment utcp.io/kamailio-config-sha256    749fc1ca8a22750531621b259d523ef045e5214e2f997683655bf22731673e7d
match                                        yes
pod-template annotations                     exactly one (the checksum); no rollout timestamp
image / securityContext / hostNetwork        unchanged / unchanged / absent
```

## Runtime Baseline

```text
kamailio Pod            kamailio-598854cdfc-j9j54  uid 8b8ecb96-…  ready=true  restarts=2  ip 10.42.2.131
kamailio pod checksum   bc14c98ecc0f8ba1c9d3bc8765a62f4d62a1a7d70c0fa2b57e2470daf4f87702
kamailio Deployment     generation 12, rv 438034, 1/1 ready
live ConfigMap          rv 435223, sha256 bc14c98e…
kamailio sockets        TCP LISTEN 0.0.0.0:8080        UDP (none)
asterisk Pod            asterisk-ari-74d8c4b5f8-czd6r  uid 18d866f8-…  ready=true  restarts=0
asterisk-sip Service    ClusterIP 10.43.209.141  5060/UDP
asterisk-sip endpoints  10.42.2.129 ready=true  (EndpointSlice asterisk-sip-6fvq9)
rtpengine Pod           uid 245b78c5-…  ready=true  restarts=0  ip 10.42.0.155
rtpengine counters      sessions{own}=0 sessions{foreign}=0 ports_used{internal}=0 ports_used{default}=0
database                tables 41, dialog/rtp/media tables (none), tenants 27, RuntimeNodes 110, pending outbox 0
redis                   dbsize 0, keys sip/dialog/rtp/media = 0/0/0/0
```

Pre-apply confirmation that the defect was still live — the authenticated INVITE
reached the relay and produced the **false** `503` while Asterisk was healthy with
a Ready endpoint:

```text
INVITE sip:9900@sip.utcp.local.test  ->  401 Unauthorized  (realm sip.utcp.local.test)
authenticated INVITE                 ->  100 trying | 503 Application Runtime Unavailable
kamailio: uri2dst2(): no corresponding socket found for "asterisk-sip.utcp-runtime.svc.cluster.local" af 2 (udp:10.43.209.141:5060)
kamailio: prepare_new_uac(): can't fwd to af 2 (IPv4), proto 1 (udp) (no corresponding listening socket)
kamailio: t_forward_nonack(): failure to add branches
kamailio: kamailio_application_dialog_rejected result=asterisk_unavailable method=INVITE
```

## Resources Applied

Exactly two, applied in the prescribed order. `kubectl diff` restricted to these
two resources contained only the correction and no unrelated drift.

| Resource | Field | Before | After |
|---|---|---|---|
| `ConfigMap/utcp-platform/kamailio-config` | resourceVersion | `435223` | `438503` |
| | data SHA-256 | `bc14c98e…` | **`749fc1ca…`** |
| | `kamailio.cfg` | — | **`+ listen=udp:0.0.0.0:5060`** (one added line) |
| `Deployment/utcp-platform/kamailio` | generation | `12` | `13` |
| | resourceVersion | `438034` | `438512` |
| | `utcp.io/kamailio-config-sha256` | `bc14c98e…` | **`749fc1ca…`** |
| | container ports | `ws 8080/TCP` | **`+ sip-udp 5060/UDP`** |
| | image | `ghcr.io/kamailio/kamailio:5.8.6-bookworm` | **unchanged** |
| | securityContext | present | **unchanged** |
| | rollout timestamp annotation | absent | **absent** |

After the ConfigMap apply and **before** the Deployment apply, the Deployment
remained at generation `12` with the old checksum and the Pod was untouched
(`uid 8b8ecb96-…`, `restarts=2`) — the running process does not reparse the
mounted file dynamically. **No reload RPC, no `kubectl rollout restart`, no
manual Pod deletion, and no timestamp annotation were used.**

## Kamailio Rollout Result

```text
deployment applied   : 2026-07-31T20:28:07Z
rollout complete     : 2026-07-31T20:28:12Z  (~5 seconds)
new ReplicaSet       : kamailio-ddc74bb7b  revision 13  desired 1  ready 1
new Pod              : kamailio-ddc74bb7b-wrj7w  uid 7b9f5f4a-1ab8-48a2-9d8f-848a6ec63f25  ip 10.42.2.136
new Pod checksum     : 749fc1ca…  (equals the rendered kamailio.cfg)
old Pod retirement   : Killing kamailio-598854cdfc-j9j54 emitted after the replacement was Started
old ReplicaSet       : revision 12 scaled to 0
conditions           : Available=True (MinimumReplicasAvailable), Progressing=True (NewReplicaSetAvailable)
manual restart       : none
unrelated workloads rolled : none
```

Running-configuration identity holds across every authority:

```text
repository-rendered kamailio.cfg          749fc1ca8a22750531621b259d523ef045e5214e2f997683655bf22731673e7d
live ConfigMap data                       749fc1ca8a22750531621b259d523ef045e5214e2f997683655bf22731673e7d
file mounted in the Pod (/etc/kamailio)   749fc1ca8a22750531621b259d523ef045e5214e2f997683655bf22731673e7d
Pod checksum annotation                   749fc1ca8a22750531621b259d523ef045e5214e2f997683655bf22731673e7d
```

**Divergence — one transient restart.** The corrected Pod shows `restarts=1`. The
first container start exited with `db_postgres … connection to server at
"postgres.utcp-data…" port 5432 failed: Connection refused` → `auth_db … unable to
open database connection` → `fix_actions(): fixing failed`. This is the known race
between a brand-new Pod IP and NetworkPolicy datapath programming, identical to the
`92365f8` round, and contains no parser error. The immediate kubelet restart
succeeded. Classified `EXPECTED_BEHAVIOR`.

## Live UDP Listener Result

**PASS.**

Kamailio's own startup banner from the running container:

```text
Listening on
             udp: 0.0.0.0 [0.0.0.0]:5060
             tcp: 0.0.0.0 [0.0.0.0]:8080
Aliases:
             *: sip.utcp.local.test:*
```

Kernel socket table inside the real Pod:

```text
TCP LISTEN 0.0.0.0:8080
UDP        0.0.0.0:5060
```

| Requirement | Result |
|---|---|
| TCP LISTEN `0.0.0.0:8080` | present |
| UDP socket `0.0.0.0:5060` | present |
| exactly one Kamailio UDP SIP listener | yes (`grep -c '^listen=' = 2`, one udp, one tcp) |
| TCP SIP `5060` | absent |
| TLS, WS, or WSS listener added | none |
| HostPort / HostNetwork | absent / absent |
| node UDP `5060` socket (all three k3d nodes) | none |
| developer-host UDP `5060` socket | none |
| Kamailio UDP Service | none — `utcp-platform/kamailio` remains ClusterIP `8080/TCP` only |
| NodePort / LoadBalancer for Kamailio | none |
| `UDPRoute` | CRD not present in the cluster |
| only UDP `5060` Service cluster-wide | `utcp-runtime/asterisk-sip` ClusterIP (internal, expected) |
| startup errors (`no corresponding socket`, `prepare_new_uac`, parser, missing module, CRITICAL) | **zero ERROR lines in the running container** |

## Authenticated Initial INVITE

**PASS.**

The subscriber was obtained through the **authorized API only** (admin login →
create user → membership → member login → forced password change → tenant context
→ `POST /api/v1/telephony/sessions` → `POST
/api/v1/telephony/sessions/{id}/signaling-credential`, `HTTP 201`). No database
insert, no Redis write, no bypass credential, no manually inserted SIP account.

```text
credential_issue_http      201
credential_realm           sip.utcp.local.test
credential_username_sha256 bed5d0725f4ace24102179e7c4b29ed870f03ef958dd17ab1a0f16e2ba107411
telephony_session_sha256   85e2383172a775ac0c3409cd23a3ea2933dbee3f91e1e5dfadeb2d3df7657239
```

**Operational note.** A signaling credential carries a bounded ~5-minute TTL
(`kamailio_signaling_auth_view` requires `expires_at > now()`), so each SIP
corridor was given a freshly re-issued credential on the same telephony session
through the same authorized member endpoint. An expired credential correctly
produces a repeated `401` rather than acceptance — itself a preserved
authorization behaviour.

Representative successful dialog (`hold-seconds=14` run):

```text
call_id              51fff9f879d75d52@utcp-s2a-dialog
from_tag             b3452aaf6025
to_tag               b2c5df75-27a4-4c8b-bf00-3055707bfbab
cseq                 2 INVITE
status sequence      401 Unauthorized -> 100 trying -- your call is important to us -> 200 OK
record_route         <sip:0.0.0.0;lr;r2=on;ftag=b3452aaf6025>, <sip:0.0.0.0:8080;transport=ws;lr;r2=on;ftag=b3452aaf6025>
contact              <sip:10.42.2.129:5060>
content_type         application/sdp
body_len             220
```

| Step | Result |
|---|---|
| 1. authentication succeeds | **PASS** — one `401` challenge, then the authenticated INVITE passed `www_authorize` with no `auth_identity_mismatch` and no `403` |
| 2. Kamailio enters `route[APPLICATION_DIALOG]` | **PASS** — `kamailio_application_dialog_challenge … method=INVITE call_id=…` then relay |
| 3. `record_route()` applied | **PASS in placement, DEFECTIVE in content** — two `Record-Route` headers are present on the `200 OK`, so Kamailio is in the route set, but both advertise `0.0.0.0` (`PRODUCT_DEFECT-8`) |
| 4. resolves the canonical Asterisk Service | **PASS** — relayed to `asterisk-sip.utcp-runtime.svc.cluster.local` |
| 5. `t_relay()` creates and forwards the UDP branch | **PASS** — no `uri2dst2`, `prepare_new_uac`, or `t_forward_nonack` error |
| 6. prior healthy-Asterisk `503` does not occur | **PASS** — zero `result=asterisk_unavailable` lines with Asterisk healthy |
| 7. Asterisk receives the INVITE | **PASS** — channel created, `calls processed` incremented on every attempt |
| 8. Asterisk identifies the internal endpoint | **PASS** — `PJSIP/anonymous-…`, endpoint `anonymous`, transport `transport-udp-internal` `0.0.0.0:5060` |
| 9. Asterisk executes `from-kamailio,9900` | **PASS** — see below |
| 10. client receives a successful SDP-bearing response | **PASS** — `200 OK` with `application/sdp` |

Live Asterisk channel captured **while the dialog was established**:

```text
Channel                   Context        Extension Prio State Application Data     Duration
PJSIP/anonymous-00000001  from-kamailio  9900         3 Up    Echo        (Empty)  00:00:25

core show channel PJSIP/anonymous-00000001
          State: Up (6)
  NativeFormats: (ulaw)
        Context: from-kamailio
      Extension: 9900
       Priority: 3
    Application: Echo
```

Endpoint authority confirmed unchanged (`pjsip show endpoint anonymous`):

```text
context        : from-kamailio
direct_media   : false
transport      : transport-udp-internal  udp  0.0.0.0:5060
```

No credential, Authorization header, or digest response is recorded anywhere in
this document or in any retained trace; the disposable client redacts
`Authorization`, `Proxy-Authorization`, `WWW-Authenticate`, and
`Proxy-Authenticate` before writing a trace line.

## SDP-Bearing Response

**PASS.**

```text
Content-Type: application/sdp
body_len     219-220 bytes
o=- 3821 3 IN IP4 10.42.2.129        <- Asterisk Pod IP (origin)
s=Asterisk
c=IN IP4 10.42.2.129                 <- Asterisk Pod IP (connection)
t=0 0
m=audio 10994 RTP/AVP 0 101          <- Asterisk-selected port, PCMU + telephone-event
a=rtpmap:0 PCMU/8000
a=rtpmap:101 telephone-event/8000
a=ptime:20
a=maxptime:140
a=sendrecv
```

| Requirement | Result |
|---|---|
| SDP originates from Asterisk | **PASS** — `s=Asterisk`, origin and connection addresses equal the Asterisk Pod IP, media port drawn from the Asterisk RTP range (`21574`, `7080`, `10994` across runs) |
| no rtpengine rewriting | **PASS** — addresses and ports are Asterisk's own; running configuration contains zero `rtpengine_*`/`rtpproxy` directives and zero `msg_apply_changes`/`subst_body`/`replace_body` |
| no rtpengine session created | **PASS** — `rtpengine_sessions{own}=0`, `{foreign}=0`, `ports_used{internal}=0`, `{default}=0`, zero offer/answer/delete log lines |
| response traverses Kamailio | **PASS** — delivered to the client over the WSS leg, with Kamailio's `Record-Route` present |
| no direct Asterisk-to-client signaling path | **PASS** — the client holds only the WSS connection to Kamailio; Asterisk has no route to the browser leg |

Recorded for later T3-S2 comparison: with no mediation, the answer carries the
**Asterisk Pod IP** in both `o=` and `c=`, and an Asterisk-chosen RTP port. T3-S2
must replace exactly these values with rtpengine addressing.

## Record-Route Result

**PARTIAL — Kamailio is in the route set, but the advertised URIs are unroutable
(`PRODUCT_DEFECT-8`).**

`record_route()` executes and produces correct **double** record-routing for the
WSS-to-UDP transport change, with `;lr`, `;r2=on`, and `;ftag=` all present:

```text
Record-Route: <sip:0.0.0.0;lr;r2=on;ftag=…>, <sip:0.0.0.0:8080;transport=ws;lr;r2=on;ftag=…>
```

The topmost value is the Asterisk-facing UDP socket and the second is the
client-facing WS socket, which is the correct ordering. Reversed per RFC 3261
§12.1.2 the UAC route set is:

```text
route_set_1  sip:0.0.0.0:8080;transport=ws;lr;r2=on;ftag=…
route_set_2  sip:0.0.0.0;lr;r2=on;ftag=…
remote_target sip:10.42.2.129:5060
```

Both hops advertise the wildcard address `0.0.0.0` rather than a reachable
address, because both listeners bind `0.0.0.0` and no advertised address is
configured. See `PRODUCT_DEFECT-8`.

## ACK Continuity

**FAIL — `PRODUCT_DEFECT-7`.**

The ACK was built correctly: Request-URI equal to the negotiated remote target,
both `Route` headers from the reversed route set, matching `Call-ID`, `From` tag,
`To` tag, and `CSeq 2 ACK`, and **no** repeated `Authorization` header.

Kamailio rejected it before reaching the loose-routing branch. Isolated with an
INVITE+ACK-only run, which produced **exactly one** rejection:

```text
kamailio: kamailio_websocket_accepted result=ok
kamailio: kamailio_application_dialog_challenge result=challenge method=INVITE call_id=f435c47182532256@utcp-s2a-dialog
kamailio: kamailio_registration_rejected result=foreign_domain      <- the ACK
foreign_domain_count = 1
```

Consequences observed: Asterisk never received the ACK, retransmitted its
`200 OK` (visible once the client had closed the WSS leg as
`msg_send_buffer(): TCP/TLS connection for WebSocket could not be found` and
`do_forward_reply(): cannot forward reply`), and left the channel `Up` until its
own transaction timer tore the session down. Two such channels were briefly
concurrent (`2 active channels, 2 active calls`) before self-terminating; no
manual cleanup was performed and none was ultimately required, but termination
came from an Asterisk timeout rather than dialog signaling.

## BYE Continuity

**FAIL — `PRODUCT_DEFECT-7`.**

```text
BYE sip:10.42.2.129:5060  (Route set applied, CSeq 3 BYE)
  -> 403 Forbidden                       <- generated by Kamailio itself
bye_call_id   51fff9f879d75d52@utcp-s2a-dialog   (matches the INVITE)
bye_from_tag  b3452aaf6025                        (matches)
bye_to_tag    b2c5df75-27a4-4c8b-bf00-3055707bfbab (matches)
bye_cseq      3 BYE
dialog_terminated  false
kamailio: kamailio_registration_rejected result=foreign_domain
```

Call-ID and both tags remain fully consistent, so the client's dialog state is
correct; the proxy refuses the request. The BYE never reached Asterisk, no
successful response was produced, and the Echo dialog did not terminate through
signaling. Reproduced identically on every dialog run, including after Asterisk
restoration.

## PRODUCT_DEFECT-7 — the domain guard precedes loose routing, so all in-dialog requests are rejected `403`

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — in `request_route`, `if ($rd != "sip.utcp.local.test") { sl_send_reply("403","Forbidden"); exit; }` executes **before** `route(APPLICATION_DIALOG)`, and therefore before `has_totag()` → `route(WITHINDLG)` → `loose_route()` |
| Expected | In-dialog ACK and BYE are loose-routed to the same Asterisk dialog; ACK confirms the session and BYE terminates it with a successful response |
| Actual | The Request-URI of every in-dialog request is the negotiated remote target — Asterisk's `Contact`, `sip:10.42.2.129:5060` — whose `$rd` is the Asterisk Pod IP, not `sip.utcp.local.test`. The guard rejects it with `403 Forbidden` and logs `kamailio_registration_rejected result=foreign_domain`. `route[WITHINDLG]` and `loose_route()` are unreachable for real dialogs |
| SIP responses | ACK: rejected (`403` is discarded by the UAC per RFC 3261, so the ACK is silently lost). BYE: `403 Forbidden` returned to the client |
| Kamailio log | `WARNING: <script>: kamailio_registration_rejected result=foreign_domain` — exactly once per in-dialog request; isolated to one occurrence with an INVITE+ACK-only run |
| Downstream effect | Asterisk retransmits the unacknowledged `200 OK`, keeps the channel `Up`, and finally tears it down on its own timer. Dialogs cannot be ended by BYE, so channels accumulate for the duration of the retransmission window |
| Parser and static checks | **All passed** — `make kamailio-signaling-config-check` and `…-config-check-test` are green, and the rendered-parser run is clean. Route *ordering* semantics are not asserted by any check, and the log message reuses the REGISTER-oriented `kamailio_registration_rejected` label, which further masks the condition |
| Severity | **Blocking.** Completion criteria 9 and 10 cannot be met, and no dialog can be terminated through the control plane |

### Smallest bounded correction

1. Move the destination-domain guard so it applies only to **out-of-dialog**
   requests. Either evaluate `has_totag()` (or `loose_route()`) before the domain
   check, or scope the domain check to the initial-request branch inside
   `route[APPLICATION_DIALOG]`. Sequential in-dialog requests must be routed by
   the route set, never by the Request-URI host.
2. Give the in-dialog rejection its own log label, distinct from
   `kamailio_registration_rejected`, so a dialog-routing failure is not reported as
   a registration failure.
3. Extend `scripts/kamailio-signaling/config-check` with an ordering assertion —
   the domain guard must not dominate the loose-routing branch — plus a
   `config-check-test` mutation that reintroduces the guard ahead of `has_totag()`.

## PRODUCT_DEFECT-8 — `record_route()` advertises the unroutable wildcard address `0.0.0.0`

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — `listen=tcp:0.0.0.0:8080` and `listen=udp:0.0.0.0:5060` bind the wildcard address with no `advertise` clause and no `advertised_address`, so `record_route()` derives its URIs from `0.0.0.0` |
| Expected | The route set advertises addresses that both peers can actually reach, so either party can send in-dialog requests along it |
| Actual | `Record-Route: <sip:0.0.0.0;lr;r2=on;ftag=…>, <sip:0.0.0.0:8080;transport=ws;lr;r2=on;ftag=…>`. `0.0.0.0` is not a routable destination for any peer |
| Observed on the wire | Every `200 OK` in this reproof, verbatim as above |
| Why the client still reached Kamailio | The proof client is a SIP-over-WSS UAC (RFC 7118): its transport is the established WebSocket connection, so it sends in-dialog requests on that connection regardless of the Route URI. The wildcard address is therefore *latent* in the client-to-Asterisk direction |
| Not yet live-proven | The Asterisk-to-client direction is the one that genuinely breaks: an Asterisk-initiated in-dialog request (BYE on hangup, session-timer re-INVITE) must route to `sip:0.0.0.0:5060`. Confirming it live requires a **confirmed** dialog, which `PRODUCT_DEFECT-7` prevents, so this consequence is recorded as reasoned impact and not claimed as observed |
| Parser and static checks | **All passed.** `config-check` deliberately *requires* `listen=udp:0.0.0.0:5060` and forbids Pod-IP, node-IP, ClusterIP, and developer-host listener bindings, so wildcard binding is currently the mandated form and nothing asserts that the advertised address is routable |
| Severity | Blocking for bidirectional in-dialog signaling; latent for the WSS client direction |

### Smallest bounded correction

1. Keep the wildcard **bind** and add an explicit **advertised** address per
   listener using Kamailio's `listen=… advertise …` form: a stable internal name
   for the UDP socket that Asterisk can resolve, and the public SIP hostname for
   the WS socket. Do not hard-code a Pod IP or node IP.
2. Note the coupling: `scripts/kamailio-signaling/config-check:258-259` currently
   fails **any** Service selecting Kamailio that exposes a UDP port, without
   distinguishing an internal ClusterIP port from public exposure. If Asterisk must
   reach Kamailio's UDP socket by a stable name, that assertion needs narrowing to
   public surface only (NodePort, LoadBalancer, `UDPRoute`, HostPort) while
   continuing to forbid public exposure.
3. Add `config-check` coverage asserting every listener has a routable advertised
   address, with a mutation case that removes it.

## CANCEL Result

**Bounded live-proof limitation, unchanged.** The `9900` fixture is
`NoOp → Answer() → Echo() → Hangup()`, so Asterisk answers immediately and no
deterministic pre-answer window exists — `100 Trying` and `200 OK` arrive within
the same instant on every run. The fixture was not altered, no delay was added to
any production configuration, and no race is claimed as passing. The committed
CANCEL branch (`t_check_trans()` then `t_relay()`) retains its parser and
mutation-test evidence only.

A second scale-to-zero cycle would have created a 30-second pre-answer window,
but the declared state-preservation boundary for this proof permits exactly one
intentional Asterisk `1 → 0 → 1` availability test, so no additional availability
perturbation was induced for a non-blocking corridor.

## Healthy-Asterisk Baseline

**PASS.** Recorded immediately before inducing unavailability, to give the
following section discriminating power.

```text
asterisk-sip ready endpoints   10.42.2.129:true   (exactly one)
calls processed                4  ->  5
authenticated INVITE           200 OK
extension 9900 executes        yes (from-kamailio, priority 3, Echo)
SDP response returned          application/sdp, c=IN IP4 10.42.2.129
```

## Asterisk-Unavailable Result

**`PRODUCT_DEFECT-9` — the committed `503` does not occur.**

Condition classified `INTENTIONALLY_INDUCED_CONDITION`: only the canonical
`Deployment/utcp-runtime/asterisk-ari` (the deployment selected by
`Service/asterisk-sip` via `utcp.dev/runtime-node=local-asterisk-ari`) was scaled
`1 → 0`. `asterisk-ari-b` was not touched.

```text
scale 1 -> 0                          deployment.apps/asterisk-ari scaled
asterisk-sip Ready endpoints          0   (endpoints: null)  after 2s
pods for local-asterisk-ari           0
```

Authenticated INVITE with the destination absent, observed for 75 seconds:

```text
20:36:26Z  INVITE sip:9900@sip.utcp.local.test  ->  401 Unauthorized
           authenticated INVITE                 ->  100 trying -- your call is important to us
20:36:56Z                                       ->  408 Request Timeout   (~30s)
to_tag     594d50c3218065a60bb91fd47a70fbc1-06dd0000
kamailio log: challenge line only — NO result=asterisk_unavailable, NO 503
```

| Requirement | Result |
|---|---|
| `503 Application Runtime Unavailable` | **NOT OBSERVED** — `PRODUCT_DEFECT-9` |
| actual result, accurately classified | `100 Trying` then **`408 Request Timeout`** after ~30 s (tm `fr_inv_timer`) |
| second Asterisk destination | **none** — `asterisk-ari-b` retained uid `8a904cdd-…`, restarts `13`, and reports `0 calls processed`: it never received anything |
| Pod-IP fallback | **none** — running configuration contains zero `10.42.` literals |
| ARI fallback | **none** |
| rtpengine routing | **none** — sessions and ports_used remained `0` |
| direct-media bypass | **none** |
| database or RuntimeNode mutation | **none** — RuntimeNodes `110`, tenants `27`, unchanged |
| exactly one relay destination in the running config | **yes** |

### PRODUCT_DEFECT-9 — `route[ASTERISK_RELAY]` cannot detect an unavailable Asterisk

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — `route[ASTERISK_RELAY]` sets `$du` and then relies solely on `if (!t_relay()) { … sl_send_reply("503","Application Runtime Unavailable"); }`. No `t_on_failure(...)` is armed and no `failure_route` exists anywhere in the configuration |
| Expected | An absent Asterisk yields `503 Application Runtime Unavailable` plus `kamailio_application_dialog_rejected result=asterisk_unavailable` |
| Actual | `t_relay()` returns **success**: `asterisk-sip.utcp-runtime.svc.cluster.local` resolves to its ClusterIP whether or not any endpoint is Ready, and UDP surfaces no immediate delivery failure, so the branch is created. The failure becomes known only asynchronously through the tm timer, which emits a bare `408 Request Timeout` after ~30 s. The `if (!t_relay())` body — and therefore the entire committed `503` contract and its log line — is unreachable for genuine unavailability |
| SIP responses | `100 Trying`, then `408 Request Timeout` |
| Kamailio log | only `kamailio_application_dialog_challenge`; **zero** `result=asterisk_unavailable` lines. There is no UTCP-authored signal that the runtime was unavailable |
| Protocol note | `408` is itself protocol-legal, so this is a **contract and observability** defect rather than a protocol violation: the committed response, its reason phrase, and its log signal never fire, and the caller waits ~30 s instead of being rejected promptly |
| Relationship to `PRODUCT_DEFECT-6` | The synchronous `!t_relay()` branch was only ever reachable because of the missing UDP socket. Closing `PRODUCT_DEFECT-6` removed the false trigger and revealed that the branch has no true trigger |
| Parser and static checks | **All passed.** No check asserts that a failure route is armed before `t_relay()` |
| Severity | **Blocking** for the committed unavailability contract (completion criterion 11 is observed and classified, but the contract itself is unmet) |

#### Smallest bounded correction

1. Arm a failure route on the relay transaction — `t_on_failure("ASTERISK_UNAVAILABLE")`
   immediately before `t_relay()` in `route[ASTERISK_RELAY]` — and add
   `failure_route[ASTERISK_UNAVAILABLE]` that converts a timeout or a `408`/`5xx`
   branch result into the committed `503 Application Runtime Unavailable` via
   `t_reply()`, emitting the existing
   `kamailio_application_dialog_rejected result=asterisk_unavailable` log.
2. Keep the existing synchronous `!t_relay()` guard for immediate failures.
3. Optionally bound the rejection window with a relay-scoped `$fr_inv_timer` so
   an unavailable runtime is reported well before ~30 s.
4. Extend `scripts/kamailio-signaling/config-check` to assert that
   `route[ASTERISK_RELAY]` arms a failure route before `t_relay()` and that the
   failure route replies `503`, with `config-check-test` mutations removing the
   `t_on_failure` call and the failure route.

## Restoration Result

**PASS.** Restored to the committed replica count read from the canonical render
(`replicas: 1`).

```text
scale 0 -> 1                     deployment.apps/asterisk-ari scaled
rollout                          successfully rolled out (automatic)
new Asterisk Pod                 asterisk-ari-74d8c4b5f8-mjkj2  uid 5497e140-…  ready=true  restarts=0
PJSIP transport                  transport-udp-internal  udp  0.0.0.0:5060      (active)
asterisk-sip Ready endpoints     10.42.1.223 ready=true   (exactly one)
authenticated INVITE             200 OK, application/sdp, c=IN IP4 10.42.1.223
extension 9900                   executed (1 call processed)
ACK / BYE                        still 403 foreign_domain — PRODUCT_DEFECT-7, unchanged
manual application reconciliation none
unrelated workload restarts      none
```

The SDP answer now carries the **new** Pod IP `10.42.1.223`, proving Kamailio
resolves the Service on each relay with no stale address pinning. Asterisk is
left Ready.

## REGISTER and Unsupported-Method Preservation

**PASS**, both against the corrected running configuration.

```text
MESSAGE sip:9900@sip.utcp.local.test -> 405 Method Not Allowed
kamailio: kamailio_registration_rejected result=unsupported_method method=MESSAGE

REGISTER (canonical scripts/kamailio-signaling/sip-wss-client.php, API-issued subscriber)
websocket_subprotocol=sip
sip_action=register
sip_status=200
sip_result=accepted
active_location_contacts=1
kamailio: kamailio_registration_challenge result=challenge
kamailio: kamailio_registration_accepted result=ok
```

The REGISTER branch was taken, not the application-dialog route. `OPTIONS` was
not used because it retains its health role (`sl_send_reply("200","Keepalive")`).
Asterisk received no REGISTER and rtpengine performed no operation.

## rtpengine Boundary Preservation

**PASS.** Unchanged throughout, as required for this signaling-only slice.

```text
rtpengine_sessions{own}/{foreign}          0 / 0        (baseline 0 / 0)
rtpengine_ports_used{internal}/{default}   0 / 0        (baseline 0 / 0)
offer / answer / delete log lines          0 / 0 / 0
Pod uid 245b78c5-…  restarts 0             unchanged
running Kamailio config rtpengine ops      0
```

## Public-Surface Preservation

**PASS.**

```text
Kamailio Service            utcp-platform/kamailio  ClusterIP  8080/TCP only
Kamailio UDP Service        absent
NodePort / LoadBalancer     absent for Kamailio
UDPRoute                    CRD not present
HostPort / HostNetwork      absent
node UDP 5060               none on any of the three k3d nodes
developer-host UDP 5060     none
application edge            unchanged
```

## State-Authority Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes | 110 | **110** |
| pending outbox | 0 | **0** |
| Redis keys `sip`/`dialog`/`rtp`/`media` | 0/0/0/0 | **0/0/0/0** |
| rtpengine sessions | 0 | **0** |
| rtpengine ports used | 0 | **0** |

No durable SIP-dialog or media authority appeared. Redis `db0` moved `0 → 3`:
ordinary session and cache entries from the authorized API calls that issued the
proof credentials. One proof user, membership, and telephony session were created
**through the canonical API**, matching the existing T1 `runtime-proof` precedent;
tenant and RuntimeNode counts are unchanged.

Full-cluster Pod snapshot diff contains exactly two changes:

```text
- utcp-platform kamailio-598854cdfc-j9j54     8b8ecb96-…  restarts 2
+ utcp-platform kamailio-ddc74bb7b-wrj7w      7b9f5f4a-…  restarts 1   <- corrected rollout
- utcp-runtime  asterisk-ari-74d8c4b5f8-czd6r 18d866f8-…  restarts 0
+ utcp-runtime  asterisk-ari-74d8c4b5f8-mjkj2 5497e140-…  restarts 0   <- intentional 1->0->1 test
```

Every other Pod retained its UID **and** restart count. **No unrelated workload
restarted.**

## Findings

| Classification | Finding |
|---|---|
| PASS | **`PRODUCT_DEFECT-6` is closed** — Kamailio owns exactly one UDP `0.0.0.0:5060` socket, confirmed by its own startup banner and the Pod's kernel socket table; zero `no corresponding socket`, `prepare_new_uac`, or `t_forward_nonack` errors remain |
| PASS | Only the two intended resources were applied, in order, with `kubectl diff` showing exactly the one added `listen=` line, the recalculated checksum, and the new `sip-udp` UDP container port; image and securityContext unchanged; no rollout timestamp |
| PASS | The ConfigMap apply alone changed nothing about the Deployment or Pod, confirming the running process does not reparse the mounted file; the checksum-coupled Deployment apply produced a fully automatic ~5-second rollout to ReplicaSet revision 13 with **no manual restart**, retiring the old Pod only after the replacement started |
| PASS | Running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount, and Pod checksum annotation (`749fc1ca…`) |
| PASS | Authenticated INVITE reaches healthy Asterisk over the canonical internal Service; the prior false healthy-Asterisk `503` is **gone** |
| PASS | Asterisk identifies the internal `anonymous` endpoint and executes `from-kamailio` extension `9900` through `Answer()` to `Echo()`, captured live as `PJSIP/anonymous-00000001 … Prio 3 Up Echo` |
| PASS | A valid SDP-bearing `200 OK` returns through Kamailio with Asterisk's own origin, connection address, and RTP port; no rtpengine rewriting and no rtpengine session |
| PASS | `record_route()` executes and produces correct double record-routing with `;lr`, `;r2=on`, and `;ftag=`, keeping Kamailio in the route set |
| PASS | Restoration is automatic: PJSIP UDP `5060` returns, `asterisk-sip` regains exactly one Ready endpoint, and the authenticated INVITE succeeds again against the **new** Pod IP with no stale address pinning and no manual reconciliation |
| PASS | REGISTER preserved (`200 accepted`, one active contact, registrar branch) and unsupported `MESSAGE` still `405`, both from the corrected configuration |
| PASS | rtpengine entirely uninvolved; no public Kamailio UDP surface; no durable dialog or media authority; no canonical state mutation; no unrelated workload restart |
| PASS | All required repository checks pass before and after |
| **PRODUCT_DEFECT-7** | The `request_route` domain guard precedes loose routing, so every in-dialog ACK and BYE — whose Request-URI is the negotiated Asterisk remote target — is rejected `403 Forbidden` (`result=foreign_domain`). ACK never reaches Asterisk and BYE cannot terminate the dialog. Blocking |
| **PRODUCT_DEFECT-8** | `record_route()` advertises `sip:0.0.0.0` for both hops because the listeners bind the wildcard address with no advertised address, yielding an unroutable route set. Latent for the WSS client direction; blocking for Asterisk-initiated in-dialog requests |
| **PRODUCT_DEFECT-9** | `route[ASTERISK_RELAY]` detects unavailability only through the synchronous `t_relay()` return value and arms no failure route, so the committed `503 Application Runtime Unavailable` is unreachable for genuine unavailability; an absent Asterisk yields a bare `408 Request Timeout` after ~30 s with no UTCP-authored rejection signal |
| INTENTIONALLY_INDUCED_CONDITION | `Deployment/utcp-runtime/asterisk-ari` scaled `1 → 0 → 1` to test the unavailability contract; `asterisk-ari-b` untouched and unused; restored to the committed replica count and left Ready |
| EXPECTED_BEHAVIOR | The corrected Kamailio Pod restarted once at startup on a transient `postgres … Connection refused` during `auth_db` fixup — the known new-Pod-IP versus NetworkPolicy programming race, with no parser error in either attempt |
| EXPECTED_BEHAVIOR | Signaling credentials carry a bounded ~5-minute TTL, so an expired credential yields a repeated `401`; each corridor was given a freshly re-issued credential through the same authorized member endpoint |
| EXPECTED_BEHAVIOR | Redis `db0` `0 → 3` from authorized API session and cache activity during credential issuance |
| PROOF_LIMITATION | CANCEL still has no deterministic pre-answer window; the `9900` fixture answers immediately and was not altered |
| PROOF_LIMITATION | The Asterisk-to-client consequence of `PRODUCT_DEFECT-8` cannot be live-proven until `PRODUCT_DEFECT-7` is corrected, because it requires a confirmed dialog |
| PROOF_LIMITATION | `res_pjsip_logger` is not in the committed `autoload=no` module set, so no Asterisk-side SIP wire trace was captured. Asterisk-side evidence is the live channel table, endpoint state, and `calls processed` counters; no module was added to production configuration |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
images built or pushed:         none
resources applied:              2 (kamailio ConfigMap, kamailio Deployment)
manual rollout restart:         none
workloads rolled:               1 (kamailio, automatically via checksum coupling)
availability tests induced:      1 (asterisk-ari 1 -> 0 -> 1, restored)
unrelated workloads restarted:  none
canonical records mutated:      none beyond authorized API proof data
```

## Cleanup

- Corrected Kamailio left Ready (`kamailio-ddc74bb7b-wrj7w`); corrected ConfigMap and Deployment left applied.
- Asterisk restored to its committed replica count and left Ready; rtpengine left Ready with counters at zero.
- No synthetic SIP proof Pod was created — the disposable clients ran from the scratch directory through the canonical Traefik/WSS edge.
- Asterisk diagnostics were console-only (`core set verbose 5`) and reverted automatically when the Pod was replaced during the availability test; current console verbosity is back to the default `0`. No module was loaded and no Asterisk configuration file was touched.
- The proof contact was deregistered through the canonical client (`sip_status=200`, `active_location_contacts=0`) and the telephony session was ended through the authorized API (`HTTP 200`), leaving `kamailio_signaling_auth_view` rows for the proof user at `0`.
- Disposable dialog client, method probe, credential helpers, cookie jar, secret file, and rendered scratch manifests removed; none was added to the repository or the cluster.
- No packet capture and no port-forward were used. `.playwright-mcp/` is absent.
- No credential, digest response, or Authorization header content was printed or recorded; traces redact `Authorization`, `Proxy-Authorization`, `WWW-Authenticate`, and `Proxy-Authenticate`.

## T3-S2A Final Status After Authenticated Dialog Reproof

```text
PRODUCT_DEFECT-5 = closed
PRODUCT_DEFECT-6 = closed
PRODUCT_DEFECT-7 = open (in-dialog ACK/BYE rejected 403 foreign_domain)
PRODUCT_DEFECT-8 = open (record_route advertises 0.0.0.0)
PRODUCT_DEFECT-9 = open (no failure route; committed 503 unreachable)
T3-S2A repository implementation = Complete
T3-S2A live signaling proof      = INCOMPLETE
T3-S2A                           = In Progress
T3-S2 media mediation            = Not Started
T3                               = In Progress
UTCP_PHASE                       = T1 (unchanged)
```

## Recommended Next Step

Bounded Codex implementation of `PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`, and
`PRODUCT_DEFECT-9` in `infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`
with matching `scripts/kamailio-signaling/config-check` and `config-check-test`
coverage, then a focused reproof of ACK continuity, BYE continuity, and the
Asterisk-unavailable `503` only. Everything else in this document is proven and
must not be repeated.

Do not add rtpengine mediation, fallback destinations, public SIP exposure,
feature gates, manual activation, browser media, conference admission, V0, T4,
external trunks, or PSTN.

---

# Final In-Dialog and Unavailable-Runtime Reproof (`081267a`)

Verdict: `T3_S2A_FINAL_IN_DIALOG_REPROOF_INCOMPLETE`

Focused reproof of only the corridors that `PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`,
and `PRODUCT_DEFECT-9` blocked. No completed authentication, relay, Asterisk
foundation, checksum-mechanism, or public-surface proof was broadly repeated, and
no production file was modified.

**All three targeted defects are closed:**

* **`PRODUCT_DEFECT-7` closed** — established-dialog routing now precedes
  initial-domain validation. In-dialog ACK and BYE are loose-routed instead of
  rejected. Post-ACK `200 OK` retransmissions dropped from **3 to 0**, and the
  client-originated BYE now returns **`200 OK`** and terminates the channel.
* **`PRODUCT_DEFECT-8` closed at its seam** — `record_route()` now advertises
  `sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060` and
  `sip:sip.utcp.local.test:443;transport=ws`. No `0.0.0.0`, Pod IP, or node IP
  appears in any route identity.
* **`PRODUCT_DEFECT-9` closed** — zero Ready Asterisk endpoints now produce an
  explicit **`503 Application Runtime Unavailable`** through
  `failure_route[ASTERISK_UNAVAILABLE]`, with the correlated
  `result=asterisk_unavailable` log. The previous bare `408` is gone.

**One new defect blocks completion:**

* **`PRODUCT_DEFECT-10`** — the reverse corridor grants the canonical Asterisk
  workload UDP `5060` egress to Kamailio but **no DNS egress**. Because the
  route set now advertises a DNS name by design, Asterisk cannot resolve it, so
  Asterisk-originated in-dialog requests never leave the Pod.

**T3-S2A remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

* Reproof executed at `081267a` (`fix(t3): complete kamailio dialog routing`).
* Branch `main`, working tree clean at start, `UTCP_PHASE=T1`, nothing pushed.

Pre-apply static authorities all passed:

```text
make kamailio-signaling-config-check        exit 0  (kamailio_signaling_config_check=pass, live_kamailio_runtime=configured)
make kamailio-signaling-config-check-test   exit 0
make security-config-check                  exit 0  (K3 security config check passed)
make security-config-check-test             exit 0
```

Render assertions over the canonical local overlay:

```text
listen=tcp:0.0.0.0:8080 advertise sip.utcp.local.test:443                                        present
listen=udp:0.0.0.0:5060 advertise kamailio-sip-internal.utcp-platform.svc.cluster.local:5060     present
t_on_failure("ASTERISK_UNAVAILABLE")                                                            present
failure_route[ASTERISK_UNAVAILABLE]                                                             present
has_totag() -> route(WITHINDLG) at line 82, initial-domain guard at line 87  -> ordering correct
t_on_failure at line 178 before t_relay at line 180                         -> ordering correct
rtpengine offer/answer/delete/manage/rtpproxy                                                   0
sha256(rendered kamailio.cfg)                58e5c733e4df9b223a7f206760494d0784523cbd28d8fbed25a0629549174386
Deployment utcp.io/kamailio-config-sha256    58e5c733e4df9b223a7f206760494d0784523cbd28d8fbed25a0629549174386
pod-template annotations                     exactly one (the checksum); no rollout timestamp
image / securityContext / hostNetwork        unchanged / unchanged / absent
```

## Runtime Baseline

```text
kamailio Pod            kamailio-ddc74bb7b-wrj7w  uid 7b9f5f4a-…  ready=true  restarts=1  ip 10.42.2.136
kamailio checksum       749fc1ca…   (the PRODUCT_DEFECT-6 correction)
kamailio Deployment     generation 13, rv 438556
live ConfigMap          rv 438503, sha256 749fc1ca…
kamailio Services       utcp-platform/kamailio ClusterIP 8080/TCP only
                        kamailio-sip-internal            NOT FOUND
NP allow-kamailio-signaling-required-traffic  rv 431718, ingress 8080/TCP only (no UDP 5060)
NP allow-asterisk-sip-from-kamailio           rv 431717, policyTypes [Ingress] only,
                                              selector lacks utcp.dev/runtime-node
asterisk Pod            asterisk-ari-74d8c4b5f8-mjkj2  uid 5497e140-…  ready=true  restarts=0  ip 10.42.1.223
asterisk-ari            replicas 1, ready 1
asterisk-sip endpoints  10.42.1.223 ready=true  (exactly one)
asterisk-ari-b          uid 8a904cdd-…  restarts 13  (secondary node, untouched)
rtpengine Pod           uid 245b78c5-…  ready=true  restarts=0
rtpengine counters      sessions{own}=0 {foreign}=0  ports_used{internal}=0 {default}=0
database                tables 41, dialog/rtp/media tables (none), tenants 27, RuntimeNodes 110,
                        families asterisk/asterisk-ari + simulator/simulator-deterministic, pending outbox 0
redis                   dbsize 2, keys sip/dialog/rtp/media = 0/0/0/0
```

Confirmed the live resources contained **none** of the `081267a` changes before
this proof.

Pre-apply bounded dialog reproducing all three defects:

```text
invite_final_status           200 OK
invite_record_route           <sip:0.0.0.0;lr;r2=on;ftag=…>, <sip:0.0.0.0:8080;transport=ws;lr;r2=on;ftag=…>
post_ack_200_retransmissions  3        <- ACK never landed (PRODUCT_DEFECT-7)
bye_final_status              403 Forbidden
dialog_terminated             false
```

## Resources Applied

Five, in dependency order. `kubectl diff` restricted to them showed only the
correction: no Asterisk Deployment change, no rtpengine change, no Prometheus
change, no public-edge change, no unrelated application ConfigMap change, no
image change, no security-context change, and no rollout timestamp.

| Resource | Before | After | Material change |
|---|---|---|---|
| `ConfigMap/utcp-platform/kamailio-config` | rv `438503`, sha256 `749fc1ca…` | rv `445431`, sha256 **`58e5c733…`** | both `advertise` clauses; `OPTIONS`/`CANCEL`/`has_totag()` moved ahead of the initial-domain guard; guard log relabelled `result=initial_foreign_domain`; `t_on_failure` + `failure_route[ASTERISK_UNAVAILABLE]`; `sl_send_reply` → `t_reply` on the relay failure |
| `Service/utcp-platform/kamailio-sip-internal` | absent | rv `445433`, clusterIP `10.43.3.212` | **created** — ClusterIP, UDP `5060`, `targetPort: sip-udp` |
| `NetworkPolicy/utcp-platform/allow-kamailio-signaling-required-traffic` | generation `4`, rv `431718` | generation **`5`**, rv `445436` | added ingress UDP `5060` from the canonical Asterisk Pod identity; tightened the existing Asterisk egress podSelector with `utcp.io/network-role` + `utcp.dev/runtime-node` |
| `NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio` | generation `1`, rv `431717`, `[Ingress]` | generation **`2`**, rv `445437`, **`[Ingress, Egress]`** | added egress UDP `5060` to the canonical Kamailio signaling Pod; tightened podSelector with `utcp.dev/runtime-node` |
| `Deployment/utcp-platform/kamailio` | generation `13`, rv `438556` | generation **`14`**, rv `445494` | checksum annotation only (`749fc1ca…` → `58e5c733…`) |

Before the Deployment apply, the Deployment remained at generation `13` with the
old checksum and the Pod was untouched (`uid 7b9f5f4a-…`, `restarts=1`),
confirming ConfigMap, Service, and policy applies do not by themselves roll the
workload.

## Kamailio Rollout Result

```text
deployment applied   : 2026-08-01T00:01:16Z
rollout complete     : 2026-08-01T00:01:20Z  (~4 seconds)
new ReplicaSet       : kamailio-6b85f9db8c  revision 14  desired 1  ready 1
new Pod              : kamailio-6b85f9db8c-c9cvc  uid a5a29649-91a7-4dea-8aec-c8c8e8d9e80c  ip 10.42.2.137
new Pod checksum     : 58e5c733…
old Pod retirement   : SuccessfulCreate + Started for the new Pod, then SuccessfulDelete /
                       Killing kamailio-ddc74bb7b-wrj7w  (old Pod available until replacement readiness)
old ReplicaSet       : revision 13 scaled to 0
conditions           : Available=True (MinimumReplicasAvailable), Progressing=True (NewReplicaSetAvailable)
restart count        : 1
manual restart       : none  (no rollout restart, no Pod deletion, no timestamp annotation, no reload RPC)
unrelated workloads rolled : none
```

**Divergence — one transient restart.** The corrected Pod shows `restarts=1` from
the known transient `postgres … Connection refused` during `auth_db` fixup — the
new-Pod-IP versus NetworkPolicy datapath programming race. It self-recovered, and
the running container has **zero** ERROR lines and no configuration or parser
error. Classified `EXPECTED_BEHAVIOR`.

## Running Configuration Identity

**PASS.** Byte-identical across all four authorities:

```text
1 repository render        58e5c733e4df9b223a7f206760494d0784523cbd28d8fbed25a0629549174386
2 live ConfigMap           58e5c733e4df9b223a7f206760494d0784523cbd28d8fbed25a0629549174386
3 mounted in the Pod       58e5c733e4df9b223a7f206760494d0784523cbd28d8fbed25a0629549174386
4 Pod checksum annotation  58e5c733e4df9b223a7f206760494d0784523cbd28d8fbed25a0629549174386
```

Assertions against the materialised running configuration:

```text
advertise client-facing    1
advertise asterisk-facing  1
t_on_failure               1
failure_route              1
has_totag() at line        83
initial-domain guard line  88      <- established-dialog routing precedes validation
result=initial_foreign_domain      1
rtpengine operations       0
```

Kamailio's own startup banner confirms both advertised identities:

```text
Listening on
             udp: 0.0.0.0 [0.0.0.0]:5060 advertise udp:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
             tcp: 0.0.0.0 [0.0.0.0]:8080 advertise tcp:sip.utcp.local.test:443
Aliases:
             *: sip.utcp.local.test:*
```

## Internal Kamailio SIP Service

**PASS.**

```text
type          ClusterIP
clusterIP     10.43.3.212
ports         [{name: sip-udp, port: 5060, protocol: UDP, targetPort: sip-udp}]
selector      app.kubernetes.io/component=kamailio, app.kubernetes.io/part-of=utcp,
              utcp.io/network-role=kamailio-signaling
EndpointSlice kamailio-sip-internal-v6pbb, ports 5060/UDP
Ready endpoints  exactly one — 10.42.2.137 ready=true target=kamailio-6b85f9db8c-c9cvc
current Pod IP   10.42.2.137   (matches)
```

The endpoint continued to track the Kamailio Pod through the whole proof,
including after the Asterisk availability test.

**Cluster-DNS resolution from the Asterisk Pod FAILS** — see
`PRODUCT_DEFECT-10`. No hard-coded ClusterIP or Pod IP is used as routing
authority anywhere: the running configuration contains zero `10.42.x.y` literals.

## Reverse NetworkPolicy Result

**PASS for the SIP corridor; incomplete for name resolution.**

Effective live policies:

```text
Asterisk source egress (utcp-runtime/allow-asterisk-sip-from-kamailio)
  podSelector  component=asterisk-ari, network-role=asterisk-ari, runtime-node=local-asterisk-ari
  egress       UDP 5060 -> ns utcp-platform, podSelector component=kamailio + network-role=kamailio-signaling

Kamailio destination ingress (utcp-platform/allow-kamailio-signaling-required-traffic)
  ingress[0]   TCP 8080 <- traefik-system / app.kubernetes.io/name=traefik   (unchanged)
  ingress[1]   UDP 5060 <- ns utcp-runtime, podSelector component=asterisk-ari
                            + network-role=asterisk-ari + runtime-node=local-asterisk-ari
  egress       UDP 5060 -> the same canonical Asterisk identity   (existing corridor intact)
```

| Requirement | Result |
|---|---|
| destination namespace / identity / UDP 5060 only, both directions | **PASS** |
| default-deny still active | **PASS** — `utcp-platform/default-deny` and `utcp-runtime/default-deny`, both `[Ingress, Egress]` with empty podSelector |
| secondary Asterisk workload not admitted | **PASS** — `asterisk-ari-b` carries `utcp.dev/runtime-node: local-asterisk-ari-b`; the only Pod matching the admitted identity is `asterisk-ari-74d8c4b5f8-…` |
| no namespace-wide UDP rule | **PASS** for the SIP corridor — the only namespace-wide UDP egress is Kamailio's pre-existing DNS rule (UDP/TCP `53` to `kube-system`) |
| no `ipBlock` substituting for Pod identity | **PASS** — neither policy contains `ipBlock` |
| no media ports added | **PASS** — no `40000-40099` port in either policy |
| existing Kamailio-to-Asterisk signaling intact | **PASS** — every dialog in this proof relayed successfully |

Bounded in-namespace probe, using the real Asterisk Pod network namespace and
policy identity (no proof-only policy was added):

```text
SIP OPTIONS -> 10.43.3.212:5060 (kamailio-sip-internal ClusterIP)
  probe_result = REPLY from ('10.43.3.212', 5060)
  first_line   = SIP/2.0 200 Keepalive

control: same source -> 10.43.50.16:2223 (rtpengine ng, not admitted)
  control_result = TIMEOUT (correctly not permitted)
```

This proves the reverse corridor end to end at the transport layer — Asterisk
egress, kube-proxy DNAT, Kamailio ingress, Kamailio request processing, and the
reply path — while the control probe confirms the policy is genuinely selective
rather than open.

## Record-Route Result

**PASS.** Actual headers from the successful `200 OK`:

```text
Record-Route: <sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060;lr;r2=on;ftag=…>,
              <sip:sip.utcp.local.test:443;transport=ws;lr;r2=on;ftag=…>
```

UAC route set after reversal per RFC 3261 §12.1.2:

```text
route_set_1  sip:sip.utcp.local.test:443;transport=ws;lr;r2=on;ftag=…            <- client-facing
route_set_2  sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060;lr;r2=on;ftag=…  <- Asterisk-facing
remote_target sip:10.42.1.223:5060                                               <- Asterisk Contact (not a Kamailio identity)
```

| Rejected pattern | Present? |
|---|---|
| `0.0.0.0` | **no** |
| Kamailio Pod IP | **no** |
| Asterisk Pod IP as a Kamailio route identity | **no** — `10.42.1.223` appears only as the Asterisk `Contact`/remote target, which is correct |
| node IP / developer-host IP | **no** |
| unrelated Service | **no** |

The route set is not merely syntactically present: the subsequent ACK and BYE
were both routed along it and both reached the same Asterisk dialog, and the
Asterisk-facing identity was independently proven reachable by the bounded
`200 Keepalive` probe above.

## ACK Continuity

**PASS.**

```text
post_ack_200_retransmissions  0      (pre-apply baseline: 3)
kamailio log                  no result=foreign_domain, no result=initial_foreign_domain,
                              no kamailio_application_dialog_relay_failed
ack_reused_authorization      false
call_id / from_tag / to_tag   consistent with the INVITE throughout
```

The retransmission counter is the objective signal: Asterisk retransmits an
unacknowledged `200 OK`, and it stopped entirely once the ACK was loose-routed to
the dialog. Initial subscriber authentication was not repeated, and the ACK was
not rejected by initial-domain validation.

## Client-Originated BYE

**PASS.**

```text
bye_final_status    200 OK
bye_call_id         059356fa6edcdcd3@utcp-s2a-dialog   (matches the INVITE)
bye_from_tag        a4e2c2c25f78                        (matches)
bye_to_tag          d48c2198-a13e-43c3-ba71-2e6e6e6f4580 (matches)
bye_cseq            3 BYE
dialog_terminated   true
asterisk channels   0 active channels, 0 active calls   (channel terminated normally)
manual cleanup      none required
kamailio log        no rejection of any kind
```

Reproduced on every dialog run, including after Asterisk restoration.

## Asterisk-Originated BYE

**FAIL — `PRODUCT_DEFECT-10`.**

A second authenticated dialog was established (`post_ack_200_retransmissions=0`,
so the dialog was confirmed on both sides), then the existing Asterisk CLI was
used as a bounded proof action to request hangup of that exact proof channel. No
dialplan or production configuration was modified.

```text
proof channel                     PJSIP/anonymous-00000003
bounded action                    Requested Hangup on channel 'PJSIP/anonymous-00000003'
dialog Call-ID                    02004f27ba66e6bb@utcp-s2a-dialog
route set advertised to Asterisk  sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
client observation window         40 s
asterisk_originated_bye_received  false
kamailio log during the window    zero lines — no BYE reached Kamailio at all
asterisk channel afterwards       0 active channels (terminated locally only)
```

Causal chain, each link proven independently:

```text
1. dialog confirmed                 post_ack_200_retransmissions = 0
2. Kamailio route identity correct  sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
3. UDP 5060 corridor works          SIP OPTIONS from the Asterisk identity -> 200 Keepalive
4. name resolution fails            gethostbyname("kamailio-sip-internal.utcp-platform.svc.cluster.local") -> gaierror
                                    gethostbyname("sip.utcp.local.test")                                  -> gaierror
                                    getent hosts asterisk-sip.utcp-runtime.svc.cluster.local              -> rc=2
5. DNS transport is blocked         raw DNS query to kube-dns 10.43.0.10:53 -> TIMEOUT
                                    (a reachable resolver would answer with an rcode, not time out)
6. no BYE left the Pod              Kamailio logged nothing; the client received nothing
```

## PRODUCT_DEFECT-10 — the canonical Asterisk workload has no DNS egress, so it cannot resolve the advertised Kamailio identity

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml`](../../../infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml) — the new `egress` block grants only UDP `5060` to the Kamailio signaling Pod. `utcp-runtime/default-deny` denies all other egress, and no other policy in `utcp-runtime` grants the canonical Asterisk Pod any egress |
| Expected | Asterisk resolves the advertised route identity `kamailio-sip-internal.utcp-platform.svc.cluster.local` through cluster DNS and sends the in-dialog BYE to Kamailio, which loose-routes it to the client |
| Actual | Every name lookup from the Asterisk Pod fails (`gaierror` / `getent` rc=2), and a raw UDP query to `kube-dns` `10.43.0.10:53` times out, so no in-dialog request is ever transmitted. The channel terminates locally and the client is never notified |
| Relevant SIP messages / logs | No BYE on the wire at all: zero Kamailio log lines during the 40-second window and `asterisk_originated_bye_received=false`. Asterisk logs no resolver diagnostic, because PJSIP name-resolution failure is not surfaced at WARNING level in this build |
| Root asymmetry | `allow-kamailio-signaling-required-traffic` already grants **Kamailio** DNS egress (`UDP 53` + `TCP 53` to the `kube-system` namespace), which is why Kamailio resolves `asterisk-sip…` successfully. The Asterisk policy has no mirror of that rule. Before `081267a` the omission was harmless because Asterisk never originated outbound traffic — ARI is ingress and SIP replies ride the established conntrack flow. Closing `PRODUCT_DEFECT-8` by advertising a **DNS name** introduced the outbound resolution requirement without granting the resolution path |
| Static checks | **All passed** — `make security-config-check`, `security-config-check-test`, `kamailio-signaling-config-check`, and `…-config-check-test` are green. Nothing asserts that a workload required to reach an advertised in-cluster identity also has DNS egress |
| Severity | **Blocking.** Completion criterion 9 cannot be met. Asterisk-initiated in-dialog signaling — BYE on hangup, session-timer re-INVITE — cannot leave the Pod, so an Asterisk-side hangup never reaches the client |

### Smallest bounded correction

1. Add a DNS egress rule to
   `infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml`
   mirroring the existing Kamailio rule exactly — `to` the `kube-system`
   namespaceSelector with `UDP 53` and `TCP 53`. Do not widen the destination
   beyond DNS and do not use an `ipBlock`.
2. Extend `scripts/security/config-check` to assert that every workload selected
   by an egress policy whose destination is expressed as an in-cluster DNS
   identity also has DNS egress, with a `config-check-test` mutation removing the
   Asterisk DNS rule.
3. Optionally reduce blast radius by adding a `podSelector` for the CoreDNS Pods
   rather than the whole `kube-system` namespace — but only if the existing
   Kamailio rule is changed in the same way, so the two stay symmetric.

## Initial Foreign-Domain Preservation

**PASS.** Proven with a fresh out-of-dialog request, not an established-dialog
request:

```text
request-URI              sip:9900@not-utcp.invalid
final status             403 Forbidden
kamailio log             kamailio_registration_rejected result=initial_foreign_domain
asterisk calls processed 4 -> 4        (not forwarded)
rtpengine operations     0             (not forwarded)
```

## Healthy-Asterisk Baseline

**PASS.**

```text
asterisk-sip Ready endpoints  10.42.1.223=true  (exactly one)
authenticated INVITE          200 OK
extension 9900                executed — calls processed 4 -> 5
SDP response                  application/sdp, c=IN IP4 10.42.1.223
post_ack retransmissions      0
BYE                           200 OK, dialog_terminated=true
```

## Asterisk-Unavailable Result

**PASS — `PRODUCT_DEFECT-9` is closed.**

Condition classified `INTENTIONALLY_INDUCED_CONDITION`: only the canonical
`Deployment/utcp-runtime/asterisk-ari` was scaled `1 → 0`. `asterisk-ari-b` was
not touched.

```text
scale 1 -> 0                    deployment.apps/asterisk-ari scaled
asterisk-sip Ready endpoints    0, pods 0   after 2s

00:06:29Z  INVITE sip:9900@sip.utcp.local.test  ->  401 Unauthorized
           authenticated INVITE                 ->  100 trying -- your call is important to us
00:06:59Z                                       ->  503 Application Runtime Unavailable
kamailio log: kamailio_application_dialog_rejected result=asterisk_unavailable
              method=INVITE call_id=2cdc66b75340b84b@utcp-s2a-dialog
```

| Requirement | Result |
|---|---|
| final response `503 Application Runtime Unavailable` | **PASS** — delivered through `failure_route[ASTERISK_UNAVAILABLE]` |
| non-sensitive `result=asterisk_unavailable` log with Call-ID correlation | **PASS** |
| client does **not** receive the previous final `408` | **PASS** — the `408` is now mapped internally and never reaches the client |
| no alternate Asterisk workload receives a call | **PASS** — `asterisk-ari-b` reports `0 calls processed`, uid and restarts unchanged |
| no Pod-IP fallback | **PASS** — zero `10.42.x.y` literals in the running configuration |
| no ARI route, no rtpengine route, no direct-media bypass | **PASS** — rtpengine sessions `0`, no operations |
| no new branch or second destination | **PASS** — exactly one relay destination in the running configuration |
| no database or RuntimeNode mutation | **PASS** — RuntimeNodes `110`, tenants `27`, unchanged |

The rejection still takes ~30 s because the failure route is driven by the TM
`fr_inv_timer`. That is the committed design of this correction — the contract
and its observability signal now both fire, which is what `PRODUCT_DEFECT-9`
required. Bounding the latency remains available as optional future tuning and is
not a defect.

## Restoration Result

**PASS.** Restored to the committed replica count read from the canonical render
(`replicas: 1`).

```text
scale 0 -> 1                   deployment.apps/asterisk-ari scaled
rollout                        successfully rolled out (automatic)
new Asterisk Pod               asterisk-ari-74d8c4b5f8-k24bc  uid 64078883-…  ready=true  restarts=0  ip 10.42.1.224
PJSIP transport                transport-udp-internal  udp  0.0.0.0:5060      (active)
asterisk-sip Ready endpoints   10.42.1.224 ready=true  (exactly one)
authenticated INVITE           200 OK
record_route                   both stable identities, unchanged
SDP                            c=IN IP4 10.42.1.224      <- new Pod IP, no stale pinning
ACK continuity                 post_ack_200_retransmissions = 0
BYE continuity                 200 OK, dialog_terminated=true
kamailio-sip-internal endpoint 10.42.2.137 ready=true    (still tracking the Kamailio Pod)
manual application reconciliation  none
unrelated workload restarts    none
```

## REGISTER and Unsupported-Method Preservation

**PASS**, both against the corrected running configuration.

```text
REGISTER (canonical scripts/kamailio-signaling/sip-wss-client.php, API-issued subscriber)
  sip_status=200  sip_result=accepted   active_location_contacts=1
  kamailio: kamailio_registration_challenge result=challenge
            kamailio_registration_accepted result=ok      <- existing registrar path

MESSAGE sip:9900@sip.utcp.local.test -> 405 Method Not Allowed
  kamailio: kamailio_registration_rejected result=unsupported_method method=MESSAGE
```

## rtpengine Boundary Preservation

**PASS.** Unchanged throughout this signaling-only slice.

```text
rtpengine_sessions{own}/{foreign}          0 / 0   (baseline 0 / 0)
rtpengine_ports_used{internal}/{default}   0 / 0   (baseline 0 / 0)
offer / answer / delete log lines          0 / 0 / 0
Pod uid 245b78c5-…  restarts 0             unchanged
running Kamailio config rtpengine ops      0
```

## Public-Surface Preservation

**PASS.**

```text
utcp-platform/kamailio                ClusterIP  8080/TCP   nodePort=none  externalIPs=none
utcp-platform/kamailio-sip-internal   ClusterIP  5060/UDP   nodePort=none  externalIPs=none
NodePort / LoadBalancer / ExternalIP  absent for every Kamailio Service
Gateway / Ingress / UDPRoute          none referencing Kamailio
HostPort / HostNetwork                absent
node UDP 5060                         none on any of the three k3d nodes
developer-host UDP 5060               none
k3d UDP publication                   none
```

The new internal Service is ClusterIP-only and therefore adds no public surface.

## State-Authority Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes | 110 | **110** |
| RuntimeNode families | `asterisk/asterisk-ari`, `simulator/simulator-deterministic` | **unchanged** |
| pending outbox | 0 | **0** |
| Redis keys `sip`/`dialog`/`rtp`/`media` | 0/0/0/0 | **0/0/0/0** |
| rtpengine sessions / ports used | 0 / 0 | **0 / 0** |

Redis `db0` moved `2 → 3`: ordinary session and cache entries from the authorized
API calls that issued the proof credentials. No durable SIP-dialog or media
authority appeared.

Full-cluster Pod snapshot diff contains exactly two changes:

```text
- utcp-platform kamailio-ddc74bb7b-wrj7w      7b9f5f4a-…  restarts 1
+ utcp-platform kamailio-6b85f9db8c-c9cvc     a5a29649-…  restarts 1   <- corrected rollout
- utcp-runtime  asterisk-ari-74d8c4b5f8-mjkj2 5497e140-…  restarts 0
+ utcp-runtime  asterisk-ari-74d8c4b5f8-k24bc 64078883-…  restarts 0   <- intentional 1->0->1 test
```

Every other Pod retained its UID **and** restart count. **No unrelated workload
restarted.**

## Findings

| Classification | Finding |
|---|---|
| PASS | **`PRODUCT_DEFECT-7` is closed** — established-dialog routing precedes initial-domain validation; in-dialog ACK and BYE are loose-routed, post-ACK `200 OK` retransmissions fell `3 → 0`, the client BYE returns `200 OK`, and no `foreign_domain` rejection occurs for established dialogs |
| PASS | **`PRODUCT_DEFECT-8` is closed at its seam** — both listeners advertise stable identities and the `Record-Route` set contains no `0.0.0.0`, Pod IP, node IP, developer-host IP, or unrelated Service; the Asterisk-facing identity is independently reachable at the transport layer |
| PASS | **`PRODUCT_DEFECT-9` is closed** — zero Ready Asterisk endpoints produce an explicit `503 Application Runtime Unavailable` through the TM failure route with a Call-ID-correlated `result=asterisk_unavailable` log, and the previous bare `408` no longer reaches the client |
| PASS | Only the five corrected resources were applied, in dependency order, with no Asterisk Deployment, rtpengine, Prometheus, public-edge, unrelated ConfigMap, image, security-context, or timestamp change |
| PASS | The checksum-coupled Deployment produced a fully automatic ~4-second rollout to ReplicaSet revision 14, retiring the old Pod only after the replacement started, with no manual restart and no unrelated workload rollout |
| PASS | Running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount, and Pod checksum annotation (`58e5c733…`), and Kamailio's own banner confirms both advertised identities |
| PASS | `Service/kamailio-sip-internal` is ClusterIP UDP `5060` on `targetPort: sip-udp` with exactly one Ready endpoint equal to the current Kamailio Pod IP, tracked correctly throughout |
| PASS | The reverse corridor admits only the canonical workloads on UDP `5060` in both directions, with default-deny intact, no `ipBlock`, no namespace-wide SIP rule, no media ports, and the secondary `asterisk-ari-b` workload correctly excluded; a bounded in-namespace probe returned `200 Keepalive` while a non-admitted destination timed out |
| PASS | Initial foreign-domain rejection remains active (`403`, `result=initial_foreign_domain`) and reaches neither Asterisk nor rtpengine |
| PASS | Restoration is automatic: PJSIP UDP `5060` returns, one Ready endpoint, and INVITE/ACK/BYE all succeed against the **new** Asterisk Pod IP with no stale pinning and no manual reconciliation |
| PASS | REGISTER preserved through the existing registrar path; unsupported `MESSAGE` still `405`; rtpengine entirely uninvolved; no public SIP exposure; no durable dialog authority; no canonical state mutation; no unrelated workload restart |
| PASS | All required repository checks pass before and after |
| **PRODUCT_DEFECT-10** | The canonical Asterisk workload has UDP `5060` egress to Kamailio but **no DNS egress**, so it cannot resolve the advertised route identity and Asterisk-originated in-dialog requests never leave the Pod. Kamailio already has the mirror DNS rule; the Asterisk policy does not. Blocking |
| INTENTIONALLY_INDUCED_CONDITION | `Deployment/utcp-runtime/asterisk-ari` scaled `1 → 0 → 1` to prove the failure-route contract; `asterisk-ari-b` untouched and unused; restored to the committed replica count and left Ready |
| EXPECTED_BEHAVIOR | The corrected Kamailio Pod restarted once on the known transient `postgres … Connection refused` new-Pod-IP versus NetworkPolicy programming race; it self-recovered with zero ERROR lines and no configuration or parser error |
| EXPECTED_BEHAVIOR | Signaling credentials carry a bounded ~5-minute TTL, so each corridor used a freshly re-issued credential from the same authorized member endpoint |
| EXPECTED_BEHAVIOR | Redis `db0` `2 → 3` from authorized API session and cache activity during credential issuance |
| EXPECTED_BEHAVIOR | The `503` arrives after ~30 s because the failure route is driven by the TM `fr_inv_timer`; the committed contract and its log both fire, so this is the designed behaviour, not a defect |
| PROOF_LIMITATION | CANCEL still has no deterministic pre-answer window — the `9900` fixture answers immediately and was not altered |
| PROOF_LIMITATION | `res_pjsip_logger` is not in the committed `autoload=no` module set, so no Asterisk-side SIP wire trace was captured. Asterisk-side evidence is the live channel table, endpoint state, and `calls processed` counters; no module was added to production configuration |
| PROOF_LIMITATION | The Asterisk-originated BYE could not be observed traversing Kamailio because `PRODUCT_DEFECT-10` blocks it upstream at name resolution. Proving that corridor end to end requires the DNS egress correction; no proof-only NetworkPolicy was added to work around it |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
images built or pushed:         none
resources applied:              5 (kamailio ConfigMap, kamailio-sip-internal Service,
                                   2 NetworkPolicies, kamailio Deployment)
manual rollout restart:         none
workloads rolled:               1 (kamailio, automatically via checksum coupling)
availability tests induced:     1 (asterisk-ari 1 -> 0 -> 1, restored)
unrelated workloads restarted:  none
canonical records mutated:      none beyond authorized API proof data
```

## Cleanup

- Corrected Kamailio left Ready (`kamailio-6b85f9db8c-c9cvc`); all five corrected resources left applied.
- Asterisk restored to its committed replica count and left Ready; rtpengine left Ready with counters at zero.
- No synthetic SIP proof Pod was created — the disposable clients ran from the scratch directory through the canonical Traefik/WSS edge; the bounded policy probe ran inside the existing Asterisk container with no added tooling and no proof-only policy.
- The proof contact was deregistered through the canonical client (`sip_status=200`, `active_location_contacts=0`) and the telephony session was ended through the authorized API (`HTTP 200`), leaving `kamailio_signaling_auth_view` rows for the proof user at `0`.
- Disposable dialog client, method probe, credential helpers, cookie jar, secret file, traces, and rendered scratch manifests removed; none was added to the repository or the cluster.
- No packet capture and no port-forward were used. `.playwright-mcp/` is absent.
- No credential, digest response, or Authorization header content was printed or recorded; traces redact `Authorization`, `Proxy-Authorization`, `WWW-Authenticate`, and `Proxy-Authenticate`.

## T3-S2A Final Status After the Final In-Dialog Reproof

```text
PRODUCT_DEFECT-5  = closed
PRODUCT_DEFECT-6  = closed
PRODUCT_DEFECT-7  = closed
PRODUCT_DEFECT-8  = closed
PRODUCT_DEFECT-9  = closed
PRODUCT_DEFECT-10 = open (Asterisk workload has no DNS egress)
T3-S2A repository implementation = Complete
T3-S2A live signaling proof      = INCOMPLETE
T3-S2A                           = In Progress
T3-S2 media mediation            = Not Started
T3                               = In Progress
UTCP_PHASE                       = T1 (unchanged)
```

## Recommended Next Step

Bounded Codex correction of `PRODUCT_DEFECT-10`: add the mirrored DNS egress rule
to `infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml`
with matching `scripts/security/config-check` and `config-check-test` coverage,
then a focused reproof of the **Asterisk-originated BYE only**. Everything else in
this section is proven and must not be repeated.

Do not add rtpengine mediation, fallback destinations, public SIP exposure,
feature gates, manual activation, browser media, conference admission, V0, T4,
external trunks, or PSTN.

---

# Asterisk-Originated BYE Closure Proof (`741efbb`)

Verdict: `T3_S2A_ASTERISK_ORIGINATED_BYE_PROOF_INCOMPLETE`

Focused evidence-only proof of the single remaining T3-S2A outcome. No production
file was modified, no proof-only NetworkPolicy was added, and no completed
corridor was broadly repeated.

**`PRODUCT_DEFECT-10` is closed.** The canonical Asterisk workload now resolves
cluster DNS over both UDP and TCP, and the Asterisk-originated in-dialog BYE
**leaves the Asterisk Pod, reaches Kamailio through the internal Service
identity, and is accepted into `route[WITHINDLG]` where `loose_route()` processes
it successfully.**

**One new defect blocks the final hop: `PRODUCT_DEFECT-11.`** Kamailio cannot
deliver an in-dialog request *to* a SIP-over-WSS client, because the committed
configuration never establishes the WebSocket alias binding. It attempts DNS
resolution of the client's `Contact` host — which RFC 7118 §5.2.1 requires to be
an unresolvable `.invalid` domain — and fails.

**T3-S2A remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

* Proof executed at `741efbb` (`fix(t3): allow asterisk cluster dns`).
* Branch `main`, working tree clean at start, `UTCP_PHASE=T1`, nothing pushed.

Focused static authorities all passed:

```text
make security-config-check                  exit 0  (K3 security config check passed)
make security-config-check-test             exit 0
make kamailio-signaling-config-check        exit 0  (live_kamailio_runtime=configured)
make kamailio-signaling-config-check-test   exit 0
```

## Runtime Baseline

```text
canonical Asterisk   asterisk-ari-74d8c4b5f8-k24bc  uid 64078883-…  ready=true  restarts=0  ip 10.42.1.224
  labels             component=asterisk-ari, part-of=utcp, runtime-node=local-asterisk-ari, network-role=asterisk-ari
secondary Asterisk   asterisk-ari-b-8557bd4d76-rcjfn  uid 8a904cdd-…  restarts=13
  labels             …, runtime-node=local-asterisk-ari-b   <- distinct runtime-node
kamailio             kamailio-6b85f9db8c-c9cvc  uid a5a29649-…  ready=true  restarts=1  ip 10.42.2.137
kamailio-sip-internal  ClusterIP 10.43.3.212, UDP 5060 targetPort sip-udp, endpoint 10.42.2.137 ready=true
allow-asterisk-sip-from-kamailio  generation 2, rv 445437, [Ingress, Egress]
  egress[0]          UDP 5060 -> utcp-platform / kamailio-signaling      (only rule; NO DNS)
Asterisk resolver    nameserver 10.43.0.10, search utcp-runtime.svc.cluster.local …, ndots:5
  hostAliases        (none)
  /etc/hosts         Kubernetes-managed; only the Pod's own name — no kamailio entry
pre-apply resolution kamailio-sip-internal.utcp-platform.svc.cluster.local -> FAILED (gaierror)
database             tables 41, dialog/rtp/media tables (none), tenants 27, RuntimeNodes 110, pending outbox 0
redis                dbsize 3, keys sip/dialog/rtp/media = 0/0/0/0
rtpengine            sessions{own}=0 {foreign}=0, ports_used{internal}=0 {default}=0
```

Cluster DNS identity confirmed to match the committed selectors exactly:

```text
kube-system namespace labels   kubernetes.io/metadata.name: kube-system
coredns-c4dbffb5f-q9qss        labels {k8s-app: kube-dns, pod-template-hash: c4dbffb5f}   ready=true  ip 10.42.0.152
kube-dns Service               clusterIP 10.43.0.10, selector {k8s-app: kube-dns}
```

## Policy Applied

Exactly one resource. `kubectl diff` limited to it showed only the added DNS
egress rule.

| Resource | Before | After |
|---|---|---|
| `NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio` | generation `2`, rv `445437` | generation **`3`**, rv **`448277`** |

```text
selected Pod labels
  app.kubernetes.io/component: asterisk-ari
  utcp.dev/runtime-node:       local-asterisk-ari
  utcp.io/network-role:        asterisk-ari

egress[0]  UDP 5060  -> ns utcp-platform, podSelector {component: kamailio, network-role: kamailio-signaling}
egress[1]  UDP 53 + TCP 53 -> ns kube-system, podSelector {k8s-app: kube-dns}     <- added
ingress[0] UDP 5060  <- ns utcp-platform, podSelector {network-role: kamailio-signaling}
```

The repository also narrows the **Kamailio** DNS rule with the same
`k8s-app: kube-dns` podSelector. `kubectl diff` showed that change to be a pure
tightening of an already-working rule (Kamailio resolves
`asterisk-sip.utcp-runtime.svc.cluster.local` → `10.43.209.141` under the live
broader rule), so it is **not materially required** for this closure proof and
was deliberately **not applied**, per the proof contract. This leaves one known,
intentional live-versus-repository drift on
`allow-kamailio-signaling-required-traffic` (live generation `5`, repository
would be `6`); it is a hardening delta only and does not affect any claim here.

No workload was restarted by the apply:

```text
asterisk-ari-74d8c4b5f8-k24bc     uid 64078883-…  restarts 0   unchanged
asterisk-ari-b-8557bd4d76-rcjfn   uid 8a904cdd-…  restarts 13  unchanged
kamailio-6b85f9db8c-c9cvc         uid a5a29649-…  restarts 1   unchanged
```

## Effective Policy Selection

**PASS.** Evaluated by matching each policy's `podSelector` against the real Pod
labels:

```text
policy                                 policyTypes       canonical   secondary-b
allow-asterisk-ari-from-utcp-workers   Ingress           True        True
allow-asterisk-sip-from-kamailio       Ingress,Egress    True        False
default-deny                           Ingress,Egress    True        True
```

The canonical Asterisk Pod's **only** egress grants come from
`allow-asterisk-sip-from-kamailio`: UDP `5060` to Kamailio and UDP/TCP `53` to
kube-dns. The secondary workload is selected only by `default-deny`, so it
retains no egress at all. No proof-only DNS policy was added.

## Cluster-DNS Resolution

**PASS.** Queried from inside the real Asterisk container (no ephemeral container
was needed — the image already ships `python3`).

```text
resolver_address   10.43.0.10:53        <- kube-dns ClusterIP, not a public resolver
query_name         kamailio-sip-internal.utcp-platform.svc.cluster.local   type=A
```

### UDP DNS Result

```text
udp_result=SUCCESS  from 10.43.0.10  rcode=0 (NOERROR)  address=10.43.3.212  duration_ms=0.5
```

### TCP DNS Result

```text
tcp_result=SUCCESS  rcode=0 (NOERROR)  address=10.43.3.212  duration_ms=0.3
```

Also via the standard resolver path used by Asterisk itself:

```text
stdlib_resolve=SUCCESS  address=10.43.3.212  duration_ms=8.8
```

### Internal Kamailio Service Resolution

```text
resolved address                       10.43.3.212
live kamailio-sip-internal clusterIP   10.43.3.212      <- exact match
```

No `/etc/hosts` entry and no `hostAliases` supplied the answer (both recorded
empty of any kamailio entry in the baseline), and every query went to the
in-cluster resolver.

## SIP Reachability

**PASS**, and still exact. Probe issued from the real Asterisk identity, using the
DNS name rather than an IP literal:

```text
resolved kamailio-sip-internal.utcp-platform.svc.cluster.local -> 10.43.3.212
udp_5060_result = REPLY from 10.43.3.212: SIP/2.0 200 Keepalive

control probes from the same identity
  rtpengine ng 10.43.50.16:2223  -> TIMEOUT (dropped, correctly denied)
  postgres 10.43.8.153:5432      -> ConnectionRefusedError (correctly denied)
```

## Established Dialog

**PASS.** One bounded authenticated call to `9900` through the normal digest path,
using a credential issued only through the authorized application API.

```text
call_id        1281477efeaeaf32@utcp-s2a-closure
from_tag       66d11fc07a8f
to_tag         70eaa4cd-9566-40e4-8c95-31c089d3dc75
invite_final   200 OK, Content-Type: application/sdp
invite_contact <sip:10.42.1.224:5060>
record_route   <sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060;lr;r2=on;ftag=66d11fc07a8f>,
               <sip:sip.utcp.local.test:443;transport=ws;lr;r2=on;ftag=66d11fc07a8f>
route_set_1    sip:sip.utcp.local.test:443;transport=ws;lr;r2=on;ftag=66d11fc07a8f
route_set_2    sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060;lr;r2=on;ftag=66d11fc07a8f
ack_sent       true,  post_ack_200_retransmissions=0     <- ACK confirmed the dialog
asterisk channel  PJSIP/anonymous-00000001  from-kamailio  9900  Prio 3  Up  Echo
client Contact <sip:ts-…@utcp-s2a-proof.invalid;transport=ws>
```

The Asterisk-facing `Record-Route` carries the internal Kamailio Service identity
exactly as required.

## Asterisk-Originated BYE

**Generated and delivered to Kamailio.** The existing Asterisk CLI was used once
as a bounded proof stimulus against that exact channel; no dialplan, module, or
production configuration was changed.

```text
01:33:31Z  asterisk -rx 'channel request hangup PJSIP/anonymous-00000001'
           -> Requested Hangup on channel 'PJSIP/anonymous-00000001'
```

Kamailio's own log proves receipt and established-dialog handling of that exact
dialog:

```text
WARNING: <script>: kamailio_application_dialog_relay_failed route=within_dialog method=BYE
         call_id=1281477efeaeaf32@utcp-s2a-closure
ERROR: <core> [core/resolve.c:1771]: sip_hostport2su(): could not resolve hostname: "utcp-s2a-proof.invalid"
ERROR: tm [ut.h:300]: uri2dst2(): failed to resolve "utcp-s2a-proof.invalid"
ERROR: tm [t_fwd.c:1764]: t_forward_nonack(): failure to add branches
INFO:  sl [sl_funcs.c:420]: sl_reply_error(): message marked with delayed-reply flag
```

Recorded from the transaction:

```text
method                BYE
call_id               1281477efeaeaf32@utcp-s2a-closure   <- identical to the INVITE dialog
request_uri host      utcp-s2a-proof.invalid              <- the client Contact, i.e. the correct remote target
next_hop              kamailio-sip-internal.utcp-platform.svc.cluster.local:5060 (UDP)
route handling        route=within_dialog  (the xlog sits inside the loose_route() branch)
transaction result    t_forward_nonack failed -> sl_reply_error() -> error response to Asterisk
asterisk outcome      channel terminated locally, 0 active channels, 2 calls processed
```

## Kamailio Receipt and Loose Routing

**PASS.** Requirements 1–9 of the BYE path are proven:

| # | Requirement | Result |
|---|---|---|
| 1 | Asterisk resolves the internal Service FQDN | **PASS** — UDP and TCP, `rcode=0`, `10.43.3.212` |
| 2 | the BYE leaves the Asterisk Pod | **PASS** — Kamailio received and logged it |
| 3 | it targets UDP `5060` on the internal Kamailio Service | **PASS** — the only permitted SIP egress; the Service is the sole UDP `5060` destination allowed |
| 4 | Asterisk egress NetworkPolicy permits it | **PASS** |
| 5 | Kamailio ingress NetworkPolicy permits it | **PASS** — the request was processed by the Kamailio script |
| 6 | Kamailio receives the BYE | **PASS** — Call-ID correlation is exact |
| 7 | the request enters established-dialog handling | **PASS** — `route=within_dialog`, reached via `has_totag()` |
| 8 | `loose_route()` processes the route set | **PASS** — the failure xlog is inside the `if (loose_route())` block, so it returned true |
| 9 | initial-domain rejection does not run | **PASS** — no `result=initial_foreign_domain` for this Call-ID |
| 10 | subscriber authentication is not repeated | **PASS** — no challenge for the BYE; the only challenge line is the initial INVITE |

## Client Receipt and Response

**FAIL — `PRODUCT_DEFECT-11`.**

```text
asterisk_originated_bye_received   false
bye_retransmissions_after_200      0   (no BYE ever arrived, so nothing to answer)
client observation window          45 s
```

Requirements 11–16 are therefore not met: Kamailio did not forward the BYE to the
client, the client returned no response, no response traversed back to Asterisk,
and the Asterisk channel terminated only by its own local hangup rather than by a
completed BYE transaction.

Note the asymmetry that isolates the seam precisely: *responses* toward the client
work — the client received the `200 OK` for its INVITE over the same WebSocket —
because `tm` routes responses by `Via` on the transaction-bound connection. Only a
**new request** toward the client fails, because that requires resolving or
re-binding the client's transport.

## PRODUCT_DEFECT-11 — Kamailio cannot route an in-dialog request to a SIP-over-WSS client (no WebSocket alias binding)

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — `route[APPLICATION_DIALOG]` calls `record_route()` but never `set_contact_alias()`, and `route[WITHINDLG]` calls `loose_route()` then `t_relay()` but never `handle_ruri_alias()`. `nathelper.so` **is** loaded, but no aliasing function is invoked anywhere: `set_contact_alias`, `add_contact_alias`, `handle_ruri_alias`, `fix_nated_contact`, `fix_nated_register`, and `nat_uac_test` all occur **0** times |
| Expected | An in-dialog request from Asterisk toward the client is delivered over the client's existing WebSocket connection, so the client can answer and the response can return to Asterisk |
| Actual | Kamailio takes the request's remote target — the client's `Contact` host — and attempts DNS resolution of it: `sip_hostport2su(): could not resolve hostname: "utcp-s2a-proof.invalid"` → `uri2dst2(): failed to resolve` → `t_forward_nonack(): failure to add branches` → `sl_reply_error()`. The BYE is never delivered |
| Why the host is unresolvable by design | RFC 7118 §5.2.1 requires a SIP-over-WebSocket client to use a `Contact` URI whose host is a randomly generated `.invalid` domain, precisely because the client has no reachable address. Every real browser stack (SIP.js, JsSIP) does this. The counterpart mechanism is the alias binding, which the configuration omits — so this is a genuine product gap, not an artifact of the proof client |
| Relevant SIP and DNS evidence | Cluster DNS is fully working (UDP and TCP, `rcode=0`) and the failure is a resolution attempt on the **client** hostname, not on the Kamailio Service name. Kamailio's log carries the exact dialog Call-ID `1281477efeaeaf32@utcp-s2a-closure` with `route=within_dialog method=BYE` |
| Scope of impact | Any Asterisk-initiated in-dialog request toward a browser client — BYE on hangup, session-timer re-INVITE, in-dialog UPDATE. An Asterisk-side or conference-side hangup can never reach the browser, which directly blocks V0 conference admission semantics |
| Static checks | **All passed** — `security-config-check`, `security-config-check-test`, `kamailio-signaling-config-check`, and `…-config-check-test` are green, and the rendered-parser run is clean. Nothing asserts that the WebSocket leg has a transport binding usable for server-initiated requests |
| Severity | **Blocking** for completion criteria 10–12 |

### Smallest bounded correction

1. In `route[APPLICATION_DIALOG]`, call `set_contact_alias()` on the initial
   client INVITE **before** `record_route()`, so the remote target Asterisk stores
   carries the `;alias=IP~PORT~PROTO` binding for the client's WebSocket
   connection.
2. In `route[WITHINDLG]`, call `handle_ruri_alias()` immediately after
   `loose_route()` and before `t_relay()`, so a request travelling toward the
   client is sent over that connection instead of being DNS-resolved.
3. Extend `scripts/kamailio-signaling/config-check` to assert both calls are
   present and correctly ordered relative to `record_route()` and `t_relay()`,
   with `config-check-test` mutations removing each one.
4. Related but separate: `save("location", "0x04")` stores the raw client contact,
   so initial requests routed to a registered browser client will hit the same
   wall. Aliasing the REGISTER path belongs to the V0 inbound-routing slice and
   should not widen this correction.

## Secondary Asterisk Exclusion

**PASS.** `asterisk-ari-b` carries `utcp.dev/runtime-node: local-asterisk-ari-b`
and therefore does not match the policy's three-label selector. It is selected
only by `default-deny`, so it holds no egress grant — neither SIP nor DNS — and
its Pod UID and restart count were unchanged throughout.

## Default-Deny Preservation

**PASS.**

```text
utcp-runtime/default-deny    policyTypes [Ingress, Egress]   podSelector {}   (all Pods)
utcp-platform/default-deny   policyTypes [Ingress, Egress]   podSelector {}   (all Pods)
```

No policy widening:

```text
egress rules on the Asterisk policy   2 (UDP 5060 to Kamailio; UDP/TCP 53 to kube-dns)
ipBlock                               absent
rule without ports (unrestricted)     none
namespace-only destination            none — every rule carries a podSelector
NodePort / LoadBalancer / ExternalIP  absent for every Kamailio and Asterisk Service
Gateway / Ingress / UDPRoute          none referencing Kamailio or Asterisk
HostPort / HostNetwork                absent
```

## rtpengine Boundary Preservation

**PASS.** Untouched throughout this signaling-only proof.

```text
rtpengine_sessions{own}/{foreign}          0 / 0   (baseline 0 / 0)
rtpengine_ports_used{internal}/{default}   0 / 0   (baseline 0 / 0)
offer / answer / delete log lines          0 / 0 / 0
```

## State and Workload Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes | 110 | **110** |
| pending outbox | 0 | **0** |
| Redis keys `sip`/`dialog`/`rtp`/`media` | 0/0/0/0 | **0/0/0/0** |
| rtpengine sessions / ports used | 0 / 0 | **0 / 0** |

Redis `db0` moved `3 → 5`: ordinary session and cache entries from the authorized
API calls that issued the proof credential.

The full-cluster Pod snapshot diff is **empty** — every Pod retained its UID and
restart count. Applying a NetworkPolicy restarted nothing, as required.

```text
expected workload rollouts   none
observed workload rollouts   none
```

## Findings

| Classification | Finding |
|---|---|
| PASS | **`PRODUCT_DEFECT-10` is closed** — the canonical Asterisk workload resolves cluster DNS over UDP **and** TCP (`rcode=0`, `10.43.3.212`, matching the live ClusterIP), using the in-cluster resolver with no `/etc/hosts` or `hostAliases` shortcut |
| PASS | Only the corrected Asterisk NetworkPolicy was applied (generation `2`→`3`); the repository's Kamailio DNS tightening was proven not materially required and deliberately not applied |
| PASS | The policy selects only the canonical `local-asterisk-ari` Pod; the secondary `asterisk-ari-b` workload is excluded and holds no egress grant |
| PASS | Existing SIP egress remains exact — `200 Keepalive` from the internal Service via its DNS name, while rtpengine `ng` and PostgreSQL from the same identity remain denied |
| PASS | A real Asterisk-originated in-dialog BYE was generated, left the Asterisk Pod, reached Kamailio over the internal Service identity, entered established-dialog handling, and was processed by `loose_route()` with no initial-domain rejection and no repeated authentication — Call-ID correlation is exact |
| PASS | Default-deny remains active in both namespaces; no `ipBlock`, no unrestricted rule, no namespace-only destination, and no NodePort, LoadBalancer, ExternalIP, HostPort, HostNetwork, Gateway, Ingress, UDPRoute, or public SIP path was added |
| PASS | rtpengine remains uninvolved; no durable dialog or media authority appeared; no canonical state mutated; **no workload restarted** — the Pod snapshot diff is empty |
| **PRODUCT_DEFECT-11** | Kamailio cannot route an in-dialog request to a SIP-over-WSS client. `nathelper.so` is loaded but no alias function is ever called, so Kamailio DNS-resolves the client's RFC 7118-mandated `.invalid` `Contact` host and fails (`sip_hostport2su` → `uri2dst2` → `t_forward_nonack` → `sl_reply_error`). The BYE never reaches the client. Blocking |
| EXPECTED_BEHAVIOR | Responses toward the client continue to work (the client received its INVITE `200 OK`) because `tm` routes responses by `Via` on the transaction-bound WebSocket connection; only new requests toward the client require the missing alias binding |
| EXPECTED_BEHAVIOR | Redis `db0` `3 → 5` from authorized API session and cache activity during credential issuance |
| EXPECTED_BEHAVIOR | Signaling credentials carry a bounded ~5-minute TTL, so the corridor used a freshly issued credential |
| PROOF_LIMITATION | One intentional live-versus-repository drift remains: `allow-kamailio-signaling-required-traffic` is live at generation `5` while the repository would render generation `6` (the `k8s-app: kube-dns` podSelector tightening). It is hardening-only, was proven unnecessary for this closure, and should be applied with the next security apply |
| PROOF_LIMITATION | The full inbound BYE header block was not captured at Kamailio. `res_pjsip_logger` is not in the committed `autoload=no` module set and no module was added, and Kamailio's committed `debug=2` does not dump messages. Receipt is nevertheless established by exact Call-ID correlation, the `route=within_dialog` marker, and the resolver error naming the client `Contact` host |
| PROOF_LIMITATION | CANCEL still has no deterministic pre-answer window — the `9900` fixture answers immediately and was not altered |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
images built or pushed:         none
resources applied:              1 (NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio)
workloads rolled or restarted:  none
ephemeral containers created:   none (the Asterisk image already ships python3)
proof-only policies added:      none
canonical records mutated:      none beyond authorized API proof data
```

## Cleanup

- Asterisk, Kamailio, and rtpengine all left Ready; the corrected Asterisk NetworkPolicy left applied.
- The proof telephony session was ended through the authorized API (`HTTP 200`), leaving `kamailio_signaling_auth_view` rows for the proof user at `0`.
- Disposable dialog client, credential helpers, cookie jar, secret file, traces, and rendered scratch manifests removed; none was added to the repository or the cluster.
- No ephemeral diagnostic container was created, so none needed removal. No packet capture and no port-forward were used. `.playwright-mcp/` is absent.
- No credential, digest response, or Authorization header content was printed or recorded; traces redact `Authorization`, `Proxy-Authorization`, `WWW-Authenticate`, and `Proxy-Authenticate`.

## T3-S2A Final Status After the Asterisk-Originated BYE Closure Proof

```text
PRODUCT_DEFECT-5  = closed
PRODUCT_DEFECT-6  = closed
PRODUCT_DEFECT-7  = closed
PRODUCT_DEFECT-8  = closed
PRODUCT_DEFECT-9  = closed
PRODUCT_DEFECT-10 = closed
PRODUCT_DEFECT-11 = open (no WebSocket alias binding for in-dialog requests toward the client)
T3-S2A repository implementation = Complete
T3-S2A live signaling proof      = INCOMPLETE
T3-S2A                           = In Progress
T3-S2 media mediation            = Not Started
T3                               = In Progress
UTCP_PHASE                       = T1 (unchanged)
```

## Recommended Next Step

Bounded Codex correction of `PRODUCT_DEFECT-11`: add `set_contact_alias()` before
`record_route()` in `route[APPLICATION_DIALOG]` and `handle_ruri_alias()` after
`loose_route()` in `route[WITHINDLG]`, with matching
`scripts/kamailio-signaling/config-check` and `config-check-test` coverage. Then a
focused reproof of the **Asterisk-originated BYE last hop only** — client receipt,
client `200 OK`, response return to Asterisk, and channel termination. Everything
else in this section is proven and must not be repeated. Apply the pending
`allow-kamailio-signaling-required-traffic` DNS-selector tightening at the same
time.

Do not add rtpengine mediation, fallback destinations, public SIP exposure,
feature gates, manual activation, browser media, conference admission, V0, T4,
external trunks, or PSTN.

---

# WebSocket-Bound Asterisk BYE Proof (`b547a98`)

Verdict: `T3_S2A_WEBSOCKET_BYE_CLOSURE_PROOF_INCOMPLETE`

Evidence-only live proof of the single remaining T3-S2A transaction. No
production file was modified and no completed corridor was broadly repeated.

**`PRODUCT_DEFECT-11` is NOT closed.** The `b547a98` alias lifecycle is present in
the running configuration and parses cleanly, but the WebSocket branch that
creates the alias **never executes**. A bounded packet capture on the Kamailio
node proves the INVITE is relayed to Asterisk with an **unaliased** `Contact`, so
Asterisk stores an unaliased remote target, and the Asterisk-originated BYE still
ends in DNS resolution of the `.invalid` host.

**Two new exact defects:**

* **`PRODUCT_DEFECT-12`** — the guard `if ($proto == "WS" || $proto == "WSS")`
  compares against **uppercase** literals. It is provably false at runtime for a
  WSS-received INVITE, so `add_contact_alias()` is dead code.
* **`PRODUCT_DEFECT-13`** — `handle_ruri_alias()` returns success when the
  Request-URI carries **no** alias at all, so the committed
  `invalid_dialog_contact_alias` guard cannot detect the miss and the request
  falls through to ordinary DNS routing — the exact fallback the contract forbids.

**T3-S2A remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

* Proof executed at `b547a98` (`fix(t3): route websocket dialog aliases`).
* Branch `main`, working tree clean at start, `UTCP_PHASE=T1`, nothing pushed.

All four focused static authorities passed:

```text
make kamailio-signaling-config-check        exit 0
make kamailio-signaling-config-check-test   exit 0
make security-config-check                  exit 0
make security-config-check-test             exit 0
```

Render assertions over the canonical local overlay:

```text
add_contact_alias()      1   line 152   after www_authorize (139): true, before record_route (159): true
handle_ruri_alias()      1   line 167   after loose_route (165): true, before t_relay (174): true
$du == "" guard              line 166   after loose_route: true
set_contact_alias / fix_nated_contact    0 / 0
REGISTER block alias operations          0
rtpengine offer/answer/delete/manage/rtpproxy   0
sha256(rendered kamailio.cfg)            3a38ad30f6add75ba2f0a90990f3bce6da146aa843dc99f751a68fa40e670e3c
Deployment utcp.io/kamailio-config-sha256 3a38ad30f6add75ba2f0a90990f3bce6da146aa843dc99f751a68fa40e670e3c
pod-template annotations                 exactly one (the checksum); no rollout timestamp
image / securityContext                  unchanged / unchanged
```

## Runtime Baseline

```text
kamailio Pod      kamailio-6b85f9db8c-c9cvc  uid a5a29649-…  ready=true  restarts=1  ip 10.42.2.137
kamailio checksum 58e5c733…          live ConfigMap sha256 58e5c733…   (rv 445431)
  alias ops in the LIVE ConfigMap:  add_contact_alias=0  handle_ruri_alias=0   <- b547a98 not yet applied
Deployment        generation 14, rv 445494
NP allow-kamailio-signaling-required-traffic  generation 5, rv 445436  (pending DNS hardening)
canonical Asterisk  asterisk-ari-74d8c4b5f8-k24bc  uid 64078883-…  ready=true  restarts=0  ip 10.42.1.224
kamailio-sip-internal endpoint  10.42.2.137 ready=true
rtpengine         uid 245b78c5-…  ready=true  restarts=0;  sessions 0/0, ports_used 0/0
database          tables 41, tenants 27, RuntimeNodes 110
                  (asterisk/asterisk-ari + simulator/simulator-deterministic), pending outbox 0
redis             dbsize 1, keys sip/dialog/rtp/media = 0/0/0/0
```

## Resources Applied

Three, in dependency order. `kubectl diff` restricted to them contained only the
intended changes.

| Resource | Before | After | Material change |
|---|---|---|---|
| `NetworkPolicy/utcp-platform/allow-kamailio-signaling-required-traffic` | generation `5`, rv `445436` | generation **`6`**, rv `455988` | only the previously reviewed cluster-DNS `podSelector k8s-app=kube-dns` hardening |
| `ConfigMap/utcp-platform/kamailio-config` | rv `445431`, sha256 `58e5c733…` | rv `455990`, sha256 **`3a38ad30…`** | `add_contact_alias()` block in the authenticated WS/WSS dialog path; `$du`/`handle_ruri_alias()` block in `WITHINDLG` |
| `Deployment/utcp-platform/kamailio` | generation `14`, rv `445494` | generation **`15`**, rv `455995` | checksum annotation only |

Retained egress authorities on the policy after apply — nothing lost, nothing widened:

```text
UDP/TCP 53 -> kube-system / k8s-app=kube-dns
TCP  5432  -> utcp-data / network-role=postgres
UDP  2223  -> utcp-platform / component=rtpengine
UDP  5060  -> utcp-runtime / canonical asterisk-ari identity
ipBlock: absent
```

Before the Deployment apply, the Deployment remained at generation `14` with the
old checksum and the Pod was untouched.

## Kamailio Rollout Result

```text
deployment applied : 2026-08-01T05:43:03Z
rollout complete   : 2026-08-01T05:43:07Z  (~4 seconds)
new ReplicaSet     : kamailio-86cdf8c446  revision 15  desired 1  ready 1
new Pod            : kamailio-86cdf8c446-g6chj  uid 7047b84c-…  ip 10.42.2.138  node k3d-utcp-local-agent-1
container started  : 2026-08-01T05:43:05Z      Ready: 2026-08-01T05:43:07Z
old Pod retirement : Created + Started for the new Pod, then SuccessfulDelete / Killing kamailio-6b85f9db8c-c9cvc
conditions         : Available=True (MinimumReplicasAvailable), Progressing=True (NewReplicaSetAvailable)
restart count      : 1   (known transient postgres/NetworkPolicy race; self-recovered)
ERROR lines in the running container : 0
manual restart / Pod deletion / reload RPC / timestamp annotation : none
unrelated workloads rolled : none
```

Classified `EXPECTED_BEHAVIOR` for the single transient restart — no parser or
configuration failure occurred.

## Running Configuration Identity

**PASS.** Byte-identical across all four authorities:

```text
1 repository render        3a38ad30f6add75ba2f0a90990f3bce6da146aa843dc99f751a68fa40e670e3c
2 live ConfigMap           3a38ad30f6add75ba2f0a90990f3bce6da146aa843dc99f751a68fa40e670e3c
3 mounted in the Pod       3a38ad30f6add75ba2f0a90990f3bce6da146aa843dc99f751a68fa40e670e3c
4 Pod checksum annotation  3a38ad30f6add75ba2f0a90990f3bce6da146aa843dc99f751a68fa40e670e3c
```

Running route structure (line numbers from the materialised configuration):

```text
add_contact_alias()   153        record_route()   160
loose_route()         166        $du == ""        167
handle_ruri_alias()   168        t_relay()        175
REGISTER block alias operations  0
rtpengine operations             0
```

Verbatim from the running Pod:

```kamailio
        if ($proto == "WS" || $proto == "WSS") {
            if (!add_contact_alias()) {
                xlog("L_WARN", "kamailio_application_dialog_rejected result=websocket_contact_alias_failed method=$rm call_id=$ci\n");
                sl_send_reply("400", "Bad Request");
                exit;
            }
        }

        record_route();

    route[WITHINDLG] {
        if (loose_route()) {
            if ($du == "") {
                if (!handle_ruri_alias()) {
                    xlog("L_WARN", "kamailio_application_dialog_rejected result=invalid_dialog_contact_alias method=$rm call_id=$ci\n");
                    sl_send_reply("400", "Bad Request");
                    exit;
                }
            }
            ...
```

Kamailio's startup banner confirms both advertised identities unchanged.

## Alias-Bearing Initial Contact

**FAIL — no alias is created.**

Evidence source: one bounded packet capture taken in the **Kamailio k3d node's
network namespace**, filtered to the single proof Call-ID suffix, with
Authorization-family headers redacted before writing. Ten datagrams were
recorded, then the capture was stopped and deleted.

Captured message sequence for the proof dialog:

```text
10.42.2.138:5060  -> 10.43.209.141:5060  INVITE sip:9900@sip.utcp.local.test
10.42.2.138:5060  -> 10.42.1.224:5060    INVITE sip:9900@sip.utcp.local.test
10.42.1.224:5060  -> 10.42.2.138:5060    SIP/2.0 100 Trying
10.43.209.141:5060-> 10.42.2.138:5060    SIP/2.0 100 Trying
10.42.1.224:5060  -> 10.42.2.138:5060    SIP/2.0 200 OK
10.43.209.141:5060-> 10.42.2.138:5060    SIP/2.0 200 OK
10.42.2.138:5060  -> 10.42.1.224:5060    ACK sip:10.42.1.224:5060
10.42.2.138:4438  -> 10.42.1.224:5060    ACK sip:10.42.1.224:5060
10.42.1.224:51509 -> 10.42.2.138:5060    BYE sip:ts-…@utcp-s2a-proof.invalid;transport=ws
10.42.2.138:5060  -> 10.42.1.224:51509   SIP/2.0 478 Unresolvable destination (478/TM)
```

The `Contact` header on the INVITE **as forwarded by Kamailio to Asterisk**:

```text
Contact: <sip:ts-b67f49863f1545b78c00d18d083ba62a@utcp-s2a-proof.invalid;transport=ws>
```

Required:

```text
Contact: <sip:ts-…@utcp-s2a-proof.invalid;transport=ws;alias=<ip>~<port>~<proto>>
```

The `.invalid` host is preserved, but **no alias parameter is appended**.

## PRODUCT_DEFECT-12 — the WebSocket alias guard compares `$proto` against uppercase literals and never matches

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — `route[APPLICATION_DIALOG]`: `if ($proto == "WS" \|\| $proto == "WSS") { if (!add_contact_alias()) { … } }` |
| Expected | An initial authenticated INVITE received over WSS enters the branch and `add_contact_alias()` appends exactly one `;alias=` parameter to the client `Contact` before `record_route()` |
| Actual | The branch is never entered, so `add_contact_alias()` is dead code and the `Contact` is forwarded verbatim |
| Cause | Kamailio's `$proto` pseudo-variable renders the transport in **lowercase** (`udp`, `tcp`, `tls`, `sctp`, `ws`, `wss`). The committed comparison uses uppercase `"WS"` / `"WSS"`, so it is always false |
| Runtime proof by elimination | Three mutually exclusive outcomes were possible for a WSS-received INVITE. (a) branch entered and `add_contact_alias()` succeeded → the forwarded `Contact` would carry `;alias=`; the capture shows it does not. (b) branch entered and the call failed → `sl_send_reply("400")` plus `result=websocket_contact_alias_failed`; the log shows **0** occurrences and the INVITE was relayed and answered `200 OK`. (c) branch not entered — the only remaining possibility, and the observed one |
| Relevant SIP and log evidence | Forwarded `Contact` without `;alias=` (capture, above); `websocket_contact_alias_failed = 0`; `invalid_dialog_contact_alias = 0`; the INVITE completed normally with `200 OK` |
| Static and parser checks | **All passed** — `kamailio-signaling-config-check`, `…-config-check-test`, `security-config-check`, `…-config-check-test` are green and the rendered-parser run is clean. A syntactically valid but never-true condition is not detectable by parsing; nothing asserts the branch is reachable |
| Severity | **Blocking.** `PRODUCT_DEFECT-11` remains open because the correction has no runtime effect |

### Smallest bounded correction

1. Compare against the lowercase values Kamailio actually produces, e.g.
   `if ($proto == "ws" || $proto == "wss")`, or use a case-insensitive form.
2. Add a `scripts/kamailio-signaling/config-check` assertion that the WebSocket
   alias guard uses the lowercase transport tokens, with a `config-check-test`
   mutation reintroducing the uppercase form.

## PRODUCT_DEFECT-13 — `handle_ruri_alias()` succeeds when no alias is present, so the committed guard cannot detect the miss and the request falls back to DNS

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — `route[WITHINDLG]`: `if ($du == "") { if (!handle_ruri_alias()) { … 400 … } }` followed unconditionally by `t_relay()` |
| Expected | When an in-dialog request toward the client carries no usable alias, the committed `invalid_dialog_contact_alias` failure fires and the request is rejected — never resolved through DNS |
| Actual | `handle_ruri_alias()` treats an absent alias parameter as success, so the guard passes, `$du` stays empty, and `t_relay()` performs ordinary DNS resolution of the `.invalid` host: `sip_hostport2su(): could not resolve hostname: "utcp-s2a-proof.invalid"` → `uri2dst2(): failed to resolve` → `t_forward_nonack(): failure to add branches` → `kamailio_application_dialog_relay_failed route=within_dialog method=BYE` → `478 Unresolvable destination` returned to Asterisk |
| Relevant evidence | `invalid_dialog_contact_alias = 0` while `application_dialog_relay_failed = 1` for the same Call-ID; the captured `478 Unresolvable destination (478/TM)` reply |
| Static and parser checks | **All passed** |
| Severity | Blocking for the contract requirement that no alias miss falls back to DNS. It also masked `PRODUCT_DEFECT-12`: the intended explicit failure never fired, so the symptom surfaced only as a generic resolution error |

### Smallest bounded correction

Assert the post-condition rather than only the return value — after
`handle_ruri_alias()`, require `$du != ""` before relaying, and otherwise take the
committed `invalid_dialog_contact_alias` failure branch. Add matching
`config-check` and `config-check-test` coverage.

## Asterisk Remote Target

**FAIL (consequence of `PRODUCT_DEFECT-12`).** Asterisk faithfully retained what
Kamailio gave it — an unaliased remote target. Proven directly from the generated
BYE rather than inferred from configuration:

```text
BYE sip:ts-b67f49863f1545b78c00d18d083ba62a@utcp-s2a-proof.invalid;transport=ws SIP/2.0
```

No `;alias=` parameter is present in the Request-URI.

## Asterisk-Originated BYE

**PASS as a SIP transaction** — Asterisk generated a correct in-dialog BYE from a
bounded `channel request hangup PJSIP/anonymous-00000002` stimulus. No dialplan,
module, or production configuration was changed and `res_pjsip_logger` was not
loaded.

```text
BYE sip:ts-b67f49863f1545b78c00d18d083ba62a@utcp-s2a-proof.invalid;transport=ws SIP/2.0
Via: SIP/2.0/UDP 10.42.1.224:5060;rport;branch=z9hG4bKPj28025909-3cbd-4e17-a21a-482ff478c4d4
From: <sip:9900@sip.utcp.local.test>;tag=c2443638-6b25-42f0-8326-b02b1194a9ee
To: <sip:ts-b67f49863f1545b78c00d18d083ba62a@sip.utcp.local.test>;tag=5db7fde37e94
Call-ID: 2d576b8dba0fe88b@utcp-s2a-closure
CSeq: 930 BYE
Route: <sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060;lr;r2=on;ftag=5db7fde37e94>
Route: <sip:sip.utcp.local.test:443;transport=ws;lr;r2=on;ftag=5db7fde37e94>
Max-Forwards: 70
User-Agent: Asterisk PBX 20.20.1
```

Call-ID and both tags match the established dialog exactly, CSeq is incremented,
and both route-set hops are present with the internal Kamailio Service first.

## Kamailio Receipt and Loose Routing

**PASS.** The BYE reached Kamailio at `10.42.2.138:5060` from `10.42.1.224:51509`
and was handled as an established dialog:

```text
kamailio_application_dialog_relay_failed route=within_dialog method=BYE call_id=2d576b8dba0fe88b@utcp-s2a-closure
```

That xlog sits inside the `if (loose_route())` block, so `has_totag()` routed to
`WITHINDLG` and `loose_route()` returned true. For this BYE there was **no**
`initial_foreign_domain`, no `foreign_domain`, no authentication challenge, and no
repeated Asterisk destination selection.

## Alias Consumption Result

**FAIL.** `$du` was empty as expected, but `handle_ruri_alias()` returned success
with nothing to consume (`PRODUCT_DEFECT-13`), so:

```text
$du after handle_ruri_alias()   still empty
.invalid host sent to DNS       YES  <- contract requires NO
sip_hostport2su() failure       present
invalid_dialog_contact_alias    absent (0)
t_forward_nonack failed         present
fallback destination used       none
```

## WebSocket Connection Selection

**FAIL.** No WebSocket connection was selected; Kamailio attempted ordinary DNS
routing instead.

## Client BYE Receipt / Client Response / Response Return to Asterisk

**FAIL.**

```text
asterisk_originated_bye_received   false
bye_retransmissions_after_200      0   (nothing arrived, so nothing to answer)
client observation window          45 s
```

Kamailio returned `478 Unresolvable destination (478/TM)` to Asterisk instead of a
client-generated `200 OK`. This is a locally generated Kamailio error response,
not the browser's response — the exact condition the proof contract says must not
be accepted.

## Asterisk Transaction and Channel Termination

The Asterisk channel terminated **locally only**, as in the previous round. The
BYE transaction did not complete end to end, so this remains distinguishable from
a genuine completed termination.

## REGISTER Preservation

**PASS**, and confirmed free of alias side effects:

```text
sip_status=200  sip_result=accepted   active_location_contacts=1
kamailio: kamailio_registration_challenge result=challenge
          kamailio_registration_accepted result=ok
stored contact: sip:ts-…@t3s2await.wss.invalid;transport=ws      <- no ;alias= parameter
```

The registrar route is unchanged and the alias lifecycle does not touch REGISTER,
exactly as required.

## Regression Boundaries

**PASS.** One bounded smoke through the changed `WITHINDLG` block:

```text
invite_final_status             200 OK
post_ack_200_retransmissions    0            <- ACK still lands
client_bye_final_status         200 OK
dialog_terminated               true
kamailio log                    no rejection or relay failure
```

Client ACK and client-originated BYE are therefore unaffected by the alias code.
The `503` failure-route contract is unchanged (`t_on_failure("ASTERISK_UNAVAILABLE")`
plus `failure_route[ASTERISK_UNAVAILABLE]` both present in the running
configuration).

## Security Boundary Preservation

**PASS**, with one qualification.

```text
alias creation gated behind authentication   yes — the block sits after www_authorize and the
                                             $au != $fU identity check in APPLICATION_DIALOG
unauthenticated initial request              still receives the 401 challenge; it can never reach
                                             the alias or relay stage
alias consumption gated                      occurs only after has_totag() -> WITHINDLG -> loose_route()
REGISTER alias behaviour                     none (0 alias operations in the REGISTER block)
public SIP or additional destination         none created
```

Qualification: because `PRODUCT_DEFECT-12` makes the creation branch unreachable
and `PRODUCT_DEFECT-13` lets a missing alias pass the guard, the committed
"invalid alias produces an explicit failure, never DNS fallback" property is
**not** currently satisfied. No destructive open-relay testing was performed and
no malformed-alias transaction was injected, since the alias path is dead code —
that test becomes meaningful only after `PRODUCT_DEFECT-12` is corrected.

## rtpengine Boundary Preservation

**PASS.**

```text
rtpengine_sessions{own}/{foreign}          0 / 0   (baseline 0 / 0)
rtpengine_ports_used{internal}/{default}   0 / 0   (baseline 0 / 0)
running configuration rtpengine operations 0
```

## Public-Surface Preservation

**PASS.** Both Kamailio Services remain ClusterIP-only (`8080/TCP` and
`5060/UDP`), with no NodePort, LoadBalancer, ExternalIP, HostPort, or
HostNetwork.

## State and Workload Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes / families | 110 / asterisk + simulator | **110 / unchanged** |
| pending outbox | 0 | **0** |
| Redis keys `sip`/`dialog`/`rtp`/`media` | 0/0/0/0 | **0/0/0/0** |
| rtpengine sessions / ports used | 0 / 0 | **0 / 0** |

Redis `db0` moved `1 → 3` from authorized API session and cache activity.

Full-cluster Pod snapshot diff contains exactly one change — the expected
Kamailio rollout:

```text
- utcp-platform kamailio-6b85f9db8c-c9cvc  a5a29649-…  restarts 1
+ utcp-platform kamailio-86cdf8c446-g6chj  7047b84c-…  restarts 1
```

Asterisk, rtpengine, and every unrelated workload retained their UID **and**
restart count.

## Findings

| Classification | Finding |
|---|---|
| PASS | Only the three intended resources were applied, in dependency order; the NetworkPolicy change was limited to the previously reviewed cluster-DNS hardening and every existing egress authority (DNS, PostgreSQL, rtpengine, Asterisk) survived intact with no `ipBlock` and nothing widened |
| PASS | The checksum-coupled Deployment produced a fully automatic ~4-second rollout to ReplicaSet revision 15 with no manual restart, retiring the old Pod only after the replacement started, and no unrelated workload rolled |
| PASS | Running configuration is byte-identical across repository render, live ConfigMap, in-Pod mount, and Pod checksum annotation (`3a38ad30…`), with the alias lifecycle correctly ordered and zero rtpengine operations |
| PASS | Asterisk generated a correct in-dialog BYE — matching Call-ID and tags, incremented CSeq, and both route-set hops with the internal Kamailio Service first — which reached Kamailio, was recognised by `has_totag()`, and was processed by `loose_route()` with no initial-domain rejection and no repeated authentication |
| PASS | REGISTER is unchanged and free of alias side effects (stored contact carries no `;alias=`); client ACK and client-originated BYE remain fully functional through the changed `WITHINDLG`; the `503` failure-route contract is intact; rtpengine remains uninvolved; no public SIP surface; no durable dialog authority; only the expected Kamailio rollout occurred |
| **PRODUCT_DEFECT-12** | `if ($proto == "WS" \|\| $proto == "WSS")` compares against uppercase literals while `$proto` renders lowercase, so `add_contact_alias()` is dead code. Proven by elimination from runtime evidence: the forwarded `Contact` carries no alias, yet `websocket_contact_alias_failed` never fired and the INVITE completed with `200 OK`. Blocking — `PRODUCT_DEFECT-11` therefore remains open |
| **PRODUCT_DEFECT-13** | `handle_ruri_alias()` returns success when the Request-URI has no alias, so the committed `invalid_dialog_contact_alias` guard cannot fire and `t_relay()` falls back to DNS on the `.invalid` host, yielding `478 Unresolvable destination`. Blocking for the no-DNS-fallback contract, and it masked `PRODUCT_DEFECT-12` |
| EXPECTED_BEHAVIOR | The corrected Kamailio Pod restarted once on the known transient `postgres … Connection refused` new-Pod-IP versus NetworkPolicy programming race; it self-recovered with zero ERROR lines and no parser or configuration failure |
| EXPECTED_BEHAVIOR | Redis `db0` `1 → 3` from authorized API session and cache activity; signaling credentials carry a bounded ~5-minute TTL so each corridor used a freshly issued credential |
| PROOF_LIMITATION | The malformed-alias security case was not exercised, because `PRODUCT_DEFECT-12` makes the alias path unreachable; it becomes testable only after that correction |
| PROOF_LIMITATION | `res_pjsip_logger` remains outside the committed `autoload=no` module set and was not loaded. Asterisk-side evidence is the bounded packet capture, the live channel table, and `calls processed` counters |
| PROOF_LIMITATION | CANCEL still has no deterministic pre-answer window — the `9900` fixture answers immediately and was not altered |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
images built or pushed:         none
resources applied:              3 (NetworkPolicy, kamailio ConfigMap, kamailio Deployment)
workloads rolled:               1 (kamailio, automatically via checksum coupling)
unrelated workloads restarted:  none
packet capture:                 1 bounded run in the Kamailio k3d node network namespace,
                                filtered to the single proof Call-ID, Authorization redacted,
                                stopped and deleted at cleanup
cluster security posture:       unchanged — the capture ran as a throwaway Docker container in the
                                node namespace, not as a Kubernetes workload; no PSA exception,
                                no privileged Pod, and no proof-only NetworkPolicy
canonical records mutated:      none beyond authorized API proof data
```

## Cleanup

- Corrected Kamailio left Ready (`kamailio-86cdf8c446-g6chj`); Asterisk and rtpengine left Ready; all three corrected resources left applied.
- The proof contact was deregistered through the canonical client (`sip_status=200`) and the telephony session ended through the authorized API (`HTTP 200`), leaving `kamailio_signaling_auth_view` rows and active contacts at `0`.
- Packet capture stopped, its container removed, and the capture file deleted. No port-forward was used.
- Disposable dialog client, regression-smoke client, sniffer, credential helpers, cookie jar, secret file, traces, and rendered scratch manifests removed; none was added to the repository or the cluster.
- No ephemeral Kubernetes diagnostic container was created. `.playwright-mcp/` is absent.
- No credential, digest response, or Authorization header content was printed or recorded; both the client trace and the packet capture redact `Authorization`, `Proxy-Authorization`, `WWW-Authenticate`, and `Proxy-Authenticate`.

## T3-S2A Final Status After the WebSocket BYE Proof

```text
PRODUCT_DEFECT-5  = closed
PRODUCT_DEFECT-6  = closed
PRODUCT_DEFECT-7  = closed
PRODUCT_DEFECT-8  = closed
PRODUCT_DEFECT-9  = closed
PRODUCT_DEFECT-10 = closed
PRODUCT_DEFECT-11 = open (in-dialog request still cannot reach the WSS client)
PRODUCT_DEFECT-12 = open (uppercase $proto guard makes add_contact_alias() dead code)
PRODUCT_DEFECT-13 = open (handle_ruri_alias() succeeds with no alias, allowing DNS fallback)
T3-S2A repository implementation = Complete
T3-S2A live signaling proof      = INCOMPLETE
T3-S2A                           = In Progress
T3-S2 media mediation            = Not Started
T3                               = In Progress
UTCP_PHASE                       = T1 (unchanged)
```

## Recommended Next Step

Bounded Codex correction of `PRODUCT_DEFECT-12` and `PRODUCT_DEFECT-13` in
`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`: compare
`$proto` against the lowercase `ws`/`wss` tokens, and require `$du != ""` after
`handle_ruri_alias()` before relaying. Add `config-check` assertions for both,
with `config-check-test` mutations restoring the uppercase comparison and removing
the post-condition. Then reproof only the last hop — alias-bearing forwarded
`Contact`, alias-bearing Asterisk remote target, alias consumption, browser
receipt, browser `200 OK`, response return to Asterisk, and completed channel
termination — plus the malformed-alias security case that is currently untestable.

Do not add registration aliasing, Path, Outbound, GRUU, fallback routing, public
SIP exposure, feature gates, manual activation, rtpengine mediation, browser
media, conference admission, V0, T4, external trunks, or PSTN.

---

# WebSocket Alias and BYE Closure Proof (`1381bf3`)

Verdict: `T3_S2A_WEBSOCKET_ALIAS_CLOSURE_PROOF_INCOMPLETE`

Evidence-only live proof of the WebSocket alias lifecycle. No production file was
modified and no completed corridor was broadly repeated.

**`PRODUCT_DEFECT-11`, `PRODUCT_DEFECT-12` and `PRODUCT_DEFECT-13` are all
closed.** The lowercase guard executes, exactly one alias reaches Asterisk,
Asterisk retains the alias-bearing remote target, the Asterisk-originated BYE is
consumed by `handle_ruri_alias()`, `$du` becomes non-empty, the existing browser
WebSocket connection is selected, the browser receives the BYE and answers
`200 OK`, and that response returns through Kamailio to Asterisk with the
transaction completing. Missing and malformed aliases both fail explicitly with
no DNS query and no relay.

**One new defect blocks completion: `PRODUCT_DEFECT-14.`** The `$du`
postcondition is applied to **every** in-dialog request, but only requests
travelling *toward the browser* carry an alias. In-dialog requests travelling
*toward Asterisk* — the ACK and the client-originated BYE — carry a normal
routable Request-URI and no alias, and are now wrongly rejected `400 Bad Request`
with `result=missing_dialog_contact_alias`. This regresses two previously proven
corridors.

**T3-S2A remains incomplete. T3 remains In Progress. `UTCP_PHASE=T1` is unchanged.**

## Source Commit

* Proof executed at `1381bf3` (`fix(t3): activate websocket dialog aliases`).
* Branch `main`, working tree clean at start, `UTCP_PHASE=T1`, nothing pushed.

All four focused static authorities passed:

```text
make kamailio-signaling-config-check        exit 0
make kamailio-signaling-config-check-test   exit 0
make security-config-check                  exit 0
make security-config-check-test             exit 0
```

Render assertions over the canonical local overlay:

```text
lowercase guard  if ($proto == "ws" || $proto == "wss")   present
uppercase "WS" / "WSS" comparisons                        absent
www_authorize 139 -> ws/wss guard 151 -> add_contact_alias 152 -> record_route 159
loose_route 165 -> $du=="" 166 -> handle_ruri_alias 167 -> $du postcondition 173 -> t_relay 180
websocket_contact_alias_failed / invalid_dialog_contact_alias / missing_dialog_contact_alias  1 / 1 / 1
REGISTER block alias operations                           0
rtpengine operations                                      0
sha256(rendered kamailio.cfg)   2b92c60ba4b4ae5717f6222351362c400a89320cfd1a406156c1e91072378301
Deployment checksum annotation  2b92c60ba4b4ae5717f6222351362c400a89320cfd1a406156c1e91072378301
pod-template annotations        exactly one (the checksum); no rollout timestamp
image / securityContext         unchanged / unchanged
```

## Runtime Baseline

```text
kamailio Pod       kamailio-86cdf8c446-g6chj  uid 7047b84c-…  ready=true  restarts=1  ip 10.42.2.138
kamailio checksum  3a38ad30…    live ConfigMap sha256 3a38ad30…    Deployment generation 15
  live guard: uppercase "WS" present=1, lowercase "ws"=0, missing_dialog_contact_alias=0
  -> the live configuration was still the PRODUCT_DEFECT-12 revision
canonical Asterisk asterisk-ari-74d8c4b5f8-k24bc  uid 64078883-…  ready=true  restarts=0  ip 10.42.1.224
kamailio-sip-internal endpoint  10.42.2.138 ready=true
rtpengine          uid 245b78c5-…  ready=true  restarts=0;  sessions 0/0, ports_used 0/0
database           tables 41, dialog/rtp/media tables (none), tenants 27, RuntimeNodes 110
                   (asterisk/asterisk-ari + simulator/simulator-deterministic), pending outbox 0
redis              dbsize 2, keys sip/dialog/rtp/media = 0/0/0/0
```

## Resources Applied

Two, in order. `kubectl diff` restricted to them contained only the correction.

| Resource | Before | After | Material change |
|---|---|---|---|
| `ConfigMap/utcp-platform/kamailio-config` | rv `458…`, sha256 `3a38ad30…` | rv `458977`, sha256 **`2b92c60b…`** | uppercase `"WS"/"WSS"` guard replaced by lowercase `"ws"/"wss"`; `missing_dialog_contact_alias` `$du` postcondition added after `handle_ruri_alias()` |
| `Deployment/utcp-platform/kamailio` | generation `15`, rv `456044` | generation **`16`**, rv `458982` | checksum annotation only |

Before the Deployment apply, the Deployment remained at generation `15` with the
old checksum and the Pod was untouched (`uid 7047b84c-…`, `restarts=1`).

## Kamailio Rollout Result

```text
deployment applied : 2026-08-01T07:19:01Z
rollout complete   : 2026-08-01T07:19:05Z  (~4 seconds)
new ReplicaSet     : kamailio-778d98849b  revision 16  desired 1  ready 1
new Pod            : kamailio-778d98849b-n4jvg  uid b46444a1-…  ip 10.42.2.139  node k3d-utcp-local-agent-1
container started  : 2026-08-01T07:19:03Z      Ready: 2026-08-01T07:19:05Z
old Pod retirement : Created + Started for the new Pod, then SuccessfulDelete / Killing kamailio-86cdf8c446-g6chj
conditions         : Available=True (MinimumReplicasAvailable), Progressing=True (NewReplicaSetAvailable)
restart count      : 1     ERROR lines in the running container: 0
manual restart / Pod deletion / reload RPC / timestamp annotation : none
unrelated workloads rolled : none
```

The single restart is the known transient `postgres`/NetworkPolicy startup race;
it self-recovered with no parser or configuration error. `EXPECTED_BEHAVIOR`.

## Running Configuration Identity

**PASS.** Byte-identical across all four authorities:

```text
1 repository render        2b92c60ba4b4ae5717f6222351362c400a89320cfd1a406156c1e91072378301
2 live ConfigMap           2b92c60ba4b4ae5717f6222351362c400a89320cfd1a406156c1e91072378301
3 mounted in the Pod       2b92c60ba4b4ae5717f6222351362c400a89320cfd1a406156c1e91072378301
4 Pod checksum annotation  2b92c60ba4b4ae5717f6222351362c400a89320cfd1a406156c1e91072378301
```

Assertions against the running configuration:

```text
uppercase "WS"/"WSS" occurrences   0
lowercase ws/wss guard             1
invalid_dialog_contact_alias       1
missing_dialog_contact_alias       1
REGISTER alias operations          0        rtpengine operations  0
```

## Live WebSocket Transport Value

**PASS.** The authoritative outcome is the alias itself: the lowercase branch is
the only code path that can append `;alias=`, and the forwarded `Contact` carries
one. `$proto` therefore evaluated to the lowercase `wss` token at runtime, and no
`websocket_contact_alias_failed` was logged. No permanent debug configuration was
added.

## Alias-Bearing Initial Contact

**PASS.** From the bounded capture of the INVITE relayed by Kamailio to Asterisk:

```text
Contact: <sip:ts-640b6b4f03e44e669b5c9fef91cee9d5@utcp-s2a-proof.invalid;alias=10.42.0.150~36196~5;transport=ws>
```

```text
original browser identity retained   yes (ts-640b6b4f03e44e669b5c9fef91cee9d5)
.invalid host retained               yes (utcp-s2a-proof.invalid)
;alias= parameters                   exactly 1  (verified on every captured copy)
alias value                          10.42.0.150~36196~5   (ip ~ port ~ proto, proto 5 = WSS)
alias-creation failure log           none
```

The alias address `10.42.0.150` is the Traefik ingress peer of the received WSS
connection, which is the correct connection binding for a browser leg terminating
at the edge.

## Asterisk Remote Target

**PASS**, proven from the generated BYE rather than inferred from the forwarded
INVITE:

```text
BYE sip:ts-640b6b4f03e44e669b5c9fef91cee9d5@utcp-s2a-proof.invalid;transport=ws;alias=10.42.0.150~36196~5 SIP/2.0
```

Asterisk retained both the browser `.invalid` Contact and the Kamailio alias
parameter.

## Asterisk-Originated BYE

**PASS.**

```text
Via: SIP/2.0/UDP 10.42.1.224:5060;rport;branch=z9hG4bKPj8c2121ea-35a1-4247-a05e-8159921a7925
From: <sip:9900@sip.utcp.local.test>;tag=3534a607-10fe-4608-bca3-77b991e3a780
To: <sip:ts-640b6b4f03e44e669b5c9fef91cee9d5@sip.utcp.local.test>;tag=b7e836e8d929
Call-ID: a10c4536be4c3d68@utcp-s2a-alias          <- matches the established dialog
CSeq: 5701 BYE                                    <- incremented
Route: <sip:kamailio-sip-internal.utcp-platform.svc.cluster.local:5060;lr;r2=on;ftag=b7e836e8d929>
Route: <sip:sip.utcp.local.test:443;transport=ws;lr;r2=on;ftag=b7e836e8d929>
Reason: SIP ;cause=408 ;text="Request Timeout"
User-Agent: Asterisk PBX 20.20.1
```

**Divergence, recorded precisely.** The `Reason: cause=408` header shows Asterisk
terminated on its own ACK timeout rather than solely on the CLI stimulus, because
`PRODUCT_DEFECT-14` prevented the ACK from arriving. The BYE is nonetheless a
genuine, correctly formed in-dialog request carrying the alias-bearing remote
target, and it exercised the complete alias corridor — so it remains valid
evidence for the alias claims, while the ACK failure is reported separately as a
defect rather than smoothed over.

## Kamailio Receipt and Loose Routing

**PASS.** The BYE arrived at `10.42.2.139:5060` from `10.42.1.224:18753`. For this
BYE there was **no** `initial_foreign_domain`, no `foreign_domain`, no
authentication challenge, and no repeated Asterisk destination selection. The
request was processed inside the `if (loose_route())` block.

## Alias Consumption and Destination Postcondition

**PASS.**

```text
$du before alias handling   empty  (the `if ($du == "")` branch was entered)
handle_ruri_alias()         succeeded — no invalid_dialog_contact_alias logged
$du after alias handling    non-empty — the missing_dialog_contact_alias guard did NOT fire
                            for this BYE, and t_relay() proceeded
resulting destination       the WSS connection identified by 10.42.0.150~36196~5
```

Negative conditions, all satisfied for this BYE:

```text
missing_dialog_contact_alias   0 for this Call-ID
invalid_dialog_contact_alias   0
.invalid DNS query             none
sip_hostport2su() failure      none
t_forward_nonack() failure     none
478 Unresolvable destination   none
fallback destination           none
new WebSocket connection       none — the existing connection was reused
```

## WebSocket Connection Selection

**PASS.** The BYE was delivered over the client's existing WebSocket connection,
evidenced by the client receiving it on the same socket it had held since the
INVITE, and by the Via chain Kamailio added:

```text
Via: SIP/2.0/TCP sip.utcp.local.test:443;branch=z9hG4bK1334.…   <- Kamailio's client-facing hop
     SIP/2.0/UDP 10.42.1.224:5060;received=10.42.1.224;rport=18753;branch=z9hG4bKPj8c2121ea-…
```

## Client BYE Receipt

**PASS.**

```text
inbound_request_method            BYE
inbound_request_uri               sip:ts-640b6b4f03e44e669b5c9fef91cee9d5@utcp-s2a-proof.invalid;transport=ws
inbound_received_at               07:21:53Z
inbound_call_id                   a10c4536be4c3d68@utcp-s2a-alias   (matches, verified true)
inbound_from_tag                  3534a607-10fe-4608-bca3-77b991e3a780
inbound_to_tag                    b7e836e8d929
inbound_cseq                      5701 BYE
inbound_authorization_present     false
```

Kamailio consumed the alias parameter before delivery, so the client saw a clean
Request-URI.

## Client Response

**PASS.** `200 OK` sent at `07:21:53Z` over the same WebSocket connection,
echoing `Via`, `From`, `To`, `Call-ID` and `CSeq`.

## Response Return to Asterisk

**PASS**, captured on the wire:

```text
10.42.2.139:5060 -> 10.42.1.224:18753   SIP/2.0 200 OK
Via: SIP/2.0/UDP 10.42.1.224:5060;received=10.42.1.224;rport=18753;branch=z9hG4bKPj8c2121ea-…
From: <sip:9900@sip.utcp.local.test>;tag=3534a607-10fe-4608-bca3-77b991e3a780
To: <sip:ts-640b6b4f03e44e669b5c9fef91cee9d5@sip.utcp.local.test>;tag=b7e836e8d929
Call-ID: a10c4536be4c3d68@utcp-s2a-alias
CSeq: 5701 BYE
User-Agent: UTCP-T3S2A-Alias-Closure
```

Call-ID and BYE CSeq match, the branch parameter matches Asterisk's own, and the
`User-Agent` proves this is the **browser's** response relayed by Kamailio, not a
Kamailio-generated reply.

## Asterisk Transaction and Channel Termination

**PASS.**

```text
bye_retransmissions_after_200   0        <- Asterisk accepted the response; no repeat
transaction timeout             none
Kamailio-generated error        none
asterisk channels afterwards    0 active channels, 0 active calls
client dialog_terminated        true
manual dialog cleanup           none
```

## Missing-Alias Result

**PASS.** One bounded synthetic in-dialog BYE with a distinct synthetic Call-ID
(never a live dialog identifier), a Route header targeting Kamailio, a `.invalid`
Request-URI and **no** alias parameter:

```text
synthetic_call_id      synthetic-missing-7fa549217b35@utcp-s2a-synthetic
synthetic_request_uri  sip:ts-synthetic@utcp-s2a-synth.invalid;transport=ws
synthetic_status       400 Bad Request
kamailio log           kamailio_application_dialog_rejected result=missing_dialog_contact_alias method=BYE
```

```text
DNS query for the .invalid host   none
t_relay()                         not reached
request reaching the browser      none
request reaching Asterisk         none — 0 datagrams captured for the synthetic Call-IDs
fallback destination              none
```

## Malformed-Alias Result

**PASS.** One bounded synthetic request based on the **observed** `ip~port~proto`
alias format, mutated to be structurally invalid:

```text
synthetic_call_id      synthetic-malformed-59aa09f25fe2@utcp-s2a-synthetic
synthetic_request_uri  sip:ts-synthetic@utcp-s2a-synth.invalid;transport=ws;alias=not-an-address~~
synthetic_status       400 Bad Request
kamailio log           ERROR: nathelper [nathelper.c:1244]: ki_handle_ruri_alias_mode(): no proto in alias param
                       kamailio_application_dialog_rejected result=invalid_dialog_contact_alias method=BYE
```

```text
DNS query        none
$du              never became a usable destination
relay            none
fallback         none
browser/Asterisk transaction created   none
```

No broad fuzzing was performed — exactly one malformed case derived from the real
format.

## DNS-Fallback Elimination

**PASS.** Across the entire proof there was **no** `sip_hostport2su()` failure,
**no** `uri2dst2()` failure, **no** `t_forward_nonack()` failure and **no**
`478 Unresolvable destination`. The `.invalid` host was never submitted to DNS in
any corridor — successful, missing-alias, or malformed-alias.

## PRODUCT_DEFECT-14 — the `$du` postcondition is applied to in-dialog requests that legitimately carry no alias, rejecting ACK and client-originated BYE

| Field | Value |
|---|---|
| Seam | [`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml`](../../../infrastructure/kubernetes/base/platform/kamailio-configmap.yaml) — `route[WITHINDLG]`: inside `if ($du == "")`, after `handle_ruri_alias()`, the new unconditional `if ($du == "") { … missing_dialog_contact_alias … 400 … }` |
| Expected | Only in-dialog requests directed at the **browser** require an alias. Requests directed at **Asterisk** carry a normal routable Request-URI (`sip:10.42.1.224:5060`) and are relayed on that URI, exactly as they were before this change |
| Actual | The postcondition runs for every in-dialog request. For a request toward Asterisk `loose_route()` correctly leaves `$du` empty (the Request-URI is already the destination), `handle_ruri_alias()` finds no alias, `$du` stays empty, and the guard rejects it `400 Bad Request` with `result=missing_dialog_contact_alias` |
| Observed — ACK | `kamailio_application_dialog_rejected result=missing_dialog_contact_alias method=ACK call_id=a10c4536be4c3d68@utcp-s2a-alias`. The ACK never reached Asterisk, so Asterisk retransmitted its `200 OK` (`post_ack_200_retransmissions=3`, previously `0`) and finally tore the channel down on its ACK timer — the source of the `Reason: cause=408` on the BYE |
| Observed — client-originated BYE | Reproduced with a second bounded dialog: `client_bye_final_status=400 Bad Request`, `client_bye_terminated=false`, with `missing_dialog_contact_alias method=ACK` and `method=BYE` both logged for that Call-ID |
| Regression scope | Two previously proven corridors — **ACK continuity** and **client-originated BYE** — are broken. Both were `PASS` at `081267a` and remained `PASS` at `b547a98` |
| Static and parser checks | **All passed** — all four focused authorities are green and the rendered-parser run is clean. Nothing asserts that the alias postcondition applies only to browser-directed requests |
| Severity | **Blocking.** The alias corridor itself now works, but ordinary client-to-Asterisk in-dialog signaling does not |

### Smallest bounded correction

1. Require the alias only when the Request-URI actually carries one — for example
   guard the whole alias block on the presence of the `alias` URI parameter:

   ```kamailio
   if ($du == "" && $(ru{uri.param,alias}) != "") {
       if (!handle_ruri_alias()) { … invalid_dialog_contact_alias … }
       if ($du == "") { … missing_dialog_contact_alias … }
   }
   ```

   A request toward Asterisk then keeps its routable Request-URI and relays
   normally, while a browser-directed request without a usable alias still fails
   explicitly and never reaches DNS.
2. Extend `scripts/kamailio-signaling/config-check` to assert the alias
   postcondition is reachable only for alias-bearing Request-URIs, with
   `config-check-test` mutations that (a) restore the unconditional
   postcondition and (b) drop the postcondition entirely.

## REGISTER Preservation

**PASS**, and free of alias side effects:

```text
sip_status=200  sip_result=accepted
kamailio: kamailio_registration_challenge result=challenge
          kamailio_registration_accepted result=ok
stored contact: sip:ts-640b6b4f03e44e669b5c9fef91cee9d5@t3s2aalias.wss.invalid;transport=ws
```

No `;alias=` appears in registrar storage — the alias lifecycle touches only the
application-dialog route, as required.

## Security Boundary Preservation

**PASS.**

```text
alias creation gated behind authentication   yes — the ws/wss block sits after www_authorize
                                             and the $au != $fU identity check
unauthenticated initial request              still receives the 401 challenge; it can never
                                             reach the alias or relay stage
alias consumption gated                      only after has_totag() -> WITHINDLG -> loose_route()
invalid alias                                explicit 400 + invalid_dialog_contact_alias, no DNS
missing alias                                explicit 400 + missing_dialog_contact_alias, no DNS
REGISTER alias behaviour                     none
public SIP or additional destination         none created
```

The "invalid or missing alias fails explicitly, never DNS fallback" property that
`PRODUCT_DEFECT-13` left unsatisfied is now **satisfied**. No destructive
open-relay testing was performed; exactly two bounded synthetic transactions were
used.

## rtpengine Boundary Preservation

**PASS.**

```text
rtpengine_sessions{own}/{foreign}          0 / 0   (baseline 0 / 0)
rtpengine_ports_used{internal}/{default}   0 / 0   (baseline 0 / 0)
running configuration rtpengine operations 0
```

## Public-Surface Preservation

**PASS.** Both Kamailio Services remain ClusterIP-only (`8080/TCP`, `5060/UDP`),
with no NodePort, LoadBalancer, ExternalIP, HostPort or HostNetwork.

## State and Workload Preservation

| Value | Before | After |
|---|---|---|
| database public tables | 41 | **41** |
| tables containing `dialog`/`rtp`/`media` | (none) | **(none)** |
| tenants | 27 | **27** |
| RuntimeNodes / families | 110 / asterisk + simulator | **110 / unchanged** |
| pending outbox | 0 | **0** |
| Redis keys `sip`/`dialog`/`rtp`/`media` | 0/0/0/0 | **0/0/0/0** |
| rtpengine sessions / ports used | 0 / 0 | **0 / 0** |

Redis `db0` moved `2 → 5` from authorized API session and cache activity.

Pod snapshot diff:

```text
- utcp-platform kamailio-86cdf8c446-g6chj  7047b84c-…  restarts 1
+ utcp-platform kamailio-778d98849b-n4jvg  b46444a1-…  restarts 1   <- expected rollout
  utcp-platform worker-55fdb7d5f6-jg2x5    dcfb533b-…  restarts 54 -> 55
```

The `worker` entry is **not** a Pod replacement — the UID is unchanged and the
container exited `exitCode 0, reason Completed` after exactly one hour
(`startedAt 06:19:25Z`, `finishedAt 07:19:27Z`). That is the Laravel queue
worker's ordinary hourly recycle, it has been incrementing steadily across the
whole T3-S2A arc, and its log shows only routine
`RuntimeReconciliationOperationalStateChanged` broadcasts. Classified
`EXPECTED_BEHAVIOR`; unrelated to Kamailio signaling. Asterisk and rtpengine
retained their UID **and** restart count.

## Findings

| Classification | Finding |
|---|---|
| PASS | **`PRODUCT_DEFECT-12` is closed** — the lowercase `ws`/`wss` guard executes and `add_contact_alias()` runs; zero uppercase comparisons remain in the running configuration |
| PASS | **`PRODUCT_DEFECT-11` is closed** — exactly one `;alias=10.42.0.150~36196~5` reaches Asterisk on the forwarded `Contact`, Asterisk retains the alias-bearing remote target, and the Asterisk-originated BYE traverses `loose_route()` → `handle_ruri_alias()` → non-empty `$du` → the existing browser WebSocket connection |
| PASS | The browser received the BYE at `07:21:53Z` with matching Call-ID, tags and CSeq, answered `200 OK`, and that response was captured returning through Kamailio to Asterisk carrying the browser's own `User-Agent` — with `0` BYE retransmissions, no transaction timeout, no Kamailio-generated error, and the channel terminating with no manual cleanup |
| PASS | **`PRODUCT_DEFECT-13` is closed** — a missing alias now yields `400` + `missing_dialog_contact_alias` and a malformed alias yields `400` + `invalid_dialog_contact_alias`, both with no DNS query, no relay, no fallback and zero datagrams toward Asterisk |
| PASS | No `.invalid` DNS query, `sip_hostport2su()` failure, `uri2dst2()` failure, `t_forward_nonack()` failure or `478 Unresolvable destination` occurred anywhere in this proof |
| PASS | Only the two intended resources were applied; the checksum-coupled Deployment produced a fully automatic ~4-second rollout to ReplicaSet revision 16 with no manual restart; running configuration is byte-identical across all four authorities |
| PASS | REGISTER unchanged with no alias in registrar storage; the `503` failure-route contract intact; rtpengine uninvolved; Services ClusterIP-only; no durable dialog authority; no canonical state mutation |
| **PRODUCT_DEFECT-14** | The `$du` postcondition applies to every in-dialog request, so requests toward Asterisk — which legitimately carry no alias — are rejected `400` with `missing_dialog_contact_alias`. ACK continuity (`post_ack_200_retransmissions` `0` → `3`) and client-originated BYE (`200 OK` → `400 Bad Request`) are both regressed. Blocking |
| EXPECTED_BEHAVIOR | The corrected Kamailio Pod restarted once on the known transient `postgres … Connection refused` new-Pod-IP versus NetworkPolicy programming race; self-recovered, zero ERROR lines |
| EXPECTED_BEHAVIOR | `worker-55fdb7d5f6-jg2x5` restart `54 → 55` is the hourly Laravel queue-worker recycle (`exitCode 0`, `reason Completed`, same Pod UID), pre-existing across the whole arc and unrelated to signaling |
| EXPECTED_BEHAVIOR | Redis `db0` `2 → 5` from authorized API activity; signaling credentials carry a bounded ~5-minute TTL so each corridor used a freshly issued credential |
| PROOF_LIMITATION | The Asterisk-originated BYE was ultimately triggered by Asterisk's ACK timeout (`Reason: cause=408`) rather than purely by the CLI stimulus, because `PRODUCT_DEFECT-14` blocked the ACK. The BYE was still a correctly formed alias-bearing in-dialog request that exercised the complete corridor, so the alias claims stand; a clean CLI-triggered hangup should be re-confirmed after the correction |
| PROOF_LIMITATION | `res_pjsip_logger` remains outside the committed `autoload=no` module set and was not loaded; Asterisk-side evidence is the bounded packet capture, channel table and counters |
| PROOF_LIMITATION | CANCEL still has no deterministic pre-answer window — the `9900` fixture answers immediately and was not altered |

## Environment Preservation

```text
production code changed:        no
Kubernetes manifests changed:   no
images built or pushed:         none
resources applied:              2 (kamailio ConfigMap, kamailio Deployment)
workloads rolled:               1 (kamailio, automatically via checksum coupling)
unrelated workloads restarted:  none (the worker recycle is its own hourly lifecycle)
packet captures:                2 bounded runs in the Kamailio k3d node network namespace,
                                each filtered to a single proof Call-ID pattern with
                                Authorization redacted; both stopped and deleted at cleanup
cluster security posture:       unchanged — captures ran as throwaway Docker containers in the
                                node namespace, not Kubernetes workloads; no PSA exception,
                                no privileged Pod, no proof-only NetworkPolicy
canonical records mutated:      none beyond authorized API proof data
```

## Cleanup

- Corrected Kamailio left Ready (`kamailio-778d98849b-n4jvg`); Asterisk and rtpengine left Ready; both corrected resources left applied.
- The proof contact was deregistered through the canonical client and the telephony session ended through the authorized API.
- Both packet captures stopped, their containers removed, and the capture files deleted. No port-forward was used.
- Disposable dialog client, client-BYE regression client, synthetic alias probe, sniffer, credential helpers, cookie jar, secret file, traces and rendered scratch manifests removed; none was added to the repository or the cluster.
- No ephemeral Kubernetes diagnostic container was created. `.playwright-mcp/` is absent.
- No credential, digest response or Authorization header content was printed or recorded; the client trace and both captures redact `Authorization`, `Proxy-Authorization`, `WWW-Authenticate` and `Proxy-Authenticate`.

## T3-S2A Final Status After the WebSocket Alias Closure Proof

```text
PRODUCT_DEFECT-5  = closed        PRODUCT_DEFECT-10 = closed
PRODUCT_DEFECT-6  = closed        PRODUCT_DEFECT-11 = closed
PRODUCT_DEFECT-7  = closed        PRODUCT_DEFECT-12 = closed
PRODUCT_DEFECT-8  = closed        PRODUCT_DEFECT-13 = closed
PRODUCT_DEFECT-9  = closed        PRODUCT_DEFECT-14 = open (alias postcondition rejects
                                                            Asterisk-directed in-dialog requests)
T3-S2A repository implementation = Complete
T3-S2A live signaling proof      = INCOMPLETE
T3-S2A                           = In Progress
T3-S2 media mediation            = Not Started
T3                               = In Progress
UTCP_PHASE                       = T1 (unchanged)
```

## Recommended Next Step

Bounded Codex correction of `PRODUCT_DEFECT-14`: guard the alias block on the
presence of an `alias` URI parameter so the postcondition applies only to
browser-directed in-dialog requests, with `config-check` and `config-check-test`
coverage for both the unconditional-postcondition and dropped-postcondition
mutations. Then reproof only ACK continuity, client-originated BYE, and one clean
CLI-triggered Asterisk-originated BYE. Everything else in this section — alias
creation, alias retention, alias consumption, browser receipt and response,
response return, missing-alias and malformed-alias failure, and DNS-fallback
elimination — is proven and must not be repeated.

Do not add fallback routing, registration aliasing, Path, Outbound, GRUU, public
SIP exposure, feature gates, manual activation, rtpengine mediation, browser
media, conference admission, V0, T4, external trunks, or PSTN.
