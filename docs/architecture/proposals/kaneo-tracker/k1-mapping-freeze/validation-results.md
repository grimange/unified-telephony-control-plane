# K1 validation results

All checks below were run locally against the frozen packet. No Kaneo writes,
package installation, production access or full GO-CP1 runtime suite occurred.

| Command / check | Result |
| --- | --- |
| `make repository-hygiene phase-status-consistency-check` | PASS, exit 0; hygiene and local cutoff checks pass; phase status reports changed=166, evidence=0, status-impact=no |
| `make phase-status-consistency-check-test` | PASS, exit 0; all 8 mutation cases |
| `python3 docs/architecture/proposals/kaneo-tracker/reproduce-preview.py --check` | PASS, exit 0; earlier frozen preview remains reproducible |
| `python3 docs/architecture/proposals/kaneo-tracker/k1-mapping-freeze/validate-packet.py` | PASS, exit 0; identities, sources, payloads, snapshots, links, secrets, whitespace and preservation |
| Two separate `validate-packet.py --canonical-payload` invocations | PASS; byte-identical output and SHA-256 matches frozen packet digest |
| `git diff --check` | PASS, exit 0 |
| `make runtime-engine-config-check` | Existing baseline failure, make exit 2: ordinary api/scheduler/telephony-reconciler workers carry accepted K5 Kubernetes API-client labels |
| `make secret-scan` | Existing baseline failure, make exit 2: sole reported location apps/web/src/views/ExternalTrunkDetailView.vue:292 |

The two baseline failures were reproduced and preserved, without incidental repair
or weakened checks. All 19 new K1 files pass the repository scanner patterns in the
local packet validator. Checks inspect untracked deliverables directly; Git diff
alone cannot validate their contents. JSON syntax, canonical ordering, exact MCP
create fields, neutral priorities, absent owners/dates, all eight status dimensions,
label references and parent/dependency endpoints are checked. The historical hard
prerequisite graph and selected hierarchy are acyclic. No selected active blocks
edge is invented. Structurally pinned remote URLs have verified local HEAD bytes;
remote publication/access remains an explicit prerequisite, not a claimed pass.

## Preservation and scope proof

Starting branch main / HEAD 5e028b9ab3d96f3430bf715f89d66ab19a758761 is unchanged.
The four starting tracked documentation modifications remain byte-identical:
AGENTS.md, CLAUDE.md, docs/AGENTS.md and docs/roadmap/phase-status.md. All 1,697
baseline file SHA-256 values and all 143 original untracked files match. All 505
source manifest entries match current bytes; 499 catalog artifacts reconcile as
495 unchanged and four prior authorized Governance E1 changes. All 151 original
source IDs plus one governance record are mapped. The validator checks the exact
empty paginated project snapshot and seven recorded read-only calls.

Current state: four pre-existing tracked modifications, zero staged changes,
162 untracked files (143 preserved plus 19 K1 deliverables). No existing file was
modified by K1. No stage, commit or push. Activation remains SELECTED_NOT_ACTIVE;
current Git operational and formal ledger authority remain intact. No technical
phase classification, roadmap priority, ADR adoption or production state changes.
Current-State Ledger Impact: NO_CHANGE_REQUIRED.
Phase-Status Consistency Check: PASS.

The original audit preservation verifier is intentionally not treated as a current
whole-tree preservation gate: Governance E1 subsequently changed four authorized
documents. Its historical hashes remain untouched. K1 records refreshed source
hashes and verifies its own starting snapshot instead.
