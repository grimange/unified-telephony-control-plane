# Local Runtime Authority Cutoff Evidence

## Scope

This evidence records the cutoff that makes `utcp-local` Kubernetes the sole canonical integrated local runtime before C4.

## Initial State

Initial inspection observed:

- `utcp-local` k3d cluster running.
- Standard ports `127.0.0.1:80` and `127.0.0.1:443` owned by the UTCP k3d load balancer.
- Repository-owned persistent Compose project `utcp` running from `infrastructure/compose/compose.yaml`.
- APNTalk k3d cluster intentionally stopped.
- Global Kubernetes context remained outside repository control.

## Implemented Cutoff

- Added canonical `local-up`, `local-status`, `local-proof`, and `local-down` targets.
- Updated normal local status and proof to target Kubernetes only.
- Added persistent Compose drift detection before canonical local lifecycle and proof.
- Changed `compose-proof` to run an isolated disposable Compose project with cleanup traps.
- Changed `compose-up` to an explicit debug-mode compatibility alias.
- Removed the Compose fallback from local identity bootstrap.
- Corrected `UTCP_PHASE` to `C3`.

## Final State

Observed after the cutoff:

- `docker compose ls` showed only the unrelated APNTalk Compose project named `docker` running from `/home/ra/Documents/apn_projects/APNTalkv3-Modernization/docker/docker-compose.yml`.
- `docker ps -a --format '{{.Names}}\t{{.Status}}\t{{.Ports}}'` showed no persistent UTCP Compose containers. The `k3d-utcp-local-serverlb` container owned `127.0.0.1:80`, `127.0.0.1:443`, and `127.0.0.1:6550`; `utcp-local-registry` remained published on `127.0.0.1:5001`.
- `k3d cluster list` showed `utcp-local` running with one server and two agents, and `apntalk-local` intentionally stopped.
- `kubectl config current-context` remained `k3d-apntalk-local`.
- `make local-status` reported `utcp-local` as the canonical integrated local runtime, Kubernetes edge ownership on standard ports, healthy K1 workloads, programmed Gateway, active K3 policy, healthy K4 observability, active C3 process roles, prepared certificate fingerprint summary, and `persistent_utcp_compose=none`.
- `make local-proof` passed the Kubernetes proof corridor without starting Compose.
- `make compose-proof` passed with an isolated project named `utcp-compose-proof-*`, gateway port `18088`, isolated proof networks and volumes, all expected Compose services including the four C3 roles, and unconditional cleanup.
- Follow-up Docker network and volume inspection found no `utcp-compose-proof-*` resources remaining.

The prior persistent UTCP Compose project was stopped and removed through the repository Compose lifecycle as the explicit authority cutoff. Docker images, the local registry, Kubernetes resources, Kubernetes PVCs, prepared TLS files, APNTalk resources, and unrelated Compose projects were preserved.

## Verification Results

Passed:

- `make help`
- `make doctor`
- `make repository-hygiene`
- `make workflow-check`
- `make secret-scan`
- `make local-status`
- `make local-proof`
- `make control-plane-config-check`
- `make control-plane-test`
- `make identity-config-check`
- `make identity-test`
- `make runtime-registry-config-check`
- `make runtime-registry-test`
- `make runtime-engine-config-check`
- `make runtime-engine-test`
- `make compose-proof`
- `make k8s-status`
- `make gateway-proof`
- `make security-proof`
- `make observability-proof`
- `make runtime-engine-status`
- `make test`
- `make check`
- `make build`
- `make container-check`

Additional inspections:

- Final `docker compose ls` confirmed no persistent UTCP Compose project.
- Final `docker ps -a` confirmed no persistent UTCP Compose containers and preserved standard k3d port ownership.
- Disposable Compose proof networks and volumes were absent after proof cleanup.
- `UTCP_PHASE=C3` matched the completed phase-status document.
- No C4 simulator behavior was introduced.

Do not include credentials, private keys, cookies, session IDs, or full noisy logs.
