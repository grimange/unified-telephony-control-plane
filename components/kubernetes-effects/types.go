// Package effects implements bounded conditional effects. It owns no durable policy.
package effects

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	appsv1 "k8s.io/api/apps/v1"
	"strconv"
	"time"
)

const Version = "utcp.infrastructure.v1"
const prefix = "infrastructure.utcp.io/"
const AdmittedGeneration = prefix + "admitted-generation"
const AdmittedIntent = prefix + "admitted-intent"
const AppliedGeneration = prefix + "applied-generation"
const AppliedIntent = prefix + "applied-intent"

type Target struct {
	ClusterID       string `json:"cluster_id"`
	Namespace       string `json:"namespace"`
	Kind            string `json:"kind"`
	Name            string `json:"name"`
	TargetID        string `json:"target_id"`
	IncarnationID   string `json:"incarnation_id"`
	ExpectedUID     string `json:"expected_uid"`
	ExpectedRV      string `json:"expected_resource_version"`
	RuntimeNodeSlug string `json:"runtime_node_slug"`
}
type Claim struct {
	Attempt int       `json:"attempt"`
	Token   string    `json:"fencing_token"`
	Expires time.Time `json:"lease_expires_at"`
}
type Action struct {
	Version        string    `json:"contract_version"`
	RequestID      string    `json:"request_id"`
	OperationID    string    `json:"operation_id"`
	TenantID       string    `json:"tenant_id"`
	RuntimeNodeID  string    `json:"runtime_node_id"`
	Target         Target    `json:"target"`
	Generation     string    `json:"target_generation"`
	Fingerprint    string    `json:"intent_fingerprint"`
	Action         string    `json:"action"`
	Replicas       int32     `json:"replicas"`
	IdempotencyKey string    `json:"idempotency_key"`
	Claim          Claim     `json:"claim"`
	Deadline       time.Time `json:"deadline"`
	CorrelationID  string    `json:"correlation_id"`
	AuditID        string    `json:"audit_id"`
}
type Result struct {
	Version     string    `json:"contract_version"`
	RequestID   string    `json:"request_id"`
	OperationID string    `json:"operation_id"`
	Outcome     string    `json:"outcome"`
	Effect      string    `json:"effect"`
	Retry       string    `json:"retry"`
	Dispatch    string    `json:"dispatch"`
	UID         *string   `json:"target_uid"`
	RV          *string   `json:"resource_version"`
	Admitted    *string   `json:"admitted_generation"`
	Applied     *string   `json:"applied_generation"`
	Finished    time.Time `json:"finished_at"`
}

// Fingerprint uses byte lengths and fixed fields; generations never pass through floating point.
func Fingerprint(a Action) string {
	t := a.Target
	fields := []string{a.Version, a.TenantID, a.RuntimeNodeID, t.ClusterID, t.Namespace, t.Kind, t.Name, t.RuntimeNodeSlug, t.TargetID, t.IncarnationID, t.ExpectedUID, a.Generation, "set_replicas", strconv.FormatInt(int64(a.Replicas), 10)}
	h := sha256.New()
	for _, f := range fields {
		fmt.Fprintf(h, "%d:%s", len([]byte(f)), f)
	}
	return hex.EncodeToString(h.Sum(nil))
}

// Client deliberately exposes only exact Deployment reads and conditional patches.
type Client interface {
	Get(context.Context, Target) (*appsv1.Deployment, error)
	Patch(context.Context, Target, []byte) (*appsv1.Deployment, error)
}
type Verifier interface {
	Verify(context.Context, Action) error
}
type DecisionError struct{ Outcome string }

func (e *DecisionError) Error() string { return "claim verification denied" }
