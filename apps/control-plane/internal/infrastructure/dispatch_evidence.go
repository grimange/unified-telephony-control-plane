package infrastructure

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"reflect"
	"strings"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

const DispatchEvidenceVersion = "utcp.dispatch-evidence.v1"

// Final attests an irrevocably closed executor journal: no in-flight request and
// no future submission/retry is possible under this dispatch right. It is not a
// timeout/cancellation flag. API rejection further attests one authenticated API
// Status Failure response for the exact request, with automatic retries disabled.
// The kernel trusts configured issuers to tell the truth; signatures do not
// create transport non-delivery guarantees or validate Kubernetes observations.
type DispatchEvidencePayload struct {
	ContractVersion string    `json:"contract_version"`
	ReceiptID       string    `json:"receipt_id"`
	Issuer          string    `json:"issuer"`
	KeyID           string    `json:"key_id"`
	ChallengeID     string    `json:"challenge_id"`
	Nonce           string    `json:"nonce"`
	Audience        string    `json:"audience"`
	Purpose         string    `json:"purpose"`
	TenantID        string    `json:"tenant_id"`
	RuntimeNodeID   string    `json:"runtime_node_id"`
	ClusterID       string    `json:"cluster_id"`
	Namespace       string    `json:"namespace"`
	Name            string    `json:"name"`
	IncarnationID   string    `json:"incarnation_id"`
	ExpectedUID     string    `json:"expected_uid"`
	IntentID        string    `json:"intent_id"`
	DispatchID      string    `json:"dispatch_id"`
	OperationID     string    `json:"operation_id"`
	Attempt         int       `json:"attempt"`
	Executor        string    `json:"executor"`
	EnvelopeHash    string    `json:"envelope_hash"`
	Generation      string    `json:"generation"`
	Fingerprint     string    `json:"fingerprint"`
	Action          string    `json:"action"`
	EvidenceType    string    `json:"evidence_type"`
	Outcome         string    `json:"outcome"`
	JournalID       string    `json:"journal_id"`
	Final           bool      `json:"final"`
	Complete        bool      `json:"complete"`
	Submissions     int       `json:"submissions"`
	APICode         int       `json:"api_code"`
	APIReason       string    `json:"api_reason"`
	StartedAt       time.Time `json:"started_at"`
	FinishedAt      time.Time `json:"finished_at"`
	ExpiresAt       time.Time `json:"expires_at"`
}
type SignedDispatchEvidence struct {
	Payload   DispatchEvidencePayload `json:"payload"`
	Signature string                  `json:"signature"`
}

func CanonicalDispatchEvidenceBytes(p DispatchEvidencePayload) ([]byte, error) {
	p.StartedAt = p.StartedAt.UTC()
	p.FinishedAt = p.FinishedAt.UTC()
	p.ExpiresAt = p.ExpiresAt.UTC()
	return json.Marshal(p)
}
func SignDispatchEvidence(p DispatchEvidencePayload, key ed25519.PrivateKey) (SignedDispatchEvidence, error) {
	if len(key) != ed25519.PrivateKeySize {
		return SignedDispatchEvidence{}, errors.New("invalid signing key")
	}
	b, e := CanonicalDispatchEvidenceBytes(p)
	if e != nil {
		return SignedDispatchEvidence{}, e
	}
	return SignedDispatchEvidence{p, base64.RawURLEncoding.EncodeToString(ed25519.Sign(key, b))}, nil
}
func ParseDispatchEvidence(raw []byte) (r SignedDispatchEvidence, e error) {
	if len(raw) > 32768 {
		return r, errors.New("evidence too large")
	}
	if e = DecodeStrict(raw, &r); e != nil {
		return
	}
	var outer map[string]json.RawMessage
	_ = json.Unmarshal(raw, &outer)
	if len(outer) != 2 {
		return r, errors.New("missing evidence fields")
	}
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(outer["payload"], &fields)
	typ := reflect.TypeOf(r.Payload)
	for j := 0; j < typ.NumField(); j++ {
		v, ok := fields[typ.Field(j).Tag.Get("json")]
		if !ok || string(v) == "null" {
			return r, errors.New("missing evidence fields")
		}
	}
	return
}

type DispatchEvidenceVerifier struct {
	Policy  TrustPolicy
	PeerSAN string
	Now     func() time.Time
}

func (v DispatchEvidenceVerifier) VerifyDispatchEvidence(ctx context.Context, raw []byte, c kernel.DispatchEvidenceChallenge) (kernel.DispatchEvidence, error) {
	reject := func() (kernel.DispatchEvidence, error) {
		return kernel.DispatchEvidence{}, errors.New("dispatch evidence rejected")
	}
	if ctx.Err() != nil {
		return reject()
	}
	r, e := ParseDispatchEvidence(raw)
	if e != nil {
		return reject()
	}
	p := r.Payload
	now := time.Now()
	if v.Now != nil {
		now = v.Now()
	}
	k, ok := v.Policy.Keys[p.KeyID]
	allowed := false
	for _, purpose := range k.Purposes {
		if purpose == "dispatch_evidence" {
			allowed = true
		}
	}
	if !ok || !allowed || k.Revoked || len(k.PublicKey) != ed25519.PublicKeySize || k.Issuer != p.Issuer || k.PeerSAN != v.PeerSAN || v.PeerSAN != c.Executor || k.ClusterID != c.ClusterID || k.Namespace != c.Namespace || now.Before(k.NotBefore) || !now.Before(k.NotAfter) {
		return reject()
	}
	if p.ContractVersion != DispatchEvidenceVersion || p.Purpose != "dispatch_evidence" || p.Outcome != "confirmed_not_applied" || !p.Final || !p.Complete || p.ReceiptID == "" || len(p.ReceiptID) > 128 || len(p.Issuer) > 256 || p.JournalID == "" || p.ChallengeID != c.ID || p.Nonce != c.Nonce || p.Audience != c.Audience || p.TenantID != c.TenantID || p.RuntimeNodeID != c.RuntimeNodeID || p.ClusterID != c.ClusterID || p.Namespace != c.Namespace || p.Name != c.Name || p.IncarnationID != c.IncarnationID || p.ExpectedUID != c.ExpectedUID || p.IntentID != c.IntentID || p.DispatchID != c.DispatchID || p.OperationID != c.OperationID || p.Attempt != c.Attempt || p.Executor != c.Executor || p.EnvelopeHash != c.EnvelopeHash || p.Generation != c.Generation || p.Fingerprint != c.Fingerprint || p.Action != c.Action {
		return reject()
	}
	if c.ID == "" || c.Nonce == "" || c.Audience == "" || c.Executor == "" || c.Attempt < 1 || c.DispatchID == "" || c.OperationID == "" || c.IntentID == "" || c.IncarnationID == "" || c.TenantID == "" || c.RuntimeNodeID == "" || c.ClusterID == "" || c.Namespace == "" || c.Name == "" || len(c.EnvelopeHash) != 64 || len(c.Fingerprint) != 64 {
		return reject()
	}
	if _, e = kernel.Generation(c.Generation); e != nil {
		return reject()
	}
	switch p.Action {
	case "admit_generation", "set_replicas", "create_suspended", "converge_projection", "retire_delete":
	default:
		return reject()
	}
	switch p.EvidenceType {
	case "pre_dispatch_rejected", "definitive_non_delivery":
		if p.Submissions != 0 || p.APICode != 0 || p.APIReason != "" {
			return reject()
		}
	case "api_rejected":
		if p.Submissions != 1 || !kernel.DefinitiveAPIRejection(p.APICode, p.APIReason) {
			return reject()
		}
	default:
		return reject()
	}
	age, skew := v.Policy.MaxAge, v.Policy.ClockSkew
	if age <= 0 {
		age = 30 * time.Second
	}
	if skew <= 0 {
		skew = 5 * time.Second
	}
	if c.IssuedAt.IsZero() || p.StartedAt.Before(c.IssuedAt) || p.StartedAt.IsZero() || p.FinishedAt.Before(p.StartedAt) || p.FinishedAt.After(now.Add(skew)) || p.StartedAt.After(now.Add(skew)) || now.Sub(p.StartedAt) > age || !now.Before(c.ExpiresAt) || !now.Before(p.ExpiresAt) || p.ExpiresAt.After(c.ExpiresAt) || p.ExpiresAt.Before(p.FinishedAt) {
		return reject()
	}
	// All public strings are bounded safe identifiers; no Status message, request
	// payload, bearer token, capability, or raw operation envelope is accepted.
	rv := reflect.ValueOf(p)
	for j := 0; j < rv.NumField(); j++ {
		if rv.Field(j).Kind() == reflect.String {
			s := rv.Field(j).String()
			if len(s) > 512 || strings.ContainsAny(s, "\x00\r\n") {
				return reject()
			}
		}
	}
	b, e := CanonicalDispatchEvidenceBytes(p)
	if e != nil {
		return reject()
	}
	sig, e := base64.RawURLEncoding.Strict().DecodeString(r.Signature)
	if e != nil || !ed25519.Verify(k.PublicKey, b, sig) {
		return reject()
	}
	safe, _ := json.Marshal(r)
	return kernel.DispatchEvidence{Challenge: c, ReceiptID: p.ReceiptID, Issuer: p.Issuer, EvidenceType: p.EvidenceType, Submissions: p.Submissions, Final: p.Final, JournalID: p.JournalID, APICode: p.APICode, APIReason: p.APIReason, StartedAt: p.StartedAt, FinishedAt: p.FinishedAt, ExpiresAt: p.ExpiresAt, SafeEnvelope: safe}, nil
}
