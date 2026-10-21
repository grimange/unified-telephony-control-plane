package observer

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync/atomic"
	"time"
	"unicode/utf8"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/runtime/serializer"
	"k8s.io/client-go/rest"
	"k8s.io/client-go/util/flowcontrol"
)

// UpstreamConfig is trusted bootstrap configuration, never a request payload.
// Credential reload is explicit and atomic; bad material preserves last-good.
type UpstreamConfig struct {
	URL       string
	CAPEM     []byte
	Token     string
	Namespace string
}
type credential struct {
	token     string
	transport *http.Transport
}
type KubernetesReader struct {
	client    rest.Interface
	state     atomic.Pointer[credential]
	namespace string
	host      string
}

func upstreamCredential(c UpstreamConfig) (*credential, error) {
	roots, err := checkedCAPool(c.CAPEM)
	if err != nil || strings.TrimSpace(c.Token) == "" || strings.ContainsAny(c.Token, "\r\n") {
		return nil, errors.New("invalid upstream credential")
	}
	return &credential{c.Token, &http.Transport{TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots}, DialContext: (&net.Dialer{Timeout: 2 * time.Second}).DialContext, TLSHandshakeTimeout: 2 * time.Second, ResponseHeaderTimeout: 5 * time.Second, MaxIdleConns: 8, MaxIdleConnsPerHost: 4}}, nil
}

// The underlying token bucket may reject a wait before its deadline elapses.
// One token is always below burst; other Wait failures mean cancellation or
// insufficient deadline budget. Normalize that untyped library error safely.
type deadlineLimiter struct{ flowcontrol.RateLimiter }

func (l deadlineLimiter) Wait(ctx context.Context) error {
	err := l.RateLimiter.Wait(ctx)
	if err == nil {
		return nil
	}
	if ctx.Err() != nil {
		return ctx.Err()
	}
	if _, ok := ctx.Deadline(); ok {
		return context.DeadlineExceeded
	}
	return err
}
func NewKubernetesReader(c UpstreamConfig) (*KubernetesReader, error) {
	u, e := url.Parse(c.URL)
	if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Path != "" && u.Path != "/" || c.Namespace == "" {
		return nil, errors.New("invalid upstream configuration")
	}
	cr, e := upstreamCredential(c)
	if e != nil {
		return nil, e
	}
	r := &KubernetesReader{namespace: c.Namespace, host: u.Host}
	r.state.Store(cr)
	scheme := runtime.NewScheme()
	if e = corev1.AddToScheme(scheme); e != nil {
		return nil, e
	}
	cfg := &rest.Config{Host: c.URL, APIPath: "/api", ContentConfig: rest.ContentConfig{GroupVersion: &corev1.SchemeGroupVersion, NegotiatedSerializer: serializer.NewCodecFactory(scheme).WithoutConversion(), ContentType: "application/json", AcceptContentTypes: "application/json"}, RateLimiter: deadlineLimiter{flowcontrol.NewTokenBucketRateLimiter(5, 10)}, Timeout: 5 * time.Second}
	client := &http.Client{Transport: r, Timeout: 5 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	r.client, e = rest.RESTClientForConfigAndClient(cfg, client)
	return r, e
}
func (r *KubernetesReader) Reload(c UpstreamConfig) error {
	// Endpoint/scope changes are not credential rotation.
	u, e := url.Parse(c.URL)
	if e != nil || u.Scheme != "https" || u.Host != r.host || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Path != "" && u.Path != "/") || c.Namespace != r.namespace {
		return errors.New("rotation changed endpoint or scope")
	}
	cr, e := upstreamCredential(c)
	if e != nil {
		return e
	}
	old := r.state.Swap(cr)
	old.transport.CloseIdleConnections()
	return nil
}
func (r *KubernetesReader) Close() { r.state.Load().transport.CloseIdleConnections() }

// RoundTrip is a second enforcement boundary: only GET to the exact two fixed
// collection paths is possible, including when client-go redirects or changes.
func (r *KubernetesReader) RoundTrip(q *http.Request) (*http.Response, error) {
	podPath := "/api/v1/pods"
	if r.namespace != "*" {
		podPath = "/api/v1/namespaces/" + r.namespace + "/pods"
	}
	if q.Method != http.MethodGet || q.URL.Scheme != "https" || q.URL.Host != r.host || (q.URL.Path != "/api/v1/nodes" && q.URL.Path != podPath) {
		return nil, errors.New("read-only scope rejected")
	}
	cr := r.state.Load()
	copy := q.Clone(q.Context())
	copy.Header = q.Header.Clone()
	copy.Header.Set("Authorization", "Bearer "+cr.token)
	response, e := cr.transport.RoundTrip(copy)
	if e != nil {
		return nil, e
	}
	defer response.Body.Close()
	// Bound raw decoded input before client-go allocates a resource object. A
	// snapshot also has a separate aggregate object/projected-size bound.
	data, e := io.ReadAll(io.LimitReader(response.Body, (16<<20)+1))
	if e != nil {
		return nil, e
	}
	if len(data) > 16<<20 {
		return nil, errInvalidList
	}
	response.Body = io.NopCloser(bytes.NewReader(data))
	return response, nil
}
func (r *KubernetesReader) ListNodes(ctx context.Context, o metav1.ListOptions) (*corev1.NodeList, error) {
	data, e := r.client.Get().Resource("nodes").VersionedParams(&o, metav1.ParameterCodec).MaxRetries(0).Do(ctx).Raw()
	if e != nil {
		return nil, e
	}
	out := new(corev1.NodeList)
	if !utf8.Valid(data) || json.Unmarshal(data, out) != nil || out.Kind != "NodeList" || out.APIVersion != "v1" || out.Items == nil {
		return nil, errInvalidList
	}
	return out, nil
}
func (r *KubernetesReader) ListPods(ctx context.Context, ns string, o metav1.ListOptions) (*corev1.PodList, error) {
	expected := r.namespace
	if expected == "*" {
		expected = ""
	}
	if ns != expected {
		return nil, errors.New("read-only scope rejected")
	}
	data, e := r.client.Get().Namespace(ns).Resource("pods").VersionedParams(&o, metav1.ParameterCodec).MaxRetries(0).Do(ctx).Raw()
	if e != nil {
		return nil, e
	}
	out := new(corev1.PodList)
	if !utf8.Valid(data) || json.Unmarshal(data, out) != nil || out.Kind != "PodList" || out.APIVersion != "v1" || out.Items == nil {
		return nil, errInvalidList
	}
	return out, nil
}
