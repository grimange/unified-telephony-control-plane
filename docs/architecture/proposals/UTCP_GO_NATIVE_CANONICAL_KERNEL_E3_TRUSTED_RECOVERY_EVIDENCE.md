# GO-CP1 E3 — trusted recovery evidence and bounded forward progress

Assignment: `UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_EVIDENCE`.
Date: 2026-10-22. Execution role: IMPLEMENTER. This report extends the
[E1 kernel](UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md) and
[E2 Asterisk policy](UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md).
Developer evidence prepares the implementation for independent review; it is not
independent acceptance, production readiness or authority transfer.

Worker: OpenAI Codex CLI. Requested model/reasoning: GPT-6 / High; no separate
runtime-model attestation is claimed. Professional role: principal
Go/PostgreSQL control-plane engineer and distributed-systems/security engineer.
Tools: repository inspection, pinned Docker Go/PHP/PostgreSQL environments,
pgx, Go test/race/vet/module tools, Ed25519, synthetic mTLS, deterministic
PostgreSQL transactions and killed Go subprocesses.

## Result and evidence boundary

**Verdict: `UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_VERIFIED`.**

The complete default E3 command exited zero against the final source, including
E2/E1, independent PHP parity, PostgreSQL concurrency/restart and offline Go
infrastructure regressions. All four disposable PostgreSQL cleanup records
confirm owned-resource removal. This is developer verification of the supported
isolated recovery contract, ready for independent review; it does not establish
production acceptance or a truthful deployed executor journal.

The implementation adds a separate challenged signed executor-evidence protocol
for affirmative nonapplication. It preserves the existing observation contract,
which cannot infer nonapplication from a currently unchanged or absent resource.
An exact signed observation can already confirm a recovery barrier whose response
was lost. E3 adds permanent coverage of that bounded forward-progress path rather
than replacing it or introducing another operation engine.

Current implementation is isolated Go migration code using canonical PostgreSQL.
Synthetic effect execution and signed fixtures establish code contracts, not real
Kubernetes observations or telephony behavior. No production callback route,
listener, executor deployment, Kubernetes credential, live mutation or writer
cutover is introduced. The operator-selected Go-native direction's ADR remains
**PROPOSED / AWAITING OPERATOR ADOPTION**.

## Repository baseline and preservation

Starting branch: `main`. Starting HEAD:
`5e028b9ab3d96f3430bf715f89d66ab19a758761`. Starting tree: 75 untracked files,
zero tracked modifications and zero staged files. A SHA-256 baseline covers
1,629 existing Git-visible regular project files. Prior E1/E2 and infrastructure
artifacts remain preserved; final comparison and all intentional modifications
are recorded in the [complete changed-file inventory](go-cp1-e3/changed-files.md).
The current comparison is 1,627 identical files, two intentional prior-file
changes, zero missing files and 19 additions. The task changes no tracked or
staged file; all 94 current untracked artifacts remain preserved.
No reset, clean, staging, commit or push is performed.

The accepted V1/K5 classifications and canonical RMA-E next action are outside
this isolated migration packet. Current-State Ledger Impact:
**NO_CHANGE_REQUIRED**. The phase-status consistency check passes.
No accepted ADR or historical proof report is rewritten.

## Source-backed recovery findings

The [decision matrix](go-cp1-e3/recovery-decision-matrix.md) identifies each evidence
source, exact identity, safe transition, remaining uncertainty and regression.
The E1 kernel already stores ordinary and recovery reservations separately,
consumes one exact operation attempt once, and rejects a recovery reservation
whose unresolved predecessor is itself recovery or an uncertain create.

`receipts.go::recoveryEvidence` allows fresh exact observation of a consumed
historical barrier even after canonical supersession. `AcceptReceipt` confirms
that exact recovery dispatch and marks its immutable predecessor dispatches
`contained`. Their historical intent outcomes remain unknown. A newer barrier
prevents applicable older conditional mutations arriving afterward; it does not
prove that those mutations never applied beforehand.

The missing behavior was affirmative negative dispatch evidence. Existing
`utcp.observation-receipt.v1` has only `bind`, `effect` and `absence`; none is
reinterpreted as a terminal transport journal. A failed GET, an unchanged field,
a timeout, a cancelled context, a process exit and a DELETE acknowledgement do
not prove nonapplication.

## Contract and implementation

`utcp.dispatch-evidence.v1` is a separate closed private protocol. Existing
`utcp.dispatch.v1`, `utcp.observation-receipt.v1`, infrastructure V1/V2/V3 actions
and their fingerprints retain their meanings. Unsupported versions, evidence
categories, properties, missing/null/duplicate fields, oversized bodies and
malformed JSON reject. The signature uses deterministic typed JSON with UTC
timestamps and unpadded base64url Ed25519.

The full envelope correlates receipt/issuer/key, challenge/nonce/audience/purpose,
tenant/RuntimeNode, cluster/namespace/physical name/incarnation/UID, exact intent,
dispatch, operation, attempt, executor, request digest, generation, fingerprint,
action, evidence category, journal identity, completeness, finality, submission
count and bounded timestamps. The attestation is assembled freshly after its
challenge; its immutable journal facts may describe an earlier execution. It
contains no lease token, capability, raw infrastructure request, private key or
arbitrary Kubernetes Status message.

| Component | Responsibility |
| --- | --- |
| `internal/kernel/dispatch_evidence.go` | Exact challenge, current canonical checks, transactional acceptance and deterministic negative classification |
| `internal/infrastructure/dispatch_evidence.go` | Strict parser, deterministic signing, issuer/key/SAN trust, freshness and category validation |
| `migrations/00002_dispatch_evidence.sql` | Subordinate challenges and append-only accepted evidence; no parallel canonical operation table |
| Contract schema and fixtures | Structural compatibility and explicit supported/unsupported evidence |
| E3 integration tests | Real PostgreSQL acceptance/races, synthetic signed evidence, actual process restart |
| `tests/go-cp1-e3/verify` | Pinned disposable PostgreSQL plus current E3/E2/E1/offline regression chain |

### Affirmative nonapplication semantics

| Evidence | Required fact | Dispatch result | Canonical consequence |
| --- | --- | --- | --- |
| `pre_dispatch_rejected` | Reserved right, final complete executor journal, zero submissions | `cancelled` | Intent `confirmed_not_applied`; no external execution claimed |
| `definitive_non_delivery` | Consumed/unknown right, journal irrevocably finalized with zero submissions and no future or in-flight send | `confirmed_not_applied` | Exact attempt resolved negatively; no consumed-attempt replay |
| `api_rejected` | Exactly one correlated external submission, terminal authenticated Kubernetes Status Failure, final journal and no automatic retry | `confirmed_not_applied` | That exact request rejected; no success, binding or retirement inferred |

The bounded structured API whitelist is 400/`BadRequest`, 401/`Unauthorized`,
403/`Forbidden`, 404/`NotFound`, 409/`Conflict` or `AlreadyExists`, and
422/`Invalid`. Code/reason must match; arbitrary 409/422 bodies or unstable
message text are not evidence. Timeout, throttling, 5xx, proxy/transport failure
and ambiguous responses reject. These are synthetic protocol tests in E3, not
new real-API proof. The future trusted executor must establish the response is
from the target API and corresponds to its sole submission.

Accepting negative evidence never binds a UID, marks successful execution,
completes retirement, creates a new reservation or grants dispatch. An
`AlreadyExists` rejection cannot adopt the conflicting resource; a DELETE
`NotFound` rejection does not replace the independent retirement absence receipt.
An exact accepted-byte retry is status-only; altered data on a consumed challenge
rejects. Receipt ID and challenge uniqueness prevent cross-operation reuse.

### Current authority and transaction boundaries

Cryptographic verification occurs before the transaction; canonical acceptance
then rechecks exact current incarnation/generation, intent supersession,
operation/attempt/status and lifecycle under existing lock ordering. Tenant,
RuntimeNode, immutable UID and request digest remain bound to persisted authority.
Preparing creation, bound effects and retiring deletion have distinct eligibility.
Attempt rollover, terminal operation, supersession and wrong identity reject.

An expired dispatch lease is not evidence of non-delivery. A fresh valid negative
attestation may still resolve its exact historical dispatch after lease expiry:
resolution grants no new execution authority. Every new reserve/consume still
passes the complete live lease, deadline, generation, lifecycle and E2 domain
policy. This is not positive authorization caching.

Accepted evidence, consumed challenge, exact dispatch/intent state, audit and
outbox event commit together. Failed acceptance or a rolled-back transaction
cannot leave a successful event. Idempotent evidence retries do not duplicate
transitions. Append-only evidence and existing append-only audit remain enforced.
No transaction spans external HTTP or Kubernetes calls. These tests exercise
READ COMMITTED transactions with explicit canonical locks and revalidation; they
do not claim database-wide SERIALIZABLE isolation.

## Recovery-barrier uncertainty and forward progress

The supported sequence is an uncertain consumed generation 10, an authorized
higher-generation recovery barrier, a lost barrier response, worker restart,
then fresh authenticated observation of that exact barrier. Confirmation clears
the recovery dispatch's uncertainty and contains only its recorded predecessors.
Current canonical authority can then reserve subsequent work through the ordinary
protocol. No consumed dispatch is granted twice; older historical uncertainty
remains recorded.

If the barrier is not established, its unresolved recovery slot remains occupied.
A second barrier cannot use recovery-of-recovery to evade that slot. Definitive
negative evidence for a failed recovery dispatch can release its slot while the
older ordinary dispatch stays unknown. A separately authorized new canonical
operation/generation may then request the one recovery slot. The bound is one
unresolved recovery slot, not a lifetime cap on operator-authorized recovery
operations. There is no automatic recursive generation loop.

When neither an exact barrier observation nor definitive negative evidence is
available, the kernel deliberately retains unresolved state. Safe progress may
require authenticated executor quiescence, stronger external acknowledgement or
credential/in-flight controls outside E3. No timeout invents that guarantee.

## Delayed creation, retirement and immutable identity

An uncertain `create_suspended` followed by exact current absence remains
uncertain: a previously dispatched POST may still arrive. A barrier cannot fence
a Deployment that does not yet exist. Its never-reused physical name, incarnation
history and cleanup-only late-creation handling remain intact. Terminal retirement
cannot reactivate an old incarnation and a same-name replacement UID cannot be
bound or resolved using old evidence. Only a legitimate finalized non-delivery
or terminal rejection attestation can resolve that exact creation dispatch
negatively; a caller's unsigned assertion cannot erase its history.

## E2 policy, security and privilege separation

The complete E3 verifier reruns the actual PHP E2 oracle and current Go comparison;
the independent PHP oracle produced 75 decisions: six positive fences, one
positive restoration, 29 fence denials and 39 restoration denials. Current Go
comparison and complete inherited regression results are recorded below.
Recovery evidence is not an alternate domain authorization route.
Fresh reserve/consume rechecks policy, attempts, lease, generation, UID,
incarnation, retirement and cross-version activation rules.

FreeSWITCH domain fencing/restoration remains unsupported. Existing Asterisk
source provenance, capacity/placement, active work, configuration and replacement
eligibility retain E2 semantics. Synthetic persisted state is not fresh runtime
telephony observation.

mTLS authenticates the current transport peer; Ed25519 preserves signed issuer
attribution and evidence integrity in durable storage. The key trust policy binds
issuer, authenticated executor SAN, cluster, namespace, purpose, validity and
revocation. Neither mechanism proves that an issuer truthfully finalized its
journal. Canonical code obtains no Kubernetes credentials; the future synthetic
I/O role obtains no PostgreSQL credentials. No public route is registered.

## Verification and exact results

Run the [pinned verification instructions](go-cp1-e3/verification.md):

```bash
tests/go-cp1-e3/verify
```

The final default command executes the following current-source stages. Each
nested verifier has a separate disposable PostgreSQL database; repeated runs are
not added as unique coverage.

| Stage | Actual result |
| --- | --- |
| E3 focused integration | 18 top-level and 50 nested passes; one expected child-helper skip; zero failures; 17.262 s |
| E3 complete Go module | 66 top-level and 301 nested passes; three expected child-helper skips; zero failures; integration 47.232 s |
| E3 complete race run | Passed; integration 96.901 s, infrastructure 2.070 s, kernel 1.017 s |
| Nested E2 focused parity/concurrency | 10 top-level and 101 nested passes; one expected child-helper skip; zero failures; 46.046 s |
| Nested E2 complete module / race | 66 top-level and 301 nested passes, three expected helper skips; integration 113.764 s ordinary / 198.444 s race; zero failures |
| Nested E1 complete module / race | 65 top-level and 226 nested passes; four expected skips; integration 142.313 s ordinary / 186.381 s race; zero failures |
| Formatting, vet and module integrity | Passed in all three canonical Go stages |
| PHP canonical PostgreSQL regression | E3 and nested E2/E1 each passed 30 tests / 117 assertions |
| PHP legacy SQLite domain regression | E3 and nested E2 each passed 148 tests / 1,445 assertions |
| Inherited canonical PostgreSQL | 19 tests / 80 assertions passed |
| Inherited PostgreSQL concurrency/restart | 11 tests / 115 assertions passed |
| Inherited operation/audit | 11 tests / 37 assertions passed |
| Additional inherited SQLite runtime/identity | 43 tests / 447 assertions passed |
| Canonical PostgreSQL PHP/Go wire | Two top-level and 25 nested ordinary passes; race passed |
| Generic effects / active / lifecycle Go | 27/143, 15/66 and 13/56 top-level/nested passes; two, three and two environment skips respectively; all race runs passed |
| Dedicated lifecycle Go | 15 top-level / 71 nested passes; zero skips; race passed |
| Dedicated active-profile Go | 18 top-level / 78 nested passes; zero skips; race passed |
| Observer Go | 21 top-level / 86 nested passes; zero skips; race passed |
| Infrastructure PHP | Effects 111 tests / 597 assertions, observer 59/368, lifecycle 20/209 and active profiles 20/267 passed; repeated before/after suites are not added |
| Python contract/validation suites | Three suites passed with 3, 6 and 3 tests |
| Existing infrastructure static documentation checks | GN-G1 67 files, GN-G2 69 files, lifecycle 26 files and active profiles 28 files passed |

PostgreSQL 17.6 applied 63 Laravel migrations and two subordinate Goose migrations;
repeating Laravel migrations was a no-op, and each protected Laravel catalog
comparison passed. The independent oracle produced three E1 fingerprints,
21 E1 claim decisions and 75 E2 domain decisions. The complete E3 and nested E2
runs both exercised the 75-case independent Asterisk parity oracle. Nested E1
intentionally lacks that E2 fixture: its fourth skip is the independent E2 oracle,
while the other three are subprocess entrypoints executed by their parent tests.
The reduction from 66/301 to 65/226 matches that one test and its 75 cases; it is
not new missing E3 coverage. The generic infrastructure stages' environment
skips are also explicit: the canonical PostgreSQL fingerprint and wire tests run
in the separate two-test/25-case PostgreSQL stage; the omitted active/lifecycle
PHP fixtures run in their dedicated later stages. A targeted wire-test filter
reports no matching tests in unrelated subpackages, as expected. No real
Kubernetes test is inferred from those filters.

All eight actual E3 process-kill windows passed. The
[verification coverage map](go-cp1-e3/verification.md) distinguishes synchronized
PostgreSQL races, cryptographic fixtures and inherited proof. Those eight windows
cover recovery reservation, consumption, synthetic effect, barrier confirmation
and both receipt families before/after commit. These are actual process and
PostgreSQL tests using synthetic external evidence; Kubernetes and telephony
claims remain outside this run. The complete default verifier exited zero.

All four run-owned PostgreSQL environments (E3, E2, E1 and inherited canonical
proof) report verified cleanup of their container, network and temporary volume.
No pre-existing resource was deleted, no PostgreSQL port was published, and
private credentials were removed. All three failed exploratory iterations also
verified their owned-resource cleanup. Temporary sanitized result records remain
under private `/tmp` output; raw logs, keys, credentials and synthetic envelopes
are not repository evidence.

### Root-cause corrections during developer verification

Initial verifier iterations exposed test composition defects, rather than a
Kubernetes mutation-semantics change. The existing independent E2 oracle requires
its E2 test marker; a test-only per-process override preserves that requirement
while the actual disposable E3 identity stays unique. The protected catalog
comparison originally included the two expected new subordinate evidence tables;
its existing relation exclusions now name exactly those tables while retaining
strict comparison of the Laravel schema. A negative fixture also attempted a
retirement-delete intent before UID binding; the canonical kernel correctly
rejected that state. The fixture correction exercises its intended evidence
condition without weakening lifecycle eligibility. The final verification run
uses all three corrections.

### Independent baseline findings

The following checks were reproduced before E3 edits and remain separate from
recovery implementation correctness:

- `make runtime-engine-config-check` fails because ordinary-worker assertions
  reject accepted K5 Kubernetes API-client labels on `utcp-platform/api`,
  `utcp-platform/scheduler` and `utcp-platform/telephony-reconciler`.
- `make secret-scan` flags the existing credential-form assignment at
  `apps/web/src/views/ExternalTrunkDetailView.vue:292`.

No check, security assertion, production permission or scanner exemption is
weakened. Both require their own focused baseline repair before relevant production
acceptance. Current repository hygiene, phase-status consistency, shell syntax
and `git diff --check` pass. All 21 task-owned files (19 new and two intentionally
modified) pass the existing scanner patterns, including Go/extensionless source;
there are zero task-file findings, trailing-whitespace errors or broken local
Markdown targets. The unchanged repository-wide scanner finding remains visible.
The final preservation comparison confirms 1,627 identical baseline files, the
two documented intentional changes and zero missing files.

## Remaining boundaries and next assignment

Inherent distributed uncertainty is retained explicitly; it is not a defect to
leave an unresolvable delayed delivery unresolved. Future production prerequisites
include a truthful durable executor journal, authenticated exact Kubernetes
observation issuance, immutable configuration publication, adopted authority
contracts, production composition, compatibility/independent acceptance and a
separately authorized writer transition. None is implemented by this packet.

The [single next implementation prompt](go-cp1-e3/next-assignment.md) implements
a durable isolated executor journal and truthful signed evidence producer using
synthetic I/O only. It targets the producer-side trust prerequisite exposed by
this consumer work and authorizes no deployment or writer cutover.
