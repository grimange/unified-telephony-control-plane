# Proposed infrastructure adapter contract v1

**PROPOSED / NOT ADOPTED.** GN-G0 contract, 2026-10-21. These artifacts grant no
runtime or deployment authority. See the [GN-G0 report](../../../UTCP_GN_G0_INFRASTRUCTURE_ADAPTER_CONTRACT_AND_FAKE_PROOF.md)
and [proposed ADR](../../../go-kubernetes-infrastructure-adapter-adr-proposed.md).

## Wire protocol and versioning

Select internal HTTPS JSON RPC with mutually authenticated TLS. Laravel already
has an HTTP seam; JSON avoids a protobuf toolchain and allows PHPUnit to share
fixtures with Go. gRPC streaming is unnecessary for bounded direct lists and
single effects. Do not expose this interface through public ingress. No FFI,
child processes, generic Kubernetes proxy, messaging consumer, controller-runtime
manager, watch, CRD, or autonomous controller is introduced.

Endpoints are `POST /internal/infrastructure/v1/observations` and, only after
write adoption, `POST /internal/infrastructure/v1/actions`. The former accepts
[observation-request.schema.json](observation-request.schema.json) and returns
[observation.schema.json](observation.schema.json); the latter accepts
[action.schema.json](action.schema.json) and returns [result.schema.json](result.schema.json).
Error names and PHP compatibility reasons are in [errors.json](errors.json).
Schemas use JSON Schema 2020-12, closed objects and lossless decimal-string
generations. ResourceVersion is opaque, never numerically ordered.

The version field is exactly `utcp.infrastructure.v1`; unsupported versions and
unknown fields/actions fail before Kubernetes access. Changing required fields,
enums, meanings or field projection requires a new major version and explicit
PHP/Go compatibility tests. Additive changes also need negotiation because v1
rejects unknown fields. No silent downgrade. Requests/responses are UTF-8 JSON;
reject duplicate keys, trailing JSON, invalid UTF-8, overflow and bodies over
1 MiB (requests) / 16 MiB (responses). Whole snapshots exceeding the response
bound fail incomplete; never truncate into success. Schema validation precedes
semantic validation; the prototype tests schemas separately and is not a full
wire decoder/server implementation.

HTTP 200 carries valid domain outcomes, including Kubernetes refusal. Malformed
wire input uses 400/413, mTLS/role failure 401/403, saturation 429 with bounded
Retry-After, service unavailable 503. Intermediary errors do not manufacture a
successful result envelope. A non-envelope response or connection loss after
submitting an action is conservatively `outcome_unknown`; only demonstrable
nondispatch allows `transport_unavailable`. Observer failures are unavailable
facts, without mutation uncertainty.

## Authentication, authorization and limits

An operator-managed private CA issues distinct Laravel observer/executor and
adapter server identities; SAN identities and EKU are verified, never merely
"certificate from this CA". The fixed observer role cannot call actions. Go
uses a fixed configured cluster/namespace allow-set and projected short-lived
ServiceAccount token/CA; callers cannot supply URLs, kubeconfigs, tokens, Secret
payloads, certificate paths, raw manifests, or arbitrary patches. No Go PG or
Redis credentials. Rotate certificates/tokens by rereading projected material;
rotation/revocation behavior must be tested in GN-G1/GN-G2.

Laravel authenticates users, checks tenant membership/permission and resolves
RuntimeNode ownership in PostgreSQL. Internal observations are cluster facts,
never a tenant API; Laravel alone joins tenant-owned RuntimeNodes and projects
public responses. A label is evidence, never tenant authorization. The action
executor certificate alone cannot authorize an effect.

Before each barrier/effect dispatch Go calls the fixed Laravel endpoint
`POST /internal/infrastructure/v1/claims/verify` using its executor identity.
The body is the complete validated Action, not just operation ID. Laravel
checks current operation, attempt/token, expiry, tenant, RuntimeNode, target,
incarnation, desired generation, hash, allowed action/replicas, immutable UID,
expected RV and authorization/lifecycle hold. Response is strictly
`{"contract_version":"utcp.infrastructure.v1","authorized":true|false}`.
Failure denies dispatch; no cached positive authorization. This is a new
proposed claim-aware seam; the current handler operation array omits claim
context. It does not create an atomic PG/Kubernetes transaction.

Use 2s connect, at most 5s Kubernetes request/RPC deadline, clamped to caller
absolute UTC deadline; do not dispatch after local claim expiry. Laravel bounds
clock skew against its own lease authority; callback is authoritative for lease
validity. Initially at most 4 concurrent observation acquisitions, 4 distinct
target actions and one local action per target, with no durable or unbounded Go
queue. Busy requests fail before dispatch. Local locks limit load; CAS provides
cross-replica protection. client-go QPS=5, burst=10 per cluster initially;
measure before adjustment. Request cancellation stops waiting/attempted I/O,
never proves external rollback. Go does not automatically retry mutating HTTP
requests. PHP alone schedules retry/recovery after durable recording.

## Observation completeness and freshness

Acquire a complete Node list and a complete Pod list for the configured scope.
v1 requests contain one explicit Pod namespace or the literal `*` for a
configured cluster-wide Pod list. The current host view requires `*`; Go maps
that scope to client-go NamespaceAll. Namespace selection is fixed by operator
configuration and observer role, never by a tenant request. Scoped observers
cannot claim cluster completeness. No tenant can select an arbitrary namespace.

Use direct lists with limit=500 in production (the experiment uses limit=2),
resourceVersion omitted for the first request, then unchanged selectors/limits
and exact opaque continuation tokens. Retain a consistent list resourceVersion
across pages for that resource collection. Empty continue ends acquisition;
empty items with a continue token is still another page. Detect repeated tokens,
malformed identity/RV, changed per-list RV, duplicate object UID/name, page cap
1000 or size bounds as incomplete. A 410 discards the entire affected acquisition;
retry the whole snapshot at most once with a new start time if deadline permits,
otherwise return incomplete. Never use the replacement continuation token to
splice snapshots. The experiment proves rejection on 410; bounded restart is
GN-G1 implementation work. A 401/403 returns explicit unavailable/denied, even
if the other resource list succeeded.

`complete=true` requires all requested pages of both resource collections,
valid normalization and no acquisition error. Each list has its own RV and
start/end timestamps. **There is no atomic cross-resource Node/Pod snapshot.**
A Pod can refer to a disappeared Node; preserve the unresolved placement rather
than inventing a host. A Pod disappearing between snapshots is absence only
from that complete scoped list, not proof of telephony termination.

Incomplete snapshots have `complete=false`, `nodes=null`, `pods=null` and a
normalized error; per-list statuses retain diagnostic completeness but their
facts cannot drive scheduling/eligibility. An empty successful cluster is two
complete empty arrays, never an observation failure. Laravel persists receipt
and collection times, rejects out-of-order snapshots for the same scope,
retains last-known facts visibly stale, and uses the established 300s freshness
limit. Delay/clock-skew beyond the deadline/allowed budget is stale. Never reset
freshness merely because a cached snapshot was received again. A request ID is
not an ordering counter; canonical per-scope acquisition sequence/collection
interval prevents older overlapping acquisitions from replacing newer facts.

Nodes normalize UID/name/RV, Ready True/False/Unknown, unschedulable, nullable
region/zone/hostname, conditions without messages, addresses, capacity,
allocatable, safe labels and sorted taints. Pods normalize UID/namespace/name/RV, nodeName or null,
phase/readiness, allowlisted ownership/correlation labels and direct controller
UID. Node names sort by name/UID; Pods by namespace/name/UID. No Node is "Ready"
when its condition is missing. Missing topology remains null without inventing
a policy penalty. Direct ReplicaSet owner reference is **not** a verified
Deployment ownership chain; `ownership_chain=unverified`. GN-G2 must directly
verify Deployment UID -> ReplicaSet controller UID -> Pod controller UID before
using owned-Pod absence as mutation/lifecycle evidence.

This v1 projection intentionally omits arbitrary labels/annotations, condition
messages, container spec/env, managedFields, status messages, Secret objects and
credentials. It retains host capacity/allocatable/addresses/conditions/taints.
Node labels are restricted to hostname, os, arch, region and zone. Existing PHP
returns arbitrary Node labels, so this is an intentional security projection
change requiring operator adoption; any additional policy keys need an explicit
reviewed schema version. GN-G1 must inventory actual policy keys and prove that
no scheduling decision loses required facts. The shared fixture proves normalized
host/Pod fields through the current PHP service, **not full API/tenant/readiness
parity**. That broader proof remains GN-G1 acceptance work.

## Bounded action and result semantics

v1 supports only `admit_generation` and `set_replicas` on an **already bound
Deployment**. Both carry the same absolute intended replicas and intent hash.
Create, projection, delete, Node patch/eviction, Service, Secret and arbitrary
resource operations are rejected. This is an executable vertical slice, not a
complete Deployment writer replacement. Lifecycle rules below bind the future
extension; GN-G2 must deliver fixed lifecycle schemas before GN-G3 can cut over.

Required identity includes cluster, namespace, Deployment name, target ID,
incarnation ID, canonical RuntimeNode slug, expected UID and exact expected RV. Generation is allocated by
PG per target under serialization, increasing for changed intent or an explicit
writer-authority handoff/supersession barrier. Handoff can retain the desired
effect and canonical business operation/idempotency identity while allocating a
new execution generation and execution idempotency key. Ordinary retries retain
generation/hash/execution idempotency key and get new attempt/request IDs.
Use positive signed-bigint range, represented as a decimal string. It is not
Kubernetes metadata.generation, claim token, timestamp or attempt count.

Fingerprint is SHA-256 over fixed ordered UTF-8 fields, each prefixed with its
UTF-8 byte length and colon, no separators beyond those prefixes:

```text
contract_version, tenant_id, runtime_node_id, cluster_id, namespace, kind,
name, runtime_node_slug, target_id, incarnation_id, expected_uid, target_generation,
"set_replicas", base10_absolute_replicas
```

Hash excludes expected RV, claim token, attempt, request ID and deadline, which
may change on an authorized retry. A generation's hash is immutable. The fixture
hash is independently computed in PHP/Go proof. Canonical uniqueness is
`(target_id, generation)` with one hash; idempotency key binds that same intent
in PG. Go has no durable dedup database. A local restart loses no canonical work.

Persisted target markers (all under `infrastructure.utcp.io/`) are
`admitted-generation`, `admitted-intent`, `applied-generation`, `applied-intent`.
Target/incarnation and tenant/RuntimeNode labels must match the canonical target;
existing part-of=utcp and utcp.dev/runtime-node=<canonical slug> ownership
checks remain prerequisites. Also require app.kubernetes.io/instance to equal
the canonical Deployment name, preserving provider/placement correlation. These new
markers/UID binding do not exist in current deployment contracts and require
separate authorized migration. Onboarding is one-way bind after canonical
validation, then CAS bootstrap; no automatic adoption of a name collision.

An admission JSON Patch tests `/metadata/uid` and `/metadata/resourceVersion`
and sets admitted generation/hash. Existing higher generation rejects; equal
generation/different hash rejects. An effect JSON Patch tests UID, exact RV,
admitted generation/hash, then atomically writes absolute `/spec/replicas` and
applied generation/hash. Use the parent Deployment endpoint, not unconditional
`/scale`. Preserve unrelated annotations. Never refresh an RV and replay
without a fresh direct read, higher-generation check and canonical claim check.
No SSA `force`, field manager or pre-read/unconditional merge patch is sufficient
for this model. SSA field ownership does not implement temporal fencing.

Applied markers plus the current replicas predicate permit `already_satisfied`;
an admission marker or matching replica count alone does not prove application.
Matching applied marker with changed replicas is drift, requiring current
reauthorization and CAS reapplication or newer intent. Prototype may proceed
straight to that reauthorized CAS rather than returning a separate drift result.
Kubernetes effect success does not complete fencing/restoration: existing owned
Pod, readiness, active-work, listener/event epoch and canonical lifecycle guards
remain in Laravel. Markers are execution evidence, never business authority.

Before dispatch rejection is `not_sent`; explicit API refusal is `rejected`.
A 409 or proven JSON Patch predicate failure is resource_version_conflict after
re-observation; arbitrary 422 validation errors remain invalid_request, not a
license to retry. GN-G2 must verify concrete API Status details; the simulation
uses 422 only for known predicate tests. After sent I/O fails without definitive
refusal, return `outcome_unknown`, `effect=unknown`, `retry=observe_first`, even
for cancellation/deadline/5xx. PHP records uncertainty and holds eligibility;
no blind failure or automatic PHP fallback. Inspect exact UID/markers/effect,
revalidate PG intent, then either record observed satisfaction, supersession,
conflict or issue a conditional retry. A lease-lost worker may append an
untrusted attempt receipt through a separately authorized engine seam; only the
current canonical claimant may transition the operation using PG fencing.

## Lifecycle extension required before writer cutover

The future fixed DeploymentProjection schema must cover only existing reviewed
provider fields (image/digest, resource requests/limits, declared ports,
service account/security context, placement policy and Secret references).
Laravel produces it; Go checks its version/hash and renders a fixed template.
No raw Secret data, user-selected privileges or arbitrary manifests. Controlled
fields and platform fixture Deployment ownership require an explicit manifest
lifecycle conflict resolution before GN-G3.

Persist target lifecycle preparing -> bound -> retiring -> retired. Physical
names include a unique PG incarnation, are never reused, and UID binding is
one-way. Replacement/recreation gets a new incarnation/name; wrong UID fails
closed even with matching labels. A 404 is absence of the bound identity, not
permission to bind a replacement with the old name.

Creation is a bounded `create_suspended` effect with fixed namespace/name,
incarnation/target/ownership markers and **replicas=0**. POST is create-if-absent;
409 requires direct inspection of exact expected incarnation/projection, never
unconditional adoption. Returned/discovered UID must be bound in PG before a
separate current-generation barrier and activation. If PG intent has retired,
never bind/activate it. An old delayed POST may recreate a retired physical name
as a zero-replica orphan after deletion. A PG tombstone or fresh GET=404 cannot
prevent that. Accept inert orphan residue with canonical cleanup; do not promise
physical nonresurrection or exactly-once create. A strict prohibition would need
an approved target-side tombstone/admission mechanism and different architecture.

Projection updates test UID/RV/admitted generation/hash and atomically update
only their declared fields plus applied markers. Projection and replicas share
the same PG target generation/order; a convergence template cannot overwrite
replicas independently of fence/restore intent. Laravel must derive effective
replicas from canonical target intent and unresolved fence/lifecycle holds, never
from the provider template's default replicas=1. A newer generation does not
authorize bypassing a fence: only canonical admitted restoration may release
that hold. Test this across all five operation types; Go adds no telephony rule.

Deletion first canonically and terminally retires the incarnation (no unretire),
admits a retirement generation, enforces existing drain/zero replicas/owned-Pod
conditions, then DELETE uses `DeleteOptions.Preconditions{UID,ResourceVersion}`.
Accepted deletion, deletionTimestamp and observed absence are distinct results.
Old DELETE cannot remove a replacement UID; RV precondition rejects modifications
after preparation. Name nonreuse and zero-replica CREATE contain late POST risk.
Unknown DELETE is resolved against that exact UID; unknown CREATE with absence
remains uncertain, never taken as proof it cannot arrive. Preserve PG tombstones
and append-only evidence. A new activation uses a new incarnation/name.

## Fixtures and proof boundary

[fixtures/manifest.json](fixtures/manifest.json) lists all schema-valid positive
and normalized negative outcomes and schema-invalid requests. Every material
result code has a sanitized result fixture, including unknown and conflict.
The [experiment](../../../../../../experiments/gn-g0-infrastructure-adapter/README.md)
contains fake-client, RFC6902 HTTP simulation, pagination, deadline, duplicate,
delayed-write and PHP subset-parity tests. It does not implement mTLS, claim
callback transport, production JSON decoding, pagination restart, durable PG
targets, lifecycle extensions or a real API server. Those are explicit staged
acceptance gates, not hidden completion claims.

## Initial legacy writer transfer

The generation guarantee covers only conditional writers. Today's PHP merge
PATCH/scale and DELETE have no such conditions: a previously admitted legacy
request can overwrite/delete the target even after a newer Go barrier. Initial
transfer therefore requires a server-confirmed legacy-request drain, followed by
an exclusively conditional writer baseline, before any same-incarnation transfer.

Chosen future procedure: quiesce every old application writer/credential; revoke
its effective writes; correlate a complete Kubernetes audit trail (RequestReceived
through proven terminal ResponseComplete stages, with Audit-ID, identity, resource
and verb) with all legacy attempts. Require server-side terminal resolution for
every accepted legacy mutation, inspect final target/deletion/child state and
resolve canonical uncertainty. Audit completeness/no-dropped-event coverage and
panics, missing stages and ambiguous cancellation remain unresolved until server
evidence establishes completion. The meaning of terminal synchronous Deployment handlers must be independently
proved in GN-G2/GN-G3. A response-start stage alone is insufficient. Then activate
only the conditional-compatible PHP baseline or Go writer. No legacy retry source
may remain; platform tooling follows the same target exclusion procedure.

If that audit/drain evidence is unavailable or incomplete, **same-incarnation
first cutover is prohibited**. A separately reviewed isolation/replacement plan
must also contain legacy create-at-one resurrection and live telephony effects;
renaming new Deployments alone is not sufficient. Never infer this drain from
credential revocation, client timeout, worker exit or a current GET. Subsequent
conditional-to-conditional rollback uses a newer execution-generation barrier;
it cannot reactivate the legacy implementation. GN-G0 executes neither transition.
