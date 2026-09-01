# RMA-A Native-k3s Asterisk Echo Fixture Materialization Repair

Current-State-Impact: yes

**Date:** 2026-09-01
**Repository:** `main` at `6bee8f4d6898bca9b2394224c03d0e041b94d576` before implementation
**Environment:** native k3s, context `default`, `utcp-dev01`

## Verdict

`RMA_A_ASTERISK_ECHO_FIXTURE_LOADED_BUT_CALL_NOT_ANSWERED`

The repository repair materialized the canonical fixture ConfigMap and loaded
the `9900` Answer-to-Echo route in the canonical Asterisk Service endpoint. A
fresh canonical Call still did not produce a live Asterisk channel or canonical
`answered` observation; it terminated remotely after originate. The fixture
boundary is repaired, but complete natural-live acceptance remains blocked by
this separate SIP/runtime path.

## Contract and authority

Installing `Echo()` does not create extension `9900`, and `Echo()` does not
answer a channel. The repository-owned source is
`infrastructure/kubernetes/components/asterisk-sip-fixtures/extensions.local.conf`;
the server overlay now includes that existing component. Base Asterisk config
already `tryinclude`s the projected fragment.

## Implementation

* `infrastructure/kubernetes/overlays/server/kustomization.yaml` includes
  `asterisk-sip-fixtures`.
* `scripts/native-k3s/config-check` validates one fixture ConfigMap, one `9900`
  route with `Answer()` preceding `Echo()`, and the canonical Deployment's
  ConfigMap volume reference.

The mount remains optional, preserving the distinction between runtime
readiness and an acceptance capability. No image changed; the existing
Asterisk digest remained in use.

## Deployment and runtime evidence

`make server-apply` converged the native-k3s platform. ConfigMap
`utcp-runtime/asterisk-local-sip-fixtures` was created at `2026-09-01T06:00:36Z`.
After repository-managed Deployment restarts, both the canonical `asterisk-ari`
Pod and outbound proof Pod projected the fixture and generated
`/tmp/utcp-asterisk/extensions.local.conf`. The Asterisk image remained
`ghcr.io/grimange/utcp-asterisk@sha256:1865f34b6887b967b61505af4416d6dbdc9c3123dca7d6bfd20db57ff7fdb1fc`.

`dialplan show 9900@from-kamailio` reported:

```text
'9900' => 1. NoOp(UTCP local T3-S2A media fixture)
           2. Answer()
           3. Echo()
           4. Hangup()
'_.'  => 1. NoOp(... rejected destination=${EXTEN})
           2. Hangup(21)
```

This proves the effective `9900 -> Answer -> Echo` path and preservation of
the reject boundary.

## Natural call check

Fresh canonical Call `3dd7d472-1977-483c-a826-8d2282cfad1b` used the supported
API and destination. Its CallLeg was `3efcd97d-854c-4288-a3a2-3303595e711c`;
originate succeeded, but the leg remained `originating` and became `failed`
with remote termination. A second fresh Call
`2cc6a253-c668-4975-84b7-8e1b024ec1ed` showed the same result. Read-only
`core show channels verbose` during the second attempt reported zero active
channels, and the canonical Asterisk Pod logs contained no inbound call.
Natural answer is therefore not proven and no recording operation was invoked.

## Validation and scope

Passed: `make server-config-check`, `make server-image-preflight`,
`make kamailio-signaling-config-check-test`, `make phase-status-consistency-check`,
`make repository-hygiene`, and `git diff --check`.

`make asterisk-ari-config-check` remains a pre-existing unrelated failure: its
generic scan flags the existing Asterisk-specific branch in
`apps/api/app/TelephonyDomain/CallDomainService.php`.

RecordingSession/application source, ARI recording behavior, FreeSWITCH,
storage, identity, authorization, and unrelated roadmap work were untouched.
The next action is a narrow Terra operational diagnosis of why canonical SIP
originate produces no Asterisk channel after the fixture is loaded.
