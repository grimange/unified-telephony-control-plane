# RMA-A Asterisk PJSIP Transaction Capture Reproof

Current-State-Impact: yes

**Date:** 2026-09-01
**Repository:** `main` at `87f754e517b4d2a2b82c83db07800608ceffe942`
**Environment:** native k3s, context `default`, `utcp-dev01`

## Verdict

`RMA_A_ASTERISK_CANONICAL_SIP_DELIVERY_SYNCHRONIZED_LIVE_TRACE_INCONCLUSIVE_DIAGNOSTIC_CAPTURE_GAP`

The requested logger capability was established before the fresh canonical
reproof: `res_pjsip_logger.so` was loaded normally and reported `Running` on
both candidate Asterisk processes, and `pjsip set logger on` succeeded on both.
However, the TTY-backed remote-console capture sessions ended at
`07:33:13Z` and `07:33:19Z`, before the fresh API request at
`07:33:21.127Z`. They consequently contain no SIP frame for the transaction.
The transaction therefore does not identify the first SIP delivery failure
boundary, and no routing, PJSIP, Service, dialplan, or RecordingSession repair
is justified by this run.

## Baseline and topology

The accepted `9900` fixture remained loaded on the selected Asterisk backend:

```text
9900 => NoOp(UTCP local T3-S2A media fixture)
        Answer()
        Echo()
        Hangup()
```

The generic `_.` rejection route remained present in `from-kamailio`.

At trace time, `Service/utcp-runtime/asterisk-sip` had ClusterIP
`10.43.190.58`, selector
`app.kubernetes.io/component=asterisk-ari` plus
`utcp.dev/runtime-node=local-asterisk-ari`, and `UDP/5060 -> sip`. Its sole
ready EndpointSlice address was `10.42.0.44:5060`,
`Pod/asterisk-ari-7c6d4d4868-xq8ff` on `utcp-dev01`. The distinct outbound
proof Asterisk Pod was `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-6864fkk5mf`
at `10.42.0.43`; it was not selected by the Service.

Both Asterisk processes were version `20.20.1` and used
`ghcr.io/grimange/utcp-asterisk@sha256:1865f34b6887b967b61505af4416d6dbdc9c3123dca7d6bfd20db57ff7fdb1fc`.
The `anonymous` endpoint and `from-kamailio` context were present on the
selected backend.

## Diagnostic capability and cleanup

`module show like res_pjsip_logger.so` initially showed the module unloaded on
both processes. A normal runtime `module load res_pjsip_logger.so` succeeded;
subsequent inspection reported:

```text
res_pjsip_logger.so  PJSIP Packet Logger  0  Running  core
```

`pjsip set logger on` then returned `PJSIP Logging enabled` on both processes.
Temporary core debug was raised from `0` to `4`. After the attempt,
`pjsip set logger off` succeeded on both and debug was restored to `0`.
The module was deliberately left loaded, with logging disabled: no forced
unload was used.

## Fresh canonical transaction

The reproof used only `POST /api/v1/calls` with the maintained destination.

| Fact | Value |
| --- | --- |
| API request timestamp | `2026-09-01T07:33:21.127Z` |
| HTTP result | `201 Created` |
| Call | `6cb3ab1a-effc-43cc-bf90-cc169a423a0a` |
| CallLeg | `cdbcd913-a5ec-4daf-ac6a-5a3ad40e212c` |
| RuntimeNode | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Runtime channel | `utcp-call-leg-cdbcd913-a5ec-4daf-ac6a-5a3ad40e212c` |
| Originate RuntimeOperation | `9abb07503c980ca229e36fbee74b5c1e` |
| Destination | `sip:anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060` |
| Originate execution | started `07:33:22Z`, completed `07:33:23Z`, `succeeded`, one attempt |
| Runtime termination observation | `07:33:53Z` |
| Canonical terminal projection | `07:33:58Z`, `failed`, reason `remote` |

The repository contract constructs `POST /ari/channels` with endpoint
`PJSIP/anonymous/sip:9900@asterisk-sip.utcp-runtime.svc.cluster.local:5060`,
the listed UTCP channel ID, configured Stasis application, 30-second timeout,
configured media formats, and trusted correlation variables. The durable
operation outcome proves ARI accepted origination; it does not prove an INVITE,
Service delivery, endpoint identification, dialplan execution, or answer.

## Capture limitation and conclusion

The base Asterisk console capture existed from `07:31:11Z` to `07:33:13Z`; the
outbound Asterisk capture existed from `07:31:17Z` to `07:33:19Z`. The fresh
canonical request was made after both timeouts. Their contents consist only of
Asterisk connection banners, not an INVITE, SIP Call-ID, response, inbound
receipt, endpoint/context selection, `9900` execution, or a `200 OK`.

The authoritative transaction again shows successful ARI origination followed
by a remote termination, with no canonical `answered` observation. It excludes
a pre-ARI failure and fixture absence, but it does not distinguish an
originating PJSIP execution failure from SIP delivery, destination processing,
or return-path failure. Recording reconciliation smoke was not reached because
the CallLeg never became `answered`.

## Validation and scope

No application, Kubernetes, PJSIP, dialplan, RecordingSession, or lifecycle
source was changed. The evidence/status packet ran
`make phase-status-consistency-check`, `make repository-hygiene`, and
`git diff --check`. The known unrelated `make asterisk-ari-config-check`
generic Asterisk-branch scan remains outside this evidence-only task.

The next trace must keep a verified TTY-backed console or another supported
SIP capture active through the API request and the full originate timeout
window before creating its one fresh canonical Call.
