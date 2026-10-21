package effects

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"mime"
	"net/http"
	"strings"
	"time"
	"unicode/utf8"
)

const ActionPath = "/internal/infrastructure/v1/actions"
const MaxRequestBytes = 1 << 20
const MaxResponseBytes = 16 << 20

type TransportConfig struct {
	ExecutorIdentity string
	Engine           *Engine
	Validator        *Validator
}

// NewHandler creates no listener. Host lifecycle, trust and production binding
// require separate adoption. Observer and executor roles stay separate.
func NewHandler(cfg TransportConfig) (http.Handler, error) {
	if cfg.ExecutorIdentity == "" || cfg.Engine == nil || cfg.Validator == nil {
		return nil, errors.New("invalid effects transport configuration")
	}
	slots := make(chan struct{}, 4)
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		if r.URL.Path != ActionPath || r.URL.RawQuery != "" {
			http.Error(w, "unsupported endpoint", 404)
			return
		}
		if r.Method != http.MethodPost {
			w.Header().Set("Allow", "POST")
			http.Error(w, "unsupported method", 405)
			return
		}
		if r.TLS == nil || len(r.TLS.VerifiedChains) == 0 || len(r.TLS.PeerCertificates) == 0 {
			http.Error(w, "authenticated TLS required", 401)
			return
		}
		valid := false
		for _, uri := range r.TLS.PeerCertificates[0].URIs {
			if uri.String() == cfg.ExecutorIdentity {
				valid = true
			}
		}
		if !valid {
			http.Error(w, "executor identity required", 403)
			return
		}
		media, params, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
		if err != nil || media != "application/json" || len(params) > 1 || len(params) == 1 && !strings.EqualFold(params["charset"], "utf-8") || r.Header.Get("Content-Encoding") != "" && r.Header.Get("Content-Encoding") != "identity" {
			http.Error(w, "UTF-8 application/json required", 400)
			return
		}
		select {
		case slots <- struct{}{}:
			defer func() { <-slots }()
		default:
			w.Header().Set("Retry-After", "1")
			http.Error(w, "executor busy", 429)
			return
		}
		deadline := time.Now().Add(5 * time.Second)
		controller := http.NewResponseController(w)
		_ = controller.SetReadDeadline(deadline)
		_ = controller.SetWriteDeadline(deadline)
		body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, MaxRequestBytes))
		if err != nil {
			var size *http.MaxBytesError
			if errors.As(err, &size) {
				http.Error(w, "invalid request size", 413)
			} else {
				http.Error(w, "invalid request body", 400)
			}
			return
		}
		q, err := cfg.Validator.ParseAction(body)
		if err != nil {
			http.Error(w, "invalid action request", 400)
			return
		}
		if q.Deadline.Before(deadline) {
			deadline = q.Deadline
		}
		ctx, cancel := context.WithDeadline(r.Context(), deadline)
		defer cancel()
		result := cfg.Engine.Execute(ctx, q)
		b, err := json.Marshal(result)
		if err != nil || len(b) > MaxResponseBytes || cfg.Validator.ValidateResult(result) != nil {
			http.Error(w, "unavailable effect response", 503)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(b)
	}), nil
}

// strictJSON rejects duplicate decoded keys, trailing values, excessive nesting
// and invalid UTF-8 before trusting a claim response. No raw errors are exposed.
func strictJSON(b []byte) error {
	if !utf8.Valid(b) {
		return errors.New("invalid JSON")
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	var value func(int) error
	value = func(depth int) error {
		if depth > 32 {
			return errors.New("JSON depth limit")
		}
		t, err := d.Token()
		if err != nil {
			return err
		}
		delim, ok := t.(json.Delim)
		if !ok {
			return nil
		}
		switch delim {
		case '{':
			seen := map[string]bool{}
			for d.More() {
				k, err := d.Token()
				if err != nil {
					return err
				}
				key, ok := k.(string)
				if !ok || seen[key] {
					return errors.New("duplicate JSON key")
				}
				seen[key] = true
				if err = value(depth + 1); err != nil {
					return err
				}
			}
		case '[':
			for d.More() {
				if err = value(depth + 1); err != nil {
					return err
				}
			}
		default:
			return errors.New("invalid JSON delimiter")
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
