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

The Asterisk Deployment has `replicas: 1`, `terminationGracePeriodSeconds: 30`,
startup initialization through `/usr/local/bin/utcp-asterisk-readiness`, ongoing
liveness through the local Asterisk control socket (`core show uptime`), ARI TCP
readiness, **no preStop hook, no PodDisruptionBudget, no NetworkPolicy-based
isolation primitive**, and a service account with
`automountServiceAccountToken: false`. Crucially, **no Role/ClusterRole grants
UTCP pod-delete or deployment-patch permission** — the control plane **cannot
terminate or isolate an Asterisk Pod today**. UTCP can therefore observe Pod state
via the API (a future adapter) but cannot yet *effect* a Kubernetes runtime fence.
Distinguishing the states: Pod-NotReady and endpoint-removed do **not** stop the
process/media; only container termination or Node loss does; network isolation
stops UTCP's view without stopping the runtime.

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
| future signaling route cutoff | Kamailio routing authority, only after T3/V0 or C6/C7/T6/V1 create SIP application-dialog routing |
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
- **Excludes:** Kubernetes runtime-fence adapter, future Kamailio SIP application-dialog routing,
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

## T5-A3 implemented repository gate

T5-A3 cuts off the unsafe automatic path where
`ConferenceFailoverCoordinator` could invoke `failoverRebindConference()`
directly after durable RuntimeNode unavailability. ARI loss, stale health, or
inspection failure is only control-plane connectivity evidence; it does not prove
that the former Asterisk 20.20.1 process has destroyed its bridge or Local
participant channels.

The coordinator now delegates eligible candidates to `RuntimeFencingCoordinator`.
That service creates or observes one idempotent generic runtime operation,
`runtime.node.verify_conference_absent`, keyed to the former RuntimeNode, former
RuntimeBinding authority, Conference, and current configuration generation. The
Asterisk adapter handles the operation read-only against the operation-target
former node. It reports:

- `absent` only after successful inspection proves the Conference bridge is
  absent and every participant channel associated with that Conference is absent.
- `present` when any owned bridge or participant channel remains.
- `unavailable` when the former runtime cannot be reached.
- `failed` for partial inspection failure, malformed evidence, or internal
  execution failure.

Unreachable and partial inspection results never authorize rebind. Completed
absence evidence is durable through the operation row plus bounded
`conference.runtime_fence_verified` audit/outbox evidence.

The authority cutoff remains in `TelephonyDomainService`. Production rebind now
requires a completed fence operation ID, and the service validates the evidence
inside the serialized rebind transaction against the current active binding,
former RuntimeNode, Conference generation, open desired state, and sustained
unavailable/stale bound-node eligibility. Stale binding evidence, stale
generation evidence, recovered former runtimes, and non-absent results block
rebind. Concurrent consumers of the same valid absent evidence produce one
authoritative replacement, one active binding, and one generation increment.

No schema, management route, manual failover command, direct coordinator-to-
Asterisk path, direct binding write outside the domain service, feature gate,
RuntimeNode allowlist, or fallback direct rebind path was introduced. External
Kubernetes runtime-termination fencing, future Kamailio SIP application-dialog routing, second-node
deployment, and live two-node acceptance remain later T5 work. The first
two-node target remains control-plane reconstruction after verified absence, not
seamless media migration.

---

# T5-A4 — Second Asterisk RuntimeNode and Kubernetes Termination-Fence Readiness

Evidence-only audit at commit `cf621eb`, `UTCP_PHASE=T1`, 2026-07-18. Defines the
two-node topology and Kubernetes runtime-fence contract that lets the T5-A3
`former_runtime_present`/`unavailable` outcomes escalate to an authoritative
process-termination fence and then rebind. No second node built, no fence
adapter, no RBAC change, no live failover.

## Exact Asterisk and Kubernetes versions

Asterisk **20.20.1**, image `utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev`
@ `sha256:944c10598da6902d6c7bfb54493376f7219e640a4245c03de5204e32c2f429bb`
(base `andrius/asterisk:20@sha256:a27dae75…`). Kubernetes/k3d **v1.35.3+k3s1**
(server and all three nodes). Deployment scale subresource present
(`replicas: 1`); one `EndpointSlice` (`asterisk-ari-*`) tracks endpoint
readiness; the Asterisk Pod carries an observable `metadata.uid`.

## Official sources consulted

- Asterisk (docs + community): each Asterisk server maintains its **own local
  channel/bridge ID namespace**; a Stasis application "will only be able to
  manipulate the channels that it controls"; the same app name on separate
  processes creates no cross-instance authority (corroborated by the T5-A3
  exact-image test: Local channels persisted with no ARI connected).
- Kubernetes v1.35 (Deployments/scale, Pods, EndpointSlice): a Deployment's
  `scale` subresource sets desired replicas; scaling a Deployment to 0 terminates
  all owned Pods and prevents recreation until scaled back up; deleting a Pod
  under a Deployment triggers immediate controller recreation (a new Pod UID);
  EndpointSlice readiness reflects probe state, not process liveness.

Sources: [ARI and Bridges — Bridge Operations](https://docs.asterisk.org/Configuration/Interfaces/Asterisk-REST-Interface-ARI/Introduction-to-ARI-and-Bridges/ARI-and-Bridges-Bridge-Operations/), [Getting Started with ARI](https://docs.asterisk.org/Configuration/Interfaces/Asterisk-REST-Interface-ARI/Getting-Started-with-ARI/).

## Current Asterisk Kubernetes topology

Single instance, all identity hard-coded to one node:

| Concern | Current value |
|---|---|
| Deployment | `asterisk-ari` (ns `utcp-runtime`, `replicas: 1`) |
| Pod labels | `app.kubernetes.io/component: asterisk-ari`, `utcp.io/network-role: asterisk-ari` |
| Service | `asterisk-ari` (ClusterIP), selector = the same component label |
| ARI port/endpoint | `8088` / `asterisk-ari.utcp-runtime.svc.cluster.local:8088` (RuntimeNode `control`/`events`/`health` endpoints) |
| Secret | `utcp-local-asterisk-ari-credentials` (overlay `secretGenerator`) |
| ConfigMap | none — Asterisk config is baked into the image; ARI creds via env `secretRef` |
| RuntimeNode | slug `local-asterisk-ari`, `adapter_key: asterisk-ari`, `runtime_family: asterisk`, registered through the authenticated C2 API |
| listener/lease/event source | single `asterisk-ari-events` Deployment; lease + event epoch are **per event source = per RuntimeNode** |
| Stasis app | `utcp-t0-observation` (config default, per-process) |
| ServiceAccount | `utcp-runtime-asterisk`, `automountServiceAccountToken: false` |

**Already multi-node-capable (no per-instance hard-coding):** the listener
`eligibleNodes()` iterates *all* `adapter_key='asterisk-ari'` active nodes and
keeps a per-node `connections` map with a per-node lease; `asterisk-ari:ensure-targets`
ensures a reconciliation target for *every* such node; leases/epochs are per event
source. **Hard-coded to one instance:** the Deployment/Service/Secret names, the
Pod component label + selector, and the single RuntimeNode endpoint host string.

## Two-node isolation requirements

| Element | Distinct per node? |
|---|---|
| Deployment name | **yes** (`asterisk-ari-a` / `-b`) |
| Service | **yes** (distinct name + selector label per node) |
| Secret (ARI creds) | **yes** (distinct credentials per node) |
| ConfigMap | n/a (config baked in image; reusable identically) |
| ARI credentials | **yes** (distinct) |
| RuntimeNode record | **yes** (distinct slug/id, both `asterisk-ari`) |
| adapter endpoint host | **yes** (each RuntimeNode's `control`/`events` host = its own Service DNS) |
| listener process | **no** — one `asterisk-ari-events` Deployment already claims both nodes via per-node leases |
| lease key | **yes** already (per event source = per node) |
| event-source epoch | **yes** already (per node) |
| monitoring labels | **yes** (per-node instance label) |
| proof-target selection | **yes** (each node inspected independently by binding) |

Answers: (1) same image reused — **yes**; (2) same Stasis app name safe on
separate processes — **yes** (per-instance); (3) bridge/channel IDs need node
qualification — **no**, they are already conference/participant-derived and the
active-binding join fences by node, and IDs are node-local namespaces; (4)
reusing canonical IDs on separate processes is **safe** (local namespaces); (5)
the current listener Deployment **already serves multiple RuntimeNodes**; (6) the
claim model **already supports** multiple ready nodes; (7) one listener process
failure affects both nodes' event ingestion (single point — acceptable for the
proof, a later HA concern, not a fencing blocker); (8) each node rolls out
independently (separate Deployments); (9) node A terminates without touching B
(separate Deployments/Services); (10) orphan inspection queries each node
independently through its own RuntimeNode/endpoint.

## RuntimeNode-to-workload identity

**Selected: RuntimeNode metadata → immutable Kubernetes workload reference
(namespace + Deployment name), validated against a UTCP-ownership label, resolved
by the fence adapter — never accepted from a public API.** The RuntimeNode
already carries a `labels` JSON column (set at registration); the canonical
mapping records the owning workload there (e.g.
`labels.kubernetes_workload = {namespace, deployment}`) at node-registration time
through the trusted deployment tooling, not via operator/public input. The
adapter resolves that reference, then **confirms the Deployment carries the UTCP
ownership label** (`app.kubernetes.io/part-of: utcp`, component `asterisk-ari`)
before any mutation, so a stray or mistyped reference cannot target a non-UTCP or
wrong-family workload.

Rejected alternatives: endpoint-host→Service→selector→Deployment (fragile —
selector edits or shared labels could resolve two Deployments); free-form
namespace/Pod/Deployment/selector from the domain API (**explicitly forbidden** —
the domain layer must not accept an arbitrary Kubernetes target); a brand-new
infra-target entity (unnecessary — the `labels` column + ownership-label
validation suffices, no schema). This is tenant-safe (RuntimeNode is
tenant-scoped), runtime-neutral (adapter-resolved), immutable (set at
registration), auditable (in the node record + fence operation payload), and
robust to Pod replacement (it targets the Deployment, not a Pod UID).

## Selected Kubernetes fencing target

**Deployment scale-to-zero** (`scale` subresource `spec.replicas = 0`), targeting
the node's own Deployment. It terminates all owned Pods (SIGTERM →
`terminationGracePeriodSeconds: 30` → SIGKILL; Asterisk exits, destroying its
in-memory bridges/channels) **and prevents automatic recreation** — the decisive
property. Pod deletion is rejected (the Deployment immediately recreates a new Pod
that would restore the old RuntimeNode as authoritative). NetworkPolicy isolation
is rejected as a *termination* proof (it stops control/ARI traffic but not the
process or any media). Selector change / pause is rejected (topology drift, may
not terminate the process). Restoration of the scaled-to-zero workload is a
**separate later "former-node workload restoration" policy** — a returning node
re-registers through the normal C2 lifecycle and its RuntimeNode becomes
selectable again only after readiness proof; it never auto-reclaims the failed
Conference (binding authority + generation already prevent that).

## Authoritative termination evidence

The `runtime.node.runtime.fence` completion predicate = **all** of:

| Evidence | Classification |
|---|---|
| Deployment `spec.replicas` observed 0 (mutation accepted) | required (mutation) |
| Deployment `status.replicas == 0` AND `status.availableReplicas == 0` | required (termination completed) |
| no Pod owned by the workload remains (`status.replicas`+`readyReplicas` 0; owned Pod list empty) | required (recreation prevented) |
| Service has zero ready endpoints (EndpointSlice) | **supporting** (routing withdrawn — corroborates, not sufficient alone) |
| old Pod UID no longer exists | supporting |
| container terminated exit state | supporting |
| ARI endpoint unreachable | **insufficient alone** (also produced by partition) |

Minimum sufficient set for `fenced`: mutation accepted **and** the workload
reports zero replicas / zero available / zero owned Pods. Service/EndpointSlice
removal is corroborating but never sufficient by itself (a NotReady Pod with a
live process also drops endpoints). **An incomplete or unconfirmed scale-down must
never produce `fenced` evidence** — the operation stays `fence_in_progress`
(retryable) until zero-replica/zero-Pod is observed, or `failed` on permission or
API error.

## Service and EndpointSlice evidence

`EndpointSlice` readiness reflects probe state, not process liveness: endpoint
absence is produced by Pod-NotReady, endpoint removal, *and* true termination
alike, so it is **supporting evidence only**. It corroborates a scale-to-zero
(zero endpoints is expected after termination) but must never be read as proof the
process/media stopped. Live check confirmed one ready EndpointSlice for the
current Service.

## Kubernetes RBAC boundary

Current state: **UTCP holds no Kubernetes API access** — three ServiceAccounts,
all `automountServiceAccountToken: false`, **zero Role/ClusterRole/binding objects
in the repo**, no Kubernetes client library in `composer.json`. The fence
requires a **narrow namespaced Role in `utcp-runtime`**, bound to a dedicated
fence ServiceAccount used only by the worker that executes the fence operation:

```
apiGroups: ["apps"]   resources: ["deployments"]        verbs: ["get","list","watch"]
apiGroups: ["apps"]   resources: ["deployments/scale"]  verbs: ["get","patch"]
apiGroups: [""]       resources: ["pods"]               verbs: ["get","list","watch"]
apiGroups: ["discovery.k8s.io"] resources: ["endpointslices"] verbs: ["get","list","watch"]
```

Namespace-scoped to `utcp-runtime` only; **no** delete-pods, no arbitrary
deployment patch beyond the scale subresource, no cluster-admin, no wildcards, no
cross-namespace mutation. Resource-name restriction is not enforceable on
`deployments/scale` via RBAC `resourceNames` for patch reliably across versions,
so **node B is protected at the adapter layer**: the adapter resolves only the
RuntimeNode's own recorded workload reference and re-validates the UTCP ownership
+ component label before patching, so a node-A fence request cannot scale node B.
The token must be mounted **only** on the executing worker (a scoped change from
today's `automountServiceAccountToken: false`), not on the API/web pods.

## Fencing adapter ownership

**A distinct `KubernetesRuntimeFenceAdapter` behind a new infrastructure-adapter
registry**, not the `RuntimeAdapterRegistry` (which is keyed by `adapterKey` =
runtime family for ARI execution/inspection; Kubernetes fencing is a different
infrastructure authority that applies across runtime families). The fence adapter:
accepts a validated RuntimeNode + its recorded workload identity; resolves and
ownership-validates the Deployment; requests scale-to-zero idempotently; polls
termination completion; returns bounded adapter-neutral outcomes; and **never**
writes RuntimeBindings, observed Conference state, invokes reconstruction, or
chooses the replacement. The generic operation engine dispatches to it via a new
handler that selects the infrastructure adapter by target kind
(`runtime_node.runtime.fence`), keeping Kubernetes specifics out of the Asterisk
ARI adapter.

## Generic runtime-fence operation

`runtime.node.runtime.fence` — target = the former RuntimeNode; payload =
`{runtime_node_id, workload_ref, fence_reason, configuration_generation,
former_runtime_binding_id, conference_id}`. Idempotency keyed on
`former_node + conference + binding + generation` (mirrors `verify_conference_absent`),
so concurrent requests collapse to one operation. Outcomes:

| Outcome | Retry? | Authorizes rebind? |
|---|---|---|
| `fenced` (replicas 0, no Pods) | no | **yes** (records durable fence evidence) |
| `already_fenced` (workload already at 0) | no | yes |
| `fence_in_progress` (scaled, Pods still terminating) | yes | no |
| `target_recovered` (node re-projected ready before mutation) | no | no — **abandon fence** |
| `target_mismatch` (workload ownership/label mismatch, or authority generation moved) | no | no |
| `unavailable_to_control` (K8s API unreachable) | yes | no |
| `permission_denied` (RBAC) | no (alert) | no |
| `failed` (other) | classified | no |

A completed `fenced` result is reused via idempotency; evidence becomes stale if
the binding or Conference generation changes before rebind (validated in the
rebind transaction, exactly like the absence path). A worker restart resumes by
re-reading the operation row and re-polling. Fencing a replacement workload
created under a changed authority context is prevented by the generation-tagged
idempotency key + ownership-label re-validation. **Never exposed as a management
command.**

## Idempotency and concurrency

Same discipline as T5-A3: one idempotent operation per (former node, conference,
binding, generation); `SKIP LOCKED` candidate claiming in the coordinator; the
primitive's row locks are the final serialization; two fence operations for the
same node collapse to one; a completed fence is reused, not repeated.

## Absence verification integration

The combined flow extends the existing `RuntimeFencingCoordinator::evaluate`
outcomes:

```
verify_conference_absent →
  absent      → validate evidence → failoverRebindConferenceAfterFence  [EXISTS today]
  present     → runtime.node.runtime.fence → fenced → rebind             [NEW: escalate]
  unavailable → runtime.node.runtime.fence → fenced → rebind             [NEW: escalate]
  failed      → classify; retry or fence explicitly; never infer absence [EXISTS: no rebind]
```

Today `present`/`unavailable` (former runtime reachable-and-hosting, or
unreachable) terminate without rebind — exactly the hard-outage/partition case the
external fence resolves. `failoverRebindConferenceAfterFence()` must accept
**either** `verified_absence` **or** `external_runtime_fenced` evidence; the
narrowest transaction-time validation is unchanged (active binding, former node,
generation, open desired state, sustained unavailable/stale eligibility) plus the
fence operation completed with a matching authority digest. **No fallback bypasses
both evidence types.**

## Fencing and rebind ordering

`detect (coordinator) → verify absent → if absent, rebind; else request
runtime.node.runtime.fence → prove termination (replicas 0 / no Pods) → record
fenced evidence → rebind → reconstruct (existing reconcilers)`. Node recovery
before mutation → `target_recovered`, abandon (primitive's under-lock ready
recheck backstops). No replacement after a successful fence → wait (no false
projection). Reconstruction failure → reconciler retries on the new node.

## RuntimeFencingCoordinator responsibilities

The **existing** `RuntimeFencingCoordinator` orchestrates both verification and
external fencing (one orchestration authority; no subordinate service needed). It
stays adapter-neutral (dispatches generic operations, never calls Kubernetes/ARI
directly), persists through operation rows, survives restart (re-reads
operations), avoids duplicate requests (idempotency), validates evidence before
rebind (in the domain transaction), abandons stale work on binding/generation
change, does not fence a recovered node (`target_recovered` + under-lock recheck),
and never auto-failbacks. New responsibility = a `former_runtime_present`/
`former_runtime_unavailable` → dispatch-fence branch; the ARI absence check and the
Kubernetes fence stay behind their respective adapters.

## Second RuntimeNode registration

**Declarative + normal C2 lifecycle, no manual creation required.** Node B is
added by applying its manifests (Deployment/Service/Secret B) and registering its
RuntimeNode through the same authenticated C2 API path the current node uses
(deployment tooling, not a human), which projects its endpoints and credentials;
the existing listener then claims it (per-node lease), proves ARI/Stasis health,
projects `ready`, and the replacement selector may choose it — all automatic after
readiness. Two distinct credentials are two Secrets + two `runtime_node_credentials`
rows. A removed/stale workload retires its RuntimeNode by transitioning
`desired_state` to `disabled` through the C2 API (existing capability). The current
single-node tooling registers one node; the second-node slice generalizes that
registration to parameterize node identity — it does **not** introduce a manual
step.

## Asterisk multi-instance contract

Verified (docs + pinned image, T5-A3 + this audit): (1) same ARI app name safe
across separate processes — **yes**; (2) each instance needs its own ARI WebSocket
— **yes** (already per-node); (3) bridge/channel IDs are node-local — **yes**; (4)
identical explicit IDs can exist independently on A and B — **yes** (separate
namespaces; the active-binding join fences by node); (5) required modules on both
— the existing ARI/Stasis/bridge/Local set in `modules.conf` (readiness script
already asserts them); (6) Local channels + dialplan identical on both — **yes**
(baked in the shared image); (7) shared file/db/socket/port collision — **none**
(each Pod is isolated; ARI is per-Pod ClusterIP; no shared volume); (8)
internal-only ARI Services sufficient — **yes**; (9) current module/readiness
checks reusable unchanged — **yes**; (10) reconstruction without SIP/RTP modules —
**yes** (Local-channel conferences need no SIP/RTP).

## Implementation dependency order

**Order B — workload-identity + generic fence operation + Kubernetes adapter
contract first, then second-node manifests.** The fence operation, adapter, RBAC,
and coordinator escalation branch are all **repository-testable without a second
live node** (fake Kubernetes client / stubbed adapter, operation-outcome
classification, rebind-evidence validation), and they are the actual blocker that
makes hard-outage failover safe. Second-node manifests + registration are a
separable slice that only becomes *useful* once a replacement can be selected, but
they are not a prerequisite for building and unit-proving the fence path. Rejected:
Order A (manifests first) delivers a second node the coordinator still cannot
safely fail over to; Order C bundles too much.

## Failure-scenario assessment (fence)

1. ARI unavailable, Pod running → verify=present/unavailable → fence → rebind
   only after termination proven. 2. Pod terminating normally → `fence_in_progress`
   until zero-Pod, then `fenced`. 3. Pod deleted, controller recreates → **why
   scale-to-zero, not delete**: delete would let the new Pod restore the node;
   scale-to-zero prevents recreation. 4. Deployment scaled to 0 → the fence action
   itself → `fenced` on zero-replica confirmation. 5. Fence repeated after success
   → `already_fenced` (idempotent). 6. Worker crash mid-termination → resume by
   re-reading the operation, re-poll. 7. Recovery before mutation → `target_recovered`,
   no fence, no rebind. 8. Recovery after mutation but before termination → the
   scale-to-zero still terminates it; a returning node re-registers later (no
   auto-reclaim). 9. Node B matched by node-A selector → prevented by
   adapter-layer workload-ref + ownership-label validation. 10. Rollout changes
   Pod UID during fencing → target is the Deployment (not a UID); scale-to-zero is
   UID-independent. 11. EndpointSlice gone before termination → supporting-only;
   fence still requires zero-replica proof. 12. Process exits, Pod API object still
   Terminating → not yet `fenced` (Pod still owned); wait for removal. 13. K8s API
   unavailable → `unavailable_to_control`, retry, no rebind. 14. Fence SA lacks
   permission → `permission_denied`, no rebind, alert. 15. No replacement after
   fence → wait. 16. Coordinator restart after fence, before rebind → durable fence
   evidence in the operation row; resumes. 17. Former workload restored after
   rebind → no reclaim (binding authority + generation; delayed events/ops fenced).
   18. Two fence operations same node → idempotency collapse. **Rebind is allowed
   only after `fenced`/`already_fenced` or `absent_verified` — never on
   `in_progress`, `unavailable_to_control`, `permission_denied`, `target_recovered`,
   `target_mismatch`, or `failed`.**

## Existing implementation

Multi-node-capable listener claim model (per-node lease/epoch, `eligibleNodes`
iterates all asterisk-ari nodes) and `ensure-targets`; C2 RuntimeNode registration
with per-node endpoints/credentials and `labels` JSON; deterministic
replacement selector with node exclusion; atomic generation-fenced rebind
(`failoverRebindConferenceAfterFence`); `RuntimeFencingCoordinator` with a
`former_runtime_present`/`former_runtime_unavailable` terminal branch ready to
escalate; generic operation engine + idempotency + durable audit/outbox evidence;
Deployment scale subresource, EndpointSlice, and observable Pod UID confirmed live.

## Missing implementation

`runtime.node.runtime.fence` operation + handler; `KubernetesRuntimeFenceAdapter`
+ infrastructure-adapter registry; the RuntimeNode→workload identity binding
(`labels.kubernetes_workload`) set at registration; narrow `utcp-runtime` RBAC +
scoped fence ServiceAccount with a mounted token on the executing worker only; the
coordinator `present`/`unavailable` → fence escalation branch;
`failoverRebindConferenceAfterFence` accepting `external_runtime_fenced` evidence;
parameterized second-node manifests + registration; live two-node acceptance.

## T5-A4 implementation-readiness decision

**A — one bounded Codex implementation slice is ready.** Workload identity,
fencing target, termination evidence, adapter ownership, RBAC, operation semantics,
dependency order (B), and focused tests are all defined, and the fence path is
fully repository-testable without a live second node.

## T5-A4 first bounded implementation slice

**The generic `runtime.node.runtime.fence` operation + a `KubernetesRuntimeFenceAdapter`
contract behind a new infrastructure-adapter registry, driven through a fake
Kubernetes client — repository-only, no RBAC applied live, no second node, no
live fencing.** Adds the operation type + handler, the adapter interface + a
Kubernetes implementation that takes an injected client abstraction (patch
scale, read Deployment/Pod status), the RuntimeNode→workload resolution with
ownership-label validation, the outcome enum with the exact rebind-authorization
rules, the coordinator `present`/`unavailable`→fence escalation branch, and
`failoverRebindConferenceAfterFence` acceptance of `external_runtime_fenced`
evidence. Excludes: applying RBAC to the cluster, mounting the SA token,
second-node manifests, and any live scale mutation.

## Ready-to-paste Codex prompt (T5-A4)

```
# T5-A4 — Generic Kubernetes runtime-fence operation and adapter (repository-only, faked client)

Repository-only slice at HEAD cf621eb. Keep UTCP_PHASE=T1. Do NOT create a second
Asterisk node, apply RBAC to the cluster, mount a service-account token, run live
failover, scale/delete any Deployment/Pod, or add a manual fence command.

Context (docs/evidence/t2/multi-node-failover-readiness.md §T5-A4): the T5-A3
RuntimeFencingCoordinator returns former_runtime_present / former_runtime_unavailable
without rebinding when the former Asterisk still hosts the conference or is
unreachable (hard outage / partition). Add an external Kubernetes runtime fence
(Deployment scale-to-zero) that proves the former process terminated, then allow
rebind. All Kubernetes calls go through an injected client abstraction so this is
unit-tested with a fake — no live cluster calls.

1. RuntimeNode→workload identity: at RuntimeNode registration, allow the trusted
   tooling to record labels.kubernetes_workload = {namespace, deployment}. Add a
   resolver that reads it and returns null if absent (do NOT accept a workload ref
   from any public/operator API field). No schema change (reuse the runtime_nodes
   labels JSON column).

2. Infrastructure adapter: new interface App\RuntimeEngine\Infrastructure\RuntimeInfrastructureFenceAdapter
   with fence(RuntimeNode workloadRef, authorityContext): FenceOutcome; and a new
   App\RuntimeEngine\Infrastructure\InfrastructureAdapterRegistry (separate from
   RuntimeAdapterRegistry). Implement App\RuntimeAdapters\Kubernetes\KubernetesRuntimeFenceAdapter
   taking an injected KubernetesWorkloadClient interface (methods: getDeploymentScale,
   patchDeploymentScale, getDeploymentStatus, listOwnedPods) — provide a real HTTP
   impl stub AND a fake used in tests. The adapter must: resolve+validate the
   Deployment carries app.kubernetes.io/part-of=utcp and component=asterisk-ari
   before mutating; patch scale to 0 idempotently; poll status; return a bounded
   FenceOutcome enum: fenced, already_fenced, fence_in_progress, target_recovered,
   target_mismatch, unavailable_to_control, permission_denied, failed. NEVER write
   bindings/observed state/reconstruction.

3. Generic operation runtime.node.runtime.fence: add the operation type
   (config/telephony_domain.php or runtime_engine.php operation_types), a handler
   that selects the infrastructure adapter by target kind and executes the fence,
   completing with a durable event conference.runtime_fence_terminated carrying
   {operation_id, runtime_node_id, verification_result: 'external_runtime_fenced',
   configuration_generation} on fenced/already_fenced only. fence_in_progress and
   unavailable_to_control are retryable; target_recovered/target_mismatch/
   permission_denied/failed do not authorize rebind. Idempotency key mirrors
   verify_conference_absent (former node + conference + binding + generation).

4. RuntimeFencingCoordinator: in evaluate(), when the verification gate returns
   former_runtime_present or former_runtime_unavailable, create/observe one
   idempotent runtime.node.runtime.fence operation for the former node; on a
   completed fenced/already_fenced result, call
   failoverRebindConferenceAfterFence with the fence operation id and reason
   'external_runtime_fenced'; otherwise return the mapped non-rebind outcome. Do
   not remove the absent_verified→rebind path.

5. TelephonyDomainService::failoverRebindConferenceAfterFence: accept EITHER a
   completed verify_conference_absent (verification_result 'absent') OR a completed
   runtime.node.runtime.fence (verification_result 'external_runtime_fenced')
   operation id; validate inside the serialized rebind transaction against the
   current active binding, former node, conference generation, open desired state,
   and sustained unavailable/stale eligibility — exactly as today. No fallback that
   bypasses both evidence types.

6. Tests (tests/Feature/TelephonyDomain/ or Failover/ or Infrastructure/):
   - fake client: scale patched to 0 and status/owned-pods report zero →
     adapter returns fenced; coordinator then rebinds once; one active binding;
     generation bumped.
   - scaled but pods still terminating → fence_in_progress → no rebind.
   - deployment lacks the UTCP ownership/component label → target_mismatch → no
     rebind, no scale patch issued.
   - client reports API unavailable → unavailable_to_control → no rebind.
   - node re-projected ready before mutation → target_recovered → no rebind.
   - rebind accepts external_runtime_fenced evidence but rejects stale-generation
     fence evidence.
   - idempotent re-evaluation after a successful fenced rebind makes no second
     binding change.

7. Update docs/evidence/t2/multi-node-failover-readiness.md §T5-A4 with an
   "implemented" note (fence operation+adapter+coordinator escalation in place;
   live RBAC, token mount, second-node manifests, and live two-node acceptance
   still pending).

Verification (all must pass):
  make repository-hygiene && make secret-scan
  make runtime-engine-config-check && make telephony-domain-config-check
  make asterisk-ari-config-check && make asterisk-conference-config-check
  make runtime-engine-test && make telephony-domain-test
  make asterisk-ari-test && make asterisk-conference-recovery-test
  make test && make check && make build
  git diff --check

One scoped commit, e.g.:
  feat(t5): add generic Kubernetes runtime-fence operation and adapter
Do not push. Do not apply RBAC live, scale any Deployment, deploy a second node,
or run live failover.
```

## T5-A4 staged acceptance criteria

**Repository acceptance:** RuntimeNode→workload identity resolved from recorded
labels (never public input) with ownership-label validation; scale-to-zero fence
via an injected client (faked in tests); `fenced` requires zero-replica/zero-Pod
proof; the outcome enum authorizes rebind only on `fenced`/`already_fenced`;
coordinator escalates `present`/`unavailable` to the fence and rebinds only on
proven termination; `failoverRebindConferenceAfterFence` accepts either absence or
external-fence evidence with unchanged transaction validation; no live RBAC/token/
scale, no second node; focused tests green.

**Live acceptance (later, layered):** apply the narrow `utcp-runtime` fence Role +
scoped SA (token mounted only on the executing worker); deploy node B; bind a
conference to node A; hard-fail node A (process alive, ARI unreachable) → verify
present/unavailable → scale-to-zero fence → termination proven → rebind once to
node B → reconstruct exactly one bridge/participant on B; node A's scaled-to-zero
workload restored later re-registers without reclaiming; both-node orphan
inspection clean; replica counts restored. Future Kamailio SIP application-dialog
routing and its intrinsic cutoff are proven in the routing phases that create that authority.

## T5-A4 repository implementation note

T5-A4 adds the generic `runtime.node.runtime.fence` operation and keeps it behind
the existing durable operation engine. Operation identity is bound to the
Conference, former RuntimeNode, former active RuntimeBinding, and Conference
configuration generation, matching the authority context used by
`runtime.node.verify_conference_absent`.

RuntimeNode workload identity is read only from persisted
`runtime_nodes.labels.kubernetes_workload` metadata:

```json
{
  "kubernetes_workload": {
    "namespace": "utcp-runtime",
    "deployment": "asterisk-ari-a"
  }
}
```

The resolver accepts only non-empty DNS-compatible namespace and Deployment names,
requires the canonical runtime namespace, performs no endpoint-host inference, and
has no fallback to the current single-node Deployment or Pod discovery.

Infrastructure fencing is now a separate runtime-neutral adapter boundary. The
Kubernetes adapter uses an injected `KubernetesWorkloadClient`; the repository
binding fails closed without configured Kubernetes credentials. Focused tests use a
fake client, and production code contains no shell or `kubectl` execution path.

Before mutation, the adapter fetches the exact Deployment from the trusted
RuntimeNode workload reference and validates UTCP ownership labels:

- `app.kubernetes.io/part-of = utcp`
- `app.kubernetes.io/component = asterisk-ari`
- `utcp.dev/runtime-node = <RuntimeNode slug>`

Mismatch, relabeling, missing ownership, or an operation target that does not
match the former RuntimeNode returns `target_mismatch` and does not patch scale.

The only Kubernetes mutation represented in this slice is Deployment
scale-to-zero through the injected client. Pods are never deleted directly, Service
selectors are not changed, RuntimeNode desired/observed state is not changed, and
bindings remain writable only by `TelephonyDomainService`.

The authoritative termination predicate for `fenced` or `already_fenced` requires:

- Deployment desired replicas is zero.
- Deployment status replicas is zero or omitted.
- Deployment available replicas is zero or omitted.
- No Pod owned by that Deployment remains.

EndpointSlice emptiness and old Pod UID disappearance are supporting signals only;
they cannot prove fencing while any owned Pod remains. API unavailability,
permission denial, target mismatch, and in-progress termination block rebind.

`RuntimeFencingCoordinator` now escalates `present` and unreachable former-runtime
absence-verification outcomes to one idempotent `runtime.node.runtime.fence`
operation. `fenced` and `already_fenced` produce bounded
`conference.runtime_fence_terminated` evidence. `failoverRebindConferenceAfterFence`
authorizes rebind only from current `verified_absence` evidence or current
`external_runtime_fenced` evidence, and validates the evidence type, operation
status, current binding, former RuntimeNode, Conference generation, open desired
state, sustained failover eligibility, and distinct replacement eligibility inside
the serialized authority-cutoff transaction.

This remains repository proof only. Namespaced Kubernetes fencing RBAC, dedicated
worker identity and token mount, live Deployment scale-to-zero proof, second-node
manifests, second RuntimeNode registration, replacement reconstruction proof, and
live two-node failover acceptance remain pending.

---

# T5-A5 — Live Kubernetes Fencing Activation, Replacement Safety, and Second-Node Manifest Readiness

Evidence-only audit at commit `98c0489`, `UTCP_PHASE=T1`, 2026-07-18. Defines the
safe contract to turn the repository-complete fence abstraction into a
live-capable capability. No live RBAC/token/scale, no second node, no live
failover.

## Fence-operation execution path

`runtime.node.runtime.fence` row → claimed by `RuntimeOperationRepository::claimAvailable`
(**global `FOR UPDATE SKIP LOCKED`, no operation-type/queue filter**) → executed
by `CommandWorker` (`telephony-command-worker` Deployment, plus `scheduler` which
runs `runtime-engine:command-worker --once` every minute) → `RuntimeFenceOperationHandler`
(registered in the shared `RuntimeOperationHandlerRegistry`) → `InfrastructureAdapterRegistry->get('kubernetes')`
→ `KubernetesRuntimeFenceAdapter->fence()` → `KubernetesWorkloadClient` (bound to
`UnavailableKubernetesWorkloadClient` today → `unavailable_to_control` → no scale,
no rebind). Answers: the handler runs on **`telephony-command-worker`** (and the
scheduler's `--once` pass); that worker also processes **all** other runtime
operations (conference ensure/close, participant ensure/remove, verify-absent,
node inspect); granting *it* Kubernetes scale authority would broaden the token to
every unrelated operation; the claim query has **no queue routing**, so today a
dedicated worker requires either an operation-type claim filter or a separate
handler registry per worker; a dedicated worker **is required** to keep token
exposure narrow.

## Selected worker identity

**Option C — a dedicated infrastructure-operation worker Deployment with a
handler-scoped claim.** Add a `runtime-engine:infrastructure-worker` command whose
`CommandWorker` claims **only** infrastructure operation types
(`runtime.node.runtime.fence`) via an operation-type filter on `claimAvailable`,
running as its own Deployment with the fence ServiceAccount + projected token; and
**remove** `RuntimeFenceOperationHandler` from the generic worker's registry (or
have the generic worker skip infrastructure types) so the ordinary
`telephony-command-worker` never claims a fence operation and never needs the
token. Operation ownership stays in PostgreSQL (both workers use the same
`runtime_operations` table + `SKIP LOCKED`; the type filter ensures only the
infra worker claims fences — no double-claim). The infra worker is an **executor
of generic operations**, not a management interface. Rejected: A (broadens token
to all operations), B-without-filter (two workers could claim the same op),
D/none.

## Kubernetes client contract

**A small bounded Kubernetes REST client on the existing stack** (`guzzlehttp/guzzle`
and `Illuminate\Http\Client` are already installed — no new SDK dependency). It
implements only `getDeployment`, `scaleDeployment` (PATCH scale subresource),
`listOwnedPods` (LIST by validated ownership selector), and optionally LIST
EndpointSlices. Contract: API server from `KUBERNETES_SERVICE_HOST`/`_PORT`
(injected — confirmed `10.43.0.1:443`); CA from
`/var/run/secrets/kubernetes.io/serviceaccount/ca.crt`; **token reread per request**
from `.../token` (projected tokens rotate ~hourly, kubelet-refreshed); bounded
connect + request timeouts; TLS **verified against the mounted CA** (never
disabled); retry classification — 401/403 → `permission_denied` (terminal, alert),
404 → `target_mismatch` (deployment gone), 409 conflict → retry,
5xx/timeout/connection → `unavailable_to_control` (retryable); parse Kubernetes
`Status` objects for `reason`; **sanitized logging** (no token, no full object
dumps). Credentials are never read from operation payloads.

## In-cluster authentication

Kubernetes/k3d **v1.35.3+k3s1**; projected bound ServiceAccount tokens are GA
(kubelet fetches a time-bound token via TokenRequest, refreshes before ~1h
expiry, invalidated on Pod deletion). Contract: keep `automountServiceAccountToken:
false` **globally**; mount an **explicit projected token volume only into the
dedicated infrastructure worker** (audience = the API server default, expiry
~3600 s); the client rereads the token file per request (do not cache across the
rotation window); CA path as above; service host/port from env. The token is
**never** mounted into the Web/API, scheduler, Asterisk listener, Asterisk
runtime, or the generic command worker.

Sources: [Configure Service Accounts for Pods](https://kubernetes.io/docs/tasks/configure-pod-container/configure-service-account/), [Projected Volumes](https://kubernetes.io/docs/concepts/storage/projected-volumes/), [Using RBAC Authorization](https://kubernetes.io/docs/reference/access-authn-authz/rbac/).

## Kubernetes RBAC

Smallest namespaced Role in `utcp-runtime`, bound to the dedicated fence SA. Exact
proposed YAML (do **not** apply):

```yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: utcp-runtime-fencer
  namespace: utcp-runtime
  labels: { app.kubernetes.io/part-of: utcp }
automountServiceAccountToken: false
---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: utcp-runtime-fencer
  namespace: utcp-runtime
  labels: { app.kubernetes.io/part-of: utcp }
rules:
  - apiGroups: ["apps"]
    resources: ["deployments"]
    verbs: ["get", "list"]
  - apiGroups: ["apps"]
    resources: ["deployments/scale"]
    verbs: ["get", "patch"]
  - apiGroups: [""]
    resources: ["pods"]
    verbs: ["get", "list"]
  - apiGroups: ["discovery.k8s.io"]
    resources: ["endpointslices"]
    verbs: ["get", "list"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: utcp-runtime-fencer
  namespace: utcp-runtime
  labels: { app.kubernetes.io/part-of: utcp }
roleRef: { apiGroup: rbac.authorization.k8s.io, kind: Role, name: utcp-runtime-fencer }
subjects:
  - { kind: ServiceAccount, name: utcp-runtime-fencer, namespace: utcp-runtime }
```

`watch` is omitted (the adapter polls, not watches). `resourceNames` is **not**
applied to `deployments/scale` (RBAC `resourceNames` does not reliably constrain
subresource patch across versions and would break as node B is added); the
wrong-workload restriction is instead enforced at the **adapter ownership layer**
(`KubernetesRuntimeFenceAdapter::isOwnedAsteriskDeployment` requires
`app.kubernetes.io/part-of=utcp`, `component=asterisk-ari`, and
`utcp.dev/runtime-node == RuntimeNode.slug`, before *and* after the scale). No
delete-pods, no full-deployment patch, no create/update, no secrets, no wildcards,
no cluster roles, no cross-namespace access.

## Current replacement-safety behavior

**Unsafe once the real client is wired.** `RuntimeFencingCoordinator::requestRuntimeFence`
creates the fence operation, and `RuntimeFenceOperationHandler`/`KubernetesRuntimeFenceAdapter::fence()`
scale the former Deployment to zero, with **no replacement-existence check at any
point before the destructive mutation**. Replacement selection happens only later,
inside `failoverRebindConferenceAfterFence` (`selectRuntimeNodeForConference(...excludeRuntimeNodeId)`
→ throws 422 if none). Today the sole protection is `UnavailableKubernetesWorkloadClient`
returning `unavailable_to_control` before any scale — i.e. fencing is disabled by
capability absence, not by a replacement guard. With one ready Asterisk node
(current reality), wiring the real client would let a fence **scale node A to zero
and then fail to find a replacement**, leaving the Conference with a terminated
runtime and no rebind. This is the mandatory blocker.

## Required replacement-before-fence invariant

**Mandatory: a distinct, active, ready, capability-compatible replacement
RuntimeNode must exist before the destructive scale mutation, and be revalidated
immediately before that mutation.** A candidate-query or operation-creation
precheck alone is insufficient because the replacement's health can change while
the fence operation waits in the queue. The canonical authority for the
mutation-time check is the **fence operation execution boundary** — the
`RuntimeFenceOperationHandler` (delegating to a domain read service), because that
is the last serialized point UTCP controls before the adapter's irreversible
scale. Safety default: **no eligible replacement → no scale mutation → no fenced
evidence → existing binding remains** (the operation returns a retryable
`no_replacement_available` outcome, not `fenced`).

## Mutation-time replacement revalidation

The handler, immediately before calling `$infra->fence()`, must call a canonical
domain read (e.g. `TelephonyDomainService::hasDistinctEligibleReplacement(tenantId,
conferenceId, formerRuntimeNodeId)`) that reselects a distinct
`active`+`ready`+capability-compatible node under the current snapshot; if none,
return retryable `no_replacement_available` (blocks scale, blocks rebind, leaves
the binding). The check lives in the handler (not the adapter — the adapter stays
Kubernetes-only and domain-agnostic; not only the coordinator — its earlier check
can go stale in the queue). It uses the same read the rebind transaction uses, so
they agree. The candidate replacement ID is **not persisted as authority** and
**not passed to the adapter**: it is a destructive-mutation precondition only; the
rebind transaction still selects and validates canonically (the replacement ID
must not become a second binding authority). Stale evidence: if the replacement
disappears after scale starts but before rebind, `failoverRebindConferenceAfterFence`
still throws 422 and the fenced (scaled-to-zero) node is simply restored later by
the restoration policy — the invariant prevents *starting* the scale without a
replacement, which is the irreversibility that matters.

## Single-node safety

With one node (today), the mutation-time check finds no distinct replacement →
`no_replacement_available` → no scale, no rebind, binding preserved. This makes
wiring the real client safe even before node B exists: the guard, not the
`UnavailableKubernetesWorkloadClient`, becomes the durable protection, so the two
changes (real client, replacement guard) must land **together** — the client must
not be enabled without the guard.

## Safe activation order

**Order 3 (repository-first, then node B, then worker authority last).** (1) Land
both repository changes — the production client **and** the replacement-before-fence
guard — in one or sequential commits, with the client still bound to
`Unavailable` by default (capability absence). (2) Deploy node B manifests +
register RuntimeNode B; prove it observed `ready` (a distinct eligible
replacement now exists). (3) **Last**, grant the dedicated infra worker the fence
SA + projected token and bind the real client, which activates live fencing. This
ordering means that at the moment live fencing becomes possible, a ready
replacement already exists and the mutation-time guard is in force; enabling the
real client makes scale-to-zero live immediately through the existing
scheduler/coordinator (no manual enable switch), and that is safe **only** because
(a) the guard blocks fencing without a replacement and (b) node B is already
ready. Deployment ordering + readiness + canonical eligibility provide safety — no
runtime enable flag is added.

## Parameterized Asterisk manifest model

**Kustomize components** (the repo already uses Kustomize base + local overlay; do
not introduce Helm). A reusable `components/asterisk-instance` parameterized by
instance suffix produces, per instance, a distinct: Deployment name
(`asterisk-ari-a`/`-b`), Service name, Secret (`…-a`/`-b-credentials`), ARI
endpoint host, RuntimeNode slug, `utcp.dev/runtime-node` label + selector,
monitoring identity, and registration payload. Shared unchanged: image, baked
dialplan/module config, readiness/liveness probes, the `utcp-runtime-asterisk`
runtime ServiceAccount, and the Stasis app name (safe per-process, verified
T5-A3/A4). The current single instance becomes `…-a` via the component;
`…-b` is a second component invocation. The `utcp.dev/runtime-node` label +
per-instance selector (already added to the base Deployment at this HEAD) makes
each Deployment independently ownership-validatable and scalable without matching
the other.

## RuntimeNode B registration

Current authority = **authenticated C2 registration API** (the `scripts/asterisk-ari/api-proof`
flow POSTs to `/api/v1/admin/runtime-nodes` + `/endpoints`, sets credentials, and
transitions desired state — no manual DB writes, no seeding). Node B follows the
identical canonical lifecycle: manifests available → authenticated C2 registration
(RuntimeNode record, endpoints = node B's own Service DNS, distinct ARI
credentials, `conference.lifecycle`+`participation` capabilities, and the
`labels.kubernetes_workload = {namespace: utcp-runtime, deployment: asterisk-ari-b}`
workload reference) → listener claims it (per-node lease/event source — already
multi-node) → ARI/Stasis readiness proven → observed `ready` → the replacement
selector may use it. Two credentials = two Secrets + two `runtime_node_credentials`
rows. A removed workload retires via `desired_state → disabled` through the C2 API.
The slice generalizes the single-node registration to parameterize node identity;
it introduces **no manual step**. This same authenticated C2 API is the one a
future **Provider Node Admin UI** must call — the UI becomes a client of the
existing registration authority, never a competing registration path (do not
implement the UI now).

## Provider Node management-authority alignment

RuntimeNode registration, endpoints, credentials, capabilities, desired state, and
the workload-reference label are all owned by the C2 `/api/v1/admin/runtime-nodes`
authority in PostgreSQL. The workload-identity label must be set **only** through
that trusted registration path (never accepted from a public field on a
conference/failover API), and the fencing adapter only *reads* it. A later Admin UI
drives the same API; there is no second management surface.

## Non-destructive validation

Without applying anything: `kubectl kustomize` render of base + the new component/
overlays; **server-side dry-run** (`kubectl apply --dry-run=server -f -`, GA in
v1.35 — validates against the live API including admission, without persisting) for
the RBAC, SA, worker Deployment, and node-B manifests; ownership-label assertion
(each Deployment carries `part-of=utcp`, `component=asterisk-ari`,
`utcp.dev/runtime-node=<slug>`); RBAC-manifest lint; Service/Secret name-collision
scan across instances; RuntimeNode registration-payload validation (schema of the
C2 POST body); worker-Deployment token-volume validation (projected volume present
only on the infra worker, `automount=false` elsewhere). Do **not** apply RBAC,
create node B, scale the current Deployment, or rely on client-side dry-run when
server-side is available. The later bounded **live** proof (RBAC applied, token
mounted, node B ready, one real scale-to-zero on a disposable target) is a
separate task.

## T5-A5 failure-scenario assessment

Scale allowed? / Rebind allowed? / behavior:

1. Only node A → **no scale / no rebind** (guard: no replacement); retry, binding
   preserved. 2. Node B ready before fence op created → scale allowed once
   revalidated at mutation; rebind after fenced. 3. Node B fails while fence op
   waits → mutation-time revalidation finds none → **no scale**; retryable
   `no_replacement_available`. 4. Node B fails immediately before scale → same
   (revalidation is the last gate) → **no scale**. 5. Node B fails after node A
   scale begins → scale already committed; rebind 422 → node A restored later by
   restoration policy; **no split-brain** (node A is terminating). 6. Worker lacks
   token → `unavailable_to_control` → no scale, retry. 7. Token expired/rotating →
   client rereads per request; transient 401 → `unavailable_to_control` retry;
   persistent → `permission_denied`. 8. API unavailable → `unavailable_to_control`,
   retry, no rebind. 9. get but not scale permission → `permission_denied`,
   terminal, no scale, alert. 10. Ownership labels mismatch → `target_mismatch`,
   no scale. 11. Node A Deployment already zero → `already_fenced` (still requires
   a replacement to rebind). 12. Node A restored before rebind → `target_recovered`
   / rebind's under-lock ready recheck aborts → no rebind. 13. Node A restored
   after rebind → no reclaim (binding + generation). 14. Two infra workers claim
   overlapping ops → `SKIP LOCKED` + idempotency → one claim. 15. Generic workers
   accidentally get the token → prevented by design (token only on the infra
   worker; the generic worker no longer claims fence ops). 16. Node B Service/Secret
   collides with node A → caught by the non-destructive collision scan pre-deploy.
   17. Registration duplicate RuntimeNode identity → the C2 unique slug/idempotency
   rejects it. 18. Scheduler active before node B ready → guard blocks fencing
   until a replacement is ready (no scale). **Rebind is allowed only after
   `fenced`/`already_fenced` (with a replacement) or `absent_verified`.**

## T5-A5 existing implementation

Full fence abstraction (`runtime.node.runtime.fence`, handler, adapter with
ownership validation + scale-to-zero + termination predicate, workload-identity
resolver, evidence, dual-evidence transactional rebind); coordinator escalation
(`former_runtime_present`/`unavailable` → fence request → `external_runtime_fenced`
→ rebind); the `utcp.dev/runtime-node` per-instance label + selector on the base
Deployment; the `UnavailableKubernetesWorkloadClient` default; Guzzle +
`Illuminate\Http\Client`; live-confirmed injected API env, `automount=false`
everywhere, projected-token GA, scale subresource, server-side dry-run.

## T5-A5 missing implementation

The production `HttpKubernetesWorkloadClient` (bounded REST, per-request token
reread, CA TLS); the dedicated infrastructure worker (operation-type-filtered
claim) + its Deployment with a projected token; the narrow `utcp-runtime` fence
Role/SA/binding; Kustomize component parameterization for `asterisk-ari-a/-b`;
node-B registration payload generalization; the later live RBAC/token/scale
proof.

## T5-A5 implementation-readiness decision

**A — one bounded Codex implementation slice is ready.** Worker identity (dedicated
infra worker), client contract (bounded REST on Guzzle), authentication (projected
token, per-request reread), exact RBAC, the replacement-before-fence invariant and
its mutation-time revalidation owner (the handler), activation order (3), manifest
parameterization (Kustomize component), and registration (C2 API) are all defined,
and the first slice is repository-testable without any live mutation.

## T5-A5 first bounded implementation slice

**The mutation-time replacement-before-fence guard — repository-only, no client,
no RBAC, no node B, no live scale.** It is the earliest dependency and the one that
makes every later activation step safe: without it, wiring the real client is
unsafe with one node. Add
`TelephonyDomainService::hasDistinctEligibleReplacement(tenantId, conferenceId,
formerRuntimeNodeId): bool` (reusing `selectRuntimeNodeForConference` semantics,
`active`+`ready`+both capabilities, excluding the former node, catching the 422),
call it in `RuntimeFenceOperationHandler::execute` immediately before
`$infra->fence()`, and return a retryable `no_replacement_available` failure when
false (no scale, no evidence). This lands while the client is still
`Unavailable`, so it is inert in production but fully unit-tested; it is the
precondition for the later client/RBAC/worker and node-B slices.

## T5-A5 implemented repository guard

The repository now enforces the destructive-mutation precondition inside
`RuntimeFenceOperationHandler`, immediately before infrastructure adapter
resolution and `fence()` invocation. The handler first validates that the fence
operation still targets the current open Conference, active former binding,
former RuntimeNode, and configuration generation. It then calls
`TelephonyDomainService::hasDistinctEligibleReplacement(...)`.

The query reuses the canonical deterministic RuntimeNode selector and returns a
boolean only. A replacement must be in the same tenant, have `desired_state =
active`, have `observed_state = ready`, carry both `conference.lifecycle` and
`conference.participation`, and differ from the former RuntimeNode. Draining,
disabled, degraded, unavailable, stale, wrong-tenant, same-node, and
incomplete-capability candidates do not authorize fencing.

When the check fails, the operation returns retryable
`no_replacement_available`. The Kubernetes adapter is not called, no scale
request is attempted, no `conference.runtime_fence_terminated` evidence is
emitted, the existing RuntimeBinding remains active, and the Conference
configuration generation is unchanged. Later sweeps classify the condition as
`no_replacement` and can retry automatically once another RuntimeNode becomes
ready.

The replacement ID remains non-authoritative: it is not accepted in operation
payloads, is not sent to the infrastructure adapter, and is not persisted by this
guard. Final replacement authority remains inside
`failoverRebindConferenceAfterFence`, which reselects and validates a replacement
transactionally after valid absence or external-fence evidence.

This completes only the repository-side single-node safety guard. The production
Kubernetes client, dedicated infrastructure worker, RBAC, projected token,
RuntimeNode B, live scale-to-zero proof, and two-node failover acceptance remain
pending.

## T5-A6 repository client and worker staging

T5-A6 adds the repository components needed for future live Kubernetes fencing
without activating them in the current single-node environment.

`RuntimeOperationRepository::claimAvailable(...)` now supports database-level
operation-type include and exclude filters while preserving deterministic claim
ordering, leases, and PostgreSQL `FOR UPDATE SKIP LOCKED` behavior. The existing
generic `runtime-engine:command-worker` excludes
`runtime.node.runtime.fence`, and the new internal
`runtime-engine:infrastructure-worker {--once}` includes only that operation
type. This keeps normal runtime operations on the generic worker and reserves
Kubernetes mutation authority for a dedicated later-deployed worker identity.

`HttpKubernetesWorkloadClient` is the bounded production client behind the
existing `KubernetesWorkloadClient` interface. It implements only exact
Deployment GET, Deployment scale-subresource PATCH, and owned-Pod listing
requests. It uses the standard in-cluster API host and port plus the mounted
projected ServiceAccount token and `ca.crt`; TLS verification is mandatory. The
token file is read for each request so projected-token rotation is honored.
Missing host, token, CA material, connection failure, TLS failure, throttling, or
server errors fail closed as `unavailable_to_control`. Authorization failures map
to `permission_denied`, expected-workload 404 maps to `target_mismatch`, 409 maps
to retryable `fence_in_progress`, and malformed Kubernetes objects map to
`failed`. No HTTP or credential failure can produce `fenced` evidence.

A non-applied Kustomize component exists at
`infrastructure/kubernetes/components/runtime-fencing/`. It stages the
`utcp-runtime-fencer` ServiceAccount, a namespace-scoped Role/RoleBinding in
`utcp-runtime`, and a dedicated infrastructure worker Deployment using the same
application image and the `telephony-infrastructure-worker` entrypoint role. The
worker has `automountServiceAccountToken: false` and mounts one explicit
projected ServiceAccount token volume at the standard Kubernetes credential path.
The Role permits only `get/list` on Deployments, `get/patch` on
`deployments/scale`, and `get/list` on Pods; it grants no Pod deletion, full
Deployment patch, Secret read, wildcard, ClusterRole, Service, or cross-namespace
authority. EndpointSlice permissions are intentionally absent because the client
does not read EndpointSlices in this slice.

The active single-node overlay does not include the runtime-fencing component.
Live fencing remains inert because existing generic workers cannot claim
`runtime.node.runtime.fence`, and no current Pod receives the fencing token or
the staged ServiceAccount. When the component is deployed later, the
replacement-before-fence guard still executes immediately before infrastructure
mutation, and `failoverRebindConferenceAfterFence(...)` still validates verified
absence or external-fence evidence against the current binding and Conference
generation before any RuntimeBinding authority cutoff.

Safe deployment order remains: deploy and register a distinct ready RuntimeNode
B first; prove it is eligible; then apply the fencing ServiceAccount/RBAC and
dedicated worker identity; then run non-destructive connectivity proof before
any live scale-to-zero acceptance. Live RuntimeNode B registration, real RBAC
application, live Kubernetes-client connectivity, live scale-to-zero proof,
replacement reconstruction proof, former-workload restoration policy, and
two-node automatic failover acceptance remain pending.

## T5-A7 staged Asterisk A/B topology artifacts

Repository-only A/B manifests now live under the non-applied overlay
`infrastructure/kubernetes/overlays/local-two-asterisk/`. The overlay uses the
reusable `infrastructure/kubernetes/components/asterisk-instance/` model to
render two isolated Asterisk runtime workloads:

| RuntimeNode | Deployment | Service | Credential Secret reference | Service DNS | Workload identity |
| --- | --- | --- | --- | --- | --- |
| `local-asterisk-ari-a` | `asterisk-ari-a` | `asterisk-ari-a` | `utcp-local-asterisk-ari-a-credentials` | `asterisk-ari-a.utcp-runtime.svc.cluster.local:8088` | `utcp-runtime/asterisk-ari-a` |
| `local-asterisk-ari-b` | `asterisk-ari-b` | `asterisk-ari-b` | `utcp-local-asterisk-ari-b-credentials` | `asterisk-ari-b.utcp-runtime.svc.cluster.local:8088` | `utcp-runtime/asterisk-ari-b` |

Each staged Service selector includes the node-specific
`utcp.dev/runtime-node` label, so Service A cannot select node B and Service B
cannot select node A. The same label also gives the Kubernetes runtime-fence
ownership validator an exact Deployment target per RuntimeNode. The component
keeps the shared Asterisk image, readiness probe, liveness probe, runtime
ServiceAccount, security context, ARI port, and Stasis configuration aligned
with the current single-node runtime.

The active local single-node overlay remains separate and continues to render
the existing `asterisk-ari` Deployment, `asterisk-ari` Service,
`utcp-local-asterisk-ari-credentials` Secret reference, Service DNS, selectors,
image, probes, command, ports, security context, and `local-asterisk-ari`
RuntimeNode label. The staged A/B overlay is not included by the active overlay,
and it does not include the runtime-fencing worker component or the fencing
ServiceAccount token. Asterisk Pods remain infrastructure targets, not
Kubernetes controllers.

Canonical registration request definitions are staged at
`infrastructure/runtime-nodes/asterisk-ari/`. They use the existing
authenticated C2 RuntimeNode API sequence: create the RuntimeNode, add control,
events, and health endpoints, attach the ARI basic credential from the matching
Kubernetes Secret context, register capabilities, write the Asterisk ARI
adapter profile, and set desired state to `active`. The definitions advertise
only the required Asterisk capabilities for this topology:
`conference.lifecycle`, `conference.participation`, `event.stream`, and
`runtime.observation`. They carry trusted workload metadata in the existing
`labels.kubernetes_workload` shape and do not contain live credential material.

The existing ARI listener remains compatible with the staged topology because
RuntimeNode A and B are represented as distinct canonical RuntimeNodes with
distinct endpoints. The listener's per-node claim, lease, and event-source epoch
model can establish separate ARI WebSocket ownership for each Asterisk process.
Conference and participant runtime IDs remain node-local Asterisk resources; the
active RuntimeBinding remains the authority that selects which node's events can
project.

Validation is centralized in `scripts/asterisk-ari/validate-ab-topology` and is
called by `scripts/asterisk-ari/config-check`. The validator renders the active
overlay and the staged A/B overlay, then checks object identity, selector
isolation, credential separation, endpoint and workload alignment, required
capabilities, active-overlay preservation, fencing-token isolation, duplicate
registration rejection, and premature fencing-worker exclusion. Live node-B
Deployment, live Secret creation, authenticated RuntimeNode-B registration,
listener observed-ready proof, RBAC application, and failover acceptance remain
pending.

Repository validation rendered both `infrastructure/kubernetes/overlays/local/runtime`
and `infrastructure/kubernetes/overlays/local-two-asterisk/`. A non-mutating
server-side dry-run against `k3d-utcp-local` accepted the staged A/B resources as
`asterisk-ari-a` and `asterisk-ari-b` Services and Deployments. No resource was
applied.

## Ready-to-paste Codex prompt (T5-A5)

```
# T5-A5 — Replacement-before-fence guard at the runtime-fence mutation boundary

Repository-only slice at HEAD 98c0489. Keep UTCP_PHASE=T1. Do NOT add the real
Kubernetes client, apply RBAC, mount tokens, deploy a second node, scale any
Deployment, or run live failover.

Problem (docs/evidence/t2/multi-node-failover-readiness.md §T5-A5):
RuntimeFenceOperationHandler scales the former Asterisk Deployment to zero with NO
check that a distinct eligible replacement RuntimeNode exists. Replacement is only
validated later in failoverRebindConferenceAfterFence. Once the real Kubernetes
client is wired, a single-node fence would terminate node A then fail to rebind,
leaving the Conference with no runtime. Add a mutation-time guard so the
destructive scale never starts without a distinct ready replacement.

1. TelephonyDomainService: add
   hasDistinctEligibleReplacement(string $tenantId, string $conferenceId,
   string $formerRuntimeNodeId): bool
   that returns true iff selectRuntimeNodeForConference(tenantId,
   [conference.lifecycle, conference.participation], excludeRuntimeNodeId:
   formerRuntimeNodeId, desiredStates: ['active']) yields a distinct
   observed-ready node; catch the 422 (No eligible runtime node) and return false.
   Read-only; no locks beyond the query; do NOT persist or return the replacement
   id (it must not become a binding authority).

2. RuntimeFenceOperationHandler::execute: immediately BEFORE calling
   $infra->fence($node, $payload), call hasDistinctEligibleReplacement for the
   operation's tenant, conference_id, and former_runtime_node_id. If false, return
   a retryable failure with FailureClass::RuntimeUnavailable and failure_code
   'no_replacement_available' (message: replacement not available for runtime
   fence) — this blocks the scale mutation and yields no fenced evidence. Do not
   change the adapter, the coordinator, or failoverRebindConferenceAfterFence.

3. RuntimeFencingCoordinator: map a runtime.node.runtime.fence operation that
   terminal/retry-fails with 'no_replacement_available' to a 'no_replacement'
   coordinator outcome (reuse the existing no-replacement classification) so the
   sweep records it and retries without rebinding.

4. Tests (tests/Feature/Infrastructure/ or TelephonyDomain/):
   - fence handler with a former node and NO distinct ready replacement present ->
     execute returns the no_replacement_available failure AND the infrastructure
     adapter fence() is never invoked (use a spy/fake adapter asserting zero scale
     calls).
   - fence handler with a distinct ready replacement present -> execute proceeds to
     the adapter (fake adapter returns fenced) and completes.
   - hasDistinctEligibleReplacement true/false cases (distinct ready node exists;
     only the former node exists; replacement not ready; replacement lacks a
     capability).
   - coordinator maps no_replacement_available to no_replacement (no binding
     change).

5. Update docs/evidence/t2/multi-node-failover-readiness.md §T5-A5 with an
   "implemented" note: the mutation-time guard is in place; production client,
   RBAC, dedicated worker/token, node-B manifests, and live proof still pending.

Verification (all must pass):
  make repository-hygiene && make secret-scan
  make runtime-engine-config-check && make telephony-domain-config-check
  make asterisk-ari-config-check && make asterisk-conference-config-check
  make runtime-engine-test && make telephony-domain-test
  make asterisk-ari-test && make asterisk-conference-recovery-test
  make test && make check && make build
  git diff --check

One scoped commit, e.g.:
  feat(t5): require a distinct replacement before runtime-fence scale mutation
Do not push. Do not add the real Kubernetes client, apply RBAC, mount tokens,
deploy a second node, or run live scale/failover.
```

## T5-A5 staged acceptance criteria

**Repository acceptance:** the fence handler never invokes the adapter scale when
no distinct ready replacement exists (returns retryable `no_replacement_available`);
`hasDistinctEligibleReplacement` uses canonical selection semantics; the coordinator
maps the outcome to `no_replacement` with no binding change; the replacement id is
never persisted or passed to the adapter; single-node behaviour is provably safe;
focused tests green; no client/RBAC/token/node-B/live scale.

**Live acceptance (later, layered, in Order 3):** land the production
`HttpKubernetesWorkloadClient` + dedicated infra worker + narrow `utcp-runtime`
RBAC + projected token (client still default-Unavailable until bound); deploy node
B via the Kustomize component and register RuntimeNode B to observed `ready`; then
bind the real client and prove one live scale-to-zero fence of a hard-failed node A
followed by a single rebind + reconstruction on node B, with the guard proven to
block fencing whenever node B is not ready; both-node orphan inspection clean;
former node-A workload restored later without reclaiming.

---

# T5-A8 — Node-B Live Rollout, Existing-Node Identity, and Horizontal-Scale Readiness

Evidence-only audit at commit `05fcecb`, `UTCP_PHASE=T1`, 2026-07-18. Resolves the
safe transition from the live single-Asterisk topology to the first live
two-RuntimeNode topology. Nothing applied, registered, scaled, or restarted.

## Current live Asterisk identity

| Concern | Live value |
|---|---|
| Deployment / Service | `asterisk-ari` / `asterisk-ari` (ns `utcp-runtime`, ClusterIP `10.43.29.130:8088`, 1/1) |
| Pod labels / selector | `part-of=utcp`, `component=asterisk-ari`, `utcp.dev/runtime-node=local-asterisk-ari`, `utcp.io/network-role=asterisk-ari` |
| Secret | `utcp-local-asterisk-ari-credentials` |
| RuntimeNode id / slug | `1d15ca88-1f74-4192-ae36-4eb2b6ef9a3c` / `local-asterisk-ari` |
| desired / observed | `active` / `ready` |
| adapter_key / family | `asterisk-ari` / `asterisk` |
| capabilities | `conference.lifecycle`, `conference.participation`, `event.stream`, `runtime.observation` |
| endpoints | control/events/health → `asterisk-ari.utcp-runtime.svc.cluster.local:8088` |
| credential | `ari-basic` `utcp_ari`, active v3 (v1/v2 retired) |
| listener lease / event source | `asterisk-ari-events`, `claimed`, owned |
| Stasis app | `utcp-t0-observation` |
| **`labels`** | **`{"purpose":"t0-proof"}` — NO `kubernetes_workload`** |

## Staged node-A identity

The `local-two-asterisk` overlay's `node-a` renders a **fresh** `asterisk-ari-a`
Deployment + Service, `utcp.dev/runtime-node=local-asterisk-ari-a`, secretRef
`utcp-local-asterisk-ari-a-credentials`; its registration artifact declares slug
`local-asterisk-ari-a` and `kubernetes_workload.deployment=asterisk-ari-a`. This is
a **distinct** workload and RuntimeNode from the live `asterisk-ari`/`local-asterisk-ari`
— not a rename of it. Deploying staged node-A would create a **third** Asterisk and
a duplicate conceptual first node.

## Selected first live two-node topology

**Option A — keep the existing `asterisk-ari`/`local-asterisk-ari` as the first
live node and deploy only staged node B (`asterisk-ari-b`/`local-asterisk-ari-b`).**
The live node already fulfills the logical node-A role (active, ready, both
conference capabilities, claimed lease). Rendering the `node-b` subtree produces
only `Deployment/asterisk-ari-b` + `Service/asterisk-ari-b` (no namespace/SA — those
live in the parent overlay), fully isolated from the live `asterisk-ari`; the
reusable component renders B independently. Rejected: B (three processes, duplicate
first-node identity, no proof needs it); C (renaming the live node changes Service
DNS, RuntimeNode identity, listener/event-source, and endangers the active binding
for zero benefit).

## Existing-node transition decision

No rename, no rollout, no re-registration of the live node. It stays exactly as-is
and becomes "node A" logically. The only change it needs is the additive
`kubernetes_workload` label (below), applied through the canonical API — not a
workload change.

## Required repository adjustment

**Two bounded repository corrections are required before live rollout — this is
the decisive finding.**

1. **Structured-label rejection (blocker).** `RuntimeRegistryService::validatedLabels`
   (line 538) requires **every label value to be a string** (`! is_string($value)`
   → `InvalidArgumentException('Invalid placement label.')`). But the
   `kubernetes_workload` label that the fence adapter's
   `RuntimeNodeWorkloadIdentityResolver` reads — and that **every** node-A/B
   `*.registration.json` provides — is a nested object
   `{"namespace","deployment"}`. Therefore **`POST /api/v1/admin/runtime-nodes` (and
   `PATCH`) would reject the staged node-B payload**, and would equally reject
   adding `kubernetes_workload` to the existing live node. `AsteriskTwoNodeTopologyTest`
   only asserts the JSON artifact shape; it never posts it through the API, so this
   gap is untested. The registry must accept the structured `kubernetes_workload`
   label (validate namespace+deployment as DNS labels, namespace = canonical
   `utcp-runtime`) while keeping all other labels flat strings.
2. **Existing-node workload identity (blocker for fencing).** The live RuntimeNode's
   `labels` = `{"purpose":"t0-proof"}` has no `kubernetes_workload`. Until (1) lands
   and the label is added via `PATCH /runtime-nodes/{id}`, the fence adapter cannot
   resolve the live node's workload (it would return `target_mismatch`). This does
   not block node-B *readiness*, but it blocks ever fencing the existing node.

A dedicated **`local-add-asterisk-b` incremental overlay** is **not strictly
required** — the existing `overlays/local-two-asterisk/node-b` subtree already
renders exactly the node-B-only resources and can be applied against the live
namespace without disturbing `asterisk-ari`. A thin convenience overlay wrapping
`node-b` + a node-B `secretGenerator` (the two-asterisk overlay has **no**
secretGenerator — credentials Secrets must be created separately) would be a
nice-to-have, not a blocker.

## Canonical node-B registration sequence

Several authenticated C2 requests (all routes confirmed present in `routes/web.php`),
per the `local-asterisk-ari-b.registration.json` artifact, in order:
`POST /runtime-nodes` (create, with labels incl. `kubernetes_workload`) →
`POST /runtime-nodes/{id}/endpoints` ×3 (control/events/health →
`asterisk-ari-b.utcp-runtime.svc.cluster.local`) →
`POST /runtime-nodes/{id}/credentials` (ari-basic, identifier + secret) →
`PUT /runtime-nodes/{id}/capabilities` (the four caps) →
`PUT /runtime-nodes/{id}/adapter-configuration` (Stasis app + timeouts) →
`POST /runtime-nodes/{id}/desired-state` (`active`). Answers: it is **several**
requests, not one; the **credentials** request creates the canonical write-only
encrypted credential; the artifact carries a **placeholder secret + a
`kubernetes_secret` reference** (no real plaintext); each request is idempotent
(per-request `idempotency_key`); a duplicate slug returns the existing node via the
create idempotency fingerprint; a re-run resolves rather than duplicates; a partial
failure is re-driven by re-running the remaining idempotent requests (reconciled,
not auto-rolled-back); the authenticated actor is a platform admin session with
`runtime.nodes.manage`/`runtime.credentials.rotate`. **No SQL or seeding.**

## Credential authority and projection

Two deterministic projections of **one** ARI username/password: (a) the canonical
write-only encrypted credential record (`runtime_node_credentials`, created via
`POST …/credentials`, fingerprinted, rot/retire supported) that the ARI adapter
uses to authenticate; and (b) the Kubernetes Secret
`utcp-local-asterisk-ari-b-credentials` (env `secretRef`) that configures Asterisk's
own ARI user. Non-secret inputs: Secret name + keys (ARI username/password env),
the ARI username (`identifier`). The two copies must be generated from the same
material to avoid drift; the canonical authority is the C2 credentials API + the
operator-supplied Secret, generated together by the deployment tooling. No credential
projection automation exists linking the Secret to the record — they are created in
the same rollout step. Real credentials are **not** generated in this audit.

## Listener multi-node readiness

Proven in prior audits and re-confirmed: `AsteriskAriEventListener::eligibleNodes()`
selects **all** `adapter_key='asterisk-ari'` active nodes and maintains a per-node
`connections` map; leases and event epochs are keyed **per RuntimeNode / event
source**; `asterisk-ari:ensure-targets` targets every such node. So one listener
process connects to `local-asterisk-ari` **and** `local-asterisk-ari-b`
simultaneously; one node's reconnect cannot steal another's lease (lease keyed by
event source); epochs and health observations are node-specific; the same Stasis
app name is safe across separate processes (per-instance, verified T5-A3/A4); the
listener assumes no fixed count. **No listener restart is required** after canonical
node-B registration — the running listener discovers the new eligible node on its
next sweep and claims it.

## Node-B replacement eligibility

Node B becomes selectable when `desired_state=active` AND `observed_state=ready` AND
both `conference.lifecycle`+`conference.participation` present AND it is distinct
from the former node. Timeline: registration (`desired_state=active`) → listener
claim + ARI/Stasis health → `runtime_info_observed` → projector writes
`observed_state=ready` → `selectRuntimeNodeForConference` (orders all eligible by
`runtime_family,adapter_key,id`, `.first()`, exclude-former) sees it. The
per-minute `asterisk-ari:ensure-targets`/reconciler sweeps drive readiness; **no
manual reconciliation command is required**.

## Existing-node workload identity

**Missing.** The live node's `labels` lack `kubernetes_workload`, and the live
Deployment does carry the ownership labels the adapter checks
(`part-of=utcp`, `component=asterisk-ari`, `utcp.dev/runtime-node=local-asterisk-ari`).
Correction (after the structured-label fix): `PATCH /api/v1/admin/runtime-nodes/{id}`
with `labels` merged to include
`{"kubernetes_workload":{"namespace":"utcp-runtime","deployment":"asterisk-ari"}}`
plus the existing `purpose`. Canonical API, no schema, no SQL, not mutated in this
audit. Not inferred from the endpoint hostname (per rule).

## Horizontal-scale assessment

**N-node clean.** Canonical selection (`selectRuntimeNodeForConference` — orderBy +
`.first()` over all eligible), listener (`eligibleNodes` iterates all), registration
API, and fence resolver (reads each node's own `kubernetes_workload`) carry **no
fixed-two-node assumption** (scan of `apps/api/app` found only unrelated byte-offset
arithmetic and event-type strings). Adding node C requires only: a new isolated
workload (component invocation with suffix `-c`), a new canonical RuntimeNode
registration, and a new credential identity — **no control-plane code change**. The
A/B naming lives only in proof artifacts (`validate-ab-topology`, the
`local-two-asterisk` overlay, `AsteriskTwoNodeTopologyTest`, the two registration
JSONs) — acceptable proof-specific scope. The reusable `components/asterisk-instance`
instantiates node C via a new overlay dir (nameSuffix `-c` + runtime-node patch)
without copying implementation or editing canonical code.

## Fixed-two-node assumptions

Acceptable (proof-specific): `validate-ab-topology`, the `local-two-asterisk`
overlay `node-a`/`node-b`, `AsteriskTwoNodeTopologyTest`, the two `*.registration.json`.
Unacceptable platform assumptions: **none found** — the selector, listener,
registration API, fence resolver, and status tooling are all node-count-agnostic.

## Non-destructive validation

1. `kubectl kustomize infrastructure/kubernetes/overlays/local-two-asterisk/node-b`
   (renders `asterisk-ari-b` only). 2. `kubectl apply --dry-run=server -f -` piped
   from that render (v1.35 GA; validates against the live API + admission, no
   persist). 3. Secret-name collision: assert `utcp-local-asterisk-ari-b-credentials`
   ∉ existing `utcp-runtime` Secrets. 4. Service-selector: node-B Service selects
   only `utcp.dev/runtime-node=local-asterisk-ari-b`. 5. Endpoint DNS: node-B endpoints
   resolve to `asterisk-ari-b.utcp-runtime.svc.cluster.local`. 6. Registration-payload
   schema validation (the `utcp.runtime-node-registration.v1` shape). 7. Duplicate
   RuntimeNode: `GET /runtime-nodes` shows no `local-asterisk-ari-b`. 8. Listener
   eligibility (read-only DB: node not yet present). 9. Workload-identity match
   (node-B `kubernetes_workload.deployment` == rendered Deployment name). 10.
   Replacement-selector read-only proof (query returns the live node today; would
   return B once ready). No apply, no real credential.

## Checkpointed live rollout (later, not executed)

1. Render + server-side dry-run node-B resources (rollback: none — read-only). 2.
Create node-B credential material through canonical tooling (rollback: retire the
credential). 3. Create the node-B Kubernetes Secret (rollback: delete the Secret).
4. Apply node-B Deployment + Service (rollback: delete both; live `asterisk-ari`
untouched). 5. Wait for Pod + Service readiness (rollback: delete node-B workload).
6. Register node B through the C2 API — create/endpoints/credential/capabilities/
adapter-config/desired-state (rollback: `desired_state=disabled`, retire credential).
7. Wait for listener claim + ARI/Stasis readiness. 8. Prove node B `observed_state=ready`
+ capability-complete. 9. Prove the replacement selector sees a distinct eligible
node. 10. **Only afterward** deploy fencing RBAC + the infrastructure worker + token
(the last, separate step that activates live fencing). Registration should occur
**after** the Deployment/Service are reachable (step 6 after step 5) so the listener
can immediately claim and prove ARI/Stasis health; registering before reachability
would leave the node `active` but never `ready`.

## Rollback plan

Every checkpoint is independently reversible without touching the live node:
pre-apply steps are read-only; Secret/Deployment/Service are deletable; registration
is reversible via `desired_state=disabled` + credential retire (the listener releases
the lease and the node drops out of eligibility). The live `asterisk-ari` node and
all active RuntimeBindings are never modified at any checkpoint.

## Live readiness acceptance

Later node-B readiness proof requires: live node still healthy; node-B Deployment
ready; node-B Service has only the node-B endpoint; node-B ARI endpoint authenticated;
node-B listener lease owned; node-B event epoch created; node-B Stasis app active;
node-B `observed_state=ready`; both conference capabilities present; the replacement
selector identifies a distinct eligible node; zero active proof Conference resources;
**no fencing worker active yet**. Failover is **not** required in the node-B readiness
proof.

## Provider Node management alignment

All node-B lifecycle (create, endpoints, credentials, capabilities, adapter config,
desired state, workload-identity label, health/readiness) flows through the C2
`/api/v1/admin/runtime-nodes` API — the same authority a future **Provider Node Admin
UI** would call. The registration JSON artifacts are **declarative API request
descriptions**, not a competing authority; the deployment tooling is a trusted API
client. No artifact writes state directly. The UI is not implemented here.

## T5-A8 existing implementation

Reusable `components/asterisk-instance`; `local-two-asterisk` overlay (node-a/node-b);
node-A/node-B registration JSON artifacts (canonical API request descriptions with
`kubernetes_workload`); `validate-ab-topology`; multi-node listener/selector/registration
(N-node clean); fence adapter + resolver reading per-node `kubernetes_workload`;
`AsteriskTwoNodeTopologyTest` (artifact-shape validation).

## T5-A8 missing implementation at audit time

(1) Registry acceptance of the structured `kubernetes_workload` label (blocker —
the canonical API currently rejects it); (2) the existing live node's
`kubernetes_workload` label via canonical `PATCH` (blocked by #1); then the live
rollout itself: node-B Secret + Deployment + Service, canonical node-B registration,
listener-claim/observed-ready proof, and later the fencing RBAC/worker/token.

## T5-A9 repository implementation

The string-only RuntimeNode label mismatch has been corrected at the canonical
registry boundary. `RuntimeRegistryService` now preserves the flat string contract
for ordinary labels while accepting the single reserved structured label
`kubernetes_workload`.

The accepted workload shape is exactly:

```json
{
  "namespace": "utcp-runtime",
  "deployment": "asterisk-ari-b"
}
```

The writer and reader share `RuntimeNodeWorkloadIdentityValidator`, so the
RuntimeNode create/update APIs and `RuntimeNodeWorkloadIdentityResolver` agree on
the canonical namespace restriction and Kubernetes Deployment DNS-label validation.
`utcp-runtime` is the only accepted namespace; malformed deployment names, extra
workload keys, missing fields, non-string values, and arbitrary nested nonreserved
labels are rejected. No schema change or second workload-reference authority was
introduced.

Focused API tests prove create and update support, JSON persistence, API response
round-trip, resolver round-trip from persisted data, flat-label compatibility, and
malformed structured-label rejection. The node-A and node-B registration artifacts
validate against the corrected canonical API contract.

Live state was not changed in this repository slice. The existing live RuntimeNode
still requires a later authenticated canonical `PATCH` to add its workload identity,
and node-B Secret creation, Deployment/Service rollout, RuntimeNode registration,
listener readiness proof, and fencing-worker activation remain pending.

## T5-A8 implementation-readiness decision

**A — Codex repository implementation is required before live rollout.** The
canonical registration API cannot accept the structured `kubernetes_workload` label
that every registration artifact and the fence resolver require, so neither node B
nor the existing-node workload-identity correction can be registered canonically
until the registry label validation is generalized. This is a small, unambiguous,
repository-testable correction and is the earliest dependency.

## T5-A8 next bounded task (completed by T5-A9)

Generalize `RuntimeRegistryService` label validation to accept the structured
`kubernetes_workload` object (namespace + deployment as DNS-1123 labels, namespace
constrained to the canonical runtime namespace) alongside flat string labels, on
both create and update, and prove via an API-level test that a node registered with
`kubernetes_workload` round-trips and resolves through `RuntimeNodeWorkloadIdentityResolver`.

## Ready-to-paste next prompt used for T5-A9

```
# T5-A9 — Accept structured kubernetes_workload RuntimeNode label in the canonical registry

Repository-only slice at HEAD 05fcecb. Keep UTCP_PHASE=T1. Do NOT deploy node B,
create Secrets, register RuntimeNodes live, apply RBAC, or run live failover.

Problem (docs/evidence/t2/multi-node-failover-readiness.md §T5-A8):
RuntimeRegistryService::validatedLabels rejects any label value that is not a
string, but the Kubernetes fence adapter's RuntimeNodeWorkloadIdentityResolver
reads a nested labels.kubernetes_workload = {namespace, deployment}, and every
infrastructure/runtime-nodes/asterisk-ari/*.registration.json provides it as a
nested object. So POST/PATCH /api/v1/admin/runtime-nodes would reject the staged
node-B payload and cannot add kubernetes_workload to the existing live node. Fix
the registry to accept this one structured label while keeping all others flat.

1. RuntimeRegistryService::validatedLabels: keep the existing flat-string rule for
   every label key EXCEPT the reserved key 'kubernetes_workload'. For that key,
   require an array with exactly 'namespace' and 'deployment' string values, each
   matching DNS-1123 label rules (^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$), and
   require namespace === the canonical runtime namespace constant used by
   RuntimeNodeWorkloadIdentityResolver (utcp-runtime). Reject any other nested
   value. Preserve JSON encoding of the full labels object (the resolver already
   decodes labels.kubernetes_workload as a nested array).

2. Confirm createNode and updateNode both route through the generalized validator
   (they already call validatedLabels), so create and PATCH both accept the
   structured label. Do not change the runtime_nodes schema (labels stays JSON).

3. Do not change RuntimeNodeWorkloadIdentityResolver, the fence adapter, the
   controller validation rules beyond what is needed to pass labels through, or
   any manifest.

4. Tests (tests/Feature/RuntimeRegistry/ and/or Infrastructure/):
   - POST /api/v1/admin/runtime-nodes with labels.kubernetes_workload
     {namespace: utcp-runtime, deployment: asterisk-ari-b} succeeds (201) and the
     stored node's labels round-trip the nested object via GET.
   - the same value resolves through RuntimeNodeWorkloadIdentityResolver to
     namespace=utcp-runtime, deployment=asterisk-ari-b.
   - PATCH adds kubernetes_workload to an existing node whose labels previously had
     only flat string labels (e.g. purpose), preserving the flat label.
   - rejection cases: kubernetes_workload with a non-utcp-runtime namespace; with a
     malformed DNS deployment; with extra keys; a non-kubernetes_workload label with
     a nested/object value still rejected.
   - a flat string label (e.g. purpose=t0-proof) still works unchanged.

5. Update docs/evidence/t2/multi-node-failover-readiness.md §T5-A8 with an
   "implemented" note: the registry now accepts kubernetes_workload; live node-B
   rollout and the existing-node label PATCH can proceed as the next Claude Code
   step.

Verification (all must pass):
  make repository-hygiene && make secret-scan
  make runtime-engine-config-check && make telephony-domain-config-check
  make asterisk-ari-config-check && make asterisk-conference-config-check
  make runtime-engine-test && make telephony-domain-test
  make asterisk-ari-test && make asterisk-conference-recovery-test
  make test && make check && make build
  git diff --check

One scoped commit, e.g.:
  feat(t5): accept structured kubernetes_workload RuntimeNode label
Do not push. Do not deploy node B, apply manifests, register nodes live, or run
live failover.
```

## T5-A8 staged acceptance criteria

**Repository acceptance (T5-A9):** the canonical create/update API accepts
`labels.kubernetes_workload = {namespace, deployment}` (DNS-validated, namespace =
utcp-runtime) and rejects malformed/foreign-namespace/other nested labels; the value
round-trips and resolves through the fence identity resolver; flat labels unchanged;
no schema change; focused tests green.

**Live acceptance (later Claude Code, Option A topology):** PATCH the existing live
node to add its `kubernetes_workload`; render + server-side dry-run node-B; create the
node-B Secret + credential; apply node-B Deployment/Service; register node B through
the C2 API; prove listener claim + `observed_state=ready` + capability-complete +
distinct-replacement-selectable, with the live node undisturbed and no fencing worker
active. Fencing RBAC/worker/token and two-node failover remain separate later slices.

---

# T5-A10 — Live node-B deployment and runtime readiness (executed)

Live checkpointed implementation at commit `8d7db56`, `UTCP_PHASE=T1`,
2026-07-18. UTCP moved from one live Asterisk RuntimeNode to **two ready
RuntimeNodes**, stopping before Kubernetes fencing authority. Node A preserved
without rename/restart; fencing worker/RBAC never deployed. Two small production
corrections were required (Service selector isolation) and are committed.

## Deployment currency prerequisite

The running `api` pod predated the T5-A9 structured-label fix (the
`RuntimeNodeWorkloadIdentityValidator` class was absent; `validatedLabels`
rejected the nested `kubernetes_workload` object), so the first PATCH returned
`422 Invalid placement label`. The committed image was built/pushed and **only
`deploy/api` was rollout-restarted** (registration is HTTP-served by api; the
listener's multi-node code was already deployed). No worker/listener/Asterisk
restart. After rollout the validator class and label acceptance were live.

## Existing RuntimeNode workload PATCH

`PATCH /api/v1/admin/runtime-nodes/{id}` (canonical, authenticated) merged the
complete label set `{"purpose":"t0-proof","kubernetes_workload":{"namespace":
"utcp-runtime","deployment":"asterisk-ari"}}` → 200. Re-read confirmed: id/slug
unchanged, desired `active`/observed `ready` unchanged, `purpose` preserved,
`kubernetes_workload` added, 3 endpoints + 4 capabilities + active credential v3
all intact, listener lease still claimed, 76 active bindings unchanged.
`RuntimeNodeWorkloadIdentityResolver` now resolves the existing node to
`namespace=utcp-runtime deployment=asterisk-ari`.

## Service-selector isolation defect (found and fixed)

Applying node B exposed a latent defect: the base `asterisk-ari` **Service**
selector was `{part-of, component}` — it lacked the `utcp.dev/runtime-node`
label the base **Deployment** already carried. With a second `component=asterisk-ari`
pod present, node A's Service load-balanced across **both** pods, and the listener
immediately logged `authentication_failed` (node-A ARI inspects round-robined to
node B, which only knows `utcp_ari_b`). Two corrections, both committed:

1. **Repository:** added `utcp.dev/runtime-node: local-asterisk-ari` to the base
   `asterisk-ari-service.yaml` selector (mirrors the base Deployment; overlays
   already patch it per node). Updated `validate-ab-topology` to expect the
   3-label active selector.
2. **Live (non-disruptive, no restart):** labeled the single node-A pod
   `utcp.dev/runtime-node=local-asterisk-ari` (additive `kubectl label`, 0
   restarts) and merge-patched the live node-A Service selector to include it.
   EndpointSlices then isolated cleanly (node-A Service → node-A pod only; node-B
   Service → node-B pod only); node-A auth failures cleared and it stayed `ready`.

Residual: the live node-A **Deployment** template/selector still lack the label
(its selector is immutable at 2 labels; the committed 3-label-selector Deployment
was never applied). The live pod-label + Service-selector patch achieves isolation
now, but a **controlled node-A Deployment recreation** (restart, out of scope here)
is needed to make the label durable in the template. Documented as a remaining gap.

## Node-B credential handling

A distinct ARI username `utcp_ari_b` and a 48-hex-char password were generated
with `openssl rand` into a `0600` temp file, never printed or committed
(password fingerprint `0e33509255a8…`). The **same** material fed both projections:
the Kubernetes Secret (`kubectl create secret --from-env-file`) and the canonical
encrypted credential record (`POST …/credentials`). The canonical credential's
stored fingerprint (`0e33509255a8`) matches the generated password fingerprint,
and the Secret's `ARI_USERNAME` (`utcp_ari_b`) matches the canonical `identifier`
— one credential value, two deterministic projections, no drift. Temp file shredded
at completion.

## Node-B Kubernetes Secret / Deployment / Service

Secret `utcp-local-asterisk-ari-b-credentials` (Opaque, keys `ARI_USERNAME`/
`ARI_PASSWORD`), referenced only by node B; node A's Secret untouched. Node-B
Deployment + Service rendered from the committed `overlays/local-two-asterisk/node-b`
subtree (with the registry image transform applied), server-side dry-run passed,
then applied: `asterisk-ari-b` 1/1 Ready, SA `utcp-runtime-asterisk`,
`automountServiceAccountToken=false` (no fencing token), readiness script passed,
`Local` channel driver loaded, zero module-loader errors. Node A stayed 1/1.

## Canonical RuntimeNode-B registration

Idempotent authenticated sequence (per-request idempotency keys): create node
(`local-asterisk-ari-b`, family `asterisk`, adapter `asterisk-ari`, workload label)
→ 3 endpoints (control/events/health → `asterisk-ari-b.utcp-runtime.svc.cluster.local:8088`)
→ ari-basic credential → capabilities (all four) → adapter configuration
(`utcp-t0-observation` + timeouts) → desired-state `active`. All 2xx. No SQL, no
seeding, no management command.

## Listener claim, epochs, ARI/Stasis, observed-ready

No listener restart. Within the first sweep (~5 s) the running `asterisk-ari-events`
listener discovered node B, claimed a **distinct** per-node lease (fencing token
`e7f036e2` vs node A `a5a4d4d4`), opened an independent event epoch (`d0e375b2`),
authenticated ARI (info endpoint 200), and registered the Stasis app
`utcp-t0-observation` on node B's process (active independently of node A's
same-named app). Node B projected `observed_state=ready`; node A's lease, epochs,
and health remained unchanged (one node's evidence never overwrote the other's).

## Observed-ready evidence

RuntimeNode B: id `05ddb383…`, slug `local-asterisk-ari-b`, desired `active`,
observed `ready`; endpoints on `asterisk-ari-b.svc`; credential active v1
(fingerprint `0e33509255a8`); capabilities lifecycle/participation/event.stream/
observation; workload `{utcp-runtime, asterisk-ari-b}`; lease claimed; open event
epoch; readiness observations flowing; Pod `asterisk-ari-b-589c94c588-mnv2t`.

## Replacement eligibility and horizontal scale

Both nodes are same-tenant, `active`, `ready`, and carry both conference
capabilities — so for a conference bound to `local-asterisk-ari`,
`local-asterisk-ari-b` is a **distinct eligible replacement** (proven read-only;
no binding/conference/failover created). Registry, listener eligibility, status
tooling (`asterisk_desired_active=2`), and the workload resolver all enumerate
**both** nodes generically. Node C would need only a new isolated workload + a new
credential + a canonical registration — no control-plane code change.

## Fencing inactivity

Zero fencing-worker Deployments, zero fencing RBAC objects (SA/Role/RoleBinding),
zero `runtime.node.runtime.fence` operations ever, no projected fencing token on
any utcp-runtime/utcp-platform pod (all `automount=false`; the only `automount=true`
pods are unrelated traefik/observability system pods). No Deployment scaled, no Pod
deleted, existing RuntimeBinding authority unchanged. The generic command worker
does not execute fence operations, and the real Kubernetes client is inert without
the (undeployed) dedicated worker + token + RBAC.

## Rollback performed

None — every checkpoint succeeded. (Rollback would have been node-B-scoped:
desired-state disabled → retire credential → delete Deployment/Service/Secret,
node A untouched.)

## T5-A10 committed corrections

`infrastructure/kubernetes/base/runtime/asterisk-ari-service.yaml` (add
runtime-node label to the Service selector) and `scripts/asterisk-ari/validate-ab-topology`
(expect the 3-label active selector). No application code changed.

## T5-A10 remaining gaps

Durable node-A Deployment template/selector alignment (needs a controlled node-A
recreation — restart, deferred); then fencing RBAC + dedicated worker + token
deployment, live Kubernetes-client connectivity proof, real scale-to-zero proof,
two-node automatic failover, replacement reconstruction, former-workload
restoration, Provider Node Admin UI, replacement-node failure handling, metrics,
and capacity/placement policy.

---

# T5-A11 — Node-A durable isolation and fencing-activation safety (audit)

Evidence-only audit at commit `571b703`, `UTCP_PHASE=T1`, 2026-07-18. Resolves
node-A Service-isolation durability and node-A rollout safety, and defines the
non-destructive fencing-worker activation contract. Nothing mutated.

## Node-A label matrix (live vs repository)

| Object | `utcp.dev/runtime-node=local-asterisk-ari` present? |
|---|---|
| **Live** Deployment metadata labels | no |
| **Live** Deployment `selector.matchLabels` | **no** (2-label: part-of + component) |
| **Live** Deployment template labels | **no** |
| **Live** ReplicaSet (`asterisk-ari-874cf868c`) template labels | **no** |
| **Live** Pod (`…-hqnsk`) labels | **yes** (added manually in T5-A10) |
| **Live** Service selector | **yes** (patched in T5-A10) |
| **Committed** base Deployment selector + template | **yes** (3-label) |
| **Committed** base Service selector | **yes** (fixed in T5-A10) |

## Pod-recreation behavior

**Isolation is NOT durable.** The live Pod carries the runtime-node label only
because it was manually labeled; the live Deployment/ReplicaSet **templates lack
it**. If the node-A Pod is recreated (eviction, node drain, liveness failure), the
replacement is minted from the template **without** the label, so the node-A
Service (whose selector requires it) would select **zero** pods → node A's ARI
Service becomes unreachable → node A goes unavailable. The fence adapter's
ownership check (`part-of=utcp` + `component=asterisk-ari` +
`utcp.dev/runtime-node==slug`) would also fail against a recreated Pod's
Deployment (which still lacks the template label).

## Durable correction decision

**C — immutable selector mismatch requires controlled Deployment recreation.** The
committed base Deployment carries a **3-label immutable `selector.matchLabels`**
(`part-of, component, utcp.dev/runtime-node`); the live Deployment's selector is
the 2-label version. A Deployment `.spec.selector` is immutable, so
`kubectl apply` of the committed manifest fails with an immutable-field error — the
live Deployment cannot be reconciled in place. The durable fix is a one-time
`kubectl replace --force` (delete + recreate) of the node-A Deployment from the
committed manifest, which recreates it with the correct selector **and** template
label, so all future Pods inherit the label and Service isolation survives
recreation. This is a **live operation, not a repository change** — the manifests
are already correct.

## Active RuntimeBinding classification

76 active bindings → 76 distinct conferences → **all `desired_state=closed` /
`observed_state=closed`**. Category breakdown:

| Category | Count |
|---|---|
| open with live runtime resources | 0 |
| open without runtime resources | 0 |
| **closed but active binding remains (historical/stale)** | **76** |
| unknown | 0 |

Every binding is the known "binding not retired on conference close" residue
(documented earlier). Zero admitted participants, zero open conference operations,
and **zero open conferences exist anywhere** in the deployment.

## Live bridge and channel inventory

Read-only ARI inspection of node A: **0 bridges, 0 active channels, 0 active
calls** (3 calls processed historically). No Stasis-controlled or Local channels,
no orphan runtime resources.

## Binding-to-runtime correlation

Of 76 active bindings: **0** correspond to a live bridge, **0** to a live
participant channel, **76** have no live Asterisk state. A node-A Pod restart
would destroy **no material active runtime state**. Reconstruction is not even
required (nothing to reconstruct), and same-node restart recovery is independently
proven (T2-B `asterisk_restart_recovery`).

## Node-A rollout safety

**A — node A can be rolled safely now.** It hosts no live bridges/channels, has no
open conference or admitted participant, no open operations, and the 76 historical
bindings are untouched by a restart. With **zero open conferences**, the failover
candidate query (which requires `conferences.desired_state='open'`) can produce no
candidate, so a transient node-A unavailability during the recreation cannot
trigger failover — even if the fence worker were already active (which it is not).
The controlled `kubectl replace --force` durable correction is therefore safe to
perform now, and should be done **before** fencing activation while there are no
open conferences.

## Immediate fencing-trigger risk

**None currently.** `ConferenceFailoverCoordinator` sweeps `everyMinute`, requires
a bound node observed `unavailable`/`stale` with no `ready` observation for
`stale_observation_seconds=300`, **and** `conferences.desired_state='open'`
(coordinator line 97). With zero open conferences, no candidate exists. Both nodes
are `ready` with healthy leases; neither is near the threshold. No
`verify_conference_absent` or `runtime.node.runtime.fence` operations exist
(retryable or otherwise), so worker deployment cannot immediately claim a stale
operation.

## Pending fence operations

Zero `runtime.node.runtime.fence` operations exist (any status). The generic
`runtime-engine:command-worker` claims with
`excludeOperationTypes:[runtime.node.runtime.fence]` (console.php:315) and the
scheduler runs only that worker `--once` — so **fence operations are never claimed
until the dedicated fence-worker Deployment exists**. The dedicated
`runtime-engine:infrastructure-worker` claims with
`includeOperationTypes:[runtime.node.runtime.fence]` (console.php:331) and is **not
scheduled** — it runs only as the (currently absent) fence-worker Deployment loop.

## Fencing component and RBAC

`components/runtime-fencing` renders 4 objects (ServiceAccount `utcp-runtime-fencer`,
Role, RoleBinding, Deployment `utcp-runtime-fence-worker`) and passes server-side
dry-run. Effective Role is exactly: `apps/deployments` get,list;
`apps/deployments/scale` get,patch; `pods` get,list. **Absent**: secret reads, pod
deletion, full-Deployment patch, Service mutation, wildcards, ClusterRole,
cross-namespace access, endpointslices. SA `automountServiceAccountToken:false`;
the worker Deployment mounts an **explicit projected token** (audience
`https://kubernetes.default.svc`, 3600 s, mode 0440) **only on itself**, plus the
`kube-root-ca.crt` CA; command `telephony-infrastructure-worker` (→
`runtime-engine:infrastructure-worker`); DB/Redis via the platform config/secret.
**Image note:** the component pins `utcp-api:local` — a live apply needs the
registry image transform (`utcp-local-registry:5000/utcp/api:0.1.0-k1-dev`), as the
node-B rollout required.

## Non-destructive Kubernetes-client proof

The production `HttpKubernetesWorkloadClient` exposes only read-only `getDeployment`
(GET) and `listOwnedPods` (LIST) plus per-request token reread. The safest proof,
after the worker is deployed, is a **bounded read-only probe** that calls
`getDeployment('utcp-runtime','asterisk-ari')` and `listOwnedPods` against a
node's **own healthy** workload and reports success/authz — exercising API
connectivity, CA validation, token load+reread, Deployment GET authz, Pod LIST
authz, and namespace restriction, while RBAC absence proves no secret/pod-delete/
full-patch authority. It must **not** scale, must not target an arbitrary workload,
and must not synthesize a fence. Since no such command exists, define a bounded
`runtime-engine:infrastructure-probe {--once}` diagnostic (read-only; no scale;
resolves only a real RuntimeNode's own workload identity) — retained as a
permanent read-only diagnostic alongside the other `*-status` commands, never a
control authority. Alternatively, a temporary probe fixture removed after proof.

## Safe activation order

1. **Durable node-A correction** — `kubectl replace --force` the node-A Deployment
   from the committed manifest (recreates with the 3-label selector+template).
   Rollback: re-apply the prior Deployment spec (captured beforehand); node A has no
   live state to lose.
2. Prove node A + B ready and **both** Services isolated (EndpointSlice: one own
   pod each) after recreation. Rollback: none (read-only).
3. Confirm zero open/pending fence operations, both leases healthy, no node near
   threshold, zero open conferences. Rollback: none.
4. Server-side dry-run `components/runtime-fencing` (with the registry image
   transform). Rollback: none.
5. Apply ServiceAccount + Role + RoleBinding. Rollback: delete the three RBAC
   objects.
6. Apply the fence-worker Deployment (registry image). Rollback: delete the
   Deployment (fence execution reverts to unavailable/unclaimed).
7. Prove token + API connectivity read-only (the bounded probe). Rollback: none.
8. Prove the generic worker still excludes fence ops (no fence claim by
   `telephony-command-worker`). Rollback: none.
9. Prove the infra worker claims only fence ops. Rollback: none.
10. Confirm no Deployment was scaled and no Pod deleted. Rollback: none.
11. **Stop before destructive failover** — do not create an open conference or
    scale-to-zero.

## T5-A11 existing implementation

Durable committed manifests (3-label Deployment selector+template, isolated
Service); dedicated `runtime-engine:infrastructure-worker` (fence-only claim) and
generic worker fence-exclusion; the `components/runtime-fencing` SA/Role/
RoleBinding/Deployment with a self-only projected token; `HttpKubernetesWorkloadClient`
(read-only GET/LIST + scale patch); the failover coordinator's open-conference-gated
candidate query. Live: both nodes ready+isolated, zero live state, zero open
conferences, zero fence ops.

## T5-A11 missing implementation

The one-time controlled node-A Deployment recreation (live op, manifests already
correct); the bounded read-only Kubernetes-client probe command/fixture; then the
fencing SA/RBAC/worker apply (with registry image transform) and the read-only
connectivity proof.

## T5-A11 next bounded step

**B — Claude Code can perform a controlled node-A rollout and fencing-worker
activation.** Node-A rollout impact is understood (zero live state → safe), the 76
bindings are historical, the durable correction is exact (`kubectl replace --force`
from the committed manifest), activation preconditions are proven, and rollback is
defined per checkpoint. No Codex repository change is required — the manifests are
already correct; only a bounded read-only probe command may optionally be added.

## Ready-to-paste next prompt (T5-A12)

```
# T5-A12 — Durable node-A recreation and controlled fencing-worker activation (read-only client proof)

Checkpointed live implementation at HEAD 571b703. Keep UTCP_PHASE=T1. Stop before
any destructive scale-to-zero or live failover. Do not create a proof Conference.

Preconditions to reconfirm (abort if any fails): both asterisk nodes active/ready
and Services isolated (each EndpointSlice = its own pod); zero open conferences;
zero runtime.node.runtime.fence operations; fence worker/RBAC absent; UTCP_PHASE=T1.

1. Durable node-A recreation (safe: node A has zero live bridges/channels, zero
   open conferences, 76 historical closed bindings only):
   - capture the current live node-A Deployment spec for rollback;
   - `kubectl replace --force` the node-A Deployment from
     infrastructure/kubernetes/base/runtime (rendered via the local runtime overlay
     so the registry image and secret apply), recreating it with the committed
     3-label selector+template (utcp.dev/runtime-node=local-asterisk-ari);
   - wait for the new node-A pod Ready; verify its template+pod carry the
     runtime-node label and the node-A Service EndpointSlice selects only the new
     node-A pod (durable isolation); verify RuntimeNode local-asterisk-ari returns
     to observed ready via the listener (no manual reconciliation);
   - confirm node B untouched and still isolated.
   Rollback: re-apply the captured prior spec.

2. Optional bounded read-only probe: add runtime-engine:infrastructure-probe
   {--once} that, for a given healthy RuntimeNode slug, resolves its workload
   identity and calls HttpKubernetesWorkloadClient getDeployment + listOwnedPods
   read-only, printing connectivity/authz results. NO scale, NO arbitrary target,
   NO fence. Permanent read-only diagnostic (like *-status), never control
   authority.

3. Fencing activation (stop before destructive failover):
   - server-side dry-run components/runtime-fencing with the registry image
     transform applied (utcp-local-registry:5000/utcp/api:0.1.0-k1-dev);
   - apply ServiceAccount + Role + RoleBinding; verify effective RBAC = deployments
     get/list, deployments/scale get/patch, pods get/list only;
   - apply the utcp-runtime-fence-worker Deployment; wait Ready; confirm the token
     is mounted only on it (automount=false elsewhere);
   - run the read-only probe to prove API connectivity, CA, token reread,
     Deployment GET, Pod LIST, namespace restriction; prove (RBAC) no secret/
     pod-delete/full-patch authority;
   - prove telephony-command-worker still excludes fence ops and the fence worker
     claims only fence ops; confirm no Deployment scaled, no Pod deleted, both
     nodes still ready, zero fence operations created.
   Rollback per object: delete the fence Deployment, then the RBAC/SA.

4. Update docs/evidence/t2/multi-node-failover-readiness.md T5-A11/A12 with the
   durable recreation result, isolation-after-recreation proof, probe results,
   fencing activation evidence, and confirmation that no scale/failover occurred.
   One scoped evidence commit (plus the probe command if added). Do not push.

Verification: make repository-hygiene && make secret-scan && the four config-checks
&& the four focused test suites && (if code added) make test && make check &&
make build && git diff --check.
Do not run: scale-to-zero, live failover, Conference creation, Pod deletion of a
node hosting live state.
```

## T5-A11 rollback

None performed (audit only).

---

# T5-A12 — Durable node-A recreation (done) and fencing activation (blocked by manifest defect)

Checkpointed live implementation at commit `a9487b9`, `UTCP_PHASE=T1`,
2026-07-18. The node-A durable isolation correction **succeeded**; the
fencing-worker activation **hit a committed-manifest namespace defect** and was
rolled back cleanly. No scale-to-zero, no Pod deletion, no failover, no
RuntimeBinding mutation.

## Node-A Deployment replacement (succeeded)

Preconditions reconfirmed immediately before mutation (zero open conferences,
zero live channels/bridges, zero fence ops, node B ready). Captured the live
node-A Deployment (UID `358198fc…`, 2-label immutable selector) to an untracked
`0600` rollback file. Rendered the canonical node-A Deployment via
`overlays/local/runtime` (registry image, unchanged secret/SA/probes/resources/
securityContext; 3-label selector + template `utcp.dev/runtime-node=local-asterisk-ari`).
`kubectl apply --dry-run=server` confirmed the immutable-selector conflict (Option
C), so performed `kubectl replace --force`: the conflicting Deployment was deleted
and recreated (new Deployment UID `94546f77…`, new Pod `asterisk-ari-69bcc8f79f-tmmxw`
UID `030c033c…`). Rollout completed 1/1.

## Durable selector and Pod template (proven)

The new Deployment selector, Pod template, **and** the new Pod all carry
`utcp.dev/runtime-node=local-asterisk-ari` — the label now derives from the
Deployment template, not a manual `kubectl label`. A future Pod recreation will
inherit it automatically.

## Node-A Service isolation (durable, proven)

Without any manual pod labeling, the node-A Service EndpointSlice selects only the
new node-A Pod (`asterisk-ari-69bcc8f79f-tmmxw`); node-B Service selects only the
node-B Pod. Neither Service crosses to the other node.

## Node-A runtime recovery (proven)

No listener restart. Node A returned to `observed_state=ready` with its lease
claimed within one sweep; RuntimeNode id/slug unchanged (`1d15ca88…`/
`local-asterisk-ari`), `kubernetes_workload.deployment=asterisk-ari` intact,
desired `active`, credential/endpoints unchanged, Stasis app `utcp-t0-observation`
active on the new Pod, ARI auth 200, a fresh event epoch opened. Node B stayed
active/ready; the 76 historical bindings were untouched; **zero failover/fence
operations were created**.

## Fencing activation — blocked by a committed-manifest namespace defect

RBAC applied cleanly and its effective permissions were exactly correct
(impersonating `system:serviceaccount:utcp-runtime:utcp-runtime-fencer`): **allowed**
`get/list deployments`, `get/patch deployments --subresource=scale`, `get/list
pods` in `utcp-runtime`; **denied** `patch deployments` (full), `update
deployments --subresource=scale`, `get secrets`, `delete pods`, `update services`,
and any `utcp-platform` access. No ClusterRole; SA `automountServiceAccountToken:false`.

The **worker Deployment failed**: `CreateContainerConfigError — configmap
"utcp-application-config" not found`. The committed `components/runtime-fencing`
places the worker **and** its ServiceAccount in `utcp-runtime`, but the worker's
`envFrom` references `utcp-application-config` (ConfigMap) and
`utcp-local-data-credentials` (Secret) — which exist **only in `utcp-platform`**
(ConfigMaps/Secrets are namespace-scoped). No overlay wires the component into
`utcp-platform`. So the worker cannot obtain its DB/app configuration and cannot
start. This is a **genuine repository manifest defect**, not a live-environment
issue.

Per the rollback contract, the fencing resources were deleted in order (worker →
RoleBinding → Role → ServiceAccount). Final state: fencing fully absent, zero fence
operations ever created, both nodes active/ready, both Services isolated, node-A
durable recreation preserved.

## Required correction (bounded, for Codex)

The fence worker must run where its app config/secret live (`utcp-platform`, with
the sibling workers), while the fencer Role stays namespaced to `utcp-runtime`
(the Asterisk Deployments it scales). Correct cross-namespace shape:

- `ServiceAccount/utcp-runtime-fencer` → **`utcp-platform`** (Pod SAs must be in
  the Pod's namespace), `automountServiceAccountToken:false`.
- Worker `Deployment/utcp-runtime-fence-worker` → **`utcp-platform`**, keeping the
  projected token + `kube-root-ca.crt` and the `utcp-platform` config/secret
  `envFrom`.
- `Role/utcp-runtime-fencer` → stays in **`utcp-runtime`** (unchanged rules).
- `RoleBinding/utcp-runtime-fencer` → stays in **`utcp-runtime`**, `roleRef` the
  `utcp-runtime` Role, `subjects` the `utcp-platform` ServiceAccount (RoleBindings
  may reference cross-namespace SA subjects). This grants the platform-resident
  worker exactly `deployments`(get/list) + `deployments/scale`(get/patch) +
  `pods`(get/list) in `utcp-runtime` and nothing else.

This keeps the token isolated to the fence worker, preserves the minimal RBAC, and
resolves the config dependency. It is a bounded manifest change (4 objects) plus
the image-transform wiring for a live apply, and any fencing-component config-check
must assert the new namespace split.

## T5-A13 repository correction — namespace split and read-only probe

The fencing component now stages the worker beside the canonical application
configuration in `utcp-platform`, while leaving Kubernetes mutation authority
bounded to `utcp-runtime`:

- `Deployment/utcp-runtime-fence-worker` is explicitly namespaced to
  `utcp-platform`.
- `ServiceAccount/utcp-runtime-fencer` is explicitly namespaced to
  `utcp-platform`.
- `Role/utcp-runtime-fencer` remains in `utcp-runtime` with only
  `deployments get/list`, `deployments/scale get/patch`, and `pods get/list`.
- `RoleBinding/utcp-runtime-fencer` remains in `utcp-runtime` and binds the
  cross-namespace subject
  `system:serviceaccount:utcp-platform:utcp-runtime-fencer`.

The worker continues to consume `utcp-application-config` and
`utcp-local-data-credentials` through namespace-local Pod injection in
`utcp-platform`; no duplicate ConfigMap, duplicate Secret, cross-namespace Secret
copy, ClusterRole, or ClusterRoleBinding was introduced. The explicit projected
Kubernetes token remains mounted only into the dedicated fencing worker, with
`automountServiceAccountToken:false` preserved.

A bounded diagnostic command, `runtime-engine:infrastructure-probe --once`, was
added for later live activation proof. The probe deterministically selects one
active and ready RuntimeNode with canonical `kubernetes_workload` metadata,
resolves it through `RuntimeNodeWorkloadIdentityResolver`, performs only
`KubernetesWorkloadClient::getDeployment()` and `listOwnedPods()`, validates UTCP
Deployment ownership and the RuntimeNode-specific label, and emits bounded
non-secret evidence: RuntimeNode slug, namespace, Deployment name, replica counts,
and owned-Pod count. It has no target options, creates no runtime operation, does
not touch RuntimeBindings or observed state, and never calls `scaleDeployment()`.

Repository config checks now assert the split namespace contract, cross-namespace
RoleBinding subject, exact RBAC rule set, projected-token isolation, fence-only
worker routing, and the read-only probe boundary. This task did not apply any
Kubernetes resources, activate the worker, create a runtime-fence operation,
scale a Deployment, delete Pods, or run failover. Live worker activation and
Kubernetes-client proof remain pending.

## Historical handoff prompt for the T5-A13 repository correction

```
# T5-A13 — Correct fencing-worker namespace and re-activate (repository fix + live activation)

Repository fix then checkpointed live activation at HEAD after T5-A12. Keep
UTCP_PHASE=T1. Stop before any scale-to-zero or live failover.

Problem (docs/evidence/t2/multi-node-failover-readiness.md T5-A12): the committed
infrastructure/kubernetes/components/runtime-fencing worker + ServiceAccount are in
utcp-runtime, but the worker's envFrom (utcp-application-config ConfigMap,
utcp-local-data-credentials Secret) exist only in utcp-platform, so the worker
fails with CreateContainerConfigError and cannot start.

1. Repository correction (components/runtime-fencing):
   - move ServiceAccount/utcp-runtime-fencer to namespace utcp-platform
     (automountServiceAccountToken:false).
   - move Deployment/utcp-runtime-fence-worker to namespace utcp-platform, keeping
     the projected serviceAccountToken volume (audience kubernetes.default.svc,
     3600s), kube-root-ca.crt, envFrom utcp-application-config + utcp-local-data-credentials,
     command telephony-infrastructure-worker, and the image (base name; overlays
     apply the registry transform).
   - keep Role/utcp-runtime-fencer in utcp-runtime (rules unchanged: deployments
     get/list, deployments/scale get/patch, pods get/list).
   - keep RoleBinding/utcp-runtime-fencer in utcp-runtime; set its subjects to the
     utcp-platform ServiceAccount (cross-namespace subject), roleRef unchanged.
   - update any fencing config-check / topology validation to assert the platform
     worker + SA and the utcp-runtime Role/RoleBinding cross-namespace subject.
   - add/adjust focused tests as needed. Do NOT add a feature gate or allowlist.

2. Verification: make repository-hygiene && make workflow-check && make secret-scan
   && the four config-checks && the focused test suites && make test && make check
   && make build && git diff --check. Commit: feat(t5): place fencing worker in the
   platform namespace with cross-namespace fence RBAC. Do not push.

3. Live activation (only after the fix is committed; reconfirm zero open
   conferences, zero fence ops, both nodes ready):
   - render components/runtime-fencing with the registry image transform;
     server-side dry-run.
   - apply SA (utcp-platform) + Role + RoleBinding (utcp-runtime); prove effective
     perms by impersonation (allowed: deployments get/list, deployments
     --subresource=scale get/patch, pods get/list in utcp-runtime; denied: full
     deployment patch, secrets, pod delete, service update, cross-namespace).
   - apply the worker Deployment (utcp-platform); wait Ready; prove only it mounts
     the projected token (automount=false elsewhere); command
     telephony-infrastructure-worker.
   - non-destructive client proof: add/run a read-only runtime-engine:infrastructure-probe
     {--once} (no scale/target/force args) that resolves a healthy RuntimeNode's
     workload and calls HttpKubernetesWorkloadClient getDeployment + listOwnedPods,
     proving API connectivity, CA, token reread, Deployment GET, Pod LIST, namespace
     restriction; never scaleDeployment().
   - prove generic command-worker still excludes fence ops and the infra worker
     includes only fence ops; confirm zero scale, zero pod deletions, zero fence
     operations created, both nodes ready.
   - STOP before destructive failover. Update the evidence doc; one scoped commit;
     do not push.

Rollback per object: delete worker -> RoleBinding -> Role -> ServiceAccount; do not
alter RuntimeNodes/RuntimeBindings.
```

## T5-A12 rollback performed

Fencing resources only: `Deployment/utcp-runtime-fence-worker`,
`RoleBinding/utcp-runtime-fencer`, `Role/utcp-runtime-fencer`,
`ServiceAccount/utcp-runtime-fencer` (all in `utcp-runtime`) were deleted after the
worker failed to start. Node A (durable recreation), node B, RuntimeNodes,
credentials, and RuntimeBindings were not touched by rollback.

---

# T5-A14 — Fencing worker activation: namespace fix confirmed, blocked by securityContext user defect

Checkpointed live implementation at commit `d4a9386`, `UTCP_PHASE=T1`,
2026-07-18. The T5-A13 namespace correction is confirmed working (config/secret
now resolve, RBAC is exactly correct), but the worker Pod is blocked by a **second
committed-manifest defect**: it forces `runAsUser: 1000` while the api image and
every sibling platform worker run as `www-data` (UID 33). Rolled back cleanly.
No fence operation, no scale, no Pod deletion, no failover.

## Baseline preconditions

Both nodes active/ready, Services isolated (durable node-A recreation from T5-A12
holds: node-A Service → `asterisk-ari-69bcc8f79f-tmmxw` only), 2 leases claimed,
zero open conferences/operations/fence-operations/verify-operations, zero live
channels/bridges, fencing absent in both namespaces. All status targets passed.

## Worker image currency

The registry `utcp/api:0.1.0-k1-dev` predated `d4a9386` (it had
`runtime-engine:infrastructure-worker` but **not** `runtime-engine:infrastructure-probe`).
Built from the clean tree and pushed to the local registry so the image contains
the probe command and the cross-namespace worker wiring. No unrelated Deployment
was restarted.

## Rendered namespace split (T5-A13 fix — correct)

`kubectl kustomize components/runtime-fencing` (registry image applied) rendered
exactly: `ServiceAccount/utcp-runtime-fencer`→**utcp-platform**;
`Role/utcp-runtime-fencer`→**utcp-runtime**; `RoleBinding/utcp-runtime-fencer`→
**utcp-runtime** with subject `ServiceAccount/utcp-runtime-fencer@utcp-platform`
(cross-namespace) and roleRef the utcp-runtime Role; `Deployment/utcp-runtime-fence-worker`→
**utcp-platform**, SA `utcp-runtime-fencer`, `envFrom` the utcp-platform
`utcp-application-config` + `utcp-local-data-credentials`, `automountServiceAccountToken:false`,
projected token (audience `https://kubernetes.default.svc`, 3600 s). Server-side
dry-run passed for all four objects.

## Effective RBAC (correct)

Applied SA + Role + RoleBinding. Impersonating
`system:serviceaccount:utcp-platform:utcp-runtime-fencer`: **allowed in
utcp-runtime** `get/list deployments`, `get/patch deployments --subresource=scale`,
`get/list pods`; **denied** `get secrets`, `delete pods`, `patch deployments`
(full), `update services` (all utcp-runtime), and **all** utcp-platform workload
access (`get deployments` and `patch scale` in utcp-platform both denied). The
cross-namespace binding grants exactly the bounded utcp-runtime permissions and
nothing in the worker's own namespace. No ClusterRole.

## Blocking defect — worker securityContext user

The worker Pod failed with exit 1:
`The /var/www/html/bootstrap/cache directory must be present and writable`. The api
image's `/var/www/html/bootstrap/cache` is owned by `www-data` (UID/GID 33) mode
`drwxrwx---` (no other-write), and every working platform worker
(`telephony-command-worker`, `telephony-reconciler`, `scheduler`) runs as
`runAsUser: 33`. The committed fence worker forces `runAsUser: 1000, runAsGroup:
1000` (`infrastructure-worker-deployment.yaml` securityContext), so UID 1000 cannot
write the www-data-owned cache and Laravel bootstrap aborts. This is a genuine
committed-manifest defect. Per the task contract for a discovered defect, the
fencing resources were rolled back rather than patched live.

## Required correction (bounded, for Codex)

In `components/runtime-fencing/infrastructure-worker-deployment.yaml`, change the
Pod `securityContext` to run as the api image's user, matching the sibling platform
workers: `runAsUser: 33`, `runAsGroup: 33` (keep `runAsNonRoot: true`,
`seccompProfile: RuntimeDefault`, and the container `allowPrivilegeEscalation:false`
+ `capabilities.drop:[ALL]`). No other change. Any fencing config-check should
assert the worker user matches the platform worker contract (33), not 1000.

## Rollback performed

Fencing resources only, in contract order: `Deployment/utcp-runtime-fence-worker`
(utcp-platform) → `RoleBinding/utcp-runtime-fencer` (utcp-runtime) →
`Role/utcp-runtime-fencer` (utcp-runtime) → `ServiceAccount/utcp-runtime-fencer`
(utcp-platform). The durable node-A recreation, node B, RuntimeNodes, credentials,
and the 76 RuntimeBindings were not touched.

## Scale / deletion / failover absence

Both Asterisk Deployments stayed `replicas=1` with unchanged pods
(`asterisk-ari-69bcc8f79f-tmmxw`, `asterisk-ari-b-589c94c588-mnv2t`) — no scale, no
Pod deletion, no restart. Zero `runtime.node.runtime.fence` operations and zero
`conference.runtime_fence_terminated` events ever created (the worker crashed at
Laravel bootstrap, before any operation claim). No RuntimeBinding generation
changed.

## Ready-to-paste next prompt (T5-A15)

```
# T5-A15 — Correct fence-worker securityContext user and re-activate

Repository fix then checkpointed live activation at HEAD after T5-A14. Keep
UTCP_PHASE=T1. Stop before any scale-to-zero or live failover.

Problem (docs/evidence/t2/multi-node-failover-readiness.md T5-A14): the committed
components/runtime-fencing/infrastructure-worker-deployment.yaml Pod securityContext
forces runAsUser:1000/runAsGroup:1000, but the api image's /var/www/html/bootstrap/cache
is owned by www-data (UID 33, mode 770) and every sibling platform worker runs as
runAsUser:33. So the fence worker crashes at Laravel bootstrap
("bootstrap/cache must be present and writable").

1. Repository correction (one file): set the fence worker Pod securityContext to
   runAsUser:33, runAsGroup:33 (keep runAsNonRoot:true, seccompProfile RuntimeDefault,
   container allowPrivilegeEscalation:false, capabilities.drop:[ALL]). If a fencing
   config-check asserts the worker user, update it to 33 (matching the platform
   worker contract). No other change; no feature gate.

2. Verification: make repository-hygiene && make workflow-check && make secret-scan
   && the four config-checks && the focused test suites && make test && make check
   && make build && git diff --check. Commit: fix(t5): run fencing worker as the
   platform image user. Do not push.

3. Live activation (only after the fix; build+push the image if it predates the fix;
   reconfirm zero open conferences, zero fence ops, both nodes ready):
   - render components/runtime-fencing with the registry image; server-side dry-run.
   - apply SA (utcp-platform) + Role + RoleBinding (utcp-runtime); prove effective
     perms by impersonation (allowed: deployments get/list, deployments
     --subresource=scale get/patch, pods get/list in utcp-runtime; denied: full
     patch, secrets, pod delete, service update, all utcp-platform workload access).
   - apply the worker Deployment (utcp-platform); wait Ready; prove only it mounts
     the projected token, it runs telephony-infrastructure-worker, and its config/
     secret resolve.
   - run kubectl exec ... php artisan runtime-engine:infrastructure-probe --once and
     prove: RuntimeNode slug resolved, namespace=utcp-runtime, Deployment GET
     succeeds, owned-Pod LIST succeeds, ownership labels validate, replica/pod counts
     reported, and NO token/secret/credential in output. Never call scaleDeployment().
   - prove token+CA files exist with safe perms and the client rereads the token
     (run the probe twice); reconfirm denied authority via impersonation.
   - prove generic command-worker still excludes fence ops and the infra worker
     includes only fence ops; confirm zero scale, zero pod deletions, zero fence
     operations created, both nodes ready, bindings unchanged.
   - STOP before destructive failover. Update the evidence doc; one scoped commit;
     do not push.

Rollback per object: delete worker -> RoleBinding -> Role -> ServiceAccount; do not
alter RuntimeNodes/RuntimeBindings or the durable node-A Deployment.
```

## T5-A15 — Fencing worker runtime identity correction

Repository correction at `UTCP_PHASE=T1`. The dedicated fencing worker manifest now
runs as the same API-image application identity used by the API, generic worker,
scheduler, telephony command worker, reconciler, event normalizer, ARI listener, and
other Laravel platform workers:

```yaml
securityContext:
  runAsNonRoot: true
  runAsUser: 33
  runAsGroup: 33
```

The defect was the previous `runAsUser: 1000` / `runAsGroup: 1000` override. The API
image prepares `storage` and `bootstrap/cache` for `www-data:www-data` and then runs
as `USER www-data`; on Debian/PHP images this is UID/GID 33. The cache directory is
group-writable for that identity and not world-writable, so UID/GID 1000 reached
Laravel bootstrap but could not write `/var/www/html/bootstrap/cache`.

The correction deliberately aligns the fencing worker with the canonical image user
instead of adding a permission workaround. The manifest still preserves explicit
non-root execution, `seccompProfile: RuntimeDefault`, `allowPrivilegeEscalation:
false`, and `capabilities.drop: [ALL]`. No root execution, init-container `chown`,
startup `chmod`, world-writable permission, `fsGroup` workaround, alternate image,
duplicate cache path, feature gate, RuntimeNode allowlist, RBAC change, token change,
or operation-routing change was introduced.

The namespace split remains unchanged: `Deployment/utcp-runtime-fence-worker` and
`ServiceAccount/utcp-runtime-fencer` render in `utcp-platform`; `Role` and
`RoleBinding` render in `utcp-runtime`; the RoleBinding subject remains
`system:serviceaccount:utcp-platform:utcp-runtime-fencer`. The worker still consumes
the canonical `utcp-application-config` ConfigMap and `utcp-local-data-credentials`
Secret from its own namespace, and only the dedicated worker mounts the projected
Kubernetes API token.

Repository validation now asserts the rendered worker uses UID/GID 33, keeps
`runAsNonRoot: true`, retains hardening fields, has no permission-changing init
container, and has no `fsGroup` workaround. Runtime-fencing component rendering and
server-side dry-run were performed non-destructively; no Kubernetes resource was
applied, no worker was activated, no runtime-fence operation was created, no
Deployment was scaled, no Pod was deleted, and no failover occurred.

Live worker activation, projected-token Kubernetes-client proof, real scale-to-zero
proof, and automatic two-node failover remain pending.

## T5-A17 — Fencing-worker egress and Kubernetes API NetworkPolicy contract (evidence-only)

Live audit at `UTCP_PHASE=T1`, HEAD `8e72e63`, clean tree. No repository correction
was implemented; no fencing resource was applied; no runtime-fence operation,
scale, Pod deletion, RuntimeNode/RuntimeBinding mutation, Conference, or failover
occurred. Both Asterisk nodes stayed `1/1` and both RuntimeNodes stayed
`active|ready` throughout. All probe resources were removed.

### Official NetworkPolicy interpretation

NetworkPolicy is additive-only. `utcp-platform` carries a `default-deny` policy
with empty `podSelector` and `policyTypes: [Ingress, Egress]`, so every Pod in the
namespace is isolated for both directions and each required flow needs an explicit
allow policy whose `podSelector` matches the Pod. A label with no matching policy
grants nothing: the probe carried `utcp.io/kubernetes-api-client: "true"` before
any candidate policy existed and all API egress remained refused.

### Existing default-deny and allow policies (live inventory)

`utcp-platform` held exactly 15 NetworkPolicies before and after the audit:
`default-deny` (selector `{}`), plus role-scoped allows keyed on
`utcp.io/network-role`: `api`, `gateway` (×3 incl. metrics and service-clusterips),
`web`, `worker` (`allow-worker-required-egress`, `allow-command-worker-to-asterisk-ari`),
`scheduler`, `migration`, `asterisk-ari-events`, `simulator-event-source`,
`kamailio-registration-observer`, `kamailio-signaling`, and
`allow-backend-data-service-clusterips` (selector `utcp.io/network-role in
(api, worker, scheduler, migration)`).

### Fencing-worker selector gap

`infrastructure/kubernetes/components/runtime-fencing/infrastructure-worker-deployment.yaml`
labels the Pod template only with `app.kubernetes.io/{name,part-of,component}`.
No NetworkPolicy in `utcp-platform` selects any of those labels. Under
default-deny the worker therefore has zero egress: CoreDNS is unreachable, so
PostgreSQL hostname resolution fails before any database or Kubernetes API
connection is attempted. This is the sole remaining activation blocker from
T5-A16.

### Common worker egress proof

Disposable probe `Pod/t5-a17-egress-probe` (restricted-PSS-compliant, UID/GID 33,
API image, ServiceAccount `t5-a17-egress-probe` with no Role/RoleBinding) was
labeled `utcp.io/network-role: worker`. Live results:

```text
dns_udp postgres.utcp-data.svc.cluster.local => 10.43.8.153
dns_udp redis.utcp-data.svc.cluster.local    => 10.43.87.131
dns_udp kubernetes.default.svc               => 10.43.0.1
dns_tcp 10.43.0.10:53                        => connect_ok
postgres 5432                                => connect_ok
redis 6379                                   => connect_ok
DENY api-clusterip 10.43.0.1:443             => connect_failed errno=111
DENY api-endpoint 172.24.0.5:6443            => connect_failed errno=111
DENY web 10.43.218.176:8080                  => connect_failed errno=111
DENY external 1.1.1.1:443                    => connect_failed errno=111
```

The `worker` role label yields exactly DNS UDP/TCP 53 (kube-dns), PostgreSQL
TCP 5432, and Redis TCP 6379 — through two cooperating policies:
`allow-worker-required-egress` (namespace+pod-selector destinations) and
`allow-backend-data-service-clusterips` (rendered Service-ClusterIP /32
destinations, currently 10.43.0.10 / 10.43.8.153 / 10.43.87.131, matching live
Services). Destination-side ingress (`utcp-data` default-deny plus its allow
policies) already admits `utcp-platform` sources for 5432/6379; no source-label
change is needed on the destination side. Nothing else is reachable. Adding
`utcp.io/network-role: worker` to the fencing-worker Pod template is therefore
the exact bounded correction for DNS, PostgreSQL, and Redis — no duplicate
policy is required.

### Kubernetes API service identity (client-visible)

```text
KUBERNETES_SERVICE_HOST=10.43.0.1
KUBERNETES_SERVICE_PORT_HTTPS=443
default/kubernetes Service: ClusterIP 10.43.0.1, port https 443/TCP -> targetPort 6443
```

### Kubernetes API backend identity (post-DNAT)

```text
default/kubernetes EndpointSlice "kubernetes": 172.24.0.5:6443 (single backend)
172.24.0.5 = k3d-utcp-local-server-0 InternalIP (control-plane node)
K3s v1.35.3+k3s1, flannel VXLAN, embedded kube-router NetworkPolicy controller
```

The in-cluster client dials `10.43.0.1:443`; kube-proxy DNAT rewrites the flow to
`172.24.0.5:6443` before policy filtering. `443` and `6443` are not
interchangeable policy targets.

### Candidate policy results (live, single-variable)

Candidate A — `t5-a17-candidate-apiserver-clusterip`, selector
`utcp.io/kubernetes-api-client: "true"`, egress `10.43.0.1/32` TCP 443 only:

```text
api-clusterip 10.43.0.1:443 => connect_failed errno=111   (after 3s and again after 15s sync)
control postgres 5432       => connect_ok                 (enforcement live)
```

FAILED. kube-router does not match the pre-DNAT Service ClusterIP for the
apiserver flow.

Candidate B — Candidate A deleted first, then
`t5-a17-candidate-apiserver-endpoint`, same selector, egress `172.24.0.5/32`
TCP 6443 only:

```text
via-service 10.43.0.1:443       => connect_ok
endpoint-direct 172.24.0.5:6443 => connect_ok
tls_verified_get /version       => HTTP/1.1 200 OK   (CA-verified, peer kubernetes.default.svc)
gitVersion                      => v1.35.3+k3s1
authenticated_discovery /api    => HTTP/1.1 200 OK
list pods (no RBAC)             => HTTP/1.1 403 Forbidden   (probe SA correctly powerless)
```

SUCCEEDED alone. After deleting and recreating the probe Pod pinned to
`k3d-utcp-local-agent-0` (different node, VXLAN path), the same policy again
permitted `10.43.0.1:443` connect and an authenticated CA-verified `/version`
GET, while PostgreSQL stayed reachable and `1.1.1.1:443` stayed refused. The
result is not tied to one Pod or to server-node placement.

Effective K3s/kube-router destination: **post-DNAT backend endpoint
`172.24.0.5/32` TCP 6443**. The Service-ClusterIP rule is neither sufficient nor
necessary; permitting both would be an unsupported compatibility fallback and is
rejected.

### Drift and lifecycle assessment

The backend endpoint is the k3d server container's Docker-network IP. It is
stable while the cluster runs but can change when node containers restart in a
different order (observed live: the 2026-07-17 host restart moved the server
from `172.24.0.4` to `172.24.0.5`). At audit time the live
`allow-traefik-kubernetes-api` and `allow-observability-kubernetes-api-egress`
policies and their `.runtime` rendered files still pinned the stale
`172.24.0.4/32` — which is why the Grafana Pod sat at `1/2 Error` — while their
ClusterIP `10.43.0.1/32:443` rules did not keep them alive, independently
confirming the Candidate A result. The endpoint is queryable
(`default/kubernetes` Endpoints/EndpointSlice), the repository already derives
it automatically (`render_apiserver_policy` in `scripts/security/lib` and
`scripts/observability/lib`, template
`infrastructure/kubernetes/security/traefik/allow-apiserver-egress.template.yaml`),
and `scripts/security/apply` re-renders on every apply — but **no config-check
compares rendered or live policies against the current endpoint**, so drift
persists silently between applies. The existing template pattern is canonical
(shared helpers, used by K3 and K4) and reusable with a narrower selector; its
dual ClusterIP+endpoint rule set predates this audit's single-variable proof.

### Selected canonical strategy

**B — generated current API endpoint IP `/32` and backend port (6443), rendered
from the live `default/kubernetes` Endpoints object via the existing
template/render pattern, selected by `utcp.io/kubernetes-api-client: "true"`,
plus an explicit drift check.**

Rejected alternatives:

- A (Service ClusterIP /32:443): proven non-functional under kube-router
  post-DNAT enforcement.
- A+B combined: ClusterIP rule proven dead weight; forbidden compatibility
  fallback that masks nothing and widens the allowed set.
- C (reuse existing template output as-is): the existing rendered policies
  select Traefik/observability Pods only, carry the dead ClusterIP rule, and
  have no staleness detection; the pattern is reused, not the artifact.
- Broad node/cluster CIDR or `0.0.0.0/0`: rejected as non-least-privilege; an
  exact `/32` is proven sufficient.

### Required repository correction (bounded, for Codex — T5-A18)

1. Add `utcp.io/network-role: worker` to the fencing-worker Pod template
   (grants exactly DNS/PostgreSQL/Redis via existing policies; no duplicates).
2. Add `utcp.io/kubernetes-api-client: "true"` to the same Pod template only.
3. Add one rendered fencer-only NetworkPolicy in `utcp-platform` (selector
   `utcp.io/kubernetes-api-client: "true"`, egress = current API endpoint
   IP/32 + endpoint port from `default/kubernetes`, no ClusterIP rule),
   rendered through the existing `scripts/security` template mechanism into
   `.runtime/kubernetes/security/` and applied by `scripts/security/apply`.
4. Extend semantic validation (`scripts/runtime-engine/config-check`) to assert
   both Pod labels and forbid broad CIDRs in the fencer policy.
5. Add a drift check that fails when a rendered/live apiserver egress policy
   does not match the current `default/kubernetes` endpoint (this also covers
   the Traefik/observability policies that are stale today).
6. No ClusterRole change, no RBAC change, no allow-all, no feature gate, no
   API egress for the ordinary `worker` role.

### Cleanup proof

`NetworkPolicy/t5-a17-candidate-apiserver-clusterip` (deleted before Candidate
B), `NetworkPolicy/t5-a17-candidate-apiserver-endpoint`,
`Pod/t5-a17-egress-probe`, and `ServiceAccount/t5-a17-egress-probe` were all
deleted; a cluster-wide scan for `t5-a17` across pods, service accounts,
network policies, config maps, roles, and role bindings returned nothing.
Post-audit: `utcp-platform` again holds exactly 15 NetworkPolicies, both
Asterisk Deployments report `1/1`, RuntimeNodes `Local Asterisk ARI` and
`Local Asterisk ARI B` remain `active|ready`, zero fence/verify-absence
operations and zero pending operations exist, and the working tree is clean at
`8e72e63` with `UTCP_PHASE=T1`.

## T5-A18 — Fencing-worker NetworkPolicy egress contract (repository implementation)

Repository implementation at `UTCP_PHASE=T1` adds the T5-A17-proven egress
contract without activating the fencing worker and without applying live fencing
resources.

### Implemented contract

- `utcp-runtime-fence-worker` Pod template now carries
  `utcp.io/network-role: worker`, reusing the existing common worker policies
  for CoreDNS, PostgreSQL, and Redis egress.
- The same Pod template carries the dedicated selector
  `utcp.io/kubernetes-api-client: "true"`.
- No ordinary platform worker in `infrastructure/kubernetes/base` carries the
  API-client label.
- The fencer-only policy template is
  `infrastructure/kubernetes/security/kubernetes-api/allow-runtime-fencer-kubernetes-api.template.yaml`.
  It selects only `utcp.io/kubernetes-api-client: "true"` in `utcp-platform`,
  has policy type `Egress`, one `ipBlock` destination, and one TCP port.
- The fencer policy is rendered by `scripts/security/lib` from the existing
  `service_endpoint_ip default kubernetes` and `service_endpoint_port default
  kubernetes` helpers into
  `.runtime/kubernetes/security/runtime-fencer-apiserver-egress.yaml`.
- `scripts/security/apply` renders and validates the endpoint-targeted policies
  before applying the canonical security manifests, then applies the rendered
  fencer policy with the other security policies.

### ClusterIP fallback removal

The Traefik and observability Kubernetes API egress templates now target only the
current post-DNAT `default/kubernetes` endpoint IP `/32` and backend endpoint
port. The proven-ineffective Service ClusterIP `/32:443` fallback was removed
from:

- `infrastructure/kubernetes/security/traefik/allow-apiserver-egress.template.yaml`
- `infrastructure/kubernetes/observability/network-policies/allow-apiserver-egress.template.yaml`

No `0.0.0.0/0`, node CIDR, cluster CIDR, hidden fallback destination, or Service
ClusterIP API rule was added.

### Drift detection

`scripts/security/check-apiserver-policy-drift` compares endpoint-targeted
NetworkPolicies against the current `default/kubernetes` endpoint IP and port.
It covers:

- `allow-runtime-fencer-kubernetes-api`
- `allow-traefik-kubernetes-api`
- `allow-observability-kubernetes-api-egress`

The check fails on stale IP, stale port, missing policy, Service ClusterIP
destination, broad CIDR, and duplicate fallback destinations. `make
security-config-check` renders the policies, runs the drift check, and performs
server-side dry-run validation. Re-running the standard security lifecycle is
the reconciliation path for endpoint drift; no manual endpoint configuration or
feature gate was introduced.

### Repository proof

Focused repository tests prove the fencer labels, ordinary-worker isolation,
common worker egress reuse, fencer-only API selector, endpoint `/32` rendering,
backend endpoint port rendering, absence of the Service ClusterIP fallback,
stale IP and stale port rejection, missing policy rejection, broad CIDR
rejection, duplicate fallback rejection, RBAC preservation, projected-token
isolation, UID/GID `33:33`, and operation routing preservation.

Non-destructive validation rendered
`infrastructure/kubernetes/components/runtime-fencing/` and the new fencer
API-egress policy. Server-side dry-run was performed against
`.runtime/kubeconfig/utcp-local.yaml`, context `k3d-utcp-local`, for the
runtime-fencing component and the rendered fencer NetworkPolicy. No Kubernetes
resource was applied, no RBAC was applied, no Deployment was scaled, no Pod was
deleted, no runtime-fence operation was created, and no failover occurred.

### Still pending

Live worker activation remains pending. Live projected-token Kubernetes-client
proof, real scale-to-zero proof, automatic failover, replacement reconstruction,
former-node restoration, stale active-binding retirement, Provider Node Admin UI,
replacement-node failure handling, coordinator metrics,
fencing metrics, capacity policy, and placement policy are not proven by this
repository-only task.

## T5-A19 — NetworkPolicy reconciliation proven live; worker activation blocked by projected-CA file mode

Checkpointed live execution at `UTCP_PHASE=T1` from HEAD `9a350f9`. The
endpoint-targeted Kubernetes API egress reconciliation succeeded and is retained
live. The dedicated fencing worker deployed, ran as UID/GID 33, passed every
network-egress proof including the apiserver flow, but the read-only
Kubernetes-client probe failed because the projected `ca.crt` file is not
readable by UID 33. Per the rollback contract the worker Deployment,
RoleBinding, Role, and ServiceAccount were removed; the correct NetworkPolicies
remain. No fence operation, scale, Pod deletion, binding mutation, or failover
occurred; both Asterisk nodes kept the same Pod UIDs throughout.

### Baseline

Clean tree at `9a350f9`; both Asterisk Deployments 1/1 (Pod UIDs
`030c033c-…45dd` / `3b9121dc-…4671e`); both RuntimeNodes `active|ready` with
fresh observations; node-A/node-B Services selecting only their own Pods; both
`asterisk-ari-events` leases claimed and unexpired; one open connection epoch
per node; zero open conferences; zero live bridges/channels on both nodes
(console inspection via `/tmp/utcp-asterisk/asterisk.conf`); zero
fence/verify/pending operations; fencing resources absent in all namespaces;
status targets passed (`gateway-status` could not run because `helm` is absent
from PATH; gateway health proven directly: `/healthz`, `/api/health/live`,
`/api/health/ready` all 200 through Traefik).

### API endpoint discovery (canonical helpers)

`service_endpoint_ip default kubernetes` → `172.24.0.5`;
`service_endpoint_port` → `6443`; ClusterIP `10.43.0.1`; in-Pod
`KUBERNETES_SERVICE_HOST=10.43.0.1`, `KUBERNETES_SERVICE_PORT_HTTPS=443`.
Exactly one endpoint IP and one port.

### Render and generated-versus-live comparison

`scripts/security/render-apiserver-policy` rendered all three policies
endpoint-only at `172.24.0.5/32:6443` (runtime-fencer selector
`utcp.io/kubernetes-api-client: "true"`; Traefik and observability selectors
unchanged). Live comparison before apply:

```text
allow-runtime-fencer-kubernetes-api   rendered 172.24.0.5/32:6443   live ABSENT              drift: create
allow-traefik-kubernetes-api          rendered 172.24.0.5/32:6443   live 172.24.0.4/32:6443
                                                                    + dead 10.43.0.1/32:443  drift: stale+dead rule
allow-observability-kubernetes-api-egress rendered 172.24.0.5/32:6443 live 172.24.0.4/32:6443
                                                                    + dead 10.43.0.1/32:443  drift: stale+dead rule
allow-backend-data-service-clusterips rendered = live                                        drift: none
```

### Apply-scope review and bounded reconciliation

`kubectl diff -k infrastructure/kubernetes/security` showed exactly one real
change beyond the policies: the live `utcp-runtime` Namespace still carries the
pre-canonical labels (`app.kubernetes.io/part-of:
unified-telephony-control-plane`, no PSA `*-version: v1.35` pins) versus the
committed canonical form. Because this task's acceptable live changes were
limited to the three policy corrections, the monolithic `scripts/security/apply`
was not run; the lifecycle's own steps were executed instead:
`render-apiserver-policy` → `scripts/security/config-check` → the same
`kube apply -f` commands the lifecycle uses for its rendered policy files.
Result: fencer policy `created`; Traefik and observability policies
`configured` (stale endpoint replaced, dead ClusterIP rule removed);
service-clusterip policies `unchanged`. The Namespace label drift is reported
for the next full security apply, which reconciles it to canon.

### Drift validation and safety scans (live)

`scripts/security/check-apiserver-policy-drift` passed against the rendered
files AND against live-state dumps of all three policies
(`endpoint=172.24.0.5/32:6443`). Cluster-wide NetworkPolicy scan: no
`0.0.0.0/0`, no `10.43.0.1/32` ClusterIP fallback, no non-/32 egress ipBlock
anywhere. `default-deny` intact (`podSelector {}`, Ingress+Egress). Zero Pods
matched the fencer selector before worker deployment.

### Post-reconciliation workload validation

Traefik 1/1; gateway health endpoints 200; PostgreSQL/Redis Running; both
Asterisk nodes Ready; leases claimed. Supporting evidence: the Grafana Pod,
crash-looping since the node-IP shuffle on its stale `172.24.0.4` pin,
recovered to 2/2 Running on its next restart after the observability policy was
corrected — no observability work performed beyond the policy reconciliation.

### Worker image currency

`git diff 8e72e63..9a350f9` touches no runtime application code (one test file
only). The deployed registry image
`utcp-local-registry:5000/utcp/api:0.1.0-k1-dev` already contains
`runtime-engine:infrastructure-worker`, `runtime-engine:infrastructure-probe`,
and `HttpKubernetesWorkloadClient`, and runs as uid=33(www-data). No rebuild,
no unrelated restarts.

### Render, dry-run, RBAC

`components/runtime-fencing` + registry image transform rendered exactly the 4
canonical objects (SA+Deployment in `utcp-platform`; Role+RoleBinding in
`utcp-runtime` with the cross-namespace subject); Pod template carries both
labels; UID/GID 33; `automountServiceAccountToken: false`; server-side dry-run
passed. SA+Role+RoleBinding applied first. Impersonation matrix for
`system:serviceaccount:utcp-platform:utcp-runtime-fencer`: ALLOWED in
`utcp-runtime` — deployments get/list, deployments `--subresource=scale`
get/patch, pods get/list; DENIED — secrets get, pods delete, full deployment
patch, services update, deployments create/delete, and all workload access in
`utcp-platform` and `utcp-data`. Exactly the approved boundary.

### Worker deployment and startup

Pre-deployment recheck: both nodes ready/fresh, zero open conferences, zero
fence/verify/pending operations, leases healthy. The Deployment rolled out and
became 1/1. One initial container restart occurred: the first start's
PostgreSQL connect was refused while kube-router was still programming the new
Pod's policy ipsets (DNS had already resolved 10.43.8.153, proving DNS egress);
the restarted container bootstrapped Laravel, connected to PostgreSQL, and
entered the fence-only polling loop with no further errors. Worker identity
verified live: SA `utcp-runtime-fencer`, both Pod labels present, uid/gid 33,
projected token + CA mounted, hardening intact, no operation created, no scale
performed, logs free of secrets.

### Blocking defect — projected CA unreadable by UID 33

```text
/var/run/secrets/kubernetes.io/serviceaccount/..data/:
-r--r----- 1  0 0  570 ca.crt   (root:root, 0440 — NOT readable by uid 33)
-rw------- 1 33 0 1216 token    (kubelet chowns serviceAccountToken to runAsUser)
```

The committed `infrastructure-worker-deployment.yaml` sets projected volume
`defaultMode: 0440` with no `fsGroup`. Kubelet special-cases only the
`serviceAccountToken` source (owner = runAsUser); ConfigMap-sourced items stay
`root:root`, so the `kube-root-ca.crt` projection is unreadable by the UID-33
process. `HttpKubernetesWorkloadClient::caPath()` requires `is_readable` and
throws `unavailable_to_control`; `php artisan runtime-engine:infrastructure-probe
--once` therefore returned `infrastructure_probe_status=failed
reason=unavailable_to_control`. The failure is isolated to file permissions:

```text
fence-worker dns postgres            => 10.43.8.153
fence-worker dns redis               => 10.43.87.131
fence-worker postgres 5432           => connect_ok
fence-worker redis 6379              => connect_ok
fence-worker apiserver 10.43.0.1:443 => connect_ok      (via fencer policy, post-DNAT 172.24.0.5:6443)
fence-worker external 1.1.1.1:443    => connect_failed errno=111
ordinary worker apiserver 10.43.0.1:443 => connect_failed errno=111
```

Token readability, network path, RBAC, DNS, and database egress are all proven
good; only the CA file mode blocks the client.

### Rollback performed (per contract)

`Deployment/utcp-runtime-fence-worker` → `RoleBinding` → `Role` →
`ServiceAccount` deleted; scan confirms zero fencing resources remain. The
reconciled NetworkPolicies were retained (independently correct; drift check
passes). Post-rollback: both Asterisk Pods same UIDs and Ready, replicas 1/1,
zero fence/verify/pending operations, `conference_runtime_bindings` unchanged
(102, max updated_at 2026-07-18 01:20:42+00), leases claimed, one open epoch
per node, tree clean, `UTCP_PHASE=T1`.

### Required bounded correction (for Codex — T5-A20)

One-file manifest change in
`infrastructure/kubernetes/components/runtime-fencing/infrastructure-worker-deployment.yaml`:
make the projected CA readable by the UID-33 process without `fsGroup` and
without loosening the token — set an explicit per-item `mode: 0444` on the
`kube-root-ca.crt` ConfigMap projection (the CA is public material; kubelet
keeps the token itself 0600 owner-uid-33 regardless), or equivalently raise the
projected `defaultMode` to 0444 relying on the kubelet token override. Extend
`RuntimeFencingManifestTest`/`scripts/runtime-engine/config-check` to assert
the CA projection mode is world/user-readable and the token source remains a
projected `serviceAccountToken` with audience `https://kubernetes.default.svc`.
No RBAC, image, label, NetworkPolicy, or handler change. After that fix,
re-run T5-A19's activation sequence unchanged (policies are already
reconciled live).

## T5-A20 — Projected Kubernetes CA readable by non-root fencing worker (repository correction)

Repository-only correction at `UTCP_PHASE=T1` addresses the T5-A19 live blocker
without activating the worker and without applying live fencing resources.

### Proven defect

The live T5-A19 activation proved the dedicated fencing worker could reach DNS,
PostgreSQL, Redis, Laravel bootstrap, the fence-only polling loop, and the
Kubernetes API network path. The read-only Kubernetes-client probe still failed
before issuing an API request because
`HttpKubernetesWorkloadClient::caPath()` requires
`/var/run/secrets/kubernetes.io/serviceaccount/ca.crt` to be readable. The
projected ServiceAccount token was readable by UID 33 with credential mode
`0600`, but the ConfigMap-sourced CA file rendered from `kube-root-ca.crt`
was `root:root` mode `0440` and unreadable by the UID/GID `33:33` worker.

### Correction

`infrastructure/kubernetes/components/runtime-fencing/infrastructure-worker-deployment.yaml`
now sets per-item `mode: 0444` only on the `kube-root-ca.crt` ConfigMap item:

- ConfigMap: `kube-root-ca.crt`
- key: `ca.crt`
- path: `ca.crt`
- mode: `0444`

The CA certificate is trust material used to verify the internal Kubernetes API;
it is not the bearer authentication credential. The ServiceAccount token remains
a short-lived projected `serviceAccountToken` with path `token`, audience
`https://kubernetes.default.svc`, expiration `3600`, no explicit item mode, and
the projected volume default remains `0440`. The credential volume is still
mounted read-only at `/var/run/secrets/kubernetes.io/serviceaccount`, matching
the production client defaults for token and CA paths.

### Preserved boundaries

No TLS verification bypass, empty CA fallback, host CA fallback, root execution,
init container, `chown`, `chmod`, `fsGroup`, duplicate CA ConfigMap, CA Secret,
second Kubernetes client, feature gate, allowlist, manual activation switch, RBAC
change, NetworkPolicy change, or operation-routing change was introduced.
`automountServiceAccountToken: false`, UID/GID `33:33`, `runAsNonRoot: true`,
dropped capabilities, `allowPrivilegeEscalation: false`, `RuntimeDefault`
seccomp, cross-namespace RBAC, fencer NetworkPolicy labels, and fence-only
operation routing remain intact.

### Repository proof

Focused manifest tests and `scripts/runtime-engine/config-check` parse the
rendered runtime-fencing component and assert the CA projection source, key,
path, and per-item `0444` mode; unchanged ServiceAccount token projection;
non-`0444` projected-volume default; read-only mount; production client path
alignment; UID/GID and hardening; unchanged RBAC; unchanged NetworkPolicy labels;
and unchanged operation routing.

Non-destructive validation rendered
`infrastructure/kubernetes/components/runtime-fencing/` and confirmed the worker
contains CA item mode `0444`, `runAsUser: 33`, `runAsGroup: 33`,
`runAsNonRoot: true`, and `automountServiceAccountToken: false`. Server-side
dry-run was performed against `.runtime/kubeconfig/utcp-local.yaml`, context
`k3d-utcp-local`, for the runtime-fencing component. No Kubernetes resource was
applied, no RBAC was created, no worker was deployed, no Deployment was scaled,
no Pod was deleted, no runtime-fence operation was created, and no failover
occurred. Endpoint-targeted NetworkPolicies remain reconciled live from T5-A19.

Live worker activation and production Kubernetes-client GET/LIST proof remain
pending; this repository-only correction does not claim that the production
client has successfully called Kubernetes.

## T5-A21 — CA fix proven live; production client blocked by rejected token audience

Checkpointed live execution at `UTCP_PHASE=T1` from HEAD `40e313d`. The T5-A20
CA projection fix works exactly as designed, and the full network + TLS + RBAC
path is now proven end-to-end from the real worker — but the production probe
still fails because the k3s API server rejects the committed projected-token
audience. Per the rollback contract the worker and RBAC were removed; the
endpoint-targeted NetworkPolicies remain live and current. No fence operation,
scale, Pod deletion, binding mutation, or failover occurred; both Asterisk Pod
UIDs are unchanged.

### Baseline and preconditions

Clean tree at `40e313d`; both Asterisk Deployments 1/1 with unchanged Pod UIDs
(`030c033c-…45dd`, `3b9121dc-…4671e`); Services isolated per node; both
RuntimeNodes `active|ready` (fresh); both leases claimed/unexpired; one open
connection epoch per node; zero open conferences; zero bridges/channels on both
nodes; zero fence/verify/pending operations; fencing resources absent; status
targets passed. API endpoint rediscovered via canonical helpers:
`172.24.0.5:6443` (one IP, one port); `KUBERNETES_SERVICE_HOST=10.43.0.1:443`.
All three endpoint-targeted policies live at `172.24.0.5/32:6443`;
`check-apiserver-policy-drift` passed; no ClusterIP fallback or broad CIDR
anywhere; `default-deny` intact; zero pods matched the fencer selector; live
ordinary-worker apiserver connect refused (errno 111).

### Image currency, render, RBAC

`f8068ba..40e313d` touches no runtime application code; the deployed registry
image contains both artisan commands, `HttpKubernetesWorkloadClient`, and
`InfrastructureConnectivityProbe`. Render (registry transform) produced exactly
the 4 canonical objects with `defaultMode: 288` (0440), `ca.crt` item
`mode: 292` (0444), token projection unchanged (`path: token`, audience
`https://kubernetes.default.svc`, 3600s); server-side dry-run passed.
SA+Role+RoleBinding applied; impersonation matrix again exactly the approved
boundary (deployments get/list, scale get/patch, pods get/list in
`utcp-runtime`; everything else denied including create/delete deployments).

### Worker deployment and CA readability (T5-A20 fix VERIFIED)

Worker rolled out 1/1 (same benign single first-second restart from kube-router
ipset programming lag; clean bootstrap after). Verified in-pod as uid=33:

```text
..data/token  mode=600 uid=33 gid=0  → token_readable=yes
..data/ca.crt mode=444 uid=0  gid=0  → ca_readable=yes
```

### Production probe result and isolation

`php artisan runtime-engine:infrastructure-probe --once` (twice):
`infrastructure_probe_status=failed reason=permission_denied`. The client maps
both 401 and 403 to `permission_denied`; direct in-pod requests over the
identical CA-verified TLS path show the real status:

```text
/version                 => HTTP/1.1 401 Unauthorized
GET  deployments/asterisk-ari => HTTP/1.1 401 Unauthorized
LIST pods                => HTTP/1.1 401 Unauthorized
projected token aud      => ["https://kubernetes.default.svc"]
projected token sub      => system:serviceaccount:utcp-platform:utcp-runtime-fencer
issuer                   => https://kubernetes.default.svc.cluster.local
```

TLS verification against the projected CA succeeded (the 401 is an HTTP-layer
response through a verified session); authentication is the sole failure. The
cluster's accepted audiences, from a default `kubectl create token` for the
fencer SA: **`["https://kubernetes.default.svc.cluster.local", "k3s"]`**. The
committed audience `https://kubernetes.default.svc` is not in the set — k3s
derives its API audience from the issuer, which includes the
`.cluster.local` suffix.

### Positive control — accepted audience succeeds end-to-end

A bounded 600-second `kubectl create token utcp-runtime-fencer -n utcp-platform
--audience=https://kubernetes.default.svc.cluster.local` piped via stdin into
the worker Pod (never printed) over the same CA-verified TLS path:

```text
/version                            => 200 OK
GET  deployments/asterisk-ari      => 200 OK  (desired=1, readyReplicas=1)
LIST pods?labelSelector=utcp.dev/runtime-node=local-asterisk-ari
                                    => 200 OK  (owned_pod_count=1)
GET  secrets                        => 403 Forbidden  (RBAC boundary holds)
```

Every layer — fencer NetworkPolicy, DNS-free endpoint dialing, TLS with the
projected CA, ServiceAccount identity, narrow RBAC — is proven good. The
projected-token audience string is the only remaining blocker.

### Rollback performed (per contract)

`Deployment/utcp-runtime-fence-worker` → `RoleBinding` → `Role` →
`ServiceAccount` deleted; scan confirms zero fencing resources. NetworkPolicies
retained (drift check passes). Post-rollback: both Asterisk Pods same UIDs and
Ready, replicas 1/1, zero fence/verify/pending operations,
`conference_runtime_bindings` unchanged (102 / 2026-07-18 01:20:42+00), leases
claimed, one open epoch per node, tree clean, `UTCP_PHASE=T1`.

### Required bounded correction (for Codex — T5-A22)

The repository correction must remove the explicit projected-token audience
rather than replacing it with a k3s-specific literal. Kubernetes makes
`serviceAccountToken.audience` optional and defaults omitted projected-token
audience to the API server identifier, which is the portable contract for this
worker because the token is used only for Kubernetes API requests. Keep
`path: token`, `expirationSeconds: 3600`, CA item mode 0444, and defaultMode
0440. Update `RuntimeFencingManifestTest` and
`scripts/runtime-engine/config-check` to assert audience omission and reject
cluster-specific audience literals or fallback behavior. No RBAC, image, label,
NetworkPolicy, client, or handler change. Then re-run the T5-A21 activation
sequence unchanged; the expected outcome is the full production
`runtime-engine:infrastructure-probe` GET/LIST success.

## T5-A22 — Default Kubernetes API audience for projected fencing token

Repository-only correction at `UTCP_PHASE=T1` removes the remaining production
authentication blocker without activating the worker and without applying live
RBAC or fencing resources.

### Proven defect

The production worker token requested the explicit audience
`https://kubernetes.default.svc`, while the current k3s API server accepted
`https://kubernetes.default.svc.cluster.local` and `k3s`. The worker therefore
received HTTP 401 before Kubernetes authorization. A bounded positive control
using an accepted audience for the same ServiceAccount, CA, NetworkPolicy,
endpoint, and RBAC proved Deployment GET and owned-Pod LIST returned 200, while
Secret GET returned 403. That isolated the projected-token audience string as
the remaining blocker.

### Correction

`infrastructure/kubernetes/components/runtime-fencing/infrastructure-worker-deployment.yaml`
now omits `serviceAccountToken.audience` so Kubernetes defaults the projected
token audience to the API server identifier. The token projection remains:

```yaml
serviceAccountToken:
  path: token
  expirationSeconds: 3600
```

No replacement literal was added. In particular, the repository does not pin the
worker to `https://kubernetes.default.svc.cluster.local`, `k3s`, a second
audience, an environment-rendered audience, or retry/fallback behavior. This
keeps token audience distinct from TLS peer naming, Service DNS, ServiceAccount
issuer, Service ClusterIP, and the endpoint-targeted NetworkPolicy IP.

### Preserved boundaries

The token remains a short-lived projected ServiceAccount credential at
`/var/run/secrets/kubernetes.io/serviceaccount/token`, with kubelet rotation and
`expirationSeconds: 3600`. The CA remains the projected `kube-root-ca.crt`
ConfigMap item at `ca.crt` with per-item mode `0444`, mounted read-only under
`/var/run/secrets/kubernetes.io/serviceaccount`; the projected volume default
remains `0440`. `automountServiceAccountToken: false`, UID/GID `33:33`,
`runAsNonRoot: true`, dropped capabilities, `allowPrivilegeEscalation: false`,
`RuntimeDefault` seccomp, fencer NetworkPolicy labels, endpoint-targeted
NetworkPolicies, cross-namespace RBAC, and fence-only operation routing remain
unchanged.

### Repository proof

Focused manifest tests and `scripts/runtime-engine/config-check` parse the
rendered runtime-fencing component and assert exactly one ServiceAccount token
projection, absent audience, token path `token`, token lifetime `3600`,
read-only credential mount, volume default `0440`, CA item mode `0444`, no
cluster-specific audience literals, no audience environment configuration,
unchanged worker-only credential mount, unchanged hardening, unchanged RBAC,
unchanged NetworkPolicy labels, and unchanged operation routing.

Non-destructive validation rendered
`infrastructure/kubernetes/components/runtime-fencing/` and confirmed the worker
token projection omits `audience`, keeps `path: token` and
`expirationSeconds: 3600`, preserves CA item mode `0444`, preserves projected
volume defaultMode `0440`, and preserves `automountServiceAccountToken: false`.
Server-side dry-run was performed against `.runtime/kubeconfig/utcp-local.yaml`,
context `k3d-utcp-local`, for the runtime-fencing component. No Kubernetes
resource was applied, no RBAC was created, no worker was deployed, no Deployment
was scaled, no Pod was deleted, no runtime-fence operation was created, and no
failover occurred.

Live production Kubernetes-client GET/LIST proof remains pending; this
repository-only correction does not claim that the production probe has passed.

## T5-A23 — Production fencing-client Kubernetes GET/LIST proof COMPLETE; resources retained live

Checkpointed live execution at `UTCP_PHASE=T1` from HEAD `308bf93`. With the
T5-A22 audience correction (explicit audience omitted → kubelet requests the
API server's default audience), the production
`runtime-engine:infrastructure-probe` succeeded end-to-end using only the
committed worker manifest's projected token. All fencing resources are
RETAINED LIVE per the successful-state contract. No fence operation, scale,
Asterisk Pod deletion, binding mutation, or failover occurred.

### Baseline and preconditions

Clean tree at `308bf93`; both Asterisk Deployments 1/1 with unchanged Pod UIDs
(`030c033c-…45dd`, `3b9121dc-…4671e`); per-node Service isolation; both
RuntimeNodes `active|ready` (fresh); leases claimed/unexpired; one open
connection epoch per node; zero open conferences; zero bridges/channels; zero
fence/verify/pending operations; fencing resources absent; all status targets
passed. API endpoint rediscovered: `172.24.0.5:6443` (single IP/port);
`KUBERNETES_SERVICE_HOST=10.43.0.1:443`. All three endpoint policies live at
`172.24.0.5/32:6443`; drift check passed; no ClusterIP fallback or broad CIDR;
default-deny intact; zero fencer-selector pods; live ordinary-worker apiserver
connect refused (errno 111). Image current (`e5ed831..308bf93` is
manifest/test/docs/config-check only; both artisan commands and both client
classes present in the deployed registry image).

### Render, dry-run, RBAC

Render (registry transform) produced exactly the 4 canonical objects;
**no `audience:` key anywhere in the render**; `defaultMode: 288` (0440),
`ca.crt` item `mode: 292` (0444), `path: token`, `expirationSeconds: 3600`;
both Pod labels; UID/GID 33; server-side dry-run passed. SA+Role+RoleBinding
applied; impersonation matrix again exactly the approved boundary.

### Worker deployment

Rolled out 1/1 with the by-now-characterized single first-second restart
(kube-router ipset programming lag for the new Pod IP; kernel-level, not a
manifest defect) and stayed Running/Ready with restart count fixed at 1 through
final acceptance. Clean bootstrap: PostgreSQL and Redis connected, fence-only
polling loop entered, correct SA, hardening intact, no operation created, no
scale invoked, no secret material in logs.

### Projected files and token contract (proven)

```text
uid=33(www-data) gid=33(www-data)
..data/token  mode=600 uid=33 → token_readable=yes
..data/ca.crt mode=444 uid=0  → ca_readable=yes
sub => system:serviceaccount:utcp-platform:utcp-runtime-fencer
iss => https://kubernetes.default.svc.cluster.local
aud => ["https://kubernetes.default.svc.cluster.local","k3s"]   (accepted set)
exp-iat => 3600s
```

### Production probe — SUCCESS (both runs identical)

```text
php artisan runtime-engine:infrastructure-probe --once   (exit 0, twice)
runtime_node_slug=local-asterisk-ari-b
namespace=utcp-runtime
deployment=asterisk-ari-b
desired_replicas=1
status_replicas=1
available_replicas=1
owned_pod_count=1
```

Deterministic RuntimeNode selection, canonical workload metadata resolution,
CA-verified TLS, default-audience authentication, Deployment GET, and
owned-Pod LIST all through the production `HttpKubernetesWorkloadClient` with
the manifest-mounted token — no audience override, no alternate token, no
legacy Secret, no host-CA fallback, no TLS bypass, no retry logic. Output
non-secret. Two consecutive runs prove stable per-request token loading.

### Authorization separation (real mounted token, SelfSubjectAccessReview)

```text
allowed:  get deployments (utcp-runtime), list pods, patch deployments/scale
denied:   get secrets, delete pods, patch full deployments,
          get deployments in utcp-platform
```

Authentication and authorization are cleanly separated: the token
authenticates; RBAC still bounds it to exactly the fence-scope reads and the
scale subresource.

### Operation routing and destructive-behavior absence

`runtime-engine:command-worker` claims with
`excludeOperationTypes: [runtime_fence]`;
`runtime-engine:infrastructure-worker` claims with
`includeOperationTypes: [runtime_fence]` — disjoint by construction. Zero fence
operations ever; the worker idles; scheduler created nothing; Asterisk replicas
stayed 1/1; Pod UIDs unchanged; `conference_runtime_bindings` unchanged
(102 rows, max updated_at 2026-07-18 01:20:42+00); zero failovers.

### Live resource retention (successful-state contract)

Retained live: `NetworkPolicy/allow-runtime-fencer-kubernetes-api` (and the
Traefik/observability endpoint policies), `ServiceAccount/utcp-runtime-fencer`
(utcp-platform), `Role`+`RoleBinding/utcp-runtime-fencer` (utcp-runtime), and
`Deployment/utcp-runtime-fence-worker` (1/1 Ready, idle). No rollback was
required. The five-defect activation chain (namespace → runtime UID →
NetworkPolicy egress → CA file mode → token audience) is closed: the fencing
worker is live with proven production Kubernetes read access.

## T5-A24 — Destructive failover and restoration proof definition (evidence-only)

Evidence-only audit at `UTCP_PHASE=T1`, HEAD `d004379`, clean tree. No scale,
fence operation, Pod deletion, binding mutation, Conference creation, or
failover was performed. Baseline: both nodes `active|ready`, Services isolated,
leases claimed, one open epoch per node, fence worker Ready/idle (restart count
stable at 1), drift check passing, zero open conferences, zero bridges/channels,
zero fence/verify/pending operations, all 102 active bindings reference
closed/closed conferences only (excluded from candidacy by
`conferences.desired_state='open'`).

### Complete failover state machine (traced, exact)

Sweep entry: `Schedule::command('telephony-domain:failover-coordinator --once')`
everyMinute (console.php:862) → `ConferenceFailoverCoordinator::sweepOnce`
(batch ≤ `runtime_engine.batch_size`, grace =
`runtime_engine.stale_observation_seconds` = 300s).

1. **Candidate claim** (`claimCandidates`, one tx, `FOR UPDATE SKIP LOCKED`):
   `conferences.desired_state='open'` ∧ binding `status='active'` (tenant-scoped
   joins) ∧ bound `runtime_nodes.observed_state ∈ {unavailable, stale}` ∧
   EXISTS ready `runtime_observations.received_at ≤ now-300s` ∧ NOT EXISTS
   ready observation `> now-300s`, ordered by conference id.
2. **Verification gate** (`RuntimeFencingCoordinator::evaluate`, serialized tx,
   row locks conference→binding→node; re-checks open/active/unchanged/qualifying
   /grace): idempotently creates `runtime.node.verify_conference_absent`
   (idempotency key = sha256(conference:node:binding:generation), payload
   carries conference_id, former_runtime_binding_id, former_runtime_node_id,
   configuration_generation; `runtime_node_id` = former node) → status
   `verification_requested`.
3. **Verification execution**: the generic `telephony-command-worker`
   (`excludeOperationTypes=[runtime.node.runtime.fence]`, console.php:315-ish)
   claims it; `AsteriskRuntimeAdapter::verifyConferenceAbsent` (line 335) calls
   ARI `conferenceRuntimeSummary` against the former node: bridge present →
   result `present`; any admitted participant channel present → `present`;
   neither → `absent`. Completion emits outbox/audit
   `conference.runtime_fence_verified` with `verification_result`.
   ARI unreachable → retryable failure → operation `retry_scheduled`.
4. **Classification on later sweeps** (`classifyExistingVerificationOperation`):
   pending/leased/running → `verification_waiting`; **retry_scheduled →
   escalate to fence (`verification_unavailable`)**; succeeded+`absent` →
   `absent_verified` (skip fence, rebind directly); succeeded+`present` →
   `former_runtime_present` → fence; terminal_failed → `verification_failed`
   (no rebind).
5. **Fence request** (`requestRuntimeFence`, re-gated in a fresh locked tx):
   idempotently creates `runtime.node.runtime.fence` (same key recipe; payload
   adds `verification_operation_id`, `fence_reason`).
6. **Fence execution**: only the dedicated worker
   (`runtime-engine:infrastructure-worker`,
   `includeOperationTypes=[runtime.node.runtime.fence]`, deployed as
   `utcp-runtime-fence-worker`) claims it.
   `RuntimeFenceOperationHandler::execute`: payload/node-identity validation →
   `operationAuthorityCurrent` (conference open, still bound to former node,
   generation unchanged, binding still active) → **replacement-before-fence
   guard** `hasDistinctEligibleReplacement` (distinct active+ready node with
   `conference.lifecycle`+`conference.participation`; fails retryable
   `no_replacement_available`) → `KubernetesRuntimeFenceAdapter::fence`:
   resolve `labels.kubernetes_workload` via `RuntimeNodeWorkloadIdentityResolver`
   (utcp-runtime ns enforced) → `getDeployment` → ownership validation
   (`isOwnedAsteriskDeployment`: part-of=utcp, component=asterisk-ari,
   `utcp.dev/runtime-node` = node slug) → **last-instant recovery check**
   (node observed ready → `target_recovered`, no mutation) → if desired>0:
   `scaleDeployment(ns, name, 0)` → re-GET + `listOwnedPods` → success only
   when desired=0 ∧ statusReplicas=0 ∧ availableReplicas=0 ∧ ownedPods=0
   (`fenced` / `already_fenced` when it was already 0); otherwise
   `fence_in_progress` (retryable — terminating Pods block completion).
   Completion emits `conference.runtime_fence_terminated` with `fence_result`
   and workload details.
7. **Rebind** (only from `absent_verified` or `external_runtime_fenced`):
   `TelephonyDomainService::failoverRebindConferenceAfterFence` →
   `failoverRebindConference` in ONE transaction with locks: re-validate
   open/active/expected binding+node/qualifying state/grace →
   `validateFailoverFenceEvidence` (operation row: tenant/aggregate/node/status
   succeeded; payload matches binding+node+**current generation**; outbox
   evidence matches and shows `absent` or `fenced|already_fenced` — else noop
   `fence_evidence_*`) → select replacement (deterministic:
   `orderBy runtime_family, adapter_key, id`, exclude former, desired ∈
   {active}, observed=ready, both conference capabilities) → retire old binding
   (`status='retired'`, `unbound_at`), insert new active binding (partial
   unique index `…one_active` guarantees single authority),
   `conferences.runtime_node_id` = replacement, `configuration_generation += 1`
   → `wakeTarget(conference)` + `wakeTarget` for every admitted participant →
   audit/outbox `conference.runtime_binding_replaced`.
8. **Reconstruction** (canonical reconciliation, no failover-specific code):
   `ConferenceReconciler` routes through `activeRuntimeNodeId` (now node B),
   inspects node B, bridge absent → dispatch `conference.ensure` (new
   generation) → `AsteriskAriClient::ensureConferenceBridge`: deterministic
   `bridgeId = conferenceBridgeId(conferenceId)`, read-first (`already_existed`
   + `assertOwnedBridge` duplicate/ownership check), else `POST /bridges`.
   `ConferenceParticipantReconciler` → `conference.participant.ensure` →
   `ensureParticipantChannel`: deterministic per-participant `channelId`,
   ARI originate of the configured Local proof endpoint into the Stasis app,
   `addChannel` with bounded conflict retries, read-after-write
   `participantAttachedToBridge` verification. Stale-generation operations
   against the former node complete as `*_stale` no-ops; the event fence
   (normalizer joins active binding node) structurally drops any late node-A
   events.

### Automatic trigger (exact)

The proof begins when the former node's control-plane evidence disappears:
listener WebSocket close → `connection_closed` → normalizer maps to
`unavailable` observation (AsteriskAriEventNormalizer:39) → projection sets
`runtime_nodes.observed_state='unavailable'` (or `markStale` flips it to
`stale` after 300s of silence). Failover fires only after **both**: observed
state ∈ {unavailable, stale} **and** the newest `ready` runtime observation is
≥ 300s old (`stale_observation_seconds`). With the everyMinute sweep, first
coordinator action lands ~5–6 minutes after ARI loss. First operation created:
`runtime.node.verify_conference_absent`. Duplicate chains are prevented by the
generation-scoped idempotency keys, existing-operation classification, the
serialized row-locked gates, and the one-active-binding unique index.

### Proof Conference contract (smallest sufficient)

Conference + bridge + **one participant** (ARI-originated Local channel). No
Kamailio, SIP, WebRTC, browser, or external endpoint is involved in T2
conference execution — participants are Local channels originated by ARI into
the Stasis app — so the smallest complete proof covers placement, live former-
node ownership, binding replacement, bridge reconstruction, and participant
reconstruction. Canonical creation path (exactly the committed
`scripts/asterisk-conference/runtime-proof` flow): authenticated login →
tenant-context → `POST /api/v1/admin/conferences` → `POST …/desired-state
{"open"}` (placement+binding happen automatically) → member telephony session →
`POST /api/v1/conferences/{id}/participants/self` → wait for
`conference.ensure` + `conference.participant.ensure` succeeded, projected
`ready`, converged reconciliation, live ARI bridge+channel. Placement is
deterministic but not selectable: the proof must read the bound
`runtime_node_id` from the active binding and treat THAT node as "node A".

### Former-node absence verification (ordering answered)

Verification can return `absent` while the former Pod is still running — that
is the safe fast path (ARI answered authoritatively: no bridge, no participant
channels), and rebind then proceeds WITHOUT fencing. Split-brain is prevented
because `absent` is an authoritative live read of the former node itself, the
rebind re-validates it against the unchanged generation, and late node-A
events/operations are fenced by binding + generation. When the bridge is still
present (`present`) or ARI is unreachable (retry_scheduled →
`verification_unavailable`), fencing happens BEFORE rebind, and the fence's
termination predicate (zero replicas AND zero owned Pods) guarantees the former
process is gone before placement moves. Persisted evidence: the operation row +
`conference.runtime_fence_verified` outbox/audit payload (`verification_result`,
`bridge_present`, `participant_channel_present`).

### Kubernetes scale-to-zero contract (exact, not executed)

Chain: `runtime.node.runtime.fence` op → dedicated worker →
`RuntimeFenceOperationHandler` (authority + replacement guards) →
`KubernetesRuntimeFenceAdapter::fence` → `HttpKubernetesWorkloadClient::scaleDeployment(ns, name, 0)`
(scale-subresource PATCH). Idempotent: already-zero → no second scale →
`already_fenced`; terminating Pod → `fence_in_progress` retry loop; recovered
node → `target_recovered` without mutation; wrong/unowned workload →
`target_mismatch` without mutation (test-proven, including
`old_pod_disappearing_while_new_owned_pod_exists_is_not_fenced`). Live proof
evidence: deployment desired/status/available = 0, owned-Pod list empty,
exactly one `conference.runtime_fence_terminated` event with
`fence_result=fenced`, exactly one scale in the operation history.

### RuntimeBinding replacement (exact)

Occurs strictly AFTER authoritative evidence (absent or fenced), inside one
transaction that retires the old binding, inserts the new active binding,
repoints `conferences.runtime_node_id`, and bumps `configuration_generation`.
Placement authority proof = old binding `retired`+`unbound_at`, new binding
`active` on node B, generation incremented, `conference.runtime_binding_replaced`
event. Single authority is DB-enforced (partial unique index). A replacement
binding CAN be committed before the new bridge exists — by design; the wake →
reconcile → ensure loop converges it, and a reconstruction failure leaves the
target `waiting`/`blocked` with the binding still authoritative on node B
(forward recovery, never rollback to the fenced node).

### Participant reconstruction decision

**Include one participant.** Recovery is already deterministic and canonical:
admitted participants are woken at rebind, re-ensured on the new node with
deterministic channel ids, conflict-bounded attach, and read-after-write
verification; `AsteriskConferenceRecoveryTest` covers repair, staleness, event
fencing, and duplicate prevention. Nothing about participants requires SIP or
Kamailio in T2.

### Former-node restoration — decision C

**C. A bounded one-time proof restoration is acceptable; production
restoration is still missing.** No code path in the repository scales a
Deployment above zero (`scaleDeployment` is invoked only with 0 by the fence
adapter); no recovery operation type exists. For the proof: one exceptional
documented `kubectl scale deployment/<node-A> --replicas=1` restores the
workload; the listener re-claims automatically (eligibleNodes iterates all
nodes), a fresh connection epoch opens, observations project `ready`, and the
node becomes eligible for FUTURE conferences only. Reclaim of the moved
conference is structurally impossible: its active binding points at node B,
node A's binding is retired, the generation moved, the fence destroyed the old
bridge (fresh Asterisk starts empty), and the event fence drops node-A
conference events. Later implementation requirement (post-proof, bounded): a
canonical restoration path (operator-driven API/desired-state driven
un-fencing) plus stale fence-evidence cleanup — do not build it before the
first destructive proof.

### Failure and rollback checkpoints

1. Conference without bridge → reconciler retries ensure; abort proof by
   closing the conference (rollbackable).
2. Bridge without participant → participant reconciler retries; rollbackable.
3. Unavailability but no candidate → diagnose candidate prerequisites
   (observed_state, ready-observation age, open state, active binding);
   coordinator emits `conference.failover_coordinator.*` audit only on
   decisive outcomes; no state changed — rollback = restore node A.
4. Verification repeatedly retrying → escalates to fence by design
   (`verification_unavailable`); terminal_failed → `verification_failed`, no
   rebind — investigate, restore node A (rollbackable).
5. Fence fails before scale (`no_replacement_available`,
   `unavailable_to_control`, `permission_denied`, `target_mismatch`) → no
   mutation occurred; binding intact; rollbackable by restoring node A.
6. Scale succeeded, Pod remains → `fence_in_progress` retries until the
   termination predicate holds; no rebind can occur early — wait or restore.
7. Fenced but rebind not yet committed (crash window) → forward recovery: the
   fence evidence is persisted; the next sweep re-classifies
   `external_runtime_fenced` and rebinds idempotently.
8. Rebind committed, bridge reconstruction fails → forward recovery on node B
   (retry/blocked visibility); never roll back to fenced node A.
9. Participant recovery fails → forward recovery via participant target
   (blocked state is explicit); conference bridge remains valid.
10. Node B fails during reconstruction → coordinator treats node B as the new
    bound unavailable node; with node A fenced there is no distinct ready
    replacement, so the replacement-before-fence guard blocks further fencing
    (`no_replacement_available`, retryable) — the conference waits for any
    node to return (known replacement-node-failure gap, not part of this
    proof's acceptance).
11. Node-A restoration fails → conference is unaffected on node B;
    infrastructure issue only.
12. Node A returns post-restoration → cannot reclaim: retired binding, moved
    generation, empty fresh Asterisk, event fence.

Rollbackable: 1–5 (and 6 by waiting), plus any pre-fence stop = close proof
conference + restore replicas. Forward-only: 7–9 (placement authority already
moved). Never manually rewrite RuntimeBindings.

### Required proof evidence (all existing observability)

Coordinator artisan summary + `conference.failover_coordinator.*` audit/outbox
rows; `runtime_operations` rows for verify + fence (status transitions, single
occurrence, `last_failure_code` history); `conference.runtime_fence_verified`
and `conference.runtime_fence_terminated` payloads;
`conference.runtime_binding_replaced` payload (previous node, new node,
generation); `conference_runtime_bindings` before/after rows; deployment
replica + owned-Pod observations; `runtime_operation.asterisk_conference_ensured`
/ `…participant_ensured` events on node B with the new generation; live ARI
bridge/channel reads on node B and absence on restored node A; listener
lease/epoch rows for node A after restoration. Non-blocking future metrics
(dashboards/counters for fence latency, coordinator outcomes) remain a
retained gap; nothing material to trustworthy proof is missing.

### Test coverage matrix (all suites passing at HEAD)

| Behavior | Test | Live proof still needed |
|---|---|---|
| Candidate selection + grace + exclusions | TelephonyDomainTest:526–697 (sustained, within-grace, degraded/connecting excluded, draining replacement excluded) | yes (real observations) |
| Verification ordering / no direct rebind | :684–747, :996 (`no_direct_rebind_path`) | yes |
| Fence-evidence authority (stale binding/generation rejected) | :830–994 | yes |
| Replacement-before-fence | KubernetesRuntimeFenceAdapterTest:140–224 | yes |
| Scale idempotency / in-progress / mismatch / recovered | KubernetesRuntimeFenceAdapterTest:45–138 | yes (first real scale) |
| Binding transition atomicity / one winner | TelephonyDomainTest:307–524 | yes |
| Bridge/participant reconstruction + duplicates + event fencing | AsteriskConferenceRecoveryTest (30 tests, incl. :970, :1039) | yes |
| Restoration | none (no code path) | bounded kubectl step |
| Replacement-node failure | guard tests only | out of scope for first proof |

### Readiness decision

**A — ready for Claude Code controlled destructive failover proof.** Every
lifecycle stage is implemented, evidence-validated, and unit/feature-tested;
the only non-implemented stage (restoration) has an explicit bounded one-time
contract. The live proof's primary unavailability trigger: disable ARI inside
node-A's running container (`asterisk -rx "module unload res_ari.so"`), which
reproduces the exact split-brain hazard (process and bridge alive, control
plane blind) and exercises the REAL scale 1→0 mutation; fallback trigger if
the module cannot unload: scale node-A to zero as the outage simulation and
accept the `already_fenced` path. Both flow through canonical detection
(connection_closed → unavailable → grace → coordinator).

## T5-A25 — First live destructive failover attempt: BLOCKED by a coordinator defect (no fence fired)

Checkpointed destructive live proof at `UTCP_PHASE=T1`, HEAD `5c3bae1`. The
canonical proof Conference was created, a faithful split-brain ARI-loss trigger
was produced (workload alive, control-plane blind), sustained-unavailability was
detected canonically, and the coordinator automatically scheduled absence
verification — but the automatic lifecycle **dead-ended before any fence
operation was created**. A precisely-isolated coordinator defect prevents the
fence path from firing under a hard ARI outage. Per the "before the fence
mutation" divergence contract, the former node's ARI was restored in place (no
Pod deletion), the proof Conference was closed canonically, and both nodes were
returned to ready. **No scale-to-zero, no fence operation, no Pod deletion, no
binding mutation, no failover occurred.**

### Baseline

Clean tree at `5c3bae1`; both Asterisk Deployments 1/1 (`asterisk-ari` uid
`030c033c…45dd`, `asterisk-ari-b` uid `3b9121dc…4671e`); both RuntimeNodes
active/ready (`Local Asterisk ARI`=`1d15ca88…`, `Local Asterisk ARI B`=
`05ddb383…`); fence worker Ready/idle (restart count 1); drift check passing;
both leases claimed; one open epoch per node; zero open conferences, bridges,
channels, fence/verify/pending operations; 102 active bindings all closed/closed.

### Proof Conference (canonical API lifecycle)

conference `2d5a704b-5281-49ee-8b49-287d2c53e055`, participant
`3035abef-…0445`, active binding `6120c124-…5618`, bound node `05ddb383…`
(**Local Asterisk ARI B = former node**; replacement = node A `1d15ca88…`),
`configuration_generation = 2`. Verified live: `conference.ensure` and
`conference.participant.ensure` succeeded, projected `ready`, ARI bridge
`utcp-conf-2d5a704b…` present on node B with 2 active channels (the participant
Local channel legs). Created strictly through the admin/member API (no SQL).

### ARI-loss trigger (faithful split-brain)

`asterisk -rx "module unload res_ari.so"` was refused (use-count 7). Unloading
the ARI submodules first was attempted, but with the REST resource modules
unloaded, `/ari/bridges/{id}` returns HTTP 404, which the client's
`getAriResource` (accepts `[200,404]`) reads as `bridge_exists=false` — a **false
absence** that would make the coordinator rebind WITHOUT fencing. That is not a
faithful outage. The faithful trigger used instead: unload only
`res_ari_events.so` (the events WebSocket resource) and keep the REST stack, so
the listener WebSocket handshake fails (→ `connection_closed` → observed
`unavailable`) while the workload keeps hosting the conference. Verified after
the trigger, via the Asterisk **control socket** (independent of ARI): bridge
`utcp-conf-2d5a704b…` alive with 2 active channels; Pod UID unchanged;
Deployment replicas 1/1. Node B `observed_state` transitioned to `unavailable`
at 01:51:14 through canonical projection; the events WebSocket stayed down so no
new `ready` observation reset the grace clock (last ready `01:48:14`). In
practice the ARI HTTP REST transport also became genuinely unreachable to the
control plane (`ARI HTTP transport failed`, confirmed from
`telephony-command-worker`) — the strongest, most faithful form of the
scenario: the node still hosts the live bridge (proven by control socket) but
the control plane cannot reach ARI at all. No DB, lease, observed-state,
NetworkPolicy, scale, or Pod-delete action was used to produce the outage.

### Automatic detection and scheduling (observed, not assisted)

Grace (`stale_observation_seconds = 300`) crossed at 01:53:14. The everyMinute
`telephony-domain:failover-coordinator` sweep at **01:54:02** claimed the
candidate and automatically created exactly one
`runtime.node.verify_conference_absent` operation (coordinator outcome
`verification_requested`) — no operator action.

### Defect: verification terminal-fails past the escalation window → no fence

The `telephony-command-worker` executed the verification. Every ARI call failed
with `ari_http_transport_failed` (`FailureClass::RuntimeUnavailable`, retryable).
The operation exhausted `max_attempts = 3` and reached `terminal_failed` at
**01:54:49** — 47 seconds, entirely between coordinator sweeps.

`RuntimeFencingCoordinator::classifyExistingVerificationOperation` escalates to
fencing **only** from `RetryScheduled`
(`RetryScheduled => requestRuntimeFence(..., 'verification_unavailable')`), while
`TerminalFailed => ['status' => 'verification_failed']` is a dead end. Because
the every-minute coordinator never sampled the operation while it was briefly
`retry_scheduled`, and because the generation-scoped idempotency key
(`sha256(conference:node:binding:generation)` at unchanged generation 2) makes
`findIdempotent` return the same `terminal_failed` row on every later sweep, the
coordinator emitted `verification_failed` at 01:55:03, 01:56:02, and 01:57:02 —
an indefinite dead-end loop. **Zero `runtime.node.runtime.fence` operations were
ever created; zero scale mutations; node B stayed at replicas 1/1.**

Test masking: `TelephonyDomainTest::test_unavailable_absence_verification_requests_external_fence_without_rebinding`
proves escalation works, but it **manually forces** the verify operation into
`retry_scheduled` before the second sweep. Production timing never satisfies that
precondition — the retries exhaust into `terminal_failed` within one worker
window — so the passing test does not cover the real hard-outage path.

### Precise defect and bounded fix (for the next bounded task)

The escalation-to-fence decision is coupled to catching the verify operation in
the transient `retry_scheduled` state, which is unreachable for a sustained ARI
outage under `max_attempts = 3` + every-minute sweeps. Bounded correction (exact
site): in `RuntimeFencingCoordinator::classifyExistingVerificationOperation`, a
`TerminalFailed` verify operation whose `last_failure_class` is
`runtime_unavailable`/`timeout` (retryable/unavailability codes such as
`ari_http_transport_failed`, `ari_http_unavailable`, `ari_connection_timeout`,
`ari_connection_failed`) must escalate to `requestRuntimeFence(...,
'verification_unavailable')` — identical to the `RetryScheduled` branch — rather
than dead-ending as `verification_failed`. Only genuinely non-recoverable
terminal failures (invalid payload, `absence_verification_context_not_found`)
should remain `verification_failed`. Add a test that runs the real sweep path to
`terminal_failed` (not a forced `retry_scheduled`) and asserts one
`runtime.node.runtime.fence` is created. No change to the fence handler,
adapter, RBAC, NetworkPolicy, or scale logic — those were already proven ready
in T5-A24/T5-A23. (Optionally, the transport-failed verify `max_attempts`/backoff
could be widened so the coordinator can also catch `retry_scheduled`, but the
classifier escalation is the primary, deterministic fix.)

### Divergence handling performed (before any fence mutation)

Per the contract, because no fence mutation had occurred: (1) node B ARI was
restored in the existing Pod via `asterisk -rx "core restart now"` (in-place
process restart — Pod UID `3b9121dc…4671e` unchanged, container restart count
1→ increment, no Pod deletion, no scale) after module reload alone did not
recover the degraded HTTP transport; node B re-claimed its lease, opened a new
event epoch, projected `ready`, and started clean (0 channels, orphan bridge
gone); (2) the proof Conference was closed through the canonical API (participant
remove → desired-state closed) and converged to `observed_state=closed`; (3)
both nodes returned active/ready. The fence worker was never invoked and remains
Ready/idle.

### Final state (restored)

Both Deployments 1/1 with **unchanged Pod UIDs** (`030c033c…45dd`,
`3b9121dc…4671e`); both RuntimeNodes active/ready; both leases claimed; one open
epoch per node; zero open conferences; zero live bridges/channels on both nodes;
zero pending operations; zero fence operations ever; 102 active bindings still
all closed/closed; tree clean; `UTCP_PHASE=T1`. No manual scale-to-zero, no Pod
deletion, no direct operation/binding/observed-state/lease write occurred.

### Readiness re-assessment

The T5-A24 readiness decision assumed the coordinator would escalate a hard ARI
outage to fencing; this live proof shows it does not, because verification
terminal-fails past the only escalation window. The infrastructure (fence
worker, RBAC, scale-to-zero, rebind, reconstruction) remains proven-ready; the
single blocking gap is the coordinator classifier above. The next step is the
bounded Codex fix, then a re-run of this exact T5-A25 destructive proof.

## T5-A26 — Exhausted verification unavailability now escalates to runtime fencing

Repository correction at `UTCP_PHASE=T1`, starting from HEAD `88c0ffd`. No live
Kubernetes resource was applied, no Deployment was scaled, no Pod was deleted, no
runtime-fence operation was created against live data, and no failover was run.

### Coordinator sampling race corrected

T5-A25 proved that a hard ARI outage can complete all three
`runtime.node.verify_conference_absent` retries between two every-minute
coordinator sweeps. The coordinator therefore may see only the persisted
`terminal_failed` operation, not its intermediate `retry_scheduled` state. The
repository correction updates
`RuntimeFencingCoordinator::classifyExistingVerificationOperation` so a terminal
verification operation with a fence-safe unavailability taxonomy now converges
on the same `verification_unavailable` runtime-fence request path as the
existing `RetryScheduled` branch.

### Terminal retryable-unavailability taxonomy

The terminal escalation is deliberately narrow. Fence eligibility is limited to
the structured failure classes that mean the former runtime could not be reached
or timed out during absence verification:

- `transient_transport`
- `runtime_unavailable`
- `timeout`

These cover the live evidence family such as `ari_http_transport_failed`,
`ari_http_unavailable`, `ari_connection_timeout`, and
`ari_connection_failed`. Unknown terminal classifications, invalid request
failures, authorization failures, unsupported contexts, malformed evidence, and
plain `internal_error` remain `verification_failed` and do not fence silently.

### Authority re-gates before fencing

Before a terminal verification result can request fencing, the coordinator still
revalidates the normal candidate query and the serialized operation authority:
the Conference must still exist and be open; the active binding must still
reference the same former RuntimeNode; the binding ID, tenant, Conference,
RuntimeNode, aggregate, and configuration generation embedded in the
verification operation must still match the locked Conference and binding; the
former RuntimeNode must still qualify as unavailable or stale; a distinct
same-tenant replacement RuntimeNode must still be active, ready, and carry the
required conference capabilities; and the existing generation-scoped
runtime-fence idempotency key must not already be owned by a non-terminal fence
operation. Recovery, closure, binding replacement, generation advancement, or
replacement loss prevents stale terminal verification evidence from fencing.

### Existing fence-request path reused

The fix does not add a second fence payload builder or operation authority.
`RetryScheduled` and terminal exhausted unavailability both call the existing
`requestRuntimeFence(..., reason: verification_unavailable)` path. The
coordinator remains the sole scheduler of `runtime.node.runtime.fence`; the
dedicated infrastructure worker remains the executor; and
`KubernetesRuntimeFenceAdapter` remains the only Kubernetes scale
implementation.

### Idempotency behavior

The terminal verification operation is retained as canonical evidence and is not
deleted or recreated. The first eligible post-terminal sweep creates exactly one
generation-scoped `runtime.node.runtime.fence` operation. Later sweeps classify
the existing fence chain as waiting and do not create duplicate verification
operations, duplicate fence operations, duplicate idempotency keys, or duplicate
outbox intents.

### Focused regression coverage

Repository tests now cover the real timing-independent sweep path:
coordinator creates the verification operation; the worker exhausts retryable
ARI transport failures through normal operation handling; the operation reaches
`terminal_failed`; the next coordinator sweep creates one runtime-fence operation
with reason `verification_unavailable`. Additional coverage proves repeated
sweep idempotency; preserved `RetryScheduled` compatibility; non-retryable,
unknown, and non-unavailability terminal failures remain non-fenceable;
Conference closure, binding replacement, generation advancement, RuntimeNode
recovery, and replacement loss prevent stale fencing; and
replacement-before-fence behavior remains in force.

### Architecture boundaries preserved

No fence handler, Kubernetes adapter, Kubernetes client, RBAC, NetworkPolicy,
fencing-worker Deployment, operation routing, scheduler cadence, scale behavior,
RuntimeBinding replacement, bridge reconstruction, participant reconstruction,
Asterisk manifest, or RuntimeNode projection changed. No direct Kubernetes call,
generic-worker fence execution, new operation type, manual operation API,
Artisan management path, feature gate, RuntimeNode allowlist, direct
RuntimeBinding write, direct observed-state write, or fallback for unknown
failure classes was introduced.

### Retained ARI false-404 hardening gap

T5-A25 also proved a separate ARI semantics gap: unloading REST resource modules
can make `/ari/bridges/{id}` return 404 while the bridge remains alive. That
false-404 case is not changed by T5-A26. Authoritative bridge absence must still
eventually distinguish a healthy bridge API returning resource-not-found from an
unavailable or missing ARI resource route. The next destructive proof should
reuse the transport-unavailable ARI-event trigger, not the false-404 trigger.

### Remaining live proof gap

The repository correction restores the coordinator path that should lead to
runtime fencing, but no live destructive scale-to-zero or failover proof was
performed in this task. The next proof remains a controlled rerun of the T5-A25
transport-unavailable scenario, stopping only when canonical automatic fencing,
rebind, reconstruction, and restoration evidence are actually observed.

## T5-A27 — Failover rerun after the T5-A26 fix: BLOCKED by a trigger divergence (fix verified, precondition unreachable)

Checkpointed destructive rerun at `UTCP_PHASE=T1`, HEAD `db609cb`. The T5-A26
coordinator fix was verified present in code and **deployed live** (the API
image was rebuilt from the clean tree, pushed, and the failover-path
deployments rolled out), and it is comprehensively unit-tested. But the live
proof could not exercise it: the authorized `res_ari_events.so`-only trigger
**does not reproduce sustained RuntimeNode unavailability** on this Asterisk
build, so the coordinator never produced a failover candidate. Per the trigger-
divergence rule, the proof stopped before any fence condition, node B was
restored, and the proof Conference was closed canonically. **No fence
operation, no scale-to-zero, no Pod deletion, no binding mutation, no failover
occurred.**

### Fix deployment (prerequisite)

The running image predated `db609cb` (the classifier fix is application code,
not a manifest change). The API image was rebuilt from the clean tree
(`docker build --target app-prod`, verified to contain
`classifyTerminalFailedVerificationOperation`), pushed to
`127.0.0.1:5001/utcp/api:0.1.0-k1-dev`, and `scheduler`,
`telephony-command-worker`, `telephony-reconciler`, `telephony-event-normalizer`,
and `utcp-runtime-fence-worker` were rolled out. The fix was confirmed live in
the `scheduler` pod (which runs the coordinator sweep). Baseline otherwise
green: both nodes active/ready, fence worker Ready/idle, drift passing, zero
open conferences/failover operations.

### Proof Conference

conference `77c6610e-…f088c9`, participant `daa4e954-…f08770`, binding
`f106de92-…e9741`, bound node `05ddb383…` (Local Asterisk ARI B = former;
deployment `asterisk-ari-b`, Pod uid `3b9121dc…4671e`), generation 2; replacement
node A `1d15ca88…`. Live bridge `utcp-conf-77c6610e…` present on node B with 2
channels; node A empty. Created through the admin/member API.

### Trigger divergence (root cause)

`asterisk -rx "module unload res_ari_events.so"` on node B (03:00:30) unloaded
the events WebSocket resource cleanly. Immediately verified: Asterisk process
alive, replicas 1/1, Pod UID unchanged, bridge + 2 channels alive via the
**control socket**. But node B **stayed `ready`** for the full observation
window (03:00:45 through 03:03:36, 8 polling samples) — it never went
unavailable, so no failover candidate was ever produced.

Root cause, confirmed by code and live probes:

- Unloading `res_ari_events.so` does **not** close the listener's already-
  established ARI events WebSocket on Asterisk 20.20.1 — the existing session
  stays open.
- `AsteriskAriEventListener` detects loss only via (a) its periodic health check
  (`AsteriskAriClient::inspect` GET `/asterisk/info` + `stasisApplicationRegistered`
  GET `/ari/applications/{app}`) throwing, or (b) `readEvent` throwing.
- With only the events module unloaded, REST stays fully healthy: `inspect`
  returns 200, `stasisApplicationRegistered` returned **true** (app still
  subscribed via the still-open socket), and `conferenceRuntimeSummary` returned
  `bridge_exists=true, owned_bridge=true`.
- `readEvent` returns `null` (not an exception) on a quiet or EOF socket
  (`fread` empty → return null), so even a closed socket is not treated as a
  failure.

Net: the health check passes every cycle and the node remains `ready`
indefinitely. The event stream is silently dead, but nothing the listener
checks reflects that.

### Correction to the T5-A25 trigger characterization

T5-A25's node-B unavailability was **not** caused by the `res_ari_events` unload
in isolation, contrary to how §T5-A25 framed it. In T5-A25 the entire `res_ari`
submodule stack was cycled (unload-all, then reload REST), which unloaded
`res_ari_asterisk` (breaking the health check's `/asterisk/info` call) and left
the ARI HTTP handler degraded so that REST calls failed at the **connection
level** (`ari_http_transport_failed`, `file_get_contents` returning false). That
collateral **transport** degradation — not the events-module unload — is what
drove the node unavailable and made verification transport-fail. The clean,
authorized `res_ari_events`-only trigger produces neither.

### Why the authorized trigger cannot reach the precondition

The T5-A26 fix escalates a `verify_conference_absent` operation that reaches
`terminal_failed` with a retryable transport/unavailability failure class. To
reach that live requires ARI HTTP to be genuinely unreachable from the control
plane (connection-level failure for both the listener health check and the
verify REST calls), while the Asterisk process and bridge stay alive. On this
build that requires disabling the ARI HTTP server itself (e.g. `http.conf`
`enabled=no` + reload) or a connection-level fault injection — none of which is
among the authorized actions. The prohibited alternatives (unloading REST
bridge modules → false-404; NetworkPolicy; Pod/scale mutation) are exactly the
paths that would otherwise reproduce it. Therefore the precondition is
unreachable under the T5-A27 authorization envelope.

### Fix status (verified, not live-proven)

The T5-A26 correction is present and correct in `db609cb` and deployed live:
`classifyExistingVerificationOperation` now routes `TerminalFailed` through
`classifyTerminalFailedVerificationOperation`, which escalates to
`requestRuntimeFence(..., 'verification_unavailable')` when
`last_failure_class ∈ {transient_transport, runtime_unavailable, timeout}` and
authority/replacement still hold, and otherwise keeps `verification_failed`. It
is covered by 11 new focused tests
(`test_terminal_retry_exhausted_absence_verification_requests_external_fence_on_next_sweep`
and siblings) that drive a real `terminal_failed` verification to exactly one
`runtime.node.runtime.fence`, plus idempotency and authority/generation/recovery
guards. All suites pass at HEAD (telephony-domain 45, asterisk-conference 71).
Live end-to-end proof remains pending a faithful transport-outage trigger.

### Divergence handling performed (before any fence condition)

Per the "before scale-to-zero" contract: `res_ari_events.so` was reloaded on
node B to restore event flow (this momentarily flapped node B to `unavailable`
as the reload reset the WebSocket handler; it reconnected and returned to
`ready` by 03:05:03 — a harmless transient, last-ready only ~4 min old so the
300 s grace was never met and no candidate formed); the proof Conference was
closed canonically (participant remove → desired-state closed → converged
`closed` at 03:05:49). No manual scale, Pod deletion, operation/binding/observed-
state/lease write occurred. Node B Pod UID stayed `3b9121dc…4671e` throughout
(no restart this run).

### Final state (restored)

Both Deployments 1/1 with unchanged Pod UIDs (`030c033c…45dd`,
`3b9121dc…4671e`); both RuntimeNodes active/ready; both leases claimed; one open
epoch per node; zero open conferences; zero live proof bridges/channels; zero
pending operations; zero fence operations created for `77c6610e…`; tree clean;
`UTCP_PHASE=T1`. Fence worker Ready/idle.

### Required next step

A bounded task to establish a faithful, authorized ARI-transport-outage trigger
that preserves the Asterisk process and live bridge — for example disabling the
ARI HTTP server via `http.conf enabled=no` + `core reload` inside the former
Pod (connection-level failure, no REST-module unload, no false-404, no Pod/scale
mutation), or an equivalent supported fault injection — added to the authorized
action set. Then re-run this exact T5-A27 destructive proof; the deployed +
unit-proven T5-A26 fix is expected to escalate the transport-terminal-failed
verification to fencing and complete the lifecycle.

## T5-A28 — Asterisk HTTP-transport outage trigger VALIDATED (http.conf disable + core reload)

Narrow reversible evidence-only audit at `UTCP_PHASE=T1`, HEAD `bc5b935`. The
temporary `http.conf` disable trigger produces every required faithful-outage
property: ARI REST fails at the transport level (not a route-level 404), the
event WebSocket disconnects and the RuntimeNode projects `unavailable`, while
the Asterisk process, proof bridge, and participant channels stay alive, the Pod
UID is unchanged, and the Deployment stays at replicas=1. No verification or
fence operation was created, and the original configuration was restored
byte-for-byte. **Trigger decision: DEFINED — validated for the T5-A27 rerun.**

### Effective HTTP configuration source and writability

`astetcdir = /tmp/utcp-asterisk` (from `asterisk.conf`); effective
`http.conf = /tmp/utcp-asterisk/http.conf` — a **regular writable file** on the
container overlay filesystem (mode 644, owner asterisk uid 1000), NOT a symlink,
ConfigMap, Secret, or projected mount. It is a container-local copy, so
in-container edits are ephemeral and reversible with no Kubernetes object
mutation. Original content: `[general] enabled=yes bindaddr=0.0.0.0
bindport=8088 tlsenable=no prefix= sessionlimit=32 session_inactivity=30000`.
Original `http show status`: Server Enabled and Bound to 0.0.0.0:8088, serving
`/ari/...` and `/ws`. Asterisk 20.20.1, PID 1 (`asterisk -f -C
/tmp/utcp-asterisk/asterisk.conf -U asterisk -G asterisk`).

### Reload-mechanism finding (important)

The prompt's preferred `config reload http.conf` was tested first (conference-
free, on node B): it reloaded without error but **did not disable** the HTTP
server (`http show status` still "Enabled and Bound") — the built-in HTTP server
does not register in Asterisk's config-reload-by-filename map. **`core reload`**
(a global configuration reload, NOT a restart — it re-reads `http.conf` and
preserves the running process and active calls) DID disable it: with
`enabled=no` in the file, `core reload` → `http show status` = "Server
Disabled", PID 1 unchanged. `core reload` is therefore the working targeted
reload; `core restart`/`module` unloads were not used.

### Preliminary mechanism validation (node B, no conference)

Backup checksum `aaf6effb9533af1f78d47b5d6f8413a0`. Disable
(`sed enabled=yes→no` + `core reload`) → "Server Disabled"; control-plane ARI
REST → `AsteriskAriException: ARI HTTP transport failed` (transport-level, not
404); restore (backup + `core reload`) → "Server Enabled and Bound", checksum
match. Node B briefly unavailable, reconnected to `ready` at 03:30:51, **zero**
failover operations created (no conference bound, outage far under the 300 s
grace).

### Proof Conference

conference `a660fbe5-…e23a7`, participant `49b2cf25-…68bb9`, binding
`139e356c-…96558`, bound node `05ddb383…` (Local Asterisk ARI B, deployment
`asterisk-ari-b`, Pod uid `3b9121dc…4671e`), generation 2. Pre-trigger: bridge
`utcp-conf-a660fbe5…` present on node B with 2 channels (control socket), node A
empty, node B `ready` with open epoch `7276dc62…`, last-ready `03:31:32`, zero
failover operations. Created through the admin/member API.

### Outage window (bounded, 39 s)

- **03:31:57 disable** — backup `/tmp/http.conf.t5a28.orig`
  (`aaf6effb9533af1f78d47b5d6f8413a0`), `enabled=no`, `core reload` →
  "Server Disabled" (no enabled URIs). Immediately: bridge + 2 channels alive
  (control socket), Pod uid `3b9121dc` unchanged, replicas 1/1.
- **03:32:18 observe** — control-plane ARI REST →
  `ari_http_transport_failed` class `runtime_unavailable` (transport-level, not
  404); node B `observed_state = unavailable`; listener epoch **closed**
  (0 open), lease **released**; bridge + channels still alive; **0** failover
  operations for the conference.
- **03:32:36 restore** — backup → `http.conf`, `core reload` → "Server Enabled
  and Bound to 0.0.0.0:8088"; checksum `aaf6effb…` matches original exactly;
  bridge + 2 channels still alive; Pod uid `3b9121dc` unchanged (container never
  restarted; the pod's restart count of 1 predates this run).

Outage duration 03:31:57 → 03:32:36 = **39 seconds** (budget 120 s; well before
the 300 s failover-eligibility threshold at ~03:36:32).

### Recovery

Node B `ready` at 03:32:51 (15 s after restore): new open event epoch, lease
`claimed`, control-plane ARI REST healthy (`bridge_exists=true,
owned_bridge=true`). Zero failover operations for the conference throughout.

### Cleanup and final state

Container backups removed after restoration checks passed. Proof Conference
closed canonically (participant remove → desired-state closed → converged
`closed`). Final: both Deployments 1/1 with unchanged Pod UIDs
(`030c033c…45dd`, `3b9121dc…4671e`); both RuntimeNodes active/ready; both leases
claimed; one open epoch per node; zero open conferences; zero live bridges/
channels; zero pending/verify/fence operations for `a660fbe5…`;
`/tmp/utcp-asterisk/http.conf` restored (`enabled=yes`, checksum `aaf6effb…`);
tree clean; `UTCP_PHASE=T1`. No Kubernetes ConfigMap/Secret/Deployment/volume
was modified; the committed manifests are unchanged.

### Trigger-readiness decision

**T5_ASTERISK_HTTP_OUTAGE_TRIGGER_DEFINED — validated for the T5-A27 rerun.**
All 15 acceptance criteria met. The exact trigger for the rerun: inside the
bound Asterisk container, back up `/tmp/utcp-asterisk/http.conf`, set
`enabled=no`, run `core reload` (NOT `config reload http.conf`, which is
insufficient; NOT `core restart`), observe the transport-level outage, then
restore from backup + `core reload` before the 300 s grace. This produces the
transport-level `verify_conference_absent` failure the T5-A26 fix escalates to
fencing. Note (retained gap): the listener still does not detect a silently dead
event stream when only `res_ari_events` is unloaded — that latent
liveness-detection gap is separate from this validated HTTP-outage trigger.

## T5-A29 — Sustained failover proof BLOCKED: the Asterisk liveness probe defeats any 300 s HTTP outage

Checkpointed destructive attempt at `UTCP_PHASE=T1`, HEAD `cf59459` (T5-A26 fix
confirmed live in `scheduler` and `telephony-command-worker`). The validated
HTTP-disable trigger was applied to the bound node, but it **cannot sustain the
outage to the 300 s failover-eligibility threshold**: the Asterisk container's
TCP liveness probe on the ARI port restarts the container within ~60 s, which
destroys the proof bridge, re-enables HTTP, and returns the node to `ready`
before any failover candidate can form. This is a decisive trigger/deployment
finding, not a coordinator or fix defect. The divergence occurred **before any
fence mutation**; the environment self-healed and was cleaned up canonically.
**No fence operation, scale-to-zero, Pod deletion, binding mutation, or failover
occurred.**

### Baseline and image currency

Clean tree at `cf59459`; T5-A26 fix live in both `scheduler` and
`telephony-command-worker` (grep-confirmed); both nodes active/ready
(`030c033c…45dd`, `3b9121dc…4671e`); fence worker Ready/idle; drift passing;
zero open conferences; only historical failover op is the T5-A25
`terminal_failed` verify. Proof baseline timestamp `2026-07-19 04:00:01` used to
scope all proof-chain queries.

### Proof Conference

conference `0eae47d6-…0040c`, participant `7c86baef-…08887`, binding
`f9c4928d-…0beb96`, bound node `05ddb383…` (Local Asterisk ARI B, deployment
`asterisk-ari-b`, Pod uid `3b9121dc…4671e`), generation 2; replacement node A
`1d15ca88…`. Live bridge `utcp-conf-0eae47d6…` + 2 channels on node B; node A
empty. http.conf backed up (checksum `aaf6effb…`). Created via the admin/member
API.

### HTTP outage and the liveness-probe restart

- **04:00:49** HTTP disabled (`enabled=no` + `core reload`) → "Server Disabled";
  bridge + 2 channels alive, PID 1, Pod uid unchanged, replicas 1/1.
- **04:01:29** control-plane ARI REST → `ari_http_transport_failed` class
  `runtime_unavailable` (transport-level, not 404); node B `unavailable`;
  listener epochs closed. All correct so far — matching T5-A28.
- **~04:01:39** the kubelet **killed and restarted** the `asterisk` container
  after 3 consecutive liveness-probe failures. Events:
  `Liveness probe failed: dial tcp 10.42.2.249:8088: connect: connection
  refused` → `Container asterisk failed liveness probe, will be restarted`.
- **~04:01:51** the fresh container is back: `http show status` = "Server
  Enabled and Bound" (regenerated `enabled=yes` config), and the proof **bridge
  is gone** (fresh Asterisk starts empty). Pod uid `3b9121dc…4671e` unchanged
  (container restart, not Pod recreation); restart count 1→2.
- **04:02:07** node B `ready` again; zero proof-scoped failover operations.

Root cause (committed manifest
`infrastructure/kubernetes/components/asterisk-instance/asterisk-ari-deployment.yaml:65`):

```yaml
livenessProbe:
  tcpSocket: { port: ari }   # 8088
  initialDelaySeconds: 20
  periodSeconds: 20
  failureThreshold: 3        # ⇒ ~60 s tolerance
  timeoutSeconds: 5
```

Disabling the ARI HTTP server makes port 8088 refuse connections, so the TCP
liveness probe fails 3× (~60 s) and kubelet restarts the container. The restart
destroys the bridge and re-enables HTTP, so the node self-heals well before the
300 s grace. **T5-A28 succeeded only because its outage was 39 s — under the
~60 s probe window.** Any outage long enough to reach failover eligibility
necessarily trips the probe first.

### Architectural insight

In a liveness-probed deployment, the exact split-brain the fence defends against
— a workload whose bridge stays alive while the control plane is blind to it for
300 s — is **not reachable via a node-local ARI outage**: kubelet's same-node
probe detects the dead ARI port and restarts the container, killing the bridge
and recovering the node. Genuine sustained unavailability with a surviving bridge
requires a condition the local probe cannot fix — a control-plane↔node network
partition (the local probe still passes), an unschedulable/crashlooping
Deployment, or a relaxed liveness probe. None of these is producible under the
T5-A29 authorized-action set (which forbids Deployment patches and NetworkPolicy
changes).

### Divergence handling performed (before any fence mutation)

Per the "before scale-to-zero" contract: the container restart had already
re-enabled HTTP and restored ARI, so no manual `http.conf` restore was needed
(the ephemeral backup vanished with the replaced container filesystem); node B
was confirmed `ready`; the proof Conference was closed canonically (participant
remove → desired-state closed → converged `closed`); both nodes returned
active/ready. No manual scale, Pod deletion, or operation/binding/state write
occurred.

### Final state (restored)

Both Deployments 1/1; both RuntimeNodes active/ready; both leases claimed; one
open epoch per node; zero open conferences; zero live bridges/channels; zero
proof-scoped and zero pending operations; node A untouched (uid `030c033c…45dd`,
restart 1); node B self-healed (uid `3b9121dc…4671e`, restart 2, http.conf
`enabled=yes`); tree clean; `UTCP_PHASE=T1`. No Kubernetes object or committed
manifest was modified.

### Required next step (bounded)

The sustained failover proof needs a trigger that produces ≥300 s RuntimeNode
unavailability while the bridge survives, without the liveness probe restarting
the container. Options, in preference order:

1. **Proof-only temporary liveness-probe relaxation** on the former node
   (e.g. raise `failureThreshold`/`periodSeconds` or remove the liveness probe
   for the proof, then revert) — this is a Deployment patch, so it must be added
   to the authorized-action set for the rerun. Combine with the validated
   http.conf disable; the node then stays unavailable through the 300 s grace
   with the bridge intact, and the T5-A26 fix escalates to fencing.
2. A control-plane→node ARI **network partition** (block `utcp-platform`→
   `asterisk-ari-b:8088`) that leaves the same-node liveness probe passing —
   requires a scoped NetworkPolicy, currently prohibited.
3. Re-examine whether the failover trigger should key off a signal other than
   300 s ARI-transport unavailability, given that liveness probes convert
   node-local ARI outages into self-healing restarts.

The T5-A26 coordinator fix remains verified-and-unit-tested; only a
probe-compatible sustained-outage trigger is missing for the end-to-end live
proof. The retained events-only listener liveness-detection gap is separate.

## T5-A30 — Asterisk core liveness separated from ARI readiness

Repository-only correction at `UTCP_PHASE=T1` after T5-A29. No Kubernetes
resource was applied, no Deployment was scaled, no Pod was deleted, no live HTTP
outage was induced, no Conference was created, and no failover was run.

T5-A29 proved the previous probe authority was wrong: disabling Asterisk HTTP
closed ARI port 8088 while the Asterisk core, bridge, and channels remained
alive. The kubelet treated that ARI-only outage as liveness failure and restarted
the container after the liveness threshold, destroying the bridge before UTCP's
300-second failover eligibility could be reached. Kubernetes liveness must
answer "should this container be restarted"; readiness must answer "should this
Pod receive control-plane traffic". ARI availability belongs to readiness, not
container-restart authority.

The canonical Asterisk runtime manifests now use:

```yaml
startupProbe:
  exec:
    command: [/usr/local/bin/utcp-asterisk-readiness]
readinessProbe:
  tcpSocket:
    port: ari
livenessProbe:
  exec:
    command:
      - /usr/sbin/asterisk
      - -C
      - /tmp/utcp-asterisk/asterisk.conf
      - -rx
      - core show uptime
  timeoutSeconds: 5
  periodSeconds: 20
  failureThreshold: 3
```

The source manifests also carry explicit null deletion markers for the retired
handler fields (`readinessProbe.exec: null`, `livenessProbe.tcpSocket: null`) so
the normal Kubernetes apply path can remove the old handler type from existing
live objects. These markers do not contain an ARI liveness port, HTTP check, or
fallback command.

`core show uptime` was selected because it is a read-only Asterisk core CLI
query through the local control socket configured by
`/tmp/utcp-asterisk/asterisk.conf`. Local image proof with Asterisk 20.20.1
showed it exits zero when the control socket responds and exits nonzero when the
socket is absent (`Unable to connect to remote asterisk ... asterisk.ctl`). The
command does not call ARI HTTP, does not carry ARI credentials, and does not
reload, restart, stop, unload, originate, or mutate Asterisk state.

The existing initialization script remains as startup behavior so initial
Asterisk boot, ARI route availability, configured module state, and Local
channel registration are still gated before normal readiness/liveness begins.
Ongoing readiness is reduced to ARI TCP availability: ARI down makes the Pod
NotReady and removes endpoints, but the container remains running so active
bridges and channels are not destroyed by the liveness probe.

Rejected alternatives:

- A proof-only overlay or Deployment patch was rejected because production and
  proof manifests must share the same health authority.
- Threshold inflation was rejected because it preserves ARI as restart authority
  and merely races UTCP's 300-second failover grace.
- NetworkPolicy fault injection was rejected as the primary correction because
  it changes the proof trigger, not the incorrect kubelet authority.

Validation added:

- Parsed manifest tests assert liveness uses the canonical Asterisk binary,
  canonical config path, read-only `core show uptime`, a bounded timeout, and no
  ARI TCP or HTTP probe.
- Parsed manifest tests assert readiness checks the ARI port, startup still
  executes `/usr/local/bin/utcp-asterisk-readiness`, node A and node B render
  identical probe contracts, Service selectors and RuntimeNode labels are
  unchanged, container security context and resources are unchanged, and no
  environment/proof overlay overrides probe authority.
- `scripts/asterisk-conference/config-check` and
  `scripts/asterisk-ari/validate-ab-topology` now reject ARI liveness,
  mutating CLI probe commands, unbounded exec probes, timing inflation,
  node-A/node-B probe drift, and proof-only probe patches.

Render evidence:

- `kubectl kustomize infrastructure/kubernetes/overlays/local/runtime` rendered
  startup readiness-script initialization, core CLI liveness, and ARI TCP
  readiness for the single active Asterisk Deployment.
- `kubectl kustomize infrastructure/kubernetes/overlays/local-two-asterisk`
  rendered the same contract for `asterisk-ari-a` and `asterisk-ari-b`.

Server-side dry-run of the corrected manifests passed against
`.runtime/kubeconfig/utcp-local.yaml` / `k3d-utcp-local` without applying
resources. Live rollout of the corrected probe contract and the automatic
destructive failover proof remain pending.

## T5-A31 — First end-to-end automatic two-node failover: probe fix PROVEN, failover EXECUTED; two proof-timing artifacts

Checkpointed destructive proof at `UTCP_PHASE=T1`, HEAD `c89bdf1`. The corrected
probe contract was rolled out live to both Asterisk nodes, the 120-second HTTP
outage was proven NOT to restart the container (the fix that unblocked T5-A29),
and — for the first time — the complete automatic failover chain executed
end-to-end: detection → verification → terminal escalation (T5-A26) → automatic
fence → scale-to-zero → binding replacement → generation increment → bridge
reconstruction on the surviving node. Two deviations from the literal acceptance
criteria remain, both precisely-characterized proof-timing artifacts (not
failover-logic defects): the fence completion event reports `already_fenced`
rather than `fenced`, and the participant was not reconstructed because its
telephony session expired mid-proof. No manual scale-to-zero, Pod deletion,
direct operation write, or RuntimeBinding edit occurred.

### Probe rollout (Phase 1–3)

T5-A26 fix confirmed live in `scheduler` and `telephony-command-worker`. Both
live Asterisk Deployments rolled out one at a time from the committed manifests:
node A (`asterisk-ari`, `overlays/local/runtime`) → new Pod
`db55d57c5-9rxg8` (uid `c19929bc`, restart 0); node B (`asterisk-ari-b`,
`overlays/local-two-asterisk/node-b` with the parent overlay's registry image
transform) → new Pod `8557bd4d76-n56mq` (uid `c1f63121`, restart 0). Only the
Deployment objects were applied (Service/Secret untouched); server-side dry-run
clean. Node-B's `node-b`-only render initially lacked the registry image
transform (it lives in the parent overlay) → ImagePullBackOff on the bare
`utcp-asterisk-ari:latest`; corrected by extracting `asterisk-ari-b` from the
full overlay render (registry image), old Pod stayed Ready throughout (rolling
strategy, no outage). Live probes on both nodes:

```text
livenessProbe.exec = [/usr/sbin/asterisk -C /tmp/utcp-asterisk/asterisk.conf -rx "core show uptime"]
livenessProbe.tcpSocket = <unset>   (committed manifest nulls the base tcpSocket)
readinessProbe.tcpSocket.port = ari
startupProbe.exec = [/usr/local/bin/utcp-asterisk-readiness]
```

Manual liveness exec exit 0 on both; Service isolation correct; both
RuntimeNodes ready; no failover op.

### Probe semantic preflight — the decisive fix (Phase 4)

Proof conference `cbe2371c-…d5976`, participant `ca088417-…432a9e`, binding
`b08e9e2e-…c82c1c`, bound node B (`05ddb383…`, deployment `asterisk-ari-b`,
Pod uid `c1f63121`), generation 2. HTTP disabled 04:59:54. Over **120 seconds**
(double the old ~60 s ARI-liveness restart window that blocked T5-A29):

```text
restart count: 0 (unchanged) — container NEVER restarted
Pod Ready: false (readiness ARI-TCP fails, as designed)
liveness core command: exit 0 every sample
bridge + 2 channels: alive throughout (control socket)
RuntimeNode: unavailable; REST: ari_http_transport_failed / runtime_unavailable
```

HTTP restored (checksum `aaf6effb…` match); node B recovered to `ready` at
05:02:58, same Pod uid, restart 0, bridge intact. This is exactly what the old
`tcpSocket:ari` liveness could not do — the T5-A29 blocker is resolved.

### Sustained outage and automatic failover (Phase 6–11)

- **05:03:09** HTTP disabled (left disabled); liveness OK, bridge alive, PID 1,
  replicas 1/1.
- **05:03:46** node B `unavailable`, REST transport-failed, epochs closed.
  Last-ready 05:03:09 → grace crosses 05:08:09. Node B held: restart 0,
  NotReady, bridge alive through the entire grace (verified 05:04:10, 05:05:10).
- **05:09:02** coordinator created exactly one `runtime.node.verify_conference_absent`;
  3/3 attempts `ari_http_transport_failed` → `terminal_failed`.
- **05:10:03** **T5-A26 escalation fired**: next sweep classified the
  terminal-failed transport verification and created exactly one
  `runtime.node.runtime.fence` (coordinator outcome `runtime_fence_requested`).
- Fence op (attempt 1) performed the **effective scale-to-zero**: node B
  `asterisk-ari-b` desired 1→0 (node B was replicas=1 until this fence, verified
  05:05:10). Pod still gracefully terminating → `fence_in_progress` (retryable).
- Attempt 2 (**05:10:20**) saw pods=0 → termination predicate satisfied →
  operation `succeeded`; `conference.runtime_fence_terminated`
  `owned_pods_remaining=0`.
- **05:11:02** rebind: old binding (`05ddb383`/node B) retired with `unbound_at`;
  new binding (`1d15ca88`/node A) active; `conference.runtime_node_id` = node A;
  generation **2 → 3**; `conference.runtime_binding_replaced`
  (previous=05ddb383, new=1d15ca88, generation=3). Exactly one active binding.
- **05:11:04** `conference.ensure` succeeded on node A at G+1; deterministic
  bridge `utcp-conf-cbe2371c…` reconstructed **only** on node A; node B fenced
  (replicas 0, owned pods 0). No duplicate verify/fence/bridge chains (one of
  each).

### Artifact 1 — fence_result=already_fenced (not "fenced")

The completion event reports `already_fenced`. Root cause: the fence op needed
2 attempts because the Asterisk Pod's graceful termination spans more than one
fence reconcile cycle (the normal case). `KubernetesRuntimeFenceAdapter::fence`
computes `wasAlreadyZero = desiredReplicas === 0` at the START of each execution;
attempt 1 (desired=1) performed the scale and returned `fence_in_progress`;
attempt 2 (desired=0, because attempt 1 already scaled) re-evaluated
`wasAlreadyZero=true` and, with pods now 0, returned `already_fenced`. The
effective scale-to-zero WAS caused by this fence operation (node B demonstrably
at replicas=1 until the fence). So the proof's substance — the fence caused the
automatic scale — is met; only the completion label reflects the retry. This is
a spec-vs-implementation nuance: `fence_result=fenced` is only reachable when the
Pod terminates within the same execution as the scale, which graceful
termination normally prevents. Bounded fix worth considering: track
`wasAlreadyZero` against the operation's own prior attempts so a self-caused
scale completes as `fenced`.

### Artifact 2 — participant not reconstructed (session expiry)

The bridge reconstructed on node A, but the participant Local-channel legs did
not. Root cause: the proof member's telephony session has a **5-minute TTL**
(created ~04:59:23, expired 05:04:23), and the scheduled
`telephony-domain:expire-sessions` sweep removed the now-expired participant at
05:14 — setting `desired_state=removed`/`observed_state=left`. Because the total
proof spans ~8 minutes (300 s grace + fence + rebind + reconstruction), the
session expired before reconstruction, so the participant was canonically
removed rather than re-admitted. This is a proof-duration artifact, not a
reconstruction defect (the bridge reconstructed correctly). Rerun mitigation:
provision a longer-lived session or refresh it before the outage.

### Restoration and cleanup (Phase 12–13)

Node B restored via proof-only `kubectl scale asterisk-ari-b --replicas=1`
(manual infrastructure recovery before T5-A37; no longer normal restoration
authority) → **new Pod** `8557bd4d76-9z4ln` (uid `bad5fbce…`,
distinct from the fenced `c1f63121`), restart 0, corrected exec liveness,
regenerated `http.conf` (`enabled=yes`), fresh empty Asterisk, lease claimed,
new open epoch, RuntimeNode ready. It did **not** reclaim the conference
(remained on node A gen 3 — retired binding, moved generation, empty fresh
Asterisk, event fence prevent reclaim). Proof Conference closed canonically
(participant remove → desired-state closed → converged `closed`).

### Final state

Both Deployments 1/1 with new Pods (`c19929bc` node A, `bad5fbce` node B), both
restart 0; both RuntimeNodes active/ready; both leases claimed; one open epoch
per node; zero open conferences; zero live bridges/channels; zero pending/actionable
operations; corrected exec liveness live on both nodes; tree clean;
`UTCP_PHASE=T1`. No Kubernetes manifest or committed source changed. Historical
operations/bindings/events retained.

### Assessment

The central new capability — corrected probes allowing a sustained ARI outage
that automatically fences and fails over — is DEFINITIVELY PROVEN for the first
time: 120 s outage with zero container restarts, then a full automatic
detection→verify→terminal→T5-A26-escalation→fence→scale-to-zero→rebind→bridge-
reconstruction chain. Per the literal acceptance criteria the run is INCOMPLETE
on two points (`fence_result=already_fenced` and participant reconstruction),
both proof-timing artifacts with exact root causes above, neither a
failover-logic defect. Recommended rerun: longer session TTL for participant
reconstruction; optionally adjust the fence adapter to report `fenced` for a
self-caused scale.

## T5-A32 — Preserve self-caused fence result across runtime-operation retries

Repository-only correction at `UTCP_PHASE=T1`, starting from HEAD `fa53f5c`.
T5-A31 proved the runtime-fence operation itself performed the effective scale
mutation on its first attempt (`asterisk-ari-b` desired replicas 1→0), then
returned `fence_in_progress` while the owned Pod terminated gracefully. The next
attempt saw desired replicas already 0 and owned Pods 0, so the old adapter
classified completion as `already_fenced` even though the same runtime-fence
operation caused the scale on the prior attempt.

The corrected contract separates two cases:

- A workload that was already at desired replicas 0 before the runtime-fence
  operation issued any accepted scale mutation completes as
  `fence_result=already_fenced`.
- A runtime-fence operation that accepted the effective scale-to-zero on an
  earlier or current attempt records operation-scoped provenance and completes
  as `fence_result=fenced` once desired replicas, status replicas, available
  replicas, and owned Pods all reach zero.

The provenance is persisted in the existing runtime-operation payload under
`runtime_fence_provenance.scale_to_zero_requested`, with structured fields for
the operation ID, workload namespace, Deployment name, pre-scale replica count,
attempt count, and request timestamp. No new table, Kubernetes credential,
ServiceAccount token, full API response, Pod log, manual override, feature gate,
RuntimeNode allowlist, or duplicate scale path was introduced. The adapter
continues to own workload identity validation and the scale subresource call;
the handler continues to own operation retry/completion evidence and the single
`conference.runtime_fence_terminated` event path.

Focused regression coverage now exercises the real handler-to-adapter path for:

- scale and complete in one attempt → `fenced`;
- scale, retry while a Pod is terminating, recreate the worker/handler, then
  complete from persisted provenance → `fenced`;
- workload already zero before the operation → `already_fenced`;
- workload already zero with a terminating Pod → retry, then
  `already_fenced` without self-scale provenance;
- scale request failure before acceptance followed by external zero state →
  `already_fenced`, not a false UTCP-caused fence;
- target mismatch and runtime recovery before mutation → no scale and no
  self-scale provenance;
- repeated completed processing → no duplicate scale and no duplicate
  termination event.

Participant/session production semantics were deliberately left unchanged. The
next live proof must create or refresh the proof telephony session through the
canonical application lifecycle so its expiry extends beyond the approximately
eight-minute proof window. The repository currently exposes canonical session
creation through `POST /api/v1/telephony/sessions` using
`telephony_domain.session_lifetime_minutes`; no proof-only infinite session,
environment TTL override, direct database expiry mutation, hidden refresh path,
or new Artisan management command was added.

Live participant-inclusive failover rerun remains pending. No Kubernetes
rollout, scale, Pod deletion, live Conference creation, RuntimeBinding mutation,
or failover was performed for this repository correction.

## T5-A33 — Participant telephony-session lifetime contract for the failover proof (evidence-only)

Evidence-only session-lifecycle audit at `UTCP_PHASE=T1`, HEAD `fdc745e`. No live
failover, scaling, or session-behavior change. Determines exactly how to keep one
admitted participant valid through the ~8–11 minute automatic failover proof
(T5-A31's participant was removed by session expiry at 5 minutes).

### Session creation authority

`POST /api/v1/telephony/sessions` → `TelephonySessionController::store`
(`routes/web.php:81`). Authenticated first-party session; actor = `$request->user()`;
active-tenant from session (`active_tenant_id`, 409 if absent); permission
`telephony.sessions.create_own` (`AuthorizationService::requireTenant`). Delegates
to `TelephonyDomainService::createSession` (line 73). Request fields: none for
expiry — only an optional `Idempotency-Key` header. **The caller cannot request an
expiry**; the server sets it unconditionally. `createSession` is idempotent AND
"return-existing": if an active session already exists for (tenant,user) it returns
it **unchanged** (lines 85–93) — it does **not** extend `expires_at`. Session→
participant link: `conference_participants.telephony_session_id`; self-admission
(`admitSelf`/`admitParticipant`) requires `status='active'` and `expires_at > now()`
at admission time only (line 554).

### Five-minute TTL source

`expires_at = now()->addMinutes(config('telephony_domain.session_lifetime_minutes', 60))`
(`TelephonyDomainService.php:102`). The key is `telephony_domain.session_lifetime_minutes`
= `env('UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES', 60)` (`config/telephony_domain.php:13`).
**Code default is 60 minutes.** The live 5-minute value is a committed local-overlay
override: `infrastructure/kubernetes/overlays/local/platform/application-config.properties:29`
`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=5` (confirmed live in ConfigMap
`utcp-application-config`). It is a configuration value applied to ALL sessions in
the local overlay, not a request-controlled or proof-only value. No ADR documents
the 5-minute choice; it reads as a local-dev convenience.

### Maximum supported validity

Unbounded by code except the config integer — any value the config provides is used
verbatim (no server cap, no min/max validation). The code default (60) already
exceeds the proof. There is no per-request maximum because there is no per-request
expiry field.

### Canonical refresh contract

**None exists.** Exhaustive search of `App\TelephonyDomain`, controllers, and routes
for refresh/renew/extend/heartbeat/keepalive/touch/`expires_at` updates found no
public route, no service method, and no code path that advances an existing session's
`expires_at`. The only session mutations are: create (sets initial expiry), end
(`POST .../end` → `ended`), and expire (scheduler → `expired`). `createSession`
re-called returns the existing session unchanged. So a running session cannot be
extended through any normal application authority.

### Refresh authorization

N/A — no refresh path to authorize.

### Expiration scheduler

`Artisan::command('telephony-domain:expire-sessions')` (`console.php:721`),
scheduled everyMinute (per the scheduler). `TelephonyDomainService::expireDueSessions`
(line 148): selects `status='active' AND expires_at <= now()` ordered by `expires_at`,
limit 100; per row, in a transaction, re-locks (`lockForUpdate`), re-checks
`status='active'`, sets `status='expired'`, revokes signaling, and calls
`removeParticipantsForSession` (line 667) which sets every admitted participant of
that session to `desired_state='removed'`, wakes the participant reconciliation
target at the removed generation, and audits `conference_participant.removed`.
Emits `telephony_session.expired`. Expiry precision: within one everyMinute sweep of
`expires_at` (0–60 s after expiry). No grace.

### Refresh-versus-expiry race

Not applicable to extension (no refresh path). For creation vs expiry:
`createSession` calls `expireDueSessionsForUpdate` under lock before deciding, and
the sweep re-locks and re-checks `status='active'` before expiring — so the two
cannot double-process a row. An already-expired session is never renewed (create
makes a NEW session with a new id; it does not resurrect the expired one, and the
participant remains tied to the expired session's id). There is therefore no way to
keep the SAME participant valid by re-creating a session.

### Participant reconstruction eligibility

`ConferenceParticipantReconciler` (lines 47–114) drives `conference.participant.ensure`
purely from `participant.desired_state='admitted'` AND `conference.desired_state='open'`
— it does **not** recheck the telephony session's status or expiry, and neither does
the Asterisk adapter's participant path. So after RuntimeBinding replacement at G+1,
the participant reconstructs (deterministic channel id, Local-channel re-originate,
bridge attach) **iff it is still `desired_state='admitted'`**. The only thing that
flips it to `removed` during the proof is the expiry sweep when its session expires.
Proof-of-eligibility evidence at G+1: `conference_participants.desired_state='admitted'`,
its `telephony_session_id` session `status='active'` with `expires_at > now()`, and a
`conference.participant.ensure` op succeeding on the replacement node at the new
generation. Net: **keeping the participant valid reduces entirely to keeping its
session's `expires_at` beyond the proof end.**

### Proof timing budget (from T5-A31 observed evidence, no preflight in rerun)

```text
conference creation + convergence + self-admission   ~60 s
HTTP disable → unavailable projection                ~40 s   (05:03:09→05:03:46)
300-second failover grace                             300 s
grace-cross → coordinator sweep → verify created     ~55 s   (obs 53 s)
3 verify attempts → terminal_failed                  ~50 s   (obs 47 s)
terminal → next sweep → runtime.fence created        ~60 s   (obs 12 s, up to a full sweep)
fence claim + Pod termination + completion           ~30 s   (obs 17 s)
fence complete → binding replacement                 ~45 s   (obs 42 s)
binding replaced → participant reconstruction        ~60 s
evidence capture                                     ~120 s
--------------------------------------------------------------
conservative total (session-create → reconstruction) ~13–15 min with scheduler jitter
```

Minimum safe session validity: **~15 minutes**. The 5-minute local value is
insufficient by construction; the 60-minute code default is more than sufficient.

### Selected session strategy

**D → resolved by a bounded config correction, then A.** The current lifecycle
offers no request-controlled expiry and no refresh path, so no purely-live API action
can extend a 5-minute session through the proof. The single deterministic lever is the
existing config key. The bounded fix: set the committed local-overlay
`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES` to a value comfortably exceeding the proof
budget (recommended **30**, giving ~15+ min margin at reconstruction; not infinite,
not proof-only, not hidden — the canonical config knob, matching the spirit of the
60-minute code default). After that change is rolled out, the participant-inclusive
rerun uses **Strategy A**: normal `POST /telephony/sessions` yields a 30-minute
session created at conference setup, leaving ~17–20 minutes of validity at
participant reconstruction (~T0+10–13 min). No refresh, no per-request expiry, no
new route required.

Rejected: A as-is (API cannot request a longer expiry while the local config is 5 min);
B/C refresh (no refresh path exists); raising via a hidden env override or an infinite
session (prohibited and unnecessary).

### Existing test coverage

| Behavior | Test | Type | Missing |
|---|---|---|---|
| Session creation + participation lifecycle | TelephonyDomainTest:28 | feature | — |
| Session expiry removes participation | TelephonyDomainTest:107 (forces `expires_at=now()-1min` then runs sweep) | feature | doesn't exercise the config TTL |
| Requested expiry | none | — | no feature exists |
| Maximum/validated expiry | none | — | no cap exists |
| Refresh/renew | none | — | no feature exists |
| Refresh/expiry race | none | — | no feature exists |
| Participant reconstruction with a valid session through failover | none | — | **live proof still required** |
| Participant non-reconstruction after expiry | partially (expiry→removed at :107) | feature | not tied to failover/G+1 |
| Local session lifetime ≥ failover proof budget | none | — | **config-check assertion missing** |

### Readiness decision

**B — bounded Codex session-lifecycle (config) implementation is required first.** The
missing piece is isolated exactly: one committed value
(`infrastructure/kubernetes/overlays/local/platform/application-config.properties`
`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES` 5→30), rolled out to the
`utcp-application-config` ConfigMap, plus a config-check/test assertion that the local
telephony session lifetime comfortably exceeds the failover proof budget. No new route,
no refresh subsystem, no request-expiry field, no session-behavior change. After it is
deployed, the participant-inclusive destructive rerun (T5-A31 flow) uses Strategy A and
should reconstruct the participant at G+1 with ample session margin.

## T5-A34 — Local telephony-session lifetime correction for failover

Repository-only correction at `UTCP_PHASE=T1`, starting from HEAD `d412f20`.
No live ConfigMap rollout, Kubernetes apply, rollout restart, live session creation,
Conference creation, HTTP outage, RuntimeBinding mutation, scale, Pod deletion, or
failover was performed.

### Root cause

T5-A31 through T5-A33 established that the automatic failover proof consumes roughly
13-15 minutes from canonical session creation through participant reconstruction.
The PHP default remains 60 minutes:
`config('telephony_domain.session_lifetime_minutes', 60)`. The committed local
platform overlay, however, overrode the runtime value to 5 minutes through
`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=5`, causing the expiration scheduler to
expire the admitted participant before reconstruction.

### Correction

The single local platform override was changed to:

```text
UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=30
```

The 30-minute value is the deterministic local default for normal local operation
and failover testing. The 60-minute PHP code default was not changed because the
defect was a local overlay override, not application behavior.

### Guard

`scripts/telephony-domain/config-check` now parses the local platform properties
file and requires `UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES` to appear exactly once
as an integer value of at least 20. The guard rejects missing, non-numeric, zero,
negative, and failover-unsafe undersized values without enforcing a speculative
maximum.

### Session behavior

Focused coverage now freezes the application clock, sets
`telephony_domain.session_lifetime_minutes` to 30, creates a session through the
canonical `POST /api/v1/telephony/sessions` route, and asserts `expires_at` is the
controlled current time plus 30 minutes. The same test sends request fields named
`expires_at` and `session_lifetime_minutes` to prove request payloads do not control
expiration. A repeated create call for the same active user and tenant returns the
existing session id and preserves the original `expires_at`; no refresh or renewal
subsystem was added.

### Render and dry-run

The local platform overlay renders `utcp-application-config` with
`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=30`. Server-side dry-run against
`.runtime/kubeconfig/utcp-local.yaml` and context `k3d-utcp-local` passed for the
rendered platform overlay. Live ConfigMap rollout remains pending.

### Boundaries retained

No session API route, request validation, model, expiration scheduler, participant
reconciliation, failover coordinator, runtime-fence handler, Kubernetes adapter,
Asterisk probe, RBAC, NetworkPolicy, RuntimeBinding replacement, or former-node
restoration behavior changed. No refresh endpoint, proof-only session type,
infinite session, additional environment key, feature gate, manual switch, hidden
TTL override, or direct expiry repair path was introduced.

The participant-inclusive destructive rerun remains pending. The application image
containing `fdc745e` and the updated local `utcp-application-config` ConfigMap must
be deployed before that rerun.

## T5-A35 — Participant-inclusive automatic two-node failover: COMPLETE

Checkpointed live rollout + destructive proof at `UTCP_PHASE=T1`, HEAD `ab4418d`
(includes `fdc745e` fence-provenance fix + `ab4418d` 30-minute local session
lifetime). The complete canonical automatic failover lifecycle succeeded
end-to-end WITH an admitted participant: `fence_result=fenced`, participant
reconstructed at G+1 with ~20 minutes of session validity remaining. No manual
scale-to-zero, Pod deletion, direct operation/expiry/participant write occurred.

### Application image build and rollout

Built the API image from the clean `ab4418d` tree, verified it contains the
`fdc745e` provenance code (`runtime_fence_provenance` / `scale_to_zero_requested`,
grep=3) and the T5-A26 escalation (grep=2), pushed to
`127.0.0.1:5001/utcp/api:0.1.0-k1-dev` (digest `sha256:bbf2f942…`). Rolled out
api, scheduler, telephony-command-worker, telephony-reconciler,
telephony-event-normalizer, utcp-runtime-fence-worker, worker — all Ready, no
crash loops (the fence-worker/worker first-second startup restart is the known
benign PostgreSQL-connect race). Asterisk/Kamailio/PostgreSQL/Redis untouched.

### Live fence-provenance code

Running fence worker contains the exact fdc745e line
(`RuntimeFenceOperationHandler.php:78` `scale_to_zero_requested_by_operation`);
scheduler contains `classifyTerminalFailedVerificationOperation`.

### ConfigMap rollout and running configuration

Rendered `overlays/local/platform`, applied only the `utcp-application-config`
ConfigMap (`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=30`). Live ConfigMap = 30;
running processes confirmed: api/scheduler/telephony-command-worker all
`printenv UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES` = 30.

### Fresh telephony session and margin

Fresh canonical `POST /api/v1/telephony/sessions` (new member, normal auth) →
session `24b3c038-…c30`, active, issued 07:34:19, expires 08:04:19, **29.67 min**
initial validity.

### Proof Conference

conference `8e823993-…e6a6`, participant `d6ec836b-…c7477`, session
`24b3c038-…c30`, binding `d8b16f13-…db89c`, bound node B (`05ddb383…`,
`asterisk-ari-b`, Pod uid `bad5fbce…`), generation 2; replacement node A
(`1d15ca88…`). Initial: participant admitted linked to the fresh session; one
active binding on node B; bridge `utcp-conf-8e823993…` + 2 channels on node B;
node A empty.

### Sustained HTTP outage and detection

07:34:54 HTTP disabled (`enabled=no` + `core reload`, backup checksum
`aaf6effb…`) → "Server Disabled"; liveness OK, bridge alive, PID 1, replicas
1/1, restart 0. 07:35:34 REST `ari_http_transport_failed`/`runtime_unavailable`,
node B `unavailable`, epochs closed, Pod NotReady, **restart count 0**
(corrected probes hold). Last-ready 07:34:54 → grace crossed 07:39:54.
Throughout the grace: session active, participant admitted, bridge + 2 channels
alive, node B never restarted.

### Automatic failover chain

```text
07:40:03  runtime.node.verify_conference_absent created (coordinator)
          → 3/3 attempts ari_http_transport_failed → terminal_failed
07:41:02  runtime.node.runtime.fence created (T5-A26 escalation on next sweep)
          → dedicated worker scaled node B 1→0 (effective scale)
07:41:20  conference.runtime_fence_terminated  fence_result=fenced  owned_pods_remaining=0
07:42:02  conference.runtime_binding_replaced  → node A, generation 3
07:42:03  conference.ensure succeeded on node A at G+1 (bridge reconstructed)
07:42:03  conference.participant.ensure succeeded on node A at G+1 (participant reconstructed)
```

### Fence-provenance evidence and final result

Fence op payload `runtime_fence_provenance.scale_to_zero_requested`:
`by_operation=true`, `operation_id=37d65841…` (matches the fence op),
`pre_scale_replicas=1` — proving THIS operation performed the 1→0 scale.
Completion event `conference.runtime_fence_terminated` `fence_result=fenced`
(NOT `already_fenced`), `owned_pods_remaining=0`. The fdc745e fix works: a
self-caused scale reports `fenced` even though completion came on a later
attempt after graceful Pod termination.

### Binding, generation, reconstruction

Old binding (`05ddb383`/node B) retired with `unbound_at`; new binding
(`1d15ca88`/node A) active; exactly one active binding; generation 2→3 (once).
Bridge `utcp-conf-8e823993…` reconstructed **only** on node A with **2 active
channels** (participant Local-channel legs); node B fenced (0 replicas, 0 owned
pods). Duplicate-prevention: exactly one verify op, one fence op, one
termination event, one active binding — no duplicate chains.

### Participant reconstruction and session validity

Participant `desired_state=admitted`, `observed_state=joined` throughout —
never removed by the expiry sweep (the 30-min session outlasted the proof).
`conference.participant.ensure` succeeded on node A at G+1 with deterministic
channel legs attached to the reconstructed bridge. **Session validity at
reconstruction: ~20.3 minutes remaining** (requirement ≥5 min). This is the
correction that T5-A31's 5-minute session could not satisfy.

### Former-node restoration

Proof-only `kubectl scale asterisk-ari-b --replicas=1` (manual infrastructure
recovery before T5-A37; no longer normal restoration authority) → **new Pod**
`8557bd4d76-2d2sk` (uid `1f53ff0b…`, distinct from fenced `bad5fbce…`), restart
0, corrected exec liveness, regenerated `http.conf` (`enabled=yes`), fresh empty
Asterisk, lease claimed, new open epoch, RuntimeNode ready. Did not reclaim the
conference (remained on node A gen 3).

### Cleanup and final state

Participant removed and conference closed canonically (converged `closed`); the
proof member session left to canonical expiry (no direct edit). Final: both
Deployments 1/1 with restart 0 (`c19929bc` node A, `1f53ff0b` node B); both
RuntimeNodes active/ready; both leases claimed; one open epoch per node; zero
open conferences; zero live bridges/channels; zero actionable operations; fence
worker Ready/idle; tree clean; `UTCP_PHASE=T1`. Historical evidence retained.

### Result

**T5_PARTICIPANT_INCLUSIVE_AUTOMATIC_FAILOVER_COMPLETE.** All 32 completion
criteria met. This is the first fully-successful end-to-end automatic two-node
conference failover with participant reconstruction: sustained ARI outage (no
container restart, corrected probes) → automatic verification → terminal
escalation (T5-A26) → dedicated-worker fence with `fence_result=fenced`
(fdc745e) → transactional rebind (gen 2→3) → bridge and participant
reconstruction on the surviving node with ~20 min session validity (ab4418d) →
one-time former-node restoration → canonical cleanup.

## T5-A36 — Canonical former-node restoration and un-fencing authority (evidence-only)

Evidence-only architecture audit at `UTCP_PHASE=T1`, HEAD `280d3b5`. Defines the
production lifecycle that replaces the proof-only manual
`kubectl scale deployment/<fenced> --replicas=1`. No implementation, scaling, or
failover performed. All tests/config-checks pass.

### Current restoration authority at T5-A36

**Superseded by T5-A37.** At the time of this evidence-only audit, no production
restoration authority existed. A successfully fenced RuntimeNode was restored
only by the manual `kubectl scale 0→1`, which appeared solely in the
`scripts/asterisk-conference/recovery-runtime-proof` test-teardown and in the
T5 proof runbooks — never in application/control-plane code. It must be
reclassified as exceptional infrastructure recovery, not normal authority.

### Scale-up authority inventory

No production code path scales a runtime workload above zero. `scaleDeployment`
(`HttpKubernetesWorkloadClient.php:36`) accepts any non-negative int, but the
only production caller (`KubernetesRuntimeFenceAdapter.php:48`) hard-codes `0`.
Grep across `apps/api/app` for restore/recover/unfence/resume/reactivate: no
production hits (all `recover*` hits are conference-reconciliation wakes or
telemetry, none scale Kubernetes). Operation types
(`config/telephony_domain.php:15-22`): conference.ensure/close,
participant.ensure/remove, verify_conference_absent, runtime_fence — no
restore/recover/unfence type. No Artisan command scales/restores a node
(`console.php` runtime commands are all reconcile/observe/worker; the
infrastructure-probe is explicitly read-only). The application is fence-only
(scale-to-zero-only).

### RuntimeNode desired-state authority

Canonical model `runtime_nodes` (`create_runtime_registry_tables.php`):
`desired_state` string(32) default `draft`, pgsql CHECK
`IN ('draft','active','draining','disabled')`; `observed_state` default
`unobserved` (runtime writes ready/unavailable/degraded/stale/connecting);
`configuration_version` generation counter. Workload identity lives in the
`labels` JSON under `kubernetes_workload`. Canonical web-admin authority:
`POST /runtime-nodes/{id}/desired-state` → `AdminRuntimeNodeController::desiredState`
(permission `runtime.nodes.manage`, session tenant) →
`RuntimeRegistryService::changeDesiredState` (`RuntimeRegistryService.php:139`),
which validates via `RuntimeRegistryCatalog::assertDesiredTransition`
(matrix: draft→{active,disabled}, active→{draining,disabled},
draining→{active,disabled}, **disabled→{draft,active}**), bumps
`configuration_version`, emits `runtime_node.desired_state_changed`. It does
**not** wake reconciliation or scale anything. No `fenced` or `maintenance`
state exists. **Kubernetes replicas are never derived from desired_state.**

### Fence-evidence lifecycle

A successful fence: (1) scales the Deployment to 0; (2) records
`runtime_fence_provenance.scale_to_zero_requested` `{by_operation, operation_id,
namespace, deployment, pre_scale_replicas, attempt_count, requested_at}` in the
`runtime_operations` payload; (3) emits `conference.runtime_fence_terminated`
(`fence_result=fenced`, owned_pods_remaining=0) to the outbox; (4) rebinds the
conference to a replacement node. **It never mutates `runtime_nodes`** — the
fenced node keeps `desired_state='active'` while its Deployment is at 0 replicas
and its `observed_state` decays to unavailable/stale. This is the core structural
gap: a node is "desired active" with no workload and nothing derives replicas
from desired_state, so nothing brings it back. Fence evidence is operation-scoped
and node-scoped (former_runtime_node_id in payload/event), immutable audit
history — restoration must SUPERSEDE it (via a restoration operation + event),
never delete it.

### Selected restoration authority model

**Model C (operator desired-state authority + automatic reconciliation
execution)** — the target the prompt prefers; the request surface already exists
(`changeDesiredState`), the automatic-execution half must be built. Canonical
lifecycle:

```text
fence completion transitions former node desired_state active → disabled
  (records fence provenance; node leaves placement candidacy by desired_state)
→ authorized operator POST /runtime-nodes/{id}/desired-state {disabled→active}
  (existing route, permission runtime.nodes.manage, existing valid transition)
→ changeDesiredState schedules a runtime.node.restore operation when the node
  carries fence evidence (idempotency-keyed on node + fence operation id)
→ dedicated infrastructure worker claims it (fence-only routing, same worker)
→ authority revalidation → scale Deployment 0 → configured replicas
→ wait owned-Pod Ready → listener lease claimed → new open event epoch
→ RuntimeNode observed ready → empty-runtime validation
→ complete: emit runtime_node.restored, supersede fence evidence
→ node eligible for FUTURE placement (never reclaims moved conferences)
```

Reusing the existing `disabled` state (per "do not infer a new state when an
existing one fits") — a fenced node semantically "should not be running", which
is exactly `disabled`; the fence operation/event supplies the "why". (A dedicated
`fenced` desired-state is the documented alternative if operator-disable vs
auto-fence must be distinguished at the state level; it requires a config/enum +
CHECK migration and is not needed for the minimal slice.) This keeps operator
authority at the web-admin desired-state action and never requires the operator
to invoke the infrastructure mutation directly.

### Restoration preconditions (from existing contracts)

Successful fence evidence exists for the node; former Deployment desired
replicas=0 and owned Pods=0; no active RuntimeBinding references the node for an
open Conference (guaranteed — rebind retired it); no unresolved fence/verify
operation for the node; workload identity (`labels.kubernetes_workload`) still
resolves and matches ownership labels; RuntimeNode configuration/credentials/
endpoints remain valid; replacement Conferences remain authoritative elsewhere
(generation moved). No cooldown/capacity/health-precheck is required by any
demonstrated safety boundary, so none should be added; an operator reason is
conventional (audit) but not a safety gate.

### Restoration operation

New operation type `runtime.node.restore` (`runtime.node.*` namespace, mirroring
`runtime.node.runtime.fence`) — do NOT overload the fence operation with reverse
behavior (ambiguous authority). Reuses the exactly-once/idempotent operation
substrate: idempotency key = hash(node, source fence operation id, configuration
generation); dedicated `RuntimeNodeRestoreOperationHandler` claimed only by the
`runtime-engine:infrastructure-worker` (add to its include list; keep excluded
from generic `command-worker`). Scale target = configured replicas, sourced from
the fence provenance `pre_scale_replicas` (proven=1) or the RuntimeNode/manifest
configured replica count — never the live Deployment spec (it is 0). Idempotent
scale: if already at target, no-op complete. Recovery after worker restart: the
operation is re-claimed and re-evaluated against live Deployment/Pod state
(mirror the fence handler's self-provenance pattern).

### Dedicated worker authority

Same dedicated infrastructure worker and RBAC already used for fencing
(deployments get/list + deployments/scale get/patch + pods get/list in
utcp-runtime). No new RBAC — scale-up uses the identical scale subresource verb
already granted. Generic command workers must continue to exclude
`runtime.node.*` infrastructure operations.

### Scale target and idempotency

`scaleDeployment(namespace, deployment, configuredReplicas)` — the client
already supports arbitrary replicas (`HttpKubernetesWorkloadClient.php:40-49`).
Idempotent: re-running observes desired=target and completes without a second
scale (mirror the fence adapter's `wasAlreadyZero`/provenance logic in reverse).

### Pod readiness / lease / epoch gates

Reuse the proven gates: Deployment rollout to configured replicas with owned Pods
Ready (startup probe + corrected exec liveness + ARI-TCP readiness — T5-A31);
listener lease claimed and a new open `runtime_event_connection_epochs` row;
RuntimeNode `observed_state='ready'` via canonical projection. These are the same
signals the T5-A35 live proof observed after the manual scale-up.

### Empty-runtime validation

The scale-up creates a fresh Pod (the fenced Pod was terminated), so the restored
Asterisk is empty by construction — as observed in every T5 restoration. Existing
inspection (`AsteriskAriClient::conferenceRuntimeSummary`,
`RuntimeConferenceInspectionService::inspect`) is per-conference/participant, not
a node-wide enumeration. **Gap:** there is no node-wide "enumerate all UTCP-owned
bridges/channels" emptiness scan. For scale-up-from-zero this is low-risk (fresh
Pod), so the minimal slice can accept fresh-Pod-UID + new-epoch + zero owned-Pod-
from-prior-generation as the emptiness contract; a node-wide UTCP-owned-resource
scan is a documented follow-up hardening, not a blocker.

### Stale-generation protection (already complete, tested)

A restored former node CANNOT reclaim a moved conference: (a) operation-side —
`AsteriskRuntimeAdapter` compares `operationGeneration < conference.configuration_generation`
and emits `*_stale` no-ops; (b) event-side — `AsteriskAriEventNormalizer:93-104`
joins `conference_runtime_bindings.runtime_node_id = receipt node AND status='active'`,
dropping former-node events; (c) binding — partial unique index
`conference_runtime_bindings_one_active` + retired-old/insert-new + generation
bump. Proven by `AsteriskConferenceRecoveryTest::test_old_node_operation_is_stale_after_atomic_runtime_binding_replacement`
and `test_delayed_old_node_conference_and_participant_events_do_not_project_after_rebind`.
No new work required.

### Placement re-entry (the one isolated race)

`selectRuntimeNodeForConference` (`TelephonyDomainService.php:792-817`) admits a
node on `desired_state IN (active,draining)` + `observed_state='ready'` +
capabilities — with **no restoration-validation gate**. If fence leaves the node
`active` (current behavior), the moment its restored Deployment observes ready it
re-qualifies for NEW placement with no cooldown. The Model-C design closes this
cleanly: fence→`disabled` removes it from candidacy, and it only returns to
`active` when the restore operation completes — so completion of the restore
operation IS the placement-re-entry gate. This is the exact bounded target; do
not add a permanent allowlist or manual toggle.

### Failure and retry taxonomy

Retryable: scale request failure, Pod scheduling/startup-probe/liveness/
readiness not yet ready, lease not claimed, event epoch not opened, RuntimeNode
not yet ready (mirror fence `fence_in_progress`). Terminal: workload identity
mismatch (`target_mismatch`), permission denied. Cancelled by changed authority:
desired_state moved away from `active` during restoration (operator re-disabled),
or configuration disabled. Non-empty restored runtime (should not occur for a
fresh Pod): terminal, surfaced explicitly — never silent fallback to manual
scale. Repeated restoration request: idempotent (same key returns the in-flight/
completed operation). All failures remain observable via the operation row +
event; no silent manual fallback.

### Restoration evidence and events

Persist on the restore operation: operation id, tenant, RuntimeNode, workload
namespace+deployment, source fence operation id, requested desired state,
requested_by actor (or policy), reason, scale provenance (target replicas,
pre_restore_replicas), new Pod UID, new event epoch id, ready observation,
empty-runtime result, completion result, timestamps. Single canonical event
`runtime_node.restored` (matching the `runtime_node.*` naming used by
`runtime_node.desired_state_changed`) — do not invent overlapping events. This
supersedes (does not delete) the `conference.runtime_fence_terminated` history.

### Existing test coverage

| Behavior | Exists | Missing |
|---|---|---|
| Stale former-node operation after rebind | AsteriskConferenceRecoveryTest:970 | — |
| Delayed former-node events dropped after rebind | :1039 | — |
| Fence scale-to-zero + fenced/already_fenced | KubernetesRuntimeFenceAdapterTest | — |
| desired-state transition matrix | RuntimeRegistry tests (assertDesiredTransition) | — |
| Authorized/unauthorized restore request | — | **missing** |
| Tenant isolation of restore | — | **missing** |
| Idempotent repeated restore | — | **missing** |
| Scale 0→configured + Pod readiness | — | **missing** |
| Lease/epoch recovery on restore | — | **missing** |
| Empty-runtime validation | — | **missing** |
| Placement blocked until restore completes | — | **missing** |
| Worker restart mid-restore | — | **missing** |
| Retryable vs terminal restore failures | — | **missing** |
| Desired-state changed during restore (cancel) | — | **missing** |
| runtime_node.restored audit/outbox idempotency | — | **missing** |

### Readiness decision

**A — bounded Codex restoration implementation is ready.** Every element is
isolated to exact classes/routes/operations/tests: canonical desired-state
authority (`changeDesiredState` + disabled↔active), the request surface (existing
route), a new `runtime.node.restore` operation + `RuntimeNodeRestoreOperationHandler`
on the existing dedicated worker + existing RBAC, scale target (fence provenance
`pre_scale_replicas`/configured), the proven readiness/lease/epoch gates,
placement re-entry via fence→disabled + restore-completion→active, complete and
tested stale-generation protection, a clear failure taxonomy, and a single
`runtime_node.restored` event. The only accepted simplification is the
fresh-Pod emptiness contract (node-wide UTCP-owned-resource enumeration deferred).

### Ready-to-paste next prompt

```text
You are working in the Unified Telephony Control Plane repository.

Perform one bounded implementation (no live failover, no scaling):

# T5-A37 — Canonical former-node restoration (desired-state driven un-fencing)

HEAD 280d3b5, branch main, clean tree, UTCP_PHASE=T1. Evidence basis:
docs/evidence/t2/multi-node-failover-readiness.md §T5-A36.

Goal: remove manual `kubectl scale --replicas=1` as accepted normal restoration
authority and replace it with canonical desired-state-driven restoration; retain
kubectl only as exceptional infrastructure recovery (document it as such, not as a
coequal management interface). Implement the smallest coherent slice:

1. Fence completion: in the runtime-fence success path (RuntimeFenceOperationHandler
   / the fencing coordinator), transition the fenced former RuntimeNode
   desired_state active→disabled via the canonical registry authority (not a raw DB
   write), carrying the fence operation id as provenance. This removes the fenced
   node from placement candidacy (selectRuntimeNodeForConference requires
   desired_state IN active,draining) and makes its state consistent with its
   zero-replica workload. Do not delete any fence/verify/binding/audit evidence.

2. Restoration request surface: reuse POST /runtime-nodes/{id}/desired-state
   (AdminRuntimeNodeController::desiredState, permission runtime.nodes.manage). When
   RuntimeRegistryService::changeDesiredState transitions a fence-disabled node
   disabled→active, schedule exactly one runtime.node.restore operation
   (idempotency key = hash(node id, source fence operation id, configuration
   generation)). Do NOT add an Artisan command, a kubectl path, a feature gate, an
   allowlist, or a hidden un-fence endpoint as normal authority.

3. New operation type telephony_domain.operation_types.runtime_node_restore =
   'runtime.node.restore' + RuntimeNodeRestoreOperationHandler, claimed ONLY by the
   dedicated runtime-engine:infrastructure-worker (include list), excluded from the
   generic command-worker. Reuse the existing fencer RBAC (scale subresource). Add
   a KubernetesRuntimeFenceAdapter/WorkloadClient restore method (or extend the
   adapter) that scales the Deployment 0→configured replicas (target from the fence
   provenance pre_scale_replicas, else the configured replica count — never the live
   0-spec), idempotent (already-at-target → complete), with self-provenance across
   retries mirroring fdc745e.

4. Gates + completion: wait owned-Pod Ready + listener lease claimed + new open
   event epoch + RuntimeNode observed ready; accept the fresh-Pod emptiness contract
   (new Pod UID + new epoch + zero owned pods from the prior generation); then
   complete, emit a single runtime_node.restored event (matching
   runtime_node.desired_state_changed naming) with full restoration evidence
   (source fence op, target replicas, new Pod UID, new epoch, ready observation,
   timestamps), and leave the node desired_state=active so it re-enters placement
   ONLY after the restore operation completes. Failure taxonomy: retryable (scale
   fail, pod/lease/epoch/ready not yet), terminal (workload identity mismatch,
   permission denied), cancelled (desired_state changed away from active mid-restore)
   — never silently fall back to manual scale.

5. Do not reclaim moved conferences: rely on the existing (tested) generation +
   active-binding + event-fence protections; add no new placement reclaim path.

Tests: authorized/unauthorized restore request; tenant isolation; idempotent repeat;
scale 0→configured + readiness; lease/epoch recovery; placement blocked until restore
completes; worker restart mid-restore; retryable vs terminal vs cancelled; 
runtime_node.restored audit/outbox idempotency; fence→disabled transition.

Verify: make repository-hygiene, workflow-check, secret-scan, runtime-engine-config-check,
telephony-domain-config-check, asterisk-ari-config-check, asterisk-conference-config-check,
runtime-engine-test, telephony-domain-test, asterisk-ari-test, asterisk-conference-test,
asterisk-conference-recovery-test, git diff --check. No kubectl apply/scale, no live
failover, no migrate:fresh. Commit once:
feat(t5): canonical desired-state-driven former-node restoration
Do not push. End with the AGENTS.md report format. Recommended next: a live
T5-A35-style rerun that restores the fenced node via the desired-state action
instead of manual kubectl scale.
```

## T5-A37 — Canonical desired-state-driven former-node restoration

Repository-only implementation on `f887387`. No Kubernetes resource was applied,
no Deployment was scaled, no Pod was deleted, no live Conference was created, and
no live failover or live restoration was performed.

### Manual scale-up authority removed from normal lifecycle

T5-A35/T5-A36 proved that manual `kubectl scale deployment/<former-node>
--replicas=1` could bring the fenced RuntimeNode workload back, but that command
was only an operational proof tool. The normal authority is now the existing
RuntimeNode desired-state API. `kubectl scale` remains only a break-glass
infrastructure recovery action outside the UTCP management contract; it is not a
coequal restoration interface.

### Fence-to-disabled transition

Successful current-authority runtime-fence completion now leaves the former
RuntimeNode in `desired_state=disabled` through `RuntimeRegistryService`, not by a
raw model write. Both successful `fenced` and valid `already_fenced` outcomes
record source-fence disable provenance on the runtime operation and keep the
replacement node and moved RuntimeBindings untouched. The disabled state removes
the fenced node from placement candidacy while its Deployment is at zero replicas.

### Desired-state restoration authority

The existing `POST /runtime-nodes/{id}/desired-state` surface and
`runtime.nodes.manage` permission are the only normal restoration request path.
For a RuntimeNode disabled by the fence lifecycle, an authorized `active` request
schedules or reuses `runtime.node.restore` and leaves the persisted node
`disabled` while the asynchronous restoration runs. Unauthorized and cross-tenant
requests retain the existing failure behavior.

### Runtime restore operation and routing

The new operation type is `runtime.node.restore`. Its payload records the tenant,
RuntimeNode, requested `active` state, source fence operation and generation,
workload namespace and Deployment, target replicas, actor, optional reason, and
expected RuntimeNode configuration version. Its idempotency key includes the
RuntimeNode ID, source fence operation ID, source fence generation, and requested
active state. The generic telephony command worker excludes it; the dedicated
infrastructure worker includes it with `runtime.node.runtime.fence`. No new RBAC
or NetworkPolicy was added.

### Source-fence replica target and restore provenance

The scale target is taken from source-fence provenance:
`runtime_fence_provenance.scale_to_zero_requested.pre_scale_replicas`. The restore
handler rejects missing or invalid provenance visibly instead of guessing from the
live Deployment spec, an environment default, or a hard-coded replica count. When
it successfully requests `0 -> target`, the operation payload records
`runtime_restore_provenance.scale_to_target_requested` with the operation ID,
source fence operation, namespace, Deployment, pre-scale replicas, target replicas,
attempt, and timestamp. That provenance survives retries and worker recreation, so
a later attempt does not issue a duplicate effective scale request.

### Readiness, lease, epoch, and fresh-runtime gates

Completion is gated on Deployment desired/status/available replicas satisfying
the source target, owned Pods existing and Ready, no prior-generation owned Pod
remaining, a current `asterisk-ari-events` lease, a new open runtime event epoch
opened after restoration began, `observed_state=ready`, and no active open
Conference binding pointing back to the restored former node. The fresh-runtime
contract for this slice is fresh Pod identity plus new event epoch plus current
ready observation plus no old owned Pod plus no moved-Conference reclaim.

### Placement re-entry

The node remains `disabled` throughout scale-up and validation, so normal
placement remains blocked by the existing placement predicate. Only after all
restore gates pass does `RuntimeRegistryService` transition the node back to
`active` and emit `runtime_node.restored`. No placement allowlist, hidden toggle,
or automatic movement of Conferences back to the restored node was added.

### Failure taxonomy

Retryable outcomes include temporary scale failure, Deployment not yet at target,
owned Pod not yet created or Ready, missing current lease, missing new open epoch,
and RuntimeNode not yet observed ready. Terminal or stale outcomes include target
or ownership mismatch, missing or invalid source fence provenance, changed
configuration version, RuntimeNode no longer disabled, source fence no longer a
successful source for the node, recovered active binding authority, permission
failure, and Kubernetes client permission or target errors. Failures remain in the
runtime operation row; there is no silent fallback to manual scale-up.

### Focused regression coverage

Added focused coverage for fence-to-disabled idempotency, authorized and
unauthorized desired-state restoration requests, repeated request idempotency,
source-fence target selection, missing-provenance terminal failure, exactly-one
scale-up, restore provenance across worker recreation, Pod readiness retry,
lease/epoch/ready retries, authority-change cancellation, placement blocked until
completion, stale moved-Conference protection, and single `runtime_node.restored`
event emission. The main regression executes the real path from desired-state
request through operation persistence, infrastructure-worker handling, fake
Kubernetes workload client, retry evidence, readiness/lease/epoch gates,
desired-state activation, and outbox evidence.

### Live proof boundary

Live canonical restoration remains pending. The next destructive proof must roll
through a successful automatic failover, request former-node restoration through
the desired-state API, prove `runtime.node.restore` scales the workload back up
without manual `kubectl scale`, and confirm the restored node re-enters placement
only after `runtime_node.restored`.

## T5-A38 — Canonical restoration live proof: BLOCKED by a restore-handler activation-ordering deadlock

Checkpointed live proof at `UTCP_PHASE=T1`, HEAD `fcf1e90`. The restoration
implementation deployed and the failover→fence→**disable** half worked exactly
as designed, but canonical restoration reached `terminal_failed` on a
precisely-isolated design deadlock: the restore handler holds the node
`disabled` until after gates that can only be satisfied while the node is
`active`. Per the failed-restoration divergence contract, evidence was
preserved, the moved conference closed canonically, and node B returned to
health via break-glass (recorded separately, NOT counted as proof success).
**Verdict: T5_CANONICAL_FORMER_NODE_RESTORATION_LIVE_PROOF_INCOMPLETE.**

### Deployment and live code currency

Built the API image from clean `fcf1e90` (digest `sha256:4a3b1e0e…`), verified
it contains `runtime.node.restore`, `RuntimeNodeRestoreOperationHandler`,
`runtime_restore_provenance`, `runtime_node.restored`, and the fence-to-disabled
transition; pushed and rolled out api/scheduler/command-worker/reconciler/
event-normalizer/infrastructure-worker/worker. Confirmed live: restore handler
present in the infra worker, `disableAfterSuccessfulFence`/
`latestSuccessfulFenceForDisabledNode` in the api, `runtime.node.restore` in the
op-type config, TTL still 30. Routing verified: restore op excluded from the
generic command-worker, included in the infrastructure-worker.

### Failover + fence-to-disabled (WORKED)

Proof conference `516480a5-…3632`, participant `6d83294a-…3b76`, session
`3423f67b` (29.66 min), binding `5f9409d8-…e259`, bound node B (`05ddb383…`,
Pod `1f53ff0b…`), generation 2. HTTP disabled 09:38:38; node B unavailable
(restart 0, corrected probes held). Automatic chain:
verify_conference_absent (09:44:02) → terminal_failed (3/3
ari_http_transport_failed) → runtime.node.runtime.fence (09:45:03) → scale 1→0
→ `conference.runtime_fence_terminated` `fence_result=fenced` (09:45:19) →
**`runtime_node.desired_state_changed` to `disabled` (09:45:19)** →
`conference.runtime_binding_replaced` (09:46:03) → conference on node A gen 3,
reconstructed/ready. Exactly one verify, one fence, one termination, one active
binding. **New restoration acceptance met**: after fence, node B
`desired_state=disabled`, replicas 0, and placement-ineligible
(`placement_eligible=NO` via the canonical selector predicate).

### Source fence provenance (WORKED)

Source fence op `ceb411f5…`: succeeded, `pre_scale_replicas=1`, and
`runtime_fence_provenance.runtime_node_disabled.by_operation=true` recorded.

### Restoration request + idempotency (WORKED)

`POST /runtime-nodes/{nodeB}/desired-state {active}` (admin, `runtime.nodes.manage`)
returned 200 with node **remaining disabled** (correct — no direct mutation).
Repeated once: same 200, node still disabled, and **exactly one**
`runtime.node.restore` operation `92c5ba04…` scheduled (idempotent — the second
request reused it), with `source_fence_operation_id=ceb411f5…` and
`target_replicas=1` from fence provenance.

### Scale-up (WORKED) then DEADLOCK (FAILED)

The infrastructure worker claimed the restore op and performed exactly one
effective scale-up: node B Deployment 0→1, new Pod `c0b0d84a…`, replicas 1/1
Ready. Restore scale provenance persisted:
`runtime_restore_provenance.scale_to_target_requested` `{by_operation=true,
target_replicas=1, source_fence_operation_id=ceb411f5…}`. But the operation then
cycled `retry_scheduled` on `runtime_restore_listener_lease_missing` and reached
**`terminal_failed` (3/3 attempts)**. Zero `runtime_node.restored` events; node
B stuck `disabled`/`unavailable` with a running Pod.

### Root cause (precisely isolated design deadlock)

`RuntimeNodeRestoreOperationHandler::execute` gate order:
Deployment ready (L145) → Pods ready (L148) → **listener lease (L153-156)** →
event epoch (L157-160) → observed_state=ready (L162-168) →
`completeRestorationActivation` disabled→active (**L178, last**). The
lease/epoch/observed-ready gates require the UTCP listener to attach and project
observations — but `AsteriskAriEventListener::eligibleNodes`
(`AsteriskAriEventListener.php:577`) filters `whereIn('desired_state',
['active','draining'])`, so it never attaches to a `disabled` node. The node is
held `disabled` until L178, which only runs after those gates pass. **Circular
dependency:** activation needs the lease gate to pass; the lease gate needs the
node active. The retryable `RuntimeUnavailable` failure therefore exhausts
`max_attempts=3` into `terminal_failed`. The Kubernetes scale-up (0→1) is
correct; the deadlock is entirely in the post-scale readiness-gate ordering.
Note this also breaks the manual `kubectl scale` break-glass, since a
fence-disabled node's listener stays detached regardless of replicas.

### Required bounded correction (superseded by T5-A39)

The initial next-step idea was to activate the RuntimeNode before lease/epoch/
observed-ready validation. T5-A39 rejects that ordering because it would weaken
the restoration completion contract. The corrected design keeps the former node
`desired_state=disabled` and placement-ineligible until all restoration gates
pass. Listener eligibility is instead extended only when the disabled node has a
matching, current, actionable `runtime.node.restore` operation for the same
tenant, RuntimeNode, requested active state, source fence, and configuration
version. Pod Ready remains insufficient for restoration completion.

### Divergence handling performed

Failed-restoration contract followed: (1) all operation/provenance/Kubernetes
evidence preserved (restore op `92c5ba04…` terminal_failed with full provenance
retained); (2) proof marked incomplete; (3) moved conference `516480a5…` closed
canonically on node A (participant removed → desired-state closed → converged
`closed` 09:56:17) — moved-conference authority preserved (node A gen 3, never
reclaimed by node B); (4) **break-glass** applied only after evidence capture:
because the documented `kubectl scale` break-glass is inert here (Pod already at
1/1; the block is `desired_state=disabled`) and re-issuing the API disabled→active
would reschedule the same deadlocking op, node B was returned to health by a
direct `runtime_nodes.desired_state=active` write, after which the listener
re-attached (lease claimed, epoch opened, observed ready by 09:56:40). This
break-glass is exceptional recovery, **not** proof success. No manual scale, Pod
deletion, or RuntimeBinding edit was used.

### Final runtime state

Both Deployments 1/1; both RuntimeNodes active/ready; both leases claimed; one
open epoch per node; zero open conferences; zero live bridges/channels; zero
actionable operations; infrastructure worker Ready/idle; tree clean;
`UTCP_PHASE=T1`. Historical operations/bindings/events/audit retained (including
the terminal-failed restore op as evidence).

### Result

The fence-to-disabled transition, canonical desired-state restore request,
idempotent single restore operation, dedicated-worker claim, source-fence
provenance, and the automatic scale-up (0→target) are all PROVEN live. The one
blocking defect is the listener eligibility deadlock for fence-disabled nodes.
T5-A39 fixes listener eligibility through persisted restore-operation authority
without early activation; then re-run this exact T5-A38 proof.

## T5-A39 — Restore-authorized listener eligibility

Repository-only correction at `UTCP_PHASE=T1`, starting HEAD `a33636d`. No live
Kubernetes resources were applied, no live restoration or failover was run, and
no direct desired-state write was performed.

### Activation-ordering deadlock

T5-A38 proved the canonical restoration path reached Deployment scale-up and a
new Asterisk Pod Ready, but could not pass `runtime_restore_listener_lease_missing`.
The restore handler correctly required listener lease, a new event epoch,
`observed_state=ready`, and fresh-runtime validation before disabled→active
activation. The ARI listener's ordinary eligibility excluded every disabled node,
so the lease and epoch could never be created. The deadlock was listener
eligibility, not Kubernetes readiness, RBAC, NetworkPolicy, or scale behavior.

### Listener eligibility versus placement eligibility

Ordinary listener eligibility remains `desired_state IN (active, draining)`.
T5-A39 adds exactly one temporary disabled-node authority: a current actionable
`runtime.node.restore` operation whose row and payload match tenant ID,
RuntimeNode ID, expected configuration version, requested desired state `active`,
and source fence identity. Actionable statuses are `pending`, `leased`,
`running`, and `retry_scheduled`. Terminal and non-actionable statuses
(`succeeded`, `terminal_failed`, `cancelled`, `expired`) grant no disabled-node
listener eligibility.

Placement remains unchanged and separate: the canonical placement predicate
still requires `desired_state IN (active, draining)` plus `observed_state=ready`
and capabilities. A disabled node with a valid restore operation may receive a
listener connection so the restoration gates can recover, but it remains
placement-ineligible until the restore handler performs the final canonical
disabled→active transition.

### Restoration completion gates

T5-A39 does not complete restoration on Kubernetes Pod Ready alone. The existing
restore handler still waits for target Deployment replicas, owned Ready Pods, a
claimed listener lease, a new open event epoch after restoration began,
RuntimeNode `observed_state=ready`, and fresh-runtime validation. Only then does
it activate the RuntimeNode, complete `runtime.node.restore`, and emit one
`runtime_node.restored` event.

### Retry window calculation

The restore operation keeps the existing `max_attempts=3`. Operation retry
delays are attempt-based at 15 seconds then 30 seconds for the second retry
window, giving a normal convergence window after the first post-scale attempt.
The listener profile reconnect minimum is 1 second, the listener lease duration
is 45 seconds, the WebSocket open/inspection path ingests `connection_opened`
and `runtime_info_observed` in one cycle, and the normalizer can project
`observed_state=ready` on its next batch. This covers at least one listener
eligibility refresh, connection attempt, lease claim, epoch open, and observed-
ready projection without globally inflating operation retries.

### Query and index behavior

The listener precomputes restore-authorized RuntimeNode IDs from
`runtime_operations` filtered by operation type, actionable status, tenant, and
RuntimeNode, then applies those authorities inside the existing bounded
eligible-node query. Malformed payloads fail closed. A supporting index on
`runtime_operations(operation_type, status, tenant_id, runtime_node_id)` backs
the lookup without introducing Redis caching or another authority.

### Regression coverage

Focused tests prove active and draining nodes remain eligible; disabled nodes
without restore authority remain ineligible; pending/leased/running/
retry-scheduled restore operations grant eligibility only for the matching
tenant, node, configuration version, active request, and source fence; wrong
tenant, wrong node, stale configuration, wrong operation type, missing source
fence, and terminal/cancelled/expired operations do not grant eligibility; and
disabled restore-authorized nodes remain excluded from placement.

The real integration regression drives admin-style restore operation data through
the infrastructure worker, fake Kubernetes scale-up, ARI listener selection,
one failed listener connection, listener retry/recovery, lease and epoch
persistence, event normalization to RuntimeNode ready, restore handler
activation, operation completion, and one `runtime_node.restored` event. It also
asserts idempotency: no duplicate scale-up, activation, or restored event.

### Remaining proof boundary

Live canonical former-node restoration remains pending. Direct database recovery
remains break-glass only, and manual scale-up is not restored as normal
authority. The next live proof must rerun canonical desired-state-driven
former-node restoration with no direct desired-state write and no manual scale-up.

## T5-A40 — Canonical former-node restoration: COMPLETE (restore-authorized listener attachment)

Checkpointed live proof at `UTCP_PHASE=T1`, HEAD `9a12faa`. The complete
canonical restoration lifecycle succeeded end-to-end with **no direct
desired-state write and no manual Kubernetes scale-up** — resolving the T5-A38
activation-ordering deadlock. The fix (restore-authorized listener eligibility)
lets the listener attach to a still-`disabled` node that has an actionable
restore operation, so the lease/epoch/observed-ready gates pass before
activation.

### Database migration

`2026_07_17_150000_add_restore_listener_operation_index.php` — additive only
(one composite index `runtime_ops_type_status_tenant_node_idx` on
`runtime_operations(operation_type, status, tenant_id, runtime_node_id)`, no
rewrites). Applied via the canonical `utcp-migrate` Job (new image): ran once
(`... DONE 30.52ms`), index present, migration recorded exactly once, all 4524
`runtime_operations` rows preserved.

### Image build and rollout

Built from clean `9a12faa` (digest `sha256:038d0365…`), verified it contains
`restorationListenerAuthorities` (grep=2), the restore handler, the migration,
and `runtime_node.restored`; pushed and rolled out
api/scheduler/command-worker/reconciler/event-normalizer/**asterisk-ari-events
(the listener)**/infrastructure-worker/worker. Live currency confirmed:
`restorationListenerAuthorities` present in the listener and api pods; both
RuntimeNodes stayed ready throughout.

### Failover to fenced + disabled (re-proven)

Proof conference `9d423262-…2d89`, participant `75d4d4e0-…75cf`, session
`49a8df05` (~59 min validity — comfortably beyond the proof), binding
`66b22366-…bfc6`, bound node B (`05ddb383…`, Pod `c0b0d84a…`), generation 2.
HTTP disabled 11:14:53 → node B unavailable (restart 0). Chain: verify (11:20:04)
→ terminal_failed → fence (11:21:04) → scale 1→0 → `fence_result=fenced`
(11:21:19) → `runtime_node.desired_state_changed:disabled` (11:21:19, exactly
one) → `conference.runtime_binding_replaced` (11:22:04) → conference on node A
gen 3. Source fence op `455fe0de…`, `pre_scale_replicas=1`.

### Placement exclusion while disabled

Node B `disabled`/replicas 0/owned Pods 0; zero active open-conference bindings
reference it; canonical selector `eligible=NO`.

### Restoration request + idempotency

`POST /runtime-nodes/{nodeB}/desired-state {active}` (admin,
`runtime.nodes.manage`) → 200, node **remained disabled** (no controller scale,
no direct activation). Repeated once → 200, still disabled, **exactly one**
`runtime.node.restore` op `daa5381f…` (second request reused it). No direct
desired-state write anywhere.

### Dedicated worker + scale-up + provenance

The infrastructure worker exclusively claimed the restore op (excluded from the
generic command worker). Exactly one effective scale-up: node B Deployment 0→1
(target from source-fence `pre_scale_replicas=1`), new Pod. Persisted
`runtime_restore_provenance.scale_to_target_requested`: `by_operation=true`,
`target_replicas=1`, `source_fence_operation_id=455fe0de…`.

### Restore-authorized listener attachment (the fix) — observed ordering

Timestamped sequence:

```text
11:23:21  restore op created, retry_scheduled/runtime_restore_deployment_not_ready
11:23:46  Deployment 0→1, new Pod Ready; node STILL disabled; lease released; no epoch
11:23:58  node disabled/READY; lease CLAIMED; new open epoch=1
          → listener attached to the DISABLED node purely via the actionable
            restore operation (AsteriskAriEventListener::eligibleNodes now
            includes disabled nodes with a matching actionable restore op)
11:24:08  runtime_node.restored emitted
11:24:11  restore op SUCCEEDED; node desired_state disabled → active; observed ready
```

Activation occurred strictly AFTER Pod readiness + lease claim + new epoch +
observed-ready — Pod readiness alone did not complete restoration. Arbitrary
disabled nodes remain listener-ineligible (eligibility requires a matching
actionable restore op keyed on tenant + node + configuration_version).

### Restoration completion and re-entry

Exactly one `runtime_node.restored` event (11:24:08) and exactly one restore op
(no duplicate scale/operation/event/transition). After completion node B is
`active`/`observed ready`, and the canonical placement selector now returns
`eligible=YES` (placement re-entry). Note: the op consumed 3/3 retry attempts,
succeeding on the final attempt once the listener attached (~12–24 s after the
Pod became ready) — the retry budget was sufficient but tight; a wider budget
would add margin (non-blocking observation).

### Moved-conference authority preservation

Throughout restoration the moved conference stayed on node A (`1d15ca88`)
generation 3, observed ready; the node-B binding remained `retired`; exactly one
active binding on node A; participant remained `admitted`. Restoration did not
move the conference back and no former-node event reclaimed authority.

### Cleanup and final state

Participant removed and conference closed canonically (converged `closed`
11:26:25). Final: both Deployments 1/1; both RuntimeNodes active/ready; both
leases claimed; one open epoch per node; zero open conferences; zero live
bridges/channels; zero actionable operations; infrastructure worker Ready/idle;
tree clean; `UTCP_PHASE=T1`. No manual scale-up, no direct desired-state write,
no RuntimeBinding write, no break-glass. Historical evidence retained.

### Result

**T5_CANONICAL_FORMER_NODE_RESTORATION_LIVE_PROOF_COMPLETE.** All 31 completion
criteria met. The full production restoration lifecycle is proven live: automatic
fence → former node disabled + placement-excluded → authorized desired-state
`active` request → single idempotent `runtime.node.restore` → infrastructure-worker
scale-up 0→pre_scale_replicas → restore-authorized listener attachment while
disabled → lease + fresh epoch + observed-ready → activation only after all gates
→ single `runtime_node.restored` → placement re-entry. The proof-only manual
`kubectl scale` restoration is fully superseded by canonical desired-state-driven
authority.

## T5-A41 — Replacement-node failure handling contract (evidence-only)

Evidence-only audit at `UTCP_PHASE=T1`, HEAD `b4677a2`. Determines control-plane
behavior when the selected replacement RuntimeNode fails during failover/
reconstruction. **Headline: replacement-node failure is already handled
resiliently by the existing architecture** — replacement identity is never
pinned (re-selected fresh at each rebind), repeated rebind is supported across
arbitrary generations, and the coordinator re-drives automatically. The only
genuine gaps are (a) no durable/observable "no-capacity / failover-pending"
state and (b) missing test coverage for the second-failover and restored-node-
reuse paths.

### Replacement selection authority

Replacement identity is **not persisted anywhere** and **not part of any
idempotency key**. It is re-selected fresh inside the rebind transaction:
`TelephonyDomainService::failoverRebindConference` calls
`selectRuntimeNodeForConference(tenantId, caps, excludeCurrentNode, ['active'])`
(`TelephonyDomainService.php:417-422`) within the same `DB::transaction` that
locks conference+binding+former-node, retires the old binding, inserts the new,
and bumps generation. The fence op payload carries only the FORMER node id, never
a `replacement_runtime_node_id`. Three points touch replacement selection —
coordinator pre-fence probe (`RuntimeFencingCoordinator.php:239`), fence-handler
last-instant probe (`RuntimeFenceOperationHandler.php:61`), and the authoritative
fresh selection at rebind (`TelephonyDomainService.php:417`) — but only the last
binds; the first two are check-only (`hasDistinctEligibleReplacement`, no
persistence). One caveat: the chosen replacement row is read with `->first()`
without `lockForUpdate` (`TelephonyDomainService.php:812`), so it is validated but
not row-locked during the rebind.

**Reselection owner (Model A, already de facto):** the `ConferenceFailoverCoordinator`
everyMinute sweep is the single canonical reselection authority — it re-drives
the verify→fence→rebind chain, and each rebind re-selects fresh. There is no
duplicated/conflicting reselection ownership.

### Failure Window A — before verification completion

Former node unavailable, no fence yet. The coordinator's `hasDistinctEligibleReplacement`
gate (`RuntimeFencingCoordinator.php:239`) is checked before requesting the fence;
if no replacement exists it returns `no_replacement` and the verify chain is not
escalated. The grace timer is derived from `runtime_observations` (durable), not
an in-memory timer, so it is preserved across sweeps. Verification is created but
fencing is withheld until capacity exists. No destructive action; nothing to
roll back.

### Failure Window B — after verification terminal_failed, before fence creation

The terminal verification is retained as historical evidence and re-usable: the
generation-scoped verify idempotency key means the same terminal-failed op is
re-found each sweep, and the T5-A26 escalation re-evaluates it against current
authority + `hasDistinctEligibleReplacement`. A later replacement uses the same
verification evidence (no new verify chain needed). Duplicate fence requests are
prevented by the fence idempotency key `sha256(conf:formerNode:binding:generation)`.

### Failure Window C — fence created, before scale-to-zero

`RuntimeFenceOperationHandler::execute` performs a last-instant
`hasDistinctEligibleReplacement` check (`:61-67`) BEFORE `scaleDeployment(0)`; if
the replacement vanished, it fails `no_replacement_available`
(`FailureClass::RuntimeUnavailable`, retryable) with no scale mutation. The op
retries; the coordinator maps it to `no_replacement` and re-drives. A different
replacement may satisfy the next attempt (nothing pins the earlier one).

### Failure Window D — former workload already scaled to zero (first irreversible boundary)

The replacement-before-fence guard means a replacement existed at scale time, but
the rebind re-selects fresh (TOCTOU). If the replacement disappears between
scale-to-zero and rebind, `failoverRebindConference` aborts 422 BEFORE retiring
the old binding (selection at `:417` precedes retire at `:429`, inside a
transaction that rolls back) — so the conference stays `desired_state='open'`,
bound to the now-fenced former node, at unchanged generation G. The coordinator
records `conference.failover_coordinator.no_replacement` and re-drives every
minute; the fence op is idempotent (`already_fenced`), so when ANY capable node
returns ready the rebind succeeds and reconstruction proceeds. **Safe and
self-healing**, but the conference has no live runtime (no bridge anywhere) for
an unbounded window with only event-level visibility — the observability gap.

### Failure Window E — replacement binding committed, bridge not ready

`ConferenceReconciler` drives `conference.ensure` on the new node via
`activeRuntimeNodeId` (current active binding). If the new node's inspection is
`unavailable`/`failed`, it returns `waiting(...,30s)` — not block/error
(`ConferenceReconciler.php:52-54`). If the new node then crosses the
unavailable/stale grace, the coordinator's candidate query re-fires (it keys on
the CURRENT active binding node + observed_state, no generation/history gate), so
the conference enters a **second automatic failover** producing G+2 with a fresh
binding to another node. The prior replacement binding is retired atomically by
the next rebind; the same open conference can be rebound repeatedly.

### Failure Window F — bridge reconstructed, participant reconstruction incomplete

Same mechanism: `ConferenceParticipantReconciler` returns `waiting` on inspection
unavailable (`ConferenceParticipantReconciler.php:53-55`), and a failed
replacement re-enters failover. Partial resources on the failed node are reclaimed
because that node is itself fenced (scale-to-zero + `disabled`) on the next
rebind, and stale-authority guards (`operationAuthorityCurrent`, generation
checks) neutralize its late operations. Reconstruction on the next node is
idempotent via **deterministic ids**: `conferenceBridgeId` = `utcp-conf-<conf>`,
`participantChannelId` = `utcp-part-<participant>` (`AsteriskAriClient.php:517-525`).

### Failure Window G — former node restoration in progress

A former node mid-restoration has `desired_state='disabled'`
(`RuntimeNodeRestoreOperationHandler.php:62-64`), so it is excluded from
`selectRuntimeNodeForConference` (requires active/draining) — it cannot be
grabbed as an emergency replacement while restoring. Restoration continues
independently. After `runtime_node.restored` (desired_state=active, observed
ready) it becomes a normal placement/replacement candidate again (nothing
excludes prior-owner nodes; only the currently-bound node is excluded), safe by
generation.

### Destructive authority boundaries

| Boundary | Owner | Idempotency | Replacement may still change after? |
|---|---|---|---|
| verify terminal persistence | coordinator | verify key (former node+gen) | yes (not selected yet) |
| runtime-fence op creation | coordinator | fence key (former node+gen) | yes |
| scale-to-zero accepted | fence handler/adapter | self-scale provenance | yes (rebind re-selects) |
| owned former Pods → 0 | adapter | provenance | yes |
| old binding retired | rebind tx | row locks + expected_binding/node | **no** (bound at same instant) |
| new binding committed | rebind tx | one-active partial index | no (until next failover) |
| generation incremented | rebind tx | monotonic +1 | n/a |
| bridge ensure accepted | reconciler/adapter | deterministic bridge id + generation | via next failover only |
| participant ensure accepted | reconciler/adapter | deterministic channel id + generation | via next failover only |

Replacement identity is free to change at every boundary EXCEPT the atomic
retire+insert, where selection and binding happen in one locked transaction.

### Multi-generation support

Fully supported and generation-agnostic. `failoverRebindConference` works for any
open conference regardless of prior rebinds; generation bump is `+1` relative to
current (`:437`); the partial unique index `conference_runtime_bindings_one_active`
enforces exactly one active binding while allowing unlimited retired rows;
stale protections compare against the LIVE generation (operation-side `< current`,
event-side active-binding join), so G, G+1 events/ops all go stale once at G+2.
No path is hard-coded to a single replacement.

### No-capacity state

When fenced with no replacement: conference `desired_state='open'`, bound to the
fenced former node (observed unavailable/stale), generation G; `conference_runtime_bindings`
has exactly one active (former-node) binding intact; the only signal is a
recurring `conference.failover_coordinator.no_replacement` audit+outbox event each
sweep. **There is no dedicated conference desired/observed enum for "failover
blocked / no capacity / degraded"** (conferences.desired_state ∈
draft/open/draining/closed; observed_state ∈ unobserved/provisioning/ready/
degraded/unavailable/closed — `degraded`/`unavailable` are observation-driven,
not a failover-pending marker). Recovery is automatic when capacity returns (the
sweep re-drives). Gap: no durable, queryable "failover-pending/no-capacity"
state, and the no_replacement event repeats unboundedly (audit/outbox noise).

### Replacement reselection authority

**Model A — coordinator-owned reselection (already implemented).** The
`ConferenceFailoverCoordinator` sweep re-drives the chain and `failoverRebindConference`
re-selects fresh; the reconstruction handlers and reconcilers do NOT re-select
(they `waiting` and let the coordinator re-fire). Single canonical owner, no
duplication. No bounded change to ownership is required.

### Operation and idempotency contract

Verify/fence ops are generation-scoped idempotent (former node + binding +
generation). Rebind uses no key but is protected by row locks + `expected_binding_id`/
`expected_runtime_node_id` + generation. A second failover (G+1→G+2) naturally
produces new verify/fence ops keyed on the NEW bound node + new generation, so it
never collides with the G→G+1 chain's keys. Repeated sweeps, worker restarts, and
capacity-returns are all handled by re-discovery + idempotent keys; historical
ops are retained (never rewritten/deleted).

### Partial-resource cleanup

No explicit domain cleanup of a failed replacement's bridge/channels — reclaimed
by fencing that node (scale-to-zero terminates its Pod) plus stale-authority
guards, with deterministic ids making reconstruction on the next node idempotent.
Cleanup is best-effort and not completion-gated; an unreachable failed replacement
is handled by scale-to-zero (workload-placement authority), not ARI cleanup.

### Restored-node eligibility

A restored former node (active/ready) is re-selectable as a replacement for a
still-open conference — nothing excludes prior-owner/retired-binding nodes; only
the currently-bound node is excluded. Safe by generation. Reuse is permitted, not
forced.

### Failure taxonomy

| Classification | Mapping |
|---|---|
| replacement_unavailable_before_fence | `no_replacement` (RuntimeUnavailable, retryable) — no fence, re-drive |
| replacement_unavailable_after_fence | `no_replacement_available` (RuntimeUnavailable, retryable) — no scale, re-drive |
| replacement_lost_before_binding_commit | rebind 422 → `no_replacement`; tx rollback, binding preserved |
| replacement_lost_after_binding_commit | second automatic failover (G+1→G+2), terminal-for-current-generation but recoverable |
| replacement_reconstruction_failed | reconciler `waiting`; if node crosses grace → next failover |
| replacement_participant_reconstruction_failed | same as reconstruction_failed |
| no_replacement_capacity | `no_replacement`, non-terminal, auto re-drive; **not durably observable (gap)** |
| replacement_identity_stale | not applicable — identity never pinned; fresh selection each rebind |

### Events and observability

Existing: `conference.failover_coordinator.{rebound,no_replacement,verification_failed,...}`,
`conference.runtime_fence_terminated`, `conference.runtime_binding_replaced`,
`runtime_node.desired_state_changed`, `runtime_node.restored`. Gaps: (a) no
durable per-conference failover-pending signal; (b) `no_replacement` repeats every
sweep with no dedup/backoff. Future metrics (failover latency, no-capacity
duration, rebind count per conference) are noted, not implemented here.

### Existing test coverage

Covered: no-replacement rollback/authority preservation
(`TelephonyDomainTest.php:415,507`), distinct-replacement queries (`:462,470,479`),
coordinator `no_replacement` continues sweep (`:637,936,996`), idempotent second
sweep after ONE rebind (`:665`), draining excluded (`:697`), stale-after-rebind
(`AsteriskConferenceRecoveryTest.php:970`), generation-advance stale
(`:912,1140`). **Missing:** (1) full second automatic failover G+1→G+2 (rebind to
B, B fails, rebind to C) with one active binding + monotonic generation; (2)
stale G+1 ops/events ignored after G+2; (3) partial bridge/participant on a failed
replacement reclaimed + idempotent reconstruction on C; (4) restored former node
re-selected as a valid replacement; (5) capacity-returns-later auto-recovery from
a no_replacement hold; (6) durable no-capacity state (once implemented).

### Session-lifetime configuration observation

**Classification: benign rollout/config drift; bounded correction available.**
The committed overlay `infrastructure/kubernetes/overlays/local/platform/application-config.properties`
sets `UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=30` and `kubectl kustomize
overlays/local/platform` renders `utcp-application-config` with the key = "30".
But the LIVE ConfigMap `utcp-application-config` is the original 2026-07-13 object
(resourceVersion 225896, never updated) and contains only `SESSION_DRIVER/
HTTP_ONLY/SAME_SITE/SECURE_COOKIE` — **no `UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES`**.
So pods (envFrom this ConfigMap) have the var unset and the code falls back to
`env(..., 60)`. The `ab4418d` overlay change was never durably applied to the
live cluster via the canonical `kube apply -k overlays/local/platform`. Non-
blocking (60 min > any proof window). Bounded correction: re-apply the platform
overlay ConfigMap (canonical security/platform apply path) and roll the app pods
so the live value matches the committed 30.

### Readiness decision

**A — bounded Codex implementation is ready.** The audit establishes: one
canonical reselection owner (coordinator, already implemented, no duplication);
failure behavior for all seven windows; multi-generation support (proven by
design); the exact no-capacity behavior (safe + auto-re-drive, but not durably
observable); operation/idempotency contract; partial-resource handling; restored-
node eligibility; failure taxonomy; event contract; and the exact test gaps. The
core resilience already exists — the bounded slice is narrow: a durable/observable
no-capacity ("failover_pending") signal + no_replacement event de-duplication,
plus the missing multi-generation / restored-node-reuse / capacity-returns tests.

### Ready-to-paste next prompt

```text
You are working in the Unified Telephony Control Plane repository.

Perform one bounded implementation (no live failover, no scaling):

# T5-A42 — Observable no-capacity failover state + multi-generation test coverage

HEAD b4677a2, branch main, clean tree, UTCP_PHASE=T1. Evidence basis:
docs/evidence/t2/multi-node-failover-readiness.md §T5-A41.

Context (already proven, do NOT change): replacement identity is re-selected
fresh at each rebind; the ConferenceFailoverCoordinator is the single canonical
reselection owner; repeated rebind (G→G+1→G+2) is supported; stale protections
are generation-relative; no-replacement rolls back before retiring the old
binding. Preserve all of this. Establish ONE canonical no-capacity signal and add
the missing multi-generation tests; remove no duplicated/conflicting behavior.

Implement the smallest coherent slice:
1. Durable, observable no-capacity signal. When the coordinator resolves a
   fenced-or-unavailable open conference to `no_replacement`, persist a durable,
   queryable marker WITHOUT introducing a second authority or a new conference
   desired_state: prefer a bounded field/row (e.g. a failover-pending marker on
   the conference row or a dedicated single-row-per-conference failover-state
   record) recording {reason=no_capacity, since, last_attempt_at, source
   fence/binding generation}. It MUST clear automatically when a rebind succeeds
   (do not require operator action). Do not add an enum value that breaks the
   existing conferences.desired_state/observed_state CHECK constraints unless the
   migration updates the constraint additively.
2. De-duplicate the no_replacement event: emit the audit/outbox
   conference.failover_coordinator.no_replacement (or a new
   conference.failover_pending event, following existing naming) only on
   transition INTO the no-capacity state (and on recovery), not every sweep —
   using the durable marker to suppress repeats. Keep exactly one canonical event
   per authority transition.
3. Add focused tests (no live cluster): (a) full second automatic failover
   G+1→G+2 — rebind to B, B goes unavailable past grace, coordinator re-fires,
   rebind to C, asserting exactly one active binding, monotonic generation, old
   bindings retired; (b) stale G+1 operations/events are ignored after G+2; (c) a
   restored former node (active/ready) is a valid replacement candidate for a
   still-open conference; (d) capacity-returns-later: a conference held in
   no-capacity rebinds automatically once a ready node appears, and the durable
   marker clears; (e) the no_replacement event is emitted once per transition,
   not per sweep.
4. Do not: pin replacement identity in the fence op, add a manual
   replacement-selection interface, add an environment gate/allowlist/manual
   reconciliation trigger, change the reselection owner, or delete historical
   operations/events.

Verify: make repository-hygiene, workflow-check, secret-scan,
runtime-engine-config-check, telephony-domain-config-check,
asterisk-ari-config-check, asterisk-conference-config-check, runtime-engine-test,
telephony-domain-test, asterisk-ari-test, asterisk-conference-test,
asterisk-conference-recovery-test, git diff --check. No kubectl/live failover/
migrate:fresh. If a migration is added it must be additive and constraint-safe.
Commit once:
feat(t5): observable no-capacity failover state and multi-generation coverage
Do not push. End with the AGENTS.md report format.

Separately (optional, benign config drift from §T5-A41): re-apply the committed
platform ConfigMap so the live UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES matches
the committed 30 — this is a live-ops correction, not part of the code slice.
```

## T5-A42 observable no-capacity failover state

T5-A42 converts the T5-A41 no-capacity observability gap into durable
Conference-owned state while preserving the coordinator as the only replacement
reselection authority. When an open Conference remains bound to an unavailable
RuntimeNode and no distinct ready, capable replacement exists, the coordinator
now marks the Conference with `failover_state=pending_no_capacity`,
`failover_binding_id`, `failover_generation`, and `failover_started_at`.

The state is scoped to the active binding and generation that first observed
`no_replacement_available`. Repeated coordinator sweeps for the same binding and
generation retain the original `failover_started_at` and do not rewrite
transition evidence. The existing
`conference.failover_coordinator.no_replacement` event is now transition-only
for that authority instead of sweep-noisy recurring evidence.

Pending no-capacity state clears automatically through existing lifecycle
authority:

- successful rebind clears the old binding/generation in the same transaction
  that retires the old binding, inserts the replacement binding, and advances
  the Conference generation
- Conference closure clears any pending failover state
- current RuntimeNode recovery before destructive rebind clears the matching
  pending state
- stale operations or older generations cannot clear pending state for a newer
  binding/generation

The existing Conference read resource exposes the read-only failover fields so
operators can observe capacity loss without a new management endpoint. Clients
cannot mutate these fields directly; they remain control-plane-owned lifecycle
observations.

Focused repository coverage now exercises capacity returning after
`pending_no_capacity`, no-replacement event de-duplication, current-node recovery
clearing, closure clearing, stale-generation protection, authorized and
cross-tenant read behavior, full replacement generation advancement from G to
G+1 to G+2, stale prior-generation protections, partial bridge and participant
reconstruction conventions, and safe reuse of a restored former RuntimeNode as a
future replacement candidate.

The local overlay still renders
`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=30`; applying the live ConfigMap remains
a separate rollout correction. No Kubernetes resources were applied, no live
Conference was created, and no live failover or restoration proof was performed
for T5-A42. Live replacement-node failure, capacity-return recovery, and
multi-generation proof remain pending.

## T5-A43 — No-capacity pending state proven; second-generation recovery BLOCKED (environment + restore-retry robustness)

Checkpointed live proof at `UTCP_PHASE=T1`, HEAD `26161b5`. The observable
no-capacity feature (durable `pending_no_capacity` + single-event dedup +
clear-on-closure) is PROVEN live. Two blockers prevented the full second-
generation recovery: (1) an environmental artifact — accumulated C4 simulator
proof nodes flapping to `observed_state=ready` present phantom conference-
replacement capacity, defeating the "no capacity" precondition and allowing
node A to be fenced; (2) a restore-robustness gap — concurrent restore ops
terminal-fail on the tight 3-attempt retry budget, and a terminal-failed restore
cannot be canonically re-requested (its generation-scoped idempotency key
re-finds the terminal op), leaving nodes stuck disabled and requiring break-glass.
**Verdict: T5_NO_CAPACITY_RECOVERY_AND_SECOND_GENERATION_LIVE_PROOF_INCOMPLETE.**

### Migration and ConfigMap correction

`2026_07_17_160000_add_conference_failover_pending_state.php` (additive: 4
nullable `failover_*` columns + 2 indexes + 2 CHECK constraints) applied via the
canonical migrate Job: recorded once, all 110 conferences preserved, zero
falsely marked pending. The session-lifetime drift from §T5-A41 was corrected —
`kube apply` of the platform `utcp-application-config` ConfigMap set live
`UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=30`, and running pods confirmed env=30.

### Image rollout and currency

Built from clean `26161b5` (digest `sha256:abed05db…`), verified pending-state +
coordinator + migration code; rolled out api/scheduler/command-worker/reconciler/
event-normalizer/listener/infra-worker/worker. Live currency confirmed
(coordinator `markConferenceFailoverPendingNoCapacity`, domain
`FAILOVER_STATE_PENDING_NO_CAPACITY`, TTL=30).

### First failover G → G+1 (proven)

Proof conference `8bc7d2c3-…b655`, participant `5554d1e5-…0e44`, session
`a713f9ac` (29 min), initial node B (05ddb383) at G (gen 2). HTTP disabled on
node B (12:49:59) → verify terminal_failed → fence `fenced` (12:56:02) → node B
disabled → rebind to node A (1d15ca88) at G+1 (gen 3) → bridge+participant
reconstructed (2 channels), participant admitted. One active binding, node B
disabled/excluded, no pending fields.

### Second outage and no-capacity entry (proven — the 26161b5 deliverable)

HTTP disabled on node A (12:58:01); node B left disabled. verify node A
terminal_failed (13:03) → fence node A (13:04:02) → rebind found no replacement
(13:05:03) → **`failover_state=pending_no_capacity`, `failover_binding_id=094a4f50`
(the G+1 binding), `failover_generation=3`, `failover_started_at=13:05:03`**.
Exactly one `conference.failover_coordinator.no_replacement` event.

### Event dedup across repeated sweeps (proven)

Across ~9 coordinator sweeps (13:05→13:10), the pending fields and started-at
timestamp remained stable, the no_replacement event count stayed at **exactly 1**
(no duplicate events, no duplicate verify/fence chains, no binding retirement of
the current binding beyond the fence, no manual reconciliation). Cross-checked:
conference stayed authoritatively on node A (asterisk) — never rebound to a
simulator node.

### Pending clears on closure (proven)

Closing the proof conference (participant remove → desired-state closed)
immediately cleared `failover_state` to null and removed the conference from
failover candidacy — demonstrating the clear-on-closure path.

### BLOCKER 1 (environmental) — phantom capacity defeated "fencing withheld" (criterion 9)

The prompt required the pre-fence guard to WITHHOLD fencing node A while no
replacement exists (node A stays replicas=1). Instead node A was fenced (scaled
to 0, disabled at 13:04:02). Root cause: the cluster carries ~49 leftover **C4
simulator proof RuntimeNodes** with `conference.lifecycle`+`conference.participation`
capabilities in `desired_state=active`, whose `observed_state` **flaps to `ready`**
as the simulator-event-source runs (confirmed: "C4 Live Proof transient" ready
13:07:55, "C4 Live Proof reconnect" ready 13:00:24). `hasDistinctEligibleReplacement`/
`selectRuntimeNodeForConference` filter on `desired_state IN (active) AND
observed_state='ready'` + capabilities — they do not exclude simulator-family
nodes. At the fence decision (~13:04) a C4 node was transiently `ready`, so the
guard saw capacity and the fence proceeded; by rebind (13:05) it had gone stale,
so the rebind found none → pending. This is the T5-A41 Window-D TOCTOU realized
via flapping test-fixture nodes: the guard behaved correctly given its inputs,
but the "no capacity" precondition was violated by phantom transient simulator
capacity. Net: criterion 9 not met, for an environmental reason, not a 26161b5
defect. (The conference nonetheless reached the correct observable
pending_no_capacity state.)

### BLOCKER 2 (code robustness) — canonical restoration terminal-failed and is not re-requestable

During cleanup, both nodes were restored canonically via the desired-state API.
Both `runtime.node.restore` ops **terminal_failed at 3/3 attempts** — node A on
`runtime_restore_pods_not_ready`, node B on `runtime_restore_listener_lease_missing`
— because two restores ran concurrently and the pods/listener took longer than
the tight 3-attempt retry budget (the T5-A40 "tight but sufficient" margin,
exhausted here under concurrent load). The Deployments did scale 0→1 (pods up),
but the ops failed before the readiness/lease/observed gates passed. Re-requesting
`desired-state active` returned HTTP 200 but scheduled **no new restore op** — the
terminal-failed op's generation-scoped idempotency key
(`findIdempotent(runtime.node.restore, …)`) re-found the terminal op, so no fresh
restore was created — leaving both nodes stuck `disabled` with running,
listener-detached pods. `latestSuccessfulFenceForDisabledNode` still finds the
source fence, but the idempotency short-circuit blocks the retry. This is a real
robustness gap: (a) the restore retry budget is too small for concurrent restores
/ slow pod readiness; (b) a terminal-failed restore permanently blocks canonical
re-restoration for that node+generation.

### Divergence / break-glass recovery (recorded separately, NOT proof success)

Per the terminal-restoration-failure contract: all operation/provenance/binding/
Pod evidence preserved (both terminal-failed restore ops retained); proof marked
incomplete; the moved conference was closed canonically first (removing simulator-
rebind risk and clearing pending). Because canonical re-restoration was blocked
(idempotency) and `kubectl scale` is inert (pods already 1/1; the block is
`desired_state=disabled`), both nodes were returned to health by a direct
`runtime_nodes.desired_state=active` write; the listener re-attached, leases
claimed, observed ready by 13:26:05. This break-glass is exceptional recovery,
explicitly not counted as proof success.

### What was NOT proven

Criterion 9 (fencing withheld while no capacity) — blocked environmentally.
Capacity-return recovery, the resumed G+1 fence chain, the G+1→G+2 second
generation, pending-clear-via-rebind, and G+2 reconstruction — not exercised,
blocked by the restoration terminal-failure and the phantom-capacity confound
(a flapping simulator node could otherwise have captured the rebind).

### Final state

Both Deployments 1/1; both RuntimeNodes active/ready (via break-glass); both
leases claimed; one open epoch per node; proof conference closed (failover fields
cleared); zero open conferences; zero pending; zero live channels; tree clean;
`UTCP_PHASE=T1`. Historical operations/bindings/events retained.

### Tests

telephony-domain-test 46→54 and asterisk-conference-test 77→85 (26161b5 added
pending-state coverage). All focused suites pass at HEAD.

### Required corrections before re-run (for the next bounded task, T5-A44)

1. **Environment/test-data:** retire the leftover C4 simulator proof RuntimeNodes
   (or otherwise ensure no non-Asterisk conference-capable node is `active`+
   flapping-`ready`) so the two-node no-capacity precondition holds. Consider
   whether replacement selection should require observation stability (not a
   single flapping `ready`) or exclude simulator-family nodes for asterisk
   conferences — noting conferences are runtime-neutral by design.
2. **Restore robustness (code):** widen/reset the `runtime.node.restore` retry
   budget so a scaled-up node's readiness/lease/observed gates are actually
   reachable (especially under concurrent restores), AND allow a re-requested
   restoration to supersede a terminal-failed restore op (per-attempt idempotency
   scope, or clear the terminal op's key on a fresh authorized request) so a node
   is never permanently stuck disabled requiring break-glass.
Then re-run this exact T5-A43 proof.

## T5-A44 repository correction: restore retry window and successor operations

T5-A43 isolated two repository defects in canonical former-node restoration. Both
former RuntimeNodes scaled from `0` to `1` and created running Pods, but normal
asynchronous convergence (`runtime_restore_pods_not_ready` followed by
`runtime_restore_listener_lease_missing`) exhausted the restore operation's
three-attempt budget. The terminal restore operation then became a permanent
idempotency blocker: a later authorized disabled-to-active desired-state request
returned the historical `terminal_failed` operation instead of creating fresh
restore authority.

The T5-A44 correction keeps the normal authority chain intact:
`POST /runtime-nodes/{id}/desired-state` remains the canonical restoration
request surface, `runtime.node.restore` remains the persisted asynchronous
authority, and the infrastructure worker remains the only Kubernetes scale and
restoration executor. No Kubernetes manifest, RBAC, NetworkPolicy, placement,
replacement-selection, fencing, Asterisk probe, RuntimeBinding, session, CLI, or
environment-gate behavior was changed.

The restore operation now has a restore-specific convergence window of eight
attempts. With the repository retry schedule, failed attempts are delayed by
15, 30, 45, 60, 75, 90, and 105 seconds, giving roughly seven minutes of retry
delay after the initial attempt. This covers normal Pod scheduling/startup,
startup/liveness/readiness probe convergence, two listener attachment and
projection cycles, lease claim, event epoch creation, RuntimeNode observed-ready
projection, and ordinary worker scheduling jitter. The default runtime-operation
budget remains three attempts for unrelated operation types.

Terminal restore operations remain immutable history. A current actionable
restore (`pending`, `leased`, `running`, or `retry_scheduled`) is reused. A
successful restore for the current source fence and configuration authority is
returned and never superseded. A terminal, cancelled, or expired restore can be
superseded only by a new authorized active desired-state request while the
RuntimeNode remains disabled and current source-fence/configuration authority is
still valid. The successor payload records
`supersedes_restore_operation_id` and `restore_attempt_generation`, and its
deterministic idempotency key includes the RuntimeNode, source fence operation,
source fence generation, configuration version, requested active state, and the
terminal predecessor ID. Repeated or concurrent requests for the same predecessor
therefore converge on one successor; if that successor terminal-fails, a later
authorized request can create the next deterministic successor.

Listener eligibility remains operation-authorized: terminal predecessors do not
make disabled RuntimeNodes listener-eligible, while an actionable successor does.
Placement remains blocked until the restoration handler completes all gates and
transitions the node back to `active`.

Focused repository coverage now includes delayed Pod/listener convergence within
the expanded window, concurrent RuntimeNode restoration, terminal-predecessor
successor creation, repeated successor requests, multi-successor sequencing,
successful-predecessor reuse, cancelled/expired predecessor supersession,
configuration-version authority changes, listener authority through actionable
restore operations, and preservation of exactly one scale and one
`runtime_node.restored` event for a successful chain. The next live rerun must
also clean up or canonically disable leftover simulator proof RuntimeNodes before
establishing the two-node no-capacity baseline. No simulator-family exclusion was
added in this repository correction.

## T5-A45 — No-capacity recovery proven; second-generation (G+1→G+2) BLOCKED by a deterministic RuntimeNode-ownership prefix-collision bug

**Verdict: `T5_NO_CAPACITY_RECOVERY_AND_SECOND_GENERATION_LIVE_PROOF_INCOMPLETE`.**
Deployed `51abc7a` and completed the interrupted no-capacity proof through automatic
capacity-return detection. The `G+1 → G+2` second-generation rebind could not complete
because a **deterministic bug in `HttpKubernetesWorkloadClient::isOwnedByDeployment`**
prevents the `asterisk-ari` (node A) RuntimeNode from ever being fenced or restored while
`asterisk-ari-b` (node B) has a running Pod. This is a code-robustness blocker, not an
environmental fluke; a re-run fails identically.

### Root cause (deterministic)

`isOwnedByDeployment(pod, identity)` treats a Pod as owned when its owner ReplicaSet name
`str_starts_with($identity->deployment . '-')`. Node A's deployment is `asterisk-ari`;
node B's ReplicaSet is `asterisk-ari-b-8557bd4d76`. Because
`str_starts_with("asterisk-ari-b-8557bd4d76", "asterisk-ari-") === true`, node A's
`listOwnedPods` **spuriously includes node B's running Pod**. The collision is directional
(node B's prefix `asterisk-ari-b-` never matches node A's Pods), which is why fencing and
restoring node B always worked historically (T5-A35/A40) and only node A is affected.

Two deterministic consequences:

1. **Fencing node A** — `KubernetesRuntimeFenceAdapter::terminationPredicateSatisfied`
   requires `count(ownedPods) === 0`. Node B's Pod is perpetually "owned", so the predicate
   is never satisfied → the fence returns `fence_in_progress` on every attempt → the op
   `terminal_failed` at 3/3. The generation-scoped verify+fence idempotency keys then poison
   the generation (`findIdempotent` returns the terminal op; the coordinator classifies
   `runtime_fence_failed` and does not re-drive) → the conference cannot advance to `G+2`.
   Node A **was** scaled to 0 (`pre_scale_replicas=1` recorded) but the op never reached the
   `fenced` state that triggers `disableAfterSuccessfulFence` + rebind.
2. **Restoring node A** — the restore handler's `runtime_restore_waiting_for_old_pods` gate
   (`count(ownedPods) !== 0`) never clears while node B runs → restore exhausts its 8 attempts.

Live confirmation: querying the pods API exactly as the handler does
(`labelSelector=app.kubernetes.io/part-of=utcp,app.kubernetes.io/component=asterisk-ari`)
returns `asterisk-ari-b-8557bd4d76-gknbx` (RS `asterisk-ari-b-8557bd4d76`), and the
`str_starts_with(..., "asterisk-ari-")` prefix test is `true`.

**Superseded by T5-A46:** do not repair this with a stricter prefix boundary or a
runtime-node label selector. The repository correction below removes prefix ownership
authority entirely and resolves Pod ownership through the exact Pod -> ReplicaSet ->
Deployment controller UID chain. Separately, the `runtime.node.runtime.fence` op still
carries the generic 3-attempt budget with no successor mechanism (unlike restore in
`51abc7a`); widening it or adding a fence successor remains a retained follow-up only if
post-fix live evidence shows a transient convergence problem.

### What was proven (solid)

- **Simulator cleanup:** 65 leftover C4/C5 simulator-deterministic RuntimeNodes canonically
  disabled via `POST /runtime-nodes/{id}/desired-state {disabled}` (65 OK / 0 fail). The
  T5-A43 phantom-capacity blocker is resolved: the canonical placement candidate query
  returns only node A and node B; excluding either leaves exactly the other.
- **`51abc7a` deployed:** api image digest `sha256:aab63072…`, running config
  `operation_max_attempts.runtime_node_restore = 8`, generic op default `3`; successor code
  (`supersedes_restore_operation_id`, `restore_attempt_generation`,
  `restoreScaleProvenanceFromPredecessor`, terminal-non-reusable, `:after:<pred>` idempotency)
  present in the built image and all 12 app-image workloads run the new digest.
- **First failover G(2) → G+1(3):** conference `188ff074` bound node B (gen 2); HTTP outage
  on node B → 300s grace → verify `terminal_failed` → fence **succeeded** (attempt 2,
  `pre_scale_replicas=1`) → node B scaled 1→0 and `disabled` → atomic rebind to node A (gen 3),
  old binding retired → bridge + participant Local-channel legs reconstructed on node A,
  participant `admitted/joined`.
- **G+1 replacement failure with no capacity:** HTTP outage on node A; node B disabled, all
  simulator nodes disabled → the canonical replacement query returns nothing.
- **Fencing withheld (strongest form):** `classifyUnavailableVerificationOperation` checks
  `hasDistinctEligibleReplacement` **before** requesting any fence, so with no replacement it
  returns `no_replacement` and **no gen-3 fence op is ever created**. Node A stayed
  `replicas=1`, Pod UID unchanged — no scale-to-zero.
- **Durable pending state + dedup:** `failover_state=pending_no_capacity`,
  `failover_binding_id=a1a8358b` (active G+1 binding), `failover_generation=3`,
  `failover_started_at=2026-07-19 22:25:03+00` — stable across 8 observed coordinator sweeps;
  exactly **one** `conference.failover_coordinator.no_replacement` event (audit and outbox);
  no duplicate verify/fence chain, no binding retirement, no scale-to-zero.
- **Canonical restoration:** `POST /runtime-nodes/{nodeB}/desired-state {active}` left node B
  `disabled` and scheduled one idempotent `runtime.node.restore` op (max 8 attempts, live);
  a repeated request produced no duplicate op; the restore **succeeded** (attempt 3/8) — node B
  returned to `active/ready`.
- **Capacity-return detection (automatic):** the coordinator detected the restored node B and
  automatically initiated the second (gen-3) runtime fence of node A — the destructive fence
  scaled node A to 0.
- **Successor restore mechanism (incidentally exercised during recovery):** node A's stuck
  restore (bug-blocked) `terminal_failed`; a re-request created a **successor**
  (`supersedes_restore_operation_id=b544e4c0…`) that completed once the phantom Pod obstacle
  was removed.

### Environmental issues encountered and canonically recovered

- **Host reboot (2026-07-19 21:25):** all k3d node containers restarted. agent-0 kubelet
  serving cert carried a stale SAN (exec broken) — fixed by deleting `serving-kubelet.crt` and
  restarting the node container.
- **NetworkPolicy apiserver-egress drift (recurrence):** `allow-runtime-fencer-kubernetes-api`
  pinned `172.24.0.5/32` while the apiserver moved to `172.24.0.4` after the reboot — the
  infrastructure worker's first fence attempt got `unavailable_to_control` and poisoned the
  first proof conference (`15b389a5`, gen 2). Re-rendered canonically
  (`scripts/security/render-apiserver-policy` + `kube apply`), closed the poisoned conference
  canonically (no destructive fence had occurred — node stayed `replicas=1`), and restarted the
  proof with a fresh conference.

### Session-lifetime accommodation

Section 7 baseline confirmed `UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES=30`. Because a
double-failover with two 300s graces plus a no-capacity dwell and a restoration approaches or
exceeds 30 minutes, the ConfigMap value was temporarily raised to 60 (api rolled) so the
participant would not be removed mid-proof, then **restored to 30** at cleanup. Login access was
obtained through the sanctioned `utcp:user-access:reset-password` recovery command; the
`tenant-member` role was added to the admin's Local membership to permit self-session/join
(additive; no canonical role-removal endpoint exists).

### Final state

Both intended Asterisk RuntimeNodes `active/ready` (deployments 1/1), listener leases claimed,
one open epoch per node, all simulator proof RuntimeNodes `disabled`, zero open/pending
conferences, zero failover-state conferences, zero actionable verify/fence/restore operations,
session TTL restored to 30. Node A recovery required a documented manual workaround (temporarily
scaling node B to 0 so node A's canonical restore could clear the buggy `waiting_for_old_pods`
gate); this recovery is recorded separately and does not count as proof success.

## T5-A46 — Repository correction: exact Kubernetes controller ownership replaces prefix ownership

**Verdict: `T5_EXACT_KUBERNETES_WORKLOAD_OWNERSHIP_FIX_COMPLETE` for repository behavior only.**
The T5-A45 live `G+1 -> G+2` failure was caused by a deterministic prefix collision between
the local Asterisk Deployments `asterisk-ari` and `asterisk-ari-b`. The former
`HttpKubernetesWorkloadClient::isOwnedByDeployment` authority treated a Pod as owned when
its controller ReplicaSet name started with the target Deployment name plus `-`, so the
node-B ReplicaSet `asterisk-ari-b-8557bd4d76` was incorrectly accepted as owned by
`asterisk-ari`.

T5-A46 removes Deployment-name prefix authority. `listOwnedPods` now resolves the exact
target Deployment object and requires a non-empty `metadata.uid`; lists namespace
ReplicaSets once for the current operation attempt; indexes them by `metadata.uid`; and
evaluates each candidate Pod through Kubernetes controller owner references:

1. Pod `metadata.ownerReferences[controller=true]` must be `kind=ReplicaSet` and carry a
   ReplicaSet UID.
2. The ReplicaSet UID must resolve to a current ReplicaSet in the same namespace list.
3. ReplicaSet `metadata.ownerReferences[controller=true]` must be `kind=Deployment`.
4. The ReplicaSet controller Deployment UID must exactly equal the target Deployment UID.

No Pod name, ReplicaSet name, Deployment-name prefix, generated name, provider-family name,
RuntimeNode display name, or partial label match grants ownership. Missing Pod owner
references, non-ReplicaSet Pod controllers, missing ReplicaSets, ReplicaSets without a
Deployment controller, and Deployment UID mismatches all fail closed as not owned. Normal
Deployment rollout is preserved because multiple ReplicaSets from different revisions are
accepted when each ReplicaSet controller points to the same exact Deployment UID.

Fence and restore continue to share the canonical `KubernetesWorkloadClient::listOwnedPods`
implementation. The fence lifecycle remains unchanged:

```
scale target Deployment to zero
-> wait for target desired/status/available replicas to reach zero
-> wait for exact target-owned Pods to reach zero
-> complete fence
```

The generic fence operation retry budget remains the default three attempts; no fence
successor operation was added. Restore still requires exact target-owned Pods to be zero
before scale-up and counts only exact target-owned Ready Pods after scale-up. Sibling Pods
from similarly named Deployments do not contribute to owned-Pod counts, Ready-Pod counts,
new Pod UID evidence, or fresh-runtime validation.

Focused repository coverage added the required prefix-collision topology:

- Deployment A `asterisk-ari`, UID `deployment-a-uid`; ReplicaSet A controlled by that UID;
  Pod A controlled by ReplicaSet A.
- Deployment B `asterisk-ari-b`, UID `deployment-b-uid`; ReplicaSet B controlled by that UID;
  Pod B controlled by ReplicaSet B.
- Pod B keeps a realistic name beginning with the old prefix-compatible text
  `asterisk-ari-b-...`, but is not owned by Deployment A.

Focused tests cover exact ownership for A and B, directional prefix collision, UID mismatch,
ReplicaSet owner mismatch, Pod without owner reference, missing ReplicaSet, ReplicaSet without
Deployment controller, multiple target Pods, and rollout ReplicaSets. Real handler-path
regressions execute `RuntimeFenceOperationHandler -> KubernetesRuntimeFenceAdapter` for
both A-with-B-running and B-with-A-running, and `RuntimeNodeRestoreOperationHandler` for
both A-with-B-running and B-with-A-running.

Repository RBAC inspection found the runtime-fencer Role already had read access to
Deployments and Pods but not ReplicaSets. T5-A46 therefore adds only read-only
`get,list` access for `apps/replicasets`; no Kubernetes write authority, labels,
NetworkPolicy, feature gate, allowlist, management CLI, RuntimeBinding authority change,
replacement-selector change, restore successor change, or Asterisk Deployment naming change
was introduced.

The live second-generation no-capacity recovery proof has **not** been rerun. The remaining
T5 live gap is to deploy this ownership fix and rerun the cleaned two-node no-capacity
capacity-return proof to complete `G+1 -> G+2`.

## T5-A47 — Exact Kubernetes ownership deployed; G+1 → G+2 second-generation live proof COMPLETE

**Verdict: `T5_EXACT_OWNERSHIP_AND_SECOND_GENERATION_LIVE_PROOF_COMPLETE`.**
Deployed `1820757` (exact controller-owner-reference workload ownership) and completed the
`G+1 → G+2` second-generation no-capacity recovery proof that T5-A45 left blocked. The
`asterisk-ari` / `asterisk-ari-b` prefix collision is removed: the second runtime fence of the
shorter-named `asterisk-ari` node completed with `fence_result=fenced` while the sibling
`asterisk-ari-b` Pod remained running, and the same node was later restored canonically while
the sibling ran. No manual scale-up, no direct desired-state or RuntimeBinding writes, no
break-glass.

### Phase marker
`versions.env` resolves explicitly to `UTCP_PHASE=T1`; working tree clean at `1820757`.

### RBAC rollout and fencer authorization
Applied only `infrastructure/kubernetes/components/runtime-fencing/rbac.yaml` (narrow read-only
ReplicaSet access). Live Role `utcp-runtime-fencer` (namespace `utcp-runtime`) rules after apply:
`deployments [get,list]`, `replicasets [get,list]`, `deployments/scale [get,patch]`,
`pods [get,list]`. `kubectl auth can-i` as `system:serviceaccount:utcp-platform:utcp-runtime-fencer`:
get/list replicasets = yes; create/update/patch/delete replicasets = no;
get/patch deployments (`--subresource=scale`) = yes (scale authority preserved). Live read-only
ReplicaSet request from the fence worker Pod returned HTTP 200.

### Application image and code currency
Built the api image from `1820757` (digest `sha256:5013aec0…`), pushed to the local registry,
rolled out all app-image workloads. The fence worker Deployment uses `imagePullPolicy=IfNotPresent`,
so the stale cached `0.1.0-k1-dev` tag was cleared from all three k3d nodes to force a fresh pull;
the running fence worker (revision 9) then reported digest `sha256:5013aec0…`. Running
`HttpKubernetesWorkloadClient` code contains `isOwnedByDeploymentUid`, `replicaSetsByUid`, and
`controllerOwnerReference`, and **zero** `str_starts_with` prefix fallbacks.

### Live ownership isolation (before failure)
Live owner chains:
`asterisk-ari` Deployment `94546f77…` → RS `asterisk-ari-db55d57c5` (`b795cef4…`) → its Pod;
`asterisk-ari-b` Deployment `57617c51…` → RS `asterisk-ari-b-8557bd4d76` (`d93adf9e…`) → its Pod.
Replicating the exact `listOwnedPods` resolution against the live cluster:
`listOwnedPods(asterisk-ari)` → only the `asterisk-ari` Pod; `listOwnedPods(asterisk-ari-b)` →
only the `asterisk-ari-b` Pod. `asterisk-ari` does not own the `asterisk-ari-b` Pod and vice
versa — the prefix collision is gone.

### Proof conference and first failover (G → G+1)
Conference `5e16a610` opened bound to node B (gen 2 = G), participant `a117e062` admitted,
bridge + Local-channel legs on node B. HTTP outage on node B → 300s grace → verify
`terminal_failed` → fence **succeeded** (attempt 2, `pre_scale_replicas=1`) → node B scaled 1→0
and `disabled` → atomic rebind to node A (gen 3), old binding retired → bridge + participant
reconstructed on node A, participant `admitted/joined`.

### G+1 no-capacity and fencing withheld
HTTP outage on node A with node B disabled and all simulator nodes disabled. Replacement query
excluding node A returned empty. No gen-3 fence op was created (the coordinator checks
`hasDistinctEligibleReplacement` before requesting a fence); node A stayed `replicas=1`, Pod UID
`857b8b88…` unchanged, bridge preserved.

### Durable pending state and deduplication
`failover_state=pending_no_capacity`, `failover_binding_id=d60fa8de` (active G+1 binding),
`failover_generation=3`, `failover_started_at=2026-07-19 23:59:03+00`. Observed across **8**
coordinator sweeps: identical binding, generation, and first-entry timestamp; exactly **one**
`conference.failover_coordinator.no_replacement` audit event and exactly **one** outbox event;
no duplicate verify/fence chain, no binding retirement, no scale-to-zero.

### Canonical capacity restoration
`POST /runtime-nodes/{nodeB}/desired-state {active}` left node B `disabled` and scheduled one
`runtime.node.restore` op (max 8 attempts). The restore **succeeded** (attempt 3/8): automatic
scale-up, restore-authorized listener attachment, lease, fresh epoch, observed ready,
`runtime_node.restored`, `desired_state=active`. No terminal predecessor; no successor needed.

### Automatic second fence with sibling-Pod exclusion (the decisive proof)
After node B became active/ready, the coordinator resumed automatically and issued one gen-3
runtime fence against node A. During the fence:
`asterisk-ari` (target) Deployment replicas 1 → 0, exact target-owned Pods → 0; `asterisk-ari-b`
(sibling restored) Deployment replicas = 1, sibling Pod running and excluded from the target
owned-Pod result. The termination predicate was satisfied **while the sibling Pod remained
present** — impossible under the old prefix rule. Fence **succeeded** (`fence_result=fenced`,
attempt 2, `pre_scale_replicas=1`); node A `disabled`. No retries were widened and the sibling
Pod was never manually removed.

### Generation G+1 → G+2 and reconstruction
Atomic rebind: G+1 (node A) binding retired at 00:06:04, G+2 binding active on the restored node
B, generation 3 → 4 (incremented once), pending state cleared. Conference read API returns
`failover_state=null`, `failover_binding_id=null`, `failover_generation=null`. Exactly one active
G+2 binding; historical G and G+1 bindings retained; no retired binding reactivated.
`conference.ensure` reconstructed one deterministic bridge `utcp-conf-5e16a610…` on node B and
`conference.participant.ensure` reconstructed the participant's Local-channel legs on node B only
(node A has no Pod). Participant stayed `admitted/joined`, session active. Conference `ready` at
gen 4, stable across 3 further sweeps (gen 4, node B, `failover_state=null`, one active binding,
single bridge).

### Stale G+1 rejection
Node A is `disabled`, `replicas=0`, no Pod — no listener attaches, so no delayed G+1 events can
be emitted or projected. All gen-3 (G+1) operations are terminal (0 actionable). The retired G+1
binding stays retired (0 active bindings on node A). Across the post-G+2 sweeps the pending state
remained `null` (no stale worker restored it) and no retired binding reactivated.

### Canonical cleanup and restore-path ownership proof
Removed the participant, closed the conference, ended the session; bridge and channels
disappeared. Restored node A canonically via `POST desired-state {active}` — the restore
**succeeded** (attempt 3/8) **while node B was running**, which the old prefix rule would have
blocked forever (`runtime_restore_waiting_for_old_pods` never clearing). Session TTL was
temporarily raised 30→60 for the double-failover proof window and restored to 30 at cleanup.

### Final state
Both intended Asterisk Deployments 1/1, both RuntimeNodes `active/ready`, both listener leases
claimed, one open epoch per node, all simulator proof RuntimeNodes disabled, zero open/pending
conferences, zero failover-state conferences, zero actionable verify/fence/restore operations,
infrastructure worker Ready and idle. No break-glass or manual Kubernetes mutation was used on
the successful path.

## T5-A48 — Canonical stale RuntimeBinding retirement contract (evidence-only)

**Verdict: `T5_STALE_RUNTIME_BINDING_RETIREMENT_CONTRACT_DEFINED`.**
Read-only audit at `9c07568`. Confirmed the single root cause of stale active RuntimeBindings:
**canonical Conference closure never retires the active RuntimeBinding.** Retirement exists only
on the two rebind transitions (`bindRuntimeNode`, `failoverRebindConference`). Defined one
canonical automatic retirement owner, exact timing, unified historical repair, locking, event,
API visibility, and the exact test slice. No production code, migrations, tests, manifests, or
runtime state were modified.

### Active RuntimeBinding inventory (live, read-only)
- Conferences: 114, all `desired_state=closed` / `observed_state=closed`. Zero open, zero
  `pending_no_capacity`, zero actionable verify/fence/restore operations.
- RuntimeBindings: **114 active, 8 retired**. One-active-per-conference is enforced by the partial
  unique index `conference_runtime_bindings_one_active (conference_id) WHERE status='active'`
  (verified: zero conferences with >1 active binding).
- **All 114 active bindings belong to closed conferences** (Category B). Active bindings by node:
  `Local Asterisk ARI` 82, `Local Asterisk ARI B` 6, and 26 on disabled C5 simulator proof nodes —
  every one of them on a **closed** conference.
- Category E (missing/deleted conference): 0. Category G (open conference on non-active node —
  the valid no-capacity case): 0. Residue span: `2026-07-15 01:16:55` → `2026-07-20 00:06:04`,
  single tenant `local` (114/114).
- The `conference_runtime_bindings` table has **no generation column**; generation lives on the
  Conference (`configuration_generation`). Category F (binding generation mismatch) is therefore
  structurally impossible.

### Authoritative active bindings
Category A (open, active, current generation, current node) currently: **0** (no open
conferences). The one-active partial index guarantees each open conference has exactly one
authoritative active binding when open; the reconciler and projection both resolve the target
node from that active binding.

### Confirmed stale binding categories
- **Category B — closed Conference, binding still active: 114 (the entire population).** This is a
  stale-authority defect, not retained history: the domain closure transaction sets
  `desired_state=closed` but performs no binding write.
- Categories A, C, D, E, F, G: empty in the live environment; F structurally impossible; E and G
  confirmed absent by direct query.

### Historical residue versus current defect
Same root cause; **not separable.** The defect is currently reproducible at `9c07568`: the
T5-A47 conference `5e16a610` (closed canonically last session at `00:10:11`) still holds an
**active** G+2 binding — its G and G+1 bindings were retired by *failover rebind*
(`unbound_at` = the rebind times `23:52:03` / `00:06:04`, not the `00:10:11` closure). Every one
of the 6 most-recent closed conferences shows the same pattern (final binding active, `closed_at`
set, binding never retired). The residue spans the entire C5 history through current HEAD, so it
is one continuous defect; historical repair and the forward fix share one mechanism.

### RuntimeBinding write authorities (complete)
Exactly three write sites, all in `App\TelephonyDomain\TelephonyDomainService`; no DELETE anywhere;
no unaccounted authority:
- **Creation** — `writeBinding()` (INSERT `status=active`), called from `createConference`
  (initial bound node), `changeConferenceDesiredState('open')` (bind on open),
  `bindRuntimeNode` (admin runtime-binding change), `failoverRebindConference` (rebind).
- **Retirement** — UPDATE `status=retired` at exactly two sites:
  `bindRuntimeNode` (retire prior before admin rebind) and
  `failoverRebindConference` (retire prior before failover rebind). Both are atomic with the new
  binding insert inside one `DB::transaction`.
- **Deletion** — none.
Reconcilers (`ConferenceReconciler`, `ConferenceParticipantReconciler`) and `ProjectionService`
only **read** bindings; they never retire.

### Conference closure lifecycle
`changeConferenceDesiredState('closed')`: sets `desired_state=closed`, `closed_at`, clears
`failover_state/binding_id/generation/started_at` (so closing a no-capacity conference **does**
clear pending state), bumps `configuration_generation` — and does **not** touch
`conference_runtime_bindings`. Runtime cleanup then proceeds asynchronously:
`ConferenceReconciler` resolves the target node from the **active** binding
(`activeRuntimeNodeId`; returns `blocked('conference_runtime_binding_missing')` if none), drives
bridge destruction (`conference.close` operation), and `ProjectionService` sets
`observed_state='closed'` and calls `markProjectedTargetConverged` when the `bridge_destroyed`
observation lands (lines 267-275). **The active binding is required until observed closure** —
both the reconciler node-resolution and the projection's observation→conference routing
(join on `conference_runtime_bindings.status='active'`) depend on it. Retirement is never called;
closure succeeds and converges while the binding stays active indefinitely.

### Session-expiry lifecycle
`expireDueSessions → removeParticipantsForSession` sets participants `desired_state=removed` and
wakes participant reconciliation. It does **not** close the conference and does **not** touch
bindings. Session expiry is orthogonal to binding retirement: a conference stays open (and its
binding authoritative) after its session expires until it is explicitly closed.

### Failover and rebind lifecycle
`failoverRebindConference` (invoked by `ConferenceFailoverCoordinator`) selects a distinct
replacement, then in one transaction retires the current active binding, inserts the new active
binding, bumps generation, clears failover fields, and emits `conference.runtime_binding_replaced`
(audit + outbox). This is the only working retirement path and correctly preserves exactly one
active binding across G→G+1→G+2 (verified by existing tests and the T5-A47 live proof).

### No-capacity binding authority (must be preserved)
While a Conference is `open` with `failover_state=pending_no_capacity` and no replacement, the
active binding on the failed/fenced former node **remains the current domain authority** until
atomic rebind. Retirement must key on `desired_state=closed` **and** `observed_state=closed`, so
it can never touch an open/pending conference, a failed conference awaiting recovery, or a
disabled-node binding whose Conference is still authoritative. Generation monotonicity, durable
pending state, capacity-return recovery, atomic replacement, historical retention, and
stale-operation/event guards are all untouched by this scope.

### Canonical retirement owner
**Model C — a dedicated automatic retirement authority owned by `TelephonyDomainService`, invoked
by one scheduled `--once` sweep on the existing everyMinute cadence, parallel to
`ConferenceFailoverCoordinator`.** Model A (retire inside the closure transaction) is rejected:
the reconciler and projection require the active binding to complete bridge cleanup and observed
closure, so retiring at `desired_state=closed` would `block` cleanup. Model B (retire inside the
`ConferenceReconciler`) is rejected: reconcilers are pure evaluators (the `ReconciliationWorker`
only creates operations or records results; no reconciler performs domain writes), so binding
retirement does not fit their contract. Model C keeps a single owner for closure-retirement,
reuses the canonical domain binding-write authority, and is the same structural pattern already
used for failover. Rebind-retirement (`failoverRebindConference`) stays a distinct transition and
is not duplicated.

### Retirement timing
Retire when `desired_state='closed'` **and** `observed_state='closed'` (i.e. after participant and
bridge cleanup and observed-closure convergence). This is safe for forward recovery
(only terminal conferences qualify), stale-event rejection (generation already advanced and node
fenced), cleanup targeting (runtime cleanup already complete — no unreachable-PBX dependency),
generation authority, read consistency, and worker restart (idempotent re-selection). Runtime
cleanup remains best-effort and already-completed by this point; domain authority retirement does
not wait on any further PBX call. A required companion refinement: `ConferenceReconciler` must
treat a `desired_state='closed'` conference with no active binding as **converged/terminal**
rather than `blocked('conference_runtime_binding_missing')`, so retired-and-closed conferences
stop re-blocking every cycle.

### Historical repair contract
The same sweep repairs the 114 pre-existing rows: query (tenant-scoped)
`conferences WHERE desired_state='closed' AND observed_state='closed' AND EXISTS(active binding)`,
retire each active binding through the canonical domain path, emit one audit+outbox event.
Idempotent (a retired binding is not re-selected), tenant-scoped, binding- and generation-aware,
never deletes historical bindings, needs no binding-ID list, no environment gate, no runtime
allowlist, no routine Artisan trigger, and no direct SQL as the production mechanism. Current and
historical retirements use the **same event type** with a distinct `reason`
(`conference_closed` vs `closed_conference_residue`). A read-only Artisan diagnostic (count/list of
stale-authority conferences) may exist for break-glass, never as the normal cleanup trigger.

### Concurrency and idempotency
Each retirement runs in a transaction that `lockForUpdate`s the Conference and the candidate active
binding, then revalidates under lock: Conference still `desired_state=closed` and
`observed_state=closed`; the binding is still `status='active'`; the binding's id and
`runtime_node_id` still match the Conference's current `runtime_node_id`; and the Conference
generation is unchanged from the selected snapshot. Only then does it set `status='retired'`,
`unbound_at`. The one-active partial index makes a concurrent second active binding impossible; a
stale sweep holding an older snapshot re-reads and finds the binding already retired or a newer
authority and no-ops. Repeated close requests are already idempotent (closed→closed is a no-op).
Closure racing failover/rebind is safe: rebind only runs for `open` conferences, so it cannot race
a closed-conference retirement; capacity-return rebind likewise requires `open`. A stale worker can
never retire a newer active binding because retirement is gated on `desired_state=closed` +
`observed_state=closed` + exact binding-id/node/generation revalidation under lock.

### Event and audit contract
Add one transition-only event `conference.runtime_binding_retired` (audit + outbox), payload:
tenant_id, conference_id, binding_id, generation (Conference `configuration_generation`),
runtime_node_id, reason, source lifecycle transition (`conference_closed`), timestamp. Emitted
exactly once per retirement — after retirement the Conference has no active binding, so it is not
re-selected, giving natural per-binding dedup without per-sweep noise. Reuse existing
`conference.runtime_binding_replaced` for rebind (unchanged). Historical repair reuses the same
event type with `reason=closed_conference_residue`.

### Web-admin visibility
Normal retirement is automatic and requires no Kubernetes/PBX/Artisan knowledge. The Conference
read API already exposes `runtime_node_id` and `configuration_generation` (which reflect the active
binding). Recommended diagnostic-only additions to the Conference/Admin serializer: current active
binding id, binding lifecycle state, last retirement reason/timestamp, and a computed
`stale_binding_authority` flag (closed + observed-closed + active binding). No manual "retire
binding" button — repository evidence shows binding lifecycle is control-plane automation, not an
operator decision.

### Existing test coverage
Present (`TelephonyDomainTest`): atomic rebind retirement
(`test_open_conference_runtime_rebind_atomically_retires_former_binding_and_wakes_reconciliation`,
L344), second-generation retirement + one-active preservation (L879), rebind history retention.
Notably, several tests (L1144/1295/1363) **manually** `UPDATE status=retired` to fabricate a
closed conference with a retired binding — direct evidence that no closure-retirement path exists.
**Missing**: normal closure retires the binding; historical-residue repair idempotency;
pending-no-capacity conference not retired; session-expiry (open conference) not retired; closure
racing failover/rebind; stale worker cannot retire a newer generation; missing/deleted Conference
behavior; disabled-node-but-open-authoritative not retired; audit/outbox dedup (one event per
retirement); tenant isolation; reconciler treats closed + no-active-binding as converged.

### Missing implementation (smallest coherent slice)
1. `TelephonyDomainService::retireClosedConferenceBindings()` — tenant-scoped, batched, locked,
   revalidating sweep that retires active bindings on `closed`+`observed-closed` conferences and
   emits `conference.runtime_binding_retired` (reason `conference_closed`; `closed_conference_residue`
   for pre-existing rows).
2. `telephony-domain:retire-closed-bindings --once` Artisan command + `Schedule::command(...)
   ->everyMinute()->withoutOverlapping()` (mirrors `failover-coordinator`).
3. `ConferenceReconciler`: closed + no-active-binding → `converged` (terminal), not `blocked`.
4. New event `conference.runtime_binding_retired` (audit + outbox).
5. Optional diagnostic serializer fields (active binding id / lifecycle / retirement reason /
   stale-authority flag).
6. Tests per the matrix above.

### Fence retry observation
Both corrected live fences in T5-A47 succeeded on **attempt 2 of 3** (first fence gen-2, second
fence gen-3). This audit found **no** evidence of a transient post-fix fence failure. No fence
retry or fence-successor work is recommended, and none is combined with this RuntimeBinding
retirement scope.

### Implementation-readiness decision
**A — bounded Codex implementation.** The audit establishes exact stale categories (all Category
B), current reproducibility, one canonical owner (Model C domain sweep), exact timing
(closed + observed-closed), unified historical repair, locking/idempotency, no-capacity
preservation, the event contract, API visibility, and the exact test matrix.

### Ready-to-paste next prompt

```
# T5-A49 — Implement Canonical Stale RuntimeBinding Retirement

Implement the contract defined in docs/evidence/t2/multi-node-failover-readiness.md §T5-A48.

Starting state: HEAD 9c07568 (or later), branch main, working tree clean, UTCP_PHASE=T1.
This is a bounded implementation task. Do not begin a new phase; UTCP_PHASE stays T1.

## Scope (smallest coherent slice)

1. Add TelephonyDomainService::retireClosedConferenceBindings(int $batchSize): array
   - Tenant-scoped, batched sweep. For each conference with desired_state='closed'
     AND observed_state='closed' AND an active conference_runtime_binding:
     open a DB::transaction, lockForUpdate the conference and its active binding,
     REVALIDATE under lock (desired_state still closed; observed_state still closed;
     binding still status='active'; binding.runtime_node_id == conference.runtime_node_id;
     conference.configuration_generation unchanged from the selected snapshot), then
     set status='retired', unbound_at=now(). Emit exactly one audit + one outbox event
     conference.runtime_binding_retired with payload {tenant_id, conference_id, binding_id,
     generation, runtime_node_id, reason, source_transition:'conference_closed', occurred_at}.
   - reason = 'conference_closed' for normal closure; 'closed_conference_residue' for
     pre-existing rows (distinguish by whether closed_at predates this deployment is NOT
     required — use a single 'conference_closed' reason unless a residue reason is trivially
     available; do not add environment gates).
   - Idempotent: a conference with no active binding is never selected. No deletes.

2. Wire telephony-domain:retire-closed-bindings {--once} Artisan command calling the sweep,
   and Schedule::command('telephony-domain:retire-closed-bindings --once')->everyMinute()
   ->withoutOverlapping(), mirroring telephony-domain:failover-coordinator.

3. ConferenceReconciler: when desired_state='closed' and there is no active binding
   (activeRuntimeNodeId === null), return ReconciliationResult::converged (terminal) instead
   of blocked('conference_runtime_binding_missing').

4. Add the conference.runtime_binding_retired event to any event/type registries as needed;
   reuse existing conference.runtime_binding_replaced for rebind (do not change it).

5. Optional (only if trivial and non-breaking): expose active binding id + binding lifecycle
   state + last retirement reason + computed stale_binding_authority flag in the Conference
   serializer for diagnostics. No manual "retire binding" endpoint or button.

## Invariants (must hold)
- Never retire a binding on an open conference, a pending_no_capacity conference, or any
  conference whose observed_state is not 'closed'.
- Never retire a newer active binding from a stale snapshot (revalidate binding id + node +
  generation under lock).
- Preserve exactly one active binding across failover generations; do not alter
  failoverRebindConference or bindRuntimeNode retirement.
- Tenant-scoped; historical bindings never deleted; no env gate, allowlist, or routine
  direct-SQL production mechanism. A read-only Artisan diagnostic is acceptable; a mutating
  Artisan command must not be the normal authority (the scheduled sweep is).

## Tests (add/extend TelephonyDomainTest + a focused sweep test)
- normal closure (desired+observed closed) retires the active binding and emits one event
- repeated sweep is idempotent (no second event, no re-retire)
- pending_no_capacity open conference is NOT retired
- open conference (post session-expiry, participants removed) is NOT retired
- observed_state not yet 'closed' is NOT retired (binding survives runtime cleanup)
- closure racing rebind cannot double-retire / cannot retire newer generation
- stale snapshot cannot retire a newer active binding
- G -> G+1 -> G+2 then close leaves zero active bindings, all retired
- missing/deleted conference: no active-binding row references it (guarded)
- audit + outbox dedup: exactly one event per retirement
- tenant isolation: sweep for tenant A does not touch tenant B bindings
- ConferenceReconciler: closed + no active binding => converged (not blocked)

## Verification
make repository-hygiene workflow-check secret-scan
make runtime-engine-config-check telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check
make runtime-engine-test telephony-domain-test asterisk-conference-test asterisk-conference-recovery-test
git diff --check

## Commit
feat(t5): retire stale RuntimeBindings on canonical conference closure
Do not push. Keep UTCP_PHASE=T1.
```

## T5-A58 — Resilience metrics and alerts implementation

**Verdict: `T5_RESILIENCE_OBSERVABILITY_IMPLEMENTED_REPOSITORY_ONLY`.**
Repository implementation at `5b219f1` extended the existing pull-based `/api/metrics` path. No
Kubernetes resources were deployed, no live Prometheus scrape was observed, no live Conference,
RuntimeBinding, PBX, or runtime state was mutated, and `UTCP_PHASE` remains `T1`.

### Metrics architecture retained
All new T5 metric families are emitted by `App\Http\Controllers\Platform\MetricsController` during
the existing Prometheus text scrape. The sources are durable PostgreSQL rows already written by the
domain/runtime lifecycle:

- `control_plane_outbox_messages` for failover transitions, binding retirement, and channel reclaim
  events.
- `conferences` and `runtime_bindings` for current no-capacity, stale active binding, and
  orphan-candidate gauges.
- `runtime_operations` for verify/fence/restore/participant-remove operation outcomes.
- `conference_recovery_metric_events` for runtime-reference health classifications.
- `BuildInfo` configuration for the API application build serving `/api/metrics`.

No in-memory counters, Pushgateway integration, Redis metrics state, new metric tables, new queue
jobs, scheduler/reconciler metric writes, new endpoint, new dependency, environment gate, or
dashboard were added.

### Implemented metric families
`utcp_conference_failover_events_total` is a counter with bounded label `event_type` in
`no_replacement`, `runtime_binding_replaced`; maximum two series. It counts deduplicated durable
transition events, not coordinator sweeps.

`utcp_conference_failover_pending` is a gauge with bounded label `failover_state` currently limited
to `pending_no_capacity`; maximum one series. `utcp_conference_failover_pending_oldest_seconds` uses
the same label and reports `max(0, database scrape time - earliest failover_started_at)`, or zero
when no Conference is pending.

`utcp_runtime_resilience_operations_total` is a counter with bounded labels `operation_type`,
`result`, and `failure_class`. The operation allowlist is the repository's canonical strings:
`runtime.node.verify_conference_absent`, `runtime.node.runtime.fence`, `runtime.node.restore`, and
`conference.participant.remove`. It counts one `runtime_operations` row as one operation object;
attempt counts do not create additional samples. Restore predecessor and successor rows each count
as separate operation objects, so the metric is not a logical restore-request counter. Unknown
historical status or failure-class values map to `other`; null failure class maps to `none`.

`utcp_conference_runtime_binding_retired_total` is a counter with bounded label `reason`, sourced
from `conference.runtime_binding_retired` events. The recognized reason is `conference_closed`;
unexpected durable payload values map to `other`. `utcp_conference_stale_active_bindings` is a
zero-healthy gauge for the exact stale-retirement invariant: `desired_state=closed`,
`observed_state=closed`, and an active RuntimeBinding still exists. The metrics path never mutates
or repairs bindings.

`utcp_conference_participant_channel_reclaimed_total` is a counter with bounded label
`classification`, sourced from `conference_participant.channel_reclaimed` events. The recognized
classification is `post_closure_orphan`; unexpected payload values map to `other`.
`utcp_conference_orphan_participant_candidates` is a gauge for the PostgreSQL-discoverable
candidate predicate used by `TelephonyDomainService::reclaimOrphanParticipantChannels`: removed
participant, closed/observed-closed Conference, no active binding, and a retained binding with a
RuntimeNode. This is documented in HELP text as a database-derived upper bound because a metrics
scrape must not call ARI or Asterisk to prove a live Local channel remains.

`utcp_conference_runtime_reference_health_10m` is a ten-minute rolling-window gauge with bounded
labels `resource_type` and `health`, sourced from `conference_recovery_metric_events`. Health values remain distinct:
`healthy_present`, `healthy_absent`, `degraded_unavailable`, and `transport_unavailable`.
Unexpected historical values map to `other`. Recognized resource types are `conference` and
`conference_participant`; unexpected values map to `other`.

`utcp_build_info` is a gauge with value `1` and bounded labels `version` and `commit`, sourced from
the same `BuildInfo` configuration used by `/api/version`. It identifies the application serving
`/api/metrics`; it does not prove command-worker or reconciler deployment version by itself. Pod
name, image digest, build timestamp, runtime hostname, and deployment identity are deliberately not
labels.

### Alerts implemented
`utcp-alerts.yaml` now includes six T5 resilience alerts:

- `UTCPConferencePendingNoCapacity`: pending no-capacity failover for 15 minutes.
- `UTCPRuntimeFenceTerminalFailure`: recent terminal fence failures.
- `UTCPRuntimeRestoreTerminalFailure`: recent terminal RuntimeNode restore failures.
- `UTCPStaleActiveRuntimeBindings`: stale closed-conference active bindings for 10 minutes.
- `UTCPOrphanParticipantCandidates`: database-derived orphan participant candidate upper bound for
  15 minutes; the annotation states this requires control-plane investigation and is not proof that
  a channel is currently alive.
- `UTCPAriReferenceFamilyDegraded`: repeated `degraded_unavailable` runtime-reference health
  observations over a 10-minute window.

The version-skew alert is intentionally deferred. The repository's Prometheus stack exposes
`utcp_build_info` from the API metrics endpoint, but no reliable worker build metric source is
present and the task explicitly forbids inferring worker skew solely from the API metric or adding
worker HTTP metrics. The T5 dashboard remains deferred.

### Cardinality and safety
The new labels are fixed vocabularies only. Tenant IDs, Conference IDs, participant IDs, session IDs,
RuntimeBinding IDs, runtime-operation IDs, RuntimeNode IDs/names, channel IDs, Pod/Deployment UIDs,
telephone numbers, endpoint URLs, credentials, and raw exception messages are not exposed as metric
labels. Unexpected durable values are mapped to `other` instead of being passed through.

### Verification coverage
Focused repository tests cover durable event counters, repeated-scrape idempotency, no-capacity
current-state and oldest-age gauges, logical operation-row semantics, restore predecessor/successor
semantics, binding retirement and stale-binding metrics, orphan reclaim and database-only candidate
metrics, all runtime-reference health states, build-info labels, cardinality/secret safety, and
PrometheusRule alert names, expressions, durations, and wording. Live Prometheus scrape and alert
behavior remain pending for deployment proof.

## T5-A49 — Canonical RuntimeBinding Retirement After Conference Closure

**Verdict:** `T5_STALE_RUNTIME_BINDING_RETIREMENT_IMPLEMENTATION_REPOSITORY_READY`

### Evidence Basis

This repository-only implementation follows the T5-A48 stale-binding audit. That audit found a
live stale population of 114 Conferences with `desired_state=closed` and `observed_state=closed`
while their corresponding RuntimeBindings remained `status=active`. The newest T5-A47 Conference
reproduced the same forward lifecycle defect: after the G+2 replacement Conference closed
canonically, its final active binding did not retire.

No live database mutation, Kubernetes apply, PBX mutation, live failover, or live restoration was
performed in this repository task. The 114-row live repair proof remains pending.

### Previous Indefinite Binding Authority Removed

The invalid authority state was:

```text
Conference desired_state=closed
Conference observed_state=closed
RuntimeBinding remains active indefinitely
```

The repository now provides a canonical retirement path:

```text
Conference desired_state=closed
AND Conference observed_state=closed
AND exact active RuntimeBinding is still current under lock
→ retire active RuntimeBinding automatically
```

Desired-state closure alone is not sufficient. The active binding remains the cleanup target until
the runtime projection has observed the Conference as closed.

### Canonical Retirement Owner

`TelephonyDomainService::retireClosedConferenceBindings()` owns the new retirement authority. It is
tenant-aware, batched, idempotent, and domain-service-owned. The scheduler and command wrapper call
the service; they do not write RuntimeBindings directly.

RuntimeBinding rows are retained. The implementation updates `status=retired` and records
`unbound_at`; it does not delete historical bindings.

### Retirement Transaction

Each candidate is revalidated inside its own bounded transaction:

- lock the Conference
- require desired and observed state are both `closed`
- require the Conference generation matches the candidate snapshot
- require the Conference runtime node still matches the candidate snapshot
- lock the candidate RuntimeBinding
- require the same tenant, Conference, binding ID, runtime node, and `status=active`
- require the current active binding ID is still the candidate binding
- retire the binding, record `unbound_at`, and emit transition evidence

A failed revalidation no-ops safely. This rejects stale snapshots and newer active bindings.

### Historical Residue Repair

Historical closed/closed residue uses the same domain method as forward lifecycle retirement. The
method supports bounded tenant-scoped execution and structured reasons, including
`closed_conference_residue` where the caller has residue evidence. The automatic scheduled sweep
uses the same lifecycle path and is safe to run repeatedly until the stale population is cleared.

### Automatic Scheduling

The repository registers:

```text
telephony-domain:retire-closed-bindings --once
Schedule::command(...)->everyMinute()->withoutOverlapping()
```

The command is a thin scheduler mechanism with bounded batch control. It has no binding,
Conference, tenant, RuntimeNode, replacement, allowlist, or manual repair selector.

### Reconciler Closure Behavior

`ConferenceReconciler` now treats:

```text
desired_state=closed
observed_state=closed
no active RuntimeBinding
```

as converged. A missing active binding before observed closure remains blocked, and an open
Conference without an active binding preserves the previous failure behavior.

### No-Capacity and Failover Preservation

Open Conferences, including `failover_state=pending_no_capacity`, remain bound. RuntimeNode disabled
or unavailable state alone cannot retire a binding. Rebind remains the only authority that retires a
binding while a Conference stays open:

```text
failoverRebindConference
→ retire old binding
→ insert replacement binding
→ increment generation
→ clear failover state
```

After G → G+1 → G+2, canonical closure retires only the final active binding and leaves all prior
retired bindings retained.

### Event Evidence

Each actual closure retirement emits exactly one audit record and one outbox event:

```text
conference.runtime_binding_retired
```

Payload evidence includes tenant ID, Conference ID, RuntimeBinding ID, RuntimeNode ID, Conference
generation, retirement reason, source transition, `unbound_at`, and event timestamp. Repeated sweeps
after retirement emit no duplicate event. Rebind continues to use
`conference.runtime_binding_replaced`.

### Diagnostic Read Visibility

The Conference serializer now exposes read-only binding diagnostics:

- current active RuntimeBinding ID
- active binding RuntimeNode ID
- binding lifecycle status
- last binding retirement reason
- last binding retirement timestamp

No Admin/UI mutation endpoint, manual button, direct SQL workflow, environment switch, runtime
allowlist, or manual binding-ID repair list was added.

### Focused Repository Tests

Focused tests cover:

- normal Admin/API close → reconciler cleanup → projection observed closed → service sweep →
  binding retired → retirement event → later reconciliation converged
- desired closed but observed not closed retains the binding
- closed/closed residue through the same service
- repeated sweeps and scheduler overlap-style invocation are idempotent
- pending no capacity and disabled open Conference retain the binding
- pending no capacity closure retires only after observed closed
- G → G+1 → G+2 closure leaves zero active bindings with all historical rows retained
- stale candidate snapshots cannot retire newer active bindings
- tenant isolation
- stale failover/rebind after closure cannot insert a new binding for a closed Conference
- event and audit deduplication
- closed/closed without active binding is converged
- closing/open without active binding remains blocked
- session expiry does not retire the binding unless the Conference separately closes

### Verification Snapshot

Focused repository checks passed:

```text
php -l apps/api/app/TelephonyDomain/TelephonyDomainService.php
php -l apps/api/app/TelephonyDomain/Reconciliation/ConferenceReconciler.php
php -l apps/api/routes/console.php
php -l apps/api/tests/Feature/TelephonyDomain/TelephonyDomainTest.php
php -l apps/api/tests/Feature/Asterisk/AsteriskConferenceRecoveryTest.php
php artisan test --filter=TelephonyDomainTest
php artisan test --filter=AsteriskConferenceRecoveryTest
```

Broad required Make verification and live deployment proof are recorded in the task completion
report. The live automatic retirement and historical-residue repair proof remains pending.

## T5-A50 — Automatic RuntimeBinding retirement and historical residue repair: LIVE PROOF COMPLETE

**Verdict: `T5_AUTOMATIC_RUNTIME_BINDING_RETIREMENT_LIVE_PROOF_COMPLETE`.**
Deployed `4740290` and proved both the historical residue repair (114 closed/closed conferences
with active bindings retired automatically to zero) and the forward lifecycle (a freshly opened
conference closed canonically, its final binding retired only after observed closure). All
retirement was driven by the everyMinute scheduler; no manual command, no direct SQL, no manual
management surface.

### Phase marker
`versions.env` line 7 resolves explicitly to `UTCP_PHASE=T1`; working tree clean at `4740290`.

### Application image build and rollout
Built the api image from `4740290` (digest `sha256:0985ac41…`), pushed to the local registry,
rolled out api, scheduler, telephony-reconciler, worker, telephony-command-worker,
telephony-event-normalizer, control-plane-outbox-dispatcher, asterisk-ari-events,
simulator-event-source, kamailio-registration-observer. Asterisk, Kamailio, PostgreSQL, Redis,
RBAC, NetworkPolicy, and the runtime-fencer were not rolled. Every rolled workload runs the new
digest; the scheduler pod (`scheduler-67996d4b9-…`, revision 44) confirmed on `sha256:0985ac41…`.

### Live retirement-code currency
The image contains `retireClosedConferenceBindings`, the
`telephony-domain:retire-closed-bindings` command, the everyMinute `withoutOverlapping` schedule,
the `conference.runtime_binding_retired` event, and the ConferenceReconciler closed/closed
no-active-binding convergence branch.

### Scheduler registration
`php artisan schedule:list` (read-only) shows
`* * * * *  php artisan telephony-domain:retire-closed-bindings --once` — automatic, every minute,
overlap-protected, batch-bounded (`--batch=100` default).

### No-manual-command proof
Retirement was never invoked manually. Only read-only `schedule:list` and normal authenticated
Conference/session APIs were used. The historical repair (114→0) occurred through scheduler
sweeps before any manual action, proving automatic normal operation.

### Historical candidate baseline
Canonical predicate `desired_state='closed' AND observed_state='closed' AND active binding`
returned **114** candidates pre-rollout (single tenant `local`), matching the expected ~114. The
inventory (conference id, tenant, binding id, node, generation, binding created_at, status,
unbound_at) was captured read-only for evidence only; the scheduler discovered candidates itself.
Pre-rollout: 114 active bindings, 8 retired, 0 `conference.runtime_binding_retired` events.

### Automatic historical repair and batch progression
The scheduler retired the historical population in two batch-bounded sweeps with no manual command:
- Sweep 1: candidates 114 → 14 (retired exactly 100 = batch size).
- Sweep 2: candidates 14 → 0 (retired the remaining 14).
Result: closed/closed conferences with active binding = 0. Each processed candidate changed
`status active → retired` and `unbound_at null → timestamp`; 0 retired rows have a null
`unbound_at`. Binding rows remained present (total retired grew 8 → 122; nothing deleted),
conferences stayed closed/closed, and each binding retained its `runtime_node_id` and generation
evidence.

### Historical retirement events
Exactly **114 audit** and **114 outbox** `conference.runtime_binding_retired` records — one per
retirement. Payload carries tenant_id, conference_id, runtime_binding_id, runtime_node_id,
conference_generation, reason, `source_transition='conference_closed'`, `unbound_at`, occurred_at.
The implementation uses a single structured reason `conference_closed` for both historical and
forward retirements (the residue-specific reason was intentionally not implemented; the T5-A48
contract permitted a single reason).

### Post-repair deduplication
Across 3+ additional everyMinute sweeps (observed 01:14:52 → 01:16:22): candidates stayed 0,
audit stayed 114, outbox stayed 114, active bindings stayed 0, retired stayed 122, and
`max(unbound_at)` was unchanged (`01:14:03`). Zero additional status changes, zero duplicate
events, zero duplicate outbox rows, no rewritten `unbound_at`, no row deletion.

### Open-conference binding preservation
A fresh conference was created after historical repair. Across 3 retirement sweeps
(01:18:10 → 01:19:30) with the conference `open/ready`, its binding stayed `active`, no
`conference.runtime_binding_retired` event was emitted for it, and the total event count stayed
114. This proves the sweep uses neither age, RuntimeNode readiness, nor sweep frequency as
retirement authority — only `desired_state=closed AND observed_state=closed`. No open or
pending-no-capacity binding was retired by historical repair (there were none during repair, and
the fresh open binding was preserved); retired failover-generation history was unchanged.

### Fresh proof conference
Through normal authenticated APIs: session `8bb8f624` (expiry 01:47:37), conference `a90bac34`
(gen 2 on open), participant `91b59874` admitted, one active binding `0efc6e14` on node B
(`05ddb383`), conference `open/ready`, deterministic bridge `utcp-conf-a90bac34…` with participant
Local-channel legs on node B. No SQL/Redis creation.

### Canonical closure timeline and binding preservation during cleanup
Close requested via the Conference API at `01:20:26` (desired=closed, observed=ready, gen 3). The
reconciler drove bridge destruction; the projection set `observed_state='closed'` at `01:20:30`.
The binding remained `active` through observed closure (sampled `closed/closed` with `binding=active`,
0 retirement events, node-B bridge gone) — it did **not** retire before observed closure.

### Forward automatic retirement and event
The next scheduler sweep retired the final binding at `01:21:04` (34s after observed closure):
`status=retired`, `unbound_at=01:21:04`, 0 active bindings for the conference. Ordering proof:
`unbound_at (01:21:04) ≥ observed_at (01:20:30)` = true. Exactly one audit + one outbox
`conference.runtime_binding_retired` event for the conference, with tenant, conference, binding,
runtime_node_id (`05ddb383`), generation 3, `reason=conference_closed`,
`source_transition=conference_closed`, `unbound_at`, and timestamp. Across 4 later sweeps
(01:21:54 → 01:23:40) the event count stayed 1/1 and `unbound_at` was not rewritten — no
duplicate event or binding mutation.

### Reconciler convergence without a binding
After retirement the conference's reconciliation state is `converged` with an empty
`blocked_reason` (not `conference_runtime_binding_missing`), stable across 4 sweeps. The
closed/closed conference with no active binding reconciles as converged under normal scheduled
reconciliation, with no binding recreated.

### Session-expiry boundary
Ending session `8bb8f624` through the normal lifecycle left the retired binding **unchanged**
(`status=retired`, `unbound_at=01:21:04`), 0 active bindings, and the retirement event count at 1.
Session handling neither created, reactivated, nor altered the retired binding; session expiry is
not reinterpreted as closure authority.

### Tenant isolation and write-authority
The candidate query and per-candidate revalidation both filter by `tenant_id`, and the service
accepts an optional tenant scope; all live data is single-tenant `local`, so isolation is
established by code scoping rather than a second live tenant. RuntimeBinding write authority is
unchanged from the T5-A48 inventory: creation via `writeBinding`, retirement via `bindRuntimeNode`,
`failoverRebindConference`, and now the closure-retirement sweep — all in `TelephonyDomainService`;
no direct SQL repair, no DELETE, no manual management surface. A mutating Artisan command exists
only as the scheduled `--once` entry point (the scheduler is the authority), not as an operator
workflow.

### Final runtime state
closed/closed conferences with active bindings = 0; open = 0; pending_no_capacity = 0; actionable
verify/fence/restore operations = 0; both intended RuntimeNodes active/ready; both Asterisk
Deployments 1/1; all simulator proof RuntimeNodes disabled; scheduler and workers Ready. Total
retired bindings = 123 (8 pre-existing + 114 historical + 1 forward), all rows retained; total
active bindings = 0; 115 audit and 115 outbox retirement events (one per retirement); no duplicate
event; no manual retirement command executed; no direct RuntimeBinding or Conference database write.

## T5-A51 — Authoritative ARI not-found handling contract (evidence-only)

**Verdict: `T5_AUTHORITATIVE_ARI_FALSE_404_CONTRACT_DEFINED`.**
Read-only audit at `a2db639`. Inventoried every production ARI 404 handler, confirmed one real
false-404 mechanism (already live-observed in T5-A25), reclassified the remaining categories as
already-safe, and defined the smallest safe hardening: gate *authoritative absence* on the health
of the same ARI resource-family list endpoint. No production code, tests, manifests, or runtime
state were modified.

### Baseline
Clean at `a2db639`, `UTCP_PHASE=T1`. Both intended RuntimeNodes active/ready; both Asterisk
Deployments 1/1 (Asterisk 20.20.1); zero open/pending conferences; zero actionable operations;
both listener leases claimed; one open epoch per node; app image `sha256:0985ac41`. ARI config:
`request_timeout_ms=4000`, `connect_timeout_ms=2000`, `participant_attach_attempts=8`,
`participant_attach_retry_microseconds=200000` (0.2s), `poll_seconds=5`, `lease_seconds=45`.

### ARI 404 call-site inventory
All ARI I/O flows through `AsteriskAriClient::ariRequest(method, resource, query, timeout,
acceptedStatuses)`. 404 is handled two ways:
- **Accepted 404** (listed in `acceptedStatuses`): returned to the caller.
  - `closeConferenceBridge` — `DELETE bridges/{id}` `[200,202,204,404]` → `absent=true` (cleanup).
  - `removeParticipantChannel` — `POST bridges/{id}/removeChannel` `[200,202,204,404,409,422]` and
    `DELETE channels/{id}` `[200,202,204,404,409]` → `absent=true` (cleanup).
  - `getAriResource` — `GET {resource}` `[200,404]` → **returns null on 404** (inspection).
- **Unexpected 404** (not accepted): `ariRequest` throws
  `AsteriskAriException(FailureClass::Conflict, 'ari_resource_not_found')`. `FailureClass::Conflict`
  is **not retryable** (`FailureClass::retryable()` = TransientTransport, RuntimeUnavailable,
  Timeout, InternalError only; `config/runtime_engine.php` lists `conflict` under
  `terminal_failure_classes`). A single unexpected 404 → operation `terminal_failed`.
- `inspectRuntimeInfo` — `GET /asterisk/info`; 404 → `ari_info_unsupported`
  (UnsupportedCapability, terminal). This is a stable REST-health reference.

Create/mutate accept-lists: `POST bridges` `[200,201,204,409]`; `POST channels` (originate)
`[200,201,202,204,409]`; `POST bridges/{id}/addChannel` `[200,202,204,409]` (404 **not** accepted,
but wrapped in a bounded in-process retry loop — see below). None of the create endpoints
reference another resource by id in a way that yields a legitimate 404, so create-path false-404 is
absorbed by the `409 already-exists` accept.

### Consumers of `getAriResource` absence (`bridge_exists=false`)
`conferenceRuntimeSummary` maps a bridge/channel `GET → 404 → null` to `bridge_exists=false` /
`participant_channel_exists=false`. Consumers:
1. **`AsteriskRuntimeAdapter::inspectConferenceRuntime`** → `RuntimeConferenceInspectionResult`:
   a 404 → `observed`/`conferencePresent=false` (authoritative absence); a **retryable**
   `AsteriskAriException` (e.g. `ari_http_unavailable`, RuntimeUnavailable) → `unavailable`
   (retry). Consumed by `ConferenceReconciler` (open → `conference_bridge_missing` → idempotent
   `conference.ensure`; closed → converge).
2. **`AsteriskRuntimeAdapter::verify_conference_absent`** → `bridge_exists=false` and no
   participant channel present → `verification_result='absent'`.

### Confirmed false-404 occurrence (live-observed, T5-A25)
Documented in this file (§T5-A25/A26, "Retained ARI false-404 hardening gap"): unloading the ARI
**REST resource modules** (`res_ari_bridges`, etc.) while the HTTP server stays up makes
`GET /ari/bridges/{id}` return **HTTP 404** (route gone) even though the bridge is **alive**
(confirmed via the Asterisk control socket: bridge present with 2 channels, Pod UID unchanged,
replicas 1/1). `getAriResource` (accepts `[200,404]`) read this as `bridge_exists=false` — a **false
absence**. T5-A25 rejected this as a failover trigger precisely because it is a false-404 rather
than a faithful transport outage. This is a real, live-observed mechanism, not a theoretical race.

### Legitimate not-found categories present in UTCP
- **Legitimate authoritative 404** — bridge/channel genuinely absent (before create, after
  destroy). Present and correct.
- **Cleanup 404** — accepted → idempotent success. Present and correct (see Cleanup Semantics).
- **Replacement-window 404** — after failover rebind commits, the deterministic bridge does not yet
  exist on the new node; `GET → 404` drives the correct idempotent `conference.ensure` create.
  Legitimate, not false.
- **Transient visibility 404 (addChannel)** — handled by the existing bounded in-process loop.
- **Stale-generation / wrong-node 404** — **prevented before the ARI call** (see authority).
- **Adapter/routing defect** — none: deterministic ids are pre-sanitized to `[A-Za-z0-9_.:-]`
  (`safeRuntimeReference`), query uses `PHP_QUERY_RFC3986`, node/endpoint/credential resolved from
  the authoritative RuntimeNode.

### Bridge lifecycle findings
`ensureConferenceBridge` inspects (`GET bridges/{id}`) then `POST bridges` `[200,201,204,409]` —
false-404 on inspect → create → `409 already-exists` accepted → idempotent, benign.
`closeConferenceBridge` `DELETE bridges/{id}` accepts 404 → `absent=true` — idempotent cleanup.
Post-rebind reconstruction: 404 on the new node is legitimate (bridge not created yet) → ensure.

### Participant lifecycle findings
`ensureParticipantChannel`: ensure bridge → inspect channel (`GET channels/{id}`) → originate if
absent (`POST channels` accepts 409) → `addChannel` in a loop of `attempts=8 × 0.2s` catching
`FailureClass::Conflict` (covers a transient bridge/channel 404 or 409), then a
`participantAttachedToBridge` check; a non-attach becomes `ari_participant_attach_pending`
(RuntimeUnavailable, **retryable** → persisted operation retry). The only terminal path is
`addChannel` throwing `Conflict` on the final attempt (bounded exhaustion). `removeParticipantChannel`
accepts 404 on both removeChannel and channel delete → idempotent cleanup. No duplicate originate:
deterministic `channelId` + inspect-before-originate + `409` accept.

### RuntimeNode authority
Every ARI request receives the authoritative `runtime_node_id` explicitly from the operation
payload; the adapter never reselects a node. `conferenceFromOperation` re-reads the Conference and
throws terminal `conference_not_bound_to_node` when the operation's node differs from the
Conference's current `runtime_node_id` at the current-or-newer generation. The reconciler and
projection resolve the target node from the **current active binding** (`activeRuntimeNodeId`), so
inspection is always directed at the current node, never the former node after rebind.

### RuntimeBinding and generation authority
Each conference/participant handler short-circuits when
`operationGeneration < conference.configuration_generation` (returns stale/converged) **before any
ARI call**, so a stale-generation worker never issues an ARI mutation or absence query for an old
generation. There is **no post-response generation revalidation**, but it is unnecessary: the
projection is generation-gated (`observed_generation <= generation`) and the event normalizer joins
the active-binding node, so a late in-flight response from a former node cannot advance the current
projection. Deterministic ids (`utcp-conf-<conferenceId>`, `utcp-part-<participantId>`) are
generation-independent, so no stale-id mismatch exists. No hardening weakens these guards.

### Wrong-node requests
Prevented: `conferenceFromOperation` rejects an operation whose node is not the Conference's current
bound node (`conference_not_bound_to_node`, terminal). A cleanup/inspection therefore only executes
against the authoritative node.

### Stale-generation requests
Prevented: per-handler generation short-circuit before the ARI call; stale ops converge without
mutating or inspecting the runtime.

### Cleanup 404 semantics
Cleanup (`bridge destroy`, `channel hangup`, `removeChannel`, former-generation cleanup) accepts 404
as `absent=true` (desired-absent satisfied). This is safe **because cleanup only runs on the
authoritative node/generation** (guarded by `conferenceFromOperation` + generation short-circuit); a
cleanup 404 therefore never proves that a current-generation resource on another node is absent.
Unchanged by this contract.

### Reconstruction 404 semantics
Create/ensure is idempotent: inspect-before-create + `409 already-exists` accept. A 404 during
initial ensure, post-rebind reconstruction, or channel inspect → create/originate → 409 → benign.
Deterministic ids guarantee idempotent reconstruction; **no compatibility fallback ids**. Unchanged.

### The hardening contract (verify/inspect absence only)
Only the **absence-determination** path is unsafe: `conferenceRuntimeSummary` (used by
`verify_conference_absent` and the reconciler inspection) cannot distinguish a *legitimate* bridge
404 (healthy REST stack, resource genuinely gone) from a *false* bridge 404 (REST resource module
degraded, resource alive). The danger is `verify_conference_absent`: a false `absent` drives the
coordinator's `absent_verified` rebind path, which rebinds **without** the destructive
`runtime.node.runtime.fence` scale-to-zero → split-brain (bridge alive on former node + reconstructed
on replacement).

**Fix:** before classifying a bridge/channel `GET → 404` as authoritative absence, confirm the same
ARI resource **family list** endpoint is healthy — `GET /ari/bridges` for a bridge, `GET /ari/channels`
for a channel — expecting HTTP 200. If the list endpoint returns 404/error/transport-failure, the
resource module is degraded → classify the specific-resource 404 as **`unavailable`**
(`FailureClass::RuntimeUnavailable`, retryable) rather than absence. If the list is healthy (200) and
the specific resource 404s → authoritative absence. This makes `verify_conference_absent`
**fail-safe**: degraded REST → `unavailable` → the existing external-fence path (which scales the
former node to zero) instead of a false `absent` → unfenced rebind. The list endpoint is the
tightest possible reference because it is served by the *same* resource module whose absence causes
the false-404 (a core-only endpoint such as `/asterisk/info` could stay healthy while
`res_ari_bridges` alone is unloaded).

### Retry ownership
The persisted operation lifecycle already owns recovery. The fix reclassifies a false-absence as
`RuntimeUnavailable`, which the **existing** `verify_conference_absent` operation retry handles (as
proven in T5-A47, where a genuine transport failure retried then took the external-fence path). No
new retry loop, no in-client polling for absence, no environment retry gate. The one existing
in-process loop (`addChannel`, 8×0.2s) is bounded and correct and is left unchanged.

### Evidence-derived retry window
No new retry window is introduced. The bridge/channel-list health probe is a single extra GET on the
same node with the existing `request_timeout_ms=4000`; on unhealthy it returns `unavailable`, and the
already-configured operation retry cadence (verify op, generation-gated) governs re-evaluation. The
`addChannel` window remains `attempts=8 × 0.2s = 1.6s` (evidence: in-process attach latency), which is
untouched.

### Duplicate-prevention contract
Unchanged and already sufficient: deterministic `utcp-conf-`/`utcp-part-` ids, inspect-before-create,
`409 already-exists` accept, and `participantAttachedToBridge` verification prevent duplicate bridge
creation and duplicate participant originate even under a false-404.

### Events and observability
Reuse existing hooks. `verify_conference_absent` already emits `conference.runtime_fence_verified`
with `verification_result` and `runtime_reference_present`; add a `runtime_reference_health`
classification (`healthy_absent` vs `degraded_unavailable`) so a false-404-driven `unavailable` is
observable. Extend the existing `recordInspectionMetric` (adapter_key/resource_type/result) to
distinguish `legitimate_absence`, `degraded_rest_unavailable`, and `transport_unavailable`. Do not
emit an event on every reconciliation poll while the condition is unchanged (the verify op already
fires once per evaluation). Metrics are proposed, **not implemented** here.

### Web-admin authority boundary
Unchanged: ARI recovery is runtime automation. No Admin user retries an ARI operation, recreates a
bridge, re-originates channels, or runs Artisan/Asterisk-CLI/Kubernetes. The Admin UI may surface
diagnostic failure state; no manual "retry ARI" button is warranted (no business decision is
required — the fail-safe classification is deterministic automation).

### Existing test coverage
`AsteriskAriAdapterTest` covers `verify_conference_absent` via an injected
`conferenceRuntimeSummary` returning `bridge_exists=true/false` → `present`/`absent`, and participant
present/absent. `AsteriskConferenceRecoveryTest` covers stale-projection-vs-live-bridge. **Missing:**
- bridge 404 with a healthy bridges-list → authoritative `absent`
- bridge 404 with an unhealthy/404 bridges-list → `unavailable` (false-404 fail-safe, **not** absent)
- participant channel 404 gated on channels-list health
- transport failure still → `unavailable` (regression)
- create-path false-404 → `409` idempotent (regression)
- cleanup 404 idempotent AND node/generation-gated (regression)
- wrong-node op rejected before any ARI call
- stale-generation op short-circuits before any ARI call
- no duplicate bridge creation / participant originate under a false-404
- `conference.runtime_fence_verified` carries the reference-health classification
- tenant isolation of the inspection/verification path

### Missing implementation (smallest coherent slice)
1. `AsteriskAriClient`: a `bridgeResourceFamilyHealthy()` / `channelResourceFamilyHealthy()` probe
   (`GET /ari/bridges` / `GET /ari/channels`, expect 200); on a specific-resource 404, consult the
   probe and throw `RuntimeUnavailable('ari_resource_family_degraded')` instead of returning null
   when the family is unhealthy. Scope this to the **absence-determination** callers
   (`conferenceRuntimeSummary` / `verify_conference_absent`), not the create/cleanup accept-lists.
2. Carry a `runtime_reference_health` classification into `conference.runtime_fence_verified` and the
   inspection result.
3. Tests per the matrix above.
(No change to node/generation guards, cleanup accept-lists, create idempotency, or the addChannel
loop.)

### Implementation-readiness decision
**A — bounded Codex implementation.** The audit establishes the confirmed affected endpoints
(`verify_conference_absent` and reconciler inspection via `conferenceRuntimeSummary` bridge/channel
GET), a live-observed reproducible mechanism (T5-A25 module-unload 404 with the bridge alive), exact
node/generation authority (unchanged and not weakened), the legitimate-vs-false classification
(same-family list health), the correct retry owner (existing operation lifecycle), bounded timing (a
single same-node probe; no new window), cleanup and reconstruction semantics (unchanged), event
behavior (reuse + one classification field), and the exact tests. No unresolved wrong-node or
stale-generation ambiguity remains.

### Ready-to-paste next prompt

```
# T5-A52 — Implement Health-Gated Authoritative ARI Absence

Implement the contract in docs/evidence/t2/multi-node-failover-readiness.md §T5-A51.

Starting state: HEAD a2db639 (or later), branch main, working tree clean, UTCP_PHASE=T1.
Bounded implementation task. Do not begin a new phase; UTCP_PHASE stays T1.

## Problem
A bridge/channel `GET /ari/bridges|channels/{id}` can return HTTP 404 while the resource is
alive when the ARI REST resource module is degraded (HTTP up, route gone) — live-observed in
T5-A25. AsteriskAriClient::getAriResource maps that 404 to null -> bridge_exists=false, which
verify_conference_absent turns into verification_result='absent', driving an UNFENCED rebind
(split-brain). Cleanup 404 and create-path 404 are already safe and MUST stay unchanged.

## Scope (smallest slice)
1. AsteriskAriClient: add resource-family health probes
   - bridgeResourceFamilyHealthy(runtimeNodeId): GET /ari/bridges expects 200
   - channelResourceFamilyHealthy(runtimeNodeId): GET /ari/channels expects 200
   Use only for ABSENCE determination. When conferenceRuntimeSummary's specific-resource
   GET returns 404, consult the same-family probe:
     - probe 200  -> authoritative absence (bridge_exists=false as today)
     - probe 404/error/transport-fail -> throw AsteriskAriException(
         FailureClass::RuntimeUnavailable, 'ari_resource_family_degraded', retryable=true)
   Do NOT change the create accept-lists ([...,409]) or the cleanup accept-lists
   ([...,404,...]); a false-404 on create is absorbed by 409, and cleanup 404 stays
   idempotent (it is already node/generation-gated by conferenceFromOperation).
2. inspectConferenceRuntime: a thrown ari_resource_family_degraded (retryable) already maps to
   RuntimeConferenceInspectionResult::unavailable — verify no code path swallows it into
   'observed'/absent.
3. verify_conference_absent: because conferenceRuntimeSummary now throws RuntimeUnavailable on a
   degraded family, the verify operation retries instead of returning 'absent'. Add a
   runtime_reference_health field ('healthy_absent' | 'degraded_unavailable') to the
   conference.runtime_fence_verified event / inspection result.

## Invariants
- Never weaken node authority (conference_not_bound_to_node) or generation short-circuits.
- Never turn a genuine absence (healthy family + resource 404) into unavailable.
- Never turn a transport failure into absence (regression guard).
- Cleanup 404 stays idempotent success; create path stays 409-idempotent.
- Deterministic ids unchanged; no fallback ids; no duplicate bridge/originate.
- No env gate, allowlist, in-client absence polling, or new retry loop. The persisted
  operation lifecycle owns retries.

## Tests (AsteriskAriAdapterTest + AsteriskConferenceRecoveryTest)
- bridge 404 + bridges-list 200 -> verification_result='absent'
- bridge 404 + bridges-list 404 -> ari_resource_family_degraded, RuntimeUnavailable,
  verify does NOT return 'absent'
- participant channel 404 gated on channels-list health
- transport failure -> unavailable (regression)
- create path false-404 -> 409 idempotent (regression)
- cleanup 404 -> idempotent success, only on authoritative node/generation (regression)
- wrong-node op -> conference_not_bound_to_node before any ARI call
- stale-generation op -> short-circuits before any ARI call
- no duplicate bridge creation / participant originate under a false-404
- conference.runtime_fence_verified carries runtime_reference_health
- tenant isolation of inspection/verification

## Verification
make repository-hygiene workflow-check secret-scan
make runtime-engine-config-check telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check
make runtime-engine-test telephony-domain-test asterisk-ari-test asterisk-conference-test asterisk-conference-recovery-test
git diff --check

## Commit
fix(t5): gate authoritative ARI absence on resource-family health
Do not push. Keep UTCP_PHASE=T1.
```

## T5-A52 — Health-gated authoritative ARI absence implementation

Status: repository implementation completed; live controlled ARI resource-family degradation proof
remains pending.

### Confirmed T5-A25 false-404 mechanism
T5-A51 captured the live failure mode from T5-A25: the Asterisk HTTP endpoint can remain reachable
while an ARI REST resource family is degraded. In that state a resource-specific request such as
`GET /ari/bridges/{bridge_id}` may return HTTP 404 even though the live bridge and channels still
exist. The previous `AsteriskAriClient::getAriResource()` behavior mapped that 404 to `null`, and
`conferenceRuntimeSummary()` converted it into `bridge_exists=false`. The
`verify_conference_absent` path could therefore report authoritative absence and allow fence
verification to succeed from degraded ARI REST evidence.

### Removed unconditional specific-resource 404 authority
The implementation removes unconditional resource-specific `404 -> absent` authority from the
authoritative runtime-inspection path. The generic `getAriResource()` helper remains available for
paths whose existing semantics are intentionally idempotent or reconstruction-oriented, but
`conferenceRuntimeSummary()` now uses a separate authoritative-inspection helper.

### Bridge and channel family-health probes
The authoritative-inspection helper now gates a specific bridge or channel 404 with a same-node,
same-endpoint resource-family probe:

- Bridge absence probe: `GET /ari/bridges`
- Channel absence probe: `GET /ari/channels`

The probe uses the existing Asterisk ARI profile request timeout (`request_timeout_ms`, default
4000ms). Only HTTP 200 is healthy. Authentication/authorization failures, 404 on the family
endpoint, unexpected 4xx/5xx, transport failure, and timeout are treated as degraded or unavailable.

### Healthy-family authoritative absence
When `GET /ari/bridges/{id}` or `GET /ari/channels/{id}` returns 404 and the matching family endpoint
returns 200, the resource is classified as authoritatively absent. Successful verification evidence
records:

```text
runtime_reference_health=healthy_absent
```

Existing present resources record:

```text
runtime_reference_health=healthy_present
```

### Degraded-family RuntimeUnavailable classification
When a resource-specific 404 is followed by an unhealthy bridge or channel family probe,
`AsteriskAriClient` throws retryable runtime unavailability:

```text
FailureClass::RuntimeUnavailable
failure_code=ari_resource_family_degraded
runtime_reference_health=degraded_unavailable
```

The persisted `verify_conference_absent` operation remains the retry owner. No in-process polling,
manual retry surface, feature gate, allowlist, or new operation type was added.

### RuntimeNode, binding, and generation preservation
The family probe uses the same RuntimeNode and ARI endpoint as the resource-specific request. It does
not reselect another RuntimeNode, consult cluster-wide health, or add fallback endpoints. The
verification handler still validates the former binding context before ARI I/O, and stale
configuration generation now short-circuits before any bridge/channel request or family-health
probe.

### Cleanup and reconstruction invariants
Cleanup semantics are preserved:

- `DELETE bridges/{id}` accepting 404 remains idempotent success.
- `bridges/{id}/removeChannel` accepting 404 remains idempotent success.
- `DELETE channels/{id}` accepting 404 remains idempotent success.

Reconstruction semantics are preserved:

- Deterministic `utcp-conf-*` bridge IDs are unchanged.
- Deterministic `utcp-part-*` channel IDs are unchanged.
- Inspect-before-create remains unchanged.
- Bridge/channel create 409 remains accepted as idempotent existence.
- The participant `addChannel` loop remains bounded at the existing 8 attempts with 0.2-second
  default delay.

### Event and inspection classification
Successful `conference.runtime_fence_verified` payloads now carry `runtime_reference_health`.
`RuntimeConferenceInspectionResult` also carries the classification so the existing
`recordInspectionMetric()` path can distinguish legitimate absence, degraded ARI REST family
unavailability, and transport unavailability through its existing diagnostic `reason` field. No new
metrics backend or duplicate event type was added.

### Focused and real-handler tests
Repository tests added in `AsteriskAriAdapterTest` cover:

- Bridge specific 404 with healthy bridge family -> authoritative absence.
- Bridge specific 404 with degraded bridge family -> retryable `ari_resource_family_degraded`.
- Bridge family transport failure after a specific 404 -> not absence.
- Channel specific 404 with healthy channel family -> authoritative channel absence.
- Channel specific 404 with degraded channel family -> retryable `ari_resource_family_degraded`.
- Existing bridge present -> no family probe.
- Specific transport failure -> `transport_unavailable`, not absence.
- Real `runtime.node.verify_conference_absent` operation retries on degraded bridge family, then
  succeeds after family recovery.
- Wrong-node verification fails before ARI requests.
- Stale-generation verification short-circuits before ARI requests.
- Cleanup bridge 404 and channel 404 remain idempotent without family probes.
- Reconstruction 404 followed by create 409 remains idempotent without family probes.
- Inspection metrics record `runtime_reference_health`.

### Live proof boundary
No Kubernetes resource, Asterisk module, ARI resource, RuntimeBinding, Conference row, failover,
fencing, or restoration was mutated during this repository task. The controlled live ARI
resource-family degradation proof remains pending.

## T5-A53 — Authoritative ARI false-404 hardening: LIVE PROOF COMPLETE

**Verdict: `T5_AUTHORITATIVE_ARI_FALSE_404_LIVE_PROOF_COMPLETE`.**
Deployed `af2212d` and proved live that a degraded ARI bridge resource family cannot falsely
establish that a live Conference bridge is absent. With `res_ari_bridges.so` unloaded (bridge REST
routes 404, HTTP up, bridge alive via control socket), every inspection classified
`degraded_unavailable` (retryable `RuntimeUnavailable`), no authoritative absence was concluded,
and no verify/fence/rebind occurred. After module restore, inspection auto-recovered to
`healthy_present` with no duplicate bridge or participant. The complementary case
(healthy family + genuine bridge 404 → `healthy_absent`) was also proven after canonical closure.

### Phase marker
`versions.env` line 7 = `UTCP_PHASE=T1`; working tree clean at `af2212d`.

### Application image build and rollout
Built the api image from `af2212d` (digest `sha256:a3a1e3f8`), pushed to the local registry,
rolled out api, telephony-reconciler, telephony-command-worker, worker, telephony-event-normalizer,
asterisk-ari-events, control-plane-outbox-dispatcher, scheduler, simulator-event-source,
kamailio-registration-observer. Asterisk, Kamailio, PostgreSQL, Redis, runtime-fencer, RBAC, and
NetworkPolicy were not rolled. Every rolled workload runs `sha256:a3a1e3f8`.

### Live false-404 code currency
The image and running listener/command-worker/api pods contain `bridgeResourceFamilyHealthy`,
`channelResourceFamilyHealthy`, `ari_resource_family_degraded`, `runtime_reference_health`,
`healthy_present`/`healthy_absent`/`degraded_unavailable`/`transport_unavailable`, and
`getAriResourceForAuthoritativeInspection` (4 family-health markers per critical pod). The cleanup
accept-lists (`DELETE bridges/{id}` `[200,202,204,404]`; `removeParticipantChannel`
`[…,404,…]`) and the node/generation guards (`conference_not_bound_to_node`, generation
short-circuit) are unchanged.

### Proof Conference
Session `83b73fc2` (30-min, expiry 03:44:55), conference `22182381` (gen 2 on open, node B
`05ddb383`), participant `1aa7b9b2` admitted, one active binding `00bb7f57`, conference `open/ready`,
deterministic bridge `utcp-conf-22182381-45d6-46e1-8af2-a77d63cc7fcc`, participant Local-channel
legs, node B Asterisk Pod UID `6e8e874c`. Created via normal authenticated APIs only.

### Healthy ARI baseline
From within node B against the authoritative ARI endpoint:
`GET /ari/bridges/{bridge_id}=200`, `GET /ari/bridges=200`, `GET /ari/channels=200`,
`GET /ari/asterisk/info=200`. Control socket: bridge present with 2 channels. Scheduled inspection
recorded `conference_recovery_metric_events` `result=observed, reason=healthy_present` every ~15s.

### Controlled bridge-family degradation
Module inventory (control socket): `res_ari_bridges.so` loaded, **use-count 0**, `.so` present on
disk. Per T5-A25 evidence, `res_ari_bridges.so` serves the `/ari/bridges` REST routes; the live
bridge lives in Asterisk core (`bridge.c`), not this REST module. Recorded pre-mutation state and
the reversible commands (`module unload res_ari_bridges.so` / `module load res_ari_bridges.so`).
At 03:16:56 unloaded **only** `res_ari_bridges.so` via the control socket.

### Asterisk HTTP and process health
Immediately after unload: Pod UID unchanged (`6e8e874c`), Deployment replicas 1/1, Asterisk process
running (`core show uptime`), `GET /ari/asterisk/info=200` and `GET /ari/channels=200` (HTTP up,
only bridge routes gone), listener lease claimed, node B `observed_state=ready` throughout.

### Specific bridge 404 + Bridge-family health result
While degraded (03:17:11): `GET /ari/bridges/{bridge_id}=404`, `GET /ari/bridges=404`
(bridge family degraded), while `GET /ari/channels=200` and `GET /ari/asterisk/info=200`.

### Live bridge and channel existence
Control socket during degradation: bridge `utcp-conf-22182381…` still present (same deterministic
id, 2 active channels) — a false resource-specific absence, not a deletion.

### Runtime-reference health classification
Every scheduled inspection from 03:17:05 onward recorded
`result=unavailable, failure_class=runtime_unavailable, reason=degraded_unavailable` for both the
conference and conference_participant resources. No `bridge_exists=false`, no `healthy_absent`.

### Verification operation behavior
No `verify_conference_absent` operation was created for the conference (0). The family-degraded
classification is retryable `RuntimeUnavailable`; the reconciler simply kept re-inspecting on its
cadence (`unavailable`) rather than escalating. No manual verification was created.

### False-absence prevention
Across ~5 minutes of degradation (03:16:56 → 03:21:45): conference stayed `open/ready`, gen 2, node
B, `failover_state` null; exactly one active binding on node B (no rebind); no `bridge_exists=false`
conclusion; no `healthy_absent`; no bridge recreation; no participant re-origination; no new
generation.

### Failover-escalation prevention
Zero `verify_conference_absent` and zero `runtime.node.runtime.fence` operations; node B replicas
1/1 (no scale-to-zero); Pod UID unchanged; no RuntimeBinding rebind; no generation increment; zero
actionable runtime operations. Because only the bridge REST module was degraded (not the events
WebSocket), node B liveness stayed `ready` — bridge absence itself was never treated as
authoritative while the bridge family was degraded, exactly as required.

### Module restoration
At 03:22:03 reloaded `res_ari_bridges.so` via the control socket. `GET /ari/bridges=200` and
`GET /ari/bridges/{bridge_id}=200` returned. Pod UID unchanged; module use-count 0, Running.

### Automatic inspection recovery
Later scheduled inspection auto-recovered to `result=observed, reason=healthy_present` at 03:22:37
— no manual operation creation, no manual ARI retry endpoint, no Artisan recovery, no direct state
repair.

### Duplicate-prevention proof
After restore: exactly ONE `utcp-conf-` bridge (original id, continuous duration = never
destroyed/recreated), exactly the 2 original participant Local-channel legs (no duplicate
originate), Pod UID unchanged. The conference remained authoritative on node B.

### Healthy-family authoritative absence
After canonical participant removal and conference closure (03:23:36), the bridge was genuinely
destroyed (control socket bridge count 0) while `GET /ari/bridges=200` and
`GET /ari/bridges/{bridge_id}=404`. The inspection classified `result=observed, reason=healthy_absent`
at 03:23:42 — healthy family + genuine specific-resource 404 correctly yields authoritative absence,
the complement of the false-404 case. No bridge was deleted via ARI directly.

### Cleanup 404 preservation
The canonical `conference.close` operation succeeded (attempt 1/3); its bridge `DELETE bridges/{id}`
accepts `[200,202,204,404]` idempotently with **no** resource-family probe (unchanged path,
confirmed in live code and regression). A DELETE 404 during idempotent cleanup remains success.

### Canonical cleanup
Participant removed, conference closed, session ended; bridge and my participant channels
(`1aa7b9b2`) disappeared; the final RuntimeBinding auto-retired (T5-A49 sweep) by 03:24:19.

### Final runtime state
Both intended RuntimeNodes active/ready; both Asterisk Deployments 1/1; both leases claimed; one
open epoch per node; 0 open/pending conferences; 0 active RuntimeBindings; 0 actionable runtime
operations; all simulator proof nodes disabled; all 8 ARI REST modules restored on node B.

### Divergence / pre-existing residue
Two orphan Local channels (`utcp-part-91b59874…`, Stasis `utcp-t0-observation`) remained on node B;
they belong to the **T5-A50** participant `91b59874` (conference `a90bac34`, closed 01:20 — ~2h
before this proof), not to this proof. This proof's own participant channels cleaned up completely
(0 `1aa7b9b2` channels). These orphans are pre-existing environmental residue, unrelated to and
unaffected by the false-404 hardening; they were not touched (hanging them up would be an
out-of-scope direct PBX mutation of another proof's leftovers). No break-glass recovery was used.

## T5-A54 — Canonical orphan Stasis Local-channel cleanup contract (evidence-only)

**Verdict: `T5_ORPHAN_STASIS_LOCAL_CHANNEL_CLEANUP_CONTRACT_DEFINED`.**
Read-only audit at `02385fc`. Root-caused the two orphan Local channels (participant
`utcp-part-91b59874…`) to a **current, reproducible** defect: closing a Conference while a
participant is still `admitted` orphans its Local legs, and the participant is only removed after
the RuntimeBinding is retired, at which point the participant reconciler is permanently blocked
(it derives the node from the *active* binding). No channel-hangup operation is ever scheduled.
Defined one canonical cleanup owner, completion evidence, retained-authority repair, concurrency,
events, and tests. No production code, tests, config, or runtime state modified; the orphan
channels were not touched.

### Baseline
Clean at `02385fc`, `UTCP_PHASE=T1`. Both intended RuntimeNodes active/ready; both Asterisk
Deployments 1/1; zero open/pending Conferences; zero active RuntimeBindings; zero actionable
operations; all simulator nodes disabled; all 8 ARI modules loaded. App image `sha256:a3a1e3f8`.
Node A `1d15ca88` (pod `asterisk-ari-db55d57c5-2vvsn`), node B `05ddb383`
(pod `asterisk-ari-b-8557bd4d76-cm7sc`); both leases claimed, one open epoch each.

### Orphan channel inventory
Exactly **two** Local legs on node B, no additional resources:
`Local/participant@utcp-conference-proof-00000001;1` and `;2`, both `State=Up`,
`Application=Stasis(utcp-t0-observation)`, context `utcp-conference-proof`, extension `participant`,
UniqueID/LinkedID `utcp-part-91b59874-2c57-41f3-b7e9-0e5cab231129`, duration ~159s+ (growing), **no
bridge membership**. Both are visible via ARI (`GET /ari/channels/utcp-part-91b59874…=200`; the
channels list returns exactly the two ids `…91b59874…` and `…91b59874…;2`). The `;1` side carries
the participant identity as the ARI channel id; `;2` is the deterministic peer. Node A has zero
channels; both nodes have zero bridges.

### Canonical participant correlation
Participant `91b59874-…`: desired_state=**removed**, observed_state=**left**, joined 01:17:48,
left_at 01:20:27, created 01:17:44, updated 01:24:17, role participant, no failure. Conference
`a90bac34-…` (`t5a50-proof-1784510257`): desired=**closed**, observed=**closed**, generation 3,
observed_generation 3, runtime_node_id `05ddb383` (node B), opened 01:17:44, closed_at 01:20:26,
observed_at 01:20:30. Session `8bb8f624-…`: **ended** 01:24:17 (`user_ended`), issued 01:17:37,
expiry 01:47:37. Binding `0efc6e14-…`: **retired** 01:21:04, runtime_node_id `05ddb383` (node B).
**No current canonical record asserts the channels should exist** — every authority says
removed/left/closed/ended/retired. These are genuine orphans.

### Conference and session state
Conference closed/closed at generation 3 on node B; session ended; participant removed. The
generation at admission was 2 (open); at removal, 3 (closed). No failover occurred
(draft→open→closed, gen 1→2→3).

### RuntimeBinding and generation authority
Exactly one binding ever existed (`0efc6e14`, node B), now retired at 01:21:04. The retired row
**still holds the node id** (`05ddb383`) — which matches where the orphan channels live — so
retained historical authority is available for repair. The deterministic channel id
(`utcp-part-{participantId}`) is participant-specific and generation-independent.

### Participant cleanup lifecycle
Admission → `conference.participant.ensure` originates the two deterministic Local legs and attaches
`;1` to the bridge. Removal path: `removeParticipant` (or session-end `removeParticipantsForSession`)
sets `desired_state='removed'` + `wakeTarget('conference_participant')`. `ConferenceParticipantReconciler`
then resolves the node from `activeRuntimeNodeId` (the **active** binding), inspects
(health-gated, T5-A52), and — if the deterministic channel is still present
(`participant_channel_exists`) — schedules `conference.participant.remove`, whose adapter calls
`removeParticipantChannel` = `POST bridges/{id}/removeChannel` + `DELETE channels/{;1}` (relying on
Local peer-hangup to destroy `;2`). `conference.close` destroys **only the bridge**
(`DELETE bridges/{id}`); it does not hang up participant channels.

### T5-A50 cleanup timeline (audit + operations + PBX)
- 01:17:38 conference.created; 01:17:44 open (gen 2) + participant.admitted;
  01:17:45→48 `conference.ensure` + `conference.participant.ensure` succeeded (channels originated).
- 01:20:26 conference desired=**closed** (gen 3); 01:20:27 `conference.close` succeeded → **bridge
  destroyed**; the two Local legs left the bridge but stayed `Up` in Stasis. Participant
  observed→**left** (bridge-departure projection), left_at 01:20:27.
- 01:20:30 conference observed=**closed**. 01:21:04 binding **retired** (T5-A49 sweep).
- **01:24:17 participant.removed** (session ended → `removeParticipantsForSession` set
  desired=removed). By now the binding is retired.
- **No `conference.participant.remove` operation exists** (operations for the conference are only
  `conference.ensure`, `conference.participant.ensure`, `conference.close`).

### Proven failure category
**A — cleanup was never scheduled**, precipitated by **I — superseded by Conference closure.**
The participant was still `admitted` when the conference closed; closing destroyed the bridge (not
the channels) and, ~40s later, retired the binding. The participant was only marked `removed` at
session-end (01:24:17), after retirement. `ConferenceParticipantReconciler.evaluate` resolves the
node from `activeRuntimeNodeId`; with the binding retired it returns
`blocked('conference_runtime_binding_missing')` before ever inspecting or scheduling
`conference.participant.remove`. No hangup call was ever issued — this is not E (no op returned
false success) nor J (both legs remain, not one). The participant `observed=left` came from
bridge-departure, not an actual hangup, masking the orphan from the domain.

### Historical residue versus current defect
**Current, reproducible** at `02385fc`. The chain is intact in current code:
`open→closed` is allowed directly (`assertConferenceTransition`) with no drain/removal prerequisite;
`changeConferenceDesiredState('closed')` does not remove participants or hang up channels;
`conference.close` destroys only the bridge; the participant reconciler derives the node solely from
the active binding; T5-A49 retires that binding shortly after observed-close. Any
"close-with-admitted-participant, then session-end/removal" sequence leaks. T5-A49 binding
retirement (correct on its own) *tightened* the window by removing the reconciler's node authority
sooner. The two channels are the live symptom; a historical-repair mechanism is also required
because the existing orphaned participant will never be re-driven by normal reconciliation.

### Local-channel peer semantics
One `POST /ari/channels` originate creates both legs (`;1` and `;2`). The `;1` side is the ARI
channel whose id equals `participantChannelId(participantId)` = `utcp-part-{id}` and carries the
participant identity; `;2` is the deterministic peer (the normalizer resolves a `;2` suffix back to
the same participant — `AsteriskAriAdapterTest` line 765). `removeParticipantChannel` DELETEs only
`;1`; Local peer-hangup then destroys `;2` (proven live in T5-A53, where a single participant
removal cleaned up both legs). Bridge removal affects only membership, not channel existence. Full
destruction is evidenced by `StasisEnd`/`ChannelDestroyed` for both legs. Both ids are derivable
from the participant id; neither must be persisted.

### ARI cleanup semantics
`removeParticipantChannel` accepts `removeChannel [200,202,204,404,409,422]` and
`DELETE channels/{id} [200,202,204,404,409]` — 404 idempotent (channel already gone). The T5-A50
leak involved **no cleanup ARI call at all** (no op ran), so there is **no demonstrated cleanup
false-success mechanism**; the T5-A52 health-gated *inspection* correction must **not** be expanded
into the cleanup accept-lists. Accept-lists stay unchanged.

### Conference closure interaction
`conference.close` is generation-gated and runs on the active binding's node; it destroys the bridge
only. Closing does not drain admitted participants. This is the precipitating step: it removes the
bridge (and triggers binding retirement) while participant channels remain, and provides no
channel-cleanup itself.

### Binding-retirement interaction
The proven T5-A49 retirement (after observed-close) removed the *active*-binding node authority the
participant reconciler needs — but the **retired binding row retains the node id**, so historical
authority is sufficient for repair. Channel cleanup must **not** be made a prerequisite for observed
Conference closure or for binding retirement (that would couple/stall the proven retirement
lifecycle and could block on an unavailable node). Instead, participant channel cleanup should be
able to proceed best-effort using the **most-recent (retired-inclusive) binding** as node authority;
a stale worker may safely use the former binding because the deterministic channel id is
participant-specific. The proven binding-retirement lifecycle is not weakened.

### Canonical cleanup owner
**Model D — the existing participant lifecycle: `ConferenceParticipantReconciler` +
`conference.participant.remove` operation, with node authority resolved from the most-recent binding
(active OR retired) for a `desired_state='removed'` participant** whose conference is closed. The
`conference.participant.remove` operation remains the single cleanup **executor** (no duplicated
ownership). Rejected: Model A alone (op never scheduled once binding retired); Model B (coupling
channel cleanup into conference closure duplicates concerns and cannot repair existing orphans);
Model C as executor (a second cleanup authority). A bounded scheduled **discovery** sweep is added
that only **re-wakes** participant reconciliation for orphaned removed participants — it discovers,
it does not hang up — so cleanup execution stays solely in `conference.participant.remove`.

### Cleanup completion evidence
Cleanup is complete only when, on the authoritative (most-recent-binding) RuntimeNode, a health-gated
ARI inspection shows **both deterministic Local legs absent** (`participant_channel_exists=false`,
via T5-A52 family-health gating so a degraded family cannot false-succeed) and **no bridge
membership**, with `participant.desired_state='removed'`. A 2xx from the hangup call alone is
insufficient — presence must be re-inspected. Requires bounded retry via the operation lifecycle;
if the RuntimeNode is unavailable, cleanup defers (retryable) and completes after node recovery.
Corroborating `ChannelDestroyed` events may be used but ARI absence under a healthy family is the
authoritative signal.

### Historical repair contract
A scheduled `telephony-domain:reclaim-orphan-participant-channels --once` (everyMinute,
`withoutOverlapping`, batch-bounded), owned by `TelephonyDomainService`, parallel to
`retire-closed-bindings`. It discovers candidates from canonical state — participants with
`desired_state='removed'` (or belonging to a `closed`/observed-closed conference) whose deterministic
Local channel still exists (health-gated inspection) on the conference's most-recent binding node —
verifies the participant/conference no longer authorize channels, revalidates under lock, and
**re-wakes participant reconciliation** (it does not hang up channels itself). The existing
`conference.participant.remove` operation performs the hangup. Idempotent (a participant with no
present channel is not re-selected); tenant-scoped; derives the node and deterministic channel id
(no hard-coded ids); no prefix-wide sweep; no channel-age-only deletion; no env gate; no allowlist;
no routine Artisan; no Asterisk CLI as normal authority. A read-only Artisan diagnostic is
acceptable for break-glass only.

### Concurrency and stale authority
All node/generation guards from `conferenceFromOperation` remain: the `conference.participant.remove`
operation still validates the participant/conference and (extended) the most-recent binding node.
Deterministic per-participant channel ids make cross-participant collision impossible — a stale
worker cannot hang up a *new* participant's channel because a new participant has a different
`utcp-part-{id}`. Participant removal racing closure/failover/rebind: the remove op targets the
participant's own deterministic channel on the current-or-most-recent node; a generation-G worker
after G+1 exists is short-circuited by the existing generation guard. Delayed `ChannelDestroyed`,
one leg disappearing before the other (peer-hangup), and repeated cleanup ops are all idempotent
(re-inspection converges). The discovery sweep only wakes reconciliation and revalidates under lock,
so it cannot double-hang-up or race a late original cleanup destructively.

### Events and observability
Existing: `conference_participant.removed` (audit + emit), `conference_participant.admitted`, and the
adapter's `runtime_operation.asterisk_conference_participant_removed` completion event. Missing: a
transition-only signal distinguishing an orphaned-channel reclaim. Add one transition-only
`conference_participant.channel_reclaimed` event (audit + outbox) emitted once per successful reclaim,
carrying tenant, conference, participant, session, runtime_binding (most-recent), generation,
runtime_node, both channel ids, cleanup classification, attempt, and final outcome. Do not emit on
every discovery poll while the condition is unchanged (a participant with a present channel is
re-woken, not re-evented; the event fires only on the reclaim transition).

### Web-admin authority boundary
Unchanged: participant removal stays authorized via Web Admin/API; channel cleanup is runtime
automation. No Admin user runs Asterisk CLI/Artisan/Kubernetes, deletes channels, or selects channel
ids. The UI may surface a cleanup-degraded state; no "hang up orphan channel" button is warranted
(no business decision required — reclaim is deterministic automation).

### Existing test coverage
Present (`AsteriskConferenceRecoveryTest`): removed-participant-with-channel-present schedules
`conference.participant.remove` (353); projected-`left`-still-inspects (374); already-absent records
absence without a remove op (395); projected-`left` records absence and converges (432);
inspection-unavailable waits (459/489); **`test_close_before_remove_projected_left_does_not_bypass_participant_runtime_cleanup`
(518)** — closed conference + projected-`left` + channel present → schedules remove; pending-op
prevents duplicates (566). **Gap:** every one of these fixtures keeps the binding **active**; none
exercise the reconciler when the binding is **retired** (the exact leak). **Missing tests:**
- participant removed after binding retirement → node resolved from most-recent (retired) binding →
  `conference.participant.remove` scheduled (the forward fix)
- historical orphan discovery sweep re-wakes participant reconciliation for a removed participant
  whose channel persists
- both Local legs reclaimed by a single hangup (peer semantics)
- cleanup completion requires re-inspected absence, not a 2xx
- node unavailable during reclaim → deferred, retried after recovery
- health-gated inspection: degraded family does not falsely declare channels absent (regression)
- stale-generation / wrong-participant guard: never hang up a newer participant's deterministic id
- discovery sweep idempotency (no duplicate reclaim/event)
- tenant isolation
- reconciler converges once both legs absent

### Missing implementation (smallest coherent slice)
1. `ConferenceParticipantReconciler` (and `conferenceFromOperation`/participant node resolution for
   the remove op): when `participant.desired_state='removed'` and no active binding exists, resolve
   the node from the **most-recent binding** (active or retired) for the conference; keep all
   generation/stale guards.
2. `TelephonyDomainService::reclaimOrphanParticipantChannels()` + a
   `telephony-domain:reclaim-orphan-participant-channels --once` command + everyMinute
   `withoutOverlapping` schedule (mirrors `retire-closed-bindings`): discover removed participants
   whose deterministic channel still exists (health-gated) on the most-recent binding node,
   revalidate under lock, and re-wake participant reconciliation. Discovery/wake only — hangup stays
   in `conference.participant.remove`.
3. New transition-only `conference_participant.channel_reclaimed` event (audit + outbox).
4. Tests per the matrix above.
(No change to conference-close behavior, binding-retirement timing, or the cleanup ARI accept-lists.)

### Implementation-readiness decision
**A — bounded Codex implementation.** The audit establishes the exact root cause (participant still
admitted at close → channels orphaned → removal after binding retirement → reconciler blocked on
active-binding node resolution → hangup op never scheduled), current reproducibility, the single
cleanup executor (`conference.participant.remove`) with most-recent-binding node authority, exact
completion evidence (re-inspected health-gated absence of both legs), retained-authority historical
repair, concurrency/stale semantics (deterministic per-participant ids), the event contract, and the
exact test gaps. No unresolved wrong-node or stale-generation ambiguity.

### Ready-to-paste next prompt

```
# T5-A55 — Implement Orphan Participant Channel Reclaim

Implement the contract in docs/evidence/t2/multi-node-failover-readiness.md §T5-A54.

Starting state: HEAD 02385fc (or later), branch main, clean tree, UTCP_PHASE=T1.
Bounded implementation task. Do not begin a new phase; UTCP_PHASE stays T1.

## Problem
Closing a Conference with an admitted participant orphans its two Local channel legs:
conference.close destroys only the bridge (legs return to Stasis), the participant is removed
only at session-end AFTER the RuntimeBinding is retired (T5-A49), and
ConferenceParticipantReconciler resolves the node from the ACTIVE binding -> null -> blocked
-> conference.participant.remove hangup op is never scheduled. Two live orphans exist
(participant 91b59874, conference a90bac34, node B 05ddb383, retired binding 0efc6e14).

## Scope (smallest slice)
1. ConferenceParticipantReconciler + the participant node authority used by
   conference.participant.remove: when participant.desired_state='removed' and there is no ACTIVE
   binding, resolve the RuntimeNode from the MOST-RECENT binding (active or retired) for the
   conference. Keep all generation/stale guards (conferenceFromOperation) and the health-gated
   inspection. conference.participant.remove stays the ONLY cleanup executor.
2. TelephonyDomainService::reclaimOrphanParticipantChannels(int $batchSize): tenant-scoped,
   batched discovery sweep. Candidates: participants with desired_state='removed' whose
   conference is closed/observed-closed and whose deterministic Local channel still exists (via
   the health-gated inspection) on the conference's most-recent binding node. Under lock,
   revalidate participant/conference/binding, then re-wake participant reconciliation
   (wakeTarget). The sweep DISCOVERS and WAKES only; it must not hang up channels itself.
3. telephony-domain:reclaim-orphan-participant-channels {--once} {--batch=100} Artisan command +
   Schedule::command('telephony-domain:reclaim-orphan-participant-channels --once')->everyMinute()
   ->withoutOverlapping(), mirroring telephony-domain:retire-closed-bindings.
4. New transition-only conference_participant.channel_reclaimed event (audit + outbox) emitted
   once per successful reclaim (tenant, conference, participant, session, runtime_binding,
   generation, runtime_node, both channel ids, classification, attempt, outcome). No per-poll
   events.

## Invariants
- conference.participant.remove is the sole hangup executor; the sweep only discovers/wakes.
- Cleanup is complete only when a health-gated re-inspection shows BOTH Local legs absent and no
  bridge membership; a 2xx alone is insufficient.
- Node unavailable -> defer (retryable) -> complete after recovery. Never declare removed while
  channels remain.
- Never hang up a channel of a newer participant lifecycle or newer generation (deterministic
  per-participant ids + existing generation guard).
- Do NOT change conference.close behavior, binding-retirement timing, or the cleanup ARI
  accept-lists ([...404,409,422]). Do NOT extend the T5-A52 health gate into cleanup accept-lists.
- No prefix-wide or age-only deletion; no hard-coded channel ids; no env gate/allowlist; no
  routine Artisan/CLI cleanup authority. Read-only Artisan diagnostic acceptable.
- Deterministic Local-channel peer semantics: hangup of the ;1 leg destroys ;2; do not add
  fallback ids.

## Tests (AsteriskConferenceRecoveryTest + TelephonyDomainTest)
- removed participant with RETIRED binding -> node from most-recent binding -> remove op scheduled
- discovery sweep re-wakes reconciliation for a removed participant whose channel persists
- both Local legs reclaimed by a single hangup (peer)
- completion requires re-inspected absence, not a 2xx
- node unavailable -> deferred, retried after recovery
- degraded ARI family does NOT falsely declare channels absent (regression, T5-A52)
- stale-generation / wrong-participant guard
- sweep idempotency (no duplicate reclaim/event)
- tenant isolation
- reconciler converges once both legs absent
- conference.close still destroys only the bridge (regression)

## Verification
make repository-hygiene workflow-check secret-scan
make runtime-engine-config-check telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check
make runtime-engine-test telephony-domain-test asterisk-ari-test asterisk-conference-test asterisk-conference-recovery-test
git diff --check

## Commit
feat(t5): reclaim orphaned participant Local channels after closure
Do not push. Keep UTCP_PHASE=T1.
```

## T5-A55 — Orphan participant Local-channel reclamation implementation

**Verdict: repository implementation complete; live reclamation proof pending.**
Implemented the bounded post-closure participant cleanup correction at `e90452d` lineage without
mutating live Conferences, RuntimeBindings, Asterisk channels, Kubernetes resources, or PBX state.
The T5-A50 live orphan pair (`participant=91b59874`, `conference=a90bac34`,
retired binding `0efc6e14`, RuntimeNode `05ddb383`, Local legs `;1` and `;2`) remains live-proof
pending; no claim is made that those channels were removed during this repository task.

### Removed active-binding-only cleanup authority
`ConferenceParticipantReconciler` no longer treats "no active RuntimeBinding" as a permanent block
for a participant that is `desired_state=removed` after its Conference is conclusively
`desired_state=closed` and `observed_state=closed`. Open Conferences and non-removed participants
still require the active binding exactly as before. The post-closure path resolves cleanup authority
from the most recent **retired** RuntimeBinding for the same tenant and Conference, ordered by
`bound_at desc`, `created_at desc`, and stable id tie-breaker. The retired binding is not
reactivated, no replacement binding is inserted, and Conference binding-retirement timing is not
delayed or reversed.

### Single cleanup executor preserved
The only production PBX channel mutation remains the existing `conference.participant.remove`
operation handled by the Asterisk runtime adapter's `removeParticipantChannel` path. The new domain
discovery method only inspects and wakes reconciliation. It does not call ARI DELETE, Asterisk CLI,
bridge deletion, direct SQL repair, or any alternate cleanup operation type.

### Retired-binding authority and stale guards
Post-closure participant cleanup carries the selected `historical_runtime_binding_id` and
`orphan_reclamation=true` in the existing participant-remove operation payload. Before ARI mutation,
the adapter revalidates that the Conference is still closed/closed, the participant is still
removed, no active binding exists, the retained binding is still the most recent retired binding,
and its RuntimeNode is the operation node. Existing generation and wrong-node guards remain in front
of runtime mutation, so stale generation-G work cannot mutate after G+1 while the Conference remains
open, and closed-Conference cleanup uses the final retained generation.

### Both-leg completion evidence
The Asterisk ARI client now derives the exact peer Local-channel id from the deterministic primary
participant channel id (`utcp-part-{participantId};2`). Runtime inspection reports primary and peer
presence, bridge membership, aggregate participant presence, and health-gated runtime-reference
classification. Participant cleanup removes the primary deterministic channel and the exact peer
channel through the existing cleanup executor, then re-inspects. A successful cleanup HTTP response
alone is insufficient: the operation succeeds only after both deterministic legs are absent and no
bridge membership remains. If either leg is still present, or ARI resource-family/transport health
is unavailable, the persisted operation remains retryable/deferred.

### Historical discovery and scheduling
`TelephonyDomainService::reclaimOrphanParticipantChannels()` performs bounded discovery for removed
participants whose Conferences are closed/closed, have no active binding, have a most recent retired
binding with a RuntimeNode, and whose deterministic participant runtime reference is still observed
through the runtime inspection service. Each candidate is revalidated under lock before waking the
existing conference-participant reconciler. The scheduled internal command
`telephony-domain:reclaim-orphan-participant-channels --once` runs every minute with
`withoutOverlapping` and bounded batch controls. It has no tenant, participant, channel, or binding
selector and is not a manual management authority.

### Reclamation event
Added transition-only `conference_participant.channel_reclaimed` audit and outbox evidence after a
historical/orphan cleanup is proven complete. Payload evidence includes tenant, Conference,
participant, session when present, RuntimeBinding, generation, RuntimeNode, primary and peer channel
ids, classification, operation/attempt id, outcome, and timestamp. Repeated sweeps and repeated
operation completions deduplicate on the participant aggregate and emit no duplicate event.

### Concurrency and preservation
The implementation preserves no-capacity, failover, generation, RuntimeBinding, Conference closure,
and binding-retirement authority. A pending/open Conference without an active binding remains
blocked rather than using retired authority. Former failover-generation bindings are ignored after
closure because only the most recent retired binding may authorize cleanup. Discovery and delayed
original cleanup converge through the same idempotent participant-remove executor. No prefix-wide
channel scan, channel-age rule, hard-coded orphan id, active-binding recreation, binding deletion,
environment gate, runtime allowlist, UI action, API endpoint, Kubernetes procedure, or Asterisk CLI
management surface was added.

### Test coverage
Focused repository tests cover removed participants with active bindings, removed participants with
retired final bindings, multiple-binding chronology, open Conference without active binding,
participant-not-removed boundary, historical discovery and wake-up, discovery without runtime
mutation, repeated discovery idempotency, runtime-reference absence skip, both-Local-leg
completion, one-leg/still-present retry behavior, ARI family degraded deferral, reclamation
event/outbox deduplication, stale generation and wrong-node guards, cleanup 404 preservation,
Conference close regression, and binding-retirement preservation. The real handler path exercised
is `ConferenceParticipantReconciler -> conference.participant.remove -> AsteriskRuntimeAdapter ->
removeParticipantChannel -> health-gated reinspection -> channel_reclaimed event`.

### Remaining proof
The live automatic reclamation proof remains pending: deploy the repository fix, allow the scheduled
discovery sweep to find the existing T5-A50 orphan pair, and prove both Local legs are removed
without direct live mutation. Events-only listener liveness detection and other T5 follow-up work
remain outside this bounded repository correction.

## T5-A56 — Automatic orphan participant Local-channel reclamation: LIVE PROOF COMPLETE (historical event via forward path)

**Verdict: `T5_ORPHAN_STASIS_LOCAL_CHANNEL_CLEANUP_LIVE_PROOF_COMPLETE`.**
Deployed `62ae45e` and proved the automatic orphan reclamation contract from T5-A54/A55. The
existing T5-A50 orphan pair was automatically discovered and reclaimed through the canonical
`conference.participant.remove` operation using the retained (retired) binding, and a fresh
close-with-admitted-participant lifecycle reproduced the orphan condition live and was reclaimed
end-to-end **with the `conference_participant.channel_reclaimed` event emitted on NEW code**. One
divergence: the *historical* orphan's reclaim **event** was missed because an old command-worker
pod (terminating mid-rollout) executed the historical remove operation on pre-`62ae45e` adapter
code; the historical channels were still correctly reclaimed. The event path is proven on the
identical forward orphan run entirely on `62ae45e`.

### Phase marker
`versions.env` line 7 = `UTCP_PHASE=T1`; clean tree at `62ae45e`.

### Application image build and rollout
Built the api image from `62ae45e` (digest `sha256:2ea4bcd2`), pushed, rolled out api, scheduler,
telephony-reconciler, telephony-command-worker, worker, asterisk-ari-events,
telephony-event-normalizer, control-plane-outbox-dispatcher, simulator-event-source,
kamailio-registration-observer. Asterisk/Kamailio/Postgres/Redis/runtime-fencer/RBAC/NetworkPolicy
not rolled. All 11 app pods run the new digest.

### Live reclamation-code currency
Running command-worker/reconciler/api pods contain `reclaimOrphanParticipantChannels`,
`cleanupRuntimeAuthority` (retained-binding resolution), `ari_participant_cleanup_pending`
(both-leg reinspection), `orphan_reclamation`, and `conference_participant.channel_reclaimed`.
The image also contains the command, everyMinute schedule, and both-leg peer logic.

### Scheduler registration
`schedule:list` shows `* * * * *  php artisan telephony-domain:reclaim-orphan-participant-channels
--once` with `Has Mutex` (withoutOverlapping), batch-bounded (`--batch=100`). No manual invocation.

### No-manual-command proof
Reclamation was driven entirely by the everyMinute scheduler + reconciler + command-worker; the
`reclaim-orphan-participant-channels` command was never manually invoked (only read-only
`schedule:list` and normal authenticated APIs).

### Historical orphan baseline
Before rollout: participant `91b59874` desired=**removed**/observed=**left**; conference
`a90bac34` **closed/closed** gen 3; session ended; binding `0efc6e14` **retired** on node B
(`05ddb383`); 0 active binding. Two Local legs on node B: `;1`
(`utcp-part-91b59874-…`) and `;2` (`…;2`), both `Up`, `Stasis(utcp-t0-observation)`, no bridge
membership, ARI-visible. Node A clean; node B Pod UID `6e8e874c`.

### Automatic historical discovery
The scheduler discovered participant `91b59874` from canonical state (removed participant of a
closed/observed-closed conference with no active binding but a retired binding) — not a hard-coded
id — and re-woke participant reconciliation. Discovery performed no ARI DELETE, no Asterisk CLI,
and no direct operation mutation (it only wakes reconciliation).

### Discovery versus execution authority
The discovery sweep only wakes/enqueues participant reconciliation; the sole hangup executor is the
`conference.participant.remove` operation. No alternate cleanup executor appeared.

### Final retained binding resolution
Participant reconciliation selected the most-recent **retired** binding `0efc6e14` on RuntimeNode
`05ddb383` (node B) — the historical orphan op payload carried
`historical_runtime_binding_id=0efc6e14`, `runtime_node_id=05ddb383`, `orphan_reclamation=true`,
`configuration_generation=3`. It selected neither an earlier binding, node A, a simulator node, a
new binding, nor a cross-node fallback. No active binding was recreated (active_bindings stayed 0).

### Historical participant-remove operation
Exactly one `conference.participant.remove` operation for `91b59874`
(`43ebb392…`, orphan_reclamation=true, node B, gen 3, binding `0efc6e14`), succeeded on attempt 1.
No alternate cleanup operation type appeared.

### Historical both-leg cleanup
Both Local legs removed (node B `91b59874` channel count 0). The reconciler+adapter targeted the
primary `;1` deterministic channel; Local peer-hangup destroyed `;2`.

### Historical cleanup reinspection
The historical op ran on the retained-binding node and the channels are absent. **Divergence:** the
op's `lease_owner` was `telephony-command-worker-5d997465f8-4t98q` — the **old** pod (new pod is
`668b7fb47-xkchf`), which was still terminating during the rollout and executed the op on
pre-`62ae45e` adapter code. The old adapter cleans the channels (removeChannel + DELETE, peer
hangup) but has no both-leg reinspection and no reclaim-event call. Hence the historical channels
were correctly reclaimed but **no `conference_participant.channel_reclaimed` event was emitted for
`91b59874`**. This is a rollout race, not a code defect — proven by the forward run below on
clean `62ae45e` code, and by no old pods remaining afterward.

### Historical reclamation event
0 audit / 0 outbox `conference_participant.channel_reclaimed` for `91b59874` (see divergence).
The functional reclamation (both legs removed via the retained-binding orphan op) is proven; the
event is proven on the forward orphan (identical code path, NEW executor).

### Historical idempotency
Across 3+ later sweeps (04:33:39→04:34:49): exactly 1 remove op, 0 reclaimed events, channels
stayed 0 — no duplicate operation, no duplicate event, no recreated channel/binding. The discovery
sweep correctly saw the channels absent and did not re-wake.

### Unrelated resource preservation
Node A stayed clean (0 channels); no other participant channel removed; no prefix-wide cleanup; no
bridge deleted by discovery; no active Conference affected; no RuntimeNode state change; 0 active
bindings throughout.

### Forward proof Conference
Session `f76dad8c` (expiry 05:05:50), conference `e67db906` (gen 2, node B `05ddb383`), participant
`f58004a9` admitted, active binding `062990c5`, conference `open/ready`, deterministic bridge
`utcp-conf-e67db906-…`, two participant Local legs. Created via normal APIs only.

### Forward closure timeline
Closed at 04:36:33 with the participant **still admitted/joined**. Timeline: bridge destroyed
immediately (control-socket bridge count 0), the **two participant Local legs lingered in Stasis**
(channels=2), conference observed=**closed**, binding **retired** at 04:37:05. `conference.close`
added no synchronous participant hangup — it destroyed only the bridge, exactly reproducing the
T5-A50 orphan condition on live NEW code.

### RuntimeBinding retirement
Closure and binding-retirement timing were unchanged (T5-A49 behavior): binding `062990c5` retired
~30s after observed-close while the participant channels still existed.

### Forward participant removal
Ending session `f76dad8c` transitioned participant `f58004a9` to desired=**removed**/observed=left
with the conference closed/closed, 0 active binding, and retained binding `062990c5` (node B)
available. No manual cleanup operation was created.

### Forward automatic discovery and cleanup
The scheduler + reconciler + NEW command-worker (`668b7fb47-xkchf`) automatically discovered the
orphan, resolved the retained binding `062990c5` (node B), created exactly one
`conference.participant.remove` (orphan_reclamation=true, node B, gen 3, binding `062990c5`),
removed the primary Local leg, and Local peer-hangup removed the peer — both legs absent
(participant channel count 0). The op only succeeds when both legs are re-inspected absent (the
adapter throws retryable `ari_participant_cleanup_pending` otherwise); it succeeded on attempt 1.
No direct PBX cleanup, no manual command, no manual reconciliation, no new binding, no cross-node
request, no duplicate operation.

### Forward event and idempotency
Exactly one `conference_participant.channel_reclaimed` **audit + outbox** event for `f58004a9`,
payload complete: tenant, conference, participant, session `f76dad8c`, runtime_binding `062990c5`,
generation 3, runtime_node `05ddb383`, primary `utcp-part-f58004a9-…`, peer `…;2`, classification
`post_closure_orphan`, operation/attempt id, outcome `reclaimed`, occurred_at. Across 4 later
sweeps (04:39:34→04:41:19): remove_ops=1, audit=1, outbox=1, 0 active bindings — no duplicate op or
event, no resource recreation.

### Runtime-unavailable deferral boundary
No node/ARI failure was introduced; the unavailable-deferral path is covered by repository tests
(`ari_participant_cleanup_pending` retryable + inspection-unavailable waits) and live code currency
(`reclaimOrphanParticipantChannels` skips `unavailable`/`failed` inspections). No natural transient
occurred.

### Final runtime state
Both intended RuntimeNodes active/ready; both Asterisk Deployments 1/1; both leases claimed; one
open epoch per node; 0 open/pending Conferences; 0 active RuntimeBindings; 0 actionable runtime
operations; **zero T5-A50 orphan legs and zero forward-proof legs** (0 utcp-part channels on both
nodes); 0 proof bridges; all simulator nodes disabled; all 8 ARI modules loaded; scheduler and
workers Ready. Total `conference_participant.channel_reclaimed` events: 1 audit / 1 outbox (the
forward reclaim; the historical one was missed to the rollout race).

### Divergence
The historical orphan's reclaim **event** was not emitted because an old command-worker pod
(`5d997465f8-4t98q`), still terminating during the rollout, executed the historical
`conference.participant.remove` operation on pre-`62ae45e` adapter code. The historical **channels**
were still correctly reclaimed via the retained binding. No manual/break-glass action was taken; no
old pods remained afterward; the identical reclaim-and-event path is fully proven on the forward
orphan run entirely on `62ae45e` (op executed by the new command-worker, event emitted). To obtain
a historical event without a rollout race, redeploy while ensuring the old command-worker has fully
terminated before any orphan op is claimed.

## T5-A57 — Coordinator, fencing, and reclamation observability contract (evidence-only)

**Verdict: `T5_RESILIENCE_OBSERVABILITY_CONTRACT_DEFINED`.**
Read-only audit at `982d14d`. The repository already has a complete pull-based Prometheus
exposition stack whose metrics are **computed on-scrape from durable PostgreSQL state** (not
in-process counters). Every proven T5 transition already leaves a durable row (`runtime_operations`,
`control_plane_outbox_messages`/`control_plane_audit_records`, `conferences.failover_state`,
`conference_recovery_metric_events`). The minimal, safe contract is therefore to **extend
`MetricsController` with a small set of on-scrape aggregations plus a `utcp_build_info` gauge, and
extend the existing PrometheusRule** — no new in-process instrumentation, no new columns, no new
tables. This inherently prevents double-counting and is restart-safe. The T5-A56 rollout race is an
observability-only gap requiring deployment sequencing + version-skew visibility, not a repository
correction. No production code, tests, manifests, dashboards, or runtime state were modified.

### Existing metrics architecture
`GET /api/metrics` → `App\Http\Controllers\Platform\MetricsController` (single-action, ~48KB) emits
**Prometheus text exposition** (`# HELP`/`# TYPE`, `name{label="v"} value`). Metrics are recomputed
each scrape by aggregating PostgreSQL tables (`runtime_operations`, `conference_recovery_metric_events`,
`runtime_reconciliation_states`, `conferences`, `conference_participants`, `telephony_sessions`,
event-source/lease tables). 39 metric families exist (simulator, telephony_sessions, conferences,
`conference_operations_total`, `conference_participant_operations_total`,
`utcp_conference_runtime_inspections_10m`, `utcp_conference_runtime_inspection_failures_10m`,
`utcp_conference_recovery_operations_total`, `utcp_conference_recovery_backlog`,
`utcp_conference_recovery_lag_seconds`, asterisk_ari_*, kamailio_*). The canonical pattern
(`conferenceOperationMetrics`): `SELECT operation_type, status AS result, coalesce(last_failure_class,
'none') AS failure_class, count(*) GROUP BY …` → one `sample()` line per bounded group. `sample()`
sanitizes every label value to `[A-Za-z0-9_.:-]`.

### Metrics export path
Pull-based. `ServiceMonitor` `infrastructure/kubernetes/observability/monitors/utcp-application-servicemonitor.yaml`
scrapes port `http` path `/api/metrics` every 30s. No push/pushgateway. `BuildInfo` (version,
commit, built_at from `config('utcp.build.*')`) is exposed at `/api/version` but has **no metric**.

### Prometheus scrape boundary
Scrape ownership is the Prometheus Operator via the ServiceMonitor (label-selected). `/api/metrics`
is **unauthenticated** but **NetworkPolicy-restricted** (`observability/network-policies/allow-application-metrics.yaml`);
only Prometheus may reach it. No public exposure. A new slice must reuse `/api/metrics` and add no
new endpoint or auth surface.

### Grafana and alerting inventory
Alerts: one `PrometheusRule` `infrastructure/kubernetes/observability/alerts/utcp-alerts.yaml`
(deployment-availability via `kube_deployment_status_replicas_available`, simulator backlog/terminal,
conference/participant reconciliation-stuck, conference/participant operation-terminal-failure). **No
T5-specific alerts** (no fence/restore/no-capacity/binding/orphan/ARI-health). Dashboards:
ConfigMap-provisioned Grafana dashboards under `infrastructure/kubernetes/observability/dashboards/`
(`platform-overview.yaml`, `workload-logs.yaml`) — dashboard ownership is Kustomize ConfigMaps. No
T5 resilience dashboard exists.

### Metric naming convention
Two families: legacy unprefixed (`conference_operations_total`, `telephony_sessions_active`) and
`utcp_`-prefixed for newer telephony/recovery/asterisk metrics
(`utcp_conference_recovery_operations_total`, `utcp_conference_runtime_inspections_10m`). New T5
metrics MUST use the `utcp_` prefix, `_total` for counters, `_seconds` for time gauges, and bounded
enum labels (`operation_type`, `result`, `failure_class`, `reason`, `resource_type`, `event_type`,
`health`, `failover_state`).

### Cardinality contract
Existing MetricsController uses **only bounded enum labels** — no tenant, Conference, participant,
binding, operation, channel, Pod, node-name, namespace, or Deployment labels; that policy is
preserved. Proven-bounded vocabularies: `operation_type` (finite config enum), `status`/`result`
(≤~8 OperationStatus values), `failure_class` (11 FailureClass values), `reason`/`health`
(4 runtime_reference_health values), `resource_type` (conference|conference_participant),
`event_type` (finite), `failover_state` (pending_no_capacity). **Static cardinality ceilings** per
new family below; total new series < ~150. Forbidden as labels (kept only in logs/payload/audit/
outbox): tenant UUID, Conference/participant/binding/operation/session UUIDs, channel IDs, Pod UID,
image digest, git hash, raw error messages.

### Failover coordinator metrics
- `utcp_conference_failover_events_total` (counter): labels `event_type` ∈ {`no_replacement`,
  `runtime_binding_replaced`}. Source: `control_plane_outbox_messages` grouped by `event_type`
  (both events already write-time deduplicated). Owner: MetricsController on-scrape. Ceiling: 2.
  Rebind-completed and pending-cleared are implied by `runtime_binding_replaced`.

### No-capacity metrics
- `utcp_conference_failover_pending` (gauge): label `failover_state` ∈ {`pending_no_capacity`}.
  Source: `SELECT failover_state, count(*) FROM conferences WHERE failover_state IS NOT NULL GROUP BY 1`.
  Owner: MetricsController on-scrape. Changes only with the authoritative `failover_state` column;
  repeated coordinator sweeps do not change it (idempotent state). Ceiling: 1.
- `utcp_conference_failover_pending_oldest_seconds` (gauge): `now - min(failover_started_at)` over
  pending conferences (mirrors the existing `utcp_conference_recovery_lag_seconds` gauge). Duration
  begins at the stable `failover_started_at` and is read on-scrape, so historical replay cannot
  double-count. Ceiling: 1. **A duration histogram is deliberately excluded** from this slice: a
  Prometheus histogram needs per-observation buckets emitted in-process at clear-time, which would
  break the on-scrape-from-DB pattern; the oldest-pending gauge is the safe analog.

### Verification / Fence / Restore metrics
- `utcp_runtime_resilience_operations_total` (counter): labels `operation_type` ∈
  {`runtime.node.verify_conference_absent`, `runtime.node.runtime.fence`, `runtime.node.restore`},
  `result` (status), `failure_class`. Source: `runtime_operations` grouped (the identical pattern to
  `conferenceRecoveryOperationMetrics`, which today covers only conference/participant ops — the gap).
  This counts **operation rows = logical operations**; `attempt_count` is a column, not a new row, so
  repeated polling attempts never create new series. Verification classifications (present / authoritative
  absent / degraded / stale / terminal) surface through `result`+`failure_class`
  (`ari_http_transport_failed`, `absence_verification_context_not_found`, etc., all bounded). Owner:
  MetricsController. Ceiling ≈ 3 × 8 × 12 = ~90 max, realistically < 30.
- Fence "owned-Pods present/zero" and "scale initiated" are execution-internal to the fence adapter
  and are NOT separate operation rows; they are already reflected by fence op `result`
  (retry_scheduled with `fence_in_progress` vs succeeded `fenced`). No extra metric needed.

### Restore metrics
`runtime.node.restore` is covered by `utcp_runtime_resilience_operations_total`. **Explicit
semantics:** this counter counts **operation objects** — a predecessor and its successor
(`supersedes_restore_operation_id`) each count as one row, so it is NOT a count of logical restore
requests. That is honest and sufficient for health/rate; a logical-request counter would require
deduping by (node, source_fence, generation) and is deferred. Successor generation
(`restore_attempt_generation`) is low-cardinality but is NOT added as a label in this slice (kept in
the operation payload) to avoid unbounded growth if generations ever climb. Terminal restore
failures surface as `result="terminal_failed"`.

### RuntimeBinding retirement metrics
- `utcp_conference_runtime_binding_retired_total` (counter): label `reason` ∈ {`conference_closed`}
  (the implementation uses a single reason; `closed_conference_residue` was intentionally not
  implemented, per T5-A48/A50). Source: `control_plane_outbox_messages` event_type
  `conference.runtime_binding_retired`. Owner: MetricsController. Ceiling: 1–2.
- `utcp_conference_stale_active_bindings` (gauge): the retire-closed-bindings **candidate invariant**
  = closed/observed-closed conferences with an active binding (proven-drained to 0 in T5-A50).
  Source: the same predicate as `retireClosedConferenceBindings`. Valuable as an **invariant alarm**
  (should always be 0). Owner: MetricsController. Ceiling: 1.

### Orphan-reclamation metrics
- `utcp_conference_participant_channel_reclaimed_total` (counter): label `classification` ∈
  {`post_closure_orphan`}. Source: `control_plane_outbox_messages` event_type
  `conference_participant.channel_reclaimed` (one event per reclaim, write-time deduped). Owner:
  MetricsController. Ceiling: 1–2.
- `utcp_conference_orphan_participant_candidates` (gauge): the `reclaimOrphanParticipantChannels`
  **DB-derivable candidate population** = removed participant + closed/observed-closed conference +
  retired binding + no active binding. This is a current-state count (idempotent — the same candidate
  is not counted once per minute; it is present until reclaimed). It is an **upper bound**: DB alone
  cannot know a Local channel is still present (that needs ARI), so the gauge over-counts by any
  candidate whose channels are already gone but whose event was missed (e.g., the T5-A56 rollout-race
  orphan). Owner: MetricsController. Ceiling: 1. Deferred-unavailable / stale / terminal-failure for
  reclamation surface through the reused `conference.participant.remove` op result (already in
  `conference_participant_operations_total`), so no orphan-specific failure counter is added
  (discovered = candidate gauge; reclaimed = the counter; failed = the participant op metric).

### ARI reference-health metrics
`recordInspectionMetric` writes `conference_recovery_metric_events(adapter_key, resource_type, result,
failure_class, reason)` where `reason` = `runtime_reference_health` (`healthy_present`,
`healthy_absent`, `degraded_unavailable`, `transport_unavailable`). The existing
`utcp_conference_runtime_inspections_10m` groups by `result`+`failure_class` only, so
`healthy_present` vs `healthy_absent` collapse to `result="observed"` (a gap); the failure metric
already carries `reason` for the unavailable/failed cases. **Add**
`utcp_conference_runtime_reference_health_10m` (current ten-minute gauge): labels `resource_type` ∈
{conference, conference_participant}, `health` ∈ the 4 values above. Source:
`conference_recovery_metric_events` grouped by `resource_type, reason`. This distinguishes routine
`healthy_absent` (normal cleanup) from abnormal `degraded_unavailable` (false-404 degradation).
Counters/gauges, not histograms. Ceiling: 2 × 4 = 8. **No new durable event** is emitted per
inspection (the metric aggregates the existing telemetry table). NOTE: `conference_recovery_metric_events`
grows every ~15s per open conference and every scrape reads it whole (an existing pattern); a
bounded retention/pruning of that table is a **pre-existing** concern worth a separate follow-up, not
part of this slice.

### Instrumentation ownership
**Single owner for every metric: `MetricsController` on-scrape.** No update is placed in the
scheduler, reconciler, operation handler, projection, or event listener — they only continue to
write their durable rows/events as today. This is why there is no double-count risk across
components: the metric is a pure read-aggregation of durable state. The only non-DB metric,
`utcp_build_info{version,commit} 1` (gauge, value 1), is sourced from `BuildInfo` config.

### Deduplication and restart semantics
Inherent. Counters = `count(*)` over durable rows (one operation row / one deduped outbox event per
logical transition); a repeated coordinator/retirement/reclaim sweep that produces no new row/event
does not move the counter. Gauges = current-state `count(*)`/`min(timestamp)`; idempotent across
sweeps. Process restart does not reset anything (state lives in PostgreSQL, not process memory).
Historical replay cannot double-count because events are write-time deduplicated and operations are
idempotency-keyed.

### Rollout-race classification
**D — observability-only gap.** T5-A56: the NEW reconciler created a `conference.participant.remove`
op with `orphan_reclamation=true`; a terminating OLD command-worker pod (`5d997465f8`), still within
its default ~30s termination grace, claimed the op lease and executed it on OLD adapter code, which
removed both channels correctly but lacks the `recordOrphanParticipantChannelReclaimed` call. The
**functional outcome was correct**; only the supplementary durable `conference_participant.channel_reclaimed`
event was missed. Under the metrics contract above, counts are unaffected — the
`conference.participant.remove` **operation row** is present regardless of executor version and is
counted by the participant-op metric; only the reclaim-**event** counter would under-read by one for
that specific orphan. Old/new versions are schema- and event-compatible (the op ran and succeeded).
This is expected rolling-deployment overlap (an in-flight worker finishing a claimed op during the
overlap window) — **not** a drain defect (B: claiming a queued op mid-rollout is normal lease
semantics) and **not** an operation-event compatibility defect that breaks correctness (C). Remedy:
deployment sequencing/visibility — a `utcp_build_info` gauge + a version-skew alert so operators see
overlap, plus (optional, low priority) a preStop drain or `stopWhenEmpty` on the command-worker to
shorten the overlap. No historical event synthesis is warranted (durable event semantics do not
require it; the op evidence is sufficient).

### Executor-version evidence
`runtime_operations` already records `lease_owner` (e.g. `telephony-command-worker-668b7fb47-xkchf:command:1`
— Pod name embedding the ReplicaSet hash as a build proxy, worker kind, and PID), `attempt_count`,
`started_at`, `completed_at`, `status`, `last_failure_class/code`. This is sufficient durable
forensic evidence of the executor Pod per operation. It does NOT record the application build
revision/image digest. Recommendation: add a bounded `utcp_build_info{version,commit} 1` gauge (the
standard Prometheus build-info pattern; `version`+`commit` are bounded per build, briefly 2 series
during a rollout) so version skew is observable. Do NOT put image digest or git hash into
high-cardinality operation-metric labels; they belong in the build-info gauge, `/api/version`, logs,
and Deployment metadata.

### Security and tenancy
Preserved. Every proposed label is a bounded operational enum — no secrets, ARI credentials, phone
numbers, or Conference/participant/session/channel/binding/operation identifiers. No tenant label
(the repository has no documented bounded-tenancy metrics policy, and existing metrics aggregate
across tenants). `/api/metrics` stays unauthenticated but NetworkPolicy-restricted to Prometheus; no
new endpoint. `sample()` label sanitization already strips unexpected characters.

### Alert contract
Extend `utcp-alerts.yaml` (PrometheusRule), following the existing
`sum(metric{...}) > N for: Xm severity: …` style:
- `UTCPConferencePendingNoCapacity`: `sum(utcp_conference_failover_pending) > 0` for 15m, warning —
  a Conference is stuck with no replacement capacity; operator adds/restores capacity. (15m avoids a
  transient no-capacity window.)
- `UTCPRuntimeFenceTerminalFailure`:
  `sum(utcp_runtime_resilience_operations_total{operation_type="runtime.node.runtime.fence",result="terminal_failed",failure_class!="none"}) > 0`
  for 10m, warning — fencing stuck; check node/K8s API/NetworkPolicy.
- `UTCPRuntimeRestoreTerminalFailure`: same for `operation_type="runtime.node.restore"`, 10m, warning.
- `UTCPStaleActiveBindings`: `sum(utcp_conference_stale_active_bindings) > 0` for 10m, warning —
  the retirement sweep is not draining; invariant breach.
- `UTCPOrphanParticipantCandidates`: `sum(utcp_conference_orphan_participant_candidates) > 0` for
  15m, warning — the reclaim sweep is not draining (note the T5-A56 upper-bound caveat: a missed
  event can hold this > 0 even after channels are gone; a bounded threshold/for window mitigates
  false alarms; this alert is a candidate to gate behind the follow-up event-resilience fix).
- `UTCPAriReferenceDegraded`:
  `sum(utcp_conference_runtime_reference_health_10m{health="degraded_unavailable"}) > 3`
  for 10m, warning — sustained false-404-style ARI degradation.
- `UTCPWorkerVersionSkew`: `count(count by (version) (utcp_build_info)) > 1` for 15m, warning —
  a rollout is stuck / version skew persists (directly addresses the T5-A56 visibility gap; 15m
  avoids firing on normal rollouts).
None require manual reconciliation as normal recovery — each indicates the *automation* is stuck.

### Dashboard contract
Minimum T5 panels: failover events + pending gauge + oldest-pending seconds; fence/restore/verify
outcomes (by result); ARI reference-health classifications; binding-retirement total + stale-active
gauge; orphan reclaimed total + candidate gauge; actionable-operation backlog (reuse existing
recovery backlog/lag). Ownership = a Kustomize ConfigMap dashboard alongside `platform-overview.yaml`.
**Recommendation: instrument + alert first, defer the dashboard** (or include one minimal
`utcp-resilience.yaml` ConfigMap) — visualization is only useful after the series exist, and a
dashboard is data-only (no code risk).

### Existing test coverage
`MetricsEndpointTest` (2 tests) asserts the endpoint renders and includes existing families. Recovery
inspection/operation metrics are indirectly exercised by `AsteriskConferenceRecoveryTest` fixtures.
**Missing:** on-scrape-from-DB dedup assertions for each new family (create N durable rows → metric =
N; repeat sweep → unchanged), label-vocabulary assertions (only bounded enums; no UUID/tenant/pod
labels), `utcp_conference_failover_pending` gauge from `failover_state`, pending-oldest-seconds
gauge, `utcp_runtime_resilience_operations_total` covering fence/verify/restore, restore
predecessor+successor both counted as operation objects, binding-retired counter + stale-binding
gauge invariant, channel-reclaimed counter + orphan-candidate gauge, ARI health 4-value counter,
`utcp_build_info` gauge, and PrometheusRule expression validity.

### Missing implementation (smallest coherent slice)
1. `MetricsController`: add 8 on-scrape metric families —
   `utcp_conference_failover_events_total`, `utcp_conference_failover_pending`,
   `utcp_conference_failover_pending_oldest_seconds`, `utcp_runtime_resilience_operations_total`
   (verify/fence/restore), `utcp_conference_runtime_binding_retired_total`,
   `utcp_conference_stale_active_bindings`, `utcp_conference_participant_channel_reclaimed_total`,
   `utcp_conference_orphan_participant_candidates`, `utcp_conference_runtime_reference_health_10m`
   — each following the existing `sample()`/GROUP BY pattern, plus `utcp_build_info{version,commit} 1`.
2. `utcp-alerts.yaml`: add the 7 bounded alerts above.
3. `MetricsEndpointTest` (+ a focused metrics unit test): the coverage matrix above.
4. Optional: a minimal `utcp-resilience.yaml` Grafana dashboard ConfigMap (or defer).
(No new columns, no in-process counters, no new tables, no new endpoint, no env gate.)

### Implementation-readiness decision
**A — bounded Codex implementation.** The audit establishes the existing on-scrape-from-DB metrics
architecture and export/scrape/security boundary, exact `utcp_`-prefixed metric names and types,
finite label vocabularies with static ceilings, a single instrumentation owner (MetricsController)
that makes double-counting structurally impossible, exact no-capacity/fence/restore/binding/orphan/ARI
contracts, the executor-version evidence plan (`utcp_build_info` gauge + existing `lease_owner`), the
rollout-race classification (D, observability-only), bounded actionable alerts, dashboard scope
(deferrable), and the test matrix.

### Ready-to-paste next prompt

```
# T5-A58 — Implement T5 Resilience Observability Metrics and Alerts

Implement the contract in docs/evidence/t2/multi-node-failover-readiness.md §T5-A57.

Starting state: HEAD 982d14d (or later), branch main, clean tree, UTCP_PHASE=T1.
Bounded implementation task. Do not begin a new phase; UTCP_PHASE stays T1.

## Architecture (MUST follow)
Metrics are computed ON-SCRAPE from durable PostgreSQL in App\Http\Controllers\Platform\
MetricsController (Prometheus text exposition at /api/metrics). Do NOT add in-process counters,
new columns, new tables, a new endpoint, or per-component instrumentation. Reuse the existing
sample() helper and GROUP BY pattern. Every label MUST be a bounded enum — NO tenant/Conference/
participant/binding/operation/session/channel UUID, Pod UID, image digest, git hash, node name,
namespace, Deployment, or raw error message labels.

## Metrics to add (all utcp_-prefixed, on-scrape)
- utcp_conference_failover_events_total (counter) label event_type — from
  control_plane_outbox_messages where event_type in
  (conference.failover_coordinator.no_replacement, conference.runtime_binding_replaced) group by event_type
- utcp_conference_failover_pending (gauge) label failover_state — conferences where failover_state
  is not null group by failover_state
- utcp_conference_failover_pending_oldest_seconds (gauge) — now - min(failover_started_at) over
  pending conferences (0 when none)
- utcp_runtime_resilience_operations_total (counter) labels operation_type,result,failure_class —
  runtime_operations where operation_type in (runtime.node.verify_conference_absent,
  runtime.node.runtime.fence, runtime.node.restore) group by operation_type,status,last_failure_class
  (counts operation ROWS = logical ops; predecessor+successor restores each count as objects)
- utcp_conference_runtime_binding_retired_total (counter) label reason — outbox event_type
  conference.runtime_binding_retired
- utcp_conference_stale_active_bindings (gauge) — closed/observed-closed conferences with an active
  binding (same predicate as retireClosedConferenceBindings); expected 0
- utcp_conference_participant_channel_reclaimed_total (counter) label classification — outbox
  event_type conference_participant.channel_reclaimed
- utcp_conference_orphan_participant_candidates (gauge) — removed participant + closed/observed-closed
  conference + retired binding + no active binding (same predicate as reclaimOrphanParticipantChannels)
- utcp_conference_runtime_reference_health_10m (current ten-minute gauge) labels resource_type,health —
  conference_recovery_metric_events group by resource_type, reason (health in healthy_present,
  healthy_absent, degraded_unavailable, transport_unavailable)
- utcp_build_info (gauge, value 1) labels version,commit — from BuildInfo/config('utcp.build.*')

Each family MUST emit a zero/none placeholder sample when its table is absent or empty (match the
existing pattern). Guard every query with Schema::hasTable.

## Alerts (extend infrastructure/kubernetes/observability/alerts/utcp-alerts.yaml PrometheusRule)
- UTCPConferencePendingNoCapacity: sum(utcp_conference_failover_pending) > 0 for 15m warning
- UTCPRuntimeFenceTerminalFailure: sum(utcp_runtime_resilience_operations_total{operation_type=
  "runtime.node.runtime.fence",result="terminal_failed",failure_class!="none"}) > 0 for 10m warning
- UTCPRuntimeRestoreTerminalFailure: same for operation_type="runtime.node.restore" 10m warning
- UTCPStaleActiveBindings: sum(utcp_conference_stale_active_bindings) > 0 for 10m warning
- UTCPOrphanParticipantCandidates: sum(utcp_conference_orphan_participant_candidates) > 0 for 15m warning
- UTCPAriReferenceDegraded: sum(utcp_conference_runtime_reference_health_10m{health=
  "degraded_unavailable"}) > 3 for 10m warning
- UTCPWorkerVersionSkew: count(count by (version)(utcp_build_info)) > 1 for 15m warning

## Invariants
- Single owner = MetricsController on-scrape; no double-count; restart-safe (no process state).
- All labels bounded enums; no high-cardinality identifiers; no tenant label.
- No new /metrics endpoint, no auth change, no env gate, no manual-recovery surface.
- Do not change conference.close, binding-retirement, reclaim, fence, or restore behavior.

## Tests (MetricsEndpointTest + a focused metrics test)
- each new family renders with # HELP/# TYPE and bounded labels only
- create N durable rows/events -> counter == N; repeat scrape -> unchanged (dedup/idempotent)
- failover_pending gauge reflects conferences.failover_state; oldest_seconds from failover_started_at
- resilience_operations covers verify/fence/restore; restore predecessor+successor both counted
- stale_active_bindings and orphan_participant_candidates gauges reflect their predicates (0 when clean)
- runtime_reference_health counter carries the 4 bounded health values by resource_type
- utcp_build_info gauge value 1 with version+commit
- assert NO UUID/tenant/pod/deployment label appears in any new sample
- PrometheusRule expressions are syntactically valid (promtool if available in CI, else a fixture check)

## Optional (same slice or deferred)
- minimal Grafana dashboard ConfigMap infrastructure/kubernetes/observability/dashboards/utcp-resilience.yaml

## Verification
make repository-hygiene workflow-check secret-scan
make runtime-engine-config-check telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check
make runtime-engine-test telephony-domain-test asterisk-ari-test asterisk-conference-test asterisk-conference-recovery-test
git diff --check

## Commit
feat(t5): add resilience observability metrics and alerts
Do not push. Keep UTCP_PHASE=T1.
```

---

# T5-A59 — Live proof: resilience metrics and Prometheus alerts (b78a0b5)

Verdict: `T5_RESILIENCE_OBSERVABILITY_LIVE_PROOF_INCOMPLETE`. The b78a0b5 deliverable
(10 new metric families + 6 Prometheus alert rules) is deployed and substantially
proven live and correct, but two verified divergences prevent an unqualified pass:
(D1) `utcp_conference_runtime_reference_health_10m` emits duplicate `health="other"`
series so Prometheus under-reports the aggregated `other` bucket; (D2) `/api/metrics`
is publicly reachable through the catch-all Traefik/Gateway route. Both are
pre-existing (not b78a0b5 regressions); details below.

## Phase marker
- `versions.env` `UTCP_PHASE=T1` (read from file). HEAD `b78a0b5a5ccf3cdd1e9fea3e8a916b2b59c9f7cd`,
  branch `main`, working tree clean at start. `UTCP_CONTRACT_VERSION=0.1.0-dev`.

## Environment recovery performed before the proof (infrastructure only)
The `utcp-local` cluster was in a post-host-restart crash state (documented node-IP-shuffle):
- k3s agent-0/agent-1 containers crash-looped with `unable to initialize network policy
  controller: error getting node subnet: failed to find interface with specified node ip`.
  Recovered with `k3d cluster stop utcp-local && k3d cluster start utcp-local` (utcp-local only;
  apntalk-local untouched). Agents came back stable; node IPs consistent
  (server 172.24.0.2, agent-0 172.24.0.4, agent-1 172.24.0.3).
- apiserver-egress NetworkPolicies had stale pinned IPs (`allow-runtime-fencer-kubernetes-api`
  172.24.0.4, `allow-observability-kubernetes-api-egress` 172.24.0.5) vs the live endpoint
  172.24.0.2, blocking the Prometheus Operator + Prometheus SD (operator CrashLoopBackOff on
  `10.43.0.1:443 connection refused`). Recovered canonically via `scripts/security/render-apiserver-policy`
  + `kubectl apply` of the three rendered `.runtime/...` egress files; `check-apiserver-policy-drift`
  then passed `endpoint=172.24.0.2/32:6443`. This restored the intended posture (pinned to the live
  apiserver) — no policy was weakened, and the application-metrics ingress policy was not touched.
  Rendered files live under gitignored `.runtime/`; working tree stayed clean.

## Application image build and rollout
- Built the canonical API image from the clean tree: `docker build -f infrastructure/docker/api/Dockerfile
  --target app-prod` with the script's args plus the real `BUILD_COMMIT=b78a0b5`
  (`BUILD_VERSION=0.1.0-dev`, `BUILD_CREATED=2026-07-20T10:55:13Z`, `IMAGE_SOURCE=local`).
- Verified the built image's `MetricsController.php` contains all 10 new metric names before push.
- Pushed via the existing local-registry workflow to `127.0.0.1:5001/utcp/api:0.1.0-k1-dev`
  (== in-cluster `utcp-local-registry:5000/utcp/api:0.1.0-k1-dev`). Registry manifest digest
  `sha256:0d96195969767d6d1c4e801b7ff4413b97115faade00f54bdd01f75459ea2d9f`.
- Rolled out ONLY `deployment/api` (imagePullPolicy=Always). New pod `api-7684684b5b-s24sl` on
  `k3d-utcp-local-server-0`, imageID `...@sha256:0d96195...`, Deployment revision 79, Ready.
  Asterisk/Kamailio/PostgreSQL/Redis/runtime-fencer/ServiceMonitor/NetworkPolicy/dashboards/RBAC
  were not rolled or modified.
- Build-info note: the `utcp-application-config` ConfigMap sets `UTCP_BUILD_COMMIT=unknown`,
  `UTCP_APP_VERSION=0.1.0-dev` via envFrom, which overrides the image ENV. Per the "do not change
  their configuration" constraint the ConfigMap was left unchanged, so the deployed runtime reports
  commit `unknown`. The baked image commit `b78a0b5` is therefore not surfaced by the metric; live
  code currency is proven instead by (a) the running pod's `MetricsController.php` containing all 10
  new metric names, (b) the 10 new families being scraped (absent from the prior image), and
  (c) the new image digest on the pod.

## Live metrics-code currency
- Running pod `MetricsController.php` grep returned all 10 new metric names.
- `GET /api/version` (via gateway) → HTTP 200 `{"service":"utcp-api","version":"0.1.0-dev","commit":"unknown","built_at":"unknown"}`.
- `GET /api/metrics` → HTTP 200, `Content-Type: text/plain; version=0.0.4; charset=utf-8`,
  no exception/SQLSTATE/HTML/stack-trace in the body.

## Metrics endpoint / exposition validation
- Each of the 10 new families has exactly one `# HELP` and one `# TYPE` (no duplicate declarations).
- Sample counts at scrape: failover_events 2, failover_pending 1, failover_pending_oldest_seconds 1,
  runtime_resilience_operations 8, runtime_binding_retired 1, stale_active_bindings 1,
  participant_channel_reclaimed 1, runtime_reference_health 13 (incl. duplicate `other`), build_info 1.
- Prometheus itself parsed and ingested the exposition (authoritative live validation); `promtool` not used.

## ServiceMonitor target health / scrape stability
- Target pool `serviceMonitor/utcp-observability/utcp-application/0`, scrapeUrl
  `http://10.42.2.160:8080/api/metrics` (the `gateway` pod, `job=gateway`, endpoint `http`),
  interval 30s / timeout 10s. `health=up`, `lastError=""`, lastScrapeDuration ~0.12–0.18s.
- `changes(up{job="gateway"}[12m]) = 0` (no flapping); `count_over_time(up[12m]) = 24` successful
  scrapes (>>3 post-rollout; the scrape target is the unchanged gateway pod, so the api rollout
  never interrupted scraping). New families queryable across observed scrapes at 10:59/11:04/11:07.

## Durable-source comparison (read-only, exact MetricsController queries)
| Family / label | Durable source | Metric | Match |
|---|---|---|---|
| failover_events no_replacement | outbox conference.failover_coordinator.no_replacement = 3 | 3 | yes |
| failover_events runtime_binding_replaced | outbox conference.runtime_binding_replaced = 8 | 8 | yes |
| failover_pending pending_no_capacity | conferences.failover_state = 0 | 0 | yes |
| failover_pending_oldest_seconds | min(failover_started_at)=NULL → 0 | 0 | yes |
| resilience participant.remove/succeeded/none | 615 | 615 | yes |
| resilience participant.remove/succeeded/runtime_unavailable | 11 | 11 | yes |
| resilience participant.remove/terminal_failed/runtime_unavailable | 18 | 18 | yes |
| resilience restore/succeeded/runtime_unavailable | 5 | 5 | yes |
| resilience restore/terminal_failed/runtime_unavailable | 4 | 4 | yes |
| resilience fence/succeeded/runtime_unavailable | 9 | 9 | yes |
| resilience fence/terminal_failed/runtime_unavailable | 2 | 2 | yes |
| resilience verify_conference_absent/terminal_failed/runtime_unavailable | 12 | 12 | yes |
| runtime_binding_retired reason=conference_closed | 117 (all rows) | 117 | yes |
| stale_active_bindings | join predicate = 0 | 0 | yes |
| participant_channel_reclaimed post_closure_orphan | 1 | 1 | yes |
| orphan_participant_candidates | DB predicate = 121 | 121 | yes |
- Resilience operations count runtime_operations ROWS (logical operation objects), not attempts;
  `conference.ensure` is correctly absent from the resilience set; restore predecessor/successor rows
  are each counted (5 succeeded + 4 terminal_failed = 9 restore rows).
- `runtime_reference_health` distinct bounded classifications match durable per-group counts exactly:
  conference{degraded_unavailable=11, healthy_absent=4, healthy_present=20},
  conference_participant{degraded_unavailable=11, healthy_absent=27, healthy_present=21,
  transport_unavailable=2712}. See D1 for the `other` bucket.

## Build information
- `utcp_build_info{version="0.1.0-dev",commit="unknown"} 1` — exactly one sample, matches `/api/version`.
  No image-digest, pod-name, or build-timestamp label. Represents the API metrics-serving build only.

## Cardinality and sensitive-data proof
- All exposition labels across the 10 new families use bounded vocabularies only:
  classification{post_closure_orphan}, event_type{no_replacement,runtime_binding_replaced},
  failover_state{pending_no_capacity}, failure_class{none,runtime_unavailable},
  health{healthy_present,healthy_absent,degraded_unavailable,transport_unavailable,other},
  operation_type{4 resilience types}, reason{conference_closed}, resource_type{conference,
  conference_participant}, result{succeeded,terminal_failed}, version{0.1.0-dev}, commit{unknown}.
- Programmatic scan of all live series for the 10 families: NONE match UUID / 7+ digit id / `@` /
  password|secret|token. No tenant/conference/participant/session/binding/operation/node/channel id,
  phone number, ARI/DB credential, or raw failure message. The instance/pod/job/namespace/service
  labels are standard Prometheus SD target metadata on every series (pod name, not UID), pre-existing.
- Unmapped historical enum values appear only as bounded `other` (as designed).

## Existing metrics compatibility
- Pre-rollout inventory (job=gateway): 44 families. Post-rollout: 54. Diff: 0 baseline families
  missing/renamed/removed; exactly the 10 new T5 families added. Existing samples remain parseable;
  existing alert rules remain loaded.

## PrometheusRule deployment / alert loading
- `kubectl diff` before apply: change limited to the six new alerts + generation bump (no existing
  rule changed). Applied the single file `infrastructure/kubernetes/observability/alerts/utcp-alerts.yaml`;
  PrometheusRule `utcp-platform-alerts` generation 8. Operator reconciled; all six loaded (~42s).
- All six: group `utcp.platform`, `health=ok`, `lastError=""`.
  | Alert | for | expr (as loaded) | state |
  |---|---|---|---|
  | UTCPConferencePendingNoCapacity | 900s | sum(utcp_conference_failover_pending{failover_state="pending_no_capacity"}) > 0 | inactive |
  | UTCPRuntimeFenceTerminalFailure | 600s | sum(increase(utcp_runtime_resilience_operations_total{operation_type="runtime.node.runtime.fence",result="terminal_failed",failure_class!="none"}[10m])) > 0 | inactive |
  | UTCPRuntimeRestoreTerminalFailure | 600s | sum(increase(...operation_type="runtime.node.restore"...[10m])) > 0 | inactive |
  | UTCPStaleActiveRuntimeBindings | 600s | sum(utcp_conference_stale_active_bindings) > 0 | inactive |
  | UTCPOrphanParticipantCandidates | 900s | sum(utcp_conference_orphan_participant_candidates) > 0 | pending (1 active) |
  | UTCPAriReferenceFamilyDegraded | 600s | sum(utcp_conference_runtime_reference_health_10m{health="degraded_unavailable"}) > 3 | inactive |

## Alert expression evaluation
- Every expression executed without parse/evaluation error (lastError empty on all six).
- Pending-no-capacity, stale-binding, fence-terminal, restore-terminal, ARI-degraded: inactive.
  (Fence/restore/ARI-degraded historical rows are older than the 10m lookback → increase()=0.)
- UTCPOrphanParticipantCandidates: PENDING because `utcp_conference_orphan_participant_candidates=121`,
  which equals the exact DB candidate predicate (accumulated closed-conference participant history —
  removed participants on closed/observed-closed conferences with a retained final binding and no
  active binding). This is a truthful upper-bound signal, NOT manufactured; it does not prove any PBX
  channel is alive. No runtime state was mutated to change it. (Relates to the deferred
  conference-recovery retention/pruning gap.)

## Alert annotation boundary
- The six new rules carry only a `summary`. No artisan, manual-reconciliation, kubectl/Kubernetes,
  PBX, feature-gate, or tenant/customer text. Summaries clearly indicate automatic recovery appearing
  stuck/degraded (waiting for capacity, terminal failure during resilience recovery, active bindings
  after the automatic retirement window, orphan upper-bound needs investigation, ARI family degrading).

## Version-skew alert decision
- No `UTCPWorkerVersionSkew` rule exists (the contract's planned version-skew alert is intentionally
  deferred). No worker HTTP metrics server / `metrics`-named service port exists. `utcp_build_info`
  proves the API metrics-serving build only; worker version-skew visibility remains a deferred
  follow-up pending a reliable Prometheus source.

## Metrics security boundary
- ServiceMonitor path remains `/api/metrics`, port `http` — unchanged.
- `allow-application-metrics-from-prometheus` NetworkPolicy unchanged (created 2026-07-14): ingress to
  gateway pods port 8080 from utcp-observability/prometheus only. Scrape target reachable (up=1).
  No new broad namespace ingress rule added (the only NetworkPolicy edits were re-pinning the
  apiserver EGRESS policies to the correct live IP during recovery).
- DIVERGENCE D2 (pre-existing): `/api/metrics` is ALSO publicly reachable through Traefik. The
  `app-https`/`root-https` HTTPRoutes are catch-all `PathPrefix:/ → gateway`; the gateway nginx serves
  `location ^~ /api/` (incl. /api/metrics) unauthenticated on 8080; `allow-gateway-required-traffic`
  permits Traefik→8080. Functional test:
  `curl -k -H 'Host: app.utcp.local.test' https://127.0.0.1/api/metrics` → HTTP 200 with full metric
  content (same for `utcp.local.test`). No dedicated metrics route/Ingress exists, but the catch-all
  app route incidentally exposes the endpoint. b78a0b5 metrics carry no sensitive data (see cardinality
  proof), so no sensitive leak; nonetheless this contradicts the intended "Prometheus-only" scrape
  posture implied by the dedicated NetworkPolicy. Not introduced by b78a0b5 (routes/nginx/route-auth/
  netpols all predate it and were unchanged); not remediated here (out of scope for a docs-only commit
  and forbidden by the "do not modify routes/NetworkPolicy" constraints). Recommended follow-up: block
  `/api/metrics` on the public server block or serve metrics on an internal-only listener.

## Divergence D1 — reference-health duplicate `other` series
- `runtimeReferenceHealthMetrics()` groups `conference_recovery_metric_events` by raw `reason` and maps
  unmapped reasons to `other` in PHP. Under real data multiple raw reasons map to `other`:
  conference{ari_http_transport_failed=326, ari_http_unavailable=1, none=3543}→other (true total 3870),
  conference_participant{ari_http_transport_failed=433, ari_http_unavailable=1, none=7170}→other
  (true total 7604). The exposition therefore emits duplicate `{...,health="other"}` series; Prometheus
  keeps the FIRST (conference 326, participant 433) and silently drops the rest, so the aggregated
  `other` bucket is under-reported. The 4 mapped classifications match durable state exactly.
- This is the SAME pre-existing exposition convention already present in unchanged metrics
  (`simulator_operations_total`, `asterisk_ari_events_received_total`), tolerated by this Prometheus
  (target stayed up, no scrape error). b78a0b5 is purely additive (343 insertions, 0 deletions) so it
  introduced no regression, but the new metric inherits the limitation. It affects the durable-aggregate
  match and "distinct classification" expectation for the `other` bucket only.
- Recommended follow-up: aggregate AFTER bounding (group by the mapped health value, summing counts) so
  `other` collapses to a single series. The unit test only seeds one unmapped reason, so it does not
  catch this; the live proof did.

## Final runtime state
- RuntimeNodes: 2 active/ready (asterisk-ari); 22 disabled + 3 draft (historical), all simulator nodes
  disabled (83 disabled, 0 enabled). Asterisk deployments asterisk-ari 1/1 and asterisk-ari-b 1/1
  (asterisk-ari-events listener 1/1). Conferences: 0 with non-closed observed state, 0 pending_no_capacity.
  RuntimeBindings: 0 active. Actionable runtime_operations (pending/leased/retry_scheduled): 0.
- Non-converged reconciliation states (accumulated history, unrelated to b78a0b5): 115
  `signaling_registration` `waiting` (normal idle T1 — no live SIP registrations) + 1
  `conference_participant` `blocked`. No runtime state was mutated for the proof.

## Verification performed
- make repository-hygiene / workflow-check / secret-scan: PASS.
- make runtime-engine-config-check / telephony-domain-config-check / asterisk-ari-config-check /
  asterisk-conference-config-check: PASS.
- make runtime-engine-test (21), telephony-domain-test (60), asterisk-ari-test (94),
  asterisk-conference-test (109), asterisk-conference-recovery-test (89): PASS.
- MetricsEndpointTest (4 tests, 114 assertions): PASS (note: seeds a single unmapped reason, so it does
  not exercise the D1 duplicate-`other` case).
- git diff --check / git diff --cached --check: clean.

## Outcome
`T5_RESILIENCE_OBSERVABILITY_LIVE_PROOF_INCOMPLETE` — deployment, exposition, target health, scrape
stability, 9/10 durable-source matches, bounded labels, no sensitive data, all six alerts loaded and
evaluating cleanly, build-info consistency, existing-metrics compatibility, and ServiceMonitor/
NetworkPolicy preservation all PASS; blocked on D1 (reference-health `other` aggregate does not match
durable totals due to duplicate series) and D2 (public `/api/metrics` route). Both are pre-existing and
not b78a0b5 regressions. UTCP_PHASE remains T1; commit not pushed.

---

# T5-A60 — Contract: metrics security cutoff and actionable orphan alert (evidence-only)

Verdict: `T5_METRICS_SECURITY_AND_ORPHAN_ALERT_CONTRACT_DEFINED`. Read-only audit against
`utcp-local` running the deployed b78a0b5 API (pod `api-7684684b5b-s24sl`, image digest
`sha256:0d96195...`). No production PHP/tests/manifests/runtime/PostgreSQL/Redis/Asterisk/
Kamailio were modified. Baseline: `UTCP_PHASE=T1`, clean tree at `69a37c2`.

Confirmed at start (read-only): API image from b78a0b5 deployed; application scrape target `up`
(`lastError=""`); `/api/metrics` still returns HTTP 200 publicly on `app.utcp.local.test` and
`utcp.local.test` (https 200; http 301→https→200); `utcp_conference_orphan_participant_candidates=121`;
no live orphan participant channels (all 121 candidate participants observed_state=left with terminal
sessions; only 2 orphan-reclamation operations ever, both succeeded); `UTCPOrphanParticipantCandidates`
has now FIRED (activeAt 11:04:46Z, for=15m elapsed); `utcp_conference_stale_active_bindings=0`;
`utcp_conference_failover_pending{pending_no_capacity}=0`.

## D1 aggregation defect (settled target, re-confirmed unchanged)
Present defect: `runtimeReferenceHealthMetrics()` runs `SELECT resource_type, reason AS health,
count(*) GROUP BY resource_type, reason`, then maps each raw `reason` to a bounded `health` in PHP.
Multiple raw reasons map to `other`, so duplicate final `{resource_type,health="other"}` lines are
emitted; Prometheus keeps the first and drops the rest. Live durable grouping of
`conference_recovery_metric_events.reason`:
- conference → other = ari_http_transport_failed(326)+ari_http_unavailable(1)+none(3543) = 3870;
  Prometheus stores 326.
- conference_participant → other = 433+1+7170 = 7604; Prometheus stores 433.
The four recognized values match exactly (conference{degraded_unavailable=11,healthy_absent=4,
healthy_present=20}; conference_participant{degraded_unavailable=11,healthy_absent=27,healthy_present=21,
transport_unavailable=2712}).

## D1 exact correction
Map first, then aggregate, then emit one series per final label set:
```
rows = SELECT resource_type, reason, count(*) c GROUP BY resource_type, reason
agg = {}
for row: rt = bounded(resource_type, [conference, conference_participant])
         h  = bounded(reason, [healthy_present, healthy_absent, degraded_unavailable, transport_unavailable])
         agg[rt, h] += row.c            # sum mapped-identical label sets
ksort(agg); emit exactly one sample per (rt, h)
```
Acceptance: recognized health values unchanged; all unmapped reasons summed into one `other` total
(conference other=3870, participant other=7604); repeated scrapes stable; no raw reason becomes a label;
regression seeds >=2 distinct unmapped reasons per resource_type and asserts a single `other` sample
equal to their sum. Purely additive-safe; no other metric method touched. D1 stays ready for Codex.

## Current Prometheus scrape path (full trace)
| Hop | ns | kind/name | selector/route | port→target | path | scope | TLS | source identity |
|---|---|---|---|---|---|---|---|---|
| 1 | utcp-observability | ServiceMonitor utcp-application | matchLabels component=gateway,part-of=utcp; nsSelector utcp-platform; endpoint port `http` | — | /api/metrics (30s/10s) | cluster | — | — |
| 2 | utcp-platform | Service gateway (ClusterIP) | selector component=gateway | http 8080→http | — | cluster | no | — |
| 3 | utcp-platform | EndpointSlice gateway | — | 10.42.2.160:8080 | — | cluster | — | Prometheus scrapes pod IP directly |
| 4 | utcp-platform | gateway Pod (nginx) | listen 8080 | location ^~ /api/ → fastcgi api:9000 | /api/metrics | cluster | no | — |
| 5 | utcp-platform | Service api (ClusterIP) | selector component=api | php-fpm 9000→php-fpm | (FastCGI) | cluster | no | — |
| 6 | utcp-platform | api Pod | containerPort php-fpm 9000 (only) | php-fpm | index.php | cluster | no | — |
| 7 | — | MetricsController (Laravel routes/api.php /metrics) | — | — | /api/metrics | — | — | — |
The ServiceMonitor reports `job="gateway"` and `URL=http://<gateway-pod>:8080/api/metrics` because it
selects the `gateway` Service (component=gateway) — the only Service exposing HTTP for /api/metrics.
It cannot select the `api` Service: the api container exposes ONLY `php-fpm` (FastCGI) on 9000, which
does not speak HTTP, so Prometheus cannot scrape it directly. All HTTP metrics access is fronted by the
gateway nginx. Prometheus connects to the gateway POD IP directly (EndpointSlice), bypassing Traefik.

## Current public metrics route (why 200)
`app-https`/`root-https` HTTPRoutes (Gateway `utcp-local`/websecure) use a single rule with implicit
`PathPrefix:/` → backend `gateway:8080`. The gateway nginx serves `location ^~ /api/` (includes
/api/metrics) by FastCGI to api:9000; the Laravel `/metrics` route (routes/api.php) is unauthenticated.
Traefik `allow-gateway-required-traffic` NetworkPolicy permits Traefik→gateway:8080. Result:
`https://app.utcp.local.test/api/metrics` → 200 (proven), and `https://utcp.local.test/api/metrics` → 200.
`app-http-redirect`/`root-http-redirect` 301 http→https, so port 80 does not bypass. Public surface is
the Traefik LoadBalancer (80:30480, 443:32417 on node IPs, and k3d serverlb 127.0.0.1:80/443); no
Ingress; no direct-to-gateway NodePort. All public forms funnel through Traefik→gateway Service→nginx:8080,
so a single nginx-8080 cutoff covers every public form. NetworkPolicy alone does not help — it permits
Traefik to proxy the request.

## Internal scrape alternatives
- Direct api Service scrape (Model A candidate): NOT viable. api Service/pod expose only php-fpm:9000
  (FastCGI, no HTTP). Prometheus cannot scrape it; would require adding an HTTP listener.
- Dedicated metrics Service selecting existing gateway:8080 (Model B candidate): does NOT preserve the
  boundary. The public HTTPRoute uses the same gateway Service→pod:8080 nginx; any nginx `return 404`
  for /api/metrics on 8080 breaks the metrics Service too (same port/path/server). So B alone cannot
  separate public from Prometheus.
- Route-layer block (Model D candidate): Traefik v3.7.7 Gateway API has no core "return 404" filter and
  the repo has no reject backend; only RequestRedirect (3xx, discloses) or a new reject service would
  work — larger than a second nginx listener. (Repo does use path-scoped rules: sip-wss Exact /ws.)
Conclusion: Models A and B cannot preserve the boundary; the metrics must be fronted by nginx.

## Selected metrics security model — Model C (dedicated internal nginx metrics listener)
Smallest reliable model that keeps /api/metrics cluster-internal using the repository-standard nginx
`return 404` mechanism (already used for dotfiles), preserves Prometheus scraping, adds no public auth,
no feature gate, no source-IP allowlist, no scrape token, and no second metrics authority (same
MetricsController via FastCGI).
- `infrastructure/docker/gateway/nginx.conf`: add a second internal server block
  `server { listen 8081; server_name _; location = /api/metrics { <same fastcgi_params + fastcgi_pass
  api:9000>; } location / { return 404; } }`, AND on the public `server{listen 8080}` add
  `location = /api/metrics { return 404; }` (exact match wins over `^~ /api/`, non-disclosing 404).
- `infrastructure/kubernetes/base/platform/gateway-deployment.yaml`: add containerPort 8081 name `metrics`.
- `infrastructure/kubernetes/base/platform/gateway-service.yaml`: add port `metrics` 8081→8081.

## Public runtime-authority cutoff
Exact change: the public 8080 server returns the repo-standard `404` for `/api/metrics`
(`location = /api/metrics { return 404; }`) so the public host no longer proxies the path to
MetricsController. Covers HTTP (via 301→HTTPS→same nginx) and HTTPS, both `app.utcp.local.test` and
`utcp.local.test` (all funnel to nginx:8080). Unrelated `/api/*` routes (`/api/version`,
`/api/health/live`, etc.) stay on `location ^~ /api/` and are unchanged. HTTPRoutes are NOT changed
(Gateway API cannot 404 natively without a reject backend); the cutoff is at the nginx authority.

## ServiceMonitor contract
`infrastructure/kubernetes/observability/servicemonitors/…` `utcp-application`: change the single
endpoint `port: http` → `port: metrics` (8081); keep path `/api/metrics`, interval 30s, timeout 10s,
selector, and namespaceSelector. Prometheus then scrapes gateway pod:8081 (internal-only) instead of
:8080. No new ServiceMonitor.

## NetworkPolicy contract
`allow-application-metrics-from-prometheus` (utcp-platform): change the ingress port 8080 → 8081; keep
from = utcp-observability/prometheus only, keep podSelector utcp.io/network-role=gateway. Result:
Prometheus→gateway:8081 allowed; the public 8080 route reaches nginx but nginx 404s /api/metrics (route
does not reach MetricsController). `allow-gateway-required-traffic` (Traefik→8080) unchanged so normal
API ingress is preserved. No namespace-wide broadening; no path filtering in NetworkPolicy (correctly,
NP is not an HTTP-path authority — the path authority is nginx). NetworkPolicy change IS required (the
scrape port moves 8080→8081).

## Security cutover rollout order (no scrape outage)
1. Deploy corrected API image (D1 aggregation fix + new orphan-reclamation-ops metric; candidate gauge
   retained, non-alertable). Confirm /api/metrics still served on 8080.
2. Deploy gateway with the ADDED internal 8081 metrics listener only (do NOT yet add the 8080 404).
   8081 serves metrics; 8080 still serves metrics.
3. Repoint ServiceMonitor port→metrics(8081) and NetworkPolicy port→8081. Confirm target `up` on 8081,
   >=3 scrapes.
4. Deploy the gateway public cutoff (`location = /api/metrics { return 404; }` on 8080). Public now
   blocked; Prometheus unaffected (8081). Confirm public 404 + target still up.
5. Apply corrected PrometheusRule (replace UTCPOrphanParticipantCandidates with the actionable
   orphan-reclamation alert). Confirm all expressions evaluate.
Steps 2→4 split ensures the internal target is healthy before the public authority is removed.

## Public and internal acceptance tests (D2)
- Public HTTPS `app.utcp.local.test` and `utcp.local.test` GET /api/metrics → 404 (non-disclosing).
- Public HTTP (port 80) → 301 → HTTPS → 404 (no bypass).
- Alternate Host header / NodePort (30480/32417) → still 404 (all funnel to nginx:8080).
- `/api/version`, `/api/health/live`, and other `/api/*` app routes remain 200/302 as before.
- Prometheus target `up`, `lastError=""`, >=3 successful scrapes on the internal port.
- ServiceMonitor selects only the intended internal target (port metrics/8081).
- NetworkPolicy permits Prometheus→8081 and does not expose the metrics target publicly.
- No Ingress/Gateway route forwards metrics to MetricsController.
- No metrics token, feature gate, or IP allowlist introduced.
- Expected public status code: 404 (repository-standard non-disclosing response).

## Orphan candidate predicate
Shared by `TelephonyDomainService::reclaimOrphanParticipantChannels()` and the metric
`orphanParticipantCandidateMetrics()`:
```
conference_participants.desired_state = 'removed'
AND conferences.desired_state = 'closed' AND conferences.observed_state = 'closed'
AND NOT EXISTS active binding for (tenant, conference)
AND EXISTS retired binding with runtime_node_id NOT NULL for (tenant, conference)
```
The predicate is purely static closed-conference history. It has NO exclusion for participants already
inspected-absent, already reclaimed (channel_reclaimed event), or otherwise conclusively handled, so a
matching participant remains a candidate FOREVER — it is a monotonic-style upper bound, not a live count.
The scrape performs only these SQL reads; it never calls ARI/Asterisk. The reclaim sweep (not the scrape)
is the only path that performs ARI inspection and acts only when a channel is still observed present.

## Current candidate classification (121 rows, read-only)
- Participant: 121/121 desired_state=removed, observed_state=left.
- Conference: 121/121 desired_state=closed, observed_state=closed; no active binding; retired
  node-bound binding present.
- Session: 117 expired, 4 ended (all terminal; no live sessions).
- Reclamation event (`conference_participant.channel_reclaimed`): 1 candidate has one; 120 do not.
- Orphan-reclamation operations (`runtime_operations` operation_type=conference.participant.remove AND
  payload.orphan_reclamation=true): 2 total, both `succeeded`/failure_class none; 0 actionable
  (pending/leased/retry_scheduled/terminal_failed/expired). Total participant.remove ops = 644.
These rows remain candidates after successful/unnecessary cleanup because the predicate never records
"handled" — closed conferences with removed participants and a retained node-bound retired binding match
permanently. No PBX channel presence is implied (participants already observed left; sessions terminal).

## Candidate gauge disposition — retain as inventory upper bound (non-alertable)
Keep `utcp_conference_orphan_participant_candidates` as a database-derived upper-bound inventory gauge
(HELP already states "does not prove a PBX channel is currently alive"; retain that caveat). Do NOT
refine via a new DB column (existing durable evidence — the orphan-reclamation operation objects — is
sufficient for alerting, see below), and do NOT remove it (useful backlog/inventory visibility). The
metric must never call ARI/Asterisk (it does not).

## Current alert defect classification — C
"Metric is valid inventory, alert is invalid." The gauge correctly reports the DB upper bound (121), but
`UTCPOrphanParticipantCandidates: sum(...) > 0` alerts on a value guaranteed positive in a healthy
environment (accumulated closed-conference history), so it fires with zero live orphans and zero stuck
automation. Confirmed against durable data (0 actionable orphan-reclamation operations).

## Actionable orphan alert source — Model A (tagged reclamation operations)
Durable source: `runtime_operations` where `operation_type = 'conference.participant.remove'` AND
`payload->>'orphan_reclamation' = 'true'`, grouped by bounded `status` (OperationStatus) and
`last_failure_class` (FailureClass + none). This marker is set by
`ConferenceParticipantReconciler` (orphan_reclamation=true) and consumed by `AsteriskRuntimeAdapter`,
which records the reclaim event on success — a durable, deduplicated, restart-safe signal that isolates
orphan reclamation from the 642 normal participant removals. New dedicated metric (the existing
`utcp_runtime_resilience_operations_total` conflates orphan with normal participant.remove):
`utcp_conference_orphan_reclamation_operations_total{result,failure_class}` (counter). Successful/absent
rows do not remain "actionable"; retry/terminal rows indicate stuck automation. Healthy value today:
`{result="succeeded",failure_class="none"} 2`, terminal_failed = 0. Bounded labels only (result,
failure_class); no participant/conference/operation/binding/node/channel/session id. Max series bounded
(~status × failure_class).

## Corrected alert contract
Replace `UTCPOrphanParticipantCandidates` (delete) with:
- Alert: `UTCPOrphanReclamationTerminalFailure`
- expr: `sum(increase(utcp_conference_orphan_reclamation_operations_total{result="terminal_failed",failure_class!="none"}[10m])) > 0`
- for: 10m; severity: warning; component: telephony-domain; namespace: utcp-platform
- summary: "Automatic orphan participant channel reclamation reached terminal failure during recent recovery."
- description: automatic orphan-channel reclamation is terminally failing; the control plane's automatic
  reconciliation is not clearing observed post-closure orphan channels.
- Automatic condition considered stuck: a tagged orphan-reclamation `conference.participant.remove`
  operation reached `terminal_failed` within the lookback (mirrors UTCPRuntimeFence/RestoreTerminalFailure).
- Operator response: observe automatic recovery; the alert signals automation is failing — NO artisan,
  manual reconciliation, PBX channel deletion, PostgreSQL/Redis edits, manual Kubernetes scaling, or
  feature gate. Expected healthy value: 0.
(Optional future extension, not required: a gauge of currently-overdue actionable orphan-reclamation
operations for "retrying/overdue" — deferred; terminal-failure is the reliable primary signal.)

## Cardinality and security
D1 fix, orphan metric, and alert use only bounded enum labels (resource_type, health, result,
failure_class, classification, version, commit). No tenant/conference/participant/session/binding/
operation/RuntimeNode/channel id, phone number, credential, or raw failure message becomes a label.
No environment gate, no IP allowlist, no scrape token in any proposed change (grep-verified against the
proposal). Manual-recovery wording excluded from the alert annotation.

## Test matrix (for the future implementation)
- Healthy historical candidate inventory (gauge > 0) does NOT fire the actionable alert.
- Retrying orphan-reclamation operation → new metric shows result=retry_scheduled (represented, not
  alerted by the terminal-failure rule).
- Terminal-failed orphan op → metric result=terminal_failed; alert fires after `for`.
- Successful orphan op → does not remain alerting.
- Repeated scheduler sweeps do not inflate a transition counter (metric counts durable operation ROWS,
  idempotent per operation; reclaim event is deduped per participant).
- Unknown failure classes map to `other`.
- No participant/Conference/operation/binding/RuntimeNode/channel id appears as a label.
- Alert expression references the implemented `utcp_conference_orphan_reclamation_operations_total`.
- Alert annotation preserves automatic-recovery authority (no manual controls).
- Candidate gauge HELP retains the upper-bound caveat.
- D1: multiple raw reasons → single `other` sample equal to their sum; repeated scrapes stable.
Do NOT manufacture a live PBX failure during the later acceptance proof.

## Missing implementation (this audit changed no code)
D1 aggregation fix; new orphan-reclamation-ops metric; gateway nginx dual-listener + public 404;
gateway Deployment/Service 8081 port; ServiceMonitor port→metrics; NetworkPolicy port→8081; PrometheusRule
alert replacement. All specified above; none applied (evidence-only).

## Implementation-readiness decision — A (bounded Codex implementation)
The audit establishes the exact D1 correction, exact public cutoff, exact internal scrape target,
required ServiceMonitor and NetworkPolicy changes, rollout order, exact orphan gauge disposition, the
exact actionable alert source and contract, and tests + live acceptance criteria. Ready for a bounded
Codex implementation prompt (below).

## Ready-to-paste next prompt (Codex — bounded implementation)
```
T5-A61 — Implement metrics security cutoff, D1 aggregation fix, and actionable orphan alert

Repo state: HEAD 69a37c2, branch main, working tree clean, UTCP_PHASE=T1. Deployed API: b78a0b5.
Implement exactly the contract in docs/evidence/t2/multi-node-failover-readiness.md (T5-A60).
Do not begin V0. Keep UTCP_PHASE=T1. Do not push.

1. D1 — apps/api/app/Http/Controllers/Platform/MetricsController.php, runtimeReferenceHealthMetrics():
   query resource_type,reason,count group by resource_type,reason; in PHP map resource_type→
   [conference,conference_participant] and reason→[healthy_present,healthy_absent,degraded_unavailable,
   transport_unavailable] (else other); aggregate counts by (mapped resource_type, mapped health);
   ksort; emit exactly one sample per final label set. No raw reason as a label.

2. New metric — MetricsController: add utcp_conference_orphan_reclamation_operations_total (counter,
   HELP+TYPE, "Maximum series" note) from runtime_operations where operation_type='conference.participant.remove'
   AND (payload->>'orphan_reclamation')='true', group by status, coalesce(last_failure_class,'none');
   bound result to OperationStatus and failure_class to FailureClass+none; zero/none placeholder when the
   table is absent, matching existing convention. Keep utcp_conference_orphan_participant_candidates as-is
   (retain upper-bound HELP caveat; no ARI/Asterisk I/O).

3. Gateway nginx — infrastructure/docker/gateway/nginx.conf: on server{listen 8080} add
   `location = /api/metrics { return 404; }` (before location ^~ /api/). Add server{listen 8081; listen
   [::]:8081; server_name _; server_tokens off; access_log ...; location = /api/metrics { <same
   fastcgi_params, SCRIPT_FILENAME/NAME/DOCUMENT_ROOT, fastcgi_pass api:9000> } location / { return 404; } }.

4. Gateway workload — base/platform/gateway-deployment.yaml add containerPort 8081 name metrics;
   base/platform/gateway-service.yaml add port name metrics 8081 targetPort metrics.

5. ServiceMonitor — observability utcp-application: endpoint port http→metrics (keep path /api/metrics,
   30s/10s, selectors).

6. NetworkPolicy — security/platform allow-application-metrics-from-prometheus: ingress port 8080→8081
   (keep from utcp-observability/prometheus only, keep gateway podSelector). Leave allow-gateway-required-traffic.

7. PrometheusRule — observability/alerts/utcp-alerts.yaml: delete UTCPOrphanParticipantCandidates; add
   UTCPOrphanReclamationTerminalFailure: expr sum(increase(utcp_conference_orphan_reclamation_operations_total
   {result="terminal_failed",failure_class!="none"}[10m])) > 0; for 10m; severity warning; component
   telephony-domain; namespace utcp-platform; summary "Automatic orphan participant channel reclamation
   reached terminal failure during recent recovery." No manual-recovery wording.

8. Tests — extend apps/api/tests/Feature/Platform/MetricsEndpointTest.php: (a) D1 multiple unmapped reasons
   per resource_type → single other sample = sum; (b) orphan-reclamation metric result/failure_class from
   tagged operations, unknown failure_class→other, no id labels; (c) alert-yaml fixture asserts the new
   alert references the implemented metric, no UTCPOrphanParticipantCandidates, no artisan/manual wording.

Verify: make repository-hygiene workflow-check secret-scan; make runtime-engine-config-check
telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check; make
runtime-engine-test telephony-domain-test asterisk-ari-test asterisk-conference-test
asterisk-conference-recovery-test; git diff --check. Commit
feat(t5): scope metrics to internal scrape and add actionable orphan alert. Do not push.
Then hand to Claude Code for the T5-A62 live cutover proof (rollout order in T5-A60 §Security cutover).
```

## Verification performed (T5-A60)
Read-only: metrics public-route inventory (https app/root 200, http 301, NodePort/LB enumerated, no
Ingress); Gateway/HTTPRoute precedence trace; ServiceMonitor selector trace; api/gateway Service + port
inventory; EndpointSlice (gateway pod-direct 10.42.2.160:8080); metrics NetworkPolicy trace; public
hostname access proof (200 now, will be 404 post-cutoff); internal scrape-alternative proof (api=FastCGI-only);
D1 mapped-aggregation trace with live durable reason grouping; orphan candidate classification (121);
orphan-reclamation operation payload inventory (2 succeeded, 0 actionable); reclamation-event inventory
(1); alert actionability analysis (defect C); cardinality/sensitive-data scan; env-gate/allowlist scan;

---

# T5-A61 — Repository implementation: corrected metrics aggregation, internal scrape path, and orphan alert replacement

Repository-only implementation started from `b64791d` on `main` at `UTCP_PHASE=T1`.
No live Kubernetes resources, runtime bindings, conferences, PBX resources, PostgreSQL rows, or Redis
state were mutated.

## Implemented corrections

- D1: `MetricsController::runtimeReferenceHealthMetrics()` now reads durable
  `conference_recovery_metric_events` counts, maps `resource_type` to the bounded
  `conference|conference_participant|other` vocabulary and `reason` to the bounded
  `healthy_present|healthy_absent|degraded_unavailable|transport_unavailable|other` vocabulary, sums by
  the final label pair, sorts deterministically, and emits one sample per final pair. Unknown raw reasons
  now collapse into the same `health="other"` bucket for the mapped resource type; no raw reason is
  exposed as a label.
- New metric:
  `utcp_conference_orphan_reclamation_operations_total{result,failure_class}`. It is sourced from
  durable `runtime_operations` rows where the canonical participant-remove operation payload has
  `orphan_reclamation=true`. One row is one counted operation object; `attempt_count` is not counted
  separately. `result` uses the existing operation-status vocabulary and unexpected values map to
  `other`; `failure_class` uses `none`, the existing failure-class vocabulary, and `other`.
- `utcp_conference_orphan_participant_candidates` remains a database-derived upper-bound inventory gauge.
  Its predicate was not narrowed and the scrape still performs only database reads. It is not treated as
  proof that a PBX channel currently exists.
- `UTCPOrphanParticipantCandidates` was removed from the repository PrometheusRule.
- `UTCPOrphanReclamationTerminalFailure` was added with:
  `sum(increase(utcp_conference_orphan_reclamation_operations_total{result="terminal_failed",failure_class!="none"}[10m])) > 0`,
  `for: 10m`, and `severity: warning`. Its annotations refer to automatic orphan participant-channel
  reclamation reaching terminal failure and do not instruct manual Artisan, PBX, PostgreSQL, Redis,
  Kubernetes scaling, gate, allowlist, or channel-deletion actions.

## Scrape-path repository state

- The canonical application metrics authority remains
  `GET /api/metrics -> MetricsController -> durable PostgreSQL aggregation on scrape`.
- `infrastructure/docker/gateway/nginx.conf` now has a dedicated internal transport listener on
  `8081`. Only exact `location = /api/metrics` is forwarded to the existing FastCGI/API path; unrelated
  paths on that listener return `404`.
- The public `8080` server now has exact `location = /api/metrics { return 404; }` before the general
  `location ^~ /api/` FastCGI route. Public `/api/version`, health, and non-metrics API paths remain on
  the existing general API route.
- The gateway Deployment exposes named container port `metrics:8081`; the gateway Service exposes
  ClusterIP port `metrics:8081 -> targetPort: metrics` while preserving `http:8080`.
- The application ServiceMonitor now scrapes `port: metrics` with path `/api/metrics`; selector,
  namespace selector, interval, and timeout were preserved.
- The Prometheus-only application metrics NetworkPolicy ingress and matching Prometheus egress rules now
  use port `8081` with the same namespace and pod selectors. The Traefik-to-gateway `8080` policy was not
  changed.
- HTTPRoute resources were not modified and no route targets port `8081`.

## Test coverage added

- D1 regression seeds multiple raw reasons (`ari_http_transport_failed`, `ari_http_unavailable`, `none`,
  and another unknown value) that map to `other`, asserts exactly one final `health="other"` sample per
  mapped resource type, verifies summed values, preserves recognized health classifications, rejects raw
  reason labels, and confirms repeated scrapes are stable.
- Orphan-reclamation metric regression seeds two successful tagged reclamation operation rows, a normal
  participant removal without the marker, retrying and terminal-failed tagged operations, nonzero
  `attempt_count`, missing and unknown failures, and payload UUIDs. It verifies row-count semantics,
  `none`/`other` failure mapping, normal-operation exclusion, repeated-scrape stability, and absence of
  tenant, conference, participant, session, binding, operation, runtime-node, channel, or payload labels.
- PrometheusRule fixture assertions prove the orphan-candidate alert is absent, the replacement alert is
  present once, the expression references the implemented metric and 10-minute lookback, duration and
  severity match the contract, candidate gauge is not alert-referenced, and manual recovery wording is
  absent.
- Gateway and manifest fixture assertions prove the public exact cutoff, internal exact metrics-only
  listener, unrelated-path 404 behavior, public API FastCGI route preservation, Deployment/Service
  metrics ports, ServiceMonitor named-port cutover, metrics NetworkPolicy port cutover, unchanged
  Traefik-to-8080 policy, and absence of HTTPRoute metrics exposure.

## Live rollout status

Staged live rollout remains pending. This repository slice does not claim that public metrics access is
already blocked in `utcp-local`, that Prometheus is already scraping `8081`, or that the corrected
PrometheusRule is already loaded by the cluster.

## Repository verification

- `make repository-hygiene`, `make workflow-check`, and `make secret-scan` passed.
- `make runtime-engine-config-check`, `make telephony-domain-config-check`,
  `make asterisk-ari-config-check`, and `make asterisk-conference-config-check` passed.
- `make runtime-engine-test`, `make telephony-domain-test`, `make asterisk-ari-test`,
  `make asterisk-conference-test`, and `make asterisk-conference-recovery-test` passed.
- `php artisan test tests/Feature/Platform/MetricsEndpointTest.php` passed and covers the D1
  duplicate-series regression, orphan-reclamation operation metric, gateway metrics transport,
  Kubernetes manifest cutover, alert replacement, sensitive-label scan, and manual-recovery wording scan.
- `make test`, `make check`, and `make build` passed.
- Phase-marker inspection confirmed `versions.env` declares `UTCP_PHASE=T1`.
- No mutating command was run against Kubernetes, PBX/runtime resources, conferences, RuntimeBindings,
  PostgreSQL business rows, Redis, or live ServiceMonitor/NetworkPolicy state.

---

# T5-A62 — Live proof: internal metrics scraping, public cutoff, D1 aggregation, actionable orphan alert (e87d1c7)

Verdict: `T5_METRICS_SECURITY_AND_ORPHAN_ALERT_LIVE_PROOF_COMPLETE`. Staged live cutover of the T5-A61
implementation against `utcp-local`. Baseline `UTCP_PHASE=T1`, clean tree at `e87d1c7`. Evidence-only
doc change; production code/manifests are the committed e87d1c7, not modified here.

## Environment recovery before cutover (infrastructure only)
Post-host-restart the apiserver moved to 172.24.0.5 while the apiserver-egress NetworkPolicies were
pinned to 172.24.0.2, crash-looping the Prometheus operator, kube-state-metrics, and grafana sidecar
(operator: `10.43.0.1:443 connection refused`). Recovered canonically via
`scripts/security/render-apiserver-policy` + apply of the three rendered `.runtime/...` egress files;
drift check then passed `endpoint=172.24.0.5/32:6443`; restarted the three crash-looped pods. No policy
weakened; the metrics NetworkPolicy under test was not touched by recovery. `.runtime/` is gitignored.

## Pre-cutover baseline (live)
API image digest sha256:0d96195... (b78a0b5, rev 79); gateway rev 11 (http:8080 only); ServiceMonitor
port `http`; metrics NetworkPolicy port 8080; target up on http://<gw>:8080/api/metrics; ARI other
duplicate-collapsed (conference 326, participant 7604→433); orphan gauge 121; `UTCPOrphanParticipantCandidates`
loaded; public `https://app.utcp.local.test/api/metrics` → 200. Wider runtime healthy: 2 asterisk nodes
active/ready, both Asterisk Deployments 1/1, 0 open/pending conferences, 0 active bindings, 0 actionable
ops, all simulators disabled.

## Corrected image build
Built canonical api and gateway images from the clean e87d1c7 tree. Verified in the api image:
utcp_conference_orphan_reclamation_operations_total (name+method), `payload->orphan_reclamation` filter,
D1 map-then-aggregate helper. Verified in the gateway image nginx: public `location = /api/metrics {
return 404 }`, internal `listen 8081` server, 8081 `location / { return 404 }`. Pushed via the local
registry: api sha256:505239983832dda184e674e540cfdab6e924afb462bca0802bd7557ccffa348a,
gateway sha256:1591593a614cefe788d34415bb980c3f5eef028d0d661d5a986505c82415ad68.

## Stage 1 — API rollout (8080 path preserved), D1 + orphan metric
Rolled out only `deployment/api` → pod api-57648d76c5-z57kg, rev 80, digest sha256:505239...
Through the still-8080 scrape path: `/api/metrics` HTTP 200, no exceptions, new orphan metric present,
and utcp_conference_runtime_reference_health_10m emits exactly one series per resource_type×health
(no duplicate label sets). Prometheus ingested the corrected aggregation while still on 8080.

## D1 corrected aggregation (durable comparison)
Live == durable, mapped and summed, one series per final label pair:
| resource_type | health | live | durable |
|---|---|---|---|
| conference | healthy_present | 20 | 20 |
| conference | healthy_absent | 4 | 4 |
| conference | degraded_unavailable | 11 | 11 |
| conference | other | 3870 | 3870 (ari_http_transport_failed 326 + ari_http_unavailable 1 + none 3543) |
| conference_participant | healthy_present | 21 | 21 |
| conference_participant | healthy_absent | 27 | 27 |
| conference_participant | degraded_unavailable | 11 | 11 |
| conference_participant | transport_unavailable | 2712 | 2712 |
| conference_participant | other | 7604 | 7604 (433 + 1 + 7170) |
Prometheus `count(...{health="other"})` = 2 (one per resource_type); no duplicate-sample warning;
recognized classifications unchanged. Family went 13→9 series (4 duplicate `other` lines collapsed).

## Orphan-reclamation operation metric (durable comparison)
`utcp_conference_orphan_reclamation_operations_total{result="succeeded",failure_class="none"} 2`.
Durable: runtime_operations where operation_type=conference.participant.remove AND
payload->>'orphan_reclamation'='true' → 2 tagged, both succeeded/none; 0 terminal_failed. Total
participant.remove ops = 644, so the 642 normal removals are correctly excluded. One operation row = one
count (attempts not multiplied); missing failure → `none`; bounded result/failure_class labels only.

## Stage 2 — gateway dual-listener + cutover
Applied gateway Service+Deployment (added metrics:8081) and rolled out the new gateway image →
gateway-bdc69cd5b-b4qtx, rev 13, digest sha256:1591593..., Service ports http:8080 + metrics:8081.
Internal listener proof (from gateway pod):
- 8081 /api/metrics → 200; /api/version → 404; / → 404; /arbitrary → 404 (metrics-only).
- 8080 /api/metrics → 404; /api/version → 200 (public cutoff live, normal API preserved).
8081 is ClusterIP-only: no NodePort, no LoadBalancer, no HTTPRoute→8081 (only the Traefik LB exposes
80/443→8080).
Then applied the metrics NetworkPolicy (ingress utcp-platform + egress utcp-observability, 8080→8081,
Prometheus-only) and the ServiceMonitor (port http→metrics, path /api/metrics preserved). Target
transitioned to http://10.42.1.81:8081/api/metrics, endpoint=metrics, job=gateway, health=up,
lastError="", interval 30s/timeout 10s, scrape duration ~0.22–0.26s. Cutover window: the operator took
~60–70s to regenerate the scrape config (target briefly showed `down` 404 on 8080 while nginx already
served 8081); this is the operator config-reload latency, not a sustained outage — scraping resumed on
8081 immediately after reload. 10 successful scrapes over 15m on 8081 with 0 flaps.

## Public metrics authority cutoff (all forms)
`/api/metrics` returns 404 on every public form:
- https app.utcp.local.test → 404; https utcp.local.test → 404.
- http (both hosts) → 301 → https → 404.
- Traefik NodePort https (:32417) via agent node IPs 172.24.0.3/.4 → 404 (server node has no svclb pod).
Normal public API unchanged: /api/version 200, /api/health/live 200, /api/health/ready 200 (both hosts).
No NetworkPolicy weakened; no auth exception, token, feature gate, or IP allowlist added.

## Internal metrics-only listener
From an authorized cluster context, port 8081: /api/metrics → 200; /api/version → 404; /api/health/live
→ 404; / → 404; /arbitrary → 404. No server_name or public route exposes the listener; no extra
application route/controller added.

## ServiceMonitor and NetworkPolicy cutover (live)
ServiceMonitor utcp-application endpoint port=metrics, path=/api/metrics (selector/nsSelector/interval/
timeout unchanged). allow-application-metrics-from-prometheus ingress port 8081 from utcp-observability/
prometheus only; allow-prometheus-egress-to-application-metrics egress port 8081. allow-gateway-required-
traffic (Traefik→8080) unchanged. Prometheus scrapes only named port `metrics`.

## Candidate gauge inventory boundary
utcp_conference_orphan_participant_candidates = 121 (queryable), == DB predicate. Documented as a
database-derived historical upper bound that does NOT prove live PBX channels (HELP retains the caveat).
No alert rule references this metric (verified: `UTCPOrphanParticipantCandidates` absent).

## Alerts — swap, loading, evaluation, false-positive elimination
PrometheusRule generation 9. `UTCPOrphanParticipantCandidates` ABSENT. `UTCPOrphanReclamationTerminalFailure`
present exactly once. All six T5 alerts loaded, health=ok, lastError="":
| alert | for | state | expr |
|---|---|---|---|
| UTCPConferencePendingNoCapacity | 900s | inactive | sum(utcp_conference_failover_pending{failover_state="pending_no_capacity"}) > 0 |
| UTCPRuntimeFenceTerminalFailure | 600s | inactive | sum(increase(utcp_runtime_resilience_operations_total{operation_type="runtime.node.runtime.fence",result="terminal_failed",failure_class!="none"}[10m])) > 0 |
| UTCPRuntimeRestoreTerminalFailure | 600s | inactive | sum(increase(...operation_type="runtime.node.restore"...[10m])) > 0 |
| UTCPStaleActiveRuntimeBindings | 600s | inactive | sum(utcp_conference_stale_active_bindings) > 0 |
| UTCPOrphanReclamationTerminalFailure | 600s | inactive | sum(increase(utcp_conference_orphan_reclamation_operations_total{result="terminal_failed",failure_class!="none"}[10m])) > 0 |
| UTCPAriReferenceFamilyDegraded | 600s | inactive | sum(utcp_conference_runtime_reference_health_10m{health="degraded_unavailable"}) > 3 |
New alert contract confirmed: references only utcp_conference_orphan_reclamation_operations_total; result
filter terminal_failed; failure_class!="none"; lookback 10m; for 10m; severity warning; component
telephony-domain; annotations summary+description with no artisan/manual/PBX/tenant wording. Direct
PromQL of the alert expr → 0 result series. False-positive elimination (D3 core): candidate gauge = 121
(positive) yet `ALERTS{alertname=~"UTCPOrphan.*"}` = NONE and no pending/firing orphan alert. No terminal
failure was manufactured. 40 rules loaded total, 0 with eval error.

## Cardinality and sensitive-data proof
Live series for utcp_conference_runtime_reference_health_10m, utcp_conference_orphan_reclamation_operations_total,
utcp_conference_orphan_participant_candidates: metric-emitted labels are bounded enums only
(failure_class, health, resource_type, result). Standard SD target metadata (instance/pod/container/job/
endpoint/service/namespace) present as on every series (pod name, not UID). Scan for UUID / 7+ digit id /
`@` / password|secret|token → NONE. No tenant/conference/participant/session/binding/operation/node/
channel id, phone number, credential, or raw error.

## Existing metrics compatibility
Family inventory: 54 (A59) → 55; 0 removed/renamed; exactly 1 added
(utcp_conference_orphan_reclamation_operations_total). All prior T5 families queryable; existing five T5
alerts remain healthy. Gateway+api 1/1; /api/version, /api/health/live, /api/health/ready all 200; no
API-routing regression in probes.

## Metrics security boundary
ServiceMonitor scrapes only named port metrics; metrics NetworkPolicy permits Prometheus→8081 only and
does not broadly expose 8081; Traefik uses 8080 only; no HTTPRoute targets metrics; no NodePort/LB
exposes 8081; public /api/metrics → 404; internal scrape up; no token/gate/allowlist/public fallback.

## Final runtime state
Prometheus target up on 8081; 10 stable scrapes / 0 flaps over 15m; public exact /api/metrics 404;
ordinary API healthy; corrected ARI aggregates match durable; candidate gauge inventory-only (121);
terminal orphan alert loaded+healthy+inactive; invalid candidate alert absent; 0 rule eval errors.
Wider runtime unchanged: 2 asterisk nodes active/ready, both Asterisk Deployments 1/1, 0 open/pending
conferences, 0 active bindings, 0 actionable ops, all simulators disabled. No telephony state mutated.

## Verification performed (T5-A62)
make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS.
make *-test: runtime-engine 21, telephony-domain 60, asterisk-ari 94, asterisk-conference 109,
asterisk-conference-recovery 89: PASS. MetricsEndpointTest 7 passed (180 assertions), including the new
D1-aggregation, orphan-reclamation-metric, and gateway-internalization tests. git diff --check /
--cached: clean. No forbidden mutating command run (no failover/fencing/ARI-unload/Conference/
RuntimeBinding/PBX/SQL-write/migrate/public-fallback/NetworkPolicy-weakening).

## Outcome
`T5_METRICS_SECURITY_AND_ORPHAN_ALERT_LIVE_PROOF_COMPLETE`. All 28 completion criteria met: e87d1c7
metrics code deployed; gateway 8081 live and metrics-only; ServiceMonitor on named port metrics; metrics
NetworkPolicy Prometheus→8081; ≥3 internal scrapes; public /api/metrics 404 on every form; ordinary API
functional; ARI one-sample-per-final-label-pair with `other` summed and no duplicate-sample issue;
orphan-reclamation metric matches durable ops with normal removals excluded; candidate gauge visible as
inventory and unreferenced by any alert; invalid candidate alert absent; replacement terminal-failure
alert loaded once and evaluating; no healthy inventory causes an orphan alert; bounded non-sensitive
labels; existing families compatible; existing T5 alerts healthy; no public route exposes 8081; no token/
allowlist/gate/public fallback; wider runtime healthy. UTCP_PHASE remains T1; commit not pushed.

---

# T5-A63 — Contract: events-only listener liveness authority (evidence-only)

Verdict: `T5_EVENTS_ONLY_LISTENER_LIVENESS_CONTRACT_DEFINED`. Read-only audit against `utcp-local`
(listener pod `asterisk-ari-events-5798745d5b-qm82r`). Baseline `UTCP_PHASE=T1`, clean tree at `fa0d8d3`.
No production PHP/tests/manifests/leases/epochs/PostgreSQL/Redis/Asterisk/Kamailio modified.

Baseline confirmed: 2 asterisk-ari RuntimeNodes active (`1d15ca88`, `05ddb383`), both observed `ready`;
asterisk-ari-events 1/1, asterisk-ari 1/1, asterisk-ari-b 1/1; both listener leases claimed by one
worker `...qm82r:asterisk-ari-events:1` (exp ~45s out); 0 open conferences (117 all observed=closed),
0 pending_no_capacity, 0 active bindings, 0 actionable operations, all simulators disabled.

## Listener implementation
`App\RuntimeAdapters\Asterisk\AsteriskAriEventListener::workOnce()` is the single-threaded event pump,
driven by the `asterisk-ari-events` console loop (`gethostname():asterisk-ari-events:getmypid()` as
owner/worker_id). Per cycle, for each node in the in-memory `$connections`: (1) renew lease
(`RuntimeListenerLeaseRepository::renew`), (2) every `heartbeat_interval_ms` run a REST health check
(`AsteriskAriClient::inspect` + `stasisApplicationRegistered`) and ingest a `runtime_info_observed`
receipt, (3) drain up to `max_events_per_cycle` WebSocket frames via non-blocking `readEvent`. Newly
eligible nodes are claimed and `openConnection()` opens a raw `stream_socket_client` WebSocket
(hand-rolled HTTP/1.1 Upgrade), sets `stream_set_blocking(false)`, closes stale epochs, opens a new
event epoch, and ingests `connection_opened`+`runtime_info_observed`+conference reconnect inspections.
Receipts flow through the normalizer → `ProjectionService` → `runtime_nodes.observed_state`/`observed_at`.

## Listener process versus stream liveness (the conflations)
| Concept | Current authority | Proves stream alive? |
|---|---|---|
| process alive | K8s livenessProbe `php artisan about` | No |
| Pod Ready | K8s readinessProbe `php artisan about` | No |
| lease claimed | `runtime_listener_leases` single-owner mutex, renewed every cycle | No |
| WebSocket TCP connected | only at `openWebSocket` handshake (HTTP 101) | Only at open |
| ARI authenticated | only at handshake (Basic auth → 101) | Only at open |
| event stream subscribed | REST `stasisApplicationRegistered` every 15s | Indirect/out-of-band |
| event frame received | `readEvent` return; NOT timestamped anywhere | No durable trace |
| event epoch current | `runtime_event_connection_epochs.status='open'` (binary) | No — see below |
| RuntimeNode ready | last projected receipt `observed_state` + `observed_at` | No — REST-fed |
Conflations currently treated as equivalent though they are not:
1. **lease renewal ≡ stream liveness** — FALSE. `renew()` is a pure DB update keyed on
   `(lease_id, owner, fencing_token, status='claimed')`; it has zero coupling to the socket and runs
   every cycle while the process loop is alive.
2. **epoch open ≡ presently connected** — FALSE. An epoch is `open` until explicitly closed/superseded;
   the table has only `opened_at`/`closed_at`, **no last-frame/heartbeat/connectivity timestamp**.
3. **observed_state=ready ≡ events flowing** — FALSE. `runtime_info_observed`→`ready` is ingested by the
   **REST** health check every 15s, so the node stays `ready` from REST heartbeats independent of the
   WebSocket. Live proof: the most recent receipts are all `runtime.info_observed` (no telephony events
   in the idle env), yet both nodes stayed `ready` with `observed_at` ~15s fresh.
4. **Pod Ready ≡ authoritative listener** — FALSE. The probe is `artisan about`.

## Lease authority
Created by `claim()` (single row per `(runtime_node_id, listener_kind)`, unique index), renewed by
`renew()`, released by `release()`, ownership checked by `isCurrent()`. Duration `lease_seconds`=45;
renewed every workOnce cycle. Expiry → another owner may `claim()` (takeover) once
`lease_expires_at < now`. Owner = `gethostname():asterisk-ari-events:pid` (pod name + pid). A spare
`metadata` JSON column exists and is currently unused. **No listener build/version recorded.** Renewal
does **not** require an active WebSocket and occurs merely because the process loop is alive.
Primary audit answer — **Can a listener continue renewing its lease while disconnected from ARI
events? YES.** Between the 15s REST health checks (and whenever `stasisApplicationRegistered` still
returns true, e.g. a half-open socket Asterisk has not yet timed out, or a sibling subscriber masking
the app registration), the listener renews its lease and reads zero frames indistinguishably from a
quiet-but-healthy stream.

## Event epoch authority
`openEpoch` inserts `status='open'`; `closeEpoch` sets `closed` (owner+open match); `closeStaleEpochs`
sets `expired` for **other** owners' open epochs on a node. An open epoch means "a listener opened it
and has not torn it down/been superseded" — i.e. **historically connected**, NOT presently connected.
Multiple `open` epochs can coexist and RuntimeNode readiness does not depend on any epoch's currency.
**Observed live defect (classification C):** node `1d15ca88` and `05ddb383` each have **3 simultaneous
`open` epochs**, all owned by the same still-running pod/pid (`...qm82r:...:1`), opened 10:47:08,
16:00:43, 21:24:53 — the same long-lived process reopened without closing its own prior epochs. Root
cause: (a) `closeStaleEpochs(nodeId, workerId)` only closes epochs with a **different** owner, so a
same-owner reconnect leaks; (b) `openConnection()` opens the epoch (line 223) before `inspect()`
(line 224) — if inspect/conference-inspection throws, the epoch is left `open` and control falls to the
outer catch which opens+closes a **different** epoch. So "open epoch per node" is not currently a
reliable single-authoritative-connection signal; the epoch cannot serve as a liveness anchor until its
lifecycle is corrected.

## RuntimeNode readiness authority
Canonical owner: `ProjectionService::apply()` sets `runtime_nodes.observed_state`/`observed_at` from the
normalized receipt (`connection_opened`/`runtime_info_observed`→`ready`, `connection_closed`→
`unavailable`, `authentication_failed`/default→`degraded`). A separate time-based degrader,
`ProjectionService::markStale($staleSeconds)` (scheduled via `console.php`, `runtime_engine.
stale_observation_seconds`=300), sets `observed_state='stale'` when `observed_at < now-300s`. **But the
REST health check ingests `runtime_info_observed` every 15s, keeping `observed_at` fresh, so `markStale`
never fires for a WebSocket-dead-but-REST-healthy node.** No path degrades the node on WebSocket-frame
absence. Readiness is thus a REST-driven projection, not an event-stream-liveness signal.

## Proven events-only failure mode
`readEvent` (non-blocking `fread`) returns `null` for **all** of: quiet-healthy (no frames),
peer-closed (no `feof` check), and stalled/half-open (no FIN). There is **no** WebSocket ping/pong (the
frame reader ignores opcodes entirely and treats every frame as a JSON text payload), **no** TCP
keepalive, **no** application heartbeat over the socket, **no** last-frame timestamp, and the
`stream_set_timeout` set at open is nullified by `stream_set_blocking(false)`. The only stall detection
is the 15s REST `stasisApplicationRegistered` correlation, which throws `ari_stasis_subscription_lost`
and tears down when the Stasis app is no longer REST-registered. Five sub-modes, distinct:
- **Connection failure** (no socket): caught at `openWebSocket`/`readEvent` throw → teardown+backoff.
- **Authentication failure** (401/403 at handshake): caught → `authentication_failed`→degraded.
- **Stalled connection** (socket open, no frames, no FIN, app still REST-registered): **NOT detected**
  between/through health checks — lease renews, epoch stays open, node stays `ready`. This is the gap.
- **Quiet-but-healthy PBX** (no activity): indistinguishable from stalled at the WebSocket layer today.
- **Downstream failure** (frames arrive, normalize/persist fails): separate — receipt/projection errors,
  not a listener-liveness concern.
Classification (Section 9): **D — already partly corrected (the REST stasis-correlation catches the
REST-visible deregistration case, tested at `AsteriskAriAdapterTest` ~line 1227/1275) but the positive
per-socket stream-liveness authority is absent/unproven**, compounded by the observed **C** epoch-leak.
Prior evidence explicitly retains this: "Old-node listener half-open — heartbeat + Stasis-registration
check tears down and reconnects (T2-B)" and "The retained events-only listener liveness-detection gap
is separate."

## Quiet-stream semantics
UTCP currently **cannot** distinguish a quiet-healthy stream from a dead/stalled one at the WebSocket
layer — both surface as `readEvent → null`. The only positive signal that proves THIS TCP connection is
bidirectionally alive is a **WebSocket ping/pong** (client sends opcode 0x9 ping, requires a 0xA pong
within a bounded window). The REST stasis-correlation is out-of-band and can be masked (half-open before
Asterisk's own socket timeout, or a second subscriber holding the app registered). Liveness must not be
based solely on receiving telephony events (idle PBX = zero events legitimately).

## Reconnect behavior
On any `AsteriskAriException` from health check or `readEvent`: `ingestFailure` (records
`connection_closed`/`authentication_failed` receipt), `recordFailureBackoff`
(`AsteriskAriReconnectBackoff`, bounded `reconnect_min_delay_ms`=1000 … `reconnect_max_delay_ms`=30000,
process-local per-node), `teardownConnection` (closes epoch, closes socket, releases lease). Reconnect
is indefinite but bounded-backoff; retry ownership is process-local (the backoff map is in-memory).
During backoff the node's `observed_state` reflects the last receipt (`unavailable` after
`connection_closed`), so readiness is not falsely `ready` **for a detected** close — but a stall that is
never detected keeps it `ready`. Per-node lease prevents cross-node interference; a stale listener that
loses its lease cannot resume (renew fails → teardown). Event gaps during an undetected stall are not
observable today.

## Listener rollout and overlap
On rollout, a new pod claims a node's lease only after the old lease expires or is released
(single-owner mutex; `claim()` returns null while a live lease is held by another owner). `openConnection`
verifies `isCurrent` after the socket opens and calls `closeStaleEpochs(nodeId, newOwner)` to expire the
old owner's open epochs. So listener leases + epoch fencing **do** prevent duplicate *authority* and the
normalizer's binding join prevents cross-node projection (T5-A1). Duplicate normalization is prevented by
the receipt `external_event_key` idempotency. The **residual** overlap issue is the same-owner epoch
leak above (not cross-owner), which does not cause duplicate authority but pollutes the epoch signal.

## Placement and failover impact
- **No active Conference**: events-only loss → node should be marked **events-degraded** (a capability
  degradation), not full `unavailable`, since REST control still works. No immediate failover.
- **Active Conference**: missed bridge/channel events can block convergence/cleanup; the node should be
  events-degraded and its bound conferences woken for REST re-inspection (the reconnect path already does
  targeted re-inspection on reconnect — the gap is *triggering* it when the stall is undetected).
- **Pending runtime operation**: verification already uses REST inspection (absence-verification), so a
  REST-healthy node can still complete fence/restore verification even when events are degraded.
- **REST healthy**: the system must NOT conclude complete node failure from events-only loss.
Chosen effect: events-only failure should **mark listener degraded → mark RuntimeNode events-degraded →
block new Conference placement on that node → wake bound-conference REST re-inspection → alert**. It
should **not** trigger fencing/failover by itself while REST control remains usable (deterministic
capability degradation, not global disable; no operator action required).

## Selected liveness model
**Model C (corrected) — event epoch carries connectivity state — driven by a positive WebSocket
ping/pong signal, with the epoch-leak defect fixed.** Rationale: prefer correcting the existing
per-connection authority (the epoch) over adding a parallel table; the epoch is the natural home for
"presently connected" once it (a) is guaranteed single-open-per-node and (b) carries a
`last_authoritative_signal_at`. The lease stays a pure ownership mutex (do NOT couple `renew()` to the
socket — that would cause churn on every transient blip). A secondary retained signal is the existing
REST stasis-correlation. (Model A rejected: coupling the lease to the socket conflates ownership with
connectivity and causes takeover churn. Model B — separate stream-health table — rejected as parallel
state when the epoch already exists.)

## State transitions
| transition | owner | trigger | durable | RuntimeNode effect | lease | epoch | metric | alert |
|---|---|---|---|---|---|---|---|---|
| connecting→connected | listener | 101 handshake + first pong/frame | epoch `open`, `last_authoritative_signal_at`=now | ready | held | open | connection state=connected | — |
| connected (heartbeat) | listener | pong received OR real frame OR REST stasis ok | update `last_authoritative_signal_at` | ready | renew | open | last_signal_age reset | — |
| connected→degraded | listener/sweeper | `last_authoritative_signal_at` older than grace | node events-degraded | held | open | events_degraded=1 | events-degraded alert |
| degraded→disconnected | listener | ping timeout / read throw / stasis lost | `connection_closed` receipt | unavailable/degraded | released | closed | reconnect_total++ | (existing reconnect) |
| disconnected→reconnecting | listener | backoff elapsed | — | (unchanged) | claim | — | — | — |
| reconnecting→connected | listener | new 101 + pong | new epoch, signal fresh | ready (recovered) | held | new open | recovered event | resolves |
Avoid one durable write per ping/pong: update `last_authoritative_signal_at` in-place (bounded to the
heartbeat cadence), and emit degraded/recovered audit events only on the crossing, not per frame.

## Timeout and grace contract
Reuse existing `config/asterisk_ari.php` (no new env, no feature gate): `heartbeat_interval_ms`=15000
(ping + REST correlation cadence), `websocket_handshake_timeout_ms`=4000 (connect), `request_timeout_ms`
=4000, `reconnect_min/max_delay_ms`=1000/30000 (backoff), `lease_seconds`=45. New constants (as
validated `config/asterisk_ari.php` keys, following the existing validation pattern): a **pong/read
deadline** (e.g. one heartbeat interval — no pong within 15s → degraded candidate) and an
**events-degraded grace** (e.g. 2× heartbeat = 30s of missing authoritative signal → node
events-degraded), with **readiness restoration** on the first fresh pong/frame after reconnect. The
existing `runtime_engine.stale_observation_seconds`=300 remains the coarse backstop.

## Metrics and alerts
Existing listener metric families (unchanged): `asterisk_ari_nodes` (by desired/observed state),
`asterisk_ari_websocket_connections` (epochs by status), `asterisk_ari_events_received_total`,
`asterisk_ari_reconnects_total`, `asterisk_ari_authentication_failures_total`,
`asterisk_ari_listener_claims_total`, `asterisk_ari_listener_nodes` (claimed). Existing alerts:
`UTCPAsteriskAriEventsUnavailable`, `UTCPAsteriskAriNodeWithoutListenerEvidence`,
`UTCPAsteriskAriAuthenticationFailures`, `UTCPAsteriskAriReconnectLoop`, `UTCPAsteriskAriReceiptBacklog`,
`UTCPAsteriskRuntimeObservationStale`. Minimum additions:
- gauge `asterisk_ari_events_degraded_nodes` (current count of active nodes whose authoritative stream
  signal is stale) — the durable, alertable health signal.
- optional counter `asterisk_ari_listener_signal_timeouts_total{result}` (pong/read-deadline timeouts).
- (reconnect_total and websocket_connections already cover reconnect/epoch churn.)
Bounded labels only (state/result enums). **No** RuntimeNode UUID, Pod UID, lease owner, raw error,
endpoint URL, or tenant label. One actionable alert: `UTCPAsteriskAriEventStreamDegraded` —
`sum(asterisk_ari_events_degraded_nodes) > 0 for 5m`, severity warning, summary "Asterisk ARI event
stream liveness is degraded on an active node while REST may still be reachable" (no manual-recovery
wording; automatic reconnect owns recovery).

## Events and audit
Emit durable audit/outbox transitions only on the crossing (not per reconnect attempt):
`runtime_node.event_listener_degraded` and `runtime_node.event_listener_recovered`, following the
existing outbox `EventEnvelope::forAggregate` convention with bounded payload (runtime_node_id in the
payload body, never as a metric label; classification/reason as bounded enums). These add operational
value beyond metrics (correlatable timeline). Do not emit an event per ping/pong or per backoff tick.

## Existing test coverage
Present: `test_listener_leases_are_node_scoped_and_fenced` (claim/takeover/renew/release/isCurrent),
`test_profile_configuration_change_releases_the_listener_lease...`, subscription-lost detection via REST
`stasisApplicationRegistered` → `ari_stasis_subscription_lost` teardown (`AsteriskAriAdapterTest`
~1227/1275), plus extensive absence-verification/conference-recovery coverage. **Missing:** quiet-healthy
vs stalled/dead stream distinction; WebSocket ping/pong + pong-timeout; lease renewal gated/monitored
against stream liveness; epoch single-open-per-node invariant + leak-on-inspect-failure regression;
events-degraded RuntimeNode transition + recovery; placement blocked while events-degraded; active
Conference during undetected event loss; REST-healthy-while-stream-dead; the new metric/alert behavior.

## Missing implementation (this audit changed no code)
(1) Fix epoch lifecycle: single open epoch per node (close same-owner superseded epochs; close the
epoch on `openConnection` failure paths). (2) Add WebSocket ping/pong to the frame reader/writer
(opcode-aware) as the positive per-socket signal. (3) Record `last_authoritative_signal_at` on the
current epoch; degrade to events-degraded when stale. (4) Events-degraded RuntimeNode capability that
blocks new placement + wakes bound-conference REST re-inspection while keeping REST control. (5) Metrics
`asterisk_ari_events_degraded_nodes` (+ optional signal-timeout counter) and alert
`UTCPAsteriskAriEventStreamDegraded`. (6) Degraded/recovered audit-outbox events. (7) The test matrix
above. All specified here; none applied (evidence-only).

## Controlled live-proof boundary
NOT performed in this audit (read-only). Constraint discovered: ARI **REST and the event WebSocket share
the same Asterisk HTTP port**, so a clean L4 "affect only the WebSocket" disruption is not trivially
reversible without touching REST — a naive NetworkPolicy/iptables block would also kill REST (and
NetworkPolicy/Pod/Asterisk mutation is prohibited). Define for the next Claude live-proof step: against
one **idle** RuntimeNode only, induce a WebSocket-only stall that keeps Asterisk + ARI REST healthy
(candidate mechanisms to establish from Asterisk evidence first: a socket-level pause of the listener's
single WS connection, or a `tc`/proxy delay scoped to the WS stream), capture lease/epoch/listener/
RuntimeNode state before and after, prove the node transitions to events-degraded within the grace and
recovers on reconnect, and fully restore. Avoid active Conference, Pod deletion, Deployment scaling,
direct DB writes; preserve the second node.

## Implementation-readiness decision — A (bounded Codex implementation)
The audit establishes the exact failure mechanism, the canonical authority (Model C corrected +
ping/pong), lease semantics (ownership mutex, decoupled), epoch semantics (+ the leak fix), the
RuntimeNode readiness effect (events-degraded capability), quiet-stream handling (ping/pong positive
signal), timeout/reconnect behavior (existing config + two new validated constants), placement/failover
effect (degrade + wake, no self-fence), metrics/events, the exact tests, and the live acceptance
criteria. The controlled live proof is explicitly deferred to the Codex implementation's Claude-proof
follow-up (Section 20 permits defining it for the next step). Ready for a bounded Codex implementation.

## Ready-to-paste next prompt (Codex — bounded implementation)
```
T5-A64 — Implement events-only listener liveness authority

Repo state: HEAD fa0d8d3, branch main, clean, UTCP_PHASE=T1. Implement exactly the T5-A63 contract in
docs/evidence/t2/multi-node-failover-readiness.md. Do not begin V0. Keep UTCP_PHASE=T1. No feature gate,
no manual reconnect command, no source-IP allowlist, no per-frame durable write. Do not push.

1. Epoch lifecycle fix (AsteriskAriEventListener + RuntimeEventReceiptRepository): guarantee a single
   open epoch per node. Close the epoch on every openConnection() failure path (inspect/conference-
   inspection throw after openEpoch), and close the listener's own prior open epoch for the node before
   opening a new one (not just other owners'). Add a regression proving no same-owner epoch leak.

2. WebSocket ping/pong (AsteriskAriClient): make the frame reader opcode-aware (handle 0x1 text/0x9
   ping/0xA pong/0x8 close); send a client ping (masked 0x9) each heartbeat interval; require a pong
   (0xA) within a bounded read/pong deadline. A missing pong within the deadline is a degraded signal;
   a close frame or read error is a disconnect.

3. Authoritative stream signal: add last_authoritative_signal_at to runtime_event_connection_epochs
   (migration) updated in-place (bounded to heartbeat cadence) on a received pong OR a real telephony
   frame OR a successful REST stasis correlation. Do NOT update it merely because the process loop ran.
   Keep the lease a pure ownership mutex (do not couple renew() to the socket).

4. Events-degraded RuntimeNode capability: when last_authoritative_signal_at is older than the
   events-degraded grace (new validated config/asterisk_ari.php constant, ~2x heartbeat), mark the node
   events-degraded — block NEW Conference placement on it and wake bound-conference REST re-inspection,
   while keeping REST control operations (fence/restore verification) usable. Restore to ready on the
   first fresh pong/frame after reconnect. Reuse existing timings; add only the pong deadline and the
   events-degraded grace as validated constants (no env feature gate).

5. Metrics (MetricsController): add gauge asterisk_ari_events_degraded_nodes (active nodes with a stale
   authoritative signal) and optional counter asterisk_ari_listener_signal_timeouts_total{result};
   bounded enum labels only, no UUID/PodUID/owner/tenant/URL/raw-error labels.

6. Alert (utcp-alerts.yaml): add UTCPAsteriskAriEventStreamDegraded —
   sum(asterisk_ari_events_degraded_nodes) > 0 for 5m, severity warning, component asterisk-ari,
   summary about ARI event-stream liveness degraded while REST may still be reachable; no manual-recovery
   wording. Preserve the existing six asterisk-ari alerts.

7. Audit/outbox transitions: emit runtime_node.event_listener_degraded / _recovered only on the crossing
   (EventEnvelope::forAggregate convention, bounded payload; runtime_node_id in payload body, never a
   metric label). No event per reconnect attempt.

8. Tests: initial connect; auth failure; socket close; reconnect; quiet-healthy stream stays ready via
   pong; pong-timeout → events-degraded; lease renewal continues but stream-signal drives readiness;
   epoch single-open-per-node + leak-on-inspect-failure regression; events-degraded transition + recovery;
   placement blocked while events-degraded; active Conference during event loss wakes REST re-inspection;
   REST-healthy-while-stream-dead; metric + alert behavior.

Verify: make repository-hygiene workflow-check secret-scan; make runtime-engine-config-check
telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check; make
runtime-engine-test telephony-domain-test asterisk-ari-test asterisk-conference-test
asterisk-conference-recovery-test; git diff --check. Commit
feat(t5): detect events-only listener liveness loss. Do not push.
Then hand to Claude Code for the T5-A65 controlled live proof (idle-node WebSocket-only stall; note
REST and WS share the Asterisk HTTP port — establish the reversible WS-only stall mechanism from
Asterisk evidence first).
```

## Verification performed (T5-A63)
Read-only: full AsteriskAriEventListener trace; WebSocket lifecycle trace (openWebSocket/readEvent/
closeWebSocket — non-blocking, no ping/pong, no feof/keepalive); lease authority trace
(RuntimeListenerLeaseRepository — renew decoupled from socket); event-epoch trace (open/close/stale —
no liveness timestamp; observed 3 open epochs/node under one owner); RuntimeNode readiness-writer trace
(ProjectionService.apply + markStale, REST-fed observed_at); reconnect/backoff trace
(AsteriskAriReconnectBackoff bounded); K8s probe trace (artisan about = process-only); historical
listener-failure evidence scan (prior half-open + retained-gap notes); rollout overlap trace (lease +
epoch fencing prevent duplicate authority; residual same-owner epoch leak); placement/failover impact
analysis; existing metric/alert inventory; cardinality/secret scan of proposal (bounded labels only);
env-gate/allowlist scan (none in listener path); manual reconnect/recovery surface scan (none);
phase-marker inspection.
make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS.
make *-test: runtime-engine 21, telephony-domain 60, asterisk-ari 94, asterisk-conference 109,
asterisk-conference-recovery 89: PASS. git diff --check / --cached: clean. No forbidden command run
(no scale/Pod-delete/Asterisk-restart/ARI-unload/Conference/RuntimeBinding/SQL-write/Redis/migrate/
lease/epoch mutation).

# T5-A64 - deterministic ARI event-stream liveness repository implementation

## Scope and boundary
Repository-only implementation. No Kubernetes deployment, Pod mutation, live ServiceMonitor change,
RuntimeBinding mutation, Conference mutation, PBX mutation, direct SQL update/delete, or live
WebSocket disruption was performed. The prompt referenced starting commit `433bf0c`; the repository
was already at `4c057c4` when implementation began, with branch `main`, clean working tree, and
`UTCP_PHASE=T1`.

## Epoch lifecycle correction
The ARI event listener now closes same-owner superseded open epochs before opening a successor, while
retaining the existing stale-owner close path. This removes the same-owner reconnect leak without
closing an epoch owned by a current different owner outside the existing fencing behavior.

The listener also closes the just-opened epoch when post-open inspection or conference reinspection
throws before the connection is recorded. All teardown paths close the connection's epoch before
discarding the connection state.

## Positive event-stream liveness
The raw ARI WebSocket reader is opcode-aware. Text frames continue through the existing ARI event
normalization path; ping, pong, and close frames are handled as control frames and are not forwarded as
telephony events. The client sends a WebSocket ping on the established heartbeat cadence and replies to
server ping frames with pong frames.

The current epoch carries `last_authoritative_signal_at`. A successful pong on the current connection
updates process-local liveness immediately and persists the timestamp only at the bounded heartbeat
cadence, avoiding one durable write per control frame. Telephony event frequency is not used as the
positive liveness signal.

## Events-degraded projection and recovery
If the current authoritative connection misses pong beyond the configured pong deadline and events-
degraded grace, the listener projects the RuntimeNode to `events_degraded` through the existing
RuntimeNode projection machinery. ARI REST health remains a separate signal. The stale event-stream
connection and epoch are torn down, existing reconnect backoff continues, and the listener does not
self-fence the RuntimeNode.

`events_degraded` RuntimeNodes remain listener-eligible for automatic reconnect and REST-based
verification/cleanup, but are excluded from new Conference placement by the existing placement selector
that requires `observed_state = ready`. Positive pong liveness after reconnect projects recovery back to
`ready` automatically and restores placement eligibility.

## Bound-Conference reinspection
On the first crossing into events-degraded state, the listener discovers active Conference bindings for
the RuntimeNode through existing binding tables and wakes the existing reconciliation/recovery path via
REST-based runtime inspection. Repeated observations while already events-degraded do not emit duplicate
transition events or repeatedly enqueue the same crossing work.

## Transition events, metric, and alert
State crossings emit bounded outbox events:

```text
runtime_node.event_listener_degraded
runtime_node.event_listener_recovered
```

The metrics endpoint now exposes a database-derived current-state gauge:

```text
asterisk_ari_events_degraded_nodes
```

The gauge has no labels and counts active/draining Asterisk ARI RuntimeNodes currently projected as
`events_degraded`. Metrics scraping performs no ARI, Kubernetes, Redis, or WebSocket I/O.

The Prometheus rule set adds:

```text
UTCPAsteriskAriEventStreamDegraded
sum(asterisk_ari_events_degraded_nodes) > 0
for: 5m
severity: warning
```

The alert annotation states that automatic ARI event-stream recovery remains degraded and contains no
manual recovery instruction.

## Focused tests
Repository tests cover the same-owner epoch leak, post-open exception cleanup, stale-owner behavior,
WebSocket ping/pong/close/text control-frame handling, quiet healthy stream behavior, pong-deadline
degradation, placement exclusion, listener reconnect eligibility, no self-fence, idempotent
Conference reinspection wake-up, automatic recovery, transition-event deduplication, the bounded gauge,
and alert-rule correctness.

## Controlled live-proof boundary
Controlled live WebSocket-only degradation and recovery proof remains pending. This implementation does
not claim that a live idle RuntimeNode has already been forced through an event-stream-only liveness
failure and automatic recovery.

---

# T5-A65 — Live proof: ARI event-stream degradation and automatic recovery (1e792fe)

Verdict: `T5_EVENTS_ONLY_LISTENER_LIVENESS_LIVE_PROOF_COMPLETE`. Controlled live proof against
`utcp-local` on one idle RuntimeNode. Baseline `UTCP_PHASE=T1`, clean tree at `1e792fe`. Evidence-only
doc change; the implementation is the committed 1e792fe.

## Provenance
HEAD `1e792fe723c304b29631eeec99f6ac1aa361bab0` (fix(t5): add deterministic ARI event-stream liveness),
on top of `4c057c4` and `433bf0c` (the T5-A63 audit). Implements the T5-A63 contract: opcode-aware
WebSocket ping/pong (AsteriskAriClient sendPing/readWebSocketMessage), epoch
`last_authoritative_signal_at` (migration 2026_07_20_120000), pong-deadline degradation
(pong_deadline_ms=15000 + events_degraded_grace_ms=30000), `events_degraded` observed_state, placement
excluded via `selectRuntimeNodeForConference` requiring `observed_state='ready'`, gauge
`asterisk_ari_events_degraded_nodes`, alert `UTCPAsteriskAriEventStreamDegraded`, and
`runtime_node.event_listener_degraded`/`_recovered` outbox events.

## Migration and rollout
Built canonical api image from clean 1e792fe (verified ping/pong, degradation logic, metric, migration
present), pushed sha256:91ade0fdf79e1792acb4908a2e0cf37730d1441c7decd44bd6e5db0a19a45c51. Ran the
canonical migration Job (utcp-migrate) — `2026_07_20_120000_add_ari_event_epoch_liveness ... DONE`
(adds epochs.last_authoritative_signal_at + runtime_nodes observed_state check incl. events_degraded).
Rolled out only api + asterisk-ari-events (both = api image); applied the PrometheusRule change.
Confirmed both workloads on digest 91ade0f. Did not restart Asterisk/Kamailio/PostgreSQL/Redis/Traefik.

## Healthy listener baseline
After the new listener (`asterisk-ari-events-777f7768fb-xh28p`, node server-0, 10.42.1.84) took over
the leases from the terminating old pod, per node: exactly **one** open epoch (the historical leaked
epochs — 3/node from before — were closed/superseded automatically by the new owner, no direct SQL),
`last_authoritative_signal_at` advancing every heartbeat (e.g. 23:35:25 → 23:35:56, pong-driven),
both leases claimed by the new owner, both RuntimeNodes ready and placement-eligible,
`asterisk_ari_events_degraded_nodes`=0, alert `UTCPAsteriskAriEventStreamDegraded` loaded and inactive
(health=ok, for=5m).

## Selected RuntimeNode
Target `1d15ca88` ("Local Asterisk ARI") → Service `asterisk-ari` ClusterIP 10.43.29.130:8088, Asterisk
pod `asterisk-ari-db55d57c5-2vvsn` (10.42.0.112, agent-0). Peer (kept healthy) `05ddb383` → `asterisk-ari-b`
10.43.149.214, pod `asterisk-ari-b-...rcjfn`. No active Conference, RuntimeBinding, or actionable
operation on either. Target WS four-tuple (from `/proc/net/tcp` in the listener netns):
`10.42.1.84:40846 → 10.43.29.130:8088` (epoch `ee52bd03`), REST+WS both healthy pre-fault.

## WebSocket-specific fault mechanism
Reversible, connection-specific: from the k3d node container `k3d-utcp-local-server-0`, `nsenter -t
<listener-sandbox-pid> -n /bin/aux/iptables` into the **listener pod's** network namespace, and DROP the
exact WS four-tuple by source port:
```
iptables -I OUTPUT -p tcp -d 10.43.29.130 --dport 8088 --sport 40846 -j DROP
iptables -I INPUT  -p tcp -s 10.43.29.130 --sport 8088 --dport 40846 -j DROP
```
This blocks only the established WS connection (unique ephemeral sport 40846); ARI REST from the same
listener uses fresh source ports and is unaffected; the whole Asterisk HTTP port is not blocked; the
listener process, Asterisk, and Pods are untouched; no PostgreSQL/Redis/lease/epoch edits. Immediately
reversible (`iptables -D`). The peer node's WS (`:xxxxx → 10.43.149.214`) was never touched. Applied at
23:49:58, removed at 23:51:21.

## REST health during event-stream failure
During the fault, REST GET to the target ClusterIP `10.43.29.130:8088/ari/asterisk/info` from the
listener netns (fresh source port) returned **HTTP 401** on 3 consecutive probes — i.e. the ARI HTTP
port responded (401 = auth required; the listener's own Basic-auth requests get 200). REST stayed
reachable while the WS was severed. Decisively, the node reached **events_degraded** (the pong-deadline
path), NOT `unavailable` (the connection-failure path), which is only possible if the listener's periodic
REST health check (`inspect` + `stasisApplicationRegistered`) kept succeeding throughout.

## Degradation detection
In-process 1s sampler timeline (target `1d15ca88`), fault at 23:49:58:
```
23:49:54  target=ready            gauge=0  place=05ddb383  tElig=Y  fence=0  deg=1
23:50:47  target=events_degraded  gauge=1  place=05ddb383  tElig=N  fence=0  deg=2   <-- ~49s after fault
23:50:53  target=ready            gauge=0  place=05ddb383  tElig=Y  fence=0  deg=2   <-- auto-recovered ~6s later
```
The node transitioned to `events_degraded` ~49s after the WS block (≈ pong_deadline 15s +
events_degraded_grace 30s + heartbeat quantization), within the configured bound. One
`runtime_node.event_listener_degraded` outbox event fired for the target with
`reason=pong_deadline_exceeded` (total deg events = 2 across two fault applications, both on the target).
`asterisk_ari_events_degraded_nodes` = 1 during the window.

## Event epoch behavior
Pre-fault the target held exactly one open epoch (`ee52bd03`, signal advancing). On pong-deadline the
listener closed the stalled epoch and released the lease (teardown), then reconnected on a fresh source
port and opened a successor epoch. Post-recovery: exactly one open epoch per node again, with
`last_authoritative_signal_at` advancing (23:52:32). No leaked/duplicate open epochs.

## Placement exclusion
The main conference placement selector `selectRuntimeNodeForConference` requires `observed_state='ready'`;
`events_degraded` is therefore ineligible. Proven live via the read-only eligibility path (the
prompt-preferred option): during the degraded window the replicated placement candidate query returned
the **peer** `05ddb383` (`place=05ddb383`, `tElig=N` for the target) — the degraded target was excluded
from new placement. Before and after, the target was eligible (`tElig=Y`). No disposable Conference was
created, so none needed cleanup.

## Self-fencing boundary
No fence operation was created at any point: `fence=0` across the entire timeline, and 0 fence-type
`runtime_operations` in the last 15 minutes. Event-stream degradation degraded the node's placement
capability only; it did not trigger fencing/failover while REST control remained usable.

## Metric and alert behavior
`asterisk_ari_events_degraded_nodes` is scraped by Prometheus (family present, job=gateway). It read 1
during the degraded window (in-process sample, computed identically to the metric endpoint) and 0 before
and after. The alert expression `sum(asterisk_ari_events_degraded_nodes) > 0` evaluates cleanly
(post-recovery: 0 result series). `UTCPAsteriskAriEventStreamDegraded` is loaded, health=ok, lastErr
empty, state inactive post-recovery. The ~6s degraded window was shorter than the 30s Prometheus scrape
interval and the 5m `for`, so the alert correctly did not fire (the fault was intentionally not held for
5m); the metric logic and alert expression are proven.

## Automatic recovery
Recovery was fully automatic: after teardown the listener reconnected through existing backoff, the
WebSocket ping/pong resumed on the successor epoch, `last_authoritative_signal_at` advanced, and the
RuntimeNode returned to `ready` with placement eligibility restored — all with no operator reconnect or
manual reconciliation. The fault was also explicitly removed at 23:51:21; ≥2 healthy heartbeat cycles
followed (stably ready through 23:52:32). Divergence: because recovery proceeded through the reconnect
path (`connection_opened` → ready) rather than an in-place pong on the still-degraded connection,
`markEventStreamRecovered` was skipped and **no `runtime_node.event_listener_recovered` event was
emitted** (rec_events=0). The stream and RuntimeNode recovered completely and automatically (the
principal recovery claim holds); only the paired recovered-telemetry event did not fire on the common
reconnect path — see Divergences.

## Final runtime state
Both RuntimeNodes ready and placement-eligible; both Asterisk Deployments 1/1 (asterisk-ari,
asterisk-ari-b) plus listener 1/1; exactly one open event epoch per node with advancing signal; leases
valid; `asterisk_ari_events_degraded_nodes`=0; alert inactive; 0 open/pending conferences; 0 active
bindings; 0 actionable operations; all simulators disabled. The iptables fault is fully removed (listener
netns has no DROP rules). No disposable proof resource was created.

## Divergences
1. **Recovered event not emitted on reconnect recovery.** `runtime_node.event_listener_recovered` fires
   only via `markEventStreamRecovered` (a pong arriving while the node is still `events_degraded`), but
   the pong-deadline path tears the connection down, so recovery normally completes through the
   reconnect's `connection_opened` → ready projection, which does not emit the recovered event. Result:
   a degraded event with no paired recovered event (an observability-symmetry gap). The node still
   recovers fully and automatically. Bounded follow-up: emit `event_listener_recovered` when a node
   leaves `events_degraded` via the reconnect path as well (or have the reconnect projection close the
   degraded transition). Does not affect degradation detection, placement exclusion, self-fence
   avoidance, or automatic stream restoration.
2. `events_degraded` is intentionally a short-lived transient (fast auto-recovery, ~6s here), so
   Prometheus's 30s scrape may not observe gauge=1 for a single event; the live metric logic and alert
   expression were validated directly. Not a defect — a consequence of correct fast recovery.

## Verification performed (T5-A65)
make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS. make *-test
(runtime-engine, telephony-domain, asterisk-ari, asterisk-conference, asterisk-conference-recovery):
PASS. git diff --check / --cached: clean. Fault applied and removed via listener-netns iptables only;
no Pod scaling/deletion, no Asterisk/Kamailio restart, no ARI module unload, no Conference/RuntimeBinding
mutation, no direct SQL/Redis writes, no NetworkPolicy weakening. Environment fully restored.

---

# T5-A66 - reconnect recovery event symmetry repository implementation

## Scope and boundary
Repository-only correction for the T5-A65 observability-symmetry gap. No listener-liveness redesign,
ping/pong timing change, lease behavior change, epoch ownership change, placement-rule change,
Conference reinspection change, metric rename, alert expression change, live Kubernetes mutation, PBX
mutation, or direct data repair was performed.

## Reconnect recovery gap
T5-A65 proved that an idle ARI event-stream fault degrades the RuntimeNode to `events_degraded` and then
recovers automatically after the listener reconnects. The remaining gap was that the common reconnect
path records `connection_opened`; that receipt projects the RuntimeNode back to `ready`, but the
`runtime_node.event_listener_recovered` outbox event was only emitted by the in-place pong recovery path.
The result was correct runtime behavior with asymmetric transition evidence.

## Shared recovered transition
The listener now snapshots the RuntimeNode's canonical projected state before ingesting
`connection_opened`. If the previous state was `events_degraded`, the reconnect path emits one
`runtime_node.event_listener_recovered` event whose source points at the `connection_opened` receipt.
The existing pong recovery path and reconnect recovery path share the same recovered outbox payload
builder, so event construction remains single-sourced.

## Idempotency behavior
Initial connection from `unobserved`, `unknown`, `connecting`, `ready`, `degraded`, `unavailable`, or
`stale` is not represented as event-stream recovery. Once the first reconnect recovery projects the node
back to `ready`, later `connection_opened` observations do not emit duplicate recovered events because
the previous canonical state is no longer `events_degraded`. Existing epoch, reconnect, placement,
metric, and degradation behavior remains unchanged.

## Focused tests
Focused repository tests cover `events_degraded -> connection_opened -> ready` recovery event emission,
duplicate suppression across repeated reconnects, the initial-connection boundary, in-place pong
recovery, one-current-epoch recovery state, restored placement eligibility, the degraded-node gauge
returning to zero, and unchanged degraded-event behavior.

## Controlled live-proof boundary
A short targeted live proof remains pending to observe exactly one
`runtime_node.event_listener_recovered` event during reconnect recovery after this code change. This
repository implementation does not claim that live recovered-event observation has already occurred.

---

# T5-A67 — Live proof: listener reconnect recovery event (f90e266)

Verdict: `T5_LISTENER_RECONNECT_RECOVERY_EVENT_LIVE_PROOF_COMPLETE`. Targeted live proof against
`utcp-local` closing the T5-A65 divergence (recovered event not emitted on the reconnect path).
Baseline `UTCP_PHASE=T1`, clean tree at `f90e266`. Evidence-only doc change.

## Listener image rollout
Built the canonical api image from clean `f90e266` (verified `appendEventStreamRecovered` +
`previousObservedState` reconnect guard present), pushed
sha256:7052af210559b11279e23bd97fe8d5c76204b01e3091f4c83b64f17c6f570843. No new migration in this
commit. Rolled out only api + asterisk-ari-events (both = api image); confirmed both workloads on the
new digest (listener pod `asterisk-ari-events-59cb6f98f7-b5wmz`, node server-0, 10.42.1.85). Did not
restart Asterisk/Kamailio/PostgreSQL/Redis/Traefik. After the new listener took over the leases from the
terminating old pod, both RuntimeNodes were `ready`, exactly one open epoch per node, signal advancing
via ping/pong (e.g. 00:35:50 → 00:36:05), degraded gauge 0, alert inactive. Boundary check: the initial
connection after rollout emitted NO recovered event (`REC_TOTAL` stayed 0 — the nodes were `ready`, so
`previousObservedState` at connect was not `events_degraded`).

## Selected RuntimeNode
Target `1d15ca88` → Service `asterisk-ari` ClusterIP 10.43.29.130:8088; peer `05ddb383` (kept healthy).
No active Conference, RuntimeBinding, or actionable operation. Target WS four-tuple (from
`/proc/net/tcp` in the listener netns): `10.42.1.85:60032 → 10.43.29.130:8088`, epoch `2730627b`.

## Event baseline
Before the transition: `runtime_node.event_listener_degraded` = 2, `runtime_node.event_listener_recovered`
= **0**, target epoch `2730627b` with advancing `last_authoritative_signal_at`.

## WebSocket fault
Reused the proven T5-A65 mechanism: from k3d node `k3d-utcp-local-server-0`, `nsenter -t 90071 -n
/bin/aux/iptables` into the listener pod netns and DROP the exact WS four-tuple by source port:
```
iptables -I OUTPUT -p tcp -d 10.43.29.130 --dport 8088 --sport 60032 -j DROP
iptables -I INPUT  -p tcp -s 10.43.29.130 --sport 8088 --dport 60032 -j DROP
```
Blocks only the established WS (unique sport 60032); ARI REST from the listener uses fresh ports and
stayed available (HTTP 401 to `.../ari/asterisk/info` from the listener netns during the fault = port
alive); listener process, Asterisk, and Pods untouched; peer WS untouched; no DB/Redis/lease/epoch
edits; immediately reversible. Applied 00:36:35, removed 00:37:39.

## Degraded transition
In-process 1s sampler (target `1d15ca88`):
```
00:36:30  target=ready            gauge=0  epoch=2730627b  deg=2  rec=0  fence=0
00:37:20  target=events_degraded  gauge=1  epoch=2730627b  deg=3  rec=0  fence=0   <-- ~45s after fault
00:37:21  target=events_degraded  gauge=1  epoch=none       deg=3  rec=0  fence=0   <-- stalled epoch closed
00:37:27  target=events_degraded  gauge=1  epoch=a59a1958   deg=3  rec=1  fence=0   <-- reconnect + recovered
00:37:28  target=ready            gauge=0  epoch=a59a1958   deg=3  rec=1  fence=0   <-- ready
```
Exactly one new degraded event (deg 2→3), stalled epoch closed on teardown, no self-fence (fence=0),
peer remained `ready`.

## Reconnect recovery
The listener reconnected automatically through backoff on a fresh source port, a successor epoch
`a59a1958` opened (its `connection_opened` receipt), and — because `previousObservedState` at reconnect
was `events_degraded` — the fix emitted the recovered event at that moment, then the RuntimeNode returned
to `ready`. (Recovery completed at 00:37:27, before the explicit fault removal at 00:37:39, because the
reconnect used an unblocked port; the fault was then removed and the environment confirmed clean.)

## Recovered event
Exactly one `runtime_node.event_listener_recovered` (REC_TOTAL 0 → 1). Its record:
- aggregate `runtime_node:1d15ca88` — the selected RuntimeNode. ✓
- `payload.source_event_id` = `bfb31e0cdcbd8462862f5b398c23dcc7`, which is a
  `asterisk.ari.connection.opened` receipt for node `1d15ca88` — i.e. it references the reconnect
  `connection_opened` receipt per repository convention. ✓
- created 00:37:26, after the degraded transition at 00:37:20. ✓
- emitted exactly once. ✓

## Duplicate suppression
After recovery the sampler observed `rec=1` held constant from 00:37:28 through 00:38:35 (>=13 healthy
1s cycles, ~68s, many `connection_opened`/heartbeat cycles) with the node stably `ready` — no additional
recovered event. `deg` also held at 3. The reconnect guard fired once (on the events_degraded→ready
reconnect) and healthy cycles do not re-emit it, because `previousObservedState` is `ready` on every
subsequent connect.

## Event epoch state
Post-recovery: exactly one open epoch per node (target `a59a1958`), `last_authoritative_signal_at`
advancing (00:39:36), listener leases valid. No leaked/duplicate open epochs.

## Final runtime state
Both RuntimeNodes ready and eligible; both Asterisk Deployments 1/1 (asterisk-ari, asterisk-ari-b) plus
listener 1/1; one open epoch per node with advancing signal; leases valid;
`asterisk_ari_events_degraded_nodes`=0 (Prometheus) with alert `UTCPAsteriskAriEventStreamDegraded`
inactive; 0 open/pending conferences; 0 active bindings; 0 actionable operations; all simulators
disabled; 0 fence operations created; iptables fault fully removed (no residual DROP rules). No
disposable proof resource created.

## Divergences
None. The T5-A65 divergence (recovered event not emitted on reconnect recovery) is resolved: recovery
via the reconnect `connection_opened` path now emits exactly one `runtime_node.event_listener_recovered`
referencing that receipt, with no duplicates.

## Verification performed (T5-A67)
make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS. make *-test
(runtime-engine, telephony-domain, asterisk-ari, asterisk-conference, asterisk-conference-recovery):
PASS. git diff --check / --cached: clean. Fault applied/removed via listener-netns iptables only; no
Pod scale/delete, no Asterisk/Kamailio restart, no ARI module unload, no Conference/RuntimeBinding
mutation, no direct SQL/Redis writes, no NetworkPolicy weakening. Broader placement/failover/observability
corridors not rerun (no regression observed). Environment fully restored.

---

# T5-A68 — Contract: deterministic capacity and placement authority (evidence-only)

Verdict: `T5_DETERMINISTIC_CAPACITY_AND_PLACEMENT_CONTRACT_DEFINED`. Narrow contract audit; no production
code changed. Baseline `UTCP_PHASE=T1`, clean tree at `4068362`.

## Current placement authority
There is exactly **one** canonical node selector: `TelephonyDomainService::selectRuntimeNodeForConference(
tenantId, capabilities, excludeRuntimeNodeId=null, desiredStates=['active','draining'])`
(TelephonyDomainService.php:1315). Three call sites, all routed through it:
1. **Initial placement** — `changeConferenceDesiredState(...'open')` L270: `$runtimeNodeId ??=
   selectRuntimeNodeForConference($tenantId, 'conference.lifecycle')`, inside a `DB::transaction` with
   `lockForUpdate()` on the conference row; then `writeBinding()`.
2. **Failover replacement** — `reconstructOpenConferenceBinding()` L426 (capabilities = conference
   lifecycle+participation, excludes the former node), retires the old active binding and writes the new
   one atomically.
3. **Replacement eligibility probe** — `hasDistinctEligibleReplacement()` L870 (desiredStates=['active']),
   used by `RuntimeFencingCoordinator` L239 and `RuntimeFenceOperationHandler` L61 to decide whether to
   fence at all.
`createConference()` L216 only binds when the caller passes an explicit `runtime_node_id` (draft); it
does not auto-select. Participant admission (`admitParticipant`/`admitSelf`) does **not** select a node —
participants inherit the conference's existing binding. The Simulator has no independent placement path.
Answer: **one canonical selector; no competing placement authority.** No authority needs removal — only
the selector's internals need extension.

## Placement entry points
Initial open (API `changeConferenceDesiredState`), failover replacement (fencing coordinator →
`reconstructOpenConferenceBinding` via a runtime operation), and the pre-fence eligibility probe. All
converge on `selectRuntimeNodeForConference`. Binding creation is always `writeBinding()` (single writer)
under the DB partial unique index `conference_runtime_bindings_one_active on (conference_id) where
status='active'` (one active binding per conference, enforced by PostgreSQL).

## Hard eligibility filters (current)
In `selectRuntimeNodeForConference`: `tenant_id = tenant` (tenant isolation); `desired_state IN
{active,draining}` (or {active} for the probe); `observed_state = 'ready'` (strict — so `events_degraded`,
`degraded`, `stale`, `unavailable`, `connecting`, `unobserved` are all excluded); `id <> excluded` when
replacing; and an `EXISTS` per required `runtime_node_capabilities.capability_key`. The `assert*`
helpers re-validate the same filters before mutation (`assertRuntimeNodeSupportsConference`:
active/draining + capability; `assertRuntimeNodeEligibleForConferenceRebind`: active/draining + ready +
capabilities). These are all **hard eligibility**. There is currently **no capacity filter and no
preference/ranking** beyond ordering. Not filtered today (candidates for the contract): runtime pool,
provider-node state, active-binding-count/capacity, reserved capacity, fenced/restoring exclusion
(handled indirectly via desired_state=disabled during fence, and via the `exclude` arg on replacement).

## Existing candidate ordering
`orderBy('runtime_family')->orderBy('adapter_key')->orderBy('id')` then `->first()`. The final
tie-breaker is `id` (the RuntimeNode UUID primary key) — a **stable persisted value**, so repeated
selection against identical state is **deterministic** (same node every time). This is already
non-random and not dependent on Pod/hostname/response ordering. The gap is that ordering is purely
lexical identity, not capacity- or load-aware: it always fills the lowest-`id` ready node first and never
spreads load or respects a per-node limit.

## Capacity evidence
- **canonical desired limit**: none exists today. `runtime_nodes.capacity_weight` (default 100) and
  `placement_priority` (default 100) columns exist (runtime registry migration) but are **unused** by
  any selector. `asterisk_ari_profiles` has no channel/conference limit. No capacity config in
  `telephony_domain`/`runtime_registry`.
- **durable reservation**: `conference_runtime_bindings` with `status='active'` and the partial unique
  index is the durable reservation of a conference→node assignment (one active per conference).
- **derived database usage**: `COUNT(conference_runtime_bindings WHERE runtime_node_id=? AND
  status='active')` = current active conference assignments per node (authoritative, transactional).
- **observed runtime usage**: `runtime_observations` / ARI reconnect inspection report bridge/channel
  presence, but are point-in-time observations — diagnostic, not placement authority.
- **pending operations**: `runtime_operations` (conference.ensure etc.) exist but are not a capacity
  ledger.
Classification: declared limit = **must be introduced** (reuse `capacity_weight` as the declared max, or
add an explicit `max_active_conferences`); durable usage = **active-binding count** (canonical, already
transactional); observed runtime counts = diagnostic only; provider-node limits = not present, defer.

## Selected capacity unit — Model A (Conference slots)
`available = declared_max_active_conferences(node) − active_conference_bindings(node)`. Declared max: use
`runtime_nodes.capacity_weight` as the canonical per-node conference-slot limit (it already exists,
defaults to 100, is tenant-owned registry state, and needs no new column or migration; 0 or a sentinel
can mean "unlimited/legacy" to preserve current behavior until operators set a real limit). Usage:
count of `conference_runtime_bindings.status='active'` for the node. This is the smallest model that
deterministically prevents oversubscription for the Asterisk Conference workload. Model B
(participant/channel slots) deferred — no durable per-node channel-limit authority exists. Model C
(weighted) deferred — no existing weight contract beyond the single scalar. No CPU/memory/K8s metrics.

## Reservation and concurrency authority
Reuse the existing transactional pattern. Initial placement already runs inside `DB::transaction` with
`lockForUpdate()` on the conference; replacement runs in the operation handler's transaction. The
contract: within the placement transaction, (1) select the candidate with `available > 0` computed from
a **locked** count, (2) revalidate eligibility, (3) `writeBinding()` (the partial unique index
`conference_runtime_bindings_one_active` guarantees no double active binding per conference), (4) commit.
For per-node slot contention between two different conferences racing for the last slot on one node, add
a `SELECT ... FOR UPDATE` on the candidate `runtime_nodes` row (or a per-node advisory lock keyed on the
node id) so the active-binding count is evaluated under a row lock; the loser re-evaluates the next
candidate or falls to pending_no_capacity. No Redis capacity authority, no process-local mutex, no manual
placement command. Idempotency: conference creation/open already flows through `IdempotencyKey`
(`telephony.conferences.create`) and the one-active-binding index makes a repeated open a no-op.

## Deterministic ranking
Explicit pipeline for the future selector:
```
1. Hard eligibility filters (tenant, desired_state, observed_state='ready', capabilities, exclude,
   available_slots > 0)
2. placement_priority ASC        (lower number = preferred; existing column, default 100)
3. available_slots DESC          (most free capacity first — spreads load, avoids hot-filling one node)
4. active_binding_count ASC      (least-loaded)
5. id ASC                        (stable persisted UUID tie-breaker — already the current final order)
```
All keys are persisted/derived deterministically; no randomness, no Pod/hostname/DB-natural/timing
order. This preserves today's `id` tie-breaker as the terminal key, so behavior stays deterministic and,
when all priorities/capacities are equal (current default state), reduces to the existing lowest-`id`
choice — a safe superset of current behavior.

## Initial and replacement placement alignment
Already aligned: initial (L270), failover replacement (L426), and the eligibility probe (L870) all call
the same `selectRuntimeNodeForConference`, so adding the capacity filter + ranking there applies
uniformly. Replacement already adds the extra exclusion (`excludeRuntimeNodeId` = former node) and a
tighter desired-state set (`['active']` for the probe). Retain those replacement-only exclusions;
otherwise identical eligibility + capacity policy. No separate replacement balancer.

## No-capacity behavior
Reuse the existing `conferences.failover_state = 'pending_no_capacity'` (DB check-constrained to null or
that value). Writers: `markConferenceFailoverPendingNoCapacity` (TelephonyDomainService, called by
`ConferenceFailoverCoordinator` L58 when `hasDistinctEligibleReplacement` is false). The conference
retains `desired_state='open'`; failover_binding_id/generation/started_at record the pending authority.
Extend the SAME state to **initial** placement: when `selectRuntimeNodeForConference` finds no eligible
node with `available>0` at open time, the open transition should record a durable pending state (rather
than today's 422 abort for the no-node case) so the conference stays desired-open and retries
automatically. Retry ownership is the persisted conference row + reconciliation, never an operator.

## Automatic retry triggers
`clearRecoveredFailoverPendingNoCapacity(graceSeconds)` (L994), scheduled via
`ConferenceFailoverCoordinator` L37, already re-activates pending conferences when their bound node
returns to `ready`/`degraded` or a recent ready `runtime_observations` row exists. Extend the wake
predicate to fire when **capacity frees** as well: a conference closes / active binding retires (slots
return), a RuntimeNode becomes ready or recovers from `events_degraded`, a node's `capacity_weight`
increases, or restoration/fence-clear makes a node eligible. Reuse the existing coordinator sweep +
reconciliation `ensureTarget`/`wakeTarget` and operation idempotency — do NOT add one scheduler or
command per trigger.

## Conflicting authority to remove
None. There is a single selector and a single binding writer; the one-active-binding partial unique index
is the durable guard. The only "conflict" is the **absence** of a capacity dimension in the one selector,
not a competing authority. Implementation extends `selectRuntimeNodeForConference` and its transaction in
place.

## First implementation slice
- Extend `selectRuntimeNodeForConference` into a capacity-aware, deterministically-ranked selector
  (hard filters incl. `available_slots>0`; ranking priority→available→load→id).
- Declared limit via `runtime_nodes.capacity_weight` (0/sentinel = unlimited for back-compat); derived
  usage via active-binding count.
- Per-node row lock (or advisory lock on node id) inside the existing placement transaction to prevent
  last-slot oversubscription; keep `writeBinding` + one-active index.
- Shared initial/replacement/probe policy (retain replacement-only exclusions).
- Durable `pending_no_capacity` for initial placement too; automatic wake on capacity/eligibility change
  via the existing coordinator sweep.
- Metrics: the existing `utcp_conference_failover_pending{pending_no_capacity}` gauge already covers the
  pending signal; optionally expose per-node available slots later. No new alert required.
Defer: weighted provider economics, predictive load, K8s autoscaling, multi-region affinity, media-relay
topology, queue fairness, per-tenant billing quotas, Provider Node Admin UI.

## Test contract
- Identical state always selects the same node (deterministic id tie-breaker).
- Ineligible nodes excluded (wrong tenant / desired_state / missing capability).
- `events_degraded` node excluded (observed_state != 'ready').
- Node with 0 available slots excluded.
- Least-loaded / most-available eligible node wins over a busier equal-priority node.
- Stable tie-breaker resolves equal candidates (lowest id).
- Concurrent final-slot requests on one node do not oversubscribe (row/advisory lock; loser goes pending
  or next candidate).
- Repeated open is idempotent (idempotency key + one-active index → no second active binding).
- Initial and replacement placement use the same policy.
- Failed/former node excluded during replacement (exclude arg).
- No eligible capacity → durable `pending_no_capacity`, conference stays desired-open.
- Capacity release (conference close/binding retire/node ready) wakes automatic retry; no manual path.
- Tenant isolation enforced (never selects another tenant's node).
- Simulator and Asterisk adapters both obey the same domain selector (no adapter-specific placement).

## Implementation-readiness decision — bounded Codex implementation
One canonical selector, one capacity unit (conference slots via capacity_weight + active-binding count),
one durable capacity source (active bindings), one reservation mechanism (placement transaction +
per-node lock + one-active index), one deterministic ranking (priority→available→load→id), one
no-capacity outcome (pending_no_capacity), automatic retry ownership (coordinator sweep + reconciliation),
and exact tests are all established. No blocker. Live capacity-exhaustion proof is not required before
implementation.

## Ready-to-paste next prompt (Codex — bounded implementation)
```
T5-A69 — Implement deterministic capacity-aware conference placement

Repo state: HEAD 4068362, branch main, clean, UTCP_PHASE=T1. Implement exactly the T5-A68 contract in
docs/evidence/t2/multi-node-failover-readiness.md. Do not begin V0. Keep UTCP_PHASE=T1. No manual
placement command, no Redis capacity authority, no process-local mutex, no feature gate. Do not push.

1. Capacity source: treat runtime_nodes.capacity_weight as the canonical per-node max active-conference
   slot count; 0 means unlimited (back-compat with today's behavior). Derived usage = COUNT of
   conference_runtime_bindings WHERE runtime_node_id=? AND status='active'. No new column/migration
   required (reuse capacity_weight); if a clearer name is warranted, add max_active_conferences via a
   registry migration defaulting to 0=unlimited and read that instead — pick one, document it.

2. Selector: extend TelephonyDomainService::selectRuntimeNodeForConference to (a) keep the current hard
   filters (tenant, desired_state, observed_state='ready', capabilities, exclude), (b) exclude nodes with
   0 available slots when a finite limit is set, (c) rank deterministically:
   placement_priority ASC, available_slots DESC, active_binding_count ASC, id ASC. Preserve the id
   terminal tie-breaker so equal-config state reduces to today's lowest-id choice.

3. Reservation: within the existing placement DB::transaction, take a row lock on the chosen
   runtime_nodes row (SELECT ... FOR UPDATE) or a pg advisory lock keyed on the node id before counting
   active bindings and calling writeBinding(); rely on the existing partial unique index
   conference_runtime_bindings_one_active for the per-conference guard. Loser of a last-slot race
   re-evaluates the next candidate, else pending_no_capacity.

4. No-capacity: when no eligible node has a free slot at initial open (changeConferenceDesiredState
   'open'), record durable failover_state='pending_no_capacity' authority (reuse
   markConferenceFailoverPendingNoCapacity / an initial-placement equivalent) with the conference
   remaining desired_state='open', instead of a hard 422 for the no-eligible-node case. Keep the 422
   only for genuinely invalid explicit runtime_node_id requests.

5. Automatic retry: extend clearRecoveredFailoverPendingNoCapacity (and the ConferenceFailoverCoordinator
   sweep) so pending conferences also re-attempt when capacity frees — conference close / active binding
   retire / node ready / events_degraded recovery / capacity_weight increase — reusing reconciliation
   ensureTarget/wakeTarget and operation idempotency. No new scheduler/command per trigger.

6. Alignment: initial, failover replacement, and hasDistinctEligibleReplacement all go through the same
   extended selector; retain replacement-only exclusions (former node, ['active'] desired states).

7. Tests (extend telephony-domain + asterisk-conference suites) — the full T5-A68 Test contract:
   deterministic identical-state selection; ineligible/events_degraded/zero-capacity exclusion;
   least-loaded/most-available wins; stable id tie-breaker; concurrent last-slot no oversubscription;
   idempotent repeat open; initial==replacement policy; former-node excluded on replacement; no-capacity
   durable pending; capacity-release wakes retry; tenant isolation; simulator+asterisk share the policy.

Verify: make repository-hygiene workflow-check secret-scan; make runtime-engine-config-check
telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check; make
runtime-engine-test telephony-domain-test asterisk-ari-test asterisk-conference-test
asterisk-conference-recovery-test; git diff --check. Commit
feat(t5): deterministic capacity-aware conference placement. Do not push. Then hand to Claude Code for a
controlled live capacity-exhaustion + automatic-retry proof.
```

## Verification performed (T5-A68)
Read-only: traced all placement entry points and the single selector + 3 call sites; enumerated hard
eligibility filters and the assert* revalidators; confirmed the id-based deterministic ordering;
inventoried capacity evidence (unused capacity_weight/placement_priority columns, active-binding count,
one-active partial unique index, runtime_observations as diagnostic, no declared limit anywhere);
confirmed no competing placement authority (participant admission inherits binding; simulator has none);
traced pending_no_capacity write + clearRecoveredFailoverPendingNoCapacity retry + coordinator schedule.
make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS. make *-test
(runtime-engine 21, telephony-domain 60, asterisk-ari 102, asterisk-conference 117,
asterisk-conference-recovery 97): PASS. git diff --check / --cached: clean. No live Conference/failover/
capacity/SQL/RuntimeNode/Kubernetes mutation performed.

# T5-A69 — Repository implementation: deterministic capacity-aware Conference placement

Status: repository implementation completed; controlled live capacity proof remains pending.

## Capacity semantics
`runtime_nodes.capacity_weight` is now the declared Conference-slot limit for the first capacity slice.
`capacity_weight > 0` means at most that many active Conference RuntimeBindings may reserve the node.
`capacity_weight = 0` means unlimited capacity for backward compatibility. Current usage is derived only
from `conference_runtime_bindings` rows with `status='active'`; participant count, channel count, PBX
bridge count, Kubernetes resources, provider economics, and runtime observations remain outside this
capacity unit.

## Canonical selector extension
`TelephonyDomainService::selectRuntimeNodeForConference()` remains the single Conference placement
selector. Initial open, failover replacement, replacement eligibility probes, and explicit RuntimeNode
binding all route through that selector. The selector preserves the existing hard filters for tenant,
desired state, `observed_state='ready'`, required capabilities, replacement exclusions, and requested
RuntimeNode constraints, then applies the capacity check as another hard eligibility condition. Non-ready
states, including `events_degraded`, remain excluded from new placement.

## Deterministic ranking and reservation
Eligible candidates are ranked explicitly by:

```text
placement_priority ASC
available slots DESC
active binding count ASC
RuntimeNode ID ASC
```

Unlimited-capacity nodes use a deterministic available-slot rank greater than any finite node. The
selector does not depend on natural database order, runtime response timing, Kubernetes Pod order,
hostnames, or randomness.

Placement still occurs inside the existing Conference transaction. The selector computes candidate usage
from active bindings, locks each selected `runtime_nodes` row with `FOR UPDATE`, revalidates tenant,
desired state, readiness, capabilities, replacement exclusion, and requested-node constraints, recounts
active bindings after the row lock, and only then returns the reservation candidate. If the candidate's
last slot was consumed by a concurrent transaction, the selector continues to the next deterministic
candidate. The existing active-binding writer and PostgreSQL one-active-binding constraint remain the
binding authority; idempotent placement reuses the existing active binding instead of consuming another
slot.

## Explicit-node boundary
An explicit `runtime_node_id` is treated as a constrained placement request. The requested node must pass
the same tenant, lifecycle, readiness, capability, exclusion, locking, and capacity checks. A full or
otherwise ineligible requested node returns the existing explicit-selection error behavior and does not
fall back to another node.

## Initial and replacement alignment
Initial placement and failover/restoration replacement now share the same capacity-aware selector and
reservation policy. Replacement retains only the established extra exclusions, including the failed or
retired former node and the active-only desired-state probe where already required. Participant admission
continues to inherit the Conference binding and performs no independent node selection.

## Durable pending-no-capacity and retry
Automatic initial open with no eligible capacity persists `conferences.failover_state =
pending_no_capacity`, keeps `desired_state='open'`, and creates no active RuntimeBinding. This is the
same durable pending state already used for replacement no-capacity, so the existing
`utcp_conference_failover_pending{failover_state="pending_no_capacity"}` observability remains the
current signal. The existing failover coordinator sweep now also reconsiders initial pending-no-capacity
Conferences by rerunning the canonical selector and binding writer. Capacity release through binding
retirement, Conference close, RuntimeNode readiness or `events_degraded` recovery, restoration, or a
capacity-weight increase can be picked up by the same idempotent sweep path without a manual command or
new scheduler.

## Focused test coverage
Repository tests now cover deterministic repeated selection, priority ordering, greater available
capacity ordering, lower active-binding count ordering, RuntimeNode ID tie-breaking, unlimited-capacity
ordering, exclusion of non-ready and `events_degraded` nodes, missing-capability exclusion, finite full
node exclusion, unlimited-node eligibility, tenant isolation, explicit-node capacity enforcement,
initial pending-no-capacity without an active binding, automatic retry after slot release, successful
pending retry clearing state and creating one active binding, idempotent repeated sweeps, replacement
former-node exclusion, final-slot recount with next-candidate fallback, idempotent repeated open, and
participant admission inheriting the Conference binding. Runtime registry tests cover `capacity_weight=0`
as the accepted unlimited slot value.

## Pending proof
No live capacity exhaustion, peer selection, pending-no-capacity dwell, slot release, or automatic retry
proof was performed in this repository-only slice. That controlled live proof remains pending.

---

# T5-A70 — Live proof: deterministic capacity exhaustion and automatic retry (97b56d6)

Verdict: `T5_DETERMINISTIC_CAPACITY_AND_PLACEMENT_LIVE_PROOF_COMPLETE`. Live proof against `utcp-local`
through canonical authenticated APIs. Baseline `UTCP_PHASE=T1`, clean tree at `97b56d6`. Evidence-only
doc change.

## Image rollout
Built canonical api image from clean `97b56d6` (verified capacity-aware selector present: 8 hits for
runtimeNodeHasConferenceCapacity/conferenceAvailableSlotRank/retryInitialPendingNoCapacityConference),
pushed sha256:ee2e6c7bc9e7472d03d00baae2b8e1ab42ac780cd5416f681ba8333d6d742b81. No migration in the
commit. Rolled out only the workloads executing the changed placement/coordinator code: api, scheduler,
worker, telephony-command-worker — all confirmed on digest ee2e6c7. Did not restart Asterisk/Kamailio/
PostgreSQL/Redis/Traefik. The failover coordinator runs `Schedule::command('telephony-domain:
failover-coordinator --once')->everyMinute()`, so automatic retry cadence is <=60s per sweep.

## Runtime baseline
Two asterisk-ari RuntimeNodes, both active/ready with conference capabilities: A=`1d15ca88` (Local
Asterisk ARI), B=`05ddb383` (Local Asterisk ARI B), tenant `7be59d2a-07c8-4b4e-a86d-c97771a670b9`. Both
Asterisk Deployments 1/1 (+ listener 1/1). 0 open/pending conferences, 0 active bindings, 0 actionable
operations, all simulators disabled.

## Original placement configuration
Node A: capacity_weight=10, placement_priority=100. Node B: capacity_weight=10, placement_priority=100.

## Temporary capacity configuration
Authenticated normally through the public gateway (app.utcp.local.test): GET /api/v1/auth/csrf → POST
/api/v1/auth/login (email/password; credential obtained via the canonical break-glass
`utcp:user-access:reset-password --show-password` for the existing `admin@utcp.local.test` tenant-admin)
→ POST /api/v1/auth/change-password (required) → POST /api/v1/auth/tenant-context. Session projection
confirmed `runtime.nodes.manage` + `telephony.conferences.manage`. No injected/DB/Redis session, no auth
bypass, no direct service invocation. Via PATCH /api/v1/admin/runtime-nodes/{id}: node A →
capacity_weight=1, placement_priority=10; node B → capacity_weight=1, placement_priority=20. Persisted
read model confirmed (A cw=1/pp=10 ready, B cw=1/pp=20 ready); capabilities and active/ready state
preserved. No direct SQL.

## Conference 1 placement
Created + opened a disposable Conference (no runtime_node_id) via POST /api/v1/admin/conferences +
/desired-state open. Result: desired_state=open, failover_state=null, bound to **node A** (preferred,
lowest priority 10). Usage: A=1/1, B=0/1, total active bindings=1, one binding, not pending.

## Conference 2 peer placement
Created + opened a second disposable Conference. Node A full → the selector **skipped the preferred
node** and bound to **node B**. Result: desired_state=open, failover_state=null, node=05ddb383. Usage:
A=1/1, B=1/1. No oversubscription.

## Capacity exhaustion / Pending-no-capacity state
Created + opened a third disposable Conference (no explicit node) with both nodes full. Open returned
HTTP 200 with desired_state=**open** and failover_state=**pending_no_capacity** (durable pending
lifecycle; no hidden fallback). Conf3 had **0 bindings**; A=1/1, B=1/1 (neither exceeded one active
binding); pending total=1. The `utcp_conference_failover_pending{failover_state="pending_no_capacity"}`
metric read **1** after the next Prometheus scrape.

## Oversubscription proof
At every step neither node exceeded its 1-slot limit: after Conf1 A=1/B=0; after Conf2 A=1/B=1; after
Conf3 A=1/B=1 with Conf3 unbound; after retry A=1/B=1 with Conf3 holding the single freed A slot. Total
active bindings never exceeded 2 (the two available slots).

## Slot release
Closed Conference 1 via POST /api/v1/admin/conferences/{id}/desired-state {closed} at 01:51:59 UTC
(HTTP 200). Conference 1 → desired_state=closed; its active RuntimeBinding retired through the canonical
reconciliation lifecycle (asynchronous). Node A usage observed dropping to 0/1 at ~01:53:17 once the
binding retired. Conference 3 remained desired=open + pending_no_capacity until automatic retry. No row
deletion or direct state mutation.

## Automatic retry
No manual coordinator/reconciliation command was invoked. The scheduled everyMinute failover coordinator
sweep automatically re-attempted the initial-pending conference once node A freed. Timeline (release
01:51:59): pending held while A still occupied; A freed ~01:53:17; **Conf3 automatically placed on node
A at ~01:54:18** — failover_state cleared to null, one active binding on 1d15ca88, A=1/1, B=1/1.
Elapsed release→automatic placement ≈ **2m19s** (bounded by the everyMinute coordinator + binding-retire
reconciliation). Exactly one active binding for Conf3 (1 total, no duplicate operation/binding); node B
unchanged at 1/1; the pending-no-capacity metric returned to **0** after the next scrape. The retry used
the same canonical `selectRuntimeNodeForConference` + `writeBinding` path (no separate balancer).

## Explicit-node boundary
Not repeated live; covered by focused tests. (An exploratory explicit-node request did not cleanly
execute and created no stray state — verified no `t5a70-explicit-%` conference row and total active
bindings unchanged. The explicit-selection/no-fallback boundary is covered by the focused
telephony-domain/asterisk-conference regression suites.)

## Cleanup and configuration restoration
Closed Conferences 2 and 3 via the normal authenticated API (HTTP 200 each); all proof bindings retired
through reconciliation (proof active bindings → 0). Restored node A and node B to the original
capacity_weight=10, placement_priority=100 via PATCH (HTTP 200). No direct SQL.

## Final runtime state
Both RuntimeNodes ready with restored cw=10/pp=100; both Asterisk Deployments 1/1 (+ listener 1/1);
0 open proof conferences; 0 pending; 0 active bindings (total); 0 actionable operations; all simulators
disabled. Network/config fault-free; no disposable resource remains.

## Divergences
None material. Binding retirement and pending-retry are asynchronous (reconciliation/coordinator
cadence), so the release→placement latency (~2m19s) reflects the everyMinute schedule, not a defect —
the principal claims (preferred→peer→pending→automatic retry, no oversubscription, no manual
reconciliation) all held. The admin runtime-node GET projection does not surface capacity_weight/
placement_priority under those key names (persisted values confirmed via the DB read and honored by the
selector); not a placement defect.

## Verification performed (T5-A70)
Canonical API login/change-password/tenant-context; Admin API capacity config + conference create/open/
close; DB read-only observation of bindings/usage/pending; Prometheus pending-metric queries. make
repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS. make *-test
(runtime-engine 21, telephony-domain 66, asterisk-ari 102, asterisk-conference 123,
asterisk-conference-recovery 97): PASS. git diff --check / --cached: clean. No manual coordinator/
reconciliation invocation, no direct SQL write, no RuntimeNode/Kubernetes mutation beyond the canonical
Admin API capacity config (restored), no Pod scale/delete, no Asterisk/Kamailio restart.

---

# T5-A71 — Audit: deterministic Kamailio signaling cutoff authority (evidence-only)

Verdict: `T5_KAMAILIO_SIGNALING_CUTOFF_CONTRACT_NOT_DEFINED` — one precise material blocker. Narrow
evidence-only audit; no production code changed. Baseline `UTCP_PHASE=T1`, clean tree at `d8a53e9`.

## Material blocker (decisive)
The contract's objective — "ensure Kamailio cannot route new SIP signaling to a RuntimeNode UTCP has
made ineligible" — presupposes a Kamailio→RuntimeNode SIP routing authority. **That routing authority
does not exist in the repository.** Kamailio's `request_route` (kamailio-configmap.yaml) handles only
REGISTER (+ an OPTIONS keepalive) and returns `405 Method Not Allowed` for every other method including
INVITE. There is no `dispatcher` module loaded, no destination/dispatcher table, no `lookup("location")`
for inbound INVITE, and no `t_relay` to any runtime. Per ADR-019 (Accepted for T1): "T1 does not add
Asterisk signaling, media, or conference execution … those remain T2/T3." Conference participants reach
Asterisk via **ARI originate** (control-plane HTTP to `asterisk-ari*:8088`, Local channels), not via
routed SIP; Asterisk Services expose only `ari=8088/TCP` (no SIP/5060 listener, no UDP), and
`scripts/kamailio-signaling/runtime-proof` itself asserts `no_asterisk_sip_scope=true`. A "new-dialog
signaling cutoff" therefore has nothing to cut off: there is no routed SIP application-dialog path to a
RuntimeNode. The invariant is currently satisfied **by construction** (Kamailio cannot route to any
runtime, and no direct SIP path to Asterisk exists), but that is the absence of the routing feature, not
an implementable T5 cutoff contract.

## Registration authority
Kamailio is the sole live SIP registrar (T1/ADR-019). `request_route`: maxfwd + sanity_check → reject
non-`sip.utcp.local.test` domains (403) → OPTIONS keepalive (200) → non-REGISTER (405) → `www_authorize`
against DB view `kamailio_signaling_auth_view` → auth-identity match (`$au == $fU`) → `save("location")`
into `usrloc` (db_mode 1, use_domain 1). WSS is terminated at Traefik and upgraded via the
`event_route[xhttp:request]` websocket path. **Signaling-eligibility authority for registration is C5**:
`TelephonySession` + `telephony_signaling_credentials` (PostgreSQL) — one active session issues one
short-lived SIP credential; `kamailio_signaling_auth_view` is the read model Kamailio authenticates
against. RuntimeNode state does NOT participate in registration.

## New-dialog routing authority
None. Kamailio does not route SIP application dialogs to any RuntimeNode (405 on INVITE). The only
path by which a caller's media/among-participants reaches a telephony runtime today is control-plane
**ARI originate** (TelephonyDomainService → AsteriskAriClient → `POST /channels` originate with Local
channels), selected by the canonical `selectRuntimeNodeForConference` placement authority (T5-A68/69)
and bound via `conference_runtime_bindings`. This is not SIP signaling and does not traverse Kamailio.

## Existing-dialog authority
N/A. No SIP dialogs are routed through Kamailio to runtimes (no Record-Route/loose_route/dialog module
for application dialogs; the config has no in-dialog routing). Existing-dialog preservation is moot until
the routing path exists.

## Current RuntimeNode signaling eligibility
RuntimeNode `desired_state ∈ {draft, active, draining, disabled}`; `observed_state ∈ {unobserved,
unknown, connecting, ready, degraded, events_degraded, unavailable, stale}`. The canonical placement
eligibility (T5-A69) is `desired ∈ {active,draining} AND observed='ready' AND capacity AND capability`.
**None of these states currently affect any SIP signaling decision** — verified: no
`eligible_for_*sip*` / `routable` / signaling-eligibility concept referencing RuntimeNode exists in the
codebase. RuntimeNode state affects only ARI-driven conference placement/recovery.

## Cutoff state contract
Cannot be defined as implementable today (no routing authority to bind it to). The intended future
mapping (for when the T2/T3 routing path exists) would consume the SAME shared eligibility contract as
placement: active+ready → eligible for new SIP application dialogs; draining+ready → existing dialogs
continue, new excluded; degraded/stale/unavailable/fenced/disabled → new excluded; events_degraded →
excluded from event-observation-dependent runtime behavior but NOT marked absent; recovered active+ready
→ eligibility restored automatically. This is documented as the target, not an implementable slice now.

## Existing-dialog preservation
Deferred with the routing path. When routing exists, the deterministic policy should be: cut off new
dialogs at the route-selection authority; preserve established dialogs (Record-Route/route-set pinning)
and let the canonical Conference/failover lifecycle decide termination or replacement; never delete
routes to force-drop live calls.

## Projection authority
Today UTCP projects only registration OBSERVATION from Kamailio, not routing eligibility TO Kamailio:
`KamailioRegistrationObserver` is a read-only poller of the `location` (usrloc) rows → normalized
receipts → UTCP projection (one-directional, Kamailio→UTCP). There is no UTCP→Kamailio routing
projection (no dispatcher rows, no generated route include, no reconciliation operation writing routes)
because there are no routes. A future cutoff would need a NEW projection authority (e.g. dispatcher/
destination rows or a generated include reconciled from the shared eligibility contract) — that is part
of the deferred routing-path work.

## Automatic restoration
N/A today. Registration eligibility "restoration" is credential/session issuance (C5), already
automatic and RuntimeNode-independent. Routing-eligibility restoration is deferred with the routing path.

## Projection failure and retry
N/A for routing (no projection). The registration observer already has durable poll-health
(`kamailio_registration_poll_health`) + checkpoint + lease + automatic retry via the scheduled observer
(everyMinute), and metrics/alerts (`kamailio_registration_*`). No routine operator reload command exists
for signaling; `kamailio-registration:observer` runs on the scheduler.

## Conflicting authority to remove
None found. There is no static Asterisk destination, no dispatcher fallback, no unconditional failover
route, no direct Asterisk SIP listener, no browser/client config pointing around Kamailio, and no
direct-runtime SIP selection in application code. Asterisk Services expose only ARI 8088/TCP; the
`allow-kamailio-signaling` NetworkPolicy permits ingress only from Traefik→8080 and egress only to
postgres + DNS. So there is no stale/conflicting signaling authority to destroy — the current posture is
already conflict-free (by absence of routing).

## Direct runtime bypass
None. Participants reach Asterisk exclusively via control-plane ARI originate; no SIP path to Asterisk
exists (no SIP listener, no Service port, no NetworkPolicy allowance). No bypass to remove.

## First implementation slice
Not applicable as a T5 signaling-cutoff slice. Current T1/T5 posture is registration-only Kamailio plus
no route to execution RuntimeNodes, so the runtime signaling cutoff is presently satisfied by
construction. This is not a permanent product non-goal: future SIP application-dialog routing remains a
roadmap requirement. Internal browser/conference routing belongs to T3/V0 when the browser media path
introduces `registered browser SIP identity -> Kamailio -> selected Conference execution runtime ->
rtpengine media`. External-trunk and general-call routing belongs to C6/C7/T6/V1 when canonical call
intent, route/trunk decision, eligible runtime selection, and projected Kamailio signaling execution
exist. In both future paths, new-dialog route selection must consume canonical eligibility so the cutoff
is intrinsic from day one.

## Test contract
Deferred with the routing path. When implemented in T3/V0 or C6/C7/T6/V1, the routing tests apply
(ready+active routable;
draining/events-degraded per policy; degraded/unavailable/stale/fenced/disabled excluded;
existing-dialog correctness; registration stays with Kamailio; automatic restoration; idempotent
projection retry; no stale authoritative projection; no static bypass; tenant isolation; no direct
Asterisk bypass; no manual reconciliation; no feature gate). None are implementable against the current
registration-only config.

## Live acceptance contract
Deferred. The §14 controlled proof (eligible→routes, made-ineligible→no new routing, existing dialog
follows contract, restored→resumes) requires the routing path first; it cannot be exercised against a
registrar that 405s all non-REGISTER methods.

## Implementation-readiness decision — blocked (neither bounded implementation nor more evidence)
The evidence is conclusive, so additional Claude Code evidence is NOT needed. A bounded Codex
implementation of a signaling *cutoff* is NOT possible, because the SIP routing authority the cutoff
would constrain does not exist. The active T5 gap classification is therefore removed. Runtime routing
is not retired: it is re-sequenced to the phases that create routing authority, with intrinsic cutoff as
part of those phases.

## Verification performed (T5-A71)
Read-only: Kamailio route-authority trace (request_route = REGISTER/OPTIONS only, 405 otherwise;
event_route xhttp websocket upgrade); registrar-vs-runtime-route separation (registrar = Kamailio+usrloc
with C5 TelephonySession/credential eligibility; runtime routing = none, ARI originate instead);
RuntimeNode state-to-signaling trace (no state affects signaling; no signaling-eligibility concept
references RuntimeNode); static/fallback route inventory (none — no dispatcher module, no destination
table, no generated route include); Asterisk direct-access inventory (Services expose only ARI 8088/TCP;
no SIP listener; runtime-proof asserts no_asterisk_sip_scope=true); projection/reconciliation trace
(observer is read-only usrloc→UTCP; no UTCP→Kamailio routing projection); existing-dialog behavior trace
(N/A — no routed dialogs); automatic restoration trace (registration eligibility via C5, automatic;
routing N/A); manual reload/reconciliation surface scan (none for signaling routing); feature-gate/
allowlist scan (none); tenant-isolation trace (registration realm + auth-identity match; RuntimeNode
routing N/A); phase-marker inspection (UTCP_PHASE=T1). NetworkPolicy confirms Kamailio ingress
Traefik→8080, egress postgres+DNS only.
make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS. make *-test
(runtime-engine 21, telephony-domain 66, asterisk-ari 102, asterisk-conference 123,
asterisk-conference-recovery 97): PASS. git diff --check / --cached: clean. No cluster/Kamailio/Asterisk/
RuntimeNode/registration/Conference/PostgreSQL/Redis mutation.

---

# T5-A72 — Resequenced Kamailio signaling cutoff to routing phases

Verdict: current T5 cutoff gap removed; future routing cutoff preserved under the phases that create
routing authority. Documentation-only correction at `UTCP_PHASE=T1`; no production code, Kamailio
configuration, Kubernetes manifests, database schema, RuntimeNode behavior, registration behavior,
Conference execution, Asterisk, rtpengine, or tests changed.

## Current registration authority
T1 remains complete and registration-only. Kamailio owns REGISTER authentication and `usrloc` contact
authority using the PostgreSQL-backed signaling credential view. Registration eligibility is C5/T1
TelephonySession and short-lived credential authority. RuntimeNode execution readiness, capacity,
fencing, and placement do not participate in REGISTER authentication or contact storage.

## Current runtime-routing authority
No current Kamailio-to-runtime dialog route exists. Kamailio has no INVITE relay, dispatcher/runtime
destination selection, application-dialog Record-Route path, or SIP route to Asterisk or another
RuntimeNode. Current Conference execution reaches Asterisk through control-plane ARI operations and
canonical `conference_runtime_bindings`, not through Kamailio SIP dialog routing.

## T5 classification correction
The current T5 `Kamailio signaling cutoff` gap is not an implementable runtime gap. With registration-only
Kamailio and no route to execution RuntimeNodes, the runtime signaling cutoff is satisfied by construction
for the current T1/T5 posture. T5-A71 is retained as evidence of the absent route authority, not as an
unresolved blocker. The old active-gap classification is destroyed; do not retain it as a deferred T5
cutoff, optional hardening item, disabled rule, or blocked current-state audit.

## Future internal application-dialog routing
UTCP still intends future SIP application-dialog routing through Kamailio. Internal browser/conference
routing is assigned to T3/V0, where the browser media path must introduce the minimum route:

```text
registered browser SIP identity
-> Kamailio
-> selected Conference execution runtime
-> rtpengine media
```

That route must consume canonical RuntimeNode eligibility for new dialogs. An ineligible execution
RuntimeNode is excluded from new application-dialog routing. Registration eligibility remains separate
from execution-runtime eligibility. T3/V0 must define and prove initial INVITE route authority, runtime
destination projection, new-dialog eligibility, existing-dialog behavior, Record-Route and in-dialog
routing where required, automatic cutoff and restoration, and no direct Asterisk bypass.

## Future external-trunk and general-call routing
External-trunk and general-call routing are assigned to C6/C7/T6/V1:

```text
canonical call intent
-> canonical route and trunk decision
-> eligible runtime selection
-> projected Kamailio signaling execution
```

The projected destination set must be derived from UTCP authority. Kamailio executes the route but must
not become tenant, trunk, caller-identity, or runtime-eligibility management authority. Ineligible,
fenced, unavailable, retired, or otherwise disallowed destinations must be absent or disabled in the
projected new-dialog route set, and automatic projection restoration follows canonical recovery.

## Roadmap reconciliation
The initial end-to-end roadmap may retain broad product-scope language for dispatcher, trunk, Asterisk,
and application-dialog routing. The implementation roadmap remains the sequencing authority, and ADRs
plus completed-phase evidence remain implemented-authority records. Existing phase-identifier
reconciliation supersedes the initial plan's broad T1 sequencing without inventing another phase
numbering scheme.

---

# T5-A73 — Contract: conference-recovery metric-event retention and pruning (evidence-only)

Verdict: `T5_CONFERENCE_RECOVERY_EVENT_RETENTION_CONTRACT_DEFINED`. Narrow evidence-only audit; no
production code changed. Baseline `UTCP_PHASE=T1`, clean tree at `2620225`.

## Recovery data stores
The in-scope store is exactly **one** table: `conference_recovery_metric_events`
(migration 2026_07_17_140000). Columns: `id` (char32 PK), `adapter_key`, `resource_type`, `result`,
`failure_class`, `reason`, `created_at`, `updated_at`. Indexes: `created_at`, `(resource_type,result)`,
`(adapter_key,result)`. **No foreign keys in or out; no tenant/conference/participant/node/operation
IDs** — only bounded enum-ish diagnostic columns. Writer: `RuntimeConferenceInspectionService::
recordInspectionMetric()` — insert-only, best-effort, wrapped in try/catch that swallows all errors with
the comment "Recovery telemetry is diagnostic evidence; it must not affect reconciliation authority."
Every row is immutable and terminal at insert (never updated, never reopened).
Classification of adjacent stores (explicitly OUT of this slice):
- `runtime_operations` / operation attempts — **canonical lifecycle state + idempotency + retry
  authority** (read by ReconciliationWorker, IdempotencyStore). Not pruneable telemetry.
- `control_plane_outbox_messages` — **canonical audit/event evidence** (also read by MetricsController
  for failover/binding-retired/reclaim counters). Canonical; separate retention question, not this slice.
- `runtime_event_receipts` / `runtime_event_connection_epochs` / `runtime_listener_leases` /
  `conference_runtime_bindings` — canonical reconciliation/listener/binding authority. Not in scope.
Only `conference_recovery_metric_events` is a pure derived metric-event source with no runtime authority.

## Canonical state versus historical evidence
- Canonical active state: none in this table (no row ever gates a runtime decision).
- Terminal audit evidence: every row (terminal-on-insert diagnostic inspection outcome).
- Derived metric events: 100% of rows — used solely to derive Prometheus counters.
- Disposable duplicate evidence: the aggregate meaning (counts by adapter/resource/result/failure/reason)
  is what matters; individual raw rows past a retention window are disposable once their contribution to
  the bounded alert windows has elapsed.
No second summary store is required for correctness IF the cumulative-counter queries are corrected (see
Metric preservation) — the compact canonical value each metric needs is derivable from the retained
recent window, and the historical trend lives in Prometheus's own TSDB, not in the source rows.

## Consumers and dependencies
Verified the ONLY readers are `MetricsController` (three methods) — no runtime service, reconciler,
idempotency check, admin API, or FK references the table (grep confirms only writer +
MetricsController). Answer to "Can deletion of a row change a future runtime decision?": **No.** Deletion
affects only the value of three Prometheus metric families and (transitively) two 10-minute-window
alerts. Consumers:
- `conferenceRecoveryInspectionMetrics()` → `utcp_conference_runtime_inspections_10m`
  (COUNT(*) GROUP BY adapter_key,resource_type,result,failure_class — **whole-table cumulative**).
- `conferenceRecoveryInspectionFailureMetrics()` → `utcp_conference_runtime_inspection_failures_10m`
  (COUNT(*) WHERE result IN (unavailable,failed) GROUP BY ... — **whole-table cumulative**).
- `runtimeReferenceHealthMetrics()` → `utcp_conference_runtime_reference_health_10m`
  (COUNT(*) GROUP BY resource_type,reason mapped to health — **whole-table cumulative**; this is the
  T5-A62 corrected aggregation).
Alerts: `UTCPTelephonyConferenceRuntimeInspectionFailures` = `sum(increase(...inspection_failures_total
[10m])) > 3`; `UTCPAsteriskAriReferenceFamilyDegraded` = `sum(increase(...reference_health_total
{health="degraded_unavailable"}[10m])) > 3`. Both use `increase()` over a 10m window.

## Terminal eligibility
Every row is terminal at insert (no status column, no retry, no reconciliation reuse, no idempotency
participation, no child records, immutable). Therefore the eligibility predicate collapses to pure age:
```
row.created_at < now() - retention_cutoff  → eligible for pruning
```
There is no "still-retryable" or "still-active" subset to protect within this table. (The generic guard
"terminal + no retry/reconciliation authority + no active dependency + older than cutoff" is satisfied
for every row once older than the cutoff, because the first three conditions hold for all rows by
construction.) The one operational constraint: the cutoff must be strictly larger than the alert
evaluation window so pruning can never remove rows an active `increase([10m])` alert still needs.

## Current growth evidence (read-only, live)
Live `conference_recovery_metric_events`: ~14,327 rows spanning OLDEST 2026-07-17 12:31 → NEWEST
2026-07-21 01:54 (~3.5 days); LAST_24H = 4,526; LAST_1H = 47 (~1.1k–4.5k/day, steady ~47/hr under idle
proof load). Results: observed = 10,827, unavailable = 3,500 (plus smaller failed/unsupported). Existing
`created_at` index already supports an age-based retention query. The table grows unbounded today and the
three counters scan the entire history on every scrape (increasingly expensive). No other in-scope table
has accumulated stale proof data at this rate.

## Existing retention conventions
No existing prune/retention service, no chunked-delete helper, and no retention config key for durable
tables exist (only `logging.php` daily-file `days` and `runtime_engine.stale_observation_seconds`, which
is a marker not a deleter). The reusable convention is the **thin-command + bounded-sweep + scheduler**
pattern: e.g. `runtime-engine:derive-stale-observations` (→ `ProjectionService::markStale(config(
'runtime_engine.stale_observation_seconds',300))`, scheduled `everyFiveMinutes()->withoutOverlapping()`)
and `telephony-domain:expire-sessions` / `retire-closed-bindings` / `reclaim-orphan-participant-channels`
(each a thin console command delegating to a domain method, scheduled `everyMinute()->
withoutOverlapping()`). This slice reuses that exact shape; there is no generic retention framework and
none should be added.

## Retention authority and defaults
Retention here is operational infrastructure policy (diagnostic telemetry volume), NOT tenant/operator
product configuration, so it belongs in stable application config with a validated deterministic default,
mirroring `runtime_engine.stale_observation_seconds`. Add one key, e.g.
`runtime_engine.conference_recovery_metric_event_retention_days` (default **7**), validated as a positive
integer with a sane floor (must exceed the 10m alert window by a wide margin; 7 days keeps roughly a week
of raw diagnostic history — ~30–35k rows at current rate — for investigation while bounding growth).
Single record family (one table) → one default; no arbitrary global multi-table duration needed. No env
feature gate, no allowlist, no per-tenant control (the table has no tenant column).

## Pruning ownership and schedule
One automatic owner: a new thin console command `runtime-engine:prune-conference-recovery-metric-events
--once` delegating to a single idempotent service method (e.g. `RuntimeConferenceInspectionService::
pruneExpiredMetricEvents(retentionDays, batchSize, maxBatches)` or a dedicated small pruner), scheduled
`Schedule::command('...--once')->hourly()->withoutOverlapping()`. Hourly is proportional (age-based, ~4.5k
rows/day; no need for per-minute). `withoutOverlapping()` provides single-owner/overlap protection at the
scheduler; no DB lease is required because deletes are age-filtered and idempotent (a row deleted by one
run is simply absent for the next). Redis is NOT the deletion authority.

## Batch and transaction behavior
Bounded batches: delete up to `batch_size` (e.g. 1,000) eligible rows per statement, loop up to
`max_batches` (e.g. 10) per run → at most ~10k rows/run, then stop and let the next hourly run continue
(catch-up is automatic since the age filter re-selects). Each batch is its own short transaction
(`DELETE FROM conference_recovery_metric_events WHERE id IN (SELECT id ... WHERE created_at < cutoff
ORDER BY created_at LIMIT batch_size)` or equivalent keyset delete on the `created_at` index). No long
unbounded deletion transaction in the scheduler. Concurrent inserts are unaffected (new rows have
`created_at >= now()`, never within the `< cutoff` predicate).

## Dependency-safe deletion
Trivial: the table has no foreign keys in either direction and no child/parent evidence tables, so there
is no ordering constraint and no cascade. The pruner deletes only from `conference_recovery_metric_events`
and touches nothing else. Proven cannot delete: active RuntimeOperations, current RuntimeBindings, open
Conferences, pending recovery work, listener epochs, idempotency authority, or alert-required rows — none
of those live in this table, and the age cutoff (>> 10m alert window) protects the rows the two
`increase([10m])` alerts still read.

## Metric and alert preservation (the one design decision)
Classification of the three current recovery-event metrics:
- `utcp_conference_runtime_inspections_10m` — a Prometheus **gauge** over rows created during the
  preceding ten minutes.
- `utcp_conference_runtime_inspection_failures_10m` — same bounded-window gauge semantics.
- `utcp_conference_runtime_reference_health_10m` — same bounded-window gauge semantics.
The alerts evaluate the current gauge value directly; they do not use `increase()` or another counter
function. No compact summary table is needed under the window-bounded correction.

## Pruning observability
Add bounded metrics from durable pruner state (all labels bounded enums, no IDs/tenant/table-from-input/
raw-exception):
- `utcp_recovery_metric_prune_runs_total{result}` (result ∈ succeeded|failed|noop).
- `utcp_recovery_metric_prune_rows_total{}` (cumulative rows pruned — genuinely monotonic).
- `utcp_recovery_metric_prune_eligible_backlog{}` (gauge: COUNT rows older than cutoff — current state).
- `utcp_recovery_metric_prune_oldest_age_seconds{}` (gauge: now - min(created_at)).
- `utcp_recovery_metric_prune_last_success_timestamp{}` (gauge) — optional.
One alert only, for a demonstrated failure mode: eligible-backlog growth, e.g.
`utcp_recovery_metric_prune_eligible_backlog > <threshold> for 30m` (warning), meaning automatic pruning
is falling behind. Annotation must NOT instruct running a manual prune command (recovery is automatic via
the hourly schedule). No per-run durable table is required if the backlog gauge derives from the same
age predicate the pruner uses.

## Failure and retry behavior
The pruner is isolated from runtime authority: a prune failure cannot block Conference operation,
recovery, placement, listener processing, or API readiness (it runs in the scheduler, touches only the
diagnostic table, and the writer already tolerates the table's absence). On failure: log + increment
`prune_runs_total{result="failed"}`, do not partially corrupt (each batch is transactional), and the next
hourly run retries (age predicate re-selects). Single-table scope means there is no cross-family
corruption risk. Do not silently skip permanently-failing rows without evidence — a persistently growing
`eligible_backlog` gauge + failed-run counter surface the condition.

## First implementation slice
- Age-only terminal eligibility predicate (`created_at < now() - retention_days`).
- Validated config default `runtime_engine.conference_recovery_metric_event_retention_days` = 7 (positive
  int, floored well above the alert window).
- One idempotent pruner method + thin console command
  `runtime-engine:prune-conference-recovery-metric-events --once`, scheduled `hourly()->
  withoutOverlapping()`.
- Bounded batch deletion (batch_size 1000, max_batches 10/run) on the existing `created_at` index.
- Correct the three MetricsController recovery queries to a bounded reference window (<= retention).
- Bounded pruning metrics + one eligible-backlog alert.
- Focused tests.
Defer: external archive/object storage, compliance immutable archives, tenant-configurable retention,
CDR retention, table partitioning, cross-region archival, general data-lifecycle framework, and any
retention policy for the canonical `runtime_operations`/`control_plane_outbox_messages` stores (separate
future question).

## Test contract
- Terminal row inside retention is preserved; terminal row older than cutoff is pruned.
- (Vacuously) no active/retryable row is pruned — assert a just-inserted row (created_at=now) is never
  eligible even with retention_days=0-guard-floor.
- Idempotency/runtime authority intact: pruning N rows does not change any runtime_operations/binding/
  conference state (assert counts unchanged) and no FK error occurs.
- Dependency-safe deletion succeeds (single table, no cascade) — delete runs cleanly.
- Batch limit enforced: with >batch_size*max_batches eligible rows, one run deletes exactly the cap and
  leaves the remainder; a second run deletes more (catch-up).
- Repeated runs idempotent: running twice with no new eligible rows deletes 0 the second time.
- Concurrent new rows not deleted: insert a fresh row mid-retention, prune, assert it survives.
- Failure in a batch is observable/retryable (simulate a delete error → failed-run metric increments,
  no partial-corruption, next run proceeds).
- Scheduler wiring: assert the command is registered and scheduled `hourly withoutOverlapping` (no manual
  enablement, no feature gate).
- Metrics bounded labels only; the three recovery metrics equal the bounded-window count before and
  after pruning rows outside that window (semantics unchanged); `increase([10m])` alert inputs unchanged.
- Tenant isolation: N/A here (no tenant column) — assert the table has none so no tenant leakage is
  possible; document that this store is tenant-agnostic diagnostic telemetry.
Use a controllable clock (Carbon::setTestNow) rather than real sleeps to age fixtures.

## Live acceptance contract (deferred — do not run now)
1. Insert disposable terminal recovery metric events via the normal inspection path (or a local proof
   fixture), including some aged beyond the cutoff using Carbon::setTestNow / a safe fixture (not direct
   production-row edits).
2. Preserve active/recent events (created within the window).
3. Run the normal scheduled `runtime-engine:prune-conference-recovery-metric-events` lifecycle (no manual
   invocation beyond triggering the scheduled command).
4. Prove bounded deletion (only >cutoff rows removed, batch cap respected, backlog gauge falls).
5. Prove the three recovery metrics and the two increase([10m]) alerts remain correct after pruning.
6. Leave no disposable state (remove proof rows).

## Implementation-readiness decision — bounded Codex implementation
Exact record family (one table), canonical-vs-pruneable authority (100% pruneable diagnostic, no runtime
dependency, no FK), terminal eligibility (pure age), retention default (7 days), scheduling owner (hourly
withoutOverlapping thin command + idempotent service), dependency-safe deletion (single table),
batch/retry behavior, metric preservation (window-bound the three cumulative queries), exact tests, and
live acceptance are all established. No blocker.

## Ready-to-paste next prompt (Codex — bounded implementation)
```
T5-A74 — Implement conference-recovery metric-event retention and pruning

Repo state: HEAD 2620225, branch main, clean, UTCP_PHASE=T1. Implement exactly the T5-A73 contract in
docs/evidence/t2/multi-node-failover-readiness.md. Do not begin V0. Keep UTCP_PHASE=T1. No feature gate,
no allowlist, no manual-enablement; normal pruning runs via the scheduler. Do not push. Scope is ONLY
conference_recovery_metric_events; do not touch runtime_operations or control_plane_outbox_messages
retention.

1. Config: add runtime_engine.conference_recovery_metric_event_retention_days (default 7) and a bounded
   reference window runtime_engine.conference_recovery_metric_reference_window_days (default 1) —
   validated positive ints, reference_window << retention_days and both >> the 10m alert window. Follow
   the config-check validation pattern used for stale_observation_seconds.

2. Pruner: add an idempotent service method (RuntimeConferenceInspectionService::
   pruneExpiredMetricEvents(int retentionDays, int batchSize=1000, int maxBatches=10): array or a small
   dedicated pruner) that deletes conference_recovery_metric_events rows with created_at < now()-retention
   in bounded batches on the created_at index (keyset/LIMIT delete), each batch its own transaction, at
   most batchSize*maxBatches rows/run; returns {rows_deleted, batches, eligible_remaining}. No long
   unbounded transaction; new rows (created_at>=now) never match.

3. Command + schedule: thin console command runtime-engine:prune-conference-recovery-metric-events
   {--once}, delegating to the service, printing rows_deleted; Schedule::command('...--once')->hourly()
   ->withoutOverlapping(). No DB lease needed.

4. Metric correction: bound the three MetricsController recovery queries
   (conferenceRecoveryInspectionMetrics, conferenceRecoveryInspectionFailureMetrics,
   runtimeReferenceHealthMetrics) to created_at > now()-reference_window so pruning rows outside the
   window never changes their value; the two increase([10m]) alerts stay correct. Keep the existing
   bounded-label/aggregation behavior (incl. the T5-A62 map-then-aggregate for reference_health).

5. Pruning observability (MetricsController): add utcp_recovery_metric_prune_eligible_backlog (gauge =
   COUNT rows older than cutoff), utcp_recovery_metric_prune_oldest_age_seconds (gauge), and, if a
   durable per-run record is added, utcp_recovery_metric_prune_runs_total{result} +
   utcp_recovery_metric_prune_rows_total. Bounded labels only; no IDs/tenant/table-from-input/raw-error.
   Add one alert: eligible_backlog > <threshold> for 30m (warning), annotation without any manual-prune
   instruction.

6. Tests (telephony/runtime-engine + asterisk-conference-recovery suites): the full T5-A73 Test contract
   — inside/outside-retention, just-inserted never eligible, runtime state unchanged after prune, batch
   cap + catch-up, idempotent repeat, concurrent-new-row survives, failure observable/retryable,
   scheduler wiring (hourly withoutOverlapping, no gate), bounded-window metric semantics unchanged
   across prune, increase([10m]) inputs unchanged, tenant-agnostic (no tenant column). Use
   Carbon::setTestNow to age fixtures, not sleeps.

Verify: make repository-hygiene workflow-check secret-scan; make runtime-engine-config-check
telephony-domain-config-check asterisk-ari-config-check asterisk-conference-config-check; make
runtime-engine-test telephony-domain-test asterisk-ari-test asterisk-conference-test
asterisk-conference-recovery-test; git diff --check. Commit
feat(t5): prune conference-recovery metric events with bounded retention. Do not push. Then hand to
Claude Code for the T5-A75 controlled live retention/pruning proof.
```

## Verification performed (T5-A73)
Read-only: recovery data-store inventory (single table conference_recovery_metric_events; schema/indexes;
no FK); consumer + FK trace (only writer RuntimeConferenceInspectionService + reader MetricsController;
no runtime/idempotency/reconciler reader; deletion cannot change a runtime decision); canonical-vs-
historical classification (runtime_operations/outbox/receipts/epochs/bindings canonical and out of scope;
this table 100% diagnostic); metric+alert dependency trace (three whole-table cumulative counters; two
increase([10m]) alerts); existing pruning-pattern inventory (no prune service/chunked-delete/retention
config; reuse derive-stale-observations/expire-sessions thin-command+scheduler pattern); scheduler +
overlap-protection trace (Schedule::command(...--once)->everyN->withoutOverlapping); retention-config
scan (none for durable tables; stale_observation_seconds precedent); manual-command + feature-gate scan
(none required; thin scheduled command only); phase-marker inspection (UTCP_PHASE=T1); live read-only
growth inspection (~14.3k rows / 3.5 days, ~4.5k/day, observed 10827 + unavailable 3500).
make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4): PASS. make *-test
(runtime-engine 21, telephony-domain 66, asterisk-ari 102, asterisk-conference 123,
asterisk-conference-recovery 97): PASS. git diff --check / --cached: clean. No cluster/DB/runtime/
Conference/RuntimeBinding/PostgreSQL/Redis mutation.

# T5-A74 conference-recovery metric-event retention implementation

## Scope and authority boundary

T5-A74 implements bounded automatic retention for exactly one diagnostic table:
`conference_recovery_metric_events`.

The table remains diagnostic metric evidence only. It is not authority for Conference lifecycle,
RuntimeBindings, runtime operations, recovery decisions, reconciliation, idempotency, event receipts,
event epochs, RuntimeNode state, or outbox delivery. The pruning service deletes from no adjacent
canonical table and no runtime recovery path reads pruning results.

## Retention configuration

Application configuration now defines:

- `runtime_engine.conference_recovery_metric_event_retention_days` = 7.
- `runtime_engine.conference_recovery_metric_event_prune_batch_size` = 1000.
- `runtime_engine.conference_recovery_metric_event_prune_max_batches_per_run` = 10.

The runtime-engine config check validates these as positive integer application defaults. They are not
environment-variable gates, tenant policy, runtime allowlists, or hidden opt-in switches. Seven days is
safely greater than the ten-minute alert window.

## Pruning behavior

`ConferenceRecoveryMetricEventPruner` computes one cutoff snapshot per run:

`created_at < now() - retention_days`

It then deletes oldest eligible rows first in short transactional batches, ordered by `created_at, id`,
with at most 1000 rows per batch and 10 batches per run. If the run cap is reached and eligible rows
remain, the result is `backlog_remaining`; the next hourly run continues from the same age predicate.
Rows exactly at the cutoff or newer are retained. Recently inserted diagnostic rows remain ineligible.

Each batch is transactional. A failed batch rolls back that batch, earlier committed batches remain valid,
the command exits nonzero, and the next scheduled run retries automatically. The service returns bounded
structured fields: result, rows deleted, batches completed, remaining-backlog flag, and cutoff.

## Command and schedule

The thin command is:

`runtime-engine:prune-conference-recovery-metric-events --once`

It delegates to the pruner, logs bounded structured fields, prints a deterministic status, and exits
nonzero on invalid configuration or pruning failure. It has no tenant, retention override, dry-run, or
manual activation option.

The scheduler runs it hourly with `withoutOverlapping()`. Normal retention pruning therefore requires no
operator command, manual reconciliation, feature gate, Redis authority, or database lease.

## Metric semantic correction

The previous diagnostic-table metric families were whole-table counts declared as counters. That was
invalid once old diagnostic rows became pruneable.

T5-A74 removes those whole-history counter families from production code and replaces them with
ten-minute rolling-window gauges:

- `utcp_conference_runtime_inspections_10m`
- `utcp_conference_runtime_inspection_failures_10m`
- `utcp_conference_runtime_reference_health_10m`

Each gauge counts matching `conference_recovery_metric_events` rows created during the preceding ten
minutes. Values may naturally decrease as rows leave the window. No replacement metric has a `_total`
suffix. Scrape queries perform only PostgreSQL reads and no PBX, Kubernetes, Redis, runtime, WebSocket,
or ARI I/O.

Label values are bounded before emission. Runtime reference health still maps resource type and health
classification before aggregation, so raw ARI reasons do not become labels and duplicate final label sets
are not emitted.

## Alert correction

The two recovery-event alerts now evaluate the current rolling-window gauges directly:

- `UTCPTelephonyConferenceRuntimeInspectionFailures`: `sum(utcp_conference_runtime_inspection_failures_10m) > 3`
- `UTCPAriReferenceFamilyDegraded`: `sum(utcp_conference_runtime_reference_health_10m{health="degraded_unavailable"}) > 3`

They no longer use `increase()` against values derived from the pruneable diagnostic table. Pruning rows
older than seven days cannot remove rows required by the ten-minute alert window.

## Pruning observability

MetricsController adds durable current-state gauges:

- `utcp_conference_recovery_metric_event_prune_eligible_backlog`
- `utcp_conference_recovery_metric_event_prune_oldest_age_seconds`

Both are computed from the same age predicate as the pruner. They use no labels. Empty backlog reports
zero age. No process-local cumulative pruning counters are exposed.

The new alert is:

`UTCPConferenceRecoveryMetricEventPruneBacklog`

It fires when `utcp_conference_recovery_metric_event_prune_eligible_backlog > 10000` for 30 minutes with
severity warning. Its annotation states that automatic retention pruning is falling behind and will retry
on the next hourly schedule. It does not instruct an operator to run the pruning command manually.

## Focused test coverage

Focused tests cover:

- Seven-day age eligibility, cutoff exclusivity, recent-row retention, and alternate retention cutoff.
- Batch size and max-batch caps, remaining-backlog reporting, catch-up on later runs, idempotent repeats,
  and oldest-first deletion.
- Runtime authority preservation for adjacent operation and outbox tables.
- Thin command execution, deterministic nonzero failure on invalid configuration, and hourly
  `withoutOverlapping()` scheduler wiring.
- Ten-minute rolling-window gauge semantics, absence of old `_total` diagnostic-table metric families,
  bounded labels, pruning backlog gauges, and alert expressions that do not use `increase()` on gauges.

Focused commands already observed passing during repository implementation:

- `php artisan test --filter=ConferenceRecoveryMetricEventPrunerTest`
- `php artisan test --filter=MetricsEndpointTest`

Controlled live proof of scheduled aged-row pruning, recent-row preservation, metric correctness, and
final cleanup remains pending. This repository update does not claim live scheduled pruning has already
been observed.

---

# T5-A75 — Live proof: scheduled recovery-event retention and pruning (3f89dc0)

Verdict: `T5_CONFERENCE_RECOVERY_EVENT_RETENTION_LIVE_PROOF_COMPLETE`. Controlled live proof against
`utcp-local`. Baseline `UTCP_PHASE=T1`, clean tree at `3f89dc0`. Evidence-only doc change.

## Image and alert rollout
Built canonical api image from clean `3f89dc0` (verified pruner + _10m metrics + prune command baked in),
pushed sha256:80b5847da2f27d598152ee09a71f18970fa2f40014d3ddbc0a8cbcf51a8cc466. No migration in the
commit. Rolled out only api + scheduler (both = api image; scheduler executes the scheduled prune
command); confirmed both on digest 80b5847. Applied the changed PrometheusRule. Did not restart
Asterisk/Kamailio/PostgreSQL/Redis/Traefik/rtpengine/unrelated workers.

## Scheduler baseline
`schedule:list` in the new scheduler pod: `0 * * * * php artisan runtime-engine:
prune-conference-recovery-metric-events --once` (Next Due ~33 min → 04:00 UTC). Scheduler runs
`php artisan schedule:work` (long-running), so the hourly entry fires at minute 0. api 1/1, scheduler 1/1.

## Metric contract
Live /api/metrics (internal 8081) and Prometheus confirmed the replacement gauges present with one HELP +
one TYPE=gauge each: `utcp_conference_runtime_inspections_10m`, `utcp_conference_runtime_inspection_
failures_10m`, `utcp_conference_runtime_reference_health_10m`, `utcp_conference_recovery_metric_event_
prune_eligible_backlog`, `utcp_conference_recovery_metric_event_prune_oldest_age_seconds`. The previous
whole-history counters `utcp_conference_runtime_inspections_total` / `_inspection_failures_total` /
`_reference_health_total` are ABSENT from the metrics output AND from Prometheus series (0 each). Alerts
now evaluate the gauges DIRECTLY (no increase()): `UTCPTelephonyConferenceRuntimeInspectionFailures` =
`sum(utcp_conference_runtime_inspection_failures_10m) > 3`; `UTCPAriReferenceFamilyDegraded` =
`sum(utcp_conference_runtime_reference_health_10m{health="degraded_unavailable"}) > 3`;
`UTCPConferenceRecoveryMetricEventPruneBacklog` = `utcp_conference_recovery_metric_event_prune_eligible_
backlog > 10000` (loaded, health=ok, inactive).

## Fixture boundary
Baseline (pre-fixture): table TOTAL=14,327 rows, eligible-backlog=0 (oldest existing row 2026-07-17, only
~4 days old vs 7-day cutoff), 10m inspections gauge = only the {none,none,none,none} 0 placeholder. Live
proof direct-insert was confined to the single noncanonical `conference_recovery_metric_events` table
(no FK, no runtime consumer); no other table was written. Unique marker `t5_a75_<suffix>` stored only in
the diagnostic `reason` column (which is NOT a Prometheus label on the inspections_10m gauge, so the
marker never became a label). Chosen bounded combination adapter_key=simulator-deterministic (maps to
bounded label `other`), resource_type=conference, result=observed, failure_class=none — a zero-baseline
low-noise series while simulators are disabled.

## Aged fixture
12 rows inserted in one transaction at created_at = now-8d (2026-07-13 03:52:09), all older than the
7-day cutoff (2026-07-14 03:52). Captured all 12 IDs.

## Recent fixture
4 rows inserted in the same transaction at created_at = now (2026-07-21 03:52:09), inside retention and
inside the 10-minute metric window. Captured all 4 IDs
(38bc1fdd…, 0d5fa88b…, 21b36022…, d091cded…). Fixture created at 03:52, ~8 min before the 04:00 prune, so
the recent rows remained inside the 10m window at prune time.

## Pre-prune backlog
At 03:52 (before the 04:00 run): all 16 captured IDs present; the 12 aged satisfy created_at < cutoff, the
4 recent do not; DB eligible-backlog = 12 (baseline 0 → +12 exactly); live gauge
`prune_eligible_backlog = 12`, `prune_oldest_age_seconds = 691237` (~8 days); the fixture 10m series
`utcp_conference_runtime_inspections_10m{adapter_key="other",resource_type="conference",result="observed",
failure_class="none"} = 4` (baseline 0 → +4, the recent rows only); the 12 aged rows contributed 0 to the
10m window (MARKER_AGED_IN_10M=0). No Conference/binding/operation/RuntimeNode state changed
(OPEN=0 BIND=0 OPS=0 NODES_READY=2).

## Scheduled pruning invocation
The normal `schedule:work` scheduler invoked the command automatically — NOT run manually. Scheduler log:
`2026-07-21 04:00:01 Running ['artisan' runtime-engine:prune-conference-recovery-metric-events --once]
298.89ms DONE`. This natural hourly run is the deletion authority.

## Selective deletion
After the 04:00:01 run: all 12 captured aged IDs ABSENT (AGED_STILL_PRESENT=0); all 4 captured recent IDs
PRESENT (RECENT_STILL_PRESENT=4); marker total now 4 (only the recent rows). Eligible-backlog returned to
the pre-fixture baseline 0 (no unrelated rows were yet eligible). No unbounded deletion (batched pruner,
12 rows « batch cap). No non-fixture recent row deleted (table TOTAL after prune = 14,331 = 14,327
baseline + 4 surviving recent). No canonical table changed.

## Recent-row preservation
The 4 recent fixture rows survived pruning (still present by captured ID), confirming retention-window
protection.

## Rolling-window metric behavior
Immediately post-prune (04:01): the fixture 10m gauge series still read 4 — deleting the 12 aged (8-day-old)
rows did NOT change it (the aged rows were never inside the 10m window). The metric remained declared a
gauge; no `_total` replacement series appeared. `prune_eligible_backlog` = 0, `prune_oldest_age_seconds`
= 0 after pruning. All three recovery/prune alerts remained inactive + health=ok.

## Alert behavior
`UTCPTelephonyConferenceRuntimeInspectionFailures`, `UTCPAriReferenceFamilyDegraded`, and
`UTCPConferenceRecoveryMetricEventPruneBacklog` all inactive, health=ok, empty lastError before, during,
and after the proof. No alert applied a counter function to a gauge.

## Cleanup
Deleted only the 4 remaining recent fixture rows by their exact captured IDs (whereIn id, not a time
predicate). After cleanup: 0 rows with the proof marker; all 16 captured IDs absent; table TOTAL back to
14,327 (the exact pre-fixture baseline); eligible-backlog = 0; the fixture 10m series gone (back to the
{none} placeholder at 0) after the next scrape.

## Runtime authority preservation
No Conference, RuntimeBinding, RuntimeOperation, RuntimeNode, receipt, epoch, or outbox state changed at
any point (OPEN=0 BIND=0 OPS=0 NODES_READY=2 throughout). Pruning touched only the diagnostic table.

## Final runtime state
Both Asterisk Deployments 1/1 (asterisk-ari, asterisk-ari-b) + listener 1/1; api 1/1, scheduler 1/1;
0 open/pending conferences; 0 active bindings; 0 actionable operations; 2 RuntimeNodes ready;
prune_eligible_backlog = 0; recovery/prune alerts inactive+healthy; table at pre-fixture baseline; no
disposable state; port-forward stopped; no temporary shell session left.

## Divergences
None. The fixture 10m series carries `adapter_key="other"` rather than `simulator-deterministic` because
the inspections_10m metric maps adapter_key through its bounded vocabulary — expected bounded-label
behavior, not a defect; the series count (4 = the recent rows) is exactly correct. Cleanup delete-by-ID
output line was buffered off screen but the follow-up MARKER_REMAINING=0 and TOTAL=14,327 confirm the
deletion.

## Verification performed (T5-A75)
Natural scheduled prune observed (no manual command as principal proof); scheduler log captured; row-level
selective-deletion proof by captured IDs; live metric + Prometheus gauge/alert queries; canonical-state
checks throughout. make repository-hygiene / workflow-check / secret-scan: PASS. make *-config-check (4):
PASS. make *-test (runtime-engine 25, telephony-domain 66, asterisk-ari 102, asterisk-conference 123,
asterisk-conference-recovery 97): PASS. git diff --check / --cached: clean. Direct inserts/deletes were
confined to the noncanonical conference_recovery_metric_events table (fixture rows only, by ID); no other
Kubernetes/runtime/Conference/RuntimeBinding/PostgreSQL/Redis/Asterisk/Kamailio mutation.
