package kernel

import "testing"

func TestExactGenerationBoundaries(t *testing.T) {
	for _, v := range []string{"1", "9007199254740993", MaxGeneration} {
		n, e := Generation(v)
		if e != nil || n < 1 {
			t.Fatalf("valid generation %s: %v", v, e)
		}
	}
	for _, v := range []string{"0", "-1", "01", "+1", "1.0", "1e2", "9223372036854775808", "9007199254740993.0", "", " 1"} {
		if _, e := Generation(v); e == nil {
			t.Fatalf("accepted malformed generation %q", v)
		}
	}
	if got, e := Increment("9007199254740993"); e != nil || got != "9007199254740994" {
		t.Fatalf("lossy allocation %q %v", got, e)
	}
	if _, e := Increment(MaxGeneration); e != ErrOverflow {
		t.Fatalf("overflow %v", e)
	}
}
func TestDefaultValidatorFailsClosed(t *testing.T) {
	s := New(nil, nil)
	if _, _, e := s.parse([]byte(`{"contract_version":"utcp.infrastructure.v3"}`)); e != ErrDenied {
		t.Fatal(e)
	}
}
func TestExactNullableUID(t *testing.T) {
	a := "uid"
	b := "other"
	if !same(nil, nil) || same(nil, &a) || same(&a, &b) || !same(&a, &a) {
		t.Fatal("identity comparison")
	}
}
