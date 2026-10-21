# Proposed ADR: bounded Go Kubernetes infrastructure adapter

## Status

**PROPOSED / NOT ADOPTED — revised 2026-10-21 by GN-G0.** No accepted ADR number
is reserved. Option B is accepted for planning; only explicit operator adoption
can change accepted architectural authority. This proposal grants no deployment,
cluster access, schema migration, writer cutover or telephony behavior change.

Assignments: `UTCP_GO_NATIVE_INFRASTRUCTURE_MIGRATION_READINESS_E1` and
`UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF`.

## Context and evidence

The historical [E1 assessment](UTCP_GO_NATIVE_INFRASTRUCTURE_MIGRATION_READINESS_E1.md)
selected a bounded adapter seam. [GN-G0](UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF.md)
adds concrete [v1 schemas and contracts](gn-g0/contracts/v1/README.md), caller and
credential inventory, PHP fixture compatibility and a reproducible isolated Go
experiment. Its SDK container uses Go 1.25.8 and client-go v0.35.3; unit/fake/HTTP
simulation, race and vet checks pass. These are not real API-server concurrency
or deployed acceptance. E1's unavailable-host-Go evidence remains historical.

[ADR-001](../../decisions/ADR-001-modular-monolith.md) requires a separately
adopted extraction decision and operational proof. Existing PostgreSQL claim
tokens fence canonical transitions; they cannot retract in-flight external I/O.
Current PHP Deployment mutation is unconditional after pre-read. All five
application operation corridors share that client: provision, converge, fence,
restore and deprovision. A scale-only migration would leave a competing writer.

## Proposed decision and authority

Retain Laravel/PostgreSQL as the sole tenant authorization, RuntimeNode identity,
telephony policy/desired state, operation, durable claim/lease/fence,
reconciliation, audit and observation-persistence authority. Redis stays
transient. Asterisk/FreeSWITCH and existing runtime adapters retain protocol
execution and readiness authority.

Introduce a bounded internal Go Kubernetes adapter using direct client-go lists
and explicit conditional effects. Kubernetes owns UID/resourceVersion, Nodes,
Pods, declared resources and placement facts. Go has no business database,
Redis credentials, durable queue, public management endpoint, autonomous desired
state or tenant policy. No controller-runtime manager, informer cache, Operator,
CRD, Kubernetes leader lease or full backend rewrite is approved.

Logical repository ownership: the GN-G0 module under `experiments/` remains a
non-production proof. GN-G1 may propose a dedicated isolated Go source module
with explicit platform ownership and contract CI; it is not automatically added
to production builds/manifests. API/domain owners retain PHP projections and
policy. Platform/security owners must be assigned for the future adapter,
ServiceAccounts, certificates, cluster mapping and deployment lifecycle.

## Transport, versioning and trust

Select internal **HTTPS JSON RPC with mTLS**, closed JSON Schema 2020-12 contracts
and exact `utcp.infrastructure.v1` version. Observation and action endpoints are
separate roles; Go verifies SAN/EKU, fixed configured cluster/namespace scopes,
strict decoding, deadlines and size/concurrency limits. Unsupported fields,
versions/actions and peer roles fail closed. Breaking or additive field changes
need explicit negotiation/versioning because v1 rejects unknown properties.

Laravel alone authenticates tenants, resolves RuntimeNode ownership and
projects cluster facts into tenant-safe responses. An action carries operation,
request, tenant/RuntimeNode and canonical slug, target/incarnation, cluster/ns/name,
UID/RV, execution generation/hash, idempotency key, claim token/expiry, deadline
and audit/correlation IDs. A fixed mTLS claim-verification callback validates the
entire action against current PG intent immediately before dispatch. Go has no
direct PG access; a positive callback is not an atomic PG/Kubernetes fence.

PHP submits bounded synchronous RPCs from the canonical worker, records safe
results/uncertainty and completes only through the current fenced PG claimant.
Go cannot independently complete operations. No retry queue or second consumer
is introduced. Preserve the richer normalized outcome alongside current PHP
exception compatibility reasons; CAS conflicts need canonical re-observation,
whereas existing terminal Conflict handling is insufficient by itself.

## Observation contract

Start with complete paged direct Node and Pod collections, one configured Pod
namespace or cluster-wide scope. Preserve opaque continuation and per-list RV,
reject repeated tokens/malformed identity/changed RV, and restart an entire
expired acquisition at most once. No atomic cross-resource snapshot is claimed.
Incomplete acquisition returns null facts and explicit unavailability, never an
empty healthy cluster. Preserve collection timestamps, established 300s freshness,
last-known stale facts and canonical per-scope ordering. Laravel owns joins,
placement policy, persistence and scheduling decisions.

Normalize tri-state readiness, nullable topology/placement, safe host capacities,
addresses/taints/conditions, allowlisted labels and direct controller identity.
Node/Pod observation does not establish a verified Deployment/ReplicaSet/Pod UID
chain. Omitting arbitrary Node labels is an intentional proposed safe-projection
change; GN-G1 must prove required policy facts and full public/tenant parity or
obtain a reviewed contract amendment. No Secret objects/raw manifests/messages,
container environment, tokens or upstream error bodies appear in output/logs.

## External-write consistency and lifecycle

Choose **generation-barrier conditional convergence**. PG serializes target
execution generations; changed intent or explicit writer handoff/supersession
allocates a newer generation, even with unchanged desired effect. Business
operation/idempotency identity is preserved; the execution key/hash changes.
Ordinary retries retain generation/hash/execution key. This is distinct from
Kubernetes metadata.generation, claim tokens and attempt counts. Laravel derives
effective replicas from canonical intent and unresolved fence/lifecycle holds;
a convergence template's default replicas=1 cannot release a fence. Only admitted
canonical restoration can do so, irrespective of a newer execution generation.

Admission patches test exact UID/RV and atomically set admitted generation/hash.
Effects test UID/RV plus admitted generation/hash and atomically write the
bounded field and applied generation/hash. Matching admission alone is not
success; current effect plus applied markers establishes observed satisfaction.
Reject higher generations, equal-generation different hashes and ownership
mismatch. A refreshed RV requires fresh identity/generation/PG authorization.
Use parent Deployment JSON Patch, not unconditional scale/merge patch or SSA
force. Real server predicate/error behavior must be verified in GN-G2.

After a newer barrier commits, an older **conditional prepared** action cannot
overwrite it under verified API semantics and exclusive-writer assumptions.
Before that barrier, an already sent action may apply after PG lease expiry;
RV alone does not encode database lease time. Hold eligibility and reconcile.
No atomic PG/Kubernetes transaction, exactly-once transport or rollback of sent
I/O is promised. Post-dispatch connection loss/cancellation/ambiguous 5xx is
`outcome_unknown`; inspect exact UID/markers/effect before reauthorization or
retry. Only current PG authority may finalize canonical state.

v1 actions intentionally cover admission and absolute replicas on a bound
Deployment. Create/projection/delete are rejected until fixed versioned lifecycle
extensions and real API tests cover every application corridor in GN-G2.
Physical target names include never-reused incarnations; bind UID one-way;
labels include canonical ownership but are not tenant authorization. Preserve
existing part-of/RuntimeNode-slug ownership and Deployment-instance correlation.
Replacement needs a new name/incarnation, not automatic UID re-adoption.

Future creation must start at replicas=0, bind UID in PG, then separately admit
and activate the current intent. POST races require exact inspection. A delayed
POST may recreate a retired name as an inert zero-replica orphan; tombstones and
GET absence cannot prevent that physical residue. Terminal retirement, drain,
zero/owned-Pod predicates and UID+RV DELETE preconditions precede deletion.
Unknown CREATE/DELETE remains uncertain; observed absence is not proof that a
sent CREATE cannot arrive. Strict physical nonresurrection or strict expiry-time
exclusion requires a separately proposed target-side enforcement mechanism.

## Exclusive writer transfer and rollback

GN-G3 must transfer **all application-level Deployment mutation paths together**,
including create/projection/deletion and scale. PHP may retain separately bounded
Service/Secret and Node-maintenance authority; Go does not inherit that broad
Role. Platform apply/recovery tools retain their authorized platform scope, but
must not overwrite adapter-managed fields during a target corridor. Resolve
fixed telephony fixture ownership explicitly.

First transfer additionally requires a **server-confirmed legacy-request drain**:
today's already accepted unconditional PHP mutations can cross a newer barrier.
Quiesce all legacy dispatch/retry sources, revoke effective writes and correlate
complete server audit request/terminal-response evidence with every accepted
legacy mutation; inspect final/deleting target and child state. GN-G2/GN-G3 must
prove audit coverage and terminal handler semantics. Revocation, worker exit,
timeout or a current GET alone cannot prove drain. Without that evidence,
same-incarnation transfer is prohibited; a separately reviewed isolation plan
must also contain legacy create-at-one resurrection and telephony effects.
Establish an exclusively conditional-compatible baseline before transfer.

Then quiesce canonical dispatch, resolve unknown attempts, remove old effective
Deployment create/update/patch/delete and scale update/patch privileges plus
credential mounts/grants, and prove actual 403 refusals with harmless canary
requests for every old identity. RBAC grants are additive; flags are insufficient.
Go receives only adopted namespaced verbs; observer cannot mutate or read Secrets.
Publish a newer execution-generation barrier before relying on stale-action
exclusion and resume pending canonical work. Preserve K5/V1 live acceptance.

Rollback repeats quiesce, unknown-result recovery and credential transfer.
Disable Go effective writes before enabling a **conditional-compatible** PHP
successor; never reactivate the legacy unconditional implementation or perform
automatic fallback. Revocation cannot retract previously admitted requests.
A newer handoff generation excludes their conditional effects only after its
barrier acknowledgment. PG holds remain until recovery is established.

## Security and operational consequences

Use least-privilege projected short-lived tokens, fixed endpoints, mTLS rotation
and separate observer/executor identities. No caller-supplied URLs, credentials,
raw patches or manifests. Initially bound Kubernetes requests to 5s, connect to
2s, with four observation acquisitions/four target actions, one local action per
target and client-go QPS=5/burst=10. Exact wire limits/retry rules are normative
in the v1 contract. Local mutexes bound load; CAS supplies cross-process safety.
Safe low-cardinality metrics cover freshness, request cost, conflicts, uncertainty,
denials, saturation and recovery. PostgreSQL retains durable audit. Another
process/certificate lifecycle is a cost, not a proven reliability gain.

## Alternatives rejected or deferred

Retaining/improving PHP remains viable, but does not supply the selected typed
Kubernetes execution seam. gRPC streaming, FFI, child processes, message-consuming
Go workers, informer-first design and a full controller add machinery without a
bounded requirement. Dual writers, local-lock-only fencing, SSA ownership as
ordering, name-based re-adoption, active-at-create and blind retries/fallback
weaken the selected contract. A full Laravel replacement and telephony listener
migration are separate deferred evaluations, not consequences of this decision.

## Adoption requirements and objective gates

1. Operator explicitly adopts the scoped ADR-001 extraction exception, safe
   projection and component/security ownership. A read-only adoption conveys
   no write authority. Allocate an accepted ADR only on actual adoption.
2. GN-G1: deliver undeployed observer, strict wire/auth/scope tests, complete
   pagination/410 recovery, freshness/overlap and failure tests, full PHP/tenant
   observation parity, required policy labels and measured request/CPU/RSS cost.
   Prove no mutations/no competing canonical observer. Resolve or explicitly
   isolate the documented baseline static-check defect before acceptance.
3. GN-G2: separately authorize canonical target/claim code/schema changes;
   implement fixed lifecycle contracts and all corridors; prove real API UID/RV
   patch/DELETE/create behavior, A–G concurrency/unknown recovery, error parity,
   Asterisk/FreeSWITCH ownership chain and legacy-drain evidence design. No
   writer cutover follows from isolated execution tests.
4. Operator explicitly accepts temporary pre-barrier stale effects and inert
   late-create residue, or commissions a different target-side safety design.
5. GN-G3: separately authorize complete credential transfer/live proof; require
   server-confirmed legacy drain, exclusive-writer 403 matrix, compatible rollback
   drill and preserved natural-live V1/K5 lifecycle behavior.
6. Reconcile canonical architecture/ledger only when actual adoption or behavior
   changes authority. Proposal-local GN-G0–GN-G5 do not displace RMA-E. GN-G4/GN-G5
   remain deferred. Until then every accepted ADR and current phase stands.

Use the bounded [GN-G1 assignment](gn-g0/GN-G1-implementation-assignment.md).
