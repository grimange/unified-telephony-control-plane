package integration_test

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/executor"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

// Exercise the producer independently through exported APIs. Authentic but
// unsupported responses and lost/late replies cannot become negative evidence.
func TestE4SyntheticHTTPResponseMatrix(t *testing.T) {
	cases := []struct {
		name       string
		code       int
		reason     string
		definitive bool
	}{
		{"success", 200, "", false},
		{"bad_request", 400, "BadRequest", true}, {"unauthorized", 401, "Unauthorized", true},
		{"forbidden", 403, "Forbidden", true}, {"not_found", 404, "NotFound", true},
		{"conflict", 409, "Conflict", true}, {"already_exists", 409, "AlreadyExists", true},
		{"invalid", 422, "Invalid", true}, {"rate_limited", 429, "TooManyRequests", false},
		{"internal_error", 500, "InternalError", false}, {"bad_gateway", 502, "BadGateway", false},
		{"unavailable", 503, "ServiceUnavailable", false}, {"unsupported_pair", 409, "Invalid", false},
		{"timeout", 403, "Forbidden", false}, {"oversized", 403, "Forbidden", false},
		{"wrong_identity", 403, "Forbidden", false}, {"untrusted_endpoint", 403, "Forbidden", false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ctx := context.Background()
			serverTLS, ca, clientPEM, clientKey := e4TLS(t)
			cert, e := tls.X509KeyPair(clientPEM, clientKey)
			must(t, e)
			roots := x509.NewCertPool()
			if !roots.AppendCertsFromPEM(ca) {
				t.Fatal("missing fixture CA")
			}
			var hits atomic.Int32
			var grant executor.Grant
			ready := make(chan struct{})
			fixture := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				<-ready
				hits.Add(1)
				if tc.name == "timeout" {
					// Reading the bounded fixture body permits net/http's connection
					// reader to detect cancellation while the handler withholds a reply.
					_, _ = io.Copy(io.Discard, io.LimitReader(r.Body, 32768))
					<-r.Context().Done()
					return
				}
				w.WriteHeader(tc.code)
				if tc.name == "oversized" {
					_, _ = w.Write([]byte(strings.Repeat("x", 4097)))
					return
				}
				status := executor.APIStatus{Kind: "Status", Status: "Failure", Code: tc.code, Reason: tc.reason, DispatchID: grant.DispatchID, EnvelopeHash: grant.EnvelopeHash, BodyHash: grant.BodyHash}
				if tc.name == "success" {
					status.Status = "Success"
				}
				if tc.name == "wrong_identity" {
					status.DispatchID = "another-dispatch"
				}
				_ = json.NewEncoder(w).Encode(status)
			}))
			fixture.TLS = serverTLS
			fixture.StartTLS()
			defer fixture.Close()
			pub, key, e := ed25519.GenerateKey(rand.Reader)
			must(t, e)
			now := time.Now().UTC()
			grant = executor.Grant{ContractVersion: executor.GrantVersion, Issuer: "matrix-kernel", KeyID: "matrix-key", Audience: "matrix-executor", Executor: "cp1-executor.test", GrantID: kernel.ID(), JournalID: "matrix-store", Endpoint: fixture.URL, ServerName: "cp1-kernel.test", DispatchID: kernel.ID(), Phase: "consumed", IntentID: "matrix-intent", OperationID: "matrix-operation", Attempt: 1, TenantID: "matrix-tenant", RuntimeNodeID: "matrix-node", ClusterID: "matrix-cluster", Namespace: "matrix-ns", Name: "matrix-deployment", IncarnationID: "matrix-incarnation", ExpectedUID: "matrix-uid", Generation: "9007199254740993", Fingerprint: strings.Repeat("b", 64), Action: "converge_projection", InfrastructureVersion: "utcp.infrastructure.v3", EnvelopeHash: strings.Repeat("a", 64), IssuedAt: now, Deadline: now.Add(time.Minute)}
			grant.BodyHash = executor.Hash(executor.SyntheticBody(grant))
			close(ready)
			signed, e := executor.SignGrant(grant, key)
			must(t, e)
			raw, e := json.Marshal(signed)
			must(t, e)
			j, e := executor.Init(filepath.Join(t.TempDir(), "journal"), grant.JournalID)
			must(t, e)
			defer j.Close()
			verifier := executor.GrantVerifier{Keys: map[string]executor.GrantKey{"matrix-key": {Issuer: grant.Issuer, PeerSAN: "cp1-kernel.test", PublicKey: pub, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour)}}, Audience: grant.Audience, Executor: grant.Executor}
			_, e = j.Accept(ctx, raw, verifier, "cp1-kernel.test")
			must(t, e)
			clientTLS := &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, Certificates: []tls.Certificate{cert}}
			if tc.name == "untrusted_endpoint" {
				clientTLS.RootCAs = x509.NewCertPool()
			}
			timeout := time.Second
			if tc.name == "timeout" {
				timeout = 250 * time.Millisecond
			}
			entry, e := j.Submit(ctx, grant.DispatchID, executor.HTTPConfig{URL: fixture.URL, TLS: clientTLS, ServerSAN: grant.ServerName, Timeout: timeout})
			if tc.name == "success" {
				must(t, e)
				if entry.State != "succeeded" {
					t.Fatal("successful response was not durably recorded")
				}
				c := kernel.DispatchEvidenceChallenge{ID: "success-challenge", Nonce: "success-nonce", Audience: "kernel", DispatchID: grant.DispatchID, OperationID: grant.OperationID, IntentID: grant.IntentID, Attempt: grant.Attempt, Executor: grant.Executor, TenantID: grant.TenantID, RuntimeNodeID: grant.RuntimeNodeID, ClusterID: grant.ClusterID, Namespace: grant.Namespace, Name: grant.Name, IncarnationID: grant.IncarnationID, ExpectedUID: grant.ExpectedUID, Generation: grant.Generation, Fingerprint: grant.Fingerprint, Action: grant.Action, EnvelopeHash: grant.EnvelopeHash, IssuedAt: time.Now().UTC(), ExpiresAt: time.Now().Add(time.Minute)}
				if _, x := j.Evidence(ctx, grant.DispatchID, c, executor.SigningIdentity{Issuer: "matrix-executor", KeyID: "matrix-evidence-key", PrivateKey: key}); !errors.Is(x, executor.ErrUncertain) {
					t.Fatalf("successful result fabricated nonapplication: %v", x)
				}
			} else if tc.definitive {
				must(t, e)
				if entry.State != "api_rejected" || entry.APICode != tc.code || entry.APIReason != tc.reason {
					t.Fatalf("wrong durable classification: %+v", entry)
				}
			} else {
				if !errors.Is(e, executor.ErrUncertain) || entry.State != "possible" {
					t.Fatalf("ambiguous response became terminal: state=%s error=%v", entry.State, e)
				}
			}
			_, _ = j.Submit(ctx, grant.DispatchID, executor.HTTPConfig{URL: fixture.URL, TLS: clientTLS, ServerSAN: grant.ServerName, Timeout: timeout})
			expected := int32(1)
			if tc.name == "untrusted_endpoint" {
				expected = 0
			}
			if hits.Load() != expected {
				t.Fatalf("automatic request count=%d want=%d", hits.Load(), expected)
			}
		})
	}
}
