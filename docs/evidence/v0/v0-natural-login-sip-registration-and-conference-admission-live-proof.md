# V0 — Natural Login, Browser SIP Registration, and Conference Admission Live Proof

## Verdict

    V0_NATURAL_LIVE_PROOF_FOUND_BLOCKER

The proof is deliberately not collapsed into an undifferentiated failure. Two
independent corridors were assessed:

    natural login → dialer → WSS → SIP REGISTER → runtime corroboration
        → PROVEN END TO END

    browser conference admission
        → NOT IMPLEMENTED in the client under proof; corridor stopped

V0 is therefore blocked **only** on the conference-admission corridor. The
registration corridor is complete and should not be re-proven.

## Method

Evidence-only. No application source was modified. Natural Playwright session
from `https://app.utcp.local.test/login`; bounded break-glass passwords issued
through the canonical `make user-access-reset-password` target and changed
through the application's own forced-change flow. No preset storage, injected
cookie, copied session, database/Redis session, or authentication bypass. SIP
frames were captured from the real browser WebSocket; digest `nonce`,
`cnonce`, `response`, and `opaque` values are redacted throughout.

## Canonical environment

    CONTEXT:            k3d-utcp-local
    KUBECONFIG:         .runtime/kubeconfig/utcp-local.yaml
    API IMAGE:          utcp/api:0.1.0-k1-dev      @sha256:929c749e…
    WEB IMAGE:          utcp/web:0.1.0-k1-dev      @sha256:e4d1ed91…
    GATEWAY IMAGE:      utcp/gateway:0.1.0-k1-dev  @sha256:180ed230…
    KAMAILIO IMAGE:     ghcr.io/kamailio/kamailio  @sha256:2552f809… (5.8.6)
    RTPENGINE IMAGE:    utcp/rtpengine:0.1.0-k1-dev @sha256:1d6b2fd5…
    WORKERS:            kamailio-registration-observer ×2, runtime-fence-worker,
                        telephony-* workers — all on the api digest above
    DEPLOYMENT FRESH:   yes

### Deployment freshness

The last three live sessions began with stale images, so freshness was treated
as a proof precondition rather than an assumption. Verified **current** this
time without any rebuild:

* newest app source mtime `2026-08-09 09:29:42` < image build
  `2026-08-09 09:37:56` (api) / `09:38:06` (web)
* deployed web bundle contains `Reference dialer` and the sip.js `Registerer`
* deployed api contains the `reference-dialer` route

No build, push, apply, or rollout was required, and none was performed.

### Edge preflight

    HTTPRoute sip-wss  host sip.utcp.local.test  path Exact /ws → Service kamailio:8080
    Gateway utcp-local (traefik-system) PROGRAMMED=True, address 172.21.0.3
    TELEPHONY_SIGNALING_WSS_URI not overridden in utcp-application-config,
      so the application default wss://sip.utcp.local.test/ws applies

Configured WSS target and the live HTTPRoute agree exactly. All platform
workloads Ready. No topology change was made.

## Natural login

Two personas were used, for reasons established below.

    LOGIN:  https://app.utcp.local.test/login
    USER:   admin@utcp.local.test (tenant-admin) — could NOT reach /dialer
    USER:   t3-s3b-t3s3b1785716804@utcp.local.test (tenant-member) — the V0 persona
    TENANT: Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)

    tenant-member capabilities returned by the application:
      telephony.conferences.join, telephony.conferences.view,
      telephony.sessions.create_own, telephony.sessions.view_own,
      telephony.signaling.issue_own, telephony.signaling.view_own,
      tenant.memberships.view, tenant.roles.view

### Persona finding — the dialer is a member surface, not an admin surface

The first natural attempt used the local administrator and was redirected to
`/forbidden` ("This route is not available for the current session
capabilities"), with no "Reference dialer" navigation link rendered.

Root cause, from the capability catalog rather than inference:

    router/index.ts:48                     requires telephony.sessions.view_own
    ReferenceDialerController::bootstrap:24 requires telephony.sessions.view_own

    identity.php tenant-admin  → telephony.sessions.manage, signaling.*,
                                 conferences.* … but NOT sessions.view_own
    identity.php tenant-member → telephony.sessions.view_own,
                                 telephony.sessions.create_own, …

So the reference dialer is reachable by `tenant-member` and structurally
unreachable by `tenant-admin`. This is coherent as a design (an administrator
is not an agent) and is **not** counted as a V0 defect, but it is a product
decision worth making explicitly: today a tenant administrator cannot use or
even see the dialer, and the V0 journey requires a member account. Recorded as
an observation, not a blocker.

The proof was then run as the correct persona. No capability, role, or
membership was modified to obtain access.

## STEP 1 — Natural navigation to the dialer

    DIALER ROUTE:   /dialer  (reached by clicking the "Reference dialer" link
                    in the Primary navigation, not by direct URL entry)
    PAGE LOADED:    "Reference dialer — Session-scoped SIP registration through
                    the canonical UTCP WSS path."
    TENANT:         Local Tenant
    USER IDENTITY:  T3 S3B Prover t3s3b1785716804

The navigation entry appeared only for the member persona, consistent with the
capability gating above.

## STEP 2 — Dialer bootstrap

Observed API sequence in the natural session:

    GET  /api/v1/reference-dialer/bootstrap                        → 200
    POST /api/v1/telephony/sessions                                → 201
    POST /api/v1/telephony/sessions/{id}/signaling-credential      → 201

    SIP IDENTITY: ts-f4d1bcd809a541ebbc2748aad1de9afa
    DOMAIN/REALM: sip.utcp.local.test
    WSS TARGET:   wss://sip.utcp.local.test/ws
    TRANSPORT:    WSS
    CONFIG SOURCE: one-time signaling credential issued by
                   SignalingCredentialService (telephony_signaling.wss_uri)
    TELEPHONY SESSION: f4d1bcd8-09a5-41eb-bc27-48aad1de9afa (status active)

The SIP identity is telephony-session-scoped (`ts-<session uuid, dashes
stripped>`), matching the T1 contract. No credential value is recorded here.

## STEP 3 — Browser WSS connection

    WSS URL:            wss://sip.utcp.local.test/ws
    WEBSOCKET OPEN:     yes — frames sent and received on the same socket
    CLOSE CODE:         none during the proof
    BROWSER CONSOLE:    0 errors

The socket opening is recorded as a distinct claim from registration; the
registration claim is established separately below.

## STEP 4 — SIP REGISTER transaction

Captured from the real browser WebSocket, digest values redacted:

    → REGISTER sip:sip.utcp.local.test SIP/2.0
      Via: SIP/2.0/WSS ss8efep0ol49.invalid;branch=z9hG4bK9175134
      To/From: <sip:ts-f4d1bcd809a541ebbc2748aad1de9afa@sip.utcp.local.test>
      CSeq: 2 REGISTER
      Contact: <sip:4tm4evd9@ss8efep0ol49.invalid;transport=ws>;expires=600
      User-Agent: SIP.js/0.21.1

    ← SIP/2.0 401 Unauthorized
      WWW-Authenticate: Digest realm="sip.utcp.local.test", nonce="<redacted>"
      Server: kamailio (5.8.6 (x86_64/linux))
      received=10.42.3.214

    → REGISTER … CSeq: 3 REGISTER
      Authorization: Digest algorithm=MD5,
        username="ts-f4d1bcd809a541ebbc2748aad1de9afa",
        realm="sip.utcp.local.test", nonce="<redacted>",
        uri="sip:sip.utcp.local.test", response="<redacted>"

    ← SIP/2.0 200 OK
      CSeq: 3 REGISTER
      Contact: <sip:4tm4evd9@ss8efep0ol49.invalid;transport=ws>;expires=120
      Server: kamailio (5.8.6 (x86_64/linux))

    REGISTER SENT:          yes (CSeq 2, unauthenticated)
    AUTH CHALLENGE:         401 Unauthorized, Digest MD5, from Kamailio 5.8.6
    AUTHENTICATED REGISTER: yes (CSeq 3, Digest MD5)
    FINAL STATUS:           200 OK
    CONTACT:                sip:4tm4evd9@ss8efep0ol49.invalid;transport=ws
    EXPIRY:                 120 s (client offered 600; registrar reduced to 120)

## STEP 5 — Browser registration state

    UI REGISTRATION STATE:  "REGISTERED — The browser received a successful SIP
                             registration response."
    CLIENT STATE:           RegistererState.Registered
    LAST REGISTER RESULT:   200 OK
    TELEPHONY SESSION:      active
    CONSOLE ERRORS:         0

No frontend state was injected or manipulated.

## STEP 6 — Runtime registration corroboration

The browser 200 was treated as insufficient on its own. Corroborated through
the canonical UTCP authority — the Kamailio registration observation
projection written by `kamailio-registration-observer`:

    CANONICAL AUTHORITY: signaling_registration_observations
    ROW:                 4d44bc70-e626-4eb9-82a8-b10dc52a773f
    TENANT:              a2315712-d650-4d43-8efb-1ac0e3cb356c
    TELEPHONY SESSION:   f4d1bcd8-09a5-41eb-bc27-48aad1de9afa
    IDENTITY:            ts-f4d1bcd809a541ebbc2748aad1de9afa
    DESIRED STATE:       eligible
    OBSERVED STATE:      registered
    CONTACT RUID:        uloc-6a779d4f-10-1
    OBSERVED AT:         2026-08-09 01:59:25+00
    OBSERVED EXPIRES AT: 2026-08-09 02:01:24+00
    LAST EVENT TYPE:     kamailio.registration.accepted
    SOURCE EPOCH:        2ada2c5f6ee71855db8796a1802f3d83
    EVIDENCE ID:         49c4eba90daf0d6c607a430d4a2e3dde
    FAILURE CLASS:       none
    RESULT:              PASS

Correlation is exact on all four required axes: same tenant, same telephony
session, same SIP identity as the wire `From`/`To`, and an expiry consistent
with the observed `200 OK` `expires=120`. This was the **only** row in
`registered` state out of 47 total observation rows, so no stale or ambiguous
registration could have been mistaken for it. Read-only inspection only; no
database write was used.

## STEP 7 — Registration stability

Observed across a full natural refresh cycle at the configured 120 s expiry.
Product configuration was not shortened.

    t0  01:59:25  observed_state=registered  expires 02:01:24
                  last_event_type=kamailio.registration.accepted
    t1  02:01:26  observed_state=registered  expires 02:03:23
                  last_event_type=kamailio.registration.refreshed

    contact_ruid unchanged across the cycle:  uloc-6a779d4f-10-1
    rows for this identity:                   1
    rows in registered state (all tenants):   1
    failure_class:                            none
    UI held "REGISTERED" continuously for 150 s with no failure state

The canonical event type advancing `accepted → refreshed` while the contact
RUID stays fixed proves a genuine re-registration of the same binding rather
than a new one. No registration loop, no rapid reconnect, no authentication
failure cycle, and no duplicate client identity appeared.

Method note: a second frame-capture pass returned zero frames because the
listener was attached after the WebSocket had already opened. That is a
harness limitation, not a product observation, and the canonical projection
above is the stronger evidence for this claim.

## STEP 8 — Conference admission entry point — BLOCKED

    CONFERENCE ACTION:      none exists
    DESTINATION:            n/a
    SOURCE OF DESTINATION:  n/a
    USER INPUT REQUIRED:    n/a

Measured on the rendered dialer page: the entire view contains **0** buttons
other than the global shell's "Menu" and "Log out", and **0** inputs other
than the shell's tenant and appearance selectors. There is no join, call,
dial, or enter-conference control of any kind.

Isolating which of the four possibilities applies:

    implementation absent            → YES for the browser client
    implementation exists, unwired   → YES for the backend admission seam
    UI missing                       → YES
    runtime route missing            → NO

Specifically:

* `apps/web/src/signaling/referenceDialerSignaling.ts` imports only
  `Registerer`, `RegistererState`, and `UserAgent` from sip.js. It contains no
  `Inviter`, no INVITE, no session or dialog handling. The client is a
  registration-only client by construction.
* `apps/web/src/views/ReferenceDialerView.vue` consumes
  `bootstrap.conferences` solely to render a count
  ("Conferences available 2"). Nothing acts on it.
* The backend admission seam **does** exist and is not the gap:
  `POST /api/v1/conferences/{conference}/participants/self`
  → `ConferenceController::joinSelf`
  → `TelephonyDomainService::admitSelf` (requires an active telephony session
  and `telephony.conferences.join`, both of which this persona had).

Per the stop-vs-continue rule, the dependent corridor was stopped here. No
conference participant was created by API, no call was originated from the
Asterisk or Kamailio CLI, no SIP generator was substituted for the browser,
and no database row was injected.

Fixture note, secondary: both existing conferences
(`rnm6-cordon-probe`, `rnm6-drain-work-proof`) are `desired_state=closed`
leftovers from RNM6 proofs, so no open conference exists to join even via the
backend seam. The dialer nonetheless reported "Conferences available 2",
counting closed conferences — a minor presentation issue distinct from the
blocker.

## STEP 9–11 — Browser conference signaling, runtime corroboration, media

    Not attempted. Dependent on STEP 8, which is blocked.

No INVITE was sent because the client cannot send one. Manufacturing
admission through any non-browser path would not have proven the V0 claim, so
none was used. RTPengine was confirmed Running and healthy but no media
session was expected or created.

## STEP 12 — UI conference state

    UI CALL STATE:        none — no call surface exists
    UI CONFERENCE STATE:  "Conferences available 2" (a count only)
    ERRORS:               none

## STEP 13 — Natural exit

The dialer exposes no leave or hang-up control. Natural exit is navigating
away, which triggers `onBeforeUnmount → signalingClient.stop()`.

After leaving the dialer via the Dashboard navigation link:

    observed_state:   expired
    last_event_type:  kamailio.registration.expired
    observed_at:      2026-08-09 02:03:27+00
    rows in registered state: 0
    failure_class:    none

Registration converged cleanly with no leaked binding. Stated precisely: the
binding lapsed at its natural 120 s expiry (02:03:23) marginally before the
navigation, so the canonical evidence shows expiry rather than an explicitly
observed un-REGISTER. Either way the terminal state is correct and no
registration leaked.

The retained RNP managed RuntimeNode
`rnp6-readiness-reproof-20260809` was not touched, and no runtime
infrastructure was retired or deprovisioned.

## Failed proof steps

### V0-BLOCKER-1 — conference admission is not implemented in the client

    CLASSIFICATION:   IMPLEMENTATION
    CLAIM:            The user enters the intended V0 conference from the
                      dialer, over the same natural browser SIP session.
    EXPECTED:         A conference entry control that drives admission, and a
                      browser INVITE (or the repository's established
                      admission mechanism) from the registered session.
    ACTUAL:           No such control exists; the browser client is
                      registration-only.
    BROWSER STATE:    REGISTERED; 0 action controls on the dialer view.
    HTTP STATE:       n/a — no admission request is ever issued by the client.
    WEBSOCKET STATE:  open and healthy; only REGISTER traffic observed.
    SIP STATE:        no INVITE transaction at any point.
    REGISTRATION AUTHORITY: healthy — registered then cleanly expired.
    CONFERENCE STATE: unchanged; no participant or binding created.
    ROOT CAUSE:       The V0 reference dialer implements only the registration
                      half of the V0 slice. `referenceDialerSignaling.ts`
                      instantiates `Registerer` only and has no `Inviter`;
                      `ReferenceDialerView.vue` renders `bootstrap.conferences`
                      as a count with no action bound to it.
    AFFECTED AUTHORITY: V0 reference application (frontend). The canonical
                      telephony admission authority is NOT at fault —
                      `TelephonyDomainService::admitSelf` exists and is routed.
    AFFECTED FILES:   apps/web/src/signaling/referenceDialerSignaling.ts
                      apps/web/src/views/ReferenceDialerView.vue

This matches, and now proves live, the pending gap the roadmap already
recorded for `V0-REF-DIALER-SIP-REGISTER`: "conference admission and full V0
remain incomplete."

### Observations that are not blockers

1. **Tenant administrators cannot reach the dialer.** `tenant-admin` lacks
   `telephony.sessions.view_own`, so `/dialer` returns `/forbidden` and the
   navigation link is hidden. Defensible as role design; needs an explicit
   product decision rather than a silent gap.
2. **"Conferences available" counts closed conferences.** Both conferences in
   the tenant are `closed`, yet the dialer reports 2 available.

## Code changes

    None.

## Environment and topology changes

    None. No rebuild, push, apply, or rollout was needed or performed.

## Improvised or non-canonical actions

    None.

## V0 completion decision

    V0 BLOCKED — conference-admission corridor only.

Explicitly preserved as proven and not to be re-run:

    natural login (forced password change flow)
    natural navigation to /dialer
    dialer bootstrap and telephony-session creation
    one-time signaling credential issuance
    browser WSS connection to the canonical SIP edge
    SIP REGISTER → 401 → authenticated REGISTER → 200 OK
    canonical runtime corroboration (observed_state=registered)
    natural re-registration cycle (accepted → refreshed, stable RUID)
    clean terminal convergence with no leaked registration
