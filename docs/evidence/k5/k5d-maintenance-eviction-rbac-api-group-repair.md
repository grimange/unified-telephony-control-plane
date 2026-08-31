# K5D — Maintenance Eviction RBAC API-Group Repair

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `b6b3bdb13ace059d99cf296e9b16191e245bc634`
(`docs(k5): isolate maintenance eviction RBAC live defect`)

Task classification: `BOUNDED IMPLEMENTATION`

## Prior live defect

The deployed maintenance ClusterRole authorized:

```yaml
apiGroups: ["policy"]
resources: ["pods/eviction"]
verbs: ["create"]
```

The maintenance client correctly sends a `POST` to the core API endpoint
`/api/v1/namespaces/{namespace}/pods/{name}/eviction` with an Eviction body
whose representation is `policy/v1`. Kubernetes authorizes that Pod
subresource in the core API group (`""`), not the `policy` group. The previous
natural proof therefore reached the endpoint and was denied before any Pod
mutation.

## Bounded repair

The role now grants exactly:

```yaml
apiGroups: [""]
resources: ["pods/eviction"]
verbs: ["create"]
```

The incorrect `policy` rule was removed. Node `get/patch`, Pod `get/list`, the
dedicated maintenance identity, observer isolation, RuntimeNode-scoped
eviction, Policy/v1 request handling, PDB behavior, and telephony drain
ordering are unchanged. No delete, wildcard, Secret, controller-mutation, or
cluster-admin privilege was added.

## Validation and proof boundary

The structured `RuntimeFencingManifestTest` now asserts the core API-group
tuple and rejects the prior `policy`-group tuple. The rendered manifest was
validated through Kustomize rendering, and the native-k3s configuration check,
repository hygiene, phase-status consistency, and API suite passed. The
generic K1/security checks reached their static checks but could not complete
their live API-dependent portion because this environment has no Kubernetes API
at `127.0.0.1:6550`.

No live RBAC mutation, maintenance request, cordon, eviction, or K5D natural
acceptance was performed in this packet. K5D remains:

```text
IMPLEMENTED_AND_TESTED
EVICTION SCOPE REPAIR VERIFIED LIVE
EVICTION RBAC DEFECT REPAIRED
NATURAL LIVE PROOF PENDING
```

Exactly one next action is to deploy repaired current main to canonical native
k3s and resume the K5D controlled natural live proof.
