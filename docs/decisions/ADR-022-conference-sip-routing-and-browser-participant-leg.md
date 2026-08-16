# ADR-022: Conference SIP Routing and Browser Participant Leg Authority

## Status

Accepted for the V0 conference-admission corridor. Extends ADR-017 (telephony
session and conference domain authority), ADR-019 (Kamailio signaling
registration authority), and ADR-020 (T3 RTP media plane), and discharges the
ADR-020 deferral "Kamailio INVITE route authority consuming the canonical
RuntimeNode eligibility projection".

## Context

V0-B proved that the browser's SIP leg is not the admitted conference leg. The
admission response returned a constant `sip:9900@{realm}`, a T3-S2A media fixture
running `Answer(); Echo(); Hangup()`; Kamailio relayed every application dialog
to one statically-labelled Service; the conference's bound RuntimeNode published
only ARI on 8088 and could not receive SIP; and the admitted participant was a
silent synthetic `Local/participant@utcp-conference-proof` channel with no
linkage to the browser's PJSIP channel. The member's microphone reached an echo
application and no conference participant could hear them.

The authoritative V0 contract requires the browser's actual signaling and media
leg to be the conference participant.

## Decision

### 1. Browser leg is the participant leg

A participant admitted through `POST /api/v1/conferences/{conference}/participants/self`
must be represented in the runtime by that browser's own SIP dialog. The
inbound PJSIP channel created by that dialog is the ConferenceParticipant's
runtime channel and is the channel added to the canonical conference bridge. A
separately originated synthetic channel does not satisfy browser self-admission.

### 2. Conference placement is the signaling placement

`conferences.runtime_node_id` and the active `conference_runtime_bindings` row
remain the sole control-plane authority for which RuntimeNode executes a
conference, and that same binding now also determines which RuntimeNode
terminates that conference's browser application SIP dialogs. There is exactly
one placement authority.

### 3. Kamailio remains the edge and executes a projected decision

Kamailio remains the single public browser-facing SIP edge and the sole
registrar. For conference admission it authenticates the INVITE, reads a
sanitized routing projection derived from canonical PostgreSQL state, and
relays to the RuntimeNode that projection names. Kamailio must not select an
application RuntimeNode independently, must not fall back to another runtime,
and must not reimplement the telephony domain.

### 4. RuntimeNodes expose a canonical internal SIP endpoint

RuntimeNodes that participate in conference signaling carry a `sip` endpoint in
the existing `runtime_node_endpoints` registry, addressed by cluster-internal
service name. No parallel managed-SIP-endpoint model is introduced. The endpoint
is internal only: no NodePort, no LoadBalancer, no host-port publication, and no
browser-reachable path. Only the Kamailio signaling workload may reach it.

### 5. Managed provisioning writes that endpoint automatically

RNP managed Asterisk provisioning publishes the SIP port on the generated
Service and registers the `sip` endpoint on the RuntimeNode as part of the
existing provisioning operation, with no operator step.

### 6. Admission returns a conference-specific destination

Canonical conference admission returns a destination that identifies the
admission, scoped to the tenant, the conference, and the participant. It follows
the established ADR-019 convention in which a domain identifier appears in the
SIP user part and is protected by digest authentication against the
session-scoped credential. The member never types it and cannot choose it.

### 7. The fixed 9900 route loses conference authority

`sip:9900@{realm}` is retained solely as the T3 SIP and media connectivity
fixture that existing accepted T3 proof tooling depends on. It must not be
returned by canonical conference admission, and it must not be used as a
fallback, gate, allowlist, compatibility mode, or manual switch for conference
routing. If canonical route resolution fails, the corridor fails observably.

### 8. Synthetic proof channels are not the self-admission path

`Local/participant@utcp-conference-proof` remains available only for simulator
and explicitly synthetic participants. It ceases to be the implementation of
`participants/self` for a participant that represents a real browser SIP caller.
The existing `conference_participants.admission_reason` value `self_admission`
is the origin metadata that distinguishes the two; no new type hierarchy is
introduced.

### 9. Authorization, routing, and execution stay separated

UTCP admission is the authorization authority. Kamailio performs authenticated
routing of an already-authorized admission. The runtime performs the admitted
operation. Asterisk never invents tenant or conference authorization, and the
browser can choose neither the RuntimeNode, nor the internal SIP destination,
nor the participant identity, nor a path that bypasses `participants/self`.

### 10. RT-1 is unrelated

Realtime control-plane UI notifications remain a notification path over
canonical committed state. Reverb is not SIP routing, conference media, dialog
authority, or runtime selection, and must not be used to implement any part of
this decision.

## Consequences

- The routing projection is a sanitized PostgreSQL view computed at query time,
  following the `kamailio_signaling_auth_view` precedent, so there is no cached
  projection to fence and no reload, generation, or invalidation machinery is
  required. Eligibility is expressed as predicates inside the view.
- Eligibility reuses the placement semantics the telephony domain already
  applies; Kamailio does not gain a second eligibility policy.
- Because the participant's runtime channel is an Asterisk-assigned inbound
  channel id rather than a derivable `utcp-part-<id>` name, the participant's
  current runtime channel becomes recorded state on `conference_participants`.
- `conference.participant.ensure` no longer originates a channel for
  `self_admission` participants; the runtime channel arrives with the browser
  INVITE. Ensure semantics for synthetic and simulator participants are
  unchanged.
- Conference bridge ownership, `utcp-conf-<conferenceId>` naming, conference
  lifecycle operations, and the existing observation, projection, and
  reconciliation authorities are unchanged.
- RNM drain, decommission, and retirement semantics continue to govern new
  admission eligibility. Mid-call migration remains explicitly unsupported.
- The contract is provider-neutral at the authority level — RuntimeNode `sip`
  endpoint, conference binding, routing projection, and participant/channel
  correlation — while adapter mechanics remain Asterisk-first. FreeSWITCH
  parity is not implemented by this decision.
