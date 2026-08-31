# K5C — Capacity / Failure-Domain Policy Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-31

Starting HEAD: `c39aed737097f7e3e8bb1397585dba98c143c510`
(`docs(architecture): define operational reporting boundary`)

K5C implementation commit in ancestry: `c5ca2d08990a15e96ded2e14e45291c904377ea9`
(`feat(k5): enforce capacity and failure-domain policy`) — verified ancestor of HEAD.

## Verdict

`K5C_PLACEMENT_OBSERVATION_AUTHORITY_LIVE_DEFECT`

and

`K5C_CANONICAL_MANAGEMENT_LIFECYCLE_LIVE_DEFECT`

**K5C is not closed.** Two reproducible live defects were isolated in the
deployed K5C implementation. No product code was repaired here, no RuntimeNode
configuration was mutated, and no proof Call was originated. The controlled
capacity and topology corridors were **not** exercised, because the natural
Web Admin mutation path they require does not exist for any RuntimeNode in this
environment (Defect B), and because the observed-topology input the topology
corridor is meant to prove is structurally unavailable (Defect A).

## Deployment

Current `main` was deployed through the canonical native-k3s lifecycle. No
lower-level path, no parallel cluster, no manual manifest application.

```text
promoted source commit  c39aed737097f7e3e8bb1397585dba98c143c510
lifecycle               server-image-sync -> server-config-check
                        -> server-image-preflight -> server-apply
UTCP_SERVER_API_IMAGE   ghcr.io/grimange/utcp-api@sha256:eb980b76…6ed2
UTCP_SERVER_WEB_IMAGE   ghcr.io/grimange/utcp-web@sha256:021bf725…a366
```

Promotion used the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing debt, which was not
repaired here. `api`, `gateway`, `web`, `worker`, `scheduler` and `reverb` all
rolled out successfully and every `utcp-platform` Pod returned to Ready.

Canonical environment, unchanged:

```text
Kubernetes   native k3s v1.36.3+k3s1
context      default
Node         utcp-dev01   InternalIP 192.168.254.124   Ready True
```

## Migration acceptance

`2026_08_30_120000_create_k5c_placement_observation_projection` ran through the
normal `utcp-migrate` Job in batch 6. The projection table did not exist before
the deployment (`to_regclass` null) and exists afterwards with its primary key,
`(tenant_id, status)` index, and both cascade foreign keys. The migration also
replaced `kamailio_inbound_runtime_target_view` with the K5C definition.

```text
migration applied        YES (normal deployment lifecycle)
manual SQL required      NO
migration retry/repair   NONE
```

## Automatic projection lifecycle

The projection is refreshed by `runtime-engine:k5c-placement-observer`,
registered at `apps/api/routes/console.php:1002` as
`->everyMinute()->withoutOverlapping()` and executed by the `scheduler`
Deployment. The scheduler log shows it running and exiting `DONE` on every
minute boundary, and `observed_at` advances between reads
(`23:56:02` → `00:00:04`).

```text
projection populated automatically   YES
manual Artisan invocation            NONE
manual SQL / manual refresh          NONE
```

Repeated reads with no cluster change returned identical factual output.

## Defect A — derived projection contradicts Kubernetes

The projection populates automatically, but every RuntimeNode is permanently
recorded as `kubernetes_observation_unavailable` with every observed fact null,
while the same application observes the real placement correctly at the same
moment.

Live projection (written by the `scheduler` Pod):

```text
102d58ba-93ec-4601-a2a3-81f95801440f | kubernetes_observation_unavailable
  uid NULL  name NULL  region NULL  zone NULL  hostname NULL  at 2026-08-31 00:00:04+00
7322e6e1-8417-42ce-ad4f-4e7d25b23a3a | kubernetes_observation_unavailable
  uid NULL  name NULL  region NULL  zone NULL  hostname NULL  at 2026-08-31 00:00:04+00
```

K5B placement API for the same RuntimeNode, fetched by the authenticated
browser session at the same time (served by the `api` Pod):

```text
status placed
kubernetes_node.uid    faa05d1c-35fd-48fa-a2f7-6060d845c9ee
kubernetes_node.name   utcp-dev01
kubernetes_node.ready  true
topology               {kubernetes.io/hostname: utcp-dev01}
workload               utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085
pod                    …-58455vvb4b  node_name utcp-dev01  Running
```

Kubernetes itself agrees with the K5B surface, not with the projection:

| Fact | Kubernetes | K5B placement API | K5C projection |
| --- | --- | --- | --- |
| status | Pod on one Node | `placed` | `kubernetes_observation_unavailable` |
| Node UID | `faa05d1c-…` | `faa05d1c-…` | `NULL` |
| Node name | `utcp-dev01` | `utcp-dev01` | `NULL` |
| region | absent | absent | `NULL` |
| zone | absent | absent | `NULL` |
| hostname | `utcp-dev01` | `utcp-dev01` | `NULL` |

**Agreement: FAIL.** A genuinely absent region label and an unobservable
Kubernetes API are different facts, and K5C's frozen policy treats them
differently only when a desired constraint is configured.

### Root cause

`K5CPlacementObservationService::refresh()` catches
`KubernetesWorkloadClientException` and writes
`kubernetes_observation_unavailable` for every RuntimeNode. The exception is
raised because the Pod running the observer has no Kubernetes credentials at
all. Two independent layers each block it:

```text
scheduler Deployment serviceAccountName   utcp-platform-app
utcp-platform-app automountServiceAccountToken   false
  -> in the running scheduler Pod:
       KUBERNETES_SERVICE_HOST   10.43.0.1      (present)
       .../serviceaccount/token  ABSENT
       .../serviceaccount/ca.crt ABSENT

ClusterRoleBinding utcp-infrastructure-reader subjects
  -> ServiceAccount utcp-api only
  kubectl auth can-i list nodes --as=…:utcp-platform-app   no
  kubectl auth can-i list pods  --as=…:utcp-platform-app   no
  kubectl auth can-i list nodes --as=…:utcp-api            yes
```

The `api` Deployment uses `utcp-api`, which has both the mounted token and the
`utcp-infrastructure-reader` binding — which is exactly why K5A and K5B are
live-proven while the K5C projection is not.

### Current blast radius

The defect is currently **latent for admission**, not silent: because both
RuntimeNodes have `placement_region` and `placement_zone` null,
`RuntimeNodeFailureDomainEvaluator::eligible()` returns `true` before consulting
the observation, and the inbound view's constraint clause short-circuits on the
same null check. The live baseline is therefore still correct
(see *Baseline*). The moment any desired region or zone is configured, every
RuntimeNode becomes permanently ineligible for new automatic telephony work
across all three corridors regardless of its real topology — an exclusion that
would look like correct K5C behaviour while being produced by a credential
fault rather than by the observed facts.

## Defect B — no natural Web Admin path to K5C policy

K5C's desired policy fields cannot be configured through the canonical
management surface for any RuntimeNode in this environment.

`apps/web/src/views/RuntimeNodesView.vue:506` gates the whole RuntimeNode edit
form — Display name, **Placement region**, **Placement zone**, **Placement
priority**, **Capacity weight**, and *Save runtime details* — on:

```text
can('runtime.nodes.manage') && node.desired_state !== 'retired'
  && runtimeManagement(node).mode !== 'managed'
```

Both live RuntimeNodes report `management.mode = "managed"`, so the form is
rendered for neither. Verified live in the natural authenticated session with
the target node's Details panel open — a full DOM enumeration of the page:

```text
Capacity weight field            ABSENT
Placement region field           ABSENT
Placement zone field             ABSENT
"Save runtime details" button    ABSENT
labels present on the page       ["Active tenant", "Appearance"]
```

The API does accept these fields for managed nodes —
`AdminRuntimeNodeController::update()` validates `placement_region`,
`placement_zone`, `placement_priority` and `capacity_weight`, and only
`runtime_family`, `adapter_key` and `labels` are routed through
`assertManualMutationAllowed()`. So the authority gap is in the Web Admin
surface, not in the API contract.

Under ADR-032 the Web Admin is the normal management authority for tenant
policy. K5C's capacity and failure-domain policy is tenant policy. The packet
requires natural Web Admin mutation (§14–§15, §46) and explicitly forbids
substituting SQL or Artisan; direct API mutation may supplement but not replace
it. The capacity-exhaustion, capacity-release, topology-constraint and
topology-recovery corridors were therefore **not** exercised, and no RuntimeNode
configuration was changed.

## Baseline established before the defects blocked the corridors

Discovered naturally from live canonical state, not hardcoded:

```text
RuntimeNode        102d58ba-93ec-4601-a2a3-81f95801440f
name               V1A Outbound Reproof Asterisk 1787825256
tenant             342ee3b1-5b74-4964-8113-15030a61fda3  (Local Tenant)
runtime / adapter  asterisk / asterisk-ari
desired / observed active / ready
capacity_weight    100
placement_priority 100
placement_region   NULL
placement_zone     NULL
management.mode    managed
workload identity  utcp-runtime | asterisk-v1a-outbound-reproof-asterisk-1787-5fced085

active conference bindings   0
active non-terminal CallLegs 0   (25 CallLegs exist, all completed/failed)
total active telephony work  0
```

The production inbound view reflects that state exactly:

```text
select * from kamailio_inbound_runtime_target_view;

tenant 342ee3b1-…  runtime_node 102d58ba-…
sip:asterisk-v1a-outbound-reproof-asterisk-1787-5fced085.utcp-runtime.svc.cluster.local:5060;transport=udp
placement_priority 100   available_capacity 100   active_telephony_work 0
```

**§13 baseline is proven:** the Node genuinely reports no region and no zone
label, the RuntimeNode configures no constraint, and the candidate remains
eligible with no K5C penalty — through the real production SQL view, not a
mock. This holds despite Defect A, because the frozen no-constraint rule
short-circuits before the observation is consulted.

RuntimeNode readiness is not rewritten by the unavailable observation:
`observed_state` remains `ready` while the projection reports
`kubernetes_observation_unavailable`. Desired and observed authorities remain
structurally separate — the desired `placement_region`/`placement_zone` columns
are untouched by the observer, which writes only `observed_*` columns.

## Natural Web Admin path

Playwright MCP, no session carried in from any earlier packet. The real login
page was reached first and showed the unauthenticated form.

```text
https://app.utcp.local.test/login     real form (Email, Password, Sign in)
natural submission                    -> /dashboard
active tenant selected                Local Tenant
sidebar navigation                    Telephony Infrastructure -> Telephony Nodes
                                      -> /admin/runtime-nodes
natural click                         Details on the target node
```

No injected cookie, no localStorage or session injection, no preset browser
state, no database- or Redis-created session, no authentication bypass. The
placement section rendered `HOST utcp-dev01`, `HOST STATUS Ready`,
`ZONE Not reported`, `REGION Not reported` and the co-resident RuntimeNode —
K5B awareness remains correct and unaffected.

## What was and was not proven

Natural-live-proven in this packet:

```text
canonical native-k3s deployment of current main
K5C migration applied through the normal lifecycle, no manual SQL
projection refreshed automatically every minute, no manual Artisan or SQL
projection output deterministic across repeated reads
missing topology + no desired constraint -> eligible, no K5C penalty
  (through the real kamailio_inbound_runtime_target_view)
active-work calculation live: 0 bindings + 0 non-terminal CallLegs = 0
RuntimeNode readiness not rewritten by an unavailable observation
desired placement columns not overwritten by the observer
natural login and natural Web Admin navigation to the RuntimeNode
```

Not proven, blocked by the defects above:

```text
projection agreement with Kubernetes facts        BLOCKED (Defect A)
capacity exhaustion -> new-work exclusion         NOT EXERCISED (Defect B)
capacity release -> automatic eligibility return  NOT EXERCISED (Defect B)
desired topology constraint -> exclusion          NOT EXERCISED (A and B)
constraint cleared -> automatic recovery          NOT EXERCISED (A and B)
outbound / inbound / conference three-corridor parity under load
                                                  NOT EXERCISED
active CallLeg capacity effect and terminal release
                                                  NOT EXERCISED
```

These remain covered by the repository regression suite recorded in the
implementation packet (665 backend tests, 5355 assertions, 9 skipped); they are
**regression-proven, not natural-live-proven**, and this document does not
claim otherwise.

## Environment and mutation boundary

No RuntimeNode configuration was changed, so nothing needed restoring.

```text
final capacity_weight     100   (unchanged, original)
final placement_region    NULL  (unchanged, original)
final placement_zone      NULL  (unchanged, original)
final placement_priority  100   (unchanged, original)
```

No proof Call was originated. No conference binding was created. No Kubernetes
scheduler mutation, no Node label change, no cordon, drain, eviction or taint.
No RBAC was modified — the RBAC facts above were read with `kubectl auth can-i`
and by reading the committed manifest. No cluster, registry, host port, node
topology, persistent volume, or deployment mechanism was changed. No K5D
behaviour was exercised and no K5E multi-host proof was attempted. No
reporting/Insights capability was implemented and ADR-033 was not modified.

## Smallest deterministic corrections

Both are bounded repository implementations for a separate packet. Neither was
performed here.

**Defect A.** The K5C placement observer needs the same read-only Kubernetes
observation authority the `api` Deployment already has, without widening it to
every workload that currently shares `utcp-platform-app` (`web`, `kamailio`,
`rtpengine`, `reverb`, the event listeners). Binding
`utcp-infrastructure-reader` to `utcp-platform-app` would grant cluster-wide
Node and Pod read to all of them and is a security-boundary widening; a
dedicated ServiceAccount for the `scheduler` Deployment with
`automountServiceAccountToken: true` and its own binding to the existing
`utcp-infrastructure-reader` ClusterRole keeps the boundary narrow. The
NetworkPolicy marker used by K5A (`utcp.io/kubernetes-api-client`) must also
cover the scheduler Pod. Choosing between these is a real design decision and
belongs to the implementation packet, not to this proof.

**Defect B.** The RuntimeNode edit form gate at
`apps/web/src/views/RuntimeNodesView.vue:506` conflates *identity* mutation,
which is legitimately restricted for UTCP-managed nodes and already guarded
server-side by `assertManualMutationAllowed()`, with *policy* mutation, which
the API already permits for managed nodes. The bounded correction is to expose
the K5C policy fields — placement region, placement zone, placement priority,
capacity weight — for managed RuntimeNodes while keeping display name,
`runtime_family`, `adapter_key` and `labels` under the existing managed-mode
restriction.

## Roadmap impact

```text
V1    COMPLETE / UNCHANGED
K5A   COMPLETE / UNCHANGED
K5B   COMPLETE / NATURAL-LIVE-PROVEN / UNCHANGED
K5C   IMPLEMENTED_AND_TESTED; NATURAL LIVE PROOF BLOCKED
      by two isolated live defects
K5D   NOT STARTED
K5E   NOT STARTED
A0    ELIGIBLE / PARALLEL
Operational Reporting & Insights
      FUTURE UTCP CORE / BOUNDARY DEFINED / NOT IMPLEMENTED / UNCHANGED
```

The `scripts/native-k3s/image-sync` `.git` debt and the runtime
deployment-convergence debt remain unchanged separate items. No Pod-age
heuristic was introduced.

**Exactly one next action:** bounded implementation correcting the K5C
placement-observation credential authority (Defect A) and the Web Admin K5C
policy-configuration gap (Defect B), after which the controlled natural K5C
acceptance corridor in this packet can be re-run unchanged.
