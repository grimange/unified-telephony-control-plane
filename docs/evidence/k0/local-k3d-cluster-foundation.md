# K0 Local k3d Cluster Foundation Evidence

## Scope

Phase K0 establishes a deterministic local k3d/K3s cluster foundation only. It does not deploy the UTCP application, PostgreSQL, Redis, Traefik, Gateway API, simulator behavior, telephony runtimes, authentication, tenancy, authorization, observability, or business-domain workloads.

## Selected Versions

| Component | Version or image |
| --- | --- |
| k3d | `v5.9.0` |
| kubectl | `v1.36.2` |
| K3s image | `docker.io/rancher/k3s:v1.35.3-k3s1` |
| Registry image | `registry:3.0.0` |
| Cluster | `utcp-local` |
| Context | `k3d-utcp-local` |
| Registry | `utcp-local-registry` |
| Kubernetes API endpoint | `127.0.0.1:6550` |
| Registry endpoint | `127.0.0.1:5001` |

## Cluster Topology

- Servers: `1`
- Agents: `2`
- Repository kubeconfig: `.runtime/kubeconfig/utcp-local.yaml`
- Repository kubeconfig default namespace: `utcp-platform`

## Namespace Proof

K0 creates these namespaces only:

- `traefik-system`
- `utcp-platform`
- `utcp-runtime`
- `utcp-data`
- `utcp-observability`

## Verification Summary

K0 runtime proof passed after correcting the registry verification script to use the configured k3d registry container name `utcp-local-registry` instead of the invalid derived name `k3d-utcp-local-registry`.

The host inotify prerequisite was active during successful proof:

- `fs.inotify.max_user_instances = 512`

The K0 proof ran these commands successfully:

- `make k3d-status`
- `make k3d-verify`
- `make k3d-registry-proof`
- `make k3d-recreate-proof`
- focused registry verification regression test: `make k3d-registry-check-test`

Observed final cluster state:

- `utcp-local`: `1/1` server, `2/2` agents
- Node Kubernetes version: `v1.35.3+k3s1`
- Repository kubeconfig context: `k3d-utcp-local`
- Repository kubeconfig default namespace: `utcp-platform`
- Repository kubeconfig path is ignored by Git: `.runtime/kubeconfig/utcp-local.yaml`
- System pods: CoreDNS, local-path-provisioner, and metrics-server were `Running`
- Packaged Traefik pods, deployments, and HelmChart resources were absent
- No UTCP application, data-service, simulator, SIP, RTP, PBX, Asterisk, FreeSWITCH, Kamailio, or rtpengine workload was deployed

Actual registry state:

- Registry container name: `utcp-local-registry`
- Registry binding: `127.0.0.1:5001->5000/tcp`
- Registry status: `running`

## Registry Pull Proof

Completed. `make k3d-registry-proof` pushed a pinned BusyBox proof image to:

```text
127.0.0.1:5001/utcp/k0-proof:1.37.0
```

The cluster pulled it through:

```text
utcp-local-registry:5000/utcp/k0-proof:1.37.0
```

The short-lived proof Job completed and was removed.

## Recreate Proof

Completed. `make k3d-recreate-proof` deleted and recreated only the UTCP-owned `utcp-local` cluster and its managed registry, regenerated the repository kubeconfig, recreated canonical namespaces, reran verification, reran registry push/pull proof, and left `utcp-local` running and healthy.

## Compose Preservation

The default Compose platform remained present after K0 proof:

- `utcp` Compose project: `running(7)`
- unrelated APNTalk Compose project: `docker`, `running(2)`
- unrelated k3d cluster: `apntalk-local`, `1/1` server, unchanged
- global Kubernetes context remained `k3d-apntalk-local`

## Hosted CI Status

Hosted GitHub Actions execution has not been observed for the uncommitted working tree.

## Known Limitations

- No UTCP Kubernetes application proof exists in K0; application deployment begins in K1.
- No Traefik, Gateway API, PostgreSQL, Redis, SIP, RTP, PBX, simulator, Asterisk, FreeSWITCH, Kamailio, rtpengine, or production Kubernetes readiness proof exists in K0.
