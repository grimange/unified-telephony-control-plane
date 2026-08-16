# RNP-4 — Managed Runtime Deprovisioning

## Status

RNP-4 is complete in the repository. RNP overall remains in progress. This
evidence records repository behavior only; the consolidated natural managed
runtime lifecycle proof remains RNP-6.

## Authority and scheduling

RNM remains the sole RuntimeNode lifecycle authority. When its existing
`DRAINED → decommission → RETIRED` corridor completes, or when its existing
`DISABLED → RETIRED` transition completes, `RuntimeRegistryService` checks the
canonical tenant-scoped RNP provisioning request relationship. Exactly one
request linked to the node is required. External/adopted nodes without that
relationship schedule no deprovision operation.

The operation is `runtime.node.deprovision`, keyed by
`runtime-node-deprovision:<provisioning-request-id>`, and is created through
the existing `RuntimeOperationRepository`. Duplicate terminal scheduling,
worker restart, lease loss, and duplicate delivery therefore converge on one
logical operation. The handler implements `RuntimeOperationHandler` and
`RunsWithoutRuntimeAdapter` and is routed only through
`telephony-infrastructure-worker`.

## Execution contract

Execution revalidates `desired_state = retired` before any infrastructure
mutation. It resolves the exact shared RNP-3 identities:

| Resource | Name |
| --- | --- |
| Deployment | `asterisk-<runtime-node-slug>-<sha256(runtime-node-id)[0:8]>` |
| Service | same deterministic base as Deployment |
| Secret | same deterministic base plus `-credentials` |

The slug is Kubernetes-safe and bounded; the RuntimeNode-ID digest provides
collision resistance. Before deletion, metadata-only inspection preflights
Deployment, Service, and Secret. Every existing object must be absent or
owned by the exact RNP-2 labels (`app.kubernetes.io/part-of=utcp` and
`utcp.dev/runtime-node=<runtime-node-slug>`). Any conflict fails the operation
before the first delete. Each writer delete rechecks ownership.

Owned resources are deleted through RNP-2 in this order: Deployment, Service,
Secret. Pods and ReplicaSets are not deleted directly. Already-absent objects
are successful no-ops, and a retry after any partial deletion continues from
the remaining owned objects. Success requires all three expected resources to
be absent; Pod garbage collection is asynchronous and is not part of the
operation boundary.

RNP-4 does not mutate the retired RuntimeNode, its endpoints, capabilities,
adapter configuration, workload identity, historical credential metadata, or
the provisioning request. RNM-owned RuntimeRegistry credential rows remain
historical records; only the Kubernetes bootstrap Secret is physically
deleted. Deprovision evidence records operation/resource metadata only and
never contains Secret material. Requested, succeeded, and failed lifecycle
events use the existing operation/audit/outbox conventions.

## Verification

The focused suite proves both RNM retirement corridors, external-runtime
protection, the RETIRED execution gate, deterministic operation scheduling,
ownership preflight with zero deletion on conflict, Deployment → Service →
Secret ordering, all-absent idempotency, transient partial-delete retry, and
historical preservation. RNP-3 provisioning, RNP-2 writer/security behavior,
RuntimeRegistry, and the repository-wide checks are run with the implementation.

No new Kubernetes resource kind, client, worker, identity, or RBAC verb is
introduced. No live managed-runtime lifecycle proof is claimed.
