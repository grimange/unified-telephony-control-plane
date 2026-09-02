# RMA-D Archive Target and Secret-Reference Authority

Current-State-Impact: yes

Date: 2026-09-02

Status: IMPLEMENTED / REPOSITORY-TESTED

## Scope

RMA-D establishes the tenant-scoped archive-target catalog and exactly one
encrypted credential reference/value per target. It does not implement archive
selection or transfer execution. RecordingSession and RecordingArtifact remain
the accepted RMA-A/RMA-C authorities.

The reconciled boundaries are:

- RMA-D owns target configuration, credential reference/value management,
  authenticated API authorization, audit, and outbox authority.
- RMA-E will own target selection, ArchiveTransfer, storage adapters,
  connectivity, and transfer mechanics.
- RMA-F will own credential versions, rotation, expiry, and safe cutover.
- RMA-G will own retention and deletion lifecycle.

## Implemented authority

`MediaArchiveTargetService` is the sole writer for `media_archive_targets` and
`media_archive_credential_references`. Target creation always starts in
`draft`; activation requires an existing credential reference but does not
contact storage. Target lifecycle is `draft`, `active`, `disabled`, or
`retired`, with retired terminal. Credential PUT replaces the one row for the
target in place and preserves its row identity.

The six authenticated tenant-admin API routes are list, create, show, patch,
desired-state transition, and whole-credential PUT under
`/api/v1/admin/recording-archive-targets`. View and manage capabilities are
separate and tenant-scoped.

## Persistence and security

The new migration creates the target and credential-reference tables with the
approved UUID identities, tenant ownership, foreign keys, tenant/slug
uniqueness, one-credential-per-target uniqueness, lifecycle and endpoint
checks, and tenant-scoped indexes. No archive-target field was added to
RecordingSession or RecordingArtifact, and no RMA-C schema was changed.

Secrets are persisted only as `Crypt::encryptString($secret)` with a SHA-256
`secret_fingerprint`. Safe projections, audit metadata, outbox payloads, and
validation/error responses do not contain the raw or encrypted secret. The
small PayloadSafety integration change permits only the non-secret
`credential_reference_id` and `secret_fingerprint` metadata required by the
approved audit/outbox contract; generic credential and secret fields remain
rejected.

## Events and boundaries

The implementation emits only `media_archive_target.created`,
`media_archive_target.updated`, `media_archive_target.state_changed`, and
`media_archive_target.credential_set` with sanitized metadata. It adds no
default-target authority, `use_path_style`, ArchiveTransfer, storage client,
connectivity check, credential version/status/rotation, retention, UI, worker,
queue, or operational gate.

## Verification

The repository-native containerized API suite passed 707 tests, skipped 9, and
recorded 5,659 assertions. Focused coverage proves tenant isolation,
authorization, target lifecycle, activation gating, credential encryption and
same-row replacement, safe API/audit/outbox projection, schema authority, and
future RMA-E/RMA-F/RMA-G boundary absence. The required repository checks and
PHP syntax validation also passed. No deployment, image publication, storage
connection, or live RMA-D acceptance was performed.

The next bounded step is canonical immutable publication/deployment, followed
by focused authenticated-API acceptance. RMA-E remains not started.
