# V1 Outbound Routing and Call Execution Boundary Proof

## Status

`V1_OUTBOUND_ROUTE_DECISION_PASSED_FOUND_CALL_EXECUTION_PRODUCT_DEFECT`

C7B route selection works against the real registered ExternalTrunk. No canonical
execution path exists from a `RouteDecision` into provider signaling, so no
INVITE was ever emitted toward the external PBX.

V1 remains active. V1-B was not activated. No repository code was changed.

## Live ExternalTrunk state (verified before and after)

```text
external_trunk_id  f944ceb3-6a8a-44a3-b776-e9db1ad6c1fc   desired_state active  cv 5
trunk_endpoint_id  f86a012f-b6c8-4ff6-8139-7f9cc80df107   outbound_registration / udp
registration       registered   observed_health ready (registration_endpoint_registered)
routingEligibility outbound eligible / inbound eligible
uac_reg            flags 20 = INIT|ONLINE
```

## Canonical call initiation surface

Established from focused repository evidence, then used unchanged:

```text
POST /api/v1/calls   {direction: outbound, runtime_node_id?, destination_ref}
  -> CallDomainService::createOutboundCall()
  -> runtime_operations row  call.leg.originate  (aggregate call_leg)
  -> C6 runtime adapter selected from runtime_node_id
```

`POST /api/v1/calls/{call}/legs` is the same path for additional legs. There is
no other production call-origination interface.

## C7B outbound route (created through the Admin API)

Destination `97001` is not valid E.164, so its canonical representation is a
`sip_uri` telephony address.

```text
telephony_address (destination)  857f1a43-330f-449f-90a6-f62bba7fe275  sip:97001@38.146.161.46  active
telephony_address (caller)       32580f24-cc25-4ec7-99f9-326c44cd2df6  sip:utcp-v1@38.146.161.46 active
external_trunk_addresses         destination attached to the lab trunk, direction outbound
caller_identity                  e9b9027d-d095-4374-af50-55706a283915  "V1 Lab Caller"  active
caller_identity_policy           active, external_trunk_id f944ceb3-...
outbound_route                   4376db8d-7afe-43d7-9c01-98d7abe5f6ff  slug v1-lab-97001
                                 priority 10, desired_state active
```

No SQL, no Kamailio-side routing, no provider-side selection.

## RouteDecision

`C7bService::evaluateOutbound()` selects the lab trunk through normal C7B
authority:

```json
{"direction":"outbound","status":"selected",
 "route_id":"4376db8d-7afe-43d7-9c01-98d7abe5f6ff",
 "external_trunk_id":"f944ceb3-6a8a-44a3-b776-e9db1ad6c1fc",
 "constraints":["route_active","external_trunk_eligible","caller_identity_authorized"]}
```

`evaluateOutbound()` has **no production caller**. Repository-wide it is invoked
only from `C7bRouteAuthorityTest` and `T6ExternalTrunkProjectionTest`;
`AdminC7bController` exposes list/create/desired-state only. Nothing in the Call
domain, command worker, reconciler, or any runtime adapter references
`external_trunk`.

## PRODUCT_DEFECT-V1-5 — no execution path from RouteDecision into provider signaling

Two bounded canonical calls were placed to
`destination_ref = telephony_address:857f1a43-...`.

Call A, no `runtime_node_id` (the natural C7B-style request):

```text
call 9e8f44e4-1ab9-448b-906f-6990d4f938ed   HTTP 201, state originating
operation call.leg.originate  runtime_node_id (null)
  status              terminal_failed
  last_failure_class  unsupported_capability
  last_failure_code   call_adapter_not_registered
  last_failure_message "C6 operations require a registered call-capable runtime adapter"
call terminal: observed_state failed, termination_reason origination_failed
```

Call B, explicit call-origination-capable RuntimeNode
`8fe47ee8-de7a-4b9e-837c-e27806bb8e22` (managed FreeSWITCH):

```text
call 387cf7b1-0595-4cb1-88fd-5bb967d76d25   HTTP 201, state originating
operation call.leg.originate  status succeeded (attempt 1)
  payload carried destination_ref verbatim
call terminal: observed_state failed, termination_reason origination_timeout
```

Neither adapter reads the destination:

```text
grep -n "destination" app/RuntimeAdapters/FreeSwitch/FreeSwitchRuntimeAdapter.php  -> no matches
grep -n "destination" app/RuntimeAdapters/Asterisk/AsteriskRuntimeAdapter.php      -> no matches
```

`CallDomainService::createOutboundCall()` stores `destination_ref` as an opaque
string and forwards it into a RuntimeNode-targeted `call.leg.originate`
operation. It never calls C7B, never produces a `RouteDecision`, and cannot
address an ExternalTrunk. The C6 handler requires a `RuntimeAdapter` resolved
from `runtime_node_id` and has no external-trunk branch.

The missing seam is precisely:

```text
Call / CallLeg origination
    -> C7bService::evaluateOutbound()  (RouteDecision)
    -> ExternalTrunk provider execution (Kamailio external-trunk signaling)
```

## SIP wire evidence

Captured in the Kamailio Pod's node network namespace for the whole call window
(11:40:42 - 11:46):

```text
REGISTER sip:38.146.161.46  x8      (ordinary registration refresh)
SIP/2.0 401 Unauthorized    x4
SIP/2.0 200 OK              x4
INVITE                      x0
```

`invite_count=0`. The Kamailio external-trunk handler was never reached, so the
prior "route matched" provider seam could not be exercised or disproven at this
level.

## External PBX

```text
pjsip show contacts / active channels : contact bound, 0 active channels
messages.log                          : no entry after the operator PJSIP reload at 10:51:19
```

Asterisk received nothing. Dialplan `97001` was never entered.

Furthest point reached: **before A** — INVITE not received.

## Media / dialog

None. No dialog, no SDP, no RTP, no Echo, no Hangup. Nothing is claimed.

## Secondary live finding — registration observation is reset by reprojection

While evaluating the RouteDecision repeatedly, 6 of 10 consecutive evaluations
returned `no_eligible_route`:

```text
11:38:56 failed  no_eligible_route  | obs=not_configured health=ready
11:39:08 selected route=4376db8d... | obs=registered      health=ready
11:39:20 failed  no_eligible_route  | obs=not_configured health=unknown
11:39:24 failed  no_eligible_route  | obs=not_configured health=unavailable
```

Mechanism, proven from live state:

1. Every C7A/C7B mutation emits a `c7a_authority` / `c7b_route` outbox message.
2. `T6ProjectionDispatcher::dispatch()` runs `projectTenant()` then
   `KamailioRegistrationControlClient::reconcile()`.
3. `reconcile()` calls `uac.reg_reload`, which Kamailio rate-limits to once per
   150 s (`uac_reg_ht_shift(): shifting in-memory table is not possible in less
   than 150 secs`). Most calls raise
   `Kamailio registration control request failed.`
4. The outbox message never completes and retries with backoff:

```text
c7a_authority  dispatched 339  retry_scheduled 4   max attempt_count 56
c7b_route      dispatched  78  retry_scheduled 2   max attempt_count 55
pending: caller_identity.created, external_trunk.address_attached,
         outbound_route.created, outbound_route.desired_state_changed, ...
         attempt_count 7, last_failure "Kamailio registration control request failed."
```

5. Every retry re-runs `projectKamailioRegistrations()`, whose trailing
   `updateOrInsert` unconditionally rewrites the **observed**
   `external_trunk_registration_observations.state` to a desired-state-derived
   `not_configured`.
6. The observer restores `registered` within ~5 s and the health reconciler
   follows, but in between `observed_health` degrades to `unknown` /
   `unavailable` and C7B returns `no_eligible_route`.

The SIP registration was continuously online throughout (`uac_reg flags=20`).
Two bounded seams: projection must not clobber observed state, and the 150 s
reload rate-limit must not be treated as a dispatch failure.

## Registration regression

Registration remained healthy for the entire proof — four full
`REGISTER -> 401 -> REGISTER+Digest -> 200 OK` refresh cycles inside the call
window, ending:

```text
uac_reg flags=20 online=yes
obs=registered  health=ready  last_success 2026-08-25 11:45:04+00
```

No forced refresh, no credential rotation.

## Runtime regression

```text
kamailio, kamailio-registration-observer (2), gateway, api, web, worker  Running
postgres-0, redis-0                                                     1/1 Running
make kamailio-signaling-config-check                pass
make kamailio-signaling-registration-runtime-proof  pass
make security-config-check                          pass
make repository-hygiene                             pass
make secret-scan                                    pass
git diff --check                                    clean
host 5060 publications                              0
```

## Retained state

The lab trunk, its registration, the destination/caller addresses, caller
identity, policy, and outbound route `v1-lab-97001` are all retained and active
so the execution seam can be proven immediately once implemented. Both proof
calls reached terminal state on their own; no cleanup was required.
