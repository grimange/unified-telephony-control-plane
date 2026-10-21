# Deployment lifecycle contract v2 proposal

**PROPOSED / NOT ADOPTED — NOT IMPLEMENTED.** GN-G2, 2026-10-21.
This is a bounded extension design, not permission to create, delete, deploy or
transfer writer authority. The existing [v1 contract](../gn-g0/contracts/v1/README.md)
and [proposed ADR](../go-kubernetes-infrastructure-adapter-adr-proposed.md) remain
unchanged. GN-G2 implements only already-bound Deployment effects.

## Dated implementation successor — 2026-10-21

The status above records this proposal when authored. The separately authorized
[Lifecycle V2 implementation report](../UTCP_GN_G2_LIFECYCLE_V2_CONDITIONAL_DEPLOYMENT_MANAGEMENT.md)
and [executable v2 contract](contracts/v2/README.md) describe the later isolated
implementation and its actual proof. That implementation accepts exactly three
v2 lifecycle actions, atomically admits/applies projection in its convergence
CAS, and performs retirement barrier CAS followed by separately authorized
conditional deletion. Its initial projection profile is **inert.pause.v1**;
the active Asterisk/FreeSWITCH profiles below remain planned. This historical
proposal is not retroactively marked adopted or fully implemented.

## Version and authority

Propose a negotiated major version `utcp.infrastructure.v2`, with the fixed
endpoint `POST /internal/infrastructure/v2/actions`. Reject unknown fields and
actions; do not silently extend the closed v1 schemas or downgrade requests.
The tables below define the proposed closed objects and action-specific fields.
Executable JSON Schemas and PHP/Go fixtures must be adopted and implemented in a
separate lifecycle assignment before GN-G3 transfers any writer.

Laravel authenticates tenants, produces desired configuration, applies telephony
admission and lifecycle holds, and allocates serialized PostgreSQL target
generations. PostgreSQL owns target/incarnation state, one-way UID binding,
operation claims, idempotency, retirement tombstones and audit. Go verifies the
complete current claim and executes only the bounded effect. Kubernetes owns
resource identity and actual state. No Go database client, policy controller or
independent cleanup loop is proposed.

## Closed request objects

Unless specified below, reuse v1 lexical bounds, signed-bigint decimal-string
generation, absolute UTC deadlines, mTLS roles and maximum sizes. All listed
members are required; `null` is allowed only where explicitly stated. Reject
duplicate keys, unknown members, numeric generations and coercion.

| Object | Exact members and constraints |
| --- | --- |
| Common envelope | `contract_version="utcp.infrastructure.v2"`, `request_id`, `operation_id`, `tenant_id`, `runtime_node_id`, `target`, `target_generation`, `intent_fingerprint`, `action`, `intent`, `idempotency_key`, `claim`, `deadline`, `correlation_id`, `audit_id`; no additional action-specific envelope fields |
| `target` | `cluster_id`, `namespace`, `kind="Deployment"`, `name`, `runtime_node_slug`, `target_id`, `incarnation_id`, `expected_uid`, `expected_resource_version`; last two are `null` only for `create_suspended`, nonempty opaque strings for bound-resource actions |
| `claim` | `attempt`, `fencing_token`, `lease_expires_at`, with v1 semantics; callback authorizes the full envelope and canonical lifecycle state |
| `intent` for creation | Exactly `kind="create_suspended"`, `projection`, `projection_fingerprint`; replicas are fixed by Go to zero, not supplied by the caller |
| `intent` for projection | Exactly `kind="converge_projection"`, `projection`, `projection_fingerprint`, `replicas` integer 0–100 derived by Laravel from effective desired intent and unresolved fence/retirement holds |
| `intent` for replica effect | Exactly `kind="set_replicas"`, `replicas` integer 0–100 |
| `intent` for deletion | Exactly `kind="retire_delete"`, `retirement_id`, `retirement_generation`, `retirement_fingerprint`; retirement generation equals envelope `target_generation`, and the retirement fingerprint identifies the canonical retirement record |
| `action` dispatch discriminator | `admit_generation`, `create_suspended`, `converge_projection`, `set_replicas` or `retire_delete`; except admission, it must equal `intent.kind`. Admission accepts only a bound-resource intent, never `create_suspended` |

Admission and execution carry the **identical complete `intent` object**, target,
generation and intent fingerprint. An admission cannot omit projection,
replicas or retirement payload and later execute a different interpretation.
Only dispatch verb, request/RV/claim/deadline/correlation metadata may differ
under fresh canonical authorization. One target generation sequence orders all
bound intent kinds; there are no separate projection/fence/restore counters.

`projection_fingerprint` is SHA-256 of a deterministic fixed-order, UTF-8
byte-length-prefixed encoding of the following closed projection. It is distinct
from the overall intent fingerprint. The v2 intent fingerprint includes version,
tenant/RuntimeNode and complete immutable target identity, generation, `intent.kind`,
projection fingerprint or an empty string when inapplicable, effective replicas
or an empty string, and retirement identity/generation/fingerprint or empty
strings, in that declared order. Exclude RV, claim and retry metadata as in v1.
Independently generated PHP/Go positive and negative vectors are an adoption gate.
Here the hashed discriminator is specifically **`intent.kind`**, never the
dispatch `action`: `admit_generation` and its matching effect must hash to the
same intent. The fixed identity order is v1's version, tenant, RuntimeNode,
cluster, namespace, kind, name, slug, target, incarnation, UID, generation, then
the intended-effect discriminator and payload digest/scalars described above.
Recompute the projection digest from the complete supplied projection before
validating the overall intent hash. Reject a mismatch or payload illegal for its
intent kind, including an admission/execution pair with different content.
For unbound creation, encode the UID slot as an empty string; a bound resource
never permits an empty UID. Encode projection fields in the table's order,
recursing through nested objects in the declared order below. Encode each array
as its base-10 element count followed by its ordered element encodings; prefix
every scalar/count with its UTF-8 byte length and colon. Canonicalize booleans as
`true`/`false` and integers as base-10 without leading zeroes. No map iteration,
locale-dependent quantities or implicit defaults enter the digest.

| `projection` member | Proposed permitted value |
| --- | --- |
| `template_version` | A reviewed fixed template identifier, initially `asterisk.v1` or `freeswitch.v1`; unknown templates fail closed |
| `image` | Immutable repository plus digest; no mutable-tag-only activation |
| `resources` | Closed requests/limits for CPU and memory, canonical Kubernetes quantity strings; configured bounds validated by Laravel and Go |
| `ports` | Ordered fixed-template port identifiers, integer container ports and `TCP`/`UDP`; no host networking or arbitrary host ports |
| `service_account` | Reviewed fixed-template account identity; caller cannot select elevated privileges |
| `security_profile` | Reviewed fixed profile identifier; no raw securityContext or privilege capability lists |
| `placement` | Reviewed versioned selector/toleration/affinity structure produced by existing placement policy, with exact closed schema and fixture parity required before adoption |
| `secret_references` | Sorted declared purpose/name/key references in the fixed namespace; no Secret values, credentials, arbitrary volume specification or raw environment map |
| `configuration_reference` | Versioned canonical projection identifier and digest, not an arbitrary URL or raw manifest |

The proposed nested objects are closed:

- `resources`: `requests` and `limits`, each exactly `cpu` and `memory` strings.
- Each `ports` element: `name`, `container_port` integer 1–65535, `protocol`.
  Sort by name; reject duplicate names or ambiguous protocol/port combinations.
- `placement`: `node_selector`, `tolerations`, `required_node_terms`,
  `preferred_node_terms`; arrays are explicitly present, including when empty.
  Selector elements are `{key,value}` sorted by key, with unique keys.
  Tolerations are `{key,operator,value,effect,seconds}` where operator is
  `Equal|Exists`, effect is `""|NoSchedule|PreferNoSchedule|NoExecute`, seconds is
  a nonnegative integer or null, and operator-specific value rules are enforced.
  Sort by those fields in order. Required terms contain exactly `expressions`;
  each expression is `{key,operator,values}` using the reviewed Kubernetes
  `In|NotIn|Exists|DoesNotExist|Gt|Lt` semantics and sorted unique values.
  Preferred terms contain exactly `weight` integer 1–100 and `expressions`.
  Sort canonical term encodings; reject duplicate terms. No `matchFields`,
  pod-affinity, topology-spread or arbitrary selector map is implicitly allowed.
- `secret_references` elements: `purpose`, `name`, `keys`; sort by purpose/name,
  keys are sorted unique strings. Purpose must be a fixed-template slot.
- `configuration_reference`: `projection_id`, `sha256`; digest is 64 lower hex.

The nullable toleration seconds scalar encodes as the empty string, which cannot
be confused with a valid integer. The executable schema must reject every
unsupported placement operator/shape and prove parity with existing policy.

Before adoption, inventory the actual Asterisk/FreeSWITCH templates and express
each required field in executable closed schemas. If a current template needs
an omitted field, revise this proposal explicitly rather than introducing a
generic Kubernetes map. Controlled field ownership must be resolved against
platform manifests, Helm/Kustomize and autoscalers before application cutover.

## Target lifecycle and identity

Use the canonical state machine `preparing -> bound -> retiring -> retired`.
Retirement is terminal: no `retired -> bound`, name reuse or UID rebinding.
PostgreSQL allocates a unique immutable incarnation before any create dispatch.
The physical Deployment name includes that incarnation and is never reused,
including after failed provisioning, cleanup or replacement. Replacement means
a new incarnation and name, not adoption of a resource found at the old name.
Retain tombstones and lifecycle history.

The target's UID changes only from unbound to one exact Kubernetes UID under
PostgreSQL CAS. Go cannot establish that canonical binding itself. A replacement
UID at a bound name fails closed even with identical ownership labels. Namespace,
name, tenant, RuntimeNode, target and incarnation remain exact trusted bindings;
labels alone never authorize adoption.

## Create and activation

1. Laravel records preparing incarnation, immutable desired projection and
   generation. A fresh full-envelope claim permits `create_suspended` only.
2. Go POSTs a fixed Deployment with exact identity markers and replicas zero.
   Kubernetes name uniqueness gives create-if-absent. Never PUT/merge an existing
   name or manufacture a UID precondition before a UID exists.
3. On 409, directly GET and verify exact incarnation, ownership, projection and
   suspended state. This is discovery, not automatic canonical adoption. Any
   mismatch is terminal ownership conflict.
4. Return `created_suspended` or `observed_suspended` with exact UID/RV and
   projection digest. Laravel binds UID once only if the same incarnation is
   still preparing and the canonical intent is current. Retired intent cannot
   bind or activate a discovered object.
5. Activation is a separate current claim, admitted generation barrier and
   conditional bound-resource effect. Provider defaults must never activate it.

**Additional invariant required:** replicas zero alone does not establish an
inert orphan. HPA, another writer, platform tooling, legacy create-at-one requests,
template side effects and unrelated resources can create effects. Before adopting
this model, prove exclusive activation of these names, absence of autoscalers and
other replica writers, and inertness of the complete suspended template and its
referenced resources. The lifecycle proposal cannot claim that invariant from
GN-G2 tests.

An already dispatched POST can arrive after retirement and deletion. PostgreSQL
tombstones, token expiry, a GET=404 and credential revocation cannot retract it.
Accept only proven inert orphan residue under the above invariant, retain
canonical cleanup work and never reactivate it. Physical nonresurrection would
require separately approved target-side admission/tombstone enforcement; it is
not provided here. If inertness cannot be proven, block create adoption rather
than interpreting zero replicas as sufficient safety.

## Projection convergence and deletion

`converge_projection` uses one parent-Deployment conditional operation testing
UID, RV, admitted generation and intent, and atomically changes declared template
fields, effective replicas and applied markers. Unrelated fields survive.
Provision, converge, fence, restore and retire share one target ordering domain.
Laravel computes effective replicas under all unresolved lifecycle/fence holds;
a newer projection generation does not release those holds.

Before `retire_delete`, Laravel records terminal retirement intent, admits its
generation and proves existing telephony drain, zero replicas and verified owned
Pod completion using current authorized observations. Go tests the exact bound
UID/RV and retirement barrier, then issues DELETE with
`DeleteOptions.Preconditions{UID,ResourceVersion}`. The API cannot atomically
combine arbitrary annotation tests with DELETE preconditions: the direct-read
retirement predicate is protected by the same observed RV, not a separate
unconditional DELETE. Finalizer or other updates after preparation require fresh
canonical authorization and a new read. No force deletion or finalizer removal.

`deletion_accepted`, `terminating` and `observed_absent` are distinct results.
An old DELETE cannot delete a replacement UID. A 404 concerns the exact bound
identity; it never authorizes rebinding or recreation.

## Recovery and bounded results

Extend result enums only in v2: `created_suspended`, `observed_suspended`,
`projection_applied`, `deletion_accepted`, `terminating`, `observed_absent` and
`orphan_suspended`. Retain the v1 outcome/dispatch/retry distinction and add
nullable `projection_fingerprint`, `retirement_id` and `deletion_timestamp`.
No raw resource or credential is returned. Durable receipts and operation
completion remain Laravel authority.

| Uncertainty | Required canonical recovery |
| --- | --- |
| Create response lost, object found | Verify exact incarnation and projection, preserve suspension, then one-way bind only under current preparing intent |
| Create response lost, object absent | Remain unknown: a delayed create can still arrive. No new incarnation activation that assumes physical absence is permanent; retain cleanup/tombstone work |
| Projection response lost | GET exact UID, admitted/applied markers, projection digest and effective replicas; reauthorize before any new CAS |
| Delete response lost | GET exact bound identity; distinguish terminating/absent/conflicting replacement, never issue unconditional replay |
| Retired orphan found later | Record append-only evidence and schedule canonical conditional cleanup; no Go autonomous policy or unretire |

## Prerequisites before GN-G3

Implement and independently review the executable v2 schemas/fixtures and PHP
claim/persistence extension in a separately authorized packet. Prove real API
create races, conditional update/delete, finalizers, name nonreuse and delayed
POST/DELETE outcomes. Prove suspended-template inertness and exclusive activation.
Complete the GN-G2 real API-server gate. Migrate all five corridors together at
the authority boundary; a scale-only transfer leaves competing application
writers. Initial legacy-request drain, credential cutoff and compatible rollback
remain mandatory in the [GN-G3 handoff](GN-G3-implementation-assignment.md).
