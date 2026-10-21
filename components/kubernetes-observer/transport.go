package observer

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"mime"
	"net"
	"net/http"
	"regexp"
	"strings"
	"sync/atomic"
	"time"
	"unicode/utf8"
)

const ObservationPath = "/internal/infrastructure/v1/observations"
const MaxRequestBytes = 1 << 20
const MaxResponseBytes = 16 << 20

var identifierPattern = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$`)
var namespacePattern = regexp.MustCompile(`^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$`)

// TransportConfig is operator configuration, not caller-selected authority.
// There is no listener, database, tenant API, or action endpoint in this package.
type TransportConfig struct {
	ClusterID         string
	AllowedNamespaces []string
	ClientIdentity    string // exact URI SAN of the Laravel observer identity
	Observe           func(context.Context, Request) Snapshot
	ValidateRequest   func([]byte) error
	ValidateResponse  func(Snapshot) error
}

// NewHandler builds an isolated HTTPS-only RPC handler. A full observation slot
// is held through response serialization; saturation has no waiting queue.
func NewHandler(cfg TransportConfig) (http.Handler, error) {
	if !identifierPattern.MatchString(cfg.ClusterID) || cfg.ClientIdentity == "" || cfg.Observe == nil || len(cfg.AllowedNamespaces) == 0 {
		return nil, errors.New("invalid observer transport configuration")
	}
	scopes := make(map[string]bool, len(cfg.AllowedNamespaces))
	for _, s := range cfg.AllowedNamespaces {
		if s != "*" && (len(s) > 253 || !namespacePattern.MatchString(s)) {
			return nil, errors.New("invalid configured namespace")
		}
		scopes[s] = true
	}
	slots := make(chan struct{}, 4)
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		if r.URL.Path != ObservationPath || r.URL.RawQuery != "" {
			http.Error(w, "unsupported endpoint", http.StatusNotFound)
			return
		}
		if r.Method != http.MethodPost {
			w.Header().Set("Allow", http.MethodPost)
			http.Error(w, "unsupported method", http.StatusMethodNotAllowed)
			return
		}
		if r.TLS == nil || len(r.TLS.VerifiedChains) == 0 {
			http.Error(w, "authenticated TLS required", http.StatusUnauthorized)
			return
		}
		if !hasURIIdentity(r.TLS.PeerCertificates, cfg.ClientIdentity) {
			http.Error(w, "observer identity required", http.StatusForbidden)
			return
		}
		media, params, mediaErr := mime.ParseMediaType(r.Header.Get("Content-Type"))
		if mediaErr != nil || media != "application/json" || len(params) > 1 || (len(params) == 1 && !strings.EqualFold(params["charset"], "utf-8")) || (r.Header.Get("Content-Encoding") != "" && r.Header.Get("Content-Encoding") != "identity") {
			http.Error(w, "UTF-8 application/json required", http.StatusBadRequest)
			return
		}
		select {
		case slots <- struct{}{}:
			defer func() { <-slots }()
		default:
			w.Header().Set("Retry-After", "1")
			http.Error(w, "observer busy", http.StatusTooManyRequests)
			return
		}
		// Bound body reads as well as Kubernetes collection. Cancellation is
		// cooperative in Observe; the Collector passes this context to client-go.
		deadline := time.Now().Add(5 * time.Second)
		ctx, cancel := context.WithDeadline(r.Context(), deadline)
		defer cancel()
		controller := http.NewResponseController(w)
		_ = controller.SetReadDeadline(deadline)
		_ = controller.SetWriteDeadline(deadline)
		body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, MaxRequestBytes))
		if err != nil {
			var tooLarge *http.MaxBytesError
			status := http.StatusBadRequest
			if errors.As(err, &tooLarge) {
				status = http.StatusRequestEntityTooLarge
			}
			http.Error(w, "invalid request body", status)
			return
		}
		q, err := ParseObservationRequest(body)
		if err == nil && cfg.ValidateRequest != nil {
			err = cfg.ValidateRequest(body)
		}
		if err != nil {
			http.Error(w, "invalid observation request", http.StatusBadRequest)
			return
		}
		if q.ClusterID != cfg.ClusterID || !scopes[q.Scope.PodNamespaces[0]] {
			http.Error(w, "observation scope forbidden", http.StatusForbidden)
			return
		}
		if q.Deadline.Before(deadline) {
			cancel()
			ctx, cancel = context.WithDeadline(r.Context(), q.Deadline)
			defer cancel()
		}
		snapshot := cfg.Observe(ctx, q)
		var errResponse error
		if cfg.ValidateResponse != nil {
			errResponse = cfg.ValidateResponse(snapshot)
		}
		encoded, err := json.Marshal(snapshot)
		if errResponse != nil {
			err = errResponse
		}
		if err != nil || len(encoded) > MaxResponseBytes {
			// Never emit a truncated successful envelope. Collector normally
			// produces a bounded incomplete snapshot before this defensive gate.
			http.Error(w, "unavailable observation response", http.StatusServiceUnavailable)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(encoded)
	}), nil
}

// ParseObservationRequest applies the closed v1 request schema, including
// lexical constraints that encoding/json alone does not enforce. It deliberately
// accepts no URLs, tokens, tenant claims, raw objects, operations or selectors.
func ParseObservationRequest(body []byte) (Request, error) {
	var q Request
	if len(body) > MaxRequestBytes || !utf8.Valid(body) {
		return q, errors.New("invalid UTF-8 or request size")
	}
	if err := rejectDuplicateKeys(body); err != nil {
		return q, err
	}
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(body, &fields); err != nil || len(fields) != 5 {
		return q, errors.New("required request fields missing")
	}
	for _, field := range []string{"contract_version", "request_id", "cluster_id", "scope", "deadline"} {
		if raw, ok := fields[field]; !ok || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
			return q, errors.New("null or missing request field")
		}
	}
	var scopeFields map[string]json.RawMessage
	if err := json.Unmarshal(fields["scope"], &scopeFields); err != nil || len(scopeFields) != 1 || scopeFields["pod_namespaces"] == nil {
		return q, errors.New("invalid scope structure")
	}
	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&q); err != nil {
		return q, errors.New("invalid request structure")
	}
	if q.Version != Version || !identifierPattern.MatchString(q.RequestID) || !identifierPattern.MatchString(q.ClusterID) || q.Deadline.IsZero() || len(q.Scope.PodNamespaces) != 1 {
		return q, errors.New("invalid request value")
	}
	namespace := q.Scope.PodNamespaces[0]
	if namespace != "*" && (len(namespace) > 253 || !namespacePattern.MatchString(namespace)) {
		return q, errors.New("invalid request namespace")
	}
	return q, nil
}

// Decode every object recursively, rejecting duplicate names even when their
// escape spellings differ. Depth is capped before recursive parser growth.
func rejectDuplicateKeys(body []byte) error {
	d := json.NewDecoder(bytes.NewReader(body))
	d.UseNumber()
	var value func(int) error
	value = func(depth int) error {
		if depth > 32 {
			return errors.New("JSON nesting exceeds request bound")
		}
		t, err := d.Token()
		if err != nil {
			return err
		}
		delim, composite := t.(json.Delim)
		if !composite {
			return nil
		}
		switch delim {
		case '{':
			seen := map[string]bool{}
			for d.More() {
				key, err := d.Token()
				if err != nil {
					return err
				}
				s, ok := key.(string)
				if !ok || seen[s] {
					return errors.New("duplicate JSON key")
				}
				seen[s] = true
				if err := value(depth + 1); err != nil {
					return err
				}
			}
		case '[':
			for d.More() {
				if err := value(depth + 1); err != nil {
					return err
				}
			}
		default:
			return errors.New("unexpected JSON delimiter")
		}
		_, err = d.Token()
		return err
	}
	if err := value(0); err != nil {
		return err
	}
	if _, err := d.Token(); err != io.EOF {
		return errors.New("trailing JSON")
	}
	return nil
}

func hasURIIdentity(certs []*x509.Certificate, identity string) bool {
	if len(certs) == 0 {
		return false
	}
	for _, uri := range certs[0].URIs {
		if uri.String() == identity {
			return true
		}
	}
	return false
}

// ServerTLSMaterial swaps an entire validated certificate/trust configuration.
// Failed reload keeps the old state. New TLS handshakes use the new state;
// established/in-flight connections are not retroactively revoked.
type ServerTLSMaterial struct{ current atomic.Pointer[tls.Config] }

func NewServerTLS(certPEM, keyPEM, clientCAPEM []byte, expectedClientURI string) (*ServerTLSMaterial, error) {
	m := &ServerTLSMaterial{}
	if err := m.Reload(certPEM, keyPEM, clientCAPEM, expectedClientURI); err != nil {
		return nil, err
	}
	return m, nil
}

func (m *ServerTLSMaterial) Reload(certPEM, keyPEM, clientCAPEM []byte, expectedClientURI string) error {
	cert, err := checkedKeyPair(certPEM, keyPEM, x509.ExtKeyUsageServerAuth)
	if err != nil {
		return err
	}
	ca, err := checkedCAPool(clientCAPEM)
	if err != nil || expectedClientURI == "" {
		return errors.New("invalid client trust configuration")
	}
	cfg := &tls.Config{
		MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{cert},
		ClientAuth: tls.RequireAndVerifyClientCert, ClientCAs: ca,
		SessionTicketsDisabled: true,
		VerifyConnection: func(cs tls.ConnectionState) error {
			if !hasURIIdentity(cs.PeerCertificates, expectedClientURI) {
				return errors.New("observer certificate identity rejected")
			}
			return nil
		},
	}
	m.current.Store(cfg)
	return nil
}

func (m *ServerTLSMaterial) Config() *tls.Config {
	return &tls.Config{MinVersion: tls.VersionTLS13, SessionTicketsDisabled: true,
		GetConfigForClient: func(*tls.ClientHelloInfo) (*tls.Config, error) {
			cfg := m.current.Load()
			if cfg == nil {
				return nil, errors.New("TLS material unavailable")
			}
			return cfg, nil
		},
	}
}

// RotatingHTTPClient keeps certificate and trust changes atomic. Reload closes
// idle connections; requests already dispatched finish under their old context.
type RotatingHTTPClient struct {
	current atomic.Pointer[http.Transport]
	Client  *http.Client
}

func NewHTTPClient(serverCAPEM, clientCertPEM, clientKeyPEM []byte, serverName string) (*RotatingHTTPClient, error) {
	m := &RotatingHTTPClient{}
	if err := m.Reload(serverCAPEM, clientCertPEM, clientKeyPEM, serverName); err != nil {
		return nil, err
	}
	m.Client = &http.Client{Transport: m, Timeout: 5 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	return m, nil
}

func (m *RotatingHTTPClient) RoundTrip(r *http.Request) (*http.Response, error) {
	if r.URL.Scheme != "https" {
		return nil, errors.New("observer transport requires HTTPS")
	}
	t := m.current.Load()
	if t == nil {
		return nil, errors.New("client TLS material unavailable")
	}
	return t.RoundTrip(r)
}

func (m *RotatingHTTPClient) CloseIdleConnections() {
	if t := m.current.Load(); t != nil {
		t.CloseIdleConnections()
	}
}

func (m *RotatingHTTPClient) Reload(serverCAPEM, clientCertPEM, clientKeyPEM []byte, serverName string) error {
	ca, err := checkedCAPool(serverCAPEM)
	if err != nil || strings.TrimSpace(serverName) == "" {
		return errors.New("invalid server trust configuration")
	}
	cert, err := checkedKeyPair(clientCertPEM, clientKeyPEM, x509.ExtKeyUsageClientAuth)
	if err != nil {
		return err
	}
	t := &http.Transport{
		Proxy: nil, DialContext: (&net.Dialer{Timeout: 2 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSClientConfig:     &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: ca, ServerName: serverName, Certificates: []tls.Certificate{cert}},
		TLSHandshakeTimeout: 2 * time.Second, ResponseHeaderTimeout: 5 * time.Second,
		MaxConnsPerHost: 4, MaxIdleConns: 4, MaxIdleConnsPerHost: 4, IdleConnTimeout: 30 * time.Second,
	}
	old := m.current.Swap(t)
	if old != nil {
		old.CloseIdleConnections()
	}
	return nil
}

func checkedCAPool(data []byte) (*x509.CertPool, error) {
	pool := x509.NewCertPool()
	count := 0
	for len(bytes.TrimSpace(data)) > 0 {
		block, rest := pem.Decode(data)
		if block == nil || block.Type != "CERTIFICATE" {
			return nil, errors.New("CA PEM unavailable or invalid")
		}
		cert, err := x509.ParseCertificate(block.Bytes)
		now := time.Now()
		if err != nil || !cert.IsCA || now.Before(cert.NotBefore) || !now.Before(cert.NotAfter) {
			return nil, errors.New("CA certificate is not currently valid")
		}
		pool.AddCert(cert)
		count++
		data = rest
	}
	if count == 0 {
		return nil, errors.New("CA PEM unavailable or invalid")
	}
	return pool, nil
}

func checkedKeyPair(certPEM, keyPEM []byte, usage x509.ExtKeyUsage) (tls.Certificate, error) {
	cert, err := tls.X509KeyPair(certPEM, keyPEM)
	if err != nil {
		return cert, errors.New("certificate/key material invalid")
	}
	leaf, err := x509.ParseCertificate(cert.Certificate[0])
	if err != nil {
		return cert, errors.New("certificate invalid")
	}
	now := time.Now()
	if now.Before(leaf.NotBefore) || !now.Before(leaf.NotAfter) {
		return cert, errors.New("certificate is not currently valid")
	}
	validUsage := false
	for _, u := range leaf.ExtKeyUsage {
		if u == usage {
			validUsage = true
		}
	}
	if !validUsage {
		return cert, fmt.Errorf("certificate missing required EKU %d", usage)
	}
	cert.Leaf = leaf
	return cert, nil
}
