# T3-S2A WebSocket Dialog Alias Correction

Starting commit: `3b65740` (`docs(t3): record blocked asterisk bye closure`)

Phase marker: `UTCP_PHASE=T1`

## Scope

This is a repository-only correction for `PRODUCT_DEFECT-11`. No Kubernetes
resources were applied, no workloads were restarted, no Asterisk, rtpengine,
REGISTER, Record-Route, DNS-policy, or public-surface resource was changed, and
T3-S2 media mediation was not started.

## PRODUCT_DEFECT-11

The final T3-S2A closure proof established that Asterisk can resolve the internal
Kamailio Service DNS name and send an in-dialog BYE to Kamailio over the exact
reverse UDP `5060` corridor. Kamailio entered `route[WITHINDLG]`, `loose_route()`
succeeded, and the Request-URI became the browser Contact:

```text
sip:<client>@<random>.invalid;transport=ws
```

The defect was the missing WebSocket connection alias lifecycle. `nathelper.so`
was already loaded, but the Kamailio configuration contained zero
`add_contact_alias()` calls and zero `handle_ruri_alias()` calls. After
`loose_route()`, Kamailio therefore attempted DNS resolution for the RFC 7118
`.invalid` Contact host, failed to add a branch, and never delivered the BYE to
the browser.

## RFC 7118 Contact Behavior

RFC 7118 defines SIP over WebSocket behavior for browser clients. Browser user
agents can publish Contact hosts under `.invalid` because the actual return path
is the existing WebSocket connection, not DNS routing to that host.

Reference: <https://www.rfc-editor.org/rfc/rfc7118.html>

## Kamailio WebSocket Pattern

The Kamailio WebSocket pattern for this topology is:

```text
initial non-REGISTER WebSocket request:
  add_contact_alias()

established request:
  loose_route()
  handle_ruri_alias()
  relay
```

`add_contact_alias()` is selected instead of `set_contact_alias()` because this
slice needs the documented WebSocket pattern: retain the browser's original
Contact while appending the received connection binding required for later
server-originated in-dialog requests.

References:

* <https://www.kamailio.org/docs/modules/stable/modules/websocket.html>
* <https://www.kamailio.org/docs/modules/stable/modules/nathelper.html>

## Correction

`route[APPLICATION_DIALOG]` now creates the WebSocket Contact alias only for
initial authenticated WS/WSS application INVITEs, after subscriber authentication
and identity validation and before `record_route()`, Asterisk destination
selection, transaction setup, and `t_relay()`.

If alias creation fails, the route logs non-sensitive Call-ID correlation with
`result=websocket_contact_alias_failed`, returns `400 Bad Request`, and exits.
The unmodified `.invalid` Contact is not forwarded when alias creation was
required and failed.

`route[WITHINDLG]` now consumes the R-URI alias only inside established-dialog
routing, after successful `loose_route()` and before relay. Alias handling is
guarded by the official `$du == ""` pattern so an existing destination URI is not
overwritten unnecessarily.

If alias handling is invalid, the route logs non-sensitive Call-ID correlation
with `result=invalid_dialog_contact_alias`, returns `400 Bad Request`, and exits.
It does not fall back to DNS routing of a malformed `.invalid` Contact host.

## Security Constraints

The correction preserves the existing open-relay boundaries:

* initial requests still pass canonical domain validation;
* initial application INVITEs still pass subscriber authentication;
* alias creation is limited to WS/WSS initial application dialogs;
* alias consumption is reachable only after `has_totag()` dispatch and successful
  `loose_route()`;
* alias consumption is unreachable from initial requests and REGISTER;
* no alias path repeats subscriber authentication or Asterisk destination
  selection;
* no arbitrary initial `;alias=` request can cause forwarding;
* no SIP Outbound, Path, GRUU, location-storage change, dialog database,
  connection registry, runtime allowlist, feature gate, or public SIP surface was
  added.

REGISTER remains owned by the existing registrar route. It does not use
`add_contact_alias()`, `set_contact_alias()`, `handle_ruri_alias()`,
`fix_nated_contact()`, `fix_nated_register()`, Path, Outbound, or GRUU in this
slice.

## Static Guards

`scripts/kamailio-signaling/config-check` now validates the rendered Kamailio
route structure with brace-matched route inspection:

* `nathelper.so` remains loaded;
* WS/WSS initial application INVITEs call exactly one `add_contact_alias()`;
* alias creation occurs after authentication and before `record_route()`,
  Asterisk destination selection, and relay;
* alias creation failure returns `400 Bad Request` and exits;
* `set_contact_alias()` is not substituted for the selected WebSocket pattern;
* `has_totag()` dispatches to `WITHINDLG`;
* `loose_route()` occurs before `handle_ruri_alias()`;
* alias consumption occurs before relay and is guarded by `$du == ""`;
* invalid alias handling returns `400 Bad Request` and exits;
* initial authentication and Asterisk destination selection are unreachable from
  alias consumption;
* REGISTER is free of the dialog alias operations;
* Outbound, Path, GRUU, registration-storage changes, rtpengine operation,
  public SIP exposure, and stale Kamailio checksum drift remain rejected.

## Mutation Coverage

`scripts/kamailio-signaling/config-check-test` now covers the WebSocket alias
contract with focused mutations for removing or replacing `add_contact_alias()`,
moving alias creation before authentication, after `record_route()`, or after
relay, removing the WS/WSS guard, aliasing UDP requests, adding duplicates,
removing failure handling or failure exits, removing `handle_ruri_alias()`,
placing alias consumption before `loose_route()` or after relay, removing the
`$du` guard, allowing invalid-alias DNS fallback, running alias consumption on
initial requests or REGISTER, adding alias creation to REGISTER, introducing
Outbound/Path/GRUU authority, allowing arbitrary initial alias routing, and stale
configuration checksums.

Existing ACK, BYE, Record-Route, timeout-to-503, DNS-policy, REGISTER,
rtpengine-boundary, and public-surface mutation coverage remains active.

## Parser Results

The rendered Kamailio configuration parses through the existing
`make kamailio-signaling-config-check` target, which renders all supported
variants and validates each final `kamailio.cfg` with the pinned Kamailio image.
The deterministic Deployment checksum was updated to match the corrected rendered
configuration.

## Status

`PRODUCT_DEFECT-11 = corrected in repository`.

T3-S2A is ready for final browser-bound BYE reproof.

T3-S2 media mediation is Not Started.

T3 remains In Progress.

## Pending Live Proof

The previous DNS selector hardening remains pending for the next live proof apply
alongside the corrected Kamailio resources. No Kubernetes apply was performed by
this correction.

The remaining proof is the browser-bound BYE last hop only: prove that
`handle_ruri_alias()` selects the existing WebSocket connection, the client
receives BYE, returns `200 OK`, the response reaches Asterisk, and the
transaction completes.
