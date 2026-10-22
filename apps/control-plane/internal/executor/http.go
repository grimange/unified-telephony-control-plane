package executor

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"io"
	"net/http"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

type HTTPConfig struct {
	URL       string
	TLS       *tls.Config
	ServerSAN string
	Timeout   time.Duration
}

// APIStatus is a synthetic, closed, authenticated response correlation contract.
// It intentionally omits Kubernetes message text and cannot stand in for a real
// Kubernetes executor's end-to-end response authenticity obligations.
type APIStatus struct {
	Kind         string `json:"kind"`
	Status       string `json:"status"`
	Code         int    `json:"code"`
	Reason       string `json:"reason"`
	DispatchID   string `json:"dispatch_id"`
	EnvelopeHash string `json:"envelope_hash"`
	BodyHash     string `json:"body_hash"`
}

func definitive(code int, reason string) bool { return kernel.DefinitiveAPIRejection(code, reason) }

// Submit permits one HTTP RoundTrip after a durable possible-submission commit.
// A fresh HTTP/1.1 transport, non-idempotent POST, nil GetBody, disabled proxy,
// disabled keep-alives, and no Client redirect loop prevent automatic replays.
// All failures after that commit remain possible and cannot produce zero-send
// evidence. The fixed loopback HTTPS endpoint is signed into the grant.
func (j *Journal) Submit(ctx context.Context, id string, c HTTPConfig) (out Entry, err error) {
	j.hook("before_possible")
	err = j.withLock(ctx, func(es map[string]Entry, h head) error {
		var ok bool
		out, ok = es[id]
		if !ok {
			return ErrDenied
		}
		if out.State != "accepted" || out.Grant.Phase != "consumed" {
			return ErrUncertain
		}
		if !time.Now().Before(out.Grant.Deadline) || c.URL != out.Grant.Endpoint || c.ServerSAN != out.Grant.ServerName || c.TLS == nil || c.TLS.InsecureSkipVerify || c.TLS.RootCAs == nil || c.TLS.VerifyConnection != nil || c.TLS.VerifyPeerCertificate != nil || c.TLS.GetClientCertificate != nil || c.TLS.KeyLogWriter != nil || len(c.TLS.Certificates) != 1 || !clientIdentity(c.TLS.Certificates[0], out.Grant.Executor) {
			return ErrDenied
		}
		out.State = "possible"
		if e := j.append(h, out); e != nil {
			return e
		}
		j.hook("after_possible")
		return nil
	})
	if err != nil {
		return out, err
	}
	j.hook("before_send")
	tc := c.TLS.Clone()
	tc.ServerName = c.ServerSAN
	tc.MinVersion = tls.VersionTLS13
	tc.NextProtos = []string{"http/1.1"}
	tr := &http.Transport{TLSClientConfig: tc, DisableKeepAlives: true, ForceAttemptHTTP2: false, TLSNextProto: map[string]func(string, *tls.Conn) http.RoundTripper{}}
	defer tr.CloseIdleConnections()
	timeout := c.Timeout
	if timeout <= 0 || timeout > 30*time.Second {
		timeout = 5 * time.Second
	}
	deadline := time.Now().Add(timeout)
	if out.Grant.Deadline.Before(deadline) {
		deadline = out.Grant.Deadline
	}
	sendctx, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	body := SyntheticBody(out.Grant)
	req, e := http.NewRequestWithContext(sendctx, http.MethodPost, c.URL, bytes.NewReader(body))
	if e != nil {
		return out, ErrUncertain
	}
	req.GetBody = nil
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-UTCP-Dispatch-ID", out.Grant.DispatchID)
	req.Header.Set("X-UTCP-Envelope-Hash", out.Grant.EnvelopeHash)
	req.Header.Set("X-UTCP-Body-Hash", out.Grant.BodyHash)
	resp, e := tr.RoundTrip(req)
	if e != nil {
		return out, ErrUncertain
	}
	defer resp.Body.Close()
	j.hook("after_response")
	raw, e := io.ReadAll(io.LimitReader(resp.Body, 4097))
	if e != nil || len(raw) > 4096 || resp.TLS == nil || len(resp.TLS.VerifiedChains) == 0 {
		return out, ErrUncertain
	}
	var status APIStatus
	if strict(raw, &status) != nil || status.Kind != "Status" || status.DispatchID != id || status.EnvelopeHash != out.Grant.EnvelopeHash || status.BodyHash != out.Grant.BodyHash || status.Code != resp.StatusCode {
		return out, ErrUncertain
	}
	// Ensure all fields were explicit; omitted/null response identity is not proof.
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(raw, &fields)
	if len(fields) != 7 {
		return out, ErrUncertain
	}
	for _, v := range fields {
		if string(v) == "null" {
			return out, ErrUncertain
		}
	}
	next := ""
	if status.Status == "Failure" && definitive(status.Code, status.Reason) {
		next = "api_rejected"
	}
	if status.Status == "Success" && status.Code >= 200 && status.Code < 300 && status.Reason == "" {
		next = "succeeded"
	}
	if next == "" {
		return out, ErrUncertain
	}
	j.hook("before_result")
	err = j.withLock(ctx, func(es map[string]Entry, h head) error {
		current, ok := es[id]
		if !ok || current.State != "possible" {
			return ErrStorage
		}
		current.State = next
		current.APICode = status.Code
		current.APIReason = status.Reason
		if e := j.append(h, current); e != nil {
			return e
		}
		out = current
		j.hook("after_result")
		return nil
	})
	return
}

func clientIdentity(c tls.Certificate, expected string) bool {
	if len(c.Certificate) == 0 || c.PrivateKey == nil {
		return false
	}
	leaf, e := x509.ParseCertificate(c.Certificate[0])
	if e != nil || time.Now().Before(leaf.NotBefore) || !time.Now().Before(leaf.NotAfter) {
		return false
	}
	for _, name := range leaf.DNSNames {
		if name == expected {
			return true
		}
	}
	for _, uri := range leaf.URIs {
		if uri.String() == expected {
			return true
		}
	}
	return false
}
