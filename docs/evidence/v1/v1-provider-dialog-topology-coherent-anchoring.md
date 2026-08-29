# V1 Provider Dialog Topology-Coherent Anchoring

## Bounded implementation

This record covers the V1 Gap B repository correction at `feb6270`. It does
not claim the real-provider Gap B live proof is complete.

The native deployment previously required `UTCP_SERVER_PROVIDER_SIP_ADDRESS`
and `UTCP_SERVER_PROVIDER_SIP_PORT` unconditionally. That blocked the accepted
registration/NAT topology, which has no stable public SIP edge. In addition,
provider-side `record_route()` cannot advertise the cluster-internal provider
socket identity to an Internet provider because that can create an unusable
route set.

## Configuration contract

Native configuration now derives one deterministic identity state:

* **ABSENT** — both values are empty. Validation and rendering pass, the
  existing internal provider socket advertisement remains, and the provider
  egress route has no provider-facing `record_route()`.
* **COMPLETE** — both values are present. Existing ADR-031 validation remains
  fail-closed, the configured address and port are advertised, and the
  provider egress route inserts `record_route()`.
* **PARTIAL** — exactly one value is present. Validation fails explicitly and
  rendering does not proceed.

No product schema field, feature flag, topology mode switch, public-address
inference, or alternate management path was added.

## Preserved return authority

`dlg_manage()` remains in `route[RUNTIME_EXTERNAL_TRUNK]`. `route[WITHINDLG]`
still gives `loose_route()` first authority. For registration/NAT provider
BYEs without a Route header, the existing trusted known-dialog path remains
the fallback: active outbound provider-source projection, `is_known_dlg()`,
`dlg_set_ruri()`, `MEDIA_DELETE`, and `t_relay()`. Contact rewriting,
application Call/CallLeg lookup, X-UTCP dependency, authentication, CSeq,
CallerIdentity, media, placement, and termination authorities are unchanged.

## Verification

Focused repository checks cover complete, absent, partial, and invalid identity
values; stable and registration/NAT rendered provider routes; unchanged
runtime socket; and the existing fallback. The registration dialog test and
Kamailio signaling mutation suite remain required. Parser-backed validation of
the rendered base/local configurations is part of the signaling suite when
the pinned Kamailio image is available.

The stable-public-edge ADR-031 live acceptance remains
`DEFERRED_BY_ENVIRONMENT`, not abandoned. The next controlled proof must deploy
the exact committed registration/NAT rendering and exercise one fresh V1-A
provider BYE return.
