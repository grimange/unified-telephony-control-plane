# C6E — Asterisk Generic-Call Natural Live Proof

## Verdict

    C6E_ASTERISK_GENERIC_CALL_NATURAL_LIVE_PROOF_FOUND_BLOCKER

The proof stopped at **deployment**. The C6A migration cannot run against
PostgreSQL, so no C6 schema exists in the canonical database and neither the
outbound nor the inbound corridor could be attempted. One exact implementation
defect is isolated, with a one-line-class correction. A second observed symptom
(missing `telephony.calls.*` capabilities) is a **downstream consequence of the
same defect**, not an independent one.

No production source was modified. No source will be patched here — the correction
belongs to Codex.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  C6A-C6E implementation present and uncommitted (36 paths)
    commit/push:   none created, not pushed

## Deployment

    API:        utcp/api  @sha256:153636632ec97ddd5a149dc744af55dd2d9c87898d648e8fac702b77235dc4c8
    WORKER:     utcp/api  @sha256:153636632ec97ddd… (worker + telephony-command-worker, same image)
    WEB:        utcp/web  @sha256:449ed24b901be7c5e717f700732b382f783b4b63cf72fd9ba91988dd33c6eb07
    ASTERISK:   utcp/asterisk-ari:0.1.0-k1-dev on the canonical managed node
    RUNTIME NODE: d4539d79-432d-48dc-8def-d52e0d0ca5e2 — active / ready
    DEPLOYMENT FRESH: **NO — `make k8s-apply` FAILED**

Lifecycle attempted: `k8s-image-build` → `k8s-image-push` → `k8s-apply` (**failed**)
→ rollout restart of all sixteen `utcp-platform` Deployments (succeeded) →
`media-edge-apply` (succeeded). `security-apply` was not required: the apiserver
`ipBlock` pins were already current on 172.21.0.2.

### Content verification — the C6E code IS in the deployed image

Inside the running API Pod:

    /var/www/html/app/TelephonyDomain/
      CallDirection.php  CallDomainService.php  CallLegRole.php
      CallObservationProcessor.php  CallOperationCatalog.php
      CallQueryService.php  CallState.php
    grep -c "call.leg.originate" CallOperationCatalog.php -> 1

The application code is deployed. **The schema is not.**

## The Blocker

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking — C6 is undeployable on PostgreSQL
    FILE:     apps/api/database/migrations/2026_08_16_100000_create_c6_call_tables.php
    LINE:     the `bridged_to_leg_id` foreign key inside the `Schema::create('call_legs', …)` closure

    EXPECTED: `php artisan migrate --force` creates `calls` and `call_legs`.
    ACTUAL:   the migration aborts and the `utcp-migrate` Job fails
              (BackoffLimitExceeded, 2 failed pods).

Verbatim runtime failure:

    2026_08_16_100000_create_c6_call_tables ....................... 17.20ms FAIL

    SQLSTATE[42830]: Invalid foreign key: 7 ERROR:  there is no unique constraint
    matching given keys for referenced table "call_legs"
    (SQL: alter table "call_legs" add constraint "call_legs_bridged_to_leg_id_foreign"
     foreign key ("bridged_to_leg_id") references "call_legs" ("id") on delete set null)

### Root cause

`call_legs` declares its primary key as a **column modifier**
(`$table->uuid('id')->primary()`) and, in the *same* `Schema::create()` blueprint,
declares a **self-referencing** foreign key
(`bridged_to_leg_id → call_legs.id`).

Laravel emits the blueprint's foreign-key `ALTER TABLE` statements before the
primary-key command derived from that column modifier. PostgreSQL requires the
referenced column to already carry a unique/primary constraint at the moment the
foreign key is added, so the self-reference fails.

This is specific to the **self**-reference. The sibling FK
`call_legs.call_id → calls.id` succeeded, because `calls` was fully created —
primary key included — by an earlier, separate `Schema::create()` call.

### Why the repository tests passed

`apps/api/phpunit.xml:27-28` runs the whole suite on **SQLite in memory**:

    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>

SQLite accepts a self-referencing foreign key inside `CREATE TABLE` and does not
enforce the "referenced column must already be unique" rule the way PostgreSQL
does. All five C6 test files
(`CallDomainServiceTest`, `CallObservationProcessorTest`, `CallApiTest`,
`SimulatorCallOperationHandlerTest`, `C6EGenericCallExecutionTest`) therefore pass
against a schema that PostgreSQL will never accept. The migration is additionally
the only place with a `DB::getDriverName() === 'pgsql'` guard, which shows the
author was aware of driver divergence but did not exercise the PostgreSQL path.

This is the same class of gap as the RH-2D `attemptId === undefined` short-circuit
and the RH-3 Registerer test double: **the test substrate is more permissive than
production.**

### Bounded correction (proven — do NOT implement in this packet)

Move the self-referencing foreign key out of the creating blueprint so it is added
after `call_legs` (and its primary key) exists:

    Schema::create('call_legs', function (Blueprint $table): void {
        …                                  // drop the bridged_to_leg_id foreign() here
    });

    Schema::table('call_legs', function (Blueprint $table): void {
        $table->foreign('bridged_to_leg_id')->references('id')->on('call_legs')->nullOnDelete();
    });

Equally acceptable and arguably simpler: drop the database-level self-FK entirely
and keep the relationship application-enforced — the C6 contract never required a
DB-level self-reference, and `CallDomainService::applyObservedBridge()` already
validates both legs.

Required regression guard: the C6 migration must be exercised against **PostgreSQL**,
not only SQLite. The repository already has a precedent target for this —
`make control-plane-migrate-proof` ("Prove C0 migrations against a disposable
PostgreSQL database"). C6 should be added to that corridor.

## Consequential Symptom — not a separate defect

    GET /api/v1/calls  ->  403 Forbidden
    session capabilities: 8, of which telephony.calls.* : 0
    capabilities table: 21 rows, telephony.calls.* : 0

The six `telephony.calls.*` capabilities are correctly declared in
`config/identity.php:28-33` and assigned to the `tenant-admin` role at line 63, but
they are absent from the database because the identity catalog sync runs **after**
the failed migration in the same `migrate --force` invocation. Fixing the migration
is expected to resolve this automatically; it should be re-verified, not fixed
separately.

**Proof-harness note for the next attempt:** `tenant-member` is *not* granted any
`telephony.calls.*` capability — only `tenant-admin` is. The C6E live proof account
must therefore hold the `tenant-admin` role. The account used here
(`t3-s3b-t3s3b1785716804@utcp.local.test`) is a tenant member and would still be
refused after the migration is fixed.

## What Was Verified Before The Blocker

    natural login:      PASS — real login page at https://app.utcp.local.test,
                        ordinary flow, no injected session
                        USER   t3-s3b-t3s3b1785716804@utcp.local.test
                        TENANT Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
                        CAPS   8 (none telephony.calls.*)
    C6D routing:        PASS — /api/v1/calls is registered and reachable; without a
                        tenant it returns the canonical 409 "Active tenant context is
                        required", with a tenant a canonical 403 — not a 404, so the
                        route, middleware and tenant-context guard are live
    capability config:  PASS — call.origination / call.control / call.hold /
                        call.transfer / call.dtmf.send / media.playback / recording
                        declared in config/runtime_registry.php and attached to both
                        asterisk-ari and simulator adapters
    adapter dispatch:   PASS (static) — AsteriskRuntimeAdapter routes unknown types to
                        the CallOperationCatalog branch and otherwise returns
                        FailureClass::UnsupportedCapability
    conference isolation: PASS (static) — both AsteriskRuntimeAdapter::conferenceOwnsChannel()
                        and CallDomainService::adoptInboundLeg() refuse
                        conference-owned channels (`asterisk_conference_channel_not_generic`)
    projection pipeline: HEALTHY — 371 conference.lifecycle, 68 runtime.readiness,
                        30 conference.participant observations in the last 10 minutes

    outbound corridor:  NOT ATTEMPTED (blocked)
    inbound corridor:   NOT ATTEMPTED (blocked)
    call.leg%% observations ever recorded: 0

## Second Finding — outbound runtime-channel correlation (UNVERIFIED)

Recorded as a **static reading only**, because the blocker prevented live
confirmation. It should be checked deliberately during the re-run rather than
discovered again.

`AsteriskAriClient::executeCallOperation()` issues
`POST /channels?endpoint=…&app=utcp-t0-observation` for `call.leg.originate` and
**discards the ARI response**, returning only
`['provider_action' => 'channels.originate', 'destination_ref' => …]`. No
`channelId` is supplied on the request, so UTCP neither chooses nor captures the
channel identity. The only binding path is
`CallObservationProcessor` → `bindObservedRuntimeChannel()`, which runs after
`resolveLegId()` — and `resolveLegId()` skips `runtime:`-prefixed subject ids.

Meanwhile `AsteriskAriEventNormalizer` maps `stasis_start` → `call.leg.offered`
unconditionally for any non-conference channel, and the processor routes
`call.leg.offered` straight to `adoptInboundLeg()`.

On that reading, an originated outbound channel would arrive as `call.leg.offered`
with `subject_id = 'runtime:<channel>'`, be **adopted as a new inbound Call**, while
the pending outbound leg keeps `runtime_channel_id = NULL` indefinitely. If that is
what happens live, the acceptance item "exact canonical CallLeg runtime channel
matches real Asterisk channel" cannot pass.

    STATUS: NOT PROVEN — do not treat as a confirmed defect.
    ACTION: verify first thing in the C6E re-run; if confirmed, the natural fix is to
            pass an explicit `channelId` on originate (making UTCP the allocator of
            the runtime channel identity) or to correlate the StasisStart to the
            pending leg before falling through to adoption.

## Proof Fixture Assessment

The canonical Asterisk node exposes exactly **one** PJSIP endpoint —
`anonymous`, `Unavailable`, `0 of inf`, with no AOR — plus the committed dialplan
fixtures `from-kamailio` (`conf-*` conference admission, `9900` Echo from
`infrastructure/kubernetes/overlays/local/runtime/extensions.local.conf`) and
`utcp-conference-proof`.

`AsteriskAriClient::asteriskEndpoint()` maps `sip:`/`tel:` destinations to
`PJSIP/<remainder>` only. Consequently:

* `sip:9900` → `PJSIP/9900` — **no such endpoint**; `9900` is a dialplan extension,
  not a PJSIP endpoint.
* `sip:anonymous` → `PJSIP/anonymous` — endpoint exists but has no AOR/contact.

A viable, configuration-free loopback fixture does exist and should be used for the
re-run: `destination_ref = sip:anonymous/sip:9900@127.0.0.1:5060`, which
`asteriskEndpoint()` renders as `PJSIP/anonymous/sip:9900@127.0.0.1:5060` — the
standard PJSIP endpoint-plus-URI dial string. Asterisk sends the INVITE to itself,
identifies it as `anonymous`, and lands on the committed `9900` Answer/Echo
extension. This introduces **no new topology and no configuration change**.

Note for the inbound corridor: the `9900` extension does **not** enter Stasis, so it
produces no `StasisStart` and therefore no `call.leg.offered`. Inbound adoption
would need either a dialplan fixture that enters Stasis or a second synthetic SIP
peer. Classify that specific need as **PROOF_HARNESS** and resolve it in the same
re-run planning.

## Security / Authority

    DIRECT ARI CONTROL USED:   NO
    DB MUTATION:               NO
    SESSION BYPASS:            NO
    OBSERVATION INJECTION:     NO
    FEATURE GATE:              NO
    SOURCE PATCHED:            NO

Asterisk CLI was used read-only (`pjsip show endpoints`). PostgreSQL was queried
read-only.

## Cleanup and Environment State

    failed migrate Job:  utcp-migrate — Failed (BackoffLimitExceeded), 2 Error pods
                         retained as evidence; the next `make k8s-apply` deletes and
                         recreates the Job, so this residue self-clears
    platform:            16/16 Deployments rolled out; telephony-reconciler continues
                         its known pre-existing fencing-token restart cycle
    runtime node:        d4539d79-… active / ready
    admitted conference participants: 0
    projection pipeline: healthy (see counts above)
    browser:             logged out normally
    media edge:          re-applied after k8s-apply

**Latent condition to be aware of while the fix is pending.** The C6 code is
deployed against a database with no `call_legs` table.
`AsteriskAriEventNormalizer::normalizeGenericCallEvent()` queries `call_legs` for
any **non-conference** channel event. Conference channels short-circuit earlier via
`conferenceChannel()`, which is why nothing has failed — there has been no generic
channel traffic and `call.leg%` observation count is 0. A generic channel appearing
before the migration is fixed would raise a missing-relation error in the
normalizer. The conference/V0/RH corridor itself is unaffected. This argues for
landing the migration fix and re-applying promptly rather than leaving the
environment in this state indefinitely.

No rollback was performed: the prior image is built from the same uncommitted tree
minus C6, so rolling back would not produce a cleaner baseline, and the risk is
confined to a code path that cannot currently be reached.

## Repository Verification

    git diff --check        → clean
    make repository-hygiene → passed
    make secret-scan        → passed

## Failed Proof Steps

    1. make k8s-apply — utcp-migrate Job failed on
       2026_08_16_100000_create_c6_call_tables (SQLSTATE 42830).
    2. Outbound generic call corridor — not attempted (no schema).
    3. Inbound generic adoption corridor — not attempted (no schema).

## C6 Status

    C6 CONTRACT:         COMPLETE
    C6A:                 IMPLEMENTED / TESTED (SQLite only) — **BLOCKED on PostgreSQL**
    C6B:                 IMPLEMENTED / TESTED
    C6C:                 IMPLEMENTED / TESTED
    C6D:                 IMPLEMENTED / TESTED (routes live-verified reachable)
    C6E IMPLEMENTATION:  IMPLEMENTED / TESTED
    C6E LIVE:            **FOUND BLOCKER — not proven**
    C6:                  NOT LIVE PROVEN

## Recommended Next Step

    BOUNDED C6E CORRECTION (Codex)

    1. Fix the self-referencing foreign key in
       2026_08_16_100000_create_c6_call_tables.php (move to a follow-up
       Schema::table, or drop the DB-level self-FK).
    2. Add C6 migrations to a PostgreSQL migration proof so the SQLite/PostgreSQL
       divergence cannot recur.
    3. Re-verify that `telephony.calls.*` capabilities land in the database once the
       migration completes.
    4. Before re-running the live proof, decide the outbound channel-correlation
       question recorded above.

Then re-run this C6E live proof with a `tenant-admin` account and the
`sip:anonymous/sip:9900@127.0.0.1:5060` loopback fixture.
