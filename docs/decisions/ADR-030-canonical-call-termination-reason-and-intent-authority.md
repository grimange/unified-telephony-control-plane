# ADR-030: Canonical Call Termination Reason and Intent Authority

## Status

Accepted as the canonical UTCP call-termination semantic authority. No runtime
behavior, adapter code, migration, or test is changed by this documentation
packet. It resolves the narrow audit verdict
`TERMINATION_INTENT_AUTHORITY_UNRESOLVED` and makes the next bounded
implementation packet unambiguous.

This ADR decides exactly one question: **what canonical meaning UTCP assigns to
the end of a Call or CallLeg, and which layer owns each part of that meaning.**
Call lifecycle authority (ADR-023), runtime-engine observation authority
(ADR-016), and the Asterisk adapter boundary (ADR-018) are restated here only
where this decision depends on them.

## Context

Repository evidence and repeated controlled live proofs on the canonical
native-k3s environment established the following, none of which is disputed:

* **Blanket `runtime_lost` fallback.** `AsteriskAriEventNormalizer` substitutes
  the literal `runtime_lost` into `termination_reason` whenever the upstream
  payload carries no string reason. The ARI payload never carries one, so the
  fallback fires on every Asterisk termination of every kind — including a
  deliberate, successful, operator-requested `call.hangup`.
* **Discarded Asterisk termination facts.** `AsteriskAriEventListener` builds an
  explicit allow-list payload (`channel_id`, `channel_name`, `channel_state`,
  `remote_identity`, `connected_identity`, bridge/digit/playback/recording
  fields). It never captures `cause`, `cause_txt`, or `tech_cause`. The facts
  that could distinguish termination causes are dropped one layer above the
  normalizer.
* **Inconsistent cross-runtime semantics.** Three adapters implement three
  different strategies for the same column: Asterisk synthesizes a semantic
  value it did not observe; FreeSWITCH copies its raw `Hangup-Cause` verbatim
  (so values such as `NORMAL_CLEARING` reach the canonical field); the
  simulator emits `requested` legitimately, because it is itself the operation
  executor and therefore genuinely knows the control intent.
* **Ambiguous `requested` fallback.** `CallObservationProcessor` defaults a
  missing reason to `requested` for `call.leg.terminated`. No ADR, contract, or
  test establishes that every missing reason means a control-plane-requested
  hangup. Exhaustive test search shows the fallback is never exercised: every
  test supplies an explicit reason. It is an untested defensive default.
* **No `call.leg.failed` producer.** The string appears only in
  `CallObservationProcessor` (consumer side). No adapter, normalizer,
  simulator, catalog, config, or test emits it. The intended split between
  normal termination and failure termination is not implemented; every runtime
  termination arrives as `call.leg.terminated`.
* **Hardcoded `termination_party`.** `CallDomainService::terminalizeObservedLeg`
  writes `termination_party = 'runtime'` unconditionally, so a deliberate
  control-plane hangup is recorded as runtime-initiated.
* **`StasisEnd` misalignment.** `channel_destroyed` and `stasis_end` both map to
  `call.leg.terminated`. Official Asterisk semantics distinguish these: a
  channel may leave a Stasis application and continue executing dialplan.
  Treating `StasisEnd` as terminal conflates control-ownership transition with
  call termination, and additionally produces duplicate terminal observations
  for a single channel.
* **Control channel versus provider leg.** V1-A executes through a Local
  control channel (`;1`, owned by the Stasis application and carrying the
  canonical `runtime_channel_id`) and a downstream provider-facing PJSIP leg.
  Their lifecycle facts are not interchangeable.
* **No canonical vocabulary.** `termination_reason` is an unconstrained
  `string(80)`. Four incompatible vocabularies currently share it: domain
  values (`origination_timeout`, `expired`), adapter-synthesized values
  (`runtime_lost`), a domain fallback (`requested`), and raw vendor causes
  (`NORMAL_CLEARING`). Two further values (`remote`, `busy`) exist only in
  tests and are written by no production path.

The consequence is that UTCP currently reports a successful, intentional
control-plane hangup as `runtime_lost` / `runtime`. Simply deleting the adapter
fallback does not fix this: with no runtime facts captured and no discriminator
defined, every Asterisk termination would instead fall through to `requested`,
attributing provider-initiated hangups to the control plane. That is a
different wrong answer, not a repair. The missing element is a contract, which
this ADR supplies.

## Decision

### 1. Three-layer authority model

UTCP adopts the following separation. Each layer owns exactly one kind of fact,
and no layer may assert what another layer owns.

```text
Layer 1 — Canonical control intent
  Call and CallLeg operations, RuntimeOperation records, requested hangup and
  cancel-origination intent.
  Authority: UTCP domain / control plane.

Layer 2 — Runtime facts
  Asterisk cause, cause_txt, tech_cause, source ARI event type;
  FreeSWITCH Hangup-Cause and any native hangup disposition.
  Authority: runtime adapters.

Layer 3 — Canonical termination meaning
  termination_reason, termination_party, call.leg.terminated,
  call.leg.failed, Completed versus Failed.
  Authority: UTCP domain.
```

This is the same authority discipline the repository already applies elsewhere:
runtimes own protocol and execution facts, and UTCP owns canonical business
meaning. It is also required by UTCP's vendor-neutrality rule — a canonical
field whose values are vendor hangup causes is a vendor leak into the core
telephony domain.

### 2. `termination_reason` is domain-canonical semantic state

`termination_reason` is **canonical UTCP semantic state (Option A)**. It is not
raw runtime or vendor cause storage.

* A runtime adapter **MUST NOT** write a vendor cause string into
  `termination_reason`.
* Raw runtime facts are preserved separately as observation payload facts
  (§9) and remain available to later work without polluting canonical state.
* This ADR does not design a raw-cause persistence schema beyond requiring that
  the facts be preserved on the observation; that is sufficient to fix the
  authority boundary.

### 3. Minimal canonical V1 vocabulary

The permitted V1 values for `termination_reason` on `calls` and `call_legs`
are exactly the following. No synonyms are introduced, and existing values are
reused wherever they already carry the right meaning.

| Value | Meaning | Producer / authority | Terminal state |
| --- | --- | --- | --- |
| `requested` | The UTCP control plane asked for this termination and it occurred. | Domain, from RuntimeOperation intent (§5) | `Completed` (success) |
| `remote` | Orderly termination initiated outside the UTCP control plane — the far-end peer, or the runtime's own orderly completion. | Domain, from an orderly runtime terminal fact with no matching local intent (§6) | `Completed` (success) |
| `runtime_lost` | The runtime, node, or channel disappeared without orderly terminal evidence. | Domain, from runtime-loss detection (§7) | `Failed` |
| `origination_timeout` | Origination was never observed to complete within the canonical deadline. | Domain, `CallOriginationReconciler` (existing, unchanged) | `Failed` |

`expired` remains valid for TelephonySession expiry and is out of scope for
Call and CallLeg termination.

`remote` is deliberately defined as *"not initiated by the UTCP control
plane"*, not as *"provably initiated by the far-end SIP peer"*. UTCP does not
today possess the runtime facts required to make the stronger claim, and
inventing that certainty would be dishonest canonical state. Finer attribution
of provider-side outcomes is Gap E and is explicitly out of scope.

The test-only value `busy` is **not** adopted. Response-specific outcomes such
as busy, rejected, or unavailable belong to Gap E's failure taxonomy, not to
this minimal termination-intent vocabulary.

### 4. `termination_party` vocabulary

`termination_party` names the **initiating causal authority**, never the
component that happened to emit the observation.

| Value | Meaning |
| --- | --- |
| `control_plane` | A UTCP control operation initiated the termination. |
| `remote` | An authority outside UTCP — peer, provider, or runtime-side orderly completion — initiated it. |
| `runtime` | The runtime itself failed or disappeared; termination was not initiated by anyone. |
| `system` | UTCP reconciliation or lifecycle enforcement initiated it (for example origination timeout). |

`control_plane` and `remote` are new; `runtime` and `system` already exist and
keep their meaning. The current unconditional `termination_party = 'runtime'`
on the observation path is legacy and **MUST BE REPLACED** (§12).

Expected pairings:

```text
requested            -> control_plane
remote               -> remote
runtime_lost         -> runtime
origination_timeout  -> system
```

### 5. Requested-termination authority

A persisted RuntimeOperation is the canonical authority establishing
control-plane termination intent.

A runtime terminal fact for a CallLeg is classified `requested` when **all** of
the following hold:

1. A RuntimeOperation of type `call.hangup`, `call.leg.hangup`, or
   `call.leg.cancel_origination` exists for that CallLeg, or for the Call that
   owns it.
2. That operation was **created before** the runtime terminal fact's
   `observed_at`.
3. That operation is not in a terminal-failure state. Intent counts from
   creation onward — `pending`, `leased`, `running`, `retry_scheduled`, and
   `succeeded` all establish intent. The operation is deliberately **not**
   required to have reached `succeeded`, because Asterisk may destroy the
   channel and emit the terminal event before the operation record flips.

Answers to the questions this decision had to settle:

1. **Is persisted RuntimeOperation intent authoritative?** Yes. It is the only
   durable, tenant-scoped, chronologically ordered record of control-plane
   termination intent, and it already exists.
2. **Must intent precede the runtime event?** Yes — by operation `created_at`
   versus observation `observed_at`. An operation created after the terminal
   fact did not cause it.
3. **Which operation states count?** All non-terminal-failure states from
   creation onward, as enumerated above.
4. **Races.** If a remote hangup arrives while a local hangup operation is in
   flight, the call is classified `requested`. UTCP asked for the termination
   and the termination happened; that is the deterministic and defensible
   reading. This ADR accepts that a true simultaneous race may occasionally
   over-attribute to the control plane, and records that closing that gap
   requires runtime cause facts, which is Gap E.
5. **Does the first causal terminal fact win?** Yes.
6. **Is terminal metadata still write-once?** Yes, unchanged (§10).

No new intent record, marker column, or state machine is introduced.

### 6. Remote-termination authority

A runtime terminal fact for a CallLeg is classified `remote` /
`termination_party = remote` when it is an **orderly** terminal fact (§8) and
no control-plane intent qualifies under §5.

This is the correct canonical result when a provider-originated hangup reaches
UTCP. This ADR defines that semantic outcome; it does **not** decide how the
provider's in-dialog BYE is routed to the managed runtime, which remains Gap B.
The semantic model is defined now so that Gap B's later repair produces correct
canonical state immediately.

### 7. Runtime-loss authority and the `call.leg.failed` producer

`runtime_lost` means the runtime, node, or channel **disappeared without
orderly terminal evidence**. It does not mean, and **MUST NOT** be derived
from, an ordinary `ChannelDestroyed` event — a `ChannelDestroyed` is orderly
terminal evidence and is therefore never runtime loss.

Genuine runtime loss is a **domain** determination, not an adapter one, because
only the domain can observe the absence of expected evidence. The canonical
producer of `call.leg.failed` is the UTCP domain, driven by existing
runtime-health authority: a non-terminal CallLeg bound to a RuntimeNode whose
observed state has left `ready` (for example the `stale` transition already
produced by `ProjectionService::markStale`), or whose event-source epoch has
been lost, without an orderly terminal fact for that leg.

`call.leg.failed` maps to `CallState::Failed` with
`termination_reason = runtime_lost` and `termination_party = runtime`. This
ADR establishes the authority and the contract; it does not implement the
producer.

### 8. CallLeg terminal event contract

```text
call.leg.terminated
  Produced from an orderly runtime terminal fact for the CallLeg's canonical
  runtime channel. Maps to CallState::Completed.
  Canonical reason is assigned by the domain: requested (§5) or remote (§6).

call.leg.failed
  Produced by the domain when runtime loss is determined (§7).
  Maps to CallState::Failed with reason runtime_lost.
```

An "orderly runtime terminal fact" is a runtime event that positively asserts
the channel has ended — Asterisk `ChannelDestroyed`, FreeSWITCH
`CHANNEL_HANGUP_COMPLETE`, or a simulator termination observation. Absence of
events is never an orderly terminal fact.

### 9. Asterisk event semantics

**`ChannelDestroyed`** — the canonical orderly terminal fact for an Asterisk
channel. It carries runtime facts (`cause`, `cause_txt`, `tech_cause`) that
describe *how* the channel ended. It does **not** encode UTCP control intent,
and the adapter **MUST NOT** infer `requested`, `remote`, or `runtime_lost`
from it. The listener **MUST** preserve `cause`, `cause_txt`, and `tech_cause`
when present, plus the source event type, as observation payload facts.

**`StasisEnd`** — a control-ownership transition fact: the channel has left the
Stasis application. Under official Asterisk semantics a channel may leave
Stasis and continue executing dialplan, so `StasisEnd` is **NOT** terminal.

```text
StasisEnd MUST NOT terminalize a CallLeg.
```

The current `stasis_end => 'call.leg.terminated'` mapping is legacy and **MUST
BE REMOVED** (§12). No repository case was found in which `StasisEnd` is
intentionally the terminal authority; in the proven V1-A corridor the Local
`;1` control channel emits `StasisEnd` and `ChannelDestroyed` for the same
channel, so removing the mapping loses no terminal coverage and additionally
eliminates a duplicate terminal observation per channel.

**`ChannelHangupRequest`** — indicates that a hangup was requested *on the
channel*. It is a runtime fact and does **not** prove that UTCP requested it; a
provider BYE produces one just as a UTCP ARI hangup does. It **MUST NOT** be
used as the requested-versus-remote discriminator. It is not currently
subscribed in `config/asterisk_ari.php`, and this ADR does not require adding
it; if it is added later it enters as a Layer 2 runtime fact only.

**ARI `DELETE /channels/{channelId}`** — UTCP's own hangup execution. This
represents known control intent because UTCP initiated it, and it is already
captured durably as the RuntimeOperation that §5 uses. Intent is taken from the
operation record, not re-derived from later runtime events.

### 10. FreeSWITCH semantics

`Hangup-Cause` is a Layer 2 runtime fact. It describes the cause, not the
initiating authority, and **MUST NOT** be written directly into
`termination_reason`. FreeSWITCH adapters **MUST** preserve `Hangup-Cause` (and
any native hangup-disposition fact, where exposed) as observation payload
facts, and the domain assigns canonical meaning by the same §5–§7 rules used
for Asterisk.

Runtimes may preserve different native facts. Canonical termination semantics
remain runtime-neutral: Asterisk and FreeSWITCH converge on the same canonical
`termination_reason` and `termination_party` model even though their preserved
raw facts differ. This ADR does not require any runtime to expose fields it
does not natively have.

### 11. Control channel versus provider leg

The Local control channel (`;1`) and the provider-facing PJSIP leg are distinct
authorities.

* Control-channel lifecycle facts describe UTCP's ARI control ownership of the
  CallLeg. They are authoritative for CallLeg lifecycle.
* Provider-leg signaling and runtime facts describe the external call outcome.
  They are authoritative for provider outcome.

```text
Local control-channel cause MUST NOT be interpreted as provider failure cause.
```

In V1-A the CallLeg's canonical `runtime_channel_id` is the Local `;1` channel,
so its cause facts are the ones most readily reachable — which is precisely the
misinterpretation risk this clause exists to prevent. Mapping provider
signaling outcomes into canonical failure detail is Gap E and is out of scope.

### 12. Adapter authority invariant

```text
A runtime adapter MUST NOT synthesize a canonical termination reason it did
not directly observe.
```

Specifically, an adapter **MUST NOT** transform a missing runtime fact into a
strong canonical assertion. The single permitted exception is an adapter that
is itself the execution authority and therefore genuinely knows the control
intent — the deterministic simulator, which emits `requested` from its own
`call.leg.hangup` execution path. That exception is legitimate and is retained.

### 13. Domain authority rule

On receiving a terminal observation for a non-terminal CallLeg, the domain
derives canonical meaning from, in combination:

```text
canonical operation intent (§5)
+ runtime terminal facts (§9, §10)
+ existing Call/CallLeg lifecycle state
+ write-once terminal metadata (§14)
```

and writes `termination_reason`, `termination_party`, and the terminal state
(`Completed` or `Failed`). The domain is the only writer of canonical
termination meaning.

### 14. Write-once and race semantics

Terminal metadata remains **write-once**, as established by ADR-023 and the
existing `CallObservationProcessorTest` invariants. This ADR changes nothing
about that.

Precedence for concurrent or duplicated terminal events:

1. The first causal terminal fact to be applied wins and fixes
   `termination_reason`, `termination_party`, `terminated_at`, and the terminal
   state.
2. Later terminal facts for the same leg — a duplicate `ChannelDestroyed`, a
   trailing `StasisEnd`, or a repeated observation — are ignored idempotently
   and leave terminal metadata unchanged.
3. Control-plane intent that existed before the winning terminal fact makes
   that fact `requested`, per §5.

This ADR does **not** address the general problem of an observation whose
`observed_at` precedes a terminalization but whose processing follows it. That
is Gap A and is untouched here.

## Consequences

### Required implementation seams

```text
AsteriskAriEventListener
  Preserve cause, cause_txt, tech_cause and the source event type as
  observation payload facts.

AsteriskAriEventNormalizer
  Stop synthesizing runtime_lost. Normalize runtime facts without inventing a
  canonical reason. Remove stasis_end from the call.leg.terminated mapping.

CallObservationProcessor / CallDomainService
  Derive termination_reason and termination_party from operation intent plus
  runtime facts plus lifecycle state. Replace the hardcoded
  termination_party = 'runtime'. Remove the unconditional
  ?? 'requested' fallback in favour of the §5/§6 determination.

call.leg.failed production
  Add the domain-owned runtime-loss producer defined in §7.

FreeSwitchEventNormalizer
  Preserve Hangup-Cause as a runtime fact; stop writing it directly into
  canonical termination_reason.
```

### Legacy behaviors and their disposition

| Current behavior | Disposition |
| --- | --- |
| Asterisk `ChannelDestroyed` → blanket `runtime_lost` | **MUST REMOVE.** The adapter **MUST NOT SYNTHESIZE** a canonical reason. |
| Asterisk `StasisEnd` → `call.leg.terminated` | **MUST REMOVE.** `StasisEnd` **MUST NOT** terminalize a CallLeg. |
| Domain missing `termination_reason` → `requested` | **MUST REPLACE** with the §5/§6 determination. The domain **MUST NOT FALL BACK** to `requested` without qualifying intent. |
| Domain `termination_party` hardcoded `runtime` | **MUST REPLACE** with the initiating causal authority (§4). |
| FreeSWITCH raw `Hangup-Cause` as `termination_reason` | **MUST REPLACE.** Preserve as a runtime fact; canonical meaning assigned by the domain. |
| `call.leg.failed` recognized but never produced | **MUST ADD** the domain-owned producer (§7). |

No feature gate, compatibility switch, legacy mode, or operator toggle is
introduced. Conflicting behavior is removed rather than preserved behind a
fallback.

### Required regression coverage

```text
Requested hangup
  canonical call.hangup -> runtime termination
  -> termination_reason = requested
  -> termination_party = control_plane
  -> never runtime_lost

Remote hangup
  no qualifying local intent + orderly terminal fact
  -> termination_reason = remote
  -> termination_party = remote
  -> never requested

Genuine runtime loss
  runtime disappearance without orderly terminal evidence
  -> call.leg.failed, CallState::Failed
  -> termination_reason = runtime_lost, termination_party = runtime

StasisEnd
  StasisEnd alone MUST NOT terminalize a CallLeg

ChannelDestroyed
  cause / cause_txt / tech_cause preserved as runtime facts
  canonical meaning assigned by the domain, not the adapter

Duplicate terminal events
  ChannelDestroyed plus trailing StasisEnd, or repeated observations
  -> deterministic write-once terminal metadata, single audit record

Cross-runtime consistency
  Asterisk and FreeSWITCH preserve different raw facts but converge on the
  same canonical termination_reason and termination_party model
```

### Non-goals

This ADR explicitly does not address:

* **Gap A** — delayed-observation versus origination-timeout precedence, and
  the general observation-time convergence model.
* **Gap B** — provider in-dialog BYE routing, Record-Route, Contact, advertised
  SIP address, NodePort, or NAT handling. Only the canonical semantic outcome
  of a provider-originated hangup is defined.
* **Gap E** — SIP 4xx/5xx mapping, Q.850 taxonomy, `failure_class` and
  `failure_code` population, and provider-specific response mapping.
* **Gap F** — trust-boundary header stripping and packet-capture
  instrumentation.
* **K5** — distributed placement and host lifecycle.
* **RMA** — recording and media archive.

No generic telephony failure framework, pluggable termination policy engine,
rules DSL, or runtime-specific policy registry is introduced. The contract is a
fixed deterministic vocabulary with a fixed authority model.
