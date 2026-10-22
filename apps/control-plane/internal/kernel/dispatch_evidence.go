package kernel

import (
	"context"
	"encoding/json"
	"time"

	"github.com/jackc/pgx/v5"
)

// Dispatch evidence is deliberately separate from resource observation. A trusted
// executor's finalized single-attempt journal can prove non-delivery; a GET cannot.
type EvidenceChallengeRequest struct{ TenantID, IncarnationID, DispatchID, Audience string }
type DispatchEvidenceChallenge struct {
	ID, Nonce, Audience, TenantID, RuntimeNodeID, IncarnationID, ClusterID, Namespace, Name, ExpectedUID string
	IntentID, DispatchID, OperationID, Executor, EnvelopeHash, Generation, Fingerprint, Action           string
	Attempt                                                                                              int
	IssuedAt, ExpiresAt                                                                                  time.Time
}
type DispatchEvidence struct {
	Challenge                        DispatchEvidenceChallenge
	ReceiptID, Issuer, EvidenceType  string
	Submissions                      int
	Final                            bool
	JournalID                        string
	APICode                          int
	APIReason                        string
	StartedAt, FinishedAt, ExpiresAt time.Time
	SafeEnvelope                     json.RawMessage
}
type DispatchEvidenceVerifier interface {
	VerifyDispatchEvidence(context.Context, []byte, DispatchEvidenceChallenge) (DispatchEvidence, error)
}

func (s *Service) readEvidenceChallenge(ctx context.Context, id string) (c DispatchEvidenceChallenge, e error) {
	e = s.Pool.QueryRow(ctx, `SELECT c.id,c.nonce,c.audience,i.tenant_id::text,i.runtime_node_id::text,i.id,i.cluster_id,i.namespace,i.physical_name,c.expected_uid,n.id::text,d.id,d.operation_id,d.executor,d.envelope_hash,d.generation::text,n.fingerprint,n.action,d.attempt,c.created_at,c.expires_at FROM infrastructure_dispatch_evidence_challenges c JOIN infrastructure_dispatches d ON d.id=c.dispatch_id JOIN infrastructure_intents n ON n.id=d.intent_id JOIN infrastructure_incarnations i ON i.id=d.incarnation_id WHERE c.id=$1`, id).Scan(&c.ID, &c.Nonce, &c.Audience, &c.TenantID, &c.RuntimeNodeID, &c.IncarnationID, &c.ClusterID, &c.Namespace, &c.Name, &c.ExpectedUID, &c.IntentID, &c.DispatchID, &c.OperationID, &c.Executor, &c.EnvelopeHash, &c.Generation, &c.Fingerprint, &c.Action, &c.Attempt, &c.IssuedAt, &c.ExpiresAt)
	return
}

// evidenceCurrent rejects attempt rollover and supersession. Lease expiration is
// not proof of non-delivery, nor does it invalidate historical negative evidence:
// accepting evidence grants no execution permission. A new reserve/consume must
// independently pass the complete current claim and domain checks.
func evidenceCurrent(ctx context.Context, t pgx.Tx, i Incarnation, in Intent, attempt int) error {
	if in.Superseded || in.Generation != i.Generation || i.State == "retired" {
		return ErrDenied
	}
	o, e := operation(ctx, t, in.OperationID)
	if e != nil || o.Attempt != attempt || o.TenantID != i.TenantID || o.RuntimeNodeID != i.RuntimeNodeID || (o.Status != "leased" && o.Status != "running") {
		return ErrDenied
	}
	var current bool
	if e = t.QueryRow(ctx, `SELECT cancelled_at IS NULL FROM runtime_operations WHERE id=$1`, in.OperationID).Scan(&current); e != nil || !current {
		return ErrDenied
	}
	switch in.Action {
	case "create_suspended":
		if i.State != "preparing" || i.UID != nil {
			return ErrDenied
		}
	case "retire_delete":
		if i.State != "retiring" || i.UID == nil {
			return ErrDenied
		}
	default:
		if i.State != "bound" || i.UID == nil {
			return ErrDenied
		}
	}
	return nil
}

func (s *Service) EvidenceChallenge(ctx context.Context, r EvidenceChallengeRequest) (c DispatchEvidenceChallenge, e error) {
	if len(r.Audience) == 0 || len(r.Audience) > 256 {
		return c, ErrDenied
	}
	e = s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, r.TenantID, r.IncarnationID)
		if x != nil {
			return x
		}
		var inID, state string
		if x = t.QueryRow(ctx, `SELECT intent_id::text,state FROM infrastructure_dispatches WHERE id=$1 AND incarnation_id=$2`, r.DispatchID, i.ID).Scan(&inID, &state); x != nil {
			return ErrDenied
		}
		in, x := scanIntent(t.QueryRow(ctx, "SELECT "+intentColumns+" FROM infrastructure_intents WHERE id=$1 FOR UPDATE", inID))
		if x != nil {
			return ErrDenied
		}
		c = DispatchEvidenceChallenge{ID: ID(), Nonce: ID() + ID(), Audience: r.Audience, TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, IncarnationID: i.ID, ClusterID: i.ClusterID, Namespace: i.Namespace, Name: i.Name, IntentID: in.ID, DispatchID: r.DispatchID, OperationID: in.OperationID, Generation: in.Generation, Fingerprint: in.Fingerprint, Action: in.Action}
		if i.UID != nil {
			c.ExpectedUID = *i.UID
		}
		if x = t.QueryRow(ctx, `SELECT attempt,executor,envelope_hash,state FROM infrastructure_dispatches WHERE id=$1 FOR UPDATE`, r.DispatchID).Scan(&c.Attempt, &c.Executor, &c.EnvelopeHash, &state); x != nil {
			return x
		}
		if state != "reserved" && state != "dispatching" && state != "outcome_unknown" {
			return ErrDenied
		}
		if x = evidenceCurrent(ctx, t, i, in, c.Attempt); x != nil {
			return x
		}
		if x = t.QueryRow(ctx, `SELECT clock_timestamp(),clock_timestamp()+interval '30 seconds'`).Scan(&c.IssuedAt, &c.ExpiresAt); x != nil {
			return x
		}
		_, x = t.Exec(ctx, `INSERT INTO infrastructure_dispatch_evidence_challenges(id,nonce,dispatch_id,audience,expected_uid,created_at,expires_at) VALUES($1,$2,$3,$4,$5,$6,$7)`, c.ID, c.Nonce, c.DispatchID, c.Audience, c.ExpectedUID, c.IssuedAt, c.ExpiresAt)
		return x
	})
	return
}

// AcceptDispatchEvidence resolves only the exact dispatch attempt. It never
// binds a UID, marks successful execution, completes retirement or grants retry.
func (s *Service) AcceptDispatchEvidence(ctx context.Context, id string, raw []byte, v DispatchEvidenceVerifier) error {
	if v == nil || len(raw) > 32768 {
		return ErrDenied
	}
	var recorded string
	e := s.Pool.QueryRow(ctx, `SELECT envelope_hash FROM infrastructure_dispatch_evidence_receipts WHERE challenge_id=$1`, id).Scan(&recorded)
	if e == nil {
		if recorded == Hash(raw) {
			return nil
		}
		return ErrDenied
	}
	if e != pgx.ErrNoRows {
		return e
	}
	c, e := s.readEvidenceChallenge(ctx, id)
	if e != nil {
		return ErrDenied
	}
	proof, e := v.VerifyDispatchEvidence(ctx, raw, c)
	if e != nil {
		return ErrDenied
	}
	if proof.Challenge != c || proof.ReceiptID == "" || proof.Issuer == "" || !proof.Final || proof.JournalID == "" {
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
		var used *time.Time
		var valid bool
		var uid string
		if x = t.QueryRow(ctx, `SELECT consumed_at,expires_at>clock_timestamp(),expected_uid FROM infrastructure_dispatch_evidence_challenges WHERE id=$1 FOR UPDATE`, c.ID).Scan(&used, &valid, &uid); x != nil {
			return x
		}
		if used != nil {
			var accepted string
			if x = t.QueryRow(ctx, `SELECT envelope_hash FROM infrastructure_dispatch_evidence_receipts WHERE challenge_id=$1`, id).Scan(&accepted); x == nil && accepted == Hash(raw) {
				return nil
			}
			return ErrDenied
		}
		if !valid || uid != c.ExpectedUID || (i.UID == nil && c.ExpectedUID != "") || (i.UID != nil && *i.UID != c.ExpectedUID) {
			return ErrDenied
		}
		if x = evidenceCurrent(ctx, t, i, in, c.Attempt); x != nil {
			return x
		}
		var state, executor, hash string
		var attempt int
		if x = t.QueryRow(ctx, `SELECT state,executor,envelope_hash,attempt FROM infrastructure_dispatches WHERE id=$1 AND intent_id=$2 AND operation_id=$3 FOR UPDATE`, c.DispatchID, in.ID, c.OperationID).Scan(&state, &executor, &hash, &attempt); x != nil {
			return ErrDenied
		}
		if executor != c.Executor || hash != c.EnvelopeHash || attempt != c.Attempt {
			return ErrDenied
		}
		targetState := "confirmed_not_applied"
		switch proof.EvidenceType {
		case "pre_dispatch_rejected":
			if state != "reserved" || proof.Submissions != 0 || proof.APICode != 0 || proof.APIReason != "" {
				return ErrDenied
			}
			targetState = "cancelled"
		case "definitive_non_delivery":
			if (state != "dispatching" && state != "outcome_unknown") || proof.Submissions != 0 || proof.APICode != 0 || proof.APIReason != "" {
				return ErrDenied
			}
		case "api_rejected":
			if (state != "dispatching" && state != "outcome_unknown") || proof.Submissions != 1 || !DefinitiveAPIRejection(proof.APICode, proof.APIReason) {
				return ErrDenied
			}
		default:
			return ErrDenied
		}
		var timely bool
		if x = t.QueryRow(ctx, `SELECT $1::timestamptz >= $2 AND $1<=clock_timestamp()+interval '5 seconds' AND $1>=clock_timestamp()-interval '30 seconds' AND $3::timestamptz>clock_timestamp()`, proof.StartedAt, c.IssuedAt, proof.ExpiresAt).Scan(&timely); x != nil || !timely {
			return ErrDenied
		}
		if _, x = t.Exec(ctx, `UPDATE infrastructure_dispatches SET state=$2,updated_at=clock_timestamp() WHERE id=$1`, c.DispatchID, targetState); x != nil {
			return x
		}
		if _, x = t.Exec(ctx, `UPDATE infrastructure_intents SET outcome='confirmed_not_applied',updated_at=clock_timestamp() WHERE id=$1`, in.ID); x != nil {
			return x
		}
		if _, x = t.Exec(ctx, `INSERT INTO infrastructure_dispatch_evidence_receipts(id,issuer,receipt_id,challenge_id,envelope_hash,safe_envelope) VALUES($1,$2,$3,$4,$5,$6)`, ID(), proof.Issuer, proof.ReceiptID, c.ID, Hash(raw), proof.SafeEnvelope); x != nil {
			return x
		}
		if _, x = t.Exec(ctx, `UPDATE infrastructure_dispatch_evidence_challenges SET consumed_at=clock_timestamp() WHERE id=$1`, id); x != nil {
			return x
		}
		return event(ctx, t, i, "dispatch_nonapplication_confirmed", in.OperationID)
	})
}

// DefinitiveAPIRejection accepts structured, terminal Kubernetes Status failure
// categories only. Timeouts, 429, 5xx, proxy failures and arbitrary 409/422 text
// never establish nonapplication. The trusted single-attempt journal must also
// attest the response came from the target API for this exact external request.
func DefinitiveAPIRejection(code int, reason string) bool {
	switch code {
	case 400:
		return reason == "BadRequest"
	case 401:
		return reason == "Unauthorized"
	case 403:
		return reason == "Forbidden"
	case 404:
		return reason == "NotFound"
	case 409:
		return reason == "Conflict" || reason == "AlreadyExists"
	case 422:
		return reason == "Invalid"
	}
	return false
}
