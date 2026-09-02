# RMA-D Archive Target and Secret-Reference Authority Focused Deployed Authenticated-API Acceptance — PASSED

Current-State-Impact: yes

**Date:** 2026-09-02
**Environment:** native k3s, context `default`, namespace `utcp-platform`
**Verdict:** `RMA_D_ARCHIVE_TARGET_AND_SECRET_REFERENCE_AUTHORITY_FOCUSED_DEPLOYED_AUTHENTICATED_API_ACCEPTANCE_PASSED`

The previously fixture-blocked acceptance ran to completion on the repaired
deployed revision. The full canonical chain is proven:

```text
platform-admin authentication → create tenant B → explicit platform membership
enrollment → normal tenant B selection → return to tenant A → RMA-D draft target
→ encrypted write-only credential → active target → real cross-tenant 404 →
owner recovery → audit/outbox/redaction → retired target
```

No storage provider participated. No production source, migration, deployment, or
identity configuration was modified.

## Repository and deployed baseline

`main` at `8ea1c88677e9875f74bfe673ef770edb5ed6248f`, matching `origin/main` and
remote `refs/heads/main`, worktree clean. RMA-D implementation source is
`2da900300e7dbd4eae8f86bc0664da7dc480a1d4`; the identity fixture repair is
`f3d3442b5e4d025425f9ba136f735a87d6d65d3e`.

All fifteen UTCP API workloads — `api`, `asterisk-ari-events`,
`control-plane-outbox-dispatcher`, `freeswitch-esl-events`, both
`kamailio-registration-observer`, `reverb`, `scheduler`,
`simulator-event-source`, `telephony-command-worker`,
`telephony-event-normalizer`, `telephony-reconciler`,
`utcp-runtime-fence-worker`, `worker`, and the completed `utcp-migrate` Job — run
`ghcr.io/grimange/utcp-api@sha256:eb797a03053edc7bcb6d8fefa0701d84e34e1f61ba242e901316d80e1f5c1b3b`.
No workload runs any other API digest.

**Identity repair baseline.** `platform.memberships.manage` exists with
`scope = platform`, mapped **only** to `platform-admin`; neither `tenant-admin`
nor `tenant-member` holds it. `platform.tenants.manage` remains a separate
platform capability and is not used as membership authority — the deployed
`AdminMembershipController::store()` requires `platform.memberships.manage`
specifically when an explicit `tenant_id` is supplied.

**RMA-D baseline.** Both tables deployed with zero pre-existing rows; both RMA-D
capabilities mapped to `tenant-admin`; exactly the six designed routes live, with
no `/default`, `DELETE`, `/rotate`, `/test-connection`, or `/reconcile`.

## Acceptance identity and fixture

| Item | Value |
| --- | --- |
| User `U` | `8c32234a-3ba5-4c05-abdf-b11cd3c41dd9` (`admin@utcp.local.test`) |
| Owner tenant `A` | `342ee3b1-5b74-4964-8113-15030a61fda3` (`local`) |
| Foreign tenant `B` | `0e2599f1-c9b5-4341-b463-aba0c848f43b` (`rma-d-accept-1788347456`) |
| Membership `M` | `b747ed8c-cfea-4fd7-856b-128c043f1e4e` |
| Target `T` | `ebb0d7b7-d2a0-4d27-8a3e-8100a7b4145b` |
| Credential reference `C` | `0db612d1-5496-4cd3-a570-a4a6c29be10f` |
| Fingerprint `F` | `31832e68eab853f3f49d443f465641d6871ea5c4a0a063bf08578fa632ddf63b` |
| Credential identifier | `AKIAACCEPTANCE1788347456` |

Authentication was the ordinary first-party sequence `GET /api/v1/auth/csrf` →
`POST /api/v1/auth/login` (200) → `POST /api/v1/auth/tenant-context` (200) using
the application's own cookie jar. No session row, Redis entry, injected cookie,
forged header, manual `active_tenant_id` change, or middleware bypass was used.
The resulting context naturally carried `platform.memberships.manage`,
`telephony.recording_archive_targets.view`, and
`telephony.recording_archive_targets.manage`. Nothing was granted for this run.

## Cross-tenant fixture through canonical authority

`POST /api/v1/admin/tenants` → `201`, creating tenant `B`.

`POST /api/v1/admin/memberships` with an explicit
`{"tenant_id": B, "user_id": U, "role_key": "tenant-admin"}` → `201`, returning
`membership_id = M`. Canonical persistence confirms `tenant_id = B`,
`user_id = U`, `status = active`, and a `tenant-admin` role assignment on `M`.
No second user was created and no SQL was used.

`POST /api/v1/auth/tenant-context` for `B` → `200`, then back to `A` → `200`.
The previously unjoinable-tenant blocker is closed: a canonically created tenant
is now canonically joinable and selectable.

## RMA-D lifecycle

| Step | Result |
| --- | --- |
| `POST /recording-archive-targets` | `201`, `desired_state=draft`, `credential_reference=null` |
| Persistence | `tenant_id = A`, `desired_state = draft`, credential count `0` |
| `POST /{T}/desired-state active` (pre-credential) | **`422`** — `An archive target requires a credential reference before activation.`; state stays `draft`, credential count stays `0` |
| `PUT /{T}/credential` | `200`, credential reference `C` created |
| `POST /{T}/desired-state active` | `200`, `desired_state=active`, `credential_reference.id = C` |
| `GET /{T}` (owner) | `200`, active, correct identifier and fingerprint |
| `GET /` (owner list) | `T` appears exactly once |

Created target configuration: `target_kind=s3_compatible`,
`endpoint_url=https://archive.acceptance.invalid`, `region=us-east-1`,
`bucket=utcp-rma-d-acceptance`, `object_prefix=acceptance/`. The endpoint is a
reserved-TLD synthetic value; it was never contacted.

## Credential security

Safe API projection contained exactly
`credential_reference.{id, identifier, secret_fingerprint}` and **none** of
`secret`, `encrypted_secret`, `version`, `status`, `rotated_at`, or `expires_at`.

Canonical persistence for row `C`:

```text
tenant_id  = A                     TENANT_IS_A=YES
target     = T                     TARGET_IS_T=YES
identifier = AKIAACCEPTANCE1788347456
fingerprint= F                     FINGERPRINT_EQUALS_EXPECTED=PASS
                                   FINGERPRINT_MATCHES_SHA256=PASS
ENCRYPTED_NOT_CLEARTEXT              = PASS
ENCRYPTED_DOES_NOT_CONTAIN_CLEARTEXT = PASS
DECRYPTION_MATCH                     = PASS
CRED_COUNT_FOR_T                     = 1
```

The decrypt assertion ran once in-process and reported only PASS; the cleartext
was never emitted. The deployed credential columns are exactly
`id, tenant_id, media_archive_target_id, identifier, encrypted_secret,
secret_fingerprint, created_at, updated_at`.

## Cross-tenant isolation

While the same session was legitimately active in tenant `B`:

```text
GET /api/v1/admin/recording-archive-targets/T   -> 404
GET /api/v1/admin/recording-archive-targets     -> T absent (list total 0)
```

`T` is the **real** tenant-A target, not a fabricated identifier. Switching back
to tenant `A` restored `GET /{T}` → `200` with `desired_state=active`, proving
the `404` was tenant isolation rather than object disappearance.

## Audit and outbox

Four audit records and four outbox messages, all
`aggregate_type = media_archive_authority`, `aggregate_id = T`:

```text
11:10:58  media_archive_target.created        {target_id, slug, target_kind, bucket, endpoint_url}
11:10:59  media_archive_target.credential_set {target_id, credential_reference_id, identifier,
                                               secret_fingerprint, replaced:false}
11:11:00  media_archive_target.state_changed  {target_id, from:draft,  to:active}
11:11:02  media_archive_target.state_changed  {target_id, from:active, to:retired}
```

`created` and `credential_set` each appear exactly once. The rejected
pre-credential activation produced no event, consistent with the existing
architecture, which does not audit rejected attempts.

## Redaction and PayloadSafety live proof

```text
CLEARTEXT_IN_AUDIT                = NONE-PASS
CLEARTEXT_IN_OUTBOX               = NONE-PASS
ENCRYPTED_STRING_IN_EVIDENCE      = NONE-PASS
FINGERPRINT_SURVIVES              = PASS   (audit and outbox)
CREDENTIAL_REFERENCE_ID_SURVIVES  = PASS   (audit and outbox)
```

An exact cleartext search across every acceptance capture (API responses,
orchestrator log, verification output) and the entire repository returned **zero**
occurrences. The ephemeral secret file was shredded and the pod copy removed.

This closes the PayloadSafety integration live: the deployed
`secret(?!_fingerprint)` and `credential(?!_reference_id)` negative lookaheads let
`secret_fingerprint` and `credential_reference_id` reach durable evidence while
raw secret material never does.

## Boundaries

**No storage interaction.** No S3, MinIO, `HEAD`, bucket creation, upload,
download, test-connection, or provider credential validation occurred. `active`
denotes configured and credentialed desired state only, never storage readiness.

**RMA-E.** Deployed target columns are exactly
`id, tenant_id, name, slug, description, target_kind, endpoint_url, region,
bucket, object_prefix, desired_state, created_by, updated_by, created_at,
updated_at` — no `is_default`, no `use_path_style`, no object key, storage URI,
checksum, size, or observed health. The only archive-related tables in the
database are `media_archive_targets` and `media_archive_credential_references`;
no `ArchiveTransfer` table exists. `recording_artifacts` is unchanged and carries
no archive column.

**RMA-F.** Credential count for `T` is `1`, and the credential row has no
`version`, `status`, `rotated_at`, or `expires_at`. No rotation, replacement, or
cutover was exercised.

**RMA-G.** No retention, `delete_after`, legal hold, purge, object deletion, or
archive expiration exists or was exercised.

## Cleanup and fixture accounting

`POST /{T}/desired-state retired` → `200`, `desired_state = retired`. A single
bounded `retired → active` attempt returned **`422`**
(`A retired archive target cannot be reactivated.`) and `GET /{T}` confirmed
`desired_state = retired`. No SQL deletion was performed.

Canonical fixture residue, disclosed in full:

```text
Foreign tenant B      0e2599f1-c9b5-4341-b463-aba0c848f43b   retained
Membership M          b747ed8c-cfea-4fd7-856b-128c043f1e4e   retained (U as tenant-admin in B)
Target T              ebb0d7b7-d2a0-4d27-8a3e-8100a7b4145b   retired
Credential reference C 0db612d1-5496-4cd3-a570-a4a6c29be10f  retained on the retired target
```

The identity fixture is retained deliberately: no first-party lifecycle provides
deterministic tenant or membership removal, and none was invented. The credential
attached to a retired target is legitimate acceptance evidence and holds only a
synthetic secret that was never valid for any storage provider.

## Acceptance matrix

Every row PASS: repaired API digest, natural authentication,
`platform.memberships.manage` naturally present, canonical tenant creation,
explicit platform enrollment, tenant B selection, return to A, target creation,
draft state, null credential, pre-credential activation `422`, credential `PUT`,
safe identifier and fingerprint projection, raw secret absent from the API,
encryption at rest, fingerprint match, single credential row, activation,
owner `GET`/list, foreign-tenant `404`, foreign-list exclusion, owner recovery,
`created`/`credential_set`/`state_changed` audit and outbox evidence,
raw-secret redaction, PayloadSafety safe-metadata survival, no storage
connection, no RMA-E/F/G leakage, retirement, and retired-terminal.
