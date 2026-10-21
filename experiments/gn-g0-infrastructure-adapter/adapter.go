// Package spike is an isolated contract experiment. It is not an RPC service.
package spike

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math/big"
	"sort"
	"strconv"
	"time"

	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/kubernetes"
)

const Version = "utcp.infrastructure.v1"
const prefix = "infrastructure.utcp.io/"
const admitted = prefix + "admitted-generation"
const admittedHash = prefix + "admitted-intent"
const applied = prefix + "applied-generation"
const appliedHash = prefix + "applied-intent"

type Target struct {
	ClusterID       string `json:"cluster_id"`
	RuntimeNodeSlug string `json:"runtime_node_slug"`
	Namespace       string `json:"namespace"`
	Kind            string `json:"kind"`
	Name            string `json:"name"`
	TargetID        string `json:"target_id"`
	IncarnationID   string `json:"incarnation_id"`
	ExpectedUID     string `json:"expected_uid"`
	ExpectedRV      string `json:"expected_resource_version"`
}
type Claim struct {
	Attempt int       `json:"attempt"`
	Token   string    `json:"fencing_token"`
	Expires time.Time `json:"lease_expires_at"`
}
type Action struct {
	Version        string    `json:"contract_version"`
	RequestID      string    `json:"request_id"`
	OperationID    string    `json:"operation_id"`
	TenantID       string    `json:"tenant_id"`
	RuntimeNodeID  string    `json:"runtime_node_id"`
	Target         Target    `json:"target"`
	Generation     string    `json:"target_generation"`
	Fingerprint    string    `json:"intent_fingerprint"`
	Action         string    `json:"action"`
	Replicas       int32     `json:"replicas"`
	IdempotencyKey string    `json:"idempotency_key"`
	Claim          Claim     `json:"claim"`
	Deadline       time.Time `json:"deadline"`
	CorrelationID  string    `json:"correlation_id"`
	AuditID        string    `json:"audit_id"`
}
type Result struct {
	Version     string    `json:"contract_version"`
	RequestID   string    `json:"request_id"`
	OperationID string    `json:"operation_id"`
	Outcome     string    `json:"outcome"`
	Effect      string    `json:"effect"`
	Retry       string    `json:"retry"`
	Dispatch    string    `json:"dispatch"`
	UID         *string   `json:"target_uid"`
	RV          *string   `json:"resource_version"`
	Admitted    *string   `json:"admitted_generation"`
	Applied     *string   `json:"applied_generation"`
	Finished    time.Time `json:"finished_at"`
}

func ptr(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}
func Fingerprint(a Action) string {
	t := a.Target
	fields := []string{Version, a.TenantID, a.RuntimeNodeID, t.ClusterID, t.Namespace, t.Kind, t.Name, t.RuntimeNodeSlug, t.TargetID, t.IncarnationID, t.ExpectedUID, a.Generation, "set_replicas", strconv.Itoa(int(a.Replicas))}
	h := sha256.New()
	for _, s := range fields {
		fmt.Fprintf(h, "%d:%s", len([]byte(s)), s)
	}
	return hex.EncodeToString(h.Sum(nil))
}

// Authorize must bind the entire Action to the current PG claim/intent. A true
// result cannot retract a request after dispatch. No PG implementation is here.
type Authorize func(context.Context, Action) bool
type Adapter struct {
	Client    kubernetes.Interface
	ClusterID string
	Authorize Authorize
	Now       func() time.Time
}

func (a Adapter) Execute(ctx context.Context, q Action) Result {
	now := a.Now
	if now == nil {
		now = time.Now
	}
	r := Result{Version: Version, RequestID: q.RequestID, OperationID: q.OperationID, Outcome: "invalid_request", Effect: "none", Retry: "never", Dispatch: "not_sent", Finished: now().UTC()}
	finish := func(code string) Result { r.Outcome = code; r.Finished = now().UTC(); return r }
	generation, ok := new(big.Int).SetString(q.Generation, 10)
	if q.Version != Version || !ok || generation.Sign() <= 0 || generation.Cmp(big.NewInt(9223372036854775807)) > 0 || len(q.Generation) > 19 || q.Generation[0] == '0' || q.Target.Kind != "Deployment" || q.Target.Namespace != "utcp-runtime" || q.Target.RuntimeNodeSlug == "" || q.Target.ExpectedUID == "" || q.Target.ExpectedRV == "" || q.Replicas < 0 || q.Replicas > 100 || q.Fingerprint != Fingerprint(q) || (q.Action != "admit_generation" && q.Action != "set_replicas") {
		return finish("invalid_request")
	}
	if q.Target.ClusterID != a.ClusterID {
		return finish("identity_mismatch")
	}
	if !now().Before(q.Deadline) || !now().Before(q.Claim.Expires) {
		return finish("stale_generation")
	}
	ctx, cancel := context.WithDeadline(ctx, q.Deadline)
	defer cancel()
	if err := ctx.Err(); err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return finish("timeout")
		}
		return finish("cancelled")
	}
	if a.Authorize == nil || !a.Authorize(ctx, q) {
		return finish("unauthorized")
	}
	d, err := a.Client.AppsV1().Deployments(q.Target.Namespace).Get(ctx, q.Target.Name, metav1.GetOptions{})
	if err != nil {
		r.Retry = "backoff"
		if apierrors.IsNotFound(err) {
			r.Retry = "never"
			return finish("target_not_found")
		}
		if apierrors.IsForbidden(err) {
			r.Retry = "never"
			return finish("forbidden")
		}
		if apierrors.IsUnauthorized(err) {
			r.Retry = "never"
			return finish("unauthorized")
		}
		if errors.Is(err, context.DeadlineExceeded) {
			return finish("timeout")
		}
		if errors.Is(err, context.Canceled) {
			return finish("cancelled")
		}
		return finish("transport_unavailable")
	}
	r.UID = ptr(string(d.UID))
	r.RV = ptr(d.ResourceVersion)
	r.Admitted = ptr(d.Annotations[admitted])
	r.Applied = ptr(d.Annotations[applied])
	if string(d.UID) != q.Target.ExpectedUID {
		return finish("identity_mismatch")
	}
	expected := map[string]string{"app.kubernetes.io/part-of": "utcp", "app.kubernetes.io/instance": q.Target.Name, "utcp.dev/runtime-node": q.Target.RuntimeNodeSlug, "utcp.io/runtime-node-id": q.RuntimeNodeID, "utcp.io/tenant-id": q.TenantID, "infrastructure.utcp.io/target-id": q.Target.TargetID, "infrastructure.utcp.io/incarnation-id": q.Target.IncarnationID}
	for k, v := range expected {
		if d.Labels[k] != v {
			return finish("ownership_conflict")
		}
	}
	observed := d.Annotations[admitted]
	if observed != "" {
		g, valid := new(big.Int).SetString(observed, 10)
		if !valid || g.Sign() <= 0 {
			return finish("ownership_conflict")
		}
		if g.Cmp(generation) > 0 {
			return finish("stale_generation")
		}
		if g.Cmp(generation) == 0 && d.Annotations[admittedHash] != q.Fingerprint {
			return finish("ownership_conflict")
		}
	}
	if observed == q.Generation && d.Annotations[admittedHash] == q.Fingerprint {
		if q.Action == "admit_generation" {
			r.Dispatch = "response_received"
			r.Effect = "admitted"
			return finish("already_satisfied")
		}
		if d.Annotations[applied] == q.Generation && d.Annotations[appliedHash] == q.Fingerprint && d.Spec.Replicas != nil && *d.Spec.Replicas == q.Replicas {
			r.Dispatch = "response_received"
			r.Effect = "observed_satisfied"
			return finish("already_satisfied")
		}
	}
	if d.ResourceVersion != q.Target.ExpectedRV {
		r.Retry = "reauthorize"
		return finish("resource_version_conflict")
	}
	if q.Action == "set_replicas" && (observed != q.Generation || d.Annotations[admittedHash] != q.Fingerprint) {
		return finish("stale_generation")
	}
	// Revalidate immediately before sending; it is still not an atomic PG/K8s fence.
	if !now().Before(q.Claim.Expires) || !a.Authorize(ctx, q) {
		return finish("stale_generation")
	}
	if err := ctx.Err(); err != nil {
		return finish("cancelled")
	}
	type op struct {
		Op    string `json:"op"`
		Path  string `json:"path"`
		Value any    `json:"value"`
	}
	ops := []op{{"test", "/metadata/uid", q.Target.ExpectedUID}, {"test", "/metadata/resourceVersion", q.Target.ExpectedRV}}
	path := func(k string) string { return "/metadata/annotations/infrastructure.utcp.io~1" + k }
	if q.Action == "admit_generation" {
		if d.Annotations == nil {
			ops = append(ops, op{"add", "/metadata/annotations", map[string]string{}})
		}
		ops = append(ops, op{"add", path("admitted-generation"), q.Generation}, op{"add", path("admitted-intent"), q.Fingerprint})
	} else {
		ops = append(ops, op{"test", path("admitted-generation"), q.Generation}, op{"test", path("admitted-intent"), q.Fingerprint}, op{"add", "/spec/replicas", q.Replicas}, op{"add", path("applied-generation"), q.Generation}, op{"add", path("applied-intent"), q.Fingerprint})
	}
	body, _ := json.Marshal(ops)
	updated, err := a.Client.AppsV1().Deployments(q.Target.Namespace).Patch(ctx, q.Target.Name, types.JSONPatchType, body, metav1.PatchOptions{})
	if err != nil {
		// Only an explicit API rejection establishes nonapplication; transport loss
		// and ambiguous server errors after dispatch require inspection first.
		if apierrors.IsConflict(err) {
			r.Dispatch = "rejected"
			r.Retry = "reauthorize"
			return finish("resource_version_conflict")
		}
		if apierrors.IsInvalid(err) {
			r.Dispatch = "rejected"
			// 422 alone does not prove a CAS failure. Re-observe to establish
			// a changed prepared identity/version, otherwise retain validation.
			observed, readErr := a.Client.AppsV1().Deployments(q.Target.Namespace).Get(ctx, q.Target.Name, metav1.GetOptions{})
			if readErr == nil && string(observed.UID) != q.Target.ExpectedUID {
				return finish("identity_mismatch")
			}
			if readErr == nil && observed.ResourceVersion != q.Target.ExpectedRV {
				r.Retry = "reauthorize"
				return finish("resource_version_conflict")
			}
			return finish("invalid_request")
		}
		if apierrors.IsForbidden(err) {
			r.Dispatch = "rejected"
			return finish("forbidden")
		}
		if apierrors.IsUnauthorized(err) {
			r.Dispatch = "rejected"
			return finish("unauthorized")
		}
		if apierrors.IsNotFound(err) {
			r.Dispatch = "rejected"
			return finish("target_not_found")
		}
		r.Dispatch = "unknown"
		r.Effect = "unknown"
		r.Retry = "observe_first"
		return finish("outcome_unknown")
	}
	r.Dispatch = "response_received"
	r.UID = ptr(string(updated.UID))
	r.RV = ptr(updated.ResourceVersion)
	r.Admitted = ptr(updated.Annotations[admitted])
	r.Applied = ptr(updated.Annotations[applied])
	r.Effect = "admitted"
	if q.Action == "set_replicas" {
		r.Effect = "applied"
	}
	return finish("success")
}

type Topology struct {
	Region   *string `json:"region"`
	Zone     *string `json:"zone"`
	Hostname *string `json:"hostname"`
}
type ConditionFact struct {
	Type   string  `json:"type"`
	Status string  `json:"status"`
	Reason *string `json:"reason"`
}
type NodeFact struct {
	UID           string               `json:"uid"`
	Name          string               `json:"name"`
	RV            string               `json:"resource_version"`
	Ready         string               `json:"ready"`
	Unschedulable bool                 `json:"unschedulable"`
	Topology      Topology             `json:"topology"`
	Conditions    []ConditionFact      `json:"conditions"`
	Addresses     []corev1.NodeAddress `json:"addresses"`
	Capacity      corev1.ResourceList  `json:"capacity"`
	Allocatable   corev1.ResourceList  `json:"allocatable"`
	Labels        map[string]string    `json:"labels"`
	Taints        []corev1.Taint       `json:"taints"`
}
type Owner struct {
	Kind string `json:"kind"`
	Name string `json:"name"`
	UID  string `json:"uid"`
}
type PodFact struct {
	UID        string            `json:"uid"`
	Name       string            `json:"name"`
	Namespace  string            `json:"namespace"`
	RV         string            `json:"resource_version"`
	NodeName   *string           `json:"node_name"`
	Phase      string            `json:"phase"`
	Ready      string            `json:"ready"`
	Labels     map[string]string `json:"labels"`
	Controller *Owner            `json:"controller"`
	Chain      string            `json:"ownership_chain"`
}
type ListStatus struct {
	Complete bool      `json:"complete"`
	RV       *string   `json:"resource_version"`
	Pages    int       `json:"pages"`
	Started  time.Time `json:"started_at"`
	Finished time.Time `json:"finished_at"`
}
type ObservationError struct {
	Code     string `json:"code"`
	Retry    string `json:"retry"`
	Dispatch string `json:"dispatch"`
}
type Snapshot struct {
	Version     string            `json:"contract_version"`
	RequestID   string            `json:"request_id"`
	ClusterID   string            `json:"cluster_id"`
	Complete    bool              `json:"complete"`
	Started     time.Time         `json:"started_at"`
	Finished    time.Time         `json:"finished_at"`
	NodesStatus ListStatus        `json:"nodes_status"`
	PodsStatus  ListStatus        `json:"pods_status"`
	Nodes       []NodeFact        `json:"nodes"`
	Pods        []PodFact         `json:"pods"`
	Error       *ObservationError `json:"error"`
}

func Normalize(nodes []corev1.Node, pods []corev1.Pod) ([]NodeFact, []PodFact) {
	ns := make([]NodeFact, 0, len(nodes))
	ps := make([]PodFact, 0, len(pods))
	for _, n := range nodes {
		ready := "Unknown"
		conditions := []ConditionFact{}
		for _, c := range n.Status.Conditions {
			if c.Type == corev1.NodeReady {
				ready = string(c.Status)
			}
			conditions = append(conditions, ConditionFact{string(c.Type), string(c.Status), ptr(c.Reason)})
		}
		sort.Slice(conditions, func(i, j int) bool { return conditions[i].Type < conditions[j].Type })
		addresses := append([]corev1.NodeAddress{}, n.Status.Addresses...)
		sort.Slice(addresses, func(i, j int) bool {
			return string(addresses[i].Type)+"|"+addresses[i].Address < string(addresses[j].Type)+"|"+addresses[j].Address
		})
		capacity, allocatable := n.Status.Capacity, n.Status.Allocatable
		if capacity == nil {
			capacity = corev1.ResourceList{}
		}
		if allocatable == nil {
			allocatable = corev1.ResourceList{}
		}
		labels := map[string]string{}
		for _, k := range []string{"kubernetes.io/hostname", "kubernetes.io/os", "kubernetes.io/arch", "topology.kubernetes.io/region", "topology.kubernetes.io/zone"} {
			if v, ok := n.Labels[k]; ok {
				labels[k] = v
			}
		}
		taints := append([]corev1.Taint{}, n.Spec.Taints...)
		sort.Slice(taints, func(i, j int) bool {
			return taints[i].Key+"|"+string(taints[i].Effect)+"|"+taints[i].Value < taints[j].Key+"|"+string(taints[j].Effect)+"|"+taints[j].Value
		})
		ns = append(ns, NodeFact{UID: string(n.UID), Name: n.Name, RV: n.ResourceVersion, Ready: ready, Unschedulable: n.Spec.Unschedulable, Topology: Topology{ptr(n.Labels["topology.kubernetes.io/region"]), ptr(n.Labels["topology.kubernetes.io/zone"]), ptr(n.Labels["kubernetes.io/hostname"])}, Conditions: conditions, Addresses: addresses, Capacity: capacity, Allocatable: allocatable, Labels: labels, Taints: taints})
	}
	for _, p := range pods {
		ready := "Unknown"
		for _, c := range p.Status.Conditions {
			if c.Type == corev1.PodReady {
				ready = string(c.Status)
			}
		}
		labels := map[string]string{}
		for _, k := range []string{"app.kubernetes.io/part-of", "app.kubernetes.io/instance", "app.kubernetes.io/component", "utcp.dev/runtime-node", "utcp.io/runtime-node-id", "utcp.io/tenant-id"} {
			if v, ok := p.Labels[k]; ok {
				labels[k] = v
			}
		}
		var owner *Owner
		for _, o := range p.OwnerReferences {
			if o.Controller != nil && *o.Controller {
				kind := o.Kind
				if kind != "ReplicaSet" && kind != "Deployment" {
					kind = "Other"
				}
				owner = &Owner{kind, o.Name, string(o.UID)}
				break
			}
		}
		phase := string(p.Status.Phase)
		if phase == "" {
			phase = "Unknown"
		}
		ps = append(ps, PodFact{string(p.UID), p.Name, p.Namespace, p.ResourceVersion, ptr(p.Spec.NodeName), phase, ready, labels, owner, "unverified"})
	}
	sort.Slice(ns, func(i, j int) bool {
		if ns[i].Name == ns[j].Name {
			return ns[i].UID < ns[j].UID
		}
		return ns[i].Name < ns[j].Name
	})
	sort.Slice(ps, func(i, j int) bool {
		a, b := ps[i], ps[j]
		if a.Namespace != b.Namespace {
			return a.Namespace < b.Namespace
		}
		if a.Name != b.Name {
			return a.Name < b.Name
		}
		return a.UID < b.UID
	})
	return ns, ps
}

// Observe acquires one complete Node list and one complete namespaced Pod list.
// HTTP simulation tests exercise continuation; fake clients alone cannot prove it.
func Observe(ctx context.Context, client kubernetes.Interface, requestID, clusterID, namespace string) Snapshot {
	now := func() time.Time { return time.Now().UTC() }
	s := Snapshot{Version: Version, RequestID: requestID, ClusterID: clusterID, Started: now()}
	s.NodesStatus.Started = s.Started
	s.PodsStatus.Started = s.Started
	fail := func(err error) Snapshot {
		code, retry := "retryable_failure", "backoff"
		if apierrors.IsForbidden(err) {
			code, retry = "forbidden", "never"
		}
		if apierrors.IsUnauthorized(err) {
			code, retry = "unauthorized", "never"
		}
		if errors.Is(err, context.DeadlineExceeded) {
			code = "timeout"
		}
		if errors.Is(err, context.Canceled) {
			code = "cancelled"
		}
		s.Error = &ObservationError{code, retry, "rejected"}
		s.Nodes = nil
		s.Pods = nil
		s.Finished = now()
		s.NodesStatus.Finished = s.Finished
		s.PodsStatus.Finished = s.Finished
		return s
	}
	var nodes []corev1.Node
	var pods []corev1.Pod
	token, rv := "", ""
	seen := map[string]bool{}
	for {
		list, err := client.CoreV1().Nodes().List(ctx, metav1.ListOptions{Limit: 2, Continue: token})
		if err != nil {
			return fail(err)
		}
		s.NodesStatus.Pages++
		if list.ResourceVersion == "" || (rv != "" && rv != list.ResourceVersion) {
			return fail(fmt.Errorf("inconsistent list"))
		}
		rv = list.ResourceVersion
		nodes = append(nodes, list.Items...)
		token = list.Continue
		if token == "" {
			break
		}
		if seen[token] || s.NodesStatus.Pages >= 1000 {
			return fail(fmt.Errorf("pagination bound"))
		}
		seen[token] = true
	}
	s.NodesStatus.Complete = true
	s.NodesStatus.RV = ptr(rv)
	s.NodesStatus.Finished = now()
	s.PodsStatus.Started = now()
	token, rv = "", ""
	seen = map[string]bool{}
	for {
		list, err := client.CoreV1().Pods(namespace).List(ctx, metav1.ListOptions{Limit: 2, Continue: token})
		if err != nil {
			return fail(err)
		}
		s.PodsStatus.Pages++
		if list.ResourceVersion == "" || (rv != "" && rv != list.ResourceVersion) {
			return fail(fmt.Errorf("inconsistent list"))
		}
		rv = list.ResourceVersion
		pods = append(pods, list.Items...)
		token = list.Continue
		if token == "" {
			break
		}
		if seen[token] || s.PodsStatus.Pages >= 1000 {
			return fail(fmt.Errorf("pagination bound"))
		}
		seen[token] = true
	}
	s.PodsStatus.Complete = true
	s.PodsStatus.RV = ptr(rv)
	s.PodsStatus.Finished = now()
	seenUID, seenName := map[string]bool{}, map[string]bool{}
	if len(nodes)+len(pods) > 100000 {
		return fail(fmt.Errorf("object count bound"))
	}
	for _, n := range nodes {
		if n.UID == "" || n.Name == "" || n.ResourceVersion == "" || seenUID[string(n.UID)] || seenName[n.Name] {
			return fail(fmt.Errorf("invalid node identity"))
		}
		seenUID[string(n.UID)] = true
		seenName[n.Name] = true
	}
	seenName = map[string]bool{}
	for _, p := range pods {
		key := p.Namespace + "/" + p.Name
		if p.UID == "" || p.Name == "" || p.Namespace == "" || p.ResourceVersion == "" || (namespace != "" && p.Namespace != namespace) || seenUID[string(p.UID)] || seenName[key] {
			return fail(fmt.Errorf("invalid pod identity"))
		}
		seenUID[string(p.UID)] = true
		seenName[key] = true
	}
	s.Nodes, s.Pods = Normalize(nodes, pods)
	projected, _ := json.Marshal(s)
	if len(projected) > 16*1024*1024 {
		return fail(fmt.Errorf("response size bound"))
	}
	s.Complete = true
	s.Finished = now()
	return s
}
