# V0 — Narrow Credential Renewal and Recovery Natural Live Reproof

## Verdict

    V0_CREDENTIAL_RENEWAL_AND_RECOVERY_NATURAL_LIVE_REPROOF_PASSED

PRODUCT_DEFECT-32 is closed and live-proven. The reference client now issues
exactly one replacement credential per renewal window, holds a truthful
REGISTERED state across the old 120-second cliff, and joins a conference
successfully more than a minute after the original credential expired.
PRODUCT_DEFECT-33 and OBSERVATION-3 were not naturally exercised because the
failure conditions that used to trigger them no longer occur; both remain
covered by the focused automated tests.

V0 is **not** closed by this task. V0-A remains pending.

## Method

Evidence-only. No application source was modified. Natural Playwright session
from `https://app.utcp.local.test/login`; a bounded break-glass password was
issued through the canonical `make user-access-reset-password` target and
changed through the application's own forced-change flow. No preset storage
state, injected cookie, copied session, database/Redis session, or
authentication bypass. SIP frames were captured by wrapping `window.WebSocket`,
and credential-request timing by wrapping `window.fetch`, both installed before
the dialer mounted — recording metadata only. **No credential secret, digest
`Authorization` material, or Kubernetes Secret value was displayed or written to
evidence.** The whole renewal corridor was observed on one continuously mounted
`/dialer` with no refresh and no remount.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing large uncommitted RNP/RNM/V0 working tree
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

Files changed by the packet under proof:

    apps/api/app/TelephonyDomain/Signaling/SignalingCredentialService.php
    apps/web/src/signaling/referenceDialerSignaling.ts
    apps/web/src/signaling/referenceDialerSignaling.test.ts
    apps/web/src/views/ReferenceDialerView.vue
    apps/web/src/views/ReferenceDialerView.test.ts
    apps/web/src/api/platform.ts

## Canonical environment

    CONTEXT:          k3d-utcp-local  (.runtime/kubeconfig/utcp-local.yaml)
    API IMAGE:        utcp/api:0.1.0-k1-dev        @sha256:2bc56e21…
    WEB IMAGE:        utcp/web:0.1.0-k1-dev        @sha256:c6491953…
    GATEWAY IMAGE:    utcp/gateway:0.1.0-k1-dev    @sha256:180ed230…
    KAMAILIO IMAGE:   ghcr.io/kamailio/kamailio    @sha256:2552f809… (5.8.6)
    RTPENGINE IMAGE:  utcp/rtpengine:0.1.0-k1-dev  @sha256:1d6b2fd5…
    DEPLOYMENT FRESH: yes

The packet had not been deployed: the running API still carried
`'expires_at' => (string) $expiresAt`. The cluster was brought to the working
tree through the canonical lifecycle only —

    make k8s-config-check → k8s-image-build → k8s-image-push → k8s-apply
    → kubectl rollout restart of every utcp-platform Deployment (same-tag)
    → make media-edge-config-check → make media-edge-apply

— and freshness was then confirmed by content, not by tag:

* deployed API contains `'issued_at' => $now->toISOString()` and
  `'expires_at' => $expiresAt->toISOString()`
* deployed web bundle contains both new guard literals,
  `expiry is invalid or too close to renew safely` and
  `credential expiry did not advance`
* rtpengine runs `--interface=browser/<pod>!127.0.0.1`

No alternate cluster, namespace, registry, port, or deployment path was used.
The apiserver NetworkPolicy drift check passed with no repair needed.

## Natural member login

    USER:        t3-s3b-t3s3b1785716804@utcp.local.test (tenant-member)
    TENANT:      Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: telephony.conferences.join, telephony.conferences.view,
                 telephony.sessions.create_own, telephony.sessions.view_own,
                 telephony.signaling.issue_own, telephony.signaling.view_own,
                 tenant.memberships.view, tenant.roles.view

## Conference fixture

Reused unchanged; nothing created, no SQL written.

    CONFERENCE:   95e4c4e9-0349-4e03-8cc6-b09980cdbee5
                  "V0 Conference Admission Reproof 20260815"
    STATE:        desired open / observed ready
    RUNTIME NODE: c7e6f4ba-b925-462f-aff4-71c9fa9a4157
                  "RNP6 Readiness Reproof 20260809" (active / ready)
    BINDING:      7262e0ff-4f4f-4e23-a08a-e1a83962d489, active
    AUTHORITY:    created in an earlier reproof through
                  POST /api/v1/admin/conferences + .../desired-state

## PROOF A — initial credential and registration — PASS

Credential metadata as returned to the real browser (no secret shown):

    CREDENTIAL ID: 45117a28-e344-4940-9538-9ae0edfe3a9c
    ISSUED_AT:     2026-08-14T21:30:53.942110Z
    EXPIRES_AT:    2026-08-14T21:32:53.942110Z
    TTL:           120 s
    TIMESTAMP FORMAT VALID: yes — explicit UTC ISO-8601 with `Z`

The decisive check, measured in the browser rather than assumed:

    Date.parse("2026-08-14T21:32:53.942110Z") = 1786743173942
                                              = 2026-08-14T21:32:53.942Z
    browser time zone                         = Asia/Manila (UTC+08:00)

The host timezone is unchanged from the run that found PRODUCT_DEFECT-32, and
the parsed instant is now correct rather than eight hours in the past.

    TELEPHONY SESSION: 0814ed59-e538-4063-b46d-8faeba96265a
    SIP IDENTITY:      ts-0814ed59e5384063b46d8faeba96265a
    WSS:               wss://sip.utcp.local.test/ws opened 21:30:53.992Z
    REGISTER RESULT:   CSeq 2 → 401 Digest → CSeq 3 → 200 OK @21:30:54.013Z
    UI STATE:          REGISTERED, Reference client status Ready

## PROOF B — renewal scheduling — PASS

    INITIAL ISSUE:            2026-08-14T21:30:53.942Z
    INITIAL EXPIRY:           2026-08-14T21:32:53.942Z
    SAFETY WINDOW:            30 s (RENEWAL_SAFETY_WINDOW_MS)
    EXPECTED RENEWAL WINDOW:  ≈2026-08-14T21:32:23.942Z
    ACTUAL RENEWAL:            2026-08-14T21:32:24.018Z
    DELAY FROM ISSUE:          90.032 s
    DEVIATION FROM PREDICTED:  76 ms

Quiescence was measured before the window, not merely assumed:

    t+18 s  (21:31:11.871Z)  credential calls 1, REGISTERs 2, SIP frames 5
    t+78 s  (21:32:11.933Z)  credential calls 1, REGISTERs 2, SIP frames 5

Nothing happened for 78 seconds on a mounted page. There was no immediate
renewal, no POST storm, and no zero-delay recursive scheduling.

## PROOF C — exactly one bounded renewal — PASS

    OLD CREDENTIAL ID: 45117a28-e344-4940-9538-9ae0edfe3a9c
    OLD EXPIRES:       2026-08-14T21:32:53.942110Z
    NEW CREDENTIAL ID: 4eef0193-7e1d-4981-942a-3981d6d82aa7
    NEW ISSUED:        2026-08-14T21:32:24.008342Z
    NEW EXPIRES:       2026-08-14T21:34:24.008342Z
    EXPIRY ADVANCED:   yes, by 90 s
    SAME TELEPHONY SESSION: yes — 0814ed59-… throughout
    SAME SIGNALING CLIENT:  yes — one WSS socket, one UserAgent, one Registerer;
                            no second socket was ever opened

Request accounting at that point:

    CREDENTIAL ISSUE REQUEST COUNT: 2
    REGISTER REQUEST COUNT:         4   (2 initial + one 401/authenticated pair)
    SIP FRAME COUNT:                9

The REGISTER pair is the expected SIP.js digest challenge sequence for the
re-registration that follows a credential swap, not churn.

## PROOF D — no credential or REGISTER storm — PASS

Full cadence over one continuously mounted page, 271 seconds:

    #  requested at              issued_at                    expires_at
    1  21:30:53.986Z (mount)     2026-08-14T21:30:53.942110Z  21:32:53.942110Z
    2  21:32:24.018Z (+90.032s)  2026-08-14T21:32:24.008342Z  21:34:24.008342Z
    3  21:33:54.129Z (+90.111s)  2026-08-14T21:33:54.095033Z  21:35:54.095033Z
    4  21:35:24.203Z (+90.074s)  2026-08-14T21:35:24.182543Z  21:37:24.182543Z

    CREDENTIALS ISSUED DURING WINDOW: 4 in 271 s (one per 90 s)
    REGISTER REQUESTS DURING WINDOW:  8
    SIP FRAME COUNT:                  26
    NEXT EXPECTED RENEWAL:            2026-08-14T21:36:54Z (a future timer)
    RESULT:                           PASS

Every replacement's expiry strictly advances, and each renewal schedules the
next one in the future rather than executing immediately. For contrast, the run
that found the defect recorded **3 406 credentials in 149 s (~23/s), 5 436
REGISTER requests and 13 620 SIP frames** from the same page. Canonical audit
confirms the new figure independently: exactly **4**
`telephony.signaling_credential.issued` records for this session.

On natural unmount the timer is cleared: credential calls froze at 4 and frames
settled at 29 (the teardown un-REGISTER) with no further activity over the
following 8 s.

## PROOF E — cross the original 120-second expiry — PASS

Same mounted page throughout; no refresh, no remount.

    ORIGINAL EXPIRES:      2026-08-14T21:32:53.942Z
    TIME CROSSED:          observed at 2026-08-14T21:33:49.196Z (+55 s past)
    CURRENT CREDENTIAL:    issued 21:32:24.008Z, expires 21:34:24.008Z, valid
    CANONICAL REGISTRATION: observed_state `registered`,
                            last_event_type `kamailio.registration.refreshed`,
                            contact RUID uloc-6a7f8890-f-1
    REGISTERED ROW COUNT:  1 (across all tenants) — no duplicate binding
    UI STATE:              REGISTERED, Ready, Join enabled
    RESULT:                PASS

## PROOF F — post-expiry conference join — PASS

The decisive regression proof. Joined 71 seconds after the original credential
had expired, from the same browser page and session.

    JOIN TIME:          2026-08-14T21:34:04.461Z
    ADMISSION HTTP:     POST /api/v1/conferences/95e4c4e9-…/participants/self → 201
                        participant 67d30af9-4be2-485a-b94d-7396bf56c7f7
    INVITE REQUEST URI: sip:9900@sip.utcp.local.test
      → INVITE            CSeq 1 @21:34:04.676Z
      ← 401 Unauthorized  CSeq 1 @21:34:04.677Z
      → ACK               CSeq 1 @21:34:04.681Z
      → INVITE with Authorization  CSeq 2 @21:34:04.682Z
      ← 100 trying        CSeq 2 @21:34:04.703Z
      ← 200 OK            CSeq 2 @21:34:04.722Z
      → ACK sip:10.42.1.33:5060   CSeq 2 @21:34:04.730Z
    AUTH RESULT:        succeeded on the renewed signaling authority
    FINAL SIP STATUS:   200 OK
    SESSION STATE:      SessionState.Established
    UI STATE:           Connected, conference name shown, Leave available
    RESULT:             PASS — the 120-second authentication cliff is eliminated

No media benchmarking was repeated; no regression appeared that would warrant it.

## PROOF G — credential and registration authority continuity — PASS

Canonical read-only corroboration during the connected call:

    TELEPHONY SESSION:        0814ed59-e538-4063-b46d-8faeba96265a (one)
    CREDENTIAL ROWS:          3 at that moment, 4 by the end of the session
    ACTIVE CREDENTIAL COUNT:  1 unrevoked (c53ca06f-83b9-4555-b201-824512ae00e4
                              at the time of the check) — each predecessor was
                              revoked exactly when its replacement was issued
    CANONICAL REGISTRATION ROWS: 1 for this identity; 1 `registered` row across
                              all tenants
    CONTACT/RUID:             uloc-6a7f8890-f-1, stable across every renewal
    RESULT:                   PASS — one session, one logical signaling client,
                              one current usable credential, one binding

## PROOF H — retry behavior — NOT NATURALLY EXERCISED

No establishment failure occurred during this proof: every INVITE authenticated
and reached Established on the first attempt. Consistent with the stop rule, no
signaling or environment failure was manufactured to force one.

    NOT NATURALLY EXERCISED.
    Covered by focused automated tests.

The relevant repository coverage is
`apps/web/src/views/ReferenceDialerView.test.ts` —
`compensates admitted participation when INVITE establishment fails` and
`coalesces repeated terminal callbacks into one canonical participant release` —
plus `apps/web/src/signaling/referenceDialerSignaling.test.ts` —
`reports INVITE failure without presenting a connected dialog` and
`reports a terminal SIP failure when the INVITE reaches Terminated before
Established`.

Static corroboration recorded without exercising it: the Join control is now
bound `:disabled="conferenceState !== 'ready' && conferenceState !== 'attention'"`,
so the permanently-disabled dead end of PRODUCT_DEFECT-33 is structurally
removed, and `join()` increments `conferenceAttempt` while `updateCallState`
discards callbacks whose `attemptId` does not match the current attempt.

## PROOF I — already-released cleanup — NOT NATURALLY EXERCISED

The participant was never released externally during this proof, so the typed
404 path was not reached. It was not manufactured.

    NOT NATURALLY EXERCISED.
    Covered by focused automated tests.

Static corroboration: `platform.ts::leaveConference` now catches
`ApiRequestError` with `status === 404` and returns `{ participant: null }`, so
`finalizeConferenceSession` treats an already-released participant as converged
and returns the view to Ready. Noted for V0-A: the guard keys on the HTTP status
rather than a typed domain error code.

## Normal leave regression — PASS

    LEAVE CLICKED:   2026-08-14T21:34:35.902Z
    BYE:             → BYE sip:10.42.1.33:5060 @21:34:35.908Z
                     ← 200 OK @21:34:35.923Z
    UI FINAL STATE:  Ready, available-conference list restored, still REGISTERED
    PARTICIPANT:     removed / left @21:34:46Z

No regression. Full media and runtime corroboration were intentionally not
repeated.

## Cleanup idempotence — PASS

    PARTICIPANT RELEASE REQUESTS: 1 — `participants/self` resource-timing count
                                  went 1 (the POST admission) → 2 (POST + exactly
                                  one DELETE) across the whole attempt
    REMOVE OPERATION COUNT:       1 — `conference.participant.remove`, succeeded,
                                  1 attempt (alongside 1 `…ensure`, succeeded,
                                  1 attempt)
    FINAL PARTICIPANT:            removed / left
    RECONCILIATION:               converged @21:36:49Z
    ADMITTED PARTICIPANTS REMAINING: 0

One logical conference attempt produced exactly one logical frontend cleanup
execution. The historical duplicate-remove observation did not recur.

## Registration truth

Compared at every stage; the UI never claimed more than the signaling authority
supported.

    mount → renewal ×3 → post-expiry join → connected → leave
      UI:        REGISTERED throughout
      Registerer: registered; re-registered on each credential swap
      canonical:  observed_state `registered`, event advancing
                  accepted → refreshed, RUID unchanged
    natural unmount and logout
      UI:        page left
      canonical:  `unregistered` / `kamailio.registration.removed` @21:35:59Z,
                  0 registered rows — clean convergence, no leaked binding

## Secret exposure review

No signaling credential secret, digest `Authorization` response material, or
Kubernetes Secret value was displayed, logged, or written to evidence. The fetch
observer recorded only `issued_at`, `expires_at`, HTTP status, and a boolean
`hasSecret`. The rendered UI exposes no credential material. `make secret-scan`
passed.

## PROOF_GAP-1 status

    UNRESOLVED BY DESIGN.
    Reserved for V0-A.

No control-plane session-end trigger was exercised, no Reverb, no polling, no
new hangup endpoint, no direct Asterisk action, and no browser-force-close
workaround was introduced.

## RT-1 status

    PLANNED
    NOT IMPLEMENTED

## Pre-existing repository issue

    apps/api/tests/Feature/RuntimeProvisioning/ManagedRuntimeDeprovisioningOperationTest.php
    still fails `pint --test` (unary_operator_spaces, statement_indentation,
    not_operator_with_successor_space). Untouched. Not a V0 failure; `make check`
    is therefore not claimed green.

The `telephony-reconciler` fencing-token CrashLoopBackOff recorded in the
previous reproof did not recur in this run.

## Observation for V0-A (not a defect at current configuration)

`scheduleCredentialRenewal()` now fails registration outright when
`delay <= 0`, i.e. whenever a credential's remaining lifetime is at or below the
30-second safety window. At the configured 120-second TTL this is correct and
was proven so. It does couple the client to the server TTL: a TTL of 30 seconds
or less would put the client into a permanent failed state at mount. Worth
recording as an invariant for the V0-A audit rather than acting on now.

## Failed proof steps

    None.

## Code changes

    None.

## Environment and topology changes

    None. No cluster, registry, namespace, host port, load balancer, Kubernetes
    context, node, persistent volume, deployment mechanism, or parallel runtime
    was created, replaced, or removed. Images were rebuilt and Deployments rolled
    through canonical targets only; `make media-edge-apply` restored the canonical
    two-interface rtpengine projection that `make k8s-apply` reverts.

## Improvised or non-canonical actions

    None.

## Retained state

    Conference 95e4c4e9-… left open / ready as the standing V0 fixture.
    RuntimeNode c7e6f4ba-… untouched, active / ready.
    Telephony session 0814ed59-… remains active with its normal 30-minute
    lifetime; its registration is `unregistered` and it holds no participant.
    4 credential rows and 4 audit records retained as the evidence above.
    0 admitted participants; 0 registered signaling rows; 35 pods Running.
    The browser session was logged out.

## V0 status

    V0 registration corridor            COMPLETE
    V0 conference happy path            LIVE PROVEN
    V0 credential renewal               NATURAL LIVE PROVEN
    V0 recovery fixes                   ACCEPTED
    V0-A lifecycle authority audit      PENDING
    V0 overall                          IN PROGRESS
