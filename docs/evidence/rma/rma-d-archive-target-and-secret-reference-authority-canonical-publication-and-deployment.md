# RMA-D Archive Target and Secret-Reference Authority — Canonical Publication and Deployment

Current-State-Impact: yes

Date: 2026-09-02

Status: IMPLEMENTED / REPOSITORY-TESTED / IMMUTABLY PUBLISHED / DEPLOYED; focused authenticated-API acceptance pending

## Source and validation

The RMA-D application source was published from commit
`2da900300e7dbd4eae8f86bc0664da7dc480a1d4`, parent
`aa97e4df676edb37237ec6aa80c8f68bd28aa7b5`. A disposable repository-native
PostgreSQL proof completed migration `UP → DOWN → UP`; both RMA-D tables were
created, rolled back in dependency order, and recreated. The authoritative API
suite passed 707 tests, skipped 9, with 5,659 assertions. Repository gates,
configuration checks, image preflight, hygiene, phase-status checks, and diff
validation passed. Host-local `make api-check` remains unavailable because the
checkout lacks `apps/api/vendor/autoload.php`; the containerized suite is the
authoritative API proof.

## Immutable publication and deployment

The `Native k3s Images` workflow completed successfully as run `33616296903`
for source commit `2da900300e7dbd4eae8f86bc0664da7dc480a1d4`. It produced lock
artifact `native-k3s-image-lock-2da900300e7dbd4eae8f86bc0664da7dc480a1d4`
(artifact ID `9841259459`). Immutable images:

```text
API:        ghcr.io/grimange/utcp-api@sha256:3715ab052ee1ffcfd447ff0ce4dc8782ca4193f6621f02a1dd65401c441c4601
Asterisk:   ghcr.io/grimange/utcp-asterisk@sha256:cedfa6455fe69db02be26c2cead09e647c8dca2ac5baaa894c7858cb40808916
Web:        ghcr.io/grimange/utcp-web@sha256:a4af8d28fc569c5ec4257c73ee375770d1a3b8627892110799e14706f92ac2d0
Gateway:    ghcr.io/grimange/utcp-gateway@sha256:49941f44e7fea31b66fbad7bf06f8ecb9afa6c985a0861d85d409b84e8887b5c
FreeSWITCH: ghcr.io/grimange/utcp-freeswitch@sha256:af86673c71ac0cdf0af19a7b1895aca8886e19c4b1c5da25037b49ffea1aa43a
RTPengine:  ghcr.io/grimange/utcp-rtpengine@sha256:423032101ec8e1e10760ac3385d434c4cea327c23bbf91053e30d240966cbab0
```

The exact lock was promoted with `GH_REPO=grimange/unified-telephony-control-plane make server-image-sync`, then passed image preflight. Native k3s deployment used the canonical `server-apply`, `server-status`, and `server-proof` path on context `default`, with `utcp-dev01` and `utcp-dev02` as canonical nodes. Current authoritative replicas converged; older digest Pods were terminating rollout residue and were not manually removed.

## Migration and deployed authority

The canonical migration job completed:

```text
2026_09_03_100000_create_media_archive_authority
2026_09_03_101000_sync_rma_d_identity_catalog
```

PostgreSQL inspection confirmed both RMA-D tables, approved columns, foreign
keys, tenant/slug and one-credential-per-target uniqueness, lifecycle/endpoint
checks, and the tenant/state index. `call_legs.recording_ref` is absent. The
tenant-scoped capabilities `telephony.recording_archive_targets.view` and
`telephony.recording_archive_targets.manage` are both mapped to `tenant-admin`.

The deployed source exposes exactly the six approved authenticated API routes
for listing, creating, showing, patching, desired-state changes, and whole
credential replacement. It contains `MediaArchiveTargetService`,
`AdminMediaArchiveTargetController`, and the `Crypt::encryptString` plus
SHA-256 credential security path. No archive target or credential business data
was created for deployment proof.

## Runtime baseline and boundaries

Ready API Pod `api-668c666449-4nx6w` on `utcp-dev01` runs image ID
`ghcr.io/grimange/utcp-api@sha256:3715ab052ee1ffcfd447ff0ce4dc8782ca4193f6621f02a1dd65401c441c4601`,
with zero restarts. Maintained Ready Asterisk Pod
`asterisk-ari-865857d55b-h5hhq` on `utcp-dev02` runs image ID
`ghcr.io/grimange/utcp-asterisk@sha256:cedfa6455fe69db02be26c2cead09e647c8dca2ac5baaa894c7858cb40808916`,
with zero restarts. API-derived authoritative process roles use the published
API digest.

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` remains `active`/`ready`,
configuration versions are both `33`, desired and observed execution images
match the published Asterisk digest, and capability `recording` is present.
FreeSWITCH remains without `recording`. `is_default`, `use_path_style`,
ArchiveTransfer/storage execution, credential versioning and rotation, and
RMA-G retention/deletion authority remain absent. No storage connection or
authenticated RMA-D acceptance was performed.

The remaining proof is the focused deployed authenticated-API acceptance:
create a draft target, write and safely project a credential reference, activate
the target, and prove tenant authorization and audit/outbox behavior without a
real storage credential.
