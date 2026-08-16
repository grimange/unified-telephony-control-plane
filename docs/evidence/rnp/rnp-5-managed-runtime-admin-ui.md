# RNP-5 — Managed Runtime Admin UI

Status: complete as repository/UI implementation. The natural managed-runtime
browser and live lifecycle proof remains RNP-6.

## Authority and UX

The existing Runtime Nodes Admin view remains the single runtime-management
application. Its Add Runtime flow offers two paths: Managed Runtime and
Register Existing Runtime. The existing external registration form remains
available for externally managed Kubernetes, VM, physical, migration, and
advanced integration cases.

Managed onboarding accepts only administrator-owned intent: Asterisk, a
deployment target returned by the canonical deployment-target API, name, and
slug. The review step presents the runtime, target, name, slug, UTCP-managed
status, and the fact that credentials, endpoints, and infrastructure are
generated automatically. It contains no secret or raw infrastructure data.

## Submission and progress

Submit performs one tenant-scoped POST to the canonical RNP provisioning API
with an idempotency key. The frontend does not create credentials, endpoints,
capabilities, adapter configuration, or Kubernetes resources and has no manual
Start, Apply, Reconcile, Retry, or Destroy Infrastructure path.

The RuntimeNode read representation now contains a sanitized `management`
projection. The projection derives managed versus external status from the
canonical provisioning-request relationship and includes only safe provisioning
and deprovisioning operation metadata: identifier, status, timestamps, and
sanitized failure class/code. It does not include operation payloads or secret
material. Human-readable progress labels are derived at render time from this
projection plus desired and observed RuntimeNode state; no frontend lifecycle
state is persisted.

## Detail, lifecycle, and safety

Managed RuntimeNode details show UTCP managed status, deployment target, and
provisioning/deprovisioning progress where canonical evidence exists. A retired
managed node remains in the historical RuntimeNode list and can show
infrastructure deprovisioned. External nodes remain External and are not
described as having infrastructure deleted. RNM lifecycle actions remain the
only lifecycle controls.

The representation is tenant-scoped by the existing RuntimeRegistry query and
uses existing `runtime.nodes.view` and `runtime.nodes.manage` boundaries. The
deployment-target and provisioning endpoints retain their existing authorization
and tenant checks. API errors use the existing sanitized error path; stack
traces, Kubernetes response bodies, tokens, and credentials are not rendered.

## Verification

Focused frontend source-contract tests cover the two onboarding paths, Asterisk
availability, target API usage, review safety, canonical submission, progress
projection, and the absence of competing infrastructure controls. The existing
RuntimeNode workflow regression remains green. Focused backend API tests cover
the managed projection, external-node distinction, tenant-scoped provisioning
behavior, and secret non-exposure. The RNP-1 through RNP-4 regression suites,
`make test`, `make check`, and `git diff --check` are the required final checks.

No live cluster mutation or browser proof was performed for RNP-5.
