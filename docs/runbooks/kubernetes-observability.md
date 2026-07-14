# K4 Runbook: Kubernetes Observability Foundation

Phase K4 installs a local observability foundation for the secured K0-K3 platform. It does not add tracing, authentication, tenancy, telephony runtimes, SIP, WSS signaling, Reverb, conference behavior, or production paging.

## Stack

K4 installs third-party software into `utcp-observability` through pinned Helm charts:

- `kube-prometheus-stack`: Prometheus Operator, Prometheus, Alertmanager, Grafana, and kube-state-metrics.
- `loki`: Loki single-binary local-development log storage.
- `alloy`: Kubernetes API Pod-log collection.

UTCP-owned alert rules, dashboards, ServiceMonitors, and NetworkPolicies are managed through Kustomize under `infrastructure/kubernetes/observability`.

## Security Model

`utcp-observability` keeps restricted Pod Security Admission. K4 workloads must run as non-root, drop Linux capabilities, disallow privilege escalation, use `RuntimeDefault` seccomp, and avoid hostPath, host network, host PID, host IPC, Docker sockets, and container runtime sockets.

K3 default-deny policies remain active. K4 adds only the required observability flows: Prometheus to Kubernetes discovery and selected scrape targets, Alloy to Kubernetes API and Loki, Grafana to Prometheus/Loki/Alertmanager, and internal observability component communication.

No observability endpoint is exposed through Traefik, Gateway API, NodePort, LoadBalancer, hostPort, or a permanent port-forward.

## Metrics

Prometheus scrapes Prometheus, Alertmanager, kube-state-metrics, Traefik metrics, Loki, Grafana, and supported observability component metrics. Traefik metrics remain cluster-internal.

Node-exporter and host-level monitoring agents are disabled because they would require host-level access that does not fit the K3 restricted local security contract.

## Logs

Alloy collects Kubernetes Pod logs from:

- `utcp-platform`
- `utcp-data`
- `utcp-observability`
- `traefik-system`

It does not collect unrelated APNTalk or broad `kube-system` logs.

Laravel, worker, scheduler, migration, Nginx gateway, web Nginx, and Traefik logs are structured JSON where UTCP owns the runtime configuration. The internal gateway generates an `X-Request-ID`, passes it to Laravel, and includes it in the response. Request IDs are log fields and must not become Loki labels.

Sensitive headers, cookies, credentials, request bodies, response bodies, Secret values, and private keys must not be logged.

## Grafana

Grafana data sources are provisioned for Prometheus, Loki, and Alertmanager. UTCP dashboards are provisioned from ConfigMaps:

- UTCP Platform Overview
- UTCP Workload Logs

Grafana credentials are generated under ignored `.runtime/observability/` storage and stored in the Kubernetes Secret `utcp-grafana-admin`. Normal status and proof commands must not print the password.

## Alerts

K4 alert rules cover required workload availability, StatefulSet readiness, repeated restarts, migration Job failure, PVC pending, expected target down, and core observability component availability.

Alertmanager uses a local null/default receiver only. K4 does not configure email, Slack, PagerDuty, external webhooks, or production paging.

`make observability-proof` creates a temporary `UTCPK4SyntheticProof` alert, verifies Alertmanager receives it, deletes the temporary rule, waits for the active alert to expire, and confirms no active proof alert remains.

## Persistence and Retention

Prometheus, Alertmanager, Grafana, and Loki use local-path PVCs sized for local development. Prometheus and Loki retention are intentionally short. This does not claim production durability or high availability.

Run:

```sh
make observability-persistence-proof
```

The proof deletes only Prometheus, Loki, and Grafana Pods, waits for replacement, and verifies persisted metric history, log history, data sources, and dashboards. It does not delete PVCs or recreate the cluster.

## Lifecycle

Validate without mutation:

```sh
make observability-config-check
```

Install or upgrade Helm releases:

```sh
make observability-install
```

Apply the full canonical K4 lifecycle:

```sh
make observability-apply
```

Inspect:

```sh
make observability-status
```

Prove runtime behavior:

```sh
make observability-proof
make observability-persistence-proof
```

Remove K4-owned resources:

```sh
make observability-delete
```

Deletion removes K4 Helm releases and K4-owned Kubernetes resources. It preserves K0, K1, K2, K3, namespaces, APNTalk resources, and the local registry. PVC deletion, when supported, must remain a separate explicit destructive action.

## Troubleshooting

If Prometheus targets are down, inspect `make observability-status`, the relevant ServiceMonitor, and K3 NetworkPolicies.

If logs are missing, verify Alloy is running, the namespace is in the Alloy discovery selector, and Loki is ready.

If Grafana dashboards are missing, check dashboard ConfigMap labels and the Grafana sidecar container logs.

If a synthetic proof alert appears to remain after the rule is deleted, check whether Alertmanager is retaining an active alert until its `endsAt` timestamp. The proof waits for active alert expiry and does not leave the PrometheusRule behind.

K4 deliberately does not install Tempo, Jaeger, Zipkin, OpenTelemetry Collector, OpenTelemetry Operator, or automatic instrumentation.
