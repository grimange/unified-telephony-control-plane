# V1-A — Registration-Based External Trunk Authority Audit

Narrow evidence / architecture authority audit. Read-only. No repository code,
cluster, external PBX, credential, or router state was modified.

Date: 2026-08-25
Branch: `main`
HEAD: `234d8ae`
Verdict: `V1_REGISTRATION_EXTERNAL_TRUNK_AUTHORITY_IDENTIFIED`

Question answered:

> How should UTCP model, project, execute, observe, and lifecycle-manage a
> registration-based external SIP trunk — while preserving static/IP-authenticated
> trunks as a coexisting mode under the same ExternalTrunk authority — and what
> single bounded Codex implementation should follow?

---

## 1. Authoritative decision output

```text
Registration desired state owner:
  C7A — trunk_endpoints (new signaling_mode + registration attributes)
  under the existing ExternalTrunk aggregate. No new canonical domain.

Registration execution owner:
  Kamailio, via the uac module's uac_reg remote-registration client.

Registration observed-state owner:
  New external_trunk_registration_observations table, written only by a
  fenced C3 event-source observer that mirrors KamailioRegistrationObserver.

Credential authority:
  TrunkCredentialReference (unchanged).

Inbound correlation:
  uac_reg local registration uuid (l_uuid = trunk_endpoint_id)
  -> canonical external_trunk_id
  -> existing kamailio_external_trunk_route_view (direction = 'inbound')
  -> C7B InboundRoute. Never remote-supplied SIP headers.

Outbound execution:
  C7B RouteDecision selects the ExternalTrunk; T6 projects it; Kamailio
  executes the selected trunk over its registered flow. The registration
  executor never selects a trunk.

Static mode coexistence:
  signaling_mode (static | outbound_registration) is orthogonal to the
  existing authentication_mode (none | credentials) on the same
  trunk_endpoints row.

C7B change:
  No. Proven: C7bService touches external_trunks exactly once (existence and
  state check, C7bService.php:272) and never reads trunk_endpoints,
  authentication_mode, or credentials. RouteDecision carries trunkId only.

T6 change:
  Yes. The provider-neutral artifact must carry signaling_mode,
  registration_target, registration_realm, and the already-present
  credential_reference_id / credential_version. No plaintext.
```

---

## 2. Current C7A canonical model — what already exists

From `2026_08_24_120000_create_c7a_external_connectivity_authority.php`:

`trunk_endpoints`:

| Column | Note |
| --- | --- |
| `endpoint_uri` | remote target |
| `transport` | `udp` default |
| `authentication_mode` | **already orthogonal**; app-validated `none\|credentials` |
| `credential_reference_id` | nullable FK to `trunk_credential_references` |
| `capabilities`, `desired_state`, `priority` | — |

`external_trunks.desired_state` CHECK: `draft, validating, active, draining,
disabled, retired`. `observed_health` CHECK: `unknown, ready, degraded,
unavailable`. `configuration_version` drives T6 `desired_generation`.

**Authentication is already independent of reachability.** What is missing is
any expression of *reachability/signaling mode* — nothing in C7A distinguishes
"we are reachable at a fixed address" from "we register outbound to the peer".

Outcome: **B — small canonical extension required**, confined to
`trunk_endpoints`. No new canonical domain (`RegisteredTrunk`, `StaticTrunk`,
`CarrierRegistration`) is needed or justified.

---

## 3. Existing registration machinery — classified

`apps/api/app/TelephonyDomain/Signaling/` and
`Reconciliation/SignalingRegistrationReconciler.php` implement registration, but
of the **opposite direction**:

- `SignalingRegistrationReconciler` reads `signaling_registration_observations`
  joined to `telephony_sessions` and `telephony_signaling_credentials`.
- `KamailioRegistrationEventNormalizer` emits
  `kamailio.registration.{accepted,refreshed,replaced,removed,expired}`.

This is Kamailio acting as **registrar** for T1 browser SIP-over-WSS sessions —
inbound REGISTER *received*. ADR-019 states it directly: Kamailio "is the sole
registrar: actual REGISTER authentication, current Contact binding, replacement,
explicit deregistration, and runtime expiration are Kamailio/native-`usrloc`
authority."

It is **not** an outbound REGISTER client and must not be overloaded into one.
Its value here is as a **proven architectural template**: fenced C3 event source
→ snapshot poll → differ → normalized receipts → observations table →
reconciler, with no manual control.

---

## 4. REGISTER execution owner — Kamailio

### Capability is already in the deployed image

`utcp-kamailio` currently loads `usrloc.so` and `registrar.so` (server side) and
**does not** load `uac.so`. But `uac.so` is present in the image, and Kamailio's
remote-registration client lives inside the `uac` module (there is no separate
`uac_reg.so`). Verified in the running Pod:

```text
modparams: reg_db_url reg_contact_addr reg_timer_interval reg_retry_interval
           reg_active reg_keep_callid reg_random_delay reg_gc_interval
table:     uacreg
columns:   l_uuid auth_username auth_password auth_ha1 auth_proxy registrar
functions: uac_reg_lookup uac_reg_status uac_reg_request_to uac_reg_refresh
           uac_reg_enable uac_reg_disable
RPC:       uac.reg_reload uac.reg_refresh uac.reg_info uac.reg_dump
           uac.reg_enable uac.reg_disable uac.reg_unregister uac.reg_active
           uac.reg_add uac.reg_remove
```

**No image rebuild is required.** Activation is `loadmodule "uac.so"` plus
modparams and a `uacreg`-shaped sanitized view.

### Why Kamailio and not Asterisk

1. **The Contact decides the inbound path.** A successful REGISTER tells the
   provider where to send inbound INVITEs. Kamailio already owns inbound
   external-trunk correlation — `kamailio-configmap.yaml:231` queries
   `kamailio_external_trunk_route_view ... direction = 'inbound'`. If Asterisk
   registered, inbound INVITEs would land directly on an Asterisk RuntimeNode,
   bypassing that correlation and C7B route authority. That is a second inbound
   authority, which `CLAUDE.md` prohibits.
2. **Trunks are RuntimeNode-neutral.** An ExternalTrunk is tenant-owned. Binding
   its registration to one Asterisk node would couple canonical connectivity to
   a runtime placement decision and risk duplicate registration across nodes —
   incompatible with K5 placement neutrality.
3. **Asterisk has no production apply path for trunks.** The file-backed PJSIP
   representation in `AsteriskExternalTrunkProjection` (82 lines, renders
   `endpoint` + `aor` + optional `auth`, and **no** `type=registration`) is
   materialised only by the proof harness itself —
   `scripts/asterisk-external-trunk/runtime-proof` `write_config()` writes
   `pjsip.conf` inline. Making Asterisk the executor would require building an
   entire file-apply/reload lifecycle that does not exist.
4. **ADR-019 directs it.** Kamailio is "pinned shared platform infrastructure"
   and the single SIP edge; "future … external-trunk phases (T2, T3, T6) must
   integrate through these same runtime-neutral boundaries."

FreeSWITCH gateway registration is **not** an established authority in this
repository and is out of scope for this slice.

A dedicated registration adapter is **not** warranted: no existing architecture
implies one, and it would duplicate the SIP edge.

### The one new surface this requires

`uac_reg` caches the `uacreg` table in memory (no `reg_db_mode` in this build),
so DB changes need `uac.reg_reload`, and observation needs `uac.reg_info`.
Kamailio currently exposes **no RPC control surface** — `jsonrpcs.so` and
`ctl.so` are not loaded, and the existing `xhttp` listener on `8080` serves only
the health endpoint and the browser WebSocket upgrade
(`kamailio-configmap.yaml:382-414`).

This is the first Kamailio consumer that needs cache invalidation rather than
query-time reads. The bounded resolution is a **dedicated internal JSON-RPC
listener**, separate from the browser-facing 8080 edge, ClusterIP-only, and
NetworkPolicy-restricted to the API/worker. It must never be exposed through
Traefik, the Gateway, or the serverlb.

---

## 5. Desired state — smallest canonical addition

On `trunk_endpoints`:

| Column | Purpose |
| --- | --- |
| `signaling_mode` | `static` (default) \| `outbound_registration`. CHECK-constrained. |
| `registration_target` | registrar URI when registering; null for static. |
| `registration_realm` | provider digest realm; needed to precompute HA1. |
| `registration_identity` | the AOR user UTCP registers as. |

Deliberately **excluded** from canonical state: expiry, refresh interval, retry
backoff, keepalive, NAT knobs. These are adapter-owned deterministic defaults
(`reg_timer_interval`, `reg_retry_interval`, `reg_random_delay`) — `CLAUDE.md`
prohibits operator-facing knobs without a documented requirement.

Validation rules:

- `signaling_mode = outbound_registration` requires
  `authentication_mode = credentials`, a bound `credential_reference_id`, and a
  `registration_target`.
- `signaling_mode = static` must leave all registration attributes null.
- Registration attributes are rejected on static endpoints rather than ignored.

Supported combinations for V1:

| Trunk | signaling_mode | authentication_mode | Supported |
| --- | --- | --- | --- |
| A | `outbound_registration` | `credentials` | Yes (V1-A) |
| B | `static` | `none` (source-IP) | Yes (V1-B) |
| C | `static` | `credentials` | Yes |
| D | `static` | source-IP **+** digest | **Not in V1** — `authentication_mode` is single-valued; combining both requires a separate change and no evidence demands it now |

---

## 6. Credential authority and the HA1 constraint

Rotation already satisfies the required invariant with **no new mechanism**
(`C7aService.php:161-176`): inside one transaction it inserts the next version,
rebinds every affected `trunk_endpoints.credential_reference_id`, retires the
prior credential, calls `bumpTrunk()` (which increments
`configuration_version` → T6 `desired_generation`), and emits
`external_trunk.credential_rotated`. The T6 artifact already carries
`credential_reference_id` and `credential_version`.

So: rotate → canonical mutation → reprojection → registration view rows change.
The only added step is the automatic `uac.reg_reload` + `uac.reg_refresh`,
which the provider lifecycle must orchestrate — never an operator action.

**Constraint that must be honoured:** `uac_reg` accepts `auth_password`
(plaintext) or `auth_ha1`. `trunk_credential_references.encrypted_secret` is
Laravel-encrypted application-side and a PostgreSQL view cannot decrypt it.
ADR-019's precedent is explicit — `kamailio_signaling_auth_view` exposes only
`username`/`domain`/`ha1`, never plaintext.

Therefore the credential service must compute and store an **HA1 verifier**
(over `registration_identity : registration_realm : secret`) at bind and rotate
time, and the registration view must expose `auth_ha1` only, leaving
`auth_password` empty. If `registration_realm` is unknown, validation must
**fail closed** rather than silently fall back to plaintext.

---

## 7. Observed state

New `external_trunk_registration_observations`, written only by the observer:

```text
not_configured | registering | registered | failed | expired | disabled
```

Metadata: `last_attempt_at`, `last_success_at`, `expires_at`,
`failure_category`, `observation_version`, and a `contact_fingerprint` hash —
following the existing rule that raw Contact strings are never persisted past
snapshot time.

Never persisted: nonce, Authorization response, realm challenge material,
plaintext secrets.

Failure normalization is deliberately coarse — `auth_rejected` (401/403 after
challenge), `not_found` (404), `timeout` (408/no response), `unreachable`
(DNS/transport), `expired`. That is the minimum needed for readiness, operator
visibility, reconciliation, retry, and audit. No exhaustive SIP taxonomy.

Observed state is runtime-derived and must not be editable through Admin APIs.

---

## 8. Lifecycle

Checked against the existing `external_trunks` CHECK constraint and the
`retired` irreversibility guards in `C7aService.php:139-140, 218-219`.

| Desired state | Registration behavior |
| --- | --- |
| `draft` | no registration; not projected |
| `validating` | no registration; configuration validated only |
| `active` | registration active and refreshing |
| `draining` | registration **retained** (existing/inbound calls keep working); no new outbound eligibility |
| `disabled` | `uac.reg_unregister` + stop refresh; no calls |
| `retired` | registration removed permanently; irreversible, consistent with the existing no-reactivation guard |

---

## 9. Readiness / eligibility

Registration must gate eligibility **only** for registration-mode trunks:

```text
signaling_mode = outbound_registration
  eligible  <=>  desired_state = active AND observed registration = registered

signaling_mode = static
  eligible  <=>  desired_state = active          (registration state irrelevant)
```

`observed_health` maps: `registered` → `ready`; `registering` → `degraded`;
`failed`/`expired` → `unavailable`. Static trunks keep their current semantics
unchanged.

---

## 10. Inbound correlation

```text
provider INVITE -> registered Contact (Kamailio edge)
  -> uac_reg_lookup recovers l_uuid  (= trunk_endpoint_id, chosen by UTCP)
  -> canonical external_trunk_id
  -> kamailio_external_trunk_route_view (direction = 'inbound', dialed $rU)
  -> TelephonyAddress -> C7B InboundRoute -> DestinationRef -> Call / CallLeg
```

Correlation comes from UTCP's own registration identity, not from remote-supplied
headers. Registration establishes **reachability only** — C7B remains the sole
route authority.

Honest limitation: the current `EXTERNAL_TRUNK_ROUTE` block terminates at
`sl_send_reply("200", "External Trunk Route Matched")`
(`kamailio-configmap.yaml:243-244`). It proves correlation, **not** call
delivery. Relaying a correlated inbound external call into a canonical Call is
pending V1 work that is independent of connectivity mode and is not part of this
packet.

---

## 11. Outbound execution

```text
Call -> C7B RouteDecision (selects ExternalTrunk) -> T6 artifact
  -> Kamailio executes the selected trunk over its registered flow
     (uac_reg_request_to supplies the registered identity/credentials)
```

The registration executor never selects a trunk and never overrides C7B.

---

## 12. T6 projection change

`external_trunk_projection_artifacts` (`utcp.t6.projection.v1`) already emits per
endpoint: `endpoint_id`, `uri`, `transport`, `authentication_mode`,
`credential_reference_id`, `credential_version`
(`ExternalTrunkProjectionService.php:143-153`).

Add provider-neutral fields only: `signaling_mode`, `registration_target`,
`registration_realm`, `registration_identity`. No secrets, no HA1 in the
artifact, no provider-local names.

Provider rendering below T6: a new sanitized
`kamailio_external_trunk_registration_view` shaped to `uacreg`
(`l_uuid`, `auth_username`, `auth_ha1`, `auth_proxy`, `registrar`, `realm`,
`flags`), granted to its own least-privilege role — exactly the
`kamailio_signaling_auth_view` / `kamailio_conference_route_view` pattern. It
must be a **separate** view; registration data must not be mixed into
`kamailio_external_trunk_route_view`, which serves an unrelated concern.

The Asterisk provider projection is **unchanged** — no `type=registration`, no
ARA/realtime, no Asterisk-specific Admin CRUD.

---

## 13. NAT / Contact handling

Owned entirely at the signaling/provider layer via `reg_contact_addr`,
`rport`/`received` (`nathelper.so` is already loaded), and registration refresh.
No NAT concept enters the canonical domain.

**Explicitly unproven:** a successful REGISTER does not by itself guarantee the
provider can later push a new INVITE through the residential NAT mapping.
Whether V1-A avoids router UDP/5060 forwarding must be established empirically in
the later live proof, not assumed here. If inbound still requires forwarding,
that must be reported honestly rather than reshaping the architecture to protect
the preferred theory.

---

## 14. External PBX preparation delta — determined, NOT applied

For `38.146.161.46`, registration-mode operation would additionally require the
AOR to accept a dynamic contact. Current prepared state has `MaxContact = 0`,
correct for static preparation. Conceptually needed later: a non-zero
`max_contacts` on `utcp-v1-aor`, `remove_existing` to avoid stale contacts, and
`rewrite_contact` on the `utcp-v1` endpoint so the NATed source is honoured.

Exact values are deliberately **not** prescribed and **not** applied — the
prompt requires UTCP registration authority to be proven first.

---

## 15. V1-B static edge preservation

Verified intact and unmodified:

- `infrastructure/k3d/cluster.yaml:21` — `0.0.0.0:5060:30560/udp`
- `infrastructure/kubernetes/base/platform/kamailio-sip-external-service.yaml:22` — `nodePort: 30560`
- `infrastructure/kubernetes/security/platform/allow-kamailio-signaling.yaml` — `38.146.161.46/32`
- `scripts/k3d/verify`, `scripts/v1/external-sip-edge-config-check` contracts

Not activated, not reverted, not marked obsolete.

```text
V1-A registration-based    -> no intentional public inbound SIP edge requirement
V1-B static/IP-authenticated -> requires the reachable external SIP edge above
```

---

## 16. Bounded Codex implementation packet

**Canonical / C7A**
- Migration: add `signaling_mode` (CHECK `static|outbound_registration`, default
  `static`), `registration_target`, `registration_realm`,
  `registration_identity` to `trunk_endpoints`; add HA1 verifier column to
  `trunk_credential_references`.
- `C7aService`: validation rules from §5; compute HA1 on credential create and
  rotate; fail closed when realm is absent.
- `AdminC7aController::endpointRules()`: extend the existing ExternalTrunk
  surface. **No** `/api/v1/admin/registrations` resource.

**T6**
- `ExternalTrunkProjectionService`: add the four provider-neutral fields.
- New migration: `kamailio_external_trunk_registration_view` + least-privilege
  role grant.

**Kamailio**
- `loadmodule "uac.so"` + `reg_db_url` (registration view), `reg_contact_addr`,
  `reg_timer_interval`, `reg_retry_interval`, `reg_random_delay`.
- Dedicated internal JSON-RPC listener (`jsonrpcs.so`), ClusterIP-only,
  NetworkPolicy-restricted, separate from the browser 8080 edge.
- `uac_reg_lookup` correlation in the inbound external-trunk path.

**Observation**
- `external_trunk_registration_observations` migration.
- `KamailioTrunkRegistrationObserver` + normalizer + differ, mirroring
  `KamailioRegistrationObserver`, polling `uac.reg_info`, emitting
  `kamailio.trunk_registration.*` receipts.
- `ExternalTrunkRegistrationReconciler` converging desired vs observed and
  mapping `observed_health`.

**Lifecycle / rotation**
- Provider lifecycle automatically issues `uac.reg_reload` + `uac.reg_refresh`
  on reprojection; `uac.reg_unregister` on `disabled`/`retired`.

**Tests** (the 16 enumerated in the prompt, no generic SIP suite)

**Proof**: synthetic runtime proof in the existing
`scripts/kamailio-signaling/` style. Live external-PBX proof is deferred.

**Explicitly excluded**: the V1-B static edge activation packet, credential
rotation, PBX changes, router configuration.

---

## 17. Conclusion

- Verdict: `V1_REGISTRATION_EXTERNAL_TRUNK_AUTHORITY_IDENTIFIED`
- Next AI coder: **Codex**
- V1 status: `V1_REMAINS_ACTIVE`

Deferred live-proof prerequisites, not blockers for repository implementation:
SIP credential rotation (`.env-external-pbx-sip-credentials` — not read in this
audit), external PBX AOR preparation, and empirical verification of inbound NAT
reachability.
