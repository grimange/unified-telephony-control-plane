package kernel

import (
	"context"
	"encoding/json"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"time"
)

func (s *Service) tx(ctx context.Context, f func(pgx.Tx) error) error {
	for n := 0; n < 3; n++ {
		t, e := s.Pool.BeginTx(ctx, pgx.TxOptions{IsoLevel: pgx.ReadCommitted})
		if e != nil {
			return e
		}
		// Predicate policies acquire their guard before any row locks. This also
		// protects lifecycle changes racing with reserve/consume in this service.
		if guarded, ok := s.policy.(interface {
			Lock(context.Context, pgx.Tx) error
		}); ok {
			e = guarded.Lock(ctx, t)
		}
		if e == nil {
			e = f(t)
		}
		if e == nil {
			e = t.Commit(ctx)
		}
		c, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		_ = t.Rollback(c)
		cancel()
		if e == nil {
			return nil
		}
		pe, ok := e.(*pgconn.PgError)
		if ok && pe.Code == "55P03" {
			return ErrConflict
		}
		if !ok || (pe.Code != "40001" && pe.Code != "40P01") {
			return e
		}
	}
	return ErrConflict
}

type scanner interface{ Scan(...any) error }

const incarnationColumns = "id,tenant_id::text,runtime_node_id::text,cluster_id,namespace,physical_name,runtime_node_slug,target_id,state,generation::text,profile,kubernetes_uid,configuration"

func scanInc(r scanner) (Incarnation, error) {
	var i Incarnation
	var c []byte
	e := r.Scan(&i.ID, &i.TenantID, &i.RuntimeNodeID, &i.ClusterID, &i.Namespace, &i.Name, &i.Slug, &i.TargetID, &i.State, &i.Generation, &i.Profile, &i.UID, &c)
	if e == nil {
		e = json.Unmarshal(c, &i.Configuration)
	}
	return i, e
}
func (s *Service) Read(ctx context.Context, tenant, id string) (Incarnation, error) {
	i, e := scanInc(s.Pool.QueryRow(ctx, "SELECT "+incarnationColumns+" FROM infrastructure_incarnations WHERE id=$1 AND tenant_id=$2", id, tenant))
	if e == pgx.ErrNoRows {
		return i, ErrDenied
	}
	return i, e
}

// lock respects RuntimeNode -> incarnation -> intent -> operation ordering.
func (s *Service) lock(ctx context.Context, t pgx.Tx, tenant, id string) (Incarnation, error) {
	var node string
	if e := t.QueryRow(ctx, "SELECT runtime_node_id::text FROM infrastructure_incarnations WHERE id=$1 AND tenant_id=$2", id, tenant).Scan(&node); e != nil {
		return Incarnation{}, ErrDenied
	}
	var found string
	if e := t.QueryRow(ctx, "SELECT id::text FROM runtime_nodes WHERE id=$1 AND tenant_id=$2 FOR UPDATE", node, tenant).Scan(&found); e != nil {
		return Incarnation{}, ErrDenied
	}
	return scanInc(t.QueryRow(ctx, "SELECT "+incarnationColumns+" FROM infrastructure_incarnations WHERE id=$1 AND tenant_id=$2 FOR UPDATE", id, tenant))
}
func event(ctx context.Context, t pgx.Tx, i Incarnation, action, op string) error {
	rid := ID()
	meta := map[string]any{"incarnation_id": i.ID}
	if op != "" {
		meta["runtime_operation_id"] = op
	}
	payload, _ := json.Marshal(meta)
	audit, _ := json.Marshal(map[string]any{"version": 1, "data": meta})
	_, e := t.Exec(ctx, `INSERT INTO control_plane_audit_records(id,tenant_id,actor_type,action,subject_type,subject_id,request_id,correlation_id,metadata,occurred_at,created_at) VALUES($1,$2,'system',$3,'infrastructure_incarnation',$4,$5,$5,$6,clock_timestamp(),clock_timestamp())`, ID(), i.TenantID, "infrastructure."+action, i.ID, rid, audit)
	if e != nil {
		return e
	}
	_, e = t.Exec(ctx, `INSERT INTO control_plane_outbox_messages(id,tenant_id,aggregate_type,aggregate_id,event_type,event_version,payload,correlation_id,request_id,occurred_at,available_at,attempt_count,dispatch_status,created_at,updated_at) VALUES($1,$2,'infrastructure_incarnation',$3,$4,1,$5,$6,$6,clock_timestamp(),clock_timestamp(),0,'pending',clock_timestamp(),clock_timestamp())`, ID(), i.TenantID, i.ID, "infrastructure."+action, payload, rid)
	return e
}
func supersede(ctx context.Context, t pgx.Tx, id string) error {
	if _, e := t.Exec(ctx, "UPDATE infrastructure_dispatches SET state='cancelled',updated_at=clock_timestamp() WHERE incarnation_id=$1 AND state='reserved'", id); e != nil {
		return e
	}
	_, e := t.Exec(ctx, "UPDATE infrastructure_intents SET superseded=true,updated_at=clock_timestamp() WHERE incarnation_id=$1 AND NOT superseded", id)
	return e
}
func (s *Service) Prepare(ctx context.Context, r PrepareRequest) (i Incarnation, err error) {
	if !identifier.MatchString(r.ClusterID) || !identifier.MatchString(r.IdempotencyKey) || !dns.MatchString(r.Namespace) || len(r.Namespace) > 63 || len(r.Configuration) != 4 {
		return i, ErrDenied
	}
	for _, k := range []string{"image", "configuration_id", "configuration_sha256", "secret_name"} {
		if r.Configuration[k] == "" {
			return i, ErrDenied
		}
	}
	if !hex64.MatchString(r.Configuration["configuration_sha256"]) || !identifier.MatchString(r.Configuration["configuration_id"]) || !pinnedImage.MatchString(r.Configuration["image"]) || len(r.Configuration["secret_name"]) > 63 || !dns.MatchString(r.Configuration["secret_name"]) {
		return i, ErrDenied
	}
	err = s.tx(ctx, func(t pgx.Tx) error {
		var family, slug string
		if e := t.QueryRow(ctx, "SELECT runtime_family,slug FROM runtime_nodes WHERE id=$1 AND tenant_id=$2 FOR UPDATE", r.RuntimeNodeID, r.TenantID).Scan(&family, &slug); e != nil {
			return ErrDenied
		}
		if r.Profile != family+".managed.v1" || !dns.MatchString(slug) {
			return ErrDenied
		}
		old, e := scanInc(t.QueryRow(ctx, "SELECT "+incarnationColumns+" FROM infrastructure_incarnations WHERE tenant_id=$1 AND runtime_node_id=$2 AND prepare_key=$3", r.TenantID, r.RuntimeNodeID, r.IdempotencyKey))
		c, _ := json.Marshal(r.Configuration)
		if e == nil {
			oc, _ := json.Marshal(old.Configuration)
			if old.ClusterID != r.ClusterID || old.Namespace != r.Namespace || old.Profile != r.Profile || string(oc) != string(c) {
				return ErrConflict
			}
			i = old
			return nil
		}
		if e != pgx.ErrNoRows {
			return e
		}
		i = Incarnation{ID: ID(), TenantID: r.TenantID, RuntimeNodeID: r.RuntimeNodeID, ClusterID: r.ClusterID, Namespace: r.Namespace, Slug: slug, TargetID: r.RuntimeNodeID, State: "preparing", Generation: "1", Profile: r.Profile, Configuration: r.Configuration}
		i.Name = "utcp-" + i.ID
		_, e = t.Exec(ctx, `INSERT INTO infrastructure_incarnations(id,tenant_id,runtime_node_id,cluster_id,namespace,physical_name,runtime_node_slug,target_id,state,generation,profile,configuration,prepare_key,created_at,updated_at) VALUES($1,$2,$3,$4,$5,$6,$7,$11,'preparing',1,$8,$9,$10,clock_timestamp(),clock_timestamp())`, i.ID, r.TenantID, r.RuntimeNodeID, r.ClusterID, r.Namespace, i.Name, slug, r.Profile, c, r.IdempotencyKey, r.RuntimeNodeID)
		if e != nil {
			return e
		}
		return event(ctx, t, i, "prepared", "")
	})
	return
}
func (s *Service) NextGeneration(ctx context.Context, tenant, id string) (g string, e error) {
	e = s.tx(ctx, func(t pgx.Tx) error {
		i, x := s.lock(ctx, t, tenant, id)
		if x != nil {
			return x
		}
		if i.State != "bound" && i.State != "preparing" {
			return ErrDenied
		}
		g, x = Increment(i.Generation)
		if x != nil {
			return x
		}
		_, x = t.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=$2,updated_at=clock_timestamp() WHERE id=$1", id, g)
		if x != nil {
			return x
		}
		if x = supersede(ctx, t, id); x != nil {
			return x
		}
		return event(ctx, t, i, "generation_allocated", "")
	})
	return
}
func (s *Service) Retire(ctx context.Context, tenant, id string) (i Incarnation, e error) {
	e = s.tx(ctx, func(t pgx.Tx) error {
		var x error
		i, x = s.lock(ctx, t, tenant, id)
		if x != nil {
			return x
		}
		if i.State == "retiring" || i.State == "retired" {
			return nil
		}
		g, x := Increment(i.Generation)
		if x != nil {
			return x
		}
		_, x = t.Exec(ctx, "UPDATE infrastructure_incarnations SET state='retiring',generation=$2,updated_at=clock_timestamp() WHERE id=$1", id, g)
		if x != nil {
			return x
		}
		i.State = "retiring"
		i.Generation = g
		if x = supersede(ctx, t, id); x != nil {
			return x
		}
		return event(ctx, t, i, "retiring", "")
	})
	return
}
