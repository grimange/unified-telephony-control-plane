# V1 — External SIP edge contract and reachability audit

Date: 2026-08-24

## Verdict

```text
V1_EXTERNAL_SIP_EDGE_EXISTING_PATH_IDENTIFIED
```

Repository authority deterministically excludes every host/L4 SIP edge and
leaves exactly one consistent path: a disposable **in-cluster proof-scoped peer**
reaching `kamailio-sip-internal` on ClusterIP `5060/UDP`, admitted by
proof-scoped NetworkPolicies. The fixture is currently placed on the default
Docker bridge, where no authorized path can ever exist.

This record does not amend `v1-external-sip-peer-fixture-preparation.md`.

## Repository state

```text
branch   main
HEAD     234d8ae30b82f1eb7b9ab2ad0bf9703e3ac684f2   (unchanged)
dirty    63 entries at start (C7A/C7B/T6/V1 packets), unchanged by this audit
created  this evidence file only
commit   none
push     not pushed
```

Nothing was mutated: no Service, NetworkPolicy, Traefik, k3d, NodePort,
LoadBalancer, Docker network, or port publication was touched.

## Authoritative V1 external SIP contract

V1 requires "inbound external peer -> address -> route -> destination ->
canonical Call/CallLeg" using a synthetic peer. The initial implementation plan
specifies "a deterministic synthetic external SIP peer, such as SIPp or **a
second isolated SIP runtime**, to act as a trunk provider", and forbids paid
carriers, public PSTN, real caller identities, and production credentials.

Crucially, no authority requires a host-level or L3-external boundary. Every
statement about "external" concerns **authority independence**, and native SIP
exposure is explicitly deferred:

```text
ADR-011:54                    "Native SIP/TLS for non-browser devices is a separate
                               future concern and is not implemented by this decision."
architecture/overview.md:85    same statement
initial implementation plan:852 same statement
plan:867                      Traefik does not initially handle: native SIP ingress
ADR-019:40 / ADR-022:45 /
authority-boundaries.md:141   Kamailio is the single *browser-facing* SIP edge
```

## Current network topology and the exact break

```text
External SIP Peer fixture            docker network "bridge"   172.17.0.0/16
        |
        |  no route to the Kubernetes service CIDR
        |  no host-published SIP port
        |  no NodePort / LoadBalancer / L4 route for SIP
        X  <-- connectivity stops here
        |
k3d node network                     172.21.0.0/16
kamailio-sip-internal                ClusterIP 10.43.115.50 : 5060/UDP
```

Observed k3d server load balancer publications (read-only):

```text
127.0.0.1:80->80/tcp
127.0.0.1:443->443/tcp
127.0.0.1:40000-40099->40000-40099/udp
127.0.0.1:6550->6443/tcp
```

No `5060` or `5061`. The fixture harness starts the peer with
`docker run -d --name "$CONTAINER" --network bridge` — the default bridge, with
no published ports and no attachment to `k3d-utcp-local`.

## Kamailio Service

```text
kamailio                ClusterIP 10.43.38.131   8080/TCP    (WSS backend behind Traefik)
kamailio-sip-internal   ClusterIP 10.43.115.50   5060/UDP    (native SIP)
selector                app.kubernetes.io/component: kamailio
                        utcp.io/network-role: kamailio-signaling
external exposure       none
```

ClusterIP is not incidental — it is asserted:

```text
scripts/kamailio-signaling/config-check:591  "internal Kamailio SIP Service must be ClusterIP"
scripts/kamailio-signaling/config-check:633  "Asterisk SIP Service must be ClusterIP"
scripts/kamailio-signaling/config-check:643  "selected application-runtime Service must be ClusterIP"
```

## Traefik role

Traefik carries **HTTP/HTTPS/WSS only**. `sip.utcp.local.test` is the `sip-wss`
HTTPRoute (SIP over secure WebSocket, T1), not native SIP. The gateway check
enforces this:

```text
scripts/gateway/config-check:61  rejects kind: TCPRoute|UDPRoute|TLSRoute,
                                 asterisk|freeswitch|rtpengine, hostNetwork,
                                 hostPath, privileged, nodePort
scripts/gateway/config-check:64  "Kamailio may only be exposed by the exact
                                  sip-wss HTTPRoute"
```

Native SIP through Traefik is therefore deliberately absent, not missing.

## Kubernetes / Gateway role

No authorized Kubernetes mechanism exists for native SIP exposure:

```text
UDPRoute / TCPRoute / TLSRoute   forbidden (gateway check; ADR-020:83;
                                 runbooks/traefik-gateway-api.md:43)
NodePort / LoadBalancer for SIP  forbidden (gateway check; ClusterIP assertions)
hostNetwork                      forbidden (k3d + gateway checks)
```

The only non-ClusterIP Services in the live cluster are `traefik`
(LoadBalancer, 80/443 TCP) and `rtpengine-media` (NodePort, 40000-40099 UDP —
the T3-S3 media edge). Both are media/HTTP, neither is SIP.

## k3d host exposure contract

```text
infrastructure/k3d/cluster.yaml  publishes only 80, 443, 40000-40099/udp, API 6550
scripts/k3d/config-check:49      check_absent "...|:5060|:5061|hostNetwork|pod-cidr"
                                 "unapproved media-edge exposure is prohibited"
scripts/k3d/verify:112           rejects :5060-> / :5061-> on the running serverlb
```

The prohibition originates in the T3 media-edge containment work: only the
approved HTTP/HTTPS edge and the approved RTP UDP range may be host-published.
SIP host exposure is explicitly named as prohibited. This is **case 2** of the
audit's options — direct host SIP exposure is forbidden, and another path is
expected.

## NetworkPolicy

`allow-kamailio-signaling-required-traffic` (utcp-platform) admits UDP `5060`
ingress **only** from:

```text
utcp-runtime / app.kubernetes.io/component: asterisk-ari + utcp.io/network-role: asterisk-ari
utcp-runtime / utcp.io/network-role: freeswitch-esl
```

and permits Kamailio UDP `5060` egress only to those same two roles. TCP 8080
ingress is allowed from Traefik. So even an in-cluster peer is currently denied;
a bounded, tightly-selected policy admission is required. No broad CIDR or
allow-all is needed or proposed.

## Fixture independence

V1's meaning of "external" is **authority independence**, not L3 externality:
the fixture is not a RuntimeNode, not a T6 provider target, carries no canonical
UTCP identifiers, and is not product-managed. The plan itself calls for "a second
isolated SIP runtime".

The repository already has an exact precedent for a proof peer that must reach
in-cluster services which are ClusterIP-only and host-unreachable by design:

```text
infrastructure/kubernetes/overlays/local/proof/t3-media/
    namespace.yaml         utcp-proof, PSA restricted
    job.yaml               one-shot prover Job
    network-policies.yaml  default-deny + narrow egress
                           + reciprocal allow-rtpengine-media-prover in utcp-platform
    kustomization.yaml
scripts/t3-media-prover/run     creates ns, ephemeral secret/configmaps, applies
                                the overlay, runs the Job, deletes everything
make t3-media-prover-run        "Run the one-shot local in-cluster WebRTC media prover"
```

That prover reaches Kamailio through Traefik `443` (WSS) and rtpengine through
the media range. It never opened a host edge. A native-SIP trunk peer cannot use
WSS, so it needs the equivalent treatment against `kamailio-sip-internal`.

## Signaling receiver already exists

The T6 Kamailio consumption slice already landed native external-trunk inbound
handling, so the SIP-side receiver is present and only the transport is missing:

```text
kamailio-configmap.yaml:90   if ($rd == "external.utcp.local.test")
kamailio-configmap.yaml:231  sql_query(... kamailio_external_trunk_route_view
                             where normalized_address = '$rU' and direction = 'inbound')
kamailio-configmap.yaml:237  route_not_found / matched logging with
                             canonical_external_trunk_id, provider_local_trunk_id, route_id
```

## Classification

```text
PROOF_DEFECT
```

The canonical target (`kamailio-sip-internal` ClusterIP `5060/UDP`) exists, is
deployed, and already has external-trunk inbound routing logic. The proof
harness places the peer on the default Docker bridge, where no repository-
authorized path can exist. The required NetworkPolicy admission is proof-scoped
overlay content, precedent-identical to `allow-rtpengine-media-prover`, not a
product edge.

## Root cause

The V1 External SIP Peer fixture was implemented as a host-level Docker
container on the default bridge, but every host and L4 SIP exposure mechanism is
explicitly prohibited by the k3d, gateway, and Kamailio service contracts, so the
peer can never reach `kamailio-sip-internal`; the repository's established
pattern for such a peer is a disposable in-cluster proof overlay.

## Bounded implementation target

One packet: **relocate the External SIP Peer fixture into a disposable
in-cluster proof overlay**, mirroring `overlays/local/proof/t3-media/`.

```text
authority        V1 acceptance harness (proof infrastructure). C7A/C7B remain
                 telephony authority; T6 remains projection; Kamailio remains the
                 SIP executor. The peer transports packets only.
files            infrastructure/kubernetes/overlays/local/proof/v1-external-sip-peer/
                   namespace.yaml (reuse utcp-proof, PSA restricted)
                   deployment.yaml or job.yaml  (pinned fixture image, pushed to
                     the local registry via the existing image lifecycle)
                   network-policies.yaml
                   kustomization.yaml
                 scripts/v1/external-sip-peer-smoke  (replace `docker run --network
                   bridge` with the overlay apply/teardown flow)
transport/port   native SIP, UDP 5060
network path     peer Pod -> kamailio-sip-internal ClusterIP 10.43.x.x:5060/UDP
                 (and Kamailio -> peer Pod 5060/UDP for the reverse direction)
NetworkPolicy    proof namespace: default-deny + egress to kube-dns 53 and to
                   utcp-platform/utcp.io/network-role: kamailio-signaling UDP 5060
                 utcp-platform: additive ingress on kamailio-signaling UDP 5060 from
                   the proof namespace + podSelector utcp.io/network-role:
                   v1-external-sip-peer, and the matching egress rule
                 no broad CIDR, no allow-all, no change to existing product rules
no second        the peer holds no canonical IDs and performs no routing; inbound
authority        resolution stays in kamailio_external_trunk_route_view fed by T6
acceptance       peer Pod sends INVITE to kamailio-sip-internal:5060; Kamailio logs
                 kamailio_external_trunk_route result=matched with the canonical
                 external_trunk_id/route_id; C7B inbound evaluation yields a
                 canonical Call/CallLeg; reverse outbound leg reaches the peer
lifecycle        overlay deleted on teardown; no host port, NodePort, LoadBalancer,
                 Traefik, k3d, or Service-type change; k3d config-check and
                 kamailio-signaling config-check must still pass unchanged
```

## Failed tests / proof steps

```text
None.
```

No proof step was executed; this audit was read-only.

## Remaining V1 proof gap

The natural external inbound and outbound SIP acceptance proof, after the fixture
is relocated.

## V1 status

```text
V1_REMAINS_ACTIVE
```
