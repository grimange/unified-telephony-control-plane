# T3-S2C Baseline Reconciliation And FreeSWITCH Auth Blocker

Date: 2026-08-02

Starting commit: `60bf137` (`fix(t3): preserve local parity overlay composition`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2C_FREESWITCH_LIVE_PARITY_INCOMPLETE`

## Summary

`ENVIRONMENT_DRIFT-1` is **reconciled**. The running cluster was fully classified
against `overlays/local`, converged to it, and `kubectl diff -k
infrastructure/kubernetes/overlays/local` now exits `0` with zero changed
objects — before the parity run and again after restoration.

`PRODUCT_DEFECT-21` is **closed**: with the overlay rebased on `../local`, the
FreeSWITCH parity diff contains only its bounded five-object delta.

FreeSWITCH itself deploys correctly: the published digest runs, the Pod is Ready
on its committed exec health probes, the `utcp-internal` Sofia profile is
`RUNNING` on UDP `5060`, runtime directories are writable as uid `1000`, the
Event Socket is loopback-only, and `application-runtime-sip` resolves to the
FreeSWITCH Pod alone while Asterisk sits at zero replicas.

The proof is nevertheless `INCOMPLETE`. A new `PRODUCT_DEFECT-22` blocks the call:
**FreeSWITCH answers the proxy-relayed INVITE with `407 Proxy Authentication
Required`** even though the committed profile sets `auth-calls=false`. Kamailio
has no credentials to offer a downstream runtime, so it ACKs the `407`, runs
`MEDIA_DELETE`, and the browser never receives a `200 OK`.

## Repository Baseline

```text
HEAD           60bf137 (branch main), working tree clean
UTCP_PHASE     T1
make freeswitch-config-check / -test        pass
make freeswitch-overlay-check / -test       pass
make freeswitch-startup-smoke-test          pass
make media-config-check                     pass
make kamailio-signaling-config-check        pass
make security-config-check                  pass
make t3-media-prover-config-check           pass
```

## ENVIRONMENT_DRIFT-1 — Classification

`kubectl diff -k overlays/local` reported **5 changed objects, 151 lines**.

| # | Resource | Field | Live | Canonical | Class | Restart | Authority |
|---|---|---|---|---|---|---|---|
| 1 | `ConfigMap/utcp-platform/kamailio-config` | relay routes | `ASTERISK_RELAY` → `asterisk-sip` | `APPLICATION_RUNTIME_RELAY` → `application-runtime-sip` | `STALE_CLUSTER_RESOURCE` | via #2 | repository |
| 2 | `Deployment/utcp-platform/kamailio` | `utcp.io/kamailio-config-sha256`, gen `18→19` | `0d788689…` | `9cb5e1f1…` | `STALE_CLUSTER_RESOURCE` | yes, by design (checksum coupling) | repository |
| 3 | `Deployment/utcp-runtime/asterisk-ari` | pod label `utcp.dev/runtime-selection`, gen `16→17` | absent | `selected-application-runtime` | `STALE_CLUSTER_RESOURCE` | yes | repository |
| 4 | `Service/utcp-runtime/application-runtime-sip` | whole object | absent | present | `STALE_CLUSTER_RESOURCE` | no | repository |
| 5 | `ConfigMap/utcp-platform/utcp-application-config` | `APP_URL`, `BROADCAST_CONNECTION`, 7 `REVERB_*` keys | `http://gateway:8080`, `log`, keys absent | `https://app.utcp.local.test`, `reverb`, keys present | `STALE_CLUSTER_RESOURCE` | no | repository |

Items 1–4 all originate from `e294ac7` (the generic selected-runtime relay),
committed but never applied. Item 5 predates the committed Reverb configuration
(`ff3444f`).

Two live-only workloads exist that `kubectl diff` cannot report, both committed
in other overlays and untouched by `apply` (no pruning):

| Resource | Origin | Class |
|---|---|---|
| `Deployment/utcp-platform/utcp-runtime-fence-worker` | `components/runtime-fencing` | `PREVIOUS_PROOF_RESIDUE` (T5) |
| `Deployment/utcp-runtime/asterisk-ari-b` | `overlays/local-two-asterisk` | `PREVIOUS_PROOF_RESIDUE` (T5) |

`asterisk-ari-b` carries no `utcp.dev/runtime-selection` label, so it is never an
endpoint of the generic Service — verified from its live Pod labels.

No item was `UNAUTHORIZED_MANUAL_DRIFT`, `CANONICAL_OVERLAY_DEFECT`, or
`UNCLEAR_AUTHORITY`.

### Safety determination

The `BROADCAST_CONNECTION` change (item 5) is the historically dangerous one —
memory records a nine-workload crash-loop when the shared ConfigMap moved to
`reverb` without the Reverb secret. Reconciliation is safe because the UI-D17
correction is committed and present in the render: every PHP workload lacking the
Reverb `secretRef` pins `BROADCAST_CONNECTION=log` explicitly
(`asterisk-ari-events`, `kamailio-registration-observer`, `scheduler`,
`simulator-event-source`, the three telephony workers, and `utcp-migrate`), while
every workload inheriting `reverb` has the `secretRef` (`api`,
`control-plane-outbox-dispatcher`, `reverb`, `worker`).

`utcp-migrate` already exists with `status.succeeded=1` and identical spec
(`BROADCAST_CONNECTION=log`), so it did not appear in the diff and the apply was a
no-op for it. No seeder or destructive job is present.

## Reconciliation Result

```text
kubectl apply -k infrastructure/kubernetes/overlays/local
  configmap/kamailio-config configured
  configmap/utcp-application-config configured
  secret/utcp-local-reverb-credentials configured
  service/application-runtime-sip created
  deployment.apps/kamailio configured
  deployment.apps/asterisk-ari configured
  statefulset.apps/postgres configured
  statefulset.apps/redis configured
```

Both expected rollouts completed cleanly. Post-reconciliation:

```text
kubectl diff -k overlays/local          exit 0, zero changed objects
all pods                                Ready
application-runtime-sip endpoints       10.42.1.8 (asterisk-ari), Ready, Asterisk only
kamailio live config                    APPLICATION_RUNTIME_RELAY x2, ASTERISK_RELAY x0
rtpengine sessions / ports_used         0 / 0
active channels                         0
```

`ENVIRONMENT_DRIFT-1 = reconciled`.

## FreeSWITCH Parity Delta

`kubectl diff -k overlays/local-freeswitch` — exactly five objects, no unrelated
image, ConfigMap, Deployment, Service, or policy drift:

```text
Deployment/utcp-runtime/asterisk-ari                    replicas 1 -> 0
Deployment/utcp-runtime/freeswitch                      new
Secret/utcp-runtime/utcp-local-freeswitch-esl-credentials  new (value redacted)
Service/utcp-runtime/application-runtime-sip            selector += component=freeswitch,
                                                        runtime-node=local-freeswitch-esl
Service/utcp-runtime/freeswitch-sip                     new
```

`kamailio-config` is absent from the delta because the generic relay is now part
of the reconciled canonical baseline. `PRODUCT_DEFECT-21 = closed`.

## Additional Reconciliation — Security Policies

The first Scenario A attempt failed with
`kamailio_application_dialog_rejected result=application_runtime_unavailable`
and FreeSWITCH logged no session: `e294ac7` also amended two existing policies
that had never been applied. `kubectl diff -k infrastructure/kubernetes/security`
showed the material gaps:

```text
allow-kamailio-signaling-required-traffic   + egress UDP 5060 to freeswitch
                                            + ingress from freeswitch
allow-rtpengine-media                       + egress UDP 21000-21099 to freeswitch
                                            + ingress from freeswitch
```

Applying the committed security kustomization closed both (verified live). Nine
other policies showed generation-only bumps with no spec change —
`EXPECTED_CONTROLLER_METADATA`.

## FreeSWITCH Image And Kubernetes Runtime

```text
source commit    60bf137111213cb412a49907e3cf99250fd6b426
registry tag     utcp-local-registry:5000/utcp/freeswitch:0.1.0-t3-s2c
registry digest  sha256:83c562fac3c9acd315fc623fe8f419a62ed811acb919b317d93081f4c3fed880
Pod              freeswitch-77fbf94d74-vgtqk on k3d-utcp-local-server-0
Pod imageID      …/utcp/freeswitch@sha256:83c562fac3c9acd315fc623fe8f419a62ed811acb919b317d93081f4c3fed880
restarts         0        Ready True
identity         uid=1000 gid=1000 groups=1000
writable         /var/log/freeswitch yes, /var/run/freeswitch yes, /var/lib/freeswitch/db yes
status           UP … FreeSWITCH (Version 1.10.12) is ready
sofia            utcp-internal  sip:mod_sofia@10.42.1.9:5060  RUNNING (0)
profile          Context utcp, RTP-IP 10.42.1.9, SIP-IP 10.42.1.9,
                 BIND-URL sip:mod_sofia@10.42.1.9:5060;transport=udp,
                 CODECS IN/OUT PCMU, DTMF rfc2833
event socket     listen-ip 127.0.0.1, listen-port 8021,
                 apply-inbound-acl loopback.auto
ESL exposure     0 Services and 0 NetworkPolicies reference 8021
```

The runtime digest matches the published digest exactly. Startup and readiness
exec probes passed on first attempt with zero restarts, confirming
`PRODUCT_DEFECT-20` closed in Kubernetes as well.

## Runtime-Selection Projection

```text
application-runtime-sip EndpointSlice   10.42.1.9  Ready=true   (freeswitch Pod)
asterisk-ari                            replicas 0, ready <none>
asterisk-ari-b                          replicas 1 but no runtime-selection label -> not an endpoint
freeswitch                              replicas 1, ready 1
```

FreeSWITCH was the sole selected runtime.

## PRODUCT_DEFECT-22 — FreeSWITCH Challenges The Proxy-Relayed INVITE With 407

Scenario A was run three times with the unchanged committed prover and runner.
All three failed identically:

```text
T3_MEDIA_PROVER_RESULT_JSON={"scenario":"browser-originated-bye",
  "errors":["page.evaluate: Error: timed out waiting for SIP message"],
  "durationMs":46430}
job_terminal_state=failed pod_phase=Failed pod_exit_code=1
```

Kamailio's own flow shows the call reaching the relay and then failing:

```text
kamailio_application_dialog_challenge result=challenge  method=INVITE call_id=51be46a8b30768@…
kamailio_application_dialog_media     result=media_offer method=INVITE call_id=51be46a8b30768@…
kamailio_application_dialog_media     result=media_delete method=INVITE call_id=51be46a8b30768@…
```

A bounded packet capture in the FreeSWITCH node network namespace, filtered to
UDP `5060`, gives the decisive wire evidence:

```text
10.42.2.176:5060 -> 10.42.1.9:5060 | INVITE sip:9900@sip.utcp.local.test | CSeq: 2 INVITE
10.42.2.176:5060 -> 10.42.1.9:5060 | INVITE sip:9900@sip.utcp.local.test | CSeq: 2 INVITE
10.42.2.176:5060 -> 10.42.1.9:5060 | INVITE sip:9900@sip.utcp.local.test | CSeq: 2 INVITE
10.42.1.9:5060 -> 10.42.2.176:5060 | SIP/2.0 407 Proxy Authentication Required | CSeq: 2 INVITE
10.42.1.9:5060 -> 10.42.2.176:5060 | SIP/2.0 407 Proxy Authentication Required | CSeq: 2 INVITE
10.42.1.9:5060 -> 10.42.2.176:5060 | SIP/2.0 407 Proxy Authentication Required | CSeq: 2 INVITE
10.42.2.176:5060 -> 10.42.1.9:5060 | ACK sip:9900@sip.utcp.local.test | CSeq: 2 ACK
10.42.2.176:5060 -> 10.42.1.9:5060 | ACK sip:9900@sip.utcp.local.test | CSeq: 2 ACK
10.42.2.176:5060 -> 10.42.1.9:5060 | ACK sip:9900@sip.utcp.local.test | CSeq: 2 ACK
```

`10.42.2.176` is the Kamailio Pod; `10.42.1.9` is the FreeSWITCH Pod. The
corridor works — the INVITE arrives and FreeSWITCH answers — so this is not a
NetworkPolicy or routing failure. FreeSWITCH counters agree: `CALLS-IN 2`,
`FAILED-CALLS-IN 4`, `1 session(s) since startup, peak 1`.

The committed profile intends no challenge, and the value is parsed correctly by
the running instance:

```text
xml_locate configuration configuration name sofia.conf
    <profile name="utcp-internal">
            <param name="context" value="utcp"></param>
            <param name="auth-calls" value="false"></param>
```

So `auth-calls=false` is live and FreeSWITCH still challenges. Kamailio is a
proxy relaying an INVITE it has already authenticated with its own subscriber
digest; it holds no downstream credentials, so it can only ACK the `407`. The
committed `failure_route[APPLICATION_RUNTIME_UNAVAILABLE]` then runs
`MEDIA_DELETE` and the browser, which waits only for a `200 OK`, times out.

Failed seam: **SDP offer / INVITE routing — the selected runtime rejects the
proxy-relayed INVITE with `407`.**

`utcp-internal` is missing the trust configuration that makes a Sofia profile
accept calls from a known proxy. The committed profile declares
`<domains><domain name="all" alias="true" parse="false"/></domains>` and no
`apply-inbound-acl`, whereas the stock internal profile pairs
`apply-inbound-acl` with `domain name="all" alias="false" parse="true"`. The
correction must make the profile accept the Kamailio-relayed INVITE without a
challenge and be verified on the wire, not merely by the profile reaching
`RUNNING`.

## Why Every Committed Check Still Passes

`make freeswitch-startup-smoke-test` asserts that FreeSWITCH starts, parses its
XML, loads modules, and brings `utcp-internal` to `RUNNING`. It does **not**
drive a call. `freeswitch-config-check` and `freeswitch-overlay-check` validate
file content and manifest shape. Nothing in the committed suite exercises an
inbound INVITE, which is exactly why a profile that answers every call with `407`
passes the entire suite.

Classification: `PROOF_LIMITATION`.

## Smallest Bounded Correction

1. Make the `utcp-internal` profile accept the proxy-relayed INVITE without a
   challenge — for example pair `apply-inbound-acl` with a bounded ACL covering
   the Kamailio signalling identity, and align the `<domains>` declaration with a
   non-aliased parse so unregistered on-net users are not implied. `auth-calls`
   alone is demonstrably insufficient in this configuration.
2. Extend `freeswitch-startup-smoke-test` (or add a bounded companion) so it
   sends one INVITE to `9900` from an untrusted-by-default source and asserts a
   `200 OK` with SDP rather than a `4xx`. That single assertion converts this
   class of defect from a live-proof discovery into a build-time failure.

## Not Executed

```text
Scenario A media assertions (ICE, DTLS, RTP, Echo, audio energy, BYE)
Scenario B (readiness marker, RTP/Echo, fs_cli hangup, FreeSWITCH BYE)
selected-runtime unavailable behavior
```

All depend on a successful INVITE. No `fs_cli` hangup stimulus was issued; the
Event Socket was used only for read-only `status`, `sofia status`, and
`xml_locate` queries plus one reversible `console loglevel` toggle that was
restored.

## Asterisk Zero-Fallback Evidence

Throughout the parity window:

```text
asterisk-ari replicas                 0 (ready <none>)
application-runtime-sip endpoints     10.42.1.9 only (FreeSWITCH)
Asterisk INVITEs received             0 — Asterisk had no running Pod
Asterisk active channels              0
Asterisk media packets                0
```

Kamailio's single branch targeted the generic Service, which resolved only to
FreeSWITCH. No fallback branch and no dual delivery occurred; the failure surfaced
as the canonical runtime-unavailable path rather than a silent reroute.

## Default Runtime Restoration

```text
kubectl apply -k overlays/local              (canonical authority, not manual patching)
freeswitch Deployment / Service / Secret     deleted (absent from the default overlay)
asterisk-ari rollout                         complete
application-runtime-sip endpoints            10.42.1.14 (asterisk-ari), Ready, Asterisk only
asterisk-ari                                 replicas 1, ready 1
kubectl diff -k overlays/local               exit 0, zero drift
all pods                                     Ready
```

The FreeSWITCH NetworkPolicies and the Kamailio/rtpengine FreeSWITCH corridor
rules remain applied because they are part of the committed security
kustomization and are inert with no FreeSWITCH Pod present.

## State And Workload Preservation

Workload delta versus the pre-reconciliation baseline is exactly the two intended
reconciliation replacements:

```text
kamailio-56b99d4b57-kldt4    -> kamailio-68998b758d-qns2m       (config checksum)
asterisk-ari-676b58b676-…    -> asterisk-ari-54f778cb7b-8fclp   (selection label)
```

Every other workload retained its UID and restart count.

```text
database public tables   41        tenants 27        RuntimeNodes 110
pending outbox           0         Redis sip/dialog/rtp/media 0/0/0/0
rtpengine sessions       0 / 0     ports_used 0
Asterisk active channels 0         FreeSWITCH active channels n/a (removed)
```

## Findings

| Classification | Finding |
|---|---|
| `PASS` | `ENVIRONMENT_DRIFT-1` fully classified: 5 diff objects plus 2 live-only workloads, all `STALE_CLUSTER_RESOURCE` or `PREVIOUS_PROOF_RESIDUE`, repository authoritative throughout |
| `PASS` | Reconciliation to `overlays/local` succeeded; canonical diff exits `0` with zero changed objects, before parity and again after restoration |
| `PASS` | `BROADCAST_CONNECTION` reconciliation is safe because the committed UI-D17 per-workload `log` pins and Reverb `secretRef`s are present; `utcp-migrate` was a no-op |
| `PASS` | **`PRODUCT_DEFECT-21` closed** — the parity diff is exactly five objects with no unrelated drift |
| `PASS` | FreeSWITCH Pod runs the published digest `sha256:83c562fac3c9…`, uid/gid `1000:1000`, zero restarts, Ready on committed exec probes; `utcp-internal` `RUNNING` on UDP `5060`; runtime dirs writable; ESL loopback-only with no Service or policy on `8021` |
| `PASS` | `application-runtime-sip` resolved to FreeSWITCH alone with Asterisk at zero replicas; `asterisk-ari-b` correctly excluded |
| `PASS` | Independent Playwright MCP natural login succeeded from the real login page and the session was invalidated on logout |
| `PASS` | Zero Asterisk fallback: no Asterisk Pod existed during parity, one branch, no dual delivery |
| `PASS` | Default Asterisk runtime restored through the canonical overlay with final zero drift |
| **`PRODUCT_DEFECT-22`** | FreeSWITCH answers the Kamailio-relayed INVITE with `407 Proxy Authentication Required` despite a live, correctly parsed `auth-calls=false`. Proven on the wire (3× INVITE, 3× `407`, 3× ACK between `10.42.2.176` and `10.42.1.9`) with `CALLS-IN 2 / FAILED-CALLS-IN 4`. Kamailio has no downstream credentials, so `MEDIA_DELETE` runs and the browser never receives `200 OK`. Seam: **SDP offer / INVITE routing** |
| `PROOF_HARNESS_DEFECT` | None. The committed prover was unchanged and is not the limiting factor |
| `PROOF_POLICY_DEFECT` | None. Once the committed security kustomization was applied, the Kamailio→FreeSWITCH and rtpengine↔FreeSWITCH corridors worked; the INVITE reached FreeSWITCH and was answered |
| `PROOF_LIMITATION` | No committed check drives an inbound INVITE, so a profile that rejects every call still passes `freeswitch-config-check`, `freeswitch-overlay-check`, and `freeswitch-startup-smoke-test` |
| `EXPECTED_CONTROLLER_METADATA` | Nine security NetworkPolicies show generation-only bumps on repeated dry-run apply with no spec change |
| `PREVIOUS_PROOF_RESIDUE` | `utcp-runtime-fence-worker` and `asterisk-ari-b` are live-only T5 artefacts committed in other overlays; neither is selected by the generic Service and neither was modified |

## Cleanup

```text
proof Jobs / Pods / namespace / proof-only NetworkPolicies   removed by the committed runner
FreeSWITCH Deployment / Service / ESL Secret                 deleted
FreeSWITCH local and registry image tags                     removed; node caches purged
structured-result scratch (.runtime/t3-media-prover)         removed
browser profiles / NSS databases / captures / traces         none retained
temporary Helm v4.0.3                                        provisioned, verified, removed
.playwright-mcp/                                             absent
credential material                                          none remaining
```

Retained deliberately: the committed FreeSWITCH NetworkPolicies and the
Kamailio/rtpengine FreeSWITCH corridor rules, which are canonical members of the
security kustomization and inert without a FreeSWITCH Pod.

## Verification Performed

```text
make freeswitch-config-check / -test              pass
make freeswitch-overlay-check / -test             pass
make freeswitch-startup-smoke-test                pass
make media-config-check                           pass
make kamailio-signaling-config-check              pass
make security-config-check                        pass
make t3-media-prover-config-check                 pass
make repository-hygiene / workflow-check / secret-scan / k8s-config-check   pass
make check                                        pass
make gateway-config-check                         pass (pinned Helm v4.0.3, removed)
node tools/t3-media-prover/sip-dialog-test.mjs    pass
kubectl diff -k overlays/local                    exit 0 (before parity and after restore)
kubectl diff -k overlays/local-freeswitch         exactly the bounded 5-object delta
git diff --check / git diff --cached --check      clean
```

## Status

```text
ENVIRONMENT_DRIFT-1   = reconciled
PRODUCT_DEFECT-17..21 = closed
PRODUCT_DEFECT-22     = open (FreeSWITCH 407 on proxy-relayed INVITE)

T3-S2A                        = Complete
T3-S2B                        = Complete
T3-S2C FreeSWITCH parity      = INCOMPLETE

provider-neutral signaling and media contract = proven against Asterisk only
runtime agnosticism                           = not yet proven
T3-S2 overall                                 = In Progress
T3-S3 external media edge                     = Not Started
T3                                            = In Progress
UTCP_PHASE=T1
```

External browser media readiness is not claimed.

## Recommended Next Step

Bounded implementation: make the `utcp-internal` Sofia profile accept the
Kamailio-relayed INVITE without challenging it, and extend the FreeSWITCH smoke
test to assert a `200 OK` with SDP for an inbound INVITE to `9900`. Then re-run
this parity proof unchanged — the baseline is now zero-drift, so the run can
begin directly at the parity overlay.
