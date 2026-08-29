# UTCP Web Instructions

These rules refine root `AGENTS.md` for the Vue 3/Vite/TypeScript operator UI.

The UI is a client of authorized API projections, never an independent control
plane. It must not create canonical state, choose RuntimeNodes, issue provider
commands, or infer readiness/completion from request success, optimistic state,
WebSocket notification, or local component state. Render server-owned lifecycle,
operation, observation, audit, stale, unavailable, and degraded states.

Use operator language and backend-provided catalogs, capabilities, validation,
authorization, tenant context, and lifecycle metadata. Provider-specific fields
appear only when declared by the API for the selected adapter. Follow existing
Vue component, composable, state, API-client, router, TypeScript, styling,
design-token, and error-handling patterns. Preserve authentication and
capability checks, explicit loading/empty/retryable/terminal states,
accessibility, focus behavior, and responsive conventions.

Prefer focused component/unit tests, then frontend lint, typecheck, build, and
test targets. Browser proof is required for authenticated operator workflows,
realtime, responsive, or live-ingress claims. Use repository Playwright through
the real login and normal API flow; never inject sessions, cookies, Redis state,
or authentication bypasses. See `docs/evidence/ui/` for established proof
terminology.
