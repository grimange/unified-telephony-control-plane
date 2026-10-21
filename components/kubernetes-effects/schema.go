package effects

import (
	"bytes"
	"encoding/json"
	"errors"
	"github.com/santhosh-tekuri/jsonschema/v5"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"unicode/utf8"
)

type Validator struct{ Action, Result *jsonschema.Schema }

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
	a, e := compile("action.schema.json")
	if e != nil {
		return nil, e
	}
	r, e := compile("result.schema.json")
	if e != nil {
		return nil, e
	}
	return &Validator{a, r}, nil
}

// validEscapedUnicode prevents encoding/json from silently replacing unpaired
// UTF-16 surrogate escapes with U+FFFD. PHP rejects those wire representations.
// Ordinary JSON syntax remains the decoder's responsibility.
func validEscapedUnicode(b []byte) bool {
	hex4 := func(p []byte) (uint16, bool) {
		if len(p) < 4 {
			return 0, false
		}
		var n uint16
		for _, c := range p[:4] {
			n <<= 4
			switch {
			case c >= '0' && c <= '9':
				n |= uint16(c - '0')
			case c >= 'a' && c <= 'f':
				n |= uint16(c - 'a' + 10)
			case c >= 'A' && c <= 'F':
				n |= uint16(c - 'A' + 10)
			default:
				return 0, false
			}
		}
		return n, true
	}
	inString := false
	for i := 0; i < len(b); i++ {
		if !inString {
			if b[i] == '"' {
				inString = true
			}
			continue
		}
		if b[i] == '"' {
			inString = false
			continue
		}
		if b[i] != '\\' {
			continue
		}
		if i+1 >= len(b) {
			return false
		}
		if b[i+1] != 'u' {
			i++
			continue
		} // Includes an escaped backslash or quote.
		n, ok := hex4(b[i+2:])
		if !ok {
			return false
		}
		if n >= 0xDC00 && n <= 0xDFFF {
			return false
		}
		if n >= 0xD800 && n <= 0xDBFF {
			if i+12 > len(b) || b[i+6] != '\\' || b[i+7] != 'u' {
				return false
			}
			low, ok := hex4(b[i+8:])
			if !ok || low < 0xDC00 || low > 0xDFFF {
				return false
			}
			i += 11
		} else {
			i += 5
		}
	}
	return true
}

// StrictJSON rejects duplicate keys, trailing JSON, null fields and excessive depth.
func StrictJSON(b []byte) (any, error) {
	if len(b) > 1<<20 || !utf8.Valid(b) || !validEscapedUnicode(b) {
		return nil, errors.New("invalid JSON")
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	var read func(int) (any, error)
	read = func(depth int) (any, error) {
		if depth > 32 {
			return nil, errors.New("JSON depth")
		}
		t, e := d.Token()
		if e != nil {
			return nil, e
		}
		if t == nil {
			return nil, errors.New("null field")
		}
		switch x := t.(type) {
		case json.Delim:
			if x == '{' {
				m := map[string]any{}
				for d.More() {
					k, e := d.Token()
					if e != nil {
						return nil, e
					}
					s, ok := k.(string)
					if !ok {
						return nil, errors.New("key")
					}
					if _, ok = m[s]; ok {
						return nil, errors.New("duplicate key")
					}
					v, e := read(depth + 1)
					if e != nil {
						return nil, e
					}
					m[s] = v
				}
				_, e = d.Token()
				return m, e
			}
			if x == '[' {
				a := []any{}
				for d.More() {
					v, e := read(depth + 1)
					if e != nil {
						return nil, e
					}
					a = append(a, v)
				}
				_, e = d.Token()
				return a, e
			}
			return nil, errors.New("delimiter")
		default:
			return t, nil
		}
	}
	v, e := read(0)
	if e != nil {
		return nil, e
	}
	if _, e = d.Token(); e != io.EOF {
		return nil, errors.New("trailing JSON")
	}
	return v, nil
}
func (v *Validator) ValidateAction(b []byte) error {
	x, e := StrictJSON(b)
	if e != nil {
		return e
	}
	if e = v.Action.Validate(x); e != nil {
		return e
	}
	m := x.(map[string]any)
	if _, e = strconv.ParseInt(m["target_generation"].(string), 10, 64); e != nil {
		return errors.New("generation exceeds signed bigint")
	}
	return nil
}
func (v *Validator) ParseAction(b []byte) (Action, error) {
	var a Action
	if e := v.ValidateAction(b); e != nil {
		return a, e
	}
	if e := json.Unmarshal(b, &a); e != nil {
		return a, e
	}
	if a.Fingerprint != Fingerprint(a) {
		return Action{}, errors.New("intent fingerprint mismatch")
	}
	return a, nil
}
func (v *Validator) ValidateResult(r Result) error {
	b, e := json.Marshal(r)
	if e != nil {
		return e
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	var x any
	if e = d.Decode(&x); e != nil {
		return e
	}
	return v.Result.Validate(x)
}
