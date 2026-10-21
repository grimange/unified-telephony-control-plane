package lifecycle

import (
	"bytes"
	"embed"
	"encoding/json"
	"errors"
	schema "github.com/santhosh-tekuri/jsonschema/v5"
	"io"
	"regexp"
	"strconv"
	"unicode/utf8"
)

//go:embed contracts/*.json
var contracts embed.FS
var actionSchema, resultSchema *schema.Schema

func init() {
	c := schema.NewCompiler()
	c.AssertFormat = true
	for _, n := range []string{"action", "result"} {
		b, _ := contracts.ReadFile("contracts/" + n + ".schema.json")
		id := "https://utcp.invalid/v2/" + n
		c.AddResource(id, bytes.NewReader(b))
		v, e := c.Compile(id)
		if e != nil {
			panic(e)
		}
		if n == "action" {
			actionSchema = v
		} else {
			resultSchema = v
		}
	}
}

// Decode rejects duplicate keys, unknown fields, invalid UTF-8 and ambiguous
// generations before converting a closed contract to typed data.
func Decode(b []byte) (Action, error) {
	var a Action
	if len(b) > 65536 || !utf8.Valid(b) || !validEscapedUnicode(b) {
		return a, errors.New("invalid request size or encoding")
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	var scan func(int) (any, error)
	scan = func(depth int) (any, error) {
		if depth > 16 {
			return nil, errors.New("depth")
		}
		t, e := d.Token()
		if e != nil {
			return nil, e
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
					v, e := scan(depth + 1)
					if e != nil {
						return nil, e
					}
					m[s] = v
				}
				_, e = d.Token()
				return m, e
			}
			if x == '[' {
				v := []any{}
				for d.More() {
					n, e := scan(depth + 1)
					if e != nil {
						return nil, e
					}
					v = append(v, n)
				}
				_, e = d.Token()
				return v, e
			}
			return nil, errors.New("delimiter")
		default:
			return t, nil
		}
	}
	v, e := scan(0)
	if e != nil {
		return a, e
	}
	if _, e = d.Token(); e != io.EOF {
		return a, errors.New("trailing value")
	}
	if e = actionSchema.Validate(v); e != nil {
		return a, errors.New("invalid lifecycle contract")
	}
	if e = json.Unmarshal(b, &a); e != nil {
		return a, e
	}
	if e = Validate(a); e != nil {
		return Action{}, e
	}
	return a, nil
}

var generationRE = regexp.MustCompile(`^[1-9][0-9]{0,18}$`)

func number(s string) (int64, error) {
	if !generationRE.MatchString(s) {
		return 0, errors.New("invalid generation")
	}
	return strconv.ParseInt(s, 10, 64)
}
func Validate(a Action) error {
	if _, e := number(a.Generation); e != nil {
		return e
	}
	if !regexp.MustCompile(`^[0-9a-f]{32}$`).MatchString(a.Claim.Token) {
		return errors.New("invalid lease token")
	}
	if a.Action != a.Intent.Kind {
		return errors.New("intent discriminator")
	}
	if a.Target.Name != "utcp-"+a.Target.IncarnationID {
		return errors.New("physical name must contain exact unique incarnation")
	}
	if a.Action != "retire_delete" {
		if a.Intent.Projection == nil || a.Intent.Projection.Image != PauseImage || a.Intent.Projection.TemplateVersion != "inert.pause.v1" || ProjectionFingerprint(*a.Intent.Projection) != a.Intent.ProjectionFingerprint {
			return errors.New("unsupported projection")
		}
	}
	if a.Action == "retire_delete" && a.Intent.RetirementGeneration != a.Generation {
		return errors.New("retirement generation mismatch")
	}
	if a.Fingerprint != Fingerprint(a) {
		return errors.New("fingerprint mismatch")
	}
	return nil
}
func ValidateResult(r Result) error {
	b, e := json.Marshal(r)
	if e != nil {
		return e
	}
	var x any
	json.Unmarshal(b, &x)
	return resultSchema.Validate(x)
}

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
