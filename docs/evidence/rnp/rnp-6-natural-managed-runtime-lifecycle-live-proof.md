# RNP-6 — Natural Managed Runtime Lifecycle Live Proof

## Verdict

    RNP_6_NATURAL_MANAGED_RUNTIME_LIFECYCLE_LIVE_PROOF_PASSED

This document is one composed evidence chain, recorded in the order it
happened and deliberately not rewritten:

    1. First natural live run (2026-08-09)  → FOUND_BLOCKER
                                              PRODUCT_DEFECT-27 / -28
    2. Bounded blocker fixes                → implemented and focused-tested
    3. Narrow live reproof (2026-08-09)     → PASSED

Sections 1 and 2 below are the original record and remain unchanged. The
narrow live reproof that closes both defects is appended at the end under
"Narrow live reproof after blockers 27/28". The composed chain establishes the
complete natural managed-runtime lifecycle, so RNP is complete.

The verdict of the first run, preserved verbatim for history, was:

    RNP_6_NATURAL_MANAGED_RUNTIME_LIFECYCLE_LIVE_PROOF_FOUND_BLOCKER

The natural managed-runtime lifecycle was driven end to end through the real
Admin UI against the canonical `utcp-local` environment. Every RNP authority
contract held: one UI submission produced exactly one provisioning request, one
RuntimeNode, and one `runtime.node.provision` operation; the infrastructure
worker automatically created exactly one Secret, Deployment, and Service with
correct ownership; credentials, endpoints, capabilities, adapter configuration,
and workload identity were all derived automatically and all preceded
activation; the RNM drain and decommission controls produced `draining →
drained → retired`; and exactly one `runtime.node.deprovision` operation was
created automatically and removed all three managed resources while retaining
the historical RuntimeNode.

Two live defects block RNP completion:

* **PRODUCT_DEFECT-27 (blocking).** The managed Asterisk Deployment is written
  with the unqualified container image `utcp-asterisk-ari` and
  `imagePullPolicy: Always`. Because RNP-3 writes the Deployment directly
  through the Kubernetes API, no Kustomize image transform applies, so the
  reference resolves to `docker.io/library/utcp-asterisk-ari:latest` and the
  Pod never leaves `ImagePullBackOff`. The managed runtime can therefore never
  become healthy or `ready`.
* **PRODUCT_DEFECT-28 (non-blocking, user-visible).** The Admin UI compares
  runtime-operation status against the string `completed`, which the backend
  never emits; the canonical terminal status is `succeeded`. A succeeded
  provision renders as `Provisioning: Requested` and a succeeded deprovision
  renders as `Infrastructure: Deprovisioning`.

Everything else in the RNP chain is live-proven.

## Canonical environment

    CONTEXT:               k3d-utcp-local
    PLATFORM NAMESPACE:    utcp-platform
    RUNTIME NAMESPACE:     utcp-runtime
    API IMAGE:             utcp-local-registry:5000/utcp/api:0.1.0-k1-dev
                           @sha256:62bc8da30800828ff5af8c4db85422eca3fb5186296af253e1867b9b3733e8bb
    WEB IMAGE:             utcp-local-registry:5000/utcp/web:0.1.0-k1-dev
                           @sha256:dcc5207715415b3f9cb8c0510cf57267cf834b40f15da4a3d3b58ebc58b6787f
    INFRASTRUCTURE WORKER: deployment/utcp-runtime-fence-worker
                           (args telephony-infrastructure-worker), 1/1 Ready,
                           ServiceAccount utcp-runtime-fencer

### Deployment currency preflight

The environment initially predated the RNP implementation under proof. The
running `api` image contained only the RNP-1 `RuntimeProvisioningService`, and
the infrastructure worker ran an older image with no `app/RuntimeProvisioning`
directory at all. The canonical lifecycle was used to bring the environment
current before any proof step:

    make k8s-config-check
    make k8s-image-build
    make k8s-image-push
    make k8s-apply            # utcp-migrate Job completed; RNP tables created
    kubectl rollout restart   # same-tag images require an explicit roll

No topology, cluster, registry, namespace, or host-port change was made.

### Environment drift corrected

The first four provision attempts failed with
`provisioning_unavailable_to_control`. The repository's own drift checker
identified the cause:

    scripts/security/check-apiserver-policy-drift
    → allow-runtime-fencer-kubernetes-api: stale endpoint destination,
      expected 172.21.0.4/32, found 172.21.0.5/32

This is the documented k3d node-IP shuffle NetworkPolicy endpoint-pin drift, an
ENVIRONMENT condition, not an RNP defect. It was repaired with the canonical
render-and-apply path used by `scripts/security/apply`:

    scripts/security/render-apiserver-policy
    kubectl apply -f .runtime/kubernetes/security/runtime-fencer-apiserver-egress.yaml
    kubectl apply -f .runtime/kubernetes/security/traefik-apiserver-egress.yaml
    kubectl apply -f .runtime/kubernetes/security/service-clusterip-egress.yaml

The operation then succeeded automatically on attempt 4 with no manual
requeue, which additionally proves the persisted retry lifecycle recovers from
a transient infrastructure-authority outage.

## Natural login

    LOGIN:       https://app.utcp.local.test/login
    USER:        admin@utcp.local.test (UTCP Local Administrator)
    TENANT:      Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: runtime.nodes.manage, runtime.nodes.view,
                 runtime.credentials.rotate, tenant.memberships.manage,
                 platform.* and telephony.* as returned by the application

A bounded temporary password was issued through the canonical break-glass
target `make user-access-reset-password`, then changed through the
application's own forced change-password flow. Tenant context was selected
through the UI tenant selector. No preset storage state, injected cookie,
copied session, database/Redis session, or authentication bypass was used.

## Proof fixture

    RUNTIME NODE:          c9c491bf-ba23-4ad2-976d-06e5a612acbf
                           rnp6-managed-asterisk-20260809
                           "RNP6 Managed Asterisk 20260809"
    PROVISIONING REQUEST:  17976e9b-b081-4dd1-847f-12245a62aa33
    PROVISION OPERATION:   12a7d4ffca357b3633d51f70fd918038
    DEPROVISION OPERATION: 80dee7954c5fe9fdceac04b0c22e220e

## Managed runtime UI

**Add Runtime** presented exactly two paths, `Managed Runtime` and
`Register Existing Runtime`. The existing registration path remained visible
and was not used.

**Managed runtime form** required only legitimate administrator inputs:

    Runtime type       (Asterisk — the only option)
    Deployment target  (Local UTCP Kubernetes)
    Name
    Slug

FreeSWITCH was not falsely offered as deployable. No Kubernetes namespace,
Deployment name, Service name, Secret name, ARI credential, container image,
raw manifest, or kubectl step was requested. The deployment target came from
canonical API data:

    GET /api/v1/admin/deployment-targets → 200
    {"id":"f23c2827-…","name":"Local UTCP Kubernetes","slug":"local-kubernetes",
     "kind":"local_kubernetes",
     "configuration":{"cluster":"utcp-local","context":"k3d-utcp-local",
                      "namespace":"utcp-runtime"}}

**Review** displayed exactly the expected intent — Runtime `Asterisk`,
Deployment target `Local UTCP Kubernetes`, Name, Slug, Management
`UTCP managed`, Credentials `Generated automatically`, Endpoints
`Generated automatically`, Infrastructure `Managed automatically by UTCP` —
with no credential plaintext and no raw infrastructure YAML.

**Submission** was a single activation of the primary `Deploy Runtime`
control:

    POST /api/v1/admin/runtime-provisioning → 202   (exactly one request)

No subsequent Start, Apply, Reconcile, Sync, or Provision Infrastructure action
was required or offered.

## Initial state

Immediately after submission:

    runtime_provisioning_requests: 1 row, status=requested
    runtime_nodes (rnp6):          desired_state=draft, observed_state=unobserved
    runtime_operations:            1 × runtime.node.provision

No duplicate RuntimeNode, provisioning request, or logical provision operation
existed at any point in the run.

## Provisioning operation

    23:23:08  created,           status=requested
    23:23:10  attempt 1 → retry_scheduled (provisioning_unavailable_to_control)
    …         attempts 2–3 → retry_scheduled (same environment drift)
    23:24:40  attempt 4 → succeeded

Progression was entirely automatic. No manual queue command, worker
invocation, or reconciliation trigger was used.

## Kubernetes resources

    NAMESPACE:  utcp-runtime
    DEPLOYMENT: asterisk-rnp6-managed-asterisk-20260809-0eb60fe5
    SERVICE:    asterisk-rnp6-managed-asterisk-20260809-0eb60fe5 (ClusterIP, 8088/TCP)
    SECRET NAME: asterisk-rnp6-managed-asterisk-20260809-0eb60fe5-credentials (Opaque)
    OWNERSHIP:  app.kubernetes.io/part-of=utcp
                app.kubernetes.io/component=asterisk-ari
                utcp.dev/runtime-node=rnp6-managed-asterisk-20260809

Exactly one Secret, one Deployment, and one Service were created. No
ConfigMap, PVC, NodePort, LoadBalancer, host-port resource, or additional
provisioning worker was created. Secret values were never displayed.

## Credential proof

Metadata only, from the canonical Admin API:

    type:        ari-basic
    identifier:  utcp_f81ahfbumre3xog6rpjrvgib   (non-secret ARI username)
    fingerprint: c11ae701d44b05132b97ce2f98db6fcca33198ec20f908f298cd77c855fd7416
    version:     1
    status:      active   (later: retired)

Exactly one credential existed for the node and exactly one was active. The
credential row stores `encrypted_secret` and `secret_fingerprint`; no plaintext
column is exposed by the API.

## Endpoint proof

Three endpoints were derived automatically, with the host matching the
generated Service name exactly:

    control  http  asterisk-rnp6-managed-asterisk-20260809-0eb60fe5.utcp-runtime.svc.cluster.local:8088/ari
    events   ws    asterisk-rnp6-managed-asterisk-20260809-0eb60fe5.utcp-runtime.svc.cluster.local:8088/ari/events
    health   http  asterisk-rnp6-managed-asterisk-20260809-0eb60fe5.utcp-runtime.svc.cluster.local:8088/ari

No operator-entered endpoint was required.

## Capability proof

Declared capabilities matched the canonical `asterisk-ari` catalog exactly:

    conference.lifecycle, conference.participation, event.stream, runtime.observation

## Adapter configuration proof

    GET /api/v1/admin/runtime-nodes/{id}/adapter-configuration → 200
    configured: true
    profile:    application_name=utcp-t0-observation, configuration_version=7,
                canonical timeout/heartbeat/reconnect defaults

## Activation ordering

Physical audit insert order proves activation was last:

    runtime_node.created                            (draft)
    runtime_provisioning.requested
    runtime_node.credential_rotated
    runtime_node.endpoints_changed  × 3
    runtime_node.capabilities_changed
    runtime_node.asterisk_ari_configuration_changed
    runtime_node.updated                            (managed_workload_identity: true)
    runtime_node.desired_state_changed              (draft → active)

The RuntimeNode did not become active before its credential, Secret,
Deployment, Service, endpoints, declared capabilities, adapter configuration,
and Kubernetes workload identity all existed.

## Asterisk workload proof — FAILED

    Deployment available: 0/1
    Pod:                  ImagePullBackOff
    Event: Failed to pull image "utcp-asterisk-ari": failed to pull and unpack
           image "docker.io/library/utcp-asterisk-ari:latest": pull access denied,
           repository does not exist or may require authorization

See PRODUCT_DEFECT-27.

## Runtime readiness proof — NOT ACHIEVED

The existing observation authority ran correctly and reported the truth rather
than a silent absence:

    desired_state:  active
    observed_state: unavailable
    observed_at:    2026-08-08 23:27:31+00 (advancing)

Degraded reporting is correct behaviour for a workload that cannot start; the
target `observed_state = ready` was unreachable because of
PRODUCT_DEFECT-27. No observed state was written manually and projection was
never invoked by hand.

## UI ready proof — PARTIAL

The list naturally rendered the managed runtime as `UTCP managed` with
`desired active` / `observed unavailable`, distinct from the `External` badge
on every pre-existing node, and exposed the RNM controls `Details`, `Drain`,
and `Disable`. It could not show `Ready` because of PRODUCT_DEFECT-27, and it
showed `Provisioning: Requested` for a succeeded provision because of
PRODUCT_DEFECT-28.

## Provisioning integrity

    runtime_nodes (rnp6):     1
    provisioning requests:    1
    runtime.node.provision:   1
    active credentials:       1
    endpoints:                3
    Deployment/Service/Secret: 1 / 1 / 1

The API ServiceAccount retains no Kubernetes infrastructure mutation
authority; only the fencer identity has it:

    system:serviceaccount:utcp-platform:utcp-platform-app
      create/patch/delete deployments, create secrets, create services → no
    system:serviceaccount:utcp-platform:utcp-runtime-fencer
      create/delete deployments, create secrets, delete services → yes

## RNM drain lifecycle

    ACTIVE:   desired_state=active
    DRAINING: runtime_node.desired_state_changed {"from":"active","to":"draining"}
    DRAINED:  runtime_node.desired_state_changed
              {"from":"draining","to":"drained","reason":"canonical_runtime_bindings_empty"}

Initiated with the real UI `Drain` control. The canonical drain coordinator
completed it (`runtime_node_drains` row `c4ddcbcd-…`: status=completed,
initial_work=0, remaining_work=0). DRAINED was never written manually and no
drain-completion command was invoked.

## RNM decommission

Initiated with the real UI `Decommission` control and its confirmation dialog:

    23:30:26  runtime_node.decommission_requested   (from drained)
    23:30:28  runtime_node.credential_retired       (decommission_retired)
    23:30:28  runtime_node.desired_state_changed    (drained → retired)

## Deprovision operation

    23:30:28  runtime_deprovision.requested
              operation 80dee7954c5fe9fdceac04b0c22e220e
              provisioning_request_id 17976e9b-b081-4dd1-847f-12245a62aa33
    23:30:31  succeeded (attempt 1)

Exactly one `runtime.node.deprovision` operation was created automatically from
the managed provisioning relationship. No Destroy Infrastructure button, manual
API call, or kubectl delete was used.

## Infrastructure removal

    DEPLOYMENT ABSENT: yes (NotFound)
    SERVICE ABSENT:    yes (NotFound)
    SECRET ABSENT:     yes (NotFound)

No residual Pod or ReplicaSet carrying the ownership label remained. The
pre-existing externally managed `asterisk-ari` Deployment, Services, and Secret
in `utcp-runtime` were untouched.

## Historical RuntimeNode proof

The same RuntimeNode remains visible in the Admin UI and retains its history:

    desired_state:   retired
    management:      UTCP managed
    deprovisioning:  succeeded (API); UI label incorrect — PRODUCT_DEFECT-28
    endpoints:       3 retained
    capabilities:    4 retained
    adapter config:  retained
    credential:      retained as status=retired, metadata only
    workload identity: retained in placement labels

No hard deletion occurred and no credential plaintext appeared.

## Audit / history proof

The canonical trail is truthful and complete, in physical insert order:

    deployment_target.registered
    runtime_provisioning.requested
    runtime_node.created
    runtime_node.credential_rotated
    runtime_node.endpoints_changed × 3
    runtime_node.capabilities_changed
    runtime_node.asterisk_ari_configuration_changed
    runtime_node.updated
    runtime_node.desired_state_changed  draft → active
    runtime_node.desired_state_changed  active → draining
    runtime_node.desired_state_changed  draining → drained
    runtime_node.decommission_requested
    runtime_node.credential_retired
    runtime_node.desired_state_changed  drained → retired
    runtime_deprovision.requested

## External runtime sanity

Read-only. All seven pre-existing simulator RuntimeNodes continued to render
with the `External` badge, distinct from the managed fixture's `UTCP managed`
badge. None was retired or mutated.

## Secret exposure review

No generated credential plaintext appeared anywhere:

    runtime_operations payload/evidence (provision + deprovision): 0 hits
    control_plane_audit_records metadata:                          0 hits
    control_plane_outbox_messages:                                 0 hits
    rendered browser DOM:                                          0 hits

Kubernetes Secret plaintext was never read or decoded.

## Failed proof steps

### PRODUCT_DEFECT-27 — managed Asterisk Deployment uses an unresolvable image

    CLASSIFICATION:  IMPLEMENTATION
    CLAIM:           A managed Asterisk runtime becomes a healthy workload and
                     reaches observed_state = ready.
    EXPECTED:        The generated Deployment references the canonical local
                     Asterisk image, the Pod runs, probes succeed, and the
                     existing observation path reports ready.
    ACTUAL:          Deployment 0/1 available; Pod ImagePullBackOff.
    UI STATE:        UTCP managed · desired active · observed unavailable.
    HTTP RESULT:     n/a — the Kubernetes writes all succeeded.
    OPERATION STATE: runtime.node.provision = succeeded.
    RUNTIME NODE:    desired_state=active, observed_state=unavailable.
    KUBERNETES:      Failed to pull image "utcp-asterisk-ari": failed to
                     resolve "docker.io/library/utcp-asterisk-ari:latest";
                     pull access denied, repository does not exist.
    OBSERVATION:     observed_at advancing; unavailable reported correctly.
    ROOT CAUSE:      The Deployment body hardcodes the unqualified image
                     reference 'utcp-asterisk-ari' with imagePullPolicy
                     'Always'. RNP-3 writes the Deployment directly through the
                     Kubernetes API, so the overlay's Kustomize images
                     transform (utcp-asterisk-ari →
                     utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev)
                     never applies. containerd resolves the bare name against
                     Docker Hub.
    AFFECTED AUTH.:  RNP-3 managed provisioning / deployment-target
                     configuration.
    AFFECTED FILE:   apps/api/app/RuntimeProvisioning/
                     ManagedAsteriskProvisioningOperationHandler.php
                     → private function deployment(), 'image' key.

    BOUNDED TARGET:  Resolve the managed runtime container image from canonical
                     configuration rather than a literal — for example from the
                     deployment target's configuration column or a
                     runtime-provisioning config key — so the local_kubernetes
                     target yields the same registry-qualified reference the
                     canonical overlay already uses. Cover it with a focused
                     regression test asserting the rendered Deployment image.

### PRODUCT_DEFECT-28 — UI compares operation status against a status the backend never emits

    CLASSIFICATION:  IMPLEMENTATION
    CLAIM:           The Admin UI truthfully reports managed provisioning and
                     deprovisioning progress.
    EXPECTED:        A succeeded provision renders as an activated/ready state;
                     a succeeded deprovision renders as 'Deprovisioned'.
    ACTUAL:          'Provisioning: Requested' for a succeeded provision;
                     'Infrastructure: Deprovisioning' after infrastructure was
                     fully removed.
    HTTP RESULT:     The API is correct — management.provisioning.status =
                     'succeeded' and management.deprovisioning.status =
                     'succeeded'.
    ROOT CAUSE:      Both label functions test operation.status === 'completed'.
                     The canonical terminal status is 'succeeded'
                     (OperationStatus::Succeeded, terminal in
                     OperationStateMachine); no backend path emits 'completed'.
                     The failure branch has the same class of bug: it tests
                     ['failed','cancelled'] while the backend emits
                     'terminal_failed' and 'expired'.
    AFFECTED AUTH.:  RNP-5 Admin UI presentation only. No canonical state is
                     affected.
    AFFECTED FILE:   apps/web/src/views/RuntimeNodesView.vue
                     → managedProvisioningLabel() (~line 1179)
                     → managedDeprovisioningLabel() (~line 1189)

    BOUNDED TARGET:  Compare against the canonical RuntimeOperationStatus union
                     already declared in apps/web/src/api/platform.ts —
                     'succeeded' for success, and 'terminal_failed' /
                     'cancelled' / 'expired' for failure. Add a focused
                     component test per label branch.

### Secondary observations (non-blocking, not defects on current evidence)

* `runtime_provisioning_requests.status` is written once as `requested` and is
  never advanced by any code path, despite a 32-char column and a
  `(tenant_id, status, created_at)` index. RNP-1 evidence documents the
  write-once behaviour as intended for now. It is not what drives
  PRODUCT_DEFECT-28, which reads the operation, not the request.
* `management.provisioning.failure` still carries the last transient failure
  (`provisioning_unavailable_to_control`) after `status = succeeded`, because
  the representation surfaces `last_failure_*` unconditionally. Harmless today,
  but it invites a misleading display.
* The decommission confirmation dialog says "externally managed infrastructure
  will not be destroyed" for a UTCP-managed runtime whose infrastructure is
  destroyed moments later, and the retirement audit metadata records
  `physical_runtime_destroyed: false`. Both are accurate at the instant RNM
  runs, before the asynchronous RNP-4 boundary, but the operator-facing wording
  is misleading for the managed path.

## Code changes

    None.

## Environment and topology changes

* Application images rebuilt from the working tree and pushed to the existing
  local registry, then deployed through `make k8s-apply` plus explicit
  same-tag rollout restarts. Repository-supported; part of the canonical
  environment.
* `allow-runtime-fencer-kubernetes-api`, `allow-traefik-kubernetes-api`, and
  the Service ClusterIP egress policy re-rendered from live endpoints and
  applied using the same render-and-apply path `scripts/security/apply` uses.
  Repository-supported; corrects pre-existing drift; part of the canonical
  environment.
* No cluster, registry, namespace, host port, load balancer, node topology,
  persistent volume, deployment mechanism, or parallel runtime was created or
  changed.

## Improvised or non-canonical actions

    None.

## Unresolved proof gaps

* Managed Asterisk workload health and `observed_state = ready` remain unproven
  and are blocked by PRODUCT_DEFECT-27.
* The UI `Ready` rendering for a managed runtime remains unproven, blocked by
  PRODUCT_DEFECT-27 and PRODUCT_DEFECT-28.

Every other RNP-6 requirement is live-proven above and does not need to be
re-run; the reproof after the fixes should re-establish the readiness segment
and the two UI labels, not the whole lifecycle.

## RNP completion decision

    RNP COMPLETION BLOCKED

## Bounded blocker fixes (2026-08-09)

PRODUCT_DEFECT-27 was implemented and focused-tested. Managed Asterisk
provisioning now reads the required, fully-qualified image reference from
`asterisk_ari.managed_image`; the canonical local application ConfigMap
provides `utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev`, matching
the checked-in local Kustomize image transform. Missing or unqualified image
configuration fails before Kubernetes mutation. The infrastructure worker
receives the setting through the existing `utcp-application-config` reference.

PRODUCT_DEFECT-28 was implemented and focused-tested. The UI now uses the
canonical `RuntimeOperationStatus` union and maps `succeeded`,
`terminal_failed`, `cancelled`, `expired`, and the in-progress states without
introducing backend aliases. Provision success remains distinct from
readiness: an active but non-ready node displays “Activated; waiting for
readiness”, while a ready node displays “Ready”. Successful deprovisioning
displays “Deprovisioned”.

The original live failure evidence above is preserved. Narrow live reproof is
still pending and must establish only image pull/start, healthy observation,
`observed_state = ready`, the Ready label, and the Deprovisioned label. The
RNP-6 verdict remains blocked pending that reproof.

---

# Narrow live reproof after blockers 27/28 (2026-08-09)

## Reproof verdict

    RNP_6_NATURAL_MANAGED_RUNTIME_LIFECYCLE_LIVE_PROOF_PASSED

All five required items passed. Both defects are closed by live evidence.

## Scope

This run deliberately proved only the five items the repairs affect. The
already-proven lifecycle areas listed under "Previously proven evidence reused"
were not rerun.

## Canonical environment

    CONTEXT:                 k3d-utcp-local
    PLATFORM NAMESPACE:      utcp-platform
    RUNTIME NAMESPACE:       utcp-runtime
    API IMAGE:               utcp-local-registry:5000/utcp/api:0.1.0-k1-dev
                             @sha256:01deaf9893987901b1701a2f765b6d4ca3ca8cca745824af23774277e5f42591
    WEB IMAGE:               utcp-local-registry:5000/utcp/web:0.1.0-k1-dev
                             @sha256:65500fc4d0891f1b5b02fec4ade220534f0925c771f5231ed8bab82f14a4e815
    INFRASTRUCTURE WORKER:   deployment/utcp-runtime-fence-worker
                             @sha256:01deaf98… (same api image), 1/1 Ready
    MANAGED ASTERISK IMAGE:  utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev

### Deployment currency preflight

The repository contained both fixes but the cluster did not: the running `api`
image had zero occurrences of `managed_image` in
`ManagedAsteriskProvisioningOperationHandler`, and the web bundle predated the
extraction of the label functions into
`apps/web/src/views/runtimeNodeManagementPresentation.ts`. The environment was
brought current through the canonical lifecycle only:

    make k8s-config-check
    make k8s-image-build      # verified both fixes present in the built images
    make k8s-image-push
    make k8s-apply
    kubectl rollout restart   # same-tag images require an explicit roll

Post-roll verification inside the running infrastructure worker:

    UTCP_MANAGED_ASTERISK_IMAGE=utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev
    grep -c managed_image …/ManagedAsteriskProvisioningOperationHandler.php → 1

confirming the value reaches the worker through the existing
`utcp-application-config` ConfigMap reference and that the handler consumes it.

### NetworkPolicy drift check

    scripts/security/check-apiserver-policy-drift
    → Kubernetes API egress drift check passed endpoint=172.21.0.4/32:6443

No drift this run; no repair was needed.

## Natural login

    LOGIN:       https://app.utcp.local.test/login
    USER:        admin@utcp.local.test (UTCP Local Administrator)
    TENANT:      Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: 19 capabilities including runtime.nodes.manage,
                 runtime.nodes.view, runtime.credentials.rotate

Bounded temporary password via `make user-access-reset-password`, changed
through the application's own forced change-password flow, tenant selected in
the UI. No preset storage, injected cookie, copied session, database/Redis
session, or authentication bypass.

## PART A — corrected Deprovisioned label (historical fixture)

    RUNTIME NODE:                c9c491bf-ba23-4ad2-976d-06e5a612acbf
                                 rnp6-managed-asterisk-20260809
    DEPROVISION OPERATION:       80dee7954c5fe9fdceac04b0c22e220e
    BACKEND STATUS:              deprovisioning.status = succeeded
                                 provisioning.status   = succeeded
                                 desired_state=retired, observed_state=unavailable
    UI LABEL:                    "Infrastructure: Deprovisioned"
    RESULT:                      PASS

The same row also now reads `Provisioning: Activated; waiting for readiness`
instead of the former `Requested`, which is the correct branch for a succeeded
provision on a node whose observed state is not `ready`. Before the fix this
row rendered `Provisioning: Requested · Infrastructure: Deprovisioning`.

The historical fixture was read only; it was not mutated.

## PART B — new readiness fixture

    RUNTIME NODE:         c7e6f4ba-b925-462f-aff4-71c9fa9a4157
    SLUG:                 rnp6-readiness-reproof-20260809
    NAME:                 RNP6 Readiness Reproof 20260809
    PROVISIONING REQUEST: eeade421-e94b-4ae3-a590-893fa055dc09
    PROVISION OPERATION:  bb1899d47e2e7559b34cf7fd4206ce53 (succeeded, attempt 1)

Created through the real UI: Runtime Nodes → Add Runtime → Managed Runtime →
Asterisk → Local UTCP Kubernetes → Review → Deploy Runtime. Exactly one
`POST /api/v1/admin/runtime-provisioning → 202`. Integrity confirmed: 1
RuntimeNode with that slug, 1 new provisioning request (2 total including the
historical one), 1 logical `runtime.node.provision` operation. No Start,
Apply, Reconcile, or Sync action existed or was used. This run needed no retry
and no environment repair, so provisioning succeeded on the first attempt.

## PART C — image authority live proof

    DEPLOYMENT:      asterisk-rnp6-readiness-reproof-20260809-e2fb39c7
    SERVICE:         asterisk-rnp6-readiness-reproof-20260809-e2fb39c7
    SECRET NAME:     asterisk-rnp6-readiness-reproof-20260809-e2fb39c7-credentials
    POD:             asterisk-rnp6-readiness-reproof-20260809-e2fb39c7-6c886bd9jsmd6
    CONFIGURED:      utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev
    ACTUAL IMAGE:    utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev
    PULL POLICY:     Always
    RESOLVED DIGEST: sha256:42b711ce7ddc47c0fc6fb3a7b499e1013d64792a9ba8c439c6c4b774024da8d2
    RESULT:          PASS

Kubelet events:

    Pulling image "utcp-local-registry:5000/utcp/asterisk-ari:0.1.0-k1-dev"
    Successfully pulled image … in 2.344s. Image size: 129356739 bytes.
    Container created
    Container started

`ImagePullBackOff = false`, `ErrImagePull = false`. The Deployment contains
neither `utcp-asterisk-ari` nor `docker.io/library/utcp-asterisk-ari` as its
image authority. **PRODUCT_DEFECT-27 is closed.**

## PART D — workload health

    DEPLOYMENT AVAILABLE: True  (MinimumReplicasAvailable)
    PROGRESSING:          True  (NewReplicaSetAvailable)
    POD RUNNING:          True
    CONTAINER READY:      True
    RESTARTS:             0
    POD CONDITIONS:       PodReadyToStartContainers, Initialized, Ready,
                          ContainersReady, PodScheduled — all True
    PROBES:               startup + readiness /usr/local/bin/utcp-asterisk-readiness
                          liveness  asterisk -rx "core show uptime"
    RESULT:               PASS

Ready roughly 30 seconds after Pod creation, with no probe-failure events and
no restarts. No exec-based configuration fix was applied to the Pod.

## PART E — canonical runtime readiness

    DESIRED STATE:  active
    OBSERVED STATE: ready
    OBSERVED AT:    advancing — first ready at 2026-08-09 00:28:36+00,
                    still ready at 00:33:38+00
    RECONCILIATION: runtime_reconciliation_states target_type=runtime_node
                    status=converged, desired_generation=9, blocked_reason=none
    OBSERVATIONS:   26 rows, observation_type=runtime.readiness.observed,
                    payload event_type=asterisk.ari.runtime.info_observed,
                    adapter_key=asterisk-ari, failure_class=null
    RESULT:         PASS

Transition captured live:

    00:28:23  desired=active observed=unavailable
    00:28:43  desired=active observed=ready       ← natural convergence
    …         eleven consecutive ready samples, each with a new evidence id
              and an advancing observed_at

Readiness came through the existing authority — Asterisk reconciler/inspect →
runtime observation → ProjectionService → `observed_state`. No state was
written manually, projection was never invoked by hand, and no diagnostic
command was used as the state authority. The sustained sample series with
rotating evidence ids proves a live observation loop rather than a single
write. This is the natural counterpart to the first run, where the same
authority correctly reported `unavailable` for the broken Pod.

`runtime_node_observed_capabilities` is empty for this node and the UI shows
`Capability evidence: unknown`; the readiness observation payload carries
`capabilities: null`. Observed capabilities remain projection authority and
are not required to equal declared capabilities, so this does not affect the
readiness verdict.

## PART F — UI Ready label

    PROVISION OPERATION STATUS: succeeded
    DESIRED STATE:              active
    OBSERVED STATE:             ready
    UI LABEL (list):            "Provisioning: Ready"
    UI LABEL (detail panel):    "Management — UTCP managed · Local UTCP Kubernetes
                                 Provisioning: Ready"
    RESULT:                     PASS

The row reads `UTCP managed · desired active · observed ready ·
Provisioning: Ready · Target: Local UTCP Kubernetes`. It does not display
`Requested`, `Provisioning infrastructure`, or `Activated; waiting for
readiness`. **PRODUCT_DEFECT-28 is closed** — Part A proves the
`Deprovisioned` branch and Part F proves the `Ready` branch.

## Secret exposure review

Sanity check only; RNP-3 automated proof and the first live run already
established secret handling.

    rendered browser DOM:            0 hits for ARI_PASSWORD / stringData / password
    RuntimeNode API representation:  0 hits for ARI_PASSWORD / stringData /
                                     "password" / "secret":
    credential exposure:             type=ari-basic, version=1, status=active,
                                     fingerprint present, no plaintext field

No Kubernetes Secret value was read, decoded, or printed.

## Previously proven evidence reused, not rerun

The following were established by the first natural live run and are unaffected
by the two repairs, which touched only the managed image reference and the
frontend status-label mapping. Rerunning them would add no evidence:

* natural onboarding UI — Add Runtime paths, Asterisk-only managed choice,
  canonical deployment target, review contents (Part B did re-exercise this
  path incidentally because a new fixture was required)
* one-request / one-node / one-operation submission integrity (re-confirmed in
  Part B)
* automatic credential generation, exactly one active `ari-basic` credential
* control/events/health endpoint bootstrap and capability bootstrap
  (re-confirmed as 3 endpoints and 4 capabilities in the secret sweep)
* adapter configuration and Kubernetes workload identity
* activation ordering proved by audit physical insert order
* ownership labels and the absence of extra managed resources
* operation retry and recovery behaviour
* RNM `ACTIVE → DRAINING → DRAINED` and `DRAINED → RETIRED`
* automatic `runtime.node.deprovision`, Deployment/Service/Secret deletion,
  historical RuntimeNode preservation
* external RuntimeNode distinction
* API ServiceAccount having no Kubernetes infrastructure mutation authority

The new readiness fixture was deliberately **not** drained or decommissioned.
Part A proves the corrected `Deprovisioned` presentation using the existing
successful historical deprovision operation, so repeating RNP-4 would only
destroy a useful live artifact.

## Retained fixture

    RuntimeNode:  c7e6f4ba-b925-462f-aff4-71c9fa9a4157
    Slug:         rnp6-readiness-reproof-20260809
    State:        desired=active, observed=ready
    Kubernetes:   1 Deployment (1/1), 1 Service, 1 Secret in utcp-runtime

This is the retained RNP-6 readiness proof fixture. It is intentionally left
ACTIVE and READY as live evidence of a healthy UTCP-managed Asterisk runtime.
The pre-existing externally managed `asterisk-ari` Deployment and Services were
untouched.

## Failed proof steps

    None.

## Code changes

    None.

## Environment and topology changes

Application images rebuilt from the repository and pushed to the existing local
registry, deployed via `make k8s-apply` plus same-tag rollout restarts. No
cluster, registry, namespace, host port, load balancer, node topology,
persistent volume, deployment mechanism, or parallel runtime was created or
changed.

## Improvised or non-canonical actions

    None.

## Composed RNP-6 completion decision

    RNP COMPLETE

The first run proved onboarding, provisioning orchestration, credential and
endpoint and capability derivation, activation ordering, the RNM lifecycle,
deprovision execution, and historical preservation. This reproof proves the
correct managed image, a healthy live Asterisk workload,
`observed_state = ready`, the UI `Ready` label, and the UI `Deprovisioned`
label. Together they establish the complete natural managed-runtime lifecycle.
