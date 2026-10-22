package infrastructure

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func receiptFixture(t *testing.T) (ReceiptPayload, Challenge, TrustPolicy, ed25519.PrivateKey, time.Time) {
	t.Helper()
	now := time.Date(2026, 10, 22, 12, 0, 0, 0, time.UTC)
	pub, key, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	p := ReceiptPayload{ContractVersion: ReceiptVersion, ReceiptID: "r1", Issuer: "observer", KeyID: "key1", ChallengeID: "c1", Nonce: "challenge-random-nonce", Purpose: "effect", Audience: "canonical", IntentID: "i1", DispatchID: "d1", EnvelopeHash: strings.Repeat("a", 64), TenantID: "t1", RuntimeNodeID: "n1", ClusterID: "test", Namespace: "test", Name: "runtime-physical", IncarnationID: "inc1", ExpectedUID: "uid1", ObservedUID: "uid1", ResourceVersion: "opaque-version", Outcome: "found", Complete: true, StartedAt: now.Add(-time.Second), FinishedAt: now, ExpiresAt: now.Add(10 * time.Second), Source: "exact_get", Generation: "9007199254740993", Fingerprint: strings.Repeat("b", 64), EffectDigest: strings.Repeat("c", 64)}
	replicas := 1
	p.Replicas = &replicas
	p.ObservedTenantID = p.TenantID
	p.ObservedRuntimeNodeID = p.RuntimeNodeID
	p.ObservedIncarnationID = p.IncarnationID
	p.AdmittedGeneration, p.AppliedGeneration = p.Generation, p.Generation
	p.AdmittedFingerprint, p.AppliedFingerprint = p.Fingerprint, p.Fingerprint
	c := Challenge{Action: "set_replicas", IssuedAt: now.Add(-2 * time.Second), ID: p.ChallengeID, Nonce: p.Nonce, Audience: p.Audience, Purpose: p.Purpose, IntentID: p.IntentID, DispatchID: p.DispatchID, EnvelopeHash: p.EnvelopeHash, TenantID: p.TenantID, RuntimeNodeID: p.RuntimeNodeID, ClusterID: p.ClusterID, Namespace: p.Namespace, Name: p.Name, IncarnationID: p.IncarnationID, ExpectedUID: p.ExpectedUID, Generation: p.Generation, Fingerprint: p.Fingerprint, EffectDigest: p.EffectDigest, ExpiresAt: now.Add(15 * time.Second)}
	policy := TrustPolicy{Keys: map[string]TrustedKey{"key1": {Issuer: p.Issuer, PeerSAN: "observer.test", ClusterID: p.ClusterID, Namespace: p.Namespace, Purposes: []string{"bind", "effect", "absence"}, PublicKey: pub, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour)}}}
	return p, c, policy, key, now
}
func TestReceiptSignatureAndExactProjection(t *testing.T) {
	p, c, policy, key, now := receiptFixture(t)
	r, e := SignReceipt(p, key)
	if e != nil {
		t.Fatal(e)
	}
	wire, _ := json.Marshal(r)
	parsed, e := ParseReceipt(wire)
	if e != nil {
		t.Fatal(e)
	}
	got, e := VerifyReceipt(parsed, c, policy, "observer.test", now)
	if e != nil {
		t.Fatal(e)
	}
	if got.Payload.Generation != "9007199254740993" || len(got.Digest) != 64 || strings.Contains(string(got.SafeEnvelope), "secret") {
		t.Fatal("unsafe projection")
	}
	r2, _ := SignReceipt(p, key)
	if r.Signature != r2.Signature {
		t.Fatal("nondeterministic signature")
	}
}
func TestReceiptNegativeCases(t *testing.T) {
	tests := []struct {
		name string
		edit func(*ReceiptPayload, *Challenge, *TrustPolicy, *string)
	}{
		{"observed ownership mismatch", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.ObservedTenantID = "other" }},
		{"missing applied marker", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.AppliedGeneration = "" }},
		{"wrong admitted marker", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.AdmittedFingerprint = "other" }},
		{"observation predates challenge", func(p *ReceiptPayload, c *Challenge, _ *TrustPolicy, _ *string) {
			c.IssuedAt = p.StartedAt.Add(time.Nanosecond)
		}},
		{"unobserved projection", func(p *ReceiptPayload, c *Challenge, _ *TrustPolicy, _ *string) {
			c.Action = "converge_projection"
			c.ProjectionDigest = "expected"
		}},
		{"wrong issuer", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Issuer = "other" }},
		{"expired signing key", func(_ *ReceiptPayload, _ *Challenge, v *TrustPolicy, _ *string) {
			k := v.Keys["key1"]
			k.NotAfter = time.Time{}
			v.Keys["key1"] = k
		}},
		{"unissued signing key", func(_ *ReceiptPayload, c *Challenge, v *TrustPolicy, _ *string) {
			k := v.Keys["key1"]
			k.NotBefore = c.ExpiresAt
			v.Keys["key1"] = k
		}},
		{"wrong namespace", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Namespace = "other" }},
		{"wrong request digest", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.EnvelopeHash = "other" }},
		{"wrong key", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.KeyID = "unknown" }},
		{"revoked", func(_ *ReceiptPayload, _ *Challenge, v *TrustPolicy, _ *string) {
			k := v.Keys["key1"]
			k.Revoked = true
			v.Keys["key1"] = k
		}},
		{"wrong peer", func(_ *ReceiptPayload, _ *Challenge, _ *TrustPolicy, s *string) { *s = "attacker.test" }},
		{"wrong cluster", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.ClusterID = "elsewhere" }},
		{"wrong uid", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.ObservedUID = "replacement" }},
		{"wrong incarnation", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.IncarnationID = "replacement" }},
		{"wrong nonce", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Nonce = "replayed" }},
		{"wrong purpose", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Purpose = "provider_ready" }},
		{"wrong audience", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Audience = "browser" }},
		{"incomplete", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Complete = false }},
		{"stale", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) {
			p.StartedAt = p.StartedAt.Add(-time.Minute)
		}},
		{"future", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) {
			p.FinishedAt = p.FinishedAt.Add(time.Minute)
		}},
		{"expired challenge", func(p *ReceiptPayload, c *Challenge, _ *TrustPolicy, _ *string) { c.ExpiresAt = p.StartedAt }},
		{"failed observation", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Outcome = "forbidden" }},
		{"list omission", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.Source = "list" }},
		{"unbound absence", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) {
			p.Outcome = "absent"
			p.ObservedUID = ""
			p.ResourceVersion = ""
		}},
		{"effect mismatch", func(p *ReceiptPayload, _ *Challenge, _ *TrustPolicy, _ *string) { p.EffectDigest = "other" }},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			p, c, policy, key, now := receiptFixture(t)
			peer := "observer.test"
			tc.edit(&p, &c, &policy, &peer)
			r, _ := SignReceipt(p, key)
			if _, e := VerifyReceipt(r, c, policy, peer, now); e == nil {
				t.Fatal("accepted invalid evidence")
			}
		})
	}
	p, c, policy, key, now := receiptFixture(t)
	r, _ := SignReceipt(p, key)
	r.Payload.Name = "forged"
	if _, e := VerifyReceipt(r, c, policy, "observer.test", now); e == nil {
		t.Fatal("accepted forgery")
	}
}
func TestExactAbsentReceipt(t *testing.T) {
	p, c, policy, key, now := receiptFixture(t)
	p.Replicas = nil
	p.ObservedTenantID, p.ObservedRuntimeNodeID, p.ObservedIncarnationID = "", "", ""
	p.AdmittedGeneration, p.AppliedGeneration, p.AdmittedFingerprint, p.AppliedFingerprint = "", "", "", ""
	p.Purpose = "absence"
	c.Purpose = "absence"
	p.Outcome = "absent"
	p.ObservedUID = ""
	p.ResourceVersion = ""
	r, _ := SignReceipt(p, key)
	if _, e := VerifyReceipt(r, c, policy, "observer.test", now); e != nil {
		t.Fatal(e)
	}
}
func TestClosedJSON(t *testing.T) {
	for _, raw := range []string{`{"x":"a","x":"b"}`, `{"x":{"a":1,"a":2}}`, `{"x":1} {}`, `{"x":"\ud800"}`} {
		var dst map[string]any
		if DecodeStrict([]byte(raw), &dst) == nil {
			t.Fatalf("accepted %s", raw)
		}
	}
	p, _, _, key, _ := receiptFixture(t)
	r, _ := SignReceipt(p, key)
	b, _ := json.Marshal(r)
	for _, bad := range []string{
		strings.Replace(string(b), `"source":"exact_get"`, `"source":"exact_get","source":"list"`, 1),
		strings.Replace(string(b), `"source":"exact_get"`, `"source":null`, 1),
		strings.Replace(string(b), `"source":"exact_get"`, `"source":"exact_get","raw_secret":"forbidden"`, 1),
	} {
		if _, err := ParseReceipt([]byte(bad)); err == nil {
			t.Fatal("accepted ambiguous receipt fields")
		}
	}
	b = []byte(strings.Replace(string(b), `"source":"exact_get",`, "", 1))
	if _, e := ParseReceipt(b); e == nil {
		t.Fatal("accepted missing source")
	}
}

func TestReceiptObservedEffects(t *testing.T) {
	for _, action := range []string{"admit_generation", "set_replicas", "converge_projection"} {
		t.Run(action, func(t *testing.T) {
			p, c, policy, key, now := receiptFixture(t)
			c.Action = action
			if action == "admit_generation" {
				p.Replicas = nil
				p.AppliedGeneration = ""
				p.AppliedFingerprint = ""
			}
			if action == "converge_projection" {
				c.ProjectionDigest = strings.Repeat("d", 64)
				p.ObservedProjectionDigest = c.ProjectionDigest
			}
			r, _ := SignReceipt(p, key)
			if _, err := VerifyReceipt(r, c, policy, "observer.test", now); err != nil {
				t.Fatal(err)
			}
			if action == "converge_projection" {
				p.ObservedProjectionDigest = ""
				r, _ = SignReceipt(p, key)
				if _, err := VerifyReceipt(r, c, policy, "observer.test", now); err == nil {
					t.Fatal("projection not observed")
				}
			}
		})
	}
	p, c, policy, key, now := receiptFixture(t)
	p.Purpose = "bind"
	c.Purpose = "bind"
	c.Action = "create_suspended"
	zero := 0
	p.Replicas = &zero
	c.ProjectionDigest = strings.Repeat("d", 64)
	p.ObservedProjectionDigest = c.ProjectionDigest
	p.ExpectedUID = ""
	c.ExpectedUID = ""
	r, _ := SignReceipt(p, key)
	if _, err := VerifyReceipt(r, c, policy, "observer.test", now); err != nil {
		t.Fatal(err)
	}
	for _, replicas := range []*int{nil, new(int)} {
		if replicas != nil {
			*replicas = 1
		}
		p.Replicas = replicas
		r, _ = SignReceipt(p, key)
		if _, err := VerifyReceipt(r, c, policy, "observer.test", now); err == nil {
			t.Fatal("non-inert binding accepted")
		}
	}
}
