# Runtime Registry Runbook

## Scope

C2 manages tenant-owned runtime-node configuration. It does not connect to Asterisk, FreeSWITCH, ARI, ESL, SIP, event WebSockets, or health endpoints.

## Authority

`RuntimeNode` is the only canonical execution-node registry entity. PostgreSQL stores node identity, tenant ownership, runtime family, adapter key, desired state, observed placeholder state, endpoints, encrypted credential metadata, declared runtime capabilities, placement metadata, audit records, idempotency records, and outbox events.

Redis is not registry authority. Kubernetes Pod discovery is not registry authority.

## Management Surface

Use the C1-authenticated web administration surface:

```text
/admin/runtime-nodes
```

API routes are under:

```text
/api/v1/admin/runtime-nodes
```

There are no Asterisk-specific, FreeSWITCH-specific, ARI, or ESL routes.

## Capabilities

C2 adds C1 authorization capabilities:

```text
runtime.nodes.view
runtime.nodes.manage
runtime.credentials.rotate
```

`tenant-admin` receives these tenant-scoped capabilities. `tenant-member` does not. Runtime capabilities such as `conference.execution` are node declarations, not user authorization capabilities.

## Desired And Observed State

Desired lifecycle states:

```text
draft
active
draining
disabled
```

Observed states in C2:

```text
unobserved
unknown
```

Do not mark a node healthy or ready in C2. C3 owns observation and reconciliation.

## Credentials

Credentials are encrypted with Laravel-supported application encryption. They are write-only:

- API responses do not return plaintext.
- API responses do not return ciphertext.
- Status output does not print secrets or fingerprints tied to user-facing identities.
- Audit metadata and outbox payloads exclude secret material.
- Rotation creates a new version and retires the previous active version for that credential type.

## Verification

Run:

```sh
make runtime-registry-config-check
make runtime-registry-test
make runtime-registry-api-proof
make runtime-registry-browser-proof
make runtime-registry-status
```

`runtime-registry-browser-proof` uses repository Playwright automation in a fresh Chromium context. It does not use Playwright MCP, injected cookies, preset sessions, database-created sessions, Redis-created sessions, or authentication bypasses.

## Kubernetes

C2 is applied through the existing K1 lifecycle:

```sh
make k8s-image-build
make k8s-image-push
make k8s-apply
make k8s-status
make k8s-proof
```

C2 adds no Deployment, Service, Gateway hostname, ServiceAccount permission, telephony NetworkPolicy, Asterisk workload, or FreeSWITCH workload.

## Troubleshooting

- `409 Active tenant context is required`: log in and select an active tenant.
- `403`: the current user lacks the required C1 runtime capability or the tenant/membership is suspended.
- `422`: runtime family, adapter key, desired transition, endpoint, or capability input failed validation.
- Credential cannot be retrieved: expected behavior. Rotate it through the UI/API.
- Observed state remains `unobserved`: expected in C2.
