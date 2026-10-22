// Package integration_test proves durable authority using independent PostgreSQL
// connections and actual child-process termination. It creates no infrastructure.
package integration_test

import (
	"bufio"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	infra "github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	effects "utcp.invalid/components/kubernetes-effects"
	"utcp.invalid/components/kubernetes-effects/active"
	"utcp.invalid/components/kubernetes-effects/lifecycle"
)

func env(t *testing.T) (context.Context, *pgxpool.Pool, *kernel.Service) {
	t.Helper()
	dsn := os.Getenv("UTCP_CP1_DSN")
	if dsn == "" {
		t.Skip("real PostgreSQL proof requires UTCP_CP1_DSN from isolated verifier")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	t.Cleanup(cancel)
	cfg, e := pgxpool.ParseConfig(dsn)
	if e != nil {
		t.Fatal("invalid disposable database configuration")
	}
	cfg.MaxConns = 16
	p, e := pgxpool.NewWithConfig(ctx, cfg)
	if e != nil {
		t.Fatal("cannot connect disposable database")
	}
	t.Cleanup(p.Close)
	var version int
	if e = p.QueryRow(ctx, "SELECT current_setting('server_version_num')::int").Scan(&version); e != nil || version < 170000 || version >= 180000 {
		t.Fatalf("PostgreSQL17 required: %d %v", version, e)
	}
	root := os.Getenv("UTCP_CP1_ROOT")
	if root == "" {
		root = filepath.Clean("../../..")
	}
	v, e := infra.NewValidator(root)
	if e != nil {
		t.Fatal(e)
	}
	return ctx, p, kernel.New(p, v)
}
func must(t *testing.T, e error) {
	t.Helper()
	if e != nil {
		t.Fatal(e)
	}
}
func denied(t *testing.T, e error) {
	t.Helper()
	if e == nil {
		t.Fatal("unauthorized operation succeeded")
	}
}
func fixture(t *testing.T, ctx context.Context, p *pgxpool.Pool, s *kernel.Service) kernel.Incarnation {
	t.Helper()
	tenant, node := kernel.UUID(), kernel.UUID()
	slug := "cp1-" + kernel.ID()[:12]
	_, e := p.Exec(ctx, "INSERT INTO tenants(id,slug,display_name,status,created_at,updated_at) VALUES($1,$2,'Synthetic CP1 proof','active',now(),now())", tenant, slug)
	must(t, e)
	_, e = p.Exec(ctx, `INSERT INTO runtime_nodes(id,tenant_id,name,slug,runtime_family,adapter_key,desired_state,observed_state,configuration_version,created_at,updated_at) VALUES($1,$2,'Synthetic CP1 proof',$3,'asterisk','asterisk-ari','active','unobserved',1,now(),now())`, node, tenant, slug)
	must(t, e)
	i, e := s.Prepare(ctx, kernel.PrepareRequest{TenantID: tenant, RuntimeNodeID: node, ClusterID: "cp1-proof-cluster", Namespace: "cp1-proof", Profile: "asterisk.managed.v1", IdempotencyKey: "prepare", Configuration: map[string]string{"image": "registry.example.test/utcp/asterisk-ari@sha256:" + strings.Repeat("a", 64), "configuration_id": "configuration-immutable-v1", "configuration_sha256": strings.Repeat("a", 64), "secret_name": "runtime-config-v1"}})
	must(t, e)
	return i
}
func prepareRequest(i kernel.Incarnation) kernel.PrepareRequest {
	return kernel.PrepareRequest{TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, ClusterID: i.ClusterID, Namespace: i.Namespace, Profile: i.Profile, IdempotencyKey: "prepare", Configuration: i.Configuration}
}
func target(i kernel.Incarnation) lifecycle.Target {
	return lifecycle.Target{ClusterID: i.ClusterID, Namespace: i.Namespace, Kind: "Deployment", Name: i.Name, RuntimeNodeSlug: i.Slug, TargetID: i.TargetID, IncarnationID: i.ID, ExpectedUID: i.UID, ExpectedRV: func() *string {
		if i.UID != nil {
			return lifecycle.String("42")
		}
		return nil
	}()}
}
func operation(t *testing.T, ctx context.Context, p *pgxpool.Pool, i kernel.Incarnation, kind string) (string, lifecycle.Claim) {
	t.Helper()
	op := kernel.ID()
	c := lifecycle.Claim{Attempt: 1, Token: kernel.ID(), Expires: time.Now().UTC().Add(20 * time.Minute).Truncate(time.Second)}
	_, e := p.Exec(ctx, `INSERT INTO runtime_operations(id,tenant_id,operation_type,aggregate_type,aggregate_id,runtime_node_id,payload_version,payload,status,priority,correlation_id,request_id,attempt_count,max_attempts,available_at,lease_owner,lease_token,lease_expires_at,created_at,updated_at) VALUES($1,$2,$3,'runtime_node',$4,$7,1,'{}','leased',100,$1,$1,1,3,now(),'cp1-proof',$5,$6,now(),now())`, op, i.TenantID, kind, i.RuntimeNodeID, c.Token, c.Expires, i.RuntimeNodeID)
	must(t, e)
	return op, c
}
func createAction(t *testing.T, ctx context.Context, p *pgxpool.Pool, i kernel.Incarnation) []byte {
	t.Helper()
	op, c := operation(t, ctx, p, i, "runtime.node.provision")
	pr := lifecycle.Projection{TemplateVersion: "inert.pause.v1", Image: lifecycle.PauseImage, ConfigurationID: i.Configuration["configuration_id"], ConfigurationSHA256: i.Configuration["configuration_sha256"]}
	a := lifecycle.Action{Version: lifecycle.Version, RequestID: kernel.ID(), OperationID: op, TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, Target: target(i), Generation: i.Generation, Action: "create_suspended", Intent: lifecycle.Intent{Kind: "create_suspended", Projection: &pr, ProjectionFingerprint: lifecycle.ProjectionFingerprint(pr)}, IdempotencyKey: kernel.ID(), Claim: c, Deadline: time.Now().UTC().Add(10 * time.Minute).Truncate(time.Second), CorrelationID: kernel.ID(), AuditID: kernel.ID()}
	a.Fingerprint = lifecycle.Fingerprint(a)
	b, e := json.Marshal(a)
	must(t, e)
	return b
}
func projection(t *testing.T, ctx context.Context, p *pgxpool.Pool, i kernel.Incarnation) []byte {
	t.Helper()
	op, c := operation(t, ctx, p, i, "runtime.node.workload.converge")
	pr := active.Projection{TemplateVersion: i.Profile, Image: i.Configuration["image"], ConfigurationID: i.Configuration["configuration_id"], ConfigurationSHA256: i.Configuration["configuration_sha256"], SecretName: i.Configuration["secret_name"]}
	a := active.Action{Version: active.Version, CanonicalState: "bound", RequestID: kernel.ID(), OperationID: op, TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, Target: target(i), Generation: i.Generation, Action: "converge_projection", Intent: active.Intent{Kind: "converge_projection", Projection: &pr, ProjectionFingerprint: active.ProjectionFingerprint(pr), Replicas: active.Replicas(1)}, IdempotencyKey: kernel.ID(), Claim: active.Claim{Attempt: c.Attempt, Token: c.Token, Expires: c.Expires}, Deadline: time.Now().UTC().Add(10 * time.Minute).Truncate(time.Second), CorrelationID: kernel.ID(), AuditID: kernel.ID()}
	a.Fingerprint = active.Fingerprint(a)
	b, e := json.Marshal(a)
	must(t, e)
	return b
}
func signed(t *testing.T, c kernel.ReceiptChallenge, uid, outcome string) ([]byte, infra.ReceiptVerifier) {
	t.Helper()
	pub, key, e := ed25519.GenerateKey(rand.Reader)
	must(t, e)
	now := time.Now().UTC()
	p := infra.ReceiptPayload{ContractVersion: infra.ReceiptVersion, ReceiptID: kernel.ID(), Issuer: "cp1-observer", KeyID: "ephemeral", ChallengeID: c.ID, Nonce: c.Nonce, Purpose: c.Purpose, Audience: c.Audience, IntentID: c.IntentID, DispatchID: c.DispatchID, EnvelopeHash: c.EnvelopeHash, TenantID: c.TenantID, RuntimeNodeID: c.RuntimeNodeID, ClusterID: c.ClusterID, Namespace: c.Namespace, Name: c.Name, IncarnationID: c.IncarnationID, ExpectedUID: c.ExpectedUID, ObservedUID: uid, ResourceVersion: "42", Outcome: outcome, Complete: true, StartedAt: now, FinishedAt: now, ExpiresAt: now.Add(20 * time.Second), Source: "exact_get", Generation: c.Generation, Fingerprint: c.Fingerprint, EffectDigest: c.EffectDigest}
	if outcome == "absent" {
		p.ResourceVersion = ""
	} else {
		p.ObservedTenantID = c.TenantID
		p.ObservedRuntimeNodeID = c.RuntimeNodeID
		p.ObservedIncarnationID = c.IncarnationID
		if c.Purpose == "bind" {
			p.AdmittedGeneration = c.Generation
			p.AdmittedFingerprint = c.Fingerprint
			p.AppliedGeneration = c.Generation
			p.AppliedFingerprint = c.Fingerprint
			p.ObservedProjectionDigest = c.ProjectionDigest
			zero := 0
			p.Replicas = &zero
		}
		if c.Purpose == "effect" {
			p.AdmittedGeneration = c.Generation
			p.AdmittedFingerprint = c.Fingerprint
			p.AppliedGeneration = c.Generation
			p.AppliedFingerprint = c.Fingerprint
			p.ObservedProjectionDigest = c.ProjectionDigest
			one := 1
			p.Replicas = &one
		}
	}
	r, e := infra.SignReceipt(p, key)
	must(t, e)
	b, e := json.Marshal(r)
	must(t, e)
	v := infra.ReceiptVerifier{Policy: infra.TrustPolicy{Keys: map[string]infra.TrustedKey{"ephemeral": {Issuer: p.Issuer, PeerSAN: "cp1-observer", ClusterID: c.ClusterID, Namespace: c.Namespace, Purposes: []string{"bind", "effect", "absence"}, PublicKey: pub, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour)}}, MaxAge: 30 * time.Second, ClockSkew: 5 * time.Second}, PeerSAN: "cp1-observer"}
	return b, v
}
func bind(t *testing.T, ctx context.Context, p *pgxpool.Pool, s *kernel.Service, i kernel.Incarnation) kernel.Incarnation {
	t.Helper()
	raw := createAction(t, ctx, p, i)
	in, e := s.PersistIntent(ctx, raw)
	must(t, e)
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: in.ID, Purpose: "bind", Audience: "cp1-kernel"})
	must(t, e)
	b, v := signed(t, c, "uid-"+kernel.ID(), "found")
	e = s.AcceptReceipt(ctx, c.ID, b, v)
	must(t, e)
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	return i
}

func TestPostgresPrepareGenerationAndRollback(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	const n = 12
	start := make(chan struct{})
	var wg sync.WaitGroup
	ids := make(chan string, n)
	errs := make(chan error, n)
	for j := 0; j < n; j++ {
		wg.Add(1)
		go func() { defer wg.Done(); <-start; x, e := s.Prepare(ctx, prepareRequest(i)); ids <- x.ID; errs <- e }()
	}
	close(start)
	wg.Wait()
	close(ids)
	close(errs)
	for e := range errs {
		must(t, e)
	}
	for id := range ids {
		if id != i.ID {
			t.Fatal("idempotent prepare allocated competing identity")
		}
	}
	var audits, outbox int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1 AND action='infrastructure.prepared'", i.ID).Scan(&audits))
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_outbox_messages WHERE aggregate_id=$1 AND event_type='infrastructure.prepared'", i.ID).Scan(&outbox))
	if audits != 1 || outbox != 1 {
		t.Fatalf("idempotent events audit=%d outbox=%d", audits, outbox)
	}
	start = make(chan struct{})
	gens := make(chan int64, n)
	errs = make(chan error, n)
	for j := 0; j < n; j++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			g, e := s.NextGeneration(ctx, i.TenantID, i.ID)
			if e == nil {
				x, _ := strconv.ParseInt(g, 10, 64)
				gens <- x
			}
			errs <- e
		}()
	}
	close(start)
	wg.Wait()
	close(gens)
	close(errs)
	for e := range errs {
		must(t, e)
	}
	var got []int64
	for g := range gens {
		got = append(got, g)
	}
	sort.Slice(got, func(a, b int) bool { return got[a] < got[b] })
	if len(got) != n {
		t.Fatal("lost allocation")
	}
	for j, g := range got {
		if g != int64(j+2) {
			t.Fatalf("generation %d = %d", j, g)
		}
	}
	tx, e := p.Begin(ctx)
	must(t, e)
	_, e = tx.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=generation+1 WHERE id=$1", i.ID)
	must(t, e)
	must(t, tx.Rollback(ctx))
	x, e := s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if x.Generation != "13" {
		t.Fatal("rolled-back generation leaked")
	}
	_, e = p.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=9007199254740993 WHERE id=$1", i.ID)
	must(t, e)
	g, e := s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	if g != "9007199254740994" {
		t.Fatal("generation lost precision")
	}
	_, e = p.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=9223372036854775807 WHERE id=$1", i.ID)
	must(t, e)
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	if !errors.Is(e, kernel.ErrOverflow) {
		t.Fatalf("overflow=%v", e)
	}
	_, e = s.Read(ctx, kernel.UUID(), i.ID)
	denied(t, e)
}

func TestPostgresBindingClaimsAndRetirement(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	unbound := projection(t, ctx, p, i)
	_, e := s.PersistIntent(ctx, unbound)
	denied(t, e)
	i = bind(t, ctx, p, s, i)
	if i.State != "bound" || i.UID == nil {
		t.Fatal("trusted binding not durable")
	}
	_, e = p.Exec(ctx, "UPDATE infrastructure_incarnations SET kubernetes_uid='replacement' WHERE id=$1", i.ID)
	denied(t, e)
	raw := projection(t, ctx, p, i)
	_, e = s.PersistIntent(ctx, raw)
	must(t, e)
	must(t, s.Verify(ctx, raw))
	var a map[string]any
	must(t, json.Unmarshal(raw, &a))
	claim := a["claim"].(map[string]any)
	claim["attempt"] = float64(2)
	bad, _ := json.Marshal(a)
	denied(t, s.Verify(ctx, bad))
	claim["attempt"] = float64(1)
	claim["fencing_token"] = kernel.ID()
	bad, _ = json.Marshal(a)
	denied(t, s.Verify(ctx, bad))
	r, e := s.Retire(ctx, i.TenantID, i.ID)
	must(t, e)
	if r.State != "retiring" {
		t.Fatal("retirement not durable")
	}
	denied(t, s.Verify(ctx, raw))
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	denied(t, e)
	_, e = p.Exec(ctx, "UPDATE infrastructure_incarnations SET state='bound' WHERE id=$1", i.ID)
	denied(t, e)
}

func TestPostgresReservationConsumeAndUnknown(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	raw := projection(t, ctx, p, i)
	_, e := s.PersistIntent(ctx, raw)
	must(t, e)
	cap := kernel.ID() + kernel.ID()
	req := kernel.ReserveRequest{Envelope: raw, Executor: "cp1-executor", Capability: cap}
	r, e := s.Reserve(ctx, req)
	must(t, e)
	const n = 8
	var wg sync.WaitGroup
	start := make(chan struct{})
	grants := make(chan bool, n)
	errs := make(chan error, n)
	for j := 0; j < n; j++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			x, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: req.Executor, Capability: cap, Envelope: raw})
			grants <- x.Granted
			errs <- e
		}()
	}
	close(start)
	wg.Wait()
	close(grants)
	close(errs)
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
		t.Fatalf("dispatch grants=%d", count)
	}
	_, e = s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: "other", Capability: cap, Envelope: raw})
	denied(t, e)
	must(t, s.MarkUnknown(ctx, r.ID, req.Executor))
	rows, e := s.Unresolved(ctx, i.TenantID)
	must(t, e)
	if len(rows) != 1 || rows[0].State != "outcome_unknown" {
		t.Fatal("uncertainty not retained")
	}
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "another", Capability: kernel.ID() + kernel.ID()})
	denied(t, e)
}

// A real killed process leaves a durable consumed reservation. A fresh process
// reads it without receiving a second dispatch grant.
func TestPostgresKilledWorkerRestart(t *testing.T) {
	for _, phase := range []string{"reserve", "consume", "synthetic"} {
		t.Run(phase, func(t *testing.T) {
			ctx, p, s := env(t)
			i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
			raw := projection(t, ctx, p, i)
			_, e := s.PersistIntent(ctx, raw)
			must(t, e)
			cap := kernel.ID() + kernel.ID()
			r := kernel.Reservation{}
			if phase != "reserve" {
				r, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "restart-worker", Capability: cap})
				must(t, e)
			}
			payload, e := json.Marshal(kernel.ConsumeRequest{ID: r.ID, Executor: "restart-worker", Capability: cap, Envelope: raw})
			must(t, e)
			path := filepath.Join(t.TempDir(), "worker.json")
			must(t, os.WriteFile(path, payload, 0600))
			exe, e := os.Executable()
			must(t, e)
			cmd := exec.CommandContext(ctx, exe, "-test.run=^TestCP1Worker$", "-test.v")
			cmd.Env = append(os.Environ(), "UTCP_CP1_WORKER="+phase, "UTCP_CP1_WORKER_FILE="+path)
			stdout, e := cmd.StdoutPipe()
			must(t, e)
			cmd.Stderr = os.Stderr
			must(t, cmd.Start())
			scanner := bufio.NewScanner(stdout)
			ready := false
			for scanner.Scan() {
				if strings.HasPrefix(scanner.Text(), "COMMITTED_BARRIER ") {
					r.ID = strings.TrimPrefix(scanner.Text(), "COMMITTED_BARRIER ")
					ready = true
					break
				}
			}
			if !ready {
				t.Fatal("worker failed before durable phase barrier")
			}
			must(t, cmd.Process.Kill())
			_ = cmd.Wait()
			expected := "dispatching"
			if phase == "reserve" {
				expected = "reserved"
			}
			x, e := s.Status(ctx, r.ID, "restart-worker")
			must(t, e)
			if x.State != expected {
				t.Fatalf("crash durability phase=%s state=%s", phase, x.State)
			}
			if phase == "synthetic" {
				b, e := os.ReadFile(path + ".effect")
				must(t, e)
				if string(b) != "synthetic effect accepted" {
					t.Fatal("synthetic effect barrier not reached")
				}
			}
			// The worker that created a reservation learns its identity from the durable
			// status capability rather than receiving another consumption grant.
			var request kernel.ConsumeRequest
			must(t, json.Unmarshal(payload, &request))
			request.ID = r.ID
			payload, e = json.Marshal(request)
			must(t, e)
			must(t, os.WriteFile(path, payload, 0600))
			cmd = exec.CommandContext(ctx, exe, "-test.run=^TestCP1Worker$", "-test.v")
			cmd.Env = append(os.Environ(), "UTCP_CP1_WORKER=recover", "UTCP_CP1_WORKER_FILE="+path, "UTCP_CP1_EXPECT_STATE="+expected)
			out, e := cmd.CombinedOutput()
			if e != nil {
				t.Fatalf("restart subprocess: %v: %s", e, out)
			}
			if !strings.Contains(string(out), "RECOVERED_WITHOUT_REDISPATCH") {
				t.Fatal("fresh subprocess recovery not proved")
			}
		})
	}
}
func TestCP1Worker(t *testing.T) {
	mode := os.Getenv("UTCP_CP1_WORKER")
	if mode == "" {
		t.Skip("subprocess helper")
	}
	ctx, _, s := env(t)
	path := os.Getenv("UTCP_CP1_WORKER_FILE")
	b, e := os.ReadFile(path)
	must(t, e)
	var r kernel.ConsumeRequest
	must(t, json.Unmarshal(b, &r))
	if mode == "reserve" {
		x, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: r.Envelope, Executor: r.Executor, Capability: r.Capability})
		must(t, e)
		fmt.Println("COMMITTED_BARRIER " + x.ID)
		select {}
	}
	if mode == "consume" || mode == "synthetic" {
		x, e := s.Consume(ctx, r)
		must(t, e)
		if !x.Granted {
			t.Fatal("initial dispatch not granted")
		}
		if mode == "synthetic" {
			must(t, os.WriteFile(path+".effect", []byte("synthetic effect accepted"), 0600))
		}
		fmt.Println("COMMITTED_BARRIER " + x.ID)
		select {}
	}
	x, e := s.Status(ctx, r.ID, r.Executor)
	must(t, e)
	if x.State != os.Getenv("UTCP_CP1_EXPECT_STATE") {
		t.Fatal("missing durable dispatch phase")
	}
	if x.State == "dispatching" {
		must(t, s.MarkUnknown(ctx, r.ID, r.Executor))
		x, e = s.Status(ctx, r.ID, r.Executor)
		must(t, e)
		if x.State != "outcome_unknown" {
			t.Fatal("restart uncertainty lost")
		}
	}
	fmt.Println("RECOVERED_WITHOUT_REDISPATCH")
}

func deleteAction(t *testing.T, ctx context.Context, p *pgxpool.Pool, i kernel.Incarnation) []byte {
	t.Helper()
	op, c := operation(t, ctx, p, i, "runtime.node.deprovision")
	a := lifecycle.Action{Version: lifecycle.Version, RequestID: kernel.ID(), OperationID: op, TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, Target: target(i), Generation: i.Generation, Action: "retire_delete", Intent: lifecycle.Intent{Kind: "retire_delete", RetirementID: kernel.ID(), RetirementGeneration: i.Generation, RetirementFingerprint: strings.Repeat("b", 64)}, IdempotencyKey: kernel.ID(), Claim: c, Deadline: time.Now().UTC().Add(10 * time.Minute).Truncate(time.Second), CorrelationID: kernel.ID(), AuditID: kernel.ID()}
	a.Fingerprint = lifecycle.Fingerprint(a)
	b, e := json.Marshal(a)
	must(t, e)
	return b
}
func TestPostgresSignedReceiptReplayAndTerminalAbsence(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	raw := createAction(t, ctx, p, i)
	in, e := s.PersistIntent(ctx, raw)
	must(t, e)
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: in.ID, Purpose: "bind", Audience: "cp1-kernel"})
	must(t, e)
	b, v := signed(t, c, "uid-"+kernel.ID(), "found")
	forged := append([]byte(nil), b...)
	var sr infra.SignedReceipt
	must(t, json.Unmarshal(forged, &sr))
	sr.Payload.ObservedUID = "forged-uid"
	forged, e = json.Marshal(sr)
	must(t, e)
	e = s.AcceptReceipt(ctx, c.ID, forged, v)
	denied(t, e)
	e = s.AcceptReceipt(ctx, c.ID, b, v)
	must(t, e)
	// An exact retry is a status-only idempotent acknowledgement. It must not
	// create a second binding, audit transition or receipt consumption.
	e = s.AcceptReceipt(ctx, c.ID, b, v)
	must(t, e)
	e = s.AcceptReceipt(ctx, c.ID, forged, v)
	denied(t, e)
	var count int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_observation_receipts r JOIN infrastructure_observation_challenges c ON c.id=r.challenge_id WHERE c.incarnation_id=$1", i.ID).Scan(&count))
	if count != 1 {
		t.Fatalf("receipt replay inserted duplicate %d", count)
	}
	var audits, events int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1 AND action='infrastructure.observation_accepted'", i.ID).Scan(&audits))
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_outbox_messages WHERE aggregate_id=$1 AND event_type='infrastructure.observation_accepted'", i.ID).Scan(&events))
	if audits != 1 || events != 1 {
		t.Fatalf("exact receipt retry duplicated transitions: audit=%d outbox=%d", audits, events)
	}
	i, e = s.Retire(ctx, i.TenantID, i.ID)
	must(t, e)
	raw = deleteAction(t, ctx, p, i)
	in, e = s.PersistIntent(ctx, raw)
	must(t, e)
	c, e = s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: in.ID, Purpose: "absence", Audience: "cp1-kernel"})
	must(t, e)
	b, v = signed(t, c, "", "absent")
	e = s.AcceptReceipt(ctx, c.ID, b, v)
	must(t, e)
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retired" {
		t.Fatalf("absence did not complete retirement: %s", i.State)
	}
	_, e = p.Exec(ctx, "UPDATE infrastructure_incarnations SET state='bound' WHERE id=$1", i.ID)
	denied(t, e)
	_, e = p.Exec(ctx, "DELETE FROM infrastructure_incarnations WHERE id=$1", i.ID)
	denied(t, e)
	r := prepareRequest(i)
	r.IdempotencyKey = "replacement"
	replacement, e := s.Prepare(ctx, r)
	must(t, e)
	if replacement.Name == i.Name || replacement.ID == i.ID {
		t.Fatal("retired physical identity reused")
	}
}

func TestPostgresUnknownRecoveryBarrierIsBounded(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	raw := projection(t, ctx, p, i)
	oldIntent, e := s.PersistIntent(ctx, raw)
	must(t, e)
	cap := kernel.ID() + kernel.ID()
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "old", Capability: cap})
	must(t, e)
	_, e = s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: "old", Capability: cap, Envelope: raw})
	must(t, e)
	must(t, s.MarkUnknown(ctx, r.ID, "old"))
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	fresh := projection(t, ctx, p, i)
	in, e := s.PersistIntent(ctx, fresh)
	must(t, e)
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: fresh, Executor: "recovery", Capability: kernel.ID() + kernel.ID()})
	denied(t, e)
	recoveryCap := kernel.ID() + kernel.ID()
	rr, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: fresh, Executor: "recovery", Capability: recoveryCap, Recovery: true, BlockedIDs: []string{r.ID}})
	must(t, e)
	if !rr.Recovery {
		t.Fatal("barrier reservation not marked")
	}
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: fresh, Executor: "unbounded", Capability: kernel.ID() + kernel.ID(), Recovery: true, BlockedIDs: []string{r.ID, rr.ID}})
	denied(t, e)
	granted, e := s.Consume(ctx, kernel.ConsumeRequest{ID: rr.ID, Executor: "recovery", Capability: recoveryCap, Envelope: fresh})
	must(t, e)
	if !granted.Granted {
		t.Fatal("authorized recovery barrier cannot progress")
	}
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: in.ID, DispatchID: rr.ID, Purpose: "effect", Audience: "cp1-kernel"})
	must(t, e)
	b, v := signed(t, c, *i.UID, "found")
	must(t, s.AcceptReceipt(ctx, c.ID, b, v))
	old, e := s.Status(ctx, r.ID, "old")
	must(t, e)
	if old.State != "contained" {
		t.Fatalf("old uncertainty not contained: %s", old.State)
	}
	var outcome string
	must(t, p.QueryRow(ctx, "SELECT outcome FROM infrastructure_intents WHERE id=$1", oldIntent.ID).Scan(&outcome))
	if outcome != "outcome_unknown" {
		t.Fatal("recovery erased prior unknown delivery evidence")
	}
	current, e := s.Status(ctx, rr.ID, "recovery")
	must(t, e)
	if current.State != "confirmed_applied" {
		t.Fatal("trusted recovery not durably confirmed")
	}
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: fresh, Executor: "different-executor-after-confirmation", Capability: kernel.ID() + kernel.ID()})
	denied(t, e)
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	newer := projection(t, ctx, p, i)
	_, e = s.PersistIntent(ctx, newer)
	must(t, e)
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: newer, Executor: "ordinary-after-recovery", Capability: kernel.ID() + kernel.ID()})
	must(t, e)
}

func TestPostgresConcurrentReservationsAndRetirement(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	raw := projection(t, ctx, p, i)
	_, e := s.PersistIntent(ctx, raw)
	must(t, e)
	start := make(chan struct{})
	var wg sync.WaitGroup
	results := make(chan error, 2)
	for _, executor := range []string{"executor-a", "executor-b"} {
		wg.Add(1)
		go func(name string) {
			defer wg.Done()
			<-start
			_, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: name, Capability: kernel.ID() + kernel.ID()})
			results <- e
		}(executor)
	}
	close(start)
	wg.Wait()
	close(results)
	success := 0
	for e := range results {
		if e == nil {
			success++
		}
	}
	if success != 1 {
		t.Fatalf("competing reservations successes=%d", success)
	}
	_, e = s.Retire(ctx, i.TenantID, i.ID)
	must(t, e)
	rows, e := s.Unresolved(ctx, i.TenantID)
	must(t, e)
	if len(rows) != 0 {
		t.Fatal("retirement retained a dispatchable unconsumed reservation")
	}
	var cancelled int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_dispatches WHERE incarnation_id=$1 AND state='cancelled'", i.ID).Scan(&cancelled))
	if cancelled != 1 {
		t.Fatal("retirement lost cancelled reservation history")
	}
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "late", Capability: kernel.ID() + kernel.ID()})
	denied(t, e)
}

func TestPostgresAuditAppendOnlyAndIntentRedaction(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	raw := createAction(t, ctx, p, i)
	var a lifecycle.Action
	must(t, json.Unmarshal(raw, &a))
	in, e := s.PersistIntent(ctx, raw)
	must(t, e)
	if strings.Contains(string(in.Envelope), a.Claim.Token) {
		t.Fatal("durable intent leaked lease credential")
	}
	var before int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1", i.ID).Scan(&before))
	_, e = p.Exec(ctx, "UPDATE control_plane_audit_records SET action='forged' WHERE subject_id=$1", i.ID)
	denied(t, e)
	tx, e := p.Begin(ctx)
	must(t, e)
	_, e = tx.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=generation+1 WHERE id=$1", i.ID)
	must(t, e)
	_, e = tx.Exec(ctx, `INSERT INTO control_plane_audit_records(id,tenant_id,actor_type,action,subject_type,subject_id,request_id,correlation_id,metadata,occurred_at,created_at) VALUES($1,$2,'system','rollback.synthetic','infrastructure_incarnation',$3,$1,$1,'{}',now(),now())`, kernel.ID(), i.TenantID, i.ID)
	must(t, e)
	must(t, tx.Rollback(ctx))
	var after int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1", i.ID).Scan(&after))
	if before != after {
		t.Fatal("rolled-back audit event persisted")
	}
}

func TestPostgresConcurrentSignedBinding(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	raw := createAction(t, ctx, p, i)
	in, e := s.PersistIntent(ctx, raw)
	must(t, e)
	var challenges [2]kernel.ReceiptChallenge
	var receipts [2][]byte
	var verifiers [2]infra.ReceiptVerifier
	for n := range challenges {
		challenges[n], e = s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: in.ID, Purpose: "bind", Audience: "cp1-kernel"})
		must(t, e)
		receipts[n], verifiers[n] = signed(t, challenges[n], "uid-"+strconv.Itoa(n), "found")
	}
	start := make(chan struct{})
	var wg sync.WaitGroup
	results := make(chan error, 2)
	for n := 0; n < 2; n++ {
		wg.Add(1)
		go func(n int) {
			defer wg.Done()
			<-start
			results <- s.AcceptReceipt(ctx, challenges[n].ID, receipts[n], verifiers[n])
		}(n)
	}
	close(start)
	wg.Wait()
	close(results)
	count := 0
	for e := range results {
		if e == nil {
			count++
		}
	}
	if count != 1 {
		t.Fatalf("conflicting trusted UID binding accepted %d", count)
	}
	x, e := s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if x.UID == nil || (*x.UID != "uid-0" && *x.UID != "uid-1") {
		t.Fatal("bound UID corrupt")
	}
}

func TestPostgresLeaseAttemptAndGenerationSupersession(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	raw := projection(t, ctx, p, i)
	_, e := s.PersistIntent(ctx, raw)
	must(t, e)
	must(t, s.Verify(ctx, raw))
	var a active.Action
	must(t, json.Unmarshal(raw, &a))
	_, e = p.Exec(ctx, "UPDATE runtime_operations SET lease_expires_at=now()-interval '1 second' WHERE id=$1", a.OperationID)
	must(t, e)
	denied(t, s.Verify(ctx, raw))
	_, e = p.Exec(ctx, "UPDATE runtime_operations SET lease_expires_at=$2,attempt_count=2 WHERE id=$1", a.OperationID, a.Claim.Expires)
	must(t, e)
	denied(t, s.Verify(ctx, raw))
	_, e = p.Exec(ctx, "UPDATE runtime_operations SET attempt_count=1,status='cancelled',cancelled_at=now() WHERE id=$1", a.OperationID)
	must(t, e)
	denied(t, s.Verify(ctx, raw))
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	denied(t, s.Verify(ctx, raw))
	var superseded bool
	must(t, p.QueryRow(ctx, "SELECT superseded FROM infrastructure_intents WHERE operation_id=$1", a.OperationID).Scan(&superseded))
	if !superseded {
		t.Fatal("new generation did not supersede old authority")
	}
}

func TestPostgresIndependentPHPOracle(t *testing.T) {
	ctx, _, s := env(t)
	path := os.Getenv("UTCP_CP1_ORACLE")
	if path == "" {
		t.Skip("PHP oracle requires UTCP_CP1_ORACLE from pinned verifier")
	}
	b, e := os.ReadFile(path)
	must(t, e)
	var oracle struct {
		Fingerprints []struct {
			Name        string          `json:"name"`
			Action      json.RawMessage `json:"action"`
			Fingerprint string          `json:"fingerprint"`
		} `json:"fingerprints"`
		Claims []struct {
			Name       string          `json:"name"`
			Action     json.RawMessage `json:"action"`
			Authorized bool            `json:"authorized"`
		} `json:"claims"`
	}
	must(t, json.Unmarshal(b, &oracle))
	if len(oracle.Fingerprints) < 3 || len(oracle.Claims) < 6 {
		t.Fatal("independent PHP oracle must cover all versions and positive/negative claims")
	}
	for _, f := range oracle.Fingerprints {
		t.Run("fingerprint/"+f.Name, func(t *testing.T) {
			var header struct {
				Version string `json:"contract_version"`
			}
			must(t, json.Unmarshal(f.Action, &header))
			var got string
			switch header.Version {
			case lifecycle.Version:
				a, e := lifecycle.Decode(f.Action)
				must(t, e)
				got = lifecycle.Fingerprint(a)
			case active.Version:
				a, e := active.Decode(f.Action)
				must(t, e)
				got = active.Fingerprint(a)
			case "utcp.infrastructure.v1":
				var a effects.Action
				must(t, json.Unmarshal(f.Action, &a))
				got = effects.Fingerprint(a)
			default:
				t.Fatal("unsupported PHP oracle version")
			}
			if got != f.Fingerprint {
				t.Fatalf("independent fingerprint differs: PHP=%s Go=%s", f.Fingerprint, got)
			}
		})
	}
	positiveVersions := map[string]int{}
	negativeVersions := map[string]int{}
	for _, c := range oracle.Claims {
		var h struct {
			Version string `json:"contract_version"`
		}
		must(t, json.Unmarshal(c.Action, &h))
		if c.Authorized {
			positiveVersions[h.Version]++
		} else {
			negativeVersions[h.Version]++
		}
	}
	for _, version := range []string{"utcp.infrastructure.v1", lifecycle.Version, active.Version} {
		if positiveVersions[version] < 1 || negativeVersions[version] < 1 {
			t.Fatalf("PHP oracle lacks independent current positive and denied negative for %s", version)
		}
	}
	for _, c := range oracle.Claims {
		t.Run("claim/"+c.Name, func(t *testing.T) {
			e := s.Verify(ctx, c.Action)
			if (e == nil) != c.Authorized {
				t.Fatalf("PHP authorized=%v Go authorized=%v (%v)", c.Authorized, e == nil, e)
			}
		})
	}
}

func TestPostgresRetirementRacesConsumption(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	raw := projection(t, ctx, p, i)
	_, e := s.PersistIntent(ctx, raw)
	must(t, e)
	cap := kernel.ID() + kernel.ID()
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "race", Capability: cap})
	must(t, e)
	start := make(chan struct{})
	var wg sync.WaitGroup
	var consumed kernel.Reservation
	var consumeErr, retireErr error
	wg.Add(2)
	go func() {
		defer wg.Done()
		<-start
		consumed, consumeErr = s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: "race", Capability: cap, Envelope: raw})
	}()
	go func() { defer wg.Done(); <-start; _, retireErr = s.Retire(ctx, i.TenantID, i.ID) }()
	close(start)
	wg.Wait()
	must(t, retireErr)
	x, e := s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if x.State != "retiring" {
		t.Fatal("retirement lost in race")
	}
	st, e := s.Status(ctx, r.ID, "race")
	must(t, e)
	if consumeErr == nil && consumed.Granted {
		if st.State != "dispatching" {
			t.Fatal("accepted before-retirement dispatch evidence missing")
		}
	} else if st.State != "cancelled" {
		t.Fatalf("retirement did not cancel unconsumed reservation: %s", st.State)
	}
	retry, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: "race", Capability: cap, Envelope: raw})
	if e == nil && retry.Granted {
		t.Fatal("retirement permitted newly consumed dispatch")
	}
}

func TestPostgresRetirementRacesGeneration(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	start := make(chan struct{})
	var wg sync.WaitGroup
	var gen string
	var genErr, retireErr error
	wg.Add(2)
	go func() { defer wg.Done(); <-start; gen, genErr = s.NextGeneration(ctx, i.TenantID, i.ID) }()
	go func() { defer wg.Done(); <-start; _, retireErr = s.Retire(ctx, i.TenantID, i.ID) }()
	close(start)
	wg.Wait()
	must(t, retireErr)
	x, e := s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if x.State != "retiring" {
		t.Fatal("generation race lost retirement")
	}
	if genErr == nil {
		if gen != "2" || x.Generation != "3" {
			t.Fatalf("invalid serial history: allocated=%s final=%s", gen, x.Generation)
		}
	} else if x.Generation != "2" {
		t.Fatalf("denied generation advanced authority: %s", x.Generation)
	}
}

func TestPostgresInboxIdempotencyAndOutboxRetry(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	consumer, key := "cp1-inbox-"+kernel.ID(), kernel.ID()
	payload := []byte(`{"generation":"9007199254740993","kind":"synthetic"}`)
	status, e := s.InboxReceive(ctx, consumer, key, "synthetic", payload)
	must(t, e)
	if status != "accepted" {
		t.Fatal("first delivery not accepted")
	}
	status, e = s.InboxReceive(ctx, consumer, key, "synthetic", []byte(`{"kind":"synthetic","generation":"9007199254740993"}`))
	must(t, e)
	if status != "duplicate_pending" {
		t.Fatal("semantic duplicate not recognized")
	}
	_, e = s.InboxReceive(ctx, consumer, key, "synthetic", []byte(`{"generation":"2"}`))
	denied(t, e)
	rollback := errors.New("synthetic rollback")
	e = s.InboxApply(ctx, consumer, key, func(tx pgx.Tx) error {
		_, x := tx.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=generation+1 WHERE id=$1", i.ID)
		if x != nil {
			return x
		}
		return rollback
	})
	if !errors.Is(e, rollback) {
		t.Fatal("rollback not propagated")
	}
	current, e := s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if current.Generation != "1" {
		t.Fatal("failed inbox application committed")
	}
	apply := func(tx pgx.Tx) error {
		_, e := tx.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=generation+1 WHERE id=$1", i.ID)
		return e
	}
	start := make(chan struct{})
	errs := make(chan error, 2)
	var wg sync.WaitGroup
	for n := 0; n < 2; n++ {
		wg.Add(1)
		go func() { defer wg.Done(); <-start; errs <- s.InboxApply(ctx, consumer, key, apply) }()
	}
	close(start)
	wg.Wait()
	close(errs)
	for e := range errs {
		must(t, e)
	}
	current, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if current.Generation != "2" {
		t.Fatal("duplicate inbox application changed canonical state twice")
	}
	status, e = s.InboxReceive(ctx, consumer, key, "synthetic", payload)
	must(t, e)
	if status != "duplicate_processed" {
		t.Fatal("processed identity not durable")
	}
	scope, idem := "cp1-idempotency-"+kernel.ID(), kernel.ID()
	callback := func(tx pgx.Tx) (json.RawMessage, error) {
		_, e := tx.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=generation+1 WHERE id=$1", i.ID)
		return json.RawMessage(`{"result":"recorded"}`), e
	}
	for n := 0; n < 2; n++ {
		result, e := s.Idempotent(ctx, scope, idem, payload, callback)
		must(t, e)
		if !strings.Contains(string(result), "recorded") {
			t.Fatal("stable idempotent result missing")
		}
	}
	current, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if current.Generation != "3" {
		t.Fatal("idempotency callback repeated")
	}
	_, e = s.Idempotent(ctx, scope, idem, []byte(`{"different":true}`), callback)
	denied(t, e)
	var outboxID string
	must(t, p.QueryRow(ctx, "SELECT id FROM control_plane_outbox_messages WHERE aggregate_id=$1 AND event_type='infrastructure.prepared'", i.ID).Scan(&outboxID))
	_, e = p.Exec(ctx, "UPDATE control_plane_outbox_messages SET available_at='2000-01-01' WHERE id=$1", outboxID)
	must(t, e)
	claims, e := s.ClaimOutbox(ctx, "cp1-delivery", 1, 30*time.Second)
	must(t, e)
	if len(claims) != 1 || claims[0].ID != outboxID {
		t.Fatal("test outbox row not leased")
	}
	first := claims[0]
	ok, e := s.RetryOutbox(ctx, first.ID, first.Token)
	must(t, e)
	if !ok {
		t.Fatal("delivery retry not durably recorded")
	}
	ok, e = s.AckOutbox(ctx, first.ID, first.Token)
	must(t, e)
	if ok {
		t.Fatal("old token acknowledged after retry")
	}
	_, e = p.Exec(ctx, "UPDATE control_plane_outbox_messages SET available_at='2000-01-01' WHERE id=$1", outboxID)
	must(t, e)
	claims, e = s.ClaimOutbox(ctx, "cp1-delivery-restart", 1, 30*time.Second)
	must(t, e)
	if len(claims) != 1 || claims[0].ID != outboxID || claims[0].Attempt != first.Attempt+1 || claims[0].Token == first.Token {
		t.Fatal("retry did not fence delivery attempt")
	}
	ok, e = s.AckOutbox(ctx, claims[0].ID, claims[0].Token)
	must(t, e)
	if !ok {
		t.Fatal("current delivery did not commit")
	}
	ok, e = s.AckOutbox(ctx, claims[0].ID, claims[0].Token)
	must(t, e)
	if ok {
		t.Fatal("duplicate acknowledgement accepted")
	}
}

func TestPostgresExpiredReservationReleasePreservesConsumedEvidence(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	// Expiry is immutable evidence. A short caller deadline sets it at creation;
	// the database clock, rather than a mutable fixture field, proves expiry.
	expiring := func(i kernel.Incarnation) []byte {
		var a active.Action
		must(t, json.Unmarshal(projection(t, ctx, p, i), &a))
		must(t, p.QueryRow(ctx, "SELECT clock_timestamp()+interval '5 seconds'").Scan(&a.Deadline))
		a.Fingerprint = active.Fingerprint(a)
		raw, e := json.Marshal(a)
		must(t, e)
		return raw
	}
	waitExpiry := func(r kernel.Reservation) {
		_, e := p.Exec(ctx, "SELECT pg_sleep(GREATEST(EXTRACT(EPOCH FROM ($1::timestamptz-clock_timestamp())),0))", r.Expires)
		must(t, e)
		var expired bool
		must(t, p.QueryRow(ctx, "SELECT expires_at<=clock_timestamp() FROM infrastructure_dispatches WHERE id=$1", r.ID).Scan(&expired))
		if !expired {
			t.Fatal("database has not reached immutable reservation expiry")
		}
	}
	raw := expiring(i)
	_, e := s.PersistIntent(ctx, raw)
	must(t, e)
	cap := kernel.ID() + kernel.ID()
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "expired-reserved", Capability: cap})
	must(t, e)
	_, e = p.Exec(ctx, "UPDATE infrastructure_dispatches SET expires_at=now()-interval '1 second' WHERE id=$1", r.ID)
	denied(t, e)
	waitExpiry(r)
	must(t, s.ReleaseExpired(ctx, i.TenantID, i.ID))
	x, e := s.Status(ctx, r.ID, "expired-reserved")
	must(t, e)
	if x.State != "cancelled" {
		t.Fatal("unconsumed expired reservation not released")
	}
	v, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: "expired-reserved", Capability: cap, Envelope: raw})
	if e == nil && v.Granted {
		t.Fatal("cancelled reservation regranted")
	}
	// A fresh operation generation can advance after safe unconsumed release.
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	raw = expiring(i)
	_, e = s.PersistIntent(ctx, raw)
	must(t, e)
	cap = kernel.ID() + kernel.ID()
	r, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "consumed-expired", Capability: cap})
	must(t, e)
	_, e = s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: "consumed-expired", Capability: cap, Envelope: raw})
	must(t, e)
	_, e = p.Exec(ctx, "UPDATE infrastructure_dispatches SET expires_at=now()-interval '1 second' WHERE id=$1", r.ID)
	denied(t, e)
	waitExpiry(r)
	must(t, s.ReleaseExpired(ctx, i.TenantID, i.ID))
	x, e = s.Status(ctx, r.ID, "consumed-expired")
	must(t, e)
	if x.State != "dispatching" {
		t.Fatal("expiry erased consumed delivery uncertainty")
	}
}

func TestPostgresUncertainCreateIsCleanupOnlyAfterRetirement(t *testing.T) {
	ctx, p, s := env(t)
	i := fixture(t, ctx, p, s)
	raw := createAction(t, ctx, p, i)
	in, e := s.PersistIntent(ctx, raw)
	must(t, e)
	cap := kernel.ID() + kernel.ID()
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "uncertain-create", Capability: cap})
	must(t, e)
	granted, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: "uncertain-create", Capability: cap, Envelope: raw})
	must(t, e)
	if !granted.Granted {
		t.Fatal("initial create not granted")
	}
	must(t, s.MarkUnknown(ctx, r.ID, "uncertain-create"))
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	freshCreate := createAction(t, ctx, p, i)
	_, e = s.PersistIntent(ctx, freshCreate)
	must(t, e)
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: freshCreate, Executor: "forbidden-create-recovery", Capability: kernel.ID() + kernel.ID(), Recovery: true, BlockedIDs: []string{r.ID}})
	denied(t, e)
	i, e = s.Retire(ctx, i.TenantID, i.ID)
	must(t, e)
	// A late authenticated observation may bind only the old inert physical object
	// for cleanup. It must retain retiring authority and never grant activation.
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: in.ID, DispatchID: r.ID, Purpose: "bind", Audience: "cp1-kernel"})
	must(t, e)
	b, v := signed(t, c, "late-create-uid", "found")
	must(t, s.AcceptReceipt(ctx, c.ID, b, v))
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retiring" || i.UID == nil || *i.UID != "late-create-uid" {
		t.Fatal("late create observation granted activation or lost cleanup identity")
	}
	_, e = s.PersistIntent(ctx, projection(t, ctx, p, i))
	denied(t, e)
	old, e := s.Status(ctx, r.ID, "uncertain-create")
	must(t, e)
	if old.State != "confirmed_applied" {
		t.Fatal("late create evidence unresolved")
	}
	raw = deleteAction(t, ctx, p, i)
	retirement, e := s.PersistIntent(ctx, raw)
	must(t, e)
	c, e = s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: retirement.ID, Purpose: "absence", Audience: "cp1-kernel"})
	must(t, e)
	b, v = signed(t, c, "", "absent")
	must(t, s.AcceptReceipt(ctx, c.ID, b, v))
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retired" {
		t.Fatal("cleanup-only recovery cannot terminate")
	}
}

// Retirement must remain live without erasing the uncertainty of an older
// consumed bound effect. Only exact-UID deletion and signed absence contain it.
func TestPostgresRetirementContainsUnknownBoundEffect(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	raw := projection(t, ctx, p, i)
	oldIntent, e := s.PersistIntent(ctx, raw)
	must(t, e)
	oldCap := kernel.ID() + kernel.ID()
	old, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "old-bound", Capability: oldCap})
	must(t, e)
	granted, e := s.Consume(ctx, kernel.ConsumeRequest{ID: old.ID, Envelope: raw, Executor: "old-bound", Capability: oldCap})
	must(t, e)
	if !granted.Granted {
		t.Fatal("bound effect did not dispatch")
	}
	must(t, s.MarkUnknown(ctx, old.ID, "old-bound"))
	i, e = s.Retire(ctx, i.TenantID, i.ID)
	must(t, e)
	cleanup := deleteAction(t, ctx, p, i)
	intent, e := s.PersistIntent(ctx, cleanup)
	must(t, e)
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: cleanup, Executor: "ordinary-cleanup", Capability: kernel.ID() + kernel.ID()})
	denied(t, e)
	cap := kernel.ID() + kernel.ID()
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: cleanup, Executor: "retire-recovery", Capability: cap, Recovery: true, BlockedIDs: []string{old.ID}})
	must(t, e)
	result, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Envelope: cleanup, Executor: "retire-recovery", Capability: cap})
	must(t, e)
	if !result.Granted {
		t.Fatal("exact-UID retirement recovery did not progress")
	}
	duplicate, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Envelope: cleanup, Executor: "retire-recovery", Capability: cap})
	must(t, e)
	if duplicate.Granted {
		t.Fatal("retirement recovery regranted dispatch")
	}
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: intent.ID, DispatchID: r.ID, Purpose: "absence", Audience: "cp1-kernel"})
	must(t, e)
	receipt, v := signed(t, c, "", "absent")
	must(t, s.AcceptReceipt(ctx, c.ID, receipt, v))
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retired" {
		t.Fatalf("trusted absence did not terminate cleanup: %s", i.State)
	}
	preserved, e := s.Status(ctx, old.ID, "old-bound")
	must(t, e)
	if preserved.State != "contained" {
		t.Fatal("old uncertain delivery not contained")
	}
	var outcome string
	must(t, p.QueryRow(ctx, "SELECT outcome FROM infrastructure_intents WHERE id=$1", oldIntent.ID).Scan(&outcome))
	if outcome != "outcome_unknown" {
		t.Fatal("retirement erased predecessor uncertainty")
	}
	terminal, e := s.Status(ctx, r.ID, "retire-recovery")
	must(t, e)
	if terminal.State != "confirmed_applied" {
		t.Fatal("retirement absence not durably recorded")
	}
	denied(t, s.Verify(ctx, raw))
}

// Trusted late evidence for an already consumed recovery is historical evidence,
// never fresh activation authority. It can restore cleanup progress after retire.
func TestPostgresHistoricalRecoveryReceiptPreservesRetirement(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	oldRaw := projection(t, ctx, p, i)
	_, e := s.PersistIntent(ctx, oldRaw)
	must(t, e)
	oldCap := kernel.ID() + kernel.ID()
	old, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: oldRaw, Executor: "old-history", Capability: oldCap})
	must(t, e)
	_, e = s.Consume(ctx, kernel.ConsumeRequest{ID: old.ID, Envelope: oldRaw, Executor: "old-history", Capability: oldCap})
	must(t, e)
	must(t, s.MarkUnknown(ctx, old.ID, "old-history"))
	_, e = s.NextGeneration(ctx, i.TenantID, i.ID)
	must(t, e)
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	recoveryRaw := projection(t, ctx, p, i)
	historical, e := s.PersistIntent(ctx, recoveryRaw)
	must(t, e)
	cap := kernel.ID() + kernel.ID()
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: recoveryRaw, Executor: "historical-recovery", Capability: cap, Recovery: true, BlockedIDs: []string{old.ID}})
	must(t, e)
	granted, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Envelope: recoveryRaw, Executor: "historical-recovery", Capability: cap})
	must(t, e)
	if !granted.Granted {
		t.Fatal("recovery not consumed")
	}
	must(t, s.MarkUnknown(ctx, r.ID, "historical-recovery"))
	i, e = s.Retire(ctx, i.TenantID, i.ID)
	must(t, e)
	retiringGeneration := i.Generation
	cleanup := deleteAction(t, ctx, p, i)
	retirement, e := s.PersistIntent(ctx, cleanup)
	must(t, e)
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: cleanup, Executor: "recursive-recovery", Capability: kernel.ID() + kernel.ID(), Recovery: true, BlockedIDs: []string{old.ID, r.ID}})
	denied(t, e)
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: historical.ID, DispatchID: r.ID, Purpose: "effect", Audience: "cp1-kernel"})
	must(t, e)
	b, v := signed(t, c, *i.UID, "found")
	must(t, s.AcceptReceipt(ctx, c.ID, b, v))
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retiring" || i.Generation != retiringGeneration {
		t.Fatal("historical evidence altered canonical retirement authority")
	}
	state, e := s.Status(ctx, old.ID, "old-history")
	must(t, e)
	if state.State != "contained" {
		t.Fatal("historical recovery evidence did not contain predecessor")
	}
	duplicate, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Envelope: recoveryRaw, Executor: "historical-recovery", Capability: cap})
	must(t, e)
	if duplicate.Granted {
		t.Fatal("historical evidence regranted activation")
	}
	denied(t, s.Verify(ctx, recoveryRaw))
	deleteCap := kernel.ID() + kernel.ID()
	d, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: cleanup, Executor: "cleanup-after-history", Capability: deleteCap})
	must(t, e)
	_, e = s.Consume(ctx, kernel.ConsumeRequest{ID: d.ID, Envelope: cleanup, Executor: "cleanup-after-history", Capability: deleteCap})
	must(t, e)
	c, e = s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: retirement.ID, DispatchID: d.ID, Purpose: "absence", Audience: "cp1-kernel"})
	must(t, e)
	b, v = signed(t, c, "", "absent")
	must(t, s.AcceptReceipt(ctx, c.ID, b, v))
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retired" {
		t.Fatal("cleanup after authenticated history failed to terminate")
	}
}
