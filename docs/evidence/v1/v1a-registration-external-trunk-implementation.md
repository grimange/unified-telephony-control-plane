# V1-A Registration-Based External Trunk Implementation

## Status

`V1_REGISTRATION_EXTERNAL_TRUNK_IMPLEMENTED_AND_TESTED`

V1 remains active. This document records the bounded repository implementation
of registration-based `ExternalTrunk` connectivity. It does not claim live
interoperability with the independent PBX at `38.146.161.46`.

## Canonical model

Registration connectivity remains part of the existing C7A aggregate:

```text
ExternalTrunk
  -> TrunkEndpoint
  -> TrunkCredentialReference
  -> TelephonyAddress / CallerIdentity
```

`trunk_endpoints.signaling_mode` is `static` or `outbound_registration`.
Registration intent is limited to `registration_target`, `registration_realm`,
and `registration_identity`. Existing rows default to `static`.

Static endpoints reject registration-only attributes. Registration endpoints
require credential authentication, a credential reference, target, realm, and
identity. Plaintext secrets are not accepted in endpoint fields.

C7B remains the owner of `InboundRoute`, `OutboundRoute`, `RouteDecision`, and
`DestinationRef`. Registration supplies reachability and readiness only; it
does not select a trunk or destination.

## Credential and T6 projection

The existing encrypted `TrunkCredentialReference` remains the only canonical
secret authority. During provider projection, the application decrypts the
credential transiently and computes:

```text
MD5(registration_identity:registration_realm:password)
```

The Kamailio provider table stores the resulting HA1 verifier and an empty
`auth_password`; it does not store a second plaintext secret. The generic
`utcp.t6.projection.v1` endpoint contains only signaling mode and registration
intent fields alongside the existing credential reference/version. It contains
neither plaintext nor HA1.

Credential rotation follows the existing C7A rebind/version/outbox path. The
T6 dispatcher reprojects the provider row and invokes the internal Kamailio
reload/refresh adapter. Removed rows invoke unregister/remove before refresh of
the remaining rows.

## Kamailio provider execution

Kamailio remains the sole registration executor. The installed `uac.so` and
`jsonrpcs.so` modules are configured; `uac_reg` reads the sanitized
`kamailio_external_trunk_registration_view` representation. The control seam
is a ClusterIP-only service on TCP 8090 and is not connected to Traefik,
Gateway API, k3d serverlb, or any public listener.

The Kamailio registration database reader is separate from the browser auth,
usrloc writer, and observer roles. It receives SELECT access only to the
registration provider table and the Kamailio `version` table.

The existing WSS listener, usrloc/registrar path, static external-trunk route
view, and Asterisk projection remain in place.

## Observation and lifecycle

`external_trunk_registration_observations` is observed state, not C7A desired
state. It normalizes only `not_configured`, `registering`, `registered`,
`failed`, `expired`, and `disabled`; failure categories are limited to
`auth_rejected`, `not_found`, `timeout`, `unreachable`, and `expired`.
Contacts are represented only by a SHA-256 fingerprint.

The existing Kamailio observer process also polls external registration state
through the internal control client. Active registration endpoints become
outbound-eligible only when observed `registered`; static endpoints ignore
registration observations. Registered, registering, and failed/expired states
map to the existing ready, degraded, and unavailable eligibility semantics.

Draft/validating endpoints do not project registration. Active endpoints
project and refresh. Draining endpoints retain registration but do not gain new
call eligibility. Disabled and retired endpoints are unregistered/removed and
cannot reactivate from the terminal retired state.

## Validation and proof

The focused V1-A test covers static defaults, validation combinations, HA1
projection, secret omission from T6 artifacts, registration readiness, and
static readiness isolation. `make kamailio-signaling-registration-runtime-proof`
runs the Kamailio configuration contract plus that focused synthetic API/T6
proof without reading `.env-external-pbx-sip-credentials` or using the real
PBX.

The proof is intentionally repository/synthetic at this stage: no disposable
SIP registrar fixture was available in the existing runtime topology, so this
packet does not claim a real REGISTER challenge/response or remote PBX result.

## Remaining V1 proof

The next bounded step is controlled live proof of the running Kamailio
`uac_reg` REGISTER path against a synthetic registrar and then the independent
PBX. The broader V1 full-call relay and bidirectional external acceptance also
remain active requirements. The V1-B static UDP/5060 edge is preserved but not
activated by this implementation.
