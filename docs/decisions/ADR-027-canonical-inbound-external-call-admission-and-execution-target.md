# ADR-027: Canonical Inbound External Call Admission and Execution Target

## Status

Accepted as the canonical V1 inbound architecture. No runtime behavior,
migration, manifest, or provider configuration is implemented by this
documentation packet. It resolves the single architectural gap recorded by the
preceding narrow audit verdict `V1_INBOUND_AUTHORITY_REMAINS_UNRESOLVED` and
makes the next bounded implementation packet unambiguous.

This ADR decides exactly one question: **where an admitted external inbound SIP
INVITE is relayed, and what canonical authority governs that execution target.**
Ingress identity authority, trusted correlation transport, anti-spoofing,
route authority, ordering, C6 adoption timing, and failure semantics were
resolved by prior evidence and are restated here only where this decision
depends on them.

## Context

C7B (`C7bService::evaluateInbound`) already owns canonical inbound route
selection but has no production caller. C6 already adopts inbound runtime
channels (`CallDomainService::adoptInboundLeg`) but binds no route, trunk, or
destination. Kamailio's `route[EXTERNAL_TRUNK_ROUTE]` matches the inbound T6
projection and then replies `200 External Trunk Route Matched` and exits; it is
reached only from the `OPTIONS` branch and never relays an INVITE.

The blocking gap was the execution target. Two directions existed in the
repository and neither was authoritative for external inbound traffic:

* The **static application-runtime Service**
  (`application-runtime-sip.utcp-runtime.svc.cluster.local`, selector
  `utcp.dev/runtime-selection: selected-application-runtime`) is assigned
  entirely in Kustomize manifests. No application code reads, writes, or
  reconciles that selector. It supplies no advance canonical RuntimeNode
  identity and proves no RuntimeNode eligibility.
* The **conference corridor** resolves a per-call canonical SIP target through
  `kamailio_conference_route_view`, joining an active binding to
  `runtime_nodes` (`desired_state = 'active'`, `observed_state = 'ready'`) and
  `runtime_node_endpoints` (`purpose = 'sip'`, enabled). The Kamailio
  configuration check explicitly forbids conference routing from falling back
  to `selected-application-runtime`.

The product dialplans confirm that generic inbound acceptance is not yet a
product contract. Asterisk's `[from-kamailio]` handles only `_[c]o[n]f-.` and
answers every other destination with `Hangup(21)`; the sole generic extension,
`c6-generic-proof`, lives in `components/asterisk-sip-fixtures`, a Kustomize
component included only by the `local` and `local-two-asterisk` overlays and
absent from the `server` overlay. The FreeSWITCH product dialplan contains only
the `9900` echo and `9901` ring fixtures and no inbound context at all.

## Decision

### 1. Accepted execution target authority

**Option B is accepted: the canonical inbound execution target is a
RuntimeNode SIP endpoint derived from canonical RuntimeNode eligibility and
projected for Kamailio consumption.**

The decisive evidence is that this authority already exists in the repository
and does not have to be invented. `TelephonyDomainService::selectRuntimeNodeForConference()`
is a complete, tenant-scoped, eligibility-gated, deterministically ordered
runtime selector that filters on `desired_state`, `observed_state = 'ready'`,
and required `runtime_node_capabilities`, and orders candidates by
`[placement_priority, available-slot rank, active binding count, id]` — a total
order whose trailing `id` guarantees determinism. `runtime_nodes` has carried
`placement_priority` and `capacity_weight` since the original runtime registry
migration, and both are managed through the canonical Admin API.

This matches the repository's own deterministic default for placement:

```text
runtime placement
  -> eligible ready target using deterministic ordering
```

Selection is expressed as a **derived projection**, not a synchronous service.
Kamailio consumes a UTCP-authored view exactly as it consumes
`kamailio_conference_route_view`, ordering by canonical columns and taking one
row. Kamailio applies no policy and holds no selection authority; the view
encodes the canonical predicate and the canonical order.

**No new binding table and no new lifecycle state are introduced.** V1 inbound
needs no pre-call binding, because there is no pre-call entity to bind: the
target is derived from current canonical RuntimeNode state at ingress. The
selected `runtime_node_id` travels with the call as trusted correlation, so the
canonical intent is verifiable at adoption rather than assumed.

### 2. Rejected alternatives

**Option A — promote the selected application-runtime Service.** Rejected. It
cannot be promoted into the canonical external ingress target without becoming
a second runtime-management authority:

* its selector is a deployment-time Kustomize label with no canonical record,
  no audit, and no management through the Web Admin UI or authorized API,
  contradicting the requirement that management authority be the normal
  application lifecycle rather than static hidden manifests;
* it proves no RuntimeNode `desired_state`, `observed_state`,
  `configuration_version` convergence, execution-image currency, or capability,
  so a draining, stale, or image-mismatched runtime would silently receive new
  external calls;
* "Kubernetes will choose a Pod" is infrastructure authority, not telephony
  authority; Kubernetes Service endpoint readiness is a Pod-level fact and is
  not the canonical telephony eligibility contract;
* the repository already rejects this mechanism for calls that must reach a
  canonically governed runtime — the Kamailio configuration check fails if
  conference routing references it;
* the behavior it would deliver into exists only as a test fixture excluded
  from the `server` overlay, so adopting it would promote fixture behavior into
  a product contract.

**Option C — a third model.** Rejected. No cleaner repository-supported
contract exists, and inventing one to avoid A and B is not permitted.

**A synchronous pre-runtime C7B admission service.** Rejected and out of scope:
ordering is settled as Model A, and `DestinationRef` supports only
`telephony_address` and `opaque`, neither of which identifies a runtime.

### 3. Runtime eligibility rule

A RuntimeNode may receive a **new** external inbound call only when all of the
following canonical conditions hold. Each reuses an existing contract; none
introduces a new lifecycle state.

| Condition | Canonical source |
| --- | --- |
| `tenant_id` matches the ingress trunk's tenant | `runtime_nodes.tenant_id` |
| `desired_state = 'active'` | RNM lifecycle (`draft`, `active`, `draining`, `drained`, `disabled`, `retired`) |
| `observed_state = 'ready'` | projection-owned observed state |
| `observed_configuration_version >= configuration_version` | runtime convergence contract |
| execution image is current | `RuntimeExecutionContract::isCurrent(desired_execution_image, observed_execution_image)` |
| capability `call.control` present | `runtime_node_capabilities`; `call.control` is the capability required by `call.leg.answer` and `call.leg.hangup` in `CallOperationCatalog` |
| an enabled SIP endpoint exists | `runtime_node_endpoints` with `purpose = 'sip'`, `enabled = true` |

Ordering across eligible candidates is `placement_priority` ascending, then
`runtime_node_id` ascending. The trailing identifier makes the order total and
therefore deterministic.

`call.origination` is deliberately **not** required: it governs outbound
origination, and an inbound leg is offered by the peer rather than originated by
UTCP.

This is **runtime eligibility, not runtime placement policy**. V1 answers "may
this runtime receive new telephony work at all?" using facts UTCP already owns.
It does not model capacity, load, failure domains, or host topology.

### 4. Request-URI / ingress token contract

**Accepted: a canonical UTCP ingress token of the form `utcp-in-<telephony_address_id>`,
directly analogous to the proven `conf-<participant-uuid>` convention.**

Kamailio rewrites the Request-URI user part to this token before relaying to the
selected runtime.

* It is provider-neutral and encodes no Asterisk or FreeSWITCH semantics.
* Both runtimes can match it deterministically by prefix plus UUID shape, with
  no catch-all extension, exactly as the conference admission user is matched.
* It collides with neither `conf-` nor the `9900`/`9901` fixture destinations,
  so the product contract does not depend on any fixture.
* It is **not** route authority: it names the canonical *address*, never the
  route or the trunk. C7B still evaluates current canonical state afterward.
* It gives both runtimes a stable canonical value to surface as
  `called_address`, replacing dependence on raw dialed digits.

The **normalized called address is rejected** as the token: dialed values are
arbitrary numeric strings that would collide with fixture destinations, would
force a catch-all dialplan entry, and would make the runtime dialplan a de facto
address router.

The token is a routing convenience for the dialplan. The **ingress header family
is authoritative** for correlation.

### 5. Trusted correlation set

Kamailio establishes, and never accepts from a peer, the provider-neutral
ingress family carrying:

```text
ingress_external_trunk_id
ingress_telephony_address_id
ingress_trunk_endpoint_id
ingress_runtime_node_id      (the canonically selected execution target)
```

Wire names follow the established `X-UTCP-*` convention. `ingress_runtime_node_id`
is the fencing element: adoption compares it against the observation receipt's
`runtime_node_id`, so a call landing anywhere other than the canonically selected
target is detected and audited rather than silently accepted.

### 6. Asterisk product inbound contract

Kamailio relays into the existing `[from-kamailio]` context, which is already the
context of both the `anonymous` and `kamailio-edge` PJSIP endpoints.

The product dialplan gains one canonical inbound extension matching
`utcp-in-<uuid>` that reads the ingress headers into channel variables, answers
the leg, and enters the existing observation application
(`utcp-t0-observation`) passing the ingress facts as Stasis application
arguments. The existing `_.` rejection with `Hangup(21)` remains for every
non-canonical destination.

This reuses the proven correlation transport end to end: `Stasis(app, args)` →
ARI `StasisStart.args[]` → `AsteriskAriEventListener::normalizeStasisStart()` →
`application_args`, the same path already used to bind conference participants.
`StasisStart` is what produces `call.leg.offered`, so entering Stasis is
mandatory on Asterisk.

The contract must live in `infrastructure/docker/asterisk/config/extensions.conf`
as product behavior. It must **not** be supplied by `extensions.local.conf` or
any Kustomize fixture component.

### 7. FreeSWITCH product inbound contract

The `utcp-internal` sofia profile already routes into context `utcp`. That
context gains one canonical inbound extension matching `utcp-in-<uuid>` that
answers the leg and parks it under existing ESL observation.

FreeSWITCH emits `call.leg.offered` from `CHANNEL_CREATE`, so no Stasis
equivalent is required; the channel must simply survive rather than fall through
to hangup. Ingress headers arrive as `variable_sip_h_X-UTCP-*`, mirroring the
established outbound `sip_h_X-UTCP-*` injection in `FreeSwitchEslClient`.

The two runtimes therefore differ only in provider mechanism, never in canonical
contract. Both surface `runtime_channel_id`, `called_address`, and the trusted
ingress correlation set; neither performs route or trunk selection.

### 8. Kamailio trust boundary

**External SIP cannot directly invoke internal UTCP execution correlation.**

Kamailio is the sole trust boundary and must, in order:

1. strip every peer-supplied `X-UTCP-*` header from any request arriving from an
   external trunk source, before any correlation logic executes;
2. derive the ingress facts itself from the T6-derived projection using the
   external source identity and called address, rejecting any non-unique match;
3. resolve the execution target from the canonical eligibility projection;
4. add the trusted ingress correlation family;
5. rewrite the Request-URI to the canonical ingress token and relay.

**The existing untrusted execution path must be cut off, not gated.** In
`request_route`, the branch keyed on `X-UTCP-Trunk-Endpoint-ID` currently
executes before the domain guard, so a peer able to reach Kamailio's SIP port
could forge three headers and drive `route[RUNTIME_EXTERNAL_TRUNK]` into an
authenticated outbound relay using the trunk's own credentials. No
`permissions.so` is loaded and `$si` is used nowhere, so no source-trust check
exists today; the path is presently masked only by the absence of a published
host SIP port. The implementation must place that branch behind the canonical
trusted-source boundary so only internal runtime sources may assert `X-UTCP-*`.
It must not be preserved behind a feature gate, allowlist, environment switch,
or compatibility fallback.

No manual allowlist is introduced. Source identity is derived from C7A trunk
endpoint state through the T6 projection, so no source address is configured
outside C7A/T6 authority. The Kubernetes NetworkPolicy CIDR remains transport
admission control only; the committed policy already records that Kubernetes
must not become a provider address allowlist or duplicate ExternalTrunk state.

Runtime SIP endpoints are not exposed as public ingress. `runtime_node_endpoints`
targets are cluster-internal by construction — the same class of value the
conference view already projects as `sip_target` — and remain reachable only from
the authorized signaling path under the existing NetworkPolicy architecture.
Only Kamailio is externally reachable.

### 9. C6 / C7B ordering

The frozen C6C contract governs and is unchanged:

```text
external ingress
  -> Kamailio trusted ingress correlation and execution-target selection
  -> selected RuntimeNode SIP endpoint
  -> runtime channel
  -> call.leg.offered
  -> C6 adoption: offered Call + CallLeg, C7 route seam NULL
  -> C7bService::evaluateInbound(tenant_id, external_trunk_id, telephony_address_id)
  -> attach RouteDecision to the existing CallLeg
```

Adoption is never gated on a successful RouteDecision, so unroutable inbound
calls remain observable. C7B remains the sole canonical inbound route-decision
authority; Kamailio, T6, and runtime adapters never select the canonical route,
and the projected `route_id` is never consumed as canonical decision authority.

### 10. Failure behavior

Kamailio owns the SIP response while no channel exists; UTCP owns canonical
state and audit once a channel exists. There is no fallback routing in either
direction.

| Condition | SIP response | Canonical Call/CallLeg | Audit |
| --- | --- | --- | --- |
| Unknown ingress identity | `404` | none | Kamailio structured log |
| Ambiguous ingress identity | `403` | none | Kamailio structured log |
| Projection query failure | `503` | none | Kamailio structured log |
| Trunk inactive or not accepting new calls | `403` | none | Kamailio structured log |
| No eligible execution target | `503` | none | Kamailio structured log |
| Relay failure to the selected target | `503` via `t_on_failure` | none | Kamailio structured log |
| Malformed or missing internal correlation | none | offered, route seam NULL | `ingress_correlation_missing` |
| Receipt tenant differs from ingress trunk tenant | none | offered, **no** route binding | `ingress_tenant_mismatch` |
| Landed runtime differs from selected target | none | offered, route seam attached only after re-validation | `ingress_execution_target_mismatch` |
| No matching inbound route | none | offered, route seam NULL, then terminated | `RouteDecision::failed('inbound','no_matching_route')` |
| Ineligible route or trunk | none | offered, route seam NULL, then terminated | `RouteDecision::failed('inbound','no_eligible_route')` with the C7A rejection code |
| Duplicate `call.leg.offered` | none | existing leg returned, `created: false` | none |

Every Kamailio branch terminates in an explicit reply plus `exit` and a
structured `result=` token, matching the existing conference and outbound routes.

### 11. RNM / T5 interaction

No new runtime lifecycle state is introduced. New inbound calls are admitted
only to `desired_state = 'active'`.

| RuntimeNode condition | May receive a NEW inbound call | Basis |
| --- | --- | --- |
| `active` and ready and converged | Yes | full eligibility rule |
| `draining` | **No** | the drain coordinator converges active bindings to zero before `drained`; admitting new work would prevent convergence |
| `drained` | No | zero remaining work is the precondition of the state |
| `retired` | No | listener leases released; reconciliation targets deleted |
| `disabled` / `draft` | No | not an operational state |
| `observed_state != 'ready'` | No | readiness is the canonical observation gate |
| stale configuration | No | `observed_configuration_version < configuration_version` |
| execution image mismatched | No | `RuntimeExecutionContract::isCurrent()` is false |

This deliberately differs from `CommandWorker::resolveAdapter()`, which permits
`draining` for operations on **existing** legs. Continuing to control an
established call on a draining node is correct; starting new work on it is not.
T5 convergence and failover are unaffected: existing legs keep their bound
runtime, and the eligibility projection naturally excludes a failed node from
subsequent inbound calls without any new failover mechanism.

### 12. K5 interaction

This decision **does not depend on K5, and K5 will not have to destroy it.**

V1 owns deterministic single-call runtime *eligibility* and the execution-target
contract. K5 later owns distributed host placement, capacity, and
failure-domain policy. K5C extends the *ordering inputs* of the same projection;
it does not change who holds the authority, so the V1 contract survives
unchanged. `capacity_weight` already exists and is deliberately left unused by
V1 to avoid pulling capacity policy forward.

No K5A–K5E implementation is introduced: there is no Kubernetes Node
observation, no host correlation, no failure-domain modelling, and no
reimplementation of Kubernetes scheduling.

### 13. Security implications

* External peers cannot assert canonical ExternalTrunk, TelephonyAddress,
  RouteDecision, or runtime identity; Kamailio strips and re-establishes all
  correlation.
* A pre-existing forgery and toll-fraud vector — external requests reaching
  `route[RUNTIME_EXTERNAL_TRUNK]` through forged headers before the domain
  guard — is cut off as part of this contract rather than deferred.
* Canonical identifiers remain UUIDs. No provider-specific identifier enters
  Call or C7 state; `DestinationRef::opaque()` already rejects provider names
  structurally.
* Normalizers keep their explicit allow-list (`$safe`) sanitization, so unknown
  provider fields cannot reach canonical state.
* No new externally reachable port, Service type, host port, or NodePort is
  introduced. Only Kamailio is externally reachable.
* No credential, secret, allowlist, or environment switch is introduced.

### 14. Implementation consequences

The following become fully specified and bounded. This ADR implements none of
them.

* A canonical inbound execution-target projection view expressing the
  eligibility rule and deterministic ordering, granted to the existing Kamailio
  reader role, alongside `tenant_id` and a normalized peer host on the inbound
  route view.
* A focused test asserting that the SQL execution-image predicate matches
  `RuntimeExecutionContract::isCurrent()`, so the view never becomes a second
  execution-contract authority.
* Kamailio: inbound INVITE admission, `X-UTCP-*` ingress stripping, the
  trusted-source guard that cuts off the header-triggered branch, execution
  target resolution, ingress correlation, Request-URI rewrite, and a real relay
  replacing the current `200 … exit` verification stub.
* Product inbound dialplan contracts for Asterisk and FreeSWITCH.
* Normalizer allow-list extensions on both providers, including
  `called_address` on Asterisk, which does not emit it today.
* `call_legs.inbound_route_id` plus route-seam binding, mirroring the existing
  outbound binding migration.
* `CallObservationProcessor` invoking `C7bService::evaluateInbound()` after
  adoption and attaching the decision, with the failure and audit behavior above.

## Consequences

* UTCP gains a canonical, auditable answer to "which runtime may receive this
  external call", grounded in RuntimeNode authority rather than a Kubernetes
  label.
* Inbound and outbound corridors become symmetric: both derive an execution
  target from canonical state and carry trusted `X-UTCP-*` correlation.
* The static `selected-application-runtime` selector is not extended to external
  ingress and does not become a second runtime-management authority. This ADR
  does not remove it; the browser application-dialog corridor that uses it is
  unchanged and out of scope.
* A latent security defect is closed as a precondition of external reachability
  rather than after it.
* Fixture-supplied inbound behavior is replaced by a product contract, so
  inbound works identically on every overlay including `server`.
