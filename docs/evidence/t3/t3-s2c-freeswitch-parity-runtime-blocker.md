# T3-S2C FreeSWITCH Live Parity Proof — Runtime Startup Blocker

Date: 2026-08-02

Starting commit: `e294ac7` (`feat(t3): add freeswitch runtime parity adapter`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2C_FREESWITCH_LIVE_PARITY_INCOMPLETE`

## Summary

The committed FreeSWITCH parity image builds, but **FreeSWITCH cannot start**.
Two independent defects were proven deterministically at the image level, before
any cluster resource was applied:

1. The committed entrypoint invokes `freeswitch -c <file>`. In FreeSWITCH, `-c`
   means "output to a console and stay in the foreground" and takes **no
   argument**; the config *directory* flag is `-conf`. The stray path is rejected
   and the process exits `1` immediately.
2. With the flag corrected, the committed `freeswitch.xml` fails to parse:
   `Cannot Initialize [[error near line 7]: markup outside of root element]`. The
   file uses `<include>` with `<settings>`, `<modules>`, `<profiles>`, and
   `<dialplans>` elements, which is not the FreeSWITCH configuration schema
   (`<document type="freeswitch/xml">` with `<section>` children). No modules
   load, no SIP profile binds, and nothing listens.

A third, latent defect was also confirmed: the container runs as uid `1000` but
none of the runtime directories the Deployment mounts are writable by it.

Because the selected runtime provably cannot serve a single INVITE, the parity
overlay was **not applied**. Applying it would have scaled Asterisk to zero and
repointed Kamailio's generic relay at an endpointless
`application-runtime-sip` Service while FreeSWITCH crash-looped, leaving the
local environment with no working application runtime and producing no proof
value. Production was left untouched.

## Repository Baseline

```text
HEAD           e294ac7 (branch main), working tree clean
UTCP_PHASE     T1
make freeswitch-config-check / -test             pass
make media-config-check / -test                  pass
make kamailio-signaling-config-check / -test     pass
make security-config-check / -test               pass
make t3-media-prover-config-check / -test        pass
```

Every committed static check passes. The defects are runtime-only: nothing in
the static suite executes the entrypoint or parses the FreeSWITCH XML.

## Runtime Baseline

```text
kamailio      kamailio-56b99d4b57-kldt4       uid 41dfde71-…  restarts 4   Ready
rtpengine     rtpengine-74cd786966-8vhff      uid 0fbd6b20-…  restarts 2   Ready
asterisk      asterisk-ari-676b58b676-dzfm4   uid 6e3b5c64-…  restarts 0   Ready
              replicas 1/1, 0 active channels, 6 calls processed
secondary     asterisk-ari-b-8557bd4d76-rcjfn uid 8a904cdd-…  restarts 15  Ready
FreeSWITCH resources                 none present
Service/application-runtime-sip      not found (base not yet applied)
asterisk-sip EndpointSlice           10.42.1.254:5060  (Asterisk only)
policy generations   allow-kamailio-signaling-required-traffic 6,
                     allow-asterisk-sip-from-kamailio 4, allow-rtpengine-media 2
rtpengine sessions own/foreign       0 / 0
rtpengine ports_used / ports_free    0 / 200
database      tables 41, tenants 27, RuntimeNodes 110 (asterisk, simulator),
              pending outbox 0
redis         keys sip/dialog/rtp/media 0/0/0/0
live Kamailio relay                  ASTERISK_RELAY -> asterisk-sip (unchanged)
```

Required baseline satisfied: selected application runtime is Asterisk,
`application-runtime-sip` endpoints are Asterisk-only (via the pre-existing
`asterisk-sip` Service), FreeSWITCH is not selected, rtpengine sessions and
allocations are zero.

## FreeSWITCH Image Build and Digest

`make image-build-freeswitch` succeeded using the repository-pinned base.

```text
source commit      e294ac7f0dfefd7eea00c41c09ebd505a2bae933
base image         docker.io/safarov/freeswitch:1.10.12
                   @sha256:b31c743f4c911a19687c61e3214968f2a24f93f9d3d667cc26284192e158ffc6
local image ID     3e8c169eb3a0
build timestamp    2026-08-02 09:33:42 +0800
runtime identity   uid=1000 gid=1000 groups=1000, HOME=/
freeswitch binary  /usr/bin/freeswitch
freeswitch version 1.10.12-release (64bit)
config paths       /etc/freeswitch/{freeswitch.xml, sip_profiles/, dialplan/}
```

The image was **not** published to the local registry and **not** deployed,
because the runtime is non-functional. No registry tag or digest exists for this
build. No diagnostic image was created; the local build tag was removed during
cleanup.

## PRODUCT_DEFECT-17 — Entrypoint Uses `-c <file>` Instead Of `-conf <dir>`

Committed entrypoint (`infrastructure/docker/freeswitch/entrypoint`):

```sh
exec /usr/bin/freeswitch -nonat -nf -c /etc/freeswitch/freeswitch.xml
```

Running the committed entrypoint unmodified:

```text
Unknown option '/etc/freeswitch/freeswitch.xml',
see '/usr/bin/freeswitch -help' for a list of valid options
exit=1
```

FreeSWITCH's own help output settles the semantics:

```text
-nf                    -- no forking
-nonat                 -- disable auto nat detection
-c                     -- output to a console and stay in the foreground
-conf [confdir]        -- alternate directory for FreeSWITCH configuration files
-log  [logdir]         -- alternate directory for logfiles
-run  [rundir]         -- alternate directory for runtime files
-db   [dbdir]          -- alternate directory for the internal database
```

`-c` takes no argument, so the config path becomes a stray positional argument
and the process exits immediately. The Pod would never reach Ready.

A further constraint applies to the correction:

```text
$ freeswitch -nonat -nf -c -conf /etc/freeswitch
You must specify all or none of -conf, -log, and -db
```

`-conf` cannot be used alone; `-log` and `-db` must accompany it, and `-run` is
needed for the PID file.

Failed seam: **SIP listener** (the runtime never starts).

## PRODUCT_DEFECT-18 — Committed `freeswitch.xml` Is Not The FreeSWITCH Schema

With the flag corrected and all directory flags supplied against writable paths,
the parser fails:

```text
Cannot Initialize [[error near line 7]: markup outside of root element]
[INFO] switch_event.c:714 Activate Eventing Engine.
--- listening sockets ---
(none)
```

Line 7 is the first `<param>` inside `<settings>`, immediately after the three
`X-PRE-PROCESS` directives.

Committed file (`infrastructure/docker/freeswitch/config/freeswitch.xml`):

```xml
<include>
  <X-PRE-PROCESS cmd="set" data="ip=0.0.0.0"/>
  ...
  <settings>...</settings>
  <modules>...</modules>
  <profiles><profile name="internal" file="sip_profiles/internal.xml"/></profiles>
  <dialplans><dialplan name="default" file="dialplan/default.xml"/></dialplans>
</include>
```

The canonical schema, from the base image's own reference config
(`/usr/share/freeswitch/conf/vanilla/freeswitch.xml`):

```xml
<?xml version="1.0"?>
<document type="freeswitch/xml">
  <section name="configuration">
    <configuration name="modules.conf" .../>
    <configuration name="sofia.conf" .../>
  </section>
  <section name="dialplan">...</section>
</document>
```

`<settings>`, `<modules>`, `<profiles>`, and `<dialplan name= file=>` are not
valid top-level FreeSWITCH elements, and mod_sofia reads profiles from
`sofia.conf.xml` inside the `configuration` section rather than a `<profiles>`
list. Consequently no module loads, no SIP profile binds UDP `5060`, the `9900`
echo extension is never registered, and the RTP range `21000-21099` is never
applied.

Failed seam: **SIP listener**, and by consequence **SDP offer/answer**, **RTP
range**, and **Echo**.

## PRODUCT_DEFECT-19 — Runtime Directories Are Not Writable By uid 1000

The Dockerfile sets `USER 1000:1000`, but inside the built image:

```text
id                    uid=1000 gid=1000 groups=1000
/var/lib/freeswitch   exists owner=995:0 perms=755 writable=NO
/var/log/freeswitch   MISSING
/var/run/freeswitch   MISSING
```

The Deployment mounts `emptyDir` volumes at all three paths and sets
`runAsUser: 1000` / `runAsGroup: 1000` with `readOnlyRootFilesystem: true` but
**no `fsGroup`**, so those volumes are created `root:root` and remain unwritable
by uid `1000`. FreeSWITCH needs to write its internal database, log files, and
PID file in exactly those locations.

This defect is latent behind the first two but would block startup immediately
after they are fixed.

Failed seam: **SIP listener** (startup).

## Resources Applied

```text
none
```

The parity overlay was rendered and reviewed but deliberately **not** applied.
Applying it would have:

* scaled `asterisk-ari` to `0` replicas (`asterisk-disabled.yaml`),
* rolled Kamailio onto the new ConfigMap whose `APPLICATION_RUNTIME_RELAY`
  targets `application-runtime-sip.utcp-runtime.svc.cluster.local:5060`, and
* pointed that Service exclusively at FreeSWITCH Pods
  (`selected-runtime-service.yaml`),

while the FreeSWITCH Deployment crash-looped — leaving the local environment
with zero Ready application-runtime endpoints and no working telephony runtime,
for no proof value. The blocker is already proven deterministically at the image
level, which is stronger and cheaper evidence than a CrashLoopBackOff.

## Overlay Review (static)

The committed overlay is otherwise correctly shaped. Recorded for the next
attempt:

```text
Deployment/utcp-runtime/freeswitch          replicas 1, component=freeswitch,
                                            runtime-node=local-freeswitch-esl,
                                            runtime-selection=selected-application-runtime
                                            ports sip UDP 5060, rtp UDP 21000
                                            runAsNonRoot, uid/gid 1000, caps drop ALL,
                                            readOnlyRootFilesystem, RuntimeDefault
Service/utcp-runtime/freeswitch-sip         ClusterIP, UDP 5060
Service/utcp-runtime/application-runtime-sip ClusterIP, UDP 5060,
                                            selector narrowed by the overlay to
                                            component=freeswitch +
                                            runtime-node=local-freeswitch-esl +
                                            runtime-selection=selected-application-runtime
Deployment/utcp-runtime/asterisk-ari        replicas 0
NetworkPolicy allow-freeswitch-sip-from-kamailio (utcp-runtime)
   ingress  UDP 5060 from kamailio-signaling
   ingress  UDP 21000-21099 from rtpengine-media
   egress   UDP 5060 to kamailio-signaling
   egress   UDP 40000-40099 to rtpengine-media
   egress   UDP/TCP 53 to kube-dns
```

No public SIP Service, no ESL Service, no public media, no NodePort,
LoadBalancer, HostPort, HostNetwork, `ipBlock`, all-UDP or all-egress rule, no
dual runtime selector, no Asterisk fallback selector, no Kamailio media-route
duplication, and no browser-prover modification. The media policies are properly
reciprocal against rtpengine's `40000-40099` and FreeSWITCH's `21000-21099`.

Kamailio's generic route names (`APPLICATION_RUNTIME_RELAY`,
`APPLICATION_RUNTIME_UNAVAILABLE`, `MEDIA_OFFER`, `MEDIA_ANSWER`,
`MEDIA_DELETE`, `APPLICATION_RUNTIME_MEDIA_REPLY`) replace the Asterisk-specific
names in place, with no FreeSWITCH-specific duplicate route. That part of the
design is sound and unaffected by the defects above.

## Not Executed

Because the runtime cannot start, none of the following were performed:

```text
FreeSWITCH startup and configuration verification
runtime-selection projection and generic Service endpoints
Asterisk scale-to-zero
Playwright MCP natural login
Scenario A (selection, SIP dialog, SDP, ICE/DTLS, RTP/Echo, audio energy, BYE)
Scenario B (readiness marker, RTP/Echo, hangup stimulus, FreeSWITCH BYE)
selected-runtime unavailable behavior
Asterisk zero-fallback runtime counters
default runtime restoration
```

No proof namespace, Job, Secret, ConfigMap, or proof NetworkPolicy was created.
No hangup stimulus was issued. The `.playwright-mcp/` directory was never
created and is absent.

## Smallest Bounded Correction

1. `infrastructure/docker/freeswitch/entrypoint` — replace
   `-c /etc/freeswitch/freeswitch.xml` with `-c -conf /etc/freeswitch -log <dir>
   -db <dir> -run <dir>`, supplying all of `-conf`, `-log`, `-db` together.
2. `infrastructure/docker/freeswitch/config/freeswitch.xml` — rewrite to the
   FreeSWITCH schema: `<document type="freeswitch/xml">` with a
   `<section name="configuration">` containing `modules.conf` and `sofia.conf`
   (the `internal` profile with `sip-port 5060`, `rtp-ip`/`sip-ip`, and the
   `21000-21099` range in `switch.conf`), plus a `<section name="dialplan">`
   carrying the `9900` answer/echo/hangup extension.
3. `infrastructure/kubernetes/components/freeswitch-instance/freeswitch-deployment.yaml`
   — add `fsGroup: 1000` to the Pod `securityContext` so the mounted `emptyDir`
   volumes are group-writable by the runtime uid, and point `-log`/`-db`/`-run`
   at those mounts.
4. `scripts/freeswitch/config-check` — add guards that would have caught all
   three: reject `-c` immediately followed by a path, require `-conf` to appear
   with `-log` and `-db`, require the config root element to be
   `<document type="freeswitch/xml">`, and require `fsGroup` when
   `readOnlyRootFilesystem` is combined with writable `emptyDir` mounts. A
   build-time smoke step that starts FreeSWITCH and asserts a bound SIP socket
   would catch this whole class.

Additionally worth verifying once startup succeeds: the Deployment's readiness
and liveness probes use `tcpSocket` against the container port named `sip`,
which is declared `UDP`. Whether that probe succeeds depends on mod_sofia also
binding TCP `5060`; it was unverifiable here because nothing ever listened.

## Provider-Neutrality Assessment

Unaffected and unchallenged by this run. The committed browser prover was not
modified and not executed. `make t3-media-prover-config-check` passes, which
rejects provider-specific browser authority. Kamailio's generic route rename
introduces no FreeSWITCH-specific duplicate route.

```text
provider-neutral signaling and media contract:  proven against Asterisk only
runtime agnosticism:                            not yet proven
```

## Containment Preservation

No cluster resource was applied, so containment is trivially unchanged: no
public SIP, no public ESL, no public media, no NodePort, LoadBalancer, HostPort,
HostNetwork, `ipBlock`, or host route. The committed overlay contains none of
these either.

## State and Workload Preservation

Full-cluster Pod snapshot diff between baseline and final is **empty** — every
workload retained its UID and restart count and all are Ready.

```text
FreeSWITCH resources                 none (unchanged)
Service/application-runtime-sip      still absent
FreeSWITCH NetworkPolicies           none
live Kamailio relay                  ASTERISK_RELAY -> asterisk-sip (unchanged)
asterisk-ari                         replicas 1, ready 1 (unchanged)
database public tables               41
tenants                              27
RuntimeNodes                         110 (asterisk, simulator)
pending outbox                       0
Redis sip/dialog/rtp/media           0/0/0/0
rtpengine sessions / ports_used      0 / 0
Asterisk active channels             0 (6 calls processed)
policy generations                   unchanged
```

## Findings

| Classification | Finding |
|---|---|
| `PASS` | The committed image builds from `e294ac7` against the pinned base and runs as uid/gid `1000:1000` with FreeSWITCH `1.10.12` present |
| `PASS` | The committed overlay is correctly shaped: single selected-runtime Service narrowed to FreeSWITCH, Asterisk to zero, reciprocal SIP and media policies against `21000-21099` / `40000-40099`, no public SIP/ESL/media, no NodePort, LoadBalancer, HostPort, HostNetwork, `ipBlock`, dual selector, or Asterisk fallback |
| `PASS` | Kamailio's generic `APPLICATION_RUNTIME_RELAY` / `APPLICATION_RUNTIME_UNAVAILABLE` rename is in place with no FreeSWITCH-specific duplicate route and no media-route duplication |
| `PASS` | No production workload, policy, Service, or configuration changed; the full-cluster Pod diff is empty and Asterisk remains the selected runtime |
| **`PRODUCT_DEFECT-17`** | Entrypoint invokes `freeswitch -c <file>`; `-c` takes no argument, so the config path is a stray option and the process exits `1` with `Unknown option`. Seam: **SIP listener**. `-conf` also requires `-log` and `-db` |
| **`PRODUCT_DEFECT-18`** | `freeswitch.xml` is not the FreeSWITCH schema — `<include>` with `<settings>`/`<modules>`/`<profiles>`/`<dialplans>` instead of `<document type="freeswitch/xml">` with `<section>` children. Parser fails `markup outside of root element` at line 7; no modules, no SIP profile, no listener, no `9900` fixture, no RTP range. Seam: **SIP listener**, consequently **SDP offer**, **SDP answer**, **RTP range**, **Echo** |
| **`PRODUCT_DEFECT-19`** | The container runs as uid `1000`, but `/var/lib/freeswitch` is owned `995:0` mode `755` and `/var/log/freeswitch` and `/var/run/freeswitch` do not exist; the Deployment mounts `emptyDir` at all three with no `fsGroup`, so they are `root:root` and unwritable. Latent behind `-17` and `-18`. Seam: **SIP listener** |
| `PROOF_HARNESS_DEFECT` | None. The committed prover was not modified and was not the limiting factor |
| `PROOF_POLICY_DEFECT` | None. The committed FreeSWITCH NetworkPolicies are correctly reciprocal and exactly scoped |
| `PROOF_LIMITATION` | The static suite passes in full because no committed check executes the entrypoint or parses the FreeSWITCH XML. `freeswitch-config-check` validates file presence and manifest shape only |
| `EXPECTED_BEHAVIOR` | The parity overlay was not applied. With FreeSWITCH unable to start, applying it would scale Asterisk to zero and repoint Kamailio at an endpointless Service, degrading the environment without adding evidence |

## Cleanup

```text
proof Jobs / Pods / Secrets / ConfigMaps    none created
proof NetworkPolicies / namespace           none created
FreeSWITCH cluster resources                none applied
local FreeSWITCH image tag                  utcp-freeswitch:dev removed
registry tag / published digest             none created
diagnostic image                            none built
browser profiles / NSS databases            none created
captures, traces, scratch results           none retained
temporary Helm v4.0.3                       provisioned, verified, removed
.playwright-mcp/                            absent
credential material                         none created or remaining
```

Kamailio Ready, rtpengine Ready, Asterisk Ready and selected at replicas `1`,
secondary runtime unchanged, zero rtpengine sessions and allocations, zero
active channels.

## Verification Performed

```text
git status / git log -20 / grep UTCP_PHASE versions.env
make freeswitch-config-check / -test              pass
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
git diff --check / git diff --cached --check      clean
make image-build-freeswitch                       pass (image builds)
committed entrypoint execution                    FAIL (exit 1, Unknown option)
committed freeswitch.xml parse                    FAIL (markup outside of root element)
```

## Status

```text
T3-S2A                                   = Complete
T3-S2B                                   = Complete
T3-S2C FreeSWITCH parity                 = INCOMPLETE (runtime cannot start)

PRODUCT_DEFECT-17 entrypoint flag        = open
PRODUCT_DEFECT-18 freeswitch.xml schema  = open
PRODUCT_DEFECT-19 runtime dir ownership  = open

provider-neutral signaling and media contract = proven against Asterisk only
runtime agnosticism                           = not yet proven
T3-S2 overall                                 = In Progress
T3-S3 external media edge                     = Not Started
T3                                            = In Progress
UTCP_PHASE=T1
```

External browser media readiness is not claimed.

## Recommended Next Step

Bounded implementation of the four corrections above — entrypoint flags,
FreeSWITCH XML schema, `fsGroup` plus matching `-log`/`-db`/`-run` paths, and
`freeswitch-config-check` guards including a build-time startup smoke test — then
re-run this parity proof unchanged.
