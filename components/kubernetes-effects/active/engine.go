package active

import (
	"context"
	"encoding/json"
	"errors"
	appsv1 "k8s.io/api/apps/v1"
	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"

	"regexp"
	"strings"
	"sync"
	"time"
)

type Config struct {
	TrustedCluster    string
	AllowedNamespaces []string
	Client            Client
	ApprovedImages    map[string][]string
	ApprovedSecrets   map[string][]string
	Verifier          Verifier
	Now               func() time.Time
}
type Engine struct {
	c      Config
	mu     sync.Mutex
	active map[string]bool
}

func NewEngine(c Config) (*Engine, error) {
	if len(c.ApprovedImages) == 0 || len(c.ApprovedSecrets) == 0 || c.TrustedCluster == "" || len(c.AllowedNamespaces) == 0 || c.Client == nil || c.Verifier == nil {
		return nil, errors.New("invalid lifecycle configuration")
	}
	if c.Now == nil {
		c.Now = time.Now
	}
	// Freeze deployment trust inputs: caller mutation must not authorize a new
	// image or credential slot after the engine has been constructed.
	c.AllowedNamespaces = append([]string(nil), c.AllowedNamespaces...)
	images, secrets := map[string][]string{}, map[string][]string{}
	for k, v := range c.ApprovedImages {
		images[k] = append([]string(nil), v...)
	}
	for k, v := range c.ApprovedSecrets {
		secrets[k] = append([]string(nil), v...)
	}
	c.ApprovedImages, c.ApprovedSecrets = images, secrets
	return &Engine{c: c, active: map[string]bool{}}, nil
}
func identity(a Action, d *appsv1.Deployment) string {
	if d == nil || d.Name != a.Target.Name || d.Namespace != a.Target.Namespace || d.ResourceVersion == "" || d.UID == "" {
		return "identity_mismatch"
	}
	if a.Target.ExpectedUID != nil && string(d.UID) != *a.Target.ExpectedUID {
		return "identity_mismatch"
	}
	for k, v := range Ownership(a) {
		if d.Labels[k] != v {
			return "ownership_conflict"
		}
	}
	return ""
}

// normalizedTemplate removes only Kubernetes default values, never arbitrary
// fields. The complete controlled Pod template then has to match byte-for-byte.
func normalizedTemplate(t corev1.PodTemplateSpec) []byte {
	t = *t.DeepCopy()
	p := &t.Spec
	if p.RestartPolicy == corev1.RestartPolicyAlways {
		p.RestartPolicy = ""
	}
	if p.EnableServiceLinks != nil && *p.EnableServiceLinks {
		p.EnableServiceLinks = nil
	}
	for i := range p.Volumes {
		v := &p.Volumes[i]
		if v.ConfigMap != nil && v.ConfigMap.DefaultMode != nil && *v.ConfigMap.DefaultMode == 420 {
			v.ConfigMap.DefaultMode = nil
		}
	}
	if p.DNSPolicy == corev1.DNSClusterFirst {
		p.DNSPolicy = ""
	}
	if p.SchedulerName == "default-scheduler" {
		p.SchedulerName = ""
	}
	if p.TerminationGracePeriodSeconds != nil && *p.TerminationGracePeriodSeconds == 30 {
		p.TerminationGracePeriodSeconds = nil
	}
	if p.ServiceAccountName == "default" {
		p.ServiceAccountName = ""
	}
	if p.DeprecatedServiceAccount == "default" {
		p.DeprecatedServiceAccount = ""
	}
	for i := range p.Containers {
		c := &p.Containers[i]
		for _, probe := range []*corev1.Probe{c.StartupProbe, c.ReadinessProbe, c.LivenessProbe} {
			if probe != nil && probe.SuccessThreshold == 1 {
				probe.SuccessThreshold = 0
			}
		}
		if c.TerminationMessagePath == "/dev/termination-log" {
			c.TerminationMessagePath = ""
		}
		if c.TerminationMessagePolicy == corev1.TerminationMessageReadFile {
			c.TerminationMessagePolicy = ""
		}
	}
	b, _ := json.Marshal(t)
	return b
}
func projectionMatches(a Action, d *appsv1.Deployment) bool {
	if a.Intent.Projection == nil || d.Annotations[ProjectionMarker] != a.Intent.ProjectionFingerprint {
		return false
	}
	p := a.Intent.Projection
	if d.Annotations[Prefix+"configuration-id"] != p.ConfigurationID || d.Annotations[Prefix+"configuration-sha256"] != p.ConfigurationSHA256 {
		return false
	}
	expected := DesiredDeployment(a)
	return string(normalizedTemplate(d.Spec.Template)) == string(normalizedTemplate(expected.Spec.Template)) && !d.Spec.Paused && strategyMatches(d.Spec.Strategy, expected.Spec.Strategy)
}
func markerCheck(a Action, d *appsv1.Deployment) string {
	ag, pg := int64(0), int64(0)
	for i, keys := range [][2]string{{AdmittedGeneration, AdmittedIntent}, {AppliedGeneration, AppliedIntent}} {
		g, gok := d.Annotations[keys[0]]
		h, hok := d.Annotations[keys[1]]
		if gok != hok {
			return "ownership_conflict"
		}
		if !gok {
			continue
		}
		n, err := number(g)
		if err != nil || !regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(h) {
			return "ownership_conflict"
		}
		if i == 0 {
			ag = n
		} else {
			pg = n
		}
	}
	if pg > ag || pg != 0 && pg == ag && d.Annotations[AppliedIntent] != d.Annotations[AdmittedIntent] {
		return "ownership_conflict"
	}
	want, _ := number(a.Generation)
	if ag > want {
		return "stale_generation"
	}
	if ag != 0 && ag == want && d.Annotations[AdmittedIntent] != a.Fingerprint {
		return "ownership_conflict"
	}
	return ""
}
func (e *Engine) authorize(ctx context.Context, a Action) string {
	if !e.c.Now().Before(a.Claim.Expires) {
		return "stale_generation"
	}
	if ctx.Err() != nil {
		return readFailure(ctx.Err())
	}
	if err := e.c.Verifier.Verify(ctx, a); err != nil {
		var de *DecisionError
		if errors.As(err, &de) {
			switch de.Outcome {
			case "unauthorized", "forbidden", "stale_generation", "invalid_request", "terminal_failure", "timeout", "cancelled", "transport_unavailable":
				return de.Outcome
			}
		}
		return readFailure(err)
	}
	if ctx.Err() != nil {
		return readFailure(ctx.Err())
	}
	if !e.c.Now().Before(a.Claim.Expires) {
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
	case apierrors.IsNotFound(err):
		return "target_not_found"
	case apierrors.IsForbidden(err):
		return "forbidden"
	case apierrors.IsUnauthorized(err):
		return "unauthorized"
	case apierrors.IsConflict(err):
		return "resource_version_conflict"
	case apierrors.IsTooManyRequests(err) || apierrors.IsServiceUnavailable(err):
		return "retryable_failure"
	default:
		return "transport_unavailable"
	}
}
func mutationFailure(err error) string {
	switch {
	case apierrors.IsInvalid(err) || apierrors.IsBadRequest(err):
		return "invalid_request"
	case apierrors.IsNotFound(err):
		return "target_not_found"
	case apierrors.IsForbidden(err):
		return "forbidden"
	case apierrors.IsUnauthorized(err):
		return "unauthorized"
	case apierrors.IsConflict(err):
		return "resource_version_conflict"
	case apierrors.IsTooManyRequests(err):
		return "retryable_failure"
	default:
		return "outcome_unknown"
	}
}
func retry(code string) string {
	switch code {
	case "outcome_unknown":
		return "observe_first"
	case "drift", "resource_version_conflict", "stale_generation":
		return "reauthorize"
	case "timeout", "cancelled", "transport_unavailable", "retryable_failure", "busy":
		return "backoff"
	default:
		return "never"
	}
}
func (e *Engine) Execute(ctx context.Context, a Action) Result { return e.run(ctx, a, false) }

// Recover never writes, binds, advances lifecycle or authorizes a blind replay.
func (e *Engine) Recover(ctx context.Context, a Action) Result { return e.run(ctx, a, true) }
func (e *Engine) run(ctx context.Context, a Action, recoverOnly bool) (r Result) {
	r = Result{Version: Version, RequestID: a.RequestID, OperationID: a.OperationID, Outcome: "invalid_request", Effect: "none", Dispatch: "not_sent", Retry: "never", Finished: e.c.Now().UTC()}
	defer func() { r.Retry = retry(r.Outcome); r.Finished = e.c.Now().UTC() }()
	finish := func(s string) Result { r.Outcome = s; return r }
	record := func(d *appsv1.Deployment) {
		r.UID = String(string(d.UID))
		r.RV = String(d.ResourceVersion)
		r.Generation = String(a.Generation)
		if a.Intent.ProjectionFingerprint != "" {
			r.ProjectionFingerprint = String(a.Intent.ProjectionFingerprint)
		}
		if a.Intent.RetirementID != "" {
			r.RetirementID = String(a.Intent.RetirementID)
		}
		if d.DeletionTimestamp != nil {
			v := d.DeletionTimestamp.Time
			r.DeletionTimestamp = &v
		}
	}
	b, err := json.Marshal(a)
	if err != nil {
		return r
	}
	if _, err = Decode(b); err != nil {
		return r
	}
	if !contains(e.c.ApprovedImages[a.Intent.Projection.TemplateVersion], a.Intent.Projection.Image) || !contains(e.c.ApprovedSecrets[a.Target.Namespace], a.Intent.Projection.SecretName) {
		return finish("forbidden")
	}
	if a.Target.ClusterID != e.c.TrustedCluster {
		return finish("identity_mismatch")
	}
	allowed := false
	for _, ns := range e.c.AllowedNamespaces {
		if ns == a.Target.Namespace {
			allowed = true
		}
	}
	if !allowed {
		return finish("forbidden")
	}
	if !e.c.Now().Before(a.Deadline) {
		return finish("timeout")
	}
	deadline := time.Now().Add(5 * time.Second)
	if a.Deadline.Before(deadline) {
		deadline = a.Deadline
	}
	if a.Claim.Expires.Before(deadline) {
		deadline = a.Claim.Expires
	}
	ctx, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	key := a.Target.Namespace + "/" + a.Target.Name
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
	d, err := e.c.Client.Get(ctx, a.Target)
	if err != nil {
		return finish(readFailure(err))
	}
	if code := identity(a, d); code != "" {
		return finish(code)
	}
	record(d)
	if d.Annotations[RetirementMarker] != "" {
		return finish("stale_generation")
	}
	if code := markerCheck(a, d); code != "" {
		return finish(code)
	}
	if d.DeletionTimestamp != nil {
		return finish("ownership_conflict")
	}
	if a.Action == "converge_projection" && projectionMatches(a, d) && d.Annotations[AdmittedGeneration] == a.Generation && d.Annotations[AdmittedIntent] == a.Fingerprint && d.Annotations[AppliedGeneration] == a.Generation && d.Annotations[AppliedIntent] == a.Fingerprint && d.Spec.Replicas != nil && *d.Spec.Replicas == *a.Intent.Replicas {
		r.Effect = "projection_applied"
		r.Dispatch = "response_received"
		return finish("already_satisfied")
	}
	if recoverOnly {
		return finish("drift")
	}
	if d.ResourceVersion != *a.Target.ExpectedRV {
		return finish("resource_version_conflict")
	}
	// The generation barrier and the projection/effect markers share one parent
	// Deployment CAS; no /scale write or detached marker mutation is performed.
	patch, err := makePatch(a, d)
	if err != nil {
		return finish("invalid_request")
	}
	if code := e.authorize(ctx, a); code != "" {
		return finish(code)
	}
	updated, err := e.c.Client.Patch(ctx, a.Target, patch)
	if err != nil {
		return e.failed(r, err)
	}
	if identity(a, updated) != "" {
		r.Outcome = "outcome_unknown"
		r.Effect = "unknown"
		r.Dispatch = "unknown"
		return r
	}
	record(updated)
	if !projectionMatches(a, updated) || updated.Spec.Replicas == nil || *updated.Spec.Replicas != *a.Intent.Replicas || updated.Annotations[AppliedIntent] != a.Fingerprint || updated.Annotations[AppliedGeneration] != a.Generation {
		r.Outcome = "outcome_unknown"
		r.Effect = "unknown"
		r.Dispatch = "unknown"
		return r
	}
	r.Effect = "projection_applied"
	r.Dispatch = "response_received"
	return finish("success")
}

func (e *Engine) failed(r Result, err error) Result {
	r.Outcome = mutationFailure(err)
	r.Dispatch = "rejected"
	if r.Outcome == "outcome_unknown" {
		r.Dispatch = "unknown"
		r.Effect = "unknown"
	}
	return r
}

type patchOp struct {
	Op    string `json:"op"`
	Path  string `json:"path"`
	Value any    `json:"value"`
}

func path(k string) string {
	return "/metadata/annotations/" + strings.ReplaceAll(strings.ReplaceAll(k, "~", "~0"), "/", "~1")
}
func makePatch(a Action, d *appsv1.Deployment) ([]byte, error) {
	ops := []patchOp{{"test", "/metadata/uid", *a.Target.ExpectedUID}, {"test", "/metadata/resourceVersion", *a.Target.ExpectedRV}}
	if d.Annotations == nil {
		ops = append(ops, patchOp{"add", "/metadata/annotations", map[string]string{}})
	}
	if old := d.Annotations[AdmittedGeneration]; old != "" {
		ops = append(ops, patchOp{"test", path(AdmittedGeneration), old}, patchOp{"test", path(AdmittedIntent), d.Annotations[AdmittedIntent]})
	}
	ops = append(ops, patchOp{"add", path(AdmittedGeneration), a.Generation}, patchOp{"add", path(AdmittedIntent), a.Fingerprint})
	want := DesiredDeployment(a)
	p := a.Intent.Projection
	ops = append(ops, patchOp{"add", "/metadata/labels/app.kubernetes.io~1component", want.Labels["app.kubernetes.io/component"]}, patchOp{"add", "/spec/template", want.Spec.Template}, patchOp{"add", "/spec/strategy", want.Spec.Strategy}, patchOp{"add", "/spec/paused", false}, patchOp{"add", "/spec/replicas", *a.Intent.Replicas}, patchOp{"add", path(ProjectionMarker), a.Intent.ProjectionFingerprint}, patchOp{"add", path(Prefix + "configuration-id"), p.ConfigurationID}, patchOp{"add", path(Prefix + "configuration-sha256"), p.ConfigurationSHA256}, patchOp{"add", path(AppliedGeneration), a.Generation}, patchOp{"add", path(AppliedIntent), a.Fingerprint})

	return json.Marshal(ops)
}

func contains(values []string, want string) bool {
	for _, v := range values {
		if v == want {
			return true
		}
	}
	return false
}
func strategyMatches(got, want appsv1.DeploymentStrategy) bool {
	b, _ := json.Marshal(got)
	c, _ := json.Marshal(want)
	return string(b) == string(c)
}
