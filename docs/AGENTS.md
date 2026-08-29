# UTCP Documentation and Evidence Instructions

These rules refine root `AGENTS.md` for architecture, ADRs, roadmap,
program-control, runbooks, evidence, and status material.

Use the canonical source for each claim: `docs/architecture/` and
`docs/decisions/` establish ownership and accepted design; the phase-status
ledger establishes current status and next action; program-control documents,
milestone ledgers, authority maps, and debt registers govern their declared
scope; `docs/evidence/` records concise sanitized proof at a point in time;
runbooks describe approved operations and environments.

Resolve conflicts using current accepted ADRs, current status, and implemented
behavior as applicable. Do not create duplicate roadmaps, competing status
ledgers, or unsupported architecture claims. Use precise terms: implemented,
source-verified, tested, live-proven, blocked, deferred, historical,
environmental issue, or proof defect. A test is not live proof, an API success
is not runtime readiness, and historical evidence does not prove current state.

Historical evidence is immutable in meaning. Do not rewrite an old report to
claim later knowledge, behavior, topology, or success. Add a dated superseding
document or correction with links. Record repository identity, date,
environment, scope, commands, sanitized observations, and supported claim when
relevant. Never record credentials, customer data, private hostnames, raw
dumps, sensitive captures, or noisy logs.

Keep AI instruction files behavioral and concise; link to architecture/history
instead of copying it. Update the canonical document when a code change alters
an accepted contract, roadmap status, runbook, or proof result. Review links,
terminology, dates, phase markers, and status consistency before handoff.

## Current-state evidence impact

Prospectively, every new or modified file under `docs/evidence/` MUST contain
exactly one declaration: `Current-State-Impact: yes` or
`Current-State-Impact: no`. `yes` means the evidence changes authoritative
current phase, gap, blocker, topology, proof classification, closure,
deferred state, or next action; `no` means it is historical, supporting,
diagnostic, or proof detail without a current-state change. A `yes` evidence
change MUST reconcile `docs/roadmap/phase-status.md` in the same bounded diff.
Untouched historical evidence is not retroactively migrated.
