# Operational Reporting & Insights Product-Boundary Alignment

**Date:** 2026-08-30
**Starting HEAD:** `c5ca2d08990a15e96ded2e14e45291c904377ea9`
Current-State-Impact: no

## Scope

This documentation-only side quest establishes a durable product boundary for
future Operational Reporting & Insights. It does not implement reporting or
change the active K5 track.

## Existing boundary resolved

The initial implementation plan already defines UTCP as the platform beneath
telephony applications and assigns campaigns, lead lists, pacing,
contact-center workflows, dispositions, application retry/outcome policy, and
business reporting to consuming applications. Existing ADR-023 Call/CallLeg,
ADR-024 infrastructure/Kubernetes, ADR-029 RMA, and ADR-032 management
authority provide the technical, observed-state, recording, audit, and
management boundaries strengthened here.

## Durable principle

UTCP owns vendor-neutral operational telephony, infrastructure, lifecycle,
control-plane, and audit reporting derived from canonical UTCP state and
authoritative external observations. Reports are derived read models, not
canonical authority. Business reporting—including campaigns, leads, agents,
dispositions, conversion, and revenue—belongs to applications consuming UTCP.

## Authority and scope

Future reports may cover normalized technical call outcomes, timing, routes,
trunks, RuntimeNode lifecycle/readiness/load, capacity exclusions, placement,
reconciliation, failover/recovery, selection explanations, audit, and later
technical RMA lifecycle. They must remain vendor-neutral and consume canonical
lifecycle/history, audit, normalized observations, and Kubernetes-owned facts
through established UTCP observation boundaries.

Reports must not write back into Calls, CallLegs, RuntimeNodes, routes, trunks,
caller identities, recording artifacts, Kubernetes facts, readiness, placement,
policy, or configuration. Selection explainability must use durable canonical
decision/audit facts rather than independently replaying policy.

Campaigns, leads, agent/business performance, dispositions, sales, revenue,
conversion, appointments, customer outcomes, workforce productivity, and
business funnel analytics remain application-owned. Technical agent/session
identity facts, queue/ACD technical lifecycle, and RMA technical facts retain
their existing boundaries; business interpretation remains outside UTCP.

## Architecture and product direction

The preferred future flow is canonical PostgreSQL state plus normalized
events/history, audit, and authoritative external observations into
deterministic PostgreSQL-backed read models and a reusable reporting/read API.
The modular monolith remains the default. No reporting microservice, analytics
warehouse, mandatory external analytics technology, report builder, export
implementation, worker, table, or API is introduced.

Operational management screens remain current-state views. A future `Insights`
area may present historical, aggregate, trend, and forensic views, and a future
read API may serve Web Admin and external consuming applications. Reverb, if
used later, remains notification-only. Tenant isolation, authorization,
privacy, and retention requirements continue to follow source authority and
ADR-029 where applicable.

Operational reporting complements rather than replaces metrics, logs, traces,
health, alerts, and diagnostics.

## Roadmap and preservation checks

- Operational Reporting & Insights is future UTCP core, not a current phase and
  not an R0 gate.
- K5 ordering is unchanged.
- K5C remains `IMPLEMENTED_AND_TESTED / NATURAL_LIVE_PROOF_PENDING`.
- The exactly-one-next action remains controlled natural K5C proof on canonical
  native k3s.
- R0, RMA, and A0 boundaries are unchanged.
- Production code, migrations, APIs, UI routes/components, workers,
  permissions, runtime configuration, CI, and deployment are unchanged.

## Documents aligned

- [`ADR-033`](../../decisions/ADR-033-operational-reporting-insights-and-business-reporting-boundary.md)
  is the canonical durable decision.
- The initial and application implementation plans now state the technical
  reporting versus business-reporting boundary.
- The executable roadmap and UI foundations reserve the future capability
  without creating a phase or implementation claim.
- The current phase ledger only records the capability in its deferred/future
  track list; K5C status and next action are unchanged.
