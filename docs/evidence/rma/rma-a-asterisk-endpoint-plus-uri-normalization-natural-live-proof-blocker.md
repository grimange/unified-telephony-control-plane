# RMA-A — Asterisk Destination Normalization Repair and Natural Live Proof Blocker

Current-State-Impact: yes

**Date:** 2026-08-31
**Implementation revision:** `87b2524da0f54619df0a28d4527c07efd82d2012` —
`fix(asterisk): preserve endpoint-plus-uri destinations`
**Proof revision deployed:** `87b2524da0f54619df0a28d4527c07efd82d2012`

## Verdict

`RMA_A_ASTERISK_ENDPOINT_PLUS_URI_NORMALIZATION_REPAIRED_NATURAL_LIVE_PROOF_FOUND_BLOCKER`

The confirmed Asterisk endpoint-plus-URI normalization defect is repaired and
automated-tested. The repaired revision was deployed through the normal
native-k3s lifecycle, and a fresh canonical Call successfully originated a
real Asterisk channel. RMA-A natural live proof then reached the canonical
recording API but was blocked by a separate deployed authorization-catalog
defect before a RecordingSession could be created.

## Asterisk Repair and Validation

Before the repair, `AsteriskAriClient::asteriskEndpoint()` reduced:

```text
sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060
```

to `anonymous/sip:9900`, discarding the explicit host and port. The bounded
repair preserves the endpoint and complete explicit URI, producing:

```text
PJSIP/anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060
```

The focused Asterisk adapter regression passed, including preservation of the
existing simple/local destination behavior. The full API image test passed:
`680 passed, 9 skipped, 5444 assertions`. No RMA-A production behavior was
changed by this proof run.

## Deployment and Call Reproof

The immutable image workflow, image synchronization, configuration checks,
image preflight, migration job, and native-k3s rollout completed normally.
The deployed API image was
`ghcr.io/grimange/utcp-api@sha256:97c9a526fa040c36500bdcb825f4f395145fa69cec72c3051ae010b0d2372a9a`.
Both development Nodes were Ready and platform workloads converged.

A fresh authenticated canonical Call request using the maintained Echo
fixture returned `201 Created`. Call
`b88ff053-f383-4043-8225-85deb9486233` was assigned to an active, ready
Asterisk RuntimeNode; its CallLeg
`d9398f78-97f7-4a2b-ac3e-566fbcc3f1b8` received a real runtime channel and
its subordinate `call.leg.originate` operation was observed in the live
runtime path. The proof did not manufacture Call, CallLeg, or operation state.

## New RMA-A Proof Blocker

The authenticated recording request reached the canonical endpoint but
returned `403 Forbidden` before any RecordingSession was created. The active
tenant context exposed `telephony.calls.record` and other tenant-admin
capabilities, but did not expose either:

```text
telephony.recordings.view
telephony.recordings.manage
```

The deployed `config/identity.php` declares both capabilities and the
`tenant-admin` role declares both capabilities. However, the deployed
persistent identity catalog migration history contains the existing K5A/K5D
syncs and no RMA capability/role sync migration. Consequently the normal
deployed tenant role assignment does not contain the RMA-A permissions required
by `RecordingSessionController`.

This is an exact application deployment/identity-catalog seam defect, not an
Asterisk recording failure. No manual permission mutation, SQL, Redis change,
direct ARI recording command, manual projection, or manual reconciliation was
used. Because authorization failed before intent creation, this run proves no
RMA-A result for RecordingSession creation, recording start/stop operations,
automatic recording observation, idempotency, lifecycle query, or recording
audit.

## Scope Preserved

The earlier Asterisk blocker evidence remains historical and is not rewritten.
RMA-B through RMA-H, artifact authority, archive storage, credentials,
retention, playback/download, FreeSWITCH recording, K5, and unrelated runtime
infrastructure were untouched by this proof. The real Asterisk Call origination
repair is independently verified; the remaining proof gap is the missing
deployed RMA permission catalog synchronization.

## Required Next Action

Implement a forward identity-catalog migration that registers
`telephony.recordings.view` and `telephony.recordings.manage` and assigns them
to the repository-defined tenant-admin role through the normal migration
lifecycle. Add a focused regression proving the persistent catalog contains
both capability and role mappings, deploy it, and rerun this controlled RMA-A
natural live proof from a fresh canonical Call.

## Current State

```text
RMA:   IN PROGRESS
RMA-A: IMPLEMENTED_AND_TESTED / NATURAL LIVE PROOF BLOCKED
RMA-B through RMA-H: NOT STARTED

K5C, K5D, K5E: COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5F: POST-K5E / UNCHANGED
```
