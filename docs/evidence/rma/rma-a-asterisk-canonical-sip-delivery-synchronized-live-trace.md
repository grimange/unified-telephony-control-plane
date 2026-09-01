# RMA-A Asterisk Canonical SIP Delivery Synchronized Live Trace

Current-State-Impact: yes

**Date:** 2026-09-01
**Repository:** `main` at `5444c8f57a92fd46fd7f126de4cc4a4db7523310`
**Environment:** native k3s, context `default`, `utcp-dev01`

## Verdict

`RMA_A_ASTERISK_CANONICAL_SIP_DELIVERY_SYNCHRONIZED_LIVE_TRACE_INCONCLUSIVE_DIAGNOSTIC_CAPTURE_GAP`

The fresh canonical originate was accepted and its ARI operation succeeded, but
the synchronized capture did not contain SIP frames. The supplied Asterisk
diagnostic command was unavailable because `res_pjsip_logger.so` existed in
both runtime images but was not loaded. The capture therefore cannot distinguish
no outbound INVITE from Service delivery, destination endpoint, dialplan, or
SIP return-path failure. No routing or application repair is justified.

## Repository and established fixture state

The worktree was clean and `HEAD` equalled `origin/main` at
`5444c8f57a92fd46fd7f126de4cc4a4db7523310`. The accepted fixture remains
present and loaded on both live Asterisk Pods. On each, Asterisk reported:

```text
9900 => 1. NoOp(UTCP local T3-S2A media fixture)
        2. Answer()
        3. Echo()
        4. Hangup()
```

The `anonymous` PJSIP endpoint still selects `from-kamailio`; the generic `_.`
rejection route remains in that context. This trace found no evidence that
fixture materialization, `Answer()`, or the rejection boundary regressed.

## Kubernetes SIP topology

`Service/utcp-runtime/asterisk-sip` has ClusterIP `10.43.190.58` and a single
UDP `5060` port with targetPort `sip`. Its selector is:

```text
app.kubernetes.io/component=asterisk-ari
utcp.dev/runtime-node=local-asterisk-ari
```

The ready EndpointSlice address was `10.42.0.44`, targetRef
`Pod/asterisk-ari-7c6d4d4868-xq8ff`. That Pod is the sole Service backend. The
separately deployed outbound Asterisk Pod
`asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6864fkk5mf`
(`10.42.0.43`) is not selected by this Service. Both Pods used
`ghcr.io/grimange/utcp-asterisk@sha256:1865f34b6887b967b61505af4416d6dbdc9c3123dca7d6bfd20db57ff7fdb1fc`.

## Fresh canonical transaction

The main synchronized transaction was created only through `POST /api/v1/calls`:

| Fact | Value |
| --- | --- |
| Call | `86db1460-abd6-4154-b4da-f443f3f20043` |
| CallLeg | `cc6d28ac-5096-462d-babf-6f9431641550` |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-cc6d28ac-5096-462d-babf-6f9431641550` |
| Originate RuntimeOperation | `c81498640d14ab5d375bed6f5887f0b3` |
| Destination | `sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060` |
| API create window | `07:07:53.946Z`–`07:07:54.674Z` |
| Operation execution | started and completed `07:07:57Z`, `succeeded`, one attempt |
| Provider termination observation | `07:08:27Z` |
| Canonical terminal projection | `07:08:33Z`, `failed`, reason `remote` |

Repository source constructs the accepted ARI request as `POST /ari/channels`
with endpoint
`PJSIP/anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060`,
channelId `utcp-call-leg-cc6d28ac-5096-462d-babf-6f9431641550`, the configured
Stasis application, a 30-second timeout, configured media formats, and trusted
correlation variables. The persisted operation result proves ARI accepted
origination; it does not prove a SIP INVITE, Service delivery, or answer.

## Synchronized diagnostics and exact gap

Before submitting the Call, capture followers were armed for both Asterisk
Pods, the command worker, ARI listener, and event normalizer, together with
short-interval `core show channels concise` samples. Both Asterisks were
temporarily changed from verbosity/debug `0/0` to `4/4` and restored to `0/0`
after the transaction.

The requested `pjsip set logger on` command failed on both instances:

```text
No such command 'pjsip set logger on'
```

Read-only inspection then established the exact diagnostic condition:

```text
/usr/lib/asterisk/modules/res_pjsip_logger.so exists
module show like pjsip does not list res_pjsip_logger.so
```

The synchronous Pod streams contained no INVITE, SIP Call-ID, Request-URI,
response, inbound receipt, context, or dialplan execution record for the Call;
the channel samplers did not capture an active channel. Since ARI channel
creation is immediate and a later empty sample only establishes absence at the
sample instant, neither observation disproves the successful originate.

The only normalizer record during the capture was an unrelated runtime-info
event. The canonical timeline contains `call.leg.terminated`, but no `offered`,
`answered`, `bridged`, or recording observation.

## Authority and conclusion

The unresolved boundary remains between ARI originate acceptance and canonical
answer. Kubernetes Service composition is now precisely known, and the
fixture/dialplan is loaded at its sole backend, but this evidence does **not**
show whether an INVITE was emitted or received. It therefore excludes neither
an originating PJSIP execution failure nor a downstream delivery/return-path
failure. It excludes a pre-ARI operation failure, fixture absence, and a
canonical answered-projection defect as the first proven failure.

No source, Kubernetes selector, PJSIP endpoint, dialplan, RecordingSession, or
canonical lifecycle state was changed. The exact next proof prerequisite is to
temporarily load the already-present `res_pjsip_logger.so` on the involved
Asterisk instances, enable its logger, perform one new canonical trace, and
unload/disable it afterwards. That is operational evidence collection, not a
product repair.

## Validation

This evidence/status-only packet ran `make phase-status-consistency-check`,
`make repository-hygiene`, and `git diff --check`. The known unrelated
`make asterisk-ari-config-check` generic Asterisk-branch scan was not changed
or rerun as a result of this trace.
