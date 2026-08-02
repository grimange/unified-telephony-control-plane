# T3-S2C FreeSWITCH Live Parity Proof — Parity Overlay Blocker

Date: 2026-08-02

Starting commit: `0f3b168` (`fix(t3): correct freeswitch runtime startup`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2C_FREESWITCH_LIVE_PARITY_INCOMPLETE`

## Summary

The FreeSWITCH runtime corrections in `0f3b168` are **good**:
`PRODUCT_DEFECT-17`, `-18`, and `-19` are all closed at the image level. The
image builds, starts under the exact committed entrypoint, parses the official
XML, loads the required modules, brings the `utcp-internal` Sofia profile to
`RUNNING`, and passes the committed executable health check — all confirmed by
`make freeswitch-startup-smoke-test`.

The proof is nevertheless `INCOMPLETE`, blocked one step later at the
**Kubernetes parity overlay**. `infrastructure/kubernetes/overlays/local-freeswitch`
includes `../../base/platform` and `../../base/runtime` directly but carries only
the `utcp-freeswitch` image mapping. It omits every other image transformation
that the canonical `overlays/local` provides, so applying it would rewrite the
whole platform to unresolvable base placeholders — **including Kamailio and
rtpengine, the two components this proof depends on**.

The overlay was therefore not applied and no parity scenario ran. Production was
left untouched; the full-cluster Pod diff is empty.

## Repository Baseline

```text
HEAD           0f3b168 (branch main), working tree clean
UTCP_PHASE     T1
make freeswitch-config-check            pass
make freeswitch-config-check-test       pass
make freeswitch-startup-smoke-test      pass
make media-config-check / -test         pass
make kamailio-signaling-config-check / -test   pass
make security-config-check / -test      pass
make t3-media-prover-config-check / -test      pass
```

## Runtime Baseline

```text
kamailio      kamailio-56b99d4b57-kldt4       uid 41dfde71-…  restarts 4   Ready
              image ghcr.io/kamailio/kamailio:5.8.6-bookworm
rtpengine     rtpengine-74cd786966-8vhff      uid 0fbd6b20-…  restarts 2   Ready
              image utcp-local-registry:5000/utcp/rtpengine:0.1.0-k1-dev
asterisk      asterisk-ari-676b58b676-dzfm4   uid 6e3b5c64-…  restarts 0   Ready
              replicas 1/1, 0 active channels, 6 calls processed
secondary     asterisk-ari-b-8557bd4d76-rcjfn uid 8a904cdd-…  restarts 15  Ready
FreeSWITCH resources               none
Service/application-runtime-sip    not found
asterisk-sip EndpointSlice         10.42.1.254:5060  (Asterisk only)
policy generations   allow-kamailio-signaling-required-traffic 6,
                     allow-asterisk-sip-from-kamailio 4, allow-rtpengine-media 2
rtpengine sessions own/foreign     0 / 0
rtpengine ports_used / ports_free  0 / 200
database   tables 41, tenants 27, RuntimeNodes 110 (asterisk, simulator),
           pending outbox 0
redis      keys sip/dialog/rtp/media 0/0/0/0
live Kamailio relay                ASTERISK_RELAY -> asterisk-sip
```

Required initial state satisfied: selected runtime is Asterisk at its committed
replica count, no FreeSWITCH resources exist, rtpengine sessions and allocations
are zero, and active channels are zero.

## FreeSWITCH Image Build and Digest

`make image-build-freeswitch` succeeded against the pinned base.

```text
source commit       0f3b168035123609e4a66311d09cb9f6b0b3cda7
base image          docker.io/safarov/freeswitch:1.10.12
                    @sha256:b31c743f4c911a19687c61e3214968f2a24f93f9d3d667cc26284192e158ffc6
local image ID      e46205af1898
registry tag        utcp-local-registry:5000/utcp/freeswitch:0.1.0-t3-s2c
registry digest     sha256:e46205af189886b33a81d02c47b849d73fb79a2a0f8c641685f7316cccc4645d
build timestamp     2026-08-02 10:47:22 +0800
runtime identity    uid=1000 gid=1000 groups=1000
configuration digest sha256 1009fddac88323b0…  (freeswitch.xml + autoload_configs
                    + sip_profiles + dialplan)
```

The image was published to the local registry. It was **not deployed**, because
the overlay that would deploy it is defective. No diagnostic or alternate image
was built; local build tags were removed at cleanup.

## Runtime Corrections Confirmed Closed

`make freeswitch-startup-smoke-test` exercises the real image with the exact
committed entrypoint and reports:

```text
utcp-internal  profile  sip:mod_sofia@172.17.0.3:5060  RUNNING (0)
FreeSWITCH startup smoke test passed
```

| Defect | Correction in `0f3b168` | State |
|---|---|---|
| `PRODUCT_DEFECT-17` | entrypoint now runs `freeswitch -nonat -c -conf /etc/freeswitch -log /var/log/freeswitch -db /var/lib/freeswitch/db -run /var/run/freeswitch`, supplying `-conf`, `-log`, and `-db` together | **closed** |
| `PRODUCT_DEFECT-18` | `freeswitch.xml` is now `<document type="freeswitch/xml">` with `configuration`, `dialplan`, `chatplan`, `directory`, and `languages` sections; `autoload_configs/` supplies `modules.conf`, `switch.conf` (`rtp-start-port 21000` / `rtp-end-port 21099`), `sofia.conf`, and `event_socket.conf`; the profile is `sip_profiles/utcp-internal.xml` and the fixture is `dialplan/utcp.xml` | **closed** |
| `PRODUCT_DEFECT-19` | entrypoint creates and asserts writability of `/var/log/freeswitch`, `/var/run/freeswitch`, `/var/lib/freeswitch/db`; the Deployment adds `fsGroup: 1000` | **closed** |
| `PRODUCT_DEFECT-20` | probes replaced with the executable `/usr/local/bin/utcp-freeswitch-healthcheck` (startup, readiness, liveness) asserting `status` `UP` and `utcp-internal … RUNNING` over loopback `fs_cli`, instead of a `tcpSocket` probe against a UDP-declared port | **closed at image level; unverified in Kubernetes** |

The Dockerfile additionally asserts each required module `.so` exists at build
time, and the Event Socket binds `127.0.0.1:8021` with
`apply-inbound-acl loopback.auto` and `stop-on-bind-error true`.

## PRODUCT_DEFECT-21 — Parity Overlay Reverts Every Platform Image

`infrastructure/kubernetes/overlays/local-freeswitch/kustomization.yaml`:

```yaml
resources:
  - ../../base/platform
  - ../../base/runtime
  - ../../components/freeswitch-instance
images:
  - name: utcp-freeswitch
    newName: utcp-local-registry:5000/utcp/freeswitch
    newTag: 0.1.0-t3-s2c
```

The canonical `overlays/local` maps eight images (`postgres`, `redis`,
`utcp-api`, `utcp-web`, `utcp-gateway`, `utcp-asterisk-ari`, `utcp-rtpengine`,
`utcp-kamailio`) and generates the application ConfigMap plus the data, Kamailio
DB, and Reverb credential Secrets. Its `runtime` sub-overlay additionally
generates the Asterisk ARI credentials Secret and the
`asterisk-local-sip-fixtures` ConfigMap that carries the Asterisk `9900` fixture,
and maps the Asterisk image.

`local-freeswitch` re-includes the same bases while providing **none** of that.
`kubectl diff -k` against the live cluster reports **22 changed objects**,
including 15 unrelated platform Deployments, with these image reversions:

```text
11 x  utcp-local-registry:5000/utcp/api:0.1.0-k1-dev        ->  utcp-api
 1 x  ghcr.io/kamailio/kamailio:5.8.6-bookworm              ->  utcp-kamailio
 1 x  utcp-local-registry:5000/utcp/rtpengine:0.1.0-k1-dev  ->  utcp-rtpengine
 1 x  utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev -> utcp-asterisk-ari
```

`utcp-api`, `utcp-kamailio`, `utcp-rtpengine`, and `utcp-asterisk-ari` are base
placeholder names with no registry, tag, or digest — they are unresolvable in the
cluster. Applying the overlay would send the API, web, gateway, Reverb,
scheduler, worker, the three telephony workers, the outbox dispatcher, the
simulator event source, the ARI event bridge, **Kamailio**, **rtpengine**, and
Asterisk into `ImagePullBackOff`.

Kamailio and rtpengine are exactly the components the parity proof exercises, so
the overlay destroys the proof's own preconditions in the act of being applied.

Failed seam: **runtime selection** (the overlay that performs selection is not
bounded).

The overlay was therefore not applied. This is not a workaround: the blocker is
established deterministically from `kubectl diff` against the live cluster, which
is stronger and safer evidence than an induced platform-wide outage.

## Smallest Bounded Correction

Rebase the parity overlay on the canonical local overlay instead of duplicating
the bases:

```yaml
resources:
  - ../local
  - ../../components/freeswitch-instance
patches:
  - path: asterisk-disabled.yaml
  - path: selected-runtime-service.yaml
images:
  - name: utcp-freeswitch
    newName: utcp-local-registry:5000/utcp/freeswitch
    newTag: 0.1.0-t3-s2c
```

That inherits every image mapping, ConfigMap, and Secret generator, and reduces
the parity delta to exactly the intended set: the FreeSWITCH Deployment and
Service, the `application-runtime-sip` selector narrowing, `asterisk-ari`
`replicas: 0`, the ESL Secret, and the generic Kamailio relay ConfigMap.

Add a `freeswitch-config-check` guard asserting that `kubectl kustomize` of the
parity overlay resolves **no** bare `utcp-*` image name — that single assertion
would have caught this.

## Resources Applied

```text
none
```

No FreeSWITCH Deployment, Service, Secret, or NetworkPolicy; no proof namespace,
Job, Secret, or ConfigMap. The Kamailio generic-relay ConfigMap was not applied
and Kamailio was not rolled.

## Overlay Review (static, retained for the retry)

Apart from `PRODUCT_DEFECT-21`, the parity design remains sound:

```text
Deployment/utcp-runtime/freeswitch
   runAsNonRoot, uid/gid 1000, fsGroup 1000, caps drop ALL,
   readOnlyRootFilesystem, seccomp RuntimeDefault, no service-account token
   ports sip UDP 5060, rtp UDP 21000
   envFrom secretRef utcp-local-freeswitch-esl-credentials
   startup/readiness/liveness = exec /usr/local/bin/utcp-freeswitch-healthcheck
   emptyDir at /var/lib/freeswitch, /var/run/freeswitch, /var/log/freeswitch
Service/utcp-runtime/freeswitch-sip           ClusterIP UDP 5060
Service/utcp-runtime/application-runtime-sip  ClusterIP UDP 5060, selector
   narrowed to component=freeswitch + runtime-node=local-freeswitch-esl +
   runtime-selection=selected-application-runtime
Deployment/utcp-runtime/asterisk-ari          replicas 0
Secret/utcp-runtime/utcp-local-freeswitch-esl-credentials  (generated)
NetworkPolicy allow-freeswitch-sip-from-kamailio (security kustomization line 27)
   ingress UDP 5060 from kamailio-signaling
   ingress UDP 21000-21099 from rtpengine-media
   egress  UDP 5060 to kamailio-signaling
   egress  UDP 40000-40099 to rtpengine-media
   egress  UDP/TCP 53 to kube-dns
```

The rendered overlay contains no NodePort, LoadBalancer, HostPort, HostNetwork,
`ipBlock`, public SIP, public ESL, public RTP, TCP `8021` Service or policy,
all-UDP or all-egress rule, dual runtime selector, Asterisk fallback selector,
duplicated media route, or prover modification. Kamailio's generic
`APPLICATION_RUNTIME_RELAY` / `APPLICATION_RUNTIME_UNAVAILABLE` routes carry no
provider-specific duplicate.

## Not Executed

```text
Kubernetes security context and writable paths (in-cluster)
FreeSWITCH startup and health in Kubernetes
Sofia profile and SIP listener in Kubernetes
runtime-selection projection and generic Service endpoints
Asterisk scale-to-zero
Playwright MCP natural login
Scenario A (selection, SIP dialog, SDP, ICE/DTLS, RTP/Echo, audio energy, BYE)
Scenario B (readiness marker, RTP/Echo, hangup stimulus, FreeSWITCH BYE)
local FreeSWITCH hangup stimulus
selected-runtime unavailable behavior
Asterisk zero-fallback runtime counters
default runtime restoration (never departed from)
```

`.playwright-mcp/` was never created and is absent. No hangup stimulus was
issued. No ephemeral credential was created.

## Provider-Neutrality Assessment

Unchallenged by this run. The committed prover was not modified and not
executed; `make t3-media-prover-config-check` passes. The FreeSWITCH adapter adds
no provider-specific Kamailio route and no browser-side FreeSWITCH knowledge.

```text
provider-neutral signaling and media contract:  proven against Asterisk only
runtime agnosticism:                            not yet proven
```

## Containment Preservation

Nothing was applied, so live containment is unchanged. The committed overlay and
policies also introduce no public SIP, ESL, media, NodePort, LoadBalancer,
HostPort, HostNetwork, `ipBlock`, or host route, and expose no TCP `8021`.

## State and Workload Preservation

Full-cluster Pod snapshot diff between baseline and final is **empty** — every
workload retained its UID and restart count and all are Ready.

```text
FreeSWITCH resources             none (unchanged)
Service/application-runtime-sip  still absent
live images                      kamailio ghcr.io/kamailio/kamailio:5.8.6-bookworm
                                 rtpengine …/utcp/rtpengine:0.1.0-k1-dev
                                 api …/utcp/api:0.1.0-k1-dev      (all unchanged)
live Kamailio relay              ASTERISK_RELAY -> asterisk-sip (unchanged)
asterisk-ari                     replicas 1, ready 1 (unchanged)
database public tables           41
tenants                          27
RuntimeNodes                     110 (asterisk, simulator)
pending outbox                   0
Redis sip/dialog/rtp/media       0/0/0/0
rtpengine sessions / ports_used  0 / 0
Asterisk active channels         0 (6 calls processed)
policy generations               unchanged
```

## Findings

| Classification | Finding |
|---|---|
| `PASS` | **`PRODUCT_DEFECT-17` closed** — the entrypoint uses `-c -conf … -log … -db … -run …` and FreeSWITCH starts |
| `PASS` | **`PRODUCT_DEFECT-18` closed** — the configuration is now the official `<document type="freeswitch/xml">` schema with `autoload_configs`, and the parse succeeds |
| `PASS` | **`PRODUCT_DEFECT-19` closed** — the entrypoint creates and asserts writable `/var/log`, `/var/run`, `/var/lib/.../db`, and the Deployment adds `fsGroup: 1000` |
| `PASS` | **`PRODUCT_DEFECT-20` closed at image level** — probes are now the executable loopback `fs_cli` health check; unverified inside Kubernetes because the Pod was never created |
| `PASS` | `make freeswitch-startup-smoke-test` runs the real image with the committed entrypoint and observes `utcp-internal … RUNNING (0)` on UDP `5060` |
| `PASS` | Image builds and publishes from `0f3b168` as `sha256:e46205af1898…`, uid/gid `1000:1000` |
| `PASS` | Every committed static check passes, including the new startup smoke test |
| `PASS` | The parity overlay's FreeSWITCH-specific content and NetworkPolicies are correctly shaped and exactly scoped, with no public exposure and no `8021` surface |
| `PASS` | No production workload, image, policy, Service, or configuration changed; the full-cluster Pod diff is empty and Asterisk remains the selected runtime |
| **`PRODUCT_DEFECT-21`** | `overlays/local-freeswitch` re-includes `base/platform` and `base/runtime` while mapping only `utcp-freeswitch`, omitting the canonical `overlays/local` image transformations and generators. `kubectl diff` shows 22 changed objects and 14 image reversions to unresolvable base placeholders — `utcp-api` ×11, `utcp-kamailio`, `utcp-rtpengine`, `utcp-asterisk-ari` — so applying it would break the entire platform including Kamailio and rtpengine. Seam: **runtime selection** |
| `PROOF_HARNESS_DEFECT` | None. The committed prover was not the limiting factor |
| `PROOF_POLICY_DEFECT` | None. The FreeSWITCH NetworkPolicies are correctly reciprocal against `21000-21099` and `40000-40099` |
| `PROOF_LIMITATION` | No committed check renders the parity overlay and asserts that all images resolve, which is why the full static suite passes against an overlay that would break the platform |
| `EXPECTED_BEHAVIOR` | The overlay was not applied; the blocker is proven from `kubectl diff` against the live cluster rather than by inducing a platform-wide outage |
| `EXPECTED_BEHAVIOR` | `local-freeswitch-esl-credentials.properties` carries a synthetic, clearly labelled local-development ESL password. `make secret-scan` passes, ESL binds `127.0.0.1:8021` with `loopback.auto` ACL, and no Service or policy exposes `8021` |

## Cleanup

```text
proof Jobs / Pods / Secrets / ConfigMaps    none created
proof NetworkPolicies / namespace           none created
FreeSWITCH cluster resources                none applied
local FreeSWITCH build tags                 removed
published registry image                    retained at
                                            sha256:e46205af1898…, valid for 0f3b168
node-cached FreeSWITCH images               none (purged before push, never pulled)
browser profiles / NSS databases            none created
captures, traces, scratch results           none retained
temporary Helm v4.0.3                       provisioned, verified, removed
.playwright-mcp/                            absent
credential material                         none created or remaining
```

Kamailio Ready, rtpengine Ready, Asterisk Ready and selected at `replicas 1`,
secondary runtime unchanged, zero rtpengine sessions and allocations, zero active
channels.

## Verification Performed

```text
git status / git log -20 / grep UTCP_PHASE versions.env
make freeswitch-config-check                      pass
make freeswitch-config-check-test                 pass
make freeswitch-startup-smoke-test                pass
make media-config-check / -test                   pass
make kamailio-signaling-config-check / -test      pass
make security-config-check / -test                pass
make t3-media-prover-config-check / -test         pass
make repository-hygiene                           pass
make workflow-check                               pass
make secret-scan                                  pass
make k8s-config-check                             pass
make check                                        pass
make gateway-config-check                         pass (pinned Helm v4.0.3, removed)
node tools/t3-media-prover/sip-dialog-test.mjs    pass
make image-build-freeswitch                       pass
kubectl diff -k overlays/local-freeswitch         22 changed objects,
                                                  14 image reversions (blocker)
git diff --check / git diff --cached --check      clean
```

## Status

```text
PRODUCT_DEFECT-17  = closed
PRODUCT_DEFECT-18  = closed
PRODUCT_DEFECT-19  = closed
PRODUCT_DEFECT-20  = closed at image level, unverified in Kubernetes
PRODUCT_DEFECT-21  = open (parity overlay reverts platform images)

T3-S2A                                   = Complete
T3-S2B                                   = Complete
T3-S2C FreeSWITCH parity                 = INCOMPLETE

provider-neutral signaling and media contract = proven against Asterisk only
runtime agnosticism                           = not yet proven
T3-S2 overall                                 = In Progress
T3-S3 external media edge                     = Not Started
T3                                            = In Progress
UTCP_PHASE=T1
```

External browser media readiness is not claimed.

## Recommended Next Step

Bounded implementation: rebase `overlays/local-freeswitch` on `../local` instead
of re-including `base/platform` and `base/runtime`, and add a
`freeswitch-config-check` guard asserting the rendered parity overlay resolves no
bare `utcp-*` image name. Then re-run this parity proof unchanged.
