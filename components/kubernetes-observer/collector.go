package observer

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"sort"
	"time"

	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/util/validation"
)

// Reader deliberately exposes only the two read operations this component needs.
type Reader interface {
	ListNodes(context.Context, metav1.ListOptions) (*corev1.NodeList, error)
	ListPods(context.Context, string, metav1.ListOptions) (*corev1.PodList, error)
}
type Collector struct {
	Reader           Reader
	ClusterID        string
	Namespace        string // "*" is an explicitly configured cluster-wide scope.
	PageSize         int64
	MaxPages         int
	MaxObjects       int
	MaxResponseBytes int
	Now              func() time.Time
}

var errInvalidList = errors.New("invalid collection")

func (c Collector) clock() time.Time {
	if c.Now != nil {
		return c.Now().UTC()
	}
	return time.Now().UTC()
}
func status(now time.Time) ListStatus { return ListStatus{Started: now, Finished: now} }
func Failure(q Request, now time.Time, code string) Snapshot {
	retry := "backoff"
	if code == "invalid_request" || code == "forbidden" || code == "unauthorized" || code == "terminal_failure" {
		retry = "never"
	}
	return Snapshot{Version: Version, RequestID: q.RequestID, ClusterID: q.ClusterID, Started: now, Finished: now, NodesStatus: status(now), PodsStatus: status(now), Error: &ObservationError{code, retry, "not_sent"}}
}
func classify(err error) string {
	switch {
	case errors.Is(err, context.Canceled):
		return "cancelled"
	case errors.Is(err, context.DeadlineExceeded), apierrors.IsTimeout(err), apierrors.IsServerTimeout(err):
		return "timeout"
	case apierrors.IsUnauthorized(err):
		return "unauthorized"
	case apierrors.IsForbidden(err):
		return "forbidden"
	case apierrors.IsNotFound(err):
		return "target_not_found"
	case errors.Is(err, errInvalidList), apierrors.IsBadRequest(err), apierrors.IsInvalid(err):
		return "terminal_failure"
	case apierrors.IsTooManyRequests(err), apierrors.IsServiceUnavailable(err), apierrors.IsInternalError(err), apierrors.IsResourceExpired(err), apierrors.IsGone(err):
		return "retryable_failure"
	}
	var n net.Error
	if errors.As(err, &n) && n.Timeout() {
		return "timeout"
	}
	return "transport_unavailable"
}

// Observe restarts BOTH collections once on expired continuation. No abandoned
// Node/Pod facts or resource versions survive a restart; no cross-list atomicity.
func (c Collector) Observe(ctx context.Context, q Request) Snapshot {
	if q.Version != Version || q.ClusterID != c.ClusterID || len(q.Scope.PodNamespaces) != 1 || q.Scope.PodNamespaces[0] != c.Namespace || c.Reader == nil {
		return Failure(q, c.clock(), "invalid_request")
	}
	deadline := time.Now().Add(5 * time.Second)
	if q.Deadline.Before(deadline) {
		deadline = q.Deadline
	}
	ctx, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	for attempt := 0; attempt < 2; attempt++ {
		s, err := c.acquire(ctx, q)
		if err == nil {
			return s
		}
		if (apierrors.IsResourceExpired(err) || apierrors.IsGone(err)) && attempt == 0 && ctx.Err() == nil {
			continue
		}
		if ctx.Err() != nil {
			err = ctx.Err()
		}
		s.Complete = false
		s.Nodes = nil
		s.Pods = nil
		s.Finished = c.clock()
		f := Failure(q, s.Finished, classify(err))
		s.Error = f.Error
		s.Error.Dispatch = "rejected"
		return s
	}
	panic("bounded acquisition unreachable")
}
func (c Collector) acquire(ctx context.Context, q Request) (Snapshot, error) {
	now := c.clock()
	s := Snapshot{Version: Version, RequestID: q.RequestID, ClusterID: c.ClusterID, Started: now, NodesStatus: status(now), PodsStatus: status(now)}
	limit := c.PageSize
	if limit <= 0 || limit > 500 {
		limit = 500
	}
	pages := c.MaxPages
	if pages <= 0 || pages > 1000 {
		pages = 1000
	}
	objects := c.MaxObjects
	if objects <= 0 || objects > 100000 {
		objects = 100000
	}
	bytesLimit := c.MaxResponseBytes
	if bytesLimit <= 0 || bytesLimit > 16<<20 {
		bytesLimit = 16 << 20
	}
	nodes := []NodeFact{}
	pods := []PodFact{}
	projectedBytes := 0
	addBytes := func(v any) error {
		b, e := json.Marshal(v)
		if e != nil {
			return errInvalidList
		}
		projectedBytes += len(b)
		if projectedBytes > bytesLimit {
			return errInvalidList
		}
		return nil
	}
	uidSeen := map[string]bool{}
	nameSeen := map[string]bool{}
	validate := func(kind, name, ns, uid, rv string) error {
		key := kind + "/" + ns + "/" + name
		if len(validation.IsDNS1123Subdomain(name)) > 0 || uid == "" || rv == "" || uidSeen[uid] || nameSeen[key] || len(uidSeen) >= objects {
			return errInvalidList
		}
		uidSeen[uid] = true
		nameSeen[key] = true
		return nil
	}
	token, rv := "", ""
	tokens := map[string]bool{}
	for {
		if err := ctx.Err(); err != nil {
			return s, err
		}
		list, err := c.Reader.ListNodes(ctx, metav1.ListOptions{Limit: limit, Continue: token})
		s.NodesStatus.Finished = c.clock()
		if err != nil {
			return s, err
		}
		s.NodesStatus.Pages++
		if list == nil || list.ResourceVersion == "" || (rv != "" && rv != list.ResourceVersion) {
			return s, errInvalidList
		}
		rv = list.ResourceVersion
		for _, n := range list.Items {
			if err = validate("Node", n.Name, "", string(n.UID), n.ResourceVersion); err != nil {
				return s, err
			}
		}
		page, _ := Normalize(list.Items, nil)
		if err = addBytes(page); err != nil {
			return s, err
		}
		nodes = append(nodes, page...)
		token = list.Continue
		if token == "" {
			break
		}
		if tokens[token] || s.NodesStatus.Pages >= pages {
			return s, errInvalidList
		}
		tokens[token] = true
	}
	s.NodesStatus.Complete = true
	s.NodesStatus.RV = ptr(rv)
	s.PodsStatus.Started = c.clock()
	s.PodsStatus.Finished = s.PodsStatus.Started
	token, rv = "", ""
	tokens = map[string]bool{}
	ns := c.Namespace
	if ns == "*" {
		ns = ""
	}
	for {
		if err := ctx.Err(); err != nil {
			return s, err
		}
		list, err := c.Reader.ListPods(ctx, ns, metav1.ListOptions{Limit: limit, Continue: token})
		s.PodsStatus.Finished = c.clock()
		if err != nil {
			return s, err
		}
		s.PodsStatus.Pages++
		if list == nil || list.ResourceVersion == "" || (rv != "" && rv != list.ResourceVersion) {
			return s, errInvalidList
		}
		rv = list.ResourceVersion
		for _, p := range list.Items {
			if len(validation.IsDNS1123Subdomain(p.Namespace)) > 0 || (ns != "" && p.Namespace != ns) {
				return s, errInvalidList
			}
			if err = validate("Pod", p.Name, p.Namespace, string(p.UID), p.ResourceVersion); err != nil {
				return s, err
			}
		}
		_, page := Normalize(nil, list.Items)
		if err = addBytes(page); err != nil {
			return s, err
		}
		pods = append(pods, page...)
		token = list.Continue
		if token == "" {
			break
		}
		if tokens[token] || s.PodsStatus.Pages >= pages {
			return s, errInvalidList
		}
		tokens[token] = true
	}
	s.PodsStatus.Complete = true
	s.PodsStatus.RV = ptr(rv)
	sort.Slice(nodes, func(i, j int) bool {
		if nodes[i].Name == nodes[j].Name {
			return nodes[i].UID < nodes[j].UID
		}
		return nodes[i].Name < nodes[j].Name
	})
	sort.Slice(pods, func(i, j int) bool {
		if pods[i].Namespace != pods[j].Namespace {
			return pods[i].Namespace < pods[j].Namespace
		}
		if pods[i].Name != pods[j].Name {
			return pods[i].Name < pods[j].Name
		}
		return pods[i].UID < pods[j].UID
	})
	s.Nodes, s.Pods = nodes, pods
	s.Complete = true
	s.Finished = c.clock()
	data, err := json.Marshal(s)
	if err != nil || len(data) > bytesLimit {
		return s, errInvalidList
	}
	// Validate normalized facts using the unchanged normative schema at assembly
	// in NewObserver; the collector also rejects malformed identities above.
	return s, nil
}

// Fresh never refreshes timestamps on receipt or repeat delivery. PHP remains
// responsible for canonical per-scope ordering and retaining last-known facts.
func Fresh(s Snapshot, now time.Time) bool {
	if !s.Complete || s.Error != nil || s.Finished.Before(s.Started) || now.Before(s.Finished) || now.Sub(s.Started) > 300*time.Second {
		return false
	}
	for _, st := range []ListStatus{s.NodesStatus, s.PodsStatus} {
		if !st.Complete || st.Started.Before(s.Started) || st.Finished.Before(st.Started) || st.Finished.After(s.Finished) {
			return false
		}
	}
	return true
}
func invalid(detail string) error { return fmt.Errorf("%w: %s", errInvalidList, detail) }
