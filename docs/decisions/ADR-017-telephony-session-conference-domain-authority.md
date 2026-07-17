# ADR-017: Telephony Session and Conference Domain Authority

## Status

Accepted and live-proven for C5.

## Context

C5 needs a canonical application-domain model for authenticated telephony sessions, conferences, conference participation, runtime-node binding, and automatic reconciliation. Earlier phases already own identity, tenant authorization, runtime nodes, runtime operations, raw event receipts, normalized observations, projection checkpoints, reconciliation state, deterministic simulator state, and scheduled simulator events.

C5 must not implement SIP registration, SIP credentials, WebSocket signaling, browser media, Asterisk ARI, FreeSWITCH ESL, Kamailio, rtpengine, or a browser calling workflow.

## Decision

PostgreSQL owns `TelephonySession`, `Conference`, `ConferenceParticipant`, `ConferenceRuntimeBinding`, desired state, lifecycle timestamps, admission and termination reason, and runtime binding. C3 observations are runtime evidence only and do not own desired application state.

A `TelephonySession` is an authenticated user's tenant-scoped control-plane telephony authorization session. It does not represent SIP registration, a media path, a call, microphone access, or a runtime channel.

Conference and participant runtime work is expressed through runtime-neutral operations:

```text
conference.ensure
conference.close
conference.participant.ensure
conference.participant.remove
```

The deterministic simulator implements those operations only as an explicit `RuntimeAdapter`. It remains a leaf adapter selected by runtime-node configuration, never a fallback. Simulator conference events enter C3 through raw receipts, normalizers, projectors, checkpoints, and reconcilers.

## Consequences

- Controllers delegate to application services and do not call adapters directly.
- Application services set desired state and initial `unobserved` defaults only; observed state changes through projection authority.
- Redis does not own sessions, conferences, participants, admission, or runtime binding.
- Generic C3 workers and reconcilers do not branch on simulator-specific behavior.
- Future Asterisk, FreeSWITCH, Kamailio, rtpengine, and browser workflow phases must integrate through these runtime-neutral boundaries instead of changing C5 domain authority.
