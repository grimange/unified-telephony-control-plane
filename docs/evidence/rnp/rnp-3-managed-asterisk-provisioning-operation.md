# RNP-3 — Managed Asterisk Provisioning Operation

Status: **Complete in the repository; no live managed-runtime lifecycle proof claimed.**

RNP-3 consumes the RNP-1 tenant-scoped `runtime_provisioning_request` and its
canonical DRAFT RuntimeNode. The Admin API transaction creates one durable
`runtime.node.provision` operation using the existing `runtime_operations`
kernel and an idempotency key derived from the provisioning-request ID.

## Authority and execution

The operation is handled by `ManagedAsteriskProvisioningOperationHandler`,
which implements `RuntimeOperationHandler` and `RunsWithoutRuntimeAdapter`.
The API/command worker excludes this operation; the
`telephony-infrastructure-worker` route claims it. The handler uses the
existing RNP-2 `KubernetesWorkloadClient`, `RuntimeRegistryService`, and
`AsteriskAriProfileService`. No Kubernetes call occurs in the API request path,
and no second operation framework or RuntimeNode registry was introduced.

## Provisioning ordering

For a `local_kubernetes` Asterisk/`asterisk-ari` request, the handler:

1. Resolves the tenant, request, target, and linked DRAFT RuntimeNode.
2. Generates or reuses the canonical encrypted `ari-basic` credential.
3. Applies the owned Secret, Deployment, and ClusterIP Service through RNP-2.
4. Registers control, events, and health endpoints through RuntimeRegistryService.
5. Derives declared capabilities from the Asterisk adapter catalog.
6. Writes the Asterisk ARI profile through AsteriskAriProfileService.
7. Registers `labels.kubernetes_workload` with namespace `utcp-runtime` and the
   generated Deployment name.
8. Requests DRAFT → ACTIVE through RuntimeRegistryService.

The operation never writes `observed_state`, observed capabilities, or
projection tables. Existing Asterisk inspection/reconciliation and ProjectionService
remain the readiness authority. Operation success means infrastructure and
configuration are materialized and activation is requested; `observed_state =
ready` remains asynchronous readiness evidence.

## Generated Asterisk contract

Resource names are stable, Kubernetes-valid, and derived from the RuntimeNode
slug plus an eight-character SHA-256 suffix of the RuntimeNode ID. The same
base is used for Deployment and Service; the Secret adds `-credentials`.
The base is bounded so all names remain within the Kubernetes DNS-label limit.

The Secret contains only `ARI_USERNAME` and `ARI_PASSWORD`, matching the
checked-in Asterisk entrypoint. The Deployment uses image `utcp-asterisk-ari`,
ARI TCP port 8088, the Secret reference, the checked-in probes, resource
limits/requests, non-root and dropped-capability security settings, and the
canonical ownership labels. The Service is an internal ClusterIP on port 8088
targeting the named `ari` container port. No host port, NodePort, LoadBalancer,
ConfigMap, PVC, or additional Kubernetes resource is generated.

Ownership is enforced by RNP-2: `app.kubernetes.io/part-of=utcp` and
`utcp.dev/runtime-node=<RuntimeNode slug>`. Existing unowned same-name
resources produce a visible ownership-conflict operation failure and are not
adopted, relabeled, overwritten, or deleted.

## Credential safety and retries

RuntimeRegistryService is the application credential authority. It generates a
cryptographically strong identifier and secret once, stores the secret only
encrypted, and returns the plaintext only across the internal provisioning
seam so the worker can deliver the same value to the Kubernetes Secret. A
retry decrypts and reuses the existing active credential. No Admin response,
operation payload/event, audit metadata, outbox event, or handler failure
message contains the plaintext.

The focused retry test simulates an interruption after credential persistence
and Secret delivery. The second attempt uses the same credential and does not
create a second RuntimeNode, operation, or active credential. RNP-2 writer
tests cover owned convergence and unowned-resource refusal for Secret,
Deployment, and Service.

## Endpoint and capability bootstrap

The generated Service host is
`<service>.utcp-runtime.svc.cluster.local`. RuntimeRegistryService receives:

| Purpose | Transport | Host | Port | Path |
|---|---|---|---:|---|
| control | http | generated Service FQDN | 8088 | `/ari` |
| events | ws | generated Service FQDN | 8088 | `/ari/events` |
| health | http | generated Service FQDN | 8088 | `/ari` |

Declared capabilities are read from the `asterisk-ari` catalog:
`event.stream`, `runtime.observation`, `conference.lifecycle`, and
`conference.participation`. The Asterisk profile is populated with the
canonical profile defaults; no operator-supplied infrastructure labels or
endpoints are required.

## Verification

The focused RNP-3 suite proves worker orchestration, activation ordering,
resource shape, generated endpoint/capability/profile/workload identity,
request and operation idempotency, credential reuse after partial failure, and
secret non-exposure. The existing RNP-1 API suite and RNP-2 Kubernetes writer
suite remain regression gates. No browser proof, live Kubernetes provisioning,
or natural Admin lifecycle proof is claimed for this packet.
