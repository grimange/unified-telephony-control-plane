# RNP-5 — Managed Runtime Workload Authority Cutover

This bounded repository change records the authority transfer implemented on
the current branch. Earlier August 22/24/25 work introduced the dedicated
`telephony-infrastructure-worker` and `utcp-runtime-fencer` boundary, but
managed RuntimeNode reconcilers still directly applied Kubernetes Deployments
and Asterisk still read Pod image IDs from `telephony-reconciler`.

The direct reconciler path has been removed. Routine managed workload repair is
now represented by `runtime.node.workload.converge`, emitted by RuntimeNode
reconciliation and executed only by `telephony-infrastructure-worker`. The
operation reuses the existing managed Asterisk and FreeSWITCH desired
Deployment builders and applies only the Deployment, preserving first-time
provisioning as the owner of Secret, Service, endpoint, credential, capability,
identity, and activation setup.

The infrastructure operation also owns the existing Kubernetes Pod observation
needed for managed Asterisk execution-image currency and writes that normalized
observation to the established RuntimeNode projection field. The reconciler
therefore retains no Kubernetes client or Kubernetes API identity. The
`utcp-runtime-fencer` identity remains attached only to the dedicated
infrastructure worker.

The shared worker egress baseline remains limited to DNS, PostgreSQL, Redis,
and Reverb. Kamailio registration-control TCP/8090 is projected through a
separate policy whose source is exactly the existing
`kamailio-registration-observer` component and whose destination is the
Kamailio component; it is not granted to the common worker role.

The C5 source guard now checks Kubernetes infrastructure authority vocabulary
rather than treating generic HTTP transport as Kubernetes access. The
canonical internal Kamailio signaling `Http::` seam is accepted, while
mutation coverage continues to reject Kubernetes workload clients and
mutation/read methods in decision and runtime-adapter surfaces.

Automatic reconciliation and existing transaction/idempotency lifecycle remain
the convergence mechanism; no manual trigger, feature gate, compatibility path,
or second Kubernetes authority was introduced. Native k3s deletion and
recreation proof is intentionally deferred to a separate controlled live-proof
packet.

Source validation for this packet passed the C3, C4, and C5 chains and their
focused mutation tests, focused authority-cutover PHPUnit coverage, and the
broader API suite (602 passed, 8 skipped). No live Kubernetes or telephony
state was changed; native k3s deletion/recreation remains the next proof.
