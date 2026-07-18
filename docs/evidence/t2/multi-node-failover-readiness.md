# T2-C0 — Multi-Node Asterisk Conference Failover and Fencing Readiness

Date: 2026-07-18 (`utcp-local`). Evidence-only audit at commit `b701937`,
`UTCP_PHASE=T1`. No production code changed; no second Asterisk node created;
no live failover run.

**Roadmap placement.** Multi-runtime conference failover is defined by the
implementation plan as **Phase T5 — Multi-Runtime Convergence, Failover, and
Recovery** (`docs/unified-telephony-control-plane-initial-implementation-plan.md`
§ "Phase T5"), whose exit criteria include "failover is deterministic",
"existing calls are not silently migrated between runtimes", and "new-call
selection excludes unhealthy or draining resources according to policy". This
assessment therefore evaluates readiness for T5-class behavior; it is not a T2
exit requirement. T2 (single-node Asterisk conference execution and recovery)
is complete and live-proven.

## RuntimeNode selection

`TelephonyDomainService::selectRuntimeNodeForConference()`
(`apps/api/app/TelephonyDomain/TelephonyDomainService.php:575`) is the only
placement query. It filters `tenant_id`, `desired_state IN (active, draining)`,
`observed_state = 'ready'`, and existence of the requested capability
(`conference.lifecycle`); orders by `runtime_family, adapter_key, id`; takes the
first; aborts 422 if none.

- **Deterministic:** yes — total order on `(runtime_family, adapter_key, id)`.
  Two equally eligible nodes resolve deterministically by `id`, not randomly.
- **Capacity:** not considered. `runtime_nodes.capacity_weight` and
  `placement_priority` exist (`runtime_registry` migration lines 30–31) but are
  **unused** by this query.
- **Draining/maintenance:** `draining` is *included* as eligible for placement
  (questionable for new placement); `disabled` is excluded via the
  `desired_state` filter.
- **Capability gate:** yes — a technically-ready node without
  `conference.lifecycle` is excluded.
- **Stale node:** cannot be selected — `observed_state='ready'` is required, and
  a stale node projects `stale`/`unavailable`/`degraded`.
- **Scope of use:** *initial placement only*. Invoked from
  `changeConferenceDesiredState()` (line 263) when a conference is opened with no
  pre-assigned node, and never during recovery. Conferences opened with an
  explicit `runtime_node_id` (the proof path and `createConference`) bypass it
  entirely.

Selection is **not** used during recovery or failover: reconcilers always route
through the existing active binding (below), never re-select.

## RuntimeBinding authority

Placement is authoritative through `conference_runtime_bindings` plus the
mirrored `conferences.runtime_node_id`. Writes go through `writeBinding()`
(line 597, inserts `status='active'`) and the retire step in `bindRuntimeNode()`
(line 316, sets prior active → `retired`).

- **Reuse/exposure:** reconcilers and the listener/normalizer resolve the node
  via the single active binding (`ConferenceReconciler::activeRuntimeNodeId`
  line 116; `ConferenceParticipantReconciler::activeRuntimeNodeId` line 114).
- **Same-node Pod restart:** binding is retained (proven in T2-B; no rebinding
  occurs on restart — the node identity is unchanged).
- **Cross-node rebind of a live conference:** **not permitted today.** The only
  rebind path, `bindRuntimeNode()` (line 303), asserts
  `desired_state IN (draft, closed)` (line 308) and 422s otherwise — an **open**
  conference cannot be rebound. There is no failover transition and no
  original-node reclamation path.

**Binding contract classification: `immutable for Conference lifetime` in
practice** (replaceable only while draft/closed, i.e. not during the live
window where failover matters). A failover design must introduce an explicit,
generation-fenced replacement transition for the open state.

## Active-binding constraints

`create unique index conference_runtime_bindings_one_active on
conference_runtime_bindings (conference_id) where status = 'active'`
(domain migration line 105) — a **partial unique index enforcing exactly one
active binding per Conference at the database level**. This is the primary
split-brain guarantee and it is already enforced. Binding `status` is
CHECK-constrained to `('active','retired')` (line 113) — no failover/replacing
vocabulary. The binding row has `bound_at`/`unbound_at` but **no generation,
ownership epoch, previous-node, or replacement-reason columns**; the
Conference's `configuration_generation` is the only generation in play.

## Cross-node operation fencing

Operations carry `runtime_node_id` baked at creation
(`runtime_operations.runtime_node_id`, kernel migration line 18) and a unique
`(operation_type, idempotency_key)` (line 44). The adapter resolves the target
node **from the operation payload**, not from the current active binding
(`AsteriskRuntimeAdapter::execute` line 33). The only guard before touching
Asterisk is the uniform stale fence
`operationGeneration(op) < conference.configuration_generation` combined with a
desired-state check, applied to every operation type (adapter lines 201, 232,
262, and the participant/close paths).

Scenario *(op queued for node A → rebind to node B → old worker claims op)*:
the adapter would still resolve node A from the payload, but the operation
no-ops as stale **iff the rebind incremented `configuration_generation`**.
`bindRuntimeNode()` does bump the generation (line 312), so the mechanism is
correct — but it is gated to draft/closed and there is no open-conference
failover path to exercise it, and this cross-node case has **never been
tested**. There is no binding-generation or node-vs-active-binding check at
execution.

**Classification: sufficient only for same-node lifecycle fencing today.** The
generation fence would extend to the cross-node case *only if* a future failover
transition bumps the conference generation atomically with the rebind; that is
an untested extension, not established cross-node protection.

## Cross-node event fencing

`AsteriskAriEventNormalizer::normalizeConferenceEvent()` (line 93) resolves the
conference by joining `conference_runtime_bindings` with
`runtime_node_id = receipt.runtime_node_id AND status = 'active'` (lines 98–99).
An event whose source node is not the *current active binding's* node yields a
null conference and is dropped (`return []`).

Scenario *(node A event emitted → rebind to node B → delayed node A event
reaches normalizer)*: the active binding is now node B, so the join
`active.runtime_node_id (B) = receipt.runtime_node_id (A)` fails and the delayed
node-A observation is structurally discarded. Runtime-node readiness
observations write only `runtime_nodes` and never conference/participant state,
so they cannot cross-contaminate.

**Classification: cross-node safe by construction** for conference/participant
projection — the normalizer's live active-binding join is exactly the required
fence. (This is stronger than the operation fence and is the audit's most
reassuring finding, though it too has no dedicated cross-node regression test.)

## Runtime inspection routing

Both reconcilers inspect strictly through `activeRuntimeNodeId` (the single
active binding). Therefore:
- bound node ready → inspect the bound node;
- bound node unavailable → inspection returns `unavailable`/`failed`, reconciler
  returns `waiting(..._runtime_inspection_unavailable, 30)` and **retries
  against the same node indefinitely**;
- another eligible node ready → **ignored** (no reselection without a rebind);
- after a (hypothetical) rebind → the next evaluation inspects the new node
  automatically, because routing reads the active binding live;
- former node returns → its readiness observation wakes only conferences still
  bound to it (none, after a rebind).

No path reselects without rebinding, inspects all nodes, or trusts projected
absence over runtime inspection. There is **no path that inspects the wrong node
after a rebind** — routing is always binding-scoped.

## Recovery wake behavior

Automatic wake sources: RuntimeNode readiness projection →
`wakeBoundConferenceRecoveryTargets` (`ProjectionService:52,80`, wakes targets
bound to *that* node); ARI conference events →
`wakeConferenceRecoveryFromAriEvent` (listener line 197); operation completion →
`RuntimeOperationRepository::complete()` rewakes the aggregate target; the
per-minute scheduler sweep (`runtime-engine:reconciler --once`,
`telephony-domain:ensure-targets`, `asterisk-ari:ensure-targets`).

Every wake path re-targets the **currently bound** node. Nothing wakes a
conference to *rebind to a different node* when its bound node fails. Recovery of
a conference on a failed bound node therefore **retries forever against the
unavailable node** (bounded backoff, no false projection — safe but stuck). The
concrete missing component is an **automatic cross-node failover coordinator**:
a decision path that, on sustained bound-node unavailability, selects a
replacement and performs the atomic rebind.

## Existing schema support

| Field / concept | Status |
|---|---|
| one active binding per conference | **present and used** (partial unique index) |
| binding status vocabulary | present (`active`/`retired` only), used |
| conference `configuration_generation` | present and used (operation/event fence) |
| `placement_priority`, `capacity_weight` | **present but unused** by selection |
| `placement_region`, `placement_zone` | present but unused by selection |
| node `draining` desired state | present and representable |
| binding generation / ownership epoch | **absent** — not strictly required (conference generation already fences); would aid auditability |
| previous RuntimeNode / replacement reason / failover attempt | **absent**; required only for auditable coordinator decisions |
| node last-healthy timestamp | **absent**; required if failover eligibility uses an unavailability duration |
| per-node capacity accounting | absent; not required for a first single-replacement slice |

No new schema is *required* for a correctness-safe first rebind: the existing
conference generation and active-binding join already provide old-operation and
old-event rejection.

## Current Kubernetes topology assumptions

Single-node by construction: one `asterisk-ari` Deployment (`replicas: 1`), one
Service `asterisk-ari`, one credential Secret
`utcp-local-asterisk-ari-credentials`, one advertised ARI endpoint
`asterisk-ari.utcp-runtime.svc.cluster.local:8088` (ClusterIP, internal only —
no public SIP/RTP). RuntimeNode records are registered through the authenticated
C2 API with a per-node endpoint host, and listener leases/event epochs are keyed
per **event source**, which is per RuntimeNode
(`EventSourceRepository::ensureRuntimeNodeSource`,
`RuntimeListenerLeaseRepository::claim` by `event_source_id`).

Two-node coexistence answers:
- Two Asterisk Deployments without collision: **requires new manifests** — a
  second Deployment/Service/Secret with distinct names and a distinct RuntimeNode
  record + endpoint host. No architectural blocker.
- Distinct RuntimeNode record each: **yes** (C2 supports N nodes; 26 records
  already exist, one active).
- Independent listener subscription each: **yes** — per-node event source, lease,
  and epoch already exist.
- Node-specific lease keys: **yes** (`event_source_id`, one per node).
- Node-specific event source names: **yes** (`source_key` per node).
- Unexpected public SIP/RTP: **no** — ARI is internal ClusterIP; the config-check
  already forbids SIP/RTP/trunk vocabulary in the Asterisk manifests.
- Second node without changing standard edge ownership: **yes** — no Gateway/edge
  change needed (internal ARI only).

## Failover state machine (derived, not implemented)

Reusing existing vocabulary (conference `observed_state`, node `observed_state`,
binding `status`, `configuration_generation`):

```
BOUND_READY              active binding node observed ready; normal reconcile
BOUND_UNAVAILABLE        bound node observed unavailable/stale; reconciler waits
FAILOVER_ELIGIBLE        BOUND_UNAVAILABLE sustained ≥ eligibility window AND a
                         distinct ready+capable node exists
REBINDING                atomic tx: retire old active binding, insert new active
                         binding (node B), bump configuration_generation, update
                         conferences.runtime_node_id  (one-active index enforces
                         the cutoff)
RECONSTRUCTING           existing recovery path reconstructs bridge-before-
                         participant on node B via generation-current operations
CONVERGED_ON_REPLACEMENT conference/participant observed on node B; converged
FORMER_NODE_RETURNED     node A readiness wakes only targets still bound to A
                         (none); its stale ops/events are already fenced
```

- Eligibility: automatic and deterministic once (a) bound node unavailable for a
  bounded window and (b) a distinct eligible node exists. No operator approval —
  no documented business/security contract requires one.
- Atomic cutoff: the single `REBINDING` transaction plus the one-active partial
  unique index. The generation bump activates the operation fence; the binding
  join activates the event fence.
- Reconstruction order: unchanged — bridge before participant (existing
  reconcilers).
- No replacement ready → remain `BOUND_UNAVAILABLE` and wait (current behavior).
- Failure during rebind → transaction rolls back; old binding remains
  authoritative; retried on next sweep.

## Split-brain prevention invariants

| Invariant | Enforced today |
|---|---|
| one active RuntimeBinding per Conference | **yes** (partial unique index) |
| one authoritative RuntimeNode per Conference | yes (mirrored `runtime_node_id` + binding) |
| old-node operations cannot execute after cutoff | **partial** — via generation fence *iff* rebind bumps generation; untested cross-node |
| old-node events cannot project after cutoff | **yes** (normalizer active-binding join); untested cross-node |
| replacement reconstructs exactly one bridge | yes (existing idempotent ensure + generation fence) |
| each desired participant reconstructed at most once | yes (existing participant reconciler + idempotency) |
| returning former node cannot auto-reclaim | **yes** — no reselection/reclaim path exists |
| former-node cleanup is best-effort, cannot restore authority | yes — cleanup is inspection-driven and binding-scoped |

## Failure-scenario assessment

1. **Bound node loses ARI, Pod running** — listener detects lost Stasis
   subscription (T2-B ab3157d), node projects unavailable; reconciler waits.
   *Missing:* failover trigger. 2. **Bound Pod deleted** — same-node
   reconstruction proven (T2-B); cross-node failover *not* triggered. 3.
   **Unavailable during bridge creation** — operation retries/ waits on same
   node; no cross-node move. 4. **Fails after bridge before participant** —
   same-node recovery reconstructs; cross-node not triggered. 5. **Binding
   changes while old op queued** — *no live path produces this yet*; generation
   fence would no-op the old op if a rebind bumped generation (untested). 6.
   **Old node returns after replacement** — safe by construction (no reclaim);
   untested because no rebind path. 7. **Delayed old-node event after rebind** —
   dropped by normalizer binding join (safe by construction; untested
   cross-node). 8. **Replacement fails during reconstruction** — existing
   recovery waits/ retries on the (new) bound node. 9. **Two equally eligible
   replacements** — deterministic by `id` order (selection is total-ordered). 10.
   **No eligible replacement** — remain waiting; no false projection. 11.
   **Old-node listener half-open** — heartbeat + Stasis-registration check tears
   down and reconnects (T2-B); per-node lease prevents cross-node interference.
   12. **DB tx fails during rebind** — atomic rollback; old binding retained.

Common theme: correctness fences (one-active index, generation, binding join)
are in place; the **trigger, the atomic open-conference rebind, and cross-node
regression proof** are the gaps.

## Existing implementation

Deterministic selection; one-active-binding DB invariant; binding-scoped
inspection routing; cross-node-safe event projection (normalizer binding join);
uniform generation/desired-state operation fence; idempotent operations;
per-node listener leases/event epochs; `placement_priority`/`capacity_weight`
columns; `draining` node state; same-node restart reconstruction (T2-B).

## Missing implementation

1. Atomic **open-conference binding replacement** transition (generation-fenced
   cutoff) — the earliest dependency.
2. **Cross-node fencing regression proof** (old-node op rejected; old-node event
   dropped) — currently only structurally true, never tested.
3. Automatic **failover coordinator** (unavailability-window detection +
   replacement selection excluding the current node) — a later slice.
4. **Second-node Kubernetes manifests** + registration — a later slice.
5. Live two-node failover acceptance — the final slice.
6. Optional auditability schema (previous-node, replacement-reason, last-healthy)
   — only if the coordinator's eligibility/audit requires it.

## Tracked non-blocking gaps

Listener backlog/emit-to-read metrics; stale no-op metrics; NetworkPolicy
endpoint-drift hardening; T2 Compose compatibility; final T2 phase-wide
acceptance — **none is a prerequisite** for the first slice. The one adjacency:
unknown-event RuntimeNode `degraded` flapping (T2-B12 §10) is **relevant to the
later coordinator slice** (an eligibility check keyed on `observed_state` must
not read transient `degraded` as failover-worthy), but does not block the
atomic-rebind primitive slice.

## Implementation-readiness decision

**C — multiple ordered slices are required, with the first clearly defined.**
Full failover needs, in order: (1) atomic rebind primitive + cross-node fencing
tests, (2) failover coordinator/trigger, (3) second-node manifests, (4) live
two-node acceptance. These must not be bundled. The first slice is fully
specified below with exact classes, authority boundary, transition, fencing
requirement, and tests, and carries no unresolved business decision.

## First bounded implementation slice

**Atomic open-conference RuntimeBinding replacement primitive + cross-node
fencing regression proof (repository-only, no live failover, no second node, no
coordinator trigger).**

- **Authority boundary:** C5 desired/placement authority
  (`TelephonyDomainService`) performs the rebind; the generic engine and adapter
  are unchanged; the projector remains the sole observed-state writer.
- **Transition:** a new domain method that, given a conference whose *current
  active binding's node is observed unavailable* and a *distinct* eligible
  replacement (reuse `selectRuntimeNodeForConference`, excluding the current
  node), atomically: retires the active binding, inserts a new active binding
  for node B, increments `configuration_generation`, updates
  `conferences.runtime_node_id`, and ensures the reconciliation target — mirrored
  on the existing `bindRuntimeNode` body but permitted for `open` conferences and
  guarded by the unavailability precondition. It is **not** a manual management
  API; it is an internal primitive the future coordinator will call.
- **Fencing requirement (the proof):** regression tests establishing that after
  the rebind, (a) an operation created for node A completes stale (generation
  fence) and never calls node A's client, and (b) a delayed node-A conference/
  participant event is dropped by the normalizer's active-binding join. These
  lock the cross-node guarantees that are today only structural.
- **Excluded:** coordinator/trigger scheduling, unavailability-duration policy,
  second-node manifests, live failover, any new schema.

## Ready-to-paste Codex prompt

```
# T2-C1 — Atomic open-conference RuntimeBinding replacement + cross-node fencing tests

Repository-only slice at HEAD b701937. Keep UTCP_PHASE=T1. Do NOT create a
second Asterisk node, run live failover, add a coordinator/trigger, add schema,
or add any manual management API/CLI.

Implement one internal domain primitive and its regression proof.

1. TelephonyDomainService (apps/api/app/TelephonyDomain/TelephonyDomainService.php):
   add a method `failoverRebindConference(string $tenantId, string $conferenceId): ?string`
   that runs in a single DB::transaction and:
   - locks the conference row (lockForUpdate); returns null if not found or
     desired_state !== 'open';
   - resolves the current active binding's runtime_node_id;
   - returns null unless that node's observed_state is one of
     ('unavailable','stale','degraded') AND desired_state NOT in ('active')
     ... i.e. the current node is not a healthy execution target. (Read the node
     row; treat only observed_state='ready' + desired_state in ('active','draining')
     as healthy; otherwise it is a failover candidate.)
   - selects a replacement via the existing selection logic but EXCLUDING the
     current node id (add an optional `excludeNodeId` param to
     selectRuntimeNodeForConference); returns null if none eligible;
   - atomically: update the active conference_runtime_bindings row to
     status='retired', unbound_at=now(); insert a new active binding for the
     replacement node (reuse writeBinding); bump conferences.configuration_generation
     by 1; set conferences.runtime_node_id = replacement; ensureTarget for the
     conference at the new generation; emit a 'conference.runtime_binding_failed_over'
     domain event (aggregate conference) carrying only configuration_generation
     (NO node/bridge/channel identifiers in the payload);
   - returns the replacement node id.
   The one-active partial unique index must continue to guarantee a single active
   binding; rely on it, do not weaken it.

2. Do not change the adapter, reconcilers, normalizer, projector, or command
   worker. The existing generation fence and normalizer active-binding join must
   remain the cutoff mechanisms.

3. Regression tests (place in tests/Feature/Asterisk/AsteriskConferenceRecoveryTest.php
   or a new AsteriskFailoverFencingTest.php):
   - failover rebind of an open conference whose bound node is observed
     'unavailable' moves the single active binding to a distinct ready node,
     bumps configuration_generation, and leaves exactly one active binding
     (assert the partial unique index invariant);
   - failover rebind is a no-op (returns null, no binding change, no generation
     bump) when the bound node is observed 'ready';
   - failover rebind is a no-op when no distinct eligible replacement exists;
   - AFTER a rebind, executing a conference.participant.ensure operation that was
     built for the OLD node/old generation returns the stale outcome
     (runtime_operation.asterisk_conference_participant_stale) and does not invoke
     the ARI client (use the existing fake/stub client pattern in the test file);
   - AFTER a rebind, normalizing a conference/participant ARI receipt whose
     runtime_node_id is the OLD node yields zero observations (normalizer
     active-binding join drops it), while the same event from the NEW node
     normalizes.

4. Update docs/evidence/t2/multi-node-failover-readiness.md with a short
   "First slice implemented" note (primitive + which cross-node fences are now
   test-locked; coordinator/second-node/live proof still pending).

Verification (all must pass):
  make repository-hygiene && make secret-scan
  make runtime-engine-config-check && make telephony-domain-config-check
  make asterisk-ari-config-check && make asterisk-conference-config-check
  make runtime-engine-test && make telephony-domain-test
  make asterisk-ari-test && make asterisk-conference-recovery-test
  make test && make check && make build
  git diff --check

One scoped commit, e.g.:
  feat(t2): atomic open-conference binding failover primitive with cross-node fencing tests
Do not push. Do not run live failover, a second Asterisk deployment, or the
full recovery corridor suite.
```

## Staged T2-C acceptance criteria

**Repository acceptance:** deterministic eligible-node selection (excluding the
current node on failover); exactly one active binding (partial unique index);
atomic authority cutoff (single rebind transaction + generation bump);
old-operation rejection (generation fence, test-locked); old-event rejection
(normalizer binding join, test-locked); replacement reconstruction
(bridge-before-participant, existing reconcilers); original-node non-reclamation
(no reselect path); focused tests green.

**Live acceptance (later, not in this audit):** two ready Asterisk RuntimeNodes;
conference bound to node A; bridge+participant only on A; A made unavailable;
binding moves exactly once to B; exactly one bridge and one participant
reconstructed on B; returning A cannot change projection or binding; no
duplicate bridge/channel; final cleanup on both nodes; replica counts restored.
