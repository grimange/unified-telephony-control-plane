# Traefik Gateway API Runbook

> **Topology note:** The HTTP/HTTPS example below is the historical **k3d/local
> variant**. The current active topology is native k3s on `utcp-dev01`, where
> `192.168.254.124:80/443` owns the edge and
> `app.utcp.local.test -> native edge`. See
> [`ADR-028`](../decisions/ADR-028-native-k3s-current-development-and-v1-acceptance-topology.md).
> Do not imply that both edges can safely operate simultaneously on this host.

Phase K2 installs Traefik as the local Kubernetes HTTP/HTTPS edge for the existing K1 application gateway.

## Architecture

External flow:

```text
curl/browser -> 127.0.0.1:80 or 127.0.0.1:443
  -> k3d server load balancer
  -> traefik-system/service/traefik
  -> Gateway API HTTPRoute
  -> utcp-platform/service/gateway:8080
  -> web or api services
```

The K1 `gateway` Service remains the only application backend exposed by Traefik.

Future WSS routing shares TCP `443`:

```text
443/TCP
└── Traefik
    ├── app.utcp.local.test
    │   └── HTTPS -> UTCP web/API
    ├── sip.utcp.local.test
    │   └── WSS -> Kamailio WebSocket listener
    └── events.utcp.local.test
        └── WSS -> Reverb/application events
```

K2 does not activate the SIP or events routes.

## Versions

- Gateway API Standard CRDs: `v1.5.1`
- Traefik Helm chart: `41.0.2`
- Traefik proxy image: `docker.io/traefik:v3.7.7`

Versions are pinned in `versions.env`. Do not use `latest`, `main`, `master`, or floating Helm repository state.

Gateway API `v1.5.1` Standard includes additional Standard resources such as GRPCRoute, BackendTLSPolicy, ListenerSet, and TLSRoute. K2 does not create UTCP resources of those kinds, and Traefik Gateway API experimental channel remains disabled.

## Host Ports and DNS

Only one local application k3d cluster may own the standard local edge at a time. When UTCP is active, `utcp-local` uses:

```text
127.0.0.1:80 -> k3d load balancer port 80
127.0.0.1:443 -> k3d load balancer port 443
```

APNTalk may be intentionally stopped by the operator. UTCP scripts must never stop, start, delete, recreate, or mutate `apntalk-local` to obtain these ports. If another process owns either standard port, UTCP preflight fails and reports the owner.

Required local DNS entries are operator-managed outside the repository:

```text
127.0.0.1 utcp.local.test
127.0.0.1 sip.utcp.local.test
127.0.0.1 app.utcp.local.test
127.0.0.1 events.utcp.local.test
```

K2 routes only `app.utcp.local.test` and `utcp.local.test`.

## Local TLS

Generate or reuse local TLS material:

```sh
make gateway-tls
```

Files are written under ignored `.runtime/tls/` storage. The certificate SANs are:

```text
app.utcp.local.test
utcp.local.test
```

The host trust store is not modified. Browser trust is not automatic. Runtime proof uses the generated CA explicitly.

## Deployment

Apply K2 end to end:

```sh
make gateway-config-check
make gateway-crds-install
make traefik-install
make gateway-tls
make gateway-apply
```

`make gateway-apply` verifies K0/K1, verifies host-port mappings, installs CRDs, installs or upgrades Traefik, applies TLS, applies Gateway API resources, waits for status, and reports status.

## Status and Proof

```sh
make gateway-status
make gateway-proof
```

Proof checks HTTP redirect to `https://app.utcp.local.test/`, HTTPS with the generated CA, `/healthz`, `/`, API live/ready/version endpoints, host mismatch rejection, reserved hostname rejection, dashboard non-exposure, no direct K1 public exposure, no port-forward process, empty `utcp-runtime`, and absence of custom UTCP edge ports.

## Cleanup

Remove K2 Gateway resources and TLS Secret while preserving K1 and Gateway API CRDs:

```sh
make gateway-delete
```

Uninstall Traefik explicitly:

```sh
CONFIRM=uninstall-traefik make gateway-delete
```

Gateway API CRDs are not removed by `gateway-delete`.

## Troubleshooting

- If `80` or `443` is occupied by a non-UTCP process or container, stop and report the exact owner with `K2_STANDARD_PORT_SWITCH_BLOCKED`. Do not choose a fallback port and do not stop the owner automatically.
- If Gateway status is not Accepted or Programmed, inspect `make gateway-status` and Traefik logs in `traefik-system`.
- If TLS fails, regenerate with `make gateway-tls` and verify the SANs.
- If reserved hostnames route to the app, inspect the HTTPRoute hostnames and remove any wildcard or reserved-host route.
- To switch local clusters, the operator explicitly stops the active cluster before starting the other one. UTCP does not control APNTalk.
