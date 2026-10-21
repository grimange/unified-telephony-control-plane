package effects

import (
	"context"
	"encoding/json"
	"errors"
	appsv1 "k8s.io/api/apps/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode/utf8"
)

type Config struct {
	TrustedCluster    string
	AllowedNamespaces []string
	Client            Client
	Verifier          Verifier
	Validator         *Validator
	Now               func() time.Time
}
type Engine struct {
	config Config
	mu     sync.Mutex
	active map[string]bool
}

func NewEngine(c Config) (*Engine, error) {
	if c.TrustedCluster == "" || len(c.AllowedNamespaces) == 0 || c.Client == nil || c.Verifier == nil || c.Validator == nil {
		return nil, errors.New("invalid effect configuration")
	}
	if c.Now == nil {
		c.Now = time.Now
	}
	return &Engine{config: c, active: map[string]bool{}}, nil
}
func ptr(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

var generationPattern = regexp.MustCompile(`^[1-9][0-9]{0,18}$`)
var hashPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

func generation(s string) (int64, error) {
	if !generationPattern.MatchString(s) {
		return 0, errors.New("invalid generation")
	}
	return strconv.ParseInt(s, 10, 64)
}
func ownership(a Action) map[string]string {
	return map[string]string{"app.kubernetes.io/part-of": "utcp", "app.kubernetes.io/instance": a.Target.Name, "utcp.dev/runtime-node": a.Target.RuntimeNodeSlug, "utcp.io/runtime-node-id": a.RuntimeNodeID, "utcp.io/tenant-id": a.TenantID, prefix + "target-id": a.Target.TargetID, prefix + "incarnation-id": a.Target.IncarnationID}
}
func identity(a Action, d *appsv1.Deployment) string {
	if d == nil || d.Name != a.Target.Name || d.Namespace != a.Target.Namespace || string(d.UID) != a.Target.ExpectedUID || (d.ResourceVersion == "" || len(d.ResourceVersion) > 256 || strings.ContainsAny(d.ResourceVersion, "\r\n") || !utf8.ValidString(d.ResourceVersion)) {
		return "identity_mismatch"
	}
	for k, v := range ownership(a) {
		if d.Labels[k] != v {
			return "ownership_conflict"
		}
	}
	if d.DeletionTimestamp != nil {
		return "ownership_conflict"
	}
	return ""
}
func markers(d *appsv1.Deployment) error {
	var ag, pg int64
	for i, pair := range [][2]string{{AdmittedGeneration, AdmittedIntent}, {AppliedGeneration, AppliedIntent}} {
		g, gok := d.Annotations[pair[0]]
		h, hok := d.Annotations[pair[1]]
		if gok != hok {
			return errors.New("incomplete markers")
		}
		if !gok {
			continue
		}
		n, e := generation(g)
		if e != nil || !hashPattern.MatchString(h) {
			return errors.New("invalid markers")
		}
		if i == 0 {
			ag = n
		} else {
			pg = n
		}
	}
	if pg > ag {
		return errors.New("applied ahead of admission")
	}
	if pg > 0 && pg == ag && d.Annotations[AppliedIntent] != d.Annotations[AdmittedIntent] {
		return errors.New("conflicting markers")
	}
	return nil
}
func barrier(a Action, d *appsv1.Deployment) string {
	if markers(d) != nil {
		return "ownership_conflict"
	}
	g := d.Annotations[AdmittedGeneration]
	if g == "" {
		if a.Action == "set_replicas" {
			return "stale_generation"
		}
		return ""
	}
	current, _ := generation(g)
	want, _ := generation(a.Generation)
	if current > want {
		return "stale_generation"
	}
	if current == want && d.Annotations[AdmittedIntent] != a.Fingerprint {
		return "ownership_conflict"
	}
	if a.Action == "set_replicas" && current != want {
		return "stale_generation"
	}
	return ""
}
func satisfied(a Action, d *appsv1.Deployment) bool {
	if d.Annotations[AdmittedGeneration] != a.Generation || d.Annotations[AdmittedIntent] != a.Fingerprint {
		return false
	}
	if a.Action == "admit_generation" {
		return true
	}
	return d.Annotations[AppliedGeneration] == a.Generation && d.Annotations[AppliedIntent] == a.Fingerprint && d.Spec.Replicas != nil && *d.Spec.Replicas == a.Replicas
}
func (e *Engine) authorize(ctx context.Context, a Action) string {
	if err := ctx.Err(); err != nil {
		return readFailure(err)
	}
	if !e.config.Now().Before(a.Claim.Expires) {
		return "stale_generation"
	}
	if err := e.config.Verifier.Verify(ctx, a); err != nil {
		var d *DecisionError
		if errors.As(err, &d) {
			switch d.Outcome {
			case "unauthorized", "forbidden", "stale_generation", "invalid_request", "terminal_failure", "transport_unavailable", "timeout", "cancelled":
				return d.Outcome
			}
		}
		if ctx.Err() != nil {
			return readFailure(ctx.Err())
		}
		return "transport_unavailable"
	}
	if err := ctx.Err(); err != nil {
		return readFailure(err)
	}
	if !e.config.Now().Before(a.Claim.Expires) {
		return "stale_generation"
	}
	return ""
}
func readFailure(err error) string {
	switch {
	case errors.Is(err, context.Canceled):
		return "cancelled"
	case errors.Is(err, context.DeadlineExceeded):
		return "timeout"
	case apierrors.IsUnauthorized(err):
		return "unauthorized"
	case apierrors.IsForbidden(err):
		return "forbidden"
	case apierrors.IsNotFound(err):
		return "target_not_found"
	case apierrors.IsTooManyRequests(err) || apierrors.IsServiceUnavailable(err) || apierrors.IsInternalError(err):
		return "retryable_failure"
	default:
		return "transport_unavailable"
	}
}
func retryFor(code string) string {
	switch code {
	case "resource_version_conflict", "stale_generation", "drift":
		return "reauthorize"
	case "timeout", "cancelled", "transport_unavailable", "retryable_failure", "busy":
		return "backoff"
	case "outcome_unknown":
		return "observe_first"
	default:
		return "never"
	}
}
func (e *Engine) Execute(ctx context.Context, a Action) Result { return e.run(ctx, a, false) }

// Recover observes first and never dispatches a mutation. Laravel decides if a fresh conditional retry is authorized.
func (e *Engine) Recover(ctx context.Context, a Action) Result { return e.run(ctx, a, true) }
func (e *Engine) run(ctx context.Context, a Action, recoverOnly bool) (r Result) {
	r = Result{Version: Version, RequestID: a.RequestID, OperationID: a.OperationID, Outcome: "invalid_request", Effect: "none", Retry: "never", Dispatch: "not_sent", Finished: e.config.Now().UTC()}
	defer func() {
		r.Finished = e.config.Now().UTC()
		r.Retry = retryFor(r.Outcome)
		if e.config.Validator.ValidateResult(r) != nil {
			r.UID = nil
			r.RV = nil
			r.Admitted = nil
			r.Applied = nil
			if r.Dispatch == "unknown" {
				r.Outcome = "outcome_unknown"
				r.Effect = "unknown"
				r.Retry = "observe_first"
			} else {
				r.Outcome = "terminal_failure"
				r.Effect = "none"
				r.Retry = "never"
			}
		}
	}()
	finish := func(code string) Result { r.Outcome = code; return r }
	b, err := json.Marshal(a)
	if err != nil || e.config.Validator.ValidateAction(b) != nil || a.Fingerprint != Fingerprint(a) {
		return finish("invalid_request")
	}
	if a.Target.ClusterID != e.config.TrustedCluster {
		return finish("identity_mismatch")
	}
	allowed := false
	for _, ns := range e.config.AllowedNamespaces {
		if ns == a.Target.Namespace {
			allowed = true
		}
	}
	if !allowed {
		return finish("forbidden")
	}
	now := e.config.Now()
	if !now.Before(a.Deadline) {
		return finish("timeout")
	}
	deadline := time.Now().Add(5 * time.Second)
	if a.Deadline.Before(deadline) {
		deadline = a.Deadline
	}
	ctx, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	key := a.Target.ClusterID + "/" + a.Target.Namespace + "/" + a.Target.Name
	e.mu.Lock()
	if e.active[key] || len(e.active) >= 4 {
		e.mu.Unlock()
		return finish("busy")
	}
	e.active[key] = true
	e.mu.Unlock()
	defer func() { e.mu.Lock(); delete(e.active, key); e.mu.Unlock() }()
	if code := e.authorize(ctx, a); code != "" {
		return finish(code)
	}
	d, err := e.config.Client.Get(ctx, a.Target)
	if err != nil {
		return finish(readFailure(err))
	}
	record := func(d *appsv1.Deployment) {
		r.UID = ptr(string(d.UID))
		r.RV = ptr(d.ResourceVersion)
		if markers(d) == nil {
			r.Admitted = ptr(d.Annotations[AdmittedGeneration])
			r.Applied = ptr(d.Annotations[AppliedGeneration])
		}
	}
	if code := identity(a, d); code != "" {
		return finish(code)
	}
	record(d)
	if code := barrier(a, d); code != "" {
		return finish(code)
	}
	if satisfied(a, d) {
		if code := e.authorize(ctx, a); code != "" {
			return finish(code)
		}
		r.Dispatch = "response_received"
		r.Effect = "observed_satisfied"
		return finish("already_satisfied")
	}
	if recoverOnly {
		if d.ResourceVersion != a.Target.ExpectedRV {
			return finish("resource_version_conflict")
		}
		return finish("drift")
	}
	if d.ResourceVersion != a.Target.ExpectedRV {
		return finish("resource_version_conflict")
	}
	patch, err := makePatch(a, d)
	if err != nil {
		return finish("invalid_request")
	}
	mutationCtx, mutationCancel := context.WithDeadline(ctx, a.Claim.Expires)
	defer mutationCancel()
	if preparer, ok := e.config.Client.(interface {
		Prepare(context.Context, Target) (context.Context, error)
	}); ok {
		prepared, prepareErr := preparer.Prepare(mutationCtx, a.Target)
		if prepareErr != nil {
			if !e.config.Now().Before(a.Claim.Expires) {
				return finish("stale_generation")
			}
			return finish(readFailure(prepareErr))
		}
		mutationCtx = prepared
	}
	if code := e.authorize(mutationCtx, a); code != "" {
		return finish(code)
	}
	updated, err := e.config.Client.Patch(mutationCtx, a.Target, patch)
	if err != nil {
		r.Dispatch = "rejected"
		switch {
		case apierrors.IsUnauthorized(err):
			return finish("unauthorized")
		case apierrors.IsForbidden(err):
			return finish("forbidden")
		case apierrors.IsNotFound(err):
			return finish("target_not_found")
		case apierrors.IsConflict(err):
			return finish("resource_version_conflict")
		case apierrors.IsInvalid(err) || apierrors.IsBadRequest(err):
			// A later observation cannot prove why a generic 422 was rejected.
			// Until a real server supplies a verified predicate-failure Status,
			// retain invalid_request rather than manufacturing retry permission.
			return finish("invalid_request")
		case apierrors.IsTooManyRequests(err):
			return finish("retryable_failure")
		default:
			r.Dispatch = "unknown"
			r.Effect = "unknown"
			return finish("outcome_unknown")
		}
	}
	// Even a 2xx response must prove exact identity, barrier and effect. A malformed response cannot prove application.
	if identity(a, updated) != "" || barrier(a, updated) != "" || !satisfied(a, updated) {
		r.Dispatch = "unknown"
		r.Effect = "unknown"
		return finish("outcome_unknown")
	}
	record(updated)
	r.Dispatch = "response_received"
	r.Effect = "admitted"
	if a.Action == "set_replicas" {
		r.Effect = "applied"
	}
	return finish("success")
}

type patchOp struct {
	Op    string `json:"op"`
	Path  string `json:"path"`
	Value any    `json:"value"`
}

func annotationPath(k string) string {
	return "/metadata/annotations/" + regexp.MustCompile(`/`).ReplaceAllString(k, "~1")
}
func makePatch(a Action, d *appsv1.Deployment) ([]byte, error) {
	ops := []patchOp{{"test", "/metadata/uid", a.Target.ExpectedUID}, {"test", "/metadata/resourceVersion", a.Target.ExpectedRV}}
	if a.Action == "admit_generation" {
		if d.Annotations == nil {
			ops = append(ops, patchOp{"add", "/metadata/annotations", map[string]string{}})
		}
		if g := d.Annotations[AdmittedGeneration]; g != "" {
			ops = append(ops, patchOp{"test", annotationPath(AdmittedGeneration), g}, patchOp{"test", annotationPath(AdmittedIntent), d.Annotations[AdmittedIntent]})
		}
		ops = append(ops, patchOp{"add", annotationPath(AdmittedGeneration), a.Generation}, patchOp{"add", annotationPath(AdmittedIntent), a.Fingerprint})
	} else {
		ops = append(ops, patchOp{"test", annotationPath(AdmittedGeneration), a.Generation}, patchOp{"test", annotationPath(AdmittedIntent), a.Fingerprint}, patchOp{"add", "/spec/replicas", a.Replicas}, patchOp{"add", annotationPath(AppliedGeneration), a.Generation}, patchOp{"add", annotationPath(AppliedIntent), a.Fingerprint})
	}
	return json.Marshal(ops)
}
