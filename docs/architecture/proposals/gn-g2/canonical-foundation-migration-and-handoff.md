# Canonical infrastructure foundation: adoption plan and next assignment

Status: planning and implementation handoff, not production authorization.
Date: 2026-10-21. The adapter ADR remains **PROPOSED / NOT ADOPTED**.
See the [implementation report](../UTCP_GN_G2_CANONICAL_LIFECYCLE_AND_CLAIM_FOUNDATION.md)
for the actual developer-verification boundary.

## Migration and adoption plan

The additive migration creates retained canonical incarnation and intent records.
It does not backfill RuntimeNodes, observe Kubernetes, adopt Deployments, change
existing operation handlers or dispatch work. Apply it only to disposable test
databases during this assignment. Production migration is separately authorized.

1. Adopt the architecture and versioned contracts explicitly before production
   service wiring. Repair the independent `runtime-engine-config-check` mismatch
   through its own focused assignment. Preserve existing V1/K5 authority.
2. Inventory all five PHP Deployment corridors: managed provisioning, managed
   workload convergence, runtime fencing, runtime restoration and managed
   deprovisioning. Record pending operations and unknown effects before moving
   any corridor. Existing operation IDs remain authoritative.
3. Prefer a new physical incarnation for replacement. Its `utcp-<incarnation>`
   Deployment name is never reused. Existing legacy names must not be rewritten
   to appear compliant, and a Kubernetes UID must never be fabricated.
4. A future legacy adoption procedure must freeze competing application writes,
   drain dispatched requests, obtain fresh exact namespace/name/UID/ownership
   observations, establish tenant and RuntimeNode identity from PostgreSQL and
   reconcile unresolved operations. Record historical physical identity and
   initialization policy under an explicitly reviewed contract extension. The
   present service intentionally does not adopt arbitrary legacy names.
5. Initialize a new incarnation at generation `1`. Preserve existing desired
   configuration versions separately; they are not automatically infrastructure
   generation values. Bind an observed UID once through an authenticated
   evidence boundary. Replacements require a new incarnation.
6. Publish digest-qualified images and immutable/versioned configuration resources
   before authorizing an active profile. A reference fingerprint binds the
   reference strings, not the current contents of a mutable Secret or ConfigMap.
   The publisher must establish content identity, namespace/ownership, immutable
   publication and rotation semantics without disclosing Secret contents.
   The current closed Asterisk v3 profile fixes the optional ConfigMap name
   `asterisk-local-sip-fixtures`; changing it to versioned publication may require
   a separately versioned profile extension. A publisher cannot substitute an
   arbitrary name through the present contract.
7. Complete durable dispatch reservation and authenticated observation receipts
   before production effects. Canonical verification currently answers a fresh
   authorization question; it does not reserve a dispatch atomically with Go.
   Unknown or attempted effects require observation and cannot be replayed by
   merely reclaiming the runtime-operation lease.
8. Writer transfer remains a separate GN-G3 authorization: all five application
   mutation paths, dedicated credentials/RBAC, credential-level PHP write cutoff,
   external replica writers/autoscalers, quiescence, unknown-outcome recovery and
   conditional-compatible rollback must be addressed together.

Rollback before production wiring leaves existing PHP behavior intact. Once
canonical history is used, retain its rows; dropping the new tables would destroy
identity and uncertain-effect evidence. Future production rollback must continue
to enforce exact UID/incarnation, generation and current claims. It must not
restore an unrestricted legacy writer while Go requests may remain in flight.

## Next assignment — ready-to-copy prompt

```text
UTCP — GN-G2: Durable Dispatch Reservation and Authenticated Observation Receipts

AI worker/coder: OpenAI Codex CLI
Model: GPT-6; reasoning: High
Role: Principal Laravel/PostgreSQL Control Plane Engineer / Distributed Systems Engineer
Execution role: IMPLEMENTER
Repository: grimange/unified-telephony-control-plane
Assignment: UTCP_GN_G2_DURABLE_DISPATCH_RESERVATION_AND_OBSERVATION_RECEIPTS

Mission
Close the demonstrated gap between fresh canonical claim verification, durable
pre-dispatch evidence and trusted Kubernetes observation results. Extend the
existing CanonicalInfrastructureService and runtime-operation authority; do not
create another operation engine. Keep all orchestration isolated from production
dispatch and writer ownership.

Required tools
Git/ripgrep, PHP 8.4/Laravel 12/PHPUnit 11, the existing disposable PostgreSQL
Docker verifier, pinned Go 1.25.8/client-go v0.35.3 SDK, JSON Schema checks,
mTLS HTTP fault injection, existing Go/PHP verification and repository checks.
No host package installation and no production credentials or databases.

Authority and baseline
Read AGENTS.md, path-local instructions, phase-status.md, the canonical foundation
report, its migration plan, proposed ADR, existing v1/v2/v3 contracts and all
relevant source. Record Git identity/status and preserve prior work. The ADR
remains PROPOSED / NOT ADOPTED. Preserve RMA-E next action and accepted V1/K5
classifications. Do not commit or push without explicit authorization.

Authorized implementation
1. Define a narrowly versioned, authenticated test-only reservation/receipt
   protocol compatible with existing Go actions. Do not silently alter normative
   v1/v2/v3 action semantics. Production HTTP routes remain unauthorized.
2. Reserve an exact persisted intent and current operation attempt transactionally
   after full-envelope validation and before a new Kubernetes dispatch. Bind the
   reservation to tenant, RuntimeNode, incarnation, UID, action, generation,
   fingerprint, lease/attempt and deadline. Repeated reservations must not grant
   competing dispatches. Go receives no PostgreSQL or Redis credentials.
3. Resolve how an existing Go pre-dispatch claim callback consumes that reservation
   without denying its own authorized dispatch or reopening blind retries. Bound
   replay/receipt semantics explicitly. PostgreSQL cannot retract a request already
   sent to Kubernetes; preserve that limitation.
4. Replace caller assertions of exact UID evidence/absence with an authenticated,
   closed, replay-resistant observation-receipt seam for binding, effect recovery
   and retirement completion. Check issuer identity, request correlation, immutable
   target, freshness, completeness, effect/markers and current canonical state.
   Do not treat a matching name or deletion HTTP acknowledgement as absence proof.
5. Preserve append-only history, stale-attempt rejection, generation ordering,
   supersession, retirement holds and C0 audit/outbox consistency. An older result
   must not resolve a newer dispatch. Uncertain effects remain observe-first.
6. Preserve Asterisk domain eligibility and existing runtime observation gates.
   FreeSWITCH domain fencing/restoration remains unsupported. Configuration resource
   publication remains separate; a reference hash must not imply content proof.

Not authorized
Production dispatch/service wiring, public callback routes, production migrations
or backfill, production databases, live Kubernetes credentials, a new cluster,
Go writer deployment, PHP writer replacement, GN-G3 cutoff, telephony traffic,
ADR adoption, host packages, commit or push.

Deliverables
Additive source and focused regressions; closed protocol schemas/fixtures;
independent PHP/Go mTLS integration; real PostgreSQL concurrency and worker-restart
proof; one deterministic pinned verifier; concise evidence report; exact migration
compatibility implications; one bounded next assignment selected from actual gaps.

Acceptance
Prove one authorized reservation per dispatch; duplicate/concurrent requests;
expired/superseded/reclaimed claims; attempt rollover; denied replay; lost callback
and mutation responses; restart before/after reservation and result commit;
retirement racing dispatch; wrong tenant/UID/incarnation; stale/incomplete/forged
observations; replacement name reuse; terminal history; atomic audit/outbox.
Use independent PostgreSQL connections and synchronization, not SQLite concurrency
claims. Run new PHP/Go integration plus existing GN-G1/GN-G2/lifecycle/profile
regressions, race/vet/module/format/schema/security checks, repository hygiene,
phase consistency and git diff --check. Keep the existing static-check mismatch
visible and separate. Report developer proof, not independent or deployed
acceptance. No writer cutover readiness claim follows from this isolated work.
```
