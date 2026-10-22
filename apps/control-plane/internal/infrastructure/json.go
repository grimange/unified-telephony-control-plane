package infrastructure

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"strings"
	"unicode/utf8"
)

// DecodeStrict rejects unknown fields, duplicate keys at every depth and trailing
// values. Encoding/json alone accepts duplicate keys and is insufficient here.
func DecodeStrict(raw []byte, dst any) error {
	if !utf8.Valid(raw) {
		return errors.New("invalid protocol encoding")
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	d.UseNumber()
	if err := uniqueValue(d, 0); err != nil {
		return errors.New("invalid protocol JSON")
	}
	if _, err := d.Token(); err != io.EOF {
		return errors.New("trailing protocol JSON")
	}
	d = json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	if err := d.Decode(dst); err != nil {
		return errors.New("invalid protocol fields")
	}
	return nil
}
func uniqueValue(d *json.Decoder, depth int) error {
	if depth > 16 {
		return errors.New("protocol depth exceeded")
	}
	t, e := d.Token()
	if e != nil {
		return e
	}
	if s, ok := t.(string); ok && strings.ContainsRune(s, utf8.RuneError) {
		return errors.New("ambiguous unicode")
	}
	delim, ok := t.(json.Delim)
	if !ok {
		return nil
	}
	switch delim {
	case '{':
		seen := map[string]bool{}
		for d.More() {
			k, e := d.Token()
			if e != nil {
				return e
			}
			s, ok := k.(string)
			if !ok || seen[s] || strings.ContainsRune(s, utf8.RuneError) {
				return errors.New("duplicate field")
			}
			seen[s] = true
			if e = uniqueValue(d, depth+1); e != nil {
				return e
			}
		}
		_, e = d.Token()
		return e
	case '[':
		for d.More() {
			if e = uniqueValue(d, depth+1); e != nil {
				return e
			}
		}
		_, e = d.Token()
		return e
	}
	return errors.New("invalid delimiter")
}
