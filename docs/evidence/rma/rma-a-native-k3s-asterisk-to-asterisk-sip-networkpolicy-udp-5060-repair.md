# RMA-A Native k3s Asterisk-to-Asterisk SIP NetworkPolicy UDP/5060 Repair

Current-State-Impact: yes

## Verdict

`RMA_A_NATIVE_K3S_ASTERISK_TO_ASTERISK_SIP_NETWORKPOLICY_UDP_5060_REPAIR_IMPLEMENTED_AND_TESTED`

The bounded NetworkPolicy repair is implemented and repository-tested. Native
k3s deployment and fresh SIP delivery remain the next Terra verification.

## Root cause and authority

The persistent originating-side PJSIP trace proved ARI channel creation and
repeated UDP/5060 INVITEs to the `asterisk-sip` Service, while its sole ready
destination Asterisk backend received no request. Runtime default-deny and
`allow-asterisk-sip-from-kamailio` omitted same-namespace Asterisk UDP/5060
egress and ingress authorization.

Dynamic RuntimeNode Pods and the canonical Service backend both use the
repository-owned stable identity selector:

```yaml
app.kubernetes.io/component: asterisk-ari
utcp.io/network-role: asterisk-ari
```

The varying `utcp.dev/runtime-node` label is not used as network identity.

## Implementation

The existing `allow-asterisk-sip-from-kamailio` policy now adds one ingress and
one egress rule. Each is limited to namespace `utcp-runtime`, the exact
Asterisk component/role selector, protocol UDP, and port 5060. Existing
Kamailio UDP/5060 ingress, Kamailio UDP/5062 egress, RTPengine media, DNS, and
the runtime namespace default-deny policy remain intact. No wildcard peer,
CIDR, Service ClusterIP, static Pod IP, TCP/TLS port, or unrelated runtime
traffic was authorized.

## Validation

Passing focused checks:

* `make security-config-check-test`
* `make kamailio-signaling-config-check-test`
* `make repository-hygiene`
* `make phase-status-consistency-check`
* `git diff --check`

The checks assert exact selectors, same-namespace peer rules, UDP/5060-only
scope, default-deny posture, and preservation of existing signaling/media/DNS
relationships. `make security-config-check` and `make server-config-check`
could not reach the configured Kubernetes API (`127.0.0.1:6550`) in this
environment. `make media-config-check` reports the unrelated pre-existing
T3-S1 durable-media-authority failure.

## Deployment and live evidence

No live mutation was performed because the configured native-k3s API was
unavailable. Live verification must apply the repository-owned policy through
the canonical native-k3s workflow, confirm the selected Pods, and run one fresh
canonical Call proving destination Asterisk receives UDP/5060. Full RMA-A
recording start/stop proof remains unclaimed.

## Scope

RecordingSession, CallLeg lifecycle, Asterisk recording behavior, FreeSWITCH,
RTP, Services, endpoint identity, and unrelated roadmap areas were untouched.
