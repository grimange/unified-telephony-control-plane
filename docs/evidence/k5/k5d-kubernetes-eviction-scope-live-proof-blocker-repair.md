# K5D — Kubernetes Eviction-Scope Live-Proof Blocker Repair

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `f7aebcdeac95d436445b0975071b54a15c41ad2d`
(`docs(k5): isolate host drain scope live-proof blocker`)

Task classification: `BOUNDED IMPLEMENTATION`

## Verdict

`K5D_KUBERNETES_EVICTION_SCOPE_LIVE_PROOF_BLOCKER_REPAIRED_AND_TESTED`

The prior natural-proof blocker was the broad `app.kubernetes.io/part-of=utcp`
eviction selector. It selected 22 Pods on the observed host, including the
maintenance coordinator, scheduler, API, Web, PostgreSQL, Redis, and other
platform workloads, although only one Pod represented an affected RuntimeNode.
No natural acceptance was run in this packet.

## Positive maintenance subject

`HostMaintenanceService` now resolves the affected RuntimeNodes from observed
Kubernetes Pod placement and canonical `RuntimeNodeWorkloadIdentityResolver`
identities. It passes the resulting, deduplicated namespace/deployment identity
set to `KubernetesMaintenanceClient::drainablePods()`.

`HttpKubernetesMaintenanceClient` remains an infrastructure adapter: it does
not query PostgreSQL or reconstruct RuntimeNode authority. It lists Pods on the
target Node, requires the existing UTCP workload label plus an exact supplied
namespace/deployment identity, and then applies the existing DaemonSet, mirror,
terminating, and terminal-Pod exclusions. A `part-of: utcp` label alone is not
sufficient.

The maintenance coordinator is therefore outside the positive RuntimeNode
subject set, as are API, Web, scheduler, telephony-reconciler, PostgreSQL,
Redis, and unaffected RuntimeNode workload identities. Affected RuntimeNode
Pods on the target Node remain eligible for Policy/v1 eviction; matching Pods
on other Nodes are not selected by this host pass.

The evidence wording “controller-managed Pods” is corrected to the precise
canonical RuntimeNode-workload identity criterion. No generic protected
component allowlist, environment gate, single/multi-node branch, or manual
runtime allowlist was added.

The previously inert `utcp.io/k5d-maintenance-client` label was removed from
the telephony reconciler manifest. Positive RuntimeNode workload identity now
provides the eviction scope; the coordinator remains outside that scope by
identity rather than by a duplicated protected-component rule.

## Preserved K5D behavior and security

The telephony ordering remains unchanged:

```text
maintenance intent
→ affected RuntimeNodes
→ new-work exclusion
→ ACTIVE → DRAINING → DRAINED
→ Kubernetes cordon
→ bounded target-host RuntimeNode workload eviction
```

Existing work is not terminated. Cordon remains after telephony drain, Policy/v1
eviction and PDB behavior are unchanged, and the coordinator remains available
for the later reconciliation pass because it is not an eviction subject.
Observer and maintenance RBAC, ServiceAccounts, NetworkPolicy, storage, K5C,
K5E, and K5F behavior are unchanged. No production `kubectl`, Kubernetes
privilege broadening, or storage redesign was introduced.

## Validation

Focused adapter and maintenance-contract tests cover positive RuntimeNode
selection, unaffected RuntimeNode exclusion, target-Node enforcement, platform
and state-store exclusion, self-eviction safety, and preserved Pod exclusions.
The backend suite and repository consistency/hygiene checks are required for
handoff. No live Kubernetes deployment or K5D natural acceptance was performed.

K5D remains:

```text
IMPLEMENTED_AND_TESTED
LIVE-PROOF BLOCKER REPAIRED
NATURAL LIVE PROOF PENDING
```

Exactly one next action is to deploy repaired current main to canonical native
k3s and perform controlled K5D telephony-aware host-maintenance natural
acceptance.
