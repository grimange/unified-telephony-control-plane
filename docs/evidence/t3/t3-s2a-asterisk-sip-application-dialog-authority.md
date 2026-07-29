# T3-S2A Asterisk SIP Application-Dialog Authority

Date: 2026-07-29

Starting commit: `8d17a4d` (`docs(t3): close rtpengine foundation proof`)

## Scope

T3-S2A establishes the missing signaling prerequisite for T3-S2:

- one canonical internal Asterisk SIP destination
- one authenticated Kamailio application-dialog routing seam
- no rtpengine offer, answer, delete, manage, or SDP rewriting
- no Kubernetes apply, workload restart, SIPp proof, browser proof, or live RTP proof

## Authority Documents

- `docs/decisions/ADR-019-kamailio-signaling-registration-authority.md`
- `docs/decisions/ADR-020-t3-rtp-media-plane.md`
- `docs/evidence/t3/t3-s1-rtpengine-foundation-live-proof.md`
- `docs/roadmap/implementation-roadmap.md`
- `docs/roadmap/phase-status.md`

## Missing-Authority Blocker

T3-S1 completed the rtpengine foundation and proved Kamailio can reach the rtpengine `ng` control endpoint, but the repository still had no internal Asterisk SIP authority:

- Asterisk exposed ARI TCP `8088` only.
- `modules.conf` used `autoload = no` and loaded no PJSIP module set.
- No `pjsip.conf` defined a SIP transport or Kamailio-facing endpoint.
- No dedicated Asterisk SIP Service existed.
- Kamailio rejected every non-REGISTER method with `405 Method Not Allowed`.
- No Kamailio-to-Asterisk SIP NetworkPolicy corridor existed.
- No application-dialog dialplan context existed.

## Canonical Asterisk SIP Destination

The canonical internal SIP destination is:

```text
asterisk-sip.utcp-runtime.svc.cluster.local:5060
```

The destination is represented by a dedicated `ClusterIP` Service named `asterisk-sip` in `utcp-runtime`, exposing only UDP port `5060` with port name `sip` and target port `sip`. ARI remains on the separate `asterisk-ari` Service and is not mixed with SIP.

No NodePort, LoadBalancer, Gateway, Ingress, HostPort, public DNS name, ClusterIP literal, Pod IP, or second Asterisk target was added.

## PJSIP Module Set

`autoload = no` remains authoritative. The explicit module list now adds the minimum dependency-complete PJSIP path validated against the pinned Asterisk image:

- `res_sorcery_memory.so`: required by `res_pjsip` sorcery storage.
- `res_sorcery_astdb.so`: required by `res_pjsip` sorcery storage.
- `res_pjproject.so`: PJPROJECT foundation required by PJSIP.
- `res_pjsip.so`: PJSIP core.
- `res_pjsip_endpoint_identifier_anonymous.so`: bounded inbound endpoint identification used with NetworkPolicy as the source boundary.
- `res_pjsip_pubsub.so`: dependency required by `chan_pjsip`.
- `res_pjsip_session.so`: INVITE/session handling.
- `res_rtp_asterisk.so`: Asterisk RTP stack needed for SDP/RTP channel operation and the local media fixture.
- `res_pjsip_sdp_rtp.so`: PJSIP SDP/RTP stream handler.
- `chan_pjsip.so`: PJSIP channel driver.
- `app_echo.so`: local-only deterministic media fixture application.

No trunk, WebRTC transport, realtime database endpoint authority, fax, recording, provisioning, or SIP registration module was added.

## Internal Transport and Endpoint

`pjsip.conf` defines exactly one internal UDP transport:

```text
[transport-udp-internal]
type=transport
protocol=udp
bind=0.0.0.0:5060
```

The Kamailio-facing endpoint is the pinned-image supported anonymous inbound endpoint:

```text
[anonymous]
type=endpoint
transport=transport-udp-internal
context=from-kamailio
direct_media=no
disallow=all
allow=ulaw
```

The endpoint is intentionally not a public trust boundary. Kubernetes NetworkPolicy restricts ingress to the Kamailio signaling identity only. This avoids brittle Pod-IP identification while keeping the repository configuration stable.

## Dialplan Context

Base Asterisk configuration now contains:

```text
[from-kamailio]
exten => _.,1,NoOp(UTCP internal application dialog rejected destination=${EXTEN})
 same => n,Hangup(21)
```

Unknown destinations terminate inside `from-kamailio` and do not fall through to another context. No trunk, PSTN, external route, second SIP leg, durable dialog state, or Kamailio registration was added.

## Local-Only Media Fixture

The local overlay generates `ConfigMap/utcp-runtime/asterisk-local-sip-fixtures` from:

```text
infrastructure/kubernetes/overlays/local/runtime/extensions.local.conf
```

It adds one reserved extension under `from-kamailio`:

```text
9900 -> Answer -> Echo -> Hangup
```

The base image configuration contains no `9900` extension. The optional mount lets the same image consume local overlay proof fixtures without introducing a production dialplan route.

## Kamailio Application-Dialog Seam

The existing REGISTER branch remains in place and still uses the canonical subscriber authentication view and registrar save operation.

Non-REGISTER behavior is now explicit:

- `INVITE`, `ACK`, `CANCEL`, `BYE`, and `UPDATE` enter `route[APPLICATION_DIALOG]`.
- Initial out-of-dialog `INVITE` reuses `www_authorize("$fd", "kamailio_signaling_auth_view")` and the existing `$au != $fU` identity guard.
- Accepted initial INVITEs call `record_route()` and relay statefully to the canonical Asterisk SIP Service DNS.
- Sequential requests use `route[WITHINDLG]` and `loose_route()`.
- `ACK` and `CANCEL` use the existing transaction authority where present.
- Unsupported methods still receive `405 Method Not Allowed`.

No rtpengine operation or SDP rewrite exists in this slice.

## Asterisk-Unavailable Contract

If Kamailio cannot relay a new application-dialog INVITE to the canonical Asterisk Service, it logs non-sensitive transaction correlation and returns:

```text
503 Application Runtime Unavailable
```

No fallback Asterisk target, Pod IP, ARI route, rtpengine destination, dispatcher pool, or direct-media bypass was introduced.

## Reciprocal NetworkPolicies

The security overlay now contains the exact reciprocal SIP corridor:

- Kamailio source egress from the existing Kamailio signaling policy to `utcp-runtime` Pods with `app.kubernetes.io/component: asterisk-ari`, UDP `5060`.
- Asterisk destination ingress in `utcp-runtime` selecting Asterisk ARI Pods and accepting only sources from `utcp-platform` Pods with `utcp.io/network-role: kamailio-signaling`, UDP `5060`.

Existing DNS, PostgreSQL, rtpengine control, ARI, observability, and default-deny policies remain active. No namespace-wide SIP rule, wildcard UDP, `ipBlock`, public SIP route, media UDP egress, HostPort, NodePort, or LoadBalancer was added.

## Static and Mutation Checks

`scripts/kamailio-signaling/config-check` now validates the T3-S2A Asterisk/Kamailio seam offline:

- explicit PJSIP module availability contract under `autoload = no`
- exactly one UDP `5060` PJSIP transport
- one Kamailio-facing endpoint with `context=from-kamailio`, `direct_media=no`, `disallow=all`, and `allow=ulaw`
- base dialplan excludes the local proof extension
- local overlay contains exactly one `9900` fixture
- dedicated ClusterIP SIP Service and unchanged ARI Service
- no SIP HostPort or public surface
- Kamailio REGISTER preservation
- authenticated application INVITE route
- Record-Route and loose-route handling
- ACK, CANCEL, BYE, and unsupported-method handling
- canonical Asterisk Service DNS with no IP literal or second target
- explicit `503 Application Runtime Unavailable`
- absence of all rtpengine operations
- exact reciprocal Kamailio/Asterisk SIP NetworkPolicies

`scripts/kamailio-signaling/config-check-test` adds focused mutation coverage for missing modules, `autoload=yes`, missing or widened SIP policies, bad Service types, HostPort, incorrect service DNS, missing authentication, missing Record-Route, missing loose routing, missing ACK/CANCEL/BYE handling, missing `503`, second Asterisk target, forbidden rtpengine operations, and leakage of the local fixture into base configuration.

## Validation

Focused repository validation performed:

```text
make kamailio-signaling-config-check
make kamailio-signaling-config-check-test
make k8s-config-check
make security-config-check
```

Asterisk disposable configuration smoke:

- built local image `utcp-asterisk-ari:t3-s2a` from the existing pinned Asterisk base image
- `utcp-asterisk-readiness` returned `asterisk_readiness=ok`
- `pjsip show transports` reported one `transport-udp-internal` UDP transport bound to `0.0.0.0:5060`
- `pjsip show endpoint anonymous` reported `context=from-kamailio`, `direct_media=false`, `allow=(ulaw)`, and `transport=transport-udp-internal`
- `module show like pjsip` reported `chan_pjsip`, `res_pjsip`, `res_pjsip_endpoint_identifier_anonymous`, `res_pjsip_pubsub`, `res_pjsip_sdp_rtp`, and `res_pjsip_session` running
- `module show like res_rtp_asterisk` reported `res_rtp_asterisk` running
- `dialplan show from-kamailio` reported the local-only `9900` fixture plus the base rejection path

Kamailio disposable configuration validation:

- rendered `kamailio.cfg` with sanitized placeholder credentials
- ran the pinned `ghcr.io/kamailio/kamailio:5.8.6-bookworm` image with `kamailio -c -f /tmp/kamailio.cfg`
- Kamailio loaded the route configuration and initialized the declared modules, including `rr`, without syntax failure
- the disposable syntax-check container was removed

Full repository verification is recorded in the final task report and commit context.

## Deferred Live Proof

Claude Code deploys only the T3-S2A Asterisk SIP and Kamailio signaling resources and proves the authenticated application-dialog corridor, internal Service resolution, Record-Route, sequential routing, local media fixture, explicit Asterisk-unavailable failure, reciprocal policy enforcement and environment preservation. Do not add rtpengine mediation during that proof.
