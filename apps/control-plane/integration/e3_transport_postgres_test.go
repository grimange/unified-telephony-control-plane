package integration_test

import (
	"bytes"
	"context"
	"crypto/tls"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

// This is test-only composition. No production receipt callback route exists.
func TestE3DispatchEvidenceMutualTLS(t *testing.T) {
	for _, executor := range []string{"cp1-executor.test", "another-authenticated-executor"} {
		t.Run(executor, func(t *testing.T) {
			ctx, p, s := env(t)
			i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
			raw := projection(t, ctx, p, i)
			in, e := s.PersistIntent(ctx, raw)
			must(t, e)
			req := kernel.ConsumeRequest{Envelope: raw, Executor: executor, Capability: kernel.ID() + kernel.ID()}
			r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: executor, Capability: req.Capability})
			must(t, e)
			req.ID = r.ID
			_, e = s.Consume(ctx, req)
			must(t, e)
			must(t, s.MarkUnknown(ctx, req.ID, executor))
			d := e3Dispatch{i, in, req}
			c := e3Challenge(t, ctx, s, d)
			ev := e3SignedEvidence(t, c, "definitive_non_delivery")
			serverTLS, clientTLS := ephemeralTLS(t)
			srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				b, e := io.ReadAll(http.MaxBytesReader(w, r.Body, 32768))
				if e != nil {
					http.Error(w, "rejected", 400)
					return
				}
				v := ev.Verifier
				v.PeerSAN = r.TLS.PeerCertificates[0].DNSNames[0]
				if e = s.AcceptDispatchEvidence(r.Context(), c.ID, b, v); e != nil {
					http.Error(w, "rejected", 403)
					return
				}
				w.WriteHeader(204)
			}))
			srv.TLS = serverTLS
			srv.StartTLS()
			defer srv.Close()
			transport := &http.Transport{TLSClientConfig: clientTLS}
			defer transport.CloseIdleConnections()
			client := &http.Client{Transport: transport, Timeout: 5 * time.Second}
			resp, e := client.Post(srv.URL, "application/json", bytes.NewReader(ev.raw(t)))
			must(t, e)
			resp.Body.Close()
			want := 204
			if executor != "cp1-executor.test" {
				want = 403
			}
			if resp.StatusCode != want {
				t.Fatalf("authenticated executor correlation got %d want %d", resp.StatusCode, want)
			}
			noCert := clientTLS.Clone()
			noCert.Certificates = []tls.Certificate{}
			badTransport := &http.Transport{TLSClientConfig: noCert}
			defer badTransport.CloseIdleConnections()
			bad := &http.Client{Transport: badTransport, Timeout: time.Second}
			resp, e = bad.Post(srv.URL, "application/json", bytes.NewReader(ev.raw(t)))
			if e == nil {
				resp.Body.Close()
				t.Fatal("mTLS admitted unauthenticated client")
			}
		})
	}
}
func TestE3EvidenceMalformedOversizedCancelled(t *testing.T) {
	ctx, p, s := env(t)
	d := e3Dispatched(t, ctx, p, s, false)
	c := e3Challenge(t, ctx, s, d)
	ev := e3SignedEvidence(t, c, "definitive_non_delivery")
	for _, raw := range [][]byte{[]byte("{"), []byte(strings.Repeat("x", 32769)), []byte(`{"payload":{},"unexpected":true}`)} {
		denied(t, s.AcceptDispatchEvidence(ctx, c.ID, raw, ev.Verifier))
	}
	cancelled, cancel := context.WithCancel(ctx)
	cancel()
	denied(t, s.AcceptDispatchEvidence(cancelled, c.ID, ev.raw(t), ev.Verifier))
	state, e := s.Status(ctx, d.Req.ID, d.Req.Executor)
	must(t, e)
	if state.State != "outcome_unknown" {
		t.Fatal("malformed/cancelled evidence altered state")
	}
}
