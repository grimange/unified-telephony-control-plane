# UI-D5 — Reverb Origin and Redis Policy Fix

Verdict: `UI_D_REVERB_ORIGIN_REDIS_POLICY_FIX_COMPLETE`

Starting commit: `ee0d280` (`docs(ui): record runtime node realtime transport proof`).
Phase marker: `UTCP_PHASE=T1` unchanged.

This is a repository-only configuration and infrastructure-policy correction. The UI-D4 natural Playwright
MCP proof was not repeated.

## Blocked Live Behavior

UI-D4 proved the public WSS transport corridor through Traefik `443` up to Laravel Reverb:

```text
wss://app.utcp.local.test/app/{key}
→ Traefik TLS listener on 443
→ gateway /app/ Upgrade proxy
→ Reverb ClusterIP Service:8080
→ HTTP 101 Switching Protocols
```

The proof then stopped because Reverb immediately returned `4009 Origin not allowed`, so no private-channel
subscription or `/api/broadcasting/auth` request could occur. Reverb also logged Redis connection failures
from the scaling pub/sub provider.

## Reverb Origin Root Cause

The deployed local value was:

```text
REVERB_ALLOWED_ORIGIN=https://app.utcp.local.test
```

Laravel Reverb validates against the parsed host from the request Origin header. For the browser origin:

```text
Origin: https://app.utcp.local.test
parse_url($origin, PHP_URL_HOST) → app.utcp.local.test
```

The configured pattern included a scheme, so it could not match the parsed hostname.

## Corrected Allowed Origin

The committed local Kubernetes and example values now use the host-only pattern:

```text
REVERB_ALLOWED_ORIGIN=app.utcp.local.test
```

The value is host-only: no scheme, path, port, wildcard, second public hostname, or permissive fallback.
Public browser authority remains `wss://app.utcp.local.test:443`; internal Reverb authority remains the
ClusterIP Service on `8080`.

## Fallback Derivation

`apps/api/config/reverb.php` no longer falls back to raw `APP_URL`. When `REVERB_ALLOWED_ORIGIN` is absent,
the config derives a hostname from `APP_URL` with `parse_url(..., PHP_URL_HOST)`.

For the canonical local application URL:

```text
APP_URL=https://app.utcp.local.test
REVERB_ALLOWED_ORIGIN absent
→ allowed_origins = [app.utcp.local.test]
```

Explicit `REVERB_ALLOWED_ORIGIN` remains authoritative. If `APP_URL` is malformed, the fallback is the
non-wildcard local hostname `localhost`.

## Redis Connectivity Root Cause

Reverb already had bounded egress to Redis for scaling pub/sub, but Redis ingress allowed only these platform
roles:

```text
api
worker
simulator-event-source
asterisk-ari-events
scheduler
migration
```

The `reverb` role was missing, so Redis rejected the Reverb workload's TCP connection to port `6379`.

## Redis Ingress Correction

`infrastructure/kubernetes/security/data/allow-redis.yaml` now permits:

```text
source namespace: utcp-platform
source role: reverb
destination role: redis
protocol: TCP
port: 6379
```

Existing approved Redis client roles remain intact. The policy still targets only Redis pods, does not permit
all namespaces or all Pods, opens no additional port, and does not expose Redis publicly.

## Preserved Boundaries

The migration overlay remains isolated from Reverb broadcasting:

```text
BROADCAST_CONNECTION=log
no utcp-local-reverb-credentials Secret
no REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET
```

Publisher workloads remain on Reverb:

```text
api
worker
control-plane-outbox-dispatcher
```

They retain `BROADCAST_CONNECTION=reverb` and the canonical Reverb credentials. The Reverb Deployment and
Service, gateway `/app/` routing, `/api/broadcasting/auth`, RuntimeNode broadcast envelope, outbox bridge,
and frontend Echo lifecycle were not changed.

## Regression Coverage

`apps/api/tests/Feature/Platform/ReverbRealtimeInfrastructureTest.php` now proves:

- rendered local platform config contains `REVERB_ALLOWED_ORIGIN=app.utcp.local.test`;
- committed local/example origin assignments are host-only and match the gateway hostname;
- `APP_URL=https://app.utcp.local.test` with no explicit origin derives `app.utcp.local.test`;
- explicit host-only `REVERB_ALLOWED_ORIGIN` overrides the fallback;
- malformed `APP_URL` falls back to the non-wildcard local hostname `localhost`;
- Redis ingress includes exactly the repository's `reverb` network role on TCP `6379`;
- the Redis policy does not permit all Pods or all namespaces and preserves existing approved roles;
- Reverb egress still permits Redis.

`scripts/kubernetes/config-check` now rejects rendered local overlays whose `REVERB_ALLOWED_ORIGIN` is not
`app.utcp.local.test` or contains a URL scheme, path, or `:443`.

`scripts/security/config-check` now rejects a Redis ingress policy that omits `reverb`, broadens to all Pods
or namespaces, or allows anything other than TCP `6379`.

## Remaining Live Proof

The only remaining proof gap is to re-run the existing UI-D4 natural Playwright MCP live proof from the
corrected commit.
