# C6E — Asterisk Natural Frontend Live Proof (second attempt)

Date: 2026-08-21

## Verdict

    C6E_ASTERISK_NATURAL_FRONTEND_LIVE_PROOF_FOUND_BLOCKER

Deployment succeeded this time. The previous PostgreSQL migration blocker is
**closed and live-confirmed**: `2026_08_16_100000_create_c6_call_tables` applied
to the canonical `utcp-local` PostgreSQL in 64.54 ms and both C6 tables exist.

The proof then stopped at the **first UI step**. The six `telephony.calls.*`
capabilities still do not exist in the canonical database, so a natural
tenant-admin session carries none of them, the `Calls` navigation entry is not
rendered, and `/calls` is refused by the route guard. Neither the outbound nor
the inbound corridor could be attempted.

The previous packet classified the missing capabilities as *"a downstream
consequence of the migration defect, not an independent one."* That
classification was **wrong**. It is an independent defect, and it is now
isolated exactly.

Two further blocking conditions were identified beyond it, so that the next
attempt is not blocked a third time.

No production source was modified. No source is patched here — the corrections
belong to Codex.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  C6A-C6E implementation present and uncommitted (37 paths,
                   +1 = this evidence file)
    commit/push:   none created, not pushed

## Deployment

    API:          utcp/api  @sha256:97f868c0af16fccb47a956d8a130ca445f02cac902cf7a5c192fb78c629df8c4
    WORKER:       utcp/api  @sha256:97f868c0af16fccb…  (worker, scheduler, reverb,
                  telephony-command-worker, telephony-event-normalizer,
                  telephony-reconciler, simulator-event-source,
                  asterisk-ari-events, control-plane-outbox-dispatcher,
                  utcp-runtime-fence-worker, kamailio-registration-observer)
    WEB:          utcp/web  @sha256:f7a11e101736f33f55be2ce34f3f0adb3b38207c8493907b2473be18d55e3afe
    GATEWAY:      utcp/gateway @sha256:16b1b049e15b3b486162d3999cb32e524a53ac0473e4878d4cadf717c83bcdb9
    ASTERISK:     utcp/asterisk-ari:0.1.0-k1-dev
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2  active / ready
                  c7e6f4ba-b925-462f-aff4-71c9fa9a4157  active / ready
    POSTGRESQL MIGRATION: **PASSED**
    CAPABILITY SYNC:      **FAILED — no sync path exists**
    DEPLOYMENT FRESH:     YES

Lifecycle used: `make k8s-image-build` → `make k8s-image-push` → `make k8s-apply`
→ `kubectl rollout restart` of the fourteen `utcp-platform` Deployments →
`make media-edge-apply`.

The rollout restart is required because every Deployment pins the mutable tag
`0.1.0-k1-dev` with `imagePullPolicy: Always`; `scripts/kubernetes/apply`
contains no rollout step, so a rebuilt same-tag image is not picked up by
`k8s-apply` alone. Before the restart the Deployments were still serving the
previous digest `sha256:153636632ec9…`. This matches the practice recorded in
the previous C6E attempt.

### Migration result — the previous blocker is closed

`utcp-migrate` Job: `Complete 1/1`, duration 8 s.

    INFO  Running migrations.
    2026_08_16_100000_create_c6_call_tables ....................... 64.54ms DONE

    select to_regclass('public.calls'), to_regclass('public.call_legs');
    -> calls | call_legs

Recorded in `migrations` as batch 6.

## Natural Login

    LOGIN PAGE:  https://app.utcp.local.test/login  (real page, ordinary form)
    USER:        admin@utcp.local.test  (UTCP Local Administrator)
    TENANT:      Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
    ROLES:       platform-admin (platform) + tenant-admin (tenant `local`)
    PERMISSIONS: 19 capabilities returned by GET /api/v1/auth/session
    SESSION BYPASS: NO

The stored `.runtime/identity/bootstrap.json` password was stale and produced
the ordinary `Invalid credentials.` form error. Recovery used the sanctioned
break-glass Make target `user-access-reset-password`, followed by the normal
forced password-change screen, after which the account password was restored to
the value recorded in `bootstrap.json`. Login, tenant selection, forced password
change and logout were all performed through the real UI. No cookie, session,
storage-state or database session was injected.

Capabilities returned for this session:

    platform.tenants.manage        telephony.conferences.join
    platform.tenants.view          telephony.conferences.manage
    platform.users.manage          telephony.conferences.participants.manage
    platform.users.view            telephony.conferences.view
    runtime.credentials.rotate     telephony.sessions.manage
    runtime.nodes.manage           telephony.signaling.issue_own
    runtime.nodes.view             telephony.signaling.manage
    tenant.memberships.manage      telephony.signaling.view_own
    tenant.memberships.view        tenant.roles.assign
    tenant.roles.view

    telephony.calls.* : 0 of 6

## Calls UI

    ROUTE:                  /calls
    NAVIGATION:             **the `Calls` entry is not rendered**
    LOADED:                 NO — /calls redirected to /forbidden
    RAW API SUBSTITUTE USED: NO

The rendered `Runtime control` navigation group contains exactly `Runtime
nodes`, `Runtime operations`, `Runtime reconciliations`, `Conference
operations`. `navigation.ts` gates the `Calls` entry on
`telephony.calls.view`, and `router/index.ts` gates the route on the same
capability, so with zero `telephony.calls.*` capabilities the reference Calls UI
is unreachable through normal navigation.

Direct navigation to `https://app.utcp.local.test/calls` landed on
`/forbidden` — *"This route is not available for the current session
capabilities."* Screenshot: `.playwright-mcp/c6e-calls-forbidden.png`
(not committed).

Corroborating API read from inside the authenticated browser session:

    GET /api/v1/calls  ->  403 {"message":"Forbidden"}

## Blocker 1 — C6 capability catalog never reaches an existing database

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking — every C6 endpoint and the whole Calls UI are
              unreachable on any real deployment
    FILE:     apps/api/database/migrations/2026_08_16_100000_create_c6_call_tables.php
    DEFECT:   the migration creates the C6 tables but never synchronizes the
              identity capability catalog

    EXPECTED: after deployment, `capabilities` contains the six
              `telephony.calls.*` keys and `role_capabilities` grants all six to
              `tenant-admin`.
    ACTUAL:   capabilities = 21 rows, `telephony.calls.*` = 0;
              role_capabilities `telephony.calls.*` = 0.

### Root cause

`config/identity.php` declares the six new capabilities and attaches them to
`tenant-admin`, but that file is only *configuration*. Rows reach the database
solely through a migration that iterates `config('identity.capabilities')`. The
established repository pattern is that **every migration which introduces new
capabilities re-runs the catalog sync**:

    2026_07_14_110000_create_identity_tenancy_authorization_tables.php  syncCatalog()
    2026_07_14_130000_create_runtime_registry_tables.php                (C2)
    2026_07_15_090000_create_telephony_session_conference_domain_tables.php  syncIdentityCatalog()
    2026_07_16_090000_create_signaling_registration_tables.php          (T1)

The C6 migration is the first capability-introducing migration that does not.
`grep -n "capabilit" 2026_08_16_100000_create_c6_call_tables.php` matches only
the unrelated column names `caller_identity_ref` and `remote_identity`.

On an already-migrated database the C1 identity migration is recorded as run and
never re-executes, so nothing ever inserts the six rows. There is no other sync
path: `ensureManagedCapabilities` concerns runtime-node capabilities, not
identity capabilities, and `identity:bootstrap-local` performs no catalog sync
and short-circuits when a platform administrator already exists.

### Why the PostgreSQL migration proof passed

`make control-plane-migrate-proof` runs against a **disposable empty**
PostgreSQL database. There, `2026_07_14_110000` executes for the first time and
its own `syncCatalog()` reads the *current* `config/identity.php` — which already
contains the six C6 capabilities. The proof therefore observed all six rows and
concluded the catalog sync works.

This is the third occurrence of the same class of gap in this repository, and
the second within C6 alone:

    RH-2D   test double more permissive than the browser
    C6 (1)  SQLite more permissive than PostgreSQL
    C6 (2)  empty database more permissive than a migrated database

The proof substrate keeps being more permissive than production.

### Bounded correction (do NOT implement in this packet)

`2026_08_16_100000_create_c6_call_tables` is already recorded in `migrations`
(batch 6) on the canonical database, so **adding the sync to that file will not
fix any existing deployment** — it will only fix fresh ones. The correction must
be a *new* migration, for example
`2026_08_2x_xxxxxx_sync_c6_call_capability_catalog.php`, that performs the same
idempotent `updateOrInsert` catalog sync used by
`2026_07_15_090000_create_telephony_session_conference_domain_tables.php`.

Required regression guard: `make control-plane-migrate-proof` must additionally
exercise migration onto a database that is **already migrated up to the previous
batch**, not only an empty one. An empty-database proof structurally cannot
detect a missing catalog sync.

## Blocker 2 — managed RuntimeNode capabilities are frozen at provisioning time

    CLASS:    IMPLEMENTATION (design question) — blocking for this proof
    SEVERITY: blocking after Blocker 1 is fixed
    FILE:     apps/api/app/RuntimeProvisioning/ManagedAsteriskProvisioningOperationHandler.php:71
              (sole call site of RuntimeRegistryService::ensureManagedCapabilities)

Both `active` / `ready` Asterisk RuntimeNodes declare exactly four capabilities:

    d4539d79-…  conference.lifecycle  conference.participation  event.stream  runtime.observation
    c7e6f4ba-…  conference.lifecycle  conference.participation  event.stream  runtime.observation

None of the seven C6 families (`call.origination`, `call.control`, `call.hold`,
`call.transfer`, `call.dtmf.send`, `media.playback`, `recording`) is declared.
`CommandWorker.php:151-152` gates every operation on a matching
`runtime_node_capabilities` row, so `call.leg.originate` would be rejected with
`UnsupportedCapability` even with the capability catalog fixed.

`ensureManagedCapabilities(... config('runtime_registry.adapter_keys.asterisk-ari.supported_capabilities'))`
is called **only** from the provisioning handler. No reconciliation path
converges an already-provisioned managed node onto the current adapter catalog.
Both nodes have a `runtime_provisioning_requests` row, so they are *managed* and
`assertManualMutationAllowed` makes `PUT /runtime-nodes/{id}/capabilities`
return 422 — the Admin UI cannot correct them either.

Two readings, and the choice is a product decision rather than a proof decision:

1. **Intended**: capabilities are a provisioning-time snapshot. Then no code
   change is needed and the next proof simply provisions a *new* managed
   Asterisk node through the canonical RNP Admin UI flow, which will declare the
   full current adapter catalog. This is ordinary reversible application-domain
   work inside `utcp-local`.
2. **Gap**: managed nodes should converge to the adapter catalog when the
   catalog changes. Then a bounded reconciliation correction is required.

Reading 1 unblocks the next proof without any repository change and is the
recommended path. Reading 2 should be decided separately and not inside the
live-proof packet.

## Blocker 3 — no generic Stasis fixture exists on a managed Asterisk node

    CLASS:    PROOF_HARNESS
    SEVERITY: blocking for the inbound corridor; recoverable for the outbound one

Complete committed dialplan on a managed Asterisk node
(`dialplan show`, read-only):

    from-kamailio
      _[c]o[n]f-.  NoOp, Answer, Stasis(utcp-t0-observation,${EXTEN}), Hangup
      _.           NoOp, Hangup(21)
    utcp-conference-proof
      participant  NoOp, Answer, Stasis(utcp-t0-observation), Hangup
    stasis-utcp-t0-observation  (res_stasis internal)

The `anonymous` PJSIP endpoint — the only endpoint on either node — has
`context: from-kamailio`.

**Correction to the previous packet's fixture recommendation.** It proposed
`destination_ref = sip:anonymous/sip:9900@127.0.0.1:5060`. Two problems:

* `9900` exists **only** on the K1-base `asterisk-ari` Deployment, injected by
  `infrastructure/kubernetes/overlays/local/runtime/extensions.local.conf`. It
  is absent from both managed nodes. On a managed node the loopback INVITE falls
  through to `_.` → `Hangup(21)`, the far end never answers, so the originated
  channel never enters Stasis and the deterministic
  `utcp-call-leg-<CallLeg ID>` correlation — which lives exclusively in the
  `stasis_start → call.leg.offered` branch of
  `AsteriskAriEventNormalizer::normalizeGenericCallEvent()` — never fires.
* The K1-base `asterisk-ari` Deployment is not a registered RuntimeNode, so it
  cannot be an operation target as things stand.

What actually works for the **outbound** corridor: the originating channel
enters Stasis as soon as the far end answers, independently of the far end's own
dialplan. So any answering loopback destination is sufficient. The clean option
that needs no repository change is to register the K1-base `asterisk-ari`
Deployment as an **external** RuntimeNode through the Admin UI — external nodes
pass `assertManualMutationAllowed`, so the seven C6 capability families can be
declared normally — and originate to `sip:anonymous/sip:9900@127.0.0.1:5060`,
where `9900` answers and runs `Echo()`.

The **inbound** corridor has no clean fixture. A generic inbound Call requires a
non-conference channel that enters Stasis, and no committed extension produces
one: `9900` answers but never enters Stasis; `utcp-conference-proof/participant`
enters Stasis but no SIP route reaches that context; `_[c]o[n]f-.` enters Stasis
but is the conference admission destination. Using `conf-*` as a generic
destination would technically be adopted as generic (`conferenceChannel()`
checks `conference_participants` rows, not the extension name) but deliberately
borrows the frozen conference corridor and is not recommended.

The deterministic correction is one bounded repository addition: a generic
Stasis fixture extension alongside `9900` (for example `9901 → NoOp, Answer,
Stasis(utcp-t0-observation)`) reachable on the runtime used for the proof. Until
that exists, inbound generic adoption is `PROOF_HARNESS` blocked.

## Outbound Runtime Correlation

    NOT ATTEMPTED — blocked at Blocker 1.

The static reading recorded in the previous packet is unchanged and remains the
first thing to verify in the next attempt. The correction is present in the
working tree: `AsteriskAriClient::executeCallOperation()` now supplies
`channelId = utcp-call-leg-<CallLeg ID>` on `POST /channels`, and the normalizer
resolves that channel id back to the pending unbound outbound CallLeg before
falling through to inbound adoption. This has not been observed live.

## Inbound Adoption

    NOT ATTEMPTED — blocked at Blocker 1; see Blocker 3 for the fixture gap.

## Conference Isolation

Not re-proven and not disturbed. The frozen conference corridor was left
untouched: no conference was created, no participant admitted, and no
conference channel was controlled. The projection pipeline continued producing
conference observations normally throughout the run (376 `conference.lifecycle`,
57 `runtime.readiness`, 2 `runtime.connection` in the final ten minutes).

## Security / Authority

    DIRECT ARI CONTROL:   NO
    DB MUTATION:          NO
    SESSION INJECTION:    NO
    OBSERVATION INJECTION: NO
    FEATURE GATE:         NO
    MANUAL RECONCILE:     NO
    SOURCE PATCHED:       NO

Asterisk CLI was used read-only (`dialplan show`, `pjsip show endpoints`,
`pjsip show endpoint anonymous`). PostgreSQL was queried read-only. The one
account mutation was the sanctioned break-glass password reset through
`make user-access-reset-password`, restored immediately through the normal
change-password screen.

## Failed Proof Steps

    1. Capability catalog sync — 0 of 6 `telephony.calls.*` capabilities exist.
    2. Calls UI navigation — the `Calls` entry is not rendered; /calls -> /forbidden.
    3. Outbound generic call corridor — not attempted.
    4. Inbound generic adoption corridor — not attempted.

## Cleanup and Environment State

    calls / call_legs / call.leg* observations: 0 / 0 / 0
    runtime nodes:      d4539d79-… active/ready, c7e6f4ba-… active/ready
    platform:           14/14 Deployments rolled out on the fresh digest
    migrate Job:        Complete (the two historical Error pods from the previous
                        attempt were replaced by this run's successful Job)
    media edge:         re-applied after k8s-apply
    browser:            logged out through the normal UI, context closed
    admin account:      password restored to the bootstrap.json value
    credential scripts: removed from .playwright-mcp/

`apntalk-local` remained stopped. No cluster, registry, host port, node,
volume, or deployment-mechanism change was made.

## Repository Verification

    git diff --check        -> clean
    make repository-hygiene -> passed
    make secret-scan        -> passed

## C6 Status

    C6 CONTRACT:         COMPLETE
    C6A:                 IMPLEMENTED / TESTED / **POSTGRESQL PROVEN**
    C6B:                 IMPLEMENTED / TESTED
    C6C:                 IMPLEMENTED / TESTED
    C6D:                 IMPLEMENTED / TESTED
    C6E IMPLEMENTATION:  IMPLEMENTED / TESTED
    REFERENCE CALL UI:   IMPLEMENTED / TESTED (unreachable in deployment)
    C6E LIVE:            **FOUND BLOCKER — not proven**
    C6:                  NOT LIVE PROVEN

## Recommended Next Step

    BOUNDED CODEX CORRECTION

1. Add a new migration that idempotently synchronizes the identity capability
   catalog, so the six `telephony.calls.*` capabilities and their `tenant-admin`
   grants reach databases that are already migrated. Do not rely on editing
   `2026_08_16_100000_create_c6_call_tables.php` — it has already run.
2. Extend `make control-plane-migrate-proof` to exercise an *already-migrated*
   database in addition to an empty one.
3. Decide Blocker 2: either accept provisioning-time capability snapshots (no
   code change; the next proof provisions a fresh managed Asterisk node through
   the Admin UI) or add convergence for managed nodes.
4. Resolve Blocker 3 by adding one generic Stasis fixture extension for the
   runtime used by the proof, or accept that inbound adoption stays
   `PROOF_HARNESS` blocked and prove outbound only.

Then re-run this C6E natural frontend proof.
