package effects

import (
	"context"
	"encoding/json"
	"encoding/pem"
	"errors"
	jsonpatch "gopkg.in/evanphx/json-patch.v4"
	"io"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/client-go/util/flowcontrol"
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestClientPreservesStructuredKubernetesStatus(t *testing.T) {
	for _, method := range []string{http.MethodGet, http.MethodPatch} {
		t.Run(method, func(t *testing.T) {
			a := fixtureAction(t)
			c, _ := protocolClient(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				w.WriteHeader(http.StatusUnprocessableEntity)
				_ = json.NewEncoder(w).Encode(metav1.Status{
					TypeMeta: metav1.TypeMeta{Kind: "Status", APIVersion: "v1"},
					Status:   "Failure", Reason: metav1.StatusReasonInvalid, Code: 422,
					Message: "untrusted arbitrary text is not a classification source",
					Details: &metav1.StatusDetails{Group: "apps", Kind: "Deployment", Name: a.Target.Name,
						Causes: []metav1.StatusCause{{Type: metav1.CauseTypeFieldValueInvalid, Field: "spec.replicas", Message: "unsafe payload"}}},
				})
			}))
			var err error
			if method == http.MethodGet {
				_, err = c.Get(context.Background(), a.Target)
			} else {
				ctx, prepareErr := c.Prepare(context.Background(), a.Target)
				if prepareErr != nil {
					t.Fatal(prepareErr)
				}
				_, err = c.Patch(ctx, a.Target, []byte(`[]`))
			}
			var status apierrors.APIStatus
			if !errors.As(err, &status) {
				t.Fatalf("expected structured API Status, got %T", err)
			}
			s := status.Status()
			if s.Code != 422 || s.Reason != metav1.StatusReasonInvalid || s.Details == nil || len(s.Details.Causes) != 1 || s.Details.Causes[0].Type != metav1.CauseTypeFieldValueInvalid || s.Details.Causes[0].Field != "spec.replicas" {
				t.Fatal("server Status reason/causes were replaced by a generic HTTP error")
			}
		})
	}
}

func protocolClient(t *testing.T, h http.Handler) (*KubernetesClient, *httptest.Server) {
	t.Helper()
	s := httptest.NewTLSServer(h)
	t.Cleanup(s.Close)
	ca := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: s.Certificate().Raw})
	c, e := NewKubernetesClient(KubernetesConfig{URL: s.URL, CA: ca, Token: "sensitive-test-token", AllowedNamespaces: []string{"utcp-runtime"}})
	if e != nil {
		t.Fatal(e)
	}
	return c, s
}
func TestClientGoConditionalProtocol(t *testing.T) {
	a := fixtureAction(t)
	d := fixtureDeployment(a)
	var mu sync.Mutex
	var gets, patches int
	c, _ := protocolClient(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		if r.URL.Path != "/apis/apps/v1/namespaces/utcp-runtime/deployments/"+a.Target.Name || r.Header.Get("Authorization") != "Bearer sensitive-test-token" {
			t.Error("unexpected path/credentials")
		}
		w.Header().Set("Content-Type", "application/json")
		if r.Method == "GET" {
			gets++
			_ = json.NewEncoder(w).Encode(d)
			return
		}
		patches++
		if r.Method != "PATCH" || r.Header.Get("Content-Type") != "application/json-patch+json" {
			t.Error("wrong effect transport")
		}
		b, _ := io.ReadAll(r.Body)
		var ops []patchOp
		_ = json.Unmarshal(b, &ops)
		if len(ops) < 4 || ops[0].Path != "/metadata/uid" || ops[1].Path != "/metadata/resourceVersion" {
			t.Error("missing atomic predicates")
		}
		patch, err := jsonpatch.DecodePatch(b)
		raw, _ := json.Marshal(d)
		if err == nil {
			raw, err = patch.Apply(raw)
		}
		if err != nil {
			t.Error(err)
		}
		_ = json.Unmarshal(raw, d)
		d.ResourceVersion = "101"
		_ = json.NewEncoder(w).Encode(d)
	}))
	e := testEngine(t, c, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "success")
	if gets != 1 || patches != 1 || d.Annotations["unrelated"] != "preserved" {
		t.Fatal("protocol count or metadata")
	}
	// The narrow client refuses unprepared direct patches and wrong kinds/scopes.
	if _, err := c.Patch(context.Background(), a.Target, []byte(`[]`)); err == nil {
		t.Fatal("unprepared mutation")
	}
	wrong := a.Target
	wrong.Namespace = "other"
	if _, err := c.Get(context.Background(), wrong); err == nil {
		t.Fatal("namespace escape")
	}
	wrong = a.Target
	wrong.Kind = "Secret"
	if _, err := c.Get(context.Background(), wrong); err == nil {
		t.Fatal("kind escape")
	}
}
func TestProtocolUnknownOutcomeNoRetry(t *testing.T) {
	for _, apply := range []bool{true, false} {
		t.Run(map[bool]string{true: "applied", false: "not-applied"}[apply], func(t *testing.T) {
			a := effectAction(fixtureAction(t))
			d := fixtureDeployment(a)
			d.Annotations[AdmittedGeneration] = a.Generation
			d.Annotations[AdmittedIntent] = a.Fingerprint
			var patches atomic.Int32
			c, _ := protocolClient(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				if r.Method == "PATCH" {
					patches.Add(1)
					if apply {
						b, _ := io.ReadAll(r.Body)
						p, _ := jsonpatch.DecodePatch(b)
						raw, _ := json.Marshal(d)
						raw, _ = p.Apply(raw)
						_ = json.Unmarshal(raw, d)
						d.ResourceVersion = "101"
					}
					conn, _, err := w.(http.Hijacker).Hijack()
					if err == nil {
						_ = conn.Close()
					}
					return
				}
				_ = json.NewEncoder(w).Encode(d)
			}))
			e := testEngine(t, c, verifyFunc(permit))
			r := e.Execute(context.Background(), a)
			expectOutcome(t, e, r, "outcome_unknown")
			if patches.Load() != 1 {
				t.Fatal("automatic mutation retry")
			}
			want := "drift"
			if apply {
				want = "already_satisfied"
			}
			expectOutcome(t, e, e.Recover(context.Background(), a), want)
			if patches.Load() != 1 {
				t.Fatal("recovery wrote")
			}
		})
	}
}
func TestGeneric422RemainsValidationDespiteLaterChange(t *testing.T) {
	a := fixtureAction(t)
	d := fixtureDeployment(a)
	var patches atomic.Int32
	c, _ := protocolClient(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == "PATCH" {
			patches.Add(1)
			d.ResourceVersion = "101"
			w.WriteHeader(422)
			_ = json.NewEncoder(w).Encode(metav1.Status{TypeMeta: metav1.TypeMeta{Kind: "Status", APIVersion: "v1"}, Status: "Failure", Reason: metav1.StatusReasonInvalid, Message: "unrelated admission validation", Code: 422})
			return
		}
		_ = json.NewEncoder(w).Encode(d)
	}))
	e := testEngine(t, c, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "invalid_request")
	if patches.Load() != 1 {
		t.Fatal("retried422")
	}
}
func TestNoDispatchWhenRateBudgetWouldOutliveClaim(t *testing.T) {
	a := fixtureAction(t)
	a.Claim.Expires = time.Now().Add(80 * time.Millisecond)
	d := fixtureDeployment(a)
	var patches atomic.Int32
	c, _ := protocolClient(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == "PATCH" {
			patches.Add(1)
		}
		_ = json.NewEncoder(w).Encode(d)
	}))
	// First token serves GET. The effect token would arrive one second later.
	c.limiter = flowcontrol.NewTokenBucketRateLimiter(1, 1)
	var callbacks atomic.Int32
	e := testEngine(t, c, verifyFunc(func(context.Context, Action) error { callbacks.Add(1); return nil }))
	r := e.Execute(context.Background(), a)
	if r.Outcome != "timeout" && r.Outcome != "stale_generation" && r.Outcome != "transport_unavailable" {
		t.Fatal(r)
	}
	if patches.Load() != 0 || callbacks.Load() != 1 || r.Dispatch != "not_sent" {
		t.Fatal("expired after limiter dispatched", r)
	}
}
func TestTransportGuardAndTLSFailure(t *testing.T) {
	a := fixtureAction(t)
	var requests atomic.Int32
	c, s := protocolClient(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		_ = json.NewEncoder(w).Encode(fixtureDeployment(a))
	}))
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := c.Get(ctx, a.Target); err == nil {
		t.Fatal("cancelled read")
	}
	if requests.Load() != 0 {
		t.Fatal("cancelled dispatched")
	}
	badCA := []byte("invalid")
	if _, err := NewKubernetesClient(KubernetesConfig{URL: s.URL, CA: badCA, AllowedNamespaces: []string{"utcp-runtime"}}); err == nil {
		t.Fatal("invalid CA")
	}
	for _, url := range []string{"http://localhost", "https://user@localhost", "https://localhost/path", "https://localhost?x=1"} {
		if _, err := NewKubernetesClient(KubernetesConfig{URL: url, CA: pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: s.Certificate().Raw}), AllowedNamespaces: []string{"utcp-runtime"}}); err == nil {
			t.Fatal("invalid URL")
		}
	}
	guard := effectGuard{base: roundTripFunc(func(*http.Request) (*http.Response, error) {
		t.Error("guard bypass")
		return nil, errors.New("bypassed")
	}), host: "example.invalid", namespaces: map[string]bool{"utcp-runtime": true}}
	for _, path := range []string{"/api/v1/secrets", "/apis/apps/v1/namespaces/utcp-runtime/deployments/name/scale", "/apis/apps/v1/namespaces/other/deployments/name"} {
		r, _ := http.NewRequest("PATCH", "https://example.invalid"+path, nil)
		if _, err := guard.RoundTrip(r); err == nil {
			t.Fatal("unsafe path")
		}
	}
}

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func TestMalformedSuccessfulMutationResponseIsUnknown(t *testing.T) {
	a := fixtureAction(t)
	c, _ := protocolClient(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == "PATCH" {
			_, _ = io.WriteString(w, `{"kind":"Deployment","apiVersion":"apps/v1"}`)
			return
		}
		_ = json.NewEncoder(w).Encode(fixtureDeployment(a))
	}))
	e := testEngine(t, c, verifyFunc(permit))
	expectOutcome(t, e, e.Execute(context.Background(), a), "outcome_unknown")
}
