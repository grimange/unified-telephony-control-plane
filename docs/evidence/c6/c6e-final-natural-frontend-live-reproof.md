# C6E — Final Natural Frontend Asterisk Live Reproof (fifth attempt)

Date: 2026-08-22

## Verdict

    C6E_ASTERISK_NATURAL_FRONTEND_LIVE_PROOF_FOUND_BLOCKER

This run advanced further than any previous attempt. For the first time a real
Asterisk channel was created on a **managed RuntimeNode** whose UniqueID matched
the reserved canonical identity exactly, and the corrected conference guard on
the command path executed cleanly.

The corridor then stopped at the **observation** half, on one exact defect: the
conference-ownership fix was applied to `AsteriskRuntimeAdapter` but the
**duplicate copy in `AsteriskAriEventNormalizer` was missed**. Every generic call
event therefore fails normalization with the same `SQLSTATE[42703]`, so no
`call.leg.*` observation is ever written and neither the outbound nor the inbound
corridor can complete.

A second, independent condition was found: `telephony-reconciler` crash-loops on
an uncaught fencing-token exception, which delays the origination deadline from
the configured 60 s to ~5.5 min.

No production source was modified.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  C6 implementation + corrections present and uncommitted
    commit/push:   none created, not pushed

## Deployment

Canonical lifecycle only: `make k8s-apply` (which internally runs config-check →
image-build → image-push → apply and performs its own rollout restarts). **No
separate manual application rollout was issued.**

    API / WORKER / COMMAND-WORKER / NORMALIZER / RECONCILER / ARI-EVENTS:
        utcp/api @sha256:e8586fe87b8b78fdd92ac2bf360cb54d7736a94bcf8afbcbe95eae9bd5deb14e
    WEB:
        utcp/web @sha256:5ae5d902ae2e54a4755191b73dd79a7288dd8b368b82295ffa30945f24ce6d51
    ASTERISK (K1-base):
        utcp/asterisk-ari @sha256:06e3a9bed9e9d42f568367780662945440d52a868bb2e151013d82913a455a4f
    MIGRATION:         utcp-migrate Complete 1/1 (6 s)
    DEPLOYMENT FRESH:  YES

Cluster/context: `utcp-local` / `k3d-utcp-local`, via the repository-pinned
kubeconfig. `apntalk-local` was not touched.

## Managed Runtime Reverification

### Fixture delivery — works for newly provisioned nodes only

`ManagedAsteriskProvisioningOperationHandler::deployment()` now emits the volume
and mount, and `applyDeployment()` is called **only** from the provisioning
handler — there is no drift reconciliation for managed workloads. The two
pre-existing managed Deployments were therefore still fixture-less after the
apply:

    asterisk-rnp6-readiness-reproof-20260809-e2fb39c7   volumes: (none)
    asterisk-v0c6-conference-runtime-20260815-5ce1a2de  volumes: (none)

To determine whether the correction itself works, one new managed Asterisk
runtime was provisioned through the canonical RNP Admin UI flow
("Create a new runtime", name-only form):

    NODE:        c6e-final-proof-20260822
    ID:          3488f30f-bdf8-4a2a-b2f9-e865b0c625d0
    DEPLOYMENT:  asterisk-c6e-final-proof-20260822-4e9ac74e
    STATE:       active / ready
    CAPABILITIES: call.control, call.dtmf.send, call.hold, call.origination,
                  call.transfer, conference.lifecycle, conference.participation,
                  event.stream, media.playback, recording, runtime.observation

The correction is confirmed on that node:

    CONFIGMAP:  asterisk-local-sip-fixtures  (utcp-runtime)
    VOLUME:     {"configMap":{"name":"asterisk-local-sip-fixtures","optional":true},
                 "name":"asterisk-local-config"}
    MOUNT:      /opt/utcp-asterisk-local-config (readOnly)
    FILE:       /tmp/utcp-asterisk/extensions.local.conf

    dialplan show from-kamailio  (live, on the managed Pod)
      'c6-generic-proof' => 1. NoOp(UTCP C6 generic inbound proof fixture)
                            2. Answer()
                            3. Stasis(utcp-t0-observation,c6-generic-proof)
                            4. Hangup()
      '9900'            => Answer, Echo, Hangup
      '_[c]o[n]f-.'     => conference admission
      '_.'              => Hangup(21)

    STASIS APPLICATION: utcp-t0-observation
    LOOPBACK:           sip:anonymous/sip:c6-generic-proof@127.0.0.1:5060

**Finding (non-blocking for this run, but material):** existing managed
Deployments are never converged onto a corrected spec. Any managed node
provisioned before a handler change keeps its original spec indefinitely.

## Runtime Catalog UI

    BACKEND VALUES:  endpoint_transports = ["http","https","tcp","tls","udp","ws","wss"]
                     endpoint_tls_modes  = ["disabled","opportunistic","required","verify"]
    FRONTEND VALUES: http="http" https="https" tcp="tcp" tls="tls"
                     udp="udp" ws="ws" wss="wss"
    NUMERIC OPTIONS: NONE

The previous packet's index-rendering defect is **closed**. The new helper
`apps/web/src/views/runtimeCatalogPresentation.ts` maps `string[] → {key,label}`
correctly. The TLS-mode control renders only against an existing endpoint and
was not exercised, but it is fed by the same verified helper.

## Natural Login

    LOGIN PAGE:  https://app.utcp.local.test/login  (real page, ordinary form)
    USER:        admin@utcp.local.test
    TENANT:      Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
                 (selected through the real Active-tenant control)
    C6 CAPABILITIES: 6 of 6 — view, view_own, originate, control, record, manage
    SESSION BYPASS:  NO

## Outbound Call

Created entirely through the Calls UI `New outbound Call` form.

    CALL:                f7e96142-ec8c-4458-a3a8-7a8dd352c10c
    CALL LEG:            ce9c19ba-7e52-4378-b71a-3a3ba1b922bf
    RUNTIME NODE:        3488f30f-bdf8-4a2a-b2f9-e865b0c625d0
    DESTINATION:         sip:anonymous/sip:c6-generic-proof@127.0.0.1:5060
    ORIGINATE OPERATION: succeeded, attempt 1
                         started/completed 2026-08-21 23:28:33Z

## Runtime Channel Correlation — PASSED

    RESERVED:  utcp-call-leg-ce9c19ba-7e52-4378-b71a-3a3ba1b922bf
    ASTERISK:  core show channel PJSIP/anonymous-00000000
                 UniqueID: utcp-call-leg-ce9c19ba-7e52-4378-b71a-3a3ba1b922bf
                 LinkedID: utcp-call-leg-ce9c19ba-7e52-4378-b71a-3a3ba1b922bf
                 State:    Up (6)
    MATCH:            EXACT
    STATE FABRICATED: NO — Call and CallLeg both remained `requested` while the
                      channel was Up; no RINGING, ANSWERED, HELD or COMPLETED
                      was invented by the reservation.

This is the first live confirmation that the reserved canonical identity is the
real Asterisk channel identity.

Both loopback channels were observed Up simultaneously:

    PJSIP/anonymous-00000000  s@from-kamailio:1                 Up  Stasis(utcp-t0-observation)
    PJSIP/anonymous-00000001  c6-generic-proof@from-kamailio:3  Up  Stasis(utcp-t0-observation,c6-generic-proof)

The far-end channel carried the Asterisk-generated UniqueID `1787354913.1` and
was the intended inbound-adoption subject.

## Conference Guard (command path) — PASSED

    POSTGRESQL ERROR:                NO
    CONFERENCE CLASSIFICATION:       NO — generic channel not treated as conference
    GENERIC OPERATION CONTINUED:     YES — call.leg.originate succeeded, attempt 1

`AsteriskRuntimeAdapter::conferenceOwnsChannel()` now joins `conferences` on
`conference_id` and filters `conferences.runtime_node_id`. The previous
blocking `SQLSTATE[42703]` on the command path is **closed and live-proven**.

## Blocking Defect — the same bad query survives in the event normalizer

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking — kills the entire C6 observation corridor, both directions
    FILE:     apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriEventNormalizer.php:230-237

The correction was applied to the adapter copy of the conference-ownership check
but not to the duplicate in the normalizer:

    private function conferenceChannel(object $receipt, string $channelId): bool
    {
        return DB::table('conference_participants')
            ->where('tenant_id', (string) $receipt->tenant_id)
            ->where('runtime_node_id', (string) $receipt->runtime_node_id)   // no such column
            ->where('runtime_channel_id', $channelId)
            ->exists();
    }

`conferenceChannel()` is the first database call in
`normalizeGenericCallEvent()` for any event carrying a non-empty `channel_id`
(line 143), so it runs before the event-type `match` and throws for **every**
generic call event.

Live evidence — the failing receipt:

    event_type    asterisk.ari.channel.stasis_start
    status        terminal_failed
    attempt_count 3
    failure_class internal_error
    failure_code  normalization_failed
    payload       {"ari_event_type":"StasisStart","channel_id":"1787354913.1",
                   "channel_name":"PJSIP_anonymous-00000001","channel_state":"Up",
                   "runtime_node_id":"3488f30f-bdf8-4a2a-b2f9-e865b0c625d0", …}

The exact query replayed read-only against the canonical PostgreSQL:

    select exists(select 1 from conference_participants
      where tenant_id='a2315712-…' and runtime_node_id='3488f30f-…'
        and runtime_channel_id='1787354913.1');
    ERROR:  column "runtime_node_id" does not exist

Actual `conference_participants` columns contain `runtime_channel_id` and
`runtime_channel_lost_at` but no `runtime_node_id`; node ownership is reachable
only through `conferences.runtime_node_id` via `conference_id` — exactly the fix
already applied in the adapter.

Consequences observed:

    runtime_observations rows of type `call%`   : 0
    outbound CallLeg observed_state             : requested (never advanced)
    inbound Calls adopted                       : 0

Events unaffected by the guard normalize normally, which is why the pipeline
looks partly healthy: `bridge.created` and the synthetic inspection events carry
no `channel_id`, so the `$channelId === ''` short-circuit at line 143 skips the
broken query entirely.

## Secondary Defect — reconciler crash-loop delays the origination deadline

    CLASS:    IMPLEMENTATION (robustness) — pre-existing, now more frequent
    SEVERITY: non-blocking for this verdict, but it degrades correction #7
    FILE:     apps/api/app/RuntimeEngine/Reconciliation/ReconciliationWorker.php:78

    if (! $this->repository->markResult($claim->id, $claim->lease_token, $result, $operationId)) {
        throw new \RuntimeException('runtime reconciliation fencing token was superseded');
    }

A lost-lease race on a single reconciliation target throws an **uncaught**
exception that terminates the whole worker instead of skipping that target. With
a third Asterisk RuntimeNode present the race hits more often:

    telephony-reconciler   0/1   CrashLoopBackOff   6 restarts in ~4 min

A typical cycle converges three targets, then dies on the fourth ~1 s after
start. The origination deadline still fired, but at **23:34:01Z for a Call
created 23:28:31Z — about 5.5 min, versus the configured
`origination_timeout_seconds => 60`.**

The safety net therefore still works and does not leak, but its latency is
governed by the crashing worker rather than by configuration.

## Observation Corridor / Command vs Observation

    NOT REACHABLE — no call observation was ever written.

The negative assertion still holds and is meaningful: canonical terminal state
came from origination-deadline reconciliation, never from operation success and
never from a fabricated observation.

## Outbound Duplication

    ORIGINAL OUTBOUND CALL:     1
    ORIGINAL OUTBOUND CALL LEG: 1
    ACCIDENTAL INBOUND CALL:    0
    ACCIDENTAL SECOND LEG:      0

No duplicate generic authority was created. Note the inbound count of 0 also
reflects the blocked adoption path, so this is not yet a positive adoption proof.

## Representative Controls

    HOLD / RESUME: not reached — requires an observation-confirmed active leg
    DTMF:          not reached
    HANGUP:        not reached; the Call terminalized through the deadline path

## Terminal Proof

    call  observed_state = failed
    leg   observed_state = failed
    termination_reason   = origination_timeout
    terminated_at        = 2026-08-21 23:34:01Z
    UI                   = failed / origination_timeout / Not bridged

No leak: 0 non-terminal Calls and 0 active channels on all four Asterisk Pods at
the end of the run.

## Timeline UI

    AUDIT              audit.call_leg.terminated  call_leg.terminated  07:34:01
    AUDIT              audit.call.terminated      call.terminated      07:34:01
    AUDIT              audit.call.created         call.created         07:28:31
    RUNTIME_OPERATION  operation.succeeded        call.leg.originate   07:28:31

    COMMAND / OBSERVATION / AUDIT separation: present and correct
    OBSERVATION rows: none — correctly, because none exist
    RAW ARI PAYLOAD:        NO
    SECRETS:                NO
    WORKER LEASE INTERNALS: NO

## Inbound Fixture / Source / Adoption

    FIXTURE:  present and correct on the managed node (see above)
    SOURCE:   real local SIP — the managed node's own PJSIP loopback INVITE,
              which genuinely reached c6-generic-proof and entered Stasis
              (channel PJSIP/anonymous-00000001, UniqueID 1787354913.1)
    ADOPTION: FAILED — the StasisStart for that channel is exactly the receipt
              that terminal-failed on the normalizer defect

The inbound source is therefore **not** a proof-harness gap. A real local SIP
source exists, reached the fixture, and entered Stasis. Adoption was blocked
purely by the normalizer defect.

## Conference Isolation

Not disturbed. No conference was created, no participant admitted, no
conference-owned channel controlled. All four Asterisk Pods ended with 0 active
channels. No RH change.

## Security / Authority

    DIRECT ARI CONTROL:     NO
    DB MUTATION:            NO
    SESSION INJECTION:      NO
    OBSERVATION INJECTION:  NO
    FEATURE GATE:           NO
    MANUAL RECONCILE:       NO
    MANUAL CAPABILITY REPAIR: NO
    MANUAL APPLICATION ROLLOUT: NO
    APNTALK TOUCHED:        NO
    SOURCE PATCHED:         NO

Read-only corroboration only: `dialplan show`, `core show channels`,
`core show channel`, and `psql` SELECTs.

## Cleanup

    Outbound Call:  terminal via the deadline path; retained as evidence.
                    0 non-terminal Calls remain.
    Asterisk:       0 active channels on all four Pods.
    RuntimeNodes:   rnp6-readiness-reproof-20260809  active / ready
                    v0c6-conference-runtime-20260815 active / ready
                    c6e-final-proof-20260822         active / ready  (RETAINED)
                    c6e-generic-proof-20260821       disabled
                    c6e-local-asterisk-20260822      disabled
    Session:        logged out through the normal UI.

`c6e-final-proof-20260822` is deliberately retained active/ready: it is the only
RuntimeNode carrying the generic fixture and is required by the next attempt.
Nothing was hard-deleted.

## Repository Verification

    git diff --check          clean
    make k8s-config-check     passed
    make repository-hygiene   passed
    make secret-scan          passed

## Code Changes

    NONE.

## Recommended Bounded Corrections

1. **Fix `AsteriskAriEventNormalizer::conferenceChannel()`
   (`:230-237`)** — apply the same `conferences` join already used by
   `AsteriskRuntimeAdapter::conferenceOwnsChannel()`. This single change unblocks
   the whole observation corridor. Add a regression test that normalizes a
   generic `StasisStart` against the real PostgreSQL schema; sweep for any other
   copy of this query.

2. **Make `ReconciliationWorker` lease loss non-fatal
   (`:78`)** — a superseded fencing token on one claim should skip that claim,
   not kill the worker. Until then the origination deadline runs minutes late.

3. **Converge existing managed Deployments** — decide whether managed workloads
   should be re-applied when the provisioning spec changes. Today a node keeps
   whatever spec it was created with, forever.

Item 1 is the only blocker for a C6E retry, and the retry can reuse the retained
`c6e-final-proof-20260822` node.

## C6 Status

    C6A-D:                 IMPLEMENTED / TESTED
    C6E IMPLEMENTATION:    IMPLEMENTED / TESTED
    REFERENCE CALL UI:     IMPLEMENTED / PARTIALLY LIVE PROVEN
    FIXTURE ON MANAGED NODE:      VERIFIED LIVE (new nodes only)
    RUNTIME CATALOG UI:           VERIFIED LIVE
    DETERMINISTIC CHANNEL IDENTITY: VERIFIED LIVE (exact Asterisk UniqueID match)
    CONFERENCE GUARD (command):     VERIFIED LIVE
    CONFERENCE GUARD (normalizer):  DEFECTIVE — blocking
    ORIGINATION DEADLINE:           FUNCTIONAL but ~5.5 min late
    OBSERVATION CORRIDOR:           BLOCKED
    C6E LIVE:                       FOUND_BLOCKER

    C6:                    NOT LIVE PROVEN

## Recommended Next Step

    BOUNDED CODEX CORRECTION — item 1 (and ideally item 2), then re-run C6E.

T4 must not start. No further C6 audit is required; the defect is exact and
one-line-localized.
