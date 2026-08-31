# K5C — Capacity / Failure-Domain Policy Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `9dd173aac3a861113a23286a0c5592cb83e89d1b`
(`fix(k5): repair inbound capacity and policy clearing`)

Deployed HEAD: `9dd173aac3a861113a23286a0c5592cb83e89d1b`

## Verdict

`K5C_CAPACITY_FAILURE_DOMAIN_POLICY_NATURAL_LIVE_PROVEN`

**K5C is complete.** Every live defect isolated by the two preceding proofs is
repaired and re-proven on real infrastructure through the canonical corridor. No
production source was changed by this proof, no manual SQL recovery was used, no
Artisan K5C management was invoked, and every temporary policy value was
restored through the Web Admin itself.

## Deployment

Canonical native-k3s lifecycle only.

```text
lifecycle               server-image-sync -> server-config-check
                        -> server-image-preflight -> server-apply
UTCP_SERVER_API_IMAGE   ghcr.io/grimange/utcp-api@sha256:86986746…f424
UTCP_SERVER_WEB_IMAGE   ghcr.io/grimange/utcp-web@sha256:7bbb1be7…b6ca
scheduler image         ghcr.io/grimange/utcp-api@sha256:86986746…f424
kamailio Pod            kamailio-676b88d969-wzjhl, restarted 01:52:18Z on the
                        new rendered config checksum
```

The `Native k3s Images` workflow was still in progress at packet start; it was
**awaited** to `completed / success` rather than worked around. Promotion used
the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing debt, which was not
repaired here.

Canonical environment, unchanged:

```text
Kubernetes   native k3s v1.36.3+k3s1
context      default
Node         utcp-dev01   InternalIP 192.168.254.124   Ready True
```

## Forward repair migration

```text
2026_08_31_100000_repair_k5c_inbound_capacity_and_ordering   batch 7
applied through the normal utcp-migrate Job
manual SQL required   NO
manual view replacement   NONE
```

The live view definition (`pg_get_viewdef`) now carries the capacity predicate
and the complete K5C ordering tuple:

```text
... AND (n.capacity_weight = 0 OR n.active_telephony_work < n.capacity_weight)
ORDER BY n.placement_priority,
         (CASE WHEN n.capacity_weight = 0 THEN 2147483647
               ELSE GREATEST(0, n.capacity_weight - n.active_telephony_work) END) DESC,
         n.active_telephony_work,
         n.id
```

## Deployed Kamailio consumer

Read from the **live** ConfigMap in the cluster, not repository source:

```text
select runtime_node_id, sip_target, available_capacity, active_telephony_work
from kamailio_inbound_runtime_target_view
where tenant_id = '$dbr(external_trunk_route=>[0,0])'
order by placement_priority asc, available_capacity desc,
         active_telephony_work asc, runtime_node_id asc
```

The consumer now selects the capacity/load columns and consumes the full
canonical ordering tuple. The previous `order by placement_priority,
runtime_node_id` is gone. **Deployment contract: PASS.**

## Observer spot-check (Defect A regression guard)

```text
scheduler Pod   scheduler-59686dd58f-7877m
ServiceAccount  utcp-kubernetes-observer     automount true
label           utcp.io/kubernetes-api-client "true"

get nodes / list nodes                yes / yes
get pods / list pods (all-ns)         yes / yes
patch nodes / delete pods / create pods   no / no / no

utcp-platform-app list nodes          no
utcp-platform-app list pods (all-ns)  no

observer cycle   2026-08-31 01:54:04 runtime-engine:k5c-placement-observer DONE
errors           no 401, no 403, no missing token, no TLS/CA failure,
                 no NetworkPolicy timeout
```

**PASS.** Least privilege intact; the shared platform identity gained nothing.

## Automatic placement projection

| Fact | Kubernetes | K5C projection |
| --- | --- | --- |
| status | one Pod on one Node | `placed` |
| Node UID | `faa05d1c-35fd-48fa-a2f7-6060d845c9ee` | same |
| Node name | `utcp-dev01` | same |
| region label | absent | `ABSENT` |
| zone label | absent | `ABSENT` |

The second RuntimeNode remains a natural positive control at
`no_managed_kubernetes_identity`. No manual projection command, SQL mutation, or
UI refresh was used as authority. **PASS.**

## Managed RuntimeNode and originals

```text
RuntimeNode        102d58ba-93ec-4601-a2a3-81f95801440f
name               V1A Outbound Reproof Asterisk 1787825256
tenant             342ee3b1-5b74-4964-8113-15030a61fda3  (Local Tenant)
runtime / adapter  asterisk / asterisk-ari
management.mode    managed
desired / observed active / ready

ORIGINAL capacity_weight     100
ORIGINAL placement_priority  100
ORIGINAL placement_region    NULL
ORIGINAL placement_zone      NULL

active conference bindings    0
active non-terminal CallLegs  0
total active telephony work   0
```

## Managed policy UI spot-check (Defect B regression guard)

Fresh natural login: a session persisted in the browser profile, so it was
**logged out first** and re-authenticated from the real login page. No cookie,
localStorage, sessionStorage, database, or Redis session injection; no bypass.
Natural sidebar navigation to Telephony Nodes, then Details on the managed node.

```text
Telephony policy section
  Placement region     present, enabled, not readonly
  Placement zone       present, enabled, not readonly
  Placement priority   present, enabled, not readonly, 100
  Capacity weight      present, enabled, not readonly, 100
  Save runtime details present

forms on page                     1  (only those four fields)
protected controls found          none
  (no Display name, Runtime integration, Adapter, Endpoint,
   Credential, Secret, Capabilities, Rotate)
Placement and infrastructure (K5B) section   0 controls, 0 forms
```

**PASS.** Policy editable, integration identity protected, K5B still read-only.

## Deployment-convergence handling

Immediately after `server-apply` the inbound view returned zero rows. This was
correctly classified as the known, separate **runtime deployment-convergence**
window and not as a K5C effect: the managed Asterisk workload had been
re-imaged, so

```text
desired_execution_image   ghcr.io/grimange/utcp-asterisk@sha256:37524a98…a260
observed_execution_image  sha256:a305bfb9…3b65
```

and the view's pre-existing execution-image convergence predicate excluded the
node. Convergence was **awaited**, never bypassed; once
`observed_execution_image` matched, baseline eligibility returned on its own.
The same discipline was applied after each policy save, where
`configuration_version` transiently exceeds `observed_configuration_version`:
every K5C exclusion claimed below was captured with `cv == ocv` so that
exclusion is attributable to K5C policy alone and never to convergence.

## Baseline eligibility

```text
Node region/zone labels   genuinely absent
placement_region/zone     NULL
inbound view              102d58ba-…  priority 100  available_capacity 100
                          active_telephony_work 0
```

Missing observed topology with no desired constraint carries **no K5C penalty**,
proven through the real production view. **PASS.**

## Capacity corridor

Active work was 0, so the smallest reversible natural scenario was used: one
bounded canonical Call against `capacity_weight = 1`. The repository-authoritative
destination was re-confirmed active before use —
`c537a4a7-af3d-474f-bf19-4be4aeaae2cf` = `sip:97001@38.146.161.46`.

Capacity was changed through the managed node's own `Telephony policy` form and
Save — never SQL, never a direct API call as substitute:

```text
Web Admin capacity_weight 100 -> 1    saved, canonical readback 1
convergence awaited                   cv 17 == ocv 17
inbound view before the Call          available_capacity 1, work 0 (eligible)
```

### Full-capacity window, captured live at 01:56:30

```text
Call 1   7283f77b-08ae-40cd-9d8b-947888e78551   201 Created, originating
         runtime_node_id omitted -> automatic selection chose 102d58ba-…
CallLeg  4dc6646e-847f-424d-8b49-0c66f34b12ee   originating

capacity_weight 1   cv 17   ocv 17   desired active   observed ready
active_telephony_work 1     -> 1 < 1 false -> FULL

INBOUND VIEW UNDER FULL CAPACITY   ZERO ROWS -- TARGET ABSENT
K5C projection during exclusion    placed, utcp-dev01, region ABSENT
```

**Defect C1 repair proven live.** The full RuntimeNode no longer appears with
`available_capacity = 0`; it is absent from the view entirely.

### Outbound under full capacity

```text
Call 2 attempt (identical canonical corridor, same destination)
  HTTP 422  "No eligible runtime node is available for outbound call execution."
```

**Three-corridor capacity parity restored:** conference and outbound share the
evaluator, and inbound now agrees.

### Existing work safety

Call 1 continued through the full-capacity state and the rejected Call 2
attempt and ended on its own:

```text
Call 1   terminated 2026-08-31 01:57:12+00   termination_reason remote
CallLeg  completed   (01:56:30 -> 01:57:12, ~42 s)
```

Not terminated by K5C admission policy. **PASS.**

### Active CallLeg effect and terminal release

```text
before Call    active non-terminal CallLegs 0   active work 0
during Call    active non-terminal CallLegs 1   active work 1   -> FULL
after terminal active non-terminal CallLegs 0   active work 0
inbound view   102d58ba-…  available_capacity 1, work 0  (present again)
```

Eligibility returned automatically with no Artisan, SQL, projection command, Pod
restart, or deployment restart. **PASS.**

## Sequential policy-save integrity (Defect E repair)

Performed without any page reload between saves — the exact condition that
previously replayed stale values.

### Form reseeding from the canonical response

After the capacity save, the still-open form showed `capacity 1`, matching
canonical state rather than the pre-save value. Previously it retained the stale
value.

### Capacity then region

```text
save capacity 100 -> 7        canonical readback  capacity_weight 7
edit ONLY region, save        canonical readback  capacity_weight 7
                                                  placement_region k5c-live-proof-region
```

Capacity was **not** silently replayed. **PASS.**

### Region then capacity

```text
region and zone cleared       canonical readback  both NULL
edit ONLY capacity 7 -> 100, save
                              canonical readback  capacity_weight 100
                                                  placement_region NULL
                                                  placement_zone   NULL
```

No stale topology value was replayed. **PASS.**

## Failure-domain corridor

Capacity was at `7` with active work `0` throughout, so every topology result is
attributable to topology alone.

### Desired constraint applied naturally

```text
Web Admin -> Telephony policy -> Placement region = k5c-live-proof-region -> Save
canonical readback   placement_region = k5c-live-proof-region
```

No Kubernetes Node label was added to match it.

### Desired versus observed authority separation

```text
UTCP desired    placement_region  k5c-live-proof-region
K5C observed    status placed, observed_region ABSENT, observed_zone ABSENT
Kubernetes      topology/region/zone labels: none (unchanged)
```

The desired constraint never contaminated observed topology, and the observer
kept refreshing real facts. **PASS.**

### Exclusion under unsatisfied constraint

```text
region constraint, cv 20 == ocv 20, capacity 7, active work 0
  inbound view    0 rows                    -> TARGET ABSENT
  outbound        HTTP 422 unavailable      -> TARGET EXCLUDED

zone constraint (region NULL), separately applied
  canonical readback  placement_zone k5c-live-proof-zone
  inbound view        0 rows                -> TARGET ABSENT
```

Both desired fields participate independently and are distinguishable because
each was applied and observed on its own. **PASS.**

### RuntimeNode readiness preservation

Throughout topology exclusion:

```text
RuntimeNode  desired_state active   observed_state ready
K5C eligibility  false
```

Readiness was never rewritten by an unsatisfiable topology constraint. **PASS.**

## Canonical constraint clear (Defect D repair)

The decisive test, through the same managed Web Admin form, no SQL, no special
endpoint:

```text
clear Placement region -> Save
  canonical readback   placement_region IS NULL  ->  t
                       capacity_weight 100? no — 7 preserved correctly
                       cv 21 == ocv 21

clear Placement zone  -> Save
  canonical readback   placement_region IS NULL  ->  t
                       placement_zone   IS NULL  ->  t
```

**A K5C failure-domain constraint can now be cleared through the canonical
management path and the clear persists as a real NULL.**

### Automatic eligibility recovery after clear

```text
inbound view    102d58ba-…  priority 100  available_capacity 7  work 0
outbound        201 Created  039f31d8-c9f9-4bf4-90d4-6679aa646832
```

No manual reconcile, no restart, no SQL recovery. **PASS.**

## Conference corridor

```text
conference K5C capacity parity:
REGRESSION-PROVEN
NATURAL-CONFERENCE-SCENARIO-NOT-AVAILABLE
```

No natural conference binding exists in this environment and none was
manufactured. Conference and outbound share the same evaluator, and outbound is
natural-live-proven here.

## Deterministic ordering

```text
SINGLE-CANDIDATE NATURAL ENVIRONMENT
```

Only one eligible RuntimeNode exists; no artificial RuntimeNode was created. The
multi-candidate tie-break sequence is proven by the **deployed** view definition
and the **deployed** Kamailio query above, both carrying the full
`placement_priority asc, available_capacity desc, active_telephony_work asc,
runtime_node_id asc` tuple, plus repository regression coverage. Behavioural
multi-candidate ranking remains REGRESSION-PROVEN, not natural-live-proven.

## Provider behaviour separated from K5C

The final recovery Call was correctly selected and created (`201 Created`), then
terminated `origination_timeout` at 02:00:02. Provider-side outcome is separate
from K5C eligibility; what K5C proves is that the candidate was selected. The
earlier proof Call completed normally (`remote`).

## Restoration and final state

Every temporary value was restored **through the Web Admin form**, and verified
by canonical readback. No SQL recovery was used anywhere in this packet.

```text
FINAL capacity_weight     100    (original)
FINAL placement_priority  100    (original, never changed)
FINAL placement_region    NULL   (original)
FINAL placement_zone      NULL   (original)
desired / observed        active / ready      cv 24 == ocv 24
inbound view              eligible, available_capacity 100, active work 0
K5C projection            placed, utcp-dev01, region ABSENT, zone ABSENT
Kubernetes topology labels  none added
all utcp-platform Pods      Ready (api, web, gateway, scheduler, worker,
                            kamailio, reverb, rtpengine, observers, workers)
```

Proof Calls, CallLegs, and audit history were retained as legitimate canonical
evidence; nothing was deleted.

## Natural-live-proven versus regression-proven

Natural-live-proven in this packet:

```text
canonical native-k3s deployment of repaired main
forward repair migration applied through the normal lifecycle, no manual SQL
live view carries the capacity predicate and full K5C ordering tuple
deployed Kamailio consumer selects capacity/load and orders by the full tuple
scheduler observer identity, least privilege, and shared-SA isolation
automatic placement projection agreeing with Kubernetes
managed RuntimeNode K5C policy controls; integration identity protected
missing topology + no constraint -> eligible, no K5C penalty
active CallLeg raises shared active telephony work
finite full capacity -> outbound excluded
finite full capacity -> inbound target ABSENT from the production view
existing work not terminated by admission policy
terminal CallLeg release and automatic capacity recovery
capacity->region and region->capacity sequential saves without stale replay
desired region and desired zone each exclude independently
desired topology does not contaminate observed topology
RuntimeNode readiness not rewritten by topology exclusion
region and zone cleared through Web Admin, persisting as real NULL
automatic topology eligibility recovery on both corridors
```

Regression-proven, not natural-live-proven, and explicitly not overclaimed:

```text
conference corridor capacity parity   (no natural conference fixture)
multi-candidate ordering behaviour    (single-candidate environment)
external inbound provider traffic     (not naturally available)
```

Per the packet these environment limitations do not block closure.

## Roadmap impact

```text
V1    COMPLETE / UNCHANGED
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   COMPLETE / NATURAL-LIVE-PROVEN
      remaining proof gap: NONE
K5D   NOT STARTED
K5E   NOT STARTED
A0    ELIGIBLE / PARALLEL
Operational Reporting & Insights
      FUTURE UTCP CORE / BOUNDARY DEFINED / NOT IMPLEMENTED / UNCHANGED
```

No K5D behaviour was exercised: no cordon, drain, eviction, maintenance intent,
or workload movement. No K5E multi-host proof was attempted. No reporting work
was done. No Kubernetes scheduler mutation, Node label change, RBAC change, or
NetworkPolicy change occurred. The `scripts/native-k3s/image-sync` `.git` debt
and the runtime deployment-convergence debt remain unchanged separate items; no
Pod-age heuristic was introduced.

**Exactly one next action:** K5D — Telephony-Aware Host Maintenance.
