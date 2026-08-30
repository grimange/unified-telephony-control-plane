# V1 Native k3s Immutable Image-Lock Promotion

Current-State-Impact: yes

Date: 2026-08-30

## Verdict

`NATIVE_K3S_IMMUTABLE_IMAGE_LOCK_PROMOTION_IMPLEMENTED_AND_TESTED`

The demonstrated stale-lock blocker is resolved in the repository. The
existing `Native k3s Images` workflow remains the sole publication authority;
this packet adds the missing explicit promotion/consumption seam.

## Defect and bounded repair

CI already publishes immutable `sha-<commit>` images, resolves their digests,
and uploads `native-k3s-image-lock-<commit>`. The native lifecycle consumed only
the locally persisted `.runtime/native-k3s/image-lock.env`, with no
repository-owned way to promote the exact CI artifact. Consequently,
`server-apply` could succeed while rendering an older API digest.

`make server-image-sync` selects the exact local HEAD by default, or an
explicit full lowercase commit from `UTCP_SERVER_SOURCE_COMMIT`, and retrieves
only `native-k3s-image-lock-<commit>` through authenticated GitHub CLI
artifact access. It validates the exact source commit, matching `sha-<commit>`
tag, native registry identity, complete expected image set, and digest-pinned
references before promotion.

## Safety and convergence

The artifact must contain exactly one candidate lock file. Validation occurs in
a temporary directory before the active lock is touched. A validated lock is
installed with a same-directory temporary file and atomic rename. Any metadata,
download, extraction, provenance, registry, or digest failure leaves the
existing active lock byte-for-byte unchanged. Promoting the same lock again is
a successful no-op. Historical local k3d registry identities remain rejected.

## Proof

The focused `scripts/native-k3s/image-sync-test` uses fake authenticated GitHub
CLI responses and local ZIP archives. It covers successful promotion,
idempotency, wrong source commit, wrong image tag, mutable references, missing
artifact, ambiguous extraction, historical local registry, authentication
failure, and prior-lock preservation after failed validation.

The directly relevant shell checks passed:

```text
bash -n scripts/native-k3s/image-sync scripts/native-k3s/image-sync-test
./scripts/native-k3s/image-sync-test
```

No real GitHub artifact was promoted and no live native-k3s deployment was
performed in this packet. The Asterisk repair image is already published, but
live promotion/deployment and controlled provider-failure fact binding remain
pending. Gap E therefore remains open.

## Explicit boundaries

No image publication redesign, local image rebuild, mutable tag, Kubernetes
mutation, provider/SIP/PBX change, Asterisk source change, failure taxonomy,
provider-channel correlation, feature gate, manual lock edit, or alternate
publication authority was added.
