# T3-S2 Provider-Neutral Media Mediation Correction

Date: 2026-08-01

Starting commit: `cc5b85b` (`docs(t3): close asterisk dialog authority`)

Phase marker: `UTCP_PHASE=T1`

Kubernetes apply: not performed.

## Scope

This repository-only slice adds the first provider-neutral rtpengine mediation contract on top of the completed T3-S2A bidirectional SIP dialog route.

Kamailio remains the SIP transaction, dialog-routing and media-control invocation authority. rtpengine owns ephemeral RTP/RTCP/ICE relay state. The selected application runtime remains only the SIP/SDP peer and application executor; Asterisk is the current reference runtime for this slice, not the canonical media-session authority.

No Asterisk deployment, PJSIP configuration, Asterisk SIP Service, internal Kamailio SIP Service, NetworkPolicy, subscriber authentication, REGISTER storage, database schema, RuntimeNode authority, Gateway edge, or `UTCP_PHASE` value changed.

## Provider-Neutral Authority

Media lifecycle decisions are driven by SIP method, dialog state, SDP presence, transaction reply/failure handling and route direction:

- Initial `INVITE` with SDP calls `route(MEDIA_OFFER)` before `record_route()` and before relay to the selected application runtime.
- Application-runtime SDP replies call `route(MEDIA_ANSWER)` from the named reply route armed on the initial runtime relay transaction.
- In-dialog `INVITE` or `UPDATE` with SDP calls the same `route(MEDIA_OFFER)` and arms the same named reply route.
- `BYE` in either established direction calls `route(MEDIA_DELETE)` before normal signaling relay.
- `CANCEL`, cancellation and terminal application-runtime failure call `route(MEDIA_DELETE)` through the same delete authority.

The implementation does not use Asterisk channel IDs, ARI, AMI, CLI state, dialplan context, Asterisk Pod IPs, provider-specific headers, Redis, PostgreSQL, environment gates, or durable media-session storage as media-session authority.

## Offer Path

`route[APPLICATION_DIALOG]` invokes `route(MEDIA_OFFER)` after canonical domain validation, subscriber authentication, authenticated identity validation and the authenticated WS/WSS Contact alias branch, and before `record_route()` plus application-runtime relay.

`route[MEDIA_OFFER]` is SDP-gated with `has_body("application/sdp")`. When SDP is absent, it returns without creating a false media session. When SDP is present, it selects media flags from media-leg characteristics:

- Browser-facing characteristics use WebRTC-oriented flags: ICE forced, SRTP/DTLS profile, and rtcp-mux offer handling.
- Application-runtime-facing characteristics use the plain RTP profile required by the current rendered runtime SDP contract.

Offer failure logs non-sensitive Call-ID correlation with `result=media_offer_failed`, returns `488 Media Relay Unavailable`, and exits. There is no direct-media fallback and no second relay target.

## Answer Path

`route[ASTERISK_RELAY]` arms `t_on_reply("APPLICATION_RUNTIME_MEDIA_REPLY")` before the initial `t_relay()`. `route[WITHINDLG]` arms the same reply route for in-dialog `INVITE` and `UPDATE` before relay.

`onreply_route[APPLICATION_RUNTIME_MEDIA_REPLY]` delegates to `route(MEDIA_ANSWER)`. The answer route is SDP-gated and applies the complementary browser-facing or application-runtime-facing media profile before the SDP response continues. Answer failure logs `result=media_answer_failed` with Call-ID correlation and drops the failed SDP reply instead of bypassing rtpengine or allowing direct media.

## Delete Paths

`route[WITHINDLG]` calls `route(MEDIA_DELETE)` for established `BYE` before relay. This protects both proven signaling directions:

- Browser-originated BYE deletes the media session and preserves normal relay toward the application runtime.
- Application-runtime-originated BYE deletes the media session and preserves the alias-based WebSocket route toward the browser.

`route[MEDIA_DELETE]` is the single direct rtpengine delete authority. Duplicate or already-cleaned sessions log `result=media_delete_failed` and return without breaking the SIP transaction.

## CANCEL And Failure Cleanup

Transaction-aware `CANCEL` handling calls `route(MEDIA_DELETE)` before checking and relaying the existing transaction. The Asterisk-unavailable failure route delegates cancellation cleanup and terminal branch-failure cleanup to the same media delete route, then preserves the committed `408` to `503 Application Runtime Unavailable` mapping for runtime no-response.

Cleanup uses the standard SIP transaction/dialog identity expected by the Kamailio rtpengine module; no Asterisk channel identifier or durable media-session key is introduced.

## Direct-Media Prohibition

The repository keeps media anchored through rtpengine. Static validation rejects direct-media bypass patterns in the Kamailio media routes, `rtpengine_manage()`, embedded Pod or node media IP literals, and provider-specific media lifecycle authority. The current Asterisk adapter still keeps `direct_media=no` as a runtime safeguard, but Kamailio plus rtpengine own the generic media-control lifecycle.

## REGISTER Preservation

REGISTER remains outside application-dialog media mediation. The brace-matched REGISTER route contains no `route(MEDIA_*)` call and no `rtpengine_offer`, `rtpengine_answer`, `rtpengine_delete`, or `rtpengine_manage` invocation.

## Static Validation

`scripts/kamailio-signaling/config-check` now validates:

- The rtpengine module and ClusterIP ng control socket are configured.
- Initial SDP INVITEs call `MEDIA_OFFER` before `record_route()` and runtime relay.
- Runtime SDP replies use the named `APPLICATION_RUNTIME_MEDIA_REPLY` route.
- In-dialog `INVITE`/`UPDATE` call `MEDIA_OFFER` and arm answer handling.
- BYE, CANCEL, cancellation and terminal failure delegate cleanup to `MEDIA_DELETE`.
- REGISTER and unsupported methods do not create media.
- Media routes use provider-neutral names and no Asterisk channel/ARI/AMI/dialplan authority.
- No direct media fallback, Pod IP, node IP, developer-host IP, public SIP exposure or stale checksum is accepted.

`scripts/media/config-check` now verifies that the rendered Kamailio ConfigMap wires the provider-neutral rtpengine offer, answer and delete routes while continuing to protect the existing T3-S1 rtpengine infrastructure boundary.

## Mutation Coverage

`scripts/kamailio-signaling/config-check-test` and `scripts/media/config-check-test` now cover the media lifecycle mutations required for this slice:

- Missing module or rtpengine socket.
- Missing, late, or wrongly placed initial media offer.
- Missing SDP guards.
- Missing reply-route answer handling.
- Missing BYE, CANCEL, or terminal-failure cleanup.
- Media operations in REGISTER.
- Offer or answer failure falling through to direct media.
- `rtpengine_manage()` replacing explicit offer/answer/delete.
- Duplicate delete authority.
- Direct rtpengine operations outside provider-neutral media routes.
- Asterisk channel-state or IP-literal media authority.
- Stale checksum coupling.

Existing bidirectional WebSocket alias, Asterisk unavailable `503`, DNS-policy, REGISTER, rtpengine infrastructure and public-surface guards remain active.

## Parser Results

The focused Kamailio and media checkers render supported variants, validate the deterministic checksum annotation, and parse the final Kamailio configuration with the pinned Kamailio image. Full repository verification is recorded in the commit final report for this correction.

## Status

`T3-S2A = Complete`.

`T3-S2 provider-neutral media mediation = corrected in repository and awaiting live proof`.

`Asterisk = current reference runtime`.

`runtime-agnostic parity = not yet proven; second-runtime parity remains required`.

`T3 = In Progress`.

`UTCP_PHASE=T1`.

## Remaining Live Proof

The next bounded proof should apply only the corrected Kamailio media configuration and checksum-coupled Deployment, then prove:

1. Browser SDP offer is rewritten through rtpengine before reaching the current reference runtime.
2. Application-runtime SDP answer is rewritten through rtpengine before reaching the browser.
3. Browser-originated BYE deletes the media session without breaking signaling.
4. Application-runtime-originated BYE deletes the media session without breaking alias-based WebSocket routing.

After that proof passes, a second-runtime parity slice, such as FreeSWITCH, remains required before declaring the application-runtime media contract proven agnostic.
