package observer

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"k8s.io/client-go/util/flowcontrol"
)

func TestNormativeObservationFixtures(t *testing.T) {
	v := validator(t)
	b, err := os.ReadFile(filepath.Join(contractDir, "fixtures/manifest.json"))
	if err != nil {
		t.Fatal(err)
	}
	var manifest []struct {
		File, Schema string
		Valid        bool
	}
	if err = json.Unmarshal(b, &manifest); err != nil {
		t.Fatal(err)
	}
	for _, entry := range manifest {
		if entry.Schema != "observation.schema.json" && entry.Schema != "observation-request.schema.json" {
			continue
		}
		t.Run(entry.File, func(t *testing.T) {
			b, e := os.ReadFile(filepath.Join(contractDir, "fixtures", entry.File))
			if e != nil {
				t.Fatal(e)
			}
			s := v.Response
			if entry.Schema == "observation-request.schema.json" {
				s = v.Request
			}
			if (validateJSON(s, b) == nil) != entry.Valid {
				t.Fatal("normative fixture compatibility changed")
			}
		})
	}
}

func TestEndToEndMTLSObservation(t *testing.T) {
	n, p := fixtureObjects(7)
	upstream := &protocolServer{t: t, nodes: n, pods: p}
	reader, _ := readerServer(t, upstream)
	v := validator(t)
	observe, err := NewObserver(Collector{Reader: reader, ClusterID: "cluster-fixture", Namespace: "*", PageSize: 2}, v)
	if err != nil {
		t.Fatal(err)
	}
	h, err := NewHandler(TransportConfig{ClusterID: "cluster-fixture", AllowedNamespaces: []string{"*"}, ClientIdentity: transportIdentity, Observe: observe, ValidateRequest: v.ValidateRequest, ValidateResponse: v.ValidateSnapshot})
	if err != nil {
		t.Fatal(err)
	}
	pki := makeTransportPKI(t)
	tlsMaterial, err := NewServerTLS(pki.server, pki.serverKey, pki.ca, transportIdentity)
	if err != nil {
		t.Fatal(err)
	}
	server := transportServer(t, h, tlsMaterial)
	client, err := NewHTTPClient(pki.ca, pki.client, pki.clientKey, "observer.test")
	if err != nil {
		t.Fatal(err)
	}
	defer client.CloseIdleConnections()
	b, _ := json.Marshal(request())
	response, err := transportCall(t, client.Client, server.URL, b)
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	if response.StatusCode != 200 {
		t.Fatal(response.StatusCode)
	}
	var snapshot Snapshot
	if err = json.NewDecoder(response.Body).Decode(&snapshot); err != nil {
		t.Fatal(err)
	}
	if v.ValidateSnapshot(snapshot) != nil || !snapshot.Complete || len(snapshot.Pods) != 7 || upstream.requests.Load() != 6 {
		t.Fatalf("incomplete endpoint integration %+v", snapshot)
	}
}

func TestMissingOrNullItemsIsNotEmpty(t *testing.T) {
	for _, kind := range []string{"Node", "Pod"} {
		for _, items := range []string{"", `,"items":null`} {
			t.Run(kind+items, func(t *testing.T) {
				n, p := fixtureObjects(0)
				server := &protocolServer{t: t, nodes: n, pods: p}
				server.hook = func(w http.ResponseWriter, r *http.Request, _ int64) bool {
					if (kind == "Node" && strings.HasSuffix(r.URL.Path, "nodes")) || (kind == "Pod" && strings.HasSuffix(r.URL.Path, "pods")) {
						w.Header().Set("Content-Type", "application/json")
						fmt.Fprintf(w, `{"apiVersion":"v1","kind":"%sList","metadata":{"resourceVersion":"1"}%s}`, kind, items)
						return true
					}
					return false
				}
				reader, _ := readerServer(t, server)
				s := observe(t, Collector{Reader: reader, ClusterID: "cluster-fixture", Namespace: "*"}, request())
				if s.Complete || s.Error == nil || s.Error.Code != "terminal_failure" || s.Nodes != nil || s.Pods != nil {
					t.Fatal("malformed collection became healthy empty")
				}
			})
		}
	}
}

func TestRateBudgetTimeoutAndTransportFailure(t *testing.T) {
	limiter := deadlineLimiter{flowcontrol.NewTokenBucketRateLimiter(5, 10)}
	ctx, cancel := context.WithTimeout(context.Background(), time.Millisecond)
	defer cancel()
	for i := 0; i < 10; i++ {
		if err := limiter.Wait(ctx); err != nil {
			t.Fatal(err)
		}
	}
	if code := classify(limiter.Wait(ctx)); code != "timeout" {
		t.Fatalf("budget rejection got %s", code)
	}
	if code := classify(&net.DNSError{Err: "fixture lookup failed", Name: "unresolvable.invalid", IsNotFound: true}); code != "transport_unavailable" {
		t.Fatal(code)
	}
	n, p := fixtureObjects(0)
	reader, server := readerServer(t, &protocolServer{t: t, nodes: n, pods: p})
	server.Close()
	s := observe(t, Collector{Reader: reader, ClusterID: "cluster-fixture", Namespace: "*"}, request())
	if s.Complete || s.Error.Code != "transport_unavailable" || s.Nodes != nil || s.Pods != nil {
		t.Fatal("transport failure became facts")
	}
}

func TestNamespaceScopedAcquisition(t *testing.T) {
	n, p := fixtureObjects(1)
	server := &protocolServer{t: t, nodes: n, pods: p}
	server.hook = func(w http.ResponseWriter, r *http.Request, _ int64) bool {
		if strings.HasSuffix(r.URL.Path, "pods") && r.URL.Path != "/api/v1/namespaces/utcp-runtime/pods" {
			t.Error("wrong scope")
			w.WriteHeader(403)
			return true
		}
		return false
	}
	_, tlsServer := readerServer(t, server)
	reader, err := NewKubernetesReader(UpstreamConfig{URL: tlsServer.URL, CAPEM: pemCertificate(tlsServer.Certificate().Raw), Token: "ephemeral-test-token", Namespace: "utcp-runtime"})
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	q := request()
	q.Scope.PodNamespaces = []string{"utcp-runtime"}
	s := observe(t, Collector{Reader: reader, ClusterID: q.ClusterID, Namespace: "utcp-runtime"}, q)
	if !s.Complete || len(s.Pods) != 1 {
		t.Fatal("namespace scope failed")
	}
}
