# C6 — Canonical Call Lifecycle and Normalized Call-Control Contract

## Verdict

    C6_CANONICAL_CALL_CONTROL_CONTRACT_COMPLETED

Implementation decision:

    C6 CONTRACT COMPLETE — BOUNDED IMPLEMENTATION READY

The decisive finding is that **C6 needs far less new machinery than the roadmap
nouns suggest**. The control-plane kernel already provides a generic operation
authority, a generic append-only observation authority with deduplication and
epoch/generation fencing, a capability-gated operation-handler contract, and a
two-method runtime adapter interface. C6 Wave 1 therefore adds **two tables, one
enum set, eighteen operation types, six capability keys, six permissions and one
read projection** — and no new lifecycle framework of any kind.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  clean apart from this evidence packet
    commit/push:   none created, not pushed

Contract/evidence only: no source, no migrations, no routes, no controllers.
RH-3 untouched; T4 and C7 not begun.

## Current C6 Repository Reality

Established by direct inspection, not by roadmap heading:

| Concern | Reality |
|---|---|
| `calls`, `call_legs`, `call_operations` tables | **ABSENT** (42 canonical tables; none call-related) |
| Call domain code | **ABSENT** — `app/TelephonyDomain/` holds only conference/session/signaling |
| Generic operation authority | **PRESENT** — `runtime_operations` (kernel table, `2026_07_14_090000`) |
| Generic observation authority | **PRESENT** — `runtime_observations` |
| Operation handler contract | **PRESENT** — `RuntimeOperationHandler` with `operationType()`, `payloadVersion()`, **`requiredRuntimeCapability()`**, `execute($operation, ?RuntimeAdapter)` |
| Runtime adapter contract | **PRESENT and already normalized** — `RuntimeAdapter::adapterKey()` + `execute(array $operation): array` |
| Capability enforcement | **PRESENT** — `CommandWorker:151-152` rejects when `requiredRuntimeCapability()` is absent from `runtime_node_capabilities` |
| Failure taxonomy | **PRESENT** — `FailureClass` enum, 11 cases including `UnsupportedCapability` |
| Unsupported-operation behaviour | **PRESENT** — `AsteriskRuntimeAdapter::execute()` `match` default returns `FailureClass::UnsupportedCapability` |
| Observation dedup | **PRESENT** — unique `(source_event_id, observation_type, subject_type, subject_id)` |
| Replay/reconnect fencing | **PRESENT** — `source_connection_epoch`, `runtime_event_connection_epochs`, `runtime_event_receipts` |
| Generation fencing | **PRESENT** — `observed_generation` monotonic guard, `ProjectionService:381-389` |
| Exact-current fencing idiom | **PRESENT** — `AsteriskConferenceParticipantBinder` with `BOUND / ALREADY_BOUND / RETRYABLE / TERMINAL` |
| Desired/observed state idiom | **PRESENT** — `conferences`, `conference_participants`, `runtime_nodes` |
| Termination-column idiom | **PRESENT** — `telephony_sessions` has `termination_reason` + `failure_class` + `failure_code` |
| DTMF / playback / recording execution | **ABSENT** — zero matches under `apps/api/app/`; `recording` is a *declared capability with no operation behind it* |
| Simulator determinism | **PRESENT** — scenario scheduling incl. `duplicate-observation`, `disconnect-reconnect`, `configuration-drift-then-converge` |

## Canonical Authority Matrix

| CONCEPT | CANONICAL OWNER | PERSISTENT? | MUTABLE? | ADAPTER OWNED? | DERIVED? | NOTES |
|---|---|---|---|---|---|---|
| **Call** | UTCP call domain | **Yes — new table `calls`** | Yes | No | No | Logical correlation + desired/observed state |
| **CallLeg** | UTCP call domain | **Yes — new table `call_legs`** | Yes | No | No | One signaling/runtime leg; owns runtime correlation |
| **CallParticipant** | — | **No** | — | No | — | **DEFERRED / removed from Wave 1** (see decision) |
| **CallOperation** | control-plane kernel | Yes — **reuse `runtime_operations`** | Yes | No | No | New `operation_type` values; `aggregate_type` = `call`/`call_leg` |
| **CallObservation** | runtime-engine projection | Yes — **reuse `runtime_observations`** | Append-only | Adapter *emits*, domain *decides* | No | `subject_type` = `call`/`call_leg` |
| **CallRouteDecision** | **C7 (later)** | Wave 1: nullable snapshot columns on `calls` | Write-once | No | No | C6 records the outcome; never computes policy |
| **CallTermination** | UTCP call domain | Columns on `calls`/`call_legs` | Write-once | No | No | Mirrors `telephony_sessions` termination columns |
| **CallTimelineEntry** | — | **No table** | — | No | **Yes** | Read projection over operations + observations + audit |
| **TelephonySession** | C1/C5 (unchanged) | Yes (existing) | Yes | No | No | Authorization only — never dialog/media authority |
| **Conference** | C5 (unchanged) | Yes (existing) | Yes | No | No | Remains canonical through Wave 1 |
| **ConferenceParticipant** | C5 (unchanged) | Yes (existing) | Yes | No | No | Remains canonical through Wave 1 |

## Persistence Model

### Tables to create — exactly two

**`calls`** — why required: no existing aggregate correlates multiple legs, and
outbound intent must exist before any runtime object does.

    id (uuid, pk)
    tenant_id (uuid, fk tenants, restrict)
    direction ('outbound' | 'inbound')
    desired_state ('active' | 'terminated')
    observed_state (see state machine)
    runtime_node_id (uuid, nullable, fk runtime_nodes)   -- selected/observing node
    route_decision (json, nullable)                       -- C7 seam, opaque snapshot
    route_decision_source (string, nullable)              -- 'c7' | 'fixture' | null
    destination_ref (string, nullable)                    -- C7 seam, opaque
    caller_identity_ref (string, nullable)                -- C7 seam, opaque
    requested_by_user_id (uuid, nullable, fk users)
    correlation_id (char32)                               -- kernel convention
    answered_at / terminated_at (timestamptz, nullable)
    termination_reason (string 80, nullable)
    termination_party ('local' | 'remote' | 'system', nullable)
    failure_class (string 80, nullable) / failure_code (string 120, nullable)
    timestamps
    indexes: (tenant_id, observed_state), (tenant_id, created_at), (runtime_node_id)

**`call_legs`** — why required: runtime correlation, per-leg control state
(hold/mute/bridge) and the inbound adoption fence all live here.

    id (uuid, pk)
    tenant_id (uuid, fk tenants, restrict)
    call_id (uuid, fk calls, restrict)
    runtime_node_id (uuid, fk runtime_nodes)
    runtime_channel_id (string, nullable)      -- adapter correlation, NOT semantics
    direction ('outbound' | 'inbound')
    role ('originator' | 'destination' | 'consultation')
    desired_state ('active' | 'terminated')
    observed_state (see state machine)
    observed_generation (bigint, nullable)     -- monotonic fence, mirrors runtime_nodes
    held (bool, default false) / muted (bool, default false)
    bridged_to_leg_id (uuid, nullable, self-fk)
    bridged_at (timestamptz, nullable)
    telephony_session_id (uuid, nullable, fk telephony_sessions)
    user_id (uuid, nullable, fk users)
    remote_identity (string, nullable)         -- opaque; C7 refines later
    media_ref (string, nullable) / recording_ref (string, nullable)
    answered_at / terminated_at (timestamptz, nullable)
    termination_reason / termination_party / failure_class / failure_code
    timestamps

    KEY FENCING CONSTRAINTS
      unique (runtime_node_id, runtime_channel_id) WHERE runtime_channel_id is not null
        -> the inbound-adoption idempotency fence and the duplicate-channel guard
      index (call_id), (tenant_id, observed_state), (telephony_session_id)

Tenant scope: both tables are `tenant_id`-scoped with restrict-on-delete, matching
`conferences` / `conference_participants`.

Terminal behaviour: terminal rows are **retained**, never hard-deleted — the same
lifecycle-history rule the conference domain follows.

### Roadmap nouns that must NOT become tables

    CallOperation      -> runtime_operations (would be a duplicate operation authority)
    CallObservation    -> runtime_observations (would be a duplicate observation authority)
    CallTermination    -> columns (write-once), mirroring telephony_sessions
    CallRouteDecision  -> nullable snapshot columns; the entity belongs to C7
    CallTimelineEntry  -> read projection (would duplicate canonical event storage)
    CallParticipant    -> deferred entirely (see decision)
    Bridge             -> relationship column (see decision)

## Call vs CallLeg

**Call** = logical, application-facing correlation object and the unit of intent,
authorization, route decision and audit. **CallLeg** = exactly one signaling/runtime
leg, and the only place a `runtime_channel_id` may appear.

| Case | Model |
|---|---|
| Single-leg outbound | 1 call, 1 leg (`role=destination`, `direction=outbound`) |
| Single-leg inbound | 1 call, 1 leg (`role=originator`, `direction=inbound`), created by adoption |
| Two-party bridged | 1 call, 2 legs, symmetric `bridged_to_leg_id` |
| Conference attachment | **Wave 1: not represented** — conference stays canonical (see cutover) |
| Replacement runtime leg | New `call_legs` row; old leg terminated with `termination_reason='replaced'`; call unchanged — mirrors the proven RH-2 replacement-leg pattern |
| Blind transfer | Transferee leg redirected; transferor leg terminated `reason='transferred'`; new leg adopted under the **same** call |
| Attended transfer | Consultation leg (`role=consultation`) exists during the operation; on completion the two surviving legs bridge and the transferor leg terminates |
| Hangup | Leg → `terminating` → `completed`; call terminates when its last active leg does |
| Runtime disconnect | Leg → `failed` via observation or reconciliation; call terminates with `termination_party='system'` |

Speculative call forking, N-way non-conference bridges and multi-call trees are
**out of Wave 1** — no roadmap acceptance requires them.

## CallParticipant Decision

    DEFERRED — REMOVE FROM C6 WAVE 1 CONTRACT

Evidence: `conference_participants` already owns participation intent for the only
multi-party construct that exists, and it is load-bearing for the frozen RH-1/2/3
recovery corridor. A generic `CallParticipant` in Wave 1 would own **no authority
that `call_legs` does not already own** — identity association is already expressible
as `telephony_session_id`, `user_id` and `remote_identity` columns on the leg.

A participant entity earns its place only when one identity must span multiple legs
across multiple calls (agents, queue members) — which is queue/ACD territory,
explicitly deferred by the post-RH-3 audit. Creating it now would be the "universal
Participant abstraction" this packet is required to reject.

## Bridge Decision

    DERIVED — no canonical Bridge aggregate in Wave 1

Evidence: the repository already has a durable bridge-like aggregate for the
multi-party case (`conferences` + `conference_runtime_bindings`), and two-party
bridging carries no state that a symmetric `bridged_to_leg_id` + `bridged_at`
relationship plus a `call.leg.bridged` observation cannot express. A durable Bridge
aggregate would duplicate the conference aggregate for N-way cases and add an empty
row for every two-party call.

Revisit only if N-way non-conference bridging becomes an acceptance target.

## Timeline Decision

    DERIVED READ PROJECTION — no `call_timeline_entries` table

The three canonical sources already exist and are already append-only:
`runtime_operations` (requested control), `runtime_observations` (observed facts) and
`control_plane_audit_records` (actor-attributed decisions). A timeline table would be
a fourth copy of the same events — the duplicated canonical event storage this packet
must avoid. Expose it as `GET /calls/{id}/timeline`, ordered by
`coalesce(observed_at, occurred_at, created_at)`, with a `source` discriminator.

## Canonical State Machine

Provider-neutral. No Asterisk/FreeSWITCH channel state and no SIP transaction state
appears in the canonical set.

```text
OUTBOUND ENTRY
  REQUESTED ──C──▶ SELECTING_ROUTE ──R──▶ ORIGINATING ──O──▶ RINGING
                                                   │            │
                                                   │            ├──O──▶ EARLY_MEDIA
                                                   │            │
                                                   └────────────┴──O──▶ ANSWERED

INBOUND ENTRY
  OFFERED ──O──▶ RINGING ──O/C──▶ ANSWERED
     │
     └──C──▶ TERMINATING            (reject / no local answer)

COMMON ACTIVE  (leg-scoped unless noted)
  ANSWERED ◀──▶ BRIDGED         (bridge / unbridge)
  ANSWERED ◀──▶ HELD            (hold / resume — LEG ONLY)
  ANSWERED ──▶ TRANSFERRING ──▶ ANSWERED | TERMINATING

TERMINAL
  TERMINATING ──▶ COMPLETED | FAILED | CANCELLED
```

Legend — every transition is exactly one of:

* **C = COMMAND-REQUESTED** — an accepted `runtime_operations` row moved desired state.
  `REQUESTED→SELECTING_ROUTE`, `OFFERED→TERMINATING` (reject), `answer`, `hangup`,
  `hold`, `bridge`, transfer initiation.
* **O = OBSERVATION-CONFIRMED** — a fenced `runtime_observations` row moved observed
  state. `ORIGINATING→RINGING`, `→EARLY_MEDIA`, `→ANSWERED`, `→BRIDGED`, `→COMPLETED`,
  `→FAILED`, DTMF, media and recording status.
* **R = CANONICAL RECONCILIATION** — a UTCP worker decided.
  `SELECTING_ROUTE→ORIGINATING` (route + runtime eligibility resolved),
  stale-leg convergence, grace expiry, orphan cleanup, `→FAILED` when a runtime
  vanishes without a terminal event.

`termination_reason` (not extra states) carries `no_answer`, `busy`, `rejected`,
`transferred`, `replaced`, `runtime_lost`, `requested`, `grace_expired`. This keeps
the state set minimal while preserving policy correctness.

`HELD`, `muted`, and `bridged_to_leg_id` are **leg** properties. The call's
`observed_state` is the aggregate: it is `ANSWERED` while any leg is answered, and
terminal only when every leg is terminal.

## Outbound Entry

    POST /calls (authorized, Idempotency-Key)
      -> calls row (direction=outbound, desired_state=active, observed_state=REQUESTED)
      -> call_legs row (role=destination, observed_state=REQUESTED)
      -> runtime_operations row (operation_type='call.leg.originate',
         aggregate_type='call_leg', aggregate_id=<leg>, idempotency_key=<request key>)
      -> CommandWorker claims, checks requiredRuntimeCapability, dispatches to adapter
      -> adapter returns runtime_channel_id correlation
      -> observations drive RINGING / EARLY_MEDIA / ANSWERED

`SELECTING_ROUTE` is entered only when a route decision is required. In Wave 1, with
no C7, the domain records `route_decision_source='fixture'` (simulator) or leaves it
null for a directly-addressed runtime, and transitions straight to `ORIGINATING`.

## Inbound Entry

When UTCP first learns of a call it did not originate:

1. The adapter observes an inbound channel with no known correlation and emits a
   normalized `call.leg.offered` observation carrying `runtime_node_id`,
   `runtime_channel_id`, `remote_identity`, `called_address` (opaque string),
   `occurred_at`, plus the kernel's `source_event_id` and `source_connection_epoch`.
2. **Canonical identity is allocated by the domain, never by the adapter.** The
   adoption path inserts `calls` + `call_legs` inside one transaction, fenced by the
   partial unique index on `(runtime_node_id, runtime_channel_id)`. A duplicate or
   replayed offer collides on that index and is a no-op — this is the same
   first-writer-wins idiom `AsteriskConferenceParticipantBinder` already uses.
3. **Tenant context** comes from `runtime_nodes.tenant_id` of the observing node,
   which is already tenant-scoped. This is the honest Wave-1 answer; C7 later refines
   it by resolving the called `TelephonyAddress` to a tenant.
4. **State before C7 exists**: the call is adopted as `OFFERED` with
   `destination_ref = null`. C6 defines the seam and does not resolve it. An
   unresolved offer is answerable and hangupable by an authorized application — that
   is precisely what the A0 inbound/IVR consumers need — but it is not routed.
5. **Simulator must produce**: a scheduled inbound offer scenario emitting
   `call.leg.offered` with a synthetic `called_address` and `remote_identity`, plus
   the existing duplicate/replay scenarios pointed at that observation.

C6 must not implement `TelephonyAddress` or `DestinationRef`. It reserves
`destination_ref` and `route_decision` as opaque nullable fields written by whoever
owns the decision.

## Operation Authority

    REUSE `runtime_operations`. Do NOT create a `call_operations` table.

`runtime_operations` is **not** infrastructure-only — it is already the telephony
operation authority for `conference.ensure`, `conference.close`,
`conference.participant.ensure` and `conference.participant.remove`. Every field C6
needs already exists:

| Requirement | Existing column |
|---|---|
| idempotency key authority | `idempotency_key` + unique `(operation_type, idempotency_key)` |
| requested_by actor | `payload.requested_by_user_id` + `request_id` + `control_plane_audit_records` |
| target Call / CallLeg | `aggregate_type` ∈ {`call`,`call_leg`} + `aggregate_id` |
| operation type | `operation_type` (160 chars) |
| requested state | `payload` + `payload_version` |
| execution state | `status`, `started_at`, `completed_at`, `cancelled_at`, lease columns |
| provider correlation | `runtime_node_id` + result `event_payload.runtime_channel_id` |
| success/failure result | `last_failure_class` / `last_failure_code` / `last_failure_message` |
| retry semantics | `attempt_count`, `max_attempts`, `available_at`, `expires_at` |
| audit | `correlation_id`, `causation_id`, `request_id` + audit records |

Creating a parallel table would introduce a second operation authority, which
`CLAUDE.md` prohibits outright.

## Normalized Operation Matrix

`RC` = required capability. All handlers implement the existing
`RuntimeOperationHandler`. `ASYNC` = completion proven by observation rather than by
the adapter return value.

| OPERATION | TARGET | RC | ADAPTER METHOD | ASYNC | CANONICAL COMPLETION EVIDENCE | FAILURE CLASS |
|---|---|---|---|---|---|---|
| `call.leg.originate` | CALL LEG | `call.origination` | `execute(op)` | Yes | `call.leg.ringing` / `answered` | RuntimeUnavailable, InvalidRequest, Timeout |
| `call.leg.cancel_origination` | CALL LEG | `call.origination` | `execute(op)` | Yes | `call.leg.terminated` (`cancelled`) | Conflict (already answered) |
| `call.leg.answer` | CALL LEG | `call.control` | `execute(op)` | Yes | `call.leg.answered` | Conflict, InvalidRequest |
| `call.leg.hangup` | CALL LEG | `call.control` | `execute(op)` | Yes | `call.leg.terminated` | RuntimeUnavailable |
| `call.hangup` | CALL | `call.control` | fan-out to legs | Yes | all legs terminated | RuntimeUnavailable |
| `call.leg.hold` | CALL LEG | `call.hold` | `execute(op)` | Yes | `call.leg.held` | UnsupportedCapability, Conflict |
| `call.leg.resume` | CALL LEG | `call.hold` | `execute(op)` | Yes | `call.leg.resumed` | UnsupportedCapability, Conflict |
| `call.legs.bridge` | RELATIONSHIP (two legs) | `call.control` | `execute(op)` | Yes | `call.leg.bridged` ×2 | Conflict, InvalidRequest |
| `call.legs.unbridge` | RELATIONSHIP | `call.control` | `execute(op)` | Yes | `call.leg.unbridged` ×2 | Conflict |
| `call.leg.blind_transfer` | CALL LEG | `call.transfer` | `execute(op)` | Yes | transferor `terminated(transferred)` + new leg adopted | UnsupportedCapability, InvalidRequest |
| `call.leg.attended_transfer` | RELATIONSHIP | `call.transfer` | `execute(op)` | Yes | surviving legs `bridged`, transferor terminated | UnsupportedCapability, Conflict |
| `call.leg.redirect` | CALL LEG | `call.transfer` | `execute(op)` | Yes | `call.leg.redirected` | UnsupportedCapability, InvalidRequest |
| `call.leg.mute` | CALL LEG | `call.control` | `execute(op)` | Yes | `call.leg.muted` | UnsupportedCapability |
| `call.leg.unmute` | CALL LEG | `call.control` | `execute(op)` | Yes | `call.leg.unmuted` | UnsupportedCapability |
| `call.leg.send_dtmf` | CALL LEG | `call.dtmf.send` | `execute(op)` | Yes | `call.leg.dtmf_sent` | UnsupportedCapability, InvalidRequest |
| `call.leg.play_media` | CALL LEG | `media.playback` | `execute(op)` | Yes | `call.leg.media_started` → `media_finished` | UnsupportedCapability, InvalidRequest |
| `call.leg.stop_media` | CALL LEG | `media.playback` | `execute(op)` | Yes | `call.leg.media_stopped` | UnsupportedCapability |
| `call.leg.start_recording` | CALL LEG | `recording` | `execute(op)` | Yes | `call.leg.recording_started` | UnsupportedCapability |
| `call.leg.stop_recording` | CALL LEG | `recording` | `execute(op)` | Yes | `call.leg.recording_stopped` | UnsupportedCapability |

Adapters never choose target semantics: the handler validates the target and the
preconditions before the adapter is called.

## Observation Model

    REUSE `runtime_observations`. Append-only. No new table.

    subject_type      'call' | 'call_leg'
    subject_id        canonical UTCP id (never a provider channel name)
    observation_type  'call.leg.offered' | 'ringing' | 'early_media' | 'answered'
                      | 'bridged' | 'unbridged' | 'held' | 'resumed' | 'muted'
                      | 'unmuted' | 'redirected' | 'dtmf_received' | 'dtmf_sent'
                      | 'media_started' | 'media_finished' | 'media_stopped'
                      | 'recording_started' | 'recording_stopped'
                      | 'terminated' | 'failed'
    observed_state    normalized leg state
    occurred_at       -> existing `observed_at` (runtime clock)
    observed_at       -> existing `received_at`  (UTCP clock)
    dedup key         existing unique (source_event_id, observation_type,
                                       subject_type, subject_id)
    correlation       existing source_connection_epoch + configuration_version

Provider payload retention: keep the **normalized** fields in `payload` plus the
minimum correlation identifiers (`runtime_channel_id`, provider event id). Do not
store raw provider event bodies — no established repository pattern justifies it,
and `ProjectionService` already writes normalized payloads only.

Raw provider events are never canonical: adapter normalizes → observation persisted
→ **fenced** domain mutation → outbox event → Reverb invalidation, exactly the
existing chain. Reverb remains notification-only.

## Stale / Duplicate Fencing

Every case reuses an existing proven mechanism; no new fencing machinery.

| Case | Deterministic behaviour | Existing mechanism |
|---|---|---|
| Duplicate channel event | Insert collides, observation ignored, no mutation | unique `(source_event_id, observation_type, subject_type, subject_id)` |
| Late hangup for a live leg | Applies only if it names the **exact current** `runtime_channel_id`; otherwise recorded and discarded | binder exact-channel idiom + partial unique index |
| Late bridge event | Rejected when either leg is terminal | terminal-state guard |
| Event from a replaced CallLeg | Scoped to the leg row that owns that `runtime_channel_id`; the replacement leg is untouched | `clear()` channel-scoped idiom proven in RH-1 |
| Provider reconnect / replay | Observations from a superseded epoch are recorded, not applied | `source_connection_epoch` + `runtime_event_connection_epochs` |
| Out-of-order observation | Monotonic guard: never regress observed state, never overwrite a terminal state | `observed_generation` guard, `ProjectionService:381-389` |
| Runtime vanishes silently | Reconciliation converges the leg to `FAILED` | `ConferenceParticipantReconciler` pattern |

Rule of authority: **the adapter observes facts; the canonical domain decides whether
they still apply.** No canonical corruption is ever left for the UI to repair.

## Adapter Contract

**Do not create an eighteen-method adapter interface.** The normalized contract
already exists and is two methods:

```php
interface RuntimeAdapter {
    public function adapterKey(): string;
    public function execute(array $operation): array;   // dispatches on operation_type
}
```

C6 extends it by **adding operation types**, not methods. Each adapter adds `match`
arms; the existing `default =>` arm already returns
`FailureClass::UnsupportedCapability`, so an adapter that has not implemented an
operation fails visibly with zero extra work. Simulator, Asterisk and later
FreeSWITCH (T4) all implement the same `operation_type` vocabulary.

Per operation the contract is fully specified by the matrix above:

* **canonical input** — `operation['payload']` with `call_id`, `leg_id`, and
  operation-specific parameters, all canonical UTCP ids.
* **adapter result** — the existing shape
  `{status, event_type?, event_payload?, failure_class?, failure_code?, failure_message?}`;
  `event_payload.runtime_channel_id` carries runtime correlation.
* **async expectation** — the adapter return proves *acceptance*; canonical
  completion always comes from an observation.
* **runtime correlation id** — `runtime_channel_id` on the leg, opaque to the domain.
* **failure classification** — the existing `FailureClass` enum, unchanged.
* **capability requirement** — declared by the handler, enforced by `CommandWorker`.

Observation ingress likewise reuses the existing listener → normalizer →
`runtime_event_receipts` → `ProjectionService` path. No new ingress mechanism.

Prohibited in canonical fields: ARI object types, Asterisk channel names as
semantics, ESL event structures, FreeSWITCH UUID semantics beyond
`runtime_channel_id` correlation.

## Capability Negotiation

Already implemented. `RuntimeOperationHandler::requiredRuntimeCapability()` +
`CommandWorker:151-152` reject the operation when the capability is absent from
`runtime_node_capabilities`. C6 adds only **capability keys** to
`config/runtime_registry.php` (which currently holds 9 conference-shaped values):

    call.origination   call.control   call.hold   call.transfer
    call.dtmf.send     media.playback
    (recording already exists — C6 finally gives it an operation)

Received DTMF is an **observation**, not an operation, so it needs no operation
capability; adapters that cannot report digits simply never emit them. No feature
gates, no DSL, no silent provider fallback.

## TelephonySession Boundary

Unchanged and preserved: TelephonySession is **control-plane authorization**, never
SIP dialog or media authority.

* `call_legs.telephony_session_id` — **nullable FK**, set for legs belonging to an
  authorized browser/application session; **null** for external inbound legs, which
  by definition have no session.
* `calls` does **not** reference TelephonySession. A call may outlive a session and
  an inbound call has none.
* Operations are authorized on **tenant + capability**, not on session ownership;
  the acting user and request are recorded on the operation payload and audit record.
* `_own`-scoped permissions resolve through `call_legs.user_id` /
  `telephony_session_id`, not by promoting the session into call authority.

## Conference Cutover Decision

    OPTION B — existing conference authority remains unchanged. C6 Wave 1 applies
    only to new generic call flows. Conference integration is a later bounded cutover.

Evidence:

1. Conference legs are bound through `conference_participants.runtime_channel_id`
   with exact-channel fencing, and the frozen RH-1/RH-2/RH-3 recovery corridor is
   built directly on `desired_state` + `runtime_channel_lost_at`.
2. The live Kamailio route view keys on `'conf-' || cp.id` — conference participation
   *is* the addressing scheme today.
3. Representing those legs as `call_legs` in Wave 1 would create **two control
   authorities over one live runtime channel**, which is exactly what `CLAUDE.md`
   forbids and what RH-2D proved catastrophic.

Staged path (later, not now): (a) read-only projection of conference legs as
`CallLeg`s for timeline/observability; (b) route conference participant control
through C6 operations while `conference_participants` stays the intent authority;
(c) only then consider unifying. Each stage needs its own proof.

**Canonical during C6 Wave 1:** `Conference` + `ConferenceParticipant` remain the
sole authority for conference legs; `calls`/`call_legs` never reference them.

## C7 Routing Boundary

C6 consumes a route decision; it never computes one.

    Seam (C6 Wave 1)                        Filled by
    calls.route_decision      (json, null)   C7B RouteDecision snapshot
    calls.route_decision_source (string)     'c7' later; 'fixture' in Wave 1
    calls.destination_ref     (string, null) C7B DestinationRef
    calls.caller_identity_ref (string, null) C7A CallerIdentity

Before C7 exists: an outbound originate may name an eligible runtime node directly
(the existing `runtime_nodes` eligibility path), and the **simulator** may supply a
bounded fixture destination. An inbound call is adopted with
`destination_ref = null` and `observed_state = OFFERED`.

Explicitly prohibited in C6: any Asterisk-specific routing authority, any dialplan
context, any trunk concept, and any resolution logic that C7 would later have to
remove.

## DTMF Observation

    observation_type: 'call.leg.dtmf_received'
    subject_type:     'call_leg'
    payload:          { digit: '0-9|*|#|A-D', duration_ms?: int,
                        source: 'rfc2833'|'inband'|'sip_info'|'unknown' }
    occurred_at:      runtime clock (observed_at column)

`source` is justified because adapters genuinely differ and an application may need
to know digit fidelity; it carries no business meaning. C6 assigns **no** semantics —
no menus, no collection buffers, no inter-digit timers, no IVR domain.

## Media Playback

Target: **CALL LEG** only (call-level fan-out is not a Wave 1 requirement).

Media reference form: a **provider-independent logical media reference** — an opaque
`media_ref` string in a reserved namespace (e.g. `utcp:media/<identifier>`). The
adapter resolves it to a runtime-local asset. C6 stores `media_ref` only.

Prohibited as canonical fields: Asterisk playback syntax (`sound:`, `digits/`),
FreeSWITCH file paths, absolute URIs to runtime-local storage.

Media-asset management (upload, catalog, per-runtime distribution) is **outside C6**.
The minimal seam is: `media_ref` is validated for syntax and recorded; resolution
failure returns `FailureClass::InvalidRequest` with `media_ref_unresolved`. No prompt
sequencing, no playlists, no barge-in policy.

## Recording Boundary

C6 **owns**: the `start_recording` / `stop_recording` control requests, the
`recording` capability gate, a `recording_ref` correlation string on the leg, and the
`recording_started` / `recording_stopped` / `recording_failed` observations.

C6 **explicitly excludes**: long-term recording storage, retention policy, lifecycle
and deletion, access control over recorded media, compliance/consent workflow,
transcription, and any recording UI or product surface. These are a separate future
domain.

## Transfer Semantics

Minimal and provider-neutral.

**`redirect`** — precondition: leg is `OFFERED`, `RINGING` or `ANSWERED`. Target: the
leg. Result: the leg is directed to a new destination; C6 records
`call.leg.redirected` and the new `destination_ref`. No new leg is modelled; if the
runtime creates one it is adopted under the same call.

**`blindTransfer`** — precondition: leg is `ANSWERED`. Target: the leg to be
transferred. Result: transferor leg → `TRANSFERRING` → terminated with
`termination_reason='transferred'`; the transferee's new leg is adopted under the
**same call**. C6 records the operation, both terminal reasons, and the adoption.

**`attendedTransfer`** — precondition: two `ANSWERED` legs in the same call and
tenant, one flagged `role=consultation`. Target: the relationship. Result: the two
surviving legs become bridged; the transferor leg terminates `transferred`.

Adapter-specific and **not** modelled: SIP REFER vs runtime-native transfer, Replaces
headers, transfer progress sub-states, park/retrieve, and every other PBX variant.
C6 records intent, outcome and the resulting leg relationships only.

## API Shape

Normalized resource pattern, consistent with the existing conference API. **One
operations endpoint, not eighteen.**

    POST   /api/v1/calls                    originate (Idempotency-Key header)
    GET    /api/v1/calls                    tenant-scoped list, filterable by state
    GET    /api/v1/calls/{id}               call + embedded legs
    GET    /api/v1/calls/{id}/legs          legs
    POST   /api/v1/calls/{id}/operations    { type, target_leg_id?, parameters }
    GET    /api/v1/calls/{id}/operations    operation history + status
    GET    /api/v1/calls/{id}/timeline      derived projection

`POST /operations` validates `type` against the operation vocabulary, resolves and
validates the target, checks preconditions, then enqueues one `runtime_operations`
row. No UI design in this packet.

## Authorization

Six permissions, following the existing `telephony.<resource>.<action>` convention
(the repository currently has ten telephony capabilities in exactly this shape):

    telephony.calls.view          tenant-wide read
    telephony.calls.view_own      read own legs/sessions
    telephony.calls.originate     create calls
    telephony.calls.control       answer/hangup/hold/bridge/transfer/dtmf/media
    telephony.calls.record        start/stop recording
    telephony.calls.manage        administrative override

Tenant isolation reuses the existing tenant-scoped query conventions; cross-tenant
read or mutation is rejected as it already is for conferences. No new RBAC machinery.

## Audit / Retention

Audit: reuse `control_plane_audit_records` with actions
`call.originated`, `call.operation_requested`, `call.terminated`,
`call.leg.adopted`. Reuse `control_plane_outbox_messages` for realtime
invalidation, consistent with RT-1A (payload = invalidation metadata only, never the
aggregate).

Retention: **no new retention machinery in C6 Wave 1.** Terminal calls, legs,
operations and observations are retained, matching how conferences and runtime
observations behave today. The only existing pruner
(`ConferenceRecoveryMetricEventPruner`) is metric-specific and is not a precedent for
domain data. Revisit retention at R0, or when observation volume is measured — not on
speculation.

## Simulator Acceptance

The simulator implements the **same** `operation_type` vocabulary as Asterisk — no
simulator-only behaviour. Deterministic acceptance for Wave 1:

| Scenario | Acceptance |
|---|---|
| Outbound call | `REQUESTED → ORIGINATING → RINGING → ANSWERED`, one leg, `runtime_channel_id` correlated |
| Inbound adopted call | `call.leg.offered` creates call + leg at `OFFERED`, tenant from node |
| Answer | `answer` op → `ANSWERED` observation → state advance |
| Hangup | leg + call terminal, `termination_reason='requested'`, `termination_party='local'` |
| Hold / resume | `held=true/false`, capability-gated |
| Bridge / unbridge | symmetric `bridged_to_leg_id` set and cleared on both legs |
| Blind transfer | transferor terminated `transferred`, new leg adopted under same call |
| Attended transfer | surviving legs bridged, transferor terminated |
| DTMF send | `dtmf_sent` observation |
| DTMF receive | scheduled `dtmf_received` with digit + source |
| Media playback | `media_started` → `media_finished`; `stop_media` → `media_stopped` |
| Recording control | `recording_started` / `recording_stopped`, `recording_ref` set |
| Unsupported operation | node without the capability → `UnsupportedCapability`, state unchanged |
| Duplicate observation | reuse existing `duplicate-observation` scenario → exactly one mutation |
| Stale/replayed observation | reuse `disconnect-reconnect` → superseded epoch recorded, not applied |
| Out-of-order | terminal state never regresses |
| Terminal lifecycle | call terminal only when every leg is terminal |

## Implementation Slices

Five bounded slices, smallest sequence with clean acceptance boundaries.

    C6A — Canonical model and operation authority
          calls + call_legs migrations, state machine, termination columns,
          CallDomainService, operation types + payload versions, capability keys,
          audit/outbox wiring. No adapter work.
          Acceptance: focused unit/integration tests; state machine rejects illegal
          transitions; operations enqueue onto runtime_operations with idempotency.

    C6B — Operation handlers, capability gating, simulator execution
          One RuntimeOperationHandler per operation type; simulator match arms.
          Acceptance: every operation executes against the simulator; a node lacking
          the capability fails with UnsupportedCapability and no state change.

    C6C — Observation ingress, inbound adoption, fencing
          Normalized observation types, adoption transaction, all seven fencing cases.
          Acceptance: duplicate / late / stale-epoch / replaced-leg / out-of-order all
          deterministic; reconciliation converges an abandoned leg.

    C6D — API, authorization, timeline projection
          Seven endpoints, six permissions, derived timeline.
          Acceptance: API proof through normal sessions; cross-tenant rejected; no
          endpoint names a vendor concept.

    C6E — Asterisk adapter call control + bounded live proof
          Asterisk match arms + ARI event normalization for call observations.
          Acceptance: one live outbound call and one live inbound adopted call with
          answer, hold/resume, DTMF, playback and hangup; terminal audit correct.

**T4 begins after C6E**, when the operation vocabulary, observation set and adapter
dispatch have been exercised by two adapters (simulator + Asterisk).

## False Abstractions Rejected

* An 18-method C6 adapter interface — the two-method `RuntimeAdapter` plus
  operation-type dispatch already is the normalized contract.
* A `call_operations` table — duplicate operation authority.
* A `call_observations` table — duplicate observation authority.
* A `call_timeline_entries` table — duplicate canonical event storage.
* A generic `CallParticipant` — no independent authority in Wave 1.
* A durable `Bridge` aggregate — conferences already cover N-way; two-party is a
  relationship.
* Generic workflow engine, event-sourcing framework, telephony graph, state-machine
  framework, capability DSL, new message bus, new retry framework, new operator CLI,
  feature gates, manual reconcile commands — all rejected; the kernel already
  provides leases, retries, idempotency, outbox, inbox and audit.
* Promoting TelephonySession into dialog/media authority.
* Any C7 routing logic, TelephonyAddress or DestinationRef implementation inside C6.
* Making C6 authoritative for existing conference legs in Wave 1.

## Exact Documentation Updates Required

1. `docs/roadmap/implementation-roadmap.md` → `### C6`: add `OFFERED` inbound entry to
   the lifecycle chain; add `playMedia`/`stopMedia` to the operation list; add
   DTMF-received to the observation set; state that CallOperation/CallObservation
   reuse the kernel tables and that CallParticipant, Bridge and CallTimelineEntry are
   not tables in Wave 1; record the Option-B conference cutover; add the recording
   control/storage boundary sentence.
2. `docs/roadmap/phase-status.md`: append the C6 contract entry (done in this packet).
3. A new **ADR — Canonical Call Lifecycle and Call-Control Authority** should be
   written **with the C6A implementation**, not now: it records the Call/CallLeg
   split, the reuse of `runtime_operations` and `runtime_observations`, and the
   Option-B conference cutover. No existing ADR (013-022) is contradicted.

## Verification

    git diff --check        → clean
    make repository-hygiene → passed
    make secret-scan        → passed

## Status

    V0:    COMPLETE / UNCHANGED
    RT-1A: COMPLETE / LIVE PROVEN / UNCHANGED
    RH-3:  COMPLETE / LIVE PROVEN / FROZEN — untouched
    T4:    not begun
    C7:    not begun
    C6:    CONTRACT COMPLETE — BOUNDED IMPLEMENTATION READY
