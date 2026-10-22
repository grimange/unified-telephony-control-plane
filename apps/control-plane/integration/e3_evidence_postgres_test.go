package integration_test

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"strings"
	"sync"
	"testing"
	"time"

	infra "github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

type e3Evidence struct {
	Payload  infra.DispatchEvidencePayload
	Key      ed25519.PrivateKey
	Verifier infra.DispatchEvidenceVerifier
}

func e3SignedEvidence(t *testing.T, c kernel.DispatchEvidenceChallenge, kind string) e3Evidence {
	t.Helper()
	pub, key, e := ed25519.GenerateKey(rand.Reader)
	must(t, e)
	now := time.Now().UTC()
	p := infra.DispatchEvidencePayload{ContractVersion: infra.DispatchEvidenceVersion, ReceiptID: kernel.ID(), Issuer: "e3-trusted-executor", KeyID: "e3-ephemeral", ChallengeID: c.ID, Nonce: c.Nonce, Audience: c.Audience, Purpose: "dispatch_evidence", TenantID: c.TenantID, RuntimeNodeID: c.RuntimeNodeID, ClusterID: c.ClusterID, Namespace: c.Namespace, Name: c.Name, IncarnationID: c.IncarnationID, ExpectedUID: c.ExpectedUID, IntentID: c.IntentID, DispatchID: c.DispatchID, OperationID: c.OperationID, Attempt: c.Attempt, Executor: c.Executor, EnvelopeHash: c.EnvelopeHash, Generation: c.Generation, Fingerprint: c.Fingerprint, Action: c.Action, EvidenceType: kind, Outcome: "confirmed_not_applied", JournalID: "closed-journal-" + kernel.ID(), Final: true, Complete: true, StartedAt: now, FinishedAt: now, ExpiresAt: now.Add(20 * time.Second)}
	if kind == "api_rejected" {
		p.Submissions = 1
		p.APICode = 422
		p.APIReason = "Invalid"
	}
	v := infra.DispatchEvidenceVerifier{Policy: infra.TrustPolicy{Keys: map[string]infra.TrustedKey{"e3-ephemeral": {Issuer: p.Issuer, PeerSAN: c.Executor, ClusterID: c.ClusterID, Namespace: c.Namespace, Purposes: []string{"dispatch_evidence"}, PublicKey: pub, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour)}}, MaxAge: 30 * time.Second, ClockSkew: 5 * time.Second}, PeerSAN: c.Executor}
	return e3Evidence{p, key, v}
}
func (e e3Evidence) raw(t *testing.T) []byte {
	t.Helper()
	signed, err := infra.SignDispatchEvidence(e.Payload, e.Key)
	must(t, err)
	b, err := json.Marshal(signed)
	must(t, err)
	return b
}
func e3Challenge(t *testing.T, ctx context.Context, s *kernel.Service, d e3Dispatch) kernel.DispatchEvidenceChallenge {
	t.Helper()
	c, e := s.EvidenceChallenge(ctx, kernel.EvidenceChallengeRequest{TenantID: d.I.TenantID, IncarnationID: d.I.ID, DispatchID: d.Req.ID, Audience: "cp1-kernel"})
	must(t, e)
	return c
}
func TestE3DefinitiveEvidenceAndNoDuplicateGrant(t *testing.T) {
	for _, kind := range []string{"pre_dispatch_rejected", "definitive_non_delivery", "api_rejected"} {
		t.Run(kind, func(t *testing.T) {
			ctx, p, s := env(t)
			var d e3Dispatch
			if kind == "pre_dispatch_rejected" {
				i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
				raw := projection(t, ctx, p, i)
				in, e := s.PersistIntent(ctx, raw)
				must(t, e)
				req := kernel.ConsumeRequest{Envelope: raw, Executor: "e3-executor", Capability: kernel.ID() + kernel.ID()}
				r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: req.Executor, Capability: req.Capability})
				must(t, e)
				req.ID = r.ID
				d = e3Dispatch{i, in, req}
			} else {
				d = e3Dispatched(t, ctx, p, s, false)
			}
			c := e3Challenge(t, ctx, s, d)
			evidence := e3SignedEvidence(t, c, kind)
			raw := evidence.raw(t)
			must(t, s.AcceptDispatchEvidence(ctx, c.ID, raw, evidence.Verifier))
			must(t, s.AcceptDispatchEvidence(ctx, c.ID, raw, evidence.Verifier))
			r, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
			must(t, e)
			want := "confirmed_not_applied"
			if kind == "pre_dispatch_rejected" {
				want = "cancelled"
			}
			if r.State != want {
				t.Fatalf("dispatch state %s want %s", r.State, want)
			}
			r, e = s.Consume(ctx, d.Req)
			if e != nil && e != kernel.ErrDenied {
				t.Fatal(e)
			}
			if r.Granted {
				t.Fatal("resolved consumed authority granted a second dispatch")
			}
			var n, a, o int
			must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_dispatch_evidence_receipts WHERE challenge_id=$1", c.ID).Scan(&n))
			must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1 AND action='infrastructure.dispatch_nonapplication_confirmed'", d.I.ID).Scan(&a))
			must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_outbox_messages WHERE aggregate_id=$1 AND event_type='infrastructure.dispatch_nonapplication_confirmed'", d.I.ID).Scan(&o))
			if n != 1 || a != 1 || o != 1 {
				t.Fatalf("duplicate resolution %d/%d/%d", n, a, o)
			}
			evidence.Payload.ReceiptID = kernel.ID()
			denied(t, s.AcceptDispatchEvidence(ctx, c.ID, evidence.raw(t), evidence.Verifier))
		})
	}
}
func TestE3EvidenceRejectsAmbiguousOrWrongIdentity(t *testing.T) {
	cases := []struct {
		name   string
		change func(*e3Evidence)
	}{
		{"not-final", func(e *e3Evidence) { e.Payload.Final = false }}, {"incomplete", func(e *e3Evidence) { e.Payload.Complete = false }},
		{"transport-failure", func(e *e3Evidence) { e.Payload.EvidenceType = "transport_failure" }}, {"current-state-absence", func(e *e3Evidence) { e.Payload.EvidenceType = "resource_absent" }},
		{"api-timeout", func(e *e3Evidence) {
			e.Payload.EvidenceType = "api_rejected"
			e.Payload.Submissions = 1
			e.Payload.APICode = 504
			e.Payload.APIReason = "Timeout"
		}},
		{"arbitrary-409", func(e *e3Evidence) {
			e.Payload.EvidenceType = "api_rejected"
			e.Payload.Submissions = 1
			e.Payload.APICode = 409
			e.Payload.APIReason = "Unknown"
		}},
		{"arbitrary-422", func(e *e3Evidence) {
			e.Payload.EvidenceType = "api_rejected"
			e.Payload.Submissions = 1
			e.Payload.APICode = 422
			e.Payload.APIReason = "Unknown"
		}},
		{"multiple-submissions", func(e *e3Evidence) { e.Payload.Submissions = 2 }}, {"wrong-tenant", func(e *e3Evidence) { e.Payload.TenantID = kernel.UUID() }},
		{"wrong-node", func(e *e3Evidence) { e.Payload.RuntimeNodeID = kernel.UUID() }}, {"wrong-cluster", func(e *e3Evidence) { e.Payload.ClusterID = "other-cluster" }},
		{"wrong-uid", func(e *e3Evidence) { e.Payload.ExpectedUID = "replacement" }}, {"wrong-incarnation", func(e *e3Evidence) { e.Payload.IncarnationID = kernel.ID() }},
		{"wrong-operation", func(e *e3Evidence) { e.Payload.OperationID = kernel.ID() }}, {"wrong-attempt", func(e *e3Evidence) { e.Payload.Attempt++ }},
		{"wrong-dispatch", func(e *e3Evidence) { e.Payload.DispatchID = kernel.ID() }}, {"wrong-generation", func(e *e3Evidence) { e.Payload.Generation = "9007199254740993" }},
		{"wrong-fingerprint", func(e *e3Evidence) { e.Payload.Fingerprint = strings.Repeat("b", 64) }}, {"wrong-scope", func(e *e3Evidence) { e.Payload.Purpose = "effect" }},
		{"wrong-audience", func(e *e3Evidence) { e.Payload.Audience = "other" }}, {"wrong-issuer", func(e *e3Evidence) { e.Payload.Issuer = "untrusted" }},
		{"wrong-executor-san", func(e *e3Evidence) { e.Verifier.PeerSAN = "other-executor" }}, {"revoked", func(e *e3Evidence) {
			k := e.Verifier.Policy.Keys[e.Payload.KeyID]
			k.Revoked = true
			e.Verifier.Policy.Keys[e.Payload.KeyID] = k
		}},
		{"wrong-signature", func(e *e3Evidence) { _, key, err := ed25519.GenerateKey(rand.Reader); must(t, err); e.Key = key }},
		{"stale", func(e *e3Evidence) { e.Payload.StartedAt = e.Payload.StartedAt.Add(-time.Minute) }},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ctx, p, s := env(t)
			d := e3Dispatched(t, ctx, p, s, false)
			c := e3Challenge(t, ctx, s, d)
			ev := e3SignedEvidence(t, c, "definitive_non_delivery")
			tc.change(&ev)
			denied(t, s.AcceptDispatchEvidence(ctx, c.ID, ev.raw(t), ev.Verifier))
			r, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
			must(t, e)
			if r.State != "outcome_unknown" {
				t.Fatal("invalid evidence altered uncertainty")
			}
		})
	}
}
func TestE3EvidenceRechecksCanonicalChanges(t *testing.T) {
	for _, change := range []string{"supersession", "retirement", "attempt-rollover", "cancelled-operation", "expiry"} {
		t.Run(change, func(t *testing.T) {
			ctx, p, s := env(t)
			d := e3Dispatched(t, ctx, p, s, false)
			c := e3Challenge(t, ctx, s, d)
			ev := e3SignedEvidence(t, c, "definitive_non_delivery")
			var e error
			switch change {
			case "supersession":
				_, e = s.NextGeneration(ctx, d.I.TenantID, d.I.ID)
			case "retirement":
				_, e = s.Retire(ctx, d.I.TenantID, d.I.ID)
			case "attempt-rollover":
				_, e = p.Exec(ctx, "UPDATE runtime_operations SET attempt_count=2 WHERE id=$1", d.Intent.OperationID)
			case "cancelled-operation":
				_, e = p.Exec(ctx, "UPDATE runtime_operations SET status='cancelled' WHERE id=$1", d.Intent.OperationID)
			case "expiry":
				_, e = p.Exec(ctx, "UPDATE infrastructure_dispatch_evidence_challenges SET expires_at=now()-interval '1 second' WHERE id=$1", c.ID)
			}
			must(t, e)
			denied(t, s.AcceptDispatchEvidence(ctx, c.ID, ev.raw(t), ev.Verifier))
			r, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
			must(t, e)
			if r.State != "outcome_unknown" {
				t.Fatal("stale canonical evidence resolved uncertainty")
			}
		})
	}
}
func TestE3ConcurrentDefinitiveEvidence(t *testing.T) {
	ctx, p, s := env(t)
	d := e3Dispatched(t, ctx, p, s, false)
	c := e3Challenge(t, ctx, s, d)
	ev := e3SignedEvidence(t, c, "definitive_non_delivery")
	raw := ev.raw(t)
	start := make(chan struct{})
	errs := make(chan error, 2)
	var wg sync.WaitGroup
	for j := 0; j < 2; j++ {
		wg.Add(1)
		go func() { defer wg.Done(); <-start; errs <- s.AcceptDispatchEvidence(ctx, c.ID, raw, ev.Verifier) }()
	}
	close(start)
	wg.Wait()
	close(errs)
	for e := range errs {
		must(t, e)
	}
	var n int
	must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_dispatch_evidence_receipts WHERE challenge_id=$1", c.ID).Scan(&n))
	if n != 1 {
		t.Fatal("concurrent receipt duplication")
	}
}
