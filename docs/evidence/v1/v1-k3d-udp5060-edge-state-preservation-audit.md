# V1 — k3d UDP/5060 Edge State-Preservation and Activation Audit

Narrow evidence audit. Read-only. No cluster, Docker, PVC, PV, or deployment
mutation was performed.

Date: 2026-08-25
Branch: `main`
HEAD: `234d8ae`
Phase marker (`versions.env`): `UTCP_PHASE=T1`
Verdict: `V1_K3D_EDGE_ACTIVATION_STATE_PRESERVATION_IMPLEMENTATION_REQUIRED`

Question answered:

> Can the existing UTCP k3d cluster safely gain the already-approved immutable
> UDP/5060 serverlb publication while preserving all material PostgreSQL/Redis
> and other persistent state, and if not, what single bounded repository change
> is required to make that lifecycle safe?

---

## 1. Cluster lifecycle authority

| Concern | Authority | Behavior |
| --- | --- | --- |
| Create | `make k3d-create` → `scripts/k3d/create` | `k3d cluster create --config infrastructure/k3d/cluster.yaml`; no-ops if the cluster exists |
| Delete | `make k3d-delete` → `scripts/k3d/delete` | `k3d cluster delete utcp-local` **and** `k3d registry delete utcp-local-registry` |
| Recreate | `make k3d-recreate-proof` → `scripts/k3d/recreate-proof` | `delete` → `create` → `registry-proof` |
| Stop (state-preserving) | `make local-down` → `scripts/local/down` | `k3d cluster stop utcp-local` only |
| Verify | `make k3d-verify` → `scripts/k3d/verify` | asserts serverlb publications |

`scripts/k3d/create` is **not** idempotent with respect to configuration: it
short-circuits on `cluster_exists`, so an already-running cluster never picks up
a changed `cluster.yaml`. There is therefore no in-place reconcile path in the
repository today.

The repository classifies its own recreate path as destructive. `Makefile:605`:

```make
ci-k3d: k3d-config-check k3d-recreate-proof ## Run the destructive local K0 k3d proof for utcp-local.
```

`scripts/k3d/recreate-proof` guards only two things — Compose project state and
the global kubectl context. It performs **no** Kubernetes data preservation and
makes no data claim.

---

## 2. Current persistent inventory

Cluster `utcp-local`, 22 days old, 1 server + 2 agents + serverlb + registry.
StorageClass: `local-path` (`rancher.io/local-path`), default,
`reclaimPolicy: Delete`, `WaitForFirstConsumer`.

| Resource | Namespace | PVC | Capacity | Node | Backing |
| --- | --- | --- | --- | --- | --- |
| PostgreSQL | `utcp-data` | `postgres-data-postgres-0` | 1Gi | `agent-1` | anonymous Docker volume |
| Redis | `utcp-data` | `redis-data-redis-0` | 512Mi | `agent-0` | anonymous Docker volume |
| Prometheus | `utcp-observability` | `prometheus-…-db-…-0` | 2Gi | `agent-1` | anonymous Docker volume |
| Alertmanager | `utcp-observability` | `alertmanager-…-db-…-0` | 256Mi | `agent-1` | anonymous Docker volume |
| Grafana | `utcp-observability` | `kube-prometheus-stack-grafana` | 512Mi | — | anonymous Docker volume |
| Loki | `utcp-observability` | `storage-loki-0` | 2Gi | — | anonymous Docker volume |

All six PVCs are `Bound`; all six PVs carry `persistentVolumeReclaimPolicy: Delete`.

---

## 3. Storage backing — where the bytes actually live

This is the decisive finding and it was proven, not assumed.

Each PV is a `spec.local` path pinned by `nodeAffinity` to a single k3d node:

```yaml
# pvc-856a1a2f-…  (utcp-data/postgres-data-postgres-0)
spec:
  local:
    path: /var/lib/rancher/k3s/storage/pvc-856a1a2f-…_utcp-data_postgres-data-postgres-0
  nodeAffinity:
    required:
      nodeSelectorTerms:
        - matchExpressions:
            - key: kubernetes.io/hostname
              operator: In
              values: [k3d-utcp-local-agent-1]
  persistentVolumeReclaimPolicy: Delete
```

`docker inspect` of each node shows `/var/lib/rancher/k3s` is a **Docker volume**,
not a host bind mount and not the container writable layer:

```text
k3d-utcp-local-agent-0  volume 17fe62c1ceda… -> /var/lib/rancher/k3s
k3d-utcp-local-agent-1  volume 08d69eebd3d1… -> /var/lib/rancher/k3s
k3d-utcp-local-server-0 volume 1350da5cfc39… -> /var/lib/rancher/k3s
k3d-utcp-local-serverlb (no /var/lib/rancher/k3s mount)
```

Those volumes are **anonymous**:

```json
{
  "Name": "08d69eebd3d12ec4a92f676e8663e57fcf299c85a74910ee368d92dc3f3bb470",
  "Labels": { "com.docker.volume.anonymous": "" }
}
```

Contrast with the one k3d-managed named volume, which carries ownership labels:

```json
{
  "Name": "k3d-utcp-local-images",
  "Labels": { "app": "k3d", "k3d.cluster": "utcp-local", "k3d.version": "v5.9.0" }
}
```

`infrastructure/k3d/cluster.yaml` declares **no** `volumes:` stanza, so nothing
binds `/var/lib/rancher/k3s` to a named volume or host path.

Answer to the Phase 3 classification: **(A)+(B) combined — the data lives in
anonymous Docker volumes that exist only for the lifetime of the specific node
containers, and are not addressable by any recreated cluster.**

Measured live contents:

```text
agent-1: /var/lib/rancher/k3s/storage/pvc-856a1a2f-…_utcp-data_postgres-data-postgres-0
         pgdata   1.6G
agent-0: /var/lib/rancher/k3s/storage/pvc-1b8f2fc3-…_utcp-data_redis-data-redis-0
         appendonlydir  dump.rdb   63M
server-0: /var/lib/rancher/k3s/server/db/state.db (+ -shm, -wal)   49M
```

---

## 4. k3d deletion effect

`scripts/k3d/delete` removes the node containers and the registry. Two
independent consequences make recreation destructive:

1. **Anonymous volumes are container-scoped.** k3d's Docker runtime removes
   nodes with `RemoveVolumes: true`, which deletes anonymous volumes.
2. **Even if an anonymous volume survived as an orphan, nothing would reattach
   it.** New node containers receive *new* anonymous volumes. `cluster.yaml`
   declares no volume mapping, and the PV `local.path` + `nodeAffinity` refer to
   a node identity that is recreated empty. The orphan would be unreachable
   without manual out-of-band copying.

Point 2 holds regardless of point 1, so the conclusion does not depend on k3d's
internal removal flag.

Destroyed by `k3d-recreate-proof`:

- PostgreSQL `pgdata` (1.6 GB) — all canonical UTCP desired state, identity,
  tenancy, runtime registry, operations, audit history
- Redis AOF + RDB (63 MB)
- k3s datastore `state.db` (49 MB) — every Kubernetes object, including all
  Secrets, PVs, PVCs
- Prometheus/Alertmanager/Loki/Grafana history
- Local registry contents (`k3d registry delete` in `scripts/k3d/delete`)

Survives: nothing inside the cluster. `k3d-utcp-local-images` is a named volume
but holds only image import scratch, not application data.

---

## 5. PostgreSQL preservation

**Does not survive `k3d cluster delete`. No recovery path exists.**

- Persistence: StatefulSet `utcp-data/postgres`, PVC `postgres-data-postgres-0`,
  PV `pvc-856a1a2f-…`, local-path on `agent-1`, `reclaimPolicy: Delete`.
- Recreated Kubernetes objects would **not** bind back to the same data. The
  PVC would be provisioned fresh and PostgreSQL would run `initdb` into an empty
  directory.
- **No repository backup mechanism exists.** A repository-wide search for
  `pg_dump|pg_restore|backup|restore` across `scripts/`, `Makefile`,
  `infrastructure/`, and `docs/runbooks/` returns no PostgreSQL backup or
  restore authority. Every hit is unrelated (alert rules, a user-access-recovery
  runbook, proof scripts using the word "restore" in another sense).
- `make k8s-persistence-proof` proves persistence across **Pod replacement**
  only (`Makefile:317`), not across cluster recreation. It must not be cited as
  evidence for this question.

---

## 6. Redis preservation

**Does not survive. Not disposable by contract.**

- Persistence: StatefulSet `utcp-data/redis`, PVC `redis-data-redis-0`,
  local-path on `agent-0`.
- On-disk state is real and AOF+RDB-backed: `appendonlydir` and `dump.rdb`
  totalling 63 MB.
- Per `CLAUDE.md`, Redis owns "queues, locks, caching, and transient
  projections" and is explicitly **not** canonical business storage. Its
  contents are therefore reconstructable in principle, and its loss is
  materially less severe than PostgreSQL's.
- However, loss is not free: in-flight queue entries, leases, and locks would be
  discarded. Because PostgreSQL owns retry lifecycle and operations, the control
  plane can rebuild — but only if PostgreSQL itself survives, which it does not
  under the recreate path.

Classification: **reconstructable, but only conditional on PostgreSQL survival.**

---

## 7. Registry preservation

`scripts/k3d/delete` explicitly deletes the registry:

```bash
if registry_exists; then
  k3d registry delete "$K3D_REGISTRY_NAME"
```

The registry is a separate k3d-managed container (`utcp-local-registry`,
`registry:3.0.0`, host `127.0.0.1:5001`). It is destroyed and recreated empty.

This is an **image-redeployment** cost, not application-data loss: every image is
rebuildable via `make k8s-image-build` + `k8s-image-push`. It must not be
conflated with PostgreSQL/Redis preservation.

---

## 8. Secret / ConfigMap reconstruction

All Kubernetes objects live in `state.db` on `server-0` and are destroyed with it.

| Class | Examples | Recoverable? |
| --- | --- | --- |
| Declaratively reproducible | `utcp-platform/utcp-local-*-credentials`, `utcp-data/utcp-local-data-credentials`, ConfigMaps | Yes — overlay generators via `make k8s-apply` |
| Repository lifecycle | `traefik-system/utcp-local-gateway-tls` | Yes — `make gateway-tls-apply` |
| Chart-generated | `utcp-observability/*` (Prometheus/Alertmanager TLS assets, `utcp-grafana-admin`) | Yes — regenerated on install |
| **Runtime-only, generated** | `utcp-runtime/asterisk-*-credentials`, `utcp-runtime/freeswitch-*-credentials` | **No** |

The `utcp-runtime/*-credentials` Secrets are produced by the managed-runtime
provisioning lifecycle and are paired with RuntimeNode rows in PostgreSQL. They
are not declarative and cannot be regenerated from the repository. Among them is
`asterisk-rnp6-readiness-reproof-20260809-…`, a deliberately retained proof
fixture.

Because PostgreSQL is destroyed in the same operation, Secret loss and row loss
are consistent rather than divergent — but retained fixtures and accumulated
proof state are permanently gone. No values were read or printed.

---

## 9. UDP/5060 mapping mutability

**`SUPPORTED_NONDESTRUCTIVE_UPDATE_AVAILABLE`**

Installed: `k3d version v5.9.0`. Two experimental commands publish a new
serverlb port on an **existing** cluster:

```text
k3d node edit NODE --port-add [HOST:][HOSTPORT:]CONTAINERPORT[/PROTOCOL][@NODEFILTER]
    [EXPERIMENTAL] (serverlb only!) Map ports from the node container to the host

k3d cluster edit CLUSTER --port-add …
    Map ports from the node containers (via the serverlb) to the host
```

Both operate **only on the serverlb**. Evidence that this is state-safe:

- `k3d-utcp-local-serverlb` has exactly one mount, the named volume
  `k3d-utcp-local-images`. It has **no** `/var/lib/rancher/k3s` mount and holds
  no persistent state — it is an nginx/confd front end.
- Server and agent containers, and therefore all anonymous volumes carrying
  PostgreSQL, Redis, and the k3s datastore, are not touched.

Current drift is confirmed: serverlb publishes `80`, `443`, `6443→6550`, and
`40000-40099/udp`, but **no 5060 mapping**, exactly as
`scripts/k3d/verify:112` asserts.

Residual risk (not proven here, because execution is out of scope): the
operation recreates the serverlb container, so it must faithfully carry forward
all 104 existing publications, and it briefly interrupts `80/443/6550` and the
RTP range. The serverlb container labels do not store an explicit port list, so
correctness depends on k3d copying the live node spec. This is precisely why the
operation needs a verified repository wrapper rather than a bare CLI call.

`k3d cluster stop`/`start` (`make local-down` / `local-up`) cannot help: Docker
port bindings are fixed at container creation, so a stop/start cycle reuses the
existing serverlb container unchanged.

---

## 10. Second, independent activation gap

`kamailio-sip-external` is implemented in the repository but **is not present in
the live cluster**:

```text
utcp-platform  kamailio                ClusterIP  8080/TCP
utcp-platform  kamailio-sip-internal   ClusterIP  5060/UDP
(no kamailio-sip-external)
```

The manifest exists at
`infrastructure/kubernetes/base/platform/kamailio-sip-external-service.yaml`
(NodePort 30560) and is already referenced by
`infrastructure/kubernetes/base/platform/kustomization.yaml:23`, which the local
overlay includes at `overlays/local/kustomization.yaml:31` and
`overlays/local/platform/kustomization.yaml:24`.

So activation requires **two** non-destructive steps, not one:

1. `make k8s-apply` — creates the NodePort Service (ordinary declarative deploy)
2. serverlb port publication — the k3d `--port-add` operation above

Without step 1 the host mapping would forward to a NodePort that does not exist.

All V1 edge work is currently uncommitted: `infrastructure/k3d/cluster.yaml` (M),
`base/platform/kustomization.yaml` (M), `scripts/k3d/{config-check,config-check-test,verify}` (M),
`kamailio-sip-external-service.yaml` (??), `scripts/v1/` (??), `docs/evidence/v1/` (??).

---

## 11. Safe recreation capability

**`BOUNDED_IMPLEMENTATION_REQUIRED`**

The destructive path is not merely unproven — it is proven unsafe: 1.6 GB of
canonical PostgreSQL state with no backup mechanism anywhere in the repository.

The non-destructive path exists in the installed toolchain but has no repository
lifecycle, no Make target, no verification, and no regression test. Invoking it
bare would be an improvised execution path outside the canonical lifecycle,
which `CLAUDE.md` prohibits.

Exactly one bounded repository implementation closes the gap.

### Bounded implementation packet

**New:** `scripts/k3d/edge-activate`

- Preflight: assert context `k3d-utcp-local`, cluster running, and record the
  complete current serverlb publication set.
- Assert the target mapping is declared in `infrastructure/k3d/cluster.yaml`
  (`0.0.0.0:5060:30560/udp`) — the file stays the source of truth.
- Idempotent no-op when `0.0.0.0:5060->30560/udp` is already published.
- Assert `Service/utcp-platform/kamailio-sip-external` exists with nodePort
  30560 before publishing, so the host port never leads to a missing NodePort.
- Execute `k3d node edit k3d-utcp-local-serverlb --port-add 0.0.0.0:5060:30560/udp`.
- Postflight: assert every previously recorded publication is still present,
  the new mapping is present, all three nodes still `Ready`, and both
  `utcp-data` StatefulSets still `1/1`. Fail loudly on any regression.
- Never call `scripts/k3d/delete`, `k3d cluster delete`, or `k3d cluster create`.

**New:** `scripts/k3d/edge-activate-test` — mutation regression coverage in the
style of `scripts/k3d/config-check-test`.

**Modified:** `Makefile` — add `k3d-edge-activate` and `k3d-edge-activate-test`
targets plus `.PHONY` entries.

**Unchanged:** `infrastructure/k3d/cluster.yaml` (already correct),
`kamailio-sip-external-service.yaml`, `scripts/k3d/verify` (already asserts the
target state and becomes the acceptance check).

Explicitly **not** in scope: any PostgreSQL backup/restore tooling. It is
unnecessary once activation avoids recreation, and building it would be
speculative disaster-recovery work the roadmap does not call for.

---

## 12. Rejected approaches

| Approach | Why rejected |
| --- | --- |
| `make k3d-recreate-proof` | Destroys 1.6 GB PostgreSQL, 63 MB Redis, 49 MB k3s datastore, all runtime Secrets, registry. No backup exists. |
| Backup → recreate → restore | Requires building absent `pg_dump`/`pg_restore` lifecycle **and** still destroys runtime-only Secrets and retained fixtures. Larger and riskier than the serverlb-only edit. |
| `k3d cluster stop`/`start` | Docker port bindings are immutable per container; reuses the same serverlb unchanged. |
| socat / iptables DNAT / docker-proxy manipulation / `kubectl port-forward` / sidecar proxy | Prohibited. Each creates a competing runtime authority outside the canonical edge. |
| Manual `docker run` republish of serverlb | Bypasses k3d node management and repository validation. |

---

## 13. Unrelated conditions (classified, not repaired)

- `make gateway-config-check` cannot run because `helm` is unavailable on this
  host. **Does not affect this question.** The selected lifecycle touches only
  the k3d serverlb and a Kustomize-managed Service; no Helm chart is involved.
- `make media-config-check` — pre-existing T3-S1 assertion.
- `make kamailio-signaling-config-check-test` — pre-existing secondary-Asterisk
  selector mutation divergence.

Neither invalidates any storage or recreation evidence above.

---

## 14. Conclusion

- Failure classification: **`STATE_PRESERVATION_IMPLEMENTATION_GAP`**
- Verdict: **`V1_K3D_EDGE_ACTIVATION_STATE_PRESERVATION_IMPLEMENTATION_REQUIRED`**
- Next action: implement `scripts/k3d/edge-activate` (+ test, + Make targets).
- Next AI coder: **Codex** (bounded repository implementation).
- V1 status: `V1_REMAINS_ACTIVE`

Deferred live-proof prerequisites, out of scope here and not blockers for the
implementation step: SIP credential rotation
(`.env-external-pbx-sip-credentials`, untracked, mode 600 — not read in this
audit) and upstream router UDP/5060 forwarding to `192.168.86.181`.
