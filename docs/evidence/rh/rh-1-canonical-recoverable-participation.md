# RH-1 — Canonical Recoverable Conference Participation

Status: **IMPLEMENTED AND TESTED**. Browser recovery automation remains RH-2.

RH-1 adds the server-side recovery foundation without changing the existing V0
conference corridor or RT-1A. \`ConferenceParticipant.desired_state\` remains
the participation-intent authority: \`admitted\` is recoverable intent and
\`removed\` is the explicit-leave cutoff. \`runtime_channel_id\` remains
runtime observation only.

The implementation adds the nullable timezone-aware
\`conference_participants.runtime_channel_lost_at\` field. The Asterisk binder
stamps it only when \`clear()\` matches the participant's current channel, and
a successful replacement bind clears it together with the new channel binding.
Stale channel termination cannot create a false recovery window.

The canonical recovery grace is the single
\`TelephonyDomainService::RECOVERABLE_PARTICIPATION_GRACE_SECONDS\` constant
(120 seconds). The recovery predicate uses the exact
\`runtime_channel_lost_at + 120 seconds\` deadline and also requires admitted
self-admission intent, no active runtime channel, an open conference, an active
session, an active placement binding matching conference placement, an active
and ready RuntimeNode, and an enabled SIP endpoint.

\`GET /api/v1/reference-dialer/bootstrap\` now exposes bounded
\`participation\` metadata for the authenticated user and tenant: participant,
conference, state, recoverability, and recovery deadline. It contains no
runtime target, credential, or secret material. RH-2 will consume this API;
RH-1 does not change the browser or implement automatic rejoin.

An existing every-minute scheduler now invokes the domain-owned expiration
sweep. Expired abandoned self-admission participants are transitioned through
the canonical participant lifecycle to \`removed\`; expiration is
transactionally locked against replacement binding. No manual flush,
reconciliation command, feature gate, or Reverb dependency was introduced.

Focused tests cover current-channel loss, stale-channel fencing, replacement
binding reset, recoverability and exact deadline behavior, automatic
expiration, bootstrap discovery, and preservation of the active telephony
session.

## Phase chain

\`\`\`
V0 COMPLETE
→ RT-1A COMPLETE / LIVE PROVEN
→ RH-0 COMPLETE
→ RH-1 IMPLEMENTED / TESTED
→ RH-2 browser recovery next
\`\`\`
