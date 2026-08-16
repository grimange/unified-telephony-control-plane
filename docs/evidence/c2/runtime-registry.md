# C2 Evidence: Runtime Registry and Runtime-Node Management

## Scope

C2 adds PostgreSQL-authoritative tenant-scoped `RuntimeNode` management with normalized endpoints, encrypted write-only credentials, declared runtime capabilities, C1 authorization, and C0 audit/idempotency/outbox integration.

No runtime command execution, command worker, event listener, ARI connection, ESL connection, health reconciliation worker, desired/observed reconciliation, simulator behavior, conference behavior, telephony session, SIP registration, Kamailio, rtpengine, Asterisk workload, FreeSWITCH workload, or V0 vertical slice is introduced.

## Implemented Authority

- Runtime nodes: PostgreSQL `runtime_nodes`
- Endpoints: PostgreSQL `runtime_node_endpoints`
- Credentials: PostgreSQL `runtime_node_credentials`, encrypted and write-only
- Declared runtime capabilities: PostgreSQL `runtime_node_capabilities` backed by code-owned catalog
- Authorization: C1 tenant capabilities
- Audit/idempotency/outbox: C0 kernel primitives

## Local Proof Commands

```text
make runtime-registry-config-check
make runtime-registry-test
make runtime-registry-api-proof
make runtime-registry-browser-proof
make runtime-registry-status
```

## Observed Local Proof

Record observed outcomes before final C2 signoff:

- `make runtime-registry-config-check`
- `make runtime-registry-test`
- `make runtime-registry-api-proof`
- `make runtime-registry-browser-proof`
- `make runtime-registry-status`
- `make k8s-apply`
- `make k8s-status`
- `make k8s-proof`
- `make gateway-proof`
- `make security-proof`
- `make observability-proof`

The browser proof must state that Playwright MCP was not available to Codex and was not claimed; repository Playwright automation was used instead.

## Credential-Secrecy Evidence To Record

Record sanitized outcomes for:

- plaintext accepted only through authorized write operations
- database value encrypted
- API responses exclude plaintext and ciphertext
- audit metadata excludes secret material
- outbox payload excludes secret material
- rotation increments version and retires the old active version
- same idempotency key replays safely
- conflicting idempotency key reuse is rejected
- unauthorized tenant/member cannot rotate or inspect metadata

Do not record proof secrets, ciphertext, raw fingerprints tied to user identities, cookies, CSRF tokens, passwords, or complete audit metadata.

## Hosted CI

Hosted execution is not observed until the workflow runs after commit and push.

## RNM-1 reconciliation (2026-08-08)

C2 remains complete as the canonical PostgreSQL RuntimeNode registry authority
defined by ADR-015; RNM supplies the later product-level lifecycle semantics.
The RNM-1 focused verification executed against the current repository passed:

```text
cd apps/api && php artisan test --filter='(RuntimeRegistryTest|draining_runtime_is_cordoned|listener_ordinary_eligibility|restore_authorized_listener_node)'
29 tests, 361 assertions: PASS
```

That executed result covers terminal soft retirement, active-binding protection,
active-only placement, existing draining bindings, listener exclusion, and
identity mutation guards. No historical C2 proof outcome is inferred here.
