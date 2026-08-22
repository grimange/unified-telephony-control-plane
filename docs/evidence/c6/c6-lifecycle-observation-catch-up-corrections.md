# C6 Lifecycle and Observation Catch-Up Corrections

Status: repository implementation and automated verification complete; final natural Asterisk/browser proof remains pending.

## Outbound lifecycle intent

`CallDomainService::createOutboundCall()` persists the originate runtime operation, reserves `utcp-call-leg-<CallLeg ID>`, and advances the Call and CallLeg from `requested` to `originating` through the canonical domain transition path. Provider lifecycle states remain observation-driven.

## Observation catch-up

`CallObservationProcessor` retains the existing `runtime_observations` ledger as fact authority. When an exact tenant/runtime-node/channel identity is adopted, it re-evaluates a bounded, deterministic set of earlier lifecycle observations using their original source epoch and occurrence timestamp. Application time may follow adoption time; fact occurrence order is preserved. The path excludes `offered` because adoption itself establishes that anchor and remains idempotent through existing domain transition fencing.

## Managed reconciliation identity

The telephony reconciler now uses the existing least-privilege `utcp-runtime-fencer` Kubernetes API identity, with the established API-client label and explicit projected service-account token while `automountServiceAccountToken` remains disabled. This allows the existing managed Asterisk desired-Deployment convergence path to use the existing apiserver policy without broadening RBAC or NetworkPolicy.

## Scope and proof boundary

No frontend, schema, conference, NetworkPolicy selector, Kamailio, C7, or alternate Kubernetes environment changes were introduced by this correction. Repository tests and configuration checks are the evidence for this packet; the natural browser/Asterisk proof is intentionally not performed here.
