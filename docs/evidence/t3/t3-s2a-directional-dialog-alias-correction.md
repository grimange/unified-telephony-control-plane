# T3-S2A Directional Dialog Alias Correction

Date: 2026-08-01

Starting commit: `8e922fb` (`docs(t3): record alias postcondition regression`)

Phase marker: `UTCP_PHASE=T1`

Kubernetes apply: not performed.

## Scope

This repository-only correction addresses `PRODUCT_DEFECT-14`: the alias postcondition added for browser-bound in-dialog requests was applied to every established request. That protected the Asterisk-to-browser BYE last hop, but it incorrectly rejected browser-to-Asterisk ACK and BYE requests whose Request-URI is a normal routable Asterisk SIP Contact and whose `$du` may legitimately remain empty after `loose_route()`.

No Asterisk configuration, Asterisk Service, internal Kamailio Service, NetworkPolicy, Record-Route advertisement, subscriber authentication, REGISTER storage, rtpengine resource, Gateway edge, database schema, RuntimeNode authority, or `UTCP_PHASE` value changed.

## Runtime Evidence

The packet-captured live proof recorded in `t3-s2a-asterisk-sip-application-dialog-live-proof.md` established the split behavior:

- Asterisk-to-browser BYE passed through `loose_route()`, carried the alias-bearing browser Contact, `handle_ruri_alias()` produced a non-empty `$du`, the existing browser WebSocket connection received BYE, the browser returned `200 OK`, and the response reached Asterisk.
- Browser-to-Asterisk ACK regressed because the postcondition required `$du` after `handle_ruri_alias()` even though the ACK targeted the routable Asterisk Contact and carried no alias.
- Browser-originated BYE regressed for the same reason, returning `400 Bad Request` with `result=missing_dialog_contact_alias` instead of relaying to Asterisk.

The root cause is directional: browser-bound sequential requests need a WebSocket alias and destination URI, while Asterisk-bound sequential requests use ordinary Request-URI forwarding.

## Dialog Corridor Matrix

| Corridor | Request direction | Expected Request-URI form | Alias required | `$du` required | Expected behavior |
|---|---|---|---|---|---|
| Initial browser to Asterisk | Browser to Kamailio to Asterisk | Canonical initial application dialog at `sip.utcp.local.test` | Contact alias is added after authentication for WS/WSS | Asterisk relay route sets `$du` | `add_contact_alias()`, `record_route()`, relay to canonical Asterisk Service |
| Sequential browser to Asterisk | Browser to Kamailio to Asterisk | Normal routable Asterisk SIP Contact | No | No, may remain empty | Relay using the Request-URI after `loose_route()` |
| Sequential Asterisk to browser | Asterisk to Kamailio to browser | Browser Contact with `.invalid` host and/or WS/WSS transport plus `alias` parameter | Yes | Yes, after `handle_ruri_alias()` | Consume alias and relay over the existing WebSocket connection |
| Missing browser alias | Asterisk to browser-style target | R-URI has WS/WSS transport or reserved `.invalid` browser Contact form but no `alias` | Yes | Not reached | `400 Bad Request`, `result=missing_dialog_contact_alias`, no DNS, no relay |
| Malformed browser alias | Asterisk to browser-style target | R-URI contains malformed `alias` parameter | Yes | Yes, but not produced | `400 Bad Request`, `result=invalid_dialog_contact_alias`, no DNS, no relay |

## Alias-Presence Routing Rule

`route[WITHINDLG]` now keeps alias consumption inside the successful `loose_route()` branch and behind the existing `$du == ""` guard. Within that branch it first checks the Request-URI alias parameter:

```kamailio
if ($(ru{uri.param,alias}) != "") {
    if (!handle_ruri_alias()) {
        ...
        sl_send_reply("400", "Bad Request");
        exit;
    }

    if ($du == "") {
        ...
        sl_send_reply("400", "Bad Request");
        exit;
    }
}
```

This preserves the working browser-bound corridor and prevents a malformed or non-consuming alias from falling through to DNS or ordinary relay.

## Missing Browser Alias Rule

The correction does not skip protection for browser-style targets that lack an alias. If `$du` is empty and no alias parameter exists, Kamailio rejects targets that identify the browser WebSocket Contact shape:

- R-URI transport parameter is `ws`.
- R-URI transport parameter is `wss`.
- R-URI host matches the reserved `.invalid` Contact form.

Those requests return `400 Bad Request` with `result=missing_dialog_contact_alias` and stop before `t_relay()`.

## Asterisk-Bound R-URI Handling

When `loose_route()` succeeds, `$du` is empty, no alias parameter exists, and the R-URI is not browser-style, Kamailio now proceeds to the existing relay using the normal Request-URI. This restores the expected ACK and client-originated BYE path toward Asterisk without synthesizing `$du`, adding an Asterisk allowlist, adding direction flags, or introducing another dialog authority.

## Static Validation

`scripts/kamailio-signaling/config-check` now validates the brace-matched `WITHINDLG` structure:

- `loose_route()` precedes alias logic.
- Alias presence is checked using the Request-URI alias parameter.
- `handle_ruri_alias()` is reachable only in the alias-present branch.
- A valid alias must produce non-empty `$du`.
- Invalid alias handling exits explicitly.
- Browser-style targets without aliases exit explicitly.
- The old unconditional `$du` postcondition is rejected.
- Initial authentication and Asterisk destination selection remain unreachable from `WITHINDLG`.
- REGISTER remains free of alias operations.
- Checksum coupling remains exact.

## Mutation Coverage

`scripts/kamailio-signaling/config-check-test` now covers the complete directional matrix:

- Asterisk-bound ACK and BYE with routable R-URI and no alias pass the repository validator.
- Restoring an unconditional `$du` requirement fails.
- Requiring aliases for every established request fails.
- Synthesizing an Asterisk-bound destination fails.
- Removing alias consumption, alias presence detection, or the alias `$du` postcondition fails.
- Moving alias consumption before `loose_route()` or after relay fails.
- WS, WSS, or `.invalid` browser targets without alias fail.
- Missing or malformed browser aliases cannot reach DNS, relay, or a fallback.
- Existing lowercase WS/WSS initial alias creation, Record-Route, ACK/client-BYE ordering, Asterisk `503`, DNS-policy, REGISTER, rtpengine, public-surface, and checksum guards remain active.

## Parser Results

Focused repository validation passed before this evidence update:

```text
make kamailio-signaling-config-check
kamailio_signaling_config_check=pass
live_kamailio_runtime=configured

make kamailio-signaling-config-check-test
kamailio_signaling_config_check_regression_tests=pass
```

Those checks render the supported Kamailio variants, parse the final configuration with the pinned Kamailio image, and verify the deterministic Deployment checksum.

## Status

`PRODUCT_DEFECT-14 = corrected in repository`.

`PRODUCT_DEFECT-11` through `PRODUCT_DEFECT-14` are ready for consolidated live closure.

`T3-S2A = ready for final bidirectional dialog proof`.

`T3-S2 media mediation = Not Started`.

`T3 = In Progress`.

## Remaining Proof Boundary

The remaining live proof should apply only the corrected Kamailio ConfigMap and checksum-coupled Deployment, then prove:

1. Browser ACK reaches Asterisk without requiring an alias.
2. Browser-originated BYE returns `200 OK`.
3. A clean CLI-triggered Asterisk BYE uses the alias and completes through the existing WebSocket connection.
4. Missing and malformed browser aliases return explicit `400` responses without DNS or relay.

Do not repeat Record-Route, cluster DNS, NetworkPolicy, unavailable-runtime `503`, REGISTER, rtpengine, restoration, or public-surface foundation proof.
