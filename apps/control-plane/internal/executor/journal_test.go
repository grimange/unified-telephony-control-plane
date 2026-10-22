package executor

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

func fixture(t *testing.T) (Grant, []byte, GrantVerifier) {
	t.Helper()
	pub, priv, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	now := time.Now().UTC()
	g := Grant{ContractVersion: GrantVersion, Issuer: "canonical", KeyID: "canonical-key", Audience: "executor", Executor: "executor.test", GrantID: "grant", JournalID: "store-test", Endpoint: "https://127.0.0.1:1/effect", ServerName: "fixture.test", DispatchID: "dispatch", Phase: "consumed", IntentID: "intent", OperationID: "operation", Attempt: 1, TenantID: "tenant", RuntimeNodeID: "node", ClusterID: "cluster", Namespace: "namespace", Name: "physical", IncarnationID: "incarnation", ExpectedUID: "uid", Generation: "9007199254740993", Fingerprint: strings.Repeat("a", 64), Action: "set_replicas", InfrastructureVersion: "utcp.infrastructure.v1", EnvelopeHash: strings.Repeat("b", 64), IssuedAt: now, Deadline: now.Add(time.Minute)}
	g.BodyHash = Hash(SyntheticBody(g))
	s, e := SignGrant(g, priv)
	if e != nil {
		t.Fatal(e)
	}
	raw, _ := json.Marshal(s)
	return g, raw, GrantVerifier{Keys: map[string]GrantKey{"canonical-key": {Issuer: "canonical", PeerSAN: "canonical.test", PublicKey: pub, NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour)}}, Audience: "executor", Executor: "executor.test"}
}
func clientCertificate(t *testing.T) tls.Certificate {
	t.Helper()
	_, priv, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), DNSNames: []string{"executor.test"}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}
	der, e := x509.CreateCertificate(rand.Reader, tmpl, tmpl, priv.Public(), priv)
	if e != nil {
		t.Fatal(e)
	}
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: priv}
}
func newJournal(t *testing.T) (*Journal, string) {
	t.Helper()
	dir := filepath.Join(t.TempDir(), "journal")
	j, e := Init(dir, "store-test")
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { _ = j.Close() })
	return j, dir
}
func accept(t *testing.T, j *Journal) (Grant, []byte, GrantVerifier) {
	t.Helper()
	g, b, v := fixture(t)
	if _, e := j.Accept(context.Background(), b, v, "canonical.test"); e != nil {
		t.Fatal(e)
	}
	return g, b, v
}
func challenge(g Grant) kernel.DispatchEvidenceChallenge {
	now := time.Now().UTC()
	return kernel.DispatchEvidenceChallenge{ID: "challenge", Nonce: "nonce", Audience: "canonical", TenantID: g.TenantID, RuntimeNodeID: g.RuntimeNodeID, IncarnationID: g.IncarnationID, ClusterID: g.ClusterID, Namespace: g.Namespace, Name: g.Name, ExpectedUID: g.ExpectedUID, IntentID: g.IntentID, DispatchID: g.DispatchID, OperationID: g.OperationID, Executor: g.Executor, EnvelopeHash: g.EnvelopeHash, Generation: g.Generation, Fingerprint: g.Fingerprint, Action: g.Action, Attempt: g.Attempt, IssuedAt: now, ExpiresAt: now.Add(time.Minute)}
}
func TestGrantAuthentication(t *testing.T) {
	_, b, v := fixture(t)
	if _, e := v.Verify(b, "canonical.test"); e != nil {
		t.Fatal(e)
	}
	for _, name := range []string{"peer", "signature", "revoked", "audience", "unknown", "duplicate", "null"} {
		t.Run(name, func(t *testing.T) {
			x := append([]byte{}, b...)
			vv := v
			vv.Keys = map[string]GrantKey{}
			for k, a := range v.Keys {
				vv.Keys[k] = a
			}
			peer := "canonical.test"
			switch name {
			case "peer":
				peer = "attacker"
			case "signature":
				x[len(x)-5] ^= 1
			case "revoked":
				k := vv.Keys["canonical-key"]
				k.Revoked = true
				vv.Keys["canonical-key"] = k
			case "audience":
				vv.Audience = "wrong"
			case "unknown":
				x = append(x[:len(x)-1], []byte(`,"extra":true}`)...)
			case "duplicate":
				x = append(x[:len(x)-1], []byte(`,"signature":"x"}`)...)
			case "null":
				x = []byte(`{"payload":null,"signature":""}`)
			}
			if _, e := vv.Verify(x, peer); e == nil {
				t.Fatal("invalid grant accepted")
			}
		})
	}
}
func TestJournalIdentityAndIrreversibleClosure(t *testing.T) {
	j, dir := newJournal(t)
	g, b, v := accept(t, j)
	if _, e := j.Accept(context.Background(), b, v, "canonical.test"); e != nil {
		t.Fatal(e)
	}
	if _, e := j.CloseGrant(context.Background(), g.DispatchID); e != nil {
		t.Fatal(e)
	}
	other, e := Open(dir, "store-test")
	if e != nil {
		t.Fatal(e)
	}
	defer other.Close()
	if _, e = other.Submit(context.Background(), g.DispatchID, HTTPConfig{}); !errors.Is(e, ErrUncertain) {
		t.Fatalf("closed send %v", e)
	}
	if _, e = Open(dir, "wrong"); e == nil {
		t.Fatal("wrong identity")
	}
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	signed, e := other.Evidence(context.Background(), g.DispatchID, challenge(g), SigningIdentity{"executor", "key", priv})
	if e != nil || signed.Payload.Submissions != 0 || signed.Payload.EvidenceType != "definitive_non_delivery" {
		t.Fatalf("evidence: %+v %v", signed, e)
	}
	wrong := challenge(g)
	wrong.ExpectedUID = "replacement"
	if _, e = other.Evidence(context.Background(), g.DispatchID, wrong, SigningIdentity{"executor", "key", priv}); e == nil {
		t.Fatal("wrong UID evidence")
	}
}
func TestJournalMissingCorruptAndTruncated(t *testing.T) {
	for _, kind := range []string{"missing_head", "missing_wal", "partial", "prefix", "bitflip", "lock_replaced"} {
		t.Run(kind, func(t *testing.T) {
			j, dir := newJournal(t)
			g, _, _ := accept(t, j)
			if _, e := j.CloseGrant(context.Background(), g.DispatchID); e != nil {
				t.Fatal(e)
			}
			switch kind {
			case "missing_head":
				_ = os.Remove(filepath.Join(dir, "head"))
			case "missing_wal":
				_ = os.Remove(filepath.Join(dir, "wal"))
			case "partial":
				f, _ := os.OpenFile(filepath.Join(dir, "wal"), os.O_APPEND|os.O_WRONLY, 0600)
				_, _ = f.Write([]byte("partial"))
				_ = f.Close()
			case "prefix":
				b, _ := os.ReadFile(filepath.Join(dir, "wal"))
				p := strings.IndexByte(string(b), '\n')
				_ = os.WriteFile(filepath.Join(dir, "wal"), b[:p+1], 0600)
			case "bitflip":
				b, _ := os.ReadFile(filepath.Join(dir, "wal"))
				b[10] ^= 1
				_ = os.WriteFile(filepath.Join(dir, "wal"), b, 0600)
			case "lock_replaced":
				_ = os.Remove(filepath.Join(dir, "lock"))
				_ = os.WriteFile(filepath.Join(dir, "lock"), nil, 0600)
			}
			if _, e := j.Snapshot(context.Background(), g.DispatchID); e == nil {
				t.Fatal("bad storage accepted")
			}
			if kind != "lock_replaced" {
				if _, e := Open(dir, "store-test"); e == nil {
					t.Fatal("bad storage reopened")
				}
			}
		})
	}
}
func TestPausedSenderAfterClosure(t *testing.T) {
	j, dir := newJournal(t)
	g, _, _ := accept(t, j)
	other, e := Open(dir, "store-test")
	if e != nil {
		t.Fatal(e)
	}
	defer other.Close()
	paused, resume := make(chan struct{}), make(chan struct{})
	j.Hook = func(s string) {
		if s == "before_possible" {
			close(paused)
			<-resume
		}
	}
	done := make(chan error, 1)
	go func() { _, e := j.Submit(context.Background(), g.DispatchID, HTTPConfig{}); done <- e }()
	<-paused
	if _, e = other.CloseGrant(context.Background(), g.DispatchID); e != nil {
		t.Fatal(e)
	}
	close(resume)
	if e = <-done; !errors.Is(e, ErrUncertain) {
		t.Fatal(e)
	}
}
func TestConcurrentClosureAndDuplicateAcceptance(t *testing.T) {
	j, dir := newJournal(t)
	g, b, v := accept(t, j)
	var wg sync.WaitGroup
	for k := 0; k < 8; k++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			other, e := Open(dir, "store-test")
			if e != nil {
				t.Error(e)
				return
			}
			defer other.Close()
			if _, e = other.Accept(context.Background(), b, v, "canonical.test"); e != nil {
				t.Error(e)
			}
			if _, e = other.CloseGrant(context.Background(), g.DispatchID); e != nil {
				t.Error(e)
			}
		}()
	}
	wg.Wait()
}
func TestSyntheticAuthenticatedRejectionAndNoRetries(t *testing.T) {
	for _, kind := range []string{"rejection", "redirect", "unavailable", "uncorrelated", "malformed", "duplicate"} {
		t.Run(kind, func(t *testing.T) {
			var count atomic.Int32
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				count.Add(1)
				if kind == "redirect" {
					w.Header().Set("Location", "/other")
					w.WriteHeader(307)
					return
				}
				if kind == "malformed" {
					_, _ = w.Write([]byte("bad"))
					return
				}
				if kind == "duplicate" {
					w.WriteHeader(403)
					_, _ = w.Write([]byte(`{"kind":"Status","status":"Failure","code":500,"code":403,"reason":"Forbidden","dispatch_id":"dispatch","envelope_hash":"` + strings.Repeat("b", 64) + `","body_hash":"` + r.Header.Get("X-UTCP-Body-Hash") + `"}`))
					return
				}
				code, reason := 403, "Forbidden"
				if kind == "unavailable" {
					code, reason = 503, "ServiceUnavailable"
				}
				dispatch := r.Header.Get("X-UTCP-Dispatch-ID")
				if kind == "uncorrelated" {
					dispatch = "wrong"
				}
				w.WriteHeader(code)
				_ = json.NewEncoder(w).Encode(APIStatus{"Status", "Failure", code, reason, dispatch, r.Header.Get("X-UTCP-Envelope-Hash"), r.Header.Get("X-UTCP-Body-Hash")})
			}))
			defer server.Close()
			g, _, v := fixture(t)
			g.Endpoint = server.URL
			g.ServerName = "example.com"
			pub, priv, _ := ed25519.GenerateKey(rand.Reader)
			key := v.Keys["canonical-key"]
			key.PublicKey = pub
			v.Keys["canonical-key"] = key
			s, _ := SignGrant(g, priv)
			raw, _ := json.Marshal(s)
			j, _ := newJournal(t)
			if _, e := j.Accept(context.Background(), raw, v, "canonical.test"); e != nil {
				t.Fatal(e)
			}
			roots := x509.NewCertPool()
			roots.AddCert(server.Certificate())
			cfg := HTTPConfig{g.Endpoint, &tls.Config{RootCAs: roots, Certificates: []tls.Certificate{clientCertificate(t)}}, g.ServerName, time.Second}
			out, e := j.Submit(context.Background(), g.DispatchID, cfg)
			if kind == "rejection" {
				if e != nil || out.State != "api_rejected" {
					t.Fatalf("%+v %v", out, e)
				}
			} else if !errors.Is(e, ErrUncertain) {
				t.Fatalf("expected unknown: %+v %v", out, e)
			}
			if _, e = j.Submit(context.Background(), g.DispatchID, cfg); !errors.Is(e, ErrUncertain) {
				t.Fatal(e)
			}
			if count.Load() != 1 {
				t.Fatalf("submissions %d", count.Load())
			}
			if _, e = j.CloseGrant(context.Background(), g.DispatchID); kind != "rejection" && !errors.Is(e, ErrUncertain) {
				t.Fatal("possible closed as zero")
			}
		})
	}
}

func TestE4JournalCrashHelper(t *testing.T) {
	if os.Getenv("UTCP_E4_UNIT_HELPER") != "1" {
		t.Skip("subprocess helper")
	}
	dir := os.Getenv("UTCP_E4_UNIT_DIR")
	j, e := Open(dir, "store-test")
	if e != nil {
		os.Exit(4)
	}
	defer j.Close()
	barrier := os.Getenv("UTCP_E4_UNIT_BARRIER")
	j.Hook = func(s string) {
		if s == barrier {
			_ = os.WriteFile(filepath.Join(dir, "ready"), []byte(s), 0600)
			if os.Getenv("UTCP_E4_UNIT_RESUME") == "1" {
				for {
					if _, e := os.Stat(filepath.Join(dir, "release")); e == nil {
						break
					}
					time.Sleep(time.Millisecond)
				}
			} else {
				select {}
			}
		}
	}
	switch os.Getenv("UTCP_E4_UNIT_MODE") {
	case "evidence":
		entry, x := j.Snapshot(context.Background(), "dispatch")
		if x != nil {
			os.Exit(8)
		}
		_, key, _ := ed25519.GenerateKey(rand.Reader)
		_, e = j.Evidence(context.Background(), "dispatch", challenge(entry.Grant), SigningIdentity{"executor", "key", key})
	case "close":
		_, e = j.CloseGrant(context.Background(), "dispatch")
	default:
		econf := HTTPConfig{URL: "https://127.0.0.1:1/effect", TLS: &tls.Config{RootCAs: x509.NewCertPool(), Certificates: []tls.Certificate{clientCertificate(t)}}, ServerSAN: "fixture.test", Timeout: time.Second}
		if b, x := os.ReadFile(filepath.Join(dir, "http-config.json")); x == nil {
			var c childHTTPConfig
			if json.Unmarshal(b, &c) != nil {
				os.Exit(6)
			}
			pair, x := tls.LoadX509KeyPair(filepath.Join(dir, "client.pem"), filepath.Join(dir, "client-key.pem"))
			if x != nil {
				os.Exit(7)
			}
			roots := x509.NewCertPool()
			roots.AppendCertsFromPEM([]byte(c.CA))
			econf = HTTPConfig{URL: c.URL, ServerSAN: c.ServerName, TLS: &tls.Config{RootCAs: roots, Certificates: []tls.Certificate{pair}}, Timeout: 30 * time.Second}
		}
		_, e = j.Submit(context.Background(), "dispatch", econf)
	}
	if e != nil {
		_ = os.WriteFile(filepath.Join(dir, "helper-failure"), []byte(e.Error()), 0600)
		os.Exit(5)
	}
}
func TestActualSIGKILLJournalBoundaries(t *testing.T) {
	for _, spec := range []struct{ name, mode, want string }{{"before_wal_write", "send", "accepted"}, {"before_wal_sync", "send", "corrupt"}, {"after_wal_sync", "send", "corrupt"}, {"before_head_replace", "send", "corrupt"}, {"after_commit", "send", "possible"}, {"after_possible", "send", "possible"}, {"before_send", "send", "possible"}, {"before_closure", "close", "accepted"}, {"after_closure", "close", "closed"}} {
		t.Run(spec.name, func(t *testing.T) {
			j, dir := newJournal(t)
			_, _, _ = accept(t, j)
			cmd := exec.Command(os.Args[0], "-test.run=^TestE4JournalCrashHelper$")
			cmd.Env = []string{"UTCP_E4_UNIT_HELPER=1", "UTCP_E4_UNIT_DIR=" + dir, "UTCP_E4_UNIT_BARRIER=" + spec.name, "UTCP_E4_UNIT_MODE=" + spec.mode}
			if e := cmd.Start(); e != nil {
				t.Fatal(e)
			}
			defer func() { _ = cmd.Process.Kill(); _ = cmd.Wait() }()
			awaitReady(t, dir)
			if e := cmd.Process.Kill(); e != nil {
				t.Fatal(e)
			}
			_ = cmd.Wait()
			reopened, e := Open(dir, "store-test")
			if spec.want == "corrupt" {
				if e == nil {
					_ = reopened.Close()
					t.Fatal("torn commit reopened")
				}
				return
			}
			if e != nil {
				t.Fatal(e)
			}
			defer reopened.Close()
			entry, e := reopened.Snapshot(context.Background(), "dispatch")
			if e != nil || entry.State != spec.want {
				t.Fatalf("state %+v error %v", entry, e)
			}
			if entry.State == "possible" {
				if _, e = reopened.CloseGrant(context.Background(), "dispatch"); !errors.Is(e, ErrUncertain) {
					t.Fatal("possible lost after restart")
				}
			}
		})
	}
}

func TestAuthenticatedGrantCannotChangeStoreOrIdentity(t *testing.T) {
	for _, kind := range []string{"wrong_store", "conflicting_identity", "reserved_upgrade"} {
		t.Run(kind, func(t *testing.T) {
			j, _ := newJournal(t)
			g, _, v := fixture(t)
			pub, priv, _ := ed25519.GenerateKey(rand.Reader)
			k := v.Keys["canonical-key"]
			k.PublicKey = pub
			v.Keys["canonical-key"] = k
			if kind == "reserved_upgrade" {
				g.Phase = "reserved"
			}
			sign := func(g Grant) []byte { s, _ := SignGrant(g, priv); b, _ := json.Marshal(s); return b }
			if _, e := j.Accept(context.Background(), sign(g), v, "canonical.test"); e != nil {
				t.Fatal(e)
			}
			switch kind {
			case "wrong_store":
				other, e := Init(filepath.Join(t.TempDir(), "other"), "different-store")
				if e != nil {
					t.Fatal(e)
				}
				defer other.Close()
				if _, e = other.Accept(context.Background(), sign(g), v, "canonical.test"); e == nil {
					t.Fatal("grant admitted to different store")
				}
			case "conflicting_identity":
				g.ExpectedUID = "replacement"
				if _, e := j.Accept(context.Background(), sign(g), v, "canonical.test"); e == nil {
					t.Fatal("conflicting signed grant replaced history")
				}
			case "reserved_upgrade":
				g.Phase = "consumed"
				if _, e := j.Accept(context.Background(), sign(g), v, "canonical.test"); e == nil {
					t.Fatal("reserved grant upgraded in place")
				}
			}
		})
	}
}

type childHTTPConfig struct{ URL, ServerName, CA string }

func processFixture(t *testing.T) (*Journal, string, *atomic.Int32) {
	t.Helper()
	var count atomic.Int32
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		count.Add(1)
		w.WriteHeader(403)
		_ = json.NewEncoder(w).Encode(APIStatus{"Status", "Failure", 403, "Forbidden", r.Header.Get("X-UTCP-Dispatch-ID"), r.Header.Get("X-UTCP-Envelope-Hash"), r.Header.Get("X-UTCP-Body-Hash")})
	}))
	t.Cleanup(server.Close)
	g, _, v := fixture(t)
	g.Endpoint = server.URL
	g.ServerName = "example.com"
	// Process scheduling and durable filesystem synchronization under the whole-module
	// race suite need a larger fixture budget than a single in-process HTTP test.
	// This remains inside the production grant contract's five-minute maximum.
	g.Deadline = g.IssuedAt.Add(3 * time.Minute)
	pub, key, _ := ed25519.GenerateKey(rand.Reader)
	trusted := v.Keys["canonical-key"]
	trusted.PublicKey = pub
	v.Keys["canonical-key"] = trusted
	signed, _ := SignGrant(g, key)
	b, _ := json.Marshal(signed)
	j, dir := newJournal(t)
	if _, e := j.Accept(context.Background(), b, v, "canonical.test"); e != nil {
		t.Fatal(e)
	}
	pair := clientCertificate(t)
	private, e := x509.MarshalPKCS8PrivateKey(pair.PrivateKey)
	if e != nil {
		t.Fatal(e)
	}
	_ = os.WriteFile(filepath.Join(dir, "client.pem"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: pair.Certificate[0]}), 0600)
	_ = os.WriteFile(filepath.Join(dir, "client-key.pem"), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: private}), 0600)
	config, _ := json.Marshal(childHTTPConfig{g.Endpoint, g.ServerName, string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: server.Certificate().Raw}))})
	_ = os.WriteFile(filepath.Join(dir, "http-config.json"), config, 0600)
	return j, dir, &count
}
func helperProcess(t *testing.T, dir, mode, barrier string, resume bool) *exec.Cmd {
	t.Helper()
	cmd := exec.Command(os.Args[0], "-test.run=^TestE4JournalCrashHelper$")
	cmd.Env = []string{"UTCP_E4_UNIT_HELPER=1", "UTCP_E4_UNIT_DIR=" + dir, "UTCP_E4_UNIT_MODE=" + mode, "UTCP_E4_UNIT_BARRIER=" + barrier}
	if resume {
		cmd.Env = append(cmd.Env, "UTCP_E4_UNIT_RESUME=1")
	}
	if e := cmd.Start(); e != nil {
		t.Fatal(e)
	}
	return cmd
}
func awaitReady(t *testing.T, dir string) {
	t.Helper()
	// This is a subprocess scheduling budget, not a journal or HTTP timeout.
	// A fixed ten seconds was insufficient during simultaneous PostgreSQL fsync and
	// whole-module race tests; readiness still requires the exact durable hook.
	deadline := time.Now().Add(90 * time.Second)
	if suiteDeadline, ok := t.Deadline(); ok && suiteDeadline.Add(-time.Second).Before(deadline) {
		deadline = suiteDeadline.Add(-time.Second)
	}
	for {
		if _, e := os.Stat(filepath.Join(dir, "ready")); e == nil {
			return
		}
		if failure, e := os.ReadFile(filepath.Join(dir, "helper-failure")); e == nil {
			t.Fatalf("process exited before barrier: %s", failure)
		}
		if time.Now().After(deadline) {
			t.Fatal("process barrier timed out within bounded scheduling budget")
		}
		time.Sleep(time.Millisecond)
	}
}
func TestIndependentProcessStaleSenderClosure(t *testing.T) {
	_, dir, count := processFixture(t)
	a := helperProcess(t, dir, "send", "before_possible", true)
	defer func() { _ = a.Process.Kill() }()
	awaitReady(t, dir)
	b := helperProcess(t, dir, "close", "", false)
	if e := b.Wait(); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(filepath.Join(dir, "release"), nil, 0600); e != nil {
		t.Fatal(e)
	}
	if e := a.Wait(); e == nil {
		t.Fatal("stale sender unexpectedly reported success")
	}
	if count.Load() != 0 {
		t.Fatal("stale process submitted after closure")
	}
	reopened, e := Open(dir, "store-test")
	if e != nil {
		t.Fatal(e)
	}
	defer reopened.Close()
	entry, e := reopened.Snapshot(context.Background(), "dispatch")
	if e != nil || entry.State != "closed" {
		t.Fatalf("%+v %v", entry, e)
	}
}
func TestIndependentProcessesCompeteForOneSend(t *testing.T) {
	_, dir, count := processFixture(t)
	a := helperProcess(t, dir, "send", "", false)
	b := helperProcess(t, dir, "send", "", false)
	ea, eb := a.Wait(), b.Wait()
	if (ea == nil) == (eb == nil) {
		t.Fatalf("expected one success one refusal: %v %v", ea, eb)
	}
	if count.Load() != 1 {
		t.Fatalf("submissions %d", count.Load())
	}
}
func TestActualSIGKILLAfterHTTPAndSigning(t *testing.T) {
	for _, barrier := range []string{"after_response", "before_result", "after_result", "before_signature", "after_signature"} {
		t.Run(barrier, func(t *testing.T) {
			j, dir, count := processFixture(t)
			mode := "send"
			if strings.Contains(barrier, "signature") {
				if _, e := j.CloseGrant(context.Background(), "dispatch"); e != nil {
					t.Fatal(e)
				}
				mode = "evidence"
			}
			cmd := helperProcess(t, dir, mode, barrier, false)
			defer func() { _ = cmd.Process.Kill() }()
			awaitReady(t, dir)
			if e := cmd.Process.Kill(); e != nil {
				t.Fatal(e)
			}
			_ = cmd.Wait()
			reopened, e := Open(dir, "store-test")
			if e != nil {
				t.Fatal(e)
			}
			defer reopened.Close()
			entry, e := reopened.Snapshot(context.Background(), "dispatch")
			want := "possible"
			if barrier == "after_result" {
				want = "api_rejected"
			}
			if mode == "evidence" {
				want = "closed"
			}
			if e != nil || entry.State != want {
				t.Fatalf("%+v %v", entry, e)
			}
			if mode == "send" && count.Load() != 1 {
				t.Fatalf("submission count %d", count.Load())
			}
			_, key, _ := ed25519.GenerateKey(rand.Reader)
			_, e = reopened.Evidence(context.Background(), "dispatch", challenge(entry.Grant), SigningIdentity{"executor", "key", key})
			if want == "possible" {
				if !errors.Is(e, ErrUncertain) {
					t.Fatal("volatile response became evidence")
				}
			} else if e != nil {
				t.Fatal(e)
			}
		})
	}
}
func TestDurabilityFailurePreventsHTTP(t *testing.T) {
	j, dir, count := processFixture(t)
	j.Hook = func(s string) {
		if s == "before_wal_write" {
			_ = os.Remove(filepath.Join(dir, "head"))
			_ = os.Mkdir(filepath.Join(dir, "head"), 0700)
		}
	}
	entry, e := j.Snapshot(context.Background(), "dispatch")
	if e != nil {
		t.Fatal(e)
	}
	cfgBytes, _ := os.ReadFile(filepath.Join(dir, "http-config.json"))
	var c childHTTPConfig
	_ = json.Unmarshal(cfgBytes, &c)
	roots := x509.NewCertPool()
	roots.AppendCertsFromPEM([]byte(c.CA))
	_, e = j.Submit(context.Background(), entry.Grant.DispatchID, HTTPConfig{c.URL, &tls.Config{RootCAs: roots, Certificates: []tls.Certificate{clientCertificate(t)}}, c.ServerName, time.Second})
	if e == nil {
		t.Fatal("durability failure accepted")
	}
	if count.Load() != 0 {
		t.Fatal("HTTP preceded durable possible-send")
	}
}

func TestGrantRotationExpiryAndVersion(t *testing.T) {
	g, _, v := fixture(t)
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	old := v.Keys["canonical-key"]
	old.Revoked = true
	v.Keys["canonical-key"] = old
	v.Keys["rotated-key"] = GrantKey{Issuer: "canonical", PeerSAN: "canonical.test", PublicKey: pub, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour)}
	g.KeyID = "rotated-key"
	sign := func(g Grant) []byte { s, _ := SignGrant(g, priv); b, _ := json.Marshal(s); return b }
	if _, e := v.Verify(sign(g), "canonical.test"); e != nil {
		t.Fatal(e)
	}
	expired := g
	expired.Deadline = time.Now().Add(-time.Second)
	if _, e := v.Verify(sign(expired), "canonical.test"); e == nil {
		t.Fatal("expired grant accepted")
	}
	bad := g
	bad.InfrastructureVersion = "utcp.infrastructure.v3"
	if _, e := v.Verify(sign(bad), "canonical.test"); e == nil {
		t.Fatal("cross-version unknown action accepted")
	}
}
