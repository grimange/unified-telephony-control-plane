# GN-G2 real API-server verification gate

**Original implementation packet: DEFERRED / NOT VERIFIED.** No explicitly authorized disposable API-server target
was supplied or established during GN-G2. No canonical cluster credentials were
read, no cluster was created and no live Kubernetes mutation was performed.
The HTTPS fixtures and RFC6902 simulator exercise protocol and decision logic;
they do not establish Kubernetes admission or concurrency guarantees.

## Required authorization and setup

The operator must identify an existing disposable API-server target and authorize
its namespace, constrained credential, API URL/CA, Kubernetes version and cleanup
scope. Do not select an ambient kubeconfig or canonical k3s context. The test
account needs only GET/PATCH on named disposable Deployments. A separately
authorized setup identity creates the namespace/resources; add create/delete
only to the fixture setup/cleanup identity. Do not use production secrets,
telephony workloads, external network paths or a new cluster to bypass this gate.

Record server `/version`, pinned client-go v0.35.3 and exact fixture/template
revision. Use a single inert pause-style Deployment, no Service, ingress or
autoscaler, in the authorized namespace. If the server is API-only without a
scheduler/controller manager, state that explicitly: this gate concerns API
mutation semantics, not live Pod readiness. Ephemeral local claim/mTLS fixtures
may authorize these sanitized actions; they are not a production Laravel route.

## Reproducible test procedure

The following is a test specification, **not an executed integration suite**.
Implement a gated test harness in the authorized proof packet using the existing
adapter library. Require explicit URL, CA, namespace, fixture name and test token
inputs; no ambient credentials or default target. Keep tokens out of arguments,
logs and committed files. The adapter's configured namespace must be exactly the
authorized namespace.

1. Create the disposable Deployment through the setup identity, with all seven
   GN-G0 ownership labels and an unrelated annotation. Capture UID/RV directly.
2. Construct a schema-valid generation-10 `admit_generation` and matching
   `set_replicas`, with a new expected RV after each accepted mutation. Invoke
   `Engine.Execute` against the real HTTPS API, using test-only current claim
   replies. Assert the result envelopes and direct-read exact state agree.
3. Repeat with wrong UID, stale RV, a conflicting generation hash, wrong tenant,
   RuntimeNode, target and incarnation. Assert nondispatch for local ownership
   rejection. Submit prepared JSON Patch predicates directly only through the
   scoped test harness to prove the API independently rejects UID/RV/barrier
   mismatches. Verify no unintended field changed.
4. Exercise absent annotations, existing empty annotations and unrelated
   annotations. Confirm parent-map bootstrap preserves unrelated metadata and
   that a concurrent annotation update invalidates the original RV.
5. Synchronize two adapters after the same GET. Release competing patches and
   prove at most one mutation based on that RV applies. Capture sanitized API
   Status `code`, `reason` and structured cause identifiers for losers. Do not
   assume the simulator's chosen status code matches this server.
6. Delay generation 10, commit generation 11's barrier, then release 10. Verify
   rejection and exact final generation. Also release 10 before the newer barrier:
   acknowledge the temporary stale effect and prove later canonical convergence.
7. Compare parent Deployment JSON Patch with `/scale` using scoped test-only
   requests. Show why scale cannot atomically carry parent metadata admission
   predicates and applied markers. The production adapter must continue refusing
   all subresource paths.
8. Trigger a generic 422 validation error separately from a JSON Patch test
   failure. Record structured sanitized Status fields. Keep generic 422 mapped
   to `invalid_request`; only add predicate-specific retry mapping after an exact
   documented server signature is proven and covered by negative regression.
9. With a controlled local forwarding proxy, lose the response after the server
   applies the patch and separately before application. Require
   `outcome_unknown/observe_first`, one PATCH attempt, then GET-only recovery.
   Matching replicas alone must not establish satisfaction.
10. Delete/recreate only the fixture Deployment using the setup identity. Submit
    the old bound request and verify UID rejection despite name/label equality.
    This is a fixture identity test, not implementation of Go lifecycle actions.
11. Cleanup only UID-identified resources created by this proof. Record unresolved
    terminating resources and stop rather than deleting unrelated state.

## Required proof artifact

Publish concise request predicates, sanitized Status classifications, final
UID/RV/marker assertions, exact test commands, test counts and cleanup outcome.
Never include tokens, kubeconfig contents, private keys, raw Deployment dumps or
noisy server logs. Record admission plugins and server version because they can
affect validation/status behavior. Distinguish API-server validation from
controller-manager, scheduler, deployed UTCP and natural-live telephony proof.

Passing this gate establishes only the tested server semantics. GN-G3 still
requires the lifecycle extension, full writer inventory, legacy-request drain,
credential cutoff, compatible rollback and separately authorized acceptance.

## 2026-10-21 supplement — separately authorized disposable API proof

The paragraphs above preserve the original GN-G2 implementation proof and its
then-unresolved gate. A later operator assignment explicitly authorized an owned
disposable k3d target. It does not authorize use of the canonical cluster or
turn the original simulated tests into real-server evidence.

The [real API proof runner](../../../../tests/gn-g2-real-api/verify) now implements
the gated procedure with a pinned node image, explicit private kubeconfig,
loopback API port, unpredictable run ownership, constrained adapter accounts,
sanitized evidence and verified cleanup. From repository root, after explicit
authorization for this isolated cluster creation:

```bash
tests/gn-g2-real-api/verify
```

The original target-selection instructions are not implicit authorization to
create any other cluster. The current runner never chooses an ambient context;
it creates and deletes only its own verified disposable target. Read the
[runner safety and cleanup instructions](../../../../tests/gn-g2-real-api/README.md)
before execution.

The final fresh real-server execution passed 16 scenario groups, race detection
and tagged vet. A narrow structured-Status error-projection defect found during
this proof was repaired and covered by permanent GET/PATCH regressions before
the final run. Actual server predicate failures were HTTP422 `Invalid` with no
causes; generic 422 remains conservatively `invalid_request`. Final evidence,
exact server responses, regression outcomes and verified owned cleanup appear in the
[real API verification report](../UTCP_GN_G2_REAL_KUBERNETES_API_CONDITIONAL_EFFECT_VERIFICATION.md).
Its verdict is `GN_G2_REAL_API_VERIFIED` for the two already-bound effects on
Kubernetes `v1.35.3+k3s1`. This API gate leaves lifecycle implementation and all
writer-cutover gates unresolved; the next implementation assignment is
[bounded lifecycle v2](GN-G2-lifecycle-v2-implementation-assignment.md).
