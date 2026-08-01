# T3-S2A Asterisk Cluster-DNS Egress Correction

Starting commit: `aa3ce38` (`docs(t3): record incomplete in-dialog reproof`)

Phase marker: `UTCP_PHASE=T1`

## Scope

This is a repository-only correction for `PRODUCT_DEFECT-10`. No Kubernetes
resources were applied, no workloads were restarted, no Kamailio route logic was
changed, and no rtpengine mediation was added.

The proven route-set authority remains:

```text
kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
```

Kubernetes Service DNS remains the stable internal identity for Asterisk-originated
in-dialog requests. The correction does not replace it with a Pod IP, Service
ClusterIP literal, node IP, developer-host address, public SIP hostname,
`hostAliases`, or manual resolver entry.

## PRODUCT_DEFECT-10

The final in-dialog live proof established that Record-Route identities, ACK
continuity, client-originated BYE, the internal Kamailio Service, the reverse SIP
NetworkPolicy corridor, healthy Asterisk dialog handling, the unavailable-runtime
`503`, restoration, REGISTER preservation, and rtpengine non-involvement all held.

The remaining defect was narrower: the canonical Asterisk workload could send SIP
egress to Kamailio on UDP `5060`, but it could not resolve the internal Kamailio
Service DNS name. The live evidence recorded name lookup failures for
`kamailio-sip-internal.utcp-platform.svc.cluster.local` and a raw DNS query timeout
to cluster DNS, so an Asterisk-originated BYE never left the Pod.

The cause was default-deny doing exactly what it should do: the canonical Asterisk
policy allowed only SIP egress to Kamailio and did not grant UDP/TCP `53` to
cluster DNS.

## Kubernetes Contract

Kubernetes creates DNS records for Services through the cluster DNS service, such
as CoreDNS, and cross-namespace Service lookups use the fully qualified Service
name. Kubernetes NetworkPolicy egress is additive: when a Pod is egress-isolated,
traffic is allowed only by matching egress rules.

References:

* <https://kubernetes.io/docs/concepts/services-networking/dns-pod-service/>
* <https://kubernetes.io/docs/concepts/services-networking/network-policies/>

## Existing Cluster-DNS Authority

The repository already uses the cluster-DNS identity in rendered NetworkPolicies:

```yaml
namespaceSelector:
  matchLabels:
    kubernetes.io/metadata.name: kube-system
podSelector:
  matchLabels:
    k8s-app: kube-dns
ports:
  - protocol: UDP
    port: 53
  - protocol: TCP
    port: 53
```

The Kamailio signaling policy now uses that exact combined namespace-and-Pod
selector for its DNS egress rule, preserving the existing Kamailio DNS authority
while making selector drift statically detectable.

## Correction

`infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml`
now preserves the existing exact SIP contract:

```text
canonical Asterisk -> canonical Kamailio -> UDP 5060
```

It adds one separate DNS egress rule for the canonical Asterisk workload only:

```text
canonical Asterisk -> kube-system/kube-dns Pods -> UDP 53 and TCP 53
```

The policy selector still requires the canonical Asterisk labels, including
`utcp.dev/runtime-node: local-asterisk-ari`, so the secondary
`asterisk-ari-b` workload remains excluded.

UDP `53` is required for normal DNS queries. TCP `53` is retained because DNS can
fall back to TCP for larger responses and because the repository-established
Kamailio DNS authority already grants both protocols.

## Static Guards

`scripts/security/config-check` now asserts the rendered repository state:

* canonical Asterisk remains egress-isolated;
* Asterisk SIP egress remains exact UDP `5060` to canonical Kamailio only;
* Asterisk DNS egress is exact UDP `53` and TCP `53` to the established
  cluster-DNS identity;
* the DNS peer is one combined namespace-and-Pod selector;
* the DNS rule is compared with the Kamailio DNS rule so selector drift fails;
* no DNS `ipBlock`, unrestricted egress, namespace-wide DNS allowance,
  DNS-over-TLS port, or broad CIDR is allowed;
* the secondary Asterisk workload remains excluded;
* default-deny and public-surface guards remain active.

## Mutation Coverage

`scripts/security/config-check-test` now covers the defect with focused mutations:

* removing UDP `53`;
* removing TCP `53`;
* changing either DNS port;
* removing the cluster-DNS Pod selector;
* removing the `kube-system` namespace selector;
* splitting namespace and Pod selectors into separate peer entries;
* widening DNS to all `kube-system` Pods;
* replacing DNS egress with `ipBlock`;
* adding `0.0.0.0/0`;
* adding unrestricted egress;
* applying the policy to `asterisk-ari-b`;
* removing or widening SIP UDP `5060` egress;
* drifting the Kamailio DNS authority;
* replacing the internal Kamailio route-set DNS identity with an IP literal.

Existing default-deny, Record-Route, reverse-policy, REGISTER, rtpengine, and
public-surface checks remain active through the existing check suite.

## Render Inspection

Rendered security resources were inspected locally. The Asterisk policy renders:

```text
selected source:
  utcp-runtime canonical Asterisk only

SIP egress:
  utcp-platform Kamailio signaling Pod
  UDP 5060

DNS egress:
  kube-system Pods labelled k8s-app=kube-dns
  UDP 53
  TCP 53
```

The rendered policy retains exact Kamailio-to-Asterisk SIP ingress and exact
Asterisk-to-Kamailio SIP egress. No namespace-wide runtime egress, public Service,
Gateway, UDPRoute, HostPort, HostNetwork, NodePort, LoadBalancer, Service CIDR,
Pod CIDR, ClusterIP literal, or `ipBlock` was added.

An attempted read-only live inspection of kube-system Pod labels could not reach
the local Kubernetes API server (`0.0.0.0:46229` refused the connection). That
does not invalidate this repository-only correction; no live apply or workload
restart was in scope.

## Status

`PRODUCT_DEFECT-10 = corrected in repository`.

T3-S2A is ready for final Asterisk-originated BYE reproof.

T3-S2 media mediation is Not Started.

T3 remains In Progress.

## Remaining Focused Proof

Claude Code applies only the corrected Asterisk NetworkPolicy and proves
cluster-Service DNS resolution plus one real Asterisk-originated BYE through the
existing internal Kamailio route set. Do not repeat ACK, client BYE,
Record-Route, unavailable-runtime `503`, REGISTER, rtpengine, restoration, or
public-surface proof.
