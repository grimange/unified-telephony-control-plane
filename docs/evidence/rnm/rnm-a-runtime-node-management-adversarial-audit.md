# RNM-A — RuntimeNode Management Adversarial Acceptance Audit

Verdict: **PASSED** after bounded fixes and a narrow live reproof of both
original attacks.

The history of this audit is deliberately preserved and must not be collapsed
into a first-pass success story:

```text
RNM-1..RNM-6 implementation and live proof
        ↓
RNM-A adversarial challenge (2026-08-08)
        ↓
2 HIGH_LIFECYCLE blockers proven live
        ↓
bounded implementation fixes
        ↓
live replay of the exact two attacks (2026-08-08, post-fix)
        ↓
both survive → RNM-A PASSED
```

The original findings below stand as recorded. Two reproducible authority
defects were proven live against the deployed canonical application. Both were
the same defect class the Stage 17 fix addressed — a retired-node guard present
on most writers and missing on a sibling — meaning that class had been fixed at
one call site rather than eliminated. The post-fix reproof is appended at the
end of this document.

Audit date: 2026-08-08. Branch `main`, HEAD `943c965`, working tree dirty with
the accumulated V0/RNM work (unmodified by this audit), `git diff --check`
clean. Environment `utcp-local` / `utcp-platform`, API image
`utcp-local-registry:5000/utcp/api:0.1.0-k1-dev`, Pod Ready, apiserver
endpoint-pin drift check passing. Natural login only; no injected session state.

RNM-1 through RNM-6 remain historically accurate as recorded. This audit does
not erase them; it challenges the RNM closure claim.

## Authority writer audit (Area A)

Static enumeration of every writer to the RuntimeNode tables:

| Table | Writers | Assessment |
| --- | --- | --- |
| `runtime_nodes` | `RuntimeRegistryService` (8), `ProjectionService` (1), `SimulatorProfileService` (1), `AsteriskAriProfileService` (1) | Canonical, but see BLOCKER-1 |
| `runtime_node_endpoints` | `RuntimeRegistryService` (3) | Canonical |
| `runtime_node_credentials` | `RuntimeRegistryService` (4) | Canonical |
| `runtime_node_capabilities` (declared) | `RuntimeRegistryService` (2) | Canonical; no observation writer |
| `runtime_node_drains` | `RuntimeNodeDrainRepository` (3), `RuntimeRegistryService` (1) | Canonical |
| `runtime_node_observed_capability_snapshots` / `_observed_capabilities` | `ProjectionService` (2 + 2) | Canonical; no Admin writer |

No Artisan command writes any RuntimeNode table — the only console writes are in
`identity:bootstrap-local` (users, tenants, memberships, role assignments). No
RuntimeNode HTTP route exists outside `routes/web.php`; `routes/channels.php`
contains only broadcast authorization. **No competing management authority was
found.**

The two profile services legitimately bump `configuration_version` inside their
own transactions as adapter-configuration authority. That is canonical service
internals, not a bypass — except that one of them does not honour terminality.

## BLOCKER-1 — Retired node accepts adapter-configuration mutation

```text
CLAIM:      RETIRED = zero configuration/lifecycle authority (RNM-1/RNM-3/RNM-6 Stage 17)
ATTACK:     PUT /api/v1/admin/runtime-nodes/{id}/adapter-configuration on a RETIRED simulator node
EXPECTED:   422 Retired runtime nodes are read-only historical records
ACTUAL:     200 — profile rewritten, node generation bumped, audit emitted
```

Reproduction, from a naturally authenticated session, against retired node
`e452304e-66ca-4438-b9bb-7c92f1f90838` (`RNM4 Capability Producer 20260808`,
`desired_state retired`, generation 9):

```text
PUT .../adapter-configuration
{"scenario_key":"terminal-failure","scenario_version":1,"seed":"rnma-adversarial","parameters":[]}
→ 200
```

Persisted effect:

| Field | Before | After |
| --- | --- | --- |
| desired_state | retired | retired |
| configuration_version | 9 | **10** |
| updated_at | 2026-08-08 07:45:01+00 | **2026-08-08 09:21:04+00** |
| simulator scenario_key | steady-ready | **terminal-failure** |
| simulator seed | local | **rnma-adversarial** |
| audit | — | **`[user] runtime_node.simulator_configuration_changed` @ 09:21:04** |

```text
AUTHORITY:  RuntimeRegistryService / adapter-configuration handler chain
ROOT CAUSE: SimulatorProfileService::put() calls simulatorNode() (which validates only
            tenant, family, and adapter) and then mutates runtime_nodes plus
            simulator_profiles. It contains zero occurrences of "retired" or
            "desired_state". Its sibling AsteriskAriProfileService::update()
            performs exactly this check and throws
            "Retired runtime nodes are read-only historical records."
AFFECTED:   apps/api/app/Simulator/SimulatorProfileService.php — put(), around the
            simulatorNode() lookup preceding the runtime_nodes update
IMPACT:     A terminal historical record retains configuration authority: its
            adapter behaviour contract and configuration generation can be
            rewritten indefinitely. It does NOT restore operational eligibility
            (verified below), so impact is bounded to record integrity and
            audit truthfulness.
FIX:        Add the retired guard in SimulatorProfileService::put() immediately after
            the locked simulatorNode() lookup, mirroring AsteriskAriProfileService,
            with a regression test asserting 422 and zero mutation.
SEVERITY:   HIGH_LIFECYCLE
```

Escalation was tested and did **not** occur: the mutated retired node remains
placement-ineligible (`422 Runtime node is not eligible for conference
execution.`) and has no `runtime_node` or `runtime_node_drain` reconciliation
target.

Exactly two `AdapterConfigurationHandler` implementations exist. One guards,
one does not — the gap is bounded and complete.

## BLOCKER-2 — `disabled → retired` strands an unrevocable active credential

```text
CLAIM:      Decommission retires every active UTCP-held runtime credential (RNM-3);
            RETIRED is terminal
ATTACK:     Create node → add credential → desired-state disabled → desired-state retired
EXPECTED:   No active UTCP-held credential remains on a terminal node, or the path is refused
ACTUAL:     Node reaches RETIRED with credential v1 still ACTIVE, and every
            remediation path is refused
```

Reproduction, node `a7769ec1-88e3-4950-b8da-6c203db1e3b5`
(`RNMA Adversarial Probe 20260808`, created through the Admin UI):

```text
POST .../credentials                      201  (v1 active)
POST .../desired-state {disabled}         200
POST .../desired-state {retired}          200  → desired_state = retired
credentials after                         v1 : ACTIVE
```

The stranded credential cannot be cleaned up by any canonical path:

```text
POST .../credentials              422 Retired runtime nodes are read-only historical records.
POST .../credentials/{id}/rotate  422 Retired runtime nodes are read-only historical records.
POST .../credentials/{id}/retire  422 Retired runtime nodes are read-only historical records.
POST .../decommission             422 Only a drained runtime node can be decommissioned.
```

```text
AUTHORITY:  RuntimeRegistryService::changeDesiredState()
ROOT CAUSE: assertDesiredTransition permits 'disabled' => [..., 'retired'], and
            changeDesiredState blocks only 'drained' → 'retired' ("must be
            decommissioned through the canonical decommission action"). The
            disabled → retired path performs the reconciliation/listener purge but
            never retires credentials, because credential retirement lives solely in
            RuntimeNodeDecommissionOperationHandler.
AFFECTED:   apps/api/app/RuntimeRegistry/RuntimeRegistryService.php — changeDesiredState()
            retirement branch; apps/api/app/RuntimeRegistry/RuntimeRegistryCatalog.php
            transition table
IMPACT:     Two reachable paths reach the same terminal state with materially
            different guarantees. An operator who disables then retires a node
            reasonably believes its credentials were dealt with; they were not, and
            the product offers no way to revoke them. No secret is exposed and the
            node holds no operational authority, so this is integrity and
            revocability, not disclosure.
FIX (bounded, one of):
            (a) retire active credentials inside the disabled → retired branch and
                audit it as system-performed; or
            (b) refuse disabled → retired through the generic endpoint and require the
                canonical decommission path.
            Either way, add coverage asserting no RETIRED node retains an active
            credential. Note the existing test
            "disabled runtime node can be soft retired and retirement is terminal"
            uses a node with no credential, which is why this went unnoticed.
SEVERITY:   HIGH_LIFECYCLE
```

RNM-3's written claim is scoped to the decommission operation and is therefore
not itself false. The defect is the unguarded alternate route to the same
terminal state.

## Adversarial attack matrix

| Attack | Result | Evidence | Classification |
| --- | --- | --- | --- |
| A — direct/bypass writers to RuntimeNode tables | SURVIVED | Only canonical services; no Artisan or alternate-route writer | — |
| B — retired metadata PATCH | SURVIVED | 422, zero mutation (Stage 17 fix holds) | — |
| B — retired endpoint add/edit/remove | SURVIVED | 422 read-only | — |
| B — retired declared-capability update | SURVIVED | 422 read-only | — |
| B — retired credential create/rotate/retire | SURVIVED | 422 read-only ×3 | — |
| B — retired decommission | SURVIVED | 422 only-drained | — |
| **B — retired adapter-configuration update** | **FAILED** | 200, generation 9→10, profile rewritten | **BLOCKER-1** |
| C — operator asserts DRAINED directly | SURVIVED | 422 "only after the drain coordinator proves zero remaining work" | — |
| C — sibling writers of `desired_state='drained'` | SURVIVED | Only `RuntimeRegistryService` lines 221/296, both coordinator-driven | — |
| C — `drained → retired` via generic endpoint | SURVIVED | 422 "must be decommissioned through the canonical decommission action" | — |
| D — new placement onto DRAINING | SURVIVED | Default `['active']`; RNM-6 proved `pending_no_capacity` live | — |
| D — failover/replacement widening | SURVIVED | Defaults `['active']`, explicitly subtracts `draining`/`retired` | — |
| E — drain cancellation race | SURVIVED (seam) | `RuntimeNodeDrainCoordinatorTest` passes; live race not manufactured | — |
| F — decommission/reactivation race | SURVIVED (seam) | "decommission request is idempotent and reactivation invalidates stale authority" passes | — |
| G — partial decommission failure | SURVIVED (seam) | "decommission rechecks active bindings before retirement" passes; no deterministic partial-strip seam exists | — |
| H — observed writer touching declared caps | SURVIVED | Declared written only by `RuntimeRegistryService` | — |
| H — Admin writer touching observed caps | SURVIVED | Observed written only by `ProjectionService` | — |
| I — older observation overwriting newer | SURVIVED | `observationIsOlder()` under `lockForUpdate()`; replay/older tests pass | — |
| J — placement from draft/draining/drained/disabled/retired | SURVIVED | Canonical selector `['active']`; retired bind rejected live | — |
| K — new-work command to ineligible states | SURVIVED | `CommandWorker` desired-state + capability gate | — |
| L — retired reconciliation/listener re-enrollment | SURVIVED | Zero `runtime_node`/`runtime_node_drain` targets; retirement purges states and releases leases | — |
| **M — retired retains active credential** | **FAILED** | v1 ACTIVE on terminal node, unrevocable | **BLOCKER-2** |
| M — secret exposure in API/UI | SURVIVED | No probe secret present in any serialization | — |
| N — cross-tenant access | UNTESTABLE LIVE | Only one tenant exists; automated coverage present (see below) | — |
| O — UI lying about server state | SURVIVED | Retired detail renders 0 inputs/0 forms; the API is the hole, not the UI | — |
| P — legacy/alternate management authority | SURVIVED | No Artisan/legacy/dev route mutates RuntimeNode state | — |
| Q — duplicate/replayed decommission | SURVIVED (seam) | Repeated requests reuse the pending operation; idempotency test passes | — |
| R — rejected mutation emitting false audit | SURVIVED | Stage 17: 422 produced no `runtime_node.updated`; audit count unchanged | — |
| S — retired historical evidence restoring authority | SURVIVED | Historical `observed_state`/capabilities retained, yet placement-ineligible with no targets | — |
| T — disabled/drained/retired collapsed | SURVIVED | Distinct transition sets and distinct handling; no harmful interchangeable path found | — |

## Untestable variants

- **Area N live cross-tenant probe.** The deployed environment contains exactly
  one tenant (`Local Tenant`); the session reports a single membership. Creating
  a second tenant and membership purely to run the probe would fabricate state
  the audit brief forbids. Automated coverage exists and passes:
  `RuntimeRegistryTest` exercises cross-tenant `assertNotFound()` for node
  detail, adapter configuration, and runtime evidence using separate tenant
  admins. Reported as a live limitation, not a defect.
- **Areas E/F/G live races.** Producing a genuine coordinator/worker interleave
  live would require artificial sleeps or fault switches, which the brief
  forbids. The deterministic seams were executed instead and pass. No claim of
  live race proof is made.
- **Partial decommission failure (G).** No deterministic failure seam exists to
  force credential retirement to commit while retirement fails; the handler's
  ordering was reviewed and revalidates node, ownership, desired state, and
  binding count under lock. Not asserted either way beyond the passing tests.

## Local proof artifacts

Both blocker artifacts are retained deliberately as evidence and were not
cleaned up:

- `e452304e-66ca-4438-b9bb-7c92f1f90838` — retired, now carrying scenario
  `terminal-failure` / seed `rnma-adversarial` at generation 10 (BLOCKER-1).
- `a7769ec1-88e3-4950-b8da-6c203db1e3b5` — `RNMA Adversarial Probe 20260808`,
  retired via `disabled → retired`, holding an active unrevocable credential
  (BLOCKER-2). Created through the Admin UI; no external system targeted.

No SQL, Redis, Kubernetes, or seeder manipulation was used to create or mutate
either artifact.

## Conclusion

The RNM lifecycle survived the substantial majority of the attack surface —
placement cordon, DRAINED system authority, capability separation, observation
ordering, listener/reconciliation exclusion, idempotency, audit integrity, and
the absence of any competing management authority all held. The failures are
narrow and share one root pattern: **terminality is enforced per-writer rather
than centrally**, so each new writer must remember the guard, and two did not.

Both fixes are small and local. Neither requires architectural change.

## Bounded implementation follow-up

The two proven blockers have been fixed in the canonical writers while the
original adversarial failures and local artifacts above remain preserved.

- **BLOCKER-1:** `SimulatorProfileService::put()` now rejects a locked
  `RETIRED` simulator node before changing `runtime_nodes` or
  `simulator_profiles`, using the established Asterisk profile error semantics.
  The Admin/API regression proves `422`, unchanged scenario/profile, unchanged
  generation and timestamp, and no simulator-configuration audit or outbox
  mutation. Non-retired simulator configuration remains covered and passing.
- **BLOCKER-2:** `RuntimeRegistryService::changeDesiredState()` preserves the
  supported `DISABLED → RETIRED` corridor and retires every active UTCP-held
  credential inside the same locked transaction before the terminal node update.
  The shared internal retirement helper is also used by decommission, so both
  terminal routes preserve credential rows/history and emit truthful retirement
  evidence with zero active credentials remaining.
- Automated focused and full repository verification passed. Normal final
  active-credential safeguards remain covered by the existing RuntimeRegistry
  tests.

## Post-fix adversarial live reproof (natural login, 2026-08-08)

Narrow reproof of the two repaired attacks only. No part of the original
adversarial matrix was replayed; every other area survived the first run.

Deployment verified before probing: context `k3d-utcp-local`, namespace
`utcp-platform`, API image `utcp-local-registry:5000/utcp/api:0.1.0-k1-dev`,
API Pod Ready and started `2026-08-08T09:41:10Z` — after the fix sources were
written at `09:36:35Z`. Edge healthy (`/login` 200) and the apiserver
endpoint-pin drift check passed, so no environment repair and no redeploy were
required. `simulator-event-source` and `telephony-command-worker` predate the
fix but sit on neither attack path: both attacks are synchronous API-authority
probes served by the `api` workload.

Natural login from `https://app.utcp.local.test/login`, forced password change
completed, `Local Tenant` selected in the real selector, capabilities
`runtime.nodes.view`, `runtime.nodes.manage`, `runtime.credentials.rotate`.
`localStorage` held only `utcp.appearance` and `pusherTransportTLS`. No injected
cookies, preset storage state, or fabricated session.

### REPROOF A — retired simulator adapter configuration — SURVIVED

The original mutated fixture was reused, exactly as retained:
`e452304e-66ca-4438-b9bb-7c92f1f90838` (`RNM4 Capability Producer 20260808`),
`retired`, simulator / `simulator-deterministic`, generation 10, scenario
`terminal-failure`, seed `rnma-adversarial`, `updated_at 2026-08-08 09:21:04+00`,
3 prior `runtime_node.simulator_configuration_changed` audits (latest 09:21:04).

UI supporting evidence: the retired node's detail renders 0 inputs, 0 selects,
0 textareas, 0 forms, no adapter-configuration fields, and only `Hide details`.

```text
PUT /api/v1/admin/runtime-nodes/e452304e…/adapter-configuration
{"scenario_key":"steady-ready","scenario_version":1,
 "seed":"rnma-reproof-must-be-rejected","parameters":[]}

→ HTTP 422
→ {"message":"Retired runtime nodes are read-only historical records."}
```

Non-mutation proof after the rejected request:

| Field | Before | After |
| --- | --- | --- |
| desired_state | retired | retired |
| configuration_version | 10 | 10 |
| updated_at | 2026-08-08 09:21:04+00 | 2026-08-08 09:21:04+00 |
| scenario_key | terminal-failure | terminal-failure |
| seed | rnma-adversarial | rnma-adversarial |
| total history records | 10 | 10 |
| `simulator_configuration_changed` audits | 3 (latest 09:21:04) | 3 (latest 09:21:04) |

No new audit, and zero runtime operations created in the probe window.
Historical configuration remains fully readable.

### REPROOF B — `DISABLED → RETIRED` terminal credential invariant — SURVIVED

Fixture `721c8f1d-3fea-4b0b-be97-5bcacd2be383`
(`RNMA Terminal Credential Reproof 20260808`), simulator /
`simulator-deterministic`, created through the Admin UI create form; credential
created through the Admin UI credential form.

```text
09:53:37  node created                      draft
09:53:55  credential rnma-reproof-control   v1 ACTIVE  fingerprint ca878bb51b25
09:54:11  Disable (Admin UI action)         disabled, credential still ACTIVE
09:54:38  desired-state → retired           retired
```

The intermediate `disabled` state correctly left the credential active — the
credential is not prematurely retired merely because the node is disabled. Note
that the Admin UI offers only `Activate` for a disabled node, so
`disabled → retired` is reachable through the canonical Admin API rather than a
UI control.

Committed terminal result:

```text
desired_state            retired
credential v1            RETIRED   (fingerprint ca878bb51b25 retained)
ACTIVE credential count  0
secret present anywhere  no
```

Audit/history is truthful and ordered:

```text
09:53:37 [user] runtime_node.created                Node created for simulator-deterministic.
09:53:55 [user] runtime_node.credential_rotated     Credential created.
09:54:11 [user] runtime_node.desired_state_changed  draft → disabled
09:54:38 [user] runtime_node.credential_retired     Credential retired.
09:54:38 [user] runtime_node.desired_state_changed  disabled → retired
```

Terminal read-only re-verified on the same node — all refused:

```text
POST .../credentials                 422 Retired runtime nodes are read-only historical records.
POST .../credentials/{id}/rotate     422 Retired runtime nodes are read-only historical records.
POST .../credentials/{id}/retire     422 Retired runtime nodes are read-only historical records.
PUT  .../adapter-configuration       422 Retired runtime nodes are read-only historical records.
```

### Decommission regression sanity

Read-only inspection of the retained RNM-6 decommission fixture
`6700025f-61b5-452a-b845-2c70afbe757e`: both credentials remain `retired`, zero
active. The shared terminal-retirement helper did not disturb the already-proven
decommission credential path. No new drain/decommission lifecycle was run; the
automated decommission regressions remain authoritative.

### Residual data from the original defect

A tenant-wide sweep found exactly one retired node still holding an active
credential: `a7769ec1-88e3-4950-b8da-6c203db1e3b5`
(`RNMA Adversarial Probe 20260808`), the original BLOCKER-2 artifact. This is
pre-fix data, not a live defect — the correction is forward-only and cannot
retroactively repair rows created before it, and the node is correctly read-only
so the product offers no way to alter it. It is retained deliberately as
evidence. No other retired node in the tenant holds an active credential.

Both original attacks therefore fail against the fixed deployment, and RNM-A
passes.
