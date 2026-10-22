package kernel

import (
	"bytes"
	"context"
	"encoding/json"
	"github.com/jackc/pgx/v5"
	"io"
	"time"
)

// stableJSON preserves integer lexemes while sorting object keys, matching C0
// StableJson for contract payloads (integer/string/bool/null; no float authority).
func stableJSON(raw []byte) ([]byte, error) {
	d := json.NewDecoder(bytes.NewReader(raw))
	d.UseNumber()
	var v any
	if e := d.Decode(&v); e != nil {
		return nil, ErrDenied
	}
	if e := d.Decode(new(any)); e != io.EOF {
		return nil, ErrDenied
	}
	return json.Marshal(v)
}

// InboxReceive uses the existing C0 delivery identity, never a second queue.
// Only a digest is persisted; payloads and credentials are not copied to inbox.
func (s *Service) InboxReceive(ctx context.Context, consumer, key, kind string, payload []byte) (status string, e error) {
	if len(consumer) < 1 || len(consumer) > 160 || len(key) < 1 || len(key) > 200 || len(kind) < 1 || len(kind) > 160 || len(payload) > 32768 {
		return "", ErrDenied
	}
	b, e := stableJSON(payload)
	if e != nil {
		return "", e
	}
	hash := Hash(b)
	e = s.tx(ctx, func(t pgx.Tx) error {
		tag, x := t.Exec(ctx, `INSERT INTO control_plane_inbox_messages(id,consumer,message_key,message_type,payload_hash,received_at,created_at,updated_at) VALUES($1,$2,$3,$4,$5,clock_timestamp(),clock_timestamp(),clock_timestamp()) ON CONFLICT(consumer,message_key) DO NOTHING`, ID(), consumer, key, kind, hash)
		if x != nil {
			return x
		}
		if tag.RowsAffected() == 1 {
			status = "accepted"
			return nil
		}
		var original, typ string
		var processed *time.Time
		if x = t.QueryRow(ctx, "SELECT payload_hash,message_type,processed_at FROM control_plane_inbox_messages WHERE consumer=$1 AND message_key=$2 FOR UPDATE", consumer, key).Scan(&original, &typ, &processed); x != nil {
			return x
		}
		if original != hash || typ != kind {
			return ErrConflict
		}
		status = "duplicate_pending"
		if processed != nil {
			status = "duplicate_processed"
		}
		return nil
	})
	return
}

// InboxApply commits DB-only application and processed marker together. No
// network or Kubernetes call may occur inside apply.
func (s *Service) InboxApply(ctx context.Context, consumer, key string, apply func(pgx.Tx) error) error {
	return s.tx(ctx, func(t pgx.Tx) error {
		var done *time.Time
		if e := t.QueryRow(ctx, "SELECT processed_at FROM control_plane_inbox_messages WHERE consumer=$1 AND message_key=$2 FOR UPDATE", consumer, key).Scan(&done); e != nil {
			return ErrDenied
		}
		if done != nil {
			return nil
		}
		if apply == nil {
			return ErrDenied
		}
		if e := apply(t); e != nil {
			return e
		}
		_, e := t.Exec(ctx, "UPDATE control_plane_inbox_messages SET processed_at=clock_timestamp(),updated_at=clock_timestamp() WHERE consumer=$1 AND message_key=$2", consumer, key)
		return e
	})
}

type OutboxClaim struct {
	ID, Token, EventType, AggregateType, AggregateID string
	Attempt                                          int
}

func (s *Service) ClaimOutbox(ctx context.Context, owner string, batch int, lease time.Duration) (out []OutboxClaim, e error) {
	if len(owner) < 1 || len(owner) > 255 || batch < 1 || batch > 100 || lease < time.Second || lease > time.Minute {
		return nil, ErrDenied
	}
	out = []OutboxClaim{}
	e = s.tx(ctx, func(t pgx.Tx) error {
		rows, x := t.Query(ctx, `SELECT id,event_type,aggregate_type,aggregate_id,attempt_count FROM control_plane_outbox_messages WHERE dispatched_at IS NULL AND dispatch_status IN ('pending','retry_scheduled','leased') AND available_at<=clock_timestamp() AND (lease_expires_at IS NULL OR lease_expires_at<=clock_timestamp() OR dispatch_status<>'leased') ORDER BY available_at,created_at,id LIMIT $1 FOR UPDATE SKIP LOCKED`, batch)
		if x != nil {
			return x
		}
		defer rows.Close()
		var claims []OutboxClaim
		for rows.Next() {
			var c OutboxClaim
			if x = rows.Scan(&c.ID, &c.EventType, &c.AggregateType, &c.AggregateID, &c.Attempt); x != nil {
				return x
			}
			c.Token = ID()
			c.Attempt++
			claims = append(claims, c)
		}
		x = rows.Err()
		rows.Close()
		if x != nil {
			return x
		}
		for _, c := range claims {
			if _, x = t.Exec(ctx, `UPDATE control_plane_outbox_messages SET dispatch_status='leased',lease_owner=$2,lease_token=$3,lease_expires_at=clock_timestamp()+($4*interval '1 second'),attempt_count=$5,updated_at=clock_timestamp() WHERE id=$1`, c.ID, owner, c.Token, int(lease/time.Second), c.Attempt); x != nil {
				return x
			}
		}
		out = claims
		return nil
	})
	return
}
func (s *Service) AckOutbox(ctx context.Context, id, token string) (bool, error) {
	tag, e := s.Pool.Exec(ctx, `UPDATE control_plane_outbox_messages SET dispatch_status='dispatched',dispatched_at=clock_timestamp(),lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,updated_at=clock_timestamp() WHERE id=$1 AND lease_token=$2 AND lease_expires_at>clock_timestamp() AND dispatch_status='leased' AND dispatched_at IS NULL`, id, token)
	return tag.RowsAffected() == 1, e
}

// RetryOutbox records fixed safe codes, never an upstream raw message.
func (s *Service) RetryOutbox(ctx context.Context, id, token string) (bool, error) {
	tag, e := s.Pool.Exec(ctx, `UPDATE control_plane_outbox_messages SET dispatch_status='retry_scheduled',available_at=clock_timestamp()+interval '30 seconds',lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,last_failure='delivery_unavailable',last_failure_class='transport',last_failure_code='delivery_unavailable',updated_at=clock_timestamp() WHERE id=$1 AND lease_token=$2 AND lease_expires_at>clock_timestamp() AND dispatch_status='leased' AND dispatched_at IS NULL`, id, token)
	return tag.RowsAffected() == 1, e
}

// Idempotent commits the existing C0 request identity, safe result and DB-only
// transition atomically. Rolled-back callbacks leave no accepted identity.
func (s *Service) Idempotent(ctx context.Context, scope, key string, request []byte, apply func(pgx.Tx) (json.RawMessage, error)) (result json.RawMessage, e error) {
	if len(scope) < 1 || len(scope) > 160 || len(key) < 1 || len(key) > 160 || len(request) > 32768 || apply == nil {
		return nil, ErrDenied
	}
	b, e := stableJSON(request)
	if e != nil {
		return nil, e
	}
	hash := Hash(b)
	e = s.tx(ctx, func(t pgx.Tx) error {
		tag, x := t.Exec(ctx, `INSERT INTO control_plane_idempotency_records(id,scope,idempotency_key,request_fingerprint,status,expires_at,created_at,updated_at) VALUES($1,$2,$3,$4,'processing',clock_timestamp()+interval '1 day',clock_timestamp(),clock_timestamp()) ON CONFLICT(scope,idempotency_key) DO NOTHING`, ID(), scope, key, hash)
		if x != nil {
			return x
		}
		if tag.RowsAffected() == 0 {
			var old, state string
			if x = t.QueryRow(ctx, "SELECT request_fingerprint,status,result FROM control_plane_idempotency_records WHERE scope=$1 AND idempotency_key=$2 FOR UPDATE", scope, key).Scan(&old, &state, &result); x != nil {
				return x
			}
			if old != hash || state != "completed" {
				return ErrConflict
			}
			return nil
		}
		result, x = apply(t)
		if x != nil {
			return x
		}
		if len(result) > 32768 || !json.Valid(result) {
			return ErrDenied
		}
		_, x = t.Exec(ctx, "UPDATE control_plane_idempotency_records SET status='completed',result=$3,updated_at=clock_timestamp() WHERE scope=$1 AND idempotency_key=$2", scope, key, result)
		return x
	})
	return
}
