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
	"testing"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
	"github.com/jackc/pgx/v5/pgxpool"
)

// Every barrier follows a committed PostgreSQL transition. The killed process
// and its replacement both install the real Asterisk policy; no in-memory grant
// or policy result survives the process boundary.
func TestE2AsteriskKilledWorkerRecovery(t *testing.T) {
	for _, restore := range []bool{false, true} {
		for _, phase := range []string{"reserve", "consume", "unknown"} {
			t.Run(fmt.Sprintf("restore=%v/%s", restore, phase), func(t *testing.T) {
				ctx, p, s := env(t)
				f := e2DomainFixture(t, ctx, p, s, restore)
				req := kernel.ConsumeRequest{Envelope: f.Raw, Executor: "e2-restart-worker", Capability: kernel.ID() + kernel.ID()}
				if phase != "reserve" {
					r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: f.Raw, Executor: req.Executor, Capability: req.Capability})
					must(t, e)
					req.ID = r.ID
				}
				path := filepath.Join(t.TempDir(), "e2-worker.json")
				e2WriteWorkerRequest(t, path, req)
				cmd := e2WorkerCommand(t, ctx, phase, path, "")
				stdout, e := cmd.StdoutPipe()
				must(t, e)
				cmd.Stderr = os.Stderr
				must(t, cmd.Start())
				t.Cleanup(func() { _ = cmd.Process.Kill() })
				scanner := bufio.NewScanner(stdout)
				committed := false
				for scanner.Scan() {
					if strings.HasPrefix(scanner.Text(), "E2_COMMITTED ") {
						req.ID = strings.TrimPrefix(scanner.Text(), "E2_COMMITTED ")
						committed = true
						break
					}
				}
				if !committed {
					_ = cmd.Wait()
					t.Fatal("Asterisk worker failed before committed phase barrier")
				}
				must(t, cmd.Process.Kill())
				_ = cmd.Wait()
				state := map[string]string{"reserve": "reserved", "consume": "dispatching", "unknown": "outcome_unknown"}[phase]
				r, e := s.Status(ctx, req.ID, req.Executor)
				must(t, e)
				if r.State != state {
					t.Fatalf("durable phase=%s, want %s", r.State, state)
				}
				// Change domain eligibility after the process dies. A pending grant
				// cannot survive that change; consumed uncertainty remains evidence.
				if restore {
					_, e = p.Exec(ctx, "UPDATE runtime_nodes SET configuration_version=2 WHERE id=$1", f.I.RuntimeNodeID)
				} else {
					_, e = p.Exec(ctx, "UPDATE runtime_nodes SET observed_state='ready' WHERE id=$1", f.I.RuntimeNodeID)
				}
				must(t, e)
				e2WriteWorkerRequest(t, path, req)
				out, e := e2WorkerCommand(t, ctx, "recover", path, state).CombinedOutput()
				if e != nil {
					t.Fatalf("fresh Asterisk recovery process: %v: %s", e, out)
				}
				if !strings.Contains(string(out), "E2_RECOVERED_WITHOUT_GRANT") {
					t.Fatal("fresh process did not prove stale policy rejection")
				}
			})
		}
	}
}

func e2WriteWorkerRequest(t *testing.T, path string, req kernel.ConsumeRequest) {
	t.Helper()
	b, e := json.Marshal(req)
	must(t, e)
	must(t, os.WriteFile(path, b, 0600))
}

func e2WorkerCommand(t *testing.T, ctx context.Context, mode, path, expected string) *exec.Cmd {
	t.Helper()
	exe, e := os.Executable()
	must(t, e)
	cmd := exec.CommandContext(ctx, exe, "-test.run=^TestE2AsteriskRecoveryWorker$", "-test.v")
	cmd.Env = append(os.Environ(), "UTCP_E2_WORKER="+mode, "UTCP_E2_WORKER_FILE="+path, "UTCP_E2_EXPECT_STATE="+expected)
	return cmd
}

func TestE2AsteriskRecoveryWorker(t *testing.T) {
	mode := os.Getenv("UTCP_E2_WORKER")
	if mode == "" {
		t.Skip("Asterisk subprocess helper")
	}
	ctx, _, s := env(t)
	s.SetDomainPolicy(kernel.NewAsteriskDomainPolicy())
	b, e := os.ReadFile(os.Getenv("UTCP_E2_WORKER_FILE"))
	must(t, e)
	var req kernel.ConsumeRequest
	must(t, json.Unmarshal(b, &req))
	if mode == "reserve" {
		r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: req.Envelope, Executor: req.Executor, Capability: req.Capability})
		must(t, e)
		if !r.Granted {
			t.Fatal("initial reservation missing")
		}
		fmt.Println("E2_COMMITTED " + r.ID)
		select {}
	}
	if mode == "consume" || mode == "unknown" {
		r, e := s.Consume(ctx, req)
		must(t, e)
		if !r.Granted {
			t.Fatal("initial Asterisk dispatch grant missing")
		}
		if mode == "unknown" {
			must(t, s.MarkUnknown(ctx, r.ID, req.Executor))
		}
		fmt.Println("E2_COMMITTED " + r.ID)
		select {}
	}
	r, e := s.Status(ctx, req.ID, req.Executor)
	must(t, e)
	if r.State != os.Getenv("UTCP_E2_EXPECT_STATE") {
		t.Fatal("durable Asterisk dispatch state lost")
	}
	denied(t, s.Verify(ctx, req.Envelope))
	if r.State == "dispatching" {
		must(t, s.MarkUnknown(ctx, req.ID, req.Executor))
	}
	r, e = s.Consume(ctx, req)
	if os.Getenv("UTCP_E2_EXPECT_STATE") == "reserved" {
		denied(t, e)
	} else {
		must(t, e)
	}
	if r.Granted {
		t.Fatal("restart granted stale or duplicate Asterisk dispatch")
	}
	fmt.Println("E2_RECOVERED_WITHOUT_GRANT")
}

// The independent writer holds an actual uncommitted change before consumption
// starts. PostgreSQL reports the wait before the test permits the writer commit.
func TestE2AsteriskConcurrentClaimInvalidation(t *testing.T) {
	for _, change := range []string{"attempt-rollover", "supersession"} {
		t.Run(change, func(t *testing.T) {
			ctx, p, s := env(t)
			f := e2DomainFixture(t, ctx, p, s, true)
			req := kernel.ReserveRequest{Envelope: f.Raw, Executor: "e2-race-worker", Capability: kernel.ID() + kernel.ID()}
			r, e := s.Reserve(ctx, req)
			must(t, e)
			writer, e := p.Acquire(ctx)
			must(t, e)
			defer writer.Release()
			tx, e := writer.Begin(ctx)
			must(t, e)
			defer tx.Rollback(ctx)
			if change == "attempt-rollover" {
				_, e = tx.Exec(ctx, "UPDATE runtime_operations SET attempt_count=2,lease_token=$2 WHERE id=$1", f.Op, kernel.ID())
			} else {
				_, e = tx.Exec(ctx, "UPDATE infrastructure_intents SET superseded=true WHERE operation_id=$1", f.Op)
			}
			must(t, e)
			done := make(chan error, 1)
			go func() {
				x, err := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: req.Executor, Capability: req.Capability, Envelope: f.Raw})
				if x.Granted {
					done <- fmt.Errorf("invalidated operation received a dispatch grant")
					return
				}
				done <- err
			}()
			e2WaitForBlocker(t, ctx, p, writer.Conn().PgConn().PID())
			must(t, tx.Commit(ctx))
			if e = <-done; e != kernel.ErrDenied {
				t.Fatalf("committed %s denied as %v, want ErrDenied", change, e)
			}
			var state string
			must(t, p.QueryRow(ctx, "SELECT state FROM infrastructure_dispatches WHERE id=$1", r.ID).Scan(&state))
			if state != "reserved" {
				t.Fatalf("denied concurrent change consumed reservation: %s", state)
			}
		})
	}
}

func e2WaitForBlocker(t *testing.T, ctx context.Context, p *pgxpool.Pool, blocker uint32) {
	t.Helper()
	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		var waiting bool
		must(t, p.QueryRow(ctx, "SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE $1=ANY(pg_blocking_pids(pid)))", blocker).Scan(&waiting))
		if waiting {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("consumer did not wait on the independent PostgreSQL authority change")
}

func TestE2AsteriskRestorationCannotFollowReplacement(t *testing.T) {
	ctx, p, s := env(t)
	f := e2DomainFixture(t, ctx, p, s, true)
	req := kernel.ReserveRequest{Envelope: f.Raw, Executor: "e2-old-incarnation", Capability: kernel.ID() + kernel.ID()}
	r, e := s.Reserve(ctx, req)
	must(t, e)
	retiring, e := s.Retire(ctx, f.I.TenantID, f.I.ID)
	must(t, e)
	retirement, e := s.PersistIntent(ctx, deleteAction(t, ctx, p, retiring))
	must(t, e)
	challenge, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: f.I.TenantID, IncarnationID: f.I.ID, IntentID: retirement.ID, Purpose: "absence", Audience: "cp1-kernel"})
	must(t, e)
	receipt, verifier := signed(t, challenge, "", "absent")
	must(t, s.AcceptReceipt(ctx, challenge.ID, receipt, verifier))
	preparing := prepareRequest(f.I)
	preparing.IdempotencyKey = "e2-replacement-" + kernel.ID()
	next, e := s.Prepare(ctx, preparing)
	must(t, e)
	next = bind(t, ctx, p, s, next)
	if next.ID == f.I.ID || next.Name == f.I.Name || *next.UID == *f.I.UID {
		t.Fatal("replacement reused immutable physical identity")
	}
	denied(t, s.Verify(ctx, f.Raw))
	x, e := s.Consume(ctx, kernel.ConsumeRequest{ID: r.ID, Executor: req.Executor, Capability: req.Capability, Envelope: f.Raw})
	// Retirement cancels unused authority; its status may be returned, never a
	// dispatch grant against either the old or replacement physical resource.
	if e != nil && e != kernel.ErrDenied {
		t.Fatal(e)
	}
	if x.Granted {
		t.Fatal("old restoration followed a replacement incarnation")
	}
	old, e := s.Read(ctx, f.I.TenantID, f.I.ID)
	must(t, e)
	if old.State != "retired" || *old.UID != *f.I.UID {
		t.Fatal("retired source identity history was altered")
	}
}
