# GO-CP1 PostgreSQL baseline and authority adoption

Status: isolated implementation and disposable verification. Future production
adoption remains planned. Parent: [GO-CP1 report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md).

## Existing authority remains the baseline

The verifier constructs a fresh PostgreSQL database by executing the complete
current Laravel migration history. It does not translate or replay that history
as Goose migrations. The observed baseline contains 63 successful Laravel
migrations; a repeat migration run performs no work. Those results apply to this
checkout and disposable database, not an existing production database.

Canonical authority remains in the established tables:

| Existing table | Responsibility retained by Go |
| --- | --- |
| `runtime_operations` | Operation identity, tenant/RuntimeNode, current attempt, status, lease/token/expiry and cancellation |
| `infrastructure_incarnations` | Immutable physical identity, one-way UID, lifecycle, signed-BIGINT generation, immutable profile/configuration |
| `infrastructure_intents` | Exact credential-free approved intent, generation/fingerprint, outcome and supersession |
| `control_plane_audit_records` | Append-only tenant-correlated canonical transition history |
| `control_plane_outbox_messages` | Transactional event persistence, leased delivery and retry |
| `control_plane_inbox_messages` | Consumer/message identity and atomic processed marker |
| `control_plane_idempotency_records` | Request fingerprint and committed safe result |

GO-CP1 adds three subordinate tables through one reviewed SQL migration:

| New table | Why it is necessary |
| --- | --- |
| `infrastructure_dispatches` | Exact operation attempt, hashed capability/token, executor, request digest, reserved/consumed/uncertain delivery and bounded recovery evidence |
| `infrastructure_observation_challenges` | One-use fresh challenge bound to canonical intent, purpose, target and audience |
| `infrastructure_observation_receipts` | Append-only authenticated receipt provenance and safe evidence; unique issuer/receipt and challenge consumption |

The migration preserves old column types, identifiers, constraints and status
strings. New foreign keys point to the existing operation/intent/incarnation
history. Dispatch triggers protect immutable identity and legal state changes;
receipt rows reject update/delete. There is deliberately no destructive down
migration after evidence exists.

## Catalog comparison

`tests/go-cp1/catalog.sql` normalizes the protected Laravel catalog into JSON:
columns/defaults/nullability, constraints, indexes, triggers, functions, views and
grants. It also projects nonsecret capability key/scope, role key/scope/built-in
identity, and role-capability edges from the seeded catalog. The verifier captures
it before and after the additive migration and
requires byte-identical normalized output. Only the three reviewed new tables,
their support functions and Goose version bookkeeping are excluded. This checks
that existing schema authority was preserved; it is not a claim that a populated
production database has been adopted.

The private output contains `catalog-before.json`, `catalog-after.json`, the
comparison result and actual migration count. It is not copied into documentation
as a raw database dump. Inspect new additive objects separately; excluding them
from the compatibility comparison is not permission to ignore their constraints.

## Future production DDL handoff — not executed

1. Freeze the accepted schema revision and identify the single current DDL owner.
2. Capture and review the actual target catalog, migration ledger, required
   catalog/seed records, grants and data invariants against the verified baseline.
3. Resolve drift explicitly. Do not fabricate missing UIDs, overwrite old
   operation history or automatically adopt an unverified workload incarnation.
4. Stop concurrent migration entry points. Record the verified Laravel baseline
   revision in the chosen Go deployment's migration-adoption record; do not run
   old migrations again through Goose.
5. Authorize one forward migration plan and one DDL credential holder. Apply only
   reviewed additions after backup/restore and compatibility proof. Application
   startup never migrates implicitly.
6. Keep old Laravel migration sources and ledger as historical evidence. Future
   Go SQL migrations begin after the accepted baseline, with schema compatibility
   preserved for the explicitly approved transition window.
7. Transfer canonical data writers separately. Sharing tables or adopting a DDL
   baseline does not authorize PHP and Go to compete for lifecycle authority.

Rollback is a compatible code/role rollback, not deletion of dispatch, receipt,
audit or incarnation evidence. Once consumed authority or external effects exist,
old unconditional writers cannot be restarted as an automatic fallback. Preserve
uncertain histories and reconcile them through current authorized observation.

The isolated `migrate-test` command requires the run-specific test marker and is
invoked only after Laravel has built a disposable baseline. It is not a production
migration manager or production target-verification mechanism.
