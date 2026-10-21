//go:build realapi

package effects

// This opt-in suite uses only an explicitly supplied disposable cluster.
// Fixture administration and bounded adapter credentials are separate identities.
import (
	"context"
	"crypto/tls"
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

type realProof struct {
	t                  *testing.T
	ctx                context.Context
	admin              kubernetes.Interface
	scoped             kubernetes.Interface
	cfg                *rest.Config
	run, ns, other     string
	nsUID, otherUID    types.UID
	token, secondToken string
	ca                 []byte
	client             *KubernetesClient
	validator          *Validator
	mu                 sync.Mutex
	evidence           []map[string]any
}

func TestRealAPIConditionalEffects(t *testing.T) {
	p := newRealProof(t)
	defer p.writeEvidence()
	defer p.cleanup()
	t.Run("initial_missing_annotations_and_idempotency", p.initialAdmission)
	t.Run("marker_conflicts_and_identity", p.markerIdentity)
	t.Run("raw_api_status_and_atomic_rejection", p.rawStatus)
	t.Run("atomic_replicas_and_preservation", p.atomicReplicas)
	t.Run("two_adapter_CAS", p.concurrentAdapters)
	t.Run("atomic_replica_CAS", p.concurrentReplicas)
	t.Run("older_after_newer_barrier", p.olderAfterBarrier)
	t.Run("older_before_newer_barrier", p.olderBeforeBarrier)
	t.Run("claim_expiry_unchanged_resource_version", p.expiredClaim)
	t.Run("name_reuse_and_recovery_identity", p.nameReuse)
	t.Run("unknown_applied_response_lost", func(t *testing.T) { p.unknown(t, true) })
	t.Run("unknown_not_applied_response_lost", func(t *testing.T) { p.unknown(t, false) })
	t.Run("RBAC_denials", p.rbac)
	t.Run("scale_external_writer_boundary", p.scaleBoundary)
	t.Run("second_authenticated_adapter_identity", p.secondIdentity)
	t.Run("claim_unavailable_and_denied_no_dispatch", p.claimFailure)
}
func newRealProof(t *testing.T) *realProof {
	t.Helper()
	run, file, wantContext, systemUID := os.Getenv("GN_G2_REAL_RUN"), os.Getenv("GN_G2_REAL_KUBECONFIG"), os.Getenv("GN_G2_REAL_CONTEXT"), os.Getenv("GN_G2_REAL_SYSTEM_UID")
	if !regexp.MustCompile(`^gn-g2-proof-[a-f0-9]{12}$`).MatchString(run) || file == "" || wantContext != "k3d-"+run || systemUID == "" {
		t.Fatal("explicit disposable cluster identity is required")
	}
	raw, err := clientcmd.LoadFromFile(file)
	if err != nil {
		t.Fatal("cannot load explicit disposable kubeconfig")
	}
	if raw.CurrentContext != wantContext || len(raw.Contexts) != 1 || len(raw.Clusters) != 1 {
		t.Fatal("unexpected kubeconfig context inventory")
	}
	cfg, err := clientcmd.NewNonInteractiveClientConfig(*raw, wantContext, &clientcmd.ConfigOverrides{}, nil).ClientConfig()
	if err != nil {
		t.Fatal("invalid explicit disposable kubeconfig")
	}
	u, err := url.Parse(cfg.Host)
	if err != nil || u.Scheme != "https" || u.Hostname() != "127.0.0.1" || u.Port() == "" || cfg.Insecure {
		t.Fatal("API must be verified HTTPS on explicit loopback port")
	}
	cfg.Timeout = 15 * time.Second
	admin, err := kubernetes.NewForConfig(cfg)
	if err != nil {
		t.Fatal("admin client configuration")
	}
	ctx := context.Background()
	system, err := admin.CoreV1().Namespaces().Get(ctx, "kube-system", metav1.GetOptions{})
	if err != nil || string(system.UID) != systemUID {
		t.Fatal("disposable kube-system UID mismatch")
	}
	version, err := admin.Discovery().ServerVersion()
	if err != nil {
		t.Fatal(err)
	}
	ca := cfg.CAData
	if len(ca) == 0 && cfg.CAFile != "" {
		ca, err = os.ReadFile(cfg.CAFile)
		if err != nil {
			t.Fatal(err)
		}
	}
	v, err := NewValidator(contracts)
	if err != nil {
		t.Fatal(err)
	}
	p := &realProof{t: t, ctx: ctx, admin: admin, cfg: cfg, run: run, ns: run + "-effects", other: run + "-other", ca: ca, validator: v}
	for _, ns := range []string{p.ns, p.other} {
		created, e := admin.CoreV1().Namespaces().Create(ctx, &corev1.Namespace{ObjectMeta: metav1.ObjectMeta{Name: ns, Labels: map[string]string{"verification.utcp.io/run": run}}}, metav1.CreateOptions{})
		if e != nil {
			p.cleanup()
			t.Fatalf("namespace create refused: %v", e)
		}
		if ns == p.ns {
			p.nsUID = created.UID
		} else {
			p.otherUID = created.UID
		}
	}
	for _, account := range []string{"adapter-a", "adapter-b"} {
		_, err = admin.CoreV1().ServiceAccounts(p.ns).Create(ctx, &corev1.ServiceAccount{ObjectMeta: metav1.ObjectMeta{Name: account}}, metav1.CreateOptions{})
		if err != nil {
			p.cleanup()
			t.Fatal(err)
		}
	}
	_, err = admin.RbacV1().Roles(p.ns).Create(ctx, &rbacv1.Role{ObjectMeta: metav1.ObjectMeta{Name: "bounded-effects"}, Rules: []rbacv1.PolicyRule{{APIGroups: []string{"apps"}, Resources: []string{"deployments"}, ResourceNames: []string{"managed"}, Verbs: []string{"get", "patch"}}}}, metav1.CreateOptions{})
	if err != nil {
		p.cleanup()
		t.Fatal(err)
	}
	_, err = admin.RbacV1().RoleBindings(p.ns).Create(ctx, &rbacv1.RoleBinding{ObjectMeta: metav1.ObjectMeta{Name: "bounded-effects"}, RoleRef: rbacv1.RoleRef{APIGroup: "rbac.authorization.k8s.io", Kind: "Role", Name: "bounded-effects"}, Subjects: []rbacv1.Subject{{Kind: "ServiceAccount", Name: "adapter-a", Namespace: p.ns}, {Kind: "ServiceAccount", Name: "adapter-b", Namespace: p.ns}}}, metav1.CreateOptions{})
	if err != nil {
		p.cleanup()
		t.Fatal(err)
	}
	expiration := int64(1200)
	for _, account := range []string{"adapter-a", "adapter-b"} {
		token, e := admin.CoreV1().ServiceAccounts(p.ns).CreateToken(ctx, account, &authv1.TokenRequest{Spec: authv1.TokenRequestSpec{ExpirationSeconds: &expiration}}, metav1.CreateOptions{})
		if e != nil {
			p.cleanup()
			t.Fatal(e)
		}
		if account == "adapter-a" {
			p.token = token.Status.Token
		} else {
			p.secondToken = token.Status.Token
		}
	}
	p.client, err = NewKubernetesClient(KubernetesConfig{URL: cfg.Host, CA: ca, Token: p.token, AllowedNamespaces: []string{p.ns}})
	if err != nil {
		p.cleanup()
		t.Fatal(err)
	}
	p.scoped, err = kubernetes.NewForConfig(p.scopedConfig(p.token))
	if err != nil {
		p.cleanup()
		t.Fatal(err)
	}
	p.record("environment", map[string]any{"run": run, "context": wantContext, "api_endpoint": cfg.Host, "kubernetes": version.GitVersion, "kube_system_uid": systemUID, "adapter_verbs": []string{"get", "patch"}, "adapter_resource_names": []string{"managed"}})
	return p
}
func (p *realProof) scopedConfig(token string) *rest.Config {
	return &rest.Config{Host: p.cfg.Host, TLSClientConfig: rest.TLSClientConfig{CAData: p.ca}, BearerToken: token, Timeout: 10 * time.Second, QPS: 20, Burst: 30}
}
func (p *realProof) record(name string, fields map[string]any) {
	p.mu.Lock()
	defer p.mu.Unlock()
	fields["case"] = name
	p.evidence = append(p.evidence, fields)
	p.t.Logf("real API evidence %s: %v", name, fields)
}
func (p *realProof) status(t *testing.T, name string, err error) int32 {
	t.Helper()
	if err == nil {
		t.Fatalf("%s unexpectedly accepted", name)
	}
	var s apierrors.APIStatus
	if !errors.As(err, &s) {
		t.Fatalf("%s did not return Kubernetes Status: %T", name, err)
	}
	status := s.Status()
	fields := map[string]any{"code": status.Code, "reason": string(status.Reason)}
	var causes []map[string]string
	if status.Details != nil {
		for _, c := range status.Details.Causes {
			causes = append(causes, map[string]string{"type": string(c.Type), "field": c.Field})
		}
	}
	fields["causes"] = causes
	p.record(name, fields)
	return status.Code
}
func (p *realProof) cleanup() {
	for _, entry := range []struct {
		name string
		uid  types.UID
	}{{p.ns, p.nsUID}, {p.other, p.otherUID}} {
		if entry.uid == "" {
			continue
		}
		ns, e := p.admin.CoreV1().Namespaces().Get(p.ctx, entry.name, metav1.GetOptions{})
		if apierrors.IsNotFound(e) {
			continue
		}
		if e != nil || ns.UID != entry.uid || ns.Labels["verification.utcp.io/run"] != p.run {
			p.t.Errorf("cleanup refuses unexpected namespace %s", entry.name)
			continue
		}
		pods, podErr := p.admin.CoreV1().Pods(entry.name).List(p.ctx, metav1.ListOptions{})
		replicas, replicaErr := p.admin.AppsV1().ReplicaSets(entry.name).List(p.ctx, metav1.ListOptions{})
		if podErr != nil || replicaErr != nil {
			p.t.Error("unable to verify paused fixture isolation")
		} else {
			p.record("inert_fixtures", map[string]any{"namespace": entry.name, "pods": len(pods.Items), "replica_sets": len(replicas.Items)})
			if len(pods.Items) != 0 || len(replicas.Items) != 0 {
				p.t.Error("paused fixtures unexpectedly created runtime workloads")
			}
		}
		e = p.admin.CoreV1().Namespaces().Delete(p.ctx, entry.name, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &entry.uid}})
		if e != nil && !apierrors.IsNotFound(e) {
			p.t.Errorf("namespace cleanup: %v", e)
			continue
		}
		e = wait.PollUntilContextTimeout(p.ctx, 100*time.Millisecond, 30*time.Second, true, func(ctx context.Context) (bool, error) {
			_, e := p.admin.CoreV1().Namespaces().Get(ctx, entry.name, metav1.GetOptions{})
			return apierrors.IsNotFound(e), nil
		})
		if e != nil {
			p.t.Errorf("namespace cleanup unconfirmed %s", entry.name)
		} else {
			p.record("cleanup", map[string]any{"namespace": entry.name, "uid": string(entry.uid), "deleted": true})
		}
	}
}
func (p *realProof) writeEvidence() {
	dir := os.Getenv("GN_G2_REAL_EVIDENCE")
	if dir == "" {
		p.t.Error("explicit sanitized evidence directory required")
		return
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	b, e := json.MarshalIndent(p.evidence, "", "  ")
	if e == nil {
		e = os.WriteFile(filepath.Join(dir, "real-api-evidence.json"), append(b, '\n'), 0644)
	}
	if e != nil {
		p.t.Errorf("write evidence: %v", e)
	}
}
func (p *realProof) fixture(t *testing.T, annotations map[string]string) Action {
	t.Helper()
	a := fixtureAction(t)
	a.Target.ClusterID = p.run
	a.Target.Namespace = p.ns
	a.Target.Name = "managed"
	a.Target.RuntimeNodeSlug = "managed"
	a.Target.IncarnationID = "inc-a"
	old, e := p.admin.AppsV1().Deployments(p.ns).Get(p.ctx, "managed", metav1.GetOptions{})
	if e == nil {
		uid := old.UID
		e = p.admin.AppsV1().Deployments(p.ns).Delete(p.ctx, "managed", metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &uid}})
		if e != nil {
			t.Fatal(e)
		}
		e = wait.PollUntilContextTimeout(p.ctx, 20*time.Millisecond, 5*time.Second, true, func(ctx context.Context) (bool, error) {
			_, e := p.admin.AppsV1().Deployments(p.ns).Get(ctx, "managed", metav1.GetOptions{})
			return apierrors.IsNotFound(e), nil
		})
		if e != nil {
			t.Fatal(e)
		}
	} else if !apierrors.IsNotFound(e) {
		t.Fatal(e)
	}
	zero := int32(0)
	d := &appsv1.Deployment{ObjectMeta: metav1.ObjectMeta{Name: "managed", Namespace: p.ns, Labels: ownership(a), Annotations: annotations}, Spec: appsv1.DeploymentSpec{Replicas: &zero, Paused: true, Selector: &metav1.LabelSelector{MatchLabels: map[string]string{"fixture": p.run}}, Template: corev1.PodTemplateSpec{ObjectMeta: metav1.ObjectMeta{Labels: map[string]string{"fixture": p.run}}, Spec: corev1.PodSpec{Containers: []corev1.Container{{Name: "inert", Image: "registry.k8s.io/pause:3.10", ImagePullPolicy: corev1.PullNever}}}}}}
	d, e = p.admin.AppsV1().Deployments(p.ns).Create(p.ctx, d, metav1.CreateOptions{})
	if e != nil {
		t.Fatal(e)
	}
	// Paused fixture creates no ReplicaSet; wait for initial controller acknowledgement.
	e = wait.PollUntilContextTimeout(p.ctx, 20*time.Millisecond, 10*time.Second, true, func(ctx context.Context) (bool, error) {
		current, e := p.admin.AppsV1().Deployments(p.ns).Get(ctx, "managed", metav1.GetOptions{})
		if e != nil {
			return false, e
		}
		d = current
		return d.Status.ObservedGeneration >= d.Generation, nil
	})
	if e != nil {
		t.Fatal("initial paused Deployment acknowledgement", e)
	}
	a.Target.ExpectedUID = string(d.UID)
	a.Target.ExpectedRV = d.ResourceVersion
	a.Fingerprint = Fingerprint(a)
	return a
}
func (p *realProof) current(t *testing.T) *appsv1.Deployment {
	t.Helper()
	d, e := p.admin.AppsV1().Deployments(p.ns).Get(p.ctx, "managed", metav1.GetOptions{})
	if e != nil {
		t.Fatal(e)
	}
	return d
}
func (p *realProof) refresh(t *testing.T, a Action) Action {
	t.Helper()
	var d *appsv1.Deployment
	err := wait.PollUntilContextTimeout(p.ctx, 20*time.Millisecond, 10*time.Second, true, func(ctx context.Context) (bool, error) {
		var e error
		d, e = p.admin.AppsV1().Deployments(p.ns).Get(ctx, "managed", metav1.GetOptions{})
		if e != nil {
			return false, e
		}
		return d.Status.ObservedGeneration >= d.Generation, nil
	})
	if err != nil {
		t.Fatal("paused controller acknowledgement before new intent", err)
	}
	a.Target.ExpectedRV = d.ResourceVersion
	a.Deadline = time.Now().Add(time.Minute)
	a.Claim.Expires = time.Now().Add(time.Minute)
	a.Fingerprint = Fingerprint(a)
	return a
}
func (p *realProof) engine(t *testing.T, c Client, v Verifier) *Engine {
	t.Helper()
	if v == nil {
		v = verifyFunc(permit)
	}
	e, err := NewEngine(Config{TrustedCluster: p.run, AllowedNamespaces: []string{p.ns}, Client: c, Verifier: v, Validator: p.validator})
	if err != nil {
		t.Fatal(err)
	}
	return e
}
func (p *realProof) execute(t *testing.T, e *Engine, a Action, want string) Result {
	t.Helper()
	r := e.Execute(p.ctx, a)
	if r.Outcome != want {
		t.Fatalf("want %s got %+v", want, r)
	}
	if err := p.validator.ValidateResult(r); err != nil {
		t.Fatal(err)
	}
	return r
}
func (p *realProof) raw(t *testing.T, a Action, b []byte) (*appsv1.Deployment, error) {
	t.Helper()
	ctx, e := p.client.Prepare(p.ctx, a.Target)
	if e != nil {
		t.Fatal(e)
	}
	return p.client.Patch(ctx, a.Target, b)
}

func (p *realProof) initialAdmission(t *testing.T) {
	for _, annotations := range []map[string]string{nil, {}, {"unrelated": "preserved"}} {
		a := p.fixture(t, annotations)
		e := p.engine(t, p.client, nil)
		r := p.execute(t, e, a, "success")
		d := p.current(t)
		if d.Annotations[AdmittedGeneration] != a.Generation || d.Annotations[AdmittedIntent] != a.Fingerprint || string(d.UID) != a.Target.ExpectedUID || d.Spec.Replicas == nil || *d.Spec.Replicas != 0 || !d.Spec.Paused {
			t.Fatal("barrier or unrelated spec changed")
		}
		if annotations["unrelated"] != "" && d.Annotations["unrelated"] != "preserved" {
			t.Fatal("annotation lost")
		}
		p.execute(t, e, a, "already_satisfied")
		p.record("initial_admission", map[string]any{"annotations_initially_absent": annotations == nil, "outcome": r.Outcome, "uid": string(d.UID), "resource_version": d.ResourceVersion, "generation": d.Annotations[AdmittedGeneration]})
	}
}
func (p *realProof) markerIdentity(t *testing.T) {
	a := p.fixture(t, map[string]string{"unrelated": "preserved"})
	e := p.engine(t, p.client, nil)
	p.execute(t, e, a, "success")
	conflict := p.refresh(t, a)
	conflict.Replicas = 1
	conflict.Fingerprint = Fingerprint(conflict)
	p.execute(t, e, conflict, "ownership_conflict")
	older := p.refresh(t, a)
	older.Generation = "9"
	older.Fingerprint = Fingerprint(older)
	p.execute(t, e, older, "stale_generation")
	for _, change := range []struct {
		name, want string
		f          func(*Action)
	}{{"uid", "identity_mismatch", func(a *Action) { a.Target.ExpectedUID = "old-uid" }}, {"tenant", "ownership_conflict", func(a *Action) { a.TenantID = "other-tenant" }}, {"runtime", "ownership_conflict", func(a *Action) { a.RuntimeNodeID = "other-runtime" }}, {"incarnation", "ownership_conflict", func(a *Action) { a.Target.IncarnationID = "inc-old" }}, {"namespace", "forbidden", func(a *Action) { a.Target.Namespace = p.other }}, {"name", "forbidden", func(a *Action) { a.Target.Name = "unrelated" }}} {
		t.Run(change.name, func(t *testing.T) {
			b := p.refresh(t, a)
			change.f(&b)
			b.Fingerprint = Fingerprint(b)
			p.execute(t, e, b, change.want)
		})
	}
	for _, annotations := range []map[string]string{{AdmittedGeneration: "10"}, {AdmittedGeneration: "broken", AdmittedIntent: a.Fingerprint}, {AdmittedGeneration: "11", AdmittedIntent: a.Fingerprint}} {
		b := p.fixture(t, annotations)
		want := "ownership_conflict"
		if annotations[AdmittedGeneration] == "11" {
			want = "stale_generation"
		}
		p.execute(t, e, b, want)
	}
	// Isolate server-side fingerprint test with all other predicates correct.
	a = p.fixture(t, nil)
	p.execute(t, e, a, "success")
	a = p.refresh(t, a)
	a.Action = "set_replicas"
	a.Replicas = 2
	a.Fingerprint = Fingerprint(a)
	patch, err := makePatch(a, p.current(t))
	if err != nil {
		t.Fatal(err)
	}
	before := p.current(t)
	_, err = p.raw(t, a, patch)
	p.status(t, "wrong_fingerprint_current_UID_RV_generation", err)
	after := p.current(t)
	if after.ResourceVersion != before.ResourceVersion || *after.Spec.Replicas != 0 || after.Annotations[AppliedGeneration] != "" {
		t.Fatal("failed fingerprint predicate changed effect or markers")
	}
	p.record("marker_identity", map[string]any{"conflicting_fingerprint_rejected": true, "older_rejected": true, "immutable_and_label_conflicts_rejected": true, "independent_server_fingerprint_test": true})
}
func (p *realProof) rawStatus(t *testing.T) {
	a := p.fixture(t, map[string]string{"unrelated": "preserved"})
	before := p.current(t)
	cases := []struct {
		name  string
		patch []byte
	}{{"wrong_uid", []byte(`[{"op":"test","path":"/metadata/uid","value":"wrong"},{"op":"add","path":"/spec/replicas","value":7}]`)}, {"wrong_resource_version", []byte(`[{"op":"test","path":"/metadata/resourceVersion","value":"0"},{"op":"add","path":"/spec/replicas","value":7}]`)}, {"missing_marker_test", []byte(`[{"op":"test","path":"/metadata/annotations/infrastructure.utcp.io~1admitted-generation","value":"10"},{"op":"add","path":"/spec/replicas","value":7}]`)}, {"invalid_patch_syntax", []byte(`[{bad`)}, {"invalid_replica_value", []byte(`[{"op":"add","path":"/spec/replicas","value":-1}]`)}}
	for _, tc := range cases {
		_, err := p.raw(t, a, tc.patch)
		code := p.status(t, tc.name, err)
		// Capture the actual wire Status independently of REST-client synthesis.
		var decoded apierrors.APIStatus
		if !errors.As(err, &decoded) {
			t.Fatal("missing client-decoded Status")
		}
		p.wireStatus(t, tc.name, tc.patch, decoded.Status())
		if code != 400 && code != 409 && code != 422 {
			t.Fatalf("unexpected rejection status %d", code)
		}
		d := p.current(t)
		if d.ResourceVersion != before.ResourceVersion || *d.Spec.Replicas != 0 {
			t.Fatal("rejected patch changed object")
		}
	}
	_, err := p.raw(t, a, []byte(`[{"op":"add","path":"/spec/replicas","value":5},{"op":"test","path":"/metadata/uid","value":"wrong"}]`))
	p.status(t, "late_test_atomic_rollback", err)
	if *p.current(t).Spec.Replicas != 0 {
		t.Fatal("JSON Patch partial application")
	}
	_, err = p.scoped.AppsV1().Deployments(p.ns).Get(p.ctx, "missing", metav1.GetOptions{})
	if p.status(t, "missing_target_forbidden_by_resource_name", err) != 403 {
		t.Fatal("expected403")
	}
	_, err = p.admin.AppsV1().Deployments(p.ns).Get(p.ctx, "missing", metav1.GetOptions{})
	if p.status(t, "missing_target_admin", err) != 404 {
		t.Fatal("expected404")
	}
	bad := p.scopedConfig("invalid-ephemeral-token")
	client, err := kubernetes.NewForConfig(bad)
	if err != nil {
		t.Fatal(err)
	}
	_, err = client.AppsV1().Deployments(p.ns).Get(p.ctx, "managed", metav1.GetOptions{})
	if p.status(t, "invalid_token", err) != 401 {
		t.Fatal("expected401")
	}
}
func (p *realProof) atomicReplicas(t *testing.T) {
	a := p.fixture(t, map[string]string{"unrelated": "preserved"})
	a.Replicas = 3
	a.Fingerprint = Fingerprint(a)
	e := p.engine(t, p.client, nil)
	p.execute(t, e, a, "success")
	a = p.refresh(t, a)
	a.Action = "set_replicas"
	r := p.execute(t, e, a, "success")
	d := p.current(t)
	if *d.Spec.Replicas != 3 || d.Annotations[AppliedGeneration] != a.Generation || d.Annotations[AppliedIntent] != a.Fingerprint || d.Annotations["unrelated"] != "preserved" || !d.Spec.Paused || d.Spec.Template.Spec.Containers[0].Image != "registry.k8s.io/pause:3.10" {
		t.Fatal("atomic effect/marker/preservation mismatch")
	}
	p.execute(t, e, a, "already_satisfied")
	p.record("set_replicas", map[string]any{"outcome": r.Outcome, "replicas": *d.Spec.Replicas, "admitted": d.Annotations[AdmittedGeneration], "applied": d.Annotations[AppliedGeneration], "resource_version": d.ResourceVersion, "unrelated_preserved": true})
}

type realHookClient struct {
	Client
	before func()
}

func (c *realHookClient) Prepare(ctx context.Context, t Target) (context.Context, error) {
	if x, ok := c.Client.(interface {
		Prepare(context.Context, Target) (context.Context, error)
	}); ok {
		return x.Prepare(ctx, t)
	}
	return ctx, nil
}
func (c *realHookClient) Patch(ctx context.Context, t Target, b []byte) (*appsv1.Deployment, error) {
	if c.before != nil {
		c.before()
	}
	return c.Client.Patch(ctx, t, b)
}
func (p *realProof) concurrentAdapters(t *testing.T) {
	a := p.fixture(t, nil)
	second, err := NewKubernetesClient(KubernetesConfig{URL: p.cfg.Host, CA: p.ca, Token: p.secondToken, AllowedNamespaces: []string{p.ns}})
	if err != nil {
		t.Fatal(err)
	}
	ready := make(chan struct{}, 2)
	release := make(chan struct{})
	results := make(chan Result, 2)
	for i := 0; i < 2; i++ {
		var authenticated Client = p.client
		if i == 1 {
			authenticated = second
		}
		c := &realHookClient{Client: authenticated, before: func() { ready <- struct{}{}; <-release }}
		e := p.engine(t, c, nil)
		b := a
		b.Generation = fmt.Sprint(10 + i)
		b.Replicas = int32(i + 1)
		b.Fingerprint = Fingerprint(b)
		go func() { results <- e.Execute(p.ctx, b) }()
	}
	for i := 0; i < 2; i++ {
		select {
		case <-ready:
		case <-time.After(5 * time.Second):
			t.Fatal("concurrent writers did not reach dispatch")
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
			t.Fatalf("unexpected concurrent result %+v", r)
		}
	}
	if success != 1 || rejected != 1 {
		t.Fatalf("CAS counts success=%d rejected=%d", success, rejected)
	}
	d := p.current(t)
	p.record("concurrent_adapters", map[string]any{"success": success, "rejected": rejected, "distinct_service_accounts": 2, "outcomes": outcomes, "resource_version": d.ResourceVersion, "admitted": d.Annotations[AdmittedGeneration], "applied": d.Annotations[AppliedGeneration], "replicas": *d.Spec.Replicas})
}
func (p *realProof) olderAfterBarrier(t *testing.T) {
	a := p.fixture(t, nil)
	a.Replicas = 2
	a.Fingerprint = Fingerprint(a)
	e := p.engine(t, p.client, nil)
	p.execute(t, e, a, "success")
	old := p.refresh(t, a)
	old.Action = "set_replicas"
	patch, err := makePatch(old, p.current(t))
	if err != nil {
		t.Fatal(err)
	}
	newer := p.refresh(t, a)
	newer.Generation = "11"
	newer.Replicas = 4
	newer.Fingerprint = Fingerprint(newer)
	p.execute(t, e, newer, "success")
	_, err = p.raw(t, old, patch)
	p.status(t, "older_after_newer_raw_predicates", err)
	p.execute(t, e, old, "stale_generation")
	// Independently prove the generation predicate even with the current RV.
	old = p.refresh(t, old)
	patch, err = makePatch(old, p.current(t))
	if err != nil {
		t.Fatal(err)
	}
	_, err = p.raw(t, old, patch)
	p.status(t, "older_generation_current_RV", err)
	d := p.current(t)
	if d.Annotations[AdmittedGeneration] != "11" || *d.Spec.Replicas != 0 {
		t.Fatal("older request overwrote barrier")
	}
}
func (p *realProof) olderBeforeBarrier(t *testing.T) {
	a := p.fixture(t, nil)
	a.Replicas = 2
	a.Fingerprint = Fingerprint(a)
	e := p.engine(t, p.client, nil)
	p.execute(t, e, a, "success")
	old := p.refresh(t, a)
	old.Action = "set_replicas"
	prepared, e2 := p.client.Prepare(p.ctx, old.Target)
	if e2 != nil {
		t.Fatal(e2)
	}
	patch, e2 := makePatch(old, p.current(t))
	if e2 != nil {
		t.Fatal(e2)
	}
	old.Claim.Expires = time.Now().Add(-time.Second)
	d, err := p.client.Patch(prepared, old.Target, patch)
	if err != nil {
		t.Fatal(err)
	}
	if *d.Spec.Replicas != 2 {
		t.Fatal("prepared old request did not apply")
	}
	newer := p.refresh(t, a)
	newer.Generation = "11"
	newer.Replicas = 4
	newer.Fingerprint = Fingerprint(newer)
	p.execute(t, e, newer, "success")
	newer = p.refresh(t, newer)
	newer.Action = "set_replicas"
	p.execute(t, e, newer, "success")
	d = p.current(t)
	if *d.Spec.Replicas != 4 || d.Annotations[AppliedGeneration] != "11" {
		t.Fatal("new intent did not converge")
	}
	p.record("older_before_barrier", map[string]any{"temporary_old_effect": 2, "converged_replicas": 4, "applied_generation": d.Annotations[AppliedGeneration], "database_expiry_retracts_dispatch": false})
}
func (p *realProof) expiredClaim(t *testing.T) {
	a := p.fixture(t, nil)
	rv := a.Target.ExpectedRV
	var checks atomic.Int32
	e := p.engine(t, p.client, verifyFunc(func(context.Context, Action) error { checks.Add(1); return &DecisionError{"stale_generation"} }))
	p.execute(t, e, a, "stale_generation")
	if p.current(t).ResourceVersion != rv || checks.Load() != 1 {
		t.Fatal("claim denial mutated resource")
	}
	a.Claim.Expires = time.Now().Add(-time.Second)
	p.execute(t, e, a, "stale_generation")
	if checks.Load() != 1 {
		t.Fatal("expired request reached verifier")
	}
	p.record("expired_claim", map[string]any{"resource_version_unchanged": true, "claim_denial_before_dispatch": true})
}
func (p *realProof) nameReuse(t *testing.T) {
	old := p.fixture(t, nil)
	e := p.engine(t, p.client, nil)
	p.execute(t, e, old, "success")
	replacement := p.fixture(t, nil)
	if old.Target.ExpectedUID == replacement.Target.ExpectedUID {
		t.Fatal("UID reused")
	}
	p.execute(t, e, old, "identity_mismatch")
	r := e.Recover(p.ctx, old)
	if r.Outcome != "identity_mismatch" {
		t.Fatalf("replacement recovery %+v", r)
	}
	p.record("name_reuse", map[string]any{"old_uid": old.Target.ExpectedUID, "replacement_uid": replacement.Target.ExpectedUID, "old_execute_and_recover_rejected": true})
}

// The proxy performs real upstream HTTPS requests using only the bounded SA.
// It drops the downstream connection once, either before forwarding the PATCH,
// or after fully reading a successful real API-server mutation response.
func (p *realProof) unknown(t *testing.T, apply bool) {
	a := p.fixture(t, nil)
	a.Replicas = 3
	if !apply {
		a.Replicas = 0
	} // Replica equality without applied markers must not prove success.
	a.Fingerprint = Fingerprint(a)
	normal := p.engine(t, p.client, nil)
	p.execute(t, normal, a, "success")
	a = p.refresh(t, a)
	a.Action = "set_replicas"
	upstream, err := url.Parse(p.cfg.Host)
	if err != nil {
		t.Fatal(err)
	}
	tr, err := rest.TransportFor(p.scopedConfig(p.token))
	if err != nil {
		t.Fatal(err)
	}
	var dropped atomic.Bool
	var forwarded, received atomic.Int32
	proxy := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fault := r.Method == http.MethodPatch && dropped.CompareAndSwap(false, true)
		if r.Method == http.MethodPatch {
			received.Add(1)
		}
		if fault && !apply {
			_, _ = io.Copy(io.Discard, r.Body)
			conn, _, e := w.(http.Hijacker).Hijack()
			if e == nil {
				_ = conn.Close()
			}
			return
		}
		outgoing := r.Clone(r.Context())
		outgoing.URL.Scheme = upstream.Scheme
		outgoing.URL.Host = upstream.Host
		outgoing.Host = upstream.Host
		outgoing.RequestURI = ""
		response, e := tr.RoundTrip(outgoing)
		if e != nil {
			http.Error(w, "upstream unavailable", http.StatusBadGateway)
			return
		}
		defer response.Body.Close()
		body, e := io.ReadAll(io.LimitReader(response.Body, 16<<20))
		if e != nil {
			http.Error(w, "upstream read unavailable", http.StatusBadGateway)
			return
		}
		if r.Method == http.MethodPatch {
			forwarded.Add(1)
		}
		if fault {
			if response.StatusCode < 200 || response.StatusCode >= 300 {
				t.Errorf("fault setup upstream rejected patch: %d", response.StatusCode)
			}
			conn, _, e := w.(http.Hijacker).Hijack()
			if e == nil {
				_ = conn.Close()
			}
			return
		}
		for k, values := range response.Header {
			if strings.EqualFold(k, "Content-Length") {
				continue
			}
			for _, value := range values {
				w.Header().Add(k, value)
			}
		}
		w.WriteHeader(response.StatusCode)
		_, _ = w.Write(body)
	}))
	proxy.TLS = &tls.Config{MinVersion: tls.VersionTLS13}
	proxy.StartTLS()
	defer proxy.Close()
	ca := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: proxy.Certificate().Raw})
	proxyClient, err := NewKubernetesClient(KubernetesConfig{URL: proxy.URL, CA: ca, Token: p.token, AllowedNamespaces: []string{p.ns}})
	if err != nil {
		t.Fatal(err)
	}
	e := p.engine(t, proxyClient, nil)
	r := p.execute(t, e, a, "outcome_unknown")
	if r.Effect != "unknown" || r.Dispatch != "unknown" || r.Retry != "observe_first" {
		t.Fatal("unknown envelope weakened")
	}
	if received.Load() != 1 {
		t.Fatalf("blind PATCH retry count %d", received.Load())
	}
	wantForwarded := int32(0)
	if apply {
		wantForwarded = 1
	}
	if forwarded.Load() != wantForwarded {
		t.Fatal("upstream forwarding count")
	}
	recovered := e.Recover(p.ctx, a)
	if apply {
		if recovered.Outcome != "already_satisfied" {
			t.Fatalf("applied recovery %+v", recovered)
		}
	} else if recovered.Outcome != "drift" {
		t.Fatalf("nonapplied recovery %+v", recovered)
	}
	if received.Load() != 1 {
		t.Fatal("recovery replayed mutation")
	}
	// A newer actual barrier also makes the old uncertain intent stale.
	newer := p.refresh(t, a)
	newer.Action = "admit_generation"
	newer.Generation = "11"
	newer.Replicas = 4
	newer.Fingerprint = Fingerprint(newer)
	p.execute(t, normal, newer, "success")
	if e.Recover(p.ctx, a).Outcome != "stale_generation" {
		t.Fatal("old unknown intent crossed newer barrier")
	}
	if received.Load() != 1 {
		t.Fatal("newer barrier recovery replayed old mutation")
	}
	// Superseding canonical authorization independently denies old recovery.
	denied := p.engine(t, proxyClient, verifyFunc(func(context.Context, Action) error { return &DecisionError{"stale_generation"} }))
	if denied.Recover(p.ctx, a).Outcome != "stale_generation" {
		t.Fatal("superseded recovery trusted old action")
	}
	replacement := p.fixture(t, nil)
	if replacement.Target.ExpectedUID == a.Target.ExpectedUID || e.Recover(p.ctx, a).Outcome != "identity_mismatch" {
		t.Fatal("uncertain old action recovered against replacement identity")
	}
	if received.Load() != 1 {
		t.Fatal("replacement recovery replayed old mutation")
	}
	p.record("unknown_outcome", map[string]any{"applied": apply, "downstream_patch_attempts": received.Load(), "upstream_patches": forwarded.Load(), "initial_outcome": r.Outcome, "recovery_outcome": recovered.Outcome, "replacement_recovery": "identity_mismatch", "recovery_mutations": 0})
}
func (p *realProof) rbac(t *testing.T) {
	a := p.fixture(t, nil)
	if _, err := p.client.Get(p.ctx, a.Target); err != nil {
		t.Fatal("allowed GET", err)
	}
	type check struct {
		name string
		call func() error
	}
	checks := []check{
		{"delete", func() error {
			return p.scoped.AppsV1().Deployments(p.ns).Delete(p.ctx, "managed", metav1.DeleteOptions{})
		}},
		{"create", func() error {
			_, e := p.scoped.AppsV1().Deployments(p.ns).Create(p.ctx, &appsv1.Deployment{ObjectMeta: metav1.ObjectMeta{Name: "unauthorized"}}, metav1.CreateOptions{})
			return e
		}},
		{"read_secret", func() error {
			_, e := p.scoped.CoreV1().Secrets(p.ns).Get(p.ctx, "nonexistent", metav1.GetOptions{})
			return e
		}},
		{"other_namespace_get", func() error {
			_, e := p.scoped.AppsV1().Deployments(p.other).Get(p.ctx, "managed", metav1.GetOptions{})
			return e
		}},
		{"other_namespace_patch", func() error {
			_, e := p.scoped.AppsV1().Deployments(p.other).Patch(p.ctx, "managed", types.MergePatchType, []byte(`{"spec":{"replicas":0}}`), metav1.PatchOptions{})
			return e
		}},
		{"mutate_node", func() error {
			_, e := p.scoped.CoreV1().Nodes().Patch(p.ctx, "nonexistent", types.MergePatchType, []byte(`{"metadata":{"labels":{"unauthorized":"true"}}}`), metav1.PatchOptions{})
			return e
		}},
		{"list_namespaces", func() error { _, e := p.scoped.CoreV1().Namespaces().List(p.ctx, metav1.ListOptions{}); return e }},
		{"list_deployments", func() error { _, e := p.scoped.AppsV1().Deployments(p.ns).List(p.ctx, metav1.ListOptions{}); return e }},
		{"unrelated_deployment", func() error {
			_, e := p.scoped.AppsV1().Deployments(p.ns).Get(p.ctx, "unrelated", metav1.GetOptions{})
			return e
		}},
		{"scale_patch", func() error {
			return p.scoped.AppsV1().RESTClient().Patch(types.MergePatchType).Namespace(p.ns).Resource("deployments").Name("managed").SubResource("scale").Body([]byte(`{"spec":{"replicas":5}}`)).Do(p.ctx).Error()
		}},
	}
	for _, c := range checks {
		t.Run(c.name, func(t *testing.T) {
			if p.status(t, "RBAC_"+c.name, c.call()) != 403 {
				t.Fatal("expected403")
			}
		})
	}
	p.record("RBAC", map[string]any{"get_named_allowed": true, "patch_named_allowed": true, "denials": len(checks), "adapter_uses_admin_credentials": false})
}
func (p *realProof) scaleBoundary(t *testing.T) {
	a := p.fixture(t, nil)
	a.Replicas = 2
	a.Fingerprint = Fingerprint(a)
	e := p.engine(t, p.client, nil)
	p.execute(t, e, a, "success")
	a = p.refresh(t, a)
	a.Action = "set_replicas"
	p.execute(t, e, a, "success")
	a = p.refresh(t, a) // Wait for controller acknowledgement before fixture-admin scale CAS.
	scale, err := p.admin.AppsV1().Deployments(p.ns).GetScale(p.ctx, "managed", metav1.GetOptions{})
	if err != nil {
		t.Fatal(err)
	}
	scale.Spec.Replicas = 7
	_, err = p.admin.AppsV1().Deployments(p.ns).UpdateScale(p.ctx, "managed", scale, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	d := p.current(t)
	if *d.Spec.Replicas != 7 || d.Annotations[AppliedGeneration] != a.Generation || d.Annotations[AppliedIntent] != a.Fingerprint {
		t.Fatal("external scale boundary not reproduced")
	}
	a = p.refresh(t, a)
	r := e.Recover(p.ctx, a)
	if r.Outcome != "drift" {
		t.Fatalf("scale drift recovery %+v", r)
	}
	// Another actor changing unrelated metadata after the adapter GET must invalidate CAS.
	b := p.fixture(t, nil)
	hook := &realHookClient{Client: p.client, before: func() {
		_, err := p.admin.AppsV1().Deployments(p.ns).Patch(p.ctx, "managed", types.MergePatchType, []byte(`{"metadata":{"annotations":{"external":"writer"}}}`), metav1.PatchOptions{})
		if err != nil {
			t.Error(err)
		}
	}}
	r = p.engine(t, hook, nil).Execute(p.ctx, b)
	if r.Outcome != "invalid_request" && r.Outcome != "resource_version_conflict" {
		t.Fatalf("external metadata CAS %+v", r)
	}
	p.record("scale_boundary", map[string]any{"external_scale_can_change_replicas_without_markers": true, "adapter_scale_permission": false, "recovery_detected_drift": true, "external_writer_exclusivity_required": true, "unrelated_metadata_CAS_outcome": r.Outcome})
}
func (p *realProof) secondIdentity(t *testing.T) {
	a := p.fixture(t, nil)
	second, err := NewKubernetesClient(KubernetesConfig{URL: p.cfg.Host, CA: p.ca, Token: p.secondToken, AllowedNamespaces: []string{p.ns}})
	if err != nil {
		t.Fatal(err)
	}
	p.execute(t, p.engine(t, second, nil), a, "success")
	p.execute(t, p.engine(t, p.client, nil), a, "already_satisfied")
	p.record("second_adapter_identity", map[string]any{"distinct_service_accounts": 2, "same_bounded_RBAC": true, "same_intent_idempotent": true, "claim_authority": "test-only verifier"})
}
func (p *realProof) claimFailure(t *testing.T) {
	a := p.fixture(t, nil)
	rv := a.Target.ExpectedRV
	for _, outcome := range []string{"unauthorized", "forbidden", "stale_generation", "transport_unavailable", "timeout", "terminal_failure"} {
		e := p.engine(t, p.client, verifyFunc(func(context.Context, Action) error { return &DecisionError{outcome} }))
		r := p.execute(t, e, a, outcome)
		if r.Dispatch != "not_sent" || p.current(t).ResourceVersion != rv {
			t.Fatalf("claim failure dispatched %+v", r)
		}
	}
	// Cancellation is observed before touching Kubernetes.
	ctx, cancel := context.WithCancel(p.ctx)
	cancel()
	r := p.engine(t, p.client, nil).Execute(ctx, a)
	if r.Outcome != "cancelled" || r.Dispatch != "not_sent" {
		t.Fatalf("cancel classification %+v", r)
	}
	p.record("claim_fail_closed", map[string]any{"denied_or_unavailable_cases": 6, "resource_version_unchanged": true, "cancelled_before_dispatch": true, "production_PHP_callback": false})
}

func (p *realProof) concurrentReplicas(t *testing.T) {
	a := p.fixture(t, nil)
	a.Replicas = 3
	a.Fingerprint = Fingerprint(a)
	p.execute(t, p.engine(t, p.client, nil), a, "success")
	a = p.refresh(t, a)
	a.Action = "set_replicas"
	ready := make(chan struct{}, 2)
	release := make(chan struct{})
	results := make(chan Result, 2)
	for i := 0; i < 2; i++ {
		c := &realHookClient{Client: p.client, before: func() { ready <- struct{}{}; <-release }}
		e := p.engine(t, c, nil)
		go func() { results <- e.Execute(p.ctx, a) }()
	}
	for i := 0; i < 2; i++ {
		select {
		case <-ready:
		case <-time.After(5 * time.Second):
			t.Fatal("replica writers did not reach synchronized dispatch")
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
			t.Fatalf("unexpected replica CAS %+v", r)
		}
	}
	d := p.current(t)
	if success != 1 || rejected != 1 || *d.Spec.Replicas != 3 || d.Annotations[AppliedGeneration] != a.Generation || d.Annotations[AppliedIntent] != a.Fingerprint {
		t.Fatal("replica/marker CAS combination not atomic")
	}
	p.record("concurrent_replica_effects", map[string]any{"success": success, "rejected": rejected, "outcomes": outcomes, "resource_version": d.ResourceVersion, "admitted": d.Annotations[AdmittedGeneration], "applied": d.Annotations[AppliedGeneration], "replicas": *d.Spec.Replicas})
}

// wireStatus records only stable structured Status fields, never raw messages,
// credential headers, response bodies, or resource dumps.
func (p *realProof) wireStatus(t *testing.T, name string, patch []byte, decoded metav1.Status) {
	t.Helper()
	client, err := rest.HTTPClientFor(p.scopedConfig(p.token))
	if err != nil {
		t.Fatal(err)
	}
	request, err := http.NewRequestWithContext(p.ctx, http.MethodPatch, p.cfg.Host+"/apis/apps/v1/namespaces/"+p.ns+"/deployments/managed", strings.NewReader(string(patch)))
	if err != nil {
		t.Fatal(err)
	}
	request.Header.Set("Content-Type", "application/json-patch+json")
	request.Header.Set("Accept", "application/json")
	response, err := client.Do(request)
	if err != nil {
		t.Fatal("wire API request failed")
	}
	defer response.Body.Close()
	body, err := io.ReadAll(io.LimitReader(response.Body, 65537))
	if err != nil || len(body) > 65536 {
		t.Fatal("bounded wire Status acquisition failed")
	}
	if response.StatusCode != int(decoded.Code) {
		t.Fatalf("wire code=%d client=%d", response.StatusCode, decoded.Code)
	}
	var status metav1.Status
	if json.Unmarshal(body, &status) != nil || status.Kind != "Status" || status.APIVersion != "v1" {
		t.Fatal("API wire error was not a v1 Status")
	}
	if status.Code != decoded.Code || status.Reason != decoded.Reason {
		t.Fatal("client Status code/reason differ from real API wire Status")
	}
	var decodedCauses []metav1.StatusCause
	if decoded.Details != nil {
		decodedCauses = decoded.Details.Causes
	}
	var causes []map[string]string
	if status.Details != nil {
		for _, cause := range status.Details.Causes {
			causes = append(causes, map[string]string{"type": string(cause.Type), "field": cause.Field})
		}
	}
	if len(causes) != len(decodedCauses) {
		t.Fatal("client lost real API Status causes")
	}
	for i, cause := range causes {
		if cause["type"] != string(decodedCauses[i].Type) || cause["field"] != decodedCauses[i].Field {
			t.Fatal("client Status cause projection differs from real API wire Status")
		}
	}
	p.record("wire_"+name, map[string]any{"http_code": response.StatusCode, "status_code": status.Code, "reason": string(status.Reason), "kind": status.Kind, "causes": causes, "client_projection_matches_wire": true})
}
