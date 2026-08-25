# V1-A Real Authenticated External REGISTER Proof

## Status

`V1A_REAL_REGISTRATION_BLOCKED_BY_PBX_PREPARATION`

Two UTCP product defects that independently blocked authenticated registration
were found, corrected, and live-proven closed. The remaining blocker is an
external PBX preparation inconsistency that UTCP cannot and must not work
around.

V1 remains active. V1-B was not activated.

## Canonical ExternalTrunk

Created through the normal authenticated Admin API (`/api/v1/admin/...`) as the
bootstrap platform administrator in the bootstrap tenant. No direct SQL, no
manual provider row, no manual observation, no manual HA1.

```text
external_trunk_id       f944ceb3-6a8a-44a3-b776-e9db1ad6c1fc
slug                    v1a-external-register-lab
desired_state           active
trunk_endpoint_id       f86a012f-b6c8-4ff6-8139-7f9cc80df107
signaling_mode          outbound_registration
authentication_mode     credentials
transport               udp
endpoint_uri            sip:38.146.161.46:5060
registration_target     sip:38.146.161.46:5060
registration_identity   utcp-v1
registration_realm      asterisk
credential_reference_id 6542d785-0f95-4086-abd3-0077f5a2c632
credential_type         sip
credential_identifier   utcp-v1
credential_version      1
```

The rotated secret was read only programmatically from the gitignored,
untracked, `0600` credential source and passed to the canonical credential
endpoint. It was never printed, echoed, or written to evidence.

## Canonical credential chain

```text
Admin API -> TrunkCredentialReference (encrypted)
          -> TrunkEndpoint binding
          -> ExternalTrunk configuration_version
          -> T6 projection (outbox dispatcher)
          -> kamailio_external_trunk_registration_view (HA1 only)
          -> Kamailio uac_reg datasource
          -> adapter-owned registration timer
```

Verified without disclosing the secret:

```text
provider_ha1_matches_md5(registration_identity:realm:rotated_secret) = true
provider auth_ha1 length                                            = 32
provider auth_password                                              = '' (empty)
t6_artifact_plaintext_hits                                          = 0
t6_artifact_ha1_hits                                                = 0
provider_table_plaintext_hits                                       = 0
credential_row_plaintext_hits                                       = 0 (encrypted)
api_log_plaintext_hits                                              = 0
kamailio_log_plaintext_hits                                         = 0
```

The generic `utcp.t6.projection.v1` Kamailio artifact carries signaling mode,
registration intent, credential reference id and version only.

## PRODUCT_DEFECT-V1A-1 (corrected) — empty REGISTER To/From user

`ExternalTrunkProjectionService::projectKamailioRegistrations()` derived
`r_username` solely from the user part of `registration_target`. A registrar URI
is normally host-only, so `r_username` was `NULL` and Kamailio emitted:

```text
REGISTER sip:38.146.161.46 SIP/2.0
To:   <sip:@38.146.161.46>
From: <sip:@38.146.161.46>;tag=...
```

The external PBX confirmed the effect:

```text
res_pjsip ... Dropping 534 bytes packet from UDP <public-nat>:<port> :
PJSIP syntax error exception when parsing 'To' header on line 3 col 10:
PJSIP syntax error exception when parsing 'From' header on line 4 col 12
```

Sixty captured packets, zero responses, pure SIP timer E/F retransmission.

Correction: `r_username` prefers an explicit registrar user part and otherwise
falls back to the canonical `registration_identity`. Live reproof after
deployment produced `To/From: <sip:utcp-v1@38.146.161.46>` and a real
challenge/response exchange.

## PRODUCT_DEFECT-V1A-2 (corrected) — observation could never become registered

`KamailioExternalTrunkRegistrationObserver` read `state`, `expires_at`,
`contact` and `failure_category` keys from `uac.reg_dump`. Live RPC output shows
those keys do not exist; Kamailio publishes:

```text
l_uuid l_username l_domain r_username r_domain realm auth_username
auth_password auth_ha1 auth_proxy expires flags diff_expires
timer_expires reg_init reg_delay contact_addr socket
```

The observer therefore always fell through to `failed`/`unreachable` with a null
expiry and null contact fingerprint, so `external_trunk_registration_observations`
could never reach `registered` and a registration endpoint could never become
outbound eligible.

Correction: canonical state is derived from the uac_reg flag bitmask
(`1 disabled`, `2 ongoing`, `4 online`, `8 auth-sent`, `16 initialised`),
`expires_at` from `timer_expires`, and the contact fingerprint from
`contact_addr`, all only when the record is online.

## CONFIGURATION_DEFECT-V1A-3 (corrected) — observer denied the control seam

`allow-kamailio-signaling-required-traffic` permitted TCP/8090 ingress only from
`utcp.io/network-role: worker`, while the committed observer egress policy
already allowed the reverse direction. Measured before the correction:

```text
kamailio-registration-observer -> kamailio:8090   Connection refused
control-plane-outbox-dispatcher -> kamailio:8090  OPEN
worker -> kamailio:8090                           OPEN
```

One ingress rule for `utcp.io/network-role: kamailio-registration-observer` was
added and applied through `make security-apply`. Measured after:

```text
kamailio-registration-observer -> kamailio:8090   OPEN
uac.reg_dump from the observer                    {"result": []}
```

Observation remains read-only; it is not a second registration authority.

## REGISTER wire proof

Captured inside the Kamailio Pod's node network namespace, so both the Pod
address (`cni0`/veth) and the SNAT'd node address (`eth0`) are visible. The
sender is the actual Kamailio Pod running `uac_reg`, not a diagnostic utility:
`User-Agent: kamailio (5.8.6 (x86_64/linux))` and a Contact user equal to the
canonical `trunk_endpoint_id`.

```text
#1  10.42.1.216:5060 -> 38.146.161.46:5060   REGISTER sip:38.146.161.46 SIP/2.0
    To:      <sip:utcp-v1@38.146.161.46>
    From:    <sip:utcp-v1@38.146.161.46>;tag=64ff6b49...-2aa36c03
    CSeq:    10 REGISTER
    Call-ID: 7206ebf26c970d19-15@0.0.0.0
    Contact: <sip:f86a012f-b6c8-4ff6-8139-7f9cc80df107@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060>
    Expires: 120

#4  38.146.161.46:5060 -> Kamailio Pod          SIP/2.0 401 Unauthorized
    Via: ...;rport=22429;received=158.62.75.77
    WWW-Authenticate: Digest realm="asterisk",nonce=...,opaque=...,algorithm=MD5,qop="auth"
    Server: Asterisk PBX 22.5.2

#7  Kamailio Pod -> 38.146.161.46:5060          REGISTER sip:38.146.161.46 SIP/2.0
    CSeq: 11 REGISTER   (same Call-ID)
    Authorization: Digest username="utcp-v1", realm="asterisk", uri="sip:38.146.161.46",
                   qop=auth, nc=00000001, algorithm=MD5   (nonce/cnonce/response withheld)

#10 38.146.161.46:5060 -> Kamailio Pod          SIP/2.0 404 Not Found
    Server: Asterisk PBX 22.5.2
```

The digest was accepted: the PBX did not re-challenge. Registration then failed
at AOR resolution.

## PBX preparation blocker

External PBX log, correlated by public NAT source and port:

```text
res_pjsip_registrar.c: AOR '' not found for endpoint 'utcp-v1' (158.62.75.77:22429)
```

Asterisk 22 `find_registrar_aor()` takes the user of the **To** header and
`find_aor_name()` matches it against the endpoint's configured `aors` list; the
warning prints the NULL lookup result, not the input. The endpoint is prepared
as:

```text
Endpoint: utcp-v1        InAuth: utcp-v1-auth/utcp-v1     Aor: utcp-v1-aor
```

The registering identity is `utcp-v1`; the only configured AOR is named
`utcp-v1-aor`; those never match. Every other endpoint on the same PBX follows
the matching convention (`Endpoint 6001` -> `Aor 6001`).

UTCP cannot resolve this from its own side: Asterisk identifies the endpoint
from the From-header user (`utcp-v1`) and resolves the AOR from the To-header
user, and Kamailio `uac_reg` builds both from the same `r_username`. Renaming
the canonical registration identity to `utcp-v1-aor` would break endpoint
identification instead.

Smallest external correction: give endpoint `utcp-v1` an AOR named `utcp-v1`
(rename `[utcp-v1-aor]` to `[utcp-v1]`, or add `utcp-v1` to `aors`), keeping
`max_contacts=1`, `remove_existing=yes`, `rewrite_contact=yes`, then reload
PJSIP. No identify rule is required.

## Automatic registration scheduling

Four consecutive cycles driven entirely by Kamailio's own registration timer.
No `uac.reg_refresh` was invoked manually.

```text
10:21:31 REGISTER   10:21:32 401   10:21:32 REGISTER+digest   10:21:32 404
10:22:21 REGISTER   10:22:22 401   10:22:22 REGISTER+digest   10:22:22 404
10:23:11 REGISTER   10:23:12 401   10:23:12 REGISTER+digest   10:23:12 404
10:24:01 REGISTER   10:24:02 401   10:24:02 REGISTER+digest   10:24:02 404
```

This is the adapter-owned retry cadence (`reg_retry_interval 60`,
`reg_random_delay 5`). A post-success refresh at the 120 s registration lifetime
was not observed because registration never succeeded.

## Observation and readiness

```text
external_trunk_registration_observations
  trunk_endpoint_id   f86a012f-b6c8-4ff6-8139-7f9cc80df107
  state               failed
  failure_category    unreachable
  last_success_at     (null)
  expires_at          (null)
  contact_fingerprint (null)
  desired_generation  5

uac.reg_dump           flags=16 (initialised, not online)
routingEligibility(outbound) {"eligible":false,"code":"external_trunk_not_ready"}
routingEligibility(inbound)  {"eligible":false,"code":"external_trunk_not_ready"}
```

The endpoint is correctly not outbound eligible. Note that the first gate is
`external_trunks.observed_health`, which remains `unknown`: no canonical writer
advances trunk-level observed health, so `external_trunk_registration_not_ready`
is unreachable in the live system today. That is a separate C7A observation gap,
recorded here and not changed by this packet.

## Multi-trunk isolation

```text
registration_view_rows_total               1
registration_view_rows_for_lab_trunk       1
registration_observations_total            1
outbound_registration_endpoints_total      1
static_endpoints_total                    36
static_endpoints_with_registration_intent  0
credential_refs_for_lab_trunk              1
```

Exactly one provider representation keyed by `trunk_endpoint_id`. Static trunks
gained no registration intent, no provider registration row, and no observation.

## Contact strategy evidence

```text
sent Contact    sip:f86a012f-b6c8-4ff6-8139-7f9cc80df107@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
observed source 158.62.75.77 (public), rport 22429, seen by the PBX as received=158.62.75.77
stored Contact  none - the AOR has no contact, endpoint state Unavailable
```

`rewrite_contact=yes` could not be exercised: Asterisk never reached contact
binding. Whether the residential NAT mapping is usable for inbound requests is
therefore untested.

## Environment defect (not a UTCP defect)

The canonical local edge `127.0.0.1:80` and `127.0.0.1:443` is intercepted in the
**host** network namespace by stale CNI hostport rules belonging to a non-UTCP,
host-level CNI (`cbr0`) whose target Pod no longer exists:

```text
-A CNI-HOSTPORT-DNAT -p tcp -m multiport --dports 80,443 -j CNI-DN-431b96bd443bf13732969
-A CNI-DN-431b96bd443bf13732969 -p tcp --dport 80  -j DNAT --to-destination 10.42.0.65:80
-A CNI-DN-431b96bd443bf13732969 -p tcp --dport 443 -j DNAT --to-destination 10.42.0.65:443
```

`127.0.0.1:6550` and `127.0.0.1:5001` (same k3d containers) work; only 80/443 are
hijacked. Restarting the k3d load balancer through `k3d node stop/start` did not
clear it, because the rules are not Docker's. The k3d publication itself is
intact (`127.0.0.1:443->443/tcp`) and Traefik answers `200` on every cluster node
address. `make kamailio-signaling-external-trunk-runtime-proof` and
`make asterisk-external-trunk-runtime-proof` fail on this and only on this.

## Verification

```text
apps/api php artisan test --filter=V1ARegistrationExternalTrunkTest   1 skipped, 13 passed
scripts/security/config-check-test                                    passed
scripts/kamailio-signaling/config-check-test                          passed
make security-config-check                                            passed
make kamailio-signaling-config-check                                  pass
make kamailio-signaling-registration-runtime-proof                    pass
make security-apply                                                   policies applied; stops at the pre-existing missing-helm gateway step
make k8s-apply                                                        applied (three times, for each corrected image)
make kamailio-signaling-external-trunk-runtime-proof                  FAIL - host edge hijack
make asterisk-external-trunk-runtime-proof                            FAIL - host edge hijack
```

## Retained state

The `v1a-external-register-lab` ExternalTrunk is retained `active` so that
registration converges automatically once the external AOR is corrected. It
currently emits one REGISTER cycle toward the PBX roughly every 50 seconds.
