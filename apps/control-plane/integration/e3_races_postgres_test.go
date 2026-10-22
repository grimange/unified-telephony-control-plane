package integration_test

import (
	"encoding/json"
	"fmt"
	"testing"

	infra "github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

func TestE3ConcurrentCanonicalEvidenceInvalidation(t *testing.T) {
	for _, change := range []string{"supersession", "retirement", "attempt-rollover"} {
		t.Run(change, func(t *testing.T) {
			ctx, p, s := env(t)
			d := e3Dispatched(t, ctx, p, s, false)
			c := e3Challenge(t, ctx, s, d)
			ev := e3SignedEvidence(t, c, "definitive_non_delivery")
			raw := ev.raw(t)
			writer, e := p.Acquire(ctx)
			must(t, e)
			defer writer.Release()
			tx, e := writer.Begin(ctx)
			must(t, e)
			defer tx.Rollback(ctx)
			switch change {
			case "supersession":
				_, e = tx.Exec(ctx, "UPDATE infrastructure_intents SET superseded=true WHERE id=$1", d.Intent.ID)
			case "retirement":
				_, e = tx.Exec(ctx, "UPDATE infrastructure_incarnations SET state='retiring' WHERE id=$1", d.I.ID)
			case "attempt-rollover":
				_, e = tx.Exec(ctx, "UPDATE runtime_operations SET attempt_count=2 WHERE id=$1", d.Intent.OperationID)
			}
			must(t, e)
			done := make(chan error, 1)
			go func() { done <- s.AcceptDispatchEvidence(ctx, c.ID, raw, ev.Verifier) }()
			e2WaitForBlocker(t, ctx, p, writer.Conn().PgConn().PID())
			must(t, tx.Commit(ctx))
			denied(t, <-done)
			r, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
			must(t, e)
			if r.State != "outcome_unknown" {
				t.Fatal("concurrent invalidation was overwritten")
			}
		})
	}
}

func TestE3NegativeVersusLatePositive(t *testing.T) {
	for _, first := range []string{"negative", "positive"} {
		t.Run(first, func(t *testing.T) {
			ctx, p, s := env(t)
			d := e3Dispatched(t, ctx, p, s, false)
			nc := e3Challenge(t, ctx, s, d)
			ev := e3SignedEvidence(t, nc, "definitive_non_delivery")
			nr := ev.raw(t)
			pc, e := s.Challenge(ctx, kernel.ChallengeRequest{TenantID: d.I.TenantID, IncarnationID: d.I.ID, IntentID: d.Intent.ID, DispatchID: d.Req.ID, Purpose: "effect", Audience: "cp1-kernel"})
			must(t, e)
			pr, pv := signed(t, pc, *d.I.UID, "found")
			negative := func() error { return s.AcceptDispatchEvidence(ctx, nc.ID, nr, ev.Verifier) }
			positive := func() error { return s.AcceptReceipt(ctx, pc.ID, pr, pv) }
			one, two := negative, positive
			want := "confirmed_not_applied"
			if first == "positive" {
				one, two = positive, negative
				want = "confirmed_applied"
			}
			locker, e := p.Acquire(ctx)
			must(t, e)
			defer locker.Release()
			tx, e := locker.Begin(ctx)
			must(t, e)
			defer tx.Rollback(ctx)
			_, e = tx.Exec(ctx, "LOCK TABLE control_plane_audit_records IN ACCESS EXCLUSIVE MODE")
			must(t, e)
			firstDone := make(chan error, 1)
			go func() { firstDone <- one() }()
			e2WaitForBlocker(t, ctx, p, locker.Conn().PgConn().PID())
			secondDone := make(chan error, 1)
			go func() { secondDone <- two() }()
			must(t, tx.Rollback(ctx))
			must(t, <-firstDone)
			denied(t, <-secondDone)
			state, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
			must(t, e)
			if state.State != want {
				t.Fatalf("competing evidence replaced first terminal fact: %s", state.State)
			}
			var n int
			must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_dispatch_evidence_receipts WHERE challenge_id=$1", nc.ID).Scan(&n))
			if first == "negative" && n != 1 || first == "positive" && n != 0 {
				t.Fatal("contradictory receipt committed")
			}
		})
	}
}

func TestE3NegativeBarrierPermitsOnlyFreshRecoveryAuthority(t *testing.T) {
	ctx, p, s := env(t)
	old := e3Dispatched(t, ctx, p, s, false)
	b := e3Barrier(t, ctx, p, s, old, true)
	c := e3Challenge(t, ctx, s, b)
	ev := e3SignedEvidence(t, c, "definitive_non_delivery")
	must(t, s.AcceptDispatchEvidence(ctx, c.ID, ev.raw(t), ev.Verifier))
	_, e := s.NextGeneration(ctx, b.I.TenantID, b.I.ID)
	must(t, e)
	i, e := s.Read(ctx, b.I.TenantID, b.I.ID)
	must(t, e)
	raw := projection(t, ctx, p, i)
	_, e = s.PersistIntent(ctx, raw)
	must(t, e)
	_, e = s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "ordinary-still-blocked", Capability: kernel.ID() + kernel.ID()})
	denied(t, e)
	r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: "new-authorized-recovery", Capability: kernel.ID() + kernel.ID(), Recovery: true, BlockedIDs: []string{old.Req.ID}})
	must(t, e)
	if !r.Granted {
		t.Fatal("definitive negative recovery did not release its bounded slot")
	}
	oldState, e := s.Status(ctx, old.Req.ID, old.Req.Executor)
	must(t, e)
	if oldState.State != "outcome_unknown" {
		t.Fatal("negative barrier erased predecessor uncertainty")
	}
}

func TestE3CompetingRecoveryReservations(t *testing.T) {
	ctx, p, s := env(t)
	old := e3Dispatched(t, ctx, p, s, false)
	_, e := s.NextGeneration(ctx, old.I.TenantID, old.I.ID)
	must(t, e)
	i, e := s.Read(ctx, old.I.TenantID, old.I.ID)
	must(t, e)
	raw := projection(t, ctx, p, i)
	_, e = s.PersistIntent(ctx, raw)
	must(t, e)
	start := make(chan struct{})
	results := make(chan error, 2)
	for j := 0; j < 2; j++ {
		go func() {
			<-start
			r, x := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: kernel.ID(), Capability: kernel.ID() + kernel.ID(), Recovery: true, BlockedIDs: []string{old.Req.ID}})
			if x == nil && !r.Granted {
				x = kernel.ErrDenied
			}
			results <- x
		}()
	}
	close(start)
	success, rejected := 0, 0
	for j := 0; j < 2; j++ {
		if e := <-results; e == nil {
			success++
		} else {
			rejected++
		}
	}
	if success != 1 || rejected != 1 {
		t.Fatalf("recovery grants=%d rejected=%d", success, rejected)
	}
}

func TestE3RecoveryAuthorityRechecksLockedChanges(t *testing.T) {
	for _, change := range []string{"generation-before-reserve", "lease-before-consume"} {
		t.Run(change, func(t *testing.T) {
			ctx, p, s := env(t)
			old := e3Dispatched(t, ctx, p, s, false)
			b := e3Barrier(t, ctx, p, s, old, false)
			writer, e := p.Acquire(ctx)
			must(t, e)
			defer writer.Release()
			tx, e := writer.Begin(ctx)
			must(t, e)
			defer tx.Rollback(ctx)
			if change == "generation-before-reserve" {
				_, e = tx.Exec(ctx, "UPDATE infrastructure_incarnations SET generation=generation+1 WHERE id=$1", b.I.ID)
			} else {
				_, e = tx.Exec(ctx, "UPDATE runtime_operations SET lease_expires_at=now()-interval '1 second' WHERE id=$1", b.Intent.OperationID)
			}
			must(t, e)
			done := make(chan error, 1)
			go func() {
				if change == "generation-before-reserve" {
					_, x := s.Reserve(ctx, kernel.ReserveRequest{Envelope: b.Req.Envelope, Executor: "competing-stale-recovery", Capability: kernel.ID() + kernel.ID(), Recovery: true, BlockedIDs: []string{old.Req.ID}})
					done <- x
				} else {
					r, x := s.Consume(ctx, b.Req)
					if x == nil && r.Granted {
						x = fmt.Errorf("expired lease granted recovery dispatch")
					}
					done <- x
				}
			}()
			e2WaitForBlocker(t, ctx, p, writer.Conn().PgConn().PID())
			must(t, tx.Commit(ctx))
			denied(t, <-done)
			oldState, e := s.Status(ctx, old.Req.ID, old.Req.Executor)
			must(t, e)
			if oldState.State != "outcome_unknown" {
				t.Fatal("stale recovery erased predecessor uncertainty")
			}
		})
	}
}

func TestE3ConcurrentValidAndForgedEvidence(t *testing.T) {
	ctx, p, s := env(t)
	d := e3Dispatched(t, ctx, p, s, false)
	c := e3Challenge(t, ctx, s, d)
	ev := e3SignedEvidence(t, c, "definitive_non_delivery")
	good := ev.raw(t)
	var forged infra.SignedDispatchEvidence
	must(t, json.Unmarshal(good, &forged))
	forged.Payload.ExpectedUID = "forged-replacement"
	bad, e := json.Marshal(forged)
	must(t, e)
	start := make(chan struct{})
	valid := make(chan error, 1)
	invalid := make(chan error, 1)
	go func() { <-start; valid <- s.AcceptDispatchEvidence(ctx, c.ID, good, ev.Verifier) }()
	go func() { <-start; invalid <- s.AcceptDispatchEvidence(ctx, c.ID, bad, ev.Verifier) }()
	close(start)
	must(t, <-valid)
	denied(t, <-invalid)
	var n int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_dispatch_evidence_receipts WHERE challenge_id=$1", c.ID).Scan(&n))
	if n != 1 {
		t.Fatal("forged concurrent evidence affected committed receipt")
	}
}

func TestE3ConcurrentRecoveryDifferentTenants(t *testing.T) {
	ctx, p, s := env(t)
	oldA := e3Dispatched(t, ctx, p, s, false)
	oldB := e3Dispatched(t, ctx, p, s, false)
	a := e3Barrier(t, ctx, p, s, oldA, true)
	b := e3Barrier(t, ctx, p, s, oldB, true)
	ca := e3Challenge(t, ctx, s, a)
	cb := e3Challenge(t, ctx, s, b)
	ea := e3SignedEvidence(t, ca, "definitive_non_delivery")
	eb := e3SignedEvidence(t, cb, "definitive_non_delivery")
	ar, br := ea.raw(t), eb.raw(t)
	denied(t, s.AcceptDispatchEvidence(ctx, ca.ID, br, eb.Verifier))
	denied(t, s.AcceptDispatchEvidence(ctx, cb.ID, ar, ea.Verifier))
	start := make(chan struct{})
	done := make(chan error, 2)
	go func() { <-start; done <- s.AcceptDispatchEvidence(ctx, ca.ID, ar, ea.Verifier) }()
	go func() { <-start; done <- s.AcceptDispatchEvidence(ctx, cb.ID, br, eb.Verifier) }()
	close(start)
	must(t, <-done)
	must(t, <-done)
	for _, d := range []e3Dispatch{oldA, oldB} {
		r, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
		must(t, e)
		if r.State != "outcome_unknown" {
			t.Fatal("tenant recovery erased predecessor uncertainty")
		}
	}
}
