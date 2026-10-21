package lifecycle

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	jsonpatch "gopkg.in/evanphx/json-patch.v4"
	appsv1 "k8s.io/api/apps/v1"
	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime/schema"
	"k8s.io/apimachinery/pkg/types"
	"strings"
	"sync"
	"testing"
	"time"
)

type verifierFunc func(context.Context, Action) error

func (f verifierFunc) Verify(ctx context.Context, a Action) error { return f(ctx, a) }

type memoryClient struct {
	mu     sync.Mutex
	d      *appsv1.Deployment
	rv     int
	writes int
	fail   string
	lose   bool
	before func(string)
}

func (m *memoryClient) Get(ctx context.Context, t Target) (*appsv1.Deployment, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.d == nil {
		return nil, apierrors.NewNotFound(schema.GroupResource{Group: "apps", Resource: "deployments"}, t.Name)
	}
	return m.d.DeepCopy(), nil
}
func (m *memoryClient) Create(ctx context.Context, t Target, d *appsv1.Deployment) (*appsv1.Deployment, error) {
	if m.before != nil {
		m.before("create")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.fail == "create" {
		return nil, errors.New("sensitive transport token must never leak")
	}
	if m.d != nil {
		return nil, apierrors.NewAlreadyExists(schema.GroupResource{Resource: "deployments"}, t.Name)
	}
	m.rv++
	m.writes++
	m.d = d.DeepCopy()
	m.d.UID = "uid-a"
	m.d.ResourceVersion = fmt.Sprint(m.rv)
	if m.lose {
		return nil, errors.New("lost")
	}
	return m.d.DeepCopy(), nil
}
func (m *memoryClient) Patch(ctx context.Context, t Target, b []byte) (*appsv1.Deployment, error) {
	if m.before != nil {
		m.before("patch")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.fail == "patch" {
		return nil, errors.New("lost")
	}
	if m.d == nil {
		return nil, apierrors.NewNotFound(schema.GroupResource{Resource: "deployments"}, t.Name)
	}
	patch, e := jsonpatch.DecodePatch(b)
	if e != nil {
		return nil, e
	}
	raw, _ := json.Marshal(m.d)
	out, e := patch.Apply(raw)
	if e != nil {
		return nil, apierrors.NewConflict(schema.GroupResource{Resource: "deployments"}, t.Name, errors.New("CAS"))
	}
	d := &appsv1.Deployment{}
	json.Unmarshal(out, d)
	m.rv++
	m.writes++
	d.ResourceVersion = fmt.Sprint(m.rv)
	m.d = d
	if m.lose {
		return nil, errors.New("lost")
	}
	return d.DeepCopy(), nil
}
func (m *memoryClient) Delete(ctx context.Context, t Target, o metav1.DeleteOptions) error {
	if m.before != nil {
		m.before("delete")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.fail == "delete" {
		return errors.New("lost")
	}
	if m.d == nil {
		return apierrors.NewNotFound(schema.GroupResource{Resource: "deployments"}, t.Name)
	}
	if string(*o.Preconditions.UID) != string(m.d.UID) || *o.Preconditions.ResourceVersion != m.d.ResourceVersion {
		return apierrors.NewConflict(schema.GroupResource{Resource: "deployments"}, t.Name, errors.New("preconditions"))
	}
	m.writes++
	if len(m.d.Finalizers) > 0 {
		now := metav1.Now()
		m.d.DeletionTimestamp = &now
	} else {
		m.d = nil
	}
	if m.lose {
		return errors.New("lost")
	}
	return nil
}
func sample() Action {
	now := time.Now().UTC()
	p := Projection{"inert.pause.v1", PauseImage, "config-1", strings.Repeat("a", 64)}
	a := Action{Version: Version, RequestID: "request-1", OperationID: "operation-1", TenantID: "tenant-1", RuntimeNodeID: "runtime-1", Target: Target{ClusterID: "test-cluster", Namespace: "test-ns", Kind: "Deployment", Name: "utcp-" + strings.Repeat("a", 32), RuntimeNodeSlug: "runtime-1", TargetID: "target-1", IncarnationID: strings.Repeat("a", 32)}, Generation: "10", Action: "create_suspended", Intent: Intent{Kind: "create_suspended", Projection: &p, ProjectionFingerprint: ProjectionFingerprint(p)}, IdempotencyKey: "idem-1", Claim: Claim{1, strings.Repeat("b", 32), now.Add(time.Minute)}, Deadline: now.Add(time.Minute), CorrelationID: "correlation-1", AuditID: "audit-1"}
	a.Fingerprint = Fingerprint(a)
	return a
}
func testEngine(t *testing.T, m *memoryClient, v Verifier) *Engine {
	t.Helper()
	if v == nil {
		v = verifierFunc(func(context.Context, Action) error { return nil })
	}
	e, err := NewEngine(Config{TrustedCluster: "test-cluster", AllowedNamespaces: []string{"test-ns"}, Client: m, Verifier: v})
	if err != nil {
		t.Fatal(err)
	}
	return e
}
func bound(a Action, m *memoryClient, kind string) Action {
	a.Target.ExpectedUID = String(string(m.d.UID))
	a.Target.ExpectedRV = String(m.d.ResourceVersion)
	a.Action = kind
	a.Intent.Kind = kind
	if kind == "converge_projection" {
		a.Intent.Replicas = Replicas(0)
	}
	if kind == "retire_delete" {
		a.Intent = Intent{Kind: kind, RetirementID: "retirement-1", RetirementGeneration: a.Generation, RetirementFingerprint: strings.Repeat("c", 64)}
	}
	a.Generation = "11"
	if kind == "retire_delete" {
		a.Intent.RetirementGeneration = a.Generation
	}
	a.Fingerprint = Fingerprint(a)
	return a
}
func TestLifecycleContracts(t *testing.T) {
	a := sample()
	b, _ := json.Marshal(a)
	if _, e := Decode(b); e != nil {
		t.Fatal(e)
	}
	cases := map[string][]byte{"unknown": append(b[:len(b)-1], []byte(`,"extra":true}`)...), "duplicate": append(b[:len(b)-1], []byte(`,"action":"create_suspended"}`)...), "version": []byte(strings.Replace(string(b), Version, "utcp.infrastructure.v9", 1)), "numeric": []byte(strings.Replace(string(b), `"target_generation":"10"`, `"target_generation":10`, 1)), "overflow": []byte(strings.Replace(string(b), `"target_generation":"10"`, `"target_generation":"9223372036854775808"`, 1)), "fingerprint": []byte(strings.Replace(string(b), a.Fingerprint, strings.Repeat("0", 64), 1)), "image": []byte(strings.Replace(string(b), PauseImage, "evil:latest", 1)), "name": []byte(strings.Replace(string(b), a.Target.Name, "utcp-reused", 1))}
	for n, b := range cases {
		t.Run(n, func(t *testing.T) {
			if _, e := Decode(b); e == nil {
				t.Fatal("accepted invalid")
			}
		})
	}
}
func TestLifecycleHappyPath(t *testing.T) {
	m := &memoryClient{}
	e := testEngine(t, m, nil)
	a := sample()
	r := e.Execute(context.Background(), a)
	if r.Outcome != "success" || r.Effect != "created_suspended" {
		t.Fatal(r)
	}
	if err := ValidateResult(r); err != nil {
		t.Fatal(err)
	}
	r = e.Execute(context.Background(), a)
	if r.Outcome != "already_satisfied" || m.writes != 1 {
		t.Fatal(r, m.writes)
	}
	p := bound(a, m, "converge_projection")
	p.Intent.Projection.ConfigurationID = "config-2"
	p.Intent.ProjectionFingerprint = ProjectionFingerprint(*p.Intent.Projection)
	p.Fingerprint = Fingerprint(p)
	m.d.Annotations["unrelated"] = "preserve"
	r = e.Execute(context.Background(), p)
	if r.Outcome != "success" || m.d.Annotations["unrelated"] != "preserve" {
		t.Fatal(r)
	}
	r = e.Execute(context.Background(), p)
	if r.Outcome != "already_satisfied" {
		t.Fatal(r)
	}
	d := bound(p, m, "retire_delete")
	d.Generation = "12"
	d.Intent.RetirementGeneration = "12"
	d.Fingerprint = Fingerprint(d)
	r = e.Execute(context.Background(), d)
	if r.Effect != "deletion_accepted" || r.Outcome != "success" {
		t.Fatal(r)
	}
	r = e.Recover(context.Background(), d)
	if r.Effect != "observed_absent" {
		t.Fatal(r)
	}
}
func TestIdentityAndGeneration(t *testing.T) {
	for _, kind := range []string{"uid", "tenant", "incarnation", "resource_version", "newer", "same_generation_conflict"} {
		t.Run(kind, func(t *testing.T) {
			m := &memoryClient{}
			e := testEngine(t, m, nil)
			a := sample()
			e.Execute(context.Background(), a)
			p := bound(a, m, "converge_projection")
			switch kind {
			case "uid":
				p.Target.ExpectedUID = String("replaced")
			case "tenant":
				m.d.Labels["utcp.io/tenant-id"] = "other"
			case "incarnation":
				m.d.Labels[Prefix+"incarnation-id"] = "other"
			case "resource_version":
				p.Target.ExpectedRV = String("stale")
			case "newer":
				m.d.Annotations[AdmittedGeneration] = "12"
			case "same_generation_conflict":
				p.Generation = "10"
			}
			p.Fingerprint = Fingerprint(p)
			r := e.Execute(context.Background(), p)
			if r.Outcome == "success" || m.writes != 1 {
				t.Fatal(r, m.writes)
			}
		})
	}
}
func TestWholeTemplateInertness(t *testing.T) {
	tests := map[string]func(*appsv1.Deployment){"sysctl": func(d *appsv1.Deployment) {
		d.Spec.Template.Spec.SecurityContext.Sysctls = []corev1.Sysctl{{Name: "x", Value: "y"}}
	}, "proc": func(d *appsv1.Deployment) {
		v := corev1.UnmaskedProcMount
		d.Spec.Template.Spec.Containers[0].SecurityContext.ProcMount = &v
	}, "sidecar": func(d *appsv1.Deployment) {
		d.Spec.Template.Spec.Containers = append(d.Spec.Template.Spec.Containers, corev1.Container{Name: "unsafe"})
	}, "secret": func(d *appsv1.Deployment) { d.Spec.Template.Spec.Volumes = []corev1.Volume{{Name: "secret"}} }, "annotation": func(d *appsv1.Deployment) { d.Spec.Template.Annotations = map[string]string{"inject": "yes"} }, "hook": func(d *appsv1.Deployment) { d.Spec.Template.Spec.Containers[0].Lifecycle = &corev1.Lifecycle{} }, "host": func(d *appsv1.Deployment) { d.Spec.Template.Spec.HostNetwork = true }}
	for name, mutate := range tests {
		t.Run(name, func(t *testing.T) {
			a := sample()
			m := &memoryClient{}
			e := testEngine(t, m, nil)
			e.Execute(context.Background(), a)
			mutate(m.d)
			r := e.Recover(context.Background(), a)
			if r.Outcome != "ownership_conflict" {
				t.Fatal(r)
			}
		})
	}
}
func TestUnknownOutcomes(t *testing.T) {
	for _, kind := range []string{"create_suspended", "converge_projection", "retire_delete"} {
		for _, applied := range []bool{false, true} {
			t.Run(fmt.Sprint(kind, applied), func(t *testing.T) {
				m := &memoryClient{}
				e := testEngine(t, m, nil)
				a := sample()
				if kind != "create_suspended" {
					e.Execute(context.Background(), a)
					a = bound(a, m, kind)
				}
				if applied {
					m.lose = true
				} else {
					m.fail = map[string]string{"create_suspended": "create", "converge_projection": "patch", "retire_delete": "delete"}[kind]
				}
				r := e.Execute(context.Background(), a)
				if r.Outcome != "outcome_unknown" || r.Retry != "observe_first" || r.Dispatch != "unknown" {
					t.Fatal(r)
				}
				m.lose = false
				m.fail = ""
				writes := m.writes
				r = e.Recover(context.Background(), a)
				if m.writes != writes {
					t.Fatal("recovery wrote")
				}
				if kind == "create_suspended" && !applied && r.Outcome != "outcome_unknown" {
					t.Fatal(r)
				}
			})
		}
	}
}
func TestFinalizersAndReplacement(t *testing.T) {
	m := &memoryClient{}
	e := testEngine(t, m, nil)
	a := sample()
	e.Execute(context.Background(), a)
	m.d.Finalizers = []string{"test/finalizer"}
	d := bound(a, m, "retire_delete")
	r := e.Execute(context.Background(), d)
	if r.Effect != "deletion_accepted" {
		t.Fatal(r)
	}
	r = e.Recover(context.Background(), d)
	if r.Effect != "terminating" || r.DeletionTimestamp == nil {
		t.Fatal(r)
	}
	m.d.UID = types.UID("replacement")
	r = e.Recover(context.Background(), d)
	if r.Outcome != "identity_mismatch" {
		t.Fatal(r)
	}
}
func TestClaimExpiryCancellationAndBackpressure(t *testing.T) {
	a := sample()
	m := &memoryClient{}
	e := testEngine(t, m, nil)
	a.Claim.Expires = time.Now().Add(-time.Second)
	if r := e.Execute(context.Background(), a); r.Outcome != "stale_generation" || m.writes != 0 {
		t.Fatal(r)
	}
	a = sample()
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if r := e.Execute(ctx, a); r.Outcome != "cancelled" {
		t.Fatal(r)
	}
	entered := make(chan struct{})
	release := make(chan struct{})
	var once sync.Once
	v := verifierFunc(func(ctx context.Context, a Action) error {
		once.Do(func() { close(entered) })
		select {
		case <-release:
			return nil
		case <-ctx.Done():
			return ctx.Err()
		}
	})
	e = testEngine(t, m, v)
	done := make(chan Result)
	go func() { done <- e.Execute(context.Background(), a) }()
	<-entered
	if r := e.Execute(context.Background(), a); r.Outcome != "busy" {
		t.Fatal(r)
	}
	close(release)
	<-done
}
func TestDelayedCreateCannotBeRetracted(t *testing.T) {
	m := &memoryClient{}
	entered := make(chan struct{})
	release := make(chan struct{})
	m.before = func(kind string) {
		if kind == "create" {
			close(entered)
			<-release
		}
	}
	e := testEngine(t, m, nil)
	a := sample()
	done := make(chan Result)
	go func() { done <- e.Execute(context.Background(), a) }()
	<-entered // Canonical retirement now occurs after dispatch; Kubernetes cannot see it.
	close(release)
	r := <-done
	if r.Outcome != "success" || m.d.Spec.Replicas == nil || *m.d.Spec.Replicas != 0 || !projectionMatches(a, m.d) {
		t.Fatal(r)
	}
	denied := testEngine(t, m, verifierFunc(func(context.Context, Action) error { return &DecisionError{"stale_generation"} }))
	if r = denied.Recover(context.Background(), a); r.Outcome != "stale_generation" {
		t.Fatal(r)
	}
}
func TestRetirementPartialBarrierIsReported(t *testing.T) {
	m := &memoryClient{}
	e := testEngine(t, m, nil)
	a := sample()
	e.Execute(context.Background(), a)
	d := bound(a, m, "retire_delete")
	calls := 0
	v := verifierFunc(func(context.Context, Action) error {
		calls++
		if calls == 3 {
			return &DecisionError{"stale_generation"}
		}
		return nil
	})
	e = testEngine(t, m, v)
	r := e.Execute(context.Background(), d)
	if r.Outcome != "stale_generation" || r.Effect != "retirement_admitted" || r.Dispatch != "response_received" || m.d == nil || m.d.Annotations[RetirementMarker] != d.Intent.RetirementID {
		t.Fatal(r)
	}
	if err := ValidateResult(r); err != nil {
		t.Fatal(err)
	}
}
