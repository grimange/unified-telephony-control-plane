package lifecycle

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"testing"
)

func TestLifecyclePHPFingerprintParity(t *testing.T) {
	dir := os.Getenv("UTCP_GN_G2_LIFECYCLE_OUTPUT")
	if dir == "" {
		t.Skip("PHP parity artifacts provided by tests/gn-g2-lifecycle/verify")
	}
	b, err := os.ReadFile(filepath.Join(dir, "php-lifecycle-proof.json"))
	if err != nil {
		t.Fatal(err)
	}
	var proof struct {
		Fingerprints []struct {
			Name     string `json:"name"`
			Action   Action `json:"action"`
			Expected string `json:"expected"`
		} `json:"fingerprints"`
	}
	if err = json.Unmarshal(b, &proof); err != nil {
		t.Fatal(err)
	}
	if len(proof.Fingerprints) < 6 {
		t.Fatal("missing PHP vectors")
	}
	export := []map[string]any{}
	for _, v := range proof.Fingerprints {
		t.Run(v.Name, func(t *testing.T) {
			actual := Fingerprint(v.Action)
			if actual != v.Expected {
				t.Fatalf("PHP mismatch %s != %s", actual, v.Expected)
			}
			b, _ := json.Marshal(v.Action)
			if _, e := Decode(b); e != nil {
				t.Fatal(e)
			}
			export = append(export, map[string]any{"name": v.Name, "action": v.Action, "actual": actual})
		})
	}
	data, _ := json.MarshalIndent(map[string]any{"fingerprints": export}, "", "  ")
	if err = os.WriteFile(filepath.Join(dir, "go-lifecycle-proof.json"), data, 0644); err != nil {
		t.Fatal(err)
	}
	if err = os.Chmod(filepath.Join(dir, "go-lifecycle-proof.json"), 0644); err != nil {
		t.Fatal(err)
	}
}
func TestLifecycleFingerprintDeterminism(t *testing.T) {
	a := sample()
	f := Fingerprint(a)
	a.RequestID = "retry"
	a.Target.ExpectedRV = String("changed")
	if Fingerprint(a) != f {
		t.Fatal("retry metadata changes intent")
	}
	a.Generation = "9223372036854775807"
	a.Fingerprint = Fingerprint(a)
	if a.Fingerprint == f {
		t.Fatal("generation excluded")
	}
	b, _ := json.Marshal(a)
	var copy Action
	json.Unmarshal(b, &copy)
	if Fingerprint(copy) != a.Fingerprint {
		t.Fatal("JSON roundtrip precision loss")
	}
}

func TestLifecyclePHPCanonicalClaimsOverMTLS(t *testing.T) {
	dir := os.Getenv("UTCP_GN_G2_LIFECYCLE_OUTPUT")
	if dir == "" {
		t.Skip("PHP canonical claim artifacts provided by lifecycle verifier")
	}
	b, err := os.ReadFile(filepath.Join(dir, "php-lifecycle-proof.json"))
	if err != nil {
		t.Fatal(err)
	}
	var proof struct {
		Claims []struct {
			Name     string `json:"name"`
			Action   Action `json:"action"`
			Response struct {
				Version    string `json:"contract_version"`
				Authorized bool   `json:"authorized"`
			} `json:"response"`
		} `json:"claims"`
	}
	if err = json.Unmarshal(b, &proof); err != nil {
		t.Fatal(err)
	}
	if len(proof.Claims) < 3 {
		t.Fatal("missing PHP canonical claim cases")
	}
	p := makeTransportPKI(t)
	for _, vector := range proof.Claims {
		t.Run(vector.Name, func(t *testing.T) {
			calls := 0
			h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				calls++
				body, _ := io.ReadAll(r.Body)
				var got Action
				if json.Unmarshal(body, &got) != nil {
					t.Error("request decode")
				}
				expected, _ := json.Marshal(vector.Action)
				actual, _ := json.Marshal(got)
				if string(expected) != string(actual) {
					t.Error("complete envelope differs")
				}
				w.Header().Set("Content-Type", "application/json")
				json.NewEncoder(w).Encode(vector.Response)
			})
			server, _ := tlsServer(t, p, h)
			v, err := NewClaimVerifier(server.URL+ClaimPath, tlsClient(t, p))
			if err != nil {
				t.Fatal(err)
			}
			err = v.Verify(context.Background(), vector.Action)
			if vector.Response.Authorized && err != nil {
				t.Fatal(err)
			}
			if !vector.Response.Authorized && err == nil {
				t.Fatal("denied canonical claim accepted")
			}
			expectedCalls := 1
			encoded, _ := json.Marshal(vector.Action)
			if _, validationErr := Decode(encoded); validationErr != nil {
				expectedCalls = 0
			}
			if calls != expectedCalls {
				t.Fatalf("callback count %d expected %d", calls, expectedCalls)
			}
		})
	}
}
func TestLifecycleNormativeFixtures(t *testing.T) {
	b, err := contracts.ReadFile("contracts/fixtures.json")
	if err != nil {
		t.Fatal(err)
	}
	var fixtures struct {
		Positive []struct {
			Name        string          `json:"name"`
			Action      json.RawMessage `json:"action"`
			Fingerprint string          `json:"expected_fingerprint"`
		} `json:"positive"`
		Negative []struct {
			Name   string          `json:"name"`
			Action json.RawMessage `json:"action"`
		} `json:"negative"`
	}
	if err = json.Unmarshal(b, &fixtures); err != nil {
		t.Fatal(err)
	}
	for _, v := range fixtures.Positive {
		t.Run(v.Name, func(t *testing.T) {
			a, e := Decode(v.Action)
			if e != nil {
				t.Fatal(e)
			}
			if Fingerprint(a) != v.Fingerprint {
				t.Fatal("independent normative fingerprint mismatch")
			}
		})
	}
	for _, v := range fixtures.Negative {
		t.Run(v.Name, func(t *testing.T) {
			if _, e := Decode(v.Action); e == nil {
				t.Fatal("negative fixture accepted")
			}
		})
	}
}
