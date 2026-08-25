# V1-A Cluster DNS and Local k3d Lifecycle Repair

**Status:** Implemented and locally runtime-tested on 2026-08-25. V1 remains
active.

## Scope

This bounded packet repaired the proven CoreDNS search-domain timeout and the
canonical local k3d start lifecycle. It did not add registration functionality,
deploy V1-A registration state, activate V1-B, modify the external PBX, or use
the external PBX credentials.

## DNS correction

The existing generated CoreDNS `Corefile` imports
`/etc/coredns/custom/*.server`. The repository now owns
`infrastructure/k3d/coredns-custom.yaml`, a `kube-system/coredns-custom`
ConfigMap containing a `lan:53` server block with the CoreDNS `template`
plugin returning `NXDOMAIN`. It does not alter the generated `.:53` block and
does not forward `.lan` queries.

`scripts/k3d/cluster-dns-apply` applies the ConfigMap, verifies its existence,
restarts the CoreDNS Deployment through Kubernetes, and waits for rollout and
Pod readiness. Repeated application converges without changing the manifest;
the second live application reported `configmap/coredns-custom unchanged`.

## Local lifecycle correction

`scripts/k3d/lib` now distinguishes `ABSENT`, `RUNNING`, and `STOPPED` from
the supported `k3d cluster list -o json` state. A stopped `utcp-local` cluster
uses `k3d cluster start utcp-local`; an absent cluster uses the existing
repository create configuration; a running cluster is reused. The start path
contains no delete operation and does not touch PVCs, PVs, or Docker volumes.

`scripts/k3d/start` and `make k3d-start` expose the canonical lifecycle.
`make local-up` now uses that path and applies the repository-owned DNS
configuration before the normal image, Kubernetes, gateway, security,
observability, and runtime reconciliation steps.

The strict `scripts/k3d/verify` contract remains unchanged, including the
declared V1-B `0.0.0.0:5060 -> 30560/udp` requirement. Starting an existing
cluster no longer invokes that full immutable-topology acceptance check, so a
pre-existing cluster is not destroyed or rejected solely because it predates
the inactive V1-B host publication. `make k3d-verify` remains the explicit
full-topology check.

## Runtime evidence

Before this packet, the recorded service lookups were approximately four
seconds and CoreDNS logged `.lan` search-suffix forwarding timeouts. After the
CoreDNS rollout, API-Pod `getent` timings were:

| Lookup | Timings (seconds) |
| --- | --- |
| `postgres.utcp-data.svc.cluster.local` | 0.07, 0.08, 0.09 |
| `redis.utcp-data.svc.cluster.local` | 0.09, 0.09, 0.10 |
| direct negative `.lan` lookup | 0.10 |

The post-rollout bounded log check found no `.lan` timeout for the acceptance
lookup. The window contained unrelated pre-existing upstream timeout entries
for non-UTCP names; those are not attributed to the repaired `.lan` path.

The gateway Deployment was `1/1` available and the normal edge request
`https://app.utcp.local.test/api/v1/auth/csrf` returned HTTP 200. The prior
gateway `no available server` condition was not present during this check.

The existing T6 runtime proofs passed after DNS recovery:

* `make kamailio-signaling-external-trunk-runtime-proof`
* `make asterisk-external-trunk-runtime-proof`

The synthetic fixture remains a regression surface. The independent external
PBX remains the authoritative V1 Level-2 interoperability target. This packet
does not claim the V1-A live external REGISTER proof.

## Persistent-state preservation

The existing `utcp-local` cluster was already running and was reused. Before
the DNS rollout, PostgreSQL and Redis were Running with their existing bound
PVCs and PVs; the three existing k3d nodes were Ready. After rollout, those
Pods, PVC/PV identities, and node identities remained intact. No cluster,
registry, PVC, PV, Docker volume, database, or Redis recreation was performed.

## Remaining proof

V1 remains active. The next bounded runtime packet is deployment and live proof
of V1-A registration against the independent external PBX, followed by the
remaining bidirectional interoperability/call evidence required by the V1
acceptance contract.
