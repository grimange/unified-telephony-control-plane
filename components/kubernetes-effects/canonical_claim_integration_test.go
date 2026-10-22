package effects_test

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
	"reflect"
	"sync/atomic"
	"testing"
	"time"

	effects "utcp.invalid/components/kubernetes-effects"
	active "utcp.invalid/components/kubernetes-effects/active"
	lifecycle "utcp.invalid/components/kubernetes-effects/lifecycle"
	observer "utcp.invalid/components/kubernetes-observer"
)

// This test exercises the existing Go claim clients against a live Laravel
// service backed by disposable PostgreSQL. Only the private test PHP container
// has DB credentials; Go has a loopback callback address and ephemeral mTLS.
func TestCanonicalPostgreSQLClaimsAndFingerprintParity(t *testing.T) {
	directory := os.Getenv("UTCP_GN_G2_CANONICAL_OUTPUT")
	phpURL := os.Getenv("UTCP_CANONICAL_PHP_URL")
	if directory == "" || phpURL == "" {
		t.Skip("run scripts/control-plane/canonical-proof for live PostgreSQL/PHP integration")
	}
	u, err := url.Parse(phpURL)
	if err != nil || u.Scheme != "http" || u.Hostname() != "127.0.0.1" || u.Port() == "" {
		t.Fatal("unsafe PHP test bridge")
	}
	data, err := os.ReadFile(filepath.Join(directory, "canonical-proof.json"))
	if err != nil {
		t.Fatal(err)
	}
	var proof struct {
		Claims []struct {
			Name     string          `json:"name"`
			Action   json.RawMessage `json:"action"`
			Response struct {
				Authorized bool `json:"authorized"`
			} `json:"response"`
		} `json:"claims"`
		Fingerprints []struct {
			Name     string          `json:"name"`
			Action   json.RawMessage `json:"action"`
			Expected string          `json:"expected"`
		} `json:"fingerprints"`
	}
	if err = json.Unmarshal(data, &proof); err != nil || len(proof.Claims) < 6 || len(proof.Fingerprints) < 3 {
		t.Fatal("incomplete PostgreSQL PHP proof export", err)
	}
	client, material := canonicalPKI(t)
	var calls atomic.Int32
	upstream := &http.Client{Timeout: 3 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.TLS == nil || len(r.TLS.VerifiedChains) == 0 || len(r.TLS.PeerCertificates) == 0 {
			http.Error(w, "mTLS required", 401)
			return
		}
		matched := false
		for _, uri := range r.TLS.PeerCertificates[0].URIs {
			if uri.String() == "spiffe://utcp.invalid/go/executor" {
				matched = true
			}
		}
		if !matched {
			http.Error(w, "executor identity required", 403)
			return
		}
		body, e := io.ReadAll(io.LimitReader(r.Body, 65537))
		if e != nil || len(body) > 65536 {
			http.Error(w, "invalid body", 400)
			return
		}
		if e = canonicalClaimJSON(body); e != nil {
			http.Error(w, "invalid JSON", 400)
			return
		}
		calls.Add(1)
		request, e := http.NewRequestWithContext(r.Context(), http.MethodPost, phpURL, bytes.NewReader(body))
		if e != nil {
			http.Error(w, "unavailable", 503)
			return
		}
		request.Header.Set("Content-Type", "application/json")
		response, e := upstream.Do(request)
		if e != nil {
			http.Error(w, "unavailable", 503)
			return
		}
		defer response.Body.Close()
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(response.StatusCode)
		io.Copy(w, io.LimitReader(response.Body, 16385))
	}))
	server.TLS = material.Config()
	server.Config.ErrorLog = log.New(io.Discard, "", 0)
	server.StartTLS()
	defer server.Close()
	validator, e := effects.NewValidator("../../docs/architecture/proposals/gn-g0/contracts/v1")
	if e != nil {
		t.Fatal(e)
	}
	v1, e := effects.NewClaimVerifier(server.URL+effects.ClaimPath, client, validator)
	if e != nil {
		t.Fatal(e)
	}
	v2, e := lifecycle.NewClaimVerifier(server.URL+lifecycle.ClaimPath, client)
	if e != nil {
		t.Fatal(e)
	}
	v3, e := active.NewClaimVerifier(server.URL+active.ClaimPath, client)
	if e != nil {
		t.Fatal(e)
	}
	versions := map[string]int{}
	for _, fixture := range proof.Fingerprints {
		t.Run("fingerprint/"+fixture.Name, func(t *testing.T) {
			var head struct {
				Version string `json:"contract_version"`
			}
			json.Unmarshal(fixture.Action, &head)
			var actual string
			switch head.Version {
			case effects.Version:
				var a effects.Action
				json.Unmarshal(fixture.Action, &a)
				if validator.ValidateAction(fixture.Action) != nil {
					t.Fatal("invalid v1 export")
				}
				actual = effects.Fingerprint(a)
			case lifecycle.Version:
				a, e := lifecycle.Decode(fixture.Action)
				if e != nil {
					t.Fatal(e)
				}
				actual = lifecycle.Fingerprint(a)
			case active.Version:
				a, e := active.Decode(fixture.Action)
				if e != nil {
					t.Fatal(e)
				}
				actual = active.Fingerprint(a)
			default:
				t.Fatal("unsupported export")
			}
			if actual != fixture.Expected {
				t.Fatal("independent PHP/Go intent fingerprint mismatch")
			}
			versions[head.Version]++
		})
	}
	if len(versions) != 3 {
		t.Fatal("all three versions must be independently fingerprinted")
	}
	accepted := 0
	denied := 0
	for _, fixture := range proof.Claims {
		t.Run("claim/"+fixture.Name, func(t *testing.T) {
			var head struct {
				Version string `json:"contract_version"`
			}
			json.Unmarshal(fixture.Action, &head)
			before := calls.Load()
			for repeat := 0; repeat < 2; repeat++ {
				var decision error
				switch head.Version {
				case effects.Version:
					var a effects.Action
					json.Unmarshal(fixture.Action, &a)
					decision = v1.Verify(context.Background(), a)
				case lifecycle.Version:
					a, e := lifecycle.Decode(fixture.Action)
					if e != nil {
						t.Fatal(e)
					}
					decision = v2.Verify(context.Background(), a)
				case active.Version:
					a, e := active.Decode(fixture.Action)
					if e != nil {
						t.Fatal(e)
					}
					decision = v3.Verify(context.Background(), a)
				default:
					t.Fatal("unsupported claim export")
				}
				if (decision == nil) != fixture.Response.Authorized {
					t.Fatalf("live canonical decision differs: authorized=%v error=%v typed=%#v callbacks=%d", fixture.Response.Authorized, decision, decision, calls.Load()-before)
				}
			}
			if fixture.Response.Authorized {
				accepted++
				if calls.Load()-before != 2 {
					t.Fatal("positive authorization was cached")
				}
			} else {
				denied++
			}
		})
	}
	if accepted < 3 || denied < 3 {
		t.Fatal("insufficient positive/negative full-envelope cases")
	}
}

func canonicalPKI(t *testing.T) (*observer.RotatingHTTPClient, *observer.ServerTLSMaterial) {
	t.Helper()
	key, e := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "ephemeral canonical proof CA"}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature}
	der, e := x509.CreateCertificate(rand.Reader, ca, ca, &key.PublicKey, key)
	if e != nil {
		t.Fatal(e)
	}
	capem := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
	issue := func(serial int64, server bool) ([]byte, []byte) {
		k, e := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if e != nil {
			t.Fatal(e)
		}
		cert := &x509.Certificate{SerialNumber: big.NewInt(serial), NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature}
		if server {
			cert.DNSNames = []string{"canonical.test"}
			cert.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}
		} else {
			uri, _ := url.Parse("spiffe://utcp.invalid/go/executor")
			cert.URIs = []*url.URL{uri}
			cert.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}
		}
		d, e := x509.CreateCertificate(rand.Reader, cert, ca, &k.PublicKey, key)
		if e != nil {
			t.Fatal(e)
		}
		private, e := x509.MarshalPKCS8PrivateKey(k)
		if e != nil {
			t.Fatal(e)
		}
		return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: d}), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: private})
	}
	server, serverKey := issue(2, true)
	caller, callerKey := issue(3, false)
	material, e := observer.NewServerTLS(server, serverKey, capem, "spiffe://utcp.invalid/go/executor")
	if e != nil {
		t.Fatal(e)
	}
	client, e := observer.NewHTTPClient(capem, caller, callerKey, "canonical.test")
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(client.CloseIdleConnections)
	return client, material
}

// Typed claim transport must retain the complete approved wire envelope.
func TestCanonicalPostgreSQLWireRoundTrip(t *testing.T) {
	directory := os.Getenv("UTCP_GN_G2_CANONICAL_OUTPUT")
	if directory == "" {
		t.Skip("requires canonical PostgreSQL export")
	}
	b, err := os.ReadFile(filepath.Join(directory, "canonical-proof.json"))
	if err != nil {
		t.Fatal(err)
	}
	var proof struct {
		Claims []struct {
			Name   string          `json:"name"`
			Action json.RawMessage `json:"action"`
		} `json:"claims"`
	}
	if err = json.Unmarshal(b, &proof); err != nil {
		t.Fatal(err)
	}
	for _, fixture := range proof.Claims {
		t.Run(fixture.Name, func(t *testing.T) {
			var head struct {
				Version string `json:"contract_version"`
			}
			json.Unmarshal(fixture.Action, &head)
			var typed any
			switch head.Version {
			case effects.Version:
				var a effects.Action
				json.Unmarshal(fixture.Action, &a)
				typed = a
			case lifecycle.Version:
				a, e := lifecycle.Decode(fixture.Action)
				if e != nil {
					t.Fatal(e)
				}
				typed = a
			case active.Version:
				a, e := active.Decode(fixture.Action)
				if e != nil {
					t.Fatal(e)
				}
				typed = a
			}
			wire, e := json.Marshal(typed)
			if e != nil {
				t.Fatal(e)
			}
			var original, roundtrip map[string]any
			json.Unmarshal(fixture.Action, &original)
			json.Unmarshal(wire, &roundtrip)
			if !reflect.DeepEqual(original, roundtrip) {
				for key, v := range original {
					if !reflect.DeepEqual(v, roundtrip[key]) {
						t.Errorf("typed transport changed field %s", key)
					}
				}
				t.FailNow()
			}
		})
	}
}

// Validate against the selected closed contract. V1 forbids null everywhere;
// V2 suspended creation requires null UID/resourceVersion before binding.
func canonicalClaimJSON(body []byte) error {
	var head struct {
		Version string `json:"contract_version"`
	}
	if err := json.Unmarshal(body, &head); err != nil {
		return err
	}
	switch head.Version {
	case effects.Version:
		_, err := effects.StrictJSON(body)
		return err
	case lifecycle.Version:
		_, err := lifecycle.Decode(body)
		return err
	case active.Version:
		_, err := active.Decode(body)
		return err
	default:
		return errors.New("unsupported claim contract")
	}
}

func TestCanonicalClaimBridgeVersionedValidation(t *testing.T) {
	b, err := os.ReadFile("lifecycle/contracts/fixtures.json")
	if err != nil {
		t.Fatal(err)
	}
	var fixtures struct {
		Positive []struct {
			Action json.RawMessage `json:"action"`
		} `json:"positive"`
	}
	if err = json.Unmarshal(b, &fixtures); err != nil {
		t.Fatal(err)
	}
	tested := false
	for _, f := range fixtures.Positive {
		var a lifecycle.Action
		json.Unmarshal(f.Action, &a)
		if a.Action != "create_suspended" {
			continue
		}
		if a.Target.ExpectedUID != nil || a.Target.ExpectedRV != nil {
			t.Fatal("fixture must represent unbound creation")
		}
		if err = canonicalClaimJSON(f.Action); err != nil {
			t.Fatal("valid nullable v2 creation rejected", err)
		}
		if _, err = effects.StrictJSON(f.Action); err == nil {
			t.Fatal("v1 restriction should differ")
		}
		// Unknown/duplicate fields still fail closed; do not relax the shared v1 parser.
		unknown := bytes.Replace(f.Action, []byte("{"), []byte(`{"unknown":true,`), 1)
		duplicate := bytes.Replace(f.Action, []byte("{"), []byte(`{"contract_version":"utcp.infrastructure.v2",`), 1)
		for _, invalid := range [][]byte{unknown, duplicate} {
			if canonicalClaimJSON(invalid) == nil {
				t.Fatal("malformed closed contract accepted")
			}
		}
		tested = true
		break
	}
	if !tested {
		t.Fatal("missing suspended create regression fixture")
	}
}
