# V0-C6 RTPengine managed RuntimeNode media policy fix

**Date:** 2026-08-15

**Status:** IMPLEMENTED AND TESTED; narrow media-and-Leave natural reproof pending.

## Blocker

The V0-C6 browser conference-leg proof established the signaling, Asterisk
entry, participant binding, and bridge-member invariants, but browser RTP did
not reach the bound managed Asterisk RuntimeNode. The RTPengine media policy
selected `utcp.dev/runtime-node: local-asterisk-ari` in both Asterisk media
directions. Managed RuntimeNodes use distinct values for that label and were
therefore excluded by default-deny policy.

## Bounded correction

The Asterisk ingress and egress peers in
`allow-rtpengine-media` now select the canonical workload identity:

```yaml
app.kubernetes.io/component: asterisk-ari
utcp.io/network-role: asterisk-ari
```

The `utcp-runtime` namespace selector and existing media ranges remain
unchanged:

* RuntimeNode to RTPengine: UDP `40000-40099`
* RTPengine to RuntimeNode: UDP `10000-20000`

The static per-node label was removed only from the Asterisk media peers.
FreeSWITCH, Kamailio, Prometheus, default-deny, and public-exposure policy
boundaries were not changed.

## Regression evidence

The security and media validators require the exact namespace plus canonical
Asterisk component/network-role selector in both directions. They model both a
historical base Asterisk label set and a managed RuntimeNode label set as
eligible, while an unrelated workload is excluded. Focused media regression
mutations fail when the static node pin is reintroduced, a canonical identity
label is removed, a peer is widened, or a media range changes.

No live apply or browser proof was performed in this repository packet. The
next step is the narrow natural media-and-Leave reproof against the canonical
environment.

## Preserved status

V0-C1 through the binder/bridge portion of V0-C4 and V0-C5 remain live proven.
This fixes the V0-C6 media-policy blocker in the repository; V0 remains
IN PROGRESS. RT-1 remains PLANNED and NOT IMPLEMENTED. The deferred bridge
recreation observation was not modified.
