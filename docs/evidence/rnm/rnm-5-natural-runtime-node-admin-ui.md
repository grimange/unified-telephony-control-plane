# RNM-5 — Natural RuntimeNode Admin UI

Status: implementation complete, repository-tested, canonically redeployed,
and naturally browser-proven. The canonical browser fixture gap and the three
acceptance defects found during the first run are closed. RNM-5 is complete.

## Authority and scope

The UI consumes the existing authenticated RuntimeNode API. Desired/configured
state and declared capabilities remain owned by `RuntimeRegistryService`;
observed runtime state and observed capabilities remain projection/evidence
owned. No new management endpoint, registry, provisioning path, or Kubernetes
operator workflow was introduced.

The create flow explicitly registers an existing or externally managed
runtime. Managed Asterisk/FreeSWITCH deployment remains the future RNP
capability.

## Implemented surface

The Runtime Nodes view provides:

- operational list badges for desired/observed state and loaded capability drift;
- safe name and placement metadata editing;
- endpoint add, edit, and remove;
- write-only credential create, rotate, and retire metadata workflows;
- declared capability editing separate from read-only observed capability evidence;
- human-readable health, freshness, drain, timeout, decommission, and failure
  evidence;
- state-aware lifecycle actions, including cancel drain, reactivate, and
  decommission confirmation;
- history loading beyond the initial page through the existing `before` cursor.

Retired nodes are read-only in the normal UI. Runtime family, adapter identity,
raw labels, Kubernetes workload data, kubeconfig, Docker, and deployment
provisioning are not exposed as normal operator inputs.

## Canonical fixture path (gap closed)

The blocker recorded previously — "Local Tenant contains zero RuntimeNodes" —
was preceded by a larger environment gap: the deployed `utcp-local` API and web
images predated all RNM work, so the earlier navigation proof exercised the
pre-RNM C2 UI. The deployed API returned `404` for
`POST /api/v1/admin/runtime-nodes/{id}/decommission` while `desired-state`
returned `419`.

Resolved through the canonical repository lifecycle only:
`make k8s-image-build`, `make k8s-image-push`, `make k8s-apply` (which re-ran
the migration Job and applied the four additive RNM migrations), then
`make k8s-restart-proof` plus pod replacement for the three telephony worker
Deployments that target is not responsible for. No cluster, registry, host port,
node topology, or persistent volume was changed.

The safe fixture type is the **deterministic simulator**, confirmed from the
live catalog rather than assumed: `simulator-deterministic` is exposed by
`GET /api/v1/admin/runtime-node-catalog` with `credentials_required: false` and
zero endpoint requirements, so it needs no external connectivity and cannot
reach a real PBX.

## Browser proof (natural login, 2026-08-08)

Started at `https://app.utcp.local.test/login`, authenticated with a bounded
break-glass credential, completed the forced password change, selected
`Local Tenant` in the real selector, and navigated to Runtime Nodes. Session
returned `runtime.nodes.view`, `runtime.nodes.manage`,
`runtime.credentials.rotate`. No injected cookies, preset storage state, or
database/Redis session was used.

Fixture: `RNM5 Browser Proof` / `rnm5-browser-proof`, simulator /
`simulator-deterministic`, tenant-scoped, created through the create form.

| Step | Result | Evidence |
| --- | --- | --- |
| Fixture creation | PROVEN | Created `draft`/`unobserved` from the UI form |
| Detail | PROVEN | Identity, desired/observed state, evidence, declared + observed capability areas, freshness, history all render |
| Metadata editing | PROVEN | Name/region/zone/priority 50/capacity 70 persisted; generation 1→2; `runtime_node.updated` recorded |
| Endpoint add/edit/remove | PROVEN | `simulator-proof.local.test:18089` added, edited to `:18090` priority 25, removed behind a confirm dialog; three `endpoints_changed` records |
| Credential create | PROVEN | v1 active with fingerprint/version/status; secret absent from DOM and from API JSON |
| Credential rotate (UI) | PROVEN | Rotation succeeds through the UI; v2 becomes active and v1 is retired |
| Credential retire | NOT_APPLICABLE | Correctly not offered; backend forbids retiring the last active credential of a type |
| Declared capabilities | PROVEN | `event.stream`, `runtime.configuration`, `runtime.observation` set from checkboxes |
| Adapter configuration | PROVEN | `steady-ready` scenario saved through the generic descriptor form |
| Activate | PROVEN | `draft → active`; actions became Drain/Disable |
| Readiness convergence | PROVEN | Reconciler issued two `runtime.node.inspect` operations, both succeeded; node reached `observed ready`, observed generation 10 = desired 10, connection `open`, reconciliation `converged` |
| Observed capability evidence | PARTIAL | Presentation proven (`Observed: Not yet observed`, freshness `unknown`); populated state not producible — see gap below |
| Drain | PROVEN | `active → draining` (user, 06:20:35) then `draining → drained` (system, 06:20:37) |
| Drain record | PROVEN | `drain_state completed`, `initial_work 0`, `remaining_work 0`, `started_at`, `last_evaluated_at`, `deadline_at` (+1h), `completed_at`, `timed_out false` |
| Reactivate | PROVEN | `drained → active`, Drain/Disable restored, then re-drained |
| Decommission confirmation | PROVEN | States retirement of UTCP authority and credentials, retention of historical records, and that externally managed infrastructure is not destroyed |
| Decommission operation | PROVEN | Operation `succeeded`; `drained → retired`; both credentials (v1, v2) `retired` |
| Retired read-only | PROVEN | Zero inputs, zero selects, zero textareas, zero forms in detail; only `Hide details` and `Load more history` remain; row is not deleted |
| History + pagination | PROVEN | 10 entries then 17 after `Load more history`, oldest `runtime_node.created`, control disappears when the cursor is exhausted |
| Negative-role browser proof | NOT_APPLICABLE | No non-managing account has credentials available through normal local setup; automated coverage retained |

`DRAINING` UX was not observed as a resting state because the fixture carried
zero active bindings and the coordinator converged in ~2 s. That deterministic
behavior was recorded rather than slowed artificially. The intermediate
`draining` state is proven by the audit history, not inferred.

## Acceptance defect closure (natural login, 2026-08-08)

The three defects from the earlier proof were corrected in the existing
frontend state/view surfaces and re-proven through the canonical deployment.

**Family/adapter normalization — RESOLVED.** Adapter selection is now driven
by the backend runtime catalog. A family change preserves a still-valid
adapter, selects the sole valid adapter when there is one, and otherwise clears
the invalid value so the operator must choose from valid catalog options. The
browser proof changed Asterisk to deterministic simulator and automatically
selected `simulator-deterministic`; no stale `asterisk-ari` value was submitted.

**Credential rotation — RESOLVED.** The UI now sends the existing credential's
canonical `credential_type` together with the replacement secret. The natural
UI flow produced active version 2 and retired version 1, with no 422 response.
The secret remains write-only and was absent from the DOM and API metadata.

**Evidence refresh — RESOLVED.** Explicit Runtime Nodes Refresh now forces
detail/evidence reloads for expanded nodes, lifecycle mutations refresh the
affected detail, and closing/reopening a detail forces a current evidence
request. After the simulator reached canonical `ready`, the row and expanded
evidence agreed without a full browser reload; reopening did not resurrect the
stale cached evidence.

Automated frontend tests, `make test`, `make check`, typecheck, lint, build,
and `git diff --check` passed. The changed application was deployed through
`make k8s-image-build`, `make k8s-image-push`, `make k8s-apply`, and
`make k8s-restart-proof` against `utcp-local`.

## Historical defects from the first proof

The following records preserve the original run's observations at the time
they were made. Their current status is **RESOLVED** as recorded above; the
old implementation descriptions are retained as historical evidence, not as
the current contract.

**PRODUCT_DEFECT-A — runtime family change does not reset the adapter key.**
Pre-existing at `943c965`; not an RNM-5 regression. `runtimeNodeForm` in
`apps/web/src/state/appState.ts` hardcodes `adapterKey: 'asterisk-ari'` and
nothing resets it when the family changes, so the adapter `<select>` binds to a
value absent from its options (`value: ""`) and creation fails with
`Invalid runtime family or adapter key.` Recoverable: the operator can pick the
adapter explicitly. Smallest fix: reset `adapterKey` to the first adapter of the
newly selected family when `runtimeFamily` changes.

**PRODUCT_DEFECT-B — UI credential rotation is non-functional.**
Pre-existing at `943c965`; not an RNM-5 regression, but it falsifies the
"write-only credential ... rotate" claim for the browser surface.
`rotateRuntimeCredential` posts only the secret; the backend
`credentialRules()` requires `credential_type`, so every UI rotation returns
`422`. Proven by contrast: the same authenticated endpoint returned `200` and
produced version 2 when `credential_type` was included. Smallest fix: send the
credential's `credential_type` (and `identifier`) with the rotation payload.

**PRODUCT_DEFECT-C — runtime evidence is never refreshed within a session.**
RNM-5 scope. `loadRuntimeNodeDetails` returns early when the cached detail state
is `success` unless `force` is set; `toggleNodeDetails` never forces, and the
list `Refresh` does not clear `runtimeEvidence`. The operator therefore sees a
fresh list badge (`observed ready`) beside stale evidence
(`Observed state: unobserved`, `Last observation: Unavailable`) and a stale row
summary (`Last observed: Unavailable`) at the same time. Neither `Refresh` nor
collapse/re-expand heals it; only a full page reload does. Smallest fix: force
a detail/evidence reload for expanded nodes when the list is refreshed, or pass
`force` from `toggleNodeDetails`.

## Remaining live capability-producer gap

**Observed-capability population is not producible in this environment.** The
deterministic simulator emits eight event types
(`connection_opened`, `connection_closed`, `readiness_changed`,
`configuration_observed`, `conference_ready`, `conference_closed`,
`participant_joined`, `participant_left`) and never
`simulator.capabilities.observed`. `SimulatorEventNormalizer` consumes that
event type, but no adapter produces it, so RNM-4's projection has no producer
for a simulator fixture. The declared/observed/freshness presentation is proven;
`declared_not_observed`, `observed_not_declared`, and a populated `observed` set
are not. Not fabricated, and not written through any Admin surface.

## Local proof data

`RNM5 Browser Proof (edited)` remains in `Local Tenant` in terminal `retired`
state with full retained history. It is local proof data, was created and
retired entirely through the canonical Admin UI, and was not removed by any
unsupported means.

This is separate from the three repaired RNM-5 defects. No simulator capability
rows were fabricated or written through an Admin/API workaround. The UI's
`Not yet observed` / `unknown` presentation remains correct.

## Deferred boundary

RNM-5 is complete. RNM-6 owns the complete natural-login browser/live lifecycle
proof against a real runtime with actual workload, including drain with
non-zero remaining work. Before RNM-6, the observed-capability producer gap may
be handled as a bounded implementation if live capability evidence is required
by that proof.

RNP owns future managed runtime provisioning and any hosting/infrastructure
lifecycle; external runtime adoption remains supported as an advanced
compatibility path.

The historical simulator producer gap recorded above was subsequently closed
by the bounded simulator producer packet. That earlier `Not yet observed`
result remains an accurate RNM-5 record; current live producer evidence is
recorded in
[`rnm-4-observed-capability-projection.md`](rnm-4-observed-capability-projection.md).
