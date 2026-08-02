# T3-S3B — Advertised Address Closed, Overlay Apply And Host Harness Blocked

Date: 2026-08-02

Starting commit: `08e1a0d` (`fix(t3): separate media bind and advertise validation`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S3B_EXTERNAL_BROWSER_MEDIA_PROOF_INCOMPLETE`

Remaining seams: **media-edge projection (overlay applicability)** and
**host-browser execution harness**

## Summary

`PRODUCT_DEFECT-23` is **closed and proven live**. The corrected validator split
works exactly as designed: on a fresh media-edge proof cluster, rtpengine starts
with **zero restarts** on the labeled media-edge node, runs the published image
digest, and its effective interface is
`internal/10.42.0.8!127.0.0.1` — binding the Pod IP and advertising the canonical
public media address, with ng control and metrics still private on the Pod IP.
The `rtpengine-media` NodePort Service has a local Ready endpoint on that node.

Two further blockers prevent the browser acceptance proof:

* **`PRODUCT_DEFECT-24`** — the committed media-edge overlay **cannot be applied**
  with `kubectl apply -k`. Its `configMapGenerator` reads
  `../../../../versions.env`, four levels above the kustomization root, which
  kustomize refuses under the default load restrictor. Only
  `kubectl kustomize --load-restrictor LoadRestrictionsNone` can render it — which
  is exactly what `scripts/media-edge/config-check` uses, so every static check
  passes. `kubectl apply -k` has **no** `--load-restrictor` flag.
* **`PROOF_HARNESS_DEFECT-H`** — there is no committed way to run the browser
  media logic from the host-browser network context. `scripts/t3-media-prover/run`
  is exclusively an in-cluster Job runner, while T3-S3B requires host execution.

The canonical `utcp-local` cluster was preserved by stop/start and restored with
zero drift.

## Repository Baseline

```text
HEAD           08e1a0d (branch main), working tree clean
UTCP_PHASE     T1
make media-edge-config-check / -test        pass
make media-edge-projection-check            pass
make rtpengine-advertised-address-check     pass
make media-config-check                     pass
make security-config-check                  pass
make t3-media-prover-config-check           pass
make image-build-rtpengine                  pass
make check                                  pass
```

## Tool Bootstrap

```text
helm v4.0.3 — fetched to .runtime/tools (gitignored), PATH-scoped to this run,
              `make gateway-config-check` PASS, removed at cleanup, not committed
k3d v5.9.0, k3s v1.35.3-k3s1, kubectl, docker — all repository-pinned
```

## PRODUCT_DEFECT-23 — CLOSED

`08e1a0d` extracts a shared `infrastructure/docker/rtpengine/address-validation`
library and splits the single overloaded validator:

```text
require_bindable_ipv4()        for POD_IP        (still rejects loopback)
require_advertised_for_pod()   for the advertised address (permits loopback,
                               still rejects the Pod IP)
```

Verified against the real image before deployment: the committed
`UTCP_PUBLIC_MEDIA_ADDRESS=127.0.0.1` no longer produces
`invalid UTCP_PUBLIC_MEDIA_ADDRESS for rtpengine advertisement` — the entrypoint
proceeds into rtpengine.

Verified **in-cluster** on the proof cluster:

```text
pod            rtpengine-5f97dd98d6-tffbf
node           k3d-utcp-mediaedge-server-0   (the utcp.dev/media-edge=true node)
podIP          10.42.0.8
ready          True        restarts 0
imageID        utcp-local-registry:5000/utcp/rtpengine
               @sha256:e883b08354f74ec171f308147aa7083761366f380b1158f7840964c7ae8b45bd
published      127.0.0.1:5002/utcp/rtpengine
               @sha256:e883b08354f74ec171f308147aa7083761366f380b1158f7840964c7ae8b45bd
```

Effective process arguments:

```text
--listen-ng=10.42.0.8:2223
--interface=internal/10.42.0.8!127.0.0.1
--port-min=40000
--port-max=40099
--listen-http=10.42.0.8:2224
```

```text
internal bind            Pod IP 10.42.0.8              PASS
advertised address       127.0.0.1                     PASS
effective interface      internal/<POD_IP>!127.0.0.1   PASS
media range              40000-40099                   PASS
ng control               private on the Pod IP         PASS
metrics                  private on the Pod IP         PASS
Pod-IP fallback          none                          PASS
advertisement errors     none in the container log     PASS
media-edge placement     scheduled on the labeled node PASS
rtpengine-media endpoint 10.42.0.8, ready, on that node PASS
```

## Media-Edge Infrastructure — Re-Verified

The proof cluster was rebuilt from the committed
`infrastructure/k3d/cluster-media-edge.yaml`. Scratch copy changed only the
cluster name and the registry host port — the registry **name** was deliberately
left as `utcp-local-registry` (the preserved container was temporarily renamed
and restored afterwards) because the committed overlays bind image references to
that registry name.

```text
UDP mappings on 127.0.0.1        100  (40000-40099)
TCP mappings inside that range   0
0.0.0.0 bindings                 0
2223 / 2224 / 5060 / 8021 / runtime RTP published   0
also published                   127.0.0.1:80/tcp, 443/tcp, 6550 -> 6443/tcp
apiserver arg                    --kube-apiserver-arg=service-node-port-range=30000-40099
node labels                      server-0 utcp.dev/media-edge=true; agents none
containerd mirror                utcp-local-registry:5000 -> http://utcp-local-registry:5000
```

Observed forwarding path, unchanged from the prior run and not re-probed at the
generic UDP level:

```text
host loopback -> k3d serverlb nginx UDP proxy -> media-edge node
              -> NodePort (externalTrafficPolicy Local) -> rtpengine Pod
```

## PRODUCT_DEFECT-24 — Overlay Cannot Be Applied

```text
$ kubectl apply -k infrastructure/kubernetes/overlays/local-media-edge
error: loading KV pairs: env source files: [../../../../versions.env]: security;
file '/…/versions.env' is not in or below
'/…/infrastructure/kubernetes/overlays/local-media-edge'
```

`kubectl kustomize` with the default load restrictor fails identically. The
committed check renders it with the restriction disabled:

```text
scripts/media-edge/config-check:106
  ["kubectl","kustomize","--load-restrictor","LoadRestrictionsNone", str(path)]
```

and `kubectl apply --help` exposes **no** `--load-restrictor` flag (`0` matches).
So the overlay is renderable by the checks but not deployable by the canonical
apply path.

Failed seam: **media-edge projection**.

The proof continued through a diagnostic
`kubectl kustomize --load-restrictor LoadRestrictionsNone | kubectl apply -f -`
in order to establish whether anything further was broken. That is not the
committed path and is not proposed as a fix.

### Correction options (single-authority must be preserved)

The `configMapGenerator` exists to keep `UTCP_PUBLIC_MEDIA_ADDRESS` single-sourced
in `versions.env`. Any correction must keep that property:

1. Have the deployment script render with
   `kubectl kustomize --load-restrictor LoadRestrictionsNone` and pipe to
   `kubectl apply -f -`, making that the canonical documented apply for this
   overlay; or
2. Replace the generator with a small overlay-local env file whose equality with
   `versions.env` is asserted by `media-edge-config-check` — authority stays in
   `versions.env`, enforced by a check rather than by file location; or
3. Inject the value through the existing `rtpengine-config` ConfigMap, which is
   already generated inside an allowed root.

Whichever is chosen, add a check that performs a **plain** `kubectl apply -k
--dry-run=server` (or `kubectl kustomize` with the default restrictor) so
applicability is validated, not just renderability.

## PROOF_HARNESS_DEFECT-H — No Host-Browser Execution Mode

T3-S3B §10 requires the media scenario to execute "in the host-browser network
context, not inside Kubernetes". The committed harness cannot do this:

```text
scripts/t3-media-prover/run   creates Namespace/utcp-proof, the ephemeral Secret,
                              the scenario and CA ConfigMaps, and the one-shot
                              Job, then waits on Job conditions and deletes them
tools/t3-media-prover/prover.mjs   single chromium.launch path with no host mode
scripts/media-edge/            config-check, config-check-test,
                               rtpengine-advertised-address-check — no runner
```

There is no committed host-side runner, and no execution-mode switch. The
external Scenario A and Scenario B therefore have no committed way to run even
once `PRODUCT_DEFECT-24` is fixed.

Failed seam: **host-browser execution harness**.

## Not Executed

```text
application baseline on the proof cluster (postgres was still pulling
  postgres:17.6-alpine from Docker Hub on the fresh cluster)
Playwright MCP natural host-browser login
external Scenario A (candidate, packet path, media, browser BYE)
external Scenario B (readiness marker, runtime BYE)
the four bounded failure cases
the live containment sweep against an allocated media port
```

No proof credentials were created on the proof cluster and `.playwright-mcp/` was
never created.

## Containment Observed

From the published surface of the proof cluster:

```text
externally published   TCP 80, TCP 443, TCP 6550 (API), UDP 40000-40099
not published          UDP 2223, TCP/UDP 2224, UDP 10000-20000,
                       UDP 21000-21099, TCP 8021, runtime SIP 5060
no 0.0.0.0 binding, no Pod-CIDR route, no hostNetwork, no hostPort,
no manual iptables/nftables/socat/Docker-proxy rule was created
```

rtpengine's own runtime confirms control and metrics stay on the Pod IP. The full
containment sweep against a live media session remains unexecuted.

## Cleanup And Restoration

```text
proof cluster deleted           k3d cluster delete utcp-mediaedge
residual containers/networks/volumes   none
preserved registry              renamed away during the run, renamed back, restarted
canonical kubeconfig            backed up and restored
scratch cluster config          removed (.runtime, never committed)
proof kubeconfig                removed
rendered diagnostic manifest    removed
temporary Helm v4.0.3           removed from .runtime/tools
.playwright-mcp/                never created
```

Canonical `utcp-local` restarted and verified:

```text
all workloads Ready              yes
database public tables           41   (unchanged)
tenants                          27   (unchanged)
RuntimeNodes                     110  (unchanged)
pending outbox                   0    (unchanged)
Redis sip/dialog/rtp/media       0/0/0/0 (unchanged)
Asterisk active channels         0
rtpengine sessions / ports_used  0 / 0
kubectl diff -k overlays/local   exit 0 — zero drift
```

## Findings

| Classification | Finding |
|---|---|
| `PASS` | **`PRODUCT_DEFECT-23` closed and proven live** — effective interface `internal/10.42.0.8!127.0.0.1`, Pod Ready with 0 restarts on the media-edge node, imageID matching the published digest, ng and metrics private, no Pod-IP fallback, no advertisement error |
| `PASS` | The corrected validator split (`require_bindable_ipv4` / `require_advertised_for_pod`) behaves correctly for both the bind and advertised addresses |
| `PASS` | Media-edge infrastructure re-verified on a fresh cluster: 100 UDP mappings on `127.0.0.1`, no TCP in range, no `0.0.0.0`, no forbidden publication, `service-node-port-range=30000-40099`, `utcp.dev/media-edge=true` on `server:0` only |
| `PASS` | `rtpengine-media` has a local Ready endpoint on the media-edge node |
| `PASS` | The canonical cluster was preserved through stop/start and restored to zero drift with all state intact |
| **`PRODUCT_DEFECT-24`** | The committed media-edge overlay cannot be applied with `kubectl apply -k`: `configMapGenerator` reads `../../../../versions.env` outside the kustomization root, which kustomize refuses by default. `scripts/media-edge/config-check` renders with `--load-restrictor LoadRestrictionsNone`, and `kubectl apply -k` has no such flag. Seam: **media-edge projection** |
| **`PROOF_HARNESS_DEFECT-H`** | No committed host-browser execution mode exists. `scripts/t3-media-prover/run` is exclusively an in-cluster Job runner and `prover.mjs` has no host path, so the T3-S3B external scenarios cannot be executed as specified. Seam: **host-browser execution harness** |
| `PROOF_LIMITATION` | No committed check validates overlay **applicability** (plain `kubectl apply -k` or default-restrictor render); only renderability with the restriction disabled is checked, which is why the full static suite passes against an overlay that cannot deploy |
| `EXPECTED_BEHAVIOR` | Postgres remained in `ContainerCreating` pulling `postgres:17.6-alpine` on the fresh proof cluster — first-time image pull, not a defect |
| `EXPECTED_BEHAVIOR` | The repository still has no canonical Helm bootstrap despite `gateway-config-check` requiring Helm; a temporary pinned `v4.0.3` binary was used and removed |
| `EXPECTED_BEHAVIOR` | The preserved registry container was temporarily renamed so the proof cluster could own the `utcp-local-registry` name that the committed overlays bind to; it was renamed back and restarted at cleanup |

## Status

```text
T3-S1 = Complete
T3-S2 = Complete
T3-S3A = Complete
T3-S3B = INCOMPLETE
T3-S3 = In Progress
T3 = In Progress
UTCP_PHASE=T1

PRODUCT_DEFECT-23 = closed
PRODUCT_DEFECT-24 = open (media-edge overlay applicability)
PROOF_HARNESS_DEFECT-H = open (no host-browser execution mode)
```

No remote-internet or cloud deployment readiness is claimed, and external browser
media readiness is **not** claimed.

## Recommended Next Step

Two bounded corrections, then rerun this proof from §4:

1. Make the media-edge overlay applicable through a canonical path while keeping
   `UTCP_PUBLIC_MEDIA_ADDRESS` single-sourced, and add a check that validates
   applicability rather than only restriction-disabled renderability.
2. Add a committed host-browser execution mode for the media prover — an explicit
   host runner or execution-mode switch that runs the same unchanged browser
   logic from the developer host against the canonical HTTPS/WSS origins.
