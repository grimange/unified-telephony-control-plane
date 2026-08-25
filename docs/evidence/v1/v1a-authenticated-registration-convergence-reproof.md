# V1-A Authenticated Registration Convergence Re-Proof

## Status

`V1A_REAL_REGISTRATION_PASSED_FOUND_TRUNK_HEALTH_PRODUCT_DEFECT`

Authenticated external registration is live-proven end to end. The next genuine
UTCP product boundary is `external_trunks.observed_health`, which has no
canonical writer and therefore blocks routing eligibility regardless of
registration health.

V1 remains active. V1-B was not activated. No repository code was changed by
this packet.

## Retained canonical ExternalTrunk

Nothing was created, rotated, rebound, or manually projected. The trunk from the
previous packet was left active and converged on its own.

```text
external_trunk_id       f944ceb3-6a8a-44a3-b776-e9db1ad6c1fc  (slug v1a-external-register-lab)
trunk_endpoint_id       f86a012f-b6c8-4ff6-8139-7f9cc80df107
desired_state           active          configuration_version 5
signaling_mode          outbound_registration      transport udp
registration_target     sip:38.146.161.46:5060
registration_identity   utcp-v1         registration_realm asterisk
credential_reference_id 6542d785-0f95-4086-abd3-0077f5a2c632   version 1
provider row            1, keyed by trunk_endpoint_id, desired_generation 5
observed_health         unknown
```

## PBX AOR correction confirmed

```text
Endpoint:  utcp-v1                       Not in use    0 of inf
InAuth:    utcp-v1-auth/utcp-v1
Aor:       utcp-v1                       1                     <- corrected, max_contacts 1
Contact:   utcp-v1/sip:f86a012f-b6c8-4ff6-8139-7f9cc8 761d0cef2e NonQual  nan
Transport: transport-udp  udp  0 0  0.0.0.0:5060
```

The AOR is now named `utcp-v1`, matching the To-header user that Asterisk 22
`find_registrar_aor()` uses. Endpoint state moved `Unavailable` -> `Not in use`.
`max_contacts=1` is visible in the AOR line. Exactly one contact exists after
many re-registrations, which is consistent with `remove_existing=yes`.
`AuthDetail` paths were not used.

## REGISTER wire proof (200 OK)

Captured in the Kamailio Pod's node network namespace. Sender is the Kamailio
Pod running `uac_reg` (`User-Agent: kamailio (5.8.6 (x86_64/linux))`, Contact
user equal to the canonical `trunk_endpoint_id`). No manual REGISTER, no
`uac.reg_refresh`, no `uac.reg_reload`, no manual projection was issued.

```text
10:55:12  Pod 10.42.0.200:5060 -> 38.146.161.46:5060   REGISTER sip:38.146.161.46 SIP/2.0
          To/From <sip:utcp-v1@38.146.161.46>   CSeq 14 REGISTER
          Call-ID 7942612544137857-15@0.0.0.0   Expires 120
          Contact <sip:f86a012f-b6c8-4ff6-8139-7f9cc80df107@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060>

10:55:12  38.146.161.46 -> Pod                          SIP/2.0 401 Unauthorized
          Via ...;rport=22108;received=158.62.75.77
          WWW-Authenticate: Digest realm="asterisk", algorithm=MD5, qop="auth"   (nonce/opaque withheld)
          Server: Asterisk PBX 22.5.2

10:55:12  Pod -> 38.146.161.46                          REGISTER, CSeq 15, same Call-ID
          Authorization: Digest username="utcp-v1", realm="asterisk",
                         uri="sip:38.146.161.46", qop=auth, nc=00000001, algorithm=MD5
                         (nonce / cnonce / response withheld)

10:55:12  38.146.161.46 -> Pod                          SIP/2.0 200 OK
          CSeq 17 REGISTER
          Contact: <sip:f86a012f-b6c8-4ff6-8139-7f9cc80df107@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060>;expires=119
          Expires: 120
          Server: Asterisk PBX 22.5.2
```

Authentication accepted (no re-challenge) and AOR/contact binding accepted.

## Post-success automatic refresh

Two consecutive full cycles, both ending in 200 OK, driven only by Kamailio's
own registration timer:

```text
10:55:12  REGISTER -> 401 -> REGISTER+Digest -> 200 OK
10:57:02  REGISTER -> 401 -> REGISTER+Digest -> 200 OK
```

Interval 110 s, consistent with `expires=120` and the record's `diff_expires`.
`uac_reg` remained online across both.

## Kamailio runtime state

```text
uac.reg_dump  l_uuid f86a012f-b6c8-4ff6-8139-7f9cc80df107
              flags 20  = UAC_REG_INIT(16) | UAC_REG_ONLINE(4)   -> online
              expires 120   timer_expires advancing   reg_init 1787653081
              contact_addr kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
```

Read-only through the internal control seam. No forced refresh or unregister.

## Canonical registration observation

Transitioned on its own from the previous packet's `failed`/`unreachable`:

```text
trunk_endpoint_id   f86a012f-b6c8-4ff6-8139-7f9cc80df107
state               registered
failure_category    (null)
last_attempt_at     2026-08-25 10:58:50+00
last_success_at     2026-08-25 10:58:50+00
expires_at          2026-08-25 10:59:01+00
contact_fingerprint 3f11cfc27fac43e95581adf965fee9e74da829421f899b82dffd27a1023e3ced
observation_version 1364 (advancing)
desired_generation  5
```

No row was written manually. Isolation held: 1 provider row, 1 observation,
1 outbound-registration endpoint across 37 external trunks.

## Contact rewrite / NAT evidence

`rewrite_contact` is **not in effect** on the external endpoint.

Asterisk 22 `res_pjsip_nat.c` `rewrite_contact()` mutates the incoming request's
Contact URI host/port in place with the packet source before the registrar
stores it, and `res_pjsip_registrar.c` `registrar_add_contact()` builds the 200
OK Contact headers from the **stored** `contact->uri`. Both observed 200 OK
responses carried the unmodified private address:

```text
sent Contact    sip:f86a012f-b6c8-4ff6-8139-7f9cc80df107@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
stored Contact  sip:f86a012f-b6c8-4ff6-8139-7f9cc80df107@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
observed NAT    received=158.62.75.77, rport 22108 / 22230 / 22498
```

The PBX has therefore bound a Contact that is a private cluster DNS name, not
resolvable or routable from the public internet.

A second, independent obstacle was measured: the cluster-side SNAT source is
stable (`172.21.0.2:12726` for every packet), but the public mapped port changes
on every REGISTER transaction (`22429` in the previous packet, then `22108`,
`22230`, `22498`). The residential NAT does not hold a stable UDP mapping, so
even a correctly rewritten public Contact would be pinned to a port that is no
longer mapped by the time an inbound request arrived.

No new canonical NAT/contact setting was introduced.

## Inbound registered-Contact reachability

Not actively exercised. The telecom MCP runtime policy permits only the
`observability` capability class, so `telecom.list_probes`,
`telecom.run_probe` and `asterisk.originate_probe` are refused
(`NOT_ALLOWED: capability_class validation`). PBX SSH `sudo` is restricted to
log tail, so no `pjsip qualify` is available. The AOR contact is `NonQual`, so
Asterisk generates no automatic OPTIONS toward it, and no PBX-originated request
appeared in the capture.

The determination is therefore structural, from the two facts above, not from an
active PBX-originated probe.

## PRODUCT_DEFECT-V1A-4 — external trunk observed health has no canonical writer

With the registration observation at `registered` and the endpoint active:

```text
routingEligibility(outbound) {"eligible":false,"code":"external_trunk_not_ready"}
routingEligibility(inbound)  {"eligible":false,"code":"external_trunk_not_ready"}
```

`C7aService::routingEligibility()` gates on `external_trunks.observed_health`
before it ever reaches the registration gate. Repository-wide, that column is
written exactly once — `'observed_health' => 'unknown'` at trunk creation in
`C7aService::createTrunk()`. No service, worker, reconciler, scheduler, console
command, or projection ever advances it.

Consequences:

- No ExternalTrunk can ever become routable in the live system.
- The `external_trunk_registration_not_ready` gate is unreachable, so the
  registration readiness contract cannot be exercised end to end.
- `V1ARegistrationExternalTrunkTest` only passes because it sets
  `observed_health = 'ready'` by direct database write.

Not fixed here: the readiness gate was not weakened and `observed_health` was not
updated directly. This is the next bounded implementation target.

## Runtime regression

```text
kamailio, kamailio-registration-observer (2), gateway, api, web, worker   Running
postgres-0, redis-0                                                      1/1 Running
kamailio-registration-control                                            ClusterIP 8090
make kamailio-signaling-config-check                pass
make kamailio-signaling-registration-runtime-proof  pass
make security-config-check                          pass
make repository-hygiene                             pass
make secret-scan                                    pass
git diff --check                                    clean
make kamailio-signaling-external-trunk-runtime-proof  FAIL - host loopback only
make asterisk-external-trunk-runtime-proof            FAIL - host loopback only
```

## Host loopback defect — unchanged, not repaired

Confirmed still present and deliberately untouched:

```text
127.0.0.1:80    FAIL      127.0.0.1:443   FAIL      127.0.0.1:6550  OK
-A CNI-DN-431b96bd443bf13732969 -p tcp --dport 80  -j DNAT --to-destination 10.42.0.65:80
-A CNI-DN-431b96bd443bf13732969 -p tcp --dport 443 -j DNAT --to-destination 10.42.0.65:443
```

The two failing proofs fail on this and only on this. They are not a
registration regression; registration is proven directly from Kamailio,
Asterisk, the provider row, and the canonical observation.

## V1-B boundary

```text
host 5060 publications on k3d-utcp-local-serverlb : 0
host UDP 5060 listeners                            : 0
router forwarding                                  : not configured
```
