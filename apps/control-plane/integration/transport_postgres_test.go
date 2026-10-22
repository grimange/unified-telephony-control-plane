package integration_test

import (
	"bytes"
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
	"testing"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/app"
	infra "github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

func ephemeralTLS(t *testing.T) (*tls.Config, *tls.Config) {
	t.Helper()
	pub, key, e := ed25519.GenerateKey(rand.Reader)
	must(t, e)
	now := time.Now()
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "CP1 ephemeral integration CA"}, NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	der, e := x509.CreateCertificate(rand.Reader, ca, ca, pub, key)
	must(t, e)
	ca, e = x509.ParseCertificate(der)
	must(t, e)
	roots := x509.NewCertPool()
	roots.AddCert(ca)
	cert := func(name string, usage x509.ExtKeyUsage) tls.Certificate {
		p, k, e := ed25519.GenerateKey(rand.Reader)
		must(t, e)
		serial, e := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
		must(t, e)
		c := &x509.Certificate{SerialNumber: serial, DNSNames: []string{name}, NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{usage}}
		b, e := x509.CreateCertificate(rand.Reader, c, ca, p, key)
		must(t, e)
		return tls.Certificate{Certificate: [][]byte{b}, PrivateKey: k}
	}
	serverCert := cert("cp1-kernel.test", x509.ExtKeyUsageServerAuth)
	server := infra.ServerTLS(func(*tls.ClientHelloInfo) (*tls.Certificate, error) { return &serverCert, nil }, roots, map[string]bool{"cp1-executor.test": true})
	client := &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, ServerName: "cp1-kernel.test", Certificates: []tls.Certificate{cert("cp1-executor.test", x509.ExtKeyUsageClientAuth)}}
	return server, client
}
func TestPostgresMutualTLSServiceComposition(t *testing.T) {
	ctx, p, s := env(t)
	i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
	raw := projection(t, ctx, p, i)
	_, e := s.PersistIntent(ctx, raw)
	must(t, e)
	serverTLS, clientTLS := ephemeralTLS(t)
	srv := httptest.NewUnstartedServer(infra.DispatchHandler(app.Dispatch{Kernel: s}, map[string]bool{"cp1-executor.test": true}, 2))
	srv.TLS = serverTLS
	srv.StartTLS()
	t.Cleanup(srv.Close)
	client := &http.Client{Timeout: 5 * time.Second, Transport: &http.Transport{TLSClientConfig: clientTLS, TLSHandshakeTimeout: time.Second, ResponseHeaderTimeout: 3 * time.Second}}
	t.Cleanup(func() { client.CloseIdleConnections() })
	post := func(q infra.DispatchRequest) (int, infra.DispatchResponse) {
		t.Helper()
		q.ContractVersion = infra.DispatchVersion
		q.RequestID = kernel.ID()
		q.Deadline = time.Now().Add(10 * time.Second)
		b, e := json.Marshal(q)
		must(t, e)
		resp, e := client.Post(srv.URL+"/internal/dispatch/v1", "application/json", bytes.NewReader(b))
		must(t, e)
		defer resp.Body.Close()
		b, e = io.ReadAll(resp.Body)
		must(t, e)
		var out infra.DispatchResponse
		if resp.StatusCode == 200 {
			must(t, json.Unmarshal(b, &out))
		}
		return resp.StatusCode, out
	}
	cap := kernel.ID() + kernel.ID()
	code, r := post(infra.DispatchRequest{Operation: "reserve", Envelope: raw, Capability: cap})
	if code != 200 || r.State != "reserved" || !r.Granted {
		t.Fatalf("reserve over authenticated transport: %d %+v", code, r)
	}
	code, first := post(infra.DispatchRequest{Operation: "consume", ReservationID: r.ReservationID, Envelope: raw, Capability: cap})
	if code != 200 || !first.Granted {
		t.Fatalf("consume over authenticated transport: %d %+v", code, first)
	}
	code, second := post(infra.DispatchRequest{Operation: "consume", ReservationID: r.ReservationID, Envelope: raw, Capability: cap})
	if code != 200 || second.Granted || second.Retry != "observe_first" {
		t.Fatalf("lost-consume-response replay: %d %+v", code, second)
	}
	code, _ = post(infra.DispatchRequest{Operation: "result", ReservationID: r.ReservationID, Result: &infra.DispatchResult{Outcome: "applied"}})
	if code < 400 {
		t.Fatal("executor fabricated confirmed effect without receipt")
	}
	code, r = post(infra.DispatchRequest{Operation: "result", ReservationID: r.ReservationID, Result: &infra.DispatchResult{Outcome: "unknown"}})
	if code != 200 || r.State != "outcome_unknown" {
		t.Fatalf("uncertainty not durable through mTLS bridge: %d %+v", code, r)
	}
	noCert := clientTLS.Clone()
	noCert.Certificates = nil
	unauthorized := &http.Client{Timeout: time.Second, Transport: &http.Transport{TLSClientConfig: noCert}}
	defer unauthorized.CloseIdleConnections()
	if resp, e := unauthorized.Post(srv.URL+"/internal/dispatch/v1", "application/json", bytes.NewReader([]byte(`{}`))); e == nil {
		resp.Body.Close()
		t.Fatal("mTLS bridge accepted missing client identity")
	}
}
