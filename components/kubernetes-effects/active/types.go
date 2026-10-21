// Package lifecycle implements isolated bounded Deployment lifecycle effects.
// It has no durable state, tenant policy, generation allocator or background loop.
package active

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	appsv1 "k8s.io/api/apps/v1"
	"strconv"
	"time"
	lifecycle "utcp.invalid/components/kubernetes-effects/lifecycle"
)

const Version = "utcp.infrastructure.v3"
const Prefix = "infrastructure.utcp.io/"
const AdmittedGeneration = Prefix + "admitted-generation"
const AdmittedIntent = Prefix + "admitted-intent"
const AppliedGeneration = Prefix + "applied-generation"
const AppliedIntent = Prefix + "applied-intent"
const ProjectionMarker = Prefix + "projection"
const RetirementMarker = Prefix + "retirement"

// PauseImage is immutable. The restricted profile has no configurable command,
// arguments, init/sidecars, credentials, hooks, ports, host access or references.
const PauseImage = "registry.k8s.io/pause@sha256:ee6521f290b2168b6e0935a181d4cff9be1ac3f505666ef0e3c98fae8199917a"

type Target = lifecycle.Target
type Projection struct {
	SecretName          string `json:"secret_name"`
	TemplateVersion     string `json:"template_version"`
	Image               string `json:"image"`
	ConfigurationID     string `json:"configuration_id"`
	ConfigurationSHA256 string `json:"configuration_sha256"`
}
type Intent struct {
	Kind                  string      `json:"kind"`
	Projection            *Projection `json:"projection,omitempty"`
	ProjectionFingerprint string      `json:"projection_fingerprint,omitempty"`
	Replicas              *int32      `json:"replicas,omitempty"`
	RetirementID          string      `json:"retirement_id,omitempty"`
	RetirementGeneration  string      `json:"retirement_generation,omitempty"`
	RetirementFingerprint string      `json:"retirement_fingerprint,omitempty"`
}
type Claim struct {
	Attempt int       `json:"attempt"`
	Token   string    `json:"fencing_token"`
	Expires time.Time `json:"lease_expires_at"`
}
type Action struct {
	Version        string    `json:"contract_version"`
	CanonicalState string    `json:"canonical_state"`
	RequestID      string    `json:"request_id"`
	OperationID    string    `json:"operation_id"`
	TenantID       string    `json:"tenant_id"`
	RuntimeNodeID  string    `json:"runtime_node_id"`
	Target         Target    `json:"target"`
	Generation     string    `json:"target_generation"`
	Fingerprint    string    `json:"intent_fingerprint"`
	Action         string    `json:"action"`
	Intent         Intent    `json:"intent"`
	IdempotencyKey string    `json:"idempotency_key"`
	Claim          Claim     `json:"claim"`
	Deadline       time.Time `json:"deadline"`
	CorrelationID  string    `json:"correlation_id"`
	AuditID        string    `json:"audit_id"`
}
type Result struct {
	Version               string     `json:"contract_version"`
	RequestID             string     `json:"request_id"`
	OperationID           string     `json:"operation_id"`
	Outcome               string     `json:"outcome"`
	Effect                string     `json:"effect"`
	Dispatch              string     `json:"dispatch"`
	Retry                 string     `json:"retry"`
	UID                   *string    `json:"target_uid"`
	RV                    *string    `json:"resource_version"`
	Generation            *string    `json:"target_generation"`
	ProjectionFingerprint *string    `json:"projection_fingerprint"`
	RetirementID          *string    `json:"retirement_id"`
	DeletionTimestamp     *time.Time `json:"deletion_timestamp"`
	Finished              time.Time  `json:"finished_at"`
}

func digest(fields ...string) string {
	h := sha256.New()
	for _, s := range fields {
		fmt.Fprintf(h, "%d:%s", len([]byte(s)), s)
	}
	return hex.EncodeToString(h.Sum(nil))
}
func ProjectionFingerprint(p Projection) string {
	return digest(p.TemplateVersion, p.Image, p.ConfigurationID, p.ConfigurationSHA256, p.SecretName)
}
func Fingerprint(a Action) string {
	t := a.Target
	uid := ""
	if t.ExpectedUID != nil {
		uid = *t.ExpectedUID
	}
	rep := ""
	if a.Intent.Replicas != nil {
		rep = strconv.FormatInt(int64(*a.Intent.Replicas), 10)
	}
	return digest(a.Version, a.TenantID, a.RuntimeNodeID, t.ClusterID, t.Namespace, t.Kind, t.Name, t.RuntimeNodeSlug, t.TargetID, t.IncarnationID, uid, a.Generation, a.Intent.Kind, a.Intent.ProjectionFingerprint, rep, a.Intent.RetirementID, a.Intent.RetirementGeneration, a.Intent.RetirementFingerprint, a.CanonicalState)
}

type Client interface {
	Get(context.Context, lifecycle.Target) (*appsv1.Deployment, error)
	Patch(context.Context, lifecycle.Target, []byte) (*appsv1.Deployment, error)
}
type Verifier interface {
	Verify(context.Context, Action) error
}
type DecisionError struct{ Outcome string }

func (e *DecisionError) Error() string { return "lifecycle claim denied" }
func String(s string) *string          { return &s }
func Replicas(n int32) *int32          { return &n }
