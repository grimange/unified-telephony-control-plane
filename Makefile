.DEFAULT_GOAL := help

.PHONY: k3d-start k3d-cluster-dns-apply k3d-runtime-ready k3d-lifecycle-test

COMPOSER_CACHE_DIR ?= /tmp/utcp-composer-cache
NPM_CONFIG_CACHE ?= /tmp/utcp-npm-cache
UTCP_API_IMAGE ?= utcp-api:dev
UTCP_WEB_IMAGE ?= utcp-web:dev
UTCP_BUILD_VERSION ?= 0.1.0-dev
UTCP_BUILD_COMMIT ?= unknown
UTCP_BUILD_CREATED ?= unknown
UTCP_IMAGE_SOURCE ?= local
UTCP_GATEWAY_IMAGE ?= utcp-gateway:dev
UTCP_RTPENGINE_IMAGE ?= utcp-rtpengine:dev
UTCP_T3_MEDIA_PROVER_IMAGE ?= utcp-t3-media-prover:dev
UTCP_FREESWITCH_IMAGE ?= utcp-freeswitch:dev
FREESWITCH_BASE_IMAGE ?= docker.io/safarov/freeswitch:1.10.12@sha256:b31c743f4c911a19687c61e3214968f2a24f93f9d3d667cc26284192e158ffc6
T3_MEDIA_PROVER_PLAYWRIGHT_VERSION ?= 1.61.1
T3_MEDIA_PROVER_BROWSER_IMAGE ?= mcr.microsoft.com/playwright:v1.61.1-noble
RTPENGINE_VERSION ?= mr26.0.1.19
RTPENGINE_SOURCE_COMMIT ?= 3552ac76cceb24e3ec176b77ec9c25554ae5923b
RTPENGINE_BASE_IMAGE ?= debian:trixie-slim@sha256:020c0d20b9880058cbe785a9db107156c3c75c2ac944a6aa7ab59f2add76a7bd
UTCP_COMPOSE_PROJECT_NAME ?= utcp-debug
UTCP_GATEWAY_PORT ?= 18088
COMPOSE_FILE ?= infrastructure/compose/compose.yaml
COMPOSE_ENV_FILES ?= --env-file versions.env --env-file infrastructure/compose/env.example
COMPOSE ?= docker compose $(COMPOSE_ENV_FILES) -f $(COMPOSE_FILE) -p $(UTCP_COMPOSE_PROJECT_NAME)

.PHONY: help doctor repository-hygiene workflow-check security-audit secret-scan api-install api-test api-check web-install web-test web-lint web-typecheck web-build install test check build image-build-api image-build-web image-build-rtpengine image-build-t3-media-prover image-build-freeswitch image-test-api image-test-web image-smoke-api image-smoke-web image-smoke image-inspect container-check local-config-check local-up local-status local-proof local-down compose-config compose-build compose-debug-up compose-up compose-status compose-logs compose-test compose-proof compose-ci compose-down compose-reset k3d-config-check k3d-media-edge-config-check k3d-registry-check-test k3d-create k3d-status k3d-verify k3d-registry-proof k3d-recreate-proof k3d-delete k8s-config-check k8s-image-build k8s-image-push k8s-apply k8s-status k8s-proof k8s-persistence-proof k8s-restart-proof k8s-delete server-config-check server-image-preflight server-apply server-status server-proof gateway-config-check gateway-crds-install traefik-install gateway-tls gateway-tls-apply gateway-apply gateway-status gateway-proof gateway-delete security-config-check security-config-check-test security-apply security-status security-proof security-delete media-config-check media-config-check-test media-edge-config-check media-edge-config-check-test media-edge-projection-check freeswitch-config-check freeswitch-config-check-test freeswitch-overlay-check freeswitch-overlay-check-test freeswitch-startup-smoke-test t3-media-prover-config-check t3-media-prover-config-check-test t3-media-prover-run observability-config-check observability-install observability-apply observability-status observability-proof observability-persistence-proof observability-delete control-plane-config-check control-plane-test control-plane-migrate-proof control-plane-proof control-plane-status identity-config-check identity-test identity-bootstrap-local identity-api-proof identity-browser-proof identity-status user-access-reset-password runtime-registry-config-check runtime-registry-test runtime-registry-api-proof runtime-registry-browser-proof runtime-engine-config-check runtime-engine-test runtime-engine-proof runtime-engine-status simulator-config-check simulator-test simulator-api-proof simulator-runtime-proof simulator-status telephony-domain-config-check telephony-domain-test telephony-domain-api-proof telephony-domain-runtime-proof telephony-domain-status asterisk-ari-config-check asterisk-ari-test asterisk-ari-api-proof asterisk-ari-runtime-proof asterisk-ari-status asterisk-conference-config-check asterisk-conference-test asterisk-conference-dialplan-check asterisk-conference-api-proof asterisk-conference-runtime-proof asterisk-conference-status asterisk-conference-recovery-test asterisk-conference-recovery-runtime-proof asterisk-conference-recovery-status kamailio-signaling-config-check-test ci ci-check ci-k3d k3d-ci

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

check: repository-hygiene media-config-check media-config-check-test media-edge-config-check media-edge-config-check-test media-edge-overlay-applicability-check media-edge-overlay-applicability-check-test media-edge-containment-check media-edge-failure-proof-test t3-media-candidate-assertions-test rtpengine-advertised-address-check freeswitch-config-check freeswitch-config-check-test freeswitch-overlay-check t3-media-prover-config-check t3-media-prover-config-check-test t3-media-prover-host-check security-config-check-test kamailio-signaling-config-check api-check web-lint web-typecheck ## Run repository, backend, frontend, media, security, and Kamailio signaling static checks.

.PHONY: rtpengine-advertised-address-check media-edge-overlay-applicability-check media-edge-overlay-applicability-check-test media-edge-apply media-edge-failure-proof-overlay-test t3-media-prover-host-check t3-media-prover-host-check-test k3d-config-check-test k3d-verifier-check-test api-entrypoint-check-test native-k3s-authority-check-test native-k3s-provider-sip-config-check-test
.PHONY: k8s-config-check-test kamailio-signaling-request-uri-semantics-test kamailio-signaling-cseq-semantics-test kamailio-signaling-external-trunk-runtime-proof kamailio-signaling-registration-runtime-proof asterisk-external-trunk-config-check asterisk-external-trunk-config-check-test asterisk-external-trunk-runtime-proof v1-external-sip-peer-config-check v1-external-sip-peer-smoke v1-external-sip-edge-config-check
.PHONY: asterisk-ari-caller-identity-semantics-test kamailio-registration-dialog-return-test
.PHONY: runtime-engine-config-check-test simulator-config-check-test telephony-domain-config-check-test

k3d-config-check-test: ## Run optional non-canonical utcp-local RTP publication mutation checks.
	@./scripts/k3d/config-check-test

native-k3s-authority-check-test: ## Run native canonical-context and historical-edge collision mutation checks.
	@./scripts/native-k3s/authority-check-test

native-k3s-provider-sip-config-check-test: ## Run native provider-facing SIP identity and rendering mutation checks.
	@./scripts/native-k3s/provider-sip-config-check-test

k3d-verifier-check-test: ## Run optional non-canonical k3d verifier command-order regression checks.
	@./scripts/k3d/verifier-check-test

api-entrypoint-check-test: ## Run API container process-role regression checks.
	@./scripts/kubernetes/api-entrypoint-check-test

k3d-media-edge-config-check: k3d-config-check ## Validate optional non-canonical utcp-local media-edge publication.

media-edge-config-check: ## Validate the external media-edge projection.
	@./scripts/media-edge/config-check

media-edge-config-check-test: ## Run media-edge projection mutation checks.
	@./scripts/media-edge/config-check-test

media-edge-projection-check: media-edge-config-check media-edge-config-check-test media-edge-overlay-applicability-check k3d-media-edge-config-check ## Validate the complete offline media-edge projection.

media-edge-overlay-applicability-check: media-edge-config-check ## Validate plain Kustomize render and client-side apply dry-run.
	@./scripts/media-edge/overlay-applicability-check

media-edge-overlay-applicability-check-test: media-edge-overlay-applicability-check ## Run List-normalization applicability mutations.
	@./scripts/media-edge/overlay-applicability-check-test

media-edge-apply: ## Apply the external media-edge projection through the explicitly selected environment lifecycle.
	@./scripts/media-edge/apply

media-edge-containment-check: ## Validate the bounded external media surface without a live mutation.
	@./scripts/media-edge/containment-sweep

media-edge-failure-proof-test: ## Run negative media proof harness mutation checks.
	@./scripts/media-edge/failure-proof-test

media-edge-failure-proof-overlay-test: ## Run offline T3-S3B failure overlay contract checks.
	@./scripts/media-edge/failure-proof-test

t3-media-candidate-assertions-test: ## Run explicit external media candidate assertion tests.
	@node tools/t3-media-prover/media-assertions-test.mjs

rtpengine-advertised-address-check: ## Execute the shared rtpengine address validators.
	@./scripts/media-edge/config-check
	@./scripts/media-edge/rtpengine-advertised-address-check

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

image-build-rtpengine: ## Build the pinned T3-S1 rtpengine image.
	docker build \
		--file infrastructure/docker/rtpengine/Dockerfile \
		--tag $(UTCP_RTPENGINE_IMAGE) \
		--build-arg BUILD_VERSION=$(UTCP_BUILD_VERSION) \
		--build-arg BUILD_COMMIT=$(UTCP_BUILD_COMMIT) \
		--build-arg BUILD_CREATED=$(UTCP_BUILD_CREATED) \
		--build-arg IMAGE_SOURCE=$(UTCP_IMAGE_SOURCE) \
		--build-arg RTPENGINE_VERSION=$(RTPENGINE_VERSION) \
		--build-arg RTPENGINE_SOURCE_COMMIT=$(RTPENGINE_SOURCE_COMMIT) \
		--build-arg RTPENGINE_BASE_IMAGE=$(RTPENGINE_BASE_IMAGE) \
		.

image-build-t3-media-prover: ## Build the local-only T3-S2B WebRTC media prover image.
	docker build \
		--file tools/t3-media-prover/Dockerfile \
		--tag $(UTCP_T3_MEDIA_PROVER_IMAGE) \
		--build-arg T3_MEDIA_PROVER_BROWSER_IMAGE=$(T3_MEDIA_PROVER_BROWSER_IMAGE) \
		--build-arg T3_MEDIA_PROVER_PLAYWRIGHT_VERSION=$(T3_MEDIA_PROVER_PLAYWRIGHT_VERSION) \
		.

image-build-freeswitch: ## Build the local-only T3-S2C FreeSWITCH parity image.
	docker build \
		--file infrastructure/docker/freeswitch/Dockerfile \
		--tag $(UTCP_FREESWITCH_IMAGE) \
		--build-arg FREESWITCH_BASE_IMAGE=$(FREESWITCH_BASE_IMAGE) \
		.

image-build: image-build-api image-build-web image-build-rtpengine ## Build all production images.

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

local-up: ## Start or reconcile the optional non-canonical k3d local runtime; never starts Compose.
	@./scripts/local/up

local-status: ## Report optional non-canonical k3d local runtime state and Compose authority drift.
	@./scripts/local/status

local-proof: ## Run the optional non-canonical k3d Kubernetes proof corridor; never starts Compose.
	@./scripts/local/proof

local-down: ## Stop only the optional non-canonical utcp-local k3d cluster; preserve registry and data.
	@./scripts/local/down

compose-config: ## Validate the resolved disposable/debug Docker Compose configuration.
	$(COMPOSE) --profile signaling config --quiet
	$(COMPOSE) --profile signaling config --format json | scripts/compose/static_check.py

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

k3d-start: ## Start or create the optional non-canonical utcp-local k3d cluster without deleting existing state.
	@./scripts/k3d/start

k3d-cluster-dns-apply: ## Apply the repository-owned CoreDNS custom configuration and restart CoreDNS.
	@./scripts/k3d/cluster-dns-apply

k3d-runtime-ready: ## Verify baseline deployment readiness without full desired-topology acceptance.
	@./scripts/k3d/runtime-ready

k3d-lifecycle-test: ## Run focused absent/running/stopped k3d lifecycle tests.
	@./scripts/k3d/lifecycle-test

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

k8s-config-check-test: ## Run managed image projection mutation checks.
	@./scripts/kubernetes/config-check-test

k8s-image-build: ## Build API, web, and gateway images for local Kubernetes.
	@./scripts/kubernetes/image-build

k8s-image-push: ## Push K1 images to the UTCP local k3d registry.
	@./scripts/kubernetes/image-push

k8s-image-push-test: ## Test immutable managed FreeSWITCH image publication and projection.
	@./scripts/kubernetes/image-push-test

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

server-credentials-test: ## Run native APP_KEY generation and Secret projection regression tests.
	@./scripts/native-k3s/credentials-test

server-config-check: ## Validate the native k3s overlay and target guardrails without applying resources.
	@./scripts/native-k3s/config-check

server-image-preflight: ## Validate native registry/image inputs and the intended native k3s target.
	@./scripts/native-k3s/image-preflight

server-apply: ## Deploy the shared UTCP platform to the validated native k3s target.
	@./scripts/native-k3s/apply

server-status: ## Report native k3s node, data, platform, PVC, and migration status.
	@./scripts/native-k3s/status

server-proof: ## Prove native k3s internal gateway, API readiness, and PVC binding.
	@./scripts/native-k3s/proof

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

gateway-proof-test: ## Test canonical UTCP runtime workload inventory classification.
	@./scripts/gateway/workload-inventory-test

gateway-proof: ## Prove K2 external HTTP/HTTPS Gateway behavior.
	@./scripts/gateway/proof

gateway-delete: ## Remove K2 Gateway resources and TLS Secret; preserve K1 and CRDs by default.
	@./scripts/gateway/delete

security-config-check: ## Validate K3 Pod Security, NetworkPolicy, RBAC, and service-account boundaries without mutating the cluster.
	@./scripts/security/config-check

security-config-check-test: ## Run focused observability API egress regression tests.
	@./scripts/security/config-check-test

security-apply: ## Apply the K3 Kubernetes network and security boundaries.
	@./scripts/security/apply

security-status: ## Report K3 Pod Security labels, NetworkPolicies, RBAC, service accounts, and workload readiness.
	@./scripts/security/status

security-proof: ## Prove K3 positive and negative connectivity, Pod Security rejection, and K2 regression behavior.
	@./scripts/security/proof

security-delete: ## Remove only K3-owned policies and generated files; this reopens namespace networking.
	@./scripts/security/delete

media-config-check: ## Validate the pinned T3-S1 rtpengine media-plane foundation without cluster mutation.
	@./scripts/media/config-check

media-config-check-test: ## Run focused T3-S1 media guard regression tests.
	@./scripts/media/config-check-test

freeswitch-config-check: ## Validate the bounded local FreeSWITCH parity adapter.
	@./scripts/freeswitch/config-check
	@./scripts/freeswitch/overlay-check

freeswitch-config-check-test: ## Run focused FreeSWITCH parity mutation tests.
	@./scripts/freeswitch/config-check-test
	@./scripts/freeswitch/overlay-check-test

freeswitch-overlay-check: ## Validate the rendered FreeSWITCH parity overlay delta.
	@./scripts/freeswitch/overlay-check

freeswitch-overlay-check-test: ## Run focused FreeSWITCH parity overlay mutation tests.
	@./scripts/freeswitch/overlay-check-test

freeswitch-startup-smoke-test: ## Build and start the committed FreeSWITCH image in disposable mounts.
	@./scripts/freeswitch/startup-smoke-test

t3-media-prover-config-check: ## Validate the local-only T3-S2B in-cluster WebRTC media prover.
	@./scripts/t3-media-prover/config-check

t3-media-prover-config-check-test: ## Run focused T3-S2B prover guard regression tests.
	@./scripts/t3-media-prover/config-check-test

t3-media-prover-host-check: t3-media-prover-config-check ## Validate host-mode prover preflight without placing a call.
	@./scripts/t3-media-prover/host-check

t3-media-prover-host-check-test: ## Run host-mode orchestration mutation checks.
	@./scripts/t3-media-prover/host-check-test

t3-media-prover-run: ## Run the one-shot local in-cluster WebRTC media prover.
	@./scripts/t3-media-prover/run

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

control-plane-config-check-test: ## Run focused C0 guard mutation tests.
	@./scripts/control-plane/config-check-test

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

user-access-reset-password: ## Issue a bounded temporary password through the canonical Kubernetes API Pod.
	@./scripts/identity/user-access-reset-password

runtime-registry-config-check: ## Validate C2 runtime-node registry schema, routes, catalogs, and boundaries.
	@./scripts/runtime-registry/config-check

runtime-registry-config-check-test: ## Run focused C2 guard mutation tests.
	@./scripts/runtime-registry/config-check-test

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

runtime-engine-config-check-test: ## Run C3 runtime-engine guard mutation tests.
	@./scripts/runtime-engine/config-check-test

runtime-engine-test: ## Run focused C3 unit and PostgreSQL integration tests.
	@./scripts/runtime-engine/test

runtime-engine-proof: ## Prove C3 outbox, command, event, projection, and reconciliation behavior.
	@./scripts/runtime-engine/proof

runtime-engine-status: ## Report non-sensitive C3 runtime-engine counts and backlog summaries.
	@./scripts/runtime-engine/status

simulator-config-check: ## Validate C4 deterministic simulator catalogs, roles, routes, and boundaries.
	@./scripts/simulator/config-check

simulator-config-check-test: ## Run C4 simulator guard mutation tests.
	@./scripts/simulator/config-check-test

simulator-test: ## Run focused C4 deterministic simulator tests.
	@./scripts/simulator/test

simulator-api-proof: ## Prove C4 simulator configuration API behavior through normal C1 sessions.
	@./scripts/simulator/api-proof

simulator-runtime-proof: ## Prove C4 simulator command, event, projection, and reconciliation runtime flow.
	@./scripts/simulator/runtime-proof

simulator-status: ## Report non-sensitive C4 simulator counts and process-role state.
	@./scripts/simulator/status

telephony-domain-config-check: ## Validate C5 telephony session and conference domain boundaries.
	@./scripts/telephony-domain/config-check

telephony-domain-config-check-test: ## Run C5 telephony-domain guard mutation tests.
	@./scripts/telephony-domain/config-check-test

telephony-domain-test: ## Run focused C5 telephony session and conference domain tests.
	@./scripts/telephony-domain/test

telephony-domain-api-proof: ## Prove C5 authenticated API lifecycle with no direct runtime command route.
	@./scripts/telephony-domain/api-proof

telephony-domain-runtime-proof: ## Prove C5 conference lifecycle through C3 and the deterministic simulator.
	@./scripts/telephony-domain/runtime-proof

telephony-domain-status: ## Report safe aggregate C5 telephony-domain status.
	@./scripts/telephony-domain/status

asterisk-ari-config-check: ## Validate T0 Asterisk ARI adapter, listener, Kubernetes, and boundary configuration.
	@./scripts/asterisk-ari/config-check

asterisk-ari-config-check-test: ## Run focused T0 guard mutation tests.
	@./scripts/asterisk-ari/config-check-test

asterisk-ari-caller-identity-semantics-test: ## Prove managed Asterisk caller identity and single provider origination semantics.
	@./scripts/asterisk-ari/caller-identity-semantics-test

asterisk-ari-test: ## Run focused T0 Asterisk ARI adapter, listener, epoch, and boundary tests.
	@./scripts/asterisk-ari/test

asterisk-ari-api-proof: ## Prove T0 Asterisk RuntimeNode setup through authenticated C1/C2 APIs.
	@./scripts/asterisk-ari/api-proof

asterisk-ari-runtime-proof: ## Prove T0 ARI HTTP, WebSocket, epoch, projection, and recovery behavior in Kubernetes.
	@./scripts/asterisk-ari/runtime-proof

asterisk-ari-status: ## Report safe aggregate T0 Asterisk ARI adapter and listener status.
	@./scripts/asterisk-ari/status

asterisk-conference-config-check: ## Validate T2-A Asterisk conference execution boundaries.
	@./scripts/asterisk-conference/config-check

asterisk-conference-test: ## Run focused T2-A Asterisk conference execution tests.
	@./scripts/asterisk-conference/test

asterisk-conference-dialplan-check: ## Resolve canonical, T3, and rejected destinations through Asterisk.
	@./scripts/asterisk-conference/dialplan-resolver-check

asterisk-conference-api-proof: ## Prove T2-A conference lifecycle API dispatch without direct ARI controllers.
	@./scripts/asterisk-conference/api-proof

asterisk-conference-runtime-proof: ## Prove T2-A live Asterisk bridge and channel execution in Kubernetes.
	@./scripts/asterisk-conference/runtime-proof

asterisk-conference-status: ## Report safe aggregate T2-A conference execution status.
	@./scripts/asterisk-conference/status

asterisk-conference-recovery-test: ## Run focused T2-B Asterisk conference recovery tests.
	@./scripts/asterisk-conference/recovery-test

asterisk-conference-recovery-runtime-proof: ## Prove T2-B Asterisk conference recovery behavior in Kubernetes.
	@./scripts/asterisk-conference/recovery-runtime-proof

asterisk-conference-recovery-status: ## Report safe aggregate T2-B conference recovery status.
	@./scripts/asterisk-conference/recovery-status

asterisk-external-trunk-config-check: ## Validate the derived Asterisk external-trunk provider seam.
	@./scripts/asterisk-external-trunk/config-check

asterisk-external-trunk-config-check-test: ## Test the managed Asterisk module/readiness contract mutations.
	@./scripts/asterisk-external-trunk/config-check-test

asterisk-external-trunk-runtime-proof: ## Prove a running Asterisk consumes the derived external-trunk representation.
	@./scripts/asterisk-external-trunk/runtime-proof

v1-external-sip-peer-config-check: ## Validate the disposable V1 External SIP Peer fixture.
	@./scripts/v1/external-sip-peer-config-check

v1-external-sip-peer-smoke: v1-external-sip-peer-config-check ## Build and smoke-test the V1 External SIP Peer preparation harness.
	@./scripts/v1/external-sip-peer-smoke

v1-external-sip-edge-config-check: ## Validate the bounded V1 external UDP/5060 edge.
	@./scripts/v1/external-sip-edge-config-check

kamailio-signaling-config-check: ## Validate current T1 signaling credential authority boundaries.
	@./scripts/kamailio-signaling/config-check

kamailio-signaling-config-check-test: ## Run focused T3-S2A signaling authority mutation tests.
	@./scripts/kamailio-signaling/config-check-test
	@./scripts/kamailio-signaling/request-uri-semantics-test
	@./scripts/kamailio-signaling/cseq-semantics-test

kamailio-registration-dialog-return-test: ## Run focused registration/NAT dialog return mutation tests.
	@./scripts/kamailio-signaling/registration-dialog-return-test

kamailio-signaling-request-uri-semantics-test: ## Execute pinned Kamailio pseudo-variable assignment semantics.
	@./scripts/kamailio-signaling/request-uri-semantics-test

kamailio-signaling-cseq-semantics-test: ## Execute pinned Kamailio authenticated INVITE CSeq semantics.
	@./scripts/kamailio-signaling/cseq-semantics-test

kamailio-signaling-test: ## Run focused T1 signaling credential authority tests.
	@./scripts/kamailio-signaling/test

kamailio-signaling-api-proof: ## Prove T1 credential API behavior; live Kamailio REGISTER remains separate.
	@./scripts/kamailio-signaling/api-proof

kamailio-signaling-runtime-proof: ## Prove live Kamailio WSS REGISTER corridor when implemented.
	@./scripts/kamailio-signaling/runtime-proof

kamailio-signaling-external-trunk-runtime-proof: ## Prove Kamailio consumes the derived external-trunk SQL view.
	@./scripts/kamailio-signaling/external-trunk-runtime-proof

kamailio-signaling-registration-runtime-proof: ## Prove the V1-A registration projection and internal control contract without real PBX credentials.
	@./scripts/kamailio-signaling/registration-runtime-proof

kamailio-signaling-status: ## Report safe aggregate T1 signaling credential and registration counts.
	@./scripts/kamailio-signaling/status

ci: ## Run the locally reproducible Phase F4 CI quality baseline.
	@./scripts/ci/local-ci

ci-check: ci ## Alias for the locally reproducible Phase F4 CI quality baseline.

ci-k3d: k3d-config-check k3d-recreate-proof ## Run the destructive local K0 k3d proof for utcp-local.

k3d-ci: ci-k3d ## Alias for the destructive local K0 k3d proof.
