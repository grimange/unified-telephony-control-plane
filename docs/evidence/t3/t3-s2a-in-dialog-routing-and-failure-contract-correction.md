# T3-S2A In-Dialog Routing and Failure Contract Correction

Starting commit: `df2d89b` (`docs(t3): record incomplete asterisk dialog reproof`)

Phase marker: `UTCP_PHASE=T1`

No Kubernetes resources were applied by this repository correction.

## Proven Starting Point

The authenticated-dialog diagnostic run recorded in
`docs/evidence/t3/t3-s2a-asterisk-sip-application-dialog-live-proof.md`
proved the initial dialog foundation before this correction:

- Authenticated initial INVITE: PASS.
- Kamailio-to-Asterisk UDP relay: PASS.
- Asterisk extension `9900`: PASS.
- SDP-bearing `200 OK`: PASS.
- Healthy-Asterisk false `503`: resolved.
- REGISTER preservation: PASS.
- Unsupported MESSAGE `405`: PASS.
- Asterisk restoration: PASS.
- rtpengine non-involvement: PASS.
- Public-surface containment: PASS.
- State-authority preservation: PASS.

The same proof isolated three remaining repository defects:
`PRODUCT_DEFECT-7`, `PRODUCT_DEFECT-8`, and `PRODUCT_DEFECT-9`.

## PRODUCT_DEFECT-7

Root cause: `request_route` performed initial Request-URI domain validation before
established-dialog handling. A legitimate sequential ACK or BYE uses the
established route set and remote target, so `$rd` may be an Asterisk Contact, Pod
address, or other next hop rather than `sip.utcp.local.test`. The old order
therefore rejected real in-dialog requests with the initial foreign-domain guard
before `has_totag()` and `loose_route()` could run.

Correction: transaction-aware CANCEL handling remains before established-dialog
routing, then requests with a To-tag execute:

```kamailio
if (has_totag()) {
    route(WITHINDLG);
    exit;
}
```

Only initial out-of-dialog requests then reach the canonical local-domain guard.
`WITHINDLG` uses the existing Record-Route and TM authority through
`loose_route()` and does not repeat subscriber authentication, initial Asterisk
destination selection, or `record_route()`. Unknown malformed sequential
requests fail explicitly.

The old log label was clarified from `foreign_domain` to
`initial_foreign_domain` so it accurately identifies initial-request rejection.

## PRODUCT_DEFECT-8

Root cause: both Kamailio listeners bound `0.0.0.0` without an advertised
identity, so `record_route()` produced route identities containing
`sip:0.0.0.0`. The wildcard bind address is valid socket authority, but it is
not a reachable dialog identity.

Correction: the bind sockets are preserved and each Record-Route-participating
listener now has an explicit stable advertisement:

- Client-facing TCP listener: `sip.utcp.local.test:443`, matching the existing
  canonical T1 signaling edge identity.
- Asterisk-facing UDP listener:
  `kamailio-sip-internal.utcp-platform.svc.cluster.local:5060`.

The Asterisk-side identity is backed by a new internal ClusterIP-only Service,
`Service/utcp-platform/kamailio-sip-internal`, exposing only named UDP `5060` to
the Kamailio signaling workload. No Service ClusterIP literal, Pod IP, node IP,
developer-host IP, NodePort, LoadBalancer, Gateway, Ingress, UDPRoute, HostPort,
HostNetwork, k3d publication, or public SIP exposure was added.

Double Record-Route behavior remains active through the existing Kamailio
`record_route()` authority and per-listener `advertise` identities. No dialog
database and no `record_route_preset()` authority was introduced.

## Reverse Signaling Corridor

The existing Kamailio-to-Asterisk UDP `5060` corridor is preserved and tightened
to the canonical local Asterisk runtime selector. The reverse established-dialog
corridor is added exactly:

- Asterisk source egress:
  `NetworkPolicy/utcp-runtime/allow-asterisk-sip-from-kamailio` allows the
  canonical Asterisk runtime workload to send UDP `5060` only to the Kamailio
  signaling workload in `utcp-platform`.
- Kamailio destination ingress:
  `NetworkPolicy/utcp-platform/allow-kamailio-signaling-required-traffic` allows
  UDP `5060` only from the canonical Asterisk runtime workload in
  `utcp-runtime`.

Default-deny, ARI policy, rtpengine control policy, DNS and PostgreSQL rules,
and public-surface prohibitions remain intact. The secondary Asterisk workload
is not granted this corridor.

## PRODUCT_DEFECT-9

Root cause: `route[ASTERISK_RELAY]` only returned
`503 Application Runtime Unavailable` for the synchronous `t_relay()` failure
path. When the Asterisk Service had no Ready endpoint, UDP relay could still be
accepted locally and fail asynchronously as a generated `408`, so the committed
runtime-unavailable contract was not reached.

Correction: `route[ASTERISK_RELAY]` now arms exactly one failure route before
`t_relay()`:

```kamailio
t_on_failure("ASTERISK_UNAVAILABLE");
```

Immediate `t_relay()` failure still returns:

```text
503 Application Runtime Unavailable
```

`failure_route[ASTERISK_UNAVAILABLE]` exits for client cancellation and maps only
the generated local `408` timeout condition to
`503 Application Runtime Unavailable` using `t_reply()`. It does not add a
branch, change destination, retry another Asterisk target, fall back to a Pod IP,
route through ARI, invoke rtpengine, or override arbitrary legitimate Asterisk
final responses. The observability log includes non-sensitive Call-ID
correlation and `result=asterisk_unavailable`.

## Static Guards and Mutation Coverage

`scripts/kamailio-signaling/config-check` now validates the brace-matched route
structure rather than whole-file substring order. It checks:

- CANCEL remains transaction-aware and before established-dialog routing.
- `has_totag()` and `route(WITHINDLG)` precede initial-domain validation.
- `loose_route()` is inside `WITHINDLG`.
- Established-dialog routing cannot reach initial authentication or destination
  selection.
- Initial foreign domains remain rejected.
- REGISTER remains isolated from application-dialog routing.
- Asterisk destination selection occurs only for initial application dialogs.
- Every Record-Route listener has the expected stable advertisement.
- The internal Kamailio SIP Service is ClusterIP-only and UDP `5060` only.
- Reverse Asterisk/Kamailio NetworkPolicies are exact and reciprocal.
- Public SIP exposure remains absent while the internal UDP Service is allowed.
- `t_on_failure("ASTERISK_UNAVAILABLE")` is armed before Asterisk `t_relay()`.
- The failure route exists, uses the generated `408` predicate, returns the
  committed `503`, avoids fallback branches and rtpengine, preserves
  cancellation, and emits the expected log correlation.

`scripts/kamailio-signaling/config-check-test` adds focused mutation coverage for
the three defects, including route reordering regressions, missing established
route/loose-route behavior, missing initial-domain rejection, missing or invalid
advertisements, wildcard and IP advertisements, missing or public internal SIP
Service variants, forbidden Gateway/UDPRoute/HostPort/HostNetwork exposure,
missing or widened reverse policies, secondary-Asterisk authority, stale
checksums, missing/misordered failure route arming, broad response mapping,
fallback branches, rtpengine calls, cancellation conversion, and missing
unavailable-runtime log correlation.

`scripts/security/config-check` now also recognizes the exact reciprocal
Kamailio/Asterisk SIP corridor and the canonical Asterisk runtime selector.

## Verification

Repository verification performed for this correction:

- `make repository-hygiene`: passed.
- `make workflow-check`: passed.
- `make secret-scan`: passed.
- `make k8s-config-check`: passed.
- `make security-config-check`: passed after updating the stale ingress-only
  security checker.
- `make security-config-check-test`: passed.
- `make media-config-check`: passed.
- `make media-config-check-test`: passed.
- `make kamailio-signaling-config-check`: passed, including rendered
  configuration extraction, checksum validation, and pinned Kamailio parser
  validation.
- `make kamailio-signaling-config-check-test`: passed after aligning the focused
  harness with the canonical Asterisk runtime selector.
- `make check`: passed.
- `make gateway-config-check`: first reported missing `helm`; Helm
  `v4.0.3` was downloaded from the repository pin, checksum verified
  (`helm-v4.0.3-linux-amd64.tar.gz: OK`), used only from
  `/tmp/utcp-tools/gateway`, and then removed. The rerun passed.
- `git diff --check`: passed.
- `git diff --cached --check`: passed.

No hosted CI proof was observed.

## Remaining Live Proof

The repository correction is ready for focused ACK/BYE/failure reproof only:

Claude Code applies only the corrected Kamailio configuration, checksum-coupled
Deployment, internal Kamailio SIP Service and exact reverse NetworkPolicies.
It then re-proves ACK continuity, BYE continuity, reachable Record-Route,
Asterisk-originated in-dialog reachability, explicit Asterisk-unavailable 503,
restoration and environment preservation. Do not repeat initial authentication,
initial INVITE, Asterisk SDP, REGISTER, rtpengine or public-surface foundation
proof.

T3-S2 media mediation remains Not Started. T3 remains In Progress.
