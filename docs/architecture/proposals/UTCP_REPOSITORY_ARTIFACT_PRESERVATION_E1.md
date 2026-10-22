# Repository Artifact Preservation E1

Assignment: `UTCP_REPOSITORY_ARTIFACT_PRESERVATION_E1`.
Worker: OpenAI Codex CLI; requested GPT-6.1 Sol / Medium; repository maintainer,
release engineer and evidence custodian. No reasoning escalation or delegation.
Operator authorization: this assignment explicitly permits scoped staging, local
commits and UTCP-9 lifecycle updates. Push is excluded.

## Result and baseline

All 209 pending artifacts were preserved without changing their bytes. Starting
branch `main`, HEAD `5e028b9ab3d96f3430bf715f89d66ab19a758761`, four tracked
modifications, 205 untracked files, zero staged files. The existing infrastructure
foundation remains an unchanged ancestor. The three preservation commits leave
no pending original files. Two closure files are added by this assignment: this
report and the [exact inventory](repository-artifact-preservation-e1/inventory.json).
The inventory lists every preserved path, original Git status, classification,
mode, byte count, starting/committed SHA-256 and containing commit.

| Group | Commit | Files | Scope |
| --- | --- | ---: | --- |
| A | `2b57772e1a6d8f7d86ab37b051c5a5217fb78ca6` | 17 | Laravel/PostgreSQL canonical service, migration, concurrency tests, PHP/Go bridge and reproducible proof |
| B | `072969c0263c44f62cfa2f57b050609d2466e8b6` | 105 | Actual Go-native implementation, proposed architecture, contracts, SQL, modules, E1–E4 tests and sanitized evidence |
| C | `2232994d25086c3efc9a25099dc22b4e05f0c191` | 87 | Kaneo readiness, frozen K1/K2 payloads, task mappings, journal, K4/K5 acceptance/activation history and worker policy |

Commit messages respectively: `chore(utcp): preserve canonical infrastructure
authority foundation`; `feat(utcp): preserve go-native canonical kernel and
executor`; `chore(utcp): activate Kaneo tracker and preserve migration history`.
The third message preserves the previously completed activation; this assignment
does not activate or modify governance anew.

The closure commit contains this report and inventory. Its exact SHA is obtained
with `git log -1 --format=%H -- docs/architecture/proposals/UTCP_REPOSITORY_ARTIFACT_PRESERVATION_E1.md`
and is included in the terminal handoff and UTCP-9 after creation. A report cannot
contain its own resulting Git commit SHA without changing that SHA.

## Preservation and security proof

Each group used an explicit path allowlist. Before each commit, staged path sets,
full staged content, whitespace, security patterns and exact baseline SHA-256
were checked. Every one of the 209 committed blobs matches its starting SHA-256;
all 1,759 entry files also remain byte-identical in the worktree. No functional
source, historical report, frozen manifest or acceptance classification changed.
Executable source-script modes were preserved. No binary or symlink candidates
occurred. Every pending JSON file parsed, and all relative Markdown link targets
in the preservation set resolved.

Ignored materials remain untouched and excluded: local credentials and runtime
credential properties, `.runtime/`, dependencies, web build output, editor/agent
state and Python/PHP caches. Their exact path inventory is recorded in the JSON.
No pending non-ignored artifact was omitted. Synthetic fixture identifiers,
invalid/example addresses, fixture application keys and generated-at-runtime test
password instructions were distinguished from private credentials. No new secret
pattern matched any staged file, including extensionless verification scripts.
No raw database, executor WAL, private envelope, compiled binary or capture was
staged. Existing Git identity and hooks were retained; no bypass flags were used.

## Verification

- Before staging: `python3 docs/architecture/proposals/kaneo-tracker/k5-activation/validate-activation.py`
  passed: ACTIVE, ten task identities, approved import digest, journal and source
  hashes with authorized historical control-file transitions accounted for.
- Before and after the three preservation commits:
  `make repository-hygiene phase-status-consistency-check` passed;
  `make phase-status-consistency-check-test` passed all eight mutation cases;
  `git diff --check` passed.
- Every batch: `git diff --cached --check`, `--name-status`, `--stat`, staged
  blob equality and the existing secret scanner's patterns passed. Scoped
  allowlist sizes were exactly 17, 105 and 87; no staged remnants remained.
- Independent byte comparison used `git show COMMIT:PATH` and SHA-256 against
  the entry inventory, also checked each commit's changed path set and ancestor
  relationship. All 209 matched. The inventory's hashes allow repeating this
  check without historical HEAD assumptions.
- K2 canonical JSON digest remains
  `d093be6eb0f9d8c96d7485456610f153e30614114f9bfe59ced03097ba3c1efb`.
  Ten unique migration keys map to ten unique task IDs; all 19 journal operations
  retain VERIFIED state and exact argument digests.
- Host `go` is absent. Module parsing succeeded through the existing pinned
  `golang@sha256:4557cf171e3cdf5053a298d5171b1a5f5734d920260c25f22c79e94760eb2084`
  image using `go mod edit -json`, `--pull=never`, no network, read-only root and
  repository mount. Both local module replacements exist in committed history.
  No dependency installation or complete runtime verification chain was run.
- `git fsck --full` completed successfully. It reported dangling objects; these
  are retained, with no corruption reported and no pruning performed.
- The historical K5 validator deliberately pins the pre-preservation HEAD and
  untracked-file boundary. Its successful entry run remains historical proof;
  it must not be rewritten to hide the authorized commits. Post-commit checks
  use blob equality and ancestry instead.

Known unchanged baseline findings were reproduced explicitly:
`make secret-scan` reports only
`apps/web/src/views/ExternalTrunkDetailView.vue:292` (UTCP-8);
`make runtime-engine-config-check` reports ordinary api/scheduler/telephony
reconciler workload Kubernetes API-client labels (UTCP-7). No incidental fix or
scanner suppression was made. These do not invalidate scoped preservation.

## Kaneo and authority

UTCP-9 `n9boiu42malfsz9oet1xgi91` was read and matched to project
`hrilr4371g33s0iw6lth3o7v` and `utcp:preservation`. Its state moved from To Do
to In Progress before Git staging, with read-back confirmation. After all 209
source/evidence files were committed and verified, the developer handoff was
recorded and In Review was read-confirmed. Original imported description and
migration marker were retained; the new assignment authority and results were
appended. Closure commit identification is appended after its creation. No other
Kaneo task was changed, and no task was marked Done.

Kaneo stays ACTIVE for operational progress. Git retains engineering evidence,
formal review/operator decisions and phase/release authority. RMA-E remains
`RMA_E_S3_COMPATIBLE_ARCHIVE_ADAPTER_AND_DETERMINISTIC_MINIO_PROOF_IMPLEMENTATION_DESIGN`.
V1/K5 accepted scopes, GO-CP1 developer-verification versus independent review,
proposed Go-native ADR, and GO-CP2 execution authorization remain unchanged.

Current-State Ledger Impact: NO_CHANGE_REQUIRED.
Phase-Status Consistency Check: PASS.

## Remaining scope and next assignment

No original tracked modification or untracked artifact remains pending after the
three preservation commits. Closure files are committed together and the terminal
handoff verifies the final index/worktree. All commits are local. No push or
remote-publication claim is made; publication and accepted UTCP-9 closure remain
separate decisions. Independent GO-CP1 review and ADR adoption remain outstanding.

Ready-to-copy next assignment, effective only when separately submitted by the
operator:

```text
Assignment: UTCP_GO_NATIVE_IDENTITY_TENANCY_COMPATIBILITY_E1
Worker: OpenAI Codex CLI; requested GPT-6.1 Sol, Medium.
Role: bounded Go-native backend implementer.
Use existing Kaneo UTCP-6, task teautbtlkefukprbmn3ai7od in project
hrilr4371g33s0iw6lth3o7v. This submitted assignment authorizes only the existing
GO-CP2 E1 identity/tenancy compatibility scope after reading its actual contract.
Read root/path-local instructions, ACTIVE tracker policy, phase ledger, proposed
Go-native ADR, migration matrix, GO-CP1 E4 handoff and the GO-CP2 E1 assignment.
Read the exact task and dependencies through MCP; preserve its identity and
confirm the authorized implementation boundary before marking In Progress.
Implement the bounded compatibility contract with tenant/session/authorization
integrity and focused regression proof. Preserve existing work and acceptance
boundaries; do not adopt the ADR, change RMA-E priority, execute production
cutover, or expand into unrelated domains. No package installation, commit or
push is authorized by this next prompt. Run proportional required checks,
reconcile any actual formal ledger impact, preserve sanitized evidence in Git,
and move only UTCP-6 to In Review when developer handoff criteria pass. Do not
self-accept or mark Done. Report exact tests, gaps, files and remaining decisions.
```
