# T2-C0 — Multi-Node Asterisk Conference Failover and Fencing Readiness

Date: 2026-07-18 (`utcp-local`). Evidence-only audit at commit `b701937`,
updated by the T5-A1 repository slice, `UTCP_PHASE=T1`. No second Asterisk node
created; no live failover run.

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

`TelephonyDomainService::selectRuntimeNodeForConference()` is the canonical
placement query. It filters `tenant_id`, `desired_state IN (active, draining)`,
`observed_state = 'ready'`, and existence of every requested capability; orders
by `runtime_family, adapter_key, id`; takes the first; aborts 422 if none. The
T5-A1 slice extends it so internal callers can exclude the currently bound
RuntimeNode when selecting a replacement.

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
- **Scope of use:** initial placement and the internal T5-A1 atomic rebind
  primitive. There is still no automatic failover coordinator.

Reconcilers still never re-select on their own: they route through the current
active binding. Replacement selection is available only inside the internal
atomic rebind primitive; a future coordinator must decide when to invoke it.

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
- **Cross-node rebind of a live conference:** available only as the internal
  T5-A1 primitive `failoverRebindConference()`. The former binding is retired
  and its runtime authority is cut off atomically before one replacement active
  binding is created. `bindRuntimeNode()` remains limited to draft/closed
  operator-directed placement changes.

**Binding contract classification: `single active authority with explicit
cutoff`**. The active binding remains authoritative for reconcilers and event
normalization. The T5-A1 primitive permits an open conference to move only when
the currently bound RuntimeNode is not observed `ready` and a distinct ready,
capable replacement exists.

## Active-binding constraints

`create unique index conference_runtime_bindings_one_active on
conference_runtime_bindings (conference_id) where status = 'active'`
(domain migration line 105) — a **partial unique index enforcing exactly one
active binding per Conference at the database level**. This is the primary
split-brain guarantee and it is already enforced. Binding `status` is
CHECK-constrained to `('active','retired')` (line 113). The primitive does not
add a failover/replacing status or any new schema. The Conference's
`configuration_generation` remains the fencing generation, and the audit/outbox
event records the bounded replacement reason.

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
no-ops as stale after the T5-A1 primitive increments
`configuration_generation`. `AsteriskRuntimeAdapter` preserves the stale
generation fence before any ARI mutation. The regression
`test_old_node_operation_is_stale_after_atomic_runtime_binding_replacement`
proves the old operation keeps node A, the active binding is node B, the
conference generation is higher than the operation generation, and no node-A ARI
mutation is called.

**Classification: repository-proven for the primitive.** Live two-node operation
fencing remains pending until the future coordinator and second-node runtime
slice exist.

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

**Classification: repository-proven for the primitive.** The regression
`test_delayed_old_node_conference_and_participant_events_do_not_project_after_rebind`
proves delayed node-A conference and participant events produce no normalized
observations after the active binding moves to node B.

## Runtime inspection routing

Both reconcilers inspect strictly through `activeRuntimeNodeId` (the single
active binding). Therefore:
- bound node ready → inspect the bound node;
- bound node unavailable → inspection returns `unavailable`/`failed`, reconciler
  returns `waiting(..._runtime_inspection_unavailable, 30)` and **retries
  against the same node indefinitely**;
- another eligible node ready → ignored unless the internal primitive is
  invoked;
- after a rebind → the next evaluation inspects the new node
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

Every wake path re-targets the **currently bound** node. The T5-A1 primitive
wakes the conference and admitted-participant reconciliation targets after the
replacement binding is committed, so existing workers reconstruct against the
replacement node. Nothing automatically decides to rebind when the bound node
fails. The concrete missing component is an **automatic cross-node failover
coordinator**: a decision path that, on sustained bound-node unavailability,
selects a replacement and invokes the atomic primitive.

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
| previous RuntimeNode / replacement reason / failover attempt | absent as schema; T5-A1 records bounded reason in audit/outbox |
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

## Failover state machine

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

- Eligibility: still pending for the future automatic coordinator. The T5-A1
  primitive only enforces the bounded preconditions when invoked internally.
- Atomic cutoff: the single `REBINDING` transaction plus the one-active partial
  unique index. The generation bump activates the operation fence; the binding
  join activates the event fence.
- Reconstruction order: unchanged — bridge before participant (existing
  reconcilers).
- No replacement ready → the primitive fails before mutation; the existing bound
  node remains authoritative.
- Failure during rebind → transaction rolls back; old binding remains
  authoritative.

## Split-brain prevention invariants

| Invariant | Enforced today |
|---|---|
| one active RuntimeBinding per Conference | **yes** (partial unique index) |
| one authoritative RuntimeNode per Conference | yes (mirrored `runtime_node_id` + binding) |
| old-node operations cannot execute after cutoff | **yes** for T5-A1 repository primitive; generation fence tested |
| old-node events cannot project after cutoff | **yes** for T5-A1 repository primitive; normalizer active-binding join tested |
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
   changes while old op queued** — T5-A1 proves the old operation completes
   stale and does not call ARI after the generation bump. 6.
   **Old node returns after replacement** — safe by construction (no reclaim).
   7. **Delayed old-node event after rebind** — T5-A1 proves the normalizer
   returns no conference/participant observations from node A after node B
   becomes active. 8. **Replacement fails during reconstruction** — existing
   recovery waits/ retries on the (new) bound node. 9. **Two equally eligible
   replacements** — deterministic by `id` order (selection is total-ordered). 10.
   **No eligible replacement** — remain waiting; no false projection. 11.
   **Old-node listener half-open** — heartbeat + Stasis-registration check tears
   down and reconnects (T2-B); per-node lease prevents cross-node interference.
   12. **DB tx fails during rebind** — atomic rollback; old binding retained.

Common theme: correctness fences (one-active index, generation, binding join)
are in place and the atomic open-conference rebind primitive now exercises
them. The **automatic trigger/coordinator and live two-node proof** remain the
gaps.

## Existing implementation

Deterministic selection with current-node exclusion; atomic open-conference
RuntimeBinding replacement primitive; one-active-binding DB invariant;
binding-scoped inspection routing; cross-node-safe event projection (normalizer
binding join); uniform generation/desired-state operation fence; idempotent
operations; per-node listener leases/event epochs; `placement_priority`/
`capacity_weight` columns; `draining` node state; same-node restart
reconstruction (T2-B).

## Missing implementation

1. Automatic **failover coordinator** (unavailability-window detection +
   replacement selection excluding the current node) — a later slice.
2. **Second-node Kubernetes manifests** + registration — a later slice.
3. Live two-node failover acceptance — the final slice.
4. Optional auditability schema (previous-node, replacement-reason, last-healthy)
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

**C — multiple ordered slices are required.** T5-A1 completes the first
repository slice: atomic rebind primitive plus cross-node fencing tests. Full
failover still needs, in order: (1) failover coordinator/trigger, (2)
second-node manifests, (3) live two-node acceptance. These must not be bundled.

## T5-A1 bounded implementation slice

**Atomic open-conference RuntimeBinding replacement primitive + cross-node
fencing regression proof (repository-only, no live failover, no second node, no
coordinator trigger).**

- **Authority boundary:** C5 desired/placement authority
  (`TelephonyDomainService`) performs the rebind; the generic engine and adapter
  are unchanged; the projector remains the sole observed-state writer.
- **Transition:** `failoverRebindConference()` is an internal domain method
  that, given a conference whose *current
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

## T5-A1 repository proof

The T5-A1 repository slice adds focused regression coverage for successful
replacement, no-op while the bound node remains ready, no eligible replacement,
distinct replacement selection, transaction rollback, repeated invocation after
authority has moved, stale old-node operations, and delayed old-node events. It
does not run live failover and does not create a second Asterisk deployment.

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

---

# T5-A2 — Automatic Failover Coordinator Sweep

Repository implementation at `UTCP_PHASE=T1`, 2026-07-18. Implements the bounded
automatic coordinator that evaluates sustained bound-RuntimeNode unavailability
and invokes `failoverRebindConference()` (T5-A1). No live failover proof, second
RuntimeNode deployment, or automatic failback is included.

## Canonical RuntimeNode health evidence

`runtime_nodes` carries exactly one health timestamp: `observed_at` (nullable),
plus `observed_state`, `last_evidence_source`, `last_observation_id`,
`observed_configuration_version`, and `updated_at`. There is **no**
`last_ready_at`, `last_failed_at`, or `unavailable_since` column.

| Field | Authority / cadence | Persistence | Survives restart | Failover-suitable |
|---|---|---|---|---|
| `observed_state` | projector (sole writer), on each observation | durable | yes | yes — the qualifying signal |
| `observed_at` | projector; = last observation's time; advances every heartbeat while actively observed | durable | yes | **weak** — "last observation", not "unavailable since" |
| listener heartbeat (`heartbeat_interval_ms=15000`) | in-listener; re-inspects and ingests `runtime_info_observed`→`ready` every ~15 s | in-memory cadence | no (re-derived) | indirect (drives observations) |
| listener lease / event epoch | `runtime_listener_leases`, per event source | durable | yes | not a health signal (ownership only) |
| `runtime_observations` (full history) | projector; every observation retained with `received_at`/`observed_at` | durable (16.8k `ready` rows live) | yes | **yes — canonical source for "time since last `ready`"** |
| `markStale` (`stale_observation_seconds=300`, every 5 min) | flips `ready/degraded/connecting`→`stale` when `observed_at` ages ≥300 s | durable | yes | yes — encodes the repo's own "gone" threshold |
| Kubernetes Pod readiness | kube API | not in domain model | n/a | **not used** — the canonical model already represents health |

The coordinator must read canonical `runtime_nodes.observed_state` +
`runtime_observations` (last `ready`), never Kubernetes Pod state.

## Degraded versus unavailable

Producers (`AsteriskAriEventNormalizer::normalize` lines 36–42, `markStale`):

- `ready` ← `runtime_info_observed` (heartbeat success).
- `unavailable` ← `connection_closed` (active listener detected transport loss).
- `degraded` ← `authentication_failed` **and the `default` branch — any
  `unknown_event_observed` frame** (confirmed flapping during event bursts,
  T2-B12 §10). ARI operations frequently still succeed while `degraded`.
- `stale` ← `markStale` when `observed_at` ages ≥300 s with no fresh
  observation (listener dead or node gone). `unavailable` is **not** in
  `markStale`'s source set, so an actively-detected-unavailable node stays
  `unavailable`.

Answers: `unknown_event_observed` **can** transiently project `degraded`;
`degraded` **can** coincide with working ARI; a single failed heartbeat projects
`unavailable` immediately (no N-strike hysteresis exists); the node does **not**
persist an unavailability-start timestamp; readiness **can** flap within a
heartbeat interval; same-node Pod restart briefly yields non-ready
(`unavailable`/`connecting`/`stale`) that must **not** trigger cross-node
failover.

**Failover-qualifying states: `unavailable` or `stale` only, after sustained
evidence.** `degraded` and `connecting` are excluded as transient. (The T5-A1
primitive itself gates only on `observed_state !== 'ready'`, so it would rebind
on `degraded` too — the coordinator policy is the necessary stricter filter, and
the primitive's under-lock readiness recheck is the backstop, not the policy.)

## Sustained-unavailability policy

No dedicated failover threshold, grace period, or hysteresis exists. The
derivable durable signal is **time since the node's last `ready` observation** in
`runtime_observations` (index `runtime_observations_node_type_idx` on
`(runtime_node_id, observation_type, observed_at)`), which is restart-safe and
flap-safe (any new `ready` resets it) and covers both `unavailable` and `stale`.

**Derived grace period = `stale_observation_seconds` (300 s)** — the only
principled durable duration in the repository, and exactly when the system's own
`markStale` declares a node no longer trustworthy. 300 s comfortably exceeds the
longest legitimate transient: same-node Pod restart + module-aware readiness gate
(observed ~17–18 s to Ready in T2-B; probe worst case
`initialDelaySeconds 15 + periodSeconds 15 × failureThreshold 12`), listener
reconnect backoff (≤30 s), lease (45 s), and heartbeat (15 s). Conceptually:

```
failover_eligible_at = max(received_at where observed_state='ready' for node) + 300s
```

- **Interval start:** the node's last `ready` observation.
- **Reset events:** any subsequent `ready` observation (recovery, flap-to-ready,
  same-node restart completing).
- **Coordinator restart / API-worker restart:** no effect — derived purely from
  durable `runtime_observations`; no in-memory timer.
- **Same-node Pod restart grace:** covered — restart re-projects `ready` well
  within 300 s, resetting the interval.
- **Maximum detection latency:** grace (300 s) + sweep cadence (≤60 s) ≈ 360 s.

This is **derivable from existing durable state — no schema required** for the
first slice. A single `runtime_nodes.ready_since`/`unavailable_since` column is a
*useful-later* optimization (cheaper than the history query), not a prerequisite.

## Coordinator ownership

**Periodic scheduler sweep** — `ConferenceFailoverCoordinator` is exposed only
through `telephony-domain:failover-coordinator {--once}`. The command follows the
existing worker convention (`do { workOnce(); sleep(poll) } while (!--once)`) and
is registered as
`Schedule::command('telephony-domain:failover-coordinator --once')->everyMinute()->withoutOverlapping()`
alongside the existing `telephony-domain:*` sweeps. The coordinator identifies
eligibility; `TelephonyDomainService` atomically cuts off former runtime
authority. No Conference ID, RuntimeNode ID, forced replacement, grace bypass, or
operator-facing failover option exists.

This boundary is adapter-neutral, PostgreSQL-authoritative, restart-safe,
idempotent (the primitive is safe to re-invoke), does not scan on every ARI
event, and adds no second management surface. It is **not** folded into the
per-target reconciliation worker (the coordinator sweeps conferences by
bound-node health, a different shape than per-target reconciliation). Rejected
alternatives: RuntimeNode transition-driven wake (would scan on ARI events;
premature); dedicated new Deployment (unnecessary infra for a per-minute sweep —
the existing `scheduler` Deployment already runs the `--once` cadence).

## Candidate Conference query

Owned by a `ConferenceFailoverCoordinator` service reading canonical state:

```
conferences c  (desired_state = 'open')
  join conference_runtime_bindings b
    on b.conference_id = c.id and b.status = 'active'
  join runtime_nodes n
    on n.id = b.runtime_node_id and n.tenant_id = c.tenant_id
where n.observed_state in ('unavailable','stale')      -- qualifying, not degraded/connecting
  and exists (                                          -- at least one durable ready boundary
        select 1 from runtime_observations o
        where o.runtime_node_id = n.id
          and o.observed_state = 'ready'
          and o.received_at <= now() - interval '300 seconds')
  and not exists (                                      -- sustained: no ready obs within grace
        select 1 from runtime_observations o
        where o.runtime_node_id = n.id
          and o.observed_state = 'ready'
          and o.received_at > now() - interval '300 seconds')
order by c.id                                           -- deterministic
for update of c skip locked                             -- reuse claimDue pattern
limit :batch
```

- **Tenant scoping:** implicit via the conference/binding tenant columns; the
  coordinator sweeps all tenants (platform sweep), one conference row per tenant
  naturally isolated by FK.
- **Batching / ordering / pagination:** bounded `LIMIT` (reuse
  `runtime_engine.batch_size`=10), deterministic `order by c.id`, next sweep
  continues remaining candidates.
- **Locking:** `FOR UPDATE OF c SKIP LOCKED` (the exact `ReconciliationRepository::claimDue`
  pattern, `ReconciliationRepository.php:93`).
- **"Already superseded":** a conference already rebound has a `ready` bound node
  (or a non-`unavailable/stale` node) → excluded by the `observed_state` filter
  without any per-conference flag.
- **"At least one distinct replacement" and "not already converged":** left to
  the primitive — the query need not pre-check them; the primitive returns a
  clean noop/throws for those cases (below). No per-Conference manual flag.

## Coordinator concurrency and claiming

Reuse `FOR UPDATE ... SKIP LOCKED` on the candidate query — no new lock service,
advisory lock, or claim table. Two sweeps cannot select the same conference row
concurrently. The T5-A1 primitive now also accepts the coordinator's expected
binding/runtime IDs, qualifying bound states, `active`-only replacement desired
states, and ready-observation grace. It revalidates those facts under its own
`lockForUpdate` authority transaction before retiring the former binding, so a
race across the skip-locked window collapses to one authoritative rebind. The
loser sees `active_binding_changed`, `bound_runtime_node_ready`, or
`bound_runtime_node_not_eligible`.

- **Claim identity:** the sweep transaction's row lock (no persisted claim owner
  needed for a per-minute idempotent sweep).
- **Claim timeout / worker death:** none required — locks release on
  transaction end/rollback; the next sweep re-evaluates from durable state.
- **Already-rebound / no-replacement outcomes:** handled per result below.

## Rebind invocation

Per surviving candidate, call
`failoverRebindConference($context, tenantId, conferenceId, 'automatic_runtime_node_unavailable', options)`
with:

- `expected_binding_id` and `expected_runtime_node_id` from the candidate row.
- `qualifying_bound_states = ['unavailable', 'stale']`.
- `replacement_desired_states = ['active']` so draining replacements are
  excluded for failover placement.
- `ready_observation_grace_seconds = runtime_engine.stale_observation_seconds`.

The primitive performs the strict under-lock revalidation and the authority
cutoff in one serialized database operation. The coordinator wraps
`HttpException` results so no-replacement and race outcomes do not fail the
entire sweep.

## Result and retry handling

| Result | Retry | Interval | Reset grace | Audit/metric | Reconcile woken |
|---|---|---|---|---|---|
| `status=rebound` | done | — | n/a (node changed) | primitive emits `conference.runtime_binding_replaced`; coordinator emits `conference.failover_coordinator.rebound` | yes (primitive wakes conf+participants) |
| `noop:bound_runtime_node_ready` / `bound_runtime_node_not_eligible` / `bound_runtime_node_recently_ready` | none | next sweep | yes (recovered or no longer qualifying) | `conference.failover_coordinator.recovered_before_cutoff` | n/a |
| `noop:replacement_runtime_node_not_distinct` | retry | next sweep (≥grace persists) | no | `no_replacement` signal | no |
| `noop:conference_not_open` / `active_binding_missing` / `bound_runtime_node_missing` | none | — | n/a | terminal-skip signal | no |
| **thrown 422** "No eligible runtime node…" (selection) | retry | next sweep | no | `no_replacement` signal | no |
| **thrown 422** (replacement race not ready) | retry | next sweep | no | `conflict` signal | no |
| thrown 404 conference not found | none | — | n/a | terminal-skip | no |
| retryable DB failure | retry | next sweep | no | `failure` signal | no |

The coordinator never dispatches bridge/participant operations — the primitive's
`wakeTarget` calls trigger the existing reconcilers.

## Replacement eligibility

Resolved by the existing `selectRuntimeNodeForConference(...excludeRuntimeNodeId)`
+ `assertRuntimeNodeEligibleForConferenceRebind` path — requires the replacement
`observed_state='ready'`, the configured replacement desired-state set, and
**both** `conference.lifecycle` and `conference.participation` capabilities,
excluding the current node, ordered `runtime_family, adapter_key, id`.

**Draining-node policy:** initial placement still follows the prior selector
contract, but automatic failover passes `replacement_desired_states=['active']`.
Semantically `draining` means "retain existing work, accept no new placement," so
a failover — which is new placement onto the node — excludes draining
replacements through the canonical selector rather than a second selector.

**Capacity/priority:** `placement_priority`/`capacity_weight` remain unused;
deterministic `id` ordering is acceptable for the first bounded slice — lack of
capacity-aware selection is not a correctness problem with a single replacement
candidate. Deferred.

## Transient recovery scenarios

1. **Unavailable one heartbeat** → not eligible (grace unmet); no action. 2.
**Same-node Pod restart within window** → `ready` re-projects <300 s, grace
resets; no failover. 3. **Listener reconnect after transient loss** → `ready`
resumes; grace resets. 4. **Flapping ready/unavailable** → each `ready` resets
grace; failover only after a full 300 s with no `ready`. 5. **Degraded from
unknown events** → excluded (not a qualifying state). 6. **Recovers just before
the coordinator locks** → primitive rechecks under lock → `noop:bound_runtime_node_ready`.
7. **Recovers after replacement commits** → binding already authoritative on B;
former node cannot reclaim; A's delayed events/ops fenced. 8. **No replacement
during outage** → thrown 422 → retry next sweep; conference waits on the
(unavailable) bound node meanwhile (existing safe behavior, no false projection).
9. **Replacement becomes ready later** → a subsequent sweep succeeds. 10.
**Coordinator restart during grace** → no effect (durable derivation).

## Coordinator restart behavior

Fully stateless/idempotent: eligibility and grace are re-derived from
`runtime_nodes` + `runtime_observations` each sweep; no in-memory timer, no
persisted claim to recover. A mid-sweep crash rolls back its open transaction;
the next `everyMinute` run re-evaluates.

## Former-node return

No coordinator path reverses a binding; delayed old-node events stay fenced
(normalizer active-binding join, test-locked); old-node operations stay stale
(generation fence, test-locked); the replacement binding remains authoritative.
**Former-node runtime cleanup is NOT a prerequisite** — authority cutoff +
replacement reconstruction come first; best-effort cleanup of the former node's
orphaned bridge/channels is a separate later slice and must not block
availability.

## Coordinator audit evidence

The primitive already emits `conference.runtime_binding_replaced` (audit +
outbox, bounded payload). The coordinator emits bounded audit/outbox signals for
`eligible`, `rebound`, `recovered_before_cutoff`, `no_replacement`,
`concurrent_conflict`, and `failed`. These rows are written only for candidate
Conferences that pass the SQL eligibility filter, so non-qualifying Conferences
do not produce per-minute audit noise. Metrics remain a later observability
slice.

## Schema assessment

| Field | Classification |
|---|---|
| `unavailable_since` / `failover_eligible_at` | **derivable from existing durable state** (`runtime_observations` last-`ready`); useful-later optimization |
| `last_failover_attempt_at` / `failover_attempt_count` | **not required** for first slice (sweep is idempotent; audit/outbox records attempts) |
| `replacement_reason` / `previous_runtime_node_id` | **already carried** in the primitive's audit+outbox payload; no column needed |
| `binding_generation` | **not required** — Conference `configuration_generation` is the fence |
| `coordinator_claim` | **not required** — `SKIP LOCKED` row lock suffices |

No schema change is required for the first coordinator slice.

## T5-A2 implementation-readiness decision

**A — coordinator implementation target is bounded and ready for Codex.** All
prerequisites are established: ownership (scheduled sweep), candidate query,
canonical unavailable signal (`observed_state ∈ {unavailable,stale}`), sustained
duration (300 s from `stale_observation_seconds`, derived from
`runtime_observations`), concurrency (`SKIP LOCKED` + primitive row locks),
replacement eligibility (existing selector, with the draining-exclusion policy
tightening), result/retry handling (including the thrown-422 no-replacement
path), restart behavior (stateless), and acceptance criteria (below). No
unresolved business decision; no operator approval required.

## T5-A2 first bounded implementation slice

A `ConferenceFailoverCoordinator` service + `telephony-domain:failover-coordinator
{--once}` command (scheduled `everyMinute`, `withoutOverlapping`) that runs the
candidate query (open conference, active binding on a node observed
`unavailable`/`stale`, no `ready` observation within 300 s, `FOR UPDATE OF
conference SKIP LOCKED`, bounded batch, `order by id`), and for each candidate
invokes `failoverRebindConference` once, mapping every result/exception to the
bounded retry/skip table above, emitting a coordinator audit signal. Includes a
`--once` diagnostic mode (one iteration) consistent with existing worker
commands, but the scheduled loop — never a manual command — is the normal
authority. Focused repository tests only; **excludes** second-node manifests,
live failover, former-node cleanup, capacity policy, reconstruction redesign,
failback, and UI.

## Ready-to-paste Codex prompt (T5-A2)

```
# T5-A2 — Automatic conference failover coordinator (scheduled sweep, repository-only)

Repository-only slice at HEAD 52ed85a. Keep UTCP_PHASE=T1. Do NOT create a
second Asterisk node, run live failover, add former-node cleanup, add capacity
policy, add schema, or add any manual management API/CLI-as-authority.

Implement a scheduled coordinator that invokes the existing
TelephonyDomainService::failoverRebindConference primitive automatically.

1. New service App\TelephonyDomain\Failover\ConferenceFailoverCoordinator with
   sweepOnce(string $workerId, int $batchSize = null): int that, in a DB
   transaction per batch:
   - selects candidate conferences with this query (deterministic, bounded):
       conferences c where c.desired_state='open'
       join conference_runtime_bindings b on b.conference_id=c.id and b.status='active'
       join runtime_nodes n on n.id=b.runtime_node_id and n.tenant_id=c.tenant_id
       where n.observed_state in ('unavailable','stale')
         and not exists (select 1 from runtime_observations o
             where o.runtime_node_id=n.id and o.observed_state='ready'
               and o.received_at > now() - (interval '1 second' * :grace))
       order by c.id
       for update of c skip locked
       limit :batch
     grace = (int) config('telephony_domain.failover_grace_seconds',
             (int) config('runtime_engine.stale_observation_seconds', 300));
     batch = $batchSize ?? (int) config('runtime_engine.batch_size', 10);
     Use DB::getDriverName()==='pgsql' ? lock('for update of ... skip locked')
     : lockForUpdate(), mirroring ReconciliationRepository::claimDue.
   - for each candidate, call
       app(TelephonyDomainService::class)->failoverRebindConference(
         ExecutionContext::system(reason:'automatic conference failover',
           tenantId:$c->tenant_id, origin:'telephony-failover-coordinator'),
         $c->tenant_id, $c->id, 'runtime_node_unavailable')
     wrapped in try/catch (\Symfony\Component\HttpKernel\Exception\HttpException):
       * status==='rebound'  -> count++, Log::info coordinator result rebound
       * status==='noop'     -> Log::info with the returned reason
       * HttpException 422 with "No eligible runtime node" -> Log::info no_replacement
       * HttpException 422 (other) -> Log::info conflict
       * HttpException 404 -> Log::info terminal_skip
     Do NOT dispatch bridge/participant operations; the primitive already wakes
     reconciliation. Return the rebound count.
   - Emit only low-cardinality logs (component, result); NO tenant/conference/
     node/binding identifiers in any structured metric-shaped field.

2. Config: add 'failover_grace_seconds' to config/telephony_domain.php defaulting
   to env('UTCP_TELEPHONY_FAILOVER_GRACE_SECONDS', 300). Do not add an enable gate.

3. Artisan command telephony-domain:failover-coordinator {--once} in
   routes/console.php following the exact existing worker loop convention
   (do { $coordinator->sweepOnce($workerId); if(!--once) sleep(config(
   'telephony_domain.poll_seconds', config('runtime_engine.poll_seconds',3))); }
   while(!--once)); workerId = gethostname().':failover-coordinator:'.getmypid().
   Register Schedule::command('telephony-domain:failover-coordinator --once')
   ->everyMinute()->withoutOverlapping() next to the other telephony-domain sweeps.

4. Do NOT change failoverRebindConference, the selector, reconcilers, normalizer,
   projector, or command worker. The primitive's under-lock readiness recheck and
   the existing generation/binding fences remain the authority.

5. Focused tests (tests/Feature/TelephonyDomain/ or Failover/):
   - open conference whose bound node is observed 'unavailable' with NO ready
     observation in the last grace seconds -> sweepOnce rebinds once to a distinct
     ready node; exactly one active binding remains; generation incremented.
   - bound node 'unavailable' but WITH a ready observation inside the grace window
     -> sweepOnce does nothing (grace unmet), binding unchanged.
   - bound node observed 'degraded' -> sweepOnce does nothing (not a qualifying
     state).
   - bound node 'stale' (no ready obs, aged) and no distinct replacement ready
     -> sweepOnce catches the 422, makes no binding change, no exception escapes.
   - two consecutive sweepOnce calls after a successful rebind create no second
     binding change (idempotent; node now ready/changed).
   Build candidate observations by inserting runtime_observations rows directly in
   the test (received_at controlled), reusing existing test fixtures for nodes,
   conferences, bindings.

6. Update docs/evidence/t2/multi-node-failover-readiness.md T5-A2 section with a
   short "coordinator implemented" note (scheduled sweep + which results are
   handled; second-node/live proof still pending).

Verification (all must pass):
  make repository-hygiene && make secret-scan
  make runtime-engine-config-check && make telephony-domain-config-check
  make asterisk-ari-config-check && make asterisk-conference-config-check
  make runtime-engine-test && make telephony-domain-test
  make asterisk-ari-test && make asterisk-conference-recovery-test
  make test && make check && make build
  git diff --check

One scoped commit, e.g.:
  feat(t5): automatic conference failover coordinator sweep
Do not push. Do not run live failover, a second Asterisk deployment, or the full
recovery corridor suite.
```

## T5-A2 staged acceptance criteria

**Repository acceptance:** deterministic candidate query; qualifying states
limited to `unavailable`/`stale`; 300 s durable grace derived from
`runtime_observations`; `SKIP LOCKED` concurrency; single idempotent primitive
invocation per candidate; every result/exception mapped to bounded retry/skip;
draining replacements excluded via the canonical selector; focused tests green;
no schema, no manual authority, no live failover.

**Live acceptance (later):** as in the T2-C live acceptance above, additionally
proving the coordinator (not a manual call) triggers the single rebind after the
sustained-unavailability grace, and does not trigger on a same-node Pod restart
that recovers within the grace window.

---

# T5-A3 — Former Asterisk Runtime Fencing and Signaling-Cutoff Readiness

Evidence-only audit at commit `cca57ea`, `UTCP_PHASE=T1`, 2026-07-18. Determines
whether the automatic coordinator (T5-A2) is safe to invoke
`failoverRebindConference` on ARI-unavailability alone. No live failover, no
second node, no Kamailio/RuntimeBinding mutation.

## Exact Asterisk version and image

Asterisk **20.20.1** (LTS series 20). Deployed image
`utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev` @
`sha256:944c10598da6902d6c7bfb54493376f7219e640a4245c03de5204e32c2f429bb`,
built FROM pinned base
`andrius/asterisk:20@sha256:a27dae75b15343ac50ab7bf45eb0ca22681fd770a14863091c32883829cf3fc9`.
`modules.conf` loads **no PJSIP, chan_sip, RTP, or udptl modules** — the current
conference proof uses only ARI-originated `Local/participant@utcp-conference-proof`
channels driven into a Stasis bridge; there is no SIP signaling into Asterisk and
no RTP media plane in this build.

## Official Asterisk sources consulted

- ARI Getting Started (docs.asterisk.org): "**application code runs in a separate
  process from Asterisk**" — the ARI app is an external controller, not the media
  engine.
- ARI Outbound Websockets: on a broken connection Asterisk **reconnects at a
  configurable interval**; disconnection is a transport event, not a teardown.
- ARI and Bridges — Bridge Operations: bridges are cleaned up by explicit
  `bridge.destroy()`; there is **no automatic bridge destruction** on app
  disconnect.
- Community (asterisk.org): after a deliberate WebSocket close simulating a
  network/power outage, on reconnect you **"retrieve a list of bridges … your
  application was responsible for and attempt to delete them"** — i.e. bridges
  and channels **persist** across the disconnect.
- No official source describes any ARI/Stasis mechanism to move a bridge or
  channel between separate Asterisk processes, or any distributed/cluster-wide
  Stasis ownership. Bridge IDs, channel IDs, and Stasis app instances are
  **local to one Asterisk process**; reusing the same Stasis app name on a second
  Asterisk creates **no** cross-instance authority.

## Exact-image validation (pinned 20.20.1)

Direct read-only test in an ephemeral `--network none` container from the exact
deployed image (`utcp-asterisk-ari:dev`, id `4ed3ab1d1711`, removed after):
originated a `Local/hold@…` channel pair into `Wait(600)` with **no ARI
application ever connected** (`utcp-t0-observation` not running). Result: both
legs stayed `Up` in `Wait(600)` and persisted across repeated polls with zero
control-plane connection. This directly confirms, against the pinned image, that
Asterisk's core hosts channels/bridges/media **independently of ARI**.

## ARI connection-loss behavior

In UTCP, every listener transport fault — WebSocket read error/EOF, lost Stasis
subscription (`ari_stasis_subscription_lost`), or failed periodic HTTP inspect —
is ingested by `AsteriskAriEventListener::ingestFailure()` as a
`connection_closed` event, which `AsteriskAriEventNormalizer` maps to
`observed_state = 'unavailable'`. `markStale` independently flips any node with no
observation for 300 s to `stale`. **Both `unavailable` and `stale` are
control-plane-connectivity signals**, produced by loss of ARI reachability — not
by any evidence that the Asterisk process, bridge, channels, or media stopped.

## Bridge and channel lifetime

Bridges and channels created via ARI persist until explicitly destroyed, until
their channels hang up, or until the Asterisk process itself exits. ARI
disconnection does none of these. A Stasis bridge whose controller vanished
remains a live mixing/holding bridge in the core; its member channels keep
running their applications.

## SIP-dialog and RTP continuity

In the **current** build there is no SIP or RTP plane, so there is nothing to
continue there — the only runtime state is the local Stasis bridge + Local
channels, which persist as above. In a **future** SIP/RTP-bearing build the
distinction becomes sharper and worse: SIP dialogs are pinned to the node via
Record-Route and RTP flows peer-to-peer or through a media relay, so both would
continue on the former node entirely independent of ARI or even of Kamailio
routing changes. The fencing contract must be defined now for that trajectory,
not just the Local-channel present.

## Distributed Asterisk limitations

None available: no cross-process bridge/channel migration, no distributed Stasis
ownership, node-local resource identifiers. Two-node failover is therefore always
**reconstruction on the replacement**, never migration — and the former node's
resources can only be removed by destroying them on that node or by terminating
that node's process.

## Current coordinator behavior

`ConferenceFailoverCoordinator::sweepOnce` (scheduled `everyMinute`) selects open
conferences whose bound node is `observed_state ∈ {unavailable,stale}` with no
`ready` observation within the 300 s grace, and **directly calls
`failoverRebindConference` (coordinator line 54)** for each. There is **no
signaling fence, no runtime fence, and no old-resource inspection** between
detection and rebind. The primitive rechecks only `observed_state !== 'ready'`
under lock — the same control-plane signal — and then retires the binding, binds
the replacement, bumps generation, and wakes reconstruction.

## ARI-loss versus runtime-loss assessment

**ARI loss does NOT imply runtime loss** (classification, backed by official docs
+ community + exact-image validation). `unavailable` is produced by ARI transport
loss while Asterisk keeps running; `stale` occurs after 300 s of no observation,
which a network partition or a dead *listener* produces just as readily as a dead
*node*. The 300 s grace proves only "no ARI evidence for 5 minutes," **not**
process termination. Same-node Pod restart is excluded by the grace (it
re-projects `ready` well within 300 s), but a **network partition with the
Asterisk process and its bridge/media still live is not excluded** — and that is
exactly the split-brain case.

## Failure-domain distinction

| Case | Old bridge/channels exist? | Media/dialogs continue? | New SIP reachable? | UTCP inspect? | Reconstruct safe? | Extra fence needed |
|---|---|---|---|---|---|---|
| A ARI WS down, HTTP up | yes | yes (n/a today) | n/a today | partial | no | subscription/runtime fence |
| B ARI down, process up | yes | yes | (would be) yes | no | **no** | runtime + signaling fence |
| C Pod NotReady, process up | yes | yes | maybe | no | **no** | runtime + signaling fence |
| D Pod deleted/terminated | no (process gone) | no | no | no | **yes** | none (termination *is* the fence) |
| E endpoint removed, Pod up | yes | yes | no (new) | no | no | runtime fence (media persists) |
| F Kamailio stops new routing | yes | yes (existing) | no (new) | maybe | partial | runtime fence still needed |
| G existing dialogs remain | yes | yes | — | — | no | runtime fence |
| H RTP continues despite isolation | yes | yes | — | — | no | runtime fence |
| I node partitioned, endpoints live | yes | yes | maybe | no | **no — split-brain risk** | definitive runtime fence |
| J host/Pod definitively terminated | no | no | no | no | **yes** | none |

Only **D** and **J** (definitive termination) make reconstruction safe with no
extra fence. Every "process still up" case (B, C, E, F, G, H, I) leaves the old
runtime able to host the bridge/media, so control-plane rebinding alone is
insufficient.

## Kamailio signaling authority

The deployed Kamailio (`kamailio-configmap.yaml`) is a **T1 registrar only**: its
`request_route` handles `REGISTER` (with auth + `save("location")`), `OPTIONS`
keepalive, and rejects every other method (`405`). There is **no dispatcher, no
`t_relay` to Asterisk, and no Asterisk backend selection** — Kamailio does not
route calls to Asterisk at all today, and RuntimeNode observed state does not feed
any routing decision. So there is currently no signaling path to fence, but also
**no existing mechanism to guarantee a former node receives no new signaling**
once SIP call routing is added. Node-specific route identity, observed-state-driven
route withdrawal, and persisted routing cutoff all remain to be built; signaling
fencing would be performed adapter-neutrally through the registration/routing
authority, not by the coordinator.

## Kubernetes fencing capabilities

The Asterisk Deployment has `replicas: 1`, `terminationGracePeriodSeconds: 30`, an
exec readiness probe + tcpSocket liveness probe, **no preStop hook, no
PodDisruptionBudget, no NetworkPolicy-based isolation primitive**, and a service
account with `automountServiceAccountToken: false`. Crucially, **no Role/ClusterRole
grants UTCP pod-delete or deployment-patch permission** — the control plane
**cannot terminate or isolate an Asterisk Pod today**. UTCP can therefore observe
Pod state via the API (a future adapter) but cannot yet *effect* a Kubernetes
runtime fence. Distinguishing the states: Pod-NotReady and endpoint-removed do
**not** stop the process/media; only container termination or Node loss does;
network isolation stops UTCP's view without stopping the runtime.

## Minimum former-runtime fencing invariant

Before replacement reconstruction, all three must hold:

```
1. Control-plane fence  — former binding retired; former-node operations stale
   (generation fence); former-node events rejected (active-binding join).
   [ALREADY ENFORCED by the T5-A1 primitive, repository-proven.]
2. Signaling fence      — the former node can receive no NEW conference signaling,
   and a returning node cannot auto-re-enter routing for the failed Conference.
   [NOT YET REQUIRED for Local-channel conferences; MANDATORY before SIP calls.]
3. Runtime/media fence  — ONE of: (a) former Asterisk process definitively
   terminated; (b) former runtime network-isolated from signaling+media;
   (c) old Conference bridge/channels authoritatively verified absent; (d) an
   external infrastructure fence guarantees the runtime cannot continue.
   [NOT ENFORCED. This is the missing safety gate.]
```

Authority cutoff (1) may precede reconstruction, but reconstruction must not begin
until (3) is satisfied (and (2) once SIP routing exists). For the **current
Local-channel build**, fence (3c) "authoritative runtime-absence verification" is
the cheapest sufficient form — but it must be *positively verified against the
runtime*, not inferred from `unavailable`/`stale`, which is precisely what today's
coordinator fails to do.

## Safe failover ordering

**Sequence D (verify-absence-first), escalating to C (external fence) —** the
safest consistent with current contracts:

```
detect sustained unavailability (coordinator, existing)
→ attempt authoritative runtime-absence verification of the former node's
   bridge/channels (generic inspection operation)
   → if authoritatively ABSENT (or the node's process/Pod is proven terminated):
        record fence evidence → failoverRebindConference → reconstruct
   → if PRESENT or UNVERIFIABLE (partition):
        require an external fence (Kubernetes termination / isolation) via a
        generic fencing operation → record fence evidence → then rebind
→ no replacement after fence: remain waiting (no false projection)
→ replacement reconstruction fails: existing reconciler waits/retries on the
   new bound node
→ former node recovers during fencing: fence intent is abandoned if the node
   re-projects ready before cutoff (primitive's under-lock ready recheck already
   backstops this)
```

Fencing begins in a fencing orchestrator (below), durable fence intent/evidence
lives in the generic operation + audit tables, and `failoverRebindConference`
runs **only after** fence evidence is recorded. No operator approval is required
*for the Local-channel build* (absence verification is automatable); an
irreversible Kubernetes termination is also automatable through a scoped adapter,
so no manual step is mandated — but the coordinator must not rebind before the
fence.

## Fencing ownership

**Combination with one orchestration authority:** a dedicated
`RuntimeFencingCoordinator` (domain-layer, runtime-neutral) owns fence
orchestration and evidence; it dispatches **generic runtime operations** for the
runtime-specific actions, keeping Kubernetes/Kamailio specifics behind adapters:

| Concern | Owner |
|---|---|
| eligibility (sustained unavailability) | existing `ConferenceFailoverCoordinator` candidate query |
| fence orchestration + evidence + idempotency | new `RuntimeFencingCoordinator` (domain) |
| runtime absence-verification / termination / isolation | generic operation executed by a runtime/infra **adapter** (Asterisk ARI inspect today; a Kubernetes runtime adapter later) |
| signaling cutoff | Kamailio routing authority (later, when SIP routing exists) |
| atomic rebind | existing `TelephonyDomainService::failoverRebindConference` |
| replacement reconstruction | existing reconcilers |

The domain layer stays runtime-neutral; no direct coordinator-to-kubectl or
coordinator-to-Kamailio execution; fence evidence persists and survives restart;
the generic operation's idempotency + the primitive's row locks prevent duplicate
fencing; rebind is invoked only after the fence.

## Fencing state and evidence

Reuse existing generic infrastructure rather than new Conference columns:

| Field/entity | Classification |
|---|---|
| fence request / evidence | **generic `runtime_operations` row + completion event** (reuse) — a `runtime_node.runtime.verify_absent` / `runtime_node.runtime.fence` operation carrying its result; no new table |
| fence audit trail | **existing audit + outbox** (reuse) |
| `former_runtime_node_id`, `replacement_reason` | **already carried** in the primitive's `conference.runtime_binding_replaced` payload |
| `runtime_fence_status` / `..._at` on the conference | **not required / do not add** — do not overload Conference observed state with infrastructure-operation state |
| `runtime_fence_generation` | derivable from Conference `configuration_generation`; not required |
| a per-node "fenced" marker | **useful later** (to stop re-fencing a node with many conferences) — derivable from the node's operation history for the first slice |

No new schema is required for the first slice; the generic operation + audit model
accurately represents infrastructure fencing.

## Generic operation reuse

**Yes — use the existing generic operation engine.** Candidate operations
(repository-consistent, target = the runtime node, node-scoped idempotency,
generation-tagged, adapter-selected, claimed/retried by the command worker,
completion evidence in the operation + outbox):
`runtime_node.runtime.verify_absent` (Asterisk ARI adapter: inspect the former
node for the conference bridge/channels; "absent" or "still present/unreachable"),
and later `runtime_node.runtime.fence` (Kubernetes adapter: terminate/isolate the
Pod) and `runtime_node.signaling.disable` (Kamailio authority: withdraw routing).
These are **never** exposed as operator commands; they are internal operations the
`RuntimeFencingCoordinator` dispatches. This keeps all runtime/infra mutation
behind the adapter boundary — no coordinator-to-Kubernetes/Kamailio shelling.

## Participant continuity contract

Honest terminology, from repository + Asterisk behavior: SIP dialogs do **not**
survive a hard node loss; channels **cannot** move between processes; RTP
**cannot** be preserved. The first two-node acceptance target is
**control-plane reconstruction only** — on the replacement node UTCP recreates the
conference bridge and re-originates each `admitted` participant's `Local/` channel
(participant desired state carries enough to recreate the proof leg; no external
origination credential is needed for Local channels). It is **not** automatic
participant redial, **not** signaling-dialog recovery, and **not** seamless media
migration. Real SIP/WebRTC participants would require new dialogs / re-INVITE /
re-admission — explicitly out of scope until a SIP-bearing build exists.

## Local two-node proof topology (defined, not built)

Node A and node B as two Deployments (`asterisk-ari-a`/`-b`) with distinct
Services, Secrets, ARI endpoints, RuntimeNode records, listener leases, and event
sources; internal-only ARI (no public SIP/RTP). Same Stasis app name is **safe**
across the two separate processes (no cross-instance authority; each app instance
is process-local). Bridge/channel IDs are already conference/participant-derived
and node-local, so no additional qualification is needed (the active-binding join
already fences by node). Node A is fenced (verify-absent or Pod termination)
without touching node B; final orphan inspection must query **both** nodes' ARI
for zero residual proof bridges/channels.

## Failure-scenario assessment (fencing)

1. Listener disconnect, Asterisk healthy → `unavailable`; **must NOT reconstruct**
   until fence — today it would. 2. ARI HTTP unreachable, SIP/RTP continue → same.
3. Pod NotReady, process up → same (media persists). 4. Endpoint gone, dialogs
continue → same. 5. Kamailio removes A for new calls, existing dialogs remain →
runtime fence still required. 6. Pod deleted cleanly → safe to reconstruct
(termination is the fence) — but UTCP cannot *cause* this yet. 7. Node partitioned
from control plane → **split-brain**: reconstruct only after external fence. 8.
Reachable by endpoints, not by UTCP → same as 7. 9. Runtime fenced, signaling
fence fails → block reconstruction (SIP build). 10. Signaling fenced, runtime
termination fails → block until absence verified. 11. Fenced, no replacement →
wait. 12. Replacement fails mid-reconstruction → reconciler retries on B. 13.
Former node returns after cutoff → no reclaim (binding authority + generation).
14. Delayed former-node events after rebind → dropped (normalizer join,
test-locked). 15. Old operations after rebind → stale (generation, test-locked).
16. Coordinator restarts during fencing → fence intent/evidence is durable in the
operation row; re-derived. 17. Two workers fence same node → generic operation
idempotency + `SKIP LOCKED`. 18. Fence unprovable → **do not reconstruct**;
escalate to external fence or remain waiting.

## Current coordinator safety classification

```
must be cut off until fencing exists
```

The coordinator invokes `failoverRebindConference` on control-plane
unavailability alone. Control-plane authority cutoff (retire binding, stale ops,
reject events) is safe and correct, but **replacement reconstruction that follows
is unsafe**: for every "process still up" failure domain (B, C, E, F, G, H, I) the
former Asterisk can still host the conference bridge/media, so reconstructing on a
second node would create two live runtimes for one Conference (split-brain) the
moment a second node and reconstruction exist. It is not yet a live hazard (single
node; reconstruction has no second node to target), but the coordinator must not
be allowed to reach automatic reconstruction before fencing is in place.

## Exact unsafe path

```
The coordinator must not invoke failoverRebindConference directly
until canonical former-runtime fencing evidence is satisfied.
```

Concretely: `ConferenceFailoverCoordinator::sweepOnce` →
`ConferenceFailoverCoordinator.php:54` `$this->domain->failoverRebindConference(...)`.
This direct call is the path to gate — it must be replaced by a call that first
obtains durable fence evidence (absence-verified or externally fenced) and only
then rebinds. It must **not** be preserved behind a fallback or feature flag.

## T5-A3 implementation-readiness decision

**B — coordinator requires a bounded pre-rebind fencing stage.** The required
fence, its ownership, sequencing, generic-operation representation, the exact
unsafe call path, and acceptance tests are all defined. The first slice inserts an
authoritative runtime-absence verification gate before rebind (sufficient for the
Local-channel build); the Kubernetes-termination and Kamailio-signaling fences are
later slices layered on the same orchestrator.

## T5-A3 first bounded implementation slice

**Insert an authoritative former-runtime absence-verification gate between the
coordinator's candidate detection and `failoverRebindConference`, using a generic
runtime operation — repository-only, no live failover, no second node, no
Kubernetes/Kamailio adapter.**

- **Affected:** `ConferenceFailoverCoordinator` (stop calling the primitive
  directly), a new `RuntimeFencingCoordinator` (or a bounded fencing step the
  coordinator delegates to), a generic `runtime_node.runtime.verify_absent`
  operation handled by the existing `AsteriskRuntimeAdapter` (reusing its
  conference inspection), and the existing operation/audit infrastructure.
- **Authority boundary:** domain-layer orchestration; runtime check via the
  adapter; rebind still only in `TelephonyDomainService`; no coordinator-to-infra
  execution.
- **Transition:** candidate → dispatch `verify_absent` for the former node+conference
  → on result `absent` (bridge and participant channels not present on the former
  node) record fence evidence and invoke `failoverRebindConference` → on result
  `present`/`unavailable`/`unverifiable`, do **not** rebind (record outcome, retry
  next sweep; escalation to an external fence is a later slice).
- **Legacy behavior to remove:** the direct
  `failoverRebindConference` call at coordinator line 54 — replaced by the gated
  path; no feature-flag fallback.
- **Tests:** absence-verified former node → coordinator rebinds once; former node
  still reporting the bridge/channels present → coordinator does not rebind, no
  binding change; former node unverifiable (inspection unavailable) → no rebind;
  idempotent re-sweep after a successful gated rebind creates no second change.
- **Excludes:** Kubernetes runtime-fence adapter, Kamailio signaling cutoff,
  second-node manifests, live failover, participant reconstruction changes.

## Ready-to-paste Codex prompt (T5-A3)

```
# T5-A3 — Gate the failover coordinator behind authoritative former-runtime absence verification

Repository-only slice at HEAD cca57ea. Keep UTCP_PHASE=T1. Do NOT create a second
Asterisk node, run live failover, add a Kubernetes/Kamailio fence adapter, add
schema, or add any manual failover/fence command as authority.

Problem (proven in docs/evidence/t2/multi-node-failover-readiness.md §T5-A3):
RuntimeNode observed_state 'unavailable'/'stale' is a CONTROL-PLANE connectivity
signal (ARI transport loss / 300s no-observation). It does NOT prove the former
Asterisk process, bridge, channels, or media stopped (Asterisk 20.20.1; bridges
persist until explicitly destroyed; ARI runs in a separate process — validated
against the pinned image). The coordinator today calls failoverRebindConference
directly (ConferenceFailoverCoordinator.php:54), so replacement reconstruction
could run while the former node still hosts the conference — split-brain once a
second node exists. Insert an authoritative runtime-absence gate before rebind.

1. Generic operation: add a runtime operation type 'runtime.node.verify_conference_absent'
   (payload: tenant_id, conference_id, runtime_node_id, configuration_generation).
   Handle it in AsteriskRuntimeAdapter::execute (new case) by reusing the existing
   conference runtime inspection (conferenceRuntimeSummary / inspectConferenceRuntime)
   against the given node+conference: complete with an event payload
   {absent: bool, inspected: bool} where absent=true only when the inspection is
   'observed' AND neither the conference bridge nor any participant channel is
   present; inspected=false when the runtime is unavailable/unreachable. Never mutate
   Asterisk. Keep it behind the adapter boundary; do not shell out.

2. Fencing step: add App\TelephonyDomain\Failover\RuntimeFencingCoordinator (or a
   bounded method the ConferenceFailoverCoordinator delegates to) that, for a
   candidate (tenantId, conferenceId, formerRuntimeNodeId):
   - creates/claims one idempotent runtime.node.verify_conference_absent operation
     (idempotency keyed on former node + conference + generation), reusing the
     existing RuntimeOperationRepository + command execution path (do NOT invent a
     new worker; the existing command worker executes it);
   - reads the completed operation's result:
       absent=true  -> record durable fence evidence (audit + outbox
                       'conference.runtime_fence_verified') and return "fenced";
       absent=false or inspected=false or operation not yet complete
                    -> return "not_fenced" (record outcome; do NOT rebind).
   Do not add Conference columns; represent fence state via the operation row +
   audit/outbox only.

3. ConferenceFailoverCoordinator: REPLACE the direct
   $this->domain->failoverRebindConference(...) call at line ~54 with:
   only invoke failoverRebindConference when the fencing step returns "fenced";
   otherwise classify the candidate as 'awaiting_fence' (new summary bucket) and
   move on (retry next sweep). Keep the existing result/exception classification
   for the rebind call that now runs only post-fence. No feature flag, no fallback
   direct call.

4. Tests (tests/Feature/TelephonyDomain/ or Failover/):
   - former node inspection reports conference bridge+participant ABSENT ->
     coordinator fences then rebinds once; exactly one active binding; generation
     bumped.
   - former node inspection reports the bridge/channels still PRESENT ->
     coordinator does NOT rebind; binding unchanged; outcome 'awaiting_fence'.
   - former node inspection UNAVAILABLE (inspected=false) -> no rebind, binding
     unchanged.
   - two consecutive sweeps after a successful gated rebind make no second binding
     change (idempotent).
   Build node/conference/binding/observation fixtures and a fake inspection result
   as the existing failover/adapter tests do.

5. Update docs/evidence/t2/multi-node-failover-readiness.md §T5-A3 with an
   "implemented" note: the absence gate is in place; Kubernetes-termination and
   Kamailio-signaling fences remain later slices.

Verification (all must pass):
  make repository-hygiene && make secret-scan
  make runtime-engine-config-check && make telephony-domain-config-check
  make asterisk-ari-config-check && make asterisk-conference-config-check
  make runtime-engine-test && make telephony-domain-test
  make asterisk-ari-test && make asterisk-conference-recovery-test
  make test && make check && make build
  git diff --check

One scoped commit, e.g.:
  feat(t5): gate conference failover behind former-runtime absence verification
Do not push. Do not run live failover, a second Asterisk deployment, Asterisk
scaling/Pod deletion, Kamailio changes, or the full recovery corridor suite.
```

## T5-A3 staged acceptance criteria

**Repository acceptance:** the coordinator never rebinds without a recorded
absence-verification fence result; `verify_conference_absent` is a generic
operation executed via the adapter (no direct infra calls); fence evidence lives
in the operation + audit/outbox (no Conference schema overload); the direct
coordinator→primitive call at line 54 is removed (no fallback); focused tests
green; no live failover, no second node.

**Live acceptance (later, layered):** two nodes; node A hosts the conference; A
made ARI-unavailable while its process still holds the bridge → coordinator
verifies presence and **does not** reconstruct (proves split-brain prevention);
A definitively terminated → coordinator verifies absence, fences, rebinds once,
reconstructs exactly one bridge/participant on B; both-node orphan inspection
clean. Kubernetes-termination and Kamailio-signaling fences proven in their own
later slices.
