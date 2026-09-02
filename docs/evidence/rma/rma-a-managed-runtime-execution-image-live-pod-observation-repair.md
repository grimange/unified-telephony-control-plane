# RMA-A Managed Runtime Execution Image Live-Pod Observation Repair

Current-State-Impact: yes

**Date:** 2026-09-02  
**Environment:** native k3s, context `default`, `utcp-dev01` (`192.168.254.124`)  
**Implementation commit:** `20f38ecb377b39b0b71de22011366d6f6b3ef7a2`  
**Verdict:** `RMA_A_MANAGED_RUNTIME_EXECUTION_IMAGE_LIVE_POD_OBSERVATION_REPAIRED_DEPLOYED_AND_PROVEN`

## Root cause and bounded repair

`ManagedRuntimeWorkloadConvergenceOperationHandler::observeExecutionImage()`
used the first Asterisk container image ID returned from Deployment-owned Pods.
The deterministic Kubernetes list order could place a terminating stale Pod
before the live Pod, projecting the stale digest into `RuntimeNode` and making
its execution contract stale. The observer now excludes Pods with a
`deletionTimestamp` and terminal `Succeeded`/`Failed` phases, prefers
`Ready=True`, and uses deterministic Pod-name ordering within the same
readiness class. If no acceptable live Pod supplies a digest, it leaves the
previous observed image unchanged.

No selector, execution-contract, worker, recording, node-management, or
`utcp-dev02` behavior was changed.

## Repository proof

The focused regression reproduces the defect with a terminating stale Pod
first and a live Ready Pod second, and proves the current digest is selected.
A second regression supplies only a terminating Pod and proves a prior valid
observed digest is retained. The repository-native API test image passed:

```text
RuntimeProvisioning + RuntimeRegistry: 67 tests, 701 assertions, PASS
Asterisk focused regression suite: 5 tests, 38 assertions, PASS
Pint check: PASS
phase-status-consistency-check: PASS
repository-hygiene: PASS
git diff --check: PASS
```

## Immutable publication and deployment

Source commit `20f38ecb377b39b0b71de22011366d6f6b3ef7a2` was pushed to
`origin/main`. GitHub Actions workflow run `33574037886` completed successfully
and published the immutable API image:

```text
ghcr.io/grimange/utcp-api@sha256:191b468df1cc47d1d4ee92108cfb1ee686acc5c22d5d93a09a65411daae2ec0a
```

The canonical image-lock artifact is
`native-k3s-image-lock-20f38ecb377b39b0b71de22011366d6f6b3ef7a2`, artifact ID
`9826112270`. `make server-image-preflight`, `make server-image-sync`,
`make server-apply`, and `make server-status` completed successfully.

## Stage-0 live proof

The managed RuntimeNode was `102d58ba-93ec-4601-a2a3-81f95801440f`, slug
`v1a-outbound-reproof-asterisk-1787825256`. After the normal reconciliation
interval, its active candidate Pod was:

| Pod | Phase | deletionTimestamp | Ready | Asterisk image ID | Node |
| --- | --- | --- | --- | --- | --- |
| `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6576f8m8vk` | Running | `<none>` | `True` | `ghcr.io/grimange/utcp-asterisk@sha256:8d086c0fd9d4b319fcf9a9bf12f9e54db236964918ff94b6263974c40bc7cf66` | `utcp-dev01` |

The previously condemned stale Pod was removed by normal Deployment rollout
cleanup; it was not force-deleted. Its prior existence and stale digest remain
documented in the historical blocker evidence. The RuntimeNode then reported:

```text
desired_state=active
observed_state=ready
desired_execution_image=ghcr.io/grimange/utcp-asterisk@sha256:8d086c0fd9d4b319fcf9a9bf12f9e54db236964918ff94b6263974c40bc7cf66
observed_execution_image=sha256:8d086c0fd9d4b319fcf9a9bf12f9e54db236964918ff94b6263974c40bc7cf66
configuration_version=33
observed_configuration_version=33
```

The desired and observed execution-image digests are equal, so the observable
`RuntimeExecutionContract::isCurrent()` predicate is true. The node was
therefore eligible for outbound execution.

One normal authenticated canonical probe then posted the maintained Echo
destination to `POST /api/v1/calls` with the RuntimeNode explicitly selected.
It returned `201 Created` and persisted:

```text
Call:       bccceed6-9a58-42f3-812c-bf1dd4e6c70d
CallLeg:    7b371903-d4a3-44d2-9cc3-f377e9d1fc48
RuntimeNode: 102d58ba-93ec-4601-a2a3-81f95801440f
Call state: answered
CallLeg state: answered
Created:    2026-09-02 00:22:24+00
```

This proves the repaired corridor from live Ready Pod image to current
execution contract, selector eligibility, and canonical Call acceptance. No
RecordingSession request or recording lifecycle acceptance was performed.

## Remaining boundary

RMA-A is not complete. The remaining natural-live lifecycle proof is deferred
to the next acceptance packet, including RecordingSession intent, natural
answer/start/stop convergence, idempotency, provider stop, and audit continuity.
