# C6 PostgreSQL Migration Blocker Correction

Date: 2026-08-16

## Scope and status

This is the bounded correction for the C6E natural-proof deployment blocker. No
natural Asterisk call proof was resumed, no frontend was added, and no T4 or C7
work was started. C6 authority remains unchanged: `calls` and `call_legs` own
canonical state, `runtime_operations` owns commands, and `runtime_observations`
owns normalized runtime facts.

## PostgreSQL failure and correction

The failed migration declared `call_legs.id` with a column-level `primary()`
modifier and declared the self-referencing `bridged_to_leg_id` foreign key in
the same `Schema::create()` blueprint. PostgreSQL received the self-FK before
the primary-key constraint was available as a referenced unique key, producing
SQLSTATE `42830`. The sibling `call_legs.call_id -> calls.id` FK was unaffected
because `calls` had already been created.

The migration now creates `call_legs` with its primary key, normal columns,
non-self foreign keys, and indexes first. After `Schema::create()` completes it
adds the named self-FK through `Schema::table()`:

```text
call_legs.bridged_to_leg_id -> call_legs.id ON DELETE SET NULL
```

The partial unique runtime-channel fence remains unchanged:

```text
UNIQUE (runtime_node_id, runtime_channel_id)
WHERE runtime_channel_id IS NOT NULL
```

The down path drops `call_legs` before `calls`, allowing PostgreSQL to remove
the self-FK and dependent call-leg constraints deterministically.

## PostgreSQL proof

The existing `make control-plane-migrate-proof` path was extended; no second
proof framework or manual SQL repair was introduced. On a disposable PostgreSQL
database it proved:

- the complete migration succeeds;
- `call_legs.id` is the primary key;
- the self-FK references `call_legs(id)` and uses `ON DELETE SET NULL`;
- the partial runtime-channel unique index exists;
- the C6 tables survive `migrate:rollback --step=1` and reapply;
- the normal identity catalog migration creates all six C6 call capabilities.

The proof completed with:

```text
control-plane-migrate-proof: empty PostgreSQL migration and repeated lifecycle passed without Redis authority
```

SQLite tests remain in place for fast domain coverage; the real PostgreSQL
proof now covers the migration ordering that SQLite does not enforce.

## Identity capability consequence

The missing `telephony.calls.*` capability rows were a consequence of the
failed migration stopping the normal catalog synchronization. The successful
proof confirms the canonical sync creates exactly:

```text
telephony.calls.view
telephony.calls.view_own
telephony.calls.originate
telephony.calls.control
telephony.calls.record
telephony.calls.manage
```

`tenant-admin` receives all six through the existing catalog assignment;
`tenant-member` receives none. No manual seeder, repair command, or permission
broadening was added.

## Narrow outbound channel-correlation review

The review answered the five required questions:

1. Before this correction, generic originate did not preallocate or provide a
   deterministic Asterisk runtime channel ID. It now supplies a provider-local
   `channelId` derived deterministically from the canonical outbound CallLeg
   ID (`utcp-call-leg-<CallLeg ID>`).
2. Yes. The old ARI `POST /channels` response was discarded and no `channelId`
   was supplied. The adapter now returns the same runtime correlation in the
   existing operation result without mutating Call/CallLeg directly.
3. The first matching `stasis_start` is normalized to the exact pending
   outbound CallLeg, then the existing observation processor binds its exact
   runtime node/channel through `CallDomainService`. A generic channel without
   that deterministic correlation remains an inbound adoption candidate.
4. Yes. Before the correction, the non-conference `call.leg.offered` path could
   reach generic inbound adoption while the outbound leg remained unbound.
5. This was a confirmed repository defect with a deterministic, bounded fix.
   It changes no C6 schema, public API, conference authority, or C7 resource.

The correction does not use remote-number matching, latest-channel lookup,
fallback adoption, or a feature gate. Conference-owned channels remain fenced
by the existing conference exclusion path.

## Live-proof boundary

The natural Asterisk proof was not performed in this correction. The PostgreSQL
deployment blocker is fixed and the outbound correlation path is repository-
tested. A separate narrow natural proof remains required before C6E can be
marked live-proven; T4 remains deferred.
