# Identity, Tenancy, and Authorization Runbook

## Scope

C1 provides PostgreSQL-authoritative users, tenants, memberships, built-in roles, capabilities, first-party sessions, active-tenant selection, password lifecycle, suspension behavior, and C0-backed audit records.

C1 does not provide public registration, OAuth/OIDC, MFA, SIP credentials, SIP REGISTER, telephony sessions, conferences, runtime nodes, provider nodes, simulator behavior, Asterisk, FreeSWITCH, Kamailio, rtpengine, ARI, or ESL.

## Authority Model

- PostgreSQL is canonical for identity, tenants, memberships, roles, capabilities, assignments, statuses, and audit records.
- Redis is transient session, rate-limit, queue, cache, and coordination storage only.
- The frontend renders server-computed capability projection; it never grants authority.
- API controllers and identity services enforce platform and tenant capabilities.
- Platform roles and tenant roles are separate scopes.

## Local Bootstrap

Run:

```sh
make identity-bootstrap-local
```

The target creates or reuses a local platform administrator and local tenant only when a platform administrator does not already exist. It writes proof credentials under ignored `.runtime/identity/` and does not print passwords during normal invocation.

Normal identity management after bootstrap is through the web-admin/API surface.

## Web Flow

1. Open `https://app.utcp.local.test/login`.
2. Log in with the local bootstrap identity.
3. Select an active tenant when required.
4. Manage tenants at `/admin/tenants`.
5. Manage users at `/admin/users`.
6. Manage memberships and tenant roles at `/admin/memberships`.
7. Log out through the application shell.

The local TLS CA is used for automated proof. Browser trust is not configured automatically.

## API Proof

Run:

```sh
make identity-api-proof
```

The proof uses the normal CSRF/session lifecycle through `https://app.utcp.local.test`:

- unauthenticated session lookup is rejected
- login succeeds with bootstrap credentials
- platform capabilities are projected
- active tenant selection is validated
- tenant capabilities are projected
- authorized mutation succeeds
- cross-tenant tenant selection is denied
- logout invalidates the session

The proof does not inject Redis sessions, database sessions, preset cookies, or browser storage.

## Browser Proof

Run:

```sh
make identity-browser-proof
```

The proof starts from `https://app.utcp.local.test/login` in a fresh Chromium context, uses the real visible login form, loads the normal session bootstrap API, creates or inspects tenants, users, memberships, and built-in tenant-role assignment through the web-admin UI, proves lower-privilege navigation denial, and logs out through the UI.

The proof uses Playwright in an isolated temporary project under `/tmp/utcp-c1-browser-proof`. It may install the pinned Playwright test package and Chromium browser locally for proof execution. It does not inject sessions, cookies, browser storage, or database records as a substitute for UI management.

## Status

Run:

```sh
make identity-status
```

The status output is intentionally non-sensitive. It reports migration presence, counts by status, catalog version, role/capability counts, and session count when available. It does not print emails, names, password hashes, temporary passwords, cookies, session IDs, audit metadata, or role assignments tied to users.

## Testing

Run:

```sh
make identity-config-check
make identity-test
make identity-browser-proof
make web-test
make web-typecheck
make web-build
```

`identity-config-check` verifies route inventory, catalog shape, no public registration/bootstrap endpoint, no JWT/localStorage authentication token, no telephony capabilities/routes, and no simulator/runtime identity scope.

## Suspension Behavior

- Suspended users cannot authenticate and active sessions are rejected on the next authenticated request.
- Suspended tenants cannot be selected as active tenant context.
- Suspended memberships produce no tenant capabilities and cannot select that tenant.
- Suspension actions are audited without secret values.

## Cleanup

C1 identity records are durable authority records. Proof-created records are suspended or left as local development proof data when hard deletion would undermine auditability. Temporary credential files remain under ignored `.runtime/identity/`.

## Troubleshooting

- `401` from `/api/v1/auth/session`: log in again; the session may have expired or been invalidated by password/status changes.
- `419` from mutation routes: refresh CSRF using `/api/v1/auth/csrf` and retry through the same cookie jar.
- `403` from admin routes: verify the active tenant and server-projected capability keys.
- Missing bootstrap credentials: rerun `make identity-bootstrap-local`.
- Secure cookie issues: use the canonical HTTPS edge, not plain HTTP, for browser/session proof.
