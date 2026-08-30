# V1 Gap F — Provider-Wire Correlation-Header Trust-Boundary Live Proof (Blocked)

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `863a9773242beec72f9574a1395d9893ff3cbd0a`
(`docs(v1): close provider failure taxonomy gap`)

## Verdict

`V1_GAP_F_PROVIDER_WIRE_EVIDENCE_ACCESS_BLOCKED`

No Call was placed. Neither an external-PBX ingress trace nor an actual UTCP
provider-egress packet capture is obtainable with currently authorized tooling,
and Gap F cannot be closed from configuration evidence alone. Per the proof
contract, originating a Call would only have produced unusable evidence, so the
packet stopped at the evidence-access gate.

The live Kamailio stripping configuration **was** verified and matches
repository authority. That establishes expected behavior; it does not close
Gap F.

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
Gap F: PROOF_GAP_ONLY  (unchanged)
```

Gap F remains open and unproven. The blocker is evidence access, not a suspected
defect: the live Kamailio configuration performs all three removals before
`t_relay()`, so there is no present indication of a header leak — only an
inability to demonstrate the wire behavior, which is precisely what a
`PROOF_GAP_ONLY` item requires.

## Boundary

Gap A through Gap E remain closed. No Call was placed, no production source was
changed, no Kamailio configuration was modified, no deployment was performed, and
no diagnostic infrastructure was created. ADR-031 stable-public-edge acceptance
remains `DEFERRED_BY_ENVIRONMENT`, not abandoned. No K5, RMA, or A0 work was
started. The `scripts/native-k3s/image-sync` `.git` defect and the broad
`Quality` CI failures remain unchanged separate debt; neither blocked this proof.
