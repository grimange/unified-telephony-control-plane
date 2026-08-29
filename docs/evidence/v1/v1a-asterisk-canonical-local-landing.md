# V1-A Asterisk canonical Local landing

## Scope

This is a bounded implementation and semantic-proof note for the managed
Asterisk ARI originate answer lifecycle. It does not close V1-A and does not
change canonical answer-observation reconciliation.

Repository source identity at implementation start:

```text
branch: main
commit: 47324dd8ff8d497177b2e9a8f9504075ae317c69
subject: fix(v1): eliminate duplicate provider origination
```

## Root cause

The prior ordinary outbound originate supplied `endpoint=Local/97001@utcp-outbound`
and `extension=97001`, without `context`, `priority`, or `app`. Asterisk
therefore used its ARI default post-answer destination `default,97001,1`; that
extension was absent and the `;1` Local half was destroyed. Supplying
`context=utcp-outbound`, `extension=97001`, and `priority=1` is also invalid for
this architecture because it gives both Local halves authority to execute the
provider Dial path.

`extension` is optional in the ARI originate contract. The repaired operation
uses the existing profile-owned Stasis application as the alternative
post-answer landing.

## Repair and preserved authority

Ordinary managed outbound origination now uses:

```text
endpoint=Local/97001@utcp-outbound
app=utcp-t0-observation
timeout=30
channelId=<CallLeg runtime channel identity>
formats=ulaw
callerId=utcp-v1 <utcp-v1>
```

The four UTCP correlation variables remain in the JSON body. The Local
outbound side still enters `utcp-outbound` exactly once, traverses
`utcp-outbound-predial`, and remains the sole provider-dialing authority.
The control side enters the existing Stasis application. No `/n` modifier was
added: the ARI `channelId` remains the canonical Local control reference while
the application owns the answered control half, and later adapter operations
continue to address that stored channel identity.

## Exact-runtime proof

The repository-owned semantic harness uses the canonical baked-in managed
Asterisk image, an isolated Docker network, and the synthetic SIP peer. The
passing run established:

```text
provider_invites=1
provider_answer=200
from_user=utcp-v1
local_control_half_valid=true
answered_channel_sustained=true
```

The harness asserts the ARI app landing, absence of invalid default-extension
errors, absence of duplicate provider INVITEs, sustained answered-channel
state beyond the historical failure window, and normal ARI teardown.

## Deferred proof gap

The separate canonical answer-observation ingestion/terminal-state race
remains deferred pending a clean live reproof. No answer lifecycle policy or
reconciliation source was changed by this repair.
