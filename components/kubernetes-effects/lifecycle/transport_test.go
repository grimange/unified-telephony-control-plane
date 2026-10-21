package lifecycle

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
	"io"
	"log"
	"math/big"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
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
func TestLifecycleMTLS(t *testing.T) {
	p := makeTransportPKI(t)
	m := &memoryClient{}
	e := testEngine(t, m, nil)
	h, err := NewHandler(TransportConfig{transportIdentity, e})
	if err != nil {
		t.Fatal(err)
	}
	s, serverMaterial := tlsServer(t, p, h)
	c := tlsClient(t, p)
	a := sample()
	b, _ := json.Marshal(a)
	post := func(client *http.Client, data []byte) (int, error) {
		req, _ := http.NewRequest("POST", s.URL+ActionPath, bytes.NewReader(data))
		req.Header.Set("Content-Type", "application/json")
		response, e := client.Do(req)
		if e != nil {
			return 0, e
		}
		defer response.Body.Close()
		io.Copy(io.Discard, response.Body)
		return response.StatusCode, nil
	}
	if code, e := post(c.Client, b); e != nil || code != 200 {
		t.Fatal(code, e)
	}
	for _, name := range []string{"missing", "untrusted", "expired", "wrong-client", "wrong-server"} {
		t.Run(name, func(t *testing.T) {
			q := p
			dns := "observer.test"
			switch name {
			case "missing":
				q.client = nil
				q.clientKey = nil
			case "untrusted":
				q = makeTransportPKI(t)
			case "expired":
				q.client, q.clientKey = p.issue(t, false, "", transportIdentity, true)
			case "wrong-client":
				q.client, q.clientKey = p.issue(t, false, "", "spiffe://wrong.invalid/caller", false)
			case "wrong-server":
				dns = "wrong.test"
			}
			client, e := observer.NewHTTPClient(q.ca, q.client, q.clientKey, dns)
			if e != nil {
				if name == "missing" || name == "expired" {
					return
				}
				t.Fatal(e)
			}
			defer client.CloseIdleConnections()
			code, e := post(client.Client, b)
			if e == nil && code == 200 {
				t.Fatal("invalid identity accepted")
			}
		})
	}
	if serverMaterial.Reload([]byte("invalid"), p.serverKey, p.ca, transportIdentity) == nil {
		t.Fatal("accepted broken rotation")
	}
	if code, e := post(c.Client, b); e != nil || code != 200 {
		t.Fatal("failed rotation damaged old credentials", code, e)
	}
	for name, body := range map[string][]byte{"unknown": []byte(`{"action":"shell"}`), "oversize": []byte(strings.Repeat("x", MaxRequestBytes+1))} {
		t.Run(name, func(t *testing.T) {
			code, e := post(c.Client, body)
			if e != nil || code < 400 {
				t.Fatal(code, e)
			}
		})
	}
}
func TestLifecycleClaimCallback(t *testing.T) {
	p := makeTransportPKI(t)
	authorized := true
	calls := 0
	h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if r.URL.Path != ClaimPath {
			t.Error(r.URL.Path)
		}
		body, _ := io.ReadAll(r.Body)
		if _, err := Decode(body); err != nil {
			t.Error(err)
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"contract_version": Version, "authorized": authorized})
	})
	s, _ := tlsServer(t, p, h)
	v, err := NewClaimVerifier(s.URL+ClaimPath, tlsClient(t, p))
	if err != nil {
		t.Fatal(err)
	}
	a := sample()
	if err = v.Verify(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	authorized = false
	if err = v.Verify(context.Background(), a); err == nil || calls != 2 {
		t.Fatal("positive authorization cached")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err = v.Verify(ctx, a); err == nil {
		t.Fatal("cancel accepted")
	}
}
