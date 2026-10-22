package kernel

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"github.com/jackc/pgx/v5"
	"sort"
)

func scanReservation(r scanner) (v Reservation, e error) {
	e = r.Scan(&v.ID, &v.State, &v.EnvelopeHash, &v.Executor, &v.IntentID, &v.IncarnationID, &v.Generation, &v.Recovery, &v.Expires)
	return
}

const reservationColumns = "id,state,envelope_hash,executor,intent_id::text,incarnation_id,generation::text,recovery,expires_at"

func (s *Service) Reserve(ctx context.Context, r ReserveRequest) (v Reservation, e error) {
	a, d, e := s.parse(r.Envelope)
	if e != nil {
		return v, e
	}
	if len(r.Capability) != 64 || !hex64.MatchString(r.Capability) || len(r.Executor) < 1 || len(r.Executor) > 256 {
		return v, ErrDenied
	}
	hash := Hash(r.Envelope)
	e = s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, a.TenantID, a.Target.IncarnationID)
		if x != nil {
			return x
		}
		// Status-only duplicate recovery never grants fresh dispatch authority.
		prior, priorErr := scanReservation(t.QueryRow(ctx, "SELECT "+reservationColumns+" FROM infrastructure_dispatches WHERE incarnation_id=$1 AND envelope_hash=$2 AND executor=$3 AND capability_hash=$4 ORDER BY created_at DESC LIMIT 1 FOR UPDATE", i.ID, hash, r.Executor, Hash([]byte(r.Capability))))
		if priorErr == nil {
			v = prior
			return nil
		}
		if priorErr != pgx.ErrNoRows {
			return priorErr
		}
		in, x := s.verifyLocked(ctx, t, i, a, d, false)
		if x != nil {
			return x
		}
		var alreadyConsumed bool
		if x = t.QueryRow(ctx, "SELECT EXISTS(SELECT 1 FROM infrastructure_dispatches WHERE operation_id=$1 AND attempt=$2 AND state NOT IN ('cancelled','reserved'))", a.OperationID, a.Claim.Attempt).Scan(&alreadyConsumed); x != nil {
			return x
		}
		if alreadyConsumed {
			return ErrDenied
		}
		old, x := scanReservation(t.QueryRow(ctx, "SELECT "+reservationColumns+" FROM infrastructure_dispatches WHERE intent_id=$1 AND attempt=$2 AND envelope_hash=$3 AND executor=$4 ORDER BY ordinal DESC LIMIT 1 FOR UPDATE", in.ID, a.Claim.Attempt, hash, r.Executor))
		if x == nil {
			v = old
			return nil
		}
		if x != pgx.ErrNoRows {
			return x
		}
		rows, x := t.Query(ctx, "SELECT d.id,d.generation::text,d.recovery,d.state,n.action FROM infrastructure_dispatches d JOIN infrastructure_intents n ON n.id=d.intent_id WHERE d.incarnation_id=$1 AND d.state IN ('reserved','dispatching','outcome_unknown') FOR UPDATE", i.ID)
		if x != nil {
			return x
		}
		var blocked []string
		for rows.Next() {
			var id, g, st, action string
			var rec bool
			if x = rows.Scan(&id, &g, &rec, &st, &action); x != nil {
				rows.Close()
				return x
			}
			if !r.Recovery || rec || st == "reserved" || action == "create_suspended" {
				rows.Close()
				return ErrConflict
			}
			gn, _ := Generation(g)
			an, _ := Generation(a.Generation)
			if an <= gn {
				rows.Close()
				return ErrDenied
			}
			blocked = append(blocked, id)
		}
		x = rows.Err()
		rows.Close()
		if x != nil {
			return x
		}
		if r.Recovery {
			// Exact-UID retirement deletion can contain delayed non-create
			// effects only after trusted absence is observed. It never
			// retracts delivery or permits an uncertain CREATE replay.
			barrier := (i.State == "bound" && (a.Action == "admit_generation" || a.Action == "converge_projection")) || (i.State == "retiring" && a.Action == "retire_delete")
			if i.UID == nil || len(blocked) == 0 || !barrier {
				return ErrDenied
			}
			sort.Strings(blocked)
			sort.Strings(r.BlockedIDs)
			if len(blocked) != len(r.BlockedIDs) {
				return ErrDenied
			}
			for n := range blocked {
				if blocked[n] != r.BlockedIDs[n] {
					return ErrDenied
				}
			}
		} else if len(r.BlockedIDs) > 0 {
			return ErrDenied
		}
		blockedJSON, _ := json.Marshal(blocked)
		var ordinal int
		if x = t.QueryRow(ctx, "SELECT COALESCE(MAX(ordinal),0)+1 FROM infrastructure_dispatches WHERE operation_id=$1 AND attempt=$2", a.OperationID, a.Claim.Attempt).Scan(&ordinal); x != nil {
			return x
		}
		v = Reservation{ID: ID(), State: "reserved", EnvelopeHash: hash, Executor: r.Executor, IntentID: in.ID, IncarnationID: i.ID, Generation: a.Generation, Recovery: r.Recovery, Expires: a.Claim.Expires}
		if a.Deadline.Before(v.Expires) {
			v.Expires = a.Deadline
		}
		_, x = t.Exec(ctx, `INSERT INTO infrastructure_dispatches(id,incarnation_id,intent_id,operation_id,attempt,ordinal,generation,envelope_hash,capability_hash,token_hash,executor,recovery,blocked_ids,state,expires_at) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,'reserved',$14)`, v.ID, i.ID, in.ID, a.OperationID, a.Claim.Attempt, ordinal, a.Generation, hash, Hash([]byte(r.Capability)), Hash([]byte(a.Claim.Token)), r.Executor, r.Recovery, blockedJSON, v.Expires)
		if x != nil {
			return x
		}
		v.Granted = true
		return event(ctx, t, i, "dispatch_reserved", a.OperationID)
	})
	return
}
func (s *Service) Consume(ctx context.Context, r ConsumeRequest) (v Reservation, e error) {
	a, d, e := s.parse(r.Envelope)
	if e != nil {
		return v, e
	}
	e = s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, a.TenantID, a.Target.IncarnationID)
		if x != nil {
			return x
		}
		var capHash, tokenHash string
		v, x = scanReservation(t.QueryRow(ctx, "SELECT "+reservationColumns+" FROM infrastructure_dispatches WHERE id=$1 AND incarnation_id=$2 FOR UPDATE", r.ID, i.ID))
		if x != nil {
			return ErrDenied
		}
		if x = t.QueryRow(ctx, "SELECT capability_hash,token_hash FROM infrastructure_dispatches WHERE id=$1", r.ID).Scan(&capHash, &tokenHash); x != nil {
			return x
		}
		if v.Executor != r.Executor || v.EnvelopeHash != Hash(r.Envelope) || subtle.ConstantTimeCompare([]byte(capHash), []byte(Hash([]byte(r.Capability)))) != 1 || tokenHash != Hash([]byte(a.Claim.Token)) {
			return ErrDenied
		}
		if v.State != "reserved" {
			return nil
		}
		_, x = s.verifyLocked(ctx, t, i, a, d, true)
		if x != nil {
			return x
		}
		tag, x := t.Exec(ctx, "UPDATE infrastructure_dispatches SET state='dispatching',updated_at=clock_timestamp() WHERE id=$1 AND state='reserved' AND expires_at>clock_timestamp()", v.ID)
		if x != nil {
			return x
		}
		if tag.RowsAffected() != 1 {
			return ErrDenied
		}
		_, x = t.Exec(ctx, "UPDATE infrastructure_intents SET outcome='dispatch_attempted',dispatch_attempt=$2,updated_at=clock_timestamp() WHERE id=$1", v.IntentID, a.Claim.Attempt)
		if x != nil {
			return x
		}
		v.State = "dispatching"
		v.Granted = true
		return event(ctx, t, i, "dispatch_consumed", a.OperationID)
	})
	return
}
func (s *Service) Status(ctx context.Context, id, executor string) (Reservation, error) {
	return scanReservation(s.Pool.QueryRow(ctx, "SELECT "+reservationColumns+" FROM infrastructure_dispatches WHERE id=$1 AND executor=$2", id, executor))
}
func (s *Service) MarkUnknown(ctx context.Context, id, executor string) error {
	return s.tx(ctx, func(t pgx.Tx) error {
		var tenant, inc string
		if e := t.QueryRow(ctx, `SELECT i.tenant_id::text,i.id FROM infrastructure_dispatches d JOIN infrastructure_incarnations i ON i.id=d.incarnation_id WHERE d.id=$1 AND d.executor=$2`, id, executor).Scan(&tenant, &inc); e != nil {
			return ErrDenied
		}
		i, e := s.lock(ctx, t, tenant, inc)
		if e != nil {
			return e
		}
		var intent, op, state string
		e = t.QueryRow(ctx, "SELECT intent_id::text,operation_id,state FROM infrastructure_dispatches WHERE id=$1 FOR UPDATE", id).Scan(&intent, &op, &state)
		if e != nil {
			return e
		}
		if state == "outcome_unknown" {
			return nil
		}
		if state != "dispatching" {
			return ErrDenied
		}
		if _, e = t.Exec(ctx, "UPDATE infrastructure_dispatches SET state='outcome_unknown',updated_at=clock_timestamp() WHERE id=$1", id); e != nil {
			return e
		}
		if _, e = t.Exec(ctx, "UPDATE infrastructure_intents SET outcome='outcome_unknown',updated_at=clock_timestamp() WHERE id=$1", intent); e != nil {
			return e
		}
		return event(ctx, t, i, "effect_outcome_unknown", op)
	})
}
func (s *Service) Unresolved(ctx context.Context, tenant string) ([]Reservation, error) {
	rows, e := s.Pool.Query(ctx, "SELECT "+"d.id,d.state,d.envelope_hash,d.executor,d.intent_id::text,d.incarnation_id,d.generation::text,d.recovery,d.expires_at"+" FROM infrastructure_dispatches d JOIN infrastructure_incarnations i ON i.id=d.incarnation_id WHERE i.tenant_id=$1 AND d.state IN ('reserved','dispatching','outcome_unknown') ORDER BY d.created_at", tenant)
	if e != nil {
		return nil, e
	}
	defer rows.Close()
	out := []Reservation{}
	for rows.Next() {
		r, e := scanReservation(rows)
		if e != nil {
			return nil, e
		}
		out = append(out, r)
	}
	return out, rows.Err()
}

// ReleaseExpired frees only never-consumed grants. Consumed/unknown attempts
// retain their delivery uncertainty and can never become replayable here.
func (s *Service) ReleaseExpired(ctx context.Context, tenant, inc string) error {
	return s.tx(ctx, func(t pgx.Tx) error {
		i, e := s.lock(ctx, t, tenant, inc)
		if e != nil {
			return e
		}
		tag, e := t.Exec(ctx, "UPDATE infrastructure_dispatches SET state='cancelled',updated_at=clock_timestamp() WHERE incarnation_id=$1 AND state='reserved' AND expires_at<=clock_timestamp()", i.ID)
		if e != nil {
			return e
		}
		if tag.RowsAffected() == 0 {
			return nil
		}
		return event(ctx, t, i, "expired_reservation_released", "")
	})
}
