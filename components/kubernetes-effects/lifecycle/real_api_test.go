//go:build realapi

package lifecycle

// These tests require the owned disposable-cluster harness. Fixture administration
// is separate from the narrowly scoped adapter ServiceAccounts; no ambient
// kubeconfig or cluster identity is accepted.
import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	appsv1 "k8s.io/api/apps/v1"
	authv1 "k8s.io/api/authentication/v1"
	corev1 "k8s.io/api/core/v1"
	rbacv1 "k8s.io/api/rbac/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/apimachinery/pkg/util/wait"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/rest"
	"k8s.io/client-go/tools/clientcmd"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

type lifecycleProof struct {
	t              *testing.T
	ctx            context.Context
	admin          kubernetes.Interface
	cfg            *rest.Config
	run, ns, other string
	namespaces     map[string]types.UID
	tokens         []string
	mu             sync.Mutex
	evidence       []map[string]any
}

func newLifecycleProof(t *testing.T) *lifecycleProof {
	t.Helper()
	run, file, want, uid := os.Getenv("GN_G2_REAL_RUN"), os.Getenv("GN_G2_REAL_KUBECONFIG"), os.Getenv("GN_G2_REAL_CONTEXT"), os.Getenv("GN_G2_REAL_SYSTEM_UID")
	if !regexp.MustCompile(`^gn-g2-proof-[a-f0-9]{12}$`).MatchString(run) || file == "" || want != "k3d-"+run || uid == "" {
		t.Fatal("explicit isolated cluster identity required")
	}
	raw, e := clientcmd.LoadFromFile(file)
	if e != nil || raw.CurrentContext != want || len(raw.Contexts) != 1 || len(raw.Clusters) != 1 {
		t.Fatal("unexpected disposable kubeconfig")
	}
	cfg, e := clientcmd.NewNonInteractiveClientConfig(*raw, want, &clientcmd.ConfigOverrides{}, nil).ClientConfig()
	if e != nil {
		t.Fatal("invalid kubeconfig")
	}
	u, e := url.Parse(cfg.Host)
	if e != nil || u.Scheme != "https" || u.Hostname() != "127.0.0.1" || u.Port() == "" || cfg.Insecure {
		t.Fatal("verified loopback HTTPS required")
	}
	cfg.Timeout = 15 * time.Second
	admin, e := kubernetes.NewForConfig(cfg)
	if e != nil {
		t.Fatal("admin config")
	}
	system, e := admin.CoreV1().Namespaces().Get(context.Background(), "kube-system", metav1.GetOptions{})
	if e != nil || string(system.UID) != uid {
		t.Fatal("cluster UID mismatch")
	}
	p := &lifecycleProof{t: t, ctx: context.Background(), admin: admin, cfg: cfg, run: run, ns: run + "-lifecycle", other: run + "-lifecycle-other", namespaces: map[string]types.UID{}}
	t.Cleanup(p.cleanup)
	for _, ns := range []string{p.ns, p.other} {
		n, e := admin.CoreV1().Namespaces().Create(p.ctx, &corev1.Namespace{ObjectMeta: metav1.ObjectMeta{Name: ns, Labels: map[string]string{"verification.utcp.io/run": run}}}, metav1.CreateOptions{})
		if e != nil {
			t.Fatal(e)
		}
		p.namespaces[ns] = n.UID
	}
	// Kubernetes cannot restrict CREATE by resourceNames. CREATE is confined to
	// this run's namespace. GET/PATCH/DELETE are confined to the named fixtures.
	names := []string{}
	for _, n := range []string{"basic", "projection", "race", "retire", "replace", "delayed", "create-applied", "create-unapplied", "patch-applied", "patch-unapplied", "delete-applied", "delete-unapplied", "claim", "compete", "retire-race"} {
		names = append(names, "utcp-"+proofIncarnation(n))
	}
	_, e = admin.RbacV1().Roles(p.ns).Create(p.ctx, &rbacv1.Role{ObjectMeta: metav1.ObjectMeta{Name: "lifecycle"}, Rules: []rbacv1.PolicyRule{{APIGroups: []string{"apps"}, Resources: []string{"deployments"}, Verbs: []string{"create"}}, {APIGroups: []string{"apps"}, Resources: []string{"deployments"}, ResourceNames: names, Verbs: []string{"get", "patch", "delete"}}}}, metav1.CreateOptions{})
	if e != nil {
		t.Fatal(e)
	}
	subjects := []rbacv1.Subject{}
	for _, name := range []string{"lifecycle-a", "lifecycle-b"} {
		_, e = admin.CoreV1().ServiceAccounts(p.ns).Create(p.ctx, &corev1.ServiceAccount{ObjectMeta: metav1.ObjectMeta{Name: name}}, metav1.CreateOptions{})
		if e != nil {
			t.Fatal(e)
		}
		subjects = append(subjects, rbacv1.Subject{Kind: "ServiceAccount", Name: name, Namespace: p.ns})
		expiry := int64(1800)
		tok, e := admin.CoreV1().ServiceAccounts(p.ns).CreateToken(p.ctx, name, &authv1.TokenRequest{Spec: authv1.TokenRequestSpec{ExpirationSeconds: &expiry}}, metav1.CreateOptions{})
		if e != nil {
			t.Fatal(e)
		}
		p.tokens = append(p.tokens, tok.Status.Token)
	}
	_, e = admin.RbacV1().RoleBindings(p.ns).Create(p.ctx, &rbacv1.RoleBinding{ObjectMeta: metav1.ObjectMeta{Name: "lifecycle"}, RoleRef: rbacv1.RoleRef{APIGroup: rbacv1.GroupName, Kind: "Role", Name: "lifecycle"}, Subjects: subjects}, metav1.CreateOptions{})
	if e != nil {
		t.Fatal(e)
	}
	v, e := admin.Discovery().ServerVersion()
	if e != nil {
		t.Fatal(e)
	}
	p.record("environment", map[string]any{"run": run, "context": want, "endpoint": cfg.Host, "kubernetes": v.GitVersion, "create_scope": "run namespace; RBAC resourceNames cannot restrict CREATE", "bound_verbs": []string{"get", "patch", "delete"}})
	return p
}
func (p *lifecycleProof) config(index int) *rest.Config {
	return &rest.Config{Host: p.cfg.Host, TLSClientConfig: rest.TLSClientConfig{CAData: p.cfg.CAData, CAFile: p.cfg.CAFile}, BearerToken: p.tokens[index], Timeout: 10 * time.Second, QPS: 50, Burst: 100}
}
func (p *lifecycleProof) record(name string, v map[string]any) {
	p.mu.Lock()
	defer p.mu.Unlock()
	v["case"] = name
	p.evidence = append(p.evidence, v)
	p.t.Logf("lifecycle API evidence %s: %v", name, v)
}
func (p *lifecycleProof) status(t *testing.T, name string, e error, want int32) {
	t.Helper()
	var s apierrors.APIStatus
	if !errors.As(e, &s) || s.Status().Code != want {
		t.Fatalf("%s wanted Status %d, got %T %v", name, want, e, e)
	}
	p.record(name, map[string]any{"code": s.Status().Code, "reason": string(s.Status().Reason)})
}
func (p *lifecycleProof) cleanup() {
	for ns, uid := range p.namespaces {
		n, e := p.admin.CoreV1().Namespaces().Get(p.ctx, ns, metav1.GetOptions{})
		if apierrors.IsNotFound(e) {
			continue
		}
		if e != nil || n.UID != uid || n.Labels["verification.utcp.io/run"] != p.run {
			p.t.Errorf("refusing namespace cleanup identity mismatch")
			continue
		}
		// Fixture cleanup may release only the one finalizer introduced by this test.
		ds, e := p.admin.AppsV1().Deployments(ns).List(p.ctx, metav1.ListOptions{})
		if e == nil {
			for _, d := range ds.Items {
				if len(d.Finalizers) == 1 && d.Finalizers[0] == "verification.utcp.io/hold" {
					b, _ := json.Marshal([]map[string]any{{"op": "test", "path": "/metadata/uid", "value": string(d.UID)}, {"op": "test", "path": "/metadata/resourceVersion", "value": d.ResourceVersion}, {"op": "remove", "path": "/metadata/finalizers"}})
					_, e = p.admin.AppsV1().Deployments(ns).Patch(p.ctx, d.Name, types.JSONPatchType, b, metav1.PatchOptions{})
					if e != nil {
						p.t.Error("owned fixture finalizer cleanup failed")
					}
				}
			}
		}
		pods, pe := p.admin.CoreV1().Pods(ns).List(p.ctx, metav1.ListOptions{})
		rss, re := p.admin.AppsV1().ReplicaSets(ns).List(p.ctx, metav1.ListOptions{})
		if pe != nil || re != nil || len(pods.Items) != 0 || len(rss.Items) != 0 {
			p.t.Error("inert fixture produced Pod/ReplicaSet or observation failed")
		} else {
			p.record("inertness_observation", map[string]any{"namespace": ns, "pods": 0, "replica_sets": 0})
		}
		e = p.admin.CoreV1().Namespaces().Delete(p.ctx, ns, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &uid}})
		if e != nil && !apierrors.IsNotFound(e) {
			p.t.Error("owned namespace delete failed")
			continue
		}
		e = wait.PollUntilContextTimeout(p.ctx, 100*time.Millisecond, 30*time.Second, true, func(ctx context.Context) (bool, error) {
			_, e := p.admin.CoreV1().Namespaces().Get(ctx, ns, metav1.GetOptions{})
			return apierrors.IsNotFound(e), nil
		})
		if e != nil {
			p.t.Error("namespace cleanup unconfirmed")
		} else {
			p.record("cleanup", map[string]any{"namespace": ns, "deleted": true})
		}
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	b, e := json.MarshalIndent(p.evidence, "", "  ")
	dir := os.Getenv("GN_G2_REAL_EVIDENCE")
	if dir == "" {
		p.t.Error("evidence directory required")
		return
	}
	if e == nil {
		e = os.WriteFile(filepath.Join(dir, "lifecycle-api-evidence.json"), append(b, '\n'), 0644)
	}
	if e != nil {
		p.t.Error("sanitized evidence write failed")
	}
}
func proofIncarnation(label string) string {
	b := sha256.Sum256([]byte(label))
	return hex.EncodeToString(b[:16])
}
func (p *lifecycleProof) action(label string) Action {
	projection := Projection{TemplateVersion: "inert.pause.v1", Image: PauseImage, ConfigurationID: "config-1", ConfigurationSHA256: strings.Repeat("a", 64)}
	incarnation := proofIncarnation(label)
	a := Action{Version: Version, RequestID: "request-" + label, OperationID: "operation-" + label, TenantID: "tenant-a", RuntimeNodeID: "runtime-a", Target: Target{ClusterID: p.run, Namespace: p.ns, Kind: "Deployment", Name: "utcp-" + incarnation, RuntimeNodeSlug: "runtime-a", TargetID: "target-" + label, IncarnationID: incarnation}, Generation: "1", Action: "create_suspended", Intent: Intent{Kind: "create_suspended", Projection: &projection, ProjectionFingerprint: ProjectionFingerprint(projection)}, IdempotencyKey: "idem-" + label, Claim: Claim{Attempt: 1, Token: strings.Repeat("1", 32), Expires: time.Now().UTC().Add(time.Minute)}, Deadline: time.Now().UTC().Add(time.Minute), CorrelationID: "correlation-" + label, AuditID: "audit-" + label}
	a.Fingerprint = Fingerprint(a)
	return a
}
func (p *lifecycleProof) client(t *testing.T, index int) Client {
	t.Helper()
	c, e := NewKubernetesClient(p.config(index), p.run, []string{p.ns})
	if e != nil {
		t.Fatal(e)
	}
	return c
}

type proofVerifier func(context.Context, Action) error

func (v proofVerifier) Verify(ctx context.Context, a Action) error { return v(ctx, a) }
func (p *lifecycleProof) engine(t *testing.T, c Client, v Verifier) *Engine {
	t.Helper()
	if v == nil {
		v = proofVerifier(func(context.Context, Action) error { return nil })
	}
	e, err := NewEngine(Config{TrustedCluster: p.run, AllowedNamespaces: []string{p.ns}, Client: c, Verifier: v})
	if err != nil {
		t.Fatal(err)
	}
	return e
}
func (p *lifecycleProof) execute(t *testing.T, e *Engine, a Action, want string) Result {
	t.Helper()
	r := e.Execute(p.ctx, a)
	if r.Outcome != want {
		t.Fatalf("%s: expected %s got %+v", a.Action, want, r)
	}
	if err := ValidateResult(r); err != nil {
		t.Fatalf("result schema %v", err)
	}
	return r
}
func (p *lifecycleProof) get(t *testing.T, a Action) *appsv1.Deployment {
	t.Helper()
	d, e := p.admin.AppsV1().Deployments(p.ns).Get(p.ctx, a.Target.Name, metav1.GetOptions{})
	if e != nil {
		t.Fatal(e)
	}
	return d
}
func (p *lifecycleProof) bind(t *testing.T, a Action) Action {
	t.Helper()
	// Fixture acquisition waits for the Deployment controller's status update.
	// This is not an adapter retry: the test authority has not dispatched yet.
	// Actual concurrent-write tests use explicit gates after this preparation.
	var d *appsv1.Deployment
	lastRV, stable := "", 0
	err := wait.PollUntilContextTimeout(p.ctx, 100*time.Millisecond, 10*time.Second, true, func(ctx context.Context) (bool, error) {
		var err error
		d, err = p.admin.AppsV1().Deployments(p.ns).Get(ctx, a.Target.Name, metav1.GetOptions{})
		if err != nil {
			return false, err
		}
		if d.Status.ObservedGeneration < d.Generation {
			stable = 0
			lastRV = ""
			return false, nil
		}
		if d.ResourceVersion == lastRV {
			stable++
		} else {
			lastRV = d.ResourceVersion
			stable = 0
		}
		return stable >= 5, nil
	})
	if err != nil {
		t.Fatalf("fixture controller quiescence: %v", err)
	}
	a.Target.ExpectedUID = String(string(d.UID))
	a.Target.ExpectedRV = String(d.ResourceVersion)
	a.Deadline = time.Now().UTC().Add(time.Minute)
	a.Claim.Expires = a.Deadline
	a.Fingerprint = Fingerprint(a)
	return a
}
func projectionAction(a Action, generation, configuration string, replicas int32) Action {
	a.Action = "converge_projection"
	a.Generation = generation
	q := *a.Intent.Projection
	q.ConfigurationID = configuration
	a.Intent = Intent{Kind: a.Action, Projection: &q, ProjectionFingerprint: ProjectionFingerprint(q), Replicas: Replicas(replicas)}
	a.Fingerprint = Fingerprint(a)
	return a
}
func retirementAction(a Action, generation string) Action {
	a.Action = "retire_delete"
	a.Generation = generation
	a.Intent = Intent{Kind: a.Action, RetirementID: "retirement-a", RetirementGeneration: generation, RetirementFingerprint: strings.Repeat("c", 64)}
	a.Fingerprint = Fingerprint(a)
	return a
}
func TestRealAPILifecycle(t *testing.T) {
	p := newLifecycleProof(t)
	t.Run("create_defaulting_duplicate_ownership", p.creation)
	t.Run("projection_CAS_barriers_preservation", p.projection)
	t.Run("two_adapter_atomic_projection", p.concurrent)
	t.Run("two_adapter_create_races", p.createRaces)
	t.Run("retirement_finalizer_and_exact_delete", p.retirement)
	t.Run("retirement_intervening_RV_change", p.retirementRVChange)
	t.Run("same_name_replacement", p.replacement)
	t.Run("expired_claim_no_dispatch", p.expired)
	t.Run("delayed_create_after_retirement", p.delayed)
	for _, action := range []string{"create", "patch", "delete"} {
		for _, apply := range []bool{true, false} {
			t.Run(fmt.Sprintf("unknown_%s_applied_%v", action, apply), func(t *testing.T) { p.unknown(t, action, apply) })
		}
	}
	t.Run("RBAC_denials", p.rbac)
}
func (p *lifecycleProof) creation(t *testing.T) {
	a := p.action("basic")
	e := p.engine(t, p.client(t, 0), nil)
	r := p.execute(t, e, a, "success")
	d := p.get(t, a)
	if r.Effect != "created_suspended" || r.UID == nil || *r.UID != string(d.UID) || d.Spec.Replicas == nil || *d.Spec.Replicas != 0 || !d.Spec.Paused || !projectionMatches(a, d) {
		t.Fatal("created object not immutable inert projection")
	}
	if d.Spec.Strategy.Type != "RollingUpdate" {
		t.Fatal("expected real Deployment defaulting")
	}
	p.execute(t, e, a, "already_satisfied")
	bad := a
	bad.TenantID = "other-tenant"
	bad.Fingerprint = Fingerprint(bad)
	p.execute(t, e, bad, "ownership_conflict")
	scoped, err := kubernetes.NewForConfig(p.config(0))
	if err != nil {
		t.Fatal(err)
	}
	_, err = scoped.AppsV1().Deployments(p.ns).Create(p.ctx, SuspendedDeployment(a), metav1.CreateOptions{})
	p.status(t, "duplicate_create", err, 409)
	p.record("create_suspended", map[string]any{"uid": string(d.UID), "resource_version": d.ResourceVersion, "paused": d.Spec.Paused, "replicas": *d.Spec.Replicas, "default_strategy": d.Spec.Strategy.Type, "duplicate": "already_satisfied", "ownership_conflict": "rejected"})
}
func (p *lifecycleProof) projection(t *testing.T) {
	a := p.action("projection")
	e := p.engine(t, p.client(t, 0), nil)
	p.execute(t, e, a, "success")
	a = p.bind(t, a)
	// Fixture administrator owns an unrelated annotation and deployment field.
	d := p.get(t, a)
	d.Annotations["verification.utcp.io/unrelated"] = "preserved"
	n := int32(7)
	d.Spec.RevisionHistoryLimit = &n
	_, err := p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	a = p.bind(t, a)
	a = projectionAction(a, "10", "config-10", 2)
	p.execute(t, e, a, "success")
	p.execute(t, e, a, "already_satisfied")
	d = p.get(t, a)
	if d.Annotations[AppliedGeneration] != "10" || d.Annotations[AppliedIntent] != a.Fingerprint || *d.Spec.Replicas != 2 || d.Annotations[ProjectionMarker] != a.Intent.ProjectionFingerprint || d.Annotations["verification.utcp.io/unrelated"] != "preserved" || *d.Spec.RevisionHistoryLimit != 7 {
		t.Fatal("non-atomic projection or unrelated field loss")
	}
	bad := a
	bad = projectionAction(bad, "10", "conflicting", 0)
	p.execute(t, e, bad, "ownership_conflict")
	stale := p.bind(t, a)
	stale = projectionAction(stale, "11", "config-11", 0)
	stale.Target.ExpectedRV = String("0")
	p.execute(t, e, stale, "resource_version_conflict")
	wrong := p.bind(t, a)
	wrong = projectionAction(wrong, "11", "config-11", 0)
	wrong.Target.ExpectedUID = String("wrong-uid")
	wrong.Fingerprint = Fingerprint(wrong)
	p.execute(t, e, wrong, "identity_mismatch")
	newer := p.bind(t, a)
	newer = projectionAction(newer, "11", "config-11", 0)
	p.execute(t, e, newer, "success")
	old := p.bind(t, a)
	p.execute(t, e, old, "stale_generation")
	// Exercise the actual server predicates independently of the engine pre-read.
	c := p.client(t, 0)
	d = p.get(t, newer)
	for _, predicate := range []struct {
		name, path string
		value      any
	}{
		{"UID", "/metadata/uid", "wrong-uid"},
		{"resourceVersion", "/metadata/resourceVersion", "0"},
		{"generation", path(AdmittedGeneration), "10"},
		{"fingerprint", path(AdmittedIntent), strings.Repeat("e", 64)},
	} {
		patch, _ := json.Marshal([]map[string]any{{"op": "test", "path": predicate.path, "value": predicate.value}, {"op": "add", "path": "/spec/replicas", "value": 1}})
		_, err := c.Patch(p.ctx, newer.Target, patch)
		p.status(t, "projection_predicate_"+predicate.name, err, 422)
		current := p.get(t, newer)
		if *current.Spec.Replicas != 0 || current.Annotations[AdmittedGeneration] != "11" || current.Annotations[AppliedGeneration] != "11" {
			t.Fatal("failed predicate partially applied effect")
		}
	}
	p.record("projection", map[string]any{"admitted": "11", "applied": "11", "atomic_marker_projection": true, "preserved_unrelated": true, "stale_RV": "resource_version_conflict", "wrong_UID": "identity_mismatch", "older": "stale_generation"})
}

// The gate releases both prepared mutations together, after the exact same RV
// was observed. Actual API-server JSON Patch enforcement decides the winner.
type proofGateClient struct {
	Client
	ready   chan<- struct{}
	release <-chan struct{}
}

func (c *proofGateClient) Patch(ctx context.Context, target Target, b []byte) (*appsv1.Deployment, error) {
	c.ready <- struct{}{}
	select {
	case <-c.release:
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	return c.Client.Patch(ctx, target, b)
}
func (p *lifecycleProof) concurrent(t *testing.T) {
	a := p.action("race")
	p.execute(t, p.engine(t, p.client(t, 0), nil), a, "success")
	a = p.bind(t, a)
	ready := make(chan struct{}, 2)
	release := make(chan struct{})
	results := make(chan Result, 2)
	for i := 0; i < 2; i++ {
		b := projectionAction(a, "10", fmt.Sprintf("config-%d", i), int32(i+1))
		c := &proofGateClient{Client: p.client(t, i), ready: ready, release: release}
		e := p.engine(t, c, nil)
		go func() { results <- e.Execute(p.ctx, b) }()
	}
	for i := 0; i < 2; i++ {
		select {
		case <-ready:
		case <-time.After(10 * time.Second):
			t.Fatal("concurrency preparation blocked")
		}
	}
	close(release)
	success, rejected := 0, 0
	outcomes := []string{}
	for i := 0; i < 2; i++ {
		r := <-results
		outcomes = append(outcomes, r.Outcome)
		if r.Outcome == "success" {
			success++
		} else if r.Outcome == "invalid_request" || r.Outcome == "resource_version_conflict" {
			rejected++
		} else {
			t.Fatalf("unexpected race result %+v", r)
		}
	}
	if success != 1 || rejected != 1 {
		t.Fatalf("race success %d rejected %d", success, rejected)
	}
	d := p.get(t, a)
	if d.Annotations[AppliedGeneration] != "10" || d.Annotations[AppliedIntent] != d.Annotations[AdmittedIntent] || d.Annotations[ProjectionMarker] == "" || (*d.Spec.Replicas != 1 && *d.Spec.Replicas != 2) {
		t.Fatal("partial marker/effect race")
	}
	winning := projectionAction(a, "10", fmt.Sprintf("config-%d", *d.Spec.Replicas-1), *d.Spec.Replicas)
	if d.Annotations[AppliedIntent] != winning.Fingerprint || d.Annotations[ProjectionMarker] != winning.Intent.ProjectionFingerprint {
		t.Fatal("winning configuration and replicas/markers were not atomic")
	}
	p.record("two_adapter_CAS", map[string]any{"successes": success, "rejections": rejected, "outcomes": outcomes, "RV": d.ResourceVersion, "generation": d.Annotations[AppliedGeneration], "replicas": *d.Spec.Replicas})
}
func (p *lifecycleProof) retirement(t *testing.T) {
	a := p.action("retire")
	e := p.engine(t, p.client(t, 0), nil)
	p.execute(t, e, a, "success")
	a = p.bind(t, a)
	d := p.get(t, a)
	d.Finalizers = []string{"verification.utcp.io/hold"}
	_, err := p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	a = retirementAction(p.bind(t, a), "20")
	a = p.prepareRetirement(t, a)
	r := p.execute(t, e, a, "success")
	if r.Effect != "deletion_accepted" {
		t.Fatal("HTTP success equated to removal")
	}
	d = p.get(t, a)
	if d.DeletionTimestamp == nil || len(d.Finalizers) != 1 {
		t.Fatal("server finalizer did not block deletion")
	}
	r = e.Recover(p.ctx, a)
	if r.Outcome != "already_satisfied" || r.Effect != "terminating" || r.DeletionTimestamp == nil {
		t.Fatalf("terminating recovery %+v", r)
	}
	scoped, err := kubernetes.NewForConfig(p.config(0))
	if err != nil {
		t.Fatal(err)
	}
	wrong := types.UID("wrong-uid")
	err = scoped.AppsV1().Deployments(p.ns).Delete(p.ctx, a.Target.Name, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &wrong}})
	p.status(t, "delete_wrong_UID", err, 409)
	uid := d.UID
	rv := "0"
	err = scoped.AppsV1().Deployments(p.ns).Delete(p.ctx, a.Target.Name, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &uid, ResourceVersion: &rv}})
	p.status(t, "delete_wrong_RV", err, 409)
	d = p.get(t, a)
	b, _ := json.Marshal([]map[string]any{{"op": "test", "path": "/metadata/uid", "value": string(d.UID)}, {"op": "test", "path": "/metadata/resourceVersion", "value": d.ResourceVersion}, {"op": "remove", "path": "/metadata/finalizers"}})
	_, err = p.admin.AppsV1().Deployments(p.ns).Patch(p.ctx, a.Target.Name, types.JSONPatchType, b, metav1.PatchOptions{})
	if err != nil {
		t.Fatal(err)
	}
	p.absent(t, a)
	r = e.Recover(p.ctx, a)
	if r.Outcome != "already_satisfied" || r.Effect != "observed_absent" {
		t.Fatalf("absent recovery %+v", r)
	}
	p.record("retire_delete", map[string]any{"request": "deletion_accepted", "held": "terminating", "finalizer_release": "fixture administrator only", "completion": "observed_absent", "UID_RV_delete_preconditions": "409"})
}
func (p *lifecycleProof) absent(t *testing.T, a Action) {
	t.Helper()
	err := wait.PollUntilContextTimeout(p.ctx, 20*time.Millisecond, 10*time.Second, true, func(ctx context.Context) (bool, error) {
		_, e := p.admin.AppsV1().Deployments(p.ns).Get(ctx, a.Target.Name, metav1.GetOptions{})
		return apierrors.IsNotFound(e), nil
	})
	if err != nil {
		t.Fatal("resource disappearance not verified")
	}
}
func (p *lifecycleProof) replacement(t *testing.T) {
	a := p.action("replace")
	e := p.engine(t, p.client(t, 0), nil)
	p.execute(t, e, a, "success")
	old := p.bind(t, a)
	uid := types.UID(*old.Target.ExpectedUID)
	err := p.admin.AppsV1().Deployments(p.ns).Delete(p.ctx, a.Target.Name, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &uid}})
	if err != nil {
		t.Fatal(err)
	}
	p.absent(t, a)
	replacement, err := p.admin.AppsV1().Deployments(p.ns).Create(p.ctx, SuspendedDeployment(a), metav1.CreateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if replacement.UID == uid {
		t.Fatal("name reuse retained UID")
	}
	stale := projectionAction(old, "10", "new-projection", 0)
	p.execute(t, e, stale, "identity_mismatch")
	stale = retirementAction(old, "20")
	p.execute(t, e, stale, "identity_mismatch")
	r := e.Recover(p.ctx, stale)
	if r.Outcome != "identity_mismatch" {
		t.Fatal("replacement recovered as old target")
	}
	current := p.get(t, a)
	if current.UID != replacement.UID {
		t.Fatal("replacement modified")
	}
	p.record("same_name_replacement", map[string]any{"UID_changed": true, "old_projection": "identity_mismatch", "old_delete": "identity_mismatch", "old_recovery": "identity_mismatch"})
}
func (p *lifecycleProof) expired(t *testing.T) {
	a := p.action("claim")
	a.Claim.Expires = time.Now().UTC().Add(-time.Second)
	e := p.engine(t, p.client(t, 0), nil)
	p.execute(t, e, a, "stale_generation")
	_, err := p.admin.AppsV1().Deployments(p.ns).Get(p.ctx, a.Target.Name, metav1.GetOptions{})
	if !apierrors.IsNotFound(err) {
		t.Fatal("expired claim dispatched")
	}
	a.Claim.Expires = time.Now().UTC().Add(time.Minute)
	e = p.engine(t, p.client(t, 0), proofVerifier(func(context.Context, Action) error { return &DecisionError{Outcome: "stale_generation"} }))
	p.execute(t, e, a, "stale_generation")
	_, err = p.admin.AppsV1().Deployments(p.ns).Get(p.ctx, a.Target.Name, metav1.GetOptions{})
	if !apierrors.IsNotFound(err) {
		t.Fatal("denied claim dispatched")
	}
	p.record("claim_rejection", map[string]any{"expired_before_dispatch": true, "superseded_before_dispatch": true, "created": false})
}

type proofDelayClient struct {
	Client
	arrived chan<- struct{}
	release <-chan struct{}
}

func (c *proofDelayClient) Create(ctx context.Context, target Target, d *appsv1.Deployment) (*appsv1.Deployment, error) {
	c.arrived <- struct{}{}
	select {
	case <-c.release:
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	return c.Client.Create(ctx, target, d)
}
func (p *lifecycleProof) delayed(t *testing.T) {
	a := p.action("delayed")
	arrived := make(chan struct{}, 1)
	release := make(chan struct{})
	var retired atomic.Bool
	c := &proofDelayClient{Client: p.client(t, 0), arrived: arrived, release: release}
	v := proofVerifier(func(context.Context, Action) error {
		if retired.Load() {
			return &DecisionError{Outcome: "stale_generation"}
		}
		return nil
	})
	e := p.engine(t, c, v)
	done := make(chan Result, 1)
	go func() { done <- e.Execute(p.ctx, a) }()
	select {
	case <-arrived:
	case <-time.After(10 * time.Second):
		t.Fatal("create dispatch not reached")
	}
	retired.Store(true)
	close(release)
	r := <-done
	if r.Outcome != "success" {
		t.Fatalf("already dispatched create %+v", r)
	}
	d := p.get(t, a)
	if !projectionMatches(a, d) || *d.Spec.Replicas != 0 || !d.Spec.Paused {
		t.Fatal("delayed create not inert")
	}
	if e.Recover(p.ctx, a).Outcome != "stale_generation" {
		t.Fatal("retired create rebound/recovered under stale claim")
	}
	// Only a newly authorized retirement effect, supplied by canonical authority,
	// may clean the inert residue. The adapter does not autonomously clean up.
	cleanup := retirementAction(p.bind(t, a), "20")
	cleanup = p.prepareRetirement(t, cleanup)
	p.execute(t, p.engine(t, p.client(t, 0), nil), cleanup, "success")
	p.absent(t, a)
	p.record("delayed_create_after_modeled_retirement", map[string]any{"already_dispatched_can_apply": true, "suspended": true, "activation": false, "stale_recovery": "rejected", "cleanup": "fresh authorized exact UID retirement"})
}
func (p *lifecycleProof) faultClient(t *testing.T, method string, apply bool) (Client, *atomic.Int32, *atomic.Int32) {
	t.Helper()
	upstream, err := url.Parse(p.cfg.Host)
	if err != nil {
		t.Fatal(err)
	}
	tr, err := rest.TransportFor(p.config(0))
	if err != nil {
		t.Fatal(err)
	}
	var dropped atomic.Bool
	received, forwarded := new(atomic.Int32), new(atomic.Int32)
	proxy := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fault := r.Method == method && dropped.CompareAndSwap(false, true)
		if r.Method == method {
			received.Add(1)
		}
		drop := func() {
			conn, _, e := w.(http.Hijacker).Hijack()
			if e == nil {
				_ = conn.Close()
			}
		}
		if fault && !apply {
			_, _ = io.Copy(io.Discard, r.Body)
			drop()
			return
		}
		outgoing := r.Clone(r.Context())
		outgoing.URL.Scheme = upstream.Scheme
		outgoing.URL.Host = upstream.Host
		outgoing.Host = upstream.Host
		outgoing.RequestURI = ""
		response, e := tr.RoundTrip(outgoing)
		if e != nil {
			http.Error(w, "upstream unavailable", 502)
			return
		}
		defer response.Body.Close()
		body, e := io.ReadAll(io.LimitReader(response.Body, 16<<20))
		if e != nil {
			http.Error(w, "upstream incomplete", 502)
			return
		}
		if r.Method == method {
			forwarded.Add(1)
		}
		if fault {
			if response.StatusCode < 200 || response.StatusCode >= 300 {
				t.Errorf("fault upstream rejected %s: %d", method, response.StatusCode)
			}
			drop()
			return
		}
		for k, vs := range response.Header {
			if !strings.EqualFold(k, "Content-Length") {
				for _, v := range vs {
					w.Header().Add(k, v)
				}
			}
		}
		w.WriteHeader(response.StatusCode)
		_, _ = w.Write(body)
	}))
	proxy.TLS = &tls.Config{MinVersion: tls.VersionTLS13}
	proxy.StartTLS()
	t.Cleanup(proxy.Close)
	cfg := p.config(0)
	cfg.Host = proxy.URL
	cfg.CAData = nil
	cfg.CAFile = ""
	cfg.TLSClientConfig = rest.TLSClientConfig{CAData: pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: proxy.Certificate().Raw})}
	c, e := NewKubernetesClient(cfg, p.run, []string{p.ns})
	if e != nil {
		t.Fatal(e)
	}
	return c, received, forwarded
}
func (p *lifecycleProof) unknown(t *testing.T, kind string, apply bool) {
	suffix := "unapplied"
	if apply {
		suffix = "applied"
	}
	label := kind + "-" + suffix
	a := p.action(label)
	normal := p.engine(t, p.client(t, 0), nil)
	method := http.MethodPost
	if kind != "create" {
		p.execute(t, normal, a, "success")
		a = p.bind(t, a)
		if kind == "patch" {
			a = projectionAction(a, "10", "uncertain-config", 0)
			method = http.MethodPatch
		} else {
			a = retirementAction(a, "20")
			a = p.prepareRetirement(t, a)
			method = http.MethodDelete
		}
	}
	c, received, forwarded := p.faultClient(t, method, apply)
	e := p.engine(t, c, nil)
	r := p.execute(t, e, a, "outcome_unknown")
	if r.Effect != "unknown" || r.Dispatch != "unknown" || r.Retry != "observe_first" {
		t.Fatal("uncertainty not preserved")
	}
	if received.Load() != 1 {
		t.Fatalf("mutation replay attempts %d", received.Load())
	}
	wantForwarded := int32(0)
	if apply {
		wantForwarded = 1
	}
	if forwarded.Load() != wantForwarded {
		t.Fatal("fault forwarding mismatch")
	}
	recovered := e.Recover(p.ctx, a)
	wantOutcome, wantEffect := "drift", "none"
	if kind == "create" {
		if apply {
			wantOutcome, wantEffect = "already_satisfied", "observed_suspended"
		} else {
			wantOutcome, wantEffect = "outcome_unknown", "unknown"
		}
	}
	if kind == "patch" && apply {
		wantOutcome, wantEffect = "already_satisfied", "projection_applied"
	}
	if kind == "delete" && apply {
		p.absent(t, a)
		recovered = e.Recover(p.ctx, a)
		wantOutcome, wantEffect = "already_satisfied", "observed_absent"
	}
	if recovered.Outcome != wantOutcome || recovered.Effect != wantEffect {
		t.Fatalf("%s recovery wanted %s/%s got %+v", kind, wantOutcome, wantEffect, recovered)
	}
	if received.Load() != 1 {
		t.Fatal("recovery blindly dispatched mutation")
	}
	denied := p.engine(t, c, proofVerifier(func(context.Context, Action) error { return &DecisionError{Outcome: "stale_generation"} }))
	if denied.Recover(p.ctx, a).Outcome != "stale_generation" {
		t.Fatal("superseded uncertain operation recovered")
	}
	p.record("unknown_"+kind, map[string]any{"applied": apply, "attempts": received.Load(), "forwarded": forwarded.Load(), "outcome": r.Outcome, "recovery": recovered.Outcome, "effect": recovered.Effect, "recovery_writes": 0})
}
func (p *lifecycleProof) rbac(t *testing.T) {
	c, e := kubernetes.NewForConfig(p.config(0))
	if e != nil {
		t.Fatal(e)
	}
	checks := []struct {
		name string
		call func() error
	}{
		{"Secrets_GET", func() error { _, e := c.CoreV1().Secrets(p.ns).Get(p.ctx, "forbidden", metav1.GetOptions{}); return e }},
		{"Nodes_PATCH", func() error {
			_, e := c.CoreV1().Nodes().Patch(p.ctx, "forbidden", types.MergePatchType, []byte(`{}`), metav1.PatchOptions{})
			return e
		}},
		{"cross_namespace_CREATE", func() error {
			a := p.action("basic")
			a.Target.Namespace = p.other
			_, e := c.AppsV1().Deployments(p.other).Create(p.ctx, SuspendedDeployment(a), metav1.CreateOptions{})
			return e
		}},
		{"cross_namespace_GET", func() error {
			_, e := c.AppsV1().Deployments(p.other).Get(p.ctx, "forbidden", metav1.GetOptions{})
			return e
		}},
		{"cross_namespace_DELETE", func() error {
			return c.AppsV1().Deployments(p.other).Delete(p.ctx, "forbidden", metav1.DeleteOptions{})
		}},
		{"unrelated_name_PATCH", func() error {
			_, e := c.AppsV1().Deployments(p.ns).Patch(p.ctx, "unrelated", types.JSONPatchType, []byte(`[]`), metav1.PatchOptions{})
			return e
		}},
		{"scale_PATCH", func() error {
			_, e := c.AppsV1().Deployments(p.ns).Patch(p.ctx, "utcp-"+proofIncarnation("basic"), types.MergePatchType, []byte(`{"spec":{"replicas":1}}`), metav1.PatchOptions{}, "scale")
			return e
		}},
		{"Services_CREATE", func() error {
			_, e := c.CoreV1().Services(p.ns).Create(p.ctx, &corev1.Service{ObjectMeta: metav1.ObjectMeta{Name: "forbidden"}}, metav1.CreateOptions{})
			return e
		}},
		{"Deployment_LIST", func() error { _, e := c.AppsV1().Deployments(p.ns).List(p.ctx, metav1.ListOptions{}); return e }},
	}
	for _, check := range checks {
		t.Run(check.name, func(t *testing.T) { p.status(t, "RBAC_"+check.name, check.call(), 403) })
	}
	p.record("RBAC_boundary", map[string]any{"denials": len(checks), "adapter_credential_not_cluster_admin": true, "create_resource_names_limitation": "namespace-only create RBAC; application envelope remains required"})
}
func (p *lifecycleProof) createRaces(t *testing.T) {
	for _, conflicting := range []bool{false, true} {
		t.Run(fmt.Sprintf("conflicting_%v", conflicting), func(t *testing.T) {
			a := p.action("compete")
			ready := make(chan struct{}, 2)
			release := make(chan struct{})
			type attempt struct {
				a Action
				e *Engine
				r Result
			}
			results := make(chan attempt, 2)
			for i := 0; i < 2; i++ {
				b := a
				if conflicting && i == 1 {
					q := *a.Intent.Projection
					q.ConfigurationID = "competing-config"
					b.Intent.Projection = &q
					b.Intent.ProjectionFingerprint = ProjectionFingerprint(q)
					b.Fingerprint = Fingerprint(b)
				}
				c := &proofDelayClient{Client: p.client(t, i), arrived: ready, release: release}
				e := p.engine(t, c, nil)
				go func() { results <- attempt{b, e, e.Execute(p.ctx, b)} }()
			}
			for i := 0; i < 2; i++ {
				select {
				case <-ready:
				case <-time.After(10 * time.Second):
					t.Fatal("both creates not prepared")
				}
			}
			close(release)
			successes, conflicts := 0, 0
			var loser attempt
			for i := 0; i < 2; i++ {
				r := <-results
				if r.r.Outcome == "success" {
					successes++
				} else if r.r.Outcome == "resource_version_conflict" {
					conflicts++
					loser = r
				} else {
					t.Fatalf("create race unexpected %+v", r.r)
				}
			}
			if successes != 1 || conflicts != 1 {
				t.Fatal("name uniqueness failed")
			}
			recovery := loser.e.Recover(p.ctx, loser.a)
			want := "already_satisfied"
			if conflicting {
				want = "ownership_conflict"
			}
			if recovery.Outcome != want {
				t.Fatalf("create race recovery expected %s got %+v", want, recovery)
			}
			d := p.get(t, a)
			if d.Spec.Replicas == nil || *d.Spec.Replicas != 0 || !d.Spec.Paused {
				t.Fatal("create race activated")
			}
			p.record("create_race", map[string]any{"competing_intent": conflicting, "successes": successes, "server_name_conflicts": conflicts, "loser_observe_first": recovery.Outcome, "UID": string(d.UID)})
			uid := d.UID
			if err := p.admin.AppsV1().Deployments(p.ns).Delete(p.ctx, a.Target.Name, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &uid}}); err != nil {
				t.Fatal(err)
			}
			p.absent(t, a)
		})
	}
}

type proofBeforeDelete struct {
	Client
	before func()
}

func (c *proofBeforeDelete) Delete(ctx context.Context, target Target, o metav1.DeleteOptions) error {
	c.before()
	return c.Client.Delete(ctx, target, o)
}
func (p *lifecycleProof) retirementRVChange(t *testing.T) {
	a := p.action("retire-race")
	p.execute(t, p.engine(t, p.client(t, 0), nil), a, "success")
	a = retirementAction(p.bind(t, a), "20")
	c := &proofBeforeDelete{Client: p.client(t, 0), before: func() {
		d := p.get(t, a)
		b, _ := json.Marshal([]map[string]any{{"op": "test", "path": "/metadata/uid", "value": string(d.UID)}, {"op": "test", "path": "/metadata/resourceVersion", "value": d.ResourceVersion}, {"op": "add", "path": "/metadata/labels/verification.utcp.io~1intervening", "value": "true"}})
		if _, err := p.admin.AppsV1().Deployments(p.ns).Patch(p.ctx, a.Target.Name, types.JSONPatchType, b, metav1.PatchOptions{}); err != nil {
			t.Fatal(err)
		}
	}}
	p.execute(t, p.engine(t, c, nil), a, "resource_version_conflict")
	d := p.get(t, a)
	if d.DeletionTimestamp != nil || d.Annotations[RetirementMarker] != a.Intent.RetirementID {
		t.Fatal("DELETE crossed intervening RV")
	}
	p.record("retirement_intervening_RV", map[string]any{"outcome": "resource_version_conflict", "delete_applied": false, "barrier_preserved": true, "retry": "canonical reauthorization required; adapter does not refresh"})
}

// prepareRetirement installs the barrier as explicit fixture administration and
// waits for controller status. This isolates successful DELETE/finalizer/lost-
// response tests from legitimate status CAS conflicts. The separate intervening
// RV test exercises adapter barrier installation and rejects a competing change.
func (p *lifecycleProof) prepareRetirement(t *testing.T, a Action) Action {
	t.Helper()
	d := p.get(t, a)
	patch, err := makePatch(a, d)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = p.admin.AppsV1().Deployments(p.ns).Patch(p.ctx, a.Target.Name, types.JSONPatchType, patch, metav1.PatchOptions{}); err != nil {
		t.Fatal(err)
	}
	return p.bind(t, a)
}
