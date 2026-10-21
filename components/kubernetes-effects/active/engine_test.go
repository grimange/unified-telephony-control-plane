package active

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	jsonpatch "gopkg.in/evanphx/json-patch.v4"
	appsv1 "k8s.io/api/apps/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	"k8s.io/apimachinery/pkg/runtime/schema"
	"strings"
	"sync"
	"testing"
	"time"
	lifecycle "utcp.invalid/components/kubernetes-effects/lifecycle"
)

type verifierFunc func(context.Context, Action) error

func (f verifierFunc) Verify(ctx context.Context, a Action) error { return f(ctx, a) }

type memoryClient struct {
	mu               sync.Mutex
	d                *appsv1.Deployment
	writes           int
	lose, notApplied bool
	before           func()
	getErr           error
}

func (m *memoryClient) Get(ctx context.Context, t Target) (*appsv1.Deployment, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.getErr != nil {
		return nil, m.getErr
	}
	return m.d.DeepCopy(), nil
}
func (m *memoryClient) Patch(ctx context.Context, t Target, b []byte) (*appsv1.Deployment, error) {
	if m.before != nil {
		m.before()
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.notApplied {
		return nil, errors.New("lost secret bearer token")
	}
	p, e := jsonpatch.DecodePatch(b)
	if e != nil {
		return nil, e
	}
	raw, _ := json.Marshal(m.d)
	out, e := p.Apply(raw)
	if e != nil {
		return nil, apierrors.NewConflict(schema.GroupResource{Resource: "deployments"}, t.Name, errors.New("CAS"))
	}
	d := &appsv1.Deployment{}
	if e = json.Unmarshal(out, d); e != nil {
		return nil, e
	}
	m.writes++
	d.ResourceVersion = fmt.Sprint(10 + m.writes)
	m.d = d
	if m.lose {
		return nil, errors.New("lost sensitive payload")
	}
	return d.DeepCopy(), nil
}

const asteriskImage = "127.0.0.1:5001/utcp/asterisk-ari@sha256:6027f3bc1464e4c7ed3485bb5a078a529e93ce3f04a2b3d3b0a03d5ac00598e9"
const freeSWITCHImage = "127.0.0.1:5001/utcp/freeswitch@sha256:3eedabc8afb2e3d1a4fc1e4ef8a6439bec2c7a30df4588566989dd9346ba3a11"

func sample() Action {
	now := time.Now().UTC()
	a := Action{Version: Version, CanonicalState: "bound", RequestID: "request-1", OperationID: "operation-1", TenantID: "tenant-1", RuntimeNodeID: "runtime-1", Target: Target{ClusterID: "test-cluster", Namespace: "test-ns", Kind: "Deployment", Name: "utcp-" + strings.Repeat("a", 32), RuntimeNodeSlug: "runtime-1", TargetID: "target-1", IncarnationID: strings.Repeat("a", 32), ExpectedUID: String("uid-a"), ExpectedRV: String("10")}, Generation: "10", Action: "converge_projection", Intent: Intent{Kind: "converge_projection", Projection: &Projection{TemplateVersion: AsteriskProfile, Image: asteriskImage, ConfigurationID: "config-1", ConfigurationSHA256: strings.Repeat("a", 64), SecretName: "runtime-secret"}, Replicas: Replicas(1)}, IdempotencyKey: "idem-1", Claim: Claim{1, strings.Repeat("b", 32), now.Add(time.Minute)}, Deadline: now.Add(time.Minute), CorrelationID: "correlation-1", AuditID: "audit-1"}
	sign(&a)
	return a
}
func sign(a *Action) {
	a.Intent.ProjectionFingerprint = ProjectionFingerprint(*a.Intent.Projection)
	a.Fingerprint = Fingerprint(*a)
}
func initial(a Action) *appsv1.Deployment {
	p := lifecycle.Projection{TemplateVersion: "inert.pause.v1", Image: lifecycle.PauseImage, ConfigurationID: "old", ConfigurationSHA256: strings.Repeat("b", 64)}
	d := lifecycle.SuspendedDeployment(lifecycle.Action{Target: a.Target, TenantID: a.TenantID, RuntimeNodeID: a.RuntimeNodeID, Generation: "9", Fingerprint: strings.Repeat("c", 64), Intent: lifecycle.Intent{Projection: &p, ProjectionFingerprint: lifecycle.ProjectionFingerprint(p)}})
	d.UID = "uid-a"
	d.ResourceVersion = "10"
	d.Annotations["unrelated"] = "preserve"
	return d
}
func testEngine(t *testing.T, m *memoryClient, v Verifier) *Engine {
	t.Helper()
	if v == nil {
		v = verifierFunc(func(context.Context, Action) error { return nil })
	}
	e, err := NewEngine(Config{TrustedCluster: "test-cluster", AllowedNamespaces: []string{"test-ns"}, Client: m, Verifier: v, ApprovedImages: map[string][]string{AsteriskProfile: {asteriskImage}, FreeSWITCHProfile: {freeSWITCHImage}}, ApprovedSecrets: map[string][]string{"test-ns": {"runtime-secret"}}})
	if err != nil {
		t.Fatal(err)
	}
	return e
}
func TestActiveProfiles(t *testing.T) {
	for _, profile := range []string{AsteriskProfile, FreeSWITCHProfile} {
		t.Run(profile, func(t *testing.T) {
			a := sample()
			a.Intent.Projection.TemplateVersion = profile
			if profile == FreeSWITCHProfile {
				a.Intent.Projection.Image = freeSWITCHImage
			}
			sign(&a)
			m := &memoryClient{d: initial(a)}
			e := testEngine(t, m, nil)
			r := e.Execute(context.Background(), a)
			if r.Outcome != "success" || r.Effect != "projection_applied" || m.writes != 1 {
				t.Fatal(r)
			}
			if err := ValidateResult(r); err != nil {
				t.Fatal(err)
			}
			if m.d.Spec.Paused || *m.d.Spec.Replicas != 1 || m.d.Annotations["unrelated"] != "preserve" {
				t.Fatal("projection/preservation")
			}
			r = e.Execute(context.Background(), a)
			if r.Outcome != "already_satisfied" || m.writes != 1 {
				t.Fatal(r)
			}
			if r = e.Recover(context.Background(), a); r.Outcome != "already_satisfied" {
				t.Fatal(r)
			}
		})
	}
}
func TestClosedActiveContract(t *testing.T) {
	a := sample()
	raw, _ := json.Marshal(a)
	if _, err := Decode(raw); err != nil {
		t.Fatal(err)
	}
	cases := map[string][]byte{"unknown": []byte(strings.TrimSuffix(string(raw), "}") + `,"command":"evil"}`), "duplicate": []byte(strings.TrimSuffix(string(raw), "}") + `,"action":"converge_projection"}`), "version": []byte(strings.Replace(string(raw), Version, "utcp.infrastructure.v2", 1)), "numeric_generation": []byte(strings.Replace(string(raw), `"target_generation":"10"`, `"target_generation":10`, 1)), "surrogate": []byte(strings.Replace(string(raw), `"config-1"`, `"\ud800"`, 1)), "null_secret": []byte(strings.Replace(string(raw), `"secret_name":"runtime-secret"`, `"secret_name":null`, 1))}
	for n, b := range cases {
		t.Run(n, func(t *testing.T) {
			if _, err := Decode(b); err == nil {
				t.Fatal("accepted invalid")
			}
		})
	}
}
func TestActiveRejections(t *testing.T) {
	cases := []struct {
		name, want string
		change     func(*Action, *memoryClient)
	}{
		{"preparing", "invalid_request", func(a *Action, m *memoryClient) { a.CanonicalState = "preparing"; sign(a) }},
		{"retiring", "invalid_request", func(a *Action, m *memoryClient) { a.CanonicalState = "retiring"; sign(a) }},
		{"unbound", "invalid_request", func(a *Action, m *memoryClient) { a.Target.ExpectedUID = nil; sign(a) }},
		{"wrong_uid", "identity_mismatch", func(a *Action, m *memoryClient) { a.Target.ExpectedUID = String("other"); sign(a) }},
		{"wrong_incarnation", "ownership_conflict", func(a *Action, m *memoryClient) { m.d.Labels[Prefix+"incarnation-id"] = "other" }},
		{"wrong_tenant", "ownership_conflict", func(a *Action, m *memoryClient) { m.d.Labels["utcp.io/tenant-id"] = "other" }},
		{"stale_rv", "resource_version_conflict", func(a *Action, m *memoryClient) { a.Target.ExpectedRV = String("1") }},
		{"newer_barrier", "stale_generation", func(a *Action, m *memoryClient) {
			m.d.Annotations[AdmittedGeneration] = "11"
			m.d.Annotations[AdmittedIntent] = strings.Repeat("f", 64)
		}},
		{"fingerprint_conflict", "ownership_conflict", func(a *Action, m *memoryClient) {
			m.d.Annotations[AdmittedGeneration] = a.Generation
			m.d.Annotations[AdmittedIntent] = strings.Repeat("f", 64)
		}},
		{"retirement_terminal", "stale_generation", func(a *Action, m *memoryClient) {
			m.d.Annotations[RetirementMarker] = "retired"
			a.Generation = "99"
			sign(a)
		}},
		{"unknown_profile", "invalid_request", func(a *Action, m *memoryClient) { a.Intent.Projection.TemplateVersion = "evil.v1"; sign(a) }},
		{"mutable_image", "invalid_request", func(a *Action, m *memoryClient) { a.Intent.Projection.Image = "evil:latest"; sign(a) }},
		{"unapproved_image", "forbidden", func(a *Action, m *memoryClient) {
			a.Intent.Projection.Image = "unapproved.example/runtime@sha256:" + strings.Repeat("f", 64)
			sign(a)
		}},
		{"unapproved_secret", "forbidden", func(a *Action, m *memoryClient) { a.Intent.Projection.SecretName = "unapproved"; sign(a) }},
		{"api_outage", "transport_unavailable", func(a *Action, m *memoryClient) { m.getErr = errors.New("secret raw server response") }},
		{"expired_claim", "stale_generation", func(a *Action, m *memoryClient) { a.Claim.Expires = time.Now().Add(-time.Minute) }},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			a := sample()
			m := &memoryClient{d: initial(a)}
			c.change(&a, m)
			r := testEngine(t, m, nil).Execute(context.Background(), a)
			if r.Outcome != c.want || m.writes != 0 {
				t.Fatal(r, m.writes)
			}
		})
	}
}
func TestUnknownActiveOutcomes(t *testing.T) {
	for _, applied := range []bool{true, false} {
		t.Run(fmt.Sprint(applied), func(t *testing.T) {
			a := sample()
			m := &memoryClient{d: initial(a), lose: applied, notApplied: !applied}
			e := testEngine(t, m, nil)
			r := e.Execute(context.Background(), a)
			if r.Outcome != "outcome_unknown" || r.Dispatch != "unknown" || r.Retry != "observe_first" {
				t.Fatal(r)
			}
			r = e.Recover(context.Background(), a)
			want := "drift"
			if applied {
				want = "already_satisfied"
			}
			if r.Outcome != want {
				t.Fatal(r)
			}
			if m.writes > 1 {
				t.Fatal("recovery replay")
			}
		})
	}
}
func TestFreshClaimBeforeDispatch(t *testing.T) {
	a := sample()
	m := &memoryClient{d: initial(a)}
	calls := 0
	e := testEngine(t, m, verifierFunc(func(context.Context, Action) error {
		calls++
		if calls == 2 {
			return &DecisionError{Outcome: "unauthorized"}
		}
		return nil
	}))
	r := e.Execute(context.Background(), a)
	if r.Outcome != "unauthorized" || m.writes != 0 || calls != 2 {
		t.Fatal(r, calls)
	}
}
func TestActiveCancellationAndBackpressure(t *testing.T) {
	a := sample()
	m := &memoryClient{d: initial(a)}
	entered, release := make(chan struct{}), make(chan struct{})
	m.before = func() { close(entered); <-release }
	e := testEngine(t, m, nil)
	done := make(chan Result)
	go func() { done <- e.Execute(context.Background(), a) }()
	<-entered
	if r := e.Execute(context.Background(), a); r.Outcome != "busy" {
		t.Fatal(r)
	}
	close(release)
	if r := <-done; r.Outcome != "success" {
		t.Fatal(r)
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if r := e.Execute(ctx, a); r.Outcome != "cancelled" {
		t.Fatal(r)
	}
}
func TestCrossAdapterCAS(t *testing.T) {
	a := sample()
	m := &memoryClient{d: initial(a)}
	entered, release := make(chan struct{}, 2), make(chan struct{})
	m.before = func() { entered <- struct{}{}; <-release }
	e1, e2 := testEngine(t, m, nil), testEngine(t, m, nil)
	other := a
	other.Generation = "11"
	sign(&other)
	out := make(chan Result, 2)
	go func() { out <- e1.Execute(context.Background(), a) }()
	go func() { out <- e2.Execute(context.Background(), other) }()
	<-entered
	<-entered
	close(release)
	success, rejected := 0, 0
	for range 2 {
		r := <-out
		if r.Outcome == "success" {
			success++
		} else if r.Outcome == "resource_version_conflict" {
			rejected++
		} else {
			t.Fatal(r)
		}
	}
	if success != 1 || rejected != 1 || m.writes != 1 {
		t.Fatal(success, rejected, m.writes)
	}
}
func TestControlledTemplateDrift(t *testing.T) {
	a := sample()
	m := &memoryClient{d: initial(a)}
	e := testEngine(t, m, nil)
	if r := e.Execute(context.Background(), a); r.Outcome != "success" {
		t.Fatal(r)
	}
	m.d.Spec.Template.Spec.Containers[0].Command = []string{"evil"}
	r := e.Recover(context.Background(), a)
	if r.Outcome != "drift" {
		t.Fatal(r)
	}
}

func TestNormativeActiveFixtures(t *testing.T) {
	b, err := contracts.ReadFile("contracts/fixtures.json")
	if err != nil {
		t.Fatal(err)
	}
	var fixtures struct {
		Positive, Negative []struct {
			Name   string          `json:"name"`
			Action json.RawMessage `json:"action"`
		}
		PositiveResults []struct {
			Name   string          `json:"name"`
			Result json.RawMessage `json:"result"`
		} `json:"positive_results"`
		NegativeResults []struct {
			Name   string          `json:"name"`
			Result json.RawMessage `json:"result"`
		} `json:"negative_results"`
	}
	if err = json.Unmarshal(b, &fixtures); err != nil {
		t.Fatal(err)
	}
	for _, v := range fixtures.Positive {
		t.Run(v.Name, func(t *testing.T) {
			if _, err := Decode(v.Action); err != nil {
				t.Fatal(err)
			}
		})
	}
	for _, v := range fixtures.Negative {
		t.Run(v.Name, func(t *testing.T) {
			if _, err := Decode(v.Action); err == nil {
				t.Fatal("accepted invalid")
			}
		})
	}
	for _, v := range fixtures.PositiveResults {
		t.Run("result/"+v.Name, func(t *testing.T) {
			var x any
			if err := json.Unmarshal(v.Result, &x); err != nil {
				t.Fatal(err)
			}
			if err := resultSchema.Validate(x); err != nil {
				t.Fatal(err)
			}
			var r Result
			if err := json.Unmarshal(v.Result, &r); err != nil {
				t.Fatal(err)
			}
			if err := ValidateResult(r); err != nil {
				t.Fatal(err)
			}
		})
	}
	for _, v := range fixtures.NegativeResults {
		t.Run("result/"+v.Name, func(t *testing.T) {
			var x any
			if err := json.Unmarshal(v.Result, &x); err != nil {
				t.Fatal(err)
			}
			if err := resultSchema.Validate(x); err == nil {
				t.Fatal("accepted invalid result")
			}
		})
	}
}
func TestCatalogFrozen(t *testing.T) {
	a := sample()
	m := &memoryClient{d: initial(a)}
	images := map[string][]string{AsteriskProfile: {asteriskImage}}
	secrets := map[string][]string{"test-ns": {"runtime-secret"}}
	e, err := NewEngine(Config{TrustedCluster: "test-cluster", AllowedNamespaces: []string{"test-ns"}, Client: m, Verifier: verifierFunc(func(context.Context, Action) error { return nil }), ApprovedImages: images, ApprovedSecrets: secrets})
	if err != nil {
		t.Fatal(err)
	}
	images[AsteriskProfile][0] = "evil@sha256:" + strings.Repeat("f", 64)
	secrets["test-ns"][0] = "other"
	if r := e.Execute(context.Background(), a); r.Outcome != "success" {
		t.Fatal(r)
	}
}

func TestActiveSafeErrorProjection(t *testing.T) {
	a := sample()
	m := &memoryClient{d: initial(a), notApplied: true}
	r := testEngine(t, m, nil).Execute(context.Background(), a)
	b, err := json.Marshal(r)
	if err != nil {
		t.Fatal(err)
	}
	for _, unsafe := range []string{"bearer", "token", "sensitive", "secret_data", a.Claim.Token, asteriskImage, "runtime-secret"} {
		if strings.Contains(string(b), unsafe) {
			t.Fatalf("unsafe result projection %s", unsafe)
		}
	}
	if err := ValidateResult(r); err != nil {
		t.Fatal(err)
	}
}

func TestUnqualifiedImageRejected(t *testing.T) {
	a := sample()
	a.Intent.Projection.Image = "asterisk@sha256:" + strings.Repeat("a", 64)
	a.Intent.ProjectionFingerprint = ProjectionFingerprint(*a.Intent.Projection)
	a.Fingerprint = Fingerprint(a)
	if err := Validate(a); err == nil {
		t.Fatal("accepted unqualified digest reference")
	}
	b, err := json.Marshal(a)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := Decode(b); err == nil {
		t.Fatal("decoded unqualified digest reference")
	}
}
