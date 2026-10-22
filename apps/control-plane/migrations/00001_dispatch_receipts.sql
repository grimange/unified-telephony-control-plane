-- +goose Up
CREATE TABLE infrastructure_dispatches (
 id char(32) PRIMARY KEY, incarnation_id varchar(32) NOT NULL REFERENCES infrastructure_incarnations(id) ON DELETE RESTRICT,
 intent_id uuid NOT NULL REFERENCES infrastructure_intents(id) ON DELETE RESTRICT,
 operation_id char(32) NOT NULL REFERENCES runtime_operations(id) ON DELETE RESTRICT,
 attempt integer NOT NULL CHECK(attempt>0), ordinal integer NOT NULL CHECK(ordinal>0), generation bigint NOT NULL CHECK(generation>0),
 envelope_hash char(64) NOT NULL, capability_hash char(64) NOT NULL, token_hash char(64) NOT NULL,
 executor varchar(256) NOT NULL, recovery boolean NOT NULL DEFAULT false, blocked_ids json NOT NULL DEFAULT '[]',
 state varchar(24) NOT NULL CHECK(state IN ('reserved','dispatching','outcome_unknown','confirmed_applied','confirmed_not_applied','cancelled','contained')),
 expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(operation_id,attempt,ordinal)
);
CREATE UNIQUE INDEX infrastructure_dispatch_ordinary_slot ON infrastructure_dispatches(incarnation_id) WHERE NOT recovery AND state IN ('reserved','dispatching','outcome_unknown');
CREATE UNIQUE INDEX infrastructure_dispatch_consumed_attempt ON infrastructure_dispatches(operation_id,attempt) WHERE state NOT IN ('reserved','cancelled');
CREATE UNIQUE INDEX infrastructure_dispatch_recovery_slot ON infrastructure_dispatches(incarnation_id) WHERE recovery AND state IN ('reserved','dispatching','outcome_unknown');
CREATE TABLE infrastructure_observation_challenges (
 id char(32) PRIMARY KEY, nonce char(64) NOT NULL UNIQUE, incarnation_id varchar(32) NOT NULL REFERENCES infrastructure_incarnations(id),
 intent_id uuid NOT NULL REFERENCES infrastructure_intents(id), dispatch_id char(32) REFERENCES infrastructure_dispatches(id),
 purpose varchar(16) NOT NULL CHECK(purpose IN ('bind','effect','absence')), audience varchar(256) NOT NULL,
 envelope_hash char(64) NOT NULL, effect_digest char(64) NOT NULL, expected_uid varchar(128), generation bigint NOT NULL,
 expires_at timestamptz NOT NULL, consumed_at timestamptz, created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE infrastructure_observation_receipts (
 id char(32) PRIMARY KEY, issuer varchar(256) NOT NULL, receipt_id varchar(128) NOT NULL,
 challenge_id char(32) NOT NULL UNIQUE REFERENCES infrastructure_observation_challenges(id),
 envelope_hash char(64) NOT NULL, safe_envelope json NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(issuer,receipt_id)
);
-- +goose StatementBegin
CREATE FUNCTION utcp_guard_dispatch_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'dispatch evidence retained'; END IF;
 IF ROW(NEW.id,NEW.incarnation_id,NEW.intent_id,NEW.operation_id,NEW.attempt,NEW.ordinal,NEW.generation,NEW.envelope_hash,NEW.capability_hash,NEW.token_hash,NEW.executor,NEW.recovery,NEW.blocked_ids::text,NEW.expires_at)
 IS DISTINCT FROM ROW(OLD.id,OLD.incarnation_id,OLD.intent_id,OLD.operation_id,OLD.attempt,OLD.ordinal,OLD.generation,OLD.envelope_hash,OLD.capability_hash,OLD.token_hash,OLD.executor,OLD.recovery,OLD.blocked_ids::text,OLD.expires_at)
 OR (OLD.state IN ('confirmed_applied','confirmed_not_applied','cancelled','contained') AND NEW IS DISTINCT FROM OLD)
 OR (NEW.state<>OLD.state AND NOT (
 (OLD.state='reserved' AND NEW.state IN ('dispatching','cancelled')) OR
 (OLD.state='dispatching' AND NEW.state IN ('outcome_unknown','confirmed_applied','confirmed_not_applied','contained')) OR
 (OLD.state='outcome_unknown' AND NEW.state IN ('confirmed_applied','confirmed_not_applied','contained'))))
 THEN RAISE EXCEPTION 'dispatch evidence immutable or invalid transition'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER infrastructure_dispatch_guard BEFORE UPDATE OR DELETE ON infrastructure_dispatches FOR EACH ROW EXECUTE FUNCTION utcp_guard_dispatch_evidence();
CREATE FUNCTION utcp_guard_receipt_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'observation receipt evidence is append-only'; END $$;
CREATE TRIGGER infrastructure_receipt_guard BEFORE UPDATE OR DELETE ON infrastructure_observation_receipts FOR EACH ROW EXECUTE FUNCTION utcp_guard_receipt_evidence();
-- +goose StatementEnd
-- No destructive down migration: evidence survives all rollbacks after durable use.
