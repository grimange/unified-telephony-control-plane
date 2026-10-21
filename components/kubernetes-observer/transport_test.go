package observer

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"log"
	"math/big"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

const transportIdentity = "spiffe://utcp.invalid/laravel/observer"

type transportPKI struct {
	ca, server, serverKey, client, clientKey []byte
	root                                     *x509.Certificate
	key                                      *ecdsa.PrivateKey
}

func makeTransportPKI(t *testing.T) transportPKI {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "ephemeral UTCP test CA"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature}
	der, err := x509.CreateCertificate(rand.Reader, ca, ca, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	p := transportPKI{ca: pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), root: ca, key: key}
	p.server, p.serverKey = p.issue(t, true, "observer.test", "", false)
	p.client, p.clientKey = p.issue(t, false, "", transportIdentity, false)
	return p
}

func (p transportPKI) issue(t *testing.T, server bool, dns, identity string, expired bool) ([]byte, []byte) {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	if err != nil {
		t.Fatal(err)
	}
	cert := &x509.Certificate{SerialNumber: serial, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature}
	if server {
		cert.DNSNames = []string{dns}
		cert.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}
	} else {
		cert.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}
		if identity != "" {
			uri, err := url.Parse(identity)
			if err != nil {
				t.Fatal(err)
			}
			cert.URIs = []*url.URL{uri}
		}
	}
	if expired {
		cert.NotBefore = time.Now().Add(-2 * time.Hour)
		cert.NotAfter = time.Now().Add(-time.Hour)
	}
	der, err := x509.CreateCertificate(rand.Reader, cert, p.root, &key.PublicKey, p.key)
	if err != nil {
		t.Fatal(err)
	}
	priv, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: priv})
}

func transportRequest() Request {
	return Request{Version: Version, RequestID: "request-transport", ClusterID: "test-cluster", Scope: Scope{PodNamespaces: []string{"*"}}, Deadline: time.Now().Add(4 * time.Second).UTC()}
}
func transportBody(t *testing.T) []byte {
	t.Helper()
	b, err := json.Marshal(transportRequest())
	if err != nil {
		t.Fatal(err)
	}
	return b
}
func transportSnapshot(q Request) Snapshot {
	now := time.Now().UTC()
	rv := "rv-1"
	status := ListStatus{Complete: true, RV: &rv, Pages: 1, Started: now, Finished: now}
	return Snapshot{Version: Version, RequestID: q.RequestID, ClusterID: q.ClusterID, Complete: true, Started: now, Finished: now, NodesStatus: status, PodsStatus: status, Nodes: []NodeFact{}, Pods: []PodFact{}}
}
func transportHandler(t *testing.T, fn func(context.Context, Request) Snapshot) http.Handler {
	t.Helper()
	h, err := NewHandler(TransportConfig{ClusterID: "test-cluster", AllowedNamespaces: []string{"*"}, ClientIdentity: transportIdentity, Observe: fn})
	if err != nil {
		t.Fatal(err)
	}
	return h
}
func transportServer(t *testing.T, h http.Handler, m *ServerTLSMaterial) *httptest.Server {
	t.Helper()
	s := httptest.NewUnstartedServer(h)
	s.TLS = m.Config()
	s.Config.ErrorLog = log.New(io.Discard, "", 0)
	s.StartTLS()
	t.Cleanup(s.Close)
	return s
}
func transportCall(t *testing.T, client *http.Client, endpoint string, body []byte) (*http.Response, error) {
	t.Helper()
	r, err := http.NewRequest(http.MethodPost, endpoint+ObservationPath, bytes.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	r.Header.Set("Content-Type", "application/json")
	return client.Do(r)
}
func transportCheckStatus(t *testing.T, c *http.Client, endpoint string, want int) {
	t.Helper()
	resp, err := transportCall(t, c, endpoint, transportBody(t))
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != want {
		t.Fatalf("HTTP %d, want %d", resp.StatusCode, want)
	}
	_, _ = io.Copy(io.Discard, resp.Body)
}

func TestTransportStrictRequestDecoding(t *testing.T) {
	valid := string(transportBody(t))
	cases := map[string]string{
		"unknown field":       strings.Replace(valid, `"scope":`, `"token":"credential","scope":`, 1),
		"duplicate field":     strings.Replace(valid, `"cluster_id":`, `"request_id":"other","cluster_id":`, 1),
		"escaped duplicate":   strings.Replace(valid, `"cluster_id":`, `"request\u005fid":"other","cluster_id":`, 1),
		"nested duplicate":    strings.Replace(valid, `"pod_namespaces":["*"]`, `"pod_namespaces":["*"],"pod_namespaces":["*"]`, 1),
		"case-fold nested":    strings.Replace(valid, `pod_namespaces`, `POD_NAMESPACES`, 1),
		"extra value":         valid + `{}`,
		"extra array":         valid + `[]`,
		"null scope":          strings.Replace(valid, `{"pod_namespaces":["*"]}`, `null`, 1),
		"empty scope":         strings.Replace(valid, `{"pod_namespaces":["*"]}`, `{}`, 1),
		"null namespaces":     strings.Replace(valid, `["*"]`, `null`, 1),
		"multiple namespaces": strings.Replace(valid, `["*"]`, `["a","b"]`, 1),
		"bad namespace":       strings.Replace(valid, `["*"]`, `["../other"]`, 1),
		"wrong version":       strings.Replace(valid, Version, "utcp.infrastructure.v2", 1),
		"wrong id type":       strings.Replace(valid, `"request-transport"`, `12`, 1),
		"bad deadline":        strings.Replace(valid, `"deadline":"`, `"deadline":"invalid`, 1),
		"null required":       strings.Replace(valid, `"request-transport"`, `null`, 1),
		"invalid UTF8":        valid[:len(valid)-1] + string([]byte{0xff}) + "}",
		"oversized":           strings.Repeat(" ", MaxRequestBytes+1),
		"null object":         "null",
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := ParseObservationRequest([]byte(body)); err == nil {
				t.Fatalf("accepted malformed request: %s", name)
			}
		})
	}
	if _, err := ParseObservationRequest([]byte(valid)); err != nil {
		t.Fatal(err)
	}
}

func TestTransportMTLSAuthenticationAndServerVerification(t *testing.T) {
	p := makeTransportPKI(t)
	foreign := makeTransportPKI(t)
	m, err := NewServerTLS(p.server, p.serverKey, p.ca, transportIdentity)
	if err != nil {
		t.Fatal(err)
	}
	var calls atomic.Int32
	s := transportServer(t, transportHandler(t, func(ctx context.Context, q Request) Snapshot { calls.Add(1); return transportSnapshot(q) }), m)
	good, err := NewHTTPClient(p.ca, p.client, p.clientKey, "observer.test")
	if err != nil {
		t.Fatal(err)
	}
	defer good.CloseIdleConnections()
	transportCheckStatus(t, good.Client, s.URL, http.StatusOK)
	cases := []struct {
		name             string
		roots, cert, key []byte
		serverName       string
	}{
		{"untrusted server CA", foreign.ca, p.client, p.clientKey, "observer.test"},
		{"wrong server identity", p.ca, p.client, p.clientKey, "wrong.test"},
		{"untrusted client CA", p.ca, foreign.client, foreign.clientKey, "observer.test"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			c, err := NewHTTPClient(tc.roots, tc.cert, tc.key, tc.serverName)
			if err != nil {
				t.Fatal(err)
			}
			defer c.CloseIdleConnections()
			resp, err := transportCall(t, c.Client, s.URL, transportBody(t))
			if resp != nil {
				resp.Body.Close()
			}
			if err == nil {
				t.Fatal("TLS should reject")
			}
		})
	}
	t.Run("missing client certificate", func(t *testing.T) {
		pool, _ := checkedCAPool(p.ca)
		c := &http.Client{Timeout: time.Second, Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: pool, ServerName: "observer.test", MinVersion: tls.VersionTLS13}}}
		resp, err := transportCall(t, c, s.URL, transportBody(t))
		if resp != nil {
			resp.Body.Close()
		}
		if err == nil {
			t.Fatal("missing client certificate accepted")
		}
	})
	t.Run("wrong client role", func(t *testing.T) {
		cert, key := p.issue(t, false, "", "spiffe://utcp.invalid/laravel/executor", false)
		c, err := NewHTTPClient(p.ca, cert, key, "observer.test")
		if err != nil {
			t.Fatal(err)
		}
		defer c.CloseIdleConnections()
		resp, err := transportCall(t, c.Client, s.URL, transportBody(t))
		if resp != nil {
			resp.Body.Close()
		}
		if err == nil {
			t.Fatal("executor identity accepted")
		}
	})
	t.Run("expired certificate", func(t *testing.T) {
		cert, key := p.issue(t, false, "", transportIdentity, true)
		if _, err := NewHTTPClient(p.ca, cert, key, "observer.test"); err == nil {
			t.Fatal("expired material accepted")
		}
		pair, err := tls.X509KeyPair(cert, key)
		if err != nil {
			t.Fatal(err)
		}
		pool, _ := checkedCAPool(p.ca)
		c := &http.Client{Timeout: time.Second, Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: pool, ServerName: "observer.test", Certificates: []tls.Certificate{pair}, MinVersion: tls.VersionTLS13}}}
		resp, err := transportCall(t, c, s.URL, transportBody(t))
		if resp != nil {
			resp.Body.Close()
		}
		if err == nil {
			t.Fatal("expired certificate accepted at handshake")
		}
	})
	if calls.Load() != 1 {
		t.Fatalf("rejected peer reached observer: calls=%d", calls.Load())
	}
}

func TestTransportCertificateRotationRetainsLastGoodMaterial(t *testing.T) {
	a := makeTransportPKI(t)
	b := makeTransportPKI(t)
	m, err := NewServerTLS(a.server, a.serverKey, a.ca, transportIdentity)
	if err != nil {
		t.Fatal(err)
	}
	s := transportServer(t, transportHandler(t, func(ctx context.Context, q Request) Snapshot { return transportSnapshot(q) }), m)
	c, err := NewHTTPClient(a.ca, a.client, a.clientKey, "observer.test")
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	transportCheckStatus(t, c.Client, s.URL, http.StatusOK)
	for _, bad := range [][]byte{nil, []byte("interrupted PEM")} {
		if err := m.Reload(bad, a.serverKey, a.ca, transportIdentity); err == nil {
			t.Fatal("bad server rotation accepted")
		}
		if err := c.Reload(bad, a.client, a.clientKey, "observer.test"); err == nil {
			t.Fatal("bad client rotation accepted")
		}
		c.CloseIdleConnections()
		transportCheckStatus(t, c.Client, s.URL, http.StatusOK)
	}
	if err := m.Reload(a.server, b.serverKey, a.ca, transportIdentity); err == nil {
		t.Fatal("mixed-generation server key accepted")
	}
	if err := c.Reload(a.ca, a.client, b.clientKey, "observer.test"); err == nil {
		t.Fatal("mixed-generation client key accepted")
	}
	c.CloseIdleConnections()
	transportCheckStatus(t, c.Client, s.URL, http.StatusOK)
	if err := m.Reload(b.server, b.serverKey, b.ca, transportIdentity); err != nil {
		t.Fatal(err)
	}
	c.CloseIdleConnections()
	resp, err := transportCall(t, c.Client, s.URL, transportBody(t))
	if resp != nil {
		resp.Body.Close()
	}
	if err == nil {
		t.Fatal("old CA connection survived new handshake")
	}
	if err := c.Reload(b.ca, b.client, b.clientKey, "observer.test"); err != nil {
		t.Fatal(err)
	}
	transportCheckStatus(t, c.Client, s.URL, http.StatusOK)
	// Concurrent readers always see a complete immutable TLS state. This is
	// exercised under -race; no partial certificate/key/trust update is exposed.
	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := 0; j < 10; j++ {
				_ = m.Reload(b.server, b.serverKey, b.ca, transportIdentity)
				_ = c.Reload(b.ca, b.client, b.clientKey, "observer.test")
			}
		}()
	}
	for i := 0; i < 4; i++ {
		transportCheckStatus(t, c.Client, s.URL, http.StatusOK)
	}
	wg.Wait()
}

func authenticatedTransportRequest(t *testing.T, body []byte) *http.Request {
	t.Helper()
	r := httptest.NewRequest(http.MethodPost, "https://observer.test"+ObservationPath, bytes.NewReader(body))
	r.Header.Set("Content-Type", "application/json")
	uri, _ := url.Parse(transportIdentity)
	leaf := &x509.Certificate{URIs: []*url.URL{uri}}
	r.TLS = &tls.ConnectionState{VerifiedChains: [][]*x509.Certificate{{leaf}}, PeerCertificates: []*x509.Certificate{leaf}}
	return r
}

func TestTransportWireScopeAndBackpressure(t *testing.T) {
	var calls atomic.Int32
	h := transportHandler(t, func(ctx context.Context, q Request) Snapshot { calls.Add(1); return transportSnapshot(q) })
	body := transportBody(t)
	cases := []struct {
		name   string
		modify func(*http.Request)
		body   []byte
		want   int
	}{
		{"no TLS", func(r *http.Request) { r.TLS = nil }, body, 401},
		{"wrong role", func(r *http.Request) { r.TLS.PeerCertificates = nil }, body, 403},
		{"wrong method", func(r *http.Request) { r.Method = "GET" }, body, 405},
		{"actions forbidden", func(r *http.Request) { r.URL.Path = "/internal/infrastructure/v1/actions" }, body, 404},
		{"query forbidden", func(r *http.Request) { r.URL.RawQuery = "token=unsafe" }, body, 404},
		{"wrong media", func(r *http.Request) { r.Header.Set("Content-Type", "text/plain") }, body, 400},
		{"compressed body", func(r *http.Request) { r.Header.Set("Content-Encoding", "gzip") }, body, 400},
		{"wrong cluster", func(r *http.Request) {}, bytes.ReplaceAll(body, []byte("test-cluster"), []byte("other-cluster")), 403},
		{"wrong scope", func(r *http.Request) {}, bytes.ReplaceAll(body, []byte(`["*"]`), []byte(`["other"]`)), 403},
		{"oversized", func(r *http.Request) {}, bytes.Repeat([]byte(" "), MaxRequestBytes+1), 413},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			r := authenticatedTransportRequest(t, tc.body)
			tc.modify(r)
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			if w.Code != tc.want {
				t.Fatalf("status %d want %d", w.Code, tc.want)
			}
		})
	}
	if calls.Load() != 0 {
		t.Fatal("invalid requests reached acquisition")
	}
	started := make(chan struct{}, 4)
	release := make(chan struct{})
	h = transportHandler(t, func(ctx context.Context, q Request) Snapshot {
		started <- struct{}{}
		select {
		case <-release:
		case <-ctx.Done():
		}
		return transportSnapshot(q)
	})
	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func() { defer wg.Done(); h.ServeHTTP(httptest.NewRecorder(), authenticatedTransportRequest(t, body)) }()
	}
	for i := 0; i < 4; i++ {
		select {
		case <-started:
		case <-time.After(time.Second):
			t.Fatal("acquisition did not start")
		}
	}
	w := httptest.NewRecorder()
	h.ServeHTTP(w, authenticatedTransportRequest(t, body))
	if w.Code != 429 || w.Header().Get("Retry-After") != "1" {
		t.Fatalf("expected bounded 429, got %d", w.Code)
	}
	close(release)
	wg.Wait()
}

func TestTransportCancellationAndDisconnect(t *testing.T) {
	for _, disconnect := range []bool{false, true} {
		name := "cancel"
		if disconnect {
			name = "disconnect"
		}
		t.Run(name, func(t *testing.T) {
			p := makeTransportPKI(t)
			m, err := NewServerTLS(p.server, p.serverKey, p.ca, transportIdentity)
			if err != nil {
				t.Fatal(err)
			}
			started := make(chan struct{})
			stopped := make(chan struct{})
			s := transportServer(t, transportHandler(t, func(ctx context.Context, q Request) Snapshot {
				close(started)
				<-ctx.Done()
				close(stopped)
				out := transportSnapshot(q)
				out.Complete = false
				out.Nodes = nil
				out.Pods = nil
				out.Error = &ObservationError{Code: "cancelled", Retry: "backoff", Dispatch: "not_sent"}
				return out
			}), m)
			c, err := NewHTTPClient(p.ca, p.client, p.clientKey, "observer.test")
			if err != nil {
				t.Fatal(err)
			}
			defer c.CloseIdleConnections()
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			r, err := http.NewRequestWithContext(ctx, http.MethodPost, s.URL+ObservationPath, bytes.NewReader(transportBody(t)))
			if err != nil {
				t.Fatal(err)
			}
			r.Header.Set("Content-Type", "application/json")
			done := make(chan error, 1)
			go func() {
				resp, err := c.Client.Do(r)
				if resp != nil {
					resp.Body.Close()
				}
				done <- err
			}()
			select {
			case <-started:
			case <-time.After(time.Second):
				t.Fatal("request did not start")
			}
			if disconnect { // Closing the HTTP/1 connection is the real disconnect path.
				s.CloseClientConnections()
			} else {
				cancel()
			}
			select {
			case err := <-done:
				if err == nil {
					t.Fatal("interrupted request reported transport success")
				}
			case <-time.After(time.Second):
				t.Fatal("client failed to stop")
			}
			select {
			case <-stopped:
			case <-time.After(time.Second):
				t.Fatal("cancellation failed to reach collector")
			}
		})
	}
}

func TestTransportDeadlineBoundsAndRepeatedRequests(t *testing.T) {
	var calls int
	h := transportHandler(t, func(ctx context.Context, q Request) Snapshot {
		calls++
		deadline, ok := ctx.Deadline()
		if !ok || time.Until(deadline) > 5*time.Second {
			t.Fatal("unbounded acquisition context")
		}
		if deadline.After(q.Deadline) {
			t.Fatal("caller deadline was not clamped")
		}
		if q.RequestID == "expired" && !errors.Is(ctx.Err(), context.DeadlineExceeded) {
			t.Fatal("expired request dispatched with live context")
		}
		return transportSnapshot(q)
	})
	for i := 0; i < 2; i++ {
		q := transportRequest()
		q.Deadline = time.Now().Add(time.Hour)
		body, _ := json.Marshal(q)
		w := httptest.NewRecorder()
		h.ServeHTTP(w, authenticatedTransportRequest(t, body))
		if w.Code != 200 {
			t.Fatal(w.Code)
		}
	}
	q := transportRequest()
	q.RequestID = "expired"
	q.Deadline = time.Now().Add(-time.Second)
	body, _ := json.Marshal(q)
	h.ServeHTTP(httptest.NewRecorder(), authenticatedTransportRequest(t, body))
	if calls != 3 {
		t.Fatal("duplicate request ID incorrectly became cached observation")
	}
}

func TestTransportSchemaHooksAndResponseBound(t *testing.T) {
	var called bool
	cfg := TransportConfig{ClusterID: "test-cluster", AllowedNamespaces: []string{"*"}, ClientIdentity: transportIdentity, Observe: func(ctx context.Context, q Request) Snapshot { called = true; return transportSnapshot(q) }, ValidateRequest: func([]byte) error { return errors.New("schema failure") }}
	h, err := NewHandler(cfg)
	if err != nil {
		t.Fatal(err)
	}
	w := httptest.NewRecorder()
	h.ServeHTTP(w, authenticatedTransportRequest(t, transportBody(t)))
	if w.Code != 400 || called {
		t.Fatal("schema rejection did not precede acquisition")
	}
	cfg.ValidateRequest = nil
	cfg.ValidateResponse = func(Snapshot) error { return errors.New("schema failure") }
	h, err = NewHandler(cfg)
	if err != nil {
		t.Fatal(err)
	}
	w = httptest.NewRecorder()
	h.ServeHTTP(w, authenticatedTransportRequest(t, transportBody(t)))
	if w.Code != 503 || strings.Contains(w.Body.String(), "schema failure") {
		t.Fatal("invalid response escaped or leaked error")
	}
	cfg.ValidateResponse = nil
	cfg.Observe = func(ctx context.Context, q Request) Snapshot {
		s := transportSnapshot(q)
		s.RequestID = strings.Repeat("x", MaxResponseBytes+1)
		return s
	}
	h, err = NewHandler(cfg)
	if err != nil {
		t.Fatal(err)
	}
	w = httptest.NewRecorder()
	h.ServeHTTP(w, authenticatedTransportRequest(t, transportBody(t)))
	if w.Code != 503 || w.Body.Len() > 1024 {
		t.Fatal("oversized successful response escaped")
	}
}
