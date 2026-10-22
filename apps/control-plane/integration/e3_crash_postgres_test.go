package integration_test

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	infra "github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

// A separate PostgreSQL connection holds the audit INSERT lock. Reaching that
// server wait proves receipt mutation has begun but its transaction cannot commit.
// Killing the actual child then proves PostgreSQL rollback, rather than a mocked
// error return. The fresh child runs against the same durable database.
func TestE3EvidenceTransactionCrash(t *testing.T) {
	for _, negative := range []bool{false, true} {
		for _, before := range []bool{true, false} {
			t.Run(fmt.Sprintf("negative=%t/before_commit=%t", negative, before), func(t *testing.T) {
				ctx, p, s := env(t)
				old := e3Dispatched(t, ctx, p, s, false)
				d := old
				in := e3WorkerInput{Req: d.Req, OldID: old.Req.ID, OldExecutor: old.Req.Executor, Negative: negative}
				if negative {
					c := e3Challenge(t, ctx, s, d)
					ev := e3SignedEvidence(t, c, "definitive_non_delivery")
					in.EvidenceID = c.ID
					in.Raw = ev.raw(t)
					in.Policy = ev.Verifier.Policy
					in.PeerSAN = ev.Verifier.PeerSAN
				} else {
					d = e3Barrier(t, ctx, p, s, old, true)
					in.Req = d.Req
					c, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: d.I.TenantID, IncarnationID: d.I.ID, IntentID: d.Intent.ID, DispatchID: d.Req.ID, Purpose: "effect", Audience: "cp1-kernel"})
					must(t, e)
					in.Challenge = c
					var v infra.ReceiptVerifier
					in.Raw, v = signed(t, c, *d.I.UID, "found")
					in.Policy = v.Policy
					in.PeerSAN = v.PeerSAN
				}
				path := filepath.Join(t.TempDir(), "receipt-worker.json")
				raw, e := json.Marshal(in)
				must(t, e)
				must(t, os.WriteFile(path, raw, 0600))
				locker, e := p.Acquire(ctx)
				must(t, e)
				defer locker.Release()
				tx, e := locker.Begin(ctx)
				must(t, e)
				defer tx.Rollback(ctx)
				if before {
					_, e = tx.Exec(ctx, "LOCK TABLE control_plane_audit_records IN ACCESS EXCLUSIVE MODE")
					must(t, e)
				} else {
					must(t, tx.Rollback(ctx))
				}
				cmd := e3Worker(t, ctx, "receipt-accept", path)
				out, e := cmd.StdoutPipe()
				must(t, e)
				cmd.Stderr = os.Stderr
				must(t, cmd.Start())
				t.Cleanup(func() { _ = cmd.Process.Kill() })
				if before {
					deadline := time.Now().Add(5 * time.Second)
					waiting := false
					for time.Now().Before(deadline) {
						must(t, p.QueryRow(ctx, "SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE $1=ANY(pg_blocking_pids(pid)))", locker.Conn().PgConn().PID()).Scan(&waiting))
						if waiting {
							break
						}
						time.Sleep(time.Millisecond)
					}
					if !waiting {
						_ = cmd.Process.Kill()
						_ = cmd.Wait()
						t.Fatal("receipt worker never reached precommit database barrier")
					}
				} else {
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
						t.Fatal("receipt worker did not commit")
					}
				}
				must(t, cmd.Process.Kill())
				_ = cmd.Wait()
				if before {
					must(t, tx.Rollback(ctx))
					r, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
					must(t, e)
					if r.State != "outcome_unknown" {
						t.Fatal("killed transaction leaked receipt outcome")
					}
					var n int
					table := "infrastructure_observation_receipts"
					id := in.Challenge.ID
					if negative {
						table = "infrastructure_dispatch_evidence_receipts"
						id = in.EvidenceID
					}
					must(t, p.QueryRow(ctx, "SELECT count(*) FROM "+table+" WHERE challenge_id=$1", id).Scan(&n))
					if n != 0 {
						t.Fatal("killed transaction committed evidence")
					}
				}
				bytes, e := e3Worker(t, ctx, "receipt-status", path).CombinedOutput()
				if e != nil {
					t.Fatalf("receipt restart failed: %v: %s", e, bytes)
				}
				if !strings.Contains(string(bytes), "E3_RECOVERED") {
					t.Fatal("missing durable receipt recovery")
				}
			})
		}
	}
}
