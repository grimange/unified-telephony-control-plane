package kernel

import "testing"

func TestDefinitiveAPIRejection(t *testing.T) {
	for _, pair := range []struct {
		code   int
		reason string
	}{
		{400, "BadRequest"}, {401, "Unauthorized"}, {403, "Forbidden"},
		{404, "NotFound"}, {409, "Conflict"}, {409, "AlreadyExists"}, {422, "Invalid"},
	} {
		if !DefinitiveAPIRejection(pair.code, pair.reason) {
			t.Fatalf("supported structured rejection %d/%s denied", pair.code, pair.reason)
		}
	}
	for _, pair := range []struct {
		code   int
		reason string
	}{
		{200, "Success"}, {202, "Accepted"}, {400, "Unknown"}, {408, "Timeout"},
		{409, "Timeout"}, {409, "conflict"}, {422, "Unknown"}, {429, "TooManyRequests"},
		{500, "InternalError"}, {503, "ServiceUnavailable"}, {504, "Timeout"}, {0, ""},
	} {
		if DefinitiveAPIRejection(pair.code, pair.reason) {
			t.Fatalf("ambiguous/nonfailure %d/%s accepted", pair.code, pair.reason)
		}
	}
}
