# RMA-B Capture Reference Direct Derivation Canonical Deployment

Current-State-Impact: yes

## Verdict

`RMA_B_CAPTURE_REFERENCE_DIRECT_DERIVATION_CANONICALLY_PUBLISHED_AND_DEPLOYED`

## Source and parentage

The repaired application source is
`8e77cdc78251ebee88f461b2775745f00d4de63a`. Git establishes the direct parent
as `674a1588d764f8ca62ede9ee79c35e2f5ce24af9`, resolving the historical SHA
transcription discrepancy. `main`, `origin/main`, and the remote `main` ref
were aligned at the start of publication, and the worktree was clean.

## Publication and image lock

The `Native k3s Images` workflow run `33590246239` completed successfully for
the repaired source commit. The immutable lock artifact was
`native-k3s-image-lock-8e77cdc78251ebee88f461b2775745f00d4de63a` (artifact
`9831597346`). The canonical image-lock promotion completed with
`GH_REPO=grimange/unified-telephony-control-plane make server-image-sync`.

| Image | Immutable reference |
| --- | --- |
| API | `ghcr.io/grimange/utcp-api@sha256:050c0cc8b8dcb5f6413fb446eb86ec66bd7ba5b9e6f44b6aa4218ba6bbdd9820` |
| Asterisk | `ghcr.io/grimange/utcp-asterisk@sha256:c303c574a01ca0d2bd8153e84505781685839505f67a124fe1ffde5982afd577` |
| Web | `ghcr.io/grimange/utcp-web@sha256:7c72b87acafe2ec1eec45c5022e0fcd9ef63cd1be74718dc2802997113bca34c` |
| Gateway | `ghcr.io/grimange/utcp-gateway@sha256:ea031e04722fefb255b2dd9c2ee516b972ffd1bacd2b53245dfdc1757a1d80a6` |
| FreeSWITCH | `ghcr.io/grimange/utcp-freeswitch@sha256:04d729fdac6e6446b60f6c181adf5b26270899f766c9d3ca2d2bc232562eb667` |
| RTPengine | `ghcr.io/grimange/utcp-rtpengine@sha256:cbdfffe401bd2328afa8a0870b9262189b1bd8b9ea421ca2e7c06b092d388816` |

## Native-k3s deployment

The canonical context was `default`, with native k3s nodes `utcp-dev01`
(`192.168.254.124`) and `utcp-dev02` (`192.168.254.125`), across the
`utcp-platform`, `utcp-runtime`, and `utcp-data` namespaces. The canonical
image preflight, apply, status, and proof targets passed; migration completed
and the application rollouts converged.

The running API Pod was
`utcp-platform/api-6579d6c89d-8j2pw` on `utcp-dev02`: Running, Ready, zero
restarts. Its configured image and actual `imageID` were the published API
reference above.

The maintained RMA-B Asterisk Pod was
`utcp-runtime/asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-cff675r6sk`
on `utcp-dev01`: Running, Ready, zero restarts. Its configured image and
actual `imageID` were the published Asterisk reference above.

## Deployed repair and runtime baseline

Read-only inspection of the running API confirmed:

* `CaptureReference::forRecordingSession()` validates and parses
  `utcp:capture/` plus the supplied canonical id directly; it does not call
  `md5()`.
* `AsteriskAriEventNormalizer` resolves the capture identifier with a
  tenant-scoped `recording_sessions.id` lookup.
* `RecordingSessionService` resolves the capture identifier with the exact
  CallLeg-scoped primary-key lookup and lock.
* No capture-boundary `md5()` scan/re-hash correlation remains in the deployed
  API source.

The target RuntimeNode
`102d58ba-93ec-4601-a2a3-81f95801440f` reported `desired_state=active`,
`observed_state=ready`, configuration version `33` observed at `33`, and
matching desired/observed execution image digest
`sha256:c303c574a01ca0d2bd8153e84505781685839505f67a124fe1ffde5982afd577`.
Its declared capabilities included `recording`. The deployed FreeSWITCH
adapter catalog continued to exclude `recording`.

## Proof boundary

This record proves publication, exact image-lock promotion, native-k3s
deployment, actual API image provenance, deployed direct derivation and
correlation semantics, Asterisk readiness, and RuntimeNode baseline health.
It does not perform the focused live capture reproof and does not claim RMA-B
complete. The remaining proof is the focused live direct-identity reproof:
`RecordingSession.id = X`, `capture_ref = utcp:capture/X`, provider reference
`utcp-capture-X`, direct event correlation, and regression confirmation of the
already-proven boundaries. No RMA-C behavior was introduced.
