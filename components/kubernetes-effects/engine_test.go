package effects

import (
	"context"
	"encoding/json"
	"errors"
	jsonpatch "gopkg.in/evanphx/json-patch.v4"
	appsv1 "k8s.io/api/apps/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime/schema"
	"k8s.io/apimachinery/pkg/types"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

const contracts = "../../docs/architecture/proposals/gn-g0/contracts/v1"

func fixtureAction(t *testing.T) Action {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(contracts, "fixtures/action-admit.json"))
	if err != nil {
		t.Fatal(err)
	}
	var a Action
	if err = json.Unmarshal(b, &a); err != nil {
		t.Fatal(err)
	}
	a.Deadline = time.Now().Add(time.Minute)
	a.Claim.Expires = time.Now().Add(time.Minute)
	return a
}
func fixtureDeployment(a Action) *appsv1.Deployment {
	n := int32(1)
	return &appsv1.Deployment{TypeMeta: metav1.TypeMeta{APIVersion: "apps/v1", Kind: "Deployment"}, ObjectMeta: metav1.ObjectMeta{Name: a.Target.Name, Namespace: a.Target.Namespace, UID: types.UID(a.Target.ExpectedUID), ResourceVersion: a.Target.ExpectedRV, Labels: ownership(a), Annotations: map[string]string{"unrelated": "preserved"}}, Spec: appsv1.DeploymentSpec{Replicas: &n}}
}

type verifyFunc func(context.Context, Action) error

func (f verifyFunc) Verify(c context.Context, a Action) error { return f(c, a) }
func permit(context.Context, Action) error                    { return nil }

type memoryClient struct {
	mu            sync.Mutex
	d             *appsv1.Deployment
	gets, patches int
	beforePatch   func()
	afterPatch    func()
	patchErr      error
	applyOnError  bool
	readErr       error
	returnBad     bool
}

func (m *memoryClient) Get(context.Context, Target) (*appsv1.Deployment, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.gets++
	if m.readErr != nil {
		return nil, m.readErr
	}
	if m.d == nil {
		return nil, apierrors.NewNotFound(schema.GroupResource{Group: "apps", Resource: "deployments"}, "fixture")
	}
	return m.d.DeepCopy(), nil
}
func (m *memoryClient) Patch(ctx context.Context, target Target, b []byte) (*appsv1.Deployment, error) {
	if m.beforePatch != nil {
		m.beforePatch()
	}
	m.mu.Lock()
	m.patches++
	if m.patchErr != nil && !m.applyOnError {
		err := m.patchErr
		m.mu.Unlock()
		return nil, err
	}
	raw, _ := json.Marshal(m.d)
	p, e := jsonpatch.DecodePatch(b)
	if e == nil {
		raw, e = p.Apply(raw)
	}
	if e != nil {
		m.mu.Unlock()
		return nil, apierrors.NewConflict(schema.GroupResource{Group: "apps", Resource: "deployments"}, target.Name, errors.New("model CAS predicate"))
	}
	var d appsv1.Deployment
	_ = json.Unmarshal(raw, &d)
	n, _ := strconv.Atoi(m.d.ResourceVersion)
	d.ResourceVersion = strconv.Itoa(n + 1)
	m.d = &d
	result := d.DeepCopy()
	err := m.patchErr
	if m.returnBad {
		result.UID = "wrong"
	}
	m.mu.Unlock()
	if m.afterPatch != nil {
		m.afterPatch()
	}
	return result, err
}
func testEngine(t *testing.T, m Client, v Verifier) *Engine {
	t.Helper()
	validator, err := NewValidator(contracts)
	if err != nil {
		t.Fatal(err)
	}
	e, err := NewEngine(Config{TrustedCluster: "cluster-fixture", AllowedNamespaces: []string{"utcp-runtime"}, Client: m, Verifier: v, Validator: validator})
	if err != nil {
		t.Fatal(err)
	}
	return e
}
func expectOutcome(t *testing.T, e *Engine, r Result, want string) {
	t.Helper()
	if err := e.config.Validator.ValidateResult(r); err != nil {
		t.Fatal(err)
	}
	if r.Outcome != want {
		t.Fatalf("want %s got %+v", want, r)
	}
}
func installBarrier(m *memoryClient, a Action) {
	m.d.Annotations[AdmittedGeneration] = a.Generation
	m.d.Annotations[AdmittedIntent] = a.Fingerprint
}
func effectAction(a Action) Action { a.Action = "set_replicas"; return a }
func TestNormativeActionsAndStrictInput(t *testing.T) {
	v, err := NewValidator(contracts)
	if err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"action-admit.json", "action-replicas.json"} {
		b, _ := os.ReadFile(filepath.Join(contracts, "fixtures", name))
		a, e := v.ParseAction(b)
		if e != nil {
			t.Fatal(e)
		}
		if Fingerprint(a) != a.Fingerprint {
			t.Fatal("fingerprint")
		}
	}
	base, _ := os.ReadFile(filepath.Join(contracts, "fixtures/action-admit.json"))
	for name, b := range map[string][]byte{"duplicate": append([]byte(`{"contract_version":"wrong",`), base[1:]...), "trailing": append(append([]byte{}, base...), []byte(` {}`)...), "null": []byte(strings.Replace(string(base), `"replicas": 0`, `"replicas": null`, 1)), "overflow": []byte(strings.Replace(string(base), `"10"`, `"9223372036854775808"`, 1)), "utf8": {0xff}, "huge": make([]byte, (1<<20)+1)} {
		t.Run(name, func(t *testing.T) {
			if _, e := v.ParseAction(b); e == nil {
				t.Fatal("accepted invalid input")
			}
		})
	}
	var manifest []struct {
		File, Schema string
		Valid        bool
	}
	b, _ := os.ReadFile(filepath.Join(contracts, "fixtures/manifest.json"))
	_ = json.Unmarshal(b, &manifest)
	for _, f := range manifest {
		if f.Schema != "action.schema.json" && f.Schema != "result.schema.json" {
			continue
		}
		t.Run(f.File, func(t *testing.T) {
			b, _ := os.ReadFile(filepath.Join(contracts, "fixtures", f.File))
			var err error
			if f.Schema == "action.schema.json" {
				err = v.ValidateAction(b)
			} else {
				var r Result
				err = json.Unmarshal(b, &r)
				if err == nil {
					err = v.ValidateResult(r)
				}
			}
			if (err == nil) != f.Valid {
				t.Fatalf("schema valid=%v error=%v", f.Valid, err)
			}
		})
	}
}
func TestAdmissionReplicasAndIdempotency(t *testing.T) {
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	e := testEngine(t, m, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "success")
	expectOutcome(t, e, e.Execute(context.Background(), a), "already_satisfied")
	a.Target.ExpectedRV = m.d.ResourceVersion
	a = effectAction(a)
	expectOutcome(t, e, e.Execute(context.Background(), a), "success")
	r := e.Execute(context.Background(), a)
	expectOutcome(t, e, r, "already_satisfied")
	if m.patches != 2 || m.d.Annotations["unrelated"] != "preserved" || r.Effect != "observed_satisfied" {
		t.Fatal("lost idempotency or metadata")
	}
	*m.d.Spec.Replicas = 9
	m.d.ResourceVersion = "103"
	expectOutcome(t, e, e.Execute(context.Background(), a), "resource_version_conflict")
	a.Target.ExpectedRV = "103"
	expectOutcome(t, e, e.Execute(context.Background(), a), "success")
}
func TestAdmissionAbsentAnnotations(t *testing.T) {
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	m.d.Annotations = nil
	e := testEngine(t, m, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "success")
}
func TestIdentityAndMarkersFailClosed(t *testing.T) {
	mutations := map[string]func(*appsv1.Deployment){"uid": func(d *appsv1.Deployment) { d.UID = "replacement" }, "namespace": func(d *appsv1.Deployment) { d.Namespace = "other" }, "name": func(d *appsv1.Deployment) { d.Name = "other" }, "runtime": func(d *appsv1.Deployment) { d.Labels["utcp.dev/runtime-node"] = "other" }, "tenant": func(d *appsv1.Deployment) { d.Labels["utcp.io/tenant-id"] = "other" }, "incarnation": func(d *appsv1.Deployment) { d.Labels[prefix+"incarnation-id"] = "other" }, "target": func(d *appsv1.Deployment) { d.Labels[prefix+"target-id"] = "other" }, "deleted": func(d *appsv1.Deployment) { x := metav1.Now(); d.DeletionTimestamp = &x }, "partialmarkers": func(d *appsv1.Deployment) { d.Annotations[AdmittedGeneration] = "10" }, "negative": func(d *appsv1.Deployment) {
		d.Annotations[AdmittedGeneration] = "-1"
		d.Annotations[AdmittedIntent] = strings.Repeat("a", 64)
	}, "overflow": func(d *appsv1.Deployment) {
		d.Annotations[AdmittedGeneration] = "9223372036854775808"
		d.Annotations[AdmittedIntent] = strings.Repeat("a", 64)
	}, "appliedahead": func(d *appsv1.Deployment) {
		d.Annotations[AppliedGeneration] = "11"
		d.Annotations[AppliedIntent] = strings.Repeat("a", 64)
	}}
	for name, mutate := range mutations {
		t.Run(name, func(t *testing.T) {
			a := fixtureAction(t)
			m := &memoryClient{d: fixtureDeployment(a)}
			mutate(m.d)
			e := testEngine(t, m, verifyFunc(permit))
			want := "ownership_conflict"
			if name == "uid" || name == "namespace" || name == "name" {
				want = "identity_mismatch"
			}
			expectOutcome(t, e, e.Execute(context.Background(), a), want)
			if m.patches != 0 {
				t.Fatal("sent forbidden patch")
			}
		})
	}
	for name, mutate := range map[string]func(*Action){"cluster": func(a *Action) { a.Target.ClusterID = "other" }, "namespace": func(a *Action) { a.Target.Namespace = "other" }, "fingerprint": func(a *Action) { a.Fingerprint = strings.Repeat("a", 64) }} {
		t.Run("request-"+name, func(t *testing.T) {
			a := fixtureAction(t)
			m := &memoryClient{d: fixtureDeployment(a)}
			mutate(&a)
			if name != "fingerprint" {
				a.Fingerprint = Fingerprint(a)
			}
			e := testEngine(t, m, verifyFunc(permit))
			want := "invalid_request"
			if name == "cluster" {
				want = "identity_mismatch"
			}
			if name == "namespace" {
				want = "forbidden"
			}
			expectOutcome(t, e, e.Execute(context.Background(), a), want)
			if m.gets != 0 || m.patches != 0 {
				t.Fatal("out of scope IO")
			}
		})
	}
}
func TestClaimExpiryAndCallbackFailClosed(t *testing.T) {
	for _, code := range []string{"unauthorized", "forbidden", "stale_generation", "transport_unavailable", "timeout", "invalid_request", "terminal_failure"} {
		t.Run(code, func(t *testing.T) {
			a := fixtureAction(t)
			m := &memoryClient{d: fixtureDeployment(a)}
			e := testEngine(t, m, verifyFunc(func(context.Context, Action) error { return &DecisionError{code} }))
			expectOutcome(t, e, e.Execute(context.Background(), a), code)
			if m.patches != 0 {
				t.Fatal("denied dispatched")
			}
		})
	}
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	var calls int
	e := testEngine(t, m, verifyFunc(func(context.Context, Action) error {
		calls++
		if calls == 2 {
			return &DecisionError{"stale_generation"}
		}
		return nil
	}))
	expectOutcome(t, e, e.Execute(context.Background(), a), "stale_generation")
	if m.patches != 0 || calls != 2 {
		t.Fatal("not rechecked before dispatch")
	}
	a.Claim.Expires = time.Now().Add(-time.Second)
	expectOutcome(t, e, e.Execute(context.Background(), a), "stale_generation")
}
func TestDelayedGenerationsBeforeAndAfterBarrier(t *testing.T) {
	for _, newFirst := range []bool{true, false} {
		t.Run(strconv.FormatBool(newFirst), func(t *testing.T) {
			old := fixtureAction(t)
			old.Action = "set_replicas"
			m := &memoryClient{d: fixtureDeployment(old)}
			installBarrier(m, old)
			e := testEngine(t, m, verifyFunc(permit))
			entered, release := make(chan struct{}), make(chan struct{})
			m.beforePatch = func() { close(entered); <-release }
			done := make(chan Result, 1)
			go func() { done <- e.Execute(context.Background(), old) }()
			<-entered
			newer := old
			newer.Generation = "11"
			newer.Replicas = 2
			newer.Fingerprint = Fingerprint(newer)
			newer.Action = "admit_generation"
			other := testEngine(t, m, verifyFunc(permit))
			m.beforePatch = nil
			if newFirst {
				expectOutcome(t, other, other.Execute(context.Background(), newer), "success")
				close(release)
				expectOutcome(t, e, <-done, "resource_version_conflict")
			} else {
				close(release)
				expectOutcome(t, e, <-done, "success")
				newer.Target.ExpectedRV = m.d.ResourceVersion
				expectOutcome(t, other, other.Execute(context.Background(), newer), "success")
			}
			newer.Target.ExpectedRV = m.d.ResourceVersion
			newer.Action = "set_replicas"
			expectOutcome(t, other, other.Execute(context.Background(), newer), "success")
			if *m.d.Spec.Replicas != 2 {
				t.Fatal("failed convergence")
			}
		})
	}
}
func TestLeaseExpiryDoesNotRetractDispatchedRequest(t *testing.T) {
	a := effectAction(fixtureAction(t))
	m := &memoryClient{d: fixtureDeployment(a)}
	installBarrier(m, a)
	clock := time.Now()
	e := testEngine(t, m, verifyFunc(permit))
	e.config.Now = func() time.Time { return clock }
	m.beforePatch = func() { clock = a.Claim.Expires.Add(time.Second) }
	expectOutcome(t, e, e.Execute(context.Background(), a), "success")
	if m.d.ResourceVersion == a.Target.ExpectedRV {
		t.Fatal("patch did not arrive")
	}
}
func TestConflictingSameGeneration(t *testing.T) {
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	installBarrier(m, a)
	a.Replicas = 2
	a.Fingerprint = Fingerprint(a)
	e := testEngine(t, m, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "ownership_conflict")
}
func TestUnknownOutcomeAndObserveFirstRecovery(t *testing.T) {
	for _, applied := range []bool{true, false} {
		t.Run(strconv.FormatBool(applied), func(t *testing.T) {
			a := effectAction(fixtureAction(t))
			m := &memoryClient{d: fixtureDeployment(a), patchErr: errors.New("credential-sensitive-network-error"), applyOnError: applied}
			installBarrier(m, a)
			e := testEngine(t, m, verifyFunc(permit))
			r := e.Execute(context.Background(), a)
			expectOutcome(t, e, r, "outcome_unknown")
			if r.Effect != "unknown" || r.Dispatch != "unknown" || r.Retry != "observe_first" {
				t.Fatal(r)
			}
			m.patchErr = nil
			before := m.patches
			want := "drift"
			if applied {
				want = "already_satisfied"
			}
			r = e.Recover(context.Background(), a)
			expectOutcome(t, e, r, want)
			if m.patches != before {
				t.Fatal("recovery replayed mutation")
			}
			raw, _ := json.Marshal(r)
			if strings.Contains(string(raw), "credential-sensitive") {
				t.Fatal("raw error leaked")
			}
		})
	}
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a), returnBad: true}
	e := testEngine(t, m, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "outcome_unknown")
}
func TestMutationErrorClassification(t *testing.T) {
	cases := map[string]struct {
		err  error
		want string
	}{"conflict": {apierrors.NewConflict(schema.GroupResource{Resource: "deployments"}, "fixture", errors.New("conflict")), "resource_version_conflict"}, "422validation": {apierrors.NewInvalid(schema.GroupKind{Kind: "Deployment"}, "fixture", nil), "invalid_request"}, "badrequest": {apierrors.NewBadRequest("bad"), "invalid_request"}, "forbidden": {apierrors.NewForbidden(schema.GroupResource{Resource: "deployments"}, "fixture", errors.New("denied")), "forbidden"}, "unauthorized": {apierrors.NewUnauthorized("denied"), "unauthorized"}, "gone": {apierrors.NewNotFound(schema.GroupResource{Resource: "deployments"}, "fixture"), "target_not_found"}, "429": {apierrors.NewTooManyRequests("busy", 1), "retryable_failure"}, "500": {apierrors.NewInternalError(errors.New("oops")), "outcome_unknown"}, "timeout": {context.DeadlineExceeded, "outcome_unknown"}, "cancel": {context.Canceled, "outcome_unknown"}}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			a := fixtureAction(t)
			m := &memoryClient{d: fixtureDeployment(a), patchErr: c.err}
			e := testEngine(t, m, verifyFunc(permit))
			expectOutcome(t, e, e.Execute(context.Background(), a), c.want)
			if m.patches != 1 {
				t.Fatal("retried")
			}
		})
	}
}
func TestCancellationBackpressureAndConcurrentAdapters(t *testing.T) {
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	entered, release := make(chan struct{}), make(chan struct{})
	var once sync.Once
	v := verifyFunc(func(ctx context.Context, _ Action) error {
		once.Do(func() { close(entered) })
		select {
		case <-release:
			return nil
		case <-ctx.Done():
			return ctx.Err()
		}
	})
	e := testEngine(t, m, v)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan Result, 1)
	go func() { done <- e.Execute(ctx, a) }()
	<-entered
	expectOutcome(t, e, e.Execute(context.Background(), a), "busy")
	cancel()
	expectOutcome(t, e, <-done, "cancelled")
	close(release)
	if m.patches != 0 {
		t.Fatal("cancelled dispatched")
	}
	var arrived atomic.Int32
	gate := make(chan struct{})
	m.beforePatch = func() {
		if arrived.Add(1) == 2 {
			close(gate)
		}
		<-gate
	}
	e1, e2 := testEngine(t, m, verifyFunc(permit)), testEngine(t, m, verifyFunc(permit))
	results := make(chan Result, 2)
	go func() { results <- e1.Execute(context.Background(), a) }()
	go func() { results <- e2.Execute(context.Background(), a) }()
	counts := map[string]int{}
	for i := 0; i < 2; i++ {
		r := <-results
		counts[r.Outcome]++
	}
	if counts["success"] != 1 || counts["resource_version_conflict"] != 1 {
		t.Fatal(counts)
	}
}
func TestReplicaEqualityWithoutMarkersIsNotIdempotency(t *testing.T) {
	a := effectAction(fixtureAction(t))
	m := &memoryClient{d: fixtureDeployment(a)}
	installBarrier(m, a)
	*m.d.Spec.Replicas = a.Replicas
	e := testEngine(t, m, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "success")
	if m.patches != 1 {
		t.Fatal("replica equality falsely satisfied")
	}
}

func TestFourDistinctTargetsBoundedAdmission(t *testing.T) {
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	entered := make(chan struct{}, 4)
	release := make(chan struct{})
	v := verifyFunc(func(ctx context.Context, _ Action) error {
		entered <- struct{}{}
		select {
		case <-release:
			return &DecisionError{Outcome: "unauthorized"}
		case <-ctx.Done():
			return ctx.Err()
		}
	})
	e := testEngine(t, m, v)
	done := make(chan Result, 4)
	for i := 0; i < 4; i++ {
		q := a
		q.Target.Name = "runtime-" + strconv.Itoa(i)
		q.Fingerprint = Fingerprint(q)
		go func(q Action) { done <- e.Execute(context.Background(), q) }(q)
	}
	for i := 0; i < 4; i++ {
		<-entered
	}
	a.Target.Name = "fifth"
	a.Fingerprint = Fingerprint(a)
	expectOutcome(t, e, e.Execute(context.Background(), a), "busy")
	close(release)
	for i := 0; i < 4; i++ {
		expectOutcome(t, e, <-done, "unauthorized")
	}
	if m.gets != 0 || m.patches != 0 {
		t.Fatal("saturated or denied action dispatched")
	}
}

func TestStrictJSONUnicodeEscapeParity(t *testing.T) {
	cases := []struct {
		name, wire string
		valid      bool
	}{
		{"unpaired_high", `{"value":"\ud800"}`, false},
		{"unpaired_low", `{"value":"\udfff"}`, false},
		{"high_then_text", `{"value":"\ud800x"}`, false},
		{"high_then_high", `{"value":"\ud800\udbff"}`, false},
		{"high_then_bmp", `{"value":"\ud800\u0061"}`, false},
		{"high_then_escaped_backslash", `{"value":"\ud800\\udc00"}`, false},
		{"unpaired_key", `{"\ud800":"value"}`, false},
		{"odd_backslashes_before_high", `{"value":"\\\ud800"}`, false},
		{"truncated_escape", `{"value":"\ud80"}`, false},
		{"bad_hex", `{"value":"\uZZZZ"}`, false},
		{"paired_surrogates", `{"value":"\ud800\udc00"}`, true},
		{"paired_uppercase", `{"value":"\uDBFF\uDFFF"}`, true},
		{"literal_backslash_sequence", `{"value":"\\ud800"}`, true},
		{"literal_low_sequence", `{"value":"\\udfff"}`, true},
		{"escaped_quote_then_pair", `{"value":"\"\ud83d\ude00"}`, true},
		{"literal_replacement_rune", `{"value":"�"}`, true},
		{"bmp", `{"value":"\u0061"}`, true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := StrictJSON([]byte(tc.wire))
			if (err == nil) != tc.valid {
				t.Fatalf("valid=%v got error=%v", tc.valid, err)
			}
		})
	}
}
