# V0-C5 Closure and V0-C6 Canonical Browser Conference-Leg Live Proof

## Verdict

    V0_C5_C6_CANONICAL_BROWSER_CONFERENCE_LEG_LIVE_PROOF_FOUND_BLOCKER

V0-C5 authority separation is **COMPLETE**. V0-C1, V0-C2 and V0-C3 are
**live-proven**. V0-C6 stopped at the runtime entry: every canonical conference
INVITE is rejected `403 Forbidden` by Asterisk because the committed dialplan
pattern `_conf-.` can never match a `conf-…` extension — in Asterisk pattern
matching `N` is the digit class `[2-9]`, and patterns are uppercased, so
`_conf-.` is evaluated as `_CONF-.` and requires a digit at the third position.

One bounded implementation defect, one file, one line.

## Method

Evidence-only live proof. No application source was modified. Deployment and
migration were executed through canonical repository targets only. Natural
Playwright login from the real login page; no preset storage, injected cookie,
copied session, database/Redis session, or authentication bypass. No credential
secret, digest material, or Kubernetes Secret value appears below.

## Repository state

    branch:        main
    HEAD:          943c965540c8647803074096e8f451eb5c01225d
    dirty:         pre-existing working tree, now including the C1–C4 packet
    diff --check:  clean
    commit/push:   none requested, none created, not pushed

C1–C4 artefacts confirmed present before deployment:

    apps/api/app/RuntimeAdapters/Asterisk/AsteriskConferenceParticipantBinder.php
    apps/api/database/migrations/2026_08_15_130000_add_sip_runtime_endpoint_catalog_values.php
    apps/api/database/migrations/2026_08_15_140000_add_runtime_channel_id_to_conference_participants.php
    apps/api/database/migrations/2026_08_15_141000_create_kamailio_conference_route_view.php
    runtime_registry.endpoint_purposes = [control, events, health, sip]
    runtime_registry.endpoint_transports = [http, https, tcp, tls, udp, ws, wss]
    TelephonyDomainService: 'sip:conf-'.$participantId.'@'.$realm
    Kamailio: sqlops.so, CONFERENCE_RUNTIME_RELAY, kamailio_conference_route_view
    Asterisk: [from-kamailio] exten => _conf-.

## Canonical environment

    CONTEXT:          k3d-utcp-local  (.runtime/kubeconfig/utcp-local.yaml)
    API IMAGE:        utcp/api:0.1.0-k1-dev        @sha256:e21aa2d5…
    WEB IMAGE:        utcp/web:0.1.0-k1-dev        @sha256:c5546ef5…
    GATEWAY IMAGE:    utcp/gateway:0.1.0-k1-dev    @sha256:47a40344…
    KAMAILIO IMAGE:   ghcr.io/kamailio/kamailio 5.8.6-bookworm
    RTPENGINE IMAGE:  utcp/rtpengine:0.1.0-k1-dev  @sha256:18095129…
    ASTERISK IMAGE:   utcp/asterisk-ari:0.1.0-k1-dev @sha256:af5e9cf8…
    DEPLOYMENT FRESH: yes

Lifecycle executed, canonical targets only: `k8s-config-check`,
`runtime-registry-config-check`, `kamailio-signaling-config-check`,
`telephony-domain-config-check`, `k8s-image-build`, `k8s-image-push`,
`k8s-apply`, rollout restart of every `utcp-platform` Deployment plus
`utcp-runtime/asterisk-ari`, `media-edge-apply`, `security-config-check`,
`security-apply`. No cluster, namespace, registry, or manual patch was created.

### Deployment content proof

    API      AsteriskConferenceParticipantBinder.php present;
             4 runtime_channel_id references; self_admission cutoff present;
             'sip:conf-' destination present
    KAMAILIO CONFERENCE_RUNTIME_RELAY ×2, kamailio_conference_route_view ×1,
             sqlops ×2 in the rendered /tmp/kamailio.cfg
    ASTERISK _conf-. at extensions.conf:14 on both the base and managed nodes
    RUNTIME  managed Service publishes 8088/TCP and 5060/UDP

## Migration result

    MIGRATION RESULT:       job utcp-migrate Complete 1/1 in 7 s
      2026_08_15_130000_add_sip_runtime_endpoint_catalog_values   DONE
      2026_08_15_140000_add_runtime_channel_id_to_conference_participants  DONE
      2026_08_15_141000_create_kamailio_conference_route_view     DONE
    RUNTIME_CHANNEL_COLUMN: conference_participants.runtime_channel_id,
                            character varying, nullable YES
    ROUTING VIEW:           kamailio_conference_route_view present
    KAMAILIO VIEW GRANT:    utcp_kamailio_auth_reader → SELECT (only)

## V0-C5 — authority separation — COMPLETE

Verified against the **deployed** `/tmp/kamailio.cfg`, not only the repository:

    dispatch:   if ($rU =~ "^conf-") { route(CONFERENCE_RUNTIME_RELAY); }
                else                 { route(APPLICATION_RUNTIME_RELAY); }

    9900 ROUTE:            APPLICATION_RUNTIME_RELAY → static
                           application-runtime-sip → existing T3 Echo fixture
    conf-* ROUTE:          CONFERENCE_RUNTIME_RELAY → SQL projection →
                           bound RuntimeNode sip endpoint
    STATIC FALLBACK:       none — grep for APPLICATION_RUNTIME_RELAY or
                           application-runtime-sip inside the deployed
                           CONFERENCE_RUNTIME_RELAY block returns 0 matches;
                           every failure branch replies 400/403/404/503 and exits
    PROJECTION LEAKAGE:    none — grep for kamailio_conference_route_view or
                           sql_query inside APPLICATION_RUNTIME_RELAY returns
                           0 matches
    RESULT:                COMPLETE

Live corroboration: during the proof the base node received **0** `conf-` dialogs
while the managed node received the conference INVITE, confirming the separation
in traffic and not only in configuration.

## Natural member login

    USER:        t3-s3b-t3s3b1785716804@utcp.local.test (tenant-member)
    TENANT:      Local Tenant (a2315712-d650-4d43-8efb-1ac0e3cb356c)
    PERMISSIONS: telephony.conferences.join, telephony.conferences.view,
                 telephony.sessions.create_own, telephony.sessions.view_own,
                 telephony.signaling.issue_own, telephony.signaling.view_own,
                 tenant.memberships.view, tenant.roles.view

## Conference fixture — V0-C1 live-proven

The retained fixture's node `c7e6f4ba` predates C1 and has 0 `sip` endpoints, so
it was **not** reused for the conference. A new managed RuntimeNode was obtained
through the canonical Admin API — `POST /api/v1/admin/runtime-provisioning` with
an Idempotency-Key, from a natural tenant-administrator browser session. No
endpoint row, Service port, or Asterisk runtime was created by hand.

    RUNTIME NODE:  d4539d79-432d-48dc-8def-d52e0d0ca5e2
                   "V0C6 Conference Runtime 20260815"
                   requested 01:03:2x → active/ready by 01:03:50 (≈25 s)
    SIP ENDPOINT:  purpose sip, transport udp,
                   host asterisk-v0c6-conference-runtime-20260815-5ce1a2de
                        .utcp-runtime.svc.cluster.local, port 5060, enabled
                   (alongside control/events/health on 8088)
    SERVICE:       ClusterIP 10.43.183.233, ports 8088/TCP and 5060/UDP —
                   no NodePort, no LoadBalancer, no host port
    CONFERENCE:    68c7d252-2203-4f2a-9b81-4d87d1294768
                   "V0C6 Conference Leg Proof 20260815", open / ready
    BINDING:       1d9da35b-b825-4716-868b-6b27f4b760e4, active, on d4539d79
    FIXTURE AUTHORITY: POST /api/v1/admin/runtime-provisioning,
                   POST /api/v1/admin/conferences,
                   POST …/runtime-binding, POST …/desired-state

The pre-existing `95e4c4e9` fixture was closed through the canonical
desired-state API so exactly one joinable conference was presented.

**V0-C1 is live-proven**: RNP provisioning wrote the SIP container port, the SIP
Service port, and the canonical `sip` endpoint automatically, with no operator
step.

## Registration

    TELEPHONY SESSION:  e0f5e994-25ca-4510-b8de-7bb572cca764
    SIP IDENTITY:       ts-e0f5e99425ca4510b8de7bb572cca764
    WSS:                wss://sip.utcp.local.test/ws opened 01:05:03.117Z
    REGISTER:           CSeq 2 → 401 Digest → CSeq 3 → 200 OK @01:05:03.137Z
    UI:                 REGISTERED, Ready, exactly one joinable conference

## Admission — V0-C2 live-proven

    PARTICIPANT:            f11ce90b-cba5-4ece-a594-2f553c3461d0
    SIGNALING DESTINATION:  sip:conf-f11ce90b-cba5-4ece-a594-2f553c3461d0
                            @sip.utcp.local.test
    PARTICIPANT/URI MATCH:  exact — the URI user part is `conf-` + the returned
                            participant id
    CONFERENCE:             68c7d252-… bound to d4539d79-…
    LEAKAGE:                the response carries no RuntimeNode Service DNS,
                            pod IP, or internal SIP target

**V0-C2 is live-proven.** The fixed `sip:9900@…` destination no longer appears in
conference admission.

## SIP INVITE and Kamailio routing — V0-C3 live-proven

Captured from the real browser WebSocket:

    → INVITE sip:conf-f11ce90b-…@sip.utcp.local.test   CSeq 1 @01:07:24.236Z
    ← 401 Unauthorized                                  CSeq 1
    → ACK                                               CSeq 1
    → INVITE with Authorization username="ts-e0f5e99425ca4510b8de7bb572cca764"
                                                        CSeq 2 @01:07:24.239Z
    ← 100 trying                                        CSeq 2 @01:07:24.258Z
    ← 403 Forbidden  Server: Asterisk PBX 20.20.1       CSeq 2 @01:07:24.387Z
    → ACK

    REQUEST URI:                    sip:conf-<participantId>@<realm> — not 9900
    AUTHENTICATED SIP IDENTITY:     ts-e0f5e99425ca4510b8de7bb572cca764
    STATIC APPLICATION RUNTIME USED: no

The `403` carries `Server: Asterisk PBX 20.20.1`, so it was generated by the
**runtime**, not by Kamailio. That is decisive for the routing claim: every
failure branch inside `CONFERENCE_RUNTIME_RELAY` logs a
`kamailio_conference_route result=…` line and replies **without relaying**, and no
such line was emitted. A successful relay therefore proves, transitively:

* the admission user-part passed the UUID format guard;
* `sql_query` against `kamailio_conference_route_view` returned **exactly one** row;
* the row's `signaling_identity` equalled the authenticated `$au`;
* `$du` was set from the row's `sip_target` and `t_relay()` succeeded.

The target is proven correct by traffic: the managed node
`asterisk-v0c6-conference-runtime-20260815-5ce1a2de` logged the INVITE at
01:07:24 while the base `asterisk-ari` node logged **0** `conf-` dialogs.

**V0-C3 is live-proven**: the canonical projection resolved the conference's bound
RuntimeNode and Kamailio relayed to its internal SIP endpoint, with no static
fallback.

## V0-C4 — BLOCKED at the runtime dialplan entry

    ASTERISK RUNTIME NODE:  d4539d79-… (the conference's bound node)
    CHANNEL ID:             none — no channel was created
    STASIS ENTRY:           none
    PARTICIPANT RUNTIME_CHANNEL_ID: null
    BRIDGE MEMBERSHIP:      none
    SYNTHETIC LOCAL CHANNEL: none (correct — the self_admission cutoff works)

Asterisk logged only:

    ast_sip_requires_authentication: No SIP authenticator registered.
                                     Assuming authentication is not required
    ast_find_ourip: Unable to get hostname

and replied 403.

### Root cause — proven by direct dialplan resolution

The `_conf-.` extension **is loaded**:

    [ Context 'from-kamailio' created by 'pbx_config' ]
      '_conf-.' =>  1. NoOp(...)  2. Answer()  3. Stasis(utcp-t0-observation,${EXTEN})  4. Hangup()
      '_.'      =>  1. NoOp(... rejected ...)  2. Hangup(21)
      -= 2 extensions (6 priorities) in 1 context. =-

but it never matches. Asterisk's own resolver was asked directly:

    dialplan show conf-f11ce90b-cba5-4ece-a594-2f553c3461d0@from-kamailio  → '_.'
    dialplan show conftest@from-kamailio                                   → '_.'
    dialplan show conf-test@from-kamailio                                  → '_.'
    dialplan show confX@from-kamailio                                      → '_.'

Every `conf…` form resolves to the catch-all `_.` → `Hangup(21)`
(CALL_REJECTED), which PJSIP maps to `403 Forbidden` — exactly the response the
browser received.

The cause is Asterisk pattern semantics: extension patterns are matched
case-insensitively with `N`, `X`, `Z` as digit classes, so `_conf-.` is evaluated
as `_CONF-.`, in which **`N` is the class `[2-9]`**. The pattern therefore
requires a digit at the third character and can never match the literal text
`conf…`. The `-` is additionally ignored inside patterns. This is
node-independent: the identical result was reproduced on the base
`asterisk-ari` node, and all Asterisk config files are byte-identical between the
two nodes apart from `ari.conf` credentials and the base node's extra
`extensions.local.conf` T3 overlay.

    CLASSIFICATION:   IMPLEMENTATION (blocking)
    CLAIM:            the browser's inbound PJSIP channel enters Stasis and is
                      bound to the admitted participant
    EXPECTED:         conf-<uuid> matches the conference admission extension
    ACTUAL:           it matches the reject catch-all and is answered 403
    BROWSER:          Needs attention — "The conference call could not be
                      established"; participant compensated to removed/left
    HTTP:             participants/self 201 (correct)
    SIP:              INVITE → 401 → auth INVITE → 100 → 403 Forbidden (Asterisk)
    KAMAILIO ROUTE:   correct — projection resolved and relayed to the bound node
    RUNTIME NODE:     d4539d79-… , correct node reached
    CHANNEL:          none created
    PARTICIPANT:      f11ce90b-… released, runtime_channel_id null
    BRIDGE:           utcp-conf-68c7d252-… , no members
    ROOT CAUSE:       Asterisk pattern `_conf-.` is evaluated as `_CONF-.` where
                      `N` is the digit class [2-9], so it cannot match `conf…`
    AFFECTED FILE:    infrastructure/docker/asterisk/config/extensions.conf
                      (`[from-kamailio] exten => _conf-.`, line 14)

Not patched, per the task instruction.

### Bounded fix shape (not applied)

The pattern must stop relying on class letters. Options the next packet should
choose between on repository evidence: escape the class characters
(`_[c]o[n]f-.`), or avoid pattern matching entirely by routing the admission on a
literal extension and passing the participant identifier another way. The
acceptance test is exactly the command used above —
`dialplan show conf-<uuid>@from-kamailio` must resolve to the conference
extension, not to `_.`.

## Runtime observation

Not reachable — no channel, no `StasisStart`, no `ChannelEnteredBridge`. The ARI
listener lease on the new node was healthy and claimed
(`asterisk-ari-events`, status `claimed`, lease valid), and the Stasis app
`utcp-t0-observation` was registered at 01:03:48, so the observation path was
ready and is not implicated.

## Media path

    RTPENGINE:        offer/answer mediation performed (media_offer, then
                      media_delete on failure)
    RUNTIME CHANNEL:  none
    BRIDGE:           none
    9900/ECHO INVOLVED: no — the browser never routed to 9900
    RESULT:           not reachable; blocked by the dialplan defect

The key negative is nonetheless established: media did **not** terminate at the
9900 Echo fixture.

## UI

    SESSION STATE:  never Established
    UI STATE:       "Needs attention — The conference call could not be
                    established"; participant compensated; Join retryable
    CONFERENCE:     V0C6 Conference Leg Proof 20260815

The reference-client failure handling behaved correctly under a genuine natural
failure.

## Natural leave

Not reachable — the dialog never established.

## Cleanup idempotence

    PARTICIPANT RELEASE REQUEST COUNT: one per attempt
    REMOVE OPERATION COUNT:            0 — no runtime channel existed to remove
    PARTICIPANT FINAL:                 both attempts removed / left
    ADMITTED PARTICIPANTS REMAINING:   0
    RECONCILIATION:                    no storm; no synthetic channel created

## Environment finding — not a V0 defect

The live `allow-asterisk-sip-from-kamailio` NetworkPolicy still carried the
pre-C1 `utcp.dev/runtime-node: local-asterisk-ari` pin, so it did not select the
managed node and the first conference INVITE never reached Asterisk
(`application_runtime_unavailable`). The corrected selector was already in the
repository; NetworkPolicies are applied by the separate `make security-apply`
lifecycle, which had not been run. After running it the policy selects all three
Asterisk pods and the INVITE reached the runtime. Classified DEPLOYMENT, resolved
through the canonical target.

`make security-apply` then exited non-zero at its final Gateway step with
`missing required K2 tool: helm`. The NetworkPolicies had already been applied
successfully before that point. Classified ENVIRONMENT (missing local tooling);
unrelated to V0.

## Secret exposure review

No signaling credential secret, digest `Authorization` response material, or
Kubernetes Secret value was displayed or written. The admission response exposed
no RuntimeNode Service DNS, pod IP, or internal SIP target — the internal target
was used only server-side by Kamailio.

## Failed proof steps

1. **V0-C4 dialplan pattern defect** — recorded above, blocking.
2. **DEPLOYMENT: NetworkPolicy not applied** — resolved during the proof through
   `make security-apply`.
3. **ENVIRONMENT: helm missing** — `make security-apply` final Gateway step;
   unrelated.

## Code changes

    None.

## Retained state

    RuntimeNode d4539d79-… "V0C6 Conference Runtime 20260815" — active/ready with
      the canonical sip endpoint; retained as V0-C1 evidence and as the fixture
      for the reproof.
    Conference 68c7d252-… "V0C6 Conference Leg Proof 20260815" — open/ready on
      that node; retained as the reproof fixture.
    Conference 95e4c4e9-… closed through the canonical API.
    RuntimeNode c7e6f4ba-… (RNP6 retained fixture) untouched.
    0 admitted participants; 36 pods Running.

## Status

    V0-C5  COMPLETE
    V0-C1  LIVE-PROVEN
    V0-C2  LIVE-PROVEN
    V0-C3  LIVE-PROVEN
    V0-C4  BLOCKED — one bounded dialplan defect
    V0-C6  BLOCKED on V0-C4
    V0     IN PROGRESS
