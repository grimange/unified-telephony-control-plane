# V1 Gap E — SIP/Q.850 Failure Taxonomy and Canonical Failure Authority Audit

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `d6d7abeca8cd077f5adac12ffa298a90053d3028`
(`fix(v1): close origination timeout precedence`)

## Verdict

`V1_GAP_E_FAILURE_TAXONOMY_AUTHORITY_UNRESOLVED`

One material fact is missing and it is load-bearing for the entire mapping
matrix: **no provider failure fact has ever entered UTCP, and none currently
can.** `AsteriskAriClient::sanitizeAriEvent()` rebuilds every ARI event from a
strict allow-list that omits `cause`, `cause_txt`, and `tech_cause`, so the
ADR-030 §9 preservation block in `AsteriskAriEventListener` is unreachable dead
code. Live data confirms the consequence: **0 of 58** stored
`asterisk.ari.channel.destroyed` receipts and **0 of 61** `call.leg.terminated`
observations carry any cause fact, and `failure_class`/`failure_code` are NULL
on **every** row of `calls` and `call_legs`.

Defining the SIP→canonical mapping now would require guessing which channel and
which field carry the provider's final status. That guess is avoidable: the
blocking defect is a bounded, ADR-030-mandated repair, after which one small
controlled proof settles the mapping.

Everything else Gap E needs — schema, canonical/raw separation, ADR-030
interaction, `Completed`/`Failed` rule, unknown handling, cross-runtime
convergence, legacy-authority status — **is resolved below.**

## Existing schema — sufficient

`2026_08_16_100000_create_c6_call_tables.php` creates on **both** `calls`
(lines 29-30) and `call_legs` (lines 65-66):

```text
failure_class  string(80)   nullable
failure_code   string(120)  nullable
```

No CHECK constraint, no index, no model cast (both tables are accessed through
the query builder, not Eloquent models). `observed_state`, `direction`, and
`desired_state` carry CHECK constraints; `termination_reason` (80),
`termination_party` (24), `failure_class`, and `failure_code` do not.

```text
SCHEMA_SUFFICIENT
```

## Current writers — none

An exhaustive search of `app/` for `failure_class`/`failure_code` returns 96
hits, and **not one writes `calls` or `call_legs`.** Every hit belongs to a
different subsystem:

```text
runtime_operations.last_failure_class / last_failure_code   FailureClass enum, control-plane operations
runtime_event_receipts.failure_class / failure_code         ingestion rejects
telephony_sessions / conferences / conference_participants  their own columns
signaling_registration_observations.failure_class           registration health
runtime_node_drains.failure_class / failure_code            drain lifecycle
external_trunk_projection_artifacts.failure_code            projection
RouteDecision::failureCode                                  in-memory route evaluation ('no_matching_route')
MetricsController                                           Prometheus labels
```

The only two `CallDomainService` hits (lines 81, 436) read
`RouteDecision::toArray()['failure_code']` — a distinct in-memory route-evaluation
concept used to build an exception message. Neither touches the columns.

Live confirmation: `call_legs` non-null `failure_class` = **0**, `failure_code` =
**0**; `calls` likewise **0** and **0**.

```text
calls.failure_class, calls.failure_code,
call_legs.failure_class, call_legs.failure_code
= DEAD COLUMNS, zero production writers
```

## Current readers — none

`CallResource` exposes `id, tenant_id, direction, state, desired_state,
termination_reason, terminated_at, correlation_id, created_at, updated_at,
destination_ref`. `CallLegResource` exposes `id, call_id, direction, role, state,
runtime_node_id, runtime_channel_id, remote_identity, bridged_to_leg_id,
bridged_at, termination_reason, terminated_at, telephony_session_id`.

Neither exposes `failure_class`, `failure_code`, `termination_party`, or
`answered_at`. Every web-UI reference (`CallConsoleView.vue:194`,
`ConferenceOperationsView.vue:178`, `RuntimeNodesView.vue:1016`,
`api/platform.ts`) reads **RuntimeOperation**, conference-participant, or
runtime-node failure fields — never Call/CallLeg. `CallQueryService`'s timeline
allow-lists observation payload keys to
`['digit','duration_ms','termination_reason','runtime_correlation_id']`.

**Defining the vocabulary has zero compatibility implications.**

## Existing failure vocabularies

| Value | Classification | Where |
| --- | --- | --- |
| `requested`, `remote`, `runtime_lost`, `origination_timeout` | canonical (ADR-030 §3) | domain |
| `origination_failed` | **canonical value outside ADR-030 §3's vocabulary** | `CallOriginationReconciler.php:45`; 1 live row |
| `expired` | canonical, TelephonySession only | out of Call scope |
| `busy`, `no_answer` | **test-only** | `CallDomainServiceTest:323,378,435`, `CallObservationProcessorTest:294,301`; written by no production path |
| `NORMAL_CLEARING` and other raw `Hangup-Cause` | Layer-2 raw | FreeSWITCH, preserved as `hangup_cause` |
| `FailureClass` enum (`transient_transport`, `runtime_unavailable`, `invalid_request`, `conflict`, `unsupported_capability`, `internal_error`, `timeout`, `authentication_failed`) | canonical **for RuntimeOperations**, not for Calls | control-plane kernel |

Live canonical distribution across all `call_legs`:

```text
completed / runtime_lost        / runtime        13   (historical, pre-ADR-030 blanket fallback)
completed / remote              / remote          3   (post-ADR-030, correct)
failed    / origination_timeout / system          2
completed / requested           / control_plane   1
failed    / origination_failed  / system          1
```

**No provider pre-answer failure outcome has ever been recorded.**

## Asterisk channel topology (V1-A)

`AsteriskAriClient::asteriskEndpoint()` returns `Local/<dest>@utcp-outbound`, and
the originate sets `channelId = utcp-call-leg-<legId>` with inherited variables
`__UTCP_CALL_LEG_ID`, `__UTCP_ROUTE_DECISION_ID`, `__UTCP_TRUNK_ENDPOINT_ID`,
`__UTCP_CALLER_IDENTITY_ID`.

```text
Local ;1  utcp-call-leg-<legId>   ARI-owned, canonical runtime_channel_id
   |
Local ;2  ...;2                   enters [utcp-outbound] dialplan
   |
Dial(PJSIP/<dest>@kamailio-edge,30,b(utcp-outbound-predial^s^1))
   |
PJSIP     PJSIP_kamailio-edge-…   provider-facing leg -> Kamailio -> provider
```

Live receipts for one proven call show **all three** channels emitting
`ChannelDestroyed`:

```text
utcp-call-leg-153e081d-…      Local_97001_utcp-outbound-00000001_1   subject 153e081d-… (the CallLeg)
utcp-call-leg-153e081d-…;2    Local_97001_utcp-outbound-00000001_2   subject runtime:…;2
1788052689.5                  PJSIP_kamailio-edge-00000001           subject runtime:1788052689.5
```

The provider-facing PJSIP channel's terminal event **already reaches UTCP's
observation store today**. It is stored uncorrelated, as
`subject_id = runtime:<uniqueid>`, because no ingested field ties it to the
CallLeg.

### The dialplan discards the provider cause on the Local path

`infrastructure/docker/asterisk/config/extensions.conf`, confirmed byte-identical
in the running managed runtime via `dialplan show utcp-outbound`
(`[extensions.conf:8-10]`):

```text
exten => _.,1,NoOp(UTCP canonical outbound destination=${EXTEN})
 same => n,Dial(PJSIP/${EXTEN}@kamailio-edge,30,b(utcp-outbound-predial^s^1))
 same => n,Hangup()
```

After `Dial()` fails, a **bare `Hangup()`** runs with no cause argument.
`${DIALSTATUS}`, `${HANGUPCAUSE}`, and `HANGUPCAUSE(<chan>,tech)` are available
on `;2` at that point and are all discarded; no channel variable carries them
onward. This is exactly the ADR-030 §11 trap: whatever cause the Local `;1`
channel reports is a Local-bridge cause, not the provider's.

## The blocking defect

`AsteriskAriClient::readWebSocketMessage()` returns
`['type' => 'event', 'event' => $this->sanitizeAriEvent($decoded)]`, and
`sanitizeAriEvent()` rebuilds the event from a **closed allow-list**:

```text
type, asterisk_id, timestamp, args, bridge, channel, digit, duration_ms,
playback, recording
```

`cause`, `cause_txt`, and `tech_cause` are **not** in it and are discarded at the
WebSocket boundary. `sanitizeAriObject()` likewise reduces `channel` to
`id, name, state, caller.number, connected.number, media_uri, channels`,
dropping `dialplan` and `channelvars`.

Therefore `AsteriskAriEventListener.php:233-240` —

```php
if ($type === 'ChannelDestroyed') {
    foreach (['cause', 'cause_txt', 'tech_cause'] as $fact) {
        if (array_key_exists($fact, $event) && $event[$fact] !== null) {
```

— can never fire. It is dead code, and ADR-030 §9's requirement ("The listener
**MUST** preserve `cause`, `cause_txt`, and `tech_cause` when present") is **not
satisfied in the live system** despite being implemented in the listener and
normalizer.

The facts are genuinely emitted. The running Asterisk 20.20.1's own ARI schema
(`/var/lib/asterisk/rest-api/events.json`) declares:

```json
"ChannelDestroyed": { "properties": {
  "cause":      { "required": true, "type": "int",    "description": "Integer representation of the cause of the hangup" },
  "cause_txt":  { "required": true, "type": "string", "description": "Text representation of the cause of the hangup" },
  "tech_cause": { "type": "int", "description": "Integer representation of the technology-specific off-nominal cause of the hangup." },
  "channel":    { "required": true, "type": "Channel" } } }
```

`cause` and `cause_txt` are **required** on every `ChannelDestroyed`.

### Why the tests did not catch it

`AsteriskAriAdapterTest` (line ~972) feeds the **normalizer** a receipt payload
that already contains `'cause' => 16, 'cause_txt' => 'Normal Clearing',
'tech_cause' => 'SIP 200'` and asserts they survive normalization. No test
exercises `AsteriskAriClient::sanitizeAriEvent()`, the layer that strips them.
138 focused tests pass while the live system loses every fact.

That fixture also asserts `tech_cause` is the **string** `'SIP 200'`, while the
live ARI schema types it **int** — an unverified assumption about the very field
Gap E would depend on.

## FreeSWITCH — asymmetric and currently correct

`FreeSwitchEventNormalizer.php:116` copies `Hangup-Cause` into the observation
payload as `hangup_cause` for `call.leg.terminated`, satisfying ADR-030 §10. The
ESL client applies **no** allow-list equivalent to `sanitizeAriEvent`, and the
normalizer already reads `variable_*` channel variables (e.g.
`variable_sip_h_X-UTCP-Ingress-*`), so FreeSWITCH's native
`variable_sip_term_status`, `variable_sip_invite_failure_status`, and
`variable_hangup_disposition` are structurally reachable without a transport
change.

This environment holds **no FreeSWITCH call receipts at all** (every stored
receipt is `asterisk.ari.*`), so FreeSWITCH provider-failure facts are
structurally available but live-unproven.

**Cross-runtime asymmetry:** FreeSWITCH preserves its Layer-2 cause; Asterisk
does not. Vendor-neutral convergence is impossible until the Asterisk side is
repaired.

## Kamailio — capable, but not the smallest seam

`t_on_failure("RUNTIME_EXTERNAL_TRUNK_AUTH")` is armed on the provider-bound
INVITE (`kamailio-configmap.yaml:372`). The failure route handles `401|407` via
`uac_auth()` and re-relay; any other final status falls through and the provider
response is relayed back to Asterisk unmodified.

```text
sees final SIP responses          yes (t_check_status is already used)
failure route exists              yes, auth-only today
logs status                       only provider_auth_failed
projects status to UTCP           NO — no signaling-observation ingestion path
correlates to canonical CallLeg   not after remove_hf strips X-UTCP-* (Gap F boundary)
```

Kamailio is a legitimate Layer-2 signaling fact source but making it one requires
building a new observation ingestion route. Asterisk's ARI already carries the
same information on a channel whose terminal event UTCP **already receives** —
that is the smaller seam.

**Gap F interaction:** Gap E does **not** require Gap F. The provider outcome is
read from the Asterisk PJSIP channel inside the runtime, never from the wire, so
no packet capture and no change to header stripping is needed.

## What is resolved

### `failure_class` — canonical definition

A small, fixed, vendor-neutral answer to one question: *what kind of thing
prevented this call from completing normally?* It is canonical UTCP semantic
state (Layer 3), never a vendor string. It is populated **only** on a terminal
CallLeg/Call whose `observed_state` is `failed`, and is NULL for every
`completed` outcome.

### `failure_code` — canonical definition

A canonical **symbolic UTCP code**, not a raw SIP status and not a Q.850 cause.
Raw protocol values stay in `runtime_observations.payload` as Layer-2 evidence.
Putting `486` or `NORMAL_CLEARING` into `failure_code` would reintroduce exactly
the vendor leak ADR-030 §2 forbids, and would make Asterisk and FreeSWITCH
disagree on identical outcomes.

### Canonical vs raw separation

```text
canonical (calls / call_legs)   failure_class, failure_code
raw evidence (runtime_observations.payload)
                                Asterisk: cause, cause_txt, tech_cause
                                FreeSWITCH: hangup_cause (+ sip_term_status when added)
```

No second business authority; `runtime_observations` is the existing evidence
store and already retains facts regardless of canonical outcome (proven by the
Gap A audit).

### Relationship to `termination_reason` / `termination_party`

Gap E adds **detail only**. ADR-030 §3's four values stay closed; Gap E
introduces no new `termination_reason`. A provider pre-answer failure is an
orderly runtime terminal fact with no qualifying local intent, so ADR-030 §6
already classifies it `remote` / `remote`. `failure_class`/`failure_code` carry
the specificity that `remote` deliberately does not assert.

### `Completed` vs `Failed`

```text
answered_at IS NOT NULL  -> Completed   (the call happened; how it ended is termination_reason)
answered_at IS NULL and an orderly provider final failure response was observed
                         -> Failed, with failure_class/failure_code populated
```

This keeps §27's proven Gap B/D behaviour intact: answered → provider BYE →
`completed / remote / remote` with **no** failure classification, even though a
`cause` fact will exist once preservation is restored. Failure detail is eligible
**only** when `answered_at IS NULL`.

### Preserved authorities

```text
runtime_lost / runtime        runtime disappearance without orderly terminal evidence.
                              A provider 503 is an orderly terminal fact and MUST NOT
                              become runtime_lost.
origination_timeout / system  Gap A closed; absence-of-observation determination.
                              Gap E MUST NOT overwrite it — the timeout terminalizes
                              before any provider fact arrives, and first-applied wins.
requested / control_plane     ADR-030 §5. A 487 following UTCP cancellation is a
                              successful requested termination, classified from persisted
                              RuntimeOperation intent, never from the status code.
401/407 challenge             ordinary authenticated retry (Kamailio uac_auth), never a
                              call failure. Only a final 401/403 after retry is one.
```

### Unknown / unmapped

When a raw fact is preserved but no canonical mapping exists, both canonical
fields remain **NULL** and the raw fact stays queryable in the observation.
Never a silent fallback to a plausible-but-wrong class. A generic
`failure_class` bucket is acceptable only if the live proof shows an outcome that
is genuinely known-failed yet unclassifiable.

### Conflicting legacy failure authority

```text
NONE
```

ADR-030's implementation already removed the blanket `runtime_lost` synthesis,
the `?? 'requested'` fallback, the hardcoded `termination_party = 'runtime'`, and
the raw `Hangup-Cause` → `termination_reason` write. No production path writes a
vendor value into canonical termination state today. The 13 historical
`completed / runtime_lost / runtime` rows predate that fix and are frozen
history, not an active authority.

Two defects requiring correction are **omissions**, not competing authorities:
the `sanitizeAriEvent` allow-list, and `origination_failed` being outside
ADR-030 §3's vocabulary (recorded; not a Gap E precedence question).

## The single unresolved fact

```text
Which Asterisk channel and which ARI field carry the provider's final SIP status
and Q.850 cause for a pre-answer provider failure, and how that channel is
correlated to the canonical CallLeg.
```

Concretely unanswerable from repository evidence:

1. Does `tech_cause` on the provider-facing PJSIP channel carry the SIP response
   code (486/404/503), and as `int` or `string`? The schema says int; the only
   test fixture says `'SIP 200'`.
2. Does the Local `;1` channel's `cause` reflect the provider's Q.850, or the
   dialplan's bare `Hangup()` default (16)? `extensions.conf:10` implies the
   latter, but Local-half cause propagation is version-specific.
3. Can the PJSIP channel be correlated to the CallLeg without enabling
   `channelvars` in `ari.conf`? `ari.conf` is generated by
   `infrastructure/docker/asterisk/entrypoint:20-31` with no `channelvars=`, and
   `sanitizeAriObject` drops `channelvars` anyway.

These cannot be answered from stored evidence because the allow-list has
prevented any such fact from ever being recorded, and because **no provider
pre-answer failure has ever occurred in this environment**. Answering them by
inference would make the entire SIP matrix a guess.

## Bounded next step — restore ADR-030 §9 fact preservation

This is a strict prerequisite, independently justified by ADR-030 §9 regardless
of Gap E's eventual taxonomy, and it is what makes the facts observable.

```text
apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriClient.php
  sanitizeAriEvent()   add 'cause' (int|null), 'cause_txt' (bounded string),
                       'tech_cause' (int|string|null) to the allow-list
  sanitizeAriObject()  add 'dialplan' (context/exten/app) and 'channelvars'
                       (bounded, allow-listed keys only) ONLY if the proof shows
                       correlation requires them

infrastructure/docker/asterisk/entrypoint  (lines 20-31)
  add channelvars = UTCP_CALL_LEG_ID to [general] ONLY if correlation requires it

apps/api/tests/Feature/Asterisk/AsteriskAriAdapterTest.php
  add the missing client-level regression: a raw ARI ChannelDestroyed frame
  carrying cause/cause_txt/tech_cause must survive sanitizeAriEvent() into the
  receipt payload — the coverage gap that allowed this defect
```

No schema change, no new service, no config gate, no taxonomy yet.

### Acceptance for that step

| # | Case | Required outcome |
| --- | --- | --- |
| 1 | raw ARI `ChannelDestroyed` with `cause`/`cause_txt`/`tech_cause` | all three survive `sanitizeAriEvent()` into the receipt payload |
| 2 | `ChannelDestroyed` without them | keys absent, no synthesis, no error |
| 3 | non-`ChannelDestroyed` events | payload shape unchanged |
| 4 | oversized / non-scalar cause values | bounded and type-coerced, never unbounded passthrough |
| 5 | `PayloadSafety` | no sensitive key introduced; existing redaction unchanged |
| 6 | existing 138 focused tests | still pass |
| 7 | adapter authority | still no canonical reason synthesized (ADR-030 §12) |

## Then: one small controlled live proof

Two or three canonical V1-A Calls through the existing registration/NAT trunk to
destinations that produce deterministic provider failures — at minimum one busy
and one not-found, plus one already-proven answered call as the negative control.
Capture, for each of the three channels, which fields carry which values.

That is the entire proof. It is not a provider test matrix, and it settles all
three unresolved questions at once. Only then is the SIP/Q.850 mapping matrix
defensible.

## Provisional taxonomy — contingent, not adopted

Recorded so the follow-up has a starting shape. **Not** a decision; each row's
`failure_code` is only defensible once the raw-fact source is proven.

| failure_class | failure_code | Typical provider fact | Terminal state | termination_reason | termination_party |
| --- | --- | --- | --- | --- | --- |
| `remote_rejection` | `busy` | 486, 600; Q.850 17 | Failed | `remote` | `remote` |
| `remote_rejection` | `declined` | 603, 403 | Failed | `remote` | `remote` |
| `unreachable` | `destination_not_found` | 404, 484; Q.850 1/3 | Failed | `remote` | `remote` |
| `unreachable` | `temporarily_unavailable` | 480; Q.850 18/20 | Failed | `remote` | `remote` |
| `no_answer` | `no_answer` | 408 after ringing; Q.850 19 | Failed | `remote` | `remote` |
| `provider` | `service_unavailable` | 500, 502, 503; Q.850 38/41 | Failed | `remote` | `remote` |
| `provider` | `authentication_failed` | final 401/403 after `uac_auth` retry | Failed | `remote` | `remote` |
| (none) | (none) | 487 after UTCP cancel | Completed | `requested` | `control_plane` |
| (none) | (none) | answered, then provider BYE | Completed | `remote` | `remote` |
| (none) | (none) | runtime disappearance | Failed | `runtime_lost` | `runtime` |
| (none) | (none) | no observation before deadline | Failed | `origination_timeout` | `system` |
| NULL | NULL | preserved raw fact with no mapping | per the observed terminal fact | per ADR-030 | per ADR-030 |

Provisional precedence, to be confirmed by the proof:

```text
1. persisted RuntimeOperation intent (ADR-030 §5)  -> requested, regardless of status code
2. provider-leg SIP final response
3. provider-leg Q.850 cause
4. runtime/transport error
5. no mapping -> NULL / NULL
```

Cross-runtime convergence is mandatory: identical provider outcomes on Asterisk
and FreeSWITCH must yield identical `failure_class`, `failure_code`,
`observed_state`, `termination_reason`, and `termination_party`, however their
raw facts differ.

## Verification performed

Read-only throughout. `AsteriskAriAdapterTest`, `FreeSwitchEventObservationTest`,
`CallDomainServiceTest`, `CallObservationProcessorTest` — **138 passed
(705 assertions)** at exact HEAD in the `utcp-api-test-current` image with the
working tree mounted read-only. Live cluster inspected read-only: receipt and
observation payloads, canonical termination distribution, failure-column
occupancy, the managed runtime's loaded dialplan, its generated `ari.conf`, and
Asterisk 20.20.1's own ARI event schema. No Call was placed, no production source
changed, no runtime or Kubernetes state mutated.

## Boundary

Gap A, B, C, and D remain closed. Gap F remains `PROOF_GAP_ONLY` and is not a
prerequisite for Gap E. No K5 or RMA work was started. ADR-031 stable-public-edge
acceptance remains `DEFERRED_BY_ENVIRONMENT`, not abandoned.
