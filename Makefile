.DEFAULT_GOAL := help

COMPOSER_CACHE_DIR ?= /tmp/utcp-composer-cache
NPM_CONFIG_CACHE ?= /tmp/utcp-npm-cache
UTCP_API_IMAGE ?= utcp-api:dev
UTCP_WEB_IMAGE ?= utcp-web:dev
UTCP_BUILD_VERSION ?= 0.1.0-dev
UTCP_BUILD_COMMIT ?= unknown
UTCP_BUILD_CREATED ?= unknown
UTCP_IMAGE_SOURCE ?= local
UTCP_GATEWAY_IMAGE ?= utcp-gateway:dev
UTCP_COMPOSE_PROJECT_NAME ?= utcp-debug
UTCP_GATEWAY_PORT ?= 18088
COMPOSE_FILE ?= infrastructure/compose/compose.yaml
COMPOSE_ENV_FILES ?= --env-file versions.env --env-file infrastructure/compose/env.example
COMPOSE ?= docker compose $(COMPOSE_ENV_FILES) -f $(COMPOSE_FILE) -p $(UTCP_COMPOSE_PROJECT_NAME)

.PHONY: help doctor repository-hygiene workflow-check security-audit secret-scan api-install api-test api-check web-install web-test web-lint web-typecheck web-build install test check build image-build-api image-build-web image-test-api image-test-web image-smoke-api image-smoke-web image-smoke image-inspect container-check local-config-check local-up local-status local-proof local-down compose-config compose-build compose-debug-up compose-up compose-status compose-logs compose-test compose-proof compose-ci compose-down compose-reset k3d-config-check k3d-registry-check-test k3d-create k3d-status k3d-verify k3d-registry-proof k3d-recreate-proof k3d-delete k8s-config-check k8s-image-build k8s-image-push k8s-apply k8s-status k8s-proof k8s-persistence-proof k8s-restart-proof k8s-delete gateway-config-check gateway-crds-install traefik-install gateway-tls gateway-tls-apply gateway-apply gateway-status gateway-proof gateway-delete security-config-check security-apply security-status security-proof security-delete observability-config-check observability-install observability-apply observability-status observability-proof observability-persistence-proof observability-delete control-plane-config-check control-plane-test control-plane-migrate-proof control-plane-proof control-plane-status identity-config-check identity-test identity-bootstrap-local identity-api-proof identity-browser-proof identity-status runtime-registry-config-check runtime-registry-test runtime-registry-api-proof runtime-registry-browser-proof runtime-registry-status runtime-engine-config-check runtime-engine-test runtime-engine-proof runtime-engine-status simulator-config-check simulator-test simulator-api-proof simulator-runtime-proof simulator-status ci ci-check ci-k3d k3d-ci

help: ## Show available commands.
	@awk 'BEGIN {FS = ":.*##"; printf "UTCP commands:\n"} /^[a-zA-Z0-9_.-]+:.*##/ {printf "  %-24s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

doctor: ## Inspect local development tools without modifying the host.
	@./scripts/doctor

repository-hygiene: ## Run the Phase F0 repository hygiene checks.
	@./scripts/check-repository-hygiene

workflow-check: ## Validate GitHub Actions syntax and immutable action pinning.
	@./scripts/ci/workflow-check

security-audit: ## Run dependency audits and secret-oriented repository checks.
	@./scripts/ci/dependency-audit
	@./scripts/ci/secret-scan

secret-scan: ## Run the secret-oriented repository scanner.
	@./scripts/ci/secret-scan

api-install: ## Install Laravel API dependencies.
	@cd apps/api && COMPOSER_CACHE_DIR=$(COMPOSER_CACHE_DIR) composer install

api-test: ## Run Laravel API tests.
	@cd apps/api && php artisan test

api-check: ## Validate Laravel API configuration, routes, and formatting.
	@cd apps/api && php artisan config:clear
	@cd apps/api && php artisan route:list --path=api/health
	@cd apps/api && php artisan route:list --path=api/version
	@cd apps/api && vendor/bin/pint --test

web-install: ## Install Vue web dependencies.
	@cd apps/web && NPM_CONFIG_CACHE=$(NPM_CONFIG_CACHE) npm ci

web-test: ## Run Vue web component tests.
	@cd apps/web && npm run test

web-lint: ## Run Vue web lint checks.
	@cd apps/web && npm run lint

web-typecheck: ## Run Vue web TypeScript checks.
	@cd apps/web && npm run typecheck

web-build: ## Build the Vue web application for production.
	@cd apps/web && npm run build

install: api-install web-install ## Install all application dependencies.

test: api-test web-test ## Run backend and frontend tests.

check: repository-hygiene api-check web-lint web-typecheck ## Run repository, backend, and frontend checks.

build: web-build ## Build deployable application artifacts introduced so far.

image-build-api: ## Build the production Laravel API image.
	docker build \
		--file infrastructure/docker/api/Dockerfile \
		--target app-prod \
		--tag $(UTCP_API_IMAGE) \
		--build-arg BUILD_VERSION=$(UTCP_BUILD_VERSION) \
		--build-arg BUILD_COMMIT=$(UTCP_BUILD_COMMIT) \
		--build-arg BUILD_CREATED=$(UTCP_BUILD_CREATED) \
		--build-arg IMAGE_SOURCE=$(UTCP_IMAGE_SOURCE) \
		.

image-build-web: ## Build the production Vue web image.
	docker build \
		--file infrastructure/docker/web/Dockerfile \
		--target app-prod \
		--tag $(UTCP_WEB_IMAGE) \
		--build-arg BUILD_VERSION=$(UTCP_BUILD_VERSION) \
		--build-arg BUILD_COMMIT=$(UTCP_BUILD_COMMIT) \
		--build-arg BUILD_CREATED=$(UTCP_BUILD_CREATED) \
		--build-arg IMAGE_SOURCE=$(UTCP_IMAGE_SOURCE) \
		.

image-build: image-build-api image-build-web ## Build all production images.

image-test-api: ## Run Laravel tests in the backend container test stage.
	docker build \
		--file infrastructure/docker/api/Dockerfile \
		--target test \
		--tag $(UTCP_API_IMAGE)-test \
		.
	docker run --rm \
		-e APP_ENV=testing \
		-e DB_CONNECTION=sqlite \
		-e DB_DATABASE=:memory: \
		-e DB_URL= \
		$(UTCP_API_IMAGE)-test php artisan test

image-test-web: ## Run Vue tests, lint, type checks, and build in the frontend container test stage.
	docker build \
		--file infrastructure/docker/web/Dockerfile \
		--target test \
		--tag $(UTCP_WEB_IMAGE)-test \
		.

image-test: image-test-api image-test-web ## Run all containerized test stages.

image-smoke-api: image-build-api ## Smoke-test the backend production image.
	./scripts/container/api-smoke $(UTCP_API_IMAGE)

image-smoke-web: image-build-web ## Smoke-test the frontend production image.
	./scripts/container/web-smoke $(UTCP_WEB_IMAGE)

image-smoke: image-smoke-api image-smoke-web ## Run all production image smoke checks.

image-inspect: ## Inspect useful immutable image metadata.
	UTCP_API_IMAGE=$(UTCP_API_IMAGE) UTCP_WEB_IMAGE=$(UTCP_WEB_IMAGE) UTCP_GATEWAY_IMAGE=$(UTCP_GATEWAY_IMAGE) ./scripts/container/image-inspect

container-check: repository-hygiene check build image-build image-test image-smoke image-inspect ## Run the full bounded F2 validation suite.

local-config-check: ## Validate the local Kubernetes authority cutoff without mutation.
	@./scripts/local/config-check

local-up: ## Start or reconcile the canonical Kubernetes local runtime; never starts Compose.
	@./scripts/local/up

local-status: ## Report canonical Kubernetes local runtime state and Compose authority drift.
	@./scripts/local/status

local-proof: ## Run the canonical Kubernetes proof corridor; never starts Compose.
	@./scripts/local/proof

local-down: ## Stop only the utcp-local k3d cluster; preserve registry and data.
	@./scripts/local/down

compose-config: ## Validate the resolved disposable/debug Docker Compose configuration.
	$(COMPOSE) config --quiet
	$(COMPOSE) config --format json | scripts/compose/static_check.py

compose-build: ## Build all images required by disposable/debug Docker Compose.
	$(COMPOSE) build

compose-debug-up: ## Explicit optional isolated Compose debug runtime; Kubernetes remains canonical.
	UTCP_GATEWAY_PORT=$(UTCP_GATEWAY_PORT) $(COMPOSE) up -d --build --wait --wait-timeout 180
	@printf 'Optional UTCP Compose debug runtime available at http://127.0.0.1:%s\n' "$(UTCP_GATEWAY_PORT)"

compose-up: compose-debug-up ## Compatibility alias for explicit Compose debug mode, not canonical local runtime.

compose-status: ## Show the optional Compose debug project service and health status.
	$(COMPOSE) ps

compose-logs: ## Show Docker Compose logs; pass SERVICE=name to limit output.
	$(COMPOSE) logs --tail=200 $(SERVICE)

compose-test: image-test ## Run containerized application tests without destroying Compose data.

compose-proof: ## Run a disposable isolated Compose compatibility proof and clean up afterward.
	@./scripts/compose/compose-proof

compose-ci: ## Run isolated CI Compose proof with project utcp-ci and port 18088, then clean it up.
	@./scripts/ci/compose-ci

compose-down: ## Stop the optional Compose debug project while preserving named volumes.
	$(COMPOSE) down --remove-orphans

compose-reset: ## Destroy the optional Compose debug project and named data volumes; requires CONFIRM=destroy-compose-data.
	@if [ "$(CONFIRM)" != "destroy-compose-data" ]; then \
		printf 'compose-reset destroys local Compose containers and named data volumes. Re-run with CONFIRM=destroy-compose-data.\n' >&2; \
		exit 2; \
	fi
	$(COMPOSE) down --volumes --remove-orphans

k3d-config-check: ## Validate the checked-in k3d cluster configuration without creating a cluster.
	@./scripts/k3d/config-check

k3d-registry-check-test: ## Run focused K0 registry container verification regression tests.
	@./scripts/k3d/test-registry-container-check

k3d-create: ## Create or validate the local utcp-local k3d cluster and repository kubeconfig.
	@./scripts/k3d/create

k3d-status: ## Report local k3d cluster, node, namespace, registry, and kubeconfig status.
	@./scripts/k3d/status

k3d-verify: ## Verify the K0 local k3d cluster foundation.
	@./scripts/k3d/verify

k3d-registry-proof: ## Prove local registry push and in-cluster image pull.
	@./scripts/k3d/registry-proof

k3d-recreate-proof: ## Delete and recreate only the UTCP-owned k3d cluster, then verify it.
	@./scripts/k3d/recreate-proof

k3d-delete: ## Delete only the UTCP-owned k3d cluster, registry, and repository kubeconfig.
	@./scripts/k3d/delete

k8s-config-check: ## Render and validate the local K1 Kustomize overlay without mutating the cluster.
	@./scripts/kubernetes/config-check

k8s-image-build: ## Build API, web, and gateway images for local Kubernetes.
	@./scripts/kubernetes/image-build

k8s-image-push: ## Push K1 images to the UTCP local k3d registry.
	@./scripts/kubernetes/image-push

k8s-apply: ## Deploy the K1 application base to the utcp-local k3d cluster.
	@./scripts/kubernetes/apply

k8s-status: ## Report K1 StatefulSets, Deployments, Pods, Services, jobs, PVCs, and images.
	@./scripts/kubernetes/status

k8s-proof: ## Prove K1 through a temporary gateway port-forward on 127.0.0.1:18089.
	@./scripts/kubernetes/proof

k8s-persistence-proof: ## Prove local PostgreSQL and Redis PVC persistence across Pod replacement.
	@./scripts/kubernetes/persistence-proof

k8s-restart-proof: ## Prove application Deployments recover from Pod replacement.
	@./scripts/kubernetes/restart-proof

k8s-delete: ## Delete only K1 Kubernetes resources; preserve PVCs unless CONFIRM=delete-k1-pvcs.
	@./scripts/kubernetes/delete

gateway-config-check: ## Validate K2 Traefik and Gateway API configuration without mutating the cluster.
	@./scripts/gateway/config-check

gateway-crds-install: ## Install pinned Gateway API Standard CRDs.
	@./scripts/gateway/install-crds

traefik-install: ## Install or upgrade pinned Traefik through Helm.
	@./scripts/gateway/install-traefik

gateway-tls: gateway-tls-apply ## Alias for applying the prepared local Gateway TLS certificate.

gateway-tls-apply: ## Apply and verify the prepared trusted local Gateway TLS certificate.
	@./scripts/gateway/tls-apply

gateway-apply: ## Apply the K2 Traefik and Gateway API lifecycle.
	@./scripts/gateway/apply

gateway-status: ## Report K2 Traefik, Gateway API, route, TLS, and edge status.
	@./scripts/gateway/status

gateway-proof: ## Prove K2 external HTTP/HTTPS Gateway behavior.
	@./scripts/gateway/proof

gateway-delete: ## Remove K2 Gateway resources and TLS Secret; preserve K1 and CRDs by default.
	@./scripts/gateway/delete

security-config-check: ## Validate K3 Pod Security, NetworkPolicy, RBAC, and service-account boundaries without mutating the cluster.
	@./scripts/security/config-check

security-apply: ## Apply the K3 Kubernetes network and security boundaries.
	@./scripts/security/apply

security-status: ## Report K3 Pod Security labels, NetworkPolicies, RBAC, service accounts, and workload readiness.
	@./scripts/security/status

security-proof: ## Prove K3 positive and negative connectivity, Pod Security rejection, and K2 regression behavior.
	@./scripts/security/proof

security-delete: ## Remove only K3-owned policies and generated files; this reopens namespace networking.
	@./scripts/security/delete

observability-config-check: ## Validate K4 observability charts, manifests, dashboards, policies, and RBAC without mutating the cluster.
	@./scripts/observability/config-check

observability-install: ## Install or upgrade pinned K4 observability Helm releases.
	@./scripts/observability/install

observability-apply: ## Apply the K4 observability lifecycle.
	@./scripts/observability/apply

observability-status: ## Report K4 observability workloads, targets, dashboards, alerts, policies, and RBAC.
	@./scripts/observability/status

observability-proof: ## Prove K4 metrics, logs, dashboards, alerts, and K3/K2 regressions.
	@./scripts/observability/proof

observability-persistence-proof: ## Prove Prometheus, Loki, and Grafana survive Pod replacement without PVC deletion.
	@./scripts/observability/persistence-proof

observability-delete: ## Remove K4-owned resources and Helm releases while retaining observability PVCs.
	@./scripts/observability/delete

control-plane-config-check: ## Validate C0 application-kernel boundaries without mutating runtime state.
	@./scripts/control-plane/config-check

control-plane-test: ## Run focused C0 unit and PostgreSQL integration tests.
	@./scripts/control-plane/test

control-plane-migrate-proof: ## Prove C0 migrations against a disposable PostgreSQL database.
	@./scripts/control-plane/migrate-proof

control-plane-proof: ## Prove C0 idempotency, leasing, fencing, outbox, inbox, and audit behavior.
	@./scripts/control-plane/proof

control-plane-status: ## Report non-sensitive C0 schema and kernel counts.
	@./scripts/control-plane/status

identity-config-check: ## Validate C1 identity, tenancy, authorization, route, and boundary configuration.
	@./scripts/identity/config-check

identity-test: ## Run focused C1 identity, tenancy, authorization, and session tests.
	@./scripts/identity/test

identity-bootstrap-local: ## Create or reuse the bounded local C1 bootstrap administrator and tenant.
	@./scripts/identity/bootstrap-local

identity-api-proof: ## Prove C1 API session, CSRF, tenant, admin, and logout behavior.
	@./scripts/identity/api-proof

identity-browser-proof: ## Prove C1 natural browser login and web-admin workflows.
	@./scripts/identity/browser-proof

identity-status: ## Report non-sensitive C1 identity, tenant, membership, role, and session counts.
	@./scripts/identity/status

runtime-registry-config-check: ## Validate C2 runtime-node registry schema, routes, catalogs, and boundaries.
	@./scripts/runtime-registry/config-check

runtime-registry-test: ## Run focused C2 runtime registry tests.
	@./scripts/runtime-registry/test

runtime-registry-api-proof: ## Prove C2 runtime registry API behavior through normal C1 sessions.
	@./scripts/runtime-registry/api-proof

runtime-registry-browser-proof: ## Prove C2 runtime registry web-admin behavior through repository Playwright.
	@./scripts/runtime-registry/browser-proof

runtime-registry-status: ## Report non-sensitive C2 runtime registry counts.
	@./scripts/runtime-registry/status

runtime-engine-config-check: ## Validate C3 command, event, projection, reconciliation, role, and TLS boundaries.
	@./scripts/runtime-engine/config-check

runtime-engine-test: ## Run focused C3 unit and PostgreSQL integration tests.
	@./scripts/runtime-engine/test

runtime-engine-proof: ## Prove C3 outbox, command, event, projection, and reconciliation behavior.
	@./scripts/runtime-engine/proof

runtime-engine-status: ## Report non-sensitive C3 runtime-engine counts and backlog summaries.
	@./scripts/runtime-engine/status

simulator-config-check: ## Validate C4 deterministic simulator catalogs, roles, routes, and boundaries.
	@./scripts/simulator/config-check

simulator-test: ## Run focused C4 deterministic simulator tests.
	@./scripts/simulator/test

simulator-api-proof: ## Prove C4 simulator configuration API behavior through normal C1 sessions.
	@./scripts/simulator/api-proof

simulator-runtime-proof: ## Prove C4 simulator command, event, projection, and reconciliation runtime flow.
	@./scripts/simulator/runtime-proof

simulator-status: ## Report non-sensitive C4 simulator counts and process-role state.
	@./scripts/simulator/status

ci: ## Run the locally reproducible Phase F4 CI quality baseline.
	@./scripts/ci/local-ci

ci-check: ci ## Alias for the locally reproducible Phase F4 CI quality baseline.

ci-k3d: k3d-config-check k3d-recreate-proof ## Run the destructive local K0 k3d proof for utcp-local.

k3d-ci: ci-k3d ## Alias for the destructive local K0 k3d proof.
