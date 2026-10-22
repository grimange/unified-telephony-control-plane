// Package executor implements isolated synthetic infrastructure I/O. It has no
// database access and does not execute Kubernetes requests.
package executor

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"net/url"
	"reflect"
	"strings"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

const GrantVersion = "utcp.executor-grant.v1"

var ErrDenied = errors.New("executor authorization denied")
var ErrStorage = errors.New("executor journal unavailable or corrupt")
var ErrUncertain = errors.New("dispatch may have been submitted; observation required")

// Grant contains safe correlation identifiers only. It is not a canonical claim
// or an operation allocator. Issuers may mint consumed grants only following a
// newly Granted canonical consumption, never a status response or cached claim.
type Grant struct {
	ContractVersion       string    `json:"contract_version"`
	Issuer                string    `json:"issuer"`
	KeyID                 string    `json:"key_id"`
	Audience              string    `json:"audience"`
	Executor              string    `json:"executor"`
	GrantID               string    `json:"grant_id"`
	JournalID             string    `json:"journal_id"`
	Endpoint              string    `json:"endpoint"`
	ServerName            string    `json:"server_name"`
	DispatchID            string    `json:"dispatch_id"`
	Phase                 string    `json:"phase"`
	IntentID              string    `json:"intent_id"`
	OperationID           string    `json:"operation_id"`
	Attempt               int       `json:"attempt"`
	TenantID              string    `json:"tenant_id"`
	RuntimeNodeID         string    `json:"runtime_node_id"`
	ClusterID             string    `json:"cluster_id"`
	Namespace             string    `json:"namespace"`
	Name                  string    `json:"name"`
	IncarnationID         string    `json:"incarnation_id"`
	ExpectedUID           string    `json:"expected_uid"`
	Generation            string    `json:"generation"`
	Fingerprint           string    `json:"fingerprint"`
	Action                string    `json:"action"`
	InfrastructureVersion string    `json:"infrastructure_version"`
	EnvelopeHash          string    `json:"envelope_hash"`
	BodyHash              string    `json:"body_hash"`
	IssuedAt              time.Time `json:"issued_at"`
	Deadline              time.Time `json:"deadline"`
}
type SignedGrant struct {
	Payload   Grant  `json:"payload"`
	Signature string `json:"signature"`
}
type GrantKey struct {
	Issuer, PeerSAN     string
	PublicKey           ed25519.PublicKey
	Revoked             bool
	NotBefore, NotAfter time.Time
}
type GrantVerifier struct {
	Keys               map[string]GrantKey
	Audience, Executor string
	Now                func() time.Time
}

func Hash(b []byte) string { d := sha256.Sum256(b); return hex.EncodeToString(d[:]) }
func grantBytes(g Grant) ([]byte, error) {
	g.IssuedAt = g.IssuedAt.UTC()
	g.Deadline = g.Deadline.UTC()
	return json.Marshal(g)
}
func SignGrant(g Grant, k ed25519.PrivateKey) (SignedGrant, error) {
	if len(k) != ed25519.PrivateKeySize {
		return SignedGrant{}, ErrDenied
	}
	b, e := grantBytes(g)
	if e != nil {
		return SignedGrant{}, e
	}
	return SignedGrant{g, base64.RawURLEncoding.EncodeToString(ed25519.Sign(k, b))}, nil
}
func (v GrantVerifier) Verify(raw []byte, peerSAN string) (Grant, error) {
	var s SignedGrant
	if len(raw) > 16384 || infrastructure.DecodeStrict(raw, &s) != nil {
		return Grant{}, ErrDenied
	}
	// DecodeStrict rejects unknown and duplicate keys; also reject absent/null keys.
	var fields map[string]json.RawMessage
	if json.Unmarshal(raw, &fields) != nil || len(fields) != 2 {
		return Grant{}, ErrDenied
	}
	var pf map[string]json.RawMessage
	_ = json.Unmarshal(fields["payload"], &pf)
	typ := reflect.TypeOf(s.Payload)
	for j := 0; j < typ.NumField(); j++ {
		x, ok := pf[typ.Field(j).Tag.Get("json")]
		if !ok || string(x) == "null" {
			return Grant{}, ErrDenied
		}
	}
	g := s.Payload
	now := time.Now()
	if v.Now != nil {
		now = v.Now()
	}
	k, ok := v.Keys[g.KeyID]
	if !ok || k.Revoked || k.Issuer != g.Issuer || peerSAN != k.PeerSAN || g.Audience != v.Audience || g.Executor != v.Executor || v.Executor == "" || v.Audience == "" || len(k.PublicKey) != ed25519.PublicKeySize || now.Before(k.NotBefore) || !now.Before(k.NotAfter) || g.IssuedAt.IsZero() || g.IssuedAt.After(now.Add(time.Second)) || !now.Before(g.Deadline) || g.Deadline.Sub(g.IssuedAt) > 5*time.Minute {
		return Grant{}, ErrDenied
	}
	if e := validateGrant(g); e != nil {
		return Grant{}, e
	}
	b, _ := grantBytes(g)
	sig, e := base64.RawURLEncoding.Strict().DecodeString(s.Signature)
	if e != nil || !ed25519.Verify(k.PublicKey, b, sig) {
		return Grant{}, ErrDenied
	}
	return g, nil
}
func validateGrant(g Grant) error {
	if g.ContractVersion != GrantVersion || (g.Phase != "reserved" && g.Phase != "consumed") || g.Attempt < 1 {
		return ErrDenied
	}
	if _, e := kernel.Generation(g.Generation); e != nil {
		return ErrDenied
	}
	for _, s := range []string{g.EnvelopeHash, g.BodyHash, g.Fingerprint} {
		b, e := hex.DecodeString(s)
		if e != nil || len(b) != 32 || strings.ToLower(s) != s {
			return ErrDenied
		}
	}
	for _, s := range []string{g.Issuer, g.KeyID, g.Audience, g.Executor, g.GrantID, g.JournalID, g.Endpoint, g.ServerName, g.DispatchID, g.IntentID, g.OperationID, g.TenantID, g.RuntimeNodeID, g.ClusterID, g.Namespace, g.Name, g.IncarnationID} {
		if s == "" || len(s) > 256 || strings.ContainsAny(s, "\x00\r\n") {
			return ErrDenied
		}
	}
	if len(g.ExpectedUID) > 256 || strings.ContainsAny(g.ExpectedUID, "\x00\r\n") {
		return ErrDenied
	}
	switch g.Action {
	case "admit_generation", "set_replicas", "create_suspended", "converge_projection", "retire_delete":
	default:
		return ErrDenied
	}
	switch g.InfrastructureVersion {
	case "utcp.infrastructure.v1", "utcp.infrastructure.v2", "utcp.infrastructure.v3":
	default:
		return ErrDenied
	}
	if g.InfrastructureVersion == "utcp.infrastructure.v1" && g.Action != "admit_generation" && g.Action != "set_replicas" || g.InfrastructureVersion == "utcp.infrastructure.v2" && g.Action != "create_suspended" && g.Action != "converge_projection" && g.Action != "retire_delete" || g.InfrastructureVersion == "utcp.infrastructure.v3" && g.Action != "converge_projection" {
		return ErrDenied
	}
	if g.Action != "create_suspended" && g.ExpectedUID == "" {
		return ErrDenied
	}
	u, e := url.Parse(g.Endpoint)
	if e != nil || u.Scheme != "https" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Host == "" {
		return ErrDenied
	}
	ip := net.ParseIP(u.Hostname())
	if ip == nil || !ip.IsLoopback() {
		return ErrDenied
	}
	if Hash(SyntheticBody(g)) != g.BodyHash {
		return ErrDenied
	}
	return nil
}

// SyntheticBody is deliberately fixed: it carries no workload template, lease,
// token, credentials, arbitrary URL, or real Kubernetes mutation body.
func SyntheticBody(g Grant) []byte {
	b, _ := json.Marshal(struct {
		DispatchID   string `json:"dispatch_id"`
		OperationID  string `json:"operation_id"`
		Attempt      int    `json:"attempt"`
		EnvelopeHash string `json:"envelope_hash"`
		Action       string `json:"action"`
	}{g.DispatchID, g.OperationID, g.Attempt, g.EnvelopeHash, g.Action})
	return b
}
