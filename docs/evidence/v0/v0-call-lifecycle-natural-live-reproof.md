# V0 — Narrow Call-Lifecycle Natural Live Reproof

## Verdict

    V0_CALL_LIFECYCLE_NATURAL_LIVE_REPROOF_FOUND_BLOCKER

The credential-renewal half of the packet fails live with a blocking defect that
the focused unit tests cannot see: the renewal timer resolves to zero and the
reference client issues signaling credentials in an unbounded loop. The
DEFECT-31 (failed-establishment) fix is confirmed working, naturally exercised.
The DEFECT-30 (remote-termination) fix could not be exercised live because no
canonical control path produces a SIP BYE toward the browser's reference leg.

V0 does not close in this run.

## Method

Evidence-only. No application source was modified. Natural Playwright session
from `https://app.utcp.local.test/login`; a bounded break-glass password was
issued through the canonical `make user-access-reset-password` target and
changed through the application's own forced-change flow. No preset storage
state, injected cookie, copied session, database/Redis session, or
authentication bypass. SIP frames were captured by wrapping `window.WebSocket`
before the dialer mounted. **No credential secret, digest response, or
Kubernetes Secret value appears in this document or was printed at any point**;
only credential metadata (identifiers and timestamps) was read.

## Repository state

    branch:  main
    HEAD:    943c965540c8647803074096e8f451eb5c01225d
    dirty:   pre-existing large uncommitted RNP/RNM/V0 working tree
    diff --check: clean

Files changed by the packet under proof (all after the previous reproof):

    apps/web/src/signaling/referenceDialerSignaling.ts
    apps/web/src/signaling/referenceDialerSignaling.test.ts
    apps/web/src/views/ReferenceDialerView.vue
    apps/web/src/views/ReferenceDialerView.test.ts
    apps/web/src/api/platform.ts
    apps/api/app/TelephonyDomain/TelephonyDomainService.php

## Canonical environment

    CONTEXT:          k3d-utcp-local  (.runtime/kubeconfig/utcp-local.yaml)
    API IMAGE:        utcp/api:0.1.0-k1-dev        @sha256:eea946c3…
    WEB IMAGE:        utcp/web:0.1.0-k1-dev        @sha256:06032885…
    GATEWAY IMAGE:    utcp/gateway:0.1.0-k1-dev    @sha256:6b8de25e…
    KAMAILIO IMAGE:   ghcr.io/kamailio/kamailio    @sha256:2552f809… (5.8.6)
    RTPENGINE IMAGE:  utcp/rtpengine:0.1.0-k1-dev  @sha256:1d6b2fd5…
    ASTERISK IMAGE:   utcp/asterisk-ari:0.1.0-k1-dev @sha256:52c1e527…
    DEPLOYMENT FRESH: yes

Freshness was established by content, not by tag. Before redeploying, the
running bundle contained none of the new implementation. After the canonical
lifecycle (`k8s-config-check` → `k8s-image-build` → `k8s-image-push` →
`k8s-apply` → rollout restart of every `utcp-platform` Deployment →
`media-edge-config-check` → `media-edge-apply`), the deployed bundle contains
the new string literal `SIP credential renewal failed`, and rtpengine runs with
`--interface=browser/<pod>!127.0.0.1`. No alternative deployment infrastructure
was created.

The apiserver NetworkPolicy drift check passed with no repair needed.

## Natural member login

    LOGIN:  https://app.utcp.local.test/login
    USER:   t3-s3b-t3s3b1785716804@utcp.local.test (tenant-member)
    TENANT: Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: telephony.conferences.join, telephony.conferences.view,
                 telephony.sessions.create_own, telephony.sessions.view_own,
                 telephony.signaling.issue_own, telephony.signaling.view_own,
                 tenant.memberships.view, tenant.roles.view

## Conference fixture

Reused, unchanged, no new fixture created:

    CONFERENCE:   95e4c4e9-0349-4e03-8cc6-b09980cdbee5
                  "V0 Conference Admission Reproof 20260815"
    STATE:        desired open / observed ready, generation 2
    RUNTIME NODE: c7e6f4ba-b925-462f-aff4-71c9fa9a4157
                  "RNP6 Readiness Reproof 20260809" (active / ready)
    BINDING:      7262e0ff-4f4f-4e23-a08a-e1a83962d489, active
    FIXTURE AUTHORITY: created in the previous reproof through
                  POST /api/v1/admin/conferences + .../desired-state

## PROOF A — initial registration — PASS

    /dialer reached by clicking "Reference dialer" in Primary navigation
    TELEPHONY SESSION:      065caa7a-7965-474a-a139-b2efe4a7e173 (active)
    SIP IDENTITY:           ts-065caa7a7965474aa139b2efe4a7e173
    WSS:                    wss://sip.utcp.local.test/ws opened 20:28:51.836Z
    REGISTER RESULT:        CSeq 2 → 401 Digest → CSeq 3 → 200 OK @20:28:51.858Z
    CREDENTIAL ISSUED AT:   2026-08-14 20:28:51Z
    CREDENTIAL EXPIRES AT:  2026-08-14 20:30:51Z  (120 s TTL)
    UI STATE:               REGISTERED, Reference client status Ready,
                            one joinable conference listed

## PROOF B — automatic credential renewal — FAIL (blocking)

The client does obtain replacement credentials automatically, without manual
action, page refresh, remount, second UserAgent, or second telephony session.
It obtains them **continuously and without bound**.

    ORIGINAL CREDENTIAL:    5949d0bb-a215-4104-b44c-7502db980833
      issued_at             2026-08-14 20:28:51Z
      expires_at            2026-08-14 20:30:51Z
    FIRST REPLACEMENT:      f44070ea-3c01-425b-991e-1dc28121fafa
      issued_at             2026-08-14 20:28:51Z   ← same second as the original
      expires_at            2026-08-14 20:30:51Z
    RENEWAL REQUEST:        HTTP 201, repeated at ~23 per second
    RESULT:                 FAIL — expected one renewal at expiry − 30 s
                            (≈20:30:21Z); observed a continuous loop from
                            20:28:51Z onward

Measured amplification, all from a single naturally mounted page:

    credentials issued for this telephony session        7 415
    credentials in the first 149 s of the first mount    3 406  (~23/s)
    control-plane audit records
      `telephony.signaling_credential.issued` (149 s)    3 406
    REGISTER requests sent on the browser socket         5 436
    SIP responses received                               2 718 × 401
                                                         2 718 × 200 OK
    total SIP frames captured in one mount               13 620
    unrevoked credentials at the end                     1

Every issuance revokes the previous credential
(`previous_credential_revoked => true`), so the canonical SIP authority for the
member rotates ~23 times per second while the dialer is open.

The loop stops cleanly when the view is unmounted: navigating to Dashboard
produced 3 further frames and then zero over the following 12 s, confirming
`onBeforeUnmount → stop() → clearCredentialRenewal()` works.

### Root cause — proven, not inferred

Measured directly in the natural browser session:

    POST /api/v1/telephony/sessions/{id}/signaling-credential  → 201
      issued_at   "2026-08-14 20:30:31"      ← no timezone designator
      expires_at  "2026-08-14 20:32:31"      ← no timezone designator

    Date.parse("2026-08-14 20:32:31")  → 1786710751000
                                       = 2026-08-14T12:32:31.000Z
    now                                  2026-08-14T20:30:31.216Z
    browser time zone                    Asia/Manila (UTC+08:00, offset −480)
    Math.max(0, parsed − Date.now() − 30000)  → 0

`SignalingCredentialService::issueOwn()` serialises the one-time credential with
`'issued_at' => (string) $now` and `'expires_at' => (string) $expiresAt`. Carbon's
default string cast produces a naive `Y-m-d H:i:s` value with no offset. V8
parses a space-separated, non-ISO datetime as **local time**, so on a UTC+8 host
the value resolves eight hours in the past. In
`referenceDialerSignaling.ts::scheduleCredentialRenewal()`:

    const expiresAt = Date.parse(this.credential.expires_at)
    const delay = Math.max(0, expiresAt - Date.now() - 30_000)   // → 0
    this.renewalTimer = setTimeout(() => void this.renewSignalingCredential(), delay)

`renewSignalingCredential()` succeeds, stores the new credential, and calls
`scheduleCredentialRenewal()` again, which computes zero again — an unbounded
immediate loop. `renewalInFlight` only prevents *concurrent* renewals, not
sequential ones.

Note the asymmetry that hid this: the **read** endpoint
`GET /api/v1/telephony/sessions/{id}/signaling-credential` returns the value
straight from PostgreSQL as `"2026-08-14 20:31:30+00"`, which `Date.parse`
handles correctly (measured: delay 89 103 ms). Only the **POST** one-time
response is timezone-naive, and only the POST response feeds the renewal timer.
The focused unit tests supply their own credential fixtures and a fake timer, so
neither the serialisation format nor the host timezone is exercised.

    CLASSIFICATION:   IMPLEMENTATION (blocking)
    AFFECTED FILES:   apps/api/app/TelephonyDomain/Signaling/SignalingCredentialService.php
                        (issueOwn — naive `(string)` datetime serialisation)
                      apps/web/src/signaling/referenceDialerSignaling.ts
                        (scheduleCredentialRenewal — no minimum delay floor and
                         no guard against a non-advancing schedule)

## PROOF C — registration continuity — FAIL (consequence of PROOF B)

    REGISTER BEFORE RENEWAL:  CSeq 2 → 401, CSeq 3 → 200 OK
    REGISTER AFTER RENEWAL:   CSeq 4, 6, 8, 10 … 5 436 requests, each renewal
                              driving a fresh 401 + authenticated REGISTER pair
    SAME TELEPHONY SESSION:   yes — 065caa7a-… throughout, no second session
    SAME USER AGENT:          yes — one UserAgent, one Registerer, one WSS socket
    CANONICAL REGISTRATION:   `signaling_registration_observations` held a single
                              row for this identity; contact RUID uloc-6a7f7a0b-10-1
    DUPLICATE BINDINGS:       none — the defect is churn, not duplication
    UI STATE:                 REGISTERED throughout
    RESULT:                   FAIL — renewal reuses the correct client objects,
                              but at a rate that makes the registration authority
                              churn continuously

## PROOF D — cross the old 120-second cliff — NOT ASSESSABLE

The original credential's expiry (20:30:51Z) was crossed while the page stayed
mounted, and the UI still reported REGISTERED with a valid current credential.
That is the intended outcome, but it is not evidence for the fix: by 20:30:51 the
original credential had already been superseded roughly 2 800 times, so the
120-second cliff was never approached in the way the design intends. Recorded as
not assessable rather than as a pass.

## PROOF E — join after original credential expiry — FAIL (natural, informative)

Attempted at 20:32:29Z, after the original expiry.

    ADMISSION:       POST /api/v1/conferences/95e4c4e9-…/participants/self → 201
                     participant 7abc5015-195e-4191-af2a-2b60607d141d
    INVITE:          → INVITE sip:9900@sip.utcp.local.test   CSeq 1 @20:32:30.618Z
    AUTH CHALLENGE:  ← 401 Unauthorized  CSeq 1
                     → ACK
                     → INVITE (authenticated)               CSeq 2 @20:32:30.622Z
    FINAL SIP:       ← 401 Unauthorized  CSeq 2  → ACK; no dialog
    SESSION STATE:   never Established
    UI STATE:        "Needs attention" — "Conference unavailable: The conference
                     call could not be established."
    RESULT:          FAIL to establish — the credential the UserAgent held had
                     already been revoked by the renewal loop between the
                     configuration update and the INVITE

This is a direct consequence of PROOF B, not a separate defect: the loop rotates
and revokes the SIP authority faster than a dialog can be set up, so INVITE
authentication became a race. A second attempt made 2.5 minutes later, clicking
Join immediately after REGISTERED, did reach Established and Connected at
20:35:02Z — confirming the corridor itself is intact and the failure mode is
timing, not routing.

### The same run is the live proof of the DEFECT-31 fix — PASS

The failure above was natural, not manufactured, and it exercised precisely the
handler the packet added:

    no indefinite "Joining…"        confirmed — the view left `joining` immediately
    no false Connected              confirmed
    participant compensated         confirmed — 7abc5015 canonically `removed`
                                    with no manual action
    "Needs attention" visible       confirmed, with the conference-unavailable alert
    retry possible                  NOT SATISFIED — see PRODUCT_DEFECT-33

## PROOF F — runtime-initiated termination — PROOF GAP

No Kubernetes Pod was killed, no Deployment scaled, no runtime resource deleted,
no SIP BYE injected, no Asterisk CLI used, no database state written, and no
browser application state modified.

The preferred canonical operation was used: the member's own established
control-plane authority for ending the active telephony session and its
conference participation.

    TRIGGER:          POST /api/v1/telephony/sessions/065caa7a-…/end
                      issued at 20:35:29.019Z from the naturally authenticated
                      member session → HTTP 200, status "ended",
                      termination_reason "user_ended"
    CANONICAL EFFECT: participant 4b5617c3-… → desired_state `removed`
                      signaling credentials revoked
                      registration observation → `pending_removal`
    REMOTE BYE:       none — 0 BYE frames at +12 s and at +37 s
    BROWSER RESPONSE: n/a
    SESSION STATE:    still Established

Nothing in UTCP sends a BYE toward the browser's reference leg. This is the
direct consequence of the two-disjoint-legs architecture recorded in the previous
reproof: `signaling_destination` is the constant `sip:9900@{realm}`, an
`Answer(); Echo(); Hangup()` fixture on the SIP-facing Asterisk, while the
conference bridge and the participant channel are a separately
control-plane-originated Local channel on an ARI-only RuntimeNode. Removing the
participant or ending the session therefore cannot reach the browser's dialog.
Asterisk's own RTP-inactivity policy did not fire either, because media was
flowing correctly over the restored media edge.

    RESULT:  PROOF_GAP — the SIP-terminated convergence path that
             PRODUCT_DEFECT-30's fix targets could not be exercised by any
             canonical control path in the current architecture.

## PROOF G — remote-termination convergence — FAIL for the control-plane path

    UI FINAL:        "Connected", conference name, Leave button — unchanged
                     37 s after the canonical session end
    PARTICIPANT:     canonically `removed` at 20:35:29Z
    BINDING:         conference binding unchanged (conference still open/ready)
    DIALOG:          still Established; browser unaware
    REGISTRATION:    correctly changed — see below

The registration half of the view did converge truthfully: once renewal failed
against the ended session the panel changed to "SIP registration failed / API
request failed", so the UI did **not** remain falsely REGISTERED. That satisfies
the registration-truth-on-failure requirement, and it was exercised naturally
rather than injected.

The call half did not converge. Stated precisely: this is **not** a
contradiction of the DEFECT-30 fix, which keys on SIP `Terminated`; no SIP event
occurred. It is a distinct gap — a control-plane-initiated termination has no
path to the reference client at all. Closing it is the RT-1 problem
(realtime control-plane notifications), which this task explicitly places out of
scope.

Cleanup afterwards was performed with the natural Leave control at 20:36:40.831Z:
the browser sent BYE at 20:36:40.847Z, and the canonical
`DELETE /participants/self` returned "Participant not found." because the
session-end had already released the participant. The client surfaced that as
"Needs attention — Participant not found." instead of converging to Ready.

## Participant cleanup / idempotence

    RELEASE REQUEST COUNT:  one canonical release per conference attempt in every
                            observed case — no duplicate frontend release was seen
    REMOVE OPERATIONS:      0 — the reconciler observed both participant channels
                            already absent and recorded evidence directly, so no
                            `conference.participant.remove` operation was needed
    FINAL RECONCILIATION:   both targets `converged` at 20:41:58Z
                            (7abc5015 attempt 15, 4b5617c3 attempt 13)
    PARTICIPANT FINAL:      both `removed` / `left`, left_at 20:41:58Z
    ADMITTED PARTICIPANTS REMAINING: 0
    RESULT:                 PASS — the frontend single-flight `cleanupPromise`
                            guard held, exactly one logical release occurred per
                            attempt, and the historical duplicate-remove
                            observation did not recur. Convergence was delayed
                            only by the reconciler restart described below, and
                            completed automatically once it recovered.

## Failed proof steps

### PRODUCT_DEFECT-32 — unbounded signaling-credential renewal loop

    CLASSIFICATION:      IMPLEMENTATION (blocking)
    CLAIM:               a replacement credential is issued automatically shortly
                         before the current one expires
    EXPECTED:            one renewal at expires_at − 30 s (≈90 s after issue)
    ACTUAL:              renewal fires immediately and repeats at ~23 per second
                         for as long as /dialer is mounted
    BROWSER STATE:       REGISTERED; one UserAgent, one Registerer, one WSS socket
    HTTP STATE:          POST …/signaling-credential 201, 7 415 times for one
                         telephony session
    SIP STATE:           5 436 REGISTER requests; 2 718 × 401 and 2 718 × 200 OK;
                         13 620 frames in one mount
    CREDENTIAL STATE:    each issuance revokes its predecessor; 1 unrevoked at rest
    REGISTRATION STATE:  single row, single contact RUID, no duplicate binding,
                         but continuously churning
    PARTICIPANT STATE:   unaffected directly; indirectly caused the PROOF E
                         INVITE authentication failure
    RUNTIME STATE:       Kamailio served every challenge; no runtime crash
    ROOT CAUSE:          timezone-naive `expires_at` in the one-time credential
                         response, parsed as local time by `Date.parse`, yielding a
                         zero renewal delay that reschedules itself unchanged
    AFFECTED FILES:      apps/api/app/TelephonyDomain/Signaling/SignalingCredentialService.php
                         apps/web/src/signaling/referenceDialerSignaling.ts

### PRODUCT_DEFECT-33 — no retry after a failed establishment

    CLASSIFICATION:  IMPLEMENTATION
    CLAIM:           after a failed establishment, retry is possible
    EXPECTED:        the member can press Join again
    ACTUAL:          the Join control is natively `disabled` and stays disabled;
                     measured `disabled: true` two minutes after the failure, with
                     no path back to `ready` short of leaving and re-entering
                     /dialer
    ROOT CAUSE:      `finalizeConferenceSession('failed')` sets
                     `conferenceState = 'attention'`, and the template binds
                     `:disabled="conferenceState !== 'ready'"` on the Join button.
                     Nothing returns the view to `ready` after a failure.
    AFFECTED FILE:   apps/web/src/views/ReferenceDialerView.vue

### OBSERVATION-3 — an externally released participant makes Leave report an error

    CLASSIFICATION:  IMPLEMENTATION (minor, downstream)
    OBSERVED:        after the canonical session end had already released the
                     participant, the natural Leave click sent BYE correctly but
                     `DELETE /participants/self` returned "Participant not found.",
                     and the view went to "Needs attention" instead of Ready.
    EXPECTED:        an already-released participant is an idempotent success for
                     the client's own leave path.
    AFFECTED FILE:   apps/web/src/views/ReferenceDialerView.vue (finalizeConferenceSession)

### PROOF_GAP-1 — no canonical path produces a runtime BYE to the reference leg

    CLASSIFICATION:  AUTHORITY / architecture
    EFFECT:          PRODUCT_DEFECT-30's fix cannot be exercised live today. It
                     remains covered by the focused automated tests
                     (`releases admission and returns Ready when the runtime
                     terminates an established session`, and
                     `reports remote termination after an established dialog
                     without requiring local leave`).
    NOT A REGRESSION: the fix was not contradicted; it was never reached.

## Failed-INVITE handler

Naturally exercised — see PROOF E. Live evidence: participant admitted then
canonically compensated to `removed`, no indefinite "Joining…", no false
Connected, "Needs attention" presented. The one expected property that failed is
retry, recorded as PRODUCT_DEFECT-33.

## Registration truth on failure

Naturally exercised — renewal failure was not injected. After the canonical
session end at 20:35:29Z the renewal attempt failed and the view changed from
REGISTERED to "SIP registration failed / API request failed". The UI did not
remain falsely REGISTERED.

    Registerer state:            registration lost; client reported failure
    UI state:                    "SIP registration failed"
    Error presentation:          visible alert, not silent
    Canonical registration:      pending_removal → unregistered; 0 registered rows

## Secret exposure review

No signaling credential secret, digest `Authorization` response material, or
Kubernetes Secret value was displayed, logged, or written to evidence. Credential
inspection returned metadata only (`secretPresent: true` as a boolean, plus
identifiers and timestamps). The rendered UI exposes no credential material.
`make secret-scan` passed.

## Product boundary

`/dialer` still contains only reference-client concerns: registration status,
telephony-session status, conference discovery, Join, Connected, Leave. No dialer
product functionality was introduced.

## RT-1 status

    PLANNED
    NOT IMPLEMENTED

Reverb was not implemented and was not used to compensate for reference-client
signaling state. PROOF G shows why RT-1 is the right next milestone once V0
closes: a control-plane-initiated termination currently has no path to the client.

## Pre-existing repository issues

1. **Pint.** `apps/api/tests/Feature/RuntimeProvisioning/ManagedRuntimeDeprovisioningOperationTest.php`
   still fails `pint --test` (`unary_operator_spaces`, `statement_indentation`,
   `not_operator_with_successor_space`). Untouched. Not a V0 failure.
2. **telephony-reconciler CrashLoopBackOff.** The reconciler exits with
   `RuntimeException: runtime reconciliation fencing token was superseded`
   (`app/RuntimeEngine/Reconciliation/ReconciliationWorker.php:78`) within
   milliseconds of start, and is in a 5-minute backoff. `ReconciliationWorker.php`
   is **not** modified by this packet, and the first crash preceded the credential
   loop, so neither is attributable to the V0 packet. **It self-healed**: after
   restart 7 the Pod became Running/Ready at 20:40:48Z and immediately converged
   the outstanding work, taking both participants to `removed` / `left` at
   20:41:58Z. Classified ENVIRONMENT (transient rollout/lease contention) with a
   pre-existing C3 robustness question worth a separate narrow diagnosis: a
   superseded fencing token is a normal concurrency outcome and arguably should
   skip the claim rather than terminate the worker process. It did not invalidate
   any claim in this proof.

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
    Telephony session 065caa7a-… ended (user_ended) through the canonical API.
    0 admitted participants; 0 registered signaling rows.
    7 415 credential rows and 3 406+ audit rows for session 065caa7a-… are
    retained as the evidence of PRODUCT_DEFECT-32 and were not deleted.
    The browser session was logged out.

## V0 completion decision

    V0 REMAINS OPEN — blocked on the signaling-credential renewal lifecycle.

Closed and not to be re-proven: the happy-path admission corridor, the
failed-establishment compensation handler, and registration truth on failure.
