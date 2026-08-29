# UTCP Codex Working Contract

This is the repository-wide engineering constitution for the Unified Telephony
Control Plane. Path-local `AGENTS.md` files refine it for the API, web UI,
infrastructure, and documentation scopes; they must not weaken these rules.
Architectural detail belongs in the authoritative documents linked below.

## Mission and priorities

UTCP is a vendor-neutral telephony control plane. It owns canonical desired
state, tenant and operator policy, normalized runtime contracts, reconciliation
decisions, lifecycle coordination, health history, auditability, and operational
visibility.

The normal flow is:

```text
operator UI -> authorized API -> canonical domain services -> PostgreSQL
desired state -> automatic reconciliation/projection -> runtime adapters and
infrastructure -> observed state and operational evidence
```

Prioritize authority boundaries, tenant and lifecycle integrity, deterministic
idempotent convergence, vendor-neutral contracts, proportional proof, bounded
scope, and preservation of existing work.

## Authority before implementation

Before editing, identify the owner, current contract, implementation seam, and
acceptance proof. Use the current repository, `docs/roadmap/phase-status.md`,
applicable ADRs, and relevant evidence. The status ledger governs current
status; ADRs establish accepted architecture; evidence records what was proven
at a point in time. Historical evidence and Git history are not rewritten to
make current behavior appear retroactively correct.

Repository truth wins over stale comments, templates, and historical reports.
If authority, intended behavior, or failure meaning is materially unclear,
perform a narrow evidence audit. Once bounded, implement rather than repeating
a broad audit.

## Architecture invariants

* UTCP is Kubernetes-first for the integrated runtime. Kubernetes owns Nodes,
  Pods, scheduling, placement, restart behavior, and declared infrastructure;
  it does not own telephony business policy.
* PostgreSQL is canonical for persisted business, identity, RuntimeNode,
  operation, lifecycle, audit, and reconciliation records. Redis may provide
  queues, locks, caching, rate limiting, and transient projections, never sole
  canonical business authority.
* The Web Admin UI and authorized API are the normal management authority. CLI
  commands are limited to bootstrap, diagnostics, recovery, migrations,
  verification, and thin scheduler entrypoints.
* Desired state, reconciliation, and observed state are distinct. Request
  success, UI state, WebSocket delivery, queue publication, or a Kubernetes
  operation does not by itself prove runtime readiness or completion.
* Telephony operations are asynchronous and distributed. Persist transitions,
  retries, leases, fencing, idempotency, receipts, observations, and audit
  evidence. Reconciliation is automatic, retryable, idempotent, and convergent;
  unknown health is unavailable or degraded, not silently absent.
* Retire resources while preserving lifecycle history. Do not hard-delete
  canonical evidence to satisfy an invariant.
* The core domain is vendor-neutral. Asterisk, FreeSWITCH, Kamailio, rtpengine,
  SIP, RTP, Kubernetes, and provider identifiers belong behind established
  adapter, projection, or infrastructure boundaries.
* UTCP does not replace live authority: Kamailio owns SIP signaling, rtpengine
  owns RTP/SRTP relay, Asterisk/FreeSWITCH own telephony execution, Traefik
  owns HTTP/HTTPS/WSS ingress, and Kubernetes owns placement.
* Infrastructure identity is distinct from public, private, service, and
  provider addressing. Keep management, inter-node, ingress, SIP, RTP/media,
  and external-provider addresses separate where the architecture requires it.

See `docs/architecture/authority-boundaries.md`, relevant ADRs, and the phase
ledger for detailed authority maps and topology decisions.

## Evidence-first delivery

Classify work as bounded implementation, narrow evidence audit, controlled live
proof, or targeted blocker diagnosis. Repository tests prove code contracts;
live proof is required for deployment, Kubernetes ownership/readiness, protocol,
browser, Prometheus, failover, recovery, or external telephony claims. State
separately what is implemented, tested, live-proven, blocked, deferred, or a
proof defect. Never fabricate a test result, runtime observation, credential,
customer identity, or completion claim.

For bounded work, normally prove canonical authority, removal or cutoff of
conflicting authority, idempotent/convergent behavior, focused regression
coverage, required broader checks, and absence of a second management authority.
Classify divergences by actual impact: correctness/security defect, authority
conflict, observability gap, historical residue, expected rollout behavior,
environmental issue, unrelated pre-existing condition, or harmless timing.

## Scope and safety

Work on one phase, subphase, or defect. Do not repair unrelated roadmap work,
invent compatibility fallbacks, feature gates, hidden opt-ins, manual
reconciliation, runtime allowlists, or alternate management authorities.
Prefer existing services, lifecycle tables, adapters, scripts, Make targets,
and canonical deployment paths.

Before Kubernetes, runtime, ingress, host-networking, or live-proof mutation,
resolve the current documented environment and report its context, node, edge
address, namespace, and target. Current V1 authority is native k3s on
`utcp-dev01` at `192.168.254.124`; `utcp-local` is secondary unless explicitly
selected by the task. Never create or replace clusters, registries, volumes,
databases, namespaces, ports, or topologies to evade a block. If a scope change
is required, stop and report the fact, blocker, smallest correction, impact,
and authorization need.

Treat repository edits and in-scope disposable proof as reversible, but preserve
required state. Require stronger evidence and explicit authority for production
traffic, customer identity, external trunks, DNS, destructive migrations,
credential revocation, real calls, or irreversible data changes.

## Security, tests, and generated files

Preserve tenant isolation, authorization, stale-generation and wrong-node
guards, secret boundaries, append-only audit semantics, and redaction. Never
commit credentials, tokens, private keys, customer information, real telephone
identities, private production hostnames, raw dumps, noisy logs, or sensitive
captures.

Add a focused regression at the lowest layer that proves changed behavior, then
run the affected suite and required repository checks. Prefer pinned,
repository-defined dependencies and existing bootstrap helpers. Do not modify
generated files, lockfiles, fixtures, certificates, or runtime artifacts unless
the task owns their source or regeneration. Keep local credentials and evidence
untracked.

## Worktree, Git, and completion

Inspect status, branch, HEAD, and relevant diffs before editing. Preserve all
pre-existing modifications and untracked files exactly. Never use reset, clean,
force-push, history rewrite, or broad deletion to make the tree convenient.
Stage only task-owned paths. Do not commit or push unless explicitly required.
Before handoff run `git diff --check`, inspect staged boundaries, and report
exact commands and outcomes.

A task is done when its stated contract and proportional proof are complete, not
when unrelated Quality jobs or every theoretical edge case is green. Stop when
source contradicts the premise, a guard exposes a genuine new security or
architecture violation, authority cannot be resolved, an environment capability
is missing, or the request expands topology, persistence, security, authority,
lifecycle, or non-goals.

Every bounded implementation or proof handoff states verdict; starting/current
identity; current state; implemented/confirmed behavior; authority boundary;
files; commands and tests; failures; divergences; proof gaps; deferred work;
commit/push status; preservation; scope changes; and the smallest next action.
List only genuine operator prerequisites, or write `None.`.

## Current-state ledger reconciliation

`docs/roadmap/phase-status.md` is the sole canonical current-state ledger.
Any bounded task that materially changes a current phase, gap, blocker,
canonical topology or environment authority, implementation/test/live-proof
classification, closure or deferred state, or canonical next action MUST
reconcile that ledger in the same bounded packet. Do not defer reconciliation
to a later documentation cleanup when the task establishes the new authority;
historical evidence remains immutable in meaning.

A status-impacting task is incomplete until its supporting implementation or
evidence is complete, `phase-status.md` reflects the resulting state, and the
phase-status consistency check passes. Work that does not change authoritative
current state does not require a ledger edit merely to satisfy this rule.

Bounded implementation and proof handoffs also report:
`Current-State Ledger Impact: UPDATED` or `NO_CHANGE_REQUIRED`, and
`Phase-Status Consistency Check: PASS`.

## Canonical references

* `README.md` — purpose, topology, and product boundary.
* `CONTRIBUTING.md` and `SECURITY.md` — contribution and security baseline.
* `docs/architecture/` and `docs/decisions/` — architecture and authority.
* `docs/roadmap/phase-status.md` — current status and next action.
* `docs/evidence/` — concise historical repository and live proof.
