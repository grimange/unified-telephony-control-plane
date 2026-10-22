package kernel

import (
	"context"
	"encoding/json"
	"github.com/jackc/pgx/v5"
	"time"
)

func (s *Service) readChallenge(ctx context.Context, q interface {
	QueryRow(context.Context, string, ...any) pgx.Row
}, id string) (c ReceiptChallenge, e error) {
	e = q.QueryRow(ctx, `SELECT c.id,c.nonce,i.tenant_id::text,i.runtime_node_id::text,i.id,i.cluster_id,i.namespace,i.physical_name,COALESCE(c.expected_uid,''),c.purpose,c.audience,c.intent_id::text,COALESCE(c.dispatch_id,''),c.envelope_hash,c.generation::text,n.fingerprint,c.effect_digest,n.action,COALESCE(n.envelope->'intent'->>'projection_fingerprint',''),c.expires_at,c.created_at FROM infrastructure_observation_challenges c JOIN infrastructure_incarnations i ON i.id=c.incarnation_id JOIN infrastructure_intents n ON n.id=c.intent_id WHERE c.id=$1`, id).Scan(&c.ID, &c.Nonce, &c.TenantID, &c.RuntimeNodeID, &c.IncarnationID, &c.ClusterID, &c.Namespace, &c.Name, &c.ExpectedUID, &c.Purpose, &c.Audience, &c.IntentID, &c.DispatchID, &c.EnvelopeHash, &c.Generation, &c.Fingerprint, &c.EffectDigest, &c.Action, &c.ProjectionDigest, &c.ExpiresAt, &c.IssuedAt)
	return
}

// Challenge binds a fresh exact-resource observation to existing canonical intent.
// EffectDigest is always derived from persisted, independently validated intent.
func (s *Service) Challenge(ctx context.Context, r ChallengeRequest) (c ReceiptChallenge, e error) {
	if len(r.Audience) < 1 || len(r.Audience) > 256 {
		return c, ErrDenied
	}
	e = s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, r.TenantID, r.IncarnationID)
		if x != nil {
			return x
		}
		in, x := scanIntent(t.QueryRow(ctx, "SELECT "+intentColumns+" FROM infrastructure_intents WHERE id=$1 AND incarnation_id=$2 FOR UPDATE", r.IntentID, i.ID))
		if x != nil {
			return ErrDenied
		}
		cleanupBind := r.Purpose == "bind" && i.State == "retiring" && in.Action == "create_suspended" && i.UID == nil
		historicalRecovery, x := recoveryEvidence(ctx, t, i, in, r.Purpose, r.DispatchID)
		if x != nil {
			return x
		}
		if (in.Superseded || in.Generation != i.Generation) && !cleanupBind && !historicalRecovery {
			return ErrDenied
		}
		if r.EffectDigest != "" && r.EffectDigest != in.Fingerprint {
			return ErrDenied
		}
		switch r.Purpose {
		case "bind":
			if (i.State != "preparing" && i.State != "retiring") || in.Action != "create_suspended" || i.UID != nil {
				return ErrDenied
			}
		case "effect":
			if (i.State != "bound" && !historicalRecovery) || r.DispatchID == "" || in.Action == "create_suspended" || in.Action == "retire_delete" {
				return ErrDenied
			}
		case "absence":
			if i.State != "retiring" || in.Action != "retire_delete" {
				return ErrDenied
			}
		default:
			return ErrDenied
		}
		var hash string
		if r.DispatchID != "" {
			var state string
			if x = t.QueryRow(ctx, `SELECT envelope_hash,state FROM infrastructure_dispatches WHERE id=$1 AND intent_id=$2 AND incarnation_id=$3 FOR UPDATE`, r.DispatchID, in.ID, i.ID).Scan(&hash, &state); x != nil {
				return ErrDenied
			}
			if state != "dispatching" && state != "outcome_unknown" {
				return ErrDenied
			}
		} else {
			hash = Hash(in.Envelope)
		}
		c = ReceiptChallenge{ID: ID(), Nonce: ID() + ID(), TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, IncarnationID: i.ID, ClusterID: i.ClusterID, Namespace: i.Namespace, Name: i.Name, Purpose: r.Purpose, Audience: r.Audience, IntentID: in.ID, DispatchID: r.DispatchID, EnvelopeHash: hash, Generation: in.Generation, Fingerprint: in.Fingerprint, EffectDigest: in.Fingerprint, Action: in.Action}
		var approved Envelope
		if json.Unmarshal(in.Envelope, &approved) != nil {
			return ErrDenied
		}
		c.ProjectionDigest = approved.Intent.ProjectionFingerprint
		if i.UID != nil {
			c.ExpectedUID = *i.UID
		}
		if x = t.QueryRow(ctx, "SELECT clock_timestamp()+interval '30 seconds',clock_timestamp()").Scan(&c.ExpiresAt, &c.IssuedAt); x != nil {
			return x
		}
		var dispatch any
		if c.DispatchID != "" {
			dispatch = c.DispatchID
		}
		var uid any
		if c.ExpectedUID != "" {
			uid = c.ExpectedUID
		}
		_, x = t.Exec(ctx, `INSERT INTO infrastructure_observation_challenges(id,nonce,incarnation_id,intent_id,dispatch_id,purpose,audience,envelope_hash,effect_digest,expected_uid,generation,expires_at) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12)`, c.ID, c.Nonce, i.ID, in.ID, dispatch, c.Purpose, c.Audience, c.EnvelopeHash, c.EffectDigest, uid, c.Generation, c.ExpiresAt)
		return x
	})
	return
}

// AcceptReceipt performs expensive transport/signature validation outside the DB
// transaction, then rechecks exact challenge/current canonical state under locks.
func (s *Service) AcceptReceipt(ctx context.Context, id string, raw []byte, v ReceiptVerifier) error {
	if v == nil {
		return ErrDenied
	}
	// A lost acceptance response may be recovered as status only. Exact
	// immutable receipt bytes grant no additional transition or dispatch,
	// even after challenge expiry. Altered/reissued receipts are not replayed.
	var recorded string
	err := s.Pool.QueryRow(ctx, "SELECT envelope_hash FROM infrastructure_observation_receipts WHERE challenge_id=$1", id).Scan(&recorded)
	if err == nil {
		if recorded == Hash(raw) {
			return nil
		}
		return ErrDenied
	}
	if err != pgx.ErrNoRows {
		return err
	}
	c, e := s.readChallenge(ctx, s.Pool, id)
	if e != nil {
		return ErrDenied
	}
	proof, e := v.Verify(ctx, raw, c)
	if e != nil {
		return ErrDenied
	}
	return s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, c.TenantID, c.IncarnationID)
		if x != nil {
			return x
		}
		in, x := scanIntent(t.QueryRow(ctx, "SELECT "+intentColumns+" FROM infrastructure_intents WHERE id=$1 FOR UPDATE", c.IntentID))
		if x != nil {
			return ErrDenied
		}
		var consumed *time.Time
		var valid bool
		if x = t.QueryRow(ctx, "SELECT consumed_at,expires_at>clock_timestamp() FROM infrastructure_observation_challenges WHERE id=$1 FOR UPDATE", id).Scan(&consumed, &valid); x != nil {
			return x
		}
		if consumed != nil {
			var accepted string
			if x = t.QueryRow(ctx, "SELECT envelope_hash FROM infrastructure_observation_receipts WHERE challenge_id=$1", id).Scan(&accepted); x == nil && accepted == Hash(raw) {
				return nil
			}
			return ErrDenied
		}
		cleanupBind := c.Purpose == "bind" && i.State == "retiring" && in.Action == "create_suspended" && i.UID == nil
		historicalRecovery, x := recoveryEvidence(ctx, t, i, in, c.Purpose, c.DispatchID)
		if x != nil {
			return x
		}
		if !valid || ((in.Superseded || in.Generation != i.Generation) && !cleanupBind && !historicalRecovery) {
			return ErrDenied
		}
		if proof.ReceiptID == "" || proof.Issuer == "" || proof.ChallengeID != c.ID || proof.TenantID != i.TenantID || proof.RuntimeNodeID != i.RuntimeNodeID || proof.IncarnationID != i.ID || proof.ClusterID != i.ClusterID || proof.Namespace != i.Namespace || proof.Name != i.Name || proof.ExpectedUID != c.ExpectedUID || proof.Purpose != c.Purpose || proof.IntentID != in.ID || proof.DispatchID != c.DispatchID || proof.EnvelopeHash != c.EnvelopeHash || proof.Generation != c.Generation || proof.Fingerprint != in.Fingerprint || proof.EffectDigest != in.Fingerprint || !proof.Complete {
			return ErrDenied
		}
		var timely bool
		if x = t.QueryRow(ctx, "SELECT $1::timestamptz<=clock_timestamp()+interval '5 seconds' AND $1>=clock_timestamp()-interval '30 seconds' AND $2::timestamptz>clock_timestamp()", proof.StartedAt, proof.ExpiresAt).Scan(&timely); x != nil || !timely {
			return ErrDenied
		}
		if proof.Outcome == "found" && (proof.ObservedTenantID != i.TenantID || proof.ObservedRuntimeNodeID != i.RuntimeNodeID || proof.ObservedIncarnationID != i.ID) {
			return ErrDenied
		}
		if c.Purpose == "effect" && (proof.AdmittedGeneration != in.Generation || proof.AdmittedFingerprint != in.Fingerprint || (in.Action != "admit_generation" && (proof.AppliedGeneration != in.Generation || proof.AppliedFingerprint != in.Fingerprint))) {
			return ErrDenied
		}
		if proof.Outcome == "found" && (proof.ObservedUID == "" || proof.ResourceVersion == "" || (i.UID != nil && proof.ObservedUID != *i.UID)) {
			return ErrDenied
		}
		var outcome string
		switch c.Purpose {
		case "bind":
			if (i.State != "preparing" && i.State != "retiring") || i.UID != nil || in.Action != "create_suspended" || proof.Outcome != "found" {
				return ErrDenied
			}
			if proof.Replicas == nil || *proof.Replicas != 0 || proof.ObservedProjectionDigest != c.ProjectionDigest || proof.AdmittedGeneration != in.Generation || proof.AdmittedFingerprint != in.Fingerprint || proof.AppliedGeneration != in.Generation || proof.AppliedFingerprint != in.Fingerprint {
				return ErrDenied
			}
			if _, x = t.Exec(ctx, "UPDATE infrastructure_incarnations SET kubernetes_uid=$2,state=CASE WHEN state='preparing' THEN 'bound' ELSE 'retiring' END,updated_at=clock_timestamp() WHERE id=$1", i.ID, proof.ObservedUID); x != nil {
				return x
			}
			outcome = "confirmed_applied"
		case "effect":
			if (i.State != "bound" && !historicalRecovery) || i.UID == nil || proof.Outcome != "found" {
				return ErrDenied
			}
			var approved Envelope
			if json.Unmarshal(in.Envelope, &approved) != nil {
				return ErrDenied
			}
			if in.Action == "converge_projection" && proof.ObservedProjectionDigest != approved.Intent.ProjectionFingerprint {
				return ErrDenied
			}
			if in.Action == "set_replicas" || in.Action == "converge_projection" {
				rep := approved.Replicas
				if approved.Version != "utcp.infrastructure.v1" {
					rep = approved.Intent.Replicas
				}
				if proof.Replicas == nil || *proof.Replicas != rep {
					return ErrDenied
				}
			}
			outcome = "confirmed_applied"
		case "absence":
			if i.State != "retiring" || in.Action != "retire_delete" || proof.Outcome != "absent" || proof.ObservedUID != "" || proof.ResourceVersion != "" {
				return ErrDenied
			}
			var pending bool
			// An exact-UID higher-generation retirement cleanup may contain
			// its immutable recorded non-create predecessors. Fresh exact
			// absence, rather than DELETE acceptance, is the proof that
			// delayed patches against that UID can no longer activate it.
			if x = t.QueryRow(ctx, `SELECT EXISTS(
 SELECT 1 FROM infrastructure_dispatches d
 WHERE d.incarnation_id=$1 AND d.state IN ('reserved','dispatching','outcome_unknown') AND d.id<>$2
 AND NOT EXISTS (
  SELECT 1 FROM infrastructure_dispatches cleanup
  JOIN infrastructure_intents cleanup_intent ON cleanup_intent.id=cleanup.intent_id
  JOIN infrastructure_intents predecessor ON predecessor.id=d.intent_id
  WHERE cleanup.id=$2 AND cleanup.incarnation_id=$1 AND cleanup.recovery
  AND cleanup.intent_id=$3 AND cleanup_intent.action='retire_delete'
  AND cleanup.state IN ('dispatching','outcome_unknown')
  AND d.generation<cleanup.generation AND NOT d.recovery
  AND d.state IN ('dispatching','outcome_unknown') AND predecessor.action<>'create_suspended'
  AND d.id::text IN (SELECT json_array_elements_text(cleanup.blocked_ids))
 ))`, i.ID, c.DispatchID, in.ID).Scan(&pending); x != nil {
				return x
			}
			if pending {
				return ErrConflict
			}
			if _, x = t.Exec(ctx, "UPDATE infrastructure_incarnations SET state='retired',updated_at=clock_timestamp() WHERE id=$1", i.ID); x != nil {
				return x
			}
			outcome = "confirmed_applied"
		default:
			return ErrDenied
		}
		if c.DispatchID != "" {
			var state string
			var recovery bool
			var blocked []byte
			if x = t.QueryRow(ctx, "SELECT state,recovery,blocked_ids FROM infrastructure_dispatches WHERE id=$1 AND intent_id=$2 FOR UPDATE", c.DispatchID, in.ID).Scan(&state, &recovery, &blocked); x != nil {
				return ErrDenied
			}
			if state != "dispatching" && state != "outcome_unknown" {
				return ErrDenied
			}
			if recovery {
				var ids []string
				if json.Unmarshal(blocked, &ids) != nil {
					return ErrDenied
				}
				for _, id := range ids {
					if _, x = t.Exec(ctx, "UPDATE infrastructure_dispatches SET state='contained',updated_at=clock_timestamp() WHERE id=$1 AND incarnation_id=$2 AND state IN ('dispatching','outcome_unknown')", id, i.ID); x != nil {
						return x
					}
				}
			}
			if _, x = t.Exec(ctx, "UPDATE infrastructure_dispatches SET state=$2,updated_at=clock_timestamp() WHERE id=$1", c.DispatchID, outcome); x != nil {
				return x
			}
		}
		if _, x = t.Exec(ctx, "UPDATE infrastructure_intents SET outcome=$2,updated_at=clock_timestamp() WHERE id=$1", in.ID, outcome); x != nil {
			return x
		}
		if _, x = t.Exec(ctx, "INSERT INTO infrastructure_observation_receipts(id,issuer,receipt_id,challenge_id,envelope_hash,safe_envelope) VALUES($1,$2,$3,$4,$5,$6)", ID(), proof.Issuer, proof.ReceiptID, c.ID, Hash(raw), proof.SafeEnvelope); x != nil {
			return x
		}
		if _, x = t.Exec(ctx, "UPDATE infrastructure_observation_challenges SET consumed_at=clock_timestamp() WHERE id=$1", id); x != nil {
			return x
		}
		return event(ctx, t, i, "observation_accepted", in.OperationID)
	})
}

// recoveryEvidence permits fresh, authenticated observation of a previously
// consumed barrier even after canonical supersession. It grants no dispatch or
// lifecycle authority: only its exact historical marker/effect can discharge
// delivery uncertainty and contain that barrier's immutable predecessors.
func recoveryEvidence(ctx context.Context, t pgx.Tx, i Incarnation, in Intent, purpose, dispatch string) (bool, error) {
	if purpose != "effect" || dispatch == "" || i.UID == nil || (i.State != "bound" && i.State != "retiring") || (in.Action != "admit_generation" && in.Action != "converge_projection") {
		return false, nil
	}
	var recovery bool
	var state string
	e := t.QueryRow(ctx, "SELECT recovery,state FROM infrastructure_dispatches WHERE id=$1 AND intent_id=$2 AND incarnation_id=$3 FOR UPDATE", dispatch, in.ID, i.ID).Scan(&recovery, &state)
	if e != nil {
		return false, ErrDenied
	}
	return recovery && (state == "dispatching" || state == "outcome_unknown"), nil
}
