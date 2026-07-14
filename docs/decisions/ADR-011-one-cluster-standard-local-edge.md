# ADR-011: One-Cluster Standard Local Edge

## Status

Accepted

## Context

The first K2 implementation used custom host ports because APNTalk was running on localhost `80` and `443`. The operator has now intentionally stopped the unrelated APNTalk k3d cluster so UTCP can use the natural local web ports.

Only one local application k3d cluster can own `127.0.0.1:80` and `127.0.0.1:443` at a time. UTCP must not control APNTalk to obtain those ports.

## Decision

When UTCP is active, `utcp-local` maps:

- `127.0.0.1:80 -> 80/tcp` on the k3d load balancer.
- `127.0.0.1:443 -> 443/tcp` on the k3d load balancer.

Cluster switching is explicit operator action. UTCP scripts check port ownership and fail clearly if another process owns `80` or `443`; they never stop, start, delete, recreate, or mutate `apntalk-local`.

Traefik remains internally configured on entryPoint and container ports `80` and `443`. Gateway listeners use `80` and `443`.

K2 routes only:

- `app.utcp.local.test`
- `utcp.local.test`

The following names remain reserved and unrouted until their implementation phases:

- `sip.utcp.local.test`
- `events.utcp.local.test`

The long-term external edge uses one public HTTPS/WSS port:

```text
443/TCP
└── Traefik
    ├── app.utcp.local.test
    │   └── HTTPS -> UTCP web/API
    ├── sip.utcp.local.test
    │   └── WSS -> Kamailio WebSocket listener
    └── events.utcp.local.test
        └── WSS -> Reverb/application events
```

Traefik routes HTTPS and future WebSocket connections by hostname and route. Kamailio remains the SIP signaling authority, rtpengine remains the media relay authority, Asterisk and FreeSWITCH remain execution runtimes, and Reverb remains notification transport rather than canonical state.

## Consequences

- The canonical K2 URL is `https://app.utcp.local.test/`.
- HTTP redirects do not include custom ports.
- `18080` and `18443` are removed from active configuration and are not fallback or compatibility ports.
- Native SIP/TLS for non-browser devices is a separate future concern and is not implemented by this decision.
- A future shared host-level edge would require a separate ADR.
