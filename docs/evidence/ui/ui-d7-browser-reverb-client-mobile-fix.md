# UI-D7 Browser Reverb Client and Runtime Nodes Mobile Fix

Date: 2026-07-23

Starting commit: `f298ab6`

Objective: correct the bounded frontend defects recorded by `docs/evidence/ui/ui-d6-runtime-node-realtime-live-proof.md` without changing backend, Kubernetes, Reverb, gateway, channel authorization, broadcast event, or outbox behavior.

## Proven Server Corridor

UI-D6 already live-proved the server and infrastructure path:

```text
wss://app.utcp.local.test:443
→ Traefik
→ gateway /app/ Upgrade proxy
→ Reverb ClusterIP:8080
→ pusher:connection_established
```

UI-D6 also proved the UI-D5 origin and Redis corrections: Reverb accepted the browser Origin, Redis access from Reverb was open, migration stayed on `BROADCAST_CONNECTION=log`, publisher workloads stayed on Reverb, and Runtime Nodes initial navigation preserved the two-request, zero-detail-fan-out budget.

## Browser Zero-Socket Symptom

The Runtime Nodes page reached the realtime lifecycle but never opened a browser WebSocket:

```text
Playwright websocket events: 0
/app/ requests: 0
/api/broadcasting/auth POSTs: 0
in-page WebSocket constructions: 0
visible state: Live updates connecting
```

The visible connecting state and test-proven lifecycle established that the page had a valid session, tenant, canonical RuntimeNode snapshot, Echo client construction, and private-channel subscription attempt. The defect was isolated to production Pusher option construction.

## Production Client Option Root Cause

The previous production client passed:

```ts
enabledTransports: [config.wsScheme]
wsPath: config.wsPath
```

For the canonical deployment this became:

```ts
enabledTransports: ['wss']
wsPath: '/app'
```

`pusher-js` registers WebSocket strategy names as `ws` and `wss`. Filtering to only `wss` can disable the usable WebSocket strategy path. Separately, `wsPath: '/app'` is a path prefix while pusher-js appends `/app/{key}`, so it can construct `/app/app/{key}`.

## Corrected Transport Options

`apps/web/src/realtime/runtimeNodeRealtime.ts` now exposes and uses `buildRuntimeNodeEchoOptions(config)`.

The production options now keep the established public authority and use a valid WebSocket strategy:

```text
broadcaster: reverb
wsHost: app.utcp.local.test
wsPort: 443
wssPort: 443
forceTLS: true when scheme is wss
enabledTransports: ws, wss
authEndpoint: /api/broadcasting/auth
X-Requested-With: XMLHttpRequest
```

No polling transport, fallback host, fallback public port, second client, feature gate, or secret-bearing option was added.

## WebSocket Path Authority

The Echo option builder no longer emits `wsPath`. That lets pusher-js use its normal public Reverb route:

```text
/app/{public-application-key}
```

The corrected client cannot construct `/app/app/{key}`. The gateway `/app/` route remains unchanged and authoritative.

## Direct Production-Option Test Seam

Previously the frontend tests replaced the realtime client through `setRuntimeNodeRealtimeClientFactory(...)`, so the real Echo option object was not exercised.

The new pure builder is used by production and directly tested without constructing a network socket. Lifecycle tests still use the injected client factory for deterministic connection, authorization, notification, reconnect, tenant-switch, logout, and storage-boundary behavior.

## Mobile Overflow Root Cause

UI-D6 measured a 375 px Runtime Nodes page-level overflow:

```text
document.documentElement.scrollWidth: 406
window.innerWidth: 375
overflow: 31 px
```

The live-update badge was visible and readable, but it was added to the shared `.section-heading` row. That row inherited horizontal flex layout and `justify-content: space-between` while lacking `min-width: 0`, wrapping, and a narrow breakpoint stacking rule. The heading, live badge, and refresh action could therefore exceed the viewport.

## Responsive Correction

`apps/web/src/style.css` now gives `.section-heading` bounded wrapping behavior:

```text
min-width: 0
flex-wrap: wrap
children max-width: 100%
live-updates badge white-space: normal
live-updates badge overflow-wrap: anywhere
```

At the existing 720 px breakpoint, `.section-heading` joins `.topbar` and `.data-row` in the vertical column rule. No root-level `overflow-x: hidden` or duplicate mobile markup was added.

## Regression Coverage

Frontend tests now prove:

* the production option builder emits host `app.utcp.local.test`, port `443`, `forceTLS: true`, `/api/broadcasting/auth`, `X-Requested-With`, and `enabledTransports: ['ws', 'wss']`;
* no `wsPath` option is emitted, preventing `/app/app/{key}`;
* no Reverb secret, polling transport, fallback public port, or fake socket path appears in the option object;
* missing required transport coordinates preserve the canonical RuntimeNode snapshot and render the disconnected/stale state without subscribing;
* Runtime Nodes still loads catalog and list only before subscription and keeps zero initial detail fan-out;
* the Runtime Nodes heading contains the live badge and refresh action in the rendered view;
* CSS preserves the Users mobile overflow correction, forbids root overflow clipping, and adds the Runtime Nodes heading/live-badge wrapping contract.

`scripts/check-repository-hygiene` now also enforces the Runtime Nodes heading and live-update badge narrow-layout contract.

## Preserved Boundaries

No backend production code, Kubernetes manifest, NetworkPolicy, Reverb configuration, gateway route, Traefik authority, RuntimeNode event contract, outbox bridge, channel authorization, migration configuration, frontend dependency, or phase marker was changed.

Public browser authority remains:

```text
wss://app.utcp.local.test:443
```

Internal Reverb authority remains:

```text
reverb ClusterIP Service:8080
```

## Remaining Live Behavioral Proof

UI-D remains In Progress. The remaining proof is to re-run the UI-D6 natural Playwright MCP live behavioral proof from this corrected frontend commit, explicitly restarting all six Deployments when mutable image tags are reused.
