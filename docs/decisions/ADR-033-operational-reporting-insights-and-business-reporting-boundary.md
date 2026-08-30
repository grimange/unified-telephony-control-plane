# ADR-033 — Operational Reporting, Insights, and Business-Reporting Boundary

## Status

Accepted as a future UTCP core capability and product-boundary decision. This
ADR defines authority and scope only; it does not claim reporting projections,
tables, APIs, exports, an Insights UI, a warehouse, or a reporting worker.

## Context

UTCP already owns vendor-neutral telephony and control-plane state, normalized
Call/CallLeg lifecycle, RuntimeNode and infrastructure interpretation,
reconciliation, audit, and operational visibility. Applications consume those
contracts to implement business workflows such as dialing, campaigns, CRM, and
contact-center operations. Without an explicit reporting boundary, a future
dashboard or aggregate could become a second source of truth or pull business
interpretation into the control plane.

## Decision

UTCP owns vendor-neutral operational telephony, infrastructure, lifecycle,
control-plane, and audit reporting derived from canonical UTCP state and
authoritative external observations. Reports are read models, not authority.
Business reporting—including campaigns, leads, agents, dispositions,
conversion, and revenue—belongs to applications consuming UTCP.

The preferred authority flow is:

```text
canonical PostgreSQL state
+ normalized lifecycle/events and history
+ canonical audit history
+ authoritative external observations
        -> deterministic reporting projection/read model
        -> reporting/read API
        -> Web Admin Insights or external consumer
```

Reporting projections MUST NOT write back into Calls, CallLegs, RuntimeNodes,
routes, trunks, caller identities, recording artifacts, Kubernetes Nodes,
readiness, placement, policy, or configuration. No separate event/state
authority may be introduced only for reporting. Where an external system owns
facts, reporting consumes UTCP's normalized or explicitly authoritative
observation boundary; it does not promote a report into desired state.

## Reporting scope

Permitted future UTCP operational reports include normalized technical facts
such as call attempts, answered/completed/failed calls, direction, duration,
timing, termination and technical failure reasons, route and trunk usage,
runtime selection and distribution, provider/runtime outcomes, capacity
exclusions, RuntimeNode readiness and lifecycle history, placement and
failure-domain observations, reconciliation/failover/recovery history, and
technical RMA lifecycle after RMA exists. Selection explainability should report
the decision recorded by the canonical selector, for example capacity or
placement exclusion followed by the selected RuntimeNode. A report must not
independently rerun policy and present its reconstruction as historical truth.

These are scope examples, not a frozen dashboard or metric catalog.

UTCP reporting remains vendor-neutral. Reports use concepts such as `call
completed`, `runtime unavailable`, `capacity exhausted`, and `route selected`,
not Asterisk, FreeSWITCH, or Kamailio business-reporting models. Vendor raw
evidence may remain available for authorized diagnostics.

Business interpretation remains outside UTCP. Campaign performance, pacing,
lead lists and penetration, agent productivity or quality, dispositions, sales,
revenue, conversion, appointments, customer/contact outcomes, workforce
productivity, business KPIs, retry effectiveness, and funnel analytics belong
to a dialer, CRM, contact-center, or other consuming application. An
application may correlate its business records with UTCP technical identifiers;
UTCP need not interpret `SALE`, `LEAD`, or `CONVERSION`.

Technical session, registration, participant, endpoint, or user/agent identity
facts may exist where telephony architecture requires them. They do not become
agent productivity, sales effectiveness, adherence, or business-performance
authority. Queue/ACD technical lifecycle may later generate operational facts
only if a separate core contract is adopted; queue business performance,
service-level interpretation, and campaign/agent queue analytics remain
application concerns.

RMA remains governed by ADR-029 and separately scheduled. Future reports may
show recording requests, capture, artifact, archive-transfer, retention, and
technical target-health lifecycle. UTCP does not own legal consent
interpretation, regulatory compliance interpretation, business recording
reasons, or customer-business classification.

## Presentation and consumer boundaries

Current operational management screens present current canonical or observed
state. Future Insights presents historical, aggregate, trend, and forensic
read models. A future reusable reporting/read API may serve Web Admin,
dialers, contact-center applications, operational integrations, and external
analytics consumers. Endpoint paths and schemas are not decided here.

The future Web Admin direction may include an `Insights` area with conceptual
domains such as Overview, Calls, Connectivity, Runtime Infrastructure,
Operations, and Audit. This is product direction only: no routes, components,
permissions, APIs, charts, or menu entries are created by this ADR.

If future realtime refresh is useful, the boundary remains:

```text
source state change -> reporting projection change -> Reverb notification
-> frontend refetches the reporting API
```

Reverb remains notification transport, never report authority. Web Admin and
the authenticated application/domain API remain the canonical management path;
filters, grouping, date ranges, and export formatting are read concerns and do
not mutate desired state. Artisan does not become reporting management
authority.

Tenant isolation, authorization, privacy, and security requirements apply to
reports and aggregates exactly as they apply to their source records. Future
retention must respect source authority, technical retention, tenant policy,
privacy/security requirements, and ADR-029 where relevant. No retention period
is fixed here. Future CSV/JSON/API export is derived data delivery, not
business-workflow authority.

## Storage and architecture boundary

The default initial direction is deterministic PostgreSQL-backed reporting
projections in the existing modular monolith. A reporting microservice is not
authorized. ClickHouse, TimescaleDB, BigQuery, Snowflake, OpenSearch,
Elasticsearch, a dedicated warehouse, and a streaming analytics platform are
explicitly deferred until evidence shows PostgreSQL projections are
insufficient.

Observability and reporting remain complementary. Metrics, logs, traces,
health, alerts, and diagnostics support engineering operations; normalized
historical lifecycle, selection, capacity, trunk, and audit interpretation
supports operational reporting. Prometheus/Grafana-style monitoring is not
canonical Call/CallLeg reporting authority, and reporting does not replace
infrastructure monitoring.

## Non-goals

This decision does not implement reporting tables, projections, APIs, workers,
exports, dashboards, an Insights UI, a report builder, arbitrary SQL, a BI
engine, a custom formula language, or a business reporting model. It does not
create a numbered phase, alter K5 ordering or status, change R0, promote RMA,
or pull reporting ahead of current work. It does not turn UTCP into a dialer,
CRM, contact-center BI, workforce-management, or revenue product.

## Roadmap classification

Operational Reporting & Insights is a future UTCP core capability, not a
current phase and not an R0 gate. It does not block K5, RMA, or A0. Its first
implementation must be separately bounded and evidence-backed. Current K5
ordering and the exactly-one-next K5C controlled natural live-proof action are
unchanged.

## Related authority

- [`ADR-023`](ADR-023-canonical-call-lifecycle-and-call-control-authority.md)
  defines canonical Call/CallLeg lifecycle and normalized observations.
- [`ADR-024`](ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md)
  defines Kubernetes fact authority and UTCP telephony interpretation.
- [`ADR-029`](ADR-029-recording-media-artifact-and-archive-authority.md) defines
  the separate technical RMA boundary.
- [`ADR-032`](ADR-032-canonical-management-authority-and-break-glass-cli-boundary.md)
  defines Web Admin/API management authority and the CLI boundary.
