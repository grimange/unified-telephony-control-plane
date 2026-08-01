# T3-S2B Media Corridor and Prover Correction

Date: 2026-08-02

Starting commit: `80e6e2b` (`docs(t3): record incomplete in-cluster media proof`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2B_MEDIA_CORRIDOR_AND_PROVER_CORRECTION_COMPLETE`

## PRODUCT_DEFECT-15

The canonical Asterisk runtime listens on UDP `10000-20000`, while rtpengine
advertises and allocates media on UDP `40000-40099`. Kubernetes NetworkPolicy
ports constrain the destination port. The previous policy used rtpengine's
range for rtpengine-to-runtime egress and had no reciprocal runtime rules.

The repository now has four exact reciprocal rules: rtpengine egress and
runtime ingress on `10000-20000`, plus runtime egress and rtpengine ingress on
`40000-40099`. Each peer combines the namespace selector with the exact
selected `local-asterisk-ari` or `rtpengine-media` Pod selector. The secondary
runtime, namespace-wide peers, `ipBlock`, Pod CIDR, Service CIDR, all-UDP,
all-egress, and public media exposure remain excluded. Kamailio control `2223`,
metrics `2224`, SIP `5060`, DNS `53`, and both namespace default-deny policies
remain. `infrastructure/docker/asterisk/config/rtp.conf` is the explicit
source for the current reference-runtime RTP range, and
`scripts/media/config-check` cross-checks both rendered policy directions
against the canonical ranges.

## Prover Corrections

The prover imports the public local CA certificate into a temporary Chromium
trust store with `certutil`, preserving hostname verification, SNI, origin,
and WebSocket TLS checks. It uses no insecure browser or global TLS bypass.
The runner accepts `Complete=True` and `Failed=True`, collects Pod status,
exit code, structured output, and bounded logs before cleanup, and uses a
bounded timeout. The Job TTL exceeds the active deadline and collection
margin. `waitForMessage` returns the matched message and advances its cursor;
natural login waits for the hydrated interactable Vue form and authenticated
navigation; `max-bundle` is absent.

Scenario selection is explicit and fail-closed for exactly
`browser-originated-bye` and `runtime-originated-bye`, with Scenario B reaching
its runtime-hangup synchronization point. The pinned UID/GID `1000` image uses
`HOME=/home/ubuntu` with a matching writable `emptyDir`; the root filesystem
remains read-only and capabilities remain dropped.

Static, mutation, and focused checks cover the four media rules,
destination-port semantics, exact selectors, default-deny and existing
control/SIP/DNS rules, trusted TLS, terminal Job collection, message cursors,
hydration, bundle policy, scenarios, cleanup, RTP/audio assertions, HOME
storage, and absence from production/base renders. No Kubernetes resource was
applied and the live prover was not run.

## Status

`PRODUCT_DEFECT-15 = corrected in repository`.

`T3-S2B prover defects = corrected in repository`.

`T3-S2B = ready for Scenario A and Scenario B live reproof`.

`T3-S2C second-runtime parity = Not Started`.

`T3-S3 external media edge = Not Started`.

`T3 = In Progress`.

`UTCP_PHASE=T1`.

Remaining focused live proof: apply the committed media NetworkPolicies and
corrected proof overlay, then prove natural login, ICE, DTLS-SRTP,
browser-to-runtime RTP, runtime-to-browser echo, non-zero received audio
energy, and cleanup for both BYE directions.
