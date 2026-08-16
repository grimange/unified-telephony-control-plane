# RNM-4 — Observed Capability Projection

## Status

RNM-4 is implemented and focused-tested in the current working tree. This
packet does not change RuntimeNode lifecycle transitions, placement policy, or
managed runtime provisioning.

## Authority

- Declared capabilities remain administrator intent in
  `runtime_node_capabilities`, written by `RuntimeRegistryService`.
- Runtime capability evidence is normalized by the existing runtime event
  normalizers and projected by `ProjectionService`.
- `RuntimeEvidenceService` derives declared/observed drift and freshness for
  the existing RuntimeNode evidence API.

## Observation contract

The repository's `runtime.capability.observed` observation is a complete
capability snapshot. The normalizers carry a sorted `capabilities` list in the
normalized payload, together with the existing observation timestamp and
configuration version. Observations without a capability list do not create an
authoritative empty snapshot.

## Persistence and projection

The forward migration
`2026_08_08_112000_create_runtime_node_observed_capabilities.php` adds snapshot
metadata and current capability rows. Snapshot metadata preserves the
distinction between no evidence and an observed empty set, including
`observed_at`, source observation ID, adapter key, configuration version, and
capability count. Current rows are replaced atomically under the snapshot lock;
tenant/node and tenant/node/capability uniqueness constraints prevent duplicate
current projections.

Older observations cannot replace a newer snapshot. Replaying the same source
observation is ignored by the existing observation receipt idempotency boundary.

## Evidence outcomes

The focused `ObservedCapabilityProjectionTest` passed 5 tests and 20
assertions. It proves:

- declared `conference`, `event.stream`, and `recording` remain unchanged when
  observed evidence contains only `conference` and `event.stream`;
- an observed-only capability is reported as `observed_not_declared`;
- a newer complete snapshot removes a capability absent from that snapshot;
- replay and older observations do not corrupt the current projection;
- no evidence is reported as unknown, while an explicit empty snapshot is
  reported as observed-empty;
- stale RuntimeNode evidence retains historical capability rows and reports
  stale freshness; and
- tenant isolation is preserved.

The focused frontend capability-evidence test passed 2 tests. It covers the
separate declared/observed/drift labels and the no-observation state. The
frontend typecheck, lint, and production build were also run after correcting
one null-safe template expression.

## Deferred boundaries

Observed capabilities remain evidence and do not mutate declared capability
intent or placement authority. RNM-5 owns the broader natural RuntimeNode UI,
RNM-6 owns full browser/live lifecycle proof, and RNP managed runtime
provisioning remains future work.

## Live simulator producer closure (2026-08-08)

The deterministic simulator now emits a complete capability snapshot from its
canonical adapter catalog during normal inspect/configuration observation. The
producer does not read `runtime_node_capabilities`; it derives the sorted,
deduplicated set from the simulator adapter's existing supported-capability
catalog. The event is scheduled and published by the existing simulator event
source, normalized as `runtime.capability.observed`, and projected by
`ProjectionService` into the existing snapshot/current-capability tables.

Focused simulator proof passed with the canonical readiness path intact. The
affected backend suite passed 67 tests and 808 assertions, followed by green
`make test`, `make check`, and `git diff --check`. The updated API image was
built, pushed, applied, and restarted through the canonical `utcp-local`
lifecycle.

Natural-login browser proof used Local Tenant and the real Runtime Nodes Admin
UI. A simulator fixture first showed `Observed: Not yet observed`; after a
normal configuration operation and UI Refresh, the same browser session showed
`observed ready`, fresh capability evidence, and this runtime-owned snapshot:

- Declared: `event.stream`, `runtime.observation`
- Observed: `conference.lifecycle`, `conference.participation`, `event.stream`,
  `runtime.configuration`, `runtime.observation`
- Declared but not observed: none
- Observed but not declared: `conference.lifecycle`,
  `conference.participation`, `runtime.configuration`
- Freshness: `fresh`

Closing and reopening the detail view preserved the populated evidence. The
fixtures were retired through the normal Admin UI lifecycle; no observed rows,
declared rows, or credentials were written through a manual or alternate path.

Asterisk and FreeSWITCH live capability producers remain outside this bounded
simulator closure and are not claimed here.
