package lifecycle

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"mime"
	"net/http"
	"net/url"
	"time"

	effects "utcp.invalid/components/kubernetes-effects"
	observer "utcp.invalid/components/kubernetes-observer"
)

const ClaimPath = "/internal/infrastructure/v2/claims/verify"

// HTTPClaimVerifier calls one trusted Laravel endpoint with the entire validated
// action. It has no database, positive cache, retry, fallback or policy engine.
type HTTPClaimVerifier struct {
	endpoint string
	client   *observer.RotatingHTTPClient
}

func NewClaimVerifier(endpoint string, client *observer.RotatingHTTPClient) (*HTTPClaimVerifier, error) {
	u, err := url.Parse(endpoint)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.Path != ClaimPath || u.RawPath != "" || u.RawQuery != "" || u.Fragment != "" || client == nil || client.Client == nil {
		return nil, errors.New("invalid claim verifier configuration")
	}
	return &HTTPClaimVerifier{endpoint: endpoint, client: client}, nil
}

func (v *HTTPClaimVerifier) Verify(ctx context.Context, action Action) error {
	b, err := json.Marshal(action)
	if err != nil {
		return &DecisionError{Outcome: "invalid_request"}
	}
	if _, err = Decode(b); err != nil {
		return &DecisionError{Outcome: "invalid_request"}
	}
	// Callback validity is authoritative; this local bound additionally prevents
	// sending after the caller deadline or the supplied claim expiry.
	deadline := time.Now().Add(5 * time.Second)
	if action.Deadline.Before(deadline) {
		deadline = action.Deadline
	}
	if action.Claim.Expires.Before(deadline) {
		deadline = action.Claim.Expires
	}
	ctx, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	q, err := http.NewRequestWithContext(ctx, http.MethodPost, v.endpoint, bytes.NewReader(b))
	if err != nil {
		return &DecisionError{Outcome: "invalid_request"}
	}
	q.Header.Set("Content-Type", "application/json")
	q.Header.Set("Accept", "application/json")
	// RoundTrip deliberately bypasses automatic redirect/retry behavior. The
	// reused observer transport supplies strict HTTPS/mTLS and bounded dialing.
	response, err := v.client.RoundTrip(q)
	if err != nil {
		return claimFailure(ctx)
	}
	defer response.Body.Close()
	if response.StatusCode == http.StatusUnauthorized {
		return &DecisionError{Outcome: "unauthorized"}
	}
	if response.StatusCode == http.StatusForbidden {
		return &DecisionError{Outcome: "forbidden"}
	}
	if response.StatusCode != http.StatusOK {
		return &DecisionError{Outcome: "transport_unavailable"}
	}
	media, _, err := mime.ParseMediaType(response.Header.Get("Content-Type"))
	if err != nil || media != "application/json" {
		return &DecisionError{Outcome: "terminal_failure"}
	}
	b, err = io.ReadAll(io.LimitReader(response.Body, (16<<10)+1))
	if err != nil {
		return claimFailure(ctx)
	}
	if len(b) > 16<<10 || claimJSON(b) != nil {
		return &DecisionError{Outcome: "terminal_failure"}
	}
	var fields map[string]json.RawMessage
	if json.Unmarshal(b, &fields) != nil || len(fields) != 2 || fields["contract_version"] == nil || fields["authorized"] == nil {
		return &DecisionError{Outcome: "terminal_failure"}
	}
	var version string
	var authorized *bool
	if json.Unmarshal(fields["contract_version"], &version) != nil || version != "utcp.infrastructure.v2" || json.Unmarshal(fields["authorized"], &authorized) != nil || authorized == nil {
		return &DecisionError{Outcome: "terminal_failure"}
	}
	if !*authorized {
		return &DecisionError{Outcome: "unauthorized"}
	}
	if ctx.Err() != nil {
		return claimFailure(ctx)
	}
	return nil
}

func claimFailure(ctx context.Context) error {
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return &DecisionError{Outcome: "timeout"}
	}
	if errors.Is(ctx.Err(), context.Canceled) {
		return &DecisionError{Outcome: "cancelled"}
	}
	return &DecisionError{Outcome: "transport_unavailable"}
}

func claimJSON(b []byte) error { _, e := effects.StrictJSON(b); return e }
