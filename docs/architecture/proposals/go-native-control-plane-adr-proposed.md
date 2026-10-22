# Proposed ADR — Go-native modular control plane and incremental Laravel retirement

Status: **PROPOSED / AWAITING OPERATOR ADOPTION**.
Date: 2026-10-21.
Assignment: `UTCP_GO_NATIVE_CONTROL_PLANE_ARCHITECTURE_PIVOT_AND_MIGRATION_PLAN`.

## Context

The operator selected Go as the long-term backend implementation language.
Laravel remains the current application authority and behavioral reference during
migration. Earlier GN-G0–GN-G2 work established bounded Go Kubernetes observation,
conditional effects, lifecycle and fixed runtime projections; the latest Laravel
canonical foundation established PostgreSQL lifecycle/claim compatibility. Their
individual proof limits remain unchanged.

The earlier [proposed adapter ADR](go-kubernetes-infrastructure-adapter-adr-proposed.md)
selected permanent Laravel/PostgreSQL authority plus a Go adapter. **Upon adoption
of this ADR**, its permanent-language target and full-backend deferral are
superseded. Its authority invariants and versioned infrastructure contracts remain
behavioral inputs unless separately versioned. It remains unedited historical
proposal material; neither proposal is adopted by this file's existence.

## Decision proposed for adoption

1. One Go modular monolith, one canonical backend codebase/release, explicit role
   processes, Vue 3/TypeScript retained. PostgreSQL remains durable canonical
   authority; Redis transient. No microservices, Operator or CRD is introduced.
2. Use the [module blueprint](go-native-control-plane/module-blueprint.md): standard
   HTTP routing, explicit constructors, pgxpool and module-owned SQL, PostgreSQL
   leased workers and existing event envelopes. Keep a narrow credential-isolated
   Kubernetes IO role with no database credentials. Canonical services call each
   other in process; replace the Laravel claim callback with Go reservation/
   consumption at the privileged IO boundary, not permanent PHP coordination.
3. Preserve the [PostgreSQL authority contract](go-native-control-plane/postgresql-authority-contract.md):
   existing operation engine/schema, incarnation history, one-way UID binding,
   lossless signed-BIGINT generations, fences, idempotency, audit/outbox/inbox,
   cross-version activation/retirement rules and restart-safe uncertainty.
   Add durable dispatch reservation/consumption and authenticated receipt protocols
   through isolated implementation and independent verification before deployment.
4. Reuse existing observer/effects/lifecycle/profile implementations and tests.
   Preserve immutable identity, ownership, conditional parent patches, delete
   preconditions, inert creation, generation barriers and observe-first recovery.
   Kubernetes owns placement; Asterisk/FreeSWITCH own telephony execution; Kamailio
   and rtpengine retain signaling/media authority. Go does not rebuild those engines.
5. Migrate all material Laravel modules in the [responsibility matrix](go-native-control-plane/laravel-to-go-matrix.md)
   through [CP0–CP6](go-native-control-plane/migration-roadmap.md). Existing browser
   API/CSRF/session projections and four private Pusher/Echo channels remain the
   frontend compatibility contract. Select an explicit forced reauthentication
   boundary for existing Laravel sessions; preserve password and encrypted-secret
   compatibility through independent fixtures before any identity transfer.
6. Go eventually owns SQL migrations through Goose; freeze and verify Laravel's
   complete effective schema before acknowledging a Go baseline. One DDL owner,
   no startup migration, no concurrent migration tools or destructive history loss.
7. Transfer canonical writers only through separately authorized fenced packets.
   All five Deployment mutation corridors, old credentials, queued/in-flight work,
   listener epochs, outbox ownership, external replica writers and rollback must
   be accounted for. Current unconditional PHP writers are not a safe automatic
   rollback target after Go effects. No transfer is authorized by adopting this ADR.

## Relationship to accepted decisions

This preserves the modular-monolith and PostgreSQL/Redis principles of
[ADR-001](../../decisions/ADR-001-modular-monolith.md) and
[ADR-007](../../decisions/ADR-007-postgresql-canonical-redis-transient.md), along
with runtime, telephony, addressing and management authority. Adoption must record
successor implementation-language/process details for
[ADR-008](../../decisions/ADR-008-container-process-contract.md),
[ADR-013](../../decisions/ADR-013-control-plane-application-kernel.md),
[ADR-014](../../decisions/ADR-014-postgresql-identity-tenancy-and-session-authentication.md)
and runtime-engine/lifecycle ADRs where they name Laravel-specific mechanisms.
Do not rewrite their historical acceptance or silently change their invariants.
Use a separately adopted successor/addendum referencing this ADR; ordinary
implementation work does not itself adopt it.

## Consequences and alternatives

One language removes the permanent PHP-to-Go authority boundary while preserving
logical and credential boundaries. Migration cost includes identity cryptography,
session transition, event/broadcast protocol, provider timing, worker restart,
existing schema adoption and independent live reproof. No measured resource or
throughput improvement is claimed by this proposal.

Reject a permanent Laravel core as the target because it contradicts the selected
direction. Reject a wholesale rewrite/cutover, parallel canonical writers, a new
Go schema replacing proven records, generic Kubernetes manifest execution,
automatic microservice extraction, controller-runtime/CRDs without a requirement,
and a Redis-only operation engine. Reject running API and Kubernetes execution
with shared unrestricted credentials. Reject preserving every PHP-era RPC when
same-process transactions are required. Reject forced reproduction of all Reverb
features where only a bounded Pusher subset is used.

## Adoption and acceptance requirements

Operator approval must explicitly adopt the target, process/credential separation,
SQL migration ownership, reservation/receipt design and forced reauthentication
policy, with independent architecture/security review. Adoption changes planning
authority only; it does not deploy code, apply migrations, authorize live proof or
cut over a writer. Record any resulting canonical ledger impact in that later
adoption packet. This proposal leaves the current ledger unchanged.

CP1 must pass real PostgreSQL concurrency/restart and PHP/Go contract tests;
CP2 browser/crypto/Pusher compatibility; CP3 Kubernetes/config-publication and
full infrastructure parity; CP4 actual supported provider/domain semantics;
CP5 recording/remaining route closure; CP6 separately authorized credential cutoff,
rollback and fresh scope-relevant runtime/natural-live proof. Resolve the known
static checker and scanner defects through narrow independent repairs. Independent
acceptance is performed by a verifier, not the implementing worker.

See the [complete pivot report](UTCP_GO_NATIVE_CONTROL_PLANE_ARCHITECTURE_PIVOT_AND_MIGRATION_PLAN.md)
and [first implementation assignment](go-native-control-plane/GO-CP1-canonical-kernel-assignment.md).
