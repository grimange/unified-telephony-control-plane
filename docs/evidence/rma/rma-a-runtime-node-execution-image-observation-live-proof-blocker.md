# RMA-A Final Natural-Live Acceptance Blocker: Terminating-Pod Execution-Image Observation

Current-State-Impact: yes

**Date:** 2026-09-01
**Repository:** `main` at `a9a2bcbd9ef7e8dc1135c4aa131f9e3bf1d0ea7a`
**Environment:** native k3s, context `default`, node `utcp-dev01`
**Deployed API image:** `ghcr.io/grimange/utcp-api@sha256:c526967c0c55d584a8596c21972c5005b5a4c3b53c0fbf0e552a6821ecdf37ec`
**Deployed Asterisk image:** `ghcr.io/grimange/utcp-asterisk@sha256:06f3e171e864fbc4c8781899f243de00c4ac40e8d16d1326dab338d6de3afc08`

## Verdict

`RMA_A_FINAL_NATURAL_LIVE_ACCEPTANCE_BLOCKED_STALE_EXECUTION_IMAGE_OBSERVATION`

The final natural-live acceptance could not create a fresh canonical Call. The
canonical outbound API rejected both explicit and automatic RuntimeNode
selection because the RuntimeNode's `observed_execution_image` is projected from
a **terminating** Pod on a NotReady node rather than from the live, Ready Pod.
This is an exact, bounded UTCP repository defect in managed-workload execution
observation. It is not a recording-capability, provider, SIP, or
RecordingSession regression.

## Scope and method

Read-only acceptance run. The Call was attempted through the supported
authenticated UTCP API over the repository-supported native-k3s gateway
port-forward (`scripts/native-k3s/proof` pattern, `service/gateway 18090:8080`).
No source, configuration, manifest, NetworkPolicy, or migration was changed. No
SQL, Redis, ARI, or dialplan mutation was used. No canonical state was created:
the Call list was 60 rows before and after the attempts, and the Asterisk
recording spool remained empty.

## Verified environment baseline

Both deployed digests matched the accepted immutable revision exactly. The
canonical node `utcp-dev01` was `Ready`; `utcp-dev02` was `NotReady`.

Accepted provider capability was re-confirmed read-only on the actual recording
Pod `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-dcd99hjgjg`:

```text
/var/spool/asterisk/recording   drwxr-x---  asterisk asterisk   (empty)
runtime uid                     1000(asterisk) gid 999(asterisk)
res_ari_recordings.so           Running
res_stasis_recording.so         Running
format_wav.so                   Running
registered formats              wav, wav16
```

The natural-answer destination was materialized on the Pod backing
`asterisk-sip`:

```text
'9900' => 1. NoOp(UTCP local T3-S2A media fixture)
          2. Answer()
          3. Echo()
          4. Hangup()
```

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` was `desired_state=active`,
`observed_state=ready`, `observed_configuration_version=33/33`, and held the
`recording` and `call.control` capabilities.

## Exact blocker

Both canonical originate paths failed deterministically:

```text
POST /api/v1/calls  {runtime_node_id: 102d58ba-...}
  -> HTTP 422  "Runtime node is not eligible for outbound call execution."

POST /api/v1/calls  {no runtime_node_id}
  -> HTTP 422  "No eligible runtime node is available for outbound call execution."
```

The RuntimeNode's execution contract never converges:

```text
desired_execution_image   ghcr.io/grimange/utcp-asterisk@sha256:06f3e171...
observed_execution_image  sha256:a6dbe7b4...
execution_image_current   false
execution_contract_current false
```

Four consecutive observations over ~35 s each refreshed `observed_at` and each
re-wrote the same stale digest, so this is stable projection, not lag.

The two Pods owned by the RuntimeNode's Deployment, in Kubernetes list order:

```text
...-9f5dbrv629  phase=Running  deletionTimestamp=2026-09-01T20:28:46Z  node=utcp-dev02  imageID=...@sha256:a6dbe7b4...
...-dcd99hjgjg  phase=Running  deletionTimestamp=<none>                node=utcp-dev01  imageID=...@sha256:06f3e171...
```

The first Pod is condemned (`deletionTimestamp` set), stranded on a NotReady
node, and `ready=false` in its EndpointSlice, so it carries no traffic and can
execute no telephony work. Its stale digest is nevertheless adopted as canonical
observed state.

## Root cause

`apps/api/app/RuntimeProvisioning/ManagedRuntimeWorkloadConvergenceOperationHandler.php:100-114`
(`observeExecutionImage()`) iterates `listOwnedPods()` and accepts the **first**
Pod exposing an `asterisk` container `imageID`, then `break 2`. It filters on
neither `metadata.deletionTimestamp`, Pod phase, nor the `Ready` condition.
`HttpKubernetesWorkloadClient::listOwnedPods()` filters only by Deployment
ownership and returns Pods in name order, so `...-9f5dbrv629` sorts before
`...-dcd99hjgjg` and deterministically wins.

The resulting consequence chain is:

```text
condemned Pod adopted
  -> observed_execution_image = stale digest
  -> RuntimeExecutionContract::isCurrent() = false
  -> RuntimeNodeSelector::convergedExecution() = false
  -> outbound RuntimeNode selection rejects the node (HTTP 422)
  -> no fresh canonical Call can be created
```

`CommandWorker::requiresFreshExecutionContract()`
(`apps/api/app/RuntimeEngine/Commands/CommandWorker.php:174-183`) applies the
same contract, so runtime operations on this node would also fail with
`FailureClass::Conflict`.

The selection is arbitrary rather than "not fully converged": the handler takes
one Pod and breaks, so had the healthy Pod sorted first the node would have been
eligible. That order-dependence is the defect signature.

## Competing causes excluded

* Recording capability, spool, modules, WAV registration — verified present on
  the live recording Pod.
* Provider ARI recording semantics — already closed on this revision.
* Answer fixture — `9900` Answer/Echo materialized.
* Capacity predicate — `capacity_weight=100` with zero active telephony work.
* Failure-domain predicate — placement `region` and `zone` are both `null`, so
  `RuntimeNodeFailureDomainEvaluator::eligible()` short-circuits true.
* Configuration generation — `observed_configuration_version` equals
  `configuration_version` (33).
* Capability predicate — `call.control` present.

`convergedExecution()` is therefore the sole failing predicate.

## Authority boundary

UTCP owns RuntimeNode observed state and execution-contract convergence.
Kubernetes owns Pod lifecycle and condemnation. The defect is UTCP adopting a
Kubernetes Pod that Kubernetes has already condemned as canonical runtime
evidence. No provider or Asterisk authority is implicated.

The repository already encodes the correct convention at three other sites:

* `apps/api/app/Infrastructure/Kubernetes/HttpKubernetesMaintenanceClient.php:65`
  skips Pods with `deletionTimestamp` or phase `Succeeded`/`Failed`.
* `apps/api/app/Infrastructure/RuntimeFencing/RuntimeNodeRestoreOperationHandler.php:363-379`
  evaluates the Pod `Ready` condition.
* `KubernetesRuntimeFenceAdapter` is covered by existing terminating-Pod tests
  (`apps/api/tests/Feature/Infrastructure/KubernetesRuntimeFenceAdapterTest.php:87,350`).

`observeExecutionImage()` is the one call site that omits it.

## Downstream proof gaps

Everything after the first acceptance boundary remains unproven in this run:
pre-answer RecordingSession intent, zero premature start, natural SIP answer,
canonical answered, exactly-one automatic start, provider WAV start,
LiveRecording, canonical active convergence, start idempotency, canonical stop,
store-preserving provider stop, RecordingFinished, stored-recording retention,
canonical stopped convergence, stop idempotency, and audit continuity. None of
these were contradicted; they were unreachable.

## Bounded next repair

Restrict `observeExecutionImage()` to live Pods before adopting an execution
image, following the established repository convention, and leave
`observed_execution_image` unchanged when no live Pod is observable rather than
adopting a condemned one.
