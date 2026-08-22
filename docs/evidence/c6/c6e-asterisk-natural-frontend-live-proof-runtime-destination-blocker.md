# C6E — Asterisk Natural Frontend Live Proof (third attempt)

Date: 2026-08-21

## Verdict

    C6E_ASTERISK_NATURAL_FRONTEND_LIVE_PROOF_FOUND_BLOCKER

The two blockers that stopped the second attempt are **closed and
live-confirmed**. A natural tenant-admin session now carries all six
`telephony.calls.*` capabilities, the `Calls` navigation entry renders, `/calls`
loads, and both active managed Asterisk RuntimeNodes advertise the full C6
capability catalog. One outbound Call was created through the real UI and the
command half of the corridor executed correctly against a managed Asterisk node.

The proof then stopped because **no registered Asterisk RuntimeNode has any
reachable destination that can answer a call**. The originated channel therefore
never entered Stasis, no runtime observation was ever produced, and the
observation half of the corridor — the actual subject of C6E — could not be
exercised in either direction.

Three conditions produce this, and a fourth defect was found as a direct
consequence. None was patched; all corrections belong to Codex.

No production source was modified.

## Repository State

    branch:        main
    HEAD:          197df5a9371657688edeeb159a9325b39980e5fc
    phase marker:  UTCP_PHASE=T1
    working tree:  C6A-C6E implementation present and uncommitted (52 paths,
                   +1 = this evidence file)
    commit/push:   none created, not pushed

## Runtime Preflight

    API:          utcp/api @sha256:03b470505013776e9ffd4b89acaa7f115ec63b68f166153dfbba460769294441
    WORKER:       utcp/api @sha256:03b4705050…  (worker, scheduler, telephony-command-worker,
                  telephony-event-normalizer, telephony-reconciler, asterisk-ari-events,
                  simulator-event-source, control-plane-outbox-dispatcher,
                  utcp-runtime-fence-worker, kamailio-registration-observer, reverb)
    WEB:          utcp/web @sha256:cc3a6114f4f39dc1b1d0c19a3a2014ada41e317d57213f5309e38bc6d0e71705
    ASTERISK:     utcp/asterisk-ari @sha256:2e237274d63cbab37839a6ceb1c2c3df9b1ad7524e68cedf13b1eb1ec7f25e17
    MIGRATION:    utcp-migrate Job Complete 1/1
    RUNTIME NODES: c7e6f4ba-b925-462f-aff4-71c9fa9a4157  active / ready
                   d4539d79-432d-48dc-8def-d52e0d0ca5e2  active / ready
    CAPABILITIES: both nodes declare all eleven configured families, including
                  call.origination, call.control, call.hold, call.transfer,
                  call.dtmf.send, media.playback, recording
    C6 GENERIC FIXTURE: present — but only on the K1-base `asterisk-ari`
                  Deployment, which is **not a registered RuntimeNode** (Blocker 1)

Both API and Asterisk digests match the readiness packet exactly. The newest
source file in the working tree (`AppServiceProvider.php`, 12:39:23Z) predates
Pod creation (12:40:20Z) by 57 s, so the deployed environment corresponds to the
current tree. **No rebuild was performed and no manual rollout restart was
issued.**

### Environmental repair performed before the proof

The host had restarted, shuffling k3d node IPs. The apiserver moved to
`172.21.0.5` while the rendered NetworkPolicies still pinned `172.21.0.2` and
`172.21.0.3`. Traefik consequently returned `404` for every route, and
`kube-state-metrics` / `prometheus-operator` were in `CrashLoopBackOff`. This is
the documented endpoint-pin drift, classified **ENVIRONMENT**, not a C6 defect.

Repair used the canonical `make security-apply`, which re-pinned
`allow-traefik-kubernetes-api` and `allow-runtime-fencer-kubernetes-api` to
`172.21.0.5`. That target then failed at a later K2 stage with
`missing required K2 tool: helm` — helm is absent from this host, a known
pre-existing environmental gap unrelated to C6. Because Traefik had started
before the pin was corrected, its Gateway watch never established; a single
`kubectl rollout restart deploy/traefik` (ordinary reversible Pod replacement)
restored routing and `/login` returned `200`.

The two `utcp-observability` apiserver pins remain stale at `172.21.0.3`; they
sit behind the failed helm stage and affect only observability, not C6.

## Natural Login

    LOGIN PAGE:  https://app.utcp.local.test/login  (real page, ordinary form)
    USER:        admin@utcp.local.test  (UTCP Local Administrator)
    TENANT:      Local Tenant — a2315712-d650-4d43-8efb-1ac0e3cb356c
                 (selected through the real Active-tenant control)
    C6 CAPABILITIES: 6 of 6
        telephony.calls.view      telephony.calls.view_own
        telephony.calls.originate telephony.calls.control
        telephony.calls.record    telephony.calls.manage
    TOTAL CAPABILITIES: 25
    SESSION BYPASS: NO

No cookie, storage state, database session or Redis session was injected. The
password from `.runtime/identity/bootstrap.json` worked on the first attempt; no
break-glass reset was needed this time.

**Previous Blocker 1 is closed and live-confirmed.** The identity catalog
synchronization migration reached the already-migrated canonical database and
the six capabilities are present on a natural session.

## Calls UI

    NAVIGATION:          `Calls` entry rendered under `Runtime control`
    ROUTE:               /calls  (reached by clicking the navigation entry)
    LOADED:              YES — not /forbidden, not 403
    RAW API SUBSTITUTE:  NO

The reference consumer renders `Call list`, `New outbound Call` (Destination +
optional Runtime node ID), `Canonical state`, `Operations` and `Timeline`.

## Outbound Call

Created entirely through the `New outbound Call` form. No Call API request was
issued by hand.

    CALL:          041e3949-aeef-4e8e-b04d-2ae49922ed70
    CALL LEG:      9efec5da-6673-4d98-a564-953b4cff529a
    DIRECTION:     outbound
    DESTINATION:   sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060
    RUNTIME NODE:  c7e6f4ba-b925-462f-aff4-71c9fa9a4157 (rnp6-readiness-reproof-20260809)
    INITIAL STATE: requested / requested
    ORIGINATE OPERATION: ef2d0d78170f9afa169bd0451680d388  →  succeeded, attempt 1
                   lease telephony-command-worker-5b9d4449b-6hrjh:command:1
                   created 20:54:24Z, started/completed 20:54:27Z

## Outbound Runtime Correlation — command side confirmed

The emitted receipt proves the deterministic channel-id contract on the command
side:

    control_plane_outbox_messages / runtime_operation.asterisk_call_executed
    {"provider_action":"channels.originate",
     "runtime_channel_id":"utcp-call-leg-9efec5da-6673-4d98-a564-953b4cff529a",
     "destination_ref":"sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060"}

    EXPECTED ASTERISK CHANNEL:  utcp-call-leg-9efec5da-6673-4d98-a564-953b4cff529a
    CHANNEL ID SENT TO ARI:     utcp-call-leg-9efec5da-6673-4d98-a564-953b4cff529a   MATCH
    ACTUAL ASTERISK CHANNEL:    none — never observed
    CALL_LEG.RUNTIME_CHANNEL_ID: (unbound)
    ACCIDENTAL INBOUND CALL:    NO
    DUPLICATE GENERIC AUTHORITY: NO

`AsteriskAriClient::executeCallOperation()` supplied `channelId` exactly as
designed. The **observation-side** half of the correlation contract — normalizer
resolves that channel id back to the pending unbound outbound CallLeg instead of
falling through to generic inbound adoption — remains **unproven live**, because
no event was ever emitted for the channel.

## Outbound Runtime Timeline

    20:54:24Z  UI: Create outbound Call     → Call + CallLeg `requested`,
                                              operation `pending`
    20:54:27Z  worker → ARI POST /channels  → operation `succeeded`
    20:54:27Z  Asterisk log: only `acl.c ast_find_ourip: Unable to get hostname`
    (none)     no Stasis event, no runtime_observation, no state change
    20:59:56Z  UI: Hang up                  → operation `terminal_failed`
    21:03Z     Call still `requested`, `updated_at` unchanged since 20:54:24Z

`runtime_observations` in the surrounding twelve minutes contains only
`runtime.readiness.observed` rows for node `c7e6f4ba`. Zero call-family
observations were produced.

## Command vs Observation

The one assertion this run *can* make is the negative one the contract cares
about most, and the implementation passes it:

    RuntimeOperation `succeeded`  ≠  canonical state advanced

`call.leg.originate` reported `succeeded` on an ARI `2xx`, yet the canonical
Call and CallLeg correctly remained `requested`, the leg correctly remained
`Unbound`, and the UI correctly displayed `requested` / `Unbound` /
`Not terminal`. **The reference UI never derived state from command success.**
Source authority held.

Hold/Resume, DTMF, Answer and the terminal corridor could not be exercised: all
of them require a bound runtime channel.

## Blocker 1 — no registered RuntimeNode carries the generic Stasis fixture

    CLASS:    DEPLOYMENT / PROOF_HARNESS
    SEVERITY: blocking for the inbound corridor
    FILES:    infrastructure/kubernetes/overlays/local/runtime/kustomization.yaml
              infrastructure/kubernetes/base/runtime/asterisk-ari-deployment.yaml:88-92

The readiness packet states the fixture was added to "the managed Asterisk local
projection". In effect it reaches only the **K1-base `asterisk-ari` Deployment**:
the `asterisk-local-sip-fixtures` ConfigMap that carries
`extensions.local.conf` is mounted solely by
`base/runtime/asterisk-ari-deployment.yaml`. RNP-managed Deployments are written
programmatically by the provisioning handler and never mount it.

Observed dialplan, read-only, on each node:

    asterisk-ari (K1-base, NOT a RuntimeNode)
      9900              NoOp, Answer, Echo, Hangup
      c6-generic-proof  NoOp, Answer, Stasis(utcp-t0-observation), Hangup
      _[c]o[n]f-.       NoOp, Answer, Stasis(utcp-t0-observation,${EXTEN}), Hangup
      _.                NoOp, Hangup(21)

    asterisk-rnp6-readiness-reproof-20260809  (RuntimeNode c7e6f4ba)
    asterisk-v0c6-conference-runtime-20260815 (RuntimeNode d4539d79)
      _[c]o[n]f-.       NoOp, Answer, Stasis(utcp-t0-observation,${EXTEN}), Hangup
      _.                NoOp, Hangup(21)

The fixture itself is correct where it exists — it enters
`utcp-t0-observation`, is non-conference, is distinct from the `9900` Echo
fixture, and uses no C7 resource. It is simply on the wrong runtime.

## Blocker 2 — the Admin UI cannot register a plain-HTTP external Asterisk

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking for the recommended workaround; independently a
              frontend-owned-catalog violation
    FILE:     apps/web/src/views/RuntimeNodesView.vue:529-538 (and the duplicate
              block near :630)

The previous packet's recommended fix for Blocker 1 was to register the K1-base
`asterisk-ari` Deployment as an **external** RuntimeNode through the Admin UI.
That path is blocked.

The endpoint `Transport` control hardcodes exactly three options:

    <option value="https">HTTPS</option>
    <option value="wss">WSS</option>
    <option value="tcp">TCP</option>

The backend catalog is wider —
`config/runtime_registry.php:61` declares
`['http','https','tcp','tls','udp','ws','wss']` — and the Asterisk ARI adapter's
own requirements (`:29-30`) accept `http` for `control` and `ws` for `events`.
Both existing managed nodes use `http` + `ws`, values RNP writes programmatically
and the UI cannot express. The canonical Asterisk image serves ARI over plain
HTTP (`http.conf: tlsenable=no`), so **no Asterisk runtime built from this
repository's own image can be registered as an external node through the Admin
UI.** There is also no TLS-mode control, so `endpoint_tls_modes: disabled`
is unreachable.

This contradicts the standing rule that *the frontend must not own a checked-in
runtime or capability catalog*. A node was created through the real UI to confirm
this (`c6e-generic-proof-20260821`, `ddd63677-289d-4078-9a3a-cf27d0c5557d`); it
reached `draft` with no usable endpoint and was returned to `disabled` through
the normal lifecycle control.

## Blocker 3 — managed RuntimeNodes have no reachable answering destination

    CLASS:    PROOF_HARNESS (topology), with an authority consequence
    SEVERITY: blocking for the outbound corridor
    FILES:    infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml
              infrastructure/kubernetes/base/platform/kamailio-configmap.yaml:105-183

`allow-asterisk-sip-from-kamailio` selects **every** `asterisk-ari` Pod and, with
the namespace `default-deny`, permits SIP egress on UDP/5060 **only to Kamailio**.
Asterisk-to-Asterisk SIP is therefore impossible. Confirmed live from the managed
node:

    getent hosts asterisk-sip.utcp-runtime.svc.cluster.local -> 10.43.158.18
    UDP 5060 datagram sent                                   -> sent ok
    reply                                                    -> TimeoutError

Routing through Kamailio does not help. `route[APPLICATION_DIALOG]` requires
`$rd == "sip.utcp.local.test"` and then `www_authorize(...)` against
`kamailio_signaling_auth_view`, with `$au == $fU`. The only PJSIP endpoint on any
Asterisk node is `anonymous`, which has no `outbound_auth`, so it cannot answer a
digest challenge. Non-`conf-` INVITEs would otherwise relay to
`application-runtime-sip`, i.e. back to the K1-base node.

Pod-loopback SIP **does** work and is unaffected by NetworkPolicy — confirmed
live on the managed node:

    OPTIONS sip:127.0.0.1 from 127.0.0.1  ->  SIP/2.0 200 OK

But a managed node's own dialplan answers nothing generic: every non-`conf-`
extension falls through `_.` to `Hangup(21)`.

The only answering loopback destination on a managed node is the conference
admission pattern `conf-<uuid>`. Using it would borrow the frozen conference
corridor and was deliberately not attempted.

## Defect 4 — an unbound outbound CallLeg is permanently stranded

    CLASS:    IMPLEMENTATION
    SEVERITY: material; independent of Blockers 1-3 and reproducible anywhere a
              destination fails to answer
    FILES:    apps/api/app/RuntimeAdapters/Asterisk/AsteriskRuntimeAdapter.php:88
              apps/api/app/RuntimeAdapters/Asterisk/AsteriskAriClient.php:336-351

    EXPECTED: an origination that never binds a runtime channel eventually
              reaches a terminal CallLeg/Call state, or remains cancellable by
              the operator.
    ACTUAL:   it is neither.

`POST /channels` with `app=utcp-t0-observation` returns `2xx` as soon as the
channel object is created, so `call.leg.originate` is recorded `succeeded` even
though the dial subsequently fails. Because the channel never enters Stasis,
Asterisk emits no event to the subscribed application, so no
`runtime_observation` is written and the leg never binds `runtime_channel_id`.

Nothing then converges the leg:

* no origination timeout fails it (`timeout=30` is an Asterisk dial timeout, not
  a control-plane deadline);
* no reconciliation pass terminates it;
* `call.leg.hangup` is **refused** —

      operation call.leg.hangup -> terminal_failed
      last_failure_class conflict
      last_failure_code  asterisk_call_channel_unbound
      "CallLeg has no current Asterisk runtime channel."

  from the `$operationType !== 'call.leg.originate' && runtime_channel_id === ''`
  guard at `AsteriskRuntimeAdapter.php:88`;
* `call.leg.cancel_origination` exists in the catalog and maps to the correct ARI
  `DELETE /channels/{id}`, but it is caught by the same guard and the reference
  UI exposes only `Hang up`.

Nine minutes after creation the Call remained `requested` with `updated_at`
unchanged and `terminated_at` null. Every failed origination is a permanent
canonical leak with no operator remedy.

The reference UI is not at fault here — it faithfully displayed `requested`,
`Unbound`, `Not terminal`, and surfaced `operation.terminal_failed` in the
timeline.

## Timeline UI

Rendered for the proof Call, in canonical order:

    RUNTIME_OPERATION  operation.terminal_failed  call.leg.hangup     04:59:56
    AUDIT              audit.call.created         call.created        04:54:24
    RUNTIME_OPERATION  operation.succeeded        call.leg.originate  04:54:24

    COMMAND vs OBSERVATION vs AUDIT distinction: present and correct
    OBSERVATION rows: none — correctly, because none exist
    RAW ARI PAYLOAD SHOWN: NO
    SECRETS SHOWN:         NO
    WORKER LEASE INTERNALS: NO

The panel states "COMMAND and OBSERVATION remain separate; this view never
derives state locally", and the observed behaviour matches.

## Inbound Fixture / Inbound Adoption / Adoption Counts

    NOT ATTEMPTED — blocked at Blocker 1 and Blocker 2.

No inbound Call was created, no `runtime_observations` row was injected, no DB
row was inserted, and no direct ARI control was used.

## Conference Isolation

Not disturbed. No conference was created, no participant admitted, no
conference-owned channel controlled, and the `conf-` loopback shortcut was
deliberately not used. Both conference-capable nodes ended the run with zero
active channels. The frozen RH corridor was not touched.

## Security / Authority

    DIRECT ARI CONTROL:    NO
    DB MUTATION:           NO
    SESSION INJECTION:     NO
    OBSERVATION INJECTION: NO
    FEATURE GATE:          NO
    MANUAL RECONCILE:      NO
    MANUAL ROLLOUT:        NO (see note)
    SOURCE PATCHED:        NO

Read-only corroboration used `asterisk -rx "dialplan show"`,
`"pjsip show endpoints"`, `"core show channels"`, a SIP `OPTIONS` reachability
probe, and `psql` SELECTs. No provider mutation was performed through CLI or ARI.

Note on MANUAL ROLLOUT: no application Deployment was manually restarted. The
single `rollout restart deploy/traefik` was environmental repair of the ingress
after the apiserver pin fix, not an application-image rollout.

## Cleanup

    Outbound Call:   Hang Up attempted through the Calls UI; refused by
                     Defect 4. Call 041e3949 remains `requested` and is
                     retained as evidence of that defect.
    Runtime node:    c6e-generic-proof-20260821 returned to `disabled`
                     through the normal UI control; history retained,
                     nothing hard-deleted.
    Session:         logged out through the normal UI.
    Asterisk:        0 active channels on all three Asterisk Pods.
    RuntimeNodes:    rnp6-readiness-reproof-20260809 active/ready,
                     v0c6-conference-runtime-20260815 active/ready — both
                     retained fixtures preserved.
    Media edge:      `make media-edge-config-check` passes; the NodePort
                     projection was not disturbed.

## Repository Verification

    git diff --check          clean
    make repository-hygiene   passed
    make secret-scan          passed
    make media-edge-config-check  passed

`make asterisk-ari-config-check` was deliberately **not** run or corrected; its
pre-existing stale PJSIP guard failure remains outside this task, and no runtime
failure in this run was attributable to it.

## Code Changes

    NONE.

## Recommended Bounded Corrections

In dependency order. All belong to Codex; none was implemented here.

1. **Project the generic fixtures into RNP-managed Asterisk configuration.**
   This is the smallest correction that unblocks *both* corridors at once and
   needs no NetworkPolicy, Kamailio or C7 change, because pod loopback is exempt
   from NetworkPolicy and was proven working (`OPTIONS` → `200 OK`). A managed
   node then gains:
   * `c6-generic-proof` — Answer + `Stasis(utcp-t0-observation)`, giving the
     inbound corridor a real local SIP source via
     `sip:anonymous/sip:c6-generic-proof@127.0.0.1:5060`;
   * `9900` — Answer + `Echo()`, giving the outbound corridor an answering,
     non-Stasis destination so exactly one Call is created.

2. **Fix Defect 4** — the stranded unbound outbound CallLeg. Either bind the
   channel id at originate time (it is already deterministic and already sent to
   ARI), or add a control-plane origination deadline that writes a terminal
   `call.leg.failed` observation, and allow `call.leg.cancel_origination` to
   execute against an unbound leg.

3. **Fix Blocker 2** — serve the endpoint transport and TLS-mode options from the
   backend catalog instead of the hardcoded frontend list in
   `RuntimeNodesView.vue`.

Item 1 alone is sufficient to retry C6E. Item 2 should land with it, because the
first failed origination during the retry will otherwise leak again.

## C6 Status

    C6A:                   IMPLEMENTED / TESTED / POSTGRESQL PROVEN
    C6B:                   IMPLEMENTED / TESTED
    C6C:                   IMPLEMENTED / TESTED
    C6D:                   IMPLEMENTED / TESTED
    C6E IMPLEMENTATION:    IMPLEMENTED / TESTED
    REFERENCE CALL UI:     IMPLEMENTED / TESTED / PARTIALLY LIVE PROVEN
                           (reachability, capability gating, command dispatch,
                            state-authority discipline and timeline separation
                            all live-confirmed)
    LIVE-PROOF READINESS:  INCOMPLETE — see Blockers 1-3
    C6E LIVE:              FOUND_BLOCKER

    C6:                    NOT LIVE PROVEN

## Recommended Next Step

    BOUNDED CODEX CORRECTION — items 1 and 2 above, then re-run C6E.

T4 must not start. No further C6 audit is required; the corrections are exact.
