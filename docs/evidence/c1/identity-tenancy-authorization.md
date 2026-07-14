# C1 Evidence: Identity, Tenancy, and Authorization

## Scope

C1 adds PostgreSQL-authoritative users, tenants, memberships, built-in roles, capabilities, first-party sessions, active-tenant selection, password lifecycle, suspension behavior, C0 audit integration, and web-admin management.

No telephony session, SIP credential, SIP REGISTER, WSS signaling route, Kamailio, rtpengine, Asterisk, FreeSWITCH, ARI, ESL, conference, registration observation, runtime node, provider node, runtime adapter, telephony worker, telephony capability, or telephony NetworkPolicy is introduced.

## Implemented Authority

- Users: PostgreSQL `users`
- Tenants: PostgreSQL `tenants`
- Memberships: PostgreSQL `tenant_memberships`
- Capabilities: code-owned `config/identity.php` synchronized into PostgreSQL
- Built-in roles: `platform-admin`, `tenant-admin`, `tenant-member`
- Role assignments: scoped platform and tenant assignment tables
- Sessions: Laravel session authentication, with Redis used only as transient session storage in runtime
- Audit: C0 append-only audit records

## Local Proof Commands

```text
make identity-config-check
make identity-test
make identity-bootstrap-local
make identity-api-proof
make identity-browser-proof
make identity-status
make web-test
make web-typecheck
make web-build
```

## Observed Local Proof

Observed on 2026-07-14:

- `make identity-config-check`: passed
- `make identity-test`: passed
- `make identity-bootstrap-local`: passed; credentials remained under ignored `.runtime/identity/bootstrap.json`
- `make identity-api-proof`: passed; unauthenticated session rejection, CSRF login, capability projection, tenant selection, authorized mutation, cross-tenant denial, logout invalidation
- `make identity-browser-proof`: passed; real login page, visible login form, server session bootstrap, tenant/user/membership administration, built-in tenant-role assignment, lower-privilege navigation denial, UI logout
- `make web-test`: passed
- `make web-typecheck`: passed
- `make web-build`: passed
- Kubernetes `make k8s-apply`: passed; C1 migration Job completed and K1 workloads rolled out

The browser proof used Playwright with HTTPS error bypass for the local development certificate. Browser trust in the local CA was not claimed.

## Runtime Evidence To Record

Before final C1 signoff, record:

- `git status --short`
- `docker compose ls`
- `k3d cluster list`
- `kubectl config current-context`
- `make control-plane-status`
- `make k8s-status`
- `make gateway-status`
- `make security-status`
- `make observability-status`

Then record sanitized outcomes for:

- bootstrap credential file path, without printing the password
- unauthenticated session rejection
- login success through HTTPS and CSRF
- session identity and platform capabilities
- active tenant selection
- tenant capabilities
- authorized admin mutation
- cross-tenant denial
- logout invalidation
- browser login from `/login`
- web-admin tenant/user/membership workflow
- lower-privilege denial when proven
- C1 Kubernetes migration Job success
- final K1/K2/K3/K4 health

## Sensitive Data Policy

Do not include:

- passwords
- temporary passwords
- password hashes
- cookies
- session IDs
- CSRF tokens
- Authorization headers
- decoded Secret values
- complete user records
- full audit metadata

## Hosted CI

Hosted execution is not observed until the workflow runs after commit and push.
