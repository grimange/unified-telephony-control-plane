# RMA-B Capture Reference Direct Derivation Repair

Current-State-Impact: yes

## Verdict

`RMA_B_CAPTURE_REFERENCE_DIRECT_DERIVATION_REPAIRED_AND_TESTED`

## Root cause and repair

The prior RMA-B implementation hashed `RecordingSession.id` with MD5 before
building `capture_ref`. That made the capture identity non-invertible and
required tenant-wide scan-and-rehash correlation in the Asterisk event
normalizer and `RecordingSessionService`.

`CaptureReference::forRecordingSession()` now derives directly from the
validated 32-character lowercase hexadecimal `RecordingSession.id`:

```text
RecordingSession.id
→ utcp:capture/<id>
→ utcp-capture-<id>
```

Both observation paths now use the extracted identifier as a tenant-scoped
primary-key predicate, retaining the existing CallLeg, RuntimeNode, and
lifecycle guards. No MD5 compatibility path, migration, API, worker, queue,
capability, provider-mechanics, or RMA-C behavior was added.

## Validation

The repository-native `make image-test-api` suite passed with 694 tests passed,
9 skipped, and 5525 assertions. The three changed production files passed
`php -l`. `make phase-status-consistency-check`, `make repository-hygiene`,
and `git diff --check` passed.

Focused tests now assert direct identity, strict malformed-ID rejection, and
that start/stop operation payloads use the exact RecordingSession primary key.
Existing Asterisk route and lifecycle expectations remain provider-mechanics
compatible.

## Remaining proof boundary

The corrected revision has not yet been republished, redeployed, or rerun
through the focused live RMA-B acceptance. Those are the next bounded
publication/deployment and acceptance-proof tasks. RMA-B is not marked
complete by this repository repair alone.
