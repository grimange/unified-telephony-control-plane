# RMA-D Archive Target Acceptance — Cross-Tenant Fixture Blocker

Current-State-Impact: yes

**Date:** 2026-09-02
**Environment:** native k3s, context `default`, namespace `utcp-platform`
**Verdict:** `RMA_D_ACCEPTANCE_PROOF_FIXTURE_BLOCKED_NO_SECOND_TENANT_MEMBERSHIP_CORRIDOR`

The deployed RMA-D authority passed every read-only prerequisite. The focused
authenticated-API acceptance was **stopped before any mutation** because the
required live cross-tenant negative boundary has no legitimate fixture and none
can be produced through canonical application authority.

No archive target, credential reference, tenant, user, membership, role, session,
schema, or runtime state was created or modified by this run.

## Repository and deployed baseline

`main` at `42a93754f032bec058480892767b9c6272ee0015`, matching `origin/main` and
remote `refs/heads/main`, worktree clean. Application implementation source is
`2da900300e7dbd4eae8f86bc0664da7dc480a1d4`.

Every UTCP API workload — `api`, `asterisk-ari-events`,
`control-plane-outbox-dispatcher`, `freeswitch-esl-events`,
`kamailio-registration-observer` (x2), `reverb`, `scheduler`,
`simulator-event-source`, `telephony-command-worker`,
`telephony-event-normalizer`, `telephony-reconciler`, `utcp-runtime-fence-worker`,
`worker`, and the completed `utcp-migrate` Job — runs
`ghcr.io/grimange/utcp-api@sha256:3715ab052ee1ffcfd447ff0ce4dc8782ca4193f6621f02a1dd65401c441c4601`.
No workload runs any other API digest; there is no mixed-version processing.

## Verified deployed prerequisites

**Schema.** `media_archive_targets` and `media_archive_credential_references`
both exist, with zero pre-existing rows.

```text
media_archive_targets:
  id, tenant_id, name, slug, description, target_kind, endpoint_url, region,
  bucket, object_prefix, desired_state, created_by, updated_by,
  created_at, updated_at

media_archive_credential_references:
  id, tenant_id, media_archive_target_id, identifier, encrypted_secret,
  secret_fingerprint, created_at, updated_at
```

The reconciled phase boundaries hold in the deployed schema: no `is_default`,
no `use_path_style` (RMA-E), and no `version`, `status`, `rotated_at`, or
`expires_at` (RMA-F).

**Capabilities.** `telephony.recording_archive_targets.view` and
`.manage` exist with `scope = tenant` and are both mapped to `tenant-admin`.

**Routes.** Exactly the six designed routes are live, and none of `/default`,
`DELETE` target, `/rotate`, `/test-connection`, or `/reconcile` exists:

```text
GET    /api/v1/admin/recording-archive-targets
POST   /api/v1/admin/recording-archive-targets
GET    /api/v1/admin/recording-archive-targets/{target}
PATCH  /api/v1/admin/recording-archive-targets/{target}
POST   /api/v1/admin/recording-archive-targets/{target}/desired-state
PUT    /api/v1/admin/recording-archive-targets/{target}/credential
```

**PayloadSafety integration.** The deployed narrow amendment is present:
`secret(?!_fingerprint)` and `credential(?!_reference_id)` negative lookaheads,
so `secret_fingerprint` and `credential_reference_id` survive redaction while
`secret` and other credential keys remain stripped.

**Natural authentication.** A real first-party login through
`GET /api/v1/auth/csrf` → `POST /api/v1/auth/login` →
`POST /api/v1/auth/tenant-context` succeeded. No session row, Redis entry,
injected cookie, forged header, or middleware bypass was used. The session
reported:

```text
user           8c32234a-3ba5-4c05-abdf-b11cd3c41dd9  (admin@utcp.local.test)
active tenant  342ee3b1-5b74-4964-8113-15030a61fda3  (slug "local")
capabilities   telephony.recording_archive_targets.view
               telephony.recording_archive_targets.manage
```

Both RMA-D capabilities are naturally present; none was granted for this run.

## The missing fixture

The acceptance requires a **real** cross-tenant denial: a target created under
tenant A must return `404` while the same session is legitimately active in a
different tenant B, and must be absent from tenant B's list. A nonexistent
random UUID is explicitly not acceptable as a substitute.

The deployed identity state provides no such corridor:

```text
tenants                = 1   (342ee3b1-…, slug "local")
tenant_memberships     = 1   (admin -> local, active)
tenant_role_assignments= 1   (tenant-admin on that membership)
```

The authenticated session's own `memberships` array contains exactly that one
entry, so there is no second tenant to switch into.

## Why canonical authority cannot produce it

1. `POST /api/v1/admin/tenants` creates a tenant but grants no membership in it.
2. `POST /api/v1/admin/memberships` binds to the **active** tenant —
   `AdminMembershipController::store()` derives `$tenantId = $this->tenantId($request)`
   and authorizes `tenant.memberships.manage` against it. There is no parameter,
   and no other route, that can enroll a user into a different tenant.
3. Consequently `POST /api/v1/auth/tenant-context` for a newly created tenant is
   denied. The repository asserts this itself: `scripts/identity/api-proof:74`
   requires exactly `403` for that selection.
4. `POST /api/v1/admin/users` creates a user with `password_change_required` and
   `temporary_password_displayed = false`, so no second identity can authenticate
   naturally either.

Every remaining route to a second-tenant context is explicitly forbidden by the
acceptance contract: fabricating session state, mutating `active_tenant_id`
directly, or creating a tenant or membership through SQL.

## Underlying product observation

This is not only a test-fixture gap. Under the current canonical API a tenant
created through `POST /api/v1/admin/tenants` is **permanently unjoinable**: no
route can add any user to it, so it can never acquire an administrator and can
never be used. Tenant creation and tenant membership are therefore not connected
by any canonical lifecycle.

## Smallest deterministic correction

Extend membership creation to accept an explicit target tenant, gated by
platform authority:

```text
POST /api/v1/admin/memberships
  body: user_id*, role_key*, tenant_id?   // new optional field

  tenant_id absent            -> unchanged behavior (active tenant)
  tenant_id == active tenant  -> unchanged behavior
  tenant_id != active tenant  -> require a platform-scoped capability
                                 (platform.tenants.manage), then enroll
```

This is a bounded identity-authority change: one request field, one
authorization branch, one service parameter, plus focused tests for the
unchanged default path, the platform-authorized cross-tenant path, and denial
without platform scope. It closes the unjoinable-tenant gap and simultaneously
makes this acceptance's cross-tenant corridor natural.

After that repair, this acceptance re-runs unchanged: create tenant B through the
canonical API, enroll the admin as `tenant-admin` in B, then exercise the full
RMA-D chain including the required live `404`.

## Boundaries not reached

Everything after the fixture check: fresh target creation, draft state,
activation-without-credential rejection, credential `PUT`, safe projection,
encrypted persistence, credential cardinality, activation, `GET`/list
persistence, cross-tenant `404`, owner-tenant recovery, audit and outbox
evidence, credential redaction, PayloadSafety live proof, retirement cleanup, and
the retired-terminal check. None was attempted, so no synthetic archive
destination or credential exists in the environment.
