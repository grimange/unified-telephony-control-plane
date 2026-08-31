# RMA-A — Identity Catalog Repair and Natural Live Proof Blocker

Current-State-Impact: yes

**Date:** 2026-08-31
**Implementation revision:** `f00e47ca206f50f3835093d290dda18d2a7a0646` —
`fix(rma): sync recording identity catalog`
**Proof revision deployed:** `f00e47ca206f50f3835093d290dda18d2a7a0646`

## Verdict

`RMA_A_RECORDING_IDENTITY_CATALOG_REPAIRED_NATURAL_LIVE_PROOF_FOUND_BLOCKER`

The missing persistent identity-catalog entries were repaired with a forward,
idempotent migration and deployed through the normal native-k3s lifecycle.
The canonical authenticated recording API then passed authorization and
created a durable RecordingSession. The complete RMA-A natural live proof did
not pass because the subordinate Asterisk recording-start operation reached a
runtime conflict before recording became active.

## Identity-Catalog Repair

The application configuration already declared these tenant-scoped capabilities
and assigned them to `tenant-admin`:

```text
telephony.recordings.view
telephony.recordings.manage
```

The persistent catalog did not contain the capability rows or role mappings,
which caused the previous canonical recording request to return `403` before
RecordingSession creation. The new forward migration
`2026_08_31_131000_sync_rma_a_identity_catalog.php` synchronizes only these two
configured capabilities and their `tenant-admin` mappings with `updateOrInsert`.
It preserves unrelated catalog state and has a no-op rollback consistent with
the repository's shared identity-catalog migration convention.

The migration was applied by the completed deployment migration job using the
immutable API image for this revision. Under the normal authenticated tenant
context, the deployed recording intent endpoint returned `202 Accepted`, and
recording list/detail endpoints returned `200`, proving that the tenant-admin
principal resolved both required capabilities. The focused migration test also
proved configured scope, tenant-admin mappings, idempotent rerun, preservation
of the existing call-record capability, and normal authorization resolution.

## Automated Validation

The focused identity-catalog regression initially exposed an incorrect dotted
configuration lookup in the test; the test was corrected to read the configured
capability map by literal key. The resulting validation passed:

```text
make image-test-api
681 passed, 9 skipped, 5456 assertions
```

Both changed PHP files passed `php -l`. `make phase-status-consistency-check`,
`make repository-hygiene`, and `git diff --check` passed before deployment.
The native image workflow and normal server image/config/preflight/apply
lifecycle also passed. The post-push Quality workflow was not green, but its
failures were unrelated pre-existing CI/environment paths: the Asterisk
boundary check reported an existing generic C3/C5 branch, and local k3d jobs
failed because the CI kubeconfig/CRDs were unavailable. No failure was tied to
the RMA migration or its focused tests.

## Fresh Canonical Call

A fresh authenticated canonical Call was created with the maintained Echo
destination:

```text
sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060
```

The repaired endpoint-plus-URI translation produced a real Asterisk channel:

```text
Call:              1123a411-dfd3-4a64-ad40-e620946eae3f
CallLeg:           4b74eef0-6108-4c55-b48d-08d64b14fc1d
RuntimeNode:       102d58ba-93ec-4601-a2a3-81f95801440f
Runtime channel:   utcp-call-leg-4b74eef0-6108-4c55-b48d-08d64b14fc1d
Originate op:      8fce631642f749f16a34b74b5595b64d
Originate result:  succeeded
```

The CallLeg was still in its natural originating phase when the recording
intent was submitted and later ended by the remote side. No Call, CallLeg,
RuntimeOperation, or RecordingSession state was manufactured.

## RMA-A Boundary Reached

The canonical request was:

```text
POST /api/v1/calls/1123a411-dfd3-4a64-ad40-e620946eae3f/recordings
```

It created:

```text
RecordingSession: dbaeca3812917aa33d36562a92626c59
Start operation:  e85af9f1a4ed38ff28bdc2895d167bb4
```

Repeating the same request with the same idempotency key returned the same
RecordingSession and start operation; no duplicate authority was created.
List and detail API reads returned the same canonical session. The generic
`call.leg.start_recording` operation path was rejected with the intended
authority-cutoff response:

```text
recording operations must use the canonical recording session API
```

The request therefore crossed the repaired authorization boundary and reached
the canonical RMA-A domain authority.

## New Live Blocker

The subordinate start operation terminal-failed in the deployed
`telephony-command-worker` with:

```text
failure_class: conflict
operation_type: call.leg.start_recording
result: terminal_failure
```

The RecordingSession remained at `desired_state=recording` and
`observed_state=requested`. The live evidence does not establish the exact
underlying ARI HTTP response or whether the conflict was caused by the channel
being non-connected/ending, an ARI recording-resource condition, or another
adapter/runtime boundary. Consequently this is not attributed to the identity
catalog migration and no production recording or adapter code was changed in
this proof run.

Because recording never became active, canonical stop execution, automatic
recording-stop observation, stopped convergence, and a complete live audit
sequence could not be proven. No direct ARI command, SQL mutation, Redis
mutation, manual projection, or manual reconciliation was used.

## Preserved Scope

RMA-B through RMA-H, archive/media artifact work, storage providers, retention,
playback/download, FreeSWITCH recording, K5, and unrelated runtime systems were
untouched. The prior Asterisk endpoint-plus-URI repair and prior RMA-A
implementation remain intact.

## Next Action

Perform a narrow evidence audit of the deployed Asterisk recording-start
conflict on a fresh naturally originated CallLeg, capturing the exact ARI
response/status and channel state before proposing any bounded repair.

RMA-A remains implemented/tested with natural live proof blocked at this new
runtime boundary. RMA-B remains not started.
