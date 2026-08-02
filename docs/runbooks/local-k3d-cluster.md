# Local k3d Cluster Runbook

## Scope

Phase K0 establishes a local Kubernetes foundation only. It creates a deterministic k3d/K3s cluster, a k3d-managed local registry, a repository-managed kubeconfig, and canonical namespace boundaries.

It does not deploy the UTCP API, web application, worker, scheduler, migration job, PostgreSQL, Redis, Traefik, Gateway API, observability, simulator behavior, or telephony runtimes.

## Prerequisites

Required local tools:

```sh
docker version
k3d version
kubectl version --client
```

`make doctor` classifies Docker, k3d, and kubectl as required for K0. It does not install or modify host software.

The proven local cluster required the host inotify instance limit to be high enough for the one-server/two-agent K3s topology. The successful K0 proof ran with:

```sh
sysctl fs.inotify.max_user_instances
```

returning:

```text
fs.inotify.max_user_instances = 512
```

Repository scripts do not change host sysctl settings.

## Topology

- Cluster: `utcp-local`
- Context: `k3d-utcp-local`
- Servers: `1`
- Agents: `2`
- K3s image: `docker.io/rancher/k3s:v1.35.3-k3s1`
- Kubernetes API: `127.0.0.1:6550`
- Registry: `utcp-local-registry`
- Registry image: `registry:3.0.0`
- Registry host endpoint: `127.0.0.1:5001`

Packaged K3s Traefik is disabled. Traefik is introduced later by K2.

K2 adds the one-cluster-at-a-time standard local edge:

```text
127.0.0.1:80 -> k3d load balancer port 80
127.0.0.1:443 -> k3d load balancer port 443
127.0.0.1:40000-40099/UDP -> k3d server:0 media-edge ports
```

The RTP publication is part of the canonical `infrastructure/k3d/cluster.yaml`
provisioning. The NodePort range is extended to `30000-40099` and the selected
server is labelled `utcp.dev/media-edge=true`; there is no separate media-edge
cluster profile or alternate registry lifecycle.

If another process owns either port, `make k3d-create` and `make k3d-recreate-proof` fail before creating or recreating the UTCP cluster. The scripts report the owner and never stop APNTalk or any unrelated listener.

## Kubeconfig Isolation

The repository kubeconfig is generated at:

```text
.runtime/kubeconfig/utcp-local.yaml
```

`.runtime/` is ignored and must not be committed. Repository scripts set `KUBECONFIG` explicitly and use context `k3d-utcp-local`.

Use:

```sh
scripts/k3d/kube get nodes
```

Do not rely on the user's global current Kubernetes context for UTCP commands.

## Namespaces

K0 creates only these namespace boundaries:

- `traefik-system`
- `utcp-platform`
- `utcp-runtime`
- `utcp-data`
- `utcp-observability`

No workloads, service accounts, role bindings, secrets, config maps, network policies, quotas, or limit ranges are created in K0.

## Lifecycle Commands

Validate checked-in configuration:

```sh
make k3d-config-check
```

Create or verify the cluster:

```sh
make k3d-create
```

Show status:

```sh
make k3d-status
```

Verify K0:

```sh
make k3d-verify
```

Prove registry push and pull:

```sh
make k3d-registry-proof
```

Delete only the UTCP-owned cluster and registry:

```sh
make k3d-delete
```

Run the destructive recreate proof:

```sh
make k3d-recreate-proof
```

`k3d-recreate-proof` deletes and recreates only `utcp-local`. It leaves optional Compose debug projects and unrelated k3d clusters untouched.
The recreate uses the canonical cluster configuration, including the loopback
UDP range `127.0.0.1:40000-40099/UDP`.

When switching between local application clusters, run cluster lifecycle commands explicitly. For example, the operator may stop APNTalk before starting UTCP, or stop UTCP before starting APNTalk. UTCP repository commands do not automate that switch.

## Docker And Compose Boundary

Docker Engine remains required because it builds application images, runs the k3d node containers, and hosts the local registry. Docker Compose remains available for disposable proof and explicit debug mode only.

The canonical integrated local runtime is `utcp-local`. Normal Kubernetes lifecycle and proof commands do not start Compose and do not fall back to Compose if Kubernetes is unavailable. A persistent UTCP Compose debug project running beside Kubernetes is reported as authority drift and must be stopped explicitly before canonical proof.

Before and after K0 proof, inspect:

```sh
docker compose ls
```

## Registry Troubleshooting

Check the registry:

```sh
k3d registry list
docker ps --filter name=utcp-local-registry
```

k3d v5.9.0 creates the registry-role container using the configured registry name directly: `utcp-local-registry`. UTCP verification treats that configured name as canonical and does not accept a prefixed fallback.

Run:

```sh
make k3d-registry-proof
```

The proof pushes a pinned BusyBox image to `127.0.0.1:5001` and runs a short-lived Kubernetes Job that pulls through the local registry. The Job is removed after proof.

## Cleanup

Non-destructive status and verification commands do not delete the cluster.

Delete K0 resources:

```sh
make k3d-delete
```

This deletes only:

- k3d cluster `utcp-local`
- k3d registry `utcp-local-registry`
- `.runtime/kubeconfig/utcp-local.yaml`

It does not delete Docker Compose volumes, unrelated Docker resources, or unrelated Kubernetes clusters.

## Known Limitations

- No UTCP application workload is deployed to Kubernetes in K0.
- No Traefik, Gateway API, Kustomize base, Helm chart, PostgreSQL, Redis, observability stack, simulator, SIP, RTP, Asterisk, FreeSWITCH, Kamailio, or rtpengine resource exists yet.
- Hosted GitHub Actions proof is available only after changes are pushed.
