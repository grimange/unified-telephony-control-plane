# V1-A Kamailio Request-URI Assignment Semantics

Date: 2026-08-28

## Bounded finding

The provider projection and correlation corridor were already repaired. A
Kamailio 6.0.7 live diagnostic then captured the outbound `$ru` value as the
literal quoted source text for `$ru = "sip:$rU@$dbr(...)"`. The same unsafe
assignment class existed in inbound construction for `$rU`. Quoted function
arguments such as `append_hf("...$dbr(...)...")` remain interpolated and were
not changed.

## Repair

Both URI-authority assignments now stage their pseudo-variable values before
using Kamailio's validated explicit concatenation form. Outbound captures the
original `$rU` before changing `$ru`, then constructs the provider URI. Inbound
stages the projected address identity before constructing its internal user.
The bare `$du` database-result assignment and provider authentication path are
unchanged.

## Repository proof

The pinned `ghcr.io/kamailio/kamailio:6.0.7-bookworm` runtime-semantic fixture
starts Kamailio in a disposable container using host UDP networking, waits for
the explicit `Listening on` startup log, sends the synthetic request only after
readiness, captures status/startup/final logs and request-send counts, and then
cleans up the named container. It executes outbound and inbound requests and
asserts the literal results, including `sip:97001@38.146.161.46:5060`. A
negative runtime case and focused mutation checks protect against restoring the
former quoted pseudo-variable assignments without banning valid quoted function
arguments.

This is repository implementation evidence. Provider-bound INVITE emission,
trust-boundary header stripping, natural authentication behavior, final
provider response, and canonical Call/CallLeg observation remain for the next
controlled live proof.

## Authenticated-provider CSeq continuation

The subsequent live diagnostic measured Kamailio 6.0.7 reusing the initial
provider INVITE CSeq after `uac_auth("1")` (`N -> N`). The bounded repair loads
`dialog.so`, enables `track_cseq_updates=1`, and calls `dlg_manage()` only in
`route[RUNTIME_EXTERNAL_TRUNK]` before the first provider transaction. The HA1
AVP contract and `uac_auth("1")` are unchanged.

The exact pinned runtime semantic fixture uses a disposable synthetic UAS and
an explicit readiness log before sending traffic. It observed `4242 -> 4243`
and a Digest Authorization retry on three consecutive runs. A focused negative
mutation removing `dlg_manage()` observed the former `4242 -> 4242` behavior;
source mutations also protect the dialog module and tracking parameter. The
Request-URI semantic regression and valid quoted function-argument behavior
remain covered. Provider post-auth response, CallerIdentity projection,
provider-facing header stripping, and canonical Call/CallLeg observation remain
pending the next controlled live proof.
