# V1 Gap E — Provider Channel to CallLeg Correlation Implementation

Current-State-Impact: yes

Date: 2026-08-30

## Verdict

`V1_GAP_E_PROVIDER_CHANNEL_CALL_LEG_CORRELATION_IMPLEMENTED_AND_TESTED`

The prior controlled proof established that the provider-facing PJSIP
`ChannelDestroyed` event carries the authoritative raw facts and that the
already-inherited `__UTCP_CALL_LEG_ID` reaches that channel. This packet closes
the repository correlation seam without implementing provider-failure taxonomy.

## Bounded repair

The managed Asterisk entrypoint now projects exactly
`channelvars = UTCP_CALL_LEG_ID` into generated `ari.conf`. The closed ARI
sanitizer allow-list accepts only that channel variable when it is a trimmed,
valid UUID, and the listener reduces it to `call_leg_id`; unrelated variables
and malformed identities are dropped. The normalizer first retains exact
runtime-channel matching, then uses the explicit identity only within the
receipt tenant and runtime-node fences, and otherwise preserves the
`runtime:<channel>` fallback.

The provider runtime channel remains evidence in the normalized payload and
does not replace the CallLeg's canonical Local `;1` `runtime_channel_id`.
Existing `cause`, `cause_txt`, and `tech_cause` preservation remains raw
Layer-2 evidence; no canonical meaning, taxonomy, schema, dialplan, Kamailio,
or failure writer was added.

## Proof

Focused Asterisk tests cover generated config projection, raw sanitizer
preservation and rejection, listener reduction, provider termination
correlation, tenant/runtime-node isolation, raw fact preservation, and
canonical runtime-channel non-mutation. Existing Asterisk, FreeSWITCH, and
domain regressions remain required; full API regression is run through the
repository container path.

No live deployment or provider 404 re-proof was performed. The exact next
action is controlled native-k3s deployment and natural 97002/SIP 404 re-proof.

## Explicit boundaries

No failure taxonomy, provider-channel side table, linkedid heuristic, feature
gate, manual control, dialplan propagation change, ARI broad channelvar
exposure, Kamailio change, schema change, or live environment mutation was
added.
