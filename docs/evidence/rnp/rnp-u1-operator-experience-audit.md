# RNP-U1 — Operator Experience and Workflow Simplification Audit

## Verdict

    RNP_USABILITY_STREAMLINING_PACKET_RECOMMENDED_BEFORE_V0

RNP remains technically complete. The backend authority model is not
questioned by this audit and nothing here reopens it. The managed-runtime
*surface*, however, still requires the primary persona to supply and interpret
implementation detail, and it exposes managed-runtime editing controls that
can silently break a UTCP-managed runtime.

One finding is a genuine P0 workflow contradiction rather than terminology
polish, which is why streamlining is recommended before V0 rather than
deferred as optional.

## Method

Evidence-only. No source was modified. Natural Playwright session from
`https://app.utcp.local.test/login` with a bounded break-glass password
changed through the application's own forced flow; tenant and capabilities as
returned by the application. Rendered product inspected directly; repository
source consulted only to establish whether an observed behaviour is
deterministic.

Environment: `k3d-utcp-local`, all platform workloads Ready, retained RNP-6
readiness fixture `rnp6-readiness-reproof-20260809` in `active/ready`.

No fixture was created or destroyed for this audit. Existing fixtures were
used: one READY managed runtime, one RETIRED/deprovisioned managed runtime,
and seven external runtimes. State after the audit is unchanged: 9
RuntimeNodes, 2 provisioning requests, fixture still `active/ready`.

## Current managed workflow, measured

    CLICKS:   6   (Managed Runtime → target select → target option →
                   Review generated configuration → Deploy Runtime,
                   plus the Name/Slug focus interactions)
    FIELDS:   4   (Runtime type, Deployment target, Name, Slug)
    USER DECISIONS: 4 required, of which 1 is genuinely meaningful (Name)
    TECHNICAL CONCEPTS EXPOSED: 8 before submission
                   (Managed Runtime vs Register Existing, Runtime type,
                    Deployment target, "Local UTCP Kubernetes", Slug,
                    "generated configuration", Management mode, Endpoints/
                    Credentials as generated artifacts)

Measured from the rendered DOM, not inferred:

* `#managed-runtime-family` — `<select>` with exactly **one** option
  ("Asterisk"), already valued `asterisk`, still marked required.
* `#managed-deployment-target` — `<select>` with a `Select a deployment
  target` placeholder plus exactly **one** real option ("Local UTCP
  Kubernetes"). The placeholder is selected by default, so the operator is
  forced to make a choice that has no alternative.
* `#managed-runtime-name` — free text, required. The only real intent.
* `#managed-runtime-slug` — free text, required, placeholder `runtime-slug`.
* `Review generated configuration` — primary button, **disabled** until all
  four are satisfied.

### Slug does not derive from Name

Verified live: filling Name with `Berlin Voice Node 2` left the Slug field
empty and the primary action disabled; selecting the deployment target did not
change that. The administrator must invent a URL-style identifier before the
workflow can continue.

## Part B — Input authority classification

| Input | Current | Classification | Recommendation |
|---|---|---|---|
| Name | required free text | USER_INTENT | Keep. The only meaningful decision. |
| Slug | required free text | DERIVABLE | Derive from Name; expose under Advanced only if editing is genuinely wanted. Backend already calls `Str::slug()` on whatever arrives (`RuntimeRegistryService::normalizeSlug`, `RuntimeProvisioningService::requestProvisioning`), so the derived value is deterministic. |
| Runtime type | required select, 1 option | SYSTEM_INTERNAL today | Hide from the normal flow; managed provisioning supports only `asterisk`/`asterisk-ari` (`RuntimeProvisioningService::MANAGED_RUNTIME`) and rejects anything else at the service boundary. Reintroduce a selector when a second managed family genuinely exists. |
| Deployment target | required select, 1 option + placeholder | DERIVABLE today, ADVANCED_OPTION later | Auto-select when exactly one target exists; show a selector only when more than one is returned. |

## Part C — Single-choice controls

Two controls currently have exactly one valid choice:

* **Runtime type** — one option, pre-valued. It costs no click today but
  occupies primary space and introduces the word "adapter"-adjacent typing to
  the persona. Hide it (or render read-only) while only one managed family
  exists.
* **Deployment target** — one option **behind a placeholder**, so it costs a
  real forced decision. This is the clearest example of friction with zero
  decision value. Auto-select the sole target.

Neither should be preserved on the theory that more options may exist later.
When a second option appears, the selector returns naturally.

## Part D — Slug assessment

Deterministic derivation is supported by the repository:

* Normalization already exists and is applied server-side regardless of input
  (`Str::slug`), so `"Berlin Voice Node 2"` → `berlin-voice-node-2`
  deterministically.
* Uniqueness is scoped per tenant by
  `runtime_nodes_tenant_slug_unique (tenant_id, slug)`.
* Resource naming does not require operator input: managed Kubernetes names
  are already built as
  `asterisk-<slug-prefix>-<sha256(runtimeNodeId) first 8>`
  (`ManagedAsteriskResourceIdentity::names`), so the node UUID — not the slug —
  supplies collision resistance for infrastructure names.

**Collision handling is where this currently fails.** `createNode` performs a
bare `DB::table('runtime_nodes')->insert(...)` with no unique-violation catch.
Proven live through the real form: submitting a Name of `Duplicate Slug Probe`
with the slug of the already-retired fixture returned

    POST /api/v1/admin/runtime-provisioning → 500
    UI: "Provisioning failed — Server Error"

with `23505 / duplicate key / Unique` in the API log. The transaction rolled
back cleanly (node and request counts unchanged, no partial fixture), so this
is a presentation and derivation defect, not a data-integrity defect.

This is reachable in ordinary use because **retired runtimes keep their slug
permanently** by design. An operator who retires "Berlin Node" and later
creates another "Berlin Node" gets a raw 500.

Recommended direction, supported by the evidence: derive the slug from Name,
and on collision either suffix deterministically or return a field-level
validation message — never a 500.

## Part E — Review-step value

The review lists eight rows. Only **one** (Name) was a free operator decision;
Runtime type and Deployment target each had a single possible value, Slug is
mechanically derivable from Name, and the remaining four rows — Management,
Credentials, Endpoints, Infrastructure — are constant explanatory text
identical for every managed runtime.

The step therefore prevents no realistic mistake. Its genuine value is
*reassurance* ("UTCP will handle credentials, endpoints, and infrastructure"),
which does not require a separate wizard step.

Note the inversion this creates: the **advanced** external path submits
directly with a single `Create runtime node` button and no review, while the
**simple** managed path requires an extra review step. The intended difficulty
gradient is backwards.

## Part F — Managed vs existing entry point

Both entry buttons render as `ui-button--secondary` — identical visual weight,
no default, no recommended path, and no `aria-pressed` state. The supporting
sentence is implementation-oriented:

    "Choose whether UTCP should provision the infrastructure or register a
     runtime whose infrastructure is managed elsewhere."

The primary persona is asked to choose between two equally weighted options
using the word "provision". Recommended hierarchy (semantics preserved):

    Create a new runtime            [primary]
    UTCP creates and manages it automatically.

    Register an existing runtime    [secondary/advanced]
    Connect telephony infrastructure you already operate.

The existing-runtime path must remain fully available; only its prominence and
labelling change.

## Part G — Terminology audit

| Current term | Classification | Proposed presentation | Reason |
|---|---|---|---|
| Managed Runtime | TECHNICAL_AND_HIDEABLE | "Create a new runtime" | Persona thinks in create-vs-connect, not management mode. |
| Register Existing Runtime | TECHNICAL_BUT_NECESSARY | "Register an existing runtime" | Accurate and already close to natural. |
| UTCP managed | NATURAL | keep | Useful, short ownership badge. |
| External | NATURAL | keep | Correct counterpart to the above. |
| RuntimeNode / Runtime node | TELEPHONY_DOMAIN | keep | Core product noun; persona learns it once. |
| Deployment target | TECHNICAL_AND_HIDEABLE | "Location" | Only meaningful when more than one exists. |
| Local UTCP Kubernetes | TECHNICAL_AND_HIDEABLE | "Local environment" | Names the orchestrator, which the persona must not need. |
| Slug | TECHNICAL_AND_HIDEABLE | derive; "Identifier" under Advanced | Pure implementation identifier. |
| Runtime type / family | TECHNICAL_BUT_NECESSARY | keep, but hide while single-valued | Meaningful once FreeSWITCH is managed. |
| Adapter / `asterisk-ari` | TECHNICAL_AND_HIDEABLE (managed) / NECESSARY (external) | Advanced only on managed | Never an operator choice on the managed path. |
| Provisioning: Requested | TECHNICAL_AND_HIDEABLE | "Creating" | Exposes request-vs-operation distinction. |
| Provisioning infrastructure | TECHNICAL_AND_HIDEABLE | "Creating runtime" | "Infrastructure" is the thing we promised to hide. |
| Activated; waiting for readiness | TECHNICAL_AND_HIDEABLE | "Starting" | Leaks the activation/observation split. |
| Provisioning: Ready | NATURAL | "Ready" | Drop the "Provisioning:" prefix once ready. |
| desired active / observed ready | TECHNICAL_AND_HIDEABLE | one status chip | Two-axis state model is control-plane internals. |
| observed unavailable | MISLEADING for the persona | "Not responding" / "Needs attention" | Reads like a UTCP outage rather than the runtime's health. |
| observed stale / unobserved | TECHNICAL_AND_HIDEABLE | "No recent data" | Projection vocabulary. |
| Drain / Draining / Drained | TELEPHONY_DOMAIN | "Take out of service"; keep Drain as the advanced term | Legitimate telephony term, but the consequence deserves plain phrasing. Existing helper text is already good. |
| Decommission | TECHNICAL_BUT_NECESSARY | "Retire runtime" | "Retired" is already the resulting state; align the verb. |
| Disable | TELEPHONY_DOMAIN | keep | Short and understood. |
| Infrastructure: Deprovisioning / Deprovisioned | TECHNICAL_AND_HIDEABLE | "Removing runtime resources" / "Runtime resources removed" | Correct but infrastructure-oriented. |
| Capability evidence: unknown | MISLEADING | hide unless it matters | Displayed on a fully healthy READY node (see below). |
| Endpoint / Capability / Operation / Configuration generation | TECHNICAL_BUT_NECESSARY (diagnostics) | Advanced section | Real support value, wrong altitude for primary view. |

## Part I — Status presentation

The list row currently shows three parallel status expressions at once:

    UTCP managed | desired active | observed ready | Provisioning: Ready

For the READY fixture this is four statements of the same fact in three
vocabularies. A deterministic presentation mapping (no new persisted
lifecycle, no backend change) collapses these:

| Canonical inputs | Proposed single status |
|---|---|
| provision op pending/leased/running/retry_scheduled | Creating |
| provision succeeded, observed ≠ ready, desired active | Starting |
| desired active, observed ready | Ready |
| observed unavailable/degraded, or op terminal_failed/expired | Needs attention |
| desired draining | Taking out of service |
| desired drained | Out of service |
| desired retired | Retired |
| deprovision succeeded | Retired · resources removed |

`desired_state`, `observed_state`, operation status, generations, and
reconciliation stay verbatim in the advanced section. The mapping must remain
pure presentation — the canonical states remain the only authority.

## Part J — Error presentation

One representative failure was captured live rather than inferred:

    UI: "Provisioning failed — Dismiss — Server Error"

That is the entire operator-facing message for a duplicate identifier. It
names no cause, no field, and no remedy. The correct shape is a plain
statement plus optional sanitized detail:

    That name is already in use by another runtime.
    Choose a different name.

For genuinely transient infrastructure failures — the class the first RNP-6
run hit as `provisioning_unavailable_to_control` across three retries before
succeeding automatically — the message should communicate that the retry is
automatic:

    Runtime could not be created yet. UTCP is retrying automatically.
    [Technical details]

No routine error path currently instructs operators toward `kubectl`,
`artisan`, SQL, or YAML, and none should. Failure class and code belong under
[Technical details].

## Part K/L — Lifecycle actions and destructive confirmation

The in-page helper text for drain states is already good and persona-
appropriate:

    "Draining — no new work will be assigned; existing work continues."
    "Drained — no active workload remains; the node is excluded from placement."

The **destructive confirmation is actively wrong for managed runtimes**:

    "Decommission this runtime node? UTCP operational authority and
     credentials will be retired; historical records remain, and externally
     managed infrastructure will not be destroyed."

For a UTCP-managed runtime the infrastructure *is* destroyed automatically —
RNP-6 proved exactly one `runtime.node.deprovision` removes the Deployment,
Service, and Secret. The confirmation tells the operator the opposite of what
happens. This is a single string reused from the external path
(`RuntimeNodesView.vue:1387`) and must branch on management mode. Proposed
managed wording:

    Retire this runtime?
    It will be permanently removed from service and cannot be brought back.
    UTCP will automatically delete the runtime's resources.
    Its history and records are kept.

Secondary observation: `Disable` renders with `ui-button--danger` styling but
has **no** confirmation, while only Decommission and endpoint removal are
confirmed. Since Disable is reversible, the absence of a prompt is defensible;
the danger styling is what is inconsistent.

## Part M — Detail information hierarchy

The detail panel has **zero progressive disclosure**: 0 `<details>` elements,
0 `summary` elements, and the only `aria-expanded` control on the page is the
navigation Menu. "Details" is one all-or-nothing toggle that reveals, in a
flat list, everything below.

### Primary (should be immediate)

    Name
    Single status ("Ready")
    Managed by UTCP · Location
    Lifecycle actions (Take out of service / Retire)
    Any active problem

### Secondary

    Runtime type
    Last observed
    Create/remove progress while a lifecycle operation is running

### Advanced diagnostics (should be collapsed)

    RuntimeNode ID, operation IDs, provisioning request ID
    adapter key, endpoint hosts/ports/paths, declared capabilities
    Kubernetes workload identity
    desired_state / observed_state, configuration + observed generation
    reconciliation state, next retry, event connection status
    failure class/code, capability evidence, full history

Nothing above should be removed — all of it has genuine support value. Only
its altitude is wrong.

### P0: managed runtimes expose external-runtime editing controls

On the **managed** READY fixture the detail panel renders the complete
external-integration editing surface, all live and all unguarded:

    Endpoints:    Purpose/Transport/Host/Port/Path editors, "Add endpoint",
                  and Save/Remove on each of the three RNP-generated endpoints
    Capabilities: "Set capabilities"
    Credentials:  Credential type / Identifier / Write-only secret /
                  "Save credential", plus "Rotate" on the managed ARI credential
    Adapter:      seven required ARI timing fields with "Save adapter configuration"

Every one of these values is generated and owned by RNP for a managed runtime.
Source confirms there is no managed-mode guard on any of these paths:
`rotateCredential`, `writeCredential`, endpoint upsert/delete, and
`setCapabilities` contain no management-mode check — the only guard is
`assertNodeNotRetired`.

The credential case is not merely clutter, it is a live hazard.
`applySecret` is called from exactly one place, the provisioning handler
(`ManagedAsteriskProvisioningOperationHandler.php:59`). Nothing re-applies the
Kubernetes Secret on rotation. So an operator pressing the exposed **Rotate**
button on a managed runtime updates UTCP's canonical credential while the
Kubernetes Secret — and therefore the running Asterisk — keeps the old one.
The predicted result is an authentication break and a runtime that drops to
`unavailable`, caused by a button offered on the normal management surface.

This mechanism is established from source and the single-call-site fact. It
was deliberately **not** executed against the retained live fixture: the audit
forbids reopening proven behaviour and there is no reason to break a healthy
proof artifact to confirm a one-call-site conclusion. Confirming it live, if
wanted, belongs in the fix packet's reproof on a disposable fixture.

### Misleading readiness signal

The healthy READY fixture simultaneously displays:

    Capability evidence: unknown
    Observed: Not yet observed
    Capability evidence freshness: unknown

`runtime_node_observed_capabilities` is legitimately empty because the
readiness observation payload carries `capabilities: null`. That is correct
projection behaviour, but presenting "unknown" and "Not yet observed" beside a
Ready runtime reads as a problem to the primary persona.

## Part N — External runtime workflow

The external form asks for Display name, Slug (required), Runtime family
(Asterisk / FreeSWITCH / Deterministic simulator), and Adapter (dependent
select), then submits directly via `Create runtime node`.

Those controls are legitimately necessary — the operator is describing
infrastructure UTCP did not create. The external path should **not** be held
to the managed path's simplicity standard, and this audit does not recommend
simplifying it. Two points do apply:

* It is not currently communicated as an advanced integration path; it is
  presented as a peer of the managed path.
* Normal users can avoid it entirely once the managed path is the prominent
  default — which is the recommendation in Part F.

No managed-path field exists solely to serve the external path; the two forms
are already separate components.

## Part O — Current vs recommended metrics

### Current

    Clicks: 6
    Fields: 4
    Decisions: 4 (1 meaningful)
    Technical concepts: 8

### Recommended

    Clicks: 3   (Add Runtime → Create a new runtime → Create Runtime)
    Fields: 1   (Name)
    Decisions: 1
    Technical concepts: 1  (the runtime is managed by UTCP)

### Recommended normal workflow

    Runtime Nodes
    → Add Runtime
    → Create a new runtime          [primary path]

        Name:  [ Berlin Voice Node 2 ]

        UTCP will create this runtime, generate its credentials and
        endpoints, and start it automatically.

        [ Create Runtime ]

with Asterisk implied while it is the only managed family, the sole location
auto-selected, the identifier derived from Name, and credentials, endpoints,
and infrastructure automatic. An "Advanced" disclosure retains Identifier and
Location for operators who need them.

## Part Q — Misconfiguration opportunities

| Opportunity | Current | Preferred resolution |
|---|---|---|
| Duplicate/colliding identifier → raw 500 | operator types a slug that already exists, including one held by a retired runtime | remove the choice (derive from Name) + deterministic collision handling; validation message as the fallback, never a 500 |
| Wrong deployment location | placeholder forces a pick from one option | remove the choice while a single target exists |
| Incorrect runtime identity | runtime type select present though single-valued | remove from the normal flow |
| Hand-editing managed endpoints/capabilities/adapter config | fully editable on managed runtimes | make read-only for managed mode, with RNP as the stated owner |
| Rotating a managed ARI credential out of band | exposed Rotate button, no Secret re-sync | hide or block for managed mode (or re-sync the Secret — a backend decision outside this audit's scope) |
| Misleading destructive confirmation | says managed infrastructure will not be destroyed | branch confirmation text on management mode |

Ordering preference honoured throughout: eliminate the choice > safe default >
validation > documentation.

## Findings by priority

### P0 — Workflow contradiction

**P0-1. Managed runtimes expose unguarded external-integration editing,
including a credential Rotate that desynchronizes the Kubernetes Secret.** The
normal management surface offers controls that are not the operator's business
for a managed runtime and at least one that can silently break it. Normal
managed operation should not require knowing which of these controls are safe.

### P1 — Major friction

**P1-1. Slug is a required, non-derived, operator-invented identifier**, and
colliding with any existing or retired runtime yields `500 Server Error` with
no actionable message. Live-proven.

**P1-2. Deployment target forces a decision with exactly one option** because
of a placeholder default.

**P1-3. Destructive confirmation is wrong for managed runtimes** — it states
that infrastructure will not be destroyed when RNP-4 destroys it
automatically.

**P1-4. Error presentation surfaces "Server Error"** as the operator-facing
explanation, with no cause, field, or remedy, and no automatic-retry framing
for transient failures.

### P2 — Terminology and hierarchy

**P2-1. Three parallel status vocabularies** on one row (`desired`,
`observed`, `Provisioning:`) with no single human status.

**P2-2. No progressive disclosure anywhere** — 0 `<details>` elements; primary
and diagnostic information share one flat surface.

**P2-3. Infrastructure-oriented vocabulary** in the normal flow: "Deployment
target", "Local UTCP Kubernetes", "Provisioning infrastructure", "Activated;
waiting for readiness", "Deprovisioned", "Slug".

**P2-4. Entry points are visually equal** with no recommended default, and the
"simple" managed path has *more* steps than the advanced external path.

**P2-5. "Capability evidence: unknown" / "Not yet observed" shown on a healthy
READY runtime**, reading as a fault.

**P2-6. Runtime type select rendered though single-valued.**

### P3 — Cosmetic

**P3-1. `Disable` uses danger styling without confirmation** while reversible;
styling and confirmation policy are inconsistent with each other.

## RNP closure assessment

RNP remains **technically complete**. Nothing in this audit contradicts
RNP-1 through RNP-6, and the composed RNP-6 live proof stands. All findings
are surface-level: presentation, disclosure, derivation, and guarding of a
management surface whose underlying authority is already correct and proven.

P0-1 is a UI-authority gap (missing managed-mode guard), not a defect in the
provisioning, lifecycle, or projection authorities.

## Bounded implementation packets

One packet is preferred. The work splits cleanly into a frontend-only majority
and one small backend item.

### Packet RNP-U2 — Managed runtime operator experience (single bounded packet)

**1. Derive the identifier from Name**

    FILE/COMPONENT: apps/web/src/views/RuntimeNodesView.vue (managed form)
    CURRENT:  Slug is a required, empty, operator-typed field; primary action
              stays disabled until it is filled.
    PROPOSED: Derive the slug from Name client-side using the same
              normalization the backend applies; move manual editing to an
              Advanced disclosure.
    WHY:      Removes the only unavoidable technical input from the flow.
    BACKEND AUTHORITY CHANGE: NO      MIGRATION: NO      LIVE REPROOF: YES

**2. Handle identifier collision deterministically**

    FILE/COMPONENT: apps/api/app/RuntimeRegistry/RuntimeRegistryService.php
                    (createNode) and/or RuntimeProvisioningService
    CURRENT:  bare insert against runtime_nodes_tenant_slug_unique → PG 23505
              → HTTP 500 → "Server Error".
    PROPOSED: Catch the unique violation and return a 422 field-level
              validation error; optionally suffix deterministically when the
              slug was derived rather than operator-supplied.
    WHY:      Reachable in ordinary use because retired runtimes keep slugs.
    BACKEND AUTHORITY CHANGE: NO (error mapping only)
    MIGRATION: NO      LIVE REPROOF: YES

**3. Collapse single-choice controls**

    FILE/COMPONENT: apps/web/src/views/RuntimeNodesView.vue (managed form)
    CURRENT:  Runtime type select with one option; Deployment target select
              with a placeholder plus one option.
    PROPOSED: Auto-select the sole deployment target and drop the placeholder;
              hide runtime type while only one managed family exists; render
              both as selectors again as soon as the API returns more than one.
    WHY:      Removes two decisions with no decision value.
    BACKEND AUTHORITY CHANGE: NO      MIGRATION: NO      LIVE REPROOF: YES

**4. Make managed-owned configuration read-only in the UI**

    FILE/COMPONENT: apps/web/src/views/RuntimeNodesView.vue (detail panel)
    CURRENT:  Endpoint, capability, credential (incl. Rotate), and adapter
              editors are fully live for managed runtimes.
    PROPOSED: When management mode is "managed", render these read-only under
              Advanced with an explicit "managed automatically by UTCP" note,
              and remove the Rotate affordance from the managed path.
    WHY:      Closes P0-1 at the surface. Rotate currently desynchronizes the
              Kubernetes Secret because applySecret has one call site.
    BACKEND AUTHORITY CHANGE: NO      MIGRATION: NO      LIVE REPROOF: YES

    NOTE: Whether the API should additionally reject these mutations for
    managed nodes is a separate authority decision. It is deliberately not
    proposed here; the UI guard removes the operator-facing hazard without
    touching proven backend authority.

**5. Single human status with advanced disclosure**

    FILE/COMPONENT: apps/web/src/views/runtimeNodeManagementPresentation.ts
                    and RuntimeNodesView.vue
    CURRENT:  desired/observed/Provisioning shown in parallel.
    PROPOSED: One derived status chip per the Part I mapping; canonical states
              verbatim under Advanced. Pure presentation mapping — no new
              persisted lifecycle.
    WHY:      One vocabulary instead of three.
    BACKEND AUTHORITY CHANGE: NO      MIGRATION: NO      LIVE REPROOF: YES

**6. Management-mode-aware destructive confirmation**

    FILE/COMPONENT: apps/web/src/views/RuntimeNodesView.vue:1387
    CURRENT:  One string claiming infrastructure will not be destroyed.
    PROPOSED: Branch on management mode; managed wording states that UTCP
              deletes the runtime's resources automatically, that retirement
              is permanent, and that history is retained.
    WHY:      The current text is the opposite of proven managed behaviour.
    BACKEND AUTHORITY CHANGE: NO      MIGRATION: NO      LIVE REPROOF: YES

**7. Entry-point hierarchy and vocabulary**

    FILE/COMPONENT: apps/web/src/views/RuntimeNodesView.vue (Add runtime panel)
                    plus the label map from Parts G/H
    CURRENT:  Two equally weighted secondary buttons; infrastructure-oriented
              labels throughout.
    PROPOSED: "Create a new runtime" as primary, "Register an existing
              runtime" as the advanced path; apply the approved label map;
              suppress "Capability evidence: unknown" on healthy runtimes.
    WHY:      Aligns the surface with the product principle.
    BACKEND AUTHORITY CHANGE: NO      MIGRATION: NO      LIVE REPROOF: YES

### Architecture guardrails preserved

No recommendation adds a feature gate, environment opt-in, manual reconcile,
manual provisioning or deprovisioning, CLI management, a second runtime UI, a
second lifecycle state machine, per-runtime image controls, or manifest
editing. One RuntimeNode authority, one managed provisioning authority, one
infrastructure worker, RNM/RNP authority separation, canonical
readiness/projection authority, the managed-vs-external distinction,
historical retention, and automatic credential/endpoint/deprovision behaviour
all remain exactly as proven.

## Environment and topology changes

    None.

## Improvised or non-canonical actions

    None.

## Code changes

    None.
