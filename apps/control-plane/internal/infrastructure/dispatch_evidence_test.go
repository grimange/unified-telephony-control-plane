package infrastructure

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
	"github.com/santhosh-tekuri/jsonschema/v5"
)

func dispatchEvidenceFixture(t *testing.T) (DispatchEvidencePayload, kernel.DispatchEvidenceChallenge, DispatchEvidenceVerifier, ed25519.PrivateKey) {
	t.Helper()
	now := time.Date(2026, 10, 22, 12, 0, 0, 0, time.UTC)
	pub, key, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	c := kernel.DispatchEvidenceChallenge{ID: "challenge", Nonce: "challenge-nonce", Audience: "canonical", TenantID: "tenant", RuntimeNodeID: "node", IncarnationID: "incarnation", ClusterID: "cluster", Namespace: "fixture", Name: "runtime-fixture", ExpectedUID: "uid", IntentID: "intent", DispatchID: "dispatch", OperationID: "operation", Executor: "executor.test", EnvelopeHash: strings.Repeat("a", 64), Generation: "9007199254740993", Fingerprint: strings.Repeat("b", 64), Action: "set_replicas", Attempt: 1, IssuedAt: now.Add(-2 * time.Second), ExpiresAt: now.Add(20 * time.Second)}
	p := DispatchEvidencePayload{ContractVersion: DispatchEvidenceVersion, ReceiptID: "receipt", Issuer: "executor", KeyID: "key", ChallengeID: c.ID, Nonce: c.Nonce, Audience: c.Audience, Purpose: "dispatch_evidence", TenantID: c.TenantID, RuntimeNodeID: c.RuntimeNodeID, ClusterID: c.ClusterID, Namespace: c.Namespace, Name: c.Name, IncarnationID: c.IncarnationID, ExpectedUID: c.ExpectedUID, IntentID: c.IntentID, DispatchID: c.DispatchID, OperationID: c.OperationID, Attempt: c.Attempt, Executor: c.Executor, EnvelopeHash: c.EnvelopeHash, Generation: c.Generation, Fingerprint: c.Fingerprint, Action: c.Action, EvidenceType: "definitive_non_delivery", Outcome: "confirmed_not_applied", JournalID: "closed-journal", Final: true, Complete: true, StartedAt: now.Add(-time.Second), FinishedAt: now, ExpiresAt: now.Add(10 * time.Second)}
	v := DispatchEvidenceVerifier{Policy: TrustPolicy{Keys: map[string]TrustedKey{"key": {Issuer: p.Issuer, PeerSAN: c.Executor, ClusterID: c.ClusterID, Namespace: c.Namespace, Purposes: []string{"dispatch_evidence"}, PublicKey: pub, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour)}}}, PeerSAN: c.Executor, Now: func() time.Time { return now }}
	return p, c, v, key
}
func TestDispatchEvidenceSupportedProofs(t *testing.T) {
	for _, kind := range []string{"pre_dispatch_rejected", "definitive_non_delivery", "api_rejected"} {
		t.Run(kind, func(t *testing.T) {
			p, c, v, key := dispatchEvidenceFixture(t)
			p.EvidenceType = kind
			if kind == "api_rejected" {
				p.Submissions = 1
				p.APICode = 422
				p.APIReason = "Invalid"
			}
			r, e := SignDispatchEvidence(p, key)
			if e != nil {
				t.Fatal(e)
			}
			raw, _ := json.Marshal(r)
			got, e := v.VerifyDispatchEvidence(context.Background(), raw, c)
			if e != nil {
				t.Fatal(e)
			}
			if got.Challenge != c || !got.Final || got.EvidenceType != kind {
				t.Fatal("correlation lost")
			}
			r2, _ := SignDispatchEvidence(p, key)
			if r.Signature != r2.Signature {
				t.Fatal("unstable signing")
			}
			var value any
			_ = json.Unmarshal(raw, &value)
			path, _ := filepath.Abs(filepath.Join("..", "..", "contracts", "dispatch-evidence.schema.json"))
			schema, e := jsonschema.Compile(path)
			if e != nil {
				t.Fatal(e)
			}
			if e = schema.Validate(value); e != nil {
				t.Fatal(e)
			}
		})
	}
}
func TestDispatchEvidenceSecurityRejections(t *testing.T) {
	cases := []struct {
		name string
		edit func(*DispatchEvidencePayload, *kernel.DispatchEvidenceChallenge, *DispatchEvidenceVerifier)
	}{
		{"version", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.ContractVersion = ReceiptVersion
		}},
		{"issuer", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Issuer = "other"
		}},
		{"key", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.KeyID = "other"
		}},
		{"revoked", func(_ *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			k := v.Policy.Keys["key"]
			k.Revoked = true
			v.Policy.Keys["key"] = k
		}},
		{"expired key", func(_ *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			k := v.Policy.Keys["key"]
			k.NotAfter = v.Now()
			v.Policy.Keys["key"] = k
		}},
		{"wrong peer", func(_ *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			v.PeerSAN = "other"
		}},
		{"other scoped observer", func(_ *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			k := v.Policy.Keys["key"]
			k.PeerSAN = "other"
			v.Policy.Keys["key"] = k
			v.PeerSAN = "other"
		}},
		{"key purpose", func(_ *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			k := v.Policy.Keys["key"]
			k.Purposes = []string{"effect"}
			v.Policy.Keys["key"] = k
		}},
		{"key namespace", func(_ *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			k := v.Policy.Keys["key"]
			k.Namespace = "other"
			v.Policy.Keys["key"] = k
		}},
		{"key cluster", func(_ *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			k := v.Policy.Keys["key"]
			k.ClusterID = "other"
			v.Policy.Keys["key"] = k
		}},
		{"purpose", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Purpose = "effect"
		}},
		{"audience", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Audience = "other"
		}},
		{"nonce", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Nonce = "old"
		}},
		{"challenge", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.ChallengeID = "other"
		}},
		{"tenant", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.TenantID = "other"
		}},
		{"runtime", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.RuntimeNodeID = "other"
		}},
		{"cluster", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.ClusterID = "other"
		}},
		{"namespace", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Namespace = "other"
		}},
		{"name", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Name = "other"
		}},
		{"incarnation", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.IncarnationID = "other"
		}},
		{"uid", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.ExpectedUID = "replacement"
		}},
		{"intent", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.IntentID = "other"
		}},
		{"operation", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.OperationID = "other"
		}},
		{"attempt", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Attempt++
		}},
		{"dispatch", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.DispatchID = "other"
		}},
		{"executor", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Executor = "other"
		}},
		{"generation", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Generation = "9007199254740992"
		}},
		{"fingerprint", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Fingerprint = strings.Repeat("c", 64)
		}},
		{"request digest", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.EnvelopeHash = strings.Repeat("c", 64)
		}},
		{"action", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Action = "create_suspended"
		}},
		{"outcome", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Outcome = "confirmed_applied"
		}},
		{"not final", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Final = false
		}},
		{"incomplete", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Complete = false
		}},
		{"missing journal", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.JournalID = ""
		}},
		{"submitted", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.Submissions = 1
		}},
		{"ambiguous transport", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.EvidenceType = "transport_failure"
		}},
		{"unchanged observation", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.EvidenceType = "exact_get"
		}},
		{"stale", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.StartedAt = p.StartedAt.Add(-time.Minute)
		}},
		{"predates challenge", func(p *DispatchEvidencePayload, c *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.StartedAt = c.IssuedAt.Add(-time.Nanosecond)
		}},
		{"expired", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			p.ExpiresAt = v.Now()
		}},
		{"expired challenge", func(_ *DispatchEvidencePayload, c *kernel.DispatchEvidenceChallenge, v *DispatchEvidenceVerifier) {
			c.ExpiresAt = v.Now()
		}},
		{"outlives challenge", func(p *DispatchEvidencePayload, c *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.ExpiresAt = c.ExpiresAt.Add(time.Second)
		}},
		{"future", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.FinishedAt = p.FinishedAt.Add(time.Hour)
		}},
		{"invalid time order", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.FinishedAt = p.StartedAt.Add(-time.Second)
		}},
		{"timeout status", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.EvidenceType = "api_rejected"
			p.Submissions = 1
			p.APICode = 504
			p.APIReason = "Timeout"
		}},
		{"arbitrary conflict", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.EvidenceType = "api_rejected"
			p.Submissions = 1
			p.APICode = 409
			p.APIReason = "Timeout"
		}},
		{"arbitrary invalid", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.EvidenceType = "api_rejected"
			p.Submissions = 1
			p.APICode = 422
			p.APIReason = "Unknown"
		}},
		{"retried API", func(p *DispatchEvidencePayload, _ *kernel.DispatchEvidenceChallenge, _ *DispatchEvidenceVerifier) {
			p.EvidenceType = "api_rejected"
			p.Submissions = 2
			p.APICode = 403
			p.APIReason = "Forbidden"
		}},
	}
	for _, tt := range cases {
		t.Run(tt.name, func(t *testing.T) {
			p, c, v, key := dispatchEvidenceFixture(t)
			tt.edit(&p, &c, &v)
			r, _ := SignDispatchEvidence(p, key)
			raw, _ := json.Marshal(r)
			if _, e := v.VerifyDispatchEvidence(context.Background(), raw, c); e == nil {
				t.Fatal("unsafe evidence accepted")
			}
		})
	}
}
func TestDispatchEvidenceStrictWireAndForgery(t *testing.T) {
	p, c, v, key := dispatchEvidenceFixture(t)
	r, _ := SignDispatchEvidence(p, key)
	raw, _ := json.Marshal(r)
	for name, b := range map[string][]byte{"unknown": []byte(strings.Replace(string(raw), `"payload":{`, `"payload":{"unexpected":true,`, 1)), "duplicate": []byte(strings.Replace(string(raw), `"contract_version":`, `"contract_version":"utcp.dispatch-evidence.v1","contract_version":`, 1)), "missing": []byte(strings.Replace(string(raw), `"final":true,`, "", 1)), "oversized": []byte(strings.Repeat(" ", 32769)), "malformed": []byte("{")} {
		t.Run(name, func(t *testing.T) {
			if _, e := v.VerifyDispatchEvidence(context.Background(), b, c); e == nil {
				t.Fatal("malformed accepted")
			}
		})
	}
	r.Signature = strings.Repeat("A", 86)
	forged, _ := json.Marshal(r)
	if _, e := v.VerifyDispatchEvidence(context.Background(), forged, c); e == nil {
		t.Fatal("forgery accepted")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, e := v.VerifyDispatchEvidence(ctx, raw, c); e == nil {
		t.Fatal("cancelled accepted")
	}
}
func TestDispatchEvidenceSchemaFixtures(t *testing.T) {
	var fixtures []struct {
		Name, Schema string
		Valid        bool
		Value        any
	}
	root := filepath.Join("..", "..", "contracts")
	raw, e := os.ReadFile(filepath.Join(root, "dispatch-evidence-fixtures.json"))
	if e != nil {
		t.Fatal(e)
	}
	if e = json.Unmarshal(raw, &fixtures); e != nil {
		t.Fatal(e)
	}
	for _, f := range fixtures {
		t.Run(f.Name, func(t *testing.T) {
			path, _ := filepath.Abs(filepath.Join(root, f.Schema))
			s, e := jsonschema.Compile(path)
			if e != nil {
				t.Fatal(e)
			}
			if e = s.Validate(f.Value); (e == nil) != f.Valid {
				t.Fatalf("schema validity want %v: %v", f.Valid, e)
			}
		})
	}
}
