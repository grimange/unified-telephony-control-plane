# V0-C — Conference SIP Routing Architecture Contract

## Verdict

    V0_C_CONFERENCE_SIP_ROUTING_ARCHITECTURE_CONTRACT_COMPLETED

Every authority V0-B left unresolved is now answered from repository evidence and
recorded in [`ADR-022`](../../decisions/ADR-022-conference-sip-routing-and-browser-participant-leg.md).
The work decomposes into six bounded Codex packets with exact file targets,
dependencies, and acceptance criteria.

## Method

Repository-evidence only. No application source, Kubernetes topology, runtime
configuration, or live state was modified. One live read-only inspection was used
to confirm that the managed Asterisk pod already listens on UDP 5060.

## Accepted topology

    Browser / SIP.js
      → WSS → Kamailio (public SIP edge, sole registrar)
      → internal SIP → conference's canonically bound RuntimeNode
      → inbound PJSIP channel
      → bound to the admitted ConferenceParticipant
      → added to utcp-conf-<conferenceId>

The rejected alternative — a central Asterisk accepting the browser call and
trunking it into the selected RuntimeNode — is not considered anywhere below.

## V0 product contract

One continuous chain: natural login → telephony session → signaling credential →
REGISTER over WSS → registration observation → conference admission → canonical
RuntimeNode selection/binding → browser INVITE → Kamailio routes to the bound
RuntimeNode → inbound browser PJSIP channel → bound to the admitted participant →
added to the conference bridge → media through rtpengine → observed browser call
leg and membership → UI Connected. The browser's actual SIP and media leg is the
participant.

## Conference SIP entry address

### Representation

    sip:conf-<participantId>@<telephony_signaling.realm>

A single opaque-to-the-user user-part built from the existing participant
identifier with a `conf-` discriminator.

### Why a raw domain identifier is the safe convention here

ADR-019 already establishes exactly this shape: the registered SIP identity is
`ts-<telephony session uuid, dashes stripped>` in the same realm, live-proven and
accepted. A domain UUID in the SIP user part, protected by digest authentication
against the session-scoped credential, is therefore the repository's established
convention rather than a new exposure class. A separate admission-token authority
is **not** required, because the participant row already carries every fact the
routing decision needs and the caller is already strongly authenticated as the
owning telephony session.

The address satisfies the stated requirements: it is tenant-scoped (the
participant row is tenant-scoped and the projection filters on it), conference
specific, participant specific, not user-editable (it is returned only after
successful admission and Kamailio rejects any admission that does not belong to
the authenticated identity), not a Kubernetes address, not a RuntimeNode IP, and
not a shared static extension.

### Authority

`TelephonyDomainService::admitParticipant` — the same method that already
resolves the conference, the active binding, the participant, and the session
under `lockForUpdate`, and that already returns `signaling_destination`. No new
authority is introduced. `referenceClientSignalingDestination()` is deleted.

### Lifetime and replay semantics

Derived, not persisted. The address is resolvable exactly while the projection
predicate holds — participant `desired_state = 'admitted'`, conference
`desired_state = 'open'`, active runtime binding, eligible RuntimeNode with a
`sip` endpoint, and an active telephony session. It is therefore:

* valid only while the participant is admitted;
* **not** single-use — reconnect after a failed or dropped INVITE reuses it,
  which is required by the accepted retry contract;
* implicitly bounded by the telephony-session lifetime, because the session
  gates both the credential and the participant;
* **requiring no new persisted authority**. Stated explicitly: no admission-token
  table is needed.

### Browser-visible form

The member never sees or types it. It is returned in the `participants/self`
201 response and consumed directly by `signalingClient.invite(...)`.

## RuntimeNode SIP endpoint contract

### Endpoint purpose

Add `sip` to `runtime_registry.endpoint_purposes` and `udp` to
`runtime_registry.endpoint_transports`. Both are catalogue additions to the
existing canonical `runtime_node_endpoints` registry; no parallel model is
created. `udp` is required because the existing internal SIP transport is UDP —
Asterisk's committed `[transport-udp-internal]` binds `0.0.0.0:5060` and
Kamailio's relay already appends `;transport=udp`.

For `adapter_keys.asterisk-ari`, `sip` is added to `endpoint_requirements` as
**not required**, so existing observation-only Asterisk nodes remain valid; a node
without a `sip` endpoint is simply not eligible for conference signaling.

### Managed Asterisk provisioning

Confirmed by live read-only inspection: the managed Asterisk pod **already
listens on UDP 5060** (`/proc/net/udp` shows `00000000:13C4`), because it runs the
same `utcp/asterisk-ari` image whose `pjsip.conf` binds `0.0.0.0:5060` with
`context=from-kamailio`. No image or PJSIP change is needed for the transport
itself. RNP must add:

* a `sip` container port (5060/UDP) on the generated Deployment;
* a `sip` port on the generated Service;
* a `sip` endpoint row registered alongside control/events/health.

### Kubernetes Service

ClusterIP only, adding `{name: sip, protocol: UDP, port: 5060, targetPort: sip}`
to the existing per-node Service.

### Internal-only exposure

No NodePort, no LoadBalancer, no host-port publication, no Gateway route. The
browser reaches only `wss://sip.utcp.local.test/ws`.

### Readiness implications

None changed. Existing readiness remains the ARI probe; SIP reachability is not
added to the readiness gate, because the projection's eligibility predicate
already requires both `observed_state = 'ready'` and the presence of an enabled
`sip` endpoint. This avoids inventing a second readiness authority.

## NetworkPolicy contract

`infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml`
currently pins `utcp.dev/runtime-node: local-asterisk-ari`, so managed nodes are
not covered. The selector must be generalized to the component/network-role
labels that RNP already writes (`app.kubernetes.io/component: asterisk-ari`,
`utcp.io/network-role: asterisk-ari`), keeping the existing shape:

    ingress: from utcp-platform / utcp.io/network-role=kamailio-signaling, UDP 5060
    ingress: from utcp-platform / rtpengine-media, UDP 10000-20000
    egress:  to kamailio-signaling UDP 5060; rtpengine UDP 40000-40099; kube-dns 53

No broader exposure, and no allowlist outside the existing Kubernetes
identity/NetworkPolicy structure.

## Kamailio routing projection

### Canonical source

`conference_participants`, `conferences`, `conference_runtime_bindings`,
`runtime_nodes`, `runtime_node_endpoints`, `telephony_sessions`.

### Projection producer

A PostgreSQL **view**, following the accepted `kamailio_signaling_auth_view`
precedent from ADR-019 (`apps/api/database/migrations/2026_07_16_160000_create_kamailio_registrar_foundation.php`).
Proposed name `kamailio_conference_route_view`, with a dedicated least-privilege
reader role granted `select` on that view only, mirroring
`utcp_kamailio_auth_reader`.

### Projection consumer

Kamailio loads one additional pinned module, `sqlops.so`, bound to the new role's
`db_url`, and performs a single `sql_query` keyed on the admission user-part
during conference INVITE routing. `db_postgres.so` is already loaded.

### Minimum data contract

Sanitized routing columns only:

    admission_user     -- 'conf-' || participant id, the SIP user part
    signaling_identity -- 'ts-' || session id, for authenticated-owner comparison
    tenant_id
    conference_id
    participant_id
    runtime_node_id
    sip_target         -- sip:<service host>:<port>;transport=udp

Authoritative data stays in the source tables; the view is projected routing
data; ARI/receipt records remain runtime observations. No credentials, HA1,
Contact values, pod names, or unrelated tenant rows appear in the view.

### Eligibility semantics

Expressed as predicates inside the view, reusing the placement semantics the
telephony domain already applies rather than a second Kamailio policy:
participant `desired_state = 'admitted'`; conference `desired_state = 'open'`
with an `active` binding; the binding's node equals `conferences.runtime_node_id`;
RuntimeNode `desired_state = 'active'` and `observed_state = 'ready'` (excluding
`draining`, `drained`, `disabled`, `retired`); an enabled `sip` endpoint exists;
telephony session `status = 'active'` and unexpired.

### Generation and fencing

**No new generation or fencing system is required, and none should be built.**
The projection is a view evaluated at query time against canonical rows, so a
stale route cannot exist: when a participant is removed, a conference closes, or
a node drains, the row simply stops being returned on the next lookup. Route
removal is therefore automatic, and there is no reload, cache, or invalidation
step. This is the same freshness property the accepted auth view already relies on.

### Failure behavior

If the lookup returns no row, Kamailio rejects the INVITE with a definite failure
response and logs a sanitized reason. It must not fall back to the static
application runtime.

## Static application runtime cutoff

    RETAINED: utcp.dev/runtime-selection = selected-application-runtime remains
              valid ONLY as the route for the T3 9900 connectivity fixture.

    REMOVED:  it loses all authority for conference INVITEs. route[APPLICATION_RUNTIME_RELAY]
              must not be reachable from the conference admission path, and must
              never be used as a fallback when route resolution fails.

## Admission projection

    POST /api/v1/conferences/{conference}/participants/self
      → ConferenceController::joinSelf
      → TelephonyDomainService::admitSelf → admitParticipant   (locks and revalidates
          tenant, capability, active session, conference open, runtime binding)
      → conference_participants row (desired admitted, admission_reason self_admission)
      → signaling_destination = sip:conf-<participantId>@<realm>
      → view kamailio_conference_route_view exposes that user-part → sip_target

## Incoming PJSIP channel binding

### Identity carried on wire

The Request-URI user part `conf-<participantId>`, which Asterisk exposes as
`${EXTEN}` in `from-kamailio` and can pass as a `Stasis()` argument. No
browser-supplied header is trusted.

### Kamailio validation

Kamailio validates, before relaying: digest authentication succeeds against the
session-scoped credential; the projection returns a row for this admission
user-part; and `signaling_identity` on that row equals the authenticated SIP
identity, so one member cannot enter another member's admission. Everything else —
conference openness, participant admission, binding currency, node eligibility —
is already encoded as view predicates, so Kamailio consumes a precomputed
decision rather than reimplementing the domain. Authorization ends at
`participants/self`; Kamailio performs authenticated routing execution only.

### Asterisk / Stasis entry

`from-kamailio` gains one canonical pattern that matches the admission user-part
and enters the existing Stasis application, passing the admission reference:

    exten => _conf-X.,1,NoOp(UTCP conference admission ${EXTEN})
     same => n,Answer()
     same => n,Stasis(<profile application>,${EXTEN})
     same => n,Hangup()

The catch-all `exten => _.` → `Hangup(21)` reject stays, and `9900` stays in the
local overlay. Conference authority stays in ARI, not the dialplan.

### Participant lookup and bridge membership

The inbound channel raises `StasisStart`, which the existing
`AsteriskAriEventListener` already receives and the normalizer already maps
(`'StasisStart' => 'stasis_start'`). A new handler resolves the participant from
the admission argument, re-verifies conference, binding, and node against
canonical state, records the channel id, and issues
`POST bridges/utcp-conf-<conferenceId>/addChannel` with the **inbound channel's
own id**.

## Synthetic proof channel cutoff

    RETAINED: Local/participant@utcp-conference-proof remains for simulator and
              explicitly synthetic participants, and the [utcp-conference-proof]
              context stays in the committed dialplan.

    REMOVED:  it stops being the implementation of participants/self. In
              AsteriskRuntimeAdapter::ensureParticipant, participants whose
              admission_reason is 'self_admission' no longer originate a channel;
              their runtime channel arrives with the browser INVITE.

`conference_participants.admission_reason` is the existing origin metadata that
distinguishes the two. No new type hierarchy is introduced.

## Runtime observation contract

Today `AsteriskAriEventNormalizer` resolves the participant from the channel-id
prefix `utcp-part-<participantId>` (`suffixForPrefix`). After the cutover a
browser participant's channel id is Asterisk-assigned, so that convention no
longer holds and the mapping must be recorded. The normalizer resolves the
participant by the recorded runtime channel id first, falling back to the
existing prefix convention for synthetic participants, and continues to emit
`conference.participant.observed` with `joined`/`left` exactly as today — so
projection and reconciliation are unchanged.

Resulting evidence chain: ConferenceParticipant → actual PJSIP channel id →
channel in `utcp-conf-<conferenceId>` → RuntimeNode. The browser SIP Call-ID is
useful correlating evidence in the proof, not a database key.

## Media contract

    Browser → WebRTC/SRTP → rtpengine → bound RuntimeNode PJSIP channel → conference bridge

The member's microphone enters the conference mix. rtpengine is not redesigned;
the existing two-interface external media edge is unchanged. `Browser → Echo()`
ceases to be the conference path and remains independently testable through T3.

## Leave and cleanup contract

Unchanged and preserved: Leave → browser BYE → the inbound PJSIP channel
terminates → `DELETE participants/self` → participant `removed`/`left` → bridge
membership converges → UI Ready.

Participant release stays **browser-triggered and runtime-observed**, which is the
current accepted behaviour: the client's DELETE sets desired state, and
`ChannelLeftBridge`/`ChannelDestroyed` observations converge observed state. No
duplicate cleanup authority is added; the existing single-flight client guard and
the reconciler's idempotent convergence both remain.

## Failed establishment contract

Strengthened by the cutover rather than weakened: because no channel is
originated for `self_admission` participants, a failed INVITE leaves no synthetic
conference channel active anywhere. The existing compensation path — participant
released, `attention` state, retry enabled — is unchanged, and the admission
address remains reusable for the retry.

`conference.participant.ensure` semantics therefore change only for
`self_admission`: it ensures the bridge exists and waits for the browser channel
rather than originating one.

## RNM / drain interaction

Existing RNM contracts are sufficient; no new lifecycle work is required. A node
that is `draining`, `drained`, `retired`, or not `ready` fails the view's
eligibility predicate, so **new** conference admissions stop routing to it
immediately and automatically. **Established** dialogs are unaffected — mid-call
migration remains explicitly unsupported and is not designed here. Existing
conference failover/rebind semantics continue to own re-placement.

## External runtime compatibility

The contract is expressed entirely in canonical objects, so an externally
registered RuntimeNode participates by satisfying the same requirement: a valid,
Kamailio-reachable `sip` endpoint registered through the existing runtime-registry
API, plus conference capabilities. No per-conference routing UI and no external
automation is added in V0.

## FreeSWITCH boundary

Not implemented here or in the cutover. The authority-level pieces — RuntimeNode
`sip` endpoint, conference binding, routing projection, participant/channel
correlation — are provider-neutral; only the dialplan/Stasis mechanics are
Asterisk-first. The committed FreeSWITCH `9900` parity fixture is untouched.

## Security boundary

Browser cannot choose the RuntimeNode (placement is control-plane); cannot submit
an internal SIP destination (it receives an admission user-part in the public
realm and Kamailio resolves the target); cannot choose a participant id (Kamailio
compares the projection's `signaling_identity` to the authenticated identity);
cannot bypass `participants/self` (no admission row means no projection row means
no route). Kamailio cannot place a conference independently. Asterisk cannot
invent tenant or conference authorization — it acts on an already-authorized,
already-routed admission.

## No new operator friction

Nothing above requires manual SIP route registration, Kamailio reload, node
allowlist, environment-variable enablement, per-runtime activation flag, CLI
routing command, or manual projection trigger. The projection is a view, so it is
always current; RNP writes the endpoint automatically during normal provisioning.

## Data model changes

One nullable column is genuinely required:

    conference_participants.runtime_channel_id  (nullable string)

Justification: the participant's runtime channel is currently *derived* from the
naming convention `utcp-part-<participantId>` because UTCP originates it. After
the cutover the browser's channel id is assigned by Asterisk and is not derivable,
while the existing observation path resolves participants **by channel id**.
Runtime observations are evidence, not authority, so they cannot own the mapping,
and `conference_participants` is the object that already owns participant
lifecycle. No new table, no admission-token table, and no routing table is needed —
the routing projection is a view over existing rows.

## Exact implementation file map

    apps/api/config/runtime_registry.php                                   endpoint_purposes + endpoint_transports + asterisk-ari endpoint_requirements
    apps/api/app/RuntimeRegistry/RuntimeRegistryCatalog.php                purpose/transport validation surface
    apps/api/app/RuntimeRegistry/RuntimeRegistryService.php                endpoint upsert path
    apps/api/app/RuntimeProvisioning/ManagedAsteriskProvisioningOperationHandler.php  Deployment port, Service port, sip endpoint registration
    apps/api/app/RuntimeProvisioning/RuntimeProvisioningService.php        provisioning contract
    infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml  selector generalization
    apps/api/database/migrations/<new>_create_kamailio_conference_route_view.php      view + least-privilege role + grants
    infrastructure/kubernetes/base/platform/kamailio-configmap.yaml        sqlops module, route lookup, conference route, reject-on-miss
    infrastructure/docker/asterisk/config/extensions.conf                  from-kamailio conference admission pattern → Stasis
    apps/api/app/TelephonyDomain/TelephonyDomainService.php                admitParticipant destination derivation; remove referenceClientSignalingDestination()
    apps/api/app/RuntimeAdapters/Asterisk/AsteriskRuntimeAdapter.php       ensureParticipant: no originate for self_admission
    apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriClient.php            addChannel by inbound channel id
    apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriEventListener.php     StasisStart admission handling
    apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriEventNormalizer.php   participant resolution by recorded channel id
    apps/api/app/TelephonyDomain/Reconciliation/ConferenceParticipantReconciler.php  self_admission ensure semantics
    apps/api/database/migrations/<new>_add_participant_runtime_channel_id.php
    apps/web/src/views/ReferenceDialerView.vue                             consumes returned destination (no change expected)
    apps/web/src/signaling/referenceDialerSignaling.ts                     consumes returned destination (no change expected)
    apps/api/tests/Feature/TelephonyDomain/TelephonyDomainTest.php         destination assertion currently pins sip:9900@...
    apps/web/src/signaling/referenceDialerSignaling.test.ts                fixtures pin sip:9900@...
    apps/web/src/views/ReferenceDialerView.test.ts                         fixtures pin sip:9900@...
    scripts/kamailio-signaling/config-check                                signaling authority static checks
    scripts/telephony-domain/config-check                                  domain boundary static checks

## Bounded Codex packet sequence

### V0-C1 — RuntimeNode internal SIP endpoint and managed provisioning

    GOAL:      Represent and provision a Kamailio-reachable internal SIP endpoint.
    FILES:     runtime_registry.php, RuntimeRegistryCatalog.php, RuntimeRegistryService.php,
               ManagedAsteriskProvisioningOperationHandler.php, RuntimeProvisioningService.php,
               allow-asterisk-sip-from-kamailio.yaml, scripts/runtime-registry/config-check
    DEPENDS:   none
    ACCEPTANCE: catalog exposes sip/udp; endpoint validation accepts sip+udp and still
               rejects unknown values; RNP renders Deployment sip port, Service sip port,
               and registers a sip endpoint; NetworkPolicy selector covers managed nodes;
               runtime-registry and security config-checks pass.
    LIVE PROOF: no — repository + rendered-manifest checks only.

### V0-C2 — Canonical conference admission destination

    GOAL:      admitParticipant returns sip:conf-<participantId>@<realm>; 9900 removed
               from conference admission authority.
    FILES:     TelephonyDomainService.php (remove referenceClientSignalingDestination),
               TelephonyDomainTest.php, referenceDialerSignaling.test.ts,
               ReferenceDialerView.test.ts, scripts/telephony-domain/config-check
    DEPENDS:   none (parallel with V0-C1)
    ACCEPTANCE: admission response carries the participant-derived destination; no
               repository path returns sip:9900 from conference admission; existing
               admission authorization tests unchanged and passing.
    LIVE PROOF: no.

### V0-C3 — Conference routing projection and Kamailio cutover

    GOAL:      Kamailio resolves the bound RuntimeNode from a sanitized view and fails
               closed; static selected-application-runtime loses conference authority.
    FILES:     new migration (kamailio_conference_route_view + least-privilege role),
               kamailio-configmap.yaml, scripts/kamailio-signaling/config-check
    DEPENDS:   V0-C1 (sip endpoint must exist to project), V0-C2 (user-part shape)
    ACCEPTANCE: view returns exactly the eligible row and nothing for closed/removed/
               draining/retired/no-sip-endpoint cases; role can select only that view;
               config-check asserts no conference fallback to APPLICATION_RUNTIME_RELAY;
               unresolved admission rejects with a definite response.
    LIVE PROOF: bounded — a conference INVITE reaches the bound RuntimeNode.

### V0-C4 — Inbound PJSIP channel becomes the participant leg

    GOAL:      The browser's own channel is bound to the participant and added to the
               conference bridge; synthetic origination stops for self_admission.
    FILES:     extensions.conf, AsteriskAriEventListener.php, AsteriskAriEventNormalizer.php,
               AsteriskAriClient.php, AsteriskRuntimeAdapter.php,
               ConferenceParticipantReconciler.php, migration adding
               conference_participants.runtime_channel_id
    DEPENDS:   V0-C3
    ACCEPTANCE: self_admission ensure originates no channel; StasisStart with a valid
               admission adds the inbound channel to utcp-conf-<id>; invalid/stale
               admission is rejected and the channel hung up; normalizer resolves the
               participant by recorded channel id and still emits joined/left; synthetic
               participants unchanged.
    LIVE PROOF: bounded — the inbound channel appears in the bridge.

### V0-C5 — Reference-client and T3 separation verification

    GOAL:      Confirm the client needs no change beyond consuming the returned
               destination, and that the T3 9900 corridor is untouched.
    FILES:     ReferenceDialerView.vue, referenceDialerSignaling.ts and their tests;
               tools/t3-media-prover/*; infrastructure/kubernetes/overlays/local/runtime/
               extensions.local.conf; infrastructure/kubernetes/overlays/local/proof/t3-media/job.yaml
    DEPENDS:   V0-C2
    ACCEPTANCE: focused frontend suite passes unchanged; T3 media prover still resolves
               9900 through the static application runtime.
    LIVE PROOF: no.

### V0-C6 — Natural browser conference live proof

    GOAL:      Prove the single continuous chain end to end.
    DEPENDS:   V0-C1..V0-C5
    ACCEPTANCE: see the live-proof contract below.
    LIVE PROOF: yes — Playwright MCP, natural login only.

V0-C3 and V0-C4 must not ship independently to a live environment: after V0-C3
routes conference INVITEs to a RuntimeNode that has no conference entry yet, the
corridor is down until V0-C4 lands. They may be separate reviews but should be
deployed together.

## V0 final live-proof contract

One continuous call must show: natural member login → REGISTERED → Join →
`participants/self` returns `sip:conf-<participantId>@<realm>` → the browser
INVITE uses exactly that destination → Kamailio routes to the conference's bound
RuntimeNode → an inbound browser PJSIP channel appears **on that node** → that
same channel id is recorded on the admitted participant → that same channel id is
a member of `utcp-conf-<conferenceId>` → media flows through rtpengine into the
conference path → runtime observation shows the call leg and membership → UI
Connected → Leave → BYE → that channel is removed → participant `removed`/`left` →
bridge membership converges → UI Ready.

The proof must explicitly assert **browser channel == admitted participant runtime
channel** — one leg, not two. Natural login only: no preset session, injected
cookie, database or Redis session, or authentication bypass.

## Legacy 9900 fixture

    RETAINED FOR T3 CONNECTIVITY PROOF
    REMOVED FROM CANONICAL CONFERENCE ADMISSION AUTHORITY

No fallback, gate, allowlist, compatibility mode, or manual switch preserves the
conference behaviour.

## Code changes

    None.

## V0 status

    IN PROGRESS
    conference SIP routing contract COMPLETE
    implementation cutover PENDING
