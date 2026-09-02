# Platform Membership Enrollment Authority Repair

Current-State-Impact: yes

**Date:** 2026-09-02
**Task:** `IDENTITY_PLATFORM_MEMBERSHIP_ENROLLMENT_AUTHORITY_IMPLEMENTATION`
**Verdict:** `IDENTITY_PLATFORM_MEMBERSHIP_ENROLLMENT_AUTHORITY_IMPLEMENTED_AND_TESTED`

## Blocker and bounded correction

RMA-D's deployed authenticated-API acceptance was fixture-blocked because
`POST /api/v1/admin/tenants` created a tenant without membership and the
existing `POST /api/v1/admin/memberships` endpoint always selected the active
tenant. A newly created tenant was therefore canonically unjoinable.

The existing `IdentityAdminService::createMembership()` already accepted an
explicit target tenant and remained unchanged. The repair extends the existing
membership route with optional `tenant_id` and makes the request shape select
the authority mode deterministically:

* without `tenant_id`, the existing active-tenant path requires
  `tenant.memberships.manage` and retains its active-tenant context requirement;
* with `tenant_id`, the platform enrollment path requires
  `platform.memberships.manage`, does not require an active tenant context,
  validates that the target tenant exists, and creates the membership in that
  tenant.

`platform.tenants.manage` was not broadened. A dedicated platform-scoped
capability preserves least privilege and keeps tenant membership administration
distinct from tenant administration.

## Identity and safety behavior

The new `platform.memberships.manage` capability is scoped to `platform` and is
granted only to `platform-admin`. It is synchronized by the identity catalog
migration; tenant-admin and tenant-member do not receive it. Existing tenant
role-scope validation remains in `IdentityAdminService`, so platform enrollment
can assign only tenant-scoped roles.

The effective target tenant is included in the existing membership-create
idempotency fingerprint with `user_id` and `role_key`. Same-key replays for the
same target are deterministic, while a different target conflicts. Existing
tenant-scoped audit events retain the actual target tenant context through the
unchanged domain service.

## Proof

The repository-native API suite passed with **713 passed, 9 skipped, and 5681
assertions**. Focused identity coverage proves canonical tenant creation,
platform enrollment of an existing user without active tenant context,
tenant-context selection for the newly joined tenant, the unchanged active
tenant path, missing platform authority, platform-tenants capability
non-authority, unknown-tenant `404`, role scope, target-aware idempotency, and
catalog mapping.

Required repository checks also passed:

* `make phase-status-consistency-check`
* `make phase-status-consistency-check-test`
* `make repository-hygiene`
* `git diff --check`

The implementation is not yet published or deployed. RMA-D remains
fixture-blocked at the deployed runtime until this revision is published and
the unchanged focused authenticated-API acceptance is rerun. RMA-E remains
not started.
