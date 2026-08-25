# T6 — Asterisk External-Trunk Provider Consumption Slice

## Status

The Asterisk provider-consumption seam is implemented and repository/provider
tested. Together with the completed Kamailio provider-consumption slice, this
meets the current T6 provider-target requirement. V1 natural external SIP
calling remains the next phase.

## Authority and selected provider seam

C7A and C7B remain the only editable canonical authorities. T6 renders their
state into replaceable provider artifacts; Asterisk consumes a provider-local
representation derived from those artifacts. The repository's managed
Asterisk seam is file-backed PJSIP configuration, not ARA/realtime: the
existing image copies its managed configuration into the Asterisk config
directory and starts Asterisk in the foreground. This slice therefore uses
that established lifecycle and does not add realtime tables, Asterisk CRUD,
or a second provider framework.

The representation is rendered by
`AsteriskExternalTrunkProjection` inside
`ExternalTrunkProjectionService`. It contains a stable endpoint ID and AOR ID
derived from the canonical trunk's stable provider ID, PJSIP endpoint/AOR
material, canonical route correlations, and credential reference/version
metadata. It contains no credential plaintext and is not an editable
management resource.

```text
C7A/C7B canonical state
    -> generic T6 projection artifact
    -> derived Asterisk provider_representation
    -> managed file-backed PJSIP configuration
    -> running Asterisk provider consumption
```

## Route and identity preservation

The provider representation preserves the canonical route ID, ExternalTrunk
ID, normalized address, DestinationRef, and CallerIdentity correlation. T6
consumes an already-selected RouteDecision for outbound intent; Asterisk does
not run C7B evaluation, choose another trunk, or substitute caller identity.
Inbound projection retains the canonical trunk/address correlation needed for
the later signaling-to-C7B boundary. Full inbound Call lifecycle and external
SIP acceptance remain V1.

## Credential authority and rotation

T6 consumes the current C7A endpoint credential reference and version. The
canonical C7A rotation transaction atomically rebinds active endpoints to the
replacement/current reference and retires the old authority before the normal
outbox projection. T6 performs no newest-reference search, stale-reference
repair, or fallback. Generic artifacts and the Asterisk representation carry
reference/version metadata only; secret material is not placed in the public
API, generic JSON, evidence, or logs.

## Lifecycle and idempotency

Active authority renders usable PJSIP material. Disabled, retired, and other
non-projectable states render a removed artifact with a null Asterisk
representation, so the provider-active representation cannot remain usable
through stale canonical state. Reactivation automatically restores the same
stable provider IDs. Repeated projection produces the same effective
representation without duplicate provider objects or a manual push operation.

## Verification

Static provider validation:

```text
make asterisk-external-trunk-config-check
asterisk_external_trunk_config_check=pass seam=file-backed-pjsip projection=derived provider=asterisk
```

The bounded runtime proof uses the normal authenticated Admin API to create a
synthetic tenant-scoped address, trunk, credential reference, endpoint,
inbound association, and route. It waits for automatic outbox/T6 convergence,
extracts only the derived Asterisk representation, starts an actual
`utcp-asterisk-ari:dev` process with disposable PJSIP configuration, and
verifies `pjsip show endpoint` sees the stable provider endpoint. It then
disables the canonical trunk and proves the endpoint is absent, reactivates
through the same canonical API, and proves automatic restoration:

```text
asterisk_runtime_active=pass
asterisk_runtime_disabled=pass
asterisk_runtime_reactivated=pass
asterisk_external_trunk_runtime_proof=pass
```

The completed Kamailio provider-consumption slice remains covered by its
existing view, SQLOps, and synthetic runtime proof. The known unrelated
secondary-Asterisk mutation-test divergence remains historical/pre-existing
and is not changed by this slice.

## Explicit non-goals

This slice does not add ARA/realtime authority, Asterisk provider CRUD, an
external PBX or SIP carrier, V1 natural external calling, Traefik/Kubernetes
telephony policy, K5, C8, recording, or ADR-026 media/AI functionality.
