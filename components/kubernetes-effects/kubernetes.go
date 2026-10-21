package effects

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"io"
	appsv1 "k8s.io/api/apps/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/runtime/schema"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/kubernetes/scheme"
	"k8s.io/client-go/rest"
	"k8s.io/client-go/util/flowcontrol"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"sync/atomic"
	"time"
	"unicode/utf8"
)

// KubernetesConfig is trusted operator configuration, never supplied in an Action.
type KubernetesConfig struct {
	URL               string
	CA                []byte
	Token             string
	AllowedNamespaces []string
}
type KubernetesClient struct {
	client     rest.Interface
	namespaces map[string]bool
	limiter    flowcontrol.RateLimiter
}

var dnsName = regexp.MustCompile(`^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$`)

func NewKubernetesClient(c KubernetesConfig) (*KubernetesClient, error) {
	u, err := url.Parse(c.URL)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Path != "" && u.Path != "/") || len(c.AllowedNamespaces) == 0 {
		return nil, errors.New("invalid Kubernetes configuration")
	}
	pool := x509.NewCertPool()
	if len(c.CA) == 0 || !pool.AppendCertsFromPEM(c.CA) {
		return nil, errors.New("invalid Kubernetes CA")
	}
	ns := map[string]bool{}
	for _, n := range c.AllowedNamespaces {
		if len(n) > 253 || !dnsName.MatchString(n) {
			return nil, errors.New("invalid Kubernetes namespace")
		}
		ns[n] = true
	}
	cfg := &rest.Config{Host: strings.TrimRight(c.URL, "/"), APIPath: "/apis", ContentConfig: rest.ContentConfig{GroupVersion: &schema.GroupVersion{Group: "apps", Version: "v1"}, NegotiatedSerializer: scheme.Codecs.WithoutConversion(), ContentType: runtime.ContentTypeJSON}, BearerToken: c.Token, TLSClientConfig: rest.TLSClientConfig{CAData: c.CA}, Timeout: 5 * time.Second, QPS: -1, Burst: 10, UserAgent: "utcp-conditional-effects/1"}
	cfg.WrapTransport = func(base http.RoundTripper) http.RoundTripper {
		if tr, ok := base.(*http.Transport); ok {
			clone := tr.Clone()
			clone.TLSHandshakeTimeout = 2 * time.Second
			clone.ResponseHeaderTimeout = 5 * time.Second
			clone.DialContext = (&net.Dialer{Timeout: 2 * time.Second, KeepAlive: 30 * time.Second}).DialContext
			clone.TLSClientConfig = clone.TLSClientConfig.Clone()
			clone.TLSClientConfig.MinVersion = tls.VersionTLS13
			base = clone
		}
		return &effectGuard{base: base, host: u.Host, namespaces: ns}
	}
	client, err := rest.HTTPClientFor(cfg)
	if err != nil {
		return nil, errors.New("invalid Kubernetes transport")
	}
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	r, err := rest.RESTClientForConfigAndClient(cfg, client)
	if err != nil {
		return nil, errors.New("invalid Kubernetes client")
	}
	return &KubernetesClient{r, ns, flowcontrol.NewTokenBucketRateLimiter(5, 10)}, nil
}
func (c *KubernetesClient) valid(t Target) bool {
	return t.Kind == "Deployment" && c.namespaces[t.Namespace] && len(t.Name) <= 253 && dnsName.MatchString(t.Name)
}
func (c *KubernetesClient) Get(ctx context.Context, t Target) (*appsv1.Deployment, error) {
	if !c.valid(t) {
		return nil, errors.New("target outside configured scope")
	}
	if e := reserve(ctx, c.limiter); e != nil {
		return nil, e
	}
	result := c.client.Get().Namespace(t.Namespace).Resource("deployments").Name(t.Name).VersionedParams(&metav1.GetOptions{}, scheme.ParameterCodec).MaxRetries(0).Do(ctx)
	// Error decodes the server's structured Status; Raw alone retains the
	// generic HTTP error and loses authoritative reason and cause fields.
	if e := result.Error(); e != nil {
		return nil, e
	}
	b, e := result.Raw()
	if e != nil {
		return nil, e
	}
	return decodeDeployment(b)
}
func (c *KubernetesClient) Patch(ctx context.Context, t Target, b []byte) (*appsv1.Deployment, error) {
	if !c.valid(t) || len(b) > 1<<20 {
		return nil, errors.New("invalid conditional target")
	}
	permit, ok := ctx.Value(permitKey{}).(*dispatchPermit)
	if !ok || permit.client != c || permit.target != t || !permit.used.CompareAndSwap(false, true) {
		return nil, errors.New("mutation was not prepared")
	}
	if e := ctx.Err(); e != nil {
		return nil, e
	}
	result := c.client.Patch(types.JSONPatchType).Namespace(t.Namespace).Resource("deployments").Name(t.Name).VersionedParams(&metav1.PatchOptions{}, scheme.ParameterCodec).MaxRetries(0).Body(b).Do(ctx)
	if e := result.Error(); e != nil {
		return nil, e
	}
	raw, e := result.Raw()
	if e != nil {
		return nil, e
	}
	return decodeDeployment(raw)
}

func reserve(ctx context.Context, l flowcontrol.RateLimiter) error {
	if err := l.Wait(ctx); err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if _, ok := ctx.Deadline(); ok {
			return context.DeadlineExceeded
		}
		return err
	}
	return nil
}

// Prepare reserves API rate budget before the final canonical claim check.
// The returned one-shot permit is target/client bound and cannot survive cancellation.
type permitKey struct{}
type dispatchPermit struct {
	client *KubernetesClient
	target Target
	used   atomic.Bool
}

func (c *KubernetesClient) Prepare(ctx context.Context, t Target) (context.Context, error) {
	if !c.valid(t) {
		return ctx, errors.New("target outside configured scope")
	}
	if e := reserve(ctx, c.limiter); e != nil {
		return ctx, e
	}
	if e := ctx.Err(); e != nil {
		return ctx, e
	}
	return context.WithValue(ctx, permitKey{}, &dispatchPermit{client: c, target: t}), nil
}
func decodeDeployment(b []byte) (*appsv1.Deployment, error) {
	if len(b) > 16<<20 || !utf8.Valid(b) {
		return nil, errors.New("Kubernetes response bound")
	}
	var d appsv1.Deployment
	dec := json.NewDecoder(bytes.NewReader(b))
	if err := dec.Decode(&d); err != nil {
		return nil, errors.New("invalid Kubernetes response")
	}
	if dec.Decode(&struct{}{}) != io.EOF || d.Kind != "Deployment" || d.APIVersion != "apps/v1" {
		return nil, errors.New("invalid Kubernetes response")
	}
	return &d, nil
}

type effectGuard struct {
	base       http.RoundTripper
	host       string
	namespaces map[string]bool
}

func (g *effectGuard) RoundTrip(r *http.Request) (*http.Response, error) {
	parts := strings.Split(r.URL.Path, "/")
	if r.URL.Scheme != "https" || r.URL.Host != g.host || (r.URL.RawQuery != "" && r.URL.RawQuery != "timeout=5s") || len(parts) != 8 || parts[1] != "apis" || parts[2] != "apps" || parts[3] != "v1" || parts[4] != "namespaces" || !g.namespaces[parts[5]] || parts[6] != "deployments" || !dnsName.MatchString(parts[7]) || (r.Method != http.MethodGet && r.Method != http.MethodPatch) || (r.Method == http.MethodPatch && r.Header.Get("Content-Type") != "application/json-patch+json") {
		return nil, errors.New("Kubernetes request outside effect boundary")
	}
	if err := r.Context().Err(); err != nil {
		return nil, err
	}
	if deadline, ok := r.Context().Deadline(); ok && !time.Now().Before(deadline) {
		return nil, context.DeadlineExceeded
	}
	resp, err := g.base.RoundTrip(r)
	if err != nil {
		return nil, err
	}
	resp.Body = &limitedBody{reader: io.LimitReader(resp.Body, (16<<20)+1), closer: resp.Body}
	return resp, nil
}

type limitedBody struct {
	reader io.Reader
	closer io.Closer
}

func (b *limitedBody) Read(p []byte) (int, error) { return b.reader.Read(p) }
func (b *limitedBody) Close() error               { return b.closer.Close() }
