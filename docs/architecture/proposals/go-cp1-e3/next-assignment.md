# Next bounded assignment — durable executor journal and evidence producer

E3 consumes authentic, exact terminal executor attestations. The next bounded
implementation must make those attestations truthful under real executor process
crashes. This prompt authorizes synthetic I/O only, not an active Kubernetes
executor or production authority transfer.

```text
# UTCP — GO-CP1 E4: Durable Executor Journal and Dispatch Evidence Producer

AI worker/coder: OpenAI Codex CLI
Model: GPT-6
Reasoning effort: High
Professional role: Principal Go Distributed Systems Engineer / Security Engineer
Execution role: IMPLEMENTER
Assignment: UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL
Repository: grimange/unified-telephony-control-plane

Objective
Implement the smallest isolated infrastructure-I/O journal that can truthfully
produce utcp.dispatch-evidence.v1 attestations. E3's canonical consumer verifies
signed exact evidence; it does not independently establish the executor's claims
of zero submissions, sole-request API rejection, or irreversible finalization.
Close that producer-side prerequisite using synthetic HTTP effects and actual
process crashes. Preserve E1/E2/E3 and the separate DB-authority/I-O roles.

Required tools
Git/ripgrep/shell and applicable AGENTS/skills; pinned Go 1.25.8 Docker SDK;
existing Go dispatch/receipt contracts; Ed25519 and ephemeral mTLS; deterministic
HTTP fixtures and fault injection; independent Go subprocesses; existing Docker
PostgreSQL 17.6 and pgx only for canonical integration in a separate process;
PHP 8.4/Laravel/PHPUnit reference; Go test/race/vet/mod verify/gofmt; JSON Schema,
repository hygiene, phase consistency, links, whitespace and secret checks.
No host packages or production credentials.

Baseline and governance
Read E1/E2/E3 reports, source, contracts and authority boundaries. Record current
branch/HEAD/staged/tracked/untracked state and SHA-256 preservation hashes.
Preserve every prior artifact. Act as Implementer; developer proof is not
independent acceptance. No ADR adoption, staging, commit, push or ledger change
without an evidenced current-state impact. Preserve accepted V1/K5/RMA-E state.

Authorized implementation
1. Build a focused private executor component with a crash-safe per-consumed-grant
   journal. Choose and document a minimal durable local persistence mechanism;
   distinguish durable fsync/commit from an in-memory record. Do not introduce a
   second canonical operation database or give the I/O process PostgreSQL access.
2. Bind journal identity permanently to exact dispatch/operation/attempt/executor,
   authenticated grant, request digest, immutable target/incarnation/UID,
   generation, fingerprint and action. Validate the grant and complete contract
   before any synthetic external submission. An untrusted caller cannot mint it.
3. Serialize journal ownership across competing processes. Durably record the
   possible-submission boundary before I/O. Consumption, process restart or a lost
   response must never create a second automatic external submission. Specify
   the unavoidable crash window between local durability and sending bytes.
4. Produce pre_dispatch_rejected or definitive_non_delivery only when the journal
   can irrevocably establish zero submissions and no in-flight/future submission
   under that right. Finalization must exclude a paused old worker resuming later.
   If quiescence or fencing cannot be established, retain unknown; no false zero.
5. Produce api_rejected only for one authenticated exact-request terminal API
   Status Failure covered by E3's code/reason contract. Disable mutation retries
   and redirects/replays that could create another submission. A proxy response,
   timeout, cancellation, ambiguous body, 429 or 5xx must remain unresolved.
   Never parse unstable Status messages as authority or equate GET absence with
   negative dispatch proof. Conflicting resources cannot be silently adopted.
6. Generate fresh challenged Ed25519 evidence from durable journal facts, with
   issuer/key/executor SAN/purpose/cluster/namespace restrictions. Re-challenging
   a closed journal may attest existing immutable facts; it cannot reopen work.
   Keep the current infrastructure V1/V2/V3 fingerprints and closed protocols.
7. Integrate only test transport with the existing canonical consumer. The
   canonical Go process has disposable PostgreSQL but no Kubernetes credentials;
   the synthetic I/O process has no database credentials. Verify actual process
   configuration and environment, not merely interface declarations.
8. Preserve observe-first behavior, uncertain creation tombstones, exact UID
   identity, bounded recovery slots and full fresh E2 domain checks for any newly
   authorized canonical reservation/consumption. No automatic replay, compensation
   or PHP fallback is permitted.

Required proof
Use deterministic barriers and actual killed/restarted processes before/after
journal commit, consume receipt, possible-send boundary, external acceptance,
response receipt, terminal finalization, signature creation and canonical result
commit. Include a paused competing stale worker, duplicate grants, lost consume
response, applied response lost, not-sent crash, ambiguous connection, malformed
and forged API response, altered journal identity, corrupted/truncated journal,
concurrent executors and shutdown cancellation. Show every grant yields at most
one automatic submission and every definitive negative attestation is justified.
Where a crash cannot distinguish sent from unsent, assert explicit unknown.

Exclusions
No real Kubernetes cluster/credentials/access/mutation, telephony commands or
traffic, production PHP/service/schema change, production database, executor
activation/deployment, public callback, database credentials in I/O, Kubernetes
credentials in canonical code, FreeSWITCH domain expansion, writer cutover,
implicit architecture adoption, host package installation or Git publication.

Deliverables
Functional isolated journal and signed producer; minimal versioned contract change
only if a genuine incompatibility is demonstrated; positive/negative fixtures;
actual subprocess/concurrent ownership tests; end-to-end synthetic mTLS consumer
integration; pinned one-command verifier; sanitized exact results and complete
changed-file/preservation inventory; one next task chosen from demonstrated gaps.

Validation and objective independent acceptance gates
Run the new verifier plus tests/go-cp1-e3/verify and relevant inherited offline
suites against final source. Run go test -count=1 ./..., go test -race -count=1
./..., go vet ./..., go mod verify and formatting in the pinned environment.
Require durable journal reconstruction, no duplicate automatic submission, no
zero-submission assertion while delivery remains possible, no late-worker send
after trusted closure, authenticated sole-request API rejection, strict signing
and replay protection, no cross-tenant/UID confusion, E2 positive/negative parity,
transactional audit/outbox, correct unknown classifications, actual separated
process credentials, cleanup, hygiene, phase consistency, link and secret checks.
Report unchanged baseline runtime-engine label and Vue scanner failures separately.

A reviewer must independently assess producer invariants, crash windows and trust
assumptions before acceptance. Isolated consumer/producer tests do not establish
production Kubernetes or telephony readiness and do not authorize cutover.
```
