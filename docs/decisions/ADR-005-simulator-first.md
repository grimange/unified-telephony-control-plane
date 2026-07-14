# ADR-005: Simulator-First Runtime Integration

## Status

Accepted; refined by ADR-013

## Context

The control-plane workflow must be proven before integrating real telephony runtimes and protocols.

ADR-013 later established that the application kernel, runtime registry, and generic command/event/reconciliation contracts come before simulator behavior. This ADR remains in force for runtime integration ordering: the simulator is the first adapter-style runtime proof, but it does not define the C0 application kernel.

## Decision

UTCP will implement a simulator-backed runtime integration before real Asterisk, FreeSWITCH, Kamailio, or rtpengine integration.

## Consequences

- Reconciliation, desired/observed state handling, retries, drift, audit, and UI workflows can be verified without PSTN or real telephony dependencies.
- Real runtime adapters must conform to contracts proven by the simulator rather than shaping the control plane around one vendor.
