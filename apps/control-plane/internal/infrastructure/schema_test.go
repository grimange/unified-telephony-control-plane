package infrastructure

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v5"
)

func TestProtocolSchemasAndFixtures(t *testing.T) {
	root := filepath.Join("..", "..", "contracts")
	var fixtures []struct {
		Name, Schema string
		Valid        bool
		Value        any
	}
	raw, err := os.ReadFile(filepath.Join(root, "fixtures.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err = json.Unmarshal(raw, &fixtures); err != nil {
		t.Fatal(err)
	}
	for _, f := range fixtures {
		t.Run(f.Name, func(t *testing.T) {
			path, err := filepath.Abs(filepath.Join(root, f.Schema))
			if err != nil {
				t.Fatal(err)
			}
			schema, err := jsonschema.Compile(path)
			if err != nil {
				t.Fatal(err)
			}
			if err = schema.Validate(f.Value); (err == nil) != f.Valid {
				t.Fatalf("schema acceptance=%v want=%v: %v", err == nil, f.Valid, err)
			}
		})
	}
	p, _, _, key, _ := receiptFixture(t)
	signed, err := SignReceipt(p, key)
	if err != nil {
		t.Fatal(err)
	}
	raw, err = json.Marshal(signed)
	if err != nil {
		t.Fatal(err)
	}
	var value any
	_ = json.Unmarshal(raw, &value)
	path, _ := filepath.Abs(filepath.Join(root, "observation-receipt.schema.json"))
	schema, err := jsonschema.Compile(path)
	if err != nil {
		t.Fatal(err)
	}
	if err = schema.Validate(value); err != nil {
		t.Fatal("actual signed receipt violates schema", err)
	}
}
