# Canonical Management Authority and Artisan Boundary Alignment

Current-State-Impact: no

## Scope and repository identity

This bounded documentation packet started from `main` at
`80849890baa841040e8cc13376329773cc50ca89` (`docs(k5): close host visibility
live proof`). The tree was clean and local `main` matched `origin/main`.

The packet establishes durable authority language only. It does not refactor
commands, add product endpoints, change runtime behavior, or start K5B.

## Authority decision

UTCP's normal management flow is:

```text
Web Admin
  -> authenticated/authorized application/domain API
  -> PostgreSQL canonical desired state
  -> automatic reconciliation/projection
  -> runtime or infrastructure adapters
  -> observed result and canonical API reread
```

Web Admin is the primary human-facing surface, but it is not the data
authority. The authenticated application/domain API is the technical mutation
authority, and PostgreSQL-persisted UTCP desired state is the configuration
authority. A successful configuration mutation does not require a routine
Artisan reconcile, project, or sync command.

Kubernetes remains authoritative for Node, Pod, readiness, capacity,
scheduling, and placement facts. Asterisk, FreeSWITCH, Kamailio, and runtime
observers remain authoritative for their runtime facts. K5A's Hosts surface
therefore remains read-only observation rather than editable UTCP host state.

## Artisan inventory

The relevant UTCP-owned command surface in `apps/api/routes/console.php` was
classified by behavior rather than command name:

| Classification | Commands | Current purpose |
| --- | --- | --- |
| BREAK_GLASS / exceptional initialization | `identity:bootstrap-local`, `utcp:user-access:reset-password` | First local platform administrator bootstrap and explicitly reasoned temporary password recovery. |
| DIAGNOSTIC | `identity:status`, `runtime-registry:status`, `runtime-engine:infrastructure-probe --once`, `runtime-engine:status`, `asterisk-ari:status`, `simulator:status`, `telephony-domain:status` | Bounded status/count/probe reporting; the infrastructure probe is explicitly read-only and requires `--once`. |
| AUTOMATIC WORKER / observer entrypoint | `runtime-engine:outbox-dispatcher`, `runtime-engine:command-worker`, `runtime-engine:infrastructure-worker`, `runtime-engine:event-normalizer`, `runtime-engine:reconciler`, `kamailio-registration:observer`, `simulator:event-source`, `asterisk-ari:events`, `freeswitch-esl:events` | Scheduled or process-owned automatic dispatch, observation, normalization, and reconciliation. These are not routine operator management steps. |
| AUTOMATIC RECOVERY / MAINTENANCE SWEEP | `runtime-engine:derive-stale-observations`, `runtime-engine:prune-conference-recovery-metric-events`, `telephony-domain:expire-sessions`, `telephony-domain:expire-recoverable-participants`, `telephony-domain:failover-coordinator`, `telephony-domain:retire-closed-bindings`, `telephony-domain:reclaim-orphan-participant-channels` | Scheduled deterministic lifecycle, stale-observation, retention, failover, retirement, and bounded recovery sweeps. They use existing services and are not manual configuration authority. |
| AUTOMATIC TARGET RECONCILIATION | `simulator:ensure-targets`, `asterisk-ari:ensure-targets`, `freeswitch-esl:ensure-targets`, `telephony-domain:ensure-targets` | Scheduled target convergence for existing canonical records; they do not create a second desired-state authority. |
| UNRELATED | `inspire` | Framework/demo command, outside UTCP management authority. |

No command in this focused inventory is documented or implemented as a
routine second Web Admin management UI. The bootstrap and password-reset
commands are exceptional identity operations; worker and sweep commands are
automatic lifecycle entrypoints; status/probe commands are diagnostics.

## Follow-up rule

No immediate CLI authority cutover is required by this inspection. If a future
command is proposed to create, edit, enable, disable, project, or synchronize
UTCP-managed configuration, its bounded implementation must route through the
authenticated API/domain and canonical reconciliation authority, or explicitly
document a narrow auditable break-glass/recovery purpose. This is an exact
semantic rule, not a command-name prohibition.

## Explicit non-goals

This packet does not modify command implementations, add or remove commands,
add environment gates or manual enablement, add Admin UI controls, change
authorization, alter K5A's read-only host view, implement K5B–K5F or A0, or
change completed V1 behavior. The current phase ledger and exactly-one-next
action remain unchanged: K5B — Telephony Placement Awareness.

The durable decision is recorded in
[`ADR-032`](../../decisions/ADR-032-canonical-management-authority-and-break-glass-boundary.md).
