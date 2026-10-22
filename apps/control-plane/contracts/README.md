# GO-CP1 private protocols

These isolated contracts do not alter `utcp.infrastructure.v1`, `.v2` or `.v3`.
`utcp.dispatch.v1` is a private reserve/consume/result/status seam. Capability
material is sent only in authenticated requests and never in response status,
audit, logs or receipt payloads. The injected backend owns PostgreSQL checks.
The HTTP handler is not a deployed listener and does not execute Kubernetes.

`utcp.observation-receipt.v1` projects exact-read evidence into fixed fields.
Signing bytes are UTF-8 `encoding/json` of the `ReceiptPayload` struct, in its
declared field order, with timestamps normalized to UTC. Every payload property
is present; `replicas` uses explicit null when inapplicable. Signatures are
Ed25519 encoded as unpadded base64url. Duplicate JSON properties and unknown
properties reject before verification. No floating point or raw objects occur
in signed evidence. Changing signing representation requires a new version.

Trust configuration binds key ID, issuer, authenticated certificate SAN,
cluster, namespace and purpose. Receipt purposes are `bind`, `effect`, `absence`.
Evidence age defaults to 30 seconds, future skew to 5 seconds, and expiry cannot
outlive the persisted challenge. Acquisition must start at or after challenge
issuance; signing a previously cached observation does not satisfy a new challenge.
Binding also requires the expected inert projection digest and zero observed
replicas; a found name and UID alone cannot authorize binding. Found evidence
carries independently observed tenant, RuntimeNode and incarnation
ownership fields. Effect receipts carry admitted/applied generation and fingerprint
markers plus observed replicas. Projection convergence also carries a digest
recomputed from the allowlisted observed projection, not merely copied from an
annotation. `effect_digest` binds the challenged intent; it is not itself proof
of the current Kubernetes effect. Absence carries no fabricated resource facts. Only a complete `exact_get` result can be
evidence. An exact absent observation is a trusted issuer's attestation of GET
404; an error/list omission/DELETE acknowledgement is never an absence receipt.

The cryptographic verifier cannot establish observation truth or current domain
policy. PostgreSQL transactionally rechecks canonical eligibility, challenge
consumption and issuer/receipt-ID uniqueness. mTLS protects the current peer and
channel; signatures preserve evidence provenance in durable storage.

Schemas validate shape, not cryptographic or domain truth. `fixtures.json`
contains explicitly synthetic structural fixtures; its signature placeholder
is not accepted evidence. Tests generate signing keys and certificates only in
memory, then exercise signature, trust and transport rejection separately.

Compatibility is strict: unsupported versions/actions/fields reject. Additions
that change required fields, signing bytes or dispatch meaning require a new
protocol version. These protocols grant no production writer authority.

`utcp.dispatch-evidence.v1` is a separate, closed protocol for negative dispatch
evidence; it does not add an observation purpose or change earlier signing bytes.
Its [schema](dispatch-evidence.schema.json) and
[structural fixtures](dispatch-evidence-fixtures.json) require the complete exact
dispatch, operation attempt, intent, tenant, RuntimeNode, immutable physical
identity, generation, fingerprint, and original request digest. Signing uses the
declared `DispatchEvidencePayload` field order and the same UTC/Ed25519 encoding
rules. Every field is explicit. Null, unknown, duplicate, malformed and oversized
payloads reject. The evidence key must permit `dispatch_evidence` and its
authenticated SAN must equal the executor recorded by the original reservation.

A newly issued challenge requires a fresh attestation interval. `started_at`
and `finished_at` describe acquisition and verification of the finalized journal
after that challenge, rather than the earlier external request's submission time.
The journal facts may predate the challenge. `final=true` and `complete=true`
assert irrevocable closure of this exact execution right: no outstanding or
in-flight request, no future delivery, and no automatic submission or retry under
that right. A zero counter, timeout, cancellation or process death alone cannot
establish this assertion. A production executor must implement and independently
prove that journal protocol; the isolated tests use trusted synthetic attestations.
Signatures authenticate the issuer's assertion, not its truth.

The supported evidence classes are:

- `pre_dispatch_rejected`: the reservation remains unconsumed and the final
  journal records zero submissions. Its dispatch becomes `cancelled` and the
  intent becomes `confirmed_not_applied`.
- `definitive_non_delivery`: the consumed right has a final zero-submission
  journal. Its dispatch and intent become `confirmed_not_applied`.
- `api_rejected`: exactly one submission received an authenticated Kubernetes
  Status Failure for that exact request, without retries. The supported code and
  structured reason pairs are `400/BadRequest`, `401/Unauthorized`,
  `403/Forbidden`, `404/NotFound`, `409/Conflict`, `409/AlreadyExists`, and
  `422/Invalid`. Arbitrary 409/422 responses, human-readable error messages,
  timeouts, transport errors, 429 and 5xx cannot support this classification.

An accepted negative fact grants no execution authority, binds no UID and cannot
complete retirement. It applies only to this exact request: `AlreadyExists`
does not prove resource absence, and rejecting a PATCH does not prove an earlier
request never applied. Current attempt, status, cancellation, generation,
supersession and lifecycle are rechecked under canonical locks. Expiration of the
old dispatch lease alone does not invalidate a historical negative fact because
no new dispatch is authorized. Rolled-over or superseded attempts remain
unresolved through this conservative protocol. Repeated identical accepted bytes
return status without another event; altered evidence for a consumed challenge
rejects. Durable receipt records are append-only and contain the signed allowlisted
metadata, without raw API messages, capabilities, lease tokens or request payloads.

Ordinary unchanged or absent resource observations never become negative dispatch
evidence. An uncertain create retains its incarnation tombstone and delayed-create
risk unless a valid closed-journal proof exists. A newer observed barrier contains
future older conditional writes but preserves their historical uncertainty. There
is at most one unresolved recovery exception, no automatic generation loop and no
second consumption grant. If a recovery barrier remains unknown, another barrier
is blocked. A definitively resolved negative barrier can free that slot only for
new canonical work that independently passes fresh full-envelope and domain
authorization; its uncertain predecessor does not become definitively negative.
