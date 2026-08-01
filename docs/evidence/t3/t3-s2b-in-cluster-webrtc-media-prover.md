# T3-S2B In-Cluster WebRTC Media Prover

Date: 2026-08-01

Starting commit: `72716a3` (`docs(t3): record media mediation live proof`)

Phase marker: `UTCP_PHASE=T1`

Kubernetes apply: not performed.

## Scope

This repository-only slice adds a reusable, local-only, one-shot WebRTC media
prover for the contained T3 media core. The prover runs inside the Kubernetes
Pod network so a real browser runtime can reach the internal rtpengine media
candidate without exposing rtpengine publicly or adding host routes.

The prover is proof infrastructure only. It is not a production service, media
authority, signaling authority, credential authority, public media gateway, or
runtime-specific test client.

## Prior Live Evidence

The T3-S2 live proof established the provider-neutral SIP/SDP media lifecycle:

- SDP offer mediation: `PASS`
- SDP answer mediation: `PASS`
- Browser-originated BYE delete: `PASS`
- Runtime-originated BYE delete: `PASS`
- Terminal runtime-failure cleanup: `PASS`
- rtpengine-unavailable fail-closed: `PASS`

The remaining gap was actual ICE, DTLS-SRTP, RTP, and echo proof. The host
browser could not route to the contained rtpengine Pod candidate because T3-S1
intentionally kept rtpengine control and media internal. That is not a Kamailio
or rtpengine product defect; it is a proof-topology limitation.

## Proof Topology

The prover lives under:

```text
tools/t3-media-prover/
infrastructure/kubernetes/overlays/local/proof/t3-media/
scripts/t3-media-prover/
```

The local overlay renders only proof resources:

- `Namespace/utcp-proof`
- `Job/utcp-proof/t3-media-prover`
- proof namespace default-deny NetworkPolicy
- exact prover egress NetworkPolicy
- exact reciprocal rtpengine media NetworkPolicy

The production/base renders contain no prover workload or `utcp-proof`
namespace. No production NetworkPolicy is widened.

## Browser Runtime And Pin

The prover image is built from the pinned Playwright browser image:

```text
mcr.microsoft.com/playwright:v1.61.1-noble
```

`versions.env` records the pinned browser image and Playwright package version:

```text
T3_MEDIA_PROVER_PLAYWRIGHT_VERSION=1.61.1
T3_MEDIA_PROVER_BROWSER_IMAGE=mcr.microsoft.com/playwright:v1.61.1-noble
```

The prover uses real Chromium/WebRTC through Playwright. It does not use a
mocked `RTCPeerConnection`, an SDP-only SIP generator, or a packet-counter
fixture.

## Canonical Origin Resolution

The browser continues to use the canonical local origins:

```text
https://app.utcp.local.test
wss://sip.utcp.local.test/ws
```

At execution time the prover resolves the internal Traefik Service DNS name and
starts Chromium with process-local host resolver rules mapping the canonical
hostnames to that internal endpoint. This preserves the canonical URL, SNI,
Host header, origin, cookie behavior, and certificate identity while avoiding
production CoreDNS edits, host routes, host networking, Service ClusterIP
literals, or Service-DNS browser URLs.

## Authentication Path

Credentials are supplied only at execution time through an external Kubernetes
Secret created by the runner. No credentials are committed.

The browser performs natural login through the real application origin before
starting SIP/WebRTC proof logic. It does not preset cookies, manually write
Redis sessions, manually insert database sessions, connect directly to
Asterisk, or bypass subscriber digest authentication.

## Deterministic Audio

The browser creates a real `RTCPeerConnection` and deterministic audio source.
It configures Chromium with fake media-device permission and fake media devices,
then drives a Web Audio tone for stable outbound media. The prover requires
real WebRTC stats, RTP counters, and received audio-energy evidence before
reporting success.

WebRTC encryption remains enabled.

## Structured Result Contract

The prover emits one structured JSON result and exits with:

```text
0      every required assertion passed
non-0  at least one required assertion failed
```

The result records:

- scenario
- Call-ID correlation
- ICE connection and gathering state
- selected candidate pair
- local and remote candidate address classes
- DTLS or peer-connection state
- outbound RTP packets and bytes
- inbound RTP packets and bytes
- audio energy or equivalent received-audio evidence
- BYE direction
- final SIP result
- cleanup result
- duration

The result excludes passwords, Authorization headers, session cookies, ICE
passwords, DTLS private material, and unredacted SDP.

SDP acceptance alone is rejected as success.

## Scenario A: Browser-Originated BYE

The browser-originated scenario performs:

```text
natural login
-> SIP-over-WSS call to extension 9900
-> SDP offer/answer
-> ICE selected or completed
-> DTLS connected
-> deterministic audio sent
-> echo audio received
-> browser sends BYE
-> 200 OK
-> rtpengine session deleted
```

All media assertions are browser/WebRTC assertions and remain independent of the
current application runtime brand.

## Scenario B: Runtime-Originated BYE

The runtime-originated scenario performs:

```text
natural login
-> media-bearing call to extension 9900
-> ICE/DTLS/media proven
-> channel remains active
-> external proof controller triggers bounded runtime hangup
-> browser receives BYE
-> browser returns 200 OK
-> rtpengine session deleted
```

The browser prover does not use Asterisk CLI, ARI, AMI, channel identifiers, Pod
IPs, or dialplan state as media authority. While Asterisk is the current
reference runtime, a live proof controller may use the existing Asterisk CLI
only as the bounded Scenario B hangup stimulus.

## Proof-Only NetworkPolicies

Default-deny remains active in the proof namespace.

The prover egress policy allows only:

- prover to cluster DNS, UDP/TCP `53`
- prover to canonical Traefik/Gateway workload, TCP `443`
- prover to rtpengine media workload, UDP `40000-40099`

The reciprocal rtpengine policy allows only:

- rtpengine media workload from/to the exact prover identity, UDP `40000-40099`

Selectors combine namespace and Pod identity. The policies contain no
`ipBlock`, Pod-CIDR allowance, namespace-wide media allowance, direct Asterisk
media access, rtpengine control-port access, public ingress, or production-base
policy widening.

## Security Context

The one-shot Job uses:

- `restartPolicy: Never`
- `backoffLimit: 0`
- bounded `activeDeadlineSeconds`
- bounded `ttlSecondsAfterFinished`
- `runAsNonRoot`
- `allowPrivilegeEscalation: false`
- all Linux capabilities dropped
- `seccompProfile: RuntimeDefault`
- `readOnlyRootFilesystem: true`
- no privileged mode
- no host networking, host PID, host IPC, HostPort, HostPath, or Docker socket

Writable `emptyDir` volumes are limited to browser runtime storage and the
proof result directory.

## Containment Boundary

T3-S1 containment is preserved. The implementation adds none of:

```text
NodePort
LoadBalancer
ExternalIP
HostPort
HostNetwork
Gateway UDPRoute
public media Service
k3d UDP port publication
host route
node route
developer-host media route
public rtpengine advertised address
```

The rtpengine media candidate remains internal. The in-cluster browser reaches
it through the Pod network.

## Static Validation

`scripts/t3-media-prover/config-check` validates:

- prover resources render only in the local proof overlay
- the workload is one-shot and bounded
- the browser image and Playwright package are pinned
- no credentials are embedded
- no public exposure or host networking exists
- privileged execution is absent
- proof NetworkPolicies use exact selectors
- media access is only UDP `40000-40099`
- DNS access is only UDP/TCP `53`
- application/signaling access is only TCP `443`
- no direct Asterisk media access is granted
- no provider-specific media authority appears in the browser prover
- structured result assertions include real RTP counters and audio evidence
- SDP-only success is rejected
- cleanup is mandatory
- execution timeout is bounded
- the proof workload is absent from production/base renders

`make check` includes the prover static and mutation checks.

## Mutation Coverage

`scripts/t3-media-prover/config-check-test` covers:

- correct proof overlay passing
- moving the Job into production base
- NodePort, LoadBalancer, HostPort, hostNetwork, privileged mode
- Pod-CIDR or `0.0.0.0/0` `ipBlock`
- widened rtpengine media ingress
- unrestricted prover egress
- missing UDP media access
- direct Asterisk media access
- mutable browser tags
- embedded credentials
- missing WebRTC packet assertions
- treating SDP acceptance as success
- missing cleanup
- missing execution timeout
- provider-specific media authority markers
- public media advertisement

The existing media, security, Kamailio signaling, rtpengine, public-surface, and
T3-S2A dialog checks remain active.

## Execution Tooling

`make t3-media-prover-run` wraps the local proof runner. The runner:

1. validates the expected local kubeconfig and context
2. runs the prover static checker
3. requires credentials through environment variables
4. creates an external, temporary Secret
5. applies only the local proof overlay
6. waits for bounded Job completion
7. retrieves the structured result
8. propagates the Job exit status
9. deletes proof resources and credentials
10. confirms no residual proof Pods remain

The command does not modify production Kamailio, rtpengine, or Asterisk
resources.

## Runtime Neutrality

The browser prover accepts runtime-neutral inputs such as application URL, WSS
URL, SIP domain, extension, scenario and timeouts. It does not accept or depend
on Asterisk channel IDs, Asterisk Pod IPs, ARI, AMI, FreeSWITCH ESL, dialplan
context, provider-specific SDP expectations, or a durable media-session table.

A bounded live controller may discover and hang up the current reference
runtime channel for Scenario B, but the WebRTC media assertions remain reusable
for a later FreeSWITCH parity slice.

## Proof Versus Production

```text
in-cluster media proof:
  validates provider-neutral mediation core

external browser media reachability:
  not proven by this harness
```

External browser media reachability remains T3-S3 scope and requires a separate
advertised-address, NAT/firewall, and public media-edge design.

## Status

`T3-S2A = Complete`.

`T3-S2B in-cluster WebRTC media-flow proof = executed and INCOMPLETE`. See
`docs/evidence/t3/t3-s2b-in-cluster-webrtc-media-live-proof.md`.

The live execution proved ICE, DTLS-SRTP, and real outbound browser-to-rtpengine
media through the committed proof overlay, and re-confirmed SDP mediation
against a real Chromium WebRTC client. It did **not** prove inbound echo,
because `PRODUCT_DEFECT-15` blocks RTP between rtpengine and the reference
runtime: `utcp-runtime` permits no media ingress to the runtime Pod,
`allow-asterisk-sip-from-kamailio` confines runtime egress to SIP `5060` plus
DNS, and `allow-rtpengine-media` egress to `asterisk-ari` is scoped to UDP
`40000-40099` rather than the runtime's configured `10000-20000` RTP range.

Six proof-harness defects in this prover are open and must be corrected before
the proof is re-run:

```text
PROOF_HARNESS_DEFECT-1  no local-CA trust; natural login fails
                        net::ERR_CERT_AUTHORITY_INVALID
PROOF_HARNESS_DEFECT-2  runner waits only on condition=complete for 480s while
                        the Job sets ttlSecondsAfterFinished: 300, so failed
                        runs are collected before their logs and result are read
PROOF_HARNESS_DEFECT-3  waitForMessage returns messages[cursor - 1], the message
                        before the match
PROOF_HARNESS_DEFECT-4  naturalLogin races Vue SPA hydration
PROOF_HARNESS_DEFECT-5  bundlePolicy: 'max-bundle' rejects rtpengine's answer,
                        which carries no a=group:BUNDLE
PROOF_HARNESS_DEFECT-6  neither the runner nor job.yaml sets
                        UTCP_T3_MEDIA_PROVER_SCENARIO, so Scenario B is
                        unreachable through the committed entry point
```

The proof-only NetworkPolicies were sufficient and correctly selected; no
`PROOF_POLICY_DEFECT` was found.

`T3-S2C second-runtime parity, preferably FreeSWITCH = Not Started`.

`T3-S3 external media edge, advertised address, NAT/firewall and public
reachability = Not Started`.

`Asterisk = current reference runtime`.

`runtime-agnostic parity = not yet proven`.

`external browser media readiness = not yet proven`.

`T3 = In Progress`.

`UTCP_PHASE=T1`.

## Remaining Live Proof

The next bounded proof should apply only the local proof resources and run the
in-cluster prover against the already-applied provider-neutral media
configuration:

```text
browser SDP offer
-> rtpengine
-> application runtime

application-runtime SDP answer
-> rtpengine
-> browser

browser BYE
-> rtpengine delete

runtime BYE
-> rtpengine delete
```

That proof closes the contained media core only. External media exposure remains
T3-S3.
