# RMA-A — Recording Authority and Lifecycle Natural Live Proof Blocker

Current-State-Impact: yes

**Date:** 2026-08-31
**Starting and deployed revision:** `16f394af5f953cf4f0cec6198fe029a6f570ad33` — `feat(rma): establish recording authority and lifecycle`

## Verdict

`RMA_A_RECORDING_AUTHORITY_AND_LIFECYCLE_NATURAL_LIVE_PROOF_FOUND_BLOCKER`

RMA-A remains implemented and tested. Its controlled natural live proof is
blocked before recording intent can be requested because the canonical Asterisk
outbound-call corridor cannot create the required active CallLeg for the
repository-defined endpoint-plus-URI SIP DestinationRef.

## Deployed Preconditions

The normal native-k3s deployment lifecycle promoted and deployed the starting
revision. The normal `utcp-migrate` Job completed, including the
`recording_sessions` migration. The API, telephony command worker, Asterisk ARI
event consumer, event normalizer, and worker deployments converged normally.

The selected managed RuntimeNode was an active, ready Asterisk ARI runtime with
the advertised `recording` capability. Its canonical workload was running in
the native two-node cluster. FreeSWITCH was not selected.

## Canonical Call Attempt and Blocker

The proof used normal application authentication and the canonical outbound
Call API. No Call, CallLeg, RuntimeOperation, or RecordingSession database row
was manufactured. The maintained Asterisk Echo fixture was supplied as:

```text
sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060
```

`POST /api/v1/calls` returned `201 Created`, and the CallLeg was assigned to
the selected Asterisk RuntimeNode. Its subordinate `call.leg.originate`
RuntimeOperation then terminal-failed with:

```text
failure_class: invalid_request
failure_code: ari_destination_invalid
```

The CallLeg consequently became terminally failed with
`origination_failed`; no non-terminal CallLeg existed on which RMA-A could
legitimately request recording.

The current `AsteriskAriClient::asteriskEndpoint()` implementation first applies
the following transformation:

```php
preg_replace('/^sip:([^@]+)@.*$/', '$1', $destination)
```

For the supported endpoint-plus-URI fixture, that reduces the destination to:

```text
anonymous/sip:9900
```

It no longer begins with `sip:` and fails the accepted local-extension pattern,
so the client raises `ari_destination_invalid` before issuing the normal ARI
origination request. The live failure and the current source agree on this
exact defect. Historical evidence that described a different endpoint rendering
does not override the deployed current contract.

## Proof Boundary Preserved

No RMA-A start or stop request was made because the required real active
CallLeg did not exist. Therefore this packet did not assert any live result for
RecordingSession creation, desired/observed recording transitions, subordinate
recording operations, recording observation, idempotency, the generic recording
operation authority cutoff, lifecycle query, or recording audit.

No manual SQL, lifecycle projection, reconciliation invocation, Redis mutation,
or direct ARI recording command was used. No production source was changed.
Runtime-local recording media, artifacts, archive targets, object storage,
retention, playback, download, and FreeSWITCH recording remain outside RMA-A.

## Required Next Action

Implement the bounded correction in `AsteriskAriClient::asteriskEndpoint()` so
a SIP DestinationRef that contains an endpoint plus URI (including the
repository-defined Asterisk Echo fixture) is normalized into the valid ARI
endpoint representation rather than being reduced to an invalid local
extension. Add a focused regression for that exact input and preserve existing
endpoint, `tel:`, and unsupported-destination behavior.

After that correction is deployed, rerun this controlled RMA-A natural live
proof from the canonical Call API through RecordingSession intent, subordinate
RuntimeOperations, Asterisk execution, and automatic observation.

## Current State

```text
RMA:   IN PROGRESS
RMA-A: IMPLEMENTED_AND_TESTED / NATURAL LIVE PROOF BLOCKED
RMA-B through RMA-H: NOT STARTED

K5C, K5D, K5E: COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5F: POST-K5E / UNCHANGED
```
