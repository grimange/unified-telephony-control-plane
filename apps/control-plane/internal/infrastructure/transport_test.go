package infrastructure

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

type certFactory struct {
	ca   *x509.Certificate
	key  ed25519.PrivateKey
	pool *x509.CertPool
}

func newCA(t *testing.T) certFactory {
	t.Helper()
	pub, key, _ := ed25519.GenerateKey(rand.Reader)
	now := time.Now()
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "ephemeral test CA"}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	der, e := x509.CreateCertificate(rand.Reader, tmpl, tmpl, pub, key)
	if e != nil {
		t.Fatal(e)
	}
	ca, e := x509.ParseCertificate(der)
	if e != nil {
		t.Fatal(e)
	}
	pool := x509.NewCertPool()
	pool.AddCert(ca)
	return certFactory{ca, key, pool}
}
func (f certFactory) cert(t *testing.T, name string, client, expired bool) tls.Certificate {
	t.Helper()
	pub, key, _ := ed25519.GenerateKey(rand.Reader)
	serial, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	now := time.Now()
	end := now.Add(time.Hour)
	if expired {
		end = now.Add(-time.Minute)
	}
	usage := x509.ExtKeyUsageServerAuth
	if client {
		usage = x509.ExtKeyUsageClientAuth
	}
	tmpl := &x509.Certificate{SerialNumber: serial, DNSNames: []string{name}, NotBefore: now.Add(-time.Hour), NotAfter: end, KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{usage}}
	der, e := x509.CreateCertificate(rand.Reader, tmpl, f.ca, pub, f.key)
	if e != nil {
		t.Fatal(e)
	}
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key}
}

type backendFunc func(context.Context, string, DispatchRequest) (DispatchResponse, error)

func (f backendFunc) Dispatch(c context.Context, s string, q DispatchRequest) (DispatchResponse, error) {
	return f(c, s, q)
}
func requestBytes() []byte {
	b, _ := json.Marshal(DispatchRequest{ContractVersion: DispatchVersion, RequestID: "request1", Operation: "consume", ReservationID: "reserved1", Capability: "ephemeral-capability", Envelope: json.RawMessage(`{"action":"synthetic"}`), Deadline: time.Now().Add(5 * time.Second)})
	return b
}
func testServer(t *testing.T, f certFactory, b DispatchBackend) (*httptest.Server, *CertificateStore) {
	t.Helper()
	s := &CertificateStore{}
	if e := s.Replace(f.cert(t, "kernel.test", false, false), time.Now()); e != nil {
		t.Fatal(e)
	}
	srv := httptest.NewUnstartedServer(DispatchHandler(b, map[string]bool{"executor.test": true}, 1))
	srv.TLS = ServerTLS(s.GetCertificate, f.pool, map[string]bool{"executor.test": true})
	srv.StartTLS()
	t.Cleanup(srv.Close)
	return srv, s
}
func testClient(f certFactory, certs []tls.Certificate, name string) *http.Client {
	return &http.Client{Timeout: time.Second, Transport: &http.Transport{TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: f.pool, ServerName: name, Certificates: certs}, TLSHandshakeTimeout: time.Second, ResponseHeaderTimeout: time.Second}}
}
func post(client *http.Client, url string, b []byte) (int, string, error) {
	r, e := client.Post(url+"/internal/dispatch/v1", "application/json", bytes.NewReader(b))
	if e != nil {
		return 0, "", e
	}
	defer r.Body.Close()
	raw, e := io.ReadAll(r.Body)
	return r.StatusCode, string(raw), e
}

func TestMutualTLSAndRotation(t *testing.T) {
	f := newCA(t)
	var called atomic.Int32
	s, store := testServer(t, f, backendFunc(func(_ context.Context, peer string, q DispatchRequest) (DispatchResponse, error) {
		called.Add(1)
		if peer != "executor.test" {
			t.Error("untrusted peer")
		}
		return DispatchResponse{ReservationID: q.ReservationID, State: "dispatching", Granted: true, Retry: "never"}, nil
	}))
	good := testClient(f, []tls.Certificate{f.cert(t, "executor.test", true, false)}, "kernel.test")
	code, body, e := post(good, s.URL, requestBytes())
	if e != nil || code != 200 {
		t.Fatalf("valid mTLS: %d %s %v", code, body, e)
	}
	for _, tc := range []struct {
		name    string
		factory certFactory
		certs   []tls.Certificate
		server  string
	}{
		{"missing cert", f, nil, "kernel.test"},
		{"wrong client SAN", f, []tls.Certificate{f.cert(t, "other.test", true, false)}, "kernel.test"},
		{"expired cert", f, []tls.Certificate{f.cert(t, "executor.test", true, true)}, "kernel.test"},
		{"wrong server identity", f, []tls.Certificate{f.cert(t, "executor.test", true, false)}, "wrong.test"},
		{"untrusted CA", newCA(t), []tls.Certificate{f.cert(t, "executor.test", true, false)}, "kernel.test"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if _, _, e := post(testClient(tc.factory, tc.certs, tc.server), s.URL, requestBytes()); e == nil {
				t.Fatal("accepted invalid TLS")
			}
		})
	}
	before, _ := store.GetCertificate(nil)
	if store.Replace(f.cert(t, "kernel.test", false, true), time.Now()) == nil {
		t.Fatal("accepted expired rotation")
	}
	after, _ := store.GetCertificate(nil)
	if before != after {
		t.Fatal("failed rotation removed certificate")
	}
	mismatch := f.cert(t, "kernel.test", false, false)
	_, mismatch.PrivateKey, _ = ed25519.GenerateKey(rand.Reader)
	if store.Replace(mismatch, time.Now()) == nil {
		t.Fatal("accepted mismatched rotation")
	}
	if e := store.Replace(f.cert(t, "kernel.test", false, false), time.Now()); e != nil {
		t.Fatal(e)
	}
	if code, _, e := post(testClient(f, []tls.Certificate{f.cert(t, "executor.test", true, false)}, "kernel.test"), s.URL, requestBytes()); e != nil || code != 200 {
		t.Fatal("rotation rejected valid new connection", e)
	}
	if called.Load() != 2 {
		t.Fatalf("unauthorized dispatch reached backend: %d", called.Load())
	}
}
func TestDispatchValidationAndRedaction(t *testing.T) {
	f := newCA(t)
	var calls atomic.Int32
	s, _ := testServer(t, f, backendFunc(func(_ context.Context, _ string, _ DispatchRequest) (DispatchResponse, error) {
		calls.Add(1)
		return DispatchResponse{}, io.ErrUnexpectedEOF
	}))
	client := testClient(f, []tls.Certificate{f.cert(t, "executor.test", true, false)}, "kernel.test")
	duplicate := bytes.Replace(requestBytes(), []byte(`"request_id":"request1"`), []byte(`"request_id":"request1","request_id":"request2"`), 1)
	for _, raw := range [][]byte{duplicate, []byte(`{"contract_version":"utcp.dispatch.v1","request_id":"a","request_id":"b"}`), []byte(`{"unknown":"token-value"}`), bytes.Repeat([]byte("x"), 65537)} {
		code, body, e := post(client, s.URL, raw)
		if e != nil || code < 400 || bytes.Contains([]byte(body), []byte("token-value")) {
			t.Fatalf("invalid rejection %d %s %v", code, body, e)
		}
	}
	code, body, e := post(client, s.URL, requestBytes())
	if e != nil || code != 409 || body != "{\"error\":\"dispatch_rejected\"}" {
		t.Fatalf("unsafe backend error: %d %s %v", code, body, e)
	}
	if calls.Load() != 1 {
		t.Fatal("invalid JSON dispatched")
	}
}
func TestDispatchCancellationAndBackpressure(t *testing.T) {
	f := newCA(t)
	entered := make(chan struct{})
	cancelled := make(chan struct{})
	s, _ := testServer(t, f, backendFunc(func(ctx context.Context, _ string, _ DispatchRequest) (DispatchResponse, error) {
		close(entered)
		<-ctx.Done()
		close(cancelled)
		return DispatchResponse{}, ctx.Err()
	}))
	client := testClient(f, []tls.Certificate{f.cert(t, "executor.test", true, false)}, "kernel.test")
	ctx, cancel := context.WithCancel(context.Background())
	req, _ := http.NewRequestWithContext(ctx, "POST", s.URL+"/internal/dispatch/v1", bytes.NewReader(requestBytes()))
	req.Header.Set("Content-Type", "application/json")
	done := make(chan struct{})
	go func() {
		r, _ := client.Do(req)
		if r != nil {
			r.Body.Close()
		}
		close(done)
	}()
	<-entered
	if code, _, e := post(client, s.URL, requestBytes()); e != nil || code != 429 {
		t.Fatalf("no bounded admission: %d %v", code, e)
	}
	cancel()
	select {
	case <-cancelled:
	case <-time.After(time.Second):
		t.Fatal("backend context not cancelled")
	}
	<-done
}
