# Platform Membership Enrollment Authority — Canonical Publication and Deployment

Current-State-Impact: yes

Date: 2026-09-02

Task: `IDENTITY_PLATFORM_MEMBERSHIP_ENROLLMENT_AUTHORITY_CANONICAL_PUBLICATION_AND_DEPLOYMENT`

Status: IMPLEMENTED / REPOSITORY-TESTED / IMMUTABLY PUBLISHED / DEPLOYED; RMA-D authenticated-API acceptance ready to resume

## Source and validation

The synchronized source revision was `f3d3442b5e4d025425f9ba136f735a87d6d65d3e`,
with parent `62cef8a6d31c5ed83759e945e7fc1e4288251564`. The repository-native
authoritative API suite passed **713 tests, 9 skipped, and 5,681 assertions**.
The phase-status consistency check, mutation test, repository hygiene,
`git diff --check`, server configuration check, and image preflight all passed.

## Immutable publication and deployment

The `Native k3s Images` workflow completed successfully as run `33620657822`
for the repair revision. It produced lock artifact
`native-k3s-image-lock-f3d3442b5e4d025425f9ba136f735a87d6d65d3e` (artifact ID
`9842928374`). Immutable images:

```text
API:        ghcr.io/grimange/utcp-api@sha256:eb797a03053edc7bcb6d8fefa0701d84e34e1f61ba242e901316d80e1f5c1b3b
Asterisk:   ghcr.io/grimange/utcp-asterisk@sha256:1561e8e5d84376e395349c509fc944a26e82a60d94304bce33079a1eb730ca64
Web:        ghcr.io/grimange/utcp-web@sha256:31bc0d6e443f5590e45be5efc8eae6f7b9fc97fff6afbe7f38e0c5f3c642b010
Gateway:    ghcr.io/grimange/utcp-gateway@sha256:2b0f19ee0e22ecab350c13dc23c26a18885cb462c0176ae9ba980a1d72266d37
FreeSWITCH: ghcr.io/grimange/utcp-freeswitch@sha256:bad4fa6377d1b6319a6ee31103c17fe0f87b0a4b9343d9b65f823b971cf2ff83
RTPengine:  ghcr.io/grimange/utcp-rtpengine@sha256:3eb2b55dd5d4dc78ca72e3f7b39f1cb790962aa3f0a9d9ad9314f50e1318db00
```

The exact lock was promoted through the canonical image-sync target. Native
k3s deployment used `server-apply`, `server-status`, and `server-proof` on
context `default`, with `utcp-dev01` and `utcp-dev02` as the canonical nodes.
Current replicas converged on the new lock; historical zero-desired old
ReplicaSets/terminating Pods were not manually removed.

## Deployed identity authority

Migration job `utcp-migrate-l25pc` completed, and migration
`2026_09_03_102000_sync_identity_platform_membership_enrollment_authority`
reported `DONE` (batch 13). PostgreSQL confirms
`platform.memberships.manage` exists with `scope=platform` and is mapped to
`platform-admin`; the existing `platform.tenants.manage` remains distinct.
The new capability is not mapped to `tenant-admin` or `tenant-member`.

The Ready API Pod `api-5c687bdd75-kj22r` on `utcp-dev02` runs the published API
image ID `ghcr.io/grimange/utcp-api@sha256:eb797a03053edc7bcb6d8fefa0701d84e34e1f61ba242e901316d80e1f5c1b3b`,
with zero restarts. Current API-derived event and process roles, including
the membership-relevant application workers, are Ready on that same API
digest. The deployed controller contains the optional `tenant_id` request
field, explicit-tenant platform authorization without active tenant context,
the unchanged active-tenant path, and a target-aware idempotency fingerprint.

The existing naturally authenticable identity `admin@utcp.local.test` has an
active `platform-admin` role, so its effective platform capabilities include
`platform.memberships.manage`. This was a read-only readiness check; no tenant,
membership, or RMA-D archive-target data was created.

RMA-D remains unchanged, RMA-E remains not started, and no acceptance
transaction was run in this deployment packet. The next proof may resume the
unchanged focused RMA-D authenticated-API acceptance using the canonical
platform-admin identity to create a tenant, enroll an existing user into it,
and then exercise the existing tenant-context authority.
