# UTCP Infrastructure and Runtime Instructions

These rules refine root `AGENTS.md` for Kubernetes, k3d, containers, Helm,
Gateway API, Traefik, Kamailio, rtpengine, Asterisk, FreeSWITCH, and runtime
proof under `infrastructure`.

Kubernetes owns Nodes, Pods, scheduling, placement, restart behavior,
namespaces, RBAC, admission, declared resources, and NetworkPolicy. UTCP owns
desired business/runtime-node state and reconciliation; it may observe
Kubernetes facts but cannot replace Kubernetes authority. Traefik owns
HTTP/HTTPS/WSS ingress; Kamailio owns SIP edge/registrar behavior; rtpengine
owns RTP/SRTP relay; Asterisk and FreeSWITCH own live telephony execution behind
UTCP adapters.

Current V1 acceptance authority is native k3s on `utcp-dev01` at
`192.168.254.124`, subject to `docs/roadmap/phase-status.md` and ADR-028.
`utcp-local` is secondary development/regression topology and must be selected
explicitly before mutation or proof. Read the phase ledger, applicable topology
ADR, and runbook first; use explicit context and namespace arguments. Never
create a parallel cluster, registry, namespace, port mapping, or Compose
authority to evade a verifier.

Keep management, inter-node, public HTTPS/WSS, SIP signaling, RTP/media,
cluster-internal service, and external-provider addresses distinct. A
Kubernetes host is not a UTCP RuntimeNode. Prefer declarative Kustomize/Helm
sources, repository preflight, Make targets, and canonical apply/proof scripts.
Preserve secret references, least-privilege RBAC, Pod Security, NetworkPolicy,
TLS, and no-public-exposure contracts.

Startup, smoke, status, API, and Kubernetes-operation success are not by
themselves runtime readiness proof. Separate desired state, provider
representation, runtime evidence, observations, and recovery. Use adapter
contracts and projection paths; do not add direct PBX control, static routing
fallbacks, manual readiness, or alternate runtime mutation authorities. Before
live mutation record environment, scope, preservation, rollback, and proof
claim. See the authority map, ADR-028, runbooks, and phase evidence.
