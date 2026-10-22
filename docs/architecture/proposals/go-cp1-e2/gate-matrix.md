# GO-CP1 E2 — source-backed Asterisk authorization gates

Status: isolated implementation and developer-test scope. Parent: [E2 report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md).
The unchanged Laravel canonical verifier is the decision oracle. Authorization
permits a future bounded effect; it does not prove fencing, restoration, workload
readiness or a completed telephony operation.

## Source and authority

- [CanonicalInfrastructureService::assertDomainPolicy](../../../../apps/api/app/ControlPlane/InfrastructureAuthority/CanonicalInfrastructureService.php)
  applies the pre-dispatch domain predicates; `verify` checks persisted full-envelope authority and returns an authorized boolean.
- [TelephonyDomainService::hasDistinctEligibleReplacement / selectRuntimeNodeForConference](../../../../apps/api/app/TelephonyDomain/TelephonyDomainService.php)
  selects an eligible alternative using capabilities, capacity and failure-domain facts.
- [RuntimeNodeWorkloadService](../../../../apps/api/app/RuntimeRegistry/RuntimeNodeWorkloadService.php)
  counts active conference bindings and nonterminal CallLegs.
- [RuntimeNodeFailureDomainEvaluator::eligible](../../../../apps/api/app/RuntimeRegistry/RuntimeNodeFailureDomainEvaluator.php)
  evaluates declared versus observed placement.
- [Go AsteriskDomainPolicy](../../../../apps/control-plane/internal/kernel/asterisk_policy.go)
  implements these predicates; [claims.go](../../../../apps/control-plane/internal/kernel/claims.go)
  retains full-envelope, lifecycle and cross-version checks.

All predicates in a decision must hold together in the same PostgreSQL transaction.
The Go service acquires its fixed predicate guard before RuntimeNode → incarnation
→ intent → operation row locks. No external network operation occurs inside it.
Operation types and capability keys match the current repository configuration;
arbitrary future PHP configuration overrides are not covered by this port.

## Fencing predicates

| PHP source / predicate | Canonical authority | Authorization / rejection | Go implementation | Permanent evidence |
| --- | --- | --- | --- | --- |
| `assertDomainPolicy`: family/profile | `runtime_nodes`, `infrastructure_incarnations` | Asterisk and `asterisk.managed.v1`; reject another family/profile | `Check`, `policyCheck` | Oracle family/profile negatives; FreeSWITCH remains denied |
| `assertDomainPolicy`: operation corridor | `runtime_operations`, approved intent | Fence accepts `admit_generation` or `set_replicas` only | `policyCheck` | Independent oracle and existing cross-version tests |
| `assertDomainPolicy`: observed state | `runtime_nodes.observed_state` | Any source-supported state except `ready`; unobserved/unavailable can qualify | `Check` | Positive unobserved/unavailable; ready negative; readiness race |
| `assertDomainPolicy`: effect | Approved infrastructure request | Exactly zero replicas for fencing | `Check` | Replica mismatch oracle |
| `assertDomainPolicy`: former identity | Canonical operation payload | Former node equals current RuntimeNode; conference/binding/configuration generation supplied | `Check` | Missing/wrong payload oracle |
| `assertDomainPolicy`: conference | `conferences` | Same tenant, open, assigned former node, exact configuration generation | `Check` SQL | Conference state/generation/identity negatives; generation race |
| `assertDomainPolicy`: binding | `conference_runtime_bindings` | Exact same-tenant conference/node binding with `active` status | `Check` SQL | Binding negatives and binding-release race |
| `hasDistinctEligibleReplacement` | `runtime_nodes` | Distinct same-tenant node, desired active, observed ready | `asteriskReplacement` | No alternative, cross-tenant, inactive/unavailable negatives; availability race |
| `selectRuntimeNodeForConference`: capabilities | `runtime_node_capabilities` | Both `conference.lifecycle` and `conference.participation` | `asteriskReplacement` | Individual capability negatives and removal race |
| Capacity evaluator and workload service | Node capacity, bindings, CallLegs | Null/zero capacity means unlimited. Otherwise active bindings excluding this conference plus nonterminal CallLegs must be below capacity | `asteriskReplacement` | Unlimited positive; capacity negative; inserted CallLeg/binding races |
| Failure-domain evaluator: unconstrained | Node placement declarations | No declared region and zone permits missing placement observation | `asteriskReplacement` | No-observation positive |
| Failure-domain evaluator: constrained | `runtime_node_k5c_placement_observations` | `placed` or `ambiguous_multiple_nodes_observed`, matching each declared dimension; missing/mismatched/unavailable placement denies | `asteriskReplacement` | Independent placed, ambiguous-multiple-nodes and stale-placed positive parity; wrong-zone/unavailable negatives and newly constrained placement race |
| Candidate ordering | Eligible alternative set | PHP ranks candidates and rechecks; authorization requires existence, not a particular chosen alternative | `asteriskReplacement` boolean existence | Source inspection plus persisted independent oracle |

The former node's active conference is the fencing context. Laravel does not
require zero active work on that former node. Capacity applies to the eligible
replacement; another RuntimeNode's existence alone is insufficient. The source
replacement search has no vendor-family restriction beyond required capabilities.
No additional desired-state or freshness predicate is invented for fencing.

## Restoration predicates

| PHP source / predicate | Canonical authority | Authorization / rejection | Go implementation | Permanent evidence |
| --- | --- | --- | --- | --- |
| `assertDomainPolicy`: family and desired state | Current RuntimeNode/profile | Asterisk profile, desired `disabled`, positive replica target | `Check` | Positive restore; desired/profile/replica negatives |
| Payload correlation | Persisted current operation | Tenant/node exact; requested state `active`; namespace/name exact; integer target replicas exact | `Check` | Individual target/tenant/node/state/replica oracle cases |
| Current configuration | `runtime_nodes.configuration_version` | Payload expected configuration version matches current version | `Check` | Configuration negative, concurrent update, restart invalidation |
| Qualifying source fence | `runtime_operations` | Exact supplied source ID; same tenant/node; fence type; succeeded with completion timestamp | `asteriskRestoreSource` | Missing/failed/incomplete/type/tenant/node negatives |
| Disabled provenance | Source payload | Map, `by_operation=true`, source operation and RuntimeNode IDs exact | `asteriskRestoreSource` | Missing/false/wrong operation/node negatives |
| Scale provenance | Source payload | Map, source operation, namespace, Deployment and integer pre-scale target exact | `asteriskRestoreSource` | Missing/wrong source/target/replica negatives |
| Source generation | Source/request payload | Source configuration generation equals requested source-fence generation under supported scalar representation | `policyComparableScalar` | Wrong-generation and malformed composite negatives; scalar unit tests |
| Competing fence | `runtime_operations` | No other fence in pending/leased/running/retry_scheduled | `asteriskRestoreSource` | Competing-fence oracle and concurrent insert/source-status tests |
| Current open conference work | `conference_runtime_bindings` joined `conferences` | No active binding to an open conference on restored node | `asteriskRestoreSource` | Binding negative and concurrent binding insertion |
| Current immutable target | Incarnation, persisted intent, request | Current bound UID/incarnation, target and generation remain exact | `target`, `verifyLocked` | Identity negatives, replacement rejection, retirement and supersession tests |

Restoration is not authorized by replicas being zero. A source fence provides
bounded provenance only in conjunction with the current full-envelope authority.
The source verifier does not enforce a source age limit, require that supplied
source be the latest successful fence, or store source UID/incarnation in this
legacy provenance. It checks conflicting *in-progress* fences. Current target
binding and never-reused physical names remain separate identity protections.
Do not describe the legacy source record as stronger immutable evidence.

PHP string-coerces source generation scalars, including missing values to empty
strings. Go preserves supported canonical integer/string comparisons and the
legacy both-missing comparison. Go rejects arrays/objects and numeric decimal,
exponent or overflow forms rather than treating them as generation provenance.
These malformed numeric forms are a deliberate fail-closed boundary, not a claim
of equivalence for every PHP coercion. Canonical valid generation strings remain
lossless, including `9007199254740993`.

## Full-envelope and version gates

The Asterisk policy cannot override operation status, attempt/token/expiry,
request deadline, tenant/node, cluster/namespace/name/slug/target ID, bound UID,
incarnation, contract/action, current generation/fingerprint, persisted intent,
supersession or canonical lifecycle. V1 nonzero scaling requires confirmed
applied V3 provider-projection evidence. V2 inert projection cannot become a
nonzero active projection. V3 requires the current approved profile and exact
canonical configuration. Retiring/retired or unbound activation remains denied.
`Verify`, `Reserve` and a first `Consume` recheck current policy without positive
caching. A duplicate already-consumed request returns status without a new grant;
that status is not a new authorization decision.

## Handler execution versus canonical authorization

[RuntimeFenceOperationHandler::execute](../../../../apps/api/app/Infrastructure/RuntimeFencing/RuntimeFenceOperationHandler.php)
performs current conference/binding/replacement checks, executes its adapter and
records self-scale provenance. Registry disablement follows successful fencing.
[RuntimeNodeRestoreOperationHandler::execute](../../../../apps/api/app/Infrastructure/RuntimeFencing/RuntimeNodeRestoreOperationHandler.php)
checks owned Deployment/Pods and source authority, requests scaling, then waits
for Deployment/Pod readiness, a current listener lease, a fresh open connection
epoch and node readiness before successful restoration.
[RuntimeRegistryService::disableAfterSuccessfulFence / completeRestorationActivation](../../../../apps/api/app/RuntimeRegistry/RuntimeRegistryService.php)
owns corresponding canonical state changes. These completion gates are not
predispatch authorization. E2 does not port runtime execution or claim actual
restoration completion, ARI connectivity, Pod readiness or live K5 proof.

## Missing and stale facts

Unknown former-node health is not relabeled healthy: it remains a state which
this specific fencing policy can intentionally authorize when the conference,
binding and alternative predicates hold. Missing constrained placement denies;
missing unconstrained placement is allowed. Capacity null/zero is an explicit
source rule, not inferred empty evidence. Laravel's canonical readiness and K5C
placement policy has no TTL here; stale persisted `placed` evidence can qualify.
This inherited freshness limitation remains visible for future production
integration. SQL errors do not become empty safe results.
