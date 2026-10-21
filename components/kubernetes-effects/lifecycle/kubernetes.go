package lifecycle

import (
	"context"
	"crypto/tls"
	"errors"
	appsv1 "k8s.io/api/apps/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/runtime/serializer"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/rest"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// KubernetesClient exposes only exact managed Deployment lifecycle operations.
// It never lists, accesses Secrets, uses scale, retries or refreshes a CAS.
type KubernetesClient struct {
	rest       rest.Interface
	cluster    string
	namespaces map[string]bool
}

func NewKubernetesClient(config *rest.Config, cluster string, namespaces []string) (*KubernetesClient, error) {
	if config == nil || cluster == "" || len(namespaces) == 0 || config.TLSClientConfig.Insecure || config.ExecProvider != nil || config.AuthProvider != nil || config.Impersonate.UserName != "" {
		return nil, errors.New("invalid lifecycle client configuration")
	}
	u, e := url.Parse(config.Host)
	if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return nil, errors.New("verified HTTPS required")
	}
	c := rest.CopyConfig(config)
	scheme := runtime.NewScheme()
	appsv1.AddToScheme(scheme)
	metav1.AddMetaToScheme(scheme)
	c.GroupVersion = &appsv1.SchemeGroupVersion
	c.APIPath = "/apis"
	c.NegotiatedSerializer = serializer.NewCodecFactory(scheme).WithoutConversion()
	c.ContentType = "application/json"
	c.AcceptContentTypes = "application/json"
	c.Timeout = 5 * time.Second
	c.RateLimiter = nil
	c.QPS = -1
	c.Burst = 0
	transport, e := rest.TransportFor(c)
	if e != nil {
		return nil, e
	}
	hc := &http.Client{Transport: transport, Timeout: 5 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	if tr, ok := transport.(*http.Transport); ok && tr.TLSClientConfig != nil {
		tr.TLSClientConfig.MinVersion = tls.VersionTLS12
	}
	rc, e := rest.RESTClientForConfigAndClient(c, hc)
	if e != nil {
		return nil, e
	}
	ns := map[string]bool{}
	for _, v := range namespaces {
		ns[v] = true
	}
	return &KubernetesClient{rc, cluster, ns}, nil
}
func (c *KubernetesClient) scope(t Target) error {
	if t.ClusterID != c.cluster || !c.namespaces[t.Namespace] || t.Kind != "Deployment" || t.Name != "utcp-"+t.IncarnationID || strings.ContainsAny(t.Name, "/\\%\r\n") {
		return errors.New("target outside configured scope")
	}
	return nil
}
func decodeDeployment(result rest.Result) (*appsv1.Deployment, error) {
	if e := result.Error(); e != nil {
		return nil, e
	}
	d := &appsv1.Deployment{}
	if e := result.Into(d); e != nil {
		return nil, e
	}
	return d, nil
}
func (c *KubernetesClient) Get(ctx context.Context, t Target) (*appsv1.Deployment, error) {
	if e := c.scope(t); e != nil {
		return nil, e
	}
	return decodeDeployment(c.rest.Get().Namespace(t.Namespace).Resource("deployments").Name(t.Name).MaxRetries(0).Do(ctx))
}
func (c *KubernetesClient) Create(ctx context.Context, t Target, d *appsv1.Deployment) (*appsv1.Deployment, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	if e := c.scope(t); e != nil {
		return nil, e
	}
	if d == nil || d.Name != t.Name || d.Namespace != t.Namespace {
		return nil, errors.New("create target mismatch")
	}
	return decodeDeployment(c.rest.Post().Namespace(t.Namespace).Resource("deployments").Body(d).MaxRetries(0).Do(ctx))
}
func (c *KubernetesClient) Patch(ctx context.Context, t Target, p []byte) (*appsv1.Deployment, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	if e := c.scope(t); e != nil {
		return nil, e
	}
	if len(p) > 65536 {
		return nil, errors.New("patch size")
	}
	return decodeDeployment(c.rest.Patch(types.JSONPatchType).Namespace(t.Namespace).Resource("deployments").Name(t.Name).Body(p).MaxRetries(0).Do(ctx))
}
func (c *KubernetesClient) Delete(ctx context.Context, t Target, o metav1.DeleteOptions) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if e := c.scope(t); e != nil {
		return e
	}
	if t.ExpectedUID == nil || o.Preconditions == nil || o.Preconditions.UID == nil || string(*o.Preconditions.UID) != *t.ExpectedUID || o.Preconditions.ResourceVersion == nil || *o.Preconditions.ResourceVersion == "" || o.GracePeriodSeconds != nil || o.PropagationPolicy != nil || o.OrphanDependents != nil {
		return errors.New("conditional delete required")
	}
	return c.rest.Delete().Namespace(t.Namespace).Resource("deployments").Name(t.Name).Body(&o).MaxRetries(0).Do(ctx).Error()
}
