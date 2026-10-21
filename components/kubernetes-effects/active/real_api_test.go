//go:build realapi

package active

// Real API proof uses only the run-owned disposable cluster. Provider templates
// remain at zero replicas: only the separate fixed pause fixture executes Pods.
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
	lifecycle "utcp.invalid/components/kubernetes-effects/lifecycle"
)

type activeProof struct {
	t              *testing.T
	ctx            context.Context
	admin          kubernetes.Interface
	cfg            *rest.Config
	run, ns, other string
	namespaces     map[string]types.UID
	tokens         []string
	mu             sync.Mutex
	evidence       []map[string]any
	images         map[string]string
}

func activeIncarnation(label string) string {
	h := sha256.Sum256([]byte("active-" + label))
	return hex.EncodeToString(h[:16])
}
func newActiveProof(t *testing.T) *activeProof {
	t.Helper()
	run, file, want, uid := os.Getenv("GN_G2_REAL_RUN"), os.Getenv("GN_G2_REAL_KUBECONFIG"), os.Getenv("GN_G2_REAL_CONTEXT"), os.Getenv("GN_G2_REAL_SYSTEM_UID")
	if !regexp.MustCompile(`^gn-g2-proof-[a-f0-9]{12}$`).MatchString(run) || file == "" || want != "k3d-"+run || uid == "" {
		t.Fatal("explicit owned disposable cluster identity required")
	}
	raw, e := clientcmd.LoadFromFile(file)
	if e != nil || raw.CurrentContext != want || len(raw.Contexts) != 1 || len(raw.Clusters) != 1 {
		t.Fatal("unexpected disposable kubeconfig")
	}
	cfg, e := clientcmd.NewNonInteractiveClientConfig(*raw, want, &clientcmd.ConfigOverrides{}, nil).ClientConfig()
	if e != nil {
		t.Fatal("invalid disposable kubeconfig")
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
	p := &activeProof{t: t, ctx: context.Background(), admin: admin, cfg: cfg, run: run, ns: run + "-active", other: run + "-active-other", namespaces: map[string]types.UID{}, images: map[string]string{
		"asterisk.managed.v1": os.Getenv("GN_G2_ACTIVE_ASTERISK_IMAGE"), "freeswitch.managed.v1": os.Getenv("GN_G2_ACTIVE_FREESWITCH_IMAGE"),
	}}
	for _, image := range p.images {
		if !regexp.MustCompile(`^[a-z0-9][a-z0-9./:_-]*@sha256:[0-9a-f]{64}$`).MatchString(image) {
			t.Fatal("verified provider digest references required; no substituted fixture image")
		}
	}
	t.Cleanup(p.cleanup)
	for _, ns := range []string{p.ns, p.other} {
		n, e := admin.CoreV1().Namespaces().Create(p.ctx, &corev1.Namespace{ObjectMeta: metav1.ObjectMeta{Name: ns, Labels: map[string]string{"verification.utcp.io/run": run}}}, metav1.CreateOptions{})
		if e != nil {
			t.Fatal(e)
		}
		p.namespaces[ns] = n.UID
	}
	names := []string{}
	for _, n := range []string{"asterisk", "freeswitch", "guards", "race", "delayed", "before-barrier", "applied", "unapplied", "replacement", "claim", "pause"} {
		names = append(names, "utcp-"+activeIncarnation(n))
	}
	_, e = admin.RbacV1().Roles(p.ns).Create(p.ctx, &rbacv1.Role{ObjectMeta: metav1.ObjectMeta{Name: "active"}, Rules: []rbacv1.PolicyRule{{APIGroups: []string{"apps"}, Resources: []string{"deployments"}, ResourceNames: names, Verbs: []string{"get", "patch"}}}}, metav1.CreateOptions{})
	if e != nil {
		t.Fatal(e)
	}
	subjects := []rbacv1.Subject{}
	for _, name := range []string{"active-a", "active-b"} {
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
	_, e = admin.RbacV1().RoleBindings(p.ns).Create(p.ctx, &rbacv1.RoleBinding{ObjectMeta: metav1.ObjectMeta{Name: "active"}, RoleRef: rbacv1.RoleRef{APIGroup: rbacv1.GroupName, Kind: "Role", Name: "active"}, Subjects: subjects}, metav1.CreateOptions{})
	if e != nil {
		t.Fatal(e)
	}
	v, e := admin.Discovery().ServerVersion()
	if e != nil {
		t.Fatal(e)
	}
	p.record("environment", map[string]any{"run": run, "context": want, "endpoint": cfg.Host, "kubernetes": v.GitVersion, "provider_execution": false, "adapter_verbs": []string{"get", "patch"}, "fixture_execution": "fixed harmless pause image only"})
	return p
}
func (p *activeProof) config(index int) *rest.Config {
	return &rest.Config{Host: p.cfg.Host, TLSClientConfig: rest.TLSClientConfig{CAData: p.cfg.CAData, CAFile: p.cfg.CAFile}, BearerToken: p.tokens[index], Timeout: 10 * time.Second, QPS: 50, Burst: 100}
}
func (p *activeProof) client(t *testing.T, index int) Client {
	t.Helper()
	c, e := lifecycle.NewKubernetesClient(p.config(index), p.run, []string{p.ns})
	if e != nil {
		t.Fatal(e)
	}
	return c
}

type activeProofVerifier func(context.Context, Action) error

func (v activeProofVerifier) Verify(ctx context.Context, a Action) error { return v(ctx, a) }
func (p *activeProof) engine(t *testing.T, c Client, v Verifier) *Engine {
	t.Helper()
	if v == nil {
		v = activeProofVerifier(func(context.Context, Action) error { return nil })
	}
	e, err := NewEngine(Config{TrustedCluster: p.run, AllowedNamespaces: []string{p.ns}, Client: c, Verifier: v, ApprovedImages: map[string][]string{"asterisk.managed.v1": {p.images["asterisk.managed.v1"]}, "freeswitch.managed.v1": {p.images["freeswitch.managed.v1"]}}, ApprovedSecrets: map[string][]string{p.ns: {"runtime-control"}}})
	if err != nil {
		t.Fatal(err)
	}
	return e
}
func (p *activeProof) record(name string, v map[string]any) {
	p.mu.Lock()
	defer p.mu.Unlock()
	v["case"] = name
	p.evidence = append(p.evidence, v)
	p.t.Logf("active API evidence %s: %v", name, v)
}
func (p *activeProof) status(t *testing.T, name string, e error, want int32) {
	t.Helper()
	var s apierrors.APIStatus
	if !errors.As(e, &s) || s.Status().Code != want {
		t.Fatalf("%s wanted Status %d, got %T %v", name, want, e, e)
	}
	p.record(name, map[string]any{"code": s.Status().Code, "reason": string(s.Status().Reason)})
}
func (p *activeProof) cleanup() {
	for ns, uid := range p.namespaces {
		n, e := p.admin.CoreV1().Namespaces().Get(p.ctx, ns, metav1.GetOptions{})
		if apierrors.IsNotFound(e) {
			continue
		}
		if e != nil || n.UID != uid || n.Labels["verification.utcp.io/run"] != p.run {
			p.t.Error("refusing namespace cleanup identity mismatch")
			continue
		}
		e = p.admin.CoreV1().Namespaces().Delete(p.ctx, ns, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &uid}})
		if e != nil && !apierrors.IsNotFound(e) {
			p.t.Error("owned namespace delete failed")
			continue
		}
		e = wait.PollUntilContextTimeout(p.ctx, 100*time.Millisecond, 45*time.Second, true, func(ctx context.Context) (bool, error) {
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
		e = os.WriteFile(filepath.Join(dir, "active-api-evidence.json"), append(b, '\n'), 0644)
	}
	if e != nil {
		p.t.Error("sanitized evidence write failed")
	}
}
func (p *activeProof) fixture(t *testing.T, label string) Action {
	t.Helper()
	inc := activeIncarnation(label)
	q := lifecycle.Projection{TemplateVersion: "inert.pause.v1", Image: lifecycle.PauseImage, ConfigurationID: "config-1", ConfigurationSHA256: strings.Repeat("a", 64)}
	old := lifecycle.Action{Version: lifecycle.Version, RequestID: "request-" + label, OperationID: "operation-" + label, TenantID: "tenant-a", RuntimeNodeID: "runtime-a", Target: Target{ClusterID: p.run, Namespace: p.ns, Kind: "Deployment", Name: "utcp-" + inc, RuntimeNodeSlug: "runtime-a", TargetID: "target-" + label, IncarnationID: inc}, Generation: "1", Action: "create_suspended", Intent: lifecycle.Intent{Kind: "create_suspended", Projection: &q, ProjectionFingerprint: lifecycle.ProjectionFingerprint(q)}, IdempotencyKey: "idem-" + label, Claim: lifecycle.Claim{Attempt: 1, Token: strings.Repeat("1", 32), Expires: time.Now().UTC().Add(time.Minute)}, Deadline: time.Now().UTC().Add(time.Minute), CorrelationID: "correlation-" + label, AuditID: "audit-" + label}
	old.Fingerprint = lifecycle.Fingerprint(old)
	_, e := p.admin.AppsV1().Deployments(p.ns).Create(p.ctx, lifecycle.SuspendedDeployment(old), metav1.CreateOptions{})
	if e != nil {
		t.Fatal(e)
	}
	image := p.images["asterisk.managed.v1"]
	profile := "asterisk.managed.v1"
	if label == "freeswitch" {
		profile = "freeswitch.managed.v1"
		image = p.images[profile]
	}
	proj := Projection{TemplateVersion: profile, Image: image, ConfigurationID: "config-active", ConfigurationSHA256: strings.Repeat("b", 64), SecretName: "runtime-control"}
	a := Action{Version: Version, CanonicalState: "bound", RequestID: old.RequestID, OperationID: old.OperationID, TenantID: old.TenantID, RuntimeNodeID: old.RuntimeNodeID, Target: old.Target, Generation: "10", Action: "converge_projection", Intent: Intent{Kind: "converge_projection", Projection: &proj, ProjectionFingerprint: ProjectionFingerprint(proj), Replicas: Replicas(0)}, IdempotencyKey: old.IdempotencyKey, Claim: Claim{Attempt: 1, Token: strings.Repeat("1", 32), Expires: old.Deadline}, Deadline: old.Deadline, CorrelationID: old.CorrelationID, AuditID: old.AuditID}
	return p.bind(t, a)
}
func (p *activeProof) get(t *testing.T, a Action) *appsv1.Deployment {
	t.Helper()
	d, e := p.admin.AppsV1().Deployments(p.ns).Get(p.ctx, a.Target.Name, metav1.GetOptions{})
	if e != nil {
		t.Fatal(e)
	}
	return d
}
func (p *activeProof) bind(t *testing.T, a Action) Action {
	t.Helper()
	var d *appsv1.Deployment
	last, stable := "", 0
	err := wait.PollUntilContextTimeout(p.ctx, 100*time.Millisecond, 15*time.Second, true, func(ctx context.Context) (bool, error) {
		var e error
		d, e = p.admin.AppsV1().Deployments(p.ns).Get(ctx, a.Target.Name, metav1.GetOptions{})
		if e != nil {
			return false, e
		}
		if d.Status.ObservedGeneration < d.Generation {
			last = ""
			stable = 0
			return false, nil
		}
		if d.ResourceVersion == last {
			stable++
		} else {
			last = d.ResourceVersion
			stable = 0
		}
		return stable >= 4, nil
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
func (p *activeProof) execute(t *testing.T, e *Engine, a Action, want string) Result {
	t.Helper()
	r := e.Execute(p.ctx, a)
	if r.Outcome != want {
		t.Fatalf("expected %s got %+v", want, r)
	}
	if e := ValidateResult(r); e != nil {
		t.Fatalf("result schema %v", e)
	}
	return r
}
func activeNext(a Action, generation, config string) Action {
	a.Generation = generation
	q := *a.Intent.Projection
	q.ConfigurationID = config
	a.Intent.Projection = &q
	a.Intent.ProjectionFingerprint = ProjectionFingerprint(q)
	a.Fingerprint = Fingerprint(a)
	return a
}

func TestRealAPIActiveProfiles(t *testing.T) {
	p := newActiveProof(t)
	t.Run("asterisk_fixed_projection", func(t *testing.T) { p.provider(t, "asterisk") })
	t.Run("freeswitch_fixed_projection", func(t *testing.T) { p.provider(t, "freeswitch") })
	t.Run("activation_identity_claim_retirement_guards", p.guards)
	t.Run("two_adapters_atomic_projection", p.concurrent)
	t.Run("older_after_retirement_barrier", p.delayed)
	t.Run("older_before_retirement_barrier", p.beforeBarrier)
	t.Run("paused_ReplicaSet_scaling_is_not_suspension", p.pausedScaling)
	t.Run("replacement_UID_rejected", p.replacement)
	for _, apply := range []bool{true, false} {
		t.Run(fmt.Sprintf("unknown_projection_applied_%v", apply), func(t *testing.T) { p.unknown(t, apply) })
	}
	t.Run("RBAC_denials", p.rbac)
}
func (p *activeProof) provider(t *testing.T, label string) {
	a := p.fixture(t, label)
	e := p.engine(t, p.client(t, 0), nil)
	d := p.get(t, a)
	d.Annotations["verification.utcp.io/unrelated"] = "preserved"
	limit := int32(7)
	d.Spec.RevisionHistoryLimit = &limit
	_, err := p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	a = p.bind(t, a)
	p.execute(t, e, a, "success")
	p.execute(t, e, a, "already_satisfied")
	d = p.get(t, a)
	if d.Spec.Paused || d.Spec.Replicas == nil || *d.Spec.Replicas != 0 || len(d.Spec.Template.Spec.Containers) != 1 || d.Spec.Template.Spec.Containers[0].Image != a.Intent.Projection.Image || d.Annotations[AppliedGeneration] != a.Generation || d.Annotations[AppliedIntent] != a.Fingerprint || d.Annotations[AdmittedGeneration] != a.Generation || d.Annotations[ProjectionMarker] != a.Intent.ProjectionFingerprint || d.Annotations["verification.utcp.io/unrelated"] != "preserved" || *d.Spec.RevisionHistoryLimit != 7 {
		t.Fatal("atomic projection marker/controlled field or unrelated field mismatch")
	}
	c := d.Spec.Template.Spec.Containers[0]
	if len(c.Ports) == 0 || c.StartupProbe == nil || c.ReadinessProbe == nil || c.LivenessProbe == nil {
		t.Fatal("required provider ports/probes absent")
	}
	ports := map[string]string{}
	for _, port := range c.Ports {
		ports[port.Name] = fmt.Sprintf("%d/%s", port.ContainerPort, port.Protocol)
	}
	if ports["sip"] != "5060/UDP" || (label == "asterisk" && (len(ports) != 2 || ports["ari"] != "8088/TCP")) || (label == "freeswitch" && (len(ports) != 3 || ports["esl"] != "8021/TCP" || ports["rtp"] != "21000/UDP")) {
		t.Fatal("provider-specific port contract mismatch")
	}
	pod := d.Spec.Template.Spec
	if pod.AutomountServiceAccountToken == nil || *pod.AutomountServiceAccountToken || pod.HostNetwork || pod.HostPID || pod.HostIPC || len(pod.InitContainers) != 0 || len(c.Command) != 0 || len(c.Args) != 0 || len(c.EnvFrom) != 1 || c.EnvFrom[0].SecretRef == nil || c.EnvFrom[0].SecretRef.Name != "runtime-control" {
		t.Fatal("provider execution or credential boundary mismatch")
	}
	if c.Resources.Requests.Cpu().String() != "50m" || c.Resources.Requests.Memory().String() != "128Mi" || c.Resources.Limits.Cpu().String() != "500m" || c.Resources.Limits.Memory().String() != "384Mi" {
		t.Fatal("provider resource contract mismatch")
	}
	pods, err := p.admin.CoreV1().Pods(p.ns).List(p.ctx, metav1.ListOptions{LabelSelector: Prefix + "incarnation-id=" + a.Target.IncarnationID})
	if err != nil || len(pods.Items) != 0 {
		t.Fatal("zero replica provider fixture unexpectedly executed")
	}
	p.record(label+"_projection", map[string]any{"profile": a.Intent.Projection.TemplateVersion, "image": c.Image, "paused": d.Spec.Paused, "replicas": *d.Spec.Replicas, "pods": 0, "atomic_markers": true, "unrelated_fields": "preserved", "idempotent": "already_satisfied", "provider_execution": false})
	newer := activeNext(p.bind(t, a), "11", "config-newer")
	p.execute(t, e, newer, "success")
	old := p.bind(t, a)
	p.execute(t, e, old, "stale_generation")
	conflict := activeNext(p.bind(t, newer), "11", "config-conflicting")
	p.execute(t, e, conflict, "ownership_conflict")
	stale := activeNext(p.bind(t, newer), "12", "config-stale")
	stale.Target.ExpectedRV = String("0")
	p.execute(t, e, stale, "resource_version_conflict")
}
func (p *activeProof) guards(t *testing.T) {
	a := p.fixture(t, "guards")
	e := p.engine(t, p.client(t, 0), nil)
	before := p.get(t, a)
	bad := a
	bad.CanonicalState = "preparing"
	bad.Fingerprint = Fingerprint(bad)
	p.execute(t, e, bad, "invalid_request")
	bad = a
	bad.Target.ExpectedUID = nil
	bad.Fingerprint = Fingerprint(bad)
	p.execute(t, e, bad, "invalid_request")
	bad = a
	bad.Target.ExpectedUID = String("wrong-uid")
	bad.Fingerprint = Fingerprint(bad)
	p.execute(t, e, bad, "identity_mismatch")
	bad = a
	bad.Claim.Expires = time.Now().UTC().Add(-time.Second)
	p.execute(t, e, bad, "stale_generation")
	deny := p.engine(t, p.client(t, 0), activeProofVerifier(func(context.Context, Action) error { return &DecisionError{Outcome: "stale_generation"} }))
	p.execute(t, deny, a, "stale_generation")
	after := p.get(t, a)
	if before.ResourceVersion != after.ResourceVersion {
		t.Fatal("rejected activation mutated target")
	}
	// Canonical retirement and Kubernetes barrier are separate authorities. A
	// stale envelope claiming bound must not bypass the installed retirement hold.
	d := p.get(t, a)
	d.Annotations[RetirementMarker] = "retirement-guard"
	d.Annotations[AdmittedGeneration] = "20"
	d.Annotations[AdmittedIntent] = strings.Repeat("c", 64)
	_, err := p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	old := p.bind(t, a)
	r := e.Execute(p.ctx, old)
	if r.Outcome != "stale_generation" && r.Outcome != "ownership_conflict" {
		t.Fatalf("retirement guard %+v", r)
	}
	newer := activeNext(p.bind(t, a), "21", "config-illegal-reactivation")
	r = e.Execute(p.ctx, newer)
	if r.Outcome != "stale_generation" && r.Outcome != "ownership_conflict" {
		t.Fatalf("retired incarnation reactivated %+v", r)
	}
	if !p.get(t, a).Spec.Paused {
		t.Fatal("retired fixture activated")
	}
	p.record("activation_guards", map[string]any{"preparing": "invalid_request", "unbound_UID": "invalid_request", "wrong_UID": "identity_mismatch", "expired_claim": "stale_generation", "denied_claim": "stale_generation", "retired_incarnation": "rejected", "no_dispatch_on_rejection": true})
}

type activeGateClient struct {
	Client
	arrived chan<- struct{}
	release <-chan struct{}
}

func (c *activeGateClient) Patch(ctx context.Context, t Target, b []byte) (*appsv1.Deployment, error) {
	c.arrived <- struct{}{}
	select {
	case <-c.release:
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	return c.Client.Patch(ctx, t, b)
}
func (p *activeProof) concurrent(t *testing.T) {
	a := p.fixture(t, "race")
	b := activeNext(a, "10", "race-other")
	arrived := make(chan struct{}, 2)
	release := make(chan struct{})
	out := make(chan Result, 2)
	for i, intent := range []Action{a, b} {
		e := p.engine(t, &activeGateClient{Client: p.client(t, i), arrived: arrived, release: release}, nil)
		go func(a Action) { out <- e.Execute(p.ctx, a) }(intent)
	}
	for range 2 {
		select {
		case <-arrived:
		case <-time.After(10 * time.Second):
			t.Fatal("concurrent dispatch gate")
		}
	}
	close(release)
	successes, rejected := 0, 0
	for range 2 {
		r := <-out
		if r.Outcome == "success" {
			successes++
		} else if r.Outcome == "invalid_request" || r.Outcome == "resource_version_conflict" {
			rejected++
		} else {
			t.Fatalf("unexpected CAS race %+v", r)
		}
	}
	if successes != 1 || rejected != 1 {
		t.Fatalf("CAS outcomes success=%d rejected=%d", successes, rejected)
	}
	d := p.get(t, a)
	if d.Annotations[AppliedIntent] != d.Annotations[AdmittedIntent] || d.Annotations[AppliedGeneration] != "10" || *d.Spec.Replicas != 0 {
		t.Fatal("partial marker/effect race")
	}
	p.record("concurrent_adapter_projection", map[string]any{"success": successes, "rejected": rejected, "resource_version": d.ResourceVersion, "applied_generation": d.Annotations[AppliedGeneration], "replicas": *d.Spec.Replicas, "atomic_markers": true})
}
func (p *activeProof) delayed(t *testing.T) {
	a := p.fixture(t, "delayed")
	arrived := make(chan struct{}, 1)
	release := make(chan struct{})
	var retired atomic.Bool
	verifier := activeProofVerifier(func(context.Context, Action) error {
		if retired.Load() {
			return &DecisionError{Outcome: "stale_generation"}
		}
		return nil
	})
	e := p.engine(t, &activeGateClient{Client: p.client(t, 0), arrived: arrived, release: release}, verifier)
	done := make(chan Result, 1)
	go func() { done <- e.Execute(p.ctx, a) }()
	select {
	case <-arrived:
	case <-time.After(10 * time.Second):
		t.Fatal("activation dispatch gate")
	}
	retired.Store(true)
	d := p.get(t, a)
	d.Annotations[AdmittedGeneration] = "20"
	d.Annotations[AdmittedIntent] = strings.Repeat("d", 64)
	d.Annotations[RetirementMarker] = "retirement-newer"
	_, err := p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	close(release)
	r := <-done
	if r.Outcome != "invalid_request" && r.Outcome != "resource_version_conflict" {
		t.Fatalf("delayed old patch crossed barrier %+v", r)
	}
	d = p.get(t, a)
	if d.Annotations[AdmittedGeneration] != "20" || !d.Spec.Paused || *d.Spec.Replicas != 0 {
		t.Fatal("old activation overwrote retirement barrier")
	}
	if e.Recover(p.ctx, a).Outcome != "stale_generation" {
		t.Fatal("retired claim recovered active intent")
	}
	p.record("older_after_retirement_barrier", map[string]any{"conditional_rejection": r.Outcome, "final_generation": "20", "paused": true, "replicas": 0, "stale_recovery": "rejected"})
}
func (p *activeProof) beforeBarrier(t *testing.T) {
	a := p.fixture(t, "before-barrier")
	arrived := make(chan struct{}, 1)
	release := make(chan struct{})
	var retired atomic.Bool
	verifier := activeProofVerifier(func(context.Context, Action) error {
		if retired.Load() {
			return &DecisionError{Outcome: "stale_generation"}
		}
		return nil
	})
	e := p.engine(t, &activeGateClient{Client: p.client(t, 0), arrived: arrived, release: release}, verifier)
	done := make(chan Result, 1)
	go func() { done <- e.Execute(p.ctx, a) }()
	select {
	case <-arrived:
	case <-time.After(10 * time.Second):
		t.Fatal("activation dispatch gate")
	}
	retired.Store(true)
	close(release)
	r := <-done
	if r.Outcome != "success" {
		t.Fatalf("already dispatched valid patch should remain capable of applying %+v", r)
	}
	d := p.get(t, a)
	if d.Spec.Paused || d.Annotations[AppliedIntent] != a.Fingerprint {
		t.Fatal("pre-barrier request did not demonstrate stale-effect window")
	}
	// Authorized fixture administrator models the newer retirement convergence;
	// the adapter neither invents retirement intent nor compensates automatically.
	d = p.get(t, a)
	d.Spec.Replicas = Replicas(0)
	d.Spec.Paused = true
	d.Annotations[AdmittedGeneration] = "20"
	d.Annotations[AdmittedIntent] = strings.Repeat("e", 64)
	d.Annotations[RetirementMarker] = "retirement-converged"
	_, err := p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if e.Recover(p.ctx, a).Outcome != "stale_generation" {
		t.Fatal("expired canonical authorization replayed")
	}
	p.record("older_before_retirement_barrier", map[string]any{"already_dispatched_can_apply": true, "temporary_stale_projection": true, "provider_replicas": 0, "retirement_convergence": "separately authorized newer barrier", "automatic_compensation": false})
}

func (p *activeProof) podCount(t *testing.T, a Action, want int, requireRunning bool) {
	t.Helper()
	var phaseCounts map[corev1.PodPhase]int
	err := wait.PollUntilContextTimeout(p.ctx, 150*time.Millisecond, 75*time.Second, true, func(ctx context.Context) (bool, error) {
		pods, e := p.admin.CoreV1().Pods(p.ns).List(ctx, metav1.ListOptions{LabelSelector: Prefix + "incarnation-id=" + a.Target.IncarnationID})
		if e != nil {
			return false, e
		}
		phaseCounts = map[corev1.PodPhase]int{}
		running := 0
		for _, pod := range pods.Items {
			phaseCounts[pod.Status.Phase]++
			if pod.DeletionTimestamp == nil && pod.Status.Phase == corev1.PodRunning {
				running++
			}
			if len(pod.Spec.Containers) != 1 || pod.Spec.Containers[0].Image != lifecycle.PauseImage {
				t.Fatal("refusing non-pause workload execution in harmless fixture")
			}
		}
		return len(pods.Items) == want && (!requireRunning || running == want), nil
	})
	if err != nil {
		t.Fatalf("pause fixture Pod reconciliation wanted=%d running=%v phases=%v: %v", want, requireRunning, phaseCounts, err)
	}
}
func (p *activeProof) pausedScaling(t *testing.T) {
	a := p.fixture(t, "pause")
	// Fixture admin never uses the active provider template for this proof. The
	// immutable inert /pause template is the only template permitted to execute.
	d := p.get(t, a)
	if len(d.Spec.Template.Spec.Containers) != 1 || d.Spec.Template.Spec.Containers[0].Image != lifecycle.PauseImage || !d.Spec.Paused || *d.Spec.Replicas != 0 {
		t.Fatal("harmless inert fixture mismatch")
	}
	p.podCount(t, a, 0, false)
	rss, err := p.admin.AppsV1().ReplicaSets(p.ns).List(p.ctx, metav1.ListOptions{LabelSelector: Prefix + "incarnation-id=" + a.Target.IncarnationID})
	if err != nil || len(rss.Items) != 0 {
		t.Fatal("new reconciled suspended fixture unexpectedly produced ReplicaSet")
	}
	p.record("suspended_zero_controller_observation", map[string]any{"paused": true, "replicas": 0, "observed_generation": d.Status.ObservedGeneration, "pods": 0, "replica_sets": 0, "image": lifecycle.PauseImage})
	// First allow a ReplicaSet to exist with one harmless running Pod.
	d = p.get(t, a)
	d.Spec.Paused = false
	d.Spec.Replicas = Replicas(1)
	_, err = p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	p.podCount(t, a, 1, true)
	a = p.bind(t, a)
	// Pausing rollout does not suspend the existing ReplicaSet. Scaling while
	// paused must cause a second Pod to exist; merely inspecting spec is no proof.
	d = p.get(t, a)
	d.Spec.Paused = true
	d.Spec.Replicas = Replicas(2)
	_, err = p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	p.podCount(t, a, 2, true)
	a = p.bind(t, a)
	d = p.get(t, a)
	rss, err = p.admin.AppsV1().ReplicaSets(p.ns).List(p.ctx, metav1.ListOptions{LabelSelector: Prefix + "incarnation-id=" + a.Target.IncarnationID})
	if err != nil || len(rss.Items) != 1 || rss.Items[0].Spec.Replicas == nil || *rss.Items[0].Spec.Replicas != 2 {
		t.Fatal("paused Deployment did not scale expected ReplicaSet")
	}
	if !d.Spec.Paused || *d.Spec.Replicas != 2 {
		t.Fatal("pause fixture state mismatch")
	}
	p.record("paused_existing_ReplicaSet_scales", map[string]any{"paused": d.Spec.Paused, "desired_replicas": *d.Spec.Replicas, "replica_sets": len(rss.Items), "ReplicaSet_replicas": *rss.Items[0].Spec.Replicas, "running_pods": 2, "rollout_pause_is_suspension": false, "image": lifecycle.PauseImage})
	// A new zero-replica authorized fence is necessary to suppress existing Pods.
	// This admin operation is fixture cleanup, not an additional adapter action.
	d = p.get(t, a)
	d.Spec.Replicas = Replicas(0)
	_, err = p.admin.AppsV1().Deployments(p.ns).Update(p.ctx, d, metav1.UpdateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	p.podCount(t, a, 0, false)
	p.record("pause_fixture_zeroed", map[string]any{"pods": 0, "telephony_execution": false})
}
func (p *activeProof) replacement(t *testing.T) {
	a := p.fixture(t, "replacement")
	e := p.engine(t, p.client(t, 0), nil)
	uid := types.UID(*a.Target.ExpectedUID)
	err := p.admin.AppsV1().Deployments(p.ns).Delete(p.ctx, a.Target.Name, metav1.DeleteOptions{Preconditions: &metav1.Preconditions{UID: &uid}})
	if err != nil {
		t.Fatal(err)
	}
	err = wait.PollUntilContextTimeout(p.ctx, 50*time.Millisecond, 15*time.Second, true, func(ctx context.Context) (bool, error) {
		_, e := p.admin.AppsV1().Deployments(p.ns).Get(ctx, a.Target.Name, metav1.GetOptions{})
		return apierrors.IsNotFound(e), nil
	})
	if err != nil {
		t.Fatal("old target disappearance unconfirmed")
	}
	// Recreate a harmless inert object under the same name using fixture admin.
	// This is intentionally adversarial name reuse, never a production lifecycle.
	inert := p.inertFor(a)
	replacement, err := p.admin.AppsV1().Deployments(p.ns).Create(p.ctx, inert, metav1.CreateOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if replacement.UID == uid {
		t.Fatal("name reuse retained UID")
	}
	p.execute(t, e, a, "identity_mismatch")
	r := e.Recover(p.ctx, a)
	if r.Outcome != "identity_mismatch" {
		t.Fatalf("old intent recovered replacement %+v", r)
	}
	p.record("replacement_UID", map[string]any{"UID_changed": true, "old_activation": "identity_mismatch", "old_recovery": "identity_mismatch", "replacement_unchanged": p.get(t, a).Spec.Paused})
}
func (p *activeProof) inertFor(a Action) *appsv1.Deployment {
	q := lifecycle.Projection{TemplateVersion: "inert.pause.v1", Image: lifecycle.PauseImage, ConfigurationID: "config-inert", ConfigurationSHA256: strings.Repeat("a", 64)}
	b := lifecycle.Action{Version: lifecycle.Version, TenantID: a.TenantID, RuntimeNodeID: a.RuntimeNodeID, Target: a.Target, Generation: "1", Intent: lifecycle.Intent{Kind: "create_suspended", Projection: &q, ProjectionFingerprint: lifecycle.ProjectionFingerprint(q)}}
	b.Target.ExpectedUID = nil
	b.Target.ExpectedRV = nil
	b.Fingerprint = lifecycle.Fingerprint(b)
	return lifecycle.SuspendedDeployment(b)
}
func (p *activeProof) faultClient(t *testing.T, apply bool) (Client, *atomic.Int32, *atomic.Int32) {
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
		fault := r.Method == http.MethodPatch && dropped.CompareAndSwap(false, true)
		if r.Method == http.MethodPatch {
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
		if r.Method == http.MethodPatch {
			forwarded.Add(1)
		}
		if fault {
			if response.StatusCode < 200 || response.StatusCode >= 300 {
				t.Errorf("fault upstream rejected PATCH: %d", response.StatusCode)
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
	c, e := lifecycle.NewKubernetesClient(cfg, p.run, []string{p.ns})
	if e != nil {
		t.Fatal(e)
	}
	return c, received, forwarded
}
func (p *activeProof) unknown(t *testing.T, apply bool) {
	label := "unapplied"
	if apply {
		label = "applied"
	}
	a := p.fixture(t, label)
	c, received, forwarded := p.faultClient(t, apply)
	e := p.engine(t, c, nil)
	r := p.execute(t, e, a, "outcome_unknown")
	if r.Effect != "unknown" || r.Dispatch != "unknown" || r.Retry != "observe_first" {
		t.Fatalf("uncertainty falsely classified %+v", r)
	}
	if received.Load() != 1 || forwarded.Load() != map[bool]int32{true: 1, false: 0}[apply] {
		t.Fatal("blind replay or fault injection mismatch")
	}
	normal := p.engine(t, p.client(t, 0), nil)
	r = normal.Recover(p.ctx, a)
	if apply {
		if r.Outcome != "already_satisfied" || r.Effect == "unknown" {
			t.Fatalf("applied projection not recovered %+v", r)
		}
	} else {
		if r.Outcome == "already_satisfied" || r.Outcome == "success" {
			t.Fatalf("unapplied projection invented successful execution %+v", r)
		}
	}
	d := p.get(t, a)
	if (d.Annotations[AppliedIntent] == a.Fingerprint) != apply {
		t.Fatal("actual API state differs from injected application")
	}
	newer := activeNext(p.bind(t, a), "11", "config-newer")
	p.execute(t, normal, newer, "success")
	stale := normal.Recover(p.ctx, a)
	if stale.Outcome != "stale_generation" {
		t.Fatalf("old uncertain projection recovered after supersession %+v", stale)
	}
	p.record("unknown_projection_"+label, map[string]any{"mutation_applied": apply, "transport": "response interrupted", "initial": "outcome_unknown", "retry": "observe_first", "mutation_requests": received.Load(), "forwarded": forwarded.Load(), "recovered": r.Outcome, "newer_intent": "success", "old_recovery": "stale_generation"})
}
func (p *activeProof) rbac(t *testing.T) {
	scoped, e := kubernetes.NewForConfig(p.config(0))
	if e != nil {
		t.Fatal(e)
	}
	_, e = scoped.CoreV1().Secrets(p.ns).List(p.ctx, metav1.ListOptions{})
	p.status(t, "RBAC_Secret_read", e, 403)
	_, e = scoped.AppsV1().Deployments(p.other).Get(p.ctx, "anything", metav1.GetOptions{})
	p.status(t, "RBAC_cross_namespace_GET", e, 403)
	_, e = scoped.AppsV1().Deployments(p.ns).Patch(p.ctx, "unrelated", types.MergePatchType, []byte(`{"spec":{"replicas":0}}`), metav1.PatchOptions{})
	p.status(t, "RBAC_unrelated_PATCH", e, 403)
	name := "utcp-" + activeIncarnation("asterisk")
	e = scoped.AppsV1().Deployments(p.ns).Delete(p.ctx, name, metav1.DeleteOptions{})
	p.status(t, "RBAC_DELETE", e, 403)
	_, e = scoped.AppsV1().Deployments(p.ns).Create(p.ctx, &appsv1.Deployment{ObjectMeta: metav1.ObjectMeta{Name: "unauthorized"}}, metav1.CreateOptions{})
	p.status(t, "RBAC_CREATE", e, 403)
	_, e = scoped.AppsV1().Deployments(p.ns).Patch(p.ctx, name, types.MergePatchType, []byte(`{"spec":{"replicas":0}}`), metav1.PatchOptions{}, "scale")
	p.status(t, "RBAC_scale_PATCH", e, 403)
	_, e = scoped.CoreV1().Nodes().Patch(p.ctx, "unrelated", types.MergePatchType, []byte(`{"metadata":{"labels":{"unsafe":"true"}}}`), metav1.PatchOptions{})
	p.status(t, "RBAC_Node_PATCH", e, 403)
}
