-- +goose Up
-- Separate dispatch-journal evidence, never a new observation purpose or engine.
CREATE TABLE infrastructure_dispatch_evidence_challenges (
 id char(32) PRIMARY KEY, nonce char(64) NOT NULL UNIQUE,
 dispatch_id char(32) NOT NULL REFERENCES infrastructure_dispatches(id),
 audience varchar(256) NOT NULL, expected_uid varchar(128) NOT NULL,
 expires_at timestamptz NOT NULL, consumed_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE infrastructure_dispatch_evidence_receipts (
 id char(32) PRIMARY KEY, issuer varchar(256) NOT NULL, receipt_id varchar(128) NOT NULL,
 challenge_id char(32) NOT NULL UNIQUE REFERENCES infrastructure_dispatch_evidence_challenges(id),
 envelope_hash char(64) NOT NULL, safe_envelope json NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(issuer,receipt_id)
);
CREATE TRIGGER infrastructure_dispatch_evidence_receipt_guard BEFORE UPDATE OR DELETE ON infrastructure_dispatch_evidence_receipts FOR EACH ROW EXECUTE FUNCTION utcp_guard_receipt_evidence();
-- No destructive down migration: retained evidence cannot be rolled back away.
