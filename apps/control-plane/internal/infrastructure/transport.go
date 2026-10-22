package infrastructure

import (
	"bytes"
	"context"
	"crypto"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"
	"sync/atomic"
	"time"
)

const DispatchVersion = "utcp.dispatch.v1"

type DispatchRequest struct {
	ContractVersion string          `json:"contract_version"`
	RequestID       string          `json:"request_id"`
	Operation       string          `json:"operation"`
	ReservationID   string          `json:"reservation_id"`
	Capability      string          `json:"capability"`
	Envelope        json.RawMessage `json:"envelope"`
	Deadline        time.Time       `json:"deadline"`
	Recovery        bool            `json:"recovery"`
	BlockedIDs      []string        `json:"blocked_ids"`
	Result          *DispatchResult `json:"result"`
}
type DispatchResult struct {
	Outcome   string `json:"outcome"`
	ReceiptID string `json:"receipt_id"`
}
type DispatchResponse struct {
	ContractVersion string `json:"contract_version"`
	ReservationID   string `json:"reservation_id"`
	State           string `json:"state"`
	Granted         bool   `json:"granted"`
	Retry           string `json:"retry"`
}

// DispatchBackend performs durable authorization. No transport handler executes
// an infrastructure action; successful consumption is not effect success.
type DispatchBackend interface {
	Dispatch(context.Context, string, DispatchRequest) (DispatchResponse, error)
}

func certificateSAN(c *x509.Certificate, allowed map[string]bool) string {
	for _, u := range c.URIs {
		if allowed[u.String()] {
			return u.String()
		}
	}
	for _, d := range c.DNSNames {
		if allowed[d] {
			return d
		}
	}
	return ""
}

// ServerTLS requires chain verification AND an explicit SAN identity. CN is not
// accepted. A fresh handshake verifies rotated client certificates every time.
func ServerTLS(certificate func(*tls.ClientHelloInfo) (*tls.Certificate, error), ca *x509.CertPool, allowed map[string]bool) *tls.Config {
	ids := map[string]bool{}
	for k, v := range allowed {
		ids[k] = v
	}
	return &tls.Config{MinVersion: tls.VersionTLS13, ClientAuth: tls.RequireAndVerifyClientCert, ClientCAs: ca, GetCertificate: certificate, VerifyConnection: func(s tls.ConnectionState) error {
		if len(s.VerifiedChains) == 0 || len(s.PeerCertificates) == 0 || certificateSAN(s.PeerCertificates[0], ids) == "" {
			return errors.New("unauthorized peer")
		}
		return nil
	}}
}
func DispatchHandler(backend DispatchBackend, allowed map[string]bool, maxConcurrent int) http.Handler {
	if maxConcurrent < 1 {
		maxConcurrent = 1
	}
	slots := make(chan struct{}, maxConcurrent)
	ids := map[string]bool{}
	for k, v := range allowed {
		ids[k] = v
	}
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reject := func(code int) {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(code)
			_, _ = io.WriteString(w, `{"error":"dispatch_rejected"}`)
		}
		if r.TLS == nil || len(r.TLS.VerifiedChains) == 0 || len(r.TLS.PeerCertificates) == 0 {
			reject(http.StatusUnauthorized)
			return
		}
		peer := certificateSAN(r.TLS.PeerCertificates[0], ids)
		if peer == "" {
			reject(http.StatusForbidden)
			return
		}
		if r.Method != "POST" || r.URL.Path != "/internal/dispatch/v1" || r.URL.RawQuery != "" {
			reject(http.StatusNotFound)
			return
		}
		if r.Header.Get("Content-Type") != "application/json" {
			reject(http.StatusUnsupportedMediaType)
			return
		}
		select {
		case slots <- struct{}{}:
			defer func() { <-slots }()
		default:
			reject(http.StatusTooManyRequests)
			return
		}
		// Bound pre-decoding body acquisition as well as the decoded request
		// deadline. This local HTTPS seam requires a deadline-capable HTTP server.
		controller := http.NewResponseController(w)
		if controller.SetReadDeadline(time.Now().Add(5*time.Second)) != nil {
			reject(http.StatusServiceUnavailable)
			return
		}
		defer controller.SetReadDeadline(time.Time{})
		raw, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 65536))
		if err != nil {
			reject(http.StatusRequestEntityTooLarge)
			return
		}
		var q DispatchRequest
		var required map[string]json.RawMessage
		if json.Unmarshal(raw, &required) != nil || len(required) != 10 {
			reject(http.StatusBadRequest)
			return
		}
		for _, field := range []string{"contract_version", "request_id", "operation", "reservation_id", "capability", "envelope", "deadline", "recovery", "blocked_ids", "result"} {
			value, ok := required[field]
			if !ok || (string(value) == "null" && field != "envelope" && field != "blocked_ids" && field != "result") {
				reject(http.StatusBadRequest)
				return
			}
		}
		if DecodeStrict(raw, &q) != nil || q.ContractVersion != DispatchVersion || q.RequestID == "" || len(q.RequestID) > 128 || q.Deadline.IsZero() || !q.Deadline.After(time.Now()) || q.Deadline.After(time.Now().Add(30*time.Second)) {
			reject(http.StatusBadRequest)
			return
		}
		if q.Operation != "reserve" && q.Operation != "consume" && q.Operation != "status" && q.Operation != "result" {
			reject(http.StatusBadRequest)
			return
		}
		if len(q.Capability) > 512 || len(q.ReservationID) > 128 || len(q.BlockedIDs) > 32 || strings.ContainsAny(q.RequestID, "\r\n") {
			reject(http.StatusBadRequest)
			return
		}
		if (q.Operation == "reserve" || q.Operation == "consume") && (len(q.Envelope) == 0 || q.Envelope[0] != '{' || q.Capability == "") {
			reject(http.StatusBadRequest)
			return
		}
		if q.Operation != "reserve" && q.ReservationID == "" {
			reject(http.StatusBadRequest)
			return
		}
		for _, id := range q.BlockedIDs {
			if id == "" || len(id) > 128 || strings.ContainsAny(id, "\x00\r\n") {
				reject(http.StatusBadRequest)
				return
			}
		}
		if q.Operation == "result" {
			if q.Result == nil || len(q.Result.ReceiptID) > 512 || (q.Result.Outcome != "unknown" && q.Result.Outcome != "applied" && q.Result.Outcome != "not_applied") {
				reject(http.StatusBadRequest)
				return
			}
		} else if q.Result != nil {
			reject(http.StatusBadRequest)
			return
		}
		ctx, cancel := context.WithDeadline(r.Context(), q.Deadline)
		defer cancel()
		out, err := backend.Dispatch(ctx, peer, q)
		if err != nil {
			reject(http.StatusConflict)
			return
		}
		if len(out.ReservationID) > 128 || len(out.State) > 128 || len(out.Retry) > 128 || strings.ContainsAny(out.ReservationID+out.State+out.Retry, "\x00\r\n") {
			reject(http.StatusInternalServerError)
			return
		}
		out.ContractVersion = DispatchVersion
		// Projection is closed and deliberately contains no bearer capability or
		// upstream error text. Once a grant might have committed, IO must observe
		// status after response interruption rather than replaying consume.
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(out)
	})
}

// CertificateStore swaps only a validated certificate. A failed rotation leaves
// the previous certificate usable. Keys exist only in caller-owned memory.
type CertificateStore struct {
	current atomic.Pointer[tls.Certificate]
}

func (s *CertificateStore) Replace(c tls.Certificate, now time.Time) error {
	if len(c.Certificate) == 0 || c.PrivateKey == nil {
		return errors.New("invalid certificate")
	}
	leaf, err := x509.ParseCertificate(c.Certificate[0])
	if err != nil || now.Before(leaf.NotBefore) || !now.Before(leaf.NotAfter) {
		return errors.New("invalid certificate")
	}
	signer, ok := c.PrivateKey.(crypto.Signer)
	if !ok {
		return errors.New("invalid certificate key")
	}
	a, e := x509.MarshalPKIXPublicKey(signer.Public())
	if e != nil {
		return errors.New("invalid certificate key")
	}
	b, e := x509.MarshalPKIXPublicKey(leaf.PublicKey)
	if e != nil || !bytes.Equal(a, b) {
		return errors.New("certificate key mismatch")
	}
	c.Leaf = leaf
	s.current.Store(&c)
	return nil
}
func (s *CertificateStore) GetCertificate(*tls.ClientHelloInfo) (*tls.Certificate, error) {
	c := s.current.Load()
	if c == nil {
		return nil, errors.New("certificate unavailable")
	}
	return c, nil
}
