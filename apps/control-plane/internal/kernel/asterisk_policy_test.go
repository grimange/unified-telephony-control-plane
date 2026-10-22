package kernel

import (
	"encoding/json"
	"testing"
)

func TestAsteriskProvenanceDoesNotCollapseCompositeGeneration(t *testing.T) {
	for name, value := range map[string]any{
		"array": []any{}, "object": map[string]any{}, "decimal": json.Number("1.0"),
		"exponent": json.Number("1e2"), "overflow": json.Number("9223372036854775808"),
	} {
		t.Run(name, func(t *testing.T) {
			if _, valid := policyComparableScalar(value); valid {
				t.Fatal("composite generation supplied provenance authority")
			}
		})
	}
	// PHP's legacy missing-field comparison is preserved; rejecting composites
	// must not accidentally introduce an additional mandatory generation field.
	if value, valid := policyComparableScalar(nil); !valid || value != "" {
		t.Fatal("legacy missing scalar semantics changed")
	}
	if value, valid := policyComparableScalar(json.Number("9007199254740993")); !valid || value != "9007199254740993" {
		t.Fatal("generation lost integer precision")
	}
}

func TestAsteriskReplicaProvenancePreservesPHPIntegerComparison(t *testing.T) {
	for name, value := range map[string]any{
		"string": "1", "decimal": json.Number("1.0"), "exponent": json.Number("1e0"),
		"boolean": true, "missing": nil, "object": map[string]any{},
	} {
		t.Run(name, func(t *testing.T) {
			if policyIntegerEquals(value, 1) {
				t.Fatal("noninteger replica provenance accepted")
			}
		})
	}
	if !policyIntegerEquals(json.Number("1"), 1) || policyIntegerEquals(json.Number("2"), 1) {
		t.Fatal("integer replica provenance comparison changed")
	}
}
