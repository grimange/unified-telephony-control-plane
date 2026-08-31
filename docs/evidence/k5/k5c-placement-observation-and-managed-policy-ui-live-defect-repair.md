# K5C Placement Observation and Managed Policy UI Live-Defect Repair

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `bf31cb2238905927b72b048351ae7732e234a41f`
(`docs(k5): isolate capacity policy live defects`)

## Scope and live blocker context

The controlled K5C natural-live proof isolated two product defects. This
bounded repair changes only the access paths required to reprove already
implemented K5C behavior. K5C selection semantics, placement observation
normalization, and the backend RuntimeNode mutation authority are unchanged.

## Defect A — placement observation authority

The scheduler previously ran as `utcp-platform-app` with
`automountServiceAccountToken: false`. That ServiceAccount was not bound to the
existing `utcp-infrastructure-reader` ClusterRole, so the automatic
`K5CPlacementObservationService` could not authenticate to Kubernetes or read
Node/Pod facts. The existing role already provides the required least-privilege
authority: `get` and `list` for core `nodes` and `pods` only.

The repair adds `utcp-kubernetes-observer`, binds it explicitly to the existing
read-only infrastructure-reader role, and runs the scheduler with that
identity and native projected ServiceAccount credentials. The scheduler Pod
also carries the existing `utcp.io/kubernetes-api-client: "true"` classification
used by the Kubernetes API egress policy. No static token, kubeconfig,
write-capable permission, broad network bypass, or scheduler security-context
relaxation was added. `utcp-platform-app` remains unbound to infrastructure
reader authority and tokenless.

## Defect B — managed RuntimeNode policy controls

The Web Admin previously hid the entire RuntimeNode edit form whenever a node
was managed. That form included valid K5C desired policy fields even though the
backend already permits those fields for managed RuntimeNodes.

The form now separates `Telephony policy` from `Runtime integration`. Capacity,
placement priority, desired region, and desired zone remain editable for an
authorized, non-retired RuntimeNode regardless of managed mode. Managed-mode
integration identity remains protected by the existing condition; no new
endpoint or mutation authority was introduced. External RuntimeNode behavior
and retired-node lifecycle restrictions remain unchanged.

## Verification

Focused manifest assertions verify the dedicated ServiceAccount, native token
mount, Kubernetes API-client classification, explicit role binding, exact
read-only Node/Pod permissions, and `utcp-platform-app` isolation. Focused Web
Admin assertions verify the policy/integration split and managed policy-field
visibility. The repository security/config check and frontend/backend suites
are the acceptance checks for this repair.

## Boundaries preserved

No K5C selector or observation algorithm changed. No RuntimeNode readiness
authority changed. No manual reconciliation, durable Host authority, static
credential, Kubernetes scheduler mutation, K5D/K5E behavior, reporting work, or
new management interface was added.

K5C remains `IMPLEMENTED_AND_TESTED` with its natural live reproof pending.
The next action is to deploy the repaired current `main` to canonical native
k3s and rerun the existing controlled natural K5C acceptance.
