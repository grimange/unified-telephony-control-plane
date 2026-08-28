# V1-A Managed Asterisk ARI Originate Format Capability

Date: 2026-08-28

## Bounded finding

The managed Asterisk live A/B proof showed that an ARI channel originate with
no `originator` and no `formats` allocated both Local channel legs as
`slin192`. The managed Kamailio-facing PJSIP endpoint accepts only `ulaw`, and
the deployed translation matrix had no `slin192` to `ulaw` path. The resulting
compatibility failure caused Dial to tear down the outbound leg before the
provider corridor could proceed. The control and experimental originates were
otherwise identical; adding `formats=ulaw` allocated both Local legs as
`ulaw` and eliminated that compatibility failure.

## Repair

`apps/api/config/asterisk_ari.php` now owns one machine-readable managed
Asterisk execution media declaration: `execution_media_formats = ['ulaw']`.
`AsteriskAriClient` consumes and validates that declaration on
`call.leg.originate`, serializes it as `formats=ulaw`, and fails before any ARI
request when the declaration is missing, blank, non-string, or duplicated.
The intentional absence of `originator` remains unchanged. The existing JSON
body containing the four `__UTCP_*` correlation variables remains unchanged.

The PJSIP `allow=ulaw` endpoint contract, codec module set, dialplan,
direct-media setting, Kamailio provider authentication authority, and V1-7
correlation path were not changed.

## Repository proof

Focused Asterisk adapter coverage asserts the exact `formats=ulaw` query,
unchanged JSON variables body, fail-closed invalid configuration behavior, and
alignment with the Kamailio-facing PJSIP endpoint. The Asterisk guard requires
the canonical declaration and adapter consumption. The containerized API
suite and repository/static checks passed after the repair.

Provider INVITE emission, the natural 401/authentication retry, final provider
response, trust-boundary header stripping, and canonical Call/CallLeg
observation remain for the next controlled live proof. RuntimeNode placement
and SIP failure-fidelity work remain deferred to the V1-A closure audit.
