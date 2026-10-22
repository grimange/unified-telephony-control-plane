// Package kernel owns PostgreSQL canonical authority, never infrastructure execution.
package kernel

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"github.com/jackc/pgx/v5/pgxpool"
	"regexp"
	"strconv"
	"time"
)

var ErrDenied = errors.New("canonical authority denied")
var ErrConflict = errors.New("canonical conflict; observe first")
var ErrOverflow = errors.New("generation overflow")

const MaxGeneration = "9223372036854775807"

var dns = regexp.MustCompile(`^[a-z0-9]([-a-z0-9]*[a-z0-9])?$`)
var identifier = regexp.MustCompile(`^[a-z0-9][a-z0-9_.:-]{0,127}$`)
var pinnedImage = regexp.MustCompile(`^[a-z0-9][a-z0-9.:-]*/[a-z0-9][a-z0-9._/-]*@sha256:[0-9a-f]{64}$`)
var hex64 = regexp.MustCompile(`^[a-f0-9]{64}$`)

func ID() string {
	var b [16]byte
	if _, e := rand.Read(b[:]); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b[:])
}
func UUID() string {
	s := ID()
	return s[:8] + "-" + s[8:12] + "-" + s[12:16] + "-" + s[16:20] + "-" + s[20:]
}
func Hash(b []byte) string { h := sha256.Sum256(b); return hex.EncodeToString(h[:]) }
func Generation(s string) (int64, error) {
	n, e := strconv.ParseInt(s, 10, 64)
	if e != nil || n < 1 || strconv.FormatInt(n, 10) != s {
		return 0, ErrDenied
	}
	return n, nil
}
func Increment(s string) (string, error) {
	n, e := Generation(s)
	if e != nil {
		return "", e
	}
	if s == MaxGeneration {
		return "", ErrOverflow
	}
	return strconv.FormatInt(n+1, 10), nil
}

// EnvelopeValidator must implement closed v1/v2/v3 schema AND fingerprint validation.
type EnvelopeValidator interface{ Validate([]byte) error }
type ValidatorFunc func([]byte) error

func (f ValidatorFunc) Validate(b []byte) error { return f(b) }

type Service struct {
	Pool      *pgxpool.Pool
	validator EnvelopeValidator
	policy    DomainPolicy
}

func New(pool *pgxpool.Pool, v EnvelopeValidator) *Service { return &Service{Pool: pool, validator: v} }

type PrepareRequest struct {
	TenantID, RuntimeNodeID, ClusterID, Namespace, Profile, IdempotencyKey string
	Configuration                                                          map[string]string
}
type Incarnation struct {
	ID, TenantID, RuntimeNodeID, ClusterID, Namespace, Name, Slug, TargetID, State, Generation, Profile string
	UID                                                                                                 *string
	Configuration                                                                                       map[string]string
}
type Envelope struct {
	Version        string    `json:"contract_version"`
	OperationID    string    `json:"operation_id"`
	TenantID       string    `json:"tenant_id"`
	RuntimeNodeID  string    `json:"runtime_node_id"`
	Generation     string    `json:"target_generation"`
	Fingerprint    string    `json:"intent_fingerprint"`
	Action         string    `json:"action"`
	CanonicalState string    `json:"canonical_state"`
	Replicas       int       `json:"replicas"`
	Deadline       time.Time `json:"deadline"`
	Target         struct {
		ClusterID     string  `json:"cluster_id"`
		Namespace     string  `json:"namespace"`
		Name          string  `json:"name"`
		IncarnationID string  `json:"incarnation_id"`
		TargetID      string  `json:"target_id"`
		Slug          string  `json:"runtime_node_slug"`
		UID           *string `json:"expected_uid"`
		RV            *string `json:"expected_resource_version"`
	} `json:"target"`
	Claim struct {
		Attempt int       `json:"attempt"`
		Token   string    `json:"fencing_token"`
		Expires time.Time `json:"lease_expires_at"`
	} `json:"claim"`
	Intent struct {
		Replicas              int               `json:"replicas"`
		Projection            map[string]string `json:"projection"`
		ProjectionFingerprint string            `json:"projection_fingerprint"`
	} `json:"intent"`
}

func (s *Service) parse(b []byte) (Envelope, []byte, error) {
	var a Envelope
	if s.validator == nil {
		return a, nil, ErrDenied
	}
	if e := s.validator.Validate(b); e != nil {
		return a, nil, ErrDenied
	}
	if e := json.Unmarshal(b, &a); e != nil {
		return a, nil, ErrDenied
	}
	if _, e := Generation(a.Generation); e != nil {
		return a, nil, e
	}
	var v map[string]any
	d := json.Unmarshal(b, &v)
	if d != nil {
		return a, nil, ErrDenied
	}
	delete(v, "claim")
	durable, e := json.Marshal(v)
	return a, durable, e
}

type Intent struct {
	ID, IncarnationID, OperationID, Generation, Action, Fingerprint, Outcome string
	Superseded                                                               bool
	Envelope                                                                 json.RawMessage
}
type ReserveRequest struct {
	Envelope   json.RawMessage
	Executor   string
	Capability string
	Recovery   bool
	BlockedIDs []string
}
type Reservation struct {
	ID, State, EnvelopeHash, Executor, IntentID, IncarnationID, Generation string
	Recovery                                                               bool
	Granted                                                                bool
	Expires                                                                time.Time
}
type ConsumeRequest struct {
	ID, Executor, Capability string
	Envelope                 json.RawMessage
}
type ChallengeRequest struct{ TenantID, IncarnationID, IntentID, DispatchID, Purpose, Audience, EffectDigest string }
type ReceiptChallenge struct {
	ID, Nonce, TenantID, RuntimeNodeID, IncarnationID, ClusterID, Namespace, Name, ExpectedUID, Purpose, Audience, IntentID, DispatchID, EnvelopeHash, Generation, Fingerprint, EffectDigest, Action, ProjectionDigest string
	ExpiresAt, IssuedAt                                                                                                                                                                                                time.Time
}
type ReceiptEvidence struct {
	ReceiptID, Issuer, ChallengeID, TenantID, RuntimeNodeID, IncarnationID, ClusterID, Namespace, Name, ExpectedUID, ObservedUID, ResourceVersion, Purpose, IntentID, DispatchID, EnvelopeHash, Generation, Fingerprint, EffectDigest, Outcome, ObservedTenantID, ObservedRuntimeNodeID, ObservedIncarnationID, AdmittedGeneration, AdmittedFingerprint, AppliedGeneration, AppliedFingerprint, ObservedProjectionDigest string
	Replicas                                                                                                                                                                                                                                                                                                                                                                                                             *int
	Complete                                                                                                                                                                                                                                                                                                                                                                                                             bool
	StartedAt, FinishedAt, ExpiresAt                                                                                                                                                                                                                                                                                                                                                                                     time.Time
	SafeEnvelope                                                                                                                                                                                                                                                                                                                                                                                                         json.RawMessage
}
type ReceiptVerifier interface {
	Verify(context.Context, []byte, ReceiptChallenge) (ReceiptEvidence, error)
}

func canonical(b []byte) string {
	var v any
	if json.Unmarshal(b, &v) != nil {
		return ""
	}
	x, _ := json.Marshal(v)
	return string(x)
}
func same(a, b *string) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return *a == *b
}
func denyIf(ok bool) error {
	if !ok {
		return ErrDenied
	}
	return nil
}
func wrap(e error) error {
	if e == nil {
		return nil
	}
	return fmt.Errorf("canonical storage: %w", e)
}
