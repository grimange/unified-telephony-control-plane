# Status and authority crosswalk

The four Kaneo columns represent operational execution only. Store separate description properties for `execution`, `authorization`, `implementation`, `verification`, `independent_review`, `operator_acceptance`, `deployment`, `governance`, `blocked_reason`, `last_reconciled_revision` and `last_reconciled_hash`. These are structured conventions, not enforced custom fields. Git is proof/decision authority. Unknown means not established by inspected evidence; it is not a claim that an external decision never occurred.

| Source meaning | Kaneo column | Required additional properties | Closure rule |
| --- | --- | --- | --- |
| Planned / NOT_STARTED / proposed assignment | to-do | execution=backlog; authorization=not established; governance=proposed if applicable | Import is not a work grant |
| Eligible / READY | to-do | readiness=eligible; authorization separate | No invented Ready column |
| Explicitly authorized | to-do until work starts | authorization=scope/revision/decision link | Authorization alone is not implementation |
| In progress / IN_PROGRESS | in-progress | actual scoped assignment and worker attribution | Use observed report, not assumed activity |
| Implemented / developer-tested / VERIFIED by implementer | in-review | implementation=reported complete; verification=developer-tested; independent_review=not established; operator_acceptance=not established | Never automatic done |
| READY_FOR_REVIEW / ready for independent review | in-review | reviewer identity/outcome absent until established | Self-tests do not fill reviewer field |
| Independently verified/accepted | in-review | independent reviewer, exact artifact, result and Git proof link | Does not imply operator acceptance or deployment |
| Operator accepted | done only for scoped accepted outcome | explicit operator decision, exact scope/artifact; deployment still separate | Task Done does not mean production readiness |
| Complete and deployed | done only with required acceptance | source completion, independent/operator acceptance as actually recorded, deployed revision, environment and scoped live proof | API success is not readiness or natural-live telephony proof |
| Blocked / BLOCKED_BY_IMPLEMENTATION | retain current non-final column; Blocked label | blocker type, reason, owner if explicit, resumption gate | Never turn a baseline checker issue into reopened V1/K5 |
| BLOCKED_BY_ENVIRONMENT / DEFERRED_BY_ENVIRONMENT | to-do or retained column; Blocked only if active work truly blocked | environmental gate; no implied implementation defect | ADR-031 public-edge corridor remains separate from V1 closure |
| Deferred / future / post-core | to-do only if imported; otherwise Git link | execution=deferred; scope and trigger | No due date or authorization invented |
| Superseded | normally no import | governance=superseded with successor proof | Preserve original record; no new completion claim |
| Cancelled | retained source or to-do pending approved terminal convention | execution=cancelled, reason/decision link | Existing final column is Done; do not equate cancellation and acceptance |
| Historical complete/live-proven ledger entry | concise summary; initial to-do until import policy approved | historical-source-status verbatim; proof links; acceptance attribution=as recorded or unknown | Audit deliberately proposes no automatic historical closure |
| PROVEN / PARTIALLY_PROVEN / NOT_PROVEN | no column mapping by itself | verification dimension + precise proof scope | A proof label cannot determine execution status |
| ADR proposed / adopted / operator-authorized / superseded | no column mapping by itself | governance dimension with versioned Git record | Kaneo cannot adopt an ADR |

Template READY_FOR_RUNTIME_PROOF maps to in-review with verification=repository-tested and required_live_proof=pending. Template COMPLETE uses the exact author/proof/acceptance scope, never title-string classification. Historical residue remains historical; later proof closes only its stated gap. Every actual source status string is preserved in inventory-records.json and the preview; crosswalk selection is by meaning and attribution, not regex matching VERIFIED/COMPLETE.

| GO-CP1 assignment | Implementation | Developer verification | Independent acceptance | Operator acceptance | Production integration |
| --- | --- | --- | --- | --- | --- |
| E1 canonical kernel | Reported implemented with limitations | Reported pass against disposable PostgreSQL | Not established; separate reviews are not blanket acceptance | Not established | None claimed |
| E2 Asterisk policy parity | Reported implemented; addresses E1 Asterisk gap | Reported parity/concurrency/restart pass | Not established | Not established | None claimed |
| E3 trusted recovery | Reported implemented; addresses affirmative nonapplication/recovery | Reported developer pass | Not established | Not established | None claimed |
| E4 executor journal | Reported implemented in isolation | Reported developer pass, synthetic executor and retained-store probes | Not established | Not established | No real executor/Kubernetes deployment claimed |

Independent PHP oracle comparisons, concurrent independent connections, and separate read-only architecture/security input improve developer evidence; none proves a distinct final QA acceptance. This audit did not rerun these suites. E4 process/container restart evidence does not establish node-loss, volume-loss or Pod-rescheduling durability.

## Status owner by dimension

| Dimension | Current owner through K4 | Proposed owner after explicit K5 approval | Conflict handling |
| --- | --- | --- | --- |
| Operational assignment status, queue, work claim, current blocker | Git ledger for canonical phase state; proposal-local assignment reports for bounded modernization | Kaneo for approved assignment records only | Stop on concurrent edits or unmapped record; no latest-timestamp-wins |
| Phase completion, release acceptance, canonical next action | phase-status.md | Keep phase-status.md until a separate decision changes its role | Task counts/Done never rewrite phase acceptance |
| Architecture/adoption, scope, technical contract | Accepted ADRs / versioned specification | Git | Kaneo links decision; no reverse adoption sync |
| Implementation evidence and technical verification | Versioned report/tests/artifact identity | Git | Worker publishes claim linked to exact source; reviewer can dispute without rewriting history |
| Independent review decision | Versioned independent acceptance record | Git; Kaneo projects outcome | Only independent reviewer establishes outcome |
| Operator acceptance/authorization | Explicit operator decision retained in versioned governance record | Same; Kaneo projects link | Worker cannot infer approval from elapsed time or own test pass |
| Deployment/runtime readiness | Authorized deployment and scoped live evidence; runtime authorities | Same | Task transition cannot mutate production desired/observed state |

Recommendation combines B and C: Kaneo primary for explicitly transferred operational assignment dimensions; Git primary for engineering/governance and phase acceptance. During migration use A as a one-way, operator-reviewed projection. Do not maintain two editable authoritative copies of the same execution field. The ledger may remain a phase acceptance ledger; eventual generated operational snapshot is optional and requires validator/worker changes before adoption. No ledger retirement is decided here.
