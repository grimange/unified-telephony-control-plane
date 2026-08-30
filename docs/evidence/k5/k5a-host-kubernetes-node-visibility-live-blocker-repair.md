# K5A Host / Kubernetes Node Visibility — Live-Blocker Repair

Current-State-Impact: yes

## Scope and authority

This bounded implementation repairs the two independent blockers recorded by
the 2026-08-30 native-k3s proof. Kubernetes remains authoritative for Nodes,
Pods, placement, and read-only infrastructure facts; the API has no durable
duplicate Host authority. The existing read-only `utcp-api` identity and
`utcp-infrastructure-reader` RBAC remain unchanged.

## NetworkPolicy repair

The API policy had Kubernetes API endpoint placeholders in a non-template
`allow-api.yaml`, so the endpoint rule was not rendered. The API Deployment is
now labeled `utcp.io/kubernetes-api-client: "true"` and its placeholder rule is
removed. It therefore uses the existing rendered
`allow-runtime-fencer-kubernetes-api.template.yaml` policy and the same
security apply substitution for the discovered Kubernetes API endpoint and
port. No broad egress or manually maintained address was added.

The security regression verifies the API workload label, rejects unresolved
endpoint markers in the apply-ready rendered policy, and checks the rendered
endpoint CIDR/port. Existing security restrictions remain enforced.

## Identity catalog repair

K5A already added `platform.infrastructure.view` to configuration, but a
pre-existing database never reran the original catalog seeding migration. The
forward migration
`2026_08_30_100000_sync_k5a_identity_catalog.php` follows the established
C7B catalog-sync pattern, upserting the capability and its
`platform-admin` role mapping. The focused upgrade test removes both rows,
runs the migration twice, and verifies database-backed authorization, covering
an already-migrated catalog rather than only a fresh database. Fresh installs
continue to seed the current catalog through the normal migration chain.

## Validation and remaining proof

Focused security/configuration checks, offline Kustomize rendering, the
identity upgrade regression, the full API suite, repository hygiene, and phase
status consistency passed. The focused infrastructure/migration run passed 16
tests with 153 assertions; `make image-test-api` passed 652 tests with 9
expected skips and 5,307 assertions. `make security-config-check-test`,
`make server-config-check`, `make phase-status-consistency-check`, and
`make repository-hygiene` passed, and offline local-overlay Kustomize rendering
contained no unresolved Kubernetes API marker. The live `make
security-config-check` remains environment-limited because this workspace has
no Kubernetes API at `127.0.0.1:6550`; it did complete the static policy checks
before that connection-dependent endpoint check. No live Kubernetes or browser
proof was performed, and no K5B–K5F behavior, V1 behavior, host desired state,
or deployment convergence workaround was added. The next action is a Claude
Code controlled natural K5A live proof on canonical native k3s.
