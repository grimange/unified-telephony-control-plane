# V1 Gap F — Provider-Wire Correlation-Header Trust-Boundary Live Proof

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `f60fd746a91531ed6eeba9ad66030f538262d3da`
(`docs(v1): record provider wire evidence access blocker`)

## Verdict

`V1_GAP_F_PROVIDER_WIRE_TRUST_BOUNDARY_LIVE_PROVEN`

One canonical outbound Call to `97001` proved the trust boundary on the wire:

```text
X-UTCP-Call-Leg-ID        internal PRESENT (exact)  ->  provider ABSENT
X-UTCP-Route-Decision-ID  internal PRESENT (exact)  ->  provider ABSENT
X-UTCP-Trunk-Endpoint-ID  internal PRESENT (exact)  ->  provider ABSENT
```

All three appear exactly once each in the entire capture — only on the trusted
runtime → Kamailio INVITE. They are absent from **every** provider-facing INVITE,
including the authenticated retry. The provider answered (`200 OK`), so the
stripped INVITE demonstrably reached and was accepted by the external PBX.

**Gap F is CLOSED.**

Recorded separately and **not** part of Gap F's adopted scope: a fourth header,
`X-UTCP-Caller-Identity-ID`, **is transmitted to the provider** on every
provider-facing INVITE. See "Out-of-scope UTCP-prefixed leakage observed" below.

## Attempt chronology

### Attempt 1 — blocked on evidence access

The first attempt placed no Call: neither an external-PBX ingress trace nor an
actual provider-egress packet capture was reachable. Host `sudo` demanded
interactive authentication, the Kamailio Pod runs with
`capabilities.drop: ["ALL"]` and failed with `Operation not permitted`, a
cluster-wide scan found zero Pods with `hostNetwork`, `privileged`, or
`NET_RAW`, creating privileged diagnostics was forbidden, and the external-PBX
telecom-MCP allowlist exposed no SIP trace. That record is preserved below.

### Attempt 2 — successful, this record

The operator installed a temporary, fixed-purpose, root-owned capture wrapper at
`/usr/local/sbin/utcp-gap-f-capture` with a hard 180 s cutoff, a fixed narrow
filter, no arbitrary `tcpdump` arguments, and no Kubernetes privilege change. It
ran unattended.

## Capture method

```text
wrapper   /usr/local/sbin/utcp-gap-f-capture   (start | stop, sudo -n)
pid       1681024
output    /run/user/1000/utcp-gap-f-capture.txt
filter    udp and (port 5062 or (host 38.146.161.46 and port 5060))
process   /usr/bin/tcpdump -i any -p -nn -s 0 -A -l -U -Z grimange <filter>
size      86 839 bytes
```

Privilege model: root-owned wrapper dropping to the unprivileged user via
`-Z grimange`; ephemeral, watchdog-terminated, no Pod, sidecar, DaemonSet, or
persistent service. A stale operator test capture was stopped idempotently
(`UTCP_GAP_F_CAPTURE_STOPPED`) and its output cleared before the proof.

## Canonical environment

Native k3s, context `default`, node `utcp-dev01` (`192.168.254.124`); k3d
`utcp-local` `0/1`, stopped and noncanonical. No deployment was performed — the
current HEAD is a documentation-only Gap E closure commit and the running
runtime already contains the behavior under proof.

## Live Kamailio configuration — verified

Read from the running canonical Pod `kamailio-6f984fbf7b-xxpbf` (1/1 Running),
`route[RUNTIME_EXTERNAL_TRUNK]`:

Correlation inputs are required before provider selection:

```text
line  2  if ($hdr(X-UTCP-Call-Leg-ID) == "" || $hdr(X-UTCP-Route-Decision-ID) == "") { ... }
line  8  sql_query(... kamailio_external_trunk_route_view v
                   where v.trunk_endpoint_id = '$hdr(X-UTCP-Trunk-Endpoint-ID)'
                     and v.destination_user = '$rU'
                     and v.direction = 'outbound'
                     and v.desired_state = 'active'
                     and v.accept_new_calls = true)
```

All three headers are removed before provider relay:

```text
line 20  $du = $dbr(runtime_external_route=>[0,0]);
line 23  $ru = "sip:" + $var(utcp_destination_user) + "@" + $var(utcp_provider_host);
line 27  $fsn = "provider";
line 28  remove_hf("X-UTCP-Call-Leg-ID");
line 29  remove_hf("X-UTCP-Route-Decision-ID");
line 30  remove_hf("X-UTCP-Trunk-Endpoint-ID");
line 31  dlg_manage();
line 32  t_on_failure("RUNTIME_EXTERNAL_TRUNK_AUTH");
line 33  if (!t_relay()) { ... }
```

```text
X-UTCP-Call-Leg-ID        REMOVED before relay
X-UTCP-Route-Decision-ID  REMOVED before relay
X-UTCP-Trunk-Endpoint-ID  REMOVED before relay
```

Live configuration matches repository authority; no
`V1_GAP_F_LIVE_KAMAILIO_CONFIG_MISMATCH` condition exists.

## Health baseline

Recorded before the evidence gate, not consumed: all `utcp-platform` Pods Ready
(not-ready `0`); ExternalTrunk `3a9bf028-…` `active`/`ready`; registration
`registered`; RuntimeNode `102d58ba-…` `active`/`ready`; non-terminal Calls `0`,
CallLegs `0`. The environment was ready; only evidence access was missing.

## Live Kamailio configuration — re-verified, unchanged

Pod `kamailio-6f984fbf7b-xxpbf` (1/1 Running), `route[RUNTIME_EXTERNAL_TRUNK]`:
line 2 requires `X-UTCP-Call-Leg-ID` and `X-UTCP-Route-Decision-ID`; line 8 keys
provider selection on `X-UTCP-Trunk-Endpoint-ID`; lines 28–30 `remove_hf()` all
three; `t_relay()` follows at line 33. No deployment was performed — HEAD is
documentation-only and the running runtime already contained the behavior.

## Health baseline

All `utcp-platform` Pods Ready; trunk `3a9bf028-…` `active`/`ready`;
registration `registered`; RuntimeNode `102d58ba-…` `active`/`ready`;
non-terminal Calls `0`, CallLegs `0`. Existing canonical `97001` objects reused:
TelephonyAddress `c537a4a7-…`, outbound route `v1a-reproof-97001`.

## The proof Call

Exactly one canonical outbound Call, `runtime_node_id` omitted.

| Fact | Value |
| --- | --- |
| Call | `3cda9899-6a87-4c0c-9094-b5be98475074` |
| CallLeg | `977a89c6-2ab9-4371-b966-61f5e9719a5b` |
| RouteDecision | `ba2f9327-1d0f-46c0-8da1-360d30272f44` |
| Originate RuntimeOperation | `ccaaddefff800472e5325c1c72e990c6` (succeeded) |
| RuntimeNode (auto-selected) | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| TrunkEndpoint | `ad7a95f4-388c-445e-9259-edd30b5137a2` |
| CallLeg.runtime_channel_id | `utcp-call-leg-977a89c6-2ab9-4371-b966-61f5e9719a5b` |
| SIP Call-ID | `ab83b472-4c46-46f8-8b92-8cdd47a48b03` |

## Internal trusted INVITE

```text
07:40:03.816471  10.42.0.4:5060 -> 10.42.0.195:5062   (managed Asterisk -> Kamailio runtime ingress)
INVITE sip:97001@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
Call-ID: ab83b472-4c46-46f8-8b92-8cdd47a48b03
CSeq: 20859 INVITE
X-UTCP-Call-Leg-ID: 977a89c6-2ab9-4371-b966-61f5e9719a5b
X-UTCP-Route-Decision-ID: ba2f9327-1d0f-46c0-8da1-360d30272f44
X-UTCP-Trunk-Endpoint-ID: ad7a95f4-388c-445e-9259-edd30b5137a2
```

Each value equals canonical application state exactly.

## Provider-facing INVITEs

Two logical provider INVITEs were transmitted for this one Call (initial, then
the authenticated retry after `401`). Each was observed at three capture points
(`veth`, `cni0`, and the real egress `wlp4s0`).

```text
07:40:03.877642  192.168.254.124:29880 -> 38.146.161.46:5060  (wlp4s0 Out, initial)
INVITE sip:97001@38.146.161.46:5060
Call-ID: ab83b472-4c46-46f8-8b92-8cdd47a48b03
CSeq: 20859 INVITE
  X-UTCP-Call-Leg-ID        ABSENT
  X-UTCP-Route-Decision-ID  ABSENT
  X-UTCP-Trunk-Endpoint-ID  ABSENT

07:40:04.104023  192.168.254.124:29880 -> 38.146.161.46:5060  (wlp4s0 Out, authenticated retry)
INVITE sip:97001@38.146.161.46:5060
Call-ID: ab83b472-4c46-46f8-8b92-8cdd47a48b03
CSeq: 20860 INVITE
Authorization: Digest username="utcp-v1", realm="asterisk", ... algorithm=MD5
  X-UTCP-Call-Leg-ID        ABSENT
  X-UTCP-Route-Decision-ID  ABSENT
  X-UTCP-Trunk-Endpoint-ID  ABSENT
```

Whole-capture occurrence counts are conclusive — each of the three appears
exactly once, in the internal INVITE only:

```text
X-UTCP-Call-Leg-ID         1
X-UTCP-Route-Decision-ID   1
X-UTCP-Trunk-Endpoint-ID   1
```

## Mandatory comparison

```text
Header                          Internal runtime→Kamailio                 Provider-facing wire
--------------------------------------------------------------------------------------------
X-UTCP-Call-Leg-ID              977a89c6-2ab9-4371-b966-61f5e9719a5b      ABSENT
X-UTCP-Route-Decision-ID        ba2f9327-1d0f-46c0-8da1-360d30272f44      ABSENT
X-UTCP-Trunk-Endpoint-ID        ad7a95f4-388c-445e-9259-edd30b5137a2      ABSENT

SIP Call-ID          ab83b472-4c46-46f8-8b92-8cdd47a48b03  (identical both sides)
provider destination 38.146.161.46:5060
destination user     97001
```

Same-transaction proof rests on the shared `Call-ID`, the shared `From` tag
`7313dedc-…`, and CSeq continuity `20859` → `20860`, not on timing.

## Provider delivery positive control

```text
07:40:04.103785  38.146.161.46 -> UTCP   SIP/2.0 401 Unauthorized
07:40:04.327038  38.146.161.46 -> UTCP   SIP/2.0 100 Trying
07:40:04.337771  38.146.161.46 -> UTCP   SIP/2.0 200 OK      <- provider answered
```

Authentication challenge: **401**, followed by a normal authenticated retry. The
external PBX answered the stripped INVITE, so this is not an internal-only
packet.

## Out-of-scope UTCP-prefixed leakage observed

Gap F's adopted scope is exactly the three headers above, and they pass. Noted
separately as required rather than folded into the verdict:

```text
X-UTCP-Caller-Identity-ID: f11a46e5-fbdc-4eb0-b28d-9c002491a80a
  internal INVITE   PRESENT
  provider INVITE   PRESENT  (initial and authenticated retry)
  capture-wide occurrences: 7  (1 internal + 6 provider-side copies)
```

The managed-Asterisk pre-dial handler applies four `X-UTCP-*` headers, and
`route[RUNTIME_EXTERNAL_TRUNK]` removes only three; the CallerIdentity header has
no `remove_hf()`. An internal UUID therefore reaches the external provider on
every outbound INVITE. This is a real trust-boundary observation, not a Gap F
acceptance failure, and it is recorded here for a separate bounded decision. It
was **not** repaired in this packet.

## Cleanup

The Call was terminated through the canonical `call.hangup` API — no AMI, ARI,
or CLI hangup. Final state `completed / remote / remote`, `answered_at`
`2026-08-30 07:40:18+00`, `failure_class` and `failure_code` NULL. Non-terminal
Calls `0`, CallLegs `0`. `termination_reason` resolved to `remote` because the
runtime terminal fact's `observed_at` preceded the hangup operation's creation
under ADR-030 §5; final call state is not a Gap F acceptance criterion.

The temporary capture output was removed after analysis. No production source,
Kamailio configuration, Asterisk configuration, or external PBX state was
changed, and no permanent diagnostic infrastructure was created.

## Evidence-access assessment

The proof contract requires actual packet evidence on both sides and explicitly
rejects closing Gap F from configuration, Kamailio source text, unit tests,
rendered ConfigMaps, pre-relay logs, constructed messages, or database state.
Every authorized avenue was tested:

### 1. Host packet capture on the canonical node — BLOCKED

The session runs on `utcp-dev01` itself (`hostname` = `utcp-dev01`, host carries
`192.168.254.124`), and `/usr/bin/tcpdump` is installed. It has no file
capabilities, and the account is in the `sudo` group but

```text
sudo -n true  ->  sudo: interactive authentication is required
```

Non-interactive privileged capture is therefore impossible. Both required
capture points would be visible here: the provider egress toward
`38.146.161.46:5060` on `wlp4s0`, and the internal runtime → Kamailio INVITE on
`cni0` / `flannel.1`.

### 2. In-cluster capture from the Kamailio Pod — BLOCKED

The Kamailio Pod is the ideal capture point — it observes both the trusted
ingress and the provider egress of the same process — and the image ships both
`tcpdump` and `ngrep`. However its container security context is

```text
allowPrivilegeEscalation: false
capabilities: { drop: ["ALL"] }
hostNetwork: (unset)
CapEff: 0000000000000000
CapBnd: 0000000000000000
```

and the empirical result is conclusive:

```text
tcpdump: any: You don't have permission to perform this capture on that device
(socket: Operation not permitted)
```

### 3. Any other capable workload — NONE EXIST

A cluster-wide scan for `hostNetwork`, `privileged`, or an added `NET_RAW`
capability returned **zero** Pods. The K3 security boundary is intact and
uniformly applied.

### 4. New privileged capture workload — NOT PERMITTED

A privileged/`hostNetwork` debug Pod, DaemonSet, sidecar, `sngrep` service, pcap
daemon, or mirroring service is explicitly forbidden by the proof contract. None
was created.

### 5. External-PBX ingress trace — BLOCKED

The external PBX is the authorized telecom-MCP target `lab-remote-asterisk-01`,
but its read-only CLI allowlist contains no SIP-trace capability:

```text
bridge show all, core show channels, core show uptime, core show version,
dialplan show telecom-mcp-test, pjsip show contacts, pjsip show endpoints,
pjsip show registrations outbound
patterns: pjsip show endpoint <x>, core show channel <x>
```

`pjsip set logger` / `pjsip show history` are absent from the allowlist and are
mutations in any case. `asterisk_logs` returned
`NOT_FOUND: Configured log file not found (/var/log/asterisk/messages.log)`, and
`telecom_capture_incident_evidence` collects state snapshots rather than SIP
message bodies and exceeded its deadline. None of these can show received INVITE
headers.

## Conclusion

```text
external-PBX ingress trace:        NOT ACCESSIBLE
UTCP provider-egress packet capture: NOT ACCESSIBLE
```

Because the contract forbids originating merely to generate unusable evidence,
no Call was placed and no canonical identifiers were consumed.

## Exact missing capability

One capability unblocks the entire proof, and it is small and host-local:

```text
Non-interactive privileged packet capture on utcp-dev01, scoped to the proof.
```

Either form is sufficient:

```text
a) allow passwordless sudo for tcpdump on utcp-dev01, e.g. a sudoers entry
   limited to /usr/bin/tcpdump; or
b) grant file capabilities once:
   sudo setcap cap_net_raw,cap_net_admin+eip /usr/bin/tcpdump
```

With either in place the whole proof runs autonomously in a single packet, with
no production change and no persistent diagnostic infrastructure:

```text
1. start one narrow ephemeral capture before origination
     host 38.146.161.46 and udp port 5060      (provider egress)
     udp port 5062                              (trusted runtime ingress)
2. place exactly one canonical outbound Call to 97001
3. stop the capture
4. compare the three X-UTCP headers on both sides of the same SIP Call-ID
5. discard the capture; commit only concise textual excerpts
```

A third option, if host capability is not to be granted: the operator runs the
narrow capture themselves during a coordinated Call and supplies the two INVITE
header blocks.

## Gap F status

```text
Gap F: CLOSED
```

The provider-wire trust boundary is live-proven for the three adopted
correlation headers. The `X-UTCP-Caller-Identity-ID` transmission recorded above
is a separate, newly isolated item outside Gap F's adopted scope.

## Boundary

Gap A through Gap F are now closed. No production source was changed, no
Kamailio or Asterisk configuration was modified, no deployment was performed, and
no permanent diagnostic infrastructure was created. The temporary host capture
wrapper and its sudoers rule remain installed and were deliberately not removed
by this packet — host authorization changes are outside repository state. ADR-031 stable-public-edge acceptance
remains `DEFERRED_BY_ENVIRONMENT`, not abandoned. No K5, RMA, or A0 work was
started. The `scripts/native-k3s/image-sync` `.git` defect and the broad
`Quality` CI failures remain unchanged separate debt; neither blocked this proof.
