# RNP-U2 — Operator Experience & Managed Authority Hardening

Status: implemented in the repository and **accepted by natural live reproof
(2026-08-09)**. All eight required reproof items passed. See "Narrow natural
usability and managed authority reproof" at the end of this document.

The chain recorded here is deliberately not rewritten:

    RNP-U1 audit → RNP-U2 implementation → natural U2 acceptance proof

## Scope

RNP-U1 identified two bounded product issues: managed runtime onboarding asked
for infrastructure choices that are fixed by the current product, and the
Runtime Nodes surface exposed manual generated-configuration mutation for
UTCP-managed nodes. RNP-U2 addresses those findings without changing RNP-1
through RNP-6 authority, lifecycle, readiness, or infrastructure behavior.

## Managed authority

The existing `runtime_provisioning_requests.runtime_node_id` relationship is
the managed ownership authority. Admin mutation corridors for generated
configuration now reject managed nodes with HTTP 422 and a sanitized domain
message. This covers endpoints, capabilities, credentials, labels/runtime
identity metadata, and adapter configuration. Runtime lifecycle actions and
read-only inspection remain available. External RuntimeNodes retain existing
integration mutation behavior. Internal RNP provisioning continues to write
through the existing RuntimeRegistryService and operation handler paths.

## Normal workflow

The existing Runtime Nodes surface now presents “Create a new runtime” as the
normal path and “Register an existing runtime” as the advanced external path.
For the current single managed runtime and single location, the managed form
contains only Name. Asterisk, the canonical location, credentials, endpoints,
and infrastructure are generated automatically. If the API later returns
multiple eligible runtime types or locations, corresponding selectors can
reappear from canonical data. The slug is derived server-side with existing
normalization, and duplicate identifiers return deterministic 422 validation
instead of a database error.

## Presentation

The UI uses pure presentation mappings over canonical desired state, observed
state, and runtime-operation status. The primary status vocabulary is
Creating, Starting, Ready, Needs attention, Taking out of service, and
Out of service, Retired, and Deprovisioned. Technical identity, operation, evidence, endpoint, capability,
credential, adapter, and history information remains available under
Advanced diagnostics; it is not required for normal operation. Managed
generated configuration is read-only, while external controls remain present.

Retirement confirmation is management-mode aware: managed runtimes state that
UTCP-managed resources are removed automatically and history remains; external
runtimes state that infrastructure outside UTCP is not deleted.

## Verification

Focused API coverage proves derived slug creation, 422 duplicate handling with
transactional no-orphan behavior, managed endpoint/capability/credential/
adapter mutation rejection, and external mutation regression. Focused Vue
coverage proves the single-input managed flow, external path preservation,
managed read-only gating, canonical status presentation, and advanced
diagnostics. `make test`, `make check`, `git diff --check`, and the production
web build pass. Disable remains unchanged as a reversible RNM action; no
additional confirmation layer was added in this bounded packet.

Natural-login usability reproof remains pending and belongs to the next narrow
Claude Code proof. The final RNP lifecycle proof is not reopened here.

## Scope review

No new lifecycle state, provisioning table, managed flag, worker, Kubernetes
authority, retry API, CLI, feature gate, or manual infrastructure control was
introduced. V0, C7, T6, and FreeSWITCH remain out of scope.

---

# Narrow natural usability and managed authority reproof (2026-08-09)

## Reproof verdict

    RNP_U2_NATURAL_USABILITY_AND_MANAGED_AUTHORITY_REPROOF_PASSED

All eight required items passed. Two bounded, non-blocking status-label
inconsistencies were found in adjacent states and are recorded below as
deferred polish; neither affects the eight acceptance items, managed
authority, or any operator action.

## Canonical environment

    CONTEXT:               k3d-utcp-local
    PLATFORM NAMESPACE:    utcp-platform
    RUNTIME NAMESPACE:     utcp-runtime
    API IMAGE:             utcp-local-registry:5000/utcp/api:0.1.0-k1-dev
                           @sha256:929c749eb5faf7ee49979a4fd5e237ce2557223d5ae892c0c9677b14197d1eff
    WEB IMAGE:             utcp-local-registry:5000/utcp/web:0.1.0-k1-dev
                           @sha256:e4d1ed919ca1779ead48334fd6d7e690f2fd8f4f3e30d21923284b7a9fccc7e7
    INFRASTRUCTURE WORKER: deployment/utcp-runtime-fence-worker, same api digest

### Deployment freshness (environment preparation, not implementation)

The U2 packet had not been deployed. Verified stale before proving anything:
the running api pod had **0** occurrences of `assertManualMutationAllowed`, and
the web bundle contained neither "Create a new runtime" nor "Local
environment". Brought current through the canonical lifecycle only:

    make k8s-config-check
    make k8s-image-build     # verified both changes present in the built images
    make k8s-image-push
    make k8s-apply
    kubectl rollout restart  # same-tag images require an explicit roll

Post-roll verification inside the running pods: api reports **9** call sites of
`assertManualMutationAllowed`; the web bundle serves "Create a new runtime".
No cluster, registry, namespace, alternate path, or feature gate was created.

## Natural login

    LOGIN:       https://app.utcp.local.test/login
    USER:        admin@utcp.local.test
    TENANT:      Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: 19 capabilities incl. runtime.nodes.manage, runtime.nodes.view,
                 runtime.credentials.rotate

Bounded break-glass password changed through the application's own forced
flow; tenant selected in the UI. No preset storage, injected cookie, copied
session, database/Redis session, or authentication bypass.

## PROOF 1 — Name-only managed creation — PASS

Entry panel now reads:

    Create a new runtime
    Register an existing runtime
    "Create a new runtime and UTCP will configure it automatically, or
     register an existing runtime as an advanced integration."

The managed form, read from the rendered DOM, contains exactly **one** control:

    VISIBLE FORM CONTROLS:      #managed-runtime-name (text, required)
    REQUIRED INPUTS:            Name only
    RUNTIME TYPE INTERACTION:   none — informational text only
    LOCATION INTERACTION:       none — informational text only
    SLUG INTERACTION:           none — field absent
    PRIMARY ACTION:             "Create Runtime" (disabled until Name is filled)

Informational line, not a control:

    "Asterisk · Local environment. UTCP will configure credentials,
     endpoints, and infrastructure automatically."

No slug, runtime-type, deployment-target, adapter, credential, endpoint, or
infrastructure input is present, and the multi-step review wizard is gone.

    CLICKS: 3    FIELDS: 1    USER DECISIONS: 1    TECHNICAL DECISIONS: 0

## PROOF 2 — Automatic single-choice resolution — PASS

Canonical API corroboration:

    GET /api/v1/admin/deployment-targets → 1 target
      "Local UTCP Kubernetes" (kind local_kubernetes)
    managed runtime types = 1 (asterisk/asterisk-ari, service-enforced by
      RuntimeProvisioningService::MANAGED_RUNTIME)

Both are resolved automatically by the page: neither renders a selector, and
neither requires interaction. Proven from the rendered DOM plus API data, not
from source.

## PROOF 3 — Managed runtime read-only UI — PASS

Existing ACTIVE/READY managed fixture `rnp6-readiness-reproof-20260809`,
opened naturally:

    MANAGEMENT MODE:                    UTCP managed
    CREDENTIAL MUTATION CONTROL:        absent
    ENDPOINT MUTATION CONTROL:          absent
    CAPABILITY MUTATION CONTROL:        absent
    ADAPTER CONFIG MUTATION CONTROL:    absent
    WORKLOAD IDENTITY MUTATION CONTROL: absent

Measured: **0** mutator buttons (no Rotate/Save/Remove/Add endpoint/Set
capabilities) and **0** input, select, or textarea elements anywhere in the
managed detail surface. The controls are absent, not present-but-failing.

## PROOF 4 — Managed backend authority rejection — PASS

Exercised from the same naturally authenticated browser session using
same-origin `fetch` with the session CSRF token. No alternate authentication
was introduced.

| Mutation corridor | HTTP | Message | State changed? | Result |
|---|---:|---|---:|---|
| credential rotate | 422 | This runtime is managed by UTCP. Its generated runtime configuration cannot be edited manually. | No | PASS |
| endpoint upsert | 422 | same | No | PASS |
| capability set | 422 | same | No | PASS |
| adapter configuration | 422 | same | No | PASS |

Canonical state before and after all four attempts is byte-identical:

    configuration_version 9 → 9
    endpoints 3 → 3
    capabilities conference.lifecycle, conference.participation,
                 event.stream, runtime.observation (unchanged)
    credential 05dc491f-…:v1:active (unchanged)

Internal RNP provisioning is unaffected: the guard lives on the Admin
controller (9 call sites in `AdminRuntimeNodeController`), while the
provisioning handler uses the internal `ensureManaged*` seams. The fixture
remained `active/ready` throughout.

## PROOF 5 — External runtime controls retained — PASS

All seven pre-existing external fixtures are RETIRED, and retired nodes are
edit-gated by `assertNodeNotRetired` regardless of management mode, so they
could not distinguish "external retains controls" from "retired blocks
controls". One disposable, registry-only external simulator node was therefore
registered through the natural external path to obtain a valid comparison. It
creates no Kubernetes infrastructure.

    EXTERNAL RUNTIME:     u2-external-control-probe (simulator/simulator-deterministic)
    MANAGEMENT MODE:      External
    EDIT CONTROLS PRESENT: Save runtime details, Add endpoint, Set capabilities,
                           Save credential, Save adapter configuration
                           — 22 inputs, all editable
    RESULT:               PASS

Direct contrast on the same build: managed = 0 mutators / 0 inputs; external =
5 mutators / 22 editable inputs. The external path is not forced through RNP.
The probe was afterwards drained and retired through the canonical UI path and
left as a historical record; no external infrastructure was touched.

## PROOF 6 — Primary status presentation — PASS (with deferred polish)

    ACTIVE/READY managed fixture
      PRIMARY STATUS:            "Ready"
      SECONDARY LINE:            "Ready · Location: Local UTCP Kubernetes"
      TECHNICAL STATES IN VIEW:  none
      ADVANCED DETAILS:          present and complete

The normal view no longer shows `desired active`, `observed ready`, or
`provisioning succeeded`. Those remain verbatim under Advanced diagnostics.

## Advanced diagnostics — PASS

One `<details>` element titled "Advanced diagnostics" now exists (the U1 audit
measured zero progressive disclosure anywhere). Expanded, it preserves:

    Endpoints (control/events/health with full FQDN, port, path)
    Declared capabilities
    Credentials — ari-basic v1 · active · fingerprint 4235ce46638f (metadata only)
    Runtime evidence — desired state, observed state, last observation,
      configuration generation 9 · observed generation 9,
      event connection status open, reconciliation state converged,
      next retry, sanitized failure, last successful inspection
    Capability evidence (declared vs observed)
    Full history

Support observability is preserved; only its altitude changed.

### Deferred polish found in adjacent states (non-blocking)

Both are bounded presentation-mapping issues in
`apps/web/src/views/runtimeNodeManagementPresentation.ts`. Neither affects any
of the eight acceptance items, managed authority, or any operator action.

1. **Retired managed runtime shows a contradictory secondary line.** The
   historical fixture `rnp6-managed-asterisk-20260809` renders primary status
   "Retired" (correct, from `runtimeNodePrimaryStatus`) beside the secondary
   line "Starting", because `managedProvisioningLabel` is rendered
   independently and still evaluates provision-succeeded plus
   observed ≠ ready. The provisioning label should be suppressed or overridden
   once `desired_state` is terminal.

2. **External draft runtime is labelled "Creating".** A newly registered
   external node in `draft` shows "Creating", because
   `runtimeNodePrimaryStatus` falls through to `managedProvisioningLabel`,
   which returns 'Creating' for `desired_state === 'draft'` when no
   provisioning operation exists. Nothing is being created for an external
   node — the operator must Activate it. A non-managed draft should read
   "Not in service" or equivalent.

## PROOF 7 — Managed retirement confirmation — PASS

The action is renamed from "Decommission" to **"Retire runtime"** and the
confirmation is management-mode branched
(`RuntimeNodesView.vue:1317-1321`, one function, two strings):

    managed:
      "Retire this runtime? It will permanently leave service.
       UTCP-managed runtime resources will be removed automatically.
       The historical runtime record will remain."

    external:
      "Retire this runtime? It will permanently leave UTCP service.
       Infrastructure managed outside UTCP will not be deleted.
       The historical runtime record will remain."

The managed text communicates all three required truths — permanent departure
from service, automatic removal of UTCP-managed resources, retained historical
record — and no longer claims the infrastructure is external or preserved.
This corrects the RNP-U1 finding that the single shared string told managed
operators the opposite of what happens.

Evidence note, stated precisely: the **external** branch was captured live from
the rendered `window.confirm` dialog on the disposable probe and then
cancelled, proving the dialog renders and that Cancel does not mutate (the
probe remained `drained`). The **managed** branch was verified from source
rather than live, because the Retire action only renders once a node reaches
`drained`, and reaching it would have required draining the retained
ACTIVE/READY proof fixture — which this task explicitly forbids. Both strings
come from the same mode-branched function, so the managed branch is
deterministic given the mode.

## External retirement confirmation sanity — PASS

Captured live and cancelled, as quoted above. Meaning matches the requirement:
runtime leaves UTCP service, externally managed infrastructure is not deleted,
historical record remains.

## PROOF 8 — Duplicate identity validation — PASS

Submitted through the normal managed flow using the retained fixture's own
name, so the derived identifier collides deterministically.

    INPUT NAME:        RNP6 Readiness Reproof 20260809
    DERIVED CONFLICT:  rnp6-readiness-reproof-20260809 (existing ACTIVE/READY node)
    HTTP:              422
    VISIBLE ERROR:     "Provisioning failed — A runtime with this name or
                        identifier already exists."
    FORBIDDEN TERMS:   none — no "Server Error", no 500, no PostgreSQL,
                        no 23505, no constraint name, no SQLSTATE
    RUNTIME COUNT:     10 → 10 (unchanged)
    REQUEST COUNT:     2 → 2 (unchanged)
    PROVISION OPS:     2 → 2 (unchanged)
    SUCCESS EVIDENCE:  none — audit shows no provisioning or node-created event
                        for the attempt
    RESULT:            PASS

This closes the RNP-U1 P1 finding, where the same input produced an unhandled
PostgreSQL 23505 surfaced as "Server Error". No database write was used to
create the duplicate condition; the conflict came from existing canonical
runtime identity.

## Secret exposure review

Rendered DOM swept across the managed detail and expanded Advanced
diagnostics: 0 hits for `ARI_PASSWORD`, `stringData`, or `password`.
Credentials appear as type, version, status, and fingerprint only. No
Kubernetes Secret value was read, decoded, or printed, and no credential
plaintext appears in validation or error presentation.

## Acceptance standard

    [x] normal managed flow requires only Name
    [x] runtime type auto-resolved under one choice
    [x] location auto-resolved under one choice
    [x] slug not required
    [x] managed manual config controls absent
    [x] managed Admin mutation APIs reject with 422
    [x] managed state unchanged after rejection
    [x] external runtime remains editable
    [x] normal managed status is human-oriented
    [x] technical canonical states remain under Advanced
    [x] managed retirement confirmation is truthful
    [x] external confirmation remains truthful
    [x] duplicate identity returns useful validation
    [x] duplicate attempt creates no orphan data
    [x] no secret exposed

## Failed proof steps

    None.

## Fixture state after the reproof

    rnp6-readiness-reproof-20260809   active / ready      (retained, untouched)
    rnp6-managed-asterisk-20260809    retired / deprovisioned (untouched)
    u2-external-control-probe         retired             (disposable, created
                                                           and retired here;
                                                           registry-only, no
                                                           infrastructure)

    RuntimeNodes 10 · provisioning requests 2 · provision operations 2

## Code changes

    None.

## Environment and topology changes

Application images rebuilt from the repository and pushed to the existing
local registry, deployed via `make k8s-apply` plus same-tag rollout restarts.
No cluster, registry, namespace, host port, node topology, persistent volume,
deployment mechanism, or parallel runtime was created or changed.

## Improvised or non-canonical actions

    None.
