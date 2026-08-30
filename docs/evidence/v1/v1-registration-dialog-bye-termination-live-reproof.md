# V1 Registration/NAT BYE Dialog-Termination Controlled Live Re-Proof

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `992c038501ea31c22692df0efaf2b36a9bfd237e`
(`fix(v1): terminate fallback provider dialogs on BYE`)

## Verdict

`V1_REGISTRATION_DIALOG_RETURN_PATH_LIVE_PROOF_PASSED`

The committed `dlg_manage()` correction closes the one criterion that failed on
2026-08-29. A provider-originated no-Route BYE now drives the tracked Kamailio
dialog from `state 3` to `state 5` (`DLG_STATE_DELETED`) as part of normal BYE
processing, and the dialog leaves the live dialog store without
`dlg_ontimeout()`. The previously proven return corridor — managed Asterisk
receipt and canonical `completed / remote / remote` — is unchanged.

Gap B is closed.

## Environment

Native k3s, context `default`, node `utcp-dev01` (`192.168.254.124`).
k3d `utcp-local` remained stopped (`0/1` servers) and non-canonical throughout.

Stable provider-facing SIP identity was absent for the whole proof:
`UTCP_SERVER_PROVIDER_SIP_ADDRESS` and `UTCP_SERVER_PROVIDER_SIP_PORT` unset.
`make server-config-check` passed in that state.

Deployment used only the canonical lifecycle: `server-config-check`,
`server-image-preflight`, `server-apply`, `server-status`. No `kubectl edit`,
`patch`, `set image`, manual ConfigMap apply, Pod deletion, or Deployment
restart was used.

## Deployment result

The pre-deployment Kamailio Pod (`kamailio-846b9fbf45-5j6vh`) carried
`dlg_manage()` exactly once, in `route[RUNTIME_EXTERNAL_TRUNK]`, and not in
`route[WITHINDLG]` — the uncorrected build. The canonical rollout produced
Pod `kamailio-6f984fbf7b-xxpbf`, Ready, carrying `dlg_manage()` twice.

That Pod restarted once during rollout (`auth_db … unable to open database
connection` while the data StatefulSet rolled) and Kubernetes' own restart
resolved it. This repeats the environmental pattern recorded in the 2026-08-29
proof and is not an application defect.

## Effective live Kamailio configuration

`route[RUNTIME_EXTERNAL_TRUNK]`: `dlg_manage()` PRESENT, provider-facing
`record_route()` ABSENT — the registration/NAT edge. The only `record_route()`
calls are on the two inbound runtime-facing paths.

`route[WITHINDLG]` fallback, in effective order:

```text
loose_route()                                   # first authority
$Rp == 5060 && $rm == "BYE" && has_totag() && is_known_dlg()
sql_query(kamailio_external_trunk_route_view, provider_host = '$si',
          direction = 'outbound', desired_state = 'active')
dlg_set_ruri()
dlg_manage()
route(MEDIA_DELETE)
t_relay()
```

Effective sockets, unchanged and with no fabricated public identity:

```text
provider  udp:0.0.0.0:5060  advertise kamailio-sip-external.utcp-platform.svc.cluster.local:5060
runtime   udp:0.0.0.0:5062  advertise kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
```

## Baseline

Non-terminal V1 Calls `0`, non-terminal CallLegs `0`, live dialog store empty.

ExternalTrunk `3a9bf028-ee87-4212-8870-a1851530fee4` was `active` / `ready` /
`registration_endpoint_registered`, endpoint signaling mode
`outbound_registration`, registration observation `registered` and naturally
refreshed. No manual reload was issued.

RuntimeNode candidate set: `7322e6e1-8417-42ce-ad4f-4e7d25b23a3a`
(`draft` / `unobserved`, not eligible) and
`102d58ba-93ec-4601-a2a3-81f95801440f` (`active` / `ready`). Gap C remains
closed.

## Canonical identities

The proof Call is the second of two calls placed in this packet; see
Divergences. Both behaved identically.

| Fact | Value |
| --- | --- |
| Call | `2fba0fec-a89b-4d58-9f72-72d16191dd0d` |
| CallLeg | `153e081d-f4fc-454e-893e-a55f078e6246` |
| RouteDecision | `adee03f8-e653-4c80-bd76-0eafd0d27a21` |
| Outbound route | `761aa1e9-3e1e-418e-a650-f59e4c68d84d` |
| Originate RuntimeOperation | `870d961d8cf6dc2961e5e8b1372be356` |
| RuntimeNode (auto-selected) | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-153e081d-f4fc-454e-893e-a55f078e6246` |
| CallerIdentity | `f11a46e5-fbdc-4eb0-b28d-9c002491a80a` (`sip:utcp-v1@…`) |
| ExternalTrunk | `3a9bf028-ee87-4212-8870-a1851530fee4` |
| TrunkEndpoint | `ad7a95f4-388c-445e-9259-edd30b5137a2` |
| SIP Call-ID | `3d22be89-62f7-4310-84e7-f5015a4228cb` |

`runtime_node_id` was omitted from the request; the selected node is identical
on the CallLeg and the originate RuntimeOperation.

## Dialog state before the BYE

Captured from the live dialog store while answered:

```text
state           3 (DLG_STATE_CONFIRMED_NA — confirmed, no ACK observed)
ref             2
call-id         3d22be89-62f7-4310-84e7-f5015a4228cb
from_uri        sip:utcp-v1@10.42.0.190
caller.tag      2115912b-…      callee.tag  18d1d3ce-…
caller.contact  sip:asterisk@10.42.0.190:5060   socket udp:0.0.0.0:5062
caller.route_set  ""
callee.contact  sip:38.146.161.46:5060          socket udp:0.0.0.0:5060
callee.route_set  ""
lifetime        43200
variables       cseq_diff = 1
```

Both tags are present, so the in-dialog BYE carries a To-tag. The empty
`callee.route_set` confirms the provider holds no route set, so its BYE cannot
carry a Route header and `loose_route()` cannot succeed. `caller.contact` is
the managed runtime Contact and equals the managed Asterisk Pod IP
(`10.42.0.190`); the provider Contact is the other side.

## Proof timeline

```text
01:18:05.x  canonical POST /api/v1/calls (runtime_node_id omitted)
01:18:09.35 INVITE runtime -> Kamailio, source 10.42.0.190, receive_port 5062
01:18:09.77 dialog present in the live dialog store
01:18:33    provider answered naturally; CallLeg/Call answered_at set
01:18:40.36 dialog sampled state 3, ref 2
01:18:41.75 dialog sampled state 5 (DELETED), ref 1
01:18:41.80 provider BYE, source 38.146.161.46, receive_port 5060,
            R-URI sip:asterisk@192.168.254.124:1879 (NAT-rewritten binding)
01:18:41.81 route(MEDIA_DELETE) executed for the BYE
01:18:42    managed Asterisk ChannelDestroyed (call.leg.terminated)
01:18:45.56 dialog still present, state 5
01:18:46.84 dialog absent from the live dialog store
01:23:10    dialog store still empty; zero dlg_ontimeout occurrences
```

The `state 3 -> state 5` sample boundary and the BYE log line are 56 ms apart
across two clocks (host wall clock for the sampler, Pod log clock for
Kamailio) and are effectively simultaneous.

## dlg_manage BYE processing — the corrected criterion

The 2026-08-29 proof recorded the dialog stuck at `ostate: 3` until
`dlg_ontimeout()` reaped it 81.5 s after the BYE. Here the same topology and
the same no-Route provider BYE produce a `DLG_STATE_DELETED` transition at the
moment of the BYE.

Behavioral evidence, per the packet's non-log-string acceptance:

```text
dlg_manage present in the exact fallback branch    live effective config
BYE matched the tracked dialog                     is_known_dlg gate, same Call-ID
dialog lifecycle changed at the BYE                state 3 -> 5, ref 2 -> 1
dialog left the store without a timeout            absent 5.0 s after the BYE
no dlg_ontimeout for the Call-ID                   zero occurrences over 328 s
```

State 5 is `DLG_STATE_DELETED`, the terminal transition the dialog module
reaches from `DLG_EVENT_REQBYE`. Reaching it requires the BYE to have entered
the dialog module's sequential-request handling, which only `dlg_manage()`
provides on this branch. No warning was emitted for
`has no runtime target`, `trust query failed`, `relay_failed`, or
`kamailio_application_dialog_relay_failed`.

The removal window was observed to 328 s after the BYE, four times the 81.5 s
interval at which the previous run's timeout fired.

## Return-path predicates

```text
Route header on the BYE          ABSENT (callee.route_set empty)
loose_route()                    FALSE / no usable Route
provider ingress                 receive_port 5060, source 38.146.161.46
provider trust projection        MATCH (no trust-query warning; branch proceeded)
has_totag()                      TRUE (both dialog tags present)
is_known_dlg()                   TRUE (tracked dialog, same Call-ID)
dlg_set_ruri()                   SUCCESS (no "has no runtime target" warning)
resulting Request-URI            managed runtime Contact sip:asterisk@10.42.0.190:5060
provider Contact selected        NO
route(MEDIA_DELETE)              EXECUTED 6 ms after receipt
t_relay()                        SUCCESS
```

A BYE with no usable Route that failed the fallback predicates would fall
through to `sl_send_reply("404")` and never reach the runtime. Managed Asterisk
received it, so the fallback branch handled the request.

## Managed Asterisk receipt and canonical outcome

Runtime observations for the proof CallLeg:

```text
01:18:10  call.leg.offered     StasisStart
01:18:10  call.leg.answered    ChannelStateChange
01:18:42  call.leg.terminated  ChannelDestroyed
```

Canonical result:

```text
CallLeg observed_state   completed
Call observed_state      completed
termination_reason       remote
termination_party        remote
answered_at              2026-08-30 01:18:33+00   (preserved on Call and CallLeg)
terminated_at            2026-08-30 01:18:46+00
failure_class            (null)
failure_code             (null)
```

Only one RuntimeOperation existed for the Call, `call.leg.originate`. No
`call.hangup`, `call.leg.hangup`, or `call.leg.cancel_origination` was issued,
so ADR-030 remote classification was reached without local termination intent.
Gap D behaviour is unchanged.

## Regression checks

Managed Asterisk reported `0 active channels`, `0 active calls`,
`2 calls processed` — both proof calls fully cleaned up, no leaked channels.
Non-terminal Calls and CallLegs were `0` after completion, and the live dialog
store was empty.

Registration remained naturally healthy before and after
(`registered`, trunk `ready`, refreshed at 01:23:10 with no manual reload).
The selected RuntimeNode remained `active` / `ready`. CallerIdentity remained
`V1A Reproof Caller` / `sip:utcp-v1@…`, `active` and unmutated. The live dialog
recorded `cseq_diff = 1`, consistent with the established authenticated-retry
CSeq contract `4242 -> 4243`. The endpoint codec contract remained
`disallow=all` / `allow=ulaw`; no codec or RTPengine configuration was touched.

Gap F observation only: the effective `route[RUNTIME_EXTERNAL_TRUNK]` removes
`X-UTCP-Call-Leg-ID`, `X-UTCP-Route-Decision-ID`, and
`X-UTCP-Trunk-Endpoint-ID` before provider relay. No privileged packet capture
was added; Gap F remains a separate `PROOF_GAP_ONLY` item.

## Divergences

Two canonical Calls were placed instead of one. The first
(`b25d9f62-186a-4073-8162-91c4af145ea8`, CallLeg
`2ce90937-ef2a-45c7-89a9-841230face41`, Call-ID
`f929a68c-022d-43ea-9358-035cead25c14`) completed correctly as
`completed / remote / remote` with `answered_at` preserved, its BYE reached
managed Asterisk, `MEDIA_DELETE` ran, and its dialog was absent 21 s later with
no `dlg_ontimeout`. Its pre-BYE dialog snapshot was lost because the sampler
matched the JSON key `call_id` while Kamailio emits `call-id`, so the decisive
before/after dialog-state observation was not captured for that Call. The
sampler was corrected to store every raw response and one further Call was
placed. This is an observational tooling divergence, not a runtime difference;
both Calls produced the same canonical and signaling outcome. It does not
invalidate the principal claim.

No environment or topology change occurred. No repository implementation was
changed by this proof. No provider, router, codec, RTPengine, placement,
termination, or ADR behavior was modified. ADR-031 stable-public-edge live
acceptance remains `DEFERRED_BY_ENVIRONMENT`, not abandoned; this
registration/NAT corridor is the current V1 acceptance path.
