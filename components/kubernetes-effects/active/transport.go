package active

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"mime"
	"net/http"
	"strings"
	"time"
)

const ActionPath = "/internal/infrastructure/v3/actions"
const MaxRequestBytes = 64 << 10
const MaxResponseBytes = 16 << 20

type TransportConfig struct {
	ExecutorIdentity string
	Engine           *Engine
}

// NewHandler creates no listener. Host lifecycle, trust and production binding
// require separate adoption. Observer and executor roles stay separate.
func NewHandler(cfg TransportConfig) (http.Handler, error) {
	if cfg.ExecutorIdentity == "" || cfg.Engine == nil {
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
		q, err := Decode(body)
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
		if err != nil || len(b) > MaxResponseBytes || ValidateResult(result) != nil {
			http.Error(w, "unavailable effect response", 503)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(b)
	}), nil
}
