package active

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"reflect"
	"sync/atomic"
	"testing"

	appsv1 "k8s.io/api/apps/v1"
)

type phpProof struct {
	Fingerprints []struct {
		Name       string `json:"name"`
		Action     Action `json:"action"`
		Expected   string `json:"expected"`
		Projection string `json:"projection"`
	} `json:"fingerprints"`
	Deployments []struct {
		Provider   string            `json:"provider"`
		Deployment appsv1.Deployment `json:"deployment"`
	} `json:"deployments"`
	Claims []struct {
		Name     string `json:"name"`
		Action   Action `json:"action"`
		Response struct {
			Version    string `json:"contract_version"`
			Authorized bool   `json:"authorized"`
		} `json:"response"`
	} `json:"claims"`
}

func loadPHP(t *testing.T) (string, phpProof) {
	t.Helper()
	dir := os.Getenv("UTCP_GN_G2_ACTIVE_OUTPUT")
	if dir == "" {
		t.Skip("PHP artifacts supplied by tests/gn-g2-active-projection/verify-php")
	}
	b, err := os.ReadFile(filepath.Join(dir, "php-active-proof.json"))
	if err != nil {
		t.Fatal(err)
	}
	var proof phpProof
	if err = json.Unmarshal(b, &proof); err != nil {
		t.Fatal(err)
	}
	if len(proof.Fingerprints) != 4 || len(proof.Deployments) != 2 || len(proof.Claims) != 6 {
		t.Fatal("incomplete independent PHP proof")
	}
	return dir, proof
}

func TestActivePHPFingerprintParity(t *testing.T) {
	dir, proof := loadPHP(t)
	export := []map[string]any{}
	for _, v := range proof.Fingerprints {
		t.Run(v.Name, func(t *testing.T) {
			if actual := Fingerprint(v.Action); actual != v.Expected {
				t.Fatalf("independent PHP action hash %s differs from Go %s", v.Expected, actual)
			}
			if actual := ProjectionFingerprint(*v.Action.Intent.Projection); actual != v.Projection {
				t.Fatal("independent projection hash differs")
			}
			b, _ := json.Marshal(v.Action)
			if _, err := Decode(b); err != nil {
				t.Fatal(err)
			}
			export = append(export, map[string]any{"name": v.Name, "action": v.Action, "actual": Fingerprint(v.Action)})
		})
	}
	b, _ := json.MarshalIndent(map[string]any{"fingerprints": export}, "", "  ")
	// Replace the output atomically: an earlier disposable container may have
	// produced a read-only file under another UID. Never open that file in place.
	f, err := os.CreateTemp(dir, ".go-active-proof-*")
	if err != nil {
		t.Fatal(err)
	}
	defer os.Remove(f.Name())
	if _, err = f.Write(b); err != nil {
		f.Close()
		t.Fatal(err)
	}
	if err = f.Close(); err != nil {
		t.Fatal(err)
	}
	if err = os.Chmod(f.Name(), 0644); err != nil {
		t.Fatal(err)
	}
	if err = os.Rename(f.Name(), filepath.Join(dir, "go-active-proof.json")); err != nil {
		t.Fatal(err)
	}
}

func TestActualPHPProviderPodSpecificationParity(t *testing.T) {
	_, proof := loadPHP(t)
	for _, source := range proof.Deployments {
		t.Run(source.Provider, func(t *testing.T) {
			var action Action
			for _, v := range proof.Fingerprints {
				if v.Name == source.Provider+"-10" {
					action = v.Action
				}
			}
			desired := DesiredDeployment(action)
			// Decode both through actual Kubernetes types: quantities, false pointer
			// fields and emptyDir objects retain semantic meaning, independent of JSON key order.
			if !reflect.DeepEqual(desired.Spec.Template.Spec, source.Deployment.Spec.Template.Spec) {
				got, _ := json.MarshalIndent(desired.Spec.Template.Spec, "", "  ")
				want, _ := json.MarshalIndent(source.Deployment.Spec.Template.Spec, "", "  ")
				t.Fatalf("full provider PodSpec differs\nGo: %s\nPHP: %s", got, want)
			}
			for _, key := range []string{"app.kubernetes.io/part-of", "app.kubernetes.io/component", "utcp.dev/runtime-node", "utcp.io/network-role"} {
				if desired.Spec.Template.Labels[key] != source.Deployment.Spec.Template.Labels[key] {
					t.Fatalf("source provider label %s lost", key)
				}
			}
			if *desired.Spec.Replicas != *source.Deployment.Spec.Replicas {
				t.Fatal("replica parity")
			}
			if desired.Spec.Paused {
				t.Fatal("active projection must explicitly resume rollout")
			}
			if desired.Spec.Strategy.Type != appsv1.RollingUpdateDeploymentStrategyType || desired.Spec.Strategy.RollingUpdate.MaxSurge.String() != "25%" || desired.Spec.Strategy.RollingUpdate.MaxUnavailable.String() != "25%" {
				t.Fatal("PHP absent strategy must preserve Kubernetes default RollingUpdate semantics")
			}
			if desired.Name == source.Deployment.Name {
				t.Fatal("lifecycle physical name must intentionally differ from legacy name")
			}
			if desired.Spec.Selector.MatchLabels[Prefix+"incarnation-id"] != action.Target.IncarnationID {
				t.Fatal("immutable incarnation selector transformation missing")
			}
		})
	}
}

func TestActualPHPCanonicalClaimsOverMTLS(t *testing.T) {
	_, proof := loadPHP(t)
	p := makeTransportPKI(t)
	for _, v := range proof.Claims {
		t.Run(v.Name, func(t *testing.T) {
			var calls atomic.Int32
			h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				calls.Add(1)
				body, _ := io.ReadAll(r.Body)
				var got Action
				if err := json.Unmarshal(body, &got); err != nil {
					t.Error(err)
				}
				want, _ := json.Marshal(v.Action)
				actual, _ := json.Marshal(got)
				if string(want) != string(actual) {
					t.Error("full envelope changed before PHP claim callback")
				}
				w.Header().Set("Content-Type", "application/json")
				json.NewEncoder(w).Encode(v.Response)
			})
			server, _ := tlsServer(t, p, h)
			verifier, err := NewClaimVerifier(server.URL+ClaimPath, tlsClient(t, p))
			if err != nil {
				t.Fatal(err)
			}
			err = verifier.Verify(context.Background(), v.Action)
			if v.Response.Authorized && err != nil {
				t.Fatal(err)
			}
			if !v.Response.Authorized && err == nil {
				t.Fatal("PHP canonical denial bypassed")
			}
			if calls.Load() != 1 {
				t.Fatalf("callback count %d", calls.Load())
			}
		})
	}
}
