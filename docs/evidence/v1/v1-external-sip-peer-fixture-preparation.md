# V1 External SIP Peer Fixture Preparation

Status: implementation/preparation complete; V1 remains active.

## Role and boundary

The repository now contains a disposable `External SIP Peer` fixture under
`infrastructure/test-fixtures/external-sip-peer/`. Its implementation uses the
pinned repository Asterisk image, but its architectural role is an independent
black-box SIP peer. It is test infrastructure, not an Asterisk `RuntimeNode`,
not a T6 provider target, and not a product-managed PBX.

The fixture is deliberately outside the UTCP product workload and is started
only by the V1 preparation/smoke harness. It has no knowledge of UTCP UUIDs,
route IDs, RuntimeNodes, or projection IDs. It accepts ephemeral credentials at
startup and generates its PJSIP configuration in its disposable filesystem.

## Fixture behavior

The peer exposes a minimal UDP SIP transport, a credential-protected peer
endpoint, deterministic inbound/outbound dialplan markers, automatic answer,
a bounded five-second hold, and hangup. No production credential, public DID,
APNTalk resource, database, admin surface, or provider-management API is part
of the fixture.

The fixture image is built from the pinned `andrius/asterisk:20` image. The
configuration check rejects committed `pjsip.conf`, canonical UTCP authority
identifiers, APNTalk references, and committed secret material.

## Preparation harness

`scripts/v1/external-sip-peer-smoke` and the Make target
`v1-external-sip-peer-smoke`:

1. build and start the disposable peer with ephemeral credentials;
2. verify Asterisk readiness and fixture dialplan markers;
3. authenticate through the normal UTCP Admin API;
4. create TelephonyAddress, ExternalTrunk, credential reference, endpoint,
   address association, inbound route, and outbound route through the API;
5. activate canonical desired state and wait for automatic T6 projection;
6. verify the fixture logs do not contain the generated password; and
7. remove the fixture and disable the temporary canonical trunk during cleanup.

No direct database writes, provider projection command, provider-specific Admin
endpoint, or manual Asterisk mutation is used.

## External signaling boundary

This packet does not add host SIP ports, NodePorts, host networking, public DNS,
Traefik SIP routes, or another cluster. The current canonical local Kamailio
UDP service is ClusterIP-only, and the repository k3d checks explicitly reject
host SIP exposure. Consequently this preparation harness proves the fixture's
configuration/lifecycle and canonical setup path, but does not claim a natural
cross-boundary SIP call.

The intended later V1 topology remains:

```text
External SIP Peer
    -> approved local/native SIP edge
    -> Kamailio / provider runtime
    -> C7B inbound evaluation
    -> canonical Call/CallLeg
```

and the reverse path for outbound calls. The edge correction, if required by
the authoritative V1 proof environment, must be a separate bounded decision;
this task does not improvise it.

## Proof gap and next action

The remaining material V1 requirement is the natural external inbound/outbound
SIP proof through this fixture, including canonical route, identity, provider,
Call/CallLeg, and terminal evidence. Fixture startup alone does not close V1.

Validation command:

```text
make v1-external-sip-peer-config-check
make v1-external-sip-peer-smoke
```
