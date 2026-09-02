# RMA-B Runtime-Neutral Capture Contract Canonical Publication and Deployment

Current-State-Impact: yes

## Verdict

`RMA_B_RUNTIME_NEUTRAL_CAPTURE_CONTRACT_CANONICALLY_PUBLISHED_AND_DEPLOYED`

## Source and publication

The verified source revision was
`157f1928078786538a6574f47abf5e1864bfc7bb`, with `main`, `origin/main`, and
the remote `main` ref aligned and the worktree clean. The repository-native
API image test passed with 693 tests passed, 9 skipped, and 5516 assertions.
The `Native k3s Images` workflow run `33583512215` completed successfully for
that commit. Its exact lock artifact was
`native-k3s-image-lock-157f1928078786538a6574f47abf5e1864bfc7bb` (artifact
`9829347496`). `make server-image-sync` promoted that artifact without manual
image editing.

The promoted immutable images included:

| Image | Digest |
| --- | --- |
| API | `ghcr.io/grimange/utcp-api@sha256:d24c058844a8bf3488fac5079195695b3720b5f2c3009f77c6f5b0f13b882016` |
| Asterisk | `ghcr.io/grimange/utcp-asterisk@sha256:ef75061713c4fcba4d78e4601f7f8159976b790348f47a08f5bd1072ea064e24` |

The Asterisk digest above is recorded from the promoted lock and running
workload; the complete image set remains in `.runtime/native-k3s/image-lock.env`.

## Native-k3s proof

The canonical native target was context `default`, node `utcp-dev01`
(`192.168.254.124`), in namespaces `utcp-platform` and `utcp-runtime`.
`make server-config-check`, `make server-image-preflight`, `make server-apply`,
`make server-status`, and `make server-proof` passed. Migration completed and
the platform rollouts converged.

The running API Pod was `utcp-platform/api-796b7fd9fc-wsj4n` on `utcp-dev01`:
Ready, Running, zero restarts, with actual `imageID`
`ghcr.io/grimange/utcp-api@sha256:d24c058844a8bf3488fac5079195695b3720b5f2c3009f77c6f5b0f13b882016`,
matching the published API digest. A read-only inspection confirmed
`CaptureReference.php` and the deployed `capture_ref` payload logic.

The selected Asterisk Pod was
`utcp-runtime/asterisk-ari-55b5c566df-8b7ld` on `utcp-dev02`: Running, Ready,
zero restarts, with actual `imageID`
`ghcr.io/grimange/utcp-asterisk@sha256:ef75061713c4fcba4d78e4601f7f8159976b790348f47a08f5bd1072ea064e24`.
The separate disposable Asterisk fixture Pod remained outside this proof's
target and was not changed manually.

The managed RuntimeNode
`102d58ba-93ec-4601-a2a3-81f95801440f` (`v1a-outbound-reproof-asterisk-1787825256`)
reported `desired_state=active`, `observed_state=ready`, configuration
version `33` observed at `33`, and desired/observed execution image equal to
`sha256:ef75061713c4fcba4d78e4601f7f8159976b790348f47a08f5bd1072ea064e24`.
Its capability set retained `recording`. The FreeSWITCH capability boundary
was not changed or exercised.

## Proof boundary

This record proves canonical publication, image-lock promotion, native-k3s
deployment, actual running image provenance, and baseline RuntimeNode health.
It does not claim RMA-B live acceptance. The pending acceptance must prove
live capture identity correlation, provider translation, channel-less recording
observations, exact session targeting, monotonicity, and the FreeSWITCH
unsupported runtime boundary. No RecordingSession capture transaction was
created here, and no RMA-C artifact/archive behavior was introduced.
