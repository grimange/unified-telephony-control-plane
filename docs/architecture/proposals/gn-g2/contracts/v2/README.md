# Lifecycle V2 executable contract

Status: implemented in the isolated developer-verification adapter; **PROPOSED /
NOT ADOPTED** for production architecture. This contract does not change
[the v1 interface](../../../gn-g0/contracts/v1/README.md).

`POST /internal/infrastructure/v2/actions` requires verified HTTPS/mTLS with the
configured executor URI identity. Version is exactly `utcp.infrastructure.v2`.
Only `create_suspended`, `converge_projection`, and `retire_delete` are accepted.
The independent `v1` admission/replica endpoint retains its existing semantics.

The executable [action schema](action.schema.json) and
[result schema](result.schema.json) close every object and discriminate each
intent. Unknown fields/actions, duplicate JSON keys, unsupported versions,
invalid UTF-8/surrogates, excessive depth/size, numeric generations and signed
bigint overflow fail closed. Input is at most 64 KiB. Generation is a positive
signed-bigint **decimal string**, never a floating point value. Lease tokens are
32 lowercase hexadecimal characters, matching Laravel's actual operation token.

## Deliberate initial projection restriction

The earlier lifecycle proposal described future `asterisk.v1` and
`freeswitch.v1` profiles with placement, resources, secrets and configuration
references. This executable contract implements **only `inert.pause.v1`**.
Those active profiles are unsupported and fail closed; this is a material
capability limit before five-corridor production migration, not an assertion of
telephony projection parity. No arbitrary manifest or partially modeled active
workload is accepted.

Projection is exactly `template_version`, `image`, `configuration_id`,
`configuration_sha256`. Image is fixed to the verified pause 3.10 OCI index:
`registry.k8s.io/pause@sha256:ee6521f290b2168b6e0935a181d4cff9be1ac3f505666ef0e3c98fae8199917a`.
Configuration references are inert canonical identifiers/digests, not URLs,
Secrets, environment variables, ConfigMap mounts or executable content.

Go constructs one `/pause` container, no arguments, sidecars, init containers,
ports, hooks, probes, environment, volumes, image-pull references or host access.
It disables token automount and service links, fixes nonroot UID 65532, drops all
capabilities, disallows privilege escalation, makes the root filesystem read
only and uses RuntimeDefault seccomp. The Deployment is paused, initially has
zero replicas, and its selector is the immutable incarnation label. The entire
Pod template is owned by this profile; unexpected fields are rejected during
observed-create/recovery validation. Only enumerated Kubernetes defaults are
normalized. Unrelated parent Deployment labels, annotations, strategy and spec
fields are preserved by projection CAS. Template-level third-party injection
is incompatible with this restricted profile, rather than silently adopted.

Pause is an explicitly selected inert executable rather than a telephony image;
its no-telephony behavior is an image supply-chain assumption. Tests prove the
constructed Deployment/template and API conditions; they do not attest the
registry image implementation. Paused/zero replicas additionally prevent Pod
creation in the isolated proof. Other writers, admission webhooks, autoscalers
or separately created resources can change these guarantees; exclusive future
writer ownership and admission policy remain adoption prerequisites.

## Identity, authority and lifecycle

Physical name is exactly `utcp-` followed by the 32-hex immutable incarnation.
Laravel/PostgreSQL must allocate it once, retain tombstones, and never reuse it.
The adapter verifies form, not historical uniqueness: it has no database.
Tenant, RuntimeNode, target, cluster, namespace, name, incarnation and ownership
labels must match the full freshly verified operation envelope. Labels alone
never authorize an effect. Kubernetes assigns UID; only Laravel may bind that
UID once. Bound actions require nonnull expected UID and resourceVersion.

Laravel owns preparing -> bound -> retiring -> retired transitions, current
claims, generation allocation, effective desired replicas, holds, telephony
admission and durable results. Test-only PHP models these rules and exercises
actual Laravel operation-lease methods. No production lifecycle persistence or
claim callback route is introduced.

The callback is `POST /internal/infrastructure/v2/claims/verify` over mTLS. It
receives the complete validated envelope each time, without positive caching.
Only exact JSON `{contract_version, authorized}` is accepted. Every mutation is
preceded by a fresh callback and local lease/context check. Deadlines are bounded
to the caller deadline, lease expiry and five seconds. Four active requests are
allowed, at most one per physical target per Engine; no unbounded queue or
client-go rate-limiter wait occurs after claim verification. Multiple instances
are protected by API-server CAS, rather than this local process guard.

## Fingerprints

Encode each scalar as UTF-8 byte length, colon, then bytes. Projection hash is
SHA-256 of the four projection fields in the order above. Overall hash is SHA-256
of these scalars, in order: version, tenant, RuntimeNode, cluster, namespace,
kind, physical name, RuntimeNode slug, target ID, incarnation, UID (empty for
creation), generation, intent kind, projection hash (empty if inapplicable),
replicas (empty if absent), retirement ID, retirement generation, retirement
fingerprint. Optional empty slots are significant. Request/RV/claim/deadline
metadata is excluded. No map ordering or numeric coercion enters hashing.
[Fixtures](fixtures.json) include independently calculated PHP vectors and
negative mutations. Changing this byte encoding requires a versioned contract;
unsupported versions never downgrade automatically.

## Effects and recovery

Creation performs GET followed by create-if-absent POST; Kubernetes name
uniqueness decides races. Matching existing exact suspended identity/projection
is observed success, never automatic canonical UID binding. Conflicting objects
are refused. A POST race may report resource-version conflict and require fresh
canonical authorization/observation, with no unconditional upsert.

Convergence atomically tests UID, expected RV and the observed old generation/
fingerprint, then installs the newly authorized generation barrier, controlled
projection, absolute replicas and applied markers in one parent Deployment JSON
Patch. No fourth v2 admission operation or `/scale` write is introduced. Older
barriers and equal-generation/different fingerprints reject before dispatch;
concurrent API changes fail CAS. No automatic RV refresh or blind retry occurs.
The supported profile remains paused/inert even when replicas is nonzero.

Retirement requires a current retiring claim and observed zero replicas. A CAS
installs the retirement barrier/ID; a **separate fresh full-envelope claim** then
permits DELETE with UID and the returned RV preconditions. This is two requests,
not one transaction. Any intervening update invalidates deletion. A successful
DELETE means `deletion_accepted`; recovery distinguishes `terminating` (including
finalizer delay) and `observed_absent`. Unknown finalizers are never removed.
Canonical telephony draining/owned Pod completion is Laravel's admission duty;
this inert-profile adapter does not independently authorize it.

Recover is read-only and still verifies the current canonical claim. Exact UID,
ownership, markers, profile and effect establish a satisfied projection; replica
equality alone never does. A missing uncertain create remains `outcome_unknown`
because an already dispatched POST may still arrive. Missing retired targets
can be `observed_absent`; replacement UID is always rejected. Revoked claims do
not authorize recovery mutation; canonical cleanup requires a separate valid
retirement/recovery intent. No PHP fallback is activated.

PostgreSQL expiry cannot retract an already dispatched Kubernetes request. An
older request may take effect before a newer barrier; after the barrier, CAS
prevents overwrite. Delayed creation can leave an inert orphan even after
retirement; observed absence does not establish permanent nonresurrection.
Canonical retained cleanup work, unique names and the inert template bound that
risk. No Kubernetes tombstone admission controller or cross-system atomicity is
claimed.

## Results and compatibility

Results expose only operation IDs, closed outcome/effect/dispatch/retry enums,
UID/RV, generation, projection/retirement identity, deletion timestamp and finish
time. No raw object, private key, bearer token or upstream error text is emitted.
Unknown dispatched mutation returns `outcome_unknown`, `effect=unknown`,
`dispatch=unknown`, `retry=observe_first`. Definitive API rejection remains a
rejection. Generic 422 is conservatively `invalid_request`; human-readable API
messages do not establish retry permission. 409 is resource-version conflict;
401/403/404 preserve normalized distinctions. Read transport failures are not
successful empty state. Cancellation after possible dispatch remains unknown.

Any additional profile, action, field, interpretation or lifecycle authority
requires explicit versioned review; neither this proposal nor its local tests
adopts production architecture or authorizes GN-G3 cutover.

Retirement has an explicit partial receipt: after its barrier CAS succeeds but
before DELETE is authorized, the result records `effect=retirement_admitted`
and `dispatch=response_received`. A later claim denial preserves that confirmed
partial effect. A definitive DELETE rejection can use `dispatch=rejected` while
retaining `retirement_admitted`; it does not imply the entire two-step action
was unexecuted. An uncertain DELETE instead reports unknown/observe-first.
