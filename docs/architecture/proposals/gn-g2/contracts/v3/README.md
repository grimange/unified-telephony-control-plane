# Active provider projection contract v3

Status: isolated implementation contract. The adapter architecture remains
PROPOSED / NOT ADOPTED. No production callback or writer transfer is installed.

`utcp.infrastructure.v3` adds a closed, bound-only `converge_projection` action
at `/internal/infrastructure/v3/actions`. It leaves normative v1/v2 unchanged.
Create suspended with v2, bind the observed UID in canonical authority, then
explicitly authorize a v3 provider projection. Active projection is never an
implicit consequence of successful creation.

`canonical_state: bound` is a claim assertion. Laravel must verify this assertion
and the complete request against current canonical state; accepting the field
alone supplies no authorization. The trusted verifier is called before the
read and immediately before every mutation, without positive caching.
`/internal/infrastructure/v3/claims/verify` accepts the entire action and returns
exactly `contract_version` plus boolean `authorized` over authenticated mTLS.

## Closed profiles

`asterisk.managed.v1` and `freeswitch.managed.v1` fix all container, probe,
resource, volume, security and strategy settings to the current PHP builders.
Only `image`, `secret_name`, `configuration_id` and `configuration_sha256` vary.
No command, sidecar, privileged setting, host access or raw Kubernetes object
is accepted. The Asterisk optional ConfigMap slot is fixed to
`asterisk-local-sip-fixtures`, matching PHP. FreeSWITCH uses three fixed
emptyDirs and UID/GID/fsGroup 1000. Both have fixed requests 50m CPU/128Mi and
limits 500m CPU/384Mi, fixed ports, exec probes, and default RollingUpdate
25% surge/25% unavailable. Asterisk retains its writable root filesystem;
this contract does not silently harden away its startup dependencies.

Images must include an explicit registry/repository path and SHA-256 digest,
and must match a trusted configured catalog
entry for that exact provider profile. Namespace-scoped Secret names must
match the trusted slot catalog; Go never reads Secret payloads. The catalogs
are deployment trust inputs, not tenant authorization. They do not resolve a
mutable tag. Laravel's existing immutable image contract already requires a
digest. Fixture digests are resolved local image RepoDigests, not invented
provider identities. Running those images is separate telephony acceptance.

## Atomic ownership and activation

The existing Deployment selector remains immutable and is correlated by the
incarnation label. A single parent Deployment JSON Patch tests UID and expected
resourceVersion, tests any installed generation barrier, and installs the
provider Pod template, fixed strategy, absolute replicas, `paused: false`,
projection/configuration markers and admitted/applied generation fingerprints.
Parent metadata and spec fields outside this documented set are preserved;
the component label is set to the provider role. Go owns the complete Pod
template, so unsupported sidecars or mutating admission additions produce
explicit drift rather than being silently trusted.

Any nonempty retirement marker rejects v3 activation, regardless of requested
generation. A newer retirement barrier changes resourceVersion and prevents an
already-prepared older conditional patch. A claim expiring after dispatch does
not revoke a request. An older request arriving before the newer barrier can
apply temporarily. Canonical authority must observe and converge the current
intent; PostgreSQL and Kubernetes do not share an atomic transaction.

Rollout pause is not suspension. Authorized nonzero replicas are permission for
the fixed runtime template to create Pods, subject to Kubernetes controllers,
admission, scheduling and image/config availability. Readiness and telephony
execution are observed separately. The adapter does not claim external replica
writers/HPA are excluded by this protocol; writer exclusivity is a future
cutover prerequisite. Suspended v2 creation remains zero replicas with the
inert profile.

## Fingerprints and compatibility

SHA-256 input is concatenated UTF-8 scalar byte-length prefixes (`N:value`).
Projection fields, in order: template version, image, configuration ID,
configuration SHA256, Secret name. Action fields, in order: contract version,
tenant ID, RuntimeNode ID, cluster ID, namespace, kind, name, RuntimeNode slug,
target ID, incarnation ID, expected UID, generation, intent kind, projection
fingerprint, decimal replicas, empty retirement ID, empty retirement generation,
empty retirement fingerprint, canonical state. Generations remain decimal
strings bounded by signed PostgreSQL bigint. ResourceVersion, claim and
transport metadata are not intent fingerprint fields. Missing/null required
values, duplicate fields, unknown fields, lone surrogates and numeric
generations are rejected.

`action.schema.json`, `result.schema.json` and `fixtures.json` are embedded
byte-for-byte in the isolated Go package. Breaking field/profile changes require
a new version. v3 never interprets v1/v2 requests as activation authorization.

## Recovery and failures

A dispatched request with no definitive API response returns
`outcome_unknown`, `effect=unknown`, `dispatch=unknown`, `retry=observe_first`.
Recovery reads exact UID/incarnation and compares generation/fingerprint,
replicas and all controlled projection fields after removing only documented
Kubernetes defaults. It never writes, refreshes resourceVersion for replay,
binds a UID, or activates a PHP fallback. If no match is established it returns
explicit drift or identity/stale/ownership failure. Replica equality alone is
insufficient. Unknown outcomes require current canonical authority before a
new dispatch. Failed JSON Patch `test` may be HTTP 422 and is conservatively
`invalid_request`; human error text is not parsed as a protocol guarantee.

Default normalization is limited to DNSClusterFirst, default-scheduler,
restartPolicy Always, default ServiceAccount names, termination grace 30s,
enableServiceLinks true, ConfigMap mode 0644, container termination-message
defaults, and probe successThreshold 1. Other template changes remain drift.
The trust catalogs are copied on engine construction so mutable caller maps
cannot change the approved image or Secret set after construction.

The fixture manifest separates `positive`/`negative` action cases from
`positive_results`/`negative_results` result cases. Results cover applied,
already-satisfied, uncertain, stale and recovery outcomes; invalid results
exercise unsupported versions, enum values, numeric generations, unknown
object fields, missing identity fields and invalid timestamps. Result schemas
validate representation; cross-field outcome meaning is enforced and tested
by the engine rather than inferred from a valid JSON shape alone.
