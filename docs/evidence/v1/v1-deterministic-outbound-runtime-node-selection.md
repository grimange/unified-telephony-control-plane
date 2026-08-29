# V1 Deterministic Outbound RuntimeNode Selection

Date: 2026-08-29

## Bounded finding

The canonical outbound Call creation path accepted a nullable
`runtime_node_id` and passed it unchanged into the Call, CallLeg, and
`call.leg.originate` RuntimeOperation. This left the optional API contract
inconsistent with the execution path, which requires a concrete RuntimeNode.

## Repair

Outbound Call creation now resolves the RuntimeNode inside its existing
database transaction before inserting canonical state. An explicit node is
tenant-scoped and validated, then honored without fallback. When omitted, the
new RuntimeNode selector chooses an active, ready tenant node with the
canonical `call.control` capability, ordered by `placement_priority` and then
stable RuntimeNode ID. The resolved ID is projected identically onto the Call,
CallLeg, and RuntimeOperation. No outbound workload-count or capacity gate was
added because the repository has no canonical outbound binding/workload table;
the conference capacity rule remains conference-specific.

If no eligible node exists, creation fails before Call, CallLeg, or
RuntimeOperation insertion. Runtime adapters remain execution-only consumers of
the resolved identity.

## Proof and preserved boundaries

Focused domain/API tests cover automatic selection and projection,
deterministic ordering, capability and state exclusion, explicit override and
invalid explicit selection, tenant isolation, and no-partial-state failure.
The existing conference selector behavior remains unchanged. The containerized
API suite is the required PHP test environment.

This repair changes no Asterisk, FreeSWITCH, Kamailio, SIP/auth/media,
observation-race, provider failure-fidelity, RuntimeNode placement scheduler,
K5, RMA, or A0 behavior. No migration, historical data mutation, live SIP
traffic, or topology change was performed. V1-A remains active; a controlled
natural live proof without manually supplying `runtime_node_id` is the next
bounded action.
