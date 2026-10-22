// Package infrastructure implements the private evidence/transport boundary. It
// owns neither canonical persistence nor Kubernetes clients.
package infrastructure

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"reflect"
	"strings"
	"time"
)

const ReceiptVersion = "utcp.observation-receipt.v1"

type ReceiptPayload struct {
	ContractVersion          string    `json:"contract_version"`
	ReceiptID                string    `json:"receipt_id"`
	Issuer                   string    `json:"issuer"`
	KeyID                    string    `json:"key_id"`
	ChallengeID              string    `json:"challenge_id"`
	Nonce                    string    `json:"nonce"`
	Purpose                  string    `json:"purpose"`
	Audience                 string    `json:"audience"`
	IntentID                 string    `json:"intent_id"`
	DispatchID               string    `json:"dispatch_id"`
	EnvelopeHash             string    `json:"envelope_hash"`
	TenantID                 string    `json:"tenant_id"`
	RuntimeNodeID            string    `json:"runtime_node_id"`
	ClusterID                string    `json:"cluster_id"`
	Namespace                string    `json:"namespace"`
	Name                     string    `json:"name"`
	IncarnationID            string    `json:"incarnation_id"`
	ExpectedUID              string    `json:"expected_uid"`
	ObservedUID              string    `json:"observed_uid"`
	ResourceVersion          string    `json:"resource_version"`
	Outcome                  string    `json:"outcome"`
	Complete                 bool      `json:"complete"`
	StartedAt                time.Time `json:"started_at"`
	FinishedAt               time.Time `json:"finished_at"`
	ExpiresAt                time.Time `json:"expires_at"`
	Source                   string    `json:"source"`
	Generation               string    `json:"generation"`
	Fingerprint              string    `json:"fingerprint"`
	EffectDigest             string    `json:"effect_digest"`
	Replicas                 *int      `json:"replicas"`
	ObservedTenantID         string    `json:"observed_tenant_id"`
	ObservedRuntimeNodeID    string    `json:"observed_runtime_node_id"`
	ObservedIncarnationID    string    `json:"observed_incarnation_id"`
	AdmittedGeneration       string    `json:"admitted_generation"`
	AdmittedFingerprint      string    `json:"admitted_fingerprint"`
	AppliedGeneration        string    `json:"applied_generation"`
	AppliedFingerprint       string    `json:"applied_fingerprint"`
	ObservedProjectionDigest string    `json:"observed_projection_digest"`
}
type SignedReceipt struct {
	Payload   ReceiptPayload `json:"payload"`
	Signature string         `json:"signature"`
}
type Challenge struct {
	ID, Nonce, Audience, Purpose, IntentID, DispatchID, EnvelopeHash, TenantID, RuntimeNodeID, ClusterID, Namespace, Name, IncarnationID, ExpectedUID, Generation, Fingerprint, EffectDigest string
	ExpiresAt, IssuedAt                                                                                                                                                                      time.Time
	Action, ProjectionDigest                                                                                                                                                                 string
}
type TrustedKey struct {
	Issuer, PeerSAN, ClusterID, Namespace string
	Purposes                              []string
	PublicKey                             ed25519.PublicKey
	NotBefore, NotAfter                   time.Time
	Revoked                               bool
}
type TrustPolicy struct {
	Keys              map[string]TrustedKey
	MaxAge, ClockSkew time.Duration
}
type VerifiedReceipt struct {
	Payload      ReceiptPayload
	Digest       string
	SafeEnvelope json.RawMessage
}

// CanonicalReceiptBytes is a versioned fixed-order struct projection. No maps,
// floating point values or raw Kubernetes objects enter signed material.
func CanonicalReceiptBytes(p ReceiptPayload) ([]byte, error) {
	p.StartedAt = p.StartedAt.UTC()
	p.FinishedAt = p.FinishedAt.UTC()
	p.ExpiresAt = p.ExpiresAt.UTC()
	return json.Marshal(p)
}
func SignReceipt(p ReceiptPayload, key ed25519.PrivateKey) (SignedReceipt, error) {
	if len(key) != ed25519.PrivateKeySize {
		return SignedReceipt{}, errors.New("invalid signing key")
	}
	b, e := CanonicalReceiptBytes(p)
	if e != nil {
		return SignedReceipt{}, e
	}
	return SignedReceipt{p, base64.RawURLEncoding.EncodeToString(ed25519.Sign(key, b))}, nil
}
func ParseReceipt(raw []byte) (SignedReceipt, error) {
	var r SignedReceipt
	if len(raw) > 32768 {
		return r, errors.New("receipt too large")
	}
	err := DecodeStrict(raw, &r)
	if err != nil {
		return r, err
	}
	var outer map[string]json.RawMessage
	_ = json.Unmarshal(raw, &outer)
	if len(outer) != 2 {
		return r, errors.New("missing receipt fields")
	}
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(outer["payload"], &fields)
	typ := reflect.TypeOf(r.Payload)
	for i := 0; i < typ.NumField(); i++ {
		v, ok := fields[typ.Field(i).Tag.Get("json")]
		if !ok || string(v) == "null" && typ.Field(i).Name != "Replicas" {
			return r, errors.New("missing receipt fields")
		}
	}
	return r, nil
}
func VerifyReceipt(r SignedReceipt, c Challenge, policy TrustPolicy, peerSAN string, now time.Time) (VerifiedReceipt, error) {
	reject := func() (VerifiedReceipt, error) { return VerifiedReceipt{}, errors.New("receipt evidence rejected") }
	p := r.Payload
	k, ok := policy.Keys[p.KeyID]
	if !ok || k.Revoked || len(k.PublicKey) != ed25519.PublicKeySize || k.Issuer != p.Issuer || k.PeerSAN != peerSAN || k.ClusterID != p.ClusterID || k.Namespace != p.Namespace || now.Before(k.NotBefore) || !now.Before(k.NotAfter) {
		return reject()
	}
	allowed := false
	for _, v := range k.Purposes {
		if v == p.Purpose {
			allowed = true
		}
	}
	if !allowed {
		return reject()
	}
	if p.ContractVersion != ReceiptVersion || p.ReceiptID == "" || p.Source != "exact_get" || !p.Complete || p.ChallengeID != c.ID || p.Nonce != c.Nonce || p.Audience != c.Audience || p.Purpose != c.Purpose || p.IntentID != c.IntentID || p.DispatchID != c.DispatchID || p.EnvelopeHash != c.EnvelopeHash || p.TenantID != c.TenantID || p.RuntimeNodeID != c.RuntimeNodeID || p.ClusterID != c.ClusterID || p.Namespace != c.Namespace || p.Name != c.Name || p.IncarnationID != c.IncarnationID || p.ExpectedUID != c.ExpectedUID || p.Generation != c.Generation || p.Fingerprint != c.Fingerprint || p.EffectDigest != c.EffectDigest {
		return reject()
	}
	if c.ID == "" || c.Nonce == "" || c.Audience == "" || c.TenantID == "" || c.RuntimeNodeID == "" || c.IncarnationID == "" || c.ClusterID == "" || c.Namespace == "" || c.Name == "" || c.IntentID == "" {
		return reject()
	}
	age, skew := policy.MaxAge, policy.ClockSkew
	if age <= 0 {
		age = 30 * time.Second
	}
	if skew <= 0 {
		skew = 5 * time.Second
	}
	if c.IssuedAt.IsZero() || p.StartedAt.Before(c.IssuedAt) || p.StartedAt.IsZero() || p.FinishedAt.Before(p.StartedAt) || p.FinishedAt.After(now.Add(skew)) || p.StartedAt.After(now.Add(skew)) || now.Sub(p.StartedAt) > age || !now.Before(p.ExpiresAt) || !now.Before(c.ExpiresAt) || p.ExpiresAt.After(c.ExpiresAt) || p.ExpiresAt.Before(p.FinishedAt) {
		return reject()
	}
	if p.Outcome == "found" {
		if p.Purpose == "absence" || p.ObservedTenantID != c.TenantID || p.ObservedRuntimeNodeID != c.RuntimeNodeID || p.ObservedIncarnationID != c.IncarnationID {
			return reject()
		}
		if p.Purpose == "bind" && (c.Action != "create_suspended" || p.Replicas == nil || *p.Replicas != 0 || c.ProjectionDigest == "" || p.ObservedProjectionDigest != c.ProjectionDigest || p.AdmittedGeneration != c.Generation || p.AdmittedFingerprint != c.Fingerprint || p.AppliedGeneration != c.Generation || p.AppliedFingerprint != c.Fingerprint) {
			return reject()
		}
		if p.Purpose == "effect" {
			if p.AdmittedGeneration != c.Generation || p.AdmittedFingerprint != c.Fingerprint {
				return reject()
			}
			if c.Action != "admit_generation" && (p.AppliedGeneration != c.Generation || p.AppliedFingerprint != c.Fingerprint || p.Replicas == nil) {
				return reject()
			}
			if c.Action == "converge_projection" && (c.ProjectionDigest == "" || p.ObservedProjectionDigest != c.ProjectionDigest) {
				return reject()
			}
		}
		if p.ObservedUID == "" || p.ResourceVersion == "" || (c.ExpectedUID != "" && p.ObservedUID != c.ExpectedUID) {
			return reject()
		}
	} else if p.Outcome == "absent" {
		if p.Purpose != "absence" {
			return reject()
		}
		if p.ObservedUID != "" || p.ResourceVersion != "" || p.Replicas != nil || p.ObservedTenantID != "" || p.ObservedRuntimeNodeID != "" || p.ObservedIncarnationID != "" || p.AdmittedGeneration != "" || p.AdmittedFingerprint != "" || p.AppliedGeneration != "" || p.AppliedFingerprint != "" || p.ObservedProjectionDigest != "" {
			return reject()
		}
	} else {
		return reject()
	}
	if p.Replicas != nil && *p.Replicas < 0 {
		return reject()
	}
	for _, s := range []string{p.Issuer, p.KeyID, p.ReceiptID, p.Nonce, p.Audience, p.IntentID, p.DispatchID, p.TenantID, p.RuntimeNodeID, p.ClusterID, p.Namespace, p.Name, p.IncarnationID, p.ExpectedUID, p.ObservedUID, p.ResourceVersion, p.Generation, p.Fingerprint, p.EffectDigest, p.EnvelopeHash, p.ObservedTenantID, p.ObservedRuntimeNodeID, p.ObservedIncarnationID, p.AdmittedGeneration, p.AdmittedFingerprint, p.AppliedGeneration, p.AppliedFingerprint, p.ObservedProjectionDigest} {
		if len(s) > 512 || strings.ContainsAny(s, "\x00\r\n") {
			return reject()
		}
	}
	raw, err := CanonicalReceiptBytes(p)
	if err != nil {
		return reject()
	}
	sig, err := base64.RawURLEncoding.Strict().DecodeString(r.Signature)
	if err != nil || !ed25519.Verify(k.PublicKey, raw, sig) {
		return reject()
	}
	sum := sha256.Sum256(raw)
	safe, _ := json.Marshal(r)
	return VerifiedReceipt{p, hex.EncodeToString(sum[:]), safe}, nil
}
