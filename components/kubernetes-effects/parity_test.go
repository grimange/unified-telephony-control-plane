package effects

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestPHPNegativeFingerprintInputsRejected(t *testing.T) {
	var vectors []struct {
		Name   string
		Action json.RawMessage
	}
	b, err := os.ReadFile("../../tests/gn-g2-effects/fixtures/invalid-fingerprints.json")
	if err != nil {
		t.Fatal(err)
	}
	if json.Unmarshal(b, &vectors) != nil || len(vectors) != 12 {
		t.Fatal("negative parity fixtures missing")
	}
	v := validator(t)
	for _, f := range vectors {
		t.Run(f.Name, func(t *testing.T) {
			if _, err := v.ParseAction(f.Action); err == nil {
				t.Fatal("Go accepted PHP-rejected canonical input")
			}
		})
	}
}

func TestPHPFingerprintAndResultParity(t *testing.T) {
	var vectors []struct {
		Name     string
		Action   Action
		Expected string
	}
	b, err := os.ReadFile("../../tests/gn-g2-effects/fixtures/fingerprints.json")
	if err != nil {
		t.Fatal(err)
	}
	if json.Unmarshal(b, &vectors) != nil || len(vectors) != 8 {
		t.Fatal("fingerprint fixtures missing")
	}
	v := validator(t)
	exports := []map[string]any{}
	for _, f := range vectors {
		t.Run(f.Name, func(t *testing.T) {
			raw, _ := json.Marshal(f.Action)
			if _, err := v.ParseAction(raw); err != nil {
				t.Fatal(err)
			}
			actual := Fingerprint(f.Action)
			if actual != f.Expected {
				t.Fatalf("fingerprint differs from PHP: %s != %s", actual, f.Expected)
			}
			exports = append(exports, map[string]any{"name": f.Name, "action": f.Action, "actual": actual})
		})
	}
	results := []Result{}
	a := fixtureAction(t)
	m := &memoryClient{d: fixtureDeployment(a)}
	e := testEngine(t, m, verifyFunc(permit))
	results = append(results, e.Execute(context.Background(), a), e.Execute(context.Background(), a))
	a.Target.ExpectedRV = m.d.ResourceVersion
	a.Action = "set_replicas"
	results = append(results, e.Execute(context.Background(), a), e.Execute(context.Background(), a))
	m.patchErr = context.DeadlineExceeded
	a.Target.ExpectedRV = m.d.ResourceVersion
	a.Generation = "11"
	a.Action = "admit_generation"
	a.Fingerprint = Fingerprint(a)
	results = append(results, e.Execute(context.Background(), a))
	for _, r := range results {
		if err := v.ValidateResult(r); err != nil {
			t.Fatal(err)
		}
	}
	if os.Getenv("UTCP_GN_G2_EXPORT") == "1" {
		dir := os.Getenv("UTCP_GN_G2_OUTPUT")
		if dir == "" {
			t.Fatal("output required")
		}
		b, err := json.MarshalIndent(map[string]any{"fingerprints": exports, "results": results}, "", "  ")
		if err != nil {
			t.Fatal(err)
		}
		if err = os.WriteFile(filepath.Join(dir, "go-proof.json"), append(b, '\n'), 0644); err != nil {
			t.Fatal(err)
		}
	}
}
