package observer

import (
	"context"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"testing"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/kubernetes/fake"
)

const contractDir = "../../docs/architecture/proposals/gn-g0/contracts/v1"

func validator(t testing.TB) *Validator {
	t.Helper()
	v, e := NewValidator(contractDir)
	if e != nil {
		t.Fatal(e)
	}
	return v
}
func request() Request {
	return Request{Version: Version, RequestID: "request-fixture", ClusterID: "cluster-fixture", Scope: Scope{[]string{"*"}}, Deadline: time.Now().Add(5 * time.Second)}
}
func observe(t testing.TB, c Collector, q Request) Snapshot {
	t.Helper()
	o, e := NewObserver(c, validator(t))
	if e != nil {
		t.Fatal(e)
	}
	s := o(context.Background(), q)
	if e = validator(t).ValidateSnapshot(s); e != nil {
		t.Fatal(e)
	}
	return s
}
func fixtureObjects(n int) (corev1.NodeList, corev1.PodList) {
	nodes := corev1.NodeList{TypeMeta: metav1.TypeMeta{APIVersion: "v1", Kind: "NodeList"}, ListMeta: metav1.ListMeta{ResourceVersion: "node-rv"}, Items: []corev1.Node{}}
	pods := corev1.PodList{TypeMeta: metav1.TypeMeta{APIVersion: "v1", Kind: "PodList"}, ListMeta: metav1.ListMeta{ResourceVersion: "pod-rv"}, Items: []corev1.Pod{}}
	for i := 0; i < 3; i++ {
		name := fmt.Sprintf("node-%d", i)
		nodes.Items = append(nodes.Items, corev1.Node{ObjectMeta: metav1.ObjectMeta{Name: name, UID: types.UID("uid-" + name), ResourceVersion: "1"}})
	}
	for i := 0; i < n; i++ {
		name := fmt.Sprintf("pod-%03d", i)
		pods.Items = append(pods.Items, corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: name, Namespace: "utcp-runtime", UID: types.UID("uid-" + name), ResourceVersion: "2"}, Spec: corev1.PodSpec{NodeName: "node-0"}, Status: corev1.PodStatus{Phase: corev1.PodRunning}})
	}
	return nodes, pods
}

type protocolServer struct {
	t        testing.TB
	nodes    corev1.NodeList
	pods     corev1.PodList
	requests atomic.Int64
	pages    atomic.Int64
	mu       sync.Mutex
	tokens   []string
	hook     func(http.ResponseWriter, *http.Request, int64) bool
}

func (p *protocolServer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	call := p.requests.Add(1)
	if r.Method != "GET" {
		p.t.Errorf("unexpected mutation %s", r.Method)
		w.WriteHeader(405)
		return
	}
	if p.hook != nil && p.hook(w, r, call) {
		return
	}
	token := r.URL.Query().Get("continue")
	p.mu.Lock()
	p.tokens = append(p.tokens, token)
	p.mu.Unlock()
	if token != "" {
		p.pages.Add(1)
	}
	start := 0
	if token != "" {
		if !strings.HasPrefix(token, "opaque: ") {
			http.Error(w, "invalid continuation", 400)
			return
		}
		var e error
		start, e = strconv.Atoi(strings.TrimPrefix(token, "opaque: "))
		if e != nil {
			http.Error(w, "invalid continuation", 400)
			return
		}
	}
	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	if limit < 1 {
		limit = 500
	}
	w.Header().Set("Content-Type", "application/json")
	if r.URL.Path == "/api/v1/nodes" {
		n := p.nodes
		end := min(start+limit, len(n.Items))
		if start > end {
			http.Error(w, "bad token", 400)
			return
		}
		n.Items = n.Items[start:end]
		n.Continue = ""
		if end < len(p.nodes.Items) {
			n.Continue = fmt.Sprintf("opaque: %d", end)
		}
		_ = json.NewEncoder(w).Encode(n)
		return
	}
	if r.URL.Path == "/api/v1/pods" || r.URL.Path == "/api/v1/namespaces/utcp-runtime/pods" {
		pds := p.pods
		end := min(start+limit, len(pds.Items))
		if start > end {
			http.Error(w, "bad token", 400)
			return
		}
		pds.Items = pds.Items[start:end]
		pds.Continue = ""
		if end < len(p.pods.Items) {
			pds.Continue = fmt.Sprintf("opaque: %d", end)
		}
		_ = json.NewEncoder(w).Encode(pds)
		return
	}
	p.t.Errorf("unexpected resource path %s", r.URL.Path)
	http.Error(w, "not allowed", 404)
}
func readerServer(t testing.TB, p http.Handler) (*KubernetesReader, *httptest.Server) {
	t.Helper()
	server := httptest.NewTLSServer(p)
	cert := server.Certificate()
	ca := pemCertificate(cert.Raw)
	r, e := NewKubernetesReader(UpstreamConfig{URL: server.URL, CAPEM: ca, Token: "ephemeral-test-token", Namespace: "*"})
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { r.Close(); server.Close() })
	return r, server
}
func TestPaginationCompleteAndDuplicateRequests(t *testing.T) {
	n, p := fixtureObjects(7)
	server := &protocolServer{t: t, nodes: n, pods: p}
	reader, _ := readerServer(t, server)
	c := Collector{Reader: reader, ClusterID: "cluster-fixture", Namespace: "*", PageSize: 2}
	for i := 0; i < 2; i++ {
		s := observe(t, c, request())
		if !s.Complete || len(s.Nodes) != 3 || len(s.Pods) != 7 || s.NodesStatus.Pages != 2 || s.PodsStatus.Pages != 4 || !Fresh(s, time.Now()) {
			t.Fatalf("bad snapshot %+v", s)
		}
	}
	if server.requests.Load() != 12 || server.pages.Load() != 8 {
		t.Fatal("request cost not explained by complete collections")
	}
}
func TestExpiredContinuationRestartsWholeSnapshot(t *testing.T) {
	for _, exhaust := range []bool{false, true} {
		t.Run(fmt.Sprint(exhaust), func(t *testing.T) {
			n, p := fixtureObjects(3)
			server := &protocolServer{t: t, nodes: n, pods: p}
			var expired atomic.Bool
			server.hook = func(w http.ResponseWriter, r *http.Request, _ int64) bool {
				if strings.Contains(r.URL.Path, "pods") && r.URL.Query().Get("continue") != "" && (exhaust || !expired.Swap(true)) {
					w.Header().Set("Content-Type", "application/json")
					w.WriteHeader(410)
					fmt.Fprint(w, `{"kind":"Status","apiVersion":"v1","reason":"Expired","code":410}`)
					return true
				}
				return false
			}
			reader, _ := readerServer(t, server)
			s := observe(t, Collector{Reader: reader, ClusterID: "cluster-fixture", Namespace: "*", PageSize: 2}, request())
			if exhaust {
				if s.Complete || s.Nodes != nil || s.Pods != nil || s.Error.Code != "retryable_failure" || server.requests.Load() != 8 {
					t.Fatalf("unbounded/reused acquisition %+v requests%d", s, server.requests.Load())
				}
			} else if !s.Complete || len(s.Pods) != 3 || server.requests.Load() != 8 {
				t.Fatal("restart must reacquire nodes and pods")
			}
		})
	}
}
func TestProtocolFailures(t *testing.T) {
	for _, tc := range []struct {
		name   string
		status int
		body   string
		code   string
	}{
		{"unauthorized", 401, `{"kind":"Status","apiVersion":"v1","reason":"Unauthorized","code":401}`, "unauthorized"},
		{"forbidden", 403, `{"kind":"Status","apiVersion":"v1","reason":"Forbidden","code":403}`, "forbidden"},
		{"busy", 429, `{"kind":"Status","apiVersion":"v1","reason":"TooManyRequests","code":429}`, "retryable_failure"},
		{"unavailable", 503, `{"kind":"Status","apiVersion":"v1","reason":"ServiceUnavailable","code":503}`, "retryable_failure"},
		{"missing", 404, `{"kind":"Status","apiVersion":"v1","reason":"NotFound","code":404}`, "target_not_found"},
		{"invalid-continuation", 400, `{"kind":"Status","apiVersion":"v1","reason":"BadRequest","code":400}`, "terminal_failure"},
		{"malformed", 200, `{"items":`, "terminal_failure"},
		{"wrong-kind", 200, `{"kind":"SecretList","apiVersion":"v1","items":[]}`, "terminal_failure"},
		{"empty-rv", 200, `{"kind":"NodeList","apiVersion":"v1","items":[]}`, "terminal_failure"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			r, _ := readerServer(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			s := observe(t, Collector{Reader: r, ClusterID: "cluster-fixture", Namespace: "*"}, request())
			if s.Complete || s.Nodes != nil || s.Pods != nil || s.Error == nil {
				t.Fatalf("failure became facts %+v", s)
			}
			if tc.code != "" && s.Error.Code != tc.code {
				t.Fatalf("got%s want%s", s.Error.Code, tc.code)
			}
		})
	}
}
func TestListIntegrityAndBounds(t *testing.T) {
	for _, mode := range []string{"changed-rv", "repeated-token", "duplicate-uid", "duplicate-name", "missing-uid", "missing-rv", "bad-name", "wrong-namespace", "page-limit", "object-limit", "projection-limit", "oversized-raw", "empty-continuation-page"} {
		t.Run(mode, func(t *testing.T) {
			n, p := fixtureObjects(3)
			srv := &protocolServer{t: t, nodes: n, pods: p}
			switch mode {
			case "duplicate-uid":
				srv.nodes.Items[1].UID = srv.nodes.Items[0].UID
			case "duplicate-name":
				srv.nodes.Items[1].Name = srv.nodes.Items[0].Name
			case "missing-uid":
				srv.nodes.Items[0].UID = ""
			case "missing-rv":
				srv.nodes.Items[0].ResourceVersion = ""
			case "bad-name":
				srv.nodes.Items[0].Name = "INVALID"
			case "wrong-namespace":
				srv.pods.Items[0].Namespace = ""
			}
			srv.hook = func(w http.ResponseWriter, r *http.Request, call int64) bool {
				if mode == "oversized-raw" {
					fmt.Fprint(w, strings.Repeat("x", (16<<20)+1))
					return true
				}
				if r.URL.Path == "/api/v1/nodes" && call == 2 && (mode == "changed-rv" || mode == "repeated-token") {
					l := srv.nodes
					l.Items = l.Items[2:]
					if mode == "changed-rv" {
						l.ResourceVersion = "different"
					} else {
						l.Continue = "opaque: 2"
					}
					_ = json.NewEncoder(w).Encode(l)
					return true
				}
				if mode == "empty-continuation-page" && call == 1 {
					l := srv.nodes
					l.Items = []corev1.Node{}
					l.Continue = "opaque: 0"
					_ = json.NewEncoder(w).Encode(l)
					return true
				}
				return false
			}
			r, _ := readerServer(t, srv)
			c := Collector{Reader: r, ClusterID: "cluster-fixture", Namespace: "*", PageSize: 2}
			switch mode {
			case "page-limit":
				c.MaxPages = 1
			case "object-limit":
				c.MaxObjects = 2
			case "projection-limit":
				c.MaxResponseBytes = 100
			}
			s := observe(t, c, request())
			if mode == "empty-continuation-page" {
				if !s.Complete || len(s.Nodes) != 3 {
					t.Fatal("empty page not followed")
				}
				return
			}
			if s.Complete || s.Nodes != nil || s.Pods != nil {
				t.Fatalf("invalid collection complete %+v", s)
			}
		})
	}
}

// fakeReader exposes no write methods even when backed by the client-go fake.
type fakeReader struct{ client *fake.Clientset }

func (f fakeReader) ListNodes(ctx context.Context, o metav1.ListOptions) (*corev1.NodeList, error) {
	n, e := f.client.CoreV1().Nodes().List(ctx, o)
	n.ResourceVersion = "nodes-rv"
	return n, e
}
func (f fakeReader) ListPods(ctx context.Context, ns string, o metav1.ListOptions) (*corev1.PodList, error) {
	p, e := f.client.CoreV1().Pods(ns).List(ctx, o)
	p.ResourceVersion = "pods-rv"
	return p, e
}
func TestFakeNormalizationAndProjection(t *testing.T) {
	n, p := fixtureObjects(1)
	n.Items[0].Labels = map[string]string{"private/token": "unsafe-marker"}
	n.Items[0].Annotations = map[string]string{"secret": "unsafe-marker"}
	n.Items[0].Status.Conditions = []corev1.NodeCondition{{Type: corev1.NodeReady, Status: corev1.ConditionTrue, Message: "unsafe-marker"}}
	n.Items[0].Spec.Taints = []corev1.Taint{{Key: "maintenance", Effect: corev1.TaintEffectNoSchedule, TimeAdded: &metav1.Time{Time: time.Now()}}}
	p.Items[0].Spec.Containers = []corev1.Container{{Name: "runtime", Env: []corev1.EnvVar{{Name: "TOKEN", Value: "unsafe-marker"}}}}
	p.Items[0].Labels = map[string]string{"utcp.dev/runtime-node": "runtime-fixture", "private/token": "unsafe-marker"}
	p.Items[0].OwnerReferences = []metav1.OwnerReference{{Kind: "ReplicaSet", Name: "rs-fixture", UID: "rs-uid", Controller: boolptr(true)}}
	f := fake.NewSimpleClientset(&n.Items[2], &n.Items[1], &n.Items[0], &p.Items[0], &corev1.Secret{ObjectMeta: metav1.ObjectMeta{Name: "secret", Namespace: "utcp-runtime"}, Data: map[string][]byte{"token": []byte("unsafe-marker")}})
	s := observe(t, Collector{Reader: fakeReader{f}, ClusterID: "cluster-fixture", Namespace: "*"}, request())
	if !s.Complete || s.Nodes[0].Ready != "True" || s.Nodes[1].Ready != "Unknown" || s.Nodes[0].Topology.Region != nil || s.Pods[0].Chain != "unverified" {
		t.Fatalf("wrong normalization %+v", s)
	}
	data, _ := json.Marshal(s)
	if strings.Contains(string(data), "unsafe-marker") || strings.Contains(string(data), "containers") {
		t.Fatal("sensitive data leaked")
	}
	for _, a := range f.Actions() {
		if a.GetVerb() != "list" || (a.GetResource().Resource != "nodes" && a.GetResource().Resource != "pods") {
			t.Fatal("unauthorized resource operation")
		}
	}
	ns, ps := Normalize([]corev1.Node{n.Items[2], n.Items[1], n.Items[0]}, p.Items)
	if !reflect.DeepEqual(s.Nodes, ns) || !reflect.DeepEqual(s.Pods, ps) {
		t.Fatal("nondeterministic output")
	}
}
func boolptr(b bool) *bool { return &b }
func TestFreshnessAndScope(t *testing.T) {
	n, p := fixtureObjects(0)
	srv := &protocolServer{t: t, nodes: n, pods: p}
	r, _ := readerServer(t, srv)
	c := Collector{Reader: r, ClusterID: "cluster-fixture", Namespace: "*"}
	s := observe(t, c, request())
	if !Fresh(s, time.Now()) || Fresh(s, time.Now().Add(301*time.Second)) || Fresh(s, s.Started.Add(-time.Second)) {
		t.Fatal("freshness budget ignored")
	}
	q := request()
	q.ClusterID = "other"
	if c.Observe(context.Background(), q).Error.Code != "invalid_request" {
		t.Fatal("cluster scope ignored")
	}
	q = request()
	q.Scope.PodNamespaces = []string{"another"}
	if c.Observe(context.Background(), q).Error.Code != "invalid_request" {
		t.Fatal("namespace scope ignored")
	}
}
func TestCancellationAndRecovery(t *testing.T) {
	n, p := fixtureObjects(2)
	srv := &protocolServer{t: t, nodes: n, pods: p}
	var slow atomic.Bool
	slow.Store(true)
	srv.hook = func(w http.ResponseWriter, r *http.Request, _ int64) bool {
		if slow.Load() {
			<-r.Context().Done()
			return true
		}
		return false
	}
	r, _ := readerServer(t, srv)
	c := Collector{Reader: r, ClusterID: "cluster-fixture", Namespace: "*"}
	q := request()
	q.Deadline = time.Now().Add(50 * time.Millisecond)
	s := observe(t, c, q)
	if s.Complete || s.Error.Code != "timeout" {
		t.Fatal(s.Error)
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if s = c.Observe(ctx, request()); s.Error.Code != "cancelled" {
		t.Fatal(s.Error)
	}
	slow.Store(false)
	if !observe(t, c, request()).Complete {
		t.Fatal("failed to recover")
	}
}
func TestUpstreamCredentialRotationAndReadOnlyGuard(t *testing.T) {
	n, p := fixtureObjects(1)
	srv := &protocolServer{t: t, nodes: n, pods: p}
	var token atomic.Value
	token.Store("ephemeral-test-token")
	srv.hook = func(w http.ResponseWriter, r *http.Request, _ int64) bool {
		if r.Header.Get("Authorization") != "Bearer "+token.Load().(string) {
			w.WriteHeader(401)
			return true
		}
		return false
	}
	r, s := readerServer(t, srv)
	cfg := UpstreamConfig{URL: s.URL, CAPEM: pemCertificate(s.Certificate().Raw), Token: "rotated-token", Namespace: "*"}
	token.Store("rotated-token")
	if e := r.Reload(cfg); e != nil {
		t.Fatal(e)
	}
	if !observe(t, Collector{Reader: r, ClusterID: "cluster-fixture", Namespace: "*"}, request()).Complete {
		t.Fatal("token rotation not active")
	}
	bad := cfg
	bad.CAPEM = []byte("invalid")
	if r.Reload(bad) == nil {
		t.Fatal("bad CA accepted")
	}
	if !observe(t, Collector{Reader: r, ClusterID: "cluster-fixture", Namespace: "*"}, request()).Complete {
		t.Fatal("bad reload destroyed last-good")
	}
	for _, path := range []string{"/api/v1/secrets", "/apis/apps/v1/namespaces/utcp-runtime/deployments"} {
		q, _ := http.NewRequest("GET", s.URL+path, nil)
		if _, e := r.RoundTrip(q); e == nil {
			t.Fatal("other kind permitted")
		}
	}
	q, _ := http.NewRequest("PATCH", s.URL+"/api/v1/nodes", nil)
	if _, e := r.RoundTrip(q); e == nil {
		t.Fatal("write permitted")
	}
	if _, e := NewKubernetesReader(UpstreamConfig{URL: "http://example.invalid", CAPEM: cfg.CAPEM, Token: "x", Namespace: "*"}); e == nil {
		t.Fatal("plaintext upstream")
	}
	// TLS trust failure yields incomplete unavailable facts, without raw errors.
	other := httptest.NewTLSServer(srv)
	defer other.Close()
	bad = cfg
	bad.URL = other.URL
	bad.CAPEM = makeTransportPKI(t).ca // httptest servers share their default certificate.
	reader, e := NewKubernetesReader(bad)
	if e != nil {
		t.Fatal(e)
	}
	defer reader.Close()
	if snap := observe(t, Collector{Reader: reader, ClusterID: "cluster-fixture", Namespace: "*"}, request()); snap.Complete {
		t.Fatal("TLS validation bypass")
	}
}

// Fixtures below exercise the real collector over HTTPS and are consumed by
// the separate PHP process. Only the intentional invalid-wire case is mutated.
func TestExportPHPParityAndMeasurements(t *testing.T) {
	output := os.Getenv("UTCP_GN_G1_OUTPUT")
	if output == "" || os.Getenv("UTCP_GN_G1_EXPORT") != "1" {
		t.Skip("set UTCP_GN_G1_OUTPUT to export parity evidence")
	}
	dirs, e := filepath.Glob("../../tests/gn-g1-observer/fixtures/*")
	if e != nil {
		t.Fatal(e)
	}
	sort.Strings(dirs)
	measurements := []map[string]any{}
	for _, dir := range dirs {
		if _, e = os.Stat(filepath.Join(dir, "nodes.json")); e != nil {
			continue
		}
		name := filepath.Base(dir)
		t.Run(name, func(t *testing.T) {
			var n corev1.NodeList
			var p corev1.PodList
			var cfg struct {
				PageSize                int64  `json:"page_size"`
				PodsStatus              int    `json:"pods_status"`
				NodesStatus             int    `json:"nodes_status"`
				AgeSeconds              int    `json:"age_seconds"`
				SnapshotContractVersion string `json:"snapshot_contract_version"`
			}
			read := func(name string, v any) {
				b, e := os.ReadFile(filepath.Join(dir, name))
				if e != nil {
					t.Fatal(e)
				}
				if e = json.Unmarshal(b, v); e != nil {
					t.Fatal(e)
				}
			}
			read("nodes.json", &n)
			read("pods.json", &p)
			read("scenario.json", &cfg)
			srv := &protocolServer{t: t, nodes: n, pods: p}
			srv.hook = func(w http.ResponseWriter, r *http.Request, _ int64) bool {
				code := cfg.PodsStatus
				if r.URL.Path == "/api/v1/nodes" {
					code = cfg.NodesStatus
				}
				if code != 0 {
					w.Header().Set("Content-Type", "application/json")
					w.WriteHeader(code)
					fmt.Fprintf(w, `{"kind":"Status","apiVersion":"v1","reason":"ServiceUnavailable","code":%d}`, code)
					return true
				}
				return false
			}
			reader, _ := readerServer(t, srv)
			c := Collector{Reader: reader, ClusterID: "cluster-fixture", Namespace: "*", PageSize: cfg.PageSize}
			observer, err := NewObserver(c, validator(t))
			if err != nil {
				t.Fatal(err)
			}
			runtime.GC()
			var before, after runtime.MemStats
			runtime.ReadMemStats(&before)
			var cpuBefore, cpuAfter syscall.Rusage
			_ = syscall.Getrusage(syscall.RUSAGE_SELF, &cpuBefore)
			start := time.Now()
			s := observer(context.Background(), request())
			elapsed := time.Since(start)
			_ = syscall.Getrusage(syscall.RUSAGE_SELF, &cpuAfter)
			runtime.ReadMemStats(&after)
			if err = validator(t).ValidateSnapshot(s); err != nil {
				t.Fatal(err)
			}
			if strings.HasPrefix(name, "performance-") && !s.Complete {
				t.Fatalf("measurement acquisition failed: %+v", s.Error)
			}
			if cfg.AgeSeconds != 0 {
				d := time.Duration(cfg.AgeSeconds) * time.Second
				s.Started = s.Started.Add(-d)
				s.Finished = s.Finished.Add(-d)
				s.NodesStatus.Started = s.NodesStatus.Started.Add(-d)
				s.NodesStatus.Finished = s.NodesStatus.Finished.Add(-d)
				s.PodsStatus.Started = s.PodsStatus.Started.Add(-d)
				s.PodsStatus.Finished = s.PodsStatus.Finished.Add(-d)
			}
			if cfg.SnapshotContractVersion != "" {
				s.Version = cfg.SnapshotContractVersion
			}
			path := filepath.Join(output, "scenarios", name)
			if e = os.MkdirAll(path, 0755); e != nil {
				t.Fatal(e)
			}
			b, _ := json.MarshalIndent(s, "", "  ")
			if e = os.WriteFile(filepath.Join(path, "snapshot.json"), append(b, '\n'), 0644); e != nil {
				t.Fatal(e)
			}
			cpu := func(r syscall.Rusage) int64 { return r.Utime.Nano() + r.Stime.Nano() }
			if strings.HasPrefix(name, "performance-") {
				measurements = append(measurements, map[string]any{"runtime_nodes": len(p.Items), "kubernetes_requests": srv.requests.Load(), "continuation_requests": srv.pages.Load(), "wall_ns": elapsed.Nanoseconds(), "process_cpu_ns": cpu(cpuAfter) - cpu(cpuBefore), "allocated_bytes": after.TotalAlloc - before.TotalAlloc, "heap_live_bytes": after.HeapAlloc, "process_max_rss_kib": cpuAfter.Maxrss, "nodes_pages": s.NodesStatus.Pages, "pods_pages": s.PodsStatus.Pages, "complete": s.Complete})
			}
		})
	}
	b, _ := json.MarshalIndent(measurements, "", "  ")
	if e = os.WriteFile(filepath.Join(output, "go-measurements.json"), append(b, '\n'), 0644); e != nil {
		t.Fatal(e)
	}
}

// Ensure imported platform helpers are meaningful across test build variants.
func pemCertificate(b []byte) []byte {
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: b})
}
