# V0-C2 — Canonical Conference Admission Destination

Date: 2026-08-15  
Status: **Complete in the repository; browser/live proof not performed**

## Scope

V0-C2 replaces the fixed `sip:9900@<realm>` value returned by canonical
conference admission with the participant-specific destination required by the
V0-C conference routing contract:

```text
sip:conf-<participantId>@<telephony_signaling.realm>
```

This packet changes repository behavior and tests only. It does not deploy the
cutover or implement V0-C3 Kamailio routing, V0-C4 inbound PJSIP participant
binding, V0-C5 separation verification, or V0-C6 natural browser proof.

## Authority and behavior

`TelephonyDomainService::admitSelf()` continues to use the existing canonical
`admitParticipant()` seam, including its tenant, session, conference,
authorization, runtime-binding, and lifecycle validation. After the participant
is created or reused, the response formats its own persisted participant ID
with the existing `telephony_signaling.realm` configuration. No token service,
routing table, or second admission authority was introduced.

The new participant-specific destination is stable when an active admitted
participant is reused. If the signaling realm is unavailable, admission fails
through the existing application error path; there is no 9900, localhost, or
internal RuntimeNode fallback.

The reference client already passes the server-returned
`signaling_destination` opaquely to the signaling client. Its source behavior
was therefore unchanged; affected fixtures now assert that the server-derived
destination is used unchanged.

## Fixed-destination cutoff

The fixed `sip:9900@<realm>` authority was removed from canonical conference
admission and its tests. The 9900 Echo route remains intact for the independent
T3 connectivity/media proof and is not a conference admission destination.

No internal RuntimeNode Service DNS name, pod address, ARI endpoint, bridge ID,
or routing projection is returned to the browser.

## Verification evidence

Focused repository tests prove:

* successful self admission returns `sip:conf-<returned participant id>@<canonical realm>`;
* a reused admitted participant returns the same participant-specific URI;
* closed/ineligible admission remains rejected before a destination is returned;
* existing admission authorization and lifecycle coverage remains green; and
* the reference client passes the returned URI unchanged to SIP.js.

V0-C1 remains complete. V0-C3 and V0-C4 remain pending and must be delivered
together before a runtime conference cutover. V0-C5 and V0-C6 remain pending.
V0 remains in progress and RT-1 remains planned, not implemented.
