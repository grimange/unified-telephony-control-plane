# T3-S2A Kamailio UDP Relay Socket Correction

Date: 2026-07-29

Starting commit: `c08180c` (`docs(t3): record kamailio dialog reproof`)

## Verdict

`PRODUCT_DEFECT-6` is corrected in the repository. T3-S2A is ready for
authenticated-dialog reproof. T3-S2 media mediation remains Not Started, and T3
remains In Progress.

No Kubernetes resource was applied, no workload was restarted, and no rtpengine
mediation was added.

## Root Cause

The live Kamailio dialog reproof confirmed that the canonical Asterisk
destination was healthy and reachable through its internal Service:

```text
sip:asterisk-sip.utcp-runtime.svc.cluster.local:5060;transport=udp
```

Kamailio, however, declared only:

```text
listen=tcp:0.0.0.0:8080
```

It had no UDP listening socket, so the transaction module could not construct an
outbound UDP branch for the Asterisk relay target. The runtime error sequence
was:

```text
uri2dst2(): no corresponding socket found
prepare_new_uac(): no corresponding listening socket
t_forward_nonack(): failure to add branches
```

The result was the configured `503 Application Runtime Unavailable` response
while Asterisk was healthy. That response was therefore masking a Kamailio
transport mismatch, not proving Asterisk unavailability.

## Correction

The canonical Kamailio ConfigMap now preserves the existing TCP listener and
adds exactly one UDP listener:

```text
listen=tcp:0.0.0.0:8080
listen=udp:0.0.0.0:5060
```

The Kamailio Deployment now declares the matching internal container port:

```text
name: sip-udp
containerPort: 5060
protocol: UDP
```

The Asterisk relay destination remains unchanged:

```text
asterisk-sip.utcp-runtime.svc.cluster.local:5060;transport=udp
```

No Service, HostPort, Gateway, UDPRoute, NodePort, LoadBalancer, k3d
publication, public DNS, second Asterisk destination, or runtime-selected
transport was added.

## Parser Boundary

The rendered Kamailio parser check from the PRODUCT_DEFECT-5 correction remains
authoritative for syntax, module loading, command resolution, route validity,
and module parameters:

```text
/usr/sbin/kamailio -c -f <rendered kamailio.cfg>
```

That parser cannot prove that an outbound `;transport=udp` destination has a
compatible local socket. `PRODUCT_DEFECT-6` was therefore a lifecycle validation
gap rather than a parser gap.

## Static Guard

`scripts/kamailio-signaling/config-check` now inspects the final rendered
Kamailio ConfigMap and Deployment and requires:

- exactly one Asterisk relay destination:
  `asterisk-sip.utcp-runtime.svc.cluster.local:5060;transport=udp`
- exactly one matching Kamailio listener:
  `listen=udp:0.0.0.0:5060`
- existing `listen=tcp:0.0.0.0:8080` unchanged
- matching Deployment port `sip-udp` with `containerPort: 5060` and
  `protocol: UDP`
- no HostPort or HostNetwork
- no Kamailio UDP Service or UDPRoute exposure
- no NodePort or LoadBalancer for a Kamailio Service
- no Pod-IP, node-IP, ClusterIP, or developer-host listener binding
- no manual rollout timestamp

The check still renders and parses every supported Kamailio variant:

```text
infrastructure/kubernetes/base/platform
infrastructure/kubernetes/base
infrastructure/kubernetes/overlays/local/platform
infrastructure/kubernetes/overlays/local
```

## Mutation Coverage

`scripts/kamailio-signaling/config-check-test` now rejects:

- removing `listen=udp:0.0.0.0:5060`
- changing the UDP listener to TCP
- changing the UDP listener port
- removing the Deployment UDP container port
- changing the container-port protocol to TCP
- changing the Asterisk relay destination UDP port
- adding a second UDP listener
- adding a second Asterisk relay destination
- adding HostPort
- exposing Kamailio UDP through a Service
- changing the Kamailio Service to NodePort or LoadBalancer
- adding a UDPRoute
- replacing the listener with a Pod-IP literal
- changing the configuration without updating the deterministic checksum
- adding a manual rollout timestamp

Existing siputils, rendered-parser, REGISTER, authentication,
application-dialog, Asterisk destination, rtpengine-boundary, reciprocal-policy,
and public-surface checks remain active.

## Rollout Coupling

The checksum-coupled Pod template was recalculated from the corrected rendered
`kamailio.cfg`:

```text
utcp.io/kamailio-config-sha256=749fc1ca8a22750531621b259d523ef045e5214e2f997683655bf22731673e7d
```

Identical configuration yields the same Pod-template hash. Changing
`kamailio.cfg` changes the Pod template. No timestamp annotation, reloader
controller, sidecar, feature gate, or manual rollout command was introduced.

## Verification

Repository verification is recorded in the final task report for the scoped
commit. The material focused checks are:

```text
make kamailio-signaling-config-check
make kamailio-signaling-config-check-test
```

Both must pass before this correction is considered complete.

## Remaining Live Boundary

The remaining proof is runtime-only:

```text
Apply only the corrected Kamailio ConfigMap and checksum-coupled Deployment,
confirm UDP 5060 is active, and resume from the authenticated INVITE through
SDP response, Record-Route, ACK, BYE, Asterisk-unavailable behavior and
restoration.
```

Do not repeat deterministic rollout, parser, authentication challenge,
REGISTER, Asterisk, NetworkPolicy, or public-surface proof during that live
closure.
