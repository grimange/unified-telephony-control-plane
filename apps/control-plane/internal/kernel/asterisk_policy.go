package kernel

import (
	"bytes"
	"context"
	"encoding/json"
	"strconv"

	"github.com/jackc/pgx/v5"
)

// AsteriskDomainPolicy ports CanonicalInfrastructureService::assertDomainPolicy
// and its conference replacement collaborators. It authorizes a bounded future
// effect; it does not establish that fencing or restoration has completed.
//
// The persisted readiness and K5C facts have exactly the source policy's
// semantics. In particular there is no invented freshness TTL, capacity zero
// means unlimited, and missing unconstrained topology is permitted.
type AsteriskDomainPolicy struct{}

func NewAsteriskDomainPolicy() *AsteriskDomainPolicy { return &AsteriskDomainPolicy{} }

// Lock protects predicate reads, including absent competing operations and new
// active work, against unchanged PostgreSQL writers. Node row locks alone do
// not protect those phantoms at READ COMMITTED. The fixed, coarse table guard is
// deliberately limited to the isolated implementation; it trades throughput for
// a serializable policy decision without requiring legacy writers to cooperate.
// It must run before any canonical row locks and is released at transaction end.
func (*AsteriskDomainPolicy) Lock(ctx context.Context, tx pgx.Tx) error {
	if _, err := tx.Exec(ctx, "SET LOCAL lock_timeout = '2s'"); err != nil {
		return err
	}
	_, err := tx.Exec(ctx, `LOCK TABLE call_legs, conference_runtime_bindings,
		conferences, runtime_node_capabilities, runtime_node_k5c_placement_observations,
		runtime_nodes, runtime_operations IN SHARE ROW EXCLUSIVE MODE`)
	return err
}

func (*AsteriskDomainPolicy) Check(ctx context.Context, tx pgx.Tx, i Incarnation, a Envelope, o Operation) error {
	var family, desired, observed, configuration string
	if err := tx.QueryRow(ctx, `SELECT runtime_family,desired_state,observed_state,
		configuration_version::text FROM runtime_nodes WHERE id=$1 AND tenant_id=$2`, i.RuntimeNodeID, i.TenantID).
		Scan(&family, &desired, &observed, &configuration); err != nil {
		if err == pgx.ErrNoRows {
			return ErrDenied
		}
		return err
	}
	if family != "asterisk" || i.Profile != "asterisk.managed.v1" {
		return ErrDenied
	}
	payload, err := policyPayload(o.Payload)
	if err != nil {
		return ErrDenied
	}
	replicas := a.Replicas
	if a.Version != "utcp.infrastructure.v1" {
		replicas = a.Intent.Replicas
	}
	switch o.Type {
	case "runtime.node.runtime.fence":
		if observed == "ready" || replicas != 0 || policyString(payload, "former_runtime_node_id") != i.RuntimeNodeID {
			return ErrDenied
		}
		conference, binding := policyString(payload, "conference_id"), policyString(payload, "former_runtime_binding_id")
		generation, ok := policyDecimal(payload["configuration_generation"])
		if conference == "" || binding == "" || !ok {
			return ErrDenied
		}
		var valid bool
		err = tx.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM conferences c
			WHERE c.id::text=$1 AND c.tenant_id=$2 AND c.desired_state='open'
			AND c.runtime_node_id=$3 AND c.configuration_generation=$4::bigint)
			AND EXISTS(SELECT 1 FROM conference_runtime_bindings b
			WHERE b.id::text=$5 AND b.tenant_id=$2 AND b.conference_id::text=$1
			AND b.runtime_node_id=$3 AND b.status='active')`, conference, i.TenantID, i.RuntimeNodeID, generation, binding).Scan(&valid)
		if err != nil {
			return err
		}
		if !valid {
			return ErrDenied
		}
		return asteriskReplacement(ctx, tx, i, conference)
	case "runtime.node.restore":
		if desired != "disabled" || replicas < 1 || policyString(payload, "tenant_id") != i.TenantID ||
			policyString(payload, "runtime_node_id") != i.RuntimeNodeID || policyString(payload, "requested_desired_state") != "active" ||
			policyString(payload, "workload_namespace") != i.Namespace || policyString(payload, "deployment") != i.Name ||
			!policyIntegerEquals(payload["target_replicas"], replicas) || policyScalarString(payload["expected_runtime_node_configuration_version"]) != configuration {
			return ErrDenied
		}
		return asteriskRestoreSource(ctx, tx, i, payload, replicas)
	default:
		return ErrDenied
	}
}

// Existence is sufficient: PHP ranks all eligible candidates, then returns the
// first. Under the transaction guard every relevant predicate remains stable,
// so no ordering choice can change this boolean authorization result.
func asteriskReplacement(ctx context.Context, tx pgx.Tx, i Incarnation, conference string) error {
	var eligible bool
	err := tx.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM runtime_nodes n
		LEFT JOIN runtime_node_k5c_placement_observations p ON p.runtime_node_id=n.id AND p.tenant_id=$1
		WHERE n.tenant_id=$1 AND n.id<>$2 AND n.desired_state='active' AND n.observed_state='ready'
		AND EXISTS(SELECT 1 FROM runtime_node_capabilities k WHERE k.runtime_node_id=n.id AND k.capability_key='conference.lifecycle')
		AND EXISTS(SELECT 1 FROM runtime_node_capabilities k WHERE k.runtime_node_id=n.id AND k.capability_key='conference.participation')
		AND (COALESCE(n.capacity_weight,0)=0 OR
			(SELECT count(*) FROM conference_runtime_bindings b WHERE b.tenant_id=$1 AND b.runtime_node_id=n.id AND b.status='active' AND b.conference_id::text<>$3)
			+ (SELECT count(*) FROM call_legs l WHERE l.tenant_id=$1 AND l.runtime_node_id=n.id AND l.observed_state NOT IN ('completed','failed','cancelled')) < n.capacity_weight)
		AND ((n.placement_region IS NULL AND n.placement_zone IS NULL) OR
			(p.status IN ('placed','ambiguous_multiple_nodes_observed')
			AND (n.placement_region IS NULL OR p.observed_region=n.placement_region)
			AND (n.placement_zone IS NULL OR p.observed_zone=n.placement_zone))))`, i.TenantID, i.RuntimeNodeID, conference).Scan(&eligible)
	if err != nil {
		return err
	}
	return denyIf(eligible)
}

func asteriskRestoreSource(ctx context.Context, tx pgx.Tx, i Incarnation, payload map[string]any, replicas int) error {
	source := policyString(payload, "source_fence_operation_id")
	if source == "" {
		return ErrDenied
	}
	var raw []byte
	err := tx.QueryRow(ctx, `SELECT payload FROM runtime_operations WHERE id::text=$1 AND tenant_id=$2
		AND runtime_node_id=$3 AND operation_type='runtime.node.runtime.fence'
		AND status='succeeded' AND completed_at IS NOT NULL`, source, i.TenantID, i.RuntimeNodeID).Scan(&raw)
	if err != nil {
		if err == pgx.ErrNoRows {
			return ErrDenied
		}
		return err
	}
	p, err := policyPayload(raw)
	if err != nil {
		return ErrDenied
	}
	provenance, _ := p["runtime_fence_provenance"].(map[string]any)
	scale, scaleOK := provenance["scale_to_zero_requested"].(map[string]any)
	disabled, disabledOK := provenance["runtime_node_disabled"].(map[string]any)
	sourceGeneration, sourceGenerationOK := policyComparableScalar(p["configuration_generation"])
	requestedGeneration, requestedGenerationOK := policyComparableScalar(payload["source_fence_generation"])
	if !scaleOK || !disabledOK || disabled["by_operation"] != true ||
		policyString(disabled, "runtime_node_id") != i.RuntimeNodeID || policyString(disabled, "operation_id") != source ||
		policyString(scale, "operation_id") != source || policyString(scale, "namespace") != i.Namespace || policyString(scale, "deployment") != i.Name ||
		!policyIntegerEquals(scale["pre_scale_replicas"], replicas) || !sourceGenerationOK || !requestedGenerationOK || sourceGeneration != requestedGeneration {
		return ErrDenied
	}
	var conflict bool
	err = tx.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM runtime_operations WHERE tenant_id=$1 AND runtime_node_id=$2
		AND operation_type='runtime.node.runtime.fence' AND id<>$3
		AND status IN ('pending','leased','running','retry_scheduled'))
		OR EXISTS(SELECT 1 FROM conference_runtime_bindings b JOIN conferences c ON c.id=b.conference_id
		WHERE b.tenant_id=$1 AND b.runtime_node_id=$2 AND b.status='active' AND c.desired_state='open')`, i.TenantID, i.RuntimeNodeID, source).Scan(&conflict)
	if err != nil {
		return err
	}
	return denyIf(!conflict)
}

func policyPayload(raw []byte) (map[string]any, error) {
	var out map[string]any
	d := json.NewDecoder(bytes.NewReader(raw))
	d.UseNumber()
	err := d.Decode(&out)
	return out, err
}
func policyString(p map[string]any, key string) string {
	s, _ := p[key].(string)
	return s
}
func policyScalarString(v any) string {
	s, _ := policyComparableScalar(v)
	return s
}

// Unsupported composite values must not collapse to the same representation as
// a missing value. Missing legacy generation fields retain PHP's empty-string
// comparison; malformed objects and arrays never supply provenance authority.
func policyComparableScalar(v any) (string, bool) {
	switch x := v.(type) {
	case nil:
		return "", true
	case string:
		return x, true
	case json.Number:
		// Canonical generations are exact integer scalars. PHP casts JSON
		// decimals/exponents through floating point, whose lexical form is not
		// trustworthy generation provenance. Reject rather than coercing it.
		if _, err := x.Int64(); err != nil {
			return "", false
		}
		return string(x), true
	case bool:
		if x {
			return "1", true
		}
		return "", true
	}
	return "", false
}
func policyIntegerEquals(v any, expected int) bool {
	n, ok := v.(json.Number)
	if !ok {
		return false
	}
	i, err := n.Int64()
	return err == nil && i == int64(expected)
}
func policyDecimal(v any) (string, bool) {
	s := policyScalarString(v)
	n, err := strconv.ParseInt(s, 10, 64)
	return strconv.FormatInt(n, 10), err == nil && n >= 0
}
