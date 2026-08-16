# V0-B — Browser Conference-Leg Authority and Correlation Audit

## Verdict

    V0_B_BROWSER_CONFERENCE_LEG_AUDIT_REQUIRES_ARCHITECTURE_CLARIFICATION

The authoritative V0 contract requires the browser's real signaling and media
leg to be admitted into the conference. It is not: the browser leg and the
admitted participant leg share no identifier, no channel, no bridge, no runtime
node, and no media path. The fixed `sip:9900@{realm}` destination is a T3-S2A
media fixture, not a conference entry.

The cutover is **not** a bounded implementation seam today, because four
required authorities are unimplemented and two are not modelled at all. This
audit therefore returns the smallest unresolved architecture question rather
than a Codex packet.

## Correction to V0-A

V0-A concluded `V0 COMPLETE`. That closure was premature on one specific point.
V0-A audited the reference-client lifecycle as one system and correctly found no
authority contradiction inside it, but it did not test this row of the phase
table, committed at `943c965` and reproduced verbatim:

> | V0 Natural login, SIP registration, and conference admission | Planned |
> The target vertical slice is natural login -> SIP REGISTER over WSS ->
> conference admission, **including proof that the T3 internal application-dialog
> route uses canonical Conference RuntimeNode eligibility** and has automatic
> cutoff/restoration.

That exit criterion is unmet. The V0 phase row and the roadmap marker have been
restored to in-progress by this audit. Every corridor V0-A proved remains
proven; only the closure decision is withdrawn.

## Method

Evidence-only, repository-first. No application source, Kubernetes topology,
Kamailio/Asterisk/rtpengine configuration, or runtime state was modified, and no
live mutation was performed. The correlation table reuses values from the
already-accepted live proofs rather than manufacturing new ones.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing large uncommitted RNP/RNM/V0 working tree (95 paths)
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

## Authoritative V0 conference contract

Classification: **ACTUAL BROWSER CONFERENCE LEG**.

The strongest accepted document is the initial implementation plan, which
`CLAUDE.md` names as the product-boundary and roadmap authority.
`docs/unified-telephony-control-plane-initial-implementation-plan.md:1697`,
"Phase V0 — Natural Login, SIP Registration, and Conference Admission Vertical
Slice", states the slice as one chained flow:

```text
Natural browser login
 ↓ Authenticated tenant and capability context
 ↓ Short-lived telephony session
 ↓ SIP REGISTER over WSS through Traefik and Kamailio
 ↓ Normalized registration observation
 ↓ Conference admission request through UTCP
 ↓ Normalized runtime adapter operation
 ↓ Asterisk conference and bridge execution
 ↓ Media through rtpengine
 ↓ Observed call legs and conference membership
 ↓ UI shows REGISTERED and CONFERENCE_JOINED
```

with exit criteria including "microphone permission is requested through the
natural browser path" and "**call-leg** and membership observations appear in the
UI".

Three features of that wording carry the contract:

1. It is a **single chain**, not a set of parallel proofs. The registered
   browser's session flows through admission into *Asterisk conference and
   bridge execution* and then *media through rtpengine*.
2. It requires **call legs**, plural and distinct from membership. A call leg
   positioned between rtpengine media and conference membership is the browser's
   own leg.
3. It requires the **microphone** to be captured through the natural browser
   path. Capturing microphone audio has no purpose in the slice unless that audio
   reaches the conference.

`docs/roadmap/implementation-roadmap.md:88-101` restates the same chain. Nothing
in any accepted ADR, architecture document, or roadmap entry narrows V0 to an
authorization/projection proof, and nothing blesses a split-leg model.

Corroborating deferral, `docs/decisions/ADR-020` §"Deferred to V0 / T4":

> "Browser SIP and conference admission; host UDP publication for
> browser-reachable media; **Kamailio INVITE route authority consuming the
> canonical RuntimeNode eligibility projection**; multi-relay selection and
> failover; …"

ADR-020 therefore assigns to V0 exactly the capability that is missing: a
Kamailio INVITE route driven by canonical RuntimeNode eligibility.

## Current browser SIP path

    browser SIP.js UserAgent (registered identity ts-<session>)
      → INVITE sip:9900@sip.utcp.local.test
      → Traefik :443 → HTTPRoute sip-wss → kamailio:8080 (WSS)
      → Kamailio: digest challenge, then route[APPLICATION_RUNTIME_RELAY]
      → $du = sip:application-runtime-sip.utcp-runtime.svc.cluster.local:5060
      → Service application-runtime-sip
          selector utcp.dev/runtime-selection=selected-application-runtime
      → Pod asterisk-ari (base T0/T2/T3 Asterisk, 10.42.1.33)
      → context from-kamailio, extension 9900
      → Answer(); Echo(); Hangup()
    media: rtpengine, interface browser/<pod>!127.0.0.1 ↔ runtime/POD!POD

## Current conference participant path

    POST /api/v1/conferences/{id}/participants/self
      → ConferenceController::joinSelf
      → TelephonyDomainService::admitSelf → admitParticipant
      → conference_participants row (desired admitted)
      → reconciliation wake at the participant desired generation
      → ConferenceParticipantReconciler
      → runtime operation conference.participant.ensure
      → AsteriskRuntimeAdapter::ensureParticipant
      → AsteriskAriClient::ensureParticipantChannel on the BOUND RuntimeNode
      → ARI POST channels
           endpoint  = Local/participant@utcp-conference-proof/n
           channelId = utcp-part-<participantId>
           callerId  = "UTCP participant <participantId>"
           app       = <profile stasis application>
      → ARI POST bridges/utcp-conf-<conferenceId>/addChannel

## 9900 route classification

    REFERENCE CONNECTIVITY FIXTURE

Specifically a **T3-S2A media-plane fixture**. Every repository reference agrees:

| Location | Content |
|---|---|
| `infrastructure/kubernetes/overlays/local/runtime/extensions.local.conf:2` | `exten => 9900,1,NoOp(UTCP local T3-S2A media fixture)` then `Answer(); Echo(); Hangup()` |
| `tools/t3-media-prover/prover.mjs:45` | `extension: env('UTCP_T3_MEDIA_PROVER_EXTENSION', '9900')` |
| `tools/t3-media-prover/sip-dialog-test.mjs:18` | `initialRequestUri: 'sip:9900@sip.example.test'` |
| `infrastructure/kubernetes/overlays/local/proof/t3-media/job.yaml:43` | media-proof job extension `"9900"` |
| `infrastructure/docker/freeswitch/config/dialplan/utcp.xml:4` | FreeSWITCH parity fixture on `^9900$` |
| `apps/api/app/TelephonyDomain/TelephonyDomainService.php:1229` | `return 'sip:9900@'.config('telephony_signaling.realm', …)` |

Two structural facts confirm it is not a conference entry:

* **`Echo()` is a loopback test application.** It returns the caller's own audio
  and joins nothing. It cannot mix, cannot be joined, and no other participant
  can hear it.
* **The committed base dialplan rejects every SIP destination.**
  `infrastructure/docker/asterisk/config/extensions.conf`:

      [from-kamailio]
      exten => _.,1,NoOp(UTCP internal application dialog rejected destination=${EXTEN})
       same => n,Hangup(21)

      #tryinclude "extensions.local.conf"

  The base image therefore has **no** browser-reachable conference entry at all.
  Extension 9900 exists only because the *local overlay* `extensions.local.conf`
  is tryincluded, and that file declares itself a T3-S2A media fixture.

The only repository artefact that treats 9900 as a conference destination is
`referenceClientSignalingDestination()` and the two tests that assert its
constant output. It contains no conference, participant, telephony-session,
RuntimeNode, or binding identity — it is a literal string built from the SIP
realm.

## Kamailio routing authority

    route[APPLICATION_RUNTIME_RELAY] {
        $du = "sip:application-runtime-sip.utcp-runtime.svc.cluster.local:5060;transport=udp";
        …
    }

The destination is a single hardcoded service URI. A case-insensitive search of
`infrastructure/kubernetes/base/platform/kamailio-configmap.yaml` for
`conference`, `participant`, `runtime_node`, `runtime-node`, and `binding`
returns **0 matches**. The only other `$du` assignments are the in-dialog alias
handling added by T3-S2A.

Kamailio therefore has **no** routing mechanism keyed on conference ID,
participant ID, telephony session, runtime binding, RuntimeNode, or any
signaling token. No `dispatcher`, `htable`, or database route lookup consumes
control-plane state.

Which runtime receives application dialogs is decided by a **static Kubernetes
label**, not by the control plane:

    Service application-runtime-sip
      selector: utcp.dev/runtime-selection = selected-application-runtime
    carried by infrastructure/kubernetes/base/runtime/asterisk-ari-deployment.yaml:23
      (or the FreeSWITCH component in the parity overlay)

So the SIP-facing runtime is selected at deploy time and cluster-wide, while the
conference's runtime is selected per-conference by
`selectRuntimeNodeForConference`. These are two independent selection
authorities that are never reconciled.

## Conference runtime SIP reachability

    Can Kamailio route an incoming browser SIP dialog to the conference
    RuntimeNode today?   NO

Three independent reasons, each sufficient:

1. **No SIP endpoint purpose exists in the runtime registry.**
   `apps/api/config/runtime_registry.php:60` —
   `'endpoint_purposes' => ['control', 'events', 'health']`. The control plane
   cannot represent a RuntimeNode's SIP signaling target at all.
2. **Managed runtimes expose no SIP port.**
   `ManagedAsteriskProvisioningOperationHandler` writes a Deployment whose only
   container port is `ari` 8088/TCP and a Service whose only port is `ari`
   8088/TCP, and registers exactly three endpoints — control, events, health —
   all `http`/`ws` on 8088 `/ari`.
3. **Kamailio has no per-node destination logic**, as shown above.

Live corroboration from the accepted evidence: the conference RuntimeNode
`c7e6f4ba-…` ("RNP6 Readiness Reproof 20260809") has exactly three endpoint rows,
all `…utcp-runtime.svc.cluster.local:8088`, and its Service publishes only 8088.
"ARI-only" is therefore literal and structural, not incidental.

## Participant channel construction

    technology / type   Local channel pair
    endpoint            Local/participant@utcp-conference-proof/n
    channel id          utcp-part-<participantId>
    peer channel id     utcp-part-<participantId>;2
    caller id           "UTCP participant <participantId>"
    bridge              utcp-conf-<conferenceId>  (type mixing)
    runtime node        the conference's bound RuntimeNode
    far-end dialplan    [utcp-conference-proof]
                          exten => participant,1,NoOp(UTCP conference proof participant)
                           same => n,Answer()
                           same => n,Stasis(utcp-t0-observation)
                           same => n,Hangup()

The far end answers and parks in a Stasis application. **It originates no audio
and consumes none.** The bridge member for an admitted participant is a silent
synthetic channel.

Relationship to the telephony session: only through the `participantId`
embedded in the ARI channel id, which is derived from the
`conference_participants` row — itself linked to `telephony_session_id`. That is
a control-plane paper trail, not a signaling relationship.

Relationship to the SIP identity, the browser Contact, and the browser's PJSIP
channel: **none.** Different Asterisk instance, different context, different
channel technology, no shared Call-ID, no shared dialog, and no bridge in common.

    Does the created participant channel have any canonical linkage to the
    browser's actual incoming PJSIP channel?   NO.

The configuration keys themselves record the intent: `proof_endpoint` and
`proof_originate_timeout_seconds`, and the dialplan context is literally named
`utcp-conference-proof`. The participant channel is a T2-A conference-execution
*proof* construct.

## Browser ↔ participant correlation

Values are taken from the accepted credential-renewal live proof
(telephony session `0814ed59-…`, 2026-08-14/15). Nothing here is manufactured.

| Field | Browser leg | Admitted participant leg | Match / relationship |
|---|---|---|---|
| Tenant | `a2315712-d650-4d43-8efb-1ac0e3cb356c` | `a2315712-d650-4d43-8efb-1ac0e3cb356c` | **MATCH** (only via the control plane) |
| Telephony session | `0814ed59-e538-4063-b46d-8faeba96265a` (SIP identity is derived from it) | `0814ed59-…` recorded on the participant row | **MATCH in the database only**; never asserted on the wire |
| SIP identity | `ts-0814ed59e5384063b46d8faeba96265a` in From/To/Authorization | not represented — caller id is `UTCP participant <participantId>` | **NO RELATIONSHIP** |
| Conference | not represented anywhere in the dialog; R-URI is `9900` | `95e4c4e9-0349-4e03-8cc6-b09980cdbee5` | **NO RELATIONSHIP** |
| Participant ID | not represented | `67d30af9-4be2-485a-b94d-7396bf56c7f7` | **NO RELATIONSHIP** |
| RuntimeNode | base `asterisk-ari` Pod `10.42.1.33` (`local-asterisk-ari`, not an active RuntimeNode row) | `c7e6f4ba-…` "RNP6 Readiness Reproof 20260809" | **DIFFERENT NODES** |
| SIP Call-ID | present, browser-generated | not represented — no SIP dialog exists for this leg | **NO RELATIONSHIP** |
| Channel ID | `PJSIP/anonymous-…` in `from-kamailio` | `utcp-part-67d30af9-…` and `;2` peer, `Local/participant@utcp-conference-proof` | **NO RELATIONSHIP** |
| Runtime binding | none — the browser leg has no `conference_runtime_bindings` linkage | `7262e0ff-4f4f-4e23-a08a-e1a83962d489`, active | **NO RELATIONSHIP** |
| Destination | `sip:9900@sip.utcp.local.test` (constant) | bridge `utcp-conf-95e4c4e9-…` | **NO RELATIONSHIP** |
| Media path | browser ↔ rtpengine `127.0.0.1:400xx` ↔ base Asterisk ↔ `Echo()` | none — silent Local channel in the bridge | **DISJOINT; no shared audio in either direction** |
| Bridge membership | never a bridge member | member of `utcp-conf-95e4c4e9-…` | **DISJOINT** |

The only correlation of any kind is tenant and telephony-session equality inside
PostgreSQL. On the signaling and media planes the two legs are wholly unrelated.

## Material mismatch

The browser SIP leg is not, and cannot become, the admitted conference
participant under the current architecture:

* the admission response returns a constant echo-fixture destination that
  carries no conference, participant, session, node, or binding identity;
* Kamailio routes every application dialog to one statically-labelled service and
  consumes no control-plane eligibility;
* the conference's bound RuntimeNode cannot receive SIP — the registry has no
  `sip` endpoint purpose and managed runtimes publish only ARI 8088;
* the participant channel is a silent `Local/participant@utcp-conference-proof`
  channel with no linkage to the browser's PJSIP channel;
* consequently the member's microphone audio reaches an `Echo()` application and
  no conference participant could ever hear them.

Measured against the authoritative contract, the currently satisfied part is
"conference admission request through UTCP → normalized runtime adapter
operation → Asterisk conference and bridge execution → observed conference
membership". The unsatisfied part is that the registered browser's own call leg
and media are not the ones executing in the conference.

## Canonical signaling destination authority

`signaling_destination` should be derived from the selected conference and its
runtime binding, not from a fixed reference fixture. The data needed to derive it
already exists and is populated: `conferences.runtime_node_id`, the active
`conference_runtime_bindings` row, and `conference_participants.id`. The natural
owner is the existing admission authority, `TelephonyDomainService::admitSelf` /
`admitParticipant`, which already resolves all three under lock and already
returns the destination.

What does **not** exist is anything for that authority to project *into*:

* no representation of a conference SIP address or entry extension;
* no `sip` endpoint purpose on a RuntimeNode to point at;
* no Kamailio mechanism that consumes such a projection;
* no defined way for an inbound channel to declare which participant it is.

No new authority should be invented for the parts that are already owned; the
missing pieces are contracts, not owners.

## Legacy / bootstrap authority cutoff

    FIXED 9900 ROUTE MAY REMAIN AS AN INDEPENDENT SIP/MEDIA CONNECTIVITY TEST,
    BUT MUST NOT BE RETURNED BY CANONICAL CONFERENCE ADMISSION.

Extension 9900 is a legitimate, committed T3-S2A media fixture and the T3 media
prover depends on it; removing it would break accepted T3 proof tooling. Its use
inside `referenceClientSignalingDestination()` is the part that conflicts with
the canonical conference architecture, because it makes the admission authority
return a destination that has nothing to do with the admitted conference.

No environment gate, fallback, allowlist, manual switch, or compatibility mode
should be introduced to keep the conflicting behaviour once a canonical
destination exists. No documented compatibility requirement calls for one.

## Smallest unresolved architecture question

> **How does a registered browser's SIP dialog become the admitted participant of
> a specific UTCP conference on that conference's bound RuntimeNode?**

Four sub-questions must be decided before any implementation, and none is
answerable from current repository evidence:

1. **Conference signaling address.** How is a conference's SIP entry point
   represented canonically — a per-conference extension, a per-participant
   one-time token in the Request-URI, or a header — and which authority mints it?
2. **RuntimeNode SIP identity.** The registry has no `sip` endpoint purpose and
   managed runtimes publish no SIP port. Does a RuntimeNode gain a SIP endpoint
   purpose and a 5060 Service port, and does RNP provisioning write it?
3. **Kamailio route authority.** ADR-020 defers "Kamailio INVITE route authority
   consuming the canonical RuntimeNode eligibility projection" to V0, but no
   projection or lookup mechanism is specified. What does Kamailio read, and how
   does it stay fenced and current?
4. **Inbound-channel → participant correlation.** How does the receiving runtime
   bind an inbound PJSIP channel to a `conference_participants` row and the
   conference bridge, and what replaces the synthetic
   `Local/participant@utcp-conference-proof` channel?

Only after these are decided is the implementation seam bounded. Recommending a
Codex packet now would require inventing all four.

## Outcome classification

Not **A** — no repository evidence establishes the split-leg model as the
intended V0 conference model, and no correlation exists between the two legs.

Not **B** — the required authorities are named but unimplemented, and two are
not modelled at all, so the seam is not bounded.

**C** — architecture clarification is required first, as an ADR amendment
extending ADR-017 (conference domain), ADR-019 (signaling authority) and
ADR-020's own V0 deferral.

## Code changes

    None.

## Environment and topology changes

    None. No cluster, registry, namespace, port, context, node, volume,
    deployment mechanism, or runtime configuration was touched, and no live
    mutation was performed.

## Improvised or non-canonical actions

    None.

## V0 completion decision

    V0 REMAINS OPEN.

Proven and not to be re-proven: natural login, WSS, SIP REGISTER, credential
renewal, single registration binding, conference authorization and admission,
participant projection, runtime conference binding, browser INVITE, browser RTP
through rtpengine, local Leave, failed-establishment compensation, cleanup
idempotence, and the telephony-session/dialog authority contract.

Outstanding: the browser's own call leg is not the admitted conference leg, and
the application-dialog route consumes no canonical Conference RuntimeNode
eligibility — the exit criterion stated in V0's own phase row.
