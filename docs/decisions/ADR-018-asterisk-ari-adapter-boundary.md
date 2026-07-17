# ADR-018: Asterisk ARI Adapter Boundary

Date: 2026-07-15

## Status

Accepted for the T0 implementation corridor.

## Decision

Asterisk ARI is implemented as a runtime adapter beneath the generic C3 engine. ARI HTTP inspection and ARI event WebSocket connectivity are owned by `AsteriskAriClient` and a dynamically assigned `asterisk-ari-events` listener process role. PostgreSQL remains authoritative for RuntimeNodes, encrypted credential metadata, listener leases, connection epochs, raw receipts, observations, projection checkpoints, and reconciliation state.

The adapter key is `asterisk-ari` and the runtime family is `asterisk`. The T0 adapter supports generic runtime observation only. Asterisk conference operations remain unsupported until T2.

## Consequences

- Controllers and C5 services do not call ARI.
- Generic C3 command, event, projection, and reconciliation workers remain runtime-neutral.
- The public application exposes no ARI route or controller.
- Credentials are written through the C2 encrypted credential API and are decrypted only inside the adapter boundary for immediate HTTP or WebSocket use.
- Local Kubernetes may deploy an internal Asterisk ARI fixture for development and CI proof, but that fixture is not a mandatory production topology.
- ConfBridge, channel control, SIP, RTP, media, trunks, and browser calling remain out of scope for T0.
