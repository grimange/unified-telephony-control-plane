package observer

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"

	"github.com/santhosh-tekuri/jsonschema/v5"
)

// Validator compiles the original proposal schemas, never a permissive copy.
// The caller supplies a trusted local directory, never a wire request path.
type Validator struct{ Request, Response *jsonschema.Schema }

func NewValidator(dir string) (*Validator, error) {
	c := jsonschema.NewCompiler()
	c.AssertFormat = true
	compile := func(name string) (*jsonschema.Schema, error) {
		b, e := os.ReadFile(filepath.Join(dir, name))
		if e != nil {
			return nil, e
		}
		id := "https://utcp.invalid/contracts/v1/" + name
		if e = c.AddResource(id, bytes.NewReader(b)); e != nil {
			return nil, e
		}
		return c.Compile(id)
	}
	q, e := compile("observation-request.schema.json")
	if e != nil {
		return nil, e
	}
	s, e := compile("observation.schema.json")
	if e != nil {
		return nil, e
	}
	return &Validator{q, s}, nil
}
func validateJSON(schema *jsonschema.Schema, b []byte) error {
	var v any
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	if e := d.Decode(&v); e != nil {
		return e
	}
	return schema.Validate(v)
}
func (v *Validator) ValidateRequest(b []byte) error { return validateJSON(v.Request, b) }
func (v *Validator) ValidateSnapshot(s Snapshot) error {
	b, e := json.Marshal(s)
	if e != nil {
		return e
	}
	return validateJSON(v.Response, b)
}

// NewObserver is the supported assembly: strict request and response validation
// surround acquisition. An invalid upstream projection becomes null facts.
func NewObserver(c Collector, v *Validator) (func(context.Context, Request) Snapshot, error) {
	if c.Reader == nil || v == nil || v.Request == nil || v.Response == nil || c.ClusterID == "" || c.Namespace == "" {
		return nil, fmt.Errorf("invalid observer configuration")
	}
	return func(ctx context.Context, q Request) Snapshot {
		b, e := json.Marshal(q)
		if e != nil || v.ValidateRequest(b) != nil {
			return Failure(q, c.clock(), "invalid_request")
		}
		s := c.Observe(ctx, q)
		if v.ValidateSnapshot(s) != nil {
			return Failure(q, c.clock(), "terminal_failure")
		}
		return s
	}, nil
}
