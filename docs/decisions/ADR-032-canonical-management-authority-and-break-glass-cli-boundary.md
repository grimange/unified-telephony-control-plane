# ADR-032: Canonical Management Authority and Break-Glass CLI Boundary

## Status

Accepted. This ADR defines the management-plane boundary for UTCP-managed
desired configuration and the permitted role of Artisan/CLI operations. It
does not change an existing product phase or runtime behavior.

## Context

UTCP has a Web Admin application, an authenticated application/domain API,
PostgreSQL-backed canonical desired state, automatic reconciliation and
projection, and Artisan commands used by bootstrap, diagnostics, workers,
observers, and recovery sweeps. Without an explicit boundary, a command that
projects or repairs state can be mistaken for a second operator-facing
management interface.

Kubernetes remains authoritative for Node, Pod, readiness, capacity,
scheduling, and placement facts. Asterisk, FreeSWITCH, Kamailio, and runtime
observers remain authoritative for their runtime facts. UTCP observes and
normalizes those facts; it does not make Web Admin an editor for them.

## Decision

### Canonical management flow

The canonical human-facing management surface is **Web Admin**. Its
authenticated and authorized application/domain API is the technical mutation
authority. The validated mutation is persisted in PostgreSQL as canonical
UTCP desired state and enters the normal automatic lifecycle:

```text
Web Admin
  -> authenticated/authorized application API
  -> domain validation
  -> canonical PostgreSQL desired state
  -> automatic reconciliation/projection
  -> runtime or infrastructure adapter
  -> observed result
  -> Web Admin rereads canonical API state
```

A successful normal configuration mutation MUST NOT require a second operator
command to reconcile, project, or synchronize it. Failures in automatic
convergence MUST be observable through established operation, observation,
health, and audit contracts; a hidden manual fallback is not part of the
normal path.

### No parallel routine CLI management plane

Artisan/CLI MUST NOT be a parallel routine configuration authority or a second
management UI. Commands are classified by semantics, not names. A command
named `reconcile`, `project`, or `sync` is permitted only when it is an
automatic scheduler/worker entrypoint or an explicit recovery operation; it
must not be a prerequisite for an otherwise successful Web Admin/API
mutation.

Normal UTCP-managed configuration includes only resources for which current
architecture grants UTCP authority, such as ExternalTrunks, TrunkEndpoints,
TelephonyAddresses, CallerIdentities, route configuration, RuntimeNode
desired state, and provider/runtime configuration. Those mutations follow the
canonical API/domain path and automatic convergence.

### Permitted CLI roles

Artisan may provide the following bounded roles:

* **Diagnostics:** read-only status, inspection, health, verification, and
  explanation commands where practical.
* **Deterministic recovery:** retrying or repairing interrupted canonical
  reconciliation, replaying canonical events, or recovering failed
  convergence through existing domain/reconciliation semantics.
* **Exceptional maintenance:** narrow operator maintenance for cases where no
  ordinary Web Admin workflow is appropriate and host or deployment authority
  is required.
* **Break-glass:** explicit, narrow, auditable, security-sensitive operations
  for exceptional access or recovery. Break-glass must not be documented as
  the easiest normal way to manage UTCP.

CLI recovery and maintenance MUST reuse canonical validation, authorization,
domain rules, persistence, audit, and reconciliation boundaries. They MUST
NOT introduce alternate configuration rules, hidden permissions, routine
manual projection, or a second desired-state store.

Bootstrap of the first local administrator and a one-time password recovery are
exceptional identity operations, not routine management UI. Likewise, a
future K5F host-enrollment bootstrap may require a privileged local command on
the new machine: Web Admin creates the authorized short-lived enrollment
intent, the operator runs the bounded local bootstrap, and Kubernetes creates
the authoritative Node. That one-time host action is not a permanent CLI
management plane and does not give UTCP authority over Kubernetes Node facts.

### External facts and environment configuration

The management boundary does not transfer external fact authority to UTCP:

* Kubernetes remains authoritative for Kubernetes infrastructure and workload
  facts; K5A Hosts is read-only observation.
* Telephony runtimes and signaling systems remain authoritative for runtime
  observations; UTCP normalizes and correlates them through existing
  contracts.
* UTCP remains authoritative for its own persisted desired configuration,
  lifecycle, policy, operations, observations, and audit records.

Environment variables remain appropriate for deployment, bootstrap, and
runtime-process configuration where the current architecture requires them.
They MUST NOT become hidden feature gates or routine substitutes for
product-managed desired state.

## Consequences

* Operators have one normal human management surface and one authorized
  technical mutation path.
* Automatic reconciliation and projection remain the normal convergence
  authority; manual commands are not part of successful configuration flow.
* Read-only infrastructure views can present Kubernetes facts without making
  UTCP a Kubernetes management console.
* Existing workers, observers, scheduled sweeps, diagnostics, bootstrap, and
  recovery commands remain valid when their semantic role stays within this
  boundary.
* Any future command that appears to manage product configuration must either
  defer to the authenticated API/domain authority or be explicitly justified
  as exceptional recovery/maintenance with audit and least privilege.

## Non-goals

This ADR does not refactor or delete existing commands, add API endpoints,
change UI controls, alter authorization implementation, implement K5B–K5F,
change V1 behavior, or replace Kubernetes/runtime authorities.
