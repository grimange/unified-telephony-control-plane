// Package observer provides an isolated, undeployed, read-only Kubernetes observation component.
package observer

import (
	corev1 "k8s.io/api/core/v1"
	"sort"
	"time"
)

const Version = "utcp.infrastructure.v1"

type Scope struct {
	PodNamespaces []string `json:"pod_namespaces"`
}
type Request struct {
	Version   string    `json:"contract_version"`
	RequestID string    `json:"request_id"`
	ClusterID string    `json:"cluster_id"`
	Scope     Scope     `json:"scope"`
	Deadline  time.Time `json:"deadline"`
}

func ptr(s string) *string {
	if s == "" {
		return nil
	}
	return &s
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
