# T3-S1 - rtpengine Reciprocal Egress and Metrics Discovery Correction

Verdict: `T3_S1_RTPENGINE_RECIPROCAL_EGRESS_CORRECTION_COMPLETE`

This evidence records the bounded repository correction for `PRODUCT_DEFECT-3`.
No Kubernetes resource was applied, no image was rebuilt or pushed, no runtime
workload manifest was changed, and `UTCP_PHASE=T1` was preserved. T3 remains In
Progress pending focused authorized-corridor live reproof.

## Source Authority

- Blocked proof commit: `beb4fd3` (`docs(t3): prove rtpengine media-plane foundation`)
- Blocked proof: [`t3-s1-rtpengine-foundation-live-proof.md`](t3-s1-rtpengine-foundation-live-proof.md)
- ADR: [`../../decisions/ADR-020-t3-rtp-media-plane.md`](../../decisions/ADR-020-t3-rtp-media-plane.md)
- Implementation evidence: [`t3-s1-rtpengine-foundation-implementation.md`](t3-s1-rtpengine-foundation-implementation.md)
- Phase marker retained: `UTCP_PHASE=T1`

## Root Cause

`allow-rtpengine-media` declared the destination-side ingress corridors for the
two authorized rtpengine consumers:

- Kamailio signaling Pods may reach rtpengine `2223/UDP`.
- Prometheus may reach rtpengine `2224/TCP`.

Under namespace default-deny, destination ingress is only one half of a working
NetworkPolicy corridor. The source Pods also need matching egress. The live
proof showed both source-side rules were absent:

- `allow-kamailio-signaling-required-traffic` allowed DNS and PostgreSQL only.
- `allow-prometheus-egress-to-application-metrics` allowed gateway metrics only.

Each source was independently shown to reach a destination already permitted by
its own policy, isolating the failure to missing source egress rather than to
rtpengine ingress, the ng listener, or the metrics listener.

## Reciprocal Policy Correction

Kamailio now has one exact source-egress rule from the existing
`utcp.io/network-role: kamailio-signaling` policy to:

```text
namespaceSelector:
  kubernetes.io/metadata.name: utcp-platform
podSelector:
  app.kubernetes.io/component: rtpengine
protocol: UDP
port: 2223
```

Prometheus now has one exact source-egress rule from the existing
`app.kubernetes.io/name In [prometheus]` policy to:

```text
namespaceSelector:
  kubernetes.io/metadata.name: utcp-platform
podSelector:
  app.kubernetes.io/component: rtpengine
protocol: TCP
port: 2224
```

The existing destination-side `allow-rtpengine-media` policy was not broadened.
Its bounded ingress remains:

- Kamailio identity to rtpengine `2223/UDP`
- Kamailio and Asterisk runtime media identities to `40000-40099/UDP`
- Prometheus identity to rtpengine `2224/TCP`

No broad namespace egress, `ipBlock`, media-range egress, public control,
public metrics, NodePort, LoadBalancer, Gateway, or Ingress exposure was added.

## Metrics Scrape Discovery

ADR-020 section 10 requires rtpengine's internal metrics listener to be
scrape-discovered. The repository's established observability convention uses
Prometheus Operator monitor resources labeled `release: kube-prometheus-stack`
with `interval: 30s` and `scrapeTimeout: 10s`.

The correction adds `PodMonitor/utcp-observability/rtpengine` because the
committed rtpengine Service intentionally exposes only the UDP ng control port,
and the task forbids changing that Service solely for discovery. The PodMonitor
selects only:

```text
namespace: utcp-platform
app.kubernetes.io/part-of: utcp
app.kubernetes.io/component: rtpengine
```

It scrapes:

```text
port: metrics
path: /metrics
interval: 30s
scrapeTimeout: 10s
```

The named container port `metrics` remains TCP `2224`.

## Static Guard Enhancement

`scripts/media/config-check` now renders the platform, security,
observability, and gateway Kustomize trees offline and validates the reciprocal
policy and scrape-discovery contract:

- `allow-rtpengine-media` targets only rtpengine media Pods.
- rtpengine destination ingress includes exact Kamailio `2223/UDP` and
  Prometheus `2224/TCP` corridors.
- Kamailio source egress includes exact rtpengine `2223/UDP`.
- Prometheus source egress includes exact rtpengine `2224/TCP`.
- source egress rules do not substitute an `ipBlock`.
- source egress rules do not widen the destination to the whole namespace.
- default-deny policies remain present through the existing security checks.
- the rtpengine PodMonitor exists, selects only rtpengine in `utcp-platform`,
  uses `/metrics`, and resolves the named metrics port to TCP `2224`.
- no public rtpengine metrics Service, Gateway, or Gateway API route exists.

Failure messages name the missing source policy, missing destination selector,
wrong port/protocol, overbroad destination, missing scrape target, or scrape
selector drift.

## Regression Coverage

`scripts/media/config-check-test` now proves the corrected repository passes and
that these temporary mutations fail:

- remove Kamailio's rtpengine egress
- change Kamailio's rtpengine egress port away from UDP `2223`
- widen Kamailio's rtpengine destination to the whole namespace
- remove Prometheus's rtpengine egress
- change Prometheus's rtpengine egress port away from TCP `2224`
- widen Prometheus's rtpengine destination to the whole namespace
- remove the rtpengine scrape target
- change the scrape path away from `/metrics`
- change the scrape selector to a non-rtpengine workload
- add a public rtpengine metrics LoadBalancer Service

The existing image, PID-file, security, media-range, gateway, k3d,
Kamailio-runtime, and durable-authority assertions remain active in the same
test suite.

## Rendered Manifest Inspection

Rendered security policy shows:

```text
Kamailio selector: utcp.io/network-role=kamailio-signaling
Kamailio egress preserved: DNS 53/UDP+TCP, PostgreSQL 5432/TCP
Kamailio rtpengine egress: utcp-platform + app.kubernetes.io/component=rtpengine, UDP 2223 only

rtpengine selector: utcp.io/network-role=rtpengine-media
rtpengine ingress preserved: Kamailio UDP 2223, media UDP 40000-40099, Prometheus TCP 2224
```

Rendered observability policy shows:

```text
Prometheus selector: app.kubernetes.io/name In [prometheus]
Prometheus egress preserved: gateway TCP 8081
Prometheus rtpengine egress: utcp-platform + app.kubernetes.io/component=rtpengine, TCP 2224 only
PodMonitor: utcp-platform rtpengine selector, /metrics, port metrics
```

Rendered platform manifests show the rtpengine Service remains `ClusterIP` and
still exposes only `2223/UDP` with target port `ng`; rtpengine's container port
`metrics` remains TCP `2224`. No public rtpengine Service was rendered.

## Static Verification

```text
make media-config-check: passed
make media-config-check-test: passed
make security-config-check: passed
```

Full verification was completed before commit and is recorded in the final task
report. No Kubernetes resource was applied.

## Architecture Preservation

This correction changes only source NetworkPolicy egress, internal scrape
discovery, deterministic static validation, mutation coverage, and
documentation. It does not change the rtpengine image, entrypoint, Deployment,
Service, ports, security context, Kamailio runtime configuration, application
domain authority, database schema, RuntimeNode authority, or `UTCP_PHASE`.

## Remaining Live Reproof Boundary

Claude Code applies only the two corrected source NetworkPolicies and the
rtpengine scrape target, then re-proves authorized control, unauthorized
control denial, authorized Prometheus scrape/discovery, unauthorized metrics
denial, and environment preservation. Do not repeat startup, PSA, readiness,
liveness, media containment, relay-failure, or restoration proofs.
