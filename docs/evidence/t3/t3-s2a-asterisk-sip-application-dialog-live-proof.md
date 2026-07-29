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
