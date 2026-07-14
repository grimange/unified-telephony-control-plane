# K4 Evidence: Kubernetes Observability Foundation

Phase K4 installs a local Kubernetes observability foundation around the completed K0-K3 platform.

## Scope

K4 includes Prometheus metrics, Loki logs, Alloy Kubernetes API log collection, Grafana dashboards and data sources, Alertmanager local alert delivery, short local retention, persistence proof, K3-compatible NetworkPolicies, and structured application/gateway logs.

K4 does not include tracing, authentication, tenancy, SIP, WSS signaling, Reverb, Kamailio, rtpengine, Asterisk, FreeSWITCH, conference behavior, fake telephony metrics, or production alert delivery.

## Versions

| Component | Version |
| --- | --- |
| kube-prometheus-stack chart | `87.15.2` |
| Prometheus Operator | `quay.io/prometheus-operator/prometheus-operator:v0.92.1` |
| Prometheus | `quay.io/prometheus/prometheus:v3.13.1-distroless` |
| Alertmanager | `quay.io/prometheus/alertmanager:v0.33.1` |
| Grafana | `docker.io/grafana/grafana:13.1.0` |
| Grafana dashboard sidecar | `docker.io/kiwigrid/k8s-sidecar:2.8.1` |
| kube-state-metrics | `registry.k8s.io/kube-state-metrics/kube-state-metrics:v2.19.1` |
| Loki chart | `7.0.0` |
| Loki | `docker.io/grafana/loki:3.6.7` |
| Alloy chart | `1.10.1` |
| Alloy | `docker.io/grafana/alloy:v1.17.1` |

## Runtime State

Observed Helm releases:

- `kube-prometheus-stack`
- `loki`
- `alloy`

Observed workloads in `utcp-observability`:

- Prometheus Operator Deployment
- Prometheus StatefulSet
- Alertmanager StatefulSet
- Grafana Deployment
- kube-state-metrics Deployment
- Loki StatefulSet
- Alloy Deployment

Observed PVCs:

- Prometheus local-path PVC
- Alertmanager local-path PVC
- Grafana local-path PVC
- Loki local-path PVC

## Metrics Proof

`make observability-proof` queried Prometheus directly through a temporary port-forward and proved:

- Prometheus readiness.
- `up` target data exists.
- kube-state-metrics deployment and StatefulSet readiness metrics exist.
- K1 workload availability metrics exist.
- Traefik request metrics exist after an HTTPS proof request.

## Log Proof

`make observability-proof` generated an HTTPS request to `https://app.utcp.local.test/api/version`, captured the returned request ID, and queried Loki for a matching structured log event.

The proof confirmed:

- Recent API, worker, scheduler, gateway, and Traefik log streams exist.
- The request ID is present as a log field.
- The request ID is not a Loki label.
- Sensitive fields such as authorization, cookie, password, secret, token, request body, and response body were not present in the correlated proof logs.

## Grafana Proof

`make observability-proof` used the Grafana API through a temporary port-forward and verified provisioned data sources:

- Prometheus
- Loki
- Alertmanager

It also verified UTCP dashboard inventory:

- UTCP Platform Overview
- UTCP Workload Logs

## Alert Proof

`make observability-proof` applied a temporary `UTCPK4SyntheticProof` PrometheusRule, waited for Prometheus evaluation and Alertmanager receipt, deleted the rule, waited for the active Alertmanager alert to expire, and confirmed no active synthetic proof alert remained.

## Persistence Proof

`make observability-persistence-proof` verifies:

- Prometheus metric history survives Prometheus Pod replacement.
- Loki log history survives Loki Pod replacement.
- Grafana data sources and dashboards survive Grafana Pod replacement.

PVCs are preserved during this proof.

## Security and Exposure Review

K4 kept `utcp-observability` under restricted Pod Security Admission and did not introduce privileged containers, hostPath, host network, host PID, host IPC, Docker socket mounts, or container runtime socket mounts.

K4 did not expose Grafana, Prometheus, Alertmanager, Loki, Alloy, or metrics endpoints through Traefik, Gateway API, NodePort, LoadBalancer, hostPort, or permanent port-forwarding.

## K3 Compatibility

K3 default-deny policies remain active. K4 adds only explicit observability allow paths for metrics scraping, Kubernetes discovery, Alloy log ingestion, Loki writes, Grafana data-source access, and Alertmanager/Prometheus communication.

Application ServiceAccounts remain unchanged and application token automount remains disabled.

## Existing Environment

UTCP retains the standard local edge on `127.0.0.1:80` and `127.0.0.1:443`. Reserved hostnames `sip.utcp.local.test` and `events.utcp.local.test` remain unrouted.

APNTalk remains outside UTCP control. K4 does not start, stop, delete, recreate, or mutate `apntalk-local`.

Hosted CI execution for the uncommitted working tree was not observed.
