package effects

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
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
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
	observer "utcp.invalid/components/kubernetes-observer"
)

const transportIdentity = "spiffe://utcp.invalid/laravel/executor"

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

func tlsServer(t *testing.T, p transportPKI, h http.Handler) (*httptest.Server, *observer.ServerTLSMaterial) {
	t.Helper()
	m, err := observer.NewServerTLS(p.server, p.serverKey, p.ca, transportIdentity)
	if err != nil {
		t.Fatal(err)
	}
	s := httptest.NewUnstartedServer(h)
	s.TLS = m.Config()
	s.Config.ErrorLog = log.New(io.Discard, "", 0)
	s.StartTLS()
	t.Cleanup(s.Close)
	return s, m
}
func tlsClient(t *testing.T, p transportPKI) *observer.RotatingHTTPClient {
	t.Helper()
	c, e := observer.NewHTTPClient(p.ca, p.client, p.clientKey, "observer.test")
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(c.CloseIdleConnections)
	return c
}
func validator(t *testing.T) *Validator {
	t.Helper()
	v, e := NewValidator(contracts)
	if e != nil {
		t.Fatal(e)
	}
	return v
}
func postAction(t *testing.T, c *http.Client, endpoint string, b []byte) (*http.Response, error) {
	t.Helper()
	q, e := http.NewRequest(http.MethodPost, endpoint+ActionPath, bytes.NewReader(b))
	if e != nil {
		t.Fatal(e)
	}
	q.Header.Set("Content-Type", "application/json")
	return c.Do(q)
}
func TestActionMTLSAndStrictWire(t *testing.T) {
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	v := validator(t)
	h, err := NewHandler(TransportConfig{transportIdentity, testEngine(t, m, verifyFunc(permit)), v})
	if err != nil {
		t.Fatal(err)
	}
	p := makeTransportPKI(t)
	s, material := tlsServer(t, p, h)
	client := tlsClient(t, p)
	b, _ := json.Marshal(a)
	resp, err := postAction(t, client.Client, s.URL, b)
	if err != nil {
		t.Fatal(err)
	}
	var result Result
	err = json.NewDecoder(resp.Body).Decode(&result)
	resp.Body.Close()
	if err != nil || resp.StatusCode != 200 || result.Outcome != "success" || v.ValidateResult(result) != nil {
		t.Fatalf("invalid successful transport: %+v %v", result, err)
	}
	for name, bad := range map[string][]byte{
		"duplicate": append([]byte(`{"action":"set_replicas",`), b[1:]...), "unsupported": bytes.Replace(b, []byte(`admit_generation`), []byte(`delete`), 1),
		"unknown": append([]byte(`{"token":"sensitive",`), b[1:]...), "null": []byte(`null`), "oversize": bytes.Repeat([]byte(" "), MaxRequestBytes+1), "trailing": append(append([]byte{}, b...), []byte(`{}`)...),
	} {
		t.Run(name, func(t *testing.T) {
			r, e := postAction(t, client.Client, s.URL, bad)
			if e != nil {
				t.Fatal(e)
			}
			defer r.Body.Close()
			raw, _ := io.ReadAll(r.Body)
			if r.StatusCode != 400 && r.StatusCode != 413 {
				t.Fatal(r.StatusCode)
			}
			if bytes.Contains(raw, []byte("sensitive")) {
				t.Fatal("raw input leaked")
			}
		})
	}
	if m.patches != 1 {
		t.Fatal("invalid request mutated")
	}
	// Rotation validation is atomic and retains the last good trust material.
	if material.Reload([]byte("invalid"), p.serverKey, p.ca, transportIdentity) == nil {
		t.Fatal("bad server reload accepted")
	}
	if client.Reload(p.ca, []byte("invalid"), p.clientKey, "observer.test") == nil {
		t.Fatal("bad client reload accepted")
	}
	client.CloseIdleConnections()
	resp, err = postAction(t, client.Client, s.URL, b)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != 200 {
		t.Fatal(resp.StatusCode)
	}
	for _, kind := range []string{"missing", "wrong-client", "untrusted", "expired", "wrong-server"} {
		t.Run(kind, func(t *testing.T) {
			q := p
			serverName := "observer.test"
			switch kind {
			case "missing":
				q.client = nil
				q.clientKey = nil
			case "wrong-client":
				q.client, q.clientKey = p.issue(t, false, "", "spiffe://utcp.invalid/laravel/observer", false)
			case "untrusted":
				q = makeTransportPKI(t)
			case "expired":
				q.client, q.clientKey = p.issue(t, false, "", transportIdentity, true)
			case "wrong-server":
				serverName = "wrong.test"
			}
			c, e := observer.NewHTTPClient(q.ca, q.client, q.clientKey, serverName)
			if e != nil {
				return
			}
			defer c.CloseIdleConnections()
			r, e := postAction(t, c.Client, s.URL, b)
			if e == nil {
				r.Body.Close()
				t.Fatal("untrusted caller connected")
			}
		})
	}
	// Defense in depth: matching URI without verified TLS chains is rejected.
	r := httptest.NewRequest("POST", ActionPath, bytes.NewReader(b))
	r.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 401 {
		t.Fatal(w.Code)
	}
}

func TestClaimHTTPFailClosedAndNoPositiveCache(t *testing.T) {
	a := fixtureAction(t)
	v := validator(t)
	p := makeTransportPKI(t)
	var calls atomic.Int32
	for name, c := range map[string]struct {
		body   string
		status int
		want   string
	}{
		"valid":     {`{"contract_version":"utcp.infrastructure.v1","authorized":true}`, 200, ""},
		"denied":    {`{"contract_version":"utcp.infrastructure.v1","authorized":false}`, 200, "unauthorized"},
		"duplicate": {`{"contract_version":"utcp.infrastructure.v1","authorized":true,"authorized":false}`, 200, "terminal_failure"},
		"extra":     {`{"contract_version":"utcp.infrastructure.v1","authorized":true,"secret":"sensitive"}`, 200, "terminal_failure"},
		"null":      {`{"contract_version":"utcp.infrastructure.v1","authorized":null}`, 200, "terminal_failure"},
		"version":   {`{"contract_version":"wrong","authorized":true}`, 200, "terminal_failure"},
		"oversize":  {strings.Repeat("x", (16<<10)+1), 200, "terminal_failure"},
		"401":       {"", 401, "unauthorized"}, "403": {"", 403, "forbidden"}, "outage": {"sensitive", 503, "transport_unavailable"}, "redirect": {"", 307, "transport_unavailable"},
	} {
		t.Run(name, func(t *testing.T) {
			s, _ := tlsServer(t, p, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				calls.Add(1)
				if r.URL.Path != ClaimPath || r.Method != "POST" {
					t.Error("unexpected callback")
				}
				raw, _ := io.ReadAll(r.Body)
				expected, _ := json.Marshal(a)
				if !bytes.Equal(raw, expected) {
					t.Error("incomplete claim binding")
				}
				w.Header().Set("Content-Type", "application/json")
				w.WriteHeader(c.status)
				_, _ = io.WriteString(w, c.body)
			}))
			ver, e := NewClaimVerifier(s.URL+ClaimPath, tlsClient(t, p), v)
			if e != nil {
				t.Fatal(e)
			}
			for i := 0; i < 2; i++ {
				err := ver.Verify(context.Background(), a)
				if c.want == "" {
					if err != nil {
						t.Fatal(err)
					}
				} else {
					var d *DecisionError
					if !errors.As(err, &d) || d.Outcome != c.want {
						t.Fatalf("got %v want %s", err, c.want)
					}
				}
			}
		})
	}
	if calls.Load() != 22 {
		t.Fatalf("positive authorization cached or callback retried: %d", calls.Load())
	}
	s, _ := tlsServer(t, p, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = io.Copy(io.Discard, r.Body); <-r.Context().Done() }))
	ver, _ := NewClaimVerifier(s.URL+ClaimPath, tlsClient(t, p), v)
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer cancel()
	err := ver.Verify(ctx, a)
	var d *DecisionError
	if !errors.As(err, &d) || d.Outcome != "timeout" {
		t.Fatal(err)
	}
	ctx, cancel = context.WithCancel(context.Background())
	cancel()
	err = ver.Verify(ctx, a)
	if !errors.As(err, &d) || d.Outcome != "cancelled" {
		t.Fatal(err)
	}
}

func TestActualPHPClaimRepliesOverMTLS(t *testing.T) {
	dir := os.Getenv("UTCP_GN_G2_OUTPUT")
	if dir == "" {
		t.Skip("run ./verify for actual PHP bridge export")
	}
	b, err := os.ReadFile(filepath.Join(dir, "php-proof.json"))
	if err != nil {
		t.Fatal(err)
	}
	var proof struct {
		Claims []struct {
			Name     string
			Action   Action
			Response json.RawMessage
		}
	}
	if json.Unmarshal(b, &proof) != nil || len(proof.Claims) != 14 {
		t.Fatal("missing PHP proof")
	}
	v := validator(t)
	p := makeTransportPKI(t)
	for _, c := range proof.Claims {
		t.Run(c.Name, func(t *testing.T) {
			s, _ := tlsServer(t, p, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				raw, _ := io.ReadAll(r.Body)
				var got Action
				_ = json.Unmarshal(raw, &got)
				if got != c.Action {
					t.Error("claim identity changed")
				}
				w.Header().Set("Content-Type", "application/json")
				_, _ = w.Write(c.Response)
			}))
			ver, err := NewClaimVerifier(s.URL+ClaimPath, tlsClient(t, p), v)
			if err != nil {
				t.Fatal(err)
			}
			err = ver.Verify(context.Background(), c.Action)
			var response struct{ Authorized bool }
			_ = json.Unmarshal(c.Response, &response)
			if (err == nil) != response.Authorized {
				t.Fatalf("PHP/Go claim parity failed: %s %v", c.Response, err)
			}
		})
	}
}
