# ADR-012: Kubernetes Observability Foundation

## Status

Accepted

## Context

K4 needs local Kubernetes observability for the secured K0-K3 platform without weakening restricted Pod Security Admission or turning observability into a business, telephony, or reconciliation authority.

The local cluster must provide metrics, logs, dashboards, alerts, retention, and proof while avoiding hostPath mounts, privileged agents, public observability exposure, remote telemetry, and production storage assumptions.

## Decision

UTCP uses the following local Kubernetes observability foundation:

- Metrics: Prometheus Operator, Prometheus, and kube-state-metrics from the official Prometheus community `kube-prometheus-stack` chart.
- Logs: Grafana Loki in single-binary local-development mode.
- Collection: Grafana Alloy using Kubernetes API Pod-log sources.
- Visualization: Grafana with repository-provisioned Prometheus, Loki, and Alertmanager data sources plus UTCP dashboards.
- Alerts: Alertmanager with a local null/default receiver and no external paging integration.
- Tracing: deferred until real application and runtime operations exist to instrument.

Third-party observability software is installed through Helm. UTCP-owned dashboards, alert rules, monitors, and NetworkPolicies are managed through Kustomize.

Node-exporter, host-level agents, hostPath log collection, host networking, host PID, privileged mode, remote-write, object storage, distributed Loki, Mimir, Thanos, Tempo, OpenTelemetry Collector, and automatic instrumentation are not part of K4.

## Consequences

- `utcp-observability` remains under restricted Pod Security Admission.
- Observability components are cluster-internal and are not exposed through Traefik, Gateway API, NodePort, LoadBalancer, hostPort, or permanent port-forwarding.
- Prometheus, Alertmanager, Grafana, and Loki use local-path PVCs with short local-development retention.
- Alloy collects logs only from UTCP namespaces selected by K4.
- Request IDs and future high-cardinality identifiers are log fields, not Loki labels.
- Production retention, high availability, external alert delivery, remote telemetry, and tracing require separate future decisions.
