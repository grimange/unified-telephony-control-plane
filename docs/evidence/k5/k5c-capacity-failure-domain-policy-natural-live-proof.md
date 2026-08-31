# K5C — Capacity / Failure-Domain Policy Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `685eb9145c884cc4f7541e789eebb02bbaa46416`
(`fix(k5): repair placement observation and policy controls`)

Deployed HEAD: `685eb9145c884cc4f7541e789eebb02bbaa46416`

## Verdict

`K5C_INBOUND_CAPACITY_PROJECTION_LIVE_DEFECT`

and

`K5C_FAILURE_DOMAIN_RECOVERY_LIVE_DEFECT`

**K5C is not closed.** Both previously isolated blockers (Defect A —
placement-observation authority; Defect B — managed policy UI) are **repaired
and live-proven**. The reproof then advanced into the capacity and
failure-domain corridors and isolated two further reproducible live defects. No
product source was repaired in this packet.

## Deployment

Deployed through the canonical native-k3s lifecycle only.

```text
lifecycle               server-image-sync -> server-config-check
                        -> server-image-preflight -> server-apply
UTCP_SERVER_API_IMAGE   ghcr.io/grimange/utcp-api@sha256:c9356782…1ee7
UTCP_SERVER_WEB_IMAGE   ghcr.io/grimange/utcp-web@sha256:8ef87d4b…fa23
```

Promotion used the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing debt, which was not
repaired here. `api`, `gateway`, `web`, `worker`, `scheduler` and `reverb` all
rolled out successfully; every `utcp-platform` Pod is Ready.

Canonical environment, unchanged:

```text
Kubernetes   native k3s v1.36.3+k3s1
context      default
Node         utcp-dev01   InternalIP 192.168.254.124   Ready True
```

## Gate A — placement observation authority (Defect A repair)

### A1 scheduler ServiceAccount identity

```text
pod        scheduler-569cc564cf-k5dcm
namespace  utcp-platform
uid        b407e418-52cb-4138-bdf3-ca7c2d8746b2
sa         utcp-kubernetes-observer
automount  true
phase      Running
```

The pre-repair Pod `scheduler-69757bc4ff-n787f` (`utcp-platform-app`,
`automount=false`) was allowed to terminate through normal rollout mechanics
before any K5C claim was made; exactly one scheduler Pod remained. No live Pod
spec was patched. **PASS.**

### A2 ServiceAccount credential mount

Presence only; no token value was read, printed, or copied out of the Pod.

```text
KUBERNETES_SERVICE_HOST        10.43.0.1
KUBERNETES_SERVICE_PORT_HTTPS  443
.../serviceaccount/token       PRESENT (1248 bytes)
.../serviceaccount/ca.crt      PRESENT (570 bytes)
.../serviceaccount/namespace   utcp-platform
```

**PASS.**

### A3 positive least-privilege RBAC

```text
as system:serviceaccount:utcp-platform:utcp-kubernetes-observer

get nodes                 yes
list nodes                yes
get pods   (all-ns)       yes
list pods  (all-ns)       yes

watch nodes               no
patch nodes               no
update nodes              no
create pods               no
patch pods                no
delete pods               no
get/list secrets          no
list deployments          no
```

Authority is exactly the existing `utcp-infrastructure-reader` ClusterRole —
`get` and `list` on core `nodes` and `pods` and nothing else. No write verb and
no additional resource was granted. Denial was proven by authorization
inspection only; nothing was mutated to demonstrate it. **PASS.**

### A4 negative shared-ServiceAccount proof

```text
as system:serviceaccount:utcp-platform:utcp-platform-app

get nodes            no
list nodes           no
get pods  (all-ns)   no
list pods (all-ns)   no
```

The shared platform identity gained no infrastructure-reader authority as a
side effect. `web`, `kamailio`, `rtpengine`, `reverb` and the event listeners
remain unprivileged. **PASS.**

### A5 network classification

```text
scheduler Pod labels
  app.kubernetes.io/component    scheduler
  app.kubernetes.io/part-of      utcp
  utcp.io/network-role           scheduler
  utcp.io/kubernetes-api-client  "true"

NetworkPolicy allow-runtime-fencer-kubernetes-api
  podSelector  {utcp.io/kubernetes-api-client: "true"}
  egress       TCP 6443 -> 192.168.254.124/32
```

The scheduler uses the pre-existing Kubernetes API egress corridor. No policy
was disabled, relaxed, or added. **PASS.**

### A6 actual in-cluster API read

Proven through the application itself rather than by adding tooling to the
image. No kubectl was installed in the scheduler image and no host kubeconfig
was mounted. The scheduled observer completed and wrote real Kubernetes facts:

```text
scheduler log   2026-08-31 01:00:02 Running ['artisan'
                runtime-engine:k5c-placement-observer]  523.83ms DONE
result          real Node/Pod facts persisted (below)
errors          no missing-token, no TLS/CA error, no 401, no 403,
                no NetworkPolicy timeout
```

**PASS.**

### A7 automatic placement projection

`runtime-engine:k5c-placement-observer` is registered at
`apps/api/routes/console.php:1002` as `->everyMinute()->withoutOverlapping()`
and runs in the scheduler. No manual command, SQL, or special API path was
used.

```text
102d58ba-93ec-4601-a2a3-81f95801440f  placed
  uid faa05d1c-35fd-48fa-a2f7-6060d845c9ee  name utcp-dev01
  region ABSENT  zone ABSENT  hostname utcp-dev01

7322e6e1-8417-42ce-ad4f-4e7d25b23a3a  no_managed_kubernetes_identity
```

The second row is a natural positive control — a draft RuntimeNode with no
managed workload identity, not created or mutated for this proof.

**Defect A is repaired and live-proven.**

## Kubernetes facts versus derived projection

| Fact | Kubernetes | K5C projection |
| --- | --- | --- |
| status | one Pod on one Node | `placed` |
| Node UID | `faa05d1c-35fd-48fa-a2f7-6060d845c9ee` | same |
| Node name | `utcp-dev01` | same |
| region label | absent | `ABSENT` |
| zone label | absent | `ABSENT` |
| `kubernetes.io/hostname` | `utcp-dev01` | `utcp-dev01` |

Pod correlation used only namespace + `app.kubernetes.io/instance` +
`part-of=utcp` — `utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085`
→ Pod `…-596649krgn` → `spec.nodeName utcp-dev01`. Nothing was synthesised from
IP, hostname, provider address, or LAN address. **Agreement: PASS.**

### Projection stability

Four scheduled observation cycles with no cluster change (01:01:03 → 01:03:02):
RuntimeNode, Node UID, Node name, region, zone and placement status were
identical every time; only `observed_at` advanced. **PASS.**

## Gate B — managed RuntimeNode policy controls (Defect B repair)

### B1 natural login

A session persisted in the browser profile from the previous packet, so it was
**logged out first** and a fresh login performed from the real login page
inside this packet. No cookie, localStorage or sessionStorage injection, no
database- or Redis-created session, no preset browser state, no authentication
bypass.

```text
https://app.utcp.local.test/login   real form -> natural submit -> /dashboard
active tenant selected              Local Tenant
```

### B2 natural navigation

```text
sidebar  Telephony Infrastructure -> Telephony Nodes -> /admin/runtime-nodes
click    Details on V1A Outbound Reproof Asterisk 1787825256
```

No deep link to an internal management route.

### B3 managed K5C policy controls

The managed RuntimeNode's detail panel now renders a `Telephony policy`
section. Full DOM enumeration:

```text
Placement region     present, enabled, not readonly   value ""
Placement zone       present, enabled, not readonly   value ""
Placement priority   present, enabled, not readonly   value 100
Capacity weight      present, enabled, not readonly   value 100
Save runtime details present
```

**VISIBLE + EDITABLE. PASS.**

### B4 protected managed integration controls

```text
forms on page                     1  (only the four K5C policy fields)
Display name field                ABSENT
"Runtime integration" heading     ABSENT
engine / adapter / integration    no label, no control
endpoint add or edit              no label, no control
credential / rotate / secret      no label, no control
capabilities                      no label, no control

Placement and infrastructure (K5B) section
  controls 0   forms 0            read-only preserved
```

Managed integration identity remains protected; the repair exposed policy only.
**PASS.**

**Defect B is repaired and live-proven.**

## Managed RuntimeNode baseline

Discovered naturally from live canonical state.

```text
RuntimeNode        102d58ba-93ec-4601-a2a3-81f95801440f
name               V1A Outbound Reproof Asterisk 1787825256
tenant             342ee3b1-5b74-4964-8113-15030a61fda3  (Local Tenant)
runtime / adapter  asterisk / asterisk-ari
management.mode    managed
desired / observed active / ready
workload identity  utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
placement status   placed on utcp-dev01

original capacity_weight     100
original placement_priority  100
original placement_region    NULL
original placement_zone      NULL

active conference bindings    0
active non-terminal CallLegs  0
total active telephony work   0
```

Baseline production inbound view — missing Kubernetes topology with no desired
constraint carries **no K5C penalty**:

```text
102d58ba-…  placement_priority 100  available_capacity 100  active_telephony_work 0
```

**PASS.**

## Capacity corridor

### Natural Web Admin mutation

Capacity was changed through the managed node's own `Telephony policy` form and
Save, never through SQL or a direct API call:

```text
Web Admin capacity_weight 100 -> 1   saved
canonical PostgreSQL readback        capacity_weight = 1,
                                     configuration_version 11
inbound view                         available_capacity 1, work 0 (still eligible)
manual reconcile / Artisan / SQL     NONE
```

**PASS.**

### Full-capacity state

Active work was 0, so the smallest reversible natural scenario was used: one
bounded canonical outbound Call against capacity 1. The repository-authoritative
destination was confirmed still active before use —
`c537a4a7-af3d-474f-bf19-4be4aeaae2cf` = `sip:97001@38.146.161.46`.

```text
Call 1  ed59174d-ec19-4ddb-86bb-9da393ee2e7c   201 Created, state originating
        runtime_node_id omitted -> automatic selection chose 102d58ba-…
CallLeg 26181999-75e9-49cb-b187-60b1ff2819ac   observed_state originating
```

Captured live inside the active window at 01:03:52:

```text
active_telephony_work        1
capacity_weight              1        -> 1 < 1 false -> FULL
RuntimeNode                  desired active / observed ready
K5C projection               placed, node utcp-dev01, region ABSENT
```

The active CallLeg raised shared active telephony work from 0 to 1, live.
**Active CallLeg capacity effect: PASS.**

### Outbound admission under full capacity

```text
Call 2 attempt (identical canonical corridor, same destination)
  HTTP 422
  "No eligible runtime node is available for outbound call execution."
```

The full RuntimeNode was excluded from automatic selection and the canonical
unavailable response was returned. **PASS.**

### Existing work safety

Call 1 continued through the full-capacity state and the rejected Call 2
attempt, and terminated naturally on its own:

```text
Call 1   terminated 2026-08-31 01:04:31+00  termination_reason remote
CallLeg  observed_state completed  (01:03:51 -> 01:04:31, ~40 s)
```

It was **not** terminated by K5C admission policy. **PASS.**

### Terminal release and automatic recovery

```text
after terminal   active_telephony_work 0
inbound view     available_capacity 1, work 0
Call 3           fba… corridor retried -> 201 Created,
                 eea1622a-25e8-4a8a-84c0-b0e7914893b7, originating
```

Eligibility returned automatically. No Artisan, SQL, manual projection command,
Pod restart, or deployment restart. **Terminal CallLeg release: PASS.
Automatic capacity recovery: PASS.**

### Defect C — inbound SQL ignores capacity

`K5C_INBOUND_CAPACITY_PROJECTION_LIVE_DEFECT`

Captured in the same full-capacity window in which outbound correctly excluded
the node, the production view still returned it:

```text
select * from kamailio_inbound_runtime_target_view;
102d58ba-…   available_capacity 0   active_telephony_work 1     <- ROW RETURNED
```

The live view definition (`pg_get_viewdef`) computes `available_capacity` and
`active_telephony_work` as output columns and orders by them, but its `WHERE`
clause contains **no capacity eligibility predicate**. It filters only on
desired/observed state, configuration convergence, execution-image
convergence, `call.control` capability, SIP endpoint, and the failure-domain
constraint.

The live Kamailio consumer does not compensate — `kamailio-config` ConfigMap,
rendered in the running Pod:

```text
sql_query("conference",
  "select runtime_node_id, sip_target from kamailio_inbound_runtime_target_view
   where tenant_id = '$dbr(external_trunk_route=>[0,0])'
   order by placement_priority, runtime_node_id",
  "inbound_runtime_target")
```

It selects no capacity column, applies no capacity predicate, and re-orders by
`placement_priority, runtime_node_id` — discarding the view's
available-capacity and active-load tiebreakers. A RuntimeNode at or beyond its
K5C capacity therefore remains a selectable inbound target.

This contradicts the K5C implementation record, which states the inbound SQL
"excludes full candidates before its stable order". Conference and outbound
share the evaluator and behave correctly; inbound diverges.
**Three-corridor capacity parity: FAIL.**

## Failure-domain corridor

Capacity was first restored to a non-blocking value (100) through Web Admin, so
that every topology result below is attributable to topology alone. Active work
was confirmed `0` immediately before each topology admission attempt.

### Desired constraint applied naturally

```text
Web Admin -> Telephony policy -> Placement region = k5c-live-proof-region -> Save
canonical readback   placement_region = k5c-live-proof-region
```

No Kubernetes Node label was added to match it.

### Desired versus observed authority separation

```text
UTCP desired    placement_region  k5c-live-proof-region
                placement_zone    k5c-live-proof-zone

K5C observed    status            placed
                observed_region   ABSENT
                observed_zone     ABSENT
                observed node     utcp-dev01

Kubernetes Node topology/region/zone labels   none (unchanged)
```

The desired constraint was never copied into observed topology, and the
observer kept refreshing real facts throughout. **PASS.**

### Eligibility under unsatisfied constraint

```text
desired region configured + observed region absent
  inbound view                     0 rows        -> excluded
  outbound canonical corridor      HTTP 422
    "No eligible runtime node is available for outbound call execution."
  active work at the time          0             -> attributable to topology

desired zone configured + observed zone absent
  inbound view                     0 rows        -> excluded
```

Both desired fields participate; the region and zone results are distinguishable
because each was applied and observed separately. **PASS.**

### RuntimeNode readiness preservation

Throughout topology exclusion:

```text
RuntimeNode  desired_state active   observed_state ready
K5C eligibility  false
```

Readiness was not rewritten by an unsatisfiable topology constraint. **PASS.**

### Defect D — a K5C constraint cannot be cleared through the canonical path

`K5C_FAILURE_DOMAIN_RECOVERY_LIVE_DEFECT`

Clearing both fields through the natural Web Admin form, from a freshly seeded
form, on a single save:

```text
form   Placement region = ""   Placement zone = ""   -> Save runtime details
result configuration_version advanced 15 -> 16   (save accepted and processed)
       placement_region  STILL k5c-live-proof-region
       placement_zone    STILL k5c-live-proof-zone
       inbound view      still 0 rows -> still excluded
```

Root cause, `apps/api/app/RuntimeRegistry/RuntimeRegistryService.php:157-158`:

```php
'placement_region' => $input['placement_region'] ?? $node->placement_region,
'placement_zone'   => $input['placement_zone']   ?? $node->placement_zone,
```

`??` cannot distinguish an explicit `null` from an absent key. The Web Admin
sends `null` to clear (`appState.ts`: `form.placement_region || null`) and the
API validates it as `nullable`, but `updateNode()` discards the null and rewrites
the stored value. Submitting an empty string instead does not help: `''` is not
`NULL`, so the view's `placement_region IS NULL` short-circuit would not apply
and the constraint would become permanently unsatisfiable.

**Once a K5C failure-domain constraint is set through the canonical management
path, it cannot be removed through any canonical path** — Web Admin and the
authenticated API share the same defective write. Topology eligibility recovery
therefore cannot occur naturally, and proof-only configuration cannot be
restored naturally.

### Evaluator correctness is not implicated

After the desired state was actually cleared (see *Environment restoration*),
eligibility returned immediately with no reconcile, no Artisan, no SQL against
the projection, and no restart:

```text
inbound view   102d58ba-…  available_capacity 100  active_telephony_work 0
outbound       201 Created  fba788d3-678a-4b3e-abf0-631d116933f5
```

Defect D is confined to the desired-state **write path**. The failure-domain
evaluator, the projection, and the automatic recovery machinery are correct.

## Environment restoration

Restoring `placement_region` and `placement_zone` to their original `NULL`
values was explicitly required, and the canonical path could not perform it
because of Defect D. Leaving the environment's only call-capable RuntimeNode
permanently ineligible for all inbound and outbound work was not an acceptable
end state.

A single targeted, guarded SQL statement restored exactly the two proof-only
columns to their pre-proof values, scoped by both the RuntimeNode id and the
exact proof values so it could not affect anything else:

```sql
update runtime_nodes set placement_region = null, placement_zone = null
where id = '102d58ba-…'
  and placement_region = 'k5c-live-proof-region'
  and placement_zone = 'k5c-live-proof-zone';
-- UPDATE 1
```

This is disclosed as a **non-canonical action**. It was not used to manufacture
any proof result, and every acceptance result above was obtained before it. It
repaired environment damage caused by Defect D. No other row, column, table,
audit record, Call, or CallLeg was touched. `configuration_version` was left at
the value the legitimate Web Admin saves produced.

Final state, verified by readback:

```text
capacity_weight     100    (original)
placement_priority  100    (original, never changed)
placement_region    NULL   (original)
placement_zone      NULL   (original)
desired / observed  active / ready
K5C projection      placed, utcp-dev01, region ABSENT, zone ABSENT
inbound view        eligible, available_capacity 100, work 0
Kubernetes Node topology labels   none added
all utcp-platform Pods            Ready
```

## Secondary observation — stale edit-form cache

Not a K5C policy defect and not a packet gate, recorded because it silently
reverted a saved value during this proof. `saveRuntimeNodeEdit()` refreshes the
node and its details but never resets `runtimeNodeEditForms[node.id]`, which
`runtimeNodeEditForm()` only initialises when absent. After one save, the form
keeps its previous in-memory values, so a **second** save without a full page
reload can silently rewrite a field the operator did not touch — observed live
when a saved `capacity_weight = 100` was reverted to `1` by a subsequent
unrelated save. Each individual save from a freshly loaded page was correct.

## What was and was not proven

Natural-live-proven in this packet:

```text
canonical native-k3s deployment of repaired main
scheduler runs as utcp-kubernetes-observer with native projected credentials
least-privilege get/list on nodes and pods only; writes and secrets denied
utcp-platform-app remains unprivileged
existing Kubernetes API egress corridor used; no policy change
scheduler-hosted observer authenticates and reads Kubernetes successfully
automatic placement projection, no manual sync of any kind
projection agrees with Kubernetes and is stable across cycles
managed RuntimeNode exposes K5C policy controls in Web Admin
managed integration identity remains protected; K5B placement stays read-only
natural Web Admin mutation reaches canonical PostgreSQL desired state
missing topology + no constraint -> eligible, no K5C penalty
active CallLeg raises shared active telephony work
full capacity -> outbound exclusion with canonical unavailable response
existing work not terminated by admission policy
terminal CallLeg releases capacity; eligibility returns automatically
desired topology constraint -> outbound and inbound exclusion
desired and observed topology remain distinct authorities
RuntimeNode readiness not rewritten by topology exclusion
```

Not proven:

```text
inbound capacity exclusion               FAIL (Defect C)
three-corridor capacity parity           FAIL (Defect C)
natural topology eligibility recovery    FAIL (Defect D)
natural restoration of proof-only config FAIL (Defect D)
conference corridor under full capacity  NOT NATURALLY EXERCISED
deterministic multi-candidate ordering   SINGLE-CANDIDATE NATURAL ENVIRONMENT
```

Conference capacity behaviour and multi-candidate ordering remain
**REGRESSION-PROVEN**, not natural-live-proven: no natural conference binding
and no second eligible RuntimeNode exists in this environment. Neither is
claimed as live evidence. Per the packet these alone would not block closure —
Defects C and D do.

## Smallest deterministic corrections

Both are bounded implementations for a separate packet; neither was performed
here.

**Defect C.** Add the K5C capacity eligibility predicate to the
`kamailio_inbound_runtime_target_view` `WHERE` clause — a full candidate must
not be returned — in a new forward migration, matching the semantics the
shared evaluator already applies to conference and outbound
(`capacity_weight = 0` unlimited, otherwise `active telephony work < capacity_weight`).
The Kamailio consumer query should be left selecting from the view so the view
remains the single inbound authority; if its `order by placement_priority,
runtime_node_id` is meant to preserve the K5C tiebreakers, that is a second,
separable question and should not be conflated with the exclusion fix.

**Defect D.** In `RuntimeRegistryService::updateNode()`, distinguish an absent
key from an explicit null for the nullable placement fields — use
`array_key_exists('placement_region', $input) ? $input['placement_region'] : $node->placement_region`
(and the same for `placement_zone`) so a canonical clear actually clears. The
existing managed-mode identity protections and the retired-node guard are
unaffected.

Also worth folding into that packet: reset `runtimeNodeEditForms[node.id]` after
a successful save so consecutive edits cannot silently revert a saved field.

## Roadmap impact

```text
V1    COMPLETE / UNCHANGED
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   IMPLEMENTED_AND_TESTED
      Defect A (placement observation authority)  REPAIRED / LIVE-PROVEN
      Defect B (managed policy UI)                REPAIRED / LIVE-PROVEN
      Defect C (inbound capacity exclusion)       OPEN
      Defect D (constraint clearing)              OPEN
K5D   NOT STARTED
K5E   NOT STARTED
A0    ELIGIBLE / PARALLEL
Operational Reporting & Insights
      FUTURE UTCP CORE / BOUNDARY DEFINED / NOT IMPLEMENTED / UNCHANGED
```

No K5D behaviour was exercised: no cordon, drain, eviction, host-maintenance
intent, or workload movement. No K5E multi-host proof was attempted. No
reporting or Insights work was done. No Kubernetes scheduler mutation, Node
label change, RBAC modification, or NetworkPolicy change occurred. The
`scripts/native-k3s/image-sync` `.git` debt and the runtime
deployment-convergence debt remain unchanged separate items; no Pod-age
heuristic was introduced.

**Exactly one next action:** bounded implementation correcting the inbound
capacity exclusion (Defect C) and the canonical placement-constraint clear path
(Defect D), after which this controlled natural K5C acceptance corridor can be
re-run unchanged.
