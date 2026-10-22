package integration_test

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v5"
)

// These static fixtures prove closed shape and lossless generation encoding,
// not signatures or clocks. Real independent ephemeral-key tests exercise those.
func TestE4ExecutorGrantSchemaFixtures(t *testing.T) {
	root := os.Getenv("UTCP_CP1_ROOT")
	if root == "" {
		root = filepath.Clean("../../..")
	}
	schemaPath := filepath.Join(root, "apps/control-plane/contracts/executor-grant-v1.schema.json")
	compiler := jsonschema.NewCompiler()
	compiler.AssertFormat = true
	schema, e := compiler.Compile(schemaPath)
	must(t, e)
	files, e := filepath.Glob(filepath.Join(root, "tests/go-cp1-e4/fixtures/*.json"))
	must(t, e)
	if len(files) < 12 {
		t.Fatal("missing E4 positive/negative fixtures")
	}
	for _, path := range files {
		t.Run(filepath.Base(path), func(t *testing.T) {
			raw, e := os.ReadFile(path)
			must(t, e)
			var value any
			must(t, json.Unmarshal(raw, &value))
			e = schema.Validate(value)
			positive := strings.HasPrefix(filepath.Base(path), "positive-")
			if positive && e != nil {
				t.Fatal(e)
			}
			if !positive && e == nil {
				t.Fatal("negative schema fixture accepted")
			}
		})
	}
}
