package integration_test

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	infra "github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
	"github.com/jackc/pgx/v5/pgxpool"
)

type e3Dispatch struct {
	I      kernel.Incarnation
	Intent kernel.Intent
	Req    kernel.ConsumeRequest
}

func e3Dispatched(t *testing.T, ctx context.Context, p *pgxpool.Pool, s *kernel.Service, create bool) e3Dispatch {
	t.Helper()
	i := fixture(t, ctx, p, s)
	if !create {
		i = bind(t, ctx, p, s, i)
	}
	var raw []byte
	if create {
		raw = createAction(t, ctx, p, i)
	} else {
		raw = projection(t, ctx, p, i)
	}
	in, e := s.PersistIntent(ctx, raw)
	must(t, e)
	req := kernel.ConsumeRequest{Envelope: raw, Executor: "e3-executor", Capability: kernel.ID() + kernel.ID()}
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: req.Executor, Capability: req.Capability})
	must(t, e)
	req.ID = r.ID
	r, e = s.Consume(ctx, req)
	must(t, e)
	if !r.Granted {
		t.Fatal("initial dispatch grant missing")
	}
	must(t, s.MarkUnknown(ctx, r.ID, req.Executor))
	return e3Dispatch{i, in, req}
}
func e3Barrier(t *testing.T, ctx context.Context, p *pgxpool.Pool, s *kernel.Service, old e3Dispatch, consume bool) e3Dispatch {
	t.Helper()
	_, e := s.NextGeneration(ctx, old.I.TenantID, old.I.ID)
	must(t, e)
	i, e := s.Read(ctx, old.I.TenantID, old.I.ID)
	must(t, e)
	raw := projection(t, ctx, p, i)
	in, e := s.PersistIntent(ctx, raw)
	must(t, e)
	req := kernel.ConsumeRequest{Envelope: raw, Executor: "e3-barrier", Capability: kernel.ID() + kernel.ID()}
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: req.Executor, Capability: req.Capability, Recovery: true, BlockedIDs: []string{old.Req.ID}})
	must(t, e)
	req.ID = r.ID
	if consume {
		r, e = s.Consume(ctx, req)
		must(t, e)
		if !r.Granted {
			t.Fatal("barrier initial grant missing")
		}
		must(t, s.MarkUnknown(ctx, r.ID, req.Executor))
	}
	return e3Dispatch{i, in, req}
}

func TestE3LostBarrierObservationProgress(t *testing.T) {
	ctx, p, s := env(t)
	old := e3Dispatched(t, ctx, p, s, false)
	barrier := e3Barrier(t, ctx, p, s, old, true)
	_, e := s.NextGeneration(ctx, old.I.TenantID, old.I.ID)
	must(t, e)
	i, e := s.Read(ctx, old.I.TenantID, old.I.ID)
	must(t, e)
	newer := projection(t, ctx, p, i)
	_, e = s.PersistIntent(ctx, newer)
	must(t, e)
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: newer, Executor: "no-recursive-barrier", Capability: kernel.ID() + kernel.ID(), Recovery: true, BlockedIDs: []string{old.Req.ID, barrier.Req.ID}})
	denied(t, e)
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: barrier.Intent.ID, DispatchID: barrier.Req.ID, Purpose: "effect", Audience: "cp1-kernel"})
	must(t, e)
	raw, v := signed(t, c, *i.UID, "found")
	must(t, s.AcceptReceipt(ctx, c.ID, raw, v))
	state, e := s.Status(ctx, old.Req.ID, old.Req.Executor)
	must(t, e)
	if state.State != "contained" {
		t.Fatal("observed barrier did not contain delayed older writes")
	}
	var outcome string
	must(t, p.QueryRow(ctx, "SELECT outcome FROM infrastructure_intents WHERE id=$1", old.Intent.ID).Scan(&outcome))
	if outcome != "outcome_unknown" {
		t.Fatal("historical uncertainty was erased")
	}
	repeat, e := s.Consume(ctx, barrier.Req)
	must(t, e)
	if repeat.Granted {
		t.Fatal("lost response granted duplicate barrier")
	}
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: newer, Executor: "new-authority-after-proof", Capability: kernel.ID() + kernel.ID()})
	must(t, e)
}

func TestE3AbsentCreateIsNotNonapplication(t *testing.T) {
	ctx, p, s := env(t)
	old := e3Dispatched(t, ctx, p, s, true)
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: old.I.TenantID, IncarnationID: old.I.ID, IntentID: old.Intent.ID, DispatchID: old.Req.ID, Purpose: "bind", Audience: "cp1-kernel"})
	must(t, e)
	raw, v := signed(t, c, "", "absent")
	denied(t, s.AcceptReceipt(ctx, c.ID, raw, v))
	i, e := s.Retire(ctx, old.I.TenantID, old.I.ID)
	must(t, e)
	// Exact-UID cleanup cannot even be authorized while the unknown create is
	// still unbound. An absent GET must not fabricate that missing identity.
	_, e = s.PersistIntent(ctx, deleteAction(t, ctx, p, i))
	denied(t, e)
	state, e := s.Status(ctx, old.Req.ID, old.Req.Executor)
	must(t, e)
	if state.State != "outcome_unknown" {
		t.Fatal("GET absence erased delayed create risk")
	}
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retiring" || i.UID != nil {
		t.Fatal("absence fabricated terminal retirement/binding")
	}
	c, e = s.Challenge(ctx, kernel.ChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, IntentID: old.Intent.ID, DispatchID: old.Req.ID, Purpose: "bind", Audience: "cp1-kernel"})
	must(t, e)
	raw, v = signed(t, c, "e3-late-inert-uid", "found")
	must(t, s.AcceptReceipt(ctx, c.ID, raw, v))
	i, e = s.Read(ctx, i.TenantID, i.ID)
	must(t, e)
	if i.State != "retiring" || i.UID == nil {
		t.Fatal("late creation is not cleanup-only")
	}
	_, e = s.PersistIntent(ctx, projection(t, ctx, p, i))
	denied(t, e)
}

func TestE3ConcurrentBarrierReceipts(t *testing.T) {
	ctx, p, s := env(t)
	old := e3Dispatched(t, ctx, p, s, false)
	b := e3Barrier(t, ctx, p, s, old, true)
	c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: b.I.TenantID, IncarnationID: b.I.ID, IntentID: b.Intent.ID, DispatchID: b.Req.ID, Purpose: "effect", Audience: "cp1-kernel"})
	must(t, e)
	raw, v := signed(t, c, *b.I.UID, "found")
	start := make(chan struct{})
	errs := make(chan error, 2)
	var wg sync.WaitGroup
	for j := 0; j < 2; j++ {
		wg.Add(1)
		go func() { defer wg.Done(); <-start; errs <- s.AcceptReceipt(ctx, c.ID, raw, v) }()
	}
	close(start)
	wg.Wait()
	close(errs)
	for e := range errs {
		must(t, e)
	}
	var receipts, audits, events int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_observation_receipts WHERE challenge_id=$1", c.ID).Scan(&receipts))
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1 AND action='infrastructure.observation_accepted'", b.I.ID).Scan(&audits))
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_outbox_messages WHERE aggregate_id=$1 AND event_type='infrastructure.observation_accepted'", b.I.ID).Scan(&events))
	if receipts != 1 || audits != 2 || events != 2 {
		t.Fatalf("duplicate evidence/events: receipts=%d audits=%d outbox=%d", receipts, audits, events)
	}
}

type e3WorkerInput struct {
	Req                kernel.ConsumeRequest
	Challenge          kernel.ReceiptChallenge
	Raw                []byte
	Policy             infra.TrustPolicy
	PeerSAN            string
	OldID, OldExecutor string
	Negative           bool
	EvidenceID         string
}

func e3Worker(t *testing.T, ctx context.Context, mode, path string) *exec.Cmd {
	t.Helper()
	exe, e := os.Executable()
	must(t, e)
	c := exec.CommandContext(ctx, exe, "-test.run=^TestE3RecoveryWorker$", "-test.v")
	c.Env = append(os.Environ(), "UTCP_E3_WORKER="+mode, "UTCP_E3_WORKER_FILE="+path)
	return c
}
func e3StartKill(t *testing.T, ctx context.Context, mode string, input e3WorkerInput) {
	t.Helper()
	path := filepath.Join(t.TempDir(), "e3-worker.json")
	raw, e := json.Marshal(input)
	must(t, e)
	must(t, os.WriteFile(path, raw, 0600))
	cmd := e3Worker(t, ctx, mode, path)
	out, e := cmd.StdoutPipe()
	must(t, e)
	cmd.Stderr = os.Stderr
	must(t, cmd.Start())
	t.Cleanup(func() { _ = cmd.Process.Kill() })
	scan := bufio.NewScanner(out)
	ready := false
	for scan.Scan() {
		if strings.HasPrefix(scan.Text(), "E3_COMMITTED") {
			ready = true
			break
		}
	}
	if !ready {
		_ = cmd.Wait()
		t.Fatal("worker failed before durable barrier")
	}
	must(t, cmd.Process.Kill())
	_ = cmd.Wait()
	outBytes, e := e3Worker(t, ctx, "status", path).CombinedOutput()
	if e != nil {
		t.Fatalf("fresh process recovery: %v: %s", e, outBytes)
	}
	if !strings.Contains(string(outBytes), "E3_RECOVERED") {
		t.Fatal("fresh process recovery missing")
	}
}
func TestE3BarrierProcessRestart(t *testing.T) {
	for _, phase := range []string{"reserved", "consumed", "synthetic-effect", "confirmed"} {
		t.Run(phase, func(t *testing.T) {
			ctx, p, s := env(t)
			old := e3Dispatched(t, ctx, p, s, false)
			b := e3Barrier(t, ctx, p, s, old, false)
			in := e3WorkerInput{Req: b.Req, OldID: old.Req.ID, OldExecutor: old.Req.Executor}
			if phase == "confirmed" {
				_, e := s.Consume(ctx, b.Req)
				must(t, e)
				must(t, s.MarkUnknown(ctx, b.Req.ID, b.Req.Executor))
				c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: b.I.TenantID, IncarnationID: b.I.ID, IntentID: b.Intent.ID, DispatchID: b.Req.ID, Purpose: "effect", Audience: "cp1-kernel"})
				must(t, e)
				in.Challenge = c
				var verifier infra.ReceiptVerifier
				in.Raw, verifier = signed(t, c, *b.I.UID, "found")
				in.Policy = verifier.Policy
				in.PeerSAN = verifier.PeerSAN
			}
			e3StartKill(t, ctx, phase, in)
		})
	}
}
func TestE3RecoveryWorker(t *testing.T) {
	mode := os.Getenv("UTCP_E3_WORKER")
	if mode == "" {
		t.Skip("E3 actual subprocess helper")
	}
	ctx, _, s := env(t)
	raw, e := os.ReadFile(os.Getenv("UTCP_E3_WORKER_FILE"))
	must(t, e)
	var in e3WorkerInput
	must(t, json.Unmarshal(raw, &in))
	if mode == "receipt-accept" || mode == "receipt-status" {
		if in.Negative {
			must(t, s.AcceptDispatchEvidence(ctx, in.EvidenceID, in.Raw, infra.DispatchEvidenceVerifier{Policy: in.Policy, PeerSAN: in.PeerSAN}))
		} else {
			must(t, s.AcceptReceipt(ctx, in.Challenge.ID, in.Raw, infra.ReceiptVerifier{Policy: in.Policy, PeerSAN: in.PeerSAN}))
		}
		if mode == "receipt-accept" {
			fmt.Println("E3_COMMITTED")
			select {}
		}
		r, e := s.Status(ctx, in.Req.ID, in.Req.Executor)
		must(t, e)
		want := "confirmed_applied"
		if in.Negative {
			want = "confirmed_not_applied"
		}
		if r.State != want {
			t.Fatalf("receipt restart state %s want %s", r.State, want)
		}
		r, e = s.Consume(ctx, in.Req)
		must(t, e)
		if r.Granted {
			t.Fatal("receipt restart duplicated dispatch grant")
		}
		fmt.Println("E3_RECOVERED")
		return
	}
	if mode == "consumed" || mode == "synthetic-effect" {
		r, e := s.Consume(ctx, in.Req)
		must(t, e)
		if !r.Granted {
			t.Fatal("missing initial grant")
		}
		if mode == "synthetic-effect" {
			must(t, os.WriteFile(os.Getenv("UTCP_E3_WORKER_FILE")+".synthetic", []byte("synthetic effect only"), 0600))
		}
	}
	if mode == "confirmed" {
		must(t, s.AcceptReceipt(ctx, in.Challenge.ID, in.Raw, infra.ReceiptVerifier{Policy: in.Policy, PeerSAN: in.PeerSAN}))
	}
	if mode != "status" {
		fmt.Println("E3_COMMITTED")
		select {}
	}
	r, e := s.Status(ctx, in.Req.ID, in.Req.Executor)
	must(t, e)
	if r.State == "reserved" {
		r, e = s.Consume(ctx, in.Req)
		must(t, e)
		if !r.Granted {
			t.Fatal("never-consumed reservation could not progress")
		}
	} else {
		r, e = s.Consume(ctx, in.Req)
		must(t, e)
		if r.Granted {
			t.Fatal("restart granted consumed authority twice")
		}
	}
	if r.State == "dispatching" {
		must(t, s.MarkUnknown(ctx, in.Req.ID, in.Req.Executor))
	}
	if in.Challenge.ID != "" {
		must(t, s.AcceptReceipt(ctx, in.Challenge.ID, in.Raw, infra.ReceiptVerifier{Policy: in.Policy, PeerSAN: in.PeerSAN}))
		old, e := s.Status(ctx, in.OldID, in.OldExecutor)
		must(t, e)
		if old.State != "contained" {
			t.Fatal("confirmed barrier lost containment")
		}
	}
	fmt.Println("E3_RECOVERED")
}
