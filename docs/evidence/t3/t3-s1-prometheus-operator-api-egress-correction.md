# T3-S1 - Prometheus Operator API Egress Correction

Verdict: `T3_S1_PROMETHEUS_OPERATOR_API_EGRESS_CORRECTION_COMPLETE`

This evidence records the bounded repository correction for `PRODUCT_DEFECT-4`.
No Kubernetes resource was applied, no image was rebuilt or pushed, no
rtpengine, Prometheus, or application workload manifest was changed, and
`UTCP_PHASE=T1` was preserved. T3 remains In Progress pending the focused
scrape-discovery live reproof.

## Source Authority

- Blocked proof commit: `a325b74` (`docs(t3): record authorized corridor reproof`)
- Blocked proof: [`t3-s1-rtpengine-foundation-live-proof.md`](t3-s1-rtpengine-foundation-live-proof.md)
- Prior correction: [`t3-s1-rtpengine-reciprocal-egress-correction.md`](t3-s1-rtpengine-reciprocal-egress-correction.md)
- ADR: [`../../decisions/ADR-020-t3-rtp-media-plane.md`](../../decisions/ADR-020-t3-rtp-media-plane.md)
- Phase marker retained: `UTCP_PHASE=T1`

## Live Root Cause

The authorized-corridor reproof showed that the rtpengine `PodMonitor`, its
selector, its namespace selector, and the named rtpengine `metrics` port
resolving to TCP `2224` were correct. Prometheus could also reach the
rtpengine metrics listener once source egress was added.

Scrape discovery still failed because the Prometheus Operator could not
reconcile monitor resources. The live Operator Pod carried these labels:

```text
app.kubernetes.io/name: kube-prometheus-stack-prometheus-operator
app.kubernetes.io/component: prometheus-operator
```

The canonical API-egress policy selected observability API consumers by
`app.kubernetes.io/name In [...]` and included the legacy value
`prometheus-operator`. No rendered API-dependent workload carried that exact
name, so the Operator matched no API-egress NetworkPolicy and its Kubernetes
API connection was refused. The valid `PodMonitor` therefore never appeared in
the generated Prometheus scrape configuration.

The same live proof independently showed that a policy-selected Pod could reach
the pinned API destinations, so the API ClusterIP, pinned node API address, and
TCP port contract were not the defect.

## Policy Correction

The canonical API-egress template now renders two bounded NetworkPolicies from
the same pinned destination contract:

```text
allow-observability-kubernetes-api-egress
  namespace: utcp-observability
  selector: app.kubernetes.io/name In [prometheus, grafana, alloy, kube-state-metrics]
  egress: pinned Kubernetes API host only, TCP 443/6443 contract

allow-prometheus-operator-apiserver-egress
  namespace: utcp-observability
  selector: app.kubernetes.io/component=prometheus-operator
  egress: pinned Kubernetes API host only, TCP 443/6443 contract
```

The ineffective `app.kubernetes.io/name=prometheus-operator` selector value was
removed because no rendered API-dependent workload uses it. The demonstrated
component label is sufficient for the Operator.

No API destination pin, port, default-deny policy, renderer ownership, drift
check, Prometheus selector, rtpengine `PodMonitor`, or rtpengine resource was
changed. No namespace-wide egress, unrestricted CIDR, Service-CIDR allow-list,
runtime-discovered allow-list, or proof-only policy was added.

## Static Coverage Validation

`scripts/security/observability-api-workloads.yaml` records the repository-owned
inventory of observability workloads that require Kubernetes API access:

- Prometheus
- Prometheus Operator
- Grafana sidecar/controller workload
- Alloy
- kube-state-metrics

`scripts/security/validate-observability-apiserver-egress` compares that
inventory's Pod-template labels with the rendered NetworkPolicy selectors. It
requires every API-dependent workload to be selected by at least one bounded
API-egress policy, requires the Operator policy to select
`app.kubernetes.io/component=prometheus-operator`, rejects the dead
`app.kubernetes.io/name=prometheus-operator` fallback when unused, and validates
that every selected API policy uses only one pinned API host and one canonical
TCP API port.

The validation does not contact a running cluster and does not treat a
human-readable allow-list string as coverage. `scripts/security/config-check`
runs the renderer, validates workload coverage against the generated policy,
then runs the existing API-egress drift check.

## Regression Coverage

`scripts/security/config-check-test` now proves the corrected repository passes
and that these temporary mutations fail:

- remove the component-based Operator policy
- change the Operator component value
- reintroduce only the ineffective `app.kubernetes.io/name=prometheus-operator`
  selector
- broaden the Operator selector to all observability Pods
- remove the pinned API destination
- widen API egress to `0.0.0.0/0`
- add a new API-dependent workload without a selecting policy

The test target is wired as `make security-config-check-test` and is included in
`make check`, so existing Namespace PSA, rtpengine, reciprocal-policy,
public-surface, and default-deny checks remain active alongside the focused
regressions.

## Rendered Policy Inspection

The canonical renderer produced:

```text
allow-observability-kubernetes-api-egress
  namespace: utcp-observability
  selector values: prometheus, grafana, alloy, kube-state-metrics
  destination: 172.24.0.2/32
  port: TCP 6443

allow-prometheus-operator-apiserver-egress
  namespace: utcp-observability
  selector: app.kubernetes.io/component=prometheus-operator
  destination: 172.24.0.2/32
  port: TCP 6443
```

`scripts/security/check-apiserver-policy-drift` passed against the generated
multi-document policy file. The rtpengine `PodMonitor` rendered unchanged as an
internal-only `PodMonitor/utcp-observability/rtpengine` selecting
`utcp-platform` Pods with `app.kubernetes.io/component=rtpengine` and scraping
`/metrics` on the named `metrics` port.

## Static Verification

Focused verification during implementation:

```text
scripts/security/config-check-test: passed
scripts/security/render-apiserver-policy: passed
scripts/security/validate-observability-apiserver-egress: passed
scripts/security/check-apiserver-policy-drift: passed
```

Full repository verification was completed before commit and is recorded in the
final task report. No Kubernetes resource was applied.

## Architecture Preservation

This correction changes only API-egress policy generation, deterministic
security validation, focused mutation coverage, and documentation. It preserves
the Prometheus Operator as the reconciliation authority, keeps the existing
rtpengine `PodMonitor`, and adds no manual scrape configuration, static scrape
target, sidecar bypass, feature gate, second monitoring control plane, image
change, workload rollout, application change, database change, or runtime
authority change.

## Remaining Live Proof Boundary

Claude Code applies only the corrected Prometheus Operator API-egress policy,
confirms the Operator reaches Ready, confirms the existing rtpengine PodMonitor
appears in generated Prometheus configuration, and proves the target is up with
at least one ingested rtpengine_* sample.
