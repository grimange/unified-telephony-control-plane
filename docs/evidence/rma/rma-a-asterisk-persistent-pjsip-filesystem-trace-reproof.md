# RMA-A Persistent Asterisk PJSIP Filesystem Trace Reproof

Current-State-Impact: yes

**Date:** 2026-09-01
**Repository:** `main` at `a8ac4c4d3f4c49b7fe60c4f096842d195e915a83` before this evidence packet
**Environment:** native k3s, context `default`, `utcp-dev01`

## Verdict

`RMA_A_ASTERISK_CANONICAL_SIP_DELIVERY_BLOCKED_RUNTIME_NETWORKPOLICY_UDP_5060`

One fresh canonical transaction was captured through persistent, Asterisk-owned filesystem logger channels. ARI created the originating channel and Asterisk sent seven UDP INVITEs to `asterisk-sip`'s ClusterIP. The sole ready Service backend captured neither that SIP Call-ID nor the runtime channel, and the originator received no SIP response before its local 30-second no-answer termination (`tech_cause=480`). The deployed `utcp-runtime` NetworkPolicy contract default-denies both directions of this Asterisk-to-Asterisk UDP/5060 path: the selected backend admits only `utcp-platform` Kamailio sources and the dynamic originating Asterisk has no corresponding UDP/5060 egress allowance.

This is a bounded Kubernetes security-policy defect, not a RecordingSession, dialplan, endpoint-identification, ARI-originate, or canonical projection defect.

## Persistent diagnostic setup and cleanup

| Role | Pod / IP | Temporary logger channel and path |
| --- | --- | --- |
| Originator | `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6864fkk5mf` / `10.42.0.43` | `rma_a_sip_origin_20260901T074000Z.log` / `/var/log/asterisk/rma_a_sip_origin_20260901T074000Z.log` |
| Service backend | `asterisk-ari-7c6d4d4868-xq8ff` / `10.42.0.44` | `rma_a_sip_destination_20260901T074000Z.log` / `/var/log/asterisk/rma_a_sip_destination_20260901T074000Z.log` |

Both processes were Asterisk `20.20.1` on `ghcr.io/grimange/utcp-asterisk@sha256:1865f34b6887b967b61505af4416d6dbdc9c3123dca7d6bfd20db57ff7fdb1fc`. `res_pjsip_logger.so` was already `Running` on both. Each temporary channel was created with `notice,warning,error,debug,verbose,dtmf`; its file existed and received Asterisk output before the API request. Immediately before the call, the origin and destination files were respectively `348701` and `67487` bytes and had just advanced timestamps. `pjsip set logger on` succeeded on both, with bounded debug/verbose enabled.

After the full call window and evidence retrieval, `pjsip set logger off`, `core set debug 0`, and `core set verbose 0` each succeeded on both processes. The two temporary logger channels were then removed normally. No module was force-unloaded, no workload was restarted, and no permanent logging configuration changed.

## Kubernetes topology and policy authority

`Service/utcp-runtime/asterisk-sip` was ClusterIP `10.43.190.58`, `UDP/5060 -> targetPort sip`, with selector:

```text
app.kubernetes.io/component=asterisk-ari
utcp.dev/runtime-node=local-asterisk-ari
```

Its sole ready EndpointSlice address was `10.42.0.44:5060`, the destination Pod above on `utcp-dev01`. The originator is also in `utcp-runtime`, has `app.kubernetes.io/component=asterisk-ari` and `utcp.io/network-role=asterisk-ari`, but a distinct dynamic `utcp.dev/runtime-node=v1a-outbound-reproof-asterisk-1787825256`; it is not a Service backend.

The deployed `default-deny` selects every `utcp-runtime` Pod for ingress and egress. The repository-owned `infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml` selects the destination Asterisk role but permits UDP/5060 ingress only from the `utcp-platform` `kamailio-signaling` role. It supplies no same-namespace Asterisk source rule. The dynamic originator is therefore excluded from that ingress rule; it is also selected by default-deny with no UDP/5060 egress allowance to the destination Asterisk role. This exactly matches the observed absence of destination receipt.

The repaired `from-kamailio,9900` route remained loaded on the selected backend as `NoOp -> Answer -> Echo -> Hangup`, with the generic reject route still present. It was not exercised because the INVITE never reached the Pod.

## Fresh canonical transaction

Only the supported `POST /api/v1/calls` authority was used.

| Fact | Value |
| --- | --- |
| API request / response | `2026-09-01T08:30:46.531Z` / `201 Created` |
| Call | `a913bf6e-6569-4615-8774-f9ad76d0fda1` |
| CallLeg | `0128a054-8f77-4a0c-a6a6-f9173c7adfbc` |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-0128a054-8f77-4a0c-a6a6-f9173c7adfbc` |
| Originate RuntimeOperation | `94ec430f8bd0f1282446eff8c52a5685` |
| Destination | `sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060` |
| Originate operation | one attempt; started and succeeded at `08:30:47Z` |
| Canonical terminal projection | `08:31:23Z`: Call and CallLeg `failed`, reason `remote` |

The captured ARI request was `POST /ari/channels` with `endpoint=PJSIP/anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060`, `app=utcp-t0-observation`, `timeout=30`, `channelId=utcp-call-leg-0128a054-8f77-4a0c-a6a6-f9173c7adfbc`, and `formats=ulaw`. The persisted operation and `runtime_operation.asterisk_call_executed` outbox event prove ARI accepted the channel creation at `08:30:47Z`.

## Synchronized Asterisk and SIP transaction

The originator created `PJSIP/anonymous-00000007`, entered the configured Stasis application with the UTCP runtime-channel identifier, and emitted SIP Call-ID `adf98382-66cd-4d8f-806d-a172339ba99b`.

At `08:30:47Z` it resolved the Service name to `10.43.190.58:5060/UDP` and sent:

```text
INVITE sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060 SIP/2.0
Call-ID: adf98382-66cd-4d8f-806d-a172339ba99b
```

The persistent origin file records retransmissions at `08:30:48Z`, `:49Z`, `:51Z`, `:55Z`, `08:31:03Z`, and `:19Z`: seven transmissions in total. It contains no received SIP response for that Call-ID. The destination file, active for the same interval, contains neither that Call-ID nor the runtime channel and no inbound PJSIP channel or dialplan execution. Consequently there is no destination endpoint identification, no `from-kamailio` context entry, no `9900`, no `Answer()`, no `Echo()`, no `200 OK`, and no ACK.

At `08:31:17Z` the originator locally destroyed the unanswered channel with `Cause: 19`, `Tech Cause: 480`, and ARI emitted `ChannelDestroyed` with `cause_txt: User alerting, no answer`. This is the local timeout result, not an observed destination SIP response. The event normalizer correctly projected the terminal remote failure; it had no answered event to project. No RecordingSession was requested, so recording reconciliation smoke was correctly not reached.

## Root cause, scope, and next action

The first failing boundary is the repository-owned native-k3s NetworkPolicy contract for an Asterisk RuntimeNode originating a canonical call to the `asterisk-sip` Service. The smallest repair target is `infrastructure/kubernetes/security/runtime/allow-asterisk-sip-from-kamailio.yaml`: add narrowly scoped UDP/5060 ingress and egress authorization between same-namespace Pods bearing the existing `asterisk-ari` role, while preserving the current Kamailio and RTP restrictions. The implementation must add focused policy/render coverage and then be live re-proven with a fresh canonical Call.

Excluded by the persistent evidence: ARI originate failure, destination URI normalization failure, DNS resolution failure, missing Service endpoint, fixture materialization, missing `9900`, incorrect `Answer -> Echo` ordering, and canonical answered projection failure. RecordingSession lifecycle, Asterisk channel recording, FreeSWITCH, bridge semantics, storage, identity, and authorization were untouched.

No application source or Kubernetes configuration was changed in this audit. The known unrelated `make asterisk-ari-config-check` generic Asterisk-branch scan remains outside scope.
