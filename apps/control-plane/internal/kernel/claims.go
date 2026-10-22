package kernel

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"github.com/jackc/pgx/v5"
)

// DomainPolicy owns trusted provider domain eligibility; absence is fail-closed
// for fencing/restoration, never an implicit permission.
type DomainPolicy interface {
	Check(context.Context, pgx.Tx, Incarnation, Envelope, Operation) error
}
type Operation struct {
	Type, Status, Token, TenantID, RuntimeNodeID string
	Attempt                                      int
	Payload                                      json.RawMessage
}

func (s *Service) SetDomainPolicy(p DomainPolicy) { s.policy = p }
func (s *Service) target(i Incarnation, a Envelope) error {
	return denyIf(a.TenantID == i.TenantID && a.RuntimeNodeID == i.RuntimeNodeID && a.Generation == i.Generation && a.Target.IncarnationID == i.ID && a.Target.ClusterID == i.ClusterID && a.Target.Namespace == i.Namespace && a.Target.Name == i.Name && a.Target.Slug == i.Slug && a.Target.TargetID == i.TargetID && same(a.Target.UID, i.UID))
}
func (s *Service) policyCheck(ctx context.Context, t pgx.Tx, i Incarnation, a Envelope, o Operation) error {
	required := "bound"
	if a.Action == "create_suspended" {
		required = "preparing"
	}
	if a.Action == "retire_delete" {
		required = "retiring"
	}
	if i.State != required {
		return ErrDenied
	}
	rep := a.Replicas
	if a.Version != "utcp.infrastructure.v1" {
		rep = a.Intent.Replicas
	}
	if a.Version == "utcp.infrastructure.v3" {
		if a.CanonicalState != "bound" || a.Intent.Projection["template_version"] != i.Profile {
			return ErrDenied
		}
		for k, v := range i.Configuration {
			if a.Intent.Projection[k] != v {
				return ErrDenied
			}
		}
	}
	if a.Version == "utcp.infrastructure.v2" && a.Action == "converge_projection" && rep != 0 {
		return ErrDenied
	}
	if a.Version == "utcp.infrastructure.v1" && rep > 0 {
		var found bool
		e := t.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM infrastructure_intents WHERE incarnation_id=$1 AND outcome='confirmed_applied' AND action='converge_projection' AND envelope->>'contract_version'='utcp.infrastructure.v3' AND envelope->'intent'->'projection'->>'template_version'=$2)`, i.ID, i.Profile).Scan(&found)
		if e != nil {
			return e
		}
		if !found {
			return ErrDenied
		}
	}
	corridors := map[string][]string{"runtime.node.provision": {"create_suspended", "converge_projection", "admit_generation"}, "runtime.node.workload.converge": {"converge_projection", "admit_generation"}, "runtime.node.deprovision": {"retire_delete"}, "runtime.node.runtime.fence": {"admit_generation", "set_replicas"}, "runtime.node.restore": {"admit_generation", "set_replicas", "converge_projection"}}
	allowed := false
	for _, x := range corridors[o.Type] {
		if x == a.Action {
			allowed = true
		}
	}
	if !allowed {
		return ErrDenied
	}
	var family, desired string
	if e := t.QueryRow(ctx, "SELECT runtime_family,desired_state FROM runtime_nodes WHERE id=$1", i.RuntimeNodeID).Scan(&family, &desired); e != nil {
		return e
	}
	if i.Profile != family+".managed.v1" {
		return ErrDenied
	}
	if o.Type == "runtime.node.runtime.fence" || o.Type == "runtime.node.restore" {
		if family != "asterisk" || s.policy == nil {
			return ErrDenied
		}
		return s.policy.Check(ctx, t, i, a, o)
	}
	if rep > 0 && desired != "active" {
		return ErrDenied
	}
	return nil
}
func operation(ctx context.Context, t pgx.Tx, id string) (Operation, error) {
	var o Operation
	e := t.QueryRow(ctx, "SELECT operation_type,status,COALESCE(lease_token,''),COALESCE(tenant_id::text,''),COALESCE(runtime_node_id::text,''),attempt_count,payload FROM runtime_operations WHERE id=$1 FOR UPDATE", id).Scan(&o.Type, &o.Status, &o.Token, &o.TenantID, &o.RuntimeNodeID, &o.Attempt, &o.Payload)
	return o, e
}

const intentColumns = "id::text,incarnation_id,operation_id,generation::text,action,fingerprint,outcome,superseded,envelope"

func scanIntent(r scanner) (i Intent, e error) {
	e = r.Scan(&i.ID, &i.IncarnationID, &i.OperationID, &i.Generation, &i.Action, &i.Fingerprint, &i.Outcome, &i.Superseded, &i.Envelope)
	return
}
func (s *Service) PersistIntent(ctx context.Context, b []byte) (out Intent, e error) {
	a, durable, e := s.parse(b)
	if e != nil {
		return out, e
	}
	e = s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, a.TenantID, a.Target.IncarnationID)
		if x != nil {
			return x
		}
		if x = s.target(i, a); x != nil {
			return x
		}
		old, x := scanIntent(t.QueryRow(ctx, "SELECT "+intentColumns+" FROM infrastructure_intents WHERE incarnation_id=$1 AND generation=$2 AND action=$3 FOR UPDATE", i.ID, a.Generation, a.Action))
		if x != nil && x != pgx.ErrNoRows {
			return x
		}
		o, x := operation(ctx, t, a.OperationID)
		if x != nil {
			return ErrDenied
		}
		if o.TenantID != i.TenantID || o.RuntimeNodeID != i.RuntimeNodeID || (o.Status != "pending" && o.Status != "leased" && o.Status != "running" && o.Status != "retry_scheduled") {
			return ErrDenied
		}
		if x = s.policyCheck(ctx, t, i, a, o); x != nil {
			return x
		}
		if old.ID != "" {
			if old.Superseded || canonical(old.Envelope) != canonical(durable) {
				return ErrConflict
			}
			out = old
			return nil
		}
		var conflict bool
		if x = t.QueryRow(ctx, "SELECT EXISTS(SELECT 1 FROM infrastructure_intents WHERE incarnation_id=$1 AND generation=$2 AND fingerprint<>$3)", i.ID, a.Generation, a.Fingerprint).Scan(&conflict); x != nil {
			return x
		}
		if conflict {
			return ErrConflict
		}
		out = Intent{ID: UUID(), IncarnationID: i.ID, OperationID: a.OperationID, Generation: a.Generation, Action: a.Action, Fingerprint: a.Fingerprint, Outcome: "not_dispatched", Envelope: durable}
		_, x = t.Exec(ctx, `INSERT INTO infrastructure_intents(id,incarnation_id,operation_id,generation,action,fingerprint,envelope,outcome,superseded,created_at,updated_at) VALUES($1,$2,$3,$4,$5,$6,$7,'not_dispatched',false,clock_timestamp(),clock_timestamp())`, out.ID, i.ID, a.OperationID, a.Generation, a.Action, a.Fingerprint, durable)
		if x != nil {
			return x
		}
		return event(ctx, t, i, "intent_persisted", a.OperationID)
	})
	return
}

// verifyLocked requires the incarnation lock, then locks intent and operation.
func (s *Service) verifyLocked(ctx context.Context, t pgx.Tx, i Incarnation, a Envelope, durable []byte, consume bool) (Intent, error) {
	var zero Intent
	if e := s.target(i, a); e != nil {
		return zero, e
	}
	in, e := scanIntent(t.QueryRow(ctx, "SELECT "+intentColumns+" FROM infrastructure_intents WHERE incarnation_id=$1 AND generation=$2 AND action=$3 FOR UPDATE", i.ID, a.Generation, a.Action))
	if e != nil {
		return zero, ErrDenied
	}
	if in.Superseded || canonical(in.Envelope) != canonical(durable) {
		return zero, ErrDenied
	}
	if in.Outcome != "not_dispatched" && in.Outcome != "dispatch_rejected" && in.Outcome != "confirmed_not_applied" && in.Outcome != "confirmed_applied" {
		return zero, ErrConflict
	}
	o, e := operation(ctx, t, a.OperationID)
	if e != nil {
		return zero, ErrDenied
	}
	if o.Status != "leased" && o.Status != "running" {
		return zero, ErrDenied
	}
	if o.TenantID != i.TenantID || o.RuntimeNodeID != i.RuntimeNodeID || o.Attempt != a.Claim.Attempt || subtle.ConstantTimeCompare([]byte(o.Token), []byte(a.Claim.Token)) != 1 || o.Token == "" {
		return zero, ErrDenied
	}
	var valid bool
	e = t.QueryRow(ctx, "SELECT lease_expires_at>clock_timestamp() AND lease_expires_at=$2 AND $3::timestamptz>clock_timestamp() AND (expires_at IS NULL OR expires_at>clock_timestamp()) AND cancelled_at IS NULL FROM runtime_operations WHERE id=$1", a.OperationID, a.Claim.Expires, a.Deadline).Scan(&valid)
	if e != nil || !valid {
		return zero, ErrDenied
	}
	if e = s.policyCheck(ctx, t, i, a, o); e != nil {
		return zero, e
	}
	return in, nil
}
func (s *Service) Verify(ctx context.Context, b []byte) error {
	a, d, e := s.parse(b)
	if e != nil {
		return e
	}
	return s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, a.TenantID, a.Target.IncarnationID)
		if x != nil {
			return x
		}
		_, x = s.verifyLocked(ctx, t, i, a, d, false)
		return x
	})
}
