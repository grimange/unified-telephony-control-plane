# Canonical kernel PostgreSQL integration proof

These tests execute against the disposable PostgreSQL 17 database created by the
repository's GO-CP1 verifier. They never create containers, clusters, listeners
outside `httptest`, or production resources. Every test inserts its own synthetic
tenant/RuntimeNode and preserves canonical history. Do not point them at an
existing database.

Run the pinned `tests/go-cp1/verify` entry point from the repository root.
Its integration environment must provide `UTCP_CP1_DSN`, `UTCP_CP1_ORACLE`, and,
if the working directory differs, `UTCP_CP1_ROOT`. Missing database or oracle
configuration causes an explicit skip; a skipped test is not PostgreSQL or
PHP/Go proof.

The suite covers concurrent generation allocation using independent pooled
connections; exact BIGINT boundaries; idempotent preparation and audit/outbox
emission; signed UID binding; competing bindings; terminal identity preservation;
lease/attempt/tenant/generation guards; reservations and one-time consumption;
retirement races; bounded recovery reservations; and authenticated transport
composition against the actual canonical service. Cleanup recovery for an unknown
bound effect requires an exact-UID retirement intent and signed absence; the
predecessor dispatch becomes contained while its unknown-delivery history
remains. Uncertain creation cannot be replayed through a recovery reservation.
A late signed receipt for an already consumed recovery is historical evidence: it
may contain its recorded predecessors, but cannot revert retirement or grant a
new dispatch. Exact receipt retries acknowledge stored evidence without adding
receipt, audit or outbox rows; changed replay payloads fail closed.

Transactional inbox delivery, command idempotency, outbox retry and stale-token
rejection use the existing canonical tables. Expiry releases only reservations
that were never consumed. No test equates lease expiry with effect absence.

`TestPostgresKilledWorkerRestart` launches the current Go test executable as a
child at each of three barriers: after reservation, after consumption, and after
a synthetic effect before result recording. It kills that child and starts a
separate recovery process against the same PostgreSQL database. It proves
committed authority survives process loss and consumed authority becomes durable
observation-required uncertainty. The synthetic effect is a temporary local test
marker; it performs no Kubernetes effect and proves no external delivery.

The mTLS integration uses ephemeral Ed25519 CA/server/client keys in memory. It
checks reserve/consume/result behavior through the real application composition,
including denial of unsupported success assertions. Separate infrastructure
package tests exercise the wider certificate/signature negative matrix.

The PHP oracle is generated independently by Laravel against the same disposable
database. The Go suite compares v1/v2/v3 fingerprints and current/denied claim
decisions. Each version must include a PHP-authorized current claim and at least
one denied claim; an all-denied oracle cannot pass the coverage gate. It does
not claim full backend or telephony parity. Kubernetes,
SIP/media, browser, deployed, and natural-live proof are outside this suite.
