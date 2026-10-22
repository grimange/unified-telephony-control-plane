# PostgreSQL authority, dispatch reservations and observation receipts

Status: proposed implementation contract, 2026-10-21.
Parent: [pivot report](../UTCP_GO_NATIVE_CONTROL_PLANE_ARCHITECTURE_PIVOT_AND_MIGRATION_PLAN.md).
This specifies future Go work; none of these new reservation/receipt tables or
protocols is implemented by this architecture task.

## Reuse the proven schema

Keep `runtime_operations` as the single operation engine, and preserve
`infrastructure_incarnations`, `infrastructure_intents`, C0 audit, outbox, inbox,
idempotency and domain tables. Go stores implement the current transitions and
constraints, including append-only audit triggers and retained terminal history.
Do not silently convert char(32) identifiers to PostgreSQL UUIDs, JSON columns to
JSONB, timestamps to a different timezone interpretation, or status strings to new
enums. Capture the actual catalog, indexes, constraints, triggers, functions,
views, grants and capability seed data in compatibility tests.

Incarnations remain `preparing → bound → retiring → retired`; binding is one-way,
including cleanup binding while retiring where the existing service permits it.
Physical names never repeat; a replacement requires a new incarnation. Keep
RuntimeNode configuration versions distinct from infrastructure generations.
Current services and migration are behavioral oracles, not permanent PHP callers:
[canonical service](../../../../apps/api/app/ControlPlane/InfrastructureAuthority/CanonicalInfrastructureService.php),
[additive migration](../../../../apps/api/database/migrations/2026_10_21_100000_create_infrastructure_incarnation_authority.php),
[operation repository](../../../../apps/api/app/ControlPlane/RuntimeOperations/RuntimeOperationRepository.php).

Use `READ COMMITTED` plus the existing consistent lock order: RuntimeNode,
incarnation, intent, operation. Lock all relevant rows before checking lifecycle,
tenancy, attempt, expiry and supersession. Generation allocation serializes on
the incarnation row; commit generation, approved fingerprint, operation/audit and
outbox changes together. No network effect inside a transaction. Queries compare
lease timestamps against database time; inject a clock only in deterministic
unit tests. Revalidate operation completion using exact attempt/token fences.

Canonical generations are decimal strings in API/contracts and signed PostgreSQL
BIGINT internally: `1..9223372036854775807`. Parse exactly; no floats, leading
zeros, exponent, negative or truncation. Overflow rejects allocation without
partial state. Fixtures above JavaScript's safe integer bound must round-trip.
The current v1/v2/v3 Go validators also reject values above signed BIGINT; no
canonical persistence or contract-range expansion is proposed.

Use a bounded pgxpool per role with connection lifetime/timeouts and readiness
checks. Commit or roll back every transaction even on context cancellation;
[pgxpool documentation](https://pkg.go.dev/github.com/jackc/pgx/v5/pgxpool) explicitly
requires transaction completion. Retry SQLSTATE `40001`/`40P01` only for bounded,
pure database transactions before external dispatch. On ambiguous commit, query
by idempotency key rather than repeat side effects. Do not retry generic unique
violations or arbitrary errors as serialization conflicts.

## Single operation history, explicit dispatch evidence

Add **dispatch-attempt evidence**, not a competing operation engine. Proposed
`infrastructure_dispatches` references existing intent and operation IDs and
records exact attempt, fencing-token digest, action-envelope SHA-256, executor
identity, reservation nonce digest, ordinal, expiry, state, timestamps and safe
result/receipt references. Use a unique `(operation_id, attempt, dispatch_ordinal)`
and a partial unique index allowing at most one unresolved ordinary
reservation/dispatch per incarnation. A separate, at-most-one recovery-barrier
slot is the only exception, as defined below. Keep attempts/history after supersession. New table fields must
be justified by these invariants in CP1; reuse existing intent outcome fields.

Version the private control protocol independently as
`utcp.dispatch.v1`; do not alter existing v1/v2/v3 action fingerprints. A canonical
byte representation binds the **entire** action envelope (including action,
claim, UID, resourceVersion and deadline), separately from the existing semantic
intent fingerprint. Lease tokens and reservation capabilities never enter logs,
audit/outbox payloads or public API results.

### Reserve, consume, execute, resolve

1. **Reserve:** kernel transaction locks canonical rows, verifies the complete
   current persisted intent, v1/v2/v3 policy, operation attempt/token/expiry,
   deadline, incarnation and generation. Reject suspended/unbound activation,
   retirement holds, unsupported FreeSWITCH domain fencing/restoration and any
   unresolved prior dispatch unless this is the narrowly authorized higher-
   generation recovery-barrier class described below. Insert `reserved`, random 256-bit capability digest,
   exact envelope hash, executor certificate SAN and expiry bounded by claim and
   request deadline. Commit audit/outbox with the reservation. An identical
   retry returns its reservation identity/status, never a second dispatch right.
2. **Submit:** canonical worker sends the exact action + reservation ID and
   capability over mTLS to IO. No PostgreSQL credentials reach IO. IO validates
   schema, trusted caller, deadline, bounded action and immutable target locally.
3. **Consume:** immediately before Kubernetes dispatch IO calls the kernel's
   private consume seam. A transaction rechecks **all** authority and the current
   executor identity/envelope hash, atomically CASes `reserved → dispatching`,
   writes durable pre-dispatch evidence, and returns one short-lived dispatch
   grant tied to that exact action/executor. This is not cached positive claim
   authorization. A repeated consume returns status/observe-first, never another
   grant, even if the original grant response was lost.
4. **Execute:** IO validates the grant and makes at most one external dispatch
   for that grant. The old claim callback response is closed (`contract_version`, `authorized`);
   consume/grant is a new injected transport seam, never extra fields added to
   that response. Existing UID/RV/marker conditions stay inside one parent
   Deployment patch; delete keeps preconditions; create keeps inertness and
   never-reused physical name. No HTTP transport automatic mutation retries.
   A process crash after consumption is conservatively uncertain even when no
   request may have left the host. This trades availability for safe recovery.
5. **Resolve:** executor submits a correlated safe result. Kernel records exact
   reservation/attempt history transactionally; stale results cannot finish a
   newer operation. Confirmed application requires matching evidence. Transport
   interruption/cancellation after possible dispatch records `outcome_unknown`
   and `observe_first`, not terminal failure. API acceptance of delete means
   pending until authenticated observation confirms absence.
6. **Recover:** fresh observation receipts classify exact identity/effect.
   Recheck current authority under locks before allocating a new dispatch ordinal.
   Observation never grants authorization. If unresolved, retain uncertainty.
   Expired leases may be reclaimed for recovery, but cannot blindly redispatch.

If reserve commits but no consume occurs, cancellation/expiry may release the
reservation only by an atomic state transition that makes future consume fail.
If consume commits but its response is lost, the caller cannot safely infer
no dispatch: observe first. If result commit is ambiguous, query the dispatch ID.
Reservation status queries reveal no bearer capability. An authenticated new
worker may recover the same canonical work, not acquire the old consumed grant.

### Distributed consistency model

Retain **generation-barrier conditional convergence**, not a PostgreSQL–Kubernetes
transaction or exactly-once external execution. Before consume, expired claims
are rejected. After dispatch, lease expiry cannot retract a request. An older
request with stale resourceVersion/markers cannot overwrite an installed newer
barrier. An older request arriving before the newer barrier may temporarily apply,
even after its database lease expired; subsequent current canonical convergence
must repair it. Unchanged resourceVersion does not prove a lease remains valid.
Do not infer resourceVersion ordering numerically; it is opaque.

One unresolved ordinary dispatch per incarnation bounds duplicates; it cannot establish
that every old network request has disappeared. Fresh reads can precede a delayed
write. Therefore reissue only with current authorization plus Kubernetes barrier
conditions, and retain old dispatch history until accounted for. Exact harmless
late-create containment requires inert whole-template creation, terminal physical
identity and cleanup-only recovery. Permanent external-writer cutoff and quiescence
remain cutover requirements; no local mutex proves cross-process exclusivity.

### Recovery progress without falsely resolving old requests

An unknown consumed request cannot be declared not-applied solely from a fresh
GET. Blocking every newer request until that declaration would deadlock safe
convergence. Therefore permit **one recovery-barrier reservation** alongside the
unresolved ordinary slot only for an already-bound exact UID. It must reference
all blocked dispatch IDs, carry a freshly allocated **strictly higher canonical
generation**, and pass the complete current policy/claim check. Its authorized
existing action must atomically install the newer barrier with any intended
bounded effect (`admit_generation`, current `converge_projection`, or retirement
barrier semantics already supported by the appropriate version). Do not invent
an arbitrary annotation operation or reuse a v1 fingerprint as a v3 intent.

After exact observation and current authorization, dispatch with the observed
resourceVersion and marker predicates. If an old write wins first, CAS fails:
retain uncertainty, reobserve and obtain a new reservation; never auto-refresh
and replay. If the recovery barrier commits first, all previously prepared
conditional UID/RV mutations against that old version are fenced. Confirm the
new barrier through an authenticated receipt; mark older intents superseded and
their effects externally contained, retaining their historical unknown delivery
outcome. Only then release the ordinary slot for fresh work. A lost recovery
response leaves its own slot uncertain; resolve by exact barrier observation,
not a second concurrent barrier dispatch. Concurrent new intent uses the same
serialized recovery coordinator, not an unbounded chain of exception slots.

This exception cannot contain a create POST that targets an absent physical name.
For unknown creation, retain the old never-reused name/tombstone and cleanup-only
policy; a replacement may use a new incarnation only after canonical retirement
and its relevant unknown effects are accounted for. A late object is inert and
requires authorized exact-identity cleanup. If outstanding delivery cannot be
bounded, retain the unresolved record and require scoped request/credential
quiescence rather than claiming confirmed absence. No implicit timeout proves
that every packet was drained. CP1 must test both the progress exception and this
fail-closed create limitation. No production cutover follows from this design.

## Authenticated observation receipts

Introduce a separate closed protocol `utcp.observation-receipt.v1` for exact
observations. Existing bulk Node/Pod snapshots remain visibility data, not binding
or absence authority. A trusted observer/IO identity issues a receipt only from
its own configured-cluster Kubernetes client. A browser or caller cannot submit
an arbitrary signed-looking object to bind a UID.

Envelope: issuer/key ID, random receipt ID, one-use challenge/request nonce,
observation purpose, canonical intent/dispatch ID and envelope hash, tenant/node
correlation from the trusted request, cluster/namespace/name/incarnation, expected
and observed UID, opaque resourceVersion, ownership chain, admitted/applied markers,
controlled projection/effect digest, exact-read outcome, completeness, acquisition
start/finish, expiry, and evidence source. Include Pod/ReplicaSet chain and provider
health only for purposes that require them; do not label Pod Ready as telephony
ready. No Secret payloads, token data or raw resource objects.

Use mTLS identity plus Ed25519-signed deterministic envelope bytes (standard Go
crypto) for durable source authenticity. Kernel config maps key ID and certificate
SAN to allowed cluster/scope/purpose; overlapping rotation keys have explicit
validity/revocation. A signature proves issuer provenance, not current desired
policy or the truth of an untrusted input. On receipt ingestion verify signature,
nonce/purpose, trusted scope, full immutable binding, complete result and current
canonical state. Persist the receipt and resulting transition in one transaction.
Unique `(issuer, receipt_id)` and challenge consumption make exact duplicates
idempotent and reject reuse for another intent. Store safe envelope/hash and
acceptance reason for audit; no bearer secrets.

For binding/recovery/retirement authority use an exact fresh GET initiated for
that challenge; default maximum evidence age **30 seconds**, issuer clock-skew
budget **5 seconds**, and a kernel-issued challenge deadline. These are proposed
CP1 defaults, configurable only within reviewed policy, not changes to the bulk
observation v1 freshness contract. Reject incomplete/error/stale evidence. Do not
compare resourceVersions as integers or accept an older request's receipt merely
because its wall-clock timestamp is later.

| Purpose | Required evidence | Rejected shortcut |
| --- | --- | --- |
| Bind preparing UID | Exact name/ownership/incarnation, actual UID, current create intent; one-way transaction | Matching name or caller-supplied UID |
| Confirm projection | UID/incarnation, expected markers, controlled fields, replicas and current intent | Replica equality alone |
| Recover unknown create | Exact physical identity + creation projection/ownership; retiring means cleanup only | Existing similarly named object |
| Confirm deletion | Authoritative exact GET `404` in trusted scope, all relevant in-flight dispatches accounted for | DELETE 2xx, list omission, `403`, finalizer/deletionTimestamp |
| Runtime readiness | Separate current provider/connection-epoch evidence correlated to bound runtime plus applicable Pod facts | Kubernetes admission or Pod Ready alone |

The kernel may accept absence while retaining a tombstone and unresolved delayed
inert-create history; it must not falsely mark all dispatches settled. Terminal
retirement must preserve a cleanup path for a late physical object, never an
activation permission or reuse of its name.

## Outbox and restart compatibility

Reuse C0 event envelopes, inbox consumer dedup keys, lease/fence checks and
transactional audit/outbox. Preserve public event payloads and after-commit
semantics; delivery is retryable, not exactly-once external notification.
Lifecycle/reservation/receipt acceptance and their events commit together.
Rollback produces neither success audit nor success outbox. Redact before insert.
On restart reconstruct unresolved work from PostgreSQL; Redis loss cannot erase
claims, reservations, receipts or canonical outcomes.

## Schema migration authority

Select [Goose SQL migrations](https://github.com/pressly/goose), one DDL owner.
Laravel migration history remains immutable and retained. CP1 uses Laravel's
reviewed migrations only to construct disposable compatibility databases; Go
never invokes them automatically on service startup.

Before handover: generate a reviewed SQL baseline from the actual migrated
PostgreSQL catalog, preserve functions/triggers/views/grants and seed/version
contracts, compare both construction paths, and verify existing database schema
fingerprints. An existing database acknowledges the baseline in the Go migration
ledger **without replaying CREATE statements** only after verification. Keep the
Laravel ledger for provenance. Missing/extra objects fail adoption, not silent
repair. New additive reservation/receipt SQL belongs to the single reviewed
migration sequence; test forward and compatibility paths.

During transition freeze concurrent DDL, use a migration advisory lock, stop old
migration jobs and remove their DDL credentials. A new Goose lock alone does not
constrain Laravel. Ordinary roles cannot migrate. Expand/contract only while both
readers need compatibility; do not drop old columns until rollback expiry and
independent acceptance. Rollback retains audit/incarnation/reservation history;
no destructive down migration after durable use. Production migration/backfill
requires separate authorization and backup/restore proof.

Legacy RuntimeNodes are not automatically incarnated. Future adoption freezes
all five Deployment writers, drains requests, verifies exact UID/ownership and
unknown outcomes, then explicitly adopts reviewed physical identities or creates
new incarnations. Never fabricate UIDs or rename history. Preserve the existing
configuration-publication limitation: image digests must be verified; immutable
Secret/ConfigMap contents need publication identity. A hash of a mutable reference
is not content proof. The fixed v3 ConfigMap name may require a versioned profile
extension before production immutable publication.

## CP1 acceptance scenarios

Use independent PostgreSQL connections and deterministic barriers for allocation,
binding, retire-versus-reserve, consume-versus-supersede and duplicate consumption.
Kill/restart real workers at every commit/dispatch window. Test lost consume/result
responses, stale executors, attempt rollover, signed receipt replay/rotation,
wrong UID/name reuse, incomplete/old observations, exact 404 versus forbidden,
terminal retirement and late-create cleanup. Compare independent PHP/Go v1/v2/v3
fingerprints and cross-version denial decisions. Run existing regression suites;
only real Kubernetes tests prove API enforcement if that behavior changes.
