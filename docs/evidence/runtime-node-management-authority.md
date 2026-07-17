# RuntimeNode Management Authority Evidence

## Scope

This evidence note covers the repository implementation target for canonical RuntimeNode/PBX management authority completion. It does not claim final natural browser acceptance, T0 listener restart proof, Asterisk restart proof, authentication-failure proof, recovery proof, or T0 phase completion.

## Confirmed Authority Shape

- RuntimeNode remains the only canonical PBX-node management entity.
- Runtime families, adapter keys, capability metadata, endpoint requirements, credential requirements, and adapter-configuration availability are served by the backend management catalog.
- The web-admin UI renders catalog data from the backend and initializes capability editing from each node's persisted capabilities.
- Asterisk T0 RuntimeNodes are limited to `event.stream` and `runtime.observation`.
- Unsupported capabilities are rejected server-side.
- Generic adapter-configuration routes dispatch through registered handlers instead of controller-level adapter branches.
- Asterisk ARI settings are PostgreSQL-authoritative per RuntimeNode in `asterisk_ari_profiles`.
- Asterisk runtime components require the per-node profile and do not use global environment values as permanent configured-node authority.
- Credential retirement, runtime evidence, and audit history are exposed through scoped authenticated RuntimeNode APIs and UI panels.
- Runtime evidence and audit history are sanitized read-only views over existing PostgreSQL authorities.

## Proof-Script Relationship

Normal management authority:

```text
web-admin UI -> canonical RuntimeNode APIs
```

Automated proof:

```text
proof scripts -> same canonical RuntimeNode APIs
```

Proof scripts remain useful regression clients. They are not documented or implemented as an alternate management authority.

## Boundaries Preserved

- No ProviderNode, PBXServer, AsteriskNode, or TelephonyServer domain model was added.
- No credential reveal function was added.
- No direct ARI call was added to controllers or frontend code.
- No manual listener reconnect, reconciliation, projection, retry, mark-ready, or mark-healthy control was added.
- No runtime allowlist, environment feature gate, simulator fallback, ConfBridge behavior, SIP behavior, RTP/media behavior, T1 behavior, or T2 behavior was added.

## Pending Proof

Natural Playwright MCP lifecycle acceptance remains pending after this repository implementation task. T0 remains in progress and `UTCP_PHASE` remains `C5`.
