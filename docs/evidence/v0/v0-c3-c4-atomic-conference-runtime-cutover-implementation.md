# V0-C3 + V0-C4 — Atomic Conference Runtime Cutover

Date: 2026-08-15  
Status: **Implemented and tested in the repository; browser/live proof not performed**

## Scope

This packet implements the repository corridor for participant-specific
conference routing and the real inbound Asterisk participant leg. C3 and C4
are intentionally one runtime cutover: the Kamailio `conf-*` route and the
inbound participant binding must ship together. No deployment or natural
browser proof was performed.

## C3 — canonical Kamailio routing

The new `kamailio_conference_route_view` is a live PostgreSQL projection over
canonical conference, participant, telephony-session, runtime-binding,
RuntimeNode, and `sip` endpoint state. It returns only an admitted
`self_admission` participant in an open conference with an active matching
binding, active signaling session, ready RuntimeNode, and enabled UDP/5060 SIP
endpoint. Its sanitized fields include the `conf-<participantId>` admission
user, authenticated signaling identity, and internal SIP target.

Kamailio loads `sqlops.so` and performs a query-time lookup for the exact
validated `conf-<UUID>` Request-URI. The authenticated identity must equal the
projection identity before the projected `$du` is used. Lookup misses,
identity mismatches, invalid identifiers, and ineligible canonical state fail
closed. There is no route to `selected-application-runtime` for `conf-*` and
no 9900 fallback. Non-conference/T3 traffic, including 9900, retains its
existing static connectivity route.

The view receives only SELECT access through the existing Kamailio reader
pattern; it is not a manually populated table and needs no refresh or reload.

## C4 — real inbound participant leg

The canonical `conf-*` dialplan pattern enters the existing
`utcp-t0-observation` Stasis application with the admission reference. It does
not originate `Local/participant@utcp-conference-proof` for
`self_admission`.

`AsteriskConferenceParticipantBinder` validates the participant, session,
conference, active binding, current RuntimeNode, and readiness before attaching
the inbound channel to `utcp-conf-<conferenceId>`. It records that exact
channel ID in the nullable `conference_participants.runtime_channel_id` field.
An existing different channel is not overwritten, and late or mismatched
events are rejected. Synthetic/proof participants retain their existing Local
channel path.

ARI event handling resolves real participant observations by
`runtime_channel_id` first and preserves the `utcp-part-*` synthetic lookup.
Participant channel removal and recovery use the recorded runtime channel when
present, while retaining the existing synthetic behavior otherwise.

The participant reconciler represents an admitted self-admission participant
without an inbound channel as awaiting inbound signaling rather than repeatedly
originating a proof channel. Existing desired-state removal and idempotent
cleanup remain canonical.

## Data model

One forward migration adds nullable, indexed
`conference_participants.runtime_channel_id`. No routing table, admission token,
new participant authority, or `runtime_channel_id` generation/fencing system
was introduced.

## Verification boundary

Focused Asterisk adapter/recovery and TelephonyDomain tests cover the changed
self-admission behavior. Kamailio and Asterisk configuration checks parse the
rendered configuration, assert the SQL projection route and identity check,
assert the static-runtime cutoff, and assert the canonical Asterisk Stasis
entry. Full repository checks remain subject to the unrelated pre-existing
RuntimeProvisioning Pint issue when encountered. Browser proof remains V0-C6.

V0-C1 and V0-C2 remain complete. V0-C5 separation verification and V0-C6
natural browser proof remain pending. V0 remains in progress and RT-1 remains
planned, not implemented.
