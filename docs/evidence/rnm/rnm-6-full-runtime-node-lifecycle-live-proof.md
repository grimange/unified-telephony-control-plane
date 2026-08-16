# RNM-6 — Full RuntimeNode Lifecycle Browser / Live Proof

Status: **PASSED** after a bounded fix and a narrow live reproof.

The history of this proof is deliberately preserved and must not be collapsed
into a single-pass narrative:

```text
Original RNM-6 (2026-08-08, first run):
FAILED Stage 17 because retired metadata PATCH remained writable.

Bounded implementation:
RuntimeRegistryService::updateNode() received the existing retired-node guard.

Post-fix live reproof (2026-08-08, Stage 17 only):
PATCH now rejected with 422 and produced zero mutation.

Final RNM-6:
PASSED.
```

The first run proved the complete planned lifecycle live through the canonical
Admin UI, including the strongest available drain evidence (`remaining_work > 0`
held open by a real runtime binding, plus a live cordon proof), and failed only
the Stage 17 retired-authority criterion. The Stage 17 reproof below closes that
gap.

Proof date: 2026-08-08. Environment: `utcp-local` (k3d), namespace
`utcp-platform`. No cluster, registry, host port, node topology, or persistent
volume was changed.

## Preflight

Branch `main`, HEAD `943c965`, working tree dirty with the accumulated V0/RNM
work, `git diff --check` clean. Live evidence showed the deployment was stale
for this corridor: `simulator-event-source` was 5d7h old and
`telephony-event-normalizer` / `telephony-reconciler` predated the RNM-4
producer changes. Corrected through the canonical lifecycle only
(`make k8s-image-build`, `make k8s-image-push`, then pod replacement per
Deployment); all eight relevant workloads then ran the current image.

## Natural login

Started at `https://app.utcp.local.test/login` with a bounded break-glass
credential, completed the forced password change, selected `Local Tenant` in the
real selector. Session returned `runtime.nodes.view`, `runtime.nodes.manage`,
`runtime.credentials.rotate`. `localStorage` contained only `utcp.appearance`
and `pusherTransportTLS`. No injected cookies, preset storage state, or
database/Redis session.

## Fixture

`RNM6 Full Lifecycle Proof 20260808` / `rnm6-full-lifecycle-proof-20260808`,
runtime family `simulator`, adapter `simulator-deterministic` taken from the
live catalog (`GET /api/v1/admin/runtime-node-catalog`), created through the
Admin UI create form. Changing the family auto-selected the sole valid adapter,
confirming the RNM-5 family/adapter fix live.

## Stage results

| Stage | Result | Evidence |
| --- | --- | --- |
| 1 Create + configure | PROVEN | `draft`/`unobserved`; region `rnm6-local`, zone `zone-proof`, priority 40, capacity 80 persisted; generation 1→2; `runtime_node.created` + `runtime_node.updated` |
| 2 Endpoints | PROVEN | `rnm6-sim-proof.local.test:19089` added → edited to `:19090` priority 30 → removed behind a confirm dialog |
| 3 Credentials | PROVEN | v1 created, rotated through the UI to v2 active with v1 retired, distinct fingerprints, no `422`; secret absent from DOM and API JSON. Confirms the RNM-5 rotation fix live |
| 4 Declared capabilities | PROVEN | Declared 3 of the adapter's 5 supported capabilities to force drift |
| 5 Activate | PROVEN | `draft → active` by user action; no manual reconciler invoked |
| 6 Readiness | PROVEN | `observed ready`, desired gen 10 = observed gen 10, connection `open`, reconciliation `converged`, via automatic scheduling |
| 6 Evidence coherence | PROVEN | After the in-page Refresh only, badge, row summary, and expanded evidence all agreed; no full browser reload required |
| 7 Observed capabilities | PROVEN | Snapshot produced automatically by the simulator through the canonical path; drift visible |
| 8 Eligibility | PROVEN | Only READY/active node in the tenant; the four earlier proof nodes were `retired` and excluded from placement |
| 9 Active work | PROVEN | Real conference bound to the node through the authorized Admin conference API |
| 10 First drain | PROVEN | Held open by real work, then system-completed after release |
| 10 Cordon | PROVEN | New conference opened while draining received no node, no binding, `failover_state: pending_no_capacity` |
| 11 Drained state | PROVEN | `Reactivate` + `Decommission` offered; new-work actions absent |
| 12 Reactivate | PROVEN | `drained → active → ready`, gen 14/14 `converged`, capability evidence fresh |
| 13 Failure/recovery | PARTIAL | Canonical scenario contract exercised; fencing corridor not applicable to a simulator node — see below |
| 14 Final drain | PROVEN | Second `active → draining → drained`, again system-produced |
| 15 Decommission | PROVEN | Confirmation covered all four disclosures; operation `succeeded`; `drained → retired` |
| 16 Credential retirement | PROVEN | Both versions `retired` by `[system]`; metadata retained; no secrets displayed |
| 17 Retired authority cutoff | **FAILED in the first run; PROVEN in the post-fix reproof** | First run: 7 of 8 canonical mutations refused, `PATCH /runtime-nodes/{id}` succeeded — see defect. Post-fix: all 8 refused, `PATCH` returns 422 with zero mutation — see Stage 17 live reproof |
| 18 Historical evidence | PROVEN | Readiness, capability, drain, decommission, credential metadata, and lifecycle history all retained and queryable |
| 19 History pagination | PROVEN | 10 entries → 20 after `Load more history`, oldest `runtime_node.created`, control disappears when the cursor is exhausted |

## Capability proof (Stage 7)

Captured at `2026-08-08 07:58:26+00`, produced automatically with no Admin write:

```text
DECLARED:              event.stream, runtime.configuration, runtime.observation
OBSERVED:              conference.lifecycle, conference.participation, event.stream,
                       runtime.configuration, runtime.observation
DECLARED_NOT_OBSERVED: (none)
OBSERVED_NOT_DECLARED: conference.lifecycle, conference.participation
FRESHNESS:             fresh
OBSERVED_AT:           2026-08-08 07:58:26+00
SOURCE:                simulator-deterministic
SOURCE_OBSERVATION_ID: 84c8e672b703e5e887341845a652e86a
CONFIGURATION_GEN:     10
```

Drift was later resolved in the same session by declaring the two conference
capabilities through the UI; at `2026-08-08 08:03:52+00` declared equalled
observed and `OBSERVED_NOT_DECLARED` was empty. Drift tracking is therefore
proven live in both directions.

`DECLARED_NOT_OBSERVED` cannot be produced with this fixture: declared
capabilities are validated against the adapter's supported set, and the
simulator observes exactly that full set, so declared is always a subset of
observed.

## Active-work drain (Stages 9–10)

The Conference operations view is read-only, so no Admin **UI** form creates a
conference. The authorized Admin conference API was used instead — a canonical,
capability-gated application surface, not SQL, not a seeder, not a hidden test
route. `conference_runtime_bindings` was never written directly.

```text
08:00:40  [user]   active → draining
          drain_state running, initial_work 1, remaining_work 1,
          deadline_at 09:00:40, UI: "Draining — no new work will be assigned;
          existing work continues."   Actions: Cancel drain, Disable
          cordon probe: new conference → runtime_node null, binding null,
                        failover_state pending_no_capacity
08:01:4x  conference closed → binding retired by the canonical sweep
08:02:12  [system] draining → drained
          drain_state completed, remaining_work 0, timed_out false
```

`DRAINED` was never operator-asserted: the audit actor for both
`draining → drained` transitions is `system`, while `active → draining` is
`user`.

## Failure / recovery (Stage 13)

A canonical safe deterministic failure mechanism does exist and requires no new
code or hidden switch: the simulator scenario catalog is selectable through the
Admin UI adapter-configuration form. Selecting `disconnect-reconnect` produced a
real observed connection lifecycle — epoch opened `08:05:01`, closed `08:05:05`,
next event `08:05:09` — after which the node automatically reconverged to
`ready` with observed generation 15 = desired 15.

The full corridor described for this stage (observed failure → fencing/disable →
canonical recovery) is **not applicable** to a simulator node: fencing requires
the `kubernetes_workload` identity that a simulator node deliberately lacks.
No fault injection was invented and no Pod was killed. The T5 evidence
(`docs/evidence/t5/t5-phase-closure.md`, rows 3, 6, 10, 13) remains authoritative
for the fence/disable/restore corridor.

## Defect — retired metadata mutation is not blocked

**Classification:** IMPLEMENTATION. **Severity:** blocks the RNM-6 success
criterion "RETIRED is read-only".

**Expected.** A `retired` RuntimeNode is a terminal, read-only historical
record; every mutation is refused at the canonical API authority.

**Actual.** Seven of eight probes against the retired node were correctly
refused:

```text
POST .../desired-state {active}     422 Invalid desired-state transition.
POST .../desired-state {draining}   422 Invalid desired-state transition.
POST .../desired-state {disabled}   422 Invalid desired-state transition.
POST .../decommission               422 Only a drained runtime node can be decommissioned.
POST .../endpoints                  422 Retired runtime nodes are read-only historical records.
PUT  .../capabilities               422 Retired runtime nodes are read-only historical records.
POST .../credentials                422 Retired runtime nodes are read-only historical records.
PATCH /api/v1/admin/runtime-nodes/{id}   200   ← accepted
```

The `PATCH` renamed the retired node, bumped `configuration_version` 18 → 19,
and appended a `runtime_node.updated` audit record attributed to `[user]`.

**Authority involved.** `RuntimeRegistryService` — the canonical desired/config
write authority. The Admin UI does not render the edit form for retired nodes,
so this is reachable only through the API; UI hiding is not authorization.

**Root cause.** `assertNodeNotRetired()` exists in
`apps/api/app/RuntimeRegistry/RuntimeRegistryService.php:689` and is called from
six sites — `addEndpoint` (456), `updateEndpoint` (489), `removeEndpoint` (520),
`setCapabilities` (536), `retireCredential` (582), and the credential write path
(621) — but **not** from `updateNode()`. `changeDesiredState` is protected
separately by the transition table, which is why the desired-state probes fail
correctly.

**Smallest bounded fix.** Add `$this->assertNodeNotRetired($node);` immediately
after `$node = $this->nodeForUpdate($nodeId, $tenantId);` in `updateNode()`, with
a focused regression test asserting `PATCH` on a retired node returns 422 and
mutates nothing.

Because of this defect the proof fixture is currently persisted under the name
`should not apply` at generation 19. That name is retained deliberately as
evidence; it was not corrected, because correcting it would require exercising
the same defect a second time.

## Follow-up implementation status

The bounded implementation fix adds the existing `assertNodeNotRetired()` guard
to `RuntimeRegistryService::updateNode()` immediately after its authoritative
locked-node lookup. A focused Admin API regression test now proves that a
retired metadata PATCH returns `422` without changing the node name,
configuration generation, or timestamp and without emitting a
`runtime_node.updated` audit or outbox mutation. Repository tests passed for
the fix.

The guard is now called from seven sites (`updateNode` at line 118 plus the
original six). The original failure artifact — the generation-19 node still
named `should not apply` — is preserved deliberately and was not renamed.

## Stage 17 live reproof (natural login, 2026-08-08, post-fix)

Narrow reproof only; the rest of the RNM-6 lifecycle was not replayed.

**Environment blocker found and corrected first.** The natural browser login was
initially impossible: the edge served `CN=TRAEFIK DEFAULT CERT` and returned
`404` for every route. Root cause was the repository's known apiserver
endpoint-pin drift after a node-IP shuffle — `allow-traefik-kubernetes-api`,
`allow-runtime-fencer-kubernetes-api`, and the observability policies pinned
`172.21.0.2/32` while the live apiserver endpoint was `172.21.0.5`. The
repository's own detector confirmed it
(`scripts/security/check-apiserver-policy-drift`: "stale endpoint destination,
expected 172.21.0.5/32, found 172.21.0.2/32"), so Traefik's Gateway provider was
denied API access and served no configuration. Corrected canonically with
`make security-apply` (which reconfigured exactly the two stale policies) plus a
single Traefik Pod restart so the provider re-read the API. The drift check then
passed, the mkcert certificate covering `app.utcp.local.test` was served again,
and `/login` returned `200`. Classification: **ENVIRONMENT/DEPLOYMENT**,
unrelated to the Stage 17 fix. No topology, cluster, registry, or host port was
changed.

Deployment verified before probing: context `k3d-utcp-local`, namespace
`utcp-platform`, API image `utcp-local-registry:5000/utcp/api:0.1.0-k1-dev`,
Pod Ready and started `2026-08-08T08:33:34Z` — after the `08:29:31Z` guard
change.

Natural login from `https://app.utcp.local.test/login` with a bounded
break-glass credential, forced password change completed, `Local Tenant`
selected in the real selector. Capabilities `runtime.nodes.view`,
`runtime.nodes.manage`, `runtime.credentials.rotate`. `localStorage` held only
`utcp.appearance` and `pusherTransportTLS`. No injected cookies, preset storage
state, copied headers, or fabricated session.

Fixture: the preserved defect artifact
`6700025f-61b5-452a-b845-2c70afbe757e`, name `should not apply`,
`desired_state retired`, generation 19, `updated_at 2026-08-08 08:15:01+00`,
latest history `2026-08-08 08:06:47+00 [user] runtime_node.updated`.

UI supporting evidence: the retired node renders zero inputs, zero selects,
zero textareas, and zero forms; only `Hide details` and `Load more history`
remain.

Decisive probe from the authenticated session:

```text
PATCH /api/v1/admin/runtime-nodes/6700025f-61b5-452a-b845-2c70afbe757e
{"name":"RNM6 retired mutation must be rejected"}

→ HTTP 422
→ {"message":"Retired runtime nodes are read-only historical records."}
```

Non-mutation proof after the rejected request:

| Field | Before | After |
| --- | --- | --- |
| name | `should not apply` | `should not apply` |
| configuration generation | 19 | 19 |
| desired_state | retired | retired |
| updated_at | 2026-08-08 08:15:01+00 | 2026-08-08 08:15:01+00 |
| placement | region `rnm6-local`, zone `zone-proof`, prio 40, cap 80 | unchanged |
| total history records | 20 | 20 |
| `runtime_node.updated` audits | 2 (latest 08:06:47) | 2 (latest 08:06:47) |

No new `runtime_node.updated` audit was emitted. Runtime operations for the node
remained at 15 with **zero** created during the probe window, so no false
decommission or side-effect operation appeared.

Full terminal mutation matrix re-checked against the same retired node — all
eight rejected:

```text
desired-state → active      422 Invalid desired-state transition.
desired-state → draining    422 Invalid desired-state transition.
desired-state → disabled    422 Invalid desired-state transition.
decommission                422 Only a drained runtime node can be decommissioned.
POST endpoints              422 Retired runtime nodes are read-only historical records.
PUT capabilities            422 Retired runtime nodes are read-only historical records.
POST credentials            422 Retired runtime nodes are read-only historical records.
PATCH metadata              422 Retired runtime nodes are read-only historical records.
```

Side-effect confirmation: endpoints still 0, credentials still exactly
`v2 retired` and `v1 retired`, declared capabilities still 5 — none of the
rejected probes partially applied.

Historical read-only evidence remained fully accessible: node detail, runtime
evidence (`observed_state`, `observed_at 08:05:09`), observed capability set of
5 with freshness now correctly `stale`, drain evidence `completed`, decommission
operation `succeeded`, credential metadata, and all 20 lifecycle history
records. The fix did not make historical records inaccessible.

Stage 17 therefore **PASSES**, and RNM-6 as a whole passes.

## Local proof data

The fixture remains in `Local Tenant` in terminal `retired` state with full
retained history. Two proof conferences (`rnm6-drain-work-proof`,
`rnm6-cordon-probe`) remain `closed` with retired bindings. All were created and
retired through canonical application surfaces and were not removed by any
unsupported means.

## Authority boundary

No alternate management authority was used at any point.

```text
Desired/config authority      RuntimeRegistryService
Observed runtime authority    ProjectionService
Declared capability authority RuntimeRegistryService
Observed capability authority runtime observation → ProjectionService
Drain completion              RuntimeNodeDrainCoordinator (actor: system)
Decommission completion       runtime operation kernel + RuntimeRegistryService
Runtime execution             generic adapter / runtime-operation boundary
Admin authority               authenticated canonical web UI and Admin API
Audit                         C0 audit + outbox
```

No SQL lifecycle manipulation, no manual reconciliation, no injected session,
no Kubernetes or Docker CLI in the operator workflow.
