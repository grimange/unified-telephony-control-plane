# Post-RH-3 Roadmap Completeness and Product-Boundary Audit

## Verdict

    UTCP_POST_RH3_ROADMAP_COMPLETENESS_AUDIT_COMPLETED

Implementation decision:

    ROADMAP NEEDS BOUNDED REVISION

The product boundary itself is sound and already written down correctly. The
executable roadmap's **outbound/conference** foundation is complete and explicit.
Its **inbound** foundation is not: the two resources an inbound call must resolve
through — an externally routable telephony address and a normalized destination —
exist only as unnamed phrases inside acceptance criteria, and the two primitives an
IVR consumer minimally needs — **DTMF received** and **media playback** — appear
nowhere in any of the three authoritative documents. All corrections fit inside the
existing C6/C7/T6/V1/A0 identifiers as bounded corridors. No renumbering and no
architectural redesign is required.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  clean
    commit/push:   none created, not pushed

Evidence-only: no production code, no runtime or browser proof, RH-3 untouched
(COMPLETE / LIVE PROVEN / SIMPLIFICATION COMPLETE / FROZEN).

## Current Executable Roadmap

`docs/roadmap/implementation-roadmap.md` is the executable authority (Document
Hierarchy, line 5). Post-RH-3 order as written:

    … V0 [complete] → RT-1 [in progress: RT-1A complete] → T4 → T5 [complete]
      → C6 → C7 → T6 → V1 → A0 → R0

Two ordering facts matter for sequencing:

* **T5 is already Complete while T4 is not** (`t5-phase-closure.md`, `T5_COMPLETE`).
  The T4→T5 ordering constraint is therefore already discharged; T4 blocks nothing
  downstream.
* **No remaining extended-scope phase depends on T4.** C7 depends on C6; T6 depends
  on C7; V1 depends on C6+C7+T6; A0 depends on V1.

## Current Product Boundary

The boundary is already stated correctly and needs no change. Initial plan §1
(line 42) assigns applications: outbound campaigns, lead lists, dialing/pacing,
contact-center queues and workforce workflows, **IVR workflow definitions**,
notification jobs, business reason, retry/disposition policy, reporting. Line 52:
"must not attempt to become a complete dialer, contact-center suite, carrier
billing platform, or customer-specific workflow engine." A0 repeats it for the
reference dialer ("not a production predictive dialer") with an explicit
out-of-scope list. **No false scope expansion was found anywhere in the roadmap.**

## Capability Coverage Matrix

Repository state established by direct inspection: the canonical schema contains
**42 tables** and none of them is `calls`, `call_legs`, `call_operations`,
`external_trunks`, `inbound_routes`, `outbound_routes`, `telephony_addresses`, or
`caller_identities`. `apps/api/app/RuntimeAdapters/` contains **only `Asterisk`**.
`dtmf`, `playback`, and `play_media` match **zero** files under `apps/api/app/`.

| CAPABILITY | UTCP CORE | REFERENCE APP ONLY | FUTURE EXTENSION | OUT OF SCOPE | REASON |
|---|---|---|---|---|---|
| Call lifecycle | ✅ C6 | | | | Canonical desired state + observation |
| Call control | ✅ C6 | | | | Normalized, capability-aware |
| External trunk | ✅ C7A | | | | External connectivity policy |
| DID / E.164 address inventory | ✅ **C7A (to add)** | | | | Tenant ownership + inbound eligibility is routing authority |
| Inbound routing | ✅ C7B | | | | Route decision authority |
| Outbound routing | ✅ C7B | | | | Route decision authority |
| Caller identity | ✅ C7A | | | | Identity policy is control-plane authority |
| Application destination | ✅ **C7B (to make explicit)** | | | | Normalized handoff target |
| DTMF sent (`sendDtmf`) | ✅ C6 | | | | Normalized call control |
| DTMF received (observation) | ✅ **C6 (to add)** | | | | Observation, not business logic; IVR/automation need it |
| Media playback / stop | ✅ **C6 (to add)** | | | | Runtime primitive; prompt *content/sequence* is the app's |
| Recording control | ✅ C6 | | | | `startRecording`/`stopRecording` already listed |
| Recording storage / retention | | | ✅ | | Storage, lifecycle, retention policy is a separate domain |
| IVR logic (menus, hours, journeys) | | ✅ A0 minimal | | ✅ | Explicitly an application concern (initial plan line 42) |
| Queue / ACD | | | ✅ **explicitly defer** | | Would make UTCP a contact-center product |
| Campaigns | | ✅ A0 minimal | | ✅ | A0 owns campaign membership only |
| Lead lists | | ✅ A0 minimal | | ✅ | Application data |
| Predictive dialing | | | | ✅ | Named non-goal |
| Agent disposition / WFM | | | | ✅ | Contact-center product |
| DID procurement / porting | | | | ✅ | Commercial carrier workflow |
| Carrier billing / settlement | | | | ✅ | Named non-goal |
| CRM | | | | ✅ | Named non-goal |
| SMS / MMS | | | | ✅ | Not telephony call control |
| STIR/SHAKEN, emergency policy, lawful intercept, full SBC | | | | ✅ | Named non-goals; do not add phases |

## Roadmap Gap Matrix

| CAPABILITY | ROADMAP LOCATION | REPOSITORY STATE | EXPLICIT/IMPLICIT/ABSENT | PROPOSED CHANGE | DEPENDENCY | PROOF SLICE |
|---|---|---|---|---|---|---|
| Call / CallLeg / CallOperation / CallObservation / CallTermination / CallTimelineEntry | C6 | **ABSENT** (no tables, no code) | EXPLICIT | none | — | C6 simulator |
| Inbound call **entry** lifecycle state | C6 lifecycle | ABSENT | **IMPLICIT** | add an inbound entry state (e.g. `OFFERED`) — current chain is outbound-shaped | C6 | C6 simulator |
| DTMF received observation | — | ABSENT | **ABSENT** | add to C6 `CallObservation` | C6 | C6 simulator + A0 IVR |
| Media playback / stop | — | ABSENT | **ABSENT** | add `playMedia`/`stopMedia` to C6 operations | C6 | C6 simulator + A0 IVR |
| Application handoff / destination class | C7 "normalized application destination" | ABSENT | **IMPLICIT** | name it in C7B | C6 | T6 inbound |
| TelephonyAddress (E.164/DID/SIP URI) | — | ABSENT | **ABSENT** | add to C7A | C7 | C7 + T6 inbound |
| DestinationRef + destination classes | C7/T6 acceptance phrases | ABSENT | **IMPLICIT** | name in C7B with an enumerated class list | C6 | T6 inbound |
| ExternalTrunk / TrunkEndpoint / TrunkCredentialReference | C7 | ABSENT | EXPLICIT | none | C6 | T6 |
| InboundRoute / OutboundRoute / RouteConstraint / RouteDecision | C7 | ABSENT | EXPLICIT | none | C6 | T6 |
| CallerIdentity / CallerIdentityPolicy | C7 | ABSENT | EXPLICIT | none | C6 | T6 |
| Inbound vertical slice | V1 | ABSENT | **ABSENT** | make V1 bidirectional | T6 | V1 inbound corridor |
| Inbound + IVR reference consumers | A0 (dialer only) | ABSENT | **ABSENT** | add two small consumers | V1 | A0 |
| Recording storage boundary | — | ABSENT | ABSENT | one sentence: control in core, storage deferred | — | — |
| Queue / ACD | — | ABSENT | ABSENT | one explicit deferral sentence | — | — |
| Capability negotiation | C2 catalog (9 values) | **PARTIAL** — conference-centric; no call/DTMF/media capabilities | IMPLICIT | extend catalog in C6/C7 | C6 | C6 |
| FreeSWITCH ESL control-plane adapter | T4 | **ABSENT** (only `Asterisk`) | EXPLICIT | narrow/re-sequence, see below | C6 (recommended) | T4 |
| FreeSWITCH SIP/media parity | T3-S2C | **COMPLETE / LIVE PROVEN** | EXPLICIT | none | — | done |

## C6 Review

C6 as written is **strong and mostly complete**. It already names all eight core
concepts the audit asks for (`Call`, `CallLeg`, `CallParticipant`, `CallOperation`,
`CallObservation`, `CallRouteDecision`, `CallTermination`, `CallTimelineEntry`) and
all sixteen listed operations including `sendDtmf`, `startRecording`,
`stopRecording`, with explicit capability results for unsupported operations. Three
bounded additions are required:

1. **DTMF received observation — ABSENT.** `sendDtmf` is the only DTMF mention in
   the entire documentation set (initial plan line 1402). An IVR, an automation
   consumer, and any menu-driven application all need inbound digits. This is an
   observation, not business logic, and belongs in `CallObservation`.
2. **Media playback / stop — ABSENT.** Zero occurrences of play/playback/prompt/
   announce as a telephony primitive anywhere. Add `playMedia` and `stopMedia` as
   normalized operations. UTCP owns *"play this media reference on this leg and tell
   me when it finished"*; the application owns which prompt, in what order, and why.
3. **The normalized lifecycle is outbound-shaped.**
   `REQUESTED → SELECTING_ROUTE → ORIGINATING → RINGING → EARLY_MEDIA → ANSWERED …`
   has no entry point for a call UTCP did not originate. An inbound leg arrives
   already offered. Add one inbound entry state (e.g. `OFFERED`) so an inbound
   `Call`/`CallLeg` is a first-class citizen of the same model rather than an
   outbound state chain reinterpreted.

Nothing else should be added. Menu trees, business hours, prompt sequencing and
customer journeys stay out.

## Telephony Address / DID Review

**This is the single largest gap.** `E.164`, `DID`, `telephony address`, and
`number inventory` return **zero matches across all three authoritative documents**.

C7 nonetheless asserts (initial plan line 1492) that "inbound routes match
normalized destination and source criteria" — but the thing being matched has no
canonical resource. Without it the roadmap cannot answer tenant ownership of a
number, trunk association, activation state, inbound eligibility, caller-identity
eligibility, route assignment, or audit history.

Recommend adding a runtime-neutral **`TelephonyAddress`** to C7A, covering E.164
numbers, SIP URIs, and logical extensions where applicable, with the administrative
lifecycle C7 already defines (`DRAFT → VALIDATING → ACTIVE → DRAINING → DISABLED →
RETIRED`) so it inherits the existing pattern rather than inventing one. Do **not**
name it `DID`, and explicitly exclude number purchasing, porting, billing,
settlement, and commercial carrier provisioning.

## Inbound Route / Destination Review

The concept is **implicit only**. "a normalized application destination" (line 1506)
and "a normalized UTCP destination" (line 1800) appear as acceptance criteria with
no model, no enumerated destination classes, and no lifecycle.

The current repository has exactly **one** destination class, and it is hardcoded:
`database/migrations/2026_08_15_141000_create_kamailio_conference_route_view.php`
projects `'conf-' || cp.id::text` as `admission_user` joined to a SIP target built
from `runtime_node_endpoints`. That is a conference-participant-specific SQL view —
correct for V0, but it is not a destination abstraction, and nothing generalizes it.

Recommend naming **`DestinationRef`** in C7B with an enumerated, runtime-neutral
class list: telephony identity, conference, application endpoint, external
destination, and an explicitly reserved future queue class. The required flow
should be written out once:

    External SIP call → ExternalTrunk → called TelephonyAddress → InboundRoute
      → RouteDecision → DestinationRef → canonical Call/CallLeg

The model must not encode Asterisk dialplan contexts, FreeSWITCH XML, PJSIP
endpoints, Sofia profiles, or Kamailio dispatcher IDs — those stay behind
adapters/projection, exactly as the existing conference route view keeps them.

## Outbound Foundation Review

**Adequate; no change required.** The boundary is already explicit. C6 owns
`originate`/`cancelOrigination` and the call model; C7 owns route, trunk eligibility
and caller identity; A0 confines campaign membership, contact selection, call-request
timing, simple retry and disposition to the reference application and lists
predictive pacing, compliance automation, production lead management, billing,
reporting, AMD and large-scale scheduling as out of scope. V1's exit criteria
already forbid the request naming Asterisk, PJSIP, ARI, Kamailio dispatcher IDs, or
trunk configuration.

## IVR Boundary Review

UTCP should expose to an IVR application exactly: `answer`, `hangup`,
`redirect`/`blindTransfer`/`attendedTransfer`, `bridge`, **DTMF received**,
**media playback**, **media stop**, call timeline, and application-destination
handoff. Of these, three are currently missing (the two media operations and the
DTMF observation) — see C6 Review.

UTCP must not own menu trees, "press 1 for sales", business hours, CRM lookup,
customer journeys, prompt-sequence logic, visual IVR editors, or flow designers.
The initial plan already assigns "IVR workflow definitions" to applications
(line 42), so no boundary change is needed — only the primitives.

**An IVR proof belongs in A0 as a reference consumer, not as a core IVR domain
phase.** There should be no C-phase named IVR.

## Queue / ACD Boundary Review

Queue/ACD appears **nowhere** in any of the three documents as a telephony concept
(the only `queue` matches are Redis job queues and queue-worker processes). It is
therefore neither planned nor deferred — an unstated hole that invites future scope
creep.

Recommend **EXPLICITLY DEFERRED** as a future application/domain extension, recorded
in one sentence, with `DestinationRef` reserving a future queue destination class so
the model does not have to change later. Do **not** add a Queue phase.

## C7 Structure Recommendation

Keep **one C7 phase**, expressed as two bounded corridors. Do not renumber.

    C7A — External Connectivity, Telephony Addressing, and Caller Identity
          ExternalTrunk, TrunkEndpoint, TrunkCredentialReference, TrunkCapability,
          TrunkHealthPolicy, TrunkProjectionTarget, TrunkDesiredProjection,
          TrunkObservedSnapshot, **TelephonyAddress**, CallerIdentity,
          CallerIdentityPolicy

    C7B — Inbound/Outbound Route and Destination Authority
          InboundRoute, OutboundRoute, RouteConstraint, RouteDecision,
          **DestinationRef**

Every concept except the two in bold is already named in C7 today; the split is
organisational plus the two additions. The existing administrative-lifecycle and
observed-health separation, secret-reference handling, and web-admin-primary-authority
requirements apply unchanged to both corridors.

## T6 Recommendation

T6 already covers trunk projection, inbound/outbound route projection, caller-identity
projection, synthetic SIP peers, and one synthetic inbound plus one synthetic outbound
call, with an explicit "no paid carrier" constraint and the correct rule that Kamailio
executes but never owns tenant/trunk/identity/eligibility authority.

One addition: state explicitly that **TelephonyAddress and DestinationRef resolution**
are part of what T6 projects and proves, and that projection must occur **without
changing canonical C7 APIs**. Otherwise T6 is adequate as written.

## V1 Recommendation

**Make V1 bidirectional.** Both the Phase Order entry and the "Second Vertical Slice"
block are outbound-only today; the sole inbound acceptance anywhere is a single line
inside T6. Rename to **V1 — Bidirectional External Call Routing and Control** with two
required corridors:

    OUTBOUND: authenticated application → OriginateCall → outbound route
              → caller identity → trunk → runtime → synthetic peer
    INBOUND:  synthetic peer → trunk → called TelephonyAddress → inbound route
              → DestinationRef → canonical Call/CallLeg → application/runtime destination

Both must use normalized call observations and the canonical audit timeline. Keep the
existing exit criteria (no vendor identifiers in the request, inspectable decision
reasons, mid-call control, explicit unsupported-capability result, duplicate/delayed
observation safety, degraded/draining trunk selection change, full timeline). **Do not
build a dialer UI to prove outbound** — the existing reference dialer surface plus API
proof is sufficient.

## A0 Reference Consumer Recommendation

Broaden A0 from one dialer to **three intentionally small consumers**, to prove that
*different classes* of application share the same contracts:

1. **Outbound consumer** — the existing reference dialer, unchanged in scope.
2. **Inbound consumer** — receives a call at a `DestinationRef` and answers/transfers.
3. **Minimal IVR consumer** — synthetic inbound address → `DestinationRef` →
   application owns the call → play a synthetic prompt → DTMF observation →
   application picks a target → UTCP transfer → normalized continued lifecycle.

Each exists only to exercise UTCP primitives. Carry A0's existing out-of-scope list
onto all three, and add explicitly: the IVR consumer must not grow menu trees,
business hours, a prompt-authoring surface, or a flow designer.

## T4 / T5 Sequencing Review

Evidence, not phase numbers:

* **T5 is Complete** (`docs/evidence/t5/t5-phase-closure.md`, `T5_COMPLETE`, all 21
  criteria SATISFIED), including multi-node failover, placement, recovery metrics and
  Namespace PSA reconciliation.
* **T4 is materially unstarted at the control plane.** `apps/api/app/RuntimeAdapters/`
  contains only `Asterisk`. None of T4's five named classes (`FreeSwitchEslClient`,
  `FreeSwitchCommandAdapter`, `FreeSwitchEventListener`, `FreeSwitchEventNormalizer`,
  `FreeSwitchHealthInspector`) exists. `AppServiceProvider` registers only
  `SimulatorRuntimeAdapter` and `AsteriskRuntimeAdapter`.
* **What T4 already has**: the `freeswitch` family and `freeswitch-esl` adapter key in
  `config/runtime_registry.php`, a pinned image, a parity overlay, and — decisively —
  **T3-S2C proved FreeSWITCH parity live at the SIP/media plane**
  (`T3_S2C_FREESWITCH_LIVE_PARITY_COMPLETE`): the same Kamailio routes, the same
  unchanged WebRTC prover, no provider-specific browser logic.
* **T4's objective text is currently unsatisfiable.** It requires proving that
  "registration, **call-control**, bridge, conference, and observation contracts work
  against a second execution runtime" — but the call-control contracts do not exist
  (C6 is 0% implemented). Executed today, T4 could only prove registration +
  conference + observation + health parity.

**Recommendation: C6 (and C7A) before the remaining T4 work.** Reasons:

1. T4 as written cannot be completed before C6 exists.
2. T5, its successor, is already complete — T4 blocks nothing.
3. C7, T6, V1 and A0 all depend on C6; none depends on T4.
4. Building the ESL adapter once against the conference-only contract and again
   against the call contract is strictly more work than building it once afterwards.
5. The usual vendor-neutrality objection — "don't design a contract with one
   implementation in view" — is already answered here: C6's own completion criteria
   require **simulator** call-control operations through the adapter contract, so C6 is
   validated against a second, non-Asterisk adapter by construction; and FreeSWITCH
   parity is already proven at the signalling/media plane.

Record T4 as **deferred, not skipped**, with its SIP/media half already proven, and
schedule it after C6 so a single adapter build covers registration, conference **and**
call control.

## Other Foundation Gaps

* **Capability catalog is conference-shaped.** `config/runtime_registry.php` defines 9
  capabilities (`conference.execution`, `conference.lifecycle`,
  `conference.participation`, `channel.control`, `event.stream`, `runtime.observation`,
  `runtime.configuration`, `registration.observation`, `recording`). There is no
  `call.*`, DTMF, media-playback, or trunk capability. C6/C7 must extend it —
  and note `recording` is a **declared capability with no operation behind it** today.
* **Recording control vs storage is undefined.** C6 owns `startRecording`/`stopRecording`.
  Recording **storage, retention, access control and export** are a separate domain;
  state them as a future extension so they are not assumed into C6.
* **Call correlation/timeline** is named (`CallTimelineEntry`) but currently has no
  cross-leg correlation identifier described; worth one clarifying sentence in C6.
* **Call termination reason** is covered by `CallTermination` plus C6's
  "termination/failure classification is auditable" criterion. Adequate.
* **RT-1 remains In Progress** (only RT-1A complete; resource coverage deliberately not
  expanded). It is horizontal and does not gate C6/C7, but it should not be silently
  forgotten when C6/C7 add new resources that will want realtime invalidation.

## False Scope Expansions Rejected

Considered and rejected — none of these should enter the roadmap:

* A core IVR domain phase, prompt authoring, or a visual flow/call-flow designer.
* A Queue/ACD phase, agent state, skills routing, or workforce management.
* Campaign, lead, pacing, predictive dialing, AMD, or disposition domains.
* CRM, billing, carrier settlement, DID purchasing/porting.
* STIR/SHAKEN compliance workflows, emergency-service policy, SMS/MMS, lawful
  intercept, full SBC behaviour.
* Recording storage/retention as part of C6.
* Renumbering C6/C7/T6/V1/A0, or reintroducing the superseded `C8`/initial-plan `T1`
  and `T2`/`T3` identifiers.
* A dialer UI built solely to prove outbound.

## Recommended Post-RH-3 Roadmap

    CURRENT POST-RH-3 ORDER
      RT-1 (in progress) → T4 → [T5 complete] → C6 → C7 → T6 → V1 → A0 → R0

    RECOMMENDED POST-RH-3 ORDER
      RT-1  (continue as the horizontal track it already is)
      C6    Call lifecycle and normalized call-control domain
              + inbound entry state, DTMF-received observation, media playback/stop
      C7A   External connectivity, TelephonyAddress, caller identity
      C7B   Inbound/outbound route and DestinationRef authority
      T4    FreeSWITCH ESL adapter parity  (deferred here, not skipped;
              SIP/media half already proven by T3-S2C)
      T6    External trunk integration and live route projection
      V1    Bidirectional external call routing and control
      A0    Reference consumers: outbound, inbound, minimal IVR
      R0    Portfolio release

Same identifiers, two sub-corridors, one deferral. No renumbering.

## Exact Documentation Revision Required

Documentation-only packet. **No source, schema, or configuration changes.**

1. `docs/roadmap/implementation-roadmap.md` → **Phase Order** block (lines 21-55):
   move `T4` to sit after `C7`, annotate it `[deferred after C6/C7 — SIP/media parity
   already proven by T3-S2C]`, and mark C7 as `C7A`/`C7B` corridors.
2. Same file → **`### C6`** (line 426): add the inbound entry state to the lifecycle
   chain; add `playMedia` and `stopMedia` to the operation list; add DTMF-received to
   `CallObservation`; add one sentence excluding IVR business logic; add one sentence
   scoping recording **control** in and recording **storage/retention** out.
3. Same file → **`### C7`** (line 432): split the Core concepts sentence into C7A and
   C7B; add `TelephonyAddress` to C7A with the existing administrative lifecycle and an
   explicit exclusion of purchasing/porting/billing/settlement; add `DestinationRef` to
   C7B with its enumerated destination classes and a reserved future queue class; add
   the one-line inbound resolution flow.
4. Same file → **`### T6`** (line 436): state that TelephonyAddress and DestinationRef
   resolution are projected and proven without changing canonical C7 APIs.
5. Same file → **`### V1`** (line 440) and the **Second Vertical Slice** block
   (lines 106-122): retitle to bidirectional and add the inbound corridor beside the
   existing outbound one.
6. Same file → **`### A0`** (line 444): add the inbound and minimal-IVR reference
   consumers with their own out-of-scope guards.
7. Same file → **`### T4`** (line 416): record it as deferred-not-skipped; note the
   five absent classes and the already-proven T3-S2C SIP/media parity; state that its
   call-control parity depends on C6.
8. Same file → add one **Queue/ACD explicitly deferred** sentence in the product-boundary
   area, and one **capability-catalog extension** note under C6/C7.
9. `docs/roadmap/phase-status.md`: append one status entry recording this audit and the
   resulting sequencing decision.

No ADR is required: none of the above contradicts ADR-013 through ADR-022. A new ADR
becomes appropriate only when C6/C7 are **implemented**, at which point a
call-and-routing authority ADR should be written alongside the code.

## Browser / Runtime Proof

Not required and not performed.

## V0 / RT-1A / RH-3 Status

    V0:    COMPLETE / UNCHANGED
    RT-1A: COMPLETE / LIVE PROVEN / UNCHANGED (RT-1 overall still In Progress)
    RH-3:  COMPLETE / LIVE PROVEN / SIMPLIFICATION COMPLETE / FROZEN — untouched
