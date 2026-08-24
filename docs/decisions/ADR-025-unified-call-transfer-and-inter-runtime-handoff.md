# ADR-025: Unified Call Transfer and Inter-Runtime Handoff Authority

## Status

Planned / roadmap defined. This ADR records a future architecture direction; it
does not claim that transfer or handoff orchestration is implemented.

## Context

Applications need to express business intent such as “send this qualified caller
to the available mortgage closer.” They should not need to select Asterisk ARI,
FreeSWITCH ESL, SIP REFER details, provider channel identifiers, rtpengine
behavior, or a Kubernetes runtime. The application chooses the business
destination. UTCP determines how the telephony path is established and
maintained.

The product-facing name is **Unified Call Transfer & Handoff**. The precise
architectural description is provider-neutral call transfer, consultation,
bridge replacement, inter-runtime handoff, and inter-provider handoff lifecycle.
Inter-runtime is the primary abstraction: Asterisk RuntimeNode A to Asterisk
RuntimeNode B is inter-runtime, same-provider; Asterisk to FreeSWITCH is both
inter-runtime and inter-provider.

## Decisions

1. Transfer and handoff orchestration is a UTCP control-plane responsibility.
   Applications retain business policy: campaign rules, qualification, agent or
   team selection, skills and queues, CRM state, scripting, transfer UI,
   disposition, pacing, and business-specific metrics. UTCP does not become a
   campaign engine, ACD, CRM, or predictive dialer.
2. The canonical abstraction is **runtime handoff**. Provider handoff is a
   subtype of inter-runtime handoff, not the sole product abstraction.
3. A handoff normally preserves one logical `Call`. Source, consultation, and
   target paths are represented by `CallLeg` records, even when their RuntimeNode
   or provider differs. A new logical Call requires an explicit future semantic
   decision, not provider convenience.
4. C6 remains the source of normalized call-control primitives. Future
   orchestration consumes `originate`, `bridge`, `unbridge`, `blind_transfer`,
   `attended_transfer`, `redirect`, `hangup`, and related primitives; it does not
   duplicate or reopen the C6 catalog.
5. Destination authority belongs to the future C7 `DestinationRef` seam and,
   where appropriate, `TelephonyAddress`. DestinationRef may identify a
   telephony identity, conference, application endpoint, external destination,
   or a reserved future queue reference. Transfer does not create a competing
   phone-number, extension, or SIP-URI authority.
6. Runtime adapters execute provider commands and listeners normalize provider
   facts. Neither owns the canonical transfer lifecycle or directly mutates
   `Call`/`CallLeg` state. `runtime_operations` remains command authority,
   `runtime_observations` remains provider-fact authority, and `CallDomainService`
   remains canonical lifecycle mutation authority.
7. Provider differences remain below the normalized control-plane boundary.
   UTCP may choose provider-native blind or attended transfer, SIP redirect or
   REFER, new-leg origination plus bridge replacement, or a runtime-to-runtime
   signaling path. A requested contract must not silently degrade to a weaker
   mechanism; unsupported semantics fail observably.
8. The original working leg remains usable until the target handoff reaches an
   explicitly defined commit point, where technically possible. Before commit,
   target failure leaves the source authoritative and terminates the failed
   target. After commit, reconciliation follows actual provider state and does
   not fabricate rollback or resurrect a released leg.
9. Existing operations, observations, audit, and outbox authorities are reused
   before a new transfer aggregate or table is considered. No provider-specific
   transfer model is permitted.
10. Reference applications and dialers consume a future canonical transfer API;
    they do not own provider switching, runtime selection, or transfer state.
    Normal operation does not introduce manual provider switching, feature
    gates, runtime allowlists, or manual reconciliation.

## Recommended lifecycle

The future lifecycle is conceptually:

```text
TRANSFER REQUESTED
        -> DESTINATION RESOLUTION
        -> TARGET RUNTIME SELECTION
        -> TARGET LEG CREATION
        -> TARGET LEG ESTABLISHING
        -> optional CONSULTATION
        -> HANDOFF / BRIDGE CUTOVER
        -> SOURCE LEG RELEASE
        -> TRANSFER COMPLETED
```

Failure classifications include target creation or answer failure,
consultation failure, cutover failure, source-release failure, runtime loss, and
cancellation. C6 already reserves `TRANSFERRING`; future implementation should
evaluate that state rather than inventing a duplicate, but this ADR does not
decide its transition table or add a writer.

A provider-neutral implementation should validate tenant, authorization, Call
and source-leg state, and destination; resolve `DestinationRef`; select an
eligible runtime; reserve a deterministic target leg; execute through the
RuntimeOperation pipeline; observe progression through runtime observations;
maintain a consultation relationship when requested; perform the safest
supported cutover; confirm the required success boundary; release the superseded
leg only after that boundary; and complete the operation with audit provenance.

## Inter-runtime and inter-provider handoff

The same logical model supports:

```text
Call
├── source leg   runtime = Asterisk
└── target leg   runtime = FreeSWITCH
```

The application submits a destination, not a provider branch. UTCP handles
runtime eligibility, provider execution, SIP/signaling path, media continuity,
bridge/cutover, observations, and failure classification. Target and
consultation legs use deterministic `runtime_channel_id` correlation and must
never be matched heuristically by phone number, timestamp, caller ID, or latest
active channel.

Media continuity is an implementation concern of the supported telephony
architecture. A future slice must determine whether media remains anchored by
rtpengine or is re-anchored/re-negotiated; the dialer must not own that choice.

## Commit, failure, and rollback semantics

Each future implementation must define its provider-appropriate transfer commit
point, such as target answer, bridge establishment, provider confirmation, or
source-release acknowledgement. Before commit, a failed target is terminated and
the source leg is retained. A cancelled consultation retains the original path
when possible. After irreversible cutover, UTCP reconciles actual observations
and reports degraded or failed outcome rather than pretending a rollback occurred.
There is no synthetic provider fact, silent resurrection, or silent fallback from
attended to blind transfer.

Transfer requests must be idempotent. The existing RuntimeOperation idempotency
authority should prevent duplicate target legs, double transfer, and repeated
source release. A separate transfer idempotency store requires demonstrated
evidence. Provider success may support `command-confirmed` transitions only where
the established C6 contract permits it; it never creates a synthetic observation.

## API, capability, security, and audit direction

Implementation should first evaluate whether the existing
`POST /calls/{call}/operations` authority can represent the transfer lifecycle.
`POST /calls/{call}/transfers` is a possible future resource only if the
multi-step status, idempotency, consultation relationships, and audit needs do
not fit the existing Call, CallLeg, and RuntimeOperation model. `call.transfer`
may remain the starting capability; finer blind/attended/redirect/inter-runtime
capabilities should be introduced only when provider evidence demonstrates the
need.

Every request remains tenant-scoped and permission-controlled. UTCP validates
Call ownership, DestinationRef access, RuntimeNode tenant service, and any
cross-provider authorization. Provider credentials and implementation details
are never exposed to applications. Existing runtime operations, observations,
audit, and derived timeline should represent requested, target, consultation,
commit, release, completion, failure, and cancellation events before a new
timeline table is considered.

## Data model and sequencing

The default is no new persistent table. Reuse `Call`, `CallLeg`,
`runtime_operations`, `runtime_observations`, audit/outbox, and the C7
DestinationRef/TelephonyAddress seam. Add a `call_transfers` aggregate only if
implementation evidence proves that a durable long-lived orchestration cannot be
represented cleanly by those authorities. Never add `asterisk_transfers`,
`freeswitch_transfers`, or `provider_handoffs`.

The planned dependency is:

```text
C6 call-control primitives
        -> T4 provider parity
        -> C7 TelephonyAddress / DestinationRef / routing foundation
        -> C8 Unified Call Transfer & Inter-Runtime Handoff
```

The proposed C8 slices are C8A Transfer Lifecycle Authority, C8B Consultative /
Attended Transfer Orchestration, C8C Same-Provider Inter-Runtime Handoff, C8D
Inter-Provider Handoff, C8E Failure / Rollback / Recovery, and C8F Natural Live
Proof. They are planned sequencing concepts, not current implementation work.

## Non-goals

- Campaign logic, lead qualification, predictive dialing, agent scripting, CRM
  workflow, ACD/queue algorithms, skill scoring, or business routing policy.
- A provider-specific Call model, C6 rewrite, T4 rewrite, or implementation in
  this documentation side quest.
- A new provider-specific transfer table, transfer idempotency store, or
  low-level media-routing authority without later evidence.

## Mainline preservation

The accepted current state remains C6 complete/live/frozen, T4A and T4B
implemented/tested, and T4C1 implemented/tested with live proof pending. The next
mainline action remains:

**T4C1 — Managed FreeSWITCH Runtime Provisioning and Secure ESL Reachability Live
Proof.**

This ADR does not make C8 a prerequisite for that proof and does not mark C8
implemented.
