# V1 Runtime Execution-Contract Eligibility Proof

## Status

`V1_RUNTIME_EXECUTION_CONTRACT_ELIGIBILITY_PRODUCT_DEFECT_IDENTIFIED`

A RuntimeNode configuration-generation contract exists and a managed-workload
convergence authority exists, but neither expresses the workload's *execution
contract* (container image identity). Stale call-capable RuntimeNodes therefore
report fully converged and remain selectable indefinitely.

No repository code was changed. No runtime state was mutated. V1 remains active.

## Established call architecture (preserved, not reopened)

`Call -> C7B RouteDecision -> CallLeg route/trunk/endpoint binding ->
call.leg.originate -> C6 RuntimeAdapter` is implemented and live. The three
Codex proof calls at 12:35-12:42 carried
`external_trunk_id f944ceb3-...` and `trunk_endpoint_id f86a012f-...` on the
CallLeg, confirming durable route binding. They terminated `runtime_lost`.

## Historical RuntimeNode

```text
slug                            c6e-final-proof-20260822
id                              3488f30f-bdf8-4a2a-b2f9-e865b0c625d0
runtime_family / adapter_key    asterisk / asterisk-ari
desired_state                   active
observed_state                  ready
configuration_version (desired) 10
observed_configuration_version  10        <- reports CONVERGED
classification                  managed (runtime_provisioning_requests row exists)
capabilities                    call.transfer (+ registry-managed set)
created_at                      2026-08-21 23:26:45+00
```

## Kubernetes ownership

Neither workload has `ownerReferences`; both are applied directly by their
respective UTCP authority and reconciled as Deployments.

```text
A. historical selected runtime
   Deployment  asterisk-c6e-final-proof-20260822-4e9ac74e
   labels      utcp.dev/runtime-node=c6e-final-proof-20260822
   generation  1            (never rolled since creation)
   applied by  ManagedAsteriskProvisioningOperationHandler / AsteriskRuntimeNodeReconciler
   Pod started 2026-08-21T23:26:46Z
   imageID     utcp/asterisk-ari@sha256:000d6fc601e56fac95bad4447713824e8b71a3596fa3cf8ea4452b9a4da43c33

B. canonical current managed Asterisk
   Deployment  asterisk-ari
   labels      utcp.dev/runtime-node=local-asterisk-ari
   generation  51           (rolled 2026-08-25T12:38:14+08:00)
   applied by  the K1 platform Kustomize base (make k8s-apply)
   Pod started 2026-08-25T12:38:16Z
   imageID     utcp/asterisk-ari@sha256:c949027a3c30f243d000319dbfe992d18c435445aad40d625a0db0ec9379d490
```

Both mount the same ConfigMap `asterisk-local-sip-fixtures`, which contains only
`extensions.local.conf` and holds neither `utcp-outbound` nor `kamailio-edge`.
The execution contract is baked into the container image.

## Live execution-contract comparison

Read-only Asterisk inspection (`-C /tmp/utcp-asterisk/asterisk.conf`):

```text
local-asterisk-ari
  dialplan show utcp-outbound
    [ Context 'utcp-outbound' created by 'pbx_config' ]
      '_.' => 1. NoOp(UTCP canonical outbound destination=${EXTEN})
              2. Set(PJSIP_HEADER(add,X-UTCP-Call-Leg-ID)=${UTCP_CALL_LEG_ID})
              3. Set(PJSIP_HEADER(add,X-UTCP-Route-Decision-ID)=...)
              4. Set(PJSIP_HEADER(add,X-UTCP-Trunk-Endpoint-ID)=...)
              5. Set(PJSIP_HEADER(add,X-UTCP-Caller-Identity-ID)=...)
  pjsip show endpoints -> kamailio-edge  Not in use

c6e-final-proof-20260822
  dialplan show utcp-outbound -> "There is no existence of 'utcp-outbound' context"
  pjsip show endpoints        -> kamailio-edge absent (only anonymous)
  /tmp/utcp-asterisk/extensions.conf contains 0 occurrences of utcp-outbound
```

All three `active` managed Asterisk RuntimeNodes run the stale digest:

```text
c6e-final-proof-20260822          sha256:000d6fc6...
v0c6-conference-runtime-20260815  sha256:000d6fc6...
rnp6-readiness-reproof-20260809   sha256:000d6fc6...
current tag digest                sha256:c949027a...   (registry, = canonical Pod)
```

## Existing runtime convergence authority

It exists, and it is genuinely enforced — but only over adapter/profile
configuration:

```text
desired  runtime_nodes.configuration_version
observed runtime_nodes.observed_configuration_version
writer   RuntimeRegistryService (desired), ProjectionService (observed, from the
         runtime's own readiness observation payload)
gate     AsteriskRuntimeNodeReconciler::evaluate()
           converged  <=> observed_state === 'ready' && ocv >= cv
           else       -> runtime.node.inspect operation
workload AsteriskRuntimeNodeReconciler::convergeManagedDeployment()
           -> KubernetesWorkloadClient::applyDeployment(desiredDeployment(...))
           runs on every reconciliation pass for managed nodes
```

`RuntimeEvidenceService` exposes it as `observed_configuration_generation`.

## Root cause — one exact mechanism

`ManagedAsteriskProvisioningOperationHandler::desiredDeployment()` builds the Pod
template from `config('asterisk_ari.managed_image')`, which is a **mutable tag**:

```text
UTCP_MANAGED_ASTERISK_IMAGE   = utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev
UTCP_MANAGED_FREESWITCH_IMAGE = utcp-local-registry:5000/utcp/freeswitch@sha256:5ea4fc99...
```

The two families enforce different contracts:

```php
// Asterisk - accepts a tag OR a digest
str_contains(strrchr($image, '/'), ':') || str_contains($image, '@sha256:')

// FreeSWITCH - digest only
preg_match('/^[^\/\s]+\/utcp\/freeswitch@sha256:[0-9a-f]{64}$/', $image)
```

Consequences, all confirmed live:

1. When the tag's content changes, `desiredDeployment()` still renders a
   byte-identical Pod template, so `applyDeployment()` is a permanent no-op and
   Kubernetes performs no rollout. `imagePullPolicy: Always` only applies when a
   Pod is recreated, which never happens.
2. The Deployment template carries no configuration or checksum annotation and
   nothing derived from `configuration_version`, so bumping the RuntimeNode
   generation cannot trigger a rollout either.
3. `configuration_version` / `observed_configuration_version` never include
   workload image identity, so a Pod running a months-old digest legitimately
   reports `observed_state=ready`, `ocv == cv`, `converged`.

The Asterisk adapter originates `Local/<destination>@utcp-outbound`
(`AsteriskAriClient` lines 352, 431, 434), which is exactly the context the stale
image lacks - hence the reported
`No such extension/context 97001@utcp-outbound while calling Local channel`.

## Runtime selection authority

`CommandWorker::resolveAdapter()` is the only eligibility gate:

```text
1. runtime node exists and tenant matches
2. desired_state in {active, draining}   (+ disabled for RunsOnDisabledRuntimeNode)
3. required capability present in runtime_node_capabilities
4. an adapter is registered for adapter_key
```

It does **not** consult `observed_state` and does **not** compare
`observed_configuration_version` with `configuration_version`. Two FreeSWITCH
nodes currently sit at `observed_state = stale` and remain fully selectable.

There is also no automatic call-capable runtime selection at all:
`CallDomainService::createOutboundCall()` passes `runtime_node_id` straight
through from the request, so the historical node was chosen because the prover
named it, not because a selector preferred it.

## Why no existing lifecycle can repair this

- The only workload carrying the execution contract, `asterisk-ari`
  (`utcp.dev/runtime-node=local-asterisk-ari`), is the K1 platform base
  Deployment and has **no RuntimeNode record**, so it can never be selected for
  a call.
- Every managed Asterisk RuntimeNode is pinned to the mutable tag, so no
  reconciliation, generation bump, or re-provision changes its Pod.
- FreeSWITCH images are digest-current, but the outbound execution contract
  exists only in `infrastructure/docker/asterisk/config/{extensions.conf,pjsip.conf}`;
  there is no FreeSWITCH equivalent of `utcp-outbound` / `kamailio-edge`.

No canonical lifecycle was therefore driven, and no runtime state was changed.
The historical proof workloads were left untouched and still eligible, so the
defect remains reproducible.

## Live call re-proof

Not attempted. It is conditional on a converged, call-capable Asterisk
RuntimeNode existing; none does.

## Registration stability

Unaffected by all call activity:

```text
observation        registered
observed_health    ready
last_success_at    2026-08-25 13:03:13+00
routingEligibility outbound {"eligible":true,"code":"external_trunk_eligible"}
```

No manual UAC refresh was used.

## Verification

```text
make kamailio-signaling-config-check                pass
make kamailio-signaling-registration-runtime-proof  pass
make security-config-check                          pass
make repository-hygiene                             pass
make secret-scan                                    pass
git diff --check                                    clean
host 5060 publications                              0
all Pods                                            Running
```
