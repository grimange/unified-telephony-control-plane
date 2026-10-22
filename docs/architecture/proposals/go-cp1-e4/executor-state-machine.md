# E4 executor journal: state and durability contract

Status: isolated implementation and developer-verification contract, 2026-10-22.
Parent: [E4 report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL.md).
This is synthetic HTTP infrastructure I/O, not a production Kubernetes executor.

## Owners and authenticated handoff

PostgreSQL remains canonical for operations, attempts, lifecycle, claims,
generations, reservation, consumption, evidence acceptance, audit and outbox.
The executor owns only subordinate durable dispatch facts and synthetic I/O. It
has no PostgreSQL or Redis configuration. A challenge is permission to attest;
it never grants permission to send.

The existing closed `utcp.dispatch.v1` response cannot carry an executable grant.
The new private `utcp.executor-grant.v1` envelope supplies an Ed25519-authenticated
handoff without changing dispatch, dispatch-evidence, infrastructure v1/v2/v3,
or their fingerprints. The canonical issuer may mint a `consumed` handoff only
from a fresh successful `Consume` with `Granted=true`; status, duplicate consume
and cached claims do not qualify. The issuer must bound its deadline by canonical
lease/request authority. The executor validates issuer/key validity and revocation,
transport SAN, audience, executor, signature, explicit fields, BIGINT generation,
action/version pairing, deadline and synthetic endpoint.

The immutable grant includes operation/attempt/dispatch/grant IDs, tenant,
RuntimeNode, cluster/namespace/name, incarnation, expected UID, generation,
fingerprint, action/version, journal ID, executor, synthetic endpoint and server
identity. `EnvelopeHash` hashes the exact existing infrastructure request bytes;
`BodyHash` independently hashes a fixed synthetic correlation body. Neither is
silently substituted for the other. The synthetic body contains no arbitrary
manifest, workload configuration, lease token or database credential.

The signed endpoint is HTTPS on a literal loopback address, with a separate
expected server SAN. This restriction is intentional for E4 fixtures. It does
not define the addressing or admission policy of a future Kubernetes executor.

## Durable state transitions

Sources: [journal](../../../../apps/control-plane/internal/executor/journal.go),
[HTTP](../../../../apps/control-plane/internal/executor/http.go),
[grant](../../../../apps/control-plane/internal/executor/grant.go), and
[evidence](../../../../apps/control-plane/internal/executor/evidence.go).

| State / boundary | Permitted transition | Durable meaning and evidence eligibility |
| --- | --- | --- |
| Received, unaccepted | Authenticate, then append `accepted`; otherwise reject | No durable dispatch right is inferred from receipt alone. |
| `accepted`, reserved phase | `closed` | Rejection-only grant. It cannot send or be upgraded in place to consumed. |
| `accepted`, consumed phase | `possible` or `closed`, under the same exclusive lock | No possible-send transition exists yet; immutable grant matches on duplicate delivery. |
| `possible` | `api_rejected` or `succeeded` only | External submission may have occurred. No resubmission, zero-send closure or negative evidence from silence. |
| HTTP exchange in progress | Remain `possible` until terminal result commits | Timeout, cancellation, crash, reset and lost response remain unknown. No separate volatile fact overrides the journal. |
| `api_rejected` | Terminal | One exact authenticated supported rejection is durable; E3-compatible `api_rejected` evidence may be signed. |
| `succeeded` | Terminal | Exact structured synthetic success persisted; negative evidence is forbidden. This is not canonical completion or runtime readiness. |
| `closed` | Terminal | Zero possible-send transitions and irreversible exclusion of all future automatic sends. Reserved phase supports `pre_dispatch_rejected`; consumed phase supports `definitive_non_delivery`. |
| Missing, malformed or inconsistent store | Refuse open and all operations | Quarantined by fail-closed errors; no automatic initialization, truncation, repair, replay or negative evidence. |

Duplicate exact acceptance returns the stored entry without a new transition.
Any changed immutable grant for the same dispatch rejects. Final closure and
submission both reload the entire shared journal under the same stable lock.
A process paused before the possible-send boundary must reacquire the lock and
observe closure before sending. If `possible` won first, closure cannot assert
zero submissions, even if the HTTP call has not started.

## Commit ordering and coordination

The store uses a checksummed, hash-chained append-only WAL and an atomic head
checkpoint. Initialization requires an entirely new directory. `Open` requires
the expected store ID and existing private files; missing storage is never treated
as an empty history. Directory mode is `0700`; regular owner-only files are
`0600`, single-linked and opened without following symlinks.

Every operation opens a separate file description and takes `flock` on a stable,
never-renamed lock inode. It verifies inode identity, reloads WAL/head, validates
all records and immutable transitions, then changes state. An in-memory mutex or
replaceable lock file would not provide this process arbitration.

A transition appends a complete sequenced record, synchronizes the WAL, writes and
synchronizes a temporary head, atomically renames it, then synchronizes the journal
directory. Initialization synchronizes the parent directory as well. Only after
that complete `possible` commit may the single HTTP `RoundTrip` start. If any
write/synchronization fails, the caller receives no send authorization.

A partial tail, changed checksum, sequence/hash mismatch, missing file, unsafe
permissions, changed lock inode, clean-prefix truncation or WAL/head disagreement
rejects the whole store. In particular, a crash after WAL synchronization but
before checkpoint replacement can quarantine an otherwise recoverable record.
This conservative liveness cost prevents guessing which history is authoritative.
There is no automatic salvage or compaction. A 64 MiB WAL cap bounds accepted history;
capacity exhaustion fails closed. Future retention/archival requires separately
reviewed preservation of dispatch tombstones and lock/store identity.

## HTTP and evidence boundaries

A consumed grant permits one fresh HTTP/1.1 transport and one non-idempotent POST.
Proxying, keep-alive reuse, HTTP/2, request replay (`GetBody`) and redirect following
are disabled. TLS 1.3 validates the configured endpoint identity and client
certificate matching the signed executor. The request uses a bounded deadline
and fixed correlation headers/body. No arbitrary request API is exposed.

The closed synthetic Status must match dispatch ID, infrastructure-envelope hash,
body hash and HTTP status, with an authenticated TLS chain. Only durable supported
`Failure` code/reason pairs become `api_rejected`: 400/BadRequest,
401/Unauthorized, 403/Forbidden, 404/NotFound, 409/Conflict, 409/AlreadyExists and
422/Invalid. E4 uses the actual E3 whitelist; real Kubernetes action semantics
remain outside this synthetic implementation. Redirects, 429, 5xx, malformed,
oversized, missing/duplicate/null fields, uncorrelated or interrupted responses
remain `possible`. Arbitrary message text is not evidence.

Signing reloads durable terminal facts, matches every fresh E3 challenge identity,
and emits unchanged `utcp.dispatch-evidence.v1`. Attestation timestamps describe
the current challenge response, not a reconstructed historical HTTP timestamp.
An old durable result may support a fresh challenge, but cannot gain a new send.
The E3 consumer independently verifies current canonical identity, issuer/key,
scope, signature, freshness, lifecycle, supersession and replay policy in its
existing transaction. Accepted evidence, transition, audit and outbox commit
together. Repeated signatures cannot create duplicate canonical transitions.

## Failure-domain contract

| Failure | Supported interpretation |
| --- | --- |
| Process SIGKILL/restart, retained coherent files | Recover validated durable state; never replay `possible` or terminal grants. |
| Same-container restart / replacement with the same mounted storage | Same journal identity and history can be reopened. The mounted-storage fixture proves closed-state reconstruction; process SIGKILL tests separately cover `possible`. No Kubernetes claim. |
| Pod rescheduling or node loss | Not established by E4. Requires trusted persistent/shared storage, correct filesystem locking/fsync semantics and deployment identity. |
| Missing volume, store, head or WAL | Fail closed. Absence is not zero-submission evidence. |
| Corruption or interrupted WAL/head commit | Whole-store quarantine. Preserve files for controlled recovery; do not recreate the journal to clear a grant. |
| Malicious privileged rewrite, cloned volume, coherent old snapshot rollback | Outside local checksum/lock protection. Future production needs exclusive store ownership and rollback/clone fencing. |

The journal assumes a trusted local filesystem honoring `flock`, rename and sync
semantics. Hashes detect accidental inconsistency; they do not authenticate a
malicious rewrite. Process credential-isolation tests establish controlled
configuration boundaries, not a sandbox against a hostile same-UID process with
host filesystem access. Private signing keys and temporary test credentials stay
outside permanent documentation and repository evidence.
