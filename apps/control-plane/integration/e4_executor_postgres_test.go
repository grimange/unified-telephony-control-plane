package integration_test

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
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

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/executor"
	infra "github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

// This composition is test-only. The child receives a synthetic grant and
// ephemeral transport/signing credentials, never database access or lease tokens.
type e4ProcessConfig struct {
	Journal, Store, CanonicalURL, EffectURL, Mode, Stage string
	CAPEM, CertificatePEM, PrivateKeyPEM                 []byte
	GrantKey, EvidenceKey                                []byte
}
type e4WireGrant struct {
	Raw json.RawMessage `json:"raw"`
}

func TestE4ExecutorProcess(t *testing.T) {
	if os.Getenv("UTCP_E4_EXECUTOR_CHILD") != "1" {
		t.Skip("executor subprocess entrypoint")
	}
	for _, item := range os.Environ() {
		name, _, _ := strings.Cut(item, "=")
		upper := strings.ToUpper(name)
		if strings.HasPrefix(upper, "DB_") || strings.Contains(upper, "POSTGRES") || strings.Contains(upper, "REDIS") || strings.Contains(upper, "KUBE") || strings.Contains(upper, "DSN") {
			t.Fatalf("executor received forbidden credential setting %s", name)
		}
	}
	var cfg e4ProcessConfig
	b, e := os.ReadFile(os.Getenv("UTCP_E4_EXECUTOR_CONFIG"))
	must(t, e)
	must(t, json.Unmarshal(b, &cfg))
	cert, e := tls.X509KeyPair(cfg.CertificatePEM, cfg.PrivateKeyPEM)
	must(t, e)
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(cfg.CAPEM) {
		t.Fatal("missing ephemeral CA")
	}
	tlsConfig := &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, ServerName: "cp1-kernel.test", Certificates: []tls.Certificate{cert}}
	tr := &http.Transport{TLSClientConfig: tlsConfig, DisableKeepAlives: true, ForceAttemptHTTP2: false}
	defer tr.CloseIdleConnections()
	client := &http.Client{Transport: tr, Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	get := func(path string, out any) {
		t.Helper()
		resp, e := client.Get(cfg.CanonicalURL + path)
		must(t, e)
		defer resp.Body.Close()
		if resp.StatusCode != 200 {
			t.Fatalf("canonical response %d", resp.StatusCode)
		}
		must(t, json.NewDecoder(io.LimitReader(resp.Body, 32768)).Decode(out))
	}
	var wire e4WireGrant
	get("/grant", &wire)
	var signed executor.SignedGrant
	must(t, json.Unmarshal(wire.Raw, &signed))
	v := executor.GrantVerifier{Keys: map[string]executor.GrantKey{"e4-kernel-key": {Issuer: "e4-kernel", PeerSAN: "cp1-kernel.test", PublicKey: ed25519.PublicKey(cfg.GrantKey), NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour)}}, Audience: "e4-synthetic-executor", Executor: "cp1-executor.test"}
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()
	var j *executor.Journal
	if cfg.Stage == "dispatch" {
		j, e = executor.Init(cfg.Journal, cfg.Store)
	} else {
		j, e = executor.Open(cfg.Journal, cfg.Store)
	}
	must(t, e)
	defer j.Close()
	// Every delivery (including a duplicate after restart) authenticates its raw
	// signed grant again; it cannot replace immutable journal metadata.
	_, e = j.Accept(ctx, wire.Raw, v, "cp1-kernel.test")
	must(t, e)
	if cfg.Stage == "dispatch" {
		switch cfg.Mode {
		case "pre_dispatch_rejected", "definitive_non_delivery":
			_, e = j.CloseGrant(ctx, signed.Payload.DispatchID)
			must(t, e)
		default:
			_, e = j.Submit(ctx, signed.Payload.DispatchID, executor.HTTPConfig{URL: cfg.EffectURL, TLS: tlsConfig, ServerSAN: "cp1-kernel.test", Timeout: 30 * time.Second})
			if cfg.Mode == "ambiguous" {
				if e == nil {
					t.Fatal("lost response did not preserve uncertainty")
				}
			} else if e != nil {
				// Distinguish the deliberately bounded fixture budget from a
				// durable protocol failure; never reinterpret uncertainty as success.
				snapshot, snapshotErr := j.Snapshot(context.Background(), signed.Payload.DispatchID)
				t.Fatalf("synthetic dispatch: %v; helper context=%v; durable state=%s; snapshot=%v", e, ctx.Err(), snapshot.State, snapshotErr)
			}
		}
		fmt.Println("E4 durable fact committed; executor credential allowlist verified")
		return
	}
	// A new process reconstructs the fact before requesting a fresh challenge.
	// Signing time is after the challenge, never copied from old response times.
	var c kernel.DispatchEvidenceChallenge
	get("/challenge", &c)
	evidence, e := j.Evidence(ctx, signed.Payload.DispatchID, c, executor.SigningIdentity{Issuer: "e4-executor", KeyID: "e4-executor-key", PrivateKey: ed25519.PrivateKey(cfg.EvidenceKey)})
	if cfg.Mode == "ambiguous" {
		if e == nil {
			t.Fatal("uncertain dispatch fabricated negative evidence")
		}
		fmt.Println("E4 unresolved dispatch preserved after process restart")
		return
	}
	must(t, e)
	body, e := json.Marshal(evidence)
	must(t, e)
	resp, e := client.Post(cfg.CanonicalURL+"/evidence", "application/json", bytes.NewReader(body))
	must(t, e)
	defer resp.Body.Close()
	if resp.StatusCode != 204 {
		t.Fatalf("canonical evidence rejection %d", resp.StatusCode)
	}
	// Byte-identical receipt replay is idempotent. It does not create another
	// dispatch grant or another canonical transition.
	resp2, e := client.Post(cfg.CanonicalURL+"/evidence", "application/json", bytes.NewReader(body))
	must(t, e)
	resp2.Body.Close()
	if resp2.StatusCode != 204 {
		t.Fatalf("idempotent evidence replay %d", resp2.StatusCode)
	}
	fmt.Println("E4 durable evidence accepted over mTLS after executor restart")
}

func e4TLS(t *testing.T) (*tls.Config, []byte, []byte, []byte) {
	t.Helper()
	now := time.Now()
	pub, key, e := ed25519.GenerateKey(rand.Reader)
	must(t, e)
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "E4 ephemeral fixture CA"}, NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	der, e := x509.CreateCertificate(rand.Reader, ca, ca, pub, key)
	must(t, e)
	ca, e = x509.ParseCertificate(der)
	must(t, e)
	roots := x509.NewCertPool()
	roots.AddCert(ca)
	caPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
	leaf := func(name string, usage x509.ExtKeyUsage) (tls.Certificate, []byte, []byte) {
		p, k, e := ed25519.GenerateKey(rand.Reader)
		must(t, e)
		serial, e := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
		must(t, e)
		c := &x509.Certificate{SerialNumber: serial, DNSNames: []string{name}, NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{usage}}
		b, e := x509.CreateCertificate(rand.Reader, c, ca, p, key)
		must(t, e)
		pk, e := x509.MarshalPKCS8PrivateKey(k)
		must(t, e)
		cp := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: b})
		kp := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: pk})
		crt, e := tls.X509KeyPair(cp, kp)
		must(t, e)
		return crt, cp, kp
	}
	server, _, _ := leaf("cp1-kernel.test", x509.ExtKeyUsageServerAuth)
	_, clientPEM, clientKey := leaf("cp1-executor.test", x509.ExtKeyUsageClientAuth)
	serverTLS := infra.ServerTLS(func(*tls.ClientHelloInfo) (*tls.Certificate, error) { return &server, nil }, roots, map[string]bool{"cp1-executor.test": true})
	return serverTLS, caPEM, clientPEM, clientKey
}

func TestE4JournalProducerCanonicalPostgres(t *testing.T) {
	for _, mode := range []string{"pre_dispatch_rejected", "definitive_non_delivery", "api_rejected", "ambiguous"} {
		t.Run(mode, func(t *testing.T) {
			ctx, p, s := env(t)
			i := bind(t, ctx, p, s, fixture(t, ctx, p, s))
			raw := projection(t, ctx, p, i)
			in, e := s.PersistIntent(ctx, raw)
			must(t, e)
			req := kernel.ConsumeRequest{Envelope: raw, Executor: "cp1-executor.test", Capability: kernel.ID() + kernel.ID()}
			r, e := s.Reserve(ctx, kernel.ReserveRequest{Envelope: raw, Executor: req.Executor, Capability: req.Capability})
			must(t, e)
			if !r.Granted {
				t.Fatal("new canonical reserve did not grant")
			}
			req.ID = r.ID
			phase := "reserved"
			if mode != "pre_dispatch_rejected" {
				consumed, e := s.Consume(ctx, req)
				must(t, e)
				if !consumed.Granted {
					t.Fatal("new canonical consume did not grant")
				}
				phase = "consumed"
			}
			// The private grant is emitted only following the new canonical grant above.
			// It contains no lease token, capability or PostgreSQL configuration.
			pub, kernelKey, e := ed25519.GenerateKey(rand.Reader)
			must(t, e)
			evidencePub, evidenceKey, e := ed25519.GenerateKey(rand.Reader)
			must(t, e)
			now := time.Now().UTC()
			storeID := "e4-store-" + kernel.ID()
			g := executor.Grant{JournalID: storeID, ServerName: "cp1-kernel.test", ContractVersion: executor.GrantVersion, Issuer: "e4-kernel", KeyID: "e4-kernel-key", Audience: "e4-synthetic-executor", Executor: req.Executor, GrantID: kernel.ID(), DispatchID: r.ID, Phase: phase, IntentID: in.ID, OperationID: in.OperationID, Attempt: 1, TenantID: i.TenantID, RuntimeNodeID: i.RuntimeNodeID, ClusterID: i.ClusterID, Namespace: i.Namespace, Name: i.Name, IncarnationID: i.ID, ExpectedUID: *i.UID, Generation: i.Generation, Fingerprint: in.Fingerprint, Action: "converge_projection", InfrastructureVersion: "utcp.infrastructure.v3", EnvelopeHash: kernel.Hash(raw), IssuedAt: now, Deadline: now.Add(3 * time.Minute)}
			if r.Expires.Before(g.Deadline) {
				g.Deadline = r.Expires
			}
			if g.Deadline.After(r.Expires) {
				t.Fatal("private grant exceeds canonical reservation expiry")
			}
			g.BodyHash = executor.Hash(executor.SyntheticBody(g))
			serverTLS, ca, clientCert, clientKey := e4TLS(t)
			var hits atomic.Int32
			effect := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				b, e := io.ReadAll(io.LimitReader(r.Body, 32768))
				if e != nil || !bytes.Equal(b, executor.SyntheticBody(g)) {
					http.Error(w, "invalid fixture request", 400)
					return
				}
				hits.Add(1)
				if mode == "ambiguous" {
					conn, _, e := w.(http.Hijacker).Hijack()
					if e == nil {
						conn.Close()
					}
					return
				}
				e4FixtureRejection(w, r, g)
			}))
			effect.TLS = serverTLS.Clone()
			effect.StartTLS()
			defer effect.Close()
			g.Endpoint = effect.URL
			sg, e := executor.SignGrant(g, kernelKey)
			must(t, e)
			grantRaw, e := json.Marshal(sg)
			must(t, e)
			var challenge kernel.DispatchEvidenceChallenge
			var challengeMu sync.Mutex
			var accepted atomic.Int32
			canonical := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				challengeMu.Lock()
				defer challengeMu.Unlock()
				switch r.URL.Path {
				case "/grant":
					_ = json.NewEncoder(w).Encode(e4WireGrant{Raw: grantRaw})
				case "/challenge":
					var e error
					challenge, e = s.EvidenceChallenge(r.Context(), kernel.EvidenceChallengeRequest{TenantID: i.TenantID, IncarnationID: i.ID, DispatchID: req.ID, Audience: "cp1-kernel"})
					if e != nil {
						http.Error(w, "rejected", 403)
						return
					}
					_ = json.NewEncoder(w).Encode(challenge)
				case "/evidence":
					b, e := io.ReadAll(http.MaxBytesReader(w, r.Body, 32768))
					if e != nil {
						http.Error(w, "rejected", 400)
						return
					}
					v := infra.DispatchEvidenceVerifier{Policy: infra.TrustPolicy{Keys: map[string]infra.TrustedKey{"e4-executor-key": {Issuer: "e4-executor", PeerSAN: req.Executor, ClusterID: i.ClusterID, Namespace: i.Namespace, Purposes: []string{"dispatch_evidence"}, PublicKey: evidencePub, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour)}}, MaxAge: 30 * time.Second, ClockSkew: time.Second}, PeerSAN: r.TLS.PeerCertificates[0].DNSNames[0]}
					if e = s.AcceptDispatchEvidence(r.Context(), challenge.ID, b, v); e != nil {
						http.Error(w, "rejected", 403)
						return
					}
					accepted.Add(1)
					w.WriteHeader(204)
				default:
					w.WriteHeader(404)
				}
			}))
			canonical.TLS = serverTLS
			canonical.StartTLS()
			defer canonical.Close()
			cfg := e4ProcessConfig{Journal: filepath.Join(t.TempDir(), "journal"), Store: storeID, CanonicalURL: canonical.URL, EffectURL: effect.URL, Mode: mode, Stage: "dispatch", CAPEM: ca, CertificatePEM: clientCert, PrivateKeyPEM: clientKey, GrantKey: pub, EvidenceKey: evidenceKey}
			e4RunProcess(t, cfg)
			cfg.Stage = "recover"
			e4RunProcess(t, cfg)
			wantHits := int32(0)
			if mode == "api_rejected" || mode == "ambiguous" {
				wantHits = 1
			}
			if hits.Load() != wantHits {
				t.Fatalf("HTTP submissions %d want %d", hits.Load(), wantHits)
			}
			state, e := s.Status(ctx, req.ID, req.Executor)
			must(t, e)
			wantState := "confirmed_not_applied"
			if mode == "pre_dispatch_rejected" {
				wantState = "cancelled"
			}
			if mode == "ambiguous" {
				wantState = "dispatching"
			}
			if state.State != wantState {
				t.Fatalf("canonical state %s want %s", state.State, wantState)
			}
			if mode != "ambiguous" {
				if accepted.Load() != 2 {
					t.Fatal("evidence not accepted idempotently")
				}
				var receipts, audits, outboxes int
				must(t, p.QueryRow(ctx, "SELECT count(*) FROM infrastructure_dispatch_evidence_receipts e JOIN infrastructure_dispatch_evidence_challenges c ON c.id=e.challenge_id WHERE c.dispatch_id=$1", req.ID).Scan(&receipts))
				must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_audit_records WHERE subject_id=$1 AND action='infrastructure.dispatch_nonapplication_confirmed'", i.ID).Scan(&audits))
				must(t, p.QueryRow(ctx, "SELECT count(*) FROM control_plane_outbox_messages WHERE aggregate_id=$1 AND event_type='infrastructure.dispatch_nonapplication_confirmed'", i.ID).Scan(&outboxes))
				if receipts != 1 || audits != 1 || outboxes != 1 {
					t.Fatalf("atomic idempotency %d/%d/%d", receipts, audits, outboxes)
				}
			}
			again, e := s.Consume(ctx, req)
			if e != nil && e != kernel.ErrDenied {
				t.Fatal(e)
			}
			if again.Granted {
				t.Fatal("historical evidence granted duplicate execution")
			}
		})
	}
}
func e4RunProcess(t *testing.T, cfg e4ProcessConfig) {
	t.Helper()
	dir := t.TempDir()
	path := filepath.Join(dir, "executor.json")
	raw, e := json.Marshal(cfg)
	must(t, e)
	must(t, os.WriteFile(path, raw, 0600))
	cmd := exec.Command(os.Args[0], "-test.run=^TestE4ExecutorProcess$", "-test.v")
	cmd.Env = []string{"UTCP_E4_EXECUTOR_CHILD=1", "UTCP_E4_EXECUTOR_CONFIG=" + path, "PATH=" + os.Getenv("PATH"), "TMPDIR=" + dir}
	out, e := cmd.CombinedOutput()
	if e != nil {
		t.Fatalf("isolated executor failed: %v\n%s", e, out)
	}
	if !bytes.Contains(out, []byte("E4 ")) {
		t.Fatal("executor subprocess did not execute expected path")
	}
	t.Log(strings.TrimSpace(string(out)))
}

// The fixture's structured rejection must be correlated with the one fixed
// synthetic body. It is not evidence of real Kubernetes API semantics.
func e4FixtureRejection(w http.ResponseWriter, r *http.Request, g executor.Grant) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("X-UTCP-Dispatch-ID", g.DispatchID)
	w.Header().Set("X-UTCP-Request-Digest", g.BodyHash)
	w.WriteHeader(403)
	_ = json.NewEncoder(w).Encode(executor.APIStatus{Kind: "Status", Status: "Failure", Code: 403, Reason: "Forbidden", DispatchID: g.DispatchID, EnvelopeHash: g.EnvelopeHash, BodyHash: g.BodyHash})
}

// The verifier invokes this entrypoint across an actual Docker restart and a
// replacement container sharing only the explicitly mounted journal directory.
// No network, database, private signing key or host configuration is mounted.
func TestE4ContainerStorageProbe(t *testing.T) {
	phase := os.Getenv("UTCP_E4_STORAGE_PHASE")
	if phase == "" {
		t.Skip("container-storage entrypoint")
	}
	ctx := context.Background()
	dir := "/e4-storage/journal"
	store := "e4-container-store"
	id := "e4-container-dispatch"
	if phase == "damaged" || phase == "missing" {
		j, e := executor.Open(dir, store)
		if e == nil {
			j.Close()
			t.Fatal("missing or damaged journal reopened")
		}
		fmt.Println("E4 container storage fails closed: " + phase)
		return
	}
	if _, e := os.Stat(dir); os.IsNotExist(e) {
		if phase != "cycle" {
			t.Fatal("container replacement lost required storage")
		}
		j, e := executor.Init(dir, store)
		must(t, e)
		defer j.Close()
		pub, key, e := ed25519.GenerateKey(rand.Reader)
		must(t, e)
		now := time.Now().UTC()
		g := executor.Grant{ContractVersion: executor.GrantVersion, Issuer: "container-kernel", KeyID: "container-key", Audience: "container-executor", Executor: "executor.test", GrantID: "container-grant", JournalID: store, Endpoint: "https://127.0.0.1:1/effect", ServerName: "fixture.test", DispatchID: id, Phase: "consumed", IntentID: "container-intent", OperationID: "container-operation", Attempt: 1, TenantID: "container-tenant", RuntimeNodeID: "container-node", ClusterID: "container-cluster", Namespace: "container-ns", Name: "container-deployment", IncarnationID: "container-incarnation", ExpectedUID: "container-uid", Generation: "1", Fingerprint: strings.Repeat("b", 64), Action: "converge_projection", InfrastructureVersion: "utcp.infrastructure.v3", EnvelopeHash: strings.Repeat("a", 64), IssuedAt: now, Deadline: now.Add(time.Minute)}
		g.BodyHash = executor.Hash(executor.SyntheticBody(g))
		signed, e := executor.SignGrant(g, key)
		must(t, e)
		raw, e := json.Marshal(signed)
		must(t, e)
		v := executor.GrantVerifier{Keys: map[string]executor.GrantKey{"container-key": {Issuer: g.Issuer, PeerSAN: "kernel.test", PublicKey: pub, NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour)}}, Audience: g.Audience, Executor: g.Executor}
		_, e = j.Accept(ctx, raw, v, "kernel.test")
		must(t, e)
		_, e = j.CloseGrant(ctx, id)
		must(t, e)
		fmt.Println("E4 container initial durable closure committed")
		return
	}
	j, e := executor.Open(dir, store)
	must(t, e)
	defer j.Close()
	entry, e := j.Snapshot(ctx, id)
	must(t, e)
	if entry.State != "closed" {
		t.Fatalf("container restart lost closure: %s", entry.State)
	}
	fmt.Println("E4 container restart/replacement preserved irreversible closure")
}
