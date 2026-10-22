package integration_test

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"sync"
	"testing"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	effects "utcp.invalid/components/kubernetes-effects"
)

func TestE2AsteriskIndependentLaravelParity(t *testing.T) {
	ctx, _, s := env(t)
	s.SetDomainPolicy(kernel.NewAsteriskDomainPolicy())
	path := os.Getenv("UTCP_CP1_E2_ORACLE")
	if path == "" {
		t.Skip("E2 independent Laravel oracle requires isolated verifier")
	}
	b, err := os.ReadFile(path)
	must(t, err)
	var oracle struct {
		Claims []struct {
			Name, Kind string
			Action     json.RawMessage
			Authorized bool
		}
	}
	must(t, json.Unmarshal(b, &oracle))
	positive := map[string]int{}
	negative := map[string]int{}
	for _, c := range oracle.Claims {
		t.Run(c.Name, func(t *testing.T) {
			err := s.Verify(ctx, c.Action)
			if (err == nil) != c.Authorized {
				t.Fatalf("PHP authorized=%v, Go authorized=%v: %v", c.Authorized, err == nil, err)
			}
			if c.Authorized {
				positive[c.Kind]++
			} else {
				negative[c.Kind]++
			}
		})
	}
	for _, k := range []string{"fence", "restore"} {
		if positive[k] == 0 || negative[k] < 10 {
			t.Fatalf("missing meaningful %s parity coverage: positive=%d negative=%d", k, positive[k], negative[k])
		}
	}
	t.Logf("Independent PHP/Go policy decisions: %d, positive fence=%d restoration=%d", len(oracle.Claims), positive["fence"], positive["restore"])
}

type e2Fixture struct {
	I                                            kernel.Incarnation
	Raw                                          []byte
	Op, Source, Conference, Binding, Replacement string
}

func e2DomainFixture(t *testing.T, ctx context.Context, p *pgxpool.Pool, s *kernel.Service, restore bool) e2Fixture {
	t.Helper()
	s.SetDomainPolicy(kernel.NewAsteriskDomainPolicy())
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	f := e2Fixture{I: i}
	if restore {
		f.Raw = projection(t, ctx, p, i)
		var a map[string]any
		must(t, json.Unmarshal(f.Raw, &a))
		f.Op = a["operation_id"].(string)
		f.Source, _ = operation(t, ctx, p, i, "runtime.node.runtime.fence")
		sourcePayload := map[string]any{"configuration_generation": 7, "runtime_fence_provenance": map[string]any{"runtime_node_disabled": map[string]any{"by_operation": true, "operation_id": f.Source, "runtime_node_id": i.RuntimeNodeID}, "scale_to_zero_requested": map[string]any{"operation_id": f.Source, "namespace": i.Namespace, "deployment": i.Name, "pre_scale_replicas": 1}}}
		b, e := json.Marshal(sourcePayload)
		must(t, e)
		_, e = p.Exec(ctx, "UPDATE runtime_operations SET status='succeeded',completed_at=now(),payload=$2 WHERE id=$1", f.Source, b)
		must(t, e)
		payload := map[string]any{"tenant_id": i.TenantID, "runtime_node_id": i.RuntimeNodeID, "requested_desired_state": "active", "source_fence_operation_id": f.Source, "source_fence_generation": 7, "workload_namespace": i.Namespace, "deployment": i.Name, "target_replicas": 1, "expected_runtime_node_configuration_version": 1}
		b, e = json.Marshal(payload)
		must(t, e)
		_, e = p.Exec(ctx, "UPDATE runtime_operations SET operation_type='runtime.node.restore',payload=$2 WHERE id=$1", f.Op, b)
		must(t, e)
		_, e = p.Exec(ctx, "UPDATE runtime_nodes SET desired_state='disabled' WHERE id=$1", i.RuntimeNodeID)
		must(t, e)
	} else {
		op, c := operation(t, ctx, p, i, "runtime.node.runtime.fence")
		f.Op = op
		f.Conference = kernel.UUID()
		f.Binding = kernel.UUID()
		f.Replacement = kernel.UUID()
		_, e := p.Exec(ctx, `INSERT INTO conferences(id,tenant_id,slug,display_name,runtime_node_id,desired_state,configuration_generation,created_at,updated_at) VALUES($1,$2,$3,'Synthetic E2 conference',$4,'open',1,now(),now())`, f.Conference, i.TenantID, "e2-"+kernel.ID(), i.RuntimeNodeID)
		must(t, e)
		_, e = p.Exec(ctx, `INSERT INTO conference_runtime_bindings(id,tenant_id,conference_id,runtime_node_id,status,bound_at,created_at,updated_at) VALUES($1,$2,$3,$4,'active',now(),now(),now())`, f.Binding, i.TenantID, f.Conference, i.RuntimeNodeID)
		must(t, e)
		_, e = p.Exec(ctx, `INSERT INTO runtime_nodes(id,tenant_id,name,slug,runtime_family,adapter_key,desired_state,observed_state,configuration_version,capacity_weight,created_at,updated_at) VALUES($1,$2,'Synthetic E2 replacement',$3,'simulator','simulator-deterministic','active','ready',1,1,now(),now())`, f.Replacement, i.TenantID, "e2-replacement-"+kernel.ID())
		must(t, e)
		for _, cap := range []string{"conference.lifecycle", "conference.participation"} {
			_, e = p.Exec(ctx, `INSERT INTO runtime_node_capabilities(id,runtime_node_id,capability_key,created_at,updated_at) VALUES($1,$2,$3,now(),now())`, kernel.UUID(), f.Replacement, cap)
			must(t, e)
		}
		b, e := json.Marshal(map[string]any{"conference_id": f.Conference, "former_runtime_binding_id": f.Binding, "former_runtime_node_id": i.RuntimeNodeID, "configuration_generation": 1})
		must(t, e)
		_, e = p.Exec(ctx, "UPDATE runtime_operations SET payload=$2 WHERE id=$1", op, b)
		must(t, e)
		a := effects.Action{Version: effects.Version, RequestID: kernel.ID(), OperationID: op, TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, Target: effects.Target{ClusterID: i.ClusterID, Namespace: i.Namespace, Kind: "Deployment", Name: i.Name, TargetID: i.TargetID, IncarnationID: i.ID, ExpectedUID: *i.UID, ExpectedRV: "42", RuntimeNodeSlug: i.Slug}, Generation: i.Generation, Action: "set_replicas", Replicas: 0, IdempotencyKey: kernel.ID(), Claim: effects.Claim{Attempt: c.Attempt, Token: c.Token, Expires: c.Expires}, Deadline: time.Now().UTC().Add(10 * time.Minute).Truncate(time.Second), CorrelationID: kernel.ID(), AuditID: kernel.ID()}
		a.Fingerprint = effects.Fingerprint(a)
		f.Raw, e = json.Marshal(a)
		must(t, e)
	}
	_, e := s.PersistIntent(ctx, f.Raw)
	must(t, e)
	must(t, s.Verify(ctx, f.Raw))
	return f
}

func e2AddConferenceSQL(f e2Fixture, node string) (string, []any) {
	c, b := kernel.UUID(), kernel.UUID()
	return `WITH c AS (INSERT INTO conferences(id,tenant_id,slug,display_name,runtime_node_id,desired_state,configuration_generation,created_at,updated_at) VALUES($1,$2,$3,'E2 racing conference',$4,'open',1,now(),now()) RETURNING id) INSERT INTO conference_runtime_bindings(id,tenant_id,conference_id,runtime_node_id,status,bound_at,created_at,updated_at) SELECT $5,$2,id,$4,'active',now(),now(),now() FROM c`, []any{c, f.I.TenantID, "e2-race-" + kernel.ID(), node, b}
}
func e2CallSQL(f e2Fixture) (string, []any) {
	call, leg := kernel.UUID(), kernel.UUID()
	return `WITH c AS (INSERT INTO calls(id,tenant_id,direction,observed_state,runtime_node_id,created_at,updated_at) VALUES($1,$2,'outbound','originating',$3,now(),now()) RETURNING id) INSERT INTO call_legs(id,tenant_id,call_id,runtime_node_id,direction,role,observed_state,created_at,updated_at) SELECT $4,$2,id,$3,'outbound','originator','originating',now(),now() FROM c`, []any{call, f.I.TenantID, f.Replacement, leg}
}

// waitBlocked checks PostgreSQL's actual lock wait, not a scheduling sleep.
func e2WaitBlocked(t *testing.T, ctx context.Context, p *pgxpool.Pool, pid uint32) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		var blocked bool
		must(t, p.QueryRow(ctx, "SELECT COALESCE(wait_event_type='Lock',false) FROM pg_stat_activity WHERE pid=$1", pid).Scan(&blocked))
		if blocked {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("independent PostgreSQL writer did not enter expected lock wait")
}

func TestE2AsteriskPredicatePhantomSerialization(t *testing.T) {
	cases := []struct {
		name     string
		restore  bool
		mutation func(e2Fixture) (string, []any)
	}{
		{"new-active-callleg", false, func(f e2Fixture) (string, []any) { return e2CallSQL(f) }},
		{"new-conference-binding", false, func(f e2Fixture) (string, []any) { return e2AddConferenceSQL(f, f.Replacement) }},
		{"target-readiness-change", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET observed_state='ready' WHERE id=$1", []any{f.I.RuntimeNodeID}
		}},
		{"replacement-capacity-change", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET capacity_weight=1,desired_state='disabled' WHERE id=$1", []any{f.Replacement}
		}},
		{"replacement-availability-change", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET observed_state='unavailable' WHERE id=$1", []any{f.Replacement}
		}},
		{"capability-removal", false, func(f e2Fixture) (string, []any) {
			return "DELETE FROM runtime_node_capabilities WHERE runtime_node_id=$1 AND capability_key='conference.lifecycle'", []any{f.Replacement}
		}},
		{"placement-observation-required", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET placement_region='proof-region' WHERE id=$1", []any{f.Replacement}
		}},
		{"conference-generation-change", false, func(f e2Fixture) (string, []any) {
			return "UPDATE conferences SET configuration_generation=configuration_generation+1 WHERE id=$1", []any{f.Conference}
		}},
		{"binding-release", false, func(f e2Fixture) (string, []any) {
			return "UPDATE conference_runtime_bindings SET status='retired' WHERE id=$1", []any{f.Binding}
		}},
		{"restoration-configuration-change", true, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET configuration_version=configuration_version+1 WHERE id=$1", []any{f.I.RuntimeNodeID}
		}},
		{"restoration-active-binding", true, func(f e2Fixture) (string, []any) { return e2AddConferenceSQL(f, f.I.RuntimeNodeID) }},
		{"restoration-new-fence", true, func(f e2Fixture) (string, []any) {
			return `INSERT INTO runtime_operations(id,tenant_id,operation_type,aggregate_type,aggregate_id,runtime_node_id,payload_version,payload,status,priority,correlation_id,request_id,attempt_count,max_attempts,available_at,created_at,updated_at) VALUES($1,$2,'runtime.node.runtime.fence','runtime_node',$3::text,$3::uuid,1,'{}','pending',100,$1,$1,0,3,now(),now(),now())`, []any{kernel.ID(), f.I.TenantID, f.I.RuntimeNodeID}
		}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ctx, p, s := env(t)
			f := e2DomainFixture(t, ctx, p, s, tc.restore)
			entered, release := make(chan struct{}), make(chan struct{})
			s.SetDomainPolicy(&e2PausedPolicy{inner: kernel.NewAsteriskDomainPolicy(), entered: entered, release: release})
			decision := make(chan error, 1)
			go func() { decision <- s.Verify(ctx, f.Raw) }()
			<-entered
			conn, err := p.Acquire(ctx)
			must(t, err)
			defer conn.Release()
			pid := conn.Conn().PgConn().PID()
			sql, args := tc.mutation(f)
			done := make(chan error, 1)
			go func() { _, e := conn.Exec(ctx, sql, args...); done <- e }()
			e2WaitBlocked(t, ctx, p, pid)
			close(release)
			must(t, <-decision)
			must(t, <-done)
			s.SetDomainPolicy(kernel.NewAsteriskDomainPolicy())
			denied(t, s.Verify(ctx, f.Raw))
			t.Log("PostgreSQL READ COMMITTED predicate guard blocked writer; next authorization rejected changed domain state")
		})
	}
}

func TestE2AsteriskReserveConsumeRechecks(t *testing.T) {
	cases := []struct {
		name     string
		restore  bool
		mutation func(e2Fixture) (string, []any)
	}{
		{"reserve-after-policy-change", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET observed_state='ready' WHERE id=$1", []any{f.I.RuntimeNodeID}
		}},
		{"consume-after-policy-change", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET observed_state='ready' WHERE id=$1", []any{f.I.RuntimeNodeID}
		}},
		{"consume-after-new-fence", true, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_operations SET status='pending',completed_at=NULL WHERE id=$1", []any{f.Source}
		}},
		{"consume-after-configuration-change", true, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_nodes SET configuration_version=2 WHERE id=$1", []any{f.I.RuntimeNodeID}
		}},
		{"consume-after-attempt-rollover", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_operations SET attempt_count=2 WHERE id=$1", []any{f.Op}
		}},
		{"consume-after-lease-expiry", false, func(f e2Fixture) (string, []any) {
			return "UPDATE runtime_operations SET lease_expires_at=now()-interval '1 second' WHERE id=$1", []any{f.Op}
		}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ctx, p, s := env(t)
			f := e2DomainFixture(t, ctx, p, s, tc.restore)
			req := kernel.ReserveRequest{Envelope: f.Raw, Executor: "e2-executor", Capability: kernel.ID() + kernel.ID()}
			var r kernel.Reservation
			var err error
			if tc.name != "reserve-after-policy-change" {
				r, err = s.Reserve(ctx, req)
				must(t, err)
			}
			sql, args := tc.mutation(f)
			_, err = p.Exec(ctx, sql, args...)
			must(t, err)
			if tc.name == "reserve-after-policy-change" {
				_, err = s.Reserve(ctx, req)
			} else {
				_, err = s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: req.Executor, Capability: req.Capability, Envelope: f.Raw})
			}
			denied(t, err)
			var consumed int
			must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_dispatches WHERE operation_id=$1 AND state NOT IN ('reserved','cancelled')", f.Op).Scan(&consumed))
			if consumed != 0 {
				t.Fatal("denied changed policy consumed dispatch authority")
			}
		})
	}
}

func TestE2AsteriskConcurrentRestorationAndRetirement(t *testing.T) {
	ctx, p, s := env(t)
	f := e2DomainFixture(t, ctx, p, s, true)
	req := kernel.ReserveRequest{Envelope: f.Raw, Executor: "e2-executor", Capability: kernel.ID() + kernel.ID()}
	r, err := s.Reserve(ctx, req)
	must(t, err)
	var wg sync.WaitGroup
	start := make(chan struct{})
	errs := make(chan error, 2)
	grants := make(chan bool, 2)
	for j := 0; j < 2; j++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			x, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: req.Executor, Capability: req.Capability, Envelope: f.Raw})
			grants <- x.Granted
			errs <- e
		}()
	}
	close(start)
	wg.Wait()
	close(errs)
	close(grants)
	for e := range errs {
		must(t, e)
	}
	count := 0
	for g := range grants {
		if g {
			count++
		}
	}
	if count != 1 {
		t.Fatalf("concurrent restoration dispatch grants=%d", count)
	}
	_, err = s.Retire(ctx, f.I.TenantID, f.I.ID)
	must(t, err)
	denied(t, s.Verify(ctx, f.Raw))
	// A fresh kernel instance has no in-memory grant state; consumed history and
	// retirement remain authoritative after recreation.
	fresh := kernel.New(p, nil)
	fresh.SetDomainPolicy(kernel.NewAsteriskDomainPolicy())
	state, err := fresh.Status(ctx, r.ID, req.Executor)
	must(t, err)
	if state.ID != r.ID {
		t.Fatal("durable restoration reservation missing")
	}
	t.Logf("one concurrent restoration dispatch grant; terminal claim denial; persisted dispatch %s", fmt.Sprint(state.State))
	denied(t, fresh.Verify(ctx, f.Raw))
}

// Pause only after the real policy has checked its predicates inside Service's
// guarded transaction. Ordinary legacy writers must wait until that commits.
type e2PausedPolicy struct {
	inner   *kernel.AsteriskDomainPolicy
	entered chan struct{}
	release chan struct{}
}

func (p *e2PausedPolicy) Lock(ctx context.Context, tx pgx.Tx) error { return p.inner.Lock(ctx, tx) }
func (p *e2PausedPolicy) Check(ctx context.Context, tx pgx.Tx, i kernel.Incarnation, a kernel.Envelope, o kernel.Operation) error {
	if err := p.inner.Check(ctx, tx, i, a, o); err != nil {
		return err
	}
	close(p.entered)
	select {
	case <-p.release:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func TestE2AsteriskPolicyLockTimeout(t *testing.T) {
	ctx, p, s := env(t)
	f := e2DomainFixture(t, ctx, p, s, false)
	tx, e := p.Begin(ctx)
	must(t, e)
	defer tx.Rollback(ctx)
	_, e = tx.Exec(ctx, "UPDATE runtime_nodes SET updated_at=now() WHERE id=$1", f.I.RuntimeNodeID)
	must(t, e)
	e = s.Verify(ctx, f.Raw)
	if e != kernel.ErrConflict {
		t.Fatalf("policy lock timeout normalized as %v, want ErrConflict", e)
	}
}

func TestE2AsteriskAuthorizationDoesNotEmitExecutionSuccess(t *testing.T) {
	ctx, p, s := env(t)
	f := e2DomainFixture(t, ctx, p, s, false)
	counts := func() (int, int) {
		var a, o int
		must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1", f.I.ID).Scan(&a))
		must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_outbox_messages WHERE aggregate_id=$1", f.I.ID).Scan(&o))
		return a, o
	}
	beforeA, beforeO := counts()
	must(t, s.Verify(ctx, f.Raw))
	must(t, s.Verify(ctx, f.Raw))
	_, e := p.Exec(ctx, "UPDATE runtime_nodes SET observed_state='ready' WHERE id=$1", f.I.RuntimeNodeID)
	must(t, e)
	denied(t, s.Verify(ctx, f.Raw))
	denied(t, s.Verify(ctx, f.Raw))
	afterA, afterO := counts()
	if beforeA != afterA || beforeO != afterO {
		t.Fatalf("authorization emitted canonical execution evidence: audit %d/%d outbox %d/%d", beforeA, afterA, beforeO, afterO)
	}
	var status string
	var complete bool
	must(t, p.QueryRow(ctx, "SELECT status,completed_at IS NOT NULL FROM runtime_operations WHERE id=$1", f.Op).Scan(&status, &complete))
	if status != "leased" || complete {
		t.Fatal("authorization changed operation execution outcome")
	}
}

func TestE2AsteriskConsumptionSerializesBeforeRetirement(t *testing.T) {
	ctx, p, s := env(t)
	f := e2DomainFixture(t, ctx, p, s, true)
	req := kernel.ReserveRequest{Envelope: f.Raw, Executor: "e2-executor", Capability: kernel.ID() + kernel.ID()}
	r, e := s.Reserve(ctx, req)
	must(t, e)
	entered, release := make(chan struct{}), make(chan struct{})
	s.SetDomainPolicy(&e2PausedPolicy{inner: kernel.NewAsteriskDomainPolicy(), entered: entered, release: release})
	consumed := make(chan error, 1)
	go func() {
		x, err := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: req.Executor, Capability: req.Capability, Envelope: f.Raw})
		if err == nil && !x.Granted {
			err = fmt.Errorf("first consumption was not granted")
		}
		consumed <- err
	}()
	<-entered
	retired := make(chan error, 1)
	go func() { _, err := s.Retire(ctx, f.I.TenantID, f.I.ID); retired <- err }()
	blocked := false
	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		must(t, p.QueryRow(ctx, "SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock' AND query ILIKE 'LOCK TABLE%')").Scan(&blocked))
		if blocked {
			break
		}
		time.Sleep(time.Millisecond)
	}
	if !blocked {
		close(release)
		t.Fatal("retirement did not wait behind actual consumption transaction")
	}
	close(release)
	must(t, <-consumed)
	// The unchanged 2s policy lock guard may fail closed while a successful
	// consumption commit waits for durable storage. Retry that exact conflict
	// only after consumption has committed; all other retirement errors fail.
	retirementErr := <-retired
	if retirementErr == kernel.ErrConflict {
		_, retirementErr = s.Retire(ctx, f.I.TenantID, f.I.ID)
	}
	must(t, retirementErr)
	s.SetDomainPolicy(kernel.NewAsteriskDomainPolicy())
	denied(t, s.Verify(ctx, f.Raw))
	again, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: req.Executor, Capability: req.Capability, Envelope: f.Raw})
	must(t, e)
	if again.Granted {
		t.Fatal("retired replay granted another dispatch")
	}
}
