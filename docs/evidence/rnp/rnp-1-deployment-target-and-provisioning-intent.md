# RNP-1 — Deployment Target and Provisioning Intent

## Verdict

RNP-1 is implemented and repository-tested. RNP overall remains in progress.
The packet performs zero Kubernetes resource mutation.

## Authority

The bounded authority is:

    Admin API
        → RuntimeProvisioningService
        → PostgreSQL deployment target and provisioning intent
        → RuntimeRegistryService
        → DRAFT RuntimeNode

RuntimeNode remains the only runtime registry. Existing-runtime registration
was not changed. No controller or service in this packet calls Kubernetes,
kubectl, Helm, or Kustomize.

## Persistence

deployment_targets is tenant-scoped and contains the stable target
discriminator, display identity, and non-secret execution-boundary
configuration. RNP-1 implements exactly local_kubernetes; its configuration
identifies utcp-local / k3d-utcp-local and namespace utcp-runtime. No
kubeconfig or Kubernetes credential is persisted.

runtime_provisioning_requests is tenant-scoped durable intent. It records the
deployment target, supported runtime family and adapter, requested name and
slug, PostgreSQL-backed idempotency identity and fingerprint, requested status,
and the associated RuntimeNode identifier. Foreign keys, tenant-scoped
uniqueness, lookup indexes, and restrict-on-delete relationships preserve
history and prevent dangling references.

## Managed runtime scope

RNP-1 accepts exactly:

    runtime_family = asterisk
    adapter_key   = asterisk-ari

Unsupported managed runtime pairs are rejected explicitly by application
validation. This is the current packet scope, not an environment feature gate
or hidden runtime allowlist. FreeSWITCH provisioning is not implemented.

## RuntimeNode handoff

An accepted request calls RuntimeRegistryService::createNode() and therefore
creates the linked canonical node with:

    desired_state  = draft
    observed_state = unobserved
    endpoints      = none
    credentials    = none

The service does not write runtime_nodes directly and does not fabricate
endpoint or credential placeholders.

## Idempotency and transactionality

The (tenant_id, idempotency_key) unique constraint and request fingerprint
make duplicate requests deterministic:

    same tenant + same key + same request
        → same provisioning intent + same RuntimeNode

    same tenant + same key + different request
        → visible 422 conflict, no success event

RuntimeNode creation and provisioning-intent insertion occur in the same
canonical database transaction. The focused rollback test forces the existing
RuntimeRegistry unique-slug failure after intent validation and verifies that
no additional RuntimeNode or provisioning request remains.

## Tenant isolation

Target listing and inspection, provisioning-target authorization, and
provisioning-request inspection all constrain reads and writes by the active
tenant. A target or request belonging to another tenant is not available to
the requesting tenant.

## API

Authenticated tenant-scoped Admin API routes provide:

    GET  /api/v1/admin/deployment-targets
    GET  /api/v1/admin/deployment-targets/{id}
    POST /api/v1/admin/runtime-provisioning
    GET  /api/v1/admin/runtime-provisioning/{id}

The local target is application-bootstrapped on first target access for the
tenant, so normal operation requires no SQL insertion, CLI command, or manual
enablement.

## Audit and outbox

The target bootstrap records deployment_target.registered. An accepted
request records runtime_provisioning.requested alongside the canonical
runtime_node.created event emitted by RuntimeRegistryService. These writes
are transactional with the accepted operation. Rejected authorization,
validation, target-isolation, idempotency, and RuntimeNode-creation paths emit
no corresponding provisioning success event.

## Verification

Focused command:

    php artisan test tests/Feature/RuntimeProvisioning/RuntimeProvisioningApiTest.php
    → 4 tests passed, 36 assertions

Migration static verification:

    php artisan migrate --pretend --env=testing
    → passed; deployment target and provisioning request DDL rendered

The full repository verification commands and their final results are recorded
below:

    make test
    → passed; API 430 passed (6 skipped), web 149 passed

    make check
    → passed; repository, API formatting, frontend lint, and type checks passed

    git diff --check
    → passed

## Explicit boundary

RNP-1 does not create Deployments, Services, Secrets, ConfigMaps, Pods, or
other Kubernetes resources. It does not change utcp-runtime-fencer RBAC,
NetworkPolicies, ServiceAccounts, the infrastructure worker, the external
RuntimeNode registration path, the RNM lifecycle, C7, T6, V0, or any RNP-2+
behavior.
