# T3-S3A — External Media Projection

## Status

Starting commit: `9b253f0` (`docs(t3): define external media edge architecture`).

T3-S3A is implemented in the repository and awaits cluster recreation and
live validation. No Kubernetes resource was applied, the local k3d cluster was
not recreated, and no browser or media scenario was run.

## Authority and projections

`UTCP_PUBLIC_MEDIA_ADDRESS` is declared once in `versions.env` and is set to
`127.0.0.1` for the local-host-browser projection. The default local overlay
does not consume it. The dedicated `local-media-edge` overlay derives the
runtime environment projection from the canonical versions file, adds the
`rtpengine-media` UDP NodePort Service, and pins rtpengine to the stable
`utcp.dev/media-edge=true` node label.

The media range remains exclusively `RTPENGINE_MEDIA_PORT_MIN/MAX`, rendered
as UDP `40000-40099`. Every Service entry has equal `port`, `targetPort`, and
`nodePort`; `externalTrafficPolicy` is `Local`. The rtpengine entrypoint keeps
the internal bind at `POD_IP` and uses the public address only as the
advertised interface half when the dedicated projection injects it. Invalid
injected values fail startup.

## k3d projection

`cluster-media-edge.yaml` inherits the canonical 80/443, API, registry, k3s,
server, and agent settings, then adds only loopback UDP
`127.0.0.1:40000-40099` on `server:0`, the
`service-node-port-range=30000-40099` API argument, and the media-edge node
label. The default `cluster.yaml` continues to reject media publication.
The profile requires cluster recreation before T3-S3B.

## Validation

The offline validator compares the canonical local and media-edge renders,
checks resolved images and generated-resource integrity, verifies the bounded
overlay delta, confirms exactly one selected public projection, and rejects
public non-media surfaces, host networking, host ports, Pod-CIDR routes, and
range drift. The k3d guard has a profile-scoped allow for only the declared
media publication, API range extension, and node label; the default guard is
unchanged in effect.

The mutation suite covers missing or duplicated authority, hard-coded or
invalid advertised addresses, range and port-count drift, TCP or wildcard
publication, control/metrics/runtime-range exposure, NodePort mismatches,
missing local traffic policy, node-label drift, host-networking/host-port
changes, default-overlay injection, and parity-resource removal.

The validator reports:

```text
media-edge projection configured and internally consistent
external media reachability awaiting T3-S3B
```

Actual host-browser UDP reachability, candidate correctness, RTP/audio energy,
cleanup, and failure behavior remain T3-S3B proof obligations.
