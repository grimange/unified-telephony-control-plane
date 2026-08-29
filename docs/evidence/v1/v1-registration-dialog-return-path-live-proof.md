# V1 Registration/NAT Dialog Return-Path Controlled Live Proof

Current-State-Impact: yes

Date: 2026-08-29

Exact HEAD: `441f15ffa68cb55b04be383a77fe5cd416e453e8`

## Verdict

`V1_REGISTRATION_DIALOG_RETURN_PATH_PROVEN_DIALOG_CLEANUP_REGRESSION`

The registration/NAT provider-dialog return corridor is **live-proven** end to
end: a provider-originated no-Route BYE returned through the existing
registration/NAT binding, matched the trusted active Kamailio dialog, was
retargeted to the managed runtime with `dlg_set_ruri()`, reached managed
Asterisk, and terminalized the canonical CallLeg as
`completed / remote / remote`.

Gap B does **not** close. One mandatory closure criterion failed: the proof
dialog did not close as a result of the BYE. It remained `CONFIRMED` and was
reaped by `dlg_ontimeout()` 81.5 s later.

## Environment

Native k3s, context `default`, node `utcp-dev01` (`192.168.254.124`).
k3d `utcp-local` remained stopped and non-canonical throughout.

Stable provider-facing SIP identity was absent for the whole proof:
`UTCP_SERVER_PROVIDER_SIP_ADDRESS` and `UTCP_SERVER_PROVIDER_SIP_PORT` unset.
`make server-config-check` passed in that state, confirming the
topology-coherence implementation is active at exact HEAD.

Deployment used only the canonical lifecycle: `server-config-check`,
`server-image-preflight`, `server-apply`, `server-status`. Kamailio restarted
once during rollout because PostgreSQL was momentarily unavailable while the
data StatefulSet rolled; Kubernetes' own restart resolved it. No manual
restart, patch, edit, or lower-level apply was used.

## Effective live Kamailio configuration

`route[RUNTIME_EXTERNAL_TRUNK]`: `dlg_manage()` PRESENT, provider-facing
`record_route()` ABSENT — the registration/NAT edge.

`route[WITHINDLG]`: `loose_route()` retains first authority. The fallback is
`$Rp == 5060 && $rm == "BYE" && has_totag() && is_known_dlg()`, then the
provider-source trust query against `kamailio_external_trunk_route_view`, then
`dlg_set_ruri()`, `route(MEDIA_DELETE)`, `t_relay()`.

Effective sockets: provider `udp:0.0.0.0:5060` advertising
`kamailio-sip-external.utcp-platform.svc.cluster.local:5060` (internal
advertise retained); runtime `udp:0.0.0.0:5062` advertising
`kamailio-sip-internal.utcp-platform.svc.cluster.local:5060`. No fabricated
public identity.

## Canonical identities

| Fact | Value |
| --- | --- |
| Call | `59b0580f-b635-4488-874b-014a4a071f1f` |
| CallLeg | `d389364b-eaf1-4d5e-af6d-c75eed3cadee` |
| RouteDecision | `2ac93045-0be5-4033-b530-3ea7a1d566c9` |
| Originate RuntimeOperation | `cfc495e4da2e13a8522aa34de109552b` |
| RuntimeNode (auto-selected) | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-d389364b-eaf1-4d5e-af6d-c75eed3cadee` |
| CallerIdentity | `f11a46e5-fbdc-4eb0-b28d-9c002491a80a` (`sip:utcp-v1@…`) |
| ExternalTrunk | `3a9bf028-ee87-4212-8870-a1851530fee4` |
| TrunkEndpoint | `ad7a95f4-388c-445e-9259-edd30b5137a2` |
| SIP Call-ID | `5a07aff9-d1d1-41ff-9573-df404c134a3d` |

`runtime_node_id` was omitted from the request. Deterministic placement chose
the only naturally eligible node; the second registered node was `draft` /
`unobserved` and was correctly not selected. Gap C remains closed.

## Live dialog observation

Captured from the live Kamailio dialog store while the call was answered:

```text
state           3 (CONFIRMED)
caller.contact  sip:asterisk@10.42.0.148:5060     socket udp:0.0.0.0:5062
caller.route_set  ""
callee.contact  sip:38.146.161.46:5060            socket udp:0.0.0.0:5060
callee.route_set  ""
lifetime        43200
variables       cseq_diff = 1
```

The empty `callee.route_set` is the direct live confirmation that the provider
holds no route set, so its in-dialog BYE cannot carry a Route header and
`loose_route()` cannot succeed. `caller.contact` is the managed runtime
Contact that `dlg_set_ruri()` must select; the provider Contact is the other
side and was not selected.

## Proof timeline

```text
22:50:49.812  canonical POST /api/v1/calls (runtime_node_id omitted)
22:50:50.844  INVITE runtime -> Kamailio, receive_port 5062
22:50:51      dialog created, state 3
22:50:59      provider answered naturally; CallLeg.answered_at set
22:51:01      Call answered
22:51:23.388  provider BYE, source 38.146.161.46, receive_port 5060,
              R-URI sip:asterisk@192.168.254.124:50447 (NAT-rewritten binding)
22:51:23.395  route(MEDIA_DELETE) executed for the BYE
22:51:23      managed Asterisk ChannelLeftBridge + ChannelDestroyed (both channels)
22:51:27      canonical CallLeg completed / remote / remote
22:52:44.936  dlg_ontimeout() reaped the dialog, ostate: 3
22:52:44.995  dialog absent from the live dialog store
```

The BYE arrived on the provider ingress socket with no Route header and was
handled by the fallback: `MEDIA_DELETE` ran 7 ms after receipt, and no
`relay_failed`, `has no runtime target`, or `trust query failed` warning was
emitted. Managed Asterisk receipt is proven independently by runtime
observations `ChannelDestroyed` and `ChannelLeftBridge`
(`ari_event_type` recorded) at the BYE instant — the historical Gap B boundary
where the BYE previously never reached the runtime.

## Canonical outcome

```text
observed_state      completed
termination_reason  remote
termination_party   remote
answered_at         2026-08-29 22:50:59+00   (preserved)
failure_class       (null)
failure_code        (null)
```

Only one RuntimeOperation existed for the Call, `call.leg.originate`. No
`call.hangup`, `call.leg.hangup`, or `call.leg.cancel_origination` was issued,
so ADR-030 remote classification was reached without local termination intent.

## Failed closure criterion

`dlg_ontimeout` was required for the proof dialog.

The dialog stayed in state 3 across the BYE and was cleared 81.5 s later by
`dlg_ontimeout()`. In the effective configuration `dlg_manage()` occurs exactly
once, in `route[RUNTIME_EXTERNAL_TRUNK]`; it is absent from `route[WITHINDLG]`.
The fallback therefore relays the BYE without engaging the dialog module's
sequential-request handling — `is_known_dlg()` matches the dialog for the trust
predicate and `dlg_set_ruri()` rewrites the Request-URI, but neither
transitions dialog state. Because `loose_route()` did not run, the dialog
module never processed the BYE as a terminating event.

This is materially narrower than the historical Gap B defect. Application and
runtime termination are correct and prompt; only Kamailio's own dialog
lifecycle lags. The residual cost is a stale in-memory dialog per
registration/NAT call until the dialog timer fires.

The observed ~113 s reap interval does not match the reported 43200 s dialog
lifetime. That discrepancy is recorded as an open detail for the follow-up
diagnosis and is not explained here.

## Regression checks

Registration remained naturally healthy before and after the call
(`registered`, identity `utcp-v1`, trunk `ready`). The selected RuntimeNode
remained `active` / `ready`. CallerIdentity remained `sip:utcp-v1@…`,
unmutated. The live dialog recorded `cseq_diff = 1`, consistent with the
established authenticated-retry CSeq contract. No codec or RTPengine
configuration was touched. After completion there were no non-terminal Calls or
CallLegs, no residual provider-side channels, and no remaining Kamailio dialog.

Gap F observation only: the effective `route[RUNTIME_EXTERNAL_TRUNK]` removes
`X-UTCP-Call-Leg-ID`, `X-UTCP-Route-Decision-ID`, and
`X-UTCP-Trunk-Endpoint-ID` before provider relay. No privileged packet capture
was added for Gap F, which remains a separate `PROOF_GAP_ONLY` item.

## Boundary

No repository implementation was changed by this proof. No provider, router,
codec, RTPengine, placement, termination, or ADR behavior was modified. ADR-031
stable-public-edge live acceptance remains `DEFERRED_BY_ENVIRONMENT`, not
abandoned; this registration/NAT corridor is the current V1 acceptance path.
