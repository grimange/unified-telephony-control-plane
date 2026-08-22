# C6 Observation Corridor Final Corrections

Date: 2026-08-22

## Scope

This bounded repository correction addresses the observation-corridor blockers
found during the accepted natural Asterisk proof. Browser and live-call proof
remain pending and were not repeated here.

## Conference ownership

`conference_participants` owns the provider channel identity. Runtime-node
ownership remains authoritative on `conferences.runtime_node_id`, reached via
`conference_id`. `AsteriskConferenceChannelOwnership` is the shared query used
by both `AsteriskRuntimeAdapter` and `AsteriskAriEventNormalizer`. No
`conference_participants.runtime_node_id` column or predicate is used.

The PostgreSQL regression exercises adapter ownership, normalizer generic
Stasis normalization, conference-channel exclusion, wrong-node ownership, and
generic deterministic channels.

## Reconciliation lease loss

An expected superseded reconciliation claim is now logged and skipped. The
worker does not evaluate a stale result into canonical state, does not create a
stale operation after the fenced row is locked and checked, and continues with
the next claim. Unexpected reconciler exceptions remain visible to the existing
worker failure path.

## Managed Asterisk desired state

`ManagedAsteriskProvisioningOperationHandler::desiredDeployment()` is the
shared desired Deployment builder for provisioning and existing-node
reconciliation. The managed Asterisk reconciler applies that desired spec for
managed nodes, including the shared `asterisk-local-sip-fixtures` ConfigMap
volume and `/opt/utcp-asterisk-local-config` mount. Repeated application is
idempotent; external RuntimeNodes are not passed through this managed path.

## Verification boundary

Focused SQLite tests, the disposable PostgreSQL `make control-plane-test`
path, and managed Asterisk reconciliation tests passed. No Kubernetes apply,
browser proof, or natural live call was performed in this packet.
