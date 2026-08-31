# K5D — Telephony-Aware Host Maintenance Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `3f0be8b1dc889b3092661a67d506141f80b90d0e`
(`fix(k5): authorize maintenance pod eviction in core api group`)

Deployed HEAD: `3f0be8b1dc889b3092661a67d506141f80b90d0e`

## Verdict

`K5D_MAINTENANCE_OBSERVATION_RBAC_LIVE_DEFECT`

(§39 classification: *RBAC desired state deployed incorrectly → deployment/RBAC
defect*)

**K5D is not closed.** The eviction RBAC repair is confirmed live and the
eviction scope repair is confirmed live, but the maintenance ClusterRole is
missing `list` on `nodes`, which the coordinator's own reconcile path requires
before it can do anything else. The corridor telephony-drains correctly and then
stalls permanently at `blocked`.

The Kubernetes safety invariants all held: **no Node was cordoned, no Pod was
evicted, and existing telephony work was never terminated.** The telephony drain
completed *before* any cordon was attempted, so the ordering contract is
positively demonstrated.

## Deployment

Canonical native-k3s lifecycle only.

```text
lifecycle               server-image-sync -> server-config-check
                        -> server-image-preflight -> server-apply
UTCP_SERVER_API_IMAGE   ghcr.io/grimange/utcp-api@sha256:6cf5b583…29bb
UTCP_SERVER_WEB_IMAGE   ghcr.io/grimange/utcp-web@sha256:f0be2c38…689a
reconciler image        ghcr.io/grimange/utcp-api@sha256:6cf5b583…29bb
```

The `Native k3s Images` workflow was still in progress at packet start and was
**awaited** to `completed / success`. Promotion used the established explicit
`GH_REPO` workaround for the recorded `scripts/native-k3s/image-sync` `.git`
debt, which was not repaired here.

## Topology

```text
utcp-dev01  192.168.254.124  control-plane  Ready  schedulable
            uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee
utcp-dev02  192.168.254.125  agent          Ready  schedulable
            uid f56c93f5-52d9-4d18-8dc6-99166a2145f6
```

No topology label, taint, affinity, or storage change was made.

## K5D migrations

```text
2026_08_31_120000_create_k5d_host_maintenances   batch 8   (confirmed)
2026_08_31_121000_sync_k5d_identity_catalog      batch 8   (confirmed)
manual SQL to create state                       NONE
```

## Gate 1 — eviction RBAC repair CONFIRMED LIVE

The previously blocking API-group mismatch is fixed and deployed.

```text
live ClusterRole utcp-kubernetes-maintenance
  {"apiGroups":[""],"resources":["nodes"],"verbs":["get","patch"]}
  {"apiGroups":[""],"resources":["pods"],"verbs":["get","list"]}
  {"apiGroups":[""],"resources":["pods/eviction"],"verbs":["create"]}

stale policy-group pods/eviction rule remaining   NO
```

Authorization, verified three ways:

```text
SubjectAccessReview  group "" / pods / eviction / create   allowed: true
  reason: RBAC: allowed by ClusterRoleBinding "utcp-kubernetes-maintenance"

kubectl auth can-i create pods --subresource=eviction      yes

live probe against a NONEXISTENT Pod as the maintenance SA:
  Error from server (NotFound): pods "k5d-audit-nonexistent-pod" not found
```

That last line is the decisive change: the same probe returned **403 Forbidden**
before the repair and now returns **404 NotFound**, proving authorization now
succeeds and only the Pod is absent. Nothing could be evicted by the probe.

Note: `kubectl auth can-i create pods/eviction` still prints `no`. That slash
form is a kubectl argument-parsing artifact, not an authorization fact — the
SubjectAccessReview and the live endpoint both say allowed. `--subresource=eviction`
is the correct form.

## Gate 2 — eviction scope repair CONFIRMED LIVE

Maintenance target derived from live placement, not assumed: after deployment
convergence the RuntimeNode workload settled onto **`utcp-dev02`**, so that is
the target host.

```text
RuntimeNode   102d58ba-93ec-4601-a2a3-81f95801440f
              V1A Outbound Reproof Asterisk 1787825256   active/ready
              cv 24 == ocv 24, execution image converged
identity      utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
subject Pod   asterisk-v1a-outbound-reproof-…-58d765fxkc
              uid 8cd09466-ebd0-4047-8fe5-f28404f8ab63   on utcp-dev02
```

Reproducing the deployed predicate read-only for target `utcp-dev02`:

```text
SUBJECT (expected eviction) — 1 Pod
  utcp-runtime/asterisk-v1a-outbound-reproof-…-58d765fxkc   [asterisk-ari]

PROTECTED on the target host (part-of=utcp, no RuntimeNode identity match)
  utcp-platform/kamailio-registration-observer-57885dd54-wxncr
  utcp-platform/worker-c48869994-s5vvh
```

| Component | Expected | Result |
| --- | --- | --- |
| affected RuntimeNode Pod | IN SUBJECT | **IN SUBJECT** |
| API / Web / scheduler | OUT | **OUT** |
| telephony-reconciler | OUT | **OUT** |
| PostgreSQL / Redis | OUT | **OUT** |
| other `part-of: utcp` Pods on target host | OUT | **OUT** |

The coordinator, API, Web, scheduler, PostgreSQL and Redis all resided on
`utcp-dev01` at proof time, so their exclusion here is by host as well as by
identity. The load-bearing on-host evidence is that
`kamailio-registration-observer` and `worker` — both `part-of: utcp`, both on the
target host — are correctly **not** subjects.

## The blocking defect — coordinator cannot list Nodes

`HostMaintenanceService::reconcile()` calls
`$this->observation->listNodes()` on **every** pass to re-verify the target host.
`HttpKubernetesInfrastructureClient::listNodes()` issues `GET /api/v1/nodes`,
which requires the `list` verb on `nodes`.

The maintenance ClusterRole grants `nodes: ["get","patch"]` — **no `list`**:

```text
as system:serviceaccount:utcp-platform:utcp-kubernetes-maintenance
  get   nodes  yes
  list  nodes  NO
  watch nodes  no
  patch nodes  yes

SubjectAccessReview  verb list / group "" / resource nodes  ->  allowed: false
```

`KubernetesWorkloadClientException::forbidden()` maps to reason
`permission_denied`, which is exactly the recorded `failure_code`, and its
message is the recorded `failure_details`:

```text
failure_code     permission_denied
failure_details  Kubernetes infrastructure observation was denied.
```

### Two coordinators, two different denials

`reconcileDue()` is invoked from `ReconciliationWorker::workOnce()`, which runs
in **two** Pods with **different** ServiceAccounts. Neither can finish the
corridor:

```text
telephony-reconciler   SA utcp-kubernetes-maintenance
  can patch nodes and create evictions
  CANNOT list nodes -> fails immediately at listNodes()
     -> "Kubernetes infrastructure observation was denied."

scheduler              SA utcp-kubernetes-observer
  CAN list nodes/pods (infrastructure-reader) -> drives the telephony drain
  CANNOT patch nodes  -> fails at cordon()
```

The live audit trail shows exactly this interleaving:

```text
06:52:13  host_maintenance.requested
06:52:17  host_maintenance.blocked        (x40, every ~3s — reconciler passes)
   …
06:54:03  host_maintenance.telephony.drained   <- a scheduler pass got through
06:54:04  host_maintenance.cordoning
06:54:04  host_maintenance.blocked             <- cordon denied to the observer
   …      blocked repeats indefinitely
```

Maintenance can therefore never reach `cordoning -> draining_kubernetes ->
completed`, and the repaired eviction path is never reached at all.

## What the run did positively demonstrate

Despite the block, real corridor behaviour was proven on live infrastructure.

### Natural Web Admin request

Fresh natural login from the real login page (no session existed; no cookie,
storage, database or Redis session injection, no bypass), tenant selected,
natural sidebar navigation to **Hosts**. The Hosts surface rendered the real
maintenance context per host:

```text
utcp-dev01   Ready   Runtime Nodes: None                       Workloads: 20
utcp-dev02   Ready   Runtime Nodes: V1A Outbound Reproof …     Workloads: 3
                     Active telephony work: 1
             [Prepare for maintenance]
```

`Prepare for maintenance` was clicked on the `utcp-dev02` card. Exactly one
maintenance intent was persisted for the correct Node UID:

```text
id        1bf5e06a4248ece10c0e49f800943039
node_uid  f56c93f5-52d9-4d18-8dc6-99166a2145f6   (utcp-dev02)
node_name utcp-dev02
```

No manual SQL, no direct API substitution for the human action.

### RuntimeNode correlation

```text
runtime_node_ids  ["102d58ba-93ec-4601-a2a3-81f95801440f"]
```

Correct — the single RuntimeNode whose workload is on the target host.

### Existing work continued; drain ordering held

One natural bounded Call to the still-authoritative destination
`c537a4a7-…` (`sip:97001@38.146.161.46`) was active when maintenance was
requested (`Active telephony work: 1` was rendered in the UI). It was **not**
terminated by the maintenance request; it ended on its own:

```text
Call c9f700e1-b040-4504-9ae9-290edce1fc42
  terminated 06:52:36+00   termination_reason remote
```

The RuntimeNode then reached `drained` naturally, and only afterwards was cordon
attempted:

```text
06:54:03  telephony.drained
06:54:04  cordoning attempted
```

**No Kubernetes mutation occurred while active telephony work was greater than
zero.** The ordering contract is positively demonstrated.

### Nothing was cordoned or evicted

```text
utcp-dev01  unschedulable=false  taints=0  Ready
utcp-dev02  unschedulable=false  taints=0  Ready
subject Pod …-58d765fxkc         Running on utcp-dev02, never evicted
coordinator telephony-reconciler Running on utcp-dev01 throughout
all utcp-platform Pods           18/18 Running
```

## Secondary finding — a blocked maintenance is unrecoverable

`AdminHostMaintenanceController` exposes only `store` (request) and `index`
(list). There is **no cancel or abort endpoint**, although `cancelled` exists in
the schema and in both service queries. Consequently a blocked maintenance:

* keeps its RuntimeNode pinned at `drained`, since `request()` returns the
  existing open record and each reconcile re-asserts the drain;
* re-blocks every ~3 seconds indefinitely — 53 `host_maintenance.blocked` audit
  events were written in roughly three minutes;
* cannot be cleared through any canonical management path.

This is recorded as an observation for the repair packet, not as the primary
verdict.

## Bounded implementation target

For a separate packet. Not implemented here.

1. Add `list` to the `nodes` rule in
   `infrastructure/kubernetes/base/platform/kubernetes-maintenance-rbac.yaml`,
   since `HostMaintenanceService::reconcile()` requires `listNodes()` on every
   pass:

   ```yaml
   - apiGroups: [""]
     resources: ["nodes"]
     verbs: ["get", "list", "patch"]
   ```

2. Decide deliberately whether `reconcileDue()` should run in the `scheduler`
   Pod at all. Today it runs under the read-only observer identity, which can
   advance the telephony drain but can never cordon — so it drives state changes
   it cannot finish, and it is the reason the drain progressed while the
   coordinator was failing. Restricting maintenance reconciliation to the
   maintenance-capable coordinator would make the corridor single-authority and
   the failure mode unambiguous.

3. Consider a canonical way to end a blocked maintenance, and damp the
   per-tick `host_maintenance.blocked` audit writes.

The eviction scope, eviction RBAC, telephony ordering, ServiceAccount
separation, NetworkPolicy, storage, and K5C/K5E behaviour all remain unchanged.

## Post-proof environment restoration

Performed **only after** all acceptance evidence above was captured. It
manufactures no acceptance result — the verdict is a defect either way.

1. **Non-canonical, disclosed:** the blocked maintenance was set to `cancelled`
   with a single guarded statement, because no canonical cancel path exists and
   the record would otherwise have kept the RuntimeNode drained and rewritten
   `blocked` audit events every ~3 seconds indefinitely. Scoped by id, status
   and failure code so it could match nothing else:

   ```sql
   update k5d_host_maintenances set status='cancelled', phase='cancelled', updated_at=now()
   where id='1bf5e06a4248ece10c0e49f800943039'
     and status='blocked' and failure_code='permission_denied';
   -- UPDATE 1
   ```

   `cancelled` is the terminal status the service already excludes from
   reconciliation; the loop stopped immediately (last `blocked` event 06:55:16,
   none since).

2. **Canonical:** the RuntimeNode was returned to service through the Web Admin
   **Reactivate** control on Telephony Nodes — no SQL, no Artisan.

Final verified state:

```text
RuntimeNode 102d58ba-…    active / ready
maintenance record        cancelled (terminal, not reconciled)
utcp-dev01 / utcp-dev02   Ready, schedulable, no taints
inbound eligibility       available_capacity 100, active work 0
utcp-platform Pods        18/18 Running
Kubernetes mutations by the product   NONE
```

Proof Calls, CallLegs and audit history were retained as legitimate canonical
evidence; nothing was scrubbed.

## Natural-live-proven versus not proven

Proven live in this packet:

```text
repaired current main deployed through the canonical lifecycle
K5D migrations confirmed applied, no manual SQL
maintenance ServiceAccount is utcp-kubernetes-maintenance
core-group pods/eviction authorization now granted (403 -> 404 probe)
no stale policy-group eviction rule remains
observer still read-only; utcp-platform-app not widened
eviction scope selects exactly the affected RuntimeNode workload
same-host part-of=utcp Pods are correctly not subjects
natural Web Admin login and Hosts navigation
Hosts surface renders affected RuntimeNode and active telephony work
maintenance requested through Web Admin; one intent for the correct Node UID
RuntimeNode correlation correct
existing Call not terminated by the maintenance request
RuntimeNode reached DRAINED naturally
no Kubernetes mutation while active telephony work > 0
```

Not proven — blocked by the defect:

```text
cordon
subject Pod eviction
non-subject survival through an actual eviction pass
post-eviction reconciliation to completed
replacement Pod creation and scheduling
automatic placement observation of a replacement
full audit lifecycle through completed
```

None of these is claimed. `K5E` remains **NOT STARTED**; no K5E-supporting
replacement evidence was produced because no eviction occurred.

## Roadmap impact

```text
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5D   IMPLEMENTED_AND_TESTED
      EVICTION SCOPE REPAIR VERIFIED LIVE
      EVICTION RBAC REPAIR VERIFIED LIVE
      NATURAL LIVE PROOF BLOCKED — K5D_MAINTENANCE_OBSERVATION_RBAC_LIVE_DEFECT
K5E   NOT STARTED
K5F   POST-K5E / UNCHANGED
```

**Exactly one next action:** bounded implementation granting `list` on `nodes`
to the maintenance ClusterRole and settling whether maintenance reconciliation
should run in the scheduler Pod, after which this acceptance corridor re-runs
unchanged.
