package spike

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"sync"
	"testing"
	"time"

	jsonpatch "github.com/evanphx/json-patch/v5"
	schema "github.com/santhosh-tekuri/jsonschema/v5"
	appsv1 "k8s.io/api/apps/v1"
	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/kubernetes/fake"
	"k8s.io/client-go/rest"
)

const contractDir = "../../docs/architecture/proposals/gn-g0/contracts/v1"

func readJSON(t *testing.T, name string, dst any) {
	t.Helper()
	b, e := os.ReadFile(filepath.Join(contractDir, name))
	if e != nil {
		t.Fatal(e)
	}
	if e = json.Unmarshal(b, dst); e != nil {
		t.Fatal(e)
	}
}
func action(t *testing.T, generation string) Action {
	t.Helper()
	var a Action
	readJSON(t, "fixtures/action-admit.json", &a)
	a.Generation = generation
	a.Fingerprint = Fingerprint(a)
	a.Deadline = time.Now().Add(time.Minute)
	a.Claim.Expires = time.Now().Add(time.Minute)
	return a
}
func deployment(a Action) *appsv1.Deployment {
	n := int32(1)
	return &appsv1.Deployment{TypeMeta: metav1.TypeMeta{APIVersion: "apps/v1", Kind: "Deployment"}, ObjectMeta: metav1.ObjectMeta{Name: a.Target.Name, Namespace: a.Target.Namespace, UID: types.UID(a.Target.ExpectedUID), ResourceVersion: "100", Labels: map[string]string{"app.kubernetes.io/part-of": "utcp", "app.kubernetes.io/instance": a.Target.Name, "utcp.dev/runtime-node": a.Target.RuntimeNodeSlug, "utcp.io/runtime-node-id": a.RuntimeNodeID, "utcp.io/tenant-id": a.TenantID, "infrastructure.utcp.io/target-id": a.Target.TargetID, "infrastructure.utcp.io/incarnation-id": a.Target.IncarnationID}, Annotations: map[string]string{"unrelated": "preserved"}}, Spec: appsv1.DeploymentSpec{Replicas: &n}}
}
func validateOutput(t *testing.T, schemaName string, value any) {
	t.Helper()
	c := schema.NewCompiler()
	c.AssertFormat = true
	contract, err := c.Compile(filepath.Join(contractDir, schemaName))
	if err != nil {
		t.Fatal(err)
	}
	body, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	var decoded any
	if err = json.Unmarshal(body, &decoded); err != nil {
		t.Fatal(err)
	}
	if err = contract.Validate(decoded); err != nil {
		t.Fatalf("emitted %s: %v", schemaName, err)
	}
}
func expect(t *testing.T, r Result, code string) {
	t.Helper()
	validateOutput(t, "result.schema.json", r)
	if r.Outcome != code {
		t.Fatalf("want %s, got %+v", code, r)
	}
}
func TestSchemasAndFixtures(t *testing.T) {
	var manifest []struct {
		File   string `json:"file"`
		Schema string `json:"schema"`
		Valid  bool   `json:"valid"`
	}
	readJSON(t, "fixtures/manifest.json", &manifest)
	for _, f := range manifest {
		t.Run(f.File, func(t *testing.T) {
			c := schema.NewCompiler()
			c.AssertFormat = true
			s, e := c.Compile(filepath.Join(contractDir, f.Schema))
			if e != nil {
				t.Fatal(e)
			}
			var v any
			readJSON(t, "fixtures/"+f.File, &v)
			e = s.Validate(v)
			if (e == nil) != f.Valid {
				t.Fatalf("valid=%v: %v", f.Valid, e)
			}
		})
	}
	var a Action
	readJSON(t, "fixtures/action-admit.json", &a)
	if Fingerprint(a) != a.Fingerprint {
		t.Fatal("cross-language fingerprint mismatch")
	}
}
func TestFakeNormalizationAndRedaction(t *testing.T) {
	var expected Snapshot
	readJSON(t, "fixtures/observation-complete.json", &expected)
	b := true
	n := corev1.Node{ObjectMeta: metav1.ObjectMeta{Name: "node-fixture", UID: "node-uid", ResourceVersion: "21", Labels: map[string]string{"kubernetes.io/hostname": "node-fixture", "topology.kubernetes.io/zone": "zone-fixture", "unsafe": "SENSITIVE-SENTINEL"}, Annotations: map[string]string{"token": "SENSITIVE-SENTINEL"}}, Status: corev1.NodeStatus{Conditions: []corev1.NodeCondition{{Type: corev1.NodeReady, Status: corev1.ConditionTrue, Message: "SENSITIVE-SENTINEL"}}}}
	p := corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "pod-fixture", Namespace: "utcp-runtime", UID: "pod-uid", ResourceVersion: "31", Labels: map[string]string{"app.kubernetes.io/part-of": "utcp", "app.kubernetes.io/instance": "runtime-fixture-inc-a", "unsafe": "SENSITIVE-SENTINEL"}, Annotations: map[string]string{"token": "SENSITIVE-SENTINEL"}, OwnerReferences: []metav1.OwnerReference{{Kind: "ReplicaSet", Name: "rs-fixture", UID: "rs-uid", Controller: &b}}}, Spec: corev1.PodSpec{NodeName: "node-fixture", Containers: []corev1.Container{{Name: "fixture", Env: []corev1.EnvVar{{Name: "TOKEN", Value: "SENSITIVE-SENTINEL"}}}}}, Status: corev1.PodStatus{Phase: corev1.PodRunning, Conditions: []corev1.PodCondition{{Type: corev1.PodReady, Status: corev1.ConditionTrue}}}}
	client := fake.NewClientset(&n, &p, &corev1.Secret{ObjectMeta: metav1.ObjectMeta{Name: "unused", Namespace: "utcp-runtime"}, Data: map[string][]byte{"token": []byte("SENSITIVE-SENTINEL")}})
	nodes, e := client.CoreV1().Nodes().List(context.Background(), metav1.ListOptions{})
	if e != nil {
		t.Fatal(e)
	}
	pods, e := client.CoreV1().Pods("utcp-runtime").List(context.Background(), metav1.ListOptions{})
	if e != nil {
		t.Fatal(e)
	}
	ns, ps := Normalize(nodes.Items, pods.Items)
	if !reflect.DeepEqual(ns, expected.Nodes) || !reflect.DeepEqual(ps, expected.Pods) {
		t.Fatalf("normalization fixture mismatch: %+v %+v", ns, ps)
	}
	raw, _ := json.Marshal(struct {
		Nodes []NodeFact
		Pods  []PodFact
	}{ns, ps})
	if strings.Contains(string(raw), "SENSITIVE-SENTINEL") {
		t.Fatal("unsafe field escaped")
	}
	missing := p.DeepCopy()
	missing.Name = "a-pod"
	missing.UID = "a"
	missing.Spec.NodeName = ""
	missing.OwnerReferences = nil
	missing.Status.Conditions = nil
	_, ordered := Normalize(nodes.Items, []corev1.Pod{p, *missing})
	if ordered[0].Name != "a-pod" || ordered[0].NodeName != nil || ordered[0].Controller != nil || ordered[0].Ready != "Unknown" {
		t.Fatal("missing placement/ownership or order incorrect")
	}
	if ns[0].Topology.Region != nil {
		t.Fatal("missing topology invented")
	}
}
func clientFor(t *testing.T, s *httptest.Server) kubernetes.Interface {
	t.Helper()
	c, e := kubernetes.NewForConfig(&rest.Config{Host: s.URL, ContentConfig: rest.ContentConfig{ContentType: "application/json"}, QPS: 1000, Burst: 1000})
	if e != nil {
		t.Fatal(e)
	}
	return c
}
func status(w http.ResponseWriter, code int, reason string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(metav1.Status{TypeMeta: metav1.TypeMeta{APIVersion: "v1", Kind: "Status"}, Status: "Failure", Reason: metav1.StatusReason(reason), Code: int32(code), Message: "sanitized fixture rejection"})
}
func TestHTTPPaginationAndFailures(t *testing.T) {
	for _, mode := range []string{"complete", "forbidden", "expired", "repeated-token", "changed-rv", "duplicate", "malformed"} {
		t.Run(mode, func(t *testing.T) {
			var mu sync.Mutex
			calls := 0
			tokens := []string{}
			s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				mu.Lock()
				defer mu.Unlock()
				calls++
				if r.URL.Query().Get("limit") != "2" {
					t.Error("missing bounded page limit")
				}
				w.Header().Set("Content-Type", "application/json")
				if r.URL.Path == "/api/v1/nodes" {
					token := r.URL.Query().Get("continue")
					tokens = append(tokens, token)
					if token != "" && mode == "expired" {
						status(w, 410, "Expired")
						return
					}
					rv := "node-rv"
					next := ""
					items := []corev1.Node{}
					if token == "" {
						next = "opaque+/="
						items = append(items, corev1.Node{ObjectMeta: metav1.ObjectMeta{Name: "node-fixture", UID: "node-uid", ResourceVersion: "21"}})
					}
					if token == "" && mode == "duplicate" {
						items = append(items, items[0])
					}
					if token == "" && mode == "malformed" {
						items[0].UID = ""
					}
					if mode == "repeated-token" {
						next = "opaque+/="
					}
					if token != "" && mode == "changed-rv" {
						rv = "different"
					}
					_ = json.NewEncoder(w).Encode(corev1.NodeList{TypeMeta: metav1.TypeMeta{APIVersion: "v1", Kind: "NodeList"}, ListMeta: metav1.ListMeta{ResourceVersion: rv, Continue: next}, Items: items})
					return
				}
				if mode == "forbidden" {
					status(w, 403, "Forbidden")
					return
				}
				if r.URL.Path != "/api/v1/namespaces/utcp-runtime/pods" {
					t.Error("wrong namespace path")
				}
				_ = json.NewEncoder(w).Encode(corev1.PodList{TypeMeta: metav1.TypeMeta{APIVersion: "v1", Kind: "PodList"}, ListMeta: metav1.ListMeta{ResourceVersion: "pod-rv", Continue: func() string {
					if r.URL.Query().Get("continue") == "" {
						return "pod-token"
					}
					return ""
				}()}, Items: []corev1.Pod{}})
			}))
			defer s.Close()
			snapshot := Observe(context.Background(), clientFor(t, s), "request-observe", "cluster-fixture", "utcp-runtime")
			validateOutput(t, "observation.schema.json", snapshot)
			if mode == "complete" {
				if !snapshot.Complete || snapshot.NodesStatus.Pages != 2 || snapshot.PodsStatus.Pages != 2 || len(snapshot.Nodes) != 1 || snapshot.Pods == nil {
					t.Fatalf("incomplete %+v", snapshot)
				}
				if calls != 4 || len(tokens) != 2 || tokens[1] != "opaque+/=" {
					t.Fatal("continuation not propagated exactly")
				}
			} else {
				if snapshot.Complete || snapshot.Nodes != nil || snapshot.Pods != nil || snapshot.Error == nil {
					t.Fatalf("failure became empty healthy: %+v", snapshot)
				}
			}
		})
	}
}

// modelServer applies RFC6902 patches and models RV increment/rejection. This is
// protocol simulation, not proof of Kubernetes admission or storage behavior.
type modelServer struct {
	mu      sync.Mutex
	d       *appsv1.Deployment
	patches int
	hold    bool
	entered chan struct{}
	release chan struct{}
	lose    bool
	reject  bool
	invalid bool
}

func (m *modelServer) serve(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	if r.Method == "GET" {
		m.mu.Lock()
		defer m.mu.Unlock()
		_ = json.NewEncoder(w).Encode(m.d)
		return
	}
	if r.Method != "PATCH" || r.Header.Get("Content-Type") != "application/json-patch+json" {
		status(w, 400, "BadRequest")
		return
	}
	var raw json.RawMessage
	if e := json.NewDecoder(r.Body).Decode(&raw); e != nil {
		status(w, 400, "BadRequest")
		return
	}
	m.mu.Lock()
	hold := m.hold
	if hold {
		m.hold = false
	}
	m.mu.Unlock()
	if hold {
		close(m.entered)
		<-m.release
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.invalid {
		status(w, 422, "Invalid")
		return
	}
	if m.reject {
		status(w, 409, "Conflict")
		return
	}
	patch, e := jsonpatch.DecodePatch(raw)
	if e != nil {
		status(w, 422, "Invalid")
		return
	}
	before, _ := json.Marshal(m.d)
	after, e := patch.Apply(before)
	if e != nil {
		status(w, 422, "Invalid")
		return
	}
	var d appsv1.Deployment
	if e = json.Unmarshal(after, &d); e != nil {
		status(w, 422, "Invalid")
		return
	}
	m.patches++
	d.ResourceVersion = fmt.Sprint(100 + m.patches)
	m.d = &d
	if m.lose {
		m.lose = false
		conn, _, e := w.(http.Hijacker).Hijack()
		if e == nil {
			_ = conn.Close()
		}
		return
	}
	_ = json.NewEncoder(w).Encode(m.d)
}
func setup(t *testing.T, a Action) (Adapter, *modelServer) {
	t.Helper()
	m := &modelServer{d: deployment(a), entered: make(chan struct{}), release: make(chan struct{})}
	s := httptest.NewServer(http.HandlerFunc(m.serve))
	t.Cleanup(s.Close)
	return Adapter{Client: clientFor(t, s), ClusterID: "cluster-fixture", Authorize: func(context.Context, Action) bool { return true }}, m
}
func currentRV(m *modelServer) string { m.mu.Lock(); defer m.mu.Unlock(); return m.d.ResourceVersion }
func apply(t *testing.T, a Adapter, m *modelServer, q Action) Result {
	t.Helper()
	expect(t, a.Execute(context.Background(), q), "success")
	q.Action = "set_replicas"
	q.Target.ExpectedRV = currentRV(m)
	return a.Execute(context.Background(), q)
}
func TestRepeatedIntentUnknownOutcomeAndRedaction(t *testing.T) {
	q := action(t, "10")
	a, m := setup(t, q)
	expect(t, apply(t, a, m, q), "success")
	q.Action = "set_replicas"
	expect(t, a.Execute(context.Background(), q), "already_satisfied")
	if m.patches != 2 {
		t.Fatal("duplicate mutation")
	}
	// New generation: barrier commits; effect commits but transport response is lost.
	q = action(t, "11")
	q.Target.ExpectedRV = currentRV(m)
	expect(t, a.Execute(context.Background(), q), "success")
	q.Action = "set_replicas"
	q.Target.ExpectedRV = currentRV(m)
	m.mu.Lock()
	m.lose = true
	m.mu.Unlock()
	r := a.Execute(context.Background(), q)
	expect(t, r, "outcome_unknown")
	if r.Dispatch != "unknown" || r.Retry != "observe_first" {
		t.Fatal("unknown became failure")
	}
	r = a.Execute(context.Background(), q)
	expect(t, r, "already_satisfied")
	if m.patches != 4 {
		t.Fatal("unknown recovery blindly rewrote")
	}
	b, _ := json.Marshal(r)
	if strings.Contains(string(b), q.Claim.Token) || strings.Contains(string(b), "annotations") {
		t.Fatal("unsafe result")
	}
}
func TestIdentityOwnershipAndConflictFailClosed(t *testing.T) {
	for _, mode := range []string{"uid", "namespace", "cluster", "labels", "target", "rv", "api-conflict", "validation", "instance", "slug", "intent-hash", "hash"} {
		t.Run(mode, func(t *testing.T) {
			q := action(t, "10")
			a, m := setup(t, q)
			want := "ownership_conflict"
			switch mode {
			case "uid":
				q.Target.ExpectedUID = "wrong"
				want = "identity_mismatch"
			case "namespace":
				q.Target.Namespace = "other"
				want = "invalid_request"
			case "cluster":
				q.Target.ClusterID = "other"
				want = "identity_mismatch"
			case "labels":
				m.d.Labels["utcp.io/tenant-id"] = "other"
			case "target":
				q.Target.TargetID = "other"
			case "rv":
				q.Target.ExpectedRV = "99"
				want = "resource_version_conflict"
			case "instance":
				m.d.Labels["app.kubernetes.io/instance"] = "other"
			case "slug":
				m.d.Labels["utcp.dev/runtime-node"] = "other"
			case "intent-hash":
				m.d.Annotations[admitted] = q.Generation
				m.d.Annotations[admittedHash] = strings.Repeat("0", 64)
			case "validation":
				m.invalid = true
				want = "invalid_request"
			case "api-conflict":
				m.reject = true
				want = "resource_version_conflict"
			case "hash":
				q.Fingerprint = "bad"
				want = "invalid_request"
			}
			if mode != "hash" {
				q.Fingerprint = Fingerprint(q)
			}
			expect(t, a.Execute(context.Background(), q), want)
			if m.patches != 0 {
				t.Fatal("mismatched target mutated")
			}
		})
	}
}
func TestDelayedOldWriteAfterNewBarrier(t *testing.T) {
	q := action(t, "10")
	a, m := setup(t, q)
	expect(t, a.Execute(context.Background(), q), "success")
	q.Action = "set_replicas"
	q.Target.ExpectedRV = currentRV(m)
	m.mu.Lock()
	m.hold = true
	m.mu.Unlock()
	done := make(chan Result, 1)
	go func() { done <- a.Execute(context.Background(), q) }()
	<-m.entered
	newer := action(t, "11")
	newer.Replicas = 1
	newer.Fingerprint = Fingerprint(newer)
	newer.Target.ExpectedRV = currentRV(m)
	expect(t, apply(t, a, m, newer), "success")
	close(m.release)
	expect(t, <-done, "resource_version_conflict")
	expect(t, a.Execute(context.Background(), q), "stale_generation")
	if *m.d.Spec.Replicas != 1 || m.d.Annotations[applied] != "11" {
		t.Fatal("older effect overwrote newer")
	}
}
func TestLeaseExpiryBeforeDispatchAndUnchangedRV(t *testing.T) {
	q := action(t, "10")
	a, m := setup(t, q)
	q.Claim.Expires = time.Now().Add(-time.Second)
	expect(t, a.Execute(context.Background(), q), "stale_generation")
	if currentRV(m) != "100" || m.patches != 0 {
		t.Fatal("expired lease dispatched")
	}
	q = action(t, "10")
	a.Authorize = func(context.Context, Action) bool { return false }
	expect(t, a.Execute(context.Background(), q), "unauthorized")
}
func TestLeaseExpiryAfterDispatchBeforeNewBarrierMayApply(t *testing.T) {
	q := action(t, "10")
	a, m := setup(t, q)
	expect(t, a.Execute(context.Background(), q), "success")
	q.Action = "set_replicas"
	q.Target.ExpectedRV = currentRV(m)
	var clockMu sync.Mutex
	now := time.Now()
	a.Now = func() time.Time { clockMu.Lock(); defer clockMu.Unlock(); return now }
	m.mu.Lock()
	m.hold = true
	m.mu.Unlock()
	done := make(chan Result, 1)
	go func() { done <- a.Execute(context.Background(), q) }()
	<-m.entered
	clockMu.Lock()
	now = q.Claim.Expires.Add(time.Second)
	clockMu.Unlock()
	close(m.release)
	expect(t, <-done, "success")
	if *m.d.Spec.Replicas != 0 {
		t.Fatal("expected allowed stale effect before newer barrier")
	}
	if m.d.Annotations[applied] != "10" {
		t.Fatal("missing provenance")
	}
	newer := action(t, "11")
	newer.Replicas = 1
	newer.Fingerprint = Fingerprint(newer)
	newer.Target.ExpectedRV = currentRV(m)
	a.Now = nil
	expect(t, apply(t, a, m, newer), "success")
	if *m.d.Spec.Replicas != 1 {
		t.Fatal("new intent did not converge")
	}
}
func TestDuplicateWorkersSameIntent(t *testing.T) {
	q := action(t, "10")
	a, m := setup(t, q)
	var wg sync.WaitGroup
	results := make(chan Result, 2)
	for i := 0; i < 2; i++ {
		wg.Add(1)
		go func() { defer wg.Done(); results <- a.Execute(context.Background(), q) }()
	}
	wg.Wait()
	close(results)
	success := 0
	for r := range results {
		if r.Outcome == "success" {
			success++
		} else if r.Outcome != "resource_version_conflict" && r.Outcome != "already_satisfied" {
			t.Fatalf("unexpected %+v", r)
		}
	}
	if success != 1 || m.patches != 1 {
		t.Fatal("duplicate barrier committed")
	}
}
func TestCancellationTimeoutAndAPIUnavailable(t *testing.T) {
	q := action(t, "10")
	a, m := setup(t, q)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	expect(t, a.Execute(ctx, q), "cancelled")
	if m.patches != 0 {
		t.Fatal("cancelled dispatched")
	}
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { <-r.Context().Done() }))
	defer s.Close()
	a.Client = clientFor(t, s)
	q.Deadline = time.Now().Add(40 * time.Millisecond)
	expect(t, a.Execute(context.Background(), q), "timeout")
	unavailable := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { status(w, 503, "ServiceUnavailable") }))
	defer unavailable.Close()
	a.Client = clientFor(t, unavailable)
	q = action(t, "10")
	expect(t, a.Execute(context.Background(), q), "transport_unavailable")
	a.Client = fake.NewClientset()
	q = action(t, "10")
	expect(t, a.Execute(context.Background(), q), "target_not_found")
}
