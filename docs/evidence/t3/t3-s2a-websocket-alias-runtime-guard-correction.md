# T3-S2A WebSocket Alias Runtime Guard Correction

Date: 2026-08-01

Starting commit: `df79e8f` (`docs(t3): record dead websocket alias guard`)

Phase marker: `UTCP_PHASE=T1`

Scope: repository-only correction for `PRODUCT_DEFECT-12` and `PRODUCT_DEFECT-13`. No Kubernetes resources were applied, no workloads were restarted, and no rtpengine mediation was introduced.

## Established Packet-Capture Evidence

The WebSocket-bound Asterisk BYE proof established the dialog foundation and isolated the remaining failure:

- Authenticated WS INVITE, Asterisk initial dialog, SDP response, Asterisk-originated BYE arrival at Kamailio, `has_totag()`, and `loose_route()` all passed.
- The INVITE forwarded from Kamailio to Asterisk retained the browser `.invalid` Contact but did not contain `;alias=`.
- Asterisk therefore retained an unaliased browser remote target.
- `handle_ruri_alias()` did not select the browser WebSocket connection.
- Kamailio attempted ordinary DNS resolution of the browser `.invalid` host, so the browser received no BYE.

Authorization headers, credentials, and SDP are not recorded in this repository evidence.

## PRODUCT_DEFECT-12

Root cause: `route[APPLICATION_DIALOG]` guarded `add_contact_alias()` with uppercase transport comparisons:

```kamailio
if ($proto == "WS" || $proto == "WSS") {
```

Live Kamailio `$proto` values for the browser leg are lowercase `ws` and `wss`. The guard was therefore dead code, so the authenticated WS initial INVITE could be relayed without an alias-bearing Contact.

Correction: the canonical guard now uses only the lowercase runtime values:

```kamailio
if ($proto == "ws" || $proto == "wss") {
```

The uppercase form was removed rather than retained as a compatibility fallback. The guard remains after subscriber authentication and authenticated identity validation, and before `record_route()` and the Asterisk relay. REGISTER and UDP requests from Asterisk are not aliased.

Alias-creation failure remains explicit:

```text
400 Bad Request
result=websocket_contact_alias_failed
method
Call-ID
exit
```

No Contact credentials, Authorization data, or SDP are logged.

## PRODUCT_DEFECT-13

Root cause: `route[WITHINDLG]` treated a successful `handle_ruri_alias()` return as sufficient. Runtime evidence showed that when the Request-URI contained no alias, the function could return without setting `$du`; Kamailio then fell through to `t_relay()` with `$du == ""` and attempted DNS resolution of the browser `.invalid` host.

Correction: the established-dialog route now enforces the destination postcondition after alias handling:

```kamailio
if ($du == "") {
    if (!handle_ruri_alias()) {
        xlog("L_WARN", "kamailio_application_dialog_rejected result=invalid_dialog_contact_alias method=$rm call_id=$ci\n");
        sl_send_reply("400", "Bad Request");
        exit;
    }

    if ($du == "") {
        xlog("L_WARN", "kamailio_application_dialog_rejected result=missing_dialog_contact_alias method=$rm call_id=$ci\n");
        sl_send_reply("400", "Bad Request");
        exit;
    }
}
```

`invalid_dialog_contact_alias` covers explicit alias processing failure. `missing_dialog_contact_alias` covers the post-call empty-destination condition. Both return `400 Bad Request`, stop route execution, and prevent DNS fallback.

The alias consumption path remains reachable only after:

```text
has_totag()
-> route(WITHINDLG)
-> loose_route()
```

Initial requests, REGISTER, unsupported methods, failed loose routing, and unauthenticated requests cannot use `handle_ruri_alias()` as relay authority. A pre-established non-empty `$du` is preserved because alias handling is still guarded by `$du == ""`.

## Static Validation

`scripts/kamailio-signaling/config-check` now validates the brace-matched route structure for:

- lowercase `$proto == "ws"` and `$proto == "wss"` comparisons in `route[APPLICATION_DIALOG]`;
- absence of uppercase `WS`/`WSS` transport comparisons and mixed compatibility guards;
- `add_contact_alias()` inside the lowercase WS/WSS guard;
- alias creation after authentication and before `record_route()` and relay;
- no alias creation in UDP or REGISTER paths;
- `loose_route()` before `handle_ruri_alias()`;
- `$du == ""` guarding alias consumption;
- explicit `invalid_dialog_contact_alias` and `missing_dialog_contact_alias` failure branches before relay;
- no initial authentication or Asterisk destination selection reachable from alias consumption;
- unchanged rtpengine and public-surface boundaries;
- deterministic Kamailio checksum coupling.

## Mutation Coverage

`scripts/kamailio-signaling/config-check-test` now covers:

- restoring uppercase `WS`/`WSS`;
- accepting only `ws` or only `wss`;
- adding uppercase compatibility alternatives;
- moving alias creation outside the transport guard;
- aliasing UDP requests;
- moving alias creation before authentication, after `record_route()`, or after relay;
- removing alias-creation failure handling;
- removing `handle_ruri_alias()` failure handling;
- removing the post-call `$du` check;
- allowing `$du == ""` to reach relay;
- removing the empty-alias failure response;
- moving the postcondition after relay;
- adding a fallback destination;
- overwriting an already non-empty `$du`;
- running alias consumption on initial requests or REGISTER;
- adding alias behavior to REGISTER;
- stale checksum detection.

Existing ACK, client-BYE, Record-Route, unavailable-runtime `503`, DNS-policy, REGISTER, rtpengine, and public-surface checks remain active.

## Parser and Render Results

Repository verification rendered and parsed the supported Kamailio variants through the existing pinned parser path:

- `infrastructure/kubernetes/base/platform`
- `infrastructure/kubernetes/base`
- `infrastructure/kubernetes/overlays/local/platform`
- `infrastructure/kubernetes/overlays/local`

The lowercase guard, alias functions, `$du` postcondition, and checksum-coupled Deployment all validate through the repository checks.

## Status

`PRODUCT_DEFECT-12 = corrected in repository`

`PRODUCT_DEFECT-13 = corrected in repository`

`PRODUCT_DEFECT-11 = ready for final live closure proof`

`T3-S2A = ready for browser-bound BYE reproof`

`T3-S2 media mediation = Not Started`

`T3 = In Progress`

`UTCP_PHASE=T1`

## Remaining Focused Proof

Claude Code applies only the corrected Kamailio ConfigMap and checksum-coupled Deployment, then proves the WebSocket BYE last hop and malformed-alias failure: the initial Contact carries one alias, Asterisk retains it, `handle_ruri_alias()` produces a non-empty destination, the existing WebSocket connection is used, the browser receives BYE and returns `200 OK`, and missing/malformed aliases fail explicitly without DNS fallback. Do not repeat Record-Route, DNS policy, unavailable-runtime `503`, REGISTER, rtpengine, restoration, or public-surface foundation proof.
