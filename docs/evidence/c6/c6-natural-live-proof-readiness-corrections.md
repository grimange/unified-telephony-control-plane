# C6 Natural-Live-Proof Readiness Corrections

Date: 2026-08-21

## Verdict

The four bounded readiness corrections are implemented and repository-tested. C6E natural frontend/live call proof was not performed and remains pending.

## Identity catalog synchronization

`2026_08_21_090000_sync_c6_identity_catalog.php` is a new forward migration. It reuses the configuration-driven identity catalog synchronization pattern used by earlier capability migrations. The disposable PostgreSQL proof prepares an already-migrated catalog with all six `telephony.calls.*` rows and role assignments removed, runs only the new migration, verifies the six configured capabilities and tenant-admin assignments, verifies tenant-member remains unassigned, preserves an unrelated capability, and reruns safely.

## Managed Asterisk capability convergence

Managed capability state remains system-owned by the managed-runtime catalog. The existing Asterisk runtime reconciler now invokes `ensureManagedCapabilities()` for managed nodes, and the application reconciler binding explicitly supplies `RuntimeRegistryService`; unmanaged nodes retain their existing manual authority. Focused tests prove old capabilities converge, a second pass is idempotent, unmanaged capability state is unchanged, and unsupported capabilities are not invented.

Canonical non-call re-verification after `make k8s-apply` showed both active/ready managed Asterisk nodes advertising the current configured catalog, including `call.origination`, `call.control`, `call.hold`, `call.transfer`, `call.dtmf.send`, `media.playback`, and `recording`. No node was reprovisioned and no manual capability mutation was used.

## Generic inbound proof fixture

The managed Asterisk local projection now contains the proof-only `c6-generic-proof` destination in the existing `from-kamailio` context. It answers and enters the existing `utcp-t0-observation` Stasis application, then hangs up. It is distinct from `conf-*` and the unchanged T3 `9900` Echo fixture, is not conference routing, and creates no C7 resource or product routing authority. Repository projection tests verify these boundaries. No synthetic call source was executed in this packet.

## Local mutable-image freshness

The canonical `scripts/kubernetes/apply` workflow now automatically restarts the declared local platform deployments and the managed Asterisk deployment after applying mutable development images, then waits for rollout completion. `make k8s-apply` completed against the existing `k3d-utcp-local` context with migration job completion and all declared rollouts ready. Running pod image IDs were verified by Kubernetes after the apply; no separate manual rollout restart command was used.

## Scope and proof boundary

No Call/CallLeg schema, runtime operation or observation authority, conference authority, TelephonySession boundary, frontend source, T4, C7, RH-3, or live call proof changed. The Asterisk configuration change is limited to the local proof fixture. The repository Asterisk config guard remains blocked by pre-existing PJSIP text in existing C6E client code; that guard failure is unrelated to this packet and was not bypassed.

## C6 corridor correction addendum

The managed-node fixture source is now shared through the
`asterisk-sip-fixtures` component and projected into both managed local
Asterisk overlays. The proof-only destination is
`c6-generic-proof -> Stasis(utcp-t0-observation)` and remains separate from
`conf-*` and the unchanged `9900` Echo fixture. The runtime-node endpoint
transport and TLS-mode options remain backend catalog metadata; the reference
UI renders those values without a fallback catalog.

Outbound origination now reserves `utcp-call-leg-<CallLeg ID>` in the existing
CallLeg/runtime-operation path before provider observation. A new normal
reconciliation target applies the existing terminal Call/CallLeg lifecycle
when a succeeded originate has passed the configured observation deadline;
exact-current locking makes later observation-confirmed progression win, and
the reservation is also available to exact-channel cancellation. No schema,
NetworkPolicy, Kamailio, conference, RH-3, or C7 change was introduced.

Repository tests pass, but this packet did not perform the natural live call
proof. Non-call Kubernetes reverification remains pending because the
canonical `k3d-apntalk-local` API endpoint was unavailable during execution;
no replacement cluster or alternate lifecycle was used.

## Final Asterisk corridor blocker corrections

The conference-channel ownership guard now resolves the runtime node through
`conference_participants.conference_id -> conferences.runtime_node_id`; it no
longer reads a nonexistent participant column. PostgreSQL-backed control-plane
coverage exercises same-node ownership, wrong-node rejection, generic-channel
rejection, and a generic Asterisk call operation with a reserved channel.

The runtime-node admin UI consumes the backend catalog's array-shaped transport
and TLS-mode metadata directly. Numeric array indexes are not rendered as
options, and no stale frontend fallback catalog was added.

The managed Asterisk provisioning projection mounts the existing
`asterisk-local-sip-fixtures` ConfigMap at the image's local configuration
projection path. This reuses the canonical `extensions.local.conf` source and
keeps `c6-generic-proof` Stasis-based, non-conference, and separate from the
unchanged `9900` Echo fixture. This packet performed repository and disposable
PostgreSQL verification only; the natural browser/Asterisk proof remains
pending.
